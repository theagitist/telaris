/**
 * Auto-tour controller. Plays a sequence of nodes by opening each rich-media card
 * and advancing when the node's media (audio or video) ends, or after a configured
 * dwell for nodes with no waitable media.
 *
 * Configured per galaxy via window.TELARIS_TOUR_CONFIG. Disabled on phones.
 */

const MOBILE_MIN_WIDTH = 768;

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
    }

    init() {
        if (!this.config || !this.config.tour_enabled) return;
        if (window.innerWidth < MOBILE_MIN_WIDTH) return;

        this.bindControls();

        const mode = this.config.tour_start_mode || 'manual';
        if (mode === 'manual') {
            this.showPlayButton();
        } else if (mode === 'idle') {
            this.startIdleWatch();
        } else if (mode === 'immediate') {
            this.start();
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
        const events = ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart'];
        const reset = () => {
            if (this.active) return;
            clearTimeout(this.idleTimerId);
            this.idleTimerId = setTimeout(() => {
                if (!this.active) this.start();
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
        document.removeEventListener('keydown', this.boundOnKeydown);
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
        // The user is closing the card themselves (close-btn or backdrop click).
        // Let the existing close handler do its thing; just exit the tour without
        // triggering closeRichMediaWindow ourselves.
        const overlay = document.getElementById('rich-media-overlay');
        if (e.currentTarget === overlay && e.target !== overlay) return;
        this.exit({ closeCard: false });
    }

    togglePause() {
        if (!this.active) return;
        if (this.paused) {
            this.paused = false;
            this.pauseIcon?.classList.remove('hidden');
            this.resumeIcon?.classList.add('hidden');
            const audio = document.getElementById('rm-audio');
            const video = document.getElementById('rm-video');
            if (this.attachedAudio && audio) audio.play().catch(() => {});
            if (this.attachedVideo && video) video.play().catch(() => {});
            if (!this.attachedAudio && !this.attachedVideo) {
                this.scheduleDwellAdvance();
            }
        } else {
            this.paused = true;
            this.pauseIcon?.classList.add('hidden');
            this.resumeIcon?.classList.remove('hidden');
            const audio = document.getElementById('rm-audio');
            const video = document.getElementById('rm-video');
            if (this.attachedAudio && audio) audio.pause();
            if (this.attachedVideo && video) video.pause();
            this.clearDwellTimer();
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

        if (this.app?.networkManager?.setFocusedNode) {
            this.app.networkManager.setFocusedNode(node);
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

        if (video) {
            this.attachedVideo = true;
            const onEnded = () => {
                video.removeEventListener('ended', onEnded);
                this.attachedVideo = null;
                if (this.active && !this.paused) this.advance(1);
            };
            video.addEventListener('ended', onEnded);
            video.play().catch(() => {});
            return;
        }

        if (audio) {
            this.attachedAudio = true;
            const onEnded = () => {
                audio.removeEventListener('ended', onEnded);
                this.attachedAudio = null;
                if (this.active && !this.paused) this.advance(1);
            };
            audio.addEventListener('ended', onEnded);
            audio.play().catch(() => {});
            return;
        }

        this.scheduleDwellAdvance();
    }

    scheduleDwellAdvance() {
        const dwell = Math.max(1, this.config.tour_default_dwell || 8);
        this.dwellTimerId = setTimeout(() => {
            this.dwellTimerId = null;
            if (this.active && !this.paused) this.advance(1);
        }, dwell * 1000);
    }

    clearDwellTimer() {
        if (this.dwellTimerId) {
            clearTimeout(this.dwellTimerId);
            this.dwellTimerId = null;
        }
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
