<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/messages
 *
 * Inbound in-app message endpoint. Dual-tag per v10 § Dual-tag endpoints:
 *   - tel-message: a peer-to-peer message signed by the remote's instance
 *     key. Caller is a peer (caller_kind = 'peer'). Used for freeform,
 *     handshake_response, retraction_notice, whitelist_request, etc., after
 *     the peer-to-peer channel has been established.
 *   - tel-relay: a Pluriverse-relayed first-contact message signed by the
 *     coord key. Caller is the Pluriverse (caller_kind = 'coord'). Used for
 *     handshake_request (round 1) and any reach-through case where the
 *     peer-to-peer channel doesn't exist yet.
 *
 * Body shape (v10 § Message envelope, line 388+):
 *   { "envelope": "<JWS Compact Serialization>" }
 *
 * JWS payload carries the structured message: protocol_version, sender_host,
 * recipient_host, sent_at, thread_id, message_type, subject, body, payload,
 * references.
 *
 * Verification ordering (v10 § Verification on receipt, line 429+):
 *   1. Body size bounds (256 KB / 64 KB / 200 KB / 32 KB / 50 entries).
 *   2. HTTP Signature (the middleware does this before we land here).
 *   3. JWS signature on the envelope.
 *   4. recipient_host MUST match our hostname.
 *   5. sent_at within ±300s.
 *   6. On relay path: inner JWS sender_host must NOT be www.telaris.ca
 *      and the inner JWS kid host MUST match sender_host (defence against
 *      relay replaying one peer's message as another's).
 *
 * Spec: P2P federation plan v10 § In-app messaging.
 */

require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/jws.php';
require_once __DIR__ . '/coord_key.php';
require_once __DIR__ . '/handshake.php';

$rawBody = (string)file_get_contents('php://input');
if (strlen($rawBody) > 256 * 1024) {
    federation_router_problem(413, 'body_too_large', 'Request body exceeds 256 KB.', '/api/pluriverse/messages');
    return;
}

$request = [
    'method' => 'POST',
    'target_uri' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . ($_SERVER['REQUEST_URI'] ?? '/api/pluriverse/messages'),
    'headers' => federation_messages_collect_headers(),
    'body' => $rawBody,
];

$verify = federation_verify_inbound(
    $request,
    ['tel-message', 'tel-relay'],
    ['dual_tag_map' => ['peer' => 'tel-message', 'coord' => 'tel-relay']],
);
if (!$verify['ok']) {
    federation_router_problem(
        401,
        $verify['reason'],
        'HTTP Signature verification failed: ' . $verify['reason'],
        '/api/pluriverse/messages',
    );
    return;
}

try {
    $parsed = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $_) {
    federation_router_problem(400, 'malformed_json', 'Request body is not valid JSON.', '/api/pluriverse/messages');
    return;
}
if (!is_array($parsed) || !isset($parsed['envelope']) || !is_string($parsed['envelope'])) {
    federation_router_problem(400, 'missing_envelope', 'Request body must include an "envelope" JWS string.', '/api/pluriverse/messages');
    return;
}
$envelope = $parsed['envelope'];
if (strlen($envelope) > 64 * 1024) {
    federation_router_problem(413, 'envelope_too_large', 'JWS envelope exceeds 64 KB.', '/api/pluriverse/messages');
    return;
}

// Resolve the public key for the JWS layer. On the coord path, the JWS is
// signed by the coord key (same as the outer HTTP layer). On the peer path,
// the JWS is signed by the peer's instance key (matches the outer caller).
$jwsKid = federation_messages_extract_jws_kid($envelope);
if ($jwsKid === null) {
    federation_router_problem(400, 'malformed_envelope_header', 'JWS envelope header is malformed or missing kid.', '/api/pluriverse/messages');
    return;
}
$kidParts = federation_jws_split_kid($jwsKid);
if ($kidParts === null) {
    federation_router_problem(400, 'malformed_kid', 'JWS kid must be "<host>:<fingerprint>".', '/api/pluriverse/messages');
    return;
}
$jwsSignerHost = $kidParts['host'];

if ($verify['caller_kind'] === 'coord') {
    // Relay path. The Pluriverse forwarded a message that originated on
    // another instance. The inner JWS kid must NOT be the Pluriverse itself
    // (relay defending against forging messages as someone else), and it
    // must match a peer we know about.
    if (strtolower($jwsSignerHost) === FEDERATION_PLURIVERSE_HOSTNAME) {
        federation_router_problem(400, 'relay_inner_signer_is_coord', 'Relay-forwarded messages must carry an inner signer that is NOT the Pluriverse.', '/api/pluriverse/messages');
        return;
    }
    $resolvedSigner = federation_resolve_signing_key($jwsSignerHost, $kidParts['fingerprint']);
    if ($resolvedSigner === null) {
        federation_router_problem(401, 'unknown_inner_signer', 'Inner JWS signer is not a known peer; cannot verify originator.', '/api/pluriverse/messages');
        return;
    }
    $jwsPub = $resolvedSigner['public_key'];
} else {
    // Direct peer path. Caller's HTTP-Sig key must match the inner JWS kid.
    if (strtolower($jwsSignerHost) !== strtolower((string)$verify['caller_host'])) {
        federation_router_problem(400, 'relay_attack_inner_outer_mismatch', 'Inner JWS signer does not match outer HTTP caller.', '/api/pluriverse/messages');
        return;
    }
    // The middleware already resolved the same public key for the HTTP layer;
    // re-resolve for JWS so we honour rotation grace identically.
    $resolvedSigner = federation_resolve_signing_key($jwsSignerHost, $kidParts['fingerprint']);
    if ($resolvedSigner === null) {
        federation_router_problem(401, 'unknown_inner_signer', 'Inner JWS signer not resolvable.', '/api/pluriverse/messages');
        return;
    }
    $jwsPub = $resolvedSigner['public_key'];
}

$jws = federation_jws_verify($envelope, $jwsPub, 'application/vnd.telaris.pluriverse-message.v1+json');
if (!$jws['valid'] && isset($resolvedSigner['previous_public_key'])) {
    $jws = federation_jws_verify($envelope, $resolvedSigner['previous_public_key'], 'application/vnd.telaris.pluriverse-message.v1+json');
}
if (!$jws['valid']) {
    federation_router_problem(401, 'jws_invalid:' . $jws['reason'], 'Inner JWS verification failed.', '/api/pluriverse/messages');
    return;
}

$payload = $jws['payload'] ?? [];
if (!is_array($payload)) {
    federation_router_problem(400, 'malformed_payload', 'JWS payload is not a JSON object.', '/api/pluriverse/messages');
    return;
}

// Required-field checks per v10's payload schema.
foreach (['sender_host', 'recipient_host', 'sent_at', 'thread_id', 'message_type'] as $req) {
    if (!isset($payload[$req]) || (string)$payload[$req] === '') {
        federation_router_problem(400, 'missing_payload_field:' . $req, 'JWS payload missing required field: ' . $req, '/api/pluriverse/messages');
        return;
    }
}

$senderHost = strtolower((string)$payload['sender_host']);
$recipientHost = strtolower((string)$payload['recipient_host']);
$ourHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if (str_contains($ourHost, ':')) $ourHost = (string)strstr($ourHost, ':', true);
if ($recipientHost !== $ourHost) {
    federation_router_problem(400, 'wrong_recipient', 'Message recipient_host does not match this instance.', '/api/pluriverse/messages');
    return;
}

if (strtolower($senderHost) !== strtolower($jwsSignerHost)) {
    federation_router_problem(400, 'sender_kid_mismatch', 'sender_host in payload does not match inner JWS kid.', '/api/pluriverse/messages');
    return;
}

$sentTs = strtotime((string)$payload['sent_at']);
if ($sentTs === false || abs(time() - $sentTs) > 300) {
    federation_router_problem(400, 'sent_at_outside_skew', 'sent_at outside ±300s skew window.', '/api/pluriverse/messages');
    return;
}

$body = isset($payload['body']) ? (string)$payload['body'] : '';
$innerPayload = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : [];
if (strlen($body) > 200 * 1024) {
    federation_router_problem(413, 'body_field_too_large', 'body field exceeds 200 KB.', '/api/pluriverse/messages');
    return;
}
if (strlen(json_encode($innerPayload)) > 32 * 1024) {
    federation_router_problem(413, 'payload_field_too_large', 'payload field exceeds 32 KB.', '/api/pluriverse/messages');
    return;
}
if (isset($payload['references']) && is_array($payload['references']) && count($payload['references']) > 50) {
    federation_router_problem(413, 'too_many_references', 'references array exceeds 50 entries.', '/api/pluriverse/messages');
    return;
}

// Resolve / autovivify the peers row by sender_host. On the relay path,
// peer_id may end up NULL if the sender isn't in our directory yet; the
// admin Inbox surfaces it with a "not-in-directory" banner.
db_ensure_peers_table();
db_ensure_pluriverse_messages_table();
db_ensure_pluriverse_messages_retry_columns();

$peerRow = getDB()->prepare("SELECT id FROM peers WHERE hostname = :h LIMIT 1");
$peerRow->execute([':h' => $senderHost]);
$senderPeerId = $peerRow->fetchColumn();
$senderPeerId = $senderPeerId !== false ? (int)$senderPeerId : null;

$messageType = (string)$payload['message_type'];
$subject = isset($payload['subject']) ? (string)$payload['subject'] : null;
$threadId = (string)$payload['thread_id'];

$insert = getDB()->prepare("
    INSERT INTO pluriverse_messages
        (peer_id, direction, thread_id, message_type, subject, body, payload, jws_envelope, delivery_status)
    VALUES (:p, 'inbound', :t, :mt, :s, :b, :pl, :j, 'not_applicable')
");
$insert->execute([
    ':p' => $senderPeerId,
    ':t' => $threadId,
    ':mt' => $messageType,
    ':s' => $subject,
    ':b' => $body,
    ':pl' => json_encode($innerPayload, JSON_UNESCAPED_SLASHES),
    ':j' => $envelope,
]);
$messageId = (int)getDB()->lastInsertId();

// Handshake first-contact: relay-forwarded handshake_request creates the
// handshakes row in pending_our_response so the admin Inbox can surface it.
if ($messageType === 'handshake_request' && $verify['caller_kind'] === 'coord') {
    handshake_register_inbound_round1_via_relay($senderHost, $threadId, $innerPayload, $messageId);
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo json_encode([
    'ok' => true,
    'message_id' => $messageId,
    'thread_id' => $threadId,
    'received_via' => $verify['caller_kind'] === 'coord' ? 'relay' : 'direct',
], JSON_UNESCAPED_SLASHES);

// --- helpers --------------------------------------------------------------

function federation_messages_collect_headers(): array {
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (!is_string($k)) continue;
        if (str_starts_with($k, 'HTTP_')) {
            $name = str_replace('_', '-', strtolower(substr($k, 5)));
            $out[$name] = (string)$v;
        }
    }
    foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $sk => $name) {
        if (isset($_SERVER[$sk])) $out[$name] = (string)$_SERVER[$sk];
    }
    return $out;
}

function federation_messages_extract_jws_kid(string $jws): ?string {
    $parts = explode('.', $jws);
    if (count($parts) !== 3) return null;
    $b64 = strtr($parts[0], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) $b64 .= str_repeat('=', 4 - $pad);
    $raw = base64_decode($b64, true);
    if ($raw === false) return null;
    try {
        $obj = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
    } catch (JsonException $_) {
        return null;
    }
    if (!is_array($obj)) return null;
    $kid = (string)($obj['kid'] ?? '');
    return $kid === '' ? null : $kid;
}
