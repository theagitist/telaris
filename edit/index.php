<?php
declare(strict_types=1);

// Set Content-Type header
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../utils/auth.php';
requireEditorOrAdminLogin();

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self' https://cdn.jsdelivr.net https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/../config.php';

$appVersion = trim(@file_get_contents(__DIR__ . '/../VERSION') ?: '0.0.0');

$galaxyEditMessage = null;
$galaxyEditError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_constellation') {
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

$pastelPalette = [
    '#FEF2F2', '#F0FAF0', '#EFF6FF', '#FFF8F0', '#F8F5FF',
    '#F0FDFA', '#FEFEF0', '#FFF5F5', '#F5F5F7', '#F5FAE8',
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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>Edit Wormholes - <?php echo htmlspecialchars($projectName); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
</head>
<body class="font-sans bg-gray-100 min-h-screen">
    <div class="max-w-7xl mx-auto py-8 px-5">
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-gray-800 text-3xl font-semibold">Edit Wormholes</h1>
                    <p class="text-gray-600 mt-1">Welcome, <?php echo htmlspecialchars($userName); ?> (<?php echo $isAdmin ? 'Admin' : 'Editor'; ?>)</p>
                </div>
                <div class="flex items-center gap-2">
                    <label for="current-constellation" class="text-sm font-medium text-gray-700">Current Galaxy:</label>
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
                            <option value="all"<?php echo $currentConstellationParam === 'all' ? ' selected' : ''; ?>><?php echo $isAdmin ? 'All galaxies' : 'All my galaxies'; ?></option>
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
                            View
                        </button>
                        <button type="button" id="galaxy-settings-btn" onclick="openCurrentGalaxySettings()" class="btn btn-sm btn-outline join-item" title="Galaxy settings" style="display:none;">
                            Settings
                        </button>
                        <button type="button" onclick="copyCurrentConstellationUrl(this)" class="btn btn-sm btn-outline join-item" title="Copy galaxy URL">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="flex gap-3">
                    <?php if ($isAdmin): ?>
                    <a href="../admin/index.php" class="btn btn-neutral">
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


        <!-- Messages -->
        <div id="notification-container" class="fixed top-4 left-1/2 -translate-x-1/2 z-[2000] flex flex-col gap-2 w-full max-w-md pointer-events-none"></div>
        <div id="message" class="hidden"></div> <!-- Legacy hidden div to avoid JS errors if referenced elsewhere -->

        <!-- Bulk Actions Bar -->
        <div id="bulk-actions-bar" class="hidden sticky top-4 z-[30] bg-neutral text-neutral-content p-4 rounded-lg shadow-xl mb-6 flex items-center justify-between transition-all">
            <div class="flex items-center gap-4">
                <span class="font-bold"><span id="selected-count">0</span> wormholes selected</span>
                <div class="h-6 w-px bg-neutral-content/30"></div>
                <button onclick="clearSelection()" class="btn btn-sm btn-ghost normal-case font-normal hover:bg-white/10">Clear Selection</button>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="openBulkMoveModal()" class="btn btn-sm btn-outline text-white border-white/30 hover:bg-white/10 hover:border-white">Move Selected</button>
                <button onclick="openBulkDuplicateModal()" class="btn btn-sm btn-outline text-white border-white/30 hover:bg-white/10 hover:border-white">Duplicate Selected</button>
                <button onclick="bulkDelete()" class="btn btn-sm btn-error text-white">Delete Selected</button>
            </div>
        </div>

        <!-- Nodes List -->
        <div id="read-only-banner" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4 text-yellow-800 text-sm" style="display: none;">
            This galaxy was imported from Mocambos and is read-only. Use the Mocambos import tool to refresh its content.
        </div>
        <div class="bg-white rounded-lg shadow-md mb-6">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <h2 class="text-gray-800 text-xl font-semibold">Wormholes (<span id="tab-list-count">0</span>)</h2>
                        <button type="button" onclick="openCreateNodeModal()" class="node-edit-action text-blue-600 hover:text-blue-800 font-medium text-base">New Wormhole</button>
                        <button type="button" id="filter-touched-today-btn" onclick="toggleTouchedTodayFilter()" class="text-xs px-2.5 py-1 rounded-full border border-gray-300 text-gray-600 hover:border-gray-500 transition" title="Show only wormholes touched today">Touched today</button>
                        <button type="button" onclick="openBulkByKeywordModal()" id="bulk-by-keyword-btn" class="text-xs px-2.5 py-1 rounded-full border border-gray-300 text-gray-600 hover:border-gray-500 transition" title="Bulk delete or move every wormhole in this galaxy carrying a chosen keyword">Bulk by keyword…</button>
                        <button type="button" onclick="document.getElementById('shortcuts_modal').showModal()" class="text-xs px-2.5 py-1 rounded-full border border-gray-300 text-gray-600 hover:border-gray-500 transition" title="Keyboard shortcuts (? to open)">?</button>
                    </div>
                    
                    <!-- Top Pagination Container -->
                    <div id="nodes-pagination-header" class="flex-1 flex justify-center"></div>

                    <div class="flex items-center gap-2 min-w-[300px]">
                        <label for="search-nodes" class="text-sm font-medium text-gray-700">Search:</label>
                        <input type="text" 
                               id="search-nodes" 
                               placeholder="Search wormholes..." 
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
                                Name<span id="sort-indicator-name"></span>
                            </div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('node_type')">
                                Type<span id="sort-indicator-node_type"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('constellation_name')">
                                Galaxy<span id="sort-indicator-constellation_name"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('url')">URL<span id="sort-indicator-url"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('keywords')">
                                Keywords<span id="sort-indicator-keywords"></span>
                            </div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('created_at')">
                                Created<span id="sort-indicator-created_at"></span>
                            </div>
                            <div class="col-span-1 text-right">Actions</div>
                        </div>
                    </div>
                    <p class="text-gray-500 p-4" id="loading-message">Loading wormholes...</p>
                </div>
            </div>

        </div>
    </div>

    <script>
        const API_KEY = <?php echo $apiKey !== null ? json_encode($apiKey, JSON_THROW_ON_ERROR) : 'null'; ?>;
        const API_BASE = '../api/nodes.php';
        const CONSTELLATIONS_API = '../api/constellations.php';
        const CONSTELLATIONS = <?php echo json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'import_source' => $c['import_source'] ?? null], $constellations), JSON_THROW_ON_ERROR); ?>;

        /** Check if a constellation is imported (read-only). */
        function isImportedConstellation(constellationId) {
            const c = CONSTELLATIONS.find(x => x.id === constellationId);
            return c && c.import_source != null && c.import_source !== '';
        }

        function updateReadOnlyState() {
            const constellationEl = document.getElementById('current-constellation');
            const cid = constellationEl ? parseInt(constellationEl.value, 10) : NaN;
            const isImported = !isNaN(cid) && isImportedConstellation(cid);
            const readOnlyBanner = document.getElementById('read-only-banner');
            const createNodeSection = document.getElementById('create-node-section');
            const bulkActions = document.getElementById('bulk-actions-bar');
            if (readOnlyBanner) readOnlyBanner.style.display = isImported ? 'block' : 'none';
            if (createNodeSection) createNodeSection.style.display = isImported ? 'none' : '';
            document.querySelectorAll('.node-edit-action').forEach(el => el.style.display = isImported ? 'none' : '');
            document.querySelectorAll('.node-checkbox').forEach(el => el.style.display = isImported ? 'none' : '');
        }

        /** Constellations from API (all), populated at load for target dropdown when Portal is selected. */
        let allConstellations = [];

        (function fetchConstellationsAtStart() {
            if (!API_KEY) return;
            fetch(CONSTELLATIONS_API, { headers: { 'X-API-Key': API_KEY } })
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
            
            document.getElementById('bulk-move-count').textContent = count;
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
                            'X-API-Key': API_KEY,
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
                    showMessage(`Successfully moved ${successCount} wormholes.`);
                }
                if (errorCount > 0) {
                    showMessage(`Failed to move ${errorCount} wormholes.`, 'error');
                }

                selectedNodeIds.clear();
                updateBulkActionsBar();
                loadNodes();
            } catch (e) {
                showMessage('An error occurred during bulk move.', 'error');
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
                        headers: { 'X-API-Key': API_KEY }
                    });
                    if (!response.ok) return;
                    node = await response.json();
                } catch (e) { return; }
            }
            document.getElementById('duplicate-source-id').value = id;
            document.getElementById('duplicate-source-name').textContent = node.name;
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
                    headers: { 'X-API-Key': API_KEY, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ duplicate_from: sourceId, constellation_id: constellationId })
                });
                if (response.ok) {
                    showMessage('Wormhole duplicated successfully.');
                    document.getElementById('duplicate_node_modal').close();
                    loadNodes();
                } else {
                    const err = await response.json();
                    showMessage('Error: ' + (err.error || 'Failed to duplicate'), 'error');
                }
            } catch (e) {
                showMessage('An error occurred while duplicating.', 'error');
            }
        }

        function openBulkDuplicateModal() {
            const count = selectedNodeIds.size;
            if (count === 0) return;
            document.getElementById('bulk-duplicate-count').textContent = count;
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
                        headers: { 'X-API-Key': API_KEY, 'Content-Type': 'application/json' },
                        body: JSON.stringify({ duplicate_from: id, constellation_id: constellationId })
                    }).then(r => {
                        if (r.ok) successCount++;
                        else errorCount++;
                    }).catch(() => errorCount++)
                );

                await Promise.all(promises);

                if (successCount > 0) {
                    showMessage(`Successfully duplicated ${successCount} wormholes.`);
                }
                if (errorCount > 0) {
                    showMessage(`Failed to duplicate ${errorCount} wormholes.`, 'error');
                }

                selectedNodeIds.clear();
                updateBulkActionsBar();
                loadNodes();
            } catch (e) {
                showMessage('An error occurred during bulk duplicate.', 'error');
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

            confirmAction(`Are you sure you want to delete ${count} selected wormholes? This action cannot be undone.`, async () => {
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
                            headers: { 'X-API-Key': API_KEY }
                        }).then(r => {
                            if (r.ok) successCount++;
                            else errorCount++;
                        }).catch(() => errorCount++)
                    );

                    await Promise.all(promises);

                    if (successCount > 0) {
                        showMessage(`Successfully deleted ${successCount} wormholes.`);
                    }
                    if (errorCount > 0) {
                        showMessage(`Failed to delete ${errorCount} wormholes.`, 'error');
                    }

                    selectedNodeIds.clear();
                    updateBulkActionsBar();
                    loadNodes();
                } catch (e) {
                    showMessage('An error occurred during bulk deletion.', 'error');
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
                buttonEl.setAttribute('title', 'Copied!');
                // Using alert or a more subtle way since we don't have the toast div here yet
                showMessage('URL copied to clipboard');
                setTimeout(() => {
                    buttonEl.setAttribute('title', origTitle || 'Copy galaxy URL');
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
            const name = window.prompt('Name of the new galaxy:');
            if (name === null || name.trim() === '') return;
            try {
                const response = await fetch('create_constellation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
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
                showMessage('Galaxy "' + newName + '" created.');
            } catch (e) {
                showMessage('Error creating galaxy: ' + e.message, 'error');
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
                    <p class="text-lg">Retrieving wormholes...</p>
                </div>
            `;

            if (!API_KEY) {
                listDiv.innerHTML = '<p class="text-red-600">Error: API key is missing. Please contact an administrator.</p>';
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
                    headers: { 'X-API-Key': API_KEY }
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
                    throw new Error('Invalid JSON response from server');
                }

                if (!result.nodes || !Array.isArray(result.nodes)) {
                    throw new Error('Invalid response format');
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
                        `<p class="text-red-600 font-semibold">Error loading wormholes</p>
                         <p class="text-red-600 text-sm mt-2">${escapeHtml(errorMsg)}</p>`;
                }
            }
        }

        // Display nodes
        function displayNodes(nodes) {
            const listDiv = document.getElementById('nodes-list');
            if (!listDiv) return;
            
            if (!Array.isArray(nodes)) {
                listDiv.innerHTML = '<p class="text-red-600 p-4">Error: Invalid data format received.</p>';
                return;
            }
            
            if (nodes.length === 0) {
                listDiv.innerHTML = `
                    <div class="flex flex-col items-center justify-center py-12 text-gray-500 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200">
                        <svg class="w-12 h-12 mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                        <p class="text-lg font-medium">No wormholes found.</p>
                        <p class="text-sm">Try adjusting your search or add a new wormhole to get started.</p>
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
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'name\')">Name<span id="sort-indicator-name"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'node_type\')">Type<span id="sort-indicator-node_type"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'constellation_name\')">Galaxy<span id="sort-indicator-constellation_name"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'keywords\')">Keywords<span id="sort-indicator-keywords"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'is_accentuated\')" title="Accentuated Status">Acc<span id="sort-indicator-is_accentuated"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'created_at\')">Created<span id="sort-indicator-created_at"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn(\'updated_at\')">Updated<span id="sort-indicator-updated_at"></span></div>
                            <div class="col-span-1 text-right pr-2">Actions</div>
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
                        : '<span class="text-xs text-gray-400">No keywords</span>';
                    const constellationName = (node.constellation_name || 'Default');
                    const nodeType = node.node_type || 'object';
                    const typeLabel = nodeType === 'portal' ? 'Portal' : 'Object';
                    const typeBadgeClass = nodeType === 'portal' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700';
                    const targetConstellationList = allConstellations.length ? allConstellations : CONSTELLATIONS;
                    const targetConstellationName = (nodeType === 'portal' && node.target_constellation_id != null)
                        ? (targetConstellationList.find(c => c.id === node.target_constellation_id)?.name || ('#' + node.target_constellation_id))
                        : '';
                    const typeDisplay = nodeType === 'portal' && targetConstellationName
                        ? `<span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium ${typeBadgeClass}" title="Target: ${escapeHtml(targetConstellationName)}">${escapeHtml(typeLabel)}</span> <span class="text-xs text-gray-500 truncate block" title="${escapeHtml(targetConstellationName)}">→ ${escapeHtml(targetConstellationName)}</span>`
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
                                ${node.is_accentuated ? '<span class="text-[10px] bg-yellow-100 text-yellow-700 px-1 rounded border border-yellow-200 font-bold" title="Accentuated Wormhole">ACC</span>' : ''}
                                ${node.url ? '<span class="text-[10px] bg-blue-100 text-blue-700 px-1 rounded" title="Has URL">URL</span>' : ''}
                                ${node.description ? '<span class="text-[10px] bg-green-100 text-green-700 px-1 rounded" title="Has Description">DESC</span>' : ''}
                                ${node.image_url ? '<span class="text-[10px] bg-purple-100 text-purple-700 px-1 rounded" title="Has Image">IMG</span>' : ''}
                                ${node.embed_code ? '<span class="text-[10px] bg-pink-100 text-pink-700 px-1 rounded" title="Has Embed">EMB</span>' : ''}
                                ${node.audio_url ? '<span class="text-[10px] bg-orange-100 text-orange-700 px-1 rounded" title="Has Audio">AUD</span>' : ''}
                                ${node.video_url ? '<span class="text-[10px] bg-cyan-100 text-cyan-700 px-1 rounded" title="Has Video">VID</span>' : ''}
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
                            ${node.is_accentuated ? '<span class="text-yellow-600 font-bold" title="Accentuated">✓</span>' : '<span class="text-gray-300">—</span>'}
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
                                    <li><a onclick="event.stopPropagation(); viewNode(${node.id})" class="text-gray-700 text-xs">View Wormhole</a></li>
                                    <li><a onclick="event.stopPropagation(); viewConstellation(${node.constellation_id})" class="text-gray-700 text-xs">View Galaxy</a></li>
                                    <li class="border-t border-gray-100 mt-1 pt-1"><a onclick="event.stopPropagation(); editNode(${node.id})" class="text-gray-700 text-xs">Edit</a></li>
                                    <li><a onclick="event.stopPropagation(); openDuplicateModal(${node.id})" class="text-gray-700 text-xs">Duplicate</a></li>
                                    <li class="node-edit-action"><a onclick="event.stopPropagation(); deleteNode(${node.id}, '${escapeHtml(node.name)}')" class="text-red-600 text-xs">Delete</a></li>
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
                listDiv.innerHTML = '<p class="text-red-600">Error displaying wormholes: ' + escapeHtml(error.message) + '</p>';
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

        // View node - preview modal
        async function viewNode(id) {
            let node = allNodes.find(n => n.id === id);
            if (!node) {
                try {
                    const res = await fetch(`${NODES_API}?id=${id}`, { headers: { 'X-API-Key': API_KEY } });
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

            if (node.embed_code) {
                const tmp = document.createElement('div');
                tmp.innerHTML = node.embed_code;
                embed.innerHTML = '';
                tmp.querySelectorAll('iframe').forEach(iframe => {
                    const src = iframe.getAttribute('src') || '';
                    if (src.match(/^https?:\/\//i)) embed.appendChild(iframe.cloneNode(true));
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
                        headers: { 'X-API-Key': API_KEY }
                    });
                    if (!response.ok) throw new Error('Failed to fetch node');
                    node = await response.json();
                } catch (e) {
                    showMessage('Error loading wormhole: ' + e.message, 'error');
                    return;
                }
            }

            editingNodeId = id;
            
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

            // Toggle target constellation if portal
            toggleTargetConstellation(node.node_type || 'object', 'modal');
            if (node.node_type === 'portal') {
                document.getElementById('edit-target-constellation-modal').value = node.target_constellation_id || '';
            }

            document.getElementById('edit_modal').showModal();
        }
        
        // Save node edit from modal
        async function saveNodeEdit(event) {
            event.preventDefault();
            
            const nodeId = parseInt(document.getElementById('edit-id').value);
            const nodeName = document.getElementById('edit-name').value.trim();
            
            if (!nodeName) {
                showMessage('Wormhole name is required', 'error');
                return;
            }
            
            if (!API_KEY) {
                showMessage('API key is missing.', 'error');
                return;
            }

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

            handleNodeSubmit(formData, 'edit', 'PUT');
        }

        function handleNodeSubmit(formData, context, method = 'POST') {
            const submitBtn = document.getElementById(`${context}-submit-btn`);
            const loader = document.getElementById(`${context}-submit-loader`);
            const progressWrap = document.getElementById(`${context}-progress-wrap`);
            const progressBar = document.getElementById(`${context}-progress-bar`);
            const progressText = document.getElementById(`${context}-progress-text`);
            const modalId = context === 'edit' ? 'edit_modal' : 'create_node_modal';

            submitBtn.disabled = true;
            loader.classList.remove('hidden');
            
            // Only show progress if files are being uploaded
            const hasFiles = formData.has('image_file') || formData.has('audio_file') || formData.has('video_file') || formData.has('icon_file');
            if (hasFiles) progressWrap.classList.remove('hidden');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', API_BASE, true);
            xhr.setRequestHeader('X-API-Key', API_KEY);
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
                    let successMsg = `Wormhole ${context === 'edit' ? 'updated' : 'created'} successfully`;
                    try {
                        const resp = JSON.parse(xhr.responseText);
                        if (resp.notice) successMsg += '. ' + resp.notice;
                    } catch (e) {}
                    showMessage(successMsg);
                    loadNodes();
                } else {
                    let errorMsg = `Failed to ${context === 'edit' ? 'update' : 'create'} wormhole`;
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
                showMessage('Network error occurred during upload', 'error');
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

            confirmAction(`Are you sure you want to delete this uploaded ${type} file?`, async () => {
                try {
                    const response = await fetch(`${API_BASE}?id=${nodeId}&file_type=${type}`, {
                        method: 'DELETE',
                        headers: { 'X-API-Key': API_KEY }
                    });
                    
                    if (!response.ok) throw new Error('Failed to delete file');
                    
                    showMessage(`${type.charAt(0).toUpperCase() + type.slice(1)} file deleted`);
                    
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
                    showMessage('Error deleting file: ' + error.message, 'error');
                }
            });
        }

        // Delete node
        async function deleteNode(id, name) {
            confirmAction(`Are you sure you want to delete "${name}"? This action cannot be undone.`, async () => {
                try {
                    const response = await fetch(`${API_BASE}?id=${id}`, {
                        method: 'DELETE',
                        headers: {
                            'X-API-Key': API_KEY
                        }
                    });
                    
                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.error || 'Failed to delete wormhole');
                    }
                    
                    showMessage('Wormhole deleted successfully');
                    loadNodes();
                } catch (error) {
                    showMessage('Error deleting wormhole: ' + error.message, 'error');
                }
            });
        }

        // Open Create Node Modal
        function openCreateNodeModal() {
            const form = document.getElementById('create-node-form');
            form.reset();
            
            // Set initial constellation for Add form based on current selection
            const currentConstellation = document.getElementById('current-constellation');
            const nodeConstellation = document.getElementById('node-constellation');
            if (currentConstellation && nodeConstellation) {
                const val = currentConstellation.value;
                nodeConstellation.value = val === 'all' ? '0' : val;
            }

            keywordState['create'] = [];
            updateKeywordTags('create');
            toggleTargetConstellation('object', 'create');

            document.getElementById('create_node_modal').showModal();
        }

        // Save new node from modal
        async function saveNewNode(event) {
            event.preventDefault();
            
            const nodeName = document.getElementById('node-name').value.trim();
            if (!nodeName) {
                showMessage('Wormhole name is required', 'error');
                return;
            }

            if (!API_KEY) {
                showMessage('API key is missing.', 'error');
                return;
            }

            const submitBtn = document.getElementById('create-submit-btn');
            const loader = document.getElementById('create-submit-loader');
            submitBtn.disabled = true;
            loader.classList.remove('hidden');

            const animation = generateRandomAnimation();
            const constellationId = parseInt(document.getElementById('node-constellation').value);
            const nodeType = document.getElementById('node-type').value;
            
            const formData = new FormData();
            formData.append('name', nodeName);
            formData.append('description', document.getElementById('node-description').value.trim());
            formData.append('url', document.getElementById('node-url').value.trim());
            formData.append('embed_code', document.getElementById('node-embed-code').value.trim());
            formData.append('is_accentuated', document.getElementById('node-accentuated').checked ? 1 : 0);
            formData.append('show_keywords', document.getElementById('node-show-keywords').checked ? 1 : 0);
            formData.append('constellation_id', isNaN(constellationId) ? 0 : constellationId);
            formData.append('node_type', nodeType);

            if (nodeType === 'portal') {
                formData.append('target_constellation_id', document.getElementById('node-target-constellation').value);
            }
            formData.append('animation', JSON.stringify(animation));
            formData.append('keywords', (keywordState['create'] || []).join(','));

            // Icon (independent of the visual mutex).
            formData.append('icon_url', document.getElementById('node-icon-url').value.trim());
            const iconFile = document.getElementById('node-icon-file').files[0];
            if (iconFile) formData.append('icon_file', iconFile);

            // Credit/attribution is shared across all visual types now (not just image).
            formData.append('image_attribution', document.getElementById('node-image-attribution').value.trim());

            // Primary visual: send only the active tab's URL/file; clear the other two.
            const visualType = document.getElementById('create-visual-type')?.value || 'image';
            if (visualType === 'image') {
                formData.append('image_url', document.getElementById('node-image-url').value.trim());
                const newUseImage = document.getElementById('node-use-image-as-node');
                formData.append('use_image_as_node', (newUseImage && newUseImage.checked) ? 1 : 0);
                const imageFile = document.getElementById('node-image-file').files[0];
                if (imageFile) formData.append('image_file', imageFile);
                formData.append('video_url', '');
                formData.append('pdf_url', '');
            } else if (visualType === 'video') {
                formData.append('video_url', document.getElementById('node-video-url').value.trim());
                formData.append('video_autoplay', document.getElementById('node-video-autoplay').checked ? 1 : 0);
                const videoFile = document.getElementById('node-video-file').files[0];
                if (videoFile) formData.append('video_file', videoFile);
                formData.append('image_url', '');
                formData.append('use_image_as_node', 0);
                formData.append('pdf_url', '');
            } else {
                formData.append('pdf_url', document.getElementById('node-pdf-url').value.trim());
                const pdfFile = document.getElementById('node-pdf-file').files[0];
                if (pdfFile) formData.append('pdf_file', pdfFile);
                formData.append('image_url', '');
                formData.append('use_image_as_node', 0);
                formData.append('video_url', '');
            }

            // Audio (independent of the visual mutex).
            formData.append('audio_url', document.getElementById('node-audio-url').value.trim());
            formData.append('audio_autoplay', document.getElementById('node-audio-autoplay').checked ? 1 : 0);
            formData.append('audio_loop', document.getElementById('node-audio-loop').checked ? 1 : 0);
            const audioFile = document.getElementById('node-audio-file').files[0];
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
                        listDiv.innerHTML = '<p class="text-red-600">Fatal error loading wormholes: ' + escapeHtml(error.message) + '</p>';
                    }
                });
            } catch (error) {
                const listDiv = document.getElementById('nodes-list');
                if (listDiv) {
                    listDiv.innerHTML = '<p class="text-red-600">Error: Could not load wormholes. ' + escapeHtml(error.message) + '</p>';
                }
            }
        });
        

        
        // Keyword Tag Management
        const keywordState = {}; // Stores arrays of keywords for each context (nodeId or 'add')

        // Helper for pastel colors
        function getPastelColor(str) {
            const pastelColors = [
                'bg-red-100 text-red-700 border-red-200',
                'bg-orange-100 text-orange-700 border-orange-200',
                'bg-amber-100 text-amber-700 border-amber-200',
                'bg-yellow-100 text-yellow-700 border-yellow-200',
                'bg-lime-100 text-lime-700 border-lime-200',
                'bg-green-100 text-green-700 border-green-200',
                'bg-emerald-100 text-emerald-700 border-emerald-200',
                'bg-teal-100 text-teal-700 border-teal-200',
                'bg-cyan-100 text-cyan-700 border-cyan-200',
                'bg-sky-100 text-sky-700 border-sky-200',
                'bg-blue-100 text-blue-700 border-blue-200',
                'bg-indigo-100 text-indigo-700 border-indigo-200',
                'bg-violet-100 text-violet-700 border-violet-200',
                'bg-purple-100 text-purple-700 border-purple-200',
                'bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200',
                'bg-pink-100 text-pink-700 border-pink-200',
                'bg-rose-100 text-rose-700 border-rose-200'
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
                    headers: { 'X-API-Key': API_KEY }
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

            const buckets = [
                { key: 'current',  label: 'Already in this galaxy',                items: data.current  || [] },
                { key: 'siblings', label: 'In sibling galaxies (same [XX] prefix)', items: data.siblings || [] },
                { key: 'global',   label: 'Other keywords',                        items: data.global   || [] },
            ];

            const rows = [];
            buckets.forEach(b => {
                const matches = b.items.filter(item => {
                    if (taken.has(item.keyword.toLowerCase())) return false;
                    if (!f) return true;
                    return item.keyword.toLowerCase().includes(f);
                });
                if (matches.length === 0) return;
                rows.push(`<div class="px-3 py-1 text-[10px] uppercase tracking-wider text-gray-500 bg-gray-50 border-b border-gray-100">${escapeHtml(b.label)}</div>`);
                matches.slice(0, 12).forEach(m => {
                    const count = (typeof m.count === 'number' && m.count > 0) ? `<span class="text-xs text-gray-400 ml-2">${m.count}</span>` : '';
                    rows.push(`<div class="px-3 py-1.5 hover:bg-blue-50 cursor-pointer flex items-center justify-between" data-kw-suggest="${escapeHtml(m.keyword)}" data-kw-context="${escapeHtml(contextId)}"><span class="text-gray-800">${escapeHtml(m.keyword)}</span>${count}</div>`);
                });
            });

            if (rows.length === 0) {
                box.classList.add('hidden');
                box.innerHTML = '';
                return;
            }
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
                    listDiv.innerHTML = `<p class="text-red-600">Fatal error loading wormholes: ${escapeHtml(error.message)}</p>`;
                }
            });
            setupLiveValidation();
        });
    </script>
    <!-- Create Node Modal -->
    <dialog id="create_node_modal" class="modal">
        <div class="modal-box max-w-4xl bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Add New Wormhole</h3>
            </div>
            <form id="create-node-form" class="space-y-4 mt-4" onsubmit="saveNewNode(event)">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="node-name" class="block mb-1.5 text-gray-800 font-medium text-sm">Name *</label>
                        <input type="text" id="node-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span id="node-name-error" class="text-xs text-red-600 mt-1 hidden">This wormhole name already exists in this galaxy.</span>
                        <span class="text-xs text-gray-500 mt-1 block">Primary title of the wormhole shown in the network.</span>
                    </div>
                    <div>
                        <label for="node-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm">Galaxy</label>
                        <select id="node-constellation" name="constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php foreach ($constellations as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-xs text-gray-500 mt-1 block">Which galaxy this wormhole belongs to.</span>
                    </div>
                    <div>
                        <label for="node-type" class="block mb-1.5 text-gray-800 font-medium text-sm">Wormhole type</label>
                        <select id="node-type" name="node_type" onchange="toggleTargetConstellation(this.value, 'create')" class="select select-bordered select-sm w-full bg-white">
                            <option value="object">Object</option>
                            <option value="portal">Portal</option>
                        </select>
                        <span class="text-xs text-gray-500 mt-1 block">Object is a standard item; Portal links to another galaxy.</span>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Keywords</label>
                        <div id="keywords-container-create" class="relative flex flex-wrap gap-2 p-2 border border-gray-300 rounded bg-white focus-within:border-blue-500 transition-colors">
                            <input type="text" id="node-keywords-input" placeholder="Add keyword..."
                                   onkeydown="handleKeywordInput(event, 'create')"
                                   oninput="if(this.value.includes(',')) { addKeywords(this.value, 'create'); this.value = ''; } else { updateKeywordSuggestions('create'); }"
                                   onfocus="updateKeywordSuggestions('create')"
                                   autocomplete="off"
                                   class="flex-1 min-w-[120px] outline-none text-sm py-1 px-1">
                            <div id="keyword-suggestions-create" class="hidden absolute left-0 right-0 top-full mt-1 z-[100] max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg text-sm"></div>
                        </div>
                        <input type="hidden" id="node-keywords" name="keywords">
                        <span class="text-xs text-gray-500 mt-1 block">Type and press Enter or comma to add keywords. Suggestions surface keywords already used in this galaxy and in sibling galaxies sharing your <code>[XX]</code> prefix.</span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="node-accentuated" name="is_accentuated" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800">Accentuate Wormhole</span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1">Make this wormhole larger and more prominent in the network.</span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="node-show-keywords" name="show_keywords" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800">Show Keywords</span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1">Display this wormhole's keywords in its info window.</span>
                    </div>
                </div>
                <div id="create-target-constellation-wrap" class="hidden">
                    <div class="flex flex-wrap items-end gap-2 mb-2">
                        <div class="min-w-[200px] flex-1">
                            <label for="node-target-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm">Target Galaxy</label>
                            <select id="node-target-constellation" name="target_constellation_id" class="select select-bordered select-sm w-full bg-white">
                                <?php foreach ($constellations as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-xs text-gray-500 mt-1 block">The destination galaxy this portal leads to.</span>
                        </div>
                        <button type="button" onclick="createNewConstellation('create')" class="py-2.5 px-4 rounded text-sm border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 cursor-pointer whitespace-nowrap">Create New Galaxy</button>
                    </div>
                </div>
                <div>
                    <label for="node-description" class="block mb-1.5 text-gray-800 font-medium text-sm">Description</label>
                    <textarea id="node-description" name="description" rows="3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                    <span class="text-xs text-gray-500 mt-1 block">Detailed text displayed when the wormhole is selected.</span>
                </div>
                <div>
                    <label for="node-url" class="block mb-1.5 text-gray-800 font-medium text-sm">URL</label>
                    <input type="url" id="node-url" name="url" placeholder="https://example.com" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">URL to open when the wormhole is clicked (optional).</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Left column: Primary visual (Image / Video / PDF — mutually exclusive). -->
                    <div class="flex flex-col">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Primary visual</label>
                        <div class="tabs tabs-bordered mb-2">
                            <button type="button" id="create-image-tab" onclick="switchVisualTab('image', 'create')" class="tab tab-sm tab-active">Image</button>
                            <button type="button" id="create-video-tab" onclick="switchVisualTab('video', 'create')" class="tab tab-sm">Video (MP4)</button>
                            <button type="button" id="create-pdf-tab" onclick="switchVisualTab('pdf', 'create')" class="tab tab-sm">PDF</button>
                        </div>
                        <input type="hidden" id="create-visual-type" value="image">
                        <span class="text-xs text-gray-500 mt-0 mb-2 block">Pick one. Switching tabs and saving clears the others.</span>

                        <!-- Image content (default visible) -->
                        <div id="create-image-content">
                            <div class="flex items-center justify-between mb-1.5 gap-2">
                                <label for="node-image-url" class="text-gray-800 font-medium text-xs">Image URL / File</label>
                                <label class="label cursor-pointer justify-end gap-2 py-0">
                                    <span class="label-text text-xs text-gray-700">Use as wormhole icon</span>
                                    <input type="checkbox" id="node-use-image-as-node" name="use_image_as_node" class="toggle toggle-neutral toggle-sm">
                                </label>
                            </div>
                            <input type="text" id="node-image-url" name="image_url" placeholder="https://example.com/image.jpg" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="node-image-file" name="image_file" accept="image/*,video/*" class="text-xs">
                        </div>

                        <!-- Video content -->
                        <div id="create-video-content" class="hidden">
                            <input type="text" id="node-video-url" name="video_url" placeholder="https://example.com/video.mp4" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="node-video-file" name="video_file" accept="video/mp4" class="text-xs">
                            <label class="flex items-center gap-2 mt-2 text-xs text-gray-700">
                                <input type="checkbox" id="node-video-autoplay" name="video_autoplay" checked>
                                Autoplay video
                            </label>
                        </div>

                        <!-- PDF content -->
                        <div id="create-pdf-content" class="hidden">
                            <input type="text" id="node-pdf-url" name="pdf_url" placeholder="https://example.com/document.pdf" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="node-pdf-file" name="pdf_file" accept="application/pdf,.pdf" class="text-xs">
                            <span class="text-xs text-gray-500 mt-1 block">Upload a PDF or provide a link.</span>
                        </div>

                        <!-- Credit (applies to whichever visual is active). Stored on nodes.image_attribution. -->
                        <input type="text" id="node-image-attribution" name="image_attribution" placeholder="Credit / attribution..." class="w-full p-2 border border-gray-300 rounded text-xs focus:outline-none focus:border-blue-500 mt-3" maxlength="255">
                        <span class="text-xs text-gray-500 mt-0.5 block">Optional credit shown on the visual in the info box (image, video, or PDF).</span>
                    </div>

                    <!-- Right column: Icon (top), Audio (bottom — independent of the visual mutex). -->
                    <div class="flex flex-col gap-4">
                        <div>
                            <label for="node-icon-url" class="block mb-1.5 text-gray-800 font-medium text-sm">Icon URL / File</label>
                            <input type="text" id="node-icon-url" name="icon_url" placeholder="https://example.com/icon.png" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="node-icon-file" name="icon_file" accept="image/*" class="text-xs">
                            <span class="text-xs text-gray-500 mt-1 block">Custom icon displayed in the 3D scene (overrides theme icon).</span>
                        </div>
                        <div>
                            <label for="node-audio-url" class="block mb-1.5 text-gray-800 font-medium text-sm">Audio URL / File</label>
                            <input type="text" id="node-audio-url" name="audio_url" placeholder="https://example.com/audio.mp3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="node-audio-file" name="audio_file" accept="audio/*" class="text-xs">
                            <div class="flex items-center gap-4 mt-2">
                                <label class="flex items-center gap-2 text-xs text-gray-700">
                                    <input type="checkbox" id="node-audio-autoplay" name="audio_autoplay" checked>
                                    Autoplay
                                </label>
                                <label class="flex items-center gap-2 text-xs text-gray-700">
                                    <input type="checkbox" id="node-audio-loop" name="audio_loop">
                                    Loop
                                </label>
                            </div>
                            <span class="text-xs text-gray-500 mt-1 block">Independent of the primary visual — audio can pair with image, video, or PDF.</span>
                        </div>
                    </div>
                </div>
                <!-- Embed code field is hidden from the editor for now (unused in practice).
                     The textarea stays in the DOM so existing embed_code values round-trip on save. -->
                <div class="hidden">
                    <textarea id="node-embed-code" name="embed_code"></textarea>
                </div>
                <div id="create-progress-wrap" class="hidden space-y-2">
                    <div class="flex justify-between text-xs font-medium">
                        <span>Uploading...</span>
                        <span id="create-progress-text">0%</span>
                    </div>
                    <progress id="create-progress-bar" class="progress progress-neutral w-full" value="0" max="100"></progress>
                </div>
                <div class="modal-action">
                    <button type="submit" id="create-submit-btn" class="btn btn-neutral">
                        <span class="loading loading-spinner hidden" id="create-submit-loader"></span>
                        Add Wormhole
                    </button>
                    <button type="button" class="btn" onclick="document.getElementById('create_node_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <dialog id="edit_modal" class="modal">
        <div class="modal-box max-w-4xl bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl">Edit Wormhole</h3>
                <span id="edit-node-constellation-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <form id="edit-node-form" class="space-y-4 mt-4" onsubmit="saveNodeEdit(event)">
                <input type="hidden" id="edit-id" name="id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Name *</label>
                        <input type="text" id="edit-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span id="edit-name-error" class="text-xs text-red-600 mt-1 hidden">This wormhole name already exists in this galaxy.</span>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Galaxy</label>
                        <select id="edit-constellation" name="constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php foreach ($constellations as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Wormhole type</label>
                        <select id="edit-node-type" name="node_type" onchange="toggleTargetConstellation(this.value, 'modal')" class="select select-bordered select-sm w-full bg-white">
                            <option value="object">Object</option>
                            <option value="portal">Portal</option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Keywords</label>
                        <div id="keywords-container-modal" class="relative flex flex-wrap gap-2 p-2 border border-gray-300 rounded bg-white focus-within:border-blue-500 transition-colors">
                            <input type="text" id="edit-keywords-input-modal" placeholder="Add keyword..."
                                   onkeydown="handleKeywordInput(event, 'modal')"
                                   oninput="if(this.value.includes(',')) { addKeywords(this.value, 'modal'); this.value = ''; } else { updateKeywordSuggestions('modal'); }"
                                   onfocus="updateKeywordSuggestions('modal')"
                                   autocomplete="off"
                                   class="flex-1 min-w-[120px] outline-none text-sm py-1 px-1">
                            <div id="keyword-suggestions-modal" class="hidden absolute left-0 right-0 top-full mt-1 z-[100] max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg text-sm"></div>
                        </div>
                        <input type="hidden" id="edit-keywords-hidden" name="keywords">
                        <span class="text-xs text-gray-500 mt-1 block">Type and press Enter or comma to add keywords. Suggestions surface keywords already used in this galaxy and in sibling galaxies sharing your <code>[XX]</code> prefix.</span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="edit-accentuated" name="is_accentuated" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800">Accentuate Wormhole</span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1">Make this wormhole larger and more prominent in the network.</span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="edit-show-keywords" name="show_keywords" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800">Show Keywords</span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1">Display this wormhole's keywords in its info window.</span>
                    </div>
                </div>
                <div id="edit-target-constellation-wrap-modal" class="hidden">
                    <div class="flex flex-wrap items-end gap-2 mb-2">
                        <div class="min-w-[200px] flex-1">
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">Target Galaxy</label>
                            <select id="edit-target-constellation-modal" name="target_constellation_id" class="select select-bordered select-sm w-full bg-white"></select>
                        </div>
                        <button type="button" onclick="createNewConstellation('modal')" class="py-2.5 px-4 rounded text-sm border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 cursor-pointer whitespace-nowrap">Create New Galaxy</button>
                    </div>
                </div>
                <div>
                    <label class="block mb-1.5 text-gray-800 font-medium text-sm">Description</label>
                    <textarea id="edit-description" name="description" rows="3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                </div>
                <div>
                    <label class="block mb-1.5 text-gray-800 font-medium text-sm">URL</label>
                    <input type="url" id="edit-url" name="url" placeholder="https://example.com" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>
                <div class="divider text-gray-400 text-xs">Media</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Left column: Primary visual (Image / Video / PDF — mutually exclusive). -->
                    <div class="flex flex-col">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Primary visual</label>
                        <div class="tabs tabs-bordered mb-2">
                            <button type="button" id="edit-image-tab" onclick="switchVisualTab('image', 'edit')" class="tab tab-sm tab-active">Image</button>
                            <button type="button" id="edit-video-tab" onclick="switchVisualTab('video', 'edit')" class="tab tab-sm">Video (MP4)</button>
                            <button type="button" id="edit-pdf-tab" onclick="switchVisualTab('pdf', 'edit')" class="tab tab-sm">PDF</button>
                        </div>
                        <input type="hidden" id="edit-visual-type" value="image">
                        <span class="text-xs text-gray-500 mt-0 mb-2 block">Pick one. Switching tabs and saving clears the others.</span>

                        <!-- Image content -->
                        <div id="edit-image-content">
                            <div class="flex items-center justify-between mb-1.5 gap-2">
                                <label for="edit-image-url" class="text-gray-800 font-medium text-xs">Image URL / File</label>
                                <label class="label cursor-pointer justify-end gap-2 py-0">
                                    <span class="label-text text-xs text-gray-700">Use as wormhole icon</span>
                                    <input type="checkbox" id="edit-use-image-as-node" name="use_image_as_node" class="toggle toggle-neutral toggle-sm">
                                </label>
                            </div>
                            <div id="edit-image-file-wrap">
                                <input type="text" id="edit-image-url" name="image_url" placeholder="https://example.com/image.jpg" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-image-file" name="image_file" accept="image/*,video/*" class="text-xs">
                            </div>
                            <div id="edit-image-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-image-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('image')" class="btn btn-error btn-sm btn-outline">Delete</button>
                            </div>
                        </div>

                        <!-- Video content -->
                        <div id="edit-video-content" class="hidden">
                            <div id="edit-video-file-wrap">
                                <input type="text" id="edit-video-url" name="video_url" placeholder="https://example.com/video.mp4" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-video-file" name="video_file" accept="video/mp4" class="text-xs">
                            </div>
                            <div id="edit-video-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-video-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('video')" class="btn btn-error btn-sm btn-outline">Delete</button>
                            </div>
                            <label class="flex items-center gap-2 mt-2 text-xs text-gray-700">
                                <input type="checkbox" id="edit-video-autoplay" name="video_autoplay">
                                Autoplay video
                            </label>
                        </div>

                        <!-- PDF content -->
                        <div id="edit-pdf-content" class="hidden">
                            <div id="edit-pdf-file-wrap">
                                <input type="text" id="edit-pdf-url" name="pdf_url" placeholder="https://example.com/document.pdf" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-pdf-file" name="pdf_file" accept="application/pdf,.pdf" class="text-xs">
                            </div>
                            <div id="edit-pdf-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-pdf-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('pdf')" class="btn btn-error btn-sm btn-outline">Delete</button>
                            </div>
                        </div>

                        <!-- Credit (applies to whichever visual is active). Stored on nodes.image_attribution. -->
                        <input type="text" id="edit-image-attribution" name="image_attribution" placeholder="Credit / attribution..." class="w-full p-2 border border-gray-300 rounded text-xs focus:outline-none focus:border-blue-500 mt-3" maxlength="255">
                        <span class="text-xs text-gray-500 mt-0.5 block">Optional credit shown on the visual in the info box (image, video, or PDF).</span>
                    </div>

                    <!-- Right column: Icon (top), Audio (middle), Embed code (bottom — independent of mutex). -->
                    <div class="flex flex-col gap-4">
                        <div id="edit-icon-container">
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">Icon URL / File</label>
                            <div id="edit-icon-file-wrap">
                                <input type="text" id="edit-icon-url" name="icon_url" placeholder="https://example.com/icon.png" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-icon-file" name="icon_file" accept="image/*" class="text-xs">
                            </div>
                            <div id="edit-icon-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-icon-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('icon')" class="btn btn-error btn-sm btn-outline">Delete</button>
                            </div>
                            <span class="text-xs text-gray-500 mt-1 block">Custom icon displayed in the 3D scene (overrides theme icon).</span>
                        </div>

                        <div>
                            <label for="edit-audio-url" class="block mb-1.5 text-gray-800 font-medium text-sm">Audio URL / File</label>
                            <div id="edit-audio-file-wrap">
                                <input type="text" id="edit-audio-url" name="audio_url" placeholder="https://example.com/audio.mp3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                                <input type="file" id="edit-audio-file" name="audio_file" accept="audio/*" class="text-xs">
                            </div>
                            <div id="edit-audio-existing" class="hidden flex items-center gap-2 mb-2">
                                <input type="text" id="edit-audio-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                                <button type="button" onclick="deleteModalFile('audio')" class="btn btn-error btn-sm btn-outline">Delete</button>
                            </div>
                            <div class="flex items-center gap-4 mt-2">
                                <label class="flex items-center gap-2 text-xs text-gray-700">
                                    <input type="checkbox" id="edit-audio-autoplay" name="audio_autoplay">
                                    Autoplay
                                </label>
                                <label class="flex items-center gap-2 text-xs text-gray-700">
                                    <input type="checkbox" id="edit-audio-loop" name="audio_loop">
                                    Loop
                                </label>
                            </div>
                            <span class="text-xs text-gray-500 mt-1 block">Independent of the primary visual — pairs with image, video, or PDF.</span>
                        </div>

                        <!-- Embed code is hidden from the editor for now (unused in practice).
                             The textarea stays in the DOM so existing embed_code values round-trip on save. -->
                        <div class="hidden">
                            <textarea id="edit-embed-code" name="embed_code"></textarea>
                        </div>
                    </div>
                </div>
                <div id="edit-progress-wrap" class="hidden space-y-2">
                    <div class="flex justify-between text-xs font-medium">
                        <span>Uploading...</span>
                        <span id="edit-progress-text">0%</span>
                    </div>
                    <progress id="edit-progress-bar" class="progress progress-neutral w-full" value="0" max="100"></progress>
                </div>
                <div class="modal-action">
                    <button type="submit" id="edit-submit-btn" class="btn btn-neutral">
                        <span class="loading loading-spinner hidden" id="edit-submit-loader"></span>
                        Update Wormhole
                    </button>
                    <button type="button" class="btn" onclick="document.getElementById('edit_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <!-- Delete Confirmation Modal -->
    <dialog id="delete_confirm_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-error text-error-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Confirm Deletion</h3>
            </div>
            <p id="delete-confirm-message" class="text-gray-600 mb-6 mt-4"></p>
            <div class="modal-action">
                <button id="delete-confirm-btn" class="btn btn-error text-white">Delete</button>
                <button type="button" class="btn" onclick="document.getElementById('delete_confirm_modal').close()">Cancel</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Bulk Move Modal -->
    <dialog id="bulk_move_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Move Wormholes</h3>
            </div>
            <p class="text-gray-600 mb-4 mt-4">Move <span id="bulk-move-count" class="font-bold">0</span> selected wormholes to another galaxy.</p>

            <div class="mb-6">
                <label for="bulk-move-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm">Destination Galaxy</label>
                <select id="bulk-move-constellation" class="select select-bordered select-sm w-full bg-white">
                    <?php foreach ($constellations as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-action">
                <button onclick="bulkMove()" class="btn btn-neutral">Move Wormholes</button>
                <button type="button" class="btn" onclick="document.getElementById('bulk_move_modal').close()">Cancel</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Duplicate Node Modal -->
    <dialog id="duplicate_node_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl">Duplicate Wormhole</h3>
                <span id="duplicate-node-constellation-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <input type="hidden" id="duplicate-source-id" value="">
            <p class="text-gray-600 mb-4 mt-4">Duplicate "<span id="duplicate-source-name" class="font-semibold"></span>" to:</p>

            <div class="mb-6">
                <label for="duplicate-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm">Destination Galaxy</label>
                <select id="duplicate-constellation" class="select select-bordered select-sm w-full bg-white">
                    <?php foreach ($constellations as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-action">
                <button onclick="confirmDuplicate()" class="btn btn-neutral">Duplicate</button>
                <button type="button" class="btn" onclick="document.getElementById('duplicate_node_modal').close()">Cancel</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Bulk Duplicate Modal -->
    <dialog id="bulk_duplicate_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl">Duplicate Wormholes</h3>
            </div>
            <p class="text-gray-600 mb-4 mt-4">Duplicate <span id="bulk-duplicate-count" class="font-bold">0</span> selected wormholes to:</p>

            <div class="mb-6">
                <label for="bulk-duplicate-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm">Destination Galaxy</label>
                <select id="bulk-duplicate-constellation" class="select select-bordered select-sm w-full bg-white">
                    <?php foreach ($constellations as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-action">
                <button onclick="bulkDuplicate()" class="btn btn-neutral">Duplicate Wormholes</button>
                <button type="button" class="btn" onclick="document.getElementById('bulk_duplicate_modal').close()">Cancel</button>
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
                        <a id="preview-url-button" href="#" target="_blank" class="block w-full py-3 bg-transparent border border-white/20 text-[#00ffcc] text-xs font-bold uppercase tracking-widest text-center transition-all hover:bg-white/10 rounded no-underline">
                            Open Link
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
                <h3 class="font-bold text-xl">Bulk action by keyword</h3>
            </div>
            <p class="text-sm text-gray-600 mt-4">
                Pick a keyword in the current galaxy. Then choose to delete every wormhole carrying it, or move them all to another galaxy.
            </p>

            <div class="mt-4">
                <label for="bulk-kw-keyword" class="block mb-1.5 text-gray-800 font-medium text-sm">Keyword</label>
                <select id="bulk-kw-keyword" class="select select-bordered select-sm w-full bg-white">
                    <option value="">Loading…</option>
                </select>
            </div>

            <div class="mt-4">
                <label class="block mb-1.5 text-gray-800 font-medium text-sm">Action</label>
                <div class="space-y-1">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="bulk-kw-op" value="delete" class="radio radio-neutral radio-sm" checked>
                        <span>Delete the matching wormholes</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="bulk-kw-op" value="move" class="radio radio-neutral radio-sm">
                        <span>Move them to another galaxy</span>
                    </label>
                </div>
            </div>

            <div id="bulk-kw-target-row" class="mt-4 hidden">
                <label for="bulk-kw-target" class="block mb-1.5 text-gray-800 font-medium text-sm">Target galaxy</label>
                <select id="bulk-kw-target" class="select select-bordered select-sm w-full bg-white"></select>
            </div>

            <p id="bulk-kw-preview" class="text-xs text-gray-600 mt-4">Pick a keyword to see the count.</p>

            <div class="modal-action">
                <button type="button" id="bulk-kw-apply" class="btn btn-neutral" disabled>Apply</button>
                <button type="button" class="btn" onclick="document.getElementById('bulk_by_keyword_modal').close()">Cancel</button>
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
                showMessage('Pick a specific galaxy first (not "All galaxies").', 'error');
                return;
            }

            const kwSelect = document.getElementById('bulk-kw-keyword');
            const targetSelect = document.getElementById('bulk-kw-target');
            const preview = document.getElementById('bulk-kw-preview');
            const applyBtn = document.getElementById('bulk-kw-apply');
            kwSelect.innerHTML = '<option value="">Loading…</option>';
            targetSelect.innerHTML = '';
            preview.textContent = 'Pick a keyword to see the count.';
            preview.style.color = '';
            applyBtn.disabled = true;
            document.querySelector('input[name="bulk-kw-op"][value="delete"]').checked = true;
            document.getElementById('bulk-kw-target-row').classList.add('hidden');

            // Load keyword list for the current galaxy.
            try {
                const r = await fetch(`../api/keywords.php?constellation_id=${cid}`, { headers: { 'X-API-Key': API_KEY } });
                if (!r.ok) throw new Error('Failed to load keywords');
                bulkKwAvailable = await r.json();
                if (!Array.isArray(bulkKwAvailable) || bulkKwAvailable.length === 0) {
                    kwSelect.innerHTML = '<option value="">(no keywords in this galaxy)</option>';
                } else {
                    kwSelect.innerHTML = '<option value="">— pick one —</option>' + bulkKwAvailable
                        .sort((a, b) => (b.usage_count || 0) - (a.usage_count || 0) || String(a.keyword).localeCompare(String(b.keyword)))
                        .map(k => `<option value="${k.id}">${escapeHtmlEdit(k.keyword)} (${k.usage_count || 0})</option>`)
                        .join('');
                }
            } catch (e) {
                kwSelect.innerHTML = '<option value="">Error loading keywords</option>';
            }

            // Target galaxy list (excludes current galaxy itself).
            if (Array.isArray(window.TELARIS_GALAXIES)) {
                targetSelect.innerHTML = '<option value="">— pick a galaxy —</option>' + window.TELARIS_GALAXIES
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
                    preview.textContent = 'Pick a keyword to see the count.';
                    applyBtn.disabled = true;
                    return;
                }
                const entry = bulkKwAvailable.find(k => k.id === kid);
                const count = entry ? (entry.usage_count || 0) : 0;
                const op = (document.querySelector('input[name="bulk-kw-op"]:checked') || {}).value || 'delete';
                if (op === 'move') {
                    const tid = parseInt(targetSelect.value, 10);
                    preview.textContent = tid && !isNaN(tid)
                        ? `Will move ${count} wormhole${count === 1 ? '' : 's'} to the chosen galaxy.`
                        : `Will move ${count} wormhole${count === 1 ? '' : 's'} — pick a target galaxy first.`;
                    applyBtn.disabled = !(tid && !isNaN(tid)) || count === 0;
                } else {
                    preview.textContent = `Will permanently delete ${count} wormhole${count === 1 ? '' : 's'}.`;
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
                const msg = op === 'delete'
                    ? `Permanently delete ${count} wormhole${count === 1 ? '' : 's'} carrying "${entry?.keyword || ''}"? This cannot be undone.`
                    : `Move ${count} wormhole${count === 1 ? '' : 's'} carrying "${entry?.keyword || ''}" to the selected galaxy?`;
                if (!window.confirm(msg)) return;

                applyBtn.disabled = true;
                try {
                    const body = { action: 'bulk_by_keyword', constellation_id: cid, keyword_id: kid, op };
                    if (op === 'move') body.target_constellation_id = tid;
                    const r = await fetch(API_BASE, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
                        body: JSON.stringify(body),
                    });
                    const json = await r.json();
                    if (!r.ok) throw new Error(json?.error || 'Bulk action failed');
                    showMessage(`${op === 'delete' ? 'Deleted' : 'Moved'} ${json.affected} wormhole${json.affected === 1 ? '' : 's'}.`);
                    document.getElementById('bulk_by_keyword_modal').close();
                    loadNodes();
                } catch (e) {
                    showMessage('Bulk action failed: ' + e.message, 'error');
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
                <h3 class="font-bold text-xl">Keyboard shortcuts</h3>
            </div>
            <table class="w-full mt-4 text-sm">
                <tbody class="divide-y divide-gray-200">
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">N</kbd></td><td class="text-gray-700">New wormhole</td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">/</kbd></td><td class="text-gray-700">Focus the search box</td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">T</kbd></td><td class="text-gray-700">Toggle "Touched today" filter</td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">G</kbd></td><td class="text-gray-700">Open galaxy settings (current galaxy)</td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">Esc</kbd></td><td class="text-gray-700">Close any open modal</td></tr>
                    <tr><td class="py-2"><kbd class="kbd kbd-sm">?</kbd></td><td class="text-gray-700">Open this help</td></tr>
                </tbody>
            </table>
            <p class="text-xs text-gray-500 mt-4">Shortcuts are ignored while typing in a text field.</p>
            <div class="modal-action">
                <button type="button" class="btn btn-neutral" onclick="document.getElementById('shortcuts_modal').close()">Close</button>
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
            if (!sel || !btn) return;
            const v = sel.value;
            btn.style.display = (v && v !== 'all') ? '' : 'none';
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