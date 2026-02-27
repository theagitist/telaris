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
