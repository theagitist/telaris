<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/published.json
 *
 * Authenticated peer read (tag tel-pull, per-peer Ed25519 HTTP Signature).
 * Returns the publish-whitelist-scoped list of authored galaxies this instance
 * offers to the calling peer: { slug, published_sequence, content_hash,
 * published_at }. The peer then pulls .head / .telaris-backup for the slugs it
 * subscribes to.
 *
 * Spec: Stage 5 galaxy publish design (5c); v10 § instance-side endpoint
 * catalogue.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/galaxy_publish.php';

const FEDERATION_PUBLISHED_PATH = '/api/pluriverse/published.json';

// Rate limit 60 req/min/IP (APCu), per the catalogue.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_published:' . date('YmdHi') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many published.json requests this minute; retry shortly.', FEDERATION_PUBLISHED_PATH);
        return;
    }
}

$verify = federation_verify_inbound(federation_build_inbound_request('GET'), 'tel-pull');
if (!$verify['ok']) {
    federation_router_problem(401, $verify['reason'], 'HTTP Signature verification failed: ' . $verify['reason'], FEDERATION_PUBLISHED_PATH);
    return;
}
if (($verify['caller_kind'] ?? '') !== 'peer' || !isset($verify['caller_peer_id'])) {
    federation_router_problem(403, 'not_a_peer', 'published.json is a peer-only endpoint.', FEDERATION_PUBLISHED_PATH);
    return;
}

try {
    $list = federation_published_for_peer((int)$verify['caller_peer_id']);
} catch (Throwable $e) {
    error_log('pluriverse/published: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not enumerate published galaxies.', FEDERATION_PUBLISHED_PATH);
    return;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'protocol_version' => '1.0',
    'published' => $list,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
