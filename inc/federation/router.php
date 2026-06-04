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
 *
 * Two route tables: an exact-match map ($routes) checked first, then a
 * parametric fallback ($paramRoutes) of anchored regexes for resource paths
 * that carry an identifier in the URL (a galaxy slug, a media hash). A matched
 * parametric route's captures land in $GLOBALS['federation_route_params'] for
 * the handler to read.
 */

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');
if ($path === '') $path = '/';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Method + path table. Each row: pattern → [allowed methods, handler file].
// The router exists so endpoints can be added without touching index.php.
$routes = [
    '/api/pluriverse/identity' => ['methods' => ['GET'], 'handler' => __DIR__ . '/identity_handler.php'],
    '/api/pluriverse/openapi.json' => ['methods' => ['GET'], 'handler' => __DIR__ . '/openapi_handler.php'],
    '/api/pluriverse/galaxies.json' => ['methods' => ['GET'], 'handler' => __DIR__ . '/galaxies_handler.php'],
    '/api/pluriverse/published.json' => ['methods' => ['GET'], 'handler' => __DIR__ . '/published_handler.php'],
    '/api/pluriverse/retracted.json' => ['methods' => ['GET'], 'handler' => __DIR__ . '/retracted_list_handler.php'],
    '/api/pluriverse/revoked.json' => ['methods' => ['GET'], 'handler' => __DIR__ . '/revoked_list_handler.php'],
    '/api/pluriverse/schema/envelope-1.0.json' => ['methods' => ['GET'], 'handler' => __DIR__ . '/schema_handler.php'],
    '/api/pluriverse/handshake' => ['methods' => ['POST'], 'handler' => __DIR__ . '/handshake_handler.php'],
    '/api/pluriverse/key-events-push' => ['methods' => ['POST'], 'handler' => __DIR__ . '/key_events_push_handler.php'],
    '/api/pluriverse/messages' => ['methods' => ['POST'], 'handler' => __DIR__ . '/messages_handler.php'],
];

// Parametric routes, tried in order after an exact-match miss. Each named
// capture group is exposed to the handler via $GLOBALS['federation_route_params'].
// Slugs are db_slugify() output: lowercase alphanumerics and hyphens, no dots,
// so the pattern can never collide with the exact .json routes above.
$paramRoutes = [
    [
        'pattern' => '#^/api/pluriverse/galaxies/(?<slug>[a-z0-9-]+)\.retracted$#',
        'methods' => ['GET'],
        'handler' => __DIR__ . '/retraction_handler.php',
    ],
    [
        'pattern' => '#^/api/pluriverse/galaxies/(?<slug>[a-z0-9-]+)$#',
        'methods' => ['GET'],
        'handler' => __DIR__ . '/galaxy_envelope_handler.php',
    ],
    [
        'pattern' => '#^/api/pluriverse/media/(?<sha256>[a-f0-9]{64})$#',
        'methods' => ['GET'],
        'handler' => __DIR__ . '/media_handler.php',
    ],
];

$route = $routes[$path] ?? null;
if ($route === null) {
    foreach ($paramRoutes as $pr) {
        if (preg_match($pr['pattern'], $path, $m)) {
            $route = $pr;
            $GLOBALS['federation_route_params'] = array_filter(
                $m,
                static fn($k) => is_string($k),
                ARRAY_FILTER_USE_KEY
            );
            break;
        }
    }
}

if ($route === null) {
    federation_router_problem(
        404,
        'not_found',
        'No federation endpoint at ' . $path,
        $path
    );
    return;
}

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
