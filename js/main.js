/**
 * Telaris main entry: initializes the 3D network when DOM is ready.
 * Maintains navigation stack: push current constellation ID when a portal is clicked;
 * Back button pops and loads the previous constellation when stack length > 1.
 *
 * Raycaster (onMouseMove cursor + onClick/onMouseDown navigation) lives in
 * TelarisNetwork in js/telaris-network.js; it uses intersectObjects(..., true)
 * and bubble-up userData lookup so portal Torus wires are hit correctly.
 */

import { TelarisNetwork } from './telaris-network.js';
import { TourController } from './tour.js';

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
    } catch (error) {
        console.error('Error initializing TelarisNetwork:', error);
        console.error('Error stack:', error.stack);
    }
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
