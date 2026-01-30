<?php
declare(strict_types=1);

// Set Content-Type header
header('Content-Type: text/html; charset=UTF-8');

require_once '../utils/auth.php';
requireEditorOrAdminLogin();

require_once '../config.php';

$pdo = getDB();

// Get default API key for API calls (using function from config.php)
$apiKey = getDefaultApiKey($pdo);

$projectAll = db_get_project_info_all_locales();
if (!$projectAll) {
    $projectAll = [
        'name' => 'Telaris', 'description' => '', 'iframe_back_text' => 'Go back',
        'alert_message' => "Close this window when you're done to go back.", 'edit_button_text' => 'Edit', 'loading_text' => 'Loading',
        'name_es' => '', 'name_pt' => '', 'description_es' => '', 'description_pt' => '',
        'iframe_back_text_es' => '', 'iframe_back_text_pt' => '', 'alert_message_es' => '', 'alert_message_pt' => '',
        'edit_button_text_es' => '', 'edit_button_text_pt' => '', 'loading_text_es' => '', 'loading_text_pt' => '',
    ];
}
$projectName = $projectAll['name'] ?? 'Telaris';
$projectTagline = db_get_project_description(); // Read directly from project_info.description
if ($projectTagline === '' && isset($projectAll['description'])) {
    $projectTagline = (string)$projectAll['description'];
}
$projectIframeBackText = $projectAll['iframe_back_text'] ?? 'Go back';
$projectAlertMessage = $projectAll['alert_message'] ?? "Close this window when you're done to go back.";
$projectEditButtonText = $projectAll['edit_button_text'] ?? 'Edit';
$projectLoadingText = $projectAll['loading_text'] ?? 'Loading';
// Spanish & Portuguese (for Settings form)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $en = [
        'name' => trim((string) ($_POST['project_name'] ?? '')),
        'description' => trim((string) ($_POST['project_tagline'] ?? '')),
        'iframe_back_text' => trim((string) ($_POST['iframe_back_text'] ?? 'Go back')),
        'alert_message' => trim((string) ($_POST['alert_message'] ?? "Close this window when you're done to go back.")),
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
            $projectName = $en['name'];
            $projectTagline = $en['description'];
            $projectIframeBackText = $en['iframe_back_text'];
            $projectAlertMessage = $en['alert_message'];
            $projectEditButtonText = $en['edit_button_text'];
            $name_es = $es['name'];
            $name_pt = $pt['name'];
            $description_es = $es['description'];
            $description_pt = $pt['description'];
            $iframe_back_text_es = $es['iframe_back_text'];
            $iframe_back_text_pt = $pt['iframe_back_text'];
            $alert_message_es = $es['alert_message'];
            $alert_message_pt = $pt['alert_message'];
            $projectEditButtonText = $en['edit_button_text'];
            $projectLoadingText = $en['loading_text'];
            $edit_button_text_es = $es['edit_button_text'];
            $edit_button_text_pt = $pt['edit_button_text'];
            $loading_text_es = $es['loading_text'];
            $loading_text_pt = $pt['loading_text'];
        }
    } else {
        $settingsError = 'English app name, iframe button text, alert message, Edit button label, and Loading text are required.';
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
}

$userName = $_SESSION['admin_user_name'] ?? 'User';
$userType = (int)($_SESSION['admin_user_type'] ?? 0);
$isAdmin = isAdminLoggedIn(); // Explicitly check if user is admin (type 2 only)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>Edit Nodes - Telaris</title>
    <script src="../js/tailwind.min.js"></script>
</head>
<body class="font-sans bg-gray-100 min-h-screen">
    <div class="max-w-7xl mx-auto py-8 px-5">
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-gray-800 text-3xl font-semibold">Edit Nodes</h1>
                    <p class="text-gray-600 mt-1">Welcome, <?php echo htmlspecialchars($userName); ?> (<?php echo $isAdmin ? 'Admin' : 'Editor'; ?>)</p>
                </div>
                <div class="flex gap-3">
                    <a href="../index.php" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded">
                        View Network
                    </a>
                    <?php if ($isAdmin): ?>
                    <a href="../admin/index.php" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded">
                        Admin Console
                    </a>
                    <?php endif; ?>
                    <a href="../utils/logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded">
                        Logout
                    </a>
                </div>
            </div>
        </div>

        <?php if (!$apiKey): ?>
        <div class="mb-5 p-4 bg-red-50 border-2 border-red-500 rounded">
            <p class="text-red-800 font-semibold">⚠️ Error: No active API key found. Please contact an administrator.</p>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
        <div class="mb-5 p-4 bg-green-50 border border-green-500 rounded text-green-800">Settings saved.</div>
        <?php endif; ?>
        <?php if (isset($settingsError)): ?>
        <div class="mb-5 p-4 bg-red-50 border border-red-500 rounded text-red-800"><?php echo htmlspecialchars($settingsError); ?></div>
        <?php endif; ?>

        <!-- Messages -->
        <div id="message" class="hidden mb-5 p-4 rounded"></div>

        <!-- Tabs -->
        <div class="bg-white rounded-lg shadow-md mb-6">
            <div class="border-b border-gray-200">
                <nav class="flex">
                    <button onclick="showTab('add')" 
                            id="tab-add"
                            class="px-6 py-3 font-medium text-sm border-b-2 border-blue-500 text-blue-600">
                        Add New Node
                    </button>
                    <button onclick="showTab('list')" 
                            id="tab-list"
                            class="px-6 py-3 font-medium text-sm border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                        List Existing Nodes
                    </button>
                    <button onclick="showTab('settings')" 
                            id="tab-settings"
                            class="px-6 py-3 font-medium text-sm border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                        Global Settings
                    </button>
                </nav>
            </div>

            <!-- Add New Node Tab -->
            <div id="content-add" class="p-6">
                <form id="node-form" class="space-y-4" novalidate>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="node-name" class="block mb-1.5 text-gray-800 font-medium">Name *</label>
                            <input type="text" 
                                   id="node-name" 
                                   name="name" 
                                   required 
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="node-keywords" class="block mb-1.5 text-gray-800 font-medium">Keywords</label>
                            <input type="text" 
                                   id="node-keywords" 
                                   name="keywords" 
                                   placeholder="comma-separated"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            <span class="text-xs text-gray-500 mt-1 block">Separate keywords with commas</span>
                        </div>
                    </div>

                    <div>
                        <label for="node-description" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                        <textarea id="node-description" 
                                  name="description" 
                                  rows="3"
                                  class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                    </div>

                    <div>
                        <label for="node-url" class="block mb-1.5 text-gray-800 font-medium">URL</label>
                        <input type="url" 
                               id="node-url" 
                               name="url" 
                               placeholder="https://example.com"
                               class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block">URL to open when the node is clicked (optional)</span>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                            Add Node
                        </button>
                    </div>
                </form>
            </div>

            <!-- List Existing Nodes Tab -->
            <div id="content-list" class="p-6 hidden">
                <!-- Search Controls -->
                <div class="mb-6 flex flex-wrap items-center gap-4 p-4 bg-gray-50 rounded-lg">
                    <div class="flex items-center gap-2 flex-1 min-w-[200px]">
                        <label for="search-nodes" class="text-sm font-medium text-gray-700">Search:</label>
                        <input type="text" 
                               id="search-nodes" 
                               placeholder="Search nodes..." 
                               oninput="applySorting()"
                               class="flex-1 p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div id="nodes-list" class="space-y-0">
                    <!-- Header row -->
                    <div class="border-b-2 border-gray-400 bg-gray-100 py-2 mb-1 sticky top-0 z-10">
                        <div class="grid grid-cols-12 gap-3 text-xs font-semibold text-gray-700">
                            <div class="col-span-3 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('name')">
                                Name<span id="sort-indicator-name"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('url')">URL<span id="sort-indicator-url"></span></div>
                            <div class="col-span-3 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('keywords')">
                                Keywords<span id="sort-indicator-keywords"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('created_at')">
                                Created<span id="sort-indicator-created_at"></span>
                            </div>
                            <div class="col-span-2 text-right">Actions</div>
                        </div>
                    </div>
                    <p class="text-gray-500 p-4" id="loading-message">Loading nodes...</p>
                </div>
            </div>

            <!-- Settings Tab -->
            <div id="content-settings" class="p-6 hidden">
                <p class="text-gray-600 mb-4 max-w-2xl">Localized content for the main app. English is required; Spanish and Portuguese are optional and fall back to English when empty.</p>
                <form method="post" action="" class="max-w-2xl">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="settings_lang" id="settings_lang" value="<?php echo htmlspecialchars($_GET['lang'] ?? 'en'); ?>">
                    <div class="border border-gray-200 rounded-lg bg-white overflow-hidden">
                        <!-- Language tabs -->
                        <div class="border-b border-gray-200 bg-gray-50">
                            <nav class="flex">
                                <button type="button" onclick="showSettingsLang('en')" id="settings-lang-tab-en" class="px-5 py-3 font-medium text-sm border-b-2 border-blue-500 text-blue-600">
                                    English
                                </button>
                                <button type="button" onclick="showSettingsLang('es')" id="settings-lang-tab-es" class="px-5 py-3 font-medium text-sm border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                                    Spanish
                                </button>
                                <button type="button" onclick="showSettingsLang('pt')" id="settings-lang-tab-pt" class="px-5 py-3 font-medium text-sm border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                                    Portuguese
                                </button>
                            </nav>
                        </div>
                        <!-- English panel -->
                        <div id="settings-lang-en" class="p-6 space-y-4">
                            <div>
                                <label for="project_name" class="block mb-1.5 text-gray-800 font-medium">App name</label>
                                <input type="text" id="project_name" name="project_name" value="<?php echo htmlspecialchars($projectName); ?>" required
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Project title shown in the main view and in page metadata.</span>
                            </div>
                            <div>
                                <label for="project_tagline" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                                <input type="text" id="project_tagline" name="project_tagline" value="<?php echo htmlspecialchars($projectTagline); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Tagline or short description shown under the title and in page metadata.</span>
                            </div>
                            <div>
                                <label for="iframe_back_text" class="block mb-1.5 text-gray-800 font-medium">Iframe button text</label>
                                <input type="text" id="iframe_back_text" name="iframe_back_text" value="<?php echo htmlspecialchars($projectIframeBackText); ?>" required
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text on the “Go back” button in the link window.</span>
                            </div>
                            <div>
                                <label for="alert_message" class="block mb-1.5 text-gray-800 font-medium">Alert message</label>
                                <textarea id="alert_message" name="alert_message" rows="3" required
                                          class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($projectAlertMessage); ?></textarea>
                                <span class="text-xs text-gray-500 mt-1 block">Message when a link cannot be embedded.</span>
                            </div>
                            <div>
                                <label for="edit_button_text" class="block mb-1.5 text-gray-800 font-medium">Edit button label</label>
                                <input type="text" id="edit_button_text" name="edit_button_text" value="<?php echo htmlspecialchars($projectEditButtonText); ?>" required
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Label for the Edit link shown to editors on the main view.</span>
                            </div>
                            <div>
                                <label for="loading_text" class="block mb-1.5 text-gray-800 font-medium">Loading text</label>
                                <input type="text" id="loading_text" name="loading_text" value="<?php echo htmlspecialchars($projectLoadingText); ?>" required
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text shown in the loading overlay (e.g. &quot;Loading&quot;).</span>
                            </div>
                        </div>
                        <!-- Spanish panel -->
                        <div id="settings-lang-es" class="p-6 space-y-4 hidden">
                            <div>
                                <label for="project_name_es" class="block mb-1.5 text-gray-800 font-medium">App name</label>
                                <input type="text" id="project_name_es" name="project_name_es" value="<?php echo htmlspecialchars($name_es); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Project title shown in the main view and in page metadata.</span>
                            </div>
                            <div>
                                <label for="project_tagline_es" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                                <input type="text" id="project_tagline_es" name="project_tagline_es" value="<?php echo htmlspecialchars($description_es); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Tagline or short description shown under the title and in page metadata.</span>
                            </div>
                            <div>
                                <label for="iframe_back_text_es" class="block mb-1.5 text-gray-800 font-medium">Iframe button text</label>
                                <input type="text" id="iframe_back_text_es" name="iframe_back_text_es" value="<?php echo htmlspecialchars($iframe_back_text_es); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text on the &quot;Go back&quot; button in the link window.</span>
                            </div>
                            <div>
                                <label for="alert_message_es" class="block mb-1.5 text-gray-800 font-medium">Alert message</label>
                                <textarea id="alert_message_es" name="alert_message_es" rows="3"
                                          class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($alert_message_es); ?></textarea>
                                <span class="text-xs text-gray-500 mt-1 block">Message when a link cannot be embedded.</span>
                            </div>
                            <div>
                                <label for="edit_button_text_es" class="block mb-1.5 text-gray-800 font-medium">Edit button label</label>
                                <input type="text" id="edit_button_text_es" name="edit_button_text_es" value="<?php echo htmlspecialchars($edit_button_text_es); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Label for the Edit link shown to editors on the main view.</span>
                            </div>
                            <div>
                                <label for="loading_text_es" class="block mb-1.5 text-gray-800 font-medium">Loading text</label>
                                <input type="text" id="loading_text_es" name="loading_text_es" value="<?php echo htmlspecialchars($loading_text_es); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text shown in the loading overlay (e.g. &quot;Loading&quot;).</span>
                            </div>
                        </div>
                        <!-- Portuguese panel -->
                        <div id="settings-lang-pt" class="p-6 space-y-4 hidden">
                            <div>
                                <label for="project_name_pt" class="block mb-1.5 text-gray-800 font-medium">App name</label>
                                <input type="text" id="project_name_pt" name="project_name_pt" value="<?php echo htmlspecialchars($name_pt); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Project title shown in the main view and in page metadata.</span>
                            </div>
                            <div>
                                <label for="project_tagline_pt" class="block mb-1.5 text-gray-800 font-medium">Description</label>
                                <input type="text" id="project_tagline_pt" name="project_tagline_pt" value="<?php echo htmlspecialchars($description_pt); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Tagline or short description shown under the title and in page metadata.</span>
                            </div>
                            <div>
                                <label for="iframe_back_text_pt" class="block mb-1.5 text-gray-800 font-medium">Iframe button text</label>
                                <input type="text" id="iframe_back_text_pt" name="iframe_back_text_pt" value="<?php echo htmlspecialchars($iframe_back_text_pt); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text on the &quot;Go back&quot; button in the link window.</span>
                            </div>
                            <div>
                                <label for="alert_message_pt" class="block mb-1.5 text-gray-800 font-medium">Alert message</label>
                                <textarea id="alert_message_pt" name="alert_message_pt" rows="3"
                                          class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($alert_message_pt); ?></textarea>
                                <span class="text-xs text-gray-500 mt-1 block">Message when a link cannot be embedded.</span>
                            </div>
                            <div>
                                <label for="edit_button_text_pt" class="block mb-1.5 text-gray-800 font-medium">Edit button label</label>
                                <input type="text" id="edit_button_text_pt" name="edit_button_text_pt" value="<?php echo htmlspecialchars($edit_button_text_pt); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Label for the Edit link shown to editors on the main view.</span>
                            </div>
                            <div>
                                <label for="loading_text_pt" class="block mb-1.5 text-gray-800 font-medium">Loading text</label>
                                <input type="text" id="loading_text_pt" name="loading_text_pt" value="<?php echo htmlspecialchars($loading_text_pt); ?>"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Text shown in the loading overlay (e.g. &quot;Loading&quot;).</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">Save settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const API_KEY = <?php echo $apiKey !== null ? json_encode($apiKey, JSON_THROW_ON_ERROR) : 'null'; ?>;
        const API_BASE = '../api/nodes.php';

        let editingNodeId = null;

        // Show message
        function showMessage(text, type = 'success') {
            const messageDiv = document.getElementById('message');
            messageDiv.className = `mb-5 p-4 rounded ${type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`;
            messageDiv.textContent = text;
            messageDiv.classList.remove('hidden');
            setTimeout(() => {
                messageDiv.classList.add('hidden');
            }, 5000);
        }

        // Load nodes
        async function loadNodes() {
            const listDiv = document.getElementById('nodes-list');
            if (!listDiv) {
                return;
            }

            // Show loading state
            const loadingMsg = listDiv.querySelector('#loading-message');
            if (loadingMsg) {
                loadingMsg.textContent = 'Loading nodes...';
            } else {
                listDiv.innerHTML = '<div class="border-b-2 border-gray-400 bg-gray-100 py-2 mb-1 sticky top-0 z-10"><div class="grid grid-cols-12 gap-3 text-xs font-semibold text-gray-700"><div class="col-span-3 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'name\')">Name<span id="sort-indicator-name"></span></div><div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'url\')">URL<span id="sort-indicator-url"></span></div><div class="col-span-3 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'keywords\')">Keywords<span id="sort-indicator-keywords"></span></div><div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'created_at\')">Created<span id="sort-indicator-created_at"></span></div><div class="col-span-2 text-right">Actions</div></div></div><p class="text-gray-500 p-4" id="loading-message">Loading nodes...</p>';
            }

            // Check if API key exists
            if (!API_KEY) {
                listDiv.innerHTML = '<p class="text-red-600">Error: API key is missing. Please contact an administrator.</p>';
                return;
            }

            try {
                // Add timeout to fetch request
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
                
                let response;
                try {
                    response = await fetch(API_BASE, {
                        headers: {
                            'X-API-Key': API_KEY
                        },
                        signal: controller.signal
                    });
                    clearTimeout(timeoutId);
                } catch (fetchError) {
                    clearTimeout(timeoutId);
                    if (fetchError.name === 'AbortError') {
                        throw new Error('Request timeout: The server took too long to respond');
                    }
                    throw fetchError;
                }
                
                // Get response text first to handle both JSON and non-JSON errors
                const responseText = await response.text();
                
                if (!response.ok) {
                    let errorMessage = `HTTP ${response.status}: ${response.statusText}`;
                    try {
                        const errorData = JSON.parse(responseText);
                        errorMessage = errorData.error || errorData.message || errorMessage;
                    } catch (e) {
                        // Not JSON, use the text directly
                        errorMessage = responseText.substring(0, 200) || errorMessage;
                    }
                    throw new Error(errorMessage);
                }
                
                // Parse JSON response
                let nodes;
                try {
                    nodes = JSON.parse(responseText);
                } catch (e) {
                    throw new Error('Invalid JSON response from server');
                }
                
                if (!Array.isArray(nodes)) {
                    throw new Error('Invalid response format: expected array, got ' + typeof nodes);
                }
                
                // Store nodes for sorting
                allNodes = nodes;
                
                // Check if this is the initial load and we should set default tab
                const urlParams = new URLSearchParams(window.location.search);
                if (!urlParams.has('tab')) {
                    // No tab specified in URL, set default based on whether nodes exist
                    const defaultTab = nodes.length > 0 ? 'list' : 'add';
                    // Update URL without reload first
                    urlParams.set('tab', defaultTab);
                    window.history.replaceState({}, '', '?' + urlParams.toString());
                    // Show the tab - if it's 'list', showTab will call loadNodes again, but that's okay
                    // If it's 'add', we just show it and don't display nodes
                    showTab(defaultTab);
                    // For list tab, showTab will handle loading, for add tab we're done
                    if (defaultTab === 'list') {
                        // showTab already called loadNodes, which will call applySorting
                        return;
                    }
                    // For add tab, we don't need to display nodes
                    return;
                }
                
                // Apply sorting if sort controls exist
                const sortBy = document.getElementById('sort-by');
                if (sortBy) {
                    applySorting();
                } else {
                    displayNodes(nodes);
                }
            } catch (error) {
                const errorMsg = error.message || 'Unknown error';
                if (listDiv) {
                    listDiv.innerHTML = 
                        `<p class="text-red-600 font-semibold">Error loading nodes</p>
                         <p class="text-red-600 text-sm mt-2">${escapeHtml(errorMsg)}</p>`;
                }
            }
        }

        // Display nodes
        function displayNodes(nodes) {
            const listDiv = document.getElementById('nodes-list');
            
            if (!listDiv) {
                return;
            }
            
            if (!Array.isArray(nodes)) {
                listDiv.innerHTML = '<p class="text-red-600">Error: Invalid data format received. Expected array, got ' + typeof nodes + '</p>';
                return;
            }
            
            if (nodes.length === 0) {
                // Remove header if no nodes
                const headerRow = listDiv.querySelector('.bg-gray-100');
                if (headerRow) {
                    headerRow.remove();
                }
                listDiv.innerHTML = '<p class="text-gray-500 p-4">No nodes found.</p>';
                return;
            }

            try {
                const html = nodes.map(node => {
                    if (!node || !node.id) {
                        return '';
                    }
                    
                    // Check if this node is being edited
                    if (editingNodeId === node.id) {
                        // Show inline edit form
                        return `
                <div class="border-2 border-blue-500 rounded p-4 bg-blue-50">
                    <h3 class="font-semibold text-gray-800 mb-4">Edit Node</h3>
                    <form class="space-y-4" onsubmit="saveInlineEdit(event, ${node.id})">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block mb-1.5 text-gray-800 font-medium text-sm">Name *</label>
                                <input type="text" 
                                       id="edit-name-${node.id}" 
                                       value="${escapeHtml(node.name)}" 
                                       required 
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block mb-1.5 text-gray-800 font-medium text-sm">Keywords</label>
                                <input type="text" 
                                       id="edit-keywords-${node.id}" 
                                       value="${node.keywords ? escapeHtml(node.keywords.join(', ')) : ''}" 
                                       placeholder="comma-separated"
                                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                                <span class="text-xs text-gray-500 mt-1 block">Separate keywords with commas</span>
                            </div>
                        </div>
                        <div>
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">Description</label>
                            <textarea id="edit-description-${node.id}" 
                                      rows="3"
                                      class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">${escapeHtml(node.description || '')}</textarea>
                        </div>
                        <div>
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">URL</label>
                            <input type="url" 
                                   id="edit-url-${node.id}" 
                                   value="${escapeHtml(node.url || '')}" 
                                   placeholder="https://example.com"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            <span class="text-xs text-gray-500 mt-1 block">URL to open when the node is clicked (optional)</span>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded text-sm cursor-pointer">
                                Save
                            </button>
                            <button type="button" onclick="cancelInlineEdit()" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded text-sm cursor-pointer">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
                        `;
                    }
                    
                    // Show normal display - compact spreadsheet-like layout
                    const createdDate = node.created_at ? new Date(node.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'N/A';
                    const descriptionTruncated = node.description ? (node.description.length > 80 ? escapeHtml(node.description.substring(0, 80)) + '...' : escapeHtml(node.description)) : '';
                    const keywordsDisplay = node.keywords && node.keywords.length > 0 
                        ? node.keywords.map(k => escapeHtml(k)).join(', ')
                        : 'No keywords';
                    return `
                <div class="border-b border-gray-300 hover:bg-gray-50 py-2">
                    <div class="grid grid-cols-12 gap-3 items-center text-sm">
                        <div class="col-span-3">
                            <div class="font-semibold text-gray-800 truncate" title="${escapeHtml(node.name)}">${escapeHtml(node.name)}</div>
                            ${descriptionTruncated ? `<div class="text-xs text-gray-500 truncate mt-0.5" title="${escapeHtml(node.description || '')}">${descriptionTruncated}</div>` : ''}
                        </div>
                        <div class="col-span-2">
                            ${node.url ? `<a href="${escapeHtml(node.url)}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline text-xs truncate block" title="${escapeHtml(node.url)}">${escapeHtml(node.url)}</a>` : '<span class="text-xs text-gray-400">—</span>'}
                        </div>
                        <div class="col-span-3">
                            <div class="text-xs text-gray-600 truncate" title="${keywordsDisplay}">${keywordsDisplay}</div>
                        </div>
                        <div class="col-span-2 text-xs text-gray-500">
                            ${createdDate}
                        </div>
                        <div class="col-span-2 flex gap-2 justify-end">
                            <button onclick="editNode(${node.id})" class="px-2 py-1 bg-blue-500 hover:bg-blue-600 text-white text-xs rounded">
                                Edit
                            </button>
                            <button onclick="deleteNode(${node.id}, '${escapeHtml(node.name)}')" class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
                    `;
                }).filter(html => html.length > 0).join('');
                
                // Preserve header if it exists, otherwise create it
                const headerRow = listDiv.querySelector('.bg-gray-100');
                let headerHTML = '';
                if (headerRow) {
                    headerHTML = headerRow.outerHTML;
                } else {
                    // Create header if it doesn't exist
                    headerHTML = `<div class="border-b-2 border-gray-400 bg-gray-100 py-2 mb-1 sticky top-0 z-10">
                        <div class="grid grid-cols-12 gap-3 text-xs font-semibold text-gray-700">
                            <div class="col-span-3 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('name')">
                                Name<span id="sort-indicator-name"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('url')">URL<span id="sort-indicator-url"></span></div>
                            <div class="col-span-3 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('keywords')">
                                Keywords<span id="sort-indicator-keywords"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('created_at')">
                                Created<span id="sort-indicator-created_at"></span>
                            </div>
                            <div class="col-span-2 text-right">Actions</div>
                        </div>
                    </div>`;
                    updateSortIndicators();
                }
                
                // Set innerHTML with header + nodes
                listDiv.innerHTML = headerHTML + html;
                updateSortIndicators();
            } catch (error) {
                listDiv.innerHTML = '<p class="text-red-600">Error displaying nodes: ' + escapeHtml(error.message) + '</p>';
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Store all nodes for sorting
        let allNodes = [];

        // Sort state
        let currentSortColumn = null;
        let currentSortOrder = 'asc'; // 'asc' or 'desc'
        
        // Sort by column header click
        function sortByColumn(column) {
            if (currentSortColumn === column) {
                // Toggle order if clicking same column
                currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                // New column, default to ascending
                currentSortColumn = column;
                currentSortOrder = 'asc';
            }
            updateSortIndicators();
            applySorting();
        }
        
        // Update sort indicators in header
        function updateSortIndicators() {
            // Reset all indicators
            ['name', 'url', 'keywords', 'created_at'].forEach(col => {
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
        
        // Apply sorting and filtering to displayed nodes
        function applySorting() {
            const searchInput = document.getElementById('search-nodes');
            
            if (!allNodes || allNodes.length === 0) {
                return;
            }
            
            // Get search query
            const searchQuery = searchInput ? searchInput.value.trim().toLowerCase() : '';
            
            // Filter nodes based on search query
            let filteredNodes = [...allNodes];
            if (searchQuery) {
                filteredNodes = allNodes.filter(node => {
                    // Search in name
                    const nameMatch = (node.name || '').toLowerCase().includes(searchQuery);
                    
                    // Search in description
                    const descriptionMatch = (node.description || '').toLowerCase().includes(searchQuery);
                    
                    // Search in URL
                    const urlMatch = (node.url || '').toLowerCase().includes(searchQuery);
                    
                    // Search in keywords
                    const keywordsMatch = node.keywords && node.keywords.length > 0
                        ? node.keywords.some(k => k.toLowerCase().includes(searchQuery))
                        : false;
                    
                    return nameMatch || descriptionMatch || urlMatch || keywordsMatch;
                });
            }
            
            // Apply sorting to filtered nodes if a column is selected
            let sortedNodes = filteredNodes;
            if (currentSortColumn) {
                sortedNodes = filteredNodes.sort((a, b) => {
                    let aVal, bVal;
                    
                    switch(currentSortColumn) {
                        case 'name':
                            aVal = (a.name || '').toLowerCase();
                            bVal = (b.name || '').toLowerCase();
                            break;
                        case 'created_at':
                            aVal = a.created_at ? new Date(a.created_at).getTime() : 0;
                            bVal = b.created_at ? new Date(b.created_at).getTime() : 0;
                            break;
                        case 'url':
                            aVal = (a.url || '').toLowerCase();
                            bVal = (b.url || '').toLowerCase();
                            break;
                        case 'keywords':
                            aVal = a.keywords && a.keywords.length > 0 ? a.keywords.join(', ').toLowerCase() : '';
                            bVal = b.keywords && b.keywords.length > 0 ? b.keywords.join(', ').toLowerCase() : '';
                            break;
                        default:
                            return 0;
                    }
                    
                    if (aVal < bVal) return currentSortOrder === 'asc' ? -1 : 1;
                    if (aVal > bVal) return currentSortOrder === 'asc' ? 1 : -1;
                    return 0;
                });
            }
            
            displayNodes(sortedNodes);
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

        // Edit node - show inline form
        async function editNode(id) {
            try {
                // Switch to list tab if not already there
                showTab('list');
                
                editingNodeId = id;
                // Reload nodes to show inline edit form
                await loadNodes();
                
                // Scroll to the edited node
                const editedNodeElement = document.querySelector(`#edit-name-${id}`);
                if (editedNodeElement) {
                    editedNodeElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    editedNodeElement.focus();
                }
            } catch (error) {
                showMessage('Error loading node for editing', 'error');
            }
        }
        
        // Save inline edit
        async function saveInlineEdit(event, nodeId) {
            event.preventDefault();
            
            const nodeName = document.getElementById(`edit-name-${nodeId}`).value.trim();
            if (!nodeName) {
                showMessage('Node name is required', 'error');
                return;
            }
            
            if (!API_KEY) {
                showMessage('API key is missing. Please contact an administrator.', 'error');
                return;
            }
            
            // Get current node data to preserve animation
            try {
                const response = await fetch(API_BASE, {
                    headers: {
                        'X-API-Key': API_KEY
                    }
                });
                
                if (!response.ok) {
                    throw new Error('Failed to load node');
                }
                
                const nodes = await response.json();
                const node = nodes.find(n => n.id === nodeId);
                
                if (!node) {
                    throw new Error('Node not found');
                }
                
                const formData = {
                    id: nodeId,
                    name: nodeName,
                    description: document.getElementById(`edit-description-${nodeId}`).value.trim() || null,
                    url: document.getElementById(`edit-url-${nodeId}`).value.trim() || null,
                    keywords: document.getElementById(`edit-keywords-${nodeId}`).value
                        .split(',')
                        .map(k => k.trim())
                        .filter(k => k.length > 0),
                    animation: node.animation // Preserve existing animation
                };
                
                const updateResponse = await fetch(API_BASE, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Key': API_KEY
                    },
                    body: JSON.stringify(formData)
                });
                
                const responseText = await updateResponse.text();
                
                if (!updateResponse.ok) {
                    let errorMessage = `HTTP ${updateResponse.status}: ${updateResponse.statusText}`;
                    try {
                        const errorData = JSON.parse(responseText);
                        errorMessage = errorData.error || errorData.message || errorMessage;
                    } catch (e) {
                        errorMessage = responseText.substring(0, 200) || errorMessage;
                    }
                    throw new Error(errorMessage);
                }
                
                // Parse successful response
                try {
                    JSON.parse(responseText);
                } catch (e) {
                    throw new Error('Invalid response from server');
                }
                
                showMessage('Node updated successfully');
                editingNodeId = null;
                loadNodes();
            } catch (error) {
                showMessage('Error saving node: ' + error.message, 'error');
            }
        }
        
        // Cancel inline edit
        function cancelInlineEdit() {
            editingNodeId = null;
            loadNodes();
        }

        // Delete node
        async function deleteNode(id, name) {
            if (!confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
                return;
            }

            try {
                const response = await fetch(`${API_BASE}?id=${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-API-Key': API_KEY
                    }
                });
                
                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.error || 'Failed to delete node');
                }
                
                showMessage('Node deleted successfully');
                loadNodes();
            } catch (error) {
                showMessage('Error deleting node: ' + error.message, 'error');
            }
        }

        // Reset add form
        function cancelEdit() {
            document.getElementById('node-form').reset();
        }

        // Wait for DOM to be ready
        document.addEventListener('DOMContentLoaded', () => {
            // Handle form submission (only for adding new nodes)
            const nodeForm = document.getElementById('node-form');
            if (nodeForm) {
                nodeForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                // Validate required fields
                const nodeName = document.getElementById('node-name').value.trim();
                if (!nodeName) {
                    showMessage('Node name is required', 'error');
                    return;
                }

                // Check API key
                if (!API_KEY) {
                    showMessage('API key is missing. Please contact an administrator.', 'error');
                    return;
                }

                // This form is only for adding new nodes
                const animation = generateRandomAnimation();
                
                const urlValue = document.getElementById('node-url').value.trim();
                const formData = {
                    name: nodeName,
                    description: document.getElementById('node-description').value.trim() || null,
                    url: urlValue || null,
                    keywords: document.getElementById('node-keywords').value
                        .split(',')
                        .map(k => k.trim())
                        .filter(k => k.length > 0),
                    animation: animation
                };

                try {
                    const url = API_BASE;
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-API-Key': API_KEY
                        },
                        body: JSON.stringify(formData)
                    });
                    
                    // Get response text first
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

                    // Parse successful response
                    try {
                        JSON.parse(responseText);
                    } catch (e) {
                        throw new Error('Invalid response from server');
                    }

                    showMessage('Node created successfully');
                    cancelEdit();
                    // Switch to list tab to see the new node
                    showTab('list');
                } catch (error) {
                    showMessage('Error saving node: ' + error.message, 'error');
                }
                });
            }

            // Load nodes on page load
            try {
                loadNodes().catch(error => {
                    const listDiv = document.getElementById('nodes-list');
                    if (listDiv) {
                        listDiv.innerHTML = '<p class="text-red-600">Fatal error loading nodes: ' + escapeHtml(error.message) + '</p>';
                    }
                });
            } catch (error) {
                const listDiv = document.getElementById('nodes-list');
                if (listDiv) {
                    listDiv.innerHTML = '<p class="text-red-600">Error: Could not load nodes. ' + escapeHtml(error.message) + '</p>';
                }
            }
        });
        
        // Settings language tabs (English / Spanish / Portuguese)
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
            // Hide all tab contents
            document.getElementById('content-add').classList.add('hidden');
            document.getElementById('content-list').classList.add('hidden');
            const contentSettings = document.getElementById('content-settings');
            if (contentSettings) contentSettings.classList.add('hidden');
            
            // Remove active styling from all tabs
            const tabs = ['add', 'list', 'settings'];
            tabs.forEach(tab => {
                const tabElement = document.getElementById('tab-' + tab);
                if (tabElement) {
                    tabElement.classList.remove('border-blue-500', 'text-blue-600');
                    tabElement.classList.add('border-transparent', 'text-gray-500');
                }
            });
            
            // Show selected tab content
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // If Global Settings, restore language sub-tab from URL
            if (tabName === 'settings') {
                const urlParams = new URLSearchParams(window.location.search);
                const lang = urlParams.get('lang');
                if (lang && ['en', 'es', 'pt'].includes(lang)) showSettingsLang(lang);
            }
            
            // Add active styling to selected tab
            const activeTab = document.getElementById('tab-' + tabName);
            if (activeTab) {
                activeTab.classList.remove('border-transparent', 'text-gray-500');
                activeTab.classList.add('border-blue-500', 'text-blue-600');
            }
            
            // If switching to list tab, ensure nodes are loaded
            if (tabName === 'list') {
                loadNodes();
            }
            
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        // Initialize tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Don't set tab here - let loadNodes determine default based on whether nodes exist
            // Only set tab if explicitly specified in URL
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('tab')) {
                showTab(urlParams.get('tab'));
            }
            // Otherwise, wait for loadNodes to set default based on node count
        });
        
        // Fallback: If DOMContentLoaded already fired
        if (document.readyState !== 'loading') {
            setTimeout(() => {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('tab')) {
                    showTab(urlParams.get('tab'));
                }
                // Otherwise, wait for loadNodes to set default based on node count
            }, 100);
        }
    </script>
</body>
</html>