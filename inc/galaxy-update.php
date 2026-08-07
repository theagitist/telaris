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
        return ['ok' => false, 'message' => t('galaxy_update_missing_id', 'Missing galaxy id.')];
    }

    if (!$isAdmin) {
        if ($userId === null || $userId === '') {
            return ['ok' => false, 'message' => t('galaxy_update_not_authorized', 'Not authorized.')];
        }
        $allowed = db_get_user_constellation_ids($userId);
        if (!in_array($id, $allowed, true)) {
            return ['ok' => false, 'message' => t('galaxy_update_no_access', 'You do not have access to this galaxy.')];
        }
        // A read_only seat may view the galaxy but not change its properties.
        if (!db_user_can_write_constellation($userId, $id)) {
            return ['ok' => false, 'message' => t('galaxy_update_read_only', 'You have read-only access to this galaxy. You can view it but cannot change it.')];
        }
    }

    $name = trim((string)($post['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => t('galaxy_update_name_required', 'Galaxy name is required.')];
    }
    $tagline = trim((string)($post['tagline'] ?? ''));

    $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech', 'light-rainbow', 'rhizome'];
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
        if ($exists['name'] && $exists['slug']) {
            $msg = sprintf(
                t('galaxy_update_duplicate_both', 'A galaxy with the name "%s" and slug "%s" already exists.'),
                htmlspecialchars($name),
                htmlspecialchars($finalSlug)
            );
        } elseif ($exists['name']) {
            $msg = sprintf(
                t('galaxy_update_duplicate_name', 'A galaxy with the name "%s" already exists.'),
                htmlspecialchars($name)
            );
        } else {
            $msg = sprintf(
                t('galaxy_update_duplicate_slug', 'A galaxy with the slug "%s" already exists.'),
                htmlspecialchars($finalSlug)
            );
        }
        return ['ok' => false, 'message' => $msg];
    }

    db_update_constellation($id, $name, $tagline, $slug !== '' ? $slug : null, $theme);

    // Galaxy-level "Allow editors" (admin-only control). The marker is only
    // rendered for admins, so editors never post it; gate on $isAdmin anyway.
    if ($isAdmin && isset($post['editors_enabled_present'])) {
        db_set_constellation_editors_enabled($id, !empty($post['editors_enabled']));
    }

    db_set_constellation_tour_config($id, [
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
    $tourKeywordIds = array_map('intval', array_filter((array)($post['tour_keyword_ids'] ?? [])));
    db_set_tour_keyword_ids($id, $tourKeywordIds);

    // Galaxy tags. Form serializes the chip list as a comma-separated 'tags' field.
    // Absent field = no change (don't wipe existing tags); present-but-empty = clear all.
    if (array_key_exists('tags', $post)) {
        $raw = (string) $post['tags'];
        $labels = $raw === '' ? [] : array_map('trim', explode(',', $raw));
        $labels = array_values(array_filter($labels, fn($l) => $l !== ''));
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $createdBy = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
        db_set_tags_for_galaxy($id, $labels, $createdBy);
    }

    return ['ok' => true, 'message' => t('galaxy_update_success', 'Galaxy updated successfully.')];
}
