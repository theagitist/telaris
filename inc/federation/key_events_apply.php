<?php
declare(strict_types=1);

/**
 * Side-effect application for Pluriverse-coord-signed key events.
 *
 * Extracted from key_events_push_handler.php so the pure apply logic is
 * testable without invoking the HTTP handler (which runs verification + emits
 * a response on include). The handler requires this file and calls
 * federation_key_events_apply_peer_event after it has verified the transport
 * HTTP-Sig + the inner coord-signed JWS envelope.
 *
 * Side effects per event_type:
 *   - compromise: swap peers.public_key, clear previous_public_key (zero
 *     grace). NOT a drop: the peer is still trusted under the new key.
 *   - scheduled_rotation / operational_rotation: swap public_key, keep the
 *     prior value in previous_public_key for the 30-day grace.
 *   - revocation: flip peers.trust_state='blocked' + record
 *     local_blacklisted_reason, then uniformly drop everything the peer
 *     mirrored (6c, the 6a primitive). This is also how an
 *     admission_status='revoked' instance reaches peers.
 *
 * coord_rotation is handled separately in the handler (coord_key.php); it
 * never reaches this function.
 *
 * Spec: P2P federation plan v10 § Key management, § State-change propagation;
 *       Stage 6 trust revocation design (6c).
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_unmirror.php';

/**
 * Apply the side-effects of a per-peer key event. Idempotent: re-applying the
 * same compromise/rotation event leaves the target columns in the same state;
 * re-applying a revocation after the drop returns dropped:[] (the 6a primitive
 * is itself idempotent).
 *
 * @return array{ok:bool, reason?:string, updated_rows?:int, dropped?:list<string>}
 */
function federation_key_events_apply_peer_event(string $eventType, string $originHost, array $payload): array {
    db_ensure_peers_table();
    $pdo = getDB();

    if ($eventType === 'compromise' || $eventType === 'revocation') {
        $newPubB64 = (string)($payload['new_public_key'] ?? '');
        $newPubBytes = $newPubB64 !== '' ? base64_decode($newPubB64, true) : false;

        if ($eventType === 'compromise') {
            if ($newPubBytes === false || strlen($newPubBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return ['ok' => false, 'reason' => 'missing_new_public_key'];
            }
            $stmt = $pdo->prepare("
                UPDATE peers
                SET public_key = :p,
                    previous_public_key = NULL,
                    key_rotated_at = NOW(),
                    rotation_reason = 'compromise'
                WHERE hostname = :h
            ");
            $stmt->execute([':p' => $newPubBytes, ':h' => $originHost]);
            return ['ok' => true, 'updated_rows' => $stmt->rowCount()];
        }

        // revocation: flag-flip, then drop.
        $stmt = $pdo->prepare("
            UPDATE peers
            SET trust_state = 'blocked',
                local_blacklisted_reason = COALESCE(local_blacklisted_reason, 'pluriverse-revoked')
            WHERE hostname = :h
        ");
        $stmt->execute([':h' => $originHost]);
        $updatedRows = $stmt->rowCount();

        // 6c: on top of the flag-flip, uniformly drop everything this peer
        // mirrored. A revocation signals something went wrong on the origin
        // side, so its prior consent is no longer reliable and the whole
        // footprint comes down (the 6a primitive, which also clears the
        // bilateral publish offer, emails the operator via 6f, and writes the
        // peer_untrust_drop audit row). Idempotent: a replayed revocation
        // after the drop returns dropped:[].
        $pidStmt = $pdo->prepare("SELECT id FROM peers WHERE hostname = :h LIMIT 1");
        $pidStmt->execute([':h' => $originHost]);
        $peerId = (int)($pidStmt->fetchColumn() ?: 0);
        $dropped = [];
        if ($peerId > 0) {
            $drop = federation_unmirror_drop_all_for_peer($peerId, 'pluriverse-revoked');
            $dropped = $drop['dropped'];
        }
        return ['ok' => true, 'updated_rows' => $updatedRows, 'dropped' => $dropped];
    }

    // scheduled_rotation / operational_rotation: 30-day grace.
    $newPubB64 = (string)($payload['new_public_key'] ?? '');
    $newPubBytes = base64_decode($newPubB64, true);
    if ($newPubBytes === false || strlen($newPubBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['ok' => false, 'reason' => 'missing_new_public_key'];
    }
    $stmt = $pdo->prepare("
        UPDATE peers
        SET previous_public_key = public_key,
            public_key = :p,
            key_rotated_at = NOW(),
            rotation_reason = :r
        WHERE hostname = :h
    ");
    $stmt->execute([
        ':p' => $newPubBytes,
        ':r' => $eventType === 'scheduled_rotation' ? 'scheduled' : 'operational',
        ':h' => $originHost,
    ]);
    return ['ok' => true, 'updated_rows' => $stmt->rowCount()];
}
