<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';
require_once __DIR__ . '/../../inc/cron.php';

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

$enabled = !empty($payload['enabled']);
$hour = isset($payload['hour']) && $payload['hour'] !== '' ? (int)$payload['hour'] : 3;
$keepDays = (int)($payload['keep_days'] ?? 7);

try {
    snapshot_set_schedule($enabled, $hour, $keepDays);
    // Mirror the enabled flag to the system cron — user never sees this detail.
    $cronWarning = null;
    try {
        if ($enabled) {
            cron_install();
        } else {
            cron_uninstall();
        }
    } catch (Throwable $ce) {
        $cronWarning = $ce->getMessage();
    }
    echo json_encode([
        'ok' => true,
        'schedule' => snapshot_get_schedule(),
        'cron' => cron_status_summary(),
        'warning' => $cronWarning,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
