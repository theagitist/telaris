<?php
declare(strict_types=1);

/**
 * admin/handshake-reject.php
 *
 * POST-only handler for the "Reject" button on an inbound pending
 * handshake row. Builds the round-2 reject envelope and queues it for
 * the dispatcher.
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

$reason = trim((string)($_POST['reason'] ?? ''));
if ($reason === '') $reason = (string)t('admin_handshake_default_reject_reason', 'No reason provided.');

$result = handshake_reject_inbound($id, $reason);
if ($result['ok']) {
    $_SESSION['pluriverse_apply_message'] = t('admin_handshake_reject_ok', 'Handshake rejected; the remote will be notified on the next dispatcher tick.');
} else {
    $_SESSION['pluriverse_apply_error'] = t('admin_handshake_reject_err', 'Could not reject the handshake:') . ' ' . $result['reason'];
}

header('Location: index.php?tab=pluriverse');
exit;
