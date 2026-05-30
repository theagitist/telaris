<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/bridges/_lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';
require_once __DIR__ . '/../inc/clustering.php';
require_once __DIR__ . '/../inc/media-optimize.php';

// Set clustering labels from locale
$_locale = locale_resolve_from_request($_GET['lang'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
$_localeStrings = db_get_project_info_for_locale($_locale);
clustering_set_labels(
    $_localeStrings['items_label_text'] ?? 'items',
    $_localeStrings['other_label_text'] ?? 'Other'
);

// Set CORS headers for API responses — restrict to same origin
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json'); header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-HTTP-Method-Override');
    header('Access-Control-Expose-Headers: X-Telaris-Clustered, X-Telaris-Total-Nodes, X-Telaris-Cluster-Path');

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Require API key authentication
requireApiKey();

// Validation constants and functions (shared with tests)
require_once __DIR__ . '/../inc/validation.php';

/**
 * Enforce the primary-visual mutex on the wormhole: at most one of {image, video, pdf}.
 * Priority on conflict: pdf > image > video. Audio is independent of this mutex.
 * The existing video↔audio mutex (audio dropped when video is set) is enforced afterward
 * so a wormhole with audio + pdf still keeps both.
 */
function applyVisualMutex(?string &$imageUrl, ?string &$videoUrl, ?string &$pdfUrl, ?string &$audioUrl): void {
    if ($pdfUrl !== null && $pdfUrl !== '') {
        $imageUrl = null;
        $videoUrl = null;
    } elseif ($imageUrl !== null && $imageUrl !== '') {
        $videoUrl = null;
    }
    // Existing rule: a video clears audio (video player owns the audio track).
    if ($videoUrl !== null && $videoUrl !== '' && $audioUrl !== null && $audioUrl !== '') {
        $audioUrl = null;
    }
}

/**
 * Effective PDF size cap. Reads project_info.pdf_max_bytes when available; falls back to
 * the compile-time default. Caller should treat the result as the runtime limit.
 */
function effectivePdfMaxBytes(): int {
    if (function_exists('db_get_pdf_max_bytes')) {
        $v = db_get_pdf_max_bytes();
        if ($v > 0) return $v;
    }
    return MAX_PDF_BYTES_DEFAULT;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) === 'PUT') {
    $method = 'PUT';
}

try {
    match ($method) {
        'GET' => (function(): void {
            $currentUserId = $_SESSION['admin_user_id'] ?? null;
            $isAdmin = isAdminLoggedIn();

            // Single node fetch by ID (for editor)
            if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
                $row = db_get_node_by_id((int)$_GET['id']);
                if ($row === null) {
                    http_response_code(404);
                    api_error('404.001', 'Node not found.');
                    return;
                }
                if (!$isAdmin && $currentUserId !== null) {
                    $allowed = db_get_constellations_for_user($currentUserId, false);
                    $allowedIds = array_column($allowed, 'id');
                    if (!in_array((int)$row['constellation_id'], $allowedIds, true)) {
                        api_error('403.005', 'Access denied.');
                    }
                }
                echo json_encode(db_format_node($row), JSON_THROW_ON_ERROR);
                return;
            }

            // Related-wormholes (visitor info card): ?related_to=NODE_ID returns up to N
            // wormholes that share keywords with the source, drawn from the source galaxy's
            // *group* (prefix family ∪ tag siblings ∪ cluster co-members ∪ self). Cross-galaxy
            // candidates get a stochastic order boost so they're more likely to surface first.
            if (isset($_GET['related_to']) && ctype_digit((string)$_GET['related_to'])) {
                $sourceId = (int) $_GET['related_to'];
                $limit = isset($_GET['limit']) && ctype_digit((string)$_GET['limit'])
                    ? max(1, min(20, (int) $_GET['limit']))
                    : 5;
                $source = db_get_node_by_id($sourceId);
                if ($source === null) {
                    http_response_code(404);
                    api_error('404.007', 'Source node not found.');
                    return;
                }
                $sourceGalaxyId = (int) $source['constellation_id'];
                $groupIds = db_get_group_galaxy_ids($sourceGalaxyId);
                $rows = db_get_related_nodes($sourceId, $sourceGalaxyId, $groupIds, $limit);
                echo json_encode($rows, JSON_THROW_ON_ERROR);
                return;
            }

            // Multigalaxy: ?galaxies=1,5,7 (numeric IDs only — slugs are resolved server-side
            // in inc/bootstrap.php before they reach the JS). Non-empty list → union mode.
            $multiGalaxyIds = [];
            if (isset($_GET['galaxies']) && is_string($_GET['galaxies']) && $_GET['galaxies'] !== '') {
                $tokens = array_filter(array_map('trim', explode(',', $_GET['galaxies'])), fn($t) => $t !== '');
                foreach ($tokens as $tok) {
                    if (preg_match('/^\d+$/', $tok)) $multiGalaxyIds[] = (int) $tok;
                }
                $multiGalaxyIds = array_values(array_unique($multiGalaxyIds));
            }

            $constellationId = null;
            if (isset($_GET['constellation_id'])) {
                if ($_GET['constellation_id'] === 'all') {
                    $constellationId = null; // all nodes (respecting user access)
                } elseif (ctype_digit((string)$_GET['constellation_id'])) {
                    $constellationId = (int) $_GET['constellation_id'];
                }
            }
            if ($constellationId === null && !isset($_GET['constellation_id']) && empty($multiGalaxyIds)) {
                $constellationId = db_get_default_constellation_id(); // main view without param: show default constellation only
            }

            // Multigalaxy mode: paginated/single-id/admin paths are intentionally not supported
            // since this is a public read-only visitor feature. Reject explicitly so callers know.
            if (!empty($multiGalaxyIds) && (isset($_GET['page']) || isset($_GET['id']))) {
                http_response_code(400);
                api_error('400.005', 'The galaxies parameter is incompatible with page/id.');
                return;
            }

            // Server-side paginated mode (for editor)
            if (isset($_GET['page'])) {
                $page = max(1, (int)$_GET['page']);
                $perPage = isset($_GET['per_page']) ? min(max(1, (int)$_GET['per_page']), 100) : 25;
                $sort = isset($_GET['sort']) && $_GET['sort'] !== '' ? (string)$_GET['sort'] : null;
                $order = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'desc' : 'asc';
                $filter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : null;
                if ($filter === '') $filter = null;
                $touchedToday = !empty($_GET['touched_today']);

                $result = db_get_nodes_paginated($constellationId, $currentUserId, $isAdmin, $page, $perPage, $sort, $order, $filter, $touchedToday);
                $result['nodes'] = db_format_nodes_bulk($result['nodes']);
                echo json_encode($result, JSON_THROW_ON_ERROR);
                return;
            }

            // Flat array mode (for 3D frontend)
            if (!empty($multiGalaxyIds)) {
                $nodes = db_get_nodes_for_constellations($multiGalaxyIds);
            } else {
                $nodes = db_get_nodes($constellationId, $currentUserId, $isAdmin);
            }
            $formatted = db_format_nodes_bulk($nodes);

            // Global search mode: return matching nodes with cluster paths
            $searchQuery = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
            if ($searchQuery !== '') {
                $searchLower = mb_strtolower($searchQuery);
                $searchLimit = isset($_GET['search_limit']) ? min((int)$_GET['search_limit'], 50) : 10;
                $results = [];
                foreach ($formatted as $node) {
                    $nameMatch = mb_stripos($node['name'] ?? '', $searchQuery) !== false;
                    $descMatch = mb_stripos($node['description'] ?? '', $searchQuery) !== false;
                    $keywordMatch = false;
                    foreach ($node['keywords'] ?? [] as $kw) {
                        if (mb_stripos($kw, $searchQuery) !== false) { $keywordMatch = true; break; }
                    }
                    if ($nameMatch || $descMatch || $keywordMatch) {
                        $clusterPath = find_cluster_path_for_node($formatted, (int)$node['id']);
                        $results[] = [
                            'id' => $node['id'],
                            'name' => $node['name'],
                            'description' => $node['description'],
                            'source_facet' => $node['source_facet'] ?? null,
                            'media_type' => $node['media_type'] ?? null,
                            'cluster_path' => $clusterPath,
                        ];
                        if (count($results) >= $searchLimit) break;
                    }
                }
                echo json_encode(['search' => true, 'query' => $searchQuery, 'count' => count($results), 'results' => $results], JSON_THROW_ON_ERROR);
                return;
            }

            $noCluster = isset($_GET['no_cluster']) && $_GET['no_cluster'] === '1';
            $clusterKey = isset($_GET['cluster']) ? trim((string)$_GET['cluster']) : '';

            // Validate cluster key format (alphanumeric, colons, slashes, hyphens, underscores, dots, spaces)
            if ($clusterKey !== '' && !preg_match('/^[a-zA-Z0-9:\/\-_. ]+$/', $clusterKey)) {
                http_response_code(400);
                api_error('400.004', 'Invalid cluster key format.');
                return;
            }

            $totalNodes = count($formatted);
            $isClustered = false;
            $clusterPath = '';

            if (!$noCluster && $clusterKey !== '') {
                $formatted = filter_nodes_by_cluster($formatted, $clusterKey);
                $isClustered = true;
                $clusterPath = $clusterKey;
            } elseif (!$noCluster) {
                $result = compute_clusters($formatted);
                if (count($result) !== $totalNodes) {
                    $formatted = $result;
                    $isClustered = true;
                }
            }

            // If this constellation was imported by a bridge that wants to
            // customize cluster-node iconography, apply the bridge's icon.
            if ($isClustered && $constellationId !== null) {
                $importSource = db_get_constellation_import_source($constellationId);
                if ($importSource !== null) {
                    $src = json_decode($importSource, true);
                    if (is_array($src) && isset($src['source'])) {
                        $iconUrl = bridges_cluster_icon_url_for((string)$src['source']);
                        if ($iconUrl !== null) {
                            foreach ($formatted as &$item) {
                                if (($item['node_type'] ?? '') === 'cluster') {
                                    $item['icon_url'] = $iconUrl;
                                }
                            }
                            unset($item);
                        }
                    }
                }
            }

            header('X-Telaris-Clustered: ' . ($isClustered ? 'true' : 'false'));
            header('X-Telaris-Total-Nodes: ' . $totalNodes);
            header('X-Telaris-Cluster-Path: ' . $clusterPath);

            echo json_encode($formatted, JSON_THROW_ON_ERROR);
        })(),

        'POST' => (function(): void {
            requireWriteAccess();
            $data = $_POST;
            if (empty($data) && empty($_FILES)) {
                $input = stream_get_contents(fopen('php://input', 'r'), 1048576);
                if (!empty($input)) {
                    $data = json_decode($input, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        http_response_code(400);
                        api_error('400.001', 'Invalid JSON: %s', [json_last_error_msg()]);
                        return;
                    }
                }
            }
            // Bulk delete or bulk move nodes in a galaxy that carry a given keyword.
            if (($data['action'] ?? '') === 'bulk_by_keyword') {
                $constellationId = (int)($data['constellation_id'] ?? 0);
                $keywordId = (int)($data['keyword_id'] ?? 0);
                $op = (string)($data['op'] ?? '');
                if ($constellationId <= 0 || $keywordId <= 0 || !in_array($op, ['delete', 'move', 'count'], true)) {
                    http_response_code(400);
                    api_error('400.031', 'constellation_id, keyword_id, and op (delete|move|count) are required.');
                    return;
                }
                $userId = $_SESSION['admin_user_id'] ?? null;
                if (!isAdminLoggedIn()) {
                    $allowed = $userId ? db_get_user_constellation_ids($userId) : [];
                    if (!in_array($constellationId, $allowed, true)) {
                        http_response_code(403);
                        api_error('403.004', 'No edit access to this galaxy.');
                        return;
                    }
                }
                $ids = db_get_node_ids_with_keyword($constellationId, $keywordId);
                if ($op === 'count') {
                    echo json_encode(['count' => count($ids)], JSON_THROW_ON_ERROR);
                    return;
                }
                if ($op === 'delete') {
                    api_require_writable_constellation($constellationId);
                    // Single bulk DELETE + one SELECT for the asset paths.
                    // Pre-fix this was a foreach of db_delete_node = 2 queries
                    // per node, which N+1'd at scale.
                    try {
                        $affected = db_bulk_delete_nodes_by_ids($ids);
                    } catch (Throwable $e) {
                        error_log('nodes.php bulk delete failed: ' . $e->getMessage());
                        $affected = 0;
                    }
                    echo json_encode(['success' => true, 'op' => 'delete', 'affected' => $affected], JSON_THROW_ON_ERROR);
                    return;
                }
                // op === 'move'
                $target = (int)($data['target_constellation_id'] ?? 0);
                if ($target <= 0) {
                    http_response_code(400);
                    api_error('400.032', 'target_constellation_id is required for move.');
                    return;
                }
                if (!isAdminLoggedIn()) {
                    $allowed = $userId ? db_get_user_constellation_ids($userId) : [];
                    if (!in_array($target, $allowed, true)) {
                        http_response_code(403);
                        api_error('403.004', 'No edit access to this galaxy.');
                        return;
                    }
                }
                // A move mutates both ends: nodes leave the source galaxy and
                // land in the target. Neither may be imported or mirrored.
                api_require_writable_constellation($constellationId);
                api_require_writable_constellation($target);
                $moved = db_bulk_move_nodes_by_keyword($constellationId, $keywordId, $target);
                echo json_encode(['success' => true, 'op' => 'move', 'affected' => $moved], JSON_THROW_ON_ERROR);
                return;
            }

            // Bulk-set use_image_as_node for every node in a galaxy.
            if (($data['action'] ?? '') === 'bulk_use_image_as_node') {
                $constellationId = (int)($data['constellation_id'] ?? 0);
                if ($constellationId <= 0) {
                    http_response_code(400);
                    api_error('400.010', 'A constellation id is required.');
                    return;
                }
                $userId = $_SESSION['admin_user_id'] ?? null;
                if (!isAdminLoggedIn()) {
                    $allowed = $userId ? db_get_user_constellation_ids($userId) : [];
                    if (!in_array($constellationId, $allowed, true)) {
                        http_response_code(403);
                        api_error('403.004', 'No edit access to this galaxy.');
                        return;
                    }
                }
                api_require_writable_constellation($constellationId);
                $value = !empty($data['value']);
                $affected = db_bulk_set_nodes_use_image_as_node($constellationId, $value);
                echo json_encode(['success' => true, 'updated' => $affected, 'value' => $value], JSON_THROW_ON_ERROR);
                return;
            }

            // Handle node duplication
            if (isset($data['duplicate_from'])) {
                $sourceId = (int)$data['duplicate_from'];
                $sourceConstellationId = db_get_node_constellation_id($sourceId);
                if ($sourceConstellationId === null) {
                    http_response_code(404);
                    api_error('404.007', 'Source node not found.');
                    return;
                }
                $targetConstellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : null;
                // Both the source and the destination must be accessible to the editor;
                // checking only the destination was an IDOR (any node could be duplicated
                // into any accessible galaxy, exposing content from galaxies the editor
                // cannot see).
                $sourceAccessError = checkEditorConstellationAccess($sourceConstellationId);
                if ($sourceAccessError !== null) {
                    http_response_code(403);
                    error_log('nodes.php access (duplicate source): ' . $sourceAccessError);
                    api_error('403.005', 'Access denied.');
                    return;
                }
                if ($targetConstellationId !== null && $targetConstellationId !== $sourceConstellationId) {
                    if (db_get_constellation_by_id($targetConstellationId) === null) {
                        http_response_code(404);
                        api_error('404.008', 'The target galaxy does not exist.');
                        return;
                    }
                    $targetAccessError = checkEditorConstellationAccess($targetConstellationId);
                    if ($targetAccessError !== null) {
                        http_response_code(403);
                        error_log('nodes.php access (duplicate target): ' . $targetAccessError);
                        api_error('403.005', 'Access denied.');
                        return;
                    }
                }
                // The duplicate lands in the target galaxy (or the source's own
                // galaxy when no target is given); that destination must be
                // writable.
                api_require_writable_constellation($targetConstellationId ?? $sourceConstellationId);
                try {
                    $newId = db_duplicate_node($sourceId, $targetConstellationId);
                    echo json_encode(['id' => $newId, 'success' => true, 'duplicated_from' => $sourceId], JSON_THROW_ON_ERROR);
                } catch (RuntimeException $e) {
                    http_response_code(500);
                    error_log('nodes.php exception: ' . $e->getMessage());
                    api_error('500.001', 'Internal server error.');
                }
                return;
            }

            if (empty($data['name'])) {
                http_response_code(400);
                api_error('400.007', 'Node name is required.');
                return;
            }
            try {
                $animationData = (isset($data['animation'])) ? (is_array($data['animation']) ? $data['animation'] : json_decode($data['animation'], true)) : [];
                $animationArray = [
                    'radius' => isset($animationData['radius']) ? (float)$animationData['radius'] : (5 + rand(0, 3)),
                    'theta'  => isset($animationData['theta'])  ? (float)$animationData['theta']  : (rand(0, 628) / 100),
                    'phi'    => isset($animationData['phi'])    ? (float)$animationData['phi']    : (rand(0, 314) / 100),
                    'speed'  => isset($animationData['speed'])  ? (float)$animationData['speed']  : (0.002 + (rand(0, 4) / 1000)),
                    'phase'  => isset($animationData['phase'])  ? (float)$animationData['phase']  : (rand(0, 628) / 100),
                ];
                $animation = json_encode($animationArray, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                http_response_code(500);
                api_error('500.013', 'Failed to encode the animation data.');
                return;
            }
            if (!is_string($animation)) {
                http_response_code(500);
                api_error('500.014', 'Failed to encode the JSON data.');
                return;
            }
            $description = null;
            if (isset($data['description']) && !empty(trim((string)$data['description']))) {
                $description = trim((string)$data['description']);
            }
            $nodeType = sanitizeNodeType($data['node_type'] ?? 'object');
            $url = null;
            if (isset($data['url']) && !empty(trim((string)$data['url']))) {
                $url = trim((string)$data['url']);
                if (!validateSafeUrl($url)) {
                    http_response_code(400);
                    api_error('400.003', 'Invalid URL: only http and https URLs are allowed.');
                    return;
                }
            }
            $name = trim((string)$data['name']);
            if (empty($name)) {
                http_response_code(400);
                api_error('400.008', 'Node name cannot be empty.');
                return;
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : db_get_default_constellation_id();

            // Enforce editor constellation access
            $accessError = checkEditorConstellationAccess($constellationId);
            if ($accessError !== null) {
                error_log('nodes.php access: ' . $accessError);
                api_error('403.005', 'Access denied.');
            }
            // Imported / mirrored galaxies are owned upstream: no new content.
            api_require_writable_constellation($constellationId);

            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            if ($targetConstellationId !== null) {
                if (db_get_constellation_by_id($targetConstellationId) === null) {
                    http_response_code(400);
                    api_error('404.008', 'The target galaxy does not exist.');
                    return;
                }
                $accessError = checkEditorConstellationAccess($targetConstellationId);
                if ($accessError !== null) {
                    http_response_code(403);
                    error_log('nodes.php access (portal target): ' . $accessError);
                    api_error('403.005', 'Access denied.');
                    return;
                }
            }

            $imageUrl = (isset($data['image_url']) && !empty(trim((string)$data['image_url']))) ? trim((string)$data['image_url']) : null;
            $embedCode = (isset($data['embed_code']) && !empty(trim((string)$data['embed_code']))) ? sanitizeEmbedCode((string)$data['embed_code']) : null;
            $audioUrl = (isset($data['audio_url']) && !empty(trim((string)$data['audio_url']))) ? trim((string)$data['audio_url']) : null;
            $audioAutoplay = isset($data['audio_autoplay']) ? (bool)$data['audio_autoplay'] : true;
            $audioLoop = isset($data['audio_loop']) ? (bool)$data['audio_loop'] : false;
            $videoUrl = (isset($data['video_url']) && !empty(trim((string)$data['video_url']))) ? trim((string)$data['video_url']) : null;
            $videoAutoplay = isset($data['video_autoplay']) ? (bool)$data['video_autoplay'] : true;
            $pdfUrl = (isset($data['pdf_url']) && !empty(trim((string)$data['pdf_url']))) ? trim((string)$data['pdf_url']) : null;
            $isAccentuated = isset($data['is_accentuated']) ? (bool)$data['is_accentuated'] : false;
            $showKeywords = isset($data['show_keywords']) ? (bool)$data['show_keywords'] : false;
            $useImageAsNode = isset($data['use_image_as_node']) ? (bool)$data['use_image_as_node'] : false;
            $iconUrl = (isset($data['icon_url']) && !empty(trim((string)$data['icon_url']))) ? trim((string)$data['icon_url']) : null;
            $imageAttribution = (isset($data['image_attribution']) && !empty(trim((string)$data['image_attribution']))) ? trim((string)$data['image_attribution']) : null;

            // Apply mutex now (URL-only state); file uploads, processed below, may re-trigger
            // the mutex when the resulting URL changes.
            applyVisualMutex($imageUrl, $videoUrl, $pdfUrl, $audioUrl);

            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            $createdBy = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
            $nodeId = db_create_node($name, $description, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay, $audioLoop, $showKeywords, $iconUrl, $imageAttribution, $useImageAsNode, $pdfUrl, $createdBy);
            if ($nodeId === 0) {
                http_response_code(500);
                api_error('500.012', 'Failed to create the node: could not retrieve its id.');
                return;
            }

            $uploadDir = UPLOAD_DIR;
            $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
            $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
            if (!is_dir($nodeFullDir)) {
                if (!mkdir($nodeFullDir, 0755, true)) {
                    http_response_code(500);
                    api_error('500.003', 'Failed to create the upload directory. Check server permissions.');
                    return;
                }
            }

            $uploadedFiles = false;
            $uploadNotice = null;
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['image_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                if ($err !== null) {
                    // Check if the file is a video — extract first frame as the image
                    $videoMime = '';
                    $videoErr = validateUploadedFile($_FILES['image_file'], FRAME_EXTRACTABLE_VIDEO_MIMES, MAX_VIDEO_BYTES, $videoMime);
                    if ($videoErr === null) {
                        $tmpVideo = $nodeFullDir . '/tmp_video_frame_' . bin2hex(random_bytes(8)) . '.' . (MIME_TO_EXT[$videoMime] ?? 'mp4');
                        if (move_uploaded_file($_FILES['image_file']['tmp_name'], $tmpVideo)) {
                            $imageRelPath = "{$nodeRelDir}/image.jpg";
                            $imageFullPath = "{$nodeFullDir}/image.jpg";
                            if (extract_video_frame($tmpVideo, $imageFullPath)) {
                                optimize_image($imageFullPath);
                                $imageUrl = $imageRelPath;
                                $uploadedFiles = true;
                                $uploadNotice = 'A video was uploaded as the image. The first frame was extracted and used instead.';
                            } else {
                                @unlink($tmpVideo);
                                http_response_code(400);
                                api_error('500.010', 'Could not extract a frame from the uploaded video.');
                                return;
                            }
                            @unlink($tmpVideo);
                        } else {
                            http_response_code(500);
                            api_error('500.004', 'Failed to save the uploaded file.');
                            return;
                        }
                    } else {
                        http_response_code(400);
                        error_log('nodes.php validation: ' . $err);
                        api_error('500.004', 'Failed to save the uploaded file.');
                        return;
                    }
                } else {
                    $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                    $imageRelPath = "{$nodeRelDir}/image.{$ext}";
                    $imageFullPath = "{$nodeFullDir}/image.{$ext}";
                    if (move_uploaded_file($_FILES['image_file']['tmp_name'], $imageFullPath)) {
                        optimize_image($imageFullPath);
                        $imageUrl = $imageRelPath;
                        $uploadedFiles = true;
                    } else {
                        http_response_code(500);
                        api_error('500.005', 'Failed to save the uploaded image.');
                        return;
                    }
                }
            }
            if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['icon_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    error_log('nodes.php validation: ' . $err);
                    api_error('500.004', 'Failed to save the uploaded file.');
                    return;
                }
                $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                $iconRelPath = "{$nodeRelDir}/icon.{$ext}";
                $iconFullPath = "{$nodeFullDir}/icon.{$ext}";
                if (move_uploaded_file($_FILES['icon_file']['tmp_name'], $iconFullPath)) {
                    optimize_icon($iconFullPath);
                    $iconUrl = $iconRelPath;
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    api_error('500.006', 'Failed to save the uploaded icon.');
                    return;
                }
            }
            if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['audio_file'], ALLOWED_AUDIO_MIMES, MAX_AUDIO_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    error_log('nodes.php validation: ' . $err);
                    api_error('500.004', 'Failed to save the uploaded file.');
                    return;
                }
                $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                $audioRelPath = "{$nodeRelDir}/audio.{$ext}";
                $audioFullPath = "{$nodeFullDir}/audio.{$ext}";
                if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $audioFullPath)) {
                    optimize_audio($audioFullPath);
                    $audioUrl = $audioRelPath;
                    $videoUrl = null; // Ensure exclusivity
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    api_error('500.007', 'Failed to save the uploaded audio.');
                    return;
                }
            }
            if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['video_file'], ALLOWED_VIDEO_MIMES, MAX_VIDEO_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    error_log('nodes.php validation: ' . $err);
                    api_error('500.004', 'Failed to save the uploaded file.');
                    return;
                }
                $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                $videoRelPath = "{$nodeRelDir}/video.{$ext}";
                $videoFullPath = "{$nodeFullDir}/video.{$ext}";
                if (move_uploaded_file($_FILES['video_file']['tmp_name'], $videoFullPath)) {
                    optimize_video($videoFullPath);
                    $videoUrl = $videoRelPath;
                    $audioUrl = null; // Ensure exclusivity
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    api_error('500.008', 'Failed to save the uploaded video.');
                    return;
                }
            }
            if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['pdf_file'], ALLOWED_PDF_MIMES, effectivePdfMaxBytes(), $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    error_log('nodes.php validation: ' . $err);
                    api_error('500.004', 'Failed to save the uploaded file.');
                    return;
                }
                if (!fileHasPdfMagic($_FILES['pdf_file']['tmp_name'])) {
                    http_response_code(400);
                    api_error('500.011', 'The file does not look like a valid PDF.');
                    return;
                }
                $pdfRelPath = "{$nodeRelDir}/document.pdf";
                $pdfFullPath = "{$nodeFullDir}/document.pdf";
                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfFullPath)) {
                    $pdfUrl = $pdfRelPath;
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    api_error('500.009', 'Failed to save the uploaded PDF.');
                    return;
                }
            }

            // Re-apply visual mutex now that file uploads have potentially changed URLs.
            // Priority pdf > image > video means a same-request PDF upload wins over an existing
            // image/video URL and over any image_file/video_file written above.
            applyVisualMutex($imageUrl, $videoUrl, $pdfUrl, $audioUrl);

            if ($uploadedFiles) {
                db_update_node($nodeId, $name, $description, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay, $audioLoop, $showKeywords, $iconUrl, $imageAttribution, $useImageAsNode, $pdfUrl);
            }

            if (isset($data['keywords'])) {
                $keywords = is_array($data['keywords']) ? $data['keywords'] : explode(',', (string)$data['keywords']);
                if (session_status() === PHP_SESSION_NONE) { session_start(); }
                $taggedBy = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
                db_save_node_keywords($nodeId, $keywords, $taggedBy);
            }
            $result = ['id' => $nodeId, 'success' => true];
            if ($uploadNotice !== null) $result['notice'] = $uploadNotice;
            echo json_encode($result, JSON_THROW_ON_ERROR);
        })(),

        'PUT' => (function(): void {
            requireWriteAccess();
            $input = stream_get_contents(fopen('php://input', 'r'), 1048576);
            $data = json_decode($input, true);

            // Handle multipart/form-data for PUT (some clients use POST + _method=PUT or just POST for uploads)
            if (empty($data) && !empty($_POST)) {
                $data = $_POST;
            }

            $id = $data['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                api_error('400.009', 'Node id is required.');
                return;
            }
            $animation = (isset($data['animation'])) ? (is_array($data['animation']) ? json_encode($data['animation'], JSON_THROW_ON_ERROR) : $data['animation']) : null;
            $nodeType = sanitizeNodeType($data['node_type'] ?? 'object');
            $url = null;
            if (isset($data['url']) && !empty(trim((string)$data['url']))) {
                $url = trim((string)$data['url']);
                if (!validateSafeUrl($url)) {
                    http_response_code(400);
                    api_error('400.003', 'Invalid URL: only http and https URLs are allowed.');
                    return;
                }
            }
            $bodyConstellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : null;

            // Source-of-truth seat check: resolve the node's current galaxy and check
            // editor access against that. The body value is operator-controlled and was
            // previously trusted; using it as the gate was an IDOR.
            $currentConstellationId = db_get_node_constellation_id((int)$id);
            if ($currentConstellationId === null) {
                http_response_code(404);
                api_error('404.001', 'Node not found.');
                return;
            }
            $accessError = checkEditorConstellationAccess($currentConstellationId);
            if ($accessError !== null) {
                http_response_code(403);
                error_log('nodes.php access (source): ' . $accessError);
                api_error('403.005', 'Access denied.');
                return;
            }
            // A node living in an imported / mirrored galaxy cannot be edited.
            api_require_writable_constellation($currentConstellationId);

            // If the body requests a move into a different galaxy, seat-check the target.
            if ($bodyConstellationId !== null && $bodyConstellationId !== $currentConstellationId) {
                if (db_get_constellation_by_id($bodyConstellationId) === null) {
                    http_response_code(404);
                    api_error('404.008', 'The target galaxy does not exist.');
                    return;
                }
                $accessError = checkEditorConstellationAccess($bodyConstellationId);
                if ($accessError !== null) {
                    http_response_code(403);
                    error_log('nodes.php access (move target): ' . $accessError);
                    api_error('403.005', 'Access denied.');
                    return;
                }
                // ...and cannot be moved into an imported / mirrored galaxy.
                api_require_writable_constellation($bodyConstellationId);
            }

            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            if ($targetConstellationId !== null) {
                if (db_get_constellation_by_id($targetConstellationId) === null) {
                    http_response_code(400);
                    api_error('404.008', 'The target galaxy does not exist.');
                    return;
                }
                $accessError = checkEditorConstellationAccess($targetConstellationId);
                if ($accessError !== null) {
                    http_response_code(403);
                    error_log('nodes.php access (portal target): ' . $accessError);
                    api_error('403.005', 'Access denied.');
                    return;
                }
            }

            // Effective constellation for on-disk upload paths: prefer the move target
            // when the editor is moving the node, otherwise the current galaxy. Never
            // the body alone, so uploads cannot be redirected to an arbitrary galaxy dir.
            $effectiveConstellationId = $bodyConstellationId ?? $currentConstellationId;

            // Audit pass #5 / Race H1 (v6.10.18): per-node advisory lock around
            // the file-mutation + db_update_node block. Two concurrent PUTs on
            // the same node id were racing on the fixed-path uploads
            // (image.{ext}, video.{ext}, tmp_video_frame.{ext}) and the
            // last-writer-wins UPDATE. Holding the lock serializes mutations
            // on the same node without affecting other nodes. Lock releases
            // automatically at request end even on fatal-error paths.
            $nodeLock = db_node_lock_acquire((int)$id);
            try {
            // Downstream code passes $constellationId to db_update_node; null means
            // "don't change the column".
            $constellationId = $bodyConstellationId;

            $imageUrl = (isset($data['image_url']) && !empty(trim((string)$data['image_url']))) ? trim((string)$data['image_url']) : null;
            $embedCode = (isset($data['embed_code']) && !empty(trim((string)$data['embed_code']))) ? sanitizeEmbedCode((string)$data['embed_code']) : null;
            $audioUrl = (isset($data['audio_url']) && !empty(trim((string)$data['audio_url']))) ? trim((string)$data['audio_url']) : null;
            $audioAutoplay = isset($data['audio_autoplay']) ? (bool)$data['audio_autoplay'] : true;
            $audioLoop = isset($data['audio_loop']) ? (bool)$data['audio_loop'] : false;
            $videoUrl = (isset($data['video_url']) && !empty(trim((string)$data['video_url']))) ? trim((string)$data['video_url']) : null;
            $videoAutoplay = isset($data['video_autoplay']) ? (bool)$data['video_autoplay'] : true;
            $pdfUrl = (isset($data['pdf_url']) && !empty(trim((string)$data['pdf_url']))) ? trim((string)$data['pdf_url']) : null;
            $isAccentuated = isset($data['is_accentuated']) ? (bool)$data['is_accentuated'] : false;
            $showKeywords = isset($data['show_keywords']) ? (bool)$data['show_keywords'] : false;
            $useImageAsNode = isset($data['use_image_as_node']) ? (bool)$data['use_image_as_node'] : false;
            $iconUrl = (isset($data['icon_url']) && !empty(trim((string)$data['icon_url']))) ? trim((string)$data['icon_url']) : null;
            $imageAttribution = (isset($data['image_attribution']) && !empty(trim((string)$data['image_attribution']))) ? trim((string)$data['image_attribution']) : null;

            // File-presence flags (used by file processing block + applyVisualMutex below).
            $hasAudioFile = isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK;
            $hasVideoFile = isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK;
            $hasIconFile  = isset($_FILES['icon_file'])  && $_FILES['icon_file']['error']  === UPLOAD_ERR_OK;
            $hasPdfFile   = isset($_FILES['pdf_file'])   && $_FILES['pdf_file']['error']   === UPLOAD_ERR_OK;

            // Initial mutex on URL state; file uploads below may flip URLs and the mutex
            // re-runs after they're processed.
            applyVisualMutex($imageUrl, $videoUrl, $pdfUrl, $audioUrl);

            // Handle file uploads for PUT
            if ($effectiveConstellationId !== null) {
                $uploadDir = UPLOAD_DIR;
                $nodeRelDir = "uploads/{$effectiveConstellationId}/{$id}";
                $nodeFullDir = "{$uploadDir}/{$effectiveConstellationId}/{$id}";
                if (!is_dir($nodeFullDir)) {
                    if (!mkdir($nodeFullDir, 0755, true)) {
                        http_response_code(500);
                        api_error('500.003', 'Failed to create the upload directory. Check server permissions.');
                        return;
                    }
                }

                $uploadNotice = null;
                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['image_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                    if ($err !== null) {
                        // Check if the file is a video — extract first frame
                        $videoMime = '';
                        $videoErr = validateUploadedFile($_FILES['image_file'], FRAME_EXTRACTABLE_VIDEO_MIMES, MAX_VIDEO_BYTES, $videoMime);
                        if ($videoErr === null) {
                            $tmpVideo = $nodeFullDir . '/tmp_video_frame_' . bin2hex(random_bytes(8)) . '.' . (MIME_TO_EXT[$videoMime] ?? 'mp4');
                            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $tmpVideo)) {
                                $imageRelPath = "{$nodeRelDir}/image.jpg";
                                $imageFullPath = "{$nodeFullDir}/image.jpg";
                                if (extract_video_frame($tmpVideo, $imageFullPath)) {
                                    optimize_image($imageFullPath);
                                    $imageUrl = $imageRelPath;
                                    $uploadNotice = 'A video was uploaded as the image. The first frame was extracted and used instead.';
                                } else {
                                    @unlink($tmpVideo);
                                    http_response_code(400);
                                    api_error('500.010', 'Could not extract a frame from the uploaded video.');
                                    return;
                                }
                                @unlink($tmpVideo);
                            } else {
                                http_response_code(500);
                                api_error('500.004', 'Failed to save the uploaded file.');
                                return;
                            }
                        } else {
                            http_response_code(400);
                            error_log('nodes.php validation: ' . $err);
                        api_error('500.004', 'Failed to save the uploaded file.');
                            return;
                        }
                    } else {
                        $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                        $imageRelPath = "{$nodeRelDir}/image.{$ext}";
                        $imageFullPath = "{$nodeFullDir}/image.{$ext}";
                        if (move_uploaded_file($_FILES['image_file']['tmp_name'], $imageFullPath)) {
                            optimize_image($imageFullPath);
                            $imageUrl = $imageRelPath;
                        } else {
                            http_response_code(500);
                            api_error('500.005', 'Failed to save the uploaded image.');
                            return;
                        }
                    }
                }
                if ($hasIconFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['icon_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        error_log('nodes.php validation: ' . $err);
                        api_error('500.004', 'Failed to save the uploaded file.');
                        return;
                    }
                    $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                    $iconRelPath = "{$nodeRelDir}/icon.{$ext}";
                    $iconFullPath = "{$nodeFullDir}/icon.{$ext}";
                    if (move_uploaded_file($_FILES['icon_file']['tmp_name'], $iconFullPath)) {
                        optimize_icon($iconFullPath);
                        $iconUrl = $iconRelPath;
                    } else {
                        http_response_code(500);
                        api_error('500.006', 'Failed to save the uploaded icon.');
                        return;
                    }
                }
                if ($hasAudioFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['audio_file'], ALLOWED_AUDIO_MIMES, MAX_AUDIO_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        error_log('nodes.php validation: ' . $err);
                        api_error('500.004', 'Failed to save the uploaded file.');
                        return;
                    }
                    $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                    $audioRelPath = "{$nodeRelDir}/audio.{$ext}";
                    $audioFullPath = "{$nodeFullDir}/audio.{$ext}";
                    if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $audioFullPath)) {
                        optimize_audio($audioFullPath);
                        $audioUrl = $audioRelPath;
                        $videoUrl = null; // Enforce exclusivity
                    } else {
                        http_response_code(500);
                        api_error('500.007', 'Failed to save the uploaded audio.');
                        return;
                    }
                }
                if ($hasVideoFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['video_file'], ALLOWED_VIDEO_MIMES, MAX_VIDEO_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        error_log('nodes.php validation: ' . $err);
                        api_error('500.004', 'Failed to save the uploaded file.');
                        return;
                    }
                    $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                    $videoRelPath = "{$nodeRelDir}/video.{$ext}";
                    $videoFullPath = "{$nodeFullDir}/video.{$ext}";
                    if (move_uploaded_file($_FILES['video_file']['tmp_name'], $videoFullPath)) {
                        optimize_video($videoFullPath);
                        $videoUrl = $videoRelPath;
                        $audioUrl = null; // Enforce exclusivity
                    } else {
                        http_response_code(500);
                        api_error('500.008', 'Failed to save the uploaded video.');
                        return;
                    }
                }
                if ($hasPdfFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['pdf_file'], ALLOWED_PDF_MIMES, effectivePdfMaxBytes(), $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        error_log('nodes.php validation: ' . $err);
                        api_error('500.004', 'Failed to save the uploaded file.');
                        return;
                    }
                    if (!fileHasPdfMagic($_FILES['pdf_file']['tmp_name'])) {
                        http_response_code(400);
                        api_error('500.011', 'The file does not look like a valid PDF.');
                        return;
                    }
                    $pdfRelPath = "{$nodeRelDir}/document.pdf";
                    $pdfFullPath = "{$nodeFullDir}/document.pdf";
                    if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfFullPath)) {
                        $pdfUrl = $pdfRelPath;
                    } else {
                        http_response_code(500);
                        api_error('500.009', 'Failed to save the uploaded PDF.');
                        return;
                    }
                }
            }

            // Final mutex pass after all uploads are reflected in the URLs.
            applyVisualMutex($imageUrl, $videoUrl, $pdfUrl, $audioUrl);

            db_update_node((int)$id, $data['name'], $data['description'] ?? null, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay, $audioLoop, $showKeywords, $iconUrl, $imageAttribution, $useImageAsNode, $pdfUrl);
            if (isset($data['keywords'])) {
                $keywords = is_array($data['keywords']) ? $data['keywords'] : explode(',', (string)$data['keywords']);
                if (session_status() === PHP_SESSION_NONE) { session_start(); }
                $taggedBy = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
                db_save_node_keywords((int)$id, $keywords, $taggedBy);
            }
            $putResult = ['success' => true];
            if (isset($uploadNotice) && $uploadNotice !== null) $putResult['notice'] = $uploadNotice;
            echo json_encode($putResult, JSON_THROW_ON_ERROR);
            } finally {
                db_node_lock_release($nodeLock);
            }
        })(),

        'DELETE' => (function(): void {
            requireWriteAccess();
            $id = $_GET['id'] ?? null;
            $fileType = $_GET['file_type'] ?? null;
            if (!$id) {
                http_response_code(400);
                api_error('400.009', 'Node id is required.');
                return;
            }

            // Enforce editor constellation access on delete
            if (isEditorLoggedIn()) {
                $userId = $_SESSION['admin_user_id'] ?? null;
                if ($userId) {
                    $nodeConstellationId = db_get_node_constellation_id((int)$id);
                    if ($nodeConstellationId !== null) {
                        $allowed = db_get_constellations_for_user($userId, false);
                        $allowedIds = array_column($allowed, 'id');
                        if (!in_array($nodeConstellationId, $allowedIds, true)) {
                            http_response_code(403);
                            api_error('403.005', 'Access denied.');
                            return;
                        }
                    }
                }
            }

            // Read-only guard for all roles (the seat check above is editor-only;
            // admins skip it but still must not delete mirrored/imported content).
            $delConstellationId = db_get_node_constellation_id((int)$id);
            if ($delConstellationId !== null) {
                api_require_writable_constellation($delConstellationId);
            }

            if ($fileType && in_array($fileType, ['image', 'audio', 'video', 'icon'])) {
                db_delete_node_file((int)$id, $fileType);
                echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
                return;
            }
            db_delete_node((int)$id);
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),

        default => api_error('405.001', 'Method not allowed.')
    };
} catch (JsonException $e) {
    api_error('400.001', 'Invalid JSON: %s', [$e->getMessage()]);
} catch (PDOException $e) {
    error_log('nodes.php PDOException: ' . $e->getMessage());
    api_error('500.002', 'Database error.');
} catch (RuntimeException $e) {
    error_log('nodes.php RuntimeException: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
