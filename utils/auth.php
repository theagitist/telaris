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
        'samesite' => 'Strict',
    ]);
    session_start();
}

// User type constants
define('USER_TYPE_REGULAR', 0);
define('USER_TYPE_EDITOR', 1);
define('USER_TYPE_ADMIN', 2);

// Auth-throttle constants (Fix 6: rate-limit login / forgot / reset).
// Window in seconds, max-attempts cap. Tuned conservatively; an honest user
// should never hit these.
define('AUTH_LOGIN_MAX_FAILURES', 5);
define('AUTH_LOGIN_WINDOW_SECONDS', 300);
define('AUTH_FORGOT_MAX_ATTEMPTS', 5);
define('AUTH_FORGOT_WINDOW_SECONDS', 300);
define('AUTH_RESET_MAX_ATTEMPTS', 10);
define('AUTH_RESET_WINDOW_SECONDS', 600);

/**
 * Best-effort client IP for throttling. Trusts CF-Connecting-IP and
 * X-Forwarded-For when present; the Polivoxia instances sit behind
 * Cloudflare so REMOTE_ADDR by itself is the proxy IP and would
 * over-throttle. Headers can be forged when an attacker hits the origin
 * directly, but the email-axis throttle still applies in that case.
 */
function auth_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $value = (string)$_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $value = trim(explode(',', $value)[0]);
            }
            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }
    }
    return '-';
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
        if (!$user || !verifyPassword($password, $user['password'])) {
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
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}
