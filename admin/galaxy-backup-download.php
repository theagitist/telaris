<?php
declare(strict_types=1);

/**
 * admin/galaxy-backup-download.php
 *
 * Stream the full-fidelity .telaris-backup for one authored galaxy. Peers
 * mirror the lossy signed envelope (relational content + media refs); this
 * is the operator's offline archive: all node fields, embed codes,
 * animations, embedded media bytes. Not a federation primitive.
 *
 * Reuses backup_build_dump (the same engine that powers the admin Download
 * Backup action) with galaxy_ids scoped to the single constellation, and
 * include_users=false (federation content only). The stream is the gzipped
 * JSON dump.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$cid = (int)($_POST['constellation_id'] ?? 0);
if ($cid <= 0) {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_publish_err_missing', 'Missing or invalid galaxy reference.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

// Pull the slug for the download filename. The constellation must be
// authored (not a mirror); the operator UI only surfaces authored galaxies
// for this action, but defence-in-depth here.
$row = getDB()->prepare("SELECT slug, import_source FROM constellations WHERE id = :id LIMIT 1");
$row->execute([':id' => $cid]);
$r = $row->fetch(PDO::FETCH_ASSOC);
if ($r === false || !empty($r['import_source'])) {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_backup_err_not_authored', 'This galaxy cannot be exported: not an authored galaxy.');
    header('Location: index.php?tab=pluriverse');
    exit;
}
$slug = (string)$r['slug'] !== '' ? (string)$r['slug'] : ('galaxy-' . $cid);

try {
    $dump = backup_build_dump([
        'galaxy_ids' => [$cid],
        'include_galaxies' => true,
        'include_users' => false,
        'media_mode' => 'embedded',
    ]);
} catch (Throwable $e) {
    error_log('admin/galaxy-backup-download: ' . $e->getMessage());
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_backup_err', 'Backup build failed:') . ' ' . $e->getMessage();
    header('Location: index.php?tab=pluriverse');
    exit;
}

$json = json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
unset($dump);
$gz = gzencode($json, 6);
unset($json);
if ($gz === false) {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_backup_err', 'Backup build failed:') . ' gzip failed';
    header('Location: index.php?tab=pluriverse');
    exit;
}

$filenameSafe = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)) ?? 'galaxy';
$filename = "telaris-{$filenameSafe}-" . gmdate('Ymd-His') . '.telaris-backup';

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($gz));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $gz;
exit;
