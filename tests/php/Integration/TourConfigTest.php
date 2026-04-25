<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the per-galaxy auto-tour config persistence layer.
 *
 * Covers:
 *  - db_get/set_constellation_tour_config round-trip
 *  - enum validation (invalid values fall back to defaults)
 *  - numeric clamping (1 minimum)
 *  - db_set_tour_keyword_ids ownership filtering (keywords from another galaxy are dropped)
 *  - db_constellation_has_audio_nodes detection
 */
final class TourConfigTest extends TestCase
{
    private PDO $pdo;
    private int $galaxyId = 0;
    private int $otherGalaxyId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
        $this->galaxyId = db_create_constellation('Aitest Tour Galaxy', '', 'aitest-tour-' . bin2hex(random_bytes(4)), 'cosmic');
        $this->otherGalaxyId = db_create_constellation('Aitest Tour Other', '', 'aitest-tour-other-' . bin2hex(random_bytes(4)), 'cosmic');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testRoundTripPreservesAllFields(): void
    {
        db_set_constellation_tour_config($this->galaxyId, [
            'tour_enabled' => true,
            'tour_start_mode' => 'idle',
            'tour_idle_seconds' => 45,
            'tour_node_selection' => 'random_n',
            'tour_random_count' => 7,
            'tour_default_dwell' => 12,
            'tour_loop' => false,
        ]);

        $cfg = db_get_constellation_tour_config($this->galaxyId);
        $this->assertNotNull($cfg);
        $this->assertTrue($cfg['tour_enabled']);
        $this->assertSame('idle', $cfg['tour_start_mode']);
        $this->assertSame(45, $cfg['tour_idle_seconds']);
        $this->assertSame('random_n', $cfg['tour_node_selection']);
        $this->assertSame(7, $cfg['tour_random_count']);
        $this->assertSame(12, $cfg['tour_default_dwell']);
        $this->assertFalse($cfg['tour_loop']);
        $this->assertSame([], $cfg['tour_keyword_ids']);
    }

    public function testInvalidEnumsFallBackToDefaults(): void
    {
        db_set_constellation_tour_config($this->galaxyId, [
            'tour_enabled' => true,
            'tour_start_mode' => 'bogus',
            'tour_node_selection' => 'nonsense',
        ]);

        $cfg = db_get_constellation_tour_config($this->galaxyId);
        $this->assertSame('manual', $cfg['tour_start_mode']);
        $this->assertSame('all', $cfg['tour_node_selection']);
    }

    public function testNumericFieldsAreClampedToMinimumOne(): void
    {
        db_set_constellation_tour_config($this->galaxyId, [
            'tour_enabled' => true,
            'tour_idle_seconds' => 0,
            'tour_random_count' => -3,
            'tour_default_dwell' => 0,
        ]);

        $cfg = db_get_constellation_tour_config($this->galaxyId);
        $this->assertSame(1, $cfg['tour_idle_seconds']);
        $this->assertSame(1, $cfg['tour_random_count']);
        $this->assertSame(1, $cfg['tour_default_dwell']);
    }

    public function testTourKeywordIdsAreFilteredByOwnership(): void
    {
        $myKw = db_create_keyword('aitest-mine', $this->galaxyId);
        $foreignKw = db_create_keyword('aitest-foreign', $this->otherGalaxyId);

        db_set_tour_keyword_ids($this->galaxyId, [$myKw, $foreignKw, 999999999]);

        $stored = db_get_tour_keyword_ids($this->galaxyId);
        $this->assertSame([$myKw], $stored, 'Only keywords belonging to the same galaxy should persist');
    }

    public function testTourKeywordIdsCanBeReplacedAtomically(): void
    {
        $kw1 = db_create_keyword('aitest-one', $this->galaxyId);
        $kw2 = db_create_keyword('aitest-two', $this->galaxyId);
        $kw3 = db_create_keyword('aitest-three', $this->galaxyId);

        db_set_tour_keyword_ids($this->galaxyId, [$kw1, $kw2]);
        $this->assertSame([$kw1, $kw2], db_get_tour_keyword_ids($this->galaxyId));

        db_set_tour_keyword_ids($this->galaxyId, [$kw3]);
        $this->assertSame([$kw3], db_get_tour_keyword_ids($this->galaxyId));

        db_set_tour_keyword_ids($this->galaxyId, []);
        $this->assertSame([], db_get_tour_keyword_ids($this->galaxyId));
    }

    public function testHasAudioNodesDetection(): void
    {
        $animation = json_encode(['radius' => 5.0, 'theta' => 0.0, 'phi' => 0.0, 'speed' => 0.0, 'phase' => 0.0]);
        db_create_node('Aitest Silent', 'no media', null, $animation, $this->galaxyId);
        $this->assertFalse(db_constellation_has_audio_nodes($this->galaxyId));

        $audioNodeId = db_create_node('Aitest Loud', 'has audio', null, $animation, $this->galaxyId);
        $this->pdo->prepare("UPDATE nodes SET audio_url = 'uploads/test.mp3' WHERE id = :id")
            ->execute([':id' => $audioNodeId]);
        $this->assertTrue(db_constellation_has_audio_nodes($this->galaxyId));
    }

    public function testGetTourConfigReturnsNullForUnknownGalaxy(): void
    {
        $this->assertNull(db_get_constellation_tour_config(987654321));
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-tour-%'");
        if ($stmt === false) return;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
            try {
                db_delete_constellation((int)$id);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}
