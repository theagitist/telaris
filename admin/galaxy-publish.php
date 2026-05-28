<?php
declare(strict_types=1);

/**
 * admin/galaxy-publish.php
 *
 * POST handler for the per-galaxy "Publish now / Re-publish" action. Wraps
 * federation_galaxy_publish (5c-i) so the operator can mint a fresh
 * envelope on demand: bumps the sequence, content-addresses any local-upload
 * media, signs the envelope, caches it for peers.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/galaxy_publish.php';

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

$res = federation_galaxy_publish($cid);
if (!$res['ok']) {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_publish_err', 'Publish failed:') . ' ' . ($res['error'] ?? 'unknown');
} else {
    $_SESSION['pluriverse_apply_message'] = sprintf(
        '%s %s (seq %d, %s…)',
        t('admin_galaxy_publish_ok', 'Galaxy published:'),
        $res['slug'],
        $res['sequence'],
        substr($res['content_hash'], 0, 12)
    );
}

header('Location: index.php?tab=pluriverse');
exit;
