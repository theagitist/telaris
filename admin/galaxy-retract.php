<?php
declare(strict_types=1);

/**
 * admin/galaxy-retract.php
 *
 * POST handler for the per-galaxy Retract action. Retraction is PERMANENT
 * and ONE-WAY: the slug is forever marked retracted, the cached envelope
 * stops being current, and subscribing peers drop their mirror on the next
 * pull cycle. To guard the action the operator must type the slug back as
 * a confirmation (HTML-form pattern; matches the strength of the irreversible
 * outcome without bouncing through a password prompt).
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/galaxy_retraction.php';

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
$confirm = trim((string)($_POST['confirm_slug'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
if ($cid <= 0) {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_publish_err_missing', 'Missing or invalid galaxy reference.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

// Verify the typed confirmation against the stored slug before passing
// anything to the retract library.
$slugStmt = getDB()->prepare("SELECT slug FROM constellations WHERE id = :id LIMIT 1");
$slugStmt->execute([':id' => $cid]);
$expectedSlug = (string)$slugStmt->fetchColumn();
if ($expectedSlug === '') {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_retract_err_not_found', 'Galaxy not found.');
    header('Location: index.php?tab=pluriverse');
    exit;
}
if ($confirm !== $expectedSlug) {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_retract_err_confirm', 'Typed confirmation did not match the slug. Retraction not performed.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$by = (string)($_SESSION['admin_user'] ?? $_SESSION['admin_email'] ?? 'admin');
$res = federation_galaxy_retract($cid, $by, $reason !== '' ? $reason : null);
if (!$res['ok']) {
    $_SESSION['pluriverse_apply_error'] = t('admin_galaxy_retract_err', 'Retract failed:') . ' ' . ($res['error'] ?? 'unknown');
} elseif (!empty($res['already_retracted'])) {
    $_SESSION['pluriverse_apply_message'] = t('admin_galaxy_retract_already', 'Slug was already retracted; envelope is intact:') . ' ' . $res['slug'];
} else {
    $_SESSION['pluriverse_apply_message'] = t('admin_galaxy_retract_ok', 'Galaxy retracted:') . ' ' . $res['slug'];
}

header('Location: index.php?tab=pluriverse');
exit;
