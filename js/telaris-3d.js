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
import { CHIP_FG, colorIndexFor } from './keyword-chips.js';
import { createNodeIcon } from './telaris-node-icons.js';
import { renderPdf, clearPdf } from './pdf-viewer.js';
import { NetworkManager } from './network-manager.js';
import { GeometryManager } from './geometry-manager.js';
import { getTheme } from './themes.js';

// Append "&fuzzy=1" to a node/connection API URL when fuzzy keyword matching is
// resolved on for this view (window.TELARIS_FUZZY_KEYWORDS, set by the server in
// inc/bootstrap.php from the installation + per-cluster toggles). When on, the API
// adds keyword_groups[] to each node so the 3D view connects variant keywords.
const fuzzyParam = () => (window.TELARIS_FUZZY_KEYWORDS ? '&fuzzy=1' : '');

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
        this.navigationStack = [{ constellationId: window.TELARIS_CONSTELLATION_ID ?? 0, clusterKey: null }];
        this.networkManager = new NetworkManager({ fadeSpeed: 0.1 });
        this.geometryManager = new GeometryManager();
        this.currentTheme = getTheme(window.TELARIS_THEME_ID || 'window.TELARIS_THEME_ID');
        this._portalFadeInMultiplier = null;
        this.raycaster = new THREE.Raycaster();
        this.mouse = new THREE.Vector2(-Infinity, -Infinity);
        this._mouseHasMoved = false;
        this.tooltip = document.getElementById('node-tooltip');
        this.tooltipLineSvg = document.getElementById('tooltip-line-svg');
        this.tooltipLine = document.getElementById('tooltip-line');
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
        this._scratchVec3 = new THREE.Vector3();
        this._scratchQuat = new THREE.Quaternion();
        this._upVec = new THREE.Vector3(0, 1, 0);
        this._zForward = new THREE.Vector3(0, 0, 1);
        this._originVec = new THREE.Vector3(0, 0, 0);
        this._anchorCache = new Map();

        this.searchQuery = '';
        this.searchFilterIds = null; // when set (Set<number>), only nodes with these ids are visible
        this._searchFlatViewActive = false; // true while a search committed us to a no_cluster flat view
        this.soundEnabled = true;
        window.telarisNetwork = this;
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

        // Store large data in sessionStorage to avoid URL length limits
        const frameKey = 'telaris_frame_' + Date.now();
        try {
            sessionStorage.setItem(frameKey, JSON.stringify({
                url, r, g, b, app, alertMsg,
                nodeName: d.name || window.TELARIS_NODE_NAME_FALLBACK_TEXT || 'window.TELARIS_NODE_NAME_FALLBACK_TEXT',
                description: d.description || '',
                openPortalText: window.TELARIS_OPEN_PORTAL_TEXT || 'window.TELARIS_OPEN_PORTAL_TEXT',
                launchingText: window.TELARIS_LAUNCHING_TEXT || 'window.TELARIS_LAUNCHING_TEXT',
                missionActiveText: window.TELARIS_MISSION_ACTIVE_TEXT || 'window.TELARIS_MISSION_ACTIVE_TEXT',
                goText: window.TELARIS_GO_TEXT || 'window.TELARIS_GO_TEXT',
            }));
        } catch (e) { /* storage full — fall through to URL params */ }

        const frameUrl = '/utils/frame.php?key=' + encodeURIComponent(frameKey) +
            '&url=' + encodeURIComponent(url) +
            '&r=' + r + '&g=' + g + '&b=' + b +
            '&app=' + encodeURIComponent(app) +
            '&node_name=' + encodeURIComponent(d.name || window.TELARIS_NODE_NAME_FALLBACK_TEXT || 'window.TELARIS_NODE_NAME_FALLBACK_TEXT') +
            '&open_portal_text=' + encodeURIComponent(window.TELARIS_OPEN_PORTAL_TEXT || 'window.TELARIS_OPEN_PORTAL_TEXT') +
            '&launching_text=' + encodeURIComponent(window.TELARIS_LAUNCHING_TEXT || 'window.TELARIS_LAUNCHING_TEXT') +
            '&mission_active_text=' + encodeURIComponent(window.TELARIS_MISSION_ACTIVE_TEXT || 'window.TELARIS_MISSION_ACTIVE_TEXT') +
            '&go_text=' + encodeURIComponent(window.TELARIS_GO_TEXT || 'window.TELARIS_GO_TEXT');
        window.open(frameUrl, '_blank');
    }

    /**
     * Try to open a URL: if the node has any local content, show rich media window instead.
     * For nodes with no content at all, open in frame directly.
     */
    smartOpenUrl(node, url) {
        const d = node && node.userData;
        if (d && (d.media_mode === 'hotglue' || d.description || d.image_url || d.video_url || d.pdf_url || d.embed_code || d.audio_url)) {
            this.showRichMediaWindow(node);
        } else {
            this.openInFrame(node, url);
        }
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
        const pdfWrap = document.getElementById('rm-pdf-wrap');
        const pdfPagesEl = document.getElementById('rm-pdf-pages');
        const pdfStatusEl = document.getElementById('rm-pdf-status');
        const pdfDownloadEl = document.getElementById('rm-pdf-download');
        const pdfOpenEl = document.getElementById('rm-pdf-open');
        const urlWrap = document.getElementById('rm-url-wrap');
        const urlButton = document.getElementById('rm-url-button');

        if (!overlay || !win) return;

        // Title. In a multi-galaxy view, show the galaxy name (dimmed) before
        // the wormhole name so it is clear which galaxy the wormhole belongs to.
        if (titleEl) {
            const wormholeName = d.name || window.TELARIS_NODE_NAME_FALLBACK_TEXT || 'window.TELARIS_NODE_NAME_FALLBACK_TEXT';
            const galaxyName = this.galaxyLabelForNode(d);
            titleEl.textContent = '';
            if (galaxyName) {
                const gSpan = document.createElement('span');
                gSpan.textContent = galaxyName;
                gSpan.style.opacity = '0.6';
                titleEl.appendChild(gSpan);
                const sep = document.createElement('span');
                sep.textContent = ' · ';
                sep.style.opacity = '0.5';
                sep.setAttribute('aria-hidden', 'true');
                titleEl.appendChild(sep);
            }
            const nSpan = document.createElement('span');
            nSpan.textContent = wormholeName;
            titleEl.appendChild(nSpan);
        }

        // Description
        if (descriptionEl) {
            descriptionEl.textContent = d.description || '';
            descriptionEl.classList.toggle('hidden', !d.description);
        }

        // Hotglue media mode: show the per-node hotglue page in a sandboxed iframe
        // and skip the classic media blocks entirely. The iframe is sandboxed
        // allow-scripts WITHOUT allow-same-origin, so editor-authored page scripts
        // run in a null origin and cannot reach the visitor session, cookies, or
        // this DOM. The page is requested anonymously (show mode, no auth).
        const hotglueWrap = document.getElementById('rm-hotglue-wrap');
        const hotglueEl = document.getElementById('rm-hotglue');
        const isHotglue = (d.media_mode === 'hotglue');
        if (isHotglue && hotglueWrap && hotglueEl) {
            const hgPage = d.hotglue_page || ('node-' + d.id);
            const hgSrc = '/hg/?' + encodeURIComponent(hgPage);
            if (hotglueEl.getAttribute('src') !== hgSrc) hotglueEl.setAttribute('src', hgSrc);
            hotglueEl.title = d.name || '';
            hotglueWrap.classList.remove('hidden');
            // hide every classic media block and stop any media still playing
            [imageWrap, embedWrap, audioWrap, videoWrap, pdfWrap].forEach(w => { if (w) w.classList.add('hidden'); });
            const cw = document.getElementById('rm-credit-wrap'); if (cw) cw.classList.add('hidden');
            if (embedEl) embedEl.innerHTML = '';
            try { if (audioEl) audioEl.pause(); } catch (e) {}
            try { if (videoEl) videoEl.pause(); } catch (e) {}
            // Single scroll surface: in hotglue mode the iframe (not the window)
            // is the vertical scroll region, so there is no window-around-iframe
            // double scrollbar. See the .rm-hotglue-mode CSS in main-view.php.
            if (win) win.classList.add('rm-hotglue-mode');
        } else if (hotglueWrap && hotglueEl) {
            hotglueWrap.classList.add('hidden');
            hotglueEl.setAttribute('src', 'about:blank');
            if (win) win.classList.remove('rm-hotglue-mode');
        }

        if (!isHotglue) {
        // Image
        if (imageWrap && imageEl) {
            if (d.image_url) {
                imageEl.src = d.image_url;
                imageWrap.classList.remove('hidden');
            } else {
                imageWrap.classList.add('hidden');
            }
        }

        // Shared credit for the active primary visual (image / video / pdf).
        // Stored on nodes.image_attribution; column name is historical.
        const creditWrap = document.getElementById('rm-credit-wrap');
        const creditEl = document.getElementById('rm-credit');
        if (creditWrap && creditEl) {
            const hasVisual = !!(d.image_url || d.video_url || d.pdf_url);
            if (hasVisual && d.image_attribution) {
                creditEl.textContent = d.image_attribution;
                creditWrap.classList.remove('hidden');
            } else {
                creditEl.textContent = '';
                creditWrap.classList.add('hidden');
            }
        }

        // Embed (server sanitizes to iframe-only; double-check client-side).
        // L-third-5 (audit v6.10.13): rather than cloneNode(true) which would
        // carry through every attribute on the source iframe, we construct a
        // fresh iframe in JS and copy only an allowlist of attributes — the
        // same allowlist the server sanitizer trusts. Defence in depth in
        // case the sanitizer ever loosens or an injection bypasses it.
        if (embedWrap && embedEl) {
            if (d.embed_code) {
                const tmp = document.createElement('div');
                tmp.innerHTML = d.embed_code;
                // Only allow <iframe> elements through
                embedEl.innerHTML = '';
                const allowedAttrs = ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy', 'sandbox', 'title'];
                tmp.querySelectorAll('iframe').forEach(srcIframe => {
                    const src = srcIframe.getAttribute('src') || '';
                    if (!src.match(/^https?:\/\//i)) return;
                    const fresh = document.createElement('iframe');
                    allowedAttrs.forEach(attr => {
                        if (srcIframe.hasAttribute(attr)) {
                            fresh.setAttribute(attr, srcIframe.getAttribute(attr));
                        }
                    });
                    embedEl.appendChild(fresh);
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
                audioEl.loop = !!d.audio_loop;
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

        // PDF (rendered by js/pdf-viewer.js, lazy-loaded). The mutex with image+video
        // is enforced server-side, so under normal conditions only one of those wraps
        // is unhidden at a time.
        if (pdfWrap && pdfPagesEl) {
            if (d.pdf_url) {
                pdfWrap.classList.remove('hidden');
                renderPdf({
                    pagesEl: pdfPagesEl,
                    statusEl: pdfStatusEl,
                    downloadEl: pdfDownloadEl,
                    openEl: pdfOpenEl,
                    url: d.pdf_url,
                });
            } else {
                clearPdf({ pagesEl: pdfPagesEl, statusEl: pdfStatusEl });
                pdfWrap.classList.add('hidden');
            }
        }
        } // end if (!isHotglue): classic media blocks

        // Keywords
        const keywordsWrap = document.getElementById('rm-keywords-wrap');
        const keywordsEl = document.getElementById('rm-keywords');
        if (keywordsWrap && keywordsEl) {
            keywordsEl.innerHTML = '';
            if (d.show_keywords && d.keywords && d.keywords.length > 0) {
                const pastelColors = [
                    'rgba(254,202,202,0.25)', 'rgba(254,215,170,0.25)', 'rgba(253,230,138,0.25)',
                    'rgba(254,240,138,0.25)', 'rgba(217,249,157,0.25)', 'rgba(187,247,208,0.25)',
                    'rgba(167,243,208,0.25)', 'rgba(153,246,228,0.25)', 'rgba(165,243,252,0.25)',
                    'rgba(186,230,253,0.25)', 'rgba(191,219,254,0.25)', 'rgba(199,210,254,0.25)',
                    'rgba(221,214,254,0.25)', 'rgba(233,213,255,0.25)', 'rgba(245,208,254,0.25)',
                    'rgba(251,207,232,0.25)', 'rgba(254,205,211,0.25)'
                ];
                const textColors = [
                    '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
                    '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
                    '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af'
                ];
                d.keywords.forEach(kw => {
                    let hash = 0;
                    for (let i = 0; i < kw.length; i++) {
                        hash = kw.charCodeAt(i) + ((hash << 5) - hash);
                    }
                    const idx = Math.abs(hash) % pastelColors.length;
                    const badge = document.createElement('span');
                    badge.style.cssText = `background:${pastelColors[idx]};color:${textColors[idx]};border:1px solid ${textColors[idx]}40;padding:2px 10px;border-radius:9999px;font-size:0.75rem;font-weight:500;`;
                    badge.textContent = `#${kw}`;
                    keywordsEl.appendChild(badge);
                });
                keywordsWrap.classList.remove('hidden');
            } else {
                keywordsWrap.classList.add('hidden');
            }
        }

        // Related wormholes (per-galaxy opt-in via TELARIS_RELATED_NODES_ENABLED).
        this._renderRelatedNodes(node);

        // URL / Action Button
        if (urlWrap && urlButton) {
            if (d.node_type === 'portal' && d.target_constellation_id != null) {
                urlWrap.classList.remove('hidden');
                urlButton.textContent = window.TELARIS_OPEN_PORTAL_TEXT || 'window.TELARIS_OPEN_PORTAL_TEXT';
                urlButton.onclick = () => {
                    this.closeRichMediaWindow();
                    if (window.telarisApp) {
                        window.telarisApp.startPortalRev(node, d.target_constellation_id, null, d.target_constellation_slug);
                    } else {
                        window.location.href = this.constellationUrl(d.target_constellation_id, d.target_constellation_slug);
                    }
                };
            } else if (d.url) {
                urlWrap.classList.remove('hidden');
                urlButton.textContent = `${window.TELARIS_LAUNCH_TEXT || 'window.TELARIS_LAUNCH_TEXT'} ${d.name || 'SYSTEM'}`;
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

        // Apply Color Glow to Window. Pull the pastel from CHIP_FG using the
        // wormhole's name so the info window's accent matches the chip color
        // shown in the 2D grid, the keyword strip, and anywhere else this
        // wormhole appears as a chip. Falls back to the legacy theme color
        // (colorR/G/B) if no name.
        let r = d.colorR || 0, g = d.colorG || 255, b = d.colorB || 204;
        const nameForColor = d.name || ('#' + (d.id || ''));
        if (nameForColor) {
            const pastel = CHIP_FG[colorIndexFor(nameForColor)];
            const p = parseInt(pastel.slice(1), 16);
            r = (p >> 16) & 0xff;
            g = (p >> 8) & 0xff;
            b = p & 0xff;
        }
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

/**
     * Up to 5 wormholes sharing keywords with the current one, drawn from the
     * source galaxy's group (prefix family ∪ tag siblings ∪ cluster co-members
     * ∪ self) — so chips can cross galaxies. The dim set still only covers
     * scene-loaded nodes, since we can't dim wormholes from galaxies that
     * aren't loaded; cross-galaxy chips just navigate to a permalink.
     */
    async _renderRelatedNodes(currentNode) {
        const wrap = document.getElementById('rm-related-wrap');
        const list = document.getElementById('rm-related');
        if (!wrap || !list) return;
        if (!window.TELARIS_RELATED_NODES_ENABLED) {
            wrap.classList.add('hidden');
            this._relatedNodeSet = null;
            return;
        }
        const sourceId = currentNode?.userData?.id;
        if (sourceId == null) {
            wrap.classList.add('hidden');
            this._relatedNodeSet = null;
            return;
        }

        // Cancel-token: if the user opens another card mid-fetch, ignore the
        // older response. Also bumped on closeRichMediaWindow so dangling
        // fetches don't repopulate a closed card.
        this._relatedFetchId = (this._relatedFetchId || 0) + 1;
        const fetchId = this._relatedFetchId;
        let rows = [];
        try {
            const resp = await apiFetch(`/api/nodes.php?related_to=${encodeURIComponent(sourceId)}&limit=5`);
            if (fetchId !== this._relatedFetchId) return;
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            rows = await resp.json();
        } catch (e) {
            wrap.classList.add('hidden');
            this._relatedNodeSet = null;
            return;
        }
        if (!Array.isArray(rows) || rows.length === 0) {
            wrap.classList.add('hidden');
            this._relatedNodeSet = null;
            return;
        }

        // Map currently-loaded scene nodes by ID so we can: (a) reuse the existing
        // fly-to behavior when the target is in the scene, (b) build the dim set
        // for visible nodes only.
        const loadedById = new Map();
        if (Array.isArray(this.nodes)) {
            for (const n of this.nodes) {
                if (n?.userData?.id != null) loadedById.set(Number(n.userData.id), n);
            }
        }
        const allRelated = new Set();
        for (const r of rows) {
            const n = loadedById.get(Number(r.id));
            if (n) allRelated.add(n);
        }
        if (allRelated.size > 0) allRelated.add(currentNode);
        this._relatedNodeSet = allRelated.size > 0 ? allRelated : null;

        // Pastel palette hashed off node name — same scheme used elsewhere in the card.
        const pastelText = [
            '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
            '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
            '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af',
        ];
        const colorFor = (str) => {
            let h = 0;
            for (let i = 0; i < str.length; i++) h = str.charCodeAt(i) + ((h << 5) - h);
            return pastelText[Math.abs(h) % pastelText.length];
        };

        list.innerHTML = '';
        for (const r of rows) {
            const name = r.name || window.TELARIS_UNTITLED_TEXT || 'window.TELARIS_UNTITLED_TEXT';
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.textContent = name;
            chip.title = (window.TELARIS_CHIP_OPEN_PREFIX_TEXT || 'window.TELARIS_CHIP_OPEN_PREFIX_TEXT') + ' ' + name;
            const color = colorFor(name);
            chip.style.cssText = [
                'background:transparent',
                'border:none',
                'padding:0',
                'color:' + color,
                'font-size:0.78rem',
                'font-weight:500',
                'cursor:pointer',
                'transition:opacity 150ms',
                'opacity:0.85',
                'white-space:nowrap',
                'max-width:180px',
                'overflow:hidden',
                'text-overflow:ellipsis',
                'flex-shrink:0',
            ].join(';');
            chip.addEventListener('mouseenter', () => { chip.style.opacity = '1'; chip.style.textDecoration = 'underline'; });
            chip.addEventListener('mouseleave', () => { chip.style.opacity = '0.85'; chip.style.textDecoration = 'none'; });
            chip.addEventListener('click', async () => {
                const localNode = loadedById.get(Number(r.id));
                if (localNode) {
                    // Same scene: existing fly-to behavior (close, beat, spotlight, fly, open).
                    this.closeRichMediaWindow();
                    await new Promise(res => setTimeout(res, 500));
                    this._tourSpotlightNode = localNode;
                    if (this.networkManager?.setFocusedNode) this.networkManager.setFocusedNode(localNode);
                    if (this.tourFocusOnNode) await this.tourFocusOnNode(localNode, 1400);
                    this.showRichMediaWindow(localNode);
                } else {
                    // Cross-galaxy: navigate to permalink in the sibling galaxy.
                    const slug = r.constellation_slug || r.constellation_id;
                    if (slug != null) window.location.href = `/${slug}/${r.id}`;
                }
            });
            list.appendChild(chip);
        }
        wrap.classList.remove('hidden');
    }

    closeRichMediaWindow() {
        this.playGlitch();
        this._relatedNodeSet = null;
        this._tourSpotlightNode = null;
        this._relatedFetchId = (this._relatedFetchId || 0) + 1; // cancel any in-flight related fetch
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
            const pdfPages = document.getElementById('rm-pdf-pages');
            const pdfStatus = document.getElementById('rm-pdf-status');
            const pdfWrapEl = document.getElementById('rm-pdf-wrap');
            if (pdfPages || pdfStatus) clearPdf({ pagesEl: pdfPages, statusEl: pdfStatus });
            if (pdfWrapEl) pdfWrapEl.classList.add('hidden');
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
            toggleBtn.innerText = this.soundEnabled ? (toggleBtn.dataset.on || 'ON') : (toggleBtn.dataset.off || 'OFF');

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
            color.setHSL(0.6, 0.2, 0.4 + Math.random() * 0.2);
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
            opacity: 0.6,
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
                        const colors = [0x223355, 0x332255, 0x3b223b]; 
                
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
        if (this.tooltipLineSvg) this.tooltipLineSvg.style.opacity = '0';
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
            aperture: 0.002,
            maxblur:  0.003,
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
        const size = 80;
        const divisions = 20;
        const half = size / 2;
        const colorCenter = 0x444444;
        const colorGrid   = 0x222222;

        // 6 faces of a cube surrounding the nodes
        // Floor (Y = -half)
        const floor = new THREE.GridHelper(size, divisions, colorCenter, colorGrid);
        floor.position.y = -half;
        this.glitchyGrid.add(floor);

        // Ceiling (Y = +half)
        const ceiling = new THREE.GridHelper(size, divisions, colorCenter, colorGrid);
        ceiling.position.y = half;
        this.glitchyGrid.add(ceiling);

        // Back wall (Z = -half)
        const back = new THREE.GridHelper(size, divisions, colorCenter, colorGrid);
        back.rotation.x = Math.PI / 2;
        back.position.z = -half;
        this.glitchyGrid.add(back);

        // Front wall (Z = +half)
        const front = new THREE.GridHelper(size, divisions, colorCenter, colorGrid);
        front.rotation.x = Math.PI / 2;
        front.position.z = half;
        this.glitchyGrid.add(front);

        // Left wall (X = -half)
        const left = new THREE.GridHelper(size, divisions, colorCenter, colorGrid);
        left.rotation.z = Math.PI / 2;
        left.position.x = -half;
        this.glitchyGrid.add(left);

        // Right wall (X = +half)
        const right = new THREE.GridHelper(size, divisions, colorCenter, colorGrid);
        right.rotation.z = Math.PI / 2;
        right.position.x = half;
        this.glitchyGrid.add(right);

        this.glitchyGrid.visible = !!(this.currentTheme && this.currentTheme.background.grid);
        this.bgScene.add(this.glitchyGrid);
    }

    updateGlitchyGrid(dt) {
        if (!this.glitchyGrid || !this.glitchyGrid.visible) return;

        // Slow rotation of the whole cube
        this.glitchyGrid.rotation.x += dt * 0.008;
        this.glitchyGrid.rotation.y += dt * 0.012;

        // Occasional twitch
        if (Math.random() < 0.01) {
            const twitchX = (Math.random() - 0.5) * 0.5;
            const twitchY = (Math.random() - 0.5) * 0.5;
            this.glitchyGrid.position.set(twitchX, twitchY, 0);

            // Randomly hide/show one face for a frame
            const target = this.glitchyGrid.children[Math.floor(Math.random() * this.glitchyGrid.children.length)];
            target.visible = false;
            setTimeout(() => { target.visible = true; }, 50);
        } else {
            this.glitchyGrid.position.lerp(this._originVec, 0.1);
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
        addThick(ceilLongPts, 0x1a1a1a, 1.1, 1.0);

        // Cross lines (perpendicular to Z, create panel grid sections)
        const ceilCrossPts = [];
        for (let z = Z_BACK; z >= Z_FAR; z -= 16) {
            ceilCrossPts.push(-CW, CT, z,  CW, CT, z);
        }
        addThick(ceilCrossPts, 0x1a1a1a, 1.1, 1.0);

        // Inner ceiling layer (lower plane — layered depth like Image 1)
        const ceilInnerPts = [];
        const CI = 18; // inner ceiling Y
        for (let x = -CW; x <= CW; x += 30) {
            ceilInnerPts.push(x, CI, Z_BACK,  x, CI, Z_FAR);
        }
        for (let z = Z_BACK; z >= Z_FAR; z -= 30) {
            ceilInnerPts.push(-CW, CI, z,  CW, CI, z);
        }
        addThick(ceilInnerPts, 0x0f0f0f, 0.7, 1.0);

        // X-brace diagonals within ceiling panels (glass-panel cross pattern)
        const ceilDiagPts = [];
        for (let z = Z_BACK - 16; z >= Z_FAR + 16; z -= 32) {
            for (let x = -CW; x < CW; x += 30) {
                const x2 = x + 30;
                ceilDiagPts.push(x, CT, z,    x2, CT, z - 16);
                ceilDiagPts.push(x2, CT, z,   x,  CT, z - 16);
            }
        }
        addThin(ceilDiagPts, 0x0f0f0f, 1.0);

        // ── FLOOR — mirrors ceiling grid pattern ─
        const floorLongPts = [];
        for (let x = -CW; x <= CW; x += 15) {
            floorLongPts.push(x, CF, Z_BACK,  x, CF, Z_FAR);
        }
        addThick(floorLongPts, 0x1a1a1a, 1.1, 1.0);

        const floorCrossPts = [];
        for (let z = Z_BACK; z >= Z_FAR; z -= 16) {
            floorCrossPts.push(-CW, CF, z,  CW, CF, z);
        }
        addThick(floorCrossPts, 0x1a1a1a, 1.1, 1.0);

        // Inner floor layer (matches inner ceiling)
        const floorInnerPts = [];
        const FI = CF + 12; // inner floor Y (above floor)
        for (let x = -CW; x <= CW; x += 30) {
            floorInnerPts.push(x, FI, Z_BACK,  x, FI, Z_FAR);
        }
        for (let z = Z_BACK; z >= Z_FAR; z -= 30) {
            floorInnerPts.push(-CW, FI, z,  CW, FI, z);
        }
        addThick(floorInnerPts, 0x0f0f0f, 0.7, 1.0);

        // X-brace diagonals within floor panels
        const floorDiagPts = [];
        for (let z = Z_BACK - 16; z >= Z_FAR + 16; z -= 32) {
            for (let x = -CW; x < CW; x += 30) {
                const x2 = x + 30;
                floorDiagPts.push(x, CF, z,    x2, CF, z - 16);
                floorDiagPts.push(x2, CF, z,   x,  CF, z - 16);
            }
        }
        addThin(floorDiagPts, 0x0f0f0f, 1.0);

        // ── SIDE WALLS — full grid matching ceiling/floor ─────────────
        const wallPts = [];
        // Horizontal runs along Z (one per Y step, matching ceiling spacing)
        for (let y = CF; y <= CT; y += 15) {
            wallPts.push(-CW, y, Z_BACK,  -CW, y, Z_FAR);
            wallPts.push( CW, y, Z_BACK,   CW, y, Z_FAR);
        }
        // Vertical stanchions at regular Z intervals (matching ceiling cross-line spacing)
        for (let z = Z_BACK; z >= Z_FAR; z -= 16) {
            wallPts.push(-CW, CF, z,  -CW, CT, z);
            wallPts.push( CW, CF, z,   CW, CT, z);
        }
        addThick(wallPts, 0x1a1a1a, 1.1, 1.0);

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
        addThick(framePts, 0x161616, 1.2, 1.0);

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
        addThick(beamPts, 0x0e0e0e, 0.7, 1.0);

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
        addThick(nodePts, 0x0c0c0c, 0.8, 1.0);

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
        addThick(nodePtsBack, 0x0c0c0c, 0.8, 1.0);

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
        addThick(tracePts, 0x0a0a0a, 0.7, 1.0);

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
        addThick(tracePtsBack, 0x0a0a0a, 0.7, 1.0);

        // ── CENTRAL VANISHING POINT GLOW ───────────────────────────────
        const glowCanvas = document.createElement('canvas');
        glowCanvas.width = glowCanvas.height = 256;
        const ctx = glowCanvas.getContext('2d');
        const grad = ctx.createRadialGradient(128, 128, 0, 128, 128, 128);
        grad.addColorStop(0,    'rgba(140, 140, 140, 0.15)');
        grad.addColorStop(0.08, 'rgba(80,   80,  80, 0.09)');
        grad.addColorStop(0.3,  'rgba(30,   30,  30, 0.04)');
        grad.addColorStop(1,    'rgba(0,     0,   0, 0.0)');
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
        addThick(beamPtsBack, 0x0e0e0e, 0.7, 1.0);

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
            g.addColorStop(0,   'rgba(180, 180, 180, 1.0)');
            g.addColorStop(0.3, 'rgba(80,   80,  80, 0.5)');
            g.addColorStop(1,   'rgba(0,     0,   0, 0.0)');
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
                color: 0x555555,
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
                color: 0x1a1a1a,
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
                color: 0x555555,
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
                color: 0x444444,
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
            g.addColorStop(0,   'rgba(220, 220, 220, 1.0)');
            g.addColorStop(0.4, 'rgba(100, 100, 100, 0.5)');
            g.addColorStop(1,   'rgba(0,     0,   0, 0.0)');
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
            this._scratchVec3.copy(d.departureDir).multiplyScalar(speed * dt);
            this.ufo.position.add(this._scratchVec3);
            
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
        this._scratchVec3.copy(this.comet.userData.target).multiplyScalar(speed * dt);
        this.comet.position.add(this._scratchVec3);

        // Point comet toward travel direction (head first, tail trails behind)
        this.comet.quaternion.setFromUnitVectors(this._zForward, this.comet.userData.target);

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
        this._scratchVec3.subVectors(
            this.rocket.userData.node2.position,
            this.rocket.userData.node1.position
        ).normalize();
        this.rocket.quaternion.setFromUnitVectors(this._zForward, this._scratchVec3);

        if (this.rocket.userData.progress >= 1) {
            this.rocket.userData.active = false;
            this.rocket.visible = false;
        }
    }

    async loadApiKey() {
        try {
            const response = await fetch('/api/apikey.php');
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
            const multiIds = Array.isArray(window.TELARIS_MULTI_GALAXY_IDS) ? window.TELARIS_MULTI_GALAXY_IDS : null;
            const urlParams = new URLSearchParams(window.location.search);
            const initialClusterKey = urlParams.get('cluster') || null;
            this.currentClusterKey = initialClusterKey;
            let fetchUrl = multiIds && multiIds.length
                ? `/api/nodes.php?galaxies=${multiIds.join(',')}${fuzzyParam()}`
                : `/api/nodes.php?constellation_id=${constellationId}${fuzzyParam()}`;
            if (initialClusterKey) fetchUrl += `&cluster=${encodeURIComponent(initialClusterKey)}`;
            const response = await apiFetch(fetchUrl);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            
            const nodesJson = await response.json();
            const nodeData = Array.isArray(nodesJson) ? nodesJson : [];
            if (nodeData.length > 0) {
                // Skip the load fade-in when the page is opening on a specific wormhole
                // (cross-galaxy chip click / direct permalink). The fade animation racing
                // with the auto-open camera fly was leaving nodes stuck invisible —
                // visitors landing on a permalink want to see the network instantly.
                const isPermalinkLoad = !!window.TELARIS_INITIAL_NODE_ID;
                if (!isPermalinkLoad) {
                    this._portalFadeInMultiplier = 0; // Keep invisible initially
                }
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
            if (initialClusterKey) {
                this.navigationStack = [
                    { constellationId, clusterKey: null },
                    { constellationId, clusterKey: initialClusterKey }
                ];
            }
        } catch (error) {
            console.error('Error loading data:', error);
            this.clearAll();
        } finally {
            this.updateBackButtonVisibility();
            this.updateBreadcrumb();
            this.updateConstellationUI(window.TELARIS_CONSTELLATION_ID ?? 0);
            
            // Show BEGIN button instead of the loading torus
            this.showBeginButton();
        }
    }

    showBeginButton() {
        const loadingOverlay = document.getElementById('loading-overlay');
        const loadingText = loadingOverlay?.querySelector('.loading-text');

        if (!loadingOverlay) return;

        // Signal torus to glow briefly before warp
        if (window._loadingTorus) window._loadingTorus.setReady();

        // Hide loading text
        if (loadingText) loadingText.style.display = 'none';

        // Start soundscape on first user click (browser requires gesture for AudioContext)
        if (window.TelarisSoundscape) {
            const startSoundOnGesture = () => {
                if (window._telarisSoundscapeInstance) return;
                try {
                    const soundscape = new window.TelarisSoundscape({ volume: 0.65, fadeTime: 4.0 });
                    window._telarisSoundscapeInstance = soundscape;
                    soundscape.start().catch(e => console.warn('Soundscape start failed:', e));
                } catch (e) {
                    console.warn('Failed to create soundscape:', e);
                }
            };
            document.addEventListener('click', startSoundOnGesture, { once: true });
        }

        // Auto-start: transition immediately
        const autoStart = () => {

            // Start torus warp-through effect, then fade overlay.
            // On permalink loads the network was created opaque (no _portalFadeInMultiplier=0
            // in loadData), so the node fade-in animation must also be skipped — otherwise
            // it'd reset the multiplier to 0 and re-hide everything that was already visible.
            const isPermalinkLoad = !!window.TELARIS_INITIAL_NODE_ID;
            const fadeOutOverlay = () => {
                loadingOverlay.style.transition = 'opacity 0.6s ease';
                loadingOverlay.style.opacity = '0';

                if (isPermalinkLoad) {
                    setTimeout(() => { loadingOverlay.style.display = 'none'; }, 600);
                    return;
                }

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
            };

            if (window._loadingTorus) {
                window._loadingTorus.startWarp(fadeOutOverlay);
            } else {
                fadeOutOverlay();
            }
        };

        autoStart();
    }

    /**
     * True when the visitor arrived via an in-app link (e.g. a cross-galaxy chip click in
     * the info card) and a `history.back()` would land back inside Telaris rather than
     * leaving the site.
     */
    _hasInAppReferrer() {
        try {
            if (!document.referrer) return false;
            const ref = new URL(document.referrer);
            return ref.origin === window.location.origin && ref.href !== window.location.href;
        } catch (e) { return false; }
    }

    setupBackButton() {
        const btn = document.getElementById('portal-back-button');
        if (!btn) return;
        btn.addEventListener('click', () => {
            // No portal/cluster stack but we got here from another Telaris page → use browser history.
            if (this.navigationStack.length <= 1) {
                if (this._hasInAppReferrer() && window.history.length > 1) {
                    window.history.back();
                }
                return;
            }
            this.navigationStack.pop(); // Remove current entry
            const prev = this.navigationStack[this.navigationStack.length - 1]; // Peek at the target
            this.updateBackButtonVisibility();
            this.clearSearch();
            if (typeof prev === 'object' && prev !== null) {
                // New format: { constellationId, clusterKey }
                if (prev.clusterKey) {
                    this.transitionToCluster(prev.constellationId, prev.clusterKey, true);
                } else {
                    this.runBackTransition(prev.constellationId);
                }
            } else {
                // Legacy format (plain ID) — fallback
                this.runBackTransition(prev);
            }
        });
    }

    updateBackButtonVisibility() {
        const btn = document.getElementById('portal-back-button');
        if (!btn) return;
        // Show if there's a portal/cluster stack to pop OR if we arrived from another
        // Telaris page (e.g. cross-galaxy chip click) and can fall back to browser history.
        const inAppReferrer = this._hasInAppReferrer() && window.history.length > 1;
        const visible = this.navigationStack.length > 1 || inAppReferrer;
        btn.style.display = visible ? 'block' : 'none';
    }

    /**
     * Build a browser URL path for a constellation, preferring slug over query param.
     */
    constellationUrl(constellationId, slug = null, clusterKey = null) {
        let base = slug ? `/${slug}` : `/?constellation_id=${constellationId}`;
        if (clusterKey) {
            base += (slug ? '?' : '&') + `cluster=${encodeURIComponent(clusterKey)}`;
        }
        return base;
    }

    async updateConstellationUI(constellationId) {
        const titleEl = document.getElementById('constellation-title');
        const taglineEl = document.getElementById('constellation-tagline');
        try {
            const response = await apiFetch('/api/constellations.php');
            if (!response.ok) return null;
            const list = await response.json();
            const c = Array.isArray(list) ? list.find(x => x.id === constellationId) : null;
            if (c) {
                document.title = c.name || document.title;
                if (titleEl) titleEl.textContent = c.name || '';
                if (taglineEl) taglineEl.textContent = c.tagline || '';
                window.TELARIS_CONSTELLATION_SLUG = c.slug || null;
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
                // Skip geometry disposal — geometries are shared via GeometryManager
                if (c.material) (Array.isArray(c.material) ? c.material : [c.material]).forEach(m => m.dispose());
            });
        });
        this.nodes = [];

        this.connections.forEach(c => {
            this.scene.remove(c.mesh);
            // Connection geometries are also shared via GeometryManager; only dispose materials
            if (c.mesh.material) c.mesh.material.dispose();
        });
        this.connections = [];
        this.networkManager.setFocusedNode(null);
        if (this.tooltip) this.hideMainTooltip();
        // Clear persistent tooltip labels
        if (this.persistentTooltipNodeToDiv) {
            this.persistentTooltipNodeToDiv.forEach(el => { if (el && el.parentNode) el.parentNode.removeChild(el); });
            this.persistentTooltipNodeToDiv.clear();
        }
        if (this.persistentTooltipsContainer) this.persistentTooltipsContainer.innerHTML = '';
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
     * Start 500ms "rev" feedback on the clicked node, then run the transition.
     * Works for both portals and clusters.
     */
    startPortalRev(clickedNode, targetId, clusterKey = null, targetSlug = null) {
        this._revvingPortal = { node: clickedNode, targetId, clusterKey, targetSlug, startTime: performance.now() };
    }

    /**
     * Run portal/cluster transition: move camera toward node over 800ms while fading network to 0,
     * then show loading torus, load data, and fade new network in.
     */
    runPortalTransition(clickedNode, targetId, clusterKey = null, targetSlug = null) {
        const portalPos = new THREE.Vector3();
        clickedNode.getWorldPosition(portalPos);

        // Pre-fetch data immediately
        let fetchUrl = `/api/nodes.php?constellation_id=${targetId}${fuzzyParam()}`;
        if (clusterKey) fetchUrl += `&cluster=${encodeURIComponent(clusterKey)}`;
        const dataPromise = apiFetch(fetchUrl)
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP error! status: ${r.status}`)))
            .then(json => Array.isArray(json) ? json : []);

        if (clusterKey) {
            // Cluster drill-in
            this.navigationStack.push({ constellationId: targetId, clusterKey });
            this.updateBackButtonVisibility();
            this._clusterLoading(targetId, clusterKey, dataPromise);
        } else {
            // Portal transition: zoom + loading torus + fade-in (original flow)
            this._portalTransition = {
                phase: 'camera_fade_out',
                startTime: performance.now(),
                duration: 800,
                cameraEnd: portalPos.clone(),
                targetEnd: portalPos.clone(),
                cameraStart: this.camera.position.clone(),
                targetStart: this.controls.target.clone(),
                targetId,
                targetSlug,
                clusterKey: null,
                dataPromise,
                targetFadeInDuration: 1000
            };
            this.controls.enabled = false;
        }
    }

    /**
     * Create a full-screen loading screen with spinning torus and localized text.
     */
    _showClusterScreen() {
        let screen = document.getElementById('cluster-loading-screen');
        if (!screen) {
            screen = document.createElement('div');
            screen.id = 'cluster-loading-screen';

            // Add CSS animation (only once)
            if (!document.getElementById('cluster-loading-css')) {
                const style = document.createElement('style');
                style.id = 'cluster-loading-css';
                style.textContent = `
                    @keyframes cls-spin { 0% { transform: rotateX(55deg) rotateZ(0deg); } 100% { transform: rotateX(55deg) rotateZ(360deg); } }
                    @keyframes cls-pulse { 0%,100% { opacity: 0.4; } 50% { opacity: 1; } }
                    #cluster-loading-screen .cls-torus {
                        width: 80px; height: 80px; border-radius: 50%;
                        border: 4px solid rgba(0,255,204,0.15);
                        border-top-color: rgba(0,255,204,0.8);
                        border-right-color: rgba(0,255,204,0.4);
                        animation: cls-spin 1.2s linear infinite;
                        box-shadow: 0 0 25px rgba(0,255,204,0.2), inset 0 0 25px rgba(0,255,204,0.05);
                        margin-bottom: 24px;
                    }
                    #cluster-loading-screen .cls-text {
                        color: rgba(255,255,255,0.5); font-family: var(--font-mono);
                        font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.15em;
                        animation: cls-pulse 2s ease-in-out infinite;
                    }
                `;
                document.head.appendChild(style);
            }

            const torus = document.createElement('div');
            torus.className = 'cls-torus';
            const text = document.createElement('p');
            text.className = 'cls-text';
            text.textContent = window.TELARIS_LOADING_TEXT || 'window.TELARIS_LOADING_TEXT';
            screen.appendChild(torus);
            screen.appendChild(text);
            Object.assign(screen.style, {
                position: 'fixed', inset: '0', zIndex: '9999',
                background: '#000', display: 'flex', flexDirection: 'column',
                alignItems: 'center', justifyContent: 'center',
                opacity: '1', pointerEvents: 'auto'
            });
            document.body.appendChild(screen);
        } else {
            screen.style.display = 'flex';
            screen.style.opacity = '1';
            screen.style.pointerEvents = 'auto';
            screen.style.transition = 'none';
        }
        return screen;
    }

    _hideClusterScreen() {
        const screen = document.getElementById('cluster-loading-screen');
        if (screen) {
            screen.style.transition = 'opacity 0.5s ease';
            screen.style.opacity = '0';
            screen.style.pointerEvents = 'none';
            setTimeout(() => { screen.style.display = 'none'; }, 600);
        }
    }

    /**
     * Unified cluster/back loading: show screen, clear scene, load data, hide screen.
     */
    async _clusterLoading(constellationId, clusterKey, dataPromise, skipPushState = false) {
        // Prevent concurrent cluster loads
        if (this._clusterLoadingInProgress) return;
        this._clusterLoadingInProgress = true;

        this._showClusterScreen();
        this.controls.enabled = false;

        // Reset all transition state so nodes are created at full opacity
        this._portalTransition = null;
        this._portalFadeInMultiplier = undefined;
        this._revvingPortal = null;

        this.clearAll();

        try {
            const nodeData = await dataPromise;
            await this.loadDataForConstellation(constellationId, nodeData, false, false, false, clusterKey);

            if (!skipPushState) {
                const navUrl = this.constellationUrl(constellationId, window.TELARIS_CONSTELLATION_SLUG, clusterKey);
                window.history.pushState({}, '', navUrl);
            }

            this._hideClusterScreen();
            this.controls.enabled = true;
        } catch (err) {
            console.error('Cluster loading failed:', err);
            this._hideClusterScreen();
            this.controls.enabled = true;
        } finally {
            this._clusterLoadingInProgress = false;
        }
    }

    /**
     * Run transition BACK: show overlay, load data, reveal.
     */
    runBackTransition(targetId) {
        const fetchUrl = `/api/nodes.php?constellation_id=${targetId}${fuzzyParam()}`;
        const dataPromise = apiFetch(fetchUrl)
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP error! status: ${r.status}`)))
            .then(json => Array.isArray(json) ? json : []);
        this._clusterLoading(targetId, null, dataPromise);
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
    switchConstellation(id, slug = null) {
        const navUrl = this.constellationUrl(id, slug);
        window.history.pushState({}, '', navUrl);
        this.navigationStack.push({ constellationId: id, clusterKey: null });
        this.updateBackButtonVisibility();
        this.clearAll();
        this.loadDataForConstellation(id);
    }

    /**
     * Fetch nodes for a constellation and replace the current network (no fade).
     */
    async loadDataForConstellation(constellationId, nodeData = null, skipFit = false, skipClear = false, skipPhysics = false, clusterKey = null) {
        if (!window.TELARIS_API_KEY) throw new Error('API key not available');

        // Skip theme re-setup when navigating within the same constellation (cluster drill-in/out)
        const isSameConstellation = constellationId === window.TELARIS_CONSTELLATION_ID;
        if (!isSameConstellation) {
            const constellationInfo = await this.updateConstellationUI(constellationId);
            const themeId = constellationInfo?.theme || 'cosmic';
            this.currentTheme = getTheme(themeId);
            this.setupTheme(this.currentTheme);
        }

        if (!nodeData) {
            let url = `/api/nodes.php?constellation_id=${constellationId}${fuzzyParam()}`;
            if (clusterKey) url += `&cluster=${encodeURIComponent(clusterKey)}`;
            const response = await apiFetch(url);
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
        this.currentClusterKey = clusterKey || null;
        this.updateBackButtonVisibility();
        this.updateBreadcrumb();
    }

    /**
     * Transition to a cluster view using back-style cross-fade (for breadcrumb/back navigation).
     */
    transitionToCluster(constellationId, clusterKey, isBack = false) {
        let fetchUrl = `/api/nodes.php?constellation_id=${constellationId}${fuzzyParam()}`;
        if (clusterKey) fetchUrl += `&cluster=${encodeURIComponent(clusterKey)}`;
        const dataPromise = apiFetch(fetchUrl)
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP error! status: ${r.status}`)))
            .then(json => Array.isArray(json) ? json : []);
        this._clusterLoading(constellationId, clusterKey, dataPromise, isBack);
    }

    /**
     * Update the breadcrumb display for cluster navigation.
     */
    updateBreadcrumb() {
        let bc = document.getElementById('cluster-breadcrumb');
        if (!bc) {
            bc = document.createElement('div');
            bc.id = 'cluster-breadcrumb';
            Object.assign(bc.style, {
                position: 'absolute', top: '10px', left: '50%', transform: 'translateX(-50%)',
                zIndex: '90', fontFamily: 'var(--font-mono)', fontSize: '0.65rem',
                letterSpacing: '0.12em', textTransform: 'uppercase',
                color: 'rgba(0, 255, 204, 0.8)', background: 'rgba(0, 0, 0, 0.4)',
                padding: '0.3rem 0.7rem', borderRadius: '2px',
                backdropFilter: 'blur(4px)', border: '1px solid rgba(0, 255, 204, 0.2)',
                display: 'none', cursor: 'default', pointerEvents: 'auto'
            });
            const container = document.getElementById('canvas-container');
            if (container) container.appendChild(bc);
        }

        if (!this.currentClusterKey) {
            bc.style.display = 'none';
            return;
        }

        bc.style.display = 'block';
        bc.innerHTML = '';

        // "All" root link
        const rootLink = document.createElement('span');
        rootLink.textContent = window.TELARIS_BREADCRUMB_ALL || 'window.TELARIS_BREADCRUMB_ALL';
        rootLink.style.cursor = 'pointer';
        rootLink.style.opacity = '0.7';
        rootLink.addEventListener('click', () => {
            const cid = window.TELARIS_CONSTELLATION_ID;
            this.navigationStack = [{ constellationId: cid, clusterKey: null }];
            this.updateBackButtonVisibility();
            this.clearSearch();
            this.transitionToCluster(cid, null);
        });
        bc.appendChild(rootLink);

        // Parse segments
        const segments = this.currentClusterKey.split('/');
        let accumulatedKey = '';
        segments.forEach((seg, idx) => {
            const sep = document.createElement('span');
            sep.textContent = ' > ';
            sep.style.opacity = '0.4';
            bc.appendChild(sep);

            accumulatedKey += (accumulatedKey ? '/' : '') + seg;
            const colonIdx = seg.indexOf(':');
            const label = colonIdx >= 0 ? seg.substring(colonIdx + 1) : seg;

            const segLink = document.createElement('span');
            segLink.textContent = label;

            if (idx < segments.length - 1) {
                // Clickable — navigate to this intermediate level
                segLink.style.cursor = 'pointer';
                segLink.style.opacity = '0.7';
                const targetKey = accumulatedKey;
                segLink.addEventListener('click', () => {
                    const cid = window.TELARIS_CONSTELLATION_ID;
                    // Trim navigation stack to root + this level
                    this.navigationStack = [
                        { constellationId: cid, clusterKey: null },
                        { constellationId: cid, clusterKey: targetKey }
                    ];
                    this.updateBackButtonVisibility();
                    this.clearSearch();
                    this.transitionToCluster(cid, targetKey);
                });
            } else {
                segLink.style.opacity = '1';
            }
            bc.appendChild(segLink);
        });
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
            this._mouseHasMoved = true;
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

                if (data.node_type === 'cluster' && data.cluster_key) {
                    event.preventDefault();
                    event.stopPropagation();
                    const cid = window.TELARIS_CONSTELLATION_ID;
                    this.startPortalRev(targetNode, cid, data.cluster_key);
                    return;
                }

                if (data.node_type === 'portal') {
                    if (data.target_constellation_id != null) {
                        event.preventDefault();
                        event.stopPropagation();

                        const hasDesc = !!(data.description && data.description.trim() !== '');
                        if (hasDesc) {
                            this.showRichMediaWindow(targetNode);
                        } else {
                            if (window.telarisApp) {
                                window.telarisApp.startPortalRev(targetNode, data.target_constellation_id, null, data.target_constellation_slug);
                            } else {
                                window.location.href = this.constellationUrl(data.target_constellation_id, data.target_constellation_slug);
                            }
                        }
                    }
                } else if (data.node_type === 'object') {
                    event.preventDefault();
                    event.stopPropagation();

                    // URL is optional: a wormhole opens its media modal if it has a
                    // hotglue page or any classic media (image/video/pdf/embed/audio).
                    // Only a node with nothing but an external URL falls back to openInFrame.
                    const hasMedia = !!(data.media_mode === 'hotglue' || data.image_url || data.video_url || data.pdf_url || data.embed_code || data.audio_url);
                    const hasDesc = !!(data.description && data.description.trim() !== '');

                    if (hasMedia) {
                        this.showRichMediaWindow(targetNode);
                    } else if (data.url) {
                        this.openInFrame(targetNode, data.url);
                    } else if (hasDesc) {
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
                        if (nodeData.node_type === 'cluster' && nodeData.cluster_key) {
                            e.preventDefault();
                            const cid = window.TELARIS_CONSTELLATION_ID;
                            this.startPortalRev(touchStartNode, cid, nodeData.cluster_key);
                            this.networkManager.setFocusedNode(null);
                        } else if (nodeData.node_type === 'portal' && nodeData.target_constellation_id != null) {
                            e.preventDefault();
                            const hasDesc = !!(nodeData.description && nodeData.description.trim() !== '');
                            if (hasDesc) {
                                this.showRichMediaWindow(touchStartNode);
                            } else {
                                this.startPortalRev(touchStartNode, nodeData.target_constellation_id, null, nodeData.target_constellation_slug);
                            }
                            this.networkManager.setFocusedNode(null);
                        } else if (nodeData.node_type === 'object') {
                            const hasMedia = !!(nodeData.media_mode === 'hotglue' || nodeData.image_url || nodeData.video_url || nodeData.pdf_url || nodeData.embed_code || nodeData.audio_url);
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

                const galaxyLabel = this.galaxyLabelForNode(node.userData);
                if (galaxyLabel) {
                    const galaxyDiv = document.createElement('div');
                    galaxyDiv.style.cssText = 'opacity:0.6; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:1px;';
                    galaxyDiv.textContent = galaxyLabel;
                    this.tooltip.appendChild(galaxyDiv);
                }

                const nameDiv = document.createElement('div');
                nameDiv.style.cssText = 'font-weight:600; margin-bottom: 2px;';
                nameDiv.textContent = node.userData.name;
                this.tooltip.appendChild(nameDiv);

                if (node.userData.description && node.userData.description.trim() !== '') {
                    const descDiv = document.createElement('div');
                    descDiv.style.cssText = 'opacity: 0.75; font-size: 0.75rem; margin-top: 4px; line-height: 1.4;';
                    descDiv.textContent = node.userData.description.trim();
                    this.tooltip.appendChild(descDiv);
                }

                const styles = this.getNodeTooltipStyles(node);
                const d = node.userData;
                const lineColor = (d && d.colorR !== undefined)
                    ? `rgb(${d.colorR},${d.colorG},${d.colorB})`
                    : '#fff';
                Object.assign(this.tooltip.style, {
                    backgroundColor: styles.backgroundColor,
                    color: styles.color,
                    backdropFilter: 'blur(8px)',
                    webkitBackdropFilter: 'blur(8px)',
                    visibility: 'visible',
                    display: 'block',
                    opacity: '0',
                    zIndex: '200',
                    border: `2px solid ${lineColor.replace('rgb(', 'rgba(').replace(')', ', 0.5)')}`,
                    paddingBottom: '8px'
                });

                if (this.tooltipLine) {
                    this.tooltipLine.setAttribute('stroke', lineColor);
                    this.tooltipLine.setAttribute('stroke-opacity', '0.5');
                }

                const rect = this.renderer.domElement.getBoundingClientRect();
                const projected = new THREE.Vector3();
                node.getWorldPosition(projected);
                projected.project(this.camera);
                const nodeX = (projected.x * 0.5 + 0.5) * rect.width;
                const nodeY = (0.5 - projected.y * 0.5) * rect.height;

                this._positionTooltipVertically(nodeY);
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    this._positionTooltipVertically(nodeY);
                    this.tooltip.style.opacity = '1';
                    if (this.tooltipLineSvg) this.tooltipLineSvg.style.opacity = '1';
                    this._updateTooltipLine(nodeX, nodeY);
                }));
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
                    id: data.id,
                    name: data.name,
                    description: data.description,
                    keywords: data.keywords || [],
                    keyword_groups: data.keyword_groups || [],
                    constellation_id: (data.constellation_id !== undefined && data.constellation_id !== null) ? Number(data.constellation_id) : null,
                    constellation_name: (typeof data.constellation_name === 'string' && data.constellation_name !== '') ? data.constellation_name : null,
                    constellation_theme: (typeof data.constellation_theme === 'string' && data.constellation_theme !== '') ? data.constellation_theme : null,
                    url: data.url,
                    image_url: data.image_url,
                    image_attribution: data.image_attribution || null,
                    embed_code: data.embed_code,
                    audio_url: data.audio_url,
                    audio_autoplay: !!data.audio_autoplay,
                    audio_loop: !!data.audio_loop,
                    video_url: data.video_url,
                    video_autoplay: !!data.video_autoplay,
                    pdf_url: data.pdf_url || null,
                    node_type: data.node_type ?? 'object',
                    target_constellation_id: (data.target_constellation_id !== undefined && data.target_constellation_id !== null && data.target_constellation_id !== '') ? Number(data.target_constellation_id) : null,
                    target_constellation_slug: data.target_constellation_slug || null,
                    is_accentuated: !!data.is_accentuated,
                    show_keywords: !!data.show_keywords,
                    use_image_as_node: !!data.use_image_as_node,
                    media_mode: (data.media_mode === 'hotglue') ? 'hotglue' : 'classic',
                    hotglue_page: data.hotglue_page || null,
                    cluster_key: data.cluster_key || null,
                    cluster_count: data.cluster_count || null,
                    icon_url: data.icon_url || null,
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
                // During cross-fade transitions, start nodes invisible so they don't flash at full opacity
                const isTransitioningIn = this._portalFadeInMultiplier !== undefined && this._portalFadeInMultiplier !== null;
                const initialOpacity = isTransitioningIn ? 0 : 1.0;
                const material = new THREE.MeshStandardMaterial({
                    color, emissive: color, emissiveIntensity: node.is_accentuated ? 1.5 : 0.5,
                    metalness: 0.3, roughness: 0.7, transparent: true, opacity: initialOpacity
                });
                // If the editor opted in, use the node's main image as the 3D icon.
                // Falls back to icon_url, then to the theme's default icon.
                const iconSourceUrl = (node.use_image_as_node && data.image_url) ? data.image_url : node.icon_url;
                // In multi-galaxy union views, each wormhole carries its source galaxy's theme so its
                // 3D icon stays recognizable across themes. The scene theme stays global (lighting,
                // background animations, station rings); only the icon factory branches per-node.
                const iconThemeId = node.constellation_theme || this.currentTheme.id;
                const mesh = createNodeIcon(material, i, this.geometryManager, node.node_type, iconThemeId, iconSourceUrl);
                mesh.visible = !isTransitioningIn;
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

                // Cluster mesh tagging
                if (node.node_type === 'cluster') {
                    mesh.isCluster = true;
                    mesh.userData.baseScale = mesh.scale.x;
                    mesh.scale.setScalar(1.5); // Slightly larger than regular nodes
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
                    if (c.material && c.name !== 'portal_hitbox' && c.name !== 'cluster_hitbox') {
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

        // Cache how many distinct galaxies the current 3D view spans. The galaxy
        // name is shown before the wormhole name (tooltip + media modal) only
        // when more than one galaxy is loaded, covering every multi-galaxy entry
        // point uniformly: cluster, multi-galaxy URL, "[PREFIX]" union, etc.
        const galaxyIds = new Set();
        for (const m of this.nodes) {
            const cid = m.userData && m.userData.constellation_id;
            if (cid !== null && cid !== undefined) galaxyIds.add(cid);
        }
        this._distinctGalaxyCount = galaxyIds.size;
    }

    /** True when the 3D view currently spans more than one galaxy. */
    isMultiGalaxyView() {
        return (this._distinctGalaxyCount || 0) > 1;
    }

    /**
     * The galaxy name to show before a wormhole name, or null when it should be
     * omitted (single-galaxy view, or the node carries no galaxy name).
     */
    galaxyLabelForNode(d) {
        if (!this.isMultiGalaxyView()) return null;
        const name = d && typeof d.constellation_name === 'string' ? d.constellation_name.trim() : '';
        return name !== '' ? name : null;
    }

    getSharedKeywordsCount(n1, n2) {
        // When fuzzy matching is on, compare the server-computed cluster keys
        // (keyword_groups) so variant keywords (colonial/colonialism, typos,
        // shared tokens) count as shared. Falls back to exact keyword overlap
        // when fuzzy is off or the groups are not present in the payload.
        if (window.TELARIS_FUZZY_KEYWORDS) {
            const g1 = n1.userData.keyword_groups;
            const g2 = n2.userData.keyword_groups;
            if (Array.isArray(g1) && Array.isArray(g2)) {
                return g1.filter(k => g2.includes(k)).length;
            }
        }
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

        const bands = [0.008, 0.012, 0.018, 0.026];
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

                    // Bridge = the two endpoints belong to different galaxies (only meaningful in
                    // multigalaxy union views, where shared keyword text crosses galaxy boundaries).
                    const cid1 = n1.userData.constellation_id;
                    const cid2 = n2.userData.constellation_id;
                    const isBridge = (cid1 != null && cid2 != null && cid1 !== cid2);

                    let mesh;
                    if (isBridge) {
                        // Subtle dashed line, same hue, slightly muted opacity so the bridge
                        // reads as part of the same fabric without editorialising it.
                        const lineGeo = new THREE.BufferGeometry();
                        lineGeo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(6), 3));
                        const lineMat = new THREE.LineDashedMaterial({
                            color,
                            transparent: true,
                            opacity: 0,
                            dashSize: 0.18,
                            gapSize: 0.12,
                            depthWrite: false,
                            depthTest: true
                        });
                        mesh = new THREE.Line(lineGeo, lineMat);
                    } else {
                        const material = new THREE.MeshBasicMaterial({
                            color,
                            transparent: true,
                            opacity: 0,
                            side: THREE.DoubleSide,
                            depthWrite: false,
                            depthTest: true
                        });
                        mesh = new THREE.Mesh(geometry, material);
                    }
                    mesh.visible = false; // Start invisible
                    mesh.renderOrder = 50; // Render after nebulas, before nodes (100)
                    this.connections.push({
                        mesh, node1: n1, node2: n2, sharedCount: shared,
                        thickness, baseOpacity: Math.min(opacity * (isBridge ? 1.0 : 1.5), 1.0),
                        currentOpacity: 0, targetOpacity: 0,
                        isBridge
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
        this._anchorCache.clear();
        const cache = this._anchorCache;
        const getAnchor = (n) => {
            if (!cache.has(n)) {
                const pos = new THREE.Vector3();
                n.getWorldPosition(pos);
                cache.set(n, pos);
            }
            return cache.get(n);
        };

        for (const c of this.connections) {
            if (!c.node1.visible || !c.node2.visible) {
                c.mesh.visible = false;
                continue;
            }

            const p1 = getAnchor(c.node1), p2 = getAnchor(c.node2);

            if (c.isBridge) {
                // Two-vertex Line: write world positions directly into the buffer.
                // computeLineDistances() must be called whenever positions change so
                // LineDashedMaterial spaces dashes correctly.
                const positions = c.mesh.geometry.attributes.position.array;
                positions[0] = p1.x; positions[1] = p1.y; positions[2] = p1.z;
                positions[3] = p2.x; positions[4] = p2.y; positions[5] = p2.z;
                c.mesh.geometry.attributes.position.needsUpdate = true;
                c.mesh.computeLineDistances();
                continue;
            }

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
        // Zero out velocities so nodes don't glitch on first visible frame
        this.nodes.forEach(n => n.userData.velocity.set(0, 0, 0));
    }

    applyForces(dtSec, strength = 0.05) {
        if (this.nodes.length < 2) return;
        const dt = Math.min(dtSec, 0.032);
        const params = { rep: 2.5 * strength, att: 0.04 * strength, ideal: 8.0, damp: strength > 0.5 ? 0.85 : 0.7, maxD: 25, maxF: 0.6 * strength };
        const maxV = strength > 0.5 ? 0.25 : 0.008;
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
            
            // Search Filtering: hard visibility toggle. When searchFilterIds is set
            // (committed search via Enter or single-result click), it takes precedence
            // over text matching so we can show exactly the chosen nodes.
            let matchesSearch;
            if (this.searchFilterIds) {
                const rawId = d.id;
                const numId = typeof rawId === 'string' ? parseInt(rawId, 10) : rawId;
                matchesSearch = this.searchFilterIds.has(numId);
            } else if (this.searchQuery) {
                const q = this.searchQuery;
                matchesSearch =
                    (d.name && d.name.toLowerCase().includes(q)) ||
                    (d.description && d.description.toLowerCase().includes(q)) ||
                    (d.keywords && d.keywords.some(k => k.toLowerCase().includes(q)));
            } else {
                matchesSearch = true;
            }

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

            const isTransitioning = this._portalFadeInMultiplier !== undefined && this._portalFadeInMultiplier !== null && this._portalFadeInMultiplier < 1;

            const forceInvisible = (this._portalFadeInMultiplier === 0);

            // 1. Theme-specific animation logic
            let glitchOffset = this._scratchVec2.set(0, 0, 0);
            let glitchScaleMult = 1.0;
            let glitchOpacityMult = 1.0;
            let glitchRotation = 0;

            // ── Universal node animations (all themes) ──────────────
            if (d.animGlitchTimer === undefined) {
                d.animGlitchTimer  = 15 + Math.random() * 20;
                d.animGlitchActive = 0;
                d.animFloatOffset  = Math.random() * Math.PI * 2;
                d.animBlinkTimer   = 25 + Math.random() * 30;
                d.animBlinkActive  = 0;
            }

            // 1. DRIFT — Lissajous float around anchor (always active to avoid jump when transition ends)
            glitchOffset.add(this._scratchVec.set(
                Math.sin(time * 0.37 + d.animFloatOffset) * 0.28,
                Math.cos(time * 0.51 + d.animFloatOffset * 1.3) * 0.28,
                0
            ));

            if (!isTransitioning) {
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

            // Tour-spotlight context: lifted out of the material loop so it can
            // also dim sprite/non-emissive materials via opacity. Strength
            // (0..1) eases in over ~600ms so the dim doesn't snap on.
            const isTourSpotlight = (this._tourSpotlightNode === n);
            const tourActive = !!this._tourSpotlightNode;
            const spotStrength = this._tourSpotlightStrength || 0;
            const tourDimNonSpotlight = (tourActive && !isTourSpotlight) ? (1.0 - spotStrength * 0.7) : 1.0;

            // Keyword-chip filter: when at least one chip is active, dim nodes
            // whose keywords don't intersect the active set.
            const activeKeywords = this.activeKeywords;
            let keywordChipDim = 1.0;
            if (activeKeywords && activeKeywords.size > 0) {
                const kws = d.keywords || [];
                let matches = false;
                for (let kI = 0; kI < kws.length; kI++) {
                    if (activeKeywords.has(kws[kI])) { matches = true; break; }
                }
                if (!matches) keywordChipDim = 0.15;
            }

            // Galaxy-list-strip filter (multigalaxy unions): when at least one galaxy
            // chip is active, dim nodes whose source galaxy isn't in the active set.
            const activeGalaxies = this.activeGalaxyIds;
            let galaxyStripDim = 1.0;
            if (activeGalaxies && activeGalaxies.size > 0) {
                if (!activeGalaxies.has(Number(d.constellation_id))) galaxyStripDim = 0.15;
            }

            // Related-nodes dim: when an info card is open and the galaxy enables
            // the related-wormholes feature, dim everything except the current
            // node + its related set.
            let relatedDim = 1.0;
            if (this._relatedNodeSet && this._relatedNodeSet.size > 0 && !this._relatedNodeSet.has(n)) {
                relatedDim = 0.15;
            }

            // Optimization: iterate cached materials directly
            d.cachedMaterials.forEach(m => {
                m.opacity = opacity * glitchOpacityMult * tourDimNonSpotlight * keywordChipDim * galaxyStripDim * relatedDim;
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

                            // Don't dim when this is the tour spotlight — we want it bright.
                            const hoverDim = (isActive && !isTourSpotlight) ? 0.15 : 1.0;
                            let flareBoost = 1.0;
                            if (d.solarFlare > 0) {
                                flareBoost = 8.0 * (d.solarFlare / 15);
                                if (m === d.cachedMaterials[0]) d.solarFlare--; // Only decrement once per node
                            }
                            // Accentuated nodes get a smaller emissive boost now
                            const accentBoost = d.is_accentuated ? 1.4 : 1.0;
                            const tourBoostFull = 6.0 + Math.sin(time * 3.5) * 3.5;
                            const tourBoost = isTourSpotlight ? (1.0 + spotStrength * (tourBoostFull - 1.0)) : 1.0;
                            m.emissiveIntensity = m._baseEmissiveIntensity * brightness * hoverDim * twinkle * flareBoost * accentBoost * tourBoost * tourDimNonSpotlight * keywordChipDim * relatedDim;
                        }
                    }
                } else if (m.isSpriteMaterial) {
                    // Sprites in Abstract theme can have random rotation jumps
                    // SKIP if this is a portal, as portals use 3D rotation logic in animate()
                    if (n.userData.node_type !== 'portal' && n.userData.node_type !== 'cluster') {
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
            
            // Scale: always apply pulse to avoid jump when transition ends
            const pulseFreq = d.is_accentuated ? 2.0 : 1.5;
            const pulseAmp = d.is_accentuated ? 0.15 : 0.08;
            const baseS = d.is_accentuated ? 2.8 : 1.8;
            const scaleMult = isTransitioning ? 1.0 : glitchScaleMult;
            // Tour spotlight: large, slow pulse so the eye catches it during the camera pan.
            // Eases in via spotStrength so the size jump isn't a snap.
            let tourSpotlightMult = 1.0;
            if (this._tourSpotlightNode === n) {
                const fullMult = 1.9 + Math.sin(time * 2.5) * 0.35;
                tourSpotlightMult = 1.0 + spotStrength * (fullMult - 1.0);
            }
            const s = (baseS + Math.sin(time * pulseFreq + d.phase) * pulseAmp) * scaleMult * tourSpotlightMult;
            n.scale.set(s, s, s);

            // Optimization: iterate cached moons directly
            d.cachedMoons.forEach(moonGroup => {
                moonGroup.rotation.y = time * moonGroup.userData.speed;
                moonGroup.rotation.z = time * (moonGroup.userData.speed * 0.3);
            });

            // 5. IDLE SPIN-UP — cosmic geometry nodes only
            if (this.currentTheme.id === 'cosmic' && !n.isPortal && !n.isCluster && !isTransitioning) {
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
        this._scratchVec.project(this.camera);

        if (this._scratchVec.z > 1 || this._scratchVec.z < -1) {
            this.tooltip.style.opacity = '0';
            if (this.tooltipLineSvg) this.tooltipLineSvg.style.opacity = '0';
            return;
        }

        const nodeX = (this._scratchVec.x * 0.5 + 0.5) * rect.width;
        const nodeY = (0.5 - this._scratchVec.y * 0.5) * rect.height;

        this._positionTooltipVertically(nodeY);
        this._updateTooltipLine(nodeX, nodeY);
    }

    _positionTooltipVertically() {
        if (!this.tooltip) return;
        this.tooltip.style.top = '3.5rem';
        this.tooltip.style.bottom = 'auto';
    }

    _updateTooltipLine(nodeX, nodeY) {
        if (!this.tooltipLine || !this.tooltip) return;
        const panelRect = this.tooltip.getBoundingClientRect();
        const containerRect = this.tooltip.parentElement.getBoundingClientRect();
        const panelX = panelRect.left - containerRect.left;
        const panelY = panelRect.top - containerRect.top + panelRect.height / 2;

        // Stepped orthogonal path: horizontal from node, then vertical, then horizontal to panel
        const midX = panelX + (nodeX - panelX) * 0.35;
        const points = `${nodeX},${nodeY} ${midX},${nodeY} ${midX},${panelY} ${panelX},${panelY}`;
        this.tooltipLine.setAttribute('points', points);
    }

    updateHoverState() {
        if (!this._mouseHasMoved) return;
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
                const isCluster = hoveredNode && hoveredNode.userData.node_type === 'cluster' && hoveredNode.userData.cluster_key;
                // URL optional: any wormhole with a hotglue page, classic media, a URL,
                // or a description is openable, so show the pointer (mirrors the click handler).
                const od = hoveredNode.userData;
                const isOpenableObject = od.node_type === 'object' && (od.media_mode === 'hotglue' || od.image_url || od.video_url || od.pdf_url || od.embed_code || od.audio_url || od.url || (od.description && String(od.description).trim() !== ''));

                this.renderer.domElement.style.cursor = (isPortal || isCluster || isOpenableObject) ? 'pointer' : 'default';

                if (this.tooltip && hoveredNode.userData.name) {
                    if (this.tooltipHideTimeout) {
                        clearTimeout(this.tooltipHideTimeout);
                        this.tooltipHideTimeout = null;
                    }

                    this.tooltip.textContent = '';

                    const galaxyLabel = this.galaxyLabelForNode(hoveredNode.userData);
                    if (galaxyLabel) {
                        const galaxyDiv = document.createElement('div');
                        galaxyDiv.style.cssText = 'opacity:0.6; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:1px;';
                        galaxyDiv.textContent = galaxyLabel;
                        this.tooltip.appendChild(galaxyDiv);
                    }

                    const nameDiv = document.createElement('div');
                    nameDiv.style.cssText = 'font-weight:600; margin-bottom: 2px;';
                    nameDiv.textContent = hoveredNode.userData.name;
                    this.tooltip.appendChild(nameDiv);

                    if (hoveredNode.userData.description && hoveredNode.userData.description.trim() !== '') {
                        const descDiv = document.createElement('div');
                        descDiv.style.cssText = 'opacity: 0.75; font-size: 0.75rem; margin-top: 4px; line-height: 1.4;';
                        descDiv.textContent = hoveredNode.userData.description.trim();
                        this.tooltip.appendChild(descDiv);
                    }

                    const styles = this.getNodeTooltipStyles(hoveredNode);
                    const d = hoveredNode.userData;
                    const lineColor = (d && d.colorR !== undefined)
                        ? `rgb(${d.colorR},${d.colorG},${d.colorB})`
                        : '#fff';
                    Object.assign(this.tooltip.style, {
                        backgroundColor: styles.backgroundColor,
                        color: styles.color,
                        backdropFilter: 'blur(8px)',
                        webkitBackdropFilter: 'blur(8px)',
                        visibility: 'visible',
                        display: 'block',
                        opacity: '0',
                        zIndex: '200',
                        border: `2px solid ${lineColor.replace('rgb(', 'rgba(').replace(')', ', 0.5)')}`,
                        paddingBottom: '8px'
                    });

                    // Style the connector line with node color
                    if (this.tooltipLine) {
                        this.tooltipLine.setAttribute('stroke', lineColor);
                        this.tooltipLine.setAttribute('stroke-opacity', '0.5');
                    }

                    // Compute node screen position for the connector line
                    const rect = this.renderer.domElement.getBoundingClientRect();
                    const projected = new THREE.Vector3();
                    hoveredNode.getWorldPosition(projected);
                    projected.project(this.camera);
                    const nodeX = (projected.x * 0.5 + 0.5) * rect.width;
                    const nodeY = (0.5 - projected.y * 0.5) * rect.height;

                    this._positionTooltipVertically(nodeY);
                    requestAnimationFrame(() => requestAnimationFrame(() => {
                        this._positionTooltipVertically(nodeY);
                        this.tooltip.style.opacity = '1';
                        if (this.tooltipLineSvg) this.tooltipLineSvg.style.opacity = '1';
                        this._updateTooltipLine(nodeX, nodeY);
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
                        if (window._loadingTorus) window._loadingTorus.reset();
                    }

                    const app = window.telarisApp || this;
                    let fetchUrl = `/api/nodes.php?constellation_id=${tr.targetId}${fuzzyParam()}`;
                    if (tr.clusterKey) fetchUrl += `&cluster=${encodeURIComponent(tr.clusterKey)}`;
                    const dataPromise = tr.dataPromise || apiFetch(fetchUrl).then(r => r.json());

                    dataPromise.then((nodeData) => {
                        if (this._portalTransition && this._portalTransition.phase === 'loading') {
                            app.loadDataForConstellation(tr.targetId, nodeData, false, false, false, tr.clusterKey).then(() => {
                                if (this._portalTransition && this._portalTransition.phase === 'loading') {
                                    this.navigationStack.push({ constellationId: tr.targetId, clusterKey: tr.clusterKey || null });
                                    this.updateBackButtonVisibility();
                                    // Update URL for portal/cluster navigation
                                    const navUrl = this.constellationUrl(tr.targetId, tr.targetSlug || window.TELARIS_CONSTELLATION_SLUG, tr.clusterKey);
                                    window.history.pushState({}, '', navUrl);
                                    
                                    const startFadeIn = () => {
                                        const loadingOverlay = document.getElementById('loading-overlay');
                                        if (loadingOverlay) {
                                            loadingOverlay.style.opacity = '0';
                                            loadingOverlay.style.pointerEvents = 'none';
                                            setTimeout(() => { loadingOverlay.style.display = 'none'; }, 300);
                                        }
                                        this._portalTransition.phase = 'fade_in';
                                        this._portalTransition.fadeInStartTime = performance.now();
                                        this._portalTransition.fadeInDuration = tr.targetFadeInDuration || 1000;
                                        this._portalFadeInMultiplier = 0;
                                    };
                                    if (window._loadingTorus) {
                                        window._loadingTorus.startWarp(startFadeIn);
                                    } else {
                                        startFadeIn();
                                    }
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
            }
        }

        // 2. Normal updates (will use the _portalFadeInMultiplier set above)
        const isZooming = this._portalTransition && this._portalTransition.phase === 'camera_fade_out';

        const isFadingIn = this._portalFadeInMultiplier !== undefined && this._portalFadeInMultiplier !== null && this._portalFadeInMultiplier < 1;
        if (!isZooming) {
            if (this._tourTweening) {
                this.controls.autoRotate = false;
            } else {
                this.controls.autoRotate = !isFadingIn && (now - this.lastInteractionAt) > this.idleRotateDelayMs;
            }
            if (!isFadingIn) {
                this.applyForces(dt, 0.05);
            }
            if (!this._tourTweening) {
                this.controls.update();
            }
            this.updateHoverState();
            this.updateTourSpotlightStrength();
            this.updateNodes(dt);
            this.updateConnections(dt);
            this.updateTourHalo();
            this.updateTourLabel();
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
            const { node, targetId, clusterKey, targetSlug } = this._revvingPortal;
            this._revvingPortal = null;
            this.runPortalTransition(node, targetId, clusterKey, targetSlug);
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
            if (object.isCluster) {
                const isRevving = this._revvingPortal && this._revvingPortal.node === object;
                const revMult = isRevving ? 8 : 1;
                const pulseSpeed = isRevving ? 12 : 1.5;
                const pulseAmp = isRevving ? 0.3 : 0.08;
                object.rotation.y += 0.005 * revMult;
                object.rotation.x += 0.002 * revMult;
                const pulse = 1 + Math.sin(time * pulseSpeed) * pulseAmp;
                const baseScale = object.userData.baseScale ?? 1.5;
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

    clearSearch() {
        this.searchQuery = '';
        this.searchFilterIds = null;
        const searchInput = document.getElementById('hud-search');
        const clearBtn = document.getElementById('hud-search-clear');
        const resultsDropdown = document.getElementById('hud-search-results');
        if (searchInput) searchInput.value = '';
        if (clearBtn) clearBtn.style.display = 'none';
        if (resultsDropdown) resultsDropdown.style.display = 'none';
        this._hideSearchLegend();

        // If we navigated into a flat (no_cluster) view to display search hits,
        // restore the constellation's default (auto-clustered) root view.
        if (this._searchFlatViewActive) {
            this._searchFlatViewActive = false;
            const cid = window.TELARIS_CONSTELLATION_ID;
            this.navigationStack = [{ constellationId: cid, clusterKey: null }];
            this.transitionToCluster(cid, null, true);
        }
    }

    /**
     * Floating pill at the top of the canvas that announces "Search results"
     * while a flat-search view is active. Click the × to clear and restore.
     */
    _renderSearchLegend(text) {
        let el = document.getElementById('search-legend');
        if (!el) {
            el = document.createElement('div');
            el.id = 'search-legend';
            Object.assign(el.style, {
                position: 'absolute', top: '10px', left: '50%', transform: 'translateX(-50%)',
                zIndex: '90', fontFamily: 'var(--font-mono)', fontSize: '0.65rem',
                letterSpacing: '0.12em', textTransform: 'uppercase',
                color: 'rgba(0, 255, 204, 0.85)', background: 'rgba(0, 0, 0, 0.55)',
                padding: '0.3rem 0.7rem', borderRadius: '2px',
                backdropFilter: 'blur(4px)', border: '1px solid rgba(0, 255, 204, 0.35)',
                cursor: 'default', pointerEvents: 'auto',
                gap: '0.5rem', alignItems: 'center', maxWidth: '60vw'
            });
            const container = document.getElementById('canvas-container');
            if (container) container.appendChild(el);
        }
        el.innerHTML = '';

        const label = document.createElement('span');
        label.textContent = text;
        label.style.overflow = 'hidden';
        label.style.textOverflow = 'ellipsis';
        label.style.whiteSpace = 'nowrap';
        el.appendChild(label);

        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = '×';
        close.title = (window.TELARIS_CLEAR_SCAN_TEXT || 'window.TELARIS_CLEAR_SCAN_TEXT');
        Object.assign(close.style, {
            background: 'none', border: 'none', color: 'rgba(255,255,255,0.7)',
            cursor: 'pointer', fontSize: '1rem', lineHeight: '1',
            padding: '0 0 0 0.25rem', marginLeft: 'auto'
        });
        close.addEventListener('click', () => this.clearSearch());
        el.appendChild(close);

        el.style.display = 'flex';
    }

    _hideSearchLegend() {
        const el = document.getElementById('search-legend');
        if (el) el.style.display = 'none';
    }

    setupSearch() {
        const searchInput = document.getElementById('hud-search');
        const clearBtn = document.getElementById('hud-search-clear');
        const resultsDropdown = document.getElementById('hud-search-results');
        if (!searchInput) return;

        let searchTimer = null;
        let abortController = null;
        let selectedIndex = -1;
        // Cache the last successful query+results so focusing the input again
        // (after Enter/click closed the dropdown) can re-show the same list
        // without waiting for a refetch.
        let lastResults = null;
        let lastQuery = '';

        const hideResults = () => {
            if (resultsDropdown) resultsDropdown.style.display = 'none';
            selectedIndex = -1;
        };

        const showResults = (results, query) => {
            if (!resultsDropdown) return;
            resultsDropdown.innerHTML = '';

            if (results.length === 0) {
                resultsDropdown.innerHTML = '<div class="px-3 py-2 text-xs text-white/40 uppercase tracking-wider">' + escapeHtml(window.TELARIS_NO_RESULTS_TEXT || '') + '</div>';
                resultsDropdown.style.display = 'block';
                return;
            }

            results.forEach((r, i) => {
                const item = document.createElement('div');
                item.className = 'search-result-item px-3 py-2 cursor-pointer hover:bg-white/10 transition-colors border-b border-white/5 last:border-b-0';
                item.dataset.index = i;

                // Highlight matching text in name
                const name = r.name || '?';
                const queryLower = query.toLowerCase();
                const nameLower = name.toLowerCase();
                const matchIdx = nameLower.indexOf(queryLower);
                let nameHtml;
                if (matchIdx >= 0) {
                    nameHtml = escapeHtml(name.substring(0, matchIdx))
                        + '<span class="text-[#00ffcc]">' + escapeHtml(name.substring(matchIdx, matchIdx + query.length)) + '</span>'
                        + escapeHtml(name.substring(matchIdx + query.length));
                } else {
                    nameHtml = escapeHtml(name);
                }

                const meta = [r.source_facet, r.media_type].filter(Boolean).join(' / ');
                const clusterLabel = r.cluster_path ? r.cluster_path.split('/').map(s => { const c = s.indexOf(':'); return c >= 0 ? s.substring(c + 1) : s; }).join(' > ') : '';

                item.innerHTML = `
                    <div class="text-xs text-white/90 leading-tight">${nameHtml}</div>
                    ${meta ? `<div class="text-[0.6rem] text-white/40 mt-0.5 uppercase tracking-wider">${escapeHtml(meta)}</div>` : ''}
                    ${clusterLabel ? `<div class="text-[0.6rem] text-[#00ffcc]/40 mt-0.5">${escapeHtml(clusterLabel)}</div>` : ''}
                `;

                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    hideResults();
                    searchInput.blur();
                    this.navigateToSearchResult(r);
                });

                resultsDropdown.appendChild(item);
            });

            resultsDropdown.style.display = 'block';
            selectedIndex = -1;
        };

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        const updateHighlight = () => {
            if (!resultsDropdown) return;
            const items = resultsDropdown.querySelectorAll('.search-result-item');
            items.forEach((el, i) => {
                el.style.background = i === selectedIndex ? 'rgba(0, 255, 204, 0.15)' : '';
            });
        };

        const doSearch = async (query) => {
            if (abortController) abortController.abort();
            if (query.length < 2) {
                hideResults();
                return;
            }

            abortController = new AbortController();
            const cid = window.TELARIS_CONSTELLATION_ID;
            try {
                const resp = await apiFetch(
                    `/api/nodes.php?constellation_id=${cid}&search=${encodeURIComponent(query)}&search_limit=10`,
                    { signal: abortController.signal }
                );
                if (!resp.ok) return;
                const data = await resp.json();
                if (data.search && Array.isArray(data.results)) {
                    lastResults = data.results;
                    lastQuery = query;
                    showResults(data.results, query);
                }
            } catch (e) {
                if (e.name !== 'AbortError') console.error('Search failed:', e);
            }
        };

        const updateSearch = (val) => {
            const query = val.trim();
            this.searchQuery = query.toLowerCase();
            // User is editing — drop any committed-id filter so text matching takes over.
            // The flat dataset (if previously loaded by Enter/click) stays loaded for refinement.
            this.searchFilterIds = null;
            if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';

            // Local filtering for currently visible nodes
            this.networkManager.setFocusedNode(null);
            if (this.tooltip) this.hideMainTooltip();
            this.markInteraction();

            // Debounced remote search
            if (searchTimer) clearTimeout(searchTimer);
            if (query.length < 2) {
                hideResults();
                return;
            }
            searchTimer = setTimeout(() => doSearch(query), 300);
        };

        searchInput.addEventListener('input', (e) => updateSearch(e.target.value));

        // Re-show the dropdown when the user comes back to the field — focus
        // covers Tab/programmatic; click covers re-clicking an already-focused
        // input. If the input still holds the same query we showed before,
        // serve the cached results instantly; otherwise refetch.
        const rehydrateDropdown = () => {
            const cur = searchInput.value.trim();
            if (cur.length < 2) return;
            if (cur === lastQuery && Array.isArray(lastResults)) {
                showResults(lastResults, lastQuery);
            } else {
                doSearch(cur);
            }
        };
        searchInput.addEventListener('focus', rehydrateDropdown);
        searchInput.addEventListener('click', rehydrateDropdown);

        searchInput.addEventListener('keydown', (e) => {
            e.stopPropagation();
            if (!resultsDropdown || resultsDropdown.style.display === 'none') return;
            const items = resultsDropdown.querySelectorAll('.search-result-item');
            if (items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                updateHighlight();
                items[selectedIndex]?.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateHighlight();
                items[selectedIndex]?.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedIndex >= 0) {
                    items[selectedIndex]?.click();
                } else {
                    // Commit search: pull all matching nodes into a flat view.
                    const q = searchInput.value.trim();
                    hideResults();
                    searchInput.blur();
                    if (q.length >= 2) this.commitSearchFromQuery(q);
                }
            } else if (e.key === 'Escape') {
                hideResults();
                searchInput.blur();
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.clearSearch();
                searchInput.focus();
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !resultsDropdown?.contains(e.target)) {
                hideResults();
            }
        });
    }

    /**
     * Navigate to a single search result: render only that node (flat, no cluster),
     * fly the camera to it, and open its rich-media card. Mirrors the visitor-
     * permalink path used for ?node=ID.
     */
    async navigateToSearchResult(result) {
        const cid = window.TELARIS_CONSTELLATION_ID;
        const nodeId = typeof result.id === 'string' ? parseInt(result.id, 10) : result.id;
        if (!Number.isFinite(nodeId)) return;

        await this._loadFlatWithFilter(cid, new Set([nodeId]));

        const labelBase = window.TELARIS_SEARCH_RESULT_TEXT || 'window.TELARIS_SEARCH_RESULT_TEXT';
        const name = (result.name || '').trim();
        this._renderSearchLegend(name ? `${labelBase}: ${name}` : labelBase);

        const target = this.nodes.find(n =>
            n && n.userData && (n.userData.id === nodeId || parseInt(n.userData.id, 10) === nodeId)
        );
        if (!target) return;

        if (this.networkManager?.setFocusedNode) this.networkManager.setFocusedNode(target);
        this.playGlitch();
        if (this.tourFocusOnNode) {
            try { await this.tourFocusOnNode(target, 1200); } catch (e) { /* non-fatal */ }
        }
        if (this.showRichMediaWindow) this.showRichMediaWindow(target);
    }

    /**
     * Pressing Enter in the search box: ask the server for every node whose
     * name/description/keywords match the query, then load the constellation
     * flat (no clustering) and let the visibility filter show only those ids.
     */
    async commitSearchFromQuery(query) {
        const q = (query || '').trim();
        if (q.length < 2) return;
        const cid = window.TELARIS_CONSTELLATION_ID;
        try {
            const resp = await apiFetch(
                `/api/nodes.php?constellation_id=${cid}&search=${encodeURIComponent(q)}&search_limit=1000`
            );
            if (!resp.ok) return;
            const data = await resp.json();
            if (!data || !data.search || !Array.isArray(data.results)) return;
            if (data.results.length === 0) return;
            const ids = new Set(
                data.results
                    .map(r => (typeof r.id === 'string' ? parseInt(r.id, 10) : r.id))
                    .filter(Number.isFinite)
            );
            if (ids.size === 0) return;
            await this._loadFlatWithFilter(cid, ids);
            const labelBase = window.TELARIS_SEARCH_RESULTS_TEXT || 'window.TELARIS_SEARCH_RESULTS_TEXT';
            this._renderSearchLegend(`${labelBase}: "${q}" (${ids.size})`);
        } catch (e) {
            console.error('Search commit failed:', e);
        }
    }

    /**
     * Fetch the constellation flat (no_cluster=1) and render only the nodes
     * whose ids are in `filterIds`. Used by Enter-commit and single-result click.
     */
    async _loadFlatWithFilter(cid, filterIds) {
        const fetchUrl = `/api/nodes.php?constellation_id=${cid}&no_cluster=1${fuzzyParam()}`;
        const dataPromise = apiFetch(fetchUrl)
            .then(r => r.ok ? r.json() : Promise.reject(new Error('Failed to fetch')))
            .then(json => Array.isArray(json) ? json : []);

        // Apply filter BEFORE loading so the very first frame renders correctly.
        this.searchFilterIds = filterIds;
        this._searchFlatViewActive = true;
        this.navigationStack = [{ constellationId: cid, clusterKey: null }];
        // skipPushState: this is a transient UI mode, not a navigable URL state.
        await this._clusterLoading(cid, null, dataPromise, true);
    }

    /**
     * Find a node mesh by its data ID and focus/highlight it.
     */
    _focusNodeById(nodeId) {
        const target = this.nodes.find(n => n.userData && n.userData.id === nodeId);
        if (!target) {
            // Try numeric comparison
            const numId = typeof nodeId === 'string' ? parseInt(nodeId, 10) : nodeId;
            const target2 = this.nodes.find(n => n.userData && (n.userData.id === numId || parseInt(n.userData.id, 10) === numId));
            if (target2) {
                this.networkManager.setFocusedNode(target2);
                this.playGlitch();
                return;
            }
            return;
        }
        this.networkManager.setFocusedNode(target);
        this.playGlitch();
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

    /**
     * Smoothly slide the camera + orbit target so a tour node sits centered.
     * Preserves the current orbit offset so the framing feels continuous.
     * Returns a Promise that resolves when the animation finishes.
     */
/**
     * Detect spotlight-target changes and ease the strength 0→1 over ~600ms.
     * Other consumers read this._tourSpotlightStrength to lerp their effects
     * (scale boost, emissive boost, halo opacity, label opacity, dim of
     * non-spotlight nodes). When the spotlight clears, strength drops to 0
     * immediately — symmetry with the card disappearing.
     */
    updateTourSpotlightStrength() {
        const FADE_MS = 600;
        if (this._lastSpotlightNode !== this._tourSpotlightNode) {
            this._lastSpotlightNode = this._tourSpotlightNode;
            this._tourSpotlightChangedAt = performance.now();
        }
        if (!this._tourSpotlightNode) {
            this._tourSpotlightStrength = 0;
            return;
        }
        const elapsed = performance.now() - (this._tourSpotlightChangedAt || 0);
        const t = Math.min(1, Math.max(0, elapsed / FADE_MS));
        // smoothstep
        this._tourSpotlightStrength = t * t * (3 - 2 * t);
    }

    /**
     * Create the additive halo sprite once. Lives in the scene and is moved
     * onto the spotlight node each frame; hidden when no spotlight is set.
     */
    _initTourHalo() {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 128;
        const ctx = canvas.getContext('2d');
        const grad = ctx.createRadialGradient(64, 64, 4, 64, 64, 64);
        grad.addColorStop(0.0, 'rgba(255, 255, 220, 1.0)');
        grad.addColorStop(0.25, 'rgba(255, 220, 150, 0.55)');
        grad.addColorStop(0.6, 'rgba(255, 170, 100, 0.18)');
        grad.addColorStop(1.0, 'rgba(255, 140, 80, 0.0)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 128, 128);
        const tex = new THREE.CanvasTexture(canvas);
        tex.colorSpace = THREE.SRGBColorSpace;
        this._tourHaloSprite = new THREE.Sprite(new THREE.SpriteMaterial({
            map: tex,
            transparent: true,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            opacity: 0,
        }));
        this._tourHaloSprite.scale.set(8, 8, 1);
        this._tourHaloSprite.renderOrder = 100;
        this._tourHaloSprite.raycast = () => {};
        this._tourHaloSprite.visible = false;
        this.scene.add(this._tourHaloSprite);
    }

    /**
     * Position + opacity-pulse the tour halo each frame. Cheap; does nothing
     * when no node is in spotlight. Call from animate(), after updateNodes.
     */
    updateTourHalo() {
        if (!this._tourSpotlightNode) {
            if (this._tourHaloSprite) this._tourHaloSprite.visible = false;
            return;
        }
        if (!this._tourHaloSprite) this._initTourHalo();
        const halo = this._tourHaloSprite;
        const node = this._tourSpotlightNode;
        node.getWorldPosition(this._scratchVec);
        halo.position.copy(this._scratchVec);
        halo.visible = true;
        const t = performance.now() * 0.001;
        const pulse = 0.5 + Math.sin(t * 3.0) * 0.5;
        const strength = this._tourSpotlightStrength || 0;
        halo.material.opacity = (0.55 + pulse * 0.4) * strength;
        // Scale eases from a small starting size to the full pulsing size.
        const fullSize = 7 + pulse * 2.5;
        const startSize = 2.5;
        halo.scale.setScalar(startSize + strength * (fullSize - startSize));
    }

    /** DOM-anchored name label that follows the spotlight node during the camera pan. */
    _initTourLabel() {
        const el = document.createElement('div');
        el.id = 'tour-spotlight-label';
        el.style.cssText = [
            'position:fixed', 'z-index:255', 'pointer-events:none',
            'transform:translate(-50%, -100%)',
            'background:rgba(0,0,0,0.65)',
            'color:#fff',
            'padding:6px 14px',
            'border-radius:6px',
            'font-size:14px',
            'font-weight:600',
            'letter-spacing:0.02em',
            'border:1px solid rgba(255,255,255,0.25)',
            'box-shadow:0 4px 16px rgba(0,0,0,0.5)',
            'transition:opacity 250ms ease-out',
            'opacity:0',
            'white-space:nowrap',
            'max-width:60vw',
            'overflow:hidden',
            'text-overflow:ellipsis',
        ].join(';');
        document.body.appendChild(el);
        this._tourLabelEl = el;
    }

    /**
     * Project the spotlight node to screen space and place the name label
     * just above it. Hidden when the rich-media card is open (the card
     * already shows the name) or when the node is behind the camera.
     */
    updateTourLabel() {
        if (!this._tourSpotlightNode) {
            if (this._tourLabelEl) this._tourLabelEl.style.opacity = '0';
            return;
        }
        const rmOverlay = document.getElementById('rich-media-overlay');
        const cardVisible = rmOverlay && !rmOverlay.classList.contains('hidden');
        if (cardVisible) {
            if (this._tourLabelEl) this._tourLabelEl.style.opacity = '0';
            return;
        }
        if (!this._tourLabelEl) this._initTourLabel();
        const label = this._tourLabelEl;
        const node = this._tourSpotlightNode;
        node.getWorldPosition(this._scratchVec);
        this._scratchVec.project(this.camera);
        if (this._scratchVec.z > 1) {
            label.style.opacity = '0';
            return;
        }
        const x = (this._scratchVec.x + 1) * 0.5 * window.innerWidth;
        const y = (1 - this._scratchVec.y) * 0.5 * window.innerHeight;
        const name = node.userData?.name || '';
        if (label.textContent !== name) label.textContent = name;
        label.style.left = x + 'px';
        label.style.top = (y - 36) + 'px';
        label.style.opacity = String(this._tourSpotlightStrength || 0);
    }

    tourFocusOnNode(node, durationMs = 1400) {
        return new Promise((resolve) => {
            if (!node || !this.camera || !this.controls) {
                resolve();
                return;
            }
            const targetWorld = new THREE.Vector3();
            node.getWorldPosition(targetWorld);

            const camStart = this.camera.position.clone();
            const tgtStart = this.controls.target.clone();
            const offset = camStart.clone().sub(tgtStart);
            const camEnd = targetWorld.clone().add(offset);
            const tgtEnd = targetWorld.clone();

            // Quadratic Bezier control point: offset perpendicular to the straight path
            // so the camera arcs instead of dollying in a straight line. Random sign +
            // small vertical wobble keeps consecutive stops from arcing identically.
            const direction = camEnd.clone().sub(camStart);
            const distance = direction.length();
            const swingDir = distance > 0.001
                ? direction.clone().normalize().cross(new THREE.Vector3(0, 1, 0))
                : new THREE.Vector3(1, 0, 0);
            if (swingDir.lengthSq() < 0.001) swingDir.set(1, 0, 0);
            swingDir.normalize();
            if (Math.random() < 0.5) swingDir.negate();
            const verticalSign = Math.random() < 0.5 ? 1 : -1;
            const camControl = camStart.clone().lerp(camEnd, 0.5)
                .add(swingDir.multiplyScalar(distance * 0.4))
                .add(new THREE.Vector3(0, distance * 0.18 * verticalSign, 0));

            const wasEnabled = this.controls.enabled;
            this.controls.enabled = false;
            this._tourTweening = true;

            const startTime = performance.now();
            const tick = () => {
                const raw = Math.min(1, (performance.now() - startTime) / durationMs);
                const eased = raw < 0.5 ? 4 * raw * raw * raw : 1 - Math.pow(-2 * raw + 2, 3) / 2;

                const u = 1 - eased;
                this.camera.position
                    .copy(camStart).multiplyScalar(u * u)
                    .add(camControl.clone().multiplyScalar(2 * u * eased))
                    .add(camEnd.clone().multiplyScalar(eased * eased));

                this.controls.target.lerpVectors(tgtStart, tgtEnd, eased);
                this.camera.lookAt(this.controls.target);

                if (raw < 1) {
                    requestAnimationFrame(tick);
                } else {
                    this._tourTweening = false;
                    this.controls.enabled = wasEnabled;
                    resolve();
                }
            };
            requestAnimationFrame(tick);
        });
    }
}

export { TelarisNetwork };
