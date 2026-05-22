<?php
declare(strict_types=1);

/**
 * Bootstrap: config check, DB, auth, project info.
 * Expects to be required from project root (e.g. index.php).
 * Sets: $isEditorOrAdmin, $projectName, $projectTagline, $projectIframeBackText, $projectAlertMessage, $currentLocale
 */

$root = dirname(__DIR__);

// Read version from VERSION file (single source of truth)
$appVersion = trim(@file_get_contents($root . '/VERSION') ?: '0.0.0');

/**
 * Path-based versioned URL for a static JS module.
 * Requires the nginx alias rule: /js/vX.Y.Z/foo.js → /js/foo.js with Cache-Control: immutable.
 * Path-based versioning is what makes Safari reliably refetch ES modules between releases;
 * Safari ignores ?v= query strings for module dedup.
 */
function asset_versioned_js_url(string $appVersion, string $relativePath): string {
    $rel = ltrim($relativePath, '/');
    if (str_starts_with($rel, 'js/')) {
        $rel = substr($rel, 3);
    }
    // Absolute path so it resolves correctly from any URL depth (e.g. visitor permalinks
    // like /{slug}/{node-id} would otherwise break with relative paths).
    return '/js/v' . $appVersion . '/' . $rel;
}

/**
 * Probe the nginx versioned-asset rule once per app version.
 * Caches success forever (per-version file marker); caches failure for 60s in /tmp
 * so we don't hammer ourselves with self-probes when an admin hasn't installed the rule.
 */
function asset_versioned_paths_ok(string $appVersion, string $root): bool {
    static $cached = null;
    if ($cached !== null) return $cached;

    $cacheDir = $root . '/var';
    $safeVer = preg_replace('/[^0-9a-z.-]/', '', strtolower($appVersion));
    $okFile = $cacheDir . '/nginx-paths-' . $safeVer . '.ok';
    $failFile = '/tmp/telaris-nginx-paths-fail-' . md5($root . '|' . $safeVer);

    if (is_file($okFile)) {
        $cached = true;
        return true;
    }
    if (is_file($failFile) && (time() - filemtime($failFile)) < 60) {
        $cached = false;
        return false;
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $probeUrl = 'https://127.0.0.1/js/v' . rawurlencode($appVersion) . '/main.js';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 2,
            'ignore_errors' => true,
            'header' => 'Host: ' . $host,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $headers = @get_headers($probeUrl, false, $ctx);
    $ok = is_array($headers) && !empty($headers[0]) && (bool)preg_match('/\b200\b/', $headers[0]);

    if ($ok) {
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        @file_put_contents($okFile, '1');
        $cached = true;
    } else {
        @file_put_contents($failFile, '0');
        $cached = false;
    }
    return $cached;
}

$nginxVersionedPathsOk = asset_versioned_paths_ok($appVersion, $root);

if (!file_exists($root . '/config.php')) {
    header('Location: admin/setup.php');
    exit();
}

try {
    require_once $root . '/config.php';
    if (empty(DB_HOST) || empty(DB_NAME) || empty(DB_USER)) {
        header('Location: admin/setup.php');
        exit();
    }
    if (!db_has_project_table()) {
        header('Location: admin/setup.php');
        exit();
    }
} catch (PDOException $e) {
    header('Location: admin/setup.php');
    exit();
} catch (Exception $e) {
    header('Location: admin/setup.php');
    exit();
}

require_once $root . '/utils/auth.php';
$isEditorOrAdmin = isEditorOrAdminLoggedIn();

// Detect locale for main app. The supported set is PROJECT_INFO_LOCALES (defined
// in inc/db.php); English is the default and the fallback for any unsupported
// language. To add a new locale (e.g. French), append 'fr' to PROJECT_INFO_LOCALES
// and add the matching block in db_default_project_info_rows(). Nothing else here
// needs to change.
$currentLocale = locale_resolve_from_request($_GET['lang'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);

$projectStrings = db_get_project_info_for_locale($currentLocale);
$projectName = $projectStrings['name'];
$projectTagline = $projectStrings['description'];
$projectIframeBackText = $projectStrings['iframe_back_text'];
$projectAlertMessage = $projectStrings['alert_message'];
$projectEditButtonText = $projectStrings['edit_button_text'] ?? 'edit_button_text';
$projectLoadingText = $projectStrings['loading_text'] ?? 'loading_text';
$projectBackButtonText = $projectStrings['back_button_text'] ?? 'back_button_text';
$projectSystemOnlineText = $projectStrings['system_online_text'] ?? 'system_online_text';
$projectReloadSystemText = $projectStrings['reload_system_text'] ?? 'reload_system_text';
$projectScanSystemText = $projectStrings['scan_system_text'] ?? 'scan_system_text';
$projectClearScanText = $projectStrings['clear_scan_text'] ?? 'clear_scan_text';
$projectSystemsLabelText = $projectStrings['systems_label_text'] ?? 'systems_label_text';
$projectHyperlinksLabelText = $projectStrings['hyperlinks_label_text'] ?? 'hyperlinks_label_text';
$projectInitializeAuthText = $projectStrings['initialize_auth_text'] ?? 'initialize_auth_text';
$projectAdminLabelText = $projectStrings['admin_label_text'] ?? 'admin_label_text';
$projectLogoutLabelText = $projectStrings['logout_label_text'] ?? 'logout_label_text';
$projectClickToViewText = $projectStrings['click_to_view_text'] ?? 'click_to_view_text';
$projectTapToViewText = $projectStrings['tap_to_view_text'] ?? 'tap_to_view_text';
$projectOpenPortalText = $projectStrings['open_portal_text'] ?? 'open_portal_text';
$projectSoundLabelText = $projectStrings['sound_label_text'] ?? 'sound_label_text';
$projectSoundOnText = $projectStrings['sound_on_text'] ?? 'sound_on_text';
$projectSoundOffText = $projectStrings['sound_off_text'] ?? 'sound_off_text';
$projectLaunchingText = $projectStrings['launching_text'] ?? 'launching_text';
$projectMissionActiveText = $projectStrings['mission_active_text'] ?? 'mission_active_text';
$projectGoText = $projectStrings['go_text'] ?? 'go_text';
$projectBreadcrumbAllText = $projectStrings['breadcrumb_all_text'] ?? 'breadcrumb_all_text';
$projectLaunchButtonText = $projectStrings['launch_button_text'] ?? 'launch_button_text';
$projectNoResultsText = $projectStrings['no_results_text'] ?? 'no_results_text';
$projectItemsLabelText = $projectStrings['items_label_text'] ?? 'items_label_text';
$projectOtherLabelText = $projectStrings['other_label_text'] ?? 'other_label_text';
// Multigalaxy + PDF visitor strings (introduced 2026-05-10).
$projectGalaxiesLabelText = $projectStrings['galaxies_label_text'] ?? 'galaxies_label_text';
$projectGalaxyCountSingularText = $projectStrings['galaxy_count_singular_text'] ?? 'galaxy_count_singular_text';
$projectGalaxyCountPluralText = $projectStrings['galaxy_count_plural_text'] ?? 'galaxy_count_plural_text';
$projectPdfLoadingText = $projectStrings['pdf_loading_text'] ?? 'pdf_loading_text';
$projectPdfRenderingText = $projectStrings['pdf_rendering_text'] ?? 'pdf_rendering_text';
$projectPdfPagesSingularText = $projectStrings['pdf_pages_singular_text'] ?? 'pdf_pages_singular_text';
$projectPdfPagesPluralText = $projectStrings['pdf_pages_plural_text'] ?? 'pdf_pages_plural_text';
$projectPdfOpenText = $projectStrings['pdf_open_text'] ?? 'pdf_open_text';
$projectPdfDownloadText = $projectStrings['pdf_download_text'] ?? 'pdf_download_text';
$projectPdfErrorLoadText = $projectStrings['pdf_error_load_text'] ?? 'pdf_error_load_text';
$projectPdfErrorOpenText = $projectStrings['pdf_error_open_text'] ?? 'pdf_error_open_text';
// Tour HUD + share + related + lang switch (visitor-side, introduced 2026-05-22).
$projectTourLabelText = $projectStrings['tour_label_text'] ?? 'tour_label_text';
$projectTourStartAriaText = $projectStrings['tour_start_aria_text'] ?? 'tour_start_aria_text';
$projectTourPreviousAriaText = $projectStrings['tour_previous_aria_text'] ?? 'tour_previous_aria_text';
$projectTourPauseAriaText = $projectStrings['tour_pause_aria_text'] ?? 'tour_pause_aria_text';
$projectTourNextAriaText = $projectStrings['tour_next_aria_text'] ?? 'tour_next_aria_text';
$projectTourExitAriaText = $projectStrings['tour_exit_aria_text'] ?? 'tour_exit_aria_text';
$projectNavToggleAriaText = $projectStrings['nav_toggle_aria_text'] ?? 'nav_toggle_aria_text';
$projectShareLinkTitleText = $projectStrings['share_link_title_text'] ?? 'share_link_title_text';
$projectRelatedLabelText = $projectStrings['related_label_text'] ?? 'related_label_text';
$projectLangLabelText = $projectStrings['lang_label_text'] ?? 'lang_label_text';
// Used by the 3D scene JS (passed via window.TELARIS_*).
$projectNodeNameFallbackText = $projectStrings['node_name_fallback_text'] ?? 'node_name_fallback_text';
$projectUntitledText = $projectStrings['untitled_text'] ?? 'untitled_text';
$projectChipOpenPrefixText = $projectStrings['chip_open_prefix_text'] ?? 'chip_open_prefix_text';
$projectSearchResultText = $projectStrings['search_result_text'] ?? 'search_result_text';
$projectSearchResultsText = $projectStrings['search_results_text'] ?? 'search_results_text';
$defaultConstellationId = $projectStrings['default_constellation_id'] ?? 0;

// Constellation for main view: root URL = default; /{NUMBER} or ?constellation_id=NUMBER = that constellation
// NEW: Support for slugs /{SLUG}
// Multigalaxy: ?galaxies=slug-or-id,slug-or-id,... renders a union of those galaxies
//   in a single 3D scene. Theme defaults to the first listed galaxy's theme; ?theme=<id> overrides.
//   Cross-galaxy connections are detected client-side from shared keyword text.
$constellationId = $defaultConstellationId;
$constellationName = $projectName;
$constellationTagline = $projectTagline;
$constellationTheme = 'cosmic';
$constellationSlug = null;
$multiGalaxyIds = [];        // Non-empty triggers multigalaxy mode in main-view.php / JS
$multiGalaxyTitle = null;    // Synthesized title for the union view

// Load actual constellation metadata if available
$defaultInfo = db_get_constellation_by_id($constellationId);
if ($defaultInfo) {
    $constellationName = $defaultInfo['name'];
    $constellationTagline = $defaultInfo['tagline'];
    $constellationTheme = $defaultInfo['theme'] ?? 'cosmic';
    $constellationSlug = $defaultInfo['slug'] ?? null;
}

// ---------------------------------------------------------------------------
// Multigalaxy resolution (?galaxies=a,b,c)
// ---------------------------------------------------------------------------
// Each entry can be a slug or a numeric ID; unknown entries are silently dropped.
// If the resolved list is non-empty we enter "multi-galaxy mode" and skip the
// single-constellation path-based logic below.
if (!empty($_GET['galaxies']) && is_string($_GET['galaxies'])) {
    $raw = $_GET['galaxies'];
    $tokens = array_filter(array_map('trim', explode(',', $raw)), fn($t) => $t !== '');
    $resolvedMembers = []; // [['id' => int, 'name' => string, 'theme' => string], ...]
    $seenIds = [];
    foreach ($tokens as $token) {
        $info = null;
        $gid = null;
        if (preg_match('/^\d+$/', $token)) {
            $gid = (int) $token;
            $info = db_get_constellation_by_id($gid);
        } else {
            $info = db_get_constellation_by_slug($token);
            // db_get_constellation_by_slug returns 'id'; db_get_constellation_by_id does not.
            if ($info && isset($info['id'])) $gid = (int) $info['id'];
        }
        if (!$info || !$gid) continue;
        if (isset($seenIds[$gid])) continue; // dedupe
        $seenIds[$gid] = true;
        $resolvedMembers[] = [
            'id' => $gid,
            'name' => (string) ($info['name'] ?? ''),
            'theme' => (string) ($info['theme'] ?? 'cosmic'),
        ];
    }
    if (!empty($resolvedMembers)) {
        $multiGalaxyIds = array_column($resolvedMembers, 'id');
        // Theme: first listed galaxy by default; ?theme=<id> overrides if it matches a known theme.
        $constellationTheme = $resolvedMembers[0]['theme'] ?: 'cosmic';
        if (!empty($_GET['theme']) && is_string($_GET['theme'])) {
            $themeReq = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['theme'])));
            $allowedThemes = ['cosmic', 'abstract', 'rectangles', 'stripes', 'tech'];
            if (in_array($themeReq, $allowedThemes, true)) {
                $constellationTheme = $themeReq;
            }
        }
        // Title: "A + B" for two, "A + B + N more" beyond that.
        $names = array_column($resolvedMembers, 'name');
        if (count($names) <= 2) {
            $multiGalaxyTitle = implode(' + ', $names);
        } else {
            $extra = count($names) - 2;
            $multiGalaxyTitle = $names[0] . ' + ' . $names[1] . ' + ' . $extra . ' more';
        }
        $constellationName = $multiGalaxyTitle;
        $constellationTagline = ''; // No single tagline for a union view.
        // Use the first member as the "current" galaxy for things that still need a single ID
        // (tour config, breadcrumb fallback, etc.). The frontend reads $multiGalaxyIds for fetching.
        $constellationId = $resolvedMembers[0]['id'];
        $constellationSlug = null; // No canonical single slug for a union.
    }
}

$path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$initialNodeId = null;

// ---------------------------------------------------------------------------
// Multigalaxy resolution by tag: /tag/foo
// ---------------------------------------------------------------------------
// /tag/<slug> unions every galaxy that carries the tag. Canonical label is the most-used
// label among assigned galaxies (db_get_canonical_label_for_tag); falls back to the slug
// if labels are missing. Reuses the same downstream pipeline as ?galaxies= and /[XXX].
$decodedPathForTag = rawurldecode($path);
if (empty($multiGalaxyIds) && preg_match('#^tag/([^/]+)$#', $decodedPathForTag, $tm)) {
    $tagSlug = db_slugify(trim($tm[1])); // canonicalize the URL token
    if ($tagSlug !== '') {
        $tagMembers = db_get_galaxies_for_tag($tagSlug);
        if (!empty($tagMembers)) {
            $multiGalaxyIds = array_map(fn($m) => (int)$m['id'], $tagMembers);
            $constellationTheme = $tagMembers[0]['theme'] ?: 'cosmic';
            if (!empty($_GET['theme']) && is_string($_GET['theme'])) {
                $themeReq = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['theme'])));
                $allowedThemes = ['cosmic', 'abstract', 'rectangles', 'stripes', 'tech'];
                if (in_array($themeReq, $allowedThemes, true)) {
                    $constellationTheme = $themeReq;
                }
            }
            $canonicalLabel = db_get_canonical_label_for_tag($tagSlug) ?: $tagSlug;
            $multiGalaxyTitle = $canonicalLabel;
            $constellationName = $multiGalaxyTitle;
            $constellationTagline = count($tagMembers) === 1
                ? $projectGalaxyCountSingularText
                : sprintf($projectGalaxyCountPluralText, count($tagMembers));
            $constellationId = $tagMembers[0]['id'];
            $constellationSlug = null;
        }
    }
}

// ---------------------------------------------------------------------------
// Multigalaxy resolution by name prefix: /[XXX]
// ---------------------------------------------------------------------------
// If the path is literally a bracketed token like "[TE]" or "[306]", union every galaxy
// whose name starts with that prefix. Reuses the same downstream pipeline as ?galaxies=.
// parse_url() does NOT URL-decode, so /%5BTE%5D stays as %5BTE%5D — decode explicitly.
$decodedPath = rawurldecode($path);
if (empty($multiGalaxyIds) && preg_match('/^\[([^\]\/]+)\]$/', $decodedPath, $pm)) {
    $prefix = trim($pm[1]);
    if ($prefix !== '') {
        $members = db_get_constellations_by_name_prefix($prefix);
        if (!empty($members)) {
            $multiGalaxyIds = array_map(fn($m) => (int)$m['id'], $members);
            $constellationTheme = $members[0]['theme'] ?: 'cosmic';
            // ?theme=<id> still overrides the inherited theme.
            if (!empty($_GET['theme']) && is_string($_GET['theme'])) {
                $themeReq = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['theme'])));
                $allowedThemes = ['cosmic', 'abstract', 'rectangles', 'stripes', 'tech'];
                if (in_array($themeReq, $allowedThemes, true)) {
                    $constellationTheme = $themeReq;
                }
            }
            $multiGalaxyTitle = '[' . $prefix . ']';
            $constellationName = $multiGalaxyTitle;
            $constellationTagline = count($members) === 1
                ? $projectGalaxyCountSingularText
                : sprintf($projectGalaxyCountPluralText, count($members));
            $constellationId = $members[0]['id'];
            $constellationSlug = null;
        }
    }
}

// Single-constellation path/query parsing is skipped in multigalaxy mode so it can't
// overwrite the synthesized title/theme/IDs we resolved above.
if (empty($multiGalaxyIds)):
// /{galaxy-slug-or-id}/{node-id} — open a specific wormhole on load.
if (preg_match('#^([^/]+)/([0-9]+)$#', $path, $m)) {
    $first = $m[1];
    $initialNodeId = (int) $m[2];
    if (preg_match('/^[0-9]+$/', $first)) {
        $constellationId = (int) $first;
        $info = db_get_constellation_by_id($constellationId);
        if ($info) {
            $constellationName = $info['name'];
            $constellationTagline = $info['tagline'];
            $constellationTheme = $info['theme'] ?? 'cosmic';
            $constellationSlug = $info['slug'] ?? null;
        } else {
            $constellationId = $defaultConstellationId;
            $initialNodeId = null;
        }
    } else {
        $info = db_get_constellation_by_slug($first);
        if ($info) {
            $constellationId = $info['id'];
            $constellationName = $info['name'];
            $constellationTagline = $info['tagline'];
            $constellationTheme = $info['theme'] ?? 'cosmic';
            $constellationSlug = $first;
        } else {
            $initialNodeId = null;
        }
    }
} elseif (preg_match('/^[0-9]+$/', $path)) {
    $constellationId = (int) $path;
    $constellationInfo = db_get_constellation_by_id($constellationId);
    if ($constellationInfo) {
        $constellationName = $constellationInfo['name'];
        $constellationTagline = $constellationInfo['tagline'];
        $constellationTheme = $constellationInfo['theme'] ?? 'cosmic';
        $constellationSlug = $constellationInfo['slug'] ?? null;
    } else {
        $constellationId = $defaultConstellationId;
    }
} elseif ($path !== '' && !str_contains($path, '.')) {
    // Attempt to match as a slug
    $constellationInfo = db_get_constellation_by_slug($path);
    if ($constellationInfo) {
        $constellationId = $constellationInfo['id'];
        $constellationName = $constellationInfo['name'];
        $constellationTagline = $constellationInfo['tagline'];
        $constellationTheme = $constellationInfo['theme'] ?? 'cosmic';
        $constellationSlug = $path;
    }
} elseif (isset($_GET['constellation_id']) && is_numeric($_GET['constellation_id'])) {
    $constellationId = (int) $_GET['constellation_id'];
    $constellationInfo = db_get_constellation_by_id($constellationId);
    if ($constellationInfo) {
        $constellationName = $constellationInfo['name'];
        $constellationTagline = $constellationInfo['tagline'];
        $constellationTheme = $constellationInfo['theme'] ?? 'cosmic';
        $constellationSlug = $constellationInfo['slug'] ?? null;
    } else {
        $constellationId = $defaultConstellationId;
    }
}

// Cluster pivot: if the chain landed on a cluster-typed row, swap to multigalaxy mode using
// its members. The cluster keeps its own name / tagline / theme / slug — they were already set
// above by the lookup. Only $multiGalaxyIds + $multiGalaxyTitle need populating.
$_resolvedInfo = db_get_constellation_by_id((int) $constellationId);
if ($_resolvedInfo && ($_resolvedInfo['type'] ?? 'galaxy') === 'cluster') {
    $memberIds = db_get_cluster_member_ids((int) $constellationId);
    if (!empty($memberIds)) {
        $multiGalaxyIds = $memberIds;
        $multiGalaxyTitle = $constellationName;
        // ?theme=<id> override (cluster's own theme already applied via the chain).
        if (!empty($_GET['theme']) && is_string($_GET['theme'])) {
            $themeReq = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['theme'])));
            $allowedThemes = ['cosmic', 'abstract', 'rectangles', 'stripes', 'tech'];
            if (in_array($themeReq, $allowedThemes, true)) {
                $constellationTheme = $themeReq;
            }
        }
    }
    // Empty-cluster case: render as a normal (empty) constellation; the visitor sees no nodes.
}
endif; // empty($multiGalaxyIds)

$tourConfig = db_get_constellation_tour_config((int) $constellationId);
if ($tourConfig === null) {
    $tourConfig = [
        'tour_enabled' => false,
        'tour_start_mode' => 'manual',
        'tour_idle_seconds' => 30,
        'tour_node_selection' => 'all',
        'tour_random_count' => 10,
        'tour_default_dwell' => 8,
        'tour_loop' => true,
        'tour_keyword_ids' => [],
        'keyword_chips_enabled' => false,
        'idle_spotlight_enabled' => false,
        'idle_spotlight_selection' => 'all',
        'idle_spotlight_idle_seconds' => 30,
        'related_nodes_enabled' => false,
        'show_2d_view' => false,
    ];
}
$keywordChipsEnabled = !empty($tourConfig['keyword_chips_enabled']);
$relatedNodesEnabled = !empty($tourConfig['related_nodes_enabled']);
$show2dView = !empty($tourConfig['show_2d_view']);

// ?node=ID query-string fallback for permalinks (when path-based form isn't usable).
if ($initialNodeId === null && isset($_GET['node']) && ctype_digit((string)$_GET['node'])) {
    $initialNodeId = (int) $_GET['node'];
}
$idleSpotlightConfig = [
    'enabled' => !empty($tourConfig['idle_spotlight_enabled']),
    'selection' => (string)($tourConfig['idle_spotlight_selection'] ?? 'all'),
    'idle_seconds' => (int)($tourConfig['idle_spotlight_idle_seconds'] ?? 30),
    'default_dwell' => (int)($tourConfig['tour_default_dwell'] ?? 8),
];

// Frontend matches keywords by string (node.userData.keywords is an array of strings),
// so resolve the configured keyword IDs to names within this constellation.
$tourKeywordNames = [];
if (!empty($tourConfig['tour_keyword_ids']) && $tourConfig['tour_node_selection'] === 'tagged') {
    $availableKeywords = db_get_keywords_for_constellation((int) $constellationId);
    $idSet = array_flip($tourConfig['tour_keyword_ids']);
    foreach ($availableKeywords as $kw) {
        if (isset($idSet[$kw['id']])) {
            $tourKeywordNames[] = $kw['keyword'];
        }
    }
}
$tourConfig['tour_keyword_names'] = $tourKeywordNames;

// Multigalaxy mode splits two ways:
//   - Emergent unions (?galaxies=, /[XX], /tag/) have no owning row. Per-galaxy
//     discovery features can't attach to anything, so auto-tour / idle spotlight /
//     related-wormholes are off. Keyword chips are the exception (see below).
//   - Cluster unions are first-class rows in `constellations`; their own
//     discovery config drives the visitor experience the same way a single
//     galaxy's does. Tour/idle/related/chips all flow from the cluster row.
//
// Keyword chips, in either branch, pool from every visible wormhole — the scene's
// content *is* the chip vocabulary, so there are no dead chips. For emergent
// unions, opt-in is "any member galaxy has the flag"; for clusters, the cluster's
// own flag is authoritative.
if (!empty($multiGalaxyIds)) {
    $_currentInfoForUnion = db_get_constellation_by_id((int) $constellationId);
    $_isClusterUnion = $_currentInfoForUnion && ($_currentInfoForUnion['type'] ?? 'galaxy') === 'cluster';

    if (!$_isClusterUnion) {
        // Emergent union: wipe per-galaxy discovery features.
        $tourConfig['tour_enabled'] = false;
        $tourConfig['related_nodes_enabled'] = false;
        $relatedNodesEnabled = false;
        $idleSpotlightConfig['enabled'] = false;

        // Batch every member galaxy's tour config in one round trip; pre-fix
        // this fan-out fired (2 queries × N members) per visitor scene-load.
        $unionMemberIds = array_values(array_filter(array_map('intval', $multiGalaxyIds), fn($v) => $v !== (int)$constellationId));
        $firstId = (int)($multiGalaxyIds[0] ?? 0);
        $idsToFetch = $unionMemberIds;
        if ($firstId > 0 && !in_array($firstId, $idsToFetch, true)) {
            $idsToFetch[] = $firstId;
        }
        $memberCfgs = $idsToFetch === [] ? [] : db_get_tour_configs_for_ids($idsToFetch);

        $chipsOn = !empty($tourConfig['keyword_chips_enabled']);
        if (!$chipsOn) {
            foreach ($unionMemberIds as $mid) {
                $memberCfg = $memberCfgs[$mid] ?? null;
                if ($memberCfg !== null && !empty($memberCfg['keyword_chips_enabled'])) {
                    $chipsOn = true;
                    break;
                }
            }
        }
        $tourConfig['keyword_chips_enabled'] = $chipsOn;
        $keywordChipsEnabled = $chipsOn;

        // show_2d_view: use the FIRST member galaxy's setting (per Adri's
        // spec). The "first" galaxy is the head of $multiGalaxyIds, which
        // reflects whatever order the route resolved (prefix lookup, tag
        // join order, explicit ?galaxies=).
        if ($firstId > 0) {
            $firstCfg = $memberCfgs[$firstId] ?? null;
            $show2dView = $firstCfg !== null && !empty($firstCfg['show_2d_view']);
            $tourConfig['show_2d_view'] = $show2dView;
        }
    }
    // Cluster branch: keep $tourConfig, $idleSpotlightConfig, $relatedNodesEnabled,
    // $keywordChipsEnabled, $show2dView as already loaded from the cluster's own row.
}

// Galaxy-list strip (visitor multigalaxy filter). Default ON for emergent unions
// (?galaxies=, /[XX], /tag/) and OFF for cluster type unless the cluster opts in.
$galaxyListEnabled = false;
$galaxyListMembers = [];
if (!empty($multiGalaxyIds)) {
    $clusterInfo = db_get_constellation_by_id((int) $constellationId);
    $isCluster = $clusterInfo && ($clusterInfo['type'] ?? 'galaxy') === 'cluster';
    if ($isCluster) {
        $galaxyListEnabled = !empty($clusterInfo['show_galaxy_list']);
    } else {
        $galaxyListEnabled = true;
    }
    if ($galaxyListEnabled) {
        // Batch every member galaxy's row in one round trip; pre-fix this was
        // one query per member which N+1'd the visitor scene-load.
        $infosById = db_get_constellations_by_ids(array_map('intval', $multiGalaxyIds));
        foreach ($multiGalaxyIds as $gid) {
            $info = $infosById[(int)$gid] ?? null;
            if (!$info) continue;
            $galaxyListMembers[] = [
                'id' => (int) $gid,
                'name' => (string) ($info['name'] ?? ''),
                'slug' => $info['slug'] ?? null,
                'theme' => (string) ($info['theme'] ?? 'cosmic'),
            ];
        }
    }
}

// Keyword view: which galaxy's canvas should the "Keyword view" menu item
// open? The keyword canvas is strictly per-galaxy (clusters reject with 400),
// so in any multi-galaxy context we route to the FIRST member galaxy — same
// rule as show_2d_view above.
$keywordViewGalaxyId = !empty($multiGalaxyIds)
    ? (int) $multiGalaxyIds[0]
    : (int) $constellationId;

