<?php
declare(strict_types=1);

/**
 * Main entry point for Telaris application
 * Checks if application is properly configured, redirects to admin/setup.php if not
 */

// Check if configuration file exists
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: admin/setup.php');
    exit();
}

// Try to load config and test database connection
try {
    require_once __DIR__ . '/config.php';
    
    // Check if database constants are configured (not empty)
    if (empty(DB_HOST) || empty(DB_NAME) || empty(DB_USER)) {
        // Database not configured, redirect to setup
        header('Location: admin/setup.php');
        exit();
    }
    
    // Test database connection
    $pdo = getDB();
    
    // Verify that essential tables exist (at least project_info)
    $tablesCheck = $pdo->query("SHOW TABLES LIKE 'project_info'")->fetch();
    if ($tablesCheck === false) {
        // Tables don't exist, redirect to setup
        header('Location: admin/setup.php');
        exit();
    }
} catch (PDOException $e) {
    // Database connection failed, redirect to setup
    header('Location: admin/setup.php');
    exit();
} catch (Exception $e) {
    // Any other error, redirect to setup
    header('Location: admin/setup.php');
    exit();
}

// Application is properly configured, continue to HTML
// Check if user is logged in as editor or admin
require_once __DIR__ . '/auth.php';
$isEditorOrAdmin = isEditorOrAdminLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telaris</title>
    <meta name="description" content="Interactive 3D knowledge visualization">
    <script src="js/tailwind.min.js"></script>
</head>
<body class="overflow-hidden bg-black font-sans">
    <div id="canvas-container" class="w-screen h-screen relative" style="position: relative;">
        <canvas class="block" style="position: relative; z-index: 1;"></canvas>
        <div id="node-tooltip" class="absolute bg-black bg-opacity-75 text-white px-3 py-2 rounded text-sm pointer-events-none z-[200] hidden" style="font-family: inherit;"></div>
    </div>
    <div id="info" class="absolute top-5 left-5 text-white z-[100] text-sm opacity-80 pointer-events-none">
        <h2 class="text-lg font-semibold mb-1">Telaris</h2>
        <p>Weaving memory</p>
    </div>
    <?php if ($isEditorOrAdmin): ?>
    <div class="absolute top-5 right-5 z-[100]">
        <a href="edit/index.php" class="text-white text-sm opacity-80 hover:opacity-100 underline pointer-events-auto">
            Edit
        </a>
    </div>
    <?php endif; ?>
    <script type="importmap">
        {
            "imports": {
                "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
                "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
            }
        }
    </script>
    <script type="module">
        import * as THREE from 'three';
        import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

        /**
         * Fetch with API key authentication
         */
        async function apiFetch(url, options = {}) {
            const apiKey = window.TELARIS_API_KEY;
            if (!apiKey) {
                throw new Error('API key not loaded');
            }
            
            const headers = {
                'X-API-Key': apiKey,
                ...(options.headers || {})
            };
            
            return fetch(url, {
                ...options,
                headers
            });
        }

        class TelarisNetwork {
            constructor() {
                this.scene = new THREE.Scene();
                this.camera = new THREE.PerspectiveCamera(
                    75,
                    window.innerWidth / window.innerHeight,
                    0.1,
                    1000
                );
                this.renderer = new THREE.WebGLRenderer({ 
                    antialias: true,
                    alpha: true 
                });
                
                this.nodes = [];
                this.nodeData = []; // Store node data from API
                this.connections = []; // Store connection lines between nodes
                this.raycaster = new THREE.Raycaster();
                this.mouse = new THREE.Vector2();
                this.tooltip = document.getElementById('node-tooltip');
                
                this.init();
            }

            getNodeAnchorPosition(node) {
                // Simply return the node's world position
                // Star nodes are centered at their 3D position
                const worldPos = new THREE.Vector3();
                node.getWorldPosition(worldPos);
                return worldPos;
            }

            init() {
                // Setup renderer
                this.renderer.setSize(window.innerWidth, window.innerHeight);
                this.renderer.setPixelRatio(window.devicePixelRatio);
                this.renderer.setClearColor(0x000000, 1);
                const canvasElement = this.renderer.domElement;
                canvasElement.style.position = 'relative';
                canvasElement.style.zIndex = '1';
                document.getElementById('canvas-container').appendChild(canvasElement);

                // Setup camera
                this.camera.position.set(0, 0, 15);

                // Add controls
                this.controls = new OrbitControls(this.camera, this.renderer.domElement);
                this.controls.enableDamping = true;
                this.controls.dampingFactor = 0.05;
                this.controls.minDistance = 5;
                this.controls.maxDistance = 30;

                // Create ambient light
                const ambientLight = new THREE.AmbientLight(0xffffff, 0.3);
                this.scene.add(ambientLight);

                // Create point lights for organic glow
                const light1 = new THREE.PointLight(0x4a90e2, 1, 50);
                light1.position.set(10, 10, 10);
                this.scene.add(light1);

                const light2 = new THREE.PointLight(0xe24a90, 1, 50);
                light2.position.set(-10, -10, 10);
                this.scene.add(light2);

                const light3 = new THREE.PointLight(0x90e24a, 1, 50);
                light3.position.set(0, 10, -10);
                this.scene.add(light3);

                // Setup mouse interaction for hover effects
                this.setupMouseInteraction();

                // Handle window resize
                window.addEventListener('resize', () => this.onWindowResize());
                
                // Load API key and data
                this.loadApiKey().then(() => {
                    this.loadData();
                });
                
                // Start animation loop
                this.animate();
            }

            async loadApiKey() {
                try {
                    const response = await fetch('config.php');
                    const config = await response.json();
                    if (config.api_key) {
                        window.TELARIS_API_KEY = config.api_key;
                    }
                } catch (error) {
                    console.error('Failed to load API key:', error);
                }
            }

            async loadData() {
                try {
                    // Wait for API key to be loaded
                    if (!window.TELARIS_API_KEY) {
                        // Wait a bit for the API key to load
                        await new Promise(resolve => setTimeout(resolve, 100));
                        if (!window.TELARIS_API_KEY) {
                            throw new Error('API key not available');
                        }
                    }
                    
                    // Load nodes
                    const nodesResponse = await apiFetch('api/nodes.php');
                    if (!nodesResponse.ok) {
                        const errorText = await nodesResponse.text();
                        console.error(`HTTP error! status: ${nodesResponse.status}`, errorText);
                        throw new Error(`HTTP error! status: ${nodesResponse.status}`);
                    }
                    const nodesJson = await nodesResponse.json();
                    console.log('Loaded nodes from API:', nodesJson);
                    // Ensure we have an array, even if API returns something unexpected
                    this.nodeData = Array.isArray(nodesJson) ? nodesJson : [];
                    
                    console.log(`Node data count: ${this.nodeData.length}`);
                    
                    // Only create nodes if we have data
                    if (this.nodeData.length > 0) {
                        this.createNodes();
                        this.createConnections();
                        console.log(`Created ${this.nodes.length} nodes`);
                        console.log(`Created ${this.connections.length} connections`);
                    } else {
                        console.log('No nodes found in database');
                        // Clear any existing nodes if database is empty
                        this.clearAll();
                    }
                } catch (error) {
                    console.error('Error loading data:', error);
                    console.error('Error details:', error.message, error.stack);
                    // Don't create default nodes - only show what's in the database
                    this.nodeData = [];
                    this.clearAll();
                }
            }

            clearAll() {
                // Clear all nodes
                this.nodes.forEach(node => {
                    this.scene.remove(node);
                    if (node.children) {
                        node.children.forEach(child => {
                            if (child.geometry) child.geometry.dispose();
                            if (child.material) child.material.dispose();
                        });
                    }
                });
                this.nodes = [];
                
                // Remove any remaining objects from scene that aren't lights or controls
                const objectsToRemove = [];
                this.scene.traverse((object) => {
                    // Keep lights, cameras, and controls
                    if (object.isLight || object.isCamera || object.isAmbientLight || object.isPointLight || object.isDirectionalLight) {
                        return;
                    }
                    // Remove everything else
                    if (object.isMesh || object.isGroup || object.isLine || object.isLineSegments || object.isCylinderGeometry) {
                        objectsToRemove.push(object);
                    }
                });
                objectsToRemove.forEach(obj => {
                    this.scene.remove(obj);
                    if (obj.geometry) obj.geometry.dispose();
                    if (obj.material) {
                        if (Array.isArray(obj.material)) {
                            obj.material.forEach(mat => mat.dispose());
                        } else {
                            obj.material.dispose();
                        }
                    }
                });
            }

            setupMouseInteraction() {
                let mouseDownPos = null;
                let mouseDownTime = 0;
                const dragThreshold = 5; // pixels
                const clickTimeThreshold = 300; // milliseconds
                
                // Detect touch device
                const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
                
                // For touch devices: track last tapped node (no time constraint)
                let lastTappedNode = null;
                
                // Track mousedown to distinguish clicks from drags
                this.renderer.domElement.addEventListener('mousedown', (event) => {
                    const rect = this.renderer.domElement.getBoundingClientRect();
                    mouseDownPos = {
                        x: event.clientX - rect.left,
                        y: event.clientY - rect.top
                    };
                    mouseDownTime = Date.now();
                });
                
                // Combined mousemove handler for drag detection and cursor changes
                this.renderer.domElement.addEventListener('mousemove', (event) => {
                    // Get canvas bounding rect to account for any offset
                    const rect = this.renderer.domElement.getBoundingClientRect();
                    
                    // Calculate mouse position in normalized device coordinates (-1 to +1)
                    this.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
                    this.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
                    
                    // Use raycaster to detect which node is hovered
                    this.raycaster.setFromCamera(this.mouse, this.camera);
                    const intersects = this.raycaster.intersectObjects(this.nodes, true);
                    
                    if (intersects.length > 0) {
                        // Find the node that was hovered - traverse up to find the parent node in nodes array
                        let hoveredObject = intersects[0].object;
                        let hoveredNode = null;
                        
                        // Traverse up the parent tree to find the node in our nodes array
                        while (hoveredObject) {
                            if (this.nodes.includes(hoveredObject)) {
                                hoveredNode = hoveredObject;
                                break;
                            }
                            hoveredObject = hoveredObject.parent;
                        }
                        
                        if (hoveredNode && hoveredNode.userData) {
                            // Update cursor
                            if (hoveredNode.userData.url) {
                                this.renderer.domElement.style.cursor = 'pointer';
                            } else {
                                this.renderer.domElement.style.cursor = 'default';
                            }
                            
                            // Show tooltip with node name
                            if (this.tooltip && hoveredNode.userData.name) {
                                this.tooltip.textContent = hoveredNode.userData.name;
                                this.tooltip.classList.remove('hidden');
                                
                                // Position tooltip near mouse cursor with offset
                                const offsetX = 15;
                                const offsetY = 15;
                                let tooltipX = event.clientX + offsetX;
                                let tooltipY = event.clientY + offsetY;
                                
                                // Make sure tooltip stays within viewport
                                const tooltipRect = this.tooltip.getBoundingClientRect();
                                if (tooltipX + tooltipRect.width > window.innerWidth) {
                                    tooltipX = event.clientX - tooltipRect.width - offsetX;
                                }
                                if (tooltipY + tooltipRect.height > window.innerHeight) {
                                    tooltipY = event.clientY - tooltipRect.height - offsetY;
                                }
                                
                                this.tooltip.style.left = tooltipX + 'px';
                                this.tooltip.style.top = tooltipY + 'px';
                            }
                        } else {
                            this.renderer.domElement.style.cursor = 'default';
                            if (this.tooltip) {
                                this.tooltip.classList.add('hidden');
                            }
                        }
                    } else {
                        this.renderer.domElement.style.cursor = 'default';
                        if (this.tooltip) {
                            this.tooltip.classList.add('hidden');
                        }
                    }
                });
                
                // Hide tooltip when mouse leaves the canvas
                this.renderer.domElement.addEventListener('mouseleave', () => {
                    if (this.tooltip) {
                        this.tooltip.classList.add('hidden');
                    }
                    this.renderer.domElement.style.cursor = 'default';
                });
                
                // Reset drag tracking on mouseup
                this.renderer.domElement.addEventListener('mouseup', () => {
                    mouseDownPos = null;
                    mouseDownTime = 0;
                });
                
                // Helper function to get node from event coordinates
                const getNodeFromEvent = (event) => {
                    const rect = this.renderer.domElement.getBoundingClientRect();
                    const clientX = event.clientX !== undefined ? event.clientX : event.touches[0]?.clientX || event.changedTouches[0]?.clientX;
                    const clientY = event.clientY !== undefined ? event.clientY : event.touches[0]?.clientY || event.changedTouches[0]?.clientY;
                    
                    if (clientX === undefined || clientY === undefined) return null;
                    
                    // Calculate mouse position in normalized device coordinates (-1 to +1)
                    this.mouse.x = ((clientX - rect.left) / rect.width) * 2 - 1;
                    this.mouse.y = -((clientY - rect.top) / rect.height) * 2 + 1;
                    
                    // Use raycaster to detect which node was clicked/tapped
                    this.raycaster.setFromCamera(this.mouse, this.camera);
                    const intersects = this.raycaster.intersectObjects(this.nodes, true);
                    
                    if (intersects.length > 0) {
                        // Find the node - traverse up to find the parent node in nodes array
                        let clickedObject = intersects[0].object;
                        let clickedNode = null;
                        
                        // Traverse up the parent tree to find the node in our nodes array
                        while (clickedObject) {
                            if (this.nodes.includes(clickedObject)) {
                                clickedNode = clickedObject;
                                break;
                            }
                            clickedObject = clickedObject.parent;
                        }
                        
                        return clickedNode;
                    }
                    return null;
                };
                
                // Helper function to show tooltip for a node at a position
                const showTooltipForNode = (node, x, y) => {
                    if (this.tooltip && node && node.userData && node.userData.name) {
                        this.tooltip.textContent = node.userData.name;
                        this.tooltip.classList.remove('hidden');
                        
                        // Position tooltip near tap/click position with offset
                        const offsetX = 15;
                        const offsetY = 15;
                        let tooltipX = x + offsetX;
                        let tooltipY = y + offsetY;
                        
                        // Make sure tooltip stays within viewport
                        const tooltipRect = this.tooltip.getBoundingClientRect();
                        if (tooltipX + tooltipRect.width > window.innerWidth) {
                            tooltipX = x - tooltipRect.width - offsetX;
                        }
                        if (tooltipY + tooltipRect.height > window.innerHeight) {
                            tooltipY = y - tooltipRect.height - offsetY;
                        }
                        
                        this.tooltip.style.left = tooltipX + 'px';
                        this.tooltip.style.top = tooltipY + 'px';
                    }
                };
                
                // Handle click events on nodes (desktop)
                this.renderer.domElement.addEventListener('click', (event) => {
                    // Skip for touch devices - handled by touchstart
                    if (isTouchDevice) {
                        return;
                    }
                    
                    // Check if this was a drag (mouse moved more than threshold) or took too long (drag operation)
                    let isDrag = false;
                    if (mouseDownPos) {
                        const rect = this.renderer.domElement.getBoundingClientRect();
                        const dx = event.clientX - rect.left - mouseDownPos.x;
                        const dy = event.clientY - rect.top - mouseDownPos.y;
                        const distance = Math.sqrt(dx * dx + dy * dy);
                        const timeElapsed = Date.now() - mouseDownTime;
                        
                        if (distance > dragThreshold || timeElapsed > clickTimeThreshold) {
                            isDrag = true;
                        }
                    }
                    
                    // Only handle if it's a click, not a drag (OrbitControls handles drags)
                    if (isDrag) {
                        return;
                    }
                    
                    const clickedNode = getNodeFromEvent(event);
                    
                    // Desktop behavior: click directly opens URL
                    if (clickedNode && clickedNode.userData && clickedNode.userData.url) {
                        event.preventDefault();
                        event.stopPropagation();
                        window.open(clickedNode.userData.url, '_blank', 'noopener,noreferrer');
                    }
                });
                
                // Handle touch events on nodes (mobile)
                if (isTouchDevice) {
                    let touchStartPos = null;
                    let touchStartTime = 0;
                    let touchStartNode = null;
                    const touchDragThreshold = 10; // pixels
                    
                    // Track touch start
                    this.renderer.domElement.addEventListener('touchstart', (event) => {
                        const touch = event.touches[0];
                        if (!touch) return;
                        
                        const rect = this.renderer.domElement.getBoundingClientRect();
                        touchStartPos = {
                            x: touch.clientX - rect.left,
                            y: touch.clientY - rect.top,
                            screenX: touch.clientX,
                            screenY: touch.clientY
                        };
                        touchStartTime = Date.now();
                        
                        // Get node at touch start position
                        touchStartNode = getNodeFromEvent(event);
                        
                        // If we're on a node, don't prevent yet - we'll check on touchend if it was a tap
                        // This allows OrbitControls to still handle drags
                    }, { passive: false });
                    
                    // Track touch move - if user drags, it's not a tap
                    this.renderer.domElement.addEventListener('touchmove', (event) => {
                        if (!touchStartPos || !touchStartNode) return;
                        
                        const touch = event.touches[0];
                        if (!touch) return;
                        
                        const dx = touch.clientX - touchStartPos.screenX;
                        const dy = touch.clientY - touchStartPos.screenY;
                        const distance = Math.sqrt(dx * dx + dy * dy);
                        
                        // If moved more than threshold, clear the node (it's a drag, not a tap)
                        if (distance > touchDragThreshold) {
                            touchStartNode = null; // Clear so touchend won't treat it as a tap
                        }
                    }, { passive: false });
                    
                    // Handle touch end - this is where we detect taps
                    this.renderer.domElement.addEventListener('touchend', (event) => {
                        const touch = event.changedTouches[0];
                        if (!touch || !touchStartPos) {
                            touchStartPos = null;
                            touchStartNode = null;
                            return;
                        }
                        
                        // Use stored screen coordinates for distance calculation
                        const dx = touch.clientX - touchStartPos.screenX;
                        const dy = touch.clientY - touchStartPos.screenY;
                        const distance = Math.sqrt(dx * dx + dy * dy);
                        const timeElapsed = Date.now() - touchStartTime;
                        
                        // Only treat as tap if it was quick and didn't move much
                        const isTap = distance < touchDragThreshold && timeElapsed < 300;
                        
                        if (isTap && touchStartNode) {
                            const clickedNode = touchStartNode;
                            
                            // Check if this is the second tap on the same node (regardless of time)
                            if (lastTappedNode === clickedNode) {
                                // Second tap on same node - open URL
                                if (clickedNode.userData && clickedNode.userData.url) {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    window.open(clickedNode.userData.url, '_blank', 'noopener,noreferrer');
                                    lastTappedNode = null; // Reset after opening
                                    touchStartPos = null;
                                    touchStartNode = null;
                                    return;
                                }
                            } else {
                                // First tap or tap on different node - show tooltip
                                lastTappedNode = clickedNode;
                                
                                // Show tooltip at touch position (use the stored start position)
                                showTooltipForNode(clickedNode, touchStartPos.screenX, touchStartPos.screenY);
                            }
                        } else if (isTap && !touchStartNode) {
                            // Tapped on empty space - hide tooltip and reset
                            lastTappedNode = null;
                            if (this.tooltip) {
                                this.tooltip.classList.add('hidden');
                            }
                        }
                        
                        // Reset touch tracking
                        touchStartPos = null;
                        touchStartNode = null;
                    }, { passive: false });
                }
            }

            createStarNode(material) {
                // Create a star shape using a group
                const starGroup = new THREE.Group();
                
                // Central bright sphere (4x larger: 0.06 * 4 = 0.24)
                const centerGeometry = new THREE.SphereGeometry(0.24, 8, 8);
                const center = new THREE.Mesh(centerGeometry, material);
                starGroup.add(center);
                
                // Create 8 spikes/rays for the star using octahedron (4x larger: 0.1 * 4 = 0.4)
                const spikeGeometry = new THREE.OctahedronGeometry(0.4, 0);
                const spikeMaterial = material.clone();
                spikeMaterial.emissiveIntensity = material.emissiveIntensity * 1.2;
                
                // Create spikes pointing in different directions (4x larger: 0.08 * 4 = 0.32)
                const directions = [
                    [0, 1, 0], [0, -1, 0], [1, 0, 0], [-1, 0, 0],
                    [0, 0, 1], [0, 0, -1], 
                    [0.7, 0.7, 0], [-0.7, 0.7, 0], [0.7, -0.7, 0], [-0.7, -0.7, 0]
                ];
                
                directions.forEach((dir, i) => {
                    const spike = new THREE.Mesh(spikeGeometry, spikeMaterial);
                    spike.position.set(dir[0] * 0.32, dir[1] * 0.32, dir[2] * 0.32);
                    spike.scale.set(0.3, 0.3, 0.3);
                    starGroup.add(spike);
                });
                
                return starGroup;
            }

            createNodes() {
                // Clear existing nodes and any other objects
                this.nodes.forEach(node => {
                    this.scene.remove(node);
                    if (node.children) {
                        node.children.forEach(child => {
                            if (child.geometry) child.geometry.dispose();
                            if (child.material) child.material.dispose();
                        });
                    }
                });
                this.nodes = [];
                
                // Clear all connections
                this.connections.forEach(conn => {
                    this.scene.remove(conn.mesh);
                    if (conn.mesh.geometry) conn.mesh.geometry.dispose();
                    if (conn.mesh.material) conn.mesh.material.dispose();
                });
                this.connections = [];
                
                // Remove any remaining objects from scene that aren't lights or controls
                const objectsToRemove = [];
                this.scene.traverse((object) => {
                    // Keep lights, cameras, and controls
                    if (object.isLight || object.isCamera || object.isAmbientLight || object.isPointLight || object.isDirectionalLight) {
                        return;
                    }
                    // Remove everything else that's not a node we just cleared
                    if (object.isMesh || object.isGroup || object.isLine || object.isLineSegments) {
                        objectsToRemove.push(object);
                    }
                });
                objectsToRemove.forEach(obj => {
                    this.scene.remove(obj);
                    if (obj.geometry) obj.geometry.dispose();
                    if (obj.material) {
                        if (Array.isArray(obj.material)) {
                            obj.material.forEach(mat => mat.dispose());
                        } else {
                            obj.material.dispose();
                        }
                    }
                });
                
                // Create nodes from API data
                // Track positions to ensure uniqueness
                const usedPositions = [];
                
                this.nodeData.forEach((nodeData, i) => {
                    const anim = nodeData.animation;
                    
                    // Generate random position for this node (ignoring database position)
                    // Random position within a sphere of radius 10, ensuring uniqueness
                    // Each node gets a different seed offset (i) to ensure different positions
                    // Base seed uses Date.now() so each page load gets different positions
                    const randomPos = this.generateRandomPosition(usedPositions, i);
                    usedPositions.push(randomPos);
                    
                    // Generate random pastel color for this node
                    // Each node gets a different seed offset (i) to ensure different colors
                    // Base seed uses Date.now() so each page load gets different colors
                    const pastelColor = this.generateRandomPastelColor(i);
                    
                    // Create color from HSL for potential future use
                    const threeColor = new THREE.Color().setHSL(
                        pastelColor.hue,
                        pastelColor.saturation,
                        pastelColor.lightness
                    );
                    
                    // Convert HSL to RGB for CSS
                    const rgb = threeColor;
                    const r = Math.round(rgb.r * 255);
                    const g = Math.round(rgb.g * 255);
                    const b = Math.round(rgb.b * 255);
                    
                    // Create star node with material
                    const nodeName = nodeData.name || `Node ${i + 1}`;
                    
                    // Create material for the star with the pastel color
                    const starMaterial = new THREE.MeshStandardMaterial({
                        color: threeColor,
                        emissive: threeColor,
                        emissiveIntensity: 0.5,
                        metalness: 0.3,
                        roughness: 0.7
                    });
                    
                    // Create the star shape
                    const node = this.createStarNode(starMaterial);
                    node.position.set(randomPos.x, randomPos.y, randomPos.z);
                    
                    // Add animation properties from database
                    node.userData = {
                        id: nodeData.id || i, // Database ID
                        index: i, // Array index
                        name: nodeName,
                        description: nodeData.description || null,
                        keywords: nodeData.keywords || [], // Keywords array
                        url: nodeData.url || null, // URL to open when clicked
                        originalPosition: new THREE.Vector3(randomPos.x, randomPos.y, randomPos.z), // Random base position
                        speed: anim.speed / 4, // 1/4 speed
                        baseSpeed: anim.speed / 4,
                        phase: anim.phase,
                        animationState: 'normal',
                        stateTimer: Math.random() * 3000 + 2000,
                        stateChangeTime: Date.now()
                    };

                    this.nodes.push(node);
                    this.scene.add(node);
                });
            }

            // Calculate number of shared keywords between two nodes
            getSharedKeywordsCount(node1, node2) {
                const keywords1 = node1.userData.keywords || [];
                const keywords2 = node2.userData.keywords || [];
                
                // Find intersection of keywords arrays
                const shared = keywords1.filter(k => keywords2.includes(k));
                return shared.length;
            }

            // Create connection lines between nodes that share keywords
            createConnections() {
                // Clear existing connections
                this.connections.forEach(conn => {
                    this.scene.remove(conn.mesh);
                    if (conn.mesh.geometry) conn.mesh.geometry.dispose();
                    if (conn.mesh.material) conn.mesh.material.dispose();
                });
                this.connections = [];

                if (this.nodes.length < 2) {
                    console.log('Not enough nodes to create connections');
                    return;
                }

                console.log(`Creating connections for ${this.nodes.length} nodes`);

                // Check all pairs of nodes
                for (let i = 0; i < this.nodes.length; i++) {
                    for (let j = i + 1; j < this.nodes.length; j++) {
                        const node1 = this.nodes[i];
                        const node2 = this.nodes[j];
                        
                        // Calculate shared keywords
                        const sharedCount = this.getSharedKeywordsCount(node1, node2);
                        
                        // Only create connection if nodes share at least one keyword
                        if (sharedCount > 0) {
                            // Calculate line thickness: 1px minimum, 7px maximum
                            // Thickness scales linearly with shared keyword count (1-7 keywords = 1-7px)
                            // Clamp sharedCount to 1-7 range, then scale: 1 keyword = 0.01 units (1px), 7 keywords = 0.07 units (7px)
                            const clampedCount = Math.min(7, Math.max(1, sharedCount));
                            const thickness = clampedCount * 0.01; // 0.01 units per keyword (representing 1px per keyword)
                            
                            // Get positions of both nodes
                            const pos1 = this.getNodeAnchorPosition(node1);
                            const pos2 = this.getNodeAnchorPosition(node2);
                            
                            // Calculate distance and direction
                            const direction = new THREE.Vector3().subVectors(pos2, pos1);
                            const distance = direction.length();
                            
                            // Create cylinder geometry for the connection line
                            const geometry = new THREE.CylinderGeometry(
                                thickness, // top radius
                                thickness, // bottom radius
                                distance,  // height
                                8          // radial segments
                            );
                            
                            // Create material with brighter color for better visibility
                            const material = new THREE.MeshBasicMaterial({
                                color: 0xffffff, // White for better visibility
                                transparent: true,
                                opacity: 0.8,
                                side: THREE.DoubleSide // Ensure both sides are visible
                            });
                            
                            // Create mesh
                            const cylinder = new THREE.Mesh(geometry, material);
                            
                            // Position and orient the cylinder
                            // Cylinder is created along Y-axis, so we need to rotate it
                            const midpoint = new THREE.Vector3().addVectors(pos1, pos2).multiplyScalar(0.5);
                            cylinder.position.copy(midpoint);
                            
                            // Rotate to align with direction
                            const up = new THREE.Vector3(0, 1, 0);
                            const quaternion = new THREE.Quaternion().setFromUnitVectors(up, direction.normalize());
                            cylinder.quaternion.copy(quaternion);
                            
                            // Store connection info including original distance for scaling
                            this.connections.push({
                                mesh: cylinder,
                                node1: node1,
                                node2: node2,
                                sharedCount: sharedCount,
                                originalDistance: distance
                            });
                            
                            this.scene.add(cylinder);
                            console.log(`Created connection between nodes ${i} and ${j} with ${sharedCount} shared keywords, thickness: ${thickness}, distance: ${distance}`);
                        }
                    }
                }
            }

            // Update connection positions as nodes move
            updateConnections() {
                if (!this.connections || this.connections.length === 0) {
                    return;
                }
                
                const anchorCache = new Map();
                const getAnchor = (node) => {
                    if (anchorCache.has(node)) {
                        return anchorCache.get(node);
                    }
                    const anchor = this.getNodeAnchorPosition(node);
                    anchorCache.set(node, anchor);
                    return anchor;
                };

                this.connections.forEach(conn => {
                    const node1 = conn.node1;
                    const node2 = conn.node2;
                    
                    // Get current world positions aligned to label centers
                    const pos1 = getAnchor(node1);
                    const pos2 = getAnchor(node2);
                    
                    // Calculate new direction and distance
                    const direction = new THREE.Vector3().subVectors(pos2, pos1);
                    const distance = direction.length();
                    
                    if (distance < 0.001) {
                        // Nodes are too close, hide connection
                        conn.mesh.visible = false;
                        return;
                    }
                    
                    conn.mesh.visible = true;
                    
                    // Update cylinder position
                    const midpoint = new THREE.Vector3().addVectors(pos1, pos2).multiplyScalar(0.5);
                    conn.mesh.position.copy(midpoint);
                    
                    // Update cylinder height (scale along Y-axis, which is the cylinder's length)
                    // Use stored original distance if available, otherwise use geometry height
                    const originalDistance = conn.originalDistance || conn.mesh.geometry.parameters.height;
                    if (originalDistance > 0) {
                        conn.mesh.scale.y = distance / originalDistance;
                    }
                    
                    // Update rotation to align with new direction
                    const up = new THREE.Vector3(0, 1, 0);
                    const normalizedDirection = direction.clone().normalize();
                    const quaternion = new THREE.Quaternion().setFromUnitVectors(up, normalizedDirection);
                    conn.mesh.quaternion.copy(quaternion);
                });
            }

            // Seeded random number generator for consistent but different results per page load
            seededRandom(seed) {
                // Simple seeded PRNG (Linear Congruential Generator)
                let state = seed;
                return function() {
                    state = (state * 9301 + 49297) % 233280;
                    return state / 233280;
                };
            }

            // Generate random pastel color (HSL)
            // Pastel colors have high lightness (0.7-0.9) and moderate saturation (0.4-0.7)
            generateRandomPastelColor(seedOffset = 0) {
                const baseSeed = Date.now() + Math.random() * 1000000;
                const seed = baseSeed + seedOffset;
                const rng = this.seededRandom(seed);
                
                // Pastel color range:
                // Hue: 0 to 1 (full spectrum)
                // Saturation: 0.4 to 0.7 (moderate, not too vibrant)
                // Lightness: 0.7 to 0.9 (light, pastel-like)
                return {
                    hue: rng(), // 0 to 1
                    saturation: 0.4 + rng() * 0.3, // 0.4 to 0.7
                    lightness: 0.7 + rng() * 0.2 // 0.7 to 0.9
                };
            }

            // Generate a random 3D position within a sphere
            // Ensures uniqueness by checking against existing positions
            generateRandomPosition(existingPositions = [], seedOffset = 0) {
                // Seed the random generator with current time + offset for uniqueness per page load
                // Each page load gets a different base seed, ensuring different positions
                const baseSeed = Date.now() + Math.random() * 1000000;
                const seed = baseSeed + seedOffset;
                
                const minDistance = 0.5; // Minimum distance between nodes
                let attempts = 0;
                const maxAttempts = 100;
                
                while (attempts < maxAttempts) {
                    // Generate random position within a sphere of radius 10
                    // Using spherical coordinates for even distribution
                    // Use a new seed for each attempt to ensure different positions
                    const attemptSeed = seed + attempts * 1000;
                    const attemptRng = this.seededRandom(attemptSeed);
                    
                    const radius = attemptRng() * 10; // Random radius from 0 to 10
                    const theta = attemptRng() * Math.PI * 2; // Random angle around z-axis (0 to 2π)
                    const phi = Math.acos(2 * attemptRng() - 1); // Random angle from z-axis (0 to π)
                    
                    const newPos = {
                        x: radius * Math.sin(phi) * Math.cos(theta),
                        y: radius * Math.sin(phi) * Math.sin(theta),
                        z: radius * Math.cos(phi)
                    };
                    
                    // Check if this position is too close to any existing position
                    let tooClose = false;
                    for (const existingPos of existingPositions) {
                        const dx = newPos.x - existingPos.x;
                        const dy = newPos.y - existingPos.y;
                        const dz = newPos.z - existingPos.z;
                        const distance = Math.sqrt(dx * dx + dy * dy + dz * dz);
                        
                        if (distance < minDistance) {
                            tooClose = true;
                            break;
                        }
                    }
                    
                    if (!tooClose) {
                        return newPos;
                    }
                    
                    attempts++;
                }
                
                // If we couldn't find a unique position after max attempts, return a position anyway
                // (shouldn't happen unless there are too many nodes)
                const finalSeed = seed + attempts * 1000;
                const finalRng = this.seededRandom(finalSeed);
                const radius = finalRng() * 10;
                const theta = finalRng() * Math.PI * 2;
                const phi = Math.acos(2 * finalRng() - 1);
                return {
                    x: radius * Math.sin(phi) * Math.cos(theta),
                    y: radius * Math.sin(phi) * Math.sin(theta),
                    z: radius * Math.cos(phi)
                };
            }

            updateNodes() {
                const time = Date.now() * 0.001 / 4; // 1/4 speed
                const currentTime = Date.now();
                
                this.nodes.forEach((node, index) => {
                    const data = node.userData;
                    
                    // Check if it's time to change animation state
                    if (currentTime - data.stateChangeTime > data.stateTimer) {
                        // Randomly select a new state
                        const states = ['normal', 'twitch', 'glow', 'fast', 'slow'];
                        data.animationState = states[Math.floor(Math.random() * states.length)];
                        data.stateChangeTime = currentTime;
                        data.stateTimer = Math.random() * 4000 + 2000; // 2-6 seconds
                    }
                    
                    // Apply state-specific speed multiplier
                    let speedMultiplier = 1.0;
                    if (data.animationState === 'fast') {
                        speedMultiplier = 2.5;
                    } else if (data.animationState === 'slow') {
                        speedMultiplier = 0.4;
                    }
                    data.speed = data.baseSpeed * speedMultiplier;
                    
                    // Use the random original position as the base
                    const basePos = data.originalPosition;
                    
                    // Add subtle floating motion around the random position
                    const floatX = Math.sin(time + data.phase) * 0.3;
                    const floatY = Math.cos(time * 0.7 + data.phase) * 0.3;
                    const floatZ = Math.sin(time * 0.5 + data.phase) * 0.2;
                    
                    // Apply twitch effect
                    let twitchX = 0, twitchY = 0, twitchZ = 0;
                    if (data.animationState === 'twitch') {
                        const twitchIntensity = Math.sin(time * 15 + data.phase) * 0.15; // Fast twitching
                        twitchX = twitchIntensity;
                        twitchY = twitchIntensity * 0.7;
                        twitchZ = twitchIntensity * 0.5;
                    }
                    
                    // Set position: random base + floating animation + twitch effect
                    node.position.x = basePos.x + floatX + twitchX;
                    node.position.y = basePos.y + floatY + twitchY;
                    node.position.z = basePos.z + floatZ + twitchZ;
                    
                    // Pulsing scale for breathing effect on star nodes
                    let scaleMultiplier = 1.0;
                    if (data.animationState === 'twitch') {
                        scaleMultiplier = 1 + Math.sin(time * 20 + data.phase) * 0.15; // Fast pulsing
                    } else {
                        scaleMultiplier = 1 + Math.sin(time * 3 + data.phase) * 0.2;
                    }
                    node.scale.set(scaleMultiplier, scaleMultiplier, scaleMultiplier);
                });
            }

            animate() {
                requestAnimationFrame(() => this.animate());
                
                this.updateNodes();
                this.updateConnections();
                
                this.controls.update();
                this.renderer.render(this.scene, this.camera);
            }

            onWindowResize() {
                this.camera.aspect = window.innerWidth / window.innerHeight;
                this.camera.updateProjectionMatrix();
                this.renderer.setSize(window.innerWidth, window.innerHeight);
            }
        }

        // Initialize the application when DOM is ready
        function initTelaris() {
            try {
                const canvasContainer = document.getElementById('canvas-container');
                if (!canvasContainer) {
                    console.error('Canvas container not found!');
                    return;
                }
                new TelarisNetwork();
            } catch (error) {
                console.error('Error initializing TelarisNetwork:', error);
                console.error('Error stack:', error.stack);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initTelaris);
        } else {
            initTelaris();
        }
    </script>
</body>
</html>
