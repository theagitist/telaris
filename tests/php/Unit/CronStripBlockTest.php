<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/cron.php';

/**
 * Pure-string parsing test for cron_strip_block().
 *
 * The function removes the marker-bracketed scheduler block from a crontab
 * payload and must leave every other byte untouched. Bugs here would cause
 * the cron install/uninstall code to corrupt the user's other crontab lines,
 * which is the worst-case failure mode of the snapshot scheduler.
 */
final class CronStripBlockTest extends TestCase
{
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
        $cron = CRON_MARKER_START . "\n*/15 * * * * /usr/bin/php /run.php\n" . CRON_MARKER_END . "\n";
        $this->assertSame('', cron_strip_block($cron));
    }

    public function testBlockAtStartPreservesTail(): void
    {
        $cron = CRON_MARKER_START . "\n*/15 * * * * /run.php\n" . CRON_MARKER_END . "\n"
              . "0 4 * * * /usr/bin/backup\n"
              . "MAILTO=root\n";
        $expected = "0 4 * * * /usr/bin/backup\nMAILTO=root";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testBlockAtEndPreservesHead(): void
    {
        $cron = "MAILTO=root\n"
              . "0 4 * * * /usr/bin/backup\n"
              . CRON_MARKER_START . "\n*/15 * * * * /run.php\n" . CRON_MARKER_END . "\n";
        $expected = "MAILTO=root\n0 4 * * * /usr/bin/backup";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testBlockInMiddlePreservesBeforeAndAfter(): void
    {
        $cron = "MAILTO=root\n"
              . CRON_MARKER_START . "\n# managed\n*/15 * * * * /run.php\n" . CRON_MARKER_END . "\n"
              . "0 4 * * * /usr/bin/backup\n";
        $expected = "MAILTO=root\n0 4 * * * /usr/bin/backup";
        $this->assertSame($expected, cron_strip_block($cron));
    }

    public function testMultipleLinesInsideBlockAllRemoved(): void
    {
        $cron = "before\n"
              . CRON_MARKER_START . "\nline1\nline2\nline3\nline4\n" . CRON_MARKER_END . "\n"
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
              . CRON_MARKER_START . "\n*/15 * * * * /run.php\n" . CRON_MARKER_END . "\n"
              . "0 4 * * * /usr/bin/backup\n";
        $once = cron_strip_block($cron);
        $twice = cron_strip_block($once);
        $this->assertSame($once, $twice);
    }

    public function testCrlfLineEndingsHandled(): void
    {
        $cron = "before\r\n"
              . CRON_MARKER_START . "\r\n*/15 * * * * /run.php\r\n" . CRON_MARKER_END . "\r\n"
              . "after\r\n";
        $result = cron_strip_block($cron);
        $this->assertStringContainsString('before', $result);
        $this->assertStringContainsString('after', $result);
        $this->assertStringNotContainsString('telaris snapshot scheduler', $result);
        $this->assertStringNotContainsString('/run.php', $result);
    }
}
