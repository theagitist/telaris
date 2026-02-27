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
$activeTab = $_GET['tab'] ?? 'users';
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
                    throw new Exception('Constellation name is required when "Create new constellation" is checked.');
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
                    throw new Exception('Constellation name is required');
                }
                
                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A constellation with this ' . implode(' and ', $errs) . ' already exists.');
                }

                $theme = trim($_POST['theme'] ?? 'cosmic');
                db_create_constellation($name, $tagline, $slug !== '' ? $slug : null, $theme);
                $message = 'Constellation created successfully.';
                $activeTab = 'constellations';
            })(),
            
            'update_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                $theme = trim($_POST['theme'] ?? 'cosmic');
                if (empty($name)) {
                    throw new Exception('Constellation name is required');
                }

                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug, $id);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A constellation with this ' . implode(' and ', $errs) . ' already exists.');
                }

                db_update_constellation($id, $name, $tagline, $slug !== '' ? $slug : null, $theme);
                $message = 'Constellation updated successfully.';
                $activeTab = 'constellations';
            })(),
            
            'duplicate_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $sourceId = (int)($_POST['source_id'] ?? -1);
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                
                if (empty($name)) {
                    throw new Exception('New constellation name is required');
                }
                
                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A constellation with this ' . implode(' and ', $errs) . ' already exists.');
                }

                db_duplicate_constellation($sourceId, $name, $tagline, $slug !== '' ? $slug : null);
                $message = 'Constellation duplicated successfully.';
                $activeTab = 'constellations';
            })(),
            
            'delete_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                db_delete_constellation($id);
                $message = 'Constellation deleted successfully.';
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
    'back_button_text' => ['label' => 'Back button text', 'desc' => 'Text on the back button when navigating between constellations.', 'type' => 'text'],
    'system_online_text' => ['label' => 'System Online text', 'desc' => 'Status text shown in the HUD (e.g. "System: Online").', 'type' => 'text'],
    'reload_system_text' => ['label' => 'Reload System text', 'desc' => 'Tooltip for the reload action.', 'type' => 'text'],
    'scan_system_text' => ['label' => 'Scan System placeholder', 'desc' => 'Placeholder text for the search input.', 'type' => 'text'],
    'clear_scan_text' => ['label' => 'Clear Scan tooltip', 'desc' => 'Tooltip for the clear search button.', 'type' => 'text'],
    'systems_label_text' => ['label' => 'Systems label', 'desc' => 'Label for the nodes count in the HUD.', 'type' => 'text'],
    'hyperlinks_label_text' => ['label' => 'Hyperlinks label', 'desc' => 'Label for the connections count in the HUD.', 'type' => 'text'],
    'initialize_auth_text' => ['label' => 'Login label', 'desc' => 'Label for the login link (e.g. "Initialize Auth").', 'type' => 'text'],
    'admin_label_text' => ['label' => 'Admin label', 'desc' => 'Label for the admin link.', 'type' => 'text'],
    'logout_label_text' => ['label' => 'Logout label', 'desc' => 'Label for the logout link.', 'type' => 'text'],
    'click_to_view_text' => ['label' => 'Click to view hint', 'desc' => 'Interaction hint shown in node tooltips for mouse users.', 'type' => 'text'],
    'tap_to_view_text' => ['label' => 'Tap to view hint', 'desc' => 'Interaction hint shown in node tooltips for touch users.', 'type' => 'text'],
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
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" />
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
                    Constellations
                </button>
                <button onclick="showTab('users')" 
                        id="tab-users"
                        class="tab tab-lg <?php echo $activeTab === 'users' ? 'tab-active' : ''; ?>">
                    Users
                </button>
                <button onclick="showTab('api-keys')" 
                        id="tab-api-keys"
                        class="tab tab-lg <?php echo $activeTab === 'api-keys' ? 'tab-active' : ''; ?>">
                    API Keys
                </button>
                <button onclick="showTab('settings')" 
                        id="tab-settings"
                        class="tab tab-lg <?php echo $activeTab === 'settings' ? 'tab-active' : ''; ?>">
                    Global Settings
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
                        <div class="overflow-x-auto border border-gray-300 rounded">
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
                                                <div class="flex gap-2 justify-end">
                                                    <?php if (!$isCurrentUser): ?>
                                                        <?php 
                                                        $delMsg = "Are you sure you want to delete the user \"$fullName\"? This action cannot be undone.";
                                                        $delMsgJs = htmlspecialchars(json_encode($delMsg), ENT_QUOTES, 'UTF-8');
                                                        ?>
                                                        <button type="button" 
                                                                onclick="event.stopPropagation(); triggerDelete('delete_user', '<?php echo addslashes($user['id']); ?>', <?php echo $delMsgJs; ?>, null)" 
                                                                class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded">Delete</button>
                                                    <?php endif; ?>
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
                                        <div class="flex gap-2">
                                            <form method="POST" action="" class="inline">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $key['is_active'] ? '0' : '1'; ?>">
                                                <button type="submit" 
                                                        class="px-3 py-1 text-sm rounded <?php echo $key['is_active'] ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-500 hover:bg-green-600'; ?> text-white">
                                                    <?php echo $key['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
                                            <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this API key? This action cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                                <button type="submit" class="px-3 py-1 text-sm bg-red-500 hover:bg-red-600 text-white rounded">
                                                    Delete
                                                </button>
                                            </form>
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
                            <h2 class="text-gray-800 text-base font-semibold">Constellations (<?php echo count($constellations); ?>)</h2>
                            <button type="button" onclick="document.getElementById('create_constellation_modal').showModal()" class="text-blue-600 hover:text-blue-800 font-medium text-base">New Constellation</button>
                        </div>

                        <!-- Top Pagination -->
                        <div id="constellations-pagination-header" class="flex-1 flex justify-center"></div>

                        <div class="flex items-center gap-2 min-w-[250px]">
                            <label for="search-constellations" class="text-sm font-medium text-gray-700">Search:</label>
                            <input type="text" 
                                   id="search-constellations" 
                                   placeholder="Search constellations..." 
                                   oninput="applyConstellationSearch()"
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
                    <p class="text-sm text-gray-600 mb-4">Each constellation is a separate set of nodes and keywords. The current default constellation, <strong><?php echo $defaultName; ?></strong>, cannot be deleted. You can change the default constellation in the <button onclick="showTab(\'settings\')" class="text-blue-600 hover:underline">Global Settings</button> tab.</p>
                    <div id="copy-url-toast" class="hidden fixed top-4 right-4 z-50 bg-green-600 text-white px-4 py-3 rounded shadow-lg text-sm" role="status" aria-live="polite">URL copied to clipboard.</div>
                    <?php if (empty($constellations)): ?>
                        <p class="text-gray-600">No constellations found.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto border border-gray-300 rounded">
                            <table id="constellations-list" class="w-full border-collapse">
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
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('created_at')">Created<span id="sort-indicator-const-created_at"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('updated_at')">Last Updated<span id="sort-indicator-const-updated_at"></span></span>
                                        </th>
                                        <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($constellations as $c): ?>
                                        <?php
                                        $cId = (int)$c['id'];
                                        $isDefault = $cId === (int)($projectAll['default_constellation_id'] ?? 0);
                                        $cTagline = isset($c['tagline']) ? (string)$c['tagline'] : '';
                                        $viewRel = $cId === (int)($projectAll['default_constellation_id'] ?? 0) ? '../index.php' : '../index.php?constellation_id=' . $cId;
                                        ?>
                                        <tr class="constellation-row border-b border-gray-300 hover:bg-gray-50" 
                                            data-id="<?php echo $cId; ?>" 
                                            data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>" 
                                            data-slug="<?php echo htmlspecialchars(strtolower($c['slug'] ?? '')); ?>"
                                            data-date-created="<?php echo isset($c['created_at']) ? strtotime($c['created_at']) : 0; ?>"
                                            data-updated-at="<?php echo isset($c['updated_at']) ? strtotime($c['updated_at']) : 0; ?>"
                                            data-tagline="<?php echo htmlspecialchars(strtolower($cTagline)); ?>">
                                            <?php 
                                            $cData = [
                                                'id' => $cId,
                                                'name' => $c['name'],
                                                'tagline' => $cTagline,
                                                'slug' => $c['slug'],
                                                'theme' => $c['theme'] ?? 'cosmic'
                                            ];
                                            $cJson = htmlspecialchars(json_encode($cData), ENT_QUOTES, 'UTF-8');
                                            $clickEditC = "editConstellation($cJson)";
                                            $cCreatedTs = isset($c['created_at']) ? strtotime($c['created_at']) : false;
                                            $cUpdatedTs = isset($c['updated_at']) ? strtotime($c['updated_at']) : false;
                                            $cCreatedIso = $cCreatedTs !== false ? gmdate('c', $cCreatedTs) : '';
                                            $cUpdatedIso = $cUpdatedTs !== false ? gmdate('c', $cUpdatedTs) : '';
                                            ?>
                                            <td class="py-2 px-2 font-mono text-gray-800 cursor-pointer whitespace-nowrap" onclick="<?php echo $clickEditC; ?>"><?php echo $cId; ?></td>
                                            <td class="py-2 px-2 font-semibold text-gray-800 cursor-pointer" onclick="<?php echo $clickEditC; ?>">
                                                <?php echo htmlspecialchars($c['name']); ?>
                                                <?php if ($isDefault): ?>
                                                    <span class="ml-2 text-xs bg-green-400 text-white px-1.5 py-0.5 rounded">Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 font-mono text-xs text-blue-600 cursor-pointer" onclick="<?php echo $clickEditC; ?>">
                                                <?php echo htmlspecialchars($c['slug'] ?? ''); ?>
                                            </td>
                                            <td class="py-2 px-2 text-gray-600 text-sm max-w-xs truncate cursor-pointer" onclick="<?php echo $clickEditC; ?>" title="<?php echo htmlspecialchars($cTagline); ?>"><?php echo htmlspecialchars($cTagline); ?></td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEditC; ?>">
                                                <?php if ($cCreatedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($cCreatedIso); ?>"><?php echo date('y-m-d H:i', $cCreatedTs); ?></span><?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEditC; ?>">
                                                <?php if ($cUpdatedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($cUpdatedIso); ?>"><?php echo date('y-m-d H:i', $cUpdatedTs); ?></span><?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-right">
                                                <div class="flex gap-2 justify-end items-center">
                                                    <?php if (!$isDefault): ?>
                                                        <?php 
                                                        $cName = $c['name'];
                                                        $delMsgC = "Are you sure you want to delete the constellation \"$cName\"? This will permanently remove ALL nodes and keywords inside it.";
                                                        $delMsgJsC = htmlspecialchars(json_encode($delMsgC), ENT_QUOTES, 'UTF-8');
                                                        $cNameJs = htmlspecialchars(json_encode($cName), ENT_QUOTES, 'UTF-8');
                                                        ?>
                                                        <button type="button" 
                                                                onclick="event.stopPropagation(); triggerDelete('delete_constellation', '<?php echo $cId; ?>', <?php echo $delMsgJsC; ?>, <?php echo $cNameJs; ?>)" 
                                                                class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded">Delete</button>
                                                    <?php endif; ?>
                                                    <button type="button" 
                                                            onclick="event.stopPropagation(); duplicateConstellation(<?php echo $cJson; ?>)" 
                                                            class="px-2 py-1 bg-blue-500 hover:bg-blue-600 text-white text-xs rounded">Duplicate</button>
                                                    <a href="<?php echo htmlspecialchars($viewRel); ?>" target="_blank" rel="noopener" class="px-2 py-1 bg-gray-500 hover:bg-gray-600 text-white text-xs rounded inline-flex items-center gap-1" onclick="event.stopPropagation()">View</a>
                                                    <button type="button" onclick="event.stopPropagation(); copyConstellationUrl('<?php echo htmlspecialchars($viewRel, ENT_QUOTES); ?>', this)" class="p-1.5 rounded border border-gray-300 hover:bg-gray-100 text-gray-600 hover:text-gray-800" title="Copy constellation URL">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                    </button>
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
                        <label for="default_constellation_id" class="block mb-1.5 text-gray-800 font-medium text-sm">Default Constellation</label>
                        <select id="default_constellation_id" name="default_constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php foreach ($constellations as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo (isset($projectAll['default_constellation_id']) && (int)$projectAll['default_constellation_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                    [ID: <?php echo (int)$c['id']; ?>] <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-xs text-gray-500 mt-1 block">Choose which constellation is shown at the root of the website. The chosen constellation will also have its name and tagline synced with the "App name" and "Description" fields below.</span>
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
        const API_URL = '../api/validate.php';

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
                    setTimeout(function() { buttonEl.setAttribute('title', origTitle || 'Copy constellation URL'); }, 1500);
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
            users: { currentPage: 1, itemsPerPage: 20 },
            constellations: { currentPage: 1, itemsPerPage: 20 }
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
            document.getElementById('user_modal').showModal();
        }

        function editConstellation(c) {
            document.getElementById('modal-constellation-id').value = c.id;
            document.getElementById('modal-constellation-name').value = c.name;
            document.getElementById('modal-constellation-slug').value = c.slug || '';
            document.getElementById('modal-constellation-tagline').value = c.tagline;
            document.getElementById('modal-constellation-theme').value = c.theme || 'cosmic';
            document.getElementById('constellation_modal').showModal();
        }

        function duplicateConstellation(c) {
            document.getElementById('duplicate-source-id').value = c.id;
            document.getElementById('duplicate-constellation-source-name').textContent = c.name;
            document.getElementById('duplicate-constellation-name').value = c.name + ' (Copy)';
            document.getElementById('duplicate-constellation-slug').value = (c.slug ? c.slug + '-copy' : '');
            document.getElementById('duplicate-constellation-tagline').value = c.tagline;
            document.getElementById('duplicate_constellation_modal').showModal();
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
                            html += `<p class="mb-2">The following portal nodes in other constellations point to this network and will also be deleted:</p>`;
                            html += `<ul class="list-disc list-inside space-y-1">`;
                            data.referencing_portals.forEach(p => {
                                html += `<li><strong>${p.name}</strong> (in constellation: ${p.constellation_name})</li>`;
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
            document.getElementById('content-php-info').classList.add('hidden');
            
            // Remove active styling from all tabs
            const tabs = ['api-keys', 'users', 'constellations', 'settings', 'php-info'];
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
            const tab = new URLSearchParams(window.location.search).get('tab') || 'users';
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
            applyPagination('constellations');

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
                    setTimeout(function() { buttonEl.setAttribute('title', origTitle || 'Copy constellation URL'); }, 1500);
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

        // Constellation list sorting
        let currentConstSortColumn = null;
        let currentConstSortOrder = 'asc';

        function sortConstellationsByColumn(column) {
            if (currentConstSortColumn === column) {
                currentConstSortOrder = currentConstSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                currentConstSortColumn = column;
                currentConstSortOrder = 'asc';
            }
            updateConstellationSortIndicators();
            applyConstellationSorting();
        }

        function updateConstellationSortIndicators() {
            ['id', 'name', 'slug', 'tagline', 'created_at', 'updated_at'].forEach(col => {
                const indicator = document.getElementById('sort-indicator-const-' + col);
                if (indicator) {
                    indicator.innerHTML = '';
                }
            });
            
            if (currentConstSortColumn) {
                const indicator = document.getElementById('sort-indicator-const-' + currentConstSortColumn);
                if (indicator) {
                    indicator.innerHTML = currentConstSortOrder === 'asc' ? ' ↑' : ' ↓';
                }
            }
        }

        function applyConstellationSorting() {
            const constTable = document.getElementById('constellations-list');
            if (!constTable) return;
            const tbody = constTable.querySelector('tbody');
            if (!tbody) return;
            
            const rows = Array.from(tbody.querySelectorAll('tr.constellation-row'));
            if (rows.length === 0) return;
            
            const sortedRows = rows.sort((a, b) => {
                let aVal, bVal;
                
                switch(currentConstSortColumn) {
                    case 'id':
                        aVal = parseInt(a.dataset.id) || 0;
                        bVal = parseInt(b.dataset.id) || 0;
                        break;
                    case 'name':
                        aVal = a.dataset.name || '';
                        bVal = b.dataset.name || '';
                        break;
                    case 'slug':
                        aVal = a.dataset.slug || '';
                        bVal = b.dataset.slug || '';
                        break;
                    case 'tagline':
                        aVal = a.dataset.tagline || '';
                        bVal = b.dataset.tagline || '';
                        break;
                    case 'created_at':
                        aVal = parseInt(a.dataset.dateCreated) || 0;
                        bVal = parseInt(b.dataset.dateCreated) || 0;
                        break;
                    case 'updated_at':
                        aVal = parseInt(a.dataset.updatedAt) || 0;
                        bVal = parseInt(b.dataset.updatedAt) || 0;
                        break;
                    default:
                        return 0;
                }
                
                if (aVal < bVal) return currentConstSortOrder === 'asc' ? -1 : 1;
                if (aVal > bVal) return currentConstSortOrder === 'asc' ? 1 : -1;
                return 0;
            });
            
            sortedRows.forEach(row => tbody.appendChild(row));
            applyPagination('constellations');
        }

        function applyUserSearch() {
            paginationState.users.currentPage = 1;
            applyPagination('users');
        }

        function applyConstellationSearch() {
            paginationState.constellations.currentPage = 1;
            applyPagination('constellations');
        }
    </script>
    <!-- Create User Modal -->
    <dialog id="create_user_modal" class="modal">
        <div class="modal-box max-w-2xl bg-white">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Create New User</h3>
            <form method="POST" action="">
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
                        Editor: Can edit nodes in assigned constellations only | Admin: Full access to all constellations.
                    </span>
                </div>
                
                <div class="mb-4 p-3 border border-gray-200 rounded bg-white">
                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                        <input type="checkbox" id="create_constellation_cb" name="create_constellation" value="1" class="rounded border-gray-300" checked onchange="toggleCreateNewConstellationName()">
                        <span class="text-gray-800 font-medium">Create a new constellation for this user</span>
                    </label>
                    <p class="text-xs text-gray-500 mb-2">A new constellation is created with the name below and the user is granted access to it (Editors only).</p>
                    <div id="create-new-constellation-name-wrap">
                        <label for="create_new_constellation_name" class="block mb-1 text-gray-700 text-sm">Constellation name *</label>
                        <input type="text" id="create_new_constellation_name" name="new_constellation_name" placeholder="Defaults to email above" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block">Name for the automatically created constellation.</span>
                    </div>
                </div>
                
                <div id="create-user-constellations-section" class="mb-4">
                    <label class="block mb-1.5 text-gray-800 font-medium">Constellation access (Editors only)</label>
                    <div class="border border-gray-200 rounded p-3 bg-white max-h-48 overflow-y-auto">
                        <?php foreach ($constellations as $c): ?>
                            <label class="flex items-center gap-2 py-1 text-sm cursor-pointer hover:bg-gray-50 rounded px-2">
                                <input type="checkbox" name="constellation_ids[]" value="<?php echo (int)$c['id']; ?>" class="rounded border-gray-300">
                                <span class="font-mono text-gray-600"><?php echo (int)$c['id']; ?></span>
                                <span class="text-gray-800"><?php echo htmlspecialchars($c['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="text-xs text-gray-500 mt-1 block">Editors can only see and edit nodes in the constellations checked above. Admins see all constellations.</span>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Create User</button>
                    <button type="button" class="btn" onclick="document.getElementById('create_user_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Create Constellation Modal -->
    <dialog id="create_constellation_modal" class="modal">
        <div class="modal-box bg-white">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Create New Constellation</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_constellation">
                
                <div class="mb-4">
                    <label for="create-constellation-name" class="block mb-1.5 text-gray-800 font-medium">Name *</label>
                    <input type="text" id="create-constellation-name" name="name" required placeholder="e.g. Main network, Archive" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-constellation-name-error" class="text-xs text-red-600 mt-1 hidden">This name is already in use.</span>
                    <span class="text-xs text-gray-500 mt-1 block">Unique name for the new node network.</span>
                </div>

                <div class="mb-4">
                    <label for="create-constellation-slug" class="block mb-1.5 text-gray-800 font-medium">URL Slug (Optional)</label>
                    <input type="text" id="create-constellation-slug" name="slug" placeholder="e.g. archive" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden">This slug is already in use.</span>
                    <span class="text-xs text-gray-500 mt-1 block">Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.</span>
                </div>
                
                <div class="mb-4">
                    <label for="create-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                    <input type="text" id="create-constellation-tagline" name="tagline" placeholder="e.g. Weaving memory" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Shown in the main view when this constellation is open.</span>
                </div>

                <div class="mb-4">
                    <label for="create-constellation-theme" class="block mb-1.5 text-gray-800 font-medium text-sm">Visual Theme</label>
                    <select id="create-constellation-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                        <option value="cosmic">Cosmic (Stars, Planets, Rockets)</option>
                        <option value="abstract">Abstract (Geometric GIF Icons)</option>
                        <option value="rectangles">Rectangles (Custom Rectangle Icons)</option>
                        <option value="stripes">Stripes (Custom Stripe Icons)</option>
                        <option value="tech">Tech (Circuit Board Icons)</option>
                    </select>
                    <span class="text-xs text-gray-500 mt-1 block">Determines the background, icons and animations.</span>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Create Constellation</button>
                    <button type="button" class="btn" onclick="document.getElementById('create_constellation_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- User Edit Modal -->
    <dialog id="user_modal" class="modal">
        <div class="modal-box max-w-2xl bg-white">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Edit User</h3>
            <form method="POST" action="">
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
                    <label class="block mb-1.5 text-gray-800 font-medium">Constellation access (Editors only)</label>
                    <div class="border border-gray-200 rounded p-3 bg-white max-h-48 overflow-y-auto">
                        <?php foreach ($constellations as $c): ?>
                            <label class="flex items-center gap-2 py-1 text-sm cursor-pointer hover:bg-gray-50 rounded px-2">
                                <input type="checkbox" name="constellation_ids[]" value="<?php echo (int)$c['id']; ?>" class="modal-user-constellation-checkbox rounded border-gray-300">
                                <span class="font-mono text-gray-600"><?php echo (int)$c['id']; ?></span>
                                <span class="text-gray-800"><?php echo htmlspecialchars($c['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
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
        <div class="modal-box bg-white">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Edit Constellation</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_constellation">
                <input type="hidden" id="modal-constellation-id" name="id">
                
                <div class="mb-4">
                    <label for="modal-constellation-name" class="block mb-1.5 text-gray-800 font-medium">Name *</label>
                    <input type="text" id="modal-constellation-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="modal-constellation-name-error" class="text-xs text-red-600 mt-1 hidden">This name is already in use.</span>
                </div>

                <div class="mb-4">
                    <label for="modal-constellation-slug" class="block mb-1.5 text-gray-800 font-medium">URL Slug (Optional)</label>
                    <input type="text" id="modal-constellation-slug" name="slug" placeholder="e.g. archive" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="modal-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden">This slug is already in use.</span>
                    <span class="text-xs text-gray-500 mt-1 block">Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.</span>
                </div>
                
                <div class="mb-4">
                    <label for="modal-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                    <input type="text" id="modal-constellation-tagline" name="tagline" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label for="modal-constellation-theme" class="block mb-1.5 text-gray-800 font-medium text-sm">Visual Theme</label>
                    <select id="modal-constellation-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                        <option value="cosmic">Cosmic (Stars, Planets, Rockets)</option>
                        <option value="abstract">Abstract (Geometric GIF Icons)</option>
                        <option value="rectangles">Rectangles (Custom Rectangle Icons)</option>
                        <option value="stripes">Stripes (Custom Stripe Icons)</option>
                        <option value="tech">Tech (Circuit Board Icons)</option>
                    </select>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Update Constellation</button>
                    <button type="button" class="btn" onclick="document.getElementById('constellation_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Duplicate Constellation Modal -->
    <dialog id="duplicate_constellation_modal" class="modal">
        <div class="modal-box bg-white">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Duplicate Constellation</h3>
            <p class="text-sm text-gray-600 mb-4">Duplicating: <strong id="duplicate-constellation-source-name"></strong></p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="duplicate_constellation">
                <input type="hidden" id="duplicate-source-id" name="source_id">
                
                <div class="mb-4">
                    <label for="duplicate-constellation-name" class="block mb-1.5 text-gray-800 font-medium">New Name *</label>
                    <input type="text" id="duplicate-constellation-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="duplicate-constellation-name-error" class="text-xs text-red-600 mt-1 hidden">This name is already in use.</span>
                </div>

                <div class="mb-4">
                    <label for="duplicate-constellation-slug" class="block mb-1.5 text-gray-800 font-medium">New URL Slug (Optional)</label>
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
        <div class="modal-box bg-white border-t-4 border-error">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Confirm Deletion</h3>
            <div id="delete-confirm-message" class="text-gray-600 mb-6"></div>
            
            <div id="delete-impact-wrap" class="mb-6 hidden"></div>

            <div id="delete-name-confirm-wrap" class="mb-6 hidden">
                <label for="delete-confirm-name-input" class="block mb-2 text-sm font-medium text-gray-700">Please type the name of the constellation to confirm:</label>
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
