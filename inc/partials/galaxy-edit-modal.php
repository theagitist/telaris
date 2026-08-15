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
            <h3 class="font-bold text-xl">
                <span id="gem-heading-edit"><?= t_attr('gem_heading', 'Edit Galaxy') ?></span>
                <span id="gem-heading-create" class="hidden"><?= t_attr('admin_modal_heading_create_galaxy', 'Create New Galaxy') ?></span>
            </h3>
            <span id="modal-constellation-id-badge" class="text-xs opacity-70 font-mono"></span>
        </div>
        <form method="POST" action="" class="mt-4">
            <input type="hidden" name="action" value="update_constellation">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" id="modal-constellation-id" name="id">

            <div class="mb-4">
                <label for="modal-constellation-name" class="block mb-1.5 text-gray-800 font-medium"><?= t_attr('gem_name_label', 'Name *') ?></label>
                <input type="text" id="modal-constellation-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                <span id="modal-constellation-name-error" class="text-xs text-red-600 mt-1 hidden"><?= t_attr('gem_name_duplicate_error', 'This name is already in use.') ?></span>
            </div>

            <div class="mb-4">
                <label for="modal-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium"><?= t_attr('gem_tagline_label', 'Tagline') ?></label>
                <input type="text" id="modal-constellation-tagline" name="tagline" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
            </div>

            <?php if ($isAdmin): ?>
            <div class="mb-4">
                <label for="modal-constellation-slug" class="block mb-1.5 text-gray-800 font-medium"><?= t_attr('gem_slug_label', 'URL Slug') ?></label>
                <input type="text" id="modal-constellation-slug" name="slug" placeholder="<?= t_attr('gem_slug_placeholder', 'e.g. archive') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                <span id="modal-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden"><?= t_attr('gem_slug_duplicate_error', 'This slug is already in use.') ?></span>
                <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('gem_slug_help', 'Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.') ?></span>
            </div>
            <?php else: ?>
            <input type="hidden" id="modal-constellation-slug" name="_slug_unused">
            <?php endif; ?>

            <div class="mb-4">
                <label for="modal-constellation-theme" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_theme_label', 'Visual Theme') ?></label>
                <select id="modal-constellation-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                    <option value="cosmic"><?= t_attr('gem_theme_cosmic', 'Cosmic (Stars, Planets, Rockets)') ?></option>
                    <option value="simple"><?= t_attr('gem_theme_simple', 'Simple (Colored Spheres)') ?></option>
                    <option value="abstract"><?= t_attr('gem_theme_abstract', 'Abstract (Geometric GIF Icons)') ?></option>
                    <option value="rectangles"><?= t_attr('gem_theme_rectangles', 'Rectangles (Custom Rectangle Icons)') ?></option>
                    <option value="stripes"><?= t_attr('gem_theme_stripes', 'Stripes (Custom Stripe Icons)') ?></option>
                    <option value="tech"><?= t_attr('gem_theme_tech', 'Tech (Circuit Board Icons)') ?></option>
                    <option value="rhizome"><?= t_attr('gem_theme_rhizome', 'Rhizome (Light, Connection Map)') ?></option>
                    <?php // light-rainbow theme intentionally hidden from the picker pending rework; still defined in js/themes.js, the validation allowlists, and i18n (gem_theme_light_rainbow). Restore an option element with value light-rainbow to expose it again. ?>
                </select>
            </div>

            <div class="mb-4">
                <label for="modal-sound-theme" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_sound_theme_label', 'Sound Theme') ?></label>
                <select id="modal-sound-theme" name="sound_theme" class="select select-bordered select-sm w-full bg-white">
                    <option value="default"><?= t_attr('gem_sound_theme_default', 'Default (Ambient)') ?></option>
                    <option value="rhizome"><?= t_attr('gem_sound_theme_rhizome', 'Rhizome (Glitchy, High-Pitched)') ?></option>
                </select>
            </div>

            <?php // Everything below configures an EXISTING galaxy (tags, discovery, idle
                  // spotlight, tours, bulk actions), so it is hidden in create mode. The
                  // unified modal shows only name/tagline/slug/theme when creating; the rest
                  // is configured once the galaxy exists (open it for edit). ?>
            <div id="gem-edit-only">

            <?php if ($isAdmin): ?>
            <div class="mb-4 border-t border-gray-200 pt-4">
                <input type="hidden" name="editors_enabled_present" value="1">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-constellation-editors-enabled" name="editors_enabled" value="1" class="toggle toggle-neutral toggle-sm" checked>
                    <span class="text-gray-800 font-medium text-sm"><?= t_attr('admin_label_galaxy_editors_enabled', 'Allow editors') ?></span>
                </label>
                <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('admin_help_galaxy_editors_enabled', 'When off, editors cannot edit this galaxy. Admins are unaffected.') ?></span>
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_tags_label', 'Tags') ?></label>
                <div id="modal-galaxy-tags-container" class="flex flex-wrap gap-2 p-2 border border-gray-300 rounded bg-white focus-within:border-blue-500 transition-colors min-h-[2.75rem] relative">
                    <input type="text" id="modal-galaxy-tags-input" placeholder="<?= t_attr('gem_tags_placeholder', 'Add tag...') ?>"
                        class="flex-1 min-w-[120px] outline-none border-none focus:ring-0 text-sm bg-transparent" autocomplete="off">
                    <div id="modal-galaxy-tags-suggestions" class="hidden absolute left-0 right-0 top-full mt-1 z-[100] max-h-56 overflow-y-auto overscroll-contain rounded border border-gray-300 bg-white shadow-lg text-sm"></div>
                </div>
                <input type="hidden" id="modal-galaxy-tags-hidden" name="tags" value="">
                <span class="text-xs text-gray-500 mt-1 block"><?= t('gem_tags_help', 'Visitors can browse the union of every galaxy carrying a tag at <code>/tag/&lt;tag&gt;</code>. Type to add; press Enter or comma. Suggestions surface tags already used in this galaxy and in sibling galaxies sharing your <code>[XX]</code> prefix.') ?></span>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_bulk_actions_label', 'Bulk wormhole actions') ?></label>
                <p class="text-xs text-gray-500 mb-2"><?= t_attr('gem_bulk_actions_help', 'Apply to every wormhole in this galaxy at once. Per-wormhole toggles still override afterward.') ?></p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-neutral btn-outline" data-bulk-flag="use_image_as_node" data-bulk-value="1">
                        <?= t_attr('gem_bulk_use_images_btn', 'Use images as icons (all wormholes)') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" data-bulk-flag="use_image_as_node" data-bulk-value="0">
                        <?= t_attr('gem_bulk_revert_icons_btn', 'Revert all to theme icons') ?>
                    </button>
                </div>
                <p id="modal-bulk-feedback" class="text-xs mt-2 text-gray-600"></p>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-keyword-chips-enabled" name="keyword_chips_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                    <span class="text-gray-800 font-medium"><?= t_attr('gem_keyword_chips_label', 'Keyword chips') ?></span>
                </label>
                <p class="text-xs text-gray-500 mt-1"><?= t_attr('gem_keyword_chips_help', "Show the most-used keywords as filter chips at the top of the galaxy. Click a chip to dim wormholes that don't match.") ?></p>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-related-nodes-enabled" name="related_nodes_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                    <span class="text-gray-800 font-medium"><?= t_attr('gem_related_label', 'Related wormholes') ?></span>
                </label>
                <p class="text-xs text-gray-500 mt-1"><?= t_attr('gem_related_help', "When a wormhole's info card is open, dim unrelated wormholes in the scene and show up to 5 related ones (sharing keywords) as click-to-jump chips at the bottom of the card. Random sample each time.") ?></p>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-show-2d-view" name="show_2d_view" value="1" class="toggle toggle-neutral toggle-sm">
                    <span class="text-gray-800 font-medium"><?= t_attr('gem_2d_view_label', '2D view switch') ?></span>
                </label>
                <p class="text-xs text-gray-500 mt-1"><?= t_attr('gem_2d_view_help', 'Show a top-center "3D / 2D" toggle so visitors can flip from the 3D scene to a flat grid of wormhole chips. The preference persists in the browser.') ?></p>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-group-nodes" name="group_nodes" value="1" class="toggle toggle-neutral toggle-sm">
                    <span class="text-gray-800 font-medium"><?= t_attr('gem_group_nodes_label', 'Group wormholes') ?></span>
                </label>
                <p class="text-xs text-gray-500 mt-1"><?= t_attr('gem_group_nodes_help', 'When a galaxy has many wormholes, bundle them into navigable groups instead of showing all at once. On by default. Turn off to always show every wormhole, however many there are.') ?></p>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-heavy-inertia" name="heavy_inertia" value="1" class="toggle toggle-neutral toggle-sm">
                    <span class="text-gray-800 font-medium"><?= t_attr('gem_heavy_inertia_label', 'Heavy movement') ?></span>
                </label>
                <p class="text-xs text-gray-500 mt-1"><?= t_attr('gem_heavy_inertia_help', 'Give this galaxy a weighty, high-inertia feel: rotating and zooming are slower and the view keeps gliding after you let go, so a dense galaxy feels massive. Off by default.') ?></p>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="modal-idle-spotlight-enabled" name="idle_spotlight_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                    <span class="text-gray-800 font-medium"><?= t_attr('gem_idle_spotlight_label', 'Idle spotlight') ?></span>
                </label>
                <p class="text-xs text-gray-500 mt-1"><?= t_attr('gem_idle_spotlight_help', 'After a period of inactivity, fly the camera to one random wormhole and open its info card. Closes when media ends or after the dwell timer.') ?></p>

                <div id="modal-idle-spotlight-section" class="mt-4 pl-6 border-l-2 border-gray-200 space-y-4 hidden">
                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_pick_from_label', 'Pick from') ?></label>
                        <div class="space-y-1">
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="idle_spotlight_selection" value="all" class="radio radio-neutral radio-sm idle-spotlight-selection">
                                <span><?= t_attr('gem_idle_pick_all', 'All wormholes') ?></span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="idle_spotlight_selection" value="accentuated" class="radio radio-neutral radio-sm idle-spotlight-selection">
                                <span><?= t_attr('gem_idle_pick_accentuated', 'Only accentuated wormholes') ?></span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label for="modal-idle-spotlight-idle-seconds" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_idle_trigger_label', 'Trigger after (seconds idle)') ?></label>
                        <input type="number" id="modal-idle-spotlight-idle-seconds" name="idle_spotlight_idle_seconds" min="1" value="30" class="input input-bordered input-sm w-32 bg-white">
                    </div>
                </div>
            </div>

            <div class="mb-4 border-t border-gray-200 pt-4">
                <div class="flex items-center justify-between gap-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal-tour-enabled" name="tour_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium"><?= t_attr('gem_autotour_label', 'Auto-tour') ?></span>
                    </label>
                    <button type="button" id="modal-tour-preview" class="btn btn-xs btn-outline" title="<?= t_attr('gem_autotour_preview_title', 'Save first, then preview the tour in a new tab') ?>"><?= t_attr('gem_autotour_preview_btn', 'Preview tour') ?></button>
                </div>
                <p class="text-xs text-gray-500 mt-1"><?= t_attr('gem_autotour_help', 'Automatically navigate through nodes, opening each card and playing media. Desktop and iPad only.') ?></p>

                <div id="modal-tour-section" class="mt-4 pl-6 border-l-2 border-gray-200 space-y-4 hidden">

                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_start_mode_label', 'Start Mode') ?></label>
                        <div class="space-y-1">
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_start_mode" value="manual" class="radio radio-neutral radio-sm tour-start-mode">
                                <span><?= t_attr('gem_start_mode_manual', 'Manual. Starts when a Play button is clicked.') ?></span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_start_mode" value="idle" class="radio radio-neutral radio-sm tour-start-mode">
                                <span><?= t_attr('gem_start_mode_idle', 'Idle. Starts after a period of inactivity.') ?></span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_start_mode" value="immediate" class="radio radio-neutral radio-sm tour-start-mode">
                                <span><?= t_attr('gem_start_mode_immediate', 'Immediate. Starts a few seconds after the galaxy loads.') ?></span>
                            </label>
                        </div>
                    </div>

                    <div id="modal-tour-idle-row" class="hidden">
                        <label for="modal-tour-idle-seconds" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_idle_threshold_label', 'Idle threshold (seconds)') ?></label>
                        <input type="number" id="modal-tour-idle-seconds" name="tour_idle_seconds" min="1" value="30" class="input input-bordered input-sm w-32 bg-white">
                    </div>

                    <div id="modal-tour-immediate-warning" class="hidden alert alert-warning text-sm py-2">
                        <span><?= t_attr('gem_immediate_audio_warning', 'This galaxy contains audio nodes. Browsers block autoplay-with-sound until there is some interaction with the page, so the first audio in an immediate-start tour may stay silent or stall.') ?></span>
                    </div>

                    <div>
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_which_nodes_label', 'Which nodes to tour') ?></label>
                        <div class="space-y-1">
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="all" class="radio radio-neutral radio-sm tour-node-selection">
                                <span><?= t_attr('gem_nodes_all', 'All nodes (random order each run)') ?></span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="accentuated" class="radio radio-neutral radio-sm tour-node-selection">
                                <span><?= t_attr('gem_nodes_accentuated', 'Only accentuated nodes') ?></span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="random_n" class="radio radio-neutral radio-sm tour-node-selection">
                                <span><?= t_attr('gem_nodes_random_n', 'A random sample of N nodes') ?></span>
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="radio" name="tour_node_selection" value="tagged" class="radio radio-neutral radio-sm tour-node-selection">
                                <span><?= t_attr('gem_nodes_tagged', 'Nodes tagged with one of these keywords') ?></span>
                            </label>
                        </div>
                    </div>

                    <div id="modal-tour-random-row" class="hidden">
                        <label for="modal-tour-random-count" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_random_count_label', 'How many nodes per tour') ?></label>
                        <input type="number" id="modal-tour-random-count" name="tour_random_count" min="1" value="10" class="input input-bordered input-sm w-32 bg-white">
                    </div>

                    <div id="modal-tour-tagged-row" class="hidden">
                        <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_keywords_label', 'Keywords (any match)') ?></label>
                        <div id="modal-tour-keywords" class="border border-gray-300 rounded p-2 max-h-40 overflow-y-auto bg-white text-sm"></div>
                        <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('gem_keywords_help', 'Nodes matching any of the selected keywords are shown to visitors.') ?></span>
                    </div>

                    <div>
                        <label for="modal-tour-default-dwell" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('gem_dwell_label', 'Pause on nodes without media (seconds)') ?></label>
                        <input type="number" id="modal-tour-default-dwell" name="tour_default_dwell" min="1" value="8" class="input input-bordered input-sm w-32 bg-white">
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal-tour-loop" name="tour_loop" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium text-sm"><?= t_attr('gem_loop_label', 'Loop the tour when it finishes') ?></span>
                    </label>
                </div>
            </div>

            </div><!-- /gem-edit-only -->

            <div class="modal-action items-center justify-between">
                <!-- Edit mode: live autosave chip. Create mode: hidden (explicit Create button). -->
                <div id="gem-autosave-status" class="flex items-center gap-2" aria-live="polite"
                     data-saving="<?= t_attr('editor_autosave_saving', 'Saving…') ?>"
                     data-saved="<?= t_attr('editor_autosave_saved', 'All changes saved') ?>"
                     data-failed="<?= t_attr('editor_autosave_failed', 'Save failed; keep editing to retry') ?>">
                    <span class="loading loading-spinner loading-xs text-gray-400 hidden" data-autosave-spinner></span>
                    <span data-autosave-text class="text-xs font-medium text-gray-400"></span>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Create mode only: one explicit submit creates the galaxy (native POST). -->
                    <button type="submit" id="gem-submit-btn" class="btn btn-neutral hidden"><?= t_attr('admin_modal_btn_create_galaxy', 'Create Galaxy') ?></button>
                    <button type="button" class="btn btn-neutral" onclick="document.getElementById('constellation_modal').close()"><?= t_attr('editor_btn_close', 'Close') ?></button>
                </div>
            </div>
        </form>
    </div>
    <form method="dialog" class="modal-backdrop"><button><?= t_attr('gem_close_btn', 'close') ?></button></form>
</dialog>
