#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: prune keyword_position_history rows older than --max-age-days (default
 * 90). Append-only by design; every canvas drag adds a row. Without pruning
 * the table dwarfs the rest of the DB after months of editorial work.
 *
 * Suggested cron line (once daily):
 *   17 4 * * * cd /var/www/starmaps.polivoxia.ca && php admin/cli/prune_history.php
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';

$opts = getopt('', ['max-age-days:', 'quiet']);
$days = isset($opts['max-age-days']) ? max(1, (int)$opts['max-age-days']) : 90;
$quiet = isset($opts['quiet']);

$stamp = '[' . gmdate('Y-m-d H:i:s') . ' UTC]';

try {
    $deleted = db_prune_keyword_position_history($days);
} catch (Throwable $e) {
    fwrite(STDERR, $stamp . ' ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    echo $stamp . " pruned keyword_position_history (>{$days}d): {$deleted} row(s)\n";
}
exit(0);
