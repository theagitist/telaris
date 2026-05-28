<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5f federation_media_store_stats().
 *
 * Confirms the database side of the stats (count + sum of size_bytes) is
 * accurate. The on-disk side is exercised live via the admin UI under
 * sudo -u www-data, since the federation-media dir is www-data:www-data and
 * the CLI test user cannot write blobs there.
 *
 * Spec: Stage 5 galaxy publish design (5f).
 */
final class MediaStoreStatsTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupHashes = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/media_store.php';
    }

    protected function setUp(): void
    {
        db_ensure_media_blobs_table();
    }

    protected function tearDown(): void
    {
        if ($this->cleanupHashes === []) return;
        $in = implode(',', array_map(fn() => '?', $this->cleanupHashes));
        getDB()->prepare("DELETE FROM media_blobs WHERE sha256 IN ($in)")->execute($this->cleanupHashes);
    }

    public function testCountsAndSumsRecordedBlobs(): void
    {
        $before = federation_media_store_stats();

        // Insert two fake media rows; storage_path can be a placeholder for the
        // DB-side aggregate test (federation_media_lookup verifies the file's
        // existence, but the stats query just sums size_bytes).
        $h1 = hash('sha256', 'fixture-' . bin2hex(random_bytes(4)));
        $h2 = hash('sha256', 'fixture-' . bin2hex(random_bytes(4)));
        $this->cleanupHashes = [$h1, $h2];
        federation_media_record($h1, '/tmp/fixture-' . $h1, 'image/jpeg', 1234);
        federation_media_record($h2, '/tmp/fixture-' . $h2, 'audio/mp3', 5678);

        $after = federation_media_store_stats();
        $this->assertSame($before['blob_count'] + 2, $after['blob_count']);
        $this->assertSame($before['total_size_bytes'] + 1234 + 5678, $after['total_size_bytes']);
        $this->assertStringEndsWith('/federation-media', $after['store_dir']);
    }

    public function testReturnsZerosOnEmptyShape(): void
    {
        // Even on an empty table the shape must be sound: all keys present,
        // ints not nulls, store_dir set.
        $stats = federation_media_store_stats();
        foreach (['blob_count', 'total_size_bytes', 'store_dir', 'disk_blob_count', 'disk_total_bytes'] as $k) {
            $this->assertArrayHasKey($k, $stats);
        }
        $this->assertIsInt($stats['blob_count']);
        $this->assertIsInt($stats['total_size_bytes']);
        $this->assertIsInt($stats['disk_blob_count']);
        $this->assertIsInt($stats['disk_total_bytes']);
        $this->assertGreaterThan(0, strlen($stats['store_dir']));
    }
}
