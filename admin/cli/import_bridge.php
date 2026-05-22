#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generic bridge import CLI.
 *
 * Usage:
 *   php admin/cli/import_bridge.php <bridge> [bridge-specific flags...]
 *
 * The first positional argument selects the bridge handler. The dispatcher
 * does not parse any flags; each bridge owns its own flag vocabulary and
 * parses with getopt() inside its own {name}_run_cli() function.
 *
 * Running with no flags after the bridge name typically drops into the
 * bridge's interactive mode if it supports one. See the per-bridge
 * documentation for the available flags and behaviour.
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/clustering.php';
require_once __DIR__ . '/../../inc/bridges/_lib.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

// First positional arg = bridge name.
$bridgeName = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') || str_starts_with($arg, '-')) continue;
    $bridgeName = $arg;
    break;
}

if ($bridgeName === '') {
    fwrite(STDERR, "Usage: php admin/cli/import_bridge.php <bridge> [bridge-specific flags...]\n");
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

exit($runFn());
