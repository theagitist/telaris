<?php
declare(strict_types=1);

/**
 * admin/peer-block.php
 *
 * POST-only handler for the operator's local "Block this peer" / "Unblock"
 * actions on the admin Pluriverse tab Local Peer List (stage 6d).
 *
 * Block is gated like manual peer entry: CSRF + a password re-auth at action
 * time (it drops every mirror the peer planted and clears the bilateral
 * publish offer, so it is destructive). Unblock is CSRF-gated only: it returns
 * the peer to 'discovered' and clears the reason, but does NOT restore dropped
 * content, so it is non-destructive.
 *
 * The DB effects live in inc/federation/peer_block.php; this handler only does
 * validation + auth + flash messaging.
 *
 * Spec: Stage 6 trust revocation design (6d).
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/peer_block.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$action = (string)($_POST['action'] ?? '');
$peerId = (int)($_POST['peer_id'] ?? 0);

if ($peerId <= 0) {
    $_SESSION['pluriverse_apply_error'] = t('admin_peer_block_err_notfound', 'That peer could not be found. Reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

if ($action === 'unblock') {
    $actor = (string)($_SESSION['admin_user_email'] ?? '');
    $res = federation_peer_unblock($peerId, $actor);
    if ($res['ok'] && $res['changed']) {
        $_SESSION['pluriverse_apply_message'] = t('admin_peer_block_unblock_ok', 'Peer unblocked and returned to discovered. Its mirrors were not restored; re-subscribe deliberately if you want its galaxies again.');
    } else {
        $_SESSION['pluriverse_apply_error'] = t('admin_peer_block_err_notfound', 'That peer could not be found. Reload the admin page and try again.');
    }
    header('Location: index.php?tab=pluriverse');
    exit;
}

if ($action !== 'block') {
    $_SESSION['pluriverse_apply_error'] = t('admin_peer_block_err_action', 'Unrecognized peer action.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

// --- block: re-auth + validate ------------------------------------------
$category = (string)($_POST['category'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$errors = [];
if (!in_array($category, FEDERATION_PEER_BLOCK_CATEGORIES, true)) {
    $errors[] = t('admin_peer_block_err_category', 'Choose a category for the block.');
}
if ($reason === '' || mb_strlen($reason) > 1024) {
    $errors[] = t('admin_peer_block_err_reason', 'A reason is required (up to 1024 characters).');
}
if ($password === '') {
    $errors[] = t('admin_peer_block_err_password_required', 'Re-enter your password to confirm.');
}
if ($errors === []) {
    $adminEmail = (string)($_SESSION['admin_user_email'] ?? '');
    if (authenticateUser($adminEmail, $password) === null) {
        $errors[] = t('admin_peer_block_err_password_wrong', 'Password does not match this admin account.');
    }
}

if ($errors !== []) {
    $_SESSION['pluriverse_apply_error'] = implode(' · ', $errors);
    header('Location: index.php?tab=pluriverse');
    exit;
}

$actor = (string)($_SESSION['admin_user_email'] ?? '');
$res = federation_peer_block($peerId, $category, $reason, $actor);
if (!$res['ok']) {
    $map = [
        'peer_not_found'   => t('admin_peer_block_err_notfound', 'That peer could not be found. Reload the admin page and try again.'),
        'invalid_category' => t('admin_peer_block_err_category', 'Choose a category for the block.'),
        'invalid_reason'   => t('admin_peer_block_err_reason', 'A reason is required (up to 1024 characters).'),
    ];
    $_SESSION['pluriverse_apply_error'] = $map[$res['error'] ?? ''] ?? t('admin_peer_block_err_action', 'Unrecognized peer action.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$_SESSION['pluriverse_apply_message'] = sprintf(
    t('admin_peer_block_ok', 'Peer blocked. %d mirror(s) dropped and any publish offer to it cleared.'),
    count($res['dropped'])
);
header('Location: index.php?tab=pluriverse');
exit;
