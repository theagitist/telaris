<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/enroll-helpers.php';

/**
 * Unit tests for the database-free enroll helpers
 * (enroll_normalize_domains, enroll_email_domain_allowed, enroll_personal_galaxy_name).
 */
final class EnrollHelpersTest extends TestCase
{
    public function testEmptyAllowlistAllowsAnyDomain(): void
    {
        $this->assertTrue(enroll_email_domain_allowed('anyone@example.com', []));
        $this->assertTrue(enroll_email_domain_allowed('weird@sub.domain.org', []));
    }

    public function testExactDomainMatchCaseInsensitive(): void
    {
        $this->assertTrue(enroll_email_domain_allowed('Sam@UBC.ca', ['ubc.ca']));
        $this->assertTrue(enroll_email_domain_allowed('sam@ubc.ca', ['ubc.ca']));
        $this->assertFalse(enroll_email_domain_allowed('sam@gmail.com', ['ubc.ca']));
    }

    public function testSubdomainIsAllowedButLookalikeIsNot(): void
    {
        $this->assertTrue(enroll_email_domain_allowed('sam@students.ubc.ca', ['ubc.ca']));
        $this->assertFalse(enroll_email_domain_allowed('sam@notubc.ca', ['ubc.ca']));
    }

    public function testMalformedEmailRejectedWhenAllowlistExists(): void
    {
        $this->assertFalse(enroll_email_domain_allowed('no-at-sign', ['ubc.ca']));
        $this->assertFalse(enroll_email_domain_allowed('trailing@', ['ubc.ca']));
    }

    public function testNormalizeDomainsFromString(): void
    {
        $this->assertSame(
            ['ubc.ca', 'gmail.com'],
            enroll_normalize_domains('UBC.ca, @gmail.com ; bogus')
        );
    }

    public function testNormalizeDomainsFromArrayDedupes(): void
    {
        $this->assertSame(
            ['ubc.ca'],
            enroll_normalize_domains(['ubc.ca', 'UBC.CA', '  ubc.ca  ', ''])
        );
    }

    public function testNormalizeDomainsDropsImplausible(): void
    {
        $this->assertSame([], enroll_normalize_domains('localhost'));
        $this->assertSame([], enroll_normalize_domains('no spaces here'));
    }

    public function testPersonalGalaxyNameFullEmail(): void
    {
        $this->assertSame('andrew@example.com', enroll_personal_galaxy_name('full_email', 'andrew@example.com', 'Andrew'));
    }

    public function testPersonalGalaxyNameEmailUsername(): void
    {
        $this->assertSame('andrew', enroll_personal_galaxy_name('email_username', 'andrew@example.com', 'Andrew'));
    }

    public function testPersonalGalaxyNameFirstNamePossessive(): void
    {
        $this->assertSame("Andrew's galaxy", enroll_personal_galaxy_name('first_name', 'andrew@example.com', 'Andrew'));
        // The possessive template is injectable for localization.
        $this->assertSame('Galaxia de Andrew', enroll_personal_galaxy_name('first_name', 'a@b.com', 'Andrew', 'Galaxia de %s'));
    }

    public function testPersonalGalaxyNameUserChoiceIsDeferred(): void
    {
        $this->assertNull(enroll_personal_galaxy_name('user_choice', 'andrew@example.com', 'Andrew'));
    }

    public function testPersonalGalaxyNameUnknownConventionIsNull(): void
    {
        $this->assertNull(enroll_personal_galaxy_name('nonsense', 'andrew@example.com', 'Andrew'));
    }

    public function testPersonalGalaxyNameEmptyInputsAreNull(): void
    {
        $this->assertNull(enroll_personal_galaxy_name('first_name', 'a@b.com', '   '));
        $this->assertNull(enroll_personal_galaxy_name('email_username', '@b.com', 'X'));
        $this->assertNull(enroll_personal_galaxy_name('full_email', '   ', 'X'));
    }
}
