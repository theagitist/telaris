<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Idempotency contract for every db_ensure_* helper.
 *
 * Telaris's schema-migration pattern is: each new table or column gets a
 * paired db_ensure_<thing>() function in inc/db.php that runs at startup
 * (or first-use). The contract is that calling it twice in a row produces
 * the same result as calling it once — no exception, no duplicate-column
 * error, no FOREIGN KEY drift.
 *
 * Federation stage 1a adds 10 new ensure helpers. Rather than write a
 * per-helper idempotency test for each (the existing
 * MigrationApiKeysActiveTest and MigrationAutoIncrementTest patterns),
 * this test reflects over inc/db.php at runtime and invokes every
 * discovered db_ensure_* helper twice. Future ensure-style helpers get
 * coverage automatically the moment they land in inc/db.php.
 *
 * Excludes the per-row encryption / lookup-hash helpers that take
 * required arguments (they have their own integration tests). The
 * detection is "no required parameters", which captures the
 * conventional ensure-helper shape: ensure_X(): void with no args.
 */
final class DbEnsureIdempotencyTest extends TestCase
{
    /** @return list<string> */
    private function discoverEnsureHelpers(): array
    {
        $out = [];
        foreach (get_defined_functions()['user'] as $fn) {
            $fn = (string)$fn;
            if (!str_starts_with($fn, 'db_ensure_')) continue;
            try {
                $r = new ReflectionFunction($fn);
            } catch (ReflectionException $_) {
                continue;
            }
            // Skip helpers that require parameters — those have their own
            // integration tests with valid inputs (e.g. per-row PII
            // encryption helpers).
            if ($r->getNumberOfRequiredParameters() !== 0) continue;
            $out[] = $fn;
        }
        sort($out);
        return $out;
    }

    public function testEveryEnsureHelperIsIdempotent(): void
    {
        $helpers = $this->discoverEnsureHelpers();
        $this->assertNotEmpty(
            $helpers,
            'No db_ensure_* helpers discovered. Has inc/db.php failed to load, or has the naming convention changed?'
        );

        $failures = [];
        foreach ($helpers as $fn) {
            try {
                $fn();
                $fn();
            } catch (Throwable $e) {
                $failures[$fn] = $e->getMessage();
            }
        }

        $this->assertSame(
            [],
            $failures,
            "These ensure helpers are NOT idempotent (failed on second invocation):\n  "
                . implode("\n  ", array_map(fn($k, $v) => "{$k}() — {$v}", array_keys($failures), $failures))
        );
    }

    /**
     * Bare floor: we expect at least the helpers known to exist at
     * v6.11.0. If the count drops, something got accidentally removed and
     * the broad sweep above would silently mask it.
     */
    public function testHelperCountFloor(): void
    {
        $helpers = $this->discoverEnsureHelpers();
        // 24 helpers at v6.11.0 (counted from `grep '^function db_ensure_' inc/db.php`).
        // Floor at 22 to absorb a couple of legitimate consolidations without
        // breaking the test, but catch large deletions. Federation stage 1a
        // adds ~10 more, so this floor should rise after 1a ships.
        $this->assertGreaterThanOrEqual(
            22,
            count($helpers),
            sprintf('Discovered only %d ensure helpers; expected >= 15. List: %s', count($helpers), implode(', ', $helpers))
        );
    }
}
