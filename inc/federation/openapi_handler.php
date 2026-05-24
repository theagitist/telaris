<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/openapi.json
 *
 * Returns the OpenAPI 3.1 spec for this instance's /api/pluriverse/* surface,
 * generated at request time from PHP attributes in
 * inc/federation/openapi/annotations.php via zircote/swagger-php.
 *
 * Spec: P2P federation plan v10 § Pluriverse protocol → Standards and crypto
 *       (line 482), § Instance-side endpoint catalogue (line 469).
 *
 * Authentication: none. Public-read.
 * Rate limit: 60 req/min/IP via APCu.
 *
 * Caching: serves Last-Modified set to the most recent mtime among the
 * scanned annotation files plus this handler. Clients may send
 * If-Modified-Since to get a 304.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Best-effort per-IP rate limit; mirrors api/csp-report.php and identity_handler.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_openapi:' . date('YmdHi') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many OpenAPI requests from this IP this minute; retry shortly.',
            '/api/pluriverse/openapi.json'
        );
        return;
    }
}

$annotationsDir = __DIR__ . '/openapi';
$thisFile = __FILE__;

// Last-Modified = max mtime across the scanned annotation files plus this handler.
// Bumping any of these correctly invalidates intermediary caches.
$mtimes = [filemtime($thisFile)];
foreach (glob($annotationsDir . '/*.php') ?: [] as $f) {
    $m = @filemtime($f);
    if ($m !== false) $mtimes[] = $m;
}
$lastModified = max($mtimes);
$lastModifiedHeader = gmdate('D, d M Y H:i:s', $lastModified) . ' GMT';

// If-Modified-Since support.
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
if (is_string($ifModifiedSince) && $ifModifiedSince !== '') {
    $clientTs = @strtotime($ifModifiedSince);
    if ($clientTs !== false && $clientTs >= $lastModified) {
        http_response_code(304);
        header('Last-Modified: ' . $lastModifiedHeader);
        header('Cache-Control: public, max-age=300');
        return;
    }
}

// swagger-php 6 uses reflection on the holder classes, which means PHP must
// have them loaded before the scan runs. The annotations file is not autoloaded
// because it has no PSR-4 mapping; require it here so its classes are visible.
require_once $annotationsDir . '/annotations.php';

try {
    $openapi = (new \OpenApi\Generator())->generate([$annotationsDir]);
    $json = $openapi->toJson();
} catch (Throwable $e) {
    error_log('pluriverse/openapi: ' . $e->getMessage());
    federation_router_problem(
        500,
        'openapi_generation_failed',
        'Could not generate the OpenAPI document for this instance.',
        '/api/pluriverse/openapi.json'
    );
    return;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Last-Modified: ' . $lastModifiedHeader);
header('X-Content-Type-Options: nosniff');
echo $json;
