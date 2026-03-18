<?php
declare(strict_types=1);

/**
 * Bootstrap: config check, DB, auth, project info.
 * Expects to be required from project root (e.g. index.php).
 * Sets: $isEditorOrAdmin, $projectName, $projectTagline, $projectIframeBackText, $projectAlertMessage, $currentLocale
 */

$root = dirname(__DIR__);

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

// Load actual constellation metadata if available
$defaultInfo = db_get_constellation_by_id($constellationId);
if ($defaultInfo) {
    $constellationName = $defaultInfo['name'];
    $constellationTagline = $defaultInfo['tagline'];
    $constellationTheme = $defaultInfo['theme'] ?? 'cosmic';
}

$path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

if (preg_match('/^[0-9]+$/', $path)) {
    $constellationId = (int) $path;
    $constellationInfo = db_get_constellation_by_id($constellationId);
    if ($constellationInfo) {
        $constellationName = $constellationInfo['name'];
        $constellationTagline = $constellationInfo['tagline'];
        $constellationTheme = $constellationInfo['theme'] ?? 'cosmic';
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
    }
} elseif (isset($_GET['constellation_id']) && is_numeric($_GET['constellation_id'])) {
    $constellationId = (int) $_GET['constellation_id'];
    $constellationInfo = db_get_constellation_by_id($constellationId);
    if ($constellationInfo) {
        $constellationName = $constellationInfo['name'];
        $constellationTagline = $constellationInfo['tagline'];
        $constellationTheme = $constellationInfo['theme'] ?? 'cosmic';
    } else {
        $constellationId = $defaultConstellationId;
    }
}

