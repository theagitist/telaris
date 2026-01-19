<?php
declare(strict_types=1);

/**
 * Authentication Helper
 * Provides functions for user authentication and authorization
 */

require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
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
        // Determine base path for login redirect
        // If script is in admin directory, go up one level to root
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = '';
        if (strpos($scriptName, '/admin/') !== false || strpos($scriptName, 'admin/') !== false) {
            $basePath = '../';
        }
        
        // Always redirect to admin after login for admin pages
        header('Location: ' . $basePath . 'login.php?redirect=admin');
        exit();
    }
}

/**
 * Require editor or admin login - redirects to login if not authenticated
 */
function requireEditorOrAdminLogin(): void {
    if (!isEditorOrAdminLoggedIn()) {
        // Determine redirect target based on current request URI or script path
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $redirectTarget = 'admin'; // default
        
        // Check if request is coming from edit directory
        if (strpos($requestUri, '/edit/') !== false || 
            strpos($requestUri, 'edit/') !== false ||
            strpos($scriptName, '/edit/') !== false ||
            strpos($scriptName, 'edit/') !== false) {
            $redirectTarget = 'edit';
        }
        
        // Determine base path for login redirect
        // If script is in edit directory, go up one level to root
        $basePath = '';
        if (strpos($scriptName, '/edit/') !== false || strpos($scriptName, 'edit/') !== false) {
            $basePath = '../';
        }
        
        header('Location: ' . $basePath . 'login.php?redirect=' . urlencode($redirectTarget));
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
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT id, email, password, firstname, lastname, type 
            FROM users 
            WHERE email = :email
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if ($user && verifyPassword($password, $user['password'])) {
            // Check if password hash needs to be updated (e.g., if cost factor changed)
            if (passwordNeedsRehash($user['password'])) {
                $newHash = hashPassword($password);
                $updateHashStmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
                $updateHashStmt->execute([':password' => $newHash, ':id' => $user['id']]);
            }
            
            // Check if user is editor (type 1) or admin (type 2)
            $userType = (int)$user['type'];
            if ($userType === USER_TYPE_EDITOR || $userType === USER_TYPE_ADMIN) {
                // Update last login
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET date_last_login = CURRENT_TIMESTAMP 
                    WHERE id = :id
                ");
                $updateStmt->execute([':id' => $user['id']]);
                
                return $user;
            }
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
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}
