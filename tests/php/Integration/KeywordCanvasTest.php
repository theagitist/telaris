<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the keyword-canvas schema and Poisson-disc seeding.
 *
 * Covers Chunk A of the keyword-canvas feature (see the design note
 * `Academia/Projects/Telaris/Features/Keyword canvas/design.md`):
 *  - db_ensure_keyword_canvas_tables() creates all three tables idempotently
 *  - db_seed_keyword_positions_for_galaxy() places only missing keywords
 *  - moved_by stays NULL on seeded rows (neutral default, not an authored claim)
 *  - Seeded coordinates are within the canvas bounds
 *  - keyword_relations CHECK constraint (a < b) rejects non-canonical pairs
 *  - keyword_relations UNIQUE on (a, b) rejects duplicate pairs
 *  - Cascade-on-keyword-delete cleans up positions, relations, and history rows
 *  - keyword_position_history is append-only by structural design
 */
final class KeywordCanvasTest extends TestCase
{
    private PDO $pdo;
    private int $galaxyId = 0;
    private int $otherGalaxyId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
        db_ensure_keyword_canvas_tables();
        $this->galaxyId = db_create_constellation(
            'Aitest Canvas Galaxy', '',
            'aitest-canvas-' . bin2hex(random_bytes(4)), 'cosmic'
        );
        $this->otherGalaxyId = db_create_constellation(
            'Aitest Canvas Other', '',
            'aitest-canvas-other-' . bin2hex(random_bytes(4)), 'cosmic'
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testEnsureTablesIsIdempotent(): void
    {
        db_ensure_keyword_canvas_tables();
        db_ensure_keyword_canvas_tables();
        // No exceptions thrown; tables either exist or were created.
        $names = ['keyword_positions', 'keyword_relations', 'keyword_position_history'];
        foreach ($names as $name) {
            $exists = $this->pdo->query("SHOW TABLES LIKE '$name'")->fetch();
            $this->assertNotFalse($exists, "Table $name should exist after ensure");
        }
    }

    public function testSeedPlacesMissingKeywordsOnly(): void
    {
        $kw1 = db_create_keyword('aitest-seed-one', $this->galaxyId);
        $kw2 = db_create_keyword('aitest-seed-two', $this->galaxyId);
        $kw3 = db_create_keyword('aitest-seed-three', $this->galaxyId);

        // Pre-place kw1 with editor-authored coordinates
        $this->pdo->prepare("INSERT INTO keyword_positions (keyword_id, canvas_x, canvas_y, moved_by, moved_at) VALUES (?, 100, 200, NULL, NOW())")
            ->execute([$kw1]);

        $seeded = db_seed_keyword_positions_for_galaxy($this->galaxyId);
        $this->assertSame(2, $seeded, 'Seeder should place only kw2 and kw3');

        // kw1's existing coords are untouched
        $stmt = $this->pdo->prepare("SELECT canvas_x, canvas_y FROM keyword_positions WHERE keyword_id = ?");
        $stmt->execute([$kw1]);
        $row = $stmt->fetch();
        $this->assertEqualsWithDelta(100.0, (float)$row['canvas_x'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float)$row['canvas_y'], 0.001);
    }

    public function testSeedLeavesMovedByNullOnSeededRows(): void
    {
        db_create_keyword('aitest-seed-null', $this->galaxyId);
        db_seed_keyword_positions_for_galaxy($this->galaxyId);

        $stmt = $this->pdo->prepare("
            SELECT p.moved_by, p.moved_at FROM keyword_positions p
            INNER JOIN keywords k ON k.id = p.keyword_id
            WHERE k.constellation_id = ?
        ");
        $stmt->execute([$this->galaxyId]);
        foreach ($stmt->fetchAll() as $row) {
            $this->assertNull($row['moved_by'], 'Seeded row should have moved_by = NULL');
            $this->assertNull($row['moved_at'], 'Seeded row should have moved_at = NULL');
        }
    }

    public function testSeedCoordinatesAreWithinCanvasBounds(): void
    {
        // Seed a handful so we exercise the spacing constraint.
        for ($i = 0; $i < 8; $i++) {
            db_create_keyword('aitest-bounds-' . $i, $this->galaxyId);
        }
        db_seed_keyword_positions_for_galaxy($this->galaxyId);

        $stmt = $this->pdo->prepare("
            SELECT p.canvas_x, p.canvas_y FROM keyword_positions p
            INNER JOIN keywords k ON k.id = p.keyword_id
            WHERE k.constellation_id = ?
        ");
        $stmt->execute([$this->galaxyId]);
        foreach ($stmt->fetchAll() as $row) {
            $this->assertGreaterThanOrEqual(0.0, (float)$row['canvas_x']);
            $this->assertLessThanOrEqual(KEYWORD_CANVAS_WIDTH, (float)$row['canvas_x']);
            $this->assertGreaterThanOrEqual(0.0, (float)$row['canvas_y']);
            $this->assertLessThanOrEqual(KEYWORD_CANVAS_HEIGHT, (float)$row['canvas_y']);
        }
    }

    public function testSeedIsNoOpWhenNothingMissing(): void
    {
        $kw = db_create_keyword('aitest-noop', $this->galaxyId);
        db_seed_keyword_positions_for_galaxy($this->galaxyId);
        $this->assertSame(0, db_seed_keyword_positions_for_galaxy($this->galaxyId));
    }

    public function testRelationCanonicalOrderingIsEnforced(): void
    {
        $kw1 = db_create_keyword('aitest-rel-a', $this->galaxyId);
        $kw2 = db_create_keyword('aitest-rel-b', $this->galaxyId);
        [$lo, $hi] = $kw1 < $kw2 ? [$kw1, $kw2] : [$kw2, $kw1];

        $this->expectException(PDOException::class);
        // Try to insert the pair in reverse order — CHECK constraint must reject.
        $this->pdo->prepare("INSERT INTO keyword_relations (keyword_a_id, keyword_b_id, created_by) VALUES (?, ?, NULL)")
            ->execute([$hi, $lo]);
    }

    public function testRelationPairUniquenessIsEnforced(): void
    {
        $kw1 = db_create_keyword('aitest-rel-uniq-a', $this->galaxyId);
        $kw2 = db_create_keyword('aitest-rel-uniq-b', $this->galaxyId);
        [$lo, $hi] = $kw1 < $kw2 ? [$kw1, $kw2] : [$kw2, $kw1];

        $this->pdo->prepare("INSERT INTO keyword_relations (keyword_a_id, keyword_b_id, created_by) VALUES (?, ?, NULL)")
            ->execute([$lo, $hi]);

        $this->expectException(PDOException::class);
        $this->pdo->prepare("INSERT INTO keyword_relations (keyword_a_id, keyword_b_id, created_by) VALUES (?, ?, NULL)")
            ->execute([$lo, $hi]);
    }

    public function testKeywordDeleteCascadesToCanvasTables(): void
    {
        $kw1 = db_create_keyword('aitest-cascade-a', $this->galaxyId);
        $kw2 = db_create_keyword('aitest-cascade-b', $this->galaxyId);
        [$lo, $hi] = $kw1 < $kw2 ? [$kw1, $kw2] : [$kw2, $kw1];

        db_seed_keyword_positions_for_galaxy($this->galaxyId);
        $this->pdo->prepare("INSERT INTO keyword_relations (keyword_a_id, keyword_b_id, created_by) VALUES (?, ?, NULL)")
            ->execute([$lo, $hi]);
        $this->pdo->prepare("INSERT INTO keyword_position_history (keyword_id, canvas_x, canvas_y) VALUES (?, 50, 50)")
            ->execute([$kw1]);

        // Sanity: rows exist before delete
        $this->assertEquals(1, (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_positions WHERE keyword_id = $kw1")->fetchColumn());
        $this->assertEquals(1, (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_relations WHERE keyword_a_id = $lo AND keyword_b_id = $hi")->fetchColumn());
        $this->assertEquals(1, (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_position_history WHERE keyword_id = $kw1")->fetchColumn());

        db_delete_keyword($kw1);

        // After delete: all three cascade
        $this->assertEquals(0, (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_positions WHERE keyword_id = $kw1")->fetchColumn());
        $this->assertEquals(0, (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_relations WHERE keyword_a_id = $lo AND keyword_b_id = $hi")->fetchColumn());
        $this->assertEquals(0, (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_position_history WHERE keyword_id = $kw1")->fetchColumn());
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-canvas-%'");
        if ($stmt === false) return;
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            // Manually unwind the cascade — db_delete_galaxy_deep is heavier than we need
            $this->pdo->prepare("DELETE FROM keywords WHERE constellation_id = ?")->execute([(int)$id]);
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$id]);
        }
    }
}
