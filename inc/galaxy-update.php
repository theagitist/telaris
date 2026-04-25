<?php
declare(strict_types=1);

/**
 * Shared handler for the galaxy edit form.
 *
 * Used by both /admin/index.php and /edit/index.php so they don't duplicate
 * the validation, auth check, or DB calls. Auth model:
 *  - admins can edit any galaxy.
 *  - editors can edit galaxies in their user_constellations assignment, with
 *    a couple of fields (slug) reserved for admins to avoid breaking URLs.
 *
 * @param array $post   Typically $_POST from the edit form.
 * @param ?string $userId  Current session user id (null for unauthenticated, but caller should require auth first).
 * @param bool $isAdmin
 * @return array{ok: bool, message: string}  Caller turns this into UI feedback.
 */
function handle_galaxy_update_post(array $post, ?string $userId, bool $isAdmin): array {
    $id = (int)($post['id'] ?? -1);
    if ($id < 0) {
        return ['ok' => false, 'message' => 'Missing galaxy id.'];
    }

    if (!$isAdmin) {
        if ($userId === null || $userId === '') {
            return ['ok' => false, 'message' => 'Not authorized.'];
        }
        $allowed = db_get_user_constellation_ids($userId);
        if (!in_array($id, $allowed, true)) {
            return ['ok' => false, 'message' => 'You do not have access to this galaxy.'];
        }
    }

    $name = trim((string)($post['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Galaxy name is required.'];
    }
    $tagline = trim((string)($post['tagline'] ?? ''));

    $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech'];
    $theme = trim((string)($post['theme'] ?? 'cosmic'));
    if (!in_array($theme, $allowedThemes, true)) {
        $theme = 'cosmic';
    }

    // Slug is admin-only to avoid breaking bookmarked URLs. Editors keep
    // whatever slug the galaxy already has.
    if ($isAdmin) {
        $slug = trim((string)($post['slug'] ?? ''));
        $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
    } else {
        $current = db_get_constellation_by_id($id);
        $finalSlug = ($current && !empty($current['slug'])) ? (string)$current['slug'] : db_slugify($name);
        $slug = $finalSlug;
    }

    $exists = db_constellation_exists($name, $finalSlug, $id);
    if ($exists['name'] || $exists['slug']) {
        $errs = [];
        if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
        if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
        return ['ok' => false, 'message' => 'A galaxy with this ' . implode(' and ', $errs) . ' already exists.'];
    }

    db_update_constellation($id, $name, $tagline, $slug !== '' ? $slug : null, $theme);

    db_set_constellation_tour_config($id, [
        'tour_enabled' => !empty($post['tour_enabled']),
        'tour_start_mode' => (string)($post['tour_start_mode'] ?? 'manual'),
        'tour_idle_seconds' => (int)($post['tour_idle_seconds'] ?? 30),
        'tour_node_selection' => (string)($post['tour_node_selection'] ?? 'all'),
        'tour_random_count' => (int)($post['tour_random_count'] ?? 10),
        'tour_default_dwell' => (int)($post['tour_default_dwell'] ?? 8),
        'tour_loop' => !empty($post['tour_loop']),
        'keyword_chips_enabled' => !empty($post['keyword_chips_enabled']),
    ]);
    $tourKeywordIds = array_map('intval', array_filter((array)($post['tour_keyword_ids'] ?? [])));
    db_set_tour_keyword_ids($id, $tourKeywordIds);

    return ['ok' => true, 'message' => 'Galaxy updated successfully.'];
}
