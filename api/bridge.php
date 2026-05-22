<?php
declare(strict_types=1);

/**
 * Generic bridge dispatcher (HTTP).
 *
 *   GET  api/bridge.php?name=<bridge>&action=<action>&...
 *   POST api/bridge.php?name=<bridge>&action=<action>   (body forwarded)
 *
 * Looks up <bridge> in TELARIS_BRIDGES, requires the handler at
 * inc/bridges/<bridge>.php, and calls <bridge>_handle_request(). The handler
 * owns its own action vocabulary; the dispatcher only enforces the framing
 * (auth, CORS, error envelope, active-bridge check).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/bridges/_lib.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

requireApiKey();

$bridgeName = trim($_GET['name'] ?? '');
if (!bridges_name_is_valid($bridgeName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid bridge name'], JSON_THROW_ON_ERROR);
    exit();
}

if (!bridges_is_active($bridgeName)) {
    http_response_code(404);
    echo json_encode(['error' => "Bridge '{$bridgeName}' is not enabled on this instance"], JSON_THROW_ON_ERROR);
    exit();
}

if (!bridges_load($bridgeName)) {
    http_response_code(500);
    echo json_encode(['error' => "Bridge '{$bridgeName}' handler file is missing"], JSON_THROW_ON_ERROR);
    exit();
}

$handlerFn = $bridgeName . '_handle_request';
if (!function_exists($handlerFn)) {
    http_response_code(500);
    echo json_encode(['error' => "Bridge '{$bridgeName}' has no request handler"], JSON_THROW_ON_ERROR);
    exit();
}

try {
    $handlerFn();
} catch (Throwable $e) {
    // Log the detail to the server log; emit a generic envelope to the
    // client so DB error text / internal paths do not surface in API
    // responses. Operators tracking down failures look at the server log.
    error_log("bridge.php ({$bridgeName}): " . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error'], JSON_THROW_ON_ERROR);
    }
}
