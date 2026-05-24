<?php
declare(strict_types=1);

/**
 * Mocambos bridge: admin-side UI.
 *
 * Exports three render functions that the generic admin/index.php invokes
 * via bridges_admin_render() at the matching slots. None of this code
 * appears outside this file: the button, the modal, the consts, and the
 * JS that drives them all live here. To add a different bridge (e.g.
 * Banana, Wikipedia, etc.), ship inc/bridges/{name}-admin.php with the
 * analogous three functions; admin/index.php does not need to change.
 *
 *   mocambos_admin_render_button()  -> galaxy-list-header button slot
 *   mocambos_admin_render_modal()   -> page-body modal slot
 *   mocambos_admin_render_js()      -> script slot (defines functions +
 *                                       registers in window.BRIDGES_REFRESH_UI)
 *
 * The JS depends on three globals exposed by admin/index.php:
 *   - API_KEY: the API key for X-API-Key requests.
 *   - showMessage(text, type): the toast helper.
 *   - loadConstellations(): reload the galaxy list after a successful refresh.
 *
 * All user-visible strings flow through t() (PHP) or
 * window.MOCAMBOS_STRINGS (JS), populated from PROJECT_INFO_KEYS with
 * the `mocambos_*` prefix. When a translation row is missing for the
 * current locale, t() returns the locale-invariant key per the
 * decolonial-identifier rule (see inc/db.php).
 */

function mocambos_admin_render_button(): void {
?>
                                <button type="button" onclick="openMocambosImportModal()" class="text-purple-600 hover:text-purple-800 font-medium text-base"><?= t_attr('mocambos_btn_import_from', 'Import from Mocambos') ?></button>
<?php
}

function mocambos_admin_render_modal(): void {
?>
    <!-- Mocambos Import Modal -->
    <dialog id="mocambos_import_modal" class="modal">
        <div class="modal-box bg-white max-w-lg !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= t_attr('mocambos_modal_heading', 'Import from Mocambos') ?></h3>
            </div>
            <!-- Step 1: API URL -->
            <div id="mocambos-url-step" class="mt-4">
                <label for="mocambos-api-url" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('mocambos_label_api_url', 'Mocambos API URL') ?></label>
                <input type="url" id="mocambos-api-url" placeholder="https://timbuktu.mocambos.net/api/v2" value="" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500 mb-1">
                <span class="text-xs text-gray-500 block mb-4"><?= t_attr('mocambos_help_api_url', 'The base API URL of the Mocambos instance (e.g. https://hostname/api/v2). You can also paste the docs URL; /docs will be stripped automatically.') ?></span>
                <button type="button" id="mocambos-fetch-btn" onclick="fetchMocambosGalaxias()" class="btn bg-purple-600 hover:bg-purple-700 text-white btn-sm"><?= t_attr('mocambos_btn_connect', 'Connect') ?></button>
            </div>
            <!-- Step 2: Loading -->
            <div id="mocambos-loading" class="hidden text-center py-8">
                <span class="loading loading-spinner loading-lg text-purple-600"></span>
                <p class="text-gray-600 mt-2"><?= t_attr('mocambos_text_loading', 'Fetching available galaxias...') ?></p>
            </div>
            <div id="mocambos-error" class="hidden text-center py-8">
                <p class="text-red-600 font-medium" id="mocambos-error-message"></p>
                <button type="button" onclick="showMocambosUrlStep()" class="btn btn-sm btn-outline mt-3"><?= t_attr('mocambos_btn_back', 'Back') ?></button>
            </div>
            <!-- Step 3: Galaxia selection + import -->
            <div id="mocambos-list" class="hidden">
                <p class="text-sm text-gray-600 mb-1"><?= t_attr('mocambos_text_connected_to', 'Connected to:') ?> <strong id="mocambos-connected-url" class="font-mono text-xs"></strong></p>
                <p class="text-sm text-gray-600 mb-3"><?= t_attr('mocambos_text_select_intro', 'Select galaxias to import. Each will become a new galaxy. Already-imported ones will be refreshed.') ?></p>
                <div id="mocambos-galaxias" class="space-y-2 max-h-64 overflow-y-auto border border-gray-200 rounded p-3 mb-4"></div>
                <div id="mocambos-import-progress" class="hidden">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="loading loading-spinner loading-sm text-purple-600"></span>
                        <span class="text-sm font-medium text-gray-700" id="mocambos-progress-status"><?= t_attr('mocambos_text_starting_import', 'Starting import...') ?></span>
                    </div>
                    <div id="mocambos-log" class="bg-gray-900 text-gray-200 rounded p-3 font-mono text-xs h-64 overflow-y-auto space-y-0.5"></div>
                </div>
                <div id="mocambos-import-result" class="hidden mb-4"></div>
            </div>
            <!-- Refresh confirmation step -->
            <div id="refresh-confirm-step" class="hidden">
                <p class="text-gray-700 mb-2"><?= t_attr('mocambos_text_refresh_intro', 'This will sync wormholes with the remote Mocambos source (incremental update).') ?></p>
                <p class="text-gray-700 mb-4" id="refresh-confirm-prompt"></p>
                <input type="text" id="refresh-confirm-input" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-purple-500 mb-4" placeholder="<?= t_attr('mocambos_placeholder_refresh_confirm', 'Type galaxy name to confirm') ?>" autocomplete="off">
                <div class="flex justify-end gap-2">
                    <button type="button" id="refresh-confirm-btn" class="btn bg-purple-600 hover:bg-purple-700 text-white btn-sm" disabled><?= t_attr('mocambos_btn_refresh', 'Refresh') ?></button>
                    <button type="button" class="btn btn-sm" onclick="document.getElementById('mocambos_import_modal').close()"><?= t_attr('mocambos_btn_cancel', 'Cancel') ?></button>
                </div>
            </div>
            <div class="modal-action">
                <button type="button" id="mocambos-import-btn" class="btn bg-purple-600 hover:bg-purple-700 text-white hidden" onclick="doMocambosImport()"><?= t_attr('mocambos_btn_import_selected', 'Import Selected') ?></button>
                <button type="button" class="btn" onclick="document.getElementById('mocambos_import_modal').close()"><?= t_attr('mocambos_btn_close', 'Close') ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button><?= t_attr('mocambos_btn_modal_backdrop_close', 'close') ?></button></form>
    </dialog>
<?php
}

function mocambos_admin_render_js(): void {
    // JS strings bundle. Each lookup goes through t(); the JS reads from
    // window.MOCAMBOS_STRINGS to keep the JS itself locale-agnostic.
    $jsStrings = [
        'validation_report_title' => t('mocambos_js_validation_report_title', 'Mocambos API Validation Report'),
        'validation_url_prefix' => t('mocambos_js_validation_url_prefix', 'URL:'),
        'validation_date_prefix' => t('mocambos_js_validation_date_prefix', 'Date:'),
        'validating_api' => t('mocambos_js_validating_api', 'Validating API...'),
        'enter_url' => t('mocambos_js_enter_url', 'Please enter a Mocambos API URL.'),
        'validation_failed_intro' => t('mocambos_js_validation_failed_intro', 'API validation failed. The following issues were found:'),
        'copied' => t('mocambos_js_copied', 'Copied!'),
        'copy_report' => t('mocambos_js_copy_report', 'Copy report to clipboard'),
        'could_not_validate' => t('mocambos_js_could_not_validate', 'Could not validate: %s'),
        'network_error' => t('mocambos_js_network_error', 'Network error'),
        'fetch_failed' => t('mocambos_js_fetch_failed', 'Failed to fetch galaxias'),
        'no_galaxias' => t('mocambos_js_no_galaxias', 'No galaxias found at this URL.'),
        'badge_imported' => t('mocambos_js_badge_imported', 'Imported'),
        'connect_failed' => t('mocambos_js_connect_failed', 'Failed to connect to Mocambos API'),
        'select_at_least_one' => t('mocambos_js_select_at_least_one', 'Please select at least one galaxia to import.'),
        'confirm_refresh_intro' => t('mocambos_js_confirm_refresh_intro', 'The following galaxies will be refreshed, replacing all current content including any edits:'),
        'confirm_refresh_continue' => t('mocambos_js_confirm_refresh_continue', 'Continue?'),
        'import_failed_generic' => t('mocambos_js_import_failed_generic', 'Import failed'),
        'import_complete_status' => t('mocambos_js_import_complete_status', 'Import complete'),
        'status_label_new' => t('mocambos_js_status_label_new', 'New'),
        'status_label_refreshed' => t('mocambos_js_status_label_refreshed', 'Refreshed'),
        'items_count' => t('mocambos_js_items_count', '%d of %d items'),
        'completed_success' => t('mocambos_js_completed_success', 'Import completed successfully.'),
        'completed_errors' => t('mocambos_js_completed_errors', 'Import completed with some errors.'),
        'refresh_complete_log' => t('mocambos_js_refresh_complete_log', 'Refresh complete.'),
        'refresh_complete_status' => t('mocambos_js_refresh_complete_status', 'Refresh complete'),
        'refresh_failed_status' => t('mocambos_js_refresh_failed_status', 'Refresh failed'),
        'missing_source' => t('mocambos_js_missing_source', 'Missing import source info for this galaxy.'),
        'refreshing' => t('mocambos_js_refreshing', 'Refreshing "%s"...'),
        'error_prefix' => t('mocambos_js_error_prefix', 'Error: %s'),
        'unknown_error' => t('mocambos_js_unknown_error', 'Unknown error'),
        'refresh_confirm_instruction' => t('mocambos_text_refresh_confirm_instruction', 'To confirm, type the galaxy name <strong id="refresh-confirm-name" class="text-gray-900">%s</strong> below:'),
        'text_loading' => t('mocambos_text_loading', 'Fetching available galaxias...'),
    ];
?>
    <script>
    // Mocambos bridge admin UI. Activated only when 'mocambos' is in TELARIS_BRIDGES.
    window.MOCAMBOS_STRINGS = <?= json_encode($jsStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    (function() {
        const MOCAMBOS_API = '../api/bridge.php?name=mocambos';
        const STR = window.MOCAMBOS_STRINGS;
        let mocambosApiBase = '';

        // Minimal sprintf for the %s / %d templates used by the strings bundle.
        function fmt(template, ...args) {
            let i = 0;
            return template.replace(/%[sd]/g, () => String(args[i++] ?? ''));
        }

        function openMocambosImportModal() {
            showMocambosUrlStep();
            document.getElementById('mocambos_import_modal').showModal();
        }

        function showMocambosUrlStep() {
            document.getElementById('mocambos-url-step').classList.remove('hidden');
            document.getElementById('mocambos-loading').classList.add('hidden');
            document.getElementById('mocambos-error').classList.add('hidden');
            document.getElementById('mocambos-list').classList.add('hidden');
            document.getElementById('mocambos-import-btn').classList.add('hidden');
            document.getElementById('mocambos-import-progress').classList.add('hidden');
            document.getElementById('mocambos-import-result').classList.add('hidden');
            document.getElementById('refresh-confirm-step').classList.add('hidden');
        }

        function buildValidationReport(apiBase, checks) {
            const statusIcon = { ok: '✅', warn: '⚠️', fail: '❌' };
            let lines = [];
            lines.push(STR.validation_report_title);
            lines.push(STR.validation_url_prefix + ' ' + apiBase);
            lines.push(STR.validation_date_prefix + ' ' + new Date().toISOString());
            lines.push('---');
            checks.forEach(c => {
                lines.push(`${statusIcon[c.status] || '?'} ${c.endpoint} (HTTP ${c.http_status || '?'})`);
                lines.push(`   ${c.detail}`);
            });
            return lines.join('\n');
        }

        async function fetchMocambosGalaxias() {
            const urlInput = document.getElementById('mocambos-api-url');
            const apiBase = urlInput.value.trim().replace(/\/+$/, '').replace(/\/docs#?.*$/i, '');
            if (!apiBase) {
                showMessage(STR.enter_url, 'error');
                return;
            }
            mocambosApiBase = apiBase;

            const urlStep = document.getElementById('mocambos-url-step');
            const loading = document.getElementById('mocambos-loading');
            const errorDiv = document.getElementById('mocambos-error');
            const errorMsg = document.getElementById('mocambos-error-message');
            const listDiv = document.getElementById('mocambos-list');
            const galaxiasDiv = document.getElementById('mocambos-galaxias');
            const importBtn = document.getElementById('mocambos-import-btn');
            const resultDiv = document.getElementById('mocambos-import-result');
            const progressDiv = document.getElementById('mocambos-import-progress');

            urlStep.classList.add('hidden');
            loading.classList.remove('hidden');
            loading.querySelector('p').textContent = STR.validating_api;
            errorDiv.classList.add('hidden');
            listDiv.classList.add('hidden');
            importBtn.classList.add('hidden');
            progressDiv.classList.add('hidden');
            resultDiv.classList.add('hidden');
            resultDiv.innerHTML = '';
            galaxiasDiv.innerHTML = '';

            // Step 1: Validate
            try {
                const valResp = await fetch(`${MOCAMBOS_API}&action=validate&api_base=${encodeURIComponent(mocambosApiBase)}`, {
                    headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                });
                const valData = await valResp.json();

                if (!valResp.ok || !valData.valid) {
                    loading.classList.add('hidden');
                    const checks = valData.checks || [];
                    const report = buildValidationReport(mocambosApiBase, checks);

                    let html = '<div class="text-left">';
                    html += `<p class="font-medium text-red-700 mb-2">${STR.validation_failed_intro}</p>`;
                    html += '<div class="space-y-2 mb-3">';
                    checks.forEach(c => {
                        const color = c.status === 'ok' ? 'green' : c.status === 'warn' ? 'yellow' : 'red';
                        const icon = c.status === 'ok' ? '✓' : c.status === 'warn' ? '⚠' : '✗';
                        html += `<div class="p-2 rounded bg-${color}-50 border border-${color}-200">`;
                        html += `<p class="text-sm font-mono"><span class="font-bold">${icon}</span> <strong>${c.endpoint}</strong> <span class="text-gray-500">(HTTP ${c.http_status || '?'})</span></p>`;
                        html += `<p class="text-xs text-gray-700 mt-0.5">${c.detail}</p>`;
                        html += '</div>';
                    });
                    html += '</div>';
                    html += `<button type="button" onclick="navigator.clipboard.writeText(this.dataset.report).then(() => { this.textContent = STR.copied || 'Copied'; setTimeout(() => { this.textContent = STR.copy_report; }, 1500); })" data-report="${report.replace(/"/g, '&quot;')}" class="btn btn-sm btn-outline text-xs">${STR.copy_report}</button>`;
                    html += '</div>';

                    if (!valData.valid && valData.error) {
                        html = `<p class="text-red-700 font-medium">${valData.error}</p>`;
                    }

                    errorMsg.innerHTML = html;
                    errorDiv.classList.remove('hidden');
                    return;
                }
            } catch (e) {
                loading.classList.add('hidden');
                errorMsg.textContent = fmt(STR.could_not_validate, e.message || STR.network_error);
                errorDiv.classList.remove('hidden');
                return;
            }

            // Step 2: Fetch galaxias
            loading.querySelector('p').textContent = STR.text_loading;
            try {
                const resp = await fetch(`${MOCAMBOS_API}&action=galaxias&api_base=${encodeURIComponent(mocambosApiBase)}`, {
                    headers: { 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN }
                });
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.error || STR.fetch_failed);
                }
                const galaxias = await resp.json();
                loading.classList.add('hidden');

                if (!Array.isArray(galaxias) || galaxias.length === 0) {
                    errorMsg.textContent = STR.no_galaxias;
                    errorDiv.classList.remove('hidden');
                    return;
                }

                document.getElementById('mocambos-connected-url').textContent = mocambosApiBase;

                galaxias.forEach((g, i) => {
                    const div = document.createElement('label');
                    div.className = 'flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer';
                    div.innerHTML = `
                        <input type="checkbox" class="checkbox checkbox-sm checkbox-primary mocambos-galaxia-cb"
                               data-slug="${g.slug}" data-smid="${g.smid}" data-mucua="${g.mucua_slug}" data-name="${g.name}">
                        <span class="flex-1 text-sm font-medium text-gray-800">${g.name}</span>
                        ${g.imported ? `<span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded">${STR.badge_imported}</span>` : ''}
                    `;
                    galaxiasDiv.appendChild(div);
                });

                listDiv.classList.remove('hidden');
                importBtn.classList.remove('hidden');
            } catch (e) {
                loading.classList.add('hidden');
                errorMsg.textContent = e.message || STR.connect_failed;
                errorDiv.classList.remove('hidden');
            }
        }

        async function doMocambosImport() {
            const checkboxes = document.querySelectorAll('.mocambos-galaxia-cb:checked');
            if (checkboxes.length === 0) {
                showMessage(STR.select_at_least_one, 'error');
                return;
            }

            const selected = [];
            const reimportNames = [];
            checkboxes.forEach(cb => {
                selected.push({
                    galaxia_slug: cb.dataset.slug,
                    galaxia_smid: cb.dataset.smid,
                    mucua_slug: cb.dataset.mucua
                });
                const badge = cb.closest('label').querySelector('.bg-purple-100');
                if (badge) reimportNames.push(cb.dataset.name);
            });

            if (reimportNames.length > 0) {
                const confirmed = confirm(
                    STR.confirm_refresh_intro + '\n\n' +
                    reimportNames.join('\n') +
                    '\n\n' + STR.confirm_refresh_continue
                );
                if (!confirmed) return;
            }

            const importBtn = document.getElementById('mocambos-import-btn');
            const progressDiv = document.getElementById('mocambos-import-progress');
            const resultDiv = document.getElementById('mocambos-import-result');
            const logDiv = document.getElementById('mocambos-log');
            const statusEl = document.getElementById('mocambos-progress-status');

            importBtn.classList.add('hidden');
            progressDiv.classList.remove('hidden');
            resultDiv.classList.add('hidden');
            logDiv.innerHTML = '';

            const colorMap = {
                info: 'text-blue-300',
                success: 'text-green-400',
                error: 'text-red-400',
                warning: 'text-yellow-400',
                node: 'text-purple-300',
                download: 'text-gray-400',
                done: 'text-green-300 font-bold',
            };

            function appendLog(msg, type) {
                const line = document.createElement('div');
                line.className = colorMap[type] || 'text-gray-300';
                line.textContent = msg;
                logDiv.appendChild(line);
                logDiv.scrollTop = logDiv.scrollHeight;
            }

            try {
                const resp = await fetch(`${MOCAMBOS_API}&action=import`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        api_base: mocambosApiBase,
                        galaxias: selected
                    })
                });

                if (!resp.ok && resp.headers.get('content-type')?.includes('application/json')) {
                    const err = await resp.json();
                    throw new Error(err.error || err.title || STR.import_failed_generic);
                }

                // Read streamed newline-delimited JSON
                const reader = resp.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let finalData = null;

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });

                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // keep incomplete line in buffer

                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const evt = JSON.parse(line);
                            appendLog(evt.message, evt.type);
                            if (evt.type === 'node') {
                                statusEl.textContent = evt.message.replace(/^\(\d+\/\d+\)\s*/, '');
                            } else if (evt.type === 'info' || evt.type === 'success') {
                                statusEl.textContent = evt.message;
                            }
                            if (evt.type === 'done') {
                                finalData = evt;
                            }
                        } catch (e) { /* skip unparseable lines */ }
                    }
                }

                // Process any remaining buffer
                if (buffer.trim()) {
                    try {
                        const evt = JSON.parse(buffer);
                        appendLog(evt.message, evt.type);
                        if (evt.type === 'done') finalData = evt;
                    } catch (e) { /* skip */ }
                }

                // Hide spinner, show summary
                statusEl.textContent = STR.import_complete_status;
                document.querySelector('#mocambos-import-progress .loading')?.classList.add('hidden');

                if (finalData && finalData.results) {
                    let html = '<div class="space-y-2 mt-3">';
                    let hasErrors = false;
                    finalData.results.forEach(r => {
                        const status = r.is_new ? STR.status_label_new : STR.status_label_refreshed;
                        const countInfo = fmt(STR.items_count, r.imported_count, r.expected_count);
                        const errCount = (r.errors || []).length;
                        html += `<div class="p-2 rounded ${errCount > 0 ? 'bg-yellow-50 border border-yellow-200' : 'bg-green-50 border border-green-200'}">`;
                        html += `<p class="text-sm font-medium">${status}: <strong>${r.galaxia_slug}</strong>: ${countInfo}</p>`;
                        if (errCount > 0) {
                            hasErrors = true;
                            html += `<ul class="text-xs text-red-600 mt-1 list-disc list-inside">`;
                            r.errors.forEach(e => { html += `<li>${e}</li>`; });
                            html += `</ul>`;
                        }
                        html += `</div>`;
                    });
                    html += '</div>';
                    resultDiv.innerHTML = html;
                    resultDiv.classList.remove('hidden');

                    showMessage(hasErrors ? STR.completed_errors : STR.completed_success, hasErrors ? 'error' : 'success');
                    setTimeout(() => { window.location.reload(); }, 3000);
                }

            } catch (e) {
                appendLog(fmt(STR.error_prefix, e.message || STR.unknown_error), 'error');
                statusEl.textContent = STR.import_failed_generic;
                document.querySelector('#mocambos-import-progress .loading')?.classList.add('hidden');
                importBtn.classList.remove('hidden');
            }
        }

        function refreshImportedConstellation(id, name) {
            const source = (window.constImportSources || {})[id];
            if (!source || !source.api_base || !source.galaxia_slug) {
                showMessage(STR.missing_source, 'error');
                return;
            }

            // Show confirmation step in the modal
            const modal = document.getElementById('mocambos_import_modal');
            const urlStep = document.getElementById('mocambos-url-step');
            const galaxiasList = document.getElementById('mocambos-list');
            const loading = document.getElementById('mocambos-loading');
            const errorDiv = document.getElementById('mocambos-error');
            const progressDiv = document.getElementById('mocambos-import-progress');
            const resultDiv = document.getElementById('mocambos-import-result');
            const importBtn = document.getElementById('mocambos-import-btn');
            const confirmStep = document.getElementById('refresh-confirm-step');
            const confirmInput = document.getElementById('refresh-confirm-input');
            const confirmBtn = document.getElementById('refresh-confirm-btn');
            const confirmPrompt = document.getElementById('refresh-confirm-prompt');

            // Hide everything, show confirmation step
            urlStep.classList.add('hidden');
            galaxiasList.classList.add('hidden');
            loading.classList.add('hidden');
            errorDiv.classList.add('hidden');
            resultDiv.classList.add('hidden');
            progressDiv.classList.add('hidden');
            if (importBtn) importBtn.classList.add('hidden');

            // Inject the templated prompt with the galaxy name (HTML <strong> preserved).
            confirmPrompt.innerHTML = fmt(STR.refresh_confirm_instruction, name);
            confirmInput.value = '';
            confirmBtn.disabled = true;
            confirmInput.oninput = () => {
                confirmBtn.disabled = confirmInput.value.trim() !== name;
            };
            confirmBtn.onclick = () => {
                confirmStep.classList.add('hidden');
                doRefreshImport(id, name);
            };
            confirmStep.classList.remove('hidden');
            modal.showModal();
            confirmInput.focus();
        }

        async function doRefreshImport(id, name) {
            const source = (window.constImportSources || {})[id];
            const modal = document.getElementById('mocambos_import_modal');
            const galaxiasList = document.getElementById('mocambos-list');
            const progressDiv = document.getElementById('mocambos-import-progress');
            const resultDiv = document.getElementById('mocambos-import-result');
            const logDiv = document.getElementById('mocambos-log');
            const statusEl = document.getElementById('mocambos-progress-status');
            const importBtn = document.getElementById('mocambos-import-btn');

            // Show progress
            if (importBtn) importBtn.classList.add('hidden');
            galaxiasList.classList.remove('hidden');
            document.getElementById('mocambos-galaxias').classList.add('hidden');
            galaxiasList.querySelectorAll(':scope > p').forEach(p => p.classList.add('hidden'));
            resultDiv.classList.add('hidden');
            progressDiv.classList.remove('hidden');
            logDiv.innerHTML = '';
            if (statusEl) statusEl.textContent = fmt(STR.refreshing, name);

            const colorMap = {
                info: 'text-blue-300', success: 'text-green-400', error: 'text-red-400',
                warning: 'text-yellow-400', node: 'text-purple-300', download: 'text-gray-400',
                done: 'text-green-300 font-bold',
            };
            function appendLog(msg, type) {
                const line = document.createElement('div');
                line.className = colorMap[type] || 'text-gray-300';
                line.textContent = msg;
                logDiv.appendChild(line);
                logDiv.scrollTop = logDiv.scrollHeight;
            }

            try {
                const resp = await fetch(`${MOCAMBOS_API}&action=import`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY, 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({
                        api_base: source.api_base,
                        galaxias: [{
                            galaxia_slug: source.galaxia_slug,
                            galaxia_smid: source.galaxia_smid || '',
                            mucua_slug: source.mucua_slug || ''
                        }]
                    })
                });

                if (!resp.ok && resp.headers.get('content-type')?.includes('application/json')) {
                    const err = await resp.json();
                    throw new Error(err.error || err.title || STR.import_failed_generic);
                }

                const reader = resp.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();
                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const msg = JSON.parse(line);
                            appendLog(msg.message || line, msg.type || 'info');
                        } catch (e) {
                            appendLog(line, 'info');
                        }
                    }
                }
                if (buffer.trim()) {
                    try {
                        const msg = JSON.parse(buffer);
                        appendLog(msg.message || buffer, msg.type || 'info');
                    } catch (e) {
                        appendLog(buffer, 'info');
                    }
                }

                appendLog(STR.refresh_complete_log, 'done');
                if (statusEl) statusEl.textContent = STR.refresh_complete_status;
                if (typeof loadConstellations === 'function') loadConstellations();
            } catch (e) {
                appendLog(fmt(STR.error_prefix, e.message || STR.unknown_error), 'error');
                if (statusEl) statusEl.textContent = STR.refresh_failed_status;
                resultDiv.innerHTML = `<button type="button" onclick="document.getElementById('mocambos_import_modal').close()" class="btn btn-sm mt-3">${STR.import_failed_generic}</button>`;
                resultDiv.classList.remove('hidden');
            }
        }

        // Expose the entry points needed by inline onclick attributes in the modal HTML.
        window.openMocambosImportModal = openMocambosImportModal;
        window.showMocambosUrlStep = showMocambosUrlStep;
        window.fetchMocambosGalaxias = fetchMocambosGalaxias;
        window.doMocambosImport = doMocambosImport;

        // Register refresh UI with the framework registry.
        window.BRIDGES_REFRESH_UI = window.BRIDGES_REFRESH_UI || {};
        window.BRIDGES_REFRESH_UI['mocambos'] = refreshImportedConstellation;
    })();
    </script>
<?php
}
