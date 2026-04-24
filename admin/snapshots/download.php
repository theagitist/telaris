<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/snapshots.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "Missing id\n";
    exit;
}

$row = snapshot_get($id);
if ($row === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Snapshot not found\n";
    exit;
}

$path = rtrim(backup_snapshots_dir(), '/') . '/' . basename((string)$row['filename']);
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Snapshot file missing on disk\n";
    exit;
}

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . basename((string)$row['filename']) . '"');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
