<?php
declare(strict_types=1);

require_once '../config.php';
require_once 'auth.php';

// Set CORS headers for API responses
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Require API key authentication
requireApiKey();

$method = $_SERVER['REQUEST_METHOD'];

try {
    match ($method) {
        'GET' => (function(): void {
            $constellationId = null;
            if (isset($_GET['constellation_id'])) {
                if ($_GET['constellation_id'] === 'all') {
                    $constellationId = null; // all nodes (e.g. for Edit page)
                } elseif (is_numeric($_GET['constellation_id'])) {
                    $constellationId = (int) $_GET['constellation_id'];
                }
            }
            if ($constellationId === null && !isset($_GET['constellation_id'])) {
                $constellationId = DEFAULT_CONSTELLATION_ID; // main view without param: show default constellation only
            }
            $nodes = db_get_nodes($constellationId);
            $formatted = array_map(fn($node) => db_format_node($node), $nodes);
            echo json_encode($formatted, JSON_THROW_ON_ERROR);
        })(),
        
        'POST' => (function(): void {
            $input = file_get_contents('php://input');
            if (empty($input)) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is empty'], JSON_THROW_ON_ERROR);
                return;
            }
            $data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_THROW_ON_ERROR);
                return;
            }
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name is required'], JSON_THROW_ON_ERROR);
                return;
            }
            try {
                $animationData = (isset($data['animation']) && is_array($data['animation'])) ? $data['animation'] : [];
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
            $url = null;
            if (isset($data['url']) && !empty(trim((string)$data['url']))) {
                $url = trim((string)$data['url']);
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL format'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            $name = trim((string)$data['name']);
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name cannot be empty'], JSON_THROW_ON_ERROR);
                return;
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : DEFAULT_CONSTELLATION_ID;
            $nodeId = db_create_node($name, $description, $url, $animation, $constellationId);
            if ($nodeId === 0) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create node: Could not retrieve node ID'], JSON_THROW_ON_ERROR);
                return;
            }
            if (isset($data['keywords']) && is_array($data['keywords']) && !empty($data['keywords'])) {
                try {
                    db_save_node_keywords($nodeId, $data['keywords']);
                } catch (Exception $e) {
                    error_log("Error saving keywords for node {$nodeId}: " . $e->getMessage());
                }
            }
            echo json_encode(['id' => $nodeId, 'success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'PUT' => (function(): void {
            $data = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
            $id = $data['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            $animation = json_encode($data['animation'], JSON_THROW_ON_ERROR);
            $url = null;
            if (isset($data['url']) && !empty(trim((string)$data['url']))) {
                $url = trim((string)$data['url']);
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL format'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : null;
            db_update_node((int)$id, $data['name'], $data['description'] ?? null, $url, $animation, $constellationId);
            if (isset($data['keywords']) && is_array($data['keywords'])) {
                db_save_node_keywords((int)$id, $data['keywords']);
            }
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'DELETE' => (function(): void {
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
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
