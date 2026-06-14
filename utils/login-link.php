<?php
declare(strict_types=1);

// Magic sign-in link request (editor self-enrollment). Mirrors utils/forgot.php:
// always shows the same generic notice to avoid leaking which addresses exist,
// throttles by IP, and emails a one-time 15-minute login token when the address
// belongs to an editor or admin. Magic-link issuance is NOT gated on vetted.

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/enroll-mail.php';

$authLocale = locale_init_strings()['__locale'] ?? 'en';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$genericNotice = t('loginlink_generic_notice', 'If an account exists for that email, a sign-in link has been sent.');
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
            // Throttle by IP only (per-email would be an existence side channel),
            // null successFilter so all attempts count. Advisory lock closes the
            // count->record TOCTOU (mirrors forgot.php).
            $lock = db_auth_throttle_lock_acquire('loginlink', $ip);
            try {
                if (!$lock['acquired'] || db_count_recent_auth_attempts('loginlink', null, $ip, AUTH_LOGINLINK_WINDOW_SECONDS, null) >= AUTH_LOGINLINK_MAX_ATTEMPTS) {
                    db_record_auth_attempt('loginlink', $email, $ip, false);
                    $notice = $genericNotice;
                } else {
                    $user = db_get_user_by_email($email);
                    // Only editors and admins can sign in; only they get a link.
                    if ($user && in_array((int)($user['type'] ?? -1), [USER_TYPE_EDITOR, USER_TYPE_ADMIN], true)) {
                        $token = db_create_login_token((string)$user['id'], 'magic_login', 900); // 15 min
                        @send_magic_login_email($email, $token, $authLocale);
                    }
                    db_record_auth_attempt('loginlink', $email, $ip, $user !== null);
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
<html lang="<?php echo htmlspecialchars($authLocale); ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?php echo t_attr('loginlink_page_title', 'Email a sign-in link - Telaris'); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="/css/vendor/daisyui-4.12.10.full.min.css" rel="stylesheet" type="text/css" />
    <?php include __DIR__ . '/../inc/admin-console-theme.php'; ?>
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-md text-white">
        <h1 class="text-white mb-2 text-3xl font-semibold text-center"><?php echo t_attr('loginlink_heading', 'Email me a sign-in link'); ?></h1>
        <p class="text-gray-400 mb-8 text-center"><?php echo t_attr('loginlink_subtitle', 'We will email you a one-time link to sign in without a password.'); ?></p>

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
            <button type="submit" class="btn btn-neutral w-full"><?php echo t_attr('loginlink_submit', 'Send sign-in link'); ?></button>
        </form>
        <?php endif; ?>

        <div class="mt-8 text-center pt-6 border-t border-gray-800">
            <a href="login.php" class="block text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('auth_forgot_back_link', '← Back to login'); ?></a>
        </div>
    </div>
</body>
</html>
