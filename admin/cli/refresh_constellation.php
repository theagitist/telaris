#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: Refresh (re-import) a bridge-imported constellation.
 *
 * Bridge-agnostic. Routes to whichever bridge stamped the constellation's
 * import_source via the optional handler hook {name}_cli_args_from_source().
 * Bridges that don't expose that hook are not refreshable this way and the
 * script refuses with a helpful message; in that case the operator should
 * re-run the bridge's own import_bridge.php call manually.
 *
 * Interactive mode (no args):
 *   php admin/cli/refresh_constellation.php
 *
 * Non-interactive (for automation):
 *   php admin/cli/refresh_constellation.php --id=10 [--no-media] [--limit=N] [--full]
 *   php admin/cli/refresh_constellation.php --list
 *
 * Options:
 *   --list       List all imported constellations with their bridge source
 *   --id=N       Constellation ID to refresh
 *   --no-media   Forwarded to the bridge import (if supported by the bridge)
 *   --limit=N    Forwarded to the bridge import (if supported by the bridge)
 *   --full       Forwarded to the bridge import (if supported by the bridge)
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/clustering.php';
require_once __DIR__ . '/../../inc/bridges/_lib.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$opts = getopt('', ['id:', 'list', 'no-media', 'limit:', 'full']);
$listMode = isset($opts['list']);
$constellationId = isset($opts['id']) ? (int)$opts['id'] : 0;
$fullRefresh = isset($opts['full']);
$noMedia = isset($opts['no-media']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;

$interactive = ($constellationId === 0 && !$listMode);

db_ensure_constellations_import_source_column();

// ── Build list of imported constellations (any bridge) ───────────────────────

$constellations = db_get_constellations();
$importedConstellations = [];
foreach ($constellations as $c) {
    $source = $c['import_source'] ?? null;
    if ($source === null || $source === '') continue;
    $s = json_decode($source, true);
    if (!is_array($s) || !isset($s['source']) || $s['source'] === '') continue;
    $importedConstellations[] = [
        'id' => (int)$c['id'],
        'name' => $c['name'],
        'bridge' => (string)$s['source'],
        'source' => $s,
    ];
}

// ── List mode ────────────────────────────────────────────────────────────────

function printConstellationList(array $constellations): void {
    echo "\nImported constellations:\n\n";
    printf("  %-4s %-30s %-12s %s\n", 'ID', 'NAME', 'NODES', 'BRIDGE');
    printf("  %-4s %-30s %-12s %s\n", str_repeat('-', 4), str_repeat('-', 30), str_repeat('-', 12), str_repeat('-', 30));
    foreach ($constellations as $c) {
        $nodes = db_get_nodes((int)$c['id']);
        printf("  %-4d %-30s %-12d %s\n", (int)$c['id'], mb_substr($c['name'], 0, 30), count($nodes), $c['bridge']);
    }
    echo "\n";
}

if ($listMode) {
    printConstellationList($importedConstellations);
    exit(0);
}

// ── Interactive: choose constellation ────────────────────────────────────────

if ($constellationId === 0 && $interactive) {
    echo "\n\033[1mRefresh Imported Constellation\033[0m\n";

    if (empty($importedConstellations)) {
        echo "\nNo imported constellations found. Run 'admin/cli/import_bridge.php <bridge>' first.\n\n";
        exit(0);
    }

    echo "\nImported constellations:\n\n";
    foreach ($importedConstellations as $i => $ic) {
        $nodes = db_get_nodes($ic['id']);
        printf("  \033[1m%d)\033[0m %s (ID %d, %d nodes) — bridge: %s\n", $i + 1, $ic['name'], $ic['id'], count($nodes), $ic['bridge']);
    }
    echo "\n";

    echo 'Select constellation number (or type ID): ';
    $choice = trim(fgets(STDIN) ?: '');
    if (ctype_digit($choice) && (int)$choice <= count($importedConstellations) && (int)$choice >= 1) {
        $constellationId = $importedConstellations[(int)$choice - 1]['id'];
    } elseif (ctype_digit($choice)) {
        $constellationId = (int)$choice;
    }

    if ($constellationId === 0) {
        echo "No constellation selected.\n";
        exit(0);
    }

    echo "\n";
    echo 'Download media files? (slower but includes images/audio/video) [y/N]: ';
    $mediaChoice = strtolower(trim(fgets(STDIN) ?: ''));
    $noMedia = !($mediaChoice === 'y' || $mediaChoice === 'yes');

    echo 'Limit number of items? (enter number, or press Enter for all) [0]: ';
    $limitInput = trim(fgets(STDIN) ?: '');
    $limit = $limitInput !== '' ? (int)$limitInput : 0;
}

if ($constellationId === 0) {
    fwrite(STDERR, "Error: --id=N is required (or run without arguments for interactive mode).\n");
    exit(1);
}

// ── Find constellation + dispatch to its bridge ──────────────────────────────

$constellation = db_get_constellation_by_id($constellationId);
if ($constellation === null) {
    fwrite(STDERR, "Error: Constellation ID {$constellationId} not found.\n");
    exit(1);
}

$importSource = db_get_constellation_import_source($constellationId);
if ($importSource === null || $importSource === '') {
    fwrite(STDERR, "Error: Constellation '{$constellation['name']}' has no import source (it was created manually).\n");
    exit(1);
}

$source = json_decode($importSource, true);
if (!is_array($source) || !isset($source['source']) || $source['source'] === '') {
    fwrite(STDERR, "Error: Constellation '{$constellation['name']}' has a malformed import_source field.\n");
    exit(1);
}

$bridgeName = (string)$source['source'];

if (!bridges_name_is_valid($bridgeName)) {
    fwrite(STDERR, "Error: invalid bridge name '{$bridgeName}' in import_source.\n");
    exit(1);
}

if (!bridges_is_active($bridgeName)) {
    fwrite(STDERR, "Error: bridge '{$bridgeName}' is not enabled on this instance (TELARIS_BRIDGES). Add it to config.php to refresh constellations imported via that bridge.\n");
    exit(1);
}

if (!bridges_load($bridgeName)) {
    fwrite(STDERR, "Error: bridge '{$bridgeName}' handler file is missing.\n");
    exit(1);
}

$argsFn = $bridgeName . '_cli_args_from_source';
if (!function_exists($argsFn)) {
    fwrite(STDERR, "Error: bridge '{$bridgeName}' does not support refresh-from-source. Re-run the import manually via admin/cli/import_bridge.php {$bridgeName} ... with the original flags.\n");
    exit(1);
}

$bridgeArgs = $argsFn($source);
if ($bridgeArgs === null) {
    fwrite(STDERR, "Error: bridge '{$bridgeName}' cannot refresh this constellation (the stored import_source is incomplete).\n");
    exit(1);
}

// ── Confirm + shell out ──────────────────────────────────────────────────────

if ($interactive) {
    echo "\n";
    echo "  Constellation: {$constellation['name']} (ID {$constellationId})\n";
    echo "  Bridge:        {$bridgeName}\n";
    echo "  Media:         " . ($noMedia ? 'skip' : 'download') . "\n";
    echo "  Limit:         " . ($limit > 0 ? $limit : 'all') . "\n";
    echo "\n  \033[33mThis will re-import from source (incremental by default; --full deletes and re-creates).\033[0m\n\n";
    echo 'Proceed? [y/N]: ';
    $confirm = strtolower(trim(fgets(STDIN) ?: ''));
    if ($confirm !== 'y' && $confirm !== 'yes') {
        echo "Aborted.\n";
        exit(0);
    }
    echo "\n";
} else {
    echo "\nRefreshing: {$constellation['name']} (ID {$constellationId}, bridge: {$bridgeName})\n\n";
}

$cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/import_bridge.php') . ' ' . escapeshellarg($bridgeName);
foreach ($bridgeArgs as $arg) {
    $cmd .= ' ' . escapeshellarg($arg);
}
if ($noMedia) $cmd .= ' --no-media';
if ($fullRefresh) $cmd .= ' --full';
if ($limit > 0) $cmd .= ' --limit=' . (int)$limit;

$process = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (is_resource($process)) {
    $exitCode = proc_close($process);
    exit($exitCode);
}
fwrite(STDERR, "Error: Failed to start import process.\n");
exit(1);
