<?php
declare(strict_types=1);

/**
 * Federation request router.
 *
 * Entry point for every `/api/pluriverse/*` request. Dispatches to the
 * matching endpoint handler. Called from index.php before the visitor
 * main view loads.
 *
 * Endpoints land here as stage 1c → 1e → stage 2+ work ships. At stage 1c
 * the only endpoint is GET /api/pluriverse/identity.
 *
 * Responses use RFC 9457 Problem Details (application/problem+json) for
 * errors, matching the existing api/ convention.
 */

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');
if ($path === '') $path = '/';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Method + path table. Each row: pattern → [allowed methods, handler file].
// The router exists so endpoints can be added without touching index.php.
$routes = [
    '/api/pluriverse/identity' => ['methods' => ['GET'], 'handler' => __DIR__ . '/identity_handler.php'],
];

if (!isset($routes[$path])) {
    federation_router_problem(
        404,
        'not_found',
        'No federation endpoint at ' . $path,
        $path
    );
    return;
}

$route = $routes[$path];
if (!in_array($method, $route['methods'], true)) {
    header('Allow: ' . implode(', ', $route['methods']));
    federation_router_problem(
        405,
        'method_not_allowed',
        $method . ' is not allowed on ' . $path . '; allowed: ' . implode(', ', $route['methods']),
        $path
    );
    return;
}

require $route['handler'];
return;

/**
 * Emit an RFC 9457 Problem Details JSON error and set headers. Inlined as a
 * function so handlers can reuse it without a separate include.
 */
function federation_router_problem(int $status, string $code, string $detail, string $instance): void {
    http_response_code($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode([
        'type' => 'https://www.telaris.ca/docs/errors/' . $code,
        'title' => match ($status) {
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'Error',
        },
        'status' => $status,
        'detail' => $detail,
        'instance' => $instance,
        'code' => $code,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
