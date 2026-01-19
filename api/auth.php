<?php
declare(strict_types=1);

/**
 * API Key Authentication
 * Validates API key from request headers or query parameters
 */

require_once '../config.php';

/**
 * Validate API key and return true if valid, false otherwise
 * Updates last_used_at timestamp on successful validation
 */
function validateApiKey(PDO $pdo, string $apiKey): bool {
    if (empty($apiKey)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, is_active 
            FROM api_keys 
            WHERE api_key = :api_key AND is_active = TRUE
        ");
        $stmt->execute([':api_key' => $apiKey]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Update last_used_at timestamp
            $updateStmt = $pdo->prepare("
                UPDATE api_keys 
                SET last_used_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $updateStmt->execute([':id' => $result['id']]);
            return true;
        }
        
        return false;
    } catch (PDOException $e) {
        return false;
    }
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
    $pdo = getDB();
    $apiKey = getApiKeyFromRequest();
    
    if (!$apiKey) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => 'API key is missing. Provide API key via X-API-Key header, Authorization: Bearer <key> header, or ?api_key= query parameter.',
            'debug' => [
                'headers_available' => function_exists('getallheaders'),
                'server_keys' => array_keys(array_filter($_SERVER, fn($k) => strpos($k, 'HTTP_') === 0 || strpos($k, 'X-') === 0, ARRAY_FILTER_USE_KEY))
            ]
        ], JSON_THROW_ON_ERROR);
        exit();
    }
    
    if (!validateApiKey($pdo, $apiKey)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => 'Invalid API key. The provided API key is not valid or is inactive.',
            'debug' => [
                'key_length' => strlen($apiKey),
                'key_prefix' => substr($apiKey, 0, 8) . '...'
            ]
        ], JSON_THROW_ON_ERROR);
        exit();
    }
}
