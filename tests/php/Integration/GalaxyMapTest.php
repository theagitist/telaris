<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Galaxy map (authored 2D view): positions, paths and routes round-trip through
 * the db_* helpers, the public payload hides unplaced wormholes and authorship,
 * and deleting a wormhole cascades out of every map table.
 * Fixtures: one ephemeral galaxy, torn down by id.
 */
final class GalaxyMapTest extends TestCase
{
    private int $cid = 0;
    private string $uid = '';

    protected function tearDown(): void
    {
        if ($this->cid) {
            $pdo = getDB();
            $pdo->prepare("DELETE FROM node_routes WHERE constellation_id = :c")->execute([':c' => $this->cid]);
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $this->cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $this->cid]);
        }
        if ($this->uid) getDB()->prepare("DELETE FROM users WHERE id = :u")->execute([':u' => $this->uid]);
    }

    public function testMapRoundTripAndCascade(): void
    {
        $sfx = bin2hex(random_bytes(4));
        $this->cid = db_create_constellation('Map ' . $sfx, '', 'map-' . $sfx, 'cosmic');
        $a = db_create_node('A', null, null, '{}', $this->cid);
        $b = db_create_node('B', null, null, '{}', $this->cid);
        $c = db_create_node('C', null, null, '{}', $this->cid);

        // Nothing authored yet: the editor payload seeds all three, the public one has nothing.
        $ed = db_get_galaxy_map($this->cid, true);
        $this->assertCount(3, $ed['positions']);
        $pub = db_get_galaxy_map($this->cid, false);
        $this->assertFalse(db_galaxy_map_has_content($pub));

        $uid = $this->uid = 'gmtest-' . $sfx;
        getDB()->prepare("INSERT INTO users (id, email, password, firstname, lastname, type) VALUES (?, ?, ?, ?, ?, 1)")
            ->execute([$uid, $uid . '@example.invalid', '', 'Map', 'Test']);
        db_record_node_position($a, 100.0, 200.0, $uid);
        db_record_node_position($b, 5000.0, -5.0, $uid);
        $pub = db_get_galaxy_map($this->cid, false);
        $pos = array_column($pub['positions'], null, 'keyword_id');
        $this->assertSame([100.0, 200.0], [$pos[$a]['canvas_x'], $pos[$a]['canvas_y']]);
        $this->assertSame([GALAXY_MAP_WIDTH, 0.0], [$pos[$b]['canvas_x'], $pos[$b]['canvas_y']]);
        $this->assertArrayNotHasKey('moved_by', $pos[$a]);
        // Deleting the editor's account keeps the map placed.
        getDB()->prepare("DELETE FROM users WHERE id = :u")->execute([':u' => $uid]);
        $this->assertCount(2, db_get_galaxy_map($this->cid, false)['positions']);
        $uid = null;

        // Paths canonicalize the pair and reject duplicates.
        $pid = db_create_node_path($b, $a, $uid, 'river', 'left', 'top');
        $p = db_get_node_path($pid);
        $this->assertSame([min($a, $b), max($a, $b)], [$p['a'], $p['b']]);
        try {
            db_create_node_path($a, $b, $uid, null);
            $this->fail('duplicate path accepted');
        } catch (PDOException $e) {
            $this->assertStringContainsString('uk_node_path_pair', $e->getMessage());
        }

        // Routes keep their stop order.
        $rid = db_create_node_route($this->cid, 'Walk', [$c, $a, $b], $uid);
        $this->assertSame([$c, $a, $b], db_get_galaxy_routes($this->cid)[0]['stops']);
        db_rename_node_route($rid, 'Stroll');
        $this->assertSame('Stroll', db_get_node_route($rid)['name']);
        $this->assertArrayNotHasKey('created_by', db_get_galaxy_map($this->cid, false)['routes'][0]);

        db_unplace_node($b);
        $this->assertCount(1, db_get_galaxy_map($this->cid, false)['positions']);

        // Deleting a wormhole removes it from positions, paths and route stops.
        getDB()->prepare("DELETE FROM nodes WHERE id = :id")->execute([':id' => $a]);
        $this->assertNull(db_get_node_path($pid));
        $this->assertSame([$c, $b], db_get_galaxy_routes($this->cid)[0]['stops']);
        $this->assertSame([], db_get_galaxy_map($this->cid, false)['positions']);
    }
}
