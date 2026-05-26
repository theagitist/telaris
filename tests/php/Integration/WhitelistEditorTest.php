<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 4f admin Whitelist editor.
 *
 * Exercises the db_set_peer_publish_whitelist / db_add_peer_subscription /
 * db_remove_peer_subscription helpers + db_recompute_peer_active_whitelist
 * against the live DB. Verifies:
 *   - publish-list save creates rows and flips has_active_whitelist
 *   - publish-list save performs a diff (adds new IDs, removes absent ones)
 *   - publish-list refuses mirrored galaxies without partial writes
 *   - subscription add is idempotent on (peer_id, remote_slug)
 *   - emptying both sides clears has_active_whitelist
 *
 * Spec: Stage 4 handshake design § Whitelist editor (4f).
 */
final class WhitelistEditorTest extends TestCase
{
    private const REMOTE_HOST = 'whitelist-test.example.invalid';
    private const PEER_HOST_2 = 'whitelist-test-other.example.invalid';
    private int $peerId = 0;
    private int $otherPeerId = 0;
    private int $galaxyA = 0;
    private int $galaxyB = 0;
    private int $galaxyMirrored = 0;

    protected function setUp(): void
    {
        $pdo = getDB();
        // Make sure tables exist before any cleanup query touches them.
        db_ensure_peers_table();
        db_ensure_galaxy_publish_whitelist_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_federation_attribution_columns();

        foreach ([self::REMOTE_HOST, self::PEER_HOST_2] as $h) {
            $pdo->prepare("DELETE FROM galaxy_publish_whitelist WHERE peer_id IN (SELECT id FROM peers WHERE hostname = :h)")->execute([':h' => $h]);
            $pdo->prepare("DELETE FROM galaxy_subscriptions WHERE peer_id IN (SELECT id FROM peers WHERE hostname = :h)")->execute([':h' => $h]);
            $pdo->prepare("DELETE FROM peers WHERE hostname = :h")->execute([':h' => $h]);
        }
        $pdo->prepare("DELETE FROM constellations WHERE slug LIKE 'wlist-test-%'")->execute();

        $insPeer = $pdo->prepare("
            INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, source, trust_state)
            VALUES (:h, :u, :p, :k, :l, 'manual', 'contacted')
        ");
        $insPeer->execute([
            ':h' => self::REMOTE_HOST,
            ':u' => 'https://' . self::REMOTE_HOST,
            ':p' => 'https://' . self::REMOTE_HOST . '/api/pluriverse',
            ':k' => sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\xA1", SODIUM_CRYPTO_SIGN_SEEDBYTES))),
            ':l' => 'wlist-test peer',
        ]);
        $this->peerId = (int)$pdo->lastInsertId();

        $insPeer->execute([
            ':h' => self::PEER_HOST_2,
            ':u' => 'https://' . self::PEER_HOST_2,
            ':p' => 'https://' . self::PEER_HOST_2 . '/api/pluriverse',
            ':k' => sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\xA2", SODIUM_CRYPTO_SIGN_SEEDBYTES))),
            ':l' => 'wlist-test peer 2',
        ]);
        $this->otherPeerId = (int)$pdo->lastInsertId();

        // Authored galaxies (mirrored_from_peer_id IS NULL, type = 'galaxy').
        $insGx = $pdo->prepare("INSERT INTO constellations (name, slug, theme, `type`) VALUES (:n, :s, 'default', 'galaxy')");
        $insGx->execute([':n' => 'WList Test A', ':s' => 'wlist-test-a']);
        $this->galaxyA = (int)$pdo->lastInsertId();
        $insGx->execute([':n' => 'WList Test B', ':s' => 'wlist-test-b']);
        $this->galaxyB = (int)$pdo->lastInsertId();

        // One mirrored galaxy (must be refused by publish-list).
        $pdo->prepare("INSERT INTO constellations (name, slug, theme, `type`, mirrored_from_peer_id) VALUES (:n, :s, 'default', 'galaxy', :p)")
            ->execute([':n' => 'WList Test Mirrored', ':s' => 'wlist-test-mirrored', ':p' => $this->otherPeerId]);
        $this->galaxyMirrored = (int)$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ([self::REMOTE_HOST, self::PEER_HOST_2] as $h) {
            $pdo->prepare("DELETE FROM galaxy_publish_whitelist WHERE peer_id IN (SELECT id FROM peers WHERE hostname = :h)")->execute([':h' => $h]);
            $pdo->prepare("DELETE FROM galaxy_subscriptions WHERE peer_id IN (SELECT id FROM peers WHERE hostname = :h)")->execute([':h' => $h]);
            $pdo->prepare("DELETE FROM peers WHERE hostname = :h")->execute([':h' => $h]);
        }
        $pdo->prepare("DELETE FROM constellations WHERE slug LIKE 'wlist-test-%'")->execute();
    }

    private function readActiveFlag(int $peerId): bool
    {
        $stmt = getDB()->prepare("SELECT has_active_whitelist FROM peers WHERE id = :p");
        $stmt->execute([':p' => $peerId]);
        return (bool)$stmt->fetchColumn();
    }

    public function testSavePublishWhitelistCreatesRowsAndFlipsFlag(): void
    {
        $this->assertFalse($this->readActiveFlag($this->peerId), 'flag starts FALSE');

        $r = db_set_peer_publish_whitelist($this->peerId, [$this->galaxyA, $this->galaxyB], 'admin@example.test');
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['added']);
        $this->assertSame(0, $r['removed']);

        $stored = db_get_peer_publish_whitelist($this->peerId);
        sort($stored);
        $expected = [$this->galaxyA, $this->galaxyB];
        sort($expected);
        $this->assertSame($expected, $stored);
        $this->assertTrue($this->readActiveFlag($this->peerId), 'flag flips TRUE after publish rows exist');
    }

    public function testSavePublishWhitelistDiffsAddsAndRemoves(): void
    {
        // Seed with A only.
        db_set_peer_publish_whitelist($this->peerId, [$this->galaxyA], 'admin@example.test');
        $this->assertSame([$this->galaxyA], db_get_peer_publish_whitelist($this->peerId));

        // Replace with B only: should remove A, add B.
        $r = db_set_peer_publish_whitelist($this->peerId, [$this->galaxyB], 'admin@example.test');
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['added']);
        $this->assertSame(1, $r['removed']);
        $this->assertSame([$this->galaxyB], db_get_peer_publish_whitelist($this->peerId));
    }

    public function testSavePublishWhitelistRefusesMirroredGalaxy(): void
    {
        // Seed with A.
        db_set_peer_publish_whitelist($this->peerId, [$this->galaxyA], 'admin@example.test');

        // Attempt to add mirrored: entire request must fail, no partial state change.
        $r = db_set_peer_publish_whitelist($this->peerId, [$this->galaxyA, $this->galaxyMirrored], 'admin@example.test');
        $this->assertFalse($r['ok']);
        $this->assertSame('mirrored_in_publish_set', $r['reason']);

        // Existing state untouched.
        $this->assertSame([$this->galaxyA], db_get_peer_publish_whitelist($this->peerId));
    }

    public function testAddSubscriptionIsIdempotent(): void
    {
        $r1 = db_add_peer_subscription($this->peerId, 'remote-slug-x', 'admin@example.test');
        $this->assertTrue($r1['ok']);
        $this->assertArrayHasKey('subscription_id', $r1);
        $this->assertTrue($this->readActiveFlag($this->peerId));

        // Duplicate (peer_id, remote_slug) on an active row -> no-op with marker.
        $r2 = db_add_peer_subscription($this->peerId, 'remote-slug-x', 'admin@example.test');
        $this->assertTrue($r2['ok']);
        $this->assertSame('exists_active', $r2['reason']);
        $this->assertSame($r1['subscription_id'], $r2['subscription_id']);

        $subs = db_get_peer_subscriptions($this->peerId);
        $this->assertCount(1, $subs);
    }

    public function testActiveFlagClearsWhenAllRowsGone(): void
    {
        // Put one publish row and one subscription on the peer.
        db_set_peer_publish_whitelist($this->peerId, [$this->galaxyA], 'admin@example.test');
        $sub = db_add_peer_subscription($this->peerId, 'remote-slug-y', 'admin@example.test');
        $this->assertTrue($this->readActiveFlag($this->peerId));

        // Remove the subscription. Flag still TRUE (publish row remains).
        $rRem = db_remove_peer_subscription($this->peerId, (int)$sub['subscription_id']);
        $this->assertTrue($rRem['ok']);
        $this->assertTrue($this->readActiveFlag($this->peerId));

        // Empty the publish list. Now both sides are clear → flag flips FALSE.
        $rEmpty = db_set_peer_publish_whitelist($this->peerId, [], 'admin@example.test');
        $this->assertTrue($rEmpty['ok']);
        $this->assertSame(0, $rEmpty['added']);
        $this->assertSame(1, $rEmpty['removed']);
        $this->assertFalse($this->readActiveFlag($this->peerId));
    }

    public function testRemoveSubscriptionEnforcesPeerMatch(): void
    {
        $sub = db_add_peer_subscription($this->peerId, 'remote-slug-z', 'admin@example.test');
        $this->assertTrue($sub['ok']);
        $r = db_remove_peer_subscription($this->otherPeerId, (int)$sub['subscription_id']);
        $this->assertFalse($r['ok']);
        $this->assertSame('peer_mismatch', $r['reason']);
        // Row still exists on the original peer.
        $this->assertCount(1, db_get_peer_subscriptions($this->peerId));
    }
}
