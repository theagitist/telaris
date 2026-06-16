<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5f-vii federation media GC sweep.
 *
 * Covers the DB-side logic in full: the live-set collector across all five
 * media columns, dry-run vs real sweep on DB-only orphans (media_blobs rows
 * whose storage_path no longer exists), and protection of blobs in the live
 * set. The on-disk happy path (writing then sweeping a real file under
 * federation-media) is verified live via `sudo -u www-data php
 * bin/galaxy-media-gc`, the same posture as MediaStoreTest's write paths.
 *
 * Spec: Stage 5 galaxy publish design (5f-vii).
 */
final class MediaGcTest extends TestCase
{
    /** @var list<int> */
    private array $cids = [];
    /** @var list<string> */
    private array $hashes = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_media_gc.php';
    }

    protected function setUp(): void
    {
        db_ensure_media_blobs_table();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        if ($this->hashes !== []) {
            $in = implode(',', array_map(fn() => '?', $this->hashes));
            $pdo->prepare("DELETE FROM media_blobs WHERE sha256 IN ($in)")->execute($this->hashes);
        }
        $this->cids = [];
        $this->hashes = [];
    }

    private function mkGalaxyWithMedia(string $slug, array $cols): int
    {
        $pdo = getDB();
        $insC = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme) VALUES (:n, :s, 'galaxy', 'cosmic') RETURNING id");
        $insC->execute([':n' => 'GC ' . $slug, ':s' => $slug]);
        $cid = (int)$insC->fetchColumn();
        $this->cids[] = $cid;
        $stmt = $pdo->prepare("INSERT INTO nodes (constellation_id, name, animation, image_url, icon_url, audio_url, video_url, pdf_url)
                                VALUES (:c, :n, '{}', :img, :ico, :aud, :vid, :pdf)");
        $stmt->execute([
            ':c' => $cid,
            ':n' => 'media-' . $slug,
            ':img' => $cols['image_url'] ?? null,
            ':ico' => $cols['icon_url'] ?? null,
            ':aud' => $cols['audio_url'] ?? null,
            ':vid' => $cols['video_url'] ?? null,
            ':pdf' => $cols['pdf_url'] ?? null,
        ]);
        return $cid;
    }

    private function registerBlob(string $bytes, ?string $existingHash = null): string
    {
        $hash = $existingHash ?? hash('sha256', $bytes);
        $tmp = sys_get_temp_dir() . '/gc-test-' . $hash;
        if ($existingHash === null) file_put_contents($tmp, $bytes);
        federation_media_record($hash, $tmp, 'image/jpeg', strlen($bytes));
        $this->hashes[] = $hash;
        return $hash;
    }

    public function testCollectLiveSha256sFindsAllFiveColumns(): void
    {
        $hashes = [];
        for ($i = 0; $i < 5; $i++) $hashes[] = hash('sha256', 'live-' . $i . '-' . bin2hex(random_bytes(3)));
        $this->mkGalaxyWithMedia('gc-five-' . bin2hex(random_bytes(3)), [
            'image_url' => 'uploads/federation-media/' . $hashes[0],
            'icon_url'  => 'uploads/federation-media/' . $hashes[1],
            'audio_url' => 'uploads/federation-media/' . $hashes[2],
            'video_url' => 'uploads/federation-media/' . $hashes[3],
            'pdf_url'   => 'uploads/federation-media/' . $hashes[4],
        ]);
        $live = federation_media_gc_collect_live_sha256s();
        foreach ($hashes as $h) {
            $this->assertArrayHasKey($h, $live, "sha256 in column not picked up: $h");
        }
    }

    public function testCollectLiveSha256sIgnoresUnrelatedAndMalformedUrls(): void
    {
        $real = hash('sha256', 'real-' . bin2hex(random_bytes(3)));
        $this->mkGalaxyWithMedia('gc-mixed-' . bin2hex(random_bytes(3)), [
            'image_url' => 'uploads/federation-media/' . $real,
            'icon_url'  => 'uploads/123/456/local.png',           // pre-federation upload
            'audio_url' => 'https://external.example/a.mp3',      // external URL
            'video_url' => 'uploads/federation-media/not-a-hash', // malformed
            'pdf_url'   => null,
        ]);
        $live = federation_media_gc_collect_live_sha256s();
        $this->assertArrayHasKey($real, $live);
        $this->assertArrayNotHasKey('not-a-hash', $live);
        // 64 chars but not all hex would also be rejected by federation_media_is_sha256
        // (uppercase tested in MediaStoreTest); here the URL doesn't even match the LIKE.
    }

    public function testSweepDryRunDoesNotDeleteOrphans(): void
    {
        // Register a media_blobs row whose storage_path points at /tmp; the
        // sweep's scan_disk only looks inside UPLOAD_DIR/federation-media, so
        // this row is a "db-only orphan" by construction. Not in the live set
        // either.
        $hash = $this->registerBlob('orphan-' . bin2hex(random_bytes(4)));

        $report = federation_media_gc_sweep(true);  // dry-run
        $this->assertTrue($report['dry_run']);
        $this->assertContains($hash, $report['db_orphans']);

        // Row still there after dry-run.
        $count = (int)getDB()->prepare("SELECT COUNT(*) FROM media_blobs WHERE sha256 = :s")->execute([':s' => $hash]) ? 1 : 0;
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM media_blobs WHERE sha256 = :s");
        $stmt->execute([':s' => $hash]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testSweepDeletesDbOnlyOrphans(): void
    {
        $hash = $this->registerBlob('orphan-' . bin2hex(random_bytes(4)));
        $report = federation_media_gc_sweep(false);
        $this->assertContains($hash, $report['db_orphans']);

        $stmt = getDB()->prepare("SELECT COUNT(*) FROM media_blobs WHERE sha256 = :s");
        $stmt->execute([':s' => $hash]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'orphan row removed');
        // No need to track the hash for tearDown DELETE: it is already gone.
        $this->hashes = array_values(array_diff($this->hashes, [$hash]));
    }

    public function testSweepProtectsBlobInLiveSet(): void
    {
        $hash = $this->registerBlob('live-protected-' . bin2hex(random_bytes(4)));
        $this->mkGalaxyWithMedia('gc-protected-' . bin2hex(random_bytes(3)), [
            'image_url' => 'uploads/federation-media/' . $hash,
        ]);

        $report = federation_media_gc_sweep(false);
        $this->assertNotContains($hash, $report['db_orphans'], 'live blob is not an orphan');

        $stmt = getDB()->prepare("SELECT COUNT(*) FROM media_blobs WHERE sha256 = :s");
        $stmt->execute([':s' => $hash]);
        $this->assertSame(1, (int)$stmt->fetchColumn(), 'media_blobs row preserved');
    }

    public function testReportShape(): void
    {
        $report = federation_media_gc_sweep(true);
        foreach (['dry_run', 'live_count', 'disk_scanned', 'too_young', 'disk_orphans', 'disk_orphans_freed_bytes', 'db_orphans', 'min_age_seconds'] as $k) {
            $this->assertArrayHasKey($k, $report, "missing report key: $k");
        }
        $this->assertIsBool($report['dry_run']);
        $this->assertIsInt($report['min_age_seconds']);
    }
}
