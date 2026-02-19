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

/** Constellation ID history for portal Back navigation. Set in initTelaris from URL or 0. */
let navigationStack;

function initTelaris() {
    try {
        navigationStack = [];
        const canvasContainer = document.getElementById('canvas-container');
        if (!canvasContainer) {
            console.error('Canvas container not found!');
            return;
        }
        const app = new TelarisNetwork();
        app.navigationStack = navigationStack;
        window.telarisApp = app;
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
