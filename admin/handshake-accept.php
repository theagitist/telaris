<?php
declare(strict_types=1);

/**
 * admin/handshake-accept.php
 *
 * POST-only handler for the "Accept" button on an inbound pending
 * handshake row. Calls handshake_accept_inbound() which builds the
 * round-2 envelope, queues an outbound message to the remote, and
 * transitions the row to accepted_awaiting_complete.
 *
 * Redirects back to admin?tab=pluriverse.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/handshake.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$id = (int)($_POST['handshake_id'] ?? 0);
if ($id <= 0) {
    $_SESSION['pluriverse_apply_error'] = t('admin_handshake_err_missing_id', 'Missing handshake id.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$result = handshake_accept_inbound($id);
if ($result['ok']) {
    $_SESSION['pluriverse_apply_message'] = t('admin_handshake_accept_ok', 'Handshake accepted; reply queued for the next dispatcher tick.');
} else {
    $reason = (string)$result['reason'];
    $msg = t('admin_handshake_accept_err', 'Could not accept the handshake:') . ' ' . $reason;
    if ($reason === 'peer_not_in_directory') {
        $msg = t('admin_handshake_err_peer_not_in_directory', 'The remote instance is not in the Pluriverse directory yet. Wait for the next peer pull (or click Refresh now) and try again.');
    }
    $_SESSION['pluriverse_apply_error'] = $msg;
}

header('Location: index.php?tab=pluriverse');
exit;
