/**
 * Galaxy list strip: bottom-right button that slides up a list of the galaxies
 * in the current multigalaxy union. Click a galaxy to dim wormholes from other
 * galaxies. Multi-select OR-match — selecting two galaxies shows their union,
 * dims the rest. The panel is collapsed by default so the strip doesn't compete
 * visually with the wormhole field.
 *
 * Active selections live on app.activeGalaxyIds (a Set of int constellation_id).
 * telaris-3d's updateNodes reads that set to apply per-node dimming.
 *
 * Visibility:
 *   - Default ON for emergent unions (?galaxies=, /[XX], /tag/) — handled server-side.
 *   - Default OFF for cluster type, unless the cluster opts in via show_galaxy_list.
 *
 * Window globals consumed:
 *   TELARIS_GALAXY_LIST_ENABLED  bool  whether to show the strip at all
 *   TELARIS_GALAXY_LIST          [{id, name, slug, theme}, ...]
 */

const COLOR_PALETTE = [
    '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
    '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
    '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af',
];

function colorFor(galaxy) {
    const key = String(galaxy.slug || galaxy.name || galaxy.id);
    let hash = 0;
    for (let i = 0; i < key.length; i++) hash = key.charCodeAt(i) + ((hash << 5) - hash);
    return COLOR_PALETTE[Math.abs(hash) % COLOR_PALETTE.length];
}

export class GalaxyListStripController {
    constructor(app) {
        this.app = app;
        this.strip = document.getElementById('galaxy-list-strip');
        this.panel = document.getElementById('galaxy-list-panel');
        this.toggleBtn = document.getElementById('galaxy-list-toggle');
        this.toggleIcon = document.getElementById('galaxy-list-toggle-icon');
        this.toggleLabel = document.getElementById('galaxy-list-toggle-label');
        this.galaxies = Array.isArray(window.TELARIS_GALAXY_LIST) ? window.TELARIS_GALAXY_LIST : [];
        this.expanded = false;
    }

    init() {
        if (!window.TELARIS_GALAXY_LIST_ENABLED) return;
        if (!this.strip || !this.panel || !this.toggleBtn) return;
        if (!this.galaxies.length) return;
        if (!this.app.activeGalaxyIds) this.app.activeGalaxyIds = new Set();

        this.renderChips();
        this.refreshLabel();
        this.bindToggle();
        this.strip.classList.remove('hidden');
    }

    renderChips() {
        this.panel.innerHTML = '';
        for (const g of this.galaxies) {
            const fg = colorFor(g);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'galaxy-list-chip';
            chip.dataset.galaxyId = String(g.id);
            chip.style.cssText = [
                'background:rgba(0,0,0,0.55)',
                'border:1px solid rgba(255,255,255,0.15)',
                'border-radius:9999px',
                'padding:0.25rem 0.65rem',
                'color:' + fg,
                'font-weight:500',
                'cursor:pointer',
                'transition:opacity 150ms, text-shadow 150ms, border-color 150ms',
                'opacity:0.85',
                'white-space:nowrap',
                'backdrop-filter:blur(4px)',
                'text-shadow:none',
                'display:flex',
                'align-items:center',
                'gap:0.4rem',
            ].join(';');
            const dot = document.createElement('span');
            dot.style.cssText = 'width:0.5rem;height:0.5rem;border-radius:9999px;background:' + fg + ';display:inline-block;flex-shrink:0;';
            const label = document.createElement('span');
            label.textContent = g.name;
            chip.appendChild(dot);
            chip.appendChild(label);
            chip.title = g.slug ? '/' + g.slug : '#' + g.id;
            chip.addEventListener('mouseenter', () => {
                chip.style.borderColor = 'rgba(255,255,255,0.35)';
            });
            chip.addEventListener('mouseleave', () => this.refreshChipStyles());
            chip.addEventListener('click', () => this.toggleGalaxy(g.id));
            this.panel.appendChild(chip);
        }
        this.refreshChipStyles();
    }

    bindToggle() {
        this.toggleBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            this.setExpanded(!this.expanded);
        });
        // Click anywhere outside the strip closes the panel.
        document.addEventListener('click', (ev) => {
            if (!this.expanded) return;
            if (this.strip.contains(ev.target)) return;
            this.setExpanded(false);
        });
        // Esc closes.
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && this.expanded) this.setExpanded(false);
        });
    }

    setExpanded(open) {
        this.expanded = !!open;
        this.toggleBtn.setAttribute('aria-expanded', this.expanded ? 'true' : 'false');
        if (this.toggleIcon) {
            this.toggleIcon.style.transform = this.expanded ? 'rotate(180deg)' : 'rotate(0deg)';
        }
        if (this.expanded) {
            this.panel.style.opacity = '1';
            this.panel.style.transform = 'translateY(0)';
            this.panel.style.pointerEvents = 'auto';
        } else {
            this.panel.style.opacity = '0';
            this.panel.style.transform = 'translateY(8px)';
            this.panel.style.pointerEvents = 'none';
        }
    }

    toggleGalaxy(galaxyId) {
        const id = Number(galaxyId);
        if (!this.app.activeGalaxyIds) this.app.activeGalaxyIds = new Set();
        if (this.app.activeGalaxyIds.has(id)) this.app.activeGalaxyIds.delete(id);
        else this.app.activeGalaxyIds.add(id);
        this.refreshChipStyles();
        this.refreshLabel();
    }

    refreshChipStyles() {
        const active = this.app.activeGalaxyIds || new Set();
        this.panel.querySelectorAll('.galaxy-list-chip').forEach(chip => {
            const id = Number(chip.dataset.galaxyId);
            const isActive = active.has(id);
            const baseOpacity = active.size === 0 ? 0.85 : (isActive ? 1.0 : 0.45);
            chip.style.opacity = String(baseOpacity);
            chip.style.borderColor = isActive ? 'rgba(255,255,255,0.55)' : 'rgba(255,255,255,0.15)';
            chip.style.fontWeight = isActive ? '700' : '500';
            const fg = chip.style.color;
            chip.style.textShadow = isActive ? `0 0 6px ${fg}, 0 0 14px ${fg}88` : 'none';
        });
    }

    refreshLabel() {
        if (!this.toggleLabel) return;
        const active = this.app.activeGalaxyIds || new Set();
        const total = this.galaxies.length;
        if (active.size === 0) {
            this.toggleLabel.textContent = `Galaxies · ${total}`;
        } else {
            this.toggleLabel.textContent = `Galaxies · ${active.size}/${total}`;
        }
    }
}
