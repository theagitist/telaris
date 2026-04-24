#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: list all snapshots on disk.
 *
 * Usage:
 *   php admin/cli/snapshot_list.php
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';

$rows = snapshot_list();
if (empty($rows)) {
    echo "No snapshots.\n";
    exit(0);
}

printf("%-4s  %-19s  %-10s  %-9s  %-30s  %s\n", 'ID', 'Created (UTC)', 'Size', 'Type', 'Filename', 'Note');
echo str_repeat('-', 110) . "\n";
foreach ($rows as $r) {
    $size = (int)$r['size_bytes'];
    $sizeStr = $size > 1048576 ? number_format($size / 1048576, 1) . ' MB' : ($size > 1024 ? number_format($size / 1024, 1) . ' KB' : $size . ' B');
    $missing = $r['file_exists'] ? '' : ' (MISSING)';
    printf("%-4s  %-19s  %-10s  %-9s  %-30s  %s\n",
        $r['id'],
        substr((string)$r['created_at'], 0, 19),
        $sizeStr,
        $r['trigger_type'],
        substr($r['filename'], 0, 30) . $missing,
        $r['note'] ?? ''
    );
}
exit(0);
