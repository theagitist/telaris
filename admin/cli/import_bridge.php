#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generic bridge import CLI.
 *
 * Usage:
 *   php admin/cli/import_bridge.php <bridge> [options...]
 *
 * The first positional argument selects the bridge handler. Remaining flags
 * are bridge-specific.
 *
 * For Mocambos (the first bridge):
 *   php admin/cli/import_bridge.php mocambos
 *   php admin/cli/import_bridge.php mocambos --api-base=URL --list
 *   php admin/cli/import_bridge.php mocambos --api-base=URL --galaxia=SLUG [--no-media] [--limit=N] [--quiet] [--full]
 *
 * Running with no flags after the bridge name drops into interactive mode if
 * the bridge supports it.
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/clustering.php';
require_once __DIR__ . '/../../inc/bridges/_lib.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

// First positional arg = bridge name; strip it before getopt sees argv.
$bridgeName = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') || str_starts_with($arg, '-')) continue;
    $bridgeName = $arg;
    break;
}

if ($bridgeName === '') {
    fwrite(STDERR, "Usage: php admin/cli/import_bridge.php <bridge> [options...]\n");
    if (count(bridges_active()) === 0) {
        fwrite(STDERR, "No bridges are enabled in this instance's config.php (TELARIS_BRIDGES).\n");
    } else {
        fwrite(STDERR, "Enabled bridges: " . implode(', ', bridges_active()) . "\n");
    }
    exit(1);
}

if (!bridges_name_is_valid($bridgeName)) {
    fwrite(STDERR, "Error: invalid bridge name '{$bridgeName}'\n");
    exit(1);
}

if (!bridges_is_active($bridgeName)) {
    fwrite(STDERR, "Error: bridge '{$bridgeName}' is not enabled in this instance's config.php (TELARIS_BRIDGES).\n");
    exit(1);
}

if (!bridges_load($bridgeName)) {
    fwrite(STDERR, "Error: bridge '{$bridgeName}' handler file is missing.\n");
    exit(1);
}

$runFn = $bridgeName . '_run_cli';
if (!function_exists($runFn)) {
    fwrite(STDERR, "Error: bridge '{$bridgeName}' does not implement a CLI handler.\n");
    exit(1);
}

// Parse remaining flags. getopt() ignores positional args automatically.
$opts = getopt('', ['api-base:', 'galaxia:', 'list', 'no-media', 'limit:', 'quiet', 'full']);
$interactive = empty($opts) || (count($opts) === 1 && isset($opts['quiet']));

exit($runFn($opts, $interactive));
