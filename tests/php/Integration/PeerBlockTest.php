<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 6d operator local block / unblock.
 *
 * federation_peer_block() / federation_peer_unblock() live in peer_block.php
 * (split from admin/peer-block.php so the DB effects are testable without the
 * auth + CSRF + re-auth scaffolding). This drives them directly.
 *
 * Fixtures follow the stage-5/6 convention: ephemeral keypairs, synthetic
 * *.example.invalid hostnames, delete by id in teardown (NEVER by real
 * hostname, per the 8ae3963 regression).
 *
 * Spec: Stage 6 trust revocation design (6d).
 */
final class PeerBlockTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';
    /** @var list<int> */
    private array $cids = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/peer_block.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_galaxy_publish_whitelist_table();
        db_ensure_federation_attribution_columns();
        db_ensure_pluriverse_log_tables();

        $kp = sodium_crypto_sign_keypair();
        $sfx = bin2hex(random_bytes(4));
        $this->host = "block-$sfx.example.invalid";
        $pdo = getDB();
        $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, trust_state)
                       VALUES (:h, :u, :e, :k, '6d test', 'whitelisted')")
            ->execute([
                ':h' => $this->host,
                ':u' => "https://{$this->host}",
                ':e' => "https://{$this->host}/api/pluriverse",
                ':k' => sodium_crypto_sign_publickey($kp),
            ]);
        $this->peerId = (int)$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        if ($this->peerId > 0) {
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]);
        }
    }

    private function seedMirror(string $remoteSlug): int
    {
        $pdo = getDB();
        $localSlug = 'mirror-' . bin2hex(random_bytes(3));
        $cid = db_create_constellation('Mirror ' . $remoteSlug, '', $localSlug, 'cosmic');
        $this->cids[] = $cid;
        $importSource = json_encode([
            'kind' => 'federation', 'origin_host' => $this->host,
            'remote_slug' => $remoteSlug, 'sequence' => 1, 'content_hash' => str_repeat('a', 64),
        ], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE constellations SET mirrored_from_peer_id = :p, import_source = :imp, read_only = TRUE WHERE id = :id")
            ->execute([':p' => $this->peerId, ':imp' => $importSource, ':id' => $cid]);
        $pdo->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, last_received_sequence, last_content_hash, is_active)
                       VALUES (:p, :s, :cid, 1, :hash, TRUE)")
            ->execute([':p' => $this->peerId, ':s' => $remoteSlug, ':cid' => $cid, ':hash' => str_repeat('a', 64)]);
        return $cid;
    }

    private function seedPublishOffer(): void
    {
        $pdo = getDB();
        $localSlug = 'authored-' . bin2hex(random_bytes(3));
        $cid = db_create_constellation('Authored offer', '', $localSlug, 'cosmic');
        $this->cids[] = $cid;
        $pdo->prepare("INSERT INTO galaxy_publish_whitelist (peer_id, constellation_id, added_by) VALUES (:p, :c, 'test')")
            ->execute([':p' => $this->peerId, ':c' => $cid]);
        db_recompute_peer_active_whitelist($this->peerId);
    }

    public function testBlockFlipsTrustDropsMirrorsAndClearsPublishOffer(): void
    {
        $cid = $this->seedMirror('slug-a');
        $this->seedPublishOffer();

        $res = federation_peer_block($this->peerId, 'consent', 'community withdrew consent', 'admin@example.invalid');
        $this->assertTrue($res['ok']);
        $this->assertSame(['slug-a'], $res['dropped']);
        $this->assertSame(1, $res['publish_entries_cleared']);

        $pdo = getDB();
        $this->assertSame('blocked', (string)$pdo->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn());
        $this->assertSame('local:consent:community withdrew consent', (string)$pdo->query("SELECT local_blacklisted_reason FROM peers WHERE id = {$this->peerId}")->fetchColumn());

        $this->cids = array_values(array_diff($this->cids, [$cid]));
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = {$this->peerId}")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_publish_whitelist WHERE peer_id = {$this->peerId}")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT has_active_whitelist FROM peers WHERE id = {$this->peerId}")->fetchColumn());
    }

    public function testBlockRejectsInvalidCategory(): void
    {
        $res = federation_peer_block($this->peerId, 'bogus', 'reason', 'admin@example.invalid');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_category', $res['error']);
        $this->assertSame('whitelisted', (string)getDB()->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn());
    }

    public function testBlockRejectsEmptyReason(): void
    {
        $res = federation_peer_block($this->peerId, 'other', '   ', 'admin@example.invalid');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_reason', $res['error']);
    }

    public function testBlockRejectsUnknownPeer(): void
    {
        $res = federation_peer_block(0, 'other', 'reason', 'admin@example.invalid');
        $this->assertFalse($res['ok']);
        $this->assertSame('peer_not_found', $res['error']);
    }

    public function testUnblockReturnsToDiscoveredWithoutRestoringContent(): void
    {
        $cid = $this->seedMirror('slug-a');
        federation_peer_block($this->peerId, 'spam', 'spammy', 'admin@example.invalid');
        $this->cids = array_values(array_diff($this->cids, [$cid]));

        $res = federation_peer_unblock($this->peerId, 'admin@example.invalid');
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['changed']);

        $pdo = getDB();
        $this->assertSame('discovered', (string)$pdo->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn());
        $this->assertNull($pdo->query("SELECT local_blacklisted_reason FROM peers WHERE id = {$this->peerId}")->fetchColumn());
        // Content stays gone: unblock does not restore the dropped mirror.
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = {$this->peerId}")->fetchColumn());
    }

    public function testUnblockIsNoOpForNonBlockedPeer(): void
    {
        $res = federation_peer_unblock($this->peerId, 'admin@example.invalid');
        $this->assertTrue($res['ok']);
        $this->assertFalse($res['changed'], 'peer was whitelisted, not blocked: nothing to change');
        $this->assertSame('whitelisted', (string)getDB()->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn());
    }

    public function testBlockWritesOperatorAuditRow(): void
    {
        federation_peer_block($this->peerId, 'legal', 'takedown notice', 'admin@example.invalid');
        $row = getDB()->prepare("SELECT actor, details_summary FROM pluriverse_log WHERE event_type = 'peer_block' AND target = :h ORDER BY id DESC LIMIT 1");
        $row->execute([':h' => $this->host]);
        $log = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);
        $this->assertSame('admin@example.invalid', $log['actor']);
        $this->assertStringContainsString('category=legal', $log['details_summary']);
    }
}
