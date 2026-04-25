/**
 * Keyword chip strip: top-N most-used keywords for the current galaxy
 * rendered as toggleable filter chips. Active selections live on
 * app.activeKeywords (a Set); telaris-3d's updateNodes reads that to dim
 * non-matching nodes.
 *
 * Enabled per galaxy via window.TELARIS_KEYWORD_CHIPS_ENABLED.
 */

// Cap on chips emitted into the DOM. The strip CSS clips to ~2 lines so any
// extras stay invisible — we still randomize the order on each load so a
// different sample shows up.
const MAX_CHIPS = 40;

// Pastel palette (same set used in the card's keyword chips and the related
// row), so the strip reads as on-brand without coupling to per-node colors.
const CHIP_FG = [
    '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
    '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
    '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af',
];

function colorIndexFor(keyword) {
    let hash = 0;
    for (let i = 0; i < keyword.length; i++) {
        hash = keyword.charCodeAt(i) + ((hash << 5) - hash);
    }
    return Math.abs(hash) % CHIP_FG.length;
}

function shuffle(arr) {
    const out = arr.slice();
    for (let i = out.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [out[i], out[j]] = [out[j], out[i]];
    }
    return out;
}

export class KeywordChipsController {
    constructor(app) {
        this.app = app;
        this.strip = document.getElementById('keyword-chips-strip');
        this.boundCount = -1; // last node count we rendered for, to avoid redundant re-renders
    }

    init() {
        if (!window.TELARIS_KEYWORD_CHIPS_ENABLED) return;
        if (!this.strip) return;
        if (!this.app) return;
        if (!this.app.activeKeywords) this.app.activeKeywords = new Set();
        this.tryRender();
    }

    tryRender() {
        // Wait until nodes are loaded; poll briefly. Re-render if the node set
        // changes (cluster drill-in/out swaps app.nodes).
        let attempts = 0;
        const maxAttempts = 100;
        const tick = () => {
            attempts++;
            const nodes = (this.app && Array.isArray(this.app.nodes)) ? this.app.nodes : [];
            if (nodes.length > 0 && nodes.length !== this.boundCount) {
                this.boundCount = nodes.length;
                this.render(nodes);
                return;
            }
            if (attempts < maxAttempts) setTimeout(tick, 200);
        };
        setTimeout(tick, 200);
    }

    render(nodes) {
        const counts = new Map();
        for (const n of nodes) {
            const kws = n?.userData?.keywords || [];
            for (const k of kws) {
                if (!k) continue;
                counts.set(k, (counts.get(k) || 0) + 1);
            }
        }
        if (counts.size === 0) {
            this.strip.classList.add('hidden');
            return;
        }

        // Fully randomize order — the CSS clip to ~2 lines decides what stays
        // visible, so a different subset surfaces each load.
        const entries = shuffle(Array.from(counts.entries())).slice(0, MAX_CHIPS);

        this.strip.innerHTML = '';
        for (const [keyword, count] of entries) {
            const idx = colorIndexFor(keyword);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'keyword-chip';
            chip.dataset.keyword = keyword;
            const fg = CHIP_FG[idx];
            chip.style.cssText = [
                'background:transparent',
                'border:none',
                'padding:0',
                'color:' + fg,
                'font-size:0.85rem',
                'line-height:1.4',
                'font-weight:500',
                'cursor:pointer',
                'transition:opacity 150ms, text-shadow 150ms',
                'opacity:0.55',
                'white-space:nowrap',
                'flex-shrink:0',
                'text-shadow:none',
            ].join(';');
            chip.textContent = `#${keyword}`;
            chip.title = `${count} wormhole${count === 1 ? '' : 's'}`;
            chip.addEventListener('mouseenter', () => {
                chip.style.opacity = '0.95';
                chip.style.textShadow = `0 0 6px ${fg}, 0 0 14px ${fg}88`;
            });
            chip.addEventListener('mouseleave', () => {
                chip.style.textShadow = 'none';
                this.refreshChipStyles();
            });
            chip.addEventListener('click', () => this.toggle(keyword, chip));
            this.strip.appendChild(chip);
        }
        this.strip.classList.remove('hidden');
        this.refreshChipStyles();

        // Re-render whenever the node set changes (cluster drill-in/out etc.).
        this.tryRender();
    }

    toggle(keyword, chip) {
        if (!this.app.activeKeywords) this.app.activeKeywords = new Set();
        if (this.app.activeKeywords.has(keyword)) {
            this.app.activeKeywords.delete(keyword);
        } else {
            this.app.activeKeywords.add(keyword);
        }
        this.refreshChipStyles();
    }

    refreshChipStyles() {
        const active = this.app.activeKeywords || new Set();
        this.strip.querySelectorAll('.keyword-chip').forEach(chip => {
            const isActive = active.has(chip.dataset.keyword);
            // Default (no active filter) is dim; active stays bright; siblings dim further when something is active.
            let opacity = 0.55;
            if (active.size > 0) opacity = isActive ? 0.95 : 0.25;
            chip.style.opacity = String(opacity);
            chip.style.fontWeight = isActive ? '700' : '500';
            const color = chip.style.color;
            chip.style.textShadow = isActive ? `0 0 6px ${color}, 0 0 14px ${color}88` : 'none';
        });
    }
}
