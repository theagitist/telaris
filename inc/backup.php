<?php
declare(strict_types=1);

/**
 * Backup engine: build dumps, inspect them, and restore them.
 *
 * Dump format: gzipped JSON with envelope:
 *   { format: 'telaris-backup', format_version: 1, app_version, created_at,
 *     contents: { galaxies, users, media }, galaxies: [...], users: [...],
 *     media_blobs: { ... } }
 *
 * Cross-references inside the dump use ref strings (gal-N, kw-N, node-N, media-N),
 * slugs (for galaxies), and emails (for users) so the restore is independent of
 * auto-increment IDs on the target instance.
 */

require_once __DIR__ . '/db.php';

const BACKUP_FORMAT = 'telaris-backup';
const BACKUP_FORMAT_VERSION = 1;

/**
 * Resolve the SNAPSHOTS_DIR path. Falls back to <UPLOAD_DIR>/../snapshots
 * when the constant isn't defined (older config.php files).
 * Creates the directory if missing. Read-only callers can use this safely
 * even when the directory doesn't exist; writers should call
 * backup_snapshots_dir_writable() to get an actionable error up front.
 */
function backup_snapshots_dir(): string {
    if (defined('SNAPSHOTS_DIR')) {
        $dir = (string)SNAPSHOTS_DIR;
    } elseif (defined('UPLOAD_DIR')) {
        $dir = dirname(rtrim(UPLOAD_DIR, '/')) . '/snapshots';
    } else {
        $dir = sys_get_temp_dir() . '/telaris-snapshots';
    }
    if (!is_dir($dir)) {
        // 2775 = group-writable + setgid, so scheduled snapshots written by the
        // PHP/www-data user are readable by an admin shell user in the same
        // group, and new files inherit the directory's group.
        @mkdir($dir, 02775, true);
    }
    return rtrim($dir, '/');
}

/**
 * Same path, but throws a clear actionable RuntimeException if the directory
 * is missing or not writable by the current process user. Writers should call
 * this so failures point at the real cause (perms, missing parent, fallback
 * path on a path the web user can't reach) instead of a generic write error.
 */
function backup_snapshots_dir_writable(): string {
    $dir = backup_snapshots_dir();
    $configured = defined('SNAPSHOTS_DIR');
    $hint = $configured
        ? "Set permissions so the web/cron user can write to {$dir} (e.g. chmod 02775 {$dir} && chgrp www-data {$dir})."
        : "SNAPSHOTS_DIR is not defined in config.php; the snapshot subsystem is falling back to {$dir}, which the current user can't create or write to. Add `define('SNAPSHOTS_DIR', '/your/path/starmaps-snapshots');` to config.php and ensure the directory exists with mode 02775.";

    if (!is_dir($dir)) {
        throw new RuntimeException("Snapshots directory does not exist and could not be created: {$dir}. {$hint}");
    }
    if (!is_writable($dir)) {
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dir))['name'] ?? '?') : '?';
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        $whoami = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
            : '?';
        throw new RuntimeException("Snapshots directory {$dir} is not writable by '{$whoami}' (currently owned by '{$owner}', mode {$perms}). {$hint}");
    }
    return $dir;
}

// ---------------------------------------------------------------------------
// EXPORT
// ---------------------------------------------------------------------------

/**
 * Build the dump array for the given options.
 *
 * @param array $opts {
 *   galaxy_ids?: int[]  — empty/missing = all galaxies
 *   include_users?: bool — default true
 *   media_mode?: 'embedded'|'refs'|'none' — default 'embedded'
 *   include_galaxies?: bool — default true (set false for users-only dump)
 * }
 */
function backup_build_dump(array $opts): array {
    $includeGalaxies = $opts['include_galaxies'] ?? true;
    $includeUsers = $opts['include_users'] ?? true;
    $mediaMode = $opts['media_mode'] ?? 'embedded';
    if (!in_array($mediaMode, ['embedded', 'refs', 'none'], true)) {
        $mediaMode = 'embedded';
    }
    $galaxyIds = isset($opts['galaxy_ids']) && is_array($opts['galaxy_ids']) ? array_map('intval', $opts['galaxy_ids']) : [];

    if ($includeGalaxies && $galaxyIds === []) {
        $all = db_get_constellations();
        $galaxyIds = array_map(fn($g) => (int)$g['id'], $all);
    }

    // Build galaxies + collect media blobs
    $galaxies = [];
    $mediaBlobs = [];
    $mediaSeen = []; // url -> media-ref
    $mediaCounter = 1;

    if ($includeGalaxies) {
        foreach ($galaxyIds as $gid) {
            $g = db_get_galaxy_for_dump($gid);
            if ($g === null) continue;

            $galRef = 'gal-' . $g['id'];
            $kwRefs = [];
            $kwOut = [];
            foreach ($g['keywords'] as $kw) {
                $kwRef = 'kw-' . $kw['id'];
                $kwRefs[$kw['id']] = $kwRef;
                $kwOut[] = ['ref' => $kwRef, 'keyword' => $kw['keyword']];
            }
            $kwNameToRef = [];
            foreach ($g['keywords'] as $kw) {
                $kwNameToRef[$kw['keyword']] = 'kw-' . $kw['id'];
            }

            $nodesOut = [];
            foreach ($g['nodes'] as $n) {
                $nodeRef = 'node-' . $n['id'];

                // Resolve keyword links via name (matches across the dump)
                $linkedKwRefs = [];
                foreach ($n['keyword_names'] as $kname) {
                    if (isset($kwNameToRef[$kname])) $linkedKwRefs[] = $kwNameToRef[$kname];
                }

                // Media handling
                $mediaRefs = [];
                $nodeOut = [
                    'ref' => $nodeRef,
                    'name' => $n['name'],
                    'description' => $n['description'],
                    'url' => $n['url'],
                    'image_url' => $n['image_url'],
                    'image_attribution' => $n['image_attribution'],
                    'icon_url' => $n['icon_url'],
                    'embed_code' => $n['embed_code'],
                    'audio_url' => $n['audio_url'],
                    'audio_autoplay' => (bool)$n['audio_autoplay'],
                    'audio_loop' => (bool)$n['audio_loop'],
                    'video_url' => $n['video_url'],
                    'video_autoplay' => (bool)$n['video_autoplay'],
                    'pdf_url' => $n['pdf_url'] ?? null,
                    'animation' => is_string($n['animation']) ? json_decode($n['animation'], true) : $n['animation'],
                    'node_type' => $n['node_type'],
                    'target_galaxy_ref' => null,
                    'target_galaxy_slug' => $n['target_constellation_slug'] ?? null,
                    'is_accentuated' => (bool)$n['is_accentuated'],
                    'show_keywords' => (bool)$n['show_keywords'],
                    'source_facet' => $n['source_facet'] ?? null,
                    'media_type' => $n['media_type'] ?? null,
                    'source_created_at' => $n['source_created_at'] ?? null,
                    'import_slug' => $n['import_slug'] ?? null,
                    'created_by_email' => $n['created_by_email'] ?? null,
                    'keyword_refs' => $linkedKwRefs,
                ];

                // Resolve target_galaxy_ref if the target galaxy is in this dump
                if ($n['target_constellation_id'] !== null && $n['target_constellation_id'] !== '') {
                    $tcid = (int)$n['target_constellation_id'];
                    if (in_array($tcid, $galaxyIds, true)) {
                        $nodeOut['target_galaxy_ref'] = 'gal-' . $tcid;
                    }
                }

                // Embed media blobs
                if ($mediaMode === 'embedded') {
                    foreach (['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'] as $field) {
                        $url = $n[$field] ?? null;
                        if (!is_string($url) || $url === '' || !str_starts_with($url, 'uploads/')) continue;
                        $absPath = backup_resolve_upload_path($url);
                        if ($absPath === null || !is_file($absPath)) continue;

                        if (!isset($mediaSeen[$url])) {
                            $bytes = @file_get_contents($absPath);
                            if ($bytes === false) continue;
                            $ref = 'media-' . $mediaCounter++;
                            $mediaSeen[$url] = $ref;
                            $mediaBlobs[$ref] = [
                                'filename' => basename($absPath),
                                'content_b64' => base64_encode($bytes),
                                'sha256' => hash('sha256', $bytes),
                                'size' => strlen($bytes),
                                'original_url' => $url,
                            ];
                        }
                        $mediaRefs[$field] = $mediaSeen[$url];
                    }
                } elseif ($mediaMode === 'none') {
                    foreach (['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'] as $field) {
                        $nodeOut[$field] = null;
                    }
                }
                // 'refs' mode: keep URLs as-is, no blobs

                if ($mediaRefs !== []) {
                    $nodeOut['media_refs'] = $mediaRefs;
                }
                $nodesOut[] = $nodeOut;
            }

            $tourOut = null;
            if (isset($g['tour']) && is_array($g['tour'])) {
                $tourKwRefs = [];
                foreach (($g['tour']['keyword_ids'] ?? []) as $kid) {
                    if (isset($kwRefs[(int)$kid])) {
                        $tourKwRefs[] = $kwRefs[(int)$kid];
                    }
                }
                $tourOut = [
                    'enabled' => (bool)$g['tour']['enabled'],
                    'start_mode' => (string)$g['tour']['start_mode'],
                    'idle_seconds' => (int)$g['tour']['idle_seconds'],
                    'node_selection' => (string)$g['tour']['node_selection'],
                    'random_count' => (int)$g['tour']['random_count'],
                    'default_dwell' => (int)$g['tour']['default_dwell'],
                    'loop' => (bool)$g['tour']['loop'],
                    'keyword_refs' => $tourKwRefs,
                ];
            }

            $galaxies[] = [
                'ref' => $galRef,
                'name' => $g['name'],
                'tagline' => $g['tagline'],
                'slug' => $g['slug'],
                'theme' => $g['theme'],
                'import_source' => $g['import_source'],
                'is_default' => (bool)$g['is_default'],
                'keywords' => $kwOut,
                'nodes' => $nodesOut,
                'editor_emails' => $g['editor_emails'],
                'tour' => $tourOut,
                // Multigalaxy tags (Idea 3). Stored as labels; slugs are re-derived on restore.
                // Additive field — older format-v1 dumps without it restore cleanly (no tags).
                'tags' => array_map(fn($t) => $t['label'], db_get_tags_for_galaxy((int)$g['id'])),
            ];
        }
    }

    $usersOut = [];
    if ($includeUsers) {
        foreach (db_get_users_for_dump() as $u) {
            $usersOut[] = [
                'email' => $u['email'],
                'firstname' => $u['firstname'],
                'lastname' => $u['lastname'],
                'type' => (int)$u['type'],
                'password_hash' => $u['password'],
                'date_created' => $u['date_created'],
                'date_last_login' => $u['date_last_login'] ?? null,
                'editor_galaxy_slugs' => $u['editor_galaxy_slugs'] ?? [],
            ];
        }
    }

    // Galaxy clusters (Idea 2). Always include; member references use galaxy slug so a
    // cluster restored into a different instance still resolves correctly. Additive on
    // format v1 — older readers ignore the 'clusters' key.
    $clustersOut = [];
    if ($includeGalaxies) {
        foreach (db_get_clusters() as $cl) {
            $memberIds = db_get_cluster_member_ids((int)$cl['id']);
            $memberSlugs = [];
            foreach ($memberIds as $mid) {
                $info = db_get_constellation_by_id($mid);
                if ($info && !empty($info['slug'])) $memberSlugs[] = (string)$info['slug'];
            }
            $clustersOut[] = [
                'ref' => 'cluster-' . $cl['id'],
                'name' => $cl['name'],
                'tagline' => $cl['tagline'],
                'slug' => $cl['slug'],
                'theme' => $cl['theme'],
                'show_galaxy_list' => !empty($cl['show_galaxy_list']),
                'member_galaxy_slugs' => $memberSlugs,
            ];
        }
    }

    $appVersion = '0.0.0';
    if (file_exists(__DIR__ . '/../VERSION')) {
        $appVersion = trim((string)file_get_contents(__DIR__ . '/../VERSION'));
    }

    return [
        'format' => BACKUP_FORMAT,
        'format_version' => BACKUP_FORMAT_VERSION,
        'app_version' => $appVersion,
        'created_at' => gmdate('c'),
        'contents' => [
            'galaxies' => $includeGalaxies,
            'users' => $includeUsers,
            'media' => $mediaMode,
        ],
        'galaxies' => $galaxies,
        'clusters' => $clustersOut,
        'users' => $usersOut,
        'media_blobs' => $mediaBlobs,
    ];
}

/**
 * Resolve a URL like "uploads/12/45/foo.jpg" to an absolute filesystem path
 * inside UPLOAD_DIR. Returns null if outside UPLOAD_DIR (path traversal guard).
 */
function backup_resolve_upload_path(string $url): ?string {
    if (!defined('UPLOAD_DIR')) return null;
    if (!str_starts_with($url, 'uploads/')) return null;
    $rel = substr($url, strlen('uploads/'));
    $candidate = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
    $real = realpath($candidate);
    $allowed = realpath(UPLOAD_DIR);
    if ($real === false || $allowed === false) return null;
    if (strpos($real, rtrim($allowed, '/') . '/') !== 0 && $real !== $allowed) return null;
    return $real;
}

/**
 * Encode a dump array as gzipped JSON and write it to $outputPath.
 * Returns ['size_bytes' => int].
 */
function backup_write_to_file(array $dump, string $outputPath): array {
    $json = json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // Free the dump array before gzip allocates its buffer. Peak memory
    // becomes max(json, gz) instead of dump + json + gz. The streaming
    // refactor that drops $json itself is queued separately.
    unset($dump);
    $gz = gzencode($json, 6);
    if ($gz === false) {
        throw new RuntimeException('Failed to gzip the backup payload.');
    }
    unset($json);
    // Integrity hash over the gzipped payload (the exact bytes on disk). Callers
    // that store snapshot metadata persist this and pass it back to
    // backup_decode_file on read to detect on-disk corruption or tampering.
    $sha256 = hash('sha256', $gz);
    $dir = dirname($outputPath);
    // Pre-flight: surface the real cause (missing dir, wrong perms, fallback
    // path, etc.) instead of a generic file_put_contents=false later on.
    if (!is_dir($dir)) {
        $hint = defined('SNAPSHOTS_DIR')
            ? "Create it: mkdir -p {$dir} && chmod 02775 {$dir} && chgrp www-data {$dir}"
            : "SNAPSHOTS_DIR is not defined in config.php; falling back to {$dir}. Define SNAPSHOTS_DIR in config.php to point at a directory the web/cron user can write.";
        throw new RuntimeException("Cannot write snapshot: directory does not exist: {$dir}. {$hint}");
    }
    if (!is_writable($dir)) {
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dir))['name'] ?? '?') : '?';
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        $whoami = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
            : '?';
        throw new RuntimeException("Cannot write snapshot to {$dir}: not writable by '{$whoami}' (owned by '{$owner}', mode {$perms}). Try: chmod 02775 {$dir} && chgrp www-data {$dir}");
    }
    $written = @file_put_contents($outputPath, $gz);
    if ($written === false) {
        $err = error_get_last();
        $reason = ($err && !empty($err['message'])) ? ' (' . $err['message'] . ')' : '';
        throw new RuntimeException('Failed to write backup to ' . $outputPath . $reason);
    }
    return ['size_bytes' => $written, 'sha256' => $sha256];
}

// ---------------------------------------------------------------------------
// INSPECT
// ---------------------------------------------------------------------------

/**
 * Read a backup file and return its decoded array.
 *
 * @param string $path File to read.
 * @param ?string $expectedSha256 Optional sha256 hex string of the on-disk
 *   gzipped bytes. When supplied (snapshot restore path), the hash is
 *   recomputed and a mismatch throws. Operator-uploaded imports pass null
 *   since the hash is not known in advance.
 */
function backup_decode_file(string $path, ?string $expectedSha256 = null): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Backup file not found or unreadable.');
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Failed to read backup file.');
    }
    if ($expectedSha256 !== null && $expectedSha256 !== '') {
        $actual = hash('sha256', $raw);
        if (!hash_equals($expectedSha256, $actual)) {
            throw new RuntimeException('Backup integrity check failed: sha256 mismatch.');
        }
    }
    // Require gzip. backup_write_to_file always gzencodes; a non-gzipped or
    // truncated file is rejected rather than silently parsed as plain JSON.
    $json = @gzdecode($raw);
    if ($json === false) {
        throw new RuntimeException('Backup file is not a valid gzip stream.');
    }
    try {
        $arr = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Backup file is not valid JSON.');
    }
    if (!is_array($arr) || ($arr['format'] ?? null) !== BACKUP_FORMAT) {
        throw new RuntimeException('Not a Telaris backup file.');
    }
    $fv = (int)($arr['format_version'] ?? 0);
    if ($fv < 1) {
        throw new RuntimeException('Backup format version is missing or invalid.');
    }
    if ($fv > BACKUP_FORMAT_VERSION) {
        throw new RuntimeException('Backup format version is newer than this app supports.');
    }
    return $arr;
}

/**
 * Return a summary of a backup file without modifying anything.
 * Used by the import UI's inspect phase.
 */
function backup_inspect_file(string $path, ?string $expectedSha256 = null): array {
    $dump = backup_decode_file($path, $expectedSha256);
    $galaxies = $dump['galaxies'] ?? [];
    $users = $dump['users'] ?? [];
    $blobs = $dump['media_blobs'] ?? [];
    $totalNodes = 0;
    $totalKeywords = 0;
    foreach ($galaxies as $g) {
        $totalNodes += count($g['nodes'] ?? []);
        $totalKeywords += count($g['keywords'] ?? []);
    }
    $totalMediaBytes = 0;
    foreach ($blobs as $b) {
        $totalMediaBytes += (int)($b['size'] ?? 0);
    }
    $hasAdmin = false;
    foreach ($users as $u) {
        if ((int)($u['type'] ?? 0) === 2) { $hasAdmin = true; break; }
    }
    $galaxiesSummary = [];
    foreach ($galaxies as $g) {
        $galaxiesSummary[] = [
            'ref' => $g['ref'],
            'name' => $g['name'],
            'slug' => $g['slug'],
            'is_default' => !empty($g['is_default']),
            'node_count' => count($g['nodes'] ?? []),
        ];
    }
    return [
        'format_version' => (int)($dump['format_version'] ?? 0),
        'app_version' => (string)($dump['app_version'] ?? ''),
        'created_at' => (string)($dump['created_at'] ?? ''),
        'contents' => $dump['contents'] ?? [],
        'galaxy_count' => count($galaxies),
        'node_count' => $totalNodes,
        'keyword_count' => $totalKeywords,
        'user_count' => count($users),
        'has_admin_user' => $hasAdmin,
        'media_blob_count' => count($blobs),
        'media_bytes' => $totalMediaBytes,
        'galaxies' => $galaxiesSummary,
    ];
}

// ---------------------------------------------------------------------------
// RESTORE
// ---------------------------------------------------------------------------

/**
 * Restore from a backup file.
 *
 * @param array $opts {
 *   mode: 'wipe_all' | 'granular'
 *   restore_users: bool (default true)
 *   users_replace_existing: bool (default false) — if true, overwrite existing users by email
 *   users_replace_password: bool (default false) — only meaningful if users_replace_existing
 *   restore_media: bool (default true)
 *   galaxies?: array<string, array{conflict: 'overwrite'|'rename', rename_suffix?: string, include?: bool}>
 *     Map from galaxy ref → per-galaxy options. Refs not in the map are skipped (granular mode).
 *     Ignored in wipe_all mode (everything is restored as-is).
 *   rename_suffix_default?: string (default ' (restored)')
 * }
 *
 * @return array Outcome report.
 */
function backup_restore_from_file(string $path, array $opts): array {
    $expectedSha256 = isset($opts['expected_sha256']) ? (string)$opts['expected_sha256'] : null;
    $dump = backup_decode_file($path, $expectedSha256 !== '' ? $expectedSha256 : null);
    $mode = $opts['mode'] ?? 'granular';
    $restoreUsers = $opts['restore_users'] ?? true;
    $restoreMedia = $opts['restore_media'] ?? true;
    $usersReplace = $opts['users_replace_existing'] ?? false;
    $usersReplacePw = $opts['users_replace_password'] ?? false;
    $renameSuffixDefault = $opts['rename_suffix_default'] ?? ' (restored)';

    $report = [
        'mode' => $mode,
        'galaxies_created' => 0,
        'galaxies_overwritten' => 0,
        'galaxies_renamed' => 0,
        'galaxies_skipped' => 0,
        'galaxies_failed' => [],
        'users_created' => 0,
        'users_updated' => 0,
        'users_skipped' => 0,
        'media_files_written' => 0,
        'media_files_skipped' => 0,
        'default_galaxy_id' => null,
    ];

    // 1. Wipe everything for snapshot restore
    if ($mode === 'wipe_all') {
        db_wipe_all_data();
    }

    // 2. Restore users first so node.created_by can be resolved
    $userEmailToId = [];
    if ($restoreUsers) {
        foreach ($dump['users'] ?? [] as $u) {
            $email = (string)($u['email'] ?? '');
            if ($email === '') { $report['users_skipped']++; continue; }
            $existing = db_get_user_by_email($email);
            if ($existing !== null) {
                if ($mode === 'wipe_all') {
                    // After wipe_all, no users exist. This shouldn't happen.
                    // But defensively: treat as create with the dump's id.
                    $existing = null;
                }
            }
            if ($existing === null) {
                $newId = 'user_' . bin2hex(random_bytes(8));
                if ((int)($u['type'] ?? 0) === 2) {
                    $newId = 'admin_' . bin2hex(random_bytes(8));
                }
                db_user_create_raw(
                    $newId,
                    $email,
                    (string)($u['password_hash'] ?? ''),
                    (string)($u['firstname'] ?? ''),
                    (string)($u['lastname'] ?? ''),
                    (int)($u['type'] ?? 0),
                    isset($u['date_created']) ? (string)$u['date_created'] : null
                );
                $userEmailToId[$email] = $newId;
                $report['users_created']++;
            } elseif ($usersReplace) {
                db_update_user(
                    $existing['id'],
                    $email,
                    (string)($u['firstname'] ?? ''),
                    (string)($u['lastname'] ?? ''),
                    (int)($u['type'] ?? 0),
                    $usersReplacePw ? (string)($u['password_hash'] ?? '') : null
                );
                $userEmailToId[$email] = $existing['id'];
                $report['users_updated']++;
            } else {
                $userEmailToId[$email] = $existing['id'];
                $report['users_skipped']++;
            }
        }
    }
    // Fold any users already on the system into the lookup so created_by still works
    foreach (db_get_users() as $u) {
        if (!isset($userEmailToId[$u['email']])) {
            $userEmailToId[$u['email']] = $u['id'];
        }
    }

    // 3. Restore galaxies
    $galaxyRefToNewId = [];     // ref → new constellation id
    $portalUpdates = [];        // [['node_id' => int, 'target_ref' => string|null, 'target_slug' => string|null], ...]
    $galaxyOpts = $opts['galaxies'] ?? [];

    foreach ($dump['galaxies'] ?? [] as $g) {
        $ref = (string)$g['ref'];
        $perOpts = $galaxyOpts[$ref] ?? null;

        if ($mode === 'granular') {
            if ($perOpts === null || ($perOpts['include'] ?? true) === false) {
                $report['galaxies_skipped']++;
                continue;
            }
        }

        $conflict = $perOpts['conflict'] ?? ($mode === 'wipe_all' ? 'overwrite' : 'overwrite');
        $renameSuffix = $perOpts['rename_suffix'] ?? $renameSuffixDefault;

        try {
            $result = backup_restore_one_galaxy($g, $dump, [
                'mode' => $mode,
                'conflict' => $conflict,
                'rename_suffix' => $renameSuffix,
                'restore_media' => $restoreMedia,
                'user_email_to_id' => $userEmailToId,
            ]);
            $galaxyRefToNewId[$ref] = $result['new_id'];
            foreach ($result['portal_updates'] as $pu) $portalUpdates[] = $pu;

            switch ($result['action']) {
                case 'created': $report['galaxies_created']++; break;
                case 'overwritten': $report['galaxies_overwritten']++; break;
                case 'renamed': $report['galaxies_renamed']++; break;
            }
            $report['media_files_written'] += $result['media_written'];
            $report['media_files_skipped'] += $result['media_skipped'];
            if (!empty($g['is_default'])) {
                $report['default_galaxy_id'] = $result['new_id'];
            }
        } catch (Throwable $e) {
            $report['galaxies_failed'][] = [
                'ref' => $ref,
                'name' => $g['name'] ?? '',
                'error' => $e->getMessage(),
            ];
        }
    }

    // 4. Resolve portal target_constellation_id (now that all new IDs exist)
    foreach ($portalUpdates as $pu) {
        $targetCid = null;
        if ($pu['target_ref'] !== null && isset($galaxyRefToNewId[$pu['target_ref']])) {
            $targetCid = $galaxyRefToNewId[$pu['target_ref']];
        } elseif ($pu['target_slug'] !== null) {
            $targetCid = db_get_constellation_id_by_slug($pu['target_slug']);
        }
        db_set_node_target_constellation((int)$pu['node_id'], $targetCid);
    }

    // 4b. Restore galaxy clusters (Idea 2). Members referenced by galaxy slug; missing members
    // are silently dropped (e.g. a galaxy excluded from the dump). On slug conflict in granular
    // mode we overwrite in place; in wipe_all mode the slug is always free.
    foreach ($dump['clusters'] ?? [] as $cl) {
        $name = (string)($cl['name'] ?? '');
        $slug = (string)($cl['slug'] ?? '');
        if ($name === '' || $slug === '') continue;
        $tagline = (string)($cl['tagline'] ?? '');
        $theme = (string)($cl['theme'] ?? 'cosmic');
        $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech'];
        if (!in_array($theme, $allowedThemes, true)) $theme = 'cosmic';

        $memberIds = [];
        foreach (($cl['member_galaxy_slugs'] ?? []) as $msl) {
            $cid = db_get_constellation_id_by_slug((string)$msl);
            if ($cid !== null) $memberIds[] = $cid;
        }

        $showGalaxyList = !empty($cl['show_galaxy_list']);
        try {
            $existingId = db_get_constellation_id_by_slug($slug);
            if ($existingId !== null) {
                $existingInfo = db_get_constellation_by_id($existingId);
                if ($existingInfo && ($existingInfo['type'] ?? 'galaxy') === 'cluster') {
                    db_update_cluster($existingId, $name, $tagline, $slug, $theme, $showGalaxyList);
                    db_set_cluster_members($existingId, $memberIds);
                }
                // Slug taken by a galaxy — skip silently rather than corrupt the galaxy.
            } else {
                db_create_cluster($name, $tagline, $slug, $theme, $memberIds, $showGalaxyList);
            }
        } catch (Throwable $e) {
            // Log and continue; cluster restore failures shouldn't block the rest of the dump.
            error_log('backup cluster restore failed: ' . $e->getMessage());
        }
    }

    // 5. Re-link editors to their galaxies (now that both exist)
    if ($restoreUsers) {
        foreach ($dump['users'] ?? [] as $u) {
            $email = (string)($u['email'] ?? '');
            $userId = $userEmailToId[$email] ?? null;
            if ($userId === null) continue;
            $slugs = $u['editor_galaxy_slugs'] ?? [];
            if ($slugs === []) continue;
            $cids = [];
            foreach ($slugs as $slug) {
                $cid = db_get_constellation_id_by_slug($slug);
                if ($cid !== null) $cids[] = $cid;
            }
            if ($cids !== []) db_set_user_constellations($userId, $cids);
        }
        // Also restore per-galaxy editor_emails (for galaxies in the dump)
        foreach ($dump['galaxies'] ?? [] as $g) {
            $ref = (string)$g['ref'];
            if (!isset($galaxyRefToNewId[$ref])) continue;
            $cid = $galaxyRefToNewId[$ref];
            foreach ($g['editor_emails'] ?? [] as $email) {
                $uid = $userEmailToId[$email] ?? null;
                if ($uid === null) continue;
                // Append rather than overwrite (db_set_user_constellations replaces, so we merge)
                $existing = db_get_user_constellation_ids($uid);
                if (!in_array($cid, $existing, true)) {
                    $existing[] = $cid;
                    db_set_user_constellations($uid, $existing);
                }
            }
        }
    }

    // 6. Update default galaxy pointer for snapshot restores
    if ($mode === 'wipe_all' && $report['default_galaxy_id'] !== null) {
        db_set_default_constellation_id((int)$report['default_galaxy_id']);
    }

    return $report;
}

/**
 * Restore one galaxy from a dump entry. Internal helper.
 *
 * @return array{action: string, new_id: int, portal_updates: array, media_written: int, media_skipped: int}
 */
function backup_restore_one_galaxy(array $g, array $dump, array $opts): array {
    // Per-galaxy atomicity. Either every DB write for this galaxy lands, or
    // none of them do. Without this, a mid-galaxy failure (e.g. media write
    // throws after the constellation row is in place) left the system with a
    // partial constellation referenced by orphan rows. On rollback, the
    // outer per-galaxy try/catch in backup_restore_from_file records the
    // failure into report['galaxies_failed'] and the next galaxy proceeds.
    //
    // Note: on-disk media written before the throw are not rolled back; they
    // sit as orphans in uploads/<id>/ that nobody can reach (the constellation
    // row is gone). That's a leak, not corruption, and acceptable.
    $pdo = getDB();
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
        $pdo->beginTransaction();
    }

    try {
    $mode = $opts['mode'];
    $conflict = $opts['conflict'];
    $renameSuffix = $opts['rename_suffix'] ?? ' (restored)';
    $restoreMedia = $opts['restore_media'];
    $userEmailToId = $opts['user_email_to_id'];
    $blobs = $dump['media_blobs'] ?? [];

    $existingId = null;
    if ($mode === 'granular' && !empty($g['slug'])) {
        $existingId = db_get_constellation_id_by_slug((string)$g['slug']);
    }

    // Decide path
    $action = 'created';
    $targetId = null;

    if ($mode === 'wipe_all') {
        // Always create fresh
        $targetId = db_create_constellation((string)$g['name'], (string)($g['tagline'] ?? ''), $g['slug'] ?? null, (string)($g['theme'] ?? 'cosmic'));
        if (!empty($g['import_source'])) db_set_constellation_import_source($targetId, (string)$g['import_source']);
        $action = 'created';
    } elseif ($conflict === 'overwrite' && $existingId !== null) {
        // Update in place: keep ID, replace contents
        $targetId = $existingId;
        $pdo = getDB();
        // Wipe nodes & keywords for this galaxy (but leave the row itself)
        $pdo->prepare("DELETE nk FROM node_keywords nk INNER JOIN nodes n ON n.id = nk.node_id WHERE n.constellation_id = :id")
            ->execute([':id' => $targetId]);
        $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :id")->execute([':id' => $targetId]);
        $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :id")->execute([':id' => $targetId]);
        // Wipe upload subdirectory for this galaxy
        if (defined('UPLOAD_DIR')) {
            db_rrmdir(rtrim(UPLOAD_DIR, '/') . '/' . $targetId, UPLOAD_DIR);
        }
        // Update the constellation row
        db_update_constellation($targetId, (string)$g['name'], (string)($g['tagline'] ?? ''), $g['slug'] ?? null, (string)($g['theme'] ?? 'cosmic'));
        db_set_constellation_import_source($targetId, !empty($g['import_source']) ? (string)$g['import_source'] : null);
        $action = 'overwritten';
    } else {
        // Either no existing match, or conflict='rename'.
        $name = (string)$g['name'];
        $slug = $g['slug'] ?? null;
        if ($existingId !== null) {
            $name = $name . $renameSuffix;
            if ($slug !== null) $slug = $slug . db_slugify($renameSuffix);
            $action = 'renamed';
        }
        // Make slug unique (suffix -2, -3 if needed)
        if ($slug !== null) {
            $base = $slug;
            $i = 2;
            while (db_get_constellation_id_by_slug($slug) !== null) {
                $slug = $base . '-' . $i;
                $i++;
            }
        }
        $targetId = db_create_constellation($name, (string)($g['tagline'] ?? ''), $slug, (string)($g['theme'] ?? 'cosmic'));
        if (!empty($g['import_source'])) db_set_constellation_import_source($targetId, (string)$g['import_source']);
    }

    // Restore galaxy tags (Idea 3). Backup stores labels; slugs are re-derived.
    if (isset($g['tags']) && is_array($g['tags'])) {
        $labels = array_values(array_filter(array_map(fn($t) => trim((string)$t), $g['tags']), fn($s) => $s !== ''));
        db_set_tags_for_galaxy($targetId, $labels);
    }

    // Insert keywords
    $kwRefToId = [];
    foreach ($g['keywords'] ?? [] as $kw) {
        $kid = db_create_keyword((string)$kw['keyword'], $targetId);
        $kwRefToId[(string)$kw['ref']] = $kid;
    }

    // Apply tour config (after keywords so tour keyword refs can resolve)
    if (isset($g['tour']) && is_array($g['tour'])) {
        db_set_constellation_tour_config($targetId, [
            'tour_enabled' => !empty($g['tour']['enabled']),
            'tour_start_mode' => (string)($g['tour']['start_mode'] ?? 'manual'),
            'tour_idle_seconds' => (int)($g['tour']['idle_seconds'] ?? 30),
            'tour_node_selection' => (string)($g['tour']['node_selection'] ?? 'all'),
            'tour_random_count' => (int)($g['tour']['random_count'] ?? 10),
            'tour_default_dwell' => (int)($g['tour']['default_dwell'] ?? 8),
            'tour_loop' => !empty($g['tour']['loop']),
        ]);
        $tourKeywordIds = [];
        foreach (($g['tour']['keyword_refs'] ?? []) as $ref) {
            if (isset($kwRefToId[(string)$ref])) {
                $tourKeywordIds[] = $kwRefToId[(string)$ref];
            }
        }
        db_set_tour_keyword_ids($targetId, $tourKeywordIds);
    }

    // Insert nodes
    $portalUpdates = [];
    $mediaWritten = 0;
    $mediaSkipped = 0;
    foreach ($g['nodes'] ?? [] as $n) {
        $nodePayload = $n;
        // Strip target_constellation_id at insert time; resolve in second pass
        $nodePayload['target_constellation_id'] = null;
        // Resolve created_by_email → user_id
        $nodePayload['created_by'] = null;
        if (!empty($n['created_by_email']) && isset($userEmailToId[$n['created_by_email']])) {
            $nodePayload['created_by'] = $userEmailToId[$n['created_by_email']];
        }
        // Animation may already be an array — db helper handles both.
        $newNodeId = db_create_node_for_restore($targetId, $nodePayload);

        // Write media blobs (and rewrite URLs to new locations)
        if ($restoreMedia && !empty($n['media_refs'])) {
            $rewriteFields = [];
            foreach ($n['media_refs'] as $field => $mediaRef) {
                if (!isset($blobs[$mediaRef])) { $mediaSkipped++; continue; }
                $blob = $blobs[$mediaRef];
                $bytes = base64_decode((string)$blob['content_b64'], true);
                if ($bytes === false) { $mediaSkipped++; continue; }
                if (isset($blob['sha256']) && hash('sha256', $bytes) !== $blob['sha256']) { $mediaSkipped++; continue; }

                if (!defined('UPLOAD_DIR')) { $mediaSkipped++; continue; }
                $relDir = "uploads/{$targetId}/{$newNodeId}";
                $absDir = rtrim(UPLOAD_DIR, '/') . '/' . $targetId . '/' . $newNodeId;
                if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
                    $mediaSkipped++;
                    continue;
                }
                $filename = (string)$blob['filename'];
                // Sanitize filename (basename, strip path components)
                $filename = basename($filename);
                $absPath = $absDir . '/' . $filename;
                if (@file_put_contents($absPath, $bytes) === false) { $mediaSkipped++; continue; }
                $rewriteFields[$field] = $relDir . '/' . $filename;
                $mediaWritten++;
            }
            if ($rewriteFields !== []) {
                $pdo = getDB();
                $sets = [];
                $params = [':id' => $newNodeId];
                foreach ($rewriteFields as $field => $newUrl) {
                    $sets[] = "$field = :$field";
                    $params[':' . $field] = $newUrl;
                }
                $pdo->prepare("UPDATE nodes SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
            }
        } elseif (!$restoreMedia) {
            // Strip media URLs we know we won't have
            $pdo = getDB();
            $pdo->prepare("UPDATE nodes SET image_url = NULL, icon_url = NULL, audio_url = NULL, video_url = NULL, pdf_url = NULL WHERE id = :id")
                ->execute([':id' => $newNodeId]);
        }

        // Link keywords
        foreach ($n['keyword_refs'] ?? [] as $kwRef) {
            if (!isset($kwRefToId[$kwRef])) continue;
            $kid = $kwRefToId[$kwRef];
            $pdo = getDB();
            $pdo->prepare("INSERT IGNORE INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid)")
                ->execute([':nid' => $newNodeId, ':kid' => $kid]);
        }

        // Queue portal target update
        if (($n['node_type'] ?? 'object') === 'portal') {
            $portalUpdates[] = [
                'node_id' => $newNodeId,
                'target_ref' => $n['target_galaxy_ref'] ?? null,
                'target_slug' => $n['target_galaxy_slug'] ?? null,
            ];
        }
    }

    $result = [
        'action' => $action,
        'new_id' => $targetId,
        'portal_updates' => $portalUpdates,
        'media_written' => $mediaWritten,
        'media_skipped' => $mediaSkipped,
    ];

        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
