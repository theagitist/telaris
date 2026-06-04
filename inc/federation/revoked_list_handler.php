<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/revoked.json
 *
 * The per-peer publish-revocation digest: a signed JWS listing the slugs this
 * instance has revoked for the calling peer (galaxies removed from that peer's
 * publish whitelist). The subscriber uses it to tell a deliberate per-peer
 * un-publish (DROP the mirror) from a benign disappearance (fossilize).
 *
 * Peer-scoped (the signature identifies the caller; the response is built for
 * that peer only) and origin-signed (a revocation is a drop signal, so it must
 * not be forgeable over the wire). The signed payload binds origin + recipient
 * + generated_at; the subscriber verifies before dropping anything.
 *
 * Spec: BACKLOG ^fed-revoke-vs-withdraw-diff; v10 § State-change propagation.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/publish_revocation.php';

const FEDERATION_REVOKED_LIST_PATH = '/api/pluriverse/revoked.json';

// Rate limit 60 req/min/IP (APCu), matching the other peer-read endpoints.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_revoked_list:' . date('YmdHi') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many revoked.json requests this minute; retry shortly.', FEDERATION_REVOKED_LIST_PATH);
        return;
    }
}

$verify = federation_verify_inbound(federation_build_inbound_request('GET'), 'tel-pull');
if (!$verify['ok']) {
    federation_router_problem(401, $verify['reason'], 'HTTP Signature verification failed: ' . $verify['reason'], FEDERATION_REVOKED_LIST_PATH);
    return;
}
if (($verify['caller_kind'] ?? '') !== 'peer' || !isset($verify['caller_peer_id'])) {
    federation_router_problem(403, 'not_a_peer', 'revoked.json is a peer-only endpoint.', FEDERATION_REVOKED_LIST_PATH);
    return;
}

$peerId = (int)$verify['caller_peer_id'];
try {
    $hostStmt = getDB()->prepare("SELECT hostname FROM peers WHERE id = :p LIMIT 1");
    $hostStmt->execute([':p' => $peerId]);
    $recipientHost = (string)($hostStmt->fetchColumn() ?: '');
    if ($recipientHost === '') {
        federation_router_problem(403, 'not_a_peer', 'Caller is not a known peer.', FEDERATION_REVOKED_LIST_PATH);
        return;
    }
    $jws = federation_publish_revocation_build_signed($peerId, $recipientHost);
} catch (Throwable $e) {
    error_log('pluriverse/revoked.json: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not build the revocation list.', FEDERATION_REVOKED_LIST_PATH);
    return;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'protocol_version' => FEDERATION_PUBLISH_REVOCATION_PROTOCOL,
    'revocations_jws' => $jws,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
