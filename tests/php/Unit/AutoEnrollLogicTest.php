<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/enroll-helpers.php';

/**
 * Unit tests for the database-free auto-enroll decision logic
 * (auto_enroll_compute_open + auto_enroll_normalize_config).
 */
final class AutoEnrollLogicTest extends TestCase
{
    public function testDisabledIsAlwaysClosed(): void
    {
        $this->assertFalse(auto_enroll_compute_open(false, false, 0, 0));
        $this->assertFalse(auto_enroll_compute_open(false, true, 5, 0));
    }

    public function testEnabledNoCapIsOpen(): void
    {
        $this->assertTrue(auto_enroll_compute_open(true, false, 0, 0));
        $this->assertTrue(auto_enroll_compute_open(true, false, 0, 1000));
    }

    public function testNonPositiveCapTreatedAsNoCap(): void
    {
        $this->assertTrue(auto_enroll_compute_open(true, true, 0, 50));
        $this->assertTrue(auto_enroll_compute_open(true, true, -3, 50));
    }

    public function testUnderCapIsOpen(): void
    {
        $this->assertTrue(auto_enroll_compute_open(true, true, 5, 0));
        $this->assertTrue(auto_enroll_compute_open(true, true, 5, 4));
    }

    public function testAtOrOverCapIsClosed(): void
    {
        $this->assertFalse(auto_enroll_compute_open(true, true, 5, 5));
        $this->assertFalse(auto_enroll_compute_open(true, true, 5, 6));
    }

    public function testNormalizeDefaultsAreSafeAndClosed(): void
    {
        $cfg = auto_enroll_normalize_config([]);
        $this->assertFalse($cfg['enabled']);
        $this->assertFalse($cfg['create_personal_galaxy']);
        $this->assertSame(ENROLL_NAMING_DEFAULT, $cfg['naming_convention']);
        $this->assertSame(ENROLL_ACCESS_DEFAULT, $cfg['access_level']);
        $this->assertSame([], $cfg['domains']);
        $this->assertSame([], $cfg['galaxy_ids']);
        $this->assertFalse($cfg['cap_enabled']);
        $this->assertSame(0, $cfg['cap']);
    }

    public function testNormalizeUnknownEnumsFallBack(): void
    {
        $cfg = auto_enroll_normalize_config([
            'naming_convention' => 'totally_invalid',
            'access_level' => 'sudo',
        ]);
        $this->assertSame(ENROLL_NAMING_DEFAULT, $cfg['naming_convention']);
        $this->assertSame(ENROLL_ACCESS_DEFAULT, $cfg['access_level']);
    }

    public function testNormalizeCapCoercion(): void
    {
        // cap_enabled but non-positive cap collapses to "no cap".
        $cfg = auto_enroll_normalize_config(['cap_enabled' => true, 'cap' => 0]);
        $this->assertFalse($cfg['cap_enabled']);
        $this->assertSame(0, $cfg['cap']);

        $cfg = auto_enroll_normalize_config(['cap_enabled' => true, 'cap' => -7]);
        $this->assertFalse($cfg['cap_enabled']);
        $this->assertSame(0, $cfg['cap']);

        // A real cap survives.
        $cfg = auto_enroll_normalize_config(['cap_enabled' => true, 'cap' => '12']);
        $this->assertTrue($cfg['cap_enabled']);
        $this->assertSame(12, $cfg['cap']);

        // cap value with cap disabled is forced to 0.
        $cfg = auto_enroll_normalize_config(['cap_enabled' => false, 'cap' => 9]);
        $this->assertFalse($cfg['cap_enabled']);
        $this->assertSame(0, $cfg['cap']);
    }

    public function testNormalizeGalaxyIdsArePositiveUniqueSorted(): void
    {
        $cfg = auto_enroll_normalize_config(['galaxy_ids' => ['3', 3, 1, 0, -2, '2', 'x']]);
        $this->assertSame([1, 2, 3], $cfg['galaxy_ids']);
    }

    public function testNormalizeTruthyCoercion(): void
    {
        $cfg = auto_enroll_normalize_config([
            'enabled' => '1',
            'create_personal_galaxy' => 'on',
        ]);
        $this->assertTrue($cfg['enabled']);
        $this->assertTrue($cfg['create_personal_galaxy']);
    }
}
