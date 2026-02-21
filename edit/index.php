<?php
declare(strict_types=1);

// Set Content-Type header
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../utils/auth.php';
requireEditorOrAdminLogin();

require_once __DIR__ . '/../config.php';

$pdo = getDB();

// Get default API key for API calls (using function from config.php)
$apiKey = getDefaultApiKey($pdo);

// Editors see only constellations assigned to them; admins see all
$currentUserId = $_SESSION['admin_user_id'] ?? null;
$isAdmin = isAdminLoggedIn();
$constellations = db_get_constellations_for_user($currentUserId, $isAdmin);

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
    <title>Edit Nodes - <?php echo htmlspecialchars($projectName); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" />
</head>
<body class="font-sans bg-gray-100 min-h-screen">
    <div class="max-w-7xl mx-auto py-8 px-5">
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-gray-800 text-3xl font-semibold">Edit Nodes</h1>
                    <p class="text-gray-600 mt-1">Welcome, <?php echo htmlspecialchars($userName); ?> (<?php echo $isAdmin ? 'Admin' : 'Editor'; ?>)</p>
                </div>
                <div class="flex items-center gap-2">
                    <label for="current-constellation" class="text-sm font-medium text-gray-700">Current Constellation:</label>
                    <div class="join">
                        <select id="current-constellation" 
                                onchange="switchConstellation(this.value)"
                                class="select select-bordered select-sm min-w-[180px] bg-white join-item">
                            <?php
                            $currentConstellationParam = isset($_GET['constellation_id']) ? trim((string)$_GET['constellation_id']) : 'all';
                            if (!is_numeric($currentConstellationParam) && $currentConstellationParam !== 'all') {
                                $currentConstellationParam = 'all';
                            }
                            ?>
                            <option value="all"<?php echo $currentConstellationParam === 'all' ? ' selected' : ''; ?>><?php echo $isAdmin ? 'All constellations' : 'All my constellations'; ?></option>
                            <?php foreach ($constellations as $c):
                                $cid = (int)$c['id'];
                                $sel = $currentConstellationParam === (string)$cid ? ' selected' : '';
                            ?>
                                <option value="<?php echo $cid; ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="viewNetwork()" class="btn btn-sm btn-neutral join-item">
                            View
                        </button>
                        <button type="button" onclick="copyCurrentConstellationUrl(this)" class="btn btn-sm btn-outline join-item" title="Copy constellation URL">
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
        <div id="message" class="hidden mb-5 p-4 rounded"></div>

        <!-- Nodes List -->
        <div class="bg-white rounded-lg shadow-md mb-6">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <h2 class="text-gray-800 text-xl font-semibold">Nodes (<span id="tab-list-count">0</span>)</h2>
                        <button type="button" onclick="openCreateNodeModal()" class="text-blue-600 hover:text-blue-800 font-medium text-base">New Node</button>
                    </div>
                    
                    <!-- Top Pagination Container -->
                    <div id="nodes-pagination-header" class="flex-1 flex justify-center"></div>

                    <div class="flex items-center gap-2 min-w-[300px]">
                        <label for="search-nodes" class="text-sm font-medium text-gray-700">Search:</label>
                        <input type="text" 
                               id="search-nodes" 
                               placeholder="Search nodes..." 
                               oninput="applySorting()"
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
                                Constellation<span id="sort-indicator-constellation_name"></span>
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
                    <p class="text-gray-500 p-4" id="loading-message">Loading nodes...</p>
                </div>
            </div>

        </div>
    </div>

    <script>
        const API_KEY = <?php echo $apiKey !== null ? json_encode($apiKey, JSON_THROW_ON_ERROR) : 'null'; ?>;
        const API_BASE = '../api/nodes.php';
        const CONSTELLATIONS_API = '../api/constellations.php';
        const CONSTELLATIONS = <?php echo json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'slug' => $c['slug']], $constellations), JSON_THROW_ON_ERROR); ?>;

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

        // Switch current constellation (reload page so list shows only that constellation)
        function switchConstellation(value) {
            const url = new URL(window.location.href);
            url.searchParams.set('constellation_id', value);
            window.location.assign(url.toString());
        }

        // Open the current constellation in the network view
        function viewNetwork() {
            const constellationId = document.getElementById('current-constellation').value;
            const targetId = constellationId === 'all' ? '0' : constellationId;
            const url = targetId === '0' ? '../index.php' : `../index.php?constellation_id=${targetId}`;
            window.open(url, '_blank');
        }

        // Copy the absolute URL of the current constellation to clipboard
        function copyCurrentConstellationUrl(buttonEl) {
            const constellationId = document.getElementById('current-constellation').value;
            const targetId = constellationId === 'all' ? '0' : constellationId;
            const relativeUrl = targetId === '0' ? '../index.php' : `../index.php?constellation_id=${targetId}`;
            const absoluteUrl = new URL(relativeUrl, window.location.origin + window.location.pathname).href;
            
            navigator.clipboard.writeText(absoluteUrl).then(() => {
                const origTitle = buttonEl.getAttribute('title');
                buttonEl.setAttribute('title', 'Copied!');
                // Using alert or a more subtle way since we don't have the toast div here yet
                showMessage('URL copied to clipboard');
                setTimeout(() => {
                    buttonEl.setAttribute('title', origTitle || 'Copy constellation URL');
                }, 1500);
            });
        }

        // Populate a target-constellation select with options from API list (allConstellations)
        function populateTargetConstellationDropdown(selectEl, selectedId) {
            if (!selectEl) return;
            const list = allConstellations.length ? allConstellations : CONSTELLATIONS;
            const currentValue = selectEl.value;
            selectEl.innerHTML = '';
            list.forEach(c => {
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
            const name = window.prompt('Name of the new constellation:');
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
                showMessage('Constellation "' + newName + '" created.');
            } catch (e) {
                showMessage('Error creating constellation: ' + e.message, 'error');
            }
        }

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
            if (!listDiv) return;

            // Show loading state
            const countEl = document.getElementById('tab-list-count');
            if (countEl) countEl.textContent = '...';
            
            listDiv.innerHTML = `
                <div class="flex flex-col items-center justify-center py-12 text-gray-500">
                    <span class="loading loading-spinner loading-lg text-neutral mb-4"></span>
                    <p class="text-lg">Retrieving nodes...</p>
                </div>
            `;

            // Check if API key exists
            if (!API_KEY) {
                listDiv.innerHTML = '<p class="text-red-600">Error: API key is missing. Please contact an administrator.</p>';
                return;
            }

            try {
                // Add timeout to fetch request
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
                
                const constellationEl = document.getElementById('current-constellation');
                const constellationId = constellationEl ? constellationEl.value : 'all';
                const query = constellationId === 'all' ? '?constellation_id=all' : ('?constellation_id=' + encodeURIComponent(constellationId));
                let response;
                try {
                    response = await fetch(API_BASE + query, {
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

                // Update node count
                const countEl = document.getElementById('tab-list-count');
                if (countEl) countEl.textContent = nodes.length;
                
                applySorting();
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
            if (!listDiv) return;
            
            if (!Array.isArray(nodes)) {
                listDiv.innerHTML = '<p class="text-red-600 p-4">Error: Invalid data format received.</p>';
                return;
            }
            
            if (nodes.length === 0) {
                listDiv.innerHTML = `
                    <div class="flex flex-col items-center justify-center py-12 text-gray-500 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200">
                        <svg class="w-12 h-12 mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                        <p class="text-lg font-medium">No nodes found.</p>
                        <p class="text-sm">Try adjusting your search or add a new node to get started.</p>
                    </div>
                `;
                return;
            }

            try {
                const headerHTML = `
                    <div class="border-b-2 border-gray-400 bg-gray-100 py-2 mb-1 sticky top-0 z-10">
                        <div class="grid grid-cols-12 gap-3 text-xs font-semibold text-gray-700">
                            <div class="col-span-3 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('name')">Name<span id="sort-indicator-name"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('node_type')">Type<span id="sort-indicator-node_type"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('constellation_name')">Constellation<span id="sort-indicator-constellation_name"></span></div>
                            <div class="col-span-2 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('keywords')">Keywords<span id="sort-indicator-keywords"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('is_accentuated')" title="Accentuated Status">Acc<span id="sort-indicator-is_accentuated"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('created_at')">Created<span id="sort-indicator-created_at"></span></div>
                            <div class="col-span-1 cursor-pointer hover:bg-gray-200 px-2 py-1 rounded flex items-center gap-1" onclick="sortByColumn('updated_at')">Updated<span id="sort-indicator-updated_at"></span></div>
                            <div class="col-span-1 text-right pr-2">Actions</div>
                        </div>
                    </div>
                `;

                const html = nodes.map(node => {
                    if (!node || !node.id) {
                        return '';
                    }
                    
                    // Show normal display - compact spreadsheet-like layout
                    const dateObj = node.created_at ? new Date(node.created_at) : null;
                    const createdDate = dateObj 
                        ? `${dateObj.getFullYear()}-${(dateObj.getMonth()+1).toString().padStart(2,'0')}-${dateObj.getDate().toString().padStart(2,'0')} ${dateObj.getHours().toString().padStart(2,'0')}:${dateObj.getMinutes().toString().padStart(2,'0')}` 
                        : 'N/A';
                    const updatedDateObj = node.updated_at ? new Date(node.updated_at) : null;
                    const updatedDate = updatedDateObj 
                        ? `${updatedDateObj.getFullYear()}-${(updatedDateObj.getMonth()+1).toString().padStart(2,'0')}-${updatedDateObj.getDate().toString().padStart(2,'0')} ${updatedDateObj.getHours().toString().padStart(2,'0')}:${updatedDateObj.getMinutes().toString().padStart(2,'0')}` 
                        : 'N/A';
                    const descriptionTruncated = node.description ? (node.description.length > 80 ? escapeHtml(node.description.substring(0, 80)) + '...' : escapeHtml(node.description)) : '';
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
                <div class="border-b border-gray-300 hover:bg-gray-50 py-2 cursor-pointer" onclick="editNode(${node.id})">
                    <div class="grid grid-cols-12 gap-3 items-center text-sm">
                        <div class="col-span-3 min-w-0">
                            <div class="font-semibold text-gray-800 truncate" title="${escapeHtml(node.name)}">${escapeHtml(node.name)}</div>
                            <div class="flex flex-wrap gap-1 mt-1">
                                ${node.is_accentuated ? '<span class="text-[10px] bg-yellow-100 text-yellow-700 px-1 rounded border border-yellow-200 font-bold" title="Accentuated Node">ACC</span>' : ''}
                                ${node.url ? '<span class="text-[10px] bg-blue-100 text-blue-700 px-1 rounded" title="Has URL">URL</span>' : ''}
                                ${node.description ? '<span class="text-[10px] bg-green-100 text-green-700 px-1 rounded" title="Has Description">DESC</span>' : ''}
                                ${node.image_url ? '<span class="text-[10px] bg-purple-100 text-purple-700 px-1 rounded" title="Has Image">IMG</span>' : ''}
                                ${node.embed_code ? '<span class="text-[10px] bg-pink-100 text-pink-700 px-1 rounded" title="Has Embed">EMB</span>' : ''}
                                ${node.audio_url ? '<span class="text-[10px] bg-orange-100 text-orange-700 px-1 rounded" title="Has Audio">AUD</span>' : ''}
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
                        <div class="col-span-1 flex gap-2 justify-end pr-2">
                            <button onclick="event.stopPropagation(); deleteNode(${node.id}, '${escapeHtml(node.name)}')" class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
                    `;
                }).filter(html => html.length > 0).join('');
                
                // Set innerHTML with header + nodes
                listDiv.innerHTML = headerHTML + html;

                // Add Pagination Controls (Top and Bottom)
                const totalPages = Math.ceil(filteredNodes.length / itemsPerPage);
                
                // Clear any existing pagination
                const headerContainer = document.getElementById('nodes-pagination-header');
                if (headerContainer) headerContainer.innerHTML = '';
                
                const oldBottom = document.getElementById('nodes-pagination-bottom');
                if (oldBottom) oldBottom.remove();

                if (totalPages > 1) {
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

                    // Header pagination
                    if (headerContainer) {
                        headerContainer.innerHTML = createPaginationHTML(true);
                    }

                    // Bottom pagination
                    const bottomPagination = document.createElement('div');
                    bottomPagination.id = 'nodes-pagination-bottom';
                    bottomPagination.innerHTML = createPaginationHTML(false);
                    listDiv.appendChild(bottomPagination);
                }

                updateSortIndicators();

                // Initialize keywords for the node being edited
                if (editingNodeId !== null) {
                    const editingNode = nodes.find(n => n.id === editingNodeId);
                    if (editingNode) {
                        keywordState[editingNodeId] = [...(editingNode.keywords || [])];
                        updateKeywordTags(editingNodeId);
                    }
                }
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

        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;  
            }
        }

        // Store all nodes for sorting
        let allNodes = [];
        let filteredNodes = [];

        // Pagination state
        let currentPage = 1;
        const itemsPerPage = 25;

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
        
        // Apply sorting and filtering to displayed nodes
        function applySorting(resetPage = true) {
            const searchInput = document.getElementById('search-nodes');
            
            if (resetPage) currentPage = 1;
            
            // Get search query
            const searchQuery = searchInput ? searchInput.value.trim().toLowerCase() : '';
            
            // Filter nodes based on search query
            filteredNodes = [...allNodes];
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
                    const constellationMatch = (node.constellation_name || '').toLowerCase().includes(searchQuery);
                    const typeMatch = (node.node_type || 'object').toLowerCase().includes(searchQuery);
                    
                    return nameMatch || descriptionMatch || urlMatch || keywordsMatch || constellationMatch || typeMatch;
                });
            }
            
            // Apply sorting to filtered nodes if a column is selected
            if (currentSortColumn) {
                filteredNodes.sort((a, b) => {
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
                        case 'updated_at':
                            aVal = a.updated_at ? new Date(a.updated_at).getTime() : 0;
                            bVal = b.updated_at ? new Date(b.updated_at).getTime() : 0;
                            break;
                        case 'url':
                            aVal = (a.url || '').toLowerCase();
                            bVal = (b.url || '').toLowerCase();
                            break;
                        case 'keywords':
                            aVal = a.keywords && a.keywords.length > 0 ? a.keywords.join(', ').toLowerCase() : '';
                            bVal = b.keywords && b.keywords.length > 0 ? b.keywords.join(', ').toLowerCase() : '';
                            break;
                        case 'constellation_name':
                            aVal = (a.constellation_name || '').toLowerCase();
                            bVal = (b.constellation_name || '').toLowerCase();
                            break;
                        case 'node_type':
                            aVal = (a.node_type || 'object').toLowerCase();
                            bVal = (b.node_type || 'object').toLowerCase();
                            break;
                        case 'is_accentuated':
                            aVal = a.is_accentuated ? 1 : 0;
                            bVal = b.is_accentuated ? 1 : 0;
                            break;
                        default:
                            return 0;
                    }
                    
                    if (aVal < bVal) return currentSortOrder === 'asc' ? -1 : 1;
                    if (aVal > bVal) return currentSortOrder === 'asc' ? 1 : -1;
                    return 0;
                });
            }
            
            // Calculate slice for pagination
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const paginatedNodes = filteredNodes.slice(start, end);

            displayNodes(paginatedNodes);
        }

        function changePage(page) {
            const totalPages = Math.ceil(filteredNodes.length / itemsPerPage);
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            applySorting(false);
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

        // Edit node - show modal
        function editNode(id) {
            const node = allNodes.find(n => n.id === id);
            if (!node) return;
            
            editingNodeId = id;
            
            // Populate basic fields
            document.getElementById('edit-id').value = node.id;
            document.getElementById('edit-name').value = node.name || '';
            document.getElementById('edit-constellation').value = node.constellation_id;
            document.getElementById('edit-node-type').value = node.node_type || 'object';
            document.getElementById('edit-description').value = node.description || '';
            document.getElementById('edit-url').value = node.url || '';
            document.getElementById('edit-embed-code').value = node.embed_code || '';
            document.getElementById('edit-audio-autoplay').checked = !!node.audio_autoplay;
            document.getElementById('edit-accentuated').checked = !!node.is_accentuated;

            // Handle keywords
            keywordState['modal'] = [...(node.keywords || [])];
            updateKeywordTags('modal');

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

            // Handle audio fields
            const audioFileWrap = document.getElementById('edit-audio-file-wrap');
            const audioExisting = document.getElementById('edit-audio-existing');
            const audioExistingName = document.getElementById('edit-audio-existing-name');
            const audioUrlInput = document.getElementById('edit-audio-url');

            if (node.audio_url && node.audio_url.startsWith('uploads/')) {
                audioFileWrap.classList.add('hidden');
                audioExisting.classList.remove('hidden');
                audioExistingName.value = node.audio_url.split('/').pop();
                audioUrlInput.value = node.audio_url; // Hidden but holds path
            } else {
                audioFileWrap.classList.remove('hidden');
                audioExisting.classList.add('hidden');
                audioUrlInput.value = node.audio_url || '';
            }

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
                showMessage('Node name is required', 'error');
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
            
            const imageUrl = document.getElementById('edit-image-url').value.trim();
            const audioUrl = document.getElementById('edit-audio-url').value.trim();

            formData.append('image_url', imageUrl);
            formData.append('audio_url', audioUrl);
            formData.append('embed_code', document.getElementById('edit-embed-code').value.trim());
            formData.append('audio_autoplay', document.getElementById('edit-audio-autoplay').checked ? 1 : 0);
            formData.append('is_accentuated', document.getElementById('edit-accentuated').checked ? 1 : 0);
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

            const imageFile = document.getElementById('edit-image-file').files[0];
            if (imageFile) formData.append('image_file', imageFile);
            
            const audioFile = document.getElementById('edit-audio-file').files[0];
            if (audioFile) formData.append('audio_file', audioFile);
            
            try {
                const response = await fetch(API_BASE, {
                    method: 'POST',
                    headers: {
                        'X-API-Key': API_KEY,
                        'X-HTTP-Method-Override': 'PUT'
                    },
                    body: formData
                });
                
                if (!response.ok) throw new Error('Failed to update node');
                
                document.getElementById('edit_modal').close();
                showMessage('Node updated successfully');
                loadNodes();
            } catch (error) {
                showMessage('Error saving node: ' + error.message, 'error');
            }
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
                    if (node) node[`${type}_url`] = '';
                    
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
                        throw new Error(error.error || 'Failed to delete node');
                    }
                    
                    showMessage('Node deleted successfully');
                    loadNodes();
                } catch (error) {
                    showMessage('Error deleting node: ' + error.message, 'error');
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
                showMessage('Node name is required', 'error');
                return;
            }

            if (!API_KEY) {
                showMessage('API key is missing.', 'error');
                return;
            }

            const animation = generateRandomAnimation();
            const constellationId = parseInt(document.getElementById('node-constellation').value);
            const nodeType = document.getElementById('node-type').value;
            
            const formData = new FormData();
            formData.append('name', nodeName);
            formData.append('description', document.getElementById('node-description').value.trim());
            formData.append('url', document.getElementById('node-url').value.trim());
            formData.append('image_url', document.getElementById('node-image-url').value.trim());
            formData.append('audio_url', document.getElementById('node-audio-url').value.trim());
            formData.append('embed_code', document.getElementById('node-embed-code').value.trim());
            formData.append('audio_autoplay', document.getElementById('node-audio-autoplay').checked ? 1 : 0);
            formData.append('is_accentuated', document.getElementById('node-accentuated').checked ? 1 : 0);
            formData.append('constellation_id', isNaN(constellationId) ? 0 : constellationId);
            formData.append('node_type', nodeType);
            
            if (nodeType === 'portal') {
                formData.append('target_constellation_id', document.getElementById('node-target-constellation').value);
            }
            formData.append('animation', JSON.stringify(animation));
            formData.append('keywords', (keywordState['create'] || []).join(','));

            const imageFile = document.getElementById('node-image-file').files[0];
            if (imageFile) formData.append('image_file', imageFile);
            
            const audioFile = document.getElementById('node-audio-file').files[0];
            if (audioFile) formData.append('audio_file', audioFile);

            try {
                const response = await fetch(API_BASE, {
                    method: 'POST',
                    headers: { 'X-API-Key': API_KEY },
                    body: formData
                });
                
                if (!response.ok) throw new Error('Failed to create node');

                document.getElementById('create_node_modal').close();
                showMessage('Node created successfully');
                loadNodes();
            } catch (error) {
                showMessage('Error saving node: ' + error.message, 'error');
            }
        }

        // Wait for DOM to be ready
        document.addEventListener('DOMContentLoaded', () => {
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

        function handleKeywordInput(event, contextId) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                const text = event.target.value.trim().replace(/,$/, '');
                if (text) {
                    if (!keywordState[contextId]) keywordState[contextId] = [];
                    if (!keywordState[contextId].includes(text)) {
                        keywordState[contextId].push(text);
                        updateKeywordTags(contextId);
                    }
                }
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
                    listDiv.innerHTML = `<p class="text-red-600">Fatal error loading nodes: ${escapeHtml(error.message)}</p>`;
                }
            });
            setupLiveValidation();
        });
    </script>
    <!-- Create Node Modal -->
    <dialog id="create_node_modal" class="modal">
        <div class="modal-box max-w-4xl bg-white">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Add New Node</h3>
            <form id="create-node-form" class="space-y-4" onsubmit="saveNewNode(event)">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="node-name" class="block mb-1.5 text-gray-800 font-medium text-sm">Name *</label>
                        <input type="text" id="node-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span id="node-name-error" class="text-xs text-red-600 mt-1 hidden">This node name already exists in this constellation.</span>
                        <span class="text-xs text-gray-500 mt-1 block">Primary title of the node shown in the network.</span>
                    </div>
                    <div>
                        <label for="node-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm">Constellation</label>
                        <select id="node-constellation" name="constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php foreach ($constellations as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-xs text-gray-500 mt-1 block">Which constellation this node belongs to.</span>
                    </div>
                    <div>
                        <label for="node-type" class="block mb-1.5 text-gray-800 font-medium text-sm">Node type</label>
                        <select id="node-type" name="node_type" onchange="toggleTargetConstellation(this.value, 'create')" class="select select-bordered select-sm w-full bg-white">
                            <option value="object">Object</option>
                            <option value="portal">Portal</option>
                        </select>
                        <span class="text-xs text-gray-500 mt-1 block">Object is a standard item; Portal links to another constellation.</span>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Keywords</label>
                        <div id="keywords-container-create" class="flex flex-wrap gap-2 p-2 border border-gray-300 rounded bg-white focus-within:border-blue-500 transition-colors">
                            <input type="text" id="node-keywords-input" placeholder="Add keyword..." onkeydown="handleKeywordInput(event, 'create')" class="flex-1 min-w-[120px] outline-none text-sm py-1 px-1">
                        </div>
                        <input type="hidden" id="node-keywords" name="keywords">
                        <span class="text-xs text-gray-500 mt-1 block">Type and press Enter or comma to add keywords.</span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="node-accentuated" name="is_accentuated" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800">Accentuate Node</span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1">Make this node larger and more prominent in the network.</span>
                    </div>
                </div>
                <div id="create-target-constellation-wrap" class="hidden">
                    <div class="flex flex-wrap items-end gap-2 mb-2">
                        <div class="min-w-[200px] flex-1">
                            <label for="node-target-constellation" class="block mb-1.5 text-gray-800 font-medium text-sm">Target Constellation</label>
                            <select id="node-target-constellation" name="target_constellation_id" class="select select-bordered select-sm w-full bg-white">
                                <?php foreach ($constellations as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-xs text-gray-500 mt-1 block">The destination constellation this portal leads to.</span>
                        </div>
                        <button type="button" onclick="createNewConstellation('create')" class="py-2.5 px-4 rounded text-sm border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 cursor-pointer whitespace-nowrap">Create New Constellation</button>
                    </div>
                </div>
                <div>
                    <label for="node-description" class="block mb-1.5 text-gray-800 font-medium text-sm">Description</label>
                    <textarea id="node-description" name="description" rows="3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                    <span class="text-xs text-gray-500 mt-1 block">Detailed text displayed when the node is selected.</span>
                </div>
                <div>
                    <label for="node-url" class="block mb-1.5 text-gray-800 font-medium text-sm">URL</label>
                    <input type="url" id="node-url" name="url" placeholder="https://example.com" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">URL to open when the node is clicked (optional).</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="node-image-url" class="block mb-1.5 text-gray-800 font-medium text-sm">Image URL / File</label>
                        <input type="text" id="node-image-url" name="image_url" placeholder="https://example.com/image.jpg" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                        <input type="file" id="node-image-file" name="image_file" accept="image/*" class="text-xs">
                        <span class="text-xs text-gray-500 mt-1 block">Upload an image or provide a link to be displayed.</span>
                    </div>
                    <div>
                        <label for="node-audio-url" class="block mb-1.5 text-gray-800 font-medium text-sm">Audio URL / File</label>
                        <input type="text" id="node-audio-url" name="audio_url" placeholder="https://example.com/audio.mp3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                        <input type="file" id="node-audio-file" name="audio_file" accept="audio/*" class="text-xs">
                        <span class="text-xs text-gray-500 mt-1 block">Upload an audio file or provide a link for background sound.</span>
                        <label class="flex items-center gap-2 mt-2 text-xs text-gray-700">
                            <input type="checkbox" id="node-audio-autoplay" name="audio_autoplay" checked>
                            Autoplay audio
                        </label>
                    </div>
                </div>
                <div>
                    <label for="node-embed-code" class="block mb-1.5 text-gray-800 font-medium text-sm">Embed Code (HTML)</label>
                    <textarea id="node-embed-code" name="embed_code" rows="3" placeholder='<iframe ...></iframe>' class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                    <span class="text-xs text-gray-500 mt-1 block">Paste iframe or HTML code for videos or interactive content.</span>
                </div>
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Add Node</button>
                    <button type="button" class="btn" onclick="document.getElementById('create_node_modal').close()">Cancel</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <dialog id="edit_modal" class="modal">
        <div class="modal-box max-w-4xl bg-white">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Edit Node</h3>
            <form id="edit-node-form" class="space-y-4" onsubmit="saveNodeEdit(event)">
                <input type="hidden" id="edit-id" name="id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Name *</label>
                        <input type="text" id="edit-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span id="edit-name-error" class="text-xs text-red-600 mt-1 hidden">This node name already exists in this constellation.</span>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Constellation</label>
                        <select id="edit-constellation" name="constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php foreach ($constellations as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Node type</label>
                        <select id="edit-node-type" name="node_type" onchange="toggleTargetConstellation(this.value, 'modal')" class="select select-bordered select-sm w-full bg-white">
                            <option value="object">Object</option>
                            <option value="portal">Portal</option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Keywords</label>
                        <div id="keywords-container-modal" class="flex flex-wrap gap-2 p-2 border border-gray-300 rounded bg-white focus-within:border-blue-500 transition-colors">
                            <input type="text" id="edit-keywords-input-modal" placeholder="Add keyword..." onkeydown="handleKeywordInput(event, 'modal')" class="flex-1 min-w-[120px] outline-none text-sm py-1 px-1">
                        </div>
                        <input type="hidden" id="edit-keywords-hidden" name="keywords">
                        <span class="text-xs text-gray-500 mt-1 block">Type and press Enter or comma to add keywords</span>
                    </div>
                    <div class="flex flex-col justify-center">
                        <label class="label cursor-pointer justify-start gap-4">
                            <input type="checkbox" id="edit-accentuated" name="is_accentuated" class="toggle toggle-neutral">
                            <span class="label-text font-medium text-gray-800">Accentuate Node</span>
                        </label>
                        <span class="text-xs text-gray-500 block ml-1">Make this node larger and more prominent in the network.</span>
                    </div>
                </div>
                <div id="edit-target-constellation-wrap-modal" class="hidden">
                    <div class="flex flex-wrap items-end gap-2 mb-2">
                        <div class="min-w-[200px] flex-1">
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm">Target Constellation</label>
                            <select id="edit-target-constellation-modal" name="target_constellation_id" class="select select-bordered select-sm w-full bg-white"></select>
                        </div>
                        <button type="button" onclick="createNewConstellation('modal')" class="py-2.5 px-4 rounded text-sm border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 cursor-pointer whitespace-nowrap">Create New Constellation</button>
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div id="edit-image-container">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Image URL / File</label>
                        <div id="edit-image-file-wrap">
                            <input type="text" id="edit-image-url" name="image_url" placeholder="https://example.com/image.jpg" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="edit-image-file" name="image_file" accept="image/*" class="text-xs">
                        </div>
                        <div id="edit-image-existing" class="hidden flex items-center gap-2 mb-2">
                            <input type="text" id="edit-image-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                            <button type="button" onclick="deleteModalFile('image')" class="btn btn-error btn-sm btn-outline">Delete</button>
                        </div>
                    </div>
                    <div id="edit-audio-container">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Audio URL / File</label>
                        <div id="edit-audio-file-wrap">
                            <input type="text" id="edit-audio-url" name="audio_url" placeholder="https://example.com/audio.mp3" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-2">
                            <input type="file" id="edit-audio-file" name="audio_file" accept="audio/*" class="text-xs">
                        </div>
                        <div id="edit-audio-existing" class="hidden flex items-center gap-2 mb-2">
                            <input type="text" id="edit-audio-existing-name" readonly class="flex-1 p-2.5 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500 cursor-not-allowed">
                            <button type="button" onclick="deleteModalFile('audio')" class="btn btn-error btn-sm btn-outline">Delete</button>
                        </div>
                        <label class="flex items-center gap-2 mt-2 text-xs text-gray-700">
                            <input type="checkbox" id="edit-audio-autoplay" name="audio_autoplay">
                            Autoplay audio
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block mb-1.5 text-gray-800 font-medium text-sm">Embed Code (HTML)</label>
                    <textarea id="edit-embed-code" name="embed_code" rows="3" placeholder='<iframe ...></iframe>' class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                </div>
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral">Update Node</button>
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
        <div class="modal-box bg-white border-t-4 border-error">
            <h3 class="font-bold text-xl mb-4 text-gray-800">Confirm Deletion</h3>
            <p id="delete-confirm-message" class="text-gray-600 mb-6"></p>
            <div class="modal-action">
                <button id="delete-confirm-btn" class="btn btn-error text-white">Delete</button>
                <button type="button" class="btn" onclick="document.getElementById('delete_confirm_modal').close()">Cancel</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
</body>
</html>