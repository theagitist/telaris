/**
 * Shared galaxy-edit modal logic for /admin/index.php and /edit/index.php.
 *
 * Both pages must already provide on `window`:
 *   - GALAXY_EDIT_API_URL  (string, e.g. '../api/constellations.php' or 'api/constellations.php')
 *   - GALAXY_EDIT_API_KEY  (string)
 *   - escapeHtmlAdmin(str) (string-escaping helper, already used by both pages)
 *
 * Loaded as a regular <script> (no module), so it just defines functions on
 * window and binds DOM listeners on DOMContentLoaded.
 */
(function () {
    // Pastel palette + per-keyword hash — duplicated from js/keyword-chips.js
    // (authoritative copy) because this script can't `import`. Used to style
    // the per-keyword tour selectors as pastel pills, matching the chip look
    // everywhere else (visitor chip strip, keyword canvas, 2D wormhole grid).
    const KW_PALETTE = [
        '#fca5a5', '#fdba74', '#fcd34d', '#fde047', '#bef264', '#86efac',
        '#6ee7b7', '#5eead4', '#67e8f9', '#7dd3fc', '#93c5fd', '#a5b4fc',
        '#c4b5fd', '#d8b4fe', '#f0abfc', '#f9a8d4', '#fda4af',
    ];
    function kwColorIndex(s) {
        let h = 0;
        for (let i = 0; i < s.length; i++) h = s.charCodeAt(i) + ((h << 5) - h);
        return Math.abs(h) % KW_PALETTE.length;
    }
    function kwPastel(s) { return KW_PALETTE[kwColorIndex(s)]; }
    function kwPastelBg(s) {
        const p = parseInt(kwPastel(s).slice(1), 16);
        const r = (p >> 16) & 0xff, g = (p >> 8) & 0xff, b = p & 0xff;
        return `rgba(${r},${g},${b},0.18)`;
    }
    function kwPastelBorder(s) {
        const p = parseInt(kwPastel(s).slice(1), 16);
        const r = (p >> 16) & 0xff, g = (p >> 8) & 0xff, b = p & 0xff;
        return `rgba(${r},${g},${b},0.5)`;
    }
    'use strict';

    const GXM = window.TELARIS_GXM || {};
    // printf-style %d/%s substituter for parametric localized strings.
    function tFmt(tpl, ...args) {
        if (!tpl) return '';
        let i = 0;
        return String(tpl).replace(/%[ds]/g, () => (i < args.length ? String(args[i++]) : ''));
    }

    function getApiUrl() {
        return window.GALAXY_EDIT_API_URL || '../api/constellations.php';
    }
    function getApiKey() {
        return window.GALAXY_EDIT_API_KEY || '';
    }
    function escape(str) {
        return typeof window.escapeHtmlAdmin === 'function'
            ? window.escapeHtmlAdmin(str)
            : String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // The create and edit galaxy windows are ONE modal (#constellation_modal). Create
    // mode shows only the basics (name/tagline/slug/theme) and an explicit Create button
    // doing a native POST; edit mode shows the full config + the live autosave chip.
    let galaxyMode = 'edit';  // 'create' lets the form POST natively; 'edit' autosaves
    function setGalaxyMode(mode) {
        galaxyMode = mode;
        const isCreate = mode === 'create';
        const show = (id, on) => { const el = document.getElementById(id); if (el) el.classList.toggle('hidden', !on); };
        show('gem-heading-create', isCreate);
        show('gem-heading-edit', !isCreate);
        show('gem-edit-only', !isCreate);   // tags/discovery/spotlight/tours/bulk need an existing galaxy
        show('gem-submit-btn', isCreate);
        show('gem-autosave-status', !isCreate);
        const actionEl = document.querySelector('#constellation_modal input[name="action"]');
        if (actionEl) actionEl.value = isCreate ? 'create_constellation' : 'update_constellation';
    }

    // Open the shared modal in CREATE mode (admin "New Galaxy"). Blank the basics, keep
    // autosave suspended (a new galaxy has no id), and let the Create button POST natively.
    function openCreateConstellation() {
        gemAutosave.beginPopulate(); // installs listeners, stays suspended
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        set('modal-constellation-id', '');
        set('modal-constellation-name', '');
        set('modal-constellation-slug', '');
        set('modal-constellation-tagline', '');
        set('modal-constellation-theme', 'cosmic');
        set('modal-galaxy-tags-hidden', '');
        const badge = document.getElementById('modal-constellation-id-badge');
        if (badge) badge.textContent = '';
        const nameErr = document.getElementById('modal-constellation-name-error');
        if (nameErr) nameErr.classList.add('hidden');
        const slugErr = document.getElementById('modal-constellation-slug-error');
        if (slugErr) slugErr.classList.add('hidden');
        const gemEe = document.getElementById('modal-constellation-editors-enabled');
        if (gemEe) gemEe.checked = true; // new galaxies allow editors by default
        setGalaxyMode('create');
        document.getElementById('constellation_modal').showModal();
        // No endPopulate(): autosave stays suspended; the explicit Create button POSTs.
    }

    async function editConstellation(c) {
        gemAutosave.beginPopulate(); // suspend autosave + install listeners while we fill fields
        setGalaxyMode('edit');
        document.getElementById('modal-constellation-id').value = c.id;
        document.getElementById('modal-constellation-name').value = c.name || '';
        const slugEl = document.getElementById('modal-constellation-slug');
        if (slugEl) slugEl.value = c.slug || '';
        document.getElementById('modal-constellation-tagline').value = c.tagline || '';
        document.getElementById('modal-constellation-theme').value = c.theme || 'cosmic';
        const gemEe = document.getElementById('modal-constellation-editors-enabled');
        if (gemEe) gemEe.checked = (c.editors_enabled !== false);
        const badge = document.getElementById('modal-constellation-id-badge');
        if (badge) badge.textContent = '#' + c.id;
        const feedback = document.getElementById('modal-bulk-feedback');
        if (feedback) { feedback.textContent = ''; feedback.style.color = ''; }
        await Promise.all([
            loadTourConfigIntoModal(c.id),
            loadGalaxyTagsIntoModal(c.id),
        ]);
        document.getElementById('constellation_modal').showModal();
        gemAutosave.endPopulate(); // resume autosave now that the form reflects the galaxy
    }

    // ---------------------------------------------------------------------
    // Galaxy tag chip input (Idea 3 — multi-galaxy union by tag).
    // ---------------------------------------------------------------------
    // State is local to the modal instance; we re-fetch on every open so we never
    // leak a previous galaxy's tags. Suggestions are bucketed (current/siblings/global)
    // by api/tags.php so we can label sources differently in the dropdown.

    const galaxyTagState = { labels: [], suggestions: { current: [], siblings: [], global: [] } };

    function tagsApiUrl() {
        // GALAXY_EDIT_API_URL points at constellations.php (relative to caller); same dir as tags.php.
        return getApiUrl().replace(/[^/]+$/, 'tags.php');
    }

    function renderGalaxyTagChips() {
        const container = document.getElementById('modal-galaxy-tags-container');
        const input = document.getElementById('modal-galaxy-tags-input');
        const hidden = document.getElementById('modal-galaxy-tags-hidden');
        if (!container || !input || !hidden) return;

        // Wipe existing chips
        container.querySelectorAll('.galaxy-tag-chip').forEach(el => el.remove());

        galaxyTagState.labels.forEach((label, idx) => {
            const chip = document.createElement('span');
            chip.className = 'galaxy-tag-chip badge bg-gray-100 text-gray-800 border border-gray-300 gap-2 py-3 px-3';
            chip.innerHTML = `${escape(label)}<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block w-4 h-4 stroke-current cursor-pointer hover:opacity-70" data-galaxy-tag-remove="${idx}"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>`;
            container.insertBefore(chip, input);
        });

        hidden.value = galaxyTagState.labels.join(',');

        // Tag add/remove auto-saves (no-op while populating or closed).
        gemAutosave.saveNow();
    }

    function addGalaxyTag(label) {
        const t = (label || '').trim().replace(/,/g, '');
        if (!t) return false;
        const existing = galaxyTagState.labels.map(l => l.toLowerCase());
        if (existing.includes(t.toLowerCase())) return false;
        galaxyTagState.labels.push(t);
        renderGalaxyTagChips();
        return true;
    }

    function removeGalaxyTag(idx) {
        if (idx < 0 || idx >= galaxyTagState.labels.length) return;
        galaxyTagState.labels.splice(idx, 1);
        renderGalaxyTagChips();
    }

    function showGalaxyTagSuggestions(filter) {
        const box = document.getElementById('modal-galaxy-tags-suggestions');
        if (!box) return;
        const f = (filter || '').trim().toLowerCase();
        const taken = new Set(galaxyTagState.labels.map(l => l.toLowerCase()));

        // Merge buckets (current galaxy / prefix-siblings / global) into one deduped,
        // alphabetically-sorted list. Origin doesn't matter; we just want to see what
        // tags exist anywhere.
        const merged = new Map(); // lowercase label -> { label, slug, count }
        const all = [
            ...(galaxyTagState.suggestions.current  || []),
            ...(galaxyTagState.suggestions.siblings || []),
            ...(galaxyTagState.suggestions.global   || []),
        ];
        for (const item of all) {
            if (!item || typeof item.label !== 'string' || item.label === '') continue;
            const lc = item.label.toLowerCase();
            if (taken.has(lc)) continue;
            if (f && !(lc.includes(f) || (item.slug || '').toLowerCase().includes(f))) continue;
            const existing = merged.get(lc);
            if (existing) {
                existing.count = (existing.count || 0) + (item.count || 0);
            } else {
                merged.set(lc, { label: item.label, slug: item.slug || null, count: item.count || 0 });
            }
        }

        if (merged.size === 0) {
            box.classList.add('hidden');
            box.innerHTML = '';
            return;
        }

        const sorted = Array.from(merged.values()).sort((a, b) =>
            a.label.localeCompare(b.label, undefined, { sensitivity: 'base' })
        );

        const rows = sorted.slice(0, 50).map(m => {
            const count = (typeof m.count === 'number' && m.count > 0) ? `<span class="text-xs text-gray-400 ml-2">${m.count}</span>` : '';
            return `<div class="px-3 py-1.5 hover:bg-gray-50 cursor-pointer flex items-center justify-between gap-2" data-galaxy-tag-suggest="${escape(m.label)}"><span class="badge bg-gray-100 text-gray-800 border border-gray-300 px-2 py-2 text-xs whitespace-nowrap">${escape(m.label)}</span>${count}</div>`;
        });

        box.innerHTML = rows.join('');
        box.classList.remove('hidden');
    }

    function hideGalaxyTagSuggestions() {
        const box = document.getElementById('modal-galaxy-tags-suggestions');
        if (box) { box.classList.add('hidden'); box.innerHTML = ''; }
    }

    async function loadGalaxyTagsIntoModal(galaxyId) {
        galaxyTagState.labels = [];
        galaxyTagState.suggestions = { current: [], siblings: [], global: [] };
        renderGalaxyTagChips();
        hideGalaxyTagSuggestions();
        try {
            const r = await fetch(`${tagsApiUrl()}?galaxy_id=${galaxyId}`, { headers: { 'X-API-Key': getApiKey(), 'X-CSRF-Token': (window.TELARIS_CSRF_TOKEN || '') } });
            if (!r.ok) throw new Error('GXM_LOAD_TAGS_FAILED');
            const data = await r.json();
            galaxyTagState.suggestions.current = (data.current || []).map(t => ({ label: t.label, slug: t.slug }));
            galaxyTagState.suggestions.siblings = (data.siblings || []).map(t => ({ label: t.label, slug: t.slug, count: t.count }));
            galaxyTagState.suggestions.global = (data.global || []).map(t => ({ label: t.label, slug: t.slug, count: t.count }));
            // Pre-fill chips from the 'current' bucket — those are the tags already assigned.
            galaxyTagState.labels = galaxyTagState.suggestions.current.map(t => t.label);
            renderGalaxyTagChips();
        } catch (e) {
            // Silent fail: editor can still type tags free-form; autocomplete just won't help.
        }
    }

    function bindGalaxyTagInput() {
        const input = document.getElementById('modal-galaxy-tags-input');
        const container = document.getElementById('modal-galaxy-tags-container');
        const box = document.getElementById('modal-galaxy-tags-suggestions');
        if (!input || !container) return;

        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' || ev.key === ',') {
                ev.preventDefault();
                if (addGalaxyTag(input.value)) {
                    input.value = '';
                    showGalaxyTagSuggestions('');
                }
            } else if (ev.key === 'Backspace' && input.value === '') {
                if (galaxyTagState.labels.length > 0) {
                    removeGalaxyTag(galaxyTagState.labels.length - 1);
                }
            } else if (ev.key === 'Escape') {
                hideGalaxyTagSuggestions();
            }
        });
        input.addEventListener('input', () => showGalaxyTagSuggestions(input.value));
        input.addEventListener('focus', () => showGalaxyTagSuggestions(input.value));

        // Click handlers (delegation): suggestion picks + chip removes.
        container.addEventListener('click', (ev) => {
            const removeIdx = ev.target.closest('[data-galaxy-tag-remove]');
            if (removeIdx) {
                removeGalaxyTag(parseInt(removeIdx.getAttribute('data-galaxy-tag-remove'), 10));
                input.focus();
                return;
            }
        });
        if (box) {
            box.addEventListener('mousedown', (ev) => {
                const pick = ev.target.closest('[data-galaxy-tag-suggest]');
                if (!pick) return;
                ev.preventDefault();
                addGalaxyTag(pick.getAttribute('data-galaxy-tag-suggest'));
                input.value = '';
                input.focus();
                showGalaxyTagSuggestions('');
            });
        }
        // Click outside container hides suggestions.
        document.addEventListener('click', (ev) => {
            if (!container.contains(ev.target)) hideGalaxyTagSuggestions();
        });
    }

    async function loadTourConfigIntoModal(constellationId) {
        const enabled = document.getElementById('modal-tour-enabled');
        const chipsEnabled = document.getElementById('modal-keyword-chips-enabled');
        const idleSpotlightEnabled = document.getElementById('modal-idle-spotlight-enabled');
        const idleSpotlightSeconds = document.getElementById('modal-idle-spotlight-idle-seconds');
        const idleSeconds = document.getElementById('modal-tour-idle-seconds');
        const randomCount = document.getElementById('modal-tour-random-count');
        const defaultDwell = document.getElementById('modal-tour-default-dwell');
        const loop = document.getElementById('modal-tour-loop');
        const keywordsBox = document.getElementById('modal-tour-keywords');

        enabled.checked = false;
        if (chipsEnabled) chipsEnabled.checked = false;
        const relatedEnabled = document.getElementById('modal-related-nodes-enabled');
        if (relatedEnabled) relatedEnabled.checked = false;
        const show2dView = document.getElementById('modal-show-2d-view');
        if (show2dView) show2dView.checked = false;
        if (idleSpotlightEnabled) idleSpotlightEnabled.checked = false;
        if (idleSpotlightSeconds) idleSpotlightSeconds.value = 30;
        idleSeconds.value = 30;
        randomCount.value = 10;
        defaultDwell.value = 8;
        loop.checked = true;
        keywordsBox.innerHTML = '<span class="text-xs text-gray-400">' + escape(GXM.statusLoadingKeywords || 'GXM.statusLoadingKeywords') + '</span>';
        document.querySelectorAll('input[name="tour_start_mode"]').forEach(r => r.checked = (r.value === 'manual'));
        document.querySelectorAll('input[name="tour_node_selection"]').forEach(r => r.checked = (r.value === 'all'));
        document.querySelectorAll('input[name="idle_spotlight_selection"]').forEach(r => r.checked = (r.value === 'all'));
        updateTourFieldVisibility();

        try {
            const r = await fetch(`${getApiUrl()}?action=tour_config&id=${constellationId}`, {
                headers: { 'X-API-Key': getApiKey(), 'X-CSRF-Token': (window.TELARIS_CSRF_TOKEN || '') }
            });
            if (!r.ok) throw new Error('GXM_LOAD_TOUR_CONFIG_FAILED');
            const cfg = await r.json();

            enabled.checked = !!cfg.tour_enabled;
            if (chipsEnabled) chipsEnabled.checked = !!cfg.keyword_chips_enabled;
            if (relatedEnabled) relatedEnabled.checked = !!cfg.related_nodes_enabled;
            if (show2dView) show2dView.checked = !!cfg.show_2d_view;
            if (idleSpotlightEnabled) idleSpotlightEnabled.checked = !!cfg.idle_spotlight_enabled;
            if (idleSpotlightSeconds) idleSpotlightSeconds.value = cfg.idle_spotlight_idle_seconds ?? 30;
            idleSeconds.value = cfg.tour_idle_seconds ?? 30;
            randomCount.value = cfg.tour_random_count ?? 10;
            defaultDwell.value = cfg.tour_default_dwell ?? 8;
            loop.checked = !!cfg.tour_loop;
            document.querySelectorAll('input[name="tour_start_mode"]').forEach(r => r.checked = (r.value === cfg.tour_start_mode));
            document.querySelectorAll('input[name="tour_node_selection"]').forEach(r => r.checked = (r.value === cfg.tour_node_selection));
            const idleSel = cfg.idle_spotlight_selection || 'all';
            document.querySelectorAll('input[name="idle_spotlight_selection"]').forEach(r => r.checked = (r.value === idleSel));

            const selectedKwIds = new Set((cfg.tour_keyword_ids || []).map(Number));
            if (!cfg.available_keywords || cfg.available_keywords.length === 0) {
                keywordsBox.innerHTML = '<span class="text-xs text-gray-500">' + escape(GXM.noKeywordsYet || 'GXM.noKeywordsYet') + '</span>';
            } else {
                // Render each keyword as a pastel pill (background + border +
                // text from the canonical palette, hashed on the keyword) so
                // the same chip aesthetic carries from the visitor strip and
                // the 2D grid into the editorial UI.
                keywordsBox.innerHTML = '<div class="flex flex-wrap gap-1.5">' + cfg.available_keywords.map(kw => {
                    const checked = selectedKwIds.has(kw.id) ? 'checked' : '';
                    const pastel = kwPastel(kw.keyword);
                    const bg = kwPastelBg(kw.keyword);
                    const border = kwPastelBorder(kw.keyword);
                    const pillStyle = `background:${bg};color:${pastel};border:1px solid ${border};padding:2px 10px;border-radius:9999px;font-size:0.75rem;font-weight:500;line-height:1.4;cursor:pointer;display:inline-flex;align-items:center;gap:6px;`;
                    return `<label style="${pillStyle}">
                        <input type="checkbox" name="tour_keyword_ids[]" value="${kw.id}" ${checked} class="checkbox checkbox-neutral" style="width:0.9rem;height:0.9rem;">
                        <span>#${escape(kw.keyword)}</span>
                    </label>`;
                }).join('') + '</div>';
            }

            document.getElementById('modal-tour-immediate-warning').dataset.hasAudio = cfg.has_audio_nodes ? '1' : '0';
        } catch (e) {
            keywordsBox.innerHTML = '<span class="text-xs text-red-600">' + escape(GXM.loadFailedKeywords || 'GXM.loadFailedKeywords') + '</span>';
            document.getElementById('modal-tour-immediate-warning').dataset.hasAudio = '0';
        }
        updateTourFieldVisibility();
    }

    function updateTourFieldVisibility() {
        const enabledEl = document.getElementById('modal-tour-enabled');
        if (enabledEl) {
            const enabled = enabledEl.checked;
            document.getElementById('modal-tour-section').classList.toggle('hidden', !enabled);
            if (enabled) {
                const startMode = document.querySelector('input[name="tour_start_mode"]:checked')?.value || 'manual';
                document.getElementById('modal-tour-idle-row').classList.toggle('hidden', startMode !== 'idle');

                const selection = document.querySelector('input[name="tour_node_selection"]:checked')?.value || 'all';
                document.getElementById('modal-tour-random-row').classList.toggle('hidden', selection !== 'random_n');
                document.getElementById('modal-tour-tagged-row').classList.toggle('hidden', selection !== 'tagged');

                const audioWarn = document.getElementById('modal-tour-immediate-warning');
                const hasAudio = audioWarn.dataset.hasAudio === '1';
                audioWarn.classList.toggle('hidden', !(hasAudio && startMode === 'immediate'));
            }
        }

        const idleSpotEnabled = document.getElementById('modal-idle-spotlight-enabled');
        if (idleSpotEnabled) {
            const idleSection = document.getElementById('modal-idle-spotlight-section');
            if (idleSection) idleSection.classList.toggle('hidden', !idleSpotEnabled.checked);
        }
    }

    async function applyBulkFlag(flag, value, button) {
        const id = parseInt(document.getElementById('modal-constellation-id')?.value, 10);
        const feedback = document.getElementById('modal-bulk-feedback');
        if (!id || isNaN(id)) return;
        const label = value
            ? (GXM.labelUseImagesAsIcons || 'GXM.labelUseImagesAsIcons')
            : (GXM.labelRevertToThemeIcons || 'GXM.labelRevertToThemeIcons');
        if (!window.confirm(tFmt(GXM.confirmApplyToAll || 'GXM.confirmApplyToAll', label))) return;
        if (button) { button.disabled = true; }
        if (feedback) { feedback.textContent = GXM.statusWorking || 'GXM.statusWorking'; feedback.style.color = ''; }
        try {
            const r = await fetch(`${getApiUrl().replace(/constellations\.php$/, 'nodes.php')}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-API-Key': getApiKey(), 'X-CSRF-Token': (window.TELARIS_CSRF_TOKEN || '') },
                body: JSON.stringify({ action: 'bulk_use_image_as_node', constellation_id: id, value: value }),
            });
            const json = await r.json();
            if (!r.ok) throw new Error(json?.error || (GXM.errUpdateFailedFallback || 'GXM.errUpdateFailedFallback'));
            if (feedback) {
                const tpl = json.updated === 1
                    ? (GXM.statusUpdatedOne || 'GXM.statusUpdatedOne')
                    : (GXM.statusUpdatedMany || 'GXM.statusUpdatedMany');
                feedback.textContent = tFmt(tpl, json.updated);
            }
        } catch (e) {
            if (feedback) { feedback.textContent = tFmt(GXM.labelFailedPrefix || 'GXM.labelFailedPrefix', e.message); feedback.style.color = '#b91c1c'; }
        } finally {
            if (button) { button.disabled = false; }
        }
    }

    function bind() {
        const enabled = document.getElementById('modal-tour-enabled');
        if (!enabled) return; // partial not on this page
        bindGalaxyTagInput();
        enabled.addEventListener('change', updateTourFieldVisibility);
        document.querySelectorAll('.tour-start-mode').forEach(r => r.addEventListener('change', updateTourFieldVisibility));
        document.querySelectorAll('.tour-node-selection').forEach(r => r.addEventListener('change', updateTourFieldVisibility));
        const idleSpotEnabled = document.getElementById('modal-idle-spotlight-enabled');
        if (idleSpotEnabled) idleSpotEnabled.addEventListener('change', updateTourFieldVisibility);
        document.querySelectorAll('button[data-bulk-flag]').forEach(btn => {
            btn.addEventListener('click', () => {
                applyBulkFlag(btn.dataset.bulkFlag, btn.dataset.bulkValue === '1', btn);
            });
        });

        const previewBtn = document.getElementById('modal-tour-preview');
        if (previewBtn) {
            previewBtn.addEventListener('click', () => {
                const id = parseInt(document.getElementById('modal-constellation-id')?.value, 10);
                if (!id || isNaN(id)) return;
                // Look up the slug from the page-level galaxy list if available; else use id.
                let path = '../index.php?constellation_id=' + id;
                if (Array.isArray(window.TELARIS_GALAXIES)) {
                    const g = window.TELARIS_GALAXIES.find(x => x.id === id);
                    if (g && g.slug) path = '../' + encodeURIComponent(g.slug);
                }
                // ?tour=preview tells the visitor to ignore start_mode and start the tour now.
                const sep = path.includes('?') ? '&' : '?';
                window.open(path + sep + 'tour=preview', '_blank');
            });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }

    // ---------------------------------------------------------------------
    // Autosave controller for the Edit Galaxy modal.
    // ---------------------------------------------------------------------
    // Replaces the explicit "Update Galaxy" button. Text edits debounce (~1s),
    // toggles/selects/tour-keyword checkboxes/tag chips save immediately, and the
    // slug field (admins only) saves on blur rather than per-keystroke to avoid
    // churning bookmarked URLs. Each save POSTs the whole form (with ajax=1) back to
    // the page handler, which calls handle_galaxy_update_post() and returns JSON.
    // On close, a pending edit is flushed and the page reloads once if anything was
    // saved (galaxy name/theme affect page chrome, matching the old POST-reload).
    const gemAutosave = (function () {
        const DEBOUNCE_MS = 1000;
        let dirty = false, inflight = false, queued = false, timer = null;
        let suspended = true, installed = false, touched = false, reloadOnDrain = false;

        function setStatus(state, override) {
            const root = document.getElementById('gem-autosave-status');
            if (!root) return;
            const textEl = root.querySelector('[data-autosave-text]');
            const spinEl = root.querySelector('[data-autosave-spinner]');
            const cfg = ({
                idle:   { attr: 'data-saved',  cls: 'text-gray-400',  spin: false },
                saving: { attr: 'data-saving', cls: 'text-gray-500',  spin: true  },
                saved:  { attr: 'data-saved',  cls: 'text-green-600', spin: false },
                failed: { attr: 'data-failed', cls: 'text-red-600',   spin: false },
            })[state] || { attr: 'data-saved', cls: 'text-gray-400', spin: false };
            if (textEl) {
                textEl.textContent = override || root.getAttribute(cfg.attr) || '';
                textEl.className = 'text-xs font-medium ' + cfg.cls;
            }
            if (spinEl) spinEl.classList.toggle('hidden', !cfg.spin);
        }

        function mainForm() {
            const modal = document.getElementById('constellation_modal');
            return modal ? modal.querySelector('form[action]') : null;
        }
        function saveUrl() { return window.GALAXY_EDIT_SAVE_URL || window.location.pathname; }

        function drained() {
            if (reloadOnDrain && !queued && !inflight) {
                reloadOnDrain = false;
                if (touched) window.location.reload();
            }
        }

        async function send() {
            if (suspended) return;
            const form = mainForm();
            const nameEl = document.getElementById('modal-constellation-name');
            if (!form || !nameEl) return;
            if (nameEl.value.trim() === '') { setStatus('failed'); drained(); return; }
            if (timer) { clearTimeout(timer); timer = null; }
            inflight = true;
            touched = true;
            dirty = false;
            setStatus('saving');
            const fd = new FormData(form);
            fd.set('ajax', '1');
            try {
                const r = await fetch(saveUrl(), {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                let data = {};
                try { data = await r.json(); } catch (e) { /* non-JSON */ }
                inflight = false;
                if (r.ok && data.ok) {
                    setStatus('saved');
                } else {
                    dirty = true; // allow retry on the next edit
                    setStatus('failed', data && data.error ? data.error : undefined);
                }
            } catch (e) {
                inflight = false;
                dirty = true;
                setStatus('failed');
            }
            if (queued) { queued = false; flush(); return; }
            drained();
        }

        function flush() {
            if (suspended || !dirty) return;
            if (inflight) { queued = true; return; }
            if (timer) { clearTimeout(timer); timer = null; }
            send();
        }
        function scheduleSave() {
            if (suspended) return;
            dirty = true;
            setStatus('saving');
            if (timer) clearTimeout(timer);
            timer = setTimeout(flush, DEBOUNCE_MS);
        }
        function saveNow() { if (suspended) return; dirty = true; flush(); }
        function flushNow() { if (suspended || !dirty) return; flush(); }

        function install() {
            if (installed) return;
            const form = mainForm();
            if (!form) return;
            installed = true;
            form.addEventListener('submit', (e) => {
                // Create mode submits natively (POST action=create_constellation -> reload).
                if (galaxyMode === 'create') return;
                e.preventDefault(); saveNow(); // edit mode: Enter key just flushes/autosaves
            });
            form.addEventListener('input', (e) => {
                const t = e.target;
                if (!t || t.disabled) return;
                if (t.id === 'modal-galaxy-tags-input') return;       // tag entry; commit hooks saveNow
                if (t.id === 'modal-constellation-slug') { dirty = true; return; } // slug saves on blur only
                const type = (t.type || '').toLowerCase();
                if (type === 'checkbox' || type === 'radio' || t.tagName === 'SELECT') return;
                scheduleSave();
            });
            form.addEventListener('change', (e) => {
                const t = e.target;
                if (!t || t.disabled) return;
                if (t.id === 'modal-galaxy-tags-input') return;
                const type = (t.type || '').toLowerCase();
                if (type === 'checkbox' || type === 'radio' || t.tagName === 'SELECT' || t.id === 'modal-constellation-slug') {
                    saveNow();
                } else {
                    flushNow(); // text-field blur: send only if an edit is pending
                }
            });
            const modal = document.getElementById('constellation_modal');
            if (modal) modal.addEventListener('close', () => {
                if (dirty && !inflight) { reloadOnDrain = true; send(); }
                else if (inflight) { reloadOnDrain = true; }
                else if (touched) { window.location.reload(); }
            });
        }

        return {
            beginPopulate() { suspended = true; if (timer) { clearTimeout(timer); timer = null; } dirty = false; queued = false; touched = false; reloadOnDrain = false; install(); },
            endPopulate() { suspended = false; inflight = false; setStatus('idle'); },
            scheduleSave, saveNow,
        };
    })();

    // Expose so inline onclick handlers and the row builders can call them.
    window.editConstellation = editConstellation;
    window.openCreateConstellation = openCreateConstellation;
    window.loadTourConfigIntoModal = loadTourConfigIntoModal;
    window.updateTourFieldVisibility = updateTourFieldVisibility;
})();
