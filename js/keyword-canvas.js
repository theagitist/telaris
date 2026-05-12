/**
 * Keyword canvas — editor surface for authoring keyword-to-keyword relationships.
 *
 * Loaded by /edit/keyword-canvas.php. Expects window.TELARIS_KC populated with
 * { API_KEY, GALAXY_ID, GALAXY_NAME, GALAXY_SLUG, IS_ADMIN, CURRENT_USER_ID,
 *   BACK_URL, APP_VERSION }.
 *
 * Two authoring layers (see Polivoxia/Projects/Telaris/Keyword canvas — design.md):
 *   1. Positions — drag a keyword node to reposition. Saved with author + timestamp.
 *      Initial placement is server-side Poisson-disc; moved_by stays NULL until the
 *      editor actively drags. Force-directed physics is deferred to a follow-up;
 *      v1 keeps positions purely authored.
 *   2. Discrete named lines — click an anchor on keyword A, then an anchor on
 *      keyword B, optionally add a note, save. Lines carry per-relation provenance.
 *
 * Renders into the #kc-canvas SVG element. No external JS dependencies.
 */
(function () {
    'use strict';

    const KC = window.TELARIS_KC || {};
    const API_URL = '/api/keyword-canvas.php';
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const CANVAS_W = 2000;
    const CANVAS_H = 2000;
    // Sizing tuned so nodes are legible on the fit-to-data viewBox. The fit-to-data
    // zoom (see fitViewToData) is doing most of the "make it bigger" work; these
    // numbers stay close to the original 16/8 so the chips read as tidy UI rather
    // than oversized blocks.
    const NODE_FONT_SIZE = 16;
    const NODE_PAD_X = 13;
    const NODE_PAD_Y = 6;
    const ANCHOR_RADIUS_REST = 4;
    const ANCHOR_RADIUS_HOVER = 7;
    const SAVE_DEBOUNCE_MS = 300;
    const ZOOM_MIN = 0.3;
    const ZOOM_MAX = 3.0;
    // Movement in screen-px from pointerdown that flips a line-draw gesture
    // from "click-click" to "drag-release". Below this, pointerup is a no-op
    // (waiting for the second click); above it, pointerup finalizes onto the
    // anchor under the cursor (or cancels if no anchor there).
    const LINE_DRAG_THRESHOLD_PX = 5;
    // Chip size band, in screen pixels. We apply an extra per-node scale so the
    // chip never reads below CHIP_MIN_PX (unreadable on zoom-out) or above
    // CHIP_MAX_PX (oversized + unprofessional on zoom-in). Within the band the
    // chip scales naturally with the zoom.
    const CHIP_MIN_PX = 20;
    const CHIP_MAX_PX = 22;

    // Pastel palette + per-keyword hash, ported verbatim from js/keyword-chips.js
    // so all keyword chips across Telaris share the same color for the same name.
    // (Authoritative copy: js/keyword-chips.js CHIP_FG + colorIndexFor.)
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

    const svg = document.getElementById('kc-canvas');
    const stage = svg.parentElement; // .kc-stage
    const statusEl = document.getElementById('kc-status');
    const emptyEl = document.getElementById('kc-empty');

    // ---------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------
    const state = {
        keywords: new Map(),    // id -> { id, name }
        positions: new Map(),   // keyword_id -> { x, y, moved_by, moved_at }
        relations: new Map(),   // relation_id -> { id, a, b, created_by, created_at, note }
        // Draw state machine: 'idle' | 'drawing'
        drawState: 'idle',
        drawStartKwId: null,
        drawStartAnchor: null,  // 'top' | 'right' | 'bottom' | 'left'
        previewLine: null,      // <line> element following the cursor
        // Selection
        selectedRelId: null,
        // Pan/zoom (viewBox)
        view: { x: 0, y: 0, w: CANVAS_W, h: CANVAS_H },
        // Debounced save buffer: keyword_id -> { x, y }
        pendingSaves: new Map(),
        saveTimer: null,
        // Multi-select (rubber-band)
        selectedNodeIds: new Set(),
        rubberBand: null,    // { pointerId, startX, startY, currentX, currentY, rect, moved }
        groupDrag: null,     // { pointerId, anchorStart, startPositions: Map<kwId, {x,y}> }
        // Spacebar held → drag-on-empty pans instead of rubber-banding
        spaceDown: false,
    };

    // Current chip extra-scale factor (kept up to date by updateChipScale).
    let chipScale = 1;

    // ---------------------------------------------------------------------
    // Idle float — tiny orbital offset per chip, like the 3D wormhole scene's
    // floating nodes. Updated every animation frame from `performance.now()`,
    // applied at render time only (does NOT touch state.positions, so saves +
    // physics integration stay clean). Phase + per-axis radii / frequencies are
    // deterministic from the keyword name so reloads reproduce the same idle
    // motion.
    // ---------------------------------------------------------------------
    const IDLE_RADIUS_MIN = 3;   // canvas units
    const IDLE_RADIUS_MAX = 5;
    const IDLE_PERIOD_MIN = 6;   // seconds per full orbit
    const IDLE_PERIOD_MAX = 10;
    const idleOffsets = new Map(); // kwId -> { dx, dy }

    /** Deterministic 0-1 value from a string, for per-chip phase + tuning. */
    function hashPhase(s) {
        let h = 0;
        for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
        // bring into [0, 1)
        return ((h >>> 0) % 10000) / 10000;
    }

    function idleOffsetFor(kwId) {
        // Skip the idle drift for chips the user is actively dragging — having
        // the chip oscillate around the cursor would feel jittery and wrong.
        // isDragLocked is defined further down (hoisted at run time).
        if (isDragLocked(kwId)) return { dx: 0, dy: 0 };
        return idleOffsets.get(kwId) || { dx: 0, dy: 0 };
    }

    function updateIdleOffsets(timestampMs) {
        const t = timestampMs / 1000;
        state.keywords.forEach((kw, id) => {
            const p1 = hashPhase(kw.name);
            const p2 = hashPhase(kw.name + '·y');
            const rx = IDLE_RADIUS_MIN + (IDLE_RADIUS_MAX - IDLE_RADIUS_MIN) * p1;
            const ry = IDLE_RADIUS_MIN + (IDLE_RADIUS_MAX - IDLE_RADIUS_MIN) * p2;
            const periodX = IDLE_PERIOD_MIN + (IDLE_PERIOD_MAX - IDLE_PERIOD_MIN) * p1;
            const periodY = IDLE_PERIOD_MIN + (IDLE_PERIOD_MAX - IDLE_PERIOD_MIN) * p2;
            const phaseX = p1 * Math.PI * 2;
            const phaseY = p2 * Math.PI * 2;
            const dx = Math.cos(t * (2 * Math.PI / periodX) + phaseX) * rx;
            const dy = Math.sin(t * (2 * Math.PI / periodY) + phaseY) * ry;
            let off = idleOffsets.get(id);
            if (!off) { off = { dx: 0, dy: 0 }; idleOffsets.set(id, off); }
            off.dx = dx; off.dy = dy;
        });
    }

    // Hover state — which chip or relation the pointer is currently over, used
    // to dim non-connected chips/lines so the editor sees the local structure
    // at a glance. Updated by mouseenter/mouseleave handlers; consumed by the
    // render functions when assigning per-element opacity.
    const hoverState = { kwId: null, relId: null };

    // Physics ease-in — when kickPhysics() fires, the spring/repulsion forces
    // are scaled by a smoothstep over the first PHYSICS_EASE_IN_MS so the
    // motion blooms instead of jolting. Re-armed on every kick.
    let physicsKickedAt = 0;
    const PHYSICS_EASE_IN_MS = 320;

    // ---------------------------------------------------------------------
    // Util
    // ---------------------------------------------------------------------
    function setStatus(text, kind = 'idle') {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.style.color = kind === 'error' ? '#b91c1c'
                              : kind === 'saving' ? '#6b7280'
                              : kind === 'saved' ? '#059669'
                              : '#6b7280';
    }

    function escapeText(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    /**
     * Convert a clientX/Y from a pointer event to canvas (SVG) coordinates.
     * Uses the SVG's getScreenCTM() so it respects viewBox + preserveAspectRatio
     * letterboxing exactly. The earlier bounding-rect math drifted when the
     * stage's aspect ratio differed from the viewBox's, making the line
     * preview lag the cursor visibly.
     */
    function clientToCanvas(clientX, clientY) {
        const pt = svg.createSVGPoint();
        pt.x = clientX;
        pt.y = clientY;
        const ctm = svg.getScreenCTM();
        if (!ctm) return { x: 0, y: 0 };
        const xf = pt.matrixTransform(ctm.inverse());
        return { x: xf.x, y: xf.y };
    }

    function applyViewBox() {
        svg.setAttribute('viewBox',
            `${state.view.x} ${state.view.y} ${state.view.w} ${state.view.h}`);
    }

    /**
     * Recompute the per-chip scale factor based on the current zoom level. If a
     * chip's natural render size in pixels would fall outside [CHIP_MIN_PX,
     * CHIP_MAX_PX], an extra scale is applied so it sits at the boundary.
     * Returns true if the scale changed (caller can decide whether to re-render).
     */
    function updateChipScale() {
        const rect = svg.getBoundingClientRect();
        if (!rect.width || !state.view.w) return false;
        // Pixels per viewBox-unit on the current zoom.
        const pxPerUnit = rect.width / state.view.w;
        const naturalPxFont = NODE_FONT_SIZE * pxPerUnit;
        let next = 1;
        if (naturalPxFont < CHIP_MIN_PX) next = CHIP_MIN_PX / naturalPxFont;
        else if (naturalPxFont > CHIP_MAX_PX) next = CHIP_MAX_PX / naturalPxFont;
        if (Math.abs(next - chipScale) < 0.005) return false;
        chipScale = next;
        return true;
    }

    /**
     * Apply translate + chipScale transform to a single node group. Reads the
     * logical position from state and adds the per-chip idle-float offset so
     * the chip oscillates gently around its position. Drag-locked chips skip
     * the idle offset (see idleOffsetFor).
     */
    function setNodeTransform(g, kwId) {
        const pos = state.positions.get(kwId);
        if (!pos) return;
        const off = idleOffsetFor(kwId);
        const x = pos.x + off.dx;
        const y = pos.y + off.dy;
        g.setAttribute('transform', `translate(${x}, ${y}) scale(${chipScale})`);
    }

    /** Walk all node groups and re-apply transforms (used after a zoom change
     *  or any time the idle offsets advance — i.e. every animation frame). */
    function reapplyAllNodeTransforms() {
        state.positions.forEach((_pos, kwId) => {
            const g = layerNodes.querySelector(`[data-kc-node="${kwId}"]`);
            if (g) setNodeTransform(g, kwId);
        });
    }

    /** Set the initial view to the bbox of the keyword positions, with padding. */
    function fitViewToData() {
        const positions = Array.from(state.positions.values());
        if (positions.length === 0) return;
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        for (const p of positions) {
            if (p.x < minX) minX = p.x;
            if (p.x > maxX) maxX = p.x;
            if (p.y < minY) minY = p.y;
            if (p.y > maxY) maxY = p.y;
        }
        // Pad by 15% of each axis (or a generous floor for single-node galaxies).
        const dataW = Math.max(maxX - minX, NODE_PAD_X * 8);
        const dataH = Math.max(maxY - minY, NODE_PAD_Y * 8);
        const padX = Math.max(dataW * 0.15, 80);
        const padY = Math.max(dataH * 0.15, 80);
        state.view.x = minX - padX;
        state.view.y = minY - padY;
        state.view.w = dataW + padX * 2;
        state.view.h = dataH + padY * 2;
    }

    // ---------------------------------------------------------------------
    // Physics — Stage 1: lines-as-springs only.
    //
    // Each keyword_relations line acts as a soft spring between its two
    // endpoint chips with a target rest distance. Chips with moved_by != null
    // are kinematic — they contribute spring force to their neighbours but
    // are not moved by it (editorial placement is sacred). Chips currently
    // being dragged are also exempt for the duration of the drag.
    //
    // No global repulsion, no co-occurrence attraction. Those are Stage 2/3
    // and live in the keyword-canvas project memory note. The political
    // argument for keeping it this minimal: lines are the only explicit
    // editorial signal of "these are related" — physics that responds to
    // anything else would silently encode statistical claims as if they were
    // editorial decisions.
    //
    // Physics state is NEVER persisted. Each page load runs the loop from
    // the saved (authored + Poisson-seeded) state to a settled layout. The
    // algorithm is deterministic so the same inputs produce the same output.
    // ---------------------------------------------------------------------
    const PHYSICS_REST_DISTANCE = 240;    // canvas units; target spring length
    const PHYSICS_SPRING_K = 0.012;       // stiffness per frame (soft)
    const PHYSICS_DAMPING = 0.82;         // velocity multiplier per frame
    const PHYSICS_SETTLE_VELOCITY = 0.15; // canvas units/frame; below = at rest
    const PHYSICS_MAX_STEPS = 600;        // safety cap (~10s @ 60fps)
    // Stage 2: light global repulsion. Every pair of chips pushes each other
    // away with a Coulomb-style 1/r² falloff, but only within REPULSION_CUTOFF
    // — beyond that distance the force is zero, which keeps the layout from
    // expanding indefinitely to the canvas boundaries. REPULSION_K is tuned
    // weak enough that springs at rest length dominate; repulsion mostly
    // matters for chips that aren't bound by a line, and for breaking up
    // tight clusters of co-located chips.
    const PHYSICS_REPULSION_K = 600;      // Coulomb constant
    const PHYSICS_REPULSION_CUTOFF = 360; // canvas units; no repulsion beyond
    const PHYSICS_REPULSION_MIN_DIST = 30; // clamp r below this to avoid blow-up

    const physicsVelocities = new Map(); // kwId -> { vx, vy }
    let physicsActive = false;
    let physicsStepsRun = 0;

    // Single continuous animation loop. Always running while the canvas is
    // visible. Each frame:
    //   1. Advance idle-float offsets (so chips drift around their positions)
    //   2. Run one physics step (if active) with smoothstep-eased forces
    //   3. Apply all node transforms + line endpoint updates
    // Switching from a "physics-only RAF that stops on settle" to a continuous
    // loop is what lets idle motion be ambient — it runs even when the layout
    // is at rest.
    let animationRafId = null;

    function isPinned(kwId) {
        const p = state.positions.get(kwId);
        return !!(p && p.moved_by != null);
    }

    function isDragLocked(kwId) {
        if (dragCtx && dragCtx.kwId === kwId) return true;
        if (state.groupDrag && state.groupDrag.startPositions.has(kwId)) return true;
        return false;
    }

    /**
     * One physics tick. `easeFactor` (0..1) scales the applied forces — used
     * to smoothstep the ramp-up after a kick so motion blooms instead of
     * jolting. Does NOT directly render: the animation loop handles that
     * after the step. Returns the max chip velocity for settle detection.
     */
    function physicsStep(easeFactor) {
        const forces = new Map();

        // Pass 1: spring forces from each editorial relation (lines drawn).
        // This is the only attraction signal — Stage 3 (co-occurrence) is
        // explicitly off; see project_keyword_canvas.md for the rationale.
        state.relations.forEach(rel => {
            const pa = state.positions.get(rel.a);
            const pb = state.positions.get(rel.b);
            if (!pa || !pb) return;
            const dx = pb.x - pa.x;
            const dy = pb.y - pa.y;
            const dist = Math.sqrt(dx * dx + dy * dy) || 0.001;
            const stretch = dist - PHYSICS_REST_DISTANCE;
            const mag = PHYSICS_SPRING_K * stretch * easeFactor;
            const fx = (dx / dist) * mag;
            const fy = (dy / dist) * mag;
            let fa = forces.get(rel.a); if (!fa) { fa = { fx: 0, fy: 0 }; forces.set(rel.a, fa); }
            let fb = forces.get(rel.b); if (!fb) { fb = { fx: 0, fy: 0 }; forces.set(rel.b, fb); }
            fa.fx += fx; fa.fy += fy;
            fb.fx -= fx; fb.fy -= fy;
        });

        // Pass 2: global Coulomb-style repulsion between every pair of chips.
        // Only chips within REPULSION_CUTOFF interact, which keeps the cost
        // bounded and the layout from expanding indefinitely. Pinned chips
        // still apply force to their neighbours but don't receive it (the
        // velocity integration below ignores them entirely).
        const positionsArr = Array.from(state.positions.entries());
        for (let i = 0; i < positionsArr.length; i++) {
            const [idA, posA] = positionsArr[i];
            for (let j = i + 1; j < positionsArr.length; j++) {
                const [idB, posB] = positionsArr[j];
                const dx = posB.x - posA.x;
                const dy = posB.y - posA.y;
                let dist2 = dx * dx + dy * dy;
                if (dist2 > PHYSICS_REPULSION_CUTOFF * PHYSICS_REPULSION_CUTOFF) continue;
                let dist = Math.sqrt(dist2) || 0.001;
                if (dist < PHYSICS_REPULSION_MIN_DIST) dist = PHYSICS_REPULSION_MIN_DIST;
                const mag = (PHYSICS_REPULSION_K / (dist * dist)) * easeFactor;
                const fx = (dx / dist) * mag;
                const fy = (dy / dist) * mag;
                let fa = forces.get(idA); if (!fa) { fa = { fx: 0, fy: 0 }; forces.set(idA, fa); }
                let fb = forces.get(idB); if (!fb) { fb = { fx: 0, fy: 0 }; forces.set(idB, fb); }
                fa.fx -= fx; fa.fy -= fy;
                fb.fx += fx; fb.fy += fy;
            }
        }

        let maxV = 0;
        state.positions.forEach((pos, kwId) => {
            if (isPinned(kwId) || isDragLocked(kwId)) {
                physicsVelocities.delete(kwId);
                return;
            }
            const f = forces.get(kwId);
            if (!f) {
                physicsVelocities.delete(kwId);
                return;
            }
            let v = physicsVelocities.get(kwId);
            if (!v) { v = { vx: 0, vy: 0 }; physicsVelocities.set(kwId, v); }
            v.vx = (v.vx + f.fx) * PHYSICS_DAMPING;
            v.vy = (v.vy + f.fy) * PHYSICS_DAMPING;
            const speed = Math.sqrt(v.vx * v.vx + v.vy * v.vy);
            if (speed > maxV) maxV = speed;
            if (speed > 0.01) {
                pos.x = Math.max(0, Math.min(CANVAS_W, pos.x + v.vx));
                pos.y = Math.max(0, Math.min(CANVAS_H, pos.y + v.vy));
            }
        });
        return maxV;
    }

    function startAnimationLoop() {
        if (animationRafId) return;
        const tick = (timestampMs) => {
            updateIdleOffsets(timestampMs);
            if (physicsActive) {
                physicsStepsRun++;
                const elapsed = timestampMs - physicsKickedAt;
                const e = Math.max(0, Math.min(1, elapsed / PHYSICS_EASE_IN_MS));
                const easeFactor = e * e * (3 - 2 * e); // smoothstep
                const maxV = physicsStep(easeFactor);
                if (maxV < PHYSICS_SETTLE_VELOCITY && elapsed > PHYSICS_EASE_IN_MS) {
                    physicsActive = false;
                    physicsVelocities.clear();
                } else if (physicsStepsRun >= PHYSICS_MAX_STEPS) {
                    physicsActive = false;
                    physicsVelocities.clear();
                }
            }
            // Render every frame: idle offsets advance even when physics is at
            // rest, so chip transforms + line endpoints must follow.
            reapplyAllNodeTransforms();
            updateLinePositions();
            animationRafId = requestAnimationFrame(tick);
        };
        animationRafId = requestAnimationFrame(tick);
    }

    function startPhysicsLoop() {
        physicsActive = true;
        physicsStepsRun = 0;
        physicsKickedAt = performance.now();
    }

    function stopPhysicsLoop() {
        physicsActive = false;
        physicsVelocities.clear();
    }

    /**
     * Re-energize the simulation. Call this after any change that could shift
     * the equilibrium: new relation, deleted relation, chip released after
     * drag (so connected chips can adapt to the new pin position). Re-arms the
     * ease-in so the new motion blooms instead of jolting.
     */
    function kickPhysics() {
        physicsVelocities.clear();
        physicsActive = true;
        physicsStepsRun = 0;
        physicsKickedAt = performance.now();
    }

    // ---------------------------------------------------------------------
    // Hydration
    // ---------------------------------------------------------------------
    async function hydrate() {
        setStatus('Loading…', 'idle');
        try {
            const res = await fetch(`${API_URL}?galaxy_id=${encodeURIComponent(KC.GALAXY_ID)}`, {
                headers: { 'X-API-Key': KC.API_KEY },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            data.keywords.forEach(k => state.keywords.set(k.id, k));
            data.positions.forEach(p => state.positions.set(p.keyword_id, {
                x: p.canvas_x, y: p.canvas_y, moved_by: p.moved_by, moved_at: p.moved_at,
            }));
            data.relations.forEach(r => state.relations.set(r.id, r));
            if (state.keywords.size === 0) {
                if (emptyEl) emptyEl.hidden = false;
                setStatus('No keywords yet');
                return;
            }
            // Fit viewBox to the bbox of the data (with padding) so nodes land
            // at a comfortable initial zoom instead of being lost in the empty
            // Poisson-disc margins.
            fitViewToData();
            updateChipScale();
            renderAll();
            // Physics: settle the layout from saved positions; chips with
            // line attachments drift toward spring equilibrium, others get
            // mild global repulsion. Pinned chips stay put. The animation
            // loop also drives idle float (continuous, runs forever).
            startPhysicsLoop();
            startAnimationLoop();
            setStatus('Ready', 'saved');
        } catch (err) {
            setStatus(`Load failed: ${err.message}`, 'error');
            console.error('keyword-canvas hydrate', err);
        }
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------
    let layerBg, layerLines, layerNodes, layerPreview, layerPopup;

    function ensureLayers() {
        // Idempotent: build the z-layered groups exactly once.
        if (svg.querySelector('[data-kc-layer="lines"]')) return;
        svg.innerHTML = '';

        // <defs> with the dot-grid pattern. Static, non-animated background
        // texture — gives the canvas the "design tool" feel of a Figma /
        // Miro board without claiming any semantic content of its own.
        // Sized for visibility at default zoom (~0.7 px/canvas-unit): dots
        // at 4-unit radius render as ~3 px circles, spaced every 60 units.
        const defs = document.createElementNS(SVG_NS, 'defs');
        const pat = document.createElementNS(SVG_NS, 'pattern');
        pat.setAttribute('id', 'kc-dot-grid');
        pat.setAttribute('width', '60');
        pat.setAttribute('height', '60');
        pat.setAttribute('patternUnits', 'userSpaceOnUse');
        const dot = document.createElementNS(SVG_NS, 'circle');
        dot.setAttribute('cx', '30');
        dot.setAttribute('cy', '30');
        dot.setAttribute('r', '4');
        dot.setAttribute('fill', '#71717a');
        dot.setAttribute('fill-opacity', '0.45');
        pat.appendChild(dot);
        defs.appendChild(pat);
        svg.appendChild(defs);

        layerBg = document.createElementNS(SVG_NS, 'g');
        layerBg.setAttribute('data-kc-layer', 'bg');
        const bgRect = document.createElementNS(SVG_NS, 'rect');
        // Oversize so the grid stays present even when panned outside the
        // canonical [0,CANVAS_W]×[0,CANVAS_H] box.
        bgRect.setAttribute('x', String(-CANVAS_W));
        bgRect.setAttribute('y', String(-CANVAS_H));
        bgRect.setAttribute('width', String(CANVAS_W * 3));
        bgRect.setAttribute('height', String(CANVAS_H * 3));
        bgRect.setAttribute('fill', 'url(#kc-dot-grid)');
        bgRect.setAttribute('pointer-events', 'none');
        layerBg.appendChild(bgRect);
        svg.appendChild(layerBg);

        layerLines = document.createElementNS(SVG_NS, 'g');
        layerLines.setAttribute('data-kc-layer', 'lines');
        svg.appendChild(layerLines);

        layerNodes = document.createElementNS(SVG_NS, 'g');
        layerNodes.setAttribute('data-kc-layer', 'nodes');
        svg.appendChild(layerNodes);

        layerPreview = document.createElementNS(SVG_NS, 'g');
        layerPreview.setAttribute('data-kc-layer', 'preview');
        svg.appendChild(layerPreview);

        layerPopup = document.createElementNS(SVG_NS, 'g');
        layerPopup.setAttribute('data-kc-layer', 'popup');
        svg.appendChild(layerPopup);
    }

    function renderAll() {
        ensureLayers();
        applyViewBox();
        // Nodes first: renderNodes measures each chip's text and populates
        // nodeSizes. renderLines reads nodeSizes via anchorWorldPoint so the
        // line endpoints land on the keyword's anchor (not its center) — if
        // nodeSizes is empty, every anchorWorldPoint returns null and no lines
        // render.
        renderNodes();
        renderLines();
    }

    /**
     * Compute anchor points for a keyword node, given its rendered text size.
     * Returns { top: {x,y}, right: {x,y}, bottom: {x,y}, left: {x,y} }.
     */
    function anchorPoints(pos, w, h) {
        return {
            top:    { x: pos.x,             y: pos.y - h / 2 },
            right:  { x: pos.x + w / 2,     y: pos.y },
            bottom: { x: pos.x,             y: pos.y + h / 2 },
            left:   { x: pos.x - w / 2,     y: pos.y },
        };
    }

    /** Cached node-size map (recomputed lazily on first render of each node). */
    const nodeSizes = new Map(); // keyword_id -> { w, h }

    function renderNodes() {
        layerNodes.innerHTML = '';
        state.keywords.forEach((kw, id) => {
            const pos = state.positions.get(id);
            if (!pos) return;

            const g = document.createElementNS(SVG_NS, 'g');
            g.setAttribute('data-kc-node', String(id));
            setNodeTransform(g, id);
            g.style.cursor = 'grab';

            // Pill — match the chip style used everywhere else in Telaris
            // (edit/index.php preview, keyword-chips visitor strip): pastel
            // background at 25% opacity, pastel border at 25% opacity, pastel
            // text at full opacity. Authoritative palette + hash in
            // js/keyword-chips.js. Sized after text is measured.
            const pastel = CHIP_FG[colorIndexFor(kw.name)];
            const rect = document.createElementNS(SVG_NS, 'rect');
            rect.setAttribute('fill', pastel);
            rect.setAttribute('fill-opacity', '0.25');
            const isSelected = state.selectedNodeIds.has(id);
            // Selection outline replaces the resting pastel border. White +
            // dashed, pixel-pinned thickness via non-scaling-stroke, persists
            // across drags (selectedNodeIds isn't cleared on drag-release).
            if (isSelected) {
                rect.setAttribute('stroke', '#ffffff');
                rect.setAttribute('stroke-width', '2.5');
                rect.setAttribute('stroke-dasharray', '4,3');
            } else {
                rect.setAttribute('stroke', pastel);
                rect.setAttribute('stroke-opacity', '0.4');
                rect.setAttribute('stroke-width', '1');
                rect.removeAttribute('stroke-dasharray');
            }
            rect.setAttribute('vector-effect', 'non-scaling-stroke');
            g.appendChild(rect);

            const text = document.createElementNS(SVG_NS, 'text');
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'central');
            text.setAttribute('font-family', 'ui-sans-serif, system-ui, sans-serif');
            text.setAttribute('font-size', String(NODE_FONT_SIZE));
            text.setAttribute('font-weight', '500');
            text.setAttribute('fill', pastel);
            text.setAttribute('pointer-events', 'none');
            text.textContent = `#${kw.name}`;
            g.appendChild(text);

            layerNodes.appendChild(g);

            // Measure rendered text to size the background pill.
            const bbox = text.getBBox();
            const w = Math.ceil(bbox.width + NODE_PAD_X * 2);
            const h = Math.ceil(bbox.height + NODE_PAD_Y * 2);
            nodeSizes.set(id, { w, h });
            rect.setAttribute('x', String(-w / 2));
            rect.setAttribute('y', String(-h / 2));
            rect.setAttribute('width', String(w));
            rect.setAttribute('height', String(h));
            rect.setAttribute('rx', String(h / 2));
            rect.setAttribute('ry', String(h / 2));

            // Anchor dots — barely visible at rest, brightened on hover. Light
            // gray reads against both pastel pill and black background. Each
            // anchor renders as two circles: a visible dot (no pointer events)
            // and an invisible hit circle ~3× wider on top of it. Clicking
            // anywhere inside the hit zone counts as an anchor click; hover
            // styling redirects to the visible dot via a JS property reference.
            const anchors = anchorPoints({ x: 0, y: 0 }, w, h);
            const HIT_RADIUS = ANCHOR_RADIUS_REST * 3;
            Object.entries(anchors).forEach(([side, ap]) => {
                const dot = document.createElementNS(SVG_NS, 'circle');
                dot.setAttribute('cx', String(ap.x));
                dot.setAttribute('cy', String(ap.y));
                dot.setAttribute('r', String(ANCHOR_RADIUS_REST));
                dot.setAttribute('fill', '#e5e7eb');
                dot.setAttribute('stroke', '#1f2937');
                dot.setAttribute('stroke-width', '1');
                dot.setAttribute('opacity', '0.4');
                dot.setAttribute('pointer-events', 'none');
                g.appendChild(dot);

                const hit = document.createElementNS(SVG_NS, 'circle');
                hit.setAttribute('cx', String(ap.x));
                hit.setAttribute('cy', String(ap.y));
                hit.setAttribute('r', String(HIT_RADIUS));
                hit.setAttribute('fill', 'transparent');
                hit.setAttribute('pointer-events', 'all');
                hit.style.cursor = 'crosshair';
                hit.setAttribute('data-kc-anchor', side);
                hit.setAttribute('data-kc-keyword', String(id));
                hit._kcVisibleDot = dot;
                g.appendChild(hit);
            });
        });
    }

    /**
     * Compute the world-space (canvas) coordinates of a keyword's anchor on a
     * given side. Accounts for the current chipScale so the line endpoint
     * lands exactly on the visible anchor dot regardless of zoom level.
     */
    function anchorWorldPoint(kwId, side) {
        const pos = state.positions.get(kwId);
        const size = nodeSizes.get(kwId);
        if (!pos || !size) return null;
        let dx = 0, dy = 0;
        switch (side) {
            case 'top':    dy = -size.h / 2; break;
            case 'right':  dx = size.w / 2;  break;
            case 'bottom': dy = size.h / 2;  break;
            case 'left':   dx = -size.w / 2; break;
            default:       dx = size.w / 2;  break;
        }
        // Include the idle-float offset so the line endpoint travels with the
        // visible anchor as the chip oscillates around its logical position.
        const off = idleOffsetFor(kwId);
        return { x: pos.x + off.dx + dx * chipScale, y: pos.y + off.dy + dy * chipScale };
    }

    /**
     * Rebuild every line element from scratch. Called when the set of
     * relations changes (create / delete / select toggle) or on full
     * renderAll. For per-frame position updates (idle drift, physics), the
     * animation loop calls the cheaper updateLinePositions() helper.
     */
    function renderLines() {
        layerLines.innerHTML = '';
        state.relations.forEach((rel, id) => {
            // Each relation renders as two stacked <line>s:
            //  1. Visible line — thin (1.25 / 2 px), pointer-events disabled,
            //     with a soft drop-shadow glow keyed to the average of the two
            //     endpoint chips' colours (so the line reads as a connection
            //     with presence, not a flat stroke).
            //  2. Hit line — transparent stroke ~14 px wide on top, pointer-
            //     events enabled. Catches clicks anywhere in a band around
            //     the visible stroke so selecting a 1.25 px line isn't finicky.
            //
            // Both lines carry data-kc-relation so updateLinePositions and the
            // hover handlers can find them.
            const ptA = anchorWorldPoint(rel.a, rel.anchor_a || 'right');
            const ptB = anchorWorldPoint(rel.b, rel.anchor_b || 'left');
            if (!ptA || !ptB) return;

            // Per-line glow colour from the average of the two endpoint
            // chips' pastel colours. Cheap blend via parsing the two hex codes.
            const kwA = state.keywords.get(rel.a);
            const kwB = state.keywords.get(rel.b);
            const glowHex = kwA && kwB
                ? blendHex(CHIP_FG[colorIndexFor(kwA.name)], CHIP_FG[colorIndexFor(kwB.name)])
                : '#cbd5e1';

            const visible = document.createElementNS(SVG_NS, 'line');
            visible.setAttribute('x1', String(ptA.x));
            visible.setAttribute('y1', String(ptA.y));
            visible.setAttribute('x2', String(ptB.x));
            visible.setAttribute('y2', String(ptB.y));
            visible.setAttribute('stroke', state.selectedRelId === id ? '#93c5fd' : glowHex);
            visible.setAttribute('vector-effect', 'non-scaling-stroke');
            visible.setAttribute('stroke-width', state.selectedRelId === id ? '2' : '1.25');
            visible.setAttribute('stroke-opacity', state.selectedRelId === id ? '0.95' : '0.6');
            visible.setAttribute('pointer-events', 'none');
            visible.setAttribute('data-kc-relation', String(id));
            visible.setAttribute('data-kc-line-visible', '1');
            visible.setAttribute('data-kc-glow', glowHex);
            visible.style.filter = `drop-shadow(0 0 3px ${glowHex})`;
            visible.style.transition = 'stroke-opacity 160ms ease-out, stroke-width 160ms ease-out, filter 160ms ease-out';
            layerLines.appendChild(visible);

            const hit = document.createElementNS(SVG_NS, 'line');
            hit.setAttribute('x1', String(ptA.x));
            hit.setAttribute('y1', String(ptA.y));
            hit.setAttribute('x2', String(ptB.x));
            hit.setAttribute('y2', String(ptB.y));
            hit.setAttribute('stroke', 'transparent');
            hit.setAttribute('vector-effect', 'non-scaling-stroke');
            hit.setAttribute('stroke-width', '14');
            hit.setAttribute('pointer-events', 'stroke');
            hit.setAttribute('data-kc-relation', String(id));
            hit.setAttribute('data-kc-line-hit', '1');
            hit.style.cursor = 'pointer';
            const title = document.createElementNS(SVG_NS, 'title');
            const auth = rel.created_by ? `${rel.created_by}` : '(no author)';
            const when = rel.created_at ? ` · ${rel.created_at}` : '';
            const note = rel.note ? `\n${rel.note}` : '';
            title.textContent = `${auth}${when}${note}`;
            hit.appendChild(title);
            layerLines.appendChild(hit);
        });
        applyHoverStyles();
    }

    /**
     * Per-frame update for line endpoints: walk the existing DOM elements and
     * rewrite x1/y1/x2/y2 only. Much cheaper than the full innerHTML='…' +
     * rebuild that renderLines does. Used by the animation loop.
     */
    function updateLinePositions() {
        if (!layerLines || state.relations.size === 0) return;
        const lines = layerLines.querySelectorAll('line[data-kc-relation]');
        for (const el of lines) {
            const id = parseInt(el.getAttribute('data-kc-relation'), 10);
            const rel = state.relations.get(id);
            if (!rel) continue;
            const ptA = anchorWorldPoint(rel.a, rel.anchor_a || 'right');
            const ptB = anchorWorldPoint(rel.b, rel.anchor_b || 'left');
            if (!ptA || !ptB) continue;
            el.setAttribute('x1', String(ptA.x));
            el.setAttribute('y1', String(ptA.y));
            el.setAttribute('x2', String(ptB.x));
            el.setAttribute('y2', String(ptB.y));
        }
    }

    /** Average two #rrggbb colours into a single hex string. */
    function blendHex(a, b) {
        const pa = parseInt(a.slice(1), 16);
        const pb = parseInt(b.slice(1), 16);
        const r = Math.round((((pa >> 16) & 0xff) + ((pb >> 16) & 0xff)) / 2);
        const g = Math.round((((pa >> 8) & 0xff) + ((pb >> 8) & 0xff)) / 2);
        const bl = Math.round(((pa & 0xff) + (pb & 0xff)) / 2);
        return '#' + [r, g, bl].map(v => v.toString(16).padStart(2, '0')).join('');
    }

    /**
     * Hover-reveals-structure. Hovering a chip thickens + brightens the lines
     * connecting it to its neighbours; hovering a line does the same for that
     * single line. No chip dimming, no opacity changes on non-connected
     * lines — the highlight is purely additive, the canvas stays readable.
     *
     * Each line's resting glow colour is stored on `data-kc-glow` by
     * renderLines; on highlight we layer a second drop-shadow with a wider
     * blur to thicken the bloom. The transition declarations on the line's
     * inline style (set in renderLines) animate stroke-width, opacity, and
     * filter smoothly.
     */
    function applyHoverStyles() {
        if (!layerLines) return;
        let activeRels = new Set();
        if (hoverState.kwId != null) {
            state.relations.forEach((rel, id) => {
                if (rel.a === hoverState.kwId || rel.b === hoverState.kwId) {
                    activeRels.add(id);
                }
            });
        } else if (hoverState.relId != null) {
            activeRels.add(hoverState.relId);
        }
        const visibles = layerLines.querySelectorAll('line[data-kc-line-visible]');
        for (const el of visibles) {
            const id = parseInt(el.getAttribute('data-kc-relation'), 10);
            const glow = el.getAttribute('data-kc-glow') || '#cbd5e1';
            if (activeRels.has(id)) {
                el.style.strokeWidth = '2.5';
                el.style.strokeOpacity = '1';
                el.style.filter = `drop-shadow(0 0 6px ${glow}) drop-shadow(0 0 14px ${glow})`;
            } else {
                el.style.strokeWidth = '';
                el.style.strokeOpacity = '';
                el.style.filter = `drop-shadow(0 0 3px ${glow})`;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Save (debounced)
    // ---------------------------------------------------------------------
    function queueSavePosition(kwId, x, y) {
        state.pendingSaves.set(kwId, { x, y });
        if (state.saveTimer) clearTimeout(state.saveTimer);
        setStatus('Saving…', 'saving');
        state.saveTimer = setTimeout(flushSaves, SAVE_DEBOUNCE_MS);
    }

    async function flushSaves() {
        if (state.pendingSaves.size === 0) return;
        const entries = Array.from(state.pendingSaves.entries());
        state.pendingSaves.clear();
        try {
            // The API accepts one move per request; fire them in parallel.
            await Promise.all(entries.map(([kwId, { x, y }]) =>
                fetch(API_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Key': KC.API_KEY,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'move_keyword', keyword_id: kwId, x, y }),
                }).then(r => {
                    if (!r.ok) throw new Error(`move_keyword ${kwId}: HTTP ${r.status}`);
                    // Track local authorship in state for future provenance display.
                    const pos = state.positions.get(kwId);
                    if (pos) {
                        pos.moved_by = KC.CURRENT_USER_ID;
                        pos.moved_at = new Date().toISOString();
                    }
                })
            ));
            setStatus('Saved', 'saved');
            // Intentionally no renderNodes() here: in-place transform updates
            // during drag already reflect the new positions, and re-rendering
            // would flash the selection outline (we want it persistent so the
            // same group can be dragged again without re-selecting).
        } catch (err) {
            setStatus(`Save failed: ${err.message}`, 'error');
            console.error('keyword-canvas save', err);
        }
    }

    // ---------------------------------------------------------------------
    // Node drag
    // ---------------------------------------------------------------------
    let dragCtx = null; // { kwId, pointerId, offsetX, offsetY, gEl }

    svg.addEventListener('pointerdown', (ev) => {
        const target = ev.target;
        // Anchor click? Start drawing a line.
        const anchorSide = target.getAttribute && target.getAttribute('data-kc-anchor');

        // While drawing, anything that isn't an anchor click cancels the draw.
        // That covers empty-space clicks (Adri's request) plus accidental
        // node-body / line clicks where the editor probably meant an anchor.
        if (state.drawState === 'drawing' && !anchorSide) {
            cancelLineDraw();
            ev.preventDefault();
            return;
        }

        if (anchorSide) {
            const kwId = parseInt(target.getAttribute('data-kc-keyword'), 10);
            startLineDraw(kwId, anchorSide, ev);
            ev.preventDefault();
            return;
        }
        // Existing relation line clicked? Select it.
        const relId = target.getAttribute && target.getAttribute('data-kc-relation');
        if (relId) {
            selectRelation(parseInt(relId, 10), ev);
            ev.preventDefault();
            return;
        }
        // Node body? Start dragging.
        const nodeG = target.closest('[data-kc-node]');
        if (nodeG) {
            const kwId = parseInt(nodeG.getAttribute('data-kc-node'), 10);
            startNodeDrag(kwId, nodeG, ev);
            ev.preventDefault();
            return;
        }
        // Background → pan when Space is held or middle-button; otherwise
        // rubber-band select (standard convention for graph editors).
        if (state.spaceDown || ev.button === 1) {
            startBackgroundPan(ev);
        } else {
            startRubberBand(ev);
        }
    });

    function startNodeDrag(kwId, gEl, ev) {
        const pos = state.positions.get(kwId);
        if (!pos) return;
        const start = clientToCanvas(ev.clientX, ev.clientY);

        // If this node is part of a multi-selection, drag the whole group.
        if (state.selectedNodeIds.has(kwId) && state.selectedNodeIds.size > 1) {
            const startPositions = new Map();
            state.selectedNodeIds.forEach(id => {
                const p = state.positions.get(id);
                if (p) startPositions.set(id, { x: p.x, y: p.y });
            });
            state.groupDrag = {
                pointerId: ev.pointerId,
                anchorStart: start,
                startPositions,
            };
            svg.setPointerCapture(ev.pointerId);
            gEl.style.cursor = 'grabbing';
            return;
        }

        // Single-node drag: clear any prior multi-selection so the visual cue
        // doesn't lie about what's being moved.
        if (state.selectedNodeIds.size > 0) {
            state.selectedNodeIds.clear();
            renderNodes();
        }
        dragCtx = {
            kwId, pointerId: ev.pointerId, gEl,
            offsetX: start.x - pos.x,
            offsetY: start.y - pos.y,
        };
        svg.setPointerCapture(ev.pointerId);
        gEl.style.cursor = 'grabbing';
    }

    svg.addEventListener('pointermove', (ev) => {
        if (dragCtx && ev.pointerId === dragCtx.pointerId) {
            const pt = clientToCanvas(ev.clientX, ev.clientY);
            const newX = Math.max(0, Math.min(CANVAS_W, pt.x - dragCtx.offsetX));
            const newY = Math.max(0, Math.min(CANVAS_H, pt.y - dragCtx.offsetY));
            const pos = state.positions.get(dragCtx.kwId);
            if (pos) {
                pos.x = newX; pos.y = newY;
                setNodeTransform(dragCtx.gEl, dragCtx.kwId);
                renderLines(); // lines glued to centers
            }
            return;
        }
        if (state.groupDrag && ev.pointerId === state.groupDrag.pointerId) {
            const pt = clientToCanvas(ev.clientX, ev.clientY);
            const dx = pt.x - state.groupDrag.anchorStart.x;
            const dy = pt.y - state.groupDrag.anchorStart.y;
            state.groupDrag.startPositions.forEach((startPos, kwId) => {
                const pos = state.positions.get(kwId);
                if (!pos) return;
                pos.x = Math.max(0, Math.min(CANVAS_W, startPos.x + dx));
                pos.y = Math.max(0, Math.min(CANVAS_H, startPos.y + dy));
                const g = layerNodes.querySelector(`[data-kc-node="${kwId}"]`);
                if (g) setNodeTransform(g, kwId);
            });
            renderLines();
            return;
        }
        if (state.rubberBand && ev.pointerId === state.rubberBand.pointerId) {
            updateRubberBand(ev);
            return;
        }
        if (state.drawState === 'drawing') {
            updateLinePreview(ev);
            return;
        }
        if (panCtx && ev.pointerId === panCtx.pointerId) {
            updateBackgroundPan(ev);
            return;
        }
    });

    svg.addEventListener('pointerup', (ev) => {
        // Drag-to-draw release: if a draw is in progress AND the pointer moved
        // past the click/drag threshold, treat release as the second-anchor
        // selection. Released on another keyword's anchor → finalize; anywhere
        // else → cancel. (No movement = click flow, untouched here.)
        if (state.drawState === 'drawing' && state.drawIsDragging) {
            const t = ev.target;
            const anchorSide = t && t.getAttribute && t.getAttribute('data-kc-anchor');
            if (anchorSide) {
                const targetKwId = parseInt(t.getAttribute('data-kc-keyword'), 10);
                if (targetKwId && targetKwId !== state.drawStartKwId) {
                    finalizeLineDraw(targetKwId, anchorSide);
                    return;
                }
            }
            cancelLineDraw();
            return;
        }
        if (dragCtx && ev.pointerId === dragCtx.pointerId) {
            const { kwId, gEl } = dragCtx;
            const pos = state.positions.get(kwId);
            if (pos) queueSavePosition(kwId, pos.x, pos.y);
            gEl.style.cursor = 'grab';
            try { svg.releasePointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
            dragCtx = null;
            // The dragged chip now has a fresh pinned position. Re-energize
            // physics so connected chips can settle around the new anchor.
            kickPhysics();
            return;
        }
        if (state.groupDrag && ev.pointerId === state.groupDrag.pointerId) {
            // Save the new position of every group-dragged node.
            state.groupDrag.startPositions.forEach((_, kwId) => {
                const pos = state.positions.get(kwId);
                if (pos) queueSavePosition(kwId, pos.x, pos.y);
            });
            try { svg.releasePointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
            state.groupDrag = null;
            kickPhysics();
            return;
        }
        if (state.rubberBand && ev.pointerId === state.rubberBand.pointerId) {
            endRubberBand(ev);
            return;
        }
        if (panCtx && ev.pointerId === panCtx.pointerId) {
            try { svg.releasePointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
            panCtx = null;
            return;
        }
    });

    // ---------------------------------------------------------------------
    // Rubber-band selection (drag on empty space) + group drag
    // ---------------------------------------------------------------------
    function startRubberBand(ev) {
        const pt = clientToCanvas(ev.clientX, ev.clientY);
        const rect = document.createElementNS(SVG_NS, 'rect');
        rect.setAttribute('fill', '#3b82f6');
        rect.setAttribute('fill-opacity', '0.12');
        rect.setAttribute('stroke', '#3b82f6');
        rect.setAttribute('stroke-width', '1');
        rect.setAttribute('stroke-dasharray', '4,3');
        rect.setAttribute('pointer-events', 'none');
        layerPreview.appendChild(rect);
        state.rubberBand = {
            pointerId: ev.pointerId,
            startX: pt.x, startY: pt.y,
            currentX: pt.x, currentY: pt.y,
            rect, moved: false,
        };
        svg.setPointerCapture(ev.pointerId);
        applyRubberBandRect();
    }

    function updateRubberBand(ev) {
        const rb = state.rubberBand;
        if (!rb) return;
        const pt = clientToCanvas(ev.clientX, ev.clientY);
        rb.currentX = pt.x;
        rb.currentY = pt.y;
        rb.moved = true;
        applyRubberBandRect();
    }

    function applyRubberBandRect() {
        const rb = state.rubberBand;
        if (!rb) return;
        const x = Math.min(rb.startX, rb.currentX);
        const y = Math.min(rb.startY, rb.currentY);
        const w = Math.abs(rb.currentX - rb.startX);
        const h = Math.abs(rb.currentY - rb.startY);
        rb.rect.setAttribute('x', String(x));
        rb.rect.setAttribute('y', String(y));
        rb.rect.setAttribute('width', String(w));
        rb.rect.setAttribute('height', String(h));
    }

    function endRubberBand(ev) {
        const rb = state.rubberBand;
        if (!rb) return;
        if (rb.moved) {
            // Select every node whose center is inside the rect.
            const x1 = Math.min(rb.startX, rb.currentX);
            const y1 = Math.min(rb.startY, rb.currentY);
            const x2 = Math.max(rb.startX, rb.currentX);
            const y2 = Math.max(rb.startY, rb.currentY);
            state.selectedNodeIds.clear();
            state.positions.forEach((pos, kwId) => {
                if (pos.x >= x1 && pos.x <= x2 && pos.y >= y1 && pos.y <= y2) {
                    state.selectedNodeIds.add(kwId);
                }
            });
            renderNodes();
        } else if (state.selectedNodeIds.size > 0) {
            // Click without drag → clear selection.
            state.selectedNodeIds.clear();
            renderNodes();
        }
        rb.rect.remove();
        state.rubberBand = null;
        try { svg.releasePointerCapture(ev.pointerId); } catch (e) { /* ignore */ }
    }

    function cancelRubberBand() {
        const rb = state.rubberBand;
        if (!rb) return;
        rb.rect.remove();
        state.rubberBand = null;
    }

    // ---------------------------------------------------------------------
    // Pan + zoom
    // ---------------------------------------------------------------------
    let panCtx = null; // { pointerId, startView, startClient }

    function startBackgroundPan(ev) {
        panCtx = {
            pointerId: ev.pointerId,
            startView: { ...state.view },
            startClient: { x: ev.clientX, y: ev.clientY },
        };
        svg.setPointerCapture(ev.pointerId);
    }
    function updateBackgroundPan(ev) {
        if (!panCtx) return;
        const rect = svg.getBoundingClientRect();
        const dx = (ev.clientX - panCtx.startClient.x) / rect.width * state.view.w;
        const dy = (ev.clientY - panCtx.startClient.y) / rect.height * state.view.h;
        state.view.x = panCtx.startView.x - dx;
        state.view.y = panCtx.startView.y - dy;
        applyViewBox();
    }

    svg.addEventListener('wheel', (ev) => {
        ev.preventDefault();
        const factor = ev.deltaY > 0 ? 1.1 : 1 / 1.1;
        const focus = clientToCanvas(ev.clientX, ev.clientY);
        const newW = state.view.w * factor;
        const newH = state.view.h * factor;
        const totalZoom = CANVAS_W / newW;
        if (totalZoom < ZOOM_MIN || totalZoom > ZOOM_MAX) return;
        // Zoom around the cursor: keep `focus` at the same screen position.
        state.view.x = focus.x - (focus.x - state.view.x) * factor;
        state.view.y = focus.y - (focus.y - state.view.y) * factor;
        state.view.w = newW;
        state.view.h = newH;
        applyViewBox();
        if (updateChipScale()) {
            reapplyAllNodeTransforms();
            renderLines(); // anchor world-points depend on chipScale
        }
    }, { passive: false });

    window.addEventListener('resize', () => {
        if (updateChipScale()) {
            reapplyAllNodeTransforms();
            renderLines();
        }
    });

    // ---------------------------------------------------------------------
    // Line drawing — anchor → anchor → note popup
    // ---------------------------------------------------------------------
    function startLineDraw(kwId, side, ev) {
        if (state.drawState === 'drawing' && state.drawStartKwId === kwId) {
            // Same node clicked twice — refuse silently (self-loop).
            cancelLineDraw();
            return;
        }
        if (state.drawState === 'drawing') {
            // Second click on a different node's anchor → finalize.
            finalizeLineDraw(kwId, side);
            return;
        }
        // First click / pointerdown. Record the origin so we can tell drag from
        // click on pointerup.
        state.drawState = 'drawing';
        state.drawStartKwId = kwId;
        state.drawStartAnchor = side;
        state.drawClientStart = { x: ev.clientX, y: ev.clientY };
        state.drawIsDragging = false;
        // Pulse the anchor dots on every chip OTHER than the source chip to
        // advertise "you can drop here." The svg-level kc-drawing class drives
        // the CSS animation; the source chip is tagged so the CSS can exclude
        // its anchors from the pulse.
        svg.classList.add('kc-drawing');
        const sourceG = layerNodes.querySelector(`[data-kc-node="${kwId}"]`);
        if (sourceG) sourceG.setAttribute('data-kc-draw-source', '1');
        const pt = clientToCanvas(ev.clientX, ev.clientY);
        state.previewLine = document.createElementNS(SVG_NS, 'line');
        state.previewLine.setAttribute('x1', String(pt.x));
        state.previewLine.setAttribute('y1', String(pt.y));
        state.previewLine.setAttribute('x2', String(pt.x));
        state.previewLine.setAttribute('y2', String(pt.y));
        state.previewLine.setAttribute('stroke', '#93c5fd');
        state.previewLine.setAttribute('vector-effect', 'non-scaling-stroke');
        state.previewLine.setAttribute('stroke-width', '1.75');
        state.previewLine.setAttribute('stroke-dasharray', '6,4');
        state.previewLine.setAttribute('stroke-opacity', '0.85');
        state.previewLine.setAttribute('pointer-events', 'none');
        layerPreview.appendChild(state.previewLine);
        setStatus("Drag to another anchor, or click one (Esc to cancel)");
    }
    function updateLinePreview(ev) {
        if (!state.previewLine) return;
        const pt = clientToCanvas(ev.clientX, ev.clientY);
        state.previewLine.setAttribute('x2', String(pt.x));
        state.previewLine.setAttribute('y2', String(pt.y));
        // Promote click-flow to drag-flow once the pointer has moved enough.
        if (!state.drawIsDragging && state.drawClientStart) {
            const dx = ev.clientX - state.drawClientStart.x;
            const dy = ev.clientY - state.drawClientStart.y;
            if (dx * dx + dy * dy >
                LINE_DRAG_THRESHOLD_PX * LINE_DRAG_THRESHOLD_PX) {
                state.drawIsDragging = true;
            }
        }
    }
    function cancelLineDraw() {
        state.drawState = 'idle';
        state.drawStartKwId = null;
        state.drawStartAnchor = null;
        state.drawClientStart = null;
        state.drawIsDragging = false;
        if (state.previewLine) {
            state.previewLine.remove();
            state.previewLine = null;
        }
        // Stop the anchor pulse and clear the source-chip tag.
        svg.classList.remove('kc-drawing');
        const tagged = layerNodes && layerNodes.querySelector('[data-kc-draw-source="1"]');
        if (tagged) tagged.removeAttribute('data-kc-draw-source');
        setStatus('Ready', 'saved');
    }
    async function finalizeLineDraw(targetKwId, targetSide) {
        const sourceKwId = state.drawStartKwId;
        const sourceSide = state.drawStartAnchor;
        cancelLineDraw();
        if (!sourceKwId || sourceKwId === targetKwId) return;
        // Already exists?
        const lo = Math.min(sourceKwId, targetKwId);
        const hi = Math.max(sourceKwId, targetKwId);
        for (const rel of state.relations.values()) {
            if (rel.a === lo && rel.b === hi) {
                setStatus('Already related', 'idle');
                return;
            }
        }
        // Prompt for an optional note before save.
        const note = await promptForNote(sourceKwId, targetKwId);
        if (note === null) return; // cancelled
        setStatus('Saving…', 'saving');
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-API-Key': KC.API_KEY },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'create_relation',
                    keyword_a_id: sourceKwId,
                    keyword_b_id: targetKwId,
                    anchor_a: sourceSide || 'right',
                    anchor_b: targetSide || 'left',
                    note: note || null,
                }),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            state.relations.set(data.id, {
                id: data.id, a: data.a, b: data.b,
                anchor_a: data.anchor_a,
                anchor_b: data.anchor_b,
                created_by: data.created_by,
                created_at: new Date().toISOString(),
                note: data.note,
            });
            renderLines();
            // Brief flash on the new line — pure CSS animation triggered by
            // toggling the kc-flash class for ~720ms. Pairs with the spring
            // pull-together from kickPhysics() to give the editorial act a
            // visible heartbeat.
            const newLine = layerLines.querySelector(
                `line[data-kc-line-visible][data-kc-relation="${data.id}"]`
            );
            if (newLine) {
                newLine.classList.add('kc-flash');
                setTimeout(() => newLine.classList.remove('kc-flash'), 720);
            }
            kickPhysics();
            setStatus('Saved', 'saved');
        } catch (err) {
            setStatus(`Create failed: ${err.message}`, 'error');
        }
    }

    // ---------------------------------------------------------------------
    // daisyui-backed modal helpers — replace browser prompt()/confirm() with
    // the same dialog patterns the rest of the admin uses.
    // ---------------------------------------------------------------------

    /**
     * Open the note-input modal. Returns a promise that resolves to the entered
     * note string, or null if the editor cancelled.
     *
     * @param {Object} opts - { title, pair, initialNote }
     */
    function openNoteModal({ title, pair, initialNote }) {
        return new Promise((resolve) => {
            const dlg = document.getElementById('kc-note-modal');
            const titleEl = document.getElementById('kc-note-modal-title');
            const pairEl = document.getElementById('kc-note-modal-pair');
            const input = document.getElementById('kc-note-modal-input');
            const saveBtn = document.getElementById('kc-note-modal-save');
            const cancelBtn = document.getElementById('kc-note-modal-cancel');
            if (!dlg || !input || !saveBtn || !cancelBtn) {
                resolve(null);
                return;
            }
            titleEl.textContent = title;
            pairEl.textContent = pair;
            input.value = initialNote || '';
            let settled = false;
            const cleanup = () => {
                saveBtn.removeEventListener('click', onSave);
                cancelBtn.removeEventListener('click', onCancel);
                dlg.removeEventListener('close', onClose);
                input.removeEventListener('keydown', onKey);
            };
            const onSave = () => { if (settled) return; settled = true; cleanup(); dlg.close(); resolve(input.value); };
            const onCancel = () => { if (settled) return; settled = true; cleanup(); dlg.close(); resolve(null); };
            const onClose = () => { if (settled) return; settled = true; cleanup(); resolve(null); };
            const onKey = (e) => {
                // Ctrl/Cmd-Enter saves; plain Enter inserts a newline (textarea default).
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    onSave();
                }
            };
            saveBtn.addEventListener('click', onSave);
            cancelBtn.addEventListener('click', onCancel);
            dlg.addEventListener('close', onClose);
            input.addEventListener('keydown', onKey);
            dlg.showModal();
            input.focus();
            input.select();
        });
    }

    /**
     * Open the relation-inspector modal for an existing line. Returns a promise
     * that resolves to one of: 'edit', 'delete', or null (closed).
     *
     * @param {Object} opts - { pairLabel, metaLine, note, canEdit }
     */
    function openLineModal({ pairLabel, metaLine, note, canEdit }) {
        return new Promise((resolve) => {
            const dlg = document.getElementById('kc-line-modal');
            const pairEl = document.getElementById('kc-line-modal-pair');
            const metaEl = document.getElementById('kc-line-modal-meta');
            const noteWrap = document.getElementById('kc-line-modal-note-wrap');
            const noteEl = document.getElementById('kc-line-modal-note');
            const noauthEl = document.getElementById('kc-line-modal-noauth');
            const editBtn = document.getElementById('kc-line-modal-edit');
            const deleteBtn = document.getElementById('kc-line-modal-delete');
            const closeBtn = document.getElementById('kc-line-modal-close');
            if (!dlg) { resolve(null); return; }
            pairEl.textContent = pairLabel;
            metaEl.textContent = metaLine;
            if (note) {
                noteWrap.hidden = false;
                noteEl.textContent = note;
            } else {
                noteWrap.hidden = true;
                noteEl.textContent = '';
            }
            noauthEl.hidden = !!canEdit;
            editBtn.hidden = !canEdit;
            deleteBtn.hidden = !canEdit;
            let settled = false;
            const cleanup = () => {
                editBtn.removeEventListener('click', onEdit);
                deleteBtn.removeEventListener('click', onDelete);
                closeBtn.removeEventListener('click', onClose);
                dlg.removeEventListener('close', onDlgClose);
            };
            const onEdit = () => { if (settled) return; settled = true; cleanup(); dlg.close(); resolve('edit'); };
            const onDelete = () => { if (settled) return; settled = true; cleanup(); dlg.close(); resolve('delete'); };
            const onClose = () => { if (settled) return; settled = true; cleanup(); dlg.close(); resolve(null); };
            const onDlgClose = () => { if (settled) return; settled = true; cleanup(); resolve(null); };
            editBtn.addEventListener('click', onEdit);
            deleteBtn.addEventListener('click', onDelete);
            closeBtn.addEventListener('click', onClose);
            dlg.addEventListener('close', onDlgClose);
            dlg.showModal();
        });
    }

    /** Open the note-input modal for a brand-new line. */
    function promptForNote(aId, bId) {
        const aName = state.keywords.get(aId)?.name || '(?)';
        const bName = state.keywords.get(bId)?.name || '(?)';
        return openNoteModal({
            title: 'New relation',
            pair: `#${aName} ↔ #${bName}`,
            initialNote: '',
        });
    }

    // ---------------------------------------------------------------------
    // Relation selection + edit/delete
    // ---------------------------------------------------------------------
    async function selectRelation(relId, ev) {
        state.selectedRelId = relId;
        // Modal will steal focus from the SVG, so no pointerout fires when
        // the dialog closes and the cursor is no longer on the line. Clear
        // hover state now so the line doesn't read as "still hovered" once
        // the user dismisses the modal.
        hoverState.kwId = null;
        hoverState.relId = null;
        renderLines();
        const rel = state.relations.get(relId);
        if (!rel) { state.selectedRelId = null; return; }
        const aName = state.keywords.get(rel.a)?.name || '(?)';
        const bName = state.keywords.get(rel.b)?.name || '(?)';
        const pairLabel = `#${aName} ↔ #${bName}`;
        // Owner check for edit/delete: only author or admin.
        const canEdit = KC.IS_ADMIN || String(rel.created_by) === String(KC.CURRENT_USER_ID);
        const metaLine = (rel.created_by ? `Authored by ${rel.created_by}` : 'No author recorded')
            + (rel.created_at ? ` · ${rel.created_at}` : '');

        const action = await openLineModal({
            pairLabel, metaLine, note: rel.note || '', canEdit,
        });
        state.selectedRelId = null;
        renderLines();
        if (!canEdit || !action) return;

        if (action === 'edit') {
            const newNote = await openNoteModal({
                title: 'Edit relation note',
                pair: pairLabel,
                initialNote: rel.note || '',
            });
            if (newNote === null) return;
            try {
                setStatus('Saving…', 'saving');
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-API-Key': KC.API_KEY },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'update_relation', relation_id: relId, note: newNote || null }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                rel.note = newNote || null;
                renderLines();
                setStatus('Saved', 'saved');
            } catch (err) {
                setStatus(`Update failed: ${err.message}`, 'error');
            }
        } else if (action === 'delete') {
            // No confirmation step: recreating a relation is cheap
            // (click-anchor → click-anchor, or drag-anchor → drag-anchor).
            try {
                setStatus('Saving…', 'saving');
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-API-Key': KC.API_KEY },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'delete_relation', relation_id: relId }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                state.relations.delete(relId);
                renderLines();
                kickPhysics();
                setStatus('Deleted', 'saved');
            } catch (err) {
                setStatus(`Delete failed: ${err.message}`, 'error');
            }
        }
    }

    // ---------------------------------------------------------------------
    // Hover — anchor pulse (existing) + reveals-structure for chips & lines.
    // ---------------------------------------------------------------------
    svg.addEventListener('pointerover', (ev) => {
        const t = ev.target;
        // Anchor dot hover: enlarge + brighten the visible dot via the JS-
        // attached reference set in renderNodes.
        if (t && t.getAttribute && t.getAttribute('data-kc-anchor') && t._kcVisibleDot) {
            t._kcVisibleDot.setAttribute('opacity', '1');
            t._kcVisibleDot.setAttribute('r', String(ANCHOR_RADIUS_HOVER));
        }
        // Chip hover: dim non-connected chips + non-connected lines. While
        // drawing, suppress hover-reveal so the anchor pulse stays the focus.
        if (state.drawState !== 'drawing') {
            const nodeG = t && t.closest && t.closest('[data-kc-node]');
            if (nodeG) {
                const id = parseInt(nodeG.getAttribute('data-kc-node'), 10);
                if (hoverState.kwId !== id) {
                    hoverState.kwId = id;
                    hoverState.relId = null;
                    applyHoverStyles();
                }
                return;
            }
            // Line hover: highlight the two endpoint chips.
            const relAttr = t && t.getAttribute && t.getAttribute('data-kc-relation');
            if (relAttr) {
                const id = parseInt(relAttr, 10);
                if (hoverState.relId !== id) {
                    hoverState.relId = id;
                    hoverState.kwId = null;
                    applyHoverStyles();
                }
            }
        }
    });
    svg.addEventListener('pointerout', (ev) => {
        const t = ev.target;
        if (t && t.getAttribute && t.getAttribute('data-kc-anchor') && t._kcVisibleDot) {
            t._kcVisibleDot.setAttribute('opacity', '0.4');
            t._kcVisibleDot.setAttribute('r', String(ANCHOR_RADIUS_REST));
        }
        // For chip/line hover, only clear when leaving the SVG to the
        // outside, or to a different chip/line (handled by the subsequent
        // pointerover). Walking via relatedTarget keeps the dim from
        // flickering as the pointer crosses inner SVG geometry.
        const rt = ev.relatedTarget;
        const stillInside = rt && rt.closest && (
            rt.closest('[data-kc-node]') || rt.closest('[data-kc-relation]')
        );
        if (!stillInside && (hoverState.kwId != null || hoverState.relId != null)) {
            hoverState.kwId = null;
            hoverState.relId = null;
            applyHoverStyles();
        }
    });

    // ---------------------------------------------------------------------
    // Keyboard
    //  - Escape cancels in-progress draw / rubber-band, or clears selection.
    //  - Space (held) switches drag-on-empty from rubber-band to pan
    //    (Figma / Miro / draw.io convention).
    // ---------------------------------------------------------------------
    function isTypingTarget(el) {
        if (!el) return false;
        if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') return true;
        if (el.isContentEditable) return true;
        return false;
    }
    document.addEventListener('keydown', (ev) => {
        if (isTypingTarget(ev.target)) return;
        if (ev.key === 'Escape') {
            if (state.drawState === 'drawing') { cancelLineDraw(); return; }
            if (state.rubberBand) { cancelRubberBand(); return; }
            if (state.selectedNodeIds.size > 0) {
                state.selectedNodeIds.clear();
                renderNodes();
            }
            return;
        }
        if ((ev.key === ' ' || ev.code === 'Space') && !state.spaceDown) {
            state.spaceDown = true;
            svg.style.cursor = 'grab';
            ev.preventDefault(); // suppress page scroll
        }
    });
    document.addEventListener('keyup', (ev) => {
        if (ev.key === ' ' || ev.code === 'Space') {
            state.spaceDown = false;
            svg.style.cursor = '';
        }
    });

    // ---------------------------------------------------------------------
    // Init
    // ---------------------------------------------------------------------
    if (!KC.API_KEY || !KC.GALAXY_ID) {
        setStatus('Page configuration is missing window.TELARIS_KC', 'error');
    } else {
        hydrate();
    }
})();
