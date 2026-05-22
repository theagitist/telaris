<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/api-error.php';
require_once __DIR__ . '/../../inc/snapshots.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('405.001', 'Method not allowed.');
}

$body = file_get_contents('php://input');
$payload = json_decode($body ?: '{}', true);
if (!is_array($payload)) $payload = [];

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf)) {
    api_error('403.003', 'Invalid security token. Reload the page and try again.');
}

$id = (int)($payload['id'] ?? 0);
if ($id <= 0) {
    api_error('400.039', 'Missing or invalid id.');
}

try {
    snapshot_delete($id);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('snapshots/delete.php: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
