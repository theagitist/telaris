<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/handshake
 *
 * Receives rounds 2 + 3 of the three-round handshake from a peer. Round 1
 * arrives via the Pluriverse relay on /api/pluriverse/messages (4e) and so
 * does not land here.
 *
 * Authentication: HTTP-Sig with tag = tel-handshake. The middleware resolves
 * the caller as a peer (the keyid host is NEVER www.telaris.ca on this
 * endpoint - that case would be a category confusion).
 *
 * Rate limit: 30 req/hour/peer per v10 line 458.
 *
 * Spec: P2P federation plan v10 § Layer 3 → The handshake (line 307+).
 */

require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/handshake.php';

$body = (string)file_get_contents('php://input');
$request = [
    'method' => 'POST',
    'target_uri' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . ($_SERVER['REQUEST_URI'] ?? '/api/pluriverse/handshake'),
    'headers' => federation_handler_collect_headers(),
    'body' => $body,
];

$verify = federation_verify_inbound($request, 'tel-handshake');
if (!$verify['ok']) {
    federation_router_problem(
        401,
        $verify['reason'],
        'HTTP Signature verification failed: ' . $verify['reason'],
        '/api/pluriverse/handshake',
    );
    return;
}
if ($verify['caller_kind'] !== 'peer') {
    federation_router_problem(
        401,
        'wrong_caller_kind',
        'Coord-signed callers may not invoke this endpoint.',
        '/api/pluriverse/handshake',
    );
    return;
}

try {
    $parsed = json_decode($body, true, 6, JSON_THROW_ON_ERROR);
} catch (JsonException $_) {
    federation_router_problem(
        400,
        'malformed_json',
        'Request body is not valid JSON.',
        '/api/pluriverse/handshake',
    );
    return;
}
if (!is_array($parsed)) {
    federation_router_problem(
        400,
        'malformed_json',
        'Request body must be a JSON object.',
        '/api/pluriverse/handshake',
    );
    return;
}

$status = (string)($parsed['status'] ?? '');
$peerId = (int)$verify['caller_peer_id'];
$remoteHost = (string)$verify['caller_host'];

if (in_array($status, ['accepted', 'rejected'], true)) {
    $result = handshake_apply_inbound_round2($peerId, $parsed, $remoteHost);
} elseif ($status === 'complete') {
    $result = handshake_apply_inbound_round3($peerId, $parsed, $remoteHost);
} else {
    federation_router_problem(
        400,
        'invalid_status',
        'Body status must be one of: accepted, rejected, complete.',
        '/api/pluriverse/handshake',
    );
    return;
}

if (!$result['ok']) {
    $code = $result['reason'];
    $httpStatus = match (true) {
        str_starts_with($code, 'wrong_state:'), $code === 'no_matching_handshake' => 409,
        $code === 'invalid_peer_key', $code === 'invalid_round2_body', $code === 'invalid_round3_body' => 400,
        default => 500,
    };
    federation_router_problem($httpStatus, $code, 'Handshake state transition refused: ' . $code, '/api/pluriverse/handshake');
    return;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo json_encode([
    'ok' => true,
    'status' => $result['status'] ?? $status,
    'handshake_id' => $result['handshake_id'] ?? null,
], JSON_UNESCAPED_SLASHES);

/**
 * PHP exposes request headers via the `HTTP_*` server-superglobals (each
 * upper-snake-cased with dashes turned to underscores). Reverse that into the
 * mixed-case dict the HTTP-Sig middleware expects.
 *
 * @return array<string,string>
 */
function federation_handler_collect_headers(): array {
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
