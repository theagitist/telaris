<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

requireApiKey();

$type = $_GET['type'] ?? '';

try {
    switch ($type) {
        case 'constellation':
            $name = $_GET['name'] ?? '';
            $slug = $_GET['slug'] ?? null;
            $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;
            $exists = db_constellation_exists($name, $slug, $excludeId);
            echo json_encode($exists, JSON_THROW_ON_ERROR);
            break;

        case 'user':
            $email = $_GET['email'] ?? '';
            $excludeId = $_GET['exclude_id'] ?? null;
            $exists = db_user_email_exists($email, $excludeId);
            echo json_encode(['email' => $exists], JSON_THROW_ON_ERROR);
            break;

        case 'node':
            $name = $_GET['name'] ?? '';
            $constellationId = isset($_GET['constellation_id']) ? (int)$_GET['constellation_id'] : null;
            $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;
            
            if ($constellationId === null) {
                http_response_code(400);
                echo json_encode(['error' => 'constellation_id is required'], JSON_THROW_ON_ERROR);
                return;
            }
            
            $exists = db_node_exists($name, $constellationId, $excludeId);
            echo json_encode(['name' => $exists], JSON_THROW_ON_ERROR);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid validation type'], JSON_THROW_ON_ERROR);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('validate.php error: ' . $e->getMessage());
    echo json_encode(['error' => 'Internal server error'], JSON_THROW_ON_ERROR);
}
