<?php
declare(strict_types=1);

/**
 * Stage 4d: handshake state machine.
 *
 * Persistent state lives in two tables:
 *   - `handshakes` (created in 4a): one row per handshake, with FKs to
 *     pluriverse_messages for each round's JWS envelope.
 *   - `pluriverse_messages` (created in 1a, extended in 4a): the round-1 / 2 / 3
 *     envelopes ride here so the outbound retry queue (4d dispatcher) and
 *     the inbox UI (4e) can both reach them through one table.
 *
 * The library exposes three application-facing entry points:
 *
 *   handshake_initiate_outbound() — round 1, "I want to handshake with B".
 *       Used by the admin UI compose modal (4e). Builds the signed message
 *       envelope, INSERTs a handshakes row + a pluriverse_messages row with
 *       delivery_status='pending', returns the new IDs.
 *
 *   handshake_apply_inbound_round2() — receiver-side handler for B's reply
 *       on /api/pluriverse/handshake. Called by 4d's endpoint after the
 *       HTTP-Sig middleware has verified the caller.
 *
 *   handshake_apply_inbound_round3() — receiver-side handler for the final
 *       round on /api/pluriverse/handshake.
 *
 * Plus:
 *   handshake_register_inbound_round1_via_relay() — called by the /messages
 *       handler (4e) when a relay-forwarded handshake_request lands. Creates
 *       the handshakes row in `pending_our_response` so the admin Inbox can
 *       surface it for Accept / Reject / Defer.
 *   handshake_expire_stale() — cron sweep: handshakes past their 14-day
 *       expires_at flip to `expired`.
 *
 * Spec: P2P federation plan v10 § Layer 3: The bilateral whitelist (line 296+).
 */

require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/http_sig.php';
require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/jws.php';

const FEDERATION_HANDSHAKE_DEFAULT_EXPIRY_DAYS = 14;
const FEDERATION_HANDSHAKE_TYP = 'application/vnd.telaris.pluriverse-message.v1+json';
const FEDERATION_HANDSHAKE_PEER_KEY_BYTES = 32;

/**
 * Generate a fresh thread id (16 random bytes, base64url, no padding). Used
 * when the initiator creates a new handshake; round 2/3 replies reuse this
 * thread_id to pair messages back to the originating handshake.
 */
function handshake_generate_thread_id(): string {
    return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}

/**
 * Generate a fresh per-peer API key: 32 random bytes, base64-encoded for
 * wire transport. The local store hashes it with SHA-256 before persisting,
 * mirroring the bcrypt + api_keys pattern used elsewhere in the codebase
 * (one-way hash; the remote keeps the cleartext value).
 */
function handshake_generate_peer_key(): string {
    return base64_encode(random_bytes(FEDERATION_HANDSHAKE_PEER_KEY_BYTES));
}

/**
 * Round 1: build the outbound envelope + persist a handshakes row + queue an
 * outbound message row for the dispatcher.
 *
 * Returns
 *   ['ok' => true, 'handshake_id' => int, 'message_id' => int, 'thread_id' => string]
 * on success, or
 *   ['ok' => false, 'reason' => string]
 * on validation failure (most often: duplicate active handshake to the same
 * remote, or the secret key isn't loadable).
 *
 * @param array<int,string> $requestedPublish Galaxy slugs we want to publish to them.
 * @param array<int,string> $requestedSubscribe Galaxy slugs we want to subscribe to.
 */
function handshake_initiate_outbound(
    string $remoteHostname,
    string $bodyMarkdown,
    array $requestedPublish = [],
    array $requestedSubscribe = [],
    string $subject = '',
): array {
    $remoteHostname = strtolower(trim($remoteHostname));
    if ($remoteHostname === '') {
        return ['ok' => false, 'reason' => 'invalid_recipient_hostname'];
    }
    if ($bodyMarkdown === '') {
        return ['ok' => false, 'reason' => 'body_required'];
    }
    if (strlen($bodyMarkdown) > 200 * 1024) {
        return ['ok' => false, 'reason' => 'body_too_large'];
    }

    db_ensure_peers_table();
    db_ensure_pluriverse_messages_table();
    db_ensure_pluriverse_messages_retry_columns();
    db_ensure_handshakes_table();

    $pdo = getDB();
    $peerId = null;
    $stmt = $pdo->prepare("SELECT id FROM peers WHERE hostname = :h LIMIT 1");
    $stmt->execute([':h' => $remoteHostname]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $peerId = (int)$row['id'];

    $existing = $pdo->prepare("
        SELECT id FROM handshakes
        WHERE remote_hostname = :h
          AND status IN ('pending_their_response', 'pending_our_response', 'accepted_awaiting_complete')
        LIMIT 1
    ");
    $existing->execute([':h' => $remoteHostname]);
    if ($existing->fetchColumn() !== false) {
        return ['ok' => false, 'reason' => 'active_handshake_exists'];
    }

    try {
        $secret = federation_load_secret_key();
    } catch (Throwable $e) {
        error_log('handshake_initiate_outbound: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'instance_identity_unavailable'];
    }
    $public = federation_derive_public_key($secret);
    $fingerprint = federation_compute_fingerprint($public);
    $localHostname = handshake_local_hostname();

    $threadId = handshake_generate_thread_id();
    $sentAt = gmdate('Y-m-d\TH:i:s\Z');

    $payload = [
        'requested_galaxies_publish' => array_values(array_unique(array_map('strval', $requestedPublish))),
        'requested_galaxies_subscribe' => array_values(array_unique(array_map('strval', $requestedSubscribe))),
    ];

    $jws = handshake_build_signed_envelope(
        secret: $secret,
        kidHost: $localHostname,
        kidFingerprint: $fingerprint,
        senderHost: $localHostname,
        recipientHost: $remoteHostname,
        sentAt: $sentAt,
        threadId: $threadId,
        messageType: 'handshake_request',
        body: $bodyMarkdown,
        payload: $payload,
        subject: $subject,
    );

    $expiresAt = date('Y-m-d H:i:s', time() + FEDERATION_HANDSHAKE_DEFAULT_EXPIRY_DAYS * 86400);

    $pdo->beginTransaction();
    try {
        $msgStmt = $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, subject, body, payload, jws_envelope,
                 delivery_status, next_attempt_at)
            VALUES (:p, 'outbound', :t, 'handshake_request', :s, :b, :pl, :j, 'pending', NOW())
            RETURNING id
        ");
        $msgStmt->execute([
            ':p' => $peerId,
            ':t' => $threadId,
            ':s' => $subject !== '' ? $subject : null,
            ':b' => $bodyMarkdown,
            ':pl' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':j' => $jws,
        ]);
        $msgId = (int)$msgStmt->fetchColumn();

        $hsStmt = $pdo->prepare("
            INSERT INTO handshakes
                (peer_id, remote_hostname, initiator, status, requested_galaxies_publish,
                 requested_galaxies_subscribe, thread_id, initial_message_id, expires_at)
            VALUES (:p, :h, 'us', 'pending_their_response', :pubg, :subg, :t, :im, :e)
            RETURNING id
        ");
        $hsStmt->execute([
            ':p' => $peerId,
            ':h' => $remoteHostname,
            ':pubg' => json_encode($payload['requested_galaxies_publish']),
            ':subg' => json_encode($payload['requested_galaxies_subscribe']),
            ':t' => $threadId,
            ':im' => $msgId,
            ':e' => $expiresAt,
        ]);
        $hsId = (int)$hsStmt->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('handshake_initiate_outbound: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'persist_failed'];
    }

    return ['ok' => true, 'handshake_id' => $hsId, 'message_id' => $msgId, 'thread_id' => $threadId];
}

/**
 * Round 2 receiver-side handler. Called by /api/pluriverse/handshake after
 * federation_verify_inbound() has authenticated the caller as $peerId.
 *
 * Body shape per v10 line 322-327:
 *   status: 'accepted' | 'rejected'
 *   peer_key_for_caller: <base64 32 bytes>   (omitted on rejected)
 *   label: <remote's display label>
 *   supported_protocol_versions: list<string>
 *   thread_id: <matches our pending handshake>
 *   reason: <text>   (rejected only)
 */
function handshake_apply_inbound_round2(int $peerId, array $body, string $remoteHostname): array {
    db_ensure_handshakes_table();
    db_ensure_peer_keys_table();
    db_ensure_pluriverse_messages_table();
    db_ensure_pluriverse_messages_retry_columns();

    $status = (string)($body['status'] ?? '');
    $threadId = (string)($body['thread_id'] ?? '');
    if ($threadId === '' || !in_array($status, ['accepted', 'rejected'], true)) {
        return ['ok' => false, 'reason' => 'invalid_round2_body'];
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, status, peer_id FROM handshakes
        WHERE thread_id = :t AND remote_hostname = :h
        LIMIT 1
    ");
    $stmt->execute([':t' => $threadId, ':h' => $remoteHostname]);
    $hs = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hs) {
        return ['ok' => false, 'reason' => 'no_matching_handshake'];
    }
    if ($hs['status'] !== 'pending_their_response') {
        return ['ok' => false, 'reason' => 'wrong_state:' . $hs['status']];
    }

    $pdo->beginTransaction();
    try {
        // Persist the round-2 message envelope (inbound, delivery_status n/a).
        $msgStmt = $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, body, payload, jws_envelope, delivery_status)
            VALUES (:p, 'inbound', :t, 'handshake_response', :b, :pl, :j, 'not_applicable')
            RETURNING id
        ");
        $msgStmt->execute([
            ':p' => $peerId,
            ':t' => $threadId,
            ':b' => isset($body['reason']) ? (string)$body['reason'] : null,
            ':pl' => json_encode($body, JSON_UNESCAPED_SLASHES),
            ':j' => isset($body['_envelope']) ? (string)$body['_envelope'] : '',
        ]);
        $msgId = (int)$msgStmt->fetchColumn();

        if ($status === 'rejected') {
            $pdo->prepare("
                UPDATE handshakes
                SET status = 'rejected', response_message_id = :m, reject_reason = :r,
                    peer_id = COALESCE(peer_id, :p)
                WHERE id = :id
            ")->execute([
                ':m' => $msgId,
                ':r' => (string)($body['reason'] ?? ''),
                ':p' => $peerId,
                ':id' => $hs['id'],
            ]);
            $pdo->prepare("
                UPDATE peers SET trust_state = 'discovered' WHERE id = :p
            ")->execute([':p' => $peerId]);
            $pdo->commit();
            return ['ok' => true, 'status' => 'rejected', 'handshake_id' => (int)$hs['id']];
        }

        // status === 'accepted'.
        $theirKey = (string)($body['peer_key_for_caller'] ?? '');
        $theirKeyBytes = base64_decode($theirKey, true);
        if ($theirKeyBytes === false || strlen($theirKeyBytes) !== FEDERATION_HANDSHAKE_PEER_KEY_BYTES) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'invalid_peer_key'];
        }

        // The key they sent is what THEY call US with (when they want to talk
        // to us). Direction = 'they_call_us' from our perspective.
        $pdo->prepare("
            INSERT INTO peer_keys (peer_id, api_key_hash, direction, is_active)
            VALUES (:p, :h, 'they_call_us', TRUE)
        ")->execute([':p' => $peerId, ':h' => hash('sha256', $theirKey, true)]);

        // Generate our key for them (the one WE call THEM with) and queue
        // the round-3 outbound message.
        $ourKey = handshake_generate_peer_key();
        $pdo->prepare("
            INSERT INTO peer_keys (peer_id, api_key_hash, direction, is_active)
            VALUES (:p, :h, 'we_call_them', TRUE)
        ")->execute([':p' => $peerId, ':h' => hash('sha256', $ourKey, true)]);

        try {
            $secret = federation_load_secret_key();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'instance_identity_unavailable'];
        }
        $public = federation_derive_public_key($secret);
        $fingerprint = federation_compute_fingerprint($public);
        $localHostname = handshake_local_hostname();

        $round3Body = [
            'status' => 'complete',
            'thread_id' => $threadId,
            'peer_key_for_caller' => $ourKey,
            'label' => handshake_local_label(),
            'supported_protocol_versions' => ['1.0'],
        ];
        $r3Envelope = handshake_build_signed_envelope(
            secret: $secret,
            kidHost: $localHostname,
            kidFingerprint: $fingerprint,
            senderHost: $localHostname,
            recipientHost: $remoteHostname,
            sentAt: gmdate('Y-m-d\TH:i:s\Z'),
            threadId: $threadId,
            messageType: 'handshake_response',
            body: 'complete',
            payload: $round3Body,
        );
        $r3Stmt = $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, body, payload, jws_envelope,
                 delivery_status, next_attempt_at)
            VALUES (:p, 'outbound', :t, 'handshake_response', 'complete', :pl, :j,
                    'pending', NOW())
            RETURNING id
        ");
        $r3Stmt->execute([
            ':p' => $peerId,
            ':t' => $threadId,
            ':pl' => json_encode($round3Body, JSON_UNESCAPED_SLASHES),
            ':j' => $r3Envelope,
        ]);
        $r3MsgId = (int)$r3Stmt->fetchColumn();

        $pdo->prepare("
            UPDATE handshakes
            SET status = 'accepted_awaiting_complete',
                response_message_id = :m,
                complete_message_id = :c,
                peer_id = COALESCE(peer_id, :p)
            WHERE id = :id
        ")->execute([
            ':m' => $msgId,
            ':c' => $r3MsgId,
            ':p' => $peerId,
            ':id' => $hs['id'],
        ]);
        $pdo->prepare("
            UPDATE peers
            SET trust_state = 'whitelisted'
            WHERE id = :p
        ")->execute([':p' => $peerId]);

        $pdo->commit();
        return [
            'ok' => true,
            'status' => 'accepted',
            'handshake_id' => (int)$hs['id'],
            'queued_round3_message_id' => $r3MsgId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('handshake_apply_inbound_round2: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'persist_failed'];
    }
}

/**
 * Round 3 receiver-side handler. Called by /api/pluriverse/handshake when the
 * initiator (us in this leg's perspective) gets the final "complete" back.
 *
 * Body shape:
 *   status: 'complete'
 *   thread_id: <ours>
 *   peer_key_for_caller: <base64 32 bytes>  (the key THEY want US to use when calling THEM)
 *   label: <remote label>
 *   supported_protocol_versions: list<string>
 */
function handshake_apply_inbound_round3(int $peerId, array $body, string $remoteHostname): array {
    db_ensure_handshakes_table();
    db_ensure_peer_keys_table();
    db_ensure_pluriverse_messages_table();
    db_ensure_pluriverse_messages_retry_columns();

    $threadId = (string)($body['thread_id'] ?? '');
    if ($threadId === '' || (string)($body['status'] ?? '') !== 'complete') {
        return ['ok' => false, 'reason' => 'invalid_round3_body'];
    }

    $theirKey = (string)($body['peer_key_for_caller'] ?? '');
    $theirKeyBytes = base64_decode($theirKey, true);
    if ($theirKeyBytes === false || strlen($theirKeyBytes) !== FEDERATION_HANDSHAKE_PEER_KEY_BYTES) {
        return ['ok' => false, 'reason' => 'invalid_peer_key'];
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, status, peer_id FROM handshakes
        WHERE thread_id = :t AND remote_hostname = :h
        LIMIT 1
    ");
    $stmt->execute([':t' => $threadId, ':h' => $remoteHostname]);
    $hs = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hs) {
        return ['ok' => false, 'reason' => 'no_matching_handshake'];
    }
    if ($hs['status'] !== 'accepted_awaiting_complete') {
        return ['ok' => false, 'reason' => 'wrong_state:' . $hs['status']];
    }

    $pdo->beginTransaction();
    try {
        $msgStmt = $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, body, payload, jws_envelope, delivery_status)
            VALUES (:p, 'inbound', :t, 'handshake_response', 'complete', :pl, :j, 'not_applicable')
            RETURNING id
        ");
        $msgStmt->execute([
            ':p' => $peerId,
            ':t' => $threadId,
            ':pl' => json_encode($body, JSON_UNESCAPED_SLASHES),
            ':j' => isset($body['_envelope']) ? (string)$body['_envelope'] : '',
        ]);
        $msgId = (int)$msgStmt->fetchColumn();

        $pdo->prepare("
            INSERT INTO peer_keys (peer_id, api_key_hash, direction, is_active)
            VALUES (:p, :h, 'they_call_us', TRUE)
        ")->execute([':p' => $peerId, ':h' => hash('sha256', $theirKey, true)]);

        $pdo->prepare("
            UPDATE handshakes
            SET status = 'complete',
                complete_message_id = :m,
                peer_id = COALESCE(peer_id, :p)
            WHERE id = :id
        ")->execute([
            ':m' => $msgId,
            ':p' => $peerId,
            ':id' => $hs['id'],
        ]);
        $pdo->prepare("
            UPDATE peers SET trust_state = 'whitelisted'
            WHERE id = :p
        ")->execute([':p' => $peerId]);

        $pdo->commit();
        return ['ok' => true, 'status' => 'complete', 'handshake_id' => (int)$hs['id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('handshake_apply_inbound_round3: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'persist_failed'];
    }
}

/**
 * Transition the INITIATOR's handshake from accepted_awaiting_complete to
 * complete once its round-3 ("complete") message has actually been delivered
 * to the peer. The initiator already holds both per-peer keys and has set the
 * peer to whitelisted when it queued round 3; the only thing it was waiting on
 * was confirmation that round 3 reached the peer (so the peer also holds the
 * initiator's key). The dispatcher calls this on successful delivery of the
 * message whose id equals the handshake's complete_message_id.
 *
 * Idempotent: matches only an accepted_awaiting_complete initiator row, so a
 * re-delivery or a responder row (which is already 'complete') is a no-op.
 * Returns true if a row transitioned.
 */
function handshake_mark_initiator_complete_on_delivery(int $deliveredMessageId): bool {
    db_ensure_handshakes_table();
    $stmt = getDB()->prepare("
        UPDATE handshakes
        SET status = 'complete'
        WHERE complete_message_id = :m
          AND initiator = 'us'
          AND status = 'accepted_awaiting_complete'
    ");
    $stmt->execute([':m' => $deliveredMessageId]);
    return $stmt->rowCount() > 0;
}

/**
 * Called by the /api/pluriverse/messages handler (4e) when a relay-forwarded
 * handshake_request lands. Creates the handshakes row in pending_our_response
 * so the admin inbox can surface it.
 */
function handshake_register_inbound_round1_via_relay(
    string $remoteHostname,
    string $threadId,
    array $payload,
    int $messageId,
): array {
    db_ensure_handshakes_table();
    $pdo = getDB();
    $peerId = null;
    $row = $pdo->prepare("SELECT id FROM peers WHERE hostname = :h LIMIT 1");
    $row->execute([':h' => $remoteHostname]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) $peerId = (int)$r['id'];

    $existing = $pdo->prepare("
        SELECT id FROM handshakes
        WHERE thread_id = :t LIMIT 1
    ");
    $existing->execute([':t' => $threadId]);
    if ($existing->fetchColumn() !== false) {
        return ['ok' => false, 'reason' => 'duplicate_thread'];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + FEDERATION_HANDSHAKE_DEFAULT_EXPIRY_DAYS * 86400);

    $stmt = $pdo->prepare("
        INSERT INTO handshakes
            (peer_id, remote_hostname, initiator, status, requested_galaxies_publish,
             requested_galaxies_subscribe, thread_id, initial_message_id, expires_at)
        VALUES (:p, :h, 'them', 'pending_our_response', :pubg, :subg, :t, :im, :e)
        RETURNING id
    ");
    $stmt->execute([
        ':p' => $peerId,
        ':h' => $remoteHostname,
        ':pubg' => json_encode((array)($payload['requested_galaxies_publish'] ?? [])),
        ':subg' => json_encode((array)($payload['requested_galaxies_subscribe'] ?? [])),
        ':t' => $threadId,
        ':im' => $messageId,
        ':e' => $expiresAt,
    ]);
    return ['ok' => true, 'handshake_id' => (int)$stmt->fetchColumn()];
}

/**
 * Admin-initiated accept of an inbound handshake_request. Called by
 * admin/handshake-accept.php after re-auth / CSRF. Builds the round-2
 * envelope, queues an outbound message to the remote's /handshake, and
 * transitions the handshake row to accepted_awaiting_complete.
 *
 * Requires the remote peer to already exist in `peers` (typically pulled
 * via stage 3's /api/pluriverse/peers.json). Refuses with
 * `peer_not_in_directory` otherwise so the operator knows to wait for the
 * next pull or trigger a Refresh-now in the admin UI.
 */
function handshake_accept_inbound(int $handshakeId): array {
    db_ensure_handshakes_table();
    db_ensure_peer_keys_table();
    db_ensure_pluriverse_messages_table();
    db_ensure_pluriverse_messages_retry_columns();

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT h.id, h.status, h.remote_hostname, h.thread_id, h.peer_id, p.id AS resolved_peer_id
        FROM handshakes h
        LEFT JOIN peers p ON p.hostname = h.remote_hostname
        WHERE h.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $handshakeId]);
    $hs = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hs) return ['ok' => false, 'reason' => 'handshake_not_found'];
    if ($hs['status'] !== 'pending_our_response') {
        return ['ok' => false, 'reason' => 'wrong_state:' . $hs['status']];
    }
    $peerId = $hs['peer_id'] !== null ? (int)$hs['peer_id'] : (int)($hs['resolved_peer_id'] ?? 0);
    if ($peerId === 0) {
        return ['ok' => false, 'reason' => 'peer_not_in_directory'];
    }

    try {
        $secret = federation_load_secret_key();
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'instance_identity_unavailable'];
    }
    $public = federation_derive_public_key($secret);
    $fingerprint = federation_compute_fingerprint($public);
    $localHostname = handshake_local_hostname();

    $ourKey = handshake_generate_peer_key();
    $round2Body = [
        'status' => 'accepted',
        'thread_id' => $hs['thread_id'],
        'peer_key_for_caller' => $ourKey,
        'label' => handshake_local_label(),
        'supported_protocol_versions' => ['1.0'],
    ];
    $envelope = handshake_build_signed_envelope(
        secret: $secret,
        kidHost: $localHostname,
        kidFingerprint: $fingerprint,
        senderHost: $localHostname,
        recipientHost: $hs['remote_hostname'],
        sentAt: gmdate('Y-m-d\TH:i:s\Z'),
        threadId: $hs['thread_id'],
        messageType: 'handshake_response',
        body: 'accepted',
        payload: $round2Body,
    );

    $pdo->beginTransaction();
    try {
        // Store the key we generated for them (direction = we_call_them).
        $pdo->prepare("
            INSERT INTO peer_keys (peer_id, api_key_hash, direction, is_active)
            VALUES (:p, :h, 'we_call_them', TRUE)
        ")->execute([':p' => $peerId, ':h' => hash('sha256', $ourKey, true)]);

        // Queue the round-2 outbound for the dispatcher.
        $msg = $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, body, payload, jws_envelope,
                 delivery_status, next_attempt_at)
            VALUES (:p, 'outbound', :t, 'handshake_response', 'accepted', :pl, :j,
                    'pending', NOW())
            RETURNING id
        ");
        $msg->execute([
            ':p' => $peerId,
            ':t' => $hs['thread_id'],
            ':pl' => json_encode($round2Body, JSON_UNESCAPED_SLASHES),
            ':j' => $envelope,
        ]);
        $msgId = (int)$msg->fetchColumn();

        $pdo->prepare("
            UPDATE handshakes
            SET status = 'accepted_awaiting_complete',
                response_message_id = :m,
                peer_id = :p
            WHERE id = :id
        ")->execute([':m' => $msgId, ':p' => $peerId, ':id' => $hs['id']]);

        $pdo->commit();
        return ['ok' => true, 'handshake_id' => (int)$hs['id'], 'queued_message_id' => $msgId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('handshake_accept_inbound: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'persist_failed'];
    }
}

/**
 * Admin-initiated reject. Mirror of accept but emits {status: rejected,
 * reason} and transitions the row to `rejected`. No peer_keys side-effect.
 */
function handshake_reject_inbound(int $handshakeId, string $reason): array {
    db_ensure_handshakes_table();
    db_ensure_pluriverse_messages_table();
    db_ensure_pluriverse_messages_retry_columns();

    if (strlen($reason) > 1024) {
        return ['ok' => false, 'reason' => 'reason_too_long'];
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT h.id, h.status, h.remote_hostname, h.thread_id, h.peer_id, p.id AS resolved_peer_id
        FROM handshakes h
        LEFT JOIN peers p ON p.hostname = h.remote_hostname
        WHERE h.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $handshakeId]);
    $hs = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hs) return ['ok' => false, 'reason' => 'handshake_not_found'];
    if ($hs['status'] !== 'pending_our_response') {
        return ['ok' => false, 'reason' => 'wrong_state:' . $hs['status']];
    }
    $peerId = $hs['peer_id'] !== null ? (int)$hs['peer_id'] : (int)($hs['resolved_peer_id'] ?? 0);

    try {
        $secret = federation_load_secret_key();
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'instance_identity_unavailable'];
    }
    $public = federation_derive_public_key($secret);
    $fingerprint = federation_compute_fingerprint($public);
    $localHostname = handshake_local_hostname();

    $body = [
        'status' => 'rejected',
        'thread_id' => $hs['thread_id'],
        'reason' => $reason,
        'label' => handshake_local_label(),
    ];
    $envelope = handshake_build_signed_envelope(
        secret: $secret,
        kidHost: $localHostname,
        kidFingerprint: $fingerprint,
        senderHost: $localHostname,
        recipientHost: $hs['remote_hostname'],
        sentAt: gmdate('Y-m-d\TH:i:s\Z'),
        threadId: $hs['thread_id'],
        messageType: 'handshake_response',
        body: 'rejected',
        payload: $body,
    );

    $pdo->beginTransaction();
    try {
        // The reject path can fire even when peer_id is NULL; the dispatcher
        // resolves peers.hostname at dispatch time via JOIN.
        $msg = $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, body, payload, jws_envelope,
                 delivery_status, next_attempt_at)
            VALUES (:p, 'outbound', :t, 'handshake_response', :b, :pl, :j,
                    'pending', NOW())
            RETURNING id
        ");
        $msg->execute([
            ':p' => $peerId !== 0 ? $peerId : null,
            ':t' => $hs['thread_id'],
            ':b' => $reason,
            ':pl' => json_encode($body, JSON_UNESCAPED_SLASHES),
            ':j' => $envelope,
        ]);
        $msgId = (int)$msg->fetchColumn();

        $pdo->prepare("
            UPDATE handshakes
            SET status = 'rejected',
                response_message_id = :m,
                reject_reason = :r,
                peer_id = COALESCE(peer_id, :p)
            WHERE id = :id
        ")->execute([
            ':m' => $msgId,
            ':r' => $reason,
            ':p' => $peerId !== 0 ? $peerId : null,
            ':id' => $hs['id'],
        ]);

        $pdo->commit();
        return ['ok' => true, 'handshake_id' => (int)$hs['id'], 'queued_message_id' => $msgId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('handshake_reject_inbound: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'persist_failed'];
    }
}

/**
 * Admin-initiated cancellation of a handshake we initiated. v10 line 345:
 * "cancellation is local-only (no notification to B)". Walks the row to
 * `cancelled` and abandons any queued outbound (delivery_status set to
 * given_up so the dispatcher leaves it alone).
 */
function handshake_cancel_outbound(int $handshakeId): array {
    db_ensure_handshakes_table();
    db_ensure_pluriverse_messages_table();
    db_ensure_pluriverse_messages_retry_columns();

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, status, initiator FROM handshakes WHERE id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $handshakeId]);
    $hs = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hs) return ['ok' => false, 'reason' => 'handshake_not_found'];
    if ($hs['initiator'] !== 'us') {
        return ['ok' => false, 'reason' => 'cannot_cancel_inbound'];
    }
    if (!in_array($hs['status'], ['pending_their_response', 'accepted_awaiting_complete'], true)) {
        return ['ok' => false, 'reason' => 'wrong_state:' . $hs['status']];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE handshakes SET status = 'cancelled' WHERE id = :id")
            ->execute([':id' => $hs['id']]);
        $pdo->prepare("
            UPDATE pluriverse_messages
            SET delivery_status = 'given_up', next_attempt_at = NULL,
                last_attempt_error = COALESCE(last_attempt_error, 'cancelled_by_admin')
            WHERE id IN (
                SELECT initial_message_id FROM handshakes WHERE id = :id1
                UNION
                SELECT complete_message_id FROM handshakes WHERE id = :id2
            )
              AND delivery_status IN ('pending', 'failed')
        ")->execute([':id1' => $hs['id'], ':id2' => $hs['id']]);
        $pdo->commit();
        return ['ok' => true, 'handshake_id' => (int)$hs['id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('handshake_cancel_outbound: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'persist_failed'];
    }
}

/**
 * Admin-initiated retry: reset an outbound message's delivery_status to
 * 'pending' and clear next_attempt_at so the next dispatcher tick picks
 * it up. Used by the "Retry now" button on a failed handshake row.
 */
function handshake_retry_outbound(int $messageId): array {
    db_ensure_pluriverse_messages_retry_columns();
    $stmt = getDB()->prepare("
        UPDATE pluriverse_messages
        SET delivery_status = 'pending', next_attempt_at = NULL
        WHERE id = :id AND direction = 'outbound'
          AND delivery_status IN ('failed', 'given_up')
    ");
    $stmt->execute([':id' => $messageId]);
    return $stmt->rowCount() > 0
        ? ['ok' => true, 'message_id' => $messageId]
        : ['ok' => false, 'reason' => 'message_not_retryable'];
}

/**
 * Cron sweep: handshakes past their 14-day expires_at flip to `expired`.
 * Returns count of rows transitioned.
 */
function handshake_expire_stale(): int {
    db_ensure_handshakes_table();
    $stmt = getDB()->prepare("
        UPDATE handshakes
        SET status = 'expired'
        WHERE status IN ('pending_their_response', 'pending_our_response', 'accepted_awaiting_complete')
          AND expires_at < NOW()
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Build a JWS Compact-serialized envelope for a handshake / freeform message.
 * Used by initiator (round 1), round-2 accept handler (round 3 outbound), and
 * (future) the freeform message composer.
 */
function handshake_build_signed_envelope(
    string $secret,
    string $kidHost,
    string $kidFingerprint,
    string $senderHost,
    string $recipientHost,
    string $sentAt,
    string $threadId,
    string $messageType,
    string $body,
    array $payload,
    string $subject = '',
): string {
    $header = [
        'alg' => 'EdDSA',
        'kid' => $kidHost . ':' . $kidFingerprint,
        'typ' => FEDERATION_HANDSHAKE_TYP,
    ];
    $jwsPayload = [
        'protocol_version' => '1.0',
        'sender_host' => $senderHost,
        'recipient_host' => $recipientHost,
        'sent_at' => $sentAt,
        'thread_id' => $threadId,
        'message_type' => $messageType,
        'body' => $body,
        'payload' => $payload,
    ];
    if ($subject !== '') $jwsPayload['subject'] = $subject;

    $headerB64 = rtrim(strtr(base64_encode(json_encode($header, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $payloadB64 = rtrim(strtr(base64_encode(json_encode($jwsPayload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $sig = sodium_crypto_sign_detached($headerB64 . '.' . $payloadB64, $secret);
    $sigB64 = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    return $headerB64 . '.' . $payloadB64 . '.' . $sigB64;
}

/**
 * Read-side helpers used by the admin UI panel. Three groups:
 *   inbound_pending: rows the admin can Accept / Reject / Defer.
 *   outbound_pending: rows we initiated that haven't terminated yet.
 *   recent_history: terminal rows (complete / rejected / expired / cancelled)
 *       in a 30-day window, for the "history" subsection.
 *
 * Returns associative arrays with peer label/hostname joined in. Includes
 * a `delivery` sub-object summarising the most-recent outbound message
 * row (attempt_count, last_attempt_error, delivery_status, message_id) so
 * the UI can surface "Retry now" or "Last error" without a second query.
 *
 * @return list<array<string,mixed>>
 */
function handshake_list_inbound_pending(int $limit = 50): array {
    db_ensure_handshakes_table();
    db_ensure_peers_table();
    $stmt = getDB()->prepare("
        SELECT h.id, h.remote_hostname, h.status, h.thread_id, h.peer_id,
               h.requested_galaxies_publish, h.requested_galaxies_subscribe,
               h.created_at, h.expires_at,
               p.label AS peer_label, m.body AS request_body
        FROM handshakes h
        LEFT JOIN peers p ON p.id = h.peer_id
        LEFT JOIN pluriverse_messages m ON m.id = h.initial_message_id
        WHERE h.initiator = 'them' AND h.status = 'pending_our_response'
        ORDER BY h.created_at DESC
        LIMIT :n
    ");
    $stmt->bindValue(':n', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function handshake_list_outbound_pending(int $limit = 50): array {
    db_ensure_handshakes_table();
    db_ensure_pluriverse_messages_retry_columns();
    $stmt = getDB()->prepare("
        SELECT h.id, h.remote_hostname, h.status, h.thread_id, h.peer_id,
               h.requested_galaxies_publish, h.requested_galaxies_subscribe,
               h.created_at, h.expires_at,
               p.label AS peer_label,
               m.id AS message_id, m.delivery_status, m.attempt_count, m.last_attempt_error, m.next_attempt_at
        FROM handshakes h
        LEFT JOIN peers p ON p.id = h.peer_id
        LEFT JOIN pluriverse_messages m
               ON m.id = COALESCE(h.complete_message_id, h.response_message_id, h.initial_message_id)
        WHERE h.initiator = 'us'
          AND h.status IN ('pending_their_response', 'accepted_awaiting_complete')
        ORDER BY h.created_at DESC
        LIMIT :n
    ");
    $stmt->bindValue(':n', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function handshake_list_recent_history(int $limit = 20, int $withinDays = 30): array {
    db_ensure_handshakes_table();
    $stmt = getDB()->prepare("
        SELECT h.id, h.remote_hostname, h.status, h.initiator,
               h.created_at, h.updated_at, h.reject_reason,
               p.label AS peer_label
        FROM handshakes h
        LEFT JOIN peers p ON p.id = h.peer_id
        WHERE h.status IN ('complete', 'rejected', 'expired', 'cancelled')
          AND h.updated_at >= NOW() - (:d * INTERVAL '1 day')
        ORDER BY h.updated_at DESC
        LIMIT :n
    ");
    $stmt->bindValue(':n', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':d', max(1, $withinDays), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function handshake_local_hostname(): string {
    // Delegated to the shared self-healing resolver (inc/federation/identity.php)
    // so the HTTP and CLI/cron paths agree on this instance's canonical
    // federation hostname.
    return federation_local_hostname();
}

function handshake_local_label(): string {
    if (function_exists('db_get_instance_name')) {
        try {
            $maybe = db_get_instance_name();
            if ($maybe !== '') return $maybe;
        } catch (Throwable $_) {}
    }
    return handshake_local_hostname();
}
