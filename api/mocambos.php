<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/mocambos-download.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

requireApiKey();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'validate') {
        requireWriteAccess();

        $apiBase = trim($_GET['api_base'] ?? '');
        if ($apiBase === '' || !filter_var($apiBase, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['valid' => false, 'error' => 'Invalid URL format. Expected a full URL like https://hostname/api/v2'], JSON_THROW_ON_ERROR);
            exit();
        }
        if (!preg_match('#^https?://#', $apiBase)) {
            http_response_code(400);
            echo json_encode(['valid' => false, 'error' => 'URL must start with http:// or https://'], JSON_THROW_ON_ERROR);
            exit();
        }

        $checks = [];
        $allOk = true;

        // Helper: fetch a URL and return [ok, http_status, body, error]
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

        // 1. Check /galaxia endpoint
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
                // Validate structure of first item
                $first = $data[0];
                $missing = [];
                foreach (['name', 'slug', 'default_mucua'] as $field) {
                    if (!isset($first[$field]) || $first[$field] === '') {
                        $missing[] = $field;
                    }
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

        // 2. Check /mucua endpoint
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
                    if (!isset($first[$field]) || $first[$field] === '') {
                        $missing[] = $field;
                    }
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

        // 3. Check /acervo/find endpoint
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

        // 4. Check /blog/find endpoint
        [$ok, $status, $body, $err] = $probe($apiBase . '/blog/find?pag_tamanho=1');
        if (!$ok) {
            $checks[] = ['endpoint' => '/blog/find', 'status' => 'fail', 'http_status' => $status,
                'detail' => $err ?? "HTTP {$status} — expected 200. This endpoint must return a paginated JSON object with an 'items' array."];
            // Blog is optional — don't fail the whole validation
            $checks[count($checks) - 1]['status'] = 'warn';
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

    } elseif ($method === 'GET' && $action === 'galaxias') {
        requireWriteAccess();

        $apiBase = trim($_GET['api_base'] ?? '');
        if ($apiBase === '' || !filter_var($apiBase, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid api_base URL'], JSON_THROW_ON_ERROR);
            exit();
        }

        // Fetch galaxias
        $galaxiasJson = @file_get_contents($apiBase . '/galaxia');
        if ($galaxiasJson === false) {
            http_response_code(502);
            echo json_encode(['error' => 'Failed to reach Mocambos API at ' . $apiBase], JSON_THROW_ON_ERROR);
            exit();
        }
        $galaxias = json_decode($galaxiasJson, true);
        if (!is_array($galaxias)) {
            http_response_code(502);
            echo json_encode(['error' => 'Invalid response from Mocambos galaxia endpoint'], JSON_THROW_ON_ERROR);
            exit();
        }

        // Fetch mucuas for slug resolution
        $mucuasJson = @file_get_contents($apiBase . '/mucua');
        $mucuas = is_string($mucuasJson) ? json_decode($mucuasJson, true) : [];
        if (!is_array($mucuas)) $mucuas = [];
        $mucuaByUuid = [];
        foreach ($mucuas as $m) {
            $mucuaId = $m['smid'] ?? $m['uuid'] ?? null;
            if ($mucuaId !== null) {
                $mucuaByUuid[$mucuaId] = $m['slug'] ?? '';
            }
        }

        // Cross-reference with local constellations
        db_ensure_constellations_import_source_column();
        $constellations = db_get_constellations();

        $result = [];
        foreach ($galaxias as $g) {
            $slug = $g['slug'] ?? '';
            $name = $g['name'] ?? $slug;
            $defaultMucuaUuid = $g['default_mucua'] ?? '';
            $mucuaSlug = $mucuaByUuid[$defaultMucuaUuid] ?? '';

            // Check if already imported
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

    } elseif ($method === 'POST' && $action === 'import') {
        requireWriteAccess();
        set_time_limit(0);

        $input = stream_get_contents(fopen('php://input', 'r'), 10485760);
        $data = json_decode($input, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body'], JSON_THROW_ON_ERROR);
            exit();
        }

        $apiBase = trim($data['api_base'] ?? 'https://timbuktu.mocambos.net/api/v2');
        $galaxias = $data['galaxias'] ?? [];
        if (!is_array($galaxias) || empty($galaxias)) {
            http_response_code(400);
            echo json_encode(['error' => 'No galaxias specified'], JSON_THROW_ON_ERROR);
            exit();
        }

        // Switch to streaming newline-delimited JSON for real-time progress
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Accel-Buffering: no'); // Disable Nginx buffering
        if (ob_get_level()) ob_end_flush();

        // Setup detailed log file
        $logDir = defined('LOG_DIR') ? LOG_DIR : (__DIR__ . '/../logs');
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

        // Derive base URL for downloads (strip /api/v2)
        $downloadBase = preg_replace('#/api/v2/?$#', '', $apiBase);

        db_ensure_constellations_import_source_column();
        db_ensure_nodes_icon_url_column();
        db_ensure_nodes_clustering_columns();

        $allConstellations = db_get_constellations();

        // Fetch mucua list for name resolution (smid → name)
        $mucuaNameMap = [];
        $mucuaListJson = @file_get_contents($apiBase . '/mucua');
        if ($mucuaListJson !== false) {
            $mucuaList = json_decode($mucuaListJson, true);
            if (is_array($mucuaList)) {
                foreach ($mucuaList as $m) {
                    $mSmid = $m['smid'] ?? $m['uuid'] ?? null;
                    if ($mSmid !== null) {
                        // Use name if available, fall back to slug
                        $mucuaNameMap[$mSmid] = $m['name'] ?? $m['slug'] ?? (string)$mSmid;
                    }
                }
            }
        }
        $streamMsg('info', 'Resolved ' . count($mucuaNameMap) . ' mucua names');

        // Build smid-to-slug map for selected galaxias
        $galaxiaSmids = [];
        foreach ($galaxias as $gal) {
            if (!empty($gal['galaxia_smid'])) {
                $galaxiaSmids[$gal['galaxia_smid']] = $gal['galaxia_slug'] ?? '';
            }
        }

        // ── Fetch ALL items once (shared across galaxias) ─────────────
        $streamMsg('info', "Fetching media items from Mocambos API...");
        $allItems = [];
        $page = 1;
        while (true) {
            $acervoUrl = $apiBase . '/acervo/find?pag_tamanho=100&pag_atual=' . $page;
            $writeLog('DEBUG', "GET {$acervoUrl}");
            $acervoJson = @file_get_contents($acervoUrl);
            if ($acervoJson === false) {
                $streamMsg('warning', "Failed to fetch acervo page {$page}, stopping");
                break;
            }
            $acervoData = json_decode($acervoJson, true);
            if (!is_array($acervoData) || !isset($acervoData['items'])) break;
            $pageCount = (int)($acervoData['page_count'] ?? 1);
            foreach ($acervoData['items'] as $item) {
                $item['_source_type'] = 'acervo';
                $allItems[] = $item;
            }
            $streamMsg('info', "Fetched acervo page {$page}/{$pageCount} (" . count($allItems) . " items so far)");
            if ($page >= $pageCount) break;
            $page++;
        }

        $streamMsg('info', "Fetching blog articles from Mocambos API...");
        $page = 1;
        while (true) {
            $blogUrl = $apiBase . '/blog/find?pag_tamanho=100&pag_atual=' . $page;
            $writeLog('DEBUG', "GET {$blogUrl}");
            $blogJson = @file_get_contents($blogUrl);
            if ($blogJson === false) break;
            $blogData = json_decode($blogJson, true);
            if (!is_array($blogData) || !isset($blogData['items'])) break;
            $pageCount = (int)($blogData['page_count'] ?? 1);
            foreach ($blogData['items'] as $item) {
                $item['_source_type'] = 'blog';
                $allItems[] = $item;
            }
            if ($pageCount > 0) {
                $streamMsg('info', "Fetched blog page {$page}/{$pageCount}");
            }
            if ($page >= $pageCount) break;
            $page++;
        }

        $streamMsg('info', "Total items fetched: " . count($allItems));

        // ── Fetch galaxia info for names (one request) ────────────────
        $galaxiaInfoMap = [];
        $galInfoJson = @file_get_contents($apiBase . '/galaxia');
        if ($galInfoJson !== false) {
            $galList = json_decode($galInfoJson, true);
            if (is_array($galList)) {
                foreach ($galList as $gl) {
                    $galaxiaInfoMap[$gl['slug'] ?? ''] = $gl;
                }
            }
        }

        // ── Process each selected galaxia ─────────────────────────────
        $results = [];

        foreach ($galaxias as $gal) {
            $galaxiaSlug = $gal['galaxia_slug'] ?? '';
            $mucuaSlug = $gal['mucua_slug'] ?? '';
            $galaxiaSmid = $gal['galaxia_smid'] ?? '';
            if ($galaxiaSlug === '') continue;

            // Resolve smid from galaxia info if not provided by frontend
            if ($galaxiaSmid === '' && isset($galaxiaInfoMap[$galaxiaSlug])) {
                $galaxiaSmid = $galaxiaInfoMap[$galaxiaSlug]['smid'] ?? '';
                $writeLog('INFO', "Resolved smid for {$galaxiaSlug} from galaxia list: {$galaxiaSmid}");
            }

            $errors = [];
            $isNew = true;
            $constellationId = null;

            // Check if already imported
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

            // Filter items belonging to this galaxia
            $galaxiaItems = [];
            foreach ($allItems as $item) {
                if (($item['galaxia_smid'] ?? '') === $galaxiaSmid) {
                    $galaxiaItems[] = $item;
                }
            }

            $writeLog('INFO', "--- Galaxia: {$galaxiaSlug} (smid={$galaxiaSmid}), mucua: {$mucuaSlug}, items: " . count($galaxiaItems) . " ---");
            $streamMsg('info', "Processing galaxia: {$galaxiaSlug} (" . count($galaxiaItems) . " items)");

            if ($constellationId !== null) {
                $writeLog('INFO', "Existing constellation found (ID {$constellationId}), clearing nodes for re-import");
                $streamMsg('info', "Re-importing — clearing existing nodes...");
                db_clear_constellation_nodes($constellationId);
            } else {
                $galInfo = $galaxiaInfoMap[$galaxiaSlug] ?? [];
                $galaxiaName = $galInfo['name'] ?? $galaxiaSlug;
                $galaxiaDesc = $galInfo['description'] ?? '';

                // Create constellation with unique slug
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
                            $slug = $baseSlug . '-' . $suffix;
                            $suffix++;
                        } else {
                            throw $e;
                        }
                    }
                }
            }

            // Set import_source immediately so it shows as imported even if aborted
            $importSource = json_encode([
                'source' => 'mocambos',
                'api_base' => $apiBase,
                'galaxia_slug' => $galaxiaSlug,
                'mucua_slug' => $mucuaSlug,
            ], JSON_THROW_ON_ERROR);
            db_set_constellation_import_source($constellationId, $importSource);

            $expectedCount = count($galaxiaItems);
            $importedCount = 0;

            // ── Phase 1: Fast batch insert of all nodes ──────────────
            $streamMsg('info', "Phase 1: Creating {$expectedCount} nodes...");
            $pdo = getDB();
            $insertStmt = $pdo->prepare("
                INSERT INTO nodes (name, description, url, animation, constellation_id, node_type, audio_autoplay, video_autoplay, mucua_name, media_type, source_created_at)
                VALUES (:name, :description, :url, :animation, :constellation_id, 'object', 1, 1, :mucua_name, :media_type, :source_created_at)
            ");
            $kwInsertStmt = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :cid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $kwLookupStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword AND constellation_id = :cid LIMIT 1");
            $nkInsertStmt = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid) ON DUPLICATE KEY UPDATE node_id=node_id");

            $batchSize = 500;
            $pdo->beginTransaction();
            $nodeIdMap = []; // itemIndex → nodeId for phase 2

            foreach ($galaxiaItems as $itemIndex => $item) {
                try {
                    $nodeName = $item['title'] ?? ($item['slug'] ?? 'unknown');
                    $nodeDesc = $item['description'] ?? '';
                    $mediaType = $item['type'] ?? 'arquivo';
                    $tags = $item['tags'] ?? [];
                    if (!is_array($tags)) $tags = [];

                    $nodeUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/permalink/';
                    $nodeUrl .= (($item['_source_type'] ?? '') === 'blog') ? 'blog/artigo/' . ($item['slug'] ?? '') : 'acervo/' . ($item['slug'] ?? '');

                    $animation = json_encode([
                        'radius' => 5 + rand(0, 3), 'theta' => rand(0, 628) / 100,
                        'phi' => rand(0, 314) / 100, 'speed' => 0.002 + (rand(0, 4) / 1000),
                        'phase' => rand(0, 628) / 100,
                    ], JSON_THROW_ON_ERROR);

                    $itemMucuaSmid = $item['mucua_smid'] ?? null;
                    $resolvedMucuaName = ($itemMucuaSmid !== null && isset($mucuaNameMap[$itemMucuaSmid])) ? $mucuaNameMap[$itemMucuaSmid] : null;
                    $itemMediaType = ($item['_source_type'] ?? '') === 'blog' ? 'blog' : $mediaType;

                    $insertStmt->execute([
                        ':name' => $nodeName,
                        ':description' => $nodeDesc ?: null,
                        ':url' => $nodeUrl,
                        ':animation' => $animation,
                        ':constellation_id' => $constellationId,
                        ':mucua_name' => $resolvedMucuaName,
                        ':media_type' => $itemMediaType,
                        ':source_created_at' => $item['created'] ?? null,
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
                    $writeLog('ERROR', 'Node create error: ' . $e->getMessage());
                }
            }
            $pdo->commit();
            $streamMsg('success', "Phase 1 complete: {$importedCount}/{$expectedCount} nodes created");

            // ── Phase 2: Download media files ────────────────────────
            $streamMsg('info', "Phase 2: Downloading media files...");
            $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
            $mediaCount = 0;
            $mediaErrors = 0;

            foreach ($galaxiaItems as $itemIndex => $item) {
                $nodeId = $nodeIdMap[$itemIndex] ?? null;
                if ($nodeId === null) continue;

                $itemSlug = $item['slug'] ?? 'unknown';
                $nodeName = $item['title'] ?? $itemSlug;
                $nodeDesc = $item['description'] ?? '';
                $mediaType = $item['type'] ?? 'arquivo';

                $nodeUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/permalink/';
                $nodeUrl .= (($item['_source_type'] ?? '') === 'blog') ? 'blog/artigo/' . $itemSlug : 'acervo/' . $itemSlug;

                $animation = json_encode([
                    'radius' => 5 + rand(0, 3), 'theta' => rand(0, 628) / 100,
                    'phi' => rand(0, 314) / 100, 'speed' => 0.002 + (rand(0, 4) / 1000),
                    'phase' => rand(0, 628) / 100,
                ], JSON_THROW_ON_ERROR);

                $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
                $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
                if (!is_dir($nodeFullDir)) @mkdir($nodeFullDir, 0775, true);

                $imageUrl = null;
                $iconUrl = null;
                $audioUrl = null;
                $videoUrl = null;
                $needsUpdate = false;

                $counter = ($itemIndex + 1) . "/{$expectedCount}";

                if ($mediaType === 'imagem') {
                    $dlUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/download/' . $itemSlug;
                    $streamMsg('download', "({$counter}) Downloading image: {$nodeName}");
                    $localPath = mocambos_download_file($dlUrl, $nodeFullDir, 'image', $writeLog);
                    if ($localPath !== null) {
                        $relPath = $nodeRelDir . '/' . basename($localPath);
                        $imageUrl = $relPath;
                        $iconUrl = $relPath;
                        $needsUpdate = true;
                    } else {
                        $mediaErrors++;
                    }
                    if ($iconUrl === null) {
                        $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                        $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon', $writeLog);
                        if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
                    }
                } elseif ($mediaType === 'video') {
                    $dlUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/download/' . $itemSlug;
                    $streamMsg('download', "({$counter}) Downloading video: {$nodeName}");
                    $localPath = mocambos_download_file($dlUrl, $nodeFullDir, 'video', $writeLog);
                    if ($localPath !== null) { $videoUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true; } else { $mediaErrors++; }
                    $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                    $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon', $writeLog);
                    if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
                } elseif ($mediaType === 'audio') {
                    $dlUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/download/' . $itemSlug;
                    $streamMsg('download', "({$counter}) Downloading audio: {$nodeName}");
                    $localPath = mocambos_download_file($dlUrl, $nodeFullDir, 'audio', $writeLog);
                    if ($localPath !== null) { $audioUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true; } else { $mediaErrors++; }
                    $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                    $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon', $writeLog);
                    if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
                } else {
                    $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                    $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon', $writeLog);
                    if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
                }

                if ($needsUpdate) {
                    db_update_node($nodeId, $nodeName, $nodeDesc ?: null, $nodeUrl, $animation, $constellationId, 'object', null, $imageUrl, null, $audioUrl, true, false, $videoUrl, true, false, false, $iconUrl);
                    $mediaCount++;
                }
            }
            $streamMsg('success', "Phase 2 complete: {$mediaCount} media files downloaded" . ($mediaErrors > 0 ? " ({$mediaErrors} failed)" : ''));
            if ($mediaErrors > 0) $errors[] = "{$mediaErrors} media downloads failed";

            // Verification: count nodes
            $actualNodes = db_get_nodes($constellationId);
            $actualCount = count($actualNodes);

            $errCount = count($errors);
            $streamMsg($errCount > 0 ? 'warning' : 'success',
                "Galaxia {$galaxiaSlug} done: {$importedCount}/{$expectedCount} items imported" .
                ($errCount > 0 ? " ({$errCount} errors)" : ''));

            $results[] = [
                'galaxia_slug' => $galaxiaSlug,
                'constellation_id' => $constellationId,
                'is_new' => $isNew,
                'expected_count' => $expectedCount,
                'imported_count' => $importedCount,
                'verified_count' => $actualCount,
                'errors' => $errors,
            ];
        }

        $writeLog('INFO', 'Import complete — ' . count($results) . ' galaxia(s) processed');
        if ($logFp) {
            $writeLog('INFO', 'Log file: ' . $logFile);
            fclose($logFp);
            $logFp = null;
        }
        $streamMsg('done', 'Import complete', ['success' => true, 'results' => $results]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed or unknown action'], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    if (isset($writeLog)) $writeLog('ERROR', 'Fatal: ' . $e->getMessage());
    if (isset($logFp) && $logFp) fclose($logFp);
    http_response_code(500);
    error_log('mocambos.php error: ' . $e->getMessage());
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
}

