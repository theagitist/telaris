<?php
declare(strict_types=1);

/**
 * Clustering engine for large constellations.
 * Groups nodes into navigable clusters when a constellation exceeds a threshold.
 */

/** @var string Localized label for "items" (e.g. "items", "elementos", "itens"). */
$CLUSTERING_ITEMS_LABEL = 'items';
/** @var string Localized label for "Other" group. */
$CLUSTERING_OTHER_LABEL = 'Other';

/**
 * Set localized labels for clustering output.
 */
function clustering_set_labels(string $itemsLabel, string $otherLabel): void {
    global $CLUSTERING_ITEMS_LABEL, $CLUSTERING_OTHER_LABEL;
    $CLUSTERING_ITEMS_LABEL = $itemsLabel;
    $CLUSTERING_OTHER_LABEL = $otherLabel;
}

/**
 * Build a cluster summary node.
 */
function make_cluster_summary(string $clusterKey, string $name, int $count, string $level): array {
    global $CLUSTERING_ITEMS_LABEL;
    return [
        'id' => 'cluster:' . $clusterKey,
        'name' => $name,
        'description' => number_format($count) . ' ' . $CLUSTERING_ITEMS_LABEL,
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
        'animation' => [
            'radius' => 5 + rand(0, 3),
            'theta'  => rand(0, 628) / 100,
            'phi'    => rand(0, 314) / 100,
            'speed'  => 0.002 + (rand(0, 4) / 1000),
            'phase'  => rand(0, 628) / 100,
        ],
        'created_at' => null,
        'updated_at' => null,
        'constellation_id' => null,
        'constellation_name' => null,
        'node_type' => 'cluster',
        'target_constellation_id' => null,
        'is_accentuated' => false,
        'show_keywords' => false,
        'cluster_key' => $clusterKey,
        'cluster_count' => $count,
        'cluster_level' => $level,
        'source_facet' => null,
        'media_type' => null,
        'source_created_at' => null,
    ];
}

/**
 * Group nodes by a field value, returning an associative array.
 */
function group_nodes_by(array $nodes, string $field): array {
    $groups = [];
    foreach ($nodes as $node) {
        $val = $node[$field] ?? null;
        $key = ($val !== null && $val !== '') ? (string)$val : '__other__';
        $groups[$key][] = $node;
    }
    return $groups;
}

/**
 * Extract year from source_created_at string.
 */
function extract_year(?string $dateStr): ?string {
    if ($dateStr === null || $dateStr === '') return null;
    if (preg_match('/^(\d{4})/', $dateStr, $m)) return $m[1];
    return null;
}

/**
 * Extract year-month from source_created_at string.
 */
function extract_year_month(?string $dateStr): ?string {
    if ($dateStr === null || $dateStr === '') return null;
    if (preg_match('/^(\d{4}-\d{2})/', $dateStr, $m)) return $m[1];
    return null;
}

/**
 * Get alphabetical bucket label for a name (A-F, G-L, M-R, S-Z).
 */
function alpha_bucket(string $name): string {
    $first = mb_strtoupper(mb_substr(trim($name), 0, 1));
    if ($first >= 'A' && $first <= 'F') return 'A-F';
    if ($first >= 'G' && $first <= 'L') return 'G-L';
    if ($first >= 'M' && $first <= 'R') return 'M-R';
    return 'S-Z';
}

/**
 * Recursively cluster nodes using a cascade of grouping criteria.
 *
 * @param array $nodes Formatted node arrays
 * @param string $parentKey Current cluster key prefix (empty at root)
 * @param array $cascade Remaining grouping criteria
 * @param int $threshold Max items before clustering
 * @return array Mixed array of cluster summaries and direct nodes
 */
function cluster_recursive(array $nodes, string $parentKey, array $cascade, int $threshold): array {
    if (count($nodes) <= $threshold || empty($cascade)) {
        return $nodes;
    }

    $criterion = array_shift($cascade);
    $groups = [];

    switch ($criterion) {
        case 'source':
            $groups = group_nodes_by($nodes, 'source_facet');
            break;
        case 'type':
            $groups = group_nodes_by($nodes, 'media_type');
            break;
        case 'date_year':
            foreach ($nodes as $node) {
                $year = extract_year($node['source_created_at'] ?? null) ?? '__other__';
                $groups[$year][] = $node;
            }
            break;
        case 'date_month':
            foreach ($nodes as $node) {
                $ym = extract_year_month($node['source_created_at'] ?? null) ?? '__other__';
                $groups[$ym][] = $node;
            }
            break;
        case 'alpha':
            foreach ($nodes as $node) {
                $bucket = alpha_bucket($node['name'] ?? '');
                $groups[$bucket][] = $node;
            }
            break;
    }

    // If grouping produced only 1 group, skip this level and try next criterion
    if (count($groups) <= 1) {
        return cluster_recursive($nodes, $parentKey, $cascade, $threshold);
    }

    $result = [];
    foreach ($groups as $key => $groupNodes) {
        $key = (string)$key; // PHP casts numeric array keys to int

        // Single-item groups: promote to parent level
        if (count($groupNodes) === 1) {
            $result[] = $groupNodes[0];
            continue;
        }

        $levelLabel = match($criterion) {
            'source' => 'source',
            'type' => 'type',
            'date_year' => 'date',
            'date_month' => 'date',
            'alpha' => 'alpha',
            default => $criterion,
        };

        $segmentKey = $levelLabel . ':' . $key;
        $clusterKey = $parentKey !== '' ? $parentKey . '/' . $segmentKey : $segmentKey;

        if (count($groupNodes) <= $threshold) {
            // Small enough, make a single cluster that contains them directly
            global $CLUSTERING_OTHER_LABEL;
                $displayName = $key === '__other__' ? $CLUSTERING_OTHER_LABEL : $key;
            $result[] = make_cluster_summary($clusterKey, $displayName, count($groupNodes), $levelLabel);
        } else {
            // Still too large — recurse with remaining cascade
            $subResult = cluster_recursive($groupNodes, $clusterKey, $cascade, $threshold);
            // If recursion produced clusters, wrap in a parent cluster
            $hasClusters = false;
            foreach ($subResult as $item) {
                if (($item['node_type'] ?? '') === 'cluster') {
                    $hasClusters = true;
                    break;
                }
            }
            if ($hasClusters) {
                global $CLUSTERING_OTHER_LABEL;
                $displayName = $key === '__other__' ? $CLUSTERING_OTHER_LABEL : $key;
                $result[] = make_cluster_summary($clusterKey, $displayName, count($groupNodes), $levelLabel);
            } else {
                // Recursion didn't help (ran out of cascade) — just make a cluster anyway
                global $CLUSTERING_OTHER_LABEL;
                $displayName = $key === '__other__' ? $CLUSTERING_OTHER_LABEL : $key;
                $result[] = make_cluster_summary($clusterKey, $displayName, count($groupNodes), $levelLabel);
            }
        }
    }

    return $result;
}

/**
 * Compute clusters for a set of nodes.
 *
 * Uses adaptive clustering: the threshold is 80 nodes, but clustering is
 * skipped if the result isn't meaningfully better than showing everything flat.
 *
 * Quality checks (any fails → show flat):
 * - Fewer than 3 groups produced
 * - Largest group contains >80% of all nodes (one dominant group)
 * - More than half the items are single-node promotions (too fragmented)
 *
 * @param array $nodes Formatted node arrays from db_format_node
 * @param int $threshold Maximum items to show at once (default 80)
 * @return array Mixed array of cluster summaries and regular nodes
 */
function compute_clusters(array $nodes, int $threshold = 80): array {
    $totalCount = count($nodes);
    if ($totalCount <= $threshold) {
        return $nodes;
    }

    // If the source_facet column is populated on any node, use it as the
    // first clustering axis (it's the strongest grouping when present).
    $hasMucua = false;
    foreach ($nodes as $node) {
        if (!empty($node['source_facet'])) {
            $hasMucua = true;
            break;
        }
    }

    if ($hasMucua) {
        $cascade = ['source', 'type', 'date_year', 'date_month', 'alpha'];
    } else {
        $cascade = ['type', 'date_year', 'date_month', 'alpha'];
    }

    $result = cluster_recursive($nodes, '', $cascade, $threshold);

    // Quality check: is the clustering actually useful?
    $clusterCount = 0;
    $maxClusterSize = 0;
    $promotedCount = 0; // single nodes promoted to parent level
    foreach ($result as $item) {
        if (($item['node_type'] ?? '') === 'cluster') {
            $clusterCount++;
            $maxClusterSize = max($maxClusterSize, $item['cluster_count'] ?? 0);
        } else {
            $promotedCount++;
        }
    }

    // Skip clustering if result is not meaningful
    if ($clusterCount < 3) {
        return $nodes; // Too few groups — not worth it
    }
    if ($maxClusterSize > $totalCount * 0.8) {
        return $nodes; // One dominant group has >80% of items — not useful
    }
    if ($promotedCount > count($result) * 0.5) {
        return $nodes; // More than half are single-node promotions — too fragmented
    }

    return $result;
}

/**
 * Filter nodes matching a cluster key, then compute sub-clusters if needed.
 *
 * @param array $nodes All formatted nodes for the constellation
 * @param string $clusterKey e.g. "source:Abdias" or "source:Abdias/type:imagem"
 * @param int $threshold Max items before clustering
 * @return array Mixed array of cluster summaries and regular nodes
 */
function filter_nodes_by_cluster(array $nodes, string $clusterKey, int $threshold = 50): array {
    // Parse cluster key into segments
    $segments = explode('/', $clusterKey);
    $filters = [];
    foreach ($segments as $seg) {
        $colonPos = strpos($seg, ':');
        if ($colonPos === false) continue;
        $level = substr($seg, 0, $colonPos);
        $value = substr($seg, $colonPos + 1);
        $filters[] = ['level' => $level, 'value' => $value];
    }

    // Filter nodes matching all segments
    $filtered = $nodes;
    foreach ($filters as $f) {
        $filtered = array_values(array_filter($filtered, function ($node) use ($f) {
            switch ($f['level']) {
                case 'source':
                    return ($node['source_facet'] ?? '') === $f['value'];
                case 'type':
                    return ($node['media_type'] ?? '') === $f['value'];
                case 'date':
                    $dateStr = $node['source_created_at'] ?? '';
                    // Match year or year-month
                    if (strlen($f['value']) === 4) {
                        return extract_year($dateStr) === $f['value'];
                    }
                    return extract_year_month($dateStr) === $f['value'];
                case 'alpha':
                    return alpha_bucket($node['name'] ?? '') === $f['value'];
                default:
                    return true;
            }
        }));
    }

    if (count($filtered) <= $threshold) {
        return $filtered;
    }

    // Determine remaining cascade based on what's already been filtered
    $usedLevels = array_map(fn($f) => $f['level'], $filters);

    // Detect whether the source_facet column is populated on this slice.
    $hasMucua = false;
    foreach ($filtered as $node) {
        if (!empty($node['source_facet'])) {
            $hasMucua = true;
            break;
        }
    }

    if ($hasMucua) {
        $fullCascade = ['source', 'type', 'date_year', 'date_month', 'alpha'];
    } else {
        $fullCascade = ['type', 'date_year', 'date_month', 'alpha'];
    }

    // Remove already-used levels from cascade
    $remaining = [];
    foreach ($fullCascade as $c) {
        $cLevel = match($c) {
            'date_year', 'date_month' => 'date',
            default => $c,
        };
        if (!in_array($cLevel, $usedLevels, true)) {
            $remaining[] = $c;
        }
    }

    return cluster_recursive($filtered, $clusterKey, $remaining, $threshold);
}

/**
 * Find the cluster path that leads to a specific node being directly visible.
 * Returns null if the constellation has ≤threshold nodes (no clustering needed),
 * or the cluster key string to drill into to see the node.
 *
 * @param array $allNodes All formatted nodes for the constellation
 * @param int $nodeId The node ID to find
 * @param int $threshold Clustering threshold
 * @return string|null Cluster key, or null if node is visible at root level
 */
function find_cluster_path_for_node(array $allNodes, int $nodeId, int $threshold = 50): ?string {
    // No clustering needed
    if (count($allNodes) <= $threshold) {
        return null;
    }

    // Find the target node
    $targetNode = null;
    foreach ($allNodes as $node) {
        if ((int)$node['id'] === $nodeId) {
            $targetNode = $node;
            break;
        }
    }
    if ($targetNode === null) return null;

    // Detect cascade
    $hasMucua = false;
    foreach ($allNodes as $node) {
        if (!empty($node['source_facet'])) { $hasMucua = true; break; }
    }
    $cascade = $hasMucua
        ? ['source', 'type', 'date_year', 'date_month', 'alpha']
        : ['type', 'date_year', 'date_month', 'alpha'];

    // Walk down the clustering tree to find where this node ends up visible
    return _find_path_recursive($allNodes, $targetNode, '', $cascade, $threshold);
}

function _find_path_recursive(array $nodes, array $targetNode, string $parentKey, array $cascade, int $threshold): ?string {
    if (count($nodes) <= $threshold) {
        // Node is directly visible at this level
        return $parentKey === '' ? null : $parentKey;
    }

    if (empty($cascade)) {
        return $parentKey === '' ? null : $parentKey;
    }

    $criterion = array_shift($cascade);
    $groups = [];

    switch ($criterion) {
        case 'source':
            $groups = group_nodes_by($nodes, 'source_facet');
            break;
        case 'type':
            $groups = group_nodes_by($nodes, 'media_type');
            break;
        case 'date_year':
            foreach ($nodes as $node) {
                $year = extract_year($node['source_created_at'] ?? null) ?? '__other__';
                $groups[$year][] = $node;
            }
            break;
        case 'date_month':
            foreach ($nodes as $node) {
                $ym = extract_year_month($node['source_created_at'] ?? null) ?? '__other__';
                $groups[$ym][] = $node;
            }
            break;
        case 'alpha':
            foreach ($nodes as $node) {
                $bucket = alpha_bucket($node['name'] ?? '');
                $groups[$bucket][] = $node;
            }
            break;
    }

    // If grouping produced ≤1 group, skip this level
    if (count($groups) <= 1) {
        return _find_path_recursive($nodes, $targetNode, $parentKey, $cascade, $threshold);
    }

    $levelLabel = match($criterion) {
        'source' => 'source', 'type' => 'type',
        'date_year', 'date_month' => 'date',
        'alpha' => 'alpha', default => $criterion,
    };

    // Find which group contains the target node
    $targetId = (int)$targetNode['id'];
    foreach ($groups as $key => $groupNodes) {
        $key = (string)$key;
        $found = false;
        foreach ($groupNodes as $gn) {
            if ((int)$gn['id'] === $targetId) { $found = true; break; }
        }
        if (!$found) continue;

        // Single-item groups are promoted — node is visible at parent level
        if (count($groupNodes) === 1) {
            return $parentKey === '' ? null : $parentKey;
        }

        $segmentKey = $levelLabel . ':' . $key;
        $clusterKey = $parentKey !== '' ? $parentKey . '/' . $segmentKey : $segmentKey;

        if (count($groupNodes) <= $threshold) {
            // Node is directly visible inside this cluster
            return $clusterKey;
        }

        // Recurse deeper
        return _find_path_recursive($groupNodes, $targetNode, $clusterKey, $cascade, $threshold);
    }

    return $parentKey === '' ? null : $parentKey;
}
