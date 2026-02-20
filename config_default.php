<?php
declare(strict_types=1);

// Database configuration
define('DB_HOST', '');
define('DB_PORT', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('UPLOAD_DIR', __DIR__ . '/uploads');

require_once __DIR__ . '/inc/db.php';

/**
 * Set CORS headers for API responses (only in web context)
 */
function setCorsHeaders(): void {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}

// Only set headers and check REQUEST_METHOD in web context
// Skip headers if being included from HTML pages (admin/setup.php, admin pages, etc.)
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$htmlPages = ['setup.php', 'login.php', 'index.php', 'test.php'];
$isHtmlPage = in_array($currentScript, $htmlPages) || strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false;

// Handle API key endpoint when config.php is accessed directly (not included)
// Check if this script is being executed directly (not included via require/include)
if (php_sapi_name() !== 'cli' && $currentScript === 'config.php' && 
    isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'config.php') !== false) {
    
    setCorsHeaders();
    
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
    
    // Return API key endpoint
    try {
        $apiKey = getDefaultApiKey();
        
        if ($apiKey) {
            echo json_encode([
                'api_key' => $apiKey
            ], JSON_THROW_ON_ERROR);
        } else {
            http_response_code(404);
            echo json_encode([
                'error' => 'Default API key not found'
            ], JSON_THROW_ON_ERROR);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Error: ' . $e->getMessage()
        ], JSON_THROW_ON_ERROR);
    }
    exit();
}

if (php_sapi_name() !== 'cli' && !$isHtmlPage) {
    setCorsHeaders();
    
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
