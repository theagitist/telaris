<?php
declare(strict_types=1);

/**
 * admin/pluriverse-apply.php
 *
 * POST-only handler for the "Apply to Pluriverse" action on the admin
 * Pluriverse tab. Signs the application body with this instance's
 * pluriverse.key (Ed25519, RFC 9421) and posts it to the Pluriverse at
 * www.telaris.ca/api/pluriverse/operators/apply. Records the result in
 * pluriverse_applications. Always redirects back to admin?tab=pluriverse
 * with a flash message in the session.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/federation/identity.php';
require_once __DIR__ . '/../inc/federation/http_sig.php';

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (db_pluriverse_has_active_application()) {
    $_SESSION['pluriverse_apply_error'] = 'An application is already active for this instance. Withdraw it before submitting a new one.';
    header('Location: index.php?tab=pluriverse');
    exit;
}

// ---------------------------------------------------------------------------
// Collect form input + minimal validation. The URL, label and operator email
// are server-side facts (current host, db_get_instance_name(), session admin
// email); the form does not send them so a tampered POST cannot redirect the
// application to a different instance or claim a different operator.
// ---------------------------------------------------------------------------
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$url = $scheme . '://' . $host;
$label = db_get_instance_name();
$operatorEmail = (string)($_SESSION['admin_user_email'] ?? '');

$editorialFraming = trim((string)($_POST['editorial_framing'] ?? ''));
$contactServices = $_POST['contact_service'] ?? [];
$contactUserIds = $_POST['contact_user_id'] ?? [];

// Server-side galaxy snapshot. All current galaxies on this instance are
// published; there is no per-galaxy opt-out (anti-siloing). N=0 is allowed
// at apply time: the application registers the instance, and the
// Pluriverse will pick up new galaxies on rescan per the v10 plan.
$publishableSlugs = [];
foreach (db_get_constellations() as $g) {
    $slug = (string)($g['slug'] ?? '');
    if ($slug !== '') $publishableSlugs[] = $slug;
}

$errors = [];
if (!preg_match('#^https://#', $url)) $errors[] = 'This instance is not served over https; cannot apply.';
if ($label === '' || mb_strlen($label) > 255) $errors[] = 'Instance Name is empty; set it in Global Settings before applying.';
if (!filter_var($operatorEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Your admin account has no valid email on file.';

$otherContacts = [];
if (is_array($contactServices) && is_array($contactUserIds)) {
    $n = min(count($contactServices), count($contactUserIds));
    for ($i = 0; $i < $n; $i++) {
        $svc = trim((string)$contactServices[$i]);
        $uid = trim((string)$contactUserIds[$i]);
        if ($svc === '' || $uid === '') continue;
        $otherContacts[] = ['service' => $svc, 'user_id' => $uid];
    }
    if (count($otherContacts) > 8) {
        $errors[] = 'At most 8 secondary contacts.';
    }
}

if ($errors !== []) {
    $_SESSION['pluriverse_apply_error'] = implode(' ', $errors);
    header('Location: index.php?tab=pluriverse');
    exit;
}

$bodyArray = [
    'url' => $url,
    'operator_email' => $operatorEmail,
    'label' => $label,
    'editorial_framing' => $editorialFraming,
    'publishable_slugs' => array_values(array_map('strval', (array)$publishableSlugs)),
    'other_contacts' => $otherContacts,
    'locale' => 'en',
];
$body = json_encode($bodyArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

// ---------------------------------------------------------------------------
// Sign + post.
// ---------------------------------------------------------------------------
try {
    $secretKey = federation_load_secret_key();
    $hostname = preg_replace('#^https?://([^/:]+).*$#', '$1', $url);
    if ($hostname === null || $hostname === '') {
        throw new RuntimeException('Could not extract hostname from URL.');
    }
    $keyid = federation_keyid((string)$hostname);
    $digest = federation_http_sig_content_digest($body);
    $now = time();
    $request = [
        'method' => 'POST',
        'target_uri' => 'https://www.telaris.ca/api/pluriverse/operators/apply',
        'headers' => [
            'host' => 'www.telaris.ca',
            'date' => gmdate('D, d M Y H:i:s', $now) . ' GMT',
            'content-type' => 'application/json',
            'content-length' => (string)strlen($body),
            'content-digest' => $digest,
        ],
        'body' => $body,
    ];
    $signed = federation_http_sig_sign($request, $secretKey, [
        'keyid' => $keyid,
        'tag' => 'pluriverse-apply',
        'created' => $now,
        'expires' => $now + 60,
    ]);
} catch (Throwable $e) {
    error_log('admin/pluriverse-apply: signing failed: ' . $e->getMessage());
    $_SESSION['pluriverse_apply_error'] = 'Could not sign the application: ' . $e->getMessage()
        . ' (Is bin/init-identity already run on this host?)';
    header('Location: index.php?tab=pluriverse');
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.telaris.ca/api/pluriverse/operators/apply',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Host: www.telaris.ca',
        'Date: ' . $request['headers']['date'],
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
        'Content-Digest: ' . $digest,
        'Signature-Input: ' . $signed['signature_input'],
        'Signature: ' . $signed['signature'],
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false || $resp === null) {
    $_SESSION['pluriverse_apply_error'] = 'Network error reaching the Pluriverse: ' . $curlErr;
    header('Location: index.php?tab=pluriverse');
    exit;
}

$parsed = null;
try { $parsed = json_decode((string)$resp, true, 6, JSON_THROW_ON_ERROR); }
catch (JsonException) { /* fall through; show raw text below */ }

if ($httpCode === 201 && is_array($parsed) && ($parsed['status'] ?? '') === 'pending') {
    try {
        $id = db_record_pluriverse_application(
            $operatorEmail,
            $label,
            'https://www.telaris.ca',
            isset($parsed['instance_id']) ? (int)$parsed['instance_id'] : null,
            isset($parsed['public_key_fingerprint']) ? (string)$parsed['public_key_fingerprint'] : null
        );
    } catch (Throwable $e) {
        error_log('admin/pluriverse-apply: local record failed (remote OK): ' . $e->getMessage());
    }
    $_SESSION['pluriverse_apply_message'] = 'Application sent. Check ' . $operatorEmail . ' for a verification link.';
    header('Location: index.php?tab=pluriverse');
    exit;
}

// Non-201: surface the Problem Details detail if present.
$detail = is_array($parsed) ? (string)($parsed['detail'] ?? $resp) : (string)$resp;
$_SESSION['pluriverse_apply_error'] = 'Pluriverse refused the application (HTTP ' . $httpCode . '): ' . $detail;
header('Location: index.php?tab=pluriverse');
exit;
