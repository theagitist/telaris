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
        this.navigationStack = [window.TELARIS_CONSTELLATION_ID ?? 0];
        this.networkManager = new NetworkManager({ fadeSpeed: 0.1 });
        this.geometryManager = new GeometryManager();
        this.raycaster = new THREE.Raycaster();
        this.mouse = new THREE.Vector2();
        this.tooltip = document.getElementById('node-tooltip');
        this.persistentTooltipsContainer = document.getElementById('persistent-tooltips');
        this.mainTooltipNodeTimeout = null;
        this.tooltipHideTimeout = null;
        this.persistentTooltipNodeToDiv = new Map();
        this.tooltipPool = []; // Optimization: Pool of DOM elements for labels

        // Idle rotation
        this.lastInteractionAt = performance.now();
        this.idleRotateDelayMs = 4500;
        this._lastFrameTime = 0;

        // Optimization: Scratch objects to avoid GC pressure
        this._scratchVec = new THREE.Vector3();
        this._scratchVec2 = new THREE.Vector3();
        this._scratchQuat = new THREE.Quaternion();
        this._upVec = new THREE.Vector3(0, 1, 0);

        this.searchQuery = '';
        this.init();
        this.setupSearch();
    }

    getNodeAnchorPosition(node) {
        node.getWorldPosition(this._scratchVec);
        return this._scratchVec;
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
        let alertMsg = typeof window.TELARIS_ALERT_MESSAGE === 'string' ? window.TELARIS_ALERT_MESSAGE : "You are traversing to the Planar Dimension\nTo explore, zoom and scroll in all directions\nClose the browser window to return to the Cosmic Dimension.";
        alertMsg = alertMsg.replace(/\{APPNAME\}/g, app);
        const frameUrl = 'utils/frame.php?url=' + encodeURIComponent(url) + 
            '&r=' + r + '&g=' + g + '&b=' + b + 
            '&app=' + encodeURIComponent(app) + 
            '&alert_msg=' + encodeURIComponent(alertMsg) +
            '&node_name=' + encodeURIComponent(d.name || 'System') +
            '&description=' + encodeURIComponent(d.description || '');
        window.open(frameUrl, '_blank', 'noopener,noreferrer');
    }

    showRichMediaWindow(node) {
        const d = node && node.userData;
        if (!d) return;

        const overlay = document.getElementById('rich-media-overlay');
        const win = document.getElementById('rich-media-window');
        const titleEl = document.getElementById('rm-title');
        const descriptionEl = document.getElementById('rm-description');
        const imageWrap = document.getElementById('rm-image-wrap');
        const imageEl = document.getElementById('rm-image');
        const embedWrap = document.getElementById('rm-embed-wrap');
        const embedEl = document.getElementById('rm-embed');
        const audioWrap = document.getElementById('rm-audio-wrap');
        const audioEl = document.getElementById('rm-audio');
        const urlWrap = document.getElementById('rm-url-wrap');
        const urlButton = document.getElementById('rm-url-button');

        if (!overlay || !win) return;
        window.telarisNetwork = this; // Ensure globally accessible for close button

        // Title
        if (titleEl) titleEl.textContent = d.name || 'System';

        // Description
        if (descriptionEl) {
            descriptionEl.textContent = d.description || '';
            descriptionEl.classList.toggle('hidden', !d.description);
        }

        // Image
        if (imageWrap && imageEl) {
            if (d.image_url) {
                imageEl.src = d.image_url;
                imageWrap.classList.remove('hidden');
            } else {
                imageWrap.classList.add('hidden');
            }
        }

        // Embed
        if (embedWrap && embedEl) {
            if (d.embed_code) {
                embedEl.innerHTML = d.embed_code;
                embedWrap.classList.remove('hidden');
            } else {
                embedEl.innerHTML = '';
                embedWrap.classList.add('hidden');
            }
        }

        // Audio
        if (audioWrap && audioEl) {
            if (d.audio_url) {
                audioEl.src = d.audio_url;
                audioWrap.classList.remove('hidden');
                
                const playPauseBtn = document.getElementById('rm-audio-play-pause');
                const stopBtn = document.getElementById('rm-audio-stop');
                const playIcon = document.getElementById('rm-play-icon');
                const pauseIcon = document.getElementById('rm-pause-icon');
                const progressBar = document.getElementById('rm-audio-progress');
                const progressContainer = document.getElementById('rm-audio-progress-container');
                const timeDisplay = document.getElementById('rm-audio-time');

                const updateTime = () => {
                    if (!audioEl.duration) return;
                    const pct = (audioEl.currentTime / audioEl.duration) * 100;
                    progressBar.style.width = pct + '%';
                    const mins = Math.floor(audioEl.currentTime / 60);
                    const secs = Math.floor(audioEl.currentTime % 60);
                    timeDisplay.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                };

                const togglePlay = () => {
                    if (audioEl.paused) audioEl.play();
                    else audioEl.pause();
                };

                audioEl.onplay = () => {
                    playIcon.classList.add('hidden');
                    pauseIcon.classList.remove('hidden');
                };
                audioEl.onpause = () => {
                    playIcon.classList.remove('hidden');
                    pauseIcon.classList.add('hidden');
                };
                audioEl.onended = () => {
                    playIcon.classList.remove('hidden');
                    pauseIcon.classList.add('hidden');
                    progressBar.style.width = '0%';
                };
                audioEl.ontimeupdate = updateTime;

                playPauseBtn.onclick = togglePlay;
                stopBtn.onclick = () => {
                    audioEl.pause();
                    audioEl.currentTime = 0;
                };

                progressContainer.onclick = (e) => {
                    const rect = progressContainer.getBoundingClientRect();
                    const pos = (e.clientX - rect.left) / rect.width;
                    audioEl.currentTime = pos * audioEl.duration;
                };

                if (d.audio_autoplay) {
                    audioEl.play().catch(err => {
                    });
                }
            } else {
                audioEl.src = '';
                audioWrap.classList.add('hidden');
            }
        }

        // URL / Action Button
        if (urlWrap && urlButton) {
            if (d.url) {
                urlWrap.classList.remove('hidden');
                urlButton.textContent = `LAUNCH ${d.name || 'SYSTEM'}`;
                urlButton.onclick = () => {
                    this.closeRichMediaWindow();
                    this.openInFrame(node, d.url);
                };
            } else {
                urlWrap.classList.add('hidden');
            }
        }

        // Calculate Spatial Origin
        const rect = this.renderer.domElement.getBoundingClientRect();
        const projected = new THREE.Vector3();
        node.getWorldPosition(projected);
        
        // 3D Tilt calculation (subtle perspective based on where the node is)
        const tiltX = projected.y * 5; // Tilt up/down
        const tiltY = -projected.x * 5; // Tilt left/right
        
        projected.project(this.camera);
        const startX = (projected.x * 0.5 + 0.5) * rect.width;
        const startY = (0.5 - projected.y * 0.5) * rect.height;

        // Apply Color Glow to Window
        const r = d.colorR || 0, g = d.colorG || 255, b = d.colorB || 204;
        win.style.setProperty('--node-accent', `rgb(${r}, ${g}, ${b})`);
        win.style.setProperty('--node-accent-muted', `rgba(${r}, ${g}, ${b}, 0.3)`);
        win.style.boxShadow = `0 0 80px -20px rgba(${r}, ${g}, ${b}, 0.5), inset 0 0 20px rgba(${r}, ${g}, ${b}, 0.1)`;
        win.style.borderColor = `rgba(${r}, ${g}, ${b}, 0.3)`;

        // Reset and animate
        overlay.classList.remove('hidden');
        win.style.transition = 'none';
        win.style.transform = `translate(${startX - rect.width/2}px, ${startY - rect.height/2}px) scale(0) rotateX(${tiltX}deg) rotateY(${tiltY}deg)`;
        win.style.opacity = '0';
        overlay.style.opacity = '0';
        
        // Force reflow
        win.offsetHeight;
        
        requestAnimationFrame(() => {
            overlay.style.opacity = '1';
            win.style.transition = 'all 0.6s cubic-bezier(0.16, 1, 0.3, 1)'; // Smooth spatial travel
            win.style.transform = 'translate(0, 0) scale(1) rotateX(0deg) rotateY(0deg)';
            win.style.opacity = '1';
        });
    }

    closeRichMediaWindow() {
        const overlay = document.getElementById('rich-media-overlay');
        const win = document.getElementById('rich-media-window');
        if (!overlay || !win) return;

        overlay.style.opacity = '0';
        win.style.transform = 'scale(0.9) translateY(20px)';
        win.style.opacity = '0';

        setTimeout(() => {
            const audio = document.getElementById('rm-audio');
            if(audio) {
                audio.pause();
                audio.onplay = null;
                audio.onpause = null;
                audio.onended = null;
                audio.ontimeupdate = null;
                audio.src = '';
            }
            const embed = document.getElementById('rm-embed');
            if(embed) { embed.innerHTML = ''; }
            overlay.classList.add('hidden');
        }, 500);
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
        this.stars.renderOrder = -20;
        this.scene.add(this.stars);
    }

            initNebulas() {
                const nebulaTex = this.geometryManager.getOrCreate('nebula_tex', () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = 128; canvas.height = 128;
                    const ctx = canvas.getContext('2d');
                    const grad = ctx.createRadialGradient(64, 64, 0, 64, 64, 64);
                    grad.addColorStop(0, 'rgba(255, 255, 255, 1)');
                    grad.addColorStop(0.5, 'rgba(255, 255, 255, 0.3)');
                    grad.addColorStop(1, 'rgba(0, 0, 0, 0)');
                    ctx.fillStyle = grad;
                    ctx.fillRect(0, 0, 128, 128);
                    return new THREE.CanvasTexture(canvas);
                });
        
                        this.bgNebulas = new THREE.Group();
                        const colors = [0x4466aa, 0x6644aa, 0x774477]; 
                
                        for (let i = 0; i < 8; i++) { // Increased to 8 for spherical coverage
                            const mat = new THREE.SpriteMaterial({
                                map: nebulaTex,
                                color: new THREE.Color(colors[i % colors.length]),
                                transparent: true,
                                opacity: 0.028, 
                                blending: THREE.AdditiveBlending,
                                depthWrite: false
                            });
                            const sprite = new THREE.Sprite(mat);
                            sprite.renderOrder = -10;
                
                            // Spherical distribution at a large distance
                            const r = 80 + Math.random() * 20;
                            const theta = Math.random() * Math.PI * 2;
                            const phi = Math.acos(2 * Math.random() - 1);
                            
                            sprite.position.set(
                                r * Math.sin(phi) * Math.cos(theta),
                                r * Math.sin(phi) * Math.sin(theta),
                                r * Math.cos(phi)
                            );
                
                            sprite.scale.set(70 + Math.random() * 30, 70 + Math.random() * 30, 1);
                            sprite.userData = { rotationSpeed: 0.005 + Math.random() * 0.01 };
                            this.bgNebulas.add(sprite);
                        }
                        this.scene.add(this.bgNebulas);
                    }                
                    updateNebulas(time) {
                        if (!this.bgNebulas) return;
                        this.bgNebulas.children.forEach((sprite, i) => {
                            sprite.material.rotation = time * sprite.userData.rotationSpeed * (i % 2 === 0 ? 1 : -1);
                            sprite.material.opacity = 0.028 + Math.sin(time * 0.2 + i) * 0.008;
                        });
                    }    updateStarfield(time) {
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
        this.initNebulas();
        if (canvasWrapper) {
            canvasWrapper.appendChild(canvasElement);
        } else {
            canvasContainer.appendChild(canvasElement);
        }
        canvasContainer.appendChild(this.tooltip);

        this.camera.position.set(0, 0, 13);

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
        this.controls.minDistance = 3;
        this.controls.maxDistance = 22;
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
        this.setupBackButton();
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
        const bodyGeo = new THREE.CylinderGeometry(0.025, 0.025, 0.12, 6);
        const bodyMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
        const body = new THREE.Mesh(bodyGeo, bodyMat);
        body.rotation.x = Math.PI / 2; // Orient along Z
        this.rocket.add(body);

        // Tip: VERY red and slightly larger cone
        const tipGeo = new THREE.ConeGeometry(0.03, 0.06, 6);
        // Using MeshStandardMaterial with emissive to pierce through bloom
        const tipMat = new THREE.MeshStandardMaterial({ 
            color: 0xff0000,
            emissive: 0xff0000,
            emissiveIntensity: 2.0
        });
        const tip = new THREE.Mesh(tipGeo, tipMat);
        tip.position.z = 0.09; // Position at the front
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
                this.syncNodePositionsFromPhysics();
                this.fitCameraToNodes();
                this.nodes.forEach(n => this.scene.add(n));
                if (this.connections.length > 0) {
                    this.connections.forEach(c => this.scene.add(c.mesh));
                }
            } else {
                this.clearAll();
            }
        } catch (error) {
            console.error('Error loading data:', error);
            this.clearAll();
        } finally {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) overlay.style.display = 'none';
            this.updateBackButtonVisibility();
            this.updateConstellationUI(window.TELARIS_CONSTELLATION_ID ?? 0);
        }
    }

    setupBackButton() {
        const btn = document.getElementById('portal-back-button');
        if (!btn) return;
        btn.addEventListener('click', () => {
            if (this.navigationStack.length <= 1) return;
            this.navigationStack.pop(); // Remove current ID
            const previousId = this.navigationStack[this.navigationStack.length - 1]; // Peek at the target ID
            this.updateBackButtonVisibility();
            this.runBackTransition(previousId);
        });
    }

    updateBackButtonVisibility() {
        const btn = document.getElementById('portal-back-button');
        if (btn) btn.style.display = this.navigationStack.length > 1 ? 'block' : 'none';
    }

    async updateConstellationUI(constellationId) {
        const titleEl = document.getElementById('constellation-title');
        const taglineEl = document.getElementById('constellation-tagline');
        if (!titleEl && !taglineEl) return;
        try {
            const response = await apiFetch('api/constellations.php');
            if (!response.ok) return;
            const list = await response.json();
            const c = Array.isArray(list) ? list.find(x => x.id === constellationId) : null;
            if (c) {
                document.title = c.name || document.title;
                if (titleEl) titleEl.textContent = c.name || '';
                if (taglineEl) taglineEl.textContent = c.tagline || '';
            }
        } catch (err) {
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
        this.networkManager.setFocusedNode(null);
        if (this.tooltip) this.hideMainTooltip();
    }

    /** Get or create a full-screen overlay used for portal transition fade. */
    getOrCreateTransitionOverlay() {
        let el = document.getElementById('portal-transition-overlay');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'portal-transition-overlay';
        el.setAttribute('aria-hidden', 'true');
        Object.assign(el.style, {
            position: 'absolute',
            inset: '0',
            backgroundColor: '#000',
            opacity: '0',
            pointerEvents: 'none',
            transition: 'opacity 0.25s ease',
            zIndex: '50'
        });
        const container = document.getElementById('canvas-container');
        if (container) container.appendChild(el);
        return el;
    }

    /**
     * Start 500ms "rev" feedback on the clicked portal, then run the constellation transition.
     */
    startPortalRev(clickedNode, targetId) {
        this._revvingPortal = { node: clickedNode, targetId, startTime: performance.now() };
    }

    /**
     * Run portal transition: move camera toward portal over 1s while fading network to 0,
     * then load new constellation, reset camera, and fade new network in.
     */
    runPortalTransition(clickedNode, targetId) {
        const portalPos = new THREE.Vector3();
        clickedNode.getWorldPosition(portalPos);
        
        // Pre-fetch data immediately
        const dataPromise = apiFetch(`api/nodes.php?constellation_id=${targetId}`)
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP error! status: ${r.status}`)))
            .then(json => Array.isArray(json) ? json : []);

        this._portalTransition = {
            phase: 'camera_fade_out',
            startTime: performance.now(),
            duration: 800,
            cameraEnd: portalPos.clone(),
            targetEnd: portalPos.clone(),
            cameraStart: this.camera.position.clone(),
            targetStart: this.controls.target.clone(),
            targetId,
            dataPromise,
            targetFadeInDuration: 1000 // Slower fade in for portals
        };
        this.controls.enabled = false;
    }

    /**
     * Run transition BACK: current constellation fades out while target constellation fades in.
     */
    runBackTransition(targetId) {
        // Capture current state as "outgoing"
        this._outgoingNodes = [...this.nodes];
        this._outgoingConnections = [...this.connections];
        
        // Reset main arrays so new nodes don't mix with outgoing
        this.nodes = [];
        this.connections = [];

        // Pre-fetch data immediately
        const dataPromise = apiFetch(`api/nodes.php?constellation_id=${targetId}`)
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP error! status: ${r.status}`)))
            .then(json => Array.isArray(json) ? json : []);

        this._portalTransition = {
            phase: 'back_cross_fade',
            startTime: performance.now(),
            duration: 800,
            targetId,
            dataPromise
        };
        this.controls.enabled = false;

        dataPromise.then(nodeData => {
            if (this._portalTransition && this._portalTransition.phase === 'back_cross_fade') {
                // Load new data WITHOUT clearing
                this.loadDataForConstellation(targetId, nodeData, true, true).then(() => {
                    // Trigger a camera fit NOW while everything is invisible so it is ready for fade in
                    this.fitCameraToNodes();
                });
            }
        });
    }

    /**
     * Fade out current network, fetch and render nodes for targetConstellationId, then fade in.
     */
    async transitionToConstellation(targetConstellationId) {
        const overlay = this.getOrCreateTransitionOverlay();
        overlay.style.display = 'block';
        overlay.style.pointerEvents = 'auto';
        const fadeOut = () => new Promise(resolve => {
            overlay.style.opacity = '1';
            setTimeout(resolve, 280);
        });
        await fadeOut();
        try {
            await this.loadDataForConstellation(targetConstellationId);
        } catch (err) {
            console.error('Portal transition failed:', err);
        }
        overlay.style.opacity = '0';
        overlay.style.pointerEvents = 'none';
        setTimeout(() => { overlay.style.display = 'none'; }, 280);
    }

    /**
     * Switch to another constellation: update URL, clear scene, fetch and render new data.
     */
    switchConstellation(id) {
        window.history.pushState({}, '', '?constellation_id=' + id);
        this.navigationStack.push(id);
        this.updateBackButtonVisibility();
        this.clearAll();
        this.loadDataForConstellation(id);
    }

    /**
     * Fetch nodes for a constellation and replace the current network (no fade).
     */
    async loadDataForConstellation(constellationId, nodeData = null, skipFit = false, skipClear = false, skipPhysics = false) {
        if (!window.TELARIS_API_KEY) throw new Error('API key not available');
        
        if (!nodeData) {
            const response = await apiFetch(`api/nodes.php?constellation_id=${constellationId}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const nodesJson = await response.json();
            nodeData = Array.isArray(nodesJson) ? nodesJson : [];
        }
        
        if (!skipClear) {
            this.clearAll();
        }
        if (nodeData.length > 0) {
            this.createNodes(nodeData);
            this.createConnections();
            
            // Warm up physics IMMEDIATELY so they are in final positions when they first appear
            this.warmupPhysics();
            this.syncNodePositionsFromPhysics();
            
            if (!skipFit) {
                this.fitCameraToNodes();
            }
            
            this.nodes.forEach(n => this.scene.add(n));
            if (this.connections.length > 0) {
                this.connections.forEach(c => this.scene.add(c.mesh));
            }
        }
        window.TELARIS_CONSTELLATION_ID = constellationId;
        this.updateBackButtonVisibility();
        this.updateConstellationUI(constellationId);
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
            const intersects = this.raycaster.intersectObjects(this.nodes.filter(n => n.visible), true);
            
            if (intersects.length > 0) {
                intersects.sort((a, b) => a.distance - b.distance);
                // console.log('[Telaris] Intersects:', intersects.length, intersects[0].object.name);
            }

            let hoveredNode = null;
            if (intersects.length > 0) {
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
                
                const isPortal = hoveredNode.userData.node_type === 'portal' && hoveredNode.userData.target_constellation_id != null;
                const isObjectWithLink = hoveredNode.userData.node_type === 'object' && hoveredNode.userData.url;
                
                this.renderer.domElement.style.cursor = (isPortal || isObjectWithLink) ? 'pointer' : 'default';

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
            
            const rect = this.renderer.domElement.getBoundingClientRect();
            this.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
            this.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
            this.raycaster.setFromCamera(this.mouse, this.camera);
            const intersects = this.raycaster.intersectObjects(this.nodes.filter(n => n.visible), true);

            if (intersects.length > 0) {
                intersects.sort((a, b) => a.distance - b.distance);
                
                let targetNode = null;
                for (const hit of intersects) {
                    let obj = hit.object;
                    while (obj && !this.nodes.includes(obj)) {
                        obj = obj.parent;
                    }
                    if (obj) { targetNode = obj; break; }
                }
                
                if (!targetNode || !targetNode.userData) return;
                
                const data = targetNode.userData;

                if (data.node_type === 'portal') {
                    if (data.target_constellation_id != null) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (window.telarisApp) {
                            window.telarisApp.startPortalRev(targetNode, data.target_constellation_id);
                        } else {
                            window.location.href = `index.php?constellation_id=${data.target_constellation_id}`;
                        }
                    }
                } else if (data.node_type === 'object') {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    const hasMedia = !!(data.image_url || data.embed_code || data.audio_url);
                    const hasDesc = !!(data.description && data.description.trim() !== '');
                    
                    if (hasMedia) {
                        this.showRichMediaWindow(targetNode);
                    } else if (data.url) {
                        this.openInFrame(targetNode, data.url);
                    } else if (hasDesc) {
                        // If ONLY description (no media, no URL), still use rich-media window
                        this.showRichMediaWindow(targetNode);
                    }
                }
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
                    const nodeData = touchStartNode.userData;
                    const currentlyFocused = this.networkManager.getFocusedNode();

                    if (currentlyFocused === touchStartNode) {
                        // SECOND TAP: Open/Trigger action
                        if (nodeData.node_type === 'portal' && nodeData.target_constellation_id != null) {
                            e.preventDefault();
                            this.startPortalRev(touchStartNode, nodeData.target_constellation_id);
                            this.networkManager.setFocusedNode(null);
                        } else if (nodeData.node_type === 'object') {
                            const hasMedia = !!(nodeData.image_url || nodeData.embed_code || nodeData.audio_url);
                            const hasDesc = !!(nodeData.description && nodeData.description.trim() !== '');

                            if (hasMedia) {
                                e.preventDefault();
                                this.showRichMediaWindow(touchStartNode);
                                this.networkManager.setFocusedNode(null);
                            } else if (nodeData.url) {
                                e.preventDefault();
                                this.openInFrame(touchStartNode, nodeData.url);
                                this.networkManager.setFocusedNode(null);
                            } else if (hasDesc) {
                                e.preventDefault();
                                this.showRichMediaWindow(touchStartNode);
                                this.networkManager.setFocusedNode(null);
                            } else {
                                // If it has nothing to open, just keep it focused or refresh tooltip
                                if (this.mainTooltipNodeTimeout) clearTimeout(this.mainTooltipNodeTimeout);
                                this.networkManager.setFocusedNode(touchStartNode);
                                showTooltipForNode(touchStartNode, touchStartPos.screenX, touchStartPos.screenY);
                            }
                        } else {
                            if (this.mainTooltipNodeTimeout) clearTimeout(this.mainTooltipNodeTimeout);
                            this.networkManager.setFocusedNode(touchStartNode);
                            showTooltipForNode(touchStartNode, touchStartPos.screenX, touchStartPos.screenY);
                        }
                    } else {
                        // FIRST TAP: Focus and show lines/tooltip
                        e.preventDefault();
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
            const intersects = this.raycaster.intersectObjects(this.nodes.filter(n => n.visible), true);
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
                    html += `<div style="opacity: 0.8; font-size: 0.75rem; display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px;">`;
                    node.userData.keywords.forEach(kw => {
                        html += `<span style="background: rgba(255,255,255,0.15); padding: 1px 4px; border-radius: 2px;">#${kw}</span>`;
                    });
                    html += `</div>`;
                }

                // Interaction hint
                const hasMedia = !!(node.userData.image_url || node.userData.embed_code || node.userData.audio_url);
                const hasDesc = !!(node.userData.description && node.userData.description.trim() !== '');
                const isPortal = node.userData.node_type === 'portal';
                
                if (node.userData.url || hasMedia || hasDesc || isPortal) {
                    const hintText = ('ontouchstart' in window || navigator.maxTouchPoints > 0) 
                        ? (window.TELARIS_TAP_TO_VIEW || 'Tap again to view')
                        : (window.TELARIS_CLICK_TO_VIEW || 'Click to view');
                    html += `<div style="opacity: 0.6; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; border-top: 1px solid rgba(255,255,255,0.1); pt-1 mt-1;">${hintText}</div>`;
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
            try {
                // 1. Define the node data object from the API response first
                const pos = new THREE.Vector3((Math.random() * 2 - 1) * b.x, (Math.random() * 2 - 1) * b.y, (Math.random() * 2 - 1) * b.z);
                const hue = (i + 0.5) / nodeData.length;
                let color = new THREE.Color().setHSL(hue, 0.7, 0.75);
                if (!!data.is_accentuated) {
                    // Pastel Red: Hue 0, Saturation 0.7, Lightness 0.7
                    color = new THREE.Color().setHSL(0, 0.7, 0.7);
                }

                const node = {
                    name: data.name,
                    description: data.description,
                    keywords: data.keywords || [],
                    url: data.url,
                    image_url: data.image_url,
                    embed_code: data.embed_code,
                    audio_url: data.audio_url,
                    audio_autoplay: !!data.audio_autoplay,
                    node_type: data.node_type ?? 'object',
                    target_constellation_id: (data.target_constellation_id !== undefined && data.target_constellation_id !== null && data.target_constellation_id !== '') ? Number(data.target_constellation_id) : null,
                    is_accentuated: !!data.is_accentuated,
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
                    colorB: Math.round(color.b * 255),
                    cachedMaterials: [],
                    cachedMoons: []
                };

                // 2. Create the icon/mesh using that data
                const material = new THREE.MeshStandardMaterial({
                    color, emissive: color, emissiveIntensity: node.is_accentuated ? 1.5 : 0.5,
                    metalness: 0.3, roughness: 0.7, transparent: true, opacity: 0
                });
                const mesh = createNodeIcon(material, i, this.geometryManager, node.node_type);
                mesh.visible = false;
                mesh.position.copy(pos);

                // Accentuation: increase base size
                if (node.is_accentuated) {
                    mesh.scale.setScalar(2.0);
                    mesh.isAccentuated = true;
                }

                // Assign full node data to mesh so raycaster and logic can read node_type, etc.
                mesh.userData = node;
                
                // Portal mesh tagging: so the animation loop can apply rotation/pulse
                if (node.node_type === 'portal') {
                    mesh.isPortal = true;
                    mesh.userData.baseScale = mesh.scale.x;
                }

                // Random celestial event: 10% chance of a satellite moon
                if (Math.random() < 0.1) {
                    const moonGeo = this.geometryManager.getSphere(0.05, 8);
                    const moonMat = new THREE.MeshBasicMaterial({ color: 0xaaaaaa });
                    const moon = new THREE.Mesh(moonGeo, moonMat);
                    
                    const orbitRadius = 0.6 + Math.random() * 0.4;
                    moon.position.set(orbitRadius, 0, 0);
                    
                    const moonGroup = new THREE.Group();
                    moonGroup.add(moon);
                    moonGroup.userData = { isMoon: true, speed: 0.5 + Math.random() * 1.5 };
                    moonGroup.raycast = () => {};
                    mesh.add(moonGroup);
                }

                mesh.traverse(c => {
                    // Skip portal hitbox material so it doesn't get rendered/faded as a sphere
                    if (c.material && c.name !== 'portal_hitbox') {
                        const mats = Array.isArray(c.material) ? c.material : [c.material];
                        mesh.userData.cachedMaterials.push(...mats);
                    }
                    if (c.userData?.isMoon) {
                        mesh.userData.cachedMoons.push(c);
                    }
                });

                this.nodes.push(mesh);
            } catch (err) {
                console.error('[Telaris] Failed to create node:', data?.name ?? data?.id ?? i, err);
            }
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

        const bands = [0.004, 0.009, 0.016, 0.024];
        const opacities = [0.14, 0.28, 0.48, 0.58];
        const geometry = this.geometryManager.getOrCreate('connection_cylinder', () => new THREE.CylinderGeometry(0.5, 0.5, 1, 8));

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
                    const material = new THREE.MeshBasicMaterial({ 
                        color, 
                        transparent: true, 
                        opacity: 0, 
                        side: THREE.DoubleSide,
                        depthWrite: false,
                        depthTest: false // Allow lines to be seen through the nodes to the very center
                    });
                    
                    const mesh = new THREE.Mesh(geometry, material);
                    mesh.visible = false; // Start invisible
                    mesh.renderOrder = 10; // Render after nebulas and nodes
                    this.connections.push({
                        mesh, node1: n1, node2: n2, sharedCount: shared,
                        thickness, baseOpacity: Math.min(opacity * 1.5, 1.0),
                        currentOpacity: 0, targetOpacity: 0
                    });
                }
            }
        }

        // Add Space Station Ring and Cluster Nebulas
        if (nodeConnectionCounts.size > 0) {
            let maxCount = -1;
            let centerpiece = null;
            
            // Shared nebula texture
            const nebulaTex = this.geometryManager.getOrCreate('nebula_tex', () => {
                const canvas = document.createElement('canvas');
                canvas.width = 128; canvas.height = 128;
                const ctx = canvas.getContext('2d');
                const grad = ctx.createRadialGradient(64, 64, 0, 64, 64, 64);
                grad.addColorStop(0, 'rgba(255, 255, 255, 1)');
                grad.addColorStop(0.5, 'rgba(255, 255, 255, 0.3)');
                grad.addColorStop(1, 'rgba(0, 0, 0, 0)');
                ctx.fillStyle = grad;
                ctx.fillRect(0, 0, 128, 128);
                return new THREE.CanvasTexture(canvas);
            });

            for (const [node, count] of nodeConnectionCounts.entries()) {
                if (count > maxCount) {
                    maxCount = count;
                    centerpiece = node;
                }

                // Add a small cluster nebula to "hub" nodes (actual clusters with 5+ connections)
                if (count >= 5) {
                    const cosmicPalette = [
                        0x4455aa, // Muted Royal Blue
                        0x6644aa, // Muted Indigo
                        0x884488, // Muted Magenta/Plum
                        0x4477aa, // Muted Cyan-Blue
                        0x553377  // Muted Deep Purple
                    ];
                    const randomColor = cosmicPalette[Math.floor(Math.random() * cosmicPalette.length)];

                    const mat = new THREE.SpriteMaterial({
                        map: nebulaTex,
                        color: new THREE.Color(randomColor),
                        transparent: true,
                        opacity: 0.025, // Increased visibility
                        blending: THREE.AdditiveBlending,
                        depthWrite: false
                    });
                    const sprite = new THREE.Sprite(mat);
                    sprite.renderOrder = -5;
                    // Scale nebula much tighter
                    const s = 2.0 + (count * 0.2);
                    sprite.scale.set(s, s, 1);
                    sprite.userData = { isClusterNebula: true, baseOpacity: 0.025 };
                    sprite.raycast = () => {}; // Make nebula non-clickable
                    node.add(sprite);
                }
            }

            if (centerpiece) {
                const ringGeo = new THREE.TorusGeometry(0.5, 0.01, 8, 32);
                const ringMat = new THREE.MeshBasicMaterial({ 
                    color: 0x00ffcc, 
                    wireframe: true, 
                    transparent: true, 
                    opacity: 0.6,
                    depthWrite: false
                });
                const ring = new THREE.Mesh(ringGeo, ringMat);
                ring.renderOrder = 5;
                ring.userData = { isStationRing: true };
                                        ring.raycast = () => {}; // Make ring non-clickable
                                        centerpiece.add(ring);
                                    }        }
    }

    updateConnections(deltaTimeSec) {
        const anchorCache = new Map();
        const getAnchor = (n) => {
            if (!anchorCache.has(n)) {
                const pos = new THREE.Vector3();
                n.getWorldPosition(pos);
                anchorCache.set(n, pos);
            }
            return anchorCache.get(n);
        };

        for (const c of this.connections) {
            if (!c.node1.visible || !c.node2.visible) {
                c.mesh.visible = false;
                continue;
            }

            const p1 = getAnchor(c.node1), p2 = getAnchor(c.node2);
            
            // Vector from p1 to p2
            this._scratchVec.subVectors(p2, p1);
            const dist = this._scratchVec.length();
            if (dist < 0.001) { c.mesh.visible = false; continue; }

            // Set position to EXACT midpoint
            c.mesh.position.copy(p1).addScaledVector(this._scratchVec, 0.5);
            
            // Align orientation: point cylinder UP (Y) along the connection vector
            this._scratchVec.normalize();
            this._scratchQuat.setFromUnitVectors(this._upVec, this._scratchVec);
            c.mesh.quaternion.copy(this._scratchQuat);
            
            // Scale: Y is height (distance), X/Z is thickness
            // Using a higher multiplier since radius is now 0.5 (diameter 1)
            const t = c.thickness * 2.0; 
            c.mesh.scale.set(t, dist, t);
        }
        this.networkManager.updateVisibility(this.connections, deltaTimeSec, this._portalFadeInMultiplier);
    }

    /** Sync node.position from userData.originalPosition so camera fit uses post-physics positions. */
    syncNodePositionsFromPhysics() {
        this.nodes.forEach(n => {
            if (n.userData.originalPosition) n.position.copy(n.userData.originalPosition);
        });
    }

    fitCameraToNodes() {
        if (this.nodes.length === 0) return;
        const box = new THREE.Box3();
        this.nodes.forEach(n => box.expandByPoint(n.position));
        
        const center = new THREE.Vector3(), size = new THREE.Vector3();
        box.getCenter(center); box.getSize(size);
        
        const maxDim = Math.max(size.x, size.y, size.z);
        let cameraZ = Math.max(Math.abs(maxDim / 2 / Math.tan(this.camera.fov * Math.PI / 360)) * 1.1, 13);

        this.camera.position.set(center.x, center.y, center.z + cameraZ);
        this.camera.lookAt(center);
        if (this.controls) {
            this.controls.target.copy(center);
            this.controls.maxDistance = Math.max(45, cameraZ * 1.8);
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
        const params = { rep: 2.5 * strength, att: 0.04 * strength, ideal: 8.0, damp: 0.85, maxD: 25, maxF: 0.6 * strength };
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
        if (!this.nodes || this.nodes.length === 0) return;
        const time = performance.now() * 0.001;
        const focused = this.networkManager.getFocusedNode();
        
        const dists = this.nodes.map(n => {
            n.getWorldPosition(this._scratchVec);
            return this._scratchVec.distanceTo(this.camera.position);
        });
        const minD = Math.min(...dists), maxD = Math.max(...dists), range = Math.max(0.001, maxD - minD);

        this.nodes.forEach((n, i) => {
            const d = n.userData;
            
            // Search Filtering: hard visibility toggle
            const matchesSearch = !this.searchQuery || 
                (d.name && d.name.toLowerCase().includes(this.searchQuery)) || 
                (d.keywords && d.keywords.some(k => k.toLowerCase().includes(this.searchQuery)));
            
            n.visible = !!matchesSearch;

            if (!n.visible) {
                n.traverse(child => {
                    if (child.material) {
                        const mats = Array.isArray(child.material) ? child.material : [child.material];
                        mats.forEach(m => { m.opacity = 0; m.visible = false; });
                    }
                });
                if (focused === n) this.networkManager.setFocusedNode(null);
                return;
            }

            const brightness = 1 - ((dists[i] - minD) / range) * 0.6;

            if (!d.solarFlare && Math.random() < 0.0005) {
                d.solarFlare = 15;
            }

            const isActive = (focused === n);
            const isVisible = n.visible && (isActive || this.persistentTooltipNodeToDiv.has(n));
            let opacity = isVisible ? 1 : (n.visible ? 0.94 : 0);
            
            let forceInvisible = false;
            if (this._portalFadeInMultiplier !== undefined && this._portalFadeInMultiplier !== null) {
                opacity *= this._portalFadeInMultiplier;
                if (this._portalFadeInMultiplier === 0) forceInvisible = true;
            }

            const isTransitioning = this._portalFadeInMultiplier !== undefined && this._portalFadeInMultiplier !== null;

            // Optimization: iterate cached materials directly
            d.cachedMaterials.forEach(m => {
                m.opacity = opacity;
                m.visible = n.visible && !forceInvisible; // Sync material visibility with node
                if (d.colorR !== undefined) {
                    m.color.setRGB((d.colorR / 255) * brightness, (d.colorG / 255) * brightness, (d.colorB / 255) * brightness);
                    if (m.emissive) m.emissive.copy(m.color);
                    
                    if (m.emissiveIntensity !== undefined) {
                        if (m._baseEmissiveIntensity === undefined) m._baseEmissiveIntensity = m.emissiveIntensity;
                        
                        // Disable twinkle and hover effects during transition for stability
                        if (isTransitioning) {
                            m.emissiveIntensity = m._baseEmissiveIntensity * brightness;
                        } else {
                            const twinkleFreq = d.is_accentuated ? 3.0 : 2.5;
                            const twinkleAmp = d.is_accentuated ? 0.8 : 0.5;
                            const twinkle = 1.0 + Math.sin(time * twinkleFreq + d.phase) * twinkleAmp;
                            
                            const hoverBoost = isActive ? 2.5 : 1.0;
                            let flareBoost = 1.0;
                            if (d.solarFlare > 0) {
                                flareBoost = 8.0 * (d.solarFlare / 15);
                                if (m === d.cachedMaterials[0]) d.solarFlare--; // Only decrement once per node
                            }
                            // Accentuated nodes get a smaller emissive boost now
                            const accentBoost = d.is_accentuated ? 1.4 : 1.0;
                            m.emissiveIntensity = m._baseEmissiveIntensity * brightness * hoverBoost * twinkle * flareBoost * accentBoost;
                        }
                    }
                }
            });

            n.position.copy(d.originalPosition);
            
            // Stable scale during transition, dynamic pulse otherwise
            if (isTransitioning) {
                const baseS = d.is_accentuated ? 2.0 : 1.4;
                n.scale.set(baseS, baseS, baseS);
            } else {
                const pulseFreq = d.is_accentuated ? 2.0 : 1.5;
                const pulseAmp = d.is_accentuated ? 0.15 : 0.08;
                const baseS = d.is_accentuated ? 2.0 : 1.4;
                const s = baseS + Math.sin(time * pulseFreq + d.phase) * pulseAmp;
                n.scale.set(s, s, s);
            }

            // Optimization: iterate cached moons directly
            d.cachedMoons.forEach(moonGroup => {
                moonGroup.rotation.y = time * moonGroup.userData.speed;
                moonGroup.rotation.z = time * (moonGroup.userData.speed * 0.3);
            });

            // Animate station rings (if any)
            n.children.forEach(child => {
                if (child.userData?.isStationRing) {
                    child.rotation.x += 0.01;
                    child.rotation.y += 0.02;
                }
            });
        });
    }

    getFront20PercentWithTier() {
        const tempPos = new THREE.Vector3();
        const withDist = this.nodes
            .filter(n => n.visible)
            .map(n => { n.getWorldPosition(tempPos); return { node: n, dist: tempPos.distanceTo(this.camera.position) }; });
        withDist.sort((a, b) => a.dist - b.dist);
        const c20 = Math.max(1, Math.floor(this.nodes.length * 0.2)), f10 = Math.max(1, Math.floor(this.nodes.length * 0.1));
        return withDist.slice(0, c20).map((e, i) => ({ node: e.node, inFront10: i < f10 }));
    }

    updatePersistentTooltips() {
        if (!this.persistentTooltipsContainer || this.nodes.length === 0) return;
        const focused = this.networkManager.getFocusedNode();
        const toShow = this.getFront20PercentWithTier().filter(e => e.node !== focused && e.node.userData?.name);
        const rect = this.renderer.domElement.getBoundingClientRect();

        const startFade = (el, node) => {
            if (el.style.visibility !== 'visible' || el._fadeOutTimeout) return;
            this.persistentTooltipNodeToDiv.delete(node);
            el.style.opacity = '0';
            el._fadeOutTimeout = setTimeout(() => { 
                el.style.visibility = 'hidden'; 
                el._fadeOutTimeout = null; 
                this.tooltipPool.push(el); // Return to pool
            }, 780);
        };

        const toShowNodes = new Set(toShow.map(e => e.node));
        toShow.forEach(e => {
            const n = e.node, opacity = e.inFront10 ? '1' : '0.2';
            n.getWorldPosition(this._scratchVec);
            const dist = this._scratchVec.distanceTo(this.camera.position);
            this._scratchVec.project(this.camera);
            
            if (this._scratchVec.z > 1 || this._scratchVec.z < -1) { 
                const d = this.persistentTooltipNodeToDiv.get(n); 
                if (d) startFade(d, n); 
                return; 
            }

            const yOff = 34 + Math.max(0, (18 - dist) * 1.5);
            const left = (this._scratchVec.x * 0.5 + 0.5) * rect.width;
            const top = (0.5 - this._scratchVec.y * 0.5) * rect.height + yOff;
            
            let el = this.persistentTooltipNodeToDiv.get(n);
            if (!el) {
                el = this.tooltipPool.pop() || document.createElement('div');
                if (!el.parentElement) {
                    el.className = 'persistent-tooltip-item absolute px-1 py-0.5 rounded text-xs pointer-events-none whitespace-nowrap';
                    this.persistentTooltipsContainer.appendChild(el);
                }
                this.persistentTooltipNodeToDiv.set(n, el);
                el.style.visibility = 'visible'; 
                el.style.opacity = '0';
                requestAnimationFrame(() => requestAnimationFrame(() => { el.style.opacity = opacity; }));
            }
            el.textContent = n.userData.name;
            const s = this.getNodeTooltipStyles(n);
            Object.assign(el.style, { 
                left: left + 'px', 
                top: top + 'px', 
                transform: 'translate(-50%, -50%) translate(-12px, 0)', 
                background: s.background, 
                color: s.color, 
                opacity 
            });
        });

        for (const [n, d] of Array.from(this.persistentTooltipNodeToDiv)) if (!toShowNodes.has(n)) startFade(d, n);
    }

    animate() {
        requestAnimationFrame(() => this.animate());
        const now = performance.now();
        const dt = this._lastFrameTime ? (now - this._lastFrameTime) / 1000 : 0.016;
        this._lastFrameTime = now;

        // 1. Update Portal Transition state FIRST
        if (this._portalTransition) {
            const tr = this._portalTransition;
            if (tr.phase === 'camera_fade_out') {
                const rawT = Math.min((now - tr.startTime) / tr.duration, 1);
                // Ease In Out Quad
                const t = rawT < 0.5 ? 2 * rawT * rawT : 1 - Math.pow(-2 * rawT + 2, 2) / 2;
                
                this.camera.position.lerpVectors(tr.cameraStart, tr.cameraEnd, t);
                this.controls.target.lerpVectors(tr.targetStart, tr.targetEnd, t);
                
                // Fade OUT current network
                this.nodes.forEach(n => {
                    (n.userData.cachedMaterials || []).forEach(m => { m.opacity = (1 - t) * 0.94; });
                });
                this.connections.forEach(c => {
                    if (c.mesh.material) c.mesh.material.opacity = (1 - t) * (c.baseOpacity ?? 0.5);
                });

                if (rawT >= 1) {
                    this.clearAll(); // BLANK SCREEN IMMEDIATELY
                    tr.phase = 'loading';
                    this._portalFadeInMultiplier = 0; // Force immediate invisibility
                    
                    const loadingOverlay = document.getElementById('loading-overlay');
                    if (loadingOverlay) {
                        loadingOverlay.style.display = 'flex';
                        loadingOverlay.style.opacity = '1';
                        loadingOverlay.style.pointerEvents = 'auto';
                    }
                    
                    const app = window.telarisApp || this;
                    const dataPromise = tr.dataPromise || apiFetch(`api/nodes.php?constellation_id=${tr.targetId}`).then(r => r.json());
                    
                    dataPromise.then((nodeData) => {
                        if (this._portalTransition && this._portalTransition.phase === 'loading') {
                            app.loadDataForConstellation(tr.targetId, nodeData).then(() => {
                                if (this._portalTransition && this._portalTransition.phase === 'loading') {
                                    this.navigationStack.push(tr.targetId);
                                    this.updateBackButtonVisibility();
                                    
                                    const loadingOverlay = document.getElementById('loading-overlay');
                                    if (loadingOverlay) {
                                        loadingOverlay.style.opacity = '0';
                                        loadingOverlay.style.pointerEvents = 'none';
                                        setTimeout(() => { loadingOverlay.style.display = 'none'; }, 300);
                                    }

                                    this._portalTransition.phase = 'fade_in';
                                    this._portalTransition.fadeInStartTime = performance.now();
                                    this._portalTransition.fadeInDuration = tr.targetFadeInDuration || 1000;
                                    this._portalFadeInMultiplier = 0; // Explicitly 0 before any update occurs
                                }
                            });
                        }
                    }).catch(err => {
                        console.error('Portal transition failed:', err);
                        this._portalTransition = null;
                        this.controls.enabled = true;
                    });
                }
            } else if (tr.phase === 'fade_in') {
                const fadeT = Math.min((now - tr.fadeInStartTime) / tr.fadeInDuration, 1);
                this._portalFadeInMultiplier = fadeT;
                if (fadeT >= 1) {
                    this._portalTransition = null;
                    this._portalFadeInMultiplier = undefined;
                    this.controls.enabled = true;
                }
            } else if (tr.phase === 'back_cross_fade') {
                const t = Math.min((now - tr.startTime) / tr.duration, 1);
                // Ease In Out
                const easeT = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
                
                // 1. Zoom OUT and fade OUT outgoing
                if (this._outgoingNodes) {
                    const opacity = (1 - easeT) * 0.94;
                    const posScale = 1 - (easeT * 0.4); // Recede positions toward center
                    const nodeScale = 1 - (easeT * 0.6); // Shrink nodes too
                    
                    this._outgoingNodes.forEach(node => {
                        // Move toward center
                        if (node.userData.originalPosition) {
                            node.position.copy(node.userData.originalPosition).multiplyScalar(posScale);
                        }
                        node.scale.set(nodeScale, nodeScale, nodeScale);
                        
                        node.traverse(c => {
                            if (c.material && c.name !== 'portal_hitbox') {
                                c.material.opacity = opacity;
                                c.material.visible = opacity > 0.001;
                            }
                        });
                    });
                }
                if (this._outgoingConnections) {
                    this._outgoingConnections.forEach(c => {
                        if (c.mesh.material) {
                            // Scale by its existing currentOpacity so we don't flash lines that were hidden
                            c.mesh.material.opacity = c.currentOpacity * (1 - easeT);
                            c.mesh.visible = c.mesh.material.opacity > 0.001;
                        }
                    });
                }

                // 2. Fade IN new (using the shared multiplier)
                this._portalFadeInMultiplier = easeT;
                
                if (this.nodes) {
                    this.nodes.forEach(node => {
                        node.visible = true;
                        // Use original position during fade in to stay stable
                        if (node.userData.originalPosition) {
                            node.position.copy(node.userData.originalPosition);
                        }
                    });
                }

                if (t >= 1) {
                    // Final cleanup of outgoing meshes
                    if (this._outgoingNodes) {
                        this._outgoingNodes.forEach(node => {
                            this.scene.remove(node);
                            node.traverse(c => {
                                if (c.geometry) c.geometry.dispose();
                                if (c.material) (Array.isArray(c.material) ? c.material : [c.material]).forEach(m => m.dispose());
                            });
                        });
                        this._outgoingNodes = null;
                    }
                    if (this._outgoingConnections) {
                        this._outgoingConnections.forEach(c => {
                            this.scene.remove(c.mesh);
                            if (c.mesh.geometry) c.mesh.geometry.dispose();
                            if (c.mesh.material) c.mesh.material.dispose();
                        });
                        this._outgoingConnections = null;
                    }

                    this._portalTransition = null;
                    this._portalFadeInMultiplier = undefined;
                    this.controls.enabled = true;
                }
            }
        }

        // 2. Normal updates (will use the _portalFadeInMultiplier set above)
        const isZooming = this._portalTransition && this._portalTransition.phase === 'camera_fade_out';
        const isBackCrossFade = this._portalTransition && this._portalTransition.phase === 'back_cross_fade';
        
        if (!isZooming) {
            this.controls.autoRotate = (now - this.lastInteractionAt) > this.idleRotateDelayMs;
            if (!isBackCrossFade) {
                this.applyForces(dt, 0.05);
            }
            this.controls.update();
            this.updateNodes();
            this.updateConnections(dt);
        }
        
        this.updatePersistentTooltips();
        this.updateComet(dt);
        this.updateRocket(dt);
        this.updateUFO(dt);
        this.updateHUD();

        // Portal meshes: slow rotation + scale pulse; rev up when clicked
        const time = now * 0.001;
        if (this._revvingPortal && (now - this._revvingPortal.startTime) >= 300) {
            const { node, targetId } = this._revvingPortal;
            this._revvingPortal = null;
            this.runPortalTransition(node, targetId);
        }
        this.scene.traverse((object) => {
            if (object.isPortal) {
                const isRevving = this._revvingPortal && this._revvingPortal.node === object;
                const revMult = isRevving ? 8 : 1;
                const pulseSpeed = isRevving ? 12 : 2.0;
                const pulseAmp = isRevving ? 0.3 : 0.1;
                object.rotation.y += 0.01 * revMult;
                object.rotation.z += 0.005 * revMult;
                const pulse = 1 + Math.sin(time * pulseSpeed) * pulseAmp;
                const baseScale = object.userData.baseScale ?? 1;
                object.scale.set(baseScale * pulse, baseScale * pulse, baseScale * pulse);
            }
        });

        if (this.composer) {
            this.renderer.autoClear = false;
            this.renderer.clear();
            this.composer.render();
        } else {
            this.renderer.render(this.scene, this.camera);
        }
    }

    updateHUD() {
        // Only update every ~100ms for performance
        const now = performance.now();
        if (this._lastHudUpdate && now - this._lastHudUpdate < 100) return;
        this._lastHudUpdate = now;

        const elX = document.getElementById('hud-x');
        const elY = document.getElementById('hud-y');
        const elZ = document.getElementById('hud-z');
        const elNodes = document.getElementById('hud-nodes');
        const elConns = document.getElementById('hud-connections');

        if (elX) elX.innerText = this.camera.position.x.toFixed(1);
        if (elY) elY.innerText = this.camera.position.y.toFixed(1);
        if (elZ) elZ.innerText = this.camera.position.z.toFixed(1);
        
        if (elNodes && this.nodes) {
            const visibleNodes = this.nodes.filter(n => n.visible).length;
            elNodes.innerText = this.searchQuery ? `${visibleNodes}/${this.nodes.length}` : this.nodes.length;
        }
        if (elConns && this.connections) {
            const visibleConns = this.connections.filter(c => c.node1.visible && c.node2.visible).length;
            elConns.innerText = this.searchQuery ? `${visibleConns}/${this.connections.length}` : this.connections.length;
        }
    }

    setupSearch() {
        const searchInput = document.getElementById('hud-search');
        const clearBtn = document.getElementById('hud-search-clear');
        if (!searchInput) return;

        const updateSearch = (val) => {
            this.searchQuery = val.toLowerCase().trim();
            if (clearBtn) clearBtn.style.display = this.searchQuery ? 'block' : 'none';
            
            // Clear focus immediately so no connections related to hidden nodes remain
            this.networkManager.setFocusedNode(null);
            if (this.tooltip) this.hideMainTooltip();
            
            this.markInteraction();
        };

        searchInput.addEventListener('input', (e) => updateSearch(e.target.value));

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                updateSearch('');
                searchInput.focus();
            });
        }

        // Prevent orbit controls from capturing keystrokes when typing
        searchInput.addEventListener('keydown', (e) => e.stopPropagation());
    }

    onWindowResize() {
        this.camera.aspect = window.innerWidth / window.innerHeight;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(window.innerWidth, window.innerHeight);
        this.composer?.setSize(window.innerWidth, window.innerHeight);
    }
}

export { TelarisNetwork };
