import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { BokehPass } from 'three/addons/postprocessing/BokehPass.js';
import { LineSegments2 } from 'three/addons/lines/LineSegments2.js';
import { LineSegmentsGeometry } from 'three/addons/lines/LineSegmentsGeometry.js';
import { LineMaterial } from 'three/addons/lines/LineMaterial.js';
import { apiFetch } from './api.js';
import { createNodeIcon } from './telaris-node-icons.js';
import { NetworkManager } from './network-manager.js';
import { GeometryManager } from './geometry-manager.js';
import { getTheme } from './themes.js';

class TelarisNetwork {
    constructor() {
        this.scene = new THREE.Scene();
        this.bgScene = new THREE.Scene();
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
        this.currentTheme = getTheme(window.TELARIS_THEME_ID || 'cosmic');
        this._portalFadeInMultiplier = null;
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
        this.soundEnabled = true;
        this.clearAll();
        this.init();
        this.setupSearch();
    }

    getNodeAnchorPosition(node) {
        node.getWorldPosition(this._scratchVec);
        return this._scratchVec;
    }

    getNodeTooltipStyles(node) {
        const d = node.userData;
        if (!d || d.colorR === undefined) return { backgroundColor: 'rgba(0,0,0,0.35)', color: 'rgb(255,255,255)' };
        const r = d.colorR, g = d.colorG, b = d.colorB;
        const darken = 0.5;
        const dr = Math.round(r * darken), dg = Math.round(g * darken), db = Math.round(b * darken);
        return {
            backgroundColor: `rgba(${dr},${dg},${db},0.35)`,
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
            '&description=' + encodeURIComponent(d.description || '') +
            '&open_portal_text=' + encodeURIComponent(window.TELARIS_OPEN_PORTAL_TEXT || 'Open the Portal');
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
        const videoWrap = document.getElementById('rm-video-wrap');
        const videoEl = document.getElementById('rm-video');
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

        // Embed (server sanitizes to iframe-only; double-check client-side)
        if (embedWrap && embedEl) {
            if (d.embed_code) {
                const tmp = document.createElement('div');
                tmp.innerHTML = d.embed_code;
                // Only allow <iframe> elements through
                embedEl.innerHTML = '';
                tmp.querySelectorAll('iframe').forEach(iframe => {
                    const src = iframe.getAttribute('src') || '';
                    if (src.match(/^https?:\/\//i)) {
                        embedEl.appendChild(iframe.cloneNode(true));
                    }
                });
                embedWrap.classList.toggle('hidden', embedEl.children.length === 0);
            } else {
                embedEl.innerHTML = '';
                embedWrap.classList.add('hidden');
            }
        }

        // Audio
        if (audioWrap && audioEl) {
            if (d.audio_url) {
                audioEl.src = d.audio_url;
                audioEl.load();
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
                        console.warn('Autoplay prevented or failed:', err);
                    });
                }
            } else {
                audioEl.src = '';
                audioEl.load();
                audioWrap.classList.add('hidden');
            }
        }

        // Video
        if (videoWrap && videoEl) {
            if (d.video_url) {
                videoEl.src = d.video_url;
                videoEl.load();
                videoWrap.classList.remove('hidden');
                
                if (d.video_autoplay) {
                    videoEl.play().catch(err => {
                        console.warn('Video autoplay prevented or failed:', err);
                    });
                }
            } else {
                videoEl.src = '';
                videoEl.load();
                videoWrap.classList.add('hidden');
            }
        }

        // URL / Action Button
        if (urlWrap && urlButton) {
            if (d.node_type === 'portal' && d.target_constellation_id != null) {
                urlWrap.classList.remove('hidden');
                urlButton.textContent = window.TELARIS_OPEN_PORTAL_TEXT || 'Open the Portal';
                urlButton.onclick = () => {
                    this.closeRichMediaWindow();
                    if (window.telarisApp) {
                        window.telarisApp.startPortalRev(node, d.target_constellation_id);
                    } else {
                        window.location.href = `index.php?constellation_id=${d.target_constellation_id}`;
                    }
                };
            } else if (d.url) {
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
        this.playGlitch();
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
            const video = document.getElementById('rm-video');
            if(video) {
                video.pause();
                video.src = '';
                video.load();
            }
            const embed = document.getElementById('rm-embed');
            if(embed) { embed.innerHTML = ''; }
            overlay.classList.add('hidden');
        }, 500);
    }

    _getSoundscape() {
        return (window._telarisSoundscape && window._telarisSoundscape()) || window._telarisSoundscapeInstance || null;
    }

    playGlitch() {
        if (!this.soundEnabled) return;
        const soundscape = this._getSoundscape();
        if (soundscape && typeof soundscape.playGlitch === 'function') {
            soundscape.playGlitch();
        }
    }

    setupSoundToggle() {
        const toggleBtn = document.getElementById('hud-sound-toggle');
        if (!toggleBtn) return;

        toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.soundEnabled = !this.soundEnabled;
            toggleBtn.innerText = this.soundEnabled ? 'ON' : 'OFF';

            const soundscape = this._getSoundscape();
            if (soundscape) {
                soundscape.setVolume(this.soundEnabled ? 0.65 : 0);
            }
        });
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
        this.bgScene.add(this.stars);
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
                        this.bgScene.add(this.bgNebulas);
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
        this.tooltip.style.transform = 'translate(-50%, -100%) translate(0, -10px) scale(0.95)';
        this.tooltipHideTimeout = setTimeout(() => {
            this.tooltip.style.visibility = 'hidden';
            this.tooltipHideTimeout = null;
        }, 300);
    }

    init() {
        // Background renderer (blurred canvas — stars, grids, nebulas, animations)
        this.bgRenderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
        this.bgRenderer.setSize(window.innerWidth, window.innerHeight);
        this.bgRenderer.setPixelRatio(window.devicePixelRatio);
        this.bgRenderer.setClearColor(0x000000, 1);
        const bgCanvas = this.bgRenderer.domElement;
        bgCanvas.id = 'telaris-bg-canvas';
        Object.assign(bgCanvas.style, {
            position: 'absolute',
            left: '0', top: '0',
            width: '100%', height: '100%',
            display: 'block',
            zIndex: '1'
        });

        // Ambient light for background scene (needed by MeshStandardMaterial objects like UFO/rocket)
        this.bgScene.add(new THREE.AmbientLight(0xffffff, 0.5));

        // Foreground renderer (unblurred canvas — nodes and connections only)
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
            backgroundColor: 'transparent',
            zIndex: '2',
        });

        const canvasContainer = document.getElementById('canvas-container');
        const canvasWrapper = document.getElementById('webgl-canvas-wrapper');

        this.initStarfield();
        this.initNebulas();
        if (canvasWrapper) {
            canvasWrapper.appendChild(bgCanvas);
            canvasWrapper.appendChild(canvasElement);
        } else {
            canvasContainer.appendChild(bgCanvas);
            canvasContainer.appendChild(canvasElement);
        }
        canvasContainer.appendChild(this.tooltip);

        this.camera.position.set(0, 0, 13);

        // Setup Post-processing — runs on bgRenderer (bloom + depth-of-field blur)
        const bgRenderScene = new RenderPass(this.bgScene, this.camera);

        // Depth texture is required by BokehPass to read per-pixel depth
        const bgRenderTarget = new THREE.WebGLRenderTarget(
            window.innerWidth, window.innerHeight,
            {
                minFilter: THREE.LinearFilter,
                magFilter: THREE.LinearFilter,
                format: THREE.RGBAFormat,
                type: THREE.UnsignedByteType,
                depthTexture: new THREE.DepthTexture(window.innerWidth, window.innerHeight),
                depthBuffer: true,
            }
        );

        // Depth-of-field: near objects sharp, far objects blurry.
        // focus = distance from camera to the in-focus plane (camera is at z=13).
        // aperture controls how fast blur increases with distance.
        // maxblur is the maximum blur amount (fraction of screen height).
        this.bokehPass = new BokehPass(this.bgScene, this.camera, {
            focus:    3.0,
            aperture: 0.004,
            maxblur:  0.007,
        });

        this.bloomPass = new UnrealBloomPass(
            new THREE.Vector2(window.innerWidth, window.innerHeight),
            0.6, 0.3, 0.9
        );
        this.bloomPass.renderToScreen = true;
        this.bloomPass.clear = false;

        // Pass order: render → DoF blur → bloom
        this.bgComposer = new EffectComposer(this.bgRenderer, bgRenderTarget);
        this.bgComposer.addPass(bgRenderScene);
        this.bgComposer.addPass(this.bokehPass);
        this.bgComposer.addPass(this.bloomPass);

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
        this.setupSoundToggle();
        window.addEventListener('resize', () => this.onWindowResize());
        
        this.loadApiKey().then(() => this.loadData());
        if (this.currentTheme.animations?.comet)    this.initComet();
        if (this.currentTheme.animations?.rocket)   this.initRocket();
        if (this.currentTheme.animations?.ufo)      this.initUFO();
        this.initGlitchyGrid();
        this.initTechBackground();
        this.setupTheme(this.currentTheme);
        this.animate();
    }

    setupTheme(theme) {
        if (!theme) return;

        // 1. Background
        if (this.stars) this.stars.visible = !!theme.background.starfield;
        if (this.bgNebulas) this.bgNebulas.visible = !!theme.background.nebulas;
        if (this.glitchyGrid) this.glitchyGrid.visible = !!theme.background.grid;

        // 3D tech background (wireframe cage + particles)
        if (this.techBg) this.techBg.visible = !!theme.background.techGrid;

        // Bloom tuning per theme
        if (this.bloomPass) {
            if (theme.id === 'tech') {
                this.bloomPass.threshold = 0.05;
                this.bloomPass.strength  = 1.6;
                this.bloomPass.radius    = 0.7;
            } else {
                this.bloomPass.threshold = 0.9;
                this.bloomPass.strength  = 0.6;
                this.bloomPass.radius    = 0.3;
            }
        }

        // CSS blur on foreground canvas — Tech theme only
        if (this.renderer?.domElement) {
            this.renderer.domElement.style.filter =
                theme.id === 'tech' ? 'blur(0.7px) brightness(1.15)' : '';
        }

        // Depth-of-field (bokeh) blur on background — Tech theme only
        if (this.bokehPass) {
            this.bokehPass.enabled = theme.id === 'tech';
        }

        const bgColor = theme.background.color !== undefined ? theme.background.color : 0x000000;
        this.bgRenderer.setClearColor(bgColor, 1);
        this.renderer.setClearColor(0x000000, 0); // foreground canvas stays transparent

        // 2. Extra animations visibility
        if (this.rocket) this.rocket.visible = false;
        if (this.ufo) this.ufo.visible = false;
        if (this.comet) this.comet.visible = false;
        
        // Reset states
        if (this.rocket) this.rocket.userData.active = false;
        if (this.ufo) this.ufo.userData.active = false;
        if (this.comet) this.comet.userData.active = false;

        // 3. Lighting
        const toRemove = [];
        this.scene.traverse(obj => {
            if (obj.isAmbientLight) {
                obj.color.setHex(theme.lighting.ambient.color);
                obj.intensity = theme.lighting.ambient.intensity;
            } else if (obj.isPointLight) {
                toRemove.push(obj);
            }
        });
        toRemove.forEach(obj => this.scene.remove(obj));

        // Add theme point lights
        theme.lighting.points.forEach(lp => {
            const light = new THREE.PointLight(lp.color, 1, 50);
            light.position.set(lp.x, lp.y, lp.z);
            this.scene.add(light);
        });
    }

    initGlitchyGrid() {
        this.glitchyGrid = new THREE.Group();
        const size = 100;
        const divisions = 20;
        const gridHelper = new THREE.GridHelper(size, divisions, 0x444444, 0x222222);
        gridHelper.rotation.x = Math.PI / 2; // Face forward
        gridHelper.position.z = -30;
        this.glitchyGrid.add(gridHelper);
        
        // Add a second back grid for parallax
        const gridHelper2 = new THREE.GridHelper(size * 2, divisions, 0x222222, 0x111111);
        gridHelper2.rotation.x = Math.PI / 2;
        gridHelper2.position.z = -60;
        this.glitchyGrid.add(gridHelper2);

        this.glitchyGrid.visible = !!(this.currentTheme && this.currentTheme.background.grid);
        this.bgScene.add(this.glitchyGrid);
    }

    updateGlitchyGrid(dt) {
        if (!this.glitchyGrid || !this.glitchyGrid.visible) return;
        
        // Steady slow movement
        this.glitchyGrid.children.forEach((grid, i) => {
            grid.rotation.z += dt * (0.01 + i * 0.005);
        });

        // Occasional twitch
        if (Math.random() < 0.01) {
            const twitchX = (Math.random() - 0.5) * 0.5;
            const twitchY = (Math.random() - 0.5) * 0.5;
            this.glitchyGrid.position.set(twitchX, twitchY, 0);
            
            // Randomly hide/show one grid for a frame
            const target = this.glitchyGrid.children[Math.floor(Math.random() * this.glitchyGrid.children.length)];
            target.visible = false;
            setTimeout(() => { target.visible = true; }, 50);
        } else {
            this.glitchyGrid.position.lerp(new THREE.Vector3(0, 0, 0), 0.1);
        }
    }

    initTechBackground() {
        this.techBg = new THREE.Group();
        this.techBgLineMats = []; // tracked so onWindowResize can update resolution

        const res = new THREE.Vector2(window.innerWidth, window.innerHeight);

        // ── Helpers ────────────────────────────────────────────────────
        // Thick lines via LineSegments2 (actual pixel linewidth, not WebGL 1px limit).
        // pts = flat array [x1,y1,z1, x2,y2,z2, ...] — each 6-element pair is one segment.
        const addThick = (pts, color, linewidth, opacity = 1.0) => {
            const geo = new LineSegmentsGeometry();
            geo.setPositions(pts);
            const mat = new LineMaterial({
                color,
                linewidth,
                resolution: res,
                transparent: opacity < 1.0,
                opacity,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
            });
            this.techBgLineMats.push(mat);
            this.techBg.add(new LineSegments2(geo, mat));
        };

        // Regular 1-px LineSegments for subtle/high-count elements.
        const addThin = (pts, color, opacity = 1.0) => {
            const geo = new THREE.BufferGeometry();
            geo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(pts), 3));
            this.techBg.add(new THREE.LineSegments(geo, new THREE.LineBasicMaterial({
                color, transparent: true, opacity,
                blending: THREE.AdditiveBlending, depthWrite: false,
            })));
        };

        // ── Corridor dimensions ────────────────────────────────────────
        // Camera sits at z=13 looking toward z=0; corridor goes into -Z.
        // Z_BACK / Z_FAR extend symmetrically well past the camera's max orbit
        // radius (22) so panning in any direction shows continuous corridor.
        const Z_BACK = 230, Z_FAR = -230;
        const CW = 105;       // half-width  (X = ±105)
        const CT = 30;        // ceiling Y   (+30)
        const CF = -52;       // floor Y     (-52)

        // ── CEILING — the dominant element (Image 1: glass panel grid) ─
        // Longitudinal lines (run along Z, create depth rush)
        const ceilLongPts = [];
        for (let x = -CW; x <= CW; x += 15) {
            ceilLongPts.push(x, CT, Z_BACK,  x, CT, Z_FAR);
        }
        addThick(ceilLongPts, 0x335566, 1.1, 1.0);

        // Cross lines (perpendicular to Z, create panel grid sections)
        const ceilCrossPts = [];
        for (let z = Z_BACK; z >= Z_FAR; z -= 16) {
            ceilCrossPts.push(-CW, CT, z,  CW, CT, z);
        }
        addThick(ceilCrossPts, 0x335566, 1.1, 1.0);

        // Inner ceiling layer (lower plane — layered depth like Image 1)
        const ceilInnerPts = [];
        const CI = 18; // inner ceiling Y
        for (let x = -CW; x <= CW; x += 30) {
            ceilInnerPts.push(x, CI, Z_BACK,  x, CI, Z_FAR);
        }
        for (let z = Z_BACK; z >= Z_FAR; z -= 30) {
            ceilInnerPts.push(-CW, CI, z,  CW, CI, z);
        }
        addThick(ceilInnerPts, 0x1a3344, 0.7, 1.0);

        // X-brace diagonals within ceiling panels (glass-panel cross pattern)
        const ceilDiagPts = [];
        for (let z = Z_BACK - 16; z >= Z_FAR + 16; z -= 32) {
            for (let x = -CW; x < CW; x += 30) {
                const x2 = x + 30;
                ceilDiagPts.push(x, CT, z,    x2, CT, z - 16);
                ceilDiagPts.push(x2, CT, z,   x,  CT, z - 16);
            }
        }
        addThin(ceilDiagPts, 0x1a3344, 1.0);

        // ── FLOOR — darker reflective grid (Image 1 bottom, Image 2 floor) ─
        const floorLongPts = [];
        for (let x = -CW; x <= CW; x += 20) {
            floorLongPts.push(x, CF, Z_BACK,  x, CF, Z_FAR);
        }
        addThick(floorLongPts, 0x1a3355, 0.8, 1.0);

        const floorCrossPts = [];
        for (let z = Z_BACK; z >= Z_FAR; z -= 22) {
            floorCrossPts.push(-CW, CF, z,  CW, CF, z);
        }
        addThick(floorCrossPts, 0x1a3355, 0.8, 1.0);

        // ── SIDE WALLS — vertical panel structure ──────────────────────
        const wallPts = [];
        // Horizontal runs (top/bottom rails + mid rails)
        for (const y of [CF, CF + 25, 0, CT - 5, CT]) {
            wallPts.push(-CW, y, Z_BACK,  -CW, y, Z_FAR);
            wallPts.push( CW, y, Z_BACK,   CW, y, Z_FAR);
        }
        // Vertical stanchions at regular Z intervals
        for (let z = Z_BACK; z >= Z_FAR; z -= 30) {
            wallPts.push(-CW, CF, z,  -CW, CT, z);
            wallPts.push( CW, CF, z,   CW, CT, z);
        }
        addThick(wallPts, 0x0d1f33, 0.7, 1.0);

        // ── CROSS-SECTION FRAMES — bright rings along the corridor ─────
        const framePts = [];
        for (let z = Z_BACK; z >= Z_FAR; z -= 32) {
            framePts.push(
                -CW, CF, z,   CW, CF, z,   // bottom
                 CW, CF, z,   CW, CT, z,   // right
                 CW, CT, z,  -CW, CT, z,   // top
                -CW, CT, z,  -CW, CF, z    // left
            );
        }
        addThick(framePts, 0x224466, 1.2, 1.0);

        // ── ENERGY BEAMS — bright diagonal rays converging to VP ───────
        // These create the dramatic light-ray effect from Image 1.
        // Lines go from outer corners near camera toward the vanishing point.
        const VP = [0, -10, -230];
        const beamSources = [
            [-CW,  CT,  Z_BACK],
            [ CW,  CT,  Z_BACK],
            [-CW,  CF,  Z_BACK],
            [ CW,  CF,  Z_BACK],
            [  0,  CT,  Z_BACK],
            [-CW,   0,  Z_BACK],
            [ CW,   0,  Z_BACK],
            [  0,  CF,  Z_BACK],
        ];
        const beamPts = [];
        for (const [px, py, pz] of beamSources) {
            beamPts.push(px, py, pz,  VP[0], VP[1], VP[2]);
        }
        addThick(beamPts, 0x223344, 0.7, 1.0);

        // ── FLOATING CIRCUIT NODES — Image 2 holographic panels ────────
        // Small wireframe rectangles floating in the corridor space.
        const nodePts = [];
        const floatingPanels = [
            [-70, 12, -55,  28, 14],
            [ 60, 15, -80,  32, 12],
            [-55, -20, -110, 24, 10],
            [ 65, -15, -130, 30, 12],
            [-75,  8, -160, 26, 10],
            [ 50,  5, -180, 22, 10],
        ];
        for (const [x, y, z, w, h] of floatingPanels) {
            nodePts.push(
                x,   y,   z,  x+w, y,   z,
                x+w, y,   z,  x+w, y-h, z,
                x+w, y-h, z,  x,   y-h, z,
                x,   y-h, z,  x,   y,   z,
            );
            // Horizontal divider line inside panel
            nodePts.push(x, y - 3, z,  x + w, y - 3, z);
            // Short trace line extending from panel
            const tx = x + w / 2;
            nodePts.push(tx, y, z,  tx, y + 6, z);
        }
        addThick(nodePts, 0x004455, 0.8, 1.0);

        // Mirrored floating panels on the +Z side
        const nodePtsBack = [];
        const floatingPanelsBack = [
            [-70, 12, 55,  28, 14],
            [ 60, 15, 80,  32, 12],
            [-55, -20, 110, 24, 10],
            [ 65, -15, 130, 30, 12],
            [-75,  8, 160, 26, 10],
            [ 50,  5, 180, 22, 10],
        ];
        for (const [x, y, z, w, h] of floatingPanelsBack) {
            nodePtsBack.push(
                x,   y,   z,  x+w, y,   z,
                x+w, y,   z,  x+w, y-h, z,
                x+w, y-h, z,  x,   y-h, z,
                x,   y-h, z,  x,   y,   z,
            );
            nodePtsBack.push(x, y - 3, z,  x + w, y - 3, z);
            const tx = x + w / 2;
            nodePtsBack.push(tx, y, z,  tx, y + 6, z);
        }
        addThick(nodePtsBack, 0x004455, 0.8, 1.0);

        // Short PCB-style traces connecting corridor wall to pads
        const tracePts = [];
        const tracePads = [
            [-CW, -15, -45], [-CW, 10, -90], [-CW, -30, -140],
            [ CW,  20, -60], [ CW, -10, -110], [ CW, 15, -165],
        ];
        for (const [wx, wy, wz] of tracePads) {
            const inner = wx > 0 ? wx - 18 : wx + 18;
            // Horizontal trace from wall inward
            tracePts.push(wx, wy, wz,  inner, wy, wz);
            // Short vertical leg
            tracePts.push(inner, wy, wz,  inner, wy + 8, wz);
            // Small pad square
            const S = 3;
            tracePts.push(
                inner - S, wy + 8,     wz,  inner + S, wy + 8,     wz,
                inner + S, wy + 8,     wz,  inner + S, wy + 8 + S, wz,
                inner + S, wy + 8 + S, wz,  inner - S, wy + 8 + S, wz,
                inner - S, wy + 8 + S, wz,  inner - S, wy + 8,     wz,
            );
        }
        addThick(tracePts, 0x003322, 0.7, 1.0);

        // Mirrored PCB traces on the +Z side
        const tracePtsBack = [];
        const tracePadsBack = [
            [-CW, -15, 45], [-CW, 10, 90], [-CW, -30, 140],
            [ CW,  20, 60], [ CW, -10, 110], [ CW, 15, 165],
        ];
        for (const [wx, wy, wz] of tracePadsBack) {
            const inner = wx > 0 ? wx - 18 : wx + 18;
            tracePtsBack.push(wx, wy, wz,  inner, wy, wz);
            tracePtsBack.push(inner, wy, wz,  inner, wy + 8, wz);
            const S = 3;
            tracePtsBack.push(
                inner - S, wy + 8,     wz,  inner + S, wy + 8,     wz,
                inner + S, wy + 8,     wz,  inner + S, wy + 8 + S, wz,
                inner + S, wy + 8 + S, wz,  inner - S, wy + 8 + S, wz,
                inner - S, wy + 8 + S, wz,  inner - S, wy + 8,     wz,
            );
        }
        addThick(tracePtsBack, 0x003322, 0.7, 1.0);

        // ── CENTRAL VANISHING POINT GLOW ───────────────────────────────
        const glowCanvas = document.createElement('canvas');
        glowCanvas.width = glowCanvas.height = 256;
        const ctx = glowCanvas.getContext('2d');
        const grad = ctx.createRadialGradient(128, 128, 0, 128, 128, 128);
        grad.addColorStop(0,    'rgba(180, 220, 255, 0.30)');
        grad.addColorStop(0.08, 'rgba(80,  160, 220, 0.18)');
        grad.addColorStop(0.3,  'rgba(20,   80, 160, 0.07)');
        grad.addColorStop(1,    'rgba(0,    10,  40,  0.0)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 256, 256);
        const glowTex = new THREE.CanvasTexture(glowCanvas);
        this.techGlowSprite = new THREE.Sprite(new THREE.SpriteMaterial({
            map: glowTex,
            blending: THREE.AdditiveBlending,
            transparent: true,
            depthWrite: false,
        }));
        this.techGlowSprite.scale.set(90, 90, 1);
        this.techGlowSprite.position.set(VP[0], VP[1], VP[2]);
        this.techBg.add(this.techGlowSprite);

        // ── MIRRORED ENERGY BEAMS — converge to +Z vanishing point ────────
        const VP_BACK = [0, -10, 230];
        const beamSourcesBack = [
            [-CW,  CT,  Z_FAR],
            [ CW,  CT,  Z_FAR],
            [-CW,  CF,  Z_FAR],
            [ CW,  CF,  Z_FAR],
            [  0,  CT,  Z_FAR],
            [-CW,   0,  Z_FAR],
            [ CW,   0,  Z_FAR],
            [  0,  CF,  Z_FAR],
        ];
        const beamPtsBack = [];
        for (const [px, py, pz] of beamSourcesBack) {
            beamPtsBack.push(px, py, pz,  VP_BACK[0], VP_BACK[1], VP_BACK[2]);
        }
        addThick(beamPtsBack, 0x223344, 0.7, 1.0);

        // ── MIRRORED VANISHING POINT GLOW (+Z end) ─────────────────────────
        this.techGlowSprite2 = new THREE.Sprite(new THREE.SpriteMaterial({
            map: glowTex,
            blending: THREE.AdditiveBlending,
            transparent: true,
            depthWrite: false,
        }));
        this.techGlowSprite2.scale.set(90, 90, 1);
        this.techGlowSprite2.position.set(VP_BACK[0], VP_BACK[1], VP_BACK[2]);
        this.techBg.add(this.techGlowSprite2);

        // ══════════════════════════════════════════════════════════════════
        // ── TECH BACKGROUND ANIMATIONS ─────────────────────────────────
        // ══════════════════════════════════════════════════════════════════

        // ── 1. ENERGY PULSES ──────────────────────────────────────────
        // Small glowing sprites that travel along ceiling/floor longitudinal lines.
        const pulseSegments = [];
        for (let x = -CW; x <= CW; x += 15) {
            pulseSegments.push([x, CT, Z_BACK, x, CT, Z_FAR]);  // ceiling, -Z direction
            pulseSegments.push([x, CT, Z_FAR,  x, CT, Z_BACK]); // ceiling, +Z direction
        }
        for (let x = -CW; x <= CW; x += 20) {
            pulseSegments.push([x, CF, Z_BACK, x, CF, Z_FAR]);  // floor, -Z direction
        }

        const pulseSpriteTex = (() => {
            const c = document.createElement('canvas');
            c.width = c.height = 64;
            const cx = c.getContext('2d');
            const g = cx.createRadialGradient(32, 32, 0, 32, 32, 32);
            g.addColorStop(0,   'rgba(180, 240, 255, 1.0)');
            g.addColorStop(0.3, 'rgba(60,  160, 220, 0.5)');
            g.addColorStop(1,   'rgba(0,    30,  80,  0.0)');
            cx.fillStyle = g;
            cx.fillRect(0, 0, 64, 64);
            return new THREE.CanvasTexture(c);
        })();

        this.techPulses = [];
        const NUM_PULSES = 3;
        for (let i = 0; i < NUM_PULSES; i++) {
            const mat = new THREE.SpriteMaterial({
                map: pulseSpriteTex,
                blending: THREE.AdditiveBlending,
                transparent: true,
                depthWrite: false,
                opacity: 0.3 + Math.random() * 0.2,
            });
            const sprite = new THREE.Sprite(mat);
            sprite.scale.set(3, 3, 1);
            const seg = pulseSegments[Math.floor(Math.random() * pulseSegments.length)];
            this.techPulses.push({
                sprite,
                ax: seg[0], ay: seg[1], az: seg[2], // start point
                bx: seg[3], by: seg[4], bz: seg[5], // end point
                t: Math.random(),
                speed: 0.09 + Math.random() * 0.06,
            });
            this.techBg.add(sprite);
        }

        // ── 2. FRAME RING CASCADE ─────────────────────────────────────
        // Bright rings that slide along Z, giving a "pulse wave through tunnel" effect.
        const cascadeRingGeo = (() => {
            const pts = new Float32Array([
                -CW, CF, 0,   CW, CF, 0,
                 CW, CT, 0,  -CW, CT, 0,
            ]);
            const g = new THREE.BufferGeometry();
            g.setAttribute('position', new THREE.BufferAttribute(pts, 3));
            return g;
        })();

        this.techCascadeRings = [];
        {
            const mat = new THREE.LineBasicMaterial({
                color: 0x00bbff,
                transparent: true,
                opacity: 0,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
            });
            const loop = new THREE.LineLoop(cascadeRingGeo, mat);
            loop.position.z = Z_BACK;
            this.techBg.add(loop);
            this.techCascadeRings.push({
                mesh: loop, mat,
                z: Z_BACK,
                speed: 55 + Math.random() * 25, // units/sec — fast traversal
                waiting: false,
                waitTimer: 0,
                waitCooldown: 18 + Math.random() * 14, // 18–32 sec between appearances
            });
        }

        // ── 3. SHOCKWAVE RINGS ────────────────────────────────────────
        // Expanding circles from each vanishing point along the floor plane.
        const shockCirclePts = (() => {
            const N = 64, pts = [];
            for (let i = 0; i < N; i++) {
                const a = (i / N) * Math.PI * 2;
                pts.push(Math.cos(a), 0, Math.sin(a));
            }
            return new Float32Array(pts);
        })();
        const shockCircleGeo = new THREE.BufferGeometry();
        shockCircleGeo.setAttribute('position', new THREE.BufferAttribute(shockCirclePts, 3));

        const SHOCK_DURATION = 6.0;
        const SHOCK_PAUSE    = 8.0;
        const SHOCK_PERIOD   = SHOCK_DURATION + SHOCK_PAUSE;
        this.techShockwaves = [];
        const shockConfigs = [
            { x: 0, y: CF, z: -230, delay: 0 },
            { x: 0, y: CF, z:  230, delay: SHOCK_PERIOD * 0.50 },
        ];
        for (const cfg of shockConfigs) {
            const mat = new THREE.LineBasicMaterial({
                color: 0x003d66,
                transparent: true,
                opacity: 0,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
            });
            const loop = new THREE.LineLoop(shockCircleGeo, mat);
            loop.position.set(cfg.x, cfg.y, cfg.z);
            loop.scale.set(0.01, 1, 0.01);
            this.techBg.add(loop);
            this.techShockwaves.push({
                mesh: loop, mat,
                timer: -cfg.delay,
                duration: SHOCK_DURATION,
                pause: SHOCK_PAUSE,
                maxR: CW,
            });
        }

        // ── 4. FLOATING PANEL FLICKER OVERLAYS ───────────────────────
        // Per-panel bright overlay meshes that flash randomly.
        this.techPanelFlickers = [];
        for (const [x, y, z, w, h] of [...floatingPanels, ...floatingPanelsBack]) {
            const overPts = [
                x,   y,   z,  x+w, y,   z,
                x+w, y,   z,  x+w, y-h, z,
                x+w, y-h, z,  x,   y-h, z,
                x,   y-h, z,  x,   y,   z,
                x,   y-3, z,  x+w, y-3, z,
            ];
            const geo = new LineSegmentsGeometry();
            geo.setPositions(overPts);
            const mat = new LineMaterial({
                color: 0x00ccdd,
                linewidth: 1.6,
                resolution: res,
                transparent: true,
                opacity: 0,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
            });
            this.techBgLineMats.push(mat);
            this.techBg.add(new LineSegments2(geo, mat));
            this.techPanelFlickers.push({
                mat,
                timer: Math.random() * 20,
                cooldown: 12 + Math.random() * 18,
                active: false,
                flickerTimer: 0,
                flickerDuration: 0.15 + Math.random() * 0.30,
            });
        }

        // ── 5. PARTICLE DATA STREAM ──────────────────────────────────
        // Small bright particles drifting along Z in both directions.
        {
            const NUM_P = 70;
            const pPos    = new Float32Array(NUM_P * 3);
            const pSpeeds = new Float32Array(NUM_P);
            const pDirs   = new Float32Array(NUM_P);
            for (let i = 0; i < NUM_P; i++) {
                pPos[i*3]   = (Math.random() * 2 - 1) * (CW * 0.85);
                pPos[i*3+1] = CF + Math.random() * (CT - CF);
                pPos[i*3+2] = Z_FAR + Math.random() * (Z_BACK - Z_FAR);
                pSpeeds[i]  = 35 + Math.random() * 45;
                pDirs[i]    = Math.random() > 0.5 ? 1 : -1;
            }
            const pGeo = new THREE.BufferGeometry();
            pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
            const pMat = new THREE.PointsMaterial({
                color: 0x00aaff,
                size: 0.7,
                transparent: true,
                opacity: 0.18,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
                sizeAttenuation: true,
            });
            this.techBg.add(new THREE.Points(pGeo, pMat));
            this.techParticles = {
                geo: pGeo, pos: pPos, speeds: pSpeeds, dirs: pDirs,
                count: NUM_P, zBack: Z_BACK, zFar: Z_FAR,
            };
        }

        // ── 6. PCB NODE SPARKS ──────────────────────────────────────
        // Brief green-white flash sprites at trace pad locations.
        const sparkTex = (() => {
            const c = document.createElement('canvas');
            c.width = c.height = 32;
            const cx = c.getContext('2d');
            const g = cx.createRadialGradient(16, 16, 0, 16, 16, 16);
            g.addColorStop(0,   'rgba(210, 255, 230, 1.0)');
            g.addColorStop(0.4, 'rgba(60,  200, 120, 0.5)');
            g.addColorStop(1,   'rgba(0,    50,  20,  0.0)');
            cx.fillStyle = g;
            cx.fillRect(0, 0, 32, 32);
            return new THREE.CanvasTexture(c);
        })();

        this.techSparks = [];
        for (const [wx, wy, wz] of [...tracePads, ...tracePadsBack]) {
            const inner = wx > 0 ? wx - 18 : wx + 18;
            const mat = new THREE.SpriteMaterial({
                map: sparkTex,
                blending: THREE.AdditiveBlending,
                transparent: true,
                depthWrite: false,
            });
            const sprite = new THREE.Sprite(mat);
            sprite.scale.set(0, 0, 1);
            sprite.position.set(inner, wy + 11, wz);
            this.techBg.add(sprite);
            this.techSparks.push({
                sprite,
                timer: Math.random() * 4,
                cooldown: 2 + Math.random() * 4,
                active: false,
                activeTimer: 0,
                activeDuration: 0.2 + Math.random() * 0.4,
            });
        }

        this.techBg.visible = false;
        this.bgScene.add(this.techBg);
    }

    updateTechBackground(dt) {
        if (!this.techBg || !this.techBg.visible) return;
        const t = performance.now() / 1000;

        // ── Vanishing-point glow pulse ─────────────────────────────────
        const pulse  = 1.0 + 0.18 * Math.sin(t * 0.7);
        const pulse2 = 1.0 + 0.18 * Math.sin(t * 0.7 + 1.2);
        if (this.techGlowSprite)  this.techGlowSprite.scale.set(90 * pulse,  90 * pulse,  1);
        if (this.techGlowSprite2) this.techGlowSprite2.scale.set(90 * pulse2, 90 * pulse2, 1);

        // ── 1. Energy pulses ───────────────────────────────────────────
        if (this.techPulses) {
            for (const p of this.techPulses) {
                p.t += p.speed * dt;
                if (p.t > 1) p.t -= 1;
                p.sprite.position.set(
                    p.ax + (p.bx - p.ax) * p.t,
                    p.ay + (p.by - p.ay) * p.t,
                    p.az + (p.bz - p.az) * p.t,
                );
            }
        }

        // ── 2. Frame ring cascade ──────────────────────────────────────
        if (this.techCascadeRings) {
            for (const r of this.techCascadeRings) {
                if (r.waiting) {
                    r.waitTimer += dt;
                    r.mat.opacity = 0;
                    if (r.waitTimer >= r.waitCooldown) {
                        r.waiting = false;
                        r.z = 230;
                    }
                    continue;
                }
                r.z -= r.speed * dt;
                if (r.z < -230) {
                    r.waiting = true;
                    r.waitTimer = 0;
                    r.waitCooldown = 18 + Math.random() * 14;
                    r.mat.opacity = 0;
                    continue;
                }
                r.mesh.position.z = r.z;
                const norm = (r.z - (-230)) / 460; // 0 at Z_FAR, 1 at Z_BACK
                r.mat.opacity = Math.sin(norm * Math.PI) * 0.28;
            }
        }

        // ── 3. Shockwave rings ─────────────────────────────────────────
        if (this.techShockwaves) {
            for (const w of this.techShockwaves) {
                w.timer += dt;
                if (w.timer < 0) {
                    w.mat.opacity = 0;
                    continue;
                }
                const norm = w.timer / w.duration;
                if (norm >= 1) {
                    w.timer = -w.pause;
                    w.mat.opacity = 0;
                    w.mesh.scale.set(0.01, 1, 0.01);
                    continue;
                }
                w.mesh.scale.set(norm * w.maxR, 1, norm * w.maxR);
                w.mat.opacity = Math.sin(norm * Math.PI) * 0.18;
            }
        }

        // ── 4. Floating panel flickers ─────────────────────────────────
        if (this.techPanelFlickers) {
            for (const pf of this.techPanelFlickers) {
                if (!pf.active) {
                    pf.timer += dt;
                    if (pf.timer >= pf.cooldown) {
                        pf.active = true;
                        pf.flickerTimer = 0;
                        pf.timer = 0;
                    }
                } else {
                    pf.flickerTimer += dt;
                    const ft = pf.flickerTimer / pf.flickerDuration;
                    if (ft >= 1) {
                        pf.active = false;
                        pf.mat.opacity = 0;
                        pf.cooldown = 12 + Math.random() * 18;
                    } else {
                        pf.mat.opacity = Math.sin(ft * Math.PI) * 0.5;
                    }
                }
            }
        }

        // ── 5. Particle data stream ────────────────────────────────────
        if (this.techParticles) {
            const { geo, pos, speeds, dirs, count, zBack, zFar } = this.techParticles;
            for (let i = 0; i < count; i++) {
                pos[i*3+2] += dirs[i] * speeds[i] * dt;
                if (pos[i*3+2] > zBack) pos[i*3+2] = zFar;
                if (pos[i*3+2] < zFar)  pos[i*3+2] = zBack;
            }
            geo.attributes.position.needsUpdate = true;
        }

        // ── 6. PCB node sparks ─────────────────────────────────────────
        if (this.techSparks) {
            for (const s of this.techSparks) {
                if (!s.active) {
                    s.timer += dt;
                    if (s.timer >= s.cooldown) {
                        s.active = true;
                        s.activeTimer = 0;
                        s.timer = 0;
                    }
                } else {
                    s.activeTimer += dt;
                    const st = s.activeTimer / s.activeDuration;
                    if (st >= 1) {
                        s.active = false;
                        s.sprite.scale.set(0, 0, 1);
                        s.cooldown = 2 + Math.random() * 4;
                    } else {
                        const sz = Math.sin(st * Math.PI) * 2.5;
                        s.sprite.scale.set(sz, sz, 1);
                    }
                }
            }
        }
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
        this.bgScene.add(this.ufo);
    }

    updateUFO(dt) {
        if (!this.ufo || !this.currentTheme.animations?.ufo) return;
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
        this.bgScene.add(this.comet);
    }

    updateComet(dt) {
        if (!this.comet || !this.currentTheme.animations?.comet) return;
        
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
        this.bgScene.add(this.rocket);
    }

    updateRocket(dt) {
        if (!this.rocket || !this.currentTheme.animations?.rocket) return;

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
            const response = await fetch('api/apikey.php');
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
                this._portalFadeInMultiplier = 0; // Keep invisible initially
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
            this.updateBackButtonVisibility();
            this.updateConstellationUI(window.TELARIS_CONSTELLATION_ID ?? 0);
            
            // Show BEGIN button instead of the loading torus
            this.showBeginButton();
        }
    }

    showBeginButton() {
        const loadingOverlay = document.getElementById('loading-overlay');
        const loadingTorus = loadingOverlay?.querySelector('.loading-torus');
        const loadingText = loadingOverlay?.querySelector('.loading-text');
        const beginBtn = document.getElementById('begin-button');
        
        if (!loadingOverlay || !beginBtn) return;

        // Add 'ready' class to trigger CSS (glowing torus, smaller button spacing)
        loadingOverlay.classList.add('ready');

        // Hide text, show button
        if (loadingText) loadingText.style.display = 'none';
        beginBtn.style.display = 'block';

        // Click anywhere on the overlay to start
        loadingOverlay.addEventListener('click', async () => {
            // Trigger soundscape start
            if (window.TelarisSoundscape && !window._telarisSoundscapeInstance) {
                try {
                    const soundscape = new window.TelarisSoundscape({ volume: 0.65, fadeTime: 4.0 });
                    window._telarisSoundscapeInstance = soundscape;
                    await soundscape.start();
                } catch (e) {
                    console.warn('Failed to start soundscape:', e);
                }
            }

            // Fade out the entire loading overlay
            loadingOverlay.style.transition = 'opacity 1s ease';
            loadingOverlay.style.opacity = '0';
            
            // Fade in nodes
            const startTime = performance.now();
            const duration = 2000;
            const animateFadeIn = (now) => {
                const t = Math.min((now - startTime) / duration, 1);
                this._portalFadeInMultiplier = t;
                if (t < 1) {
                    requestAnimationFrame(animateFadeIn);
                } else {
                    this._portalFadeInMultiplier = undefined;
                    loadingOverlay.style.display = 'none';
                }
            };
            requestAnimationFrame(animateFadeIn);
        }, { once: true });
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
        try {
            const response = await apiFetch('api/constellations.php');
            if (!response.ok) return null;
            const list = await response.json();
            const c = Array.isArray(list) ? list.find(x => x.id === constellationId) : null;
            if (c) {
                document.title = c.name || document.title;
                if (titleEl) titleEl.textContent = c.name || '';
                if (taglineEl) taglineEl.textContent = c.tagline || '';
                return c;
            }
        } catch (err) {
        }
        return null;
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
        
        // 1. Fetch constellation info to get the theme FIRST
        const constellationInfo = await this.updateConstellationUI(constellationId);
        const themeId = constellationInfo?.theme || 'cosmic';
        this.currentTheme = getTheme(themeId);
        this.setupTheme(this.currentTheme);

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
        });

        this.renderer.domElement.addEventListener('mouseleave', () => {
            this.markInteraction();
            this.networkManager.setFocusedNode(null);
            if (this.mainTooltipNodeTimeout) {
                clearTimeout(this.mainTooltipNodeTimeout);
                this.mainTooltipNodeTimeout = null;
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
                this.playGlitch();
                
                const data = targetNode.userData;

                if (data.node_type === 'portal') {
                    if (data.target_constellation_id != null) {
                        event.preventDefault();
                        event.stopPropagation();

                        const hasDesc = !!(data.description && data.description.trim() !== '');
                        if (hasDesc) {
                            this.showRichMediaWindow(targetNode);
                        } else {
                            if (window.telarisApp) {
                                window.telarisApp.startPortalRev(targetNode, data.target_constellation_id);
                            } else {
                                window.location.href = `index.php?constellation_id=${data.target_constellation_id}`;
                            }
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
                            const hasDesc = !!(nodeData.description && nodeData.description.trim() !== '');
                            if (hasDesc) {
                                this.showRichMediaWindow(touchStartNode);
                            } else {
                                this.startPortalRev(touchStartNode, nodeData.target_constellation_id);
                            }
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
                        this.playGlitch();
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

                this.tooltip.textContent = '';

                const nameDiv = document.createElement('div');
                nameDiv.style.cssText = 'font-weight:600; margin-bottom: 2px;';
                nameDiv.textContent = node.userData.name;
                this.tooltip.appendChild(nameDiv);

                if (node.userData.keywords?.length > 0) {
                    const kwDiv = document.createElement('div');
                    kwDiv.style.cssText = 'opacity: 0.8; font-size: 0.75rem; display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px;';
                    node.userData.keywords.forEach(kw => {
                        const span = document.createElement('span');
                        span.style.cssText = 'background: rgba(255,255,255,0.15); padding: 1px 4px; border-radius: 2px;';
                        span.textContent = '#' + kw;
                        kwDiv.appendChild(span);
                    });
                    this.tooltip.appendChild(kwDiv);
                }

                // Interaction hint
                const hasMedia = !!(node.userData.image_url || node.userData.embed_code || node.userData.audio_url);
                const hasDesc = !!(node.userData.description && node.userData.description.trim() !== '');
                const isPortal = node.userData.node_type === 'portal';

                if (node.userData.url || hasMedia || hasDesc || isPortal) {
                    const hintText = ('ontouchstart' in window || navigator.maxTouchPoints > 0)
                        ? (window.TELARIS_TAP_TO_VIEW || 'Tap again to view')
                        : (window.TELARIS_CLICK_TO_VIEW || 'Click to view');
                    const hintDiv = document.createElement('div');
                    hintDiv.style.cssText = 'opacity: 0.5; font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.05em; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 4px; margin-top: 4px; text-align: center;';
                    hintDiv.textContent = hintText;
                    this.tooltip.appendChild(hintDiv);
                }

                const styles = this.getNodeTooltipStyles(node);
                Object.assign(this.tooltip.style, {
                    backgroundColor: styles.backgroundColor,
                    color: styles.color,
                    backdropFilter: 'blur(8px)',
                    webkitBackdropFilter: 'blur(8px)',
                    visibility: 'visible',
                    display: 'block',
                    opacity: '0',
                    zIndex: '200',
                    border: 'none',
                    maxWidth: 'none',
                    paddingBottom: '8px'
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
                    transform: 'translate(-50%, -100%) translate(0, -20px)'
                });
                requestAnimationFrame(() => requestAnimationFrame(() => { this.tooltip.style.opacity = '1'; }));
            }
        };
    }

    createNodes(nodeData) {
        // Clear previous nodes and connections from scene and arrays
        this.nodes.forEach(n => this.scene.remove(n));
        this.connections.forEach(c => this.scene.remove(c.mesh));
        this.nodes = [];
        this.connections = [];

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
                    video_url: data.video_url,
                    video_autoplay: !!data.video_autoplay,
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
                    metalness: 0.3, roughness: 0.7, transparent: true, opacity: 1.0
                });
                const mesh = createNodeIcon(material, i, this.geometryManager, node.node_type, this.currentTheme.id);
                mesh.visible = true;
                mesh.position.copy(pos);
                mesh.renderOrder = 100; // Force nodes to stay in front of lines

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

                // Random celestial event: 10% chance of a satellite moon (only if theme allows)
                if (this.currentTheme.animations?.satellites && Math.random() < 0.1) {
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
                        // Ensure isSpriteMaterial is properly flagged if the parent is a Sprite or if the material itself is already tagged
                        mats.forEach(m => {
                            if (c.isSprite || m.isSpriteMaterial) m.isSpriteMaterial = true;
                        });
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
                        depthTest: true
                    });
                    
                    const mesh = new THREE.Mesh(geometry, material);
                    mesh.visible = false; // Start invisible
                    mesh.renderOrder = 50; // Render after nebulas, before nodes (100)
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
                if (this.currentTheme.animations?.stationRing && count >= 5) {
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

            if (this.currentTheme.animations?.stationRing && centerpiece) {
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

    updateNodes(dt) {
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
                        mats.forEach(m => { m.opacity = 0; });
                        child.visible = false;
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
            const isVisibleActive = n.visible && (isActive || this.persistentTooltipNodeToDiv.has(n));
            let baseOpacity = isVisibleActive ? 1 : (n.visible ? 0.94 : 0);
            let opacity = baseOpacity;
            
            if (this._portalFadeInMultiplier !== undefined && this._portalFadeInMultiplier !== null) {
                opacity = baseOpacity * this._portalFadeInMultiplier;
            }

            const isTransitioning = this._portalFadeInMultiplier !== undefined && this._portalFadeInMultiplier !== null;

            const forceInvisible = (this._portalFadeInMultiplier === 0);

            // 1. Theme-specific animation logic
            let glitchOffset = this._scratchVec2.set(0, 0, 0);
            let glitchScaleMult = 1.0;
            let glitchOpacityMult = 1.0;
            let glitchRotation = 0;

            if (!isTransitioning) {
                // ── Universal node animations (all themes) ──────────────
                if (d.animGlitchTimer === undefined) {
                    d.animGlitchTimer  = 15 + Math.random() * 20;
                    d.animGlitchActive = 0;
                    d.animFloatOffset  = Math.random() * Math.PI * 2;
                    d.animBlinkTimer   = 25 + Math.random() * 30;
                    d.animBlinkActive  = 0;
                }

                // 1. DRIFT — Lissajous float around anchor
                glitchOffset.add(this._scratchVec.set(
                    Math.sin(time * 0.37 + d.animFloatOffset) * 0.28,
                    Math.cos(time * 0.51 + d.animFloatOffset * 1.3) * 0.28,
                    0
                ));

                // 2. GLITCH — brief random jitter/flicker episodes
                d.animGlitchTimer -= dt;
                if (d.animGlitchTimer <= 0) {
                    d.animGlitchActive = 0.12 + Math.random() * 0.2;
                    d.animGlitchTimer  = 15 + Math.random() * 25;
                }
                if (d.animGlitchActive > 0) {
                    d.animGlitchActive -= dt;
                    if (Math.random() < 0.5) {
                        glitchOffset.add(this._scratchVec.set(
                            (Math.random() - 0.5) * 0.35,
                            (Math.random() - 0.5) * 0.35,
                            0
                        ));
                        glitchScaleMult  *= 0.6 + Math.random() * 0.8;
                        glitchOpacityMult *= 0.3 + Math.random() * 0.7;
                        glitchRotation    = (Math.random() - 0.5) * 1.8;
                    }
                }

                // 3. BLINK — brief flash-off (transmission dropout)
                d.animBlinkTimer -= dt;
                if (d.animBlinkTimer <= 0 && d.animBlinkActive <= 0) {
                    d.animBlinkActive = 0.06 + Math.random() * 0.1;
                    d.animBlinkTimer  = 25 + Math.random() * 30;
                }
                if (d.animBlinkActive > 0) {
                    d.animBlinkActive -= dt;
                    glitchOpacityMult = 0;
                }
            }

            // Optimization: iterate cached materials directly
            d.cachedMaterials.forEach(m => {
                m.opacity = opacity * glitchOpacityMult;
                m.transparent = true;
                m.visible = true;
                
                // Only update color for non-sprite materials (standard geometry nodes)
                if (d.colorR !== undefined && !m.isSpriteMaterial) {
                    if (m.color) m.color.setRGB((d.colorR / 255) * brightness, (d.colorG / 255) * brightness, (d.colorB / 255) * brightness);
                    if (m.emissive && m.color) m.emissive.copy(m.color);
                    
                    if (m.emissiveIntensity !== undefined) {
                        if (m._baseEmissiveIntensity === undefined) {
                            m._baseEmissiveIntensity = m.emissiveIntensity || 0.5;
                        }
                        
                        // Disable twinkle and hover effects during transition for stability
                        if (isTransitioning) {
                            m.emissiveIntensity = m._baseEmissiveIntensity * brightness;
                        } else {
                            const twinkleFreq = d.is_accentuated ? 3.0 : 2.5;
                            const twinkleAmp = d.is_accentuated ? 0.8 : 0.5;
                            const twinkle = 1.0 + Math.sin(time * twinkleFreq + d.phase) * twinkleAmp;
                            
                            // Dim the node when it is active (tooltip is shown) to improve readability
                            const hoverDim = isActive ? 0.15 : 1.0;
                            let flareBoost = 1.0;
                            if (d.solarFlare > 0) {
                                flareBoost = 8.0 * (d.solarFlare / 15);
                                if (m === d.cachedMaterials[0]) d.solarFlare--; // Only decrement once per node
                            }
                            // Accentuated nodes get a smaller emissive boost now
                            const accentBoost = d.is_accentuated ? 1.4 : 1.0;
                            m.emissiveIntensity = m._baseEmissiveIntensity * brightness * hoverDim * twinkle * flareBoost * accentBoost;
                        }
                    }
                } else if (m.isSpriteMaterial) {
                    // Sprites in Abstract theme can have random rotation jumps
                    // SKIP if this is a portal, as portals use 3D rotation logic in animate()
                    if (n.userData.node_type !== 'portal') {
                        if (glitchRotation !== 0) {
                            m.rotation = glitchRotation;
                        } else {
                            // Varied continuous rotation (some clockwise, some counter-clockwise)
                            const rotDir = (d.phase % 2 > 1) ? 1 : -1;
                            m.rotation = time * (0.15 + (d.phase % 0.2)) * rotDir;
                        }
                    }
                }
            });

            // Ensure the main object and all its children are visible if search matches
            n.visible = matchesSearch && !forceInvisible; 
            n.traverse(child => {
                if (child.material) child.visible = matchesSearch && !forceInvisible;
            });

            n.position.copy(d.originalPosition).add(glitchOffset);
            
            // Stable scale during transition, dynamic pulse otherwise
            if (isTransitioning) {
                const baseS = d.is_accentuated ? 2.8 : 1.8;
                n.scale.set(baseS, baseS, baseS);
            } else {
                const pulseFreq = d.is_accentuated ? 2.0 : 1.5;
                const pulseAmp = d.is_accentuated ? 0.15 : 0.08;
                const baseS = d.is_accentuated ? 2.8 : 1.8;
                const s = (baseS + Math.sin(time * pulseFreq + d.phase) * pulseAmp) * glitchScaleMult;
                n.scale.set(s, s, s);
            }

            // Optimization: iterate cached moons directly
            d.cachedMoons.forEach(moonGroup => {
                moonGroup.rotation.y = time * moonGroup.userData.speed;
                moonGroup.rotation.z = time * (moonGroup.userData.speed * 0.3);
            });

            // 5. IDLE SPIN-UP — cosmic geometry nodes only
            if (this.currentTheme.id === 'cosmic' && !n.isPortal && !isTransitioning) {
                if (d.animSpinTimer === undefined) {
                    d.animSpinTimer  = 15 + Math.random() * 25;
                    d.animSpinActive = 0;
                    n.rotation.y = Math.random() * Math.PI * 2;
                }
                n.rotation.y += 0.15 * dt;
                n.rotation.x += 0.05 * dt;
                d.animSpinTimer -= dt;
                if (d.animSpinTimer <= 0) {
                    d.animSpinActive = 0.3 + Math.random() * 0.5;
                    d.animSpinTimer  = 18 + Math.random() * 22;
                }
                if (d.animSpinActive > 0) {
                    d.animSpinActive -= dt;
                    n.rotation.y += 0.15 * (1 + (d.animSpinActive / 0.5) * 10) * dt;
                }
            }

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

    updateMainTooltip() {
        if (!this.tooltip || this.tooltip.style.visibility !== 'visible') return;
        
        const focused = this.networkManager.getFocusedNode();
        if (!focused) return;

        const rect = this.renderer.domElement.getBoundingClientRect();
        focused.getWorldPosition(this._scratchVec);
        const dist = this._scratchVec.distanceTo(this.camera.position);
        this._scratchVec.project(this.camera);

        if (this._scratchVec.z > 1 || this._scratchVec.z < -1) {
            this.tooltip.style.opacity = '0';
            return;
        }

        const tooltipYOffset = 34 + Math.max(0, (18 - dist) * 1.5);
        const x = (this._scratchVec.x * 0.5 + 0.5) * rect.width;
        const y = (0.5 - this._scratchVec.y * 0.5) * rect.height + tooltipYOffset;

        this.tooltip.style.left = x + 'px';
        this.tooltip.style.top = y + 'px';
    }

    updateHoverState() {
        this.raycaster.setFromCamera(this.mouse, this.camera);
        const intersects = this.raycaster.intersectObjects(this.nodes.filter(n => n.visible), true);
        
        let hoveredNode = null;
        if (intersects.length > 0) {
            intersects.sort((a, b) => a.distance - b.distance);
            for (const hit of intersects) {
                let obj = hit.object;
                while (obj && !this.nodes.includes(obj)) obj = obj.parent;
                if (obj) { hoveredNode = obj; break; }
            }
        }

        const currentFocused = this.networkManager.getFocusedNode();

        if (hoveredNode && hoveredNode.userData) {
            if (this.mainTooltipNodeTimeout) {
                clearTimeout(this.mainTooltipNodeTimeout);
                this.mainTooltipNodeTimeout = null;
            }

            // ONLY update if it's a NEW node
            if (currentFocused !== hoveredNode) {
                this.networkManager.setFocusedNode(hoveredNode);
                
                const isPortal = hoveredNode && hoveredNode.userData.node_type === 'portal' && hoveredNode.userData.target_constellation_id != null;
                const isObjectWithLink = hoveredNode.userData.node_type === 'object' && hoveredNode.userData.url;
                
                this.renderer.domElement.style.cursor = (isPortal || isObjectWithLink) ? 'pointer' : 'default';

                if (this.tooltip && hoveredNode.userData.name) {
                    if (this.tooltipHideTimeout) {
                        clearTimeout(this.tooltipHideTimeout);
                        this.tooltipHideTimeout = null;
                    }

                    this.tooltip.textContent = '';

                    const nameDiv = document.createElement('div');
                    nameDiv.style.cssText = 'font-weight:600; margin-bottom: 2px;';
                    nameDiv.textContent = hoveredNode.userData.name;
                    this.tooltip.appendChild(nameDiv);

                    if (hoveredNode.userData.keywords?.length > 0) {
                        const kwDiv = document.createElement('div');
                        kwDiv.style.cssText = 'opacity: 0.8; font-size: 0.75rem; display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px;';
                        hoveredNode.userData.keywords.forEach(kw => {
                            const span = document.createElement('span');
                            span.style.cssText = 'background: rgba(255,255,255,0.15); padding: 1px 4px; border-radius: 2px;';
                            span.textContent = '#' + kw;
                            kwDiv.appendChild(span);
                        });
                        this.tooltip.appendChild(kwDiv);
                    }

                    // Interaction hint
                    const hasMedia = !!(hoveredNode.userData.image_url || hoveredNode.userData.embed_code || hoveredNode.userData.audio_url);
                    const hasDesc = !!(hoveredNode.userData.description && hoveredNode.userData.description.trim() !== '');
                    const isPortalNode = hoveredNode.userData.node_type === 'portal';

                    if (hoveredNode.userData.url || hasMedia || hasDesc || isPortalNode) {
                        const hintText = ('ontouchstart' in window || navigator.maxTouchPoints > 0)
                            ? (window.TELARIS_TAP_TO_VIEW || 'Tap again to view')
                            : (window.TELARIS_CLICK_TO_VIEW || 'Click to view');
                        const hintDiv = document.createElement('div');
                        hintDiv.style.cssText = 'opacity: 0.5; font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.05em; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 4px; margin-top: 4px; text-align: center;';
                        hintDiv.textContent = hintText;
                        this.tooltip.appendChild(hintDiv);
                    }

                                        const styles = this.getNodeTooltipStyles(hoveredNode);
                                        Object.assign(this.tooltip.style, {
                                            backgroundColor: styles.backgroundColor,
                                            color: styles.color,
                    
                            backdropFilter: 'blur(8px)',
                            webkitBackdropFilter: 'blur(8px)',
                            visibility: 'visible',
                            display: 'block',
                            opacity: '0',
                            zIndex: '200',
                            border: 'none',
                            maxWidth: 'none',
                            paddingBottom: '8px',
                            transform: 'translate(-50%, -100%) translate(0, -10px) scale(0.95)'
                        });                    
                        
                        const rect = this.renderer.domElement.getBoundingClientRect();
                        const projected = new THREE.Vector3();
                        hoveredNode.getWorldPosition(projected);
                        const dist = projected.distanceTo(this.camera.position);
                        projected.project(this.camera);
                        
                        const tooltipYOffset = 34 + Math.max(0, (18 - dist) * 1.5);
                        const x = (projected.x * 0.5 + 0.5) * rect.width;
                        const y = (0.5 - projected.y * 0.5) * rect.height + tooltipYOffset;
                        
                        Object.assign(this.tooltip.style, {
                            left: x + 'px',
                            top: y + 'px'
                        });
                        requestAnimationFrame(() => requestAnimationFrame(() => { 
                            this.tooltip.style.opacity = '1'; 
                            this.tooltip.style.transform = 'translate(-50%, -100%) translate(0, -20px) scale(1)';
                        }));
                    }
            }
        } else {
            this.renderer.domElement.style.cursor = 'default';
            this.networkManager.setFocusedNode(null);
            if (this.mainTooltipNodeTimeout) {
                clearTimeout(this.mainTooltipNodeTimeout);
                this.mainTooltipNodeTimeout = null;
            }
            if (this.tooltip) {
                // Immediate hide if no node is hovered
                this.hideMainTooltip();
            }
        }
    }

    scheduleHideTooltip(delayMs = 2000) {
        this.cancelHideTooltip();
        this._hideTooltipTimeout = setTimeout(() => {
            this.hideMainTooltip();
            this._hideTooltipTimeout = null;
        }, delayMs);
    }

    cancelHideTooltip() {
        if (this._hideTooltipTimeout) {
            clearTimeout(this._hideTooltipTimeout);
            this._hideTooltipTimeout = null;
        }
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
            this.updateHoverState();
            this.updateNodes(dt);
            this.updateConnections(dt);
        }
        
        this.updateMainTooltip();
        this.updatePersistentTooltips();
        this.updateComet(dt);
        this.updateRocket(dt);
        this.updateUFO(dt);
        this.updateGlitchyGrid(dt);
        this.updateTechBackground(dt);
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
                // Sprites ignore object.rotation — spin via material.rotation instead
                if (object.isSprite && object.material) {
                    object.material.rotation += 0.006 * revMult;
                } else {
                    object.rotation.y += 0.01 * revMult;
                    object.rotation.z += 0.005 * revMult;
                }
                const pulse = 1 + Math.sin(time * pulseSpeed) * pulseAmp;
                const baseScale = object.userData.baseScale ?? 1;
                object.scale.set(baseScale * pulse, baseScale * pulse, baseScale * pulse);
            }
        });

        if (this.bgComposer) {
            this.bgComposer.render();
        } else {
            this.bgRenderer.render(this.bgScene, this.camera);
        }

        this.renderer.render(this.scene, this.camera);
    }

    updateHUD() {
        // Only update every ~100ms for performance
        const now = performance.now();
        if (this._lastHudUpdate && now - this._lastHudUpdate < 100) return;
        this._lastHudUpdate = now;

        const elNodes = document.getElementById('hud-nodes');
        const elConns = document.getElementById('hud-connections');

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
            const focused = this.networkManager.getFocusedNode();
            if (focused) {
                this.setNodeDimmed(focused, false);
            }
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
        this.bgRenderer.setSize(window.innerWidth, window.innerHeight);
        this.bgComposer?.setSize(window.innerWidth, window.innerHeight);
        this.renderer.setSize(window.innerWidth, window.innerHeight);
        // LineMaterial needs the viewport resolution for correct pixel linewidth
        for (const mat of (this.techBgLineMats || [])) {
            mat.resolution.set(window.innerWidth, window.innerHeight);
        }
    }
}

export { TelarisNetwork };
