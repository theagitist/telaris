<?php
declare(strict_types=1);

/**
 * Stage 6e (instance side): advisory peer-blacklist-notice to the Pluriverse.
 *
 * When an operator locally blocks a peer (6d), this instance sends a courtesy
 * notice to the Pluriverse coordination server for admin review. It is advisory
 * only: v10 keeps notices non-binding (no automatic network-wide propagation
 * from one operator's report), and a failed notice never rolls back the local
 * block, which is the operator's sovereign act.
 *
 * Delivery rides the existing outbound dispatcher queue (pluriverse_messages),
 * so it inherits the standard 1m/5m/30m/2h/6h/12h/24h backoff and give-up.
 * The dispatcher signs each attempt at the HTTP layer with tag tel-bl-notice;
 * the reporter hostname is asserted by the signing keyid, NOT carried in the
 * body (v10 line 86), so the Pluriverse cannot be told a false reporter.
 *
 * Spec: Stage 6 trust revocation design (6e); v10 § Blacklist mechanics.
 */

require_once dirname(__DIR__) . '/db.php';

const FEDERATION_PEER_BLACKLIST_NOTICE_TYPE = 'peer_blacklist_notice';

/**
 * Enqueue an advisory peer-blacklist-notice for the given (already blocked)
 * peer. Best-effort: any failure is logged and swallowed so the caller (the 6d
 * block handler) is never affected.
 *
 * @return array{ok:bool, message_id?:int, error?:string}
 */
function federation_send_peer_blacklist_notice(int $blacklistedPeerId, string $reason, string $category): array {
    try {
        db_ensure_peers_table();
        db_ensure_pluriverse_messages_table();
        db_ensure_pluriverse_messages_retry_columns();
        $pdo = getDB();

        $hostStmt = $pdo->prepare("SELECT hostname FROM peers WHERE id = :p LIMIT 1");
        $hostStmt->execute([':p' => $blacklistedPeerId]);
        $hostname = $hostStmt->fetchColumn();
        if ($hostname === false) {
            return ['ok' => false, 'error' => 'peer_not_found'];
        }

        // Body sent to the Pluriverse. The reporter is intentionally absent: it
        // is taken from the HTTP-Sig keyid at delivery time.
        $body = json_encode([
            'blacklisted_hostname' => (string)$hostname,
            'blacklisted_at' => gmdate('c'),
            'reason' => $reason,
            'category' => $category,
        ], JSON_UNESCAPED_SLASHES);

        $threadId = 'bl-' . bin2hex(random_bytes(8));
        // jws_envelope is NOT a JWS for this message type: the notice is signed
        // only at the HTTP layer, so the column carries the plain JSON body the
        // dispatcher will POST (the same column the relay path reuses as its
        // deliverable). payload keeps a structured copy for admin/audit reads.
        $stmt = $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, subject, body, payload, jws_envelope,
                 delivery_status, next_attempt_at)
            VALUES (:p, 'outbound', :t, 'peer_blacklist_notice', NULL, NULL, :pl, :j, 'pending', NOW())
        ");
        $stmt->execute([':p' => $blacklistedPeerId, ':t' => $threadId, ':pl' => $body, ':j' => $body]);
        return ['ok' => true, 'message_id' => (int)$pdo->lastInsertId()];
    } catch (Throwable $e) {
        error_log('federation_send_peer_blacklist_notice: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'enqueue_failed'];
    }
}
