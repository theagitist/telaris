<?php
declare(strict_types=1);

/**
 * Stage 6b: Pluriverse-blacklist enforcement.
 *
 * pluriverse_pull_blacklist() (transport, in pluriverse_pull.php) full-replaces
 * the local mirror of the Pluriverse blacklist table. It does NOT act on it:
 * the pull is pure transport. This file is the enforcement consumer.
 *
 * After a non-304 blacklist pull, match every peer against the freshly-replaced
 * blacklist; for each peer that newly matches, flip trust_state='blocked' and
 * uniformly drop everything it mirrored (the 6a primitive). v10 §
 * State-change propagation: "governance-driven untrust events propagate
 * uniformly; receivers drop affected content on next refresh."
 *
 * Matching (v10 § Blacklist mechanics): peers are identified by hostname, so
 *   hostname entry  exact host match (case-insensitive);
 *   domain entry    registrable-domain suffix at a label boundary;
 *   ip entry        NOT peer-applicable (abuse/edge control), ignored here.
 *
 * The trust flip is owned here (this channel's transition); the content
 * teardown + operator email (6f) + audit row are owned by
 * federation_unmirror_drop_all_for_peer.
 *
 * Spec: Stage 6 trust revocation design (6b); v10 § State-change propagation.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_unmirror.php';

/**
 * Does a blacklist entry match a peer hostname?
 *
 * `domain` matches at a label boundary so "example.invalid" catches both
 * "example.invalid" and "node.example.invalid" but not "notexample.invalid".
 * `ip` (and any unknown type) never matches a peer: peers are hostname-keyed.
 */
function federation_blacklist_matches_host(string $host, string $entryType, string $entryValue): bool {
    $h = strtolower(trim($host));
    $v = strtolower(trim($entryValue));
    if ($h === '' || $v === '') {
        return false;
    }
    if ($entryType === 'hostname') {
        return $h === $v;
    }
    if ($entryType === 'domain') {
        return $h === $v || str_ends_with($h, '.' . $v);
    }
    return false;
}

/**
 * Enforce the locally-mirrored Pluriverse blacklist against the peer list.
 *
 * For each not-already-blocked peer that matches a hostname/domain entry:
 *   1. flip trust_state='blocked' + record local_blacklisted_reason;
 *   2. federation_unmirror_drop_all_for_peer (drops every mirror, clears the
 *      bilateral publish offer, emails the operator, writes the audit row).
 *
 * Idempotent across pulls: a peer already 'blocked' (by this channel or any
 * other) is skipped, so its content is not re-dropped and the operator is not
 * re-notified. An entry that matches no peer is harmless.
 *
 * @return array{ok:bool, entries:int, peers_checked:int, enforced:list<array{hostname:string, peer_id:int, dropped:list<string>, publish_entries_cleared:int}>}
 */
function federation_enforce_pluriverse_blacklist(): array {
    db_ensure_peers_table();
    db_ensure_pluriverse_blacklist_table();
    $pdo = getDB();

    // Only hostname/domain entries can match a peer; `ip` entries are an
    // abuse/edge control, not a peer-untrust signal (Stage 6 § does not solve).
    $entries = $pdo->query("
        SELECT entry_type, entry_value, reason
        FROM pluriverse_blacklist
        WHERE entry_type IN ('hostname', 'domain')
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Candidate peers: anything not already blocked. An already-blocked peer's
    // content was torn down when it was first matched (or by another untrust
    // channel), so re-matching it is a no-op.
    $peers = $pdo->query("
        SELECT id, hostname
        FROM peers
        WHERE trust_state <> 'blocked'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $enforced = [];
    foreach ($peers as $peer) {
        $host = (string)$peer['hostname'];
        $peerId = (int)$peer['id'];

        $matchReason = null;
        foreach ($entries as $e) {
            if (federation_blacklist_matches_host($host, (string)$e['entry_type'], (string)$e['entry_value'])) {
                $matchReason = $e['reason'] !== null ? (string)$e['reason'] : '';
                break;
            }
        }
        if ($matchReason === null) {
            continue;
        }

        // Block first (the trust transition this channel owns), then tear down
        // the content. COALESCE preserves any reason already recorded (mirrors
        // the key-event revocation handler); a fresh block records the
        // blacklist reason.
        $reasonTag = 'pluriverse-blacklist' . ($matchReason !== '' ? ':' . $matchReason : '');
        $upd = $pdo->prepare("
            UPDATE peers
            SET trust_state = 'blocked',
                local_blacklisted_reason = COALESCE(local_blacklisted_reason, :r)
            WHERE id = :id
        ");
        $upd->execute([':r' => substr($reasonTag, 0, 1024), ':id' => $peerId]);

        // drop_all self-notifies the operator (6f) and writes the
        // peer_untrust_drop audit row; the drop reason token stays the bare
        // 'pluriverse-blacklist' so the email renders the right reason line.
        $drop = federation_unmirror_drop_all_for_peer($peerId, 'pluriverse-blacklist');
        $enforced[] = [
            'hostname' => $host,
            'peer_id' => $peerId,
            'dropped' => $drop['dropped'],
            'publish_entries_cleared' => $drop['publish_entries_cleared'],
        ];
    }

    return [
        'ok' => true,
        'entries' => count($entries),
        'peers_checked' => count($peers),
        'enforced' => $enforced,
    ];
}
