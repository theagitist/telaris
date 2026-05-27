<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/galaxies/{slug}
 *
 * The canonical per-galaxy pull endpoint: returns the cached, origin-signed JWS
 * envelope (5b) for one authored, currently-published galaxy, scoped to the
 * calling peer's publish whitelist. The consumer (5d) verifies the signature
 * and the freshness checks, then materializes the galaxy as a local mirror.
 *
 * Change-detection is the index's job: published.json already carries each
 * slug's content_hash + published_sequence, so a consumer only fetches this
 * endpoint when the index shows a slug it does not yet mirror at the current
 * hash. As a courtesy this endpoint also honours conditional GET: it emits a
 * strong ETag (the content_hash) and answers If-None-Match with 304, so a
 * consumer re-checking a single slug spends no body bytes when nothing changed.
 *
 * This replaces the v10 catalogue's {slug}.head / {slug}.telaris-backup pair.
 * The standard collection+member shape (published.json index + one canonical
 * signed member here) covers routine federation; full-fidelity archival export
 * is an operator concern, not a pull primitive. See Stage 5 galaxy publish
 * design § Publish-side endpoints.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/galaxy_publish.php';

const FEDERATION_GALAXY_ENVELOPE_PATH = '/api/pluriverse/galaxies/{slug}';

$slug = (string)($GLOBALS['federation_route_params']['slug'] ?? '');
if ($slug === '') {
    federation_router_problem(404, 'not_found', 'No galaxy slug in request path.', FEDERATION_GALAXY_ENVELOPE_PATH);
    return;
}

// Rate limit 60 req/min/IP (APCu), per the catalogue.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_galaxy:' . date('YmdHi') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many galaxy pulls this minute; retry shortly.', FEDERATION_GALAXY_ENVELOPE_PATH);
        return;
    }
}

$verify = federation_verify_inbound(federation_build_inbound_request('GET'), 'tel-pull');
if (!$verify['ok']) {
    federation_router_problem(401, $verify['reason'], 'HTTP Signature verification failed: ' . $verify['reason'], FEDERATION_GALAXY_ENVELOPE_PATH);
    return;
}
if (($verify['caller_kind'] ?? '') !== 'peer' || !isset($verify['caller_peer_id'])) {
    federation_router_problem(403, 'not_a_peer', 'Galaxy pulls are a peer-only endpoint.', FEDERATION_GALAXY_ENVELOPE_PATH);
    return;
}

try {
    $row = federation_published_envelope_for_peer((int)$verify['caller_peer_id'], $slug);
} catch (Throwable $e) {
    error_log('pluriverse/galaxies: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not read the galaxy envelope.', FEDERATION_GALAXY_ENVELOPE_PATH);
    return;
}

// Uniform 404 whether the slug is unknown, not current, mirrored, or simply not
// in this peer's publish whitelist: never leak which galaxies exist.
if ($row === null) {
    federation_router_problem(404, 'not_found', 'No galaxy is published to you at ' . $slug . '.', FEDERATION_GALAXY_ENVELOPE_PATH);
    return;
}

$etag = '"' . $row['content_hash'] . '"';
$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && _federation_etag_matches($ifNoneMatch, $etag)) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Cache-Control: no-cache, private');
    return;
}

http_response_code(200);
header('Content-Type: application/jose; charset=utf-8');
header('ETag: ' . $etag);
header('Cache-Control: no-cache, private');
header('X-Content-Type-Options: nosniff');
echo $row['envelope_jws'];

/**
 * RFC 9110 If-None-Match comparison against our single strong ETag. Honours the
 * "*" wildcard and a comma-separated list, and tolerates the weak "W/" prefix a
 * client may echo back.
 */
function _federation_etag_matches(string $ifNoneMatch, string $etag): bool {
    if ($ifNoneMatch === '*') return true;
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if (str_starts_with($candidate, 'W/')) {
            $candidate = trim(substr($candidate, 2));
        }
        if ($candidate === $etag) return true;
    }
    return false;
}
