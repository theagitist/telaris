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

const MOBILE_MIN_WIDTH = 768;

// Pastel palette shared with the auto-tour dwell bar so cycles stay visually consistent.
const DWELL_BAR_COLORS = [
    '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
    '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
    '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af',
];

function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

export class IdleSpotlightController {
    constructor(app, config) {
        this.app = app;
        this.config = config || {};
        this.idleTimerId = null;
        this.dwellTimerId = null;
        this.dwellAnimation = null;
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
        const visibleDwellSec = this.computeDwellSeconds(node, baseDwell);

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
            this.cancelDwellBar();
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

    /** Reading-time estimate (180 wpm + 2s settle) bounded by baseDwell. */
    computeDwellSeconds(node, baseDwell) {
        const text = node?.userData?.description || '';
        if (!text) return baseDwell;
        const stripped = String(text).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        if (!stripped) return baseDwell;
        const words = stripped.split(/\s+/).length;
        const seconds = (words / 180) * 60;
        return Math.max(baseDwell, seconds + 2);
    }

    scheduleDwellEnd(durationMs) {
        if (this.dwellTimerId) { clearTimeout(this.dwellTimerId); this.dwellTimerId = null; }
        this.cancelDwellBar();
        this.dwellTimerId = setTimeout(() => {
            this.dwellTimerId = null;
            this.endStop();
        }, durationMs);
        this.startDwellBar(durationMs);
    }

    startDwellBar(durationMs) {
        const track = document.getElementById('tour-dwell-bar-track');
        const bar = document.getElementById('tour-dwell-bar');
        if (!track || !bar) return;
        track.classList.remove('hidden');
        bar.style.backgroundColor = DWELL_BAR_COLORS[Math.floor(Math.random() * DWELL_BAR_COLORS.length)];
        bar.style.transform = 'scaleX(1)';
        if (this.dwellAnimation) { this.dwellAnimation.cancel(); this.dwellAnimation = null; }
        if (typeof bar.animate !== 'function') return;
        this.dwellAnimation = bar.animate(
            [{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }],
            { duration: durationMs, easing: 'linear', fill: 'forwards' }
        );
    }

    cancelDwellBar() {
        if (this.dwellAnimation) { this.dwellAnimation.cancel(); this.dwellAnimation = null; }
        const track = document.getElementById('tour-dwell-bar-track');
        if (track) track.classList.add('hidden');
    }

    /** Close the card, clear spotlight, and restart the idle watch. */
    endStop() {
        if (this.dwellTimerId) { clearTimeout(this.dwellTimerId); this.dwellTimerId = null; }
        this.cancelDwellBar();
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
        this.cancelDwellBar();
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
