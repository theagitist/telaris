<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for db_format_nodes_bulk() — verifies all expected fields
 * are present in the formatted output, including image_attribution.
 */
final class FormatNodesBulkTest extends TestCase
{
    private function makeNode(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Test Node',
            'description' => 'A test node',
            'url' => 'https://example.com',
            'image_url' => 'uploads/1/1/image.jpg',
            'image_attribution' => 'Photo by Test Author',
            'icon_url' => null,
            'embed_code' => null,
            'audio_url' => null,
            'audio_autoplay' => 1,
            'audio_loop' => 0,
            'video_url' => null,
            'video_autoplay' => 1,
            'animation' => '{"radius":5,"theta":0,"phi":0,"speed":0.0025,"phase":0}',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'constellation_id' => 1,
            'constellation_name' => 'Test Constellation',
            'node_type' => 'object',
            'target_constellation_id' => null,
            'is_accentuated' => 0,
            'show_keywords' => 0,
            'mucua_name' => null,
            'media_type' => null,
            'source_created_at' => null,
        ], $overrides);
    }

    public function testOutputContainsImageAttribution(): void
    {
        $nodes = [$this->makeNode(['image_attribution' => 'Photo by Someone'])];
        $result = db_format_nodes_bulk($nodes);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('image_attribution', $result[0]);
        $this->assertSame('Photo by Someone', $result[0]['image_attribution']);
    }

    public function testImageAttributionNullWhenEmpty(): void
    {
        $nodes = [$this->makeNode(['image_attribution' => ''])];
        $result = db_format_nodes_bulk($nodes);

        $this->assertArrayHasKey('image_attribution', $result[0]);
        $this->assertNull($result[0]['image_attribution']);
    }

    public function testImageAttributionNullWhenMissing(): void
    {
        $node = $this->makeNode();
        unset($node['image_attribution']);
        $result = db_format_nodes_bulk([$node]);

        $this->assertArrayHasKey('image_attribution', $result[0]);
        $this->assertNull($result[0]['image_attribution']);
    }

    public function testAllExpectedKeysPresent(): void
    {
        $result = db_format_nodes_bulk([$this->makeNode()]);
        $node = $result[0];

        $expectedKeys = [
            'id', 'name', 'description', 'url',
            'image_url', 'image_attribution', 'icon_url',
            'embed_code', 'audio_url', 'audio_autoplay', 'audio_loop',
            'video_url', 'video_autoplay',
            'keywords', 'animation',
            'created_at', 'updated_at',
            'constellation_id', 'constellation_name',
            'node_type', 'target_constellation_id',
            'is_accentuated', 'show_keywords',
            'mucua_name', 'media_type', 'source_created_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $node, "Missing key: {$key}");
        }
    }

    public function testAnimationParsedToObject(): void
    {
        $result = db_format_nodes_bulk([$this->makeNode()]);
        $anim = $result[0]['animation'];

        $this->assertIsArray($anim);
        $this->assertArrayHasKey('radius', $anim);
        $this->assertArrayHasKey('speed', $anim);
    }

    public function testBooleanFieldsCast(): void
    {
        $result = db_format_nodes_bulk([$this->makeNode([
            'audio_autoplay' => 1,
            'audio_loop' => 0,
            'video_autoplay' => 1,
            'is_accentuated' => 0,
            'show_keywords' => 1,
        ])]);
        $node = $result[0];

        $this->assertIsBool($node['audio_autoplay']);
        $this->assertTrue($node['audio_autoplay']);
        $this->assertIsBool($node['audio_loop']);
        $this->assertFalse($node['audio_loop']);
        $this->assertIsBool($node['video_autoplay']);
        $this->assertTrue($node['video_autoplay']);
        $this->assertIsBool($node['is_accentuated']);
        $this->assertFalse($node['is_accentuated']);
        $this->assertIsBool($node['show_keywords']);
        $this->assertTrue($node['show_keywords']);
    }

    public function testMultipleNodesFormatted(): void
    {
        $nodes = [
            $this->makeNode(['id' => 1, 'name' => 'Node A', 'image_attribution' => 'Author A']),
            $this->makeNode(['id' => 2, 'name' => 'Node B', 'image_attribution' => null]),
            $this->makeNode(['id' => 3, 'name' => 'Node C', 'image_attribution' => 'Author C']),
        ];
        $result = db_format_nodes_bulk($nodes);

        $this->assertCount(3, $result);
        $this->assertSame('Author A', $result[0]['image_attribution']);
        $this->assertNull($result[1]['image_attribution']);
        $this->assertSame('Author C', $result[2]['image_attribution']);
    }

    public function testMucuaNamePreservedWhenSet(): void
    {
        $result = db_format_nodes_bulk([$this->makeNode(['mucua_name' => 'TestMucua'])]);
        $this->assertSame('TestMucua', $result[0]['mucua_name']);
    }

    public function testMucuaNameNullWhenEmpty(): void
    {
        $result = db_format_nodes_bulk([$this->makeNode(['mucua_name' => ''])]);
        $this->assertNull($result[0]['mucua_name']);
    }
}
