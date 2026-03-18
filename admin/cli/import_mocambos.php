#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: Import a Mocambos galaxia into Telaris.
 *
 * Interactive mode (no args):
 *   php admin/cli/import_mocambos.php
 *
 * Non-interactive (for automation):
 *   php admin/cli/import_mocambos.php --api-base=URL --galaxia=SLUG [--no-media] [--limit=N]
 *   php admin/cli/import_mocambos.php --api-base=URL --list
 *
 * Options:
 *   --api-base=URL    Mocambos API base URL
 *   --list            List available galaxias and exit
 *   --galaxia=SLUG    Galaxia slug to import
 *   --no-media        Skip media file downloads (faster, nodes still created)
 *   --limit=N         Import only the first N items (useful for testing)
 *   --quiet           Minimal output (errors and summary only)
 *   --full            Full re-import (delete all nodes first, skip incremental diff)
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/clustering.php';
require_once __DIR__ . '/../../inc/mocambos-download.php';
require_once __DIR__ . '/../../inc/mocambos-sync.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

// ── Helpers ──────────────────────────────────────────────────────────────────

$_CLI_QUIET = false;

function cli_log(string $level, string $msg, bool $verbose = false): void {
    global $_CLI_QUIET;
    if ($_CLI_QUIET && $verbose) return;
    if ($_CLI_QUIET && $level === 'INFO') return;
    $ts = date('H:i:s');
    $prefix = match($level) {
        'ERROR' => "\033[31m[ERR]\033[0m",
        'WARN' => "\033[33m[WRN]\033[0m",
        'OK' => "\033[32m[OK ]\033[0m",
        'DL' => "\033[35m[DL ]\033[0m",
        default => "\033[36m[INF]\033[0m",
    };
    echo "{$prefix} {$ts} {$msg}\n";
}

function cli_fetch_json(string $url): mixed {
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    return json_decode($body, true);
}

function cli_prompt(string $prompt, string $default = ''): string {
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $prompt . $suffix . ': ';
    $input = trim(fgets(STDIN) ?: '');
    return $input !== '' ? $input : $default;
}

function cli_confirm(string $prompt): bool {
    echo $prompt . ' [y/N]: ';
    $input = strtolower(trim(fgets(STDIN) ?: ''));
    return $input === 'y' || $input === 'yes';
}

// ── Parse arguments ──────────────────────────────────────────────────────────

$opts = getopt('', ['api-base:', 'galaxia:', 'list', 'no-media', 'limit:', 'quiet', 'full']);
$_CLI_QUIET = isset($opts['quiet']);
$fullRefresh = isset($opts['full']);

$apiBase = trim($opts['api-base'] ?? '');
$listMode = isset($opts['list']);
$galaxiaSlug = trim($opts['galaxia'] ?? '');
$noMedia = isset($opts['no-media']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;

$interactive = ($apiBase === '' && $galaxiaSlug === '' && !$listMode);

// ── Interactive: ask for API base ────────────────────────────────────────────

if ($apiBase === '') {
    if ($interactive) {
        echo "\n\033[1mMocambos Import\033[0m\n\n";
        $apiBase = cli_prompt('Mocambos API base URL', 'https://oya.mocambos.net/api/v2');
    } else {
        fwrite(STDERR, "Error: --api-base is required.\n");
        fwrite(STDERR, "Usage: php admin/cli/import_mocambos.php --api-base=URL --galaxia=SLUG\n");
        exit(1);
    }
}
$apiBase = rtrim($apiBase, '/');

// ── Fetch galaxia list ───────────────────────────────────────────────────────

cli_log('INFO', "Connecting to {$apiBase}...");

$galaxias = cli_fetch_json($apiBase . '/galaxia');
if (!is_array($galaxias)) {
    cli_log('ERROR', "Failed to fetch galaxia list from {$apiBase}/galaxia");
    exit(1);
}

// Fetch mucua map
$mucuaNameMap = [];
$mucuaSlugMap = []; // smid → slug (for URL construction)
$mucuaList = cli_fetch_json($apiBase . '/mucua');
if (is_array($mucuaList)) {
    foreach ($mucuaList as $m) {
        $mSmid = $m['smid'] ?? $m['uuid'] ?? null;
        if ($mSmid !== null) {
            $mucuaNameMap[$mSmid] = $m['name'] ?? $m['slug'] ?? (string)$mSmid;
            $mucuaSlugMap[$mSmid] = $m['slug'] ?? (string)$mSmid;
        }
    }
}
cli_log('INFO', 'Found ' . count($galaxias) . ' galaxia(s), ' . count($mucuaNameMap) . ' mucua(s)');

// Build galaxia info map
$galaxiaInfoMap = [];
$galaxiaIndexed = []; // numeric index for interactive selection
foreach ($galaxias as $i => $g) {
    $slug = $g['slug'] ?? '';
    $galaxiaInfoMap[$slug] = $g;
    $galaxiaIndexed[$i + 1] = $g;
}

// ── List mode ────────────────────────────────────────────────────────────────

if ($listMode) {
    echo "\nAvailable galaxias at {$apiBase}:\n\n";
    printf("  %-40s %-20s %s\n", 'SLUG', 'NAME', 'SMID');
    printf("  %-40s %-20s %s\n", str_repeat('-', 40), str_repeat('-', 20), str_repeat('-', 36));
    foreach ($galaxias as $g) {
        printf("  %-40s %-20s %s\n", $g['slug'] ?? '?', $g['name'] ?? '?', $g['smid'] ?? '?');
    }
    echo "\n";
    exit(0);
}

// ── Interactive: choose galaxia ──────────────────────────────────────────────

if ($galaxiaSlug === '' && $interactive) {
    echo "\nAvailable galaxias:\n\n";
    foreach ($galaxiaIndexed as $num => $g) {
        $name = $g['name'] ?? $g['slug'] ?? '?';
        $slug = $g['slug'] ?? '?';
        // Check if already imported
        $imported = '';
        db_ensure_constellations_import_source_column();
        $constellations = db_get_constellations();
        foreach ($constellations as $c) {
            if ($c['import_source'] !== null) {
                $src = json_decode($c['import_source'], true);
                if (is_array($src) && ($src['galaxia_slug'] ?? '') === $slug) {
                    $imported = " \033[33m(already imported)\033[0m";
                    break;
                }
            }
        }
        printf("  \033[1m%d)\033[0m %s — %s%s\n", $num, $name, $slug, $imported);
    }
    echo "\n";

    $choice = cli_prompt('Select galaxia number (or type slug)');
    if (ctype_digit($choice) && isset($galaxiaIndexed[(int)$choice])) {
        $galaxiaSlug = $galaxiaIndexed[(int)$choice]['slug'] ?? '';
    } else {
        $galaxiaSlug = $choice;
    }

    if ($galaxiaSlug === '') {
        echo "No galaxia selected.\n";
        exit(0);
    }
}

if ($galaxiaSlug === '') {
    fwrite(STDERR, "Error: --galaxia=SLUG is required.\n");
    exit(1);
}

// ── Interactive: options ─────────────────────────────────────────────────────

if ($interactive) {
    echo "\n";
    $noMedia = !cli_confirm('Download media files? (slower but includes images/audio/video)');
    $limitInput = cli_prompt('Limit number of items? (enter number, or press Enter for all)', '0');
    $limit = (int)$limitInput;
}

// ── Find the target galaxia ──────────────────────────────────────────────────

$galInfo = $galaxiaInfoMap[$galaxiaSlug] ?? null;
if ($galInfo === null) {
    // Try partial match
    foreach ($galaxiaInfoMap as $slug => $info) {
        if (str_contains($slug, $galaxiaSlug)) {
            $galInfo = $info;
            $galaxiaSlug = $slug;
            cli_log('INFO', "Matched galaxia slug: {$slug}");
            break;
        }
    }
}
if ($galInfo === null) {
    cli_log('ERROR', "Galaxia '{$galaxiaSlug}' not found. Use --list to see available galaxias.");
    exit(1);
}

// ── Interactive: confirm ─────────────────────────────────────────────────────

if ($interactive) {
    $galaxiaName = $galInfo['name'] ?? $galaxiaSlug;
    echo "\n";
    echo "  Galaxia:  {$galaxiaName} ({$galaxiaSlug})\n";
    echo "  API:      {$apiBase}\n";
    echo "  Media:    " . ($noMedia ? 'skip' : 'download') . "\n";
    echo "  Limit:    " . ($limit > 0 ? $limit : 'all') . "\n";
    echo "\n";
    if (!cli_confirm('Proceed with import?')) {
        echo "Aborted.\n";
        exit(0);
    }
    echo "\n";
}

$galaxiaSmid = $galInfo['smid'] ?? '';
$galaxiaName = $galInfo['name'] ?? $galaxiaSlug;
$defaultMucua = $galInfo['default_mucua'] ?? '';
$mucuaSlug = '';
if ($defaultMucua !== '' && isset($mucuaList)) {
    foreach ($mucuaList as $m) {
        if (($m['smid'] ?? '') === $defaultMucua) {
            $mucuaSlug = $m['slug'] ?? '';
            break;
        }
    }
}

cli_log('INFO', "Galaxia: {$galaxiaName} (slug={$galaxiaSlug}, smid={$galaxiaSmid})");

// ── Fetch all items ──────────────────────────────────────────────────────────

cli_log('INFO', 'Fetching media items...');
$allItems = [];
$page = 1;
while (true) {
    $data = cli_fetch_json($apiBase . '/acervo/find?pag_tamanho=100&pag_atual=' . $page);
    if (!is_array($data) || !isset($data['items'])) break;
    $pageCount = (int)($data['page_count'] ?? 1);
    foreach ($data['items'] as $item) {
        $item['_source_type'] = 'acervo';
        $allItems[] = $item;
    }
    cli_log('INFO', "  Acervo page {$page}/{$pageCount} (" . count($allItems) . " items)");
    if ($page >= $pageCount) break;
    $page++;
}

cli_log('INFO', 'Fetching blog articles...');
$page = 1;
while (true) {
    $data = cli_fetch_json($apiBase . '/blog/find?pag_tamanho=100&pag_atual=' . $page);
    if (!is_array($data) || !isset($data['items'])) break;
    $pageCount = (int)($data['page_count'] ?? 1);
    foreach ($data['items'] as $item) {
        $item['_source_type'] = 'blog';
        $allItems[] = $item;
    }
    if ($pageCount > 0) cli_log('INFO', "  Blog page {$page}/{$pageCount}");
    if ($page >= $pageCount) break;
    $page++;
}

// Filter by galaxia
$galaxiaItems = array_values(array_filter($allItems, fn($item) => ($item['galaxia_smid'] ?? '') === $galaxiaSmid));
cli_log('INFO', 'Total items for this galaxia: ' . count($galaxiaItems));

if ($limit > 0 && count($galaxiaItems) > $limit) {
    $galaxiaItems = array_slice($galaxiaItems, 0, $limit);
    cli_log('WARN', "Limited to {$limit} items (--limit)");
}

// ── Find or create constellation ─────────────────────────────────────────────

db_ensure_constellations_import_source_column();
db_ensure_nodes_icon_url_column();
db_ensure_nodes_clustering_columns();
db_ensure_nodes_import_slug_column();

$allConstellations = db_get_constellations();
$constellationId = null;
$isNew = true;
$isIncremental = false;

foreach ($allConstellations as $c) {
    if ($c['import_source'] !== null && $c['import_source'] !== '') {
        $source = json_decode($c['import_source'], true);
        if (is_array($source) && ($source['galaxia_slug'] ?? '') === $galaxiaSlug) {
            $constellationId = (int)$c['id'];
            $isNew = false;
            break;
        }
    }
}

if ($constellationId !== null && $fullRefresh) {
    cli_log('WARN', "Full refresh — clearing all nodes for re-import...");
    db_clear_constellation_nodes($constellationId);
} elseif ($constellationId !== null) {
    cli_log('INFO', "Existing constellation found (ID {$constellationId}), computing diff...");

    // Backfill import_slug for nodes that don't have it
    $backfilled = db_backfill_import_slugs($constellationId);
    if ($backfilled > 0) cli_log('INFO', "Backfilled {$backfilled} import slugs");

    $existingBySlug = db_get_nodes_by_import_slug($constellationId);
    $diff = mocambos_compute_diff($existingBySlug, $galaxiaItems, $mucuaNameMap);
    cli_log('OK', "Diff: " . count($diff['added']) . " new, " . count($diff['modified']) . " modified, " . count($diff['deleted']) . " deleted, " . $diff['unchanged'] . " unchanged");

    if (!empty($diff['deleted'])) {
        cli_log('INFO', "Deleting " . count($diff['deleted']) . " removed items...");
        mocambos_apply_deletions($diff['deleted'], $constellationId, getDB());
    }
    if (!empty($diff['modified'])) {
        cli_log('INFO', "Updating " . count($diff['modified']) . " modified items...");
        mocambos_apply_modifications($diff['modified'], $constellationId, getDB());
        foreach ($diff['modified'] as $mod) {
            cli_log('INFO', "  Updated: {$mod['slug']} [" . implode(', ', $mod['changes']) . "]", true);
        }
    }

    // Only new items go through the insert pipeline
    $galaxiaItems = array_map(fn($a) => $a['item'], $diff['added']);
    $expectedCount = count($galaxiaItems);
    $isIncremental = true;

    if ($expectedCount === 0 && empty($diff['modified']) && empty($diff['deleted'])) {
        cli_log('OK', "Everything is up to date — nothing to do.");
    }
} else {
    $galaxiaDesc = $galInfo['description'] ?? '';
    $baseSlug = db_slugify($galaxiaName);
    $slug = $baseSlug;
    $suffix = 2;
    while (true) {
        try {
            $constellationId = db_create_constellation($galaxiaName, $galaxiaDesc ?: '', $slug, 'abstract');
            break;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') && $suffix <= 20) {
                $slug = $baseSlug . '-' . $suffix++;
            } else {
                throw $e;
            }
        }
    }
    cli_log('OK', "Created constellation: {$galaxiaName} (ID {$constellationId})");
}

// Set import_source immediately
$importSource = json_encode([
    'source' => 'mocambos',
    'api_base' => $apiBase,
    'galaxia_slug' => $galaxiaSlug,
    'mucua_slug' => $mucuaSlug,
], JSON_THROW_ON_ERROR);
db_set_constellation_import_source($constellationId, $importSource);

// ── Import items ─────────────────────────────────────────────────────────────

$downloadBase = preg_replace('#/api/v2/?$#', '', $apiBase);
$expectedCount = count($galaxiaItems);
$importedCount = 0;
$errorCount = 0;
$startTime = microtime(true);

$writeLog = function(string $level, string $msg) { /* no-op for download function */ };

// Pre-create constellation upload dir with correct permissions
$uploadDir = UPLOAD_DIR;
if (!$noMedia) {
    $constDir = "{$uploadDir}/{$constellationId}";
    if (!is_dir($constDir) && !@mkdir($constDir, 0775, true)) {
        cli_log('WARN', "Cannot create {$constDir} — skipping all media downloads. Fix: sudo chmod -R g+w uploads/ && sudo chown -R " . get_current_user() . ":www-data uploads/");
        $noMedia = true;
    }
}

// ── Fast batch import (no media) ─────────────────────────────────────────────
if ($noMedia) {
    cli_log('INFO', "Fast import mode (no media)...");
    $pdo = getDB();

    // Prepare statements once
    $insertStmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, animation, constellation_id, node_type, audio_autoplay, video_autoplay, mucua_name, media_type, source_created_at, import_slug)
        VALUES (:name, :description, :url, :animation, :constellation_id, 'object', 1, 1, :mucua_name, :media_type, :source_created_at, :import_slug)
    ");
    $kwInsertStmt = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :cid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $kwLookupStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword AND constellation_id = :cid LIMIT 1");
    $nkInsertStmt = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid) ON DUPLICATE KEY UPDATE node_id=node_id");

    $batchSize = 500;
    $pdo->beginTransaction();

    foreach ($galaxiaItems as $idx => $item) {
        try {
            $nodeName = $item['title'] ?? ($item['slug'] ?? 'unknown');
            $nodeDesc = $item['description'] ?? '';
            $mediaType = $item['type'] ?? 'arquivo';
            $tags = $item['tags'] ?? [];
            if (!is_array($tags)) $tags = [];

            $itemMucuaSlugForUrl = $mucuaSlugMap[$item['mucua_smid'] ?? ''] ?? '';
            $nodeUrl = $downloadBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlugForUrl . '/' . ($item['slug'] ?? '');

            $animation = json_encode([
                'radius' => 5 + rand(0, 3), 'theta' => rand(0, 628) / 100,
                'phi' => rand(0, 314) / 100, 'speed' => 0.002 + (rand(0, 4) / 1000),
                'phase' => rand(0, 628) / 100,
            ], JSON_THROW_ON_ERROR);

            $itemMucuaSmid = $item['mucua_smid'] ?? null;
            $resolvedMucuaName = ($itemMucuaSmid !== null && isset($mucuaNameMap[$itemMucuaSmid])) ? $mucuaNameMap[$itemMucuaSmid] : null;
            $itemMediaType = ($item['_source_type'] ?? '') === 'blog' ? 'blog' : $mediaType;

            // Single INSERT with clustering metadata
            $insertStmt->execute([
                ':name' => $nodeName,
                ':description' => $nodeDesc ?: null,
                ':url' => $nodeUrl,
                ':animation' => $animation,
                ':constellation_id' => $constellationId,
                ':mucua_name' => $resolvedMucuaName,
                ':media_type' => $itemMediaType,
                ':source_created_at' => $item['created'] ?? null,
                ':import_slug' => $item['slug'] ?? null,
            ]);
            $nodeId = (int)$pdo->lastInsertId();

            // Keywords
            foreach ($tags as $tag) {
                $tag = trim($tag);
                if ($tag === '') continue;
                $kwInsertStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
                $kwId = (int)$pdo->lastInsertId();
                if ($kwId === 0) {
                    $kwLookupStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
                    $kwId = (int)($kwLookupStmt->fetchColumn() ?: 0);
                }
                if ($kwId > 0) {
                    $nkInsertStmt->execute([':nid' => $nodeId, ':kid' => $kwId]);
                }
            }

            $importedCount++;

            // Commit in batches
            if ($importedCount % $batchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                $rate = round($importedCount / (microtime(true) - $startTime), 0);
                cli_log('INFO', "  {$importedCount}/{$expectedCount} imported ({$rate} nodes/sec)");
            }
        } catch (Throwable $e) {
            $errorCount++;
            if ($errorCount <= 5) cli_log('ERROR', ($item['slug'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    $pdo->commit();
    cli_log('OK', "Batch import complete: {$importedCount}/{$expectedCount}");

// ── Two-phase import (with media) ────────────────────────────────────────────
} else {
    // Phase 1: Fast batch insert of all nodes (same as --no-media path)
    cli_log('OK', "Phase 1/2: Creating {$expectedCount} nodes...");
    $pdo = getDB();

    $insertStmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, animation, constellation_id, node_type, audio_autoplay, video_autoplay, mucua_name, media_type, source_created_at, import_slug)
        VALUES (:name, :description, :url, :animation, :constellation_id, 'object', 1, 1, :mucua_name, :media_type, :source_created_at, :import_slug)
    ");
    $kwInsertStmt = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :cid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $kwLookupStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword AND constellation_id = :cid LIMIT 1");
    $nkInsertStmt = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid) ON DUPLICATE KEY UPDATE node_id=node_id");

    $batchSize = 500;
    $pdo->beginTransaction();
    $nodeIdMap = []; // itemIndex → nodeId

    foreach ($galaxiaItems as $idx => $item) {
        try {
            $nodeName = $item['title'] ?? ($item['slug'] ?? 'unknown');
            $nodeDesc = $item['description'] ?? '';
            $mediaType = $item['type'] ?? 'arquivo';
            $tags = $item['tags'] ?? [];
            if (!is_array($tags)) $tags = [];

            $itemMucuaSlugForUrl = $mucuaSlugMap[$item['mucua_smid'] ?? ''] ?? '';
            $nodeUrl = $downloadBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlugForUrl . '/' . ($item['slug'] ?? '');

            $animation = json_encode([
                'radius' => 5 + rand(0, 3), 'theta' => rand(0, 628) / 100,
                'phi' => rand(0, 314) / 100, 'speed' => 0.002 + (rand(0, 4) / 1000),
                'phase' => rand(0, 628) / 100,
            ], JSON_THROW_ON_ERROR);

            $itemMucuaSmid = $item['mucua_smid'] ?? null;
            $resolvedMucuaName = ($itemMucuaSmid !== null && isset($mucuaNameMap[$itemMucuaSmid])) ? $mucuaNameMap[$itemMucuaSmid] : null;
            $itemMediaType = ($item['_source_type'] ?? '') === 'blog' ? 'blog' : $mediaType;

            $insertStmt->execute([
                ':name' => $nodeName, ':description' => $nodeDesc ?: null, ':url' => $nodeUrl,
                ':animation' => $animation, ':constellation_id' => $constellationId,
                ':mucua_name' => $resolvedMucuaName, ':media_type' => $itemMediaType,
                ':source_created_at' => $item['created'] ?? null,
                ':import_slug' => $item['slug'] ?? null,
            ]);
            $nodeId = (int)$pdo->lastInsertId();
            $nodeIdMap[$idx] = $nodeId;

            foreach ($tags as $tag) {
                $tag = trim($tag);
                if ($tag === '') continue;
                $kwInsertStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
                $kwId = (int)$pdo->lastInsertId();
                if ($kwId === 0) {
                    $kwLookupStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
                    $kwId = (int)($kwLookupStmt->fetchColumn() ?: 0);
                }
                if ($kwId > 0) $nkInsertStmt->execute([':nid' => $nodeId, ':kid' => $kwId]);
            }

            $importedCount++;
            if ($importedCount % $batchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                $rate = round($importedCount / (microtime(true) - $startTime), 0);
                cli_log('INFO', "  {$importedCount}/{$expectedCount} nodes ({$rate}/sec)");
            }
        } catch (Throwable $e) {
            $errorCount++;
            if ($errorCount <= 5) cli_log('ERROR', ($item['slug'] ?? '?') . ': ' . $e->getMessage());
        }
    }
    $pdo->commit();
    $phase1Time = round(microtime(true) - $startTime, 1);
    cli_log('OK', "Phase 1 complete: {$importedCount}/{$expectedCount} nodes in {$phase1Time}s");

    // Phase 2: Download media files
    $mediaStart = microtime(true);
    $mediaOk = 0;
    $mediaFail = 0;
    $mediaSkip = 0;
    cli_log('OK', "Phase 2/2: Downloading media for {$importedCount} nodes...");

    foreach ($galaxiaItems as $idx => $item) {
        $nodeId = $nodeIdMap[$idx] ?? null;
        if ($nodeId === null) { $mediaSkip++; continue; }

        $itemSlug = $item['slug'] ?? 'unknown';
        $nodeName = $item['title'] ?? $itemSlug;
        $nodeDesc = $item['description'] ?? '';
        $mediaType = $item['type'] ?? 'arquivo';
        $counter = ($idx + 1) . "/{$expectedCount}";

        // Build frontend URL using slug aliases
        $itemMucuaSlugForUrl = $mucuaSlugMap[$item['mucua_smid'] ?? ''] ?? '';
        $nodeUrl = $downloadBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlugForUrl . '/' . $itemSlug;

        $animation = json_encode([
            'radius' => 5 + rand(0, 3), 'theta' => rand(0, 628) / 100,
            'phi' => rand(0, 314) / 100, 'speed' => 0.002 + (rand(0, 4) / 1000),
            'phase' => rand(0, 628) / 100,
        ], JSON_THROW_ON_ERROR);

        $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
        $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
        if (!is_dir($nodeFullDir)) @mkdir($nodeFullDir, 0775, true);

        $imageUrl = null; $iconUrl = null; $audioUrl = null; $videoUrl = null;
        $needsUpdate = false;

        // Build download URL with per-item mucua slug and content hash
        $dlMucuaSlug = $mucuaSlugMap[$item['mucua_smid'] ?? ''] ?? $mucuaSlug;
        $dlBase = $downloadBase . '/' . $galaxiaSlug . '/' . $dlMucuaSlug;
        $contentHash = '';
        $content = $item['content'] ?? [];
        if (is_array($content) && !empty($content) && isset($content[0]['hash_sum'])) {
            $contentHash = $content[0]['hash_sum'];
        }
        $hashSuffix = $contentHash !== '' ? '/' . $contentHash : '';

        cli_log('DL', "({$counter}) {$nodeName} [{$mediaType}]", true);

        if ($mediaType === 'imagem') {
            $localPath = mocambos_download_file("{$dlBase}/acervo/download/{$itemSlug}{$hashSuffix}", $nodeFullDir, 'image', $writeLog);
            if ($localPath !== null) {
                $relPath = $nodeRelDir . '/' . basename($localPath);
                $imageUrl = $relPath; $iconUrl = $relPath; $needsUpdate = true;
                cli_log('OK', "  image saved (" . round(filesize($localPath) / 1024, 1) . " KB)", true);
            } else {
                cli_log('WARN', "  image download failed", true);
            }
        } elseif ($mediaType === 'video') {
            $localPath = mocambos_download_file("{$dlBase}/acervo/download/{$itemSlug}{$hashSuffix}", $nodeFullDir, 'video', $writeLog);
            if ($localPath !== null) {
                $videoUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true;
                cli_log('OK', "  video saved (" . round(filesize($localPath) / 1024, 1) . " KB)", true);
            } else {
                cli_log('WARN', "  video download failed", true);
            }
        } elseif ($mediaType === 'audio') {
            $localPath = mocambos_download_file("{$dlBase}/acervo/download/{$itemSlug}{$hashSuffix}", $nodeFullDir, 'audio', $writeLog);
            if ($localPath !== null) {
                $audioUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true;
                cli_log('OK', "  audio saved (" . round(filesize($localPath) / 1024, 1) . " KB)", true);
            } else {
                cli_log('WARN', "  audio download failed", true);
            }
        }

        // Thumbnail as icon fallback
        if ($iconUrl === null) {
            $thumbPath = mocambos_download_file("{$dlBase}/acervo/thumbnail/{$itemSlug}", $nodeFullDir, 'icon', $writeLog);
            if ($thumbPath !== null) {
                $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true;
                cli_log('OK', "  thumbnail saved", true);
            }
        }

        if ($needsUpdate) {
            db_update_node($nodeId, $nodeName, $nodeDesc ?: null, $nodeUrl, $animation, $constellationId, 'object', null, $imageUrl, null, $audioUrl, true, false, $videoUrl, true, false, false, $iconUrl);
            $mediaOk++;
        } else {
            $mediaFail++;
        }

        // Progress summary every 100 items (always shown, even in quiet)
        if (($idx + 1) % 100 === 0) {
            $elapsed = round(microtime(true) - $mediaStart, 0);
            $rate = $elapsed > 0 ? round(($idx + 1) / $elapsed, 1) : 0;
            cli_log('INFO', "  Media progress: {$mediaOk} ok, {$mediaFail} failed, " . ($idx + 1) . "/{$expectedCount} processed ({$rate}/sec, {$elapsed}s)");
        }
    }

    $phase2Time = round(microtime(true) - $mediaStart, 1);
    cli_log('OK', "Phase 2 complete: {$mediaOk} media saved, {$mediaFail} failed in {$phase2Time}s");
}

// ── Summary ──────────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $startTime, 1);
echo "\n";
cli_log('INFO', str_repeat('─', 50));
cli_log('INFO', "Constellation: {$galaxiaName} (ID {$constellationId})");
cli_log('INFO', "Imported: {$importedCount}/{$expectedCount} items in {$elapsed}s");
if ($errorCount > 0) cli_log('WARN', "Errors: {$errorCount}");
if ($noMedia) cli_log('INFO', "Media downloads skipped (--no-media)");
if ($isNew) cli_log('INFO', "New constellation created");
else cli_log('INFO', "Existing constellation re-imported");
echo "\n";
