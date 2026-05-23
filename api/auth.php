<?php
declare(strict_types=1);

/**
 * API Key Authentication
 * Validates API key from request headers or query parameters
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

/**
 * Validate API key and return true if valid, false otherwise
 * Updates last_used_at timestamp on successful validation
 */
function validateApiKey(PDO $pdo, string $apiKey): bool {
    return db_validate_api_key($apiKey);
}

/**
 * Get API key from request (checks headers first, then query parameters)
 * Works in all PHP environments (Apache, FastCGI, FPM, etc.)
 */
function getApiKeyFromRequest(): ?string {
    // Helper function to get headers in all environments
    $getHeader = function(string $name): ?string {
        // Try getallheaders() first (Apache only)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers) {
                foreach ($headers as $key => $value) {
                    if (strtolower($key) === strtolower($name)) {
                        return trim($value);
                    }
                }
            }
        }
        
        // Fallback: Use $_SERVER (works in all environments)
        // HTTP_ headers are prefixed and uppercased with underscores
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey])) {
            return trim($_SERVER[$serverKey]);
        }
        
        // Also check for exact match (some servers use different formats)
        if (isset($_SERVER[$name])) {
            return trim($_SERVER[$name]);
        }
        
        return null;
    };
    
    // Check X-API-Key header
    $apiKey = $getHeader('X-API-Key');
    if ($apiKey) {
        return $apiKey;
    }
    
    // Check Authorization header (Bearer token format)
    $auth = $getHeader('Authorization');
    if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
        return trim($matches[1]);
    }
    
    // Check query parameter
    if (isset($_GET['api_key'])) {
        return trim($_GET['api_key']);
    }
    
    return null;
}

/**
 * Require session-based auth (editor or admin) for write operations.
 * Call this AFTER requireApiKey() on POST/PUT/DELETE endpoints.
 * API-key-only callers (no session) are restricted to read-only access.
 */
function requireWriteAccess(): void {
    // Start session if not already started (auth.php may be loaded without utils/auth.php)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['admin_user_id']) || empty($_SESSION['admin_user_type'])) {
        api_error('403.001', 'Write operations require an authenticated session. Please log in.');
    }
    $type = (int)$_SESSION['admin_user_type'];
    if ($type !== 1 && $type !== 2) { // 1=editor, 2=admin
        api_error('403.002', 'Insufficient permissions for write operations.');
    }
    // CSRF: every write endpoint also requires the token. SameSite=Strict on the
    // session cookie is the belt; this is the suspenders, and the right primitive
    // when the cookie boundary shifts (subdomains, CORS, future flows).
    verify_csrf_token();
}

/**
 * Default cache headers for every JSON API response. no-store keeps responses
 * out of the browser back-cache (admin/editor surfaces contain other-user
 * data) and out of any intermediate proxy. Individual endpoints can override
 * by emitting their own Cache-Control after this.
 */
function api_no_store(): void {
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
}

/**
 * Verify the request carries a valid CSRF token. Reads X-CSRF-Token (header)
 * first, then csrf_token in $_POST. JSON-body callers must send the header
 * because $_POST is empty for application/json. Session is started if not
 * already up; the token is established on first auth-page render.
 *
 * Call this AFTER requireWriteAccess() on every write endpoint. SameSite=Strict
 * already blocks cross-origin form posts, but the token is the explicit
 * defence and the right primitive when the cookie boundary shifts (CORS,
 * subdomains, etc.).
 */
function verify_csrf_token(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $field = $_POST['csrf_token'] ?? '';
    $submitted = $header !== '' ? $header : $field;
    if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
        api_error('403.003', 'Invalid security token. Please reload the page and try again.');
    }
}

/**
 * Require API key authentication
 * Exits with 401 error if API key is missing or invalid
 */
function requireApiKey(): void {
    $apiKey = getApiKeyFromRequest();
    
    if (!$apiKey) {
        api_error('401.001', 'API key is missing. Provide it via the X-API-Key header, the Authorization: Bearer header, or the api_key query parameter.');
    }

    if (!db_validate_api_key($apiKey)) {
        api_error('401.002', 'Invalid API key.');
    }
}
