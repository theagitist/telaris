<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/schema/{name}.json
 *
 * Serves the published JSON Schemas for the federation wire formats from
 * inc/federation/schemas/. Public, no signature: a receiver MAY validate a
 * payload against its schema before doing any crypto work. The file served is
 * chosen from a fixed allowlist keyed by the request path basename, so the
 * route can never reach outside the schemas directory.
 *
 * Caching: Last-Modified = the schema file's mtime; honours If-Modified-Since
 * with a 304. Bumping a schema file invalidates intermediary caches.
 *
 * Spec: Stage 5 galaxy publish design (5b/5c); v10 § Instance-side endpoint
 * catalogue (schema endpoints).
 */

require_once dirname(__DIR__, 2) . '/config.php';

const FEDERATION_SCHEMA_DIR = __DIR__ . '/schemas';

// Allowlist of servable schema files. Add a file + its exact route in router.php
// together; the message and key-event schemas slot in here when they ship.
const FEDERATION_SCHEMA_FILES = [
    'envelope-1.0.json' => 'envelope-1.0.json',
];

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$name = basename($path);

$schemaPath = '/api/pluriverse/schema/' . $name;

// Rate limit 60 req/min/IP (APCu), per the catalogue.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_schema:' . date('YmdHi') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many schema requests this minute; retry shortly.', $schemaPath);
        return;
    }
}

if (!isset(FEDERATION_SCHEMA_FILES[$name])) {
    federation_router_problem(404, 'not_found', 'No published schema named ' . $name . '.', $schemaPath);
    return;
}

$file = FEDERATION_SCHEMA_DIR . '/' . FEDERATION_SCHEMA_FILES[$name];
$body = @file_get_contents($file);
if ($body === false) {
    error_log('pluriverse/schema: cannot read ' . $file);
    federation_router_problem(500, 'schema_unavailable', 'The schema file could not be read.', $schemaPath);
    return;
}

$mtime = @filemtime($file) ?: time();
$lastModifiedHeader = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
if (is_string($ifModifiedSince) && $ifModifiedSince !== '') {
    $clientTs = @strtotime($ifModifiedSince);
    if ($clientTs !== false && $clientTs >= $mtime) {
        http_response_code(304);
        header('Last-Modified: ' . $lastModifiedHeader);
        header('Cache-Control: public, max-age=3600');
        return;
    }
}

http_response_code(200);
header('Content-Type: application/schema+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Last-Modified: ' . $lastModifiedHeader);
header('X-Content-Type-Options: nosniff');
echo $body;
