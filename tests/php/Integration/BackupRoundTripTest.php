<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/backup.php';

/**
 * Integration test: export -> file -> import round-trip preserves galaxy data.
 *
 * Highest-stakes path in the snapshot/backup feature: a corrupted ref-resolution
 * or slug-conflict bug here would silently lose nodes or keywords on restore.
 *
 * Strategy:
 *  - Insert an isolated source galaxy with a unique slug, two keywords, two
 *    nodes, and a many-to-many keyword link pattern that exercises both the
 *    1-keyword and shared-keyword cases.
 *  - Build a galaxy-only dump (no users, no media), write to a temp file.
 *  - Restore in granular mode with conflict='rename', producing a copy with
 *    a known suffix.
 *  - Verify the copy: name + slug suffixed, keywords replicated, nodes
 *    replicated with descriptions, node->keyword links rebuilt by name.
 *  - Tear down both galaxies and the temp file unconditionally.
 */
final class BackupRoundTripTest extends TestCase
{
    private PDO $pdo;
    private int $sourceId = 0;
    private string $sourceSlug = '';
    private string $renameSuffix = ' (aitest copy)';
    private ?string $dumpPath = null;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanupAitestGalaxies();
        $this->sourceSlug = 'aitest-roundtrip-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->cleanupAitestGalaxies();
        if ($this->dumpPath !== null && file_exists($this->dumpPath)) {
            @unlink($this->dumpPath);
        }
    }

    public function testRoundTripPreservesGalaxyKeywordsAndNodeLinks(): void
    {
        // 1. Build the source galaxy
        $this->sourceId = db_create_constellation('Aitest Source', 'round-trip', $this->sourceSlug, 'cosmic');

        $animation = json_encode([
            'radius' => 5.0, 'theta' => 1.0, 'phi' => 1.0, 'speed' => 1.0, 'phase' => 0.0,
        ]);
        $node1Id = db_create_node('Aitest Node One', 'description one', null, $animation, $this->sourceId);
        $node2Id = db_create_node('Aitest Node Two', 'description two', null, $animation, $this->sourceId);

        // Node 1 -> [alpha, beta], Node 2 -> [beta]. Shared keyword 'beta' makes a connection.
        db_save_node_keywords($node1Id, ['aitest-alpha', 'aitest-beta']);
        db_save_node_keywords($node2Id, ['aitest-beta']);

        // 2. Build dump and write to temp file
        $dump = backup_build_dump([
            'galaxy_ids' => [$this->sourceId],
            'include_users' => false,
            'media_mode' => 'none',
            'include_galaxies' => true,
        ]);
        $this->assertSame(BACKUP_FORMAT, $dump['format'] ?? null);
        $this->assertSame(BACKUP_FORMAT_VERSION, $dump['format_version'] ?? null);
        $this->assertCount(1, $dump['galaxies'] ?? []);

        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-backup-') . '.telaris-backup';
        backup_write_to_file($dump, $this->dumpPath);
        $this->assertFileExists($this->dumpPath);

        // 3. Restore as a renamed copy
        $galRef = (string)$dump['galaxies'][0]['ref'];
        $report = backup_restore_from_file($this->dumpPath, [
            'mode' => 'granular',
            'restore_users' => false,
            'restore_media' => false,
            'rename_suffix_default' => $this->renameSuffix,
            'galaxies' => [
                $galRef => [
                    'include' => true,
                    'conflict' => 'rename',
                    'rename_suffix' => $this->renameSuffix,
                ],
            ],
        ]);

        // Whether the dump's slug already existed or not, conflict='rename' is the explicit path.
        // backup_restore_one_galaxy only suffixes the name when an existing slug matches; in our
        // case the source galaxy still exists so the suffix is applied.
        $this->assertSame(1, $report['galaxies_renamed'] + $report['galaxies_created'], 'Exactly one galaxy should be restored');

        // 4. Find the copy galaxy by suffixed slug
        $copySlug = $this->sourceSlug . db_slugify($this->renameSuffix);
        $copyId = db_get_constellation_id_by_slug($copySlug);
        $this->assertNotNull($copyId, "Copy galaxy with slug '$copySlug' should exist after restore");
        $this->assertNotSame($this->sourceId, $copyId, 'Copy must be a distinct row, not the source');

        // 5. Verify keywords on the copy
        $copyKeywords = $this->pdo->prepare("SELECT keyword FROM keywords WHERE constellation_id = :id ORDER BY keyword");
        $copyKeywords->execute([':id' => $copyId]);
        $kws = $copyKeywords->fetchAll(PDO::FETCH_COLUMN, 0);
        $this->assertSame(['aitest-alpha', 'aitest-beta'], $kws);

        // 6. Verify nodes on the copy
        $copyNodes = $this->pdo->prepare("SELECT id, name, description FROM nodes WHERE constellation_id = :id ORDER BY name");
        $copyNodes->execute([':id' => $copyId]);
        $nodes = $copyNodes->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $nodes);
        $this->assertSame('Aitest Node One', $nodes[0]['name']);
        $this->assertSame('description one', $nodes[0]['description']);
        $this->assertSame('Aitest Node Two', $nodes[1]['name']);
        $this->assertSame('description two', $nodes[1]['description']);

        // 7. Verify node->keyword links survived (resolved by keyword name across the dump)
        $node1Kws = db_get_keywords_for_node((int)$nodes[0]['id']);
        $node2Kws = db_get_keywords_for_node((int)$nodes[1]['id']);
        sort($node1Kws);
        sort($node2Kws);
        $this->assertSame(['aitest-alpha', 'aitest-beta'], $node1Kws, 'Node One should keep both keywords');
        $this->assertSame(['aitest-beta'], $node2Kws, 'Node Two should keep its single keyword');
    }

    /**
     * Remove any galaxy whose slug starts with our test prefix. Idempotent.
     * Runs in setUp and tearDown so a previously-failed test cannot leave junk.
     */
    private function cleanupAitestGalaxies(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-%'");
        if ($stmt === false) return;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
            try {
                db_delete_constellation((int)$id);
            } catch (Throwable $e) {
                // Ignore: default-constellation guard never matches our slugs.
            }
        }
    }
}
