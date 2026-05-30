<?php
declare(strict_types=1);

/**
 * Stage 6d: operator-initiated local blacklist (block / unblock).
 *
 * The operator's own untrust lever, alongside the two governance channels
 * (Pluriverse blacklist 6b, key-event revocation 6c). Blocking a peer flips
 * trust_state='blocked', records local_blacklisted_reason='local:<category>:
 * <reason>', and uniformly drops everything the peer mirrored (the 6a
 * primitive, which also clears the bilateral publish offer, emails the
 * operator via 6f, and writes the peer_untrust_drop audit row).
 *
 * Split from admin/peer-block.php so the block/unblock effects are testable
 * without the auth + CSRF + re-auth scaffolding. The handler validates the
 * operator (re-auth on block) and calls these; these own the DB effects.
 *
 * Unblock returns the peer to 'discovered' and clears the reason. It does NOT
 * restore the dropped mirrors: prior content was torn down by design, and
 * re-subscription is a fresh operator decision.
 *
 * Spec: Stage 6 trust revocation design (6d); v10 § State-change propagation.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_unmirror.php';

/**
 * Allowed block categories. The first four are structured signals the 6e
 * peer-blacklist-notice forwards to the Pluriverse for admin review; 'consent'
 * reflects Telaris's editorial-sovereignty framing (a source community's
 * consent withdrawal is the one countervailing power over mirrored content).
 */
const FEDERATION_PEER_BLOCK_CATEGORIES = ['spam', 'harmful', 'legal', 'consent', 'other'];

/**
 * Block a peer locally and tear down its footprint.
 *
 * @return array{ok:bool, error?:string, dropped?:list<string>, publish_entries_cleared?:int}
 */
function federation_peer_block(int $peerId, string $category, string $reason, string $actorEmail): array {
    if (!in_array($category, FEDERATION_PEER_BLOCK_CATEGORIES, true)) {
        return ['ok' => false, 'error' => 'invalid_category'];
    }
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 1024) {
        return ['ok' => false, 'error' => 'invalid_reason'];
    }

    db_ensure_peers_table();
    $pdo = getDB();

    $hostStmt = $pdo->prepare("SELECT hostname FROM peers WHERE id = :p LIMIT 1");
    $hostStmt->execute([':p' => $peerId]);
    $hostname = $hostStmt->fetchColumn();
    if ($hostname === false) {
        return ['ok' => false, 'error' => 'peer_not_found'];
    }
    $hostname = (string)$hostname;

    // The operator is setting the reason deliberately, so overwrite (unlike the
    // governance channels' COALESCE) -- a re-block after an unblock should
    // record the new reason.
    $tag = 'local:' . $category . ':' . $reason;
    $pdo->prepare("
        UPDATE peers
        SET trust_state = 'blocked',
            local_blacklisted_reason = :r
        WHERE id = :p
    ")->execute([':r' => $tag, ':p' => $peerId]);

    // Audit the operator action itself (the content teardown writes its own
    // peer_untrust_drop row inside drop_all).
    db_ensure_pluriverse_log_tables();
    $pdo->prepare("
        INSERT INTO pluriverse_log (event_type, actor, target, outcome, details_summary)
        VALUES ('peer_block', :a, :h, 'success', :d)
    ")->execute([
        ':a' => $actorEmail !== '' ? $actorEmail : 'operator',
        ':h' => $hostname,
        ':d' => substr('category=' . $category, 0, 1024),
    ]);

    $drop = federation_unmirror_drop_all_for_peer($peerId, 'local-blacklist');
    return [
        'ok' => true,
        'dropped' => $drop['dropped'],
        'publish_entries_cleared' => $drop['publish_entries_cleared'],
    ];
}

/**
 * Unblock a peer: return it to 'discovered' and clear the reason. Does not
 * restore dropped mirrors. Idempotent: a peer not currently blocked is a
 * no-op (changed=false).
 *
 * @return array{ok:bool, changed:bool}
 */
function federation_peer_unblock(int $peerId, string $actorEmail): array {
    db_ensure_peers_table();
    $pdo = getDB();

    $hostStmt = $pdo->prepare("SELECT hostname FROM peers WHERE id = :p LIMIT 1");
    $hostStmt->execute([':p' => $peerId]);
    $hostname = $hostStmt->fetchColumn();
    if ($hostname === false) {
        return ['ok' => false, 'changed' => false];
    }

    $upd = $pdo->prepare("
        UPDATE peers
        SET trust_state = 'discovered',
            local_blacklisted_reason = NULL
        WHERE id = :p AND trust_state = 'blocked'
    ");
    $upd->execute([':p' => $peerId]);
    $changed = $upd->rowCount() > 0;

    if ($changed) {
        db_ensure_pluriverse_log_tables();
        $pdo->prepare("
            INSERT INTO pluriverse_log (event_type, actor, target, outcome, details_summary)
            VALUES ('peer_unblock', :a, :h, 'success', 'returned to discovered; mirrors not restored')
        ")->execute([
            ':a' => $actorEmail !== '' ? $actorEmail : 'operator',
            ':h' => (string)$hostname,
        ]);
    }

    return ['ok' => true, 'changed' => $changed];
}
