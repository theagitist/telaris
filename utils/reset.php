<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';

$authLocale = locale_init_strings()['__locale'] ?? 'en';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenUser = $token !== '' ? db_get_user_for_password_reset_token($token) : null;

$error = null;
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    $ip = auth_client_ip();
    $emailForRecord = $tokenUser['email'] ?? null;
    // Per-(action, IP) advisory lock closes the count → record TOCTOU
    // (M-C1, audit v6.10.11).
    $lock = db_auth_throttle_lock_acquire('reset', $ip);
    try {
        if (!$lock['acquired'] || db_count_recent_auth_attempts('reset', null, $ip, AUTH_RESET_WINDOW_SECONDS, null) >= AUTH_RESET_MAX_ATTEMPTS) {
            db_record_auth_attempt('reset', $emailForRecord, $ip, false);
            $error = t('auth_error_throttled', 'Too many attempts. Please try again later.');
        } elseif (!hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
            db_record_auth_attempt('reset', $emailForRecord, $ip, false);
            $error = t('auth_error_invalid_request', 'Invalid request. Please reload the page and try again.');
        } elseif (!$tokenUser) {
            db_record_auth_attempt('reset', $emailForRecord, $ip, false);
            $error = t('auth_reset_invalid_token_message', 'This reset link is invalid or has expired. Please request a new one.');
        } else {
            $pw1 = (string)($_POST['password'] ?? '');
            $pw2 = (string)($_POST['password_confirm'] ?? '');
            if (strlen($pw1) < 8) {
                db_record_auth_attempt('reset', $emailForRecord, $ip, false);
                $error = t('auth_reset_error_password_too_short', 'Password must be at least 8 characters.');
            } elseif ($pw1 !== $pw2) {
                db_record_auth_attempt('reset', $emailForRecord, $ip, false);
                $error = t('auth_reset_error_password_mismatch', 'Passwords do not match.');
            } else {
                $hash = password_hash($pw1, PASSWORD_DEFAULT);
                $ok = db_consume_password_reset_token($token, $hash);
                if (!$ok) {
                    db_record_auth_attempt('reset', $emailForRecord, $ip, false);
                    $error = t('auth_reset_invalid_token_message', 'This reset link is invalid or has expired. Please request a new one.');
                } else {
                    db_record_auth_attempt('reset', $emailForRecord, $ip, true);
                    db_audit_log(
                        action: 'password.reset.consumed',
                        actorUserId: (string)($tokenUser['id'] ?? '') ?: null,
                        targetType: 'user',
                        targetId: (string)($tokenUser['id'] ?? '') ?: null,
                        ip: $ip,
                        actorEmail: $emailForRecord,
                    );
                    $success = true;
                    // Audit pass #4 (M2, v6.10.17): rotate the session ID at the
                    // privilege boundary. The user just authenticated against the
                    // reset token; if the pre-reset session was hijacked
                    // (session-fixation, eavesdropped cookie), regenerating
                    // forces the attacker's copy to invalidate. The CSRF token
                    // is rotated alongside so any captured-and-replayed form
                    // POST also fails. Multi-session invalidation (other devices
                    // signed in as this user) is a separate gap tracked in
                    // BACKLOG; this fix closes only the current-session window.
                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
            }
        }
    } finally {
        db_auth_throttle_lock_release($lock);
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($authLocale); ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?php echo t_attr('auth_reset_page_title', 'Set new password - Telaris'); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-md text-white">
        <h1 class="text-white mb-2 text-3xl font-semibold text-center"><?php echo t_attr('auth_reset_heading', 'Set new password'); ?></h1>

        <?php if ($success): ?>
            <p class="text-emerald-300 my-6 text-center text-sm"><?php echo t_attr('auth_reset_success_message', 'Password updated. You can now log in with your new password.'); ?></p>
            <a href="login.php" class="btn btn-neutral w-full"><?php echo t_attr('auth_reset_btn_go_to_login', 'Go to login'); ?></a>
        <?php elseif (!$tokenUser): ?>
            <p class="text-red-300 my-6 text-center text-sm"><?php echo t_attr('auth_reset_invalid_token_message', 'This reset link is invalid or has expired. Please request a new one.'); ?></p>
            <a href="forgot.php" class="btn btn-neutral w-full"><?php echo t_attr('auth_reset_btn_request_new_link', 'Request a new link'); ?></a>
        <?php else: ?>
            <p class="text-gray-400 mb-6 text-center text-sm"><?php echo sprintf(t('auth_reset_intro_html', 'Setting a new password for <strong>%s</strong>.'), '<strong class="text-white">' . htmlspecialchars((string)$tokenUser['email']) . '</strong>'); ?></p>

            <?php if ($error): ?>
                <div class="bg-red-900/30 border border-red-800 text-red-200 p-4 rounded mb-5 text-sm">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="mb-5">
                    <label for="password" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('auth_reset_new_password_label', 'New password'); ?></label>
                    <input type="password" id="password" name="password" required minlength="8" autofocus autocomplete="new-password"
                           class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
                    <span class="text-xs text-gray-500 mt-1 block"><?php echo t_attr('auth_reset_password_help', 'At least 8 characters.'); ?></span>
                </div>
                <div class="mb-6">
                    <label for="password_confirm" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('auth_reset_confirm_password_label', 'Confirm new password'); ?></label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"
                           class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
                </div>
                <button type="submit" class="btn btn-neutral w-full"><?php echo t_attr('auth_reset_submit', 'Update password'); ?></button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
