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
    'use strict';

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

    async function editConstellation(c) {
        document.getElementById('modal-constellation-id').value = c.id;
        document.getElementById('modal-constellation-name').value = c.name || '';
        const slugEl = document.getElementById('modal-constellation-slug');
        if (slugEl) slugEl.value = c.slug || '';
        document.getElementById('modal-constellation-tagline').value = c.tagline || '';
        document.getElementById('modal-constellation-theme').value = c.theme || 'cosmic';
        const badge = document.getElementById('modal-constellation-id-badge');
        if (badge) badge.textContent = '#' + c.id;
        const feedback = document.getElementById('modal-bulk-feedback');
        if (feedback) { feedback.textContent = ''; feedback.style.color = ''; }
        await Promise.all([
            loadTourConfigIntoModal(c.id),
            loadGalaxyTagsIntoModal(c.id),
        ]);
        document.getElementById('constellation_modal').showModal();
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

        const buckets = [
            { key: 'current', label: 'Already on this galaxy', items: galaxyTagState.suggestions.current },
            { key: 'siblings', label: 'In sibling galaxies (same [XX] prefix)', items: galaxyTagState.suggestions.siblings },
            { key: 'global', label: 'Other tags', items: galaxyTagState.suggestions.global },
        ];

        const rows = [];
        buckets.forEach(b => {
            const matches = b.items.filter(item => {
                if (taken.has(item.label.toLowerCase())) return false;
                if (!f) return true;
                return item.label.toLowerCase().includes(f) || (item.slug || '').toLowerCase().includes(f);
            });
            if (matches.length === 0) return;
            rows.push(`<div class="px-3 py-1 text-[10px] uppercase tracking-wider text-gray-500 bg-gray-50 border-b border-gray-100">${escape(b.label)}</div>`);
            matches.slice(0, 12).forEach(m => {
                const count = (typeof m.count === 'number' && m.count > 0) ? `<span class="text-xs text-gray-400 ml-2">${m.count}</span>` : '';
                rows.push(`<div class="px-3 py-1.5 hover:bg-blue-50 cursor-pointer flex items-center justify-between" data-galaxy-tag-suggest="${escape(m.label)}"><span class="text-gray-800">${escape(m.label)}</span>${count}</div>`);
            });
        });

        if (rows.length === 0) {
            box.classList.add('hidden');
            box.innerHTML = '';
            return;
        }
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
            const r = await fetch(`${tagsApiUrl()}?galaxy_id=${galaxyId}`, { headers: { 'X-API-Key': getApiKey() } });
            if (!r.ok) throw new Error('Failed to load tags');
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
        if (idleSpotlightEnabled) idleSpotlightEnabled.checked = false;
        if (idleSpotlightSeconds) idleSpotlightSeconds.value = 30;
        idleSeconds.value = 30;
        randomCount.value = 10;
        defaultDwell.value = 8;
        loop.checked = true;
        keywordsBox.innerHTML = '<span class="text-xs text-gray-400">Loading…</span>';
        document.querySelectorAll('input[name="tour_start_mode"]').forEach(r => r.checked = (r.value === 'manual'));
        document.querySelectorAll('input[name="tour_node_selection"]').forEach(r => r.checked = (r.value === 'all'));
        document.querySelectorAll('input[name="idle_spotlight_selection"]').forEach(r => r.checked = (r.value === 'all'));
        updateTourFieldVisibility();

        try {
            const r = await fetch(`${getApiUrl()}?action=tour_config&id=${constellationId}`, {
                headers: { 'X-API-Key': getApiKey() }
            });
            if (!r.ok) throw new Error('Failed to load tour config');
            const cfg = await r.json();

            enabled.checked = !!cfg.tour_enabled;
            if (chipsEnabled) chipsEnabled.checked = !!cfg.keyword_chips_enabled;
            if (relatedEnabled) relatedEnabled.checked = !!cfg.related_nodes_enabled;
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
                keywordsBox.innerHTML = '<span class="text-xs text-gray-500">No keywords yet for this galaxy.</span>';
            } else {
                keywordsBox.innerHTML = cfg.available_keywords.map(kw => {
                    const checked = selectedKwIds.has(kw.id) ? 'checked' : '';
                    return `<label class="flex items-center gap-2 py-0.5 cursor-pointer">
                        <input type="checkbox" name="tour_keyword_ids[]" value="${kw.id}" ${checked} class="checkbox checkbox-neutral checkbox-xs">
                        <span class="text-gray-800">${escape(kw.keyword)}</span>
                    </label>`;
                }).join('');
            }

            document.getElementById('modal-tour-immediate-warning').dataset.hasAudio = cfg.has_audio_nodes ? '1' : '0';
        } catch (e) {
            keywordsBox.innerHTML = '<span class="text-xs text-red-600">Failed to load.</span>';
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
        const label = value ? 'use images as icons' : 'revert all to theme icons';
        if (!window.confirm(`Apply "${label}" to every wormhole in this galaxy?`)) return;
        if (button) { button.disabled = true; }
        if (feedback) { feedback.textContent = 'Working…'; feedback.style.color = ''; }
        try {
            const r = await fetch(`${getApiUrl().replace(/constellations\.php$/, 'nodes.php')}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-API-Key': getApiKey() },
                body: JSON.stringify({ action: 'bulk_use_image_as_node', constellation_id: id, value: value }),
            });
            const json = await r.json();
            if (!r.ok) throw new Error(json?.error || 'Update failed');
            if (feedback) feedback.textContent = `Updated ${json.updated} wormhole${json.updated === 1 ? '' : 's'}. Reload the visitor view to see the change.`;
        } catch (e) {
            if (feedback) { feedback.textContent = 'Failed: ' + e.message; feedback.style.color = '#b91c1c'; }
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

    // Expose so inline onclick handlers and the row builders can call them.
    window.editConstellation = editConstellation;
    window.loadTourConfigIntoModal = loadTourConfigIntoModal;
    window.updateTourFieldVisibility = updateTourFieldVisibility;
})();
