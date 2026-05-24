<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/bridges/_lib.php';

final class BridgesLibTest extends TestCase
{
    public function testValidNamesAreAccepted(): void
    {
        $this->assertTrue(bridges_name_is_valid('mocambos'));
        $this->assertTrue(bridges_name_is_valid('wikipedia'));
        $this->assertTrue(bridges_name_is_valid('a'));
        $this->assertTrue(bridges_name_is_valid('a1'));
        $this->assertTrue(bridges_name_is_valid('foo-bar'));
        $this->assertTrue(bridges_name_is_valid('foo_bar'));
        $this->assertTrue(bridges_name_is_valid('foo-bar_baz-123'));
    }

    public function testEmptyNameRejected(): void
    {
        $this->assertFalse(bridges_name_is_valid(''));
    }

    public function testNameStartingWithDigitRejected(): void
    {
        $this->assertFalse(bridges_name_is_valid('1foo'));
    }

    public function testNameStartingWithSeparatorRejected(): void
    {
        $this->assertFalse(bridges_name_is_valid('-foo'));
        $this->assertFalse(bridges_name_is_valid('_foo'));
    }

    public function testUppercaseRejected(): void
    {
        $this->assertFalse(bridges_name_is_valid('Mocambos'));
        $this->assertFalse(bridges_name_is_valid('MOCAMBOS'));
    }

    public function testPathTraversalRejected(): void
    {
        $this->assertFalse(bridges_name_is_valid('../etc/passwd'));
        $this->assertFalse(bridges_name_is_valid('..'));
        $this->assertFalse(bridges_name_is_valid('foo/bar'));
        $this->assertFalse(bridges_name_is_valid('foo\\bar'));
    }

    public function testDotsAndOtherSpecialsRejected(): void
    {
        $this->assertFalse(bridges_name_is_valid('foo.bar'));
        $this->assertFalse(bridges_name_is_valid('foo bar'));
        $this->assertFalse(bridges_name_is_valid('foo;bar'));
        $this->assertFalse(bridges_name_is_valid("foo\nbar"));
        $this->assertFalse(bridges_name_is_valid('foo:bar'));
    }

    public function testIsActiveMatchesTelarisBridges(): void
    {
        // TELARIS_BRIDGES is [] in this test environment (defaulted by _lib.php).
        // bridges_is_active() should reflect that.
        $this->assertSame([], bridges_active());
        $this->assertFalse(bridges_is_active('mocambos'));
        $this->assertFalse(bridges_is_active('anything'));
        $this->assertFalse(bridges_is_active(''));
    }

    public function testLoadActualMocambosHandler(): void
    {
        $this->assertTrue(bridges_load('mocambos'));
        $this->assertTrue(function_exists('mocambos_handle_request'));
        $this->assertTrue(function_exists('mocambos_run_cli'));
    }

    public function testLoadMissingBridgeReturnsFalse(): void
    {
        $this->assertFalse(bridges_load('nonexistent-bridge-xyz'));
    }

    /**
     * bridges_load() applies the same name validation as the dispatchers do,
     * so a caller that forgets to validate cannot weaponize it as a path
     * traversal primitive.
     */
    public function testLoadRejectsInvalidNames(): void
    {
        $this->assertFalse(bridges_load(''));
        $this->assertFalse(bridges_load('../etc/passwd'));
        $this->assertFalse(bridges_load('foo/bar'));
        $this->assertFalse(bridges_load('foo.bar'));
        $this->assertFalse(bridges_load('Mocambos'));
    }

    /**
     * M-B1 (third-pass audit, v6.10.15) — bridges_cluster_icon_url_for caches
     * its result in-request. With TELARIS_BRIDGES = [] (default), every call
     * short-circuits at bridges_is_active() and returns null. The cache must
     * memoize that null so subsequent calls return identical results without
     * re-entering the lookup path.
     *
     * Invariants pinned:
     *   - First call for an unknown bridge returns null
     *   - Second call returns the same null (cache hit)
     *   - Different bridge names get distinct cache entries
     */
    public function testClusterIconUrlForReturnsNullWhenInactive(): void
    {
        // TELARIS_BRIDGES is [] in this test env, so every name is inactive.
        $this->assertNull(bridges_cluster_icon_url_for('mocambos'));
        $this->assertNull(bridges_cluster_icon_url_for('mocambos'));
        $this->assertNull(bridges_cluster_icon_url_for('nonexistent-bridge'));
    }

    /**
     * Cache distinguishes between bridge names. Two different names get two
     * cache entries; one doesn't poison the other.
     */
    public function testClusterIconUrlForCacheIsKeyedByBridgeName(): void
    {
        $a = bridges_cluster_icon_url_for('aitest-cache-a-' . bin2hex(random_bytes(2)));
        $b = bridges_cluster_icon_url_for('aitest-cache-b-' . bin2hex(random_bytes(2)));
        $this->assertNull($a);
        $this->assertNull($b);
    }
}
