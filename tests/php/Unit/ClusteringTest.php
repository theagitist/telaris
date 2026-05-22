<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../../inc/clustering.php';

final class ClusteringTest extends TestCase
{
    private static function makeNode(int $id, string $name, ?string $facet = null, ?string $mediaType = null, ?string $date = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'description' => null,
            'url' => null,
            'image_url' => null,
            'icon_url' => null,
            'embed_code' => null,
            'audio_url' => null,
            'audio_autoplay' => false,
            'audio_loop' => false,
            'video_url' => null,
            'video_autoplay' => false,
            'keywords' => [],
            'animation' => ['radius' => 5, 'theta' => 0, 'phi' => 0, 'speed' => 0.003, 'phase' => 0],
            'created_at' => null,
            'updated_at' => null,
            'constellation_id' => 1,
            'constellation_name' => 'Test',
            'node_type' => 'object',
            'target_constellation_id' => null,
            'is_accentuated' => false,
            'show_keywords' => false,
            'source_facet' => $facet,
            'media_type' => $mediaType,
            'source_created_at' => $date,
        ];
    }

    private static function makeNodes(int $count, ?string $facet = null, ?string $mediaType = null): array
    {
        $nodes = [];
        for ($i = 1; $i <= $count; $i++) {
            $nodes[] = self::makeNode($i, "Node {$i}", $facet, $mediaType);
        }
        return $nodes;
    }

    // ── compute_clusters ─────────────────────────────────────────────────────

    public function testSmallConstellationNotClustered(): void
    {
        $nodes = self::makeNodes(50);
        $result = compute_clusters($nodes);
        $this->assertCount(50, $result);
        foreach ($result as $item) {
            $this->assertNotSame('cluster', $item['node_type'] ?? '');
        }
    }

    public function testBelowThresholdNotClustered(): void
    {
        $nodes = self::makeNodes(80);
        $result = compute_clusters($nodes);
        $this->assertCount(80, $result);
    }

    public function testAboveThresholdWithGoodGroupsGetsClustered(): void
    {
        // 200 nodes across 5 facets = 40 each — good quality clustering
        $nodes = [];
        $facets = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
        for ($i = 0; $i < 200; $i++) {
            $nodes[] = self::makeNode($i + 1, "Node {$i}", $facets[$i % 5], 'imagem', '2014-01-01');
        }
        $result = compute_clusters($nodes);
        $this->assertLessThan(200, count($result), 'Should be clustered');

        $clusterCount = 0;
        foreach ($result as $item) {
            if (($item['node_type'] ?? '') === 'cluster') $clusterCount++;
        }
        $this->assertGreaterThanOrEqual(3, $clusterCount, 'Should have at least 3 clusters');
    }

    public function testQualityCheckSkipsWhenTooFewGroups(): void
    {
        // 100 nodes all in same source value — produces only 1 group, should stay flat
        $nodes = self::makeNodes(100, 'single-source', 'imagem');
        $result = compute_clusters($nodes);
        $this->assertCount(100, $result, 'Single-group clustering should be skipped');
    }

    public function testQualityCheckSkipsDominantGroup(): void
    {
        // 100 nodes: 90 in source-a, 5 in source-b, 5 in source-c
        $nodes = [];
        for ($i = 0; $i < 90; $i++) $nodes[] = self::makeNode($i + 1, "A-{$i}", 'source-a', 'imagem');
        for ($i = 0; $i < 5; $i++) $nodes[] = self::makeNode(91 + $i, "B-{$i}", 'source-b', 'imagem');
        for ($i = 0; $i < 5; $i++) $nodes[] = self::makeNode(96 + $i, "C-{$i}", 'source-c', 'imagem');

        $result = compute_clusters($nodes);
        $this->assertCount(100, $result, 'Dominant group (90%) should cause clustering to be skipped');
    }

    public function testCascadeWithoutFacetUsesMediaType(): void
    {
        // 200 nodes with no source_facet but different media types
        $types = ['imagem', 'video', 'audio', 'arquivo', 'blog'];
        $nodes = [];
        for ($i = 0; $i < 200; $i++) {
            $nodes[] = self::makeNode($i + 1, "Node {$i}", null, $types[$i % 5], '2014-01-01');
        }
        $result = compute_clusters($nodes);
        $this->assertLessThan(200, count($result), 'Should be clustered by media type');
    }

    // ── make_cluster_summary ─────────────────────────────────────────────────

    public function testClusterSummaryFormat(): void
    {
        $summary = make_cluster_summary('source:test', 'Test Source', 42, 'source');
        $this->assertSame('cluster:source:test', $summary['id']);
        $this->assertSame('Test Source', $summary['name']);
        $this->assertSame('cluster', $summary['node_type']);
        $this->assertSame('source:test', $summary['cluster_key']);
        $this->assertSame(42, $summary['cluster_count']);
        $this->assertSame('source', $summary['cluster_level']);
        $this->assertSame([], $summary['keywords']);
        $this->assertStringContains('42', $summary['description']);
    }

    // ── filter_nodes_by_cluster ──────────────────────────────────────────────

    public function testFilterBySourceFacet(): void
    {
        $nodes = [];
        for ($i = 0; $i < 10; $i++) $nodes[] = self::makeNode($i + 1, "A-{$i}", 'alpha');
        for ($i = 0; $i < 10; $i++) $nodes[] = self::makeNode(11 + $i, "B-{$i}", 'bravo');

        $result = filter_nodes_by_cluster($nodes, 'source:alpha');
        $this->assertCount(10, $result);
        foreach ($result as $node) {
            $this->assertSame('alpha', $node['source_facet']);
        }
    }

    public function testFilterByNestedKey(): void
    {
        $nodes = [];
        for ($i = 0; $i < 5; $i++) $nodes[] = self::makeNode($i + 1, "AI-{$i}", 'alpha', 'imagem');
        for ($i = 0; $i < 5; $i++) $nodes[] = self::makeNode(6 + $i, "AV-{$i}", 'alpha', 'video');
        for ($i = 0; $i < 5; $i++) $nodes[] = self::makeNode(11 + $i, "B-{$i}", 'bravo', 'imagem');

        $result = filter_nodes_by_cluster($nodes, 'source:alpha/type:imagem');
        $this->assertCount(5, $result);
        foreach ($result as $node) {
            $this->assertSame('alpha', $node['source_facet']);
            $this->assertSame('imagem', $node['media_type']);
        }
    }

    // ── find_cluster_path_for_node ───────────────────────────────────────────

    public function testFindPathReturnsNullForSmallConstellation(): void
    {
        $nodes = self::makeNodes(30);
        $path = find_cluster_path_for_node($nodes, 1);
        $this->assertNull($path);
    }

    public function testFindPathReturnsCorrectSourceCluster(): void
    {
        // 200 nodes across 5 source values
        $facets = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
        $nodes = [];
        for ($i = 0; $i < 200; $i++) {
            $nodes[] = self::makeNode($i + 1, "Node {$i}", $facets[$i % 5], 'imagem');
        }

        // Node 1 has source 'alpha' (index 0 % 5 = 0)
        $path = find_cluster_path_for_node($nodes, 1);
        $this->assertNotNull($path);
        $this->assertStringContainsString('source:alpha', $path);
    }

    public function testFindPathReturnsNullForMissingNode(): void
    {
        $nodes = self::makeNodes(200, 'test');
        $path = find_cluster_path_for_node($nodes, 99999);
        $this->assertNull($path);
    }

    // ── Localization labels ──────────────────────────────────────────────────

    public function testClusteringLabelsCanBeSet(): void
    {
        clustering_set_labels('elementos', 'Otros');
        global $CLUSTERING_ITEMS_LABEL, $CLUSTERING_OTHER_LABEL;
        $this->assertSame('elementos', $CLUSTERING_ITEMS_LABEL);
        $this->assertSame('Otros', $CLUSTERING_OTHER_LABEL);

        // Reset
        clustering_set_labels('items', 'Other');
    }

    public function testClusterSummaryUsesLocalizedLabel(): void
    {
        clustering_set_labels('itens', 'Outros');
        $summary = make_cluster_summary('source:x', 'X', 10, 'source');
        $this->assertStringContainsString('itens', $summary['description']);

        // Reset
        clustering_set_labels('items', 'Other');
    }

    // ── Helper functions ─────────────────────────────────────────────────────

    public function testExtractYear(): void
    {
        $this->assertSame('2014', extract_year('2014-03-15T10:00:00'));
        $this->assertSame('2023', extract_year('2023'));
        $this->assertNull(extract_year(null));
        $this->assertNull(extract_year(''));
    }

    public function testExtractYearMonth(): void
    {
        $this->assertSame('2014-03', extract_year_month('2014-03-15T10:00:00'));
        $this->assertNull(extract_year_month(null));
        $this->assertNull(extract_year_month(''));
    }

    public function testAlphaBucket(): void
    {
        $this->assertSame('A-F', alpha_bucket('Apple'));
        $this->assertSame('G-L', alpha_bucket('grape'));
        $this->assertSame('M-R', alpha_bucket('Mango'));
        $this->assertSame('S-Z', alpha_bucket('strawberry'));
        $this->assertSame('S-Z', alpha_bucket('Zebra'));
    }

    // ── PHP assertion helper ─────────────────────────────────────────────────

    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertTrue(str_contains($haystack, $needle), "Failed asserting that '{$haystack}' contains '{$needle}'");
    }
}
