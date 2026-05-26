<?php
declare(strict_types=1);

/**
 * admin/whitelist-publish-save.php
 *
 * Replace the set of authored galaxies we are willing to publish to one
 * peer. POST-only, CSRF + admin-auth gated. Refuses mirrored galaxies
 * (not authored locally). Redirects back to ?tab=pluriverse#whitelist-<id>.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$peerId = (int)($_POST['peer_id'] ?? 0);
if ($peerId <= 0) {
    $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_missing_peer', 'Missing peer id.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$raw = $_POST['constellation_ids'] ?? [];
if (!is_array($raw)) $raw = [];
$ids = [];
foreach ($raw as $v) {
    $n = (int)$v;
    if ($n > 0) $ids[] = $n;
}

$actor = $_SESSION['admin_user_email'] ?? $_SESSION['user']['email'] ?? null;
$result = db_set_peer_publish_whitelist($peerId, $ids, is_string($actor) ? $actor : null);

if ($result['ok']) {
    $msg = sprintf(
        (string)t('admin_whitelist_publish_save_ok', 'Publish list saved (%1$d added, %2$d removed).'),
        (int)$result['added'],
        (int)$result['removed']
    );
    $_SESSION['pluriverse_apply_message'] = $msg;
} else {
    $reason = (string)($result['reason'] ?? 'unknown');
    if ($reason === 'mirrored_in_publish_set') {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_mirrored', 'Cannot publish a mirrored galaxy onward; only authored galaxies are allowed.');
    } elseif ($reason === 'unknown_peer') {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_unknown_peer', 'That peer no longer exists.');
    } else {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_publish_save_err', 'Could not save the publish list.');
    }
}

header('Location: index.php?tab=pluriverse#whitelist-' . $peerId);
exit;
