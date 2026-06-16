<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5c-i galaxy publish library.
 *
 * Exercises against the live DB:
 *   - federation_galaxy_collect_content assembles a galaxy's metadata, nodes
 *     (with keywords + media refs) and keyword relations
 *   - publish guard: unknown galaxy is rejected
 *   - publish guard: a mirrored galaxy (import_source set) cannot be published
 *   - publish guard: a retracted slug cannot be published
 *
 * The guard paths return before the envelope is signed, so this test does not
 * need the live instance key. The sign/verify happy path is covered by
 * GalaxyEnvelopeTest (5b) with synthetic keys.
 *
 * Spec: Stage 5 galaxy publish design (5c).
 */
final class GalaxyPublishTest extends TestCase
{
    private const DEMO_GALAXY_ID = 413; // [manual-demo] Coastal plants
    private int $mirrorId = 0;
    private int $retractedId = 0;
    private string $retractedSlug = '';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_publish.php';
    }

    protected function setUp(): void
    {
        $pdo = getDB();
        // A mirrored galaxy (import_source set) — must be refused for publish.
        $insMirror = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme, import_source) VALUES (:n, :s, 'galaxy', 'cosmic', :src) RETURNING id");
        $insMirror->execute([
                ':n' => 'fed publish test mirror',
                ':s' => 'fed-pub-test-mirror-' . bin2hex(random_bytes(4)),
                ':src' => json_encode(['peer' => 'origin.example.invalid', 'slug' => 'x']),
            ]);
        $this->mirrorId = (int)$insMirror->fetchColumn();

        // An authored galaxy whose slug is retracted — must be refused.
        $this->retractedSlug = 'fed-pub-test-retracted-' . bin2hex(random_bytes(4));
        $insRetracted = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme) VALUES (:n, :s, 'galaxy', 'cosmic') RETURNING id");
        $insRetracted->execute([':n' => 'fed publish test retracted', ':s' => $this->retractedSlug]);
        $this->retractedId = (int)$insRetracted->fetchColumn();

        db_ensure_retracted_galaxies_table();
        $pdo->prepare("INSERT INTO retracted_galaxies (constellation_id, slug, reason) VALUES (:cid, :s, 'test')")
            ->execute([':cid' => $this->retractedId, ':s' => $this->retractedSlug]);
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        $pdo->prepare("DELETE FROM retracted_galaxies WHERE slug = :s")->execute([':s' => $this->retractedSlug]);
        foreach ([$this->mirrorId, $this->retractedId] as $id) {
            if ($id > 0) {
                $pdo->prepare("DELETE FROM published_galaxies WHERE constellation_id = :id")->execute([':id' => $id]);
                $pdo->prepare("DELETE FROM constellations WHERE id = :id")->execute([':id' => $id]);
            }
        }
    }

    public function testCollectContentAssemblesDemoGalaxy(): void
    {
        $content = federation_galaxy_collect_content(self::DEMO_GALAXY_ID);
        $this->assertNotNull($content, 'demo galaxy 413 must exist for this test');
        $this->assertSame('manual-demo-coastal-plants', $content['slug']);
        $this->assertNull($content['import_source'], 'demo galaxy is authored, not mirrored');
        $this->assertNotEmpty($content['galaxy']['name']);
        $this->assertNotEmpty($content['nodes'], 'galaxy should have wormholes');

        // At least one node should carry keywords; structure must be well-formed.
        $node = $content['nodes'][0];
        $this->assertArrayHasKey('name', $node);
        $this->assertArrayHasKey('keywords', $node);
        $this->assertArrayHasKey('media', $node);
        $this->assertIsList($node['keywords']);
    }

    public function testPublishRejectsUnknownGalaxy(): void
    {
        $res = federation_galaxy_publish(999999999);
        $this->assertFalse($res['ok']);
        $this->assertSame('galaxy_not_found', $res['error']);
    }

    public function testPublishRejectsMirroredGalaxy(): void
    {
        $res = federation_galaxy_publish($this->mirrorId);
        $this->assertFalse($res['ok']);
        $this->assertSame('galaxy_is_mirrored', $res['error']);
    }

    public function testPublishRejectsRetractedSlug(): void
    {
        $res = federation_galaxy_publish($this->retractedId);
        $this->assertFalse($res['ok']);
        $this->assertSame('slug_retracted', $res['error']);
    }
}
