<?php
/**
 * Fleet shapes modal - dedicated, admin-only, launched from the Galaxies tab
 * toolbar ("Galaxy shapes", openFractalFleetModal in js/galaxy-edit-modal.js).
 * Read-only. Lists every galaxy with a one-word shape + the always-defined counts,
 * so the whole fleet is legible at a glance; the per-galaxy "Galaxy shape" panel
 * carries the full picture. Static labels localized here via t_attr().
 */
?>
<dialog id="fractal_fleet_modal" class="modal">
    <div class="modal-box bg-white !pt-0 max-w-2xl">
        <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
            <h3 class="font-bold text-xl"><?= t_attr('gem_fractal_fleet_title', 'Galaxy shapes overview') ?></h3>
        </div>

        <div class="mt-4">
            <p class="text-sm text-gray-500 mb-3"><?= t_attr('gem_fractal_fleet_intro', 'A quick read on the shape of every galaxy. Open Galaxy shape from a galaxy\'s Actions menu for the full picture.') ?></p>

            <p id="fractal-fleet-loading" class="text-sm text-gray-500 italic"><?= t_attr('gem_fractal_fleet_loading', 'Reading the galaxies…') ?></p>
            <p id="fractal-fleet-empty" class="text-sm text-gray-600 hidden"><?= t_attr('gem_fractal_fleet_empty', 'No galaxies to show.') ?></p>

            <div id="fractal-fleet-body" class="hidden overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-300 text-left text-gray-600">
                            <th class="py-2 px-2 ff-sort cursor-pointer select-none hover:text-gray-900" data-sort="name"><?= t_attr('gem_fractal_fleet_col_name', 'Galaxy') ?><span class="ff-arrow"></span></th>
                            <th class="py-2 px-2 ff-sort cursor-pointer select-none hover:text-gray-900" data-sort="shape"><?= t_attr('gem_fractal_fleet_col_shape', 'Shape') ?><span class="ff-arrow"></span></th>
                            <th class="py-2 px-2 text-right ff-sort cursor-pointer select-none hover:text-gray-900" data-sort="node_count"><?= t_attr('gem_fractal_stat_nodes', 'Wormholes') ?><span class="ff-arrow"></span></th>
                            <th class="py-2 px-2 text-right ff-sort cursor-pointer select-none hover:text-gray-900" data-sort="edge_count"><?= t_attr('gem_fractal_stat_edges', 'Connections') ?><span class="ff-arrow"></span></th>
                            <th class="py-2 px-2 text-right ff-sort cursor-pointer select-none hover:text-gray-900" data-sort="density"><?= t_attr('gem_fractal_stat_density', 'Link density') ?><span class="ff-arrow"></span></th>
                            <th class="py-2 px-2"><?= t_attr('gem_fractal_fleet_col_flags', 'Notes') ?></th>
                        </tr>
                    </thead>
                    <tbody id="ff-rows"></tbody>
                </table>
            </div>

            <div class="modal-action">
                <button type="button" class="btn btn-neutral" onclick="document.getElementById('fractal_fleet_modal').close()"><?= t_attr('editor_btn_close', 'Close') ?></button>
            </div>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button><?= t_attr('gem_close_btn', 'close') ?></button></form>
</dialog>
