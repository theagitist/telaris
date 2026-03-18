<?php
declare(strict_types=1);

/**
 * Incremental sync logic for Mocambos imports.
 * Shared between api/mocambos.php (web) and admin/cli/import_mocambos.php (CLI).
 */

/**
 * Compute the diff between existing nodes and incoming API items.
 *
 * @param array $existingBySlug Keyed by slug from db_get_nodes_by_import_slug()
 * @param array $apiItems Raw items from the Mocambos API
 * @param array $mucuaNameMap smid → name map
 * @return array{added: array, modified: array, deleted: array, unchanged: int}
 */
function mocambos_compute_diff(array $existingBySlug, array $apiItems, array $mucuaNameMap): array {
    $apiBySlug = [];
    foreach ($apiItems as $idx => $item) {
        $slug = $item['slug'] ?? '';
        if ($slug === '') continue;
        $apiBySlug[$slug] = [
            'idx' => $idx,
            'item' => $item,
            'name' => $item['title'] ?? $slug,
            'description' => $item['description'] ?? '',
            'media_type' => ($item['_source_type'] ?? '') === 'blog' ? 'blog' : ($item['type'] ?? 'arquivo'),
            'mucua_name' => _resolve_mucua($item, $mucuaNameMap),
            'source_created_at' => $item['created'] ?? '',
            'tags' => _normalize_tags($item['tags'] ?? []),
        ];
    }

    $added = [];
    $modified = [];
    $deleted = [];
    $unchanged = 0;

    // Find additions and modifications
    foreach ($apiBySlug as $slug => $apiData) {
        if (!isset($existingBySlug[$slug])) {
            $added[] = $apiData;
            continue;
        }
        $existing = $existingBySlug[$slug];
        $changes = _detect_changes($existing, $apiData);
        if (!empty($changes)) {
            $modified[] = [
                'node_id' => $existing['id'],
                'slug' => $slug,
                'changes' => $changes,
                'api' => $apiData,
                'existing' => $existing,
            ];
        } else {
            $unchanged++;
        }
    }

    // Find deletions
    foreach ($existingBySlug as $slug => $existing) {
        if (!isset($apiBySlug[$slug])) {
            $deleted[] = ['node_id' => $existing['id'], 'slug' => $slug, 'name' => $existing['name']];
        }
    }

    return ['added' => $added, 'modified' => $modified, 'deleted' => $deleted, 'unchanged' => $unchanged];
}

/**
 * Apply additions from the diff.
 * Returns array of [itemIndex => nodeId] for media download phase.
 */
function mocambos_apply_additions(
    array $additions,
    int $constellationId,
    string $galaxiaSlug,
    array $mucuaSlugMap,
    string $downloadBase,
    PDO $pdo
): array {
    if (empty($additions)) return [];

    $insertStmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, animation, constellation_id, node_type, audio_autoplay, video_autoplay, mucua_name, media_type, source_created_at, import_slug)
        VALUES (:name, :description, :url, :animation, :constellation_id, 'object', 1, 1, :mucua_name, :media_type, :source_created_at, :import_slug)
    ");
    $kwInsertStmt = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :cid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $kwLookupStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword AND constellation_id = :cid LIMIT 1");
    $nkInsertStmt = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid) ON DUPLICATE KEY UPDATE node_id=node_id");

    $nodeIdMap = [];
    $pdo->beginTransaction();

    foreach ($additions as $i => $apiData) {
        $item = $apiData['item'];
        $slug = $item['slug'] ?? '';
        $itemMucuaSlug = $mucuaSlugMap[$item['mucua_smid'] ?? ''] ?? '';
        $nodeUrl = $downloadBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlug . '/' . $slug;

        $animation = json_encode([
            'radius' => 5 + rand(0, 3), 'theta' => rand(0, 628) / 100,
            'phi' => rand(0, 314) / 100, 'speed' => 0.002 + (rand(0, 4) / 1000),
            'phase' => rand(0, 628) / 100,
        ], JSON_THROW_ON_ERROR);

        $insertStmt->execute([
            ':name' => $apiData['name'],
            ':description' => $apiData['description'] ?: null,
            ':url' => $nodeUrl,
            ':animation' => $animation,
            ':constellation_id' => $constellationId,
            ':mucua_name' => $apiData['mucua_name'] ?: null,
            ':media_type' => $apiData['media_type'] ?: null,
            ':source_created_at' => $apiData['source_created_at'] ?: null,
            ':import_slug' => $slug,
        ]);
        $nodeId = (int)$pdo->lastInsertId();
        $nodeIdMap[$apiData['idx']] = $nodeId;

        foreach ($apiData['tags'] as $tag) {
            if ($tag === '') continue;
            $kwInsertStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
            $kwId = (int)$pdo->lastInsertId();
            if ($kwId === 0) {
                $kwLookupStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
                $kwId = (int)($kwLookupStmt->fetchColumn() ?: 0);
            }
            if ($kwId > 0) $nkInsertStmt->execute([':nid' => $nodeId, ':kid' => $kwId]);
        }

        if ($i % 500 === 499) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    $pdo->commit();
    return $nodeIdMap;
}

/**
 * Apply modifications from the diff.
 */
function mocambos_apply_modifications(array $modifications, int $constellationId, PDO $pdo): void {
    if (empty($modifications)) return;

    $updateStmt = $pdo->prepare("UPDATE nodes SET name = :name, description = :description, media_type = :media_type, mucua_name = :mucua_name, source_created_at = :source_created_at WHERE id = :id");

    foreach ($modifications as $mod) {
        $api = $mod['api'];
        $nodeId = $mod['node_id'];

        $updateStmt->execute([
            ':id' => $nodeId,
            ':name' => $api['name'],
            ':description' => $api['description'] ?: null,
            ':media_type' => $api['media_type'] ?: null,
            ':mucua_name' => $api['mucua_name'] ?: null,
            ':source_created_at' => $api['source_created_at'] ?: null,
        ]);

        // Update keywords if changed
        if (in_array('tags', $mod['changes'], true)) {
            db_save_node_keywords($nodeId, $api['tags']);
        }
    }
}

/**
 * Apply deletions from the diff.
 */
function mocambos_apply_deletions(array $deletions, int $constellationId, PDO $pdo): void {
    foreach ($deletions as $del) {
        db_delete_node($del['node_id']);
    }
    // Clean up orphan keywords
    if (!empty($deletions)) {
        $pdo->prepare("
            DELETE k FROM keywords k
            LEFT JOIN node_keywords nk ON nk.keyword_id = k.id
            WHERE k.constellation_id = :cid AND nk.id IS NULL
        ")->execute([':cid' => $constellationId]);
    }
}

// ── Internal helpers ─────────────────────────────────────────────────────────

function _resolve_mucua(array $item, array $mucuaNameMap): string {
    $smid = $item['mucua_smid'] ?? null;
    if ($smid !== null && isset($mucuaNameMap[$smid])) {
        return $mucuaNameMap[$smid];
    }
    return '';
}

function _normalize_tags(mixed $tags): array {
    if (!is_array($tags)) return [];
    $out = [];
    foreach ($tags as $t) {
        $t = trim((string)$t);
        if ($t !== '') $out[] = $t;
    }
    sort($out);
    return $out;
}

function _detect_changes(array $existing, array $api): array {
    $changes = [];
    if (($existing['name'] ?? '') !== ($api['name'] ?? '')) $changes[] = 'name';
    if (($existing['description'] ?? '') !== ($api['description'] ?? '')) $changes[] = 'description';
    if (($existing['media_type'] ?? '') !== ($api['media_type'] ?? '')) $changes[] = 'media_type';
    if (($existing['mucua_name'] ?? '') !== ($api['mucua_name'] ?? '')) $changes[] = 'mucua_name';
    if (($existing['source_created_at'] ?? '') !== ($api['source_created_at'] ?? '')) $changes[] = 'source_created_at';

    $existingTags = $existing['keywords'] ?? [];
    sort($existingTags);
    $apiTags = $api['tags'] ?? [];
    if ($existingTags !== $apiTags) $changes[] = 'tags';

    return $changes;
}
