<?php
declare(strict_types=1);

/**
 * Install or uninstall the snapshot scheduler crontab entry for the PHP user.
 * Payload: { action: 'install' | 'uninstall', csrf_token }
 */

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
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

$action = (string)($payload['action'] ?? '');

try {
    if ($action === 'install') {
        cron_install();
    } elseif ($action === 'uninstall') {
        cron_uninstall();
    } else {
        throw new InvalidArgumentException('action must be "install" or "uninstall".');
    }
    echo json_encode(['ok' => true, 'cron' => cron_status_summary()]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
