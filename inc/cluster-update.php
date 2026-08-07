<?php
declare(strict_types=1);

/**
 * Persist a cluster's discovery config (auto-tour, idle spotlight, related
 * wormholes, keyword chips) from a posted cluster-modal form. Mirrors the
 * galaxy variant in inc/galaxy-update.php but with two differences:
 *   1. Clusters use db_set_constellation_tour_config() against the cluster row.
 *   2. tour_node_selection='tagged' reads a comma-separated `tour_keyword_names`
 *      field (clusters have no native keywords; names are persisted as
 *      cluster-owned keyword rows by db_set_cluster_tour_keyword_names).
 *
 * Caller has already validated cluster type / admin auth.
 */
function save_cluster_discovery_config_from_post(int $clusterId, array $post): void {
    db_set_constellation_tour_config($clusterId, [
        'tour_enabled' => !empty($post['tour_enabled']),
        'tour_start_mode' => (string)($post['tour_start_mode'] ?? 'manual'),
        'tour_idle_seconds' => (int)($post['tour_idle_seconds'] ?? 30),
        'tour_node_selection' => (string)($post['tour_node_selection'] ?? 'all'),
        'tour_random_count' => (int)($post['tour_random_count'] ?? 10),
        'tour_default_dwell' => (int)($post['tour_default_dwell'] ?? 8),
        'tour_loop' => !empty($post['tour_loop']),
        'keyword_chips_enabled' => !empty($post['keyword_chips_enabled']),
        'idle_spotlight_enabled' => !empty($post['idle_spotlight_enabled']),
        'idle_spotlight_selection' => (string)($post['idle_spotlight_selection'] ?? 'all'),
        'idle_spotlight_idle_seconds' => (int)($post['idle_spotlight_idle_seconds'] ?? 30),
        'related_nodes_enabled' => !empty($post['related_nodes_enabled']),
        'show_2d_view' => !empty($post['show_2d_view']),
        'group_nodes' => !empty($post['group_nodes']),
    ]);

    $raw = (string)($post['tour_keyword_names'] ?? '');
    $names = $raw === '' ? [] : array_map('trim', explode(',', $raw));
    $names = array_values(array_filter($names, fn($n) => $n !== ''));
    db_set_cluster_tour_keyword_names($clusterId, $names);
}
