<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * composer.json + composer.lock health check.
 *
 * Federation stage 1d / 1e add two new composer packages
 * (zircote/swagger-php, web-token/jwt-framework). This test gives a
 * pre-merge signal that:
 *
 *   - composer.json is syntactically valid (`composer validate --strict`)
 *   - composer.lock matches composer.json (no out-of-sync drift)
 *   - no known security advisories in the locked deps (`composer audit`)
 *
 * Failure here means the dep tree is in a state where production
 * `composer install` would warn or fail.
 *
 * Test skips with a clear message if the composer binary is unreachable
 * (this is expected on hosts that don't keep composer in $PATH); on
 * starmaps.polivoxia.ca composer is /usr/local/bin/composer per the
 * project's setup-host pattern.
 */
final class ComposerHealthTest extends TestCase
{
    private string $projectRoot;
    private string $composerBin;

    protected function setUp(): void
    {
        $this->projectRoot = realpath(__DIR__ . '/../../..') ?: '';
        $this->composerBin = $this->locateComposer();
        if ($this->composerBin === '') {
            $this->markTestSkipped('composer binary not found in PATH; skipping composer health checks');
        }
    }

    public function testComposerValidateStrict(): void
    {
        [$code, $output] = $this->runComposer(['validate', '--strict', '--no-check-publish']);
        $this->assertSame(
            0,
            $code,
            "composer validate --strict returned {$code}:\n{$output}"
        );
    }

    public function testComposerAuditClean(): void
    {
        // composer audit exits non-zero on advisories. Exit 0 = clean; > 0 =
        // advisories present (or remote registry was unreachable, which we
        // distinguish below).
        [$code, $output] = $this->runComposer(['audit', '--no-interaction', '--format=plain']);
        if (str_contains($output, 'Failed to download') || str_contains($output, 'Could not fetch')) {
            $this->markTestSkipped("composer audit could not reach the advisories registry:\n{$output}");
        }
        $this->assertSame(
            0,
            $code,
            "composer audit found advisories:\n{$output}"
        );
    }

    private function locateComposer(): string
    {
        // Try the canonical path first, then fall back to PATH.
        foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $cand) {
            if (is_executable($cand)) return $cand;
        }
        $which = trim((string)@shell_exec('command -v composer 2>/dev/null'));
        return $which !== '' && is_executable($which) ? $which : '';
    }

    /**
     * @param list<string> $args
     * @return array{0:int, 1:string}
     */
    private function runComposer(array $args): array
    {
        $cmd = 'cd ' . escapeshellarg($this->projectRoot)
            . ' && ' . escapeshellarg($this->composerBin)
            . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' 2>&1';
        $output = '';
        $code = 1;
        $h = popen($cmd, 'r');
        if ($h === false) {
            return [1, 'popen failed'];
        }
        while (!feof($h)) {
            $chunk = fread($h, 4096);
            if ($chunk === false) break;
            $output .= $chunk;
        }
        $code = pclose($h);
        // pclose returns the wait status; on POSIX the exit code is in the
        // high byte. Recover it via `pcntl_*` if available, otherwise rely
        // on the convention that 0 == 0 either way.
        if (function_exists('pcntl_wifexited') && pcntl_wifexited($code)) {
            $code = pcntl_wexitstatus($code);
        }
        return [$code, $output];
    }
}
