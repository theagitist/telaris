<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/cron.php';

/**
 * Pure-string parsing test for cron_strip_block().
 *
 * The function removes the marker-bracketed scheduler block from a crontab
 * payload and must leave every other byte untouched. Bugs here would corrupt
 * the user's other crontab lines, which is the worst-case failure mode of
 * the snapshot scheduler.
 *
 * Multi-tenant invariants tested below:
 *   - Site-tagged blocks for the *current* site → always stripped.
 *   - Site-tagged blocks for a *different* site → preserved.
 *   - Legacy (pre-site-tag) blocks → stripped only if the body references
 *     the current site's script path, else preserved.
 */
final class CronStripBlockTest extends TestCase
{
    /** @var string */
    private $start;
    /** @var string */
    private $end;
    /** @var string */
    private $myScript;

    protected function setUp(): void
    {
        $this->start = cron_marker_start();
        $this->end = cron_marker_end();
        $this->myScript = cron_script_path();
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame('', cron_strip_block(''));
    }

    public function testCrontabWithoutMarkerIsUnchanged(): void
    {
        $cron = "MAILTO=root\n0 4 * * * /usr/bin/backup\n";
        $expected = "MAILTO=root\n0 4 * * * /usr/bin/backup";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testBlockAloneRemoved(): void
    {
        $cron = $this->start . "\n*/15 * * * * /usr/bin/php /run.php\n" . $this->end . "\n";
        $this->assertSame('', cron_strip_block($cron));
    }

    public function testBlockAtStartPreservesTail(): void
    {
        $cron = $this->start . "\n*/15 * * * * /run.php\n" . $this->end . "\n"
              . "0 4 * * * /usr/bin/backup\n"
              . "MAILTO=root\n";
        $expected = "0 4 * * * /usr/bin/backup\nMAILTO=root";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testBlockAtEndPreservesHead(): void
    {
        $cron = "MAILTO=root\n"
              . "0 4 * * * /usr/bin/backup\n"
              . $this->start . "\n*/15 * * * * /run.php\n" . $this->end . "\n";
        $expected = "MAILTO=root\n0 4 * * * /usr/bin/backup";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testBlockInMiddlePreservesBeforeAndAfter(): void
    {
        $cron = "MAILTO=root\n"
              . $this->start . "\n# managed\n*/15 * * * * /run.php\n" . $this->end . "\n"
              . "0 4 * * * /usr/bin/backup\n";
        $expected = "MAILTO=root\n0 4 * * * /usr/bin/backup";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testMultipleLinesInsideBlockAllRemoved(): void
    {
        $cron = "before\n"
              . $this->start . "\nline1\nline2\nline3\nline4\n" . $this->end . "\n"
              . "after\n";
        $expected = "before\nafter";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testTrailingBlankLinesTrimmed(): void
    {
        $cron = "0 4 * * * /usr/bin/backup\n\n\n";
        $this->assertSame("0 4 * * * /usr/bin/backup", cron_strip_block($cron));
    }

    public function testStripIsIdempotent(): void
    {
        $cron = "MAILTO=root\n"
              . $this->start . "\n*/15 * * * * /run.php\n" . $this->end . "\n"
              . "0 4 * * * /usr/bin/backup\n";
        $once = cron_strip_block($cron);
        $twice = cron_strip_block($once);
        $this->assertSame($once, $twice);
    }

    public function testCrlfLineEndingsHandled(): void
    {
        $cron = "before\r\n"
              . $this->start . "\r\n*/15 * * * * /run.php\r\n" . $this->end . "\r\n"
              . "after\r\n";
        $result = cron_strip_block($cron);
        $this->assertStringContainsString('before', $result);
        $this->assertStringContainsString('after', $result);
        $this->assertStringNotContainsString('/run.php', $result);
    }

    public function testForeignSiteBlockIsPreserved(): void
    {
        // A block tagged for a different site (different marker text) must
        // survive — the whole point of site-tagging is multi-tenant isolation.
        $foreignStart = '# >>> telaris snapshot scheduler: other.example.com >>>';
        $foreignEnd   = '# <<< telaris snapshot scheduler: other.example.com <<<';
        $cron = "MAILTO=root\n"
              . $foreignStart . "\n*/15 * * * * /usr/bin/php /var/www/other.example.com/admin/cli/snapshot_run_scheduled.php\n" . $foreignEnd . "\n"
              . $this->start . "\n*/15 * * * * /run.php\n" . $this->end . "\n";
        $result = cron_strip_block($cron);
        $this->assertStringContainsString($foreignStart, $result);
        $this->assertStringContainsString('/var/www/other.example.com', $result);
        $this->assertStringNotContainsString($this->start, $result);
    }

    public function testLegacyBlockWithThisSitePathIsStripped(): void
    {
        // A pre-site-tag block that references THIS site's script is "ours"
        // and should be cleaned up on the next install (migration path).
        $cron = "MAILTO=root\n"
              . CRON_LEGACY_MARKER_START . "\n*/15 * * * * /usr/bin/php " . $this->myScript . " >> /tmp/log\n" . CRON_LEGACY_MARKER_END . "\n"
              . "0 4 * * * /usr/bin/backup\n";
        $result = cron_strip_block($cron);
        $this->assertStringNotContainsString(CRON_LEGACY_MARKER_START, $result);
        $this->assertStringNotContainsString($this->myScript, $result);
        $this->assertStringContainsString('/usr/bin/backup', $result);
    }

    public function testLegacyBlockForDifferentSiteIsPreserved(): void
    {
        // A legacy block whose body references a DIFFERENT site's script must
        // be preserved — it's a sibling site's installation, not ours.
        $otherScript = '/var/www/other.example.com/admin/cli/snapshot_run_scheduled.php';
        $cron = "MAILTO=root\n"
              . CRON_LEGACY_MARKER_START . "\n*/15 * * * * /usr/bin/php " . $otherScript . "\n" . CRON_LEGACY_MARKER_END . "\n";
        $result = cron_strip_block($cron);
        $this->assertStringContainsString(CRON_LEGACY_MARKER_START, $result);
        $this->assertStringContainsString($otherScript, $result);
    }
}
