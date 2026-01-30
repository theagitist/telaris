/**
 * Telaris main entry: initializes the 3D network when DOM is ready.
 */

import { TelarisNetwork } from './telaris-network.js';

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
