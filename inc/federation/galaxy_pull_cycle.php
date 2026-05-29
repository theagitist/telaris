<?php
declare(strict_types=1);

/**
 * Stage 5d-v: galaxy-pull orchestrator.
 *
 * One full pull cycle per peer:
 *   1. retracted.json -> apply_retracted (drop matching mirrors)
 *   2. published.json -> diff -> apply_withdrawn (fossilize matching subs)
 *      -> for each to_pull: fetch envelope -> verify + materialize (with the
 *      default media fetcher) -> update subscription bookkeeping
 *   3. record success / failure state with exponential backoff (1m / 5m /
 *      30m / 2h / 6h / 12h / 24h, capped — no give-up: a peer that comes back
 *      online resumes).
 *
 * Step 1 runs before step 2 so a slug that has both a fresh published row
 * (uncommon: should never happen, the publish path refuses retracted slugs)
 * and a fresh retraction in the same cycle still gets the retraction
 * honoured. Defence in depth.
 *
 * A retracted.json transport error is treated as a transient pull failure
 * (back off). A bad signature inside the digest is reported in `invalid` and
 * does NOT abort the cycle (a single malformed retraction must not shield
 * legitimate work). Same posture as federation_pull_apply_retracted.
 *
 * Spec: Stage 5 galaxy publish design (5d-v); v10 § State-change propagation.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_pull.php';
require_once __DIR__ . '/galaxy_mirror.php';
require_once __DIR__ . '/galaxy_unmirror.php';

/**
 * Backoff schedule, matching the 4d dispatcher's curve so operator mental
 * model stays one schedule. consecutive_failures=0 is the steady-state cron
 * cadence (the next 30-minute slot); 1+ is the cooldown after a failure.
 */
function federation_galaxy_pull_backoff_seconds(int $consecutiveFailures): int {
    return match (true) {
        $consecutiveFailures <= 0 => 0,
        $consecutiveFailures === 1 => 60,
        $consecutiveFailures === 2 => 5 * 60,
        $consecutiveFailures === 3 => 30 * 60,
        $consecutiveFailures === 4 => 2 * 3600,
        $consecutiveFailures === 5 => 6 * 3600,
        $consecutiveFailures === 6 => 12 * 3600,
        default => 24 * 3600,
    };
}

/**
 * The peers a cron tick should consider: peers with at least one active
 * subscription that are NOT on cooldown. Locally / Pluriverse blacklisted
 * peers are not filtered here yet (those columns vary by deployment); the
 * orchestrator is the single chokepoint for the peer-pull workload, so a
 * future blacklist filter slots in here.
 *
 * @return list<int>
 */
function federation_galaxy_pull_eligible_peer_ids(): array {
    db_ensure_galaxy_subscriptions_table();
    db_ensure_peer_pull_state_table();
    $rows = getDB()->query("
        SELECT p.id
        FROM peers p
        WHERE EXISTS (
            SELECT 1 FROM galaxy_subscriptions s
            WHERE s.peer_id = p.id AND s.is_active = TRUE
        )
        AND NOT EXISTS (
            SELECT 1 FROM peer_pull_state ps
            WHERE ps.peer_id = p.id AND ps.next_pull_at IS NOT NULL AND ps.next_pull_at > NOW()
        )
        ORDER BY p.id
    ")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/**
 * Read the per-peer pull state, or a zeroed default if no row exists yet.
 *
 * @return array{peer_id:int, last_pull_started_at:?string, last_pull_succeeded_at:?string, last_pull_failed_at:?string, next_pull_at:?string, consecutive_failures:int, last_error:?string}
 */
function federation_galaxy_pull_state_get(int $peerId): array {
    db_ensure_peer_pull_state_table();
    $stmt = getDB()->prepare("SELECT * FROM peer_pull_state WHERE peer_id = :p LIMIT 1");
    $stmt->execute([':p' => $peerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'peer_id' => $peerId,
            'last_pull_started_at' => null,
            'last_pull_succeeded_at' => null,
            'last_pull_failed_at' => null,
            'next_pull_at' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
        ];
    }
    $row['peer_id'] = (int)$row['peer_id'];
    $row['consecutive_failures'] = (int)$row['consecutive_failures'];
    return $row;
}

/** Stamp the start of a pull (last_pull_started_at, blank next_pull_at gate). */
function federation_galaxy_pull_state_start(int $peerId): void {
    db_ensure_peer_pull_state_table();
    getDB()->prepare("
        INSERT INTO peer_pull_state (peer_id, last_pull_started_at, next_pull_at, consecutive_failures)
        VALUES (:p, NOW(), NULL, 0)
        ON DUPLICATE KEY UPDATE
            last_pull_started_at = NOW(),
            next_pull_at = NULL
    ")->execute([':p' => $peerId]);
}

/** Clear the failure state; record the success timestamp. */
function federation_galaxy_pull_state_record_success(int $peerId): void {
    db_ensure_peer_pull_state_table();
    getDB()->prepare("
        INSERT INTO peer_pull_state (peer_id, last_pull_succeeded_at, consecutive_failures, last_error, next_pull_at)
        VALUES (:p, NOW(), 0, NULL, NULL)
        ON DUPLICATE KEY UPDATE
            last_pull_succeeded_at = NOW(),
            consecutive_failures = 0,
            last_error = NULL,
            next_pull_at = NULL
    ")->execute([':p' => $peerId]);
}

/**
 * Bump the failure counter, set next_pull_at per the backoff schedule,
 * record the error. Truncates last_error to 255 chars (column size) so a
 * curl-formatted message can never overflow.
 */
function federation_galaxy_pull_state_record_failure(int $peerId, string $error): void {
    db_ensure_peer_pull_state_table();
    $err = mb_strimwidth($error, 0, 255, '');
    $pdo = getDB();
    $pdo->prepare("
        INSERT INTO peer_pull_state (peer_id, last_pull_failed_at, consecutive_failures, last_error, next_pull_at)
        VALUES (:p, NOW(), 1, :err, NOW())
        ON DUPLICATE KEY UPDATE
            last_pull_failed_at = NOW(),
            consecutive_failures = consecutive_failures + 1,
            last_error = :err2
    ")->execute([':p' => $peerId, ':err' => $err, ':err2' => $err]);
    // Compute the new next_pull_at based on the post-increment failure count.
    $cnt = (int)$pdo->query("SELECT consecutive_failures FROM peer_pull_state WHERE peer_id = $peerId")->fetchColumn();
    $cooldown = federation_galaxy_pull_backoff_seconds($cnt);
    $pdo->prepare("UPDATE peer_pull_state SET next_pull_at = DATE_ADD(NOW(), INTERVAL :s SECOND) WHERE peer_id = :p")
        ->execute([':s' => $cooldown, ':p' => $peerId]);
}

/**
 * One full pull cycle for one peer. Returns a structured report; callers can
 * surface it in CLI output, admin flash messages, JSON logs. On any transport
 * error during retracted.json or published.json the cycle aborts and the
 * peer is marked failed (backed off). Envelope-level errors during the
 * to_pull loop do NOT mark the whole cycle failed (one bad galaxy must not
 * shield the rest of the peer's content); they are surfaced per slug.
 *
 * @param array{force?:bool} $opts Force skips the next_pull_at gate.
 * @return array{ok:bool, peer_id:int, host:string, retracted:array, withdrawn:array, materialized:list<array>, errors:list<array>, error?:string}
 */
function federation_galaxy_pull_peer(int $peerId, array $opts = []): array {
    db_ensure_peers_table();
    $pdo = getDB();
    $peer = $pdo->prepare("SELECT id, hostname FROM peers WHERE id = :id LIMIT 1");
    $peer->execute([':id' => $peerId]);
    $row = $peer->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'ok' => false, 'peer_id' => $peerId, 'host' => '',
            'retracted' => [], 'withdrawn' => [], 'materialized' => [],
            'errors' => [], 'error' => 'unknown_peer',
        ];
    }
    $host = (string)$row['hostname'];

    if (empty($opts['force'])) {
        $state = federation_galaxy_pull_state_get($peerId);
        if ($state['next_pull_at'] !== null && strtotime($state['next_pull_at']) > time()) {
            return [
                'ok' => false, 'peer_id' => $peerId, 'host' => $host,
                'retracted' => [], 'withdrawn' => [], 'materialized' => [],
                'errors' => [], 'error' => 'on_cooldown',
            ];
        }
    }

    federation_galaxy_pull_state_start($peerId);
    $report = [
        'ok' => true, 'peer_id' => $peerId, 'host' => $host,
        'retracted' => ['processed' => 0, 'dropped' => [], 'recorded' => [], 'invalid' => []],
        'withdrawn' => ['fossilized' => []],
        'materialized' => [],
        'errors' => [],
    ];

    // 1. retracted.json: a transport failure backs the peer off; per-entry
    //    verification failures are reported and the cycle continues.
    $rResp = federation_pull_fetch_retracted($host);
    if (!$rResp['ok']) {
        $err = 'retracted_fetch:' . ($rResp['error'] ?? ('http_' . $rResp['status']));
        federation_galaxy_pull_state_record_failure($peerId, $err);
        $report['ok'] = false;
        $report['error'] = $err;
        return $report;
    }
    if (!$rResp['not_modified'] && $rResp['retracted'] !== []) {
        $report['retracted'] = federation_pull_apply_retracted($peerId, $host, $rResp['retracted']);
    }

    // 2. published.json + diff: same transport-failure posture.
    $pResp = federation_pull_fetch_published($host);
    if (!$pResp['ok']) {
        $err = 'published_fetch:' . ($pResp['error'] ?? ('http_' . $pResp['status']));
        federation_galaxy_pull_state_record_failure($peerId, $err);
        $report['ok'] = false;
        $report['error'] = $err;
        return $report;
    }
    if (!$pResp['not_modified']) {
        $diff = federation_pull_diff($peerId, $pResp['published']);
        if ($diff['withdrawn'] !== []) {
            $report['withdrawn'] = federation_pull_apply_withdrawn($peerId, $diff['withdrawn']);
        }
        $fetcher = federation_mirror_default_fetcher($host);
        foreach ($diff['to_pull'] as $item) {
            $slug = (string)$item['slug'];
            $lastHash = $item['last_content_hash'];
            $expectedHash = (string)$item['content_hash'];
            $lastSeq = (int)$item['last_received_sequence'];

            $eResp = federation_pull_fetch_envelope($host, $slug, $lastHash);
            if (!$eResp['ok']) {
                $report['errors'][] = ['slug' => $slug, 'stage' => 'envelope_fetch', 'error' => $eResp['error'] ?? ('http_' . $eResp['status'])];
                continue;
            }
            if ($eResp['not_modified']) {
                // The index advertised a new hash but the per-slug ETag still
                // matches the last one we mirrored. Origin's index is ahead of
                // its envelope cache, or we already mirrored it; either way,
                // nothing to do.
                continue;
            }
            $apply = federation_pull_apply_envelope($peerId, $host, $slug, $eResp['jws'], $expectedHash, $lastSeq, $fetcher);
            if (!$apply['ok']) {
                $detail = ['slug' => $slug, 'stage' => 'apply', 'error' => $apply['error'] ?? 'apply_failed'];
                if (isset($apply['sha256'])) $detail['sha256'] = $apply['sha256'];
                $report['errors'][] = $detail;
                continue;
            }
            $report['materialized'][] = [
                'slug' => $slug,
                'constellation_id' => $apply['constellation_id'],
                'sequence' => $apply['sequence'],
            ];
        }
    }

    // 6f: one operator email per cycle for galaxies dropped by origin
    // retraction this tick. (Governance-untrust drops via
    // federation_unmirror_drop_all_for_peer self-notify; the per-slug
    // retraction path does not, so it is notified here.)
    $retractedDropped = $report['retracted']['dropped'] ?? [];
    if ($retractedDropped !== []) {
        $items = array_map(
            static fn(string $slug): array => ['slug' => $slug, 'origin_host' => $host],
            $retractedDropped
        );
        federation_notify_operator_mirror_dropped($items, 'origin_retraction');
    }

    federation_galaxy_pull_state_record_success($peerId);
    return $report;
}

/**
 * Cron-mode entry point: pull every eligible peer in one tick. Returns a
 * report of {peer_id, host, ok, summary} per peer plus rollups for
 * structured logging.
 *
 * @param array{force?:bool} $opts
 * @return array{peers_total:int, peers_ok:int, peers_failed:int, results:list<array<string,mixed>>}
 */
function federation_galaxy_pull_all_eligible(array $opts = []): array {
    $ids = federation_galaxy_pull_eligible_peer_ids();
    $results = [];
    $ok = 0;
    foreach ($ids as $id) {
        $r = federation_galaxy_pull_peer($id, $opts);
        $results[] = $r;
        if ($r['ok']) $ok++;
    }
    return [
        'peers_total' => count($ids),
        'peers_ok' => $ok,
        'peers_failed' => count($ids) - $ok,
        'results' => $results,
    ];
}
