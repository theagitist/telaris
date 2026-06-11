<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Full create-read-update-delete round-trip for a wormhole (node), asserted
 * through the public db_* helpers and the API formatter db_format_node.
 *
 * This is the broad safety net for the data layer's core write path. It would
 * have caught two bugs that shipped to users:
 *   - 86cc082: db_format_node / db_format_nodes_bulk whitelist their output, and
 *     media_mode / hotglue_page were dropped, so the editor and viewer fell back
 *     to classic media even when the node was set to hotglue. Pinned by
 *     testMediaModeSurvivesTheFormatter.
 *   - the perennial tinyint-to-bool drift: BOOLEAN columns come back from MySQL
 *     as '0'/'1' and must be cast to real booleans for the JSON API. Pinned by
 *     testBooleanFieldsAreRealBooleans.
 *
 * Everything is asserted through the helper layer (not raw SQL), so the file
 * encodes the contract that must hold identically before and after the MySQL to
 * PostgreSQL migration (ROADMAP ^pg-migration) and survives the rewrite
 * unchanged. Two assertions are explicit migration tripwires, flagged in their
 * doc comments: lastInsertId behaviour (Postgres needs INSERT ... RETURNING id)
 * and the ON UPDATE CURRENT_TIMESTAMP auto-touch on updated_at (Postgres needs a
 * trigger).
 *
 * Fixtures: ephemeral constellation per test, torn down by id.
 */
final class NodeCrudRoundTripTest extends TestCase
{
    /** @var list<int> */
    private array $cids = [];

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM node_keywords WHERE node_id IN (SELECT id FROM nodes WHERE constellation_id = :c)")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        $this->cids = [];
    }

    private function makeConstellation(): int
    {
        $sfx = bin2hex(random_bytes(4));
        $cid = db_create_constellation('Node CRUD ' . $sfx, '', 'ncrud-' . $sfx, 'cosmic');
        $this->cids[] = $cid;
        return $cid;
    }

    /* ---- create + read back -------------------------------------------- */

    public function testCreateAndReadBackCoreFields(): void
    {
        $cid = $this->makeConstellation();
        $anim = '{"radius":5,"theta":1.2,"phi":0.3,"speed":0.0025,"phase":0}';
        $nodeId = db_create_node('Alpha wormhole', 'a description', 'https://example.com', $anim, $cid);
        $this->assertGreaterThan(0, $nodeId);

        $formatted = db_format_node(db_get_node_by_id($nodeId));
        $this->assertSame($nodeId, $formatted['id']);
        $this->assertSame('Alpha wormhole', $formatted['name']);
        $this->assertSame('a description', $formatted['description']);
        $this->assertSame('https://example.com', $formatted['url']);
        $this->assertSame($cid, $formatted['constellation_id']);
        $this->assertSame('object', $formatted['node_type']);
        $this->assertIsArray($formatted['keywords']);
        $this->assertSame([], $formatted['keywords']);
        $this->assertIsArray($formatted['animation']);
        $this->assertSame(5, $formatted['animation']['radius']);
    }

    public function testNullableFieldsRoundTripAsNull(): void
    {
        $cid = $this->makeConstellation();
        $nodeId = db_create_node('No optionals', null, null, '{}', $cid);
        $formatted = db_format_node(db_get_node_by_id($nodeId));
        $this->assertNull($formatted['description']);
        $this->assertNull($formatted['url']);
        $this->assertNull($formatted['image_url']);
        $this->assertNull($formatted['icon_url']);
        $this->assertNull($formatted['audio_url']);
        $this->assertNull($formatted['video_url']);
        $this->assertNull($formatted['pdf_url']);
        $this->assertNull($formatted['target_constellation_id']);
    }

    /**
     * tinyint(1) BOOLEAN columns come back as '0'/'1'; the API contract is real
     * PHP booleans. This drift is exactly the kind the migration can silently
     * change (Postgres BOOLEAN returns differently again), so pin every flag.
     */
    public function testBooleanFieldsAreRealBooleans(): void
    {
        $cid = $this->makeConstellation();
        // positional args: ..., audioAutoplay, isAccentuated, videoUrl,
        // videoAutoplay, audioLoop, showKeywords, ...
        $nodeId = db_create_node(
            'Flags', null, null, '{}', $cid, 'object', null, null, null, null,
            false,  // audioAutoplay
            true,   // isAccentuated
            null,   // videoUrl
            false,  // videoAutoplay
            true,   // audioLoop
            true    // showKeywords
        );
        $f = db_format_node(db_get_node_by_id($nodeId));
        $this->assertIsBool($f['audio_autoplay']);
        $this->assertFalse($f['audio_autoplay']);
        $this->assertIsBool($f['is_accentuated']);
        $this->assertTrue($f['is_accentuated']);
        $this->assertIsBool($f['video_autoplay']);
        $this->assertFalse($f['video_autoplay']);
        $this->assertIsBool($f['audio_loop']);
        $this->assertTrue($f['audio_loop']);
        $this->assertIsBool($f['show_keywords']);
        $this->assertTrue($f['show_keywords']);
    }

    /**
     * Regression for 86cc082: media_mode and hotglue_page were dropped by the
     * output whitelist in db_format_node, so a hotglue wormhole rendered as
     * classic. A fresh node defaults to classic; flipping it must survive the
     * read-back through the formatter.
     */
    public function testMediaModeSurvivesTheFormatter(): void
    {
        $cid = $this->makeConstellation();
        $nodeId = db_create_node('Media mode', null, null, '{}', $cid);

        $fresh = db_format_node(db_get_node_by_id($nodeId));
        $this->assertSame('classic', $fresh['media_mode'], 'a new node defaults to classic media');
        $this->assertNull($fresh['hotglue_page']);

        db_set_node_media_mode($nodeId, 'hotglue');
        $after = db_format_node(db_get_node_by_id($nodeId));
        $this->assertSame('hotglue', $after['media_mode'], 'media_mode must survive the formatter whitelist');
    }

    /* ---- update -------------------------------------------------------- */

    public function testUpdateRoundTrip(): void
    {
        $cid = $this->makeConstellation();
        $nodeId = db_create_node('Before', 'old desc', 'https://old.example', '{}', $cid);
        db_update_node($nodeId, 'After', 'new desc', 'https://new.example', '{"radius":7}', $cid);

        $f = db_format_node(db_get_node_by_id($nodeId));
        $this->assertSame('After', $f['name']);
        $this->assertSame('new desc', $f['description']);
        $this->assertSame('https://new.example', $f['url']);
        $this->assertSame(7, $f['animation']['radius']);
    }

    /**
     * Migration tripwire: updated_at is ON UPDATE CURRENT_TIMESTAMP in MySQL,
     * so an UPDATE auto-advances it. PostgreSQL has no such column modifier and
     * needs a BEFORE UPDATE trigger; if the migration forgets it, this fails.
     * The 1s sleep is deliberate: TIMESTAMP resolution is whole seconds.
     */
    public function testUpdatedAtAutoAdvancesOnUpdate(): void
    {
        $cid = $this->makeConstellation();
        $nodeId = db_create_node('Touch me', null, null, '{}', $cid);
        $before = db_get_node_by_id($nodeId)['updated_at'];

        sleep(1);
        db_update_node($nodeId, 'Touched', null, null, '{}', $cid);
        $after = db_get_node_by_id($nodeId)['updated_at'];

        $this->assertGreaterThan(
            strtotime((string)$before),
            strtotime((string)$after),
            'updated_at must auto-advance on UPDATE (ON UPDATE CURRENT_TIMESTAMP / trigger)'
        );
    }

    /* ---- identity / lastInsertId --------------------------------------- */

    /**
     * Migration tripwire: db_create_node returns pdo->lastInsertId(). On MySQL
     * that reads the AUTO_INCREMENT value; on PostgreSQL it needs an
     * INSERT ... RETURNING id (lastInsertId requires a sequence name and is
     * brittle). New ids must be positive and strictly distinct.
     */
    public function testNewNodeIdsArePositiveAndDistinct(): void
    {
        $cid = $this->makeConstellation();
        $a = db_create_node('one', null, null, '{}', $cid);
        $b = db_create_node('two', null, null, '{}', $cid);
        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThan(0, $b);
        $this->assertNotSame($a, $b);
    }

    /* ---- delete -------------------------------------------------------- */

    public function testDeleteRemovesNode(): void
    {
        $cid = $this->makeConstellation();
        $nodeId = db_create_node('doomed', null, null, '{}', $cid);
        $this->assertNotNull(db_get_node_by_id($nodeId));
        db_delete_node($nodeId);
        $this->assertNull(db_get_node_by_id($nodeId));
    }

    public function testGetNodeByIdReturnsNullForMissing(): void
    {
        $this->assertNull(db_get_node_by_id(2000000000));
    }

    /* ---- duplicate detection ------------------------------------------- */

    public function testNodeExistsDetectsDuplicateName(): void
    {
        $cid = $this->makeConstellation();
        $nodeId = db_create_node('Unique name', null, null, '{}', $cid);

        $this->assertTrue(db_node_exists('Unique name', $cid));
        // The node should not count as a duplicate of itself.
        $this->assertFalse(db_node_exists('Unique name', $cid, $nodeId));
        $this->assertFalse(db_node_exists('Some other name', $cid));
    }
}
