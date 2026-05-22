<?php
declare(strict_types=1);

/**
 * Admin endpoint: build a backup dump and stream it as a download.
 *
 * Method: POST (form submission from the admin Backup tab)
 * CSRF: validated via $_POST['csrf_token']
 * Auth: admin session only
 */

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/backup.php';
locale_init_strings();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo "405.001 " . t('api_error_405_001', 'Method not allowed.') . "\n";
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403.003 " . t('api_error_403_003', 'Invalid security token. Reload the page and try again.') . "\n";
    exit;
}

$includeGalaxies = !empty($_POST['include_galaxies']);
$includeUsers = !empty($_POST['include_users']);

if (!$includeGalaxies && !$includeUsers) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "400.043 " . t('api_error_400_043', 'Select at least galaxies or users to back up.') . "\n";
    exit;
}

$galaxyIds = [];
if ($includeGalaxies) {
    $scope = $_POST['galaxy_scope'] ?? 'all';
    if ($scope === 'selected') {
        $raw = $_POST['galaxy_ids'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_numeric($v)) $galaxyIds[] = (int)$v;
            }
        }
    }
}

$mediaMode = $_POST['media_mode'] ?? 'embedded';
if (!in_array($mediaMode, ['embedded', 'refs', 'none'], true)) $mediaMode = 'embedded';

try {
    $dump = backup_build_dump([
        'include_galaxies' => $includeGalaxies,
        'include_users' => $includeUsers,
        'galaxy_ids' => $galaxyIds,
        'media_mode' => $mediaMode,
    ]);
    $json = json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $gz = gzencode($json, 6);
    if ($gz === false) throw new RuntimeException('gzip failed');

    $stamp = gmdate('Y-m-d-His');
    $filename = "telaris-backup-{$stamp}.telaris-backup";

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($gz));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $gz;
    exit;
} catch (Throwable $e) {
    error_log('backup/export.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "500.001 " . t('api_error_500_001', 'Internal server error.') . "\n";
    exit;
}
