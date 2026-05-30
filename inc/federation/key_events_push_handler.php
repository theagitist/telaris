<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/key-events-push
 *
 * Receives Pluriverse-coord-signed key rotation events: scheduled_rotation,
 * operational_rotation, compromise, revocation, coord_rotation. The body is
 * a JSON object carrying a JWS-Compact-serialized envelope at "envelope".
 *
 * The HTTP layer is signed by the Pluriverse coordination key (tag =
 * tel-key-events); the inner JWS envelope is also signed by the coord key,
 * giving a transport-layer + content-layer proof of authority.
 *
 * Side effects per event_type:
 *   - coord_rotation: swap cached coord key (4c handles it).
 *   - compromise: update peers.public_key for the named origin, clear
 *     previous_public_key (zero grace).
 *   - scheduled_rotation / operational_rotation: update peers.public_key,
 *     keep peers.previous_public_key set to the prior value with the
 *     standard 30-day grace.
 *   - revocation: flip peers.trust_state = 'blocked', record
 *     local_blacklisted_reason = 'pluriverse-revoked', then uniformly drop
 *     every mirror from that peer (6c; see key_events_apply.php).
 *
 * Idempotent on replay: INSERT IGNORE into key_events keyed on
 * (origin_host, occurred_at, event_type) per v10 § Sequence-gap
 * detection + UNIQUE constraint at the schema level (added defensively
 * here at runtime since v10 line 1255 covers it).
 *
 * Spec: P2P federation plan v10 § Key management (line 816+),
 *       § Pluriverse coordination key rotation (line 836+).
 */

require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/jws.php';
require_once __DIR__ . '/coord_key.php';
require_once __DIR__ . '/key_events_apply.php';

$body = (string)file_get_contents('php://input');
$request = [
    'method' => 'POST',
    'target_uri' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . ($_SERVER['REQUEST_URI'] ?? '/api/pluriverse/key-events-push'),
    'headers' => federation_handler_collect_headers_kep(),
    'body' => $body,
];

$verify = federation_verify_inbound($request, 'tel-key-events');
if (!$verify['ok']) {
    federation_router_problem(
        401,
        $verify['reason'],
        'HTTP Signature verification failed: ' . $verify['reason'],
        '/api/pluriverse/key-events-push',
    );
    return;
}
if ($verify['caller_kind'] !== 'coord') {
    federation_router_problem(
        401,
        'wrong_caller_kind',
        'Only Pluriverse-coord-signed requests may invoke this endpoint.',
        '/api/pluriverse/key-events-push',
    );
    return;
}

try {
    $parsed = json_decode($body, true, 6, JSON_THROW_ON_ERROR);
} catch (JsonException $_) {
    federation_router_problem(400, 'malformed_json', 'Request body is not valid JSON.', '/api/pluriverse/key-events-push');
    return;
}
$envelope = is_array($parsed) ? (string)($parsed['envelope'] ?? '') : '';
if ($envelope === '') {
    federation_router_problem(400, 'missing_envelope', 'Request body must include a JWS envelope.', '/api/pluriverse/key-events-push');
    return;
}

$coordPub = federation_coord_key_get(allowTofu: false);
if ($coordPub === null) {
    federation_router_problem(503, 'no_cached_coord_key', 'Instance has not pinned the Pluriverse coord key yet.', '/api/pluriverse/key-events-push');
    return;
}

$jws = federation_jws_verify($envelope, $coordPub, FEDERATION_COORD_KEY_EVENT_TYP);
if (!$jws['valid']) {
    // Retry against the previous-grace slot.
    $prev = federation_coord_key_get_previous();
    if ($prev !== null) {
        $jws = federation_jws_verify($envelope, $prev, FEDERATION_COORD_KEY_EVENT_TYP);
    }
    if (!$jws['valid']) {
        federation_router_problem(401, 'jws_invalid:' . $jws['reason'], 'JWS envelope verification failed.', '/api/pluriverse/key-events-push');
        return;
    }
}

$payload = $jws['payload'] ?? [];
if (!is_array($payload)) {
    federation_router_problem(400, 'malformed_payload', 'JWS payload must be an object.', '/api/pluriverse/key-events-push');
    return;
}

$eventType = (string)($payload['event_type'] ?? '');
$validTypes = ['scheduled_rotation', 'operational_rotation', 'compromise', 'revocation', 'coord_rotation'];
if (!in_array($eventType, $validTypes, true)) {
    // v10 § Unknown type handling: unknown event_type values MUST be rejected
    // (security-relevant, distinct from the unknown-message-type rule).
    federation_router_problem(400, 'unknown_event_type', 'Refusing unrecognized event_type: ' . $eventType, '/api/pluriverse/key-events-push');
    return;
}

if ($eventType === 'coord_rotation') {
    $result = federation_coord_key_apply_rotation($envelope);
    if (!$result['ok']) {
        $code = $result['reason'];
        if ($code === 'already_applied') {
            // Already processed; treat as success (idempotent push).
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'status' => 'already_applied'], JSON_UNESCAPED_SLASHES);
            return;
        }
        federation_router_problem(400, $code, 'Coord rotation refused: ' . $code, '/api/pluriverse/key-events-push');
        return;
    }
    federation_key_events_insert($payload, $envelope);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'status' => 'rotated', 'new_fingerprint' => $result['new_fingerprint'] ?? null], JSON_UNESCAPED_SLASHES);
    return;
}

$originHost = strtolower(trim((string)($payload['origin_host'] ?? '')));
if ($originHost === '') {
    federation_router_problem(400, 'missing_origin_host', 'Per-peer key events must carry an origin_host.', '/api/pluriverse/key-events-push');
    return;
}

$applyResult = federation_key_events_apply_peer_event($eventType, $originHost, $payload);
federation_key_events_insert($payload, $envelope);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo json_encode([
    'ok' => true,
    'status' => 'applied',
    'event_type' => $eventType,
    'origin_host' => $originHost,
    'applied' => $applyResult,
], JSON_UNESCAPED_SLASHES);

// --- helpers --------------------------------------------------------------

function federation_handler_collect_headers_kep(): array {
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

function federation_key_events_insert(array $payload, string $envelope): void {
    db_ensure_key_events_table();
    $stmt = getDB()->prepare("
        INSERT IGNORE INTO key_events
            (origin_host, event_type, occurred_at, signed_payload, received_via)
        VALUES (:o, :e, :t, :s, 'push')
    ");
    $eventType = (string)($payload['event_type'] ?? '');
    $origin = (string)($payload['origin_host'] ?? ($eventType === 'coord_rotation' ? 'www.telaris.ca' : ''));
    $occurredRaw = (string)($payload['occurred_at'] ?? '');
    $ts = $occurredRaw !== '' ? strtotime($occurredRaw) : false;
    $stmt->execute([
        ':o' => $origin,
        ':e' => $eventType,
        ':t' => $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'),
        ':s' => $envelope,
    ]);
}

// federation_key_events_apply_peer_event lives in key_events_apply.php (split
// out so the apply logic is testable without invoking this HTTP handler).
