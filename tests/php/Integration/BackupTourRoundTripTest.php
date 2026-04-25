<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/backup.php';

/**
 * Integration test: tour config + tour keyword selections survive a backup/restore.
 *
 * Confirms the dump emits tour fields and `keyword_refs`, and that on restore
 * the new keyword IDs are correctly resolved when applying tour keyword filters.
 */
final class BackupTourRoundTripTest extends TestCase
{
    private PDO $pdo;
    private int $sourceId = 0;
    private string $sourceSlug = '';
    private string $renameSuffix = ' (aitest tour copy)';
    private ?string $dumpPath = null;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
        $this->sourceSlug = 'aitest-tour-bk-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        if ($this->dumpPath !== null && file_exists($this->dumpPath)) {
            @unlink($this->dumpPath);
        }
    }

    public function testTourSettingsSurviveBackupAndRestore(): void
    {
        $this->sourceId = db_create_constellation('Aitest Tour BK', '', $this->sourceSlug, 'cosmic');

        $kwAlpha = db_create_keyword('aitest-bk-alpha', $this->sourceId);
        $kwBeta = db_create_keyword('aitest-bk-beta', $this->sourceId);
        $kwGamma = db_create_keyword('aitest-bk-gamma', $this->sourceId);

        db_set_constellation_tour_config($this->sourceId, [
            'tour_enabled' => true,
            'tour_start_mode' => 'idle',
            'tour_idle_seconds' => 42,
            'tour_node_selection' => 'tagged',
            'tour_random_count' => 5,
            'tour_default_dwell' => 11,
            'tour_loop' => false,
        ]);
        db_set_tour_keyword_ids($this->sourceId, [$kwAlpha, $kwGamma]);

        $dump = backup_build_dump([
            'galaxy_ids' => [$this->sourceId],
            'include_users' => false,
            'media_mode' => 'none',
            'include_galaxies' => true,
        ]);
        $this->assertCount(1, $dump['galaxies'] ?? []);
        $this->assertNotEmpty($dump['galaxies'][0]['tour'] ?? null);
        $this->assertSame('tagged', $dump['galaxies'][0]['tour']['node_selection']);
        $this->assertCount(2, $dump['galaxies'][0]['tour']['keyword_refs']);

        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-tour-bk-') . '.telaris-backup';
        backup_write_to_file($dump, $this->dumpPath);

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
        $this->assertSame(1, $report['galaxies_renamed'] + $report['galaxies_created']);

        $copySlug = $this->sourceSlug . db_slugify($this->renameSuffix);
        $copyId = db_get_constellation_id_by_slug($copySlug);
        $this->assertNotNull($copyId);

        $cfg = db_get_constellation_tour_config((int)$copyId);
        $this->assertNotNull($cfg);
        $this->assertTrue($cfg['tour_enabled']);
        $this->assertSame('idle', $cfg['tour_start_mode']);
        $this->assertSame(42, $cfg['tour_idle_seconds']);
        $this->assertSame('tagged', $cfg['tour_node_selection']);
        $this->assertSame(5, $cfg['tour_random_count']);
        $this->assertSame(11, $cfg['tour_default_dwell']);
        $this->assertFalse($cfg['tour_loop']);

        // Resolve restored keyword IDs by name and verify they match the tour selection.
        $kwStmt = $this->pdo->prepare("SELECT id, keyword FROM keywords WHERE constellation_id = :id");
        $kwStmt->execute([':id' => $copyId]);
        $byName = [];
        foreach ($kwStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byName[$row['keyword']] = (int)$row['id'];
        }
        $expected = [$byName['aitest-bk-alpha'], $byName['aitest-bk-gamma']];
        sort($expected);
        $stored = $cfg['tour_keyword_ids'];
        sort($stored);
        $this->assertSame($expected, $stored);
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-tour-bk-%'");
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
