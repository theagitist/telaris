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
    return 'js/v' . $appVersion . '/' . $rel;
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

// Detect locale for main app. Supported: en, es, pt. English is default/fallback for any unsupported language.
$currentLocale = 'en';
if (!empty($_GET['lang']) && is_string($_GET['lang'])) {
    $req = strtolower(trim($_GET['lang']));
    // Normalize pt-BR, pt-PT, etc. to pt
    if (str_starts_with($req, 'pt')) {
        $req = 'pt';
    } elseif (str_starts_with($req, 'es')) {
        $req = 'es';
    } elseif (str_starts_with($req, 'en')) {
        $req = 'en';
    }
    if (in_array($req, ['en', 'es', 'pt'], true)) {
        $currentLocale = $req;
    }
} elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    // Parse Accept-Language by preference order (e.g. "pt-BR, pt;q=0.9, en;q=0.8") and pick first supported.
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
    $parts = array_map('trim', explode(',', $accept));
    foreach ($parts as $part) {
        if (preg_match('/^([a-z]{2})(?:[-_][a-z0-9]+)?/i', trim(explode(';', $part)[0]), $m)) {
            $code = strtolower($m[1]);
            if ($code === 'pt' || $code === 'pt-br' || $code === 'pt-pt') {
                $currentLocale = 'pt';
                break;
            }
            if ($code === 'es') {
                $currentLocale = 'es';
                break;
            }
            if ($code === 'en') {
                $currentLocale = 'en';
                break;
            }
        }
    }
}
// Ensure we never use an unsupported locale
if (!in_array($currentLocale, ['en', 'es', 'pt'], true)) {
    $currentLocale = 'en';
}

$projectStrings = db_get_project_info_for_locale($currentLocale);
$projectName = $projectStrings['name'];
$projectTagline = $projectStrings['description'];
$projectIframeBackText = $projectStrings['iframe_back_text'];
$projectAlertMessage = $projectStrings['alert_message'];
$projectEditButtonText = $projectStrings['edit_button_text'] ?? 'Edit';
$projectLoadingText = $projectStrings['loading_text'] ?? 'Loading';
$projectBackButtonText = $projectStrings['back_button_text'] ?? 'Back';
$projectSystemOnlineText = $projectStrings['system_online_text'] ?? 'System: Online';
$projectReloadSystemText = $projectStrings['reload_system_text'] ?? 'Reload System';
$projectScanSystemText = $projectStrings['scan_system_text'] ?? 'SCAN SYSTEM...';
$projectClearScanText = $projectStrings['clear_scan_text'] ?? 'Clear Scan';
$projectSystemsLabelText = $projectStrings['systems_label_text'] ?? 'Systems:';
$projectHyperlinksLabelText = $projectStrings['hyperlinks_label_text'] ?? 'Hyperlinks:';
$projectInitializeAuthText = $projectStrings['initialize_auth_text'] ?? 'Initialize Auth';
$projectAdminLabelText = $projectStrings['admin_label_text'] ?? 'Admin';
$projectLogoutLabelText = $projectStrings['logout_label_text'] ?? 'Logout';
$projectClickToViewText = $projectStrings['click_to_view_text'] ?? 'Click to view';
$projectTapToViewText = $projectStrings['tap_to_view_text'] ?? 'Tap again to view';
$projectOpenPortalText = $projectStrings['open_portal_text'] ?? 'Open the Portal';
$projectSoundLabelText = $projectStrings['sound_label_text'] ?? 'Sound:';
$projectSoundOnText = $projectStrings['sound_on_text'] ?? 'ON';
$projectSoundOffText = $projectStrings['sound_off_text'] ?? 'OFF';
$projectLaunchingText = $projectStrings['launching_text'] ?? 'Launching';
$projectMissionActiveText = $projectStrings['mission_active_text'] ?? 'Mission Active';
$projectGoText = $projectStrings['go_text'] ?? 'GO';
$projectBreadcrumbAllText = $projectStrings['breadcrumb_all_text'] ?? 'All';
$projectLaunchButtonText = $projectStrings['launch_button_text'] ?? 'LAUNCH';
$projectNoResultsText = $projectStrings['no_results_text'] ?? 'No results';
$projectItemsLabelText = $projectStrings['items_label_text'] ?? 'items';
$projectOtherLabelText = $projectStrings['other_label_text'] ?? 'Other';
$defaultConstellationId = $projectStrings['default_constellation_id'] ?? 0;

// Constellation for main view: root URL = default; /{NUMBER} or ?constellation_id=NUMBER = that constellation
// NEW: Support for slugs /{SLUG}
$constellationId = $defaultConstellationId;
$constellationName = $projectName;
$constellationTagline = $projectTagline;
$constellationTheme = 'cosmic';
$constellationSlug = null;

// Load actual constellation metadata if available
$defaultInfo = db_get_constellation_by_id($constellationId);
if ($defaultInfo) {
    $constellationName = $defaultInfo['name'];
    $constellationTagline = $defaultInfo['tagline'];
    $constellationTheme = $defaultInfo['theme'] ?? 'cosmic';
    $constellationSlug = $defaultInfo['slug'] ?? null;
}

$path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

if (preg_match('/^[0-9]+$/', $path)) {
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
    ];
}
$keywordChipsEnabled = !empty($tourConfig['keyword_chips_enabled']);

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

