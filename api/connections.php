<?php
declare(strict_types=1);

require_once '../config.php';
require_once 'auth.php';

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
        
        default => throw new RuntimeException('Method not allowed. Connections are calculated automatically based on shared keywords.', 405)
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
