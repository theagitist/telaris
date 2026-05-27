<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5c-media content-addressable store.
 *
 * The pure / read-only paths run as the CLI user: external and unresolvable
 * URLs are not content-addressed, the hash validator is strict, and lookups of
 * malformed or unknown hashes return null. The copy + media_blobs registration
 * happy path writes under UPLOAD_DIR (owned by www-data), so it is exercised
 * live as www-data, not here, the same way the envelope/retraction signing
 * paths are.
 *
 * Spec: Stage 5 galaxy publish design (5c-media).
 */
final class MediaStoreTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/media_store.php';
    }

    public function testExternalUrlIsNotContentAddressed(): void
    {
        $this->assertNull(federation_media_register_upload('https://example.com/img.jpg'));
        $this->assertNull(federation_media_register_upload('data:image/png;base64,AAAA'));
        $this->assertNull(federation_media_register_upload(''));
    }

    public function testUnresolvableLocalUploadIsNull(): void
    {
        // Path-traversal and a non-existent file both resolve to null.
        $this->assertNull(federation_media_register_upload('uploads/../../etc/passwd'));
        $this->assertNull(federation_media_register_upload('uploads/does-not-exist-' . bin2hex(random_bytes(6)) . '.jpg'));
    }

    public function testSha256Validator(): void
    {
        $this->assertTrue(federation_media_is_sha256(str_repeat('a', 64)));
        $this->assertTrue(federation_media_is_sha256(hash('sha256', 'x')));
        $this->assertFalse(federation_media_is_sha256(str_repeat('a', 63)));
        $this->assertFalse(federation_media_is_sha256(str_repeat('A', 64)), 'uppercase hex is rejected');
        $this->assertFalse(federation_media_is_sha256('../etc/passwd'));
        $this->assertFalse(federation_media_is_sha256(''));
    }

    public function testLookupRejectsMalformedAndUnknown(): void
    {
        $this->assertNull(federation_media_lookup('not-a-hash'));
        $this->assertNull(federation_media_lookup(str_repeat('f', 64)), 'a well-formed but unknown hash is null');
    }

    public function testMediaDirIsUnderUploadDir(): void
    {
        if (!defined('UPLOAD_DIR')) {
            $this->markTestSkipped('UPLOAD_DIR not defined in this environment');
        }
        $dir = federation_media_dir();
        $this->assertStringStartsWith(rtrim(UPLOAD_DIR, '/'), $dir);
        $this->assertStringEndsWith('/federation-media', $dir);
    }
}
