#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: Refresh (re-import) a Mocambos constellation.
 *
 * Interactive mode (no args):
 *   php admin/cli/refresh_constellation.php
 *
 * Non-interactive (for automation):
 *   php admin/cli/refresh_constellation.php --id=10 [--no-media] [--limit=N]
 *   php admin/cli/refresh_constellation.php --list
 *
 * Options:
 *   --list       List all constellations with their import source
 *   --id=N       Constellation ID to refresh
 *   --no-media   Skip media file downloads
 *   --limit=N    Import only the first N items
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/clustering.php';

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

// ── Build list of imported constellations ────────────────────────────────────

$constellations = db_get_constellations();
$importedConstellations = [];
foreach ($constellations as $c) {
    $source = $c['import_source'] ?? null;
    if ($source !== null && $source !== '') {
        $s = json_decode($source, true);
        if (is_array($s) && ($s['source'] ?? '') === 'mocambos') {
            $importedConstellations[] = [
                'id' => (int)$c['id'],
                'name' => $c['name'],
                'slug' => $s['galaxia_slug'] ?? '?',
                'api_base' => $s['api_base'] ?? '?',
            ];
        }
    }
}

// ── List mode ────────────────────────────────────────────────────────────────

function printConstellationList(array $constellations, array $allConstellations): void {
    echo "\nConstellations:\n\n";
    printf("  %-4s %-30s %-12s %s\n", 'ID', 'NAME', 'NODES', 'IMPORT SOURCE');
    printf("  %-4s %-30s %-12s %s\n", str_repeat('-', 4), str_repeat('-', 30), str_repeat('-', 12), str_repeat('-', 40));
    foreach ($allConstellations as $c) {
        $nodes = db_get_nodes((int)$c['id']);
        $source = $c['import_source'] ?? null;
        $sourceLabel = '(manual)';
        if ($source !== null && $source !== '') {
            $s = json_decode($source, true);
            if (is_array($s)) {
                $sourceLabel = ($s['source'] ?? '?') . ' / ' . ($s['galaxia_slug'] ?? '?');
            }
        }
        printf("  %-4d %-30s %-12d %s\n", (int)$c['id'], mb_substr($c['name'], 0, 30), count($nodes), $sourceLabel);
    }
    echo "\n";
}

if ($listMode) {
    printConstellationList($importedConstellations, $constellations);
    exit(0);
}

// ── Interactive: choose constellation ────────────────────────────────────────

if ($constellationId === 0 && $interactive) {
    echo "\n\033[1mRefresh Mocambos Constellation\033[0m\n";

    if (empty($importedConstellations)) {
        echo "\nNo Mocambos-imported constellations found. Use 'import_bridge.php mocambos' first.\n\n";
        exit(0);
    }

    echo "\nImported constellations:\n\n";
    foreach ($importedConstellations as $i => $ic) {
        $nodes = db_get_nodes($ic['id']);
        printf("  \033[1m%d)\033[0m %s (ID %d, %d nodes) — %s\n", $i + 1, $ic['name'], $ic['id'], count($nodes), $ic['slug']);
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

// ── Find constellation ───────────────────────────────────────────────────────

$constellation = db_get_constellation_by_id($constellationId);
if ($constellation === null) {
    fwrite(STDERR, "Error: Constellation ID {$constellationId} not found.\n");
    exit(1);
}

$importSource = db_get_constellation_import_source($constellationId);
if ($importSource === null || $importSource === '') {
    fwrite(STDERR, "Error: Constellation '{$constellation['name']}' has no import source — it was created manually.\n");
    exit(1);
}

$source = json_decode($importSource, true);
if (!is_array($source) || ($source['source'] ?? '') !== 'mocambos') {
    fwrite(STDERR, "Error: Constellation '{$constellation['name']}' was not imported from Mocambos.\n");
    exit(1);
}

$apiBase = $source['api_base'] ?? '';
$galaxiaSlug = $source['galaxia_slug'] ?? '';

// ── Interactive: confirm ─────────────────────────────────────────────────────

if ($interactive) {
    echo "\n";
    echo "  Constellation: {$constellation['name']} (ID {$constellationId})\n";
    echo "  Source:         {$apiBase} / {$galaxiaSlug}\n";
    echo "  Media:          " . ($noMedia ? 'skip' : 'download') . "\n";
    echo "  Limit:          " . ($limit > 0 ? $limit : 'all') . "\n";
    echo "\n  \033[33mThis will delete all existing nodes and re-import from source.\033[0m\n\n";
    echo 'Proceed? [y/N]: ';
    $confirm = strtolower(trim(fgets(STDIN) ?: ''));
    if ($confirm !== 'y' && $confirm !== 'yes') {
        echo "Aborted.\n";
        exit(0);
    }
    echo "\n";
} else {
    echo "\nRefreshing: {$constellation['name']} (ID {$constellationId})\n";
    echo "Source: {$apiBase} / {$galaxiaSlug}\n\n";
}

// ── Delegate to import script ────────────────────────────────────────────────

$cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/import_bridge.php')
    . ' mocambos'
    . ' --api-base=' . escapeshellarg($apiBase)
    . ' --galaxia=' . escapeshellarg($galaxiaSlug);
if ($noMedia) $cmd .= ' --no-media';
if ($fullRefresh) $cmd .= ' --full';
if ($limit > 0) $cmd .= ' --limit=' . $limit;

$process = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (is_resource($process)) {
    $exitCode = proc_close($process);
    exit($exitCode);
} else {
    fwrite(STDERR, "Error: Failed to start import process.\n");
    exit(1);
}
