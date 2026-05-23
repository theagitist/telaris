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

$note = isset($payload['note']) && is_string($payload['note']) ? trim($payload['note']) : null;
if ($note === '') $note = null;

try {
    $id = snapshot_create($note, 'manual', $_SESSION['admin_user_id'] ?? null);
    db_audit_log(
        action: 'snapshot.create.manual',
        actorUserId: $_SESSION['admin_user_id'] ?? null,
        targetType: 'snapshot',
        targetId: (string)$id,
        details: ['note' => $note],
        ip: function_exists('auth_client_ip') ? auth_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null),
        actorEmail: $_SESSION['admin_user_email'] ?? null,
    );
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('snapshots/create.php: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
