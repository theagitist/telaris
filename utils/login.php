<?php
declare(strict_types=1);

// Set Content-Type header to ensure proper rendering
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/session-login.php';

$authLocale = locale_init_strings()['__locale'] ?? 'en';

// Get requested redirect from URL
$requestedTarget = $_GET['redirect'] ?? $_POST['redirect'] ?? null;

// If already logged in, redirect based on role. A disabled editor (installation
// switch off, or their own switch off) is not allowed an editor session: clear
// it and show the notice below. Admins are never gated.
if (isEditorOrAdminLoggedIn()) {
    $loggedType = (int)$_SESSION['admin_user_type'];
    if ($loggedType === USER_TYPE_ADMIN || db_editor_login_allowed((string)($_SESSION['admin_user_id'] ?? ''))) {
        redirectUser($loggedType, $requestedTarget);
    }
    $_SESSION = [];
    header('Location: login.php?notice=editors_disabled');
    exit();
}

// Generate CSRF token for this session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;
// Surfaced when a disabled editor is bounced out of the editor or off a sign-in.
if (($_GET['notice'] ?? '') === 'editors_disabled') {
    $error = t('auth_editors_disabled_notice', 'Editing is currently disabled here. Please contact the operator if you think this is a mistake.');
}

// The login screen presents a single, identical layout for every email so it
// never reveals whether an account exists or whether it has a password. The
// prominent action is "Email me a sign-in link" (the only way a password-less,
// unvetted self-enrolled editor signs in); the password field lives behind a
// secondary "I have a password" disclosure, so password-less users are never
// asked for a password. Both controls are submit buttons of one <form> (with a
// native <details> toggle) so no JavaScript is needed under the strict CSP.
$prefillEmail = '';

// Whether self-enrolment is currently open (enabled and under any cap). Mirrors
// the main-view hamburger link so the login screen offers the same entry point.
$enrollOpen = db_auto_enroll_is_open();

// Magic-link sign-in: consume a one-time token and establish the session.
// Available to every editor/admin (NOT gated on vetted); it is the only way an
// unvetted, password-less self-enrolled editor signs in. finalize_user_login
// redirects and exits on success.
$magicToken = (string)($_GET['token'] ?? '');
if ($magicToken !== '' && ($_GET['purpose'] ?? '') === 'magic_login') {
    $magicUser = db_consume_login_token($magicToken, 'magic_login');
    if ($magicUser !== null && in_array((int)$magicUser['type'], [USER_TYPE_EDITOR, USER_TYPE_ADMIN], true)) {
        finalize_user_login($magicUser, $requestedTarget, $authLocale);
    }
    $error = t('loginlink_expired_error', 'That sign-in link is invalid or has expired. Request a new one below.');
}

// Password sign-in (the "I have a password" branch posts here with
// action=password). The email-link button posts to utils/login-link.php instead,
// so it never reaches this handler.
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'password') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = t('auth_error_invalid_request', 'Invalid request. Please reload the page and try again.');
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = auth_client_ip();
        $prefillEmail = $email;

        if (empty($email) || empty($password)) {
            $error = t('auth_login_error_required', 'Email and password are required');
        } else {
            // Per-(action, IP) advisory lock closes the count → record TOCTOU
            // (M-C1, audit v6.10.11). Bcrypt runs under the lock for this IP,
            // serializing same-IP parallel attempts without affecting other
            // users.
            $lock = db_auth_throttle_lock_acquire('login', $ip);
            try {
                if (!$lock['acquired'] || db_count_recent_auth_attempts('login', $email, $ip, AUTH_LOGIN_WINDOW_SECONDS, false) >= AUTH_LOGIN_MAX_FAILURES) {
                    $error = t('auth_error_throttled', 'Too many attempts. Please try again later.');
                    db_record_auth_attempt('login', $email, $ip, false);
                } else {
                    $user = authenticateUser($email, $password);

                    if ($user) {
                        db_record_auth_attempt('login', $email, $ip, true);
                        // Release the throttle lock before session work; session_regenerate_id
                        // can be slow on some setups and there's no reason to hold the gate.
                        db_auth_throttle_lock_release($lock);
                        $lock = ['acquired' => false];
                        // Establish session + first-login welcome, then redirect (shared
                        // with the magic-link path). authenticateUser already touched
                        // last_login, but the returned row carries its pre-update value,
                        // so first-login detection inside the helper is correct.
                        finalize_user_login($user, $requestedTarget, $authLocale);
                    } else {
                        db_record_auth_attempt('login', $email, $ip, false);
                        $error = t('auth_login_error_invalid', 'Invalid email or password. Only editor and admin users can login here.');
                    }
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
    <title><?php echo t_attr('auth_login_page_title', 'Login - Telaris'); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="/css/vendor/daisyui-4.12.10.full.min.css" rel="stylesheet" type="text/css" />
    <?php include __DIR__ . '/../inc/admin-console-theme.php'; ?>
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-md text-white">
        <h1 class="text-white mb-2 text-3xl font-semibold text-center"><?php echo t_attr('auth_login_heading', 'Telaris Login'); ?></h1>
        <p class="text-gray-400 mb-8 text-center"><?php echo t_attr('auth_login_subtitle', 'Access the constellation workspace'); ?></p>

        <?php if ($error): ?>
            <div class="bg-red-900/30 border border-red-800 text-red-200 p-4 rounded mb-5 text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <?php if ($requestedTarget): ?>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($requestedTarget); ?>">
            <?php endif; ?>
            <div class="mb-5">
                <label for="email" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('auth_email_label', 'Email'); ?></label>
                <input type="email"
                       id="email"
                       name="email"
                       required
                       autofocus
                       value="<?php echo htmlspecialchars($prefillEmail); ?>"
                       class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
            </div>

            <!-- Prominent, password-less path: posts the email to the magic-link
                 endpoint, which always shows a generic notice (anti-enumeration). -->
            <button type="submit" formaction="login-link.php" class="btn btn-primary w-full">
                <?php echo t_attr('auth_login_emaillink_button', 'Email me a sign-in link'); ?>
            </button>

            <!-- Secondary, opt-in password path. Collapsed by default so a
                 password-less account is never asked for a password. Native
                 <details> keeps this JS-free under the strict CSP. The password
                 field is not HTML-required so opening it never blocks the
                 email-link button above; the server validates emptiness. -->
            <details class="mt-4 group"<?php echo $prefillEmail !== '' ? ' open' : ''; ?>>
                <summary class="cursor-pointer list-none text-center text-gray-400 hover:text-white transition-colors text-sm select-none">
                    <?php echo t_attr('auth_login_have_password', 'I have a password'); ?>
                </summary>
                <div class="mt-4">
                    <label for="password" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('auth_password_label', 'Password'); ?></label>
                    <input type="password"
                           id="password"
                           name="password"
                           class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
                    <button type="submit" name="action" value="password" class="btn btn-neutral w-full mt-4">
                        <?php echo t_attr('auth_login_submit', 'Sign In'); ?>
                    </button>
                    <a href="forgot.php" class="block mt-3 text-center text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('auth_login_forgot_link', 'Forgot your password?'); ?></a>
                </div>
            </details>
        </form>

        <?php if ($enrollOpen): ?>
            <!-- Self-enrolment entry point, shown only when auto-enroll is open
                 (enabled and under any cap), below the "I have a password"
                 disclosure. Mirrors the main-view hamburger link. -->
            <div class="mt-4 text-center">
                <a href="enroll.php" class="text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('enroll_menu_link', 'Enrol as Editor'); ?></a>
            </div>
        <?php endif; ?>

        <div class="mt-8 text-center pt-6 border-t border-gray-800">
            <a href="../index.php" class="text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('auth_login_back_link', '← Back to Constellation'); ?></a>
        </div>
    </div>
</body>
</html>
