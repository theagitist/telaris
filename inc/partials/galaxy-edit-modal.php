<?php
/**
 * Galaxy edit modal — shared between /admin/index.php and /edit/index.php.
 *
 * Caller is expected to set $isAdmin (bool) before including. Editors don't see
 * the slug field (changing it would break bookmarked URLs).
 *
 * The companion JS lives in js/galaxy-edit-modal.js. The form posts back to
 * the page that included the partial; the page calls handle_galaxy_update_post()
 * (inc/galaxy-update.php) from its action dispatcher.
 */
$isAdmin = isset($isAdmin) ? (bool)$isAdmin : false;
?>
<dialog id="constellation_modal" class="modal">
    <div class="modal-box bg-white !pt-0 max-w-3xl">
        <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
            <h3 class="font-bold text-xl">Edit Galaxy</h3>
            <span id="modal-constellation-id-badge" class="text-xs opacity-70 font-mono"></span>
        </div>
        <form method="POST" action="" class="mt-4">
            <input type="hidden" name="action" value="update_constellation">
            <input type="hidden" id="modal-constellation-id" name="id">

            <div class="mb-4">
                <label for="modal-constellation-name" class="block mb-1.5 text-gray-800 font-medium">Name *</label>
                <input type="text" id="modal-constellation-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                <span id="modal-constellation-name-error" class="text-xs text-red-600 mt-1 hidden">This name is already in use.</span>
            </div>

            <div class="mb-4">
                <label for="modal-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                <input type="text" id="modal-constellation-tagline" name="tagline" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
            </div>

            <?php if ($isAdmin): ?>
            <div class="mb-4">
                <label for="modal-constellation-slug" class="block mb-1.5 text-gray-800 font-medium">URL Slug</label>
                <input type="text" id="modal-constellation-slug" name="slug" placeholder="e.g. archive" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                <span id="modal-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden">This slug is already in use.</span>
                <span class="text-xs text-gray-500 mt-1 block">Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.</span>
            </div>
            <?php else: ?>
            <input type="hidden" id="modal-constellation-slug" name="_slug_unused">
            <?php endif; ?>

            <div class="mb-4">
                <label for="modal-constellation-theme" class="block mb-1.5 text-gray-800 font-medium text-sm">Visual Theme</label>
                <select id="modal-constellation-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                    <option value="cosmic">Cosmic (Stars, Planets, Rockets)</option>
                    <option value="simple">Simple (Colored Spheres)</option>
                    <option value="abstract">Abstract (Geometric GIF Icons)</option>
                    <option value="rectangles">Rectangles (Custom Rectangle Icons)</option>
                    <option value="stripes">Stripes (Custom Stripe Icons)</option>
                    <option value="tech">Tech (Circuit Board Icons)</option>
                </select>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-tour-enabled" name="tour_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                    <span class="text-gray-800 font-medium">Auto-tour</span>
                </label>
                <p class="text-xs text-gray-500 mt-1">Automatically navigate visitors through nodes, opening each card and playing media. Desktop and iPad only.</p>

                <div id="modal-tour-section" class="mt-4 pl-6 border-l-2 border-gray-200 space-y-4 hidden">

                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Start Mode</label>
                        <div class="space-y-1">
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_start_mode" value="manual" class="radio radio-neutral radio-sm tour-start-mode">
                                <span>Manual. Visitor clicks a Play button to start.</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_start_mode" value="idle" class="radio radio-neutral radio-sm tour-start-mode">
                                <span>Idle. Start after visitor is inactive for a while.</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_start_mode" value="immediate" class="radio radio-neutral radio-sm tour-start-mode">
                                <span>Immediate. Start a few seconds after the galaxy loads.</span>
                            </label>
                        </div>
                    </div>

                    <div id="modal-tour-idle-row" class="hidden">
                        <label for="modal-tour-idle-seconds" class="block mb-1.5 text-gray-800 font-medium text-sm">Idle threshold (seconds)</label>
                        <input type="number" id="modal-tour-idle-seconds" name="tour_idle_seconds" min="1" value="30" class="input input-bordered input-sm w-32 bg-white">
                    </div>

                    <div id="modal-tour-immediate-warning" class="hidden alert alert-warning text-sm py-2">
                        <span>This galaxy contains audio nodes. Browsers block autoplay-with-sound until the visitor interacts with the page, so the first audio in an immediate-start tour may stay silent or stall.</span>
                    </div>

                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Which nodes to tour</label>
                        <div class="space-y-1">
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="all" class="radio radio-neutral radio-sm tour-node-selection">
                                <span>All nodes (random order each run)</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="accentuated" class="radio radio-neutral radio-sm tour-node-selection">
                                <span>Only accentuated nodes</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="random_n" class="radio radio-neutral radio-sm tour-node-selection">
                                <span>A random sample of N nodes</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="tagged" class="radio radio-neutral radio-sm tour-node-selection">
                                <span>Nodes tagged with one of these keywords</span>
                            </label>
                        </div>
                    </div>

                    <div id="modal-tour-random-row" class="hidden">
                        <label for="modal-tour-random-count" class="block mb-1.5 text-gray-800 font-medium text-sm">How many nodes per tour</label>
                        <input type="number" id="modal-tour-random-count" name="tour_random_count" min="1" value="10" class="input input-bordered input-sm w-32 bg-white">
                    </div>

                    <div id="modal-tour-tagged-row" class="hidden">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm">Keywords (any match)</label>
                        <div id="modal-tour-keywords" class="border border-gray-300 rounded p-2 max-h-40 overflow-y-auto bg-white text-sm"></div>
                        <span class="text-xs text-gray-500 mt-1 block">Visitors will see nodes matching any of the selected keywords.</span>
                    </div>

                    <div>
                        <label for="modal-tour-default-dwell" class="block mb-1.5 text-gray-800 font-medium text-sm">Pause on nodes without media (seconds)</label>
                        <input type="number" id="modal-tour-default-dwell" name="tour_default_dwell" min="1" value="8" class="input input-bordered input-sm w-32 bg-white">
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal-tour-loop" name="tour_loop" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium text-sm">Loop the tour when it finishes</span>
                    </label>
                </div>
            </div>

            <div class="modal-action">
                <button type="submit" class="btn btn-neutral">Update Galaxy</button>
                <button type="button" class="btn" onclick="document.getElementById('constellation_modal').close()">Cancel</button>
            </div>
        </form>
    </div>
    <form method="dialog" class="modal-backdrop"><button>close</button></form>
</dialog>
