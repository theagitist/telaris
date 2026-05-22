<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/api-error.php';
require_once __DIR__ . '/../../inc/snapshots.php';
require_once __DIR__ . '/../../inc/cron.php';

header('Content-Type: application/json');

try {
    $schedule = snapshot_get_schedule();
    // Self-heal: if the scheduler is meant to be on but the crontab line is
    // missing (fresh deploy, manual removal, etc.), reinstall it silently.
    if (!empty($schedule['enabled']) && !cron_is_installed()) {
        try { cron_install(); } catch (Throwable $e) { /* reported via cron status */ }
    }
    echo json_encode([
        'ok' => true,
        'snapshots' => snapshot_list(),
        'schedule' => $schedule,
        'snapshots_dir' => backup_snapshots_dir(),
        'cron' => cron_status_summary(),
    ]);
} catch (Throwable $e) {
    error_log('snapshots/list.php: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
