<?php
declare(strict_types=1);

// Set Content-Type header
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../utils/auth.php';
requireEditorOrAdminLogin();

// First-login consent gate: an editor who has not accepted the current Terms +
// Privacy versions is sent to the consent page before reaching the editor. No-op
// when not enforced or for admins (BACKLOG ^consent-gate-first-login).
require_once __DIR__ . '/../inc/consent.php';
consent_gate_or_redirect('../');

// Per-request nonce for the (currently report-only) strict CSP. The enforced
// CSP keeps 'unsafe-inline' on script-src because edit/index.php carries
// ~76 inline event handlers (onclick=, onsubmit=, etc.). Migrating them to
// addEventListener is queued separately as a UI refactor; the Report-Only
// header below collects what would break before the flip, written to
// api/csp-report.php (which logs to error_log).
$cspEditNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' 'nonce-{$cspEditNonce}' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; report-uri /api/csp-report.php");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/../config.php';

$appVersion = trim(@file_get_contents(__DIR__ . '/../VERSION') ?: '0.0.0');

// Establish per-session CSRF token; mirrored to JS as CSRF_TOKEN further down.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$galaxyEditMessage = null;
$galaxyEditError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_constellation') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        $galaxyEditError = t('auth_error_invalid_request', 'Invalid request. Please reload the page and try again.');
    } else {
        require_once __DIR__ . '/../inc/galaxy-update.php';
        $result = handle_galaxy_update_post(
            $_POST,
            $_SESSION['admin_user_id'] ?? null,
            isAdminLoggedIn()
        );
        if ($result['ok']) {
            $galaxyEditMessage = $result['message'];
        } else {
            $galaxyEditError = $result['message'];
        }
    }
    // The Edit Galaxy modal auto-saves: it POSTs the same form with ajax=1 and
    // expects JSON instead of a full-page re-render. Emit it and stop here.
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        if ($galaxyEditError !== null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $galaxyEditError], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => true, 'message' => $galaxyEditMessage ?? ''], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

$pdo = getDB();

// Get default API key for API calls (using function from config.php)
$apiKey = getDefaultApiKey($pdo);

// Editors see only constellations assigned to them; admins see all
$currentUserId = $_SESSION['admin_user_id'] ?? null;
$isAdmin = isAdminLoggedIn();
$constellations = db_get_constellations_for_user($currentUserId, $isAdmin);

// Group constellations by [Tag] prefix for visual grouping
function extractConstellationGroup(string $name): ?string {
    if (preg_match('/^\[([^\]]+)\]/', $name, $m)) {
        return $m[1];
    }
    return null;
}

// Dark-console group tints: faint hue-tinted near-Void plates that cluster
// related galaxies without reading as white on the dark console chrome.
$pastelPalette = [
    '#301a1d', '#1a3020', '#1a2233', '#302612', '#251a38',
    '#143030', '#30301a', '#301a26', '#26262e', '#24301a',
];
$constellationGroupColors = [];
$groupColorIndex = 0;

usort($constellations, function ($a, $b) {
    $ga = extractConstellationGroup($a['name']);
    $gb = extractConstellationGroup($b['name']);
    if ($ga !== null && $gb === null) return -1;
    if ($ga === null && $gb !== null) return 1;
    if ($ga !== null && $gb !== null && $ga !== $gb) return strcasecmp($ga, $gb);
    return strcasecmp($a['name'], $b['name']);
});

foreach ($constellations as $c) {
    $group = extractConstellationGroup($c['name']);
    if ($group !== null && !isset($constellationGroupColors[$group])) {
        $constellationGroupColors[$group] = $pastelPalette[$groupColorIndex % count($pastelPalette)];
        $groupColorIndex++;
    }
}

// Page title only (Global Settings are in Admin)
$projectInfoEn = db_get_project_info_for_locale('en');
$projectName = $projectInfoEn['name'] ?? 'Telaris';

$userName = $_SESSION['admin_user_name'] ?? 'User';
$userType = (int)($_SESSION['admin_user_type'] ?? 0);
$isAdmin = isAdminLoggedIn(); // Explicitly check if user is admin (type 2 only)
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?= t_attr('editor_page_title', 'Edit Wormholes') ?> - <?php echo htmlspecialchars($projectName); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="/css/vendor/daisyui-4.12.10.full.min.css" rel="stylesheet" type="text/css" />
    <?php include __DIR__ . '/../inc/admin-console-theme.php'; ?>
</head>
<body class="font-sans bg-gray-100 min-h-screen">
    <div class="max-w-7xl mx-auto py-8 px-5">
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-gray-800 text-3xl font-semibold"><?= t_attr('editor_page_title', 'Edit Wormholes') ?></h1>
                    <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($userName); ?> (<?= $isAdmin ? t('editor_user_role_admin', 'Admin') : t('editor_user_role_editor', 'Editor') ?>)</p>
                </div>
                <div class="flex items-center gap-2">
                    <label for="current-constellation" class="text-sm font-medium text-gray-700"><?= t_attr('editor_label_current_galaxy', 'Current Galaxy:') ?></label>
                    <div class="join">
                        <select id="current-constellation" 
                                onchange="switchConstellation(this.value)"
                                class="select select-bordered select-sm min-w-[180px] bg-white join-item">
                            <?php
                            // Resolve current galaxy from ?constellation_id=N or ?slug=foo (slug takes precedence).
                            $currentConstellationParam = 'all';
                            if (isset($_GET['slug']) && is_string($_GET['slug']) && trim((string)$_GET['slug']) !== '') {
                                $slugLookup = trim((string)$_GET['slug']);
                                $resolved = db_get_constellation_by_slug($slugLookup);
                                if ($resolved && isset($resolved['id'])) {
                                    $currentConstellationParam = (string)(int)$resolved['id'];
                                }
                            } elseif (isset($_GET['constellation_id']) && is_numeric($_GET['constellation_id'])) {
                                $currentConstellationParam = trim((string)$_GET['constellation_id']);
                            }
                            ?>
                            <option value="all"<?php echo $currentConstellationParam === 'all' ? ' selected' : ''; ?>><?= $isAdmin ? t('editor_option_all_galaxies_admin', 'All galaxies') : t('editor_option_all_galaxies_editor', 'All my galaxies') ?></option>
                            <?php
                            $currentOptgroup = null;
                            $inOptgroup = false;
                            foreach ($constellations as $c):
                                $cid = (int)$c['id'];
                                $sel = $currentConstellationParam === (string)$cid ? ' selected' : '';
                                $g = extractConstellationGroup($c['name']);
                                if ($g !== $currentOptgroup) {
                                    if ($inOptgroup) { echo '</optgroup>'; $inOptgroup = false; }
                                    if ($g !== null) { echo '<optgroup label="' . htmlspecialchars($g) . '">'; $inOptgroup = true; }
                                    $currentOptgroup = $g;
                                }
                            ?>
                                <option value="<?php echo $cid; ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach;
                            if ($inOptgroup) echo '</optgroup>';
                            ?>
                        </select>
                        <button type="button" onclick="viewNetwork()" class="btn btn-sm btn-neutral join-item">
                            <?= t_attr('editor_btn_view', 'View') ?>
                        </button>
                        <button type="button" id="galaxy-settings-btn" onclick="openCurrentGalaxySettings()" class="btn btn-sm btn-outline join-item" title="<?= t_attr('editor_btn_galaxy_settings_title', 'Galaxy settings') ?>" style="display:none;">
                            <?= t_attr('editor_btn_settings', 'Settings') ?>
                        </button>
                        <button type="button" id="galaxy-canvas-btn" onclick="openCurrentGalaxyKeywordCanvas()" class="btn btn-sm btn-outline join-item" title="<?= t_attr('editor_btn_keyword_canvas_title', 'Author keyword relationships') ?>" style="display:none;">
                            <?= t_attr('editor_btn_canvas', 'Canvas') ?>
                        </button>
                        <button type="button" onclick="copyCurrentConstellationUrl(this)" class="btn btn-sm btn-outline join-item" title="<?= t_attr('editor_btn_copy_url_title', 'Copy galaxy URL') ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="flex gap-3">
                    <?php if ($isAdmin): ?>
                    <a href="../admin/index.php" class="btn btn-neutral">
                        <?= t_attr('editor_btn_admin_console', 'Admin Console') ?>
                    </a>
                    <?php endif; ?>
                    <a href="../utils/logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded">
                        <?= t_attr('editor_btn_logout', 'Logout') ?>
                    </a>
                </div>
            </div>
        </div>

        <?php if (!$apiKey): ?>
        <div class="mb-5 p-4 bg-red-50 border-2 border-red-500 rounded">
            <p class="text-red-800 font-semibold"><?= t_attr('editor_error_no_api_key', '⚠️ Error: No active API key found. Please contact an administrator.') ?></p>
        </div>
        <?php endif; ?>


        <!-- Messages -->
        <div id="notification-container" class="fixed top-4 left-1/2 -translate-x-1/2 z-[2000] flex flex-col gap-2 w-full max-w-md pointer-events-none"></div>
        <div id="message" class="hidden"></div> <!-- Legacy hidden div to avoid JS errors if referenced elsewhere -->

        <!-- Bulk Actions Bar -->
        <div id="bulk-actions-bar" class="hidden sticky top-4 z-[30] bg-neutral text-neutral-content p-4 rounded-lg shadow-xl mb-6 flex items-center justify-between transition-all">
            <div class="flex items-center gap-4">
                <span class="font-bold"><span id="selected-count">0</span> <?= t_attr('editor_bulk_selected_suffix', 'wormholes selected') ?></span>
                <div class="h-6 w-px bg-neutral-content/30"></div>
                <button onclick="clearSelection()" class="btn btn-sm btn-ghost normal-case font-normal hover:bg-white/10"><?= t_attr('editor_btn_clear_selection', 'Clear Selection') ?></button>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="openBulkMoveModal()" class="btn btn-sm btn-outline text-white border-white/30 hover:bg-white/10 hover:border-white"><?= t_attr('editor_btn_bulk_move', 'Move Selected') ?></button>
                <button onclick="openBulkDuplicateModal()" class="btn btn-sm btn-outline text-white border-white/30 hover:bg-white/10 hover:border-white"><?= t_attr('editor_btn_bulk_duplicate', 'Duplicate Selected') ?></button>
                <button onclick="bulkDelete()" class="btn btn-sm btn-error text-white"><?= t_attr('editor_btn_bulk_delete', 'Delete Selected') ?></button>
            </div>
        </div>

        <!-- Nodes List -->
        <div id="read-only-banner" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4 text-yellow-800 text-sm" style="display: none;">
            <span id="read-only-banner-text"><?= t_attr('editor_banner_imported_read_only', 'This galaxy was imported from an external source and is read-only. Use the Refresh action in the admin galaxy list to sync changes.') ?></span>
        </div>
        <div class="bg-white rounded-lg shadow-md mb-6">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <h2 class="text-gray-800 text-xl font-semibold"><?= t_attr('editor_heading_wormholes', 'Wormholes') ?> (<span id="tab-list-count">0</span>)</h2>
                        <button type="button" onclick="openCreateNodeModal()" class="node-edit-action text-blue-600 hover:text-blue-800 font-medium text-base"><?= t_attr('editor_btn_new_wormhole', 'New Wormhole') ?></button>
                        <button type="button" id="filter-touched-today-btn" onclick="toggleTouchedTodayFilter()" class="text-xs px-2.5 py-1 rounded-full border border-gray-300 text-gray-600 hover:border-gray-500 transition" title="<?= t_attr('editor_btn_touched_today_title', 'Show only wormholes touched today') ?>"><?= t_attr('editor_btn_touched_today', 'Touched today') ?></button>
                        <button type="button" onclick="openBulkByKeywordModal()" id="bulk-by-keyword-btn" class="text-xs px-2.5 py-1 rounded-full border border-gray-300 text-gray-600 hover:border-gray-500 transition" title="<?= t_attr('editor_btn_bulk_keyword_title', 'Bulk delete or move every wormhole in this galaxy carrying a chosen keyword') ?>"><?= t_attr('editor_btn_bulk_by_keyword', 'Bulk by keyword…') ?></button>
                        <button type="button" onclick="document.getElementById('shortcuts_modal').showModal()" class="text-xs px-2.5 py-1 rounded-full border border-gray-300 text-gray-600 hover:border-gray-500 transition" title="<?= t_attr('editor_btn_shortcuts_title', 'Keyboard shortcuts (? to open)') ?>">?</button>
                    </div>

                    <!-- Top Pagination Container -->
                    <div id="nodes-pagination-header" class="flex-1 flex justify-center"></div>

                    <div class="flex items-center gap-2 min-w-[300px]">
                        <label for="search-nodes" class="text-sm font-medium text-gray-700"><?= t_attr('editor_label_search', 'Search:') ?></label>
                        <input type="text"
                               id="search-nodes"
                               placeholder="<?= t_attr('editor_placeholder_search_wormholes', 'Search wormholes...') ?>"
                               oninput="debouncedSearch()"
                               class="flex-1 p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    </div>
                </div>
            </div>

            <!-- List Existing Nodes Content -->
            <div id="content-list" class="custom-tab-panel p-6">
                <div id="nodes-list" class="space-y-0">
                    <!-- Header row -->
                    <div class="border-b-2 border-gray-400 bg-gray-100 py-2 mb-1 sticky top-0 z-10">
                        <div class="grid grid-cols-12 gap-3 text-xs font-semibold text-gray-700">
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('name')">
                                <?= t_attr('editor_col_name', 'Name') ?><span id="sort-indicator-name"></span>
                            </div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('node_type')">
                                <?= t_attr('editor_col_type', 'Type') ?><span id="sort-indicator-node_type"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('constellation_name')">
                                <?= t_attr('editor_col_galaxy', 'Galaxy') ?><span id="sort-indicator-constellation_name"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('url')"><?= t_attr('editor_col_url', 'URL') ?><span id="sort-indicator-url"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('keywords')">
                                <?= t_attr('editor_col_keywords', 'Keywords') ?><span id="sort-indicator-keywords"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('created_at')">
                                <?= t_attr('editor_col_created', 'Created') ?><span id="sort-indicator-created_at"></span>
                            </div>
                            <div class="col-span-1 text-right"><?= t_attr('editor_col_actions', 'Actions') ?></div>
                        </div>
                    </div>
                    <p class="text-gray-500 p-4" id="loading-message"><?= t_attr('editor_msg_loading_wormholes', 'Loading wormholes...') ?></p>
                </div>
            </div>

        </div>
    </div>

    <script>
        const API_KEY = <?php echo $apiKey !== null ? json_encode($apiKey, JSON_THROW_ON_ERROR) : 'null'; ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
        window.TELARIS_CSRF_TOKEN = CSRF_TOKEN;
        const API_BASE = '../api/nodes.php';
        const CONSTELLATIONS_API = '../api/constellations.php';
        const CONSTELLATIONS = <?php echo json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'import_source' => $c['import_source'] ?? null], $constellations), JSON_THROW_ON_ERROR); ?>;
        const READ_ONLY_BANNER_GENERIC = <?php echo json_encode((string)t('editor_banner_imported_read_only', 'This galaxy was imported from an external source and is read-only. Use the Refresh action in the admin galaxy list to sync changes.'), JSON_THROW_ON_ERROR); ?>;
        const READ_ONLY_BANNER_MIRROR_FEDERATION = <?php echo json_encode((string)t('editor_banner_mirror_federation', 'This galaxy is mirrored from %s and is read-only. Updates flow via the galaxy-pull cron, or use Refresh galaxies now in the admin Pluriverse tab.'), JSON_THROW_ON_ERROR); ?>;

        // Localized strings consumed by inline JS. Mirrors the visitor-side
        // window.TELARIS_* pattern; bundled in one object to avoid declaring
        // ~80 PHP variables for every key.
        window.TELARIS_EDIT = <?= json_encode([
            // Loading + retrieval states
            'msgRetrieving' => t('editor_msg_retrieving_wormholes', 'Retrieving wormholes...'),
            'errorApiKeyMissingFetch' => t('editor_error_api_key_missing_fetch', 'Error: API key is missing. Please contact an administrator.'),
            'errorApiKeyMissing' => t('editor_error_api_key_missing', 'API key is missing.'),
            'errorInvalidJson' => t('editor_error_invalid_json', 'Invalid JSON response from server'),
            'errorInvalidFormat' => t('editor_error_invalid_format', 'Invalid response format'),
            'errorInvalidDataFormat' => t('editor_error_invalid_data_format', 'Error: Invalid data format received.'),
            'headingErrorLoading' => t('editor_heading_error_loading', 'Error loading wormholes'),
            'headingNoWormholes' => t('editor_heading_no_wormholes', 'No wormholes found.'),
            'textEmptyStateHelp' => t('editor_text_empty_state_help', 'Try adjusting your search or add a new wormhole to get started.'),
            'errorFatalLoading' => t('editor_error_fatal_loading', 'Fatal error loading wormholes: %s'),
            'errorCouldNotLoad' => t('editor_error_could_not_load', 'Error: Could not load wormholes. %s'),
            // Row template
            'textNoKeywords' => t('editor_text_no_keywords', 'No keywords'),
            'labelTypePortal' => t('editor_label_node_type_portal', 'Portal'),
            'labelTypeObject' => t('editor_label_node_type_object', 'Object'),
            'badgeAcc' => t('editor_badge_accentuated', 'ACC'),
            'badgeAccTitle' => t('editor_badge_accentuated_title', 'Accentuated Wormhole'),
            'badgeUrl' => t('editor_badge_has_url', 'URL'),
            'badgeUrlTitle' => t('editor_badge_has_url_title', 'Has URL'),
            'badgeDesc' => t('editor_badge_has_desc', 'DESC'),
            'badgeDescTitle' => t('editor_badge_has_desc_title', 'Has Description'),
            'badgeImg' => t('editor_badge_has_img', 'IMG'),
            'badgeImgTitle' => t('editor_badge_has_img_title', 'Has Image'),
            'badgeEmb' => t('editor_badge_has_emb', 'EMB'),
            'badgeEmbTitle' => t('editor_badge_has_emb_title', 'Has Embed'),
            'badgeAud' => t('editor_badge_has_aud', 'AUD'),
            'badgeAudTitle' => t('editor_badge_has_aud_title', 'Has Audio'),
            'badgeVid' => t('editor_badge_has_vid', 'VID'),
            'badgeVidTitle' => t('editor_badge_has_vid_title', 'Has Video'),
            'titleAccentuated' => t('editor_title_accentuated', 'Accentuated'),
            'colName' => t('editor_col_name', 'Name'),
            'colType' => t('editor_col_type', 'Type'),
            'colGalaxy' => t('editor_col_galaxy', 'Galaxy'),
            'colKeywords' => t('editor_col_keywords', 'Keywords'),
            'colAcc' => t('editor_col_acc', 'Acc'),
            'colAccTitle' => t('editor_col_acc_title', 'Accentuated Status'),
            'colCreated' => t('editor_col_created', 'Created'),
            'colUpdated' => t('editor_col_updated', 'Updated'),
            'colActions' => t('editor_col_actions', 'Actions'),
            // Row actions
            'actionViewWormhole' => t('editor_action_view_wormhole', 'View Wormhole'),
            'actionViewGalaxy' => t('editor_action_view_galaxy', 'View Galaxy'),
            'actionEdit' => t('editor_action_edit', 'Edit'),
            'actionDuplicate' => t('editor_action_duplicate', 'Duplicate'),
            'actionDelete' => t('editor_action_delete', 'Delete'),
            // Bulk action toasts (use %d as placeholder)
            'toastBulkMoveSuccess' => t('editor_toast_bulk_move_success', 'Successfully moved %d wormholes.'),
            'toastBulkMoveFailed' => t('editor_toast_bulk_move_failed', 'Failed to move %d wormholes.'),
            'toastBulkMoveError' => t('editor_toast_bulk_move_error', 'An error occurred during bulk move.'),
            'toastDuplicateSuccess' => t('editor_toast_duplicate_success', 'Wormhole duplicated successfully.'),
            'errorFailedDuplicate' => t('editor_error_failed_duplicate', 'Failed to duplicate'),
            'toastDuplicateErrorGeneric' => t('editor_toast_duplicate_error_generic', 'An error occurred while duplicating.'),
            'toastBulkDuplicateSuccess' => t('editor_toast_bulk_duplicate_success', 'Successfully duplicated %d wormholes.'),
            'toastBulkDuplicateFailed' => t('editor_toast_bulk_duplicate_failed', 'Failed to duplicate %d wormholes.'),
            'toastBulkDuplicateError' => t('editor_toast_bulk_duplicate_error', 'An error occurred during bulk duplicate.'),
            'confirmBulkDelete' => t('editor_confirm_bulk_delete', 'Are you sure you want to delete %d selected wormholes? This action cannot be undone.'),
            'toastBulkDeleteSuccess' => t('editor_toast_bulk_delete_success', 'Successfully deleted %d wormholes.'),
            'toastBulkDeleteFailed' => t('editor_toast_bulk_delete_failed', 'Failed to delete %d wormholes.'),
            'toastBulkDeleteError' => t('editor_toast_bulk_delete_error', 'An error occurred during bulk deletion.'),
            'toastUrlCopied' => t('editor_toast_url_copied', 'URL copied to clipboard'),
            'titleUrlCopied' => t('editor_title_url_copied', 'Copied!'),
            'titleCopyUrlDefault' => t('editor_btn_copy_url_title', 'Copy galaxy URL'),
            // Galaxy creation
            'toastGalaxyCreated' => t('editor_toast_galaxy_created', 'Galaxy "%s" created.'),
            'toastErrorCreatingGalaxy' => t('editor_toast_error_creating_galaxy', 'Error creating galaxy: %s'),
            'promptNewGalaxyName' => t('editor_prompt_new_galaxy_name', 'Name of the new galaxy:'),
            // Move/Duplicate modal dynamic descriptions
            'textMoveCountWormholes' => t('editor_text_move_count_wormholes', 'Move %d selected wormholes to another galaxy.'),
            'textDuplicateTo' => t('editor_text_duplicate_to', 'Duplicate "%s" to:'),
            'textDuplicateCountWormholes' => t('editor_text_duplicate_count_wormholes', 'Duplicate %d selected wormholes to:'),
            'labelTargetPrefix' => t('editor_label_target_prefix', 'Target:'),
            // Node CRUD toasts
            'toastUpdatedSuccess' => t('editor_toast_updated_successfully', 'Wormhole updated successfully'),
            'toastCreatedSuccess' => t('editor_toast_created_successfully', 'Wormhole created successfully'),
            'errorFailedUpdate' => t('editor_error_failed_update', 'Failed to update wormhole'),
            'errorFailedCreate' => t('editor_error_failed_create', 'Failed to create wormhole'),
            'errorNetworkUpload' => t('editor_error_network_upload', 'Network error occurred during upload'),
            'errorNameRequired' => t('editor_error_name_required', 'Wormhole name is required'),
            'autosaveSaving' => t('editor_autosave_saving', 'Saving…'),
            'autosaveSaved' => t('editor_autosave_saved', 'All changes saved'),
            'autosaveFailed' => t('editor_autosave_failed', 'Save failed; keep editing to retry'),
            'errorLoadingNode' => t('editor_error_loading_node', 'Error loading wormhole: %s'),
            'confirmDeleteFile' => t('editor_confirm_delete_file', 'Are you sure you want to delete this uploaded %s file?'),
            'toastFileDeleted' => t('editor_toast_file_deleted', '%s file deleted'),
            'errorDeletingFile' => t('editor_error_deleting_file', 'Error deleting file: %s'),
            'confirmDeleteNode' => t('editor_confirm_delete_node', 'Are you sure you want to delete "%s"? This action cannot be undone.'),
            'errorDeleteWormhole' => t('editor_error_delete_wormhole', 'Failed to delete wormhole'),
            'toastDeletedSuccess' => t('editor_toast_deleted_successfully', 'Wormhole deleted successfully'),
            'errorDeletingWormhole' => t('editor_error_deleting_wormhole', 'Error deleting wormhole: %s'),
            // Bulk-by-keyword modal
            'optionLoading' => t('editor_option_loading', 'Loading…'),
            'optionNoKeywords' => t('editor_option_no_keywords', '(no keywords in this galaxy)'),
            'optionPickOne' => t('editor_option_pick_one', 'pick one'),
            'optionErrorKeywords' => t('editor_option_error_keywords', 'Error loading keywords'),
            'optionPickGalaxy' => t('editor_option_pick_galaxy', 'pick a galaxy'),
            'textPickKeyword' => t('editor_text_pick_keyword', 'Pick a keyword to see the count.'),
            'errorPickSpecificGalaxy' => t('editor_error_pick_specific_galaxy', 'Pick a specific galaxy first (not "All galaxies").'),
            'previewMoveOne' => t('editor_preview_move_one', 'Will move 1 wormhole to the chosen galaxy.'),
            'previewMoveMany' => t('editor_preview_move_many', 'Will move %d wormholes to the chosen galaxy.'),
            'previewMovePickTargetOne' => t('editor_preview_move_pick_target_one', 'Will move 1 wormhole. Pick a target galaxy first.'),
            'previewMovePickTargetMany' => t('editor_preview_move_pick_target_many', 'Will move %d wormholes. Pick a target galaxy first.'),
            'previewDeleteOne' => t('editor_preview_delete_one', 'Will permanently delete 1 wormhole.'),
            'previewDeleteMany' => t('editor_preview_delete_many', 'Will permanently delete %d wormholes.'),
            'confirmBulkDeleteKeywordOne' => t('editor_confirm_bulk_delete_keyword_one', 'Permanently delete 1 wormhole carrying "%s"? This cannot be undone.'),
            'confirmBulkDeleteKeywordMany' => t('editor_confirm_bulk_delete_keyword_many', 'Permanently delete %d wormholes carrying "%s"? This cannot be undone.'),
            'confirmBulkMoveKeywordOne' => t('editor_confirm_bulk_move_keyword_one', 'Move 1 wormhole carrying "%s" to the selected galaxy?'),
            'confirmBulkMoveKeywordMany' => t('editor_confirm_bulk_move_keyword_many', 'Move %d wormholes carrying "%s" to the selected galaxy?'),
            'toastBulkDeletedOne' => t('editor_toast_bulk_deleted_one', 'Deleted 1 wormhole.'),
            'toastBulkDeletedMany' => t('editor_toast_bulk_deleted_many', 'Deleted %d wormholes.'),
            'toastBulkMovedOne' => t('editor_toast_bulk_moved_one', 'Moved 1 wormhole.'),
            'toastBulkMovedMany' => t('editor_toast_bulk_moved_many', 'Moved %d wormholes.'),
            'toastBulkActionFailed' => t('editor_toast_bulk_action_failed', 'Bulk action failed: %s'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;

        // Tiny printf-style %d/%s formatter for the localized strings above.
        // Replaces the first %d or %s with each argument in order. Sufficient
        // for our patterns where every string has 1-2 placeholders.
        function tFmt(tpl, ...args) {
            let i = 0;
            return tpl.replace(/%[ds]/g, () => (i < args.length ? String(args[i++]) : ''));
        }

        /** Parse a constellation's import_source JSON; return its provenance shape or null. */
        function parseImportSource(c) {
            if (!c || !c.import_source) return null;
            try {
                const decoded = JSON.parse(c.import_source);
                return decoded && typeof decoded === 'object' ? decoded : null;
            } catch (_) {
                return null;
            }
        }

        /** Check if a constellation is imported (read-only). */
        function isImportedConstellation(constellationId) {
            const c = CONSTELLATIONS.find(x => x.id === constellationId);
            return c && c.import_source != null && c.import_source !== '';
        }

        function updateReadOnlyState() {
            const constellationEl = document.getElementById('current-constellation');
            const cid = constellationEl ? parseInt(constellationEl.value, 10) : NaN;
            const c = isNaN(cid) ? null : CONSTELLATIONS.find(x => x.id === cid);
            const isImported = c && c.import_source != null && c.import_source !== '';
            const readOnlyBanner = document.getElementById('read-only-banner');
            const readOnlyBannerText = document.getElementById('read-only-banner-text');
            const createNodeSection = document.getElementById('create-node-section');
            if (readOnlyBanner) readOnlyBanner.style.display = isImported ? 'block' : 'none';
            if (isImported && readOnlyBannerText) {
                // Federation mirrors get an origin-aware message; other imports
                // (Mocambos bridge) fall back to the generic copy.
                const src = parseImportSource(c);
                if (src && src.kind === 'federation' && src.origin_host) {
                    readOnlyBannerText.textContent = READ_ONLY_BANNER_MIRROR_FEDERATION.replace('%s', src.origin_host);
                } else {
                    readOnlyBannerText.textContent = READ_ONLY_BANNER_GENERIC;
                }
            }
            if (createNodeSection) createNodeSection.style.display = isImported ? 'none' : '';
            document.querySelectorAll('.node-edit-action').forEach(el => el.style.display = isImported ? 'none' : '');
            document.querySelectorAll('.node-checkbox').forEach(el => el.style.display = isImported ? 'none' : '');
        }

        /** Constellations from API (all), populated at load for target dropdown when Portal is selected. */
        let allConstellations = [];

        (function fetchConstellationsAtStart() {
            if (!API_KEY) return;
            fetch(CONSTELLATIONS_API, { headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN } })
                .then(r => r.ok ? r.json() : Promise.resolve([]))
                .then(data => { allConstellations = Array.isArray(data) ? data.map(c => ({ id: c.id, name: c.name || '' })) : []; })
                .catch(() => {});
        })();

        let editingNodeId = null;
        let selectedNodeIds = new Set();

        function toggleNodeSelection(id, event) {
            if (event) {
                // If it's a click on the row but not the checkbox itself, we might want to still toggle
                // but we must be careful not to trigger when clicking "Edit" or "Delete"
                if (event.target.closest('button') || event.target.closest('a')) {
                    return;
                }
            }

            if (selectedNodeIds.has(id)) {
                selectedNodeIds.delete(id);
            } else {
                selectedNodeIds.add(id);
            }
            
            updateBulkActionsBar();
            // Re-render to show selection (more efficient way would be to just toggle class)
            // For now, let's just toggle the class and checkbox manually for speed
            const row = document.querySelector(`.node-checkbox[data-id="${id}"]`)?.closest('.border-b');
            const checkbox = document.querySelector(`.node-checkbox[data-id="${id}"]`);
            if (row) row.classList.toggle('bg-blue-50/50', selectedNodeIds.has(id));
            if (checkbox) checkbox.checked = selectedNodeIds.has(id);
            
            updateSelectAllCheckbox();
        }

        function toggleSelectAll(checkbox) {
            const isChecked = checkbox.checked;
            if (isChecked) {
                // Select all nodes on current page
                allNodes.forEach(node => selectedNodeIds.add(node.id));
            } else {
                // Unselect all nodes on current page
                allNodes.forEach(node => selectedNodeIds.delete(node.id));
            }
            updateBulkActionsBar();
            // Re-render to show updated checkbox states
            displayNodes(allNodes);
            updateSelectAllCheckbox();
        }

        function updateSelectAllCheckbox() {
            const selectAllCb = document.getElementById('select-all-nodes');
            if (!selectAllCb) return;

            const currentPageNodes = allNodes;
            if (currentPageNodes.length === 0) {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = false;
                return;
            }

            const allSelected = currentPageNodes.every(node => selectedNodeIds.has(node.id));
            const someSelected = currentPageNodes.some(node => selectedNodeIds.has(node.id));
            
            selectAllCb.checked = allSelected;
            selectAllCb.indeterminate = someSelected && !allSelected;
        }

        function updateBulkActionsBar() {
            const bar = document.getElementById('bulk-actions-bar');
            const countEl = document.getElementById('selected-count');
            
            if (selectedNodeIds.size > 0) {
                bar.classList.remove('hidden');
                countEl.textContent = selectedNodeIds.size;
            } else {
                bar.classList.add('hidden');
            }
        }

        function openBulkMoveModal() {
            const count = selectedNodeIds.size;
            if (count === 0) return;
            
            document.getElementById('bulk-move-description').textContent = tFmt(TELARIS_EDIT.textMoveCountWormholes, count);
            document.getElementById('bulk_move_modal').showModal();
        }

        async function bulkMove() {
            const constellationId = document.getElementById('bulk-move-constellation').value;
            if (!constellationId) return;

            const ids = Array.from(selectedNodeIds);
            let successCount = 0;
            let errorCount = 0;

            const bar = document.getElementById('bulk-actions-bar');
            bar.classList.add('opacity-50', 'pointer-events-none');
            document.getElementById('bulk_move_modal').close();

            try {
                // Update each node. We need to fetch the node data first or send a partial update if the API supports it.
                // Our API handles PUT with partial data if we provide ID and Name.
                const promises = ids.map(id => {
                    const node = allNodes.find(n => n.id === id);
                    if (!node) return Promise.resolve();

                    const formData = new FormData();
                    formData.append('id', id);
                    formData.append('name', node.name);
                    formData.append('constellation_id', constellationId);
                    
                    return fetch(API_BASE, {
                        method: 'POST',
                        headers: {
                            'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN,
                            'X-HTTP-Method-Override': 'PUT'
                        },
                        body: formData
                    }).then(r => {
                        if (r.ok) successCount++;
                        else errorCount++;
                    }).catch(() => errorCount++);
                });

                await Promise.all(promises);

                if (successCount > 0) {
                    showMessage(tFmt(TELARIS_EDIT.toastBulkMoveSuccess, successCount));
                }
                if (errorCount > 0) {
                    showMessage(tFmt(TELARIS_EDIT.toastBulkMoveFailed, errorCount), 'error');
                }

                selectedNodeIds.clear();
                updateBulkActionsBar();
                loadNodes();
            } catch (e) {
                showMessage(TELARIS_EDIT.toastBulkMoveError, 'error');
            } finally {
                bar.classList.remove('opacity-50', 'pointer-events-none');
            }
        }

        // --- Duplicate Node ---

        async function openDuplicateModal(id) {
            let node = allNodes.find(n => n.id === id);
            if (!node) {
                try {
                    const response = await fetch(`${API_BASE}?id=${id}`, {
                        headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                    });
                    if (!response.ok) return;
                    node = await response.json();
                } catch (e) { return; }
            }
            document.getElementById('duplicate-source-id').value = id;
            document.getElementById('duplicate-source-prompt').textContent = tFmt(TELARIS_EDIT.textDuplicateTo, node.name);
            document.getElementById('duplicate-constellation').value = node.constellation_id;
            document.getElementById('duplicate-node-constellation-badge').textContent = '#' + id;
            document.getElementById('duplicate_node_modal').showModal();
        }

        async function confirmDuplicate() {
            const sourceId = parseInt(document.getElementById('duplicate-source-id').value);
            const constellationId = parseInt(document.getElementById('duplicate-constellation').value);
            if (!sourceId) return;

            try {
                const response = await fetch(API_BASE, {
                    method: 'POST',
                    headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ duplicate_from: sourceId, constellation_id: constellationId })
                });
                if (response.ok) {
                    showMessage(TELARIS_EDIT.toastDuplicateSuccess);
                    document.getElementById('duplicate_node_modal').close();
                    loadNodes();
                } else {
                    const err = await response.json();
                    showMessage('Error: ' + (err.error || TELARIS_EDIT.errorFailedDuplicate), 'error');
                }
            } catch (e) {
                showMessage(TELARIS_EDIT.toastDuplicateErrorGeneric, 'error');
            }
        }

        function openBulkDuplicateModal() {
            const count = selectedNodeIds.size;
            if (count === 0) return;
            document.getElementById('bulk-duplicate-description').textContent = tFmt(TELARIS_EDIT.textDuplicateCountWormholes, count);
            document.getElementById('bulk_duplicate_modal').showModal();
        }

        async function bulkDuplicate() {
            const constellationId = parseInt(document.getElementById('bulk-duplicate-constellation').value);
            if (!constellationId) return;

            const ids = Array.from(selectedNodeIds);
            let successCount = 0;
            let errorCount = 0;

            const bar = document.getElementById('bulk-actions-bar');
            bar.classList.add('opacity-50', 'pointer-events-none');
            document.getElementById('bulk_duplicate_modal').close();

            try {
                const promises = ids.map(id =>
                    fetch(API_BASE, {
                        method: 'POST',
                        headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' },
                        body: JSON.stringify({ duplicate_from: id, constellation_id: constellationId })
                    }).then(r => {
                        if (r.ok) successCount++;
                        else errorCount++;
                    }).catch(() => errorCount++)
                );

                await Promise.all(promises);

                if (successCount > 0) {
                    showMessage(tFmt(TELARIS_EDIT.toastBulkDuplicateSuccess, successCount));
                }
                if (errorCount > 0) {
                    showMessage(tFmt(TELARIS_EDIT.toastBulkDuplicateFailed, errorCount), 'error');
                }

                selectedNodeIds.clear();
                updateBulkActionsBar();
                loadNodes();
            } catch (e) {
                showMessage(TELARIS_EDIT.toastBulkDuplicateError, 'error');
            } finally {
                bar.classList.remove('opacity-50', 'pointer-events-none');
            }
        }

        function clearSelection() {
            selectedNodeIds.clear();
            updateBulkActionsBar();
            const selectAllCb = document.getElementById('select-all-nodes');
            if (selectAllCb) {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = false;
            }
            // Re-render current page without server fetch
            displayNodes(allNodes);
            updateSelectAllCheckbox();
        }

        async function bulkDelete() {
            const count = selectedNodeIds.size;
            if (count === 0) return;

            confirmAction(tFmt(TELARIS_EDIT.confirmBulkDelete, count), async () => {
                const ids = Array.from(selectedNodeIds);
                let successCount = 0;
                let errorCount = 0;

                // Show loading message or disable bar
                const bar = document.getElementById('bulk-actions-bar');
                bar.classList.add('opacity-50', 'pointer-events-none');

                try {
                    // We call the API for each ID. If we update the API to handle bulk, we can do it in one call.
                    // For now, let's do it sequentially or in parallel batches.
                    const promises = ids.map(id => 
                        fetch(`${API_BASE}?id=${id}`, {
                            method: 'DELETE',
                            headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                        }).then(r => {
                            if (r.ok) successCount++;
                            else errorCount++;
                        }).catch(() => errorCount++)
                    );

                    await Promise.all(promises);

                    if (successCount > 0) {
                        showMessage(tFmt(TELARIS_EDIT.toastBulkDeleteSuccess, successCount));
                    }
                    if (errorCount > 0) {
                        showMessage(tFmt(TELARIS_EDIT.toastBulkDeleteFailed, errorCount), 'error');
                    }

                    selectedNodeIds.clear();
                    updateBulkActionsBar();
                    loadNodes();
                } catch (e) {
                    showMessage(TELARIS_EDIT.toastBulkDeleteError, 'error');
                } finally {
                    bar.classList.remove('opacity-50', 'pointer-events-none');
                }
            });
        }

        // Switch current constellation (reload page so list shows only that constellation)
        function switchConstellation(value) {
            const url = new URL(window.location.href);
            url.searchParams.set('constellation_id', value);
            window.location.assign(url.toString());
        }

        // Open the current constellation in the network view
        function viewNetwork() {
            const constellationId = document.getElementById('current-constellation').value;
            if (!constellationId || constellationId === 'all') {
                window.open('../index.php', '_blank');
                return;
            }
            const galaxy = (typeof TELARIS_GALAXIES !== 'undefined')
                ? TELARIS_GALAXIES.find(g => g.id === parseInt(constellationId, 10))
                : null;
            const url = (galaxy && galaxy.slug)
                ? '../' + encodeURIComponent(galaxy.slug)
                : '../index.php?constellation_id=' + constellationId;
            window.open(url, '_blank');
        }

        // Copy the absolute URL of the current constellation to clipboard
        function copyCurrentConstellationUrl(buttonEl) {
            const constellationId = document.getElementById('current-constellation').value;
            let relativeUrl;
            if (!constellationId || constellationId === 'all') {
                relativeUrl = '../index.php';
            } else {
                const galaxy = (typeof TELARIS_GALAXIES !== 'undefined')
                    ? TELARIS_GALAXIES.find(g => g.id === parseInt(constellationId, 10))
                    : null;
                relativeUrl = (galaxy && galaxy.slug)
                    ? '../' + encodeURIComponent(galaxy.slug)
                    : '../index.php?constellation_id=' + constellationId;
            }
            const absoluteUrl = new URL(relativeUrl, window.location.origin + window.location.pathname).href;
            
            navigator.clipboard.writeText(absoluteUrl).then(() => {
                const origTitle = buttonEl.getAttribute('title');
                buttonEl.setAttribute('title', TELARIS_EDIT.titleUrlCopied);
                // Using alert or a more subtle way since we don't have the toast div here yet
                showMessage(TELARIS_EDIT.toastUrlCopied);
                setTimeout(() => {
                    buttonEl.setAttribute('title', origTitle || TELARIS_EDIT.titleCopyUrlDefault);
                }, 1500);
            });
        }

        // Populate a target-constellation select with options from API list (allConstellations)
        function populateTargetConstellationDropdown(selectEl, selectedId) {
            if (!selectEl) return;
            const list = allConstellations.length ? allConstellations : CONSTELLATIONS;
            const currentValue = selectEl.value;
            selectEl.innerHTML = '';

            // Group by [Tag] prefix
            const groupRegex = /^\[([^\]]+)\]/;
            const grouped = [], ungrouped = [];
            list.forEach(c => {
                const m = (c.name || '').match(groupRegex);
                if (m) { grouped.push({ ...c, group: m[1] }); }
                else { ungrouped.push(c); }
            });
            // Sort grouped by group name then name
            grouped.sort((a, b) => a.group.localeCompare(b.group) || a.name.localeCompare(b.name));
            ungrouped.sort((a, b) => (a.name || '').localeCompare(b.name || ''));

            let currentGroup = null;
            let optgroup = null;
            grouped.forEach(c => {
                if (c.group !== currentGroup) {
                    optgroup = document.createElement('optgroup');
                    optgroup.label = c.group;
                    selectEl.appendChild(optgroup);
                    currentGroup = c.group;
                }
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                optgroup.appendChild(opt);
            });
            ungrouped.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                selectEl.appendChild(opt);
            });

            const valueToSet = selectedId != null ? String(selectedId) : currentValue;
            if (valueToSet && Array.from(selectEl.options).some(o => o.value === valueToSet)) selectEl.value = valueToSet;
        }

        // Show/hide Target Constellation block when node type is portal; populate target dropdown from API list
        function toggleTargetConstellation(nodeType, context, nodeId) {
            if (context === 'add' || context === 'create') {
                const wrap = document.getElementById(context === 'add' ? 'add-target-constellation-wrap' : 'create-target-constellation-wrap');
                if (wrap) wrap.classList.toggle('hidden', nodeType !== 'portal');
                if (nodeType === 'portal') {
                    const select = document.getElementById(context === 'add' ? 'node-target-constellation' : 'node-target-constellation');
                    // Note: both use same ID in template above for simplicity or unique ones
                    const actualSelect = context === 'add' ? document.getElementById('node-target-constellation') : document.getElementById('node-target-constellation');
                    populateTargetConstellationDropdown(actualSelect);
                }
            } else if (context === 'modal') {
                const wrap = document.getElementById('edit-target-constellation-wrap-modal');
                if (wrap) wrap.classList.toggle('hidden', nodeType !== 'portal');
                if (nodeType === 'portal') {
                    const select = document.getElementById('edit-target-constellation-modal');
                    const node = allNodes && allNodes.find(n => n.id === editingNodeId);
                    populateTargetConstellationDropdown(select, node ? node.target_constellation_id : null);
                }
            } else if (context === 'inline' && nodeId) {
                const wrap = document.getElementById('edit-target-constellation-wrap-' + nodeId);
                if (wrap) wrap.classList.toggle('hidden', nodeType !== 'portal');
                if (nodeType === 'portal') {
                    const select = document.getElementById('edit-target-constellation-' + nodeId);
                    const node = allNodes && allNodes.find(n => n.id === nodeId);
                    populateTargetConstellationDropdown(select, node ? node.target_constellation_id : null);
                }
            }
        }

        // Create new constellation via API and add to dropdowns
        async function createNewConstellation(context, inlineNodeId) {
            const name = window.prompt(TELARIS_EDIT.promptNewGalaxyName);
            if (name === null || name.trim() === '') return;
            try {
                const response = await fetch('create_constellation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ name: name.trim() })
                });
                const text = await response.text();
                if (!response.ok) {
                    const err = (() => { try { return JSON.parse(text).error; } catch (e) { return text || response.statusText; } })();
                    throw new Error(err);
                }
                const data = JSON.parse(text);
                const newId = data.id;
                const newName = data.name || name.trim();
                CONSTELLATIONS.push({ id: newId, name: newName });
                // Update add form dropdown
                const addSelect = document.getElementById('node-target-constellation');
                if (addSelect) {
                    const opt = document.createElement('option');
                    opt.value = newId;
                    opt.textContent = newName;
                    addSelect.appendChild(opt);
                }
                // Update current-constellation header dropdown
                const currentSelect = document.getElementById('current-constellation');
                if (currentSelect && !Array.from(currentSelect.options).some(o => o.value === String(newId))) {
                    const opt = document.createElement('option');
                    opt.value = newId;
                    opt.textContent = newName;
                    currentSelect.appendChild(opt);
                }
                // Update modal target constellation dropdowns
                const modalSelect = document.getElementById('edit-target-constellation-modal');
                if (modalSelect && context === 'modal') {
                    const opt = document.createElement('option');
                    opt.value = newId;
                    opt.textContent = newName;
                    modalSelect.appendChild(opt);
                    modalSelect.value = String(newId);
                }
                const createSelect = document.getElementById('node-target-constellation');
                if (createSelect && (context === 'create' || context === 'add')) {
                    const opt = document.createElement('option');
                    opt.value = newId;
                    opt.textContent = newName;
                    createSelect.appendChild(opt);
                    createSelect.value = String(newId);
                }
                showMessage(tFmt(TELARIS_EDIT.toastGalaxyCreated, newName));
            } catch (e) {
                showMessage(tFmt(TELARIS_EDIT.toastErrorCreatingGalaxy, e.message), 'error');
            }
        }

        // Show message as a temporary toast
        function showMessage(text, type = 'success') {
            // If a modal dialog is open, place notification inside it so it's visible
            let container = null;
            const openDialog = document.querySelector('dialog[open]');
            if (openDialog) {
                container = openDialog.querySelector('.dialog-notification-container');
                if (!container) {
                    container = document.createElement('div');
                    container.className = 'dialog-notification-container fixed top-4 left-1/2 -translate-x-1/2 z-[9999] flex flex-col gap-2 w-full max-w-md pointer-events-none';
                    openDialog.appendChild(container);
                }
            } else {
                container = document.getElementById('notification-container');
            }
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `alert ${type === 'success' ? 'alert-success' : 'alert-error'} shadow-lg mb-2 pointer-events-auto transition-all duration-500 transform -translate-y-4 opacity-0 text-white`;
            toast.innerHTML = `<div class="text-sm font-medium">${text}</div>`;

            container.appendChild(toast);

            // Trigger animation
            requestAnimationFrame(() => {
                toast.classList.remove('-translate-y-4', 'opacity-0');
            });

            // Auto-remove after 2 seconds
            setTimeout(() => {
                toast.classList.add('-translate-y-4', 'opacity-0');
                setTimeout(() => {
                    toast.remove();
                }, 500);
            }, 2000);
        }

        // Load nodes with server-side pagination
        async function loadNodes() {
            const listDiv = document.getElementById('nodes-list');
            if (!listDiv) return;

            // Show loading state
            const countEl = document.getElementById('tab-list-count');
            if (countEl) countEl.textContent = '...';

            listDiv.innerHTML = `
                <div class="flex flex-col items-center justify-center py-12 text-gray-500">
                    <span class="loading loading-spinner loading-lg text-neutral mb-4"></span>
                    <p class="text-lg">${escapeHtml(TELARIS_EDIT.msgRetrieving)}</p>
                </div>
            `;

            if (!API_KEY) {
                listDiv.innerHTML = '<p class="text-red-600">' + escapeHtml(TELARIS_EDIT.errorApiKeyMissingFetch) + '</p>';
                return;
            }

            try {
                const constellationEl = document.getElementById('current-constellation');
                const constellationId = constellationEl ? constellationEl.value : 'all';

                const params = new URLSearchParams();
                params.set('constellation_id', constellationId);
                params.set('no_cluster', '1');
                params.set('page', currentPage);
                params.set('per_page', itemsPerPage);
                if (currentSortColumn) {
                    params.set('sort', currentSortColumn);
                    params.set('order', currentSortOrder);
                }
                if (currentFilter) {
                    params.set('filter', currentFilter);
                }
                if (touchedTodayFilter) {
                    params.set('touched_today', '1');
                }

                const response = await fetch(API_BASE + '?' + params.toString(), {
                    headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                });

                const responseText = await response.text();

                if (!response.ok) {
                    let errorMessage = `HTTP ${response.status}: ${response.statusText}`;
                    try {
                        const errorData = JSON.parse(responseText);
                        errorMessage = errorData.error || errorData.message || errorMessage;
                    } catch (e) {
                        errorMessage = responseText.substring(0, 200) || errorMessage;
                    }
                    throw new Error(errorMessage);
                }

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    throw new Error(TELARIS_EDIT.errorInvalidJson);
                }

                if (!result.nodes || !Array.isArray(result.nodes)) {
                    throw new Error(TELARIS_EDIT.errorInvalidFormat);
                }

                allNodes = result.nodes;
                totalNodes = result.total;
                totalPages = Math.ceil(totalNodes / itemsPerPage);

                // Guard against page exceeding total after deletions
                if (currentPage > totalPages && totalPages > 0) {
                    currentPage = totalPages;
                    return loadNodes();
                }

                if (countEl) countEl.textContent = totalNodes;

                displayNodes(allNodes);
                updatePagination();
                updateSelectAllCheckbox();
                updateSortIndicators();
                updateReadOnlyState();
            } catch (error) {
                const errorMsg = error.message || 'Unknown error';
                if (listDiv) {
                    listDiv.innerHTML =
                        `<p class="text-red-600 font-semibold">${escapeHtml(TELARIS_EDIT.headingErrorLoading)}</p>
                         <p class="text-red-600 text-sm mt-2">${escapeHtml(errorMsg)}</p>`;
                }
            }
        }

        // Display nodes
        function displayNodes(nodes) {
            const listDiv = document.getElementById('nodes-list');
            if (!listDiv) return;

            if (!Array.isArray(nodes)) {
                listDiv.innerHTML = '<p class="text-red-600 p-4">' + escapeHtml(TELARIS_EDIT.errorInvalidDataFormat) + '</p>';
                return;
            }

            if (nodes.length === 0) {
                listDiv.innerHTML = `
                    <div class="flex flex-col items-center justify-center py-12 text-gray-500 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200">
                        <svg class="w-12 h-12 mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                        <p class="text-lg font-medium">${escapeHtml(TELARIS_EDIT.headingNoWormholes)}</p>
                        <p class="text-sm">${escapeHtml(TELARIS_EDIT.textEmptyStateHelp)}</p>
                    </div>
                `;
                return;
            }

            try {
                const headerHTML = `
                    <div class="border-b-2 border-gray-400 bg-gray-100 py-2 mb-1 sticky top-0 z-10">
                        <div class="grid grid-cols-12 gap-3 text-xs font-semibold text-gray-700 items-center">
                            <div class="col-span-1 flex justify-center">
                                <input type="checkbox" id="select-all-nodes" onclick="toggleSelectAll(this)" class="checkbox checkbox-xs border-gray-400">
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'name\')">${escapeHtml(TELARIS_EDIT.colName)}<span id="sort-indicator-name"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'node_type\')">${escapeHtml(TELARIS_EDIT.colType)}<span id="sort-indicator-node_type"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'constellation_name\')">${escapeHtml(TELARIS_EDIT.colGalaxy)}<span id="sort-indicator-constellation_name"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'keywords\')">${escapeHtml(TELARIS_EDIT.colKeywords)}<span id="sort-indicator-keywords"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'is_accentuated\')" title="${escapeHtml(TELARIS_EDIT.colAccTitle)}">${escapeHtml(TELARIS_EDIT.colAcc)}<span id="sort-indicator-is_accentuated"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'created_at\')">${escapeHtml(TELARIS_EDIT.colCreated)}<span id="sort-indicator-created_at"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'updated_at\')">${escapeHtml(TELARIS_EDIT.colUpdated)}<span id="sort-indicator-updated_at"></span></div>
                            <div class="col-span-1 text-right pr-2">${escapeHtml(TELARIS_EDIT.colActions)}</div>
                        </div>
                    </div>
                `;

                const html = nodes.map(node => {
                    if (!node || !node.id) {
                        return '';
                    }
                    
                    const isSelected = selectedNodeIds.has(node.id);
                    // Show normal display - compact spreadsheet-like layout
                    const dateObj = node.created_at ? new Date(node.created_at) : null;
                    const createdDate = dateObj 
                        ? `${dateObj.getFullYear().toString().slice(-2)}-${(dateObj.getMonth()+1).toString().padStart(2,'0')}-${dateObj.getDate().toString().padStart(2,'0')} ${dateObj.getHours().toString().padStart(2,'0')}:${dateObj.getMinutes().toString().padStart(2,'0')}` 
                        : 'N/A';
                    const updatedDateObj = node.updated_at ? new Date(node.updated_at) : null;
                    const updatedDate = updatedDateObj 
                        ? `${updatedDateObj.getFullYear().toString().slice(-2)}-${(updatedDateObj.getMonth()+1).toString().padStart(2,'0')}-${updatedDateObj.getDate().toString().padStart(2,'0')} ${updatedDateObj.getHours().toString().padStart(2,'0')}:${updatedDateObj.getMinutes().toString().padStart(2,'0')}` 
                        : 'N/A';
                    const keywordsDisplay = node.keywords && node.keywords.length > 0
                        ? node.keywords.map(k => `<span class="badge badge-sm border-current/20 ${getPastelColor(k)}">${escapeHtml(k)}</span>`).join(' ')
                        : `<span class="text-xs text-gray-400">${escapeHtml(TELARIS_EDIT.textNoKeywords)}</span>`;
                    const constellationName = (node.constellation_name || 'Default');
                    const nodeType = node.node_type || 'object';
                    const typeLabel = nodeType === 'portal' ? TELARIS_EDIT.labelTypePortal : TELARIS_EDIT.labelTypeObject;
                    const typeBadgeClass = nodeType === 'portal' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700';
                    const targetConstellationList = allConstellations.length ? allConstellations : CONSTELLATIONS;
                    const targetConstellationName = (nodeType === 'portal' && node.target_constellation_id != null)
                        ? (targetConstellationList.find(c => c.id === node.target_constellation_id)?.name || ('#' + node.target_constellation_id))
                        : '';
                    const typeDisplay = nodeType === 'portal' && targetConstellationName
                        ? `<span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium ${typeBadgeClass}" title="${escapeHtml(TELARIS_EDIT.labelTargetPrefix)} ${escapeHtml(targetConstellationName)}">${escapeHtml(typeLabel)}</span> <span class="text-xs text-gray-500 truncate block" title="${escapeHtml(targetConstellationName)}">→ ${escapeHtml(targetConstellationName)}</span>`
                        : `<span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium ${typeBadgeClass}">${escapeHtml(typeLabel)}</span>`;
                    return `
                <div class="border-b border-gray-300 hover:bg-gray-50 py-2 cursor-pointer transition-colors ${isSelected ? 'bg-blue-50/50' : ''}" onclick="toggleNodeSelection(${node.id}, event)">
                    <div class="grid grid-cols-12 gap-3 items-center text-sm">
                        <div class="col-span-1 flex justify-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="node-checkbox checkbox checkbox-xs" data-id="${node.id}" ${isSelected ? 'checked' : ''} onclick="toggleNodeSelection(${node.id}, event)">
                        </div>
                        <div class="col-span-2 min-w-0" onclick="editNode(${node.id}); event.stopPropagation();">
                            <div class="font-semibold text-gray-800 truncate" title="${escapeHtml(node.name)}">${escapeHtml(node.name)}</div>
                            <div class="flex flex-wrap gap-1 mt-1">
                                ${node.is_accentuated ? `<span class="text-[10px] bg-yellow-100 text-yellow-700 px-1 rounded border border-yellow-200 font-bold" title="${escapeHtml(TELARIS_EDIT.badgeAccTitle)}">${escapeHtml(TELARIS_EDIT.badgeAcc)}</span>` : ''}
                                ${node.url ? `<span class="text-[10px] bg-blue-100 text-blue-700 px-1 rounded" title="${escapeHtml(TELARIS_EDIT.badgeUrlTitle)}">${escapeHtml(TELARIS_EDIT.badgeUrl)}</span>` : ''}
                                ${node.description ? `<span class="text-[10px] bg-green-100 text-green-700 px-1 rounded" title="${escapeHtml(TELARIS_EDIT.badgeDescTitle)}">${escapeHtml(TELARIS_EDIT.badgeDesc)}</span>` : ''}
                                ${node.image_url ? `<span class="text-[10px] bg-purple-100 text-purple-700 px-1 rounded" title="${escapeHtml(TELARIS_EDIT.badgeImgTitle)}">${escapeHtml(TELARIS_EDIT.badgeImg)}</span>` : ''}
                                ${node.embed_code ? `<span class="text-[10px] bg-pink-100 text-pink-700 px-1 rounded" title="${escapeHtml(TELARIS_EDIT.badgeEmbTitle)}">${escapeHtml(TELARIS_EDIT.badgeEmb)}</span>` : ''}
                                ${node.audio_url ? `<span class="text-[10px] bg-orange-100 text-orange-700 px-1 rounded" title="${escapeHtml(TELARIS_EDIT.badgeAudTitle)}">${escapeHtml(TELARIS_EDIT.badgeAud)}</span>` : ''}
                                ${node.video_url ? `<span class="text-[10px] bg-cyan-100 text-cyan-700 px-1 rounded" title="${escapeHtml(TELARIS_EDIT.badgeVidTitle)}">${escapeHtml(TELARIS_EDIT.badgeVid)}</span>` : ''}
                            </div>
                        </div>
                        <div class="col-span-1 text-xs">
                            ${typeDisplay}
                        </div>
                        <div class="col-span-2 text-xs text-gray-600 truncate" title="${escapeHtml(constellationName)}">${escapeHtml(constellationName)}</div>
                        <div class="col-span-2">
                            <div class="flex flex-wrap gap-1">${keywordsDisplay}</div>
                        </div>
                        <div class="col-span-1 text-center">
                            ${node.is_accentuated ? `<span class="text-yellow-600 font-bold" title="${escapeHtml(TELARIS_EDIT.titleAccentuated)}">✓</span>` : '<span class="text-gray-300">—</span>'}
                        </div>
                        <div class="col-span-1 text-xs text-gray-500 whitespace-nowrap">
                            ${createdDate}
                        </div>
                        <div class="col-span-1 text-xs text-gray-500 whitespace-nowrap">
                            ${updatedDate}
                        </div>
                        <div class="col-span-1 flex justify-end pr-2">
                            <div class="dropdown dropdown-end">
                                <label tabindex="0" onclick="event.stopPropagation(); closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                </label>
                                <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-44">
                                    <li><a onclick="event.stopPropagation(); viewNode(${node.id})" class="text-gray-700 text-xs">${escapeHtml(TELARIS_EDIT.actionViewWormhole)}</a></li>
                                    <li><a onclick="event.stopPropagation(); viewConstellation(${node.constellation_id})" class="text-gray-700 text-xs">${escapeHtml(TELARIS_EDIT.actionViewGalaxy)}</a></li>
                                    <li class="border-t border-gray-100 mt-1 pt-1"><a onclick="event.stopPropagation(); editNode(${node.id})" class="text-gray-700 text-xs">${escapeHtml(TELARIS_EDIT.actionEdit)}</a></li>
                                    <li><a onclick="event.stopPropagation(); openDuplicateModal(${node.id})" class="text-gray-700 text-xs">${escapeHtml(TELARIS_EDIT.actionDuplicate)}</a></li>
                                    <li class="node-edit-action"><a onclick="event.stopPropagation(); deleteNode(${node.id}, '${escapeHtml(node.name)}')" class="text-red-600 text-xs">${escapeHtml(TELARIS_EDIT.actionDelete)}</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                    `;
                }).filter(html => html.length > 0).join('');
                
                // Set innerHTML with header + nodes
                listDiv.innerHTML = headerHTML + html;

                // Initialize keywords for the node being edited
                if (editingNodeId !== null) {
                    const editingNode = nodes.find(n => n.id === editingNodeId);
                    if (editingNode) {
                        keywordState[editingNodeId] = [...(editingNode.keywords || [])];
                        updateKeywordTags(editingNodeId);
                    }
                }
            } catch (error) {
                listDiv.innerHTML = '<p class="text-red-600">' + escapeHtml(tFmt(TELARIS_EDIT.errorCouldNotLoad, error.message)) + '</p>';
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function closeAllDropdowns(except) {
            document.querySelectorAll('.dropdown').forEach(d => {
                const label = d.querySelector('[tabindex="0"]');
                if (label && label !== except) label.blur();
            });
        }
        document.addEventListener('click', () => closeAllDropdowns(null));

        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;  
            }
        }

        // Store all nodes for sorting
        let allNodes = [];       // Current page nodes only
        let totalNodes = 0;      // Server-provided total after filter
        let totalPages = 0;

        // Pagination state
        let currentPage = 1;
        const itemsPerPage = 25;

        // Sort state
        let currentSortColumn = null;
        let currentSortOrder = 'asc'; // 'asc' or 'desc'

        // Filter state
        let currentFilter = '';
        let touchedTodayFilter = false;
        function toggleTouchedTodayFilter() {
            touchedTodayFilter = !touchedTodayFilter;
            const btn = document.getElementById('filter-touched-today-btn');
            if (btn) {
                if (touchedTodayFilter) {
                    btn.classList.remove('border-gray-300', 'text-gray-600', 'hover:border-gray-500');
                    btn.classList.add('bg-neutral', 'text-neutral-content', 'border-neutral');
                } else {
                    btn.classList.add('border-gray-300', 'text-gray-600', 'hover:border-gray-500');
                    btn.classList.remove('bg-neutral', 'text-neutral-content', 'border-neutral');
                }
            }
            currentPage = 1;
            loadNodes();
        }

        // Debounced search
        const debouncedSearch = (() => {
            let timer;
            return () => {
                clearTimeout(timer);
                timer = setTimeout(() => applySorting(), 300);
            };
        })();

        // Sort by column header click
        function sortByColumn(column) {
            if (currentSortColumn === column) {
                currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortColumn = column;
                currentSortOrder = 'asc';
            }
            currentPage = 1;
            updateSortIndicators();
            loadNodes();
        }
        
        // Update sort indicators in header
        function updateSortIndicators() {
            // Reset all indicators
            ['name', 'node_type', 'constellation_name', 'url', 'keywords', 'is_accentuated', 'created_at', 'updated_at'].forEach(col => {
                const indicator = document.getElementById('sort-indicator-' + col);
                if (indicator) {
                    indicator.innerHTML = '';
                }
            });
            
            // Set indicator for current sort column
            if (currentSortColumn) {
                const indicator = document.getElementById('sort-indicator-' + currentSortColumn);
                if (indicator) {
                    indicator.innerHTML = currentSortOrder === 'asc' ? ' ↑' : ' ↓';
                }
            }
        }
        
        // Apply sorting and filtering — triggers a server-side fetch
        function applySorting(resetPage = true) {
            const searchInput = document.getElementById('search-nodes');
            currentFilter = searchInput ? searchInput.value.trim() : '';
            if (resetPage) currentPage = 1;
            loadNodes();
        }

        // Server-side pagination rendering
        function updatePagination() {
            const headerContainer = document.getElementById('nodes-pagination-header');
            if (headerContainer) headerContainer.innerHTML = '';

            const oldBottom = document.getElementById('nodes-pagination-bottom');
            if (oldBottom) oldBottom.remove();

            if (totalPages <= 1) return;

            const createPaginationHTML = (isTop) => {
                let html = `<div id="nodes-pagination-${isTop ? 'top' : 'bottom'}" class="flex items-center gap-2 ${isTop ? '' : 'mt-8 pb-4 flex justify-center'}">`;
                html += `<button onclick="changePage(${currentPage - 1})" class="btn btn-xs ${currentPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                        html += `<button onclick="changePage(${i})" class="btn btn-xs ${i === currentPage ? 'btn-neutral' : ''}">${i}</button>`;
                    } else if (i === currentPage - 3 || i === currentPage + 3) {
                        html += `<span class="px-0.5 text-gray-400">...</span>`;
                    }
                }
                html += `<button onclick="changePage(${currentPage + 1})" class="btn btn-xs ${currentPage === totalPages ? 'btn-disabled' : ''}">»</button>`;
                html += `</div>`;
                return html;
            };

            if (headerContainer) {
                headerContainer.innerHTML = createPaginationHTML(true);
            }

            const listDiv = document.getElementById('nodes-list');
            if (listDiv) {
                const bottomPagination = document.createElement('div');
                bottomPagination.id = 'nodes-pagination-bottom';
                bottomPagination.innerHTML = createPaginationHTML(false);
                listDiv.appendChild(bottomPagination);
            }
        }

        function changePage(page) {
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            loadNodes();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Generate random animation values
        function generateRandomAnimation() {
            return {
                radius: 5 + Math.random() * 3, // 5 to 8
                theta: Math.random() * 6.28, // 0 to 2π
                phi: Math.random() * 3.14, // 0 to π
                speed: 0.002 + (Math.random() * 0.004), // 0.002 to 0.006
                phase: Math.random() * 6.28 // 0 to 2π
            };
        }

        // Primary visual tab switcher (Image | Video | PDF). Audio is independent.
        // ctx is 'create' or 'edit'; tab is 'image' | 'video' | 'pdf'.
        function switchVisualTab(tab, ctx) {
            const types = ['image', 'video', 'pdf'];
            if (!types.includes(tab)) tab = 'image';
            for (const t of types) {
                const tabEl = document.getElementById(`${ctx}-${t}-tab`);
                const contentEl = document.getElementById(`${ctx}-${t}-content`);
                if (!tabEl || !contentEl) continue;
                if (t === tab) {
                    tabEl.classList.add('tab-active');
                    contentEl.classList.remove('hidden');
                } else {
                    tabEl.classList.remove('tab-active');
                    contentEl.classList.add('hidden');
                }
            }
            const hidden = document.getElementById(`${ctx}-visual-type`);
            if (hidden) hidden.value = tab;
        }

        // Media mode tabs (Classic vs Hotglue). The active mode is persisted to
        // nodes.media_mode on save, and decides what visitors see (phase 6).
        function switchMediaMode(mode, ctx) {
            if (mode !== 'hotglue') mode = 'classic';
            const classicTab = document.getElementById(`${ctx}-media-classic-tab`);
            const hotglueTab = document.getElementById(`${ctx}-media-hotglue-tab`);
            const classicContent = document.getElementById(`${ctx}-media-classic-content`);
            const hotglueContent = document.getElementById(`${ctx}-media-hotglue-content`);
            const isHotglue = (mode === 'hotglue');
            if (classicTab) classicTab.classList.toggle('tab-active', !isHotglue);
            if (hotglueTab) hotglueTab.classList.toggle('tab-active', isHotglue);
            if (classicContent) classicContent.classList.toggle('hidden', isHotglue);
            if (hotglueContent) hotglueContent.classList.toggle('hidden', !isHotglue);
            const hidden = document.getElementById(`${ctx}-media-mode`);
            if (hidden) hidden.value = mode;

            // The Edit Wormhole tab choice (Classic vs Hotglue) is a persisted field;
            // selecting a tab auto-saves it. (Suspended during populate, so no-op then.)
            if (ctx === 'edit' && typeof editAutosave !== 'undefined') editAutosave.saveNow();
        }

        // Open the per-node hotglue editor in the full-screen overlay. Same-origin,
        // so the editor's Telaris session + CSRF ride into the iframe; the phase-3
        // auth bridge enforces the seat + read-only checks on every write.
        function openHotglueEditor() {
            const id = document.getElementById('edit-id').value;
            if (!id) return;
            const stored = (document.getElementById('edit-hotglue-page').value || '').trim();
            const page = stored !== '' ? stored : ('node-' + id);
            const iframe = document.getElementById('hotglue-iframe');
            iframe.src = '../hg/?' + encodeURIComponent(page) + '/edit';
            document.getElementById('hotglue_modal').showModal();
        }

        function closeHotglueEditor() {
            const iframe = document.getElementById('hotglue-iframe');
            // Blank the iframe so its session/editor state is torn down on close.
            iframe.src = 'about:blank';
            document.getElementById('hotglue_modal').close();
        }

        // View node - preview modal
        async function viewNode(id) {
            let node = allNodes.find(n => n.id === id);
            if (!node) {
                try {
                    const res = await fetch(`${NODES_API}?id=${id}`, { headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN } });
                    if (res.ok) node = await res.json();
                } catch (e) { /* ignore */ }
            }
            if (!node) return;

            const title = document.getElementById('preview-title');
            const image = document.getElementById('preview-image');
            const imageWrap = document.getElementById('preview-image-wrap');
            const imageAttr = document.getElementById('preview-image-attribution');
            const embed = document.getElementById('preview-embed');
            const embedWrap = document.getElementById('preview-embed-wrap');
            const video = document.getElementById('preview-video');
            const videoWrap = document.getElementById('preview-video-wrap');
            const audio = document.getElementById('preview-audio');
            const audioWrap = document.getElementById('preview-audio-wrap');
            const desc = document.getElementById('preview-description');
            const urlWrap = document.getElementById('preview-url-wrap');
            const urlBtn = document.getElementById('preview-url-button');
            const kwWrap = document.getElementById('preview-keywords-wrap');
            const kwEl = document.getElementById('preview-keywords');

            title.textContent = node.name || '';

            // Ensure relative upload paths resolve correctly from /edit/
            const absUrl = (url) => url && url.startsWith('uploads/') ? '/' + url : url;

            // Hotglue media mode: preview the per-node hotglue page in a sandboxed
            // iframe (allow-scripts WITHOUT allow-same-origin, exactly as the visitor
            // popup) and skip the classic media. Mirrors showRichMediaWindow in
            // js/telaris-3d.js; the two preview paths must stay in sync.
            const hgWrap = document.getElementById('preview-hotglue-wrap');
            const hgEl = document.getElementById('preview-hotglue');
            const previewIsHotglue = (node.media_mode === 'hotglue');
            if (previewIsHotglue && hgWrap && hgEl) {
                const hgPage = node.hotglue_page || ('node-' + node.id);
                hgEl.src = '/hg/?' + encodeURIComponent(hgPage);
                hgEl.title = node.name || '';
                hgWrap.classList.remove('hidden');
                imageWrap.classList.add('hidden');
                embedWrap.classList.add('hidden'); embed.innerHTML = '';
                videoWrap.classList.add('hidden'); try { video.pause(); } catch (e) {}
                audioWrap.classList.add('hidden'); try { audio.pause(); } catch (e) {}
            } else if (hgWrap && hgEl) {
                hgWrap.classList.add('hidden');
                hgEl.src = 'about:blank';
            }

            if (!previewIsHotglue) {
            if (node.image_url) {
                image.src = absUrl(node.image_url);
                imageWrap.classList.remove('hidden');
                if (node.image_attribution) {
                    imageAttr.textContent = node.image_attribution;
                    imageAttr.classList.remove('hidden');
                } else {
                    imageAttr.classList.add('hidden');
                }
            } else {
                imageWrap.classList.add('hidden');
            }

            // M3 (audit pass #4, v6.10.17): construct a fresh iframe instead
            // of cloneNode(true) so any attribute the server sanitizer doesn't
            // strip (or might miss in a future regression) can't ride through
            // to the rendered DOM. Allowlist matches the visitor-side fix
            // from L-third-5 in js/telaris-3d.js. Both surfaces stay in sync.
            if (node.embed_code) {
                const tmp = document.createElement('div');
                tmp.innerHTML = node.embed_code;
                embed.innerHTML = '';
                const allowedAttrs = ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy', 'sandbox', 'title'];
                tmp.querySelectorAll('iframe').forEach(srcIframe => {
                    const src = srcIframe.getAttribute('src') || '';
                    if (!src.match(/^https?:\/\//i)) return;
                    const fresh = document.createElement('iframe');
                    allowedAttrs.forEach(attr => {
                        if (srcIframe.hasAttribute(attr)) {
                            fresh.setAttribute(attr, srcIframe.getAttribute(attr));
                        }
                    });
                    embed.appendChild(fresh);
                });
                embedWrap.classList.toggle('hidden', embed.children.length === 0);
            } else {
                embedWrap.classList.add('hidden');
                embed.innerHTML = '';
            }

            if (node.video_url) {
                video.src = absUrl(node.video_url);
                video.load();
                videoWrap.classList.remove('hidden');
            } else {
                video.src = '';
                video.load();
                videoWrap.classList.add('hidden');
            }

            if (node.audio_url) {
                audio.src = absUrl(node.audio_url);
                audio.loop = !!node.audio_loop;
                audio.load();
                audioWrap.classList.remove('hidden');

                const playPauseBtn = document.getElementById('preview-audio-play-pause');
                const stopBtn = document.getElementById('preview-audio-stop');
                const playIcon = document.getElementById('preview-play-icon');
                const pauseIcon = document.getElementById('preview-pause-icon');
                const progressBar = document.getElementById('preview-audio-progress');
                const progressContainer = document.getElementById('preview-audio-progress-container');
                const timeDisplay = document.getElementById('preview-audio-time');

                const updateTime = () => {
                    if (!audio.duration) return;
                    const pct = (audio.currentTime / audio.duration) * 100;
                    progressBar.style.width = pct + '%';
                    const mins = Math.floor(audio.currentTime / 60);
                    const secs = Math.floor(audio.currentTime % 60);
                    timeDisplay.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                };

                audio.onplay = () => { playIcon.classList.add('hidden'); pauseIcon.classList.remove('hidden'); };
                audio.onpause = () => { playIcon.classList.remove('hidden'); pauseIcon.classList.add('hidden'); };
                audio.onended = () => { playIcon.classList.remove('hidden'); pauseIcon.classList.add('hidden'); progressBar.style.width = '0%'; };
                audio.ontimeupdate = updateTime;

                playPauseBtn.onclick = () => { if (audio.paused) audio.play(); else audio.pause(); };
                stopBtn.onclick = () => { audio.pause(); audio.currentTime = 0; };
                progressContainer.onclick = (e) => {
                    const rect = progressContainer.getBoundingClientRect();
                    audio.currentTime = ((e.clientX - rect.left) / rect.width) * audio.duration;
                };

                audio.play().catch(() => {});
            } else {
                audio.pause();
                audio.onplay = null;
                audio.onpause = null;
                audio.onended = null;
                audio.ontimeupdate = null;
                audio.src = '';
                audioWrap.classList.add('hidden');
            }
            } // end if (!previewIsHotglue): classic media blocks

            desc.textContent = node.description || '';
            desc.classList.toggle('hidden', !node.description);

            if (node.url) {
                urlBtn.href = node.url;
                urlWrap.classList.remove('hidden');
            } else {
                urlWrap.classList.add('hidden');
            }

            const kws = node.keywords || [];
            if (kws.length > 0) {
                const pastelBgs = [
                    'rgba(254,202,202,0.25)', 'rgba(254,215,170,0.25)', 'rgba(253,230,138,0.25)',
                    'rgba(254,240,138,0.25)', 'rgba(217,249,157,0.25)', 'rgba(187,247,208,0.25)',
                    'rgba(167,243,208,0.25)', 'rgba(153,246,228,0.25)', 'rgba(165,243,252,0.25)',
                    'rgba(186,230,253,0.25)', 'rgba(191,219,254,0.25)', 'rgba(199,210,254,0.25)',
                    'rgba(221,214,254,0.25)', 'rgba(233,213,255,0.25)', 'rgba(245,208,254,0.25)',
                    'rgba(251,207,232,0.25)', 'rgba(254,205,211,0.25)'
                ];
                const pastelText = [
                    '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
                    '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
                    '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af'
                ];
                kwEl.innerHTML = '';
                kws.forEach(k => {
                    let hash = 0;
                    for (let i = 0; i < k.length; i++) hash = k.charCodeAt(i) + ((hash << 5) - hash);
                    const idx = Math.abs(hash) % pastelBgs.length;
                    const span = document.createElement('span');
                    span.style.cssText = `background:${pastelBgs[idx]};color:${pastelText[idx]};border:1px solid ${pastelText[idx]}40;padding:2px 10px;border-radius:9999px;font-size:0.75rem;font-weight:500;`;
                    span.textContent = `#${k}`;
                    kwEl.appendChild(span);
                });
                kwWrap.classList.remove('hidden');
            } else {
                kwWrap.classList.add('hidden');
            }

            const modal = document.getElementById('view_node_modal');
            modal.showModal();
            modal.onclose = () => {
                const a = document.getElementById('preview-audio');
                if (a) { a.pause(); a.onplay = null; a.onpause = null; a.onended = null; a.ontimeupdate = null; a.src = ''; }
                const v = document.getElementById('preview-video');
                if (v) { v.pause(); v.src = ''; v.load(); }
                const e = document.getElementById('preview-embed');
                if (e) e.innerHTML = '';
            };
        }

        // View constellation - open in new tab
        function viewConstellation(constellationId) {
            const c = CONSTELLATIONS.find(c => c.id === constellationId);
            const path = c && c.slug ? `/${c.slug}` : `/${constellationId}`;
            window.open(path, '_blank');
        }

        // Edit node - show modal
        async function editNode(id) {
            let node = allNodes.find(n => n.id === id);
            if (!node) {
                // Node not on current page — fetch from API
                try {
                    const response = await fetch(`${API_BASE}?id=${id}`, {
                        headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                    });
                    if (!response.ok) throw new Error('Failed to fetch node');
                    node = await response.json();
                } catch (e) {
                    showMessage(tFmt(TELARIS_EDIT.errorLoadingNode, e.message), 'error');
                    return;
                }
            }

            editingNodeId = id;

            // Suspend autosave while we set fields programmatically (setting .value does
            // not fire input/change, but this also installs listeners + resets state).
            editAutosave.beginPopulate();

            // Populate basic fields
            document.getElementById('edit-id').value = node.id;
            document.getElementById('edit-name').value = node.name || '';
            document.getElementById('edit-constellation').value = node.constellation_id;
            document.getElementById('edit-node-constellation-badge').textContent = '#' + node.id;
            document.getElementById('edit-node-type').value = node.node_type || 'object';
            document.getElementById('edit-description').value = node.description || '';
            document.getElementById('edit-url').value = node.url || '';
            document.getElementById('edit-embed-code').value = node.embed_code || '';
            document.getElementById('edit-audio-autoplay').checked = !!node.audio_autoplay;
            document.getElementById('edit-audio-loop').checked = !!node.audio_loop;
            document.getElementById('edit-video-autoplay').checked = !!node.video_autoplay;
            document.getElementById('edit-accentuated').checked = !!node.is_accentuated;
            document.getElementById('edit-show-keywords').checked = !!node.show_keywords;
            const editUseImage = document.getElementById('edit-use-image-as-node');
            if (editUseImage) editUseImage.checked = !!node.use_image_as_node;

            // Handle keywords
            keywordState['modal'] = [...(node.keywords || [])];
            updateKeywordTags('modal');

            // Clear file inputs so previously selected files are not re-uploaded
            document.getElementById('edit-image-file').value = '';
            document.getElementById('edit-audio-file').value = '';
            document.getElementById('edit-video-file').value = '';
            document.getElementById('edit-icon-file').value = '';

            // Handle image fields
            const imageFileWrap = document.getElementById('edit-image-file-wrap');
            const imageExisting = document.getElementById('edit-image-existing');
            const imageExistingName = document.getElementById('edit-image-existing-name');
            const imageUrlInput = document.getElementById('edit-image-url');
            
            if (node.image_url && node.image_url.startsWith('uploads/')) {
                imageFileWrap.classList.add('hidden');
                imageExisting.classList.remove('hidden');
                imageExistingName.value = node.image_url.split('/').pop();
                imageUrlInput.value = node.image_url; // Hidden but holds path
            } else {
                imageFileWrap.classList.remove('hidden');
                imageExisting.classList.add('hidden');
                imageUrlInput.value = node.image_url || '';
            }
            document.getElementById('edit-image-attribution').value = node.image_attribution || '';

            // Handle audio fields
            const audioFileWrap = document.getElementById('edit-audio-file-wrap');
            const audioExisting = document.getElementById('edit-audio-existing');
            const audioExistingName = document.getElementById('edit-audio-existing-name');
            const audioUrlInput = document.getElementById('edit-audio-url');

            if (node.audio_url && node.audio_url.startsWith('uploads/')) {
                audioFileWrap.classList.add('hidden');
                audioExisting.classList.remove('hidden');
                audioExistingName.value = node.audio_url.split('/').pop();
                audioUrlInput.value = node.audio_url; 
            } else {
                audioFileWrap.classList.remove('hidden');
                audioExisting.classList.add('hidden');
                audioUrlInput.value = node.audio_url || '';
            }

            // Handle video fields
            const videoFileWrap = document.getElementById('edit-video-file-wrap');
            const videoExisting = document.getElementById('edit-video-existing');
            const videoExistingName = document.getElementById('edit-video-existing-name');
            const videoUrlInput = document.getElementById('edit-video-url');

            if (node.video_url && node.video_url.startsWith('uploads/')) {
                videoFileWrap.classList.add('hidden');
                videoExisting.classList.remove('hidden');
                videoExistingName.value = node.video_url.split('/').pop();
                videoUrlInput.value = node.video_url;
            } else {
                videoFileWrap.classList.remove('hidden');
                videoExisting.classList.add('hidden');
                videoUrlInput.value = node.video_url || '';
            }

            // Handle icon fields
            const iconFileWrap = document.getElementById('edit-icon-file-wrap');
            const iconExisting = document.getElementById('edit-icon-existing');
            const iconExistingName = document.getElementById('edit-icon-existing-name');
            const iconUrlInput = document.getElementById('edit-icon-url');

            if (node.icon_url && node.icon_url.startsWith('uploads/')) {
                iconFileWrap.classList.add('hidden');
                iconExisting.classList.remove('hidden');
                iconExistingName.value = node.icon_url.split('/').pop();
                iconUrlInput.value = node.icon_url;
            } else {
                iconFileWrap.classList.remove('hidden');
                iconExisting.classList.add('hidden');
                iconUrlInput.value = node.icon_url || '';
            }

            // Handle PDF fields (mutex with image+video enforced server-side).
            const pdfFileWrap = document.getElementById('edit-pdf-file-wrap');
            const pdfExisting = document.getElementById('edit-pdf-existing');
            const pdfExistingName = document.getElementById('edit-pdf-existing-name');
            const pdfUrlInput = document.getElementById('edit-pdf-url');
            if (pdfUrlInput) {
                if (node.pdf_url && node.pdf_url.startsWith('uploads/')) {
                    pdfFileWrap.classList.add('hidden');
                    pdfExisting.classList.remove('hidden');
                    pdfExistingName.value = node.pdf_url.split('/').pop();
                    pdfUrlInput.value = node.pdf_url;
                } else {
                    pdfFileWrap.classList.remove('hidden');
                    pdfExisting.classList.add('hidden');
                    pdfUrlInput.value = node.pdf_url || '';
                }
            }

            // Pick the active primary-visual tab based on which URL is set on the node.
            // Audio is independent; its block is always visible.
            let visual = 'image';
            if (node.pdf_url) visual = 'pdf';
            else if (node.video_url) visual = 'video';
            switchVisualTab(visual, 'edit');

            // Media mode (Classic vs Hotglue) and the node's hotglue page slug.
            document.getElementById('edit-hotglue-page').value = node.hotglue_page || ('node-' + node.id);
            switchMediaMode(node.media_mode === 'hotglue' ? 'hotglue' : 'classic', 'edit');

            // Toggle target constellation if portal
            toggleTargetConstellation(node.node_type || 'object', 'modal');
            if (node.node_type === 'portal') {
                document.getElementById('edit-target-constellation-modal').value = node.target_constellation_id || '';
            }

            setWormholeMode('edit');
            document.getElementById('edit_modal').showModal();

            // Resume autosave now that the form reflects the node.
            editAutosave.endPopulate();
        }

        // Edit Wormhole modal save.
        //
        // The modal auto-saves (no explicit "Update" button): text edits are debounced,
        // toggles/selects/keywords/file-picks save immediately, and closing the modal
        // flushes any pending change. buildEditFormData() assembles the current form
        // state; the editAutosave controller (below) drives sending it. Returns
        // { fd, fileTypes } or null when the form can't be saved yet (empty name, or no
        // API key) — the controller reflects that in the status chip.
        function buildEditFormData() {
            const nodeId = parseInt(document.getElementById('edit-id').value);
            const nodeName = document.getElementById('edit-name').value.trim();

            if (!nodeName) return null;
            if (!API_KEY) return null;

            const node = allNodes.find(n => n.id === nodeId);

            const formData = new FormData();
            formData.append('id', nodeId);
            formData.append('name', nodeName);
            formData.append('description', document.getElementById('edit-description').value.trim());
            formData.append('url', document.getElementById('edit-url').value.trim());
            
            // Image-related fields (image_url, image_attribution, use_image_as_node) are
            // appended below in the visual-tab block — only when the Image tab is active.
            formData.append('embed_code', document.getElementById('edit-embed-code').value.trim());
            formData.append('is_accentuated', document.getElementById('edit-accentuated').checked ? 1 : 0);
            formData.append('show_keywords', document.getElementById('edit-show-keywords').checked ? 1 : 0);
            formData.append('constellation_id', document.getElementById('edit-constellation').value);
            
            const nodeType = document.getElementById('edit-node-type').value;
            formData.append('node_type', nodeType);
            
            if (nodeType === 'portal') {
                formData.append('target_constellation_id', document.getElementById('edit-target-constellation-modal').value);
            }
            
            if (node) {
                formData.append('animation', JSON.stringify(node.animation));
            }
            
            formData.append('keywords', (keywordState['modal'] || []).join(','));

            // Media mode: whichever tab (Classic / Hotglue) is active is what visitors get.
            formData.append('media_mode', document.getElementById('edit-media-mode').value || 'classic');

            // Icon (independent of the visual mutex).
            formData.append('icon_url', document.getElementById('edit-icon-url').value.trim());
            const iconFile = document.getElementById('edit-icon-file').files[0];
            if (iconFile) formData.append('icon_file', iconFile);

            // Primary visual: send only the active tab's URL/file; clear the other two so
            // the server-side mutex doesn't fight a stale value.
            const visualType = document.getElementById('edit-visual-type')?.value || 'image';
            // Credit/attribution is shared across all visual types now (not just image).
            formData.append('image_attribution', document.getElementById('edit-image-attribution').value.trim());

            if (visualType === 'image') {
                formData.append('image_url', document.getElementById('edit-image-url').value.trim());
                formData.append('use_image_as_node', document.getElementById('edit-use-image-as-node').checked ? 1 : 0);
                const imageFile = document.getElementById('edit-image-file').files[0];
                if (imageFile) formData.append('image_file', imageFile);
                formData.append('video_url', '');
                formData.append('pdf_url', '');
            } else if (visualType === 'video') {
                formData.append('video_url', document.getElementById('edit-video-url').value.trim());
                formData.append('video_autoplay', document.getElementById('edit-video-autoplay').checked ? 1 : 0);
                const videoFile = document.getElementById('edit-video-file').files[0];
                if (videoFile) formData.append('video_file', videoFile);
                formData.append('image_url', '');
                formData.append('use_image_as_node', 0);
                formData.append('pdf_url', '');
            } else {
                formData.append('pdf_url', document.getElementById('edit-pdf-url').value.trim());
                const pdfFile = document.getElementById('edit-pdf-file').files[0];
                if (pdfFile) formData.append('pdf_file', pdfFile);
                formData.append('image_url', '');
                formData.append('use_image_as_node', 0);
                formData.append('video_url', '');
            }

            // Audio is always sent (independent of the visual mutex).
            formData.append('audio_url', document.getElementById('edit-audio-url').value.trim());
            formData.append('audio_autoplay', document.getElementById('edit-audio-autoplay').checked ? 1 : 0);
            formData.append('audio_loop', document.getElementById('edit-audio-loop').checked ? 1 : 0);
            const audioFile = document.getElementById('edit-audio-file').files[0];
            if (audioFile) formData.append('audio_file', audioFile);

            const fileTypes = [];
            ['image', 'video', 'pdf', 'audio', 'icon'].forEach(t => {
                if (formData.get(t + '_file') instanceof File) fileTypes.push(t);
            });
            return { fd: formData, fileTypes };
        }

        // Update one file field's UI (existing-file chip vs file picker) from a stored
        // URL. Mirrors the per-type blocks in editNode(); used to refresh the picker
        // after an autosave upload so the new uploads/ path shows and is not re-sent.
        function setEditFileFieldUI(type, url) {
            const wrap = document.getElementById(`edit-${type}-file-wrap`);
            const existing = document.getElementById(`edit-${type}-existing`);
            const nameEl = document.getElementById(`edit-${type}-existing-name`);
            const urlInput = document.getElementById(`edit-${type}-url`);
            if (!urlInput) return;
            if (url && url.startsWith('uploads/')) {
                if (wrap) wrap.classList.add('hidden');
                if (existing) existing.classList.remove('hidden');
                if (nameEl) nameEl.value = url.split('/').pop();
                urlInput.value = url;
            } else {
                if (wrap) wrap.classList.remove('hidden');
                if (existing) existing.classList.add('hidden');
                urlInput.value = url || '';
            }
        }

        // Edit Wormhole autosave controller. Replaces the explicit "Update Wormhole"
        // button. Debounces text edits, saves toggles/selects/keywords/file-picks
        // immediately, serializes overlapping saves, and flushes on modal close. The
        // hotglue overlay has its own autosave; this only ever persists wormhole
        // fields (never hotglue page content), so the two never fight.
        const editAutosave = (function () {
            const DEBOUNCE_MS = 1000;
            let dirty = false;       // an un-sent change is pending
            let inflight = false;    // a save request is currently in flight
            let queued = false;      // a change arrived while a save was in flight
            let timer = null;
            let suspended = true;    // true while populating the form or modal closed
            let installed = false;   // listeners attached once
            let pendingReload = false; // reload the list once the in-flight save drains
            let touched = false;     // at least one save was sent during this open

            function statusEls() {
                const root = document.getElementById('edit-autosave-status');
                if (!root) return null;
                return {
                    spinner: root.querySelector('[data-autosave-spinner]'),
                    text: root.querySelector('[data-autosave-text]'),
                };
            }
            function setStatus(state, overrideText) {
                const els = statusEls();
                if (!els) return;
                const map = {
                    idle:   { txt: TELARIS_EDIT.autosaveSaved,  cls: 'text-gray-400', spin: false },
                    saving: { txt: TELARIS_EDIT.autosaveSaving, cls: 'text-gray-500', spin: true  },
                    saved:  { txt: TELARIS_EDIT.autosaveSaved,  cls: 'text-green-600', spin: false },
                    failed: { txt: TELARIS_EDIT.autosaveFailed, cls: 'text-red-600',  spin: false },
                };
                const s = map[state] || map.idle;
                if (els.text) {
                    els.text.textContent = overrideText || s.txt;
                    els.text.className = 'text-xs font-medium ' + s.cls;
                }
                if (els.spinner) els.spinner.classList.toggle('hidden', !s.spin);
            }

            function patchLocalNode(fd) {
                const id = parseInt(document.getElementById('edit-id').value, 10);
                const node = allNodes.find(n => n.id === id);
                if (!node) return;
                const g = k => fd.get(k);
                node.name = g('name');
                node.description = g('description');
                node.url = g('url');
                node.embed_code = g('embed_code');
                node.is_accentuated = g('is_accentuated') === '1' ? 1 : 0;
                node.show_keywords = g('show_keywords') === '1' ? 1 : 0;
                node.constellation_id = parseInt(g('constellation_id'), 10);
                node.node_type = g('node_type');
                if (node.node_type === 'portal') {
                    node.target_constellation_id = parseInt(g('target_constellation_id'), 10) || null;
                }
                node.media_mode = g('media_mode') || 'classic';
                node.hotglue_page = document.getElementById('edit-hotglue-page').value || ('node-' + id);
                node.icon_url = g('icon_url');
                node.image_attribution = g('image_attribution');
                node.keywords = (keywordState['modal'] || []).slice();
                node.audio_autoplay = g('audio_autoplay') === '1' ? 1 : 0;
                node.audio_loop = g('audio_loop') === '1' ? 1 : 0;
                node.video_autoplay = g('video_autoplay') === '1' ? 1 : 0;
                // URLs: skip when a file was uploaded — the stored path comes from refetch.
                if (!(fd.get('image_file') instanceof File)) node.image_url = g('image_url');
                if (!(fd.get('video_file') instanceof File)) node.video_url = g('video_url');
                if (!(fd.get('pdf_file') instanceof File)) node.pdf_url = g('pdf_url');
                if (!(fd.get('audio_file') instanceof File)) node.audio_url = g('audio_url');
            }

            async function refetchFileUI() {
                const id = document.getElementById('edit-id').value;
                try {
                    const r = await fetch(`${API_BASE}?id=${id}`, {
                        headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                    });
                    if (!r.ok) return;
                    const node = await r.json();
                    ['image', 'audio', 'video', 'icon', 'pdf'].forEach(t => setEditFileFieldUI(t, node[`${t}_url`]));
                    const ln = allNodes.find(n => n.id === parseInt(id, 10));
                    if (ln) ['image', 'audio', 'video', 'icon', 'pdf'].forEach(t => { ln[`${t}_url`] = node[`${t}_url`]; });
                } catch (e) { /* leave the picker as-is on a transient fetch error */ }
            }

            function drained() {
                if (pendingReload && !queued && !inflight) {
                    pendingReload = false;
                    loadNodes();
                }
            }

            function send() {
                if (suspended) return;
                const built = buildEditFormData();
                if (!built) {
                    // Can't save yet (empty name). Keep the change pending so a later
                    // valid edit retries; surface it quietly in the chip, no toast.
                    setStatus('failed', TELARIS_EDIT.errorNameRequired);
                    drained();
                    return;
                }
                if (timer) { clearTimeout(timer); timer = null; }
                inflight = true;
                touched = true;
                dirty = false;
                setStatus('saving');

                const { fd, fileTypes } = built;
                const progressWrap = document.getElementById('edit-progress-wrap');
                const progressBar = document.getElementById('edit-progress-bar');
                const progressText = document.getElementById('edit-progress-text');
                if (fileTypes.length && progressWrap) progressWrap.classList.remove('hidden');

                const xhr = new XMLHttpRequest();
                xhr.open('POST', API_BASE, true);
                xhr.setRequestHeader('X-API-Key', API_KEY);
                xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
                xhr.setRequestHeader('X-HTTP-Method-Override', 'PUT');

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable && progressBar) {
                        const pct = Math.round((e.loaded / e.total) * 100);
                        progressBar.value = pct;
                        progressText.textContent = pct + '%';
                    }
                };
                const finishProgress = () => {
                    if (progressWrap) progressWrap.classList.add('hidden');
                    if (progressBar) progressBar.value = 0;
                    if (progressText) progressText.textContent = '0%';
                };

                xhr.onload = () => {
                    inflight = false;
                    finishProgress();
                    if (xhr.status >= 200 && xhr.status < 300) {
                        setStatus('saved');
                        // Clear uploaded file inputs so the next save won't re-send them.
                        fileTypes.forEach(t => { const fi = document.getElementById(`edit-${t}-file`); if (fi) fi.value = ''; });
                        patchLocalNode(fd);
                        if (fileTypes.length) refetchFileUI();
                    } else {
                        dirty = true; // allow retry on the next edit
                        let msg = TELARIS_EDIT.errorFailedUpdate;
                        try { const r = JSON.parse(xhr.responseText); msg = r.error || msg; } catch (e) {}
                        setStatus('failed');
                        showMessage(`Error: ${msg} (${xhr.status})`, 'error');
                    }
                    if (queued) { queued = false; flush(); return; }
                    drained();
                };
                xhr.onerror = () => {
                    inflight = false;
                    dirty = true;
                    finishProgress();
                    setStatus('failed');
                    showMessage(TELARIS_EDIT.errorNetworkUpload, 'error');
                    if (queued) { queued = false; flush(); return; }
                    drained();
                };
                xhr.send(fd);
            }

            function flush() {
                if (suspended || !dirty) return;
                if (inflight) { queued = true; return; }
                if (timer) { clearTimeout(timer); timer = null; }
                send();
            }

            function scheduleSave() {
                if (suspended) return;
                dirty = true;
                setStatus('saving');
                if (timer) clearTimeout(timer);
                timer = setTimeout(flush, DEBOUNCE_MS);
            }
            function saveNow() {
                if (suspended) return;
                dirty = true;
                flush();
            }
            // Send a pending edit immediately, but never force a save when nothing changed
            // (used for text-field blur, which fires whether or not the value changed).
            function flushNow() {
                if (suspended || !dirty) return;
                flush();
            }

            function installListeners() {
                if (installed) return;
                installed = true;
                const form = document.getElementById('edit-node-form');
                if (!form) return;
                form.addEventListener('input', (e) => {
                    const t = e.target;
                    if (!t || t.disabled) return;
                    if (t.id === 'edit-keywords-input-modal') return; // keyword text box; commit fires saveNow
                    const type = (t.type || '').toLowerCase();
                    if (type === 'file' || type === 'checkbox' || type === 'radio' || t.tagName === 'SELECT') return;
                    scheduleSave();
                });
                form.addEventListener('change', (e) => {
                    const t = e.target;
                    if (!t || t.disabled) return;
                    if (t.id === 'edit-keywords-input-modal') return;
                    const type = (t.type || '').toLowerCase();
                    if (type === 'file' || type === 'checkbox' || type === 'radio' || t.tagName === 'SELECT') {
                        saveNow(); // a real value change: file pick, toggle, or select
                    } else {
                        flushNow(); // text-field blur: send only if an edit is pending
                    }
                });
                const modal = document.getElementById('edit_modal');
                if (modal) modal.addEventListener('close', () => {
                    // Flush any pending edit; refresh the list once saves drain, but only
                    // if something was actually saved (or is still pending) this session.
                    if (dirty && !inflight) { pendingReload = true; send(); }
                    else if (inflight) { pendingReload = true; }
                    else if (touched) { loadNodes(); }
                });
            }

            return {
                // Called by editNode(): suspend autosave, then resume after populate.
                beginPopulate() { suspended = true; if (timer) { clearTimeout(timer); timer = null; } dirty = false; queued = false; touched = false; pendingReload = false; installListeners(); },
                endPopulate() { suspended = false; inflight = false; pendingReload = false; setStatus('idle'); },
                scheduleSave, saveNow,
            };
        })();

        // Thin wrapper kept for the form's onsubmit (Enter key) — flush immediately.
        function saveNodeEdit(event) {
            if (event) event.preventDefault();
            editAutosave.saveNow();
        }

        // Submit a NEW wormhole (one-shot POST) from the unified modal in create mode.
        // Editing an existing wormhole autosaves instead (editAutosave), so this path is
        // create-only and targets the unified modal's create-mode submit + progress.
        function handleNodeSubmit(formData, context, method = 'POST') {
            const submitBtn = document.getElementById('edit-submit-btn');
            const loader = document.getElementById('edit-submit-loader');
            const progressWrap = document.getElementById('edit-progress-wrap');
            const progressBar = document.getElementById('edit-progress-bar');
            const progressText = document.getElementById('edit-progress-text');
            const modalId = 'edit_modal';

            submitBtn.disabled = true;
            loader.classList.remove('hidden');
            
            // Only show progress if files are being uploaded
            const hasFiles = formData.has('image_file') || formData.has('audio_file') || formData.has('video_file') || formData.has('icon_file');
            if (hasFiles) progressWrap.classList.remove('hidden');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', API_BASE, true);
            xhr.setRequestHeader('X-API-Key', API_KEY);
            xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
            if (method === 'PUT') {
                xhr.setRequestHeader('X-HTTP-Method-Override', 'PUT');
            }

            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressBar.value = percent;
                    progressText.textContent = percent + '%';
                }
            };

            xhr.onload = () => {
                submitBtn.disabled = false;
                loader.classList.add('hidden');
                progressWrap.classList.add('hidden');
                progressBar.value = 0;
                progressText.textContent = '0%';

                if (xhr.status >= 200 && xhr.status < 300) {
                    document.getElementById(modalId).close();
                    let successMsg = context === 'edit' ? TELARIS_EDIT.toastUpdatedSuccess : TELARIS_EDIT.toastCreatedSuccess;
                    try {
                        const resp = JSON.parse(xhr.responseText);
                        if (resp.notice) successMsg += '. ' + resp.notice;
                    } catch (e) {}
                    showMessage(successMsg);
                    loadNodes();
                } else {
                    let errorMsg = context === 'edit' ? TELARIS_EDIT.errorFailedUpdate : TELARIS_EDIT.errorFailedCreate;
                    try {
                        const response = JSON.parse(xhr.responseText);
                        errorMsg = response.error || errorMsg;
                    } catch (e) {}
                    showMessage(`Error: ${errorMsg} (${xhr.status})`, 'error');
                    console.error('Submit failed:', xhr.status, xhr.responseText);
                }
            };

            xhr.onerror = () => {
                submitBtn.disabled = false;
                loader.classList.add('hidden');
                progressWrap.classList.add('hidden');
                showMessage(TELARIS_EDIT.errorNetworkUpload, 'error');
            };

            xhr.send(formData);
        }

        // Helper for custom confirmation modal
        function confirmAction(message, onConfirm) {
            const modal = document.getElementById('delete_confirm_modal');
            const messageEl = document.getElementById('delete-confirm-message');
            const confirmBtn = document.getElementById('delete-confirm-btn');
            
            messageEl.textContent = message;
            
            // Clone button to remove old listeners
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            newConfirmBtn.onclick = () => {
                onConfirm();
                modal.close();
            };
            
            modal.showModal();
        }

        // Delete node file from modal context
        async function deleteModalFile(type) {
            const nodeId = document.getElementById('edit-id').value;
            if (!nodeId) return;

            confirmAction(tFmt(TELARIS_EDIT.confirmDeleteFile, type), async () => {
                try {
                    const response = await fetch(`${API_BASE}?id=${nodeId}&file_type=${type}`, {
                        method: 'DELETE',
                        headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                    });

                    if (!response.ok) throw new Error('Failed to delete file');

                    showMessage(tFmt(TELARIS_EDIT.toastFileDeleted, type.charAt(0).toUpperCase() + type.slice(1)));
                    
                    // Update UI in modal
                    document.getElementById(`edit-${type}-file-wrap`).classList.remove('hidden');
                    document.getElementById(`edit-${type}-existing`).classList.add('hidden');
                    document.getElementById(`edit-${type}-url`).value = '';
                    
                    // Update allNodes so if we close and re-open without reload it stays deleted
                    const node = allNodes.find(n => n.id === parseInt(nodeId));
                    if (node) {
                        if (type === 'image') node.image_url = '';
                        else if (type === 'audio') node.audio_url = '';
                        else if (type === 'video') node.video_url = '';
                        else if (type === 'icon') node.icon_url = '';
                        else if (type === 'pdf') node.pdf_url = '';
                    }
                    
                } catch (error) {
                    showMessage(tFmt(TELARIS_EDIT.errorDeletingFile, error.message), 'error');
                }
            });
        }

        // Delete node
        async function deleteNode(id, name) {
            confirmAction(tFmt(TELARIS_EDIT.confirmDeleteNode, name), async () => {
                try {
                    const response = await fetch(`${API_BASE}?id=${id}`, {
                        method: 'DELETE',
                        headers: {
                            'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN
                        }
                    });

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.error || TELARIS_EDIT.errorDeleteWormhole);
                    }

                    showMessage(TELARIS_EDIT.toastDeletedSuccess);
                    loadNodes();
                } catch (error) {
                    showMessage(tFmt(TELARIS_EDIT.errorDeletingWormhole, error.message), 'error');
                }
            });
        }

        // The create and edit wormhole windows are ONE modal (#edit_modal). This mode
        // flag decides its chrome: 'create' shows an explicit Add button (a new wormhole
        // has no id to autosave against yet); 'edit' shows the live autosave chip.
        let wormholeModalMode = 'edit';

        function setWormholeMode(mode) {
            wormholeModalMode = mode;
            const isCreate = mode === 'create';
            const show = (id, on) => { const el = document.getElementById(id); if (el) el.classList.toggle('hidden', !on); };
            show('wm-heading-create', isCreate);
            show('wm-heading-edit', !isCreate);
            show('edit-submit-btn', isCreate);          // create: explicit Add button
            show('edit-autosave-status', !isCreate);    // edit: live autosave chip
            show('edit-hotglue-create-note', isCreate); // hotglue tab: "save first" note (create)
            show('edit-hotglue-edit-wrap', !isCreate);  // vs the live "Edit hotglue content" button (edit)
        }

        // The unified form's submit handler. Create mode: Add a new wormhole. Edit mode:
        // the modal autosaves, so a stray Enter just flushes any pending change.
        function onWormholeFormSubmit(event) {
            if (wormholeModalMode === 'create') { saveNewNode(event); }
            else { saveNodeEdit(event); }
        }

        // Open the unified modal in CREATE mode: blank the fields and keep autosave
        // suspended (beginPopulate installs the listeners but does not engage; we never
        // call endPopulate here, so nothing autosaves until the wormhole exists).
        function openCreateNodeModal() {
            editingNodeId = null;
            editAutosave.beginPopulate();

            const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
            const setChk = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v; };

            setVal('edit-id', '');
            const badge = document.getElementById('edit-node-constellation-badge');
            if (badge) badge.textContent = '';
            ['edit-name', 'edit-description', 'edit-url', 'edit-embed-code', 'edit-image-url',
             'edit-video-url', 'edit-pdf-url', 'edit-audio-url', 'edit-icon-url',
             'edit-image-attribution'].forEach(id => setVal(id, ''));
            // Create defaults (match the former Add form).
            setChk('edit-accentuated', false);
            setChk('edit-show-keywords', false);
            setChk('edit-use-image-as-node', false);
            setChk('edit-audio-autoplay', true);
            setChk('edit-audio-loop', false);
            setChk('edit-video-autoplay', true);

            // No existing files on a new node: clear inputs, show pickers, hide the
            // "existing file + Delete" rows.
            ['image', 'video', 'pdf', 'audio', 'icon'].forEach(t => {
                const fi = document.getElementById(`edit-${t}-file`); if (fi) fi.value = '';
                const wrap = document.getElementById(`edit-${t}-file-wrap`); if (wrap) wrap.classList.remove('hidden');
                const existing = document.getElementById(`edit-${t}-existing`); if (existing) existing.classList.add('hidden');
            });

            // Default the galaxy to the currently-selected one.
            const current = document.getElementById('current-constellation');
            if (current) { const v = current.value; setVal('edit-constellation', v === 'all' ? '0' : v); }

            setVal('edit-node-type', 'object');
            keywordState['modal'] = [];
            updateKeywordTags('modal');
            toggleTargetConstellation('object', 'modal');

            switchVisualTab('image', 'edit');
            setVal('edit-hotglue-page', '');
            switchMediaMode('classic', 'edit');

            setWormholeMode('create');
            document.getElementById('edit_modal').showModal();
        }

        // Save a NEW wormhole from the unified modal (create mode). Reads the same
        // edit-* fields the edit flow uses; the API POST creates the row.
        async function saveNewNode(event) {
            event.preventDefault();

            const nodeName = document.getElementById('edit-name').value.trim();
            if (!nodeName) {
                showMessage(TELARIS_EDIT.errorNameRequired, 'error');
                return;
            }
            if (!API_KEY) {
                showMessage(TELARIS_EDIT.errorApiKeyMissing, 'error');
                return;
            }

            const animation = generateRandomAnimation();
            const constellationId = parseInt(document.getElementById('edit-constellation').value);
            const nodeType = document.getElementById('edit-node-type').value;

            const formData = new FormData();
            formData.append('name', nodeName);
            formData.append('description', document.getElementById('edit-description').value.trim());
            formData.append('url', document.getElementById('edit-url').value.trim());
            formData.append('embed_code', document.getElementById('edit-embed-code').value.trim());
            formData.append('is_accentuated', document.getElementById('edit-accentuated').checked ? 1 : 0);
            formData.append('show_keywords', document.getElementById('edit-show-keywords').checked ? 1 : 0);
            formData.append('constellation_id', isNaN(constellationId) ? 0 : constellationId);
            formData.append('node_type', nodeType);

            if (nodeType === 'portal') {
                formData.append('target_constellation_id', document.getElementById('edit-target-constellation-modal').value);
            }
            formData.append('animation', JSON.stringify(animation));
            formData.append('keywords', (keywordState['modal'] || []).join(','));
            // Persist the media mode chosen on the tabs (Hotglue is composed after creation).
            formData.append('media_mode', document.getElementById('edit-media-mode')?.value || 'classic');

            // Icon (independent of the visual mutex).
            formData.append('icon_url', document.getElementById('edit-icon-url').value.trim());
            const iconFile = document.getElementById('edit-icon-file').files[0];
            if (iconFile) formData.append('icon_file', iconFile);

            // Credit/attribution is shared across all visual types.
            formData.append('image_attribution', document.getElementById('edit-image-attribution').value.trim());

            // Primary visual: send only the active tab's URL/file; clear the other two.
            const visualType = document.getElementById('edit-visual-type')?.value || 'image';
            if (visualType === 'image') {
                formData.append('image_url', document.getElementById('edit-image-url').value.trim());
                const useImg = document.getElementById('edit-use-image-as-node');
                formData.append('use_image_as_node', (useImg && useImg.checked) ? 1 : 0);
                const imageFile = document.getElementById('edit-image-file').files[0];
                if (imageFile) formData.append('image_file', imageFile);
                formData.append('video_url', '');
                formData.append('pdf_url', '');
            } else if (visualType === 'video') {
                formData.append('video_url', document.getElementById('edit-video-url').value.trim());
                formData.append('video_autoplay', document.getElementById('edit-video-autoplay').checked ? 1 : 0);
                const videoFile = document.getElementById('edit-video-file').files[0];
                if (videoFile) formData.append('video_file', videoFile);
                formData.append('image_url', '');
                formData.append('use_image_as_node', 0);
                formData.append('pdf_url', '');
            } else {
                formData.append('pdf_url', document.getElementById('edit-pdf-url').value.trim());
                const pdfFile = document.getElementById('edit-pdf-file').files[0];
                if (pdfFile) formData.append('pdf_file', pdfFile);
                formData.append('image_url', '');
                formData.append('use_image_as_node', 0);
                formData.append('video_url', '');
            }

            // Audio (independent of the visual mutex).
            formData.append('audio_url', document.getElementById('edit-audio-url').value.trim());
            formData.append('audio_autoplay', document.getElementById('edit-audio-autoplay').checked ? 1 : 0);
            formData.append('audio_loop', document.getElementById('edit-audio-loop').checked ? 1 : 0);
            const audioFile = document.getElementById('edit-audio-file').files[0];
            if (audioFile) formData.append('audio_file', audioFile);

            handleNodeSubmit(formData, 'create', 'POST');
        }

        // Wait for DOM to be ready
        document.addEventListener('DOMContentLoaded', () => {
            // Load nodes on page load
            try {
                loadNodes().catch(error => {
                    const listDiv = document.getElementById('nodes-list');
                    if (listDiv) {
                        listDiv.innerHTML = '<p class="text-red-600">' + escapeHtml(tFmt(TELARIS_EDIT.errorFatalLoading, error.message)) + '</p>';
                    }
                });
            } catch (error) {
                const listDiv = document.getElementById('nodes-list');
                if (listDiv) {
                    listDiv.innerHTML = '<p class="text-red-600">' + escapeHtml(tFmt(TELARIS_EDIT.errorCouldNotLoad, error.message)) + '</p>';
                }
            }
        });
        

        
        // Keyword Tag Management
        const keywordState = {}; // Stores arrays of keywords for each context (nodeId or 'add')

        // Helper for pastel colors
        function getPastelColor(str) {
            // Dark-console keyword chips: bright constellation pastel text on a
            // faint same-hue plate (the canonical chip look from the brand book
            // and the visitor view). Seventeen .tel-kw-N classes are defined in
            // inc/admin-console-theme.php, one per constellation pastel.
            const pastelColors = [
                'tel-kw-0', 'tel-kw-1', 'tel-kw-2', 'tel-kw-3', 'tel-kw-4',
                'tel-kw-5', 'tel-kw-6', 'tel-kw-7', 'tel-kw-8', 'tel-kw-9',
                'tel-kw-10', 'tel-kw-11', 'tel-kw-12', 'tel-kw-13', 'tel-kw-14',
                'tel-kw-15', 'tel-kw-16'
            ];
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                hash = str.charCodeAt(i) + ((hash << 5) - hash);
            }
            const index = Math.abs(hash) % pastelColors.length;
            return pastelColors[index];
        }

        function updateKeywordTags(contextId) {
            const container = document.getElementById(`keywords-container-${contextId}`);
            let hiddenInputId = '';
            if (contextId === 'modal') {
                hiddenInputId = 'edit-keywords-hidden';
            } else if (contextId === 'create' || contextId === 'add') {
                hiddenInputId = 'node-keywords';
            } else {
                hiddenInputId = `edit-keywords-${contextId}`;
            }
            const hiddenInput = document.getElementById(hiddenInputId);
            if (!container || !hiddenInput) return;

            const keywords = keywordState[contextId] || [];
            hiddenInput.value = keywords.join(',');

            // Remove all existing badges
            container.querySelectorAll('.badge').forEach(el => el.remove());

            // Re-render badges before the input
            const input = container.querySelector('input');
            keywords.forEach((kw, index) => {
                const colorClass = getPastelColor(kw);
                const badge = document.createElement('div');
                badge.className = `badge ${colorClass} gap-2 py-3 px-3 border border-current/20`;
                badge.innerHTML = `
                    ${escapeHtml(kw)}
                    <svg onclick="removeKeyword('${contextId}', ${index})" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block w-4 h-4 stroke-current cursor-pointer hover:opacity-70"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                `;
                container.insertBefore(badge, input);
            });

            // Keyword changes in the Edit Wormhole modal auto-save immediately.
            // (No-op while the modal is populating or closed — the controller is suspended.)
            if (contextId === 'modal' && typeof editAutosave !== 'undefined') editAutosave.saveNow();
        }

        function addKeywords(text, contextId) {
            if (!text) return;
            const parts = text.split(',').map(p => p.trim()).filter(p => p !== '');
            if (parts.length === 0) return;

            if (!keywordState[contextId]) keywordState[contextId] = [];
            let added = false;
            parts.forEach(kw => {
                if (!keywordState[contextId].includes(kw)) {
                    keywordState[contextId].push(kw);
                    added = true;
                }
            });

            if (added) {
                updateKeywordTags(contextId);
            }
        }

        function handleKeywordInput(event, contextId) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                addKeywords(event.target.value, contextId);
                event.target.value = '';
            } else if (event.key === 'Backspace' && event.target.value === '') {
                if (keywordState[contextId] && keywordState[contextId].length > 0) {
                    keywordState[contextId].pop();
                    updateKeywordTags(contextId);
                }
            }
        }

        function removeKeyword(contextId, index) {
            if (keywordState[contextId]) {
                keywordState[contextId].splice(index, 1);
                updateKeywordTags(contextId);
            }
        }

        // ----- Keyword autocomplete (Idea 3 follow-on) -------------------
        // Suggestions are bucketed by api/keywords.php?autocomplete=1: current-galaxy
        // first, then prefix-siblings, then global. We cache per-constellation so
        // changing the editor's galaxy filter or opening different node modals
        // doesn't refetch on every keystroke.
        const keywordSuggestionCache = new Map(); // cid -> { current:[], siblings:[], global:[] }
        let keywordSuggestionConstellation = { create: null, modal: null };

        function keywordSuggestionContainer(contextId) {
            return document.getElementById('keyword-suggestions-' + contextId);
        }

        function keywordSuggestionConstellationFor(contextId) {
            if (contextId === 'modal') {
                const v = parseInt(document.getElementById('edit-constellation')?.value, 10);
                return Number.isFinite(v) ? v : null;
            }
            // Create form: ask the create-row's node-constellation select; fall back to the
            // page-level "current galaxy" filter if the create row hasn't picked one yet.
            const v1 = parseInt(document.getElementById('node-constellation')?.value, 10);
            if (Number.isFinite(v1) && v1 > 0) return v1;
            const v2 = document.getElementById('current-constellation')?.value;
            if (v2 && v2 !== 'all') {
                const v2n = parseInt(v2, 10);
                if (Number.isFinite(v2n)) return v2n;
            }
            return null;
        }

        async function ensureKeywordSuggestions(contextId) {
            const cid = keywordSuggestionConstellationFor(contextId);
            keywordSuggestionConstellation[contextId] = cid;
            if (cid === null) return null;
            if (keywordSuggestionCache.has(cid)) return keywordSuggestionCache.get(cid);
            try {
                const r = await fetch(`../api/keywords.php?constellation_id=${cid}&autocomplete=1`, {
                    headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                });
                if (!r.ok) throw new Error('fetch failed');
                const data = await r.json();
                keywordSuggestionCache.set(cid, data);
                return data;
            } catch (e) {
                return null;
            }
        }

        function renderKeywordSuggestions(contextId, data, filter) {
            const box = keywordSuggestionContainer(contextId);
            if (!box) return;
            const f = (filter || '').trim().toLowerCase();
            const taken = new Set((keywordState[contextId] || []).map(k => k.toLowerCase()));

            // Merge all source buckets (current galaxy, prefix-siblings, global) into one
            // deduped, alphabetically-sorted list. Origin doesn't matter to the editor —
            // the only thing that matters is whether the keyword exists somewhere relevant.
            const merged = new Map(); // lowercase keyword -> { keyword, count }
            const all = [...(data.current || []), ...(data.siblings || []), ...(data.global || [])];
            for (const item of all) {
                if (!item || typeof item.keyword !== 'string' || item.keyword === '') continue;
                const lc = item.keyword.toLowerCase();
                if (taken.has(lc)) continue;
                if (f && !lc.includes(f)) continue;
                const existing = merged.get(lc);
                if (existing) {
                    existing.count = (existing.count || 0) + (item.count || 0);
                } else {
                    merged.set(lc, { keyword: item.keyword, count: item.count || 0 });
                }
            }

            if (merged.size === 0) {
                box.classList.add('hidden');
                box.innerHTML = '';
                return;
            }

            const sorted = Array.from(merged.values()).sort((a, b) =>
                a.keyword.localeCompare(b.keyword, undefined, { sensitivity: 'base' })
            );

            const rows = sorted.slice(0, 50).map(m => {
                const colorClass = getPastelColor(m.keyword);
                const count = (typeof m.count === 'number' && m.count > 0) ? `<span class="text-xs text-gray-400 ml-2">${m.count}</span>` : '';
                return `<div class="px-3 py-1.5 hover:bg-gray-50 cursor-pointer flex items-center justify-between gap-2" data-kw-suggest="${escapeHtml(m.keyword)}" data-kw-context="${escapeHtml(contextId)}"><span class="badge ${colorClass} border border-current/20 px-2 py-2 text-xs whitespace-nowrap">${escapeHtml(m.keyword)}</span>${count}</div>`;
            });

            box.innerHTML = rows.join('');
            box.classList.remove('hidden');
        }

        async function updateKeywordSuggestions(contextId) {
            const input = contextId === 'modal'
                ? document.getElementById('edit-keywords-input-modal')
                : document.getElementById('node-keywords-input');
            const data = await ensureKeywordSuggestions(contextId);
            if (!data) {
                const box = keywordSuggestionContainer(contextId);
                if (box) { box.classList.add('hidden'); box.innerHTML = ''; }
                return;
            }
            renderKeywordSuggestions(contextId, data, input ? input.value : '');
        }

        // Click-to-pick on suggestions (delegated, once globally).
        document.addEventListener('mousedown', (ev) => {
            const pick = ev.target.closest('[data-kw-suggest]');
            if (!pick) return;
            const ctx = pick.getAttribute('data-kw-context');
            const kw = pick.getAttribute('data-kw-suggest');
            ev.preventDefault();
            addKeywords(kw, ctx);
            const input = ctx === 'modal'
                ? document.getElementById('edit-keywords-input-modal')
                : document.getElementById('node-keywords-input');
            if (input) { input.value = ''; input.focus(); }
            updateKeywordSuggestions(ctx);
        });

        // Click outside the keyword container hides suggestions.
        document.addEventListener('click', (ev) => {
            ['create', 'modal'].forEach(ctx => {
                const c = document.getElementById('keywords-container-' + ctx);
                const box = keywordSuggestionContainer(ctx);
                if (!c || !box) return;
                if (!c.contains(ev.target)) {
                    box.classList.add('hidden');
                }
            });
        });

        // Expose for inline handlers.
        window.updateKeywordSuggestions = updateKeywordSuggestions;

        // Initialize on page load
        const API_URL = '../api/validate.php';

        async function validateField(type, params) {
            if (typeof API_KEY === 'undefined' || !API_KEY) return { valid: true };
            const query = new URLSearchParams({ type, ...params, api_key: API_KEY }).toString();
            try {
                const response = await fetch(`${API_URL}?${query}`);
                return await response.json();
            } catch (e) {
                console.error('Validation failed', e);
                return { valid: true };
            }
        }

        function setupLiveValidation() {
            const debounce = (fn, delay) => {
                let timeoutId;
                return (...args) => {
                    clearTimeout(timeoutId);
                    timeoutId = setTimeout(() => fn(...args), delay);
                };
            };

            const validateNode = async (nameEl, cidEl, errorEl, idEl = null) => {
                const name = nameEl.value.trim();
                const cid = cidEl.value;
                const form = nameEl.closest('form');
                const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
                if (!name || !cid) {
                    errorEl.classList.add('hidden');
                    return;
                }
                const result = await validateField('node', { name, constellation_id: cid, exclude_id: idEl ? idEl.value : null });
                if (result.name) {
                    errorEl.classList.remove('hidden');
                    nameEl.classList.add('border-red-500');
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    errorEl.classList.add('hidden');
                    nameEl.classList.remove('border-red-500');
                    if (submitBtn) {
                        const otherErrors = form.querySelectorAll('.text-red-600:not(.hidden)');
                        if (otherErrors.length === 0) submitBtn.disabled = false;
                    }
                }
            };

            // Create Node
            const createName = document.getElementById('node-name');
            const createCid = document.getElementById('node-constellation');
            const createErr = document.getElementById('node-name-error');
            if (createName && createCid) {
                const validateCreate = debounce(() => validateNode(createName, createCid, createErr), 500);
                createName.addEventListener('input', validateCreate);
                createCid.addEventListener('change', validateCreate);
            }

            // Edit Node
            const editName = document.getElementById('edit-name');
            const editCid = document.getElementById('edit-constellation');
            const editErr = document.getElementById('edit-name-error');
            const editId = document.getElementById('edit-id');
            if (editName && editCid) {
                const validateEdit = debounce(() => validateNode(editName, editCid, editErr, editId), 500);
                editName.addEventListener('input', validateEdit);
                editCid.addEventListener('change', validateEdit);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadNodes().catch(error => {
                const listDiv = document.getElementById('nodes-list');
                if (listDiv) {
                    listDiv.innerHTML = `<p class="text-red-600">${escapeHtml(tFmt(TELARIS_EDIT.errorFatalLoading, error.message))}</p>`;
                }
            });
            setupLiveValidation();
        });
    </script>
    <dialog id="edit_modal" class="modal">
        <div class="modal-box max-w-4xl bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl">
                    <span id="wm-heading-edit"><?= t_attr('editor_modal_heading_edit_wormhole', 'Edit Wormhole') ?></span>
                    <span id="wm-heading-create" class="hidden"><?= t_attr('editor_modal_heading_add_wormhole', 'Add New Wormhole') ?></span>
                </h3>
                <span id="edit-node-constellation-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <form id="edit-node-form" class="space-y-4 mt-4" onsubmit="onWormholeFormSubmit(event)">
                <input type="hidden" id="edit-id" name="id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_name_required', 'Name *') ?></label>
                        <input type="text" id="edit-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span id="edit-name-error" class="text-xs text-red-600 mt-1 hidden"><?= t_attr('editor_error_name_exists', 'This wormhole name already exists in this galaxy.') ?></span>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_galaxy', 'Galaxy') ?></label>
                        <select id="edit-constellation" name="constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php foreach ($constellations as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_wormhole_type', 'Wormhole type') ?></label>
                        <select id="edit-node-type" name="node_type" onchange="toggleTargetConstellation(this.value, 'modal')" class="select select-bordered select-sm w-full bg-white">
                            <option value="object"><?= t_attr('editor_label_node_type_object', 'Object') ?></option>
                            <option value="portal"><?= t_attr('editor_label_node_type_portal', 'Portal') ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_keywords', 'Keywords') ?></label>
                        <div id="keywords-container-modal" class="relative flex flex-wrap gap-2 p-2 border border-gray-300 rounded bg-white focus-within:border-blue-500 transition-colors">
                            <input type="text" id="edit-keywords-input-modal" placeholder="<?= t_attr('editor_placeholder_add_keyword', 'Add keyword...') ?>"
                                   onkeydown="handleKeywordInput(event, 'modal')"
                                   oninput="if(this.value.includes(',')) { addKeywords(this.value, 'modal'); this.value = ''; } else { updateKeywordSuggestions('modal'); }"
                                   onfocus="updateKeywordSuggestions('modal')"
                                   autocomplete="off"
                                   class="flex-1 min-w-[120px] outline-none text-sm py-1 px-1">
                            <div id="keyword-suggestions-modal" class="hidden absolute left-0 right-0 top-full mt-1 z-[100] max-h-56 overflow-y-auto overscroll-contain rounded border border-gray-300 bg-white shadow-lg text-sm"></div>
                        </div>
                        <input type="hidden" id="edit-keywords-hidden" name="keywords">
                        <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('editor_help_keywords_add', 'Type and press Enter or comma to add keywords. Suggestions surface keywords already used in this galaxy and in sibling galaxies sharing your `[XX]` prefix.') ?></span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="edit-accentuated" name="is_accentuated" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800"><?= t_attr('editor_label_accentuate_wormhole', 'Accentuate Wormhole') ?></span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1"><?= t_attr('editor_help_accentuate', 'Make this wormhole larger and more prominent in the network.') ?></span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="edit-show-keywords" name="show_keywords" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800"><?= t_attr('editor_label_show_keywords', 'Show Keywords') ?></span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1"><?= t_attr('editor_help_show_keywords', "Display this wormhole's keywords in its info window.") ?></span>
                    </div>
                </div>
                <div id="edit-target-constellation-wrap-modal" class="hidden">
                    <div class="flex flex-wrap items-end gap-2 mb-2">
                        <div class="min-w-[200px] flex-1">
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_target_galaxy', 'Target Galaxy') ?></label>
                            <select id="edit-target-constellation-modal" name="target_constellation_id" class="select select-bordered select-sm w-full bg-white"></select>
                        </div>
                        <button type="button" onclick="createNewConstellation('modal')" class="py-2.5 px-4 rounded text-sm border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 cursor-pointer whitespace-nowrap"><?= t_attr('editor_btn_create_new_galaxy', 'Create New Galaxy') ?></button>
                    </div>
                </div>
                <div>
                    <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_description', 'Description') ?></label>
                    <textarea id="edit-description" name="description" rows="3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_url', 'URL') ?></label>
                        <input type="url" id="edit-url" name="url" placeholder="<?= t_attr('editor_placeholder_url', 'https://example.com') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <!-- Icon lives next to the URL (it is the node's 3D-scene icon, not part of the media block). -->
                    <div id="edit-icon-container">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_icon_url_file', 'Icon URL / File') ?></label>
                        <div id="edit-icon-file-wrap">
                            <input type="text" id="edit-icon-url" name="icon_url" placeholder="<?= t_attr('editor_placeholder_icon_url', 'https://example.com/icon.png') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="edit-icon-file" name="icon_file" accept="image/*" class="text-xs">
                        </div>
                        <div id="edit-icon-existing" class="hidden flex items-center gap-2 mb-2">
                            <input type="text" id="edit-icon-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                            <button type="button" onclick="deleteModalFile('icon')" class="btn btn-error btn-sm btn-outline"><?= t_attr('editor_btn_delete_file', 'Delete') ?></button>
                        </div>
                        <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('editor_help_icon', 'Custom icon displayed in the 3D scene (overrides theme icon).') ?></span>
                    </div>
                </div>
                <div class="divider text-gray-400 text-xs"><?= t_attr('editor_divider_media', 'Media') ?></div>
                <!-- Media is either the Classic block (image/video/pdf/audio/embed) or a Hotglue page.
                     Whichever tab is active on save is persisted to nodes.media_mode (phase 5). -->
                <div class="tabs tabs-bordered mb-2">
                    <button type="button" id="edit-media-classic-tab" onclick="switchMediaMode('classic', 'edit')" class="tab tab-sm tab-active"><?= t_attr('editor_tab_classic', 'Classic') ?></button>
                    <button type="button" id="edit-media-hotglue-tab" onclick="switchMediaMode('hotglue', 'edit')" class="tab tab-sm"><?= t_attr('editor_tab_hotglue', 'Hotglue') ?></button>
                </div>
                <input type="hidden" id="edit-media-mode" name="media_mode" value="classic">
                <input type="hidden" id="edit-hotglue-page" value="">
                <div id="edit-media-classic-content">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Left column: Primary visual (Image / Video / PDF, mutually exclusive). -->
                    <div class="flex flex-col">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_primary_visual', 'Primary visual') ?></label>
                        <div class="tabs tabs-bordered mb-2">
                            <button type="button" id="edit-image-tab" onclick="switchVisualTab('image', 'edit')" class="tab tab-sm tab-active"><?= t_attr('editor_tab_image', 'Image') ?></button>
                            <button type="button" id="edit-video-tab" onclick="switchVisualTab('video', 'edit')" class="tab tab-sm"><?= t_attr('editor_tab_video', 'Video (MP4)') ?></button>
                            <button type="button" id="edit-pdf-tab" onclick="switchVisualTab('pdf', 'edit')" class="tab tab-sm"><?= t_attr('editor_tab_pdf', 'PDF') ?></button>
                        </div>
                        <input type="hidden" id="edit-visual-type" value="image">
                        <span class="text-xs text-gray-500 mt-0 mb-2 block"><?= t_attr('editor_help_visual_mutex', 'Pick one. Switching tabs and saving clears the others.') ?></span>

                        <!-- Image content -->
                        <div id="edit-image-content">
                            <div class="flex items-center justify-between mb-1.5 gap-2">
                                <label for="edit-image-url" class="text-gray-800 font-medium text-xs"><?= t_attr('editor_label_image_url_file', 'Image URL / File') ?></label>
                                <label class="label cursor-pointer justify-end gap-2 py-0">
                                    <span class="label-text text-xs text-gray-700"><?= t_attr('editor_label_use_as_icon', 'Use as wormhole icon') ?></span>
                                    <input type="checkbox" id="edit-use-image-as-node" name="use_image_as_node" class="toggle toggle-neutral toggle-sm">
                                </label>
                            </div>
                            <div id="edit-image-file-wrap">
                                <input type="text" id="edit-image-url" name="image_url" placeholder="<?= t_attr('editor_placeholder_image_url', 'https://example.com/image.jpg') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-image-file" name="image_file" accept="image/*,video/*" class="text-xs">
                            </div>
                            <div id="edit-image-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-image-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('image')" class="btn btn-error btn-sm btn-outline"><?= t_attr('editor_btn_delete_file', 'Delete') ?></button>
                            </div>
                        </div>

                        <!-- Video content -->
                        <div id="edit-video-content" class="hidden">
                            <div id="edit-video-file-wrap">
                                <input type="text" id="edit-video-url" name="video_url" placeholder="<?= t_attr('editor_placeholder_video_url', 'https://example.com/video.mp4') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-video-file" name="video_file" accept="video/mp4" class="text-xs">
                            </div>
                            <div id="edit-video-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-video-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('video')" class="btn btn-error btn-sm btn-outline"><?= t_attr('editor_btn_delete_file', 'Delete') ?></button>
                            </div>
                            <label class="flex items-center gap-2 mt-2 text-xs text-gray-700">
                                <input type="checkbox" id="edit-video-autoplay" name="video_autoplay">
                                <?= t_attr('editor_label_autoplay_video', 'Autoplay video') ?>
                            </label>
                        </div>

                        <!-- PDF content -->
                        <div id="edit-pdf-content" class="hidden">
                            <div id="edit-pdf-file-wrap">
                                <input type="text" id="edit-pdf-url" name="pdf_url" placeholder="<?= t_attr('editor_placeholder_pdf_url', 'https://example.com/document.pdf') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-pdf-file" name="pdf_file" accept="application/pdf,.pdf" class="text-xs">
                            </div>
                            <div id="edit-pdf-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-pdf-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('pdf')" class="btn btn-error btn-sm btn-outline"><?= t_attr('editor_btn_delete_file', 'Delete') ?></button>
                            </div>
                        </div>

                        <!-- Credit (applies to whichever visual is active). Stored on nodes.image_attribution. -->
                        <input type="text" id="edit-image-attribution" name="image_attribution" placeholder="<?= t_attr('editor_placeholder_credit', 'Credit / attribution...') ?>" class="w-full p-2 border border-gray-300 rounded text-xs focus:outline-none focus:border-blue-500 mt-3" maxlength="255">
                        <span class="text-xs text-gray-500 mt-0.5 block"><?= t_attr('editor_help_credit', 'Optional credit shown on the visual in the info box (image, video, or PDF).') ?></span>
                    </div>

                    <!-- Right column: Audio (top), Embed code (bottom, independent of the visual mutex). -->
                    <div class="flex flex-col gap-4">
                        <div>
                            <label for="edit-audio-url" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_audio_url_file', 'Audio URL / File') ?></label>
                            <div id="edit-audio-file-wrap">
                                <input type="text" id="edit-audio-url" name="audio_url" placeholder="<?= t_attr('editor_placeholder_audio_url', 'https://example.com/audio.mp3') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-audio-file" name="audio_file" accept="audio/*" class="text-xs">
                            </div>
                            <div id="edit-audio-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-audio-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('audio')" class="btn btn-error btn-sm btn-outline"><?= t_attr('editor_btn_delete_file', 'Delete') ?></button>
                            </div>
                            <div class="flex items-center gap-4 mt-2">
                                <label class="flex items-center gap-2 text-xs text-gray-700">
                                    <input type="checkbox" id="edit-audio-autoplay" name="audio_autoplay">
                                    <?= t_attr('editor_label_autoplay', 'Autoplay') ?>
                                </label>
                                <label class="flex items-center gap-2 text-xs text-gray-700">
                                    <input type="checkbox" id="edit-audio-loop" name="audio_loop">
                                    <?= t_attr('editor_label_loop', 'Loop') ?>
                                </label>
                            </div>
                            <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('editor_help_audio', 'Independent of the primary visual: audio can pair with image, video, or PDF.') ?></span>
                        </div>

                        <!-- Embed code is hidden from the editor for now (unused in practice).
                             The textarea stays in the DOM so existing embed_code values round-trip on save. -->
                        <div class="hidden">
                            <textarea id="edit-embed-code" name="embed_code"></textarea>
                        </div>
                    </div>
                </div>
                </div><!-- /edit-media-classic-content -->
                <div id="edit-media-hotglue-content" class="hidden">
                    <p class="text-xs text-gray-500 mb-4 text-center"><?= t_attr('editor_help_hotglue', 'Compose this wormhole\'s media as a freeform hotglue page. Whichever tab is selected when you save is what visitors see.') ?></p>
                    <!-- Edit mode: open the live hotglue editor for this node's page. -->
                    <div id="edit-hotglue-edit-wrap" class="flex justify-center py-2">
                        <button type="button" onclick="openHotglueEditor()" class="btn btn-primary btn-wide gap-2 shadow-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                            <?= t_attr('editor_btn_edit_hotglue', 'Edit hotglue content') ?>
                        </button>
                    </div>
                    <!-- Create mode: the page is node-<id>, created from the saved wormhole, so it can't be composed until the wormhole exists. -->
                    <p id="edit-hotglue-create-note" class="hidden text-xs text-gray-500 text-center py-2 px-4"><?= t_attr('editor_hotglue_create_note', 'Save the wormhole first, then reopen it to compose the hotglue page. Add it with this tab selected to start in Hotglue mode.') ?></p>
                </div>
                <div id="edit-progress-wrap" class="hidden space-y-2">
                    <div class="flex justify-between text-xs font-medium">
                        <span><?= t_attr('editor_text_uploading', 'Uploading...') ?></span>
                        <span id="edit-progress-text">0%</span>
                    </div>
                    <progress id="edit-progress-bar" class="progress progress-neutral w-full" value="0" max="100"></progress>
                </div>
                <div class="modal-action items-center justify-between">
                    <!-- Edit mode: live autosave status. Create mode: hidden (an explicit Add button is used). -->
                    <div id="edit-autosave-status" class="flex items-center gap-2" aria-live="polite">
                        <span class="loading loading-spinner loading-xs text-gray-400 hidden" data-autosave-spinner></span>
                        <span data-autosave-text class="text-xs font-medium text-gray-400"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <!-- Create mode only: a brand-new wormhole is created with one explicit submit (no id to autosave against yet). -->
                        <button type="submit" id="edit-submit-btn" class="btn btn-neutral hidden">
                            <span class="loading loading-spinner hidden" id="edit-submit-loader"></span>
                            <?= t_attr('editor_btn_add_wormhole', 'Add Wormhole') ?>
                        </button>
                        <button type="button" class="btn btn-neutral" onclick="document.getElementById('edit_modal').close()"><?= t_attr('editor_btn_close', 'Close') ?></button>
                    </div>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <!-- Hotglue content editor: near-full-screen overlay that embeds the per-node
         hotglue page same-origin (so the editor's Telaris session + CSRF ride).
         openHotglueEditor() sets the iframe src from the node being edited. -->
    <dialog id="hotglue_modal" class="modal">
        <div class="modal-box max-w-none w-[96vw] h-[94vh] p-0 bg-white flex flex-col overflow-hidden">
            <div class="px-4 py-2 bg-neutral text-neutral-content flex items-center justify-between shrink-0">
                <h3 class="font-bold text-sm"><?= t_attr('editor_hotglue_modal_heading', 'Edit hotglue content') ?></h3>
                <button type="button" class="btn btn-sm" onclick="closeHotglueEditor()"><?= t_attr('editor_btn_hotglue_done', 'Done') ?></button>
            </div>
            <iframe id="hotglue-iframe" src="about:blank" class="grow w-full border-0" title="<?= t_attr('editor_hotglue_modal_heading', 'Edit hotglue content') ?>"></iframe>
        </div>
    </dialog>

    <!-- Delete Confirmation Modal -->
    <dialog id="delete_confirm_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-error text-error-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= t_attr('editor_modal_heading_confirm_delete', 'Confirm Deletion') ?></h3>
            </div>
            <p id="delete-confirm-message" class="text-gray-600 mb-6 mt-4"></p>
            <div class="modal-action">
                <button id="delete-confirm-btn" class="btn btn-error text-white"><?= t_attr('editor_btn_delete', 'Delete') ?></button>
                <button type="button" class="btn" onclick="document.getElementById('delete_confirm_modal').close()"><?= t_attr('editor_btn_cancel', 'Cancel') ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Bulk Move Modal -->
    <dialog id="bulk_move_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= t_attr('editor_modal_heading_move_wormholes', 'Move Wormholes') ?></h3>
            </div>
            <p class="text-gray-600 mb-4 mt-4" id="bulk-move-description"></p>

            <div class="mb-6">
                <label for="bulk-move-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_destination_galaxy', 'Destination Galaxy') ?></label>
                <select id="bulk-move-constellation" class="select select-bordered select-sm w-full bg-white">
                    <?php foreach ($constellations as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-action">
                <button onclick="bulkMove()" class="btn btn-neutral"><?= t_attr('editor_btn_move_wormholes', 'Move Wormholes') ?></button>
                <button type="button" class="btn" onclick="document.getElementById('bulk_move_modal').close()"><?= t_attr('editor_btn_cancel', 'Cancel') ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Duplicate Node Modal -->
    <dialog id="duplicate_node_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl"><?= t_attr('editor_modal_heading_duplicate_wormhole', 'Duplicate Wormhole') ?></h3>
                <span id="duplicate-node-constellation-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <input type="hidden" id="duplicate-source-id" value="">
            <p class="text-gray-600 mb-4 mt-4" id="duplicate-source-prompt"></p>

            <div class="mb-6">
                <label for="duplicate-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_destination_galaxy', 'Destination Galaxy') ?></label>
                <select id="duplicate-constellation" class="select select-bordered select-sm w-full bg-white">
                    <?php foreach ($constellations as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-action">
                <button onclick="confirmDuplicate()" class="btn btn-neutral"><?= t_attr('editor_btn_duplicate', 'Duplicate') ?></button>
                <button type="button" class="btn" onclick="document.getElementById('duplicate_node_modal').close()"><?= t_attr('editor_btn_cancel', 'Cancel') ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Bulk Duplicate Modal -->
    <dialog id="bulk_duplicate_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= t_attr('editor_modal_heading_duplicate_wormholes', 'Duplicate Wormholes') ?></h3>
            </div>
            <p class="text-gray-600 mb-4 mt-4" id="bulk-duplicate-description"></p>

            <div class="mb-6">
                <label for="bulk-duplicate-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_destination_galaxy', 'Destination Galaxy') ?></label>
                <select id="bulk-duplicate-constellation" class="select select-bordered select-sm w-full bg-white">
                    <?php foreach ($constellations as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-action">
                <button onclick="bulkDuplicate()" class="btn btn-neutral"><?= t_attr('editor_btn_duplicate_wormholes', 'Duplicate Wormholes') ?></button>
                <button type="button" class="btn" onclick="document.getElementById('bulk_duplicate_modal').close()"><?= t_attr('editor_btn_cancel', 'Cancel') ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- View Node Preview Modal -->
    <dialog id="view_node_modal" class="modal">
        <div class="modal-box max-w-2xl p-0 bg-[#0a0a0c]/90 border border-white/20 text-white" style="box-shadow: 0 0 50px -10px rgba(0, 255, 204, 0.3);">
            <!-- Close Button -->
            <form method="dialog">
                <button class="absolute top-4 right-4 text-white/50 hover:text-white transition-colors z-10 bg-transparent border-none p-0 cursor-pointer">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </form>

            <!-- Content -->
            <div class="p-6 md:p-8">
                <h3 id="preview-title" class="text-2xl font-bold mb-4 tracking-tight uppercase border-b-2 border-white/20 pb-2"></h3>
                <div class="space-y-6">
                    <!-- Hotglue page (media_mode=hotglue), sandboxed without allow-same-origin -->
                    <div id="preview-hotglue-wrap" class="hidden">
                        <iframe id="preview-hotglue" src="about:blank" sandbox="allow-scripts allow-popups" referrerpolicy="no-referrer" class="w-full rounded-md border border-white/20 bg-white" style="height: 65vh;"></iframe>
                    </div>

                    <!-- Image -->
                    <div id="preview-image-wrap" class="hidden relative">
                        <img id="preview-image" src="" alt="" class="w-full h-auto rounded-md border border-white/20">
                        <span id="preview-image-attribution" class="hidden absolute bottom-1 right-1 text-[10px] text-white/80 bg-black/50 px-1.5 py-0.5 rounded pointer-events-none"></span>
                    </div>

                    <!-- Embed -->
                    <div id="preview-embed-wrap" class="hidden aspect-video">
                        <div id="preview-embed" class="w-full h-full"></div>
                    </div>

                    <!-- Video -->
                    <div id="preview-video-wrap" class="hidden">
                        <video id="preview-video" controls preload="auto" class="w-full h-auto rounded-md border border-white/20" style="width: 100% !important;"></video>
                    </div>

                    <!-- Audio -->
                    <div id="preview-audio-wrap" class="hidden">
                        <audio id="preview-audio" preload="auto"></audio>
                        <div class="flex items-center gap-4 bg-white/5 border border-white/10 rounded-lg p-3">
                            <button id="preview-audio-play-pause" class="text-[#00ffcc] hover:opacity-80 transition-opacity">
                                <svg id="preview-play-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                <svg id="preview-pause-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" class="hidden"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                            </button>
                            <button id="preview-audio-stop" class="text-[#00ffcc] hover:opacity-80 transition-opacity">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 6h12v12H6z"/></svg>
                            </button>
                            <div class="flex-1 h-1 bg-white/10 rounded-full overflow-hidden cursor-pointer relative" id="preview-audio-progress-container">
                                <div id="preview-audio-progress" class="absolute top-0 left-0 h-full w-0 bg-[#00ffcc] transition-all duration-100"></div>
                            </div>
                            <span id="preview-audio-time" class="text-[10px] font-mono text-[#00ffcc] opacity-50 tabular-nums">0:00</span>
                        </div>
                    </div>

                    <!-- Description -->
                    <div id="preview-description" class="hidden text-gray-300 leading-relaxed text-sm md:text-base whitespace-pre-wrap max-h-[40vh] overflow-y-auto pr-1" style="scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.2) transparent;"></div>

                    <!-- Keywords -->
                    <div id="preview-keywords-wrap" class="hidden">
                        <div id="preview-keywords" class="flex flex-wrap gap-2"></div>
                    </div>

                    <!-- URL / Action Button -->
                    <div id="preview-url-wrap" class="hidden pt-4">
                        <a id="preview-url-button" href="#" target="_blank" class="block w-full py-3 bg-transparent border border-white/20 text-[#00ffcc] text-xs font-bold uppercase tracking-[0.22em] text-center transition-all hover:bg-white/10 rounded no-underline">
                            <?= t_attr('editor_btn_open_link', 'Open Link') ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <?php require __DIR__ . '/../inc/partials/galaxy-edit-modal.php'; ?>

    <!-- Bulk by keyword modal -->
    <dialog id="bulk_by_keyword_modal" class="modal">
        <div class="modal-box bg-white !pt-0 max-w-lg">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= t_attr('editor_modal_heading_bulk_keyword', 'Bulk action by keyword') ?></h3>
            </div>
            <p class="text-sm text-gray-600 mt-4">
                <?= t_attr('editor_text_bulk_keyword_help', 'Pick a keyword in the current galaxy. Then choose to delete every wormhole carrying it, or move them all to another galaxy.') ?>
            </p>

            <div class="mt-4">
                <label for="bulk-kw-keyword" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_keyword', 'Keyword') ?></label>
                <select id="bulk-kw-keyword" class="select select-bordered select-sm w-full bg-white">
                    <option value=""><?= t_attr('editor_option_loading', 'Loading…') ?></option>
                </select>
            </div>

            <div class="mt-4">
                <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_action', 'Action') ?></label>
                <div class="space-y-1">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="bulk-kw-op" value="delete" class="radio radio-neutral radio-sm" checked>
                        <span><?= t_attr('editor_option_delete_matching', 'Delete the matching wormholes') ?></span>
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="bulk-kw-op" value="move" class="radio radio-neutral radio-sm">
                        <span><?= t_attr('editor_option_move_matching', 'Move them to another galaxy') ?></span>
                    </label>
                </div>
            </div>

            <div id="bulk-kw-target-row" class="mt-4 hidden">
                <label for="bulk-kw-target" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('editor_label_target_galaxy', 'Target galaxy') ?></label>
                <select id="bulk-kw-target" class="select select-bordered select-sm w-full bg-white"></select>
            </div>

            <p id="bulk-kw-preview" class="text-xs text-gray-600 mt-4"><?= t_attr('editor_text_pick_keyword', 'Pick a keyword to see the count.') ?></p>

            <div class="modal-action">
                <button type="button" id="bulk-kw-apply" class="btn btn-neutral" disabled><?= t_attr('editor_btn_apply', 'Apply') ?></button>
                <button type="button" class="btn" onclick="document.getElementById('bulk_by_keyword_modal').close()"><?= t_attr('editor_btn_cancel', 'Cancel') ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <script>
        let bulkKwAvailable = []; // [{id, keyword, usage_count}]

        async function openBulkByKeywordModal() {
            const sel = document.getElementById('current-constellation');
            const cid = parseInt(sel?.value, 10);
            if (!cid || isNaN(cid)) {
                showMessage(TELARIS_EDIT.errorPickSpecificGalaxy, 'error');
                return;
            }

            const kwSelect = document.getElementById('bulk-kw-keyword');
            const targetSelect = document.getElementById('bulk-kw-target');
            const preview = document.getElementById('bulk-kw-preview');
            const applyBtn = document.getElementById('bulk-kw-apply');
            kwSelect.innerHTML = `<option value="">${escapeHtmlEdit(TELARIS_EDIT.optionLoading)}</option>`;
            targetSelect.innerHTML = '';
            preview.textContent = TELARIS_EDIT.textPickKeyword;
            preview.style.color = '';
            applyBtn.disabled = true;
            document.querySelector('input[name="bulk-kw-op"][value="delete"]').checked = true;
            document.getElementById('bulk-kw-target-row').classList.add('hidden');

            // Load keyword list for the current galaxy.
            try {
                const r = await fetch(`../api/keywords.php?constellation_id=${cid}`, { headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN } });
                if (!r.ok) throw new Error('Failed to load keywords');
                bulkKwAvailable = await r.json();
                if (!Array.isArray(bulkKwAvailable) || bulkKwAvailable.length === 0) {
                    kwSelect.innerHTML = `<option value="">${escapeHtmlEdit(TELARIS_EDIT.optionNoKeywords)}</option>`;
                } else {
                    kwSelect.innerHTML = `<option value="">${escapeHtmlEdit(TELARIS_EDIT.optionPickOne)}</option>` + bulkKwAvailable
                        .sort((a, b) => (b.usage_count || 0) - (a.usage_count || 0) || String(a.keyword).localeCompare(String(b.keyword)))
                        .map(k => `<option value="${k.id}">${escapeHtmlEdit(k.keyword)} (${k.usage_count || 0})</option>`)
                        .join('');
                }
            } catch (e) {
                kwSelect.innerHTML = `<option value="">${escapeHtmlEdit(TELARIS_EDIT.optionErrorKeywords)}</option>`;
            }

            // Target galaxy list (excludes current galaxy itself).
            if (Array.isArray(window.TELARIS_GALAXIES)) {
                targetSelect.innerHTML = `<option value="">${escapeHtmlEdit(TELARIS_EDIT.optionPickGalaxy)}</option>` + window.TELARIS_GALAXIES
                    .filter(g => g.id !== cid)
                    .map(g => `<option value="${g.id}">${escapeHtmlEdit(g.name)}</option>`)
                    .join('');
            }

            document.getElementById('bulk_by_keyword_modal').showModal();
        }

        function escapeHtmlEdit(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        document.addEventListener('DOMContentLoaded', () => {
            const kwSelect = document.getElementById('bulk-kw-keyword');
            const opRadios = document.querySelectorAll('input[name="bulk-kw-op"]');
            const targetSelect = document.getElementById('bulk-kw-target');
            const targetRow = document.getElementById('bulk-kw-target-row');
            const preview = document.getElementById('bulk-kw-preview');
            const applyBtn = document.getElementById('bulk-kw-apply');

            const refreshPreview = () => {
                const kid = parseInt(kwSelect.value, 10);
                if (!kid || isNaN(kid)) {
                    preview.textContent = TELARIS_EDIT.textPickKeyword;
                    applyBtn.disabled = true;
                    return;
                }
                const entry = bulkKwAvailable.find(k => k.id === kid);
                const count = entry ? (entry.usage_count || 0) : 0;
                const op = (document.querySelector('input[name="bulk-kw-op"]:checked') || {}).value || 'delete';
                if (op === 'move') {
                    const tid = parseInt(targetSelect.value, 10);
                    if (tid && !isNaN(tid)) {
                        preview.textContent = count === 1 ? TELARIS_EDIT.previewMoveOne : tFmt(TELARIS_EDIT.previewMoveMany, count);
                    } else {
                        preview.textContent = count === 1 ? TELARIS_EDIT.previewMovePickTargetOne : tFmt(TELARIS_EDIT.previewMovePickTargetMany, count);
                    }
                    applyBtn.disabled = !(tid && !isNaN(tid)) || count === 0;
                } else {
                    preview.textContent = count === 1 ? TELARIS_EDIT.previewDeleteOne : tFmt(TELARIS_EDIT.previewDeleteMany, count);
                    applyBtn.disabled = count === 0;
                }
            };

            kwSelect && kwSelect.addEventListener('change', refreshPreview);
            opRadios.forEach(r => r.addEventListener('change', () => {
                targetRow.classList.toggle('hidden', (document.querySelector('input[name="bulk-kw-op"]:checked') || {}).value !== 'move');
                refreshPreview();
            }));
            targetSelect && targetSelect.addEventListener('change', refreshPreview);

            applyBtn && applyBtn.addEventListener('click', async () => {
                const sel = document.getElementById('current-constellation');
                const cid = parseInt(sel?.value, 10);
                const kid = parseInt(kwSelect.value, 10);
                const op = (document.querySelector('input[name="bulk-kw-op"]:checked') || {}).value;
                const tid = op === 'move' ? parseInt(targetSelect.value, 10) : null;
                if (!cid || !kid || !op) return;
                const entry = bulkKwAvailable.find(k => k.id === kid);
                const count = entry ? (entry.usage_count || 0) : 0;
                const kw = entry?.keyword || '';
                let msg;
                if (op === 'delete') {
                    msg = count === 1
                        ? tFmt(TELARIS_EDIT.confirmBulkDeleteKeywordOne, kw)
                        : tFmt(TELARIS_EDIT.confirmBulkDeleteKeywordMany, count, kw);
                } else {
                    msg = count === 1
                        ? tFmt(TELARIS_EDIT.confirmBulkMoveKeywordOne, kw)
                        : tFmt(TELARIS_EDIT.confirmBulkMoveKeywordMany, count, kw);
                }
                if (!window.confirm(msg)) return;

                applyBtn.disabled = true;
                try {
                    const body = { action: 'bulk_by_keyword', constellation_id: cid, keyword_id: kid, op };
                    if (op === 'move') body.target_constellation_id = tid;
                    const r = await fetch(API_BASE, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN },
                        body: JSON.stringify(body),
                    });
                    const json = await r.json();
                    if (!r.ok) throw new Error(json?.error || 'Bulk action failed');
                    const n = json.affected;
                    let okMsg;
                    if (op === 'delete') {
                        okMsg = n === 1 ? TELARIS_EDIT.toastBulkDeletedOne : tFmt(TELARIS_EDIT.toastBulkDeletedMany, n);
                    } else {
                        okMsg = n === 1 ? TELARIS_EDIT.toastBulkMovedOne : tFmt(TELARIS_EDIT.toastBulkMovedMany, n);
                    }
                    showMessage(okMsg);
                    document.getElementById('bulk_by_keyword_modal').close();
                    loadNodes();
                } catch (e) {
                    showMessage(tFmt(TELARIS_EDIT.toastBulkActionFailed, e.message), 'error');
                } finally {
                    applyBtn.disabled = false;
                }
            });
        });
    </script>

    <!-- Keyboard shortcuts modal -->
    <dialog id="shortcuts_modal" class="modal">
        <div class="modal-box bg-white !pt-0 max-w-md">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= t_attr('editor_modal_heading_shortcuts', 'Keyboard shortcuts') ?></h3>
            </div>
            <table class="w-full mt-4 text-sm">
                <tbody class="divide-y divide-gray-200">
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">N</kbd></td><td class="text-gray-700"><?= t_attr('editor_shortcut_new_wormhole', 'New wormhole') ?></td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">/</kbd></td><td class="text-gray-700"><?= t_attr('editor_shortcut_focus_search', 'Focus the search box') ?></td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">T</kbd></td><td class="text-gray-700"><?= t_attr('editor_shortcut_toggle_touched', 'Toggle "Touched today" filter') ?></td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">G</kbd></td><td class="text-gray-700"><?= t_attr('editor_shortcut_galaxy_settings', 'Open galaxy settings (current galaxy)') ?></td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">Esc</kbd></td><td class="text-gray-700"><?= t_attr('editor_shortcut_close_modal', 'Close any open modal') ?></td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">?</kbd></td><td class="text-gray-700"><?= t_attr('editor_shortcut_open_help', 'Open this help') ?></td></tr>
                </tbody>
            </table>
            <p class="text-xs text-gray-500 mt-4"><?= t_attr('editor_note_shortcuts_typing', 'Shortcuts are ignored while typing in a text field.') ?></p>
            <div class="modal-action">
                <button type="button" class="btn btn-neutral" onclick="document.getElementById('shortcuts_modal').close()"><?= t_attr('editor_btn_close', 'Close') ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <script>
        // Editor keyboard shortcuts. Ignored while typing in any input/textarea/select
        // and while a modal is open (Esc still closes the topmost modal natively).
        document.addEventListener('keydown', function (e) {
            if (e.metaKey || e.ctrlKey || e.altKey) return;
            const t = e.target;
            const tag = t && t.tagName ? t.tagName.toUpperCase() : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
            // Don't fire shortcuts when any modal dialog is open.
            if (document.querySelector('dialog[open]')) return;

            const key = e.key.toLowerCase();
            if (key === '?') {
                e.preventDefault();
                document.getElementById('shortcuts_modal').showModal();
            } else if (key === '/') {
                e.preventDefault();
                const s = document.getElementById('search-nodes');
                if (s) s.focus();
            } else if (key === 'n') {
                e.preventDefault();
                if (typeof openCreateNodeModal === 'function') openCreateNodeModal();
            } else if (key === 't') {
                e.preventDefault();
                if (typeof toggleTouchedTodayFilter === 'function') toggleTouchedTodayFilter();
            } else if (key === 'g') {
                e.preventDefault();
                if (typeof openCurrentGalaxySettings === 'function') openCurrentGalaxySettings();
            }
        });
    </script>

    <script>
        window.GALAXY_EDIT_API_URL = '../api/constellations.php';
        window.GALAXY_EDIT_API_KEY = <?php echo json_encode($apiKey); ?>;
        window.TELARIS_GXM = <?= json_encode([
            'statusLoadingKeywords' => t('editor_gxm_status_loading_keywords', 'Loading…'),
            'noKeywordsYet' => t('editor_gxm_no_keywords_yet', 'No keywords yet for this galaxy.'),
            'loadFailedKeywords' => t('editor_gxm_load_failed_keywords', 'Failed to load.'),
            'labelUseImagesAsIcons' => t('editor_gxm_label_use_images_as_icons', 'use images as icons'),
            'labelRevertToThemeIcons' => t('editor_gxm_label_revert_to_theme_icons', 'revert all to theme icons'),
            'confirmApplyToAll' => t('editor_gxm_confirm_apply_to_all', 'Apply "%s" to every wormhole in this galaxy?'),
            'statusWorking' => t('editor_gxm_status_working', 'Working…'),
            'statusUpdatedOne' => t('editor_gxm_status_updated_one', 'Updated %d wormhole. Reload the visitor view to see the change.'),
            'statusUpdatedMany' => t('editor_gxm_status_updated_many', 'Updated %d wormholes. Reload the visitor view to see the change.'),
            'labelFailedPrefix' => t('editor_gxm_label_failed_prefix', 'Failed: %s'),
            'errUpdateFailedFallback' => t('editor_gxm_err_update_failed_fallback', 'Update failed'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
        window.escapeHtmlAdmin = window.escapeHtmlAdmin || function (str) {
            return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        };

        // Editors and admins both reach the same modal via this handler.
        // The select holds the current galaxy id; we look up its row via the
        // already-loaded constellation list.
        const TELARIS_GALAXIES = <?php echo json_encode(array_map(fn($c) => [
            'id' => (int)$c['id'],
            'name' => (string)($c['name'] ?? ''),
            'tagline' => (string)($c['tagline'] ?? ''),
            'slug' => (string)($c['slug'] ?? ''),
            'theme' => (string)($c['theme'] ?? 'cosmic'),
        ], $constellations)); ?>;

        function openCurrentGalaxySettings() {
            const sel = document.getElementById('current-constellation');
            const id = parseInt(sel?.value, 10);
            if (!id || isNaN(id)) return;
            const galaxy = TELARIS_GALAXIES.find(g => g.id === id);
            if (!galaxy) return;
            window.editConstellation(galaxy);
        }

        function refreshGalaxySettingsButton() {
            const sel = document.getElementById('current-constellation');
            const btn = document.getElementById('galaxy-settings-btn');
            const canvasBtn = document.getElementById('galaxy-canvas-btn');
            if (!sel) return;
            const v = sel.value;
            const show = (v && v !== 'all') ? '' : 'none';
            if (btn) btn.style.display = show;
            if (canvasBtn) canvasBtn.style.display = show;
        }

        function openCurrentGalaxyKeywordCanvas() {
            const sel = document.getElementById('current-constellation');
            const id = parseInt(sel?.value, 10);
            if (!id || isNaN(id)) return;
            window.open('keyword-canvas.php?galaxy_id=' + id, '_blank');
        }
        document.addEventListener('DOMContentLoaded', () => {
            const sel = document.getElementById('current-constellation');
            if (sel) sel.addEventListener('change', refreshGalaxySettingsButton);
            refreshGalaxySettingsButton();
        });

        <?php if ($galaxyEditMessage): ?>
        document.addEventListener('DOMContentLoaded', () => {
            showMessage(<?php echo json_encode($galaxyEditMessage); ?>, 'success');
        });
        <?php elseif ($galaxyEditError): ?>
        document.addEventListener('DOMContentLoaded', () => {
            showMessage(<?php echo json_encode($galaxyEditError); ?>, 'error');
        });
        <?php endif; ?>
    </script>
    <script src="../js/galaxy-edit-modal.js?v=<?php echo $appVersion; ?>"></script>
</body>
</html>