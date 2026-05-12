/**
 * Telaris main entry: initializes the 3D network when DOM is ready.
 * Maintains navigation stack: push current constellation ID when a portal is clicked;
 * Back button pops and loads the previous constellation when stack length > 1.
 *
 * Raycaster (onMouseMove cursor + onClick/onMouseDown navigation) lives in
 * TelarisNetwork in js/telaris-3d.js; it uses intersectObjects(..., true)
 * and bubble-up userData lookup so portal Torus wires are hit correctly.
 */

import { TelarisNetwork } from './telaris-3d.js';
import { TourController } from './auto-tour.js';
import { KeywordChipsController } from './keyword-chips.js';
import { IdleSpotlightController } from './idle-spotlight.js';
import { GalaxyListStripController } from './galaxy-list-strip.js';
import { WormholeGrid2D } from './wormhole-grid-2d.js';

/** Constellation ID history for portal Back navigation. Set in initTelaris from URL or 0. */
let navigationStack;

function initTelaris() {
    try {
        const initialId = window.TELARIS_CONSTELLATION_ID ?? 0;
        navigationStack = [initialId];
        const canvasContainer = document.getElementById('canvas-container');
        if (!canvasContainer) {
            console.error('Canvas container not found!');
            return;
        }
        const app = new TelarisNetwork();
        app.navigationStack = navigationStack;
        window.telarisApp = app;

        startTourWhenReady(app);

        if (window.TELARIS_KEYWORD_CHIPS_ENABLED) {
            const chips = new KeywordChipsController(app);
            chips.init();
            window.telarisKeywordChips = chips;
        }

        if (window.TELARIS_GALAXY_LIST_ENABLED) {
            const strip = new GalaxyListStripController(app);
            strip.init();
            window.telarisGalaxyListStrip = strip;
        }

        const idleCfg = window.TELARIS_IDLE_SPOTLIGHT_CONFIG;
        if (idleCfg && idleCfg.enabled) {
            startIdleSpotlightWhenReady(app, idleCfg);
        }

        if (window.TELARIS_INITIAL_NODE_ID) {
            openInitialNodeWhenReady(app, parseInt(window.TELARIS_INITIAL_NODE_ID, 10));
        }

        // 2D wormhole grid — alternative visitor view. Reset to 3D on every
        // page load (we want 3D to stay the primary surface). The switch
        // toggles the WebGL wrapper off + the grid container on.
        const grid = new WormholeGrid2D(app);
        window.telarisGrid2D = grid;
        wireViewModeSwitch(app, grid);
        // Re-render the grid whenever the underlying node set changes (cluster
        // drill, back navigation). loadDataForConstellation is the single
        // funnel for new node data, so a small wrapper around it suffices.
        patchAppForGridRefresh(app, grid);
    } catch (error) {
        console.error('Error initializing TelarisNetwork:', error);
        console.error('Error stack:', error.stack);
    }
}

function openInitialNodeWhenReady(app, nodeId) {
    if (!nodeId || isNaN(nodeId)) return;
    let attempts = 0;
    const maxAttempts = 100;
    const tick = () => {
        attempts++;
        if (Array.isArray(app.nodes) && app.nodes.length > 0) {
            const node = app.nodes.find(n =>
                n && n.userData && (n.userData.id === nodeId || parseInt(n.userData.id, 10) === nodeId)
            );
            if (node) {
                // Permalink loads should show the target instantly. Force-complete the load-fade
                // so nodes aren't stuck at opacity 0 while the camera flies — observed reliably
                // when the loading-torus warp + node fade-in animation race with the auto-open.
                app._portalFadeInMultiplier = undefined;
                // Small delay so the scene has settled visually before the card flies in.
                setTimeout(() => {
                    if (app.networkManager?.setFocusedNode) app.networkManager.setFocusedNode(node);
                    if (app.tourFocusOnNode) {
                        app.tourFocusOnNode(node, 1200).then(() => {
                            if (app.showRichMediaWindow) app.showRichMediaWindow(node);
                        });
                    } else if (app.showRichMediaWindow) {
                        app.showRichMediaWindow(node);
                    }
                }, 600);
            }
            return;
        }
        if (attempts < maxAttempts) setTimeout(tick, 100);
    };
    setTimeout(tick, 100);
}

function startIdleSpotlightWhenReady(app, cfg) {
    let attempts = 0;
    const maxAttempts = 100;
    const tick = () => {
        attempts++;
        if (Array.isArray(app.nodes) && app.nodes.length > 0) {
            const ctrl = new IdleSpotlightController(app, cfg);
            ctrl.init();
            window.telarisIdleSpotlight = ctrl;
            return;
        }
        if (attempts < maxAttempts) setTimeout(tick, 100);
    };
    setTimeout(tick, 100);
}

function startTourWhenReady(app) {
    const cfg = window.TELARIS_TOUR_CONFIG;
    if (!cfg || !cfg.tour_enabled) return;

    let attempts = 0;
    const maxAttempts = 100;
    const tick = () => {
        attempts++;
        if (Array.isArray(app.nodes) && app.nodes.length > 0) {
            const tour = new TourController(app, cfg);
            tour.init();
            window.telarisTour = tour;
            return;
        }
        if (attempts < maxAttempts) {
            setTimeout(tick, 100);
        }
    };
    setTimeout(tick, 100);
}

const VIEW_MODE_STORAGE_KEY = 'telaris.viewMode';

function wireViewModeSwitch(app, grid) {
    const switchEl = document.getElementById('view-mode-switch');
    const btn3d = document.getElementById('view-mode-3d');
    const btn2d = document.getElementById('view-mode-2d');
    const webglWrap = document.getElementById('webgl-canvas-wrapper');
    // Switch element is only rendered when the per-galaxy show_2d_view flag is
    // on (see main-view.php). If absent we exit and the 3D view runs as it
    // always has — no toggle visible.
    if (!switchEl || !btn3d || !btn2d || !webglWrap) return;

    // 3D-only overlays — these need to disappear when 2D takes over. The
    // hover tooltip (#node-tooltip) and its connector (#tooltip-line-svg)
    // are exceptions: 2D re-uses both for its own card-hover panel + line
    // (see wormhole-grid-2d.js).
    const overlayIds = ['persistent-tooltips', 'cluster-breadcrumb'];
    const previousDisplay = new Map();

    const setMode = (mode) => {
        const is2d = (mode === '2d');
        btn3d.setAttribute('aria-pressed', is2d ? 'false' : 'true');
        btn2d.setAttribute('aria-pressed', is2d ? 'true' : 'false');
        btn3d.style.color = is2d ? '#fff' : '#000';
        btn3d.style.background = is2d ? 'transparent' : '#fff';
        btn2d.style.color = is2d ? '#000' : '#fff';
        btn2d.style.background = is2d ? '#fff' : 'transparent';
        for (const id of overlayIds) {
            const el = document.getElementById(id);
            if (!el) continue;
            if (is2d) {
                if (!previousDisplay.has(id)) previousDisplay.set(id, el.style.display);
                el.style.display = 'none';
            } else {
                el.style.display = previousDisplay.get(id) || '';
            }
        }
        // Hide the hover tooltip + connector line on every mode switch so
        // stale state doesn't linger across modes. Each mode re-shows them
        // via its own hover code.
        const tip = document.getElementById('node-tooltip');
        if (tip) { tip.style.opacity = '0'; tip.style.visibility = 'hidden'; }
        const tipLineSvg = document.getElementById('tooltip-line-svg');
        if (tipLineSvg) tipLineSvg.style.opacity = '0';
        if (is2d) {
            webglWrap.style.visibility = 'hidden';
            grid.setActive(true);
        } else {
            webglWrap.style.visibility = '';
            grid.setActive(false);
        }
        // Persist visitor preference (only stored when the switch is wired,
        // i.e. when this galaxy enables 2D).
        try { localStorage.setItem(VIEW_MODE_STORAGE_KEY, mode); } catch (e) { /* private mode */ }
    };

    btn3d.addEventListener('click', () => setMode('3d'));
    btn2d.addEventListener('click', () => setMode('2d'));
    // Restore the visitor's preferred mode. Only honour '2d' — anything else
    // (or absent / private mode) falls through to the 3D default.
    let initial = '3d';
    try {
        if (localStorage.getItem(VIEW_MODE_STORAGE_KEY) === '2d') initial = '2d';
    } catch (e) { /* ignore */ }
    setMode(initial);
}

function patchAppForGridRefresh(app, grid) {
    if (!app || !app.loadDataForConstellation) return;
    const original = app.loadDataForConstellation.bind(app);
    app.loadDataForConstellation = async function (...args) {
        const result = await original(...args);
        // Refresh the grid even if it's currently hidden — sometimes the
        // visitor swaps modes after a cluster drill, and we want the grid
        // to be up-to-date when they do. refresh() bails cheaply if the
        // node list is empty.
        if (grid && grid.isActive()) grid.refresh();
        return result;
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTelaris);
} else {
    initTelaris();
}
