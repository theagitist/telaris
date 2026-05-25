<?php
declare(strict_types=1);

/**
 * admin/pluriverse-manual-peer.php
 *
 * POST-only handler for the "Add manual peer" form in the Advanced
 * disclosure of the admin Pluriverse tab.
 *
 * Manual peer entry is intentionally gated: it bypasses the
 * Pluriverse-vouched trust chain. The handler requires the operator to
 * re-enter their password at add time (separate from the session
 * cookie), records the actor + timestamp in manual_added_by /
 * manual_added_at / manual_reauth_at, and the UI surfaces a persistent
 * banner on the row reading "Manual peer added by X on Y; verify
 * intended." This row will NEVER be silently upgraded to source =
 * registry by the puller — db_get_local_peers + the puller's hostname
 * conflict check both leave manual rows alone.
 *
 * Spec: P2P federation plan v10 § Layer 2: The local peer list →
 * "Manual admin entry, last-resort under Advanced".
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$hostname = strtolower(trim((string)($_POST['hostname'] ?? '')));
$url = trim((string)($_POST['url'] ?? ''));
$publicKeyB64 = trim((string)($_POST['public_key'] ?? ''));
$label = trim((string)($_POST['label'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$errors = [];

if ($hostname === '' || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $hostname)) {
    $errors[] = t('admin_pluriverse_manual_err_hostname', 'Hostname must be a lowercase DNS name (e.g. example.org).');
}
if ($url === '' || !preg_match('#^https://#i', $url)) {
    $errors[] = t('admin_pluriverse_manual_err_url', 'URL must begin with https://.');
}
if ($label === '' || mb_strlen($label) > 255) {
    $errors[] = t('admin_pluriverse_manual_err_label', 'Name is required (1-255 characters).');
}
$publicKey = null;
if ($publicKeyB64 !== '') {
    $padded = $publicKeyB64;
    $pad = strlen($padded) % 4;
    if ($pad > 0) $padded .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
    if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        $publicKey = $decoded;
    }
}
if ($publicKey === null) {
    $errors[] = t('admin_pluriverse_manual_err_pubkey', 'Public key must be a 32-byte Ed25519 key encoded as base64url.');
}
if ($password === '') {
    $errors[] = t('admin_pluriverse_manual_err_password_required', 'Re-enter your password to confirm.');
}

if ($errors === []) {
    $adminEmail = (string)($_SESSION['admin_user_email'] ?? '');
    $user = authenticateUser($adminEmail, $password);
    if ($user === null) {
        $errors[] = t('admin_pluriverse_manual_err_password_wrong', 'Password does not match this admin account.');
    }
}

if ($errors === []) {
    try {
        db_ensure_peers_table();
        $pdo = getDB();
        $dup = $pdo->prepare("SELECT id, source FROM peers WHERE hostname = :h LIMIT 1");
        $dup->execute([':h' => $hostname]);
        $existing = $dup->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            $errors[] = sprintf(
                t('admin_pluriverse_manual_err_duplicate', 'A peer for hostname %s already exists (source: %s).'),
                $hostname,
                (string)$existing['source']
            );
        }
    } catch (Throwable $e) {
        $errors[] = 'Database error: ' . $e->getMessage();
    }
}

if ($errors !== []) {
    $_SESSION['pluriverse_apply_error'] = implode(' · ', $errors);
    header('Location: index.php?tab=pluriverse');
    exit;
}

try {
    $pdo = getDB();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO peers (
            hostname, url, pluriverse_endpoint, public_key, label,
            source, source_detail, trust_state, has_active_whitelist,
            health_status, manual_added_by, manual_added_at, manual_reauth_at
        ) VALUES (
            :h, :u, :pe, :pk, :l,
            'manual', :sd, 'discovered', FALSE,
            'unknown', :mab, :maa, :mra
        )
    ");
    $stmt->execute([
        ':h' => $hostname,
        ':u' => $url,
        ':pe' => $url,
        ':pk' => $publicKey,
        ':l' => $label,
        ':sd' => 'manual entry',
        ':mab' => (string)($_SESSION['admin_user_email'] ?? 'unknown'),
        ':maa' => $now,
        ':mra' => $now,
    ]);
    $_SESSION['pluriverse_apply_message'] = sprintf(
        t('admin_pluriverse_manual_added', 'Manual peer %s added. Treat it as not Pluriverse-vouched until you confirm with the operator out of band.'),
        $hostname
    );
} catch (Throwable $e) {
    $_SESSION['pluriverse_apply_error'] = 'Insert failed: ' . $e->getMessage();
}

header('Location: index.php?tab=pluriverse');
exit;
