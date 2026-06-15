<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../utils/auth.php';
requireEditorOrAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';
require_once __DIR__ . '/../api/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('405.001', 'Method not allowed.');
}
verify_csrf_token();

$input = file_get_contents('php://input');
$data = json_decode($input, true);
$name = isset($data['name']) ? trim((string)$data['name']) : '';
if ($name === '') {
    api_error('400.011', 'A constellation name is required.');
}

try {
    $tagline = isset($data['tagline']) ? trim((string)$data['tagline']) : '';
    $id = db_create_constellation($name, $tagline);
    // So editors see the new constellation, assign it to the current user if not admin.
    // Single-seat add preserves the user's other seats and their per-seat access
    // levels (read_only/read_write); a whole-set replace would flatten them.
    if (!isAdminLoggedIn()) {
        $userId = (string)($_SESSION['admin_user_id'] ?? '');
        if ($userId !== '') {
            db_add_user_constellation($userId, $id, 'read_write');
            // Deferred-enrolment binding: if this editor's personal galaxy was
            // deferred (user_choice naming), the first galaxy they create becomes
            // their personal one (visitor features on + per-installation cluster).
            // No-op for ordinary editors with no pending flag.
            require_once __DIR__ . '/../inc/enroll-actions.php';
            enroll_bind_deferred_personal_galaxy($userId, $id);
        }
    }
    echo json_encode(['id' => $id, 'name' => $name, 'tagline' => $tagline], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('edit/create_constellation.php: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
