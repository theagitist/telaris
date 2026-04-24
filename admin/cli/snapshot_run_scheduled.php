#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: cron target. Checks the snapshot schedule and creates a snapshot if due.
 * Quiet by default — prints only when something happens or fails.
 *
 * Recommended cron line (runs every 5 minutes; the script decides when to act):
 *   * /5 * * * * php /var/www/starmaps.polivoxia.ca/admin/cli/snapshot_run_scheduled.php
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';

try {
    $newId = snapshot_run_if_due();
} catch (Throwable $e) {
    fwrite(STDERR, "[snapshot_run_scheduled] " . $e->getMessage() . "\n");
    exit(1);
}

if ($newId !== null) {
    $row = snapshot_get($newId);
    $mb = $row !== null ? number_format(((int)$row['size_bytes']) / 1048576, 2) : '?';
    echo "[snapshot_run_scheduled] Created snapshot #{$newId} ({$mb} MB)\n";
}
exit(0);
