<?php
/**
 * Fractal profile modal - dedicated, admin-only, launched from the galaxy row
 * Actions menu (openFractalProfileModal in js/galaxy-edit-modal.js). Read-only.
 *
 * Include from admin/index.php only. The companion JS fills the value spans and
 * draws the f(alpha) curve; static labels are localized here via t_attr().
 * Plain-language first: a one-line summary + concrete counts up top, with the
 * technical measurements tucked into a collapsible drawer.
 */
?>
<dialog id="fractal_profile_modal" class="modal">
    <div class="modal-box bg-white !pt-0 max-w-lg">
        <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
            <h3 class="font-bold text-xl">
                <?= t_attr('gem_fractal_title', 'How this galaxy is shaped') ?>
                <span id="fp-galaxy-name" class="font-normal opacity-80"></span>
            </h3>
            <div class="text-xs opacity-70 mt-0.5"><?= t_attr('gem_fractal_subtitle', 'Fractal profile · read-only') ?></div>
        </div>

        <div class="mt-4">
            <p class="text-sm text-gray-500 mb-4"><?= t_attr('gem_fractal_intro', 'A quick read on how this galaxy\'s wormholes connect to each other through shared keywords.') ?></p>

            <p id="fractal-profile-loading" class="text-sm text-gray-500 italic"><?= t_attr('gem_fractal_loading', 'Reading the galaxy…') ?></p>
            <p id="fractal-profile-nocompute" class="text-sm text-gray-600 hidden"></p>

            <div id="fractal-profile-body" class="hidden">
                <!-- Plain-language summary, the headline. -->
                <p id="fp-summary" class="text-base text-gray-800 leading-relaxed"></p>

                <!-- Concrete counts anyone can read. -->
                <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-700">
                    <span><?= t_attr('gem_fractal_stat_nodes', 'Wormholes') ?>: <b id="fp-nodes"></b></span>
                    <span><?= t_attr('gem_fractal_stat_edges', 'Connections') ?>: <b id="fp-edges"></b></span>
                    <span><?= t_attr('gem_fractal_stat_components', 'Connected pieces') ?>: <b id="fp-comps"></b></span>
                    <span><?= t_attr('gem_fractal_stat_diameter', 'Steps across') ?>: <b id="fp-diam"></b></span>
                </div>

                <!-- The technical measurements, opt-in. -->
                <details class="mt-4">
                    <summary class="text-sm text-gray-500 cursor-pointer select-none"><?= t_attr('gem_fractal_details_toggle', 'Show the measurements') ?></summary>
                    <div class="mt-3 space-y-1.5 text-xs text-gray-600 font-mono">
                        <div><?= t_attr('gem_fractal_dB_label', 'Fractal dimension (d_B)') ?>: <span id="fp-dB"></span> <span id="fp-dB-r2" class="text-gray-400"></span></div>
                        <div><?= t_attr('gem_fractal_width_label', 'Unevenness (spectrum width)') ?>: <span id="fp-width"></span></div>
                        <div><?= t_attr('gem_fractal_gen_dims_label', 'Generalized dimensions (D0/D1/D2)') ?>: <span id="fp-dims"></span></div>
                        <div><?= t_attr('gem_fractal_gamma_label', 'Hub dominance (degree exponent γ)') ?>: <span id="fp-gamma"></span></div>
                    </div>
                    <div class="mt-3">
                        <div class="text-xs text-gray-500 mb-1"><?= t_attr('gem_fractal_spectrum_label', 'Connection texture, f(α)') ?></div>
                        <svg id="fp-chart" viewBox="0 0 220 120" class="w-56 h-28 border border-gray-200 rounded bg-gray-50" preserveAspectRatio="xMidYMid meet"></svg>
                    </div>
                </details>
            </div>

            <div class="modal-action">
                <button type="button" class="btn btn-neutral" onclick="document.getElementById('fractal_profile_modal').close()"><?= t_attr('editor_btn_close', 'Close') ?></button>
            </div>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button><?= t_attr('gem_close_btn', 'close') ?></button></form>
</dialog>
