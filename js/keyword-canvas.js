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
    const CHIP_MIN_PX = 14;
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

    /** Apply translate + chipScale transform to a single node group. */
    function setNodeTransform(g, x, y) {
        g.setAttribute('transform', `translate(${x}, ${y}) scale(${chipScale})`);
    }

    /** Walk all node groups and re-apply transforms (used after a zoom change). */
    function reapplyAllNodeTransforms() {
        state.positions.forEach((pos, kwId) => {
            const g = layerNodes.querySelector(`[data-kc-node="${kwId}"]`);
            if (g) setNodeTransform(g, pos.x, pos.y);
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
            setStatus('Ready', 'saved');
        } catch (err) {
            setStatus(`Load failed: ${err.message}`, 'error');
            console.error('keyword-canvas hydrate', err);
        }
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------
    let layerLines, layerNodes, layerPreview, layerPopup;

    function ensureLayers() {
        // Idempotent: build the three z-layered groups exactly once.
        if (svg.querySelector('[data-kc-layer="lines"]')) return;
        svg.innerHTML = '';
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
            setNodeTransform(g, pos.x, pos.y);
            g.style.cursor = 'grab';

            // Pill background — pastel fill keyed by keyword name. Sized after
            // text is measured. Rounded corners (rx >= h/2) make it a true pill.
            const pastel = CHIP_FG[colorIndexFor(kw.name)];
            const rect = document.createElementNS(SVG_NS, 'rect');
            rect.setAttribute('fill', pastel);
            const isSelected = state.selectedNodeIds.has(id);
            // Selection outline: pixel-pinned thickness via non-scaling-stroke,
            // bright white + dashed so it pops against any pastel fill. This
            // outline persists across drags (state.selectedNodeIds is preserved
            // through group-drag-release, so the same chips can be dragged again).
            rect.setAttribute('stroke', isSelected ? '#ffffff' : 'none');
            rect.setAttribute('stroke-width', isSelected ? '2.5' : '0');
            rect.setAttribute('vector-effect', 'non-scaling-stroke');
            if (isSelected) {
                rect.setAttribute('stroke-dasharray', '4,3');
            } else {
                rect.removeAttribute('stroke-dasharray');
            }
            g.appendChild(rect);

            const text = document.createElementNS(SVG_NS, 'text');
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'central');
            text.setAttribute('font-family', 'ui-sans-serif, system-ui, sans-serif');
            text.setAttribute('font-size', String(NODE_FONT_SIZE));
            text.setAttribute('font-weight', '500');
            text.setAttribute('fill', '#1f2937');
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
        return { x: pos.x + dx * chipScale, y: pos.y + dy * chipScale };
    }

    function renderLines() {
        layerLines.innerHTML = '';
        state.relations.forEach((rel, id) => {
            // Each relation renders as two stacked <line>s:
            //  1. Visible line — thin (1.25 / 2 px), pointer-events disabled.
            //  2. Hit line — transparent stroke ~14 px wide on top, pointer-events
            //     enabled. Catches clicks anywhere within a band around the
            //     visible stroke so selecting a 1.25 px line isn't finicky.
            //
            // Line endpoints are computed from each keyword's *anchor* (where
            // the editor dropped the line) — not the chip center. Legacy rows
            // without anchor info default to 'right'/'left'.
            const ptA = anchorWorldPoint(rel.a, rel.anchor_a || 'right');
            const ptB = anchorWorldPoint(rel.b, rel.anchor_b || 'left');
            if (!ptA || !ptB) return;

            const visible = document.createElementNS(SVG_NS, 'line');
            visible.setAttribute('x1', String(ptA.x));
            visible.setAttribute('y1', String(ptA.y));
            visible.setAttribute('x2', String(ptB.x));
            visible.setAttribute('y2', String(ptB.y));
            visible.setAttribute('stroke', state.selectedRelId === id ? '#93c5fd' : '#cbd5e1');
            visible.setAttribute('vector-effect', 'non-scaling-stroke');
            visible.setAttribute('stroke-width', state.selectedRelId === id ? '2' : '1.25');
            visible.setAttribute('stroke-opacity', state.selectedRelId === id ? '0.95' : '0.55');
            visible.setAttribute('pointer-events', 'none');
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
            hit.style.cursor = 'pointer';
            const title = document.createElementNS(SVG_NS, 'title');
            const auth = rel.created_by ? `${rel.created_by}` : '(no author)';
            const when = rel.created_at ? ` · ${rel.created_at}` : '';
            const note = rel.note ? `\n${rel.note}` : '';
            title.textContent = `${auth}${when}${note}`;
            hit.appendChild(title);
            layerLines.appendChild(hit);
        });
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
                setNodeTransform(dragCtx.gEl, newX, newY);
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
                if (g) setNodeTransform(g, pos.x, pos.y);
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
                setStatus('Deleted', 'saved');
            } catch (err) {
                setStatus(`Delete failed: ${err.message}`, 'error');
            }
        }
    }

    // ---------------------------------------------------------------------
    // Anchor hover (purely visual)
    // ---------------------------------------------------------------------
    svg.addEventListener('pointerover', (ev) => {
        const t = ev.target;
        if (t && t.getAttribute && t.getAttribute('data-kc-anchor') && t._kcVisibleDot) {
            t._kcVisibleDot.setAttribute('opacity', '1');
            t._kcVisibleDot.setAttribute('r', String(ANCHOR_RADIUS_HOVER));
        }
    });
    svg.addEventListener('pointerout', (ev) => {
        const t = ev.target;
        if (t && t.getAttribute && t.getAttribute('data-kc-anchor') && t._kcVisibleDot) {
            t._kcVisibleDot.setAttribute('opacity', '0.4');
            t._kcVisibleDot.setAttribute('r', String(ANCHOR_RADIUS_REST));
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
