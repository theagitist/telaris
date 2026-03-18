<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';

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
    if ($method === 'GET' && $action === 'galaxias') {
        requireWriteAccess();

        $apiBase = trim($_GET['api_base'] ?? 'https://timbuktu.mocambos.net/api/v2');
        if (!filter_var($apiBase, FILTER_VALIDATE_URL)) {
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
            if (isset($m['uuid'])) {
                $mucuaByUuid[$m['uuid']] = $m['slug'] ?? '';
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

        // Derive base URL for downloads (strip /api/v2)
        $downloadBase = preg_replace('#/api/v2/?$#', '', $apiBase);

        db_ensure_constellations_import_source_column();
        db_ensure_nodes_icon_url_column();

        $allConstellations = db_get_constellations();
        $results = [];

        foreach ($galaxias as $gal) {
            $galaxiaSlug = $gal['galaxia_slug'] ?? '';
            $mucuaSlug = $gal['mucua_slug'] ?? '';
            if ($galaxiaSlug === '') continue;

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

            if ($constellationId !== null) {
                // Re-import: clear existing nodes
                db_clear_constellation_nodes($constellationId);
            } else {
                // New: fetch galaxia name
                $galaxiaName = $galaxiaSlug;
                $galaxiaDesc = '';
                $galInfoJson = @file_get_contents($apiBase . '/galaxia');
                if ($galInfoJson !== false) {
                    $galList = json_decode($galInfoJson, true);
                    if (is_array($galList)) {
                        foreach ($galList as $gl) {
                            if (($gl['slug'] ?? '') === $galaxiaSlug) {
                                $galaxiaName = $gl['name'] ?? $galaxiaSlug;
                                $galaxiaDesc = $gl['description'] ?? '';
                                break;
                            }
                        }
                    }
                }

                // Create constellation with unique slug
                $baseSlug = db_slugify($galaxiaName);
                $slug = $baseSlug;
                $suffix = 2;
                while (true) {
                    try {
                        $constellationId = db_create_constellation($galaxiaName, $galaxiaDesc, $slug, 'abstract');
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

            // Fetch acervo (media) items
            $allItems = [];
            $page = 1;
            while (true) {
                $acervoUrl = $apiBase . '/acervo/find?pag_tamanho=100&pag_atual=' . $page;
                $acervoJson = @file_get_contents($acervoUrl);
                if ($acervoJson === false) break;
                $acervoData = json_decode($acervoJson, true);
                if (!is_array($acervoData) || !isset($acervoData['items'])) break;
                foreach ($acervoData['items'] as $item) {
                    $item['_source_type'] = 'acervo';
                    $allItems[] = $item;
                }
                $pageCount = (int)($acervoData['page_count'] ?? 1);
                if ($page >= $pageCount) break;
                $page++;
            }

            // Fetch blog articles
            $page = 1;
            while (true) {
                $blogUrl = $apiBase . '/blog/find?pag_tamanho=100&pag_atual=' . $page;
                $blogJson = @file_get_contents($blogUrl);
                if ($blogJson === false) break;
                $blogData = json_decode($blogJson, true);
                if (!is_array($blogData) || !isset($blogData['items'])) break;
                foreach ($blogData['items'] as $item) {
                    $item['_source_type'] = 'blog';
                    $allItems[] = $item;
                }
                $pageCount = (int)($blogData['page_count'] ?? 1);
                if ($page >= $pageCount) break;
                $page++;
            }

            $expectedCount = count($allItems);
            $importedCount = 0;

            foreach ($allItems as $item) {
                $itemSlug = $item['slug'] ?? 'unknown';
                try {
                    $nodeName = $item['title'] ?? $itemSlug;
                    $nodeDesc = $item['description'] ?? '';
                    $mediaType = $item['type'] ?? 'arquivo';
                    $tags = $item['tags'] ?? [];
                    if (!is_array($tags)) $tags = [];

                    // Build source permalink for URL
                    $nodeUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/permalink/';
                    if (($item['_source_type'] ?? '') === 'blog') {
                        $nodeUrl .= 'blog/artigo/' . $itemSlug;
                    } else {
                        $nodeUrl .= 'acervo/' . $itemSlug;
                    }

                    // Random animation
                    $animationArray = [
                        'radius' => 5 + rand(0, 3),
                        'theta'  => rand(0, 628) / 100,
                        'phi'    => rand(0, 314) / 100,
                        'speed'  => 0.002 + (rand(0, 4) / 1000),
                        'phase'  => rand(0, 628) / 100,
                    ];
                    $animation = json_encode($animationArray, JSON_THROW_ON_ERROR);

                    // Create node
                    $nodeId = db_create_node(
                        $nodeName,
                        $nodeDesc ?: null,
                        $nodeUrl,
                        $animation,
                        $constellationId,
                        'object',
                        null,
                        null, // image_url
                        null, // embed_code
                        null, // audio_url
                        true, // audio_autoplay
                        false, // is_accentuated
                        null, // video_url
                        true, // video_autoplay
                        false, // audio_loop
                        false, // show_keywords
                        null  // icon_url
                    );

                    // Setup upload directory
                    $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
                    $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
                    $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
                    if (!is_dir($nodeFullDir)) {
                        mkdir($nodeFullDir, 0755, true);
                    }

                    $imageUrl = null;
                    $iconUrl = null;
                    $audioUrl = null;
                    $videoUrl = null;
                    $needsUpdate = false;

                    // Download media based on type
                    if ($mediaType === 'imagem') {
                        // Download image → used for both icon and image
                        $dlUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/download/' . $itemSlug;
                        $localPath = mocambos_download_file($dlUrl, $nodeFullDir, 'image');
                        if ($localPath !== null) {
                            $relPath = $nodeRelDir . '/' . basename($localPath);
                            $imageUrl = $relPath;
                            $iconUrl = $relPath;
                            $needsUpdate = true;
                        } else {
                            $errors[] = 'Failed to download media for: ' . $itemSlug;
                        }
                        // Also try thumbnail as fallback icon
                        if ($iconUrl === null) {
                            $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                            $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon');
                            if ($thumbPath !== null) {
                                $iconUrl = $nodeRelDir . '/' . basename($thumbPath);
                                $needsUpdate = true;
                            }
                        }
                    } elseif ($mediaType === 'video') {
                        // Download video
                        $dlUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/download/' . $itemSlug;
                        $localPath = mocambos_download_file($dlUrl, $nodeFullDir, 'video');
                        if ($localPath !== null) {
                            $videoUrl = $nodeRelDir . '/' . basename($localPath);
                            $needsUpdate = true;
                        } else {
                            $errors[] = 'Failed to download media for: ' . $itemSlug;
                        }
                        // Download thumbnail as icon
                        $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                        $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon');
                        if ($thumbPath !== null) {
                            $iconUrl = $nodeRelDir . '/' . basename($thumbPath);
                            $needsUpdate = true;
                        }
                    } elseif ($mediaType === 'audio') {
                        // Download audio
                        $dlUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/download/' . $itemSlug;
                        $localPath = mocambos_download_file($dlUrl, $nodeFullDir, 'audio');
                        if ($localPath !== null) {
                            $audioUrl = $nodeRelDir . '/' . basename($localPath);
                            $needsUpdate = true;
                        } else {
                            $errors[] = 'Failed to download media for: ' . $itemSlug;
                        }
                        // Download thumbnail as icon
                        $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                        $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon');
                        if ($thumbPath !== null) {
                            $iconUrl = $nodeRelDir . '/' . basename($thumbPath);
                            $needsUpdate = true;
                        }
                    } else {
                        // arquivo or blog — try thumbnail for icon
                        $thumbUrl = $downloadBase . '/' . $galaxiaSlug . '/' . $mucuaSlug . '/acervo/thumbnail/' . $itemSlug;
                        $thumbPath = mocambos_download_file($thumbUrl, $nodeFullDir, 'icon');
                        if ($thumbPath !== null) {
                            $iconUrl = $nodeRelDir . '/' . basename($thumbPath);
                            $needsUpdate = true;
                        }
                    }

                    // Update node with downloaded file paths
                    if ($needsUpdate) {
                        db_update_node(
                            $nodeId,
                            $nodeName,
                            $nodeDesc ?: null,
                            $nodeUrl,
                            $animation,
                            $constellationId,
                            'object',
                            null,
                            $imageUrl,
                            null, // embed_code
                            $audioUrl,
                            true,
                            false,
                            $videoUrl,
                            true,
                            false,
                            false,
                            $iconUrl
                        );
                    }

                    // Save keywords/tags
                    if (!empty($tags)) {
                        db_save_node_keywords($nodeId, $tags);
                    }

                    $importedCount++;
                } catch (Throwable $e) {
                    $errors[] = 'Failed to import item: ' . $itemSlug . ' (' . $e->getMessage() . ')';
                    error_log('Mocambos import error for ' . $itemSlug . ': ' . $e->getMessage());
                }
            }

            // Set import_source on constellation
            $importSource = json_encode([
                'source' => 'mocambos',
                'api_base' => $apiBase,
                'galaxia_slug' => $galaxiaSlug,
                'mucua_slug' => $mucuaSlug,
            ], JSON_THROW_ON_ERROR);
            db_set_constellation_import_source($constellationId, $importSource);

            // Verification: count nodes
            $actualNodes = db_get_nodes($constellationId);
            $actualCount = count($actualNodes);

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

        echo json_encode(['success' => true, 'results' => $results], JSON_THROW_ON_ERROR);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed or unknown action'], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('mocambos.php error: ' . $e->getMessage());
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
}

/**
 * Download a file from a URL to local disk using streaming.
 * Returns the local file path on success, null on failure.
 */
function mocambos_download_file(string $url, string $destDir, string $prefix): ?string {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => true,
            'max_redirects' => 5,
        ],
    ]);

    $src = @fopen($url, 'r', false, $ctx);
    if ($src === false) {
        error_log("Mocambos download failed: could not open $url");
        return null;
    }

    // Detect extension from Content-Type
    $meta = stream_get_meta_data($src);
    $ext = 'bin';
    $headers = $meta['wrapper_data'] ?? [];
    foreach ($headers as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $ct = trim(substr($h, 13));
            $ct = explode(';', $ct)[0]; // strip charset
            $mimeMap = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
                'video/mp4' => 'mp4', 'video/webm' => 'webm',
                'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav',
                'application/pdf' => 'pdf',
            ];
            $ext = $mimeMap[trim($ct)] ?? $ext;
            break;
        }
    }

    $destPath = $destDir . '/' . $prefix . '.' . $ext;
    $dest = fopen($destPath, 'w');
    if ($dest === false) {
        fclose($src);
        error_log("Mocambos download failed: could not write to $destPath");
        return null;
    }

    $bytes = 0;
    while (!feof($src)) {
        $chunk = fread($src, 8192);
        if ($chunk === false) break;
        fwrite($dest, $chunk);
        $bytes += strlen($chunk);
    }

    fclose($src);
    fclose($dest);

    // Delete 0-byte files
    if ($bytes === 0) {
        @unlink($destPath);
        error_log("Mocambos download failed: 0 bytes from $url");
        return null;
    }

    return $destPath;
}
