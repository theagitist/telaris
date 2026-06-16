<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5f operator surface view query.
 *
 * federation_published_galaxies_admin_view() lists every authored galaxy on
 * this instance with its federation status (published / not_published /
 * retracted / stale). Mirrored galaxies and galaxies with no slug are
 * excluded. Drives the admin/index.php Pluriverse tab "Your published
 * galaxies" section.
 *
 * Spec: Stage 5 galaxy publish design (5f).
 */
final class GalaxyPublishedAdminViewTest extends TestCase
{
    /** @var list<int> */
    private array $cids = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_publish.php';
    }

    protected function setUp(): void
    {
        db_ensure_published_galaxies_table();
        db_ensure_retracted_galaxies_table();
        db_ensure_federation_attribution_columns();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $slug = (string)$pdo->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();
            if ($slug !== '') {
                $pdo->prepare("DELETE FROM published_galaxies WHERE slug = :s")->execute([':s' => $slug]);
                $pdo->prepare("DELETE FROM retracted_galaxies WHERE slug = :s")->execute([':s' => $slug]);
            }
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        $this->cids = [];
    }

    private function mkGalaxy(string $slug, ?string $importSource = null): int
    {
        $pdo = getDB();
        $insC = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme, import_source)
                       VALUES (:n, :s, 'galaxy', 'cosmic', :src) RETURNING id");
        $insC->execute([':n' => 'Test ' . $slug, ':s' => $slug, ':src' => $importSource]);
        $cid = (int)$insC->fetchColumn();
        $this->cids[] = $cid;
        return $cid;
    }

    private function markPublished(int $cid, int $seq = 1, bool $isCurrent = true): void
    {
        $pdo = getDB();
        $slug = (string)$pdo->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();
        $pdo->prepare("INSERT INTO published_galaxies
                (constellation_id, slug, published_sequence, content_hash, envelope_jws, is_current)
                VALUES (:c, :s, :seq, :h, 'aaa.bbb.ccc', :cur)")
            ->execute([':c' => $cid, ':s' => $slug, ':seq' => $seq, ':h' => str_repeat('0', 64), ':cur' => $isCurrent ? 1 : 0]);
    }

    private function markRetracted(int $cid): void
    {
        $slug = (string)getDB()->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();
        getDB()->prepare("INSERT INTO retracted_galaxies (constellation_id, slug, retracted_by, reason)
                          VALUES (:c, :s, 'test', 'integration')")
            ->execute([':c' => $cid, ':s' => $slug]);
        getDB()->prepare("UPDATE published_galaxies SET is_current = FALSE WHERE slug = :s")->execute([':s' => $slug]);
    }

    private function row(array $view, string $slug): ?array
    {
        foreach ($view as $r) if ($r['slug'] === $slug) return $r;
        return null;
    }

    public function testAuthoredPublishedShowsAsPublished(): void
    {
        $sfx = bin2hex(random_bytes(3));
        $cid = $this->mkGalaxy("av-pub-$sfx");
        $this->markPublished($cid, 3);
        $row = $this->row(federation_published_galaxies_admin_view(), "av-pub-$sfx");
        $this->assertNotNull($row);
        $this->assertSame('published', $row['status']);
        $this->assertSame(3, $row['sequence']);
        $this->assertTrue($row['is_current']);
    }

    public function testAuthoredNeverPublishedShowsAsNotPublished(): void
    {
        $sfx = bin2hex(random_bytes(3));
        $cid = $this->mkGalaxy("av-fresh-$sfx");
        $row = $this->row(federation_published_galaxies_admin_view(), "av-fresh-$sfx");
        $this->assertNotNull($row);
        $this->assertSame('not_published', $row['status']);
        $this->assertNull($row['sequence']);
        $this->assertNull($row['published_at']);
    }

    public function testRetractedShowsAsRetracted(): void
    {
        $sfx = bin2hex(random_bytes(3));
        $cid = $this->mkGalaxy("av-ret-$sfx");
        $this->markPublished($cid, 1);
        $this->markRetracted($cid);
        $row = $this->row(federation_published_galaxies_admin_view(), "av-ret-$sfx");
        $this->assertNotNull($row);
        $this->assertSame('retracted', $row['status']);
        $this->assertNotNull($row['retracted_at']);
        $this->assertSame('integration', $row['retracted_reason']);
    }

    public function testMirroredGalaxiesAreExcluded(): void
    {
        $sfx = bin2hex(random_bytes(3));
        $mirror = $this->mkGalaxy("av-mirror-$sfx", json_encode(['origin_host' => 'other.invalid']));
        $this->markPublished($mirror);
        $row = $this->row(federation_published_galaxies_admin_view(), "av-mirror-$sfx");
        $this->assertNull($row, 'mirrored galaxies do not appear in the operator publish surface');
    }

    public function testSluglessGalaxiesAreExcluded(): void
    {
        $pdo = getDB();
        $insC = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme) VALUES ('Slugless', NULL, 'galaxy', 'cosmic') RETURNING id");
        $insC->execute();
        $cid = (int)$insC->fetchColumn();
        $this->cids[] = $cid;
        foreach (federation_published_galaxies_admin_view() as $r) {
            $this->assertNotSame($cid, $r['constellation_id']);
        }
    }
}
