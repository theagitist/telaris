<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 3 pull library against the live Pluriverse.
 *
 * Exercises pluriverse_pull_peers / pluriverse_pull_blacklist /
 * pluriverse_pull_key_events end-to-end:
 *   - first invocation: 200, state row materialises, etag stored
 *   - second invocation: 304 not_modified, state row updated
 *   - self-detection: own hostname/fingerprint skipped from peers
 *
 * Skips if www.telaris.ca is unreachable. Mutates `pluriverse_pull_state`
 * and may insert new `peers` / `pluriverse_blacklist` rows; safe because
 * the upserts are idempotent and the test runs against the dev DB anyway.
 *
 * Spec: P2P federation plan v10 § Layer 2: The local peer list.
 */
final class PluriversePullTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/pluriverse_pull.php';
    }

    private function reachable(): bool
    {
        $ch = curl_init(PLURIVERSE_PULL_BASE_URL . '/api/pluriverse/identity');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    private function clearPullState(string $endpoint): void
    {
        db_ensure_pluriverse_pull_state_table();
        getDB()->prepare("DELETE FROM pluriverse_pull_state WHERE endpoint = :e")
            ->execute([':e' => $endpoint]);
    }

    public function testPeersPullFreshThen304(): void
    {
        if (!$this->reachable()) {
            $this->markTestSkipped(PLURIVERSE_PULL_BASE_URL . ' unreachable; skipping');
        }
        $this->clearPullState(PLURIVERSE_PULL_ENDPOINT_PEERS);

        $r1 = pluriverse_pull_peers();
        $this->assertTrue($r1['ok'], 'first pull failed: ' . ($r1['error'] ?? ''));
        $this->assertSame(200, $r1['status']);
        $this->assertFalse($r1['not_modified']);

        $r2 = pluriverse_pull_peers();
        $this->assertTrue($r2['ok'], 'second pull failed: ' . ($r2['error'] ?? ''));
        $this->assertSame(304, $r2['status']);
        $this->assertTrue($r2['not_modified']);
        $this->assertSame(0, $r2['rows_processed']);
    }

    public function testBlacklistPullShape(): void
    {
        if (!$this->reachable()) {
            $this->markTestSkipped(PLURIVERSE_PULL_BASE_URL . ' unreachable; skipping');
        }
        $this->clearPullState(PLURIVERSE_PULL_ENDPOINT_BLACKLIST);

        $r1 = pluriverse_pull_blacklist();
        $this->assertTrue($r1['ok'], 'first pull failed: ' . ($r1['error'] ?? ''));
        $this->assertContains($r1['status'], [200, 304]);

        $r2 = pluriverse_pull_blacklist();
        $this->assertTrue($r2['ok']);
        $this->assertSame(304, $r2['status']);
    }

    public function testKeyEventsPullShape(): void
    {
        if (!$this->reachable()) {
            $this->markTestSkipped(PLURIVERSE_PULL_BASE_URL . ' unreachable; skipping');
        }
        $this->clearPullState(PLURIVERSE_PULL_ENDPOINT_KEY_EVENTS);

        $r1 = pluriverse_pull_key_events();
        $this->assertTrue($r1['ok'], 'first pull failed: ' . ($r1['error'] ?? ''));
        $this->assertContains($r1['status'], [200, 304]);

        $r2 = pluriverse_pull_key_events();
        $this->assertTrue($r2['ok']);
        $this->assertSame(304, $r2['status']);
    }

    public function testSelfDetectionSkipsOwnPeerRow(): void
    {
        if (!$this->reachable()) {
            $this->markTestSkipped(PLURIVERSE_PULL_BASE_URL . ' unreachable; skipping');
        }
        $this->clearPullState(PLURIVERSE_PULL_ENDPOINT_PEERS);

        $pdo = getDB();
        $pdo->exec("DELETE FROM peers WHERE source = 'registry' AND hostname IN ('starmaps.polivoxia.ca','telaris.polivoxia.ca')");

        $result = pluriverse_pull_peers();
        $this->assertTrue($result['ok']);

        // Our own hostname (the dev / source-of-truth box) must not have been
        // inserted as a peer. Self-detection runs via fingerprint match against
        // either federation_public_key_fingerprint() or the latest
        // pluriverse_applications.remote_fingerprint.
        $cnt = (int)$pdo->query("
            SELECT COUNT(*) FROM peers
            WHERE source = 'registry' AND hostname = 'starmaps.polivoxia.ca'
        ")->fetchColumn();
        $this->assertSame(0, $cnt, 'self-row leaked into local peers');
    }

    public function testPullStatePersistsEtag(): void
    {
        if (!$this->reachable()) {
            $this->markTestSkipped(PLURIVERSE_PULL_BASE_URL . ' unreachable; skipping');
        }
        $this->clearPullState(PLURIVERSE_PULL_ENDPOINT_PEERS);

        pluriverse_pull_peers();
        $state = pluriverse_pull_state_get(PLURIVERSE_PULL_ENDPOINT_PEERS);
        $this->assertNotNull($state['last_etag']);
        $this->assertNotEmpty($state['last_etag']);
    }
}
