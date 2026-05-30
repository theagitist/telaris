<?php
declare(strict_types=1);

/**
 * Federation/governance reconciliation after a wipe_all snapshot restore (q22).
 *
 * Snapshots are full content backups; federation/governance state lives in
 * instance-local tables (peers, remote_retractions, retracted_galaxies,
 * pluriverse_blacklist, galaxy_subscriptions) that are NOT in the dump and
 * survive db_wipe_all_data. A mirrored galaxy IS captured in the snapshot, but
 * only its import_source column is restored: read_only and mirrored_from_peer_id
 * come back as column defaults (FALSE / NULL). Two bugs follow:
 *
 *   Bug 1 (consent): a mirror the origin has since retracted, or whose peer has
 *   since been blacklisted/blocked, is resurrected by the restore. It must be
 *   dropped again.
 *
 *   Bug 2 (integrity): a still-valid mirror comes back as a plain editable local
 *   galaxy. Without read_only it is API-writable (compounding the q21 read-only
 *   bug), and without mirrored_from_peer_id the galaxy-pull / unmirror machinery
 *   no longer recognizes it. It must be relinked.
 *
 * This pass runs at the end of a successful wipe_all restore. After a wipe_all,
 * EVERY constellation is freshly restored, so scanning all import_source rows is
 * safe (there is no pre-existing content to disturb).
 *
 * Scope: the match key is "origin peer + remote slug as stored in import_source"
 * (the same key the pull/unmirror code uses), so reconciliation is scoped to
 * constellations carrying import_source (federation mirrors + bridge imports).
 * Authored galaxies in retracted_galaxies (our own OUTBOUND retractions, keyed
 * by local slug, no import_source) are intentionally left intact: deleting our
 * own restored content would be unwarranted data loss, and the surviving
 * retracted_galaxies record already blocks re-publishing them.
 *
 * Spec: q22 (snapshot restore federation reconciliation). Reuses the
 * galaxy_unmirror / galaxy_retraction / blacklist_enforce federation libs; no
 * raw cross-module SQL leaks into the backup layer.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_mirror.php';      // federation_parse_import_source
require_once __DIR__ . '/galaxy_unmirror.php';    // federation_unmirror_drop_constellation
require_once __DIR__ . '/galaxy_retraction.php';  // federation_retraction_for_slug
require_once __DIR__ . '/blacklist_enforce.php';  // federation_blacklist_matches_host

/**
 * Reconcile federation state after a wipe_all restore. Drops resurrected
 * withdrawn/banned mirrors first, then relinks the surviving ones. Returns a
 * report of what changed.
 *
 * The production caller (a wipe_all restore) passes no argument, so every
 * import_source constellation is reconciled. $limitToCids scopes the scan to a
 * specific id set; it exists so integration tests can run against their own
 * fixtures without touching unrelated mirrors on the shared dev database (the
 * function is otherwise global by design, since after a real wipe_all every
 * constellation is freshly restored content).
 *
 * @param list<int>|null $limitToCids
 * @return array{dropped:list<array{slug:string,origin:string,reason:string}>, relinked:list<string>, orphaned:list<string>, restamped:list<string>}
 */
function federation_reconcile_after_restore(?array $limitToCids = null): array {
    db_ensure_constellations_import_source_column();
    db_ensure_federation_attribution_columns();
    db_ensure_peers_table();
    db_ensure_galaxy_subscriptions_table();
    db_ensure_pluriverse_blacklist_table();
    db_ensure_retracted_galaxies_table();
    db_ensure_remote_retractions_table();
    db_ensure_pluriverse_log_tables();
    $pdo = getDB();

    $report = ['dropped' => [], 'relinked' => [], 'orphaned' => [], 'restamped' => []];

    $sql = "SELECT id, slug, import_source FROM constellations WHERE import_source IS NOT NULL AND import_source <> ''";
    $params = [];
    if ($limitToCids !== null) {
        $ids = array_values(array_unique(array_map('intval', array_filter($limitToCids, fn($v) => (int)$v > 0))));
        if ($ids === []) {
            return $report;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND id IN ($place)";
        $params = $ids;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $cid = (int)$r['id'];
        $slug = (string)$r['slug'];
        $parsed = federation_parse_import_source((string)$r['import_source']);

        // Non-federation import (e.g. the Mocambos bridge writes a different
        // `kind` here). It has no origin peer or subscription, so there is
        // nothing to relink or drop, but its content is still upstream-owned:
        // make the read_only column truthful so it matches the q21 API guard
        // (which already treats any import_source as read-only).
        if ($parsed === null) {
            $pdo->prepare("UPDATE constellations SET read_only = TRUE WHERE id = :c")->execute([':c' => $cid]);
            $report['restamped'][] = $slug;
            continue;
        }

        $originHost = $parsed['origin_host'];
        $remoteSlug = $parsed['remote_slug'];
        $peerId = _federation_restore_peer_id($originHost);

        // Drop first: a mirror the source retracted, or whose peer is banned,
        // must not survive the restore.
        $dropReason = _federation_restore_drop_reason($slug, $peerId, $originHost, $remoteSlug);
        if ($dropReason !== null) {
            federation_unmirror_drop_constellation($cid, $dropReason);
            _federation_restore_audit($originHost, $dropReason, $remoteSlug);
            $report['dropped'][] = ['slug' => $slug, 'remote_slug' => $remoteSlug, 'origin' => $originHost, 'reason' => $dropReason];
            continue;
        }

        // Survivor: relink so it is read-only again and the pull/unmirror
        // machinery recognizes it.
        if ($peerId === null) {
            // The peer row is gone (operator removed the peer between snapshot
            // and restore). Keep it read-only with a null peer so the q21 guard
            // still protects it; it just will not refresh until re-discovery.
            $pdo->prepare("UPDATE constellations SET read_only = TRUE, mirrored_from_peer_id = NULL WHERE id = :c")
                ->execute([':c' => $cid]);
            error_log("federation_reconcile_after_restore: orphan mirror slug={$slug} origin={$originHost} has no peer row; left read_only with null peer, not refreshable until re-discovery");
            $report['orphaned'][] = $slug;
            continue;
        }

        $pdo->prepare("UPDATE constellations SET read_only = TRUE, mirrored_from_peer_id = :p WHERE id = :c")
            ->execute([':p' => $peerId, ':c' => $cid]);
        // Repoint the surviving subscription: its local_constellation_id is
        // stale (the wipe minted fresh constellation ids), so galaxy-pull /
        // unmirror would otherwise track a constellation that no longer exists.
        $pdo->prepare("UPDATE galaxy_subscriptions SET local_constellation_id = :c WHERE peer_id = :p AND remote_slug = :s")
            ->execute([':c' => $cid, ':p' => $peerId, ':s' => $remoteSlug]);
        $report['relinked'][] = $slug;
    }

    return $report;
}

/** Resolve a peer id from an origin hostname (lowercased), or null. */
function _federation_restore_peer_id(string $originHost): ?int {
    $host = strtolower(trim($originHost));
    if ($host === '') {
        return null;
    }
    $stmt = getDB()->prepare("SELECT id FROM peers WHERE hostname = :h LIMIT 1");
    $stmt->execute([':h' => $host]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Reason this restored mirror must be dropped, or null to keep it. Checks the
 * three governance records the task names, all via existing federation libs.
 */
function _federation_restore_drop_reason(string $slug, ?int $peerId, string $originHost, string $remoteSlug): ?string {
    // Peer locally blocked (operator block 6d, key-event revocation 6c, or
    // Pluriverse-blacklist enforcement 6b all set trust_state='blocked').
    if ($peerId !== null) {
        $ts = getDB()->prepare("SELECT trust_state FROM peers WHERE id = :p LIMIT 1");
        $ts->execute([':p' => $peerId]);
        if ((string)$ts->fetchColumn() === 'blocked') {
            return 'restore-peer-blocked';
        }
    }
    // Origin host appears in the mirrored Pluriverse blacklist.
    if (_federation_restore_host_blacklisted($originHost)) {
        return 'restore-pluriverse-blacklist';
    }
    // Origin retracted this galaxy (inbound retraction we honoured).
    if ($peerId !== null && federation_unmirror_is_remote_retracted($peerId, $remoteSlug)) {
        return 'restore-remote-retracted';
    }
    // Edge: a federation mirror whose LOCAL slug we ourselves retracted.
    if (federation_retraction_for_slug($slug) !== null) {
        return 'restore-retracted';
    }
    return null;
}

/** True if the origin host matches any hostname/domain Pluriverse-blacklist entry. */
function _federation_restore_host_blacklisted(string $originHost): bool {
    if (trim($originHost) === '') {
        return false;
    }
    $entries = getDB()->query("
        SELECT entry_type, entry_value
        FROM pluriverse_blacklist
        WHERE entry_type IN ('hostname', 'domain')
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($entries as $e) {
        if (federation_blacklist_matches_host($originHost, (string)$e['entry_type'], (string)$e['entry_value'])) {
            return true;
        }
    }
    return false;
}

/** Audit a restore-reconcile drop in pluriverse_log. */
function _federation_restore_audit(string $originHost, string $reason, string $remoteSlug): void {
    $details = 'reason=' . $reason . '; remote_slug=' . $remoteSlug;
    getDB()->prepare("
        INSERT INTO pluriverse_log (event_type, actor, target, outcome, details_summary)
        VALUES ('restore_reconcile_drop', 'restore', :h, 'success', :d)
    ")->execute([
        ':h' => $originHost !== '' ? $originHost : '(unknown)',
        ':d' => substr($details, 0, 1024),
    ]);
}
