<?php
declare(strict_types=1);

// Database configuration
define('DB_HOST', '');
define('DB_PORT', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

/**
 * Create database connection using PHP 8.3 features
 * @return PDO Database connection
 * @throws PDOException
 */
function getDB(): PDO {
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO(
            dsn: $dsn,
            username: DB_USER,
            password: DB_PASS,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode = "STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"'
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        // Only set HTTP headers if not running in CLI mode
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()], JSON_THROW_ON_ERROR));
        } else {
            throw $e; // Re-throw for CLI scripts to handle
        }
    }
}

/**
 * Get the default API key from the database
 * 
 * @param PDO|null $pdo Optional database connection (uses getDB() if not provided)
 * @return string|null The API key if found, null otherwise
 */
function getDefaultApiKey(?PDO $pdo = null): ?string {
    try {
        if ($pdo === null) {
            $pdo = getDB();
        }
        
        $stmt = $pdo->query("SELECT api_key FROM api_keys WHERE name = 'Default API Key' AND is_active = TRUE LIMIT 1");
        $result = $stmt->fetch();
        
        if ($result) {
            return $result['api_key'];
        }
        
        return null;
    } catch (PDOException $e) {
        return null;
    }
}

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
