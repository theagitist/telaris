#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: restore from a backup file.
 *
 * Usage:
 *   php admin/cli/backup_import.php --input=FILE [options]
 *
 * Options:
 *   --input=FILE                Required. Path to a .telaris-backup file.
 *   --mode=overwrite            (default) Overwrite galaxies whose slug already exists.
 *   --mode=rename               Always create new galaxies; rename on conflict.
 *   --rename-suffix=" (restored)"  Suffix to append when renaming.
 *   --skip-users                Don't restore users.
 *   --replace-users             Update existing users (matched by email) instead of skipping.
 *   --replace-passwords         Also overwrite password hashes (only with --replace-users).
 *   --no-media                  Don't write media files; rows still restored.
 *   --inspect-only              Print summary and exit without restoring.
 *   --force                     Skip the y/N prompt.
 *   --quiet                     Print only errors and the final summary.
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/backup.php';

$opts = getopt('', [
    'input:', 'mode::', 'rename-suffix::',
    'skip-users', 'replace-users', 'replace-passwords',
    'no-media', 'inspect-only', 'force', 'quiet', 'help',
]);

if (isset($opts['help']) || empty($opts['input'])) {
    echo "Usage: php admin/cli/backup_import.php --input=FILE [options]\n";
    echo "  --input=FILE              Required. Path to a .telaris-backup file.\n";
    echo "  --mode=overwrite|rename   Default: overwrite.\n";
    echo "  --rename-suffix=' (restored)'\n";
    echo "  --skip-users              Don't restore users.\n";
    echo "  --replace-users           Update existing users by email (default: skip).\n";
    echo "  --replace-passwords       Overwrite password hashes too.\n";
    echo "  --no-media                Don't write media files.\n";
    echo "  --inspect-only            Print summary and exit.\n";
    echo "  --force                   Skip the y/N confirmation prompt.\n";
    echo "  --quiet                   Minimal output.\n";
    exit(empty($opts['input']) ? 1 : 0);
}

$quiet = isset($opts['quiet']);
$path = (string)$opts['input'];
if (!is_file($path)) {
    fwrite(STDERR, "Input file not found: $path\n");
    exit(2);
}

$mode = $opts['mode'] ?? 'overwrite';
if (!in_array($mode, ['overwrite', 'rename'], true)) {
    fwrite(STDERR, "Invalid --mode: must be overwrite or rename.\n");
    exit(2);
}

try {
    $summary = backup_inspect_file($path);
} catch (Throwable $e) {
    fwrite(STDERR, "Inspect failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    echo "Backup summary\n";
    echo "  Format v{$summary['format_version']} · App {$summary['app_version']}\n";
    echo "  Created at:    {$summary['created_at']}\n";
    echo "  Galaxies:      {$summary['galaxy_count']}\n";
    echo "  Wormholes:     {$summary['node_count']}\n";
    echo "  Keywords:      {$summary['keyword_count']}\n";
    echo "  Users:         {$summary['user_count']}" . ($summary['has_admin_user'] ? '' : ' (NO ADMIN USER)') . "\n";
    echo "  Media files:   {$summary['media_blob_count']} (" . number_format($summary['media_bytes'] / 1048576, 1) . " MB)\n";
}

if (isset($opts['inspect-only'])) {
    exit(0);
}

// Build per-galaxy options for granular mode (include all)
$galaxyOpts = [];
foreach ($summary['galaxies'] as $g) {
    $galaxyOpts[$g['ref']] = [
        'include' => true,
        'conflict' => $mode,
        'rename_suffix' => $opts['rename-suffix'] ?? ' (restored)',
    ];
}

if (!isset($opts['force'])) {
    echo "\nProceed with restore? [y/N] ";
    $line = fgets(STDIN);
    if ($line === false || strtolower(trim($line)) !== 'y') {
        echo "Cancelled.\n";
        exit(0);
    }
}

$restoreOpts = [
    'mode' => 'granular',
    'restore_users' => !isset($opts['skip-users']),
    'restore_media' => !isset($opts['no-media']),
    'users_replace_existing' => isset($opts['replace-users']),
    'users_replace_password' => isset($opts['replace-users']) && isset($opts['replace-passwords']),
    'rename_suffix_default' => $opts['rename-suffix'] ?? ' (restored)',
    'galaxies' => $galaxyOpts,
];

try {
    $report = backup_restore_from_file($path, $restoreOpts);
} catch (Throwable $e) {
    fwrite(STDERR, "Restore failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Restore complete:\n";
echo "  Galaxies — created: {$report['galaxies_created']}, overwritten: {$report['galaxies_overwritten']}, renamed: {$report['galaxies_renamed']}, skipped: {$report['galaxies_skipped']}\n";
echo "  Users — created: {$report['users_created']}, updated: {$report['users_updated']}, skipped: {$report['users_skipped']}\n";
echo "  Media — written: {$report['media_files_written']}, skipped: {$report['media_files_skipped']}\n";
if (!empty($report['galaxies_failed'])) {
    echo "  Failures:\n";
    foreach ($report['galaxies_failed'] as $f) {
        echo "    - " . ($f['name'] ?: $f['ref']) . ": " . $f['error'] . "\n";
    }
    exit(1);
}
exit(0);
