<?php
declare(strict_types=1);

/**
 * Galaxy tags API.
 *
 * GET  /api/tags.php
 *      ?galaxy_id=N    → autocomplete-friendly bucketed response: tags currently on that
 *                        galaxy ('current'), tags on prefix-siblings ('siblings'), and
 *                        global tags ('global'). Each list contains {slug, label, count}.
 *      ?galaxy_id=N&assigned=1 → just the tags assigned to galaxy N (slug + label).
 *      (no params)     → flat global tag list with counts.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

header('Content-Type: application/json');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

requireApiKey();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('405.001', 'Method not allowed.');
    }

    $galaxyId = isset($_GET['galaxy_id']) && ctype_digit((string)$_GET['galaxy_id']) ? (int)$_GET['galaxy_id'] : null;
    $assignedOnly = !empty($_GET['assigned']);

    if ($galaxyId !== null && $assignedOnly) {
        echo json_encode(db_get_tags_for_galaxy($galaxyId), JSON_THROW_ON_ERROR);
        exit();
    }

    if ($galaxyId !== null) {
        // Bucketed autocomplete response. Each tag appears in only the most-specific bucket
        // (current > siblings > global) so the frontend can render them in priority order
        // without de-duplicating.
        $current = db_get_tags_for_galaxy($galaxyId);
        $currentSlugs = array_flip(array_map(fn($t) => $t['slug'], $current));

        $siblingIds = db_get_prefix_sibling_ids($galaxyId);
        $siblingIds = array_values(array_filter($siblingIds, fn($id) => $id !== $galaxyId));
        $siblings = $siblingIds === [] ? [] : db_get_tags_for_galaxies($siblingIds);
        $siblings = array_values(array_filter($siblings, fn($t) => !isset($currentSlugs[$t['slug']])));
        $siblingSlugs = array_flip(array_map(fn($t) => $t['slug'], $siblings));

        $global = db_get_all_tags_with_counts();
        $global = array_values(array_filter($global, fn($t) => !isset($currentSlugs[$t['slug']]) && !isset($siblingSlugs[$t['slug']])));

        echo json_encode([
            'current' => array_map(fn($t) => ['slug' => $t['slug'], 'label' => $t['label']], $current),
            'siblings' => $siblings,
            'global' => $global,
        ], JSON_THROW_ON_ERROR);
        exit();
    }

    echo json_encode(db_get_all_tags_with_counts(), JSON_THROW_ON_ERROR);
} catch (PDOException $e) {
    error_log('tags.php PDOException: ' . $e->getMessage());
    api_error('500.002', 'Database error.');
} catch (RuntimeException $e) {
    error_log('tags.php RuntimeException: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
} catch (JsonException $e) {
    error_log('tags.php JsonException: ' . $e->getMessage());
    api_error('400.041', 'Encoding error.');
}
