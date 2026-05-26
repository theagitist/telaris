<?php
declare(strict_types=1);

/**
 * admin/handshake-initiate.php
 *
 * POST-only handler for the "Compose handshake_request" form on the admin
 * Pluriverse tab. Calls handshake_initiate_outbound() to build the round-1
 * envelope and queue it. The dispatcher (bin/pluriverse-dispatch, cron-driven)
 * picks it up and POSTs to the Pluriverse relay.
 *
 * Note: until the Pluriverse-side /api/pluriverse/relay endpoint ships (stage
 * 4g), the relay POST will fail. Failed rows back off per the standard
 * schedule and surface in the dispatcher's --status output; the admin
 * UI also surfaces the last_attempt_error on the outbound row.
 *
 * Sensitive-information check: the message body is scanned for high-confidence
 * secret patterns (private-key PEMs, AWS access keys, JWT-shaped strings,
 * SSH private keys, the literal text "password=", common API-key prefixes).
 * On match, the request is refused unless the form also submits the explicit
 * "send_anyway=1" override; the override is logged.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/handshake.php';
require_once __DIR__ . '/../inc/federation/sensitive_info.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$recipient = strtolower(trim((string)($_POST['recipient_hostname'] ?? '')));
$body = trim((string)($_POST['body'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$pubGalaxies = federation_handshake_parse_galaxy_list((string)($_POST['requested_publish'] ?? ''));
$subGalaxies = federation_handshake_parse_galaxy_list((string)($_POST['requested_subscribe'] ?? ''));
$sendAnyway = isset($_POST['send_anyway']) && (string)$_POST['send_anyway'] === '1';

if ($recipient === '' || !filter_var('http://' . $recipient, FILTER_VALIDATE_URL)) {
    $_SESSION['pluriverse_apply_error'] = t('admin_handshake_err_invalid_recipient', 'Recipient hostname is missing or malformed.');
    header('Location: index.php?tab=pluriverse');
    exit;
}
if ($body === '') {
    $_SESSION['pluriverse_apply_error'] = t('admin_handshake_err_body_required', 'A message body is required for a handshake request.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (!$sendAnyway) {
    $sensitive = federation_sensitive_info_scan($body);
    if ($sensitive !== []) {
        $_SESSION['pluriverse_apply_error'] = sprintf(
            (string)t('admin_handshake_err_sensitive_info', 'Your message contains content that looks like a secret (%s). Edit the message and try again, or check "Send anyway" to override.'),
            implode(', ', $sensitive),
        );
        header('Location: index.php?tab=pluriverse');
        exit;
    }
}

$result = handshake_initiate_outbound($recipient, $body, $pubGalaxies, $subGalaxies, $subject);
if ($result['ok']) {
    if ($sendAnyway) {
        federation_sensitive_info_log_override($recipient, (int)$result['handshake_id']);
    }
    $_SESSION['pluriverse_apply_message'] = t('admin_handshake_initiate_ok', 'Handshake request queued. Delivery to the Pluriverse relay happens on the next dispatcher tick.');
} else {
    $reason = (string)$result['reason'];
    $msg = t('admin_handshake_initiate_err', 'Could not queue the handshake request:') . ' ' . $reason;
    if ($reason === 'active_handshake_exists') {
        $msg = t('admin_handshake_err_active_exists', 'An active handshake to that hostname is already in flight; cancel it before initiating another.');
    }
    $_SESSION['pluriverse_apply_error'] = $msg;
}

header('Location: index.php?tab=pluriverse');
exit;

function federation_handshake_parse_galaxy_list(string $raw): array {
    $out = [];
    foreach (preg_split('/[,\r\n]+/', $raw) ?: [] as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        if (strlen($tok) > 64) continue;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $tok)) continue;
        $out[] = $tok;
    }
    return array_values(array_unique($out));
}
