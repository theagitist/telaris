<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Mocambos bridge SSRF defence.
 *
 * Exercises:
 *   - _mocambos_ip_is_public for the canonical private / reserved ranges and
 *     the public-IP positive case.
 *   - _mocambos_validate_safe_url for URL-structure rejections and the
 *     allow-list gate; IP-literal hosts confirm the gate runs before any
 *     other check so an attacker cannot bypass it with 127.0.0.1.
 *
 * DNS-resolution paths are not exercised here (they depend on the host's
 * resolver). The fetch-time gate in _mocambos_fetch_json and the redirect
 * disable in download.php cover the runtime case.
 */
final class MocambosSafeUrlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../inc/bridges/mocambos/handler.php';
    }

    public function testIpIsPublicAcceptsPublicV4(): void
    {
        $this->assertTrue(_mocambos_ip_is_public('8.8.8.8'));
        $this->assertTrue(_mocambos_ip_is_public('1.1.1.1'));
    }

    public function testIpIsPublicRejectsPrivateRanges(): void
    {
        $this->assertFalse(_mocambos_ip_is_public('10.0.0.1'));
        $this->assertFalse(_mocambos_ip_is_public('172.16.0.1'));
        $this->assertFalse(_mocambos_ip_is_public('192.168.1.1'));
        $this->assertFalse(_mocambos_ip_is_public('fc00::1'));
    }

    public function testIpIsPublicRejectsLoopbackAndLinkLocal(): void
    {
        $this->assertFalse(_mocambos_ip_is_public('127.0.0.1'));
        $this->assertFalse(_mocambos_ip_is_public('169.254.169.254'));
        $this->assertFalse(_mocambos_ip_is_public('0.0.0.0'));
        $this->assertFalse(_mocambos_ip_is_public('::1'));
        $this->assertFalse(_mocambos_ip_is_public('fe80::1'));
    }

    public function testIpIsPublicRejectsGarbage(): void
    {
        $this->assertFalse(_mocambos_ip_is_public(''));
        $this->assertFalse(_mocambos_ip_is_public('not-an-ip'));
        $this->assertFalse(_mocambos_ip_is_public('999.999.999.999'));
    }

    public function testValidateRejectsEmptyUrl(): void
    {
        $this->assertNotNull(_mocambos_validate_safe_url(''));
    }

    public function testValidateRejectsMalformedUrl(): void
    {
        $this->assertNotNull(_mocambos_validate_safe_url('not a url'));
        $this->assertNotNull(_mocambos_validate_safe_url('htp://x'));
    }

    public function testValidateRejectsWrongScheme(): void
    {
        $this->assertNotNull(_mocambos_validate_safe_url('file:///etc/passwd'));
        $this->assertNotNull(_mocambos_validate_safe_url('gopher://example.com/'));
        $this->assertNotNull(_mocambos_validate_safe_url('javascript:alert(1)'));
    }

    public function testValidateRejectsHostNotOnAllowList(): void
    {
        $this->assertNotNull(_mocambos_validate_safe_url('https://evil.com/api/v2'));
        $this->assertNotNull(_mocambos_validate_safe_url('https://mocambos.net.evil.com/api/v2'));
    }

    public function testValidateRejectsIpLiteralsViaAllowList(): void
    {
        // IP literals always fail the allow-list (they cannot match
        // ".mocambos.net" / ".baobaxia.net" or the bare suffixes). This is the
        // primary defence against SSRF-via-IP-literal attempts.
        $this->assertNotNull(_mocambos_validate_safe_url('http://127.0.0.1/'));
        $this->assertNotNull(_mocambos_validate_safe_url('http://169.254.169.254/latest/meta-data/'));
        $this->assertNotNull(_mocambos_validate_safe_url('http://192.168.1.1/'));
        $this->assertNotNull(_mocambos_validate_safe_url('http://[::1]/'));
    }

    public function testValidateRejectsSubdomainTrickery(): void
    {
        // "mocambos.net.attacker.tld" must NOT match the "mocambos.net" suffix
        // (suffix match is on ".mocambos.net", with a leading dot, anchoring
        // the boundary).
        $this->assertNotNull(_mocambos_validate_safe_url('https://mocambos.net.attacker.tld/api/v2'));
        $this->assertNotNull(_mocambos_validate_safe_url('https://fakebaobaxia.net/api/v2'));
    }
}
