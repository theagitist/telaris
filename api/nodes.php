<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';

// Set CORS headers for API responses
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-HTTP-Method-Override');
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Require API key authentication
requireApiKey();

/** Allowed node_type values (must match nodes.node_type ENUM). */
const NODE_TYPE_VALUES = ['object', 'portal'];

/**
 * Sanitize node_type from request data; return one of NODE_TYPE_VALUES or 'object'.
 */
function sanitizeNodeType(mixed $value): string {
    $s = is_string($value) ? trim($value) : '';
    return in_array($s, NODE_TYPE_VALUES, true) ? $s : 'object';
}

/**
 * Parse target_constellation_id from request data as nullable integer.
 */
function parseTargetConstellationId(mixed $value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $id = (int)$value;
        return $id >= 0 ? $id : null;
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'];
file_put_contents(__DIR__ . '/../debug_upload.log', "--- " . date('Y-m-d H:i:s') . " ---\nMETHOD: $method\nPOST: " . print_r($_POST, true) . "\nFILES: " . print_r($_FILES, true) . "\nHEADERS: " . print_r(getallheaders(), true) . "\n", FILE_APPEND);
if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) === 'PUT') {
    $method = 'PUT';
}

try {
    match ($method) {
        'GET' => (function(): void {
            $currentUserId = $_SESSION['admin_user_id'] ?? null;
            $isAdmin = isAdminLoggedIn();

            $constellationId = null;
            if (isset($_GET['constellation_id'])) {
                if ($_GET['constellation_id'] === 'all') {
                    $constellationId = null; // all nodes (respecting user access)
                } elseif (is_numeric($_GET['constellation_id'])) {
                    $constellationId = (int) $_GET['constellation_id'];
                }
            }
            if ($constellationId === null && !isset($_GET['constellation_id'])) {
                $constellationId = db_get_default_constellation_id(); // main view without param: show default constellation only
            }
            $nodes = db_get_nodes($constellationId, $currentUserId, $isAdmin);
            $formatted = array_map(fn($node) => db_format_node($node), $nodes);
            echo json_encode($formatted, JSON_THROW_ON_ERROR);
        })(),
        
        'POST' => (function(): void {
            $data = $_POST;
            if (empty($data) && empty($_FILES)) {
                $input = file_get_contents('php://input');
                if (!empty($input)) {
                    $data = json_decode($input, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
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
                    'theta' => isset($animationData['theta']) ? (float)$animationData['theta'] : (rand(0, 628) / 100),
                    'phi' => isset($animationData['phi']) ? (float)$animationData['phi'] : (rand(0, 314) / 100),
                    'speed' => isset($animationData['speed']) ? (float)$animationData['speed'] : (0.002 + (rand(0, 4) / 1000)),
                    'phase' => isset($animationData['phase']) ? (float)$animationData['phase'] : (rand(0, 628) / 100)
                ];
                $animation = json_encode($animationArray, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to encode animation data: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
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
                if ($nodeType !== 'portal' && !filter_var($url, FILTER_VALIDATE_URL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL format'], JSON_THROW_ON_ERROR);
                    return;
                }
                if ($nodeType === 'portal' && !filter_var($url, FILTER_VALIDATE_URL)) {
                    $url = null;
                }
            }
            $name = trim((string)$data['name']);
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name cannot be empty'], JSON_THROW_ON_ERROR);
                return;
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : db_get_default_constellation_id();
            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            $imageUrl = (isset($data['image_url']) && !empty(trim((string)$data['image_url']))) ? trim((string)$data['image_url']) : null;
            $embedCode = (isset($data['embed_code']) && !empty(trim((string)$data['embed_code']))) ? trim((string)$data['embed_code']) : null;
            $audioUrl = (isset($data['audio_url']) && !empty(trim((string)$data['audio_url']))) ? trim((string)$data['audio_url']) : null;
            $audioAutoplay = isset($data['audio_autoplay']) ? (bool)$data['audio_autoplay'] : true;
            $isAccentuated = isset($data['is_accentuated']) ? (bool)$data['is_accentuated'] : false;
            
            $nodeId = db_create_node($name, $description, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated);
            if ($nodeId === 0) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create node: Could not retrieve node ID'], JSON_THROW_ON_ERROR);
                return;
            }

            $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
            $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
            $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
            if (!is_dir($nodeFullDir)) {
                if (!mkdir($nodeFullDir, 0755, true)) {
                    http_response_code(500);
                    echo json_encode(['error' => "Failed to create directory: {$nodeFullDir}. Check permissions."], JSON_THROW_ON_ERROR);
                    return;
                }
            }

            $uploadedFiles = false;
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
                $imageRelPath = "{$nodeRelDir}/image.{$ext}";
                $imageFullPath = "{$nodeFullDir}/image.{$ext}";
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $imageFullPath)) {
                    $imageUrl = $imageRelPath;
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => "Failed to move uploaded image to: {$imageFullPath}"], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION);
                $audioRelPath = "{$nodeRelDir}/audio.{$ext}";
                $audioFullPath = "{$nodeFullDir}/audio.{$ext}";
                if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $audioFullPath)) {
                    $audioUrl = $audioRelPath;
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => "Failed to move uploaded audio to: {$audioFullPath}"], JSON_THROW_ON_ERROR);
                    return;
                }
            }

            if ($uploadedFiles) {
                db_update_node($nodeId, $name, $description, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated);
            }

            if (isset($data['keywords'])) {
                $keywords = is_array($data['keywords']) ? $data['keywords'] : explode(',', (string)$data['keywords']);
                try {
                    db_save_node_keywords($nodeId, $keywords);
                } catch (Exception $e) {
                }
            }
            echo json_encode(['id' => $nodeId, 'success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'PUT' => (function(): void {
            $input = file_get_contents('php://input');
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
                if ($nodeType !== 'portal' && !filter_var($url, FILTER_VALIDATE_URL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL format'], JSON_THROW_ON_ERROR);
                    return;
                }
                if ($nodeType === 'portal' && !filter_var($url, FILTER_VALIDATE_URL)) {
                    $url = null;
                }
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : null;
            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            $imageUrl = (isset($data['image_url']) && !empty(trim((string)$data['image_url']))) ? trim((string)$data['image_url']) : null;
            $embedCode = (isset($data['embed_code']) && !empty(trim((string)$data['embed_code']))) ? trim((string)$data['embed_code']) : null;
            $audioUrl = (isset($data['audio_url']) && !empty(trim((string)$data['audio_url']))) ? trim((string)$data['audio_url']) : null;
            $audioAutoplay = isset($data['audio_autoplay']) ? (bool)$data['audio_autoplay'] : true;
            $isAccentuated = isset($data['is_accentuated']) ? (bool)$data['is_accentuated'] : false;

            // Handle file uploads for PUT
            if ($constellationId !== null) {
                $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
                $nodeRelDir = "uploads/{$constellationId}/{$id}";
                $nodeFullDir = "{$uploadDir}/{$constellationId}/{$id}";
                if (!is_dir($nodeFullDir)) {
                    if (!mkdir($nodeFullDir, 0755, true)) {
                        http_response_code(500);
                        echo json_encode(['error' => "Failed to create directory: {$nodeFullDir}. Check permissions."], JSON_THROW_ON_ERROR);
                        return;
                    }
                }

                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
                    $imageRelPath = "{$nodeRelDir}/image.{$ext}";
                    $imageFullPath = "{$nodeFullDir}/image.{$ext}";
                    if (move_uploaded_file($_FILES['image_file']['tmp_name'], $imageFullPath)) {
                        $imageUrl = $imageRelPath;
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => "Failed to move uploaded image to: {$imageFullPath}"], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION);
                    $audioRelPath = "{$nodeRelDir}/audio.{$ext}";
                    $audioFullPath = "{$nodeFullDir}/audio.{$ext}";
                    if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $audioFullPath)) {
                        $audioUrl = $audioRelPath;
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => "Failed to move uploaded audio to: {$audioFullPath}"], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
            }
            
            db_update_node((int)$id, $data['name'], $data['description'] ?? null, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated);
            if (isset($data['keywords'])) {
                $keywords = is_array($data['keywords']) ? $data['keywords'] : explode(',', (string)$data['keywords']);
                db_save_node_keywords((int)$id, $keywords);
            }
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'DELETE' => (function(): void {
            $id = $_GET['id'] ?? null;
            $fileType = $_GET['file_type'] ?? null; // 'image' or 'audio'
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            if ($fileType && in_array($fileType, ['image', 'audio'])) {
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
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 405);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
