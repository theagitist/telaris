<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/clustering.php';
require_once __DIR__ . '/../inc/media-optimize.php';

// Set clustering labels from locale
$_locale = 'en';
if (isset($_GET['lang'])) {
    $_locale = strtolower(trim((string)$_GET['lang']));
} elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $_al = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    if (str_starts_with($_al, 'pt')) $_locale = 'pt';
    elseif (str_starts_with($_al, 'es')) $_locale = 'es';
}
$_localeStrings = db_get_project_info_for_locale($_locale);
clustering_set_labels(
    $_localeStrings['items_label_text'] ?? 'items',
    $_localeStrings['other_label_text'] ?? 'Other'
);

// Set CORS headers for API responses — restrict to same origin
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
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

/**
 * If the current session belongs to an editor (not admin), verify they have access
 * to the given constellation. Returns an error message or null on success.
 */
function checkEditorConstellationAccess(int $constellationId): ?string {
    if (!isEditorOrAdminLoggedIn()) {
        return null; // API-key-only callers are not session-restricted
    }
    if (isAdminLoggedIn()) {
        return null; // Admins have access to all constellations
    }
    $userId = $_SESSION['admin_user_id'] ?? null;
    if (!$userId) {
        return null;
    }
    $allowed = db_get_constellations_for_user($userId, false);
    $allowedIds = array_column($allowed, 'id');
    if (!in_array($constellationId, $allowedIds, true)) {
        return 'Access denied to this constellation';
    }
    return null;
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
                    echo json_encode(['error' => 'Node not found'], JSON_THROW_ON_ERROR);
                    return;
                }
                if (!$isAdmin && $currentUserId !== null) {
                    $allowed = db_get_constellations_for_user($currentUserId, false);
                    $allowedIds = array_column($allowed, 'id');
                    if (!in_array((int)$row['constellation_id'], $allowedIds, true)) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Access denied'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                echo json_encode(db_format_node($row), JSON_THROW_ON_ERROR);
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
                echo json_encode(['error' => 'galaxies= is incompatible with page/id'], JSON_THROW_ON_ERROR);
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
                            'mucua_name' => $node['mucua_name'] ?? null,
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
                echo json_encode(['error' => 'Invalid cluster key format'], JSON_THROW_ON_ERROR);
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

            // Set Baobáxia icon on cluster nodes for Mocambos constellations
            if ($isClustered && $constellationId !== null) {
                $importSource = db_get_constellation_import_source($constellationId);
                if ($importSource !== null) {
                    $src = json_decode($importSource, true);
                    if (is_array($src) && ($src['source'] ?? '') === 'mocambos') {
                        foreach ($formatted as &$item) {
                            if (($item['node_type'] ?? '') === 'cluster') {
                                $item['icon_url'] = 'img/baobaxia-cluster.svg';
                            }
                        }
                        unset($item);
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
                        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_THROW_ON_ERROR);
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
                    echo json_encode(['error' => 'constellation_id, keyword_id, and op (delete|move|count) are required'], JSON_THROW_ON_ERROR);
                    return;
                }
                $userId = $_SESSION['admin_user_id'] ?? null;
                if (!isAdminLoggedIn()) {
                    $allowed = $userId ? db_get_user_constellation_ids($userId) : [];
                    if (!in_array($constellationId, $allowed, true)) {
                        http_response_code(403);
                        echo json_encode(['error' => 'No access to this galaxy'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                $ids = db_get_node_ids_with_keyword($constellationId, $keywordId);
                if ($op === 'count') {
                    echo json_encode(['count' => count($ids)], JSON_THROW_ON_ERROR);
                    return;
                }
                if ($op === 'delete') {
                    foreach ($ids as $nid) {
                        try { db_delete_node($nid); } catch (Throwable $e) { /* keep going */ }
                    }
                    echo json_encode(['success' => true, 'op' => 'delete', 'affected' => count($ids)], JSON_THROW_ON_ERROR);
                    return;
                }
                // op === 'move'
                $target = (int)($data['target_constellation_id'] ?? 0);
                if ($target <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'target_constellation_id is required for move'], JSON_THROW_ON_ERROR);
                    return;
                }
                if (!isAdminLoggedIn()) {
                    $allowed = $userId ? db_get_user_constellation_ids($userId) : [];
                    if (!in_array($target, $allowed, true)) {
                        http_response_code(403);
                        echo json_encode(['error' => 'No access to the target galaxy'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                $moved = db_bulk_move_nodes_by_keyword($constellationId, $keywordId, $target);
                echo json_encode(['success' => true, 'op' => 'move', 'affected' => $moved], JSON_THROW_ON_ERROR);
                return;
            }

            // Bulk-set use_image_as_node for every node in a galaxy.
            if (($data['action'] ?? '') === 'bulk_use_image_as_node') {
                $constellationId = (int)($data['constellation_id'] ?? 0);
                if ($constellationId <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'constellation_id required'], JSON_THROW_ON_ERROR);
                    return;
                }
                $userId = $_SESSION['admin_user_id'] ?? null;
                if (!isAdminLoggedIn()) {
                    $allowed = $userId ? db_get_user_constellation_ids($userId) : [];
                    if (!in_array($constellationId, $allowed, true)) {
                        http_response_code(403);
                        echo json_encode(['error' => 'No access to this galaxy'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
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
                    echo json_encode(['error' => 'Source node not found'], JSON_THROW_ON_ERROR);
                    return;
                }
                $targetConstellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : null;
                // Check editor access on target constellation (or source if same)
                $accessCid = $targetConstellationId ?? $sourceConstellationId;
                $accessError = checkEditorConstellationAccess($accessCid);
                if ($accessError !== null) {
                    http_response_code(403);
                    echo json_encode(['error' => $accessError], JSON_THROW_ON_ERROR);
                    return;
                }
                try {
                    $newId = db_duplicate_node($sourceId, $targetConstellationId);
                    echo json_encode(['id' => $newId, 'success' => true, 'duplicated_from' => $sourceId], JSON_THROW_ON_ERROR);
                } catch (RuntimeException $e) {
                    http_response_code(500);
                    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
                }
                return;
            }

            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name is required'], JSON_THROW_ON_ERROR);
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
                echo json_encode(['error' => 'Failed to encode animation data'], JSON_THROW_ON_ERROR);
                return;
            }
            if (!is_string($animation)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to encode JSON data'], JSON_THROW_ON_ERROR);
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
                    echo json_encode(['error' => 'Invalid URL: only http and https URLs are allowed'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            $name = trim((string)$data['name']);
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name cannot be empty'], JSON_THROW_ON_ERROR);
                return;
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : db_get_default_constellation_id();

            // Enforce editor constellation access
            $accessError = checkEditorConstellationAccess($constellationId);
            if ($accessError !== null) {
                http_response_code(403);
                echo json_encode(['error' => $accessError], JSON_THROW_ON_ERROR);
                return;
            }

            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            if ($targetConstellationId !== null && db_get_constellation_by_id($targetConstellationId) === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Target constellation does not exist'], JSON_THROW_ON_ERROR);
                return;
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

            $nodeId = db_create_node($name, $description, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay, $audioLoop, $showKeywords, $iconUrl, $imageAttribution, $useImageAsNode, $pdfUrl);
            if ($nodeId === 0) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create node: Could not retrieve node ID'], JSON_THROW_ON_ERROR);
                return;
            }

            $uploadDir = UPLOAD_DIR;
            $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
            $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
            if (!is_dir($nodeFullDir)) {
                if (!mkdir($nodeFullDir, 0755, true)) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create upload directory. Check server permissions.'], JSON_THROW_ON_ERROR);
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
                        $tmpVideo = $nodeFullDir . '/tmp_video_frame.' . (MIME_TO_EXT[$videoMime] ?? 'mp4');
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
                                echo json_encode(['error' => 'Could not extract a frame from the uploaded video'], JSON_THROW_ON_ERROR);
                                return;
                            }
                            @unlink($tmpVideo);
                        } else {
                            http_response_code(500);
                            echo json_encode(['error' => 'Failed to save uploaded file'], JSON_THROW_ON_ERROR);
                            return;
                        }
                    } else {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                        echo json_encode(['error' => 'Failed to save uploaded image'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
            }
            if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['icon_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                    echo json_encode(['error' => 'Failed to save uploaded icon'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['audio_file'], ALLOWED_AUDIO_MIMES, MAX_AUDIO_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                    echo json_encode(['error' => 'Failed to save uploaded audio'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['video_file'], ALLOWED_VIDEO_MIMES, MAX_VIDEO_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                    echo json_encode(['error' => 'Failed to save uploaded video'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['pdf_file'], ALLOWED_PDF_MIMES, effectivePdfMaxBytes(), $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                    return;
                }
                if (!fileHasPdfMagic($_FILES['pdf_file']['tmp_name'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'File does not look like a valid PDF'], JSON_THROW_ON_ERROR);
                    return;
                }
                $pdfRelPath = "{$nodeRelDir}/document.pdf";
                $pdfFullPath = "{$nodeFullDir}/document.pdf";
                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfFullPath)) {
                    $pdfUrl = $pdfRelPath;
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to save uploaded PDF'], JSON_THROW_ON_ERROR);
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
                db_save_node_keywords($nodeId, $keywords);
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
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            $animation = (isset($data['animation'])) ? (is_array($data['animation']) ? json_encode($data['animation'], JSON_THROW_ON_ERROR) : $data['animation']) : null;
            $nodeType = sanitizeNodeType($data['node_type'] ?? 'object');
            $url = null;
            if (isset($data['url']) && !empty(trim((string)$data['url']))) {
                $url = trim((string)$data['url']);
                if (!validateSafeUrl($url)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL: only http and https URLs are allowed'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : null;

            // Enforce editor constellation access — use provided or look up existing
            $accessConstellationId = $constellationId;
            if ($accessConstellationId === null) {
                $accessConstellationId = db_get_node_constellation_id((int)$id);
            }
            if ($accessConstellationId !== null) {
                $accessError = checkEditorConstellationAccess($accessConstellationId);
                if ($accessError !== null) {
                    http_response_code(403);
                    echo json_encode(['error' => $accessError], JSON_THROW_ON_ERROR);
                    return;
                }
            }

            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            if ($targetConstellationId !== null && db_get_constellation_by_id($targetConstellationId) === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Target constellation does not exist'], JSON_THROW_ON_ERROR);
                return;
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

            // File-presence flags (used by file processing block + applyVisualMutex below).
            $hasAudioFile = isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK;
            $hasVideoFile = isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK;
            $hasIconFile  = isset($_FILES['icon_file'])  && $_FILES['icon_file']['error']  === UPLOAD_ERR_OK;
            $hasPdfFile   = isset($_FILES['pdf_file'])   && $_FILES['pdf_file']['error']   === UPLOAD_ERR_OK;

            // Initial mutex on URL state; file uploads below may flip URLs and the mutex
            // re-runs after they're processed.
            applyVisualMutex($imageUrl, $videoUrl, $pdfUrl, $audioUrl);

            // Handle file uploads for PUT
            if ($constellationId !== null) {
                $uploadDir = UPLOAD_DIR;
                $nodeRelDir = "uploads/{$constellationId}/{$id}";
                $nodeFullDir = "{$uploadDir}/{$constellationId}/{$id}";
                if (!is_dir($nodeFullDir)) {
                    if (!mkdir($nodeFullDir, 0755, true)) {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to create upload directory. Check server permissions.'], JSON_THROW_ON_ERROR);
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
                            $tmpVideo = $nodeFullDir . '/tmp_video_frame.' . (MIME_TO_EXT[$videoMime] ?? 'mp4');
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
                                    echo json_encode(['error' => 'Could not extract a frame from the uploaded video'], JSON_THROW_ON_ERROR);
                                    return;
                                }
                                @unlink($tmpVideo);
                            } else {
                                http_response_code(500);
                                echo json_encode(['error' => 'Failed to save uploaded file'], JSON_THROW_ON_ERROR);
                                return;
                            }
                        } else {
                            http_response_code(400);
                            echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                            echo json_encode(['error' => 'Failed to save uploaded image'], JSON_THROW_ON_ERROR);
                            return;
                        }
                    }
                }
                if ($hasIconFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['icon_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                        echo json_encode(['error' => 'Failed to save uploaded icon'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                if ($hasAudioFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['audio_file'], ALLOWED_AUDIO_MIMES, MAX_AUDIO_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                        echo json_encode(['error' => 'Failed to save uploaded audio'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                if ($hasVideoFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['video_file'], ALLOWED_VIDEO_MIMES, MAX_VIDEO_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
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
                        echo json_encode(['error' => 'Failed to save uploaded video'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                if ($hasPdfFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['pdf_file'], ALLOWED_PDF_MIMES, effectivePdfMaxBytes(), $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                        return;
                    }
                    if (!fileHasPdfMagic($_FILES['pdf_file']['tmp_name'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'File does not look like a valid PDF'], JSON_THROW_ON_ERROR);
                        return;
                    }
                    $pdfRelPath = "{$nodeRelDir}/document.pdf";
                    $pdfFullPath = "{$nodeFullDir}/document.pdf";
                    if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfFullPath)) {
                        $pdfUrl = $pdfRelPath;
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to save uploaded PDF'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
            }

            // Final mutex pass after all uploads are reflected in the URLs.
            applyVisualMutex($imageUrl, $videoUrl, $pdfUrl, $audioUrl);

            db_update_node((int)$id, $data['name'], $data['description'] ?? null, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay, $audioLoop, $showKeywords, $iconUrl, $imageAttribution, $useImageAsNode, $pdfUrl);
            if (isset($data['keywords'])) {
                $keywords = is_array($data['keywords']) ? $data['keywords'] : explode(',', (string)$data['keywords']);
                db_save_node_keywords((int)$id, $keywords);
            }
            $putResult = ['success' => true];
            if (isset($uploadNotice) && $uploadNotice !== null) $putResult['notice'] = $uploadNotice;
            echo json_encode($putResult, JSON_THROW_ON_ERROR);
        })(),

        'DELETE' => (function(): void {
            requireWriteAccess();
            $id = $_GET['id'] ?? null;
            $fileType = $_GET['file_type'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
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
                            echo json_encode(['error' => 'Access denied to this constellation'], JSON_THROW_ON_ERROR);
                            return;
                        }
                    }
                }
            }

            if ($fileType && in_array($fileType, ['image', 'audio', 'video', 'icon'])) {
                db_delete_node_file((int)$id, $fileType);
                echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
                return;
            }
            db_delete_node((int)$id);
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),

        default => throw new RuntimeException('Method not allowed', 405)
    };
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error'], JSON_THROW_ON_ERROR);
} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 405);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
