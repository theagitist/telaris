<?php
declare(strict_types=1);

/**
 * Bridge: Mocambos / Baobáxia.
 *
 * Pulls galaxias from a Mocambos / Baobáxia instance into Telaris constellations.
 * Entry points are mocambos_handle_request() for HTTP (called by api/bridge.php)
 * and mocambos_run_cli() for the shell (called by admin/cli/import_bridge.php).
 * mocambos_cli_args_from_source() is the optional refresh hook called by
 * admin/cli/refresh_constellation.php.
 *
 * The actual per-galaxia import is in _mocambos_import_galaxia(); both entry
 * points produce a streamMsg / logger callback pair and delegate to it.
 */

require_once __DIR__ . '/download.php';
require_once __DIR__ . '/sync.php';

// ── HTTP entry point ─────────────────────────────────────────────────────────

function mocambos_handle_request(): void {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET' && $action === 'validate') {
        requireWriteAccess();
        _mocambos_http_validate();
    } elseif ($method === 'GET' && $action === 'galaxias') {
        requireWriteAccess();
        _mocambos_http_list_galaxias();
    } elseif ($method === 'POST' && $action === 'import') {
        requireWriteAccess();
        set_time_limit(0);
        _mocambos_http_import();
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed or unknown action'], JSON_THROW_ON_ERROR);
    }
}

// ── Optional presentation hooks ──────────────────────────────────────────────

/**
 * Visitor-side: the icon URL applied to auto-generated cluster pseudo-nodes
 * in constellations this bridge imported. Called from api/nodes.php via
 * bridges_cluster_icon_url_for().
 */
function mocambos_cluster_icon_url(): string {
    return 'img/bridges/mocambos/cluster.svg';
}

// ── Refresh hook (optional handler interface) ────────────────────────────────

/**
 * Build the CLI argv tail that re-imports a constellation whose
 * import_source JSON was stamped by this bridge. Returns null if the
 * stored source cannot be refreshed (missing fields, etc.). Used by
 * admin/cli/refresh_constellation.php to route refresh from a stored
 * source back through the bridge-specific import path.
 */
function mocambos_cli_args_from_source(array $source): ?array {
    $apiBase = $source['api_base'] ?? '';
    $galaxiaSlug = $source['galaxia_slug'] ?? '';
    if ($apiBase === '' || $galaxiaSlug === '') {
        return null;
    }
    return [
        '--api-base=' . $apiBase,
        '--galaxia=' . $galaxiaSlug,
    ];
}

// ── CLI entry point ──────────────────────────────────────────────────────────

/**
 * CLI entry point. Parses Mocambos-specific flags via getopt() on the global
 * $argv (PHP getopt ignores the bridge-name positional automatically) and
 * runs the import. Returns the process exit code.
 *
 * Recognised flags: --api-base=URL, --galaxia=SLUG, --list, --no-media,
 * --limit=N, --quiet, --full. With no flags, drops into interactive mode.
 */
function mocambos_run_cli(): int {
    $opts = getopt('', ['api-base:', 'galaxia:', 'list', 'no-media', 'limit:', 'quiet', 'full']);
    $interactive = empty($opts) || (count($opts) === 1 && isset($opts['quiet']));

    $quiet = isset($opts['quiet']);
    $fullRefresh = isset($opts['full']);
    $apiBase = trim($opts['api-base'] ?? '');
    $listMode = isset($opts['list']);
    $galaxiaSlug = trim($opts['galaxia'] ?? '');
    $noMedia = isset($opts['no-media']);
    $limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;

    $log = _mocambos_cli_logger($quiet);

    if ($apiBase === '') {
        if ($interactive) {
            echo "\n\033[1mMocambos Import\033[0m\n\n";
            $apiBase = _mocambos_cli_prompt('Mocambos API base URL', 'https://oya.mocambos.net/api/v2');
        } else {
            fwrite(STDERR, "Error: --api-base is required.\n");
            fwrite(STDERR, "Usage: php admin/cli/import_bridge.php mocambos --api-base=URL --galaxia=SLUG\n");
            return 1;
        }
    }
    $apiBase = rtrim($apiBase, '/');

    $log('INFO', "Connecting to {$apiBase}...");

    $galaxias = _mocambos_fetch_json($apiBase . '/galaxia');
    if (!is_array($galaxias)) {
        $log('ERROR', "Failed to fetch galaxia list from {$apiBase}/galaxia");
        return 1;
    }

    $mucuaMaps = _mocambos_fetch_mucua_maps($apiBase);
    $log('INFO', 'Found ' . count($galaxias) . ' galaxia(s), ' . count($mucuaMaps['name']) . ' mucua(s)');

    $galaxiaInfoMap = [];
    $galaxiaIndexed = [];
    foreach ($galaxias as $i => $g) {
        $slug = $g['slug'] ?? '';
        $galaxiaInfoMap[$slug] = $g;
        $galaxiaIndexed[$i + 1] = $g;
    }

    // List-only mode.
    if ($listMode) {
        echo "\nAvailable galaxias at {$apiBase}:\n\n";
        printf("  %-40s %-20s %s\n", 'SLUG', 'NAME', 'SMID');
        printf("  %-40s %-20s %s\n", str_repeat('-', 40), str_repeat('-', 20), str_repeat('-', 36));
        foreach ($galaxias as $g) {
            printf("  %-40s %-20s %s\n", $g['slug'] ?? '?', $g['name'] ?? '?', $g['smid'] ?? '?');
        }
        echo "\n";
        return 0;
    }

    // Interactive: choose galaxia.
    if ($galaxiaSlug === '' && $interactive) {
        db_ensure_constellations_import_source_column();
        $constellations = db_get_constellations();
        $importedSlugs = [];
        foreach ($constellations as $c) {
            if ($c['import_source'] !== null) {
                $src = json_decode($c['import_source'], true);
                if (is_array($src) && isset($src['galaxia_slug'])) {
                    $importedSlugs[$src['galaxia_slug']] = true;
                }
            }
        }
        echo "\nAvailable galaxias:\n\n";
        foreach ($galaxiaIndexed as $num => $g) {
            $name = $g['name'] ?? $g['slug'] ?? '?';
            $slug = $g['slug'] ?? '?';
            $imported = isset($importedSlugs[$slug]) ? " \033[33m(already imported)\033[0m" : '';
            printf("  \033[1m%d)\033[0m %s — %s%s\n", $num, $name, $slug, $imported);
        }
        echo "\n";
        $choice = _mocambos_cli_prompt('Select galaxia number (or type slug)');
        if (ctype_digit($choice) && isset($galaxiaIndexed[(int)$choice])) {
            $galaxiaSlug = $galaxiaIndexed[(int)$choice]['slug'] ?? '';
        } else {
            $galaxiaSlug = $choice;
        }
        if ($galaxiaSlug === '') {
            echo "No galaxia selected.\n";
            return 0;
        }
    }

    if ($galaxiaSlug === '') {
        fwrite(STDERR, "Error: --galaxia=SLUG is required.\n");
        return 1;
    }

    // Locate the galaxia (try partial match).
    $galInfo = $galaxiaInfoMap[$galaxiaSlug] ?? null;
    if ($galInfo === null) {
        foreach ($galaxiaInfoMap as $slug => $info) {
            if (str_contains($slug, $galaxiaSlug)) {
                $galInfo = $info;
                $galaxiaSlug = $slug;
                $log('INFO', "Matched galaxia slug: {$slug}");
                break;
            }
        }
    }
    if ($galInfo === null) {
        $log('ERROR', "Galaxia '{$galaxiaSlug}' not found. Use --list to see available galaxias.");
        return 1;
    }

    if ($interactive) {
        echo "\n";
        $noMedia = !_mocambos_cli_confirm('Download media files? (slower but includes images/audio/video)');
        $limitInput = _mocambos_cli_prompt('Limit number of items? (enter number, or press Enter for all)', '0');
        $limit = (int)$limitInput;
    }

    $galaxiaSmid = $galInfo['smid'] ?? '';
    $galaxiaName = $galInfo['name'] ?? $galaxiaSlug;
    $defaultMucua = $galInfo['default_mucua'] ?? '';
    $mucuaSlug = '';
    if ($defaultMucua !== '' && isset($mucuaMaps['slug'][$defaultMucua])) {
        $mucuaSlug = $mucuaMaps['slug'][$defaultMucua];
    }

    if ($interactive) {
        echo "\n";
        echo "  Galaxia:  {$galaxiaName} ({$galaxiaSlug})\n";
        echo "  API:      {$apiBase}\n";
        echo "  Media:    " . ($noMedia ? 'skip' : 'download') . "\n";
        echo "  Limit:    " . ($limit > 0 ? $limit : 'all') . "\n";
        echo "\n";
        if (!_mocambos_cli_confirm('Proceed with import?')) {
            echo "Aborted.\n";
            return 0;
        }
        echo "\n";
    }

    $log('INFO', "Galaxia: {$galaxiaName} (slug={$galaxiaSlug}, smid={$galaxiaSmid})");

    // Fetch all items, filter by galaxia.
    $allItems = _mocambos_fetch_all_items($apiBase, function(string $msg) use ($log) { $log('INFO', $msg); });
    $galaxiaItems = array_values(array_filter($allItems, fn($item) => ($item['galaxia_smid'] ?? '') === $galaxiaSmid));
    $log('INFO', 'Total items for this galaxia: ' . count($galaxiaItems));

    if ($limit > 0 && count($galaxiaItems) > $limit) {
        $galaxiaItems = array_slice($galaxiaItems, 0, $limit);
        $log('WARN', "Limited to {$limit} items (--limit)");
    }

    // streamMsg for CLI mode just routes through the colored logger.
    $streamMsg = function(string $type, string $message, array $extra = []) use ($log) {
        $level = match($type) {
            'error' => 'ERROR',
            'warning' => 'WARN',
            'success' => 'OK',
            'download' => 'DL',
            default => 'INFO',
        };
        $log($level, $message);
    };

    $logger = function(string $level, string $msg) {
        // No-op for the shared download helper. CLI logs are handled by streamMsg.
    };

    $startTime = microtime(true);

    $result = _mocambos_import_galaxia([
        'api_base' => $apiBase,
        'galaxia_slug' => $galaxiaSlug,
        'galaxia_smid' => $galaxiaSmid,
        'galaxia_name' => $galaxiaName,
        'galaxia_desc' => $galInfo['description'] ?? '',
        'mucua_slug' => $mucuaSlug,
        'galaxia_items' => $galaxiaItems,
        'mucua_name_map' => $mucuaMaps['name'],
        'mucua_slug_map' => $mucuaMaps['slug'],
        'mucua_uri_map' => $mucuaMaps['uri'],
        'full_refresh' => $fullRefresh,
        'skip_media' => $noMedia,
    ], $streamMsg, $logger);

    $elapsed = round(microtime(true) - $startTime, 1);
    echo "\n";
    $log('INFO', str_repeat('─', 50));
    $log('INFO', "Constellation: {$galaxiaName} (ID {$result['constellation_id']})");
    $log('INFO', "Imported: {$result['imported_count']}/{$result['expected_count']} items in {$elapsed}s");
    if (count($result['errors']) > 0) {
        $log('WARN', 'Errors: ' . count($result['errors']));
    }
    if ($noMedia) {
        $log('INFO', "Media downloads skipped (--no-media)");
    }
    $log('INFO', $result['is_new'] ? 'New constellation created' : 'Existing constellation re-imported');
    echo "\n";
    return 0;
}

// ── HTTP action handlers ─────────────────────────────────────────────────────

function _mocambos_http_validate(): void {
    $apiBase = trim($_GET['api_base'] ?? '');
    if ($apiBase === '' || !filter_var($apiBase, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['valid' => false, 'error' => 'Invalid URL format. Expected a full URL like https://hostname/api/v2'], JSON_THROW_ON_ERROR);
        return;
    }
    if (!preg_match('#^https?://#', $apiBase)) {
        http_response_code(400);
        echo json_encode(['valid' => false, 'error' => 'URL must start with http:// or https://'], JSON_THROW_ON_ERROR);
        return;
    }

    $checks = [];
    $allOk = true;

    $probe = function(string $url) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/[\d.]+ (\d+)#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
        }
        if ($body === false) {
            return [false, 0, null, 'Connection failed — could not reach the server'];
        }
        return [$status >= 200 && $status < 300, $status, $body, null];
    };

    // /galaxia
    [$ok, $status, $body, $err] = $probe($apiBase . '/galaxia');
    if (!$ok) {
        $checks[] = ['endpoint' => '/galaxia', 'status' => 'fail', 'http_status' => $status,
            'detail' => $err ?? "HTTP {$status} — expected 200. This endpoint must return a JSON array of galaxia objects."];
        $allOk = false;
    } else {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $checks[] = ['endpoint' => '/galaxia', 'status' => 'fail', 'http_status' => $status,
                'detail' => 'Response is not a valid JSON array. Received: ' . mb_substr((string)$body, 0, 200)];
            $allOk = false;
        } elseif (count($data) === 0) {
            $checks[] = ['endpoint' => '/galaxia', 'status' => 'warn', 'http_status' => $status,
                'detail' => 'Returned an empty array — no galaxias available to import.'];
        } else {
            $first = $data[0];
            $missing = [];
            foreach (['name', 'slug', 'default_mucua'] as $field) {
                if (!isset($first[$field]) || $first[$field] === '') $missing[] = $field;
            }
            if ($missing) {
                $checks[] = ['endpoint' => '/galaxia', 'status' => 'fail', 'http_status' => $status,
                    'detail' => 'Galaxia objects are missing required fields: ' . implode(', ', $missing) . '. Each galaxia must have: name, slug, default_mucua.'];
                $allOk = false;
            } else {
                $checks[] = ['endpoint' => '/galaxia', 'status' => 'ok', 'http_status' => $status,
                    'detail' => 'Found ' . count($data) . ' galaxia(s). Structure looks correct.'];
            }
        }
    }

    // /mucua
    [$ok, $status, $body, $err] = $probe($apiBase . '/mucua');
    if (!$ok) {
        $checks[] = ['endpoint' => '/mucua', 'status' => 'fail', 'http_status' => $status,
            'detail' => $err ?? "HTTP {$status} — expected 200. This endpoint must return a JSON array of mucua objects."];
        $allOk = false;
    } else {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $checks[] = ['endpoint' => '/mucua', 'status' => 'fail', 'http_status' => $status,
                'detail' => 'Response is not a valid JSON array. Received: ' . mb_substr((string)$body, 0, 200)];
            $allOk = false;
        } elseif (count($data) === 0) {
            $checks[] = ['endpoint' => '/mucua', 'status' => 'warn', 'http_status' => $status,
                'detail' => 'Returned an empty array — no mucuas found. Media downloads may not work.'];
        } else {
            $first = $data[0];
            $missing = [];
            foreach (['smid', 'slug'] as $field) {
                if (!isset($first[$field]) || $first[$field] === '') $missing[] = $field;
            }
            if ($missing) {
                $checks[] = ['endpoint' => '/mucua', 'status' => 'fail', 'http_status' => $status,
                    'detail' => 'Mucua objects are missing required fields: ' . implode(', ', $missing) . '. Each mucua must have: smid, slug.'];
                $allOk = false;
            } else {
                $checks[] = ['endpoint' => '/mucua', 'status' => 'ok', 'http_status' => $status,
                    'detail' => 'Found ' . count($data) . ' mucua(s). Structure looks correct.'];
            }
        }
    }

    // /acervo/find
    [$ok, $status, $body, $err] = $probe($apiBase . '/acervo/find?pag_tamanho=1');
    if (!$ok) {
        $checks[] = ['endpoint' => '/acervo/find', 'status' => 'fail', 'http_status' => $status,
            'detail' => $err ?? "HTTP {$status} — expected 200. This endpoint must return a paginated JSON object with an 'items' array."];
        $allOk = false;
    } else {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items'])) {
            $checks[] = ['endpoint' => '/acervo/find', 'status' => 'fail', 'http_status' => $status,
                'detail' => 'Response missing "items" key. Expected {item_count, page_count, items: [...]}. Received: ' . mb_substr((string)$body, 0, 200)];
            $allOk = false;
        } else {
            $itemCount = $data['item_count'] ?? count($data['items']);
            $checks[] = ['endpoint' => '/acervo/find', 'status' => 'ok', 'http_status' => $status,
                'detail' => "Returned {$itemCount} media item(s) total. Structure looks correct."];
        }
    }

    // /blog/find (optional)
    [$ok, $status, $body, $err] = $probe($apiBase . '/blog/find?pag_tamanho=1');
    if (!$ok) {
        $checks[] = ['endpoint' => '/blog/find', 'status' => 'warn', 'http_status' => $status,
            'detail' => $err ?? "HTTP {$status} — expected 200. Blog articles will not be imported."];
    } else {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items'])) {
            $checks[] = ['endpoint' => '/blog/find', 'status' => 'warn', 'http_status' => $status,
                'detail' => 'Response missing "items" key. Blog articles will not be imported.'];
        } else {
            $itemCount = $data['item_count'] ?? count($data['items']);
            $checks[] = ['endpoint' => '/blog/find', 'status' => 'ok', 'http_status' => $status,
                'detail' => "Returned {$itemCount} blog article(s) total. Structure looks correct."];
        }
    }

    echo json_encode(['valid' => $allOk, 'checks' => $checks], JSON_THROW_ON_ERROR);
}

function _mocambos_http_list_galaxias(): void {
    $apiBase = trim($_GET['api_base'] ?? '');
    if ($apiBase === '' || !filter_var($apiBase, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid api_base URL'], JSON_THROW_ON_ERROR);
        return;
    }

    $galaxias = _mocambos_fetch_json($apiBase . '/galaxia');
    if (!is_array($galaxias)) {
        http_response_code(502);
        echo json_encode(['error' => 'Failed to reach Mocambos API at ' . $apiBase], JSON_THROW_ON_ERROR);
        return;
    }

    $mucuaMaps = _mocambos_fetch_mucua_maps($apiBase);

    db_ensure_constellations_import_source_column();
    $constellations = db_get_constellations();

    $result = [];
    foreach ($galaxias as $g) {
        $slug = $g['slug'] ?? '';
        $name = $g['name'] ?? $slug;
        $defaultMucuaUuid = $g['default_mucua'] ?? '';
        $mucuaSlug = $mucuaMaps['slug'][$defaultMucuaUuid] ?? '';

        $imported = false;
        $constellationId = null;
        foreach ($constellations as $c) {
            if ($c['import_source'] !== null && $c['import_source'] !== '') {
                $source = json_decode($c['import_source'], true);
                if (is_array($source) && ($source['galaxia_slug'] ?? '') === $slug) {
                    $imported = true;
                    $constellationId = (int)$c['id'];
                    break;
                }
            }
        }

        $result[] = [
            'name' => $name,
            'slug' => $slug,
            'smid' => $g['smid'] ?? '',
            'mucua_slug' => $mucuaSlug,
            'imported' => $imported,
            'constellation_id' => $constellationId,
        ];
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
}

function _mocambos_http_import(): void {
    $input = stream_get_contents(fopen('php://input', 'r'), 10485760);
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body'], JSON_THROW_ON_ERROR);
        return;
    }

    $apiBase = trim($data['api_base'] ?? 'https://timbuktu.mocambos.net/api/v2');
    $fullRefresh = !empty($data['full_refresh']);
    $galaxias = $data['galaxias'] ?? [];
    if (!is_array($galaxias) || empty($galaxias)) {
        http_response_code(400);
        echo json_encode(['error' => 'No galaxias specified'], JSON_THROW_ON_ERROR);
        return;
    }

    // Switch to streaming newline-delimited JSON for real-time progress.
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_flush();

    $logDir = defined('LOG_DIR') ? LOG_DIR : (__DIR__ . '/../../logs');
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/mocambos-import-' . date('Y-m-d_H-i-s') . '.log';
    $logFp = fopen($logFile, 'w');

    $writeLog = function(string $level, string $message) use ($logFp) {
        if ($logFp) {
            $ts = date('Y-m-d H:i:s');
            fwrite($logFp, "[{$ts}] [{$level}] {$message}\n");
            fflush($logFp);
        }
    };

    $writeLog('INFO', 'Import started — api_base=' . $apiBase . ', galaxias=' . json_encode(array_column($galaxias, 'galaxia_slug')));

    $streamMsg = function(string $type, string $message, array $extra = []) use ($writeLog) {
        $line = json_encode(array_merge(['type' => $type, 'message' => $message], $extra), JSON_THROW_ON_ERROR);
        echo $line . "\n";
        flush();
        $level = match($type) { 'error' => 'ERROR', 'warning' => 'WARN', default => 'INFO' };
        $writeLog($level, $message);
    };

    db_ensure_constellations_import_source_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_import_slug_column();

    // One mucua fetch shared across all selected galaxias.
    $mucuaMaps = _mocambos_fetch_mucua_maps($apiBase);
    $streamMsg('info', 'Resolved ' . count($mucuaMaps['name']) . ' mucua names');

    // Fetch all items once across all selected galaxias.
    $streamMsg('info', "Fetching media items from Mocambos API...");
    $allItems = _mocambos_fetch_all_items($apiBase, function(string $msg) use ($streamMsg) {
        $streamMsg('info', $msg);
    });
    $streamMsg('info', "Total items fetched: " . count($allItems));

    // Galaxia info map for names that may not be in selection payload.
    $galaxiaInfoMap = [];
    $galInfo = _mocambos_fetch_json($apiBase . '/galaxia');
    if (is_array($galInfo)) {
        foreach ($galInfo as $gl) {
            $galaxiaInfoMap[$gl['slug'] ?? ''] = $gl;
        }
    }

    $results = [];

    foreach ($galaxias as $gal) {
        $galaxiaSlug = $gal['galaxia_slug'] ?? '';
        $mucuaSlug = $gal['mucua_slug'] ?? '';
        $galaxiaSmid = $gal['galaxia_smid'] ?? '';
        if ($galaxiaSlug === '') continue;

        if ($galaxiaSmid === '' && isset($galaxiaInfoMap[$galaxiaSlug])) {
            $galaxiaSmid = $galaxiaInfoMap[$galaxiaSlug]['smid'] ?? '';
            $writeLog('INFO', "Resolved smid for {$galaxiaSlug} from galaxia list: {$galaxiaSmid}");
        }

        $galaxiaItems = array_values(array_filter($allItems, fn($item) => ($item['galaxia_smid'] ?? '') === $galaxiaSmid));
        $writeLog('INFO', "--- Galaxia: {$galaxiaSlug} (smid={$galaxiaSmid}), mucua: {$mucuaSlug}, items: " . count($galaxiaItems) . " ---");
        $streamMsg('info', "Processing galaxia: {$galaxiaSlug} (" . count($galaxiaItems) . " items)");

        $galInfo = $galaxiaInfoMap[$galaxiaSlug] ?? [];

        $result = _mocambos_import_galaxia([
            'api_base' => $apiBase,
            'galaxia_slug' => $galaxiaSlug,
            'galaxia_smid' => $galaxiaSmid,
            'galaxia_name' => $galInfo['name'] ?? $galaxiaSlug,
            'galaxia_desc' => $galInfo['description'] ?? '',
            'mucua_slug' => $mucuaSlug,
            'galaxia_items' => $galaxiaItems,
            'mucua_name_map' => $mucuaMaps['name'],
            'mucua_slug_map' => $mucuaMaps['slug'],
            'mucua_uri_map' => $mucuaMaps['uri'],
            'full_refresh' => $fullRefresh,
            'skip_media' => false,
        ], $streamMsg, $writeLog);

        $results[] = [
            'galaxia_slug' => $galaxiaSlug,
            'constellation_id' => $result['constellation_id'],
            'is_new' => $result['is_new'],
            'expected_count' => $result['expected_count'],
            'imported_count' => $result['imported_count'],
            'verified_count' => $result['verified_count'],
            'errors' => $result['errors'],
        ];
    }

    $writeLog('INFO', 'Import complete — ' . count($results) . ' galaxia(s) processed');
    if ($logFp) {
        $writeLog('INFO', 'Log file: ' . $logFile);
        fclose($logFp);
    }
    $streamMsg('done', 'Import complete', ['success' => true, 'results' => $results]);
}

// ── Shared import core ───────────────────────────────────────────────────────

/**
 * Import a single galaxia. Used by both the HTTP and CLI entry points.
 *
 * $params keys: api_base, galaxia_slug, galaxia_smid, galaxia_name,
 *               galaxia_desc, mucua_slug, galaxia_items, source_facet_map,
 *               mucua_slug_map, mucua_uri_map, full_refresh (bool),
 *               skip_media (bool).
 *
 * $streamMsg(type, message [, extra]) is the user-facing progress callback.
 * $logger(level, message) writes to whatever persistent log the caller keeps;
 * it is also the callback used by mocambos_download_file().
 */
function _mocambos_import_galaxia(array $params, Closure $streamMsg, Closure $logger): array {
    $apiBase       = $params['api_base'];
    $galaxiaSlug   = $params['galaxia_slug'];
    $galaxiaSmid   = $params['galaxia_smid'];
    $galaxiaName   = $params['galaxia_name'];
    $galaxiaDesc   = $params['galaxia_desc'];
    $mucuaSlug     = $params['mucua_slug'];
    $galaxiaItems  = $params['galaxia_items'];
    $mucuaNameMap  = $params['mucua_name_map'];
    $mucuaSlugMap  = $params['mucua_slug_map'];
    $mucuaUriMap   = $params['mucua_uri_map'];
    $fullRefresh   = (bool)$params['full_refresh'];
    $skipMedia     = (bool)($params['skip_media'] ?? false);

    $downloadBase = preg_replace('#/api/v2/?$#', '', $apiBase);
    $errors = [];
    $isNew = true;
    $constellationId = null;
    $isIncremental = false;

    db_ensure_constellations_import_source_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_import_slug_column();

    // Locate existing constellation, if any.
    foreach (db_get_constellations() as $c) {
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
        $logger('INFO', "Full refresh — clearing all nodes for re-import");
        $streamMsg('info', "Full refresh — clearing existing nodes...");
        db_clear_constellation_nodes($constellationId);
    } elseif ($constellationId !== null) {
        $logger('INFO', "Existing constellation found (ID {$constellationId}), checking for incremental sync");
        $streamMsg('info', "Re-importing — computing diff...");

        $backfilled = db_backfill_import_slugs($constellationId);
        if ($backfilled > 0) {
            $streamMsg('info', "Backfilled {$backfilled} import slugs");
        }

        $existingBySlug = db_get_nodes_by_import_slug($constellationId);
        $diff = mocambos_compute_diff($existingBySlug, $galaxiaItems, $mucuaNameMap);
        $streamMsg('info', "Diff: " . count($diff['added']) . " new, " . count($diff['modified']) . " modified, " . count($diff['deleted']) . " deleted, " . $diff['unchanged'] . " unchanged");

        if (!empty($diff['deleted'])) {
            $streamMsg('info', "Deleting " . count($diff['deleted']) . " removed items...");
            mocambos_apply_deletions($diff['deleted'], $constellationId, getDB());
        }
        if (!empty($diff['modified'])) {
            $streamMsg('info', "Updating " . count($diff['modified']) . " modified items...");
            mocambos_apply_modifications($diff['modified'], $constellationId, getDB());
        }
        $isIncremental = true;
        $galaxiaItems = array_map(fn($a) => $a['item'], $diff['added']);
    } else {
        $baseSlug = db_slugify($galaxiaName);
        $slug = $baseSlug;
        $suffix = 2;
        while (true) {
            try {
                $constellationId = db_create_constellation($galaxiaName, $galaxiaDesc ?: '', $slug, 'abstract');
                $streamMsg('success', "Created constellation: {$galaxiaName} (ID {$constellationId})");
                break;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry') && $suffix <= 20) {
                    $slug = $baseSlug . '-' . $suffix++;
                } else {
                    throw $e;
                }
            }
        }
    }

    // Stamp import_source immediately so it shows as imported even if the run is interrupted.
    $importSource = json_encode([
        'source' => 'mocambos',
        'api_base' => $apiBase,
        'galaxia_slug' => $galaxiaSlug,
        'mucua_slug' => $mucuaSlug,
    ], JSON_THROW_ON_ERROR);
    db_set_constellation_import_source($constellationId, $importSource);

    $expectedCount = count($galaxiaItems);
    $importedCount = 0;

    // ── Phase 1: batch-insert new nodes ────────────────────────────────────
    $streamMsg('info', $isIncremental ? "Adding {$expectedCount} new nodes..." : "Phase 1: Creating {$expectedCount} nodes...");
    $pdo = getDB();
    $insertStmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, animation, constellation_id, node_type, audio_autoplay, video_autoplay, source_facet, media_type, source_created_at, import_slug)
        VALUES (:name, :description, :url, :animation, :constellation_id, 'object', 1, 1, :source_facet, :media_type, :source_created_at, :import_slug)
    ");
    $kwInsertStmt = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :cid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $kwLookupStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword AND constellation_id = :cid LIMIT 1");
    $nkInsertStmt = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid) ON DUPLICATE KEY UPDATE node_id=node_id");

    $batchSize = 500;
    $pdo->beginTransaction();
    $nodeIdMap = [];

    foreach ($galaxiaItems as $itemIndex => $item) {
        try {
            $nodeName = $item['title'] ?? ($item['slug'] ?? 'unknown');
            $nodeDesc = $item['description'] ?? '';
            $mediaType = $item['type'] ?? 'arquivo';
            $tags = $item['tags'] ?? [];
            if (!is_array($tags)) $tags = [];

            $itemMucuaSmidVal = $item['mucua_smid'] ?? '';
            $itemMucuaSlugForUrl = $mucuaSlugMap[$itemMucuaSmidVal] ?? '';
            $itemFrontendBase = $mucuaUriMap[$itemMucuaSmidVal] ?? $downloadBase;
            $nodeUrl = $itemFrontendBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlugForUrl . '/' . ($item['slug'] ?? '');

            $animation = _mocambos_random_animation();

            $itemMucuaSmid = $item['mucua_smid'] ?? null;
            $resolvedMucuaName = ($itemMucuaSmid !== null && isset($mucuaNameMap[$itemMucuaSmid])) ? $mucuaNameMap[$itemMucuaSmid] : null;
            $itemMediaType = ($item['_source_type'] ?? '') === 'blog' ? 'blog' : $mediaType;

            $insertStmt->execute([
                ':name' => $nodeName,
                ':description' => $nodeDesc ?: null,
                ':url' => $nodeUrl,
                ':animation' => $animation,
                ':constellation_id' => $constellationId,
                ':source_facet' => $resolvedMucuaName,
                ':media_type' => $itemMediaType,
                ':source_created_at' => $item['created'] ?? null,
                ':import_slug' => $item['slug'] ?? null,
            ]);
            $nodeId = (int)$pdo->lastInsertId();
            $nodeIdMap[$itemIndex] = $nodeId;

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
            if ($importedCount % $batchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                $streamMsg('info', "  {$importedCount}/{$expectedCount} nodes created");
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to create node: ' . ($item['slug'] ?? '?') . ' (' . $e->getMessage() . ')';
            $logger('ERROR', 'Node create error: ' . $e->getMessage());
        }
    }
    $pdo->commit();
    $streamMsg('success', "Phase 1 complete: {$importedCount}/{$expectedCount} nodes created");

    // ── Phase 2: download media files (unless skipped) ─────────────────────
    $mediaCount = 0;
    $mediaErrors = 0;

    if (!$skipMedia) {
        $streamMsg('info', "Phase 2: Downloading media files...");
        $uploadDir = UPLOAD_DIR;

        foreach ($galaxiaItems as $itemIndex => $item) {
            $nodeId = $nodeIdMap[$itemIndex] ?? null;
            if ($nodeId === null) continue;

            $itemSlug = $item['slug'] ?? 'unknown';
            $nodeName = $item['title'] ?? $itemSlug;
            $nodeDesc = $item['description'] ?? '';
            $mediaType = $item['type'] ?? 'arquivo';

            $itemMucuaSmidVal = $item['mucua_smid'] ?? '';
            $itemMucuaSlugForUrl = $mucuaSlugMap[$itemMucuaSmidVal] ?? '';
            $itemFrontendBase = $mucuaUriMap[$itemMucuaSmidVal] ?? $downloadBase;
            $nodeUrl = $itemFrontendBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlugForUrl . '/' . $itemSlug;
            $animation = _mocambos_random_animation();

            $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
            $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
            if (!is_dir($nodeFullDir)) @mkdir($nodeFullDir, 0775, true);

            $imageUrl = null;
            $iconUrl = null;
            $audioUrl = null;
            $videoUrl = null;
            $needsUpdate = false;

            $counter = ($itemIndex + 1) . "/{$expectedCount}";

            $contentHash = '';
            $content = $item['content'] ?? [];
            if (is_array($content) && !empty($content) && isset($content[0]['hash_sum'])) {
                $contentHash = $content[0]['hash_sum'];
            }
            $dlMucuaSlug = $mucuaSlugMap[$item['mucua_smid'] ?? ''] ?? $mucuaSlug;
            $dlBase = $downloadBase . '/' . $galaxiaSlug . '/' . $dlMucuaSlug;
            $hashSuffix = $contentHash !== '' ? '/' . $contentHash : '';

            if ($mediaType === 'imagem') {
                $streamMsg('download', "({$counter}) Downloading image: {$nodeName}");
                $localPath = mocambos_download_file($dlBase . '/acervo/download/' . $itemSlug . $hashSuffix, $nodeFullDir, 'image', $logger);
                if ($localPath !== null) {
                    $relPath = $nodeRelDir . '/' . basename($localPath);
                    $imageUrl = $relPath;
                    $iconUrl = $relPath;
                    $needsUpdate = true;
                } else {
                    $mediaErrors++;
                }
                if ($iconUrl === null) {
                    $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                    if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
                }
            } elseif ($mediaType === 'video') {
                $streamMsg('download', "({$counter}) Downloading video: {$nodeName}");
                $localPath = mocambos_download_file($dlBase . '/acervo/download/' . $itemSlug . $hashSuffix, $nodeFullDir, 'video', $logger);
                if ($localPath !== null) { $videoUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true; } else { $mediaErrors++; }
                $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
            } elseif ($mediaType === 'audio') {
                $streamMsg('download', "({$counter}) Downloading audio: {$nodeName}");
                $localPath = mocambos_download_file($dlBase . '/acervo/download/' . $itemSlug . $hashSuffix, $nodeFullDir, 'audio', $logger);
                if ($localPath !== null) { $audioUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true; } else { $mediaErrors++; }
                $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
            } else {
                $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
            }

            if ($needsUpdate) {
                db_update_node($nodeId, $nodeName, $nodeDesc ?: null, $nodeUrl, $animation, $constellationId, 'object', null, $imageUrl, null, $audioUrl, true, false, $videoUrl, true, false, false, $iconUrl);
                $mediaCount++;
            }
        }
        $streamMsg('success', "Phase 2 complete: {$mediaCount} media files downloaded" . ($mediaErrors > 0 ? " ({$mediaErrors} failed)" : ''));
        if ($mediaErrors > 0) $errors[] = "{$mediaErrors} media downloads failed";
    }

    $actualNodes = db_get_nodes($constellationId);
    $actualCount = count($actualNodes);
    $errCount = count($errors);
    $streamMsg($errCount > 0 ? 'warning' : 'success',
        "Galaxia {$galaxiaSlug} done: {$importedCount}/{$expectedCount} items imported" .
        ($errCount > 0 ? " ({$errCount} errors)" : ''));

    return [
        'constellation_id' => $constellationId,
        'is_new' => $isNew,
        'is_incremental' => $isIncremental,
        'expected_count' => $expectedCount,
        'imported_count' => $importedCount,
        'verified_count' => $actualCount,
        'media_count' => $mediaCount,
        'media_errors' => $mediaErrors,
        'errors' => $errors,
    ];
}

// ── Data-fetch helpers ───────────────────────────────────────────────────────

function _mocambos_fetch_json(string $url): mixed {
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    return json_decode($body, true);
}

/**
 * Build the three mucua maps: smid → name, smid → slug, smid → public_uri.
 */
function _mocambos_fetch_mucua_maps(string $apiBase): array {
    $name = [];
    $slug = [];
    $uri = [];
    $mucuaList = _mocambos_fetch_json($apiBase . '/mucua');
    if (is_array($mucuaList)) {
        foreach ($mucuaList as $m) {
            $mSmid = $m['smid'] ?? $m['uuid'] ?? null;
            if ($mSmid === null) continue;
            $name[$mSmid] = $m['name'] ?? $m['slug'] ?? (string)$mSmid;
            $slug[$mSmid] = $m['slug'] ?? (string)$mSmid;
            $pUri = $m['public_uri'] ?? null;
            if ($pUri !== null && $pUri !== '') {
                $uri[$mSmid] = rtrim($pUri, '/');
            }
        }
    }
    return ['name' => $name, 'slug' => $slug, 'uri' => $uri];
}

/**
 * Pull every item from /acervo/find and /blog/find, tagging each with
 * _source_type so the import loop can distinguish them. $progress receives
 * a one-line message per page fetched.
 */
function _mocambos_fetch_all_items(string $apiBase, ?Closure $progress = null): array {
    $progress ??= function(string $msg) {};
    $allItems = [];

    $page = 1;
    while (true) {
        $data = _mocambos_fetch_json($apiBase . '/acervo/find?pag_tamanho=100&pag_atual=' . $page);
        if (!is_array($data) || !isset($data['items'])) break;
        $pageCount = (int)($data['page_count'] ?? 1);
        foreach ($data['items'] as $item) {
            $item['_source_type'] = 'acervo';
            $allItems[] = $item;
        }
        $progress("Fetched acervo page {$page}/{$pageCount} (" . count($allItems) . " items so far)");
        if ($page >= $pageCount) break;
        $page++;
    }

    $page = 1;
    while (true) {
        $data = _mocambos_fetch_json($apiBase . '/blog/find?pag_tamanho=100&pag_atual=' . $page);
        if (!is_array($data) || !isset($data['items'])) break;
        $pageCount = (int)($data['page_count'] ?? 1);
        foreach ($data['items'] as $item) {
            $item['_source_type'] = 'blog';
            $allItems[] = $item;
        }
        if ($pageCount > 0) {
            $progress("Fetched blog page {$page}/{$pageCount}");
        }
        if ($page >= $pageCount) break;
        $page++;
    }

    return $allItems;
}

function _mocambos_random_animation(): string {
    return json_encode([
        'radius' => 5 + rand(0, 3),
        'theta' => rand(0, 628) / 100,
        'phi' => rand(0, 314) / 100,
        'speed' => 0.002 + (rand(0, 4) / 1000),
        'phase' => rand(0, 628) / 100,
    ], JSON_THROW_ON_ERROR);
}

// ── CLI input helpers ────────────────────────────────────────────────────────

function _mocambos_cli_logger(bool $quiet): Closure {
    return function(string $level, string $msg, bool $verbose = false) use ($quiet) {
        if ($quiet && ($verbose || $level === 'INFO')) return;
        $ts = date('H:i:s');
        $prefix = match($level) {
            'ERROR' => "\033[31m[ERR]\033[0m",
            'WARN'  => "\033[33m[WRN]\033[0m",
            'OK'    => "\033[32m[OK ]\033[0m",
            'DL'    => "\033[35m[DL ]\033[0m",
            default => "\033[36m[INF]\033[0m",
        };
        echo "{$prefix} {$ts} {$msg}\n";
    };
}

function _mocambos_cli_prompt(string $prompt, string $default = ''): string {
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $prompt . $suffix . ': ';
    $input = trim(fgets(STDIN) ?: '');
    return $input !== '' ? $input : $default;
}

function _mocambos_cli_confirm(string $prompt): bool {
    echo $prompt . ' [y/N]: ';
    $input = strtolower(trim(fgets(STDIN) ?: ''));
    return $input === 'y' || $input === 'yes';
}
