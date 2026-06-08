<?php
declare(strict_types=1);

/**
 * API Key Authentication
 * Validates API key from request headers or query parameters
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

// API-key throttle constants (second-pass audit M3). Defined here rather
// than in utils/auth.php because the bare API endpoints (constellations,
// connections, tags, apikey) deliberately skip utils/auth.php to keep
// visitor reads session-less.
if (!defined('AUTH_API_KEY_MAX_FAILURES')) {
    define('AUTH_API_KEY_MAX_FAILURES', 30);
}
if (!defined('AUTH_API_KEY_WINDOW_SECONDS')) {
    define('AUTH_API_KEY_WINDOW_SECONDS', 300);
}

/**
 * Resolve the client IP for throttling. Mirrors utils/auth.php's
 * auth_client_ip() — trusts REMOTE_ADDR alone because nginx's Cloudflare
 * real-IP snippet (see etc/nginx/cloudflare-realip.conf.sample) rewrites
 * it to the real client IP for Cloudflare-fronted traffic. The bare API
 * endpoints can throttle without pulling in the session bootstrap.
 */
function api_client_ip(): string {
    $value = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : '-';
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
 * Require API key authentication. Exits 401 if missing or invalid; exits with
 * the generic 401.002 if the source IP has burned through the per-IP failure
 * budget for the window (second-pass audit M3).
 *
 * The throttle gates on the resolved client IP only — API keys aren't tied
 * to a user account, so there's no email axis. AUTH_API_KEY_MAX_FAILURES is
 * set well above any honest-client failure rate (visitor pages fetch the key
 * once per session and reuse it), so an honest miss won't lock out.
 */
function requireApiKey(): void {
    $ip = api_client_ip();

    // Per-(action, IP) advisory lock closes the count → record TOCTOU
    // (M-C1, audit v6.10.11). On contention or acquisition failure we
    // refuse with 401.002, matching the over-threshold response — fail
    // closed.
    $lock = db_auth_throttle_lock_acquire('api_key', $ip);
    try {
        // Pre-check: if this IP has already burned through the failure budget,
        // refuse without doing any work. Counter is still incremented so a
        // determined attacker pays the cost; the missing $apiKey path is a
        // "failure" for throttling purposes too.
        if (!$lock['acquired'] || db_count_recent_auth_attempts('api_key', null, $ip, AUTH_API_KEY_WINDOW_SECONDS, false) >= AUTH_API_KEY_MAX_FAILURES) {
            db_record_auth_attempt('api_key', null, $ip, false);
            api_error('401.002', 'Invalid API key.');
        }

        $apiKey = getApiKeyFromRequest();

        if (!$apiKey) {
            db_record_auth_attempt('api_key', null, $ip, false);
            api_error('401.001', 'API key is missing. Provide it via the X-API-Key header, the Authorization: Bearer header, or the api_key query parameter.');
        }

        if (!db_validate_api_key($apiKey)) {
            db_record_auth_attempt('api_key', null, $ip, false);
            api_error('401.002', 'Invalid API key.');
        }

        db_record_auth_attempt('api_key', null, $ip, true);
    } finally {
        db_auth_throttle_lock_release($lock);
    }
}
