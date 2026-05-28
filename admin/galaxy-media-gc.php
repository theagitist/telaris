<?php
declare(strict_types=1);

/**
 * admin/galaxy-media-gc.php
 *
 * POST handler for the "Run media GC sweep" button next to the federation
 * media store stats panel. Wraps federation_media_gc_sweep so the operator
 * can clean up orphaned blobs from the admin UI without shelling in to run
 * bin/galaxy-media-gc.
 *
 * Always runs a real sweep (not dry-run); the result is surfaced in a flash
 * message so the operator sees how many blobs were freed.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/galaxy_media_gc.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$report = federation_media_gc_sweep(false);

$diskOrphans = count($report['disk_orphans']);
$dbOrphans = count($report['db_orphans']);
$freedKb = (int)round($report['disk_orphans_freed_bytes'] / 1024);

$_SESSION['pluriverse_apply_message'] = sprintf(
    '%s %d %s · %d %s · %d KiB %s · %d %s',
    t('admin_ms_gc_ok', 'Media GC swept:'),
    $diskOrphans,
    t('admin_ms_gc_blobs', 'orphan blobs'),
    $dbOrphans,
    t('admin_ms_gc_rows', 'orphan rows'),
    $freedKb,
    t('admin_ms_gc_freed', 'freed'),
    $report['too_young'],
    t('admin_ms_gc_protected', 'protected in-flight')
);

header('Location: index.php?tab=pluriverse');
exit;
