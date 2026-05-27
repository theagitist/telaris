<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5c envelope JSON Schema.
 *
 * No JSON Schema validator ships in vendor, so this does not run full schema
 * validation. Instead it guards the two things that actually rot: the schema
 * file must be well-formed Draft 2020-12 with the right $id/title, and its
 * declared top-level properties + required list must stay in lockstep with the
 * real federation_galaxy_envelope_payload() shape. If someone adds a payload
 * field without versioning the schema (or vice versa), this fails.
 *
 * Spec: Stage 5 galaxy publish design (5b/5c).
 */
final class EnvelopeSchemaTest extends TestCase
{
    private const SCHEMA_FILE = __DIR__ . '/../../../inc/federation/schemas/envelope-1.0.json';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_envelope.php';
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        $raw = file_get_contents(self::SCHEMA_FILE);
        $this->assertNotFalse($raw, 'schema file is readable');
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'schema is valid JSON');
        return $decoded;
    }

    public function testSchemaMetadata(): void
    {
        $s = $this->schema();
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $s['$schema']);
        $this->assertSame('https://www.telaris.ca/api/pluriverse/schema/envelope-1.0.json', $s['$id']);
        $this->assertSame('object', $s['type']);
        $this->assertFalse($s['additionalProperties'], 'top level is closed');
        $this->assertSame('1.0', $s['properties']['protocol_version']['const']);
    }

    public function testSchemaMatchesPayloadShape(): void
    {
        $payload = federation_galaxy_envelope_payload(
            ['name' => 'g', 'tagline' => 't', 'theme' => 'cosmic'],
            [['name' => 'n', 'node_type' => 'object', 'keywords' => [], 'media' => []]],
            [['from' => 'a', 'to' => 'b']],
            'starmaps.polivoxia.ca',
            'a-slug',
            1,
            gmdate('c')
        );
        $s = $this->schema();
        $declared = array_keys($s['properties']);

        // Every payload key is declared (closed top level would otherwise reject it).
        foreach (array_keys($payload) as $key) {
            $this->assertContains($key, $declared, "payload key '$key' is declared in the schema");
        }
        // Every required key is actually emitted by the builder.
        foreach ($s['required'] as $req) {
            $this->assertArrayHasKey($req, $payload, "required key '$req' is present in the built payload");
        }
        // The closed top level declares nothing the builder never emits.
        foreach ($declared as $key) {
            $this->assertArrayHasKey($key, $payload, "schema property '$key' exists in the built payload");
        }
    }

    public function testNodeAndMediaShapesAreOpen(): void
    {
        $s = $this->schema();
        $this->assertTrue($s['$defs']['node']['additionalProperties'], 'node shape stays open during 5c-media');
        $this->assertTrue($s['$defs']['media_ref']['additionalProperties'], 'media ref stays open during 5c-media');
        $this->assertFalse($s['$defs']['keyword_relation']['additionalProperties'], 'keyword relation is closed');
    }
}
