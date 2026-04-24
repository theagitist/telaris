#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: restore a snapshot. WIPES the system and replaces it with the snapshot's
 * state. All snapshots created after the restored one are deleted.
 *
 * Usage:
 *   php admin/cli/snapshot_restore.php --id=N [--force] [--allow-no-admin]
 *   php admin/cli/snapshot_restore.php --file=PATH [--force] [--allow-no-admin]
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';

$opts = getopt('', ['id::', 'file::', 'force', 'allow-no-admin', 'help']);

if (isset($opts['help']) || (empty($opts['id']) && empty($opts['file']))) {
    echo "Usage: php admin/cli/snapshot_restore.php (--id=N | --file=PATH) [--force] [--allow-no-admin]\n";
    echo "  --id=N             Snapshot id (see snapshot_list.php)\n";
    echo "  --file=PATH        Restore directly from a backup file path\n";
    echo "  --force            Skip the y/N prompt\n";
    echo "  --allow-no-admin   Permit restoring snapshots that contain no admin user\n";
    exit(empty($opts['id']) && empty($opts['file']) ? 1 : 0);
}

$confirmNoAdmin = isset($opts['allow-no-admin']);

if (!empty($opts['id'])) {
    $id = (int)$opts['id'];
    $row = snapshot_get($id);
    if ($row === null) {
        fwrite(STDERR, "Snapshot #$id not found.\n");
        exit(2);
    }
    echo "About to restore snapshot #$id (created {$row['created_at']}, file {$row['filename']}).\n";
    echo "This will WIPE the system and delete all snapshots newer than this one.\n";
    if (!isset($opts['force'])) {
        echo "Type RESTORE to confirm: ";
        $line = fgets(STDIN);
        if ($line === false || trim($line) !== 'RESTORE') {
            echo "Cancelled.\n";
            exit(0);
        }
    }
    try {
        $report = snapshot_restore($id, $confirmNoAdmin);
    } catch (Throwable $e) {
        fwrite(STDERR, "Restore failed: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    // Restore directly from a file path (no snapshot row, no later-snapshot deletion)
    $path = (string)$opts['file'];
    if (!is_file($path)) {
        fwrite(STDERR, "File not found: $path\n");
        exit(2);
    }
    echo "About to restore from $path. This will WIPE the system.\n";
    if (!isset($opts['force'])) {
        echo "Type RESTORE to confirm: ";
        $line = fgets(STDIN);
        if ($line === false || trim($line) !== 'RESTORE') {
            echo "Cancelled.\n";
            exit(0);
        }
    }
    try {
        $summary = backup_inspect_file($path);
        if (!$confirmNoAdmin && empty($summary['has_admin_user'])) {
            fwrite(STDERR, "Snapshot has no admin user. Pass --allow-no-admin to override.\n");
            exit(1);
        }
        $report = backup_restore_from_file($path, [
            'mode' => 'wipe_all',
            'restore_users' => true,
            'restore_media' => true,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "Restore failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Restore complete:\n";
echo "  Galaxies created: {$report['galaxies_created']}\n";
echo "  Users created:    {$report['users_created']}\n";
echo "  Media written:    {$report['media_files_written']}\n";
if (isset($report['snapshots_deleted_after_restore'])) {
    echo "  Later snapshots deleted: {$report['snapshots_deleted_after_restore']}\n";
}
if (!empty($report['galaxies_failed'])) {
    echo "  Failures:\n";
    foreach ($report['galaxies_failed'] as $f) {
        echo "    - " . ($f['name'] ?: $f['ref']) . ": " . $f['error'] . "\n";
    }
    exit(1);
}
exit(0);
