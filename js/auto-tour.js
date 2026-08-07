/**
 * Auto-tour controller. Plays a sequence of nodes by opening each rich-media card
 * and advancing when the node's media (audio or video) ends, or after a configured
 * dwell for nodes with no waitable media.
 *
 * Configured per galaxy via window.TELARIS_TOUR_CONFIG. Disabled on phones.
 */

import { MOBILE_MIN_WIDTH, delay, computeDwellSeconds, DwellBar } from './tour-shared.js';

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
        this.boundOnCardCloseIntent = this.onCardCloseIntent.bind(this);
        this.boundOnSceneInteract = this.onSceneInteract.bind(this);
    }

    /** The 3D scene canvas (OrbitControls target), or null before it exists. */
    get sceneCanvas() {
        return (this.app && this.app.renderer && this.app.renderer.domElement) || null;
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
        // Interacting with the scene (rotate/zoom/tap) stops the tour at once.
        // Bound to the canvas only, so clicks on the HUD or media card (which
        // sit above it) drive the tour instead of quitting it. pointerdown /
        // wheel / touchstart are deliberate actions; passive hover is ignored.
        const canvas = this.sceneCanvas;
        if (canvas) {
            canvas.addEventListener('pointerdown', this.boundOnSceneInteract);
            canvas.addEventListener('pointermove', this.boundOnSceneInteract, { passive: true });
            canvas.addEventListener('wheel', this.boundOnSceneInteract, { passive: true });
            canvas.addEventListener('touchstart', this.boundOnSceneInteract, { passive: true });
        }
        const closeBtn = document.getElementById('rm-close-btn');
        const overlay = document.getElementById('rich-media-overlay');
        if (closeBtn) closeBtn.addEventListener('click', this.boundOnCardCloseIntent);
        if (overlay) overlay.addEventListener('click', this.boundOnCardCloseIntent);
        this.playCurrent();
    }

    exit({ closeCard = true } = {}) {
        this.cancelled = true;
        this.active = false;
        this.clearDwellTimer();
        this.detachMediaListeners();
        this.hideHud();
        if (this.app) this.app._tourSpotlightNode = null;
        document.removeEventListener('keydown', this.boundOnKeydown);
        const canvas = this.sceneCanvas;
        if (canvas) {
            canvas.removeEventListener('pointerdown', this.boundOnSceneInteract);
            canvas.removeEventListener('pointermove', this.boundOnSceneInteract);
            canvas.removeEventListener('wheel', this.boundOnSceneInteract);
            canvas.removeEventListener('touchstart', this.boundOnSceneInteract);
        }
        const closeBtn = document.getElementById('rm-close-btn');
        const overlay = document.getElementById('rich-media-overlay');
        if (closeBtn) closeBtn.removeEventListener('click', this.boundOnCardCloseIntent);
        if (overlay) overlay.removeEventListener('click', this.boundOnCardCloseIntent);
        if (closeCard && this.app?.closeRichMediaWindow) {
            this.app.closeRichMediaWindow();
        }
        if ((this.config.tour_start_mode || 'manual') === 'manual') {
            this.showPlayButton();
        }
    }

    onCardCloseIntent(e) {
        // The user closed the card themselves (close-X or backdrop click).
        // Treat this as "I'm done with this card, move on" — same effect as the
        // dwell timer running out. Use the HUD exit button to actually quit.
        const overlay = document.getElementById('rich-media-overlay');
        if (e.currentTarget === overlay && e.target !== overlay) return;
        this.clearDwellTimer();
        this.detachMediaListeners();
        if (this.active && !this.paused) this.advance(1);
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

    async playCurrent() {
        if (this.cancelled) return;
        const node = this.queue[this.position];
        if (!node) return;

        this.updateProgress();

        // Close the previous card first so the camera animation is visible.
        // closeRichMediaWindow clears _tourSpotlightNode, so we set the new
        // spotlight AFTER the close.
        const overlay = document.getElementById('rich-media-overlay');
        const cardOpen = overlay && !overlay.classList.contains('hidden');
        if (cardOpen && this.app?.closeRichMediaWindow) {
            this.app.closeRichMediaWindow();
            await delay(500);
            if (this.cancelled || !this.active) return;
        }

        // Tag the next node for the spotlight pulse so users can see which one
        // the camera is heading to before the card opens.
        if (this.app) this.app._tourSpotlightNode = node;
        if (this.app?.networkManager?.setFocusedNode) {
            this.app.networkManager.setFocusedNode(node);
        }

        if (this.app?.tourFocusOnNode) {
            await this.app.tourFocusOnNode(node, 1400);
            if (this.cancelled || !this.active) return;
        }

        if (this.app?.showRichMediaWindow) {
            this.app.showRichMediaWindow(node);
        }

        // Wait for the card's media DOM to be wired up by showRichMediaWindow.
        setTimeout(() => {
            if (this.cancelled || !this.active) return;
            this.attachMediaOrDwell(node);
        }, 50);
    }

    attachMediaOrDwell(node) {
        const data = node?.userData || {};
        const audio = data.audio_url ? document.getElementById('rm-audio') : null;
        const video = data.video_url ? document.getElementById('rm-video') : null;
        const media = video || audio;

        const baseDwell = Math.max(1, this.config.tour_default_dwell || 8);
        const visibleDwellSec = computeDwellSeconds(node, baseDwell);

        if (!media) {
            this.scheduleDwellAdvance(visibleDwellSec * 1000);
            return;
        }

        if (media === video) this.attachedVideo = true;
        else this.attachedAudio = true;

        const onEnded = () => {
            media.removeEventListener('ended', onEnded);
            this.attachedAudio = null;
            this.attachedVideo = null;
            this.clearDwellTimer();
            if (this.active && !this.paused) this.advance(1);
        };
        media.addEventListener('ended', onEnded);

        // Always start a visible reading-time countdown. This covers the
        // autoplay-blocked case immediately and guarantees forward progress
        // before metadata loads. If play succeeds, we replace it with a
        // hidden failsafe matched to the media's real duration.
        this.scheduleDwellAdvance(visibleDwellSec * 1000);

        const onPlaySuccess = () => {
            // Hide the visible bar; replace with a hidden failsafe that lasts
            // the full media length + 3s, so 'ended' normally fires first.
            this.dwellBar.cancel();
            if (this.dwellTimerId) {
                clearTimeout(this.dwellTimerId);
                this.dwellTimerId = null;
            }
            const armFailsafe = () => {
                const seconds = (isFinite(media.duration) && media.duration > 0)
                    ? media.duration + 3
                    : baseDwell * 4;
                this.dwellTimerId = setTimeout(() => {
                    this.dwellTimerId = null;
                    if (this.active && !this.paused) this.advance(1);
                }, seconds * 1000);
            };
            if (media.readyState >= 1) {
                armFailsafe();
            } else {
                media.addEventListener('loadedmetadata', armFailsafe, { once: true });
            }
        };

        const playPromise = media.play();
        if (playPromise && typeof playPromise.then === 'function') {
            playPromise.then(onPlaySuccess).catch(() => {
                // Autoplay blocked. Leave the visible default-dwell bar alone —
                // tour advances when it empties.
            });
        } else {
            // Older browsers without a returned promise: assume play succeeded.
            onPlaySuccess();
        }
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

    onSceneInteract() {
        // Any deliberate scene interaction ends the tour immediately. In idle
        // mode the persistent idle-watch listeners re-arm the countdown after
        // this, so the tour can resume once the visitor goes quiet again.
        if (this.active) this.exit();
    }
}
