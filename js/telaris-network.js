import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { apiFetch } from './api.js';
import { createNodeIcon } from './telaris-node-icons.js';
import { NetworkManager } from './network-manager.js';
import { GeometryManager } from './geometry-manager.js';

class TelarisNetwork {
    constructor() {
        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
        this.renderer = new THREE.WebGLRenderer({
            antialias: true,
            alpha: true,
            premultipliedAlpha: false
        });

        this.nodes = [];
        this.connections = [];
        this.networkManager = new NetworkManager({ fadeSpeed: 0.1 });
        this.geometryManager = new GeometryManager();
        this.raycaster = new THREE.Raycaster();
        this.mouse = new THREE.Vector2();
        this.tooltip = document.getElementById('node-tooltip');
        this.persistentTooltipsContainer = document.getElementById('persistent-tooltips');
        this.mainTooltipNodeTimeout = null;
        this.tooltipHideTimeout = null;
        this.persistentTooltipNodeToDiv = new Map();

        // Idle rotation
        this.lastInteractionAt = performance.now();
        this.idleRotateDelayMs = 4500;
        this._lastFrameTime = 0;

        this.init();
    }

    getNodeAnchorPosition(node) {
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

    openInFrame(node, url) {
        const d = node && node.userData;
        const r = (d && d.colorR !== undefined) ? d.colorR : 60;
        const g = (d && d.colorG !== undefined) ? d.colorG : 60;
        const b = (d && d.colorB !== undefined) ? d.colorB : 80;
        const app = typeof window.TELARIS_APP_NAME === 'string' ? window.TELARIS_APP_NAME : 'Telaris';
        let alertMsg = typeof window.TELARIS_ALERT_MESSAGE === 'string' ? window.TELARIS_ALERT_MESSAGE : "Close this window when you're done to go back to " + app + ".";
        alertMsg = alertMsg.replace(/\{APPNAME\}/g, app);
        const frameUrl = 'utils/frame.php?url=' + encodeURIComponent(url) + '&r=' + r + '&g=' + g + '&b=' + b + '&app=' + encodeURIComponent(app) + '&alert_msg=' + encodeURIComponent(alertMsg);
        window.open(frameUrl, '_blank', 'noopener,noreferrer');
    }

    markInteraction() {
        this.lastInteractionAt = performance.now();
    }

    initStarfield() {
        const starCount = 1000;
        const geometry = new THREE.BufferGeometry();
        const positions = new Float32Array(starCount * 3);
        const colors = new Float32Array(starCount * 3);

        for (let i = 0; i < starCount; i++) {
            const r = 40 + Math.random() * 60;
            const theta = Math.random() * Math.PI * 2;
            const phi = Math.acos(2 * Math.random() - 1);
            positions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
            positions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
            positions[i * 3 + 2] = r * Math.cos(phi);

            const color = new THREE.Color();
            color.setHSL(0.6, 0.2, 0.8 + Math.random() * 0.2);
            colors[i * 3] = color.r;
            colors[i * 3 + 1] = color.g;
            colors[i * 3 + 2] = color.b;
        }

        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));

        const material = new THREE.PointsMaterial({
            size: 0.12,
            vertexColors: true,
            transparent: true,
            opacity: 0.8,
            sizeAttenuation: true
        });

        this.stars = new THREE.Points(geometry, material);
        this.scene.add(this.stars);
    }

    updateStarfield(time) {
        if (!this.stars) return;
        this.stars.rotation.y = time * 0.02;
        this.stars.rotation.x = time * 0.01;
        this.stars.material.opacity = 0.6 + Math.sin(time * 2) * 0.2;
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
        this.renderer.setSize(window.innerWidth, window.innerHeight);
        this.renderer.setPixelRatio(window.devicePixelRatio);
        this.renderer.setClearColor(0x000000, 0);
        
        const canvasElement = this.renderer.domElement;
        Object.assign(canvasElement.style, {
            position: 'absolute',
            left: '0',
            top: '0',
            width: '100%',
            height: '100%',
            display: 'block',
            backgroundColor: 'transparent'
        });

        const canvasContainer = document.getElementById('canvas-container');
        const canvasWrapper = document.getElementById('webgl-canvas-wrapper');
        
        this.initStarfield();
        if (canvasWrapper) {
            canvasWrapper.appendChild(canvasElement);
        } else {
            canvasContainer.appendChild(canvasElement);
        }
        canvasContainer.appendChild(this.tooltip);

        this.camera.position.set(0, 0, 15);

        // Setup Post-processing (Bloom)
        const renderScene = new RenderPass(this.scene, this.camera);
        renderScene.clearAlpha = 0;
        
        const bloomPass = new UnrealBloomPass(
            new THREE.Vector2(window.innerWidth, window.innerHeight),
            0.6, 0.3, 0.9
        );
        bloomPass.renderToScreen = true;
        bloomPass.clear = false;
        
        const renderTarget = new THREE.WebGLRenderTarget(
            window.innerWidth, window.innerHeight,
            {
                minFilter: THREE.LinearFilter,
                magFilter: THREE.LinearFilter,
                format: THREE.RGBAFormat,
                type: THREE.UnsignedByteType,
                samples: 4
            }
        );
        
        this.composer = new EffectComposer(this.renderer, renderTarget);
        this.composer.addPass(renderScene);
        this.composer.addPass(bloomPass);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.05;
        this.controls.minDistance = 5;
        this.controls.maxDistance = 30;
        this.controls.zoomSpeed = ('ontouchstart' in window || navigator.maxTouchPoints > 0) ? 1.5 : 4.0;
        this.controls.autoRotate = false;
        this.controls.autoRotateSpeed = 0.35;
        this.controls.target.set(0, -1.8, 0);

        this.controls.addEventListener('start', () => this.markInteraction());
        this.controls.addEventListener('end', () => this.markInteraction());
        this.controls.addEventListener('change', () => this.markInteraction());

        this.scene.add(new THREE.AmbientLight(0xffffff, 0.3));
        const lights = [
            [0x4a90e2, 10, 10, 10],
            [0xe24a90, -10, -10, 10],
            [0x90e24a, 0, 10, -10]
        ];
        lights.forEach(([col, x, y, z]) => {
            const lp = new THREE.PointLight(col, 1, 50);
            lp.position.set(x, y, z);
            this.scene.add(lp);
        });

        this.setupMouseInteraction();
        window.addEventListener('resize', () => this.onWindowResize());
        
        this.loadApiKey().then(() => this.loadData());
        this.initComet();
        this.initRocket();
        this.initUFO();
        this.animate();
    }

    initUFO() {
        this.ufo = new THREE.Group();
        
        // Disk: metallic silver
        const diskGeo = new THREE.CylinderGeometry(0.15, 0.15, 0.03, 16);
        const diskMat = new THREE.MeshStandardMaterial({ 
            color: 0xcccccc, 
            metalness: 0.9, 
            roughness: 0.1,
            emissive: 0x333333 
        });
        const disk = new THREE.Mesh(diskGeo, diskMat);
        this.ufo.add(disk);

        // Dome: glass
        const domeGeo = new THREE.SphereGeometry(0.06, 12, 8, 0, Math.PI * 2, 0, Math.PI / 2);
        const domeMat = new THREE.MeshBasicMaterial({ color: 0x00ffff, transparent: true, opacity: 0.5 });
        const dome = new THREE.Mesh(domeGeo, domeMat);
        dome.position.y = 0.01;
        this.ufo.add(dome);

        this.ufo.visible = false;
        this.ufo.userData = { 
            active: false, 
            state: 'idle', // 'idle', 'hovering', 'leaving'
            timer: 0 
        };
        this.scene.add(this.ufo);
    }

    updateUFO(dt) {
        if (!this.ufo) return;
        const d = this.ufo.userData;

        if (!d.active) {
            // Very rare: 0.02% chance per frame (~once every 2 mins)
            if (this.nodes.length > 0 && Math.random() < 0.0002) {
                d.active = true;
                d.state = 'hovering';
                d.timer = 3.0; // 3 seconds scan
                this.ufo.visible = true;
                
                const randomNode = this.nodes[Math.floor(Math.random() * this.nodes.length)];
                this.ufo.position.copy(randomNode.position).add(new THREE.Vector3(0, 0.5, 0));
                d.departureDir = new THREE.Vector3(Math.random() - 0.5, Math.random() - 0.5, Math.random() - 0.5).normalize();
            }
            return;
        }

        if (d.state === 'hovering') {
            d.timer -= dt;
            // Wobble/Tilt
            this.ufo.rotation.z = Math.sin(performance.now() * 0.01) * 0.2;
            this.ufo.rotation.x = Math.cos(performance.now() * 0.01) * 0.1;
            
            if (d.timer <= 0) {
                d.state = 'leaving';
            }
        } else if (d.state === 'leaving') {
            // Extreme acceleration
            const speed = 100.0;
            this.ufo.position.add(d.departureDir.clone().multiplyScalar(speed * dt));
            
            if (this.ufo.position.length() > 200) {
                d.active = false;
                this.ufo.visible = false;
            }
        }
    }

    initComet() {
        this.comet = new THREE.Group();
        
        // Head: bright white sphere
        const headGeo = this.geometryManager.getSphere(0.12, 8);
        const headMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
        const head = new THREE.Mesh(headGeo, headMat);
        this.comet.add(head);

        // Tail: tapering cylinder that starts AT the head
        // Radius top (front) is same as sphere radius, radius bottom (back) is near 0
        const tailGeo = new THREE.CylinderGeometry(0.12, 0.01, 1.2, 8);
        const tailMat = new THREE.MeshBasicMaterial({ 
            color: 0xccccff, 
            transparent: true, 
            opacity: 0.4 
        });
        const tail = new THREE.Mesh(tailGeo, tailMat);
        // Position it so the wide part sits in the center of the sphere
        tail.position.z = -0.6; 
        tail.rotation.x = Math.PI / 2; // Orient along Z
        this.comet.add(tail);

        this.comet.visible = false;
        this.comet.userData = { active: false, speed: 0.2 };
        this.scene.add(this.comet);
    }

    updateComet(dt) {
        if (!this.comet) return;
        
        if (!this.comet.userData.active) {
            // 0.1% chance per frame to start a fly-by (~once every 15-30 seconds)
            if (Math.random() < 0.001) {
                this.comet.userData.active = true;
                this.comet.visible = true;
                // Start from a random position far away
                this.comet.position.set(
                    (Math.random() - 0.5) * 60,
                    (Math.random() - 0.5) * 60,
                    (Math.random() - 0.5) * 40
                );
                // Aim toward the other side
                this.comet.userData.target = new THREE.Vector3(
                    -this.comet.position.x + (Math.random() - 0.5) * 20,
                    -this.comet.position.y + (Math.random() - 0.5) * 20,
                    -this.comet.position.z + (Math.random() - 0.5) * 20
                ).normalize();
            }
            return;
        }

        // Move comet
        const speed = 30.0; // Units per second (doubled)
        const movement = this.comet.userData.target.clone().multiplyScalar(speed * dt);
        this.comet.position.add(movement);

        // Point comet toward travel direction (head first, tail trails behind)
        this.comet.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), this.comet.userData.target);

        // Deactivate if far away
        if (this.comet.position.length() > 100) {
            this.comet.userData.active = false;
            this.comet.visible = false;
        }
    }

    initRocket() {
        this.rocket = new THREE.Group();
        
        // Body: white cylinder
        const bodyGeo = new THREE.CylinderGeometry(0.04, 0.04, 0.2, 6);
        const bodyMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
        const body = new THREE.Mesh(bodyGeo, bodyMat);
        body.rotation.x = Math.PI / 2; // Orient along Z
        this.rocket.add(body);

        // Tip: VERY red and slightly larger cone
        const tipGeo = new THREE.ConeGeometry(0.045, 0.1, 6);
        // Using MeshStandardMaterial with emissive to pierce through bloom
        const tipMat = new THREE.MeshStandardMaterial({ 
            color: 0xff0000,
            emissive: 0xff0000,
            emissiveIntensity: 2.0
        });
        const tip = new THREE.Mesh(tipGeo, tipMat);
        tip.position.z = 0.15; // Position at the front
        tip.rotation.x = Math.PI / 2; // Orient along Z
        this.rocket.add(tip);

        this.rocket.visible = false;
        this.rocket.userData = { active: false, progress: 0 };
        this.scene.add(this.rocket);
    }

    updateRocket(dt) {
        if (!this.rocket) return;

        if (!this.rocket.userData.active) {
            // 0.2% chance per frame to launch a rocket between ANY two connected nodes
            if (this.connections.length > 0 && Math.random() < 0.002) {
                const conn = this.connections[Math.floor(Math.random() * this.connections.length)];
                this.rocket.userData.active = true;
                // Randomly choose direction
                const reverse = Math.random() > 0.5;
                this.rocket.userData.node1 = reverse ? conn.node2 : conn.node1;
                this.rocket.userData.node2 = reverse ? conn.node1 : conn.node2;
                this.rocket.userData.progress = 0;
                this.rocket.visible = true;
            }
            return;
        }

        // Advance rocket
        this.rocket.userData.progress += dt * 0.4; // Slower speed (approx 2.5 seconds to cross)
        this.rocket.position.lerpVectors(
            this.rocket.userData.node1.position, 
            this.rocket.userData.node2.position, 
            this.rocket.userData.progress
        );

        // Point rocket toward target
        const dir = new THREE.Vector3().subVectors(
            this.rocket.userData.node2.position,
            this.rocket.userData.node1.position
        ).normalize();
        this.rocket.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), dir);

        if (this.rocket.userData.progress >= 1) {
            this.rocket.userData.active = false;
            this.rocket.visible = false;
        }
    }

    async loadApiKey() {
        try {
            const response = await fetch('config.php');
            const config = await response.json();
            if (config.api_key) window.TELARIS_API_KEY = config.api_key;
        } catch (error) {
            console.error('Failed to load API key:', error);
        }
    }

    async loadData() {
        try {
            if (!window.TELARIS_API_KEY) {
                await new Promise(r => setTimeout(r, 100));
                if (!window.TELARIS_API_KEY) throw new Error('API key not available');
            }
            
            const constellationId = window.TELARIS_CONSTELLATION_ID || 0;
            const response = await apiFetch(`api/nodes.php?constellation_id=${constellationId}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            
            const nodesJson = await response.json();
            const nodeData = Array.isArray(nodesJson) ? nodesJson : [];
            
            if (nodeData.length > 0) {
                this.createNodes(nodeData);
                this.createConnections();
                this.warmupPhysics();
                this.fitCameraToNodes();
                
                this.nodes.forEach(n => this.scene.add(n));
                this.connections.forEach(c => this.scene.add(c.mesh));
            } else {
                this.clearAll();
            }
        } catch (error) {
            console.error('Error loading data:', error);
            this.clearAll();
        } finally {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) overlay.style.display = 'none';
        }
    }

    clearAll() {
        this.nodes.forEach(node => {
            this.scene.remove(node);
            node.traverse(c => {
                if (c.geometry) c.geometry.dispose();
                if (c.material) (Array.isArray(c.material) ? c.material : [c.material]).forEach(m => m.dispose());
            });
        });
        this.nodes = [];
        
        this.connections.forEach(c => {
            this.scene.remove(c.mesh);
            if (c.mesh.geometry) c.mesh.geometry.dispose();
            if (c.mesh.material) c.mesh.material.dispose();
        });
        this.connections = [];
    }

    setupMouseInteraction() {
        let mouseDownPos = null;
        let mouseDownTime = 0;
        const dragThreshold = 5;
        const clickTimeThreshold = 300;
        const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        
        this.renderer.domElement.addEventListener('mousedown', (e) => {
            this.markInteraction();
            const rect = this.renderer.domElement.getBoundingClientRect();
            mouseDownPos = { x: e.clientX - rect.left, y: e.clientY - rect.top };
            mouseDownTime = Date.now();
        });
        
        this.renderer.domElement.addEventListener('mousemove', (e) => {
            this.markInteraction();
            const rect = this.renderer.domElement.getBoundingClientRect();
            this.mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
            this.mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
            
            this.raycaster.setFromCamera(this.mouse, this.camera);
            const intersects = this.raycaster.intersectObjects(this.nodes, true);
            
            let hoveredNode = null;
            if (intersects.length > 0) {
                intersects.sort((a, b) => a.distance - b.distance);
                for (const hit of intersects) {
                    let obj = hit.object;
                    while (obj && !this.nodes.includes(obj)) obj = obj.parent;
                    if (obj) { hoveredNode = obj; break; }
                }
            }

            if (hoveredNode && hoveredNode.userData) {
                if (this.mainTooltipNodeTimeout) {
                    clearTimeout(this.mainTooltipNodeTimeout);
                    this.mainTooltipNodeTimeout = null;
                }
                this.networkManager.setFocusedNode(hoveredNode);
                this.renderer.domElement.style.cursor = hoveredNode.userData.url ? 'pointer' : 'default';
                
                if (this.tooltip && hoveredNode.userData.name) {
                    if (this.tooltipHideTimeout) {
                        clearTimeout(this.tooltipHideTimeout);
                        this.tooltipHideTimeout = null;
                    }
                    
                    let html = `<div style="font-weight:600; margin-bottom: 2px;">${hoveredNode.userData.name}</div>`;
                    if (hoveredNode.userData.keywords?.length > 0) {
                        html += `<div style="opacity: 0.8; font-size: 0.75rem; display: flex; flex-wrap: wrap; gap: 4px;">`;
                        hoveredNode.userData.keywords.forEach(kw => {
                            html += `<span style="background: rgba(255,255,255,0.15); padding: 1px 4px; border-radius: 2px;">#${kw}</span>`;
                        });
                        html += `</div>`;
                    }
                    this.tooltip.innerHTML = html;

                    const styles = this.getNodeTooltipStyles(hoveredNode);
                    Object.assign(this.tooltip.style, {
                        background: styles.background,
                        color: styles.color,
                        visibility: 'visible',
                        display: 'block',
                        opacity: '0'
                    });

                    const projected = new THREE.Vector3();
                    hoveredNode.getWorldPosition(projected);
                    const dist = projected.distanceTo(this.camera.position);
                    projected.project(this.camera);
                    
                    const tooltipYOffset = 34 + Math.max(0, (18 - dist) * 1.5);
                    const x = (projected.x * 0.5 + 0.5) * rect.width;
                    const y = (0.5 - projected.y * 0.5) * rect.height + tooltipYOffset;
                    
                    Object.assign(this.tooltip.style, {
                        left: x + 'px',
                        top: y + 'px',
                        transform: 'translate(-50%, -50%) translate(-12px, 0)'
                    });
                    
                    requestAnimationFrame(() => requestAnimationFrame(() => { this.tooltip.style.opacity = '1'; }));
                }
            } else {
                this.renderer.domElement.style.cursor = 'default';
                if (!this.mainTooltipNodeTimeout) {
                    this.mainTooltipNodeTimeout = setTimeout(() => {
                        this.networkManager.setFocusedNode(null);
                        this.mainTooltipNodeTimeout = null;
                    }, 1000);
                }
                if (this.tooltip) this.hideMainTooltip();
            }
        });
        
        this.renderer.domElement.addEventListener('mouseleave', () => {
            this.markInteraction();
            if (!this.mainTooltipNodeTimeout) {
                this.mainTooltipNodeTimeout = setTimeout(() => {
                    this.networkManager.setFocusedNode(null);
                    this.mainTooltipNodeTimeout = null;
                }, 1000);
            }
            if (this.tooltip) this.hideMainTooltip();
            this.renderer.domElement.style.cursor = 'default';
        });

        this.renderer.domElement.addEventListener('mouseup', () => {
            this.markInteraction();
            mouseDownPos = null;
            mouseDownTime = 0;
        });

        this.renderer.domElement.addEventListener('click', (event) => {
            if (isTouchDevice) return;
            
            let isDrag = false;
            if (mouseDownPos) {
                const rect = this.renderer.domElement.getBoundingClientRect();
                const dx = event.clientX - rect.left - mouseDownPos.x;
                const dy = event.clientY - rect.top - mouseDownPos.y;
                const distance = Math.hypot(dx, dy);
                const timeElapsed = Date.now() - mouseDownTime;
                if (distance > dragThreshold || timeElapsed > clickTimeThreshold) isDrag = true;
            }
            
            if (isDrag) return;
            
            const clickedNode = getNodeFromEvent(event);
            if (clickedNode?.userData?.url) {
                event.preventDefault();
                event.stopPropagation();
                this.openInFrame(clickedNode, clickedNode.userData.url);
            }
        }, true);

        // Wheel & Trackpad
        const tempOffset = new THREE.Vector3();
        const tempSpherical = new THREE.Spherical();
        this.renderer.domElement.addEventListener('wheel', (e) => {
            this.markInteraction();
            if (e.ctrlKey) return;
            
            const isMouseWheel = e.deltaMode === 1 || (e.deltaMode === 0 && Math.abs(e.deltaX) === 0);
            if (isMouseWheel) {
                e.preventDefault();
                tempOffset.subVectors(this.camera.position, this.controls.target);
                tempSpherical.setFromVector3(tempOffset);
                tempSpherical.radius += e.deltaY * (e.deltaMode === 1 ? 0.02 : 0.002) * this.controls.zoomSpeed;
                tempSpherical.radius = THREE.MathUtils.clamp(tempSpherical.radius, this.controls.minDistance, this.controls.maxDistance);
                tempOffset.setFromSpherical(tempSpherical);
                this.camera.position.copy(this.controls.target).add(tempOffset);
                this.camera.lookAt(this.controls.target);
                return;
            }
            
            if (Math.abs(e.deltaX) > 0 || Math.abs(e.deltaY) > 0) {
                e.preventDefault();
                e.stopPropagation();
                tempOffset.subVectors(this.camera.position, this.controls.target);
                tempSpherical.setFromVector3(tempOffset);
                tempSpherical.theta += e.deltaX * 0.002;
                tempSpherical.phi = THREE.MathUtils.clamp(tempSpherical.phi + e.deltaY * 0.002, 0.05, Math.PI - 0.05);
                tempOffset.setFromSpherical(tempSpherical);
                this.camera.position.copy(this.controls.target).add(tempOffset);
                this.camera.lookAt(this.controls.target);
            }
        }, { passive: false, capture: true });

        window.addEventListener('keydown', () => this.markInteraction(), { passive: true });
        
        // Touch Handlers
        if (isTouchDevice) {
            let touchStartPos = null;
            let touchStartTime = 0;
            let touchStartNode = null;
            
            this.renderer.domElement.addEventListener('touchstart', (e) => {
                this.markInteraction();
                const t = e.touches[0];
                if (!t) return;
                const rect = this.renderer.domElement.getBoundingClientRect();
                touchStartPos = { x: t.clientX - rect.left, y: t.clientY - rect.top, screenX: t.clientX, screenY: t.clientY };
                touchStartTime = Date.now();
                touchStartNode = getNodeFromEvent(e);
            }, { passive: false });
            
            this.renderer.domElement.addEventListener('touchmove', (e) => {
                this.markInteraction();
                if (!touchStartPos || !touchStartNode) return;
                const t = e.touches[0];
                if (Math.hypot(t.clientX - touchStartPos.screenX, t.clientY - touchStartPos.screenY) > 10) touchStartNode = null;
            }, { passive: false });
            
            this.renderer.domElement.addEventListener('touchend', (e) => {
                this.markInteraction();
                if (!touchStartPos) return;
                const t = e.changedTouches[0];
                const isTap = Math.hypot(t.clientX - touchStartPos.screenX, t.clientY - touchStartPos.screenY) < 10 && (Date.now() - touchStartTime) < 300;
                
                if (isTap && touchStartNode) {
                    if (touchStartNode.userData.url) {
                        e.preventDefault();
                        this.openInFrame(touchStartNode, touchStartNode.userData.url);
                        this.networkManager.setFocusedNode(null);
                    } else {
                        if (this.mainTooltipNodeTimeout) clearTimeout(this.mainTooltipNodeTimeout);
                        this.networkManager.setFocusedNode(touchStartNode);
                        showTooltipForNode(touchStartNode, touchStartPos.screenX, touchStartPos.screenY);
                    }
                } else if (isTap) {
                    if (!this.mainTooltipNodeTimeout) {
                        this.mainTooltipNodeTimeout = setTimeout(() => {
                            this.networkManager.setFocusedNode(null);
                            this.mainTooltipNodeTimeout = null;
                        }, 1000);
                    }
                    if (this.tooltip) this.hideMainTooltip();
                }
                touchStartPos = null; touchStartNode = null;
            }, { passive: false });
        }

        const getNodeFromEvent = (event) => {
            const rect = this.renderer.domElement.getBoundingClientRect();
            const clientX = event.clientX !== undefined ? event.clientX : event.touches[0]?.clientX || event.changedTouches[0]?.clientX;
            const clientY = event.clientY !== undefined ? event.clientY : event.touches[0]?.clientY || event.changedTouches[0]?.clientY;
            if (clientX === undefined) return null;
            
            this.mouse.x = ((clientX - rect.left) / rect.width) * 2 - 1;
            this.mouse.y = -((clientY - rect.top) / rect.height) * 2 + 1;
            
            this.raycaster.setFromCamera(this.mouse, this.camera);
            const intersects = this.raycaster.intersectObjects(this.nodes, true);
            if (intersects.length > 0) {
                intersects.sort((a, b) => a.distance - b.distance);
                for (const hit of intersects) {
                    let obj = hit.object;
                    while (obj && !this.nodes.includes(obj)) obj = obj.parent;
                    if (obj) return obj;
                }
            }
            return null;
        };

        const showTooltipForNode = (node, x, y) => {
            if (this.tooltip && node?.userData?.name) {
                if (this.tooltipHideTimeout) clearTimeout(this.tooltipHideTimeout);
                
                let html = `<div style="font-weight:600; margin-bottom: 2px;">${node.userData.name}</div>`;
                if (node.userData.keywords?.length > 0) {
                    html += `<div style="opacity: 0.8; font-size: 0.75rem; display: flex; flex-wrap: wrap; gap: 4px;">`;
                    node.userData.keywords.forEach(kw => {
                        html += `<span style="background: rgba(255,255,255,0.15); padding: 1px 4px; border-radius: 2px;">#${kw}</span>`;
                    });
                    html += `</div>`;
                }
                this.tooltip.innerHTML = html;

                const styles = this.getNodeTooltipStyles(node);
                Object.assign(this.tooltip.style, {
                    background: styles.background,
                    color: styles.color,
                    visibility: 'visible',
                    display: 'block',
                    opacity: '0'
                });

                const rect = this.renderer.domElement.getBoundingClientRect();
                const projected = new THREE.Vector3();
                node.getWorldPosition(projected);
                const dist = projected.distanceTo(this.camera.position);
                projected.project(this.camera);
                
                const tooltipYOffset = 34 + Math.max(0, (18 - dist) * 1.5);
                const screenX = (projected.x * 0.5 + 0.5) * rect.width;
                const screenY = (0.5 - projected.y * 0.5) * rect.height + tooltipYOffset;
                
                Object.assign(this.tooltip.style, {
                    left: screenX + 'px',
                    top: screenY + 'px',
                    transform: 'translate(-50%, -50%) translate(-12px, 0)'
                });
                requestAnimationFrame(() => requestAnimationFrame(() => { this.tooltip.style.opacity = '1'; }));
            }
        };
    }

    createNodes(nodeData) {
        this.nodes = [];
        this.clearAll();
        
        const cameraZ = this.camera.position.z;
        const halfHeight = Math.tan(THREE.MathUtils.degToRad(this.camera.fov * 0.5)) * cameraZ;
        const halfWidth = halfHeight * this.camera.aspect;
        const b = { x: halfWidth * 0.9, y: halfHeight * 0.9, z: cameraZ * 0.5 };

        nodeData.forEach((data, i) => {
            const pos = new THREE.Vector3((Math.random() * 2 - 1) * b.x, (Math.random() * 2 - 1) * b.y, (Math.random() * 2 - 1) * b.z);
            const hue = (i + 0.5) / nodeData.length;
            const color = new THREE.Color().setHSL(hue, 0.7, 0.75);

            const material = new THREE.MeshStandardMaterial({
                color, emissive: color, emissiveIntensity: 0.5,
                metalness: 0.3, roughness: 0.7, transparent: true, opacity: 0.94
            });

            const node = createNodeIcon(material, i, this.geometryManager);
            node.position.copy(pos);
            
            // Random celestial event: 10% chance of a satellite moon
            if (Math.random() < 0.1) {
                const moonGeo = this.geometryManager.getSphere(0.05, 8);
                const moonMat = new THREE.MeshBasicMaterial({ color: 0xaaaaaa });
                const moon = new THREE.Mesh(moonGeo, moonMat);
                
                // Position moon at a distance
                const orbitRadius = 0.6 + Math.random() * 0.4;
                moon.position.set(orbitRadius, 0, 0);
                
                const moonGroup = new THREE.Group();
                moonGroup.add(moon);
                moonGroup.userData = { isMoon: true, speed: 0.5 + Math.random() * 1.5 };
                node.add(moonGroup);
            }

            node.userData = {
                name: data.name,
                description: data.description,
                keywords: data.keywords || [],
                url: data.url,
                originalPosition: pos.clone(),
                speed: data.animation.speed / 4,
                baseSpeed: data.animation.speed / 4,
                phase: data.animation.phase,
                animationState: 'normal',
                stateTimer: Math.random() * 3000 + 2000,
                stateChangeTime: Date.now(),
                velocity: new THREE.Vector3(),
                colorR: Math.round(color.r * 255),
                colorG: Math.round(color.g * 255),
                colorB: Math.round(color.b * 255)
            };
            this.nodes.push(node);
        });
    }

    getSharedKeywordsCount(n1, n2) {
        return (n1.userData.keywords || []).filter(k => (n2.userData.keywords || []).includes(k)).length;
    }

    createConnections() {
        this.connections = [];
        if (this.nodes.length < 2) return;

        let maxShared = 0;
        for (let i = 0; i < this.nodes.length; i++) {
            for (let j = i + 1; j < this.nodes.length; j++) {
                maxShared = Math.max(maxShared, this.getSharedKeywordsCount(this.nodes[i], this.nodes[j]));
            }
        }

        const bands = [0.002, 0.005, 0.009, 0.014];
        const opacities = [0.14, 0.28, 0.48, 0.58];
        const geometry = this.geometryManager.getOrCreate('connection_cylinder', () => new THREE.CylinderGeometry(1, 1, 1, 8));

        // Track connection counts to find the centerpiece
        const nodeConnectionCounts = new Map();

        for (let i = 0; i < this.nodes.length; i++) {
            for (let j = i + 1; j < this.nodes.length; j++) {
                const n1 = this.nodes[i], n2 = this.nodes[j];
                const shared = this.getSharedKeywordsCount(n1, n2);
                if (shared > 0) {
                    nodeConnectionCounts.set(n1, (nodeConnectionCounts.get(n1) || 0) + 1);
                    nodeConnectionCounts.set(n2, (nodeConnectionCounts.get(n2) || 0) + 1);

                    const pct = shared / maxShared;
                    const bIdx = Math.min(Math.floor(pct * 4), 3);
                    const thickness = bands[bIdx];
                    const opacity = opacities[bIdx];
                    
                    const hue = (this.connections.length * 0.618) % 1;
                    const color = new THREE.Color().setHSL(hue, 0.7, 0.68);
                    const material = new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0, side: THREE.DoubleSide });
                    
                    const mesh = new THREE.Mesh(geometry, material);
                    this.connections.push({
                        mesh, node1: n1, node2: n2, sharedCount: shared,
                        thickness, baseOpacity: Math.min(opacity * 1.5, 1.0),
                        currentOpacity: 0, targetOpacity: 0
                    });
                }
            }
        }

        // Add Space Station Ring to the most connected node
        if (nodeConnectionCounts.size > 0) {
            let maxCount = -1;
            let centerpiece = null;
            for (const [node, count] of nodeConnectionCounts.entries()) {
                if (count > maxCount) {
                    maxCount = count;
                    centerpiece = node;
                }
            }

            if (centerpiece) {
                const ringGeo = new THREE.TorusGeometry(0.5, 0.01, 8, 32);
                const ringMat = new THREE.MeshBasicMaterial({ color: 0x00ffcc, wireframe: true, transparent: true, opacity: 0.6 });
                const ring = new THREE.Mesh(ringGeo, ringMat);
                ring.userData = { isStationRing: true };
                centerpiece.add(ring);
            }
        }
    }

    updateConnections(deltaTimeSec) {
        const anchorCache = new Map();
        const getAnchor = (n) => {
            if (!anchorCache.has(n)) anchorCache.set(n, this.getNodeAnchorPosition(n));
            return anchorCache.get(n);
        };

        for (const c of this.connections) {
            const p1 = getAnchor(c.node1), p2 = getAnchor(c.node2);
            const dir = new THREE.Vector3().subVectors(p2, p1);
            const dist = dir.length();
            if (dist < 0.001) { c.mesh.visible = false; continue; }

            c.mesh.position.copy(p1).add(dir.clone().multiplyScalar(0.5));
            c.mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir.clone().normalize());
            c.mesh.scale.set(c.thickness, dist, c.thickness);
            c.mesh.visible = true;
        }
        this.networkManager.updateVisibility(this.connections, deltaTimeSec);
    }

    fitCameraToNodes() {
        if (this.nodes.length === 0) return;
        const box = new THREE.Box3();
        this.nodes.forEach(n => box.expandByPoint(n.position));
        
        const center = new THREE.Vector3(), size = new THREE.Vector3();
        box.getCenter(center); box.getSize(size);
        
        const maxDim = Math.max(size.x, size.y, size.z);
        let cameraZ = Math.max(Math.abs(maxDim / 2 / Math.tan(this.camera.fov * Math.PI / 360)) * 1.1, 15);

        this.camera.position.set(center.x, center.y, center.z + cameraZ);
        this.camera.lookAt(center);
        if (this.controls) {
            this.controls.target.copy(center);
            this.controls.maxDistance = Math.max(100, cameraZ * 2);
            this.controls.update();
        }
    }

    warmupPhysics() {
        if (this.nodes.length < 2) return;
        for (let i = 0; i < 300; i++) this.applyForces(0.016, 1.0);
    }

    applyForces(dtSec, strength = 0.05) {
        if (this.nodes.length < 2) return;
        const dt = Math.min(dtSec, 0.032);
        const params = { rep: 2.0 * strength, att: 0.04 * strength, ideal: 6.0, damp: 0.85, maxD: 22, maxF: 0.6 * strength };
        const maxV = strength > 0.5 ? 0.25 : 0.02;
        const temp = new THREE.Vector3();

        for (let i = 0; i < this.nodes.length; i++) {
            for (let j = i + 1; j < this.nodes.length; j++) {
                const n1 = this.nodes[i], n2 = this.nodes[j];
                temp.subVectors(n1.userData.originalPosition, n2.userData.originalPosition);
                const d2 = temp.lengthSq();
                if (d2 < 0.0001 || d2 > 900) continue;
                const f = Math.min(params.rep / d2, params.maxF) * dt;
                temp.normalize().multiplyScalar(f);
                n1.userData.velocity.add(temp); n2.userData.velocity.sub(temp);
            }
        }

        this.connections.forEach(c => {
            temp.subVectors(c.node2.userData.originalPosition, c.node1.userData.originalPosition);
            const d = temp.length();
            if (d < 0.1 || d <= params.ideal) return;
            const f = Math.min((d - params.ideal) * params.att * (1 + c.sharedCount * 0.4), params.maxF) * dt;
            temp.normalize().multiplyScalar(f);
            c.node1.userData.velocity.add(temp); c.node2.userData.velocity.sub(temp);
        });

        this.nodes.forEach(n => {
            const d = n.userData;
            if (d.velocity.length() > maxV) d.velocity.normalize().multiplyScalar(maxV);
            d.originalPosition.add(d.velocity);
            d.velocity.multiplyScalar(params.damp);
            d.velocity.add(temp.copy(d.originalPosition).multiplyScalar(-0.002 * dt));
            if (d.originalPosition.length() > params.maxD) {
                d.originalPosition.normalize().multiplyScalar(params.maxD);
                d.velocity.multiplyScalar(0.5);
            }
        });
    }

    updateNodes() {
        const time = performance.now() * 0.001; // Current time in seconds
        const focused = this.networkManager.getFocusedNode();
        
        const tempPos = new THREE.Vector3();
        const dists = this.nodes.map(n => { n.getWorldPosition(tempPos); return tempPos.distanceTo(this.camera.position); });
        const minD = Math.min(...dists), maxD = Math.max(...dists), range = Math.max(0.001, maxD - minD);

        this.nodes.forEach((n, i) => {
            const d = n.userData;
            // Depth-based brightness
            const brightness = 1 - ((dists[i] - minD) / range) * 0.6;

            // Solar Flare Logic: 0.05% chance per frame (~once every 30s per constellation)
            // If active, it lasts for 15 frames
            if (!d.solarFlare && Math.random() < 0.0005) {
                d.solarFlare = 15;
            }

            n.traverse(obj => {
                if (obj.material) {
                    const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
                    mats.forEach(m => {
                        const isActive = (focused === n);
                        m.opacity = (isActive || this.persistentTooltipNodeToDiv.has(n)) ? 1 : 0.94;
                        
                        if (d.colorR !== undefined) {
                            m.color.setRGB((d.colorR / 255) * brightness, (d.colorG / 255) * brightness, (d.colorB / 255) * brightness);
                            m.emissive?.copy(m.color);
                            
                            if (m.emissiveIntensity !== undefined) {
                                if (m._baseEmissiveIntensity === undefined) m._baseEmissiveIntensity = m.emissiveIntensity;
                                
                                // Enhanced Twinkle: vary intensity between 0.5 and 1.5 of base
                                const twinkle = 1.0 + Math.sin(time * 2.5 + d.phase) * 0.5;
                                const hoverBoost = isActive ? 2.5 : 1.0;
                                
                                // Solar Flare multiplier: sudden burst
                                let flareBoost = 1.0;
                                if (d.solarFlare > 0) {
                                    flareBoost = 8.0 * (d.solarFlare / 15); // Fade out the flare
                                    d.solarFlare--;
                                }

                                m.emissiveIntensity = m._baseEmissiveIntensity * brightness * hoverBoost * twinkle * flareBoost;
                            }
                        }
                    });
                }
            });

            // Keep position perfectly stable
            n.position.copy(d.originalPosition);
            
            // Subtle pulse
            const s = 1 + Math.sin(time * 1.5 + d.phase) * 0.05;
            n.scale.set(s, s, s);

            // Animate satellites and station rings
            n.children.forEach(child => {
                if (child.userData?.isMoon) {
                    child.rotation.y = time * child.userData.speed;
                    child.rotation.z = time * (child.userData.speed * 0.3);
                } else if (child.userData?.isStationRing) {
                    child.rotation.x += 0.01;
                    child.rotation.y += 0.02;
                }
            });
        });
    }

    getFront20PercentWithTier() {
        const tempPos = new THREE.Vector3();
        const withDist = this.nodes.map(n => { n.getWorldPosition(tempPos); return { node: n, dist: tempPos.distanceTo(this.camera.position) }; });
        withDist.sort((a, b) => a.dist - b.dist);
        const c20 = Math.max(1, Math.floor(this.nodes.length * 0.2)), f10 = Math.max(1, Math.floor(this.nodes.length * 0.1));
        return withDist.slice(0, c20).map((e, i) => ({ node: e.node, inFront10: i < f10 }));
    }

    updatePersistentTooltips() {
        if (!this.persistentTooltipsContainer || this.nodes.length === 0) return;
        const focused = this.networkManager.getFocusedNode();
        const toShow = this.getFront20PercentWithTier().filter(e => e.node !== focused && e.node.userData?.name);
        const rect = this.renderer.domElement.getBoundingClientRect();
        const containerRect = this.persistentTooltipsContainer.parentElement.getBoundingClientRect();
        const projected = new THREE.Vector3();

        const startFade = (el, node) => {
            if (el.style.visibility !== 'visible' || el._fadeOutTimeout) return;
            this.persistentTooltipNodeToDiv.delete(node);
            el.style.opacity = '0';
            el._fadeOutTimeout = setTimeout(() => { el.style.visibility = 'hidden'; el._fadeOutTimeout = null; }, 780);
        };

        const toShowNodes = new Set(toShow.map(e => e.node));
        toShow.forEach(e => {
            const n = e.node, opacity = e.inFront10 ? '1' : '0.2';
            n.getWorldPosition(projected);
            const dist = projected.distanceTo(this.camera.position);
            projected.project(this.camera);
            if (projected.z > 1 || projected.z < -1) { const d = this.persistentTooltipNodeToDiv.get(n); if (d) startFade(d, n); return; }

            const yOff = 34 + Math.max(0, (18 - dist) * 1.5);
            const left = (projected.x * 0.5 + 0.5) * rect.width, top = (0.5 - projected.y * 0.5) * rect.height + yOff;
            
            let el = this.persistentTooltipNodeToDiv.get(n);
            if (!el) {
                el = Array.from(this.persistentTooltipsContainer.children).find(c => c.style.visibility === 'hidden' && !c._fadeOutTimeout) || document.createElement('div');
                if (!el.parentElement) {
                    el.className = 'persistent-tooltip-item absolute px-1 py-0.5 rounded text-xs pointer-events-none whitespace-nowrap';
                    this.persistentTooltipsContainer.appendChild(el);
                }
                this.persistentTooltipNodeToDiv.set(n, el);
                el.style.visibility = 'visible'; el.style.opacity = '0';
                requestAnimationFrame(() => requestAnimationFrame(() => { el.style.opacity = opacity; }));
            }
            el.textContent = n.userData.name;
            const s = this.getNodeTooltipStyles(n);
            Object.assign(el.style, { left: left + 'px', top: top + 'px', transform: 'translate(-50%, -50%) translate(-12px, 0)', background: s.background, color: s.color, opacity });
        });

        for (const [n, d] of Array.from(this.persistentTooltipNodeToDiv)) if (!toShowNodes.has(n)) startFade(d, n);
    }

    animate() {
        requestAnimationFrame(() => this.animate());
        const now = performance.now();
        const dt = this._lastFrameTime ? (now - this._lastFrameTime) / 1000 : 0.016;
        this._lastFrameTime = now;

        this.controls.autoRotate = (now - this.lastInteractionAt) > this.idleRotateDelayMs;
        this.applyForces(dt, 0.05);
        this.controls.update();
        this.updatePersistentTooltips();
        this.updateStarfield(now * 0.001);
        this.updateNodes();
        this.updateConnections(dt);
        this.updateComet(dt);
        this.updateRocket(dt);
        this.updateUFO(dt);

        if (this.composer) {
            this.renderer.autoClear = false;
            this.renderer.clear();
            this.composer.render();
        } else {
            this.renderer.render(this.scene, this.camera);
        }
    }

    onWindowResize() {
        this.camera.aspect = window.innerWidth / window.innerHeight;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(window.innerWidth, window.innerHeight);
        this.composer?.setSize(window.innerWidth, window.innerHeight);
    }
}

export { TelarisNetwork };
