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
    <style>
        #node-tooltip {
            transition: opacity 0.75s ease-in-out;
        }
        .persistent-tooltip-item {
            transition: opacity 0.75s ease-in-out;
        }
        #starfield-background {
            pointer-events: none;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
        }
        .star-dot {
            position: absolute;
            border-radius: 50%;
            background: #fff;
            will-change: opacity;
        }
        @keyframes star-blink {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 1; }
        }
    </style>
</head>
<body class="overflow-hidden bg-black font-sans">
        <div id="canvas-container" class="relative" style="position: relative; width: 100vw; height: 100vh; min-height: 100vh;">
        <div id="starfield-background" class="absolute z-0" style="inset: 0;" aria-hidden="true"></div>
        <div id="webgl-canvas-wrapper" class="absolute inset-0" style="z-index: 1;"></div>
        <div id="persistent-tooltips" class="absolute inset-0 pointer-events-none z-[150]" style="font-family: inherit;"></div>
        <div id="node-tooltip" class="absolute px-3 py-2 rounded text-sm pointer-events-none z-[200]" style="font-family: inherit; opacity: 0; visibility: hidden;"></div>
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
                    alpha: true,
                    premultipliedAlpha: false
                });
                
                this.nodes = [];
                this.nodeData = []; // Store node data from API
                this.connections = []; // Store connection lines between nodes
                this.raycaster = new THREE.Raycaster();
                this.mouse = new THREE.Vector2();
                this.tooltip = document.getElementById('node-tooltip');
                this.persistentTooltipsContainer = document.getElementById('persistent-tooltips');
                this.mainTooltipNode = null; // node currently shown by the main hover/tap tooltip (skip in persistent labels)
                this.tooltipHideTimeout = null; // for fade-out delay before hiding main tooltip
                this.persistentTooltipNodeToDiv = new Map(); // node -> div, so we never reuse a div for a different node (ensures full fade-out then fade-in)

                // Idle rotation (auto-rotate when user is inactive)
                this.lastInteractionAt = performance.now();
                this.idleRotateDelayMs = 4500; // wait ~4.5s of inactivity before rotating
                
                this.init();
            }

            getNodeAnchorPosition(node) {
                // Simply return the node's world position
                // Star nodes are centered at their 3D position
                const worldPos = new THREE.Vector3();
                node.getWorldPosition(worldPos);
                return worldPos;
            }

            getNodeTooltipStyles(node) {
                const d = node.userData;
                if (!d || d.colorR === undefined) return { background: 'rgba(0,0,0,0.35)', color: 'rgb(255,255,255)' };
                const r = d.colorR, g = d.colorG, b = d.colorB;
                const darken = 0.5;
                const dr = Math.round(r * darken), dg = Math.round(g * darken), db = Math.round(b * darken);
                return {
                    background: `rgba(${dr},${dg},${db},0.35)`,
                    color: `rgb(${r},${g},${b})`
                };
            }

            markInteraction() {
                this.lastInteractionAt = performance.now();
            }

            initStarfield(container) {
                const starfield = document.getElementById('starfield-background');
                if (!starfield) return;
                const starCount = 180;
                for (let i = 0; i < starCount; i++) {
                    const star = document.createElement('div');
                    star.className = 'star-dot';
                    star.style.left = (Math.random() * 100) + '%';
                    star.style.top = (Math.random() * 100) + '%';
                    const size = 1 + Math.random() * 2;
                    star.style.width = size + 'px';
                    star.style.height = size + 'px';
                    const gray = Math.floor(180 + Math.random() * 75);
                    star.style.background = `rgb(${gray},${gray},${gray + 20})`;
                    const duration = 1.8 + Math.random() * 2.8;
                    const delay = Math.random() * 4;
                    star.style.animation = `star-blink ${duration}s ease-in-out ${delay}s infinite`;
                    starfield.appendChild(star);
                }
            }

            hideMainTooltip() {
                if (!this.tooltip) return;
                if (this.tooltipHideTimeout) {
                    clearTimeout(this.tooltipHideTimeout);
                }
                this.tooltip.style.opacity = '0';
                this.tooltipHideTimeout = setTimeout(() => {
                    this.tooltip.style.visibility = 'hidden';
                    this.tooltipHideTimeout = null;
                }, 780);
            }

            init() {
                // Setup renderer
                this.renderer.setSize(window.innerWidth, window.innerHeight);
                this.renderer.setPixelRatio(window.devicePixelRatio);
                this.renderer.setClearColor(0x000000, 0);
                const canvasElement = this.renderer.domElement;
                canvasElement.style.position = 'absolute';
                canvasElement.style.left = '0';
                canvasElement.style.top = '0';
                canvasElement.style.width = '100%';
                canvasElement.style.height = '100%';
                canvasElement.style.display = 'block';
                canvasElement.style.backgroundColor = 'transparent';
                const canvasContainer = document.getElementById('canvas-container');
                const canvasWrapper = document.getElementById('webgl-canvas-wrapper');
                this.initStarfield(canvasContainer);
                if (canvasWrapper) {
                    canvasWrapper.appendChild(canvasElement);
                } else {
                    canvasContainer.appendChild(canvasElement);
                }
                canvasContainer.appendChild(this.tooltip);

                // Setup camera
                this.camera.position.set(0, 0, 15);

                // Add controls
                this.controls = new OrbitControls(this.camera, this.renderer.domElement);
                this.controls.enableDamping = true;
                this.controls.dampingFactor = 0.05;
                this.controls.minDistance = 5;
                this.controls.maxDistance = 30;
                this.controls.autoRotate = false;
                this.controls.autoRotateSpeed = 0.35; // subtle, not distracting
                // Look at a point below center so the graph appears higher on screen
                this.controls.target.set(0, -1.8, 0);

                // Any interaction with controls counts as activity (stops idle rotation)
                this.controls.addEventListener('start', () => this.markInteraction());
                this.controls.addEventListener('end', () => this.markInteraction());
                this.controls.addEventListener('change', () => this.markInteraction());

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
                    this.markInteraction();
                    const rect = this.renderer.domElement.getBoundingClientRect();
                    mouseDownPos = {
                        x: event.clientX - rect.left,
                        y: event.clientY - rect.top
                    };
                    mouseDownTime = Date.now();
                });
                
                // Combined mousemove handler for drag detection and cursor changes
                this.renderer.domElement.addEventListener('mousemove', (event) => {
                    this.markInteraction();
                    // Get canvas bounding rect to account for any offset
                    const rect = this.renderer.domElement.getBoundingClientRect();
                    
                    // Calculate mouse position in normalized device coordinates (-1 to +1)
                    this.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
                    this.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
                    
                    // Use raycaster to detect which node is hovered (pick front-most under cursor)
                    this.raycaster.setFromCamera(this.mouse, this.camera);
                    const intersects = this.raycaster.intersectObjects(this.nodes, true);
                    if (intersects.length > 0) {
                        intersects.sort((a, b) => a.distance - b.distance);
                        let hoveredNode = null;
                        for (const hit of intersects) {
                            let obj = hit.object;
                            while (obj) {
                                if (this.nodes.includes(obj)) {
                                    hoveredNode = obj;
                                    break;
                                }
                                obj = obj.parent;
                            }
                            if (hoveredNode) break;
                        }
                        if (hoveredNode && hoveredNode.userData) {
                            if (hoveredNode.userData.url) {
                                this.renderer.domElement.style.cursor = 'pointer';
                            } else {
                                this.renderer.domElement.style.cursor = 'default';
                            }
                            if (this.tooltip && hoveredNode.userData.name) {
                                if (this.tooltipHideTimeout) {
                                    clearTimeout(this.tooltipHideTimeout);
                                    this.tooltipHideTimeout = null;
                                }
                                this.mainTooltipNode = hoveredNode;
                                this.tooltip.textContent = hoveredNode.userData.name;
                                const tipStyles = this.getNodeTooltipStyles(hoveredNode);
                                this.tooltip.style.background = tipStyles.background;
                                this.tooltip.style.color = tipStyles.color;
                                this.tooltip.style.visibility = 'visible';
                                this.tooltip.style.display = 'block';
                                this.tooltip.style.opacity = '0';
                                const projected = new THREE.Vector3();
                                hoveredNode.getWorldPosition(projected);
                                const distanceToCamera = projected.distanceTo(this.camera.position);
                                projected.project(this.camera);
                                const container = this.renderer.domElement.parentElement;
                                const containerRect = container ? container.getBoundingClientRect() : rect;
                                const tooltipGap = -12;
                                const tooltipYOffset = 34 + Math.max(0, (18 - distanceToCamera) * 1.5);
                                const tooltipX = (rect.left - containerRect.left) + (projected.x * 0.5 + 0.5) * rect.width;
                                const tooltipY = (rect.top - containerRect.top) + (0.5 - projected.y * 0.5) * rect.height + tooltipYOffset;
                                this.tooltip.style.left = tooltipX + 'px';
                                this.tooltip.style.top = tooltipY + 'px';
                                this.tooltip.style.transform = `translate(-50%, -50%) translate(${tooltipGap}px, 0)`;
                                requestAnimationFrame(() => {
                                    requestAnimationFrame(() => {
                                        this.tooltip.style.opacity = '1';
                                    });
                                });
                            }
                        } else {
                            this.renderer.domElement.style.cursor = 'default';
                            if (this.tooltip) this.hideMainTooltip();
                        }
                    } else {
                        this.renderer.domElement.style.cursor = 'default';
                        if (this.tooltip) this.hideMainTooltip();
                    }
                });
                
                // Hide tooltip when mouse leaves the canvas
                this.renderer.domElement.addEventListener('mouseleave', () => {
                    this.markInteraction();
                    this.mainTooltipNode = null;
                    if (this.tooltip) this.hideMainTooltip();
                    this.renderer.domElement.style.cursor = 'default';
                });
                
                // Reset drag tracking on mouseup
                this.renderer.domElement.addEventListener('mouseup', () => {
                    this.markInteraction();
                    mouseDownPos = null;
                    mouseDownTime = 0;
                });

                // Other interactions that should cancel idle rotation
                this.renderer.domElement.addEventListener('wheel', () => this.markInteraction(), { passive: true });
                window.addEventListener('keydown', () => this.markInteraction(), { passive: true });
                
                // Helper function to get node from event coordinates
                const getNodeFromEvent = (event) => {
                    const rect = this.renderer.domElement.getBoundingClientRect();
                    const clientX = event.clientX !== undefined ? event.clientX : event.touches[0]?.clientX || event.changedTouches[0]?.clientX;
                    const clientY = event.clientY !== undefined ? event.clientY : event.touches[0]?.clientY || event.changedTouches[0]?.clientY;
                    
                    if (clientX === undefined || clientY === undefined) return null;
                    
                    // Calculate mouse position in normalized device coordinates (-1 to +1)
                    this.mouse.x = ((clientX - rect.left) / rect.width) * 2 - 1;
                    this.mouse.y = -((clientY - rect.top) / rect.height) * 2 + 1;
                    
                    // Use raycaster to detect which node was clicked/tapped (pick front-most: closest to camera)
                    this.raycaster.setFromCamera(this.mouse, this.camera);
                    const intersects = this.raycaster.intersectObjects(this.nodes, true);
                    if (intersects.length > 0) {
                        intersects.sort((a, b) => a.distance - b.distance);
                        for (const hit of intersects) {
                            let obj = hit.object;
                            while (obj) {
                                if (this.nodes.includes(obj)) {
                                    return obj;
                                }
                                obj = obj.parent;
                            }
                        }
                    }
                    return null;
                };
                
                // Front 10% by Z: distance threshold (nodes with distance <= this are closest to camera)
                const getFront10PercentThreshold = () => {
                    const tempPos = new THREE.Vector3();
                    const distances = this.nodes.map(n => {
                        n.getWorldPosition(tempPos);
                        return tempPos.distanceTo(this.camera.position);
                    });
                    distances.sort((a, b) => a - b);
                    const idx = Math.max(0, Math.floor(this.nodes.length * 0.1));
                    return distances[idx];
                };
                const isNodeInFront10Percent = (node, threshold) => {
                    const tempPos = new THREE.Vector3();
                    node.getWorldPosition(tempPos);
                    return tempPos.distanceTo(this.camera.position) <= threshold;
                };
                
                // Helper function to show tooltip for a node (positioned at node with 3px gap, same as hover/persistent)
                const showTooltipForNode = (node, x, y) => {
                    if (this.tooltip && node && node.userData && node.userData.name) {
                        if (this.tooltipHideTimeout) {
                            clearTimeout(this.tooltipHideTimeout);
                            this.tooltipHideTimeout = null;
                        }
                        this.tooltip.textContent = node.userData.name;
                        const tipStyles = this.getNodeTooltipStyles(node);
                        this.tooltip.style.background = tipStyles.background;
                        this.tooltip.style.color = tipStyles.color;
                        this.tooltip.style.visibility = 'visible';
                        this.tooltip.style.display = 'block';
                        this.tooltip.style.opacity = '0';
                        const rect = this.renderer.domElement.getBoundingClientRect();
                        const container = this.renderer.domElement.parentElement;
                        const containerRect = container ? container.getBoundingClientRect() : rect;
                        const projected = new THREE.Vector3();
                        node.getWorldPosition(projected);
                        const distanceToCamera = projected.distanceTo(this.camera.position);
                        projected.project(this.camera);
                        const tooltipGap = -12;
                        const tooltipYOffset = 34 + Math.max(0, (18 - distanceToCamera) * 1.5);
                        const tooltipX = (rect.left - containerRect.left) + (projected.x * 0.5 + 0.5) * rect.width;
                        const tooltipY = (rect.top - containerRect.top) + (0.5 - projected.y * 0.5) * rect.height + tooltipYOffset;
                        this.tooltip.style.left = tooltipX + 'px';
                        this.tooltip.style.top = tooltipY + 'px';
                        this.tooltip.style.transform = `translate(-50%, -50%) translate(${tooltipGap}px, 0)`;
                        requestAnimationFrame(() => {
                            requestAnimationFrame(() => {
                                this.tooltip.style.opacity = '1';
                            });
                        });
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
                        this.markInteraction();
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
                        this.markInteraction();
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
                        this.markInteraction();
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
                                    lastTappedNode = null;
                                    this.mainTooltipNode = null;
                                    touchStartPos = null;
                                    touchStartNode = null;
                                    return;
                                }
                            } else {
                                // First tap or tap on different node - always show tooltip for the tapped node
                                lastTappedNode = clickedNode;
                                this.mainTooltipNode = clickedNode;
                                showTooltipForNode(clickedNode, touchStartPos.screenX, touchStartPos.screenY);
                            }
                        } else if (isTap && !touchStartNode) {
                            // Tapped on empty space - hide tooltip and reset
                            lastTappedNode = null;
                            this.mainTooltipNode = null;
                            if (this.tooltip) this.hideMainTooltip();
                        }
                        
                        // Reset touch tracking
                        touchStartPos = null;
                        touchStartNode = null;
                    }, { passive: false });
                }
            }

            createStarNode(material) {
                const starGroup = new THREE.Group();
                const centerGeometry = new THREE.SphereGeometry(0.24, 8, 8);
                const center = new THREE.Mesh(centerGeometry, material);
                starGroup.add(center);
                const spikeGeometry = new THREE.OctahedronGeometry(0.4, 0);
                const spikeMaterial = material.clone();
                spikeMaterial.emissiveIntensity = material.emissiveIntensity * 1.2;
                const directions = [
                    [0, 1, 0], [0, -1, 0], [1, 0, 0], [-1, 0, 0],
                    [0, 0, 1], [0, 0, -1],
                    [0.7, 0.7, 0], [-0.7, 0.7, 0], [0.7, -0.7, 0], [-0.7, -0.7, 0]
                ];
                directions.forEach((dir) => {
                    const spike = new THREE.Mesh(spikeGeometry, spikeMaterial);
                    spike.position.set(dir[0] * 0.32, dir[1] * 0.32, dir[2] * 0.32);
                    spike.scale.set(0.3, 0.3, 0.3);
                    starGroup.add(spike);
                });
                return starGroup;
            }

            createMoonNode(material) {
                const group = new THREE.Group();
                const geo = new THREE.SphereGeometry(0.28, 16, 16);
                group.add(new THREE.Mesh(geo, material));
                return group;
            }

            createFivePointStarNode(material) {
                // Sun: outer circle with inner circle “bite” so only a thin C-shaped sliver remains (Flaticon-style)
                const group = new THREE.Group();
                const R = 0.28, r = 0.11;
                const shape = new THREE.Shape();
                for (let i = 0; i < 10; i++) {
                    const angle = (i / 5) * Math.PI - Math.PI / 2;
                    const radius = (i % 2 === 0) ? R : r;
                    const x = Math.cos(angle) * radius;
                    const y = Math.sin(angle) * radius;
                    if (i === 0) shape.moveTo(x, y);
                    else shape.lineTo(x, y);
                }
                shape.closePath();
                const starMat = material.clone();
                starMat.side = THREE.DoubleSide;
                const geo = new THREE.ExtrudeGeometry(shape, { depth: 0.06, bevelEnabled: false });
                const mesh = new THREE.Mesh(geo, starMat);
                group.add(mesh);
                return group;
            }

            createAsteroidNode(material) {
                const group = new THREE.Group();
                const geo = new THREE.IcosahedronGeometry(0.22, 1);
                group.add(new THREE.Mesh(geo, material));
                return group;
            }

            createSparkleNode(material) {
                const group = new THREE.Group();
                const coreGeo = new THREE.TetrahedronGeometry(0.2, 0);
                group.add(new THREE.Mesh(coreGeo, material));
                const rayMat = material.clone();
                rayMat.emissiveIntensity = material.emissiveIntensity * 1.3;
                const rayGeo = new THREE.ConeGeometry(0.06, 0.22, 5);
                const up = new THREE.Vector3(0, 1, 0);
                const dirs = [[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]];
                dirs.forEach(([x, y, z]) => {
                    const ray = new THREE.Mesh(rayGeo, rayMat);
                    const out = new THREE.Vector3(x, y, z).normalize();
                    ray.position.copy(out).multiplyScalar(0.18);
                    ray.quaternion.setFromUnitVectors(up, out);
                    group.add(ray);
                });
                return group;
            }

            createNodeIcon(material, index) {
                const icons = [
                    () => this.createStarNode(material),
                    () => this.createMoonNode(material),
                    () => this.createFivePointStarNode(material),
                    () => this.createAsteroidNode(material),
                    () => this.createSparkleNode(material)
                ];
                const choice = (index * 1103515245 + 12345) >>> 0;
                return icons[choice % 5]();
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
                // Goal: positions should be well distributed, visible, and centered in the viewport.
                const usedPositions = [];
                const rawPositions = [];

                // Camera-based bounds: use full viewport so all distributions utilize as much screen as possible
                const cameraZ = this.camera.position.z;
                const halfHeight = Math.tan(THREE.MathUtils.degToRad(this.camera.fov * 0.5)) * cameraZ;
                const halfWidth = halfHeight * this.camera.aspect;
                const layoutMargin = 0.98;

                const layoutBounds = {
                    xMax: halfWidth * layoutMargin,
                    yMax: halfHeight * layoutMargin,
                    zMax: cameraZ * 0.52
                };

                for (let i = 0; i < this.nodeData.length; i++) {
                    const pos = this.generateRandomPosition(usedPositions, i, layoutBounds);
                    usedPositions.push(pos);
                    rawPositions.push(new THREE.Vector3(pos.x, pos.y, pos.z));
                }

                // Recentre around origin to prevent drift
                const centroid = new THREE.Vector3(0, 0, 0);
                rawPositions.forEach(p => centroid.add(p));
                if (rawPositions.length > 0) {
                    centroid.multiplyScalar(1 / rawPositions.length);
                }
                rawPositions.forEach(p => p.sub(centroid));

                // Find actual maximum extent in each axis after centering
                let maxAbsX = 0, maxAbsY = 0, maxAbsZ = 0;
                rawPositions.forEach(p => {
                    maxAbsX = Math.max(maxAbsX, Math.abs(p.x));
                    maxAbsY = Math.max(maxAbsY, Math.abs(p.y));
                    maxAbsZ = Math.max(maxAbsZ, Math.abs(p.z));
                });

                const extentEpsilon = 0.01;
                const n = rawPositions.length;
                // When one axis has no spread, force positions to span [-1, 1] so scaling uses full screen
                if (maxAbsX < extentEpsilon && n > 1) {
                    rawPositions.forEach((p, i) => { p.x = (2 * i / (n - 1)) - 1; });
                    maxAbsX = 1;
                } else if (maxAbsX < extentEpsilon) maxAbsX = 1;
                if (maxAbsY < extentEpsilon && n > 1) {
                    rawPositions.forEach((p, i) => { p.y = (2 * i / (n - 1)) - 1; });
                    maxAbsY = 1;
                } else if (maxAbsY < extentEpsilon) maxAbsY = 1;
                if (maxAbsZ < extentEpsilon && n > 1) {
                    rawPositions.forEach((p, i) => { p.z = (2 * i / (n - 1)) - 1; });
                    maxAbsZ = 1;
                } else if (maxAbsZ < extentEpsilon) maxAbsZ = 1;

                // Safe bounds: use perspective frustum so all nodes are visible at load.
                // Use distance to front of *scaled* cloud (safeZ) so X/Y bounds match what's visible at that plane.
                const marginFactor = 0.95;
                const safeZ = layoutBounds.zMax * marginFactor;
                const distAtClosestZ = Math.max(0.1, cameraZ - safeZ);
                const tanHalfFov = Math.tan(THREE.MathUtils.degToRad(this.camera.fov * 0.5));
                // Leave clear headroom for float (±0.3) and twitch (±0.15) so no node goes off-screen
                const fillX = 0.88;
                const fillY = 0.84;
                const safeX = distAtClosestZ * tanHalfFov * this.camera.aspect * fillX;
                const safeY = Math.max(1, distAtClosestZ * tanHalfFov * fillY);

                const scaleX = safeX / maxAbsX;
                const scaleY = safeY / maxAbsY;
                const scaleZ = safeZ / maxAbsZ;

                rawPositions.forEach(p => {
                    // Scale each axis independently to ensure all nodes fit within safe bounds
                    p.x *= scaleX;
                    p.y *= scaleY;
                    p.z *= scaleZ;
                });

                // No pull step: keep random distribution so connected nodes stay spread out (longer lines, less crammed)

                for (let i = 0; i < this.nodeData.length; i++) {
                    const nodeData = this.nodeData[i];
                    const anim = nodeData.animation;
                    const randomPos = rawPositions[i];

                    // Generate random pastel color for this node
                    const pastelColor = this.generateRandomPastelColor(i);

                    // Create color from HSL
                    const threeColor = new THREE.Color().setHSL(
                        pastelColor.hue,
                        pastelColor.saturation,
                        pastelColor.lightness
                    );

                    // Create node with material
                    const nodeName = nodeData.name || `Node ${i + 1}`;

                    // Create material for the star with the pastel color
                    const starMaterial = new THREE.MeshStandardMaterial({
                        color: threeColor,
                        emissive: threeColor,
                        emissiveIntensity: 0.5,
                        metalness: 0.3,
                        roughness: 0.7,
                        transparent: true,
                        opacity: 0.94
                    });

                    // Create a random constellation-themed icon (star, moon, five-point star, asteroid, sparkle)
                    const node = this.createNodeIcon(starMaterial, i);
                    node.position.copy(randomPos);

                    // Store RGB for tooltip styling (background 5%, text 100%)
                    const r = Math.round(threeColor.r * 255), g = Math.round(threeColor.g * 255), b = Math.round(threeColor.b * 255);
                    // Add animation properties from database
                    node.userData = {
                        id: nodeData.id || i, // Database ID
                        index: i, // Array index
                        name: nodeName,
                        description: nodeData.description || null,
                        keywords: nodeData.keywords || [], // Keywords array
                        url: nodeData.url || null, // URL to open when clicked
                        originalPosition: randomPos.clone(), // Random base position (centered + scaled)
                        speed: anim.speed / 4, // 1/4 speed
                        baseSpeed: anim.speed / 4,
                        phase: anim.phase,
                        animationState: 'normal',
                        stateTimer: Math.random() * 3000 + 2000,
                        stateChangeTime: Date.now(),
                        colorR: r, colorG: g, colorB: b // For tooltip background (5%) and text (100%)
                    };

                    this.nodes.push(node);
                    this.scene.add(node);
                }
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

                // First pass: find max shared keywords between any pair (used as 100% for thickness)
                let maxShared = 0;
                for (let i = 0; i < this.nodes.length; i++) {
                    for (let j = i + 1; j < this.nodes.length; j++) {
                        const count = this.getSharedKeywordsCount(this.nodes[i], this.nodes[j]);
                        if (count > maxShared) maxShared = count;
                    }
                }

                // Thickness bands by strength (0–25%, 26–50%, 51–75%, 76–100%) – all relatively thin
                const THINNEST = 0.004;
                const MEDIUM_THIN = 0.008;
                const MEDIUM_THICK = 0.012;
                const THICKEST = 0.016;

                let connectionIndex = 0; // Track connection index for unique colors

                // Check all pairs of nodes
                for (let i = 0; i < this.nodes.length; i++) {
                    for (let j = i + 1; j < this.nodes.length; j++) {
                        const node1 = this.nodes[i];
                        const node2 = this.nodes[j];
                        
                        // Calculate shared keywords
                        const sharedCount = this.getSharedKeywordsCount(node1, node2);
                        
                        // Only create connection if nodes share at least one keyword (0% = no line)
                        if (sharedCount > 0) {
                            const pct = maxShared > 0 ? sharedCount / maxShared : 1;
                            // Thickness band: 0–25% thinnest, 26–50% medium-thin, 51–75% medium-thick, 76–100% thickest
                            let thickness;
                            if (pct <= 0.25) thickness = THINNEST;
                            else if (pct <= 0.5) thickness = MEDIUM_THIN;
                            else if (pct <= 0.75) thickness = MEDIUM_THICK;
                            else thickness = THICKEST;
                            
                            // Opacity by strength: 4 bands; lowest band barely visible
                            let opacity;
                            if (pct <= 0.25) {
                                opacity = 0.012;
                            } else if (pct <= 0.5) {
                                opacity = 0.18;
                            } else if (pct <= 0.75) {
                                opacity = 0.48;
                            } else {
                                opacity = 0.58;
                            }
                            
                            // Get positions of both nodes
                            const pos1 = this.getNodeAnchorPosition(node1);
                            const pos2 = this.getNodeAnchorPosition(node2);
                            
                            // Calculate distance and direction
                            const direction = new THREE.Vector3().subVectors(pos2, pos1);
                            const distance = direction.length();
                            
                            const geometry = new THREE.CylinderGeometry(
                                thickness,
                                thickness,
                                distance,
                                8
                            );
                            const colorSeed = connectionIndex * 1000 + i * 100 + j;
                            const pastelColor = this.generateRandomPastelColor(colorSeed);
                            const threeColor = new THREE.Color().setHSL(
                                pastelColor.hue,
                                pastelColor.saturation,
                                0.68
                            );
                            const material = new THREE.MeshBasicMaterial({
                                color: threeColor,
                                transparent: true,
                                opacity,
                                side: THREE.DoubleSide
                            });
                            connectionIndex++;
                            const cylinder = new THREE.Mesh(geometry, material);
                            const midpoint = new THREE.Vector3().addVectors(pos1, pos2).multiplyScalar(0.5);
                            cylinder.position.copy(midpoint);
                            const up = new THREE.Vector3(0, 1, 0);
                            const quaternion = new THREE.Quaternion().setFromUnitVectors(up, direction.clone().normalize());
                            cylinder.quaternion.copy(quaternion);
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
                    const midpoint = new THREE.Vector3().addVectors(pos1, pos2).multiplyScalar(0.5);
                    conn.mesh.position.copy(midpoint);
                    const up = new THREE.Vector3(0, 1, 0);
                    const normalizedDirection = direction.clone().normalize();
                    const quaternion = new THREE.Quaternion().setFromUnitVectors(up, normalizedDirection);
                    conn.mesh.quaternion.copy(quaternion);
                    const originalDistance = conn.originalDistance || conn.mesh.geometry?.parameters?.height;
                    if (originalDistance > 0) {
                        conn.mesh.scale.y = distance / originalDistance;
                    }
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
            // Pastel colors with higher saturation for more vivid appearance
            // Still light enough to be pastel, but more intense than before
            generateRandomPastelColor(seedOffset = 0) {
                const baseSeed = Date.now() + Math.random() * 1000000;
                const seed = baseSeed + seedOffset;
                const rng = this.seededRandom(seed);
                
                // Pastel color range (more vivid):
                // Hue: 0 to 1 (full spectrum)
                // Saturation: 0.6 to 0.85 (higher saturation for more vivid colors)
                // Lightness: 0.65 to 0.85 (slightly lower for more intensity, still pastel)
                return {
                    hue: rng(), // 0 to 1
                    saturation: 0.6 + rng() * 0.25, // 0.6 to 0.85
                    lightness: 0.65 + rng() * 0.2 // 0.65 to 0.85
                };
            }

            // Generate a random 3D position within camera-based bounds.
            // Ensures uniqueness by checking against existing positions.
            generateRandomPosition(existingPositions = [], seedOffset = 0, bounds = null) {
                // Seed the random generator with current time + offset for uniqueness per page load
                // Each page load gets a different base seed, ensuring different positions
                const baseSeed = Date.now() + Math.random() * 1000000;
                const seed = baseSeed + seedOffset;
                
                const minDistance = 2.8; // Minimum distance between nodes (larger = less crammed)
                let attempts = 0;
                const maxAttempts = 250;

                // Default bounds if not provided (more generous Z for depth variation)
                const b = bounds ?? { xMax: 8, yMax: 6, zMax: 6 };
                
                while (attempts < maxAttempts) {
                    // Uniform in full box so nodes spread evenly across X and Y (not clustered in center)
                    const attemptSeed = seed + attempts * 1000;
                    const attemptRng = this.seededRandom(attemptSeed);

                    const x = (attemptRng() * 2 - 1) * b.xMax;
                    const y = (attemptRng() * 2 - 1) * b.yMax;
                    const z = (attemptRng() * 2 - 1) * b.zMax;

                    const newPos = {
                        x,
                        y,
                        z
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
                const x = (finalRng() * 2 - 1) * b.xMax;
                const y = (finalRng() * 2 - 1) * b.yMax;
                const z = (finalRng() * 2 - 1) * b.zMax;
                return {
                    x,
                    y,
                    z
                };
            }

            updateNodes() {
                const time = Date.now() * 0.001 / 4; // 1/4 speed
                const currentTime = Date.now();
                const hasTooltip = (node) => this.mainTooltipNode === node || this.persistentTooltipNodeToDiv.has(node);

                this.nodes.forEach((node, index) => {
                    const data = node.userData;
                    node.traverse((obj) => {
                        if (obj.material) {
                            const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
                            const opacity = hasTooltip(node) ? 1 : 0.94;
                            mats.forEach(m => { m.opacity = opacity; });
                        }
                    });
                    
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
                    
                    // Pulsing scale for breathing effect on nodes
                    let scaleMultiplier = 1.0;
                    if (data.animationState === 'twitch') {
                        scaleMultiplier = 1 + Math.sin(time * 20 + data.phase) * 0.15; // Fast pulsing
                    } else {
                        scaleMultiplier = 1 + Math.sin(time * 3 + data.phase) * 0.2;
                    }
                    node.scale.set(scaleMultiplier, scaleMultiplier, scaleMultiplier);
                });
            }

            // Returns nodes in the front 10% by distance to camera (closest first). Used for persistent labels.
            getFront10PercentNodes() {
                const tempPos = new THREE.Vector3();
                const withDistance = this.nodes.map(n => {
                    n.getWorldPosition(tempPos);
                    return { node: n, distance: tempPos.distanceTo(this.camera.position) };
                });
                withDistance.sort((a, b) => a.distance - b.distance);
                const count = Math.max(1, Math.floor(this.nodes.length * 0.1));
                return withDistance.slice(0, count).map(entry => entry.node);
            }

            // Front 20% by distance: first 10% get full opacity, next 10% get 10% opacity; beyond 20% not shown.
            getFront20PercentWithTier() {
                const tempPos = new THREE.Vector3();
                const withDistance = this.nodes.map(n => {
                    n.getWorldPosition(tempPos);
                    return { node: n, distance: tempPos.distanceTo(this.camera.position) };
                });
                withDistance.sort((a, b) => a.distance - b.distance);
                const count20 = Math.max(1, Math.floor(this.nodes.length * 0.2));
                const front10Count = Math.max(1, Math.floor(this.nodes.length * 0.1));
                return withDistance.slice(0, count20).map((entry, i) => ({
                    node: entry.node,
                    inFront10: i < front10Count
                }));
            }

            updatePersistentTooltips() {
                if (!this.persistentTooltipsContainer || this.nodes.length === 0) return;
                const front20WithTier = this.getFront20PercentWithTier();
                const toShow = front20WithTier.filter(entry => entry.node !== this.mainTooltipNode && entry.node.userData && entry.node.userData.name);
                const canvasRect = this.renderer.domElement.getBoundingClientRect();
                const container = this.persistentTooltipsContainer.parentElement;
                const containerRect = container ? container.getBoundingClientRect() : canvasRect;
                const rect = canvasRect;
                const projected = new THREE.Vector3();
                const nodeToDiv = this.persistentTooltipNodeToDiv;

                const getAvailableDiv = () => {
                    for (const el of this.persistentTooltipsContainer.children) {
                        if (el.style.visibility === 'hidden' && !el._fadeOutTimeout) return el;
                    }
                    const el = document.createElement('div');
                    el.className = 'persistent-tooltip-item absolute px-1 py-0.5 rounded text-xs pointer-events-none whitespace-nowrap';
                    el.style.opacity = '0';
                    el.style.visibility = 'hidden';
                    this.persistentTooltipsContainer.appendChild(el);
                    return el;
                };

                const startPersistentFadeOut = (el, node) => {
                    if (el.style.visibility !== 'visible') return;
                    if (el._fadeOutTimeout) return;
                    nodeToDiv.delete(node);
                    el.style.opacity = '0';
                    el._fadeOutTimeout = setTimeout(() => {
                        el.style.visibility = 'hidden';
                        el._fadeOutTimeout = null;
                    }, 780);
                };

                const toShowNodes = new Set(toShow.map(entry => entry.node));
                toShow.forEach((entry) => {
                    const node = entry.node;
                    const inFront10 = entry.inFront10;
                    const tierOpacity = inFront10 ? '1' : '0.2';
                    node.getWorldPosition(projected);
                    const distanceToCamera = projected.distanceTo(this.camera.position);
                    projected.project(this.camera);
                    const ndcX = projected.x;
                    const ndcY = projected.y;
                    if (projected.z > 1 || projected.z < -1) {
                        const div = nodeToDiv.get(node);
                        if (div) startPersistentFadeOut(div, node);
                        return;
                    }
                    const yOffset = 34 + Math.max(0, (18 - distanceToCamera) * 1.5);
                    const left = (rect.left - containerRect.left) + (ndcX * 0.5 + 0.5) * rect.width;
                    const top = (rect.top - containerRect.top) + (0.5 - ndcY * 0.5) * rect.height + yOffset;
                    const gap = -12;
                    let el = nodeToDiv.get(node);
                    if (el) {
                        el.textContent = node.userData.name;
                        el.style.left = left + 'px';
                        el.style.top = top + 'px';
                        el.style.transform = `translate(-50%, -50%) translate(${gap}px, 0)`;
                        const styles = this.getNodeTooltipStyles(node);
                        el.style.background = styles.background;
                        el.style.color = styles.color;
                        el.style.opacity = tierOpacity;
                        return;
                    }
                    el = getAvailableDiv();
                    nodeToDiv.set(node, el);
                    el.textContent = node.userData.name;
                    el.style.left = left + 'px';
                    el.style.top = top + 'px';
                    el.style.transform = `translate(-50%, -50%) translate(${gap}px, 0)`;
                    const styles = this.getNodeTooltipStyles(node);
                    el.style.background = styles.background;
                    el.style.color = styles.color;
                    el.style.visibility = 'visible';
                    el.style.opacity = '0';
                    const elToFade = el;
                    const targetOpacity = tierOpacity;
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            elToFade.style.opacity = targetOpacity;
                        });
                    });
                });

                for (const [node, div] of Array.from(nodeToDiv)) {
                    if (!toShowNodes.has(node)) startPersistentFadeOut(div, node);
                }
            }

            animate() {
                requestAnimationFrame(() => this.animate());
                
                // Enable subtle auto-rotation when idle; stop immediately on interaction
                const idleForMs = performance.now() - this.lastInteractionAt;
                this.controls.autoRotate = idleForMs > this.idleRotateDelayMs;

                // Apply controls (zoom/rotate/pan) first so camera is current before we compute front nodes and opacities
                this.controls.update();
                this.updatePersistentTooltips();
                this.updateNodes();
                this.updateConnections();
                
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
