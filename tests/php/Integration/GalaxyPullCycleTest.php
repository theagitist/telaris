<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5d-v orchestrator state machine.
 *
 * Covers the backoff schedule, the per-peer state helpers, the eligibility
 * predicate (active-subscription gate, cooldown gate), and the cooldown
 * branch of federation_galaxy_pull_peer (which short-circuits without
 * touching the network). The full per-peer cycle is verified live via
 * `php bin/galaxy-pull --status` / `--peer=` against a real peer, the same
 * posture as the dispatcher: its PHPUnit surface is the state machine, not
 * the wire.
 *
 * Spec: Stage 5 galaxy publish design (5d-v).
 */
final class GalaxyPullCycleTest extends TestCase
{
    /** @var list<int> */
    private array $peerIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_pull_cycle.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_peer_pull_state_table();
    }

    protected function tearDown(): void
    {
        if ($this->peerIds === []) return;
        $pdo = getDB();
        $in = implode(',', array_fill(0, count($this->peerIds), '?'));
        // peer FK CASCADEs subscriptions + pull-state.
        $pdo->prepare("DELETE FROM peers WHERE id IN ($in)")->execute($this->peerIds);
        $this->peerIds = [];
    }

    private function makePeer(string $host = ''): int
    {
        $sfx = bin2hex(random_bytes(4));
        $host = $host !== '' ? $host : "cycle-$sfx.example.invalid";
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $pdo = getDB();
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, decode(:k, 'hex'), 'cycle test') RETURNING id");
        $ins->execute([
                ':h' => $host,
                ':u' => "https://{$host}",
                ':e' => "https://{$host}/api/pluriverse",
                ':k' => bin2hex($pk),
            ]);
        $id = (int)$ins->fetchColumn();
        $this->peerIds[] = $id;
        return $id;
    }

    private function subscribe(int $peerId, string $slug = 'some-galaxy'): void
    {
        getDB()->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, is_active) VALUES (:p, :s, TRUE)")
            ->execute([':p' => $peerId, ':s' => $slug]);
    }

    /* ---- backoff schedule ----------------------------------------------- */

    public function testBackoffScheduleMatchesDispatcherCurve(): void
    {
        // Dispatcher curve: 1m, 5m, 30m, 2h, 6h, 12h, 24h, then 24h cap.
        $this->assertSame(0, federation_galaxy_pull_backoff_seconds(0), 'no failures = no delay');
        $this->assertSame(60, federation_galaxy_pull_backoff_seconds(1));
        $this->assertSame(300, federation_galaxy_pull_backoff_seconds(2));
        $this->assertSame(1800, federation_galaxy_pull_backoff_seconds(3));
        $this->assertSame(7200, federation_galaxy_pull_backoff_seconds(4));
        $this->assertSame(21600, federation_galaxy_pull_backoff_seconds(5));
        $this->assertSame(43200, federation_galaxy_pull_backoff_seconds(6));
        $this->assertSame(86400, federation_galaxy_pull_backoff_seconds(7));
        $this->assertSame(86400, federation_galaxy_pull_backoff_seconds(99), 'caps at 24h; no give-up');
    }

    /* ---- state helpers --------------------------------------------------- */

    public function testStateGetReturnsZeroedDefaultForUnseenPeer(): void
    {
        $pid = $this->makePeer();
        $s = federation_galaxy_pull_state_get($pid);
        $this->assertSame($pid, $s['peer_id']);
        $this->assertNull($s['last_pull_succeeded_at']);
        $this->assertNull($s['next_pull_at']);
        $this->assertSame(0, $s['consecutive_failures']);
    }

    public function testStateRecordSuccessClearsFailure(): void
    {
        $pid = $this->makePeer();
        federation_galaxy_pull_state_record_failure($pid, 'first error');
        federation_galaxy_pull_state_record_failure($pid, 'second error');
        $mid = federation_galaxy_pull_state_get($pid);
        $this->assertSame(2, $mid['consecutive_failures']);
        $this->assertNotNull($mid['next_pull_at']);

        federation_galaxy_pull_state_record_success($pid);
        $after = federation_galaxy_pull_state_get($pid);
        $this->assertSame(0, $after['consecutive_failures']);
        $this->assertNull($after['last_error']);
        $this->assertNull($after['next_pull_at']);
        $this->assertNotNull($after['last_pull_succeeded_at']);
    }

    public function testStateRecordFailureBumpsCounterAndSetsBackoff(): void
    {
        $pid = $this->makePeer();
        federation_galaxy_pull_state_record_failure($pid, 'curl: connection refused');

        $s = federation_galaxy_pull_state_get($pid);
        $this->assertSame(1, $s['consecutive_failures']);
        $this->assertSame('curl: connection refused', $s['last_error']);
        $this->assertNotNull($s['next_pull_at']);
        $delta = strtotime($s['next_pull_at']) - time();
        // 1-failure backoff is 60s; allow a couple of seconds of clock slop.
        $this->assertGreaterThanOrEqual(55, $delta);
        $this->assertLessThanOrEqual(65, $delta);

        federation_galaxy_pull_state_record_failure($pid, 'http_503');
        $s2 = federation_galaxy_pull_state_get($pid);
        $this->assertSame(2, $s2['consecutive_failures']);
        $delta2 = strtotime($s2['next_pull_at']) - time();
        // 2-failure backoff is 300s.
        $this->assertGreaterThanOrEqual(295, $delta2);
        $this->assertLessThanOrEqual(305, $delta2);
    }

    public function testStateRecordFailureTruncatesLongError(): void
    {
        $pid = $this->makePeer();
        $long = str_repeat('x', 1000);
        federation_galaxy_pull_state_record_failure($pid, $long);
        $s = federation_galaxy_pull_state_get($pid);
        $this->assertSame(255, strlen($s['last_error']), 'fits in VARCHAR(255)');
    }

    /* ---- eligibility ----------------------------------------------------- */

    public function testEligibilityRequiresActiveSubscription(): void
    {
        $a = $this->makePeer('with-sub.example.invalid');
        $b = $this->makePeer('no-sub.example.invalid');
        $this->subscribe($a);
        $ids = federation_galaxy_pull_eligible_peer_ids();
        $this->assertContains($a, $ids);
        $this->assertNotContains($b, $ids, 'peer with no active subscription is not pulled');
    }

    public function testEligibilityExcludesInactiveSubscriptions(): void
    {
        $p = $this->makePeer();
        getDB()->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, is_active) VALUES (:p, 'paused', FALSE)")
            ->execute([':p' => $p]);
        $this->assertNotContains($p, federation_galaxy_pull_eligible_peer_ids());
    }

    public function testEligibilityExcludesPeersOnCooldown(): void
    {
        $p = $this->makePeer();
        $this->subscribe($p);
        federation_galaxy_pull_state_record_failure($p, 'transient');
        $this->assertNotContains($p, federation_galaxy_pull_eligible_peer_ids(), 'cooldown 60s in the future');

        // Force next_pull_at into the past: peer is eligible again.
        getDB()->prepare("UPDATE peer_pull_state SET next_pull_at = NOW() - INTERVAL '1 SECOND' WHERE peer_id = :p")
            ->execute([':p' => $p]);
        $this->assertContains($p, federation_galaxy_pull_eligible_peer_ids());
    }

    /* ---- cycle short-circuit -------------------------------------------- */

    public function testPullPeerShortCircuitsOnCooldown(): void
    {
        $p = $this->makePeer();
        $this->subscribe($p);
        federation_galaxy_pull_state_record_failure($p, 'first failure');

        $res = federation_galaxy_pull_peer($p, ['force' => false]);
        $this->assertFalse($res['ok']);
        $this->assertSame('on_cooldown', $res['error']);
        // consecutive_failures stays at 1: cooldown branch must not double-bump.
        $this->assertSame(1, federation_galaxy_pull_state_get($p)['consecutive_failures']);
    }

    public function testPullPeerReportsUnknownPeer(): void
    {
        $res = federation_galaxy_pull_peer(99999999, []);
        $this->assertFalse($res['ok']);
        $this->assertSame('unknown_peer', $res['error']);
    }
}
