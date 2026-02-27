<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

requireApiKey();

$method = $_SERVER['REQUEST_METHOD'];

try {
    match ($method) {
        'GET' => (function(): void {
            if (isset($_GET['action']) && $_GET['action'] === 'impact' && isset($_GET['id'])) {
                $id = (int)$_GET['id'];
                $portals = db_get_referencing_portals($id);
                echo json_encode([
                    'referencing_portals' => array_map(fn($p) => [
                        'id' => (int)$p['id'],
                        'name' => $p['name'],
                        'constellation_id' => (int)$p['constellation_id'],
                        'constellation_name' => $p['constellation_name']
                    ], $portals)
                ], JSON_THROW_ON_ERROR);
                return;
            }
            $list = db_get_constellations();
            $out = array_map(fn(array $row) => [
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'tagline' => (string)($row['tagline'] ?? ''),
                'theme' => (string)($row['theme'] ?? 'cosmic'),
            ], $list);
            echo json_encode($out, JSON_THROW_ON_ERROR);
        })(),

        'POST' => (function(): void {
            requireWriteAccess();
            $input = file_get_contents('php://input');
            if ($input === '' || $input === false) {
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
            $name = isset($data['name']) ? trim((string)$data['name']) : '';
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Constellation name is required'], JSON_THROW_ON_ERROR);
                return;
            }
            $tagline = isset($data['tagline']) ? trim((string)$data['tagline']) : '';
            $allowedThemes = ['cosmic', 'abstract', 'rectangles', 'stripes', 'tech'];
            $theme = isset($data['theme']) ? trim((string)$data['theme']) : 'cosmic';
            if (!in_array($theme, $allowedThemes, true)) {
                $theme = 'cosmic';
            }
            $id = db_create_constellation($name, $tagline, null, $theme);
            echo json_encode([
                'id' => $id,
                'name' => $name,
                'tagline' => $tagline,
                'theme' => $theme,
            ], JSON_THROW_ON_ERROR);
        })(),

        default => (function(): void {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed'], JSON_THROW_ON_ERROR);
        })(),
    };
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
