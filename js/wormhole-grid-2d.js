/**
 * Wormhole grid (2D view) — alternative visitor-side layout.
 *
 * Same spatial logic as the keyword canvas: Poisson-disc seeded positions +
 * springs from keyword-shared connections + global Coulomb repulsion + idle
 * float. Cards are small (chip-sized) and distributed across the visible
 * area rather than tiled — same "constellation in 2D" feel.
 *
 * Read-only: visitors don't drag cards, no positions are persisted, every
 * page load reseeds and physics settles fresh. Mode is non-persistent —
 * 3D stays the default surface.
 *
 * Data: walks `app.nodes` for the current galaxy/cluster level. No extra
 * fetches — same array the 3D scene uses, just rendered flat.
 *
 * Click handling:
 *   - cluster card → app.transitionToCluster(constellationId, cluster_key)
 *   - portal card  → page navigate to target galaxy URL (mode resets to 3D)
 *   - regular node → app.showRichMediaWindow(node) — same info card as 3D
 */

import { CHIP_FG, colorIndexFor } from './keyword-chips.js';

// --- Card sizing (much smaller than the v1 tiled grid) ---
const CARD_PAD_X = 8;
const CARD_PAD_Y = 4;
const CARD_FONT_PX = 12;
const CARD_MIN_W = 60;
const CARD_MAX_W = 160;
const CARD_GAP_MIN = 16; // min separation enforced by Poisson + repulsion

// --- Physics ---
// Springs from keyword-shared pairs are intentionally DROPPED in the visitor
// 2D view: they pulled every connected chip into a knot. Relations get
// expressed via hover-revealed lines instead. We keep only light Coulomb
// repulsion so the Poisson seed never starts with overlaps. Physics runs
// invisibly during a presettle, then stops — only idle float continues.
const PHYS_DAMPING = 0.82;
const PHYS_SETTLE_VELOCITY = 0.15;
const PHYS_MAX_STEPS = 600;
const PHYS_REPULSION_K = 1000;
const PHYS_REPULSION_CUTOFF = 180;
const PHYS_REPULSION_MIN_DIST = 30;
const PRESETTLE_STEPS = 160;
const CROSSFADE_MS = 280;
// Target area each chip "owns" during Poisson seeding (chip body + room
// to breathe). Total seed region scales linearly with node count, then
// gets clamped to a min/max so 5-node and 200-node galaxies both look OK.
const SEED_AREA_PER_CHIP = 19000;
const SEED_REGION_MIN_W = 520;
const SEED_REGION_MIN_H = 340;
// Per-pair pixel padding kept around each card when checking rectangle
// overlap (in both Poisson seeding and the post-settle resolver). Keeps
// chips from kissing edges.
const CARD_GAP_PADDING = 10;

// --- Idle float ---
const IDLE_RADIUS_MIN = 2;
const IDLE_RADIUS_MAX = 4;
const IDLE_PERIOD_MIN = 6;
const IDLE_PERIOD_MAX = 10;

// --- Lines ---
// Match the 3D scene: keyword-sharing connections are invisible by default,
// surfaced only on hover (same convention as visited-card highlight). Pastel
// tones; intentionally muted — the chips are the focus, lines are context.
const LINE_BASE_OPACITY = 0;
const LINE_HIGHLIGHT_OPACITY = 0.5;
const LINE_WIDTH_BASE = 1;
const LINE_WIDTH_PER_SHARE = 0.6;
const LINE_WIDTH_CAP = 4;
const LINE_GLOW_REST_PX = 2;
const LINE_GLOW_HOVER_PX = 4;

const SVG_NS = 'http://www.w3.org/2000/svg';

/** Average two #rrggbb colours into a single hex string. Matches keyword-canvas's blendHex. */
function blendHex(a, b) {
    const pa = parseInt(a.slice(1), 16);
    const pb = parseInt(b.slice(1), 16);
    const r = Math.round((((pa >> 16) & 0xff) + ((pb >> 16) & 0xff)) / 2);
    const g = Math.round((((pa >> 8) & 0xff) + ((pb >> 8) & 0xff)) / 2);
    const bl = Math.round(((pa & 0xff) + (pb & 0xff)) / 2);
    return '#' + [r, g, bl].map(v => v.toString(16).padStart(2, '0')).join('');
}

export class WormholeGrid2D {
    constructor(app) {
        this.app = app;
        this.container = document.getElementById('wormhole-grid-2d');
        this.linesSvg = document.getElementById('wormhole-grid-2d-lines');
        this.cardsLayer = document.getElementById('wormhole-grid-2d-cards');
        this.active = false;

        this.nodes = [];                       // current node data list
        this.adjacency = new Map();            // nodeId -> Set<nodeId>
        this.positions = new Map();            // nodeId -> { x, y }
        this.velocities = new Map();           // nodeId -> { vx, vy }
        this.idleOffsets = new Map();          // nodeId -> { dx, dy }
        this.cardEls = new Map();              // nodeId -> div
        this.cardSizes = new Map();            // nodeId -> { w, h }
        this.lineEls = new Map();              // edgeKey -> SVG <line>

        this.animationRafId = null;
        this.hoveredNodeId = null;
        this._lastActiveKeywordsSnapshot = '';

        this._onResize = this._onResize.bind(this);
        window.addEventListener('resize', this._onResize);
    }

    isActive() { return this.active; }

    /**
     * Switch the 2D view on/off with a crossfade. When activating: seed
     * positions, presettle repulsion invisibly, fade the whole container in.
     * No visible "springing" — the cards appear already laid out.
     */
    setActive(active) {
        if (this.active === active) return;
        this.active = active;
        if (!this.container) return;
        if (active) {
            // Start invisible so the seed + presettle aren't seen.
            this.container.style.transition = 'none';
            this.container.style.opacity = '0';
            this.container.classList.remove('hidden');
            this.refresh(); // builds cards, seeds positions, runs presettle
            if (this.nodes.length === 0) this._pollForNodes();
            this._startAnimationLoop();
            // Force a layout flush so the opacity transition below registers
            // as a transition (not an initial paint at opacity 1).
            void this.container.offsetWidth;
            this.container.style.transition = `opacity ${CROSSFADE_MS}ms ease`;
            this.container.style.opacity = '1';
        } else {
            this.container.style.transition = `opacity ${Math.floor(CROSSFADE_MS * 0.7)}ms ease`;
            this.container.style.opacity = '0';
            setTimeout(() => {
                if (!this.active) {
                    this.container.classList.add('hidden');
                    this._stopAnimationLoop();
                }
            }, Math.floor(CROSSFADE_MS * 0.75));
        }
    }

    refresh() {
        if (!this.container || !this.cardsLayer || !this.linesSvg) return;
        const nodes = this._pullNodeData();
        this.nodes = nodes;
        if (nodes.length === 0) {
            this.cardsLayer.innerHTML = '';
            this.linesSvg.innerHTML = '';
            this.positions.clear();
            this.velocities.clear();
            this.idleOffsets.clear();
            this.cardEls.clear();
            this.cardSizes.clear();
            this.lineEls.clear();
            return;
        }
        this._buildAdjacency(nodes);
        this._buildCards(nodes);          // measures + stores card sizes
        this._seedPositions(nodes);        // Poisson-disc within the viewport
        this._buildLines(nodes);           // SVG lines, positioned in _renderFrame
        this._presettle();                 // resolve any seed overlaps offscreen
        this._resolveOverlaps();           // hard guarantee: no rectangle overlaps
        this._renderFrame();               // place cards + lines at settled positions
    }

    /**
     * Run repulsion-only physics steps until velocities settle below the
     * threshold (or the safety cap). No springs — see the comment at the top
     * of the physics constants block. Happens while the container is still
     * at opacity 0 so visitors never see the motion.
     */
    _presettle() {
        this.velocities.clear();
        for (let i = 0; i < PRESETTLE_STEPS; i++) {
            const maxV = this._physicsStep();
            if (maxV < PHYS_SETTLE_VELOCITY && i > 8) break;
        }
        this.velocities.clear();
    }

    _pullNodeData() {
        const arr = this.app && Array.isArray(this.app.nodes) ? this.app.nodes : [];
        const out = [];
        for (const m of arr) {
            if (!m || !m.userData || m.userData.id == null) continue;
            out.push(m.userData);
        }
        return out;
    }

    /**
     * Adjacency is keyed Map<id, Map<otherId, sharedKeywordCount>>, so the
     * line renderer can scale stroke width by share count (more keywords
     * shared = thicker line, like the 3D scene's connection weighting).
     */
    _buildAdjacency(nodes) {
        this.adjacency.clear();
        const byKw = new Map();
        for (const n of nodes) {
            this.adjacency.set(n.id, new Map());
            const kws = Array.isArray(n.keywords) ? n.keywords : [];
            for (const kw of kws) {
                if (!kw) continue;
                const k = String(kw).toLowerCase();
                let list = byKw.get(k);
                if (!list) { list = []; byKw.set(k, list); }
                list.push(n.id);
            }
        }
        for (const [, list] of byKw) {
            if (list.length < 2) continue;
            for (let i = 0; i < list.length; i++) {
                for (let j = i + 1; j < list.length; j++) {
                    const a = list[i], b = list[j];
                    const ma = this.adjacency.get(a);
                    const mb = this.adjacency.get(b);
                    ma.set(b, (ma.get(b) || 0) + 1);
                    mb.set(a, (mb.get(a) || 0) + 1);
                }
            }
        }
    }

    /**
     * Build the card DOM elements and measure them. Sizes go into cardSizes
     * so the seeder and repulsion can honour each card's footprint instead
     * of using a single padding constant.
     */
    _buildCards(nodes) {
        this.cardsLayer.innerHTML = '';
        this.cardEls.clear();
        this.cardSizes.clear();
        for (const n of nodes) {
            const card = this._buildCard(n);
            this.cardsLayer.appendChild(card);
            this.cardEls.set(n.id, card);
        }
        // Measure once everything is in the DOM. Capture each card's
        // bounding rect so the seeder + repulsion can avoid overlaps.
        for (const [id, card] of this.cardEls) {
            const r = card.getBoundingClientRect();
            this.cardSizes.set(id, { w: r.width, h: r.height });
        }
    }

    _buildCard(n) {
        const isCluster = n.node_type === 'cluster';
        const isPortal = n.node_type === 'portal';
        // Deterministic pastel color per wormhole name — same palette + hash
        // used everywhere else (keyword chips, canvas, related row), so a
        // wormhole reads the same shade across the app.
        const pastel = CHIP_FG[colorIndexFor(n.name || ('#' + n.id))];
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'wh2d-card';
        card.dataset.nodeId = String(n.id);
        card.dataset.pastel = pastel;
        Object.assign(card.style, {
            position: 'absolute',
            background: this._pastelRgba(pastel, 0.18),
            border: `1px solid ${this._pastelRgba(pastel, 0.55)}`,
            borderRadius: '9999px',
            boxShadow: '0 2px 8px rgba(0, 0, 0, 0.35)',
            color: pastel,
            cursor: 'pointer',
            padding: `${CARD_PAD_Y}px ${CARD_PAD_X + 4}px ${CARD_PAD_Y}px ${CARD_PAD_X}px`,
            display: 'inline-flex',
            alignItems: 'center',
            gap: '6px',
            lineHeight: '1.2',
            whiteSpace: 'nowrap',
            maxWidth: CARD_MAX_W + 'px',
            transition: 'border-color 160ms ease, transform 160ms ease, box-shadow 160ms ease, background 160ms ease',
            transform: 'translate(-50%, -50%)',
            transformOrigin: '50% 50%',
            font: 'inherit',
        });
        card.style.setProperty('font-size', CARD_FONT_PX + 'px');
        card.style.setProperty('font-weight', '500');
        // Cluster / portal accent: a faint extra ring so they read as
        // "navigates somewhere" at a glance, without overriding the pastel.
        if (isCluster) card.style.boxShadow = '0 0 0 1px rgba(0, 255, 204, 0.5), 0 2px 8px rgba(0, 0, 0, 0.35)';
        else if (isPortal) card.style.boxShadow = '0 0 0 1px rgba(252, 211, 77, 0.5), 0 2px 8px rgba(0, 0, 0, 0.35)';

        // Thumbnail dot — only if the wormhole has an image. Same visual idea
        // as the keyword canvas's anchor dots: a tiny visual prefix to the label.
        const imageUrl = n.image_url || (n.icon_url && !isCluster ? n.icon_url : null);
        if (imageUrl) {
            const thumb = document.createElement('span');
            Object.assign(thumb.style, {
                display: 'inline-block',
                width: '14px',
                height: '14px',
                borderRadius: '50%',
                backgroundImage: `url(${JSON.stringify(imageUrl).slice(1, -1)})`,
                backgroundSize: 'cover',
                backgroundPosition: 'center',
                flex: '0 0 auto',
                boxShadow: '0 0 0 1px rgba(255,255,255,0.15)',
            });
            card.appendChild(thumb);
        }

        const label = document.createElement('span');
        label.textContent = isCluster ? this._clusterDisplayName(n) : (n.name || '#' + n.id);
        Object.assign(label.style, {
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            maxWidth: (CARD_MAX_W - 30) + 'px',
        });
        card.appendChild(label);

        if (isCluster && n.cluster_count) {
            const badge = document.createElement('span');
            badge.textContent = String(n.cluster_count);
            Object.assign(badge.style, {
                fontSize: '0.65rem',
                color: 'rgba(0, 255, 204, 0.9)',
                marginLeft: '2px',
                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                opacity: '0.85',
            });
            card.appendChild(badge);
        }

        card.addEventListener('mouseenter', () => this._onCardHover(n.id, true));
        card.addEventListener('mouseleave', () => this._onCardHover(n.id, false));
        card.addEventListener('click', () => this._onCardClick(n));
        return card;
    }

    _clusterDisplayName(n) {
        return n.name || n.cluster_key || 'cluster';
    }

    /** "#rrggbb" + alpha → "rgba(r,g,b,a)" string. */
    _pastelRgba(hex, alpha) {
        const p = parseInt(hex.slice(1), 16);
        const r = (p >> 16) & 0xff;
        const g = (p >> 8) & 0xff;
        const b = p & 0xff;
        return `rgba(${r},${g},${b},${alpha})`;
    }

    /**
     * Poisson-disc seed positions inside the container. Each candidate sample
     * keeps the best-candidate (Mitchell) distance from prior chips. Uses each
     * card's measured size + a small gap to set a per-pair minimum distance
     * so the layout never starts with overlaps.
     */
    _seedPositions(nodes) {
        this.positions.clear();
        const rect = this.container.getBoundingClientRect();
        const W = Math.max(200, rect.width);
        const H = Math.max(200, rect.height);
        // Compute a centered seed region whose area scales with node count,
        // so a 10-chip galaxy doesn't fill the same 1500×800 space a 100-chip
        // galaxy does. Aspect matches the viewport so the region "feels"
        // right at any window size.
        const aspect = W / H;
        const targetArea = nodes.length * SEED_AREA_PER_CHIP;
        let regionW = Math.sqrt(targetArea * aspect);
        let regionH = targetArea / Math.max(1, regionW);
        // Clamp upward to viewport (with chrome margins) and downward to a
        // sensible minimum so few-chip galaxies don't collapse to a dot.
        const maxRegionW = Math.max(SEED_REGION_MIN_W, W - 120);
        const maxRegionH = Math.max(SEED_REGION_MIN_H, H - 160);
        regionW = Math.min(regionW, maxRegionW);
        regionH = Math.min(regionH, maxRegionH);
        regionW = Math.max(regionW, SEED_REGION_MIN_W);
        regionH = Math.max(regionH, SEED_REGION_MIN_H);
        const xMin = (W - regionW) / 2;
        const xMax = xMin + regionW;
        // Push the region down a bit so the top-center switch + the
        // (optional) keyword chip strip at the bottom stay clear.
        const yMin = Math.max(80, (H - regionH) / 2);
        const yMax = yMin + regionH;
        // Strict no-overlap Poisson. For each node we try up to N candidate
        // positions, REJECT any that overlap an already-placed card using
        // exact axis-aligned-rectangle math, then among the non-overlapping
        // candidates pick the one with the largest min-distance (Mitchell's
        // best-candidate spread). If all candidates overlap, the inner loop
        // doubles the attempt count and retries before giving up.
        const placed = []; // [{x, y, w, h}]
        for (const n of nodes) {
            const size = this.cardSizes.get(n.id) || { w: 80, h: 28 };
            const halfW = size.w / 2 + CARD_GAP_PADDING;
            const halfH = size.h / 2 + CARD_GAP_PADDING;
            let best = null;
            let bestDist = -1;
            for (const attempts of [40, 200]) {
                for (let i = 0; i < attempts; i++) {
                    const cx = xMin + Math.random() * (xMax - xMin);
                    const cy = yMin + Math.random() * (yMax - yMin);
                    let overlap = false;
                    let minGap = Infinity;
                    for (const p of placed) {
                        const xGap = (halfW + p.halfW) - Math.abs(cx - p.x);
                        const yGap = (halfH + p.halfH) - Math.abs(cy - p.y);
                        if (xGap > 0 && yGap > 0) { overlap = true; break; }
                        // Use the larger negative-gap as a proxy for "how far
                        // apart we are" — bigger is better (more uniform spread).
                        const gap = Math.max(-xGap, -yGap);
                        if (gap < minGap) minGap = gap;
                    }
                    if (overlap) continue;
                    if (minGap > bestDist) {
                        bestDist = minGap;
                        best = { x: cx, y: cy };
                    }
                }
                if (best) break; // Found a non-overlapping spot — no retry needed.
            }
            // Fallback: if we genuinely can't fit (e.g. very tight region +
            // many large cards), place anyway near the center. The post-
            // settle pass will push any remaining overlaps apart.
            if (!best) {
                best = { x: (xMin + xMax) / 2, y: (yMin + yMax) / 2 };
            }
            placed.push({ x: best.x, y: best.y, halfW, halfH });
            this.positions.set(n.id, { x: best.x, y: best.y });
        }
    }

    /**
     * Guarantee no rectangle overlaps remain after physics. Walks every
     * pair; if any overlap, push them apart along the axis of least
     * overlap (less layout disruption) and iterate. Cheap — the inner
     * loop is O(N²) but only runs until clean (usually 0-3 passes).
     */
    _resolveOverlaps() {
        const MAX_ITER = 60;
        const ids = Array.from(this.positions.keys());
        const rect = this.container.getBoundingClientRect();
        const margin = 30;
        for (let iter = 0; iter < MAX_ITER; iter++) {
            let any = false;
            for (let i = 0; i < ids.length; i++) {
                const pa = this.positions.get(ids[i]);
                const sa = this.cardSizes.get(ids[i]);
                if (!pa || !sa) continue;
                for (let j = i + 1; j < ids.length; j++) {
                    const pb = this.positions.get(ids[j]);
                    const sb = this.cardSizes.get(ids[j]);
                    if (!pb || !sb) continue;
                    const minDx = (sa.w + sb.w) / 2 + CARD_GAP_PADDING;
                    const minDy = (sa.h + sb.h) / 2 + CARD_GAP_PADDING;
                    const xOver = minDx - Math.abs(pb.x - pa.x);
                    const yOver = minDy - Math.abs(pb.y - pa.y);
                    if (xOver > 0 && yOver > 0) {
                        any = true;
                        // Push along the axis with the smaller overlap —
                        // moves chips less. Split the correction evenly.
                        if (xOver < yOver) {
                            const push = xOver / 2 + 0.5;
                            const dir = pb.x >= pa.x ? 1 : -1;
                            pa.x -= dir * push;
                            pb.x += dir * push;
                        } else {
                            const push = yOver / 2 + 0.5;
                            const dir = pb.y >= pa.y ? 1 : -1;
                            pa.y -= dir * push;
                            pb.y += dir * push;
                        }
                    }
                }
            }
            // Clamp inside the container so we don't push a chip off-screen.
            for (const id of ids) {
                const p = this.positions.get(id);
                const s = this.cardSizes.get(id);
                if (!p || !s) continue;
                const hx = s.w / 2 + margin;
                const hy = s.h / 2 + margin;
                if (p.x < hx) p.x = hx;
                if (p.x > rect.width - hx) p.x = rect.width - hx;
                if (p.y < hy + 30) p.y = hy + 30;
                if (p.y > rect.height - hy) p.y = rect.height - hy;
            }
            if (!any) return;
        }
    }

    _buildLines(nodes) {
        this.linesSvg.innerHTML = '';
        this.lineEls.clear();
        const rect = this.container.getBoundingClientRect();
        this.linesSvg.setAttribute('viewBox', `0 0 ${rect.width} ${rect.height}`);
        this.linesSvg.setAttribute('width', String(rect.width));
        this.linesSvg.setAttribute('height', String(rect.height));
        const nodeById = new Map();
        for (const n of nodes) nodeById.set(n.id, n);
        const seen = new Set();
        for (const n of nodes) {
            const adj = this.adjacency.get(n.id);
            if (!adj) continue;
            for (const [otherId, sharedCount] of adj) {
                const key = WormholeGrid2D._edgeKey(n.id, otherId);
                if (seen.has(key)) continue;
                seen.add(key);
                const other = nodeById.get(otherId);
                if (!other) continue;
                // Pastel glow: blend the two endpoint colors so the line reads
                // as "of both chips" rather than neutral. Same blendHex used
                // in keyword-canvas.
                const colA = CHIP_FG[colorIndexFor(n.name || ('#' + n.id))];
                const colB = CHIP_FG[colorIndexFor(other.name || ('#' + otherId))];
                const glow = blendHex(colA, colB);
                const width = Math.min(
                    LINE_WIDTH_BASE + LINE_WIDTH_PER_SHARE * (sharedCount - 1),
                    LINE_WIDTH_CAP
                );
                const line = document.createElementNS(SVG_NS, 'line');
                line.setAttribute('stroke', glow);
                line.setAttribute('stroke-opacity', String(LINE_BASE_OPACITY));
                line.setAttribute('stroke-width', String(width));
                line.setAttribute('stroke-linecap', 'round');
                line.setAttribute('data-a', String(n.id));
                line.setAttribute('data-b', String(otherId));
                line.setAttribute('data-glow', glow);
                line.setAttribute('data-base-width', String(width));
                line.setAttribute('data-shared', String(sharedCount));
                line.style.transition = 'stroke-opacity 160ms ease, stroke-width 160ms ease, filter 160ms ease';
                line.style.filter = `drop-shadow(0 0 ${LINE_GLOW_REST_PX}px ${glow})`;
                this.linesSvg.appendChild(line);
                this.lineEls.set(key, line);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Physics + idle float (mirrors keyword-canvas.js)
    // ---------------------------------------------------------------------

    _startAnimationLoop() {
        if (this.animationRafId) return;
        const tick = (timestampMs) => {
            if (!this.active) { this.animationRafId = null; return; }
            this._updateIdleOffsets(timestampMs);
            this._syncActiveKeywordDim();
            this._renderFrame();
            this.animationRafId = requestAnimationFrame(tick);
        };
        this.animationRafId = requestAnimationFrame(tick);
    }

    _stopAnimationLoop() {
        if (this.animationRafId) cancelAnimationFrame(this.animationRafId);
        this.animationRafId = null;
        this.velocities.clear();
    }

    /**
     * Repulsion-only physics — visitor 2D never gets springs (they collapsed
     * connected chips into a knot). Just enough push to fix Poisson overlap.
     * Returns the max chip velocity so the presettle loop knows when to stop.
     */
    _physicsStep() {
        const forces = new Map();

        // Coulomb repulsion between every pair within cutoff.
        const ids = Array.from(this.positions.keys());
        for (let i = 0; i < ids.length; i++) {
            const aId = ids[i];
            const pa = this.positions.get(aId);
            for (let j = i + 1; j < ids.length; j++) {
                const bId = ids[j];
                const pb = this.positions.get(bId);
                const dx = pb.x - pa.x;
                const dy = pb.y - pa.y;
                let d2 = dx * dx + dy * dy;
                if (d2 > PHYS_REPULSION_CUTOFF * PHYS_REPULSION_CUTOFF) continue;
                let dist = Math.sqrt(d2) || 0.001;
                if (dist < PHYS_REPULSION_MIN_DIST) dist = PHYS_REPULSION_MIN_DIST;
                const mag = PHYS_REPULSION_K / (dist * dist);
                const fx = (dx / dist) * mag;
                const fy = (dy / dist) * mag;
                let fa = forces.get(aId); if (!fa) { fa = { fx: 0, fy: 0 }; forces.set(aId, fa); }
                let fb = forces.get(bId); if (!fb) { fb = { fx: 0, fy: 0 }; forces.set(bId, fb); }
                fa.fx -= fx; fa.fy -= fy;
                fb.fx += fx; fb.fy += fy;
            }
        }

        // Integrate.
        const rect = this.container.getBoundingClientRect();
        const margin = 40;
        let maxV = 0;
        for (const [id, pos] of this.positions) {
            const f = forces.get(id);
            if (!f) continue;
            let v = this.velocities.get(id);
            if (!v) { v = { vx: 0, vy: 0 }; this.velocities.set(id, v); }
            v.vx = (v.vx + f.fx) * PHYS_DAMPING;
            v.vy = (v.vy + f.fy) * PHYS_DAMPING;
            const speed = Math.sqrt(v.vx * v.vx + v.vy * v.vy);
            if (speed > maxV) maxV = speed;
            if (speed > 0.01) {
                pos.x = Math.max(margin, Math.min(rect.width - margin, pos.x + v.vx));
                pos.y = Math.max(margin + 30, Math.min(rect.height - margin, pos.y + v.vy));
            }
        }
        return maxV;
    }

    /**
     * Mirror the 3D scene's keyword-chip filter: when one or more chips at the
     * bottom strip are active, dim cards whose keywords don't intersect the
     * active set. Diffed against the last snapshot to avoid per-frame DOM work
     * when nothing changed.
     */
    _syncActiveKeywordDim() {
        const active = this.app && this.app.activeKeywords;
        const snapshot = active && active.size > 0
            ? Array.from(active).sort().join('|').toLowerCase()
            : '';
        if (snapshot === this._lastActiveKeywordsSnapshot) return;
        this._lastActiveKeywordsSnapshot = snapshot;
        const filter = snapshot ? new Set(snapshot.split('|')) : null;
        for (const n of this.nodes) {
            const card = this.cardEls.get(n.id);
            if (!card) continue;
            let matches = true;
            if (filter) {
                matches = false;
                const kws = Array.isArray(n.keywords) ? n.keywords : [];
                for (const kw of kws) {
                    if (kw && filter.has(String(kw).toLowerCase())) { matches = true; break; }
                }
            }
            card.style.opacity = matches ? '1' : '0.18';
            card.style.transition = 'opacity 200ms ease';
        }
        // Also dim lines whose endpoints aren't both in the matching set.
        if (filter) {
            const matchingIds = new Set();
            for (const n of this.nodes) {
                const kws = Array.isArray(n.keywords) ? n.keywords : [];
                for (const kw of kws) {
                    if (kw && filter.has(String(kw).toLowerCase())) { matchingIds.add(n.id); break; }
                }
            }
            for (const [key, line] of this.lineEls) {
                const aId = parseInt(line.getAttribute('data-a'), 10);
                const bId = parseInt(line.getAttribute('data-b'), 10);
                const both = matchingIds.has(aId) && matchingIds.has(bId);
                line.style.opacity = both ? '1' : '0.15';
            }
        } else {
            for (const [, line] of this.lineEls) line.style.opacity = '1';
        }
    }

    _updateIdleOffsets(timestampMs) {
        const t = timestampMs / 1000;
        for (const n of this.nodes) {
            const p1 = hashPhase(String(n.id));
            const p2 = hashPhase(String(n.id) + '·y');
            const rx = IDLE_RADIUS_MIN + (IDLE_RADIUS_MAX - IDLE_RADIUS_MIN) * p1;
            const ry = IDLE_RADIUS_MIN + (IDLE_RADIUS_MAX - IDLE_RADIUS_MIN) * p2;
            const periodX = IDLE_PERIOD_MIN + (IDLE_PERIOD_MAX - IDLE_PERIOD_MIN) * p1;
            const periodY = IDLE_PERIOD_MIN + (IDLE_PERIOD_MAX - IDLE_PERIOD_MIN) * p2;
            const phaseX = p1 * Math.PI * 2;
            const phaseY = p2 * Math.PI * 2;
            const dx = Math.cos(t * (2 * Math.PI / periodX) + phaseX) * rx;
            const dy = Math.sin(t * (2 * Math.PI / periodY) + phaseY) * ry;
            let off = this.idleOffsets.get(n.id);
            if (!off) { off = { dx: 0, dy: 0 }; this.idleOffsets.set(n.id, off); }
            off.dx = dx; off.dy = dy;
        }
    }

    _renderFrame() {
        // Move cards.
        for (const n of this.nodes) {
            const pos = this.positions.get(n.id);
            const off = this.idleOffsets.get(n.id) || { dx: 0, dy: 0 };
            const card = this.cardEls.get(n.id);
            if (!pos || !card) continue;
            card.style.left = (pos.x + off.dx) + 'px';
            card.style.top = (pos.y + off.dy) + 'px';
        }
        // Update line endpoints — clip each end to the chip's rect boundary
        // so the stroke appears to enter the chip from outside instead of
        // passing through it. (The chip's pill is inscribed in its bounding
        // rect, so the rect boundary is exactly the visible chip edge.)
        for (const [, line] of this.lineEls) {
            const aId = parseInt(line.getAttribute('data-a'), 10);
            const bId = parseInt(line.getAttribute('data-b'), 10);
            const pa = this.positions.get(aId);
            const pb = this.positions.get(bId);
            if (!pa || !pb) continue;
            const oa = this.idleOffsets.get(aId) || { dx: 0, dy: 0 };
            const ob = this.idleOffsets.get(bId) || { dx: 0, dy: 0 };
            const cxA = pa.x + oa.dx;
            const cyA = pa.y + oa.dy;
            const cxB = pb.x + ob.dx;
            const cyB = pb.y + ob.dy;
            const sa = this.cardSizes.get(aId);
            const sb = this.cardSizes.get(bId);
            const e1 = sa ? this._clipToRect(cxA, cyA, sa.w, sa.h, cxB, cyB) : { x: cxA, y: cyA };
            const e2 = sb ? this._clipToRect(cxB, cyB, sb.w, sb.h, cxA, cyA) : { x: cxB, y: cyB };
            line.setAttribute('x1', String(e1.x));
            line.setAttribute('y1', String(e1.y));
            line.setAttribute('x2', String(e2.x));
            line.setAttribute('y2', String(e2.y));
        }
    }

    /**
     * Compute the point on an axis-aligned rect (centered at cx,cy, dims w×h)
     * where a ray toward (tx, ty) exits the rect. Used to land line endpoints
     * on the chip boundary instead of the chip center.
     */
    _clipToRect(cx, cy, w, h, tx, ty) {
        const dx = tx - cx;
        const dy = ty - cy;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 1e-6) return { x: cx, y: cy };
        const ux = dx / dist;
        const uy = dy / dist;
        const tVert = ux !== 0 ? (w / 2) / Math.abs(ux) : Infinity;
        const tHoriz = uy !== 0 ? (h / 2) / Math.abs(uy) : Infinity;
        const t = Math.min(tVert, tHoriz);
        return { x: cx + ux * t, y: cy + uy * t };
    }

    // ---------------------------------------------------------------------
    // Hover + click
    // ---------------------------------------------------------------------

    _onCardHover(nodeId, entering) {
        this.hoveredNodeId = entering ? nodeId : null;
        // Per-card pastel-glow line highlight.
        const neighbours = this.adjacency.get(nodeId);
        if (neighbours) {
            for (const [otherId] of neighbours) {
                const k = WormholeGrid2D._edgeKey(nodeId, otherId);
                const line = this.lineEls.get(k);
                if (!line) continue;
                const glow = line.getAttribute('data-glow') || '#cbd5e1';
                const baseW = parseFloat(line.getAttribute('data-base-width') || '1');
                if (entering) {
                    line.setAttribute('stroke-opacity', String(LINE_HIGHLIGHT_OPACITY));
                    line.setAttribute('stroke-width', String(baseW + 0.5));
                    line.style.filter = `drop-shadow(0 0 ${LINE_GLOW_HOVER_PX}px ${glow})`;
                } else {
                    line.setAttribute('stroke-opacity', String(LINE_BASE_OPACITY));
                    line.setAttribute('stroke-width', String(baseW));
                    line.style.filter = `drop-shadow(0 0 ${LINE_GLOW_REST_PX}px ${glow})`;
                }
            }
        }
        // Top-right hover panel + stepped connector line, mirroring the 3D
        // scene's treatment exactly. Reuses #node-tooltip and #tooltip-line-svg
        // so the visitor sees the same thing whether the scene is 3D or 2D.
        const tip = document.getElementById('node-tooltip');
        const tipLineSvg = document.getElementById('tooltip-line-svg');
        const tipLine = document.getElementById('tooltip-line');
        const card = this.cardEls.get(nodeId);
        if (!tip || !card) return;
        const node = this.nodes.find(n => n.id === nodeId);
        if (!node) return;
        if (entering) {
            tip.textContent = '';
            const pastel = card.dataset.pastel || '#ffffff';
            const name = document.createElement('div');
            name.style.cssText = 'font-weight:600;margin-bottom:2px;';
            name.textContent = node.node_type === 'cluster' ? this._clusterDisplayName(node) : (node.name || '#' + node.id);
            tip.appendChild(name);
            if (node.description && String(node.description).trim() !== '') {
                const desc = document.createElement('div');
                desc.style.cssText = 'opacity:0.75;font-size:0.75rem;margin-top:4px;line-height:1.4;';
                desc.textContent = String(node.description).trim();
                tip.appendChild(desc);
            }
            // Pinned at upper-right (top:3.5rem, right:3rem), same as the 3D
            // scene. We don't track the card vertically — the connector line
            // points to the card instead.
            Object.assign(tip.style, {
                position: 'fixed',
                top: '3.5rem',
                right: '3rem',
                left: 'auto',
                bottom: 'auto',
                maxWidth: '280px',
                padding: '12px 14px 10px',
                background: 'rgba(10, 10, 12, 0.92)',
                color: '#ffffff',
                borderRadius: '12px',
                border: `2px solid ${this._pastelRgba(pastel, 0.5)}`,
                backdropFilter: 'blur(8px)',
                WebkitBackdropFilter: 'blur(8px)',
                boxShadow: `0 8px 24px ${this._pastelRgba(pastel, 0.18)}`,
                visibility: 'visible',
                display: 'block',
                opacity: '1',
                zIndex: '200',
                transition: 'opacity 200ms ease',
                pointerEvents: 'none',
            });
            // Connector line — wait two frames so the tooltip's layout has
            // settled and its bounding rect is meaningful.
            requestAnimationFrame(() => requestAnimationFrame(() => {
                if (this.hoveredNodeId !== nodeId) return; // raced with another hover
                this._drawTooltipLine(card, tipLineSvg, tipLine, tip, pastel);
            }));
        } else {
            tip.style.opacity = '0';
            tip.style.visibility = 'hidden';
            if (tipLineSvg) tipLineSvg.style.opacity = '0';
        }
    }

    /**
     * Stepped orthogonal connector line from card center to the tooltip
     * panel's left edge — same shape the 3D scene uses, ported to screen-
     * pixel math (the tooltip-line-svg sits absolute in #canvas-container
     * with width/height 100%, so its local coords are container-relative).
     */
    _drawTooltipLine(card, tipLineSvg, tipLine, tip, pastel) {
        if (!tipLineSvg || !tipLine || !tip) return;
        const cardRect = card.getBoundingClientRect();
        const tipRect = tip.getBoundingClientRect();
        const parentRect = tipLineSvg.parentElement.getBoundingClientRect();
        const cardX = cardRect.left + cardRect.width / 2 - parentRect.left;
        const cardY = cardRect.top + cardRect.height / 2 - parentRect.top;
        const panelX = tipRect.left - parentRect.left;
        const panelY = tipRect.top + tipRect.height / 2 - parentRect.top;
        const midX = panelX + (cardX - panelX) * 0.35;
        const points = `${cardX},${cardY} ${midX},${cardY} ${midX},${panelY} ${panelX},${panelY}`;
        tipLine.setAttribute('points', points);
        tipLine.setAttribute('stroke', pastel);
        tipLine.setAttribute('stroke-opacity', '0.5');
        tipLineSvg.style.opacity = '1';
    }

    _onCardClick(n) {
        const app = this.app;
        if (!app) return;
        if (n.node_type === 'cluster' && n.cluster_key) {
            const cid = window.TELARIS_CONSTELLATION_ID;
            if (cid != null && app.transitionToCluster) {
                app.transitionToCluster(cid, n.cluster_key);
            }
            return;
        }
        if (n.node_type === 'portal' && n.target_constellation_id) {
            const url = app.constellationUrl
                ? app.constellationUrl(n.target_constellation_id, n.target_slug || null)
                : `/?constellation_id=${n.target_constellation_id}`;
            window.location.href = url;
            return;
        }
        const mesh = app.nodes && app.nodes.find(m => m && m.userData && m.userData.id === n.id);
        if (mesh && app.showRichMediaWindow) app.showRichMediaWindow(mesh);
    }

    // ---------------------------------------------------------------------
    // Lifecycle helpers
    // ---------------------------------------------------------------------

    _onResize() {
        if (!this.active) return;
        if (this._resizePending) return;
        this._resizePending = true;
        requestAnimationFrame(() => {
            this._resizePending = false;
            this.refresh();
        });
    }

    _pollForNodes() {
        if (this._polling) return;
        this._polling = true;
        let attempts = 0;
        const tick = () => {
            attempts++;
            if (!this.active) { this._polling = false; return; }
            if (this._pullNodeData().length > 0) {
                this._polling = false;
                this.refresh();
                return;
            }
            if (attempts < 60) setTimeout(tick, 200);
            else this._polling = false;
        };
        setTimeout(tick, 200);
    }

    static _edgeKey(a, b) {
        return a < b ? `${a}-${b}` : `${b}-${a}`;
    }
}

/** Deterministic 0..1 from a string. Same shape as keyword-canvas hashPhase. */
function hashPhase(s) {
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
    return ((h >>> 0) % 10000) / 10000;
}
