<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenUser = $token !== '' ? db_get_user_for_password_reset_token($token) : null;

$error = null;
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
        $error = 'Invalid request. Please reload the page and try again.';
    } elseif (!$tokenUser) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $pw1 = (string)($_POST['password'] ?? '');
        $pw2 = (string)($_POST['password_confirm'] ?? '');
        if (strlen($pw1) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($pw1 !== $pw2) {
            $error = 'Passwords don\'t match.';
        } else {
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            $ok = db_consume_password_reset_token($token, $hash);
            if (!$ok) {
                $error = 'This reset link is invalid or has expired. Please request a new one.';
            } else {
                $success = true;
                // Rotate the CSRF token now that we've completed a sensitive action.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>Set new password - Telaris</title>
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-md text-white">
        <h1 class="text-white mb-2 text-3xl font-semibold text-center">Set new password</h1>

        <?php if ($success): ?>
            <p class="text-emerald-300 my-6 text-center text-sm">Password updated. You can now log in with your new password.</p>
            <a href="login.php" class="btn btn-neutral w-full">Go to login</a>
        <?php elseif (!$tokenUser): ?>
            <p class="text-red-300 my-6 text-center text-sm">This reset link is invalid or has expired. Please request a new one.</p>
            <a href="forgot.php" class="btn btn-neutral w-full">Request a new link</a>
        <?php else: ?>
            <p class="text-gray-400 mb-6 text-center text-sm">Setting a new password for <strong class="text-white"><?php echo htmlspecialchars((string)$tokenUser['email']); ?></strong>.</p>

            <?php if ($error): ?>
                <div class="bg-red-900/30 border border-red-800 text-red-200 p-4 rounded mb-5 text-sm">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="mb-5">
                    <label for="password" class="block mb-1.5 text-gray-300 font-medium">New password</label>
                    <input type="password" id="password" name="password" required minlength="8" autofocus autocomplete="new-password"
                           class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
                    <span class="text-xs text-gray-500 mt-1 block">At least 8 characters.</span>
                </div>
                <div class="mb-6">
                    <label for="password_confirm" class="block mb-1.5 text-gray-300 font-medium">Confirm new password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"
                           class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
                </div>
                <button type="submit" class="btn btn-neutral w-full">Update password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
