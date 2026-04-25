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
        await loadTourConfigIntoModal(c.id);
        document.getElementById('constellation_modal').showModal();
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
