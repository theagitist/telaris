<?php
declare(strict_types=1);

/**
 * API Key Authentication
 * Validates API key from request headers or query parameters
 */

require_once __DIR__ . '/../config.php';

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
 * Require API key authentication
 * Exits with 401 error if API key is missing or invalid
 */
function requireApiKey(): void {
    $apiKey = getApiKeyFromRequest();
    
    if (!$apiKey) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => 'API key is missing. Provide API key via X-API-Key header, Authorization: Bearer <key> header, or ?api_key= query parameter.',
        ], JSON_THROW_ON_ERROR);
        exit();
    }
    
    if (!db_validate_api_key($apiKey)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => 'Invalid API key.',
        ], JSON_THROW_ON_ERROR);
        exit();
    }
}
