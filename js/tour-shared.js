/**
 * Shared primitives for the auto-tour (auto-tour.js) and idle-spotlight
 * (idle-spotlight.js) controllers. Both fly the camera to a wormhole, open its
 * info card, and either let media play to its 'ended' event or hold for a
 * visible dwell countdown. These pieces were duplicated verbatim in both files;
 * extracting them keeps the dwell-bar visuals and reading-time pacing identical
 * across the two experiences and gives them a single place to change.
 */

/** Below this viewport width both experiences are disabled (phones). */
export const MOBILE_MIN_WIDTH = 768;

/**
 * Pastel palette for the dwell-bar fill. Same hues as the keyword chips in
 * telaris-3d.js, so the bar reads as on-brand without coupling to a node's
 * accent colour. A fresh colour is picked each time the bar starts.
 */
const DWELL_BAR_COLORS = [
    '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
    '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
    '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af',
];

/** Promise that resolves after `ms` milliseconds. */
export function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * How long to keep the dwell bar up for a node with no waitable media. If the
 * card has descriptive text, scale by an estimated reading time (180 wpm + 2s
 * to settle in), bounded below by `baseDwell`. Otherwise return `baseDwell`.
 */
export function computeDwellSeconds(node, baseDwell) {
    const text = node?.userData?.description || '';
    if (!text) return baseDwell;
    const stripped = String(text).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    if (!stripped) return baseDwell;
    const words = stripped.split(/\s+/).length;
    const seconds = (words / 180) * 60;
    return Math.max(baseDwell, seconds + 2);
}

/**
 * The shrinking dwell-bar shown at the bottom of the info card. Wraps the
 * shared #tour-dwell-bar-track / #tour-dwell-bar DOM and the single in-flight
 * Web Animation. Both controllers drive the same DOM ids, so the bar looks
 * identical whether it is the tour or the idle spotlight running it.
 */
export class DwellBar {
    constructor() {
        this.animation = null;
    }

    /** Start a fresh countdown shrinking from full to empty over `durationMs`. */
    start(durationMs) {
        const track = document.getElementById('tour-dwell-bar-track');
        const bar = document.getElementById('tour-dwell-bar');
        if (!track || !bar) return;
        track.classList.remove('hidden');
        bar.style.backgroundColor = DWELL_BAR_COLORS[Math.floor(Math.random() * DWELL_BAR_COLORS.length)];
        bar.style.transform = 'scaleX(1)';
        if (this.animation) {
            this.animation.cancel();
            this.animation = null;
        }
        if (typeof bar.animate !== 'function') return;
        this.animation = bar.animate(
            [{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }],
            { duration: durationMs, easing: 'linear', fill: 'forwards' }
        );
    }

    /** Stop the animation and hide the bar. */
    cancel() {
        if (this.animation) {
            this.animation.cancel();
            this.animation = null;
        }
        const track = document.getElementById('tour-dwell-bar-track');
        if (track) track.classList.add('hidden');
    }

    /** Pause a running countdown (used by the auto-tour pause control). */
    pause() {
        if (this.animation && this.animation.playState === 'running') {
            this.animation.pause();
        }
    }

    /** Resume a paused countdown. */
    resume() {
        if (this.animation && this.animation.playState === 'paused') {
            this.animation.play();
        }
    }

    /**
     * Milliseconds left on the current countdown, or null if nothing is
     * running. Used by the auto-tour pause logic to re-arm its dwell timer for
     * the remaining time.
     */
    remainingMs() {
        if (!this.animation) return null;
        const total = this.animation.effect.getTiming().duration;
        const elapsed = Number(this.animation.currentTime || 0);
        return Math.max(0, total - elapsed);
    }
}
