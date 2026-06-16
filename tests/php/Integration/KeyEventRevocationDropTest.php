<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 6c key-event revocation drop.
 *
 * federation_key_events_apply_peer_event() lives in key_events_apply.php (split
 * from the HTTP handler so it is testable in isolation). This exercises the
 * apply side-effects directly:
 *   - revocation  -> peer blocked AND every mirror uniformly dropped (6a);
 *   - revocation replayed -> idempotent, dropped:[] on the second pass;
 *   - compromise  -> key swapped, NOT dropped (still trusted under new key).
 *
 * Fixtures follow the stage-5/6 convention: ephemeral keypairs, synthetic
 * *.example.invalid hostnames, delete by id in teardown (NEVER by real
 * hostname, per the 8ae3963 regression).
 *
 * Spec: Stage 6 trust revocation design (6c); v10 § State-change propagation.
 */
final class KeyEventRevocationDropTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';
    private string $pk = '';
    /** @var list<int> */
    private array $cids = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/key_events_apply.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_federation_attribution_columns();
        db_ensure_pluriverse_log_tables();

        $kp = sodium_crypto_sign_keypair();
        $this->pk = sodium_crypto_sign_publickey($kp);
        $sfx = bin2hex(random_bytes(4));
        $this->host = "revoke-$sfx.example.invalid";
        $pdo = getDB();
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, trust_state)
                       VALUES (:h, :u, :e, decode(:k, 'hex'), '6c test', 'whitelisted') RETURNING id");
        $ins->execute([
                ':h' => $this->host,
                ':u' => "https://{$this->host}",
                ':e' => "https://{$this->host}/api/pluriverse",
                ':k' => bin2hex($this->pk),
            ]);
        $this->peerId = (int)$ins->fetchColumn();
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
            'kind' => 'federation',
            'origin_host' => $this->host,
            'remote_slug' => $remoteSlug,
            'sequence' => 1,
            'content_hash' => str_repeat('a', 64),
        ], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE constellations SET mirrored_from_peer_id = :p, import_source = :imp, read_only = TRUE WHERE id = :id")
            ->execute([':p' => $this->peerId, ':imp' => $importSource, ':id' => $cid]);
        $pdo->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, last_received_sequence, last_content_hash, is_active)
                       VALUES (:p, :s, :cid, 1, :hash, TRUE)")
            ->execute([':p' => $this->peerId, ':s' => $remoteSlug, ':cid' => $cid, ':hash' => str_repeat('a', 64)]);
        return $cid;
    }

    public function testRevocationBlocksAndDropsMirrors(): void
    {
        $cidA = $this->seedMirror('slug-a');
        $cidB = $this->seedMirror('slug-b');

        $res = federation_key_events_apply_peer_event('revocation', $this->host, []);
        $this->assertTrue($res['ok']);
        sort($res['dropped']);
        $this->assertSame(['slug-a', 'slug-b'], $res['dropped']);

        $pdo = getDB();
        $this->assertSame('blocked', (string)$pdo->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn());
        $this->assertSame('pluriverse-revoked', (string)$pdo->query("SELECT local_blacklisted_reason FROM peers WHERE id = {$this->peerId}")->fetchColumn());

        $this->cids = array_values(array_diff($this->cids, [$cidA, $cidB]));
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM constellations WHERE id IN ($cidA, $cidB)")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = {$this->peerId}")->fetchColumn());
    }

    public function testRevocationIsIdempotentOnReplay(): void
    {
        $cid = $this->seedMirror('slug-a');
        $first = federation_key_events_apply_peer_event('revocation', $this->host, []);
        $this->assertSame(['slug-a'], $first['dropped']);
        $this->cids = array_values(array_diff($this->cids, [$cid]));

        $second = federation_key_events_apply_peer_event('revocation', $this->host, []);
        $this->assertTrue($second['ok']);
        $this->assertSame([], $second['dropped'], 'replayed revocation drops nothing new');
        $this->assertSame('blocked', (string)getDB()->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn());
    }

    public function testRevocationForUnknownHostIsHarmless(): void
    {
        $res = federation_key_events_apply_peer_event('revocation', 'no-such-peer.example.invalid', []);
        $this->assertTrue($res['ok']);
        $this->assertSame(0, $res['updated_rows']);
        $this->assertSame([], $res['dropped']);
    }

    public function testCompromiseSwapsKeyButDoesNotDrop(): void
    {
        $cid = $this->seedMirror('slug-keep');
        $kpNew = sodium_crypto_sign_keypair();
        $newPub = sodium_crypto_sign_publickey($kpNew);

        $res = federation_key_events_apply_peer_event('compromise', $this->host, [
            'new_public_key' => base64_encode($newPub),
        ]);
        $this->assertTrue($res['ok']);
        $this->assertArrayNotHasKey('dropped', $res, 'compromise returns no drop list');

        $pdo = getDB();
        // Mirror + subscription survive: still trusted under the new key.
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = {$this->peerId}")->fetchColumn());
        // Trust state untouched by a compromise rotation.
        $this->assertSame('whitelisted', (string)$pdo->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn());
        // Key actually swapped, previous cleared (zero grace).
        $stored = $pdo->query("SELECT encode(public_key, 'hex') AS public_key, encode(previous_public_key, 'hex') AS previous_public_key FROM peers WHERE id = {$this->peerId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($newPub, hex2bin((string)$stored['public_key']));
        $this->assertNull($stored['previous_public_key']);
    }

    public function testCompromiseRejectsMissingKey(): void
    {
        $res = federation_key_events_apply_peer_event('compromise', $this->host, []);
        $this->assertFalse($res['ok']);
        $this->assertSame('missing_new_public_key', $res['reason']);
    }
}
