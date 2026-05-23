<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/backup.php';

/**
 * Regression test for the backup-integrity hardening (audit v6.10.8 / H3).
 *
 * Invariants pinned:
 *  - backup_write_to_file returns a sha256 of the gzipped on-disk bytes.
 *  - backup_decode_file with the correct expected hash succeeds.
 *  - backup_decode_file with a wrong expected hash throws.
 *  - backup_decode_file refuses non-gzip input (no silent JSON fallback).
 *  - backup_decode_file refuses missing / zero format_version.
 */
final class BackupIntegrityTest extends TestCase
{
    private ?string $dumpPath = null;
    private PDO $pdo;
    private int $sourceId = 0;
    private string $sourceSlug = '';

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanupAitestGalaxies();
        $this->sourceSlug = 'aitest-integrity-' . bin2hex(random_bytes(4));
        $this->sourceId = db_create_constellation('Aitest Integrity', 'h3', $this->sourceSlug, 'cosmic');
    }

    protected function tearDown(): void
    {
        $this->cleanupAitestGalaxies();
        if ($this->dumpPath !== null && file_exists($this->dumpPath)) {
            @unlink($this->dumpPath);
        }
    }

    public function testWriteReturnsSha256OfOnDiskBytes(): void
    {
        $dump = backup_build_dump([
            'galaxy_ids' => [$this->sourceId],
            'include_users' => false,
            'media_mode' => 'none',
            'include_galaxies' => true,
        ]);
        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-integrity-') . '.telaris-backup';
        $written = backup_write_to_file($dump, $this->dumpPath);

        $this->assertArrayHasKey('sha256', $written);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$written['sha256']);

        $onDisk = (string)file_get_contents($this->dumpPath);
        $this->assertSame(
            $written['sha256'],
            hash('sha256', $onDisk),
            'sha256 returned by backup_write_to_file MUST equal hash of the bytes that landed on disk.'
        );
    }

    public function testCorrectHashAccepted(): void
    {
        $dump = backup_build_dump([
            'galaxy_ids' => [$this->sourceId],
            'include_users' => false,
            'media_mode' => 'none',
            'include_galaxies' => true,
        ]);
        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-integrity-') . '.telaris-backup';
        $written = backup_write_to_file($dump, $this->dumpPath);

        $decoded = backup_decode_file($this->dumpPath, (string)$written['sha256']);
        $this->assertSame(BACKUP_FORMAT, $decoded['format']);
    }

    public function testWrongHashRejected(): void
    {
        $dump = backup_build_dump([
            'galaxy_ids' => [$this->sourceId],
            'include_users' => false,
            'media_mode' => 'none',
            'include_galaxies' => true,
        ]);
        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-integrity-') . '.telaris-backup';
        backup_write_to_file($dump, $this->dumpPath);

        $bogus = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sha256 mismatch/');
        backup_decode_file($this->dumpPath, $bogus);
    }

    public function testNonGzipInputRejected(): void
    {
        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-integrity-') . '.telaris-backup';
        // Plain JSON pretending to be a backup. Pre-fix, the silent gzdecode
        // fallback would have accepted this. Post-fix, it MUST be refused.
        $plain = json_encode([
            'format' => BACKUP_FORMAT,
            'format_version' => BACKUP_FORMAT_VERSION,
            'galaxies' => [],
        ]);
        file_put_contents($this->dumpPath, (string)$plain);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/gzip/');
        backup_decode_file($this->dumpPath);
    }

    public function testZeroFormatVersionRejected(): void
    {
        $this->dumpPath = tempnam(sys_get_temp_dir(), 'aitest-integrity-') . '.telaris-backup';
        $payload = json_encode([
            'format' => BACKUP_FORMAT,
            'format_version' => 0,
            'galaxies' => [],
        ]);
        $gz = gzencode((string)$payload, 6);
        file_put_contents($this->dumpPath, (string)$gz);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/format version/i');
        backup_decode_file($this->dumpPath);
    }

    private function cleanupAitestGalaxies(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-integrity-%'");
        if ($stmt === false) return;
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo->prepare("DELETE FROM nodes WHERE constellation_id = ?")->execute([(int)$id]);
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$id]);
        }
    }
}
