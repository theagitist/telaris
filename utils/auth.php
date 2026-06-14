<?php
declare(strict_types=1);

/**
 * Authentication Helper
 * Provides functions for user authentication and authorization
 */

require_once __DIR__ . '/../config.php';

// Start session if not already started (skip in CLI — e.g. PHPUnit)
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        // Lax, not Strict: a sign-in link clicked inside an email is a
        // cross-site top-level navigation. With Strict the browser withholds
        // the freshly-set session cookie on the post-login redirect (the whole
        // redirect chain inherits the cross-site initiator), so the magic-link
        // and enrol-confirm flows land back on the login screen. Lax sends the
        // cookie on top-level GET navigations while still blocking cross-site
        // subresources and cross-site POSTs; CSRF is enforced separately by the
        // per-request csrf_token, so protection does not depend on Strict.
        'samesite' => 'Lax',
    ]);
    session_start();
}

// User type constants
define('USER_TYPE_REGULAR', 0);
define('USER_TYPE_EDITOR', 1);
define('USER_TYPE_ADMIN', 2);

// Auth-throttle constants (Fix 6: rate-limit login / forgot / reset).
// Window in seconds, max-attempts cap. Tuned conservatively; an honest user
// should never hit these. Guarded against double-define because api/auth.php
// (second-pass audit M3) also declares the AUTH_API_KEY_* pair, and any
// future caller that pulls both files would otherwise emit a PHP Warning.
if (!defined('AUTH_LOGIN_MAX_FAILURES')) {
    define('AUTH_LOGIN_MAX_FAILURES', 5);
    define('AUTH_LOGIN_WINDOW_SECONDS', 300);
    define('AUTH_FORGOT_MAX_ATTEMPTS', 5);
    define('AUTH_FORGOT_WINDOW_SECONDS', 300);
    define('AUTH_RESET_MAX_ATTEMPTS', 10);
    define('AUTH_RESET_WINDOW_SECONDS', 600);
    // Magic sign-in link requests (editor self-enrollment): same shape as forgot.
    define('AUTH_LOGINLINK_MAX_ATTEMPTS', 5);
    define('AUTH_LOGINLINK_WINDOW_SECONDS', 300);
    // Self-enrolment submissions: per-IP, slightly tighter window.
    define('AUTH_ENROLL_MAX_ATTEMPTS', 5);
    define('AUTH_ENROLL_WINDOW_SECONDS', 600);
}
// API-key throttle (second-pass audit M3): legitimate visitor pages fetch
// the public key once per session, then reuse it; sustained-rate-of-failure
// well below this cap is what a brute-force attempt looks like.
if (!defined('AUTH_API_KEY_MAX_FAILURES')) {
    define('AUTH_API_KEY_MAX_FAILURES', 30);
    define('AUTH_API_KEY_WINDOW_SECONDS', 300);
}

/**
 * Client IP for throttling. Trusts REMOTE_ADDR alone — nginx rewrites it
 * to the real client IP for Cloudflare-fronted traffic via the
 * /etc/nginx/snippets/cloudflare-realip.conf snippet (set_real_ip_from +
 * real_ip_header CF-Connecting-IP + real_ip_recursive on). On direct-to-
 * origin requests, REMOTE_ADDR is the attacker's real IP because nginx
 * does NOT rewrite headers from non-trusted sources. Either way, this is
 * the source-of-truth value; the application must not consult forwarded
 * headers because an attacker hitting the origin directly can forge them.
 *
 * The snippet must be installed and included from every Telaris vhost.
 * A copy lives in the repo at etc/nginx/cloudflare-realip.conf.sample
 * with installation instructions. Without the snippet, REMOTE_ADDR will
 * be the Cloudflare edge IP and per-IP throttles will over-fire (legit
 * users behind the same CF edge get lumped together) — the email-axis
 * throttle still applies, so this is a UX regression rather than a
 * security regression.
 */
function auth_client_ip(): string {
    $value = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : '-';
}

/**
 * Check if user is logged in as admin (type 2 only)
 */
function isAdminLoggedIn(): bool {
    return isset($_SESSION['admin_user_id']) && 
           isset($_SESSION['admin_user_type']) && 
           (int)$_SESSION['admin_user_type'] === USER_TYPE_ADMIN;
}

/**
 * Check if user is logged in as editor (type 1)
 */
function isEditorLoggedIn(): bool {
    return isset($_SESSION['admin_user_id']) && 
           isset($_SESSION['admin_user_type']) && 
           (int)$_SESSION['admin_user_type'] === USER_TYPE_EDITOR;
}

/**
 * Check if user is logged in as editor or admin
 */
function isEditorOrAdminLoggedIn(): bool {
    return isset($_SESSION['admin_user_id']) &&
           isset($_SESSION['admin_user_type']) &&
           ((int)$_SESSION['admin_user_type'] === USER_TYPE_EDITOR ||
            (int)$_SESSION['admin_user_type'] === USER_TYPE_ADMIN);
}

/**
 * If the current session belongs to an editor (not admin), verify they have
 * a seat on the given constellation. Admins and API-key-only callers are not
 * restricted at this layer. Returns an error message string when the editor
 * is denied, or null when allowed.
 */
function checkEditorConstellationAccess(int $constellationId): ?string {
    if (!isEditorOrAdminLoggedIn()) {
        return null;
    }
    if (isAdminLoggedIn()) {
        return null;
    }
    $userId = $_SESSION['admin_user_id'] ?? null;
    if (!$userId) {
        return null;
    }
    $allowed = db_get_constellations_for_user($userId, false);
    $allowedIds = array_column($allowed, 'id');
    if (!in_array($constellationId, $allowedIds, true)) {
        return 'Access denied to this constellation';
    }
    return null;
}

/**
 * Require admin login - redirects to login if not authenticated
 */
function requireAdminLogin(): void {
    if (!isAdminLoggedIn()) {
        // Determine base path for login redirect (login is in utils/)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = '';
        if (strpos($scriptName, '/admin/') !== false || strpos($scriptName, 'admin/') !== false) {
            $basePath = '../';
        }
        header('Location: ' . $basePath . 'utils/login.php?redirect=admin');
        exit();
    }
}

/**
 * Require editor or admin login - redirects to login if not authenticated
 */
function requireEditorOrAdminLogin(): void {
    if (!isEditorOrAdminLoggedIn()) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $redirectTarget = 'admin';
        if (strpos($requestUri, '/edit/') !== false || 
            strpos($requestUri, 'edit/') !== false ||
            strpos($scriptName, '/edit/') !== false ||
            strpos($scriptName, 'edit/') !== false) {
            $redirectTarget = 'edit';
        }
        $basePath = '';
        if (strpos($scriptName, '/edit/') !== false || strpos($scriptName, 'edit/') !== false) {
            $basePath = '../';
        }
        header('Location: ' . $basePath . 'utils/login.php?redirect=' . urlencode($redirectTarget));
        exit();
    }
}

/**
 * Hash a password using bcrypt with automatic salting
 * Uses PASSWORD_DEFAULT which currently uses bcrypt with cost factor 10
 * 
 * @param string $password Plain text password
 * @return string Hashed password (includes salt and algorithm info)
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify a password against a hash
 * Automatically extracts salt from the hash
 * 
 * @param string $password Plain text password to verify
 * @param string $hash Stored password hash
 * @return bool True if password matches, false otherwise
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Check if a password hash needs to be rehashed (e.g., if cost factor increased)
 * 
 * @param string $hash Existing password hash
 * @return bool True if hash should be rehashed
 */
function passwordNeedsRehash(string $hash): bool {
    return password_needs_rehash($hash, PASSWORD_DEFAULT);
}

/**
 * Authenticate user with email and password
 * Returns user data on success, null on failure
 * Allows both editors (type 1) and admins (type 2) to authenticate
 * 
 * Security: Uses password_verify() which automatically handles salt extraction
 */
function authenticateUser(string $email, string $password): ?array {
    try {
        $user = db_get_user_by_email($email);
        // A null/empty password means "no password set yet" (an unvetted
        // self-enrolled editor who signs in only via an emailed magic link).
        // Such an account cannot be authenticated by password; verifyPassword
        // would also TypeError on a null hash under strict_types.
        if (!$user || $user['password'] === null || $user['password'] === '') {
            return null;
        }
        if (!verifyPassword($password, (string)$user['password'])) {
            return null;
        }
        if (passwordNeedsRehash($user['password'])) {
            $newHash = hashPassword($password);
            db_update_user_password($user['id'], $newHash);
        }
        $userType = (int)$user['type'];
        if ($userType === USER_TYPE_EDITOR || $userType === USER_TYPE_ADMIN) {
            db_update_user_last_login($user['id']);
            return $user;
        }
        return null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Logout current user
 */
function logoutAdmin(): void {
    $_SESSION = [];
    if (isset($_COOKIE[session_name()])) {
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            // Match the session cookie's attributes (see session_set_cookie_params above).
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
