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

        const idleCfg = window.TELARIS_IDLE_SPOTLIGHT_CONFIG;
        if (idleCfg && idleCfg.enabled) {
            startIdleSpotlightWhenReady(app, idleCfg);
        }
    } catch (error) {
        console.error('Error initializing TelarisNetwork:', error);
        console.error('Error stack:', error.stack);
    }
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
