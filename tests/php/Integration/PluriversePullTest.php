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
        // Wipe only the self-row, never sibling peers. Wiping a real sibling
        // hostname here used to silently reset an operator-set trust_state
        // (e.g. whitelisted) the next pluriverse-pull tick, since the INSERT
        // branch starts every fresh row at trust_state='discovered'.
        $pdo->exec("DELETE FROM peers WHERE source = 'registry' AND hostname = 'starmaps.polivoxia.ca'");

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

    /**
     * Regression: when a peer row is wiped (e.g. an old test cleanup, or an
     * accidental admin DELETE), the next pluriverse-pull tick used to re-insert
     * it with trust_state='discovered', silently regressing any prior
     * operator-confirmed whitelisting. The handshake row survives a peer-row
     * delete (no FK CASCADE), so it is a durable record of that agreement and
     * is now the source for restoring the trust state on re-discovery.
     */
    public function testRediscoveryRestoresTrustFromHandshake(): void
    {
        if (!$this->reachable()) {
            $this->markTestSkipped(PLURIVERSE_PULL_BASE_URL . ' unreachable; skipping');
        }
        require_once dirname(__DIR__, 3) . '/inc/federation/handshake.php';

        $pdo = getDB();
        db_ensure_handshakes_table();
        db_ensure_pluriverse_log_tables();

        // The live registry currently advertises starmaps + telaris. The
        // self-row is skipped, so the only INSERT path the pull can reach is
        // for telaris.polivoxia.ca. Anchor the test on that hostname.
        $target = 'telaris.polivoxia.ca';

        // Ensure there is at least one `complete` handshake row for the
        // target hostname (idempotent seed so the test runs even on a fresh
        // dev box that has never handshaked). Use a synthetic thread_id
        // unique to the test so no real handshake gets clobbered.
        $threadId = 'rediscovery-restore-test-' . bin2hex(random_bytes(6));
        $expires = date('Y-m-d H:i:s', time() + 86400 * 30);
        $insHs = $pdo->prepare("
            INSERT INTO handshakes
                (peer_id, remote_hostname, initiator, status,
                 requested_galaxies_publish, requested_galaxies_subscribe,
                 thread_id, expires_at)
            VALUES (NULL, :h, 'us', 'complete', '[]', '[]', :t, :e) RETURNING id
        ");
        $insHs->execute([':h' => $target, ':t' => $threadId, ':e' => $expires]);
        $seededHandshakeId = (int)$insHs->fetchColumn();

        // Wipe the peer row so the INSERT branch fires on next pull.
        $pdo->exec("DELETE FROM peers WHERE source = 'registry' AND hostname = " . $pdo->quote($target));
        $this->clearPullState(PLURIVERSE_PULL_ENDPOINT_PEERS);

        try {
            $result = pluriverse_pull_peers();
            $this->assertTrue($result['ok'], 'pull failed: ' . ($result['error'] ?? ''));

            $row = $pdo->prepare("SELECT id, trust_state FROM peers WHERE hostname = :h AND source = 'registry'");
            $row->execute([':h' => $target]);
            $peer = $row->fetch(PDO::FETCH_ASSOC);
            $this->assertNotFalse($peer, 'peer row was not re-inserted');
            $this->assertSame(
                'whitelisted',
                $peer['trust_state'],
                'INSERT branch should restore trust_state from the completed handshake'
            );

            $newPeerId = (int)$peer['id'];
            $hsCheck = $pdo->prepare("SELECT peer_id FROM handshakes WHERE id = :i");
            $hsCheck->execute([':i' => $seededHandshakeId]);
            $linkedPeerId = $hsCheck->fetchColumn();
            $this->assertSame(
                $newPeerId,
                (int)$linkedPeerId,
                'handshake row should be relinked to the new peer id'
            );

            $logCnt = (int)$pdo->query(
                "SELECT COUNT(*) FROM pluriverse_log
                 WHERE event_type = 'peer_trust_state_restored'
                   AND target = " . $pdo->quote($target) . "
                   AND created_at > NOW() - INTERVAL '5 MINUTE'"
            )->fetchColumn();
            $this->assertGreaterThan(0, $logCnt, 'restoration should be audited');
        } finally {
            // Always remove the synthetic handshake so the test doesn't leave
            // detritus. The peer row stays; it will be the legitimate row
            // for the live sibling instance.
            $pdo->prepare("DELETE FROM handshakes WHERE id = :i")
                ->execute([':i' => $seededHandshakeId]);
        }
    }
}
