#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/setup-host.php — host-level provisioning for a Telaris instance.
 *
 * Idempotently installs the bits admin/setup.php cannot reach because it
 * runs as the web user without sudo:
 *
 *   - /etc/nginx/snippets/cloudflare-realip.conf
 *   - /etc/nginx/snippets/telaris-deny.conf
 *   - /etc/logrotate.d/telaris-snapshots-<site>
 *   - /etc/cron.d/telaris-pluriverse-pull-<site>     (federation stage 3)
 *   - /etc/cron.d/telaris-pluriverse-dispatch-<site> (federation stage 4d)
 *   - /etc/cron.d/telaris-galaxy-pull-<site>         (federation stage 5d-v)
 *   - chmod 0640 + chgrp www-data on config.php
 *   - secrets/ dir 0700 owned by www-data
 *
 * Scope: Ubuntu / Debian only. Other distros need their own bridge.
 *
 * Modes:
 *   sudo php bin/setup-host.php           # rewrite to canonical, reload nginx
 *   sudo php bin/setup-host.php --check   # report what's installed, exit 1 if any gap
 *
 * Rewrite mode is always destructive-to-canonical: existing snippets and
 * logrotate rules are overwritten with the repo's current content. That's
 * the point — single source of truth, no drift.
 *
 * Companion: admin/setup.php is the web-context install wizard (DB, schema,
 * first admin user); it runs as the web user and cannot install host
 * config. This script is the missing complement.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "bin/setup-host.php must be run from the command line, not the web.\n";
    exit(1);
}

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "bin/setup-host.php must be run as root.\n");
    fwrite(STDERR, "  Try: sudo php bin/setup-host.php" . (isset($argv[1]) ? ' ' . $argv[1] : '') . "\n");
    exit(1);
}

// Distro gate: Ubuntu / Debian only. Other distros (RHEL, Alpine, ...) have
// different nginx layouts, logrotate paths, and FPM user names. Out of scope.
$osRelease = @parse_ini_file('/etc/os-release');
$idLike = strtolower(trim((string)($osRelease['ID_LIKE'] ?? '')));
$id = strtolower(trim((string)($osRelease['ID'] ?? '')));
if ($id !== 'ubuntu' && $id !== 'debian' && !str_contains($idLike, 'debian') && !str_contains($idLike, 'ubuntu')) {
    fwrite(STDERR, "Unsupported distro: ID={$id}, ID_LIKE={$idLike}. Ubuntu / Debian only.\n");
    fwrite(STDERR, "  Manual install: see etc/nginx/cloudflare-realip.conf.sample header.\n");
    exit(1);
}

$opts = getopt('', ['check', 'verbose', 'help']);
$checkOnly = isset($opts['check']);
$verbose = isset($opts['verbose']);

if (isset($opts['help'])) {
    echo "Usage: sudo php bin/setup-host.php [--check] [--verbose]\n";
    echo "\n";
    echo "  --check    Report what's installed and what's missing; exit 1 on any gap.\n";
    echo "  --verbose  More detail per step.\n";
    echo "  (no flag)  Rewrite host config to canonical and reload nginx.\n";
    exit(0);
}

// Concurrency guard. Two operators running this script in parallel could race
// on the rename + nginx -t + rollback path. Acquire a non-blocking flock; if
// another instance holds it, refuse with a clear message.
$lockPath = '/run/telaris-setup-host.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "bin/setup-host.php is already running (lock held at {$lockPath}).\n");
    exit(1);
}
// $lockHandle stays open for the rest of the run; flock releases on exit.

// ---------------------------------------------------------------------------
// Paths derived from the script's location. bin/setup-host.php lives inside
// the Telaris site root; basename of that root is the site identifier used
// for per-site filenames (logrotate, etc.).
//
// realpath() the root so a symlinked site root (e.g. blue/green deploy via
// /var/www/<site> → /opt/releases/<ts>) still produces a stable site name
// based on the underlying directory rather than the symlink target.
// ---------------------------------------------------------------------------

$rootCandidate = dirname(__DIR__);
$root = realpath($rootCandidate);
if ($root === false) {
    fwrite(STDERR, "Could not realpath the site root ({$rootCandidate}).\n");
    exit(1);
}
$siteName = basename($root);

$cfSrc = $root . '/etc/nginx/cloudflare-realip.conf.sample';
$cfDst = '/etc/nginx/snippets/cloudflare-realip.conf';

$denySrc = $root . '/etc/nginx/telaris-deny.conf.sample';
$denyDst = '/etc/nginx/snippets/telaris-deny.conf';

$hgSnippetSrc = $root . '/etc/nginx/telaris-hotglue.conf.sample';
$hgSnippetDst = '/etc/nginx/snippets/telaris-hotglue.conf';
$hgContentDir = $root . '/hg/content';
$hgUserConfig = $root . '/hg/user-config.inc.php';
$fpmUser = 'www-data';  // Debian/Ubuntu php-fpm pool user (this script is Debian/Ubuntu-only)

$logrotateSrc = $root . '/etc/logrotate/telaris-snapshots.sample';
$logrotateDst = '/etc/logrotate.d/telaris-snapshots-' . $siteName;

$pullCronSrc = $root . '/etc/cron.d/pluriverse-pull.sample';
$pullCronDst = '/etc/cron.d/telaris-pluriverse-pull-' . $siteName;

$dispatchCronSrc = $root . '/etc/cron.d/pluriverse-dispatch.sample';
$dispatchCronDst = '/etc/cron.d/telaris-pluriverse-dispatch-' . $siteName;

$galaxyPullCronSrc = $root . '/etc/cron.d/galaxy-pull.sample';
$galaxyPullCronDst = '/etc/cron.d/telaris-galaxy-pull-' . $siteName;

$configPath = $root . '/config.php';
$vhostCandidates = [
    "/etc/nginx/sites-available/{$siteName}.conf",
    "/etc/nginx/sites-enabled/{$siteName}.conf",
];

// ---------------------------------------------------------------------------
// Per-task results accumulator. Each entry: ['name', 'status', 'detail',
// 'fix' => Closure|null]. status: 'ok' | 'missing' | 'mismatch' | 'error'.
// ---------------------------------------------------------------------------

$tasks = [];

// 1. nginx installed and active
$tasks[] = (function() {
    $bin = '/usr/sbin/nginx';
    if (!file_exists($bin)) {
        return ['name' => 'nginx installed', 'status' => 'missing', 'detail' => "nginx binary not at {$bin}", 'fix' => null];
    }
    $rc = 1;
    @exec('systemctl is-active nginx 2>&1', $out, $rc);
    $msg = trim(implode("\n", $out));
    if ($rc !== 0 || $msg !== 'active') {
        return ['name' => 'nginx service active', 'status' => 'missing', 'detail' => "systemctl is-active nginx → '{$msg}' (rc={$rc})", 'fix' => null];
    }
    return ['name' => 'nginx installed + active', 'status' => 'ok', 'detail' => 'active', 'fix' => null];
})();

// 2. Cloudflare real-IP snippet matches canonical (atomic write + nginx -t before commit).
$tasks[] = (function() use ($cfSrc, $cfDst) {
    if (!file_exists($cfSrc)) {
        return ['name' => 'cloudflare-realip snippet', 'status' => 'error', 'detail' => "repo source missing at {$cfSrc}", 'fix' => null];
    }
    $canonical = file_get_contents($cfSrc);
    $installed = file_exists($cfDst) ? file_get_contents($cfDst) : '';
    if ($installed === $canonical) {
        return ['name' => 'cloudflare-realip snippet', 'status' => 'ok', 'detail' => "matches {$cfSrc}", 'fix' => null];
    }
    $fix = function() use ($cfSrc, $cfDst, $canonical) {
        // Refuse to write through a pre-existing symlink. Root-only on stock
        // Debian/Ubuntu, but the provisioning helper should be paranoid: an
        // attacker who can plant a symlink at /etc/nginx/snippets/... could
        // redirect our write to an arbitrary path. is_link() is the canonical
        // PHP test (doesn't follow); realpath() confirms the resolved target
        // (if any) stays under /etc/nginx/.
        if (is_link($cfDst)) {
            return ['ok' => false, 'detail' => "{$cfDst} is a symlink; refusing to write through it (unlink first if intentional)"];
        }
        if (file_exists($cfDst)) {
            $real = realpath($cfDst);
            if ($real === false || !str_starts_with($real, '/etc/nginx/')) {
                return ['ok' => false, 'detail' => "{$cfDst} resolves outside /etc/nginx/ (real='{$real}'); refusing to write"];
            }
        }
        $tmp = $cfDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        // Validate nginx config WITH the new file in place by atomic-renaming
        // first; if nginx -t fails we restore from the backup.
        $backup = file_exists($cfDst) ? file_get_contents($cfDst) : null;
        if (!rename($tmp, $cfDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$cfDst}"];
        }
        $rc = 1; $out = [];
        @exec('/usr/sbin/nginx -t 2>&1', $out, $rc);
        if ($rc !== 0) {
            // Roll back.
            if ($backup !== null) {
                file_put_contents($cfDst, $backup);
            } else {
                @unlink($cfDst);
            }
            return ['ok' => false, 'detail' => "nginx -t rejected the new snippet:\n" . implode("\n", $out)];
        }
        return ['ok' => true, 'detail' => "wrote {$cfDst} (validated by nginx -t)"];
    };
    return ['name' => 'cloudflare-realip snippet', 'status' => file_exists($cfDst) ? 'mismatch' : 'missing', 'detail' => "{$cfDst} differs from canonical", 'fix' => $fix];
})();

// 3. nginx vhost for this site includes the snippet. We DON'T auto-edit the
// vhost (too risky). Report only.
$tasks[] = (function() use ($vhostCandidates) {
    $present = null;
    foreach ($vhostCandidates as $path) {
        if (file_exists($path)) { $present = $path; break; }
    }
    if ($present === null) {
        return ['name' => 'vhost includes CF snippet', 'status' => 'missing', 'detail' => 'no vhost found at ' . implode(' or ', $vhostCandidates), 'fix' => null];
    }
    $body = file_get_contents($present) ?: '';
    if (str_contains($body, 'include snippets/cloudflare-realip.conf')) {
        return ['name' => 'vhost includes CF snippet', 'status' => 'ok', 'detail' => "include found in {$present}", 'fix' => null];
    }
    return ['name' => 'vhost includes CF snippet', 'status' => 'missing', 'detail' => "{$present} does not include snippets/cloudflare-realip.conf — add manually inside the server { } block", 'fix' => null];
})();

// 3a. Telaris-deny snippet matches canonical (atomic write + nginx -t before
// commit). Mirrors task #2's pattern. Audit pass #4 (2026-05-24) found that
// /vendor/, /tests/, /phpunit.xml, /package.json, /composer (PHAR), and
// /nginx-versioned-assets.conf were all served via the docroot — the
// in-vhost extension blocklist missed .json/.xml/.conf/.lock and didn't
// cover the directory trees. This snippet closes that gap.
$tasks[] = (function() use ($denySrc, $denyDst) {
    if (!file_exists($denySrc)) {
        return ['name' => 'telaris-deny snippet', 'status' => 'error', 'detail' => "repo source missing at {$denySrc}", 'fix' => null];
    }
    $canonical = file_get_contents($denySrc);
    $installed = file_exists($denyDst) ? file_get_contents($denyDst) : '';
    if ($installed === $canonical) {
        return ['name' => 'telaris-deny snippet', 'status' => 'ok', 'detail' => "matches {$denySrc}", 'fix' => null];
    }
    $fix = function() use ($denyDst, $canonical) {
        if (is_link($denyDst)) {
            return ['ok' => false, 'detail' => "{$denyDst} is a symlink; refusing to write through it (unlink first if intentional)"];
        }
        if (file_exists($denyDst)) {
            $real = realpath($denyDst);
            if ($real === false || !str_starts_with($real, '/etc/nginx/')) {
                return ['ok' => false, 'detail' => "{$denyDst} resolves outside /etc/nginx/ (real='{$real}'); refusing to write"];
            }
        }
        $tmp = $denyDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        $backup = file_exists($denyDst) ? file_get_contents($denyDst) : null;
        if (!rename($tmp, $denyDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$denyDst}"];
        }
        $rc = 1; $out = [];
        @exec('/usr/sbin/nginx -t 2>&1', $out, $rc);
        if ($rc !== 0) {
            if ($backup !== null) {
                file_put_contents($denyDst, $backup);
            } else {
                @unlink($denyDst);
            }
            return ['ok' => false, 'detail' => "nginx -t rejected the new snippet:\n" . implode("\n", $out)];
        }
        return ['ok' => true, 'detail' => "wrote {$denyDst} (validated by nginx -t)"];
    };
    return ['name' => 'telaris-deny snippet', 'status' => file_exists($denyDst) ? 'mismatch' : 'missing', 'detail' => "{$denyDst} differs from canonical", 'fix' => $fix];
})();

// 3b. nginx vhost includes the telaris-deny snippet. Report-only, same as 3.
$tasks[] = (function() use ($vhostCandidates) {
    $present = null;
    foreach ($vhostCandidates as $path) {
        if (file_exists($path)) { $present = $path; break; }
    }
    if ($present === null) {
        return ['name' => 'vhost includes deny snippet', 'status' => 'missing', 'detail' => 'no vhost found at ' . implode(' or ', $vhostCandidates), 'fix' => null];
    }
    $body = file_get_contents($present) ?: '';
    if (str_contains($body, 'include snippets/telaris-deny.conf')) {
        return ['name' => 'vhost includes deny snippet', 'status' => 'ok', 'detail' => "include found in {$present}", 'fix' => null];
    }
    return ['name' => 'vhost includes deny snippet', 'status' => 'missing', 'detail' => "{$present} does not include snippets/telaris-deny.conf — add manually inside the server { } block", 'fix' => null];
})();

// 3c. Hotglue media-surface snippet matches canonical (atomic write + nginx -t).
// Same pattern as the cloudflare/deny snippets. Serves the vendored hotglue
// fork securely under /hg/ (see etc/nginx/telaris-hotglue.conf.sample).
$tasks[] = (function() use ($hgSnippetSrc, $hgSnippetDst) {
    if (!file_exists($hgSnippetSrc)) {
        return ['name' => 'hotglue snippet', 'status' => 'error', 'detail' => "repo source missing at {$hgSnippetSrc}", 'fix' => null];
    }
    $canonical = file_get_contents($hgSnippetSrc);
    $installed = file_exists($hgSnippetDst) ? file_get_contents($hgSnippetDst) : '';
    if ($installed === $canonical) {
        return ['name' => 'hotglue snippet', 'status' => 'ok', 'detail' => "matches {$hgSnippetSrc}", 'fix' => null];
    }
    $fix = function() use ($hgSnippetSrc, $hgSnippetDst, $canonical) {
        if (is_link($hgSnippetDst)) {
            return ['ok' => false, 'detail' => "{$hgSnippetDst} is a symlink; refusing to write through it (unlink first if intentional)"];
        }
        if (file_exists($hgSnippetDst)) {
            $real = realpath($hgSnippetDst);
            if ($real === false || !str_starts_with($real, '/etc/nginx/')) {
                return ['ok' => false, 'detail' => "{$hgSnippetDst} resolves outside /etc/nginx/ (real='{$real}'); refusing to write"];
            }
        }
        $tmp = $hgSnippetDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        $backup = file_exists($hgSnippetDst) ? file_get_contents($hgSnippetDst) : null;
        if (!rename($tmp, $hgSnippetDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$hgSnippetDst}"];
        }
        $rc = 1; $out = [];
        @exec('/usr/sbin/nginx -t 2>&1', $out, $rc);
        if ($rc !== 0) {
            if ($backup !== null) {
                file_put_contents($hgSnippetDst, $backup);
            } else {
                @unlink($hgSnippetDst);
            }
            return ['ok' => false, 'detail' => "nginx -t rejected the new snippet:\n" . implode("\n", $out)];
        }
        return ['ok' => true, 'detail' => "wrote {$hgSnippetDst} (validated by nginx -t)"];
    };
    return ['name' => 'hotglue snippet', 'status' => file_exists($hgSnippetDst) ? 'mismatch' : 'missing', 'detail' => "{$hgSnippetDst} differs from canonical", 'fix' => $fix];
})();

// 3d. nginx vhost includes the telaris-hotglue snippet. Report-only, same as 3/3b.
$tasks[] = (function() use ($vhostCandidates) {
    $present = null;
    foreach ($vhostCandidates as $path) {
        if (file_exists($path)) { $present = $path; break; }
    }
    if ($present === null) {
        return ['name' => 'vhost includes hotglue snippet', 'status' => 'missing', 'detail' => 'no vhost found at ' . implode(' or ', $vhostCandidates), 'fix' => null];
    }
    $body = file_get_contents($present) ?: '';
    if (str_contains($body, 'include snippets/telaris-hotglue.conf')) {
        return ['name' => 'vhost includes hotglue snippet', 'status' => 'ok', 'detail' => "include found in {$present}", 'fix' => null];
    }
    return ['name' => 'vhost includes hotglue snippet', 'status' => 'missing', 'detail' => "{$present} does not include snippets/telaris-hotglue.conf — add manually inside the server { } block", 'fix' => null];
})();

// 3e. Hotglue content directory exists and is writable by the web user.
// hotglue stores its flat-file pages + uploaded media here; php-fpm (www-data)
// must own it. Never web-served directly (the snippet denies /hg/content/).
$tasks[] = (function() use ($hgContentDir, $fpmUser) {
    $exists = is_dir($hgContentDir);
    $ownerOk = $exists && (function_exists('fileowner') && function_exists('posix_getpwuid')
        ? (($pw = posix_getpwuid(fileowner($hgContentDir))) && $pw['name'] === $fpmUser)
        : false);
    if ($exists && $ownerOk) {
        return ['name' => 'hotglue content dir', 'status' => 'ok', 'detail' => "{$hgContentDir} owned by {$fpmUser}", 'fix' => null];
    }
    $fix = function() use ($hgContentDir, $fpmUser) {
        if (!is_dir($hgContentDir) && !@mkdir($hgContentDir, 0755, true)) {
            return ['ok' => false, 'detail' => "could not create {$hgContentDir}"];
        }
        if (!@chown($hgContentDir, $fpmUser) || !@chgrp($hgContentDir, $fpmUser)) {
            return ['ok' => false, 'detail' => "could not chown {$hgContentDir} to {$fpmUser}"];
        }
        @chmod($hgContentDir, 0755);
        return ['ok' => true, 'detail' => "ensured {$hgContentDir} (0755, {$fpmUser})"];
    };
    return ['name' => 'hotglue content dir', 'status' => $exists ? 'mismatch' : 'missing', 'detail' => $exists ? "{$hgContentDir} not owned by {$fpmUser}" : "{$hgContentDir} missing", 'fix' => $fix];
})();

// hotglue's per-instance user-config.inc.php carries CONTENT_DIR, BASE_URL,
// USE_MIN_FILES and auth. php-fpm (www-data) must be able to READ it, else
// hotglue's @include silently fails and the whole install reverts to upstream
// defaults (wrong content dir, minified JS without the Telaris CSRF patch).
// It is per-instance + gitignored, so absence is fine; when present, ensure it
// is www-data-readable (theagitist:www-data 0640). Editing it with an editor
// can reset the group to the owner, so this self-heals it (cf. config.php).
$tasks[] = (function() use ($hgUserConfig, $fpmUser) {
    if (!file_exists($hgUserConfig)) {
        return ['name' => 'hotglue user-config perms', 'status' => 'ok', 'detail' => 'no per-instance user-config (using defaults)', 'fix' => null];
    }
    $groupOk = function_exists('posix_getgrgid')
        && ($gr = posix_getgrgid(filegroup($hgUserConfig))) && $gr['name'] === $fpmUser;
    $perms = fileperms($hgUserConfig) & 0777;
    $groupReadable = $groupOk && ($perms & 0040);
    $worldReadable = ($perms & 0004);
    if ($groupReadable || $worldReadable) {
        return ['name' => 'hotglue user-config perms', 'status' => 'ok', 'detail' => "{$hgUserConfig} readable by {$fpmUser}", 'fix' => null];
    }
    $fix = function() use ($hgUserConfig, $fpmUser) {
        if (!@chgrp($hgUserConfig, $fpmUser)) {
            return ['ok' => false, 'detail' => "could not chgrp {$hgUserConfig} to {$fpmUser}"];
        }
        @chmod($hgUserConfig, 0640);
        return ['ok' => true, 'detail' => "set {$hgUserConfig} to {$fpmUser}-readable (0640)"];
    };
    return ['name' => 'hotglue user-config perms', 'status' => 'mismatch', 'detail' => "{$hgUserConfig} not readable by {$fpmUser}", 'fix' => $fix];
})();

// 4. Logrotate entry matches canonical (with __SITE_LOGS__ substitution).
$tasks[] = (function() use ($logrotateSrc, $logrotateDst, $root) {
    if (!file_exists($logrotateSrc)) {
        return ['name' => 'logrotate snapshot rule', 'status' => 'error', 'detail' => "repo source missing at {$logrotateSrc}", 'fix' => null];
    }
    $template = file_get_contents($logrotateSrc);
    $canonical = str_replace('__SITE_LOGS__', $root . '/logs', $template);
    $installed = file_exists($logrotateDst) ? file_get_contents($logrotateDst) : '';
    if ($installed === $canonical) {
        return ['name' => 'logrotate snapshot rule', 'status' => 'ok', 'detail' => "matches {$logrotateDst}", 'fix' => null];
    }
    $fix = function() use ($logrotateDst, $canonical) {
        $tmp = $logrotateDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        if (!rename($tmp, $logrotateDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$logrotateDst}"];
        }
        // logrotate --debug to validate.
        $rc = 1; $out = [];
        @exec('/usr/sbin/logrotate --debug ' . escapeshellarg($logrotateDst) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'detail' => "logrotate --debug rejected: " . implode("\n", $out)];
        }
        return ['ok' => true, 'detail' => "wrote {$logrotateDst} (validated by logrotate --debug)"];
    };
    return ['name' => 'logrotate snapshot rule', 'status' => file_exists($logrotateDst) ? 'mismatch' : 'missing', 'detail' => "{$logrotateDst} differs from canonical", 'fix' => $fix];
})();

// 4a. Pluriverse-pull cron entries (federation stage 3).
// Drops a per-site /etc/cron.d/telaris-pluriverse-pull-<site> with the three
// jobs (key-events 5 min, peers + blacklist 30 min staggered). Substitutes
// __SITE_ROOT__ for the absolute site root. cron picks up changes to
// /etc/cron.d/ files automatically; no daemon reload required.
$tasks[] = (function() use ($pullCronSrc, $pullCronDst, $root) {
    if (!file_exists($pullCronSrc)) {
        return ['name' => 'pluriverse-pull cron', 'status' => 'error', 'detail' => "repo source missing at {$pullCronSrc}", 'fix' => null];
    }
    if (!file_exists('/etc/cron.d')) {
        return ['name' => 'pluriverse-pull cron', 'status' => 'error', 'detail' => '/etc/cron.d does not exist; cron not installed', 'fix' => null];
    }
    if (!file_exists($root . '/bin/pluriverse-pull')) {
        return ['name' => 'pluriverse-pull cron', 'status' => 'error', 'detail' => "{$root}/bin/pluriverse-pull does not exist (federation stage 3 not yet deployed?)", 'fix' => null];
    }
    $template = file_get_contents($pullCronSrc);
    $canonical = str_replace('__SITE_ROOT__', $root, $template);
    $installed = file_exists($pullCronDst) ? file_get_contents($pullCronDst) : '';
    if ($installed === $canonical) {
        return ['name' => 'pluriverse-pull cron', 'status' => 'ok', 'detail' => "matches {$pullCronDst}", 'fix' => null];
    }
    $fix = function() use ($pullCronDst, $canonical) {
        if (is_link($pullCronDst)) {
            return ['ok' => false, 'detail' => "{$pullCronDst} is a symlink; refusing to write through it (unlink first if intentional)"];
        }
        if (file_exists($pullCronDst)) {
            $real = realpath($pullCronDst);
            if ($real === false || !str_starts_with($real, '/etc/cron.d/')) {
                return ['ok' => false, 'detail' => "{$pullCronDst} resolves outside /etc/cron.d/ (real='{$real}'); refusing to write"];
            }
        }
        $tmp = $pullCronDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        // /etc/cron.d files must be 0644, owned by root, with no group write.
        // cron silently ignores files outside that profile.
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        if (!rename($tmp, $pullCronDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$pullCronDst}"];
        }
        return ['ok' => true, 'detail' => "wrote {$pullCronDst} (cron picks up automatically)"];
    };
    return [
        'name' => 'pluriverse-pull cron',
        'status' => file_exists($pullCronDst) ? 'mismatch' : 'missing',
        'detail' => file_exists($pullCronDst) ? "{$pullCronDst} differs from canonical" : "{$pullCronDst} does not exist",
        'fix' => $fix,
    ];
})();

// 4b. Pluriverse-dispatch cron entry (federation stage 4d).
// Same shape as 4a: drops /etc/cron.d/telaris-pluriverse-dispatch-<site>
// with the every-minute outbound queue drainer. Idempotent and bounded;
// safe to leave running on every host that has the federation surface.
$tasks[] = (function() use ($dispatchCronSrc, $dispatchCronDst, $root) {
    if (!file_exists($dispatchCronSrc)) {
        return ['name' => 'pluriverse-dispatch cron', 'status' => 'error', 'detail' => "repo source missing at {$dispatchCronSrc}", 'fix' => null];
    }
    if (!file_exists('/etc/cron.d')) {
        return ['name' => 'pluriverse-dispatch cron', 'status' => 'error', 'detail' => '/etc/cron.d does not exist; cron not installed', 'fix' => null];
    }
    if (!file_exists($root . '/bin/pluriverse-dispatch')) {
        return ['name' => 'pluriverse-dispatch cron', 'status' => 'error', 'detail' => "{$root}/bin/pluriverse-dispatch does not exist (federation stage 4 not yet deployed?)", 'fix' => null];
    }
    $template = file_get_contents($dispatchCronSrc);
    $canonical = str_replace('__SITE_ROOT__', $root, $template);
    $installed = file_exists($dispatchCronDst) ? file_get_contents($dispatchCronDst) : '';
    if ($installed === $canonical) {
        return ['name' => 'pluriverse-dispatch cron', 'status' => 'ok', 'detail' => "matches {$dispatchCronDst}", 'fix' => null];
    }
    $fix = function() use ($dispatchCronDst, $canonical) {
        if (is_link($dispatchCronDst)) {
            return ['ok' => false, 'detail' => "{$dispatchCronDst} is a symlink; refusing to write through it (unlink first if intentional)"];
        }
        if (file_exists($dispatchCronDst)) {
            $real = realpath($dispatchCronDst);
            if ($real === false || !str_starts_with($real, '/etc/cron.d/')) {
                return ['ok' => false, 'detail' => "{$dispatchCronDst} resolves outside /etc/cron.d/ (real='{$real}'); refusing to write"];
            }
        }
        $tmp = $dispatchCronDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        if (!rename($tmp, $dispatchCronDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$dispatchCronDst}"];
        }
        return ['ok' => true, 'detail' => "wrote {$dispatchCronDst} (cron picks up automatically)"];
    };
    return [
        'name' => 'pluriverse-dispatch cron',
        'status' => file_exists($dispatchCronDst) ? 'mismatch' : 'missing',
        'detail' => file_exists($dispatchCronDst) ? "{$dispatchCronDst} differs from canonical" : "{$dispatchCronDst} does not exist",
        'fix' => $fix,
    ];
})();

// 4c. Galaxy-pull cron entry (federation stage 5d-v).
// Same shape as 4a/4b: drops /etc/cron.d/telaris-galaxy-pull-<site> with the
// every-5-minute peer-pull loop (offset +2 from key-events). Per-peer
// backoff inside the binary keeps misbehaving peers cheap.
$tasks[] = (function() use ($galaxyPullCronSrc, $galaxyPullCronDst, $root) {
    if (!file_exists($galaxyPullCronSrc)) {
        return ['name' => 'galaxy-pull cron', 'status' => 'error', 'detail' => "repo source missing at {$galaxyPullCronSrc}", 'fix' => null];
    }
    if (!file_exists('/etc/cron.d')) {
        return ['name' => 'galaxy-pull cron', 'status' => 'error', 'detail' => '/etc/cron.d does not exist; cron not installed', 'fix' => null];
    }
    if (!file_exists($root . '/bin/galaxy-pull')) {
        return ['name' => 'galaxy-pull cron', 'status' => 'error', 'detail' => "{$root}/bin/galaxy-pull does not exist (federation stage 5 not yet deployed?)", 'fix' => null];
    }
    $template = file_get_contents($galaxyPullCronSrc);
    $canonical = str_replace('__SITE_ROOT__', $root, $template);
    $installed = file_exists($galaxyPullCronDst) ? file_get_contents($galaxyPullCronDst) : '';
    if ($installed === $canonical) {
        return ['name' => 'galaxy-pull cron', 'status' => 'ok', 'detail' => "matches {$galaxyPullCronDst}", 'fix' => null];
    }
    $fix = function() use ($galaxyPullCronDst, $canonical) {
        if (is_link($galaxyPullCronDst)) {
            return ['ok' => false, 'detail' => "{$galaxyPullCronDst} is a symlink; refusing to write through it (unlink first if intentional)"];
        }
        if (file_exists($galaxyPullCronDst)) {
            $real = realpath($galaxyPullCronDst);
            if ($real === false || !str_starts_with($real, '/etc/cron.d/')) {
                return ['ok' => false, 'detail' => "{$galaxyPullCronDst} resolves outside /etc/cron.d/ (real='{$real}'); refusing to write"];
            }
        }
        $tmp = $galaxyPullCronDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        if (!rename($tmp, $galaxyPullCronDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$galaxyPullCronDst}"];
        }
        return ['ok' => true, 'detail' => "wrote {$galaxyPullCronDst} (cron picks up automatically)"];
    };
    return [
        'name' => 'galaxy-pull cron',
        'status' => file_exists($galaxyPullCronDst) ? 'mismatch' : 'missing',
        'detail' => file_exists($galaxyPullCronDst) ? "{$galaxyPullCronDst} differs from canonical" : "{$galaxyPullCronDst} does not exist",
        'fix' => $fix,
    ];
})();

// 5. config.php permissions: 0640, owner www-data group. Refuses to bless
// a config.php owned by an unexpected user — chmod-on-attacker-planted-file
// would otherwise give the malicious config exactly the perms PHP-FPM needs
// to read it. The allowlist names the two principals that legitimately own
// instance config: 'root' (system-installed deploys) and the deploy user
// (operator-installed deploys; identified by SUDO_USER when this script was
// invoked with sudo, otherwise the script's working directory owner).
$tasks[] = (function() use ($configPath) {
    if (!file_exists($configPath)) {
        return ['name' => 'config.php exists', 'status' => 'missing', 'detail' => "{$configPath} does not exist (run admin/setup.php first)", 'fix' => null];
    }
    $mode = fileperms($configPath) & 0777;
    $group = posix_getgrgid(filegroup($configPath));
    $groupName = is_array($group) ? ($group['name'] ?? '?') : '?';
    $ownerUid = fileowner($configPath);
    $ownerPwd = posix_getpwuid($ownerUid);
    $ownerName = is_array($ownerPwd) ? ($ownerPwd['name'] ?? '?') : '?';

    $allowedOwners = ['root'];
    $sudoUser = (string)($_SERVER['SUDO_USER'] ?? '');
    if ($sudoUser !== '' && $sudoUser !== 'root') {
        $allowedOwners[] = $sudoUser;
    }
    $modeOk = ($mode === 0640);
    $groupOk = ($groupName === 'www-data');
    $ownerOk = in_array($ownerName, $allowedOwners, true);

    if ($modeOk && $groupOk && $ownerOk) {
        return ['name' => 'config.php perms', 'status' => 'ok', 'detail' => sprintf('mode %o owner %s group %s', $mode, $ownerName, $groupName), 'fix' => null];
    }
    if (!$ownerOk) {
        $allow = implode(', ', $allowedOwners);
        return [
            'name' => 'config.php perms',
            'status' => 'error',
            'detail' => "config.php is owned by '{$ownerName}', not in allowlist [{$allow}]; refusing to chmod (an unexpected owner may indicate a planted file — investigate before re-running)",
            'fix' => null,
        ];
    }
    $fix = function() use ($configPath) {
        $okGroup = @chgrp($configPath, 'www-data');
        $okMode = @chmod($configPath, 0640);
        if (!$okGroup || !$okMode) {
            return ['ok' => false, 'detail' => 'chgrp/chmod failed; check ownership of ' . $configPath];
        }
        return ['ok' => true, 'detail' => "chgrp www-data + chmod 0640 on {$configPath}"];
    };
    return [
        'name' => 'config.php perms',
        'status' => 'mismatch',
        'detail' => sprintf('mode %o owner %s group %s; want 0640 group www-data', $mode, $ownerName, $groupName),
        'fix' => $fix,
    ];
})();

// 6. secrets/ directory perms: 0700, owned by www-data. Federation identity
// (pluriverse.key, log.key) lives here. Generated by bin/init-identity /
// bin/init-log-key; this task only ensures the dir itself is correct.
// Files inside are checked separately (file-level perms via
// `php bin/init-identity --check` and `php bin/init-log-key --check`).
$tasks[] = (function() use ($root) {
    $secretsDir = $root . '/secrets';
    if (!file_exists($secretsDir)) {
        $fix = function() use ($secretsDir) {
            if (!@mkdir($secretsDir, 0700, true) && !is_dir($secretsDir)) {
                return ['ok' => false, 'detail' => "could not create {$secretsDir}"];
            }
            $okMode = @chmod($secretsDir, 0700);
            $okOwner = @chown($secretsDir, 'www-data');
            $okGroup = @chgrp($secretsDir, 'www-data');
            if (!$okMode || !$okOwner || !$okGroup) {
                return ['ok' => false, 'detail' => "chmod/chown/chgrp on {$secretsDir} partially failed"];
            }
            return ['ok' => true, 'detail' => "created {$secretsDir} (0700 www-data:www-data)"];
        };
        return ['name' => 'secrets/ dir', 'status' => 'missing', 'detail' => "{$secretsDir} does not exist", 'fix' => $fix];
    }
    if (is_link($secretsDir)) {
        return ['name' => 'secrets/ dir', 'status' => 'error', 'detail' => "{$secretsDir} is a symlink; refusing to chmod through it (unlink first if intentional)", 'fix' => null];
    }
    $mode = fileperms($secretsDir) & 0777;
    $ownerPwd = posix_getpwuid(fileowner($secretsDir));
    $ownerName = is_array($ownerPwd) ? ($ownerPwd['name'] ?? '?') : '?';
    $groupGrp = posix_getgrgid(filegroup($secretsDir));
    $groupName = is_array($groupGrp) ? ($groupGrp['name'] ?? '?') : '?';

    $modeOk = ($mode === 0700);
    $ownerOk = ($ownerName === 'www-data');
    $groupOk = ($groupName === 'www-data');
    if ($modeOk && $ownerOk && $groupOk) {
        return ['name' => 'secrets/ dir', 'status' => 'ok', 'detail' => sprintf('mode %o owner %s group %s', $mode, $ownerName, $groupName), 'fix' => null];
    }
    $fix = function() use ($secretsDir) {
        $okMode = @chmod($secretsDir, 0700);
        $okOwner = @chown($secretsDir, 'www-data');
        $okGroup = @chgrp($secretsDir, 'www-data');
        if (!$okMode || !$okOwner || !$okGroup) {
            return ['ok' => false, 'detail' => "chmod/chown/chgrp on {$secretsDir} partially failed"];
        }
        return ['ok' => true, 'detail' => "set {$secretsDir} to 0700 www-data:www-data"];
    };
    return [
        'name' => 'secrets/ dir',
        'status' => 'mismatch',
        'detail' => sprintf('mode %o owner %s group %s; want 0700 www-data:www-data', $mode, $ownerName, $groupName),
        'fix' => $fix,
    ];
})();

// ---------------------------------------------------------------------------
// Execute. In --check mode, only report. Otherwise, run fixes and then
// reload nginx if any nginx-touching fix succeeded.
// ---------------------------------------------------------------------------

$header = $checkOnly
    ? sprintf("Telaris host check — site=%s root=%s\n", $siteName, $root)
    : sprintf("Telaris host setup — site=%s root=%s\n", $siteName, $root);
echo $header;
echo str_repeat('=', strlen(trim($header))) . "\n";

$exitCode = 0;
$nginxTouched = false;

foreach ($tasks as $task) {
    $status = $task['status'];
    $name = $task['name'];
    $detail = $task['detail'];

    if ($status === 'ok') {
        printf("  [ok]      %s%s\n", $name, $verbose ? " — {$detail}" : '');
        continue;
    }

    // Non-ok: report + fix (if applicable + not --check).
    printf("  [%s] %s — %s\n", $status, $name, $detail);

    if ($checkOnly) {
        $exitCode = 1;
        continue;
    }

    if ($task['fix'] === null) {
        printf("           (no automatic fix — operator intervention required)\n");
        $exitCode = 1;
        continue;
    }

    $result = ($task['fix'])();
    if ($result['ok']) {
        printf("           → fixed: %s\n", $result['detail']);
        if (str_contains($name, 'cloudflare-realip') || str_contains($name, 'telaris-deny')) {
            $nginxTouched = true;
        }
    } else {
        printf("           → fix FAILED: %s\n", $result['detail']);
        $exitCode = 1;
    }
}

// Reload nginx once at the end if any nginx-touching fix succeeded.
if ($nginxTouched && !$checkOnly) {
    echo "\nReloading nginx (CF snippet changed)…\n";
    $rc = 1; $out = [];
    @exec('systemctl reload nginx 2>&1', $out, $rc);
    if ($rc === 0) {
        echo "  [ok]      nginx reloaded\n";
    } else {
        echo "  [error]   systemctl reload nginx failed (rc={$rc}): " . implode("\n", $out) . "\n";
        $exitCode = 1;
    }
}

echo "\n";
if ($exitCode === 0) {
    echo $checkOnly ? "All checks passed.\n" : "Host setup complete.\n";
} else {
    echo $checkOnly
        ? "One or more checks failed; re-run without --check to apply fixes, or address operator-intervention items first.\n"
        : "Setup completed with errors; re-run after addressing the messages above.\n";
}
exit($exitCode);
