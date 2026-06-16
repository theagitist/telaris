<?php
declare(strict_types=1);

/**
 * Stage 5d-iv: drop / fossilize a mirror, and apply propagation state changes.
 *
 * The propagation table from P2P federation plan v10 § State-change propagation
 * resolves to two local actions:
 *   drop       remove the mirror constellation and clear the subscription
 *              (retraction / origin revocation / blacklist / whitelist revocation)
 *   fossilize  keep the mirror, mark the subscription inactive so no further
 *              pulls happen (slug-level withdrawal: peer's published.json no
 *              longer lists the slug but no retraction was minted; prior
 *              consent stands).
 *
 * Both actions are idempotent and safe to call from any pull cycle. Drop is
 * also the entry point for operator-driven revocation/blacklist (5f), which
 * is why it lives in this file rather than inside the pull loop.
 *
 * Blob files (UPLOAD_DIR/federation-media/<sha256>) are NEVER unlinked here:
 * content-addressed media is deduped across mirrors, and precise refcounting
 * is a deferred 5f concern (see media_store.php). An orphaned blob is a leak,
 * not a correctness problem; a future GC sweep handles it.
 *
 * Spec: Stage 5 galaxy publish design (5d-iv); v10 § State-change propagation.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_retraction.php';
require_once __DIR__ . '/drop_notice.php';

/**
 * Drop a mirror: delete the local constellation, clear (delete) the
 * subscription. Idempotent. Used by retraction propagation; the same primitive
 * will be called by 5f's revocation/blacklist UI.
 *
 * Returns ok=true even when there is nothing to drop (subscription gone,
 * constellation already deleted), so the caller can iterate without branching.
 *
 * @return array{ok:bool, dropped_constellation_id:?int, error?:string}
 */
function federation_unmirror_drop(int $peerId, string $remoteSlug, string $reason): array {
    db_ensure_galaxy_subscriptions_table();
    // The constellation-delete path below transitively calls
    // db_get_referencing_portals -> db_ensure_nodes_node_type_index, which
    // runs DDL (CREATE INDEX) on first call and implicit-commits any
    // in-progress transaction in MySQL. Fire the ensure here, before
    // beginTransaction, so the tx remains intact for the delete.
    db_ensure_nodes_node_type_index();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, local_constellation_id FROM galaxy_subscriptions WHERE peer_id = :p AND remote_slug = :s LIMIT 1");
    $stmt->execute([':p' => $peerId, ':s' => $remoteSlug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['ok' => true, 'dropped_constellation_id' => null];
    }
    $subId = (int)$row['id'];
    $cid = $row['local_constellation_id'] !== null ? (int)$row['local_constellation_id'] : 0;

    $pdo->beginTransaction();
    try {
        if ($cid > 0) {
            // Verify the constellation we are about to drop is actually a
            // mirror from this peer; defence against a stray subscription row
            // pointing at an authored galaxy (should not happen, but if it
            // does, refuse rather than wipe authored content).
            $own = $pdo->prepare("SELECT mirrored_from_peer_id FROM constellations WHERE id = :id LIMIT 1");
            $own->execute([':id' => $cid]);
            $ownRow = $own->fetch(PDO::FETCH_ASSOC);
            if ($ownRow !== false) {
                $mirroredFrom = $ownRow['mirrored_from_peer_id'] !== null ? (int)$ownRow['mirrored_from_peer_id'] : 0;
                if ($mirroredFrom !== $peerId) {
                    $pdo->rollBack();
                    return ['ok' => false, 'dropped_constellation_id' => null, 'error' => 'not_a_mirror_of_this_peer'];
                }
                _federation_unmirror_delete_constellation_keep_blobs($cid);
            }
        }
        // Delete the subscription row outright: a permanently-retracted /
        // revoked / blacklisted (peer, slug) pair should not auto-re-subscribe
        // on the next pull. The operator can manually re-add via admin (and
        // 5f's surface checks remote_retractions before doing so).
        $pdo->prepare("DELETE FROM galaxy_subscriptions WHERE id = :id")->execute([':id' => $subId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('federation_unmirror_drop: ' . $e->getMessage());
        return ['ok' => false, 'dropped_constellation_id' => null, 'error' => 'drop_failed'];
    }
    return ['ok' => true, 'dropped_constellation_id' => $cid > 0 ? $cid : null];
}

/**
 * Drop a mirror identified by its LOCAL constellation id (not by a
 * (peer, remote_slug) subscription lookup), blob-safe.
 *
 * The post-restore reconciliation (federation_reconcile_after_restore) needs
 * this: a wipe_all restore mints fresh constellation ids, so the surviving
 * galaxy_subscriptions rows point at stale ids and cannot be the lookup key for
 * federation_unmirror_drop. Here the caller already knows the exact restored
 * constellation to remove (resolved from its import_source).
 *
 * Reuses _federation_unmirror_delete_constellation_keep_blobs so shared
 * content-addressed blobs are preserved exactly as in federation_unmirror_drop,
 * then removes any subscription row still pointing at this id. Idempotent: a
 * missing constellation is a no-op success.
 *
 * @return array{ok:bool, error?:string}
 */
function federation_unmirror_drop_constellation(int $constellationId, string $reason): array {
    if ($constellationId <= 0) {
        return ['ok' => true];
    }
    db_ensure_galaxy_subscriptions_table();
    // Same DDL pre-fire as federation_unmirror_drop: the delete path calls
    // db_get_referencing_portals -> db_ensure_nodes_node_type_index (CREATE
    // INDEX), which implicit-commits a transaction in MySQL. Fire it first so
    // the transaction below stays intact.
    db_ensure_nodes_node_type_index();
    $pdo = getDB();

    $exists = $pdo->prepare("SELECT 1 FROM constellations WHERE id = :c LIMIT 1");
    $exists->execute([':c' => $constellationId]);
    if (!$exists->fetchColumn()) {
        return ['ok' => true];
    }

    $pdo->beginTransaction();
    try {
        _federation_unmirror_delete_constellation_keep_blobs($constellationId);
        $pdo->prepare("DELETE FROM galaxy_subscriptions WHERE local_constellation_id = :c")
            ->execute([':c' => $constellationId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('federation_unmirror_drop_constellation: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'drop_failed'];
    }
    return ['ok' => true];
}

/**
 * Stage 6a: tear down every mirror from one peer in a single sweep, the
 * uniform-drop primitive the governance-untrust channels share (Pluriverse
 * blacklist enforcement 6b, key-event revocation 6c, operator local blacklist
 * 6d). v10 § State-change propagation: "governance-driven untrust events
 * propagate uniformly; receivers drop affected content."
 *
 * Drops ALL of the peer's subscriptions, active AND fossilized. Fossilization
 * (origin withdrawal) is the soft case where prior consent lets the mirror
 * stand; a hard untrust overrides that ("revocation signals something went
 * wrong on the origin side; the prior consent is no longer reliable"), so the
 * peer's whole footprint comes down.
 *
 * Also clears the bilateral offer: we stop publishing OUR galaxies to a peer
 * we've untrusted by deleting its publish-whitelist rows, then recompute
 * has_active_whitelist. Writes one peer_untrust_drop audit row summarizing the
 * teardown (one row for the whole peer, not one per slug).
 *
 * Does NOT flip peers.trust_state: the caller owns the trust transition (and
 * the reason it carries), this owns the content teardown. Keeps the primitive
 * composable across the three untrust channels.
 *
 * Idempotent: a second call once everything is gone returns dropped=[] and
 * still writes an audit row recording a zero-effect sweep.
 *
 * @return array{ok:bool, dropped:list<string>, refused:list<string>, publish_entries_cleared:int}
 */
function federation_unmirror_drop_all_for_peer(int $peerId, string $reason): array {
    db_ensure_peers_table();
    db_ensure_galaxy_subscriptions_table();
    db_ensure_galaxy_publish_whitelist_table();
    $pdo = getDB();

    $hostStmt = $pdo->prepare("SELECT hostname FROM peers WHERE id = :p LIMIT 1");
    $hostStmt->execute([':p' => $peerId]);
    $hostname = (string)($hostStmt->fetchColumn() ?: '');

    $subStmt = $pdo->prepare("SELECT remote_slug FROM galaxy_subscriptions WHERE peer_id = :p");
    $subStmt->execute([':p' => $peerId]);
    $slugs = $subStmt->fetchAll(PDO::FETCH_COLUMN);

    $dropped = [];
    $refused = [];
    foreach ($slugs as $slug) {
        $slug = (string)$slug;
        if ($slug === '') continue;
        $res = federation_unmirror_drop($peerId, $slug, $reason);
        if (!$res['ok']) {
            // federation_unmirror_drop refuses (and leaves the subscription
            // intact) only when the local constellation is not a mirror of
            // this peer, the should-not-happen safety guard. Surface it rather
            // than silently leaving a dangling subscription.
            $refused[] = $slug;
            continue;
        }
        // dropped_constellation_id is null for a pending subscription that
        // never materialized a constellation; the subscription row is still
        // deleted, but nothing visible disappeared, so it is not "dropped".
        if ($res['dropped_constellation_id'] !== null) {
            $dropped[] = $slug;
        }
    }

    $delPub = $pdo->prepare("DELETE FROM galaxy_publish_whitelist WHERE peer_id = :p");
    $delPub->execute([':p' => $peerId]);
    $publishCleared = $delPub->rowCount();
    db_recompute_peer_active_whitelist($peerId);

    db_ensure_pluriverse_log_tables();
    $details = 'reason=' . $reason
        . '; mirrors_dropped=' . count($dropped)
        . '; publish_entries_cleared=' . $publishCleared;
    if ($refused !== []) {
        $details .= '; refused=' . count($refused);
    }
    $log = $pdo->prepare("
        INSERT INTO pluriverse_log (event_type, actor, target, outcome, details_summary)
        VALUES ('peer_untrust_drop', 'federation', :h, :o, :d)
    ");
    $log->execute([
        ':h' => $hostname !== '' ? $hostname : ('peer#' . $peerId),
        ':o' => $refused === [] ? 'success' : 'warning',
        ':d' => substr($details, 0, 1024),
    ]);

    // 6f: one operator email for the whole peer teardown (no-op if nothing
    // visible was dropped, mail unconfigured, or no admins).
    if ($dropped !== []) {
        $items = array_map(
            static fn(string $slug): array => ['slug' => $slug, 'origin_host' => $hostname],
            $dropped
        );
        federation_notify_operator_mirror_dropped($items, $reason);
    }

    return [
        'ok' => true,
        'dropped' => $dropped,
        'refused' => $refused,
        'publish_entries_cleared' => $publishCleared,
    ];
}

/**
 * Fossilize a subscription: keep the local mirror constellation as-is, mark
 * the subscription inactive with a reason so the diff stops pulling it and
 * the admin surface can show "fossilized 2026-05-28: origin_withdrawal".
 * Idempotent. Unlike drop, the underlying constellation is untouched: prior
 * consent stands.
 *
 * @return array{ok:bool, fossilized:bool}
 */
function federation_unmirror_fossilize(int $peerId, string $remoteSlug, string $reason): array {
    db_ensure_galaxy_subscriptions_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE galaxy_subscriptions
        SET is_active = FALSE,
            fossilized_at = COALESCE(fossilized_at, CURRENT_TIMESTAMP),
            fossilized_reason = COALESCE(fossilized_reason, :reason)
        WHERE peer_id = :p AND remote_slug = :s
    ");
    $stmt->execute([':p' => $peerId, ':s' => $remoteSlug, ':reason' => $reason]);
    return ['ok' => true, 'fossilized' => $stmt->rowCount() > 0];
}

/**
 * Record an inbound retraction in remote_retractions (idempotent on
 * (peer_id, slug)). Lets the admin surface refuse re-subscription to a
 * permanently-retracted (peer, slug) pair.
 */
function federation_unmirror_record_remote_retraction(
    int $peerId,
    string $remoteSlug,
    string $retractedAt,
    string $reason,
    string $retractionJws
): void {
    db_ensure_remote_retractions_table();
    // Parse retracted_at to a MySQL TIMESTAMP-compatible string; fall back to
    // now() if the origin sent a value MySQL would refuse (zero / pre-1970).
    $ts = strtotime($retractedAt);
    $tsStr = $ts !== false && $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
    $stmt = getDB()->prepare("
        INSERT INTO remote_retractions (peer_id, remote_slug, retracted_at, reason, retraction_jws)
        VALUES (:p, :s, :ts, :reason, :jws)
        ON CONFLICT (peer_id, remote_slug) DO UPDATE SET
            retracted_at = EXCLUDED.retracted_at,
            reason = EXCLUDED.reason,
            retraction_jws = EXCLUDED.retraction_jws
    ");
    $stmt->execute([
        ':p' => $peerId,
        ':s' => $remoteSlug,
        ':ts' => $tsStr,
        ':reason' => $reason,
        ':jws' => $retractionJws,
    ]);
}

/** Has this (peer, slug) pair been retracted by the origin and honoured locally? */
function federation_unmirror_is_remote_retracted(int $peerId, string $remoteSlug): bool {
    db_ensure_remote_retractions_table();
    $stmt = getDB()->prepare("SELECT 1 FROM remote_retractions WHERE peer_id = :p AND remote_slug = :s LIMIT 1");
    $stmt->execute([':p' => $peerId, ':s' => $remoteSlug]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Operator-facing view: every active or fossilized subscription on this
 * instance and the mirror constellation it points at (if any). Drives the
 * 5f admin "Mirrored galaxies" section. An active subscription without a
 * materialized mirror yet shows as `pending` (first pull hasn't completed).
 *
 * @return list<array{subscription_id:int, peer_id:int, origin_host:string, remote_slug:string, local_constellation_id:?int, local_name:?string, node_count:int, last_received_sequence:?int, last_content_hash:?string, last_synced_at:?string, status:string, fossilized_reason:?string, fossilized_at:?string}>
 */
function federation_mirror_inspection_view(): array {
    db_ensure_galaxy_subscriptions_table();
    db_ensure_peers_table();
    $rows = getDB()->query("
        SELECT
            s.id AS subscription_id,
            s.peer_id,
            p.hostname AS origin_host,
            s.remote_slug,
            s.local_constellation_id,
            c.name AS local_name,
            s.last_received_sequence,
            s.last_content_hash,
            s.last_synced_at,
            s.is_active,
            s.fossilized_at,
            s.fossilized_reason,
            (SELECT COUNT(*) FROM nodes n WHERE n.constellation_id = s.local_constellation_id) AS node_count
        FROM galaxy_subscriptions s
        INNER JOIN peers p ON p.id = s.peer_id
        LEFT JOIN constellations c ON c.id = s.local_constellation_id
        ORDER BY p.hostname, s.remote_slug
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $cid = $r['local_constellation_id'] !== null ? (int)$r['local_constellation_id'] : null;
        $isActive = (bool)$r['is_active'];
        $fossilizedAt = $r['fossilized_at'] !== null ? (string)$r['fossilized_at'] : null;
        if ($fossilizedAt !== null) {
            $status = 'fossilized';
        } elseif (!$isActive) {
            $status = 'paused';
        } elseif ($cid === null) {
            $status = 'pending';
        } else {
            $status = 'active';
        }
        $out[] = [
            'subscription_id' => (int)$r['subscription_id'],
            'peer_id' => (int)$r['peer_id'],
            'origin_host' => (string)$r['origin_host'],
            'remote_slug' => (string)$r['remote_slug'],
            'local_constellation_id' => $cid,
            'local_name' => $r['local_name'] !== null ? (string)$r['local_name'] : null,
            'node_count' => (int)$r['node_count'],
            'last_received_sequence' => $r['last_received_sequence'] !== null ? (int)$r['last_received_sequence'] : null,
            'last_content_hash' => $r['last_content_hash'] !== null ? (string)$r['last_content_hash'] : null,
            'last_synced_at' => $r['last_synced_at'] !== null ? (string)$r['last_synced_at'] : null,
            'status' => $status,
            'fossilized_reason' => $r['fossilized_reason'] !== null ? (string)$r['fossilized_reason'] : null,
            'fossilized_at' => $fossilizedAt,
        ];
    }
    return $out;
}

/**
 * Recent inbound retractions this instance has honoured, newest first.
 * Drives the 5f "Honoured retractions" admin subsection (history view; the
 * mirror itself was dropped at the time of honoring, so this is the only
 * surviving local record of the event).
 *
 * @return list<array{peer_id:int, origin_host:string, remote_slug:string, retracted_at:string, honored_at:string, reason:?string}>
 */
function federation_remote_retractions_recent(int $limit = 50): array {
    db_ensure_remote_retractions_table();
    $limit = max(1, min(500, $limit));
    $rows = getDB()->query("
        SELECT rr.peer_id, p.hostname AS origin_host, rr.remote_slug,
               rr.retracted_at, rr.honored_at, rr.reason
        FROM remote_retractions rr
        INNER JOIN peers p ON p.id = rr.peer_id
        ORDER BY rr.honored_at DESC, rr.id DESC
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'peer_id' => (int)$r['peer_id'],
            'origin_host' => (string)$r['origin_host'],
            'remote_slug' => (string)$r['remote_slug'],
            'retracted_at' => (string)$r['retracted_at'],
            'honored_at' => (string)$r['honored_at'],
            'reason' => $r['reason'] !== null ? (string)$r['reason'] : null,
        ];
    }
    return $out;
}

/**
 * Apply a peer's retracted.json digest. For each entry: verify the envelope
 * against the peer's identity key (with rotation grace), bind it to (peer_host,
 * slug), record it in remote_retractions, drop any mirror we hold for that
 * slug. Verification failures and slug mismatches are reported in `invalid`,
 * they do not abort the rest of the list (a single bad entry must not stop
 * legitimate retractions from being honoured).
 *
 * @param list<array{slug:string, retracted_at:string, reason:string, retraction_jws:string}> $items
 * @return array{processed:int, dropped:list<string>, recorded:list<string>, invalid:list<array{slug:string, reason:string}>}
 */
function federation_pull_apply_retracted(int $peerId, string $peerHost, array $items): array {
    db_ensure_peers_table();
    $pdo = getDB();
    $keyStmt = $pdo->prepare("SELECT public_key, previous_public_key FROM peers WHERE id = :id LIMIT 1");
    $keyStmt->execute([':id' => $peerId]);
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);
    if ($keyRow === false) {
        return ['processed' => 0, 'dropped' => [], 'recorded' => [], 'invalid' => [['slug' => '*', 'reason' => 'unknown_peer']]];
    }
    $currentKey = (string)$keyRow['public_key'];
    $previousKey = $keyRow['previous_public_key'] !== null ? (string)$keyRow['previous_public_key'] : '';

    $processed = 0;
    $dropped = [];
    $recorded = [];
    $invalid = [];

    foreach ($items as $item) {
        $processed++;
        $slug = (string)($item['slug'] ?? '');
        $jws = (string)($item['retraction_jws'] ?? '');

        $verify = federation_retraction_verify($jws, $currentKey);
        if (!$verify['valid'] && $verify['reason'] === 'signature_invalid' && $previousKey !== '') {
            $verify = federation_retraction_verify($jws, $previousKey);
        }
        if (!$verify['valid']) {
            $invalid[] = ['slug' => $slug, 'reason' => $verify['reason']];
            continue;
        }
        $payload = $verify['payload'];

        // Bind the retraction to the peer we pulled it from and the slug the
        // digest entry advertised; the verifier already checked structure.
        if (strcasecmp((string)($payload['origin_host'] ?? ''), $peerHost) !== 0) {
            $invalid[] = ['slug' => $slug, 'reason' => 'origin_host_mismatch'];
            continue;
        }
        $payloadSlug = (string)($payload['slug'] ?? '');
        if ($payloadSlug === '' || $payloadSlug !== $slug) {
            $invalid[] = ['slug' => $slug, 'reason' => 'slug_mismatch'];
            continue;
        }

        federation_unmirror_record_remote_retraction(
            $peerId,
            $slug,
            (string)($payload['retracted_at'] ?? ''),
            (string)($payload['reason'] ?? ''),
            $jws
        );
        $recorded[] = $slug;

        $drop = federation_unmirror_drop($peerId, $slug, 'origin_retraction');
        if ($drop['ok'] && $drop['dropped_constellation_id'] !== null) {
            $dropped[] = $slug;
        }
    }
    return ['processed' => $processed, 'dropped' => $dropped, 'recorded' => $recorded, 'invalid' => $invalid];
}

/**
 * Fossilize every subscription whose slug is in the withdrawn[] classification
 * returned by federation_pull_diff. Idempotent.
 *
 * @param list<string> $withdrawnSlugs
 * @return array{fossilized:list<string>}
 */
function federation_pull_apply_withdrawn(int $peerId, array $withdrawnSlugs): array {
    $out = [];
    foreach ($withdrawnSlugs as $slug) {
        $slug = (string)$slug;
        if ($slug === '') continue;
        $res = federation_unmirror_fossilize($peerId, $slug, 'origin_withdrawal');
        if ($res['fossilized']) $out[] = $slug;
    }
    return ['fossilized' => $out];
}

/**
 * Mirror-aware constellation delete: same shape as db_delete_constellation,
 * but does NOT unlink any uploads/* asset files. Mirrors point at shared
 * content-addressed blobs under uploads/federation-media/; unlinking those
 * here would wipe blobs other mirrors may still reference. Precise blob
 * refcounting is a 5f concern; the orphaned-blob sweep cleans up unused ones
 * later.
 */
function _federation_unmirror_delete_constellation_keep_blobs(int $constellationId): void {
    $pdo = getDB();
    // 1. Drop any portals in OTHER constellations that point here. Bulk delete
    //    matches db_delete_constellation, but we route through node-id DELETE
    //    (not by-constellation) so we can avoid the asset-unlinking branch
    //    we use for the in-constellation nodes below.
    $referencing = db_get_referencing_portals($constellationId);
    if ($referencing !== []) {
        $ids = array_map(fn($r) => (int)$r['id'], $referencing);
        // Portals are URL nodes; their image_url / etc. may be non-blob assets
        // authored on another (NOT-mirror) constellation. Use the standard
        // by-ids helper which performs the file cleanup for those rows; it
        // never touches our content-addressed blob path because portals are
        // not on the mirror constellation we are about to delete. This is the
        // legitimate unmirror teardown, so it bypasses the read-only guard.
        db_bulk_delete_nodes_by_ids($ids, true);
    }
    // 2. Drop nodes in THIS constellation without touching their asset files.
    //    Their file refs are either external URLs (we never own them) or
    //    uploads/federation-media/<sha256> (shared blobs, intentionally kept).
    //    node_keywords cascades on FK.
    $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $constellationId]);
    // 3. Keyword relations + keywords for the mirror.
    $pdo->prepare("
        DELETE kr FROM keyword_relations kr
        WHERE kr.keyword_a_id IN (SELECT id FROM keywords WHERE constellation_id = :c1)
           OR kr.keyword_b_id IN (SELECT id FROM keywords WHERE constellation_id = :c2)
    ")->execute([':c1' => $constellationId, ':c2' => $constellationId]);
    $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $constellationId]);
    // 4. The constellation itself. galaxy_subscriptions.local_constellation_id
    //    is ON DELETE SET NULL, so the subscription's local pointer drops
    //    cleanly here even though we delete the subscription row immediately
    //    after at the call site.
    $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $constellationId]);
}
