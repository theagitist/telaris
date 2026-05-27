<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/galaxies/{slug}.retracted
 *
 * The canonical retraction endpoint: returns the origin-signed retraction
 * envelope (JWS Compact) for a retracted slug, or 404 if the slug is not
 * retracted. Public (no signature): any holder of a stale mirror, whitelisted
 * or not, must be able to fetch and verify the withdrawal. Retractions are
 * permanent, so the response is cacheable.
 *
 * Spec: Stage 5 galaxy publish design (5c); v10 § State-change propagation.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_retraction.php';

const FEDERATION_RETRACTED_SLUG_PATH = '/api/pluriverse/galaxies/{slug}.retracted';

$slug = (string)($GLOBALS['federation_route_params']['slug'] ?? '');
if ($slug === '') {
    federation_router_problem(404, 'not_found', 'No galaxy slug in request path.', FEDERATION_RETRACTED_SLUG_PATH);
    return;
}

// Rate limit 60 req/min/IP (APCu), per the catalogue.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_retracted:' . date('YmdHi') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many retraction requests this minute; retry shortly.', FEDERATION_RETRACTED_SLUG_PATH);
        return;
    }
}

try {
    $jws = federation_retraction_for_slug($slug);
} catch (Throwable $e) {
    error_log('pluriverse/retracted: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not read the retraction.', FEDERATION_RETRACTED_SLUG_PATH);
    return;
}

if ($jws === null) {
    federation_router_problem(404, 'not_found', 'No retraction exists for ' . $slug . '.', FEDERATION_RETRACTED_SLUG_PATH);
    return;
}

http_response_code(200);
header('Content-Type: application/jose; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $jws;
