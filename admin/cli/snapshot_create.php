#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: create a snapshot of the current system.
 *
 * Usage:
 *   php admin/cli/snapshot_create.php [--note="..."]
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';

$opts = getopt('', ['note::', 'quiet', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php admin/cli/snapshot_create.php [--note=\"...\"]\n";
    exit(0);
}

$note = isset($opts['note']) ? (string)$opts['note'] : null;
$quiet = isset($opts['quiet']);

try {
    $id = snapshot_create($note, 'manual', null);
} catch (Throwable $e) {
    fwrite(STDERR, "Snapshot create failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    $row = snapshot_get($id);
    if ($row !== null) {
        $mb = number_format(((int)$row['size_bytes']) / 1048576, 2);
        echo "Snapshot #{$id} created: {$row['filename']} ({$mb} MB)\n";
    } else {
        echo "Snapshot #{$id} created.\n";
    }
}
exit(0);
