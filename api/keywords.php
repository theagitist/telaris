<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// Require API key authentication
requireApiKey();

$method = $_SERVER['REQUEST_METHOD'];

try {
    match ($method) {
        'GET' => (function(): void {
            $nodeId = isset($_GET['node_id']) ? (int)$_GET['node_id'] : null;
            $keywords = db_get_keywords($nodeId > 0 ? $nodeId : null);
            echo json_encode($keywords, JSON_THROW_ON_ERROR);
        })(),
        
        'POST' => (function(): void {
            requireWriteAccess();
            $data = json_decode(stream_get_contents(fopen('php://input', 'r'), 1048576), true, flags: JSON_THROW_ON_ERROR);
            $keyword = trim($data['keyword'] ?? '');
            if (empty($keyword)) {
                http_response_code(400);
                echo json_encode(['error' => 'Keyword required'], JSON_THROW_ON_ERROR);
                return;
            }
            $keywordId = db_create_keyword($keyword);
            echo json_encode(['id' => $keywordId, 'keyword' => $keyword, 'success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'DELETE' => (function(): void {
            requireWriteAccess();
            $id = $_GET['id'] ?? null;
            $constellationId = $_GET['constellation_id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Keyword ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            if ($constellationId === null || !ctype_digit((string)$constellationId)) {
                http_response_code(400);
                echo json_encode(['error' => 'constellation_id required'], JSON_THROW_ON_ERROR);
                return;
            }
            $actualConstellationId = db_get_keyword_constellation_id((int)$id);
            if ($actualConstellationId === null || $actualConstellationId !== (int)$constellationId) {
                http_response_code(403);
                echo json_encode(['error' => 'Keyword does not belong to the specified constellation'], JSON_THROW_ON_ERROR);
                return;
            }
            db_delete_keyword((int)$id);
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        default => throw new RuntimeException('Method not allowed', 405)
    };
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('keywords.php PDOException: ' . $e->getMessage());
    echo json_encode(['error' => 'Database error'], JSON_THROW_ON_ERROR);
} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 405);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
