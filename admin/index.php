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

// Load project info for Global Settings form
$projectAll = db_get_project_info_all_locales();
if (!$projectAll) {
    $projectAll = [
        'name' => 'Telaris', 'description' => '', 'iframe_back_text' => 'Go back',
        'alert_message' => "Close this window when you're done to go back to {APPNAME}.", 'edit_button_text' => 'Edit', 'loading_text' => 'Loading',
        'name_es' => '', 'name_pt' => '', 'description_es' => '', 'description_pt' => '',
        'iframe_back_text_es' => '', 'iframe_back_text_pt' => '', 'alert_message_es' => '', 'alert_message_pt' => '',
        'edit_button_text_es' => '', 'edit_button_text_pt' => '', 'loading_text_es' => '', 'loading_text_pt' => '',
    ];
}
$projectName = $projectAll['name'] ?? 'Telaris';
$projectTagline = $projectAll['description'] ?? '';
$projectIframeBackText = $projectAll['iframe_back_text'] ?? 'Go back';
$projectAlertMessage = $projectAll['alert_message'] ?? "Close this window when you're done to go back to {APPNAME}.";
$projectEditButtonText = $projectAll['edit_button_text'] ?? 'Edit';
$projectLoadingText = $projectAll['loading_text'] ?? 'Loading';
$name_es = $projectAll['name_es'] ?? '';
$name_pt = $projectAll['name_pt'] ?? '';
$description_es = $projectAll['description_es'] ?? '';
$description_pt = $projectAll['description_pt'] ?? '';
$iframe_back_text_es = $projectAll['iframe_back_text_es'] ?? '';
$iframe_back_text_pt = $projectAll['iframe_back_text_pt'] ?? '';
$alert_message_es = $projectAll['alert_message_es'] ?? '';
$alert_message_pt = $projectAll['alert_message_pt'] ?? '';
$edit_button_text_es = $projectAll['edit_button_text_es'] ?? 'Editar';
$edit_button_text_pt = $projectAll['edit_button_text_pt'] ?? 'Editar';
$loading_text_es = $projectAll['loading_text_es'] ?? 'Cargando';
$loading_text_pt = $projectAll['loading_text_pt'] ?? 'Carregando';

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
                $hashedPassword = hashPassword($password);
                $err = createUser(getDB(), $email, $hashedPassword, $firstname, $lastname, $type);
                if ($err !== null) {
                    throw new Exception($err);
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
            
            'logout' => (function(): void {
                logoutAdmin();
                header('Location: ../utils/login.php');
                exit();
            })(),
            
            'save_settings' => (function(): void {
                global $message, $error, $settingsError, $activeTab;
                global $projectName, $projectTagline, $projectIframeBackText, $projectAlertMessage, $projectEditButtonText, $projectLoadingText;
                global $name_es, $name_pt, $description_es, $description_pt, $iframe_back_text_es, $iframe_back_text_pt;
                global $alert_message_es, $alert_message_pt, $edit_button_text_es, $edit_button_text_pt, $loading_text_es, $loading_text_pt;
                $en = [
                    'name' => trim((string) ($_POST['project_name'] ?? '')),
                    'description' => trim((string) ($_POST['project_tagline'] ?? '')),
                    'iframe_back_text' => trim((string) ($_POST['iframe_back_text'] ?? 'Go back')),
                    'alert_message' => trim((string) ($_POST['alert_message'] ?? "Close this window when you're done to go back to {APPNAME}.")),
                    'edit_button_text' => trim((string) ($_POST['edit_button_text'] ?? 'Edit')),
                    'loading_text' => trim((string) ($_POST['loading_text'] ?? 'Loading')),
                ];
                $es = [
                    'name' => trim((string) ($_POST['project_name_es'] ?? '')),
                    'description' => trim((string) ($_POST['project_tagline_es'] ?? '')),
                    'iframe_back_text' => trim((string) ($_POST['iframe_back_text_es'] ?? '')),
                    'alert_message' => trim((string) ($_POST['alert_message_es'] ?? '')),
                    'edit_button_text' => trim((string) ($_POST['edit_button_text_es'] ?? 'Editar')),
                    'loading_text' => trim((string) ($_POST['loading_text_es'] ?? 'Cargando')),
                ];
                $pt = [
                    'name' => trim((string) ($_POST['project_name_pt'] ?? '')),
                    'description' => trim((string) ($_POST['project_tagline_pt'] ?? '')),
                    'iframe_back_text' => trim((string) ($_POST['iframe_back_text_pt'] ?? '')),
                    'alert_message' => trim((string) ($_POST['alert_message_pt'] ?? '')),
                    'edit_button_text' => trim((string) ($_POST['edit_button_text_pt'] ?? 'Editar')),
                    'loading_text' => trim((string) ($_POST['loading_text_pt'] ?? 'Carregando')),
                ];
                if ($en['name'] !== '' && $en['iframe_back_text'] !== '' && $en['alert_message'] !== '' && $en['edit_button_text'] !== '' && $en['loading_text'] !== '') {
                    try {
                        db_update_project_settings_with_locales($en, $es, $pt);
                        $lang = isset($_POST['settings_lang']) && in_array($_POST['settings_lang'], ['en', 'es', 'pt'], true) ? $_POST['settings_lang'] : 'en';
                        header('Location: index.php?tab=settings&saved=1&lang=' . urlencode($lang));
                        exit;
                    } catch (Throwable $e) {
                        $settingsError = 'Failed to save settings. Please try again. (' . htmlspecialchars($e->getMessage()) . ')';
                        $activeTab = 'settings';
                        $projectName = $en['name'];
                        $projectTagline = $en['description'];
                        $projectIframeBackText = $en['iframe_back_text'];
                        $projectAlertMessage = $en['alert_message'];
                        $projectEditButtonText = $en['edit_button_text'];
                        $projectLoadingText = $en['loading_text'];
                        $name_es = $es['name'];
                        $name_pt = $pt['name'];
                        $description_es = $es['description'];
                        $description_pt = $pt['description'];
                        $iframe_back_text_es = $es['iframe_back_text'];
                        $iframe_back_text_pt = $pt['iframe_back_text'];
                        $alert_message_es = $es['alert_message'];
                        $alert_message_pt = $pt['alert_message'];
                        $edit_button_text_es = $es['edit_button_text'];
                        $edit_button_text_pt = $pt['edit_button_text'];
                        $loading_text_es = $es['loading_text'];
                        $loading_text_pt = $pt['loading_text'];
                    }
                } else {
                    $settingsError = 'English app name, iframe button text, alert message, Edit button label, and Loading text are required.';
                    $activeTab = 'settings';
                    $projectName = $en['name'];
                    $projectTagline = $en['description'];
                    $projectIframeBackText = $en['iframe_back_text'];
                    $projectAlertMessage = $en['alert_message'];
                    $projectEditButtonText = $en['edit_button_text'];
                    $projectLoadingText = $en['loading_text'];
                    $name_es = $es['name'];
                    $name_pt = $pt['name'];
                    $description_es = $es['description'];
                    $description_pt = $pt['description'];
                    $iframe_back_text_es = $es['iframe_back_text'];
                    $iframe_back_text_pt = $pt['iframe_back_text'];
                    $alert_message_es = $es['alert_message'];
                    $alert_message_pt = $pt['alert_message'];
                    $edit_button_text_es = $es['edit_button_text'];
                    $edit_button_text_pt = $pt['edit_button_text'];
                    $loading_text_es = $es['loading_text'];
                    $loading_text_pt = $pt['loading_text'];
                }
            })(),
            
            default => throw new RuntimeException('Invalid action')
        };
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all API keys and users
$apiKeys = db_get_api_keys();
$users = db_get_users();

// Get user to edit if specified
$editUser = null;
if (isset($_GET['edit_user'])) {
    $editUserId = trim($_GET['edit_user']);
    foreach ($users as $user) {
        if ($user['id'] === $editUserId) {
            $editUser = $user;
            break;
        }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>Admin Console - Telaris</title>
    <script src="../js/tailwind.min.js"></script>
</head>
<body class="font-sans bg-gray-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-8 px-5">
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-gray-800 text-3xl font-semibold">Admin Console</h1>
                    <p class="text-gray-600 mt-1">Welcome, <?php echo htmlspecialchars($_SESSION['admin_user_name'] ?? 'Admin'); ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="../index.php" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded">
                        View Network
                    </a>
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
        <div class="bg-white rounded-lg shadow-md mb-6">
            <div class="border-b border-gray-200">
                <nav class="flex">
                    <button onclick="showTab('users')" 
                            id="tab-users"
                            class="px-6 py-3 font-medium text-sm border-b-2 <?php echo $activeTab === 'users' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                        Users
                    </button>
                    <button onclick="showTab('api-keys')" 
                            id="tab-api-keys"
                            class="px-6 py-3 font-medium text-sm border-b-2 <?php echo $activeTab === 'api-keys' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                        API Keys
                    </button>
                    <button onclick="showTab('settings')" 
                            id="tab-settings"
                            class="px-6 py-3 font-medium text-sm border-b-2 <?php echo $activeTab === 'settings' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                        Global Settings
                    </button>
                    <button onclick="showTab('php-info')" 
                            id="tab-php-info"
                            class="px-6 py-3 font-medium text-sm border-b-2 <?php echo $activeTab === 'php-info' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                        PHP Information
                    </button>
                </nav>
            </div>

            <!-- Users Tab -->
            <div id="content-users" class="p-6 <?php echo $activeTab !== 'users' ? 'hidden' : ''; ?>">
                <!-- Create/Edit User Form (hidden by default; shown when New User clicked or when editing) -->
                <div id="user-form-panel" class="mb-8 p-4 bg-blue-50 border border-blue-200 rounded <?php echo $editUser ? '' : 'hidden'; ?>">
                    <h2 class="text-blue-800 text-xl font-semibold mb-4">
                        <?php echo $editUser ? 'Edit User' : 'Create New User'; ?>
                    </h2>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="<?php echo $editUser ? 'update_user' : 'create_user'; ?>">
                        <?php if ($editUser): ?>
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($editUser['id']); ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="firstname" class="block mb-1.5 text-gray-800 font-medium">First Name *</label>
                                <input type="text" 
                                       id="firstname" 
                                       name="firstname" 
                                       required 
                                       value="<?php echo htmlspecialchars($editUser['firstname'] ?? ''); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label for="lastname" class="block mb-1.5 text-gray-800 font-medium">Last Name *</label>
                                <input type="text" 
                                       id="lastname" 
                                       name="lastname" 
                                       required 
                                       value="<?php echo htmlspecialchars($editUser['lastname'] ?? ''); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="email" class="block mb-1.5 text-gray-800 font-medium">Email *</label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   required 
                                   value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div class="mb-4">
                            <label for="password" class="block mb-1.5 text-gray-800 font-medium">
                                Password <?php echo $editUser ? '(leave blank to keep current)' : '*'; ?>
                            </label>
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   <?php echo $editUser ? '' : 'required'; ?>
                                   minlength="8"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            <span class="text-xs text-gray-500 mt-1 block">Minimum 8 characters</span>
                        </div>
                        
                        <div class="mb-4">
                            <label for="type" class="block mb-1.5 text-gray-800 font-medium">User Type *</label>
                            <select id="type" 
                                    name="type" 
                                    required 
                                    class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <option value="1" <?php echo ($editUser['type'] ?? 0) == 1 ? 'selected' : ''; ?>>Editor</option>
                                <option value="2" <?php echo ($editUser['type'] ?? 0) == 2 ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <span class="text-xs text-gray-500 mt-1 block">
                                Editor: Can edit nodes | Admin: Full access
                            </span>
                        </div>
                        
                        <div class="flex gap-2">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                                <?php echo $editUser ? 'Update User' : 'Create User'; ?>
                            </button>
                            <?php if ($editUser): ?>
                                <a href="index.php?tab=users" class="bg-gray-500 hover:bg-gray-600 text-white py-2.5 px-6 rounded text-base cursor-pointer inline-block">
                                    Cancel
                                </a>
                            <?php else: ?>
                                <button type="button" onclick="document.getElementById('user-form-panel').classList.add('hidden');" class="bg-gray-500 hover:bg-gray-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                                    Cancel
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Users list -->
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <h2 class="text-gray-800 text-base font-semibold">Users (<?php echo count($users); ?>)</h2>
                        <a href="#" onclick="document.getElementById('user-form-panel').classList.remove('hidden'); return false;" class="text-blue-600 hover:text-blue-800 font-medium text-base">New User</a>
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
                                        <tr class="user-row border-b border-gray-300 hover:bg-gray-50" data-user-id="<?php echo htmlspecialchars($user['id']); ?>" data-name="<?php echo htmlspecialchars(strtolower($user['firstname'] . ' ' . $user['lastname'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($user['email'])); ?>" data-type="<?php echo $userType; ?>" data-date-created="<?php echo $createdTs !== false ? $createdTs : '0'; ?>" data-date-last-login="<?php echo $lastLoginTs !== false ? $lastLoginTs : '0'; ?>">
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
                                                    <a href="index.php?tab=users&edit_user=<?php echo urlencode($user['id']); ?>" class="px-2 py-1 bg-blue-500 hover:bg-blue-600 text-white text-xs rounded">Edit</a>
                                                    <?php if (!$isCurrentUser): ?>
                                                        <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                                            <button type="submit" class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded">Delete</button>
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
                        <div id="settings-lang-en" class="p-6 space-y-4 <?php echo ($_GET['lang'] ?? 'en') !== 'en' ? 'hidden' : ''; ?>">
                            <div>
                                <label for="project_name" class="block mb-1.5 text-gray-800 font-medium">App name</label>
                                <input type="text" id="project_name" name="project_name" value="<?php echo htmlspecialchars($projectName); ?>" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Project title shown in the main view and in page metadata.</span>
                            </div>
                            <div>
                                <label for="project_tagline" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                                <input type="text" id="project_tagline" name="project_tagline" value="<?php echo htmlspecialchars($projectTagline); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Tagline or short description shown under the title and in page metadata.</span>
                            </div>
                            <div>
                                <label for="iframe_back_text" class="block mb-1.5 text-gray-800 font-medium">Iframe button text</label>
                                <input type="text" id="iframe_back_text" name="iframe_back_text" value="<?php echo htmlspecialchars($projectIframeBackText); ?>" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text on the &quot;Go back&quot; button in the link window.</span>
                            </div>
                            <div>
                                <label for="alert_message" class="block mb-1.5 text-gray-800 font-medium">Alert message</label>
                                <textarea id="alert_message" name="alert_message" rows="3" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($projectAlertMessage); ?></textarea>
                                <span class="text-xs text-gray-500 mt-1 block">Message when a link cannot be embedded.</span>
                            </div>
                            <div>
                                <label for="edit_button_text" class="block mb-1.5 text-gray-800 font-medium">Edit button label</label>
                                <input type="text" id="edit_button_text" name="edit_button_text" value="<?php echo htmlspecialchars($projectEditButtonText); ?>" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Label for the Edit link shown to editors on the main view.</span>
                            </div>
                            <div>
                                <label for="loading_text" class="block mb-1.5 text-gray-800 font-medium">Loading text</label>
                                <input type="text" id="loading_text" name="loading_text" value="<?php echo htmlspecialchars($projectLoadingText); ?>" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text shown in the loading overlay (e.g. &quot;Loading&quot;).</span>
                            </div>
                        </div>
                        <div id="settings-lang-es" class="p-6 space-y-4 <?php echo ($_GET['lang'] ?? '') !== 'es' ? 'hidden' : ''; ?>">
                            <div>
                                <label for="project_name_es" class="block mb-1.5 text-gray-800 font-medium">App name</label>
                                <input type="text" id="project_name_es" name="project_name_es" value="<?php echo htmlspecialchars($name_es); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Project title shown in the main view and in page metadata.</span>
                            </div>
                            <div>
                                <label for="project_tagline_es" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                                <input type="text" id="project_tagline_es" name="project_tagline_es" value="<?php echo htmlspecialchars($description_es); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Tagline or short description shown under the title and in page metadata.</span>
                            </div>
                            <div>
                                <label for="iframe_back_text_es" class="block mb-1.5 text-gray-800 font-medium">Iframe button text</label>
                                <input type="text" id="iframe_back_text_es" name="iframe_back_text_es" value="<?php echo htmlspecialchars($iframe_back_text_es); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text on the &quot;Go back&quot; button in the link window.</span>
                            </div>
                            <div>
                                <label for="alert_message_es" class="block mb-1.5 text-gray-800 font-medium">Alert message</label>
                                <textarea id="alert_message_es" name="alert_message_es" rows="3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($alert_message_es); ?></textarea>
                                <span class="text-xs text-gray-500 mt-1 block">Message when a link cannot be embedded.</span>
                            </div>
                            <div>
                                <label for="edit_button_text_es" class="block mb-1.5 text-gray-800 font-medium">Edit button label</label>
                                <input type="text" id="edit_button_text_es" name="edit_button_text_es" value="<?php echo htmlspecialchars($edit_button_text_es); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Label for the Edit link shown to editors on the main view.</span>
                            </div>
                            <div>
                                <label for="loading_text_es" class="block mb-1.5 text-gray-800 font-medium">Loading text</label>
                                <input type="text" id="loading_text_es" name="loading_text_es" value="<?php echo htmlspecialchars($loading_text_es); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text shown in the loading overlay (e.g. &quot;Loading&quot;).</span>
                            </div>
                        </div>
                        <div id="settings-lang-pt" class="p-6 space-y-4 <?php echo ($_GET['lang'] ?? '') !== 'pt' ? 'hidden' : ''; ?>">
                            <div>
                                <label for="project_name_pt" class="block mb-1.5 text-gray-800 font-medium">App name</label>
                                <input type="text" id="project_name_pt" name="project_name_pt" value="<?php echo htmlspecialchars($name_pt); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Project title shown in the main view and in page metadata.</span>
                            </div>
                            <div>
                                <label for="project_tagline_pt" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                                <input type="text" id="project_tagline_pt" name="project_tagline_pt" value="<?php echo htmlspecialchars($description_pt); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Tagline or short description shown under the title and in page metadata.</span>
                            </div>
                            <div>
                                <label for="iframe_back_text_pt" class="block mb-1.5 text-gray-800 font-medium">Iframe button text</label>
                                <input type="text" id="iframe_back_text_pt" name="iframe_back_text_pt" value="<?php echo htmlspecialchars($iframe_back_text_pt); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text on the &quot;Go back&quot; button in the link window.</span>
                            </div>
                            <div>
                                <label for="alert_message_pt" class="block mb-1.5 text-gray-800 font-medium">Alert message</label>
                                <textarea id="alert_message_pt" name="alert_message_pt" rows="3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($alert_message_pt); ?></textarea>
                                <span class="text-xs text-gray-500 mt-1 block">Message when a link cannot be embedded.</span>
                            </div>
                            <div>
                                <label for="edit_button_text_pt" class="block mb-1.5 text-gray-800 font-medium">Edit button label</label>
                                <input type="text" id="edit_button_text_pt" name="edit_button_text_pt" value="<?php echo htmlspecialchars($edit_button_text_pt); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Label for the Edit link shown to editors on the main view.</span>
                            </div>
                            <div>
                                <label for="loading_text_pt" class="block mb-1.5 text-gray-800 font-medium">Loading text</label>
                                <input type="text" id="loading_text_pt" name="loading_text_pt" value="<?php echo htmlspecialchars($loading_text_pt); ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text shown in the loading overlay (e.g. &quot;Loading&quot;).</span>
                            </div>
                        </div>
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
        
        // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            document.getElementById('content-api-keys').classList.add('hidden');
            document.getElementById('content-users').classList.add('hidden');
            const contentSettings = document.getElementById('content-settings');
            if (contentSettings) contentSettings.classList.add('hidden');
            document.getElementById('content-php-info').classList.add('hidden');
            
            // Remove active styling from all tabs
            const tabs = ['api-keys', 'users', 'settings', 'php-info'];
            tabs.forEach(tab => {
                const tabElement = document.getElementById('tab-' + tab);
                if (tabElement) {
                    tabElement.classList.remove('border-blue-500', 'text-blue-600');
                    tabElement.classList.add('border-transparent', 'text-gray-500');
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
                activeTabEl.classList.remove('border-transparent', 'text-gray-500');
                activeTabEl.classList.add('border-blue-500', 'text-blue-600');
            }
            
            // Update URL without reload (but preserve edit_user if present)
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            if (tabName !== 'users') {
                url.searchParams.delete('edit_user');
            }
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
        });
        
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
        }
    </script>
</body>
</html>
