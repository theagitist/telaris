<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/snapshots.php';
locale_init_strings();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "400.039 " . t('api_error_400_039', 'Missing or invalid id.') . "\n";
    exit;
}

$row = snapshot_get($id);
if ($row === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "404.014 " . t('api_error_404_014', 'Snapshot not found.') . "\n";
    exit;
}

// Defence in depth on top of basename(): the snapshot filename is operator-
// controlled (set when snapshot_create runs) but should always match the
// internal naming convention. Refuse anything that doesn't.
$filename = (string)$row['filename'];
if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
    error_log('snapshots/download.php: refused id ' . $id . ' with invalid filename ' . $filename);
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "404.014 " . t('api_error_404_014', 'Snapshot not found.') . "\n";
    exit;
}

$path = rtrim(backup_snapshots_dir(), '/') . '/' . basename($filename);
if (!is_file($path)) {
    error_log('snapshots/download.php: file missing for snapshot id ' . $id);
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "404.014 " . t('api_error_404_014', 'Snapshot not found.') . "\n";
    exit;
}

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
