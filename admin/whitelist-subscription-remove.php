<?php
declare(strict_types=1);

/**
 * admin/whitelist-subscription-remove.php
 *
 * Hard-delete one subscription row. POST-only, CSRF + admin-auth gated.
 * peer_id is required (defends against bare-id POSTs hitting other peers'
 * rows). After delete, has_active_whitelist is recomputed for the peer.
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
$subId = (int)($_POST['subscription_id'] ?? 0);
if ($peerId <= 0 || $subId <= 0) {
    $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_missing_peer', 'Missing peer id.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$result = db_remove_peer_subscription($peerId, $subId);

if ($result['ok']) {
    $_SESSION['pluriverse_apply_message'] = t('admin_whitelist_subscription_remove_ok', 'Subscription removed.');
} else {
    $reason = (string)($result['reason'] ?? 'unknown');
    if ($reason === 'unknown_subscription') {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_unknown_subscription', 'That subscription no longer exists.');
    } elseif ($reason === 'peer_mismatch') {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_err_peer_mismatch', 'That subscription belongs to a different peer.');
    } else {
        $_SESSION['pluriverse_apply_error'] = t('admin_whitelist_subscription_remove_err', 'Could not remove the subscription.');
    }
}

header('Location: index.php?tab=pluriverse#whitelist-' . $peerId);
exit;
