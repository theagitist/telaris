<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5e visitor-side mirror parser.
 *
 * federation_parse_import_source() must return null for everything except
 * federation mirrors. Mocambos bridge writes its own JSON into the same
 * column (kind != "federation"); a malformed or empty value must not throw.
 *
 * Spec: Stage 5 galaxy publish design (5e).
 */
final class ParseImportSourceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_mirror.php';
    }

    public function testParsesFederationMirror(): void
    {
        $json = json_encode([
            'kind' => 'federation',
            'origin_host' => 'peer.example.invalid',
            'remote_slug' => 'coastal-plants',
            'sequence' => 3,
            'content_hash' => str_repeat('a', 64),
        ]);
        $out = federation_parse_import_source($json);
        $this->assertNotNull($out);
        $this->assertSame('federation', $out['kind']);
        $this->assertSame('peer.example.invalid', $out['origin_host']);
        $this->assertSame('coastal-plants', $out['remote_slug']);
        $this->assertSame(3, $out['sequence']);
    }

    public function testReturnsNullForNullEmptyAndAuthored(): void
    {
        $this->assertNull(federation_parse_import_source(null));
        $this->assertNull(federation_parse_import_source(''));
    }

    public function testReturnsNullForMocambosBridgeShape(): void
    {
        // Mocambos bridge writes its own JSON into import_source; the visitor
        // mirror parser must not claim that as a federation provenance.
        $json = json_encode(['source' => 'mocambos', 'api_base' => 'https://m', 'galaxia_slug' => 'g']);
        $this->assertNull(federation_parse_import_source($json));
    }

    public function testReturnsNullForMalformedJson(): void
    {
        $this->assertNull(federation_parse_import_source('{not valid'));
        $this->assertNull(federation_parse_import_source('[]'));
        $this->assertNull(federation_parse_import_source('"just a string"'));
    }

    public function testReturnsNullWhenRequiredFieldsMissing(): void
    {
        // kind=federation but no origin_host:
        $this->assertNull(federation_parse_import_source(json_encode(['kind' => 'federation', 'remote_slug' => 's'])));
        // kind=federation but no remote_slug:
        $this->assertNull(federation_parse_import_source(json_encode(['kind' => 'federation', 'origin_host' => 'h.example'])));
    }
}
