<?php
declare(strict_types=1);

/**
 * admin/handshake-cancel.php
 *
 * POST-only handler for the "Cancel" button on an outbound pending
 * handshake row. Local-only per v10 line 345 ("cancellation is local-only;
 * no notification to B since B may never have logged in").
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

$result = handshake_cancel_outbound($id);
if ($result['ok']) {
    $_SESSION['pluriverse_apply_message'] = t('admin_handshake_cancel_ok', 'Handshake cancelled. Any queued outbound was abandoned; the remote is not notified.');
} else {
    $_SESSION['pluriverse_apply_error'] = t('admin_handshake_cancel_err', 'Could not cancel the handshake:') . ' ' . $result['reason'];
}

header('Location: index.php?tab=pluriverse');
exit;
