/**
 * Idle node spotlight: when the visitor has been idle for N seconds, fly the
 * camera to a random wormhole, open its info card, and either let media play
 * to its 'ended' event or hold for a dwell timer (with the visible bar).
 * After the card closes, restart the idle watch and pick another node.
 *
 * Reuses TelarisNetwork's tourFocusOnNode + showRichMediaWindow + halo so the
 * visual is identical to the auto-tour. Doesn't conflict with the auto-tour
 * controller — both watch idle independently; if both fire near the same time
 * the second one's start() bails because the rich-media-overlay is already up.
 *
 * Configured per galaxy via window.TELARIS_IDLE_SPOTLIGHT_CONFIG.
 * Disabled on phones (matches auto-tour's mobile gating).
 */

import { MOBILE_MIN_WIDTH, computeDwellSeconds, DwellBar } from './tour-shared.js';

export class IdleSpotlightController {
    constructor(app, config) {
        this.app = app;
        this.config = config || {};
        this.idleTimerId = null;
        this.dwellTimerId = null;
        this.dwellBar = new DwellBar();
        this.attachedMedia = null;
        this.attachedOnEnded = null;
        this.busy = false;
        this.cancelled = false;
        this.boundOnUserInteraction = this.onUserInteraction.bind(this);
        this.boundOnCardCloseIntent = this.onCardCloseIntent.bind(this);
    }

    init() {
        if (!this.config || !this.config.enabled) return;
        if (window.innerWidth < MOBILE_MIN_WIDTH) return;
        this.armIdleWatch();
    }

    armIdleWatch() {
        if (this.cancelled) return;
        const seconds = Math.max(1, this.config.idle_seconds || 30);
        const events = ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart'];
        const reset = () => {
            if (this.busy) return;
            clearTimeout(this.idleTimerId);
            this.idleTimerId = setTimeout(() => this.fire(), seconds * 1000);
        };
        // Stash so we can detach if needed.
        if (!this._listenerEvents) {
            this._listenerEvents = events;
            events.forEach(ev => window.addEventListener(ev, reset, { passive: true }));
            this._listenerReset = reset;
        }
        reset();
    }

    pickNode() {
        const allNodes = (this.app && Array.isArray(this.app.nodes)) ? this.app.nodes : [];
        const visible = allNodes.filter(n =>
            n && n.userData && n.userData.id != null && n.userData.node_type !== 'cluster'
        );
        let pool = visible;
        if (this.config.selection === 'accentuated') {
            pool = visible.filter(n => !!n.userData.is_accentuated);
        }
        if (pool.length === 0) return null;
        return pool[Math.floor(Math.random() * pool.length)];
    }

    async fire() {
        if (this.busy || this.cancelled) return;
        // Don't compete with the rich-media-window if the user (or tour) already opened one.
        const overlay = document.getElementById('rich-media-overlay');
        if (overlay && !overlay.classList.contains('hidden')) {
            this.armIdleWatch();
            return;
        }
        const node = this.pickNode();
        if (!node) {
            this.armIdleWatch();
            return;
        }
        this.busy = true;
        if (this.app) this.app._tourSpotlightNode = node;
        if (this.app?.networkManager?.setFocusedNode) {
            this.app.networkManager.setFocusedNode(node);
        }

        if (this.app?.tourFocusOnNode) {
            await this.app.tourFocusOnNode(node, 1400);
            if (this.cancelled) { this.busy = false; return; }
        }
        if (this.app?.showRichMediaWindow) {
            this.app.showRichMediaWindow(node);
        }
        // Hook close-X / backdrop so user can dismiss and restart idle watch.
        const closeBtn = document.getElementById('rm-close-btn');
        if (closeBtn) closeBtn.addEventListener('click', this.boundOnCardCloseIntent);
        if (overlay) overlay.addEventListener('click', this.boundOnCardCloseIntent);

        // Wait for the card's media DOM to be wired up.
        setTimeout(() => {
            if (this.cancelled) return;
            this.attachMediaOrDwell(node);
        }, 50);
    }

    attachMediaOrDwell(node) {
        const data = node?.userData || {};
        const audio = data.audio_url ? document.getElementById('rm-audio') : null;
        const video = data.video_url ? document.getElementById('rm-video') : null;
        const media = video || audio;

        const baseDwell = Math.max(1, this.config.default_dwell || 8);
        const visibleDwellSec = computeDwellSeconds(node, baseDwell);

        if (!media) {
            this.scheduleDwellEnd(visibleDwellSec * 1000);
            return;
        }

        const onEnded = () => {
            media.removeEventListener('ended', onEnded);
            this.attachedMedia = null;
            this.attachedOnEnded = null;
            this.endStop();
        };
        media.addEventListener('ended', onEnded);
        this.attachedMedia = media;
        this.attachedOnEnded = onEnded;

        // Visible default-dwell countdown until play resolves; replaced with a
        // hidden failsafe sized to media.duration once playback starts.
        this.scheduleDwellEnd(visibleDwellSec * 1000);

        const playPromise = media.play();
        const onPlaySuccess = () => {
            this.dwellBar.cancel();
            if (this.dwellTimerId) { clearTimeout(this.dwellTimerId); this.dwellTimerId = null; }
            const arm = () => {
                const seconds = (isFinite(media.duration) && media.duration > 0)
                    ? media.duration + 3
                    : baseDwell * 4;
                this.dwellTimerId = setTimeout(() => {
                    this.dwellTimerId = null;
                    this.endStop();
                }, seconds * 1000);
            };
            if (media.readyState >= 1) arm();
            else media.addEventListener('loadedmetadata', arm, { once: true });
        };
        if (playPromise && typeof playPromise.then === 'function') {
            playPromise.then(onPlaySuccess).catch(() => { /* autoplay blocked, dwell bar handles it */ });
        } else {
            onPlaySuccess();
        }
    }

    scheduleDwellEnd(durationMs) {
        if (this.dwellTimerId) { clearTimeout(this.dwellTimerId); this.dwellTimerId = null; }
        this.dwellBar.cancel();
        this.dwellTimerId = setTimeout(() => {
            this.dwellTimerId = null;
            this.endStop();
        }, durationMs);
        this.dwellBar.start(durationMs);
    }

    /** Close the card, clear spotlight, and restart the idle watch. */
    endStop() {
        if (this.dwellTimerId) { clearTimeout(this.dwellTimerId); this.dwellTimerId = null; }
        this.dwellBar.cancel();
        if (this.attachedMedia && this.attachedOnEnded) {
            this.attachedMedia.removeEventListener('ended', this.attachedOnEnded);
        }
        this.attachedMedia = null;
        this.attachedOnEnded = null;
        const closeBtn = document.getElementById('rm-close-btn');
        const overlay = document.getElementById('rich-media-overlay');
        if (closeBtn) closeBtn.removeEventListener('click', this.boundOnCardCloseIntent);
        if (overlay) overlay.removeEventListener('click', this.boundOnCardCloseIntent);
        if (this.app?.closeRichMediaWindow) this.app.closeRichMediaWindow();
        if (this.app) this.app._tourSpotlightNode = null;
        this.busy = false;
        this.armIdleWatch();
    }

    onCardCloseIntent(e) {
        // User dismissed the card. Treat as end-of-stop.
        const overlay = document.getElementById('rich-media-overlay');
        if (e.currentTarget === overlay && e.target !== overlay) return;
        // Don't double-call closeRichMediaWindow — the existing handlers will fire.
        if (this.dwellTimerId) { clearTimeout(this.dwellTimerId); this.dwellTimerId = null; }
        this.dwellBar.cancel();
        if (this.attachedMedia && this.attachedOnEnded) {
            this.attachedMedia.removeEventListener('ended', this.attachedOnEnded);
        }
        this.attachedMedia = null;
        this.attachedOnEnded = null;
        const closeBtn = document.getElementById('rm-close-btn');
        if (closeBtn) closeBtn.removeEventListener('click', this.boundOnCardCloseIntent);
        if (overlay) overlay.removeEventListener('click', this.boundOnCardCloseIntent);
        if (this.app) this.app._tourSpotlightNode = null;
        this.busy = false;
        this.armIdleWatch();
    }

    onUserInteraction() {
        // Hook reserved for future "exit on first interaction" behavior.
    }
}
