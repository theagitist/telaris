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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTelaris);
} else {
    initTelaris();
}
