<?php
declare(strict_types=1);

/**
 * Public endpoint: returns the default API key for the frontend.
 * GET /api/apikey.php → { "api_key": "..." }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
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
        http_response_code(404);
        echo json_encode(['error' => 'API key not found'], JSON_THROW_ON_ERROR);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error'], JSON_THROW_ON_ERROR);
}
