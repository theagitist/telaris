<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../utils/auth.php';
requireEditorOrAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('405.001', 'Method not allowed.');
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
$name = isset($data['name']) ? trim((string)$data['name']) : '';
if ($name === '') {
    api_error('400.011', 'A constellation name is required.');
}

try {
    $tagline = isset($data['tagline']) ? trim((string)$data['tagline']) : '';
    $id = db_create_constellation($name, $tagline);
    // So editors see the new constellation, assign it to the current user if not admin
    if (!isAdminLoggedIn()) {
        $userId = (string)($_SESSION['admin_user_id'] ?? '');
        if ($userId !== '') {
            $currentIds = db_get_user_constellation_ids($userId);
            $currentIds[] = $id;
            db_set_user_constellations($userId, $currentIds);
        }
    }
    echo json_encode(['id' => $id, 'name' => $name, 'tagline' => $tagline], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('edit/create_constellation.php: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
