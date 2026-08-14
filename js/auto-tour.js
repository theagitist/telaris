/**
 * Auto-tour controller. Non-blocking: walks a sequence of nodes, lighting each
 * one up (halo + floating name label + focus boost) for a configured dwell while
 * the visitor keeps freely exploring the scene. No camera move, no auto-card.
 *
 * Configured per galaxy via window.TELARIS_TOUR_CONFIG. Disabled on phones.
 */

import { MOBILE_MIN_WIDTH, computeDwellSeconds, DwellBar } from './tour-shared.js';

function shuffle(arr) {
    const out = arr.slice();
    for (let i = out.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [out[i], out[j]] = [out[j], out[i]];
    }
    return out;
}

export class TourController {
    /**
     * @param {Object} app - The TelarisNetwork instance.
     * @param {Object} config - window.TELARIS_TOUR_CONFIG.
     */
    constructor(app, config) {
        this.app = app;
        this.config = config || {};
        this.queue = [];
        this.position = 0;
        this.active = false;
        this.paused = false;
        this.dwellTimerId = null;
        this.dwellBar = new DwellBar();
        this.attachedAudio = null;
        this.attachedVideo = null;
        this.idleTimerId = null;
        this.cancelled = false;

        this.playBtn = document.getElementById('tour-play-btn');
        this.hud = document.getElementById('tour-hud');
        this.progressEl = document.getElementById('tour-hud-progress');
        this.progressBarEl = document.getElementById('tour-hud-progress-bar');
        this.pauseBtn = document.getElementById('tour-hud-pause');
        this.pauseIcon = document.getElementById('tour-hud-pause-icon');
        this.resumeIcon = document.getElementById('tour-hud-resume-icon');
        this.prevBtn = document.getElementById('tour-hud-prev');
        this.nextBtn = document.getElementById('tour-hud-next');
        this.exitBtn = document.getElementById('tour-hud-exit');

        this.boundOnKeydown = this.onKeydown.bind(this);
    }

    /** True while a wormhole info card is open over the map. */
    isCardOpen() {
        const overlay = document.getElementById('rich-media-overlay');
        return !!(overlay && !overlay.classList.contains('hidden'));
    }

    init() {
        if (!this.config || !this.config.tour_enabled) return;
        if (window.innerWidth < MOBILE_MIN_WIDTH) return;

        this.bindControls();

        // ?tour=preview overrides the configured start mode (used by the
        // editor's Preview button so admins can audition without flipping
        // the visitor's start setting).
        const params = new URLSearchParams(window.location.search);
        if (params.get('tour') === 'preview') {
            setTimeout(() => { if (!this.active && !this.cancelled && !this.isCardOpen()) this.start(); }, 1500);
            return;
        }

        const mode = this.config.tour_start_mode || 'manual';
        if (mode === 'manual') {
            this.showPlayButton();
        } else if (mode === 'idle') {
            this.startIdleWatch();
        } else if (mode === 'immediate') {
            // Brief grace period so the visitor can take in the scene before the
            // camera starts panning. Don't start if they've already kicked the tour
            // off some other way in the meantime.
            setTimeout(() => {
                if (!this.active && !this.cancelled && !this.isCardOpen()) this.start();
            }, 3000);
        }
    }

    bindControls() {
        this.playBtn?.addEventListener('click', () => this.start());
        this.pauseBtn?.addEventListener('click', () => this.togglePause());
        this.prevBtn?.addEventListener('click', () => this.advance(-1));
        this.nextBtn?.addEventListener('click', () => this.advance(1));
        this.exitBtn?.addEventListener('click', () => this.exit());
    }

    showPlayButton() {
        this.playBtn?.classList.remove('hidden');
    }

    hidePlayButton() {
        this.playBtn?.classList.add('hidden');
    }

    showHud() {
        this.hud?.classList.remove('hidden');
        this.hud?.classList.add('flex');
    }

    hideHud() {
        this.hud?.classList.add('hidden');
        this.hud?.classList.remove('flex');
    }

    startIdleWatch() {
        const seconds = Math.max(1, this.config.tour_idle_seconds || 30);
        // OrbitControls drives the scene with pointer events + continuous
        // touchmove; listening only to mousemove/mousedown missed drags and
        // pinch/pan, so the idle timer fired mid-interaction. Cover pointer and
        // touch move so any active interaction keeps resetting the countdown.
        const events = ['pointerdown', 'pointermove', 'wheel', 'keydown', 'touchstart', 'touchmove'];
        const reset = () => {
            if (this.active) return;
            clearTimeout(this.idleTimerId);
            this.idleTimerId = setTimeout(() => {
                // Only auto-start over a clear map: never cover an open info card.
                // If a card is open the visitor is reading it; the next interaction
                // (moving/closing) re-arms this timer, so it restarts once clear.
                if (!this.active && !this.isCardOpen()) this.start();
            }, seconds * 1000);
        };
        events.forEach(ev => window.addEventListener(ev, reset, { passive: true }));
        reset();
    }

    pickNodes() {
        const allNodes = (this.app && Array.isArray(this.app.nodes)) ? this.app.nodes : [];
        const visible = allNodes.filter(n => n && n.userData && n.userData.id != null && n.userData.node_type !== 'cluster');

        const selection = this.config.tour_node_selection || 'all';
        let pool = visible;

        if (selection === 'accentuated') {
            pool = visible.filter(n => !!n.userData.is_accentuated);
        } else if (selection === 'tagged') {
            const wanted = new Set((this.config.tour_keyword_names || []).map(s => s.toLowerCase()));
            if (wanted.size === 0) {
                pool = [];
            } else {
                pool = visible.filter(n => {
                    const kws = n.userData.keywords || [];
                    return kws.some(k => wanted.has(String(k).toLowerCase()));
                });
            }
        }

        let queue = shuffle(pool);

        if (selection === 'random_n') {
            const n = Math.max(1, this.config.tour_random_count || 10);
            queue = queue.slice(0, n);
        }

        return queue;
    }

    start() {
        if (this.active) return;
        const queue = this.pickNodes();
        if (queue.length === 0) {
            this.hidePlayButton();
            return;
        }
        this.queue = queue;
        this.position = 0;
        this.active = true;
        this.paused = false;
        this.cancelled = false;
        this.hidePlayButton();
        this.showHud();
        document.addEventListener('keydown', this.boundOnKeydown);
        // Non-blocking tour: scene interaction (rotate/zoom/tap) no longer stops
        // the tour, so the visitor keeps exploring while nodes light up. The tour
        // opens no card either. It exits only via Escape or the HUD exit button,
        // or by reaching the end of a non-looping queue.
        this.playCurrent();
    }

    exit() {
        this.cancelled = true;
        this.active = false;
        this.clearDwellTimer();
        this.detachMediaListeners();
        this.hideHud();
        if (this.app) this.app._tourSpotlightNode = null;
        document.removeEventListener('keydown', this.boundOnKeydown);
        // The tour opens no card of its own, so exit leaves any card the visitor
        // opened manually untouched.
        if ((this.config.tour_start_mode || 'manual') === 'manual') {
            this.showPlayButton();
        }
    }

    togglePause() {
        if (!this.active) return;
        const audio = document.getElementById('rm-audio');
        const video = document.getElementById('rm-video');
        if (this.paused) {
            this.paused = false;
            this.pauseIcon?.classList.remove('hidden');
            this.resumeIcon?.classList.add('hidden');
            if (this.attachedAudio && audio) audio.play().catch(() => {});
            if (this.attachedVideo && video) video.play().catch(() => {});
            this.dwellBar.resume();
            if (this._dwellRemainingMs != null) {
                const remaining = this._dwellRemainingMs;
                this._dwellRemainingMs = null;
                this.dwellTimerId = setTimeout(() => {
                    this.dwellTimerId = null;
                    if (this.active && !this.paused) this.advance(1);
                }, remaining);
            }
        } else {
            this.paused = true;
            this.pauseIcon?.classList.add('hidden');
            this.resumeIcon?.classList.remove('hidden');
            if (this.attachedAudio && audio) audio.pause();
            if (this.attachedVideo && video) video.pause();
            if (this.dwellTimerId) {
                const remaining = this.dwellBar.remainingMs();
                if (remaining != null) {
                    this._dwellRemainingMs = remaining;
                }
                this.dwellBar.pause();
                clearTimeout(this.dwellTimerId);
                this.dwellTimerId = null;
            }
        }
    }

    advance(direction) {
        if (!this.active) return;
        this.clearDwellTimer();
        this.detachMediaListeners();
        const next = this.position + direction;
        if (next < 0) {
            this.position = 0;
            this.playCurrent();
            return;
        }
        if (next >= this.queue.length) {
            if (this.config.tour_loop) {
                this.queue = shuffle(this.queue);
                this.position = 0;
                this.playCurrent();
            } else {
                this.exit();
            }
            return;
        }
        this.position = next;
        this.playCurrent();
    }

    playCurrent() {
        if (this.cancelled) return;
        const node = this.queue[this.position];
        if (!node) return;

        this.updateProgress();

        // Non-blocking tour: just illuminate the node (halo + floating name
        // label + focus boost) and let the visitor keep exploring. No camera
        // move, no auto-card. The spotlight visuals live in telaris-3d.js and
        // are driven per frame off _tourSpotlightNode / _tourSpotlightStrength.
        if (this.app) this.app._tourSpotlightNode = node;
        if (this.app?.networkManager?.setFocusedNode) {
            this.app.networkManager.setFocusedNode(node);
        }

        // No card means no waitable media, so every stop is a dwell.
        const baseDwell = Math.max(1, this.config.tour_default_dwell || 8);
        this.scheduleDwellAdvance(computeDwellSeconds(node, baseDwell) * 1000);
    }

    scheduleDwellAdvance(durationMs) {
        if (durationMs == null) {
            durationMs = Math.max(1, this.config.tour_default_dwell || 8) * 1000;
        }
        // Cancel any prior timer/bar before re-arming so we don't stack callbacks.
        if (this.dwellTimerId) {
            clearTimeout(this.dwellTimerId);
            this.dwellTimerId = null;
        }
        this.dwellBar.cancel();
        this.dwellTimerId = setTimeout(() => {
            this.dwellTimerId = null;
            if (this.active && !this.paused) this.advance(1);
        }, durationMs);
        this.dwellBar.start(durationMs);
    }

    clearDwellTimer() {
        if (this.dwellTimerId) {
            clearTimeout(this.dwellTimerId);
            this.dwellTimerId = null;
        }
        this.dwellBar.cancel();
    }

    detachMediaListeners() {
        this.attachedAudio = null;
        this.attachedVideo = null;
    }

    updateProgress() {
        if (this.progressEl) {
            this.progressEl.textContent = `${this.position + 1} / ${this.queue.length}`;
        }
        if (this.progressBarEl) {
            const pct = this.queue.length > 0 ? ((this.position + 1) / this.queue.length) * 100 : 0;
            this.progressBarEl.style.width = pct + '%';
        }
    }

    onKeydown(e) {
        if (e.key === 'Escape') {
            this.exit();
        }
    }
}
