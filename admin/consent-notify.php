<?php
declare(strict_types=1);

/**
 * admin/consent-notify.php
 *
 * POST-only handler for the persistent "documents changed" alert on the admin
 * console. Two actions:
 *   action=send       email every editor who owes the pending document
 *                     version(s), record the notifications + a 'sent' decision
 *                     (clears the alert).
 *   action=disregard  clear the alert WITHOUT emailing anyone. Deliberately
 *                     high-friction: the operator must type the localized
 *                     confirmation phrase (consent_notify_phrase_matches),
 *                     not just click. A wrong phrase leaves the alert active.
 *
 * Sets a flash and redirects back to the console. Editors are still prompted
 * at the gate on next sign-in regardless of which action is taken.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/consent_notify.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['consent_notify_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php');
    exit;
}

$loc = consent_notify_current_locale();
$adminId = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
$action = (string)($_POST['action'] ?? '');

if ($action === 'send') {
    $r = consent_notify_send($adminId);
    if ($r['resolved'] && $r['sent'] > 0) {
        $_SESSION['consent_notify_message'] = consent_notify_t('flash_sent', $loc) . ' (' . $r['sent'] . ')';
    } elseif ($r['resolved']) {
        $_SESSION['consent_notify_message'] = consent_notify_t('flash_sent_none', $loc);
    } else {
        $_SESSION['consent_notify_error'] = consent_notify_t('flash_send_failed', $loc);
    }
} elseif ($action === 'disregard') {
    if (consent_notify_phrase_matches((string)($_POST['confirm_phrase'] ?? ''))) {
        consent_notify_disregard($adminId);
        $_SESSION['consent_notify_message'] = consent_notify_t('flash_disregarded', $loc);
    } else {
        $_SESSION['consent_notify_error'] = consent_notify_t('flash_phrase_bad', $loc);
    }
}

header('Location: index.php');
exit;
