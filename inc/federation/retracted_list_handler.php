<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/retracted.json
 *
 * The per-peer retraction digest: a newest-first list of this instance's signed
 * retractions, each carrying its signed envelope so the peer can verify before
 * dropping a mirror. Behind the tel-pull per-peer verifier (the digest is a
 * convenience for subscribers; the per-slug envelopes are themselves public).
 * Not whitelist-scoped: retractions are inherently public.
 *
 * Spec: Stage 5 galaxy publish design (5c); v10 § State-change propagation.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/galaxy_retraction.php';

const FEDERATION_RETRACTED_LIST_PATH = '/api/pluriverse/retracted.json';

// Rate limit 60 req/min/IP (APCu), per the catalogue.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_retracted_list:' . date('YmdHi') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many retracted.json requests this minute; retry shortly.', FEDERATION_RETRACTED_LIST_PATH);
        return;
    }
}

$verify = federation_verify_inbound(federation_build_inbound_request('GET'), 'tel-pull');
if (!$verify['ok']) {
    federation_router_problem(401, $verify['reason'], 'HTTP Signature verification failed: ' . $verify['reason'], FEDERATION_RETRACTED_LIST_PATH);
    return;
}
if (($verify['caller_kind'] ?? '') !== 'peer' || !isset($verify['caller_peer_id'])) {
    federation_router_problem(403, 'not_a_peer', 'retracted.json is a peer-only endpoint.', FEDERATION_RETRACTED_LIST_PATH);
    return;
}

try {
    $list = federation_recent_retractions();
} catch (Throwable $e) {
    error_log('pluriverse/retracted.json: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not enumerate retractions.', FEDERATION_RETRACTED_LIST_PATH);
    return;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'protocol_version' => '1.0',
    'retracted' => $list,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
