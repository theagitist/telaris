<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contract for the "Disable Hotglue content" installation switch, asserted
 * through the public db_* helpers.
 *
 * Two pieces are pinned here:
 *  - db_get/db_set_disable_hotglue_content round-trip the installation-level
 *    flag (stored on the project_info 'en' row). The default is off, so a fresh
 *    installation keeps full hotglue support.
 *  - db_node_has_hotglue_content recognises a wormhole that already has hotglue
 *    content (media_mode = 'hotglue', OR a hotglue_pages row assigned to it).
 *    The switch leans on this to keep existing content editable while refusing
 *    to create new hotglue content.
 *
 * The switch's actual value is saved in setUp and restored in tearDown, so this
 * file is safe to run against the live starmaps DB without leaving hotglue
 * disabled for real editors. Fixtures are ephemeral and torn down by id.
 */
final class HotglueDisableTest extends TestCase
{
    /** @var list<int> constellation ids created during the test */
    private array $cids = [];
    private bool $originalSwitch = false;

    protected function setUp(): void
    {
        $this->originalSwitch = db_get_disable_hotglue_content();
    }

    protected function tearDown(): void
    {
        // Restore the installation switch to whatever it was before the test.
        db_set_disable_hotglue_content($this->originalSwitch);

        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM hotglue_pages WHERE node_id IN (SELECT id FROM nodes WHERE constellation_id = :c)")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM node_keywords WHERE node_id IN (SELECT id FROM nodes WHERE constellation_id = :c)")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        $this->cids = [];
    }

    private function makeNode(): int
    {
        $sfx = bin2hex(random_bytes(4));
        $cid = db_create_constellation('HG test ' . $sfx, '', 'hg-' . $sfx, 'cosmic');
        $this->cids[] = $cid;
        return db_create_node('node-' . $sfx, null, null, '{}', $cid);
    }

    /* ---- the installation switch --------------------------------------- */

    public function testSwitchRoundTrips(): void
    {
        db_set_disable_hotglue_content(true);
        $this->assertTrue(db_get_disable_hotglue_content());

        db_set_disable_hotglue_content(false);
        $this->assertFalse(db_get_disable_hotglue_content());
    }

    public function testColumnIsPresent(): void
    {
        db_ensure_disable_hotglue_content_column();
        $row = getDB()->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'project_info' AND column_name = 'disable_hotglue_content'")->fetchColumn();
        $this->assertNotFalse($row, 'disable_hotglue_content column should exist after ensure');
    }

    /* ---- has-hotglue-content detection --------------------------------- */

    public function testFreshClassicNodeHasNoHotglueContent(): void
    {
        $nodeId = $this->makeNode();
        $this->assertFalse(db_node_has_hotglue_content($nodeId));
    }

    public function testNodeInHotglueModeHasContent(): void
    {
        $nodeId = $this->makeNode();
        db_set_node_media_mode($nodeId, 'hotglue');
        $this->assertTrue(db_node_has_hotglue_content($nodeId));
    }

    public function testNodeWithAssignedPageHasContentEvenWhenClassic(): void
    {
        $nodeId = $this->makeNode();
        // Register a hotglue_pages row for the node without flipping media_mode.
        $page = db_hotglue_page_get_or_create_for_node($nodeId, null);
        $this->assertNotNull($page);
        $this->assertTrue(db_node_has_hotglue_content($nodeId));
    }
}
