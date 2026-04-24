<?php
declare(strict_types=1);

/**
 * Cron self-management for the snapshot scheduler.
 *
 * The app writes its own line into the PHP user's (www-data) crontab, bracketed
 * by marker comments so install/uninstall are idempotent. The installed line
 * runs the scheduler every 15 minutes and appends output to a log file the
 * admin UI displays.
 */

const CRON_MARKER_START = '# >>> telaris snapshot scheduler >>>';
const CRON_MARKER_END   = '# <<< telaris snapshot scheduler <<<';

/** Absolute path to the snapshot_run_scheduled.php CLI script. */
function cron_script_path(): string {
    return dirname(__DIR__) . '/admin/cli/snapshot_run_scheduled.php';
}

/** Path to the cron log (appended to by the installed crontab line). */
function cron_log_path(): string {
    $dir = defined('LOG_DIR') ? LOG_DIR : (dirname(__DIR__) . '/logs');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return rtrim($dir, '/') . '/snapshot_cron.log';
}

/** The exact crontab line the app installs. */
function cron_scheduled_line(): string {
    $php = cron_php_binary();
    $script = cron_script_path();
    $log = cron_log_path();
    // Run every 15 minutes; the script decides when to actually capture.
    return '*/15 * * * * ' . $php . ' ' . $script . ' >> ' . $log . ' 2>&1';
}

function cron_php_binary(): string {
    // Prefer the CLI binary that matches the running version; fall back to `php`.
    $cli = PHP_BINDIR . '/php';
    if (is_executable($cli)) return $cli;
    return '/usr/bin/php';
}

/**
 * Is the system cron service active? Returns ['active' => bool, 'message' => str].
 */
function cron_service_status(): array {
    $out = []; $rc = 0;
    @exec('systemctl is-active cron 2>&1', $out, $rc);
    $msg = trim(implode("\n", $out));
    if ($rc === 0 && $msg === 'active') {
        return ['active' => true, 'message' => 'active'];
    }
    // Fallback: pgrep
    $out2 = [];
    @exec('pgrep -x cron 2>/dev/null', $out2);
    if (!empty($out2)) return ['active' => true, 'message' => 'running'];
    return ['active' => false, 'message' => $msg !== '' ? $msg : 'inactive'];
}

/**
 * Return the current user's crontab contents. Empty string means no crontab yet.
 * Throws only on unexpected errors (missing binary, permission denied).
 */
function cron_current_crontab(): string {
    $out = []; $rc = 0;
    @exec('crontab -l 2>&1', $out, $rc);
    $txt = implode("\n", $out);
    if ($rc !== 0) {
        if (stripos($txt, 'no crontab') !== false) return '';
        throw new RuntimeException('Unable to read crontab: ' . $txt);
    }
    return $txt;
}

function cron_is_installed(): bool {
    try {
        $c = cron_current_crontab();
    } catch (Throwable $e) {
        return false;
    }
    return strpos($c, CRON_MARKER_START) !== false;
}

/**
 * Install (or reinstall) the scheduler block in the crontab. Replaces any
 * existing block between our markers.
 */
function cron_install(): void {
    $current = cron_current_crontab();
    $clean = cron_strip_block($current);
    $block = CRON_MARKER_START . "\n"
           . "# Managed by Telaris admin. Do not edit between these markers.\n"
           . cron_scheduled_line() . "\n"
           . CRON_MARKER_END;
    $new = ($clean === '' ? '' : rtrim($clean, "\n") . "\n\n") . $block . "\n";
    cron_write_crontab($new);
}

function cron_uninstall(): void {
    $current = cron_current_crontab();
    $clean = cron_strip_block($current);
    cron_write_crontab($clean);
}

function cron_strip_block(string $crontab): string {
    if ($crontab === '') return '';
    $lines = preg_split('/\r?\n/', $crontab);
    $out = [];
    $inside = false;
    foreach ($lines as $ln) {
        if (strpos($ln, CRON_MARKER_START) !== false) { $inside = true; continue; }
        if ($inside && strpos($ln, CRON_MARKER_END) !== false) { $inside = false; continue; }
        if ($inside) continue;
        $out[] = $ln;
    }
    while (!empty($out) && trim(end($out)) === '') array_pop($out);
    return implode("\n", $out);
}

function cron_write_crontab(string $body): void {
    $tmp = tempnam(sys_get_temp_dir(), 'telaris-cron-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp file for crontab write.');
    }
    $content = $body === '' ? '' : rtrim($body, "\n") . "\n";
    if (file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write temp crontab file.');
    }
    $out = []; $rc = 0;
    @exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    @unlink($tmp);
    if ($rc !== 0) {
        throw new RuntimeException('crontab install failed: ' . trim(implode("\n", $out)));
    }
}

/** Last N lines of the cron log. */
function cron_recent_log(int $lines = 40): string {
    $path = cron_log_path();
    if (!file_exists($path)) return '';
    $lines = max(1, min(500, $lines));
    $out = [];
    @exec('tail -n ' . $lines . ' ' . escapeshellarg($path) . ' 2>/dev/null', $out);
    return implode("\n", $out);
}

/**
 * Aggregate status for the admin UI.
 */
function cron_status_summary(): array {
    $service = cron_service_status();
    $installed = cron_is_installed();
    $line = cron_scheduled_line();
    $logPath = cron_log_path();
    $logExists = file_exists($logPath);
    $logSize = $logExists ? filesize($logPath) : 0;
    $logMtime = $logExists ? gmdate('Y-m-d H:i:s', (int)filemtime($logPath)) . ' UTC' : null;
    return [
        'service_active' => $service['active'],
        'service_message' => $service['message'],
        'installed' => $installed,
        'line' => $line,
        'log_path' => $logPath,
        'log_exists' => $logExists,
        'log_size' => $logSize,
        'log_mtime' => $logMtime,
        'recent_log' => cron_recent_log(40),
    ];
}
