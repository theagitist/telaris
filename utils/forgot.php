<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/mail.php';
require_once __DIR__ . '/../inc/email-template.php';
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
            // Per-(action, IP) advisory lock closes the count → record TOCTOU
            // (M-C1, audit v6.10.11).
            $lock = db_auth_throttle_lock_acquire('forgot', $ip);
            try {
                if (!$lock['acquired'] || db_count_recent_auth_attempts('forgot', null, $ip, AUTH_FORGOT_WINDOW_SECONDS, null) >= AUTH_FORGOT_MAX_ATTEMPTS) {
                    db_record_auth_attempt('forgot', $email, $ip, false);
                    // Show the same generic notice so the throttle itself doesn't
                    // signal whether the address exists. The legitimate user sees
                    // the success message; the attacker stops getting mail sent.
                    $notice = $genericNotice;
                } else {
                    $user = db_get_user_by_email($email);
            if ($user) {
                $token = db_create_password_reset_token((string)$user['id'], 86400); // 24h
                // Pin the host from trusted config (never the request Host
                // header, which is attacker-controllable). SITE_BASE_URL is the
                // explicit override; TELARIS_HOSTNAME is core per-instance config
                // present on every instance; the Host header is a logged last
                // resort only.
                $cfgBaseUrl = instance_setting_get('site_base_url');
                $cfgHostname = instance_setting_get('telaris_hostname');
                if ($cfgBaseUrl !== '' && preg_match('#^https?://#', $cfgBaseUrl)) {
                    $base = rtrim($cfgBaseUrl, '/');
                } elseif ($cfgHostname !== '') {
                    $base = 'https://' . rtrim($cfgHostname, '/');
                } else {
                    error_log('utils/forgot.php: neither site_base_url nor telaris_hostname is set; falling back to Host header (Host-injection risk).');
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $base = $scheme . '://' . $host;
                }
                $resetUrl = $base . '/utils/reset.php?token=' . urlencode($token);

                $appName = mail_settings_get()['from_name'] !== '' ? mail_settings_get()['from_name'] : 'Telaris';
                $name = trim(((string)($user['firstname'] ?? '')) . ' ' . ((string)($user['lastname'] ?? '')));
                // Raw (unescaped) greeting: the email shell escapes paragraph text.
                $greeting = $name !== ''
                    ? sprintf(t('auth_email_greeting_named', 'Hi %s,'), $name)
                    : t('auth_email_greeting_anon', 'Hi,');

                $subject = sprintf(t('auth_email_subject', 'Reset your %s password'), $appName);
                // On-brand shell (same format as the enrolment emails): the reset
                // link is the CTA button (and the plain-text fallback URL), so
                // recipients act on it instead of an auto-linkified bare hostname.
                $rendered = telaris_email_render([
                    'heading'    => $subject,
                    'paragraphs' => [
                        $greeting,
                        t('auth_email_intro', 'We received a request to reset your password. Click the link below to set a new one:'),
                    ],
                    'cta'        => ['label' => t('auth_email_cta', 'Reset password'), 'url' => $resetUrl],
                    'note'       => t('auth_email_expiry', 'This link expires in 24 hours and can only be used once. If you did not request a reset, you can safely ignore this email; your password will not change.'),
                    'locale'     => $authLocale,
                ]);

                // Send. Failures are logged but don't change the user-facing notice
                // (we don't want to leak whether the email exists or whether mail is broken).
                @mail_send($email, $subject, $rendered['html'], $rendered['text'], $name !== '' ? $name : null);
            }
            db_record_auth_attempt('forgot', $email, $ip, $user !== null);
            // Show the generic notice whether or not the user existed / mail succeeded.
            $notice = $genericNotice;
                }
            } finally {
                db_auth_throttle_lock_release($lock);
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
    <link href="/css/vendor/daisyui-4.12.10.full.min.css" rel="stylesheet" type="text/css" />
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-md text-white">
        <h1 class="text-white mb-2 text-3xl font-semibold text-center"><?php echo t_attr('auth_forgot_heading', 'Forgot password'); ?></h1>
        <p class="text-gray-400 mb-8 text-center"><?php echo t_attr('auth_forgot_subtitle', 'We will email you a one-time link to set a new password.'); ?></p>

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
                <label for="email" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('auth_email_label', 'Email'); ?></label>
                <input type="email" id="email" name="email" required autofocus
                       class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
            </div>
            <button type="submit" class="btn btn-neutral w-full"><?php echo t_attr('auth_forgot_submit', 'Send reset link'); ?></button>
        </form>
        <?php endif; ?>

        <div class="mt-8 text-center pt-6 border-t border-gray-800 space-y-2">
            <a href="login.php" class="block text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('auth_forgot_back_link', '← Back to login'); ?></a>
        </div>
    </div>
</body>
</html>
