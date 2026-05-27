<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/media/{sha256}
 *
 * Serves a content-addressed media blob to an authenticated peer (tel-pull).
 * The receiver re-hashes the bytes against the path's sha256 to verify, so the
 * federation layer treats the body as an opaque, tamper-evident blob. The hash
 * is itself an unguessable 256-bit capability a peer only learns from a galaxy
 * envelope it was entitled to pull, so blobs are not additionally
 * whitelist-scoped (a mapping blob -> galaxies -> whitelist would be ambiguous
 * for shared/deduped media). Amplification is bounded by the per-peer rate
 * limit + the publish-side whitelist gate on the envelopes that carry hashes.
 *
 * Content-addressed, so the response is immutable and aggressively cacheable;
 * the strong ETag is the hash and If-None-Match short-circuits to 304.
 *
 * Spec: Stage 5 galaxy publish design (5c-media); v10 § Standards and crypto.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/media_store.php';

const FEDERATION_MEDIA_PATH = '/api/pluriverse/media/{sha256}';

$sha256 = strtolower((string)($GLOBALS['federation_route_params']['sha256'] ?? ''));
if (!federation_media_is_sha256($sha256)) {
    federation_router_problem(404, 'not_found', 'Malformed media hash.', FEDERATION_MEDIA_PATH);
    return;
}

// Rate limit 600 req/hour/IP (APCu), per the catalogue.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_media:' . date('YmdH') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 3600);
    if ($count !== false && (int)$count > 600) {
        federation_router_problem(429, 'rate_limited', 'Too many media requests this hour; retry shortly.', FEDERATION_MEDIA_PATH);
        return;
    }
}

$verify = federation_verify_inbound(federation_build_inbound_request('GET'), 'tel-pull');
if (!$verify['ok']) {
    federation_router_problem(401, $verify['reason'], 'HTTP Signature verification failed: ' . $verify['reason'], FEDERATION_MEDIA_PATH);
    return;
}
if (($verify['caller_kind'] ?? '') !== 'peer' || !isset($verify['caller_peer_id'])) {
    federation_router_problem(403, 'not_a_peer', 'Media fetch is a peer-only endpoint.', FEDERATION_MEDIA_PATH);
    return;
}

try {
    $blob = federation_media_lookup($sha256);
} catch (Throwable $e) {
    error_log('pluriverse/media: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not look up the media blob.', FEDERATION_MEDIA_PATH);
    return;
}
if ($blob === null) {
    federation_router_problem(404, 'not_found', 'No media blob with that hash.', FEDERATION_MEDIA_PATH);
    return;
}

$etag = '"' . $sha256 . '"';
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch === '*' || ($ifNoneMatch !== '' && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true))) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=31536000, immutable');
    return;
}

http_response_code(200);
header('Content-Type: ' . $blob['mime']);
header('Content-Length: ' . $blob['size_bytes']);
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
readfile($blob['storage_path']);
