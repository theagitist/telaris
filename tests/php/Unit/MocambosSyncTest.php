<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/bridges/mocambos/sync.php';

final class MocambosSyncTest extends TestCase
{
    private static function makeExisting(string $slug, string $name, string $desc = '', string $type = 'imagem', string $mucua = '', string $date = '', array $keywords = []): array
    {
        return [
            'id' => crc32($slug), // deterministic fake ID
            'name' => $name,
            'description' => $desc,
            'media_type' => $type,
            'source_facet' => $mucua,
            'source_created_at' => $date,
            'keywords' => $keywords,
            'url' => 'https://example.com/permalink/acervo/' . $slug,
        ];
    }

    private static function makeApiItem(string $slug, string $title, string $desc = '', string $type = 'imagem', ?string $mucuaSmid = null, string $created = '', array $tags = []): array
    {
        return [
            'slug' => $slug,
            'title' => $title,
            'description' => $desc,
            'type' => $type,
            'mucua_smid' => $mucuaSmid,
            'created' => $created,
            'tags' => $tags,
            '_source_type' => 'acervo',
        ];
    }

    // ── Diff computation ─────────────────────────────────────────────────────

    public function testAllUnchanged(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A', 'desc', 'imagem', '', '2014-01-01', ['tag1']),
            'node-b' => self::makeExisting('node-b', 'Node B', '', 'video', '', '2014-02-01'),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A', 'desc', 'imagem', null, '2014-01-01', ['tag1']),
            self::makeApiItem('node-b', 'Node B', '', 'video', null, '2014-02-01'),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['modified']);
        $this->assertCount(0, $diff['deleted']);
        $this->assertSame(2, $diff['unchanged']);
    }

    public function testDetectsAdditions(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A'),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A'),
            self::makeApiItem('node-b', 'Node B'),
            self::makeApiItem('node-c', 'Node C'),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(2, $diff['added']);
        $this->assertSame(1, $diff['unchanged']);
        $slugs = array_map(fn($a) => $a['item']['slug'], $diff['added']);
        $this->assertContains('node-b', $slugs);
        $this->assertContains('node-c', $slugs);
    }

    public function testDetectsDeletions(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A'),
            'node-b' => self::makeExisting('node-b', 'Node B'),
            'node-c' => self::makeExisting('node-c', 'Node C'),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A'),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(2, $diff['deleted']);
        $slugs = array_map(fn($d) => $d['slug'], $diff['deleted']);
        $this->assertContains('node-b', $slugs);
        $this->assertContains('node-c', $slugs);
    }

    public function testDetectsNameModification(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Old Name'),
        ];
        $api = [
            self::makeApiItem('node-a', 'New Name'),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(1, $diff['modified']);
        $this->assertContains('name', $diff['modified'][0]['changes']);
    }

    public function testDetectsDescriptionModification(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A', 'old description'),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A', 'new description'),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(1, $diff['modified']);
        $this->assertContains('description', $diff['modified'][0]['changes']);
    }

    public function testDetectsTagModification(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A', '', 'imagem', '', '', ['tag1', 'tag2']),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A', '', 'imagem', null, '', ['tag1', 'tag3']),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(1, $diff['modified']);
        $this->assertContains('tags', $diff['modified'][0]['changes']);
    }

    public function testDetectsMediaTypeModification(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A', '', 'imagem'),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A', '', 'video'),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(1, $diff['modified']);
        $this->assertContains('media_type', $diff['modified'][0]['changes']);
    }

    public function testMucuaResolution(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A', '', 'imagem', 'Resolved Name'),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A', '', 'imagem', 'smid-123'),
        ];
        $mucuaMap = ['smid-123' => 'Resolved Name'];

        $diff = mocambos_compute_diff($existing, $api, $mucuaMap);

        $this->assertSame(1, $diff['unchanged']);
        $this->assertCount(0, $diff['modified']);
    }

    public function testMucuaMismatchDetected(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Node A', '', 'imagem', 'Old Mucua'),
        ];
        $api = [
            self::makeApiItem('node-a', 'Node A', '', 'imagem', 'smid-123'),
        ];
        $mucuaMap = ['smid-123' => 'New Mucua'];

        $diff = mocambos_compute_diff($existing, $api, $mucuaMap);

        $this->assertCount(1, $diff['modified']);
        $this->assertContains('source_facet', $diff['modified'][0]['changes']);
    }

    public function testMultipleChangesDetected(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'Old', 'old desc', 'imagem', '', '2014-01-01', ['old-tag']),
        ];
        $api = [
            self::makeApiItem('node-a', 'New', 'new desc', 'video', null, '2015-06-01', ['new-tag']),
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertCount(1, $diff['modified']);
        $changes = $diff['modified'][0]['changes'];
        $this->assertContains('name', $changes);
        $this->assertContains('description', $changes);
        $this->assertContains('media_type', $changes);
        $this->assertContains('source_created_at', $changes);
        $this->assertContains('tags', $changes);
    }

    public function testEmptyApiReturnsAllAsDeleted(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'A'),
            'node-b' => self::makeExisting('node-b', 'B'),
        ];

        $diff = mocambos_compute_diff($existing, [], []);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['modified']);
        $this->assertCount(2, $diff['deleted']);
    }

    public function testEmptyExistingReturnsAllAsAdded(): void
    {
        $api = [
            self::makeApiItem('node-a', 'A'),
            self::makeApiItem('node-b', 'B'),
        ];

        $diff = mocambos_compute_diff([], $api, []);

        $this->assertCount(2, $diff['added']);
        $this->assertCount(0, $diff['modified']);
        $this->assertCount(0, $diff['deleted']);
    }

    public function testBlogItemMediaTypeResolvesToBlog(): void
    {
        $existing = [
            'node-a' => self::makeExisting('node-a', 'A', '', 'blog'),
        ];
        $api = [
            ['slug' => 'node-a', 'title' => 'A', 'description' => '', 'type' => 'artigo',
             'mucua_smid' => null, 'created' => '', 'tags' => [], '_source_type' => 'blog'],
        ];

        $diff = mocambos_compute_diff($existing, $api, []);

        $this->assertSame(1, $diff['unchanged']);
    }

    public function testSkipsItemsWithEmptySlug(): void
    {
        $api = [
            self::makeApiItem('', 'No Slug'),
            self::makeApiItem('valid', 'Valid'),
        ];

        $diff = mocambos_compute_diff([], $api, []);

        $this->assertCount(1, $diff['added']);
        $this->assertSame('valid', $diff['added'][0]['item']['slug']);
    }
}
