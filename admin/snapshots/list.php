<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';
require_once __DIR__ . '/../../inc/cron.php';

header('Content-Type: application/json');

try {
    echo json_encode([
        'ok' => true,
        'snapshots' => snapshot_list(),
        'schedule' => snapshot_get_schedule(),
        'snapshots_dir' => backup_snapshots_dir(),
        'cron' => cron_status_summary(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
