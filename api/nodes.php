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

/**
 * Save keywords for a node using MySQL 8 features
 */
function saveNodeKeywords(PDO $pdo, int $nodeId, array $keywords): void {
    // Remove existing keywords for this node
    $deleteStmt = $pdo->prepare("DELETE FROM node_keywords WHERE node_id = :node_id");
    $deleteStmt->execute([':node_id' => $nodeId]);
    
    if (empty($keywords)) {
        return;
    }
    
    // Insert keywords using MySQL 8 INSERT ... ON DUPLICATE KEY UPDATE
    $keywordStmt = $pdo->prepare("
        INSERT INTO keywords (keyword) 
        VALUES (:keyword)
        ON DUPLICATE KEY UPDATE id=id
    ");
    
    $nodeKeywordStmt = $pdo->prepare("
        INSERT INTO node_keywords (node_id, keyword_id)
        VALUES (:node_id, :keyword_id)
        ON DUPLICATE KEY UPDATE node_id=node_id, keyword_id=keyword_id
    ");
    
    foreach ($keywords as $keyword) {
        $keyword = trim($keyword);
        if (empty($keyword)) continue;
        
        try {
            // Insert or get keyword ID
            $keywordStmt->execute([':keyword' => $keyword]);
            
            // Get the keyword ID - if it was a duplicate, we need to query for it
            $keywordId = (int)$pdo->lastInsertId();
            if ($keywordId === 0) {
                // Keyword already existed, get its ID
                $getIdStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword LIMIT 1");
                $getIdStmt->execute([':keyword' => $keyword]);
                $result = $getIdStmt->fetch();
                $keywordId = $result ? (int)$result['id'] : 0;
            }
            
            if ($keywordId > 0) {
                // Link keyword to node
                $nodeKeywordStmt->execute([
                    ':node_id' => $nodeId,
                    ':keyword_id' => $keywordId
                ]);
            }
        } catch (PDOException $e) {
            // Log error but continue with other keywords
            error_log("Error saving keyword '{$keyword}' for node {$nodeId}: " . $e->getMessage());
        }
    }
}

/**
 * Format node data for JSON response
 */
function formatNode(array $node, PDO $pdo): array {
    // Get keywords for this node using CTE (MySQL 8 feature)
    $keywordStmt = $pdo->prepare("
        WITH node_keywords_cte AS (
            SELECT k.keyword 
            FROM keywords k
            JOIN node_keywords nk ON k.id = nk.keyword_id
            WHERE nk.node_id = :node_id
        )
        SELECT keyword FROM node_keywords_cte ORDER BY keyword
    ");
    $keywordStmt->execute([':node_id' => (int)$node['id']]);
    $keywords = $keywordStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Parse JSON columns directly
    $animation = json_decode($node['animation'], true, flags: JSON_THROW_ON_ERROR);
    
    return [
        'id' => (int)$node['id'],
        'name' => $node['name'],
        'description' => $node['description'] ?? null,
        'url' => $node['url'] ?? null,
        'keywords' => $keywords,
        'animation' => $animation
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getDB();

try {
    match ($method) {
        'GET' => (function() use ($pdo): void {
            // Get all nodes with their keywords using MySQL 8 JSON columns
            $stmt = $pdo->query("
                SELECT id, name, description, url, animation
                FROM nodes 
                ORDER BY id
            ");
            $nodes = $stmt->fetchAll();
            
            $formatted = array_map(fn($node) => formatNode($node, $pdo), $nodes);
            
            echo json_encode($formatted, JSON_THROW_ON_ERROR);
        })(),
        
        'POST' => (function() use ($pdo): void {
            // Create new node
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
            
            // Validate required fields
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name is required'], JSON_THROW_ON_ERROR);
                return;
            }
            
            // Prepare JSON objects for MySQL 8 - use provided values or generate defaults
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
            
            // Ensure animation is a string (JSON string)
            if (!is_string($animation)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to encode JSON data'], JSON_THROW_ON_ERROR);
                return;
            }
            
            // Prepare description - ensure it's either a string or null
            $description = null;
            if (isset($data['description']) && !empty(trim($data['description']))) {
                $description = trim($data['description']);
            }
            
            // Prepare URL - ensure it's either a string or null
            $url = null;
            if (isset($data['url']) && !empty(trim($data['url']))) {
                $url = trim($data['url']);
                // Basic URL validation
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL format'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            
            // Prepare name - ensure it's not empty
            $name = trim($data['name']);
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name cannot be empty'], JSON_THROW_ON_ERROR);
                return;
            }
            
            // Final validation - ensure all required values are set
            if (empty($name) || !is_string($animation)) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Invalid data for node creation',
                    'details' => [
                        'name_empty' => empty($name),
                        'animation_is_string' => is_string($animation)
                    ]
                ], JSON_THROW_ON_ERROR);
                return;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO nodes (name, description, url, animation)
                VALUES (:name, :description, :url, :animation)
            ");
            
            $executeParams = [
                ':name' => $name,
                ':description' => $description,
                ':url' => $url,
                ':animation' => $animation
            ];
            
            $stmt->execute($executeParams);
            
            $nodeId = (int)$pdo->lastInsertId();
            
            if ($nodeId === 0) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create node: Could not retrieve node ID'], JSON_THROW_ON_ERROR);
                return;
            }
            
            // Handle keywords if provided (non-critical, don't fail node creation if keywords fail)
            if (isset($data['keywords']) && is_array($data['keywords']) && !empty($data['keywords'])) {
                try {
                    saveNodeKeywords($pdo, $nodeId, $data['keywords']);
                } catch (Exception $e) {
                    // Log error but don't fail node creation
                    error_log("Error saving keywords for node {$nodeId}: " . $e->getMessage());
                }
            }
            
            echo json_encode(['id' => $nodeId, 'success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'PUT' => (function() use ($pdo): void {
            // Update node
            $data = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
            $id = $data['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            
            // Prepare JSON objects
            $animation = json_encode($data['animation'], JSON_THROW_ON_ERROR);
            
            // Prepare URL - ensure it's either a string or null
            $url = null;
            if (isset($data['url']) && !empty(trim($data['url']))) {
                $url = trim($data['url']);
                // Basic URL validation
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL format'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            
            $stmt = $pdo->prepare("
                UPDATE nodes SET
                    name = :name,
                    description = :description,
                    url = :url,
                    animation = :animation
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $id,
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':url' => $url,
                ':animation' => $animation
            ]);
            
            // Handle keywords if provided
            if (isset($data['keywords']) && is_array($data['keywords'])) {
                saveNodeKeywords($pdo, (int)$id, $data['keywords']);
            }
            
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'DELETE' => (function() use ($pdo): void {
            // Delete node
            $id = $_GET['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            
            $stmt = $pdo->prepare("DELETE FROM nodes WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
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
