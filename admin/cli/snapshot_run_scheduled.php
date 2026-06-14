#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: cron target. Checks the snapshot schedule and creates a snapshot if due.
 *
 * Always prints a single timestamped status line so the cron log in the admin
 * UI shows cron is alive (a quiet script would look broken).
 *
 * The crontab line is installed by the admin UI (see inc/cron.php). Manual
 * install is also fine. Recommended cadence: every 15 minutes.
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';
require_once __DIR__ . '/../../inc/db.php';

$stamp = '[' . gmdate('Y-m-d H:i:s') . ' UTC]';

try {
    $newId = snapshot_run_if_due();
} catch (Throwable $e) {
    fwrite(STDERR, $stamp . ' ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($newId !== null) {
    $row = snapshot_get($newId);
    $mb = $row !== null ? number_format(((int)$row['size_bytes']) / 1048576, 2) : '?';
    echo $stamp . " created snapshot #{$newId} ({$mb} MB)\n";
} else {
    echo $stamp . " no-op (schedule disabled or not yet due)\n";
}

// Self-enrolment maintenance: reclaim expired/used login tokens and abandoned
// never-confirmed enrolments. Both are cheap indexed deletes that normally
// touch zero rows, so running each tick is fine; a failure here must not fail
// the snapshot run, so it is isolated in its own try. When it does reclaim
// something, it prints an extra status line beyond the snapshot line above.
const STALE_ENROLLMENT_DAYS = 30; // retention threshold for never-confirmed enrolments
try {
    $tokens = db_gc_login_tokens();
    $stale  = db_gc_unconfirmed_enrollments(STALE_ENROLLMENT_DAYS);
    if ($tokens > 0 || $stale > 0) {
        echo $stamp . " gc: {$tokens} login token(s), {$stale} abandoned enrolment(s)\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $stamp . ' gc ERROR: ' . $e->getMessage() . "\n");
}

exit(0);
