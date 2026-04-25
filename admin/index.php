<?php
declare(strict_types=1);

/**
 * Admin Console
 * Main administration interface combining API key management and PHP information
 */

// Check if config.php exists, if not redirect to setup
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit();
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob:; connect-src 'self'; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/../config.php';

$message = null;
$error = null;
$settingsError = null;
$activeTab = $_GET['tab'] ?? 'constellations';
// If editing a user, ensure we're on the users tab
if (isset($_GET['edit_user'])) {
    $activeTab = 'users';
}
if (isset($_GET['edit_constellation'])) {
    $activeTab = 'constellations';
}

// Load project info for Global Settings form
$projectAll = db_get_project_info_all_locales() ?: [];

$projectData = [];
$defaults = db_default_project_info_rows();
foreach (['en', 'es', 'pt'] as $l) {
    foreach (PROJECT_INFO_KEYS as $k) {
        $dataKey = ($l === 'en') ? $k : $k . '_' . $l;
        $projectData[$dataKey] = $projectAll[$dataKey] ?? $defaults[$l][$k] ?? '';
    }
}
// Legacy variable names for backward compatibility if needed in this file
$projectName = $projectData['name'];
$projectTagline = $projectData['description'];
$projectIframeBackText = $projectData['iframe_back_text'];
$projectAlertMessage = $projectData['alert_message'];
$projectEditButtonText = $projectData['edit_button_text'];
$projectLoadingText = $projectData['loading_text'];

$systemVersion = 'Unknown';
if (file_exists(__DIR__ . '/../VERSION')) {
    $systemVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
}

// CSRF token management
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';

// Handle API key actions and user management actions
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token on all POST actions
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Invalid or expired security token. Please try again.';
    } else {
    try {
        match ($_POST['action']) {
            'generate' => (function(): void {
                global $message, $activeTab;
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                if (empty($name)) {
                    throw new Exception('API key name is required');
                }
                $apiKey = bin2hex(random_bytes(32));
                db_insert_api_key($apiKey, $name, $description ?: null);
                $_SESSION['new_api_key'] = $apiKey;
                $_SESSION['new_api_key_name'] = $name;
                header('Location: index.php?tab=api-keys&generated=1');
                exit();
            })(),
            
            'toggle' => (function(): void {
                global $message, $activeTab;
                $id = (int)($_POST['id'] ?? 0);
                $isActive = (bool)($_POST['is_active'] ?? false);
                db_toggle_api_key($id, $isActive);
                $message = 'API key ' . ($isActive ? 'activated' : 'deactivated') . ' successfully.';
                $activeTab = 'api-keys';
            })(),
            
            'delete' => (function(): void {
                global $message, $activeTab;
                $id = (int)($_POST['id'] ?? 0);
                db_delete_api_key($id);
                $message = 'API key deleted successfully.';
                $activeTab = 'api-keys';
            })(),
            
            'create_user' => (function(): void {
                global $message, $error, $activeTab;
                $email = trim($_POST['email'] ?? '');
                $firstname = trim($_POST['firstname'] ?? '');
                $lastname = trim($_POST['lastname'] ?? '');
                $password = $_POST['password'] ?? '';
                $type = (int)($_POST['type'] ?? 0);
                if (empty($email) || empty($firstname) || empty($lastname) || empty($password)) {
                    throw new Exception('All fields are required');
                }
                if (strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format');
                }
                if ($type !== 1 && $type !== 2) {
                    throw new Exception('User type must be Editor (1) or Admin (2)');
                }
                if (db_user_email_exists($email)) {
                    throw new Exception('Email already exists');
                }
                $createConstellation = !empty($_POST['create_constellation']);
                $newConstellationName = trim((string)($_POST['new_constellation_name'] ?? ''));
                if ($createConstellation && $newConstellationName === '') {
                    throw new Exception('Galaxy name is required when "Create new galaxy" is checked.');
                }
                $hashedPassword = hashPassword($password);
                $err = createUser(getDB(), $email, $hashedPassword, $firstname, $lastname, $type);
                if ($err !== null) {
                    throw new Exception($err);
                }
                $newUser = db_get_user_by_email($email);
                if ($newUser && isset($newUser['id'])) {
                    $constellationIds = array_map('intval', array_filter((array)($_POST['constellation_ids'] ?? [])));
                    if ($createConstellation && $newConstellationName !== '') {
                        $newConstellationId = db_create_constellation($newConstellationName, '');
                        $constellationIds[] = $newConstellationId;
                    }
                    if ($type === USER_TYPE_EDITOR) {
                        db_set_user_constellations($newUser['id'], $constellationIds);
                    }
                }
                $message = 'User created successfully.';
                $activeTab = 'users';
            })(),
            
            'update_user' => (function(): void {
                global $message, $error, $activeTab;
                $id = trim($_POST['id'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $firstname = trim($_POST['firstname'] ?? '');
                $lastname = trim($_POST['lastname'] ?? '');
                $password = $_POST['password'] ?? '';
                $type = (int)($_POST['type'] ?? 0);
                if (empty($id) || empty($email) || empty($firstname) || empty($lastname)) {
                    throw new Exception('All required fields must be filled');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format');
                }
                if ($type !== 1 && $type !== 2) {
                    throw new Exception('User type must be Editor (1) or Admin (2)');
                }
                if (db_user_email_exists($email, $id)) {
                    throw new Exception('Email already exists for another user');
                }
                if ($id === ($_SESSION['admin_user_id'] ?? '') && $type !== USER_TYPE_ADMIN) {
                    throw new Exception('You cannot change your own user type');
                }
                if (!empty($password) && strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                $hashedPassword = !empty($password) ? hashPassword($password) : null;
                db_update_user($id, $email, $firstname, $lastname, $type, $hashedPassword);
                $constellationIds = array_map('intval', array_filter((array)($_POST['constellation_ids'] ?? [])));
                db_set_user_constellations($id, $type === USER_TYPE_EDITOR ? $constellationIds : []);
                $message = 'User updated successfully.';
                $activeTab = 'users';
            })(),
            
            'delete_user' => (function(): void {
                global $message, $error, $activeTab;
                $id = trim($_POST['id'] ?? '');
                if (empty($id)) {
                    throw new Exception('User ID is required');
                }
                if ($id === ($_SESSION['admin_user_id'] ?? '')) {
                    throw new Exception('You cannot delete your own account');
                }
                db_delete_user($id);
                $message = 'User deleted successfully.';
                $activeTab = 'users';
            })(),
            
            'create_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                if (empty($name)) {
                    throw new Exception('Galaxy name is required');
                }

                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A galaxy with this ' . implode(' and ', $errs) . ' already exists.');
                }

                $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech'];
                $theme = trim($_POST['theme'] ?? 'cosmic');
                if (!in_array($theme, $allowedThemes, true)) { $theme = 'cosmic'; }
                db_create_constellation($name, $tagline, $slug !== '' ? $slug : null, $theme);
                $message = 'Galaxy created successfully.';
                $activeTab = 'constellations';
            })(),
            
            'update_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech'];
                $theme = trim($_POST['theme'] ?? 'cosmic');
                if (!in_array($theme, $allowedThemes, true)) { $theme = 'cosmic'; }
                if (empty($name)) {
                    throw new Exception('Galaxy name is required');
                }

                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug, $id);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A galaxy with this ' . implode(' and ', $errs) . ' already exists.');
                }

                db_update_constellation($id, $name, $tagline, $slug !== '' ? $slug : null, $theme);

                db_set_constellation_tour_config($id, [
                    'tour_enabled' => !empty($_POST['tour_enabled']),
                    'tour_start_mode' => (string)($_POST['tour_start_mode'] ?? 'manual'),
                    'tour_idle_seconds' => (int)($_POST['tour_idle_seconds'] ?? 30),
                    'tour_node_selection' => (string)($_POST['tour_node_selection'] ?? 'all'),
                    'tour_random_count' => (int)($_POST['tour_random_count'] ?? 10),
                    'tour_default_dwell' => (int)($_POST['tour_default_dwell'] ?? 8),
                    'tour_loop' => !empty($_POST['tour_loop']),
                ]);
                $tourKeywordIds = array_map('intval', array_filter((array)($_POST['tour_keyword_ids'] ?? [])));
                db_set_tour_keyword_ids($id, $tourKeywordIds);

                $message = 'Galaxy updated successfully.';
                $activeTab = 'constellations';
            })(),
            
            'duplicate_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $sourceId = (int)($_POST['source_id'] ?? -1);
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                
                if (empty($name)) {
                    throw new Exception('New galaxy name is required');
                }

                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A galaxy with this ' . implode(' and ', $errs) . ' already exists.');
                }

                db_duplicate_constellation($sourceId, $name, $tagline, $slug !== '' ? $slug : null);
                $message = 'Galaxy duplicated successfully.';
                $activeTab = 'constellations';
            })(),
            
            'delete_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                db_delete_constellation($id);
                $message = 'Galaxy deleted successfully.';
                $activeTab = 'constellations';
            })(),
            
            'logout' => (function(): void {
                logoutAdmin();
                header('Location: ../utils/login.php');
                exit();
            })(),
            
            'save_settings' => (function(): void {
                global $message, $error, $settingsError, $activeTab, $projectData;
                $en = []; $es = []; $pt = [];
                foreach (PROJECT_INFO_KEYS as $k) {
                    $en[$k] = trim((string)($_POST[$k] ?? ''));
                    $es[$k] = trim((string)($_POST[$k . '_es'] ?? ''));
                    $pt[$k] = trim((string)($_POST[$k . '_pt'] ?? ''));
                }
                
                // Project name and description mapping
                $en['name'] = trim((string) ($_POST['project_name'] ?? ''));
                $en['description'] = trim((string) ($_POST['project_tagline'] ?? ''));
                $es['name'] = trim((string) ($_POST['project_name_es'] ?? ''));
                $es['description'] = trim((string) ($_POST['project_tagline_es'] ?? ''));
                $pt['name'] = trim((string) ($_POST['project_name_pt'] ?? ''));
                $pt['description'] = trim((string) ($_POST['project_tagline_pt'] ?? ''));

                if ($en['name'] !== '' && $en['iframe_back_text'] !== '' && $en['alert_message'] !== '' && $en['edit_button_text'] !== '' && $en['loading_text'] !== '') {
                    try {
                        $defaultConstellationId = isset($_POST['default_constellation_id']) ? (int)$_POST['default_constellation_id'] : null;
                        db_update_project_settings_with_locales($en, $es, $pt, $defaultConstellationId);
                        $lang = isset($_POST['settings_lang']) && in_array($_POST['settings_lang'], ['en', 'es', 'pt'], true) ? $_POST['settings_lang'] : 'en';
                        header('Location: index.php?tab=settings&saved=1&lang=' . urlencode($lang));
                        exit;
                    } catch (Throwable $e) {
                        $settingsError = 'Failed to save settings. Please try again. (' . htmlspecialchars($e->getMessage()) . ')';
                        $activeTab = 'settings';
                        foreach ($en as $k => $v) $projectData[$k] = $v;
                        foreach ($es as $k => $v) $projectData[$k . '_es'] = $v;
                        foreach ($pt as $k => $v) $projectData[$k . '_pt'] = $v;
                    }
                } else {
                    $settingsError = 'English app name, iframe button text, alert message, Edit button label, and Loading text are required.';
                    $activeTab = 'settings';
                    foreach ($en as $k => $v) $projectData[$k] = $v;
                    foreach ($es as $k => $v) $projectData[$k . '_es'] = $v;
                    foreach ($pt as $k => $v) $projectData[$k . '_pt'] = $v;
                }
            })(),
            
            default => throw new RuntimeException('Invalid action')
        };
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    } // end CSRF-valid block
}

// Get all API keys, users, and constellations
$apiKeys = db_get_api_keys();
$users = db_get_users();
$constellations = db_get_constellations();

// Group constellations by [Tag] prefix for visual grouping
function extractConstellationGroup(string $name): ?string {
    if (preg_match('/^\[([^\]]+)\]/', $name, $m)) {
        return $m[1];
    }
    return null;
}

$constellationGroupColors = [];
$pastelPalette = [
    '#FEF2F2', '#F0FAF0', '#EFF6FF', '#FFF8F0', '#F8F5FF',
    '#F0FDFA', '#FEFEF0', '#FFF5F5', '#F5F5F7', '#F5FAE8',
];
$groupColorIndex = 0;

// Sort: grouped constellations first (by group name), ungrouped after
usort($constellations, function ($a, $b) {
    $ga = extractConstellationGroup($a['name']);
    $gb = extractConstellationGroup($b['name']);
    if ($ga !== null && $gb === null) return -1;
    if ($ga === null && $gb !== null) return 1;
    if ($ga !== null && $gb !== null && $ga !== $gb) return strcasecmp($ga, $gb);
    return strcasecmp($a['name'], $b['name']);
});

// Assign a pastel color per unique group
foreach ($constellations as $c) {
    $group = extractConstellationGroup($c['name']);
    if ($group !== null && !isset($constellationGroupColors[$group])) {
        $constellationGroupColors[$group] = $pastelPalette[$groupColorIndex % count($pastelPalette)];
        $groupColorIndex++;
    }
}

// Get constellation access mapping for JavaScript
$userConstellationsMap = [];
foreach ($users as $u) {
    if ((int)$u['type'] === USER_TYPE_EDITOR) {
        $userConstellationsMap[$u['id']] = db_get_user_constellation_ids($u['id']);
    }
}

// Check for newly generated key
$newApiKey = $_SESSION['new_api_key'] ?? null;
$newApiKeyName = $_SESSION['new_api_key_name'] ?? null;
if ($newApiKey && isset($_GET['generated'])) {
    unset($_SESSION['new_api_key']);
    unset($_SESSION['new_api_key_name']);
}

// Get PHP information
$phpVersion = PHP_VERSION;
$phpSapi = php_sapi_name();
$loadedExtensions = @get_loaded_extensions();
if (!is_array($loadedExtensions)) {
    $loadedExtensions = [];
}
sort($loadedExtensions);

$phpConfig = [
    'Version' => PHP_VERSION,
    'SAPI' => php_sapi_name(),
    'Server API' => php_sapi_name(),
    'Zend Engine' => @zend_version() ?: 'Unknown',
    'Memory Limit' => @ini_get('memory_limit') ?: 'Not set',
    'Max Execution Time' => (@ini_get('max_execution_time') ?: '0') . ' seconds',
    'Upload Max Filesize' => @ini_get('upload_max_filesize') ?: 'Not set',
    'Post Max Size' => @ini_get('post_max_size') ?: 'Not set',
    'Default Timezone' => @date_default_timezone_get() ?: 'Not set',
    'Error Reporting' => (string)(@ini_get('error_reporting') ?: '0'),
    'Display Errors' => @ini_get('display_errors') ? 'On' : 'Off',
];

$importantExtensions = [
    'pdo' => 'PDO',
    'pdo_mysql' => 'PDO MySQL',
    'json' => 'JSON',
    'mbstring' => 'Multibyte String',
    'curl' => 'cURL',
    'openssl' => 'OpenSSL',
    'zip' => 'ZIP',
    'gd' => 'GD',
    'xml' => 'XML',
    'dom' => 'DOM',
];

$extensionStatus = [];
foreach ($importantExtensions as $ext => $name) {
    $extensionStatus[$name] = @extension_loaded($ext);
}

$fieldMeta = [
    'name' => ['label' => 'App name', 'desc' => 'Project title shown in the main view and in page metadata.', 'type' => 'text', 'post_name' => 'project_name'],
    'description' => ['label' => 'Description', 'desc' => 'Tagline or short description shown under the title and in page metadata.', 'type' => 'text', 'post_name' => 'project_tagline'],
    'iframe_back_text' => ['label' => 'Iframe button text', 'desc' => 'Text on the "Go back" button in the link window.', 'type' => 'text'],
    'alert_message' => ['label' => 'Alert message', 'desc' => 'Message when a link cannot be embedded.', 'type' => 'textarea'],
    'edit_button_text' => ['label' => 'Edit button label', 'desc' => 'Label for the Edit link shown to editors on the main view.', 'type' => 'text'],
    'loading_text' => ['label' => 'Loading text', 'desc' => 'Text shown in the loading overlay (e.g. "Loading").', 'type' => 'text'],
    'back_button_text' => ['label' => 'Back button text', 'desc' => 'Text on the back button when navigating between galaxies.', 'type' => 'text'],
    'system_online_text' => ['label' => 'System Online text', 'desc' => 'Status text shown in the HUD (e.g. "System: Online").', 'type' => 'text'],
    'reload_system_text' => ['label' => 'Reload System text', 'desc' => 'Tooltip for the reload action.', 'type' => 'text'],
    'scan_system_text' => ['label' => 'Scan System placeholder', 'desc' => 'Placeholder text for the search input.', 'type' => 'text'],
    'clear_scan_text' => ['label' => 'Clear Scan tooltip', 'desc' => 'Tooltip for the clear search button.', 'type' => 'text'],
    'systems_label_text' => ['label' => 'Systems label', 'desc' => 'Label for the wormholes count in the HUD.', 'type' => 'text'],
    'hyperlinks_label_text' => ['label' => 'Hyperlinks label', 'desc' => 'Label for the connections count in the HUD.', 'type' => 'text'],
    'initialize_auth_text' => ['label' => 'Login label', 'desc' => 'Label for the login link (e.g. "Initialize Auth").', 'type' => 'text'],
    'admin_label_text' => ['label' => 'Admin label', 'desc' => 'Label for the admin link.', 'type' => 'text'],
    'logout_label_text' => ['label' => 'Logout label', 'desc' => 'Label for the logout link.', 'type' => 'text'],
    'click_to_view_text' => ['label' => 'Click to view hint', 'desc' => 'Interaction hint shown in wormhole tooltips for mouse users.', 'type' => 'text'],
    'tap_to_view_text' => ['label' => 'Tap to view hint', 'desc' => 'Interaction hint shown in wormhole tooltips for touch users.', 'type' => 'text'],
    'open_portal_text' => ['label' => 'Open portal button text', 'desc' => 'Text on the button that opens a portal when it has a description.', 'type' => 'text'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>Admin Console - Telaris</title>
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
</head>
<body class="font-sans bg-gray-100 min-h-screen">
    <!-- Notification Container -->
    <div id="notification-container" class="fixed top-4 left-1/2 -translate-x-1/2 z-[2000] flex flex-col gap-2 w-full max-w-md pointer-events-none"></div>

    <!-- Initial Loading Overlay -->
    <div id="admin-loading-overlay" class="fixed inset-0 z-[1000] bg-gray-100 flex flex-col items-center justify-center transition-opacity duration-300">
        <span class="loading loading-spinner loading-lg text-neutral mb-4"></span>
        <p class="text-gray-600 font-medium">Loading Admin Console...</p>
    </div>

    <div class="max-w-6xl mx-auto py-8 px-5">
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-gray-800 text-3xl font-semibold">Admin Console</h1>
                    <p class="text-gray-600 mt-1">Welcome, <?php echo htmlspecialchars($_SESSION['admin_user_name'] ?? 'Admin'); ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="../edit/index.php" class="btn btn-neutral">
                        Edit Content
                    </a>
                    <form method="POST" action="" class="inline">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Messages Data (Hidden) -->
        <div id="php-messages" class="hidden">
            <?php if ($newApiKey): ?>
                <div data-type="success" data-title="✓ API Key Generated">
                    Your API Key: <?php echo htmlspecialchars($newApiKey); ?> (Name: <?php echo htmlspecialchars($newApiKeyName); ?>). PLEASE COPY IT NOW.
                </div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div data-type="success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div data-type="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
                <div data-type="success">Global settings saved.</div>
            <?php endif; ?>
            <?php if ($settingsError): ?>
                <div data-type="error"><?php echo htmlspecialchars($settingsError); ?></div>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="mb-6">
            <div class="tabs tabs-lifted">
                <button onclick="showTab('constellations')"
                        id="tab-constellations"
                        class="tab tab-lg <?php echo $activeTab === 'constellations' ? 'tab-active' : ''; ?>">
                    Galaxies
                </button>
                <button onclick="showTab('users')"
                        id="tab-users"
                        class="tab tab-lg <?php echo $activeTab === 'users' ? 'tab-active' : ''; ?>">
                    Users
                </button>
                <button onclick="showTab('backup')"
                        id="tab-backup"
                        class="tab tab-lg <?php echo $activeTab === 'backup' ? 'tab-active' : ''; ?>">
                    Backup
                </button>
                <button onclick="showTab('snapshots')"
                        id="tab-snapshots"
                        class="tab tab-lg <?php echo $activeTab === 'snapshots' ? 'tab-active' : ''; ?>">
                    Snapshots
                </button>
                <button onclick="showTab('settings')"
                        id="tab-settings"
                        class="tab tab-lg <?php echo $activeTab === 'settings' ? 'tab-active' : ''; ?>">
                    Global Settings
                </button>
                <button onclick="showTab('api-keys')"
                        id="tab-api-keys"
                        class="tab tab-lg <?php echo $activeTab === 'api-keys' ? 'tab-active' : ''; ?>">
                    API Keys
                </button>
                <button onclick="showTab('php-info')"
                        id="tab-php-info"
                        class="tab tab-lg <?php echo $activeTab === 'php-info' ? 'tab-active' : ''; ?>">
                    PHP Information
                </button>
            </div>
        </div>

        <div class="bg-white rounded-b-lg shadow-md mb-6 -mt-6 pt-6">
            <!-- Users Tab -->
            <div id="content-users" class="p-6 <?php echo $activeTab !== 'users' ? 'hidden' : ''; ?>">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-gray-800 text-base font-semibold">Users (<?php echo count($users); ?>)</h2>
                            <button type="button" onclick="document.getElementById('create_user_modal').showModal()" class="text-blue-600 hover:text-blue-800 font-medium text-base">New User</button>
                        </div>
                        
                        <!-- Top Pagination -->
                        <div id="users-pagination-header" class="flex-1 flex justify-center"></div>

                        <div class="flex items-center gap-2 min-w-[250px]">
                            <label for="search-users" class="text-sm font-medium text-gray-700">Search:</label>
                            <input type="text" 
                                   id="search-users" 
                                   placeholder="Search users..." 
                                   oninput="applyUserSearch()"
                                   class="flex-1 p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <?php if (empty($users)): ?>
                        <p class="text-gray-600">No users found.</p>
                    <?php else: ?>
                        <div class="border border-gray-300 rounded">
                            <table id="users-list" class="w-full border-collapse">
                                <thead>
                                    <tr class="border-b-2 border-gray-400 bg-gray-100">
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('name')">Name<span id="sort-indicator-name"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('email')">Email<span id="sort-indicator-email"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('type')">Type<span id="sort-indicator-type"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('date_created')">Created<span id="sort-indicator-date_created"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('date_last_login')">Last Login<span id="sort-indicator-date_last_login"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('updated_at')">Last Updated<span id="sort-indicator-updated_at"></span></span>
                                        </th>
                                        <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $typeLabels = [0 => 'Regular', 1 => 'Editor', 2 => 'Admin'];
                                        $typeColors = [0 => 'bg-gray-400', 1 => 'bg-blue-400', 2 => 'bg-purple-400'];
                                        $userType = (int)$user['type'];
                                        $fullName = htmlspecialchars($user['firstname'] . ' ' . $user['lastname']);
                                        $email = htmlspecialchars($user['email']);
                                        $createdTs = strtotime($user['date_created'] ?? '');
                                        $lastLoginTs = !empty($user['date_last_login']) ? strtotime($user['date_last_login']) : false;
                                        $updatedTs = !empty($user['updated_at']) ? strtotime($user['updated_at']) : false;
                                        $createdIso = $createdTs !== false ? gmdate('c', $createdTs) : '';
                                        $lastLoginIso = $lastLoginTs !== false ? gmdate('c', $lastLoginTs) : null;
                                        $updatedIso = $updatedTs !== false ? gmdate('c', $updatedTs) : null;
                                        $isCurrentUser = $user['id'] === ($_SESSION['admin_user_id'] ?? '');
                                        ?>
                                        <tr class="user-row border-b border-gray-300 hover:bg-gray-50" 
                                            data-user-id="<?php echo htmlspecialchars($user['id']); ?>" 
                                            data-name="<?php echo htmlspecialchars(strtolower($user['firstname'] . ' ' . $user['lastname'])); ?>" 
                                            data-email="<?php echo htmlspecialchars(strtolower($user['email'])); ?>" 
                                            data-type="<?php echo $userType; ?>" 
                                            data-date-created="<?php echo $createdTs !== false ? $createdTs : '0'; ?>" 
                                            data-date-last-login="<?php echo $lastLoginTs !== false ? $lastLoginTs : '0'; ?>"
                                            data-updated-at="<?php echo $updatedTs !== false ? $updatedTs : '0'; ?>">
                                            <?php 
                                            $userData = [
                                                'id' => $user['id'],
                                                'firstname' => $user['firstname'],
                                                'lastname' => $user['lastname'],
                                                'email' => $user['email'],
                                                'type' => $user['type']
                                            ];
                                            $userJson = htmlspecialchars(json_encode($userData), ENT_QUOTES, 'UTF-8');
                                            $clickEdit = "editUser($userJson)";
                                            ?>
                                            <td class="py-2 px-2 font-semibold text-gray-800 max-w-[12rem] cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <span class="block truncate" title="<?php echo $fullName; ?>"><?php echo $fullName; ?><?php if ($isCurrentUser): ?> <span class="ml-1 text-xs bg-green-400 text-white px-1.5 py-0.5 rounded">You</span><?php endif; ?></span>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-600 max-w-[14rem] cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <span class="block truncate" title="<?php echo $email; ?>"><?php echo $email; ?></span>
                                            </td>
                                            <td class="py-2 px-2 cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <span class="text-xs <?php echo $typeColors[$userType]; ?> text-white px-2 py-1 rounded"><?php echo $typeLabels[$userType]; ?></span>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <?php if ($createdIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($createdIso); ?>"><?php echo date('y-m-d H:i', $createdTs); ?></span><?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <?php if ($lastLoginIso !== null): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($lastLoginIso); ?>"><?php echo date('y-m-d H:i', $lastLoginTs); ?></span><?php else: ?>Never<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <?php if ($updatedIso !== null): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($updatedIso); ?>"><?php echo date('y-m-d H:i', $updatedTs); ?></span><?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-right">
                                                <div class="flex justify-end">
                                                    <div class="dropdown dropdown-end">
                                                        <label tabindex="0" onclick="event.stopPropagation(); closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                                        </label>
                                                        <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-36">
                                                            <li><a onclick="event.stopPropagation(); <?php echo $clickEdit; ?>" class="text-gray-700 text-xs">Edit</a></li>
                                                            <?php if (!$isCurrentUser): ?>
                                                                <?php
                                                                $delMsg = "Are you sure you want to delete the user \"$fullName\"? This action cannot be undone.";
                                                                $delMsgJs = htmlspecialchars(json_encode($delMsg), ENT_QUOTES, 'UTF-8');
                                                                ?>
                                                                <li><a onclick="event.stopPropagation(); triggerDelete('delete_user', '<?php echo addslashes($user['id']); ?>', <?php echo $delMsgJs; ?>, null)" class="text-red-600 text-xs">Delete</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- API Keys Tab -->
            <div id="content-api-keys" class="p-6 <?php echo $activeTab !== 'api-keys' ? 'hidden' : ''; ?>">
                <!-- Generate New API Key Form (hidden by default; shown when New API Key clicked) -->
                <div id="api-key-form-panel" class="mb-8 p-4 bg-blue-50 border border-blue-200 rounded hidden">
                    <h2 class="text-blue-800 text-xl font-semibold mb-4">Generate New API Key</h2>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="generate">
                        <div class="mb-4">
                            <label for="name" class="block mb-1.5 text-gray-800 font-medium">Name *</label>
                            <input type="text" 
                                   id="name" 
                                   name="name" 
                                   required 
                                   placeholder="e.g., Frontend App, Mobile App, Admin"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            <span class="text-xs text-gray-500 mt-1 block">A descriptive name for this API key</span>
                        </div>
                        <div class="mb-4">
                            <label for="description" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                            <textarea id="description" 
                                      name="description" 
                                      rows="2"
                                      placeholder="Optional description of what this key is used for"
                                      class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                                Generate API Key
                            </button>
                            <button type="button" onclick="document.getElementById('api-key-form-panel').classList.add('hidden');" class="bg-gray-500 hover:bg-gray-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- API Keys list -->
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <h2 class="text-gray-800 text-base font-semibold">API Keys (<?php echo count($apiKeys); ?>)</h2>
                        <a href="#" onclick="document.getElementById('api-key-form-panel').classList.remove('hidden'); return false;" class="text-blue-600 hover:text-blue-800 font-medium text-base">New API Key</a>
                    </div>
                    
                    <?php if (empty($apiKeys)): ?>
                        <p class="text-gray-600">No API keys have been generated yet.</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($apiKeys as $key): ?>
                                <div class="p-4 border border-gray-300 rounded <?php echo $key['is_active'] ? 'bg-white' : 'bg-gray-100'; ?>">
                                    <div class="flex items-start justify-between mb-2">
                                        <div class="flex-1">
                                            <h3 class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($key['name']); ?>
                                                <?php if (!$key['is_active']): ?>
                                                    <span class="ml-2 text-xs bg-gray-400 text-white px-2 py-1 rounded">Inactive</span>
                                                <?php endif; ?>
                                            </h3>
                                            <?php if ($key['description']): ?>
                                                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($key['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dropdown dropdown-end">
                                            <label tabindex="0" onclick="closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                            </label>
                                            <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-40">
                                                <li>
                                                    <form method="POST" action="" class="p-0 m-0">
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                                        <input type="hidden" name="is_active" value="<?php echo $key['is_active'] ? '0' : '1'; ?>">
                                                        <button type="submit" class="w-full text-left text-gray-700 text-xs px-3 py-1.5 hover:bg-gray-100 rounded">
                                                            <?php echo $key['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" action="" class="p-0 m-0" onsubmit="return confirm('Are you sure you want to delete this API key? This action cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                                        <button type="submit" class="w-full text-left text-red-600 text-xs px-3 py-1.5 hover:bg-gray-100 rounded">
                                                            Delete
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500 space-y-1">
                                        <?php
                                        $keyCreatedTs = isset($key['created_at']) && $key['created_at'] !== '' ? strtotime($key['created_at']) : false;
                                        $keyUpdatedTs = isset($key['updated_at']) && $key['updated_at'] !== '' ? strtotime($key['updated_at']) : false;
                                        $keyCreatedIso = $keyCreatedTs !== false ? gmdate('c', $keyCreatedTs) : '';
                                        $keyUpdatedIso = $keyUpdatedTs !== false ? gmdate('c', $keyUpdatedTs) : '';
                                        ?>
                                        <p><strong>Created:</strong> <?php if ($keyCreatedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyCreatedIso); ?>"><?php echo date('y-m-d H:i', $keyCreatedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                        <?php if (!empty($key['last_used_at'])): ?>
                                            <?php $keyLastUsedTs = strtotime($key['last_used_at']); $keyLastUsedIso = $keyLastUsedTs !== false ? gmdate('c', $keyLastUsedTs) : ''; ?>
                                            <p><strong>Last Used:</strong> <?php if ($keyLastUsedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyLastUsedIso); ?>"><?php echo date('y-m-d H:i', $keyLastUsedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                        <?php else: ?>
                                            <p><strong>Last Used:</strong> Never</p>
                                        <?php endif; ?>
                                        <p><strong>Last Updated:</strong> <?php if ($keyUpdatedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyUpdatedIso); ?>"><?php echo date('y-m-d H:i', $keyUpdatedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Constellations Tab -->
            <div id="content-constellations" class="p-6 <?php echo $activeTab !== 'constellations' ? 'hidden' : ''; ?>">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-gray-800 text-base font-semibold">Galaxies (<span id="constellations-count">...</span>)</h2>
                            <button type="button" onclick="document.getElementById('create_constellation_modal').showModal()" class="text-blue-600 hover:text-blue-800 font-medium text-base">New Galaxy</button>
                            <button type="button" onclick="openMocambosImportModal()" class="text-purple-600 hover:text-purple-800 font-medium text-base">Import from Mocambos</button>
                        </div>

                        <!-- Top Pagination -->
                        <div id="constellations-pagination-header" class="flex-1 flex justify-center"></div>

                        <div class="flex items-center gap-2 min-w-[250px]">
                            <label for="search-constellations" class="text-sm font-medium text-gray-700">Search:</label>
                            <input type="text" 
                                   id="search-constellations" 
                                   placeholder="Search galaxies..." 
                                   oninput="debouncedConstSearch()"
                                   class="flex-1 p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    <?php 
                        $defaultId = (int)($projectAll['default_constellation_id'] ?? 0); 
                        $defaultName = 'ID ' . $defaultId;
                        foreach($constellations as $c) {
                            if ((int)$c['id'] === $defaultId) {
                                $defaultName = htmlspecialchars($c['name']) . ' (ID ' . $defaultId . ')';
                                break;
                            }
                        }
                    ?>
                    <p class="text-sm text-gray-600 mb-4">Each galaxy is a separate set of wormholes and keywords. The current default galaxy, <strong><?php echo $defaultName; ?></strong>, cannot be deleted.<br>You can change the default galaxy in the <button onclick="showTab(\'settings\')" class="text-blue-600 hover:underline">Global Settings</button> tab.</p>
                    <div id="copy-url-toast" class="hidden fixed top-4 right-4 z-50 bg-green-600 text-white px-4 py-3 rounded shadow-lg text-sm" role="status" aria-live="polite">URL copied to clipboard.</div>
                    <div id="constellations-list-container"></div>
                </div>
            </div>

            <!-- Global Settings Tab -->
            <div id="content-settings" class="p-6 <?php echo $activeTab !== 'settings' ? 'hidden' : ''; ?>">
                <div class="flex justify-between items-center mb-4">
                    <p class="text-gray-600 max-w-2xl">Localized content for the main app. English is required; Spanish and Portuguese are optional and fall back to English when empty.</p>
                    <div class="bg-gray-100 px-3 py-1.5 rounded-md border border-gray-200">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Version</span>
                        <span class="ml-2 font-mono text-sm font-bold text-gray-700"><?php echo htmlspecialchars($systemVersion); ?></span>
                    </div>
                </div>
                <form method="post" action="" class="max-w-2xl">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="settings_lang" id="settings_lang" value="<?php echo htmlspecialchars($_GET['lang'] ?? 'en'); ?>">
                    
                    <div class="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <label for="default_constellation_id" class="block mb-1.5 text-gray-800 font-medium text-sm">Default Galaxy</label>
                        <select id="default_constellation_id" name="default_constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php
                            $currentOptgroup = null;
                            $inOptgroup = false;
                            foreach ($constellations as $c):
                                $g = extractConstellationGroup($c['name']);
                                if ($g !== $currentOptgroup) {
                                    if ($inOptgroup) { echo '</optgroup>'; $inOptgroup = false; }
                                    if ($g !== null) { echo '<optgroup label="' . htmlspecialchars($g) . '">'; $inOptgroup = true; }
                                    $currentOptgroup = $g;
                                }
                            ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo (isset($projectAll['default_constellation_id']) && (int)$projectAll['default_constellation_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                    [ID: <?php echo (int)$c['id']; ?>] <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach;
                            if ($inOptgroup) echo '</optgroup>';
                            ?>
                        </select>
                        <span class="text-xs text-gray-500 mt-1 block">Choose which galaxy is shown at the root of the website. The chosen galaxy will also have its name and tagline synced with the "App name" and "Description" fields below.</span>
                    </div>

                    <div class="border border-gray-200 rounded-lg bg-white overflow-hidden">
                        <div class="border-b border-gray-200 bg-gray-50">
                            <nav class="flex">
                                <button type="button" onclick="showSettingsLang('en')" id="settings-lang-tab-en" class="px-5 py-3 font-medium text-sm border-b-2 <?php echo ($_GET['lang'] ?? 'en') === 'en' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">English</button>
                                <button type="button" onclick="showSettingsLang('es')" id="settings-lang-tab-es" class="px-5 py-3 font-medium text-sm border-b-2 <?php echo ($_GET['lang'] ?? '') === 'es' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">Spanish</button>
                                <button type="button" onclick="showSettingsLang('pt')" id="settings-lang-tab-pt" class="px-5 py-3 font-medium text-sm border-b-2 <?php echo ($_GET['lang'] ?? '') === 'pt' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">Portuguese</button>
                            </nav>
                        </div>
                        <?php foreach (['en', 'es', 'pt'] as $l): ?>
                        <div id="settings-lang-<?php echo $l; ?>" class="p-6 space-y-4 <?php echo ($_GET['lang'] ?? 'en') !== $l ? 'hidden' : ''; ?>">
                            <?php foreach ($fieldMeta as $k => $m): ?>
                            <?php 
                                $inputName = ($m['post_name'] ?? $k) . ($l === 'en' ? '' : '_' . $l); 
                                $val = $projectData[($l === 'en' ? $k : $k . '_' . $l)] ?? '';
                                $required = ($l === 'en' && $k !== 'description');
                            ?>
                            <div>
                                <label for="<?php echo $inputName; ?>" class="block mb-1.5 text-gray-800 font-medium"><?php echo htmlspecialchars($m['label']); ?></label>
                                <?php if ($m['type'] === 'textarea'): ?>
                                <textarea id="<?php echo $inputName; ?>" name="<?php echo $inputName; ?>" rows="2" <?php echo $required ? 'required' : ''; ?> class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($val); ?></textarea>
                                <?php else: ?>
                                <input type="text" id="<?php echo $inputName; ?>" name="<?php echo $inputName; ?>" value="<?php echo htmlspecialchars($val); ?>" <?php echo $required ? 'required' : ''; ?> class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <?php endif; ?>
                                <span class="text-xs text-gray-500 mt-1 block"><?php echo htmlspecialchars($m['desc']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-neutral">Save settings</button>
                    </div>
                </form>
            </div>

            <!-- Backup Tab -->
            <div id="content-backup" class="p-6 <?php echo $activeTab !== 'backup' ? 'hidden' : ''; ?>">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                    <!-- Export -->
                    <section>
                        <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold">Download a backup</h2>
                        <p class="text-sm text-gray-600 mb-4">Create a portable backup file containing galaxies and/or users. The default produces a full backup with embedded media.</p>

                        <form id="backup-export-form" method="POST" action="backup/export.php" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                            <div class="border border-gray-300 rounded p-4">
                                <label class="flex items-center gap-2 mb-3">
                                    <input type="checkbox" name="include_galaxies" value="1" checked id="export-include-galaxies" class="checkbox checkbox-sm">
                                    <span class="font-semibold">Galaxies</span>
                                </label>
                                <div id="export-galaxies-options" class="ml-6 space-y-2">
                                    <label class="flex items-center gap-2"><input type="radio" name="galaxy_scope" value="all" checked class="radio radio-sm"> <span>All galaxies</span></label>
                                    <label class="flex items-center gap-2"><input type="radio" name="galaxy_scope" value="selected" class="radio radio-sm"> <span>Selected galaxies only</span></label>
                                    <div id="export-galaxy-picker" class="hidden mt-3 border border-gray-200 rounded p-3 bg-gray-50">
                                        <div id="export-prefix-chips" class="flex flex-wrap gap-1 mb-3"></div>
                                        <div id="export-galaxy-list" class="max-h-64 overflow-y-auto bg-white border border-gray-200 rounded">
                                            <p class="text-xs text-gray-500 p-3">Loading galaxies...</p>
                                        </div>
                                        <div class="flex justify-between mt-2 text-xs">
                                            <button type="button" onclick="exportGalaxiesSelectAll(true)" class="text-blue-600 hover:underline">Select all</button>
                                            <button type="button" onclick="exportGalaxiesSelectAll(false)" class="text-blue-600 hover:underline">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border border-gray-300 rounded p-4">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="include_users" value="1" checked class="checkbox checkbox-sm">
                                    <span class="font-semibold">Users (always all)</span>
                                </label>
                                <p class="text-xs text-gray-500 ml-6">User passwords are exported as hashes. They never appear in plaintext.</p>
                            </div>

                            <div class="border border-gray-300 rounded p-4">
                                <div class="font-semibold mb-2">Media files</div>
                                <div class="space-y-1 text-sm">
                                    <label class="flex items-center gap-2"><input type="radio" name="media_mode" value="embedded" checked class="radio radio-sm"> <span>Embedded — self-contained backup (recommended)</span></label>
                                    <label class="flex items-center gap-2"><input type="radio" name="media_mode" value="refs" class="radio radio-sm"> <span>References only — smaller file, only restorable on the same server</span></label>
                                    <label class="flex items-center gap-2"><input type="radio" name="media_mode" value="none" class="radio radio-sm"> <span>None — strip all media</span></label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">Download backup</button>
                        </form>
                    </section>

                    <!-- Import -->
                    <section>
                        <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold">Restore from a backup</h2>
                        <p class="text-sm text-gray-600 mb-4">Upload a <code>.telaris-backup</code> file. You will see a summary before anything is changed.</p>

                        <div class="space-y-4">
                            <div class="border border-gray-300 rounded p-4">
                                <input type="file" id="backup-import-file" accept=".telaris-backup,application/gzip,application/octet-stream" class="file-input file-input-bordered file-input-sm w-full" onchange="backupOnFilePicked()">
                                <div id="backup-import-file-info" class="hidden mt-2 text-xs text-gray-600"></div>
                                <button type="button" id="backup-import-inspect-btn" onclick="backupInspect()" class="btn btn-neutral btn-sm mt-3">Inspect file</button>
                                <div id="backup-import-status" class="hidden mt-3 text-sm">
                                    <div id="backup-import-status-text" class="text-gray-700"></div>
                                    <div id="backup-import-progress-wrap" class="hidden mt-1 w-full bg-gray-200 rounded h-2 overflow-hidden">
                                        <div id="backup-import-progress-bar" class="bg-blue-500 h-2 transition-all duration-200" style="width: 0%"></div>
                                    </div>
                                </div>
                            </div>

                            <div id="backup-import-summary" class="hidden border border-blue-300 bg-blue-50 rounded p-4 text-sm">
                                <!-- Filled by JS -->
                            </div>

                            <div id="backup-import-options" class="hidden border border-gray-300 rounded p-4 space-y-4">
                                <div>
                                    <div class="font-semibold mb-2">Galaxies in this file</div>
                                    <div id="import-prefix-chips" class="flex flex-wrap gap-1 mb-2"></div>
                                    <div id="import-galaxy-list" class="max-h-64 overflow-y-auto bg-white border border-gray-200 rounded"></div>
                                    <div class="flex justify-between mt-2 text-xs">
                                        <button type="button" onclick="importGalaxiesSelectAll(true)" class="text-blue-600 hover:underline">Select all</button>
                                        <button type="button" onclick="importGalaxiesSelectAll(false)" class="text-blue-600 hover:underline">Clear</button>
                                    </div>
                                </div>

                                <div>
                                    <div class="font-semibold mb-2">For each selected galaxy</div>
                                    <div class="space-y-1 text-sm">
                                        <label class="flex items-center gap-2"><input type="radio" name="import_conflict" value="overwrite" checked class="radio radio-sm"> <span>Overwrite if a galaxy with the same slug exists</span></label>
                                        <label class="flex items-center gap-2"><input type="radio" name="import_conflict" value="rename" class="radio radio-sm"> <span>Create as new (rename on conflict, suffix:</span>
                                            <input type="text" id="import-rename-suffix" value=" (restored)" class="input input-bordered input-xs ml-1" style="width: 140px;">
                                            <span>)</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="border-t pt-3">
                                    <div class="font-semibold mb-2">Users in this file</div>
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="import-restore-users" checked class="checkbox checkbox-sm"> <span>Restore users</span></label>
                                    <div class="ml-6 mt-2 space-y-1 text-sm">
                                        <label class="flex items-center gap-2"><input type="radio" name="import_users_mode" value="skip" checked class="radio radio-sm"> <span>Skip existing users (match by email)</span></label>
                                        <label class="flex items-center gap-2"><input type="radio" name="import_users_mode" value="replace" class="radio radio-sm"> <span>Update existing users by email</span></label>
                                        <label class="flex items-center gap-2 ml-6"><input type="checkbox" id="import-users-replace-pw" class="checkbox checkbox-sm"> <span>Also overwrite password hashes</span></label>
                                    </div>
                                </div>

                                <div class="border-t pt-3">
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="import-restore-media" checked class="checkbox checkbox-sm"> <span>Restore media files</span></label>
                                </div>

                                <div class="border-t pt-3">
                                    <button type="button" onclick="backupCommit()" class="btn btn-warning">Restore</button>
                                </div>
                            </div>

                            <div id="backup-import-result" class="hidden border border-green-300 bg-green-50 rounded p-4 text-sm">
                                <!-- Filled by JS -->
                            </div>
                        </div>
                    </section>

                </div>
            </div>

            <!-- Snapshots Tab -->
            <div id="content-snapshots" class="p-6 <?php echo $activeTab !== 'snapshots' ? 'hidden' : ''; ?>">
                <p class="text-sm text-gray-600 mb-4">Snapshots are local, on-disk full backups of the entire system. Restoring a snapshot wipes everything and replaces it with the snapshot's state. Any snapshots created after the restored one are deleted.</p>

                <!-- Create snapshot -->
                <section class="mb-8 border border-gray-300 rounded p-4">
                    <h2 class="text-lg font-semibold mb-3">Create snapshot now</h2>
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="text" id="snapshot-note" placeholder="Optional note (e.g. before migration)" class="input input-bordered input-sm flex-1 min-w-[240px]">
                        <button type="button" id="snapshot-create-btn" onclick="snapshotCreate()" class="btn btn-neutral btn-sm">Create snapshot</button>
                    </div>
                    <div id="snapshot-create-progress" class="mt-3 hidden">
                        <progress class="progress progress-neutral w-full"></progress>
                        <p id="snapshot-create-progress-label" class="text-xs text-gray-600 mt-1">Creating snapshot. This may take a minute for large instances. Please do not close this tab.</p>
                    </div>
                </section>

                <!-- Schedule -->
                <section class="mb-8 border border-gray-300 rounded p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h2 class="text-lg font-semibold">Snapshot scheduler</h2>
                        <label class="text-sm flex items-center gap-3 cursor-pointer select-none">
                            <span class="font-medium">Enable daily snapshots</span>
                            <input type="checkbox" id="schedule-enabled" class="toggle toggle-neutral toggle-sm">
                        </label>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
                        <label class="text-sm">Hour (UTC)
                            <input type="number" id="schedule-hour" min="0" max="23" value="3" class="input input-bordered input-sm w-full">
                        </label>
                        <label class="text-sm">Keep days (auto)
                            <input type="number" id="schedule-keep-days" min="1" value="7" class="input input-bordered input-sm w-full">
                        </label>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 items-center">
                        <button type="button" onclick="scheduleSave()" class="btn btn-neutral btn-sm">Save</button>
                        <button type="button" onclick="snapshotsLoad()" class="btn btn-ghost btn-sm">Refresh status</button>
                    </div>

                    <div class="mt-4 pt-3 border-t border-gray-200">
                        <div class="flex flex-wrap gap-4 text-sm">
                            <div>
                                <span class="text-gray-500">Status:</span>
                                <span id="scheduler-status-badge" class="ml-1 px-2 py-0.5 rounded text-xs bg-gray-200">loading...</span>
                            </div>
                            <div>
                                <span class="text-gray-500">Last snapshot:</span>
                                <span id="scheduler-last-run" class="ml-1">never</span>
                            </div>
                            <div>
                                <span class="text-gray-500">Last checked:</span>
                                <span id="scheduler-last-check" class="ml-1">never</span>
                            </div>
                        </div>
                        <div id="scheduler-status-detail" class="text-xs text-amber-700 mt-2 hidden"></div>
                        <div class="text-xs text-gray-600 mt-3 mb-1">Recent activity</div>
                        <pre id="scheduler-log" class="bg-gray-900 text-green-200 p-2 rounded text-xs overflow-x-auto max-h-64 whitespace-pre-wrap">(no activity yet)</pre>
                    </div>
                </section>

                <!-- List -->
                <section>
                    <h2 class="text-lg font-semibold mb-3">Available snapshots</h2>
                    <div id="snapshots-table-wrap">
                        <p class="text-sm text-gray-500">Loading...</p>
                    </div>
                </section>
            </div>

            <!-- PHP Information Tab -->
            <div id="content-php-info" class="p-6 <?php echo $activeTab !== 'php-info' ? 'hidden' : ''; ?>">
                <!-- PHP Configuration -->
                <div class="mb-6">
                    <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold">PHP Configuration</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
                        <?php foreach ($phpConfig as $label => $value): ?>
                            <div class="p-2.5 bg-gray-50 rounded">
                                <div class="font-semibold text-gray-600 text-sm mb-1"><?php echo htmlspecialchars($label); ?></div>
                                <div class="text-gray-800 text-lg"><?php echo htmlspecialchars((string)$value); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Important Extensions -->
                <div class="mb-6">
                    <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold">Important Extensions</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5 mt-4">
                        <?php foreach ($extensionStatus as $name => $installed): ?>
                            <div class="p-3 rounded flex items-center gap-2.5 <?php echo $installed ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-700'; ?>">
                                <span class="font-bold text-xl"><?php echo $installed ? '✓' : '✗'; ?></span>
                                <span><?php echo htmlspecialchars($name); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- All Loaded Extensions -->
                <div>
                    <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold">All Loaded Extensions (<?php echo count($loadedExtensions); ?>)</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2 mt-4">
                        <?php foreach ($loadedExtensions as $ext): ?>
                            <span class="p-1.5 px-2.5 bg-gray-100 rounded text-sm font-mono"><?php echo htmlspecialchars($ext); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
    
    <script>
        const API_KEY = <?php echo json_encode(getDefaultApiKey()); ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
        const API_URL = '../api/validate.php';
        const MOCAMBOS_API = '../api/mocambos.php';
        let mocambosApiBase = '';

        function closeAllDropdowns(except) {
            document.querySelectorAll('.dropdown').forEach(d => {
                const label = d.querySelector('[tabindex="0"]');
                if (label && label !== except) label.blur();
            });
        }
        document.addEventListener('click', () => closeAllDropdowns(null));

        function openMocambosImportModal() {
            showMocambosUrlStep();
            document.getElementById('mocambos_import_modal').showModal();
        }

        function showMocambosUrlStep() {
            document.getElementById('mocambos-url-step').classList.remove('hidden');
            document.getElementById('mocambos-loading').classList.add('hidden');
            document.getElementById('mocambos-error').classList.add('hidden');
            document.getElementById('mocambos-list').classList.add('hidden');
            document.getElementById('mocambos-import-btn').classList.add('hidden');
            document.getElementById('mocambos-import-progress').classList.add('hidden');
            document.getElementById('mocambos-import-result').classList.add('hidden');
            document.getElementById('refresh-confirm-step').classList.add('hidden');
        }

        function buildValidationReport(apiBase, checks) {
            const statusIcon = { ok: '✅', warn: '⚠️', fail: '❌' };
            let lines = [];
            lines.push('Mocambos API Validation Report');
            lines.push('URL: ' + apiBase);
            lines.push('Date: ' + new Date().toISOString());
            lines.push('---');
            checks.forEach(c => {
                lines.push(`${statusIcon[c.status] || '?'} ${c.endpoint} (HTTP ${c.http_status || '—'})`);
                lines.push(`   ${c.detail}`);
            });
            return lines.join('\n');
        }

        async function fetchMocambosGalaxias() {
            const urlInput = document.getElementById('mocambos-api-url');
            const apiBase = urlInput.value.trim().replace(/\/+$/, '').replace(/\/docs#?.*$/i, '');
            if (!apiBase) {
                showMessage('Please enter a Mocambos API URL.', 'error');
                return;
            }
            mocambosApiBase = apiBase;

            const urlStep = document.getElementById('mocambos-url-step');
            const loading = document.getElementById('mocambos-loading');
            const errorDiv = document.getElementById('mocambos-error');
            const errorMsg = document.getElementById('mocambos-error-message');
            const listDiv = document.getElementById('mocambos-list');
            const galaxiasDiv = document.getElementById('mocambos-galaxias');
            const importBtn = document.getElementById('mocambos-import-btn');
            const resultDiv = document.getElementById('mocambos-import-result');
            const progressDiv = document.getElementById('mocambos-import-progress');

            urlStep.classList.add('hidden');
            loading.classList.remove('hidden');
            loading.querySelector('p').textContent = 'Validating API...';
            errorDiv.classList.add('hidden');
            listDiv.classList.add('hidden');
            importBtn.classList.add('hidden');
            progressDiv.classList.add('hidden');
            resultDiv.classList.add('hidden');
            resultDiv.innerHTML = '';
            galaxiasDiv.innerHTML = '';

            // Step 1: Validate
            try {
                const valResp = await fetch(`${MOCAMBOS_API}?action=validate&api_base=${encodeURIComponent(mocambosApiBase)}`, {
                    headers: { 'X-API-Key': API_KEY }
                });
                const valData = await valResp.json();

                if (!valResp.ok || !valData.valid) {
                    loading.classList.add('hidden');
                    const checks = valData.checks || [];
                    const report = buildValidationReport(mocambosApiBase, checks);

                    let html = '<div class="text-left">';
                    html += '<p class="font-medium text-red-700 mb-2">API validation failed. The following issues were found:</p>';
                    html += '<div class="space-y-2 mb-3">';
                    checks.forEach(c => {
                        const color = c.status === 'ok' ? 'green' : c.status === 'warn' ? 'yellow' : 'red';
                        const icon = c.status === 'ok' ? '✓' : c.status === 'warn' ? '⚠' : '✗';
                        html += `<div class="p-2 rounded bg-${color}-50 border border-${color}-200">`;
                        html += `<p class="text-sm font-mono"><span class="font-bold">${icon}</span> <strong>${c.endpoint}</strong> <span class="text-gray-500">(HTTP ${c.http_status || '—'})</span></p>`;
                        html += `<p class="text-xs text-gray-700 mt-0.5">${c.detail}</p>`;
                        html += '</div>';
                    });
                    html += '</div>';
                    html += `<button type="button" onclick="navigator.clipboard.writeText(this.dataset.report).then(() => { this.textContent = 'Copied!'; setTimeout(() => { this.textContent = 'Copy report to clipboard'; }, 1500); })" data-report="${report.replace(/"/g, '&quot;')}" class="btn btn-sm btn-outline text-xs">Copy report to clipboard</button>`;
                    html += '</div>';

                    if (!valData.valid && valData.error) {
                        html = `<p class="text-red-700 font-medium">${valData.error}</p>`;
                    }

                    errorMsg.innerHTML = html;
                    errorDiv.classList.remove('hidden');
                    return;
                }
            } catch (e) {
                loading.classList.add('hidden');
                errorMsg.textContent = 'Could not validate: ' + (e.message || 'Network error');
                errorDiv.classList.remove('hidden');
                return;
            }

            // Step 2: Fetch galaxias
            loading.querySelector('p').textContent = 'Fetching available galaxias...';
            try {
                const resp = await fetch(`${MOCAMBOS_API}?action=galaxias&api_base=${encodeURIComponent(mocambosApiBase)}`, {
                    headers: { 'X-API-Key': API_KEY }
                });
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.error || 'Failed to fetch galaxias');
                }
                const galaxias = await resp.json();
                loading.classList.add('hidden');

                if (!Array.isArray(galaxias) || galaxias.length === 0) {
                    errorMsg.textContent = 'No galaxias found at this URL.';
                    errorDiv.classList.remove('hidden');
                    return;
                }

                document.getElementById('mocambos-connected-url').textContent = mocambosApiBase;

                galaxias.forEach((g, i) => {
                    const div = document.createElement('label');
                    div.className = 'flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer';
                    div.innerHTML = `
                        <input type="checkbox" class="checkbox checkbox-sm checkbox-primary mocambos-galaxia-cb"
                               data-slug="${g.slug}" data-smid="${g.smid}" data-mucua="${g.mucua_slug}" data-name="${g.name}">
                        <span class="flex-1 text-sm font-medium text-gray-800">${g.name}</span>
                        ${g.imported ? '<span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded">Imported</span>' : ''}
                    `;
                    galaxiasDiv.appendChild(div);
                });

                listDiv.classList.remove('hidden');
                importBtn.classList.remove('hidden');
            } catch (e) {
                loading.classList.add('hidden');
                errorMsg.textContent = e.message || 'Failed to connect to Mocambos API';
                errorDiv.classList.remove('hidden');
            }
        }

        async function doMocambosImport() {
            const checkboxes = document.querySelectorAll('.mocambos-galaxia-cb:checked');
            if (checkboxes.length === 0) {
                showMessage('Please select at least one galaxia to import.', 'error');
                return;
            }

            const selected = [];
            const reimportNames = [];
            checkboxes.forEach(cb => {
                selected.push({
                    galaxia_slug: cb.dataset.slug,
                    galaxia_smid: cb.dataset.smid,
                    mucua_slug: cb.dataset.mucua
                });
                const badge = cb.closest('label').querySelector('.bg-purple-100');
                if (badge) reimportNames.push(cb.dataset.name);
            });

            if (reimportNames.length > 0) {
                const confirmed = confirm(
                    'The following galaxies will be refreshed, replacing all current content including any edits:\n\n' +
                    reimportNames.join('\n') +
                    '\n\nContinue?'
                );
                if (!confirmed) return;
            }

            const importBtn = document.getElementById('mocambos-import-btn');
            const progressDiv = document.getElementById('mocambos-import-progress');
            const resultDiv = document.getElementById('mocambos-import-result');
            const logDiv = document.getElementById('mocambos-log');
            const statusEl = document.getElementById('mocambos-progress-status');

            importBtn.classList.add('hidden');
            progressDiv.classList.remove('hidden');
            resultDiv.classList.add('hidden');
            logDiv.innerHTML = '';

            const colorMap = {
                info: 'text-blue-300',
                success: 'text-green-400',
                error: 'text-red-400',
                warning: 'text-yellow-400',
                node: 'text-purple-300',
                download: 'text-gray-400',
                done: 'text-green-300 font-bold',
            };

            function appendLog(msg, type) {
                const line = document.createElement('div');
                line.className = colorMap[type] || 'text-gray-300';
                line.textContent = msg;
                logDiv.appendChild(line);
                logDiv.scrollTop = logDiv.scrollHeight;
            }

            try {
                const resp = await fetch(`${MOCAMBOS_API}?action=import`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Key': API_KEY
                    },
                    body: JSON.stringify({
                        api_base: mocambosApiBase,
                        galaxias: selected
                    })
                });

                if (!resp.ok && resp.headers.get('content-type')?.includes('application/json')) {
                    const err = await resp.json();
                    throw new Error(err.error || 'Import failed');
                }

                // Read streamed newline-delimited JSON
                const reader = resp.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let finalData = null;

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });

                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // keep incomplete line in buffer

                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const evt = JSON.parse(line);
                            appendLog(evt.message, evt.type);
                            if (evt.type === 'node') {
                                statusEl.textContent = evt.message.replace(/^\(\d+\/\d+\)\s*/, '');
                            } else if (evt.type === 'info' || evt.type === 'success') {
                                statusEl.textContent = evt.message;
                            }
                            if (evt.type === 'done') {
                                finalData = evt;
                            }
                        } catch (e) { /* skip unparseable lines */ }
                    }
                }

                // Process any remaining buffer
                if (buffer.trim()) {
                    try {
                        const evt = JSON.parse(buffer);
                        appendLog(evt.message, evt.type);
                        if (evt.type === 'done') finalData = evt;
                    } catch (e) { /* skip */ }
                }

                // Hide spinner, show summary
                statusEl.textContent = 'Import complete';
                document.querySelector('#mocambos-import-progress .loading')?.classList.add('hidden');

                if (finalData && finalData.results) {
                    let html = '<div class="space-y-2 mt-3">';
                    let hasErrors = false;
                    finalData.results.forEach(r => {
                        const status = r.is_new ? 'New' : 'Refreshed';
                        const countInfo = `${r.imported_count} of ${r.expected_count} items`;
                        const errCount = (r.errors || []).length;
                        html += `<div class="p-2 rounded ${errCount > 0 ? 'bg-yellow-50 border border-yellow-200' : 'bg-green-50 border border-green-200'}">`;
                        html += `<p class="text-sm font-medium">${status}: <strong>${r.galaxia_slug}</strong> — ${countInfo}</p>`;
                        if (errCount > 0) {
                            hasErrors = true;
                            html += `<ul class="text-xs text-red-600 mt-1 list-disc list-inside">`;
                            r.errors.forEach(e => { html += `<li>${e}</li>`; });
                            html += `</ul>`;
                        }
                        html += `</div>`;
                    });
                    html += '</div>';
                    resultDiv.innerHTML = html;
                    resultDiv.classList.remove('hidden');

                    showMessage('Import completed' + (hasErrors ? ' with some errors.' : ' successfully.'), hasErrors ? 'error' : 'success');
                    setTimeout(() => { window.location.reload(); }, 3000);
                }

            } catch (e) {
                appendLog('Error: ' + e.message, 'error');
                statusEl.textContent = 'Import failed';
                document.querySelector('#mocambos-import-progress .loading')?.classList.add('hidden');
                importBtn.classList.remove('hidden');
            }
        }

        async function validateField(type, params) {
            if (!API_KEY) return { valid: true }; // Skip if no API key (shouldn't happen)
            const query = new URLSearchParams({ type, ...params, api_key: API_KEY }).toString();
            try {
                const response = await fetch(`${API_URL}?${query}`);
                return await response.json();
            } catch (e) {
                console.error('Validation failed', e);
                return { valid: true };
            }
        }

        function showMessage(text, type = 'success', title = null) {
            const container = document.getElementById('notification-container');
            if (!container) return;

            const toast = document.createElement('div');
            // Using DaisyUI alert classes for styling
            toast.className = `alert ${type === 'success' ? 'alert-success' : 'alert-error'} shadow-lg mb-2 pointer-events-auto transition-all duration-500 transform -translate-y-4 opacity-0 text-white`;
            
            let content = `<div>`;
            if (title) content += `<h3 class="font-bold text-xs uppercase opacity-80 mb-1">${title}</h3>`;
            content += `<div class="text-sm font-medium">${text}</div></div>`;
            
            toast.innerHTML = content;
            container.appendChild(toast);

            // Trigger animation
            requestAnimationFrame(() => {
                toast.classList.remove('-translate-y-4', 'opacity-0');
            });

            // Auto-remove after 2 seconds
            setTimeout(() => {
                toast.classList.add('-translate-y-4', 'opacity-0');
                setTimeout(() => toast.remove(), 500);
            }, 2000);
        }

        function toggleUserConstellationsSection() {
            const typeSelect = document.getElementById('type');
            const section = document.getElementById('user-constellations-section');
            if (!typeSelect || !section) return;
            const isEditor = typeSelect.value === '1';
            section.classList.toggle('hidden', !isEditor);
        }
        function toggleCreateNewConstellationName() {
            const cb = document.getElementById('create_constellation_cb');
            const wrap = document.getElementById('create-new-constellation-name-wrap');
            if (cb && wrap) wrap.classList.toggle('hidden', !cb.checked);
        }
        function initCreateUserForm() {
            const emailEl = document.getElementById('create-email');
            const nameEl = document.getElementById('create_new_constellation_name');
            const createCb = document.getElementById('create_constellation_cb');
            if (createCb) createCb.addEventListener('change', toggleCreateNewConstellationName);
            if (emailEl && nameEl) {
                emailEl.addEventListener('input', function() {
                    if (nameEl.value === '' || nameEl.getAttribute('data-auto') === '1') {
                        nameEl.value = emailEl.value;
                        nameEl.setAttribute('data-auto', '1');
                    }
                });
                nameEl.addEventListener('input', function() { nameEl.removeAttribute('data-auto'); });
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

            const validateUser = async (emailEl, errorEl, idEl = null) => {
                const email = emailEl.value.trim();
                const form = emailEl.closest('form');
                const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
                if (!email) {
                    errorEl.classList.add('hidden');
                    return;
                }
                const result = await validateField('user', { email, exclude_id: idEl ? idEl.value : null });
                if (result.email) {
                    errorEl.classList.remove('hidden');
                    emailEl.classList.add('border-red-500');
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    errorEl.classList.add('hidden');
                    emailEl.classList.remove('border-red-500');
                    if (submitBtn) {
                        const otherErrors = form.querySelectorAll('.text-red-600:not(.hidden)');
                        if (otherErrors.length === 0) submitBtn.disabled = false;
                    }
                }
            };

            const validateConstellation = async (nameEl, slugEl, nameErrEl, slugErrEl, idEl = null) => {
                const name = nameEl.value.trim();
                const slug = slugEl.value.trim();
                const form = nameEl.closest('form');
                const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
                if (!name && !slug) {
                    nameErrEl.classList.add('hidden');
                    slugErrEl.classList.add('hidden');
                    return;
                }
                const result = await validateField('constellation', { name, slug, exclude_id: idEl ? idEl.value : null });
                let hasError = false;
                if (result.name) {
                    nameErrEl.classList.remove('hidden');
                    nameEl.classList.add('border-red-500');
                    hasError = true;
                } else {
                    nameErrEl.classList.add('hidden');
                    nameEl.classList.remove('border-red-500');
                }
                if (result.slug) {
                    slugErrEl.classList.remove('hidden');
                    slugEl.classList.add('border-red-500');
                    hasError = true;
                } else {
                    slugErrEl.classList.add('hidden');
                    slugEl.classList.remove('border-red-500');
                }
                if (submitBtn) {
                    if (hasError) submitBtn.disabled = true;
                    else {
                        const otherErrors = form.querySelectorAll('.text-red-600:not(.hidden)');
                        if (otherErrors.length === 0) submitBtn.disabled = false;
                    }
                }
            };

            // Create User
            const createEmail = document.getElementById('create-email');
            const createEmailErr = document.getElementById('create-email-error');
            if (createEmail) createEmail.addEventListener('input', debounce(() => validateUser(createEmail, createEmailErr), 500));

            // Edit User
            const modalEmail = document.getElementById('modal-email');
            const modalEmailErr = document.getElementById('modal-email-error');
            const modalUserId = document.getElementById('modal-user-id');
            if (modalEmail) modalEmail.addEventListener('input', debounce(() => validateUser(modalEmail, modalEmailErr, modalUserId), 500));

            // Create Constellation
            const createCName = document.getElementById('create-constellation-name');
            const createCSlug = document.getElementById('create-constellation-slug');
            const createCNameErr = document.getElementById('create-constellation-name-error');
            const createCSlugErr = document.getElementById('create-constellation-slug-error');
            const validateCreateC = debounce(() => validateConstellation(createCName, createCSlug, createCNameErr, createCSlugErr), 500);
            if (createCName) createCName.addEventListener('input', validateCreateC);
            if (createCSlug) createCSlug.addEventListener('input', validateCreateC);

            // Edit Constellation
            const modalCName = document.getElementById('modal-constellation-name');
            const modalCSlug = document.getElementById('modal-constellation-slug');
            const modalCNameErr = document.getElementById('modal-constellation-name-error');
            const modalCSlugErr = document.getElementById('modal-constellation-slug-error');
            const modalCId = document.getElementById('modal-constellation-id');
            const validateModalC = debounce(() => validateConstellation(modalCName, modalCSlug, modalCNameErr, modalCSlugErr, modalCId), 500);
            if (modalCName) modalCName.addEventListener('input', validateModalC);
            if (modalCSlug) modalCSlug.addEventListener('input', validateModalC);

            // Duplicate Constellation
            const dupCName = document.getElementById('duplicate-constellation-name');
            const dupCSlug = document.getElementById('duplicate-constellation-slug');
            const dupCNameErr = document.getElementById('duplicate-constellation-name-error');
            const dupCSlugErr = document.getElementById('duplicate-constellation-slug-error');
            const validateDupC = debounce(() => validateConstellation(dupCName, dupCSlug, dupCNameErr, dupCSlugErr), 500);
            if (dupCName) dupCName.addEventListener('input', validateDupC);
            if (dupCSlug) dupCSlug.addEventListener('input', validateDupC);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const typeSelect = document.getElementById('type');
            if (typeSelect) typeSelect.addEventListener('change', toggleUserConstellationsSection);
            toggleCreateNewConstellationName();
            initCreateUserForm();
            setupLiveValidation();
        });
        function copyConstellationUrl(relativePath, buttonEl) {
            const absoluteUrl = new URL(relativePath, window.location.origin + window.location.pathname).href;
            navigator.clipboard.writeText(absoluteUrl).then(function() {
                const toast = document.getElementById('copy-url-toast');
                if (toast) {
                    toast.classList.remove('hidden');
                    setTimeout(function() { toast.classList.add('hidden'); }, 3000);
                }
                if (buttonEl) {
                    const origTitle = buttonEl.getAttribute('title');
                    buttonEl.setAttribute('title', 'Copied!');
                    setTimeout(function() { buttonEl.setAttribute('title', origTitle || 'Copy galaxy URL'); }, 1500);
                }
            });
        }
        // Settings language sub-tabs (English / Spanish / Portuguese)
        function showSettingsLang(lang) {
            if (!['en', 'es', 'pt'].includes(lang)) lang = 'en';
            ['en', 'es', 'pt'].forEach(l => {
                const panel = document.getElementById('settings-lang-' + l);
                const tabBtn = document.getElementById('settings-lang-tab-' + l);
                if (panel) panel.classList.toggle('hidden', l !== lang);
                if (tabBtn) {
                    if (l === lang) {
                        tabBtn.classList.remove('border-transparent', 'text-gray-500');
                        tabBtn.classList.add('border-blue-500', 'text-blue-600');
                    } else {
                        tabBtn.classList.remove('border-blue-500', 'text-blue-600');
                        tabBtn.classList.add('border-transparent', 'text-gray-500');
                    }
                }
            });
            const langInput = document.getElementById('settings_lang');
            if (langInput) langInput.value = lang;
        }
        
        const userConstellationsMap = <?php echo json_encode($userConstellationsMap); ?>;

        // Pagination State
        const paginationState = {
            users: { currentPage: 1, itemsPerPage: 20 }
        };

        function applyPagination(type) {
            const state = paginationState[type];
            const selector = type === 'users' ? 'tr.user-row' : 'tr.constellation-row';
            const searchId = type === 'users' ? 'search-users' : 'search-constellations';
            const query = document.getElementById(searchId).value.toLowerCase().trim();
            
            const allRows = Array.from(document.querySelectorAll(selector));
            
            // First filter based on search
            const visibleFilteredRows = allRows.filter(row => {
                let text = '';
                if (type === 'users') {
                    text = (row.dataset.name || '') + ' ' + (row.dataset.email || '');
                } else {
                    text = (row.dataset.name || '') + ' ' + (row.dataset.tagline || '') + ' ' + (row.dataset.id || '');
                }
                return text.toLowerCase().includes(query);
            });

            const totalItems = visibleFilteredRows.length;
            const totalPages = Math.ceil(totalItems / state.itemsPerPage);
            
            if (state.currentPage > totalPages && totalPages > 0) state.currentPage = totalPages;
            if (state.currentPage < 1) state.currentPage = 1;

            const start = (state.currentPage - 1) * state.itemsPerPage;
            const end = start + state.itemsPerPage;

            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');

            // Show only rows for current page
            visibleFilteredRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                }
            });

            updatePaginationControls(type, totalPages);
        }

        function updatePaginationControls(type, totalPages) {
            const state = paginationState[type];
            const positions = ['top', 'bottom'];
            
            positions.forEach(pos => {
                const containerId = `${type}-pagination-${pos}`;
                const headerContainerId = `${type}-pagination-header`;
                let container = document.getElementById(containerId);

                if (pos === 'top') {
                    const header = document.getElementById(headerContainerId);
                    if (!header) return;
                    
                    if (totalPages <= 1) {
                        header.innerHTML = '';
                        return;
                    }

                    let html = `<div id="${containerId}" class="flex items-center gap-2">`;
                    html += `<button type="button" onclick="goToPage('${type}', ${state.currentPage - 1})" class="btn btn-xs ${state.currentPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === 1 || i === totalPages || (i >= state.currentPage - 2 && i <= state.currentPage + 2)) {
                            html += `<button type="button" onclick="goToPage('${type}', ${i})" class="btn btn-xs ${i === state.currentPage ? 'btn-neutral' : ''}">${i}</button>`;
                        } else if (i === state.currentPage - 3 || i === state.currentPage + 3) {
                            html += `<span class="px-0.5 text-gray-400">...</span>`;
                        }
                    }
                    html += `<button type="button" onclick="goToPage('${type}', ${state.currentPage + 1})" class="btn btn-xs ${state.currentPage === totalPages ? 'btn-disabled' : ''}">»</button>`;
                    html += `</div>`;
                    header.innerHTML = html;
                } else {
                    // Bottom pagination
                    if (!container) {
                        container = document.createElement('div');
                        container.id = containerId;
                        container.className = `flex justify-center items-center gap-2 mt-6 pb-4`;
                        const tableWrap = document.querySelector(type === 'users' ? '#users-list' : 'table.w-full').parentNode;
                        tableWrap.parentNode.insertBefore(container, tableWrap.nextSibling);
                    }

                    if (totalPages <= 1) {
                        container.innerHTML = '';
                        return;
                    }

                    let html = `<button type="button" onclick="goToPage('${type}', ${state.currentPage - 1})" class="btn btn-sm ${state.currentPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === 1 || i === totalPages || (i >= state.currentPage - 2 && i <= state.currentPage + 2)) {
                            html += `<button type="button" onclick="goToPage('${type}', ${i})" class="btn btn-sm ${i === state.currentPage ? 'btn-neutral' : ''}">${i}</button>`;
                        } else if (i === state.currentPage - 3 || i === state.currentPage + 3) {
                            html += `<span class="px-1 text-gray-400">...</span>`;
                        }
                    }
                    html += `<button type="button" onclick="goToPage('${type}', ${state.currentPage + 1})" class="btn btn-sm ${state.currentPage === totalPages ? 'btn-disabled' : ''}">»</button>`;
                    container.innerHTML = html;
                }
            });
        }

        function goToPage(type, page) {
            paginationState[type].currentPage = page;
            applyPagination(type);
        }

        function toggleModalUserConstellations() {
            const typeSelect = document.getElementById('modal-type');
            const section = document.getElementById('modal-user-constellations-section');
            if (!typeSelect || !section) return;
            section.classList.toggle('hidden', typeSelect.value !== '1');
        }

        function editUser(user) {
            document.getElementById('modal-user-id').value = user.id;
            document.getElementById('modal-firstname').value = user.firstname;
            document.getElementById('modal-lastname').value = user.lastname;
            document.getElementById('modal-email').value = user.email;
            document.getElementById('modal-type').value = user.type;
            document.getElementById('modal-password').value = '';

            // Reset and set checkboxes
            const checkboxes = document.querySelectorAll('.modal-user-constellation-checkbox');
            const userAccess = userConstellationsMap[user.id] || [];
            checkboxes.forEach(cb => {
                cb.checked = userAccess.includes(parseInt(cb.value));
            });

            toggleModalUserConstellations();
            document.getElementById('modal-user-id-badge').textContent = '#' + user.id;
            document.getElementById('user_modal').showModal();
        }

        async function editConstellation(c) {
            document.getElementById('modal-constellation-id').value = c.id;
            document.getElementById('modal-constellation-name').value = c.name;
            document.getElementById('modal-constellation-slug').value = c.slug || '';
            document.getElementById('modal-constellation-tagline').value = c.tagline;
            document.getElementById('modal-constellation-theme').value = c.theme || 'cosmic';
            document.getElementById('modal-constellation-id-badge').textContent = '#' + c.id;
            await loadTourConfigIntoModal(c.id);
            document.getElementById('constellation_modal').showModal();
        }

        async function loadTourConfigIntoModal(constellationId) {
            const enabled = document.getElementById('modal-tour-enabled');
            const section = document.getElementById('modal-tour-section');
            const idleSeconds = document.getElementById('modal-tour-idle-seconds');
            const randomCount = document.getElementById('modal-tour-random-count');
            const defaultDwell = document.getElementById('modal-tour-default-dwell');
            const loop = document.getElementById('modal-tour-loop');
            const keywordsBox = document.getElementById('modal-tour-keywords');

            enabled.checked = false;
            idleSeconds.value = 30;
            randomCount.value = 10;
            defaultDwell.value = 8;
            loop.checked = true;
            keywordsBox.innerHTML = '<span class="text-xs text-gray-400">Loading…</span>';
            document.querySelectorAll('input[name="tour_start_mode"]').forEach(r => r.checked = (r.value === 'manual'));
            document.querySelectorAll('input[name="tour_node_selection"]').forEach(r => r.checked = (r.value === 'all'));
            updateTourFieldVisibility();

            try {
                const r = await fetch(`${CONST_API}?action=tour_config&id=${constellationId}`, {
                    headers: { 'X-API-Key': API_KEY }
                });
                if (!r.ok) throw new Error('Failed to load tour config');
                const cfg = await r.json();

                enabled.checked = !!cfg.tour_enabled;
                idleSeconds.value = cfg.tour_idle_seconds ?? 30;
                randomCount.value = cfg.tour_random_count ?? 10;
                defaultDwell.value = cfg.tour_default_dwell ?? 8;
                loop.checked = !!cfg.tour_loop;
                document.querySelectorAll('input[name="tour_start_mode"]').forEach(r => r.checked = (r.value === cfg.tour_start_mode));
                document.querySelectorAll('input[name="tour_node_selection"]').forEach(r => r.checked = (r.value === cfg.tour_node_selection));

                const selectedKwIds = new Set((cfg.tour_keyword_ids || []).map(Number));
                if (!cfg.available_keywords || cfg.available_keywords.length === 0) {
                    keywordsBox.innerHTML = '<span class="text-xs text-gray-500">No keywords yet for this galaxy.</span>';
                } else {
                    keywordsBox.innerHTML = cfg.available_keywords.map(kw => {
                        const checked = selectedKwIds.has(kw.id) ? 'checked' : '';
                        return `<label class="flex items-center gap-2 py-0.5 cursor-pointer">
                            <input type="checkbox" name="tour_keyword_ids[]" value="${kw.id}" ${checked} class="checkbox checkbox-neutral checkbox-xs">
                            <span class="text-gray-800">${escapeHtmlAdmin(kw.keyword)}</span>
                        </label>`;
                    }).join('');
                }

                document.getElementById('modal-tour-immediate-warning').dataset.hasAudio = cfg.has_audio_nodes ? '1' : '0';
            } catch (e) {
                keywordsBox.innerHTML = '<span class="text-xs text-red-600">Failed to load.</span>';
                document.getElementById('modal-tour-immediate-warning').dataset.hasAudio = '0';
            }
            updateTourFieldVisibility();
        }

        function updateTourFieldVisibility() {
            const enabled = document.getElementById('modal-tour-enabled').checked;
            document.getElementById('modal-tour-section').classList.toggle('hidden', !enabled);
            if (!enabled) return;

            const startMode = document.querySelector('input[name="tour_start_mode"]:checked')?.value || 'manual';
            document.getElementById('modal-tour-idle-row').classList.toggle('hidden', startMode !== 'idle');

            const selection = document.querySelector('input[name="tour_node_selection"]:checked')?.value || 'all';
            document.getElementById('modal-tour-random-row').classList.toggle('hidden', selection !== 'random_n');
            document.getElementById('modal-tour-tagged-row').classList.toggle('hidden', selection !== 'tagged');

            const audioWarn = document.getElementById('modal-tour-immediate-warning');
            const hasAudio = audioWarn.dataset.hasAudio === '1';
            audioWarn.classList.toggle('hidden', !(hasAudio && startMode === 'immediate'));
        }

        document.addEventListener('DOMContentLoaded', () => {
            const enabled = document.getElementById('modal-tour-enabled');
            if (enabled) enabled.addEventListener('change', updateTourFieldVisibility);
            document.querySelectorAll('.tour-start-mode').forEach(r => r.addEventListener('change', updateTourFieldVisibility));
            document.querySelectorAll('.tour-node-selection').forEach(r => r.addEventListener('change', updateTourFieldVisibility));
        });

        function duplicateConstellation(c) {
            document.getElementById('duplicate-source-id').value = c.id;
            document.getElementById('duplicate-constellation-source-name').textContent = c.name;
            document.getElementById('duplicate-constellation-name').value = c.name + ' (Copy)';
            document.getElementById('duplicate-constellation-slug').value = (c.slug ? c.slug + '-copy' : '');
            document.getElementById('duplicate-constellation-tagline').value = c.tagline;
            document.getElementById('duplicate-constellation-id-badge').textContent = '#' + c.id;
            document.getElementById('duplicate_constellation_modal').showModal();
        }

        function refreshImportedConstellation(id, name) {
            const source = constImportSources[id];
            if (!source || !source.api_base || !source.galaxia_slug) {
                showMessage('Missing import source info for this galaxy.', 'error');
                return;
            }

            // Show confirmation step in the modal
            const modal = document.getElementById('mocambos_import_modal');
            const urlStep = document.getElementById('mocambos-url-step');
            const galaxiasList = document.getElementById('mocambos-list');
            const loading = document.getElementById('mocambos-loading');
            const errorDiv = document.getElementById('mocambos-error');
            const progressDiv = document.getElementById('mocambos-import-progress');
            const resultDiv = document.getElementById('mocambos-import-result');
            const importBtn = document.getElementById('mocambos-import-btn');
            const confirmStep = document.getElementById('refresh-confirm-step');
            const confirmInput = document.getElementById('refresh-confirm-input');
            const confirmBtn = document.getElementById('refresh-confirm-btn');
            const confirmName = document.getElementById('refresh-confirm-name');

            // Hide everything, show confirmation step
            urlStep.classList.add('hidden');
            galaxiasList.classList.add('hidden');
            loading.classList.add('hidden');
            errorDiv.classList.add('hidden');
            resultDiv.classList.add('hidden');
            progressDiv.classList.add('hidden');
            if (importBtn) importBtn.classList.add('hidden');

            confirmName.textContent = name;
            confirmInput.value = '';
            confirmBtn.disabled = true;
            confirmInput.oninput = () => {
                confirmBtn.disabled = confirmInput.value.trim() !== name;
            };
            confirmBtn.onclick = () => {
                confirmStep.classList.add('hidden');
                doRefreshImport(id, name);
            };
            confirmStep.classList.remove('hidden');
            modal.showModal();
            confirmInput.focus();
        }

        async function doRefreshImport(id, name) {
            const source = constImportSources[id];
            const modal = document.getElementById('mocambos_import_modal');
            const galaxiasList = document.getElementById('mocambos-list');
            const progressDiv = document.getElementById('mocambos-import-progress');
            const resultDiv = document.getElementById('mocambos-import-result');
            const logDiv = document.getElementById('mocambos-log');
            const statusEl = document.getElementById('mocambos-progress-status');
            const importBtn = document.getElementById('mocambos-import-btn');

            // Show progress
            if (importBtn) importBtn.classList.add('hidden');
            galaxiasList.classList.remove('hidden');
            document.getElementById('mocambos-galaxias').classList.add('hidden');
            galaxiasList.querySelectorAll(':scope > p').forEach(p => p.classList.add('hidden'));
            resultDiv.classList.add('hidden');
            progressDiv.classList.remove('hidden');
            logDiv.innerHTML = '';
            if (statusEl) statusEl.textContent = `Refreshing "${name}"...`;

            const colorMap = {
                info: 'text-blue-300', success: 'text-green-400', error: 'text-red-400',
                warning: 'text-yellow-400', node: 'text-purple-300', download: 'text-gray-400',
                done: 'text-green-300 font-bold',
            };
            function appendLog(msg, type) {
                const line = document.createElement('div');
                line.className = colorMap[type] || 'text-gray-300';
                line.textContent = msg;
                logDiv.appendChild(line);
                logDiv.scrollTop = logDiv.scrollHeight;
            }

            try {
                const resp = await fetch(`${MOCAMBOS_API}?action=import`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
                    body: JSON.stringify({
                        api_base: source.api_base,
                        galaxias: [{
                            galaxia_slug: source.galaxia_slug,
                            galaxia_smid: source.galaxia_smid || '',
                            mucua_slug: source.mucua_slug || ''
                        }]
                    })
                });

                if (!resp.ok && resp.headers.get('content-type')?.includes('application/json')) {
                    const err = await resp.json();
                    throw new Error(err.error || 'Refresh failed');
                }

                const reader = resp.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();
                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const msg = JSON.parse(line);
                            appendLog(msg.message || line, msg.type || 'info');
                        } catch (e) {
                            appendLog(line, 'info');
                        }
                    }
                }
                if (buffer.trim()) {
                    try {
                        const msg = JSON.parse(buffer);
                        appendLog(msg.message || buffer, msg.type || 'info');
                    } catch (e) {
                        appendLog(buffer, 'info');
                    }
                }

                appendLog('Refresh complete.', 'done');
                if (statusEl) statusEl.textContent = 'Refresh complete';
                loadConstellations();
            } catch (e) {
                appendLog('Error: ' + (e.message || 'Unknown error'), 'error');
                if (statusEl) statusEl.textContent = 'Refresh failed';
                resultDiv.innerHTML = '<button type="button" onclick="document.getElementById(\'mocambos_import_modal\').close()" class="btn btn-sm mt-3">Close</button>';
                resultDiv.classList.remove('hidden');
            }
        }

        async function triggerDelete(action, id, message, confirmName = null) {
            document.getElementById('delete-action').value = action;
            document.getElementById('delete-id').value = id;
            document.getElementById('delete-confirm-message').innerHTML = message;
            
            const confirmWrap = document.getElementById('delete-name-confirm-wrap');
            const confirmInput = document.getElementById('delete-confirm-name-input');
            const deleteBtn = document.getElementById('delete-confirm-btn');
            const impactWrap = document.getElementById('delete-impact-wrap');
            
            // Reset impact wrap
            impactWrap.innerHTML = '';
            impactWrap.classList.add('hidden');

            if (confirmName) {
                confirmWrap.classList.remove('hidden');
                confirmInput.value = '';
                confirmInput.setAttribute('data-expected', confirmName);
                deleteBtn.disabled = true;

                // Fetch impact for constellation deletion
                if (action === 'delete_constellation') {
                    try {
                        const response = await fetch(`../api/constellations.php?action=impact&id=${id}`, {
                            headers: { 'X-API-Key': API_KEY }
                        });
                        const data = await response.json();
                        if (data.referencing_portals && data.referencing_portals.length > 0) {
                            let html = `<div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded text-amber-800 text-xs">`;
                            html += `<p class="font-bold mb-2 uppercase tracking-wide">⚠️ Deletion Impact:</p>`;
                            html += `<p class="mb-2">The following portals in other galaxies point to this network and will also be deleted:</p>`;
                            html += `<ul class="list-disc list-inside space-y-1">`;
                            data.referencing_portals.forEach(p => {
                                html += `<li><strong>${p.name}</strong> (in galaxy: ${p.constellation_name})</li>`;
                            });
                            html += `</ul></div>`;
                            impactWrap.innerHTML = html;
                            impactWrap.classList.remove('hidden');
                        }
                    } catch (e) {
                        console.error('Failed to fetch deletion impact', e);
                    }
                }
            } else {
                confirmWrap.classList.add('hidden');
                deleteBtn.disabled = false;
            }
            
            document.getElementById('delete_confirm_modal').showModal();
        }

        function checkDeleteConfirmName(input) {
            const expected = input.getAttribute('data-expected');
            const deleteBtn = document.getElementById('delete-confirm-btn');
            deleteBtn.disabled = (input.value !== expected);
        }

        function toggleCreateUserConstellations() {
            const typeSelect = document.getElementById('create-type');
            const section = document.getElementById('create-user-constellations-section');
            if (!typeSelect || !section) return;
            section.classList.toggle('hidden', typeSelect.value !== '1');
        }

        function toggleCreateNewConstellationName() {
            const cb = document.getElementById('create_constellation_cb');
            const wrap = document.getElementById('create-new-constellation-name-wrap');
            if (cb && wrap) wrap.classList.toggle('hidden', !cb.checked);
        }

        function initCreateUserModalLogic() {
            const emailEl = document.getElementById('create-email');
            const nameEl = document.getElementById('create_new_constellation_name');
            if (emailEl && nameEl) {
                emailEl.addEventListener('input', function() {
                    if (nameEl.value === '' || nameEl.getAttribute('data-auto') === '1') {
                        nameEl.value = emailEl.value;
                        nameEl.setAttribute('data-auto', '1');
                    }
                });
                nameEl.addEventListener('input', function() { nameEl.removeAttribute('data-auto'); });
            }
        }

        // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            document.getElementById('content-api-keys').classList.add('hidden');
            document.getElementById('content-users').classList.add('hidden');
            const contentConstellations = document.getElementById('content-constellations');
            if (contentConstellations) contentConstellations.classList.add('hidden');
            const contentSettings = document.getElementById('content-settings');
            if (contentSettings) contentSettings.classList.add('hidden');
            const contentBackup = document.getElementById('content-backup');
            if (contentBackup) contentBackup.classList.add('hidden');
            const contentSnapshots = document.getElementById('content-snapshots');
            if (contentSnapshots) contentSnapshots.classList.add('hidden');
            document.getElementById('content-php-info').classList.add('hidden');

            // Remove active styling from all tabs
            const tabs = ['api-keys', 'users', 'constellations', 'settings', 'backup', 'snapshots', 'php-info'];
            tabs.forEach(tab => {
                const tabElement = document.getElementById('tab-' + tab);
                if (tabElement) {
                    tabElement.classList.remove('tab-active');
                }
            });
            
            // Show selected tab
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // If Global Settings, restore language sub-tab from URL
            if (tabName === 'settings') {
                const urlParams = new URLSearchParams(window.location.search);
                const lang = urlParams.get('lang');
                if (lang && ['en', 'es', 'pt'].includes(lang)) showSettingsLang(lang);
            }

            // Lazy-load Snapshots tab on first open
            if (tabName === 'snapshots' && typeof snapshotsLoad === 'function') {
                snapshotsLoad();
            }
            
            // Add active styling to selected tab
            const activeTabEl = document.getElementById('tab-' + tabName);
            if (activeTabEl) {
                activeTabEl.classList.add('tab-active');
            }
            
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        // Format all date/time elements in the user's timezone (browser locale)
        function formatLocalDatetimes() {
            document.querySelectorAll('.local-datetime').forEach(function(span) {
                const iso = span.getAttribute('data-datetime-iso');
                if (iso) {
                    try {
                        const d = new Date(iso);
                        const yy = d.getFullYear().toString().slice(-2);
                        const mm = (d.getMonth() + 1).toString().padStart(2, '0');
                        const dd = d.getDate().toString().padStart(2, '0');
                        const hh = d.getHours().toString().padStart(2, '0');
                        const min = d.getMinutes().toString().padStart(2, '0');
                        span.textContent = `${yy}-${mm}-${dd} ${hh}:${min}`;
                    } catch (e) {}
                }
            });
        }

        // Initialize tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const tab = new URLSearchParams(window.location.search).get('tab') || 'constellations';
            showTab(tab);
            formatLocalDatetimes();
            initCreateUserModalLogic();
            toggleCreateUserConstellations();
            toggleCreateNewConstellationName();

            // Toastify any PHP-rendered messages
            document.querySelectorAll('#php-messages > div').forEach(msg => {
                showMessage(msg.innerHTML, msg.dataset.type, msg.dataset.title);
            });

            // Clean up the URL to prevent messages from reappearing on refresh or subsequent actions
            const url = new URL(window.location);
            let urlChanged = false;
            if (url.searchParams.has('saved')) { url.searchParams.delete('saved'); urlChanged = true; }
            if (url.searchParams.has('generated')) { url.searchParams.delete('generated'); urlChanged = true; }
            if (urlChanged) {
                window.history.replaceState({}, '', url);
            }

            // Initial pagination
            applyPagination('users');
            loadConstellations();

            // Hide loading overlay
            const overlay = document.getElementById('admin-loading-overlay');
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(() => overlay.remove(), 300);
            }
        });

        function copyConstellationUrl(relativePath, buttonEl) {
            const absoluteUrl = new URL(relativePath, window.location.origin + window.location.pathname).href;
            navigator.clipboard.writeText(absoluteUrl).then(function() {
                const toast = document.getElementById('copy-url-toast');
                if (toast) {
                    toast.classList.remove('hidden');
                    setTimeout(function() { toast.classList.add('hidden'); }, 3000);
                }
                if (buttonEl) {
                    const origTitle = buttonEl.getAttribute('title');
                    buttonEl.setAttribute('title', 'Copied!');
                    setTimeout(function() { buttonEl.setAttribute('title', origTitle || 'Copy galaxy URL'); }, 1500);
                }
            });
        }

        // Settings language sub-tabs (English / Spanish / Portuguese)
        function showSettingsLang(lang) {
            if (!['en', 'es', 'pt'].includes(lang)) lang = 'en';
            ['en', 'es', 'pt'].forEach(l => {
                const panel = document.getElementById('settings-lang-' + l);
                const tabBtn = document.getElementById('settings-lang-tab-' + l);
                if (panel) panel.classList.toggle('hidden', l !== lang);
                if (tabBtn) {
                    if (l === lang) {
                        tabBtn.classList.remove('border-transparent', 'text-gray-500');
                        tabBtn.classList.add('border-blue-500', 'text-blue-600');
                    } else {
                        tabBtn.classList.remove('border-blue-500', 'text-blue-600');
                        tabBtn.classList.add('border-transparent', 'text-gray-500');
                    }
                }
            });
            const langInput = document.getElementById('settings_lang');
            if (langInput) langInput.value = lang;
        }
        
        function copyApiKey() {
            const input = document.getElementById('new-api-key');
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
            
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            button.classList.add('btn-success');
            button.classList.remove('btn-neutral');
            
            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('btn-success');
                button.classList.add('btn-neutral');
            }, 2000);
        }
        
        // User list sorting
        let currentUserSortColumn = null;
        let currentUserSortOrder = 'asc';
        
        function sortUsersByColumn(column) {
            if (currentUserSortColumn === column) {
                // Toggle order if clicking same column
                currentUserSortOrder = currentUserSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                // New column, default to ascending
                currentUserSortColumn = column;
                currentUserSortOrder = 'asc';
            }
            updateUserSortIndicators();
            applyUserSorting();
        }
        
        function updateUserSortIndicators() {
            // Reset all indicators
            ['name', 'email', 'type', 'date_created', 'date_last_login', 'updated_at'].forEach(col => {
                const indicator = document.getElementById('sort-indicator-' + col);
                if (indicator) {
                    indicator.innerHTML = '';
                }
            });
            
            // Set indicator for current sort column
            if (currentUserSortColumn) {
                const indicator = document.getElementById('sort-indicator-' + currentUserSortColumn);
                if (indicator) {
                    indicator.innerHTML = currentUserSortOrder === 'asc' ? ' ↑' : ' ↓';
                }
            }
        }
        
        function applyUserSorting() {
            const usersTable = document.getElementById('users-list');
            if (!usersTable) {
                return;
            }
            const tbody = usersTable.querySelector('tbody');
            if (!tbody) {
                return;
            }
            
            const userRows = Array.from(tbody.querySelectorAll('tr.user-row'));
            if (userRows.length === 0) {
                return;
            }
            
            // Sort user rows
            const sortedRows = userRows.sort((a, b) => {
                let aVal, bVal;
                
                switch(currentUserSortColumn) {
                    case 'name':
                        aVal = a.dataset.name || '';
                        bVal = b.dataset.name || '';
                        break;
                    case 'email':
                        aVal = a.dataset.email || '';
                        bVal = b.dataset.email || '';
                        break;
                    case 'type':
                        aVal = parseInt(a.dataset.type) || 0;
                        bVal = parseInt(b.dataset.type) || 0;
                        break;
                    case 'date_created':
                        aVal = parseInt(a.dataset.dateCreated) || 0;
                        bVal = parseInt(b.dataset.dateCreated) || 0;
                        break;
                    case 'date_last_login':
                        aVal = parseInt(a.dataset.dateLastLogin) || 0;
                        bVal = parseInt(b.dataset.dateLastLogin) || 0;
                        break;
                    case 'updated_at':
                        aVal = parseInt(a.dataset.updatedAt) || 0;
                        bVal = parseInt(b.dataset.updatedAt) || 0;
                        break;
                    default:
                        return 0;
                }
                
                if (aVal < bVal) return currentUserSortOrder === 'asc' ? -1 : 1;
                if (aVal > bVal) return currentUserSortOrder === 'asc' ? 1 : -1;
                return 0;
            });
            
            // Re-append sorted rows to tbody
            sortedRows.forEach(row => {
                tbody.appendChild(row);
            });

            applyPagination('users');
        }

        // --- Constellations: server-side pagination ---
        const CONST_API = '../api/constellations.php';
        let constPage = 1;
        const constPerPage = 20;
        let constSortColumn = null;
        let constSortOrder = 'asc';
        let constFilter = '';
        let constTotalPages = 0;

        const constImportSources = {}; // id → import_source object
        const pastelPalette = ['#FEF2F2','#F0FAF0','#EFF6FF','#FFF8F0','#F8F5FF','#F0FDFA','#FEFEF0','#FFF5F5','#F5F5F7','#F5FAE8'];
        const groupColorMap = {};
        let groupColorIdx = 0;

        function getGroupColor(name) {
            const m = name.match(/^\[([^\]]+)\]/);
            if (!m) return '';
            const g = m[1];
            if (!groupColorMap[g]) {
                groupColorMap[g] = pastelPalette[groupColorIdx % pastelPalette.length];
                groupColorIdx++;
            }
            return groupColorMap[g];
        }

        function escapeHtmlAdmin(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        const debouncedConstSearch = (() => {
            let timer;
            return () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    constFilter = document.getElementById('search-constellations').value.trim();
                    constPage = 1;
                    loadConstellations();
                }, 300);
            };
        })();

        function sortConstellationsByColumn(column) {
            if (constSortColumn === column) {
                constSortOrder = constSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                constSortColumn = column;
                constSortOrder = 'asc';
            }
            constPage = 1;
            updateConstellationSortIndicators();
            loadConstellations();
        }

        function updateConstellationSortIndicators() {
            ['id', 'name', 'slug', 'tagline', 'node_count', 'created_at', 'updated_at'].forEach(col => {
                const indicator = document.getElementById('sort-indicator-const-' + col);
                if (indicator) indicator.innerHTML = '';
            });
            if (constSortColumn) {
                const indicator = document.getElementById('sort-indicator-const-' + constSortColumn);
                if (indicator) indicator.innerHTML = constSortOrder === 'asc' ? ' ↑' : ' ↓';
            }
        }

        function constGoToPage(page) {
            if (page < 1 || page > constTotalPages) return;
            constPage = page;
            loadConstellations();
        }

        function updateConstPagination() {
            const headerContainer = document.getElementById('constellations-pagination-header');
            if (headerContainer) headerContainer.innerHTML = '';

            const oldBottom = document.getElementById('constellations-pagination-bottom');
            if (oldBottom) oldBottom.remove();

            if (constTotalPages <= 1) return;

            const createHTML = (isTop) => {
                let html = `<div id="constellations-pagination-${isTop ? 'top' : 'bottom'}" class="flex items-center gap-2 ${isTop ? '' : 'mt-6 pb-4 flex justify-center'}">`;
                html += `<button type="button" onclick="constGoToPage(${constPage - 1})" class="btn btn-xs ${constPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                for (let i = 1; i <= constTotalPages; i++) {
                    if (i === 1 || i === constTotalPages || (i >= constPage - 2 && i <= constPage + 2)) {
                        html += `<button type="button" onclick="constGoToPage(${i})" class="btn btn-xs ${i === constPage ? 'btn-neutral' : ''}">${i}</button>`;
                    } else if (i === constPage - 3 || i === constPage + 3) {
                        html += `<span class="px-0.5 text-gray-400">...</span>`;
                    }
                }
                html += `<button type="button" onclick="constGoToPage(${constPage + 1})" class="btn btn-xs ${constPage === constTotalPages ? 'btn-disabled' : ''}">»</button>`;
                html += `</div>`;
                return html;
            };

            if (headerContainer) headerContainer.innerHTML = createHTML(true);

            const container = document.getElementById('constellations-list-container');
            if (container) {
                const bottom = document.createElement('div');
                bottom.id = 'constellations-pagination-bottom';
                bottom.innerHTML = createHTML(false);
                container.appendChild(bottom);
            }
        }

        async function loadConstellations() {
            const container = document.getElementById('constellations-list-container');
            if (!container) return;

            const params = new URLSearchParams();
            params.set('page', constPage);
            params.set('per_page', constPerPage);
            if (constSortColumn) {
                params.set('sort', constSortColumn);
                params.set('order', constSortOrder);
            }
            if (constFilter) params.set('filter', constFilter);

            try {
                const response = await fetch(CONST_API + '?' + params.toString(), {
                    headers: { 'X-API-Key': API_KEY }
                });
                if (!response.ok) throw new Error('Failed to load constellations');
                const result = await response.json();

                const constellations = result.constellations;
                constellations.forEach(c => {
                    if (c.import_source) constImportSources[c.id] = c.import_source;
                });
                const total = result.total;
                constTotalPages = Math.ceil(total / constPerPage);

                if (constPage > constTotalPages && constTotalPages > 0) {
                    constPage = constTotalPages;
                    return loadConstellations();
                }

                const countEl = document.getElementById('constellations-count');
                if (countEl) countEl.textContent = total;

                if (constellations.length === 0) {
                    container.innerHTML = '<p class="text-gray-600 py-4">No galaxies found.</p>';
                    updateConstPagination();
                    return;
                }

                let html = `<div class="border border-gray-300 rounded">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="border-b-2 border-gray-400 bg-gray-100">
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('id')">ID<span id="sort-indicator-const-id"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('name')">Name<span id="sort-indicator-const-name"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('slug')">Slug<span id="sort-indicator-const-slug"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('tagline')">Tagline<span id="sort-indicator-const-tagline"></span></span>
                                </th>
                                <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('node_count')">Wormholes<span id="sort-indicator-const-node_count"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('created_at')">Created<span id="sort-indicator-const-created_at"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('updated_at')">Last Updated<span id="sort-indicator-const-updated_at"></span></span>
                                </th>
                                <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>`;

                constellations.forEach(c => {
                    const bgColor = getGroupColor(c.name);
                    const bgStyle = bgColor ? ` style="background-color: ${bgColor}"` : '';
                    const hoverClass = bgColor ? '' : ' hover:bg-gray-50';
                    const slug = c.slug || '';
                    const viewRel = c.is_default ? '../index.php' : (slug ? '../' + encodeURIComponent(slug) : '../index.php?constellation_id=' + c.id);
                    const cJson = JSON.stringify({ id: c.id, name: c.name, tagline: c.tagline, slug: slug, theme: c.theme });
                    const cJsonAttr = escapeHtmlAdmin(cJson);
                    const clickEdit = `editConstellation(${cJsonAttr})`;

                    const createdAt = c.created_at ? new Date(c.created_at) : null;
                    const updatedAt = c.updated_at ? new Date(c.updated_at) : null;
                    const fmtDate = (d) => d ? `${d.getFullYear().toString().slice(-2)}-${(d.getMonth()+1).toString().padStart(2,'0')}-${d.getDate().toString().padStart(2,'0')} ${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}` : '—';

                    const delMsg = JSON.stringify(`Are you sure you want to delete the galaxy "${c.name}"? This will permanently remove ALL wormholes and keywords inside it.`);
                    const cNameJson = JSON.stringify(c.name);

                    html += `<tr class="constellation-row border-b border-gray-300${hoverClass}"${bgStyle}>
                        <td class="py-2 px-2 font-mono text-gray-800 cursor-pointer whitespace-nowrap" onclick="${clickEdit}">${c.id}</td>
                        <td class="py-2 px-2 font-semibold text-gray-800 cursor-pointer" onclick="${clickEdit}">
                            ${escapeHtmlAdmin(c.name)}
                            ${c.is_default ? '<span class="ml-2 text-xs bg-green-400 text-white px-1.5 py-0.5 rounded">Default</span>' : ''}
                            ${c.import_source ? '<span class="ml-2 text-xs bg-purple-400 text-white px-1.5 py-0.5 rounded">Imported</span>' : ''}
                        </td>
                        <td class="py-2 px-2 font-mono text-xs text-blue-600 cursor-pointer" onclick="${clickEdit}">${escapeHtmlAdmin(slug)}</td>
                        <td class="py-2 px-2 text-gray-600 text-sm max-w-xs truncate cursor-pointer" onclick="${clickEdit}" title="${escapeHtmlAdmin(c.tagline)}">${escapeHtmlAdmin(c.tagline)}</td>
                        <td class="py-2 px-2 text-right whitespace-nowrap">
                            <a href="../edit/?constellation_id=${c.id}" class="text-blue-600 hover:text-blue-800 hover:underline text-sm font-medium">${c.node_count}</a>
                        </td>
                        <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="${clickEdit}">${fmtDate(createdAt)}</td>
                        <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="${clickEdit}">${fmtDate(updatedAt)}</td>
                        <td class="py-2 px-2 text-right">
                            <div class="flex justify-end">
                                <div class="dropdown dropdown-end">
                                    <label tabindex="0" onclick="event.stopPropagation(); closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                    </label>
                                    <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-40">
                                        <li><a onclick="event.stopPropagation(); editConstellation(${cJsonAttr})" class="text-gray-700 text-xs">Edit</a></li>
                                        <li><a href="${escapeHtmlAdmin(viewRel)}" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="text-gray-700 text-xs">View</a></li>
                                        <li><a onclick="event.stopPropagation(); copyConstellationUrl('${escapeHtmlAdmin(viewRel)}', this)" class="text-gray-700 text-xs">Copy URL</a></li>
                                        <li><a onclick="event.stopPropagation(); duplicateConstellation(${cJsonAttr})" class="text-gray-700 text-xs">Duplicate</a></li>
                                        ${c.import_source ? `<li><a onclick="event.stopPropagation(); refreshImportedConstellation(${c.id}, ${escapeHtmlAdmin(cNameJson)})" class="text-purple-600 text-xs">Refresh</a></li>` : ''}
                                        ${!c.is_default ? `<li><a onclick="event.stopPropagation(); triggerDelete('delete_constellation', '${c.id}', ${escapeHtmlAdmin(delMsg)}, ${escapeHtmlAdmin(cNameJson)})" class="text-red-600 text-xs">Delete</a></li>` : ''}
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>`;
                });

                html += `</tbody></table></div>`;
                container.innerHTML = html;

                updateConstPagination();
                updateConstellationSortIndicators();
                formatLocalDatetimes();
            } catch (e) {
                container.innerHTML = '<p class="text-red-600">Error loading galaxies: ' + escapeHtmlAdmin(e.message) + '</p>';
            }
        }

        function applyUserSearch() {
            paginationState.users.currentPage = 1;
            applyPagination('users');
        }

        // ====================================================================
        // Backup tab
        // ====================================================================

        let backupGalaxiesCache = null;
        let backupImportTempId = null;
        let backupImportSummary = null;

        async function backupLoadGalaxiesForExport() {
            if (backupGalaxiesCache) return backupGalaxiesCache;
            try {
                // Paginate through the constellations API (per_page is capped at 100 server-side)
                const all = [];
                let page = 1;
                while (true) {
                    const r = await fetch(CONST_API + '?page=' + page + '&per_page=100', { headers: { 'X-API-Key': API_KEY } });
                    if (!r.ok) throw new Error('Failed to load galaxies');
                    const data = await r.json();
                    const rows = data.constellations || [];
                    rows.forEach(c => all.push({
                        id: c.id, name: c.name, slug: c.slug, node_count: c.node_count || 0,
                    }));
                    const total = data.total || 0;
                    if (all.length >= total || rows.length === 0) break;
                    page++;
                    if (page > 200) break; // hard safety cap
                }
                backupGalaxiesCache = all;
                return backupGalaxiesCache;
            } catch (e) {
                showMessage('Failed to load galaxies: ' + escapeHtmlAdmin(e.message), 'error');
                return [];
            }
        }

        function backupGetPrefix(name) {
            const m = (name || '').match(/^\s*\[([^\]]{1,16})\]/);
            return m ? m[1] : null;
        }

        function backupRenderPrefixChips(galaxies, container, onChipClick) {
            if (!container) return;
            const counts = new Map();
            let noPrefix = 0;
            galaxies.forEach(g => {
                const p = backupGetPrefix(g.name);
                if (p === null) noPrefix++;
                else counts.set(p, (counts.get(p) || 0) + 1);
            });
            const chips = [];
            Array.from(counts.entries()).sort((a, b) => a[0].localeCompare(b[0])).forEach(([p, n]) => {
                chips.push(`<button type="button" data-prefix="${escapeHtmlAdmin(p)}" class="px-2 py-1 text-xs bg-blue-100 hover:bg-blue-200 text-blue-800 rounded">[${escapeHtmlAdmin(p)}] (${n})</button>`);
            });
            if (noPrefix > 0) {
                chips.push(`<button type="button" data-prefix="" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded">No prefix (${noPrefix})</button>`);
            }
            container.innerHTML = chips.join('');
            container.querySelectorAll('button[data-prefix]').forEach(btn => {
                btn.addEventListener('click', () => onChipClick(btn.getAttribute('data-prefix')));
            });
        }

        async function backupRenderExportGalaxyList() {
            const galaxies = await backupLoadGalaxiesForExport();
            const list = document.getElementById('export-galaxy-list');
            if (!list) return;
            if (galaxies.length === 0) {
                list.innerHTML = '<p class="text-xs text-gray-500 p-3">No galaxies found.</p>';
                return;
            }
            list.innerHTML = galaxies.map(g => `
                <label class="flex items-center gap-2 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="galaxy_ids[]" value="${g.id}" data-name="${escapeHtmlAdmin(g.name)}" class="checkbox checkbox-sm">
                    <span class="flex-1 text-sm">${escapeHtmlAdmin(g.name)}</span>
                    <span class="text-xs text-gray-500">${g.node_count} wormholes</span>
                </label>
            `).join('');
            backupRenderPrefixChips(galaxies, document.getElementById('export-prefix-chips'), (prefix) => {
                const matching = Array.from(list.querySelectorAll('input[type="checkbox"]')).filter(cb => {
                    const p = backupGetPrefix(cb.getAttribute('data-name') || '');
                    return prefix === '' ? p === null : p === prefix;
                });
                const allChecked = matching.length > 0 && matching.every(cb => cb.checked);
                matching.forEach(cb => { cb.checked = !allChecked; });
            });
        }

        function exportGalaxiesSelectAll(state) {
            document.querySelectorAll('#export-galaxy-list input[type="checkbox"]').forEach(cb => cb.checked = state);
        }

        document.addEventListener('change', (e) => {
            if (e.target.matches('input[name="galaxy_scope"]')) {
                const picker = document.getElementById('export-galaxy-picker');
                if (picker) picker.classList.toggle('hidden', e.target.value !== 'selected');
                if (e.target.value === 'selected') backupRenderExportGalaxyList();
            }
            if (e.target.id === 'export-include-galaxies') {
                document.getElementById('export-galaxies-options')?.classList.toggle('opacity-50', !e.target.checked);
                document.querySelectorAll('#export-galaxies-options input').forEach(el => el.disabled = !e.target.checked);
            }
        });

        function backupFormatBytes(b) {
            if (b > 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
            if (b > 1048576) return (b / 1048576).toFixed(1) + ' MB';
            if (b > 1024) return (b / 1024).toFixed(1) + ' KB';
            return b + ' B';
        }

        function backupOnFilePicked() {
            const fileEl = document.getElementById('backup-import-file');
            const info = document.getElementById('backup-import-file-info');
            if (!fileEl.files || fileEl.files.length === 0) {
                info.classList.add('hidden');
                return;
            }
            const f = fileEl.files[0];
            info.textContent = `Selected: ${f.name} (${backupFormatBytes(f.size)})`;
            info.classList.remove('hidden');
            // Hide any previous results
            document.getElementById('backup-import-summary').classList.add('hidden');
            document.getElementById('backup-import-options').classList.add('hidden');
            document.getElementById('backup-import-result').classList.add('hidden');
            document.getElementById('backup-import-status').classList.add('hidden');
        }

        function backupSetStatus(text, opts) {
            opts = opts || {};
            const wrap = document.getElementById('backup-import-status');
            const txt = document.getElementById('backup-import-status-text');
            const progWrap = document.getElementById('backup-import-progress-wrap');
            const bar = document.getElementById('backup-import-progress-bar');
            wrap.classList.remove('hidden');
            txt.innerHTML = text;
            if (typeof opts.progress === 'number') {
                progWrap.classList.remove('hidden');
                bar.style.width = Math.max(0, Math.min(100, opts.progress)) + '%';
                bar.classList.remove('bg-red-500', 'bg-green-500');
                bar.classList.add(opts.progress >= 100 ? 'bg-blue-500' : 'bg-blue-500');
            } else {
                progWrap.classList.add('hidden');
            }
            if (opts.error) {
                txt.classList.add('text-red-700');
                txt.classList.remove('text-gray-700');
            } else {
                txt.classList.add('text-gray-700');
                txt.classList.remove('text-red-700');
            }
        }

        async function backupInspect() {
            const fileEl = document.getElementById('backup-import-file');
            if (!fileEl.files || fileEl.files.length === 0) {
                showMessage('Choose a backup file first.', 'error');
                return;
            }
            const file = fileEl.files[0];
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('backup_file', file);
            const btn = document.getElementById('backup-import-inspect-btn');
            btn.disabled = true;
            btn.textContent = 'Inspecting...';
            backupSetStatus(`Preparing upload of <strong>${escapeHtmlAdmin(file.name)}</strong> (${backupFormatBytes(file.size)})...`, { progress: 0 });

            const t0 = Date.now();
            try {
                const data = await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'backup/import.php?phase=inspect');
                    let parseTimer = null;
                    let dots = 0;
                    xhr.upload.onprogress = (e) => {
                        if (!e.lengthComputable) return;
                        const pct = (e.loaded / e.total) * 100;
                        const speed = (e.loaded / Math.max(1, (Date.now() - t0) / 1000));
                        if (pct < 100) {
                            backupSetStatus(
                                `Uploading: ${pct.toFixed(0)}% &middot; ${backupFormatBytes(e.loaded)} / ${backupFormatBytes(e.total)} &middot; ${backupFormatBytes(speed)}/s`,
                                { progress: pct }
                            );
                        }
                    };
                    xhr.upload.onload = () => {
                        backupSetStatus(
                            `Upload complete (${backupFormatBytes(file.size)}). Server is now parsing the backup, decoding gzip, and validating media checksums. For large files this can take 10-30 seconds.`,
                            { progress: 100 }
                        );
                        // Animate dots so the user sees the page is alive
                        parseTimer = setInterval(() => {
                            dots = (dots + 1) % 4;
                            const el = document.getElementById('backup-import-status-text');
                            if (el && el.dataset.parsing === '1') {
                                el.querySelector('.parse-dots').textContent = '.'.repeat(dots);
                            }
                        }, 400);
                        const txt = document.getElementById('backup-import-status-text');
                        txt.dataset.parsing = '1';
                        txt.innerHTML += ' <span class="parse-dots text-blue-600 font-bold"></span>';
                    };
                    xhr.onload = () => {
                        if (parseTimer) clearInterval(parseTimer);
                        if (xhr.status >= 200 && xhr.status < 300) {
                            try { resolve(JSON.parse(xhr.responseText)); }
                            catch (e) { reject(new Error('Invalid JSON in server response')); }
                        } else {
                            try {
                                const err = JSON.parse(xhr.responseText);
                                reject(new Error(err.error || ('HTTP ' + xhr.status)));
                            } catch (e) {
                                reject(new Error('HTTP ' + xhr.status + ': ' + (xhr.responseText.slice(0, 200) || 'unknown error')));
                            }
                        }
                    };
                    xhr.onerror = () => { if (parseTimer) clearInterval(parseTimer); reject(new Error('Network error during upload (check file size limits)')); };
                    xhr.ontimeout = () => { if (parseTimer) clearInterval(parseTimer); reject(new Error('Request timed out')); };
                    xhr.send(fd);
                });
                if (!data.ok) throw new Error(data.error || 'Inspection failed');
                backupImportTempId = data.temp_id;
                backupImportSummary = data.summary;
                backupRenderImportSummary(data.summary);
                backupRenderImportGalaxyList(data.summary.galaxies || []);
                document.getElementById('backup-import-summary').classList.remove('hidden');
                document.getElementById('backup-import-options').classList.remove('hidden');
                document.getElementById('backup-import-result').classList.add('hidden');
                const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
                backupSetStatus(`Done in ${elapsed}s. Review the summary below, choose your options, then Restore.`, {});
            } catch (e) {
                backupSetStatus('Failed: ' + escapeHtmlAdmin(e.message), { error: true });
                showMessage('Inspect failed: ' + escapeHtmlAdmin(e.message), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Inspect file';
            }
        }

        function backupRenderImportSummary(s) {
            const el = document.getElementById('backup-import-summary');
            const mb = (s.media_bytes || 0) / 1048576;
            const adminWarn = !s.has_admin_user && s.user_count > 0 ? ' <span class="text-red-700 font-semibold">(no admin user!)</span>' : '';
            el.innerHTML = `
                <div class="font-semibold mb-1">Backup file summary</div>
                <div>Format v${s.format_version} · App ${escapeHtmlAdmin(s.app_version)} · Created ${escapeHtmlAdmin(s.created_at)}</div>
                <div>Galaxies: ${s.galaxy_count} · Wormholes: ${s.node_count} · Keywords: ${s.keyword_count}</div>
                <div>Users: ${s.user_count}${adminWarn} · Media: ${s.media_blob_count} files (${mb.toFixed(1)} MB)</div>
            `;
        }

        function backupRenderImportGalaxyList(galaxies) {
            const list = document.getElementById('import-galaxy-list');
            if (!list) return;
            if (galaxies.length === 0) {
                list.innerHTML = '<p class="text-xs text-gray-500 p-3">No galaxies in this backup.</p>';
                document.getElementById('import-prefix-chips').innerHTML = '';
                return;
            }
            list.innerHTML = galaxies.map(g => `
                <label class="flex items-center gap-2 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" data-ref="${escapeHtmlAdmin(g.ref)}" data-name="${escapeHtmlAdmin(g.name)}" checked class="checkbox checkbox-sm import-galaxy-cb">
                    <span class="flex-1 text-sm">${escapeHtmlAdmin(g.name)}${g.is_default ? ' <span class="text-xs text-purple-600">(default)</span>' : ''}</span>
                    <span class="text-xs text-gray-500">${g.node_count} wormholes</span>
                </label>
            `).join('');
            backupRenderPrefixChips(galaxies, document.getElementById('import-prefix-chips'), (prefix) => {
                const matching = Array.from(list.querySelectorAll('input.import-galaxy-cb')).filter(cb => {
                    const p = backupGetPrefix(cb.getAttribute('data-name') || '');
                    return prefix === '' ? p === null : p === prefix;
                });
                const allChecked = matching.length > 0 && matching.every(cb => cb.checked);
                matching.forEach(cb => { cb.checked = !allChecked; });
            });
        }

        function importGalaxiesSelectAll(state) {
            document.querySelectorAll('#import-galaxy-list input.import-galaxy-cb').forEach(cb => cb.checked = state);
        }

        async function backupCommit() {
            if (!backupImportTempId) {
                showMessage('Inspect a file first.', 'error');
                return;
            }
            const conflict = document.querySelector('input[name="import_conflict"]:checked')?.value || 'overwrite';
            const renameSuffix = document.getElementById('import-rename-suffix')?.value || ' (restored)';
            const restoreUsers = document.getElementById('import-restore-users')?.checked ?? true;
            const usersMode = document.querySelector('input[name="import_users_mode"]:checked')?.value || 'skip';
            const usersReplacePw = document.getElementById('import-users-replace-pw')?.checked ?? false;
            const restoreMedia = document.getElementById('import-restore-media')?.checked ?? true;

            const galaxiesOpts = {};
            let selectedCount = 0;
            document.querySelectorAll('#import-galaxy-list input.import-galaxy-cb').forEach(cb => {
                const ref = cb.getAttribute('data-ref');
                if (!ref) return;
                if (cb.checked) {
                    galaxiesOpts[ref] = { include: true, conflict, rename_suffix: renameSuffix };
                    selectedCount++;
                }
            });

            const userCount = (backupImportSummary?.user_count || 0);
            if (selectedCount === 0 && (!restoreUsers || userCount === 0)) {
                showMessage('Nothing selected to restore.', 'error');
                return;
            }
            const proceed = confirm(`Restore ${selectedCount} galaxy/galaxies` + (restoreUsers ? ` and up to ${userCount} user(s)` : '') + ` into this system?\n\nConflict mode: ${conflict.toUpperCase()}\n\nThis cannot be undone.`);
            if (!proceed) return;

            const payload = {
                csrf_token: CSRF_TOKEN,
                temp_id: backupImportTempId,
                confirm: true,
                mode: 'granular',
                restore_users: restoreUsers,
                restore_media: restoreMedia,
                users_replace_existing: usersMode === 'replace',
                users_replace_password: usersMode === 'replace' && usersReplacePw,
                rename_suffix_default: renameSuffix,
                galaxies: galaxiesOpts,
            };
            try {
                const r = await fetch('backup/import.php?phase=commit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify(payload),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Restore failed');
                const rep = data.report;
                const failedHtml = (rep.galaxies_failed && rep.galaxies_failed.length)
                    ? '<div class="mt-2 text-red-700">Failures:<ul class="list-disc ml-6">' + rep.galaxies_failed.map(f => `<li>${escapeHtmlAdmin(f.name || f.ref)}: ${escapeHtmlAdmin(f.error)}</li>`).join('') + '</ul></div>'
                    : '';
                const el = document.getElementById('backup-import-result');
                el.innerHTML = `
                    <div class="font-semibold mb-1">Restore complete</div>
                    <div>Galaxies: created ${rep.galaxies_created}, overwritten ${rep.galaxies_overwritten}, renamed ${rep.galaxies_renamed}, skipped ${rep.galaxies_skipped}</div>
                    <div>Users: created ${rep.users_created}, updated ${rep.users_updated}, skipped ${rep.users_skipped}</div>
                    <div>Media files: written ${rep.media_files_written}, skipped ${rep.media_files_skipped}</div>
                    ${failedHtml}
                `;
                el.classList.remove('hidden');
                backupImportTempId = null;
                showMessage('Restore complete.', 'success');
            } catch (e) {
                showMessage('Restore failed: ' + escapeHtmlAdmin(e.message), 'error');
            }
        }

        // ====================================================================
        // Snapshots tab
        // ====================================================================

        async function snapshotsLoad() {
            const wrap = document.getElementById('snapshots-table-wrap');
            try {
                const r = await fetch('snapshots/list.php');
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Failed to load snapshots');
                snapshotsRenderScheduler(data.schedule, data.cron);
                snapshotsRenderTable(data.snapshots || []);
            } catch (e) {
                wrap.innerHTML = '<p class="text-red-600">' + escapeHtmlAdmin(e.message) + '</p>';
            }
        }

        function snapshotsRenderScheduler(s, c) {
            if (!s) return;
            document.getElementById('schedule-enabled').checked = !!s.enabled;
            document.getElementById('schedule-hour').value = (s.hour ?? 3);
            document.getElementById('schedule-keep-days').value = (s.keep_days ?? 7);

            document.getElementById('scheduler-last-run').textContent = s.last_run_at ? (s.last_run_at + ' UTC') : 'never';
            document.getElementById('scheduler-last-check').textContent = (c && c.log_mtime) ? c.log_mtime : 'never';

            const badge = document.getElementById('scheduler-status-badge');
            const detail = document.getElementById('scheduler-status-detail');
            let label, cls, msg = '';
            if (!s.enabled) {
                label = 'Disabled';
                cls = 'bg-gray-200 text-gray-700';
            } else if (c && c.service_active && c.installed) {
                label = 'Active';
                cls = 'bg-green-100 text-green-800';
            } else {
                label = 'Needs attention';
                cls = 'bg-amber-100 text-amber-800';
                if (c && !c.service_active) {
                    msg = "The system's cron service is not running (" + (c.service_message || 'inactive') + "). Scheduled snapshots will not be taken until cron is started.";
                } else if (c && !c.installed) {
                    msg = 'Unable to register the scheduler with cron. Try saving again.';
                } else {
                    msg = 'Scheduler status unknown.';
                }
            }
            badge.textContent = label;
            badge.className = 'ml-1 px-2 py-0.5 rounded text-xs ' + cls;
            if (msg) {
                detail.textContent = msg;
                detail.classList.remove('hidden');
            } else {
                detail.classList.add('hidden');
            }

            const logEl = document.getElementById('scheduler-log');
            const logTxt = (c && c.recent_log && c.recent_log.length) ? c.recent_log : '';
            logEl.textContent = logTxt || '(no activity yet)';
        }

        function snapshotsRenderTable(rows) {
            const wrap = document.getElementById('snapshots-table-wrap');
            if (!rows.length) {
                wrap.innerHTML = '<p class="text-sm text-gray-500">No snapshots yet. Create one above.</p>';
                return;
            }
            const fmtBytes = (b) => {
                if (b > 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
                if (b > 1048576) return (b / 1048576).toFixed(1) + ' MB';
                if (b > 1024) return (b / 1024).toFixed(1) + ' KB';
                return b + ' B';
            };
            let html = `<div class="border border-gray-300 rounded overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead><tr class="border-b-2 border-gray-400 bg-gray-100">
                        <th class="text-left p-2">Created (UTC)</th>
                        <th class="text-left p-2">Size</th>
                        <th class="text-left p-2">Type</th>
                        <th class="text-left p-2">Creator</th>
                        <th class="text-left p-2">Note</th>
                        <th class="text-right p-2">Actions</th>
                    </tr></thead><tbody>`;
            rows.forEach(r => {
                const missing = !r.file_exists ? ' <span class="text-red-700 text-xs">(file missing)</span>' : '';
                html += `<tr class="border-b border-gray-200 hover:bg-gray-50">
                    <td class="p-2 whitespace-nowrap">${escapeHtmlAdmin(r.created_at)}${missing}</td>
                    <td class="p-2 whitespace-nowrap">${fmtBytes(parseInt(r.size_bytes, 10) || 0)}</td>
                    <td class="p-2"><span class="text-xs px-2 py-0.5 rounded ${r.trigger_type === 'manual' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'}">${escapeHtmlAdmin(r.trigger_type)}</span></td>
                    <td class="p-2">${escapeHtmlAdmin(r.creator_email || (r.trigger_type === 'scheduled' ? 'system' : '—'))}</td>
                    <td class="p-2">${escapeHtmlAdmin(r.note || '')}</td>
                    <td class="p-2 text-right whitespace-nowrap">
                        <button type="button" onclick="snapshotRestoreClick(${r.id}, '${escapeHtmlAdmin(r.created_at)}')" class="text-orange-600 hover:underline text-xs mr-2">Restore</button>
                        <a href="snapshots/download.php?id=${r.id}" class="text-blue-600 hover:underline text-xs mr-2">Download</a>
                        <button type="button" onclick="snapshotDeleteClick(${r.id})" class="text-red-600 hover:underline text-xs">Delete</button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            wrap.innerHTML = html;
        }

        async function snapshotCreate() {
            const note = document.getElementById('snapshot-note').value;
            const btn = document.getElementById('snapshot-create-btn');
            const progress = document.getElementById('snapshot-create-progress');
            const label = document.getElementById('snapshot-create-progress-label');
            const originalLabel = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading loading-spinner loading-xs"></span> Creating...';
            progress.classList.remove('hidden');
            const t0 = Date.now();
            const tick = setInterval(() => {
                const s = Math.floor((Date.now() - t0) / 1000);
                label.textContent = 'Creating snapshot. Elapsed: ' + s + 's. This may take a minute for large instances. Please do not close this tab.';
            }, 1000);
            try {
                const r = await fetch('snapshots/create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN, note }),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Create failed');
                showMessage('Snapshot created in ' + Math.floor((Date.now() - t0) / 1000) + 's.', 'success');
                document.getElementById('snapshot-note').value = '';
                snapshotsLoad();
            } catch (e) {
                showMessage('Create snapshot failed: ' + escapeHtmlAdmin(e.message), 'error');
            } finally {
                clearInterval(tick);
                btn.disabled = false;
                btn.innerHTML = originalLabel;
                progress.classList.add('hidden');
                label.textContent = 'Creating snapshot. This may take a minute for large instances. Please do not close this tab.';
            }
        }

        async function snapshotDeleteClick(id) {
            if (!confirm('Delete this snapshot? The file will be permanently removed from disk.')) return;
            try {
                const r = await fetch('snapshots/delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN, id }),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Delete failed');
                showMessage('Snapshot deleted.', 'success');
                snapshotsLoad();
            } catch (e) {
                showMessage('Delete failed: ' + escapeHtmlAdmin(e.message), 'error');
            }
        }

        async function snapshotRestoreClick(id, createdAt) {
            const phrase = prompt(`RESTORE will WIPE the entire system and replace it with the snapshot from ${createdAt}.\n\nAll snapshots created after that point will also be deleted.\n\nType RESTORE to confirm:`);
            if (phrase !== 'RESTORE') {
                if (phrase !== null) showMessage('Confirmation phrase did not match. Restore cancelled.', 'error');
                return;
            }
            let confirmNoAdmin = false;
            try {
                const r = await fetch('snapshots/restore.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN, id, confirm_text: 'RESTORE', confirm_no_admin: confirmNoAdmin }),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) {
                    if (data.error && data.error.indexOf('no admin user') !== -1) {
                        if (!confirm('WARNING: this snapshot has no admin user. Restoring will lock everyone out of the admin console. Proceed anyway?')) return;
                        // Retry with override
                        const r2 = await fetch('snapshots/restore.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                            body: JSON.stringify({ csrf_token: CSRF_TOKEN, id, confirm_text: 'RESTORE', confirm_no_admin: true }),
                        });
                        const data2 = await r2.json();
                        if (!r2.ok || !data2.ok) throw new Error(data2.error || 'Restore failed');
                        showMessage('Restore complete. You may be logged out.', 'success');
                        return;
                    }
                    throw new Error(data.error || 'Restore failed');
                }
                const rep = data.report;
                showMessage(`Restore complete. Created ${rep.galaxies_created} galaxies, ${rep.users_created} users. ${rep.snapshots_deleted_after_restore} later snapshot(s) deleted. You may be logged out.`, 'success');
                snapshotsLoad();
            } catch (e) {
                showMessage('Restore failed: ' + escapeHtmlAdmin(e.message), 'error');
            }
        }

        async function scheduleSave() {
            const payload = {
                csrf_token: CSRF_TOKEN,
                enabled: document.getElementById('schedule-enabled').checked,
                hour: document.getElementById('schedule-hour').value,
                keep_days: document.getElementById('schedule-keep-days').value,
            };
            try {
                const r = await fetch('snapshots/schedule.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify(payload),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Save failed');
                if (data.warning) {
                    showMessage('Saved, but scheduler could not register with cron: ' + escapeHtmlAdmin(data.warning), 'error');
                } else {
                    showMessage('Schedule saved.', 'success');
                }
                snapshotsRenderScheduler(data.schedule, data.cron);
            } catch (e) {
                showMessage('Save schedule failed: ' + escapeHtmlAdmin(e.message), 'error');
            }
        }
    </script>
    <!-- Create User Modal -->
    <dialog id="create_user_modal" class="modal">
        <div class="modal-box max-w-2xl bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Create New User</h3>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="create_user">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="create-firstname" class="block mb-1.5 text-gray-800 font-medium">First Name *</label>
                        <input type="text" id="create-firstname" name="firstname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block">The user's given name.</span>
                    </div>
                    <div>
                        <label for="create-lastname" class="block mb-1.5 text-gray-800 font-medium">Last Name *</label>
                        <input type="text" id="create-lastname" name="lastname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block">The user's family name.</span>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="create-email" class="block mb-1.5 text-gray-800 font-medium">Email *</label>
                    <input type="email" id="create-email" name="email" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-email-error" class="text-xs text-red-600 mt-1 hidden">This email is already in use.</span>
                    <span class="text-xs text-gray-500 mt-1 block">Login identifier and contact address.</span>
                </div>
                
                <div class="mb-4">
                    <label for="create-password" class="block mb-1.5 text-gray-800 font-medium">Password *</label>
                    <input type="password" id="create-password" name="password" required minlength="8" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Minimum 8 characters.</span>
                </div>
                
                <div class="mb-4">
                    <label for="create-type" class="block mb-1.5 text-gray-800 font-medium text-sm">User Type *</label>
                    <select id="create-type" name="type" required onchange="toggleCreateUserConstellations()" class="select select-bordered select-sm w-full bg-white">
                        <option value="1">Editor</option>
                        <option value="2">Admin</option>
                    </select>
                    <span class="text-xs text-gray-500 mt-1 block">
                        Editor: Can edit wormholes in assigned galaxies only | Admin: Full access to all galaxies.
                    </span>
                </div>
                
                <div class="mb-4 p-3 border border-gray-200 rounded bg-white">
                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                        <input type="checkbox" id="create_constellation_cb" name="create_constellation" value="1" class="rounded border-gray-300" checked onchange="toggleCreateNewConstellationName()">
                        <span class="text-gray-800 font-medium">Create a new galaxy for this user</span>
                    </label>
                    <p class="text-xs text-gray-500 mb-2">A new galaxy is created with the name below and the user is granted access to it (Editors only).</p>
                    <div id="create-new-constellation-name-wrap">
                        <label for="create_new_constellation_name" class="block mb-1 text-gray-700 text-sm">Galaxy name *</label>
                        <input type="text" id="create_new_constellation_name" name="new_constellation_name" placeholder="Defaults to email above" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block">Name for the automatically created galaxy.</span>
                    </div>
                </div>
                
                <div id="create-user-constellations-section" class="mb-4">
                    <label class="block mb-1.5 text-gray-800 font-medium">Galaxy access (Editors only)</label>
                    <div class="border border-gray-200 rounded p-3 bg-white max-h-48 overflow-y-auto">
                        <?php
                        $prevGroup = false;
                        foreach ($constellations as $c):
                            $g = extractConstellationGroup($c['name']);
                            $bgColor = $g !== null ? ($constellationGroupColors[$g] ?? '') : '';
                            if ($g !== $prevGroup) {
                                if ($prevGroup !== false && $prevGroup !== null) echo '</div>';
                                if ($g !== null) echo '<div class="rounded mb-1 mt-1 px-1" style="background-color: ' . htmlspecialchars($constellationGroupColors[$g] ?? '') . '">';
                                $prevGroup = $g;
                            }
                        ?>
                            <label class="flex items-center gap-2 py-1 text-sm cursor-pointer hover:opacity-80 rounded px-2">
                                <input type="checkbox" name="constellation_ids[]" value="<?php echo (int)$c['id']; ?>" class="rounded border-gray-300">
                                <span class="font-mono text-gray-600"><?php echo (int)$c['id']; ?></span>
                                <span class="text-gray-800"><?php echo htmlspecialchars($c['name']); ?></span>
                            </label>
                        <?php endforeach;
                        if ($prevGroup !== false && $prevGroup !== null) echo '</div>';
                        ?>
                    </div>
                    <span class="text-xs text-gray-500 mt-1 block">Editors can only see and edit wormholes in the galaxies checked above. Admins see all galaxies.</span>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Create User</button>
                    <button type="button" class="btn" onclick="document.getElementById('create_user_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Mocambos Import Modal -->
    <dialog id="mocambos_import_modal" class="modal">
        <div class="modal-box bg-white max-w-lg !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Import from Mocambos</h3>
            </div>
            <!-- Step 1: API URL -->
            <div id="mocambos-url-step" class="mt-4">
                <label for="mocambos-api-url" class="block mb-1.5 text-gray-800 font-medium text-sm">Mocambos API URL</label>
                <input type="url" id="mocambos-api-url" placeholder="https://timbuktu.mocambos.net/api/v2" value="" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-1">
                <span class="text-xs text-gray-500 block mb-4">The base API URL of the Mocambos instance (e.g. https://hostname/api/v2). You can also paste the docs URL — /docs will be stripped automatically.</span>
                <button type="button" id="mocambos-fetch-btn" onclick="fetchMocambosGalaxias()" class="btn bg-purple-600 hover:bg-purple-700 text-white btn-sm">Connect</button>
            </div>
            <!-- Step 2: Loading -->
            <div id="mocambos-loading" class="hidden text-center py-8">
                <span class="loading loading-spinner loading-lg text-purple-600"></span>
                <p class="text-gray-600 mt-2">Fetching available galaxias...</p>
            </div>
            <div id="mocambos-error" class="hidden text-center py-8">
                <p class="text-red-600 font-medium" id="mocambos-error-message"></p>
                <button type="button" onclick="showMocambosUrlStep()" class="btn btn-sm btn-outline mt-3">Back</button>
            </div>
            <!-- Step 3: Galaxia selection + import -->
            <div id="mocambos-list" class="hidden">
                <p class="text-sm text-gray-600 mb-1">Connected to: <strong id="mocambos-connected-url" class="font-mono text-xs"></strong></p>
                <p class="text-sm text-gray-600 mb-3">Select galaxias to import. Each will become a new galaxy. Already-imported ones will be refreshed.</p>
                <div id="mocambos-galaxias" class="space-y-2 max-h-64 overflow-y-auto border border-gray-200 rounded p-3 mb-4"></div>
                <div id="mocambos-import-progress" class="hidden">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="loading loading-spinner loading-sm text-purple-600"></span>
                        <span class="text-sm font-medium text-gray-700" id="mocambos-progress-status">Starting import...</span>
                    </div>
                    <div id="mocambos-log" class="bg-gray-900 text-gray-200 rounded p-3 font-mono text-xs h-64 overflow-y-auto space-y-0.5"></div>
                </div>
                <div id="mocambos-import-result" class="hidden mb-4"></div>
            </div>
            <!-- Refresh confirmation step -->
            <div id="refresh-confirm-step" class="hidden">
                <p class="text-gray-700 mb-2">This will sync wormholes with the remote Mocambos source (incremental update).</p>
                <p class="text-gray-700 mb-4">To confirm, type the galaxy name <strong id="refresh-confirm-name" class="text-gray-900"></strong> below:</p>
                <input type="text" id="refresh-confirm-input" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-purple-500 mb-4" placeholder="Type galaxy name to confirm" autocomplete="off">
                <div class="flex justify-end gap-2">
                    <button type="button" id="refresh-confirm-btn" class="btn bg-purple-600 hover:bg-purple-700 text-white btn-sm" disabled>Refresh</button>
                    <button type="button" class="btn btn-sm" onclick="document.getElementById('mocambos_import_modal').close()">Cancel</button>
                </div>
            </div>
            <div class="modal-action">
                <button type="button" id="mocambos-import-btn" class="btn bg-purple-600 hover:bg-purple-700 text-white hidden" onclick="doMocambosImport()">Import Selected</button>
                <button type="button" class="btn" onclick="document.getElementById('mocambos_import_modal').close()">Close</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Create Constellation Modal -->
    <dialog id="create_constellation_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Create New Galaxy</h3>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="create_constellation">
                
                <div class="mb-4">
                    <label for="create-constellation-name" class="block mb-1.5 text-gray-800 font-medium">Name *</label>
                    <input type="text" id="create-constellation-name" name="name" required placeholder="e.g. Main network, Archive" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-constellation-name-error" class="text-xs text-red-600 mt-1 hidden">This name is already in use.</span>
                    <span class="text-xs text-gray-500 mt-1 block">Unique name for the new wormhole network.</span>
                </div>

                <div class="mb-4">
                    <label for="create-constellation-slug" class="block mb-1.5 text-gray-800 font-medium">URL Slug</label>
                    <input type="text" id="create-constellation-slug" name="slug" placeholder="e.g. archive" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden">This slug is already in use.</span>
                    <span class="text-xs text-gray-500 mt-1 block">Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.</span>
                </div>
                
                <div class="mb-4">
                    <label for="create-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                    <input type="text" id="create-constellation-tagline" name="tagline" placeholder="e.g. Weaving memory" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Shown in the main view when this galaxy is open.</span>
                </div>

                <div class="mb-4">
                    <label for="create-constellation-theme" class="block mb-1.5 text-gray-800 font-medium text-sm">Visual Theme</label>
                    <select id="create-constellation-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                        <option value="cosmic">Cosmic (Stars, Planets, Rockets)</option>
                        <option value="simple">Simple (Colored Spheres)</option>
                        <option value="abstract">Abstract (Geometric GIF Icons)</option>
                        <option value="rectangles">Rectangles (Custom Rectangle Icons)</option>
                        <option value="stripes">Stripes (Custom Stripe Icons)</option>
                        <option value="tech">Tech (Circuit Board Icons)</option>
                    </select>
                    <span class="text-xs text-gray-500 mt-1 block">Determines the background, icons and animations.</span>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Create Galaxy</button>
                    <button type="button" class="btn" onclick="document.getElementById('create_constellation_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- User Edit Modal -->
    <dialog id="user_modal" class="modal">
        <div class="modal-box max-w-2xl bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl">Edit User</h3>
                <span id="modal-user-id-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" id="modal-user-id" name="id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="modal-firstname" class="block mb-1.5 text-gray-800 font-medium">First Name *</label>
                        <input type="text" id="modal-firstname" name="firstname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label for="modal-lastname" class="block mb-1.5 text-gray-800 font-medium">Last Name *</label>
                        <input type="text" id="modal-lastname" name="lastname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="modal-email" class="block mb-1.5 text-gray-800 font-medium">Email *</label>
                    <input type="email" id="modal-email" name="email" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="modal-email-error" class="text-xs text-red-600 mt-1 hidden">This email is already in use.</span>
                </div>
                
                <div class="mb-4">
                    <label for="modal-password" class="block mb-1.5 text-gray-800 font-medium">Password (leave blank to keep current)</label>
                    <input type="password" id="modal-password" name="password" minlength="8" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="modal-type" class="block mb-1.5 text-gray-800 font-medium text-sm">User Type *</label>
                    <select id="modal-type" name="type" required onchange="toggleModalUserConstellations()" class="select select-bordered select-sm w-full bg-white">
                        <option value="1">Editor</option>
                        <option value="2">Admin</option>
                    </select>
                </div>
                
                <div id="modal-user-constellations-section" class="mb-4 hidden">
                    <label class="block mb-1.5 text-gray-800 font-medium">Galaxy access (Editors only)</label>
                    <div class="border border-gray-200 rounded p-3 bg-white max-h-48 overflow-y-auto">
                        <?php
                        $prevGroup2 = false;
                        foreach ($constellations as $c):
                            $g2 = extractConstellationGroup($c['name']);
                            if ($g2 !== $prevGroup2) {
                                if ($prevGroup2 !== false && $prevGroup2 !== null) echo '</div>';
                                if ($g2 !== null) echo '<div class="rounded mb-1 mt-1 px-1" style="background-color: ' . htmlspecialchars($constellationGroupColors[$g2] ?? '') . '">';
                                $prevGroup2 = $g2;
                            }
                        ?>
                            <label class="flex items-center gap-2 py-1 text-sm cursor-pointer hover:opacity-80 rounded px-2">
                                <input type="checkbox" name="constellation_ids[]" value="<?php echo (int)$c['id']; ?>" class="modal-user-constellation-checkbox rounded border-gray-300">
                                <span class="font-mono text-gray-600"><?php echo (int)$c['id']; ?></span>
                                <span class="text-gray-800"><?php echo htmlspecialchars($c['name']); ?></span>
                            </label>
                        <?php endforeach;
                        if ($prevGroup2 !== false && $prevGroup2 !== null) echo '</div>';
                        ?>
                    </div>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Update User</button>
                    <button type="button" class="btn" onclick="document.getElementById('user_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Constellation Edit Modal -->
    <dialog id="constellation_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl">Edit Galaxy</h3>
                <span id="modal-constellation-id-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="update_constellation">
                <input type="hidden" id="modal-constellation-id" name="id">
                
                <div class="mb-4">
                    <label for="modal-constellation-name" class="block mb-1.5 text-gray-800 font-medium">Name *</label>
                    <input type="text" id="modal-constellation-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="modal-constellation-name-error" class="text-xs text-red-600 mt-1 hidden">This name is already in use.</span>
                </div>

                <div class="mb-4">
                    <label for="modal-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                    <input type="text" id="modal-constellation-tagline" name="tagline" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label for="modal-constellation-slug" class="block mb-1.5 text-gray-800 font-medium">URL Slug</label>
                    <input type="text" id="modal-constellation-slug" name="slug" placeholder="e.g. archive" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="modal-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden">This slug is already in use.</span>
                    <span class="text-xs text-gray-500 mt-1 block">Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.</span>
                </div>

                <div class="mb-4">
                    <label for="modal-constellation-theme" class="block mb-1.5 text-gray-800 font-medium text-sm">Visual Theme</label>
                    <select id="modal-constellation-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                        <option value="cosmic">Cosmic (Stars, Planets, Rockets)</option>
                        <option value="simple">Simple (Colored Spheres)</option>
                        <option value="abstract">Abstract (Geometric GIF Icons)</option>
                        <option value="rectangles">Rectangles (Custom Rectangle Icons)</option>
                        <option value="stripes">Stripes (Custom Stripe Icons)</option>
                        <option value="tech">Tech (Circuit Board Icons)</option>
                    </select>
                </div>

                <div class="mb-4 border-t border-gray-200 pt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal-tour-enabled" name="tour_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium">Auto-tour</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1">Automatically navigate visitors through nodes, opening each card and playing media. Desktop and iPad only.</p>

                    <div id="modal-tour-section" class="mt-4 pl-6 border-l-2 border-gray-200 space-y-4 hidden">

                        <div>
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">Start Mode</label>
                            <div class="space-y-1">
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_start_mode" value="manual" class="radio radio-neutral radio-sm tour-start-mode">
                                    <span>Manual — visitor clicks a Play button to start</span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_start_mode" value="idle" class="radio radio-neutral radio-sm tour-start-mode">
                                    <span>Idle — start after visitor is inactive for a while</span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_start_mode" value="immediate" class="radio radio-neutral radio-sm tour-start-mode">
                                    <span>Immediate — start as soon as the galaxy loads</span>
                                </label>
                            </div>
                        </div>

                        <div id="modal-tour-idle-row" class="hidden">
                            <label for="modal-tour-idle-seconds" class="block mb-1.5 text-gray-800 font-medium text-sm">Idle threshold (seconds)</label>
                            <input type="number" id="modal-tour-idle-seconds" name="tour_idle_seconds" min="1" value="30" class="input input-bordered input-sm w-32 bg-white">
                        </div>

                        <div id="modal-tour-immediate-warning" class="hidden alert alert-warning text-sm py-2">
                            <span>This galaxy contains audio nodes. Browsers block autoplay-with-sound until the visitor interacts with the page, so the first audio in an immediate-start tour may stay silent or stall.</span>
                        </div>

                        <div>
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">Which nodes to tour</label>
                            <div class="space-y-1">
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="all" class="radio radio-neutral radio-sm tour-node-selection">
                                    <span>All nodes (random order each run)</span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="accentuated" class="radio radio-neutral radio-sm tour-node-selection">
                                    <span>Only accentuated nodes</span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="random_n" class="radio radio-neutral radio-sm tour-node-selection">
                                    <span>A random sample of N nodes</span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="tagged" class="radio radio-neutral radio-sm tour-node-selection">
                                    <span>Nodes tagged with one of these keywords</span>
                                </label>
                            </div>
                        </div>

                        <div id="modal-tour-random-row" class="hidden">
                            <label for="modal-tour-random-count" class="block mb-1.5 text-gray-800 font-medium text-sm">How many nodes per tour</label>
                            <input type="number" id="modal-tour-random-count" name="tour_random_count" min="1" value="10" class="input input-bordered input-sm w-32 bg-white">
                        </div>

                        <div id="modal-tour-tagged-row" class="hidden">
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">Keywords (any match)</label>
                            <div id="modal-tour-keywords" class="border border-gray-300 rounded p-2 max-h-40 overflow-y-auto bg-white text-sm"></div>
                            <span class="text-xs text-gray-500 mt-1 block">Visitors will see nodes matching any of the selected keywords.</span>
                        </div>

                        <div>
                            <label for="modal-tour-default-dwell" class="block mb-1.5 text-gray-800 font-medium text-sm">Pause on nodes without media (seconds)</label>
                            <input type="number" id="modal-tour-default-dwell" name="tour_default_dwell" min="1" value="8" class="input input-bordered input-sm w-32 bg-white">
                        </div>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="modal-tour-loop" name="tour_loop" value="1" class="toggle toggle-neutral toggle-sm">
                            <span class="text-gray-800 font-medium text-sm">Loop the tour when it finishes</span>
                        </label>
                    </div>
                </div>

                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Update Galaxy</button>
                    <button type="button" class="btn" onclick="document.getElementById('constellation_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Duplicate Constellation Modal -->
    <dialog id="duplicate_constellation_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl">Duplicate Galaxy</h3>
                <span id="duplicate-constellation-id-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <p class="text-sm text-gray-600 mb-4 mt-4">Duplicating: <strong id="duplicate-constellation-source-name"></strong></p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="duplicate_constellation">
                <input type="hidden" id="duplicate-source-id" name="source_id">
                
                <div class="mb-4">
                    <label for="duplicate-constellation-name" class="block mb-1.5 text-gray-800 font-medium">New Name *</label>
                    <input type="text" id="duplicate-constellation-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="duplicate-constellation-name-error" class="text-xs text-red-600 mt-1 hidden">This name is already in use.</span>
                </div>

                <div class="mb-4">
                    <label for="duplicate-constellation-slug" class="block mb-1.5 text-gray-800 font-medium">New URL Slug</label>
                    <input type="text" id="duplicate-constellation-slug" name="slug" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="duplicate-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden">This slug is already in use.</span>
                </div>
                
                <div class="mb-4">
                    <label for="duplicate-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">New Tagline</label>
                    <input type="text" id="duplicate-constellation-tagline" name="tagline" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Duplicate</button>
                    <button type="button" class="btn" onclick="document.getElementById('duplicate_constellation_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Delete Confirmation Modal -->
    <dialog id="delete_confirm_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-error text-error-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Confirm Deletion</h3>
            </div>
            <div id="delete-confirm-message" class="text-gray-600 mb-6 mt-4"></div>

            <div id="delete-impact-wrap" class="mb-6 hidden"></div>

            <div id="delete-name-confirm-wrap" class="mb-6 hidden">
                <label for="delete-confirm-name-input" class="block mb-2 text-sm font-medium text-gray-700">Please type the name of the galaxy to confirm:</label>
                <input type="text" 
                       id="delete-confirm-name-input" 
                       oninput="checkDeleteConfirmName(this)"
                       placeholder="Type name here..."
                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-error">
            </div>

            <div class="modal-action">
                <form id="delete-form" method="POST" action="">
                    <input type="hidden" name="action" id="delete-action" value="">
                    <input type="hidden" name="id" id="delete-id" value="">
                    <button type="submit" id="delete-confirm-btn" class="btn btn-error text-white">Delete</button>
                </form>
                <button type="button" class="btn" onclick="document.getElementById('delete_confirm_modal').close()">Cancel</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
<script>
// Auto-inject CSRF token into all POST forms
document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(form => {
    if (!form.querySelector('input[name="csrf_token"]')) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = <?php echo json_encode($csrfToken); ?>;
        form.appendChild(input);
    }
});
</script>
</body>
</html>
