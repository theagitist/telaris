<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/backup.php';

/**
 * Integration test: snapshots/backups round-trip a wormhole's hotglue media
 * (phase 7, the release gate).
 *
 * Covers:
 *  - the nodes.media_mode / nodes.hotglue_page columns through dump + restore;
 *  - the hg/content/<page> tree (head/ + shared/) captured as sha256-checked
 *    blobs and rewritten on restore;
 *  - reconciliation: a default-named page (hotglue_page NULL) FOLLOWS the new
 *    node id (node-<newid>); a custom page name is PRESERVED;
 *  - media_mode='none' dumps omit the content tree;
 *  - the path-traversal / page-name guards on the write helper.
 *
 * Writes live under <app>/hg/content (www-data-owned), so run this file as
 * www-data. Every page dir created is tracked and removed in tearDown.
 */
final class HotglueBackupRoundTripTest extends TestCase
{
    private PDO $pdo;
    private string $slugPrefix = '';
    private string $renameSuffix = ' (aitest hg copy)';
    private ?string $dumpPath = null;
    /** @var list<string> page dirs to remove on teardown */
    private array $pagesToClean = [];

    protected function setUp(): void
    {
        $this->pdo = getDB();
        if (backup_hotglue_content_dir() === null) {
            $this->markTestSkipped('hg/content store not present on this host');
        }
        $this->slugPrefix = 'aitest-hg-' . bin2hex(random_bytes(4));
        $this->cleanupGalaxies();
    }

    protected function tearDown(): void
    {
        $this->cleanupGalaxies();
        foreach ($this->pagesToClean as $page) {
            $this->removePageDir($page);
        }
        if ($this->dumpPath !== null && file_exists($this->dumpPath)) {
            @unlink($this->dumpPath);
        }
    }

    public function testDefaultPageContentFollowsNewNodeId(): void
    {
        $gid = db_create_constellation('Aitest HG', 'hotglue', $this->slugPrefix . '-default', 'cosmic');
        $anim = json_encode(['radius' => 5.0, 'theta' => 1.0, 'phi' => 1.0, 'speed' => 1.0, 'phase' => 0.0]);
        $nid = db_create_node('Aitest HG Node', 'desc', null, $anim, $gid);
        db_set_node_media_mode($nid, 'hotglue');

        // Live page = node-<id> (default; hotglue_page is NULL). Write a head
        // object and a shared media file.
        $page = 'node-' . $nid;
        $this->pagesToClean[] = $page;
        $obj = "type:text\nmodule:text\nobject-left:10px\nobject-top:10px\n\nhello aitest";
        $shared = "binary-ish\x00\x01shared bytes";
        $this->writePageFile($page, 'head/178102390659', $obj);
        $this->writePageFile($page, 'shared/pic.bin', $shared);

        // Dump (embedded media mode = full content).
        $dump = backup_build_dump(['galaxy_ids' => [$gid], 'include_users' => false, 'media_mode' => 'embedded', 'include_galaxies' => true]);
        $node = $this->onlyNode($dump);
        $this->assertSame('hotglue', $node['media_mode']);
        $this->assertNull($node['hotglue_page']);
        $this->assertArrayHasKey('hotglue_content', $node);
        $this->assertSame($page, $node['hotglue_content']['page']);
        $paths = array_column($node['hotglue_content']['files'], 'path');
        sort($paths);
        $this->assertSame(['head/178102390659', 'shared/pic.bin'], $paths, 'head/ and shared/ captured');
        foreach ($node['hotglue_content']['files'] as $f) {
            $this->assertArrayHasKey('sha256', $f);
        }

        // Restore as a renamed copy.
        $newGid = $this->restoreRename($dump);
        $newNode = $this->pdo->query("SELECT id, media_mode, hotglue_page FROM nodes WHERE constellation_id = $newGid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($newNode);
        $newNid = (int)$newNode['id'];
        $this->assertSame('hotglue', $newNode['media_mode']);
        $this->assertNull($newNode['hotglue_page'], 'default page stays NULL so it maps to node-<newid>');
        $this->assertNotSame($nid, $newNid, 'restore creates a distinct node');

        // Content followed the new id: node-<newid> holds byte-identical files.
        $newPage = 'node-' . $newNid;
        $this->pagesToClean[] = $newPage;
        $restored = backup_hotglue_collect_files($newPage);
        $this->assertCount(2, $restored);
        $this->assertHashesMatch($page, $newPage);
    }

    public function testCustomPageNameIsPreserved(): void
    {
        $gid = db_create_constellation('Aitest HG Custom', 'hotglue', $this->slugPrefix . '-custom', 'cosmic');
        $anim = json_encode(['radius' => 5.0, 'theta' => 1.0, 'phi' => 1.0, 'speed' => 1.0, 'phase' => 0.0]);
        $nid = db_create_node('Aitest HG Custom Node', 'desc', null, $anim, $gid);
        db_set_node_media_mode($nid, 'hotglue');
        $custom = $this->slugPrefix . '-page';
        $this->pdo->prepare("UPDATE nodes SET hotglue_page = :p WHERE id = :id")->execute([':p' => $custom, ':id' => $nid]);
        $this->pagesToClean[] = $custom;
        $this->writePageFile($custom, 'head/178102390659', "type:text\nmodule:text\n\ncustom page");

        $dump = backup_build_dump(['galaxy_ids' => [$gid], 'include_users' => false, 'media_mode' => 'embedded', 'include_galaxies' => true]);
        $node = $this->onlyNode($dump);
        $this->assertSame($custom, $node['hotglue_page']);
        $this->assertSame($custom, $node['hotglue_content']['page']);

        $newGid = $this->restoreRename($dump);
        $newNode = $this->pdo->query("SELECT id, hotglue_page FROM nodes WHERE constellation_id = $newGid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($custom, $newNode['hotglue_page'], 'custom page name preserved on restore');
        // Content lives at the custom page (unchanged), byte-identical.
        $restored = backup_hotglue_collect_files($custom);
        $this->assertCount(1, $restored);
        $this->assertHashesMatch($custom, $custom);
    }

    public function testMediaModeNoneOmitsHotglueContent(): void
    {
        $gid = db_create_constellation('Aitest HG None', 'hotglue', $this->slugPrefix . '-none', 'cosmic');
        $anim = json_encode(['radius' => 5.0, 'theta' => 1.0, 'phi' => 1.0, 'speed' => 1.0, 'phase' => 0.0]);
        $nid = db_create_node('Aitest HG None Node', 'desc', null, $anim, $gid);
        db_set_node_media_mode($nid, 'hotglue');
        $page = 'node-' . $nid;
        $this->pagesToClean[] = $page;
        $this->writePageFile($page, 'head/1', "type:text\nmodule:text\n\nx");

        $dump = backup_build_dump(['galaxy_ids' => [$gid], 'include_users' => false, 'media_mode' => 'none', 'include_galaxies' => true]);
        $node = $this->onlyNode($dump);
        $this->assertSame('hotglue', $node['media_mode'], 'the column is still carried');
        $this->assertArrayNotHasKey('hotglue_content', $node, "media_mode='none' strips embedded content");
    }

    public function testWriteFilesRejectsTraversalAndForeignSubtrees(): void
    {
        $page = $this->slugPrefix . '-guard';
        $this->pagesToClean[] = $page;
        $files = [
            ['path' => 'head/ok', 'content_b64' => base64_encode('good'), 'sha256' => hash('sha256', 'good')],
            ['path' => 'shared/ok2', 'content_b64' => base64_encode('good2'), 'sha256' => hash('sha256', 'good2')],
            ['path' => '../evil', 'content_b64' => base64_encode('bad'), 'sha256' => hash('sha256', 'bad')],
            ['path' => 'head/../../evil', 'content_b64' => base64_encode('bad'), 'sha256' => hash('sha256', 'bad')],
            ['path' => 'secrets/x', 'content_b64' => base64_encode('bad'), 'sha256' => hash('sha256', 'bad')],
            ['path' => 'head/bad', 'content_b64' => base64_encode('tampered'), 'sha256' => 'deadbeef'], // sha mismatch
        ];
        $written = backup_hotglue_write_files($page, $files);
        $this->assertSame(2, $written, 'only the two valid head/ + shared/ files are written');

        $base = backup_hotglue_content_dir();
        $this->assertFileExists($base . '/' . $page . '/head/ok');
        $this->assertFileExists($base . '/' . $page . '/shared/ok2');
        // The traversal target must NOT have escaped the page dir.
        $this->assertFileDoesNotExist($base . '/evil');
        $this->assertFileDoesNotExist(dirname($base) . '/evil');
    }

    public function testPageNameGuard(): void
    {
        $this->assertTrue(backup_hotglue_page_name_ok('node-53'));
        $this->assertTrue(backup_hotglue_page_name_ok('a.b-c_d'));
        $this->assertFalse(backup_hotglue_page_name_ok(''));
        $this->assertFalse(backup_hotglue_page_name_ok('node-53/head'));
        $this->assertFalse(backup_hotglue_page_name_ok('../etc'));
        $this->assertFalse(backup_hotglue_page_name_ok('a b'));
    }

    public function testNodeHotgluePageMapping(): void
    {
        $gid = db_create_constellation('Aitest HG Map', 'hotglue', $this->slugPrefix . '-map', 'cosmic');
        $anim = json_encode(['radius' => 5.0, 'theta' => 1.0, 'phi' => 1.0, 'speed' => 1.0, 'phase' => 0.0]);
        $nid = db_create_node('Aitest HG Map Node', 'desc', null, $anim, $gid);
        $this->assertSame('node-' . $nid, db_node_hotglue_page($nid), 'default maps to node-<id>');
        $this->pdo->prepare("UPDATE nodes SET hotglue_page = :p WHERE id = :id")->execute([':p' => $this->slugPrefix . '-mp', ':id' => $nid]);
        $this->assertSame($this->slugPrefix . '-mp', db_node_hotglue_page($nid), 'custom name returned verbatim');
    }

    // --- helpers -----------------------------------------------------------

    private function onlyNode(array $dump): array
    {
        $this->assertCount(1, $dump['galaxies'] ?? []);
        $nodes = $dump['galaxies'][0]['nodes'] ?? [];
        $this->assertCount(1, $nodes);
        return $nodes[0];
    }

    private function restoreRename(array $dump): int
    {
        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-hg-') . '.telaris-backup';
        backup_write_to_file($dump, $this->dumpPath);
        $galRef = (string)$dump['galaxies'][0]['ref'];
        $report = backup_restore_from_file($this->dumpPath, [
            'mode' => 'granular',
            'restore_users' => false,
            'restore_media' => true,
            'rename_suffix_default' => $this->renameSuffix,
            'galaxies' => [$galRef => ['include' => true, 'conflict' => 'rename', 'rename_suffix' => $this->renameSuffix]],
        ]);
        $this->assertSame(1, $report['galaxies_renamed'] + $report['galaxies_created']);
        $this->assertGreaterThan(0, $report['hotglue_files_written'] ?? 0, 'restore reports hotglue files written');
        // The renamed copy's slug.
        $base = $dump['galaxies'][0]['slug'];
        $copyId = db_get_constellation_id_by_slug($base . db_slugify($this->renameSuffix));
        $this->assertNotNull($copyId);
        return (int)$copyId;
    }

    private function writePageFile(string $page, string $rel, string $content): void
    {
        $base = backup_hotglue_content_dir();
        $abs = $base . '/' . $page . '/' . $rel;
        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $content);
    }

    private function assertHashesMatch(string $pageA, string $pageB): void
    {
        $a = []; foreach (backup_hotglue_collect_files($pageA) as $f) { $a[$f['path']] = $f['sha256']; }
        $b = []; foreach (backup_hotglue_collect_files($pageB) as $f) { $b[$f['path']] = $f['sha256']; }
        ksort($a); ksort($b);
        $this->assertSame($a, $b, "pages $pageA and $pageB must have byte-identical file sets");
    }

    private function removePageDir(string $page): void
    {
        $base = backup_hotglue_content_dir();
        if ($base === null) return;
        $dir = $base . '/' . $page;
        if (is_dir($dir)) {
            try { db_rrmdir($dir, realpath($base)); } catch (Throwable $e) { /* best effort */ }
        }
    }

    private function cleanupGalaxies(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-hg-%'");
        if ($stmt === false) return;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
            try { db_delete_constellation((int)$id); } catch (Throwable $e) { /* ignore */ }
        }
    }
}
