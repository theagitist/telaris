<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

header('Content-Type: application/json');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
            $constellationId = null;
            if (isset($_GET['constellation_id'])) {
                if ($_GET['constellation_id'] === 'all') {
                    $constellationId = null;
                } elseif (is_numeric($_GET['constellation_id'])) {
                    $constellationId = (int) $_GET['constellation_id'];
                }
            }
            $connections = db_get_connections($constellationId);
            echo json_encode($connections, JSON_THROW_ON_ERROR);
        })(),
        
        default => api_error('405.001', 'Method not allowed.')
    };
} catch (JsonException $e) {
    api_error('400.001', 'Invalid JSON: %s', [$e->getMessage()]);
} catch (PDOException $e) {
    error_log('connections.php PDOException: ' . $e->getMessage());
    api_error('500.002', 'Database error.');
} catch (RuntimeException $e) {
    error_log('connections.php RuntimeException: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
