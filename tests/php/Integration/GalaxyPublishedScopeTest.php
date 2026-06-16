<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5c-ii published.json peer scoping.
 *
 * federation_published_for_peer() must return exactly the galaxies this
 * instance offers a given peer: current published rows for authored galaxies
 * that sit in the peer's publish whitelist. It must exclude:
 *   - galaxies not in the peer's publish whitelist
 *   - mirrored galaxies (import_source set), even if whitelisted
 *
 * Rows are inserted directly (no signing) since the scoping query does not
 * depend on envelope validity. The signed publish path is covered by
 * GalaxyPublishTest + GalaxyEnvelopeTest.
 *
 * Spec: Stage 5 galaxy publish design (5c).
 */
final class GalaxyPublishedScopeTest extends TestCase
{
    private int $peerId = 0;
    private int $authoredA = 0;
    private int $authoredB = 0;
    private int $mirrored = 0;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_publish.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_published_galaxies_table();
        db_ensure_galaxy_publish_whitelist_table();
        $pdo = getDB();
        $sfx = bin2hex(random_bytes(4));

        $insPeer = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, decode(:k, 'hex'), :l) RETURNING id");
        $insPeer->execute([
                ':h' => "scope-peer-$sfx.example.invalid",
                ':u' => "https://scope-peer-$sfx.example.invalid",
                ':e' => "https://scope-peer-$sfx.example.invalid/api/pluriverse",
                ':k' => bin2hex(random_bytes(32)),
                ':l' => 'scope test peer',
            ]);
        $this->peerId = (int)$insPeer->fetchColumn();

        $this->authoredA = $this->mkGalaxy("scope-authored-a-$sfx", null);
        $this->authoredB = $this->mkGalaxy("scope-authored-b-$sfx", null);
        $this->mirrored  = $this->mkGalaxy("scope-mirror-$sfx", json_encode(['peer' => 'x']));

        // Publish all three (direct insert, dummy envelope).
        foreach ([$this->authoredA, $this->authoredB, $this->mirrored] as $cid) {
            $this->publishRow($cid);
        }
        // Whitelist A and the mirror for the peer; NOT B.
        foreach ([$this->authoredA, $this->mirrored] as $cid) {
            $pdo->prepare("INSERT INTO galaxy_publish_whitelist (peer_id, constellation_id) VALUES (:p, :c)")
                ->execute([':p' => $this->peerId, ':c' => $cid]);
        }
    }

    private function mkGalaxy(string $slug, ?string $importSource): int
    {
        $pdo = getDB();
        $insC = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme, import_source)
                       VALUES (:n, :s, 'galaxy', 'cosmic', :src) RETURNING id");
        $insC->execute([':n' => $slug, ':s' => $slug, ':src' => $importSource]);
        return (int)$insC->fetchColumn();
    }

    private function publishRow(int $cid): void
    {
        $pdo = getDB();
        $slug = (string)$pdo->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();
        $pdo->prepare("INSERT INTO published_galaxies
                (constellation_id, slug, published_sequence, content_hash, envelope_jws, is_current)
                VALUES (:c, :s, 1, :h, 'aaa.bbb.ccc', TRUE)")
            ->execute([':c' => $cid, ':s' => $slug, ':h' => str_repeat('0', 64)]);
    }

    private function slugFor(int $cid): string
    {
        return (string)getDB()->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ([$this->authoredA, $this->authoredB, $this->mirrored] as $cid) {
            if ($cid > 0) {
                $pdo->prepare("DELETE FROM published_galaxies WHERE constellation_id = :c")->execute([':c' => $cid]);
                $pdo->prepare("DELETE FROM galaxy_publish_whitelist WHERE constellation_id = :c")->execute([':c' => $cid]);
                $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
            }
        }
        if ($this->peerId > 0) {
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]); // CASCADEs whitelist
        }
    }

    public function testScopeReturnsOnlyWhitelistedAuthoredGalaxies(): void
    {
        $list = federation_published_for_peer($this->peerId);
        $slugs = array_column($list, 'slug');

        $authoredASlug = (string)getDB()->query("SELECT slug FROM constellations WHERE id = {$this->authoredA}")->fetchColumn();
        $authoredBSlug = (string)getDB()->query("SELECT slug FROM constellations WHERE id = {$this->authoredB}")->fetchColumn();
        $mirrorSlug    = (string)getDB()->query("SELECT slug FROM constellations WHERE id = {$this->mirrored}")->fetchColumn();

        $this->assertContains($authoredASlug, $slugs, 'whitelisted authored galaxy is offered');
        $this->assertNotContains($authoredBSlug, $slugs, 'non-whitelisted galaxy is withheld');
        $this->assertNotContains($mirrorSlug, $slugs, 'mirrored galaxy is never offered, even if whitelisted');

        // Shape check on the offered row.
        $row = $list[array_search($authoredASlug, $slugs, true)];
        $this->assertSame(1, $row['published_sequence']);
        $this->assertSame(str_repeat('0', 64), $row['content_hash']);
    }

    public function testEnvelopeForSlugReturnsWhitelistedAuthored(): void
    {
        $row = federation_published_envelope_for_peer($this->peerId, $this->slugFor($this->authoredA));
        $this->assertNotNull($row, 'whitelisted authored galaxy resolves for the peer');
        $this->assertSame('aaa.bbb.ccc', $row['envelope_jws']);
        $this->assertSame(str_repeat('0', 64), $row['content_hash']);
        $this->assertSame(1, $row['published_sequence']);
    }

    public function testEnvelopeForSlugWithholdsNonWhitelisted(): void
    {
        $this->assertNull(
            federation_published_envelope_for_peer($this->peerId, $this->slugFor($this->authoredB)),
            'non-whitelisted galaxy is withheld (uniform null -> 404)'
        );
    }

    public function testEnvelopeForSlugWithholdsMirror(): void
    {
        $this->assertNull(
            federation_published_envelope_for_peer($this->peerId, $this->slugFor($this->mirrored)),
            'mirrored galaxy is never offered, even if whitelisted'
        );
    }

    public function testEnvelopeForUnknownSlugIsNull(): void
    {
        $this->assertNull(
            federation_published_envelope_for_peer($this->peerId, 'no-such-slug-' . bin2hex(random_bytes(3)))
        );
    }

    public function testEmptyForPeerWithNoWhitelist(): void
    {
        $pdo = getDB();
        $sfx = bin2hex(random_bytes(4));
        $insEmpty = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, decode(:k, 'hex'), 'empty peer') RETURNING id");
        $insEmpty->execute([
                ':h' => "scope-empty-$sfx.example.invalid",
                ':u' => "https://scope-empty-$sfx.example.invalid",
                ':e' => "https://scope-empty-$sfx.example.invalid/api/pluriverse",
                ':k' => bin2hex(random_bytes(32)),
            ]);
        $emptyPeer = (int)$insEmpty->fetchColumn();
        try {
            $this->assertSame([], federation_published_for_peer($emptyPeer));
        } finally {
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $emptyPeer]);
        }
    }
}
