<?php
declare(strict_types=1);

// Set Content-Type header
header('Content-Type: text/html; charset=UTF-8');

require_once '../auth.php';
requireEditorOrAdminLogin();

require_once '../config.php';

$pdo = getDB();

// Get default API key for API calls (using function from config.php)
$apiKey = getDefaultApiKey($pdo);

$userName = $_SESSION['admin_user_name'] ?? 'User';
$userType = (int)($_SESSION['admin_user_type'] ?? 0);
$isAdmin = $userType === USER_TYPE_ADMIN;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                    <a href="../logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded">
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

        <!-- Add/Edit Node Form -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <h2 class="text-gray-800 text-2xl font-semibold mb-4" id="form-title">Add New Node</h2>
            <form id="node-form" class="space-y-4" novalidate>
                <input type="hidden" id="node-id" name="id">
                
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

                <!-- Animation fields - hidden, not editable by users -->
                <div id="advanced-fields" class="grid grid-cols-1 gap-4 hidden">
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium">Animation</label>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="anim-radius" class="block text-xs text-gray-600 mb-1">Radius</label>
                                <input type="number" 
                                       id="anim-radius" 
                                       step="0.1" 
                                       value="5.0"
                                       disabled
                                       class="w-full p-2 border border-gray-300 rounded text-sm">
                            </div>
                            <div>
                                <label for="anim-speed" class="block text-xs text-gray-600 mb-1">Speed</label>
                                <input type="number" 
                                       id="anim-speed" 
                                       step="0.0001" 
                                       value="0.0025"
                                       disabled
                                       class="w-full p-2 border border-gray-300 rounded text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" id="submit-btn" class="bg-blue-500 hover:bg-blue-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                        Add Node
                    </button>
                    <button type="button" id="cancel-btn" class="hidden bg-gray-500 hover:bg-gray-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- Nodes List -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-gray-800 text-2xl font-semibold mb-4">Existing Nodes</h2>
            <div id="nodes-list" class="space-y-3">
                <p class="text-gray-500" id="loading-message">Loading nodes...</p>
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
            listDiv.innerHTML = '<p class="text-gray-500">Loading nodes...</p>';

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
                
                displayNodes(nodes);
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
                listDiv.innerHTML = '<p class="text-gray-500">No nodes found.</p>';
                return;
            }

            try {
                const html = nodes.map(node => {
                    if (!node || !node.id) {
                        return '';
                    }
                    return `
                <div class="border border-gray-300 rounded p-4 hover:bg-gray-50">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h3 class="font-semibold text-gray-800">${escapeHtml(node.name)}</h3>
                            ${node.description ? `<p class="text-sm text-gray-600 mt-1">${escapeHtml(node.description)}</p>` : ''}
                            ${node.url ? `<p class="text-sm text-blue-600 mt-1"><a href="${escapeHtml(node.url)}" target="_blank" rel="noopener noreferrer" class="underline">${escapeHtml(node.url)}</a></p>` : ''}
                            <div class="mt-2 flex flex-wrap gap-2">
                                ${node.keywords && node.keywords.length > 0 
                                    ? node.keywords.map(k => `<span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">${escapeHtml(k)}</span>`).join('')
                                    : '<span class="text-xs text-gray-400">No keywords</span>'}
                            </div>
                        </div>
                        <div class="flex gap-2 ml-4">
                            <button onclick="editNode(${node.id})" class="px-3 py-1 bg-blue-500 hover:bg-blue-600 text-white text-sm rounded">
                                Edit
                            </button>
                            <button onclick="deleteNode(${node.id}, '${escapeHtml(node.name)}')" class="px-3 py-1 bg-red-500 hover:bg-red-600 text-white text-sm rounded">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
                    `;
                }).filter(html => html.length > 0).join('');
                
                listDiv.innerHTML = html;
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

        // Edit node
        async function editNode(id) {
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
                const node = nodes.find(n => n.id === id);
                
                if (!node) {
                    throw new Error('Node not found');
                }

                // Populate form
                document.getElementById('node-id').value = node.id;
                document.getElementById('node-name').value = node.name;
                document.getElementById('node-description').value = node.description || '';
                document.getElementById('node-url').value = node.url || '';
                document.getElementById('node-keywords').value = node.keywords ? node.keywords.join(', ') : '';
                
                document.getElementById('anim-radius').value = node.animation.radius;
                document.getElementById('anim-speed').value = node.animation.speed;

                // Update UI
                document.getElementById('form-title').textContent = 'Edit Node';
                document.getElementById('submit-btn').textContent = 'Update Node';
                document.getElementById('cancel-btn').classList.remove('hidden');
                // Note: advanced-fields (animation) remain hidden - not editable
                editingNodeId = id;

                // Scroll to form
                document.getElementById('node-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (error) {
                showMessage('Error loading node for editing', 'error');
            }
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

        // Cancel edit
        function cancelEdit() {
            document.getElementById('node-form').reset();
            document.getElementById('node-id').value = '';
            document.getElementById('form-title').textContent = 'Add New Node';
            document.getElementById('submit-btn').textContent = 'Add Node';
            document.getElementById('cancel-btn').classList.add('hidden');
            editingNodeId = null;
        }

        // Wait for DOM to be ready
        document.addEventListener('DOMContentLoaded', () => {
            // Handle form submission
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

                const nodeId = document.getElementById('node-id').value;
                const isEdit = nodeId && nodeId !== '';

                // Generate automatic values for new nodes, use form values for editing
                let animation;
                
                if (isEdit) {
                    // When editing, use form values
                    animation = {
                        radius: parseFloat(document.getElementById('anim-radius').value) || 5.0,
                        theta: 0,
                        phi: 0,
                        speed: parseFloat(document.getElementById('anim-speed').value) || 0.0025,
                        phase: 0
                    };
                } else {
                    // When creating, generate automatic values
                    animation = generateRandomAnimation();
                }
                
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
                    const method = isEdit ? 'PUT' : 'POST';
                    
                    if (isEdit) {
                        formData.id = parseInt(nodeId);
                    }
                    
                    const response = await fetch(url, {
                        method: method,
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

                    showMessage(isEdit ? 'Node updated successfully' : 'Node created successfully');
                    cancelEdit();
                    loadNodes();
                } catch (error) {
                    showMessage('Error saving node: ' + error.message, 'error');
                }
                });
            }

            // Cancel button
            const cancelBtn = document.getElementById('cancel-btn');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', cancelEdit);
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
        
        // Fallback: If DOMContentLoaded already fired, call loadNodes immediately
        if (document.readyState !== 'loading') {
            setTimeout(() => {
                if (typeof loadNodes === 'function') {
                    loadNodes().catch(() => {});
                }
            }, 100);
        }
    </script>
</body>
</html>
