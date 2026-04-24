<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$body = file_get_contents('php://input');
$payload = json_decode($body ?: '{}', true);
if (!is_array($payload)) $payload = [];

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
    exit;
}

$id = (int)($payload['id'] ?? 0);
$confirmText = (string)($payload['confirm_text'] ?? '');
$confirmNoAdmin = (bool)($payload['confirm_no_admin'] ?? false);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid id']);
    exit;
}
if ($confirmText !== 'RESTORE') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Confirmation phrase missing or wrong (must be RESTORE)']);
    exit;
}

try {
    $report = snapshot_restore($id, $confirmNoAdmin);
    echo json_encode(['ok' => true, 'report' => $report]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
