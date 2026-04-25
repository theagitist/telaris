/**
 * Keyword chip strip: top-N most-used keywords for the current galaxy
 * rendered as toggleable filter chips. Active selections live on
 * app.activeKeywords (a Set); telaris-3d's updateNodes reads that to dim
 * non-matching nodes.
 *
 * Enabled per galaxy via window.TELARIS_KEYWORD_CHIPS_ENABLED.
 */

const TOP_N = 12;

// Same palette as keyword chips inside the rich-media card, so the strip
// reads as on-brand without coupling to per-node accent colors.
const CHIP_BG = [
    'rgba(254,202,202,0.25)', 'rgba(254,215,170,0.25)', 'rgba(253,230,138,0.25)',
    'rgba(254,240,138,0.25)', 'rgba(217,249,157,0.25)', 'rgba(187,247,208,0.25)',
    'rgba(167,243,208,0.25)', 'rgba(153,246,228,0.25)', 'rgba(165,243,252,0.25)',
    'rgba(186,230,253,0.25)', 'rgba(191,219,254,0.25)', 'rgba(199,210,254,0.25)',
    'rgba(221,214,254,0.25)', 'rgba(233,213,255,0.25)', 'rgba(245,208,254,0.25)',
    'rgba(251,207,232,0.25)', 'rgba(254,205,211,0.25)',
];
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
        const entries = Array.from(counts.entries())
            .filter(([, c]) => c >= 1)
            .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
            .slice(0, TOP_N);

        if (entries.length === 0) {
            this.strip.classList.add('hidden');
            return;
        }

        this.strip.innerHTML = '';
        for (const [keyword, count] of entries) {
            const idx = colorIndexFor(keyword);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'keyword-chip';
            chip.dataset.keyword = keyword;
            chip.style.cssText = [
                'background:' + CHIP_BG[idx],
                'color:' + CHIP_FG[idx],
                'border:1px solid ' + CHIP_FG[idx] + '40',
                'padding:4px 12px',
                'border-radius:9999px',
                'font-size:0.8rem',
                'font-weight:500',
                'cursor:pointer',
                'transition:all 150ms',
                'opacity:0.85',
                'white-space:nowrap',
            ].join(';');
            chip.textContent = `#${keyword}`;
            chip.title = `${count} wormhole${count === 1 ? '' : 's'}`;
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
            chip.style.opacity = (active.size === 0 || isActive) ? '1' : '0.45';
            chip.style.outline = isActive ? '2px solid currentColor' : 'none';
            chip.style.outlineOffset = isActive ? '2px' : '0';
        });
    }
}
