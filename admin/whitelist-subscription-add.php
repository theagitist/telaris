<?php
declare(strict_types=1);

/**
 * admin/whitelist-subscription-add.php
 *
 * Add one remote-slug subscription for one peer. POST-only, CSRF +
 * admin-auth gated. Idempotent on (peer_id, remote_slug); a previously
 * removed subscription gets reactivated.
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
$slug = (string)($_POST['remote_slug'] ?? '');
if ($peerId <= 0) {
    $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_missing_peer', 'Missing peer id.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$actor = $_SESSION['admin_user_email'] ?? $_SESSION['user']['email'] ?? null;
$result = db_add_peer_subscription($peerId, $slug, is_string($actor) ? $actor : null);

if ($result['ok']) {
    if (($result['reason'] ?? '') === 'exists_active') {
        $_SESSION['pluriverse_apply_message'] = t('admin_whitelist_subscription_add_exists', 'That subscription is already active; nothing changed.');
    } else {
        $_SESSION['pluriverse_apply_message'] = t('admin_whitelist_subscription_add_ok', 'Subscription added.');
    }
} else {
    $reason = (string)($result['reason'] ?? 'unknown');
    if ($reason === 'invalid_slug') {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_invalid_slug', 'The remote slug is empty or too long.');
    } elseif ($reason === 'unknown_peer') {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_unknown_peer', 'That peer no longer exists.');
    } else {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_subscription_add_err', 'Could not add the subscription.');
    }
}

header('Location: index.php?tab=pluriverse#whitelist-' . $peerId);
exit;
