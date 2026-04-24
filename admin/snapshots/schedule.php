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

$frequency = (string)($payload['frequency'] ?? 'off');
$hour = isset($payload['hour']) && $payload['hour'] !== '' && $payload['hour'] !== null ? (int)$payload['hour'] : null;
$dow = isset($payload['day_of_week']) && $payload['day_of_week'] !== '' && $payload['day_of_week'] !== null ? (int)$payload['day_of_week'] : null;
$keepLast = (int)($payload['keep_last'] ?? 10);

try {
    snapshot_set_schedule($frequency, $hour, $dow, $keepLast);
    echo json_encode(['ok' => true, 'schedule' => snapshot_get_schedule()]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
