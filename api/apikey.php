<?php
declare(strict_types=1);

/**
 * Public endpoint: returns the default API key for the frontend.
 * GET /api/apikey.php → { "api_key": "..." }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

header('Content-Type: application/json'); header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $apiKey = getDefaultApiKey();
    if ($apiKey) {
        echo json_encode(['api_key' => $apiKey], JSON_THROW_ON_ERROR);
    } else {
        api_error('404.009', 'API key not found.');
    }
} catch (Exception $e) {
    error_log('apikey.php: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
