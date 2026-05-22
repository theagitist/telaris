<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/mail.php';
require_once __DIR__ . '/../inc/db.php';

$authLocale = locale_init_strings()['__locale'] ?? 'en';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Always render the same outcome message regardless of whether the email exists,
// to avoid leaking which addresses have accounts.
$genericNotice = t('auth_forgot_generic_notice', 'If an account exists for that email, a password reset link has been sent.');
$notice = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = t('auth_error_invalid_request', 'Invalid request. Please reload the page and try again.');
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('auth_forgot_error_invalid_email', 'Please enter a valid email address.');
        } else {
            $ip = auth_client_ip();
            // Throttle by IP (not email — that would also be an existence
            // side channel) using a null successFilter so all attempts count.
            if (db_count_recent_auth_attempts('forgot', null, $ip, AUTH_FORGOT_WINDOW_SECONDS, null) >= AUTH_FORGOT_MAX_ATTEMPTS) {
                db_record_auth_attempt('forgot', $email, $ip, false);
                // Show the same generic notice so the throttle itself doesn't
                // signal whether the address exists. The legitimate user sees
                // the success message; the attacker stops getting mail sent.
                $notice = $genericNotice;
            } else {
                $user = db_get_user_by_email($email);
            if ($user) {
                $token = db_create_password_reset_token((string)$user['id'], 86400); // 24h
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetUrl = $scheme . '://' . $host . '/utils/reset.php?token=' . urlencode($token);

                $appName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? (string)MAIL_FROM_NAME : 'Telaris';
                $name = trim(((string)($user['firstname'] ?? '')) . ' ' . ((string)($user['lastname'] ?? '')));
                $greeting = $name !== ''
                    ? sprintf(t('auth_email_greeting_named', 'Hi %s,'), htmlspecialchars($name))
                    : t('auth_email_greeting_anon', 'Hi,');

                $subject = sprintf(t('auth_email_subject', 'Reset your %s password'), $appName);
                $html = '<p>' . $greeting . '</p>'
                      . '<p>' . t('auth_email_intro', 'We received a request to reset your password. Click the link below to set a new one:') . '</p>'
                      . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>'
                      . '<p>' . t('auth_email_expiry', 'This link expires in 24 hours and can only be used once. If you did not request a reset, you can safely ignore this email; your password will not change.') . '</p>'
                      . '<p>' . htmlspecialchars($appName) . '</p>';
                $text = t('auth_email_text_intro', "We received a request to reset your password.\n\nReset link (24h, single-use):\n")
                      . $resetUrl
                      . t('auth_email_text_outro', "\n\nIf you did not request a reset, ignore this email.");

                // Send. Failures are logged but don't change the user-facing notice
                // (we don't want to leak whether the email exists or whether mail is broken).
                @mail_send($email, $subject, $html, $text, $name !== '' ? $name : null);
            }
            db_record_auth_attempt('forgot', $email, $ip, $user !== null);
            // Show the generic notice whether or not the user existed / mail succeeded.
            $notice = $genericNotice;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($authLocale); ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?php echo t_attr('auth_forgot_page_title', 'Reset Password - Telaris'); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-md text-white">
        <h1 class="text-white mb-2 text-3xl font-semibold text-center"><?php echo t('auth_forgot_heading', 'Forgot password'); ?></h1>
        <p class="text-gray-400 mb-8 text-center"><?php echo t('auth_forgot_subtitle', 'We will email you a one-time link to set a new password.'); ?></p>

        <?php if ($error): ?>
            <div class="bg-red-900/30 border border-red-800 text-red-200 p-4 rounded mb-5 text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($notice): ?>
            <div class="bg-emerald-900/30 border border-emerald-800 text-emerald-200 p-4 rounded mb-5 text-sm">
                <?php echo htmlspecialchars($notice); ?>
            </div>
        <?php endif; ?>

        <?php if (!$notice): ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-5">
                <label for="email" class="block mb-1.5 text-gray-300 font-medium"><?php echo t('auth_email_label', 'Email'); ?></label>
                <input type="email" id="email" name="email" required autofocus
                       class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
            </div>
            <button type="submit" class="btn btn-neutral w-full"><?php echo t('auth_forgot_submit', 'Send reset link'); ?></button>
        </form>
        <?php endif; ?>

        <div class="mt-8 text-center pt-6 border-t border-gray-800 space-y-2">
            <a href="login.php" class="block text-gray-400 hover:text-white transition-colors text-sm"><?php echo t('auth_forgot_back_link', '← Back to login'); ?></a>
        </div>
    </div>
</body>
</html>
