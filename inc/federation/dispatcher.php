<?php
declare(strict_types=1);

/**
 * Stage 4d: outbound message dispatcher.
 *
 * Reads rows from `pluriverse_messages` where delivery_status IN ('pending',
 * 'failed') AND next_attempt_at <= NOW(), signs each as an HTTP-Sig POST,
 * and ships it to the recipient. The retry schedule mirrors v10's handshake
 * exponential backoff: 1m -> 5m -> 30m -> 2h -> 6h -> 12h -> 24h -> give up.
 *
 * Two delivery shapes:
 *   - "relay": message_type = handshake_request (round 1, before any direct
 *     channel exists). POSTed to www.telaris.ca/api/pluriverse/relay,
 *     signed by THIS instance's key with tag = tel-relay. The Pluriverse
 *     unwraps and forwards.
 *   - "direct": every other outbound message. POSTed straight to
 *     <peer.hostname>/api/pluriverse/{messages,handshake}, signed by this
 *     instance's key with tag = tel-message (or tel-handshake for round 3
 *     replies). v10 line 458 + 466 cover both.
 *
 * Spec: P2P federation plan v10 § The handshake → Timeout and cancellation
 *       (line 345+) for the retry schedule.
 *       § Pluriverse protocol → Instance-side endpoint catalogue (line 457)
 *       for the relay endpoint.
 */

require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/http_sig.php';
require_once __DIR__ . '/sig_verify.php';

const FEDERATION_DISPATCH_RELAY_URL = 'https://www.telaris.ca/api/pluriverse/relay';
const FEDERATION_DISPATCH_BLACKLIST_NOTICE_URL = 'https://www.telaris.ca/api/pluriverse/peer-blacklist-notice';
const FEDERATION_DISPATCH_BATCH_SIZE = 50;
const FEDERATION_DISPATCH_MAX_ATTEMPTS = 7;
const FEDERATION_DISPATCH_HTTP_TIMEOUT = 15;
const FEDERATION_DISPATCH_HTTP_CONNECT_TIMEOUT = 5;

/** Backoff schedule in seconds; index = attempt count just finished. */
const FEDERATION_DISPATCH_BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600, 43200, 86400];

/**
 * Process a single batch of pending outbound rows. Returns a summary.
 *
 * @return array{processed:int, delivered:int, failed:int, given_up:int}
 */
function federation_dispatcher_process_batch(int $batchSize = FEDERATION_DISPATCH_BATCH_SIZE): array {
    db_ensure_pluriverse_messages_table();
    db_ensure_pluriverse_messages_retry_columns();

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, peer_id, direction, thread_id, message_type, jws_envelope,
               attempt_count
        FROM pluriverse_messages
        WHERE direction = 'outbound'
          AND delivery_status IN ('pending', 'failed')
          AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
        ORDER BY id ASC
        LIMIT :n
    ");
    $stmt->bindValue(':n', max(1, $batchSize), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'given_up' => 0];
    foreach ($rows as $row) {
        $summary['processed']++;
        $r = federation_dispatcher_deliver_one((int)$row['id']);
        if ($r['delivered']) {
            $summary['delivered']++;
        } elseif (($r['status'] ?? '') === 'given_up') {
            $summary['given_up']++;
        } else {
            $summary['failed']++;
        }
    }
    return $summary;
}

/**
 * Deliver a single outbound message row. Updates the row in place. Returns
 * ['delivered' => bool, 'status' => string, 'http_status' => ?int, 'error' => ?string].
 */
function federation_dispatcher_deliver_one(int $messageId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT m.id, m.peer_id, m.thread_id, m.message_type, m.jws_envelope, m.attempt_count,
               p.hostname AS peer_hostname
        FROM pluriverse_messages m
        LEFT JOIN peers p ON p.id = m.peer_id
        WHERE m.id = :id AND m.direction = 'outbound'
        LIMIT 1
    ");
    $stmt->execute([':id' => $messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['delivered' => false, 'status' => 'not_found', 'http_status' => null, 'error' => null];

    $deliveryShape = federation_dispatcher_classify_delivery($row);
    if ($deliveryShape === null) {
        federation_dispatcher_advance($messageId, false, null, 'no_target_resolvable');
        return ['delivered' => false, 'status' => 'failed', 'http_status' => null, 'error' => 'no_target_resolvable'];
    }
    [$targetUri, $tag, $bodyShape] = $deliveryShape;

    try {
        $secret = federation_load_secret_key();
    } catch (Throwable $e) {
        federation_dispatcher_advance($messageId, false, null, 'identity_unavailable');
        return ['delivered' => false, 'status' => 'failed', 'http_status' => null, 'error' => 'identity_unavailable'];
    }
    $public = federation_derive_public_key($secret);
    $fingerprint = federation_compute_fingerprint($public);
    $localHostname = federation_dispatcher_local_hostname();

    [$delivered, $httpStatus, $error] = federation_dispatcher_http_post(
        targetUri: $targetUri,
        body: $bodyShape,
        tag: $tag,
        keyid: $localHostname . ':' . $fingerprint,
        secret: $secret,
    );

    if ($delivered) {
        federation_dispatcher_advance($messageId, true, $httpStatus, null);
        // Round 3 ("complete") just reached the peer: the initiator can now
        // leave accepted_awaiting_complete for complete (both sides hold all
        // keys). No-op for any other delivered message.
        if ((string)$row['message_type'] === 'handshake_response'
            && function_exists('handshake_mark_initiator_complete_on_delivery')) {
            handshake_mark_initiator_complete_on_delivery($messageId);
        }
    } else {
        federation_dispatcher_advance($messageId, false, $httpStatus, $error);
    }
    $status = $delivered ? 'delivered' : ((int)$row['attempt_count'] + 1 >= FEDERATION_DISPATCH_MAX_ATTEMPTS ? 'given_up' : 'failed');
    return ['delivered' => $delivered, 'status' => $status, 'http_status' => $httpStatus, 'error' => $error];
}

/**
 * Map an outbound row to its delivery shape. Returns null if we cannot
 * resolve a target (e.g. handshake_request with no peer_id and no
 * recipient hostname in the envelope — should not happen but defensive).
 *
 * @return array{0:string,1:string,2:string}|null [targetUri, tag, bodyString]
 */
function federation_dispatcher_classify_delivery(array $row): ?array {
    $messageType = (string)$row['message_type'];
    $envelope = (string)$row['jws_envelope'];

    if ($messageType === 'handshake_request') {
        // Round 1: relay through the Pluriverse. Body is a JSON object wrapping
        // recipient_hostname + the original message envelope, signed at the
        // HTTP layer with tel-relay.
        $recipient = federation_dispatcher_extract_recipient_host($envelope);
        if ($recipient === null) return null;
        $bodyJson = json_encode([
            'recipient_hostname' => $recipient,
            'envelope' => $envelope,
        ], JSON_UNESCAPED_SLASHES);
        return [FEDERATION_DISPATCH_RELAY_URL, 'tel-relay', (string)$bodyJson];
    }

    if ($messageType === 'handshake_response') {
        // Round 2 + 3: directly to peer's /api/pluriverse/handshake. Body is the
        // plain inner payload (NOT the JWS envelope - the handshake endpoint
        // uses HTTP Signatures only at the transport layer; the JWS lives in
        // pluriverse_messages.jws_envelope as audit material).
        $peerHost = (string)($row['peer_hostname'] ?? '');
        if ($peerHost === '') return null;
        $inner = federation_dispatcher_extract_inner_payload($envelope);
        if ($inner === null) return null;
        $body = is_array($inner['payload']) ? $inner['payload'] : [];
        $body['thread_id'] = $inner['thread_id'] ?? (string)$row['thread_id'];
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        return ['https://' . $peerHost . '/api/pluriverse/handshake', 'tel-handshake', (string)$bodyJson];
    }

    if ($messageType === 'peer_blacklist_notice') {
        // 6e: advisory notice to the Pluriverse coord. The body is plain JSON
        // signed only at the HTTP layer with tel-bl-notice; jws_envelope holds
        // that JSON body (not a JWS). The reporter is asserted by the keyid the
        // dispatcher signs with, never the body.
        return [FEDERATION_DISPATCH_BLACKLIST_NOTICE_URL, 'tel-bl-notice', $envelope];
    }

    // Default: freeform / typed message direct to peer /messages. Body is the
    // JWS envelope wrapped per v10 § Message envelope.
    $peerHost = (string)($row['peer_hostname'] ?? '');
    if ($peerHost === '') return null;
    $bodyJson = json_encode(['envelope' => $envelope], JSON_UNESCAPED_SLASHES);
    return ['https://' . $peerHost . '/api/pluriverse/messages', 'tel-message', (string)$bodyJson];
}

/**
 * @return array{0:bool,1:?int,2:?string} [delivered, http_status, error]
 */
function federation_dispatcher_http_post(string $targetUri, string $body, string $tag, string $keyid, string $secret): array {
    $headers = [
        'Host' => parse_url($targetUri, PHP_URL_HOST) ?: '',
        'Date' => gmdate('D, d M Y H:i:s') . ' GMT',
        'Content-Type' => 'application/json',
    ];
    $request = [
        'method' => 'POST',
        'target_uri' => $targetUri,
        'headers' => $headers,
        'body' => $body,
    ];

    $signed = federation_http_sig_sign($request, $secret, [
        'keyid' => $keyid,
        'tag' => $tag,
        'nonce' => federation_http_sig_generate_nonce(),
    ]);

    $headers['Content-Digest'] = $signed['content_digest'] ?? federation_http_sig_content_digest($body);
    $headers['Content-Length'] = (string)strlen($body);
    $headers['Signature-Input'] = $signed['signature_input'];
    $headers['Signature'] = $signed['signature'];

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = $k . ': ' . $v;
    }

    $ch = curl_init($targetUri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CONNECTTIMEOUT => FEDERATION_DISPATCH_HTTP_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => FEDERATION_DISPATCH_HTTP_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Telaris/4d dispatcher',
    ]);
    $resp = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return [false, null, 'curl:' . $err];
    }
    if ($httpStatus >= 200 && $httpStatus < 300) {
        return [true, $httpStatus, null];
    }
    return [false, $httpStatus, 'http_' . $httpStatus];
}

function federation_dispatcher_advance(int $messageId, bool $delivered, ?int $httpStatus, ?string $error): void {
    $pdo = getDB();
    if ($delivered) {
        $pdo->prepare("
            UPDATE pluriverse_messages
            SET delivery_status = 'delivered',
                next_attempt_at = NULL,
                last_attempt_error = NULL,
                attempt_count = attempt_count + 1
            WHERE id = :id
        ")->execute([':id' => $messageId]);
        return;
    }

    // Increment + decide whether to retry or give up.
    $row = $pdo->prepare("SELECT attempt_count FROM pluriverse_messages WHERE id = :id");
    $row->execute([':id' => $messageId]);
    $count = (int)$row->fetchColumn() + 1;
    if ($count >= FEDERATION_DISPATCH_MAX_ATTEMPTS) {
        $pdo->prepare("
            UPDATE pluriverse_messages
            SET delivery_status = 'given_up',
                next_attempt_at = NULL,
                last_attempt_error = :e,
                attempt_count = :c
            WHERE id = :id
        ")->execute([':e' => $error, ':c' => $count, ':id' => $messageId]);
        return;
    }
    $delay = FEDERATION_DISPATCH_BACKOFF_SECONDS[$count] ?? end(FEDERATION_DISPATCH_BACKOFF_SECONDS);
    $pdo->prepare("
        UPDATE pluriverse_messages
        SET delivery_status = 'failed',
            next_attempt_at = NOW() + (:d * INTERVAL '1 second'),
            last_attempt_error = :e,
            attempt_count = :c
        WHERE id = :id
    ")->execute([':d' => $delay, ':e' => $error, ':c' => $count, ':id' => $messageId]);
}

function federation_dispatcher_local_hostname(): string {
    // Delegated to the shared self-healing resolver (inc/federation/identity.php).
    return federation_local_hostname();
}

/**
 * Extract the recipient_host field from a JWS-wrapped handshake message.
 * Returns null on malformed input.
 */
function federation_dispatcher_extract_recipient_host(string $jws): ?string {
    $parts = explode('.', $jws);
    if (count($parts) !== 3) return null;
    $payloadB64 = $parts[1];
    $b64 = strtr($payloadB64, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) $b64 .= str_repeat('=', 4 - $pad);
    $raw = base64_decode($b64, true);
    if ($raw === false) return null;
    try {
        $obj = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
    } catch (JsonException $_) {
        return null;
    }
    if (!is_array($obj)) return null;
    $h = (string)($obj['recipient_host'] ?? '');
    return $h === '' ? null : $h;
}

/**
 * Extract the inner payload (and thread_id) from a JWS envelope. Used to
 * rebuild the round-3 inner JSON body the recipient endpoint expects.
 *
 * @return array{thread_id:?string, payload:array<string,mixed>}|null
 */
function federation_dispatcher_extract_inner_payload(string $jws): ?array {
    $parts = explode('.', $jws);
    if (count($parts) !== 3) return null;
    $b64 = strtr($parts[1], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) $b64 .= str_repeat('=', 4 - $pad);
    $raw = base64_decode($b64, true);
    if ($raw === false) return null;
    try {
        $obj = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $_) {
        return null;
    }
    if (!is_array($obj)) return null;
    return [
        'thread_id' => isset($obj['thread_id']) ? (string)$obj['thread_id'] : null,
        'payload' => is_array($obj['payload'] ?? null) ? $obj['payload'] : [],
    ];
}
