<?php
declare(strict_types=1);

// Set Content-Type header to ensure proper rendering
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';

$authLocale = locale_init_strings()['__locale'] ?? 'en';

/**
 * Helper to redirect user based on their type and requested target
 */
function redirectUser(int $type, ?string $requestedTarget = null): void {
    if ($type === USER_TYPE_ADMIN) {
        // Admins can go to either admin or edit
        if ($requestedTarget === 'edit') {
            header('Location: ../edit/index.php');
        } else {
            header('Location: ../admin/index.php');
        }
    } else {
        // Everyone else (Editors) goes to edit
        header('Location: ../edit/index.php');
    }
    exit();
}

// Get requested redirect from URL
$requestedTarget = $_GET['redirect'] ?? $_POST['redirect'] ?? null;

// If already logged in, redirect based on role
if (isEditorOrAdminLoggedIn()) {
    redirectUser((int)$_SESSION['admin_user_type'], $requestedTarget);
}

// Generate CSRF token for this session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;

// Handle login form submission
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = t('auth_error_invalid_request', 'Invalid request. Please reload the page and try again.');
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = auth_client_ip();

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
                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        // Set session variables
                        $_SESSION['admin_user_id'] = $user['id'];
                        $_SESSION['admin_user_email'] = $user['email'];
                        $_SESSION['admin_user_name'] = trim(((string)($user['firstname'] ?? '')) . ' ' . ((string)($user['lastname'] ?? '')));
                        $_SESSION['admin_user_type'] = $user['type'];

                        // Redirect based on user type
                        redirectUser((int)$user['type'], $requestedTarget);
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
                       class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
            </div>

            <div class="mb-6">
                <label for="password" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('auth_password_label', 'Password'); ?></label>
                <input type="password"
                       id="password"
                       name="password"
                       required
                       class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
            </div>

            <button type="submit" class="btn btn-neutral w-full">
                <?php echo t_attr('auth_login_submit', 'Sign In'); ?>
            </button>
        </form>

        <div class="mt-4 text-center">
            <a href="forgot.php" class="text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('auth_login_forgot_link', 'Forgot your password?'); ?></a>
        </div>

        <div class="mt-8 text-center pt-6 border-t border-gray-800">
            <a href="../index.php" class="text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('auth_login_back_link', '← Back to Constellation'); ?></a>
        </div>
    </div>
</body>
</html>
