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

require_once '../config.php';

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


// Handle API key actions and user management actions
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
                if (empty($name)) {
                    throw new Exception('Constellation name is required');
                }
                db_create_constellation($name, $tagline);
                $message = 'Constellation created successfully.';
                $activeTab = 'constellations';
            })(),
            
            'update_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                if (empty($name)) {
                    throw new Exception('Constellation name is required');
                }
                db_update_constellation($id, $name, $tagline);
                $message = 'Constellation updated successfully.';
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
                        db_update_project_settings_with_locales($en, $es, $pt);
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
    <!-- Initial Loading Overlay -->
    <div id="admin-loading-overlay" class="fixed inset-0 z-[1000] bg-gray-100 flex flex-col items-center justify-center transition-opacity duration-300">
        <span class="loading loading-spinner loading-lg text-primary mb-4"></span>
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
                    <a href="../edit/index.php" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded">
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

        <!-- Messages -->
        <?php if ($newApiKey): ?>
            <div class="mb-5 p-4 bg-green-50 border-2 border-green-500 rounded">
                <h3 class="text-green-800 font-semibold mb-2">✓ API Key Generated Successfully!</h3>
                <p class="text-gray-700 mb-3"><strong>Name:</strong> <?php echo htmlspecialchars($newApiKeyName); ?></p>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Your API Key:</label>
                    <div class="flex items-center gap-2">
                        <input type="text" 
                               id="new-api-key" 
                               value="<?php echo htmlspecialchars($newApiKey); ?>" 
                               readonly 
                               class="flex-1 p-2 border border-gray-300 rounded bg-gray-50 font-mono text-sm">
                        <button onclick="copyApiKey()" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                            Copy
                        </button>
                    </div>
                </div>
                <p class="text-sm text-red-600 font-semibold">⚠️ Save this key now! You won't be able to see it again.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="bg-green-50 text-green-600 p-4 rounded mb-5"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-50 text-red-700 p-4 rounded mb-5"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
            <div class="mb-5 p-4 bg-green-50 border border-green-500 rounded text-green-800">Global settings saved.</div>
        <?php endif; ?>
        <?php if ($settingsError): ?>
            <div class="mb-5 p-4 bg-red-50 border border-red-500 rounded text-red-800"><?php echo htmlspecialchars($settingsError); ?></div>
        <?php endif; ?>

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
                                        $createdIso = $createdTs !== false ? gmdate('c', $createdTs) : '';
                                        $lastLoginIso = $lastLoginTs !== false ? gmdate('c', $lastLoginTs) : null;
                                        $isCurrentUser = $user['id'] === ($_SESSION['admin_user_id'] ?? '');
                                        ?>
                                        <tr class="user-row border-b border-gray-300 hover:bg-gray-50 cursor-pointer" 
                                            onclick="editUser({
                                                id: '<?php echo addslashes($user['id']); ?>',
                                                firstname: '<?php echo addslashes($user['firstname']); ?>',
                                                lastname: '<?php echo addslashes($user['lastname']); ?>',
                                                email: '<?php echo addslashes($user['email']); ?>',
                                                type: '<?php echo $user['type']; ?>'
                                            })"
                                            data-user-id="<?php echo htmlspecialchars($user['id']); ?>" 
                                            data-name="<?php echo htmlspecialchars(strtolower($user['firstname'] . ' ' . $user['lastname'])); ?>" 
                                            data-email="<?php echo htmlspecialchars(strtolower($user['email'])); ?>" 
                                            data-type="<?php echo $userType; ?>" 
                                            data-date-created="<?php echo $createdTs !== false ? $createdTs : '0'; ?>" 
                                            data-date-last-login="<?php echo $lastLoginTs !== false ? $lastLoginTs : '0'; ?>">
                                            <td class="py-2 px-2 font-semibold text-gray-800 max-w-[12rem]">
                                                <span class="block truncate" title="<?php echo $fullName; ?>"><?php echo $fullName; ?><?php if ($isCurrentUser): ?> <span class="ml-1 text-xs bg-green-400 text-white px-1.5 py-0.5 rounded">You</span><?php endif; ?></span>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-600 max-w-[14rem]">
                                                <span class="block truncate" title="<?php echo $email; ?>"><?php echo $email; ?></span>
                                            </td>
                                            <td class="py-2 px-2">
                                                <span class="text-xs <?php echo $typeColors[$userType]; ?> text-white px-2 py-1 rounded"><?php echo $typeLabels[$userType]; ?></span>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap">
                                                <?php if ($createdIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($createdIso); ?>"><?php echo date('M d, Y H:i', $createdTs); ?></span><?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap">
                                                <?php if ($lastLoginIso !== null): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($lastLoginIso); ?>"><?php echo date('M d, Y H:i', $lastLoginTs); ?></span><?php else: ?>Never<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-right">
                                                <div class="flex gap-2 justify-end">
                                                    <?php if (!$isCurrentUser): ?>
                                                        <form method="POST" action="" class="inline" onsubmit="event.stopPropagation(); return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                                            <button type="submit" class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded" onclick="event.stopPropagation()">Delete</button>
                                                        </form>
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
                                        $keyCreatedIso = $keyCreatedTs !== false ? gmdate('c', $keyCreatedTs) : '';
                                        ?>
                                        <p><strong>Created:</strong> <?php if ($keyCreatedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyCreatedIso); ?>"><?php echo date('Y-m-d H:i:s', $keyCreatedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                        <?php if (!empty($key['last_used_at'])): ?>
                                            <?php $keyLastUsedTs = strtotime($key['last_used_at']); $keyLastUsedIso = $keyLastUsedTs !== false ? gmdate('c', $keyLastUsedTs) : ''; ?>
                                            <p><strong>Last Used:</strong> <?php if ($keyLastUsedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyLastUsedIso); ?>"><?php echo date('Y-m-d H:i:s', $keyLastUsedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                        <?php else: ?>
                                            <p><strong>Last Used:</strong> Never</p>
                                        <?php endif; ?>
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
                    <p class="text-sm text-gray-600 mb-4">Each constellation is a separate set of nodes and keywords. The default constellation (ID 0) cannot be deleted.</p>
                    <div id="copy-url-toast" class="hidden fixed top-4 right-4 z-50 bg-green-600 text-white px-4 py-3 rounded shadow-lg text-sm" role="status" aria-live="polite">URL copied to clipboard.</div>
                    <?php if (empty($constellations)): ?>
                        <p class="text-gray-600">No constellations found.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto border border-gray-300 rounded">
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr class="border-b-2 border-gray-400 bg-gray-100">
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">ID</th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">Name</th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">Tagline</th>
                                        <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($constellations as $c): ?>
                                        <?php
                                        $cId = (int)$c['id'];
                                        $isDefault = $cId === 0;
                                        $cTagline = isset($c['tagline']) ? (string)$c['tagline'] : '';
                                        $viewRel = $cId === 0 ? '../index.php' : '../index.php?constellation_id=' . $cId;
                                        ?>
                                        <tr class="constellation-row border-b border-gray-300 hover:bg-gray-50 cursor-pointer" 
                                            onclick="editConstellation({
                                                id: '<?php echo $cId; ?>',
                                                name: '<?php echo addslashes($c['name']); ?>',
                                                tagline: '<?php echo addslashes($cTagline); ?>'
                                            })"
                                            data-id="<?php echo $cId; ?>" 
                                            data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>" 
                                            data-tagline="<?php echo htmlspecialchars(strtolower($cTagline)); ?>">
                                            <td class="py-2 px-2 font-mono text-gray-800"><?php echo $cId; ?></td>
                                            <td class="py-2 px-2 font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($c['name']); ?>
                                                <?php if ($isDefault): ?>
                                                    <span class="ml-2 text-xs bg-green-400 text-white px-1.5 py-0.5 rounded">Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-gray-600 text-sm max-w-xs truncate" title="<?php echo htmlspecialchars($cTagline); ?>"><?php echo htmlspecialchars($cTagline); ?></td>
                                            <td class="py-2 px-2 text-right">
                                                <div class="flex gap-2 justify-end items-center">
                                                    <?php if (!$isDefault): ?>
                                                        <form method="POST" action="" class="inline" onsubmit="event.stopPropagation(); return confirm('Are you sure you want to delete this constellation? Nodes and keywords in this constellation must be moved or deleted first.');">
                                                            <input type="hidden" name="action" value="delete_constellation">
                                                            <input type="hidden" name="id" value="<?php echo $cId; ?>">
                                                            <button type="submit" class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded" onclick="event.stopPropagation()">Delete</button>
                                                        </form>
                                                    <?php endif; ?>
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
                <p class="text-gray-600 mb-4 max-w-2xl">Localized content for the main app. English is required; Spanish and Portuguese are optional and fall back to English when empty.</p>
                <form method="post" action="" class="max-w-2xl">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="settings_lang" id="settings_lang" value="<?php echo htmlspecialchars($_GET['lang'] ?? 'en'); ?>">
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
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">Save settings</button>
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
        function toggleUserConstellationsSection() {
            const typeSelect = document.getElementById('type');
            const section = document.getElementById('user-constellations-section');
            if (!typeSelect || !section) return;
            const isEditor = typeSelect.value === '1';
            section.classList.toggle('hidden', !isEditor);
        }
        function toggleNewConstellationName() {
            const cb = document.getElementById('create_constellation');
            const wrap = document.getElementById('new-constellation-name-wrap');
            if (cb && wrap) wrap.classList.toggle('hidden', !cb.checked);
        }
        function initCreateUserForm() {
            const emailEl = document.getElementById('email');
            const nameEl = document.getElementById('new_constellation_name');
            const createCb = document.getElementById('create_constellation');
            if (createCb) createCb.addEventListener('change', toggleNewConstellationName);
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
        document.addEventListener('DOMContentLoaded', function() {
            const typeSelect = document.getElementById('type');
            if (typeSelect) typeSelect.addEventListener('change', toggleUserConstellationsSection);
            toggleNewConstellationName();
            initCreateUserForm();
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
                            html += `<button type="button" onclick="goToPage('${type}', ${i})" class="btn btn-xs ${i === state.currentPage ? 'btn-primary' : ''}">${i}</button>`;
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
                            html += `<button type="button" onclick="goToPage('${type}', ${i})" class="btn btn-sm ${i === state.currentPage ? 'btn-primary' : ''}">${i}</button>`;
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
            document.getElementById('modal-constellation-tagline').value = c.tagline;
            document.getElementById('constellation_modal').showModal();
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
                        span.textContent = new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
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
            button.classList.add('bg-green-500');
            button.classList.remove('bg-blue-500');
            
            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('bg-green-500');
                button.classList.add('bg-blue-500');
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
            ['name', 'email', 'type', 'date_created', 'date_last_login'].forEach(col => {
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
                    <span class="text-xs text-gray-500 mt-1 block">Login identifier and contact address.</span>
                </div>
                
                <div class="mb-4">
                    <label for="create-password" class="block mb-1.5 text-gray-800 font-medium">Password *</label>
                    <input type="password" id="create-password" name="password" required minlength="8" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Minimum 8 characters.</span>
                </div>
                
                <div class="mb-4">
                    <label for="create-type" class="block mb-1.5 text-gray-800 font-medium">User Type *</label>
                    <select id="create-type" name="type" required onchange="toggleCreateUserConstellations()" class="select select-bordered w-full bg-white">
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
                    <button type="submit" class="btn btn-primary">Create User</button>
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
                    <span class="text-xs text-gray-500 mt-1 block">Unique name for the new node network.</span>
                </div>
                
                <div class="mb-4">
                    <label for="create-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                    <input type="text" id="create-constellation-tagline" name="tagline" placeholder="e.g. Weaving memory" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Shown in the main view when this constellation is open.</span>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-primary">Create Constellation</button>
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
                </div>
                
                <div class="mb-4">
                    <label for="modal-password" class="block mb-1.5 text-gray-800 font-medium">Password (leave blank to keep current)</label>
                    <input type="password" id="modal-password" name="password" minlength="8" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>
                
                <div class="mb-4">
                    <label for="modal-type" class="block mb-1.5 text-gray-800 font-medium">User Type *</label>
                    <select id="modal-type" name="type" required onchange="toggleModalUserConstellations()" class="select select-bordered w-full bg-white">
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
                    <button type="submit" class="btn btn-primary">Update User</button>
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
                </div>
                
                <div class="mb-4">
                    <label for="modal-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                    <input type="text" id="modal-constellation-tagline" name="tagline" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-primary">Update Constellation</button>
                    <button type="button" class="btn" onclick="document.getElementById('constellation_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
</body>
</html>
