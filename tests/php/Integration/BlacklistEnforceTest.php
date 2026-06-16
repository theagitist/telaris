<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 6b Pluriverse-blacklist enforcement.
 *
 * federation_enforce_pluriverse_blacklist() is the consumer the stage-3
 * blacklist pull was missing: after the local pluriverse_blacklist mirror is
 * replaced, match every peer against it and, for each newly-matched peer,
 * flip trust_state='blocked' and uniformly drop its mirrors (the 6a primitive).
 *
 * Fixtures follow the stage-5 convention: ephemeral keypairs, synthetic
 * *.example.invalid hostnames, delete by id in teardown (NEVER by real
 * hostname, per the 8ae3963 regression). Blacklist rows the test inserts are
 * removed by their (synthetic) entry_value.
 *
 * Spec: Stage 6 trust revocation design (6b); v10 § State-change propagation.
 */
final class BlacklistEnforceTest extends TestCase
{
    /** @var list<int> */
    private array $peerIds = [];
    /** @var list<int> */
    private array $cids = [];
    /** @var list<string> */
    private array $blEntryValues = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/blacklist_enforce.php';
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_unmirror.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_federation_attribution_columns();
        db_ensure_pluriverse_blacklist_table();
        db_ensure_pluriverse_log_tables();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        foreach ($this->peerIds as $pid) {
            // Peer FK CASCADEs galaxy_subscriptions + galaxy_publish_whitelist.
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $pid]);
        }
        foreach ($this->blEntryValues as $v) {
            $pdo->prepare("DELETE FROM pluriverse_blacklist WHERE entry_value = :v")->execute([':v' => $v]);
        }
        $this->peerIds = [];
        $this->cids = [];
        $this->blEntryValues = [];
    }

    private function seedPeer(string $host): int
    {
        $pdo = getDB();
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, decode(:k, 'hex'), 'blacklist-enforce test') RETURNING id");
        $ins->execute([
                ':h' => $host,
                ':u' => "https://$host",
                ':e' => "https://$host/api/pluriverse",
                ':k' => bin2hex($pk),
            ]);
        $pid = (int)$ins->fetchColumn();
        $this->peerIds[] = $pid;
        return $pid;
    }

    private function seedMirror(int $peerId, string $host, string $remoteSlug): int
    {
        $pdo = getDB();
        $localSlug = 'mirror-' . bin2hex(random_bytes(3));
        $cid = db_create_constellation('Mirror ' . $remoteSlug, '', $localSlug, 'cosmic');
        $this->cids[] = $cid;
        $importSource = json_encode([
            'kind' => 'federation',
            'origin_host' => $host,
            'remote_slug' => $remoteSlug,
            'sequence' => 1,
            'content_hash' => str_repeat('a', 64),
        ], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE constellations SET mirrored_from_peer_id = :p, import_source = :imp, read_only = TRUE WHERE id = :id")
            ->execute([':p' => $peerId, ':imp' => $importSource, ':id' => $cid]);
        $pdo->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, last_received_sequence, last_content_hash, is_active)
                       VALUES (:p, :s, :cid, 1, :hash, TRUE)")
            ->execute([':p' => $peerId, ':s' => $remoteSlug, ':cid' => $cid, ':hash' => str_repeat('a', 64)]);
        return $cid;
    }

    private function seedBlacklistEntry(string $type, string $value, string $reason = ''): void
    {
        getDB()->prepare("INSERT INTO pluriverse_blacklist (entry_type, entry_value, reason, added_at)
                          VALUES (:t, :v, :r, NOW())
                          ON CONFLICT (entry_type, entry_value) DO UPDATE SET reason = EXCLUDED.reason")
            ->execute([':t' => $type, ':v' => $value, ':r' => $reason]);
        $this->blEntryValues[] = $value;
    }

    private function enforcedFor(array $result, string $host): ?array
    {
        foreach ($result['enforced'] as $en) {
            if ($en['hostname'] === $host) {
                return $en;
            }
        }
        return null;
    }

    /* ---- matcher unit ---------------------------------------------------- */

    public function testMatcherHostnameExactCaseInsensitive(): void
    {
        $this->assertTrue(federation_blacklist_matches_host('Node.Example.Invalid', 'hostname', 'node.example.invalid'));
        $this->assertFalse(federation_blacklist_matches_host('other.example.invalid', 'hostname', 'node.example.invalid'));
    }

    public function testMatcherDomainSuffixAtLabelBoundary(): void
    {
        $this->assertTrue(federation_blacklist_matches_host('example.invalid', 'domain', 'example.invalid'));
        $this->assertTrue(federation_blacklist_matches_host('node.example.invalid', 'domain', 'example.invalid'));
        // No false-positive on a non-boundary suffix.
        $this->assertFalse(federation_blacklist_matches_host('notexample.invalid', 'domain', 'example.invalid'));
        $this->assertFalse(federation_blacklist_matches_host('evil-example.invalid', 'domain', 'example.invalid'));
    }

    public function testMatcherIpNeverMatchesPeer(): void
    {
        $this->assertFalse(federation_blacklist_matches_host('192.0.2.5', 'ip', '192.0.2.5'));
    }

    /* ---- enforcement ----------------------------------------------------- */

    public function testNewHostnameMatchBlocksAndDropsMirror(): void
    {
        $host = 'bad-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $pid = $this->seedPeer($host);
        $cid = $this->seedMirror($pid, $host, 'slug-a');
        $this->seedBlacklistEntry('hostname', $host, 'abuse');

        $res = federation_enforce_pluriverse_blacklist();
        $this->assertTrue($res['ok']);
        $mine = $this->enforcedFor($res, $host);
        $this->assertNotNull($mine, 'the matched peer is in the enforced list');
        $this->assertSame(['slug-a'], $mine['dropped']);

        $pdo = getDB();
        $this->assertSame('blocked', (string)$pdo->query("SELECT trust_state FROM peers WHERE id = $pid")->fetchColumn());
        $reason = (string)$pdo->query("SELECT local_blacklisted_reason FROM peers WHERE id = $pid")->fetchColumn();
        $this->assertSame('pluriverse-blacklist:abuse', $reason);
        // Mirror + subscription gone.
        $this->cids = array_values(array_diff($this->cids, [$cid]));
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = $pid")->fetchColumn());
    }

    public function testDomainSuffixMatchBlocks(): void
    {
        $domain = 'evil-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $host = 'node.' . $domain;
        $pid = $this->seedPeer($host);
        $cid = $this->seedMirror($pid, $host, 'slug-b');
        $this->seedBlacklistEntry('domain', $domain, 'domain-wide block');

        $res = federation_enforce_pluriverse_blacklist();
        $this->assertNotNull($this->enforcedFor($res, $host));
        $this->assertSame('blocked', (string)getDB()->query("SELECT trust_state FROM peers WHERE id = $pid")->fetchColumn());
        $this->cids = array_values(array_diff($this->cids, [$cid]));
    }

    public function testAlreadyBlockedPeerIsNoOp(): void
    {
        $host = 'blocked-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $pid = $this->seedPeer($host);
        getDB()->prepare("UPDATE peers SET trust_state = 'blocked', local_blacklisted_reason = 'local:other:pre-existing' WHERE id = :p")
            ->execute([':p' => $pid]);
        $this->seedBlacklistEntry('hostname', $host, 'abuse');

        $res = federation_enforce_pluriverse_blacklist();
        $this->assertNull($this->enforcedFor($res, $host), 'an already-blocked peer is skipped');
        // Reason left as the pre-existing one (not overwritten by the blacklist pass).
        $reason = (string)getDB()->query("SELECT local_blacklisted_reason FROM peers WHERE id = $pid")->fetchColumn();
        $this->assertSame('local:other:pre-existing', $reason);
    }

    public function testUnrelatedPeerUntouched(): void
    {
        $badHost = 'bad-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $goodHost = 'good-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $badPid = $this->seedPeer($badHost);
        $goodPid = $this->seedPeer($goodHost);
        $goodCid = $this->seedMirror($goodPid, $goodHost, 'slug-good');
        $this->seedBlacklistEntry('hostname', $badHost, 'abuse');

        federation_enforce_pluriverse_blacklist();
        $pdo = getDB();
        $this->assertSame('discovered', (string)$pdo->query("SELECT trust_state FROM peers WHERE id = $goodPid")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = $goodPid")->fetchColumn(), 'unrelated mirror survives');
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM constellations WHERE id = $goodCid")->fetchColumn());
    }

    public function testEntryMatchingNoPeerIsHarmless(): void
    {
        $orphan = 'never-a-peer-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $this->seedBlacklistEntry('hostname', $orphan, 'no peer here');
        $res = federation_enforce_pluriverse_blacklist();
        $this->assertTrue($res['ok']);
        $this->assertNull($this->enforcedFor($res, $orphan));
    }

    public function testEnforcementWritesAuditRow(): void
    {
        $host = 'audit-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $pid = $this->seedPeer($host);
        $cid = $this->seedMirror($pid, $host, 'slug-audit');
        $this->seedBlacklistEntry('hostname', $host, 'abuse');

        federation_enforce_pluriverse_blacklist();
        $this->cids = array_values(array_diff($this->cids, [$cid]));

        $row = getDB()->prepare("SELECT outcome, details_summary FROM pluriverse_log WHERE event_type = 'peer_untrust_drop' AND target = :h ORDER BY id DESC LIMIT 1");
        $row->execute([':h' => $host]);
        $log = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log, 'drop_all wrote a peer_untrust_drop audit row');
        $this->assertStringContainsString('reason=pluriverse-blacklist', $log['details_summary']);
    }
}
