<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5d-ii envelope verify + mirror materialization.
 *
 * Drives federation_pull_apply_envelope end to end with ephemeral keypairs (no
 * instance key, no network): an envelope signed by a synthetic "origin" whose
 * public key is stored on a peer row is verified, materialized into a read-only
 * mirror constellation with provenance, and the subscription bookkeeping is
 * updated. Also covers re-publish (replace in place), replay, and the binding
 * rejections (wrong key, hash, origin, slug).
 *
 * Spec: Stage 5 galaxy publish design (5d-ii).
 */
final class GalaxyMirrorTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';
    private string $sk = '';
    private string $pk = '';
    /** @var list<int> */
    private array $cids = [];
    /** @var list<string> */
    private array $tmpFiles = [];
    /** @var list<string> sha256s registered in media_blobs that this test owns. */
    private array $cleanupBlobs = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_pull.php';
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_mirror.php';
        require_once dirname(__DIR__, 3) . '/inc/federation/media_store.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_federation_attribution_columns();
        db_ensure_media_blobs_table();
        $kp = sodium_crypto_sign_keypair();
        $this->sk = sodium_crypto_sign_secretkey($kp);
        $this->pk = sodium_crypto_sign_publickey($kp);
        $sfx = bin2hex(random_bytes(4));
        $this->host = "origin-$sfx.example.invalid";
        $pdo = getDB();
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, :k, 'mirror test origin') RETURNING id");
        $ins->execute([
                ':h' => $this->host,
                ':u' => "https://{$this->host}",
                ':e' => "https://{$this->host}/api/pluriverse",
                ':k' => $this->pk,
            ]);
        $this->peerId = (int)$ins->fetchColumn();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM keyword_relations kr
                           WHERE kr.keyword_a_id IN (SELECT id FROM keywords WHERE constellation_id = :c1)
                              OR kr.keyword_b_id IN (SELECT id FROM keywords WHERE constellation_id = :c2)")
                ->execute([':c1' => $cid, ':c2' => $cid]);
            $pdo->prepare("DELETE FROM node_keywords nk USING nodes n WHERE n.id = nk.node_id AND n.constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("UPDATE galaxy_subscriptions SET local_constellation_id = NULL WHERE local_constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        foreach ($this->cleanupBlobs as $sha256) {
            $pdo->prepare("DELETE FROM media_blobs WHERE sha256 = :s")->execute([':s' => $sha256]);
            // Best-effort: also remove the on-disk blob if this test process can.
            if (defined('UPLOAD_DIR')) {
                $f = rtrim(UPLOAD_DIR, '/') . '/federation-media/' . $sha256;
                if (is_file($f) && is_writable($f)) @unlink($f);
            }
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        if ($this->host !== '') {
            $pdo->prepare("DELETE FROM seen_nonces WHERE origin_host = :h")->execute([':h' => $this->host]);
        }
        if ($this->peerId > 0) {
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]); // CASCADEs subscriptions
        }
    }

    /**
     * Pre-populate media_blobs with a hash that points at a CLI-writable
     * tempfile, so the resolver short-circuits before the network leg. Returns
     * the temp path so callers can assert on it.
     */
    private function preCacheBlob(string $sha256, string $bytes, string $mime = 'image/jpeg'): string
    {
        $tmp = sys_get_temp_dir() . '/fed-media-test-' . $sha256 . '-' . bin2hex(random_bytes(3));
        file_put_contents($tmp, $bytes);
        $this->tmpFiles[] = $tmp;
        federation_media_record($sha256, $tmp, $mime, strlen($bytes));
        $this->cleanupBlobs[] = $sha256;
        return $tmp;
    }

    private function subscribe(string $slug): void
    {
        getDB()->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, is_active) VALUES (:p, :s, TRUE)")
            ->execute([':p' => $this->peerId, ':s' => $slug]);
    }

    /** @return array{0:string,1:string} [jws, contentHash] */
    private function envelope(string $slug, int $seq, array $nodes, array $relations): array
    {
        $payload = federation_galaxy_envelope_payload(
            ['name' => 'Coastal plants', 'tagline' => 'a mirror', 'theme' => 'cosmic'],
            $nodes,
            $relations,
            $this->host,
            $slug,
            $seq,
            gmdate('c')
        );
        $hash = federation_galaxy_content_hash($payload);
        $jws = federation_jws_sign($payload, FEDERATION_GALAXY_ENVELOPE_TYP, $this->host . ':fp', $this->sk);
        return [$jws, $hash];
    }

    private function sampleNodes(): array
    {
        return [
            [
                'name' => 'Beach Strawberry',
                'description' => 'native ground cover',
                'node_type' => 'object',
                'keywords' => ['edible', 'native'],
                'media' => [
                    ['field' => 'image_url', 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg'],
                    ['field' => 'audio_url', 'src' => 'https://example.com/a.mp3'],
                ],
            ],
            [
                'name' => 'Dune Grass',
                'node_type' => 'object',
                'keywords' => ['native'],
                'media' => [],
            ],
        ];
    }

    public function testMaterializesMirror(): void
    {
        $slug = 'coastal-plants';
        $this->subscribe($slug);
        [$jws, $hash] = $this->envelope($slug, 3, $this->sampleNodes(), [['from' => 'edible', 'to' => 'native', 'note' => 'both apply']]);

        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $cid = $res['constellation_id'];
        $this->cids[] = $cid;
        $this->assertSame(3, $res['sequence']);

        $pdo = getDB();
        $cStmt = $pdo->prepare("SELECT mirrored_from_peer_id, read_only, import_source FROM constellations WHERE id = :id");
        $cStmt->execute([':id' => $cid]);
        $c = $cStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($this->peerId, (int)$c['mirrored_from_peer_id']);
        $this->assertSame(1, (int)$c['read_only']);
        $imp = json_decode((string)$c['import_source'], true);
        $this->assertSame('federation', $imp['kind']);
        $this->assertSame($this->host, $imp['origin_host']);
        $this->assertSame($hash, $imp['content_hash']);

        // Nodes + media mapping.
        $nodes = $pdo->prepare("SELECT name, image_url, audio_url FROM nodes WHERE constellation_id = :c ORDER BY name");
        $nodes->execute([':c' => $cid]);
        $rows = $nodes->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $strawberry = $rows[0];
        $this->assertSame('https://example.com/a.mp3', $strawberry['audio_url'], 'external media kept as src');
        $this->assertNull($strawberry['image_url'], 'content-addressed media is null until 5d-iii resolves it');

        // Keywords + relation.
        $kw = (int)$pdo->query("SELECT COUNT(*) FROM keywords WHERE constellation_id = $cid")->fetchColumn();
        $this->assertSame(2, $kw, 'edible + native');
        $rel = (int)$pdo->query("SELECT COUNT(*) FROM keyword_relations kr JOIN keywords k ON k.id = kr.keyword_a_id WHERE k.constellation_id = $cid")->fetchColumn();
        $this->assertSame(1, $rel);

        // Subscription bookkeeping.
        $sub = $pdo->prepare("SELECT local_constellation_id, last_received_sequence, last_content_hash FROM galaxy_subscriptions WHERE peer_id = :p AND remote_slug = :s");
        $sub->execute([':p' => $this->peerId, ':s' => $slug]);
        $subRow = $sub->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($cid, (int)$subRow['local_constellation_id']);
        $this->assertSame(3, (int)$subRow['last_received_sequence']);
        $this->assertSame($hash, (string)$subRow['last_content_hash']);
    }

    public function testRepublishReplacesInPlace(): void
    {
        $slug = 'coastal-plants';
        $this->subscribe($slug);
        [$jws1, $hash1] = $this->envelope($slug, 3, $this->sampleNodes(), []);
        $r1 = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws1, $hash1, 0);
        $cid = $r1['constellation_id'];
        $this->cids[] = $cid;

        // Re-publish at a higher sequence with one node.
        [$jws2, $hash2] = $this->envelope($slug, 7, [['name' => 'Only Node', 'node_type' => 'object', 'keywords' => [], 'media' => []]], []);
        $r2 = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws2, $hash2, 3);
        $this->assertTrue($r2['ok'], $r2['error'] ?? '');
        $this->assertSame($cid, $r2['constellation_id'], 'same local constellation reused');

        $count = (int)getDB()->query("SELECT COUNT(*) FROM nodes WHERE constellation_id = $cid")->fetchColumn();
        $this->assertSame(1, $count, 'old nodes were cleared');
    }

    public function testRejectsReplay(): void
    {
        $slug = 'coastal-plants';
        $this->subscribe($slug);
        [$jws, $hash] = $this->envelope($slug, 3, $this->sampleNodes(), []);
        $r1 = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0);
        $this->cids[] = $r1['constellation_id'];
        // Same envelope again with the same last-seen sequence: nonce store rejects it.
        $r2 = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0);
        $this->assertFalse($r2['ok']);
        $this->assertSame('envelope_replay', $r2['error']);
    }

    public function testRejectsWrongKey(): void
    {
        $slug = 'coastal-plants';
        $this->subscribe($slug);
        $kp2 = sodium_crypto_sign_keypair();
        $payload = federation_galaxy_envelope_payload(['name' => 'x', 'tagline' => '', 'theme' => 'cosmic'], [], [], $this->host, $slug, 3, gmdate('c'));
        $hash = federation_galaxy_content_hash($payload);
        $jws = federation_jws_sign($payload, FEDERATION_GALAXY_ENVELOPE_TYP, $this->host . ':fp', sodium_crypto_sign_secretkey($kp2));
        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0);
        $this->assertFalse($res['ok']);
        $this->assertSame('signature_invalid', $res['error']);
    }

    public function testRejectsContentHashMismatch(): void
    {
        $slug = 'coastal-plants';
        $this->subscribe($slug);
        [$jws] = $this->envelope($slug, 3, $this->sampleNodes(), []);
        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, str_repeat('0', 64), 0);
        $this->assertFalse($res['ok']);
        $this->assertSame('content_hash_mismatch', $res['error']);
    }

    public function testRejectsOriginAndSlugMismatch(): void
    {
        $slug = 'coastal-plants';
        $this->subscribe($slug);
        [$jws, $hash] = $this->envelope($slug, 3, $this->sampleNodes(), []);
        $this->assertSame('origin_host_mismatch', federation_pull_apply_envelope($this->peerId, 'someone-else.invalid', $slug, $jws, $hash, 0)['error']);
        $this->assertSame('slug_mismatch', federation_pull_apply_envelope($this->peerId, $this->host, 'different-slug', $jws, $hash, 0)['error']);
    }

    /* ---- 5d-iii: media resolver ----------------------------------------- */

    public function testFetcherResolvesFreshBlob(): void
    {
        // Full fetch + re-hash + store + URL synthesis. Requires write access
        // to federation-media (owned by www-data); skip on the CLI test runner
        // when it isn't a group member.
        $dir = federation_media_dir();
        if (!is_writable($dir)) {
            $this->markTestSkipped('federation-media dir not writable; live happy path covered via sudo -u www-data');
        }
        $bytes = 'test image bytes ' . bin2hex(random_bytes(8));
        $sha256 = hash('sha256', $bytes);
        $this->cleanupBlobs[] = $sha256;

        $called = 0;
        $fetcher = function (string $h) use ($sha256, $bytes, &$called): array {
            $this->assertSame($sha256, $h);
            $called++;
            return ['ok' => true, 'bytes' => $bytes, 'mime' => 'image/jpeg'];
        };

        $slug = 'with-media';
        $this->subscribe($slug);
        $nodes = [[
            'name' => 'n1',
            'node_type' => 'object',
            'keywords' => [],
            'media' => [['field' => 'image_url', 'sha256' => $sha256, 'mime' => 'image/jpeg']],
        ]];
        [$jws, $hash] = $this->envelope($slug, 1, $nodes, []);

        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0, $fetcher);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->cids[] = $res['constellation_id'];
        $this->assertSame(1, $called, 'fetcher called once');

        // Node points at the canonical web URL the visitor surface will read.
        $url = (string)getDB()->query("SELECT image_url FROM nodes WHERE constellation_id = " . $res['constellation_id'])->fetchColumn();
        $this->assertSame('uploads/federation-media/' . $sha256, $url);

        // Blob landed on disk and re-hashes to the claimed sha256.
        $dest = $dir . '/' . $sha256;
        $this->assertFileExists($dest);
        $this->assertSame($sha256, hash_file('sha256', $dest));

        // media_blobs row recorded.
        $blob = federation_media_lookup($sha256);
        $this->assertNotNull($blob);
        $this->assertSame($sha256, $blob['sha256']);
        $this->assertSame('image/jpeg', $blob['mime']);
        $this->assertSame(strlen($bytes), $blob['size_bytes']);
    }

    public function testFetcherHashMismatchFailsClosed(): void
    {
        $claimed = str_repeat('b', 64);   // a node's envelope references this
        $served  = 'these bytes do not hash to the claimed sha256';
        $fetcher = function (string $h) use ($served): array {
            return ['ok' => true, 'bytes' => $served, 'mime' => 'image/jpeg'];
        };
        $slug = 'bad-media';
        $this->subscribe($slug);
        $nodes = [[
            'name' => 'n1',
            'node_type' => 'object',
            'keywords' => [],
            'media' => [['field' => 'image_url', 'sha256' => $claimed]],
        ]];
        [$jws, $hash] = $this->envelope($slug, 1, $nodes, []);

        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0, $fetcher);
        $this->assertFalse($res['ok']);
        $this->assertSame('media_hash_mismatch', $res['error']);
        $this->assertSame($claimed, $res['sha256'] ?? '');

        // No constellation created and the subscription bookkeeping is untouched.
        $sub = getDB()->prepare("SELECT local_constellation_id, last_received_sequence FROM galaxy_subscriptions WHERE peer_id = :p AND remote_slug = :s");
        $sub->execute([':p' => $this->peerId, ':s' => $slug]);
        $row = $sub->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['local_constellation_id']);
        $this->assertNull($row['last_received_sequence']);
    }

    public function testFetcherErrorFailsClosed(): void
    {
        $sha256 = hash('sha256', 'unreachable');
        $fetcher = fn (string $h): array => ['ok' => false, 'error' => 'http_503'];
        $slug = 'unavailable-media';
        $this->subscribe($slug);
        $nodes = [[
            'name' => 'n1',
            'node_type' => 'object',
            'keywords' => [],
            'media' => [['field' => 'image_url', 'sha256' => $sha256]],
        ]];
        [$jws, $hash] = $this->envelope($slug, 1, $nodes, []);

        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0, $fetcher);
        $this->assertFalse($res['ok']);
        $this->assertSame('media_unavailable', $res['error']);
        $this->assertSame($sha256, $res['sha256'] ?? '');

        // The replay nonce must have been released so a retry can proceed.
        $nonceCount = (int)getDB()->query("SELECT COUNT(*) FROM seen_nonces WHERE origin_host = '{$this->host}'")->fetchColumn();
        $this->assertSame(0, $nonceCount, 'nonce released for retry');
    }

    public function testCachedBlobSkipsFetcher(): void
    {
        $bytes = 'already cached ' . bin2hex(random_bytes(6));
        $sha256 = hash('sha256', $bytes);
        $this->preCacheBlob($sha256, $bytes);

        $called = 0;
        $fetcher = function (string $h) use (&$called): array {
            $called++;
            return ['ok' => false, 'error' => 'should_not_be_called'];
        };

        $slug = 'cached-media';
        $this->subscribe($slug);
        $nodes = [
            ['name' => 'a', 'node_type' => 'object', 'keywords' => [], 'media' => [['field' => 'image_url', 'sha256' => $sha256]]],
            ['name' => 'b', 'node_type' => 'object', 'keywords' => [], 'media' => [['field' => 'image_url', 'sha256' => $sha256]]],
        ];
        [$jws, $hash] = $this->envelope($slug, 1, $nodes, []);

        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0, $fetcher);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->cids[] = $res['constellation_id'];
        $this->assertSame(0, $called, 'cached blob: fetcher never called');

        // Both nodes point at the canonical URL.
        $rows = getDB()->query("SELECT image_url FROM nodes WHERE constellation_id = " . $res['constellation_id'])->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $rows);
        foreach ($rows as $u) $this->assertSame('uploads/federation-media/' . $sha256, $u);
    }

    public function testFetcherIsDedupedAcrossNodes(): void
    {
        if (!is_writable(federation_media_dir())) {
            $this->markTestSkipped('federation-media dir not writable to CLI user');
        }
        $bytes = 'dedup bytes ' . bin2hex(random_bytes(8));
        $sha256 = hash('sha256', $bytes);
        $this->cleanupBlobs[] = $sha256;

        $called = 0;
        $fetcher = function (string $h) use ($sha256, $bytes, &$called): array {
            $called++;
            return ['ok' => true, 'bytes' => $bytes, 'mime' => 'image/jpeg'];
        };

        $slug = 'dedup-media';
        $this->subscribe($slug);
        $nodes = [
            ['name' => 'a', 'node_type' => 'object', 'keywords' => [], 'media' => [['field' => 'image_url', 'sha256' => $sha256]]],
            ['name' => 'b', 'node_type' => 'object', 'keywords' => [], 'media' => [['field' => 'image_url', 'sha256' => $sha256]]],
            ['name' => 'c', 'node_type' => 'object', 'keywords' => [], 'media' => [['field' => 'icon_url', 'sha256' => $sha256]]],
        ];
        [$jws, $hash] = $this->envelope($slug, 1, $nodes, []);

        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0, $fetcher);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->cids[] = $res['constellation_id'];
        $this->assertSame(1, $called, 'one fetch for three referencing nodes (two fields)');
    }

    public function testNullFetcherStillSoftSkipsUncachedBlobs(): void
    {
        // Preserves the 5d-ii posture: passing no fetcher leaves uncached blobs
        // unresolved (NULL fields), instead of failing the whole pull. Cron
        // callers wire federation_mirror_default_fetcher() to get fail-closed
        // semantics; this case keeps test stubs and fetch-disabled paths green.
        $slug = 'soft-skip';
        $this->subscribe($slug);
        $nodes = [[
            'name' => 'n1',
            'node_type' => 'object',
            'keywords' => [],
            'media' => [
                ['field' => 'image_url', 'sha256' => str_repeat('e', 64), 'mime' => 'image/jpeg'],
                ['field' => 'audio_url', 'src' => 'https://example.com/a.mp3'],
            ],
        ]];
        [$jws, $hash] = $this->envelope($slug, 1, $nodes, []);

        $res = federation_pull_apply_envelope($this->peerId, $this->host, $slug, $jws, $hash, 0, null);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->cids[] = $res['constellation_id'];
        $row = getDB()->query("SELECT image_url, audio_url FROM nodes WHERE constellation_id = " . $res['constellation_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['image_url'], 'uncached, no fetcher: NULL');
        $this->assertSame('https://example.com/a.mp3', $row['audio_url'], 'external src passes through');
    }
}
