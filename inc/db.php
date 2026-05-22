<?php
declare(strict_types=1);

/**
 * Database layer: all DB connection and queries in one place.
 * Expects DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS to be defined (e.g. by config.php).
 */

// ---------------------------------------------------------------------------
// Connection
// ---------------------------------------------------------------------------

/** @var PDO|null Test-only override — when set, getDB() returns this instead of connecting. */
$_TELARIS_DB_OVERRIDE = null;

/**
 * Override (or clear) the PDO instance returned by getDB().
 * Used by test bootstrap to inject a test database connection.
 */
function resetDB(?PDO $override = null): void {
    global $_TELARIS_DB_OVERRIDE;
    $_TELARIS_DB_OVERRIDE = $override;
}

/**
 * @return PDO
 * @throws PDOException
 */
function getDB(): PDO {
    global $_TELARIS_DB_OVERRIDE;
    if ($_TELARIS_DB_OVERRIDE !== null) {
        return $_TELARIS_DB_OVERRIDE;
    }

    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $port = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '3306';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, $port, DB_NAME);
        $pdo = new PDO(
            $dsn,
            DB_USER,
            defined('DB_PASS') ? DB_PASS : '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode = "STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"'
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        throw $e;
    }
}


/**
 * @param PDO|null $pdo
 * @return string|null
 */
function getDefaultApiKey(?PDO $pdo = null): ?string {
    try {
        if ($pdo === null) {
            $pdo = getDB();
        }
        $stmt = $pdo->query("SELECT api_key FROM api_keys WHERE name = 'Default API Key' AND is_active = TRUE LIMIT 1");
        $result = $stmt->fetch();
        return $result ? $result['api_key'] : null;
    } catch (PDOException $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Project info
// ---------------------------------------------------------------------------

/** Column keys for project_info (one row per locale). */
const PROJECT_INFO_KEYS = [
    // Visitor (legacy + phase B)
    'name', 'description', 'iframe_back_text', 'alert_message', 'edit_button_text', 'loading_text', 'back_button_text', 'system_online_text', 'reload_system_text', 'scan_system_text', 'clear_scan_text', 'systems_label_text', 'hyperlinks_label_text', 'initialize_auth_text', 'admin_label_text', 'logout_label_text', 'click_to_view_text', 'tap_to_view_text', 'open_portal_text', 'sound_label_text', 'sound_on_text', 'sound_off_text', 'launching_text', 'mission_active_text', 'go_text', 'breadcrumb_all_text', 'launch_button_text', 'no_results_text', 'items_label_text', 'other_label_text', 'galaxies_label_text', 'galaxy_count_singular_text', 'galaxy_count_plural_text', 'pdf_loading_text', 'pdf_rendering_text', 'pdf_pages_singular_text', 'pdf_pages_plural_text', 'pdf_open_text', 'pdf_download_text', 'pdf_error_load_text', 'pdf_error_open_text', 'tour_label_text', 'tour_start_aria_text', 'tour_previous_aria_text', 'tour_pause_aria_text', 'tour_next_aria_text', 'tour_exit_aria_text', 'nav_toggle_aria_text', 'share_link_title_text', 'related_label_text', 'lang_label_text', 'node_name_fallback_text', 'untitled_text', 'chip_open_prefix_text', 'search_result_text', 'search_results_text',
    // Editor chunk C1 (edit/index.php)
    'editor_page_title', 'editor_user_role_admin', 'editor_user_role_editor', 'editor_label_current_galaxy', 'editor_option_all_galaxies_admin', 'editor_option_all_galaxies_editor', 'editor_btn_view', 'editor_btn_galaxy_settings_title', 'editor_btn_settings', 'editor_btn_keyword_canvas_title', 'editor_btn_canvas', 'editor_btn_copy_url_title', 'editor_btn_admin_console', 'editor_btn_logout', 'editor_error_no_api_key',
    'editor_bulk_selected_suffix', 'editor_btn_clear_selection', 'editor_btn_bulk_move', 'editor_btn_bulk_duplicate', 'editor_btn_bulk_delete',
    'editor_banner_imported_read_only', 'editor_heading_wormholes', 'editor_btn_new_wormhole', 'editor_btn_touched_today_title', 'editor_btn_touched_today', 'editor_btn_bulk_keyword_title', 'editor_btn_bulk_by_keyword', 'editor_btn_shortcuts_title', 'editor_label_search', 'editor_placeholder_search_wormholes',
    'editor_col_name', 'editor_col_type', 'editor_col_galaxy', 'editor_col_url', 'editor_col_keywords', 'editor_col_created', 'editor_col_updated', 'editor_col_actions', 'editor_col_acc', 'editor_col_acc_title',
    'editor_msg_loading_wormholes', 'editor_msg_retrieving_wormholes',
    'editor_heading_no_wormholes', 'editor_text_empty_state_help', 'editor_heading_error_loading',
    'editor_error_api_key_missing', 'editor_error_api_key_missing_fetch', 'editor_error_invalid_json', 'editor_error_invalid_format', 'editor_error_invalid_data_format',
    'editor_text_no_keywords', 'editor_label_node_type_portal', 'editor_label_node_type_object',
    'editor_badge_accentuated', 'editor_badge_accentuated_title', 'editor_badge_has_url', 'editor_badge_has_url_title', 'editor_badge_has_desc', 'editor_badge_has_desc_title', 'editor_badge_has_img', 'editor_badge_has_img_title', 'editor_badge_has_emb', 'editor_badge_has_emb_title', 'editor_badge_has_aud', 'editor_badge_has_aud_title', 'editor_badge_has_vid', 'editor_badge_has_vid_title', 'editor_title_accentuated',
    'editor_action_view_wormhole', 'editor_action_view_galaxy', 'editor_action_edit', 'editor_action_duplicate', 'editor_action_delete',
    'editor_toast_bulk_move_success', 'editor_toast_bulk_move_failed', 'editor_toast_bulk_move_error', 'editor_toast_duplicate_success', 'editor_error_failed_duplicate', 'editor_toast_duplicate_error_generic', 'editor_toast_bulk_duplicate_success', 'editor_toast_bulk_duplicate_failed', 'editor_toast_bulk_duplicate_error', 'editor_confirm_bulk_delete', 'editor_toast_bulk_delete_success', 'editor_toast_bulk_delete_failed', 'editor_toast_bulk_delete_error',
    'editor_toast_url_copied', 'editor_title_url_copied', 'editor_toast_galaxy_created', 'editor_toast_error_creating_galaxy', 'editor_prompt_new_galaxy_name',
    'editor_modal_heading_add_wormhole', 'editor_modal_heading_edit_wormhole', 'editor_label_name_required', 'editor_error_name_exists', 'editor_help_name', 'editor_label_galaxy', 'editor_help_constellation', 'editor_label_wormhole_type', 'editor_help_node_type', 'editor_label_keywords', 'editor_placeholder_add_keyword', 'editor_help_keywords_add', 'editor_label_accentuate_wormhole', 'editor_help_accentuate', 'editor_label_show_keywords', 'editor_help_show_keywords', 'editor_label_target_galaxy', 'editor_help_target_galaxy', 'editor_btn_create_new_galaxy', 'editor_label_description', 'editor_help_description', 'editor_label_url', 'editor_placeholder_url', 'editor_help_url', 'editor_label_primary_visual', 'editor_tab_image', 'editor_tab_video', 'editor_tab_pdf', 'editor_help_visual_mutex', 'editor_label_image_url_file', 'editor_label_use_as_icon', 'editor_placeholder_image_url', 'editor_placeholder_video_url', 'editor_label_autoplay_video', 'editor_placeholder_pdf_url', 'editor_help_pdf', 'editor_placeholder_credit', 'editor_help_credit', 'editor_label_icon_url_file', 'editor_placeholder_icon_url', 'editor_help_icon', 'editor_label_audio_url_file', 'editor_placeholder_audio_url', 'editor_label_autoplay', 'editor_label_loop', 'editor_help_audio',
    'editor_text_uploading', 'editor_btn_add_wormhole', 'editor_btn_cancel', 'editor_divider_media', 'editor_btn_delete_file', 'editor_btn_update_wormhole',
    'editor_modal_heading_confirm_delete', 'editor_btn_delete',
    'editor_modal_heading_move_wormholes', 'editor_text_move_count_wormholes', 'editor_label_destination_galaxy', 'editor_btn_move_wormholes',
    'editor_modal_heading_duplicate_wormhole', 'editor_text_duplicate_to', 'editor_btn_duplicate',
    'editor_modal_heading_duplicate_wormholes', 'editor_text_duplicate_count_wormholes', 'editor_btn_duplicate_wormholes',
    'editor_btn_open_link', 'editor_btn_apply', 'editor_label_target_prefix',
    'editor_modal_heading_bulk_keyword', 'editor_text_bulk_keyword_help', 'editor_label_keyword', 'editor_option_loading', 'editor_label_action', 'editor_option_delete_matching', 'editor_option_move_matching', 'editor_text_pick_keyword', 'editor_error_pick_specific_galaxy', 'editor_option_no_keywords', 'editor_option_pick_one', 'editor_option_error_keywords', 'editor_option_pick_galaxy',
    'editor_preview_move_one', 'editor_preview_move_many', 'editor_preview_move_pick_target_one', 'editor_preview_move_pick_target_many', 'editor_preview_delete_one', 'editor_preview_delete_many',
    'editor_confirm_bulk_delete_keyword_one', 'editor_confirm_bulk_delete_keyword_many', 'editor_confirm_bulk_move_keyword_one', 'editor_confirm_bulk_move_keyword_many', 'editor_toast_bulk_deleted_one', 'editor_toast_bulk_deleted_many', 'editor_toast_bulk_moved_one', 'editor_toast_bulk_moved_many', 'editor_toast_bulk_action_failed',
    'editor_modal_heading_shortcuts', 'editor_shortcut_new_wormhole', 'editor_shortcut_focus_search', 'editor_shortcut_toggle_touched', 'editor_shortcut_galaxy_settings', 'editor_shortcut_close_modal', 'editor_shortcut_open_help', 'editor_note_shortcuts_typing', 'editor_btn_close',
    'editor_toast_updated_successfully', 'editor_toast_created_successfully', 'editor_error_failed_update', 'editor_error_failed_create', 'editor_error_network_upload', 'editor_error_name_required', 'editor_error_loading_node', 'editor_confirm_delete_file', 'editor_toast_file_deleted', 'editor_error_deleting_file', 'editor_confirm_delete_node', 'editor_error_delete_wormhole', 'editor_toast_deleted_successfully', 'editor_error_deleting_wormhole', 'editor_error_fatal_loading', 'editor_error_could_not_load',
    // C2: keyword canvas (js/keyword-canvas.js) + galaxy-edit modal (js/galaxy-edit-modal.js).
    'editor_kc_status_loading', 'editor_kc_status_no_keywords', 'editor_kc_status_ready', 'editor_kc_status_saving', 'editor_kc_status_saved', 'editor_kc_status_deleting', 'editor_kc_status_deleted', 'editor_kc_status_merging', 'editor_kc_status_merged', 'editor_kc_status_renamed', 'editor_kc_status_already_related', 'editor_kc_status_drag_or_click',
    'editor_kc_status_load_failed', 'editor_kc_status_save_failed', 'editor_kc_status_create_failed', 'editor_kc_status_delete_failed', 'editor_kc_status_rename_failed', 'editor_kc_status_merge_failed', 'editor_kc_status_update_failed',
    'editor_kc_modal_title_new_relation', 'editor_kc_modal_title_edit_relation', 'editor_kc_label_authored_by', 'editor_kc_label_no_author_recorded', 'editor_kc_label_no_author_short',
    'editor_kc_err_empty_name', 'editor_kc_err_name_taken_galaxy', 'editor_kc_err_name_taken_conflict', 'editor_kc_err_missing_config',
    'editor_gxm_status_loading_keywords', 'editor_gxm_no_keywords_yet', 'editor_gxm_load_failed_keywords', 'editor_gxm_label_use_images_as_icons', 'editor_gxm_label_revert_to_theme_icons', 'editor_gxm_confirm_apply_to_all', 'editor_gxm_status_working', 'editor_gxm_status_updated_one', 'editor_gxm_status_updated_many', 'editor_gxm_label_failed_prefix', 'editor_gxm_err_update_failed_fallback',
    // C3: admin/index.php (visible chrome). Modals are C4. Static HTML + JS-rendered tables and toasts.
    'admin_loading_console', 'admin_heading_console', 'admin_label_welcome', 'admin_btn_edit_content', 'admin_btn_logout',
    'admin_msg_api_key_generated_title', 'admin_msg_api_key_generated_body', 'admin_msg_settings_saved',
    'admin_tab_galaxies', 'admin_tab_clusters', 'admin_tab_users', 'admin_tab_backup', 'admin_tab_snapshots', 'admin_tab_settings', 'admin_tab_api_keys', 'admin_tab_php_info',
    'admin_heading_users', 'admin_btn_new_user', 'admin_btn_bulk_import', 'admin_label_search', 'admin_placeholder_search_users', 'admin_msg_no_users',
    'admin_col_user_name', 'admin_col_user_email', 'admin_col_user_type', 'admin_col_user_created', 'admin_col_user_last_login', 'admin_col_user_last_updated', 'admin_col_actions',
    'admin_user_type_regular', 'admin_user_type_editor', 'admin_user_type_admin', 'admin_badge_you', 'admin_label_never',
    'admin_action_edit', 'admin_action_delete', 'admin_confirm_delete_user',
    'admin_heading_generate_api_key', 'admin_label_api_key_name', 'admin_placeholder_api_key_name', 'admin_help_api_key_name',
    'admin_label_api_key_description', 'admin_placeholder_api_key_description', 'admin_btn_generate_api_key', 'admin_btn_cancel',
    'admin_heading_api_keys', 'admin_btn_new_api_key', 'admin_msg_no_api_keys',
    'admin_badge_inactive', 'admin_action_deactivate', 'admin_action_activate', 'admin_confirm_delete_api_key',
    'admin_label_created', 'admin_label_last_used', 'admin_label_last_updated',
    'admin_heading_galaxies', 'admin_btn_new_galaxy', 'admin_placeholder_search_galaxies',
    'admin_help_galaxies_default', 'admin_help_galaxies_settings_link', 'admin_toast_url_copied',
    'admin_heading_clusters', 'admin_btn_new_cluster', 'admin_placeholder_search_clusters', 'admin_help_clusters',
    'admin_help_settings', 'admin_label_version',
    'admin_label_default_galaxy', 'admin_help_default_galaxy',
    'admin_label_pdf_max', 'admin_help_pdf_max', 'admin_btn_save_settings',
    'admin_heading_download_backup', 'admin_help_download_backup',
    'admin_label_galaxies', 'admin_label_all_galaxies', 'admin_label_selected_galaxies', 'admin_msg_loading_galaxies',
    'admin_btn_select_all', 'admin_btn_clear',
    'admin_label_users_always_all', 'admin_help_users_export',
    'admin_label_media_files', 'admin_label_media_embedded', 'admin_label_media_refs', 'admin_label_media_none',
    'admin_btn_download_backup',
    'admin_heading_restore_backup', 'admin_help_restore_backup', 'admin_btn_inspect_file',
    'admin_label_galaxies_in_file', 'admin_label_for_each_galaxy',
    'admin_label_overwrite_slug', 'admin_label_create_as_new',
    'admin_label_users_in_file', 'admin_label_restore_users',
    'admin_label_skip_existing', 'admin_label_update_existing', 'admin_label_overwrite_pw',
    'admin_label_restore_media', 'admin_btn_restore',
    'admin_help_snapshots',
    'admin_heading_create_snapshot', 'admin_placeholder_snapshot_note', 'admin_btn_create_snapshot', 'admin_msg_creating_snapshot',
    'admin_heading_snapshot_scheduler', 'admin_label_enable_daily', 'admin_label_hour_utc', 'admin_label_keep_days',
    'admin_btn_save', 'admin_btn_refresh_status',
    'admin_label_status', 'admin_label_last_snapshot', 'admin_label_last_checked',
    'admin_label_status_loading', 'admin_label_never_lower',
    'admin_label_recent_activity', 'admin_msg_no_activity',
    'admin_heading_available_snapshots', 'admin_msg_loading',
    'admin_heading_php_config', 'admin_heading_important_extensions', 'admin_heading_all_extensions',
    'admin_msg_no_galaxies',
    'admin_col_id', 'admin_col_galaxy_name', 'admin_col_slug', 'admin_col_tagline', 'admin_col_wormholes', 'admin_col_created', 'admin_col_last_updated',
    'admin_badge_default', 'admin_badge_imported', 'admin_title_tour_enabled',
    'admin_msg_error_loading_galaxies',
    'admin_action_view', 'admin_action_copy_url', 'admin_action_keyword_canvas', 'admin_action_duplicate', 'admin_action_refresh',
    'admin_confirm_delete_galaxy',
    'admin_msg_no_clusters_search', 'admin_msg_no_clusters',
    'admin_col_theme', 'admin_col_members',
    'admin_title_idle_spotlight', 'admin_title_galaxy_list', 'admin_badge_galaxy_list',
    'admin_confirm_delete_cluster', 'admin_msg_error_loading_clusters',
    'admin_label_no_prefix_chip', 'admin_label_wormhole_count', 'admin_label_default_inline',
    'admin_msg_no_galaxies_in_backup', 'admin_msg_file_selected',
    'admin_toast_choose_backup', 'admin_toast_inspect_first', 'admin_toast_inspect_failed', 'admin_toast_failed_prefix',
    'admin_toast_nothing_selected', 'admin_confirm_restore', 'admin_toast_restore_complete', 'admin_toast_restore_failed',
    'admin_label_backup_summary', 'admin_text_format_app_created', 'admin_text_summary_counts', 'admin_text_summary_users_media',
    'admin_text_no_admin_user_warn', 'admin_label_failures',
    'admin_heading_restore_complete', 'admin_text_galaxies_report', 'admin_text_users_report', 'admin_text_media_report',
    'admin_label_disabled', 'admin_label_active', 'admin_label_needs_attention',
    'admin_msg_cron_inactive', 'admin_msg_cron_not_installed', 'admin_msg_scheduler_unknown',
    'admin_msg_no_snapshots',
    'admin_col_snapshot_created', 'admin_col_size', 'admin_col_type', 'admin_col_creator', 'admin_col_note',
    'admin_label_file_missing', 'admin_label_creator_system',
    'admin_action_restore', 'admin_action_download',
    'admin_btn_creating', 'admin_msg_creating_elapsed',
    'admin_toast_snapshot_created', 'admin_toast_create_snapshot_failed',
    'admin_confirm_delete_snapshot', 'admin_toast_snapshot_deleted', 'admin_toast_delete_failed',
    'admin_prompt_restore_snapshot', 'admin_toast_confirm_phrase_mismatch',
    'admin_confirm_no_admin', 'admin_toast_restore_complete_logout', 'admin_toast_restore_complete_report',
    'admin_toast_failed_load_galaxies',
    'admin_toast_saved_cron_warning', 'admin_toast_schedule_saved', 'admin_toast_save_schedule_failed',
    // C4: admin/index.php (modals). Bulk Users Import, Create User, Edit User, Create Galaxy, Cluster create/edit, Duplicate Galaxy, Delete Confirmation.
    'admin_modal_heading_bulk_users',
    'admin_modal_bulk_users_imported_one', 'admin_modal_bulk_users_imported_many',
    'admin_modal_bulk_users_galaxies_created_one', 'admin_modal_bulk_users_galaxies_created_many',
    'admin_modal_bulk_users_skipped_exists_one', 'admin_modal_bulk_users_skipped_exists_many',
    'admin_modal_bulk_users_skipped_invalid_one', 'admin_modal_bulk_users_skipped_invalid_many',
    'admin_modal_bulk_users_mail_failed_one', 'admin_modal_bulk_users_mail_failed_many',
    'admin_modal_bulk_users_col_line', 'admin_modal_bulk_users_col_email', 'admin_modal_bulk_users_col_outcome', 'admin_modal_bulk_users_col_galaxy', 'admin_modal_bulk_users_col_note',
    'admin_modal_bulk_users_col_name', 'admin_modal_bulk_users_col_role', 'admin_modal_bulk_users_col_status',
    'admin_modal_btn_done', 'admin_modal_btn_confirm_import', 'admin_modal_btn_preview',
    'admin_modal_bulk_users_preview_intro', 'admin_modal_bulk_users_row_override',
    'admin_modal_bulk_users_form_intro',
    'admin_modal_bulk_users_field_email', 'admin_modal_bulk_users_field_first_name', 'admin_modal_bulk_users_field_last_name', 'admin_modal_bulk_users_field_type', 'admin_modal_bulk_users_field_create_galaxy',
    'admin_modal_bulk_users_example_label', 'admin_modal_bulk_users_footer_help',
    'admin_modal_bulk_users_textarea_placeholder',
    'admin_modal_bulk_users_label_create_galaxy_each', 'admin_modal_bulk_users_help_create_galaxy_each',
    'admin_modal_heading_create_user',
    'admin_modal_label_first_name', 'admin_modal_help_first_name', 'admin_modal_label_last_name', 'admin_modal_help_last_name',
    'admin_modal_label_email', 'admin_modal_err_email_in_use', 'admin_modal_help_email',
    'admin_modal_label_password', 'admin_modal_help_password_min',
    'admin_modal_label_user_type', 'admin_modal_opt_user_type_editor', 'admin_modal_opt_user_type_admin', 'admin_modal_help_user_type',
    'admin_modal_label_create_galaxy_for_user', 'admin_modal_help_create_galaxy_for_user',
    'admin_modal_label_new_galaxy_name', 'admin_modal_placeholder_new_galaxy_name', 'admin_modal_help_new_galaxy_name',
    'admin_modal_label_galaxy_access_editors', 'admin_modal_help_galaxy_access_editors',
    'admin_modal_btn_create_user',
    'admin_modal_heading_create_galaxy',
    'admin_modal_label_galaxy_name', 'admin_modal_placeholder_galaxy_name', 'admin_modal_err_name_in_use', 'admin_modal_help_galaxy_name',
    'admin_modal_label_url_slug', 'admin_modal_placeholder_url_slug', 'admin_modal_err_slug_in_use', 'admin_modal_help_url_slug',
    'admin_modal_label_tagline', 'admin_modal_placeholder_tagline', 'admin_modal_help_tagline',
    'admin_modal_label_visual_theme',
    'admin_modal_opt_theme_cosmic', 'admin_modal_opt_theme_simple', 'admin_modal_opt_theme_abstract', 'admin_modal_opt_theme_rectangles', 'admin_modal_opt_theme_stripes', 'admin_modal_opt_theme_tech',
    'admin_modal_help_visual_theme',
    'admin_modal_btn_create_galaxy',
    'admin_modal_heading_create_cluster', 'admin_modal_heading_edit_cluster', 'admin_modal_heading_duplicate_cluster',
    'admin_modal_placeholder_cluster_name', 'admin_modal_placeholder_cluster_slug', 'admin_modal_help_cluster_slug',
    'admin_modal_placeholder_cluster_tagline',
    'admin_modal_opt_cluster_theme_cosmic', 'admin_modal_opt_cluster_theme_abstract', 'admin_modal_opt_cluster_theme_rectangles', 'admin_modal_opt_cluster_theme_stripes', 'admin_modal_opt_cluster_theme_tech',
    'admin_modal_help_cluster_theme',
    'admin_modal_label_show_galaxy_list', 'admin_modal_help_show_galaxy_list',
    'admin_modal_label_member_galaxies', 'admin_modal_help_member_galaxies',
    'admin_modal_count_selected_one', 'admin_modal_count_selected_many',
    'admin_modal_label_keyword_chips', 'admin_modal_help_keyword_chips',
    'admin_modal_label_related_wormholes', 'admin_modal_help_related_wormholes',
    'admin_modal_label_2d_view', 'admin_modal_help_2d_view',
    'admin_modal_label_idle_spotlight', 'admin_modal_help_idle_spotlight',
    'admin_modal_label_pick_from', 'admin_modal_opt_pick_all_wormholes', 'admin_modal_opt_pick_accentuated',
    'admin_modal_label_trigger_after_seconds',
    'admin_modal_label_auto_tour', 'admin_modal_title_preview_tour', 'admin_modal_btn_preview_tour', 'admin_modal_help_auto_tour',
    'admin_modal_label_start_mode', 'admin_modal_opt_start_manual', 'admin_modal_opt_start_idle', 'admin_modal_opt_start_immediate',
    'admin_modal_label_idle_threshold', 'admin_modal_warn_immediate_audio',
    'admin_modal_label_which_wormholes',
    'admin_modal_opt_tour_all', 'admin_modal_opt_tour_accentuated', 'admin_modal_opt_tour_random_n', 'admin_modal_opt_tour_tagged',
    'admin_modal_label_random_count',
    'admin_modal_label_tour_keywords', 'admin_modal_placeholder_tour_keywords', 'admin_modal_help_tour_keywords',
    'admin_modal_label_dwell_seconds', 'admin_modal_label_loop_tour',
    'admin_modal_btn_create_cluster', 'admin_modal_btn_update_cluster',
    'admin_modal_name_copy_suffix',
    'admin_modal_heading_edit_user', 'admin_modal_label_password_optional', 'admin_modal_btn_update_user',
    'admin_modal_heading_duplicate_galaxy', 'admin_modal_label_duplicating',
    'admin_modal_label_new_name', 'admin_modal_label_new_url_slug', 'admin_modal_label_new_tagline',
    'admin_modal_btn_duplicate',
    'admin_modal_heading_confirm_deletion',
    'admin_modal_label_type_galaxy_name', 'admin_modal_placeholder_type_name',
    'admin_modal_btn_delete',
    'admin_modal_deletion_impact_title', 'admin_modal_deletion_impact_intro', 'admin_modal_deletion_impact_row',
];

/**
 * Locales supported (one row per locale in project_info). The default locale
 * is the first entry. To add a new locale (e.g. French), append its 2-letter
 * code here AND add a matching block in db_default_project_info_rows().
 */
const PROJECT_INFO_LOCALES = ['en', 'es', 'pt'];

/**
 * Pick the best supported locale from a request. Tries the explicit ?lang=
 * query parameter first, then the Accept-Language header. Returns 'en' (or
 * PROJECT_INFO_LOCALES[0] if that ever changes) when nothing supported is
 * found.
 */
function locale_resolve_from_request(mixed $queryLang, ?string $acceptLanguage): string {
    $default = PROJECT_INFO_LOCALES[0];
    if (is_string($queryLang) && $queryLang !== '') {
        $code = locale_normalize_code($queryLang);
        if ($code !== null && in_array($code, PROJECT_INFO_LOCALES, true)) {
            return $code;
        }
    }
    if ($acceptLanguage !== null && $acceptLanguage !== '') {
        foreach (array_map('trim', explode(',', $acceptLanguage)) as $part) {
            $bare = trim(explode(';', $part)[0]);
            $code = locale_normalize_code($bare);
            if ($code !== null && in_array($code, PROJECT_INFO_LOCALES, true)) {
                return $code;
            }
        }
    }
    return $default;
}

/**
 * Normalize a locale token to a 2-letter lowercase code. Strips region suffix
 * (pt-BR -> pt). Returns null on malformed input.
 */
function locale_normalize_code(string $raw): ?string {
    $raw = strtolower(trim($raw));
    if ($raw === '') return null;
    if (preg_match('/^([a-z]{2})(?:[-_][a-z0-9]+)?$/', $raw, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Resolve the current locale (from request) and cache the matching
 * project_info strings on the request. Subsequent calls return the cached
 * array. Surfaces that need translated strings (admin, editor, visitor)
 * call this once, then use t() to read individual keys.
 */
function locale_init_strings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $loc = locale_resolve_from_request($_GET['lang'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
    $cache = db_get_project_info_for_locale($loc);
    $cache['__locale'] = $loc;
    return $cache;
}

/**
 * Translate a single project_info key against the current locale, falling
 * back to the supplied English string if the key is missing or empty.
 * The fallback ensures pages still render correctly when a key has not
 * yet been added to project_info (e.g. between deploys).
 *
 * Returns a raw string. Wrap in htmlspecialchars() at the call site for
 * HTML output. Use t_attr() in HTML attributes.
 */
function t(string $key, string $fallback = ''): string {
    $strings = locale_init_strings();
    $val = $strings[$key] ?? '';
    return $val !== '' ? (string)$val : $fallback;
}

/**
 * Like t(), but escaped for HTML attribute / body use. Convenience for
 * the common case in PHP templates.
 */
function t_attr(string $key, string $fallback = ''): string {
    return htmlspecialchars(t($key, $fallback), ENT_QUOTES, 'UTF-8');
}

/**
 * Get the default constellation ID from project settings.
 */
/**
 * Ensure project_info.pdf_max_bytes exists. Global (stored on the 'en' row only).
 */
function db_ensure_pdf_max_bytes_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM project_info LIKE 'pdf_max_bytes'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE project_info ADD COLUMN pdf_max_bytes BIGINT UNSIGNED NULL DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_pdf_max_bytes_column: ' . $e->getMessage());
    }
}

/**
 * Effective PDF size cap in bytes. NULL/missing => fall back to MAX_PDF_BYTES_DEFAULT
 * from inc/validation.php (25MB). Inlined here so db.php doesn't have to require
 * validation.php at load time.
 */
function db_get_pdf_max_bytes(): int {
    $fallback = defined('MAX_PDF_BYTES_DEFAULT') ? MAX_PDF_BYTES_DEFAULT : (25 * 1024 * 1024);
    db_ensure_pdf_max_bytes_column();
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT pdf_max_bytes FROM project_info WHERE locale = 'en' LIMIT 1");
        $row = $stmt->fetch();
        $val = $row ? $row['pdf_max_bytes'] : null;
        if ($val === null || $val === '') return $fallback;
        $v = (int) $val;
        return $v > 0 ? $v : $fallback;
    } catch (PDOException $e) {
        error_log('db_get_pdf_max_bytes: ' . $e->getMessage());
        return $fallback;
    }
}

/**
 * Update the PDF size cap. Pass null/0 to revert to MAX_PDF_BYTES_DEFAULT.
 */
function db_set_pdf_max_bytes(?int $bytes): void {
    db_ensure_pdf_max_bytes_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE project_info SET pdf_max_bytes = :v WHERE locale = 'en'");
    $stmt->execute([':v' => $bytes !== null && $bytes > 0 ? $bytes : null]);
}

function db_get_default_constellation_id(): int {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT default_constellation_id FROM project_info WHERE locale = 'en' LIMIT 1");
        $row = $stmt->fetch();
        return $row ? (int)$row['default_constellation_id'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function db_has_project_table(): bool {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SHOW TABLES LIKE 'project_info'");
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Default values per locale for project_info (used when no data exists).
 */
function db_default_project_info_rows(string $enName = 'Telaris', string $enDescription = 'Weaving memory'): array {
    return [
        'en' => [
            'name' => $enName, 'description' => $enDescription, 'iframe_back_text' => 'Go back', 
            'alert_message' => "You are traversing to the Planar Dimension\nTo explore, zoom and scroll in all directions\nClose the browser window to return to the Cosmic Dimension.", 
            'edit_button_text' => 'Edit', 'loading_text' => 'Loading',
            'back_button_text' => 'Back', 'system_online_text' => 'Online',
            'reload_system_text' => 'Reload', 'scan_system_text' => 'SEARCH...',
            'clear_scan_text' => 'Clear Search', 'systems_label_text' => 'Wormholes:',
            'hyperlinks_label_text' => 'Hyperlinks:', 'initialize_auth_text' => 'Login',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Logout',
            'click_to_view_text' => 'Click to view', 'tap_to_view_text' => 'Tap again to view',
            'open_portal_text' => 'Open the Portal',
            'sound_label_text' => 'Sound:', 'sound_on_text' => 'ON', 'sound_off_text' => 'OFF',
            'launching_text' => 'Launching', 'mission_active_text' => 'Mission Active', 'go_text' => 'GO',
            'breadcrumb_all_text' => 'All', 'launch_button_text' => 'LAUNCH',
            'no_results_text' => 'No results', 'items_label_text' => 'items', 'other_label_text' => 'Other',
            'galaxies_label_text' => 'Galaxies',
            'galaxy_count_singular_text' => '1 galaxy',
            'galaxy_count_plural_text' => '%d galaxies',
            'pdf_loading_text' => 'Loading PDF…',
            'pdf_rendering_text' => 'Rendering pages…',
            'pdf_pages_singular_text' => '1 page',
            'pdf_pages_plural_text' => '%d pages',
            'pdf_open_text' => 'Open in new window',
            'pdf_download_text' => 'Download',
            'pdf_error_load_text' => 'PDF library failed to load.',
            'pdf_error_open_text' => "Couldn't open PDF.",
            'tour_label_text' => 'Tour',
            'tour_start_aria_text' => 'Start tour',
            'tour_previous_aria_text' => 'Previous',
            'tour_pause_aria_text' => 'Pause',
            'tour_next_aria_text' => 'Next',
            'tour_exit_aria_text' => 'Exit tour',
            'nav_toggle_aria_text' => 'Toggle navigation menu',
            'share_link_title_text' => 'Copy link to this wormhole',
            'related_label_text' => 'Related',
            'lang_label_text' => 'Lang:',
            'node_name_fallback_text' => 'System',
            'untitled_text' => 'Untitled',
            'chip_open_prefix_text' => 'Open',
            'search_result_text' => 'Search result',
            'search_results_text' => 'Search results',
            // Editor chunk C1 (edit/index.php)
            'editor_page_title' => 'Edit Wormholes',
            'editor_user_role_admin' => 'Admin',
            'editor_user_role_editor' => 'Editor',
            'editor_label_current_galaxy' => 'Current Galaxy:',
            'editor_option_all_galaxies_admin' => 'All galaxies',
            'editor_option_all_galaxies_editor' => 'All my galaxies',
            'editor_btn_view' => 'View',
            'editor_btn_galaxy_settings_title' => 'Galaxy settings',
            'editor_btn_settings' => 'Settings',
            'editor_btn_keyword_canvas_title' => 'Author keyword relationships',
            'editor_btn_canvas' => 'Canvas',
            'editor_btn_copy_url_title' => 'Copy galaxy URL',
            'editor_btn_admin_console' => 'Admin Console',
            'editor_btn_logout' => 'Logout',
            'editor_error_no_api_key' => '⚠️ Error: No active API key found. Please contact an administrator.',
            'editor_bulk_selected_suffix' => 'wormholes selected',
            'editor_btn_clear_selection' => 'Clear Selection',
            'editor_btn_bulk_move' => 'Move Selected',
            'editor_btn_bulk_duplicate' => 'Duplicate Selected',
            'editor_btn_bulk_delete' => 'Delete Selected',
            'editor_banner_imported_read_only' => 'This galaxy was imported from an external source and is read-only. Use the Refresh action in the admin galaxy list to sync changes.',
            'editor_heading_wormholes' => 'Wormholes',
            'editor_btn_new_wormhole' => 'New Wormhole',
            'editor_btn_touched_today_title' => 'Show only wormholes touched today',
            'editor_btn_touched_today' => 'Touched today',
            'editor_btn_bulk_keyword_title' => 'Bulk delete or move every wormhole in this galaxy carrying a chosen keyword',
            'editor_btn_bulk_by_keyword' => 'Bulk by keyword…',
            'editor_btn_shortcuts_title' => 'Keyboard shortcuts (? to open)',
            'editor_label_search' => 'Search:',
            'editor_placeholder_search_wormholes' => 'Search wormholes...',
            'editor_col_name' => 'Name',
            'editor_col_type' => 'Type',
            'editor_col_galaxy' => 'Galaxy',
            'editor_col_url' => 'URL',
            'editor_col_keywords' => 'Keywords',
            'editor_col_created' => 'Created',
            'editor_col_updated' => 'Updated',
            'editor_col_actions' => 'Actions',
            'editor_col_acc' => 'Acc',
            'editor_col_acc_title' => 'Accentuated Status',
            'editor_msg_loading_wormholes' => 'Loading wormholes...',
            'editor_msg_retrieving_wormholes' => 'Retrieving wormholes...',
            'editor_heading_no_wormholes' => 'No wormholes found.',
            'editor_text_empty_state_help' => 'Try adjusting your search or add a new wormhole to get started.',
            'editor_heading_error_loading' => 'Error loading wormholes',
            'editor_error_api_key_missing' => 'API key is missing.',
            'editor_error_api_key_missing_fetch' => 'Error: API key is missing. Please contact an administrator.',
            'editor_error_invalid_json' => 'Invalid JSON response from server',
            'editor_error_invalid_format' => 'Invalid response format',
            'editor_error_invalid_data_format' => 'Error: Invalid data format received.',
            'editor_text_no_keywords' => 'No keywords',
            'editor_label_node_type_portal' => 'Portal',
            'editor_label_node_type_object' => 'Object',
            'editor_badge_accentuated' => 'ACC',
            'editor_badge_accentuated_title' => 'Accentuated Wormhole',
            'editor_badge_has_url' => 'URL',
            'editor_badge_has_url_title' => 'Has URL',
            'editor_badge_has_desc' => 'DESC',
            'editor_badge_has_desc_title' => 'Has Description',
            'editor_badge_has_img' => 'IMG',
            'editor_badge_has_img_title' => 'Has Image',
            'editor_badge_has_emb' => 'EMB',
            'editor_badge_has_emb_title' => 'Has Embed',
            'editor_badge_has_aud' => 'AUD',
            'editor_badge_has_aud_title' => 'Has Audio',
            'editor_badge_has_vid' => 'VID',
            'editor_badge_has_vid_title' => 'Has Video',
            'editor_title_accentuated' => 'Accentuated',
            'editor_action_view_wormhole' => 'View Wormhole',
            'editor_action_view_galaxy' => 'View Galaxy',
            'editor_action_edit' => 'Edit',
            'editor_action_duplicate' => 'Duplicate',
            'editor_action_delete' => 'Delete',
            'editor_toast_bulk_move_success' => 'Successfully moved %d wormholes.',
            'editor_toast_bulk_move_failed' => 'Failed to move %d wormholes.',
            'editor_toast_bulk_move_error' => 'An error occurred during bulk move.',
            'editor_toast_duplicate_success' => 'Wormhole duplicated successfully.',
            'editor_error_failed_duplicate' => 'Failed to duplicate',
            'editor_toast_duplicate_error_generic' => 'An error occurred while duplicating.',
            'editor_toast_bulk_duplicate_success' => 'Successfully duplicated %d wormholes.',
            'editor_toast_bulk_duplicate_failed' => 'Failed to duplicate %d wormholes.',
            'editor_toast_bulk_duplicate_error' => 'An error occurred during bulk duplicate.',
            'editor_confirm_bulk_delete' => 'Are you sure you want to delete %d selected wormholes? This action cannot be undone.',
            'editor_toast_bulk_delete_success' => 'Successfully deleted %d wormholes.',
            'editor_toast_bulk_delete_failed' => 'Failed to delete %d wormholes.',
            'editor_toast_bulk_delete_error' => 'An error occurred during bulk deletion.',
            'editor_toast_url_copied' => 'URL copied to clipboard',
            'editor_title_url_copied' => 'Copied!',
            'editor_toast_galaxy_created' => 'Galaxy "%s" created.',
            'editor_toast_error_creating_galaxy' => 'Error creating galaxy: %s',
            'editor_prompt_new_galaxy_name' => 'Name of the new galaxy:',
            'editor_modal_heading_add_wormhole' => 'Add New Wormhole',
            'editor_modal_heading_edit_wormhole' => 'Edit Wormhole',
            'editor_label_name_required' => 'Name *',
            'editor_error_name_exists' => 'This wormhole name already exists in this galaxy.',
            'editor_help_name' => 'Primary title of the wormhole shown in the network.',
            'editor_label_galaxy' => 'Galaxy',
            'editor_help_constellation' => 'Which galaxy this wormhole belongs to.',
            'editor_label_wormhole_type' => 'Wormhole type',
            'editor_help_node_type' => 'Object is a standard item; Portal links to another galaxy.',
            'editor_label_keywords' => 'Keywords',
            'editor_placeholder_add_keyword' => 'Add keyword...',
            'editor_help_keywords_add' => 'Type and press Enter or comma to add keywords. Suggestions surface keywords already used in this galaxy and in sibling galaxies sharing your `[XX]` prefix.',
            'editor_label_accentuate_wormhole' => 'Accentuate Wormhole',
            'editor_help_accentuate' => 'Make this wormhole larger and more prominent in the network.',
            'editor_label_show_keywords' => 'Show Keywords',
            'editor_help_show_keywords' => "Display this wormhole's keywords in its info window.",
            'editor_label_target_galaxy' => 'Target Galaxy',
            'editor_help_target_galaxy' => 'The destination galaxy this portal leads to.',
            'editor_btn_create_new_galaxy' => 'Create New Galaxy',
            'editor_label_description' => 'Description',
            'editor_help_description' => 'Detailed text displayed when the wormhole is selected.',
            'editor_label_url' => 'URL',
            'editor_placeholder_url' => 'https://example.com',
            'editor_help_url' => 'URL to open when the wormhole is clicked (optional).',
            'editor_label_primary_visual' => 'Primary visual',
            'editor_tab_image' => 'Image',
            'editor_tab_video' => 'Video (MP4)',
            'editor_tab_pdf' => 'PDF',
            'editor_help_visual_mutex' => 'Pick one. Switching tabs and saving clears the others.',
            'editor_label_image_url_file' => 'Image URL / File',
            'editor_label_use_as_icon' => 'Use as wormhole icon',
            'editor_placeholder_image_url' => 'https://example.com/image.jpg',
            'editor_placeholder_video_url' => 'https://example.com/video.mp4',
            'editor_label_autoplay_video' => 'Autoplay video',
            'editor_placeholder_pdf_url' => 'https://example.com/document.pdf',
            'editor_help_pdf' => 'Upload a PDF or provide a link.',
            'editor_placeholder_credit' => 'Credit / attribution...',
            'editor_help_credit' => 'Optional credit shown on the visual in the info box (image, video, or PDF).',
            'editor_label_icon_url_file' => 'Icon URL / File',
            'editor_placeholder_icon_url' => 'https://example.com/icon.png',
            'editor_help_icon' => 'Custom icon displayed in the 3D scene (overrides theme icon).',
            'editor_label_audio_url_file' => 'Audio URL / File',
            'editor_placeholder_audio_url' => 'https://example.com/audio.mp3',
            'editor_label_autoplay' => 'Autoplay',
            'editor_label_loop' => 'Loop',
            'editor_help_audio' => 'Independent of the primary visual: audio can pair with image, video, or PDF.',
            'editor_text_uploading' => 'Uploading...',
            'editor_btn_add_wormhole' => 'Add Wormhole',
            'editor_btn_cancel' => 'Cancel',
            'editor_divider_media' => 'Media',
            'editor_btn_delete_file' => 'Delete',
            'editor_btn_update_wormhole' => 'Update Wormhole',
            'editor_modal_heading_confirm_delete' => 'Confirm Deletion',
            'editor_btn_delete' => 'Delete',
            'editor_modal_heading_move_wormholes' => 'Move Wormholes',
            'editor_text_move_count_wormholes' => 'Move %d selected wormholes to another galaxy.',
            'editor_label_destination_galaxy' => 'Destination Galaxy',
            'editor_btn_move_wormholes' => 'Move Wormholes',
            'editor_modal_heading_duplicate_wormhole' => 'Duplicate Wormhole',
            'editor_text_duplicate_to' => 'Duplicate "%s" to:',
            'editor_btn_duplicate' => 'Duplicate',
            'editor_modal_heading_duplicate_wormholes' => 'Duplicate Wormholes',
            'editor_text_duplicate_count_wormholes' => 'Duplicate %d selected wormholes to:',
            'editor_btn_duplicate_wormholes' => 'Duplicate Wormholes',
            'editor_btn_open_link' => 'Open Link',
            'editor_btn_apply' => 'Apply',
            'editor_label_target_prefix' => 'Target:',
            'editor_modal_heading_bulk_keyword' => 'Bulk action by keyword',
            'editor_text_bulk_keyword_help' => 'Pick a keyword in the current galaxy. Then choose to delete every wormhole carrying it, or move them all to another galaxy.',
            'editor_label_keyword' => 'Keyword',
            'editor_option_loading' => 'Loading…',
            'editor_label_action' => 'Action',
            'editor_option_delete_matching' => 'Delete the matching wormholes',
            'editor_option_move_matching' => 'Move them to another galaxy',
            'editor_text_pick_keyword' => 'Pick a keyword to see the count.',
            'editor_error_pick_specific_galaxy' => 'Pick a specific galaxy first (not "All galaxies").',
            'editor_option_no_keywords' => '(no keywords in this galaxy)',
            'editor_option_pick_one' => 'pick one',
            'editor_option_error_keywords' => 'Error loading keywords',
            'editor_option_pick_galaxy' => 'pick a galaxy',
            'editor_preview_move_one' => 'Will move 1 wormhole to the chosen galaxy.',
            'editor_preview_move_many' => 'Will move %d wormholes to the chosen galaxy.',
            'editor_preview_move_pick_target_one' => 'Will move 1 wormhole. Pick a target galaxy first.',
            'editor_preview_move_pick_target_many' => 'Will move %d wormholes. Pick a target galaxy first.',
            'editor_preview_delete_one' => 'Will permanently delete 1 wormhole.',
            'editor_preview_delete_many' => 'Will permanently delete %d wormholes.',
            'editor_confirm_bulk_delete_keyword_one' => 'Permanently delete 1 wormhole carrying "%s"? This cannot be undone.',
            'editor_confirm_bulk_delete_keyword_many' => 'Permanently delete %d wormholes carrying "%s"? This cannot be undone.',
            'editor_confirm_bulk_move_keyword_one' => 'Move 1 wormhole carrying "%s" to the selected galaxy?',
            'editor_confirm_bulk_move_keyword_many' => 'Move %d wormholes carrying "%s" to the selected galaxy?',
            'editor_toast_bulk_deleted_one' => 'Deleted 1 wormhole.',
            'editor_toast_bulk_deleted_many' => 'Deleted %d wormholes.',
            'editor_toast_bulk_moved_one' => 'Moved 1 wormhole.',
            'editor_toast_bulk_moved_many' => 'Moved %d wormholes.',
            'editor_toast_bulk_action_failed' => 'Bulk action failed: %s',
            'editor_modal_heading_shortcuts' => 'Keyboard shortcuts',
            'editor_shortcut_new_wormhole' => 'New wormhole',
            'editor_shortcut_focus_search' => 'Focus the search box',
            'editor_shortcut_toggle_touched' => 'Toggle "Touched today" filter',
            'editor_shortcut_galaxy_settings' => 'Open galaxy settings (current galaxy)',
            'editor_shortcut_close_modal' => 'Close any open modal',
            'editor_shortcut_open_help' => 'Open this help',
            'editor_note_shortcuts_typing' => 'Shortcuts are ignored while typing in a text field.',
            'editor_btn_close' => 'Close',
            'editor_toast_updated_successfully' => 'Wormhole updated successfully',
            'editor_toast_created_successfully' => 'Wormhole created successfully',
            'editor_error_failed_update' => 'Failed to update wormhole',
            'editor_error_failed_create' => 'Failed to create wormhole',
            'editor_error_network_upload' => 'Network error occurred during upload',
            'editor_error_name_required' => 'Wormhole name is required',
            'editor_error_loading_node' => 'Error loading wormhole: %s',
            'editor_confirm_delete_file' => 'Are you sure you want to delete this uploaded %s file?',
            'editor_toast_file_deleted' => '%s file deleted',
            'editor_error_deleting_file' => 'Error deleting file: %s',
            'editor_confirm_delete_node' => 'Are you sure you want to delete "%s"? This action cannot be undone.',
            'editor_error_delete_wormhole' => 'Failed to delete wormhole',
            'editor_toast_deleted_successfully' => 'Wormhole deleted successfully',
            'editor_error_deleting_wormhole' => 'Error deleting wormhole: %s',
            'editor_error_fatal_loading' => 'Fatal error loading wormholes: %s',
            'editor_error_could_not_load' => 'Error: Could not load wormholes. %s',
            'editor_kc_status_loading' => 'Loading…',
            'editor_kc_status_no_keywords' => 'No keywords yet',
            'editor_kc_status_ready' => 'Ready',
            'editor_kc_status_saving' => 'Saving…',
            'editor_kc_status_saved' => 'Saved',
            'editor_kc_status_deleting' => 'Deleting…',
            'editor_kc_status_deleted' => 'Deleted',
            'editor_kc_status_merging' => 'Merging…',
            'editor_kc_status_merged' => 'Merged',
            'editor_kc_status_renamed' => 'Renamed',
            'editor_kc_status_already_related' => 'Already related',
            'editor_kc_status_drag_or_click' => 'Drag to another anchor, or click one (Esc to cancel)',
            'editor_kc_status_load_failed' => 'Load failed: %s',
            'editor_kc_status_save_failed' => 'Save failed: %s',
            'editor_kc_status_create_failed' => 'Create failed: %s',
            'editor_kc_status_delete_failed' => 'Delete failed: %s',
            'editor_kc_status_rename_failed' => 'Rename failed: %s',
            'editor_kc_status_merge_failed' => 'Merge failed: %s',
            'editor_kc_status_update_failed' => 'Update failed: %s',
            'editor_kc_modal_title_new_relation' => 'New relation',
            'editor_kc_modal_title_edit_relation' => 'Edit relation note',
            'editor_kc_label_authored_by' => 'Authored by %s',
            'editor_kc_label_no_author_recorded' => 'No author recorded',
            'editor_kc_label_no_author_short' => '(no author)',
            'editor_kc_err_empty_name' => 'Pick a non-empty name.',
            'editor_kc_err_name_taken_galaxy' => 'That name is already taken in this galaxy',
            'editor_kc_err_name_taken_conflict' => 'That name is already taken; change it or merge.',
            'editor_kc_err_missing_config' => 'Page configuration is missing (window.TELARIS_KC)',
            'editor_gxm_status_loading_keywords' => 'Loading…',
            'editor_gxm_no_keywords_yet' => 'No keywords yet for this galaxy.',
            'editor_gxm_load_failed_keywords' => 'Failed to load.',
            'editor_gxm_label_use_images_as_icons' => 'use images as icons',
            'editor_gxm_label_revert_to_theme_icons' => 'revert all to theme icons',
            'editor_gxm_confirm_apply_to_all' => 'Apply "%s" to every wormhole in this galaxy?',
            'editor_gxm_status_working' => 'Working…',
            'editor_gxm_status_updated_one' => 'Updated %d wormhole. Reload the visitor view to see the change.',
            'editor_gxm_status_updated_many' => 'Updated %d wormholes. Reload the visitor view to see the change.',
            'editor_gxm_label_failed_prefix' => 'Failed: %s',
            'editor_gxm_err_update_failed_fallback' => 'Update failed',
            // C3: admin/index.php
            'admin_loading_console' => 'Loading Admin Console...',
            'admin_heading_console' => 'Admin Console',
            'admin_label_welcome' => 'Welcome, %s',
            'admin_btn_edit_content' => 'Edit Content',
            'admin_btn_logout' => 'Logout',
            'admin_msg_api_key_generated_title' => '✓ API Key Generated',
            'admin_msg_api_key_generated_body' => 'Your API Key: %s (Name: %s). PLEASE COPY IT NOW.',
            'admin_msg_settings_saved' => 'Global settings saved.',
            'admin_tab_galaxies' => 'Galaxies',
            'admin_tab_clusters' => 'Clusters',
            'admin_tab_users' => 'Users',
            'admin_tab_backup' => 'Backup',
            'admin_tab_snapshots' => 'Snapshots',
            'admin_tab_settings' => 'Global Settings',
            'admin_tab_api_keys' => 'API Keys',
            'admin_tab_php_info' => 'PHP Information',
            'admin_heading_users' => 'Users',
            'admin_btn_new_user' => 'New User',
            'admin_btn_bulk_import' => 'Bulk import',
            'admin_label_search' => 'Search:',
            'admin_placeholder_search_users' => 'Search users...',
            'admin_msg_no_users' => 'No users found.',
            'admin_col_user_name' => 'Name',
            'admin_col_user_email' => 'Email',
            'admin_col_user_type' => 'Type',
            'admin_col_user_created' => 'Created',
            'admin_col_user_last_login' => 'Last Login',
            'admin_col_user_last_updated' => 'Last Updated',
            'admin_col_actions' => 'Actions',
            'admin_user_type_regular' => 'Regular',
            'admin_user_type_editor' => 'Editor',
            'admin_user_type_admin' => 'Admin',
            'admin_badge_you' => 'You',
            'admin_label_never' => 'Never',
            'admin_action_edit' => 'Edit',
            'admin_action_delete' => 'Delete',
            'admin_confirm_delete_user' => 'Are you sure you want to delete the user "%s"? This action cannot be undone.',
            'admin_heading_generate_api_key' => 'Generate New API Key',
            'admin_label_api_key_name' => 'Name *',
            'admin_placeholder_api_key_name' => 'e.g., Frontend App, Mobile App, Admin',
            'admin_help_api_key_name' => 'A descriptive name for this API key',
            'admin_label_api_key_description' => 'Description',
            'admin_placeholder_api_key_description' => 'Optional description of what this key is used for',
            'admin_btn_generate_api_key' => 'Generate API Key',
            'admin_btn_cancel' => 'Cancel',
            'admin_heading_api_keys' => 'API Keys',
            'admin_btn_new_api_key' => 'New API Key',
            'admin_msg_no_api_keys' => 'No API keys have been generated yet.',
            'admin_badge_inactive' => 'Inactive',
            'admin_action_deactivate' => 'Deactivate',
            'admin_action_activate' => 'Activate',
            'admin_confirm_delete_api_key' => 'Are you sure you want to delete this API key? This action cannot be undone.',
            'admin_label_created' => 'Created:',
            'admin_label_last_used' => 'Last Used:',
            'admin_label_last_updated' => 'Last Updated:',
            'admin_heading_galaxies' => 'Galaxies',
            'admin_btn_new_galaxy' => 'New Galaxy',
            'admin_placeholder_search_galaxies' => 'Search galaxies...',
            'admin_help_galaxies_default' => 'Each galaxy is a separate set of wormholes and keywords. The current default galaxy, %s, cannot be deleted.',
            'admin_help_galaxies_settings_link' => 'You can change the default galaxy in the %s tab.',
            'admin_toast_url_copied' => 'URL copied to clipboard.',
            'admin_heading_clusters' => 'Galaxy Clusters',
            'admin_btn_new_cluster' => 'New Cluster',
            'admin_placeholder_search_clusters' => 'Search clusters...',
            'admin_help_clusters' => 'A cluster is a curated union of galaxies with its own slug, title, theme, and permalink. Clusters have no native wormholes; they render the union of their members via the multigalaxy pipeline.',
            'admin_help_settings' => 'Instance-wide settings for the main app.',
            'admin_label_version' => 'Version',
            'admin_label_default_galaxy' => 'Default Galaxy',
            'admin_help_default_galaxy' => 'Choose which galaxy is shown at the root of the website.',
            'admin_label_pdf_max' => 'PDF max size (MB)',
            'admin_help_pdf_max' => "Largest PDF a wormhole can carry. Default 25 MB. Editors uploading bigger files will get a 'File exceeds maximum allowed size' error.",
            'admin_btn_save_settings' => 'Save settings',
            'admin_heading_download_backup' => 'Download a backup',
            'admin_help_download_backup' => 'Create a portable backup file containing galaxies and/or users. The default produces a full backup with embedded media.',
            'admin_label_galaxies' => 'Galaxies',
            'admin_label_all_galaxies' => 'All galaxies',
            'admin_label_selected_galaxies' => 'Selected galaxies only',
            'admin_msg_loading_galaxies' => 'Loading galaxies...',
            'admin_btn_select_all' => 'Select all',
            'admin_btn_clear' => 'Clear',
            'admin_label_users_always_all' => 'Users (always all)',
            'admin_help_users_export' => 'User passwords are exported as hashes. They never appear in plaintext.',
            'admin_label_media_files' => 'Media files',
            'admin_label_media_embedded' => 'Embedded: self-contained backup (recommended)',
            'admin_label_media_refs' => 'References only: smaller file, only restorable on the same server',
            'admin_label_media_none' => 'None: strip all media',
            'admin_btn_download_backup' => 'Download backup',
            'admin_heading_restore_backup' => 'Restore from a backup',
            'admin_help_restore_backup' => 'Upload a .telaris-backup file. You will see a summary before anything is changed.',
            'admin_btn_inspect_file' => 'Inspect file',
            'admin_label_galaxies_in_file' => 'Galaxies in this file',
            'admin_label_for_each_galaxy' => 'For each selected galaxy',
            'admin_label_overwrite_slug' => 'Overwrite if a galaxy with the same slug exists',
            'admin_label_create_as_new' => 'Create as new (rename on conflict, suffix:',
            'admin_label_users_in_file' => 'Users in this file',
            'admin_label_restore_users' => 'Restore users',
            'admin_label_skip_existing' => 'Skip existing users (match by email)',
            'admin_label_update_existing' => 'Update existing users by email',
            'admin_label_overwrite_pw' => 'Also overwrite password hashes',
            'admin_label_restore_media' => 'Restore media files',
            'admin_btn_restore' => 'Restore',
            'admin_help_snapshots' => "Snapshots are local, on-disk full backups of the entire system. Restoring a snapshot wipes everything and replaces it with the snapshot's state. Any snapshots created after the restored one are deleted.",
            'admin_heading_create_snapshot' => 'Create snapshot now',
            'admin_placeholder_snapshot_note' => 'Optional note (e.g. before migration)',
            'admin_btn_create_snapshot' => 'Create snapshot',
            'admin_msg_creating_snapshot' => 'Creating snapshot. This may take a minute for large instances. Please do not close this tab.',
            'admin_heading_snapshot_scheduler' => 'Snapshot scheduler',
            'admin_label_enable_daily' => 'Enable daily snapshots',
            'admin_label_hour_utc' => 'Hour (UTC)',
            'admin_label_keep_days' => 'Keep days (auto)',
            'admin_btn_save' => 'Save',
            'admin_btn_refresh_status' => 'Refresh status',
            'admin_label_status' => 'Status:',
            'admin_label_last_snapshot' => 'Last snapshot:',
            'admin_label_last_checked' => 'Last checked:',
            'admin_label_status_loading' => 'loading...',
            'admin_label_never_lower' => 'never',
            'admin_label_recent_activity' => 'Recent activity',
            'admin_msg_no_activity' => '(no activity yet)',
            'admin_heading_available_snapshots' => 'Available snapshots',
            'admin_msg_loading' => 'Loading...',
            'admin_heading_php_config' => 'PHP Configuration',
            'admin_heading_important_extensions' => 'Important Extensions',
            'admin_heading_all_extensions' => 'All Loaded Extensions',
            'admin_msg_no_galaxies' => 'No galaxies found.',
            'admin_col_id' => 'ID',
            'admin_col_galaxy_name' => 'Name',
            'admin_col_slug' => 'Slug',
            'admin_col_tagline' => 'Tagline',
            'admin_col_wormholes' => 'Wormholes',
            'admin_col_created' => 'Created',
            'admin_col_last_updated' => 'Last Updated',
            'admin_badge_default' => 'Default',
            'admin_badge_imported' => 'Imported',
            'admin_title_tour_enabled' => 'Auto-tour enabled',
            'admin_msg_error_loading_galaxies' => 'Error loading galaxies: %s',
            'admin_action_view' => 'View',
            'admin_action_copy_url' => 'Copy URL',
            'admin_action_keyword_canvas' => 'Keyword canvas',
            'admin_action_duplicate' => 'Duplicate',
            'admin_action_refresh' => 'Refresh',
            'admin_confirm_delete_galaxy' => 'Are you sure you want to delete the galaxy "%s"? This will permanently remove ALL wormholes and keywords inside it.',
            'admin_msg_no_clusters_search' => 'No clusters match this search.',
            'admin_msg_no_clusters' => 'No clusters yet.',
            'admin_col_theme' => 'Theme',
            'admin_col_members' => 'Members',
            'admin_title_idle_spotlight' => 'Idle spotlight enabled',
            'admin_title_galaxy_list' => 'Galaxy list shown to visitors',
            'admin_badge_galaxy_list' => 'Galaxy list',
            'admin_confirm_delete_cluster' => 'Delete cluster "%s"? Members (the galaxies inside) are unaffected; only the cluster itself is removed.',
            'admin_msg_error_loading_clusters' => 'Error loading clusters: %s',
            'admin_label_no_prefix_chip' => 'No prefix (%d)',
            'admin_label_wormhole_count' => '%d wormholes',
            'admin_label_default_inline' => '(default)',
            'admin_msg_no_galaxies_in_backup' => 'No galaxies in this backup.',
            'admin_msg_file_selected' => 'Selected: %s (%s)',
            'admin_toast_choose_backup' => 'Choose a backup file first.',
            'admin_toast_inspect_first' => 'Inspect a file first.',
            'admin_toast_inspect_failed' => 'Inspect failed: %s',
            'admin_toast_failed_prefix' => 'Failed: %s',
            'admin_toast_nothing_selected' => 'Nothing selected to restore.',
            'admin_confirm_restore' => "Restore %s into this system?\n\nConflict mode: %s\n\nThis cannot be undone.",
            'admin_toast_restore_complete' => 'Restore complete.',
            'admin_toast_restore_failed' => 'Restore failed: %s',
            'admin_label_backup_summary' => 'Backup file summary',
            'admin_text_format_app_created' => 'Format v%s · App %s · Created %s',
            'admin_text_summary_counts' => 'Galaxies: %s · Wormholes: %s · Keywords: %s',
            'admin_text_summary_users_media' => 'Users: %s%s · Media: %s files (%s MB)',
            'admin_text_no_admin_user_warn' => '(no admin user!)',
            'admin_label_failures' => 'Failures:',
            'admin_heading_restore_complete' => 'Restore complete',
            'admin_text_galaxies_report' => 'Galaxies: created %s, overwritten %s, renamed %s, skipped %s',
            'admin_text_users_report' => 'Users: created %s, updated %s, skipped %s',
            'admin_text_media_report' => 'Media files: written %s, skipped %s',
            'admin_label_disabled' => 'Disabled',
            'admin_label_active' => 'Active',
            'admin_label_needs_attention' => 'Needs attention',
            'admin_msg_cron_inactive' => "The system's cron service is not running (%s). Scheduled snapshots will not be taken until cron is started.",
            'admin_msg_cron_not_installed' => 'Unable to register the scheduler with cron. Try saving again.',
            'admin_msg_scheduler_unknown' => 'Scheduler status unknown.',
            'admin_msg_no_snapshots' => 'No snapshots yet. Create one above.',
            'admin_col_snapshot_created' => 'Created (UTC)',
            'admin_col_size' => 'Size',
            'admin_col_type' => 'Type',
            'admin_col_creator' => 'Creator',
            'admin_col_note' => 'Note',
            'admin_label_file_missing' => '(file missing)',
            'admin_label_creator_system' => 'system',
            'admin_action_restore' => 'Restore',
            'admin_action_download' => 'Download',
            'admin_btn_creating' => 'Creating...',
            'admin_msg_creating_elapsed' => 'Creating snapshot. Elapsed: %ss. This may take a minute for large instances. Please do not close this tab.',
            'admin_toast_snapshot_created' => 'Snapshot created in %ss.',
            'admin_toast_create_snapshot_failed' => 'Create snapshot failed: %s',
            'admin_confirm_delete_snapshot' => 'Delete this snapshot? The file will be permanently removed from disk.',
            'admin_toast_snapshot_deleted' => 'Snapshot deleted.',
            'admin_toast_delete_failed' => 'Delete failed: %s',
            'admin_prompt_restore_snapshot' => "RESTORE will WIPE the entire system and replace it with the snapshot from %s.\n\nAll snapshots created after that point will also be deleted.\n\nType RESTORE to confirm:",
            'admin_toast_confirm_phrase_mismatch' => 'Confirmation phrase did not match. Restore cancelled.',
            'admin_confirm_no_admin' => 'WARNING: this snapshot has no admin user. Restoring will lock everyone out of the admin console. Proceed anyway?',
            'admin_toast_restore_complete_logout' => 'Restore complete. You may be logged out.',
            'admin_toast_restore_complete_report' => 'Restore complete. Created %s galaxies, %s users. %s later snapshot(s) deleted. You may be logged out.',
            'admin_toast_failed_load_galaxies' => 'Failed to load galaxies: %s',
            'admin_toast_saved_cron_warning' => 'Saved, but scheduler could not register with cron: %s',
            'admin_toast_schedule_saved' => 'Schedule saved.',
            'admin_toast_save_schedule_failed' => 'Save schedule failed: %s',
            // C4: admin/index.php (modals)
            'admin_modal_heading_bulk_users' => 'Bulk import users',
            'admin_modal_bulk_users_imported_one' => 'Imported <strong>%d</strong> user.',
            'admin_modal_bulk_users_imported_many' => 'Imported <strong>%d</strong> users.',
            'admin_modal_bulk_users_galaxies_created_one' => ' Created <strong>%d</strong> galaxy.',
            'admin_modal_bulk_users_galaxies_created_many' => ' Created <strong>%d</strong> galaxies.',
            'admin_modal_bulk_users_skipped_exists_one' => ' Skipped <strong>%d</strong> already-existing email.',
            'admin_modal_bulk_users_skipped_exists_many' => ' Skipped <strong>%d</strong> already-existing emails.',
            'admin_modal_bulk_users_skipped_invalid_one' => ' Skipped <strong>%d</strong> invalid row.',
            'admin_modal_bulk_users_skipped_invalid_many' => ' Skipped <strong>%d</strong> invalid rows.',
            'admin_modal_bulk_users_mail_failed_one' => ' <strong>%d</strong> setup email failed to send.',
            'admin_modal_bulk_users_mail_failed_many' => ' <strong>%d</strong> setup emails failed to send.',
            'admin_modal_bulk_users_col_line' => 'Line',
            'admin_modal_bulk_users_col_email' => 'Email',
            'admin_modal_bulk_users_col_outcome' => 'Outcome',
            'admin_modal_bulk_users_col_galaxy' => 'Galaxy',
            'admin_modal_bulk_users_col_note' => 'Note',
            'admin_modal_bulk_users_col_name' => 'Name',
            'admin_modal_bulk_users_col_role' => 'Role',
            'admin_modal_bulk_users_col_status' => 'Status',
            'admin_modal_btn_done' => 'Done',
            'admin_modal_btn_confirm_import' => 'Confirm import',
            'admin_modal_btn_preview' => 'Preview',
            'admin_modal_bulk_users_preview_intro' => 'Review the parsed list. Click <strong>Confirm import</strong> to create the new accounts and email each one a one-time setup link.',
            'admin_modal_bulk_users_row_override' => '(row override)',
            'admin_modal_bulk_users_form_intro' => 'Paste a list of users, one per line, columns comma-separated. Only the email is required; everything else is optional.',
            'admin_modal_bulk_users_field_email' => '<strong>email</strong>: required',
            'admin_modal_bulk_users_field_first_name' => '<strong>first name</strong>',
            'admin_modal_bulk_users_field_last_name' => '<strong>last name</strong>',
            'admin_modal_bulk_users_field_type' => '<strong>type</strong>: <code>Editor</code> (default) or <code>Admin</code>',
            'admin_modal_bulk_users_field_create_galaxy' => '<strong>create galaxy?</strong>: <code>yes</code> / <code>no</code>. Empty inherits the checkbox below; a value here overrides it.',
            'admin_modal_bulk_users_example_label' => '<strong>Example:</strong>',
            'admin_modal_bulk_users_footer_help' => 'Each new user gets a welcome email with a one-time setup link (7-day TTL) to set their password. When a galaxy is created for them, the email also includes the galaxy URL and the login link. Existing emails are skipped; lines starting with <code>#</code> are ignored.',
            'admin_modal_bulk_users_textarea_placeholder' => 'email, firstname, lastname, type, create-galaxy',
            'admin_modal_bulk_users_label_create_galaxy_each' => 'Create a galaxy for each new user',
            'admin_modal_bulk_users_help_create_galaxy_each' => 'Slug taken from the email name (before the <code>@</code>); collisions get a short random suffix. Editors are assigned to their own galaxy; admins see every galaxy already. Override per row in the 5th column.',
            'admin_modal_heading_create_user' => 'Create New User',
            'admin_modal_label_first_name' => 'First Name *',
            'admin_modal_help_first_name' => 'The user\'s given name.',
            'admin_modal_label_last_name' => 'Last Name *',
            'admin_modal_help_last_name' => 'The user\'s family name.',
            'admin_modal_label_email' => 'Email *',
            'admin_modal_err_email_in_use' => 'This email is already in use.',
            'admin_modal_help_email' => 'Login identifier and contact address.',
            'admin_modal_label_password' => 'Password *',
            'admin_modal_help_password_min' => 'Minimum 8 characters.',
            'admin_modal_label_user_type' => 'User Type *',
            'admin_modal_opt_user_type_editor' => 'Editor',
            'admin_modal_opt_user_type_admin' => 'Admin',
            'admin_modal_help_user_type' => 'Editor: Can edit wormholes in assigned galaxies only | Admin: Full access to all galaxies.',
            'admin_modal_label_create_galaxy_for_user' => 'Create a new galaxy for this user',
            'admin_modal_help_create_galaxy_for_user' => 'A new galaxy is created with the name below and the user is granted access to it (Editors only).',
            'admin_modal_label_new_galaxy_name' => 'Galaxy name *',
            'admin_modal_placeholder_new_galaxy_name' => 'Defaults to email above',
            'admin_modal_help_new_galaxy_name' => 'Name for the automatically created galaxy.',
            'admin_modal_label_galaxy_access_editors' => 'Galaxy access (Editors only)',
            'admin_modal_help_galaxy_access_editors' => 'Editors can only see and edit wormholes in the galaxies checked above. Admins see all galaxies.',
            'admin_modal_btn_create_user' => 'Create User',
            'admin_modal_heading_create_galaxy' => 'Create New Galaxy',
            'admin_modal_label_galaxy_name' => 'Name *',
            'admin_modal_placeholder_galaxy_name' => 'e.g. Main network, Archive',
            'admin_modal_err_name_in_use' => 'This name is already in use.',
            'admin_modal_help_galaxy_name' => 'Unique name for the new wormhole network.',
            'admin_modal_label_url_slug' => 'URL Slug',
            'admin_modal_placeholder_url_slug' => 'e.g. archive',
            'admin_modal_err_slug_in_use' => 'This slug is already in use.',
            'admin_modal_help_url_slug' => 'Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.',
            'admin_modal_label_tagline' => 'Tagline',
            'admin_modal_placeholder_tagline' => 'e.g. Weaving memory',
            'admin_modal_help_tagline' => 'Shown in the main view when this galaxy is open.',
            'admin_modal_label_visual_theme' => 'Visual Theme',
            'admin_modal_opt_theme_cosmic' => 'Cosmic (Stars, Planets, Rockets)',
            'admin_modal_opt_theme_simple' => 'Simple (Colored Spheres)',
            'admin_modal_opt_theme_abstract' => 'Abstract (Geometric GIF Icons)',
            'admin_modal_opt_theme_rectangles' => 'Rectangles (Custom Rectangle Icons)',
            'admin_modal_opt_theme_stripes' => 'Stripes (Custom Stripe Icons)',
            'admin_modal_opt_theme_tech' => 'Tech (Circuit Board Icons)',
            'admin_modal_help_visual_theme' => 'Determines the background, icons and animations.',
            'admin_modal_btn_create_galaxy' => 'Create Galaxy',
            'admin_modal_heading_create_cluster' => 'Create Cluster',
            'admin_modal_heading_edit_cluster' => 'Edit Cluster',
            'admin_modal_heading_duplicate_cluster' => 'Duplicate Cluster',
            'admin_modal_placeholder_cluster_name' => 'e.g. Tracing the Earth',
            'admin_modal_placeholder_cluster_slug' => 'e.g. tracing-the-earth',
            'admin_modal_help_cluster_slug' => 'Visitors land at <code>/&lt;slug&gt;</code>. If left blank, one is generated from the name.',
            'admin_modal_placeholder_cluster_tagline' => 'e.g. A curated cluster',
            'admin_modal_opt_cluster_theme_cosmic' => 'Cosmic',
            'admin_modal_opt_cluster_theme_abstract' => 'Abstract',
            'admin_modal_opt_cluster_theme_rectangles' => 'Rectangles',
            'admin_modal_opt_cluster_theme_stripes' => 'Stripes',
            'admin_modal_opt_cluster_theme_tech' => 'Tech',
            'admin_modal_help_cluster_theme' => 'Scene theme. Each wormhole\'s icon still uses its source galaxy\'s theme.',
            'admin_modal_label_show_galaxy_list' => 'Show galaxy list to visitors',
            'admin_modal_help_show_galaxy_list' => 'When on, visitors see a list of the cluster\'s member galaxies in the bottom-right corner; clicking dims wormholes from other galaxies. Off by default for clusters since the curated framing is usually meant to read as one experience.',
            'admin_modal_label_member_galaxies' => 'Member galaxies *',
            'admin_modal_help_member_galaxies' => 'Visitors see the union of these galaxies\' wormholes. Bridges (subtle dashed lines) connect wormholes sharing keyword text across galaxies.',
            'admin_modal_count_selected_one' => '%d selected',
            'admin_modal_count_selected_many' => '%d selected',
            'admin_modal_label_keyword_chips' => 'Keyword chips',
            'admin_modal_help_keyword_chips' => 'Pool the most-used keywords across all visible wormholes (every member galaxy) into a filter chip strip at the top of the cluster. Click a chip to dim non-matching wormholes.',
            'admin_modal_label_related_wormholes' => 'Related wormholes',
            'admin_modal_help_related_wormholes' => 'When a wormhole\'s info card is open, dim unrelated ones and surface up to 5 related wormholes (sharing keywords) as click-to-jump chips at the bottom of the card. Pools across the whole cluster; chips can surface wormholes from any member galaxy.',
            'admin_modal_label_2d_view' => '2D view switch',
            'admin_modal_help_2d_view' => 'Show a top-center "3D / 2D" toggle so visitors can flip from the 3D scene to a flat grid of wormhole chips. Visitor\'s preference persists in their browser.',
            'admin_modal_label_idle_spotlight' => 'Idle spotlight',
            'admin_modal_help_idle_spotlight' => 'When the visitor is idle, fly the camera to one random wormhole anywhere in the cluster and open its info card. Closes when media ends or after the dwell timer.',
            'admin_modal_label_pick_from' => 'Pick from',
            'admin_modal_opt_pick_all_wormholes' => 'All wormholes (across every member galaxy)',
            'admin_modal_opt_pick_accentuated' => 'Only accentuated wormholes',
            'admin_modal_label_trigger_after_seconds' => 'Trigger after (seconds idle)',
            'admin_modal_label_auto_tour' => 'Auto-tour',
            'admin_modal_title_preview_tour' => 'Save first, then preview the tour in a new tab',
            'admin_modal_btn_preview_tour' => 'Preview tour',
            'admin_modal_help_auto_tour' => 'Automatically navigate visitors through wormholes across the cluster, opening each card and playing media. Desktop and iPad only.',
            'admin_modal_label_start_mode' => 'Start Mode',
            'admin_modal_opt_start_manual' => 'Manual. Visitor clicks a Play button to start.',
            'admin_modal_opt_start_idle' => 'Idle. Start after visitor is inactive for a while.',
            'admin_modal_opt_start_immediate' => 'Immediate. Start a few seconds after the cluster loads.',
            'admin_modal_label_idle_threshold' => 'Idle threshold (seconds)',
            'admin_modal_warn_immediate_audio' => 'One or more member galaxies contain audio wormholes. Browsers block autoplay-with-sound until the visitor interacts with the page, so the first audio in an immediate-start tour may stay silent or stall.',
            'admin_modal_label_which_wormholes' => 'Which wormholes to tour',
            'admin_modal_opt_tour_all' => 'All wormholes (random order each run)',
            'admin_modal_opt_tour_accentuated' => 'Only accentuated wormholes',
            'admin_modal_opt_tour_random_n' => 'A random sample of N wormholes',
            'admin_modal_opt_tour_tagged' => 'Wormholes tagged with one of these keywords',
            'admin_modal_label_random_count' => 'How many wormholes per tour',
            'admin_modal_label_tour_keywords' => 'Keywords (any match, comma-separated)',
            'admin_modal_placeholder_tour_keywords' => 'e.g. Ideology, Resistance, Land',
            'admin_modal_help_tour_keywords' => 'Matches by keyword name (case-insensitive) across every member galaxy. Useful when the same tag (e.g. <code>Ideology</code>) exists in several galaxies but with different keyword IDs.',
            'admin_modal_label_dwell_seconds' => 'Pause on wormholes without media (seconds)',
            'admin_modal_label_loop_tour' => 'Loop the tour when it finishes',
            'admin_modal_btn_create_cluster' => 'Create Cluster',
            'admin_modal_btn_update_cluster' => 'Update Cluster',
            'admin_modal_name_copy_suffix' => ' (Copy)',
            'admin_modal_heading_edit_user' => 'Edit User',
            'admin_modal_label_password_optional' => 'Password (leave blank to keep current)',
            'admin_modal_btn_update_user' => 'Update User',
            'admin_modal_heading_duplicate_galaxy' => 'Duplicate Galaxy',
            'admin_modal_label_duplicating' => 'Duplicating:',
            'admin_modal_label_new_name' => 'New Name *',
            'admin_modal_label_new_url_slug' => 'New URL Slug',
            'admin_modal_label_new_tagline' => 'New Tagline',
            'admin_modal_btn_duplicate' => 'Duplicate',
            'admin_modal_heading_confirm_deletion' => 'Confirm Deletion',
            'admin_modal_label_type_galaxy_name' => 'Please type the name of the galaxy to confirm:',
            'admin_modal_placeholder_type_name' => 'Type name here...',
            'admin_modal_btn_delete' => 'Delete',
            'admin_modal_deletion_impact_title' => '⚠️ Deletion Impact:',
            'admin_modal_deletion_impact_intro' => 'The following portals in other galaxies point to this network and will also be deleted:',
            'admin_modal_deletion_impact_row' => '<strong>%s</strong> (in galaxy: %s)',
        ],
        'es' => [
            'name' => 'Telaris', 'description' => 'Tejiendo memoria', 'iframe_back_text' => 'Volver', 
            'alert_message' => "Estás cruzando hacia la Dimensión Planar\nPara explorar, haz zoom y desplázate en todas las direcciones\nCierra la ventana del navegador para volver a la Dimensión Cósmica.", 
            'edit_button_text' => 'Editar', 'loading_text' => 'Cargando',
            'back_button_text' => 'Volver', 'system_online_text' => 'En línea',
            'reload_system_text' => 'Recargar', 'scan_system_text' => 'BUSCAR...',
            'clear_scan_text' => 'Limpiar Búsqueda', 'systems_label_text' => 'Agujeros de Gusano:',
            'hyperlinks_label_text' => 'Hipervínculos:', 'initialize_auth_text' => 'Iniciar sesión',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Cerrar sesión',
            'click_to_view_text' => 'Haz clic para ver', 'tap_to_view_text' => 'Toca de nuevo para ver',
            'open_portal_text' => 'Abrir el Portal',
            'sound_label_text' => 'Sonido:', 'sound_on_text' => 'SÍ', 'sound_off_text' => 'NO',
            'launching_text' => 'Lanzando', 'mission_active_text' => 'Misión Activa', 'go_text' => 'YA',
            'breadcrumb_all_text' => 'Todo', 'launch_button_text' => 'LANZAR',
            'no_results_text' => 'Sin resultados', 'items_label_text' => 'elementos', 'other_label_text' => 'Otros',
            'galaxies_label_text' => 'Galaxias',
            'galaxy_count_singular_text' => '1 galaxia',
            'galaxy_count_plural_text' => '%d galaxias',
            'pdf_loading_text' => 'Cargando PDF…',
            'pdf_rendering_text' => 'Procesando páginas…',
            'pdf_pages_singular_text' => '1 página',
            'pdf_pages_plural_text' => '%d páginas',
            'pdf_open_text' => 'Abrir en otra pestaña',
            'pdf_download_text' => 'Descargar',
            'pdf_error_load_text' => 'No se pudo cargar la biblioteca de PDF.',
            'pdf_error_open_text' => 'No se pudo abrir el PDF.',
            'tour_label_text' => 'Recorrido',
            'tour_start_aria_text' => 'Iniciar recorrido',
            'tour_previous_aria_text' => 'Anterior',
            'tour_pause_aria_text' => 'Pausar',
            'tour_next_aria_text' => 'Siguiente',
            'tour_exit_aria_text' => 'Salir del recorrido',
            'nav_toggle_aria_text' => 'Alternar menú de navegación',
            'share_link_title_text' => 'Copiar enlace a este agujero de gusano',
            'related_label_text' => 'Relacionados',
            'lang_label_text' => 'Idioma:',
            'node_name_fallback_text' => 'Sistema',
            'untitled_text' => 'Sin título',
            'chip_open_prefix_text' => 'Abrir',
            'search_result_text' => 'Resultado',
            'search_results_text' => 'Resultados',
            // Editor chunk C1 (edit/index.php)
            'editor_page_title' => 'Editar agujeros de gusano',
            'editor_user_role_admin' => 'Administradora',
            'editor_user_role_editor' => 'Editora',
            'editor_label_current_galaxy' => 'Galaxia actual:',
            'editor_option_all_galaxies_admin' => 'Todas las galaxias',
            'editor_option_all_galaxies_editor' => 'Todas mis galaxias',
            'editor_btn_view' => 'Ver',
            'editor_btn_galaxy_settings_title' => 'Ajustes de la galaxia',
            'editor_btn_settings' => 'Ajustes',
            'editor_btn_keyword_canvas_title' => 'Crear relaciones entre palabras clave',
            'editor_btn_canvas' => 'Lienzo',
            'editor_btn_copy_url_title' => 'Copiar URL de la galaxia',
            'editor_btn_admin_console' => 'Consola de administración',
            'editor_btn_logout' => 'Cerrar sesión',
            'editor_error_no_api_key' => '⚠️ Error: no se encontró ninguna clave de API activa. Contacta a una administradora.',
            'editor_bulk_selected_suffix' => 'agujeros de gusano seleccionados',
            'editor_btn_clear_selection' => 'Limpiar selección',
            'editor_btn_bulk_move' => 'Mover seleccionados',
            'editor_btn_bulk_duplicate' => 'Duplicar seleccionados',
            'editor_btn_bulk_delete' => 'Eliminar seleccionados',
            'editor_banner_imported_read_only' => 'Esta galaxia se importó desde una fuente externa y es de solo lectura. Usa la acción Actualizar en la lista de galaxias del panel de administración para sincronizar cambios.',
            'editor_heading_wormholes' => 'Agujeros de gusano',
            'editor_btn_new_wormhole' => 'Nuevo agujero de gusano',
            'editor_btn_touched_today_title' => 'Mostrar solo agujeros de gusano modificados hoy',
            'editor_btn_touched_today' => 'Modificados hoy',
            'editor_btn_bulk_keyword_title' => 'Eliminar o mover en bloque todo agujero de gusano de esta galaxia que tenga una palabra clave específica',
            'editor_btn_bulk_by_keyword' => 'Acción en bloque por palabra clave…',
            'editor_btn_shortcuts_title' => 'Atajos de teclado (? para abrir)',
            'editor_label_search' => 'Buscar:',
            'editor_placeholder_search_wormholes' => 'Buscar agujeros de gusano...',
            'editor_col_name' => 'Nombre',
            'editor_col_type' => 'Tipo',
            'editor_col_galaxy' => 'Galaxia',
            'editor_col_url' => 'URL',
            'editor_col_keywords' => 'Palabras clave',
            'editor_col_created' => 'Creación',
            'editor_col_updated' => 'Actualización',
            'editor_col_actions' => 'Acciones',
            'editor_col_acc' => 'Ac',
            'editor_col_acc_title' => 'Estado de acentuación',
            'editor_msg_loading_wormholes' => 'Cargando agujeros de gusano...',
            'editor_msg_retrieving_wormholes' => 'Obteniendo agujeros de gusano...',
            'editor_heading_no_wormholes' => 'No se encontraron agujeros de gusano.',
            'editor_text_empty_state_help' => 'Ajusta la búsqueda o crea un nuevo agujero de gusano para empezar.',
            'editor_heading_error_loading' => 'Error al cargar agujeros de gusano',
            'editor_error_api_key_missing' => 'Falta la clave de API.',
            'editor_error_api_key_missing_fetch' => 'Error: falta la clave de API. Contacta a una administradora.',
            'editor_error_invalid_json' => 'Respuesta JSON inválida del servidor',
            'editor_error_invalid_format' => 'Formato de respuesta inválido',
            'editor_error_invalid_data_format' => 'Error: se recibió un formato de datos inválido.',
            'editor_text_no_keywords' => 'Sin palabras clave',
            'editor_label_node_type_portal' => 'Portal',
            'editor_label_node_type_object' => 'Objeto',
            'editor_badge_accentuated' => 'AC',
            'editor_badge_accentuated_title' => 'Agujero de gusano acentuado',
            'editor_badge_has_url' => 'URL',
            'editor_badge_has_url_title' => 'Tiene URL',
            'editor_badge_has_desc' => 'DESC',
            'editor_badge_has_desc_title' => 'Tiene descripción',
            'editor_badge_has_img' => 'IMG',
            'editor_badge_has_img_title' => 'Tiene imagen',
            'editor_badge_has_emb' => 'EMB',
            'editor_badge_has_emb_title' => 'Tiene incrustación',
            'editor_badge_has_aud' => 'AUD',
            'editor_badge_has_aud_title' => 'Tiene audio',
            'editor_badge_has_vid' => 'VID',
            'editor_badge_has_vid_title' => 'Tiene video',
            'editor_title_accentuated' => 'Acentuado',
            'editor_action_view_wormhole' => 'Ver agujero de gusano',
            'editor_action_view_galaxy' => 'Ver galaxia',
            'editor_action_edit' => 'Editar',
            'editor_action_duplicate' => 'Duplicar',
            'editor_action_delete' => 'Eliminar',
            'editor_toast_bulk_move_success' => 'Se movieron %d agujeros de gusano.',
            'editor_toast_bulk_move_failed' => 'Falló al mover %d agujeros de gusano.',
            'editor_toast_bulk_move_error' => 'Ocurrió un error durante el movimiento en bloque.',
            'editor_toast_duplicate_success' => 'Agujero de gusano duplicado correctamente.',
            'editor_error_failed_duplicate' => 'Falló al duplicar',
            'editor_toast_duplicate_error_generic' => 'Ocurrió un error al duplicar.',
            'editor_toast_bulk_duplicate_success' => 'Se duplicaron %d agujeros de gusano.',
            'editor_toast_bulk_duplicate_failed' => 'Falló al duplicar %d agujeros de gusano.',
            'editor_toast_bulk_duplicate_error' => 'Ocurrió un error durante la duplicación en bloque.',
            'editor_confirm_bulk_delete' => '¿Seguro que quieres eliminar %d agujeros de gusano? Esta acción no se puede deshacer.',
            'editor_toast_bulk_delete_success' => 'Se eliminaron %d agujeros de gusano.',
            'editor_toast_bulk_delete_failed' => 'Falló al eliminar %d agujeros de gusano.',
            'editor_toast_bulk_delete_error' => 'Ocurrió un error durante la eliminación en bloque.',
            'editor_toast_url_copied' => 'URL copiada al portapapeles',
            'editor_title_url_copied' => '¡Copiada!',
            'editor_toast_galaxy_created' => 'Galaxia "%s" creada.',
            'editor_toast_error_creating_galaxy' => 'Error al crear la galaxia: %s',
            'editor_prompt_new_galaxy_name' => 'Nombre de la nueva galaxia:',
            'editor_modal_heading_add_wormhole' => 'Agregar nuevo agujero de gusano',
            'editor_modal_heading_edit_wormhole' => 'Editar agujero de gusano',
            'editor_label_name_required' => 'Nombre *',
            'editor_error_name_exists' => 'Este nombre ya existe en esta galaxia.',
            'editor_help_name' => 'Título principal del agujero de gusano que se muestra en la red.',
            'editor_label_galaxy' => 'Galaxia',
            'editor_help_constellation' => 'A qué galaxia pertenece este agujero de gusano.',
            'editor_label_wormhole_type' => 'Tipo de agujero de gusano',
            'editor_help_node_type' => 'Objeto es un elemento estándar; Portal lleva a otra galaxia.',
            'editor_label_keywords' => 'Palabras clave',
            'editor_placeholder_add_keyword' => 'Agregar palabra clave...',
            'editor_help_keywords_add' => 'Escribe y presiona Enter o coma para agregar palabras clave. Las sugerencias muestran palabras clave ya usadas en esta galaxia y en galaxias hermanas que compartan tu prefijo `[XX]`.',
            'editor_label_accentuate_wormhole' => 'Acentuar agujero de gusano',
            'editor_help_accentuate' => 'Hace que este agujero de gusano se vea más grande y destacado en la red.',
            'editor_label_show_keywords' => 'Mostrar palabras clave',
            'editor_help_show_keywords' => 'Muestra las palabras clave de este agujero de gusano en su ventana de información.',
            'editor_label_target_galaxy' => 'Galaxia destino',
            'editor_help_target_galaxy' => 'La galaxia destino a la que lleva este portal.',
            'editor_btn_create_new_galaxy' => 'Crear nueva galaxia',
            'editor_label_description' => 'Descripción',
            'editor_help_description' => 'Texto detallado que aparece cuando se selecciona el agujero de gusano.',
            'editor_label_url' => 'URL',
            'editor_placeholder_url' => 'https://example.com',
            'editor_help_url' => 'URL que se abre al hacer clic en el agujero de gusano (opcional).',
            'editor_label_primary_visual' => 'Visual principal',
            'editor_tab_image' => 'Imagen',
            'editor_tab_video' => 'Video (MP4)',
            'editor_tab_pdf' => 'PDF',
            'editor_help_visual_mutex' => 'Elige una. Al cambiar de pestaña y guardar se borran las otras.',
            'editor_label_image_url_file' => 'URL o archivo de imagen',
            'editor_label_use_as_icon' => 'Usar como icono del agujero de gusano',
            'editor_placeholder_image_url' => 'https://example.com/image.jpg',
            'editor_placeholder_video_url' => 'https://example.com/video.mp4',
            'editor_label_autoplay_video' => 'Reproducir video automáticamente',
            'editor_placeholder_pdf_url' => 'https://example.com/document.pdf',
            'editor_help_pdf' => 'Sube un PDF o pega un enlace.',
            'editor_placeholder_credit' => 'Crédito o atribución...',
            'editor_help_credit' => 'Crédito opcional que se muestra sobre el visual en la caja de información (imagen, video o PDF).',
            'editor_label_icon_url_file' => 'URL o archivo de icono',
            'editor_placeholder_icon_url' => 'https://example.com/icon.png',
            'editor_help_icon' => 'Icono personalizado que se muestra en la escena 3D (reemplaza el icono del tema).',
            'editor_label_audio_url_file' => 'URL o archivo de audio',
            'editor_placeholder_audio_url' => 'https://example.com/audio.mp3',
            'editor_label_autoplay' => 'Reproducir automáticamente',
            'editor_label_loop' => 'En bucle',
            'editor_help_audio' => 'Independiente del visual principal: el audio puede acompañar a imagen, video o PDF.',
            'editor_text_uploading' => 'Subiendo...',
            'editor_btn_add_wormhole' => 'Agregar agujero de gusano',
            'editor_btn_cancel' => 'Cancelar',
            'editor_divider_media' => 'Multimedia',
            'editor_btn_delete_file' => 'Eliminar',
            'editor_btn_update_wormhole' => 'Actualizar agujero de gusano',
            'editor_modal_heading_confirm_delete' => 'Confirmar eliminación',
            'editor_btn_delete' => 'Eliminar',
            'editor_modal_heading_move_wormholes' => 'Mover agujeros de gusano',
            'editor_text_move_count_wormholes' => 'Mover %d agujeros de gusano seleccionados a otra galaxia.',
            'editor_label_destination_galaxy' => 'Galaxia destino',
            'editor_btn_move_wormholes' => 'Mover agujeros de gusano',
            'editor_modal_heading_duplicate_wormhole' => 'Duplicar agujero de gusano',
            'editor_text_duplicate_to' => 'Duplicar "%s" en:',
            'editor_btn_duplicate' => 'Duplicar',
            'editor_modal_heading_duplicate_wormholes' => 'Duplicar agujeros de gusano',
            'editor_text_duplicate_count_wormholes' => 'Duplicar %d agujeros de gusano seleccionados en:',
            'editor_btn_duplicate_wormholes' => 'Duplicar agujeros de gusano',
            'editor_btn_open_link' => 'Abrir enlace',
            'editor_btn_apply' => 'Aplicar',
            'editor_label_target_prefix' => 'Destino:',
            'editor_modal_heading_bulk_keyword' => 'Acción en bloque por palabra clave',
            'editor_text_bulk_keyword_help' => 'Elige una palabra clave en la galaxia actual. Luego decide si eliminar todos los agujeros de gusano que la tengan o moverlos a otra galaxia.',
            'editor_label_keyword' => 'Palabra clave',
            'editor_option_loading' => 'Cargando…',
            'editor_label_action' => 'Acción',
            'editor_option_delete_matching' => 'Eliminar los agujeros de gusano que coincidan',
            'editor_option_move_matching' => 'Moverlos a otra galaxia',
            'editor_text_pick_keyword' => 'Elige una palabra clave para ver el total.',
            'editor_error_pick_specific_galaxy' => 'Elige primero una galaxia específica (no "Todas las galaxias").',
            'editor_option_no_keywords' => '(sin palabras clave en esta galaxia)',
            'editor_option_pick_one' => 'elige una',
            'editor_option_error_keywords' => 'Error al cargar palabras clave',
            'editor_option_pick_galaxy' => 'elige una galaxia',
            'editor_preview_move_one' => 'Se moverá 1 agujero de gusano a la galaxia elegida.',
            'editor_preview_move_many' => 'Se moverán %d agujeros de gusano a la galaxia elegida.',
            'editor_preview_move_pick_target_one' => 'Se moverá 1 agujero de gusano. Elige primero una galaxia destino.',
            'editor_preview_move_pick_target_many' => 'Se moverán %d agujeros de gusano. Elige primero una galaxia destino.',
            'editor_preview_delete_one' => 'Se eliminará 1 agujero de gusano de forma permanente.',
            'editor_preview_delete_many' => 'Se eliminarán %d agujeros de gusano de forma permanente.',
            'editor_confirm_bulk_delete_keyword_one' => '¿Eliminar de forma permanente 1 agujero de gusano con la palabra clave "%s"? Esto no se puede deshacer.',
            'editor_confirm_bulk_delete_keyword_many' => '¿Eliminar de forma permanente %d agujeros de gusano con la palabra clave "%s"? Esto no se puede deshacer.',
            'editor_confirm_bulk_move_keyword_one' => '¿Mover 1 agujero de gusano con la palabra clave "%s" a la galaxia seleccionada?',
            'editor_confirm_bulk_move_keyword_many' => '¿Mover %d agujeros de gusano con la palabra clave "%s" a la galaxia seleccionada?',
            'editor_toast_bulk_deleted_one' => 'Se eliminó 1 agujero de gusano.',
            'editor_toast_bulk_deleted_many' => 'Se eliminaron %d agujeros de gusano.',
            'editor_toast_bulk_moved_one' => 'Se movió 1 agujero de gusano.',
            'editor_toast_bulk_moved_many' => 'Se movieron %d agujeros de gusano.',
            'editor_toast_bulk_action_failed' => 'La acción en bloque falló: %s',
            'editor_modal_heading_shortcuts' => 'Atajos de teclado',
            'editor_shortcut_new_wormhole' => 'Nuevo agujero de gusano',
            'editor_shortcut_focus_search' => 'Enfocar el campo de búsqueda',
            'editor_shortcut_toggle_touched' => 'Alternar filtro "Modificados hoy"',
            'editor_shortcut_galaxy_settings' => 'Abrir ajustes de la galaxia (galaxia actual)',
            'editor_shortcut_close_modal' => 'Cerrar cualquier ventana modal abierta',
            'editor_shortcut_open_help' => 'Abrir esta ayuda',
            'editor_note_shortcuts_typing' => 'Los atajos se ignoran mientras escribes en un campo de texto.',
            'editor_btn_close' => 'Cerrar',
            'editor_toast_updated_successfully' => 'Agujero de gusano actualizado correctamente',
            'editor_toast_created_successfully' => 'Agujero de gusano creado correctamente',
            'editor_error_failed_update' => 'Falló al actualizar el agujero de gusano',
            'editor_error_failed_create' => 'Falló al crear el agujero de gusano',
            'editor_error_network_upload' => 'Error de red durante la subida',
            'editor_error_name_required' => 'Se requiere el nombre del agujero de gusano',
            'editor_error_loading_node' => 'Error al cargar el agujero de gusano: %s',
            'editor_confirm_delete_file' => '¿Seguro que quieres eliminar este archivo %s subido?',
            'editor_toast_file_deleted' => 'Archivo %s eliminado',
            'editor_error_deleting_file' => 'Error al eliminar el archivo: %s',
            'editor_confirm_delete_node' => '¿Seguro que quieres eliminar "%s"? Esta acción no se puede deshacer.',
            'editor_error_delete_wormhole' => 'Falló al eliminar el agujero de gusano',
            'editor_toast_deleted_successfully' => 'Agujero de gusano eliminado correctamente',
            'editor_error_deleting_wormhole' => 'Error al eliminar el agujero de gusano: %s',
            'editor_error_fatal_loading' => 'Error fatal al cargar agujeros de gusano: %s',
            'editor_error_could_not_load' => 'Error: no se pudieron cargar los agujeros de gusano. %s',
            'editor_kc_status_loading' => 'Cargando…',
            'editor_kc_status_no_keywords' => 'Aún no hay palabras clave',
            'editor_kc_status_ready' => 'Listo',
            'editor_kc_status_saving' => 'Guardando…',
            'editor_kc_status_saved' => 'Guardado',
            'editor_kc_status_deleting' => 'Eliminando…',
            'editor_kc_status_deleted' => 'Eliminado',
            'editor_kc_status_merging' => 'Fusionando…',
            'editor_kc_status_merged' => 'Fusionado',
            'editor_kc_status_renamed' => 'Renombrado',
            'editor_kc_status_already_related' => 'Ya están relacionadas',
            'editor_kc_status_drag_or_click' => 'Arrastra hasta otro punto de anclaje, o haz clic en uno (Esc para cancelar)',
            'editor_kc_status_load_failed' => 'Error al cargar: %s',
            'editor_kc_status_save_failed' => 'Error al guardar: %s',
            'editor_kc_status_create_failed' => 'Error al crear: %s',
            'editor_kc_status_delete_failed' => 'Error al eliminar: %s',
            'editor_kc_status_rename_failed' => 'Error al renombrar: %s',
            'editor_kc_status_merge_failed' => 'Error al fusionar: %s',
            'editor_kc_status_update_failed' => 'Error al actualizar: %s',
            'editor_kc_modal_title_new_relation' => 'Nueva relación',
            'editor_kc_modal_title_edit_relation' => 'Editar nota de relación',
            'editor_kc_label_authored_by' => 'Creada por %s',
            'editor_kc_label_no_author_recorded' => 'Sin autoría registrada',
            'editor_kc_label_no_author_short' => '(sin autoría)',
            'editor_kc_err_empty_name' => 'Elige un nombre no vacío.',
            'editor_kc_err_name_taken_galaxy' => 'Ese nombre ya está en uso en esta galaxia',
            'editor_kc_err_name_taken_conflict' => 'Ese nombre ya está en uso; cámbialo o fusiónalas.',
            'editor_kc_err_missing_config' => 'Falta la configuración de la página (window.TELARIS_KC)',
            'editor_gxm_status_loading_keywords' => 'Cargando…',
            'editor_gxm_no_keywords_yet' => 'Aún no hay palabras clave para esta galaxia.',
            'editor_gxm_load_failed_keywords' => 'Error al cargar.',
            'editor_gxm_label_use_images_as_icons' => 'usar imágenes como íconos',
            'editor_gxm_label_revert_to_theme_icons' => 'restaurar todos los íconos del tema',
            'editor_gxm_confirm_apply_to_all' => '¿Aplicar "%s" a cada agujero de gusano de esta galaxia?',
            'editor_gxm_status_working' => 'Procesando…',
            'editor_gxm_status_updated_one' => 'Se actualizó %d agujero de gusano. Vuelve a cargar la vista de visitante para ver el cambio.',
            'editor_gxm_status_updated_many' => 'Se actualizaron %d agujeros de gusano. Vuelve a cargar la vista de visitante para ver el cambio.',
            'editor_gxm_label_failed_prefix' => 'Error: %s',
            'editor_gxm_err_update_failed_fallback' => 'Error al actualizar',
            // C3: admin/index.php
            'admin_loading_console' => 'Cargando consola de administración...',
            'admin_heading_console' => 'Consola de administración',
            'admin_label_welcome' => 'Bienvenida, %s',
            'admin_btn_edit_content' => 'Editar contenido',
            'admin_btn_logout' => 'Cerrar sesión',
            'admin_msg_api_key_generated_title' => '✓ Llave de API generada',
            'admin_msg_api_key_generated_body' => 'Tu llave de API: %s (Nombre: %s). CÓPIALA AHORA.',
            'admin_msg_settings_saved' => 'Ajustes globales guardados.',
            'admin_tab_galaxies' => 'Galaxias',
            'admin_tab_clusters' => 'Cúmulos',
            'admin_tab_users' => 'Usuarias',
            'admin_tab_backup' => 'Respaldo',
            'admin_tab_snapshots' => 'Instantáneas',
            'admin_tab_settings' => 'Ajustes globales',
            'admin_tab_api_keys' => 'Llaves de API',
            'admin_tab_php_info' => 'Información de PHP',
            'admin_heading_users' => 'Usuarias',
            'admin_btn_new_user' => 'Nueva usuaria',
            'admin_btn_bulk_import' => 'Importación masiva',
            'admin_label_search' => 'Buscar:',
            'admin_placeholder_search_users' => 'Buscar usuarias...',
            'admin_msg_no_users' => 'No se encontraron usuarias.',
            'admin_col_user_name' => 'Nombre',
            'admin_col_user_email' => 'Correo',
            'admin_col_user_type' => 'Tipo',
            'admin_col_user_created' => 'Creada',
            'admin_col_user_last_login' => 'Último inicio de sesión',
            'admin_col_user_last_updated' => 'Última actualización',
            'admin_col_actions' => 'Acciones',
            'admin_user_type_regular' => 'Regular',
            'admin_user_type_editor' => 'Editora',
            'admin_user_type_admin' => 'Administradora',
            'admin_badge_you' => 'Tú',
            'admin_label_never' => 'Nunca',
            'admin_action_edit' => 'Editar',
            'admin_action_delete' => 'Eliminar',
            'admin_confirm_delete_user' => '¿Seguro que quieres eliminar a la usuaria "%s"? Esta acción no se puede deshacer.',
            'admin_heading_generate_api_key' => 'Generar nueva llave de API',
            'admin_label_api_key_name' => 'Nombre *',
            'admin_placeholder_api_key_name' => 'p. ej., App frontend, App móvil, Admin',
            'admin_help_api_key_name' => 'Un nombre descriptivo para esta llave de API',
            'admin_label_api_key_description' => 'Descripción',
            'admin_placeholder_api_key_description' => 'Descripción opcional del uso de esta llave',
            'admin_btn_generate_api_key' => 'Generar llave de API',
            'admin_btn_cancel' => 'Cancelar',
            'admin_heading_api_keys' => 'Llaves de API',
            'admin_btn_new_api_key' => 'Nueva llave de API',
            'admin_msg_no_api_keys' => 'Aún no se han generado llaves de API.',
            'admin_badge_inactive' => 'Inactiva',
            'admin_action_deactivate' => 'Desactivar',
            'admin_action_activate' => 'Activar',
            'admin_confirm_delete_api_key' => '¿Seguro que quieres eliminar esta llave de API? Esta acción no se puede deshacer.',
            'admin_label_created' => 'Creada:',
            'admin_label_last_used' => 'Último uso:',
            'admin_label_last_updated' => 'Última actualización:',
            'admin_heading_galaxies' => 'Galaxias',
            'admin_btn_new_galaxy' => 'Nueva galaxia',
            'admin_placeholder_search_galaxies' => 'Buscar galaxias...',
            'admin_help_galaxies_default' => 'Cada galaxia es un conjunto independiente de agujeros de gusano y palabras clave. La galaxia predeterminada actual, %s, no se puede eliminar.',
            'admin_help_galaxies_settings_link' => 'Puedes cambiar la galaxia predeterminada en la pestaña %s.',
            'admin_toast_url_copied' => 'URL copiada al portapapeles.',
            'admin_heading_clusters' => 'Cúmulos de galaxias',
            'admin_btn_new_cluster' => 'Nuevo cúmulo',
            'admin_placeholder_search_clusters' => 'Buscar cúmulos...',
            'admin_help_clusters' => 'Un cúmulo es una unión curada de galaxias con su propio slug, título, tema y enlace permanente. Los cúmulos no tienen agujeros de gusano propios; muestran la unión de sus miembros a través del flujo multigalaxia.',
            'admin_help_settings' => 'Ajustes que aplican a toda la instancia de la aplicación principal.',
            'admin_label_version' => 'Versión',
            'admin_label_default_galaxy' => 'Galaxia predeterminada',
            'admin_help_default_galaxy' => 'Elige qué galaxia se muestra en la raíz del sitio.',
            'admin_label_pdf_max' => 'Tamaño máximo de PDF (MB)',
            'admin_help_pdf_max' => "PDF más grande que puede contener un agujero de gusano. Por defecto 25 MB. Las editoras que suban archivos más grandes verán el error 'El archivo supera el tamaño máximo permitido'.",
            'admin_btn_save_settings' => 'Guardar ajustes',
            'admin_heading_download_backup' => 'Descargar un respaldo',
            'admin_help_download_backup' => 'Crea un archivo de respaldo portable con galaxias y/o usuarias. La opción por defecto produce un respaldo completo con los archivos multimedia incrustados.',
            'admin_label_galaxies' => 'Galaxias',
            'admin_label_all_galaxies' => 'Todas las galaxias',
            'admin_label_selected_galaxies' => 'Solo galaxias seleccionadas',
            'admin_msg_loading_galaxies' => 'Cargando galaxias...',
            'admin_btn_select_all' => 'Seleccionar todo',
            'admin_btn_clear' => 'Limpiar',
            'admin_label_users_always_all' => 'Usuarias (siempre todas)',
            'admin_help_users_export' => 'Las contraseñas de las usuarias se exportan como hashes. Nunca aparecen en texto plano.',
            'admin_label_media_files' => 'Archivos multimedia',
            'admin_label_media_embedded' => 'Incrustados: respaldo autocontenido (recomendado)',
            'admin_label_media_refs' => 'Solo referencias: archivo más pequeño, restaurable solo en el mismo servidor',
            'admin_label_media_none' => 'Ninguno: descartar todo el material multimedia',
            'admin_btn_download_backup' => 'Descargar respaldo',
            'admin_heading_restore_backup' => 'Restaurar desde un respaldo',
            'admin_help_restore_backup' => 'Sube un archivo .telaris-backup. Verás un resumen antes de que se aplique cualquier cambio.',
            'admin_btn_inspect_file' => 'Inspeccionar archivo',
            'admin_label_galaxies_in_file' => 'Galaxias en este archivo',
            'admin_label_for_each_galaxy' => 'Para cada galaxia seleccionada',
            'admin_label_overwrite_slug' => 'Sobrescribir si ya existe una galaxia con el mismo slug',
            'admin_label_create_as_new' => 'Crear como nueva (renombrar en caso de conflicto, sufijo:',
            'admin_label_users_in_file' => 'Usuarias en este archivo',
            'admin_label_restore_users' => 'Restaurar usuarias',
            'admin_label_skip_existing' => 'Saltar usuarias existentes (coincidir por correo)',
            'admin_label_update_existing' => 'Actualizar usuarias existentes por correo',
            'admin_label_overwrite_pw' => 'También sobrescribir los hashes de contraseña',
            'admin_label_restore_media' => 'Restaurar archivos multimedia',
            'admin_btn_restore' => 'Restaurar',
            'admin_help_snapshots' => 'Las instantáneas son respaldos completos locales, en disco, del sistema entero. Restaurar una instantánea borra todo y lo reemplaza por el estado de la instantánea. Cualquier instantánea creada después de la restaurada se elimina.',
            'admin_heading_create_snapshot' => 'Crear instantánea ahora',
            'admin_placeholder_snapshot_note' => 'Nota opcional (p. ej. antes de migrar)',
            'admin_btn_create_snapshot' => 'Crear instantánea',
            'admin_msg_creating_snapshot' => 'Creando instantánea. Puede tomar un minuto en instancias grandes. Por favor no cierres esta pestaña.',
            'admin_heading_snapshot_scheduler' => 'Programador de instantáneas',
            'admin_label_enable_daily' => 'Activar instantáneas diarias',
            'admin_label_hour_utc' => 'Hora (UTC)',
            'admin_label_keep_days' => 'Días a conservar (auto)',
            'admin_btn_save' => 'Guardar',
            'admin_btn_refresh_status' => 'Actualizar estado',
            'admin_label_status' => 'Estado:',
            'admin_label_last_snapshot' => 'Última instantánea:',
            'admin_label_last_checked' => 'Última comprobación:',
            'admin_label_status_loading' => 'cargando...',
            'admin_label_never_lower' => 'nunca',
            'admin_label_recent_activity' => 'Actividad reciente',
            'admin_msg_no_activity' => '(aún sin actividad)',
            'admin_heading_available_snapshots' => 'Instantáneas disponibles',
            'admin_msg_loading' => 'Cargando...',
            'admin_heading_php_config' => 'Configuración de PHP',
            'admin_heading_important_extensions' => 'Extensiones importantes',
            'admin_heading_all_extensions' => 'Todas las extensiones cargadas',
            'admin_msg_no_galaxies' => 'No se encontraron galaxias.',
            'admin_col_id' => 'ID',
            'admin_col_galaxy_name' => 'Nombre',
            'admin_col_slug' => 'Slug',
            'admin_col_tagline' => 'Lema',
            'admin_col_wormholes' => 'Agujeros de gusano',
            'admin_col_created' => 'Creada',
            'admin_col_last_updated' => 'Última actualización',
            'admin_badge_default' => 'Predeterminada',
            'admin_badge_imported' => 'Importada',
            'admin_title_tour_enabled' => 'Tour automático activado',
            'admin_msg_error_loading_galaxies' => 'Error al cargar las galaxias: %s',
            'admin_action_view' => 'Ver',
            'admin_action_copy_url' => 'Copiar URL',
            'admin_action_keyword_canvas' => 'Lienzo de palabras clave',
            'admin_action_duplicate' => 'Duplicar',
            'admin_action_refresh' => 'Refrescar',
            'admin_confirm_delete_galaxy' => '¿Seguro que quieres eliminar la galaxia "%s"? Esto eliminará permanentemente TODOS los agujeros de gusano y palabras clave dentro de ella.',
            'admin_msg_no_clusters_search' => 'Ningún cúmulo coincide con esta búsqueda.',
            'admin_msg_no_clusters' => 'Aún no hay cúmulos.',
            'admin_col_theme' => 'Tema',
            'admin_col_members' => 'Miembros',
            'admin_title_idle_spotlight' => 'Foco en reposo activado',
            'admin_title_galaxy_list' => 'Lista de galaxias visible para las visitantes',
            'admin_badge_galaxy_list' => 'Lista de galaxias',
            'admin_confirm_delete_cluster' => '¿Eliminar el cúmulo "%s"? Sus miembros (las galaxias dentro) no se ven afectadas; solo se elimina el cúmulo en sí.',
            'admin_msg_error_loading_clusters' => 'Error al cargar los cúmulos: %s',
            'admin_label_no_prefix_chip' => 'Sin prefijo (%d)',
            'admin_label_wormhole_count' => '%d agujeros de gusano',
            'admin_label_default_inline' => '(predeterminada)',
            'admin_msg_no_galaxies_in_backup' => 'No hay galaxias en este respaldo.',
            'admin_msg_file_selected' => 'Seleccionado: %s (%s)',
            'admin_toast_choose_backup' => 'Primero elige un archivo de respaldo.',
            'admin_toast_inspect_first' => 'Primero inspecciona un archivo.',
            'admin_toast_inspect_failed' => 'Inspección fallida: %s',
            'admin_toast_failed_prefix' => 'Error: %s',
            'admin_toast_nothing_selected' => 'No hay nada seleccionado para restaurar.',
            'admin_confirm_restore' => "¿Restaurar %s en este sistema?\n\nModo de conflicto: %s\n\nEsto no se puede deshacer.",
            'admin_toast_restore_complete' => 'Restauración completa.',
            'admin_toast_restore_failed' => 'Restauración fallida: %s',
            'admin_label_backup_summary' => 'Resumen del archivo de respaldo',
            'admin_text_format_app_created' => 'Formato v%s · App %s · Creado %s',
            'admin_text_summary_counts' => 'Galaxias: %s · Agujeros de gusano: %s · Palabras clave: %s',
            'admin_text_summary_users_media' => 'Usuarias: %s%s · Multimedia: %s archivos (%s MB)',
            'admin_text_no_admin_user_warn' => '(¡sin usuaria administradora!)',
            'admin_label_failures' => 'Fallos:',
            'admin_heading_restore_complete' => 'Restauración completa',
            'admin_text_galaxies_report' => 'Galaxias: creadas %s, sobrescritas %s, renombradas %s, omitidas %s',
            'admin_text_users_report' => 'Usuarias: creadas %s, actualizadas %s, omitidas %s',
            'admin_text_media_report' => 'Archivos multimedia: escritos %s, omitidos %s',
            'admin_label_disabled' => 'Desactivado',
            'admin_label_active' => 'Activo',
            'admin_label_needs_attention' => 'Requiere atención',
            'admin_msg_cron_inactive' => 'El servicio cron del sistema no se está ejecutando (%s). Las instantáneas programadas no se tomarán hasta que cron se inicie.',
            'admin_msg_cron_not_installed' => 'No se pudo registrar el programador con cron. Intenta guardar otra vez.',
            'admin_msg_scheduler_unknown' => 'Estado del programador desconocido.',
            'admin_msg_no_snapshots' => 'Aún no hay instantáneas. Crea una arriba.',
            'admin_col_snapshot_created' => 'Creada (UTC)',
            'admin_col_size' => 'Tamaño',
            'admin_col_type' => 'Tipo',
            'admin_col_creator' => 'Creadora',
            'admin_col_note' => 'Nota',
            'admin_label_file_missing' => '(archivo faltante)',
            'admin_label_creator_system' => 'sistema',
            'admin_action_restore' => 'Restaurar',
            'admin_action_download' => 'Descargar',
            'admin_btn_creating' => 'Creando...',
            'admin_msg_creating_elapsed' => 'Creando instantánea. Tiempo: %ss. Puede tomar un minuto en instancias grandes. Por favor no cierres esta pestaña.',
            'admin_toast_snapshot_created' => 'Instantánea creada en %ss.',
            'admin_toast_create_snapshot_failed' => 'Error al crear la instantánea: %s',
            'admin_confirm_delete_snapshot' => '¿Eliminar esta instantánea? El archivo se eliminará permanentemente del disco.',
            'admin_toast_snapshot_deleted' => 'Instantánea eliminada.',
            'admin_toast_delete_failed' => 'Error al eliminar: %s',
            'admin_prompt_restore_snapshot' => "RESTAURAR BORRARÁ el sistema entero y lo reemplazará con la instantánea del %s.\n\nTodas las instantáneas creadas después de ese punto también se eliminarán.\n\nEscribe RESTORE para confirmar:",
            'admin_toast_confirm_phrase_mismatch' => 'La frase de confirmación no coincide. Restauración cancelada.',
            'admin_confirm_no_admin' => 'ADVERTENCIA: esta instantánea no tiene usuaria administradora. Restaurarla bloqueará el acceso de todas a la consola de administración. ¿Continuar de todos modos?',
            'admin_toast_restore_complete_logout' => 'Restauración completa. Es posible que se cierre tu sesión.',
            'admin_toast_restore_complete_report' => 'Restauración completa. %s galaxias creadas, %s usuarias. %s instantánea(s) posterior(es) eliminadas. Es posible que se cierre tu sesión.',
            'admin_toast_failed_load_galaxies' => 'Error al cargar las galaxias: %s',
            'admin_toast_saved_cron_warning' => 'Guardado, pero el programador no pudo registrarse con cron: %s',
            'admin_toast_schedule_saved' => 'Programación guardada.',
            'admin_toast_save_schedule_failed' => 'Error al guardar la programación: %s',
            // C4: admin/index.php (modales)
            'admin_modal_heading_bulk_users' => 'Importar usuarias en lote',
            'admin_modal_bulk_users_imported_one' => 'Se importó <strong>%d</strong> usuaria.',
            'admin_modal_bulk_users_imported_many' => 'Se importaron <strong>%d</strong> usuarias.',
            'admin_modal_bulk_users_galaxies_created_one' => ' Se creó <strong>%d</strong> galaxia.',
            'admin_modal_bulk_users_galaxies_created_many' => ' Se crearon <strong>%d</strong> galaxias.',
            'admin_modal_bulk_users_skipped_exists_one' => ' Se omitió <strong>%d</strong> correo ya existente.',
            'admin_modal_bulk_users_skipped_exists_many' => ' Se omitieron <strong>%d</strong> correos ya existentes.',
            'admin_modal_bulk_users_skipped_invalid_one' => ' Se omitió <strong>%d</strong> fila inválida.',
            'admin_modal_bulk_users_skipped_invalid_many' => ' Se omitieron <strong>%d</strong> filas inválidas.',
            'admin_modal_bulk_users_mail_failed_one' => ' <strong>%d</strong> correo de configuración no pudo enviarse.',
            'admin_modal_bulk_users_mail_failed_many' => ' <strong>%d</strong> correos de configuración no pudieron enviarse.',
            'admin_modal_bulk_users_col_line' => 'Línea',
            'admin_modal_bulk_users_col_email' => 'Correo',
            'admin_modal_bulk_users_col_outcome' => 'Resultado',
            'admin_modal_bulk_users_col_galaxy' => 'Galaxia',
            'admin_modal_bulk_users_col_note' => 'Nota',
            'admin_modal_bulk_users_col_name' => 'Nombre',
            'admin_modal_bulk_users_col_role' => 'Rol',
            'admin_modal_bulk_users_col_status' => 'Estado',
            'admin_modal_btn_done' => 'Listo',
            'admin_modal_btn_confirm_import' => 'Confirmar importación',
            'admin_modal_btn_preview' => 'Previsualizar',
            'admin_modal_bulk_users_preview_intro' => 'Revisa la lista interpretada. Haz clic en <strong>Confirmar importación</strong> para crear las cuentas nuevas y enviarle a cada una un enlace de configuración de un solo uso.',
            'admin_modal_bulk_users_row_override' => '(anulación de fila)',
            'admin_modal_bulk_users_form_intro' => 'Pega una lista de usuarias, una por línea, con columnas separadas por comas. Solo el correo es obligatorio; todo lo demás es opcional.',
            'admin_modal_bulk_users_field_email' => '<strong>correo</strong>: obligatorio',
            'admin_modal_bulk_users_field_first_name' => '<strong>nombre</strong>',
            'admin_modal_bulk_users_field_last_name' => '<strong>apellido</strong>',
            'admin_modal_bulk_users_field_type' => '<strong>tipo</strong>: <code>Editor</code> (por defecto) o <code>Admin</code>',
            'admin_modal_bulk_users_field_create_galaxy' => '<strong>¿crear galaxia?</strong>: <code>sí</code> / <code>no</code>. Vacío hereda la casilla de abajo; un valor aquí la anula.',
            'admin_modal_bulk_users_example_label' => '<strong>Ejemplo:</strong>',
            'admin_modal_bulk_users_footer_help' => 'Cada nueva usuaria recibe un correo de bienvenida con un enlace de configuración de un solo uso (TTL de 7 días) para establecer su contraseña. Cuando se crea una galaxia para ella, el correo incluye además la URL de la galaxia y el enlace de inicio de sesión. Los correos ya existentes se omiten; las líneas que comienzan con <code>#</code> se ignoran.',
            'admin_modal_bulk_users_textarea_placeholder' => 'correo, nombre, apellido, tipo, crear-galaxia',
            'admin_modal_bulk_users_label_create_galaxy_each' => 'Crear una galaxia para cada nueva usuaria',
            'admin_modal_bulk_users_help_create_galaxy_each' => 'El slug se toma del nombre del correo (antes de <code>@</code>); las colisiones reciben un sufijo aleatorio corto. Las editoras quedan asignadas a su propia galaxia; las administradoras ya ven todas las galaxias. Anula por fila en la 5.ª columna.',
            'admin_modal_heading_create_user' => 'Crear nueva usuaria',
            'admin_modal_label_first_name' => 'Nombre *',
            'admin_modal_help_first_name' => 'El nombre de pila de la usuaria.',
            'admin_modal_label_last_name' => 'Apellido *',
            'admin_modal_help_last_name' => 'El apellido de la usuaria.',
            'admin_modal_label_email' => 'Correo *',
            'admin_modal_err_email_in_use' => 'Este correo ya está en uso.',
            'admin_modal_help_email' => 'Identificador de inicio de sesión y dirección de contacto.',
            'admin_modal_label_password' => 'Contraseña *',
            'admin_modal_help_password_min' => 'Mínimo 8 caracteres.',
            'admin_modal_label_user_type' => 'Tipo de usuaria *',
            'admin_modal_opt_user_type_editor' => 'Editora',
            'admin_modal_opt_user_type_admin' => 'Administradora',
            'admin_modal_help_user_type' => 'Editora: solo puede editar agujeros de gusano en las galaxias asignadas | Administradora: acceso completo a todas las galaxias.',
            'admin_modal_label_create_galaxy_for_user' => 'Crear una galaxia nueva para esta usuaria',
            'admin_modal_help_create_galaxy_for_user' => 'Se crea una galaxia con el nombre de abajo y se le concede acceso a ella (solo editoras).',
            'admin_modal_label_new_galaxy_name' => 'Nombre de la galaxia *',
            'admin_modal_placeholder_new_galaxy_name' => 'Por defecto, el correo de arriba',
            'admin_modal_help_new_galaxy_name' => 'Nombre para la galaxia creada automáticamente.',
            'admin_modal_label_galaxy_access_editors' => 'Acceso a galaxias (solo editoras)',
            'admin_modal_help_galaxy_access_editors' => 'Las editoras solo pueden ver y editar agujeros de gusano en las galaxias marcadas arriba. Las administradoras ven todas las galaxias.',
            'admin_modal_btn_create_user' => 'Crear usuaria',
            'admin_modal_heading_create_galaxy' => 'Crear nueva galaxia',
            'admin_modal_label_galaxy_name' => 'Nombre *',
            'admin_modal_placeholder_galaxy_name' => 'p. ej. Red principal, Archivo',
            'admin_modal_err_name_in_use' => 'Este nombre ya está en uso.',
            'admin_modal_help_galaxy_name' => 'Nombre único para la nueva red de agujeros de gusano.',
            'admin_modal_label_url_slug' => 'Slug de URL',
            'admin_modal_placeholder_url_slug' => 'p. ej. archivo',
            'admin_modal_err_slug_in_use' => 'Este slug ya está en uso.',
            'admin_modal_help_url_slug' => 'Ruta de URL personalizada. Si se deja vacía, se generará a partir del nombre. Solo letras, números y guiones.',
            'admin_modal_label_tagline' => 'Lema',
            'admin_modal_placeholder_tagline' => 'p. ej. Tejiendo memoria',
            'admin_modal_help_tagline' => 'Se muestra en la vista principal cuando esta galaxia está abierta.',
            'admin_modal_label_visual_theme' => 'Tema visual',
            'admin_modal_opt_theme_cosmic' => 'Cósmico (estrellas, planetas, cohetes)',
            'admin_modal_opt_theme_simple' => 'Simple (esferas de colores)',
            'admin_modal_opt_theme_abstract' => 'Abstracto (íconos GIF geométricos)',
            'admin_modal_opt_theme_rectangles' => 'Rectángulos (íconos de rectángulos personalizados)',
            'admin_modal_opt_theme_stripes' => 'Franjas (íconos de franjas personalizadas)',
            'admin_modal_opt_theme_tech' => 'Tecnológico (íconos de circuitos)',
            'admin_modal_help_visual_theme' => 'Determina el fondo, los íconos y las animaciones.',
            'admin_modal_btn_create_galaxy' => 'Crear galaxia',
            'admin_modal_heading_create_cluster' => 'Crear cúmulo',
            'admin_modal_heading_edit_cluster' => 'Editar cúmulo',
            'admin_modal_heading_duplicate_cluster' => 'Duplicar cúmulo',
            'admin_modal_placeholder_cluster_name' => 'p. ej. Rastreando la Tierra',
            'admin_modal_placeholder_cluster_slug' => 'p. ej. rastreando-la-tierra',
            'admin_modal_help_cluster_slug' => 'Las visitantes llegan a <code>/&lt;slug&gt;</code>. Si se deja vacío, se genera a partir del nombre.',
            'admin_modal_placeholder_cluster_tagline' => 'p. ej. Un cúmulo curado',
            'admin_modal_opt_cluster_theme_cosmic' => 'Cósmico',
            'admin_modal_opt_cluster_theme_abstract' => 'Abstracto',
            'admin_modal_opt_cluster_theme_rectangles' => 'Rectángulos',
            'admin_modal_opt_cluster_theme_stripes' => 'Franjas',
            'admin_modal_opt_cluster_theme_tech' => 'Tecnológico',
            'admin_modal_help_cluster_theme' => 'Tema de la escena. El ícono de cada agujero de gusano sigue usando el tema de su galaxia de origen.',
            'admin_modal_label_show_galaxy_list' => 'Mostrar la lista de galaxias a las visitantes',
            'admin_modal_help_show_galaxy_list' => 'Cuando se activa, las visitantes ven una lista de las galaxias miembro del cúmulo en la esquina inferior derecha; al hacer clic se atenúan los agujeros de gusano de otras galaxias. Desactivado por defecto en cúmulos, ya que el encuadre curado suele leerse como una sola experiencia.',
            'admin_modal_label_member_galaxies' => 'Galaxias miembro *',
            'admin_modal_help_member_galaxies' => 'Las visitantes ven la unión de los agujeros de gusano de estas galaxias. Los puentes (líneas discontinuas sutiles) conectan agujeros de gusano que comparten texto de palabra clave entre galaxias.',
            'admin_modal_count_selected_one' => '%d seleccionada',
            'admin_modal_count_selected_many' => '%d seleccionadas',
            'admin_modal_label_keyword_chips' => 'Fichas de palabras clave',
            'admin_modal_help_keyword_chips' => 'Reúne las palabras clave más usadas entre todos los agujeros de gusano visibles (todas las galaxias miembro) en una tira de fichas de filtro en la parte superior del cúmulo. Haz clic en una ficha para atenuar los agujeros de gusano que no coincidan.',
            'admin_modal_label_related_wormholes' => 'Agujeros de gusano relacionados',
            'admin_modal_help_related_wormholes' => 'Cuando la tarjeta de información de un agujero de gusano está abierta, atenúa los no relacionados y muestra hasta 5 agujeros de gusano relacionados (que compartan palabras clave) como fichas de salto en la parte inferior de la tarjeta. Reúne en todo el cúmulo; las fichas pueden surgir de cualquier galaxia miembro.',
            'admin_modal_label_2d_view' => 'Interruptor de vista 2D',
            'admin_modal_help_2d_view' => 'Muestra un conmutador "3D / 2D" en la parte superior central para que las visitantes pasen de la escena 3D a una cuadrícula plana de fichas de agujeros de gusano. La preferencia de cada visitante persiste en su navegador.',
            'admin_modal_label_idle_spotlight' => 'Foco al estar inactiva',
            'admin_modal_help_idle_spotlight' => 'Cuando la visitante está inactiva, la cámara vuela a un agujero de gusano aleatorio en cualquier parte del cúmulo y abre su tarjeta de información. Se cierra cuando termina el contenido o tras el temporizador de permanencia.',
            'admin_modal_label_pick_from' => 'Elegir entre',
            'admin_modal_opt_pick_all_wormholes' => 'Todos los agujeros de gusano (de todas las galaxias miembro)',
            'admin_modal_opt_pick_accentuated' => 'Solo agujeros de gusano destacados',
            'admin_modal_label_trigger_after_seconds' => 'Activar después de (segundos de inactividad)',
            'admin_modal_label_auto_tour' => 'Recorrido automático',
            'admin_modal_title_preview_tour' => 'Guarda primero y luego previsualiza el recorrido en una pestaña nueva',
            'admin_modal_btn_preview_tour' => 'Previsualizar recorrido',
            'admin_modal_help_auto_tour' => 'Lleva automáticamente a las visitantes por agujeros de gusano de todo el cúmulo, abriendo cada tarjeta y reproduciendo el contenido. Solo escritorio e iPad.',
            'admin_modal_label_start_mode' => 'Modo de inicio',
            'admin_modal_opt_start_manual' => 'Manual. La visitante hace clic en un botón de reproducción para iniciar.',
            'admin_modal_opt_start_idle' => 'Inactiva. Inicia después de que la visitante esté inactiva un rato.',
            'admin_modal_opt_start_immediate' => 'Inmediato. Inicia unos segundos después de cargarse el cúmulo.',
            'admin_modal_label_idle_threshold' => 'Umbral de inactividad (segundos)',
            'admin_modal_warn_immediate_audio' => 'Una o más galaxias miembro contienen agujeros de gusano con audio. Los navegadores bloquean la reproducción automática con sonido hasta que la visitante interactúa con la página, así que el primer audio en un recorrido de inicio inmediato puede quedar en silencio o detenerse.',
            'admin_modal_label_which_wormholes' => 'Qué agujeros de gusano recorrer',
            'admin_modal_opt_tour_all' => 'Todos los agujeros de gusano (orden aleatorio en cada ejecución)',
            'admin_modal_opt_tour_accentuated' => 'Solo agujeros de gusano destacados',
            'admin_modal_opt_tour_random_n' => 'Una muestra aleatoria de N agujeros de gusano',
            'admin_modal_opt_tour_tagged' => 'Agujeros de gusano etiquetados con alguna de estas palabras clave',
            'admin_modal_label_random_count' => 'Cuántos agujeros de gusano por recorrido',
            'admin_modal_label_tour_keywords' => 'Palabras clave (cualquier coincidencia, separadas por comas)',
            'admin_modal_placeholder_tour_keywords' => 'p. ej. Ideología, Resistencia, Tierra',
            'admin_modal_help_tour_keywords' => 'Coincide por nombre de palabra clave (sin distinguir mayúsculas) en todas las galaxias miembro. Útil cuando la misma etiqueta (p. ej. <code>Ideología</code>) existe en varias galaxias pero con identificadores distintos.',
            'admin_modal_label_dwell_seconds' => 'Pausa en agujeros de gusano sin contenido (segundos)',
            'admin_modal_label_loop_tour' => 'Repetir el recorrido al terminar',
            'admin_modal_btn_create_cluster' => 'Crear cúmulo',
            'admin_modal_btn_update_cluster' => 'Actualizar cúmulo',
            'admin_modal_name_copy_suffix' => ' (Copia)',
            'admin_modal_heading_edit_user' => 'Editar usuaria',
            'admin_modal_label_password_optional' => 'Contraseña (déjala vacía para conservar la actual)',
            'admin_modal_btn_update_user' => 'Actualizar usuaria',
            'admin_modal_heading_duplicate_galaxy' => 'Duplicar galaxia',
            'admin_modal_label_duplicating' => 'Duplicando:',
            'admin_modal_label_new_name' => 'Nuevo nombre *',
            'admin_modal_label_new_url_slug' => 'Nuevo slug de URL',
            'admin_modal_label_new_tagline' => 'Nuevo lema',
            'admin_modal_btn_duplicate' => 'Duplicar',
            'admin_modal_heading_confirm_deletion' => 'Confirmar eliminación',
            'admin_modal_label_type_galaxy_name' => 'Escribe el nombre de la galaxia para confirmar:',
            'admin_modal_placeholder_type_name' => 'Escribe el nombre aquí...',
            'admin_modal_btn_delete' => 'Eliminar',
            'admin_modal_deletion_impact_title' => '⚠️ Impacto de la eliminación:',
            'admin_modal_deletion_impact_intro' => 'Los siguientes portales en otras galaxias apuntan a esta red y también se eliminarán:',
            'admin_modal_deletion_impact_row' => '<strong>%s</strong> (en la galaxia: %s)',
        ],
        'pt' => [
            'name' => 'Telaris', 'description' => 'Tecendo memória', 'iframe_back_text' => 'Voltar', 
            'alert_message' => "Você está atravessando para a Dimensão Planar\nPara explorar, use o zoom e role em todas as direções\nFeche a janela do navegador para retornar à Dimensão Cósmica.", 
            'edit_button_text' => 'Editar', 'loading_text' => 'Carregando',
            'back_button_text' => 'Voltar', 'system_online_text' => 'Online',
            'reload_system_text' => 'Recarregar', 'scan_system_text' => 'BUSCAR...',
            'clear_scan_text' => 'Limpar Busca', 'systems_label_text' => 'Buracos de Minhoca:',
            'hyperlinks_label_text' => 'Hiperlinks:', 'initialize_auth_text' => 'Entrar',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Sair',
            'click_to_view_text' => 'Clique para ver', 'tap_to_view_text' => 'Toque novamente para ver',
            'open_portal_text' => 'Abrir o Portal',
            'sound_label_text' => 'Som:', 'sound_on_text' => 'SIM', 'sound_off_text' => 'NÃO',
            'launching_text' => 'Lançando', 'mission_active_text' => 'Missão Ativa', 'go_text' => 'VAI',
            'breadcrumb_all_text' => 'Tudo', 'launch_button_text' => 'LANÇAR',
            'no_results_text' => 'Sem resultados', 'items_label_text' => 'itens', 'other_label_text' => 'Outros',
            'galaxies_label_text' => 'Galáxias',
            'galaxy_count_singular_text' => '1 galáxia',
            'galaxy_count_plural_text' => '%d galáxias',
            'pdf_loading_text' => 'Carregando PDF…',
            'pdf_rendering_text' => 'Processando páginas…',
            'pdf_pages_singular_text' => '1 página',
            'pdf_pages_plural_text' => '%d páginas',
            'pdf_open_text' => 'Abrir em outra aba',
            'pdf_download_text' => 'Baixar',
            'pdf_error_load_text' => 'Falha ao carregar a biblioteca de PDF.',
            'pdf_error_open_text' => 'Não foi possível abrir o PDF.',
            'tour_label_text' => 'Tour',
            'tour_start_aria_text' => 'Iniciar tour',
            'tour_previous_aria_text' => 'Anterior',
            'tour_pause_aria_text' => 'Pausar',
            'tour_next_aria_text' => 'Próximo',
            'tour_exit_aria_text' => 'Sair do tour',
            'nav_toggle_aria_text' => 'Alternar menu de navegação',
            'share_link_title_text' => 'Copiar link para este buraco de minhoca',
            'related_label_text' => 'Relacionados',
            'lang_label_text' => 'Idioma:',
            'node_name_fallback_text' => 'Sistema',
            'untitled_text' => 'Sem título',
            'chip_open_prefix_text' => 'Abrir',
            'search_result_text' => 'Resultado',
            'search_results_text' => 'Resultados',
            // Editor chunk C1 (edit/index.php)
            'editor_page_title' => 'Editar buracos de minhoca',
            'editor_user_role_admin' => 'Administradora',
            'editor_user_role_editor' => 'Editora',
            'editor_label_current_galaxy' => 'Galáxia atual:',
            'editor_option_all_galaxies_admin' => 'Todas as galáxias',
            'editor_option_all_galaxies_editor' => 'Todas as minhas galáxias',
            'editor_btn_view' => 'Ver',
            'editor_btn_galaxy_settings_title' => 'Configurações da galáxia',
            'editor_btn_settings' => 'Configurações',
            'editor_btn_keyword_canvas_title' => 'Criar relações entre palavras-chave',
            'editor_btn_canvas' => 'Tela',
            'editor_btn_copy_url_title' => 'Copiar URL da galáxia',
            'editor_btn_admin_console' => 'Console de administração',
            'editor_btn_logout' => 'Sair',
            'editor_error_no_api_key' => '⚠️ Erro: nenhuma chave de API ativa encontrada. Entre em contato com uma administradora.',
            'editor_bulk_selected_suffix' => 'buracos de minhoca selecionados',
            'editor_btn_clear_selection' => 'Limpar seleção',
            'editor_btn_bulk_move' => 'Mover selecionados',
            'editor_btn_bulk_duplicate' => 'Duplicar selecionados',
            'editor_btn_bulk_delete' => 'Excluir selecionados',
            'editor_banner_imported_read_only' => 'Esta galáxia foi importada de uma fonte externa e é apenas leitura. Use a ação Atualizar na lista de galáxias do painel de administração para sincronizar mudanças.',
            'editor_heading_wormholes' => 'Buracos de minhoca',
            'editor_btn_new_wormhole' => 'Novo buraco de minhoca',
            'editor_btn_touched_today_title' => 'Mostrar apenas buracos de minhoca modificados hoje',
            'editor_btn_touched_today' => 'Modificados hoje',
            'editor_btn_bulk_keyword_title' => 'Excluir ou mover em massa todo buraco de minhoca desta galáxia que tenha uma palavra-chave específica',
            'editor_btn_bulk_by_keyword' => 'Ação em massa por palavra-chave…',
            'editor_btn_shortcuts_title' => 'Atalhos do teclado (? para abrir)',
            'editor_label_search' => 'Buscar:',
            'editor_placeholder_search_wormholes' => 'Buscar buracos de minhoca...',
            'editor_col_name' => 'Nome',
            'editor_col_type' => 'Tipo',
            'editor_col_galaxy' => 'Galáxia',
            'editor_col_url' => 'URL',
            'editor_col_keywords' => 'Palavras-chave',
            'editor_col_created' => 'Criação',
            'editor_col_updated' => 'Atualização',
            'editor_col_actions' => 'Ações',
            'editor_col_acc' => 'Ac',
            'editor_col_acc_title' => 'Estado de acentuação',
            'editor_msg_loading_wormholes' => 'Carregando buracos de minhoca...',
            'editor_msg_retrieving_wormholes' => 'Obtendo buracos de minhoca...',
            'editor_heading_no_wormholes' => 'Nenhum buraco de minhoca encontrado.',
            'editor_text_empty_state_help' => 'Ajuste a busca ou crie um novo buraco de minhoca para começar.',
            'editor_heading_error_loading' => 'Erro ao carregar buracos de minhoca',
            'editor_error_api_key_missing' => 'A chave de API está ausente.',
            'editor_error_api_key_missing_fetch' => 'Erro: a chave de API está ausente. Entre em contato com uma administradora.',
            'editor_error_invalid_json' => 'Resposta JSON inválida do servidor',
            'editor_error_invalid_format' => 'Formato de resposta inválido',
            'editor_error_invalid_data_format' => 'Erro: formato de dados inválido recebido.',
            'editor_text_no_keywords' => 'Sem palavras-chave',
            'editor_label_node_type_portal' => 'Portal',
            'editor_label_node_type_object' => 'Objeto',
            'editor_badge_accentuated' => 'AC',
            'editor_badge_accentuated_title' => 'Buraco de minhoca acentuado',
            'editor_badge_has_url' => 'URL',
            'editor_badge_has_url_title' => 'Tem URL',
            'editor_badge_has_desc' => 'DESC',
            'editor_badge_has_desc_title' => 'Tem descrição',
            'editor_badge_has_img' => 'IMG',
            'editor_badge_has_img_title' => 'Tem imagem',
            'editor_badge_has_emb' => 'EMB',
            'editor_badge_has_emb_title' => 'Tem incorporação',
            'editor_badge_has_aud' => 'AUD',
            'editor_badge_has_aud_title' => 'Tem áudio',
            'editor_badge_has_vid' => 'VID',
            'editor_badge_has_vid_title' => 'Tem vídeo',
            'editor_title_accentuated' => 'Acentuado',
            'editor_action_view_wormhole' => 'Ver buraco de minhoca',
            'editor_action_view_galaxy' => 'Ver galáxia',
            'editor_action_edit' => 'Editar',
            'editor_action_duplicate' => 'Duplicar',
            'editor_action_delete' => 'Excluir',
            'editor_toast_bulk_move_success' => '%d buracos de minhoca movidos.',
            'editor_toast_bulk_move_failed' => 'Falha ao mover %d buracos de minhoca.',
            'editor_toast_bulk_move_error' => 'Ocorreu um erro durante a movimentação em massa.',
            'editor_toast_duplicate_success' => 'Buraco de minhoca duplicado com sucesso.',
            'editor_error_failed_duplicate' => 'Falha ao duplicar',
            'editor_toast_duplicate_error_generic' => 'Ocorreu um erro ao duplicar.',
            'editor_toast_bulk_duplicate_success' => '%d buracos de minhoca duplicados.',
            'editor_toast_bulk_duplicate_failed' => 'Falha ao duplicar %d buracos de minhoca.',
            'editor_toast_bulk_duplicate_error' => 'Ocorreu um erro durante a duplicação em massa.',
            'editor_confirm_bulk_delete' => 'Tem certeza de que deseja excluir %d buracos de minhoca? Esta ação não pode ser desfeita.',
            'editor_toast_bulk_delete_success' => '%d buracos de minhoca excluídos.',
            'editor_toast_bulk_delete_failed' => 'Falha ao excluir %d buracos de minhoca.',
            'editor_toast_bulk_delete_error' => 'Ocorreu um erro durante a exclusão em massa.',
            'editor_toast_url_copied' => 'URL copiada para a área de transferência',
            'editor_title_url_copied' => 'Copiada!',
            'editor_toast_galaxy_created' => 'Galáxia "%s" criada.',
            'editor_toast_error_creating_galaxy' => 'Erro ao criar a galáxia: %s',
            'editor_prompt_new_galaxy_name' => 'Nome da nova galáxia:',
            'editor_modal_heading_add_wormhole' => 'Adicionar novo buraco de minhoca',
            'editor_modal_heading_edit_wormhole' => 'Editar buraco de minhoca',
            'editor_label_name_required' => 'Nome *',
            'editor_error_name_exists' => 'Este nome já existe nesta galáxia.',
            'editor_help_name' => 'Título principal do buraco de minhoca exibido na rede.',
            'editor_label_galaxy' => 'Galáxia',
            'editor_help_constellation' => 'A qual galáxia este buraco de minhoca pertence.',
            'editor_label_wormhole_type' => 'Tipo de buraco de minhoca',
            'editor_help_node_type' => 'Objeto é um item padrão; Portal leva a outra galáxia.',
            'editor_label_keywords' => 'Palavras-chave',
            'editor_placeholder_add_keyword' => 'Adicionar palavra-chave...',
            'editor_help_keywords_add' => 'Digite e pressione Enter ou vírgula para adicionar palavras-chave. As sugestões mostram palavras-chave já usadas nesta galáxia e em galáxias irmãs que compartilhem seu prefixo `[XX]`.',
            'editor_label_accentuate_wormhole' => 'Acentuar buraco de minhoca',
            'editor_help_accentuate' => 'Faz com que este buraco de minhoca apareça maior e em destaque na rede.',
            'editor_label_show_keywords' => 'Mostrar palavras-chave',
            'editor_help_show_keywords' => 'Exibe as palavras-chave deste buraco de minhoca na sua janela de informações.',
            'editor_label_target_galaxy' => 'Galáxia destino',
            'editor_help_target_galaxy' => 'A galáxia destino para a qual este portal leva.',
            'editor_btn_create_new_galaxy' => 'Criar nova galáxia',
            'editor_label_description' => 'Descrição',
            'editor_help_description' => 'Texto detalhado exibido quando o buraco de minhoca é selecionado.',
            'editor_label_url' => 'URL',
            'editor_placeholder_url' => 'https://example.com',
            'editor_help_url' => 'URL aberta ao clicar no buraco de minhoca (opcional).',
            'editor_label_primary_visual' => 'Visual principal',
            'editor_tab_image' => 'Imagem',
            'editor_tab_video' => 'Vídeo (MP4)',
            'editor_tab_pdf' => 'PDF',
            'editor_help_visual_mutex' => 'Escolha uma. Ao trocar de aba e salvar, as outras são apagadas.',
            'editor_label_image_url_file' => 'URL ou arquivo de imagem',
            'editor_label_use_as_icon' => 'Usar como ícone do buraco de minhoca',
            'editor_placeholder_image_url' => 'https://example.com/image.jpg',
            'editor_placeholder_video_url' => 'https://example.com/video.mp4',
            'editor_label_autoplay_video' => 'Reproduzir vídeo automaticamente',
            'editor_placeholder_pdf_url' => 'https://example.com/document.pdf',
            'editor_help_pdf' => 'Envie um PDF ou cole um link.',
            'editor_placeholder_credit' => 'Crédito ou atribuição...',
            'editor_help_credit' => 'Crédito opcional exibido sobre o visual na caixa de informações (imagem, vídeo ou PDF).',
            'editor_label_icon_url_file' => 'URL ou arquivo de ícone',
            'editor_placeholder_icon_url' => 'https://example.com/icon.png',
            'editor_help_icon' => 'Ícone personalizado exibido na cena 3D (substitui o ícone do tema).',
            'editor_label_audio_url_file' => 'URL ou arquivo de áudio',
            'editor_placeholder_audio_url' => 'https://example.com/audio.mp3',
            'editor_label_autoplay' => 'Reproduzir automaticamente',
            'editor_label_loop' => 'Em loop',
            'editor_help_audio' => 'Independente do visual principal: o áudio pode acompanhar imagem, vídeo ou PDF.',
            'editor_text_uploading' => 'Enviando...',
            'editor_btn_add_wormhole' => 'Adicionar buraco de minhoca',
            'editor_btn_cancel' => 'Cancelar',
            'editor_divider_media' => 'Mídia',
            'editor_btn_delete_file' => 'Excluir',
            'editor_btn_update_wormhole' => 'Atualizar buraco de minhoca',
            'editor_modal_heading_confirm_delete' => 'Confirmar exclusão',
            'editor_btn_delete' => 'Excluir',
            'editor_modal_heading_move_wormholes' => 'Mover buracos de minhoca',
            'editor_text_move_count_wormholes' => 'Mover %d buracos de minhoca selecionados para outra galáxia.',
            'editor_label_destination_galaxy' => 'Galáxia destino',
            'editor_btn_move_wormholes' => 'Mover buracos de minhoca',
            'editor_modal_heading_duplicate_wormhole' => 'Duplicar buraco de minhoca',
            'editor_text_duplicate_to' => 'Duplicar "%s" em:',
            'editor_btn_duplicate' => 'Duplicar',
            'editor_modal_heading_duplicate_wormholes' => 'Duplicar buracos de minhoca',
            'editor_text_duplicate_count_wormholes' => 'Duplicar %d buracos de minhoca selecionados em:',
            'editor_btn_duplicate_wormholes' => 'Duplicar buracos de minhoca',
            'editor_btn_open_link' => 'Abrir link',
            'editor_btn_apply' => 'Aplicar',
            'editor_label_target_prefix' => 'Destino:',
            'editor_modal_heading_bulk_keyword' => 'Ação em massa por palavra-chave',
            'editor_text_bulk_keyword_help' => 'Escolha uma palavra-chave na galáxia atual. Depois decida se quer excluir todos os buracos de minhoca que a contêm ou movê-los para outra galáxia.',
            'editor_label_keyword' => 'Palavra-chave',
            'editor_option_loading' => 'Carregando…',
            'editor_label_action' => 'Ação',
            'editor_option_delete_matching' => 'Excluir os buracos de minhoca correspondentes',
            'editor_option_move_matching' => 'Movê-los para outra galáxia',
            'editor_text_pick_keyword' => 'Escolha uma palavra-chave para ver o total.',
            'editor_error_pick_specific_galaxy' => 'Escolha primeiro uma galáxia específica (não "Todas as galáxias").',
            'editor_option_no_keywords' => '(sem palavras-chave nesta galáxia)',
            'editor_option_pick_one' => 'escolha uma',
            'editor_option_error_keywords' => 'Erro ao carregar palavras-chave',
            'editor_option_pick_galaxy' => 'escolha uma galáxia',
            'editor_preview_move_one' => 'Será movido 1 buraco de minhoca para a galáxia escolhida.',
            'editor_preview_move_many' => 'Serão movidos %d buracos de minhoca para a galáxia escolhida.',
            'editor_preview_move_pick_target_one' => 'Será movido 1 buraco de minhoca. Escolha primeiro uma galáxia destino.',
            'editor_preview_move_pick_target_many' => 'Serão movidos %d buracos de minhoca. Escolha primeiro uma galáxia destino.',
            'editor_preview_delete_one' => '1 buraco de minhoca será excluído permanentemente.',
            'editor_preview_delete_many' => '%d buracos de minhoca serão excluídos permanentemente.',
            'editor_confirm_bulk_delete_keyword_one' => 'Excluir permanentemente 1 buraco de minhoca com a palavra-chave "%s"? Esta ação não pode ser desfeita.',
            'editor_confirm_bulk_delete_keyword_many' => 'Excluir permanentemente %d buracos de minhoca com a palavra-chave "%s"? Esta ação não pode ser desfeita.',
            'editor_confirm_bulk_move_keyword_one' => 'Mover 1 buraco de minhoca com a palavra-chave "%s" para a galáxia selecionada?',
            'editor_confirm_bulk_move_keyword_many' => 'Mover %d buracos de minhoca com a palavra-chave "%s" para a galáxia selecionada?',
            'editor_toast_bulk_deleted_one' => '1 buraco de minhoca excluído.',
            'editor_toast_bulk_deleted_many' => '%d buracos de minhoca excluídos.',
            'editor_toast_bulk_moved_one' => '1 buraco de minhoca movido.',
            'editor_toast_bulk_moved_many' => '%d buracos de minhoca movidos.',
            'editor_toast_bulk_action_failed' => 'A ação em massa falhou: %s',
            'editor_modal_heading_shortcuts' => 'Atalhos do teclado',
            'editor_shortcut_new_wormhole' => 'Novo buraco de minhoca',
            'editor_shortcut_focus_search' => 'Focar o campo de busca',
            'editor_shortcut_toggle_touched' => 'Alternar filtro "Modificados hoje"',
            'editor_shortcut_galaxy_settings' => 'Abrir configurações da galáxia (galáxia atual)',
            'editor_shortcut_close_modal' => 'Fechar qualquer modal aberto',
            'editor_shortcut_open_help' => 'Abrir esta ajuda',
            'editor_note_shortcuts_typing' => 'Os atalhos são ignorados enquanto você digita em um campo de texto.',
            'editor_btn_close' => 'Fechar',
            'editor_toast_updated_successfully' => 'Buraco de minhoca atualizado com sucesso',
            'editor_toast_created_successfully' => 'Buraco de minhoca criado com sucesso',
            'editor_error_failed_update' => 'Falha ao atualizar o buraco de minhoca',
            'editor_error_failed_create' => 'Falha ao criar o buraco de minhoca',
            'editor_error_network_upload' => 'Erro de rede durante o envio',
            'editor_error_name_required' => 'O nome do buraco de minhoca é obrigatório',
            'editor_error_loading_node' => 'Erro ao carregar o buraco de minhoca: %s',
            'editor_confirm_delete_file' => 'Tem certeza de que deseja excluir este arquivo %s enviado?',
            'editor_toast_file_deleted' => 'Arquivo %s excluído',
            'editor_error_deleting_file' => 'Erro ao excluir o arquivo: %s',
            'editor_confirm_delete_node' => 'Tem certeza de que deseja excluir "%s"? Esta ação não pode ser desfeita.',
            'editor_error_delete_wormhole' => 'Falha ao excluir o buraco de minhoca',
            'editor_toast_deleted_successfully' => 'Buraco de minhoca excluído com sucesso',
            'editor_error_deleting_wormhole' => 'Erro ao excluir o buraco de minhoca: %s',
            'editor_error_fatal_loading' => 'Erro fatal ao carregar buracos de minhoca: %s',
            'editor_error_could_not_load' => 'Erro: não foi possível carregar os buracos de minhoca. %s',
            'editor_kc_status_loading' => 'Carregando…',
            'editor_kc_status_no_keywords' => 'Ainda não há palavras-chave',
            'editor_kc_status_ready' => 'Pronto',
            'editor_kc_status_saving' => 'Salvando…',
            'editor_kc_status_saved' => 'Salvo',
            'editor_kc_status_deleting' => 'Excluindo…',
            'editor_kc_status_deleted' => 'Excluído',
            'editor_kc_status_merging' => 'Mesclando…',
            'editor_kc_status_merged' => 'Mesclado',
            'editor_kc_status_renamed' => 'Renomeado',
            'editor_kc_status_already_related' => 'Já estão relacionadas',
            'editor_kc_status_drag_or_click' => 'Arraste até outro ponto de ancoragem, ou clique em um (Esc para cancelar)',
            'editor_kc_status_load_failed' => 'Erro ao carregar: %s',
            'editor_kc_status_save_failed' => 'Erro ao salvar: %s',
            'editor_kc_status_create_failed' => 'Erro ao criar: %s',
            'editor_kc_status_delete_failed' => 'Erro ao excluir: %s',
            'editor_kc_status_rename_failed' => 'Erro ao renomear: %s',
            'editor_kc_status_merge_failed' => 'Erro ao mesclar: %s',
            'editor_kc_status_update_failed' => 'Erro ao atualizar: %s',
            'editor_kc_modal_title_new_relation' => 'Nova relação',
            'editor_kc_modal_title_edit_relation' => 'Editar nota da relação',
            'editor_kc_label_authored_by' => 'Criada por %s',
            'editor_kc_label_no_author_recorded' => 'Sem autoria registrada',
            'editor_kc_label_no_author_short' => '(sem autoria)',
            'editor_kc_err_empty_name' => 'Escolha um nome não vazio.',
            'editor_kc_err_name_taken_galaxy' => 'Esse nome já está em uso nesta galáxia',
            'editor_kc_err_name_taken_conflict' => 'Esse nome já está em uso; mude-o ou mescle.',
            'editor_kc_err_missing_config' => 'Falta a configuração da página (window.TELARIS_KC)',
            'editor_gxm_status_loading_keywords' => 'Carregando…',
            'editor_gxm_no_keywords_yet' => 'Ainda não há palavras-chave para esta galáxia.',
            'editor_gxm_load_failed_keywords' => 'Erro ao carregar.',
            'editor_gxm_label_use_images_as_icons' => 'usar imagens como ícones',
            'editor_gxm_label_revert_to_theme_icons' => 'restaurar todos os ícones do tema',
            'editor_gxm_confirm_apply_to_all' => 'Aplicar "%s" a cada buraco de minhoca desta galáxia?',
            'editor_gxm_status_working' => 'Processando…',
            'editor_gxm_status_updated_one' => '%d buraco de minhoca atualizado. Recarregue a visão da visitante para ver a mudança.',
            'editor_gxm_status_updated_many' => '%d buracos de minhoca atualizados. Recarregue a visão da visitante para ver a mudança.',
            'editor_gxm_label_failed_prefix' => 'Erro: %s',
            'editor_gxm_err_update_failed_fallback' => 'Erro ao atualizar',
            // C3: admin/index.php
            'admin_loading_console' => 'Carregando console de administração...',
            'admin_heading_console' => 'Console de administração',
            'admin_label_welcome' => 'Bem-vinda, %s',
            'admin_btn_edit_content' => 'Editar conteúdo',
            'admin_btn_logout' => 'Sair',
            'admin_msg_api_key_generated_title' => '✓ Chave de API gerada',
            'admin_msg_api_key_generated_body' => 'Sua chave de API: %s (Nome: %s). COPIE-A AGORA.',
            'admin_msg_settings_saved' => 'Configurações globais salvas.',
            'admin_tab_galaxies' => 'Galáxias',
            'admin_tab_clusters' => 'Aglomerados',
            'admin_tab_users' => 'Usuárias',
            'admin_tab_backup' => 'Backup',
            'admin_tab_snapshots' => 'Snapshots',
            'admin_tab_settings' => 'Configurações globais',
            'admin_tab_api_keys' => 'Chaves de API',
            'admin_tab_php_info' => 'Informação do PHP',
            'admin_heading_users' => 'Usuárias',
            'admin_btn_new_user' => 'Nova usuária',
            'admin_btn_bulk_import' => 'Importação em lote',
            'admin_label_search' => 'Buscar:',
            'admin_placeholder_search_users' => 'Buscar usuárias...',
            'admin_msg_no_users' => 'Nenhuma usuária encontrada.',
            'admin_col_user_name' => 'Nome',
            'admin_col_user_email' => 'E-mail',
            'admin_col_user_type' => 'Tipo',
            'admin_col_user_created' => 'Criada',
            'admin_col_user_last_login' => 'Último login',
            'admin_col_user_last_updated' => 'Última atualização',
            'admin_col_actions' => 'Ações',
            'admin_user_type_regular' => 'Regular',
            'admin_user_type_editor' => 'Editora',
            'admin_user_type_admin' => 'Administradora',
            'admin_badge_you' => 'Você',
            'admin_label_never' => 'Nunca',
            'admin_action_edit' => 'Editar',
            'admin_action_delete' => 'Excluir',
            'admin_confirm_delete_user' => 'Tem certeza de que deseja excluir a usuária "%s"? Esta ação não pode ser desfeita.',
            'admin_heading_generate_api_key' => 'Gerar nova chave de API',
            'admin_label_api_key_name' => 'Nome *',
            'admin_placeholder_api_key_name' => 'p. ex., App de frontend, App móvel, Admin',
            'admin_help_api_key_name' => 'Um nome descritivo para esta chave de API',
            'admin_label_api_key_description' => 'Descrição',
            'admin_placeholder_api_key_description' => 'Descrição opcional do uso desta chave',
            'admin_btn_generate_api_key' => 'Gerar chave de API',
            'admin_btn_cancel' => 'Cancelar',
            'admin_heading_api_keys' => 'Chaves de API',
            'admin_btn_new_api_key' => 'Nova chave de API',
            'admin_msg_no_api_keys' => 'Ainda não foram geradas chaves de API.',
            'admin_badge_inactive' => 'Inativa',
            'admin_action_deactivate' => 'Desativar',
            'admin_action_activate' => 'Ativar',
            'admin_confirm_delete_api_key' => 'Tem certeza de que deseja excluir esta chave de API? Esta ação não pode ser desfeita.',
            'admin_label_created' => 'Criada:',
            'admin_label_last_used' => 'Último uso:',
            'admin_label_last_updated' => 'Última atualização:',
            'admin_heading_galaxies' => 'Galáxias',
            'admin_btn_new_galaxy' => 'Nova galáxia',
            'admin_placeholder_search_galaxies' => 'Buscar galáxias...',
            'admin_help_galaxies_default' => 'Cada galáxia é um conjunto independente de buracos de minhoca e palavras-chave. A galáxia padrão atual, %s, não pode ser excluída.',
            'admin_help_galaxies_settings_link' => 'Você pode alterar a galáxia padrão na aba %s.',
            'admin_toast_url_copied' => 'URL copiada para a área de transferência.',
            'admin_heading_clusters' => 'Aglomerados de galáxias',
            'admin_btn_new_cluster' => 'Novo aglomerado',
            'admin_placeholder_search_clusters' => 'Buscar aglomerados...',
            'admin_help_clusters' => 'Um aglomerado é uma união curada de galáxias com seu próprio slug, título, tema e link permanente. Aglomerados não têm buracos de minhoca próprios; eles renderizam a união de seus membros através do pipeline multigaláxia.',
            'admin_help_settings' => 'Configurações que se aplicam a toda a instância da aplicação principal.',
            'admin_label_version' => 'Versão',
            'admin_label_default_galaxy' => 'Galáxia padrão',
            'admin_help_default_galaxy' => 'Escolha qual galáxia é exibida na raiz do site.',
            'admin_label_pdf_max' => 'Tamanho máximo de PDF (MB)',
            'admin_help_pdf_max' => "Maior PDF que um buraco de minhoca pode conter. Padrão 25 MB. Editoras que enviarem arquivos maiores verão o erro 'O arquivo excede o tamanho máximo permitido'.",
            'admin_btn_save_settings' => 'Salvar configurações',
            'admin_heading_download_backup' => 'Baixar um backup',
            'admin_help_download_backup' => 'Crie um arquivo de backup portátil com galáxias e/ou usuárias. A opção padrão produz um backup completo com mídia incorporada.',
            'admin_label_galaxies' => 'Galáxias',
            'admin_label_all_galaxies' => 'Todas as galáxias',
            'admin_label_selected_galaxies' => 'Apenas galáxias selecionadas',
            'admin_msg_loading_galaxies' => 'Carregando galáxias...',
            'admin_btn_select_all' => 'Selecionar tudo',
            'admin_btn_clear' => 'Limpar',
            'admin_label_users_always_all' => 'Usuárias (sempre todas)',
            'admin_help_users_export' => 'As senhas das usuárias são exportadas como hashes. Nunca aparecem em texto plano.',
            'admin_label_media_files' => 'Arquivos de mídia',
            'admin_label_media_embedded' => 'Incorporados: backup autocontido (recomendado)',
            'admin_label_media_refs' => 'Apenas referências: arquivo menor, restaurável só no mesmo servidor',
            'admin_label_media_none' => 'Nenhum: descartar toda a mídia',
            'admin_btn_download_backup' => 'Baixar backup',
            'admin_heading_restore_backup' => 'Restaurar a partir de um backup',
            'admin_help_restore_backup' => 'Envie um arquivo .telaris-backup. Você verá um resumo antes que qualquer mudança seja aplicada.',
            'admin_btn_inspect_file' => 'Inspecionar arquivo',
            'admin_label_galaxies_in_file' => 'Galáxias neste arquivo',
            'admin_label_for_each_galaxy' => 'Para cada galáxia selecionada',
            'admin_label_overwrite_slug' => 'Sobrescrever se já existe uma galáxia com o mesmo slug',
            'admin_label_create_as_new' => 'Criar como nova (renomear em caso de conflito, sufixo:',
            'admin_label_users_in_file' => 'Usuárias neste arquivo',
            'admin_label_restore_users' => 'Restaurar usuárias',
            'admin_label_skip_existing' => 'Pular usuárias existentes (combinar por e-mail)',
            'admin_label_update_existing' => 'Atualizar usuárias existentes por e-mail',
            'admin_label_overwrite_pw' => 'Também sobrescrever os hashes de senha',
            'admin_label_restore_media' => 'Restaurar arquivos de mídia',
            'admin_btn_restore' => 'Restaurar',
            'admin_help_snapshots' => 'Snapshots são backups completos locais, em disco, do sistema inteiro. Restaurar um snapshot apaga tudo e substitui pelo estado do snapshot. Quaisquer snapshots criados após o restaurado são excluídos.',
            'admin_heading_create_snapshot' => 'Criar snapshot agora',
            'admin_placeholder_snapshot_note' => 'Nota opcional (p. ex. antes da migração)',
            'admin_btn_create_snapshot' => 'Criar snapshot',
            'admin_msg_creating_snapshot' => 'Criando snapshot. Pode levar um minuto em instâncias grandes. Por favor, não feche esta aba.',
            'admin_heading_snapshot_scheduler' => 'Agendador de snapshots',
            'admin_label_enable_daily' => 'Ativar snapshots diários',
            'admin_label_hour_utc' => 'Hora (UTC)',
            'admin_label_keep_days' => 'Dias a manter (auto)',
            'admin_btn_save' => 'Salvar',
            'admin_btn_refresh_status' => 'Atualizar status',
            'admin_label_status' => 'Status:',
            'admin_label_last_snapshot' => 'Último snapshot:',
            'admin_label_last_checked' => 'Última verificação:',
            'admin_label_status_loading' => 'carregando...',
            'admin_label_never_lower' => 'nunca',
            'admin_label_recent_activity' => 'Atividade recente',
            'admin_msg_no_activity' => '(ainda sem atividade)',
            'admin_heading_available_snapshots' => 'Snapshots disponíveis',
            'admin_msg_loading' => 'Carregando...',
            'admin_heading_php_config' => 'Configuração do PHP',
            'admin_heading_important_extensions' => 'Extensões importantes',
            'admin_heading_all_extensions' => 'Todas as extensões carregadas',
            'admin_msg_no_galaxies' => 'Nenhuma galáxia encontrada.',
            'admin_col_id' => 'ID',
            'admin_col_galaxy_name' => 'Nome',
            'admin_col_slug' => 'Slug',
            'admin_col_tagline' => 'Lema',
            'admin_col_wormholes' => 'Buracos de minhoca',
            'admin_col_created' => 'Criada',
            'admin_col_last_updated' => 'Última atualização',
            'admin_badge_default' => 'Padrão',
            'admin_badge_imported' => 'Importada',
            'admin_title_tour_enabled' => 'Tour automático ativado',
            'admin_msg_error_loading_galaxies' => 'Erro ao carregar as galáxias: %s',
            'admin_action_view' => 'Visualizar',
            'admin_action_copy_url' => 'Copiar URL',
            'admin_action_keyword_canvas' => 'Tela de palavras-chave',
            'admin_action_duplicate' => 'Duplicar',
            'admin_action_refresh' => 'Atualizar',
            'admin_confirm_delete_galaxy' => 'Tem certeza de que deseja excluir a galáxia "%s"? Isso removerá permanentemente TODOS os buracos de minhoca e palavras-chave dentro dela.',
            'admin_msg_no_clusters_search' => 'Nenhum aglomerado corresponde a esta busca.',
            'admin_msg_no_clusters' => 'Ainda não há aglomerados.',
            'admin_col_theme' => 'Tema',
            'admin_col_members' => 'Membros',
            'admin_title_idle_spotlight' => 'Foco em repouso ativado',
            'admin_title_galaxy_list' => 'Lista de galáxias visível para as visitantes',
            'admin_badge_galaxy_list' => 'Lista de galáxias',
            'admin_confirm_delete_cluster' => 'Excluir o aglomerado "%s"? Seus membros (as galáxias dentro) não são afetadas; apenas o aglomerado em si é removido.',
            'admin_msg_error_loading_clusters' => 'Erro ao carregar os aglomerados: %s',
            'admin_label_no_prefix_chip' => 'Sem prefixo (%d)',
            'admin_label_wormhole_count' => '%d buracos de minhoca',
            'admin_label_default_inline' => '(padrão)',
            'admin_msg_no_galaxies_in_backup' => 'Não há galáxias neste backup.',
            'admin_msg_file_selected' => 'Selecionado: %s (%s)',
            'admin_toast_choose_backup' => 'Primeiro escolha um arquivo de backup.',
            'admin_toast_inspect_first' => 'Primeiro inspecione um arquivo.',
            'admin_toast_inspect_failed' => 'Inspeção falhou: %s',
            'admin_toast_failed_prefix' => 'Erro: %s',
            'admin_toast_nothing_selected' => 'Nada selecionado para restaurar.',
            'admin_confirm_restore' => "Restaurar %s neste sistema?\n\nModo de conflito: %s\n\nIsso não pode ser desfeito.",
            'admin_toast_restore_complete' => 'Restauração completa.',
            'admin_toast_restore_failed' => 'Restauração falhou: %s',
            'admin_label_backup_summary' => 'Resumo do arquivo de backup',
            'admin_text_format_app_created' => 'Formato v%s · App %s · Criado %s',
            'admin_text_summary_counts' => 'Galáxias: %s · Buracos de minhoca: %s · Palavras-chave: %s',
            'admin_text_summary_users_media' => 'Usuárias: %s%s · Mídia: %s arquivos (%s MB)',
            'admin_text_no_admin_user_warn' => '(sem usuária administradora!)',
            'admin_label_failures' => 'Falhas:',
            'admin_heading_restore_complete' => 'Restauração completa',
            'admin_text_galaxies_report' => 'Galáxias: criadas %s, sobrescritas %s, renomeadas %s, ignoradas %s',
            'admin_text_users_report' => 'Usuárias: criadas %s, atualizadas %s, ignoradas %s',
            'admin_text_media_report' => 'Arquivos de mídia: escritos %s, ignorados %s',
            'admin_label_disabled' => 'Desativado',
            'admin_label_active' => 'Ativo',
            'admin_label_needs_attention' => 'Requer atenção',
            'admin_msg_cron_inactive' => 'O serviço cron do sistema não está em execução (%s). Snapshots agendados não serão tomados até que o cron seja iniciado.',
            'admin_msg_cron_not_installed' => 'Não foi possível registrar o agendador com o cron. Tente salvar novamente.',
            'admin_msg_scheduler_unknown' => 'Status do agendador desconhecido.',
            'admin_msg_no_snapshots' => 'Ainda não há snapshots. Crie um acima.',
            'admin_col_snapshot_created' => 'Criado (UTC)',
            'admin_col_size' => 'Tamanho',
            'admin_col_type' => 'Tipo',
            'admin_col_creator' => 'Criadora',
            'admin_col_note' => 'Nota',
            'admin_label_file_missing' => '(arquivo ausente)',
            'admin_label_creator_system' => 'sistema',
            'admin_action_restore' => 'Restaurar',
            'admin_action_download' => 'Baixar',
            'admin_btn_creating' => 'Criando...',
            'admin_msg_creating_elapsed' => 'Criando snapshot. Tempo: %ss. Pode levar um minuto em instâncias grandes. Por favor, não feche esta aba.',
            'admin_toast_snapshot_created' => 'Snapshot criado em %ss.',
            'admin_toast_create_snapshot_failed' => 'Erro ao criar snapshot: %s',
            'admin_confirm_delete_snapshot' => 'Excluir este snapshot? O arquivo será removido permanentemente do disco.',
            'admin_toast_snapshot_deleted' => 'Snapshot excluído.',
            'admin_toast_delete_failed' => 'Falha ao excluir: %s',
            'admin_prompt_restore_snapshot' => "RESTAURAR APAGARÁ o sistema inteiro e o substituirá pelo snapshot de %s.\n\nTodos os snapshots criados após esse ponto também serão excluídos.\n\nDigite RESTORE para confirmar:",
            'admin_toast_confirm_phrase_mismatch' => 'A frase de confirmação não correspondeu. Restauração cancelada.',
            'admin_confirm_no_admin' => 'AVISO: este snapshot não tem usuária administradora. Restaurá-lo bloqueará o acesso de todas ao console de administração. Continuar mesmo assim?',
            'admin_toast_restore_complete_logout' => 'Restauração completa. Você pode ser desconectada.',
            'admin_toast_restore_complete_report' => 'Restauração completa. %s galáxias criadas, %s usuárias. %s snapshot(s) posterior(es) excluído(s). Você pode ser desconectada.',
            'admin_toast_failed_load_galaxies' => 'Falha ao carregar galáxias: %s',
            'admin_toast_saved_cron_warning' => 'Salvo, mas o agendador não conseguiu registrar com o cron: %s',
            'admin_toast_schedule_saved' => 'Agendamento salvo.',
            'admin_toast_save_schedule_failed' => 'Falha ao salvar o agendamento: %s',
            // C4: admin/index.php (modais)
            'admin_modal_heading_bulk_users' => 'Importar usuárias em lote',
            'admin_modal_bulk_users_imported_one' => 'Importou-se <strong>%d</strong> usuária.',
            'admin_modal_bulk_users_imported_many' => 'Importaram-se <strong>%d</strong> usuárias.',
            'admin_modal_bulk_users_galaxies_created_one' => ' Criou-se <strong>%d</strong> galáxia.',
            'admin_modal_bulk_users_galaxies_created_many' => ' Criaram-se <strong>%d</strong> galáxias.',
            'admin_modal_bulk_users_skipped_exists_one' => ' Ignorou-se <strong>%d</strong> e-mail já existente.',
            'admin_modal_bulk_users_skipped_exists_many' => ' Ignoraram-se <strong>%d</strong> e-mails já existentes.',
            'admin_modal_bulk_users_skipped_invalid_one' => ' Ignorou-se <strong>%d</strong> linha inválida.',
            'admin_modal_bulk_users_skipped_invalid_many' => ' Ignoraram-se <strong>%d</strong> linhas inválidas.',
            'admin_modal_bulk_users_mail_failed_one' => ' <strong>%d</strong> e-mail de configuração não pôde ser enviado.',
            'admin_modal_bulk_users_mail_failed_many' => ' <strong>%d</strong> e-mails de configuração não puderam ser enviados.',
            'admin_modal_bulk_users_col_line' => 'Linha',
            'admin_modal_bulk_users_col_email' => 'E-mail',
            'admin_modal_bulk_users_col_outcome' => 'Resultado',
            'admin_modal_bulk_users_col_galaxy' => 'Galáxia',
            'admin_modal_bulk_users_col_note' => 'Nota',
            'admin_modal_bulk_users_col_name' => 'Nome',
            'admin_modal_bulk_users_col_role' => 'Papel',
            'admin_modal_bulk_users_col_status' => 'Estado',
            'admin_modal_btn_done' => 'Concluído',
            'admin_modal_btn_confirm_import' => 'Confirmar importação',
            'admin_modal_btn_preview' => 'Pré-visualizar',
            'admin_modal_bulk_users_preview_intro' => 'Revise a lista interpretada. Clique em <strong>Confirmar importação</strong> para criar as novas contas e enviar a cada uma um link de configuração de uso único.',
            'admin_modal_bulk_users_row_override' => '(substituição de linha)',
            'admin_modal_bulk_users_form_intro' => 'Cole uma lista de usuárias, uma por linha, com colunas separadas por vírgula. Apenas o e-mail é obrigatório; o resto é opcional.',
            'admin_modal_bulk_users_field_email' => '<strong>e-mail</strong>: obrigatório',
            'admin_modal_bulk_users_field_first_name' => '<strong>primeiro nome</strong>',
            'admin_modal_bulk_users_field_last_name' => '<strong>sobrenome</strong>',
            'admin_modal_bulk_users_field_type' => '<strong>tipo</strong>: <code>Editor</code> (padrão) ou <code>Admin</code>',
            'admin_modal_bulk_users_field_create_galaxy' => '<strong>criar galáxia?</strong>: <code>sim</code> / <code>não</code>. Vazio herda a caixa abaixo; um valor aqui a substitui.',
            'admin_modal_bulk_users_example_label' => '<strong>Exemplo:</strong>',
            'admin_modal_bulk_users_footer_help' => 'Cada nova usuária recebe um e-mail de boas-vindas com um link de configuração de uso único (TTL de 7 dias) para definir sua senha. Quando uma galáxia é criada para ela, o e-mail inclui também a URL da galáxia e o link de login. E-mails já existentes são ignorados; linhas que começam com <code>#</code> são ignoradas.',
            'admin_modal_bulk_users_textarea_placeholder' => 'e-mail, nome, sobrenome, tipo, criar-galáxia',
            'admin_modal_bulk_users_label_create_galaxy_each' => 'Criar uma galáxia para cada nova usuária',
            'admin_modal_bulk_users_help_create_galaxy_each' => 'O slug é tomado do nome do e-mail (antes do <code>@</code>); colisões recebem um sufixo aleatório curto. As editoras ficam atribuídas à própria galáxia; as administradoras já veem todas as galáxias. Substitua por linha na 5.ª coluna.',
            'admin_modal_heading_create_user' => 'Criar nova usuária',
            'admin_modal_label_first_name' => 'Primeiro nome *',
            'admin_modal_help_first_name' => 'O nome próprio da usuária.',
            'admin_modal_label_last_name' => 'Sobrenome *',
            'admin_modal_help_last_name' => 'O sobrenome da usuária.',
            'admin_modal_label_email' => 'E-mail *',
            'admin_modal_err_email_in_use' => 'Este e-mail já está em uso.',
            'admin_modal_help_email' => 'Identificador de login e endereço de contato.',
            'admin_modal_label_password' => 'Senha *',
            'admin_modal_help_password_min' => 'Mínimo de 8 caracteres.',
            'admin_modal_label_user_type' => 'Tipo de usuária *',
            'admin_modal_opt_user_type_editor' => 'Editora',
            'admin_modal_opt_user_type_admin' => 'Administradora',
            'admin_modal_help_user_type' => 'Editora: só pode editar buracos de minhoca nas galáxias atribuídas | Administradora: acesso completo a todas as galáxias.',
            'admin_modal_label_create_galaxy_for_user' => 'Criar uma nova galáxia para esta usuária',
            'admin_modal_help_create_galaxy_for_user' => 'Cria-se uma galáxia com o nome abaixo e concede-se acesso a ela (apenas editoras).',
            'admin_modal_label_new_galaxy_name' => 'Nome da galáxia *',
            'admin_modal_placeholder_new_galaxy_name' => 'Por padrão, o e-mail acima',
            'admin_modal_help_new_galaxy_name' => 'Nome para a galáxia criada automaticamente.',
            'admin_modal_label_galaxy_access_editors' => 'Acesso a galáxias (apenas editoras)',
            'admin_modal_help_galaxy_access_editors' => 'As editoras só veem e editam buracos de minhoca nas galáxias marcadas acima. As administradoras veem todas as galáxias.',
            'admin_modal_btn_create_user' => 'Criar usuária',
            'admin_modal_heading_create_galaxy' => 'Criar nova galáxia',
            'admin_modal_label_galaxy_name' => 'Nome *',
            'admin_modal_placeholder_galaxy_name' => 'p. ex. Rede principal, Arquivo',
            'admin_modal_err_name_in_use' => 'Este nome já está em uso.',
            'admin_modal_help_galaxy_name' => 'Nome único para a nova rede de buracos de minhoca.',
            'admin_modal_label_url_slug' => 'Slug de URL',
            'admin_modal_placeholder_url_slug' => 'p. ex. arquivo',
            'admin_modal_err_slug_in_use' => 'Este slug já está em uso.',
            'admin_modal_help_url_slug' => 'Caminho de URL personalizado. Se ficar vazio, será gerado a partir do nome. Apenas letras, números e hífens.',
            'admin_modal_label_tagline' => 'Slogan',
            'admin_modal_placeholder_tagline' => 'p. ex. Tecendo memória',
            'admin_modal_help_tagline' => 'Aparece na vista principal quando esta galáxia está aberta.',
            'admin_modal_label_visual_theme' => 'Tema visual',
            'admin_modal_opt_theme_cosmic' => 'Cósmico (estrelas, planetas, foguetes)',
            'admin_modal_opt_theme_simple' => 'Simples (esferas coloridas)',
            'admin_modal_opt_theme_abstract' => 'Abstrato (ícones GIF geométricos)',
            'admin_modal_opt_theme_rectangles' => 'Retângulos (ícones de retângulos personalizados)',
            'admin_modal_opt_theme_stripes' => 'Faixas (ícones de faixas personalizadas)',
            'admin_modal_opt_theme_tech' => 'Tecnológico (ícones de circuitos)',
            'admin_modal_help_visual_theme' => 'Determina o fundo, os ícones e as animações.',
            'admin_modal_btn_create_galaxy' => 'Criar galáxia',
            'admin_modal_heading_create_cluster' => 'Criar aglomerado',
            'admin_modal_heading_edit_cluster' => 'Editar aglomerado',
            'admin_modal_heading_duplicate_cluster' => 'Duplicar aglomerado',
            'admin_modal_placeholder_cluster_name' => 'p. ex. Rastreando a Terra',
            'admin_modal_placeholder_cluster_slug' => 'p. ex. rastreando-a-terra',
            'admin_modal_help_cluster_slug' => 'As visitantes chegam a <code>/&lt;slug&gt;</code>. Se ficar vazio, é gerado a partir do nome.',
            'admin_modal_placeholder_cluster_tagline' => 'p. ex. Um aglomerado curado',
            'admin_modal_opt_cluster_theme_cosmic' => 'Cósmico',
            'admin_modal_opt_cluster_theme_abstract' => 'Abstrato',
            'admin_modal_opt_cluster_theme_rectangles' => 'Retângulos',
            'admin_modal_opt_cluster_theme_stripes' => 'Faixas',
            'admin_modal_opt_cluster_theme_tech' => 'Tecnológico',
            'admin_modal_help_cluster_theme' => 'Tema da cena. O ícone de cada buraco de minhoca continua usando o tema da galáxia de origem.',
            'admin_modal_label_show_galaxy_list' => 'Mostrar a lista de galáxias às visitantes',
            'admin_modal_help_show_galaxy_list' => 'Quando ativado, as visitantes veem uma lista das galáxias membras do aglomerado no canto inferior direito; ao clicar, atenuam-se os buracos de minhoca de outras galáxias. Desativado por padrão para aglomerados, pois o enquadramento curado costuma ler-se como uma experiência única.',
            'admin_modal_label_member_galaxies' => 'Galáxias membras *',
            'admin_modal_help_member_galaxies' => 'As visitantes veem a união dos buracos de minhoca destas galáxias. Pontes (linhas tracejadas sutis) conectam buracos de minhoca que compartilham texto de palavra-chave entre galáxias.',
            'admin_modal_count_selected_one' => '%d selecionada',
            'admin_modal_count_selected_many' => '%d selecionadas',
            'admin_modal_label_keyword_chips' => 'Fichas de palavras-chave',
            'admin_modal_help_keyword_chips' => 'Reúne as palavras-chave mais usadas em todos os buracos de minhoca visíveis (todas as galáxias membras) numa tira de fichas de filtro no topo do aglomerado. Clique numa ficha para atenuar os buracos de minhoca que não correspondem.',
            'admin_modal_label_related_wormholes' => 'Buracos de minhoca relacionados',
            'admin_modal_help_related_wormholes' => 'Quando o cartão de informações de um buraco de minhoca está aberto, atenua os não relacionados e exibe até 5 buracos de minhoca relacionados (que compartilham palavras-chave) como fichas de salto na parte inferior do cartão. Reúne em todo o aglomerado; as fichas podem surgir de qualquer galáxia membra.',
            'admin_modal_label_2d_view' => 'Interruptor de vista 2D',
            'admin_modal_help_2d_view' => 'Mostra um alternador "3D / 2D" no topo central para que as visitantes passem da cena 3D para uma grade plana de fichas de buracos de minhoca. A preferência da visitante persiste no navegador.',
            'admin_modal_label_idle_spotlight' => 'Holofote em inatividade',
            'admin_modal_help_idle_spotlight' => 'Quando a visitante está inativa, a câmara voa para um buraco de minhoca aleatório em qualquer parte do aglomerado e abre o cartão de informações. Fecha quando o conteúdo termina ou após o temporizador de permanência.',
            'admin_modal_label_pick_from' => 'Escolher entre',
            'admin_modal_opt_pick_all_wormholes' => 'Todos os buracos de minhoca (em todas as galáxias membras)',
            'admin_modal_opt_pick_accentuated' => 'Apenas buracos de minhoca destacados',
            'admin_modal_label_trigger_after_seconds' => 'Acionar após (segundos de inatividade)',
            'admin_modal_label_auto_tour' => 'Passeio automático',
            'admin_modal_title_preview_tour' => 'Salve primeiro e depois pré-visualize o passeio numa nova aba',
            'admin_modal_btn_preview_tour' => 'Pré-visualizar passeio',
            'admin_modal_help_auto_tour' => 'Leva automaticamente as visitantes por buracos de minhoca em todo o aglomerado, abrindo cada cartão e reproduzindo o conteúdo. Apenas desktop e iPad.',
            'admin_modal_label_start_mode' => 'Modo de início',
            'admin_modal_opt_start_manual' => 'Manual. A visitante clica num botão de reprodução para começar.',
            'admin_modal_opt_start_idle' => 'Inativa. Começa após a visitante ficar inativa por um tempo.',
            'admin_modal_opt_start_immediate' => 'Imediato. Começa alguns segundos após o aglomerado carregar.',
            'admin_modal_label_idle_threshold' => 'Limite de inatividade (segundos)',
            'admin_modal_warn_immediate_audio' => 'Uma ou mais galáxias membras contêm buracos de minhoca com áudio. Os navegadores bloqueiam a reprodução automática com som até a visitante interagir com a página, então o primeiro áudio num passeio de início imediato pode ficar em silêncio ou travar.',
            'admin_modal_label_which_wormholes' => 'Quais buracos de minhoca percorrer',
            'admin_modal_opt_tour_all' => 'Todos os buracos de minhoca (ordem aleatória em cada execução)',
            'admin_modal_opt_tour_accentuated' => 'Apenas buracos de minhoca destacados',
            'admin_modal_opt_tour_random_n' => 'Uma amostra aleatória de N buracos de minhoca',
            'admin_modal_opt_tour_tagged' => 'Buracos de minhoca marcados com uma destas palavras-chave',
            'admin_modal_label_random_count' => 'Quantos buracos de minhoca por passeio',
            'admin_modal_label_tour_keywords' => 'Palavras-chave (qualquer correspondência, separadas por vírgula)',
            'admin_modal_placeholder_tour_keywords' => 'p. ex. Ideologia, Resistência, Terra',
            'admin_modal_help_tour_keywords' => 'Corresponde por nome de palavra-chave (sem distinguir maiúsculas) em todas as galáxias membras. Útil quando a mesma etiqueta (p. ex. <code>Ideologia</code>) existe em várias galáxias com identificadores diferentes.',
            'admin_modal_label_dwell_seconds' => 'Pausa em buracos de minhoca sem conteúdo (segundos)',
            'admin_modal_label_loop_tour' => 'Repetir o passeio ao terminar',
            'admin_modal_btn_create_cluster' => 'Criar aglomerado',
            'admin_modal_btn_update_cluster' => 'Atualizar aglomerado',
            'admin_modal_name_copy_suffix' => ' (Cópia)',
            'admin_modal_heading_edit_user' => 'Editar usuária',
            'admin_modal_label_password_optional' => 'Senha (deixe em branco para manter a atual)',
            'admin_modal_btn_update_user' => 'Atualizar usuária',
            'admin_modal_heading_duplicate_galaxy' => 'Duplicar galáxia',
            'admin_modal_label_duplicating' => 'Duplicando:',
            'admin_modal_label_new_name' => 'Novo nome *',
            'admin_modal_label_new_url_slug' => 'Novo slug de URL',
            'admin_modal_label_new_tagline' => 'Novo slogan',
            'admin_modal_btn_duplicate' => 'Duplicar',
            'admin_modal_heading_confirm_deletion' => 'Confirmar exclusão',
            'admin_modal_label_type_galaxy_name' => 'Digite o nome da galáxia para confirmar:',
            'admin_modal_placeholder_type_name' => 'Digite o nome aqui...',
            'admin_modal_btn_delete' => 'Excluir',
            'admin_modal_deletion_impact_title' => '⚠️ Impacto da exclusão:',
            'admin_modal_deletion_impact_intro' => 'Os seguintes portais em outras galáxias apontam para esta rede e também serão excluídos:',
            'admin_modal_deletion_impact_row' => '<strong>%s</strong> (na galáxia: %s)',
        ],
    ];
}

/** Ensure nodes.show_keywords column exists (added in v5.5). */
function db_ensure_nodes_show_keywords_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'show_keywords'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN show_keywords BOOLEAN NOT NULL DEFAULT FALSE AFTER is_accentuated");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_show_keywords_column: ' . $e->getMessage());
    }
}

/** Ensure nodes.use_image_as_node column exists (lets editors use image_url as the 3D node icon). */
function db_ensure_nodes_use_image_as_node_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'use_image_as_node'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN use_image_as_node BOOLEAN NOT NULL DEFAULT FALSE AFTER show_keywords");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_use_image_as_node_column: ' . $e->getMessage());
    }
}

function db_ensure_constellations_import_source_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'import_source'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE constellations ADD COLUMN import_source VARCHAR(500) NULL DEFAULT NULL AFTER theme");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_constellations_import_source_column: ' . $e->getMessage());
    }
}

/** Ensure constellations.tour_* columns and constellation_tour_keywords junction table exist. */
function db_ensure_constellations_tour_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'tour_enabled'")->fetch();
        if (!$row) {
            $pdo->exec("
                ALTER TABLE constellations
                    ADD COLUMN tour_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER import_source,
                    ADD COLUMN tour_start_mode ENUM('immediate','idle','manual') NOT NULL DEFAULT 'manual' AFTER tour_enabled,
                    ADD COLUMN tour_idle_seconds INT UNSIGNED NOT NULL DEFAULT 30 AFTER tour_start_mode,
                    ADD COLUMN tour_node_selection ENUM('all','accentuated','random_n','tagged') NOT NULL DEFAULT 'all' AFTER tour_idle_seconds,
                    ADD COLUMN tour_random_count INT UNSIGNED NOT NULL DEFAULT 10 AFTER tour_node_selection,
                    ADD COLUMN tour_default_dwell INT UNSIGNED NOT NULL DEFAULT 8 AFTER tour_random_count,
                    ADD COLUMN tour_loop BOOLEAN NOT NULL DEFAULT TRUE AFTER tour_default_dwell
            ");
        }
        // keyword_chips_enabled was added later; check separately so older instances pick it up.
        $row2 = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'keyword_chips_enabled'")->fetch();
        if (!$row2) {
            $pdo->exec("ALTER TABLE constellations ADD COLUMN keyword_chips_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER tour_loop");
        }
        // idle_spotlight_* added later; check separately.
        $row3 = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'idle_spotlight_enabled'")->fetch();
        if (!$row3) {
            $pdo->exec("
                ALTER TABLE constellations
                    ADD COLUMN idle_spotlight_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER keyword_chips_enabled,
                    ADD COLUMN idle_spotlight_selection ENUM('all','accentuated') NOT NULL DEFAULT 'all' AFTER idle_spotlight_enabled,
                    ADD COLUMN idle_spotlight_idle_seconds INT UNSIGNED NOT NULL DEFAULT 30 AFTER idle_spotlight_selection
            ");
        }
        // related_nodes_enabled added later; check separately.
        $row4 = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'related_nodes_enabled'")->fetch();
        if (!$row4) {
            $pdo->exec("ALTER TABLE constellations ADD COLUMN related_nodes_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER idle_spotlight_idle_seconds");
        }
        // show_2d_view: opt-in per galaxy / cluster. When TRUE, the visitor
        // view shows a top-center "3D / 2D" segmented switch (and remembers
        // the visitor's choice in localStorage).
        $row5 = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'show_2d_view'")->fetch();
        if (!$row5) {
            $pdo->exec("ALTER TABLE constellations ADD COLUMN show_2d_view BOOLEAN NOT NULL DEFAULT FALSE AFTER related_nodes_enabled");
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS constellation_tour_keywords (
                constellation_id INT NOT NULL,
                keyword_id INT NOT NULL,
                PRIMARY KEY (constellation_id, keyword_id),
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE,
                FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE,
                INDEX idx_constellation_id (constellation_id),
                INDEX idx_keyword_id (keyword_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_constellations_tour_columns: ' . $e->getMessage());
    }
}

/**
 * Ensure constellations.type column + galaxy_cluster_members table exist.
 *
 * 'galaxy' is the default; 'cluster' rows hold no native wormholes and get their nodes
 * from member galaxies via galaxy_cluster_members. The visitor render path treats clusters
 * as a curated alias for ?galaxies=member1,member2,...; only routing/edit-UI care about the
 * type distinction.
 */
function db_ensure_constellations_type_and_cluster_members(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'type'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE constellations ADD COLUMN `type` ENUM('galaxy','cluster') NOT NULL DEFAULT 'galaxy' AFTER theme, ADD INDEX idx_type (`type`)");
        }
        // Per-cluster opt-in for the visitor's galaxy-list strip. Emergent unions
        // (?galaxies=, /[XX], /tag/) default to ON; clusters default to OFF since the
        // curator has authored a unified experience.
        $row2 = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'show_galaxy_list'")->fetch();
        if (!$row2) {
            $pdo->exec("ALTER TABLE constellations ADD COLUMN show_galaxy_list BOOLEAN NOT NULL DEFAULT FALSE AFTER `type`");
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS galaxy_cluster_members (
                cluster_id INT NOT NULL,
                member_id INT NOT NULL,
                position INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (cluster_id, member_id),
                INDEX idx_cluster_id (cluster_id),
                INDEX idx_member_id (member_id),
                FOREIGN KEY (cluster_id) REFERENCES constellations(id) ON DELETE CASCADE,
                FOREIGN KEY (member_id)  REFERENCES constellations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_constellations_type_and_cluster_members: ' . $e->getMessage());
    }
}

/**
 * Ensure the password_reset_tokens table exists.
 *
 * Tokens are hashed (SHA-256) before storage so a DB compromise can't be used to take over
 * accounts via outstanding reset links. Single-use: used_at is set when consumed and the
 * lookup query rejects rows with used_at IS NOT NULL.
 */
function db_ensure_password_reset_tokens_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_password_reset_tokens_table: ' . $e->getMessage());
    }
}

/**
 * Ensure the galaxy_tags junction table exists.
 *
 * Each row associates a galaxy with a tag. The slug is the canonical lookup key
 * (lowercase, hyphenated); the label is the editor's display preference and may
 * legitimately differ across galaxies sharing the same slug. For union view titles
 * we pick the most-common label per slug.
 */
function db_ensure_galaxy_tags_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS galaxy_tags (
                constellation_id INT NOT NULL,
                tag_slug VARCHAR(80) NOT NULL,
                tag_label VARCHAR(120) NOT NULL,
                PRIMARY KEY (constellation_id, tag_slug),
                INDEX idx_tag_slug (tag_slug),
                INDEX idx_constellation_id (constellation_id),
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_galaxy_tags_table: ' . $e->getMessage());
    }
}

/**
 * Provenance columns — second-pass migrations adding `created_by` (and where
 * missing, `created_at`) to editorial tables. Legacy rows stay NULL meaning
 * "pre-provenance era". users.id is VARCHAR(255) on this schema, so every
 * `created_by` FK matches that type. New rows get populated by the write
 * helpers below; the read/surface side is a separate ship (see TODOs).
 */
function db_ensure_keywords_created_by_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM keywords LIKE 'created_by'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE keywords
                ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL AFTER created_at,
                ADD INDEX idx_keywords_created_by (created_by),
                ADD CONSTRAINT fk_keywords_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_keywords_created_by_column: ' . $e->getMessage());
    }
}

function db_ensure_node_keywords_created_by_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM node_keywords LIKE 'created_by'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE node_keywords
                ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL AFTER created_at,
                ADD INDEX idx_node_keywords_created_by (created_by),
                ADD CONSTRAINT fk_node_keywords_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_node_keywords_created_by_column: ' . $e->getMessage());
    }
}

function db_ensure_galaxy_tags_provenance_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        db_ensure_galaxy_tags_table();
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM galaxy_tags") as $r) {
            $cols[$r['Field']] = true;
        }
        if (!isset($cols['created_at'])) {
            $pdo->exec("ALTER TABLE galaxy_tags ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER tag_label");
        }
        if (!isset($cols['created_by'])) {
            $pdo->exec("ALTER TABLE galaxy_tags
                ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL AFTER created_at,
                ADD INDEX idx_galaxy_tags_created_by (created_by),
                ADD CONSTRAINT fk_galaxy_tags_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_galaxy_tags_provenance_columns: ' . $e->getMessage());
    }
}

function db_ensure_constellations_created_by_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM constellations LIKE 'created_by'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE constellations
                ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL AFTER updated_at,
                ADD INDEX idx_constellations_created_by (created_by),
                ADD CONSTRAINT fk_constellations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_constellations_created_by_column: ' . $e->getMessage());
    }
}

/** Ensure nodes.image_attribution column exists. */
function db_ensure_nodes_image_attribution_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'image_attribution'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN image_attribution VARCHAR(255) NULL DEFAULT NULL AFTER image_url");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_image_attribution_column: ' . $e->getMessage());
    }
}

function db_ensure_nodes_icon_url_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'icon_url'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN icon_url VARCHAR(500) NULL DEFAULT NULL AFTER image_url");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_icon_url_column: ' . $e->getMessage());
    }
}

/** Ensure nodes.pdf_url column exists. */
function db_ensure_nodes_pdf_url_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'pdf_url'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN pdf_url VARCHAR(500) NULL DEFAULT NULL AFTER video_autoplay");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_pdf_url_column: ' . $e->getMessage());
    }
}

/**
 * Ensure an index covers nodes.node_type. Hot filters live in db_get_related_nodes
 * (excludes node_type='cluster') and db_get_referencing_portals (where
 * node_type='portal'); without an index those scan the whole table, which gets
 * expensive once bridge imports push the row count past tens of thousands.
 */
function db_ensure_nodes_node_type_index(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SHOW INDEX FROM nodes WHERE Key_name = :name");
        $stmt->execute([':name' => 'idx_node_type']);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE nodes ADD INDEX idx_node_type (node_type)");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_node_type_index: ' . $e->getMessage());
    }
}

/**
 * Ensure the keyword-canvas tables exist. See `Academia/Projects/Telaris/Features/Keyword canvas/design.md`
 * in the user's vault for the full design rationale.
 *
 * Three tables:
 *   - keyword_positions: latest x/y per keyword (continuous layer). moved_by = NULL means
 *     the position is a neutral default from initial Poisson-disc placement, not an
 *     authored claim.
 *   - keyword_relations: discrete named lines between keyword pairs (with author + date
 *     + optional note). Canonical ordering enforced via CHECK; one row per pair via
 *     UNIQUE.
 *   - keyword_position_history: append-only audit log for every position write.
 */
function db_ensure_keyword_canvas_tables(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();

        // users.id is VARCHAR(255) on this schema, not INT — moved_by / created_by
        // FK columns must match that exact type and collation to satisfy MySQL 8's
        // strict FK type-equality check.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS keyword_positions (
                keyword_id INT PRIMARY KEY,
                canvas_x FLOAT NOT NULL,
                canvas_y FLOAT NOT NULL,
                moved_by VARCHAR(255) NULL,
                moved_at TIMESTAMP NULL,
                FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE,
                FOREIGN KEY (moved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS keyword_relations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                keyword_a_id INT NOT NULL,
                keyword_b_id INT NOT NULL,
                created_by VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                note TEXT NULL,
                anchor_a VARCHAR(8) NOT NULL DEFAULT 'right',
                anchor_b VARCHAR(8) NOT NULL DEFAULT 'left',
                UNIQUE KEY uk_pair (keyword_a_id, keyword_b_id),
                CONSTRAINT chk_canonical CHECK (keyword_a_id < keyword_b_id),
                FOREIGN KEY (keyword_a_id) REFERENCES keywords(id) ON DELETE CASCADE,
                FOREIGN KEY (keyword_b_id) REFERENCES keywords(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Idempotent migration: if keyword_relations was created before the
        // anchor_a/anchor_b columns landed, add them now with sensible defaults.
        $hasAnchorA = $pdo->query("SHOW COLUMNS FROM keyword_relations LIKE 'anchor_a'")->fetch();
        if (!$hasAnchorA) {
            $pdo->exec("ALTER TABLE keyword_relations ADD COLUMN anchor_a VARCHAR(8) NOT NULL DEFAULT 'right' AFTER note");
            $pdo->exec("ALTER TABLE keyword_relations ADD COLUMN anchor_b VARCHAR(8) NOT NULL DEFAULT 'left' AFTER anchor_a");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS keyword_position_history (
                id INT PRIMARY KEY AUTO_INCREMENT,
                keyword_id INT NOT NULL,
                canvas_x FLOAT NOT NULL,
                canvas_y FLOAT NOT NULL,
                moved_by VARCHAR(255) NULL,
                moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_keyword (keyword_id),
                FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_keyword_canvas_tables: ' . $e->getMessage());
    }
}

/**
 * Canvas coordinate-space constants. The SVG renderer uses these as its viewBox
 * (d3-zoom then scales to fit the viewport). Fixed coordinate space keeps stored
 * positions stable across resizes.
 */
const KEYWORD_CANVAS_WIDTH = 2000.0;
const KEYWORD_CANVAS_HEIGHT = 2000.0;

/**
 * Place every keyword in a galaxy that doesn't yet have a position row.
 *
 * Initial placement is **truly uniform** — Mitchell's best-candidate sampling (a
 * simple Poisson-disc-style algorithm) scatters keywords across the canvas with
 * a minimum spacing constraint. No co-occurrence prior, no algorithmic clustering.
 * The political point is in `Keyword canvas — design.md`: editors author from a
 * neutral baseline, not from a model's guess.
 *
 * `moved_by` stays NULL on every seeded row. The position only counts as an
 * authored claim once the editor actually drags it.
 *
 * Idempotent: keywords that already have a position row are left alone. Safe under
 * concurrent calls because the PRIMARY KEY on keyword_positions.keyword_id makes
 * INSERT IGNORE no-op on races.
 *
 * @return int Number of newly-seeded position rows.
 */
function db_seed_keyword_positions_for_galaxy(int $galaxyId): int {
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();

    // Find keywords in this galaxy without a position row.
    $missing = $pdo->prepare("
        SELECT k.id
        FROM keywords k
        LEFT JOIN keyword_positions p ON p.keyword_id = k.id
        WHERE k.constellation_id = :cid AND p.keyword_id IS NULL
    ");
    $missing->execute([':cid' => $galaxyId]);
    $missingIds = $missing->fetchAll(PDO::FETCH_COLUMN);
    if (empty($missingIds)) return 0;

    // Collect any existing positions in this galaxy — new placements should avoid
    // them too so editor-authored positions don't get crowded by seeding.
    $existing = $pdo->prepare("
        SELECT p.canvas_x, p.canvas_y
        FROM keyword_positions p
        INNER JOIN keywords k ON k.id = p.keyword_id
        WHERE k.constellation_id = :cid
    ");
    $existing->execute([':cid' => $galaxyId]);
    $points = [];
    while ($row = $existing->fetch()) {
        $points[] = [(float)$row['canvas_x'], (float)$row['canvas_y']];
    }

    $w = KEYWORD_CANVAS_WIDTH;
    $h = KEYWORD_CANVAS_HEIGHT;
    $totalAfter = count($points) + count($missingIds);
    // Heuristic minimum spacing: scales down as the canvas fills. Floor at 40 so
    // very dense galaxies don't drift into pixel-overlap territory; ceiling at 180
    // so very sparse galaxies don't end up needing huge zoom-out to see siblings.
    $minDist = max(40.0, min(180.0, sqrt($w * $h / max(1, $totalAfter)) * 0.55));

    $insert = $pdo->prepare("
        INSERT IGNORE INTO keyword_positions (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
        VALUES (:kid, :x, :y, NULL, NULL)
    ");

    $seeded = 0;
    foreach ($missingIds as $kid) {
        [$x, $y] = _poisson_disc_next_point($points, $w, $h, $minDist);
        $insert->execute([':kid' => (int)$kid, ':x' => $x, ':y' => $y]);
        $points[] = [$x, $y];
        $seeded++;
    }
    return $seeded;
}

/**
 * Hydrate the keyword canvas for a galaxy: returns keywords, positions, and
 * relations in one payload. Triggers lazy seeding so keywords without a position
 * get one before the response.
 *
 * @return array{
 *   keywords: list<array{id:int,name:string}>,
 *   positions: list<array{keyword_id:int,canvas_x:float,canvas_y:float,moved_by:?string,moved_at:?string}>,
 *   relations: list<array{id:int,a:int,b:int,created_by:?string,created_at:?string,note:?string}>,
 *   canvas_width:float,
 *   canvas_height:float,
 * }
 */
function db_get_keyword_canvas_hydration(int $galaxyId): array {
    db_ensure_keyword_canvas_tables();
    db_seed_keyword_positions_for_galaxy($galaxyId);
    $pdo = getDB();

    $kwStmt = $pdo->prepare("
        SELECT k.id, k.keyword
        FROM keywords k
        WHERE k.constellation_id = :cid
        ORDER BY k.keyword
    ");
    $kwStmt->execute([':cid' => $galaxyId]);
    $keywords = array_map(fn(array $r) => [
        'id' => (int)$r['id'],
        'name' => (string)$r['keyword'],
    ], $kwStmt->fetchAll());

    $posStmt = $pdo->prepare("
        SELECT p.keyword_id, p.canvas_x, p.canvas_y, p.moved_by, p.moved_at
        FROM keyword_positions p
        INNER JOIN keywords k ON k.id = p.keyword_id
        WHERE k.constellation_id = :cid
    ");
    $posStmt->execute([':cid' => $galaxyId]);
    $positions = array_map(fn(array $r) => [
        'keyword_id' => (int)$r['keyword_id'],
        'canvas_x' => (float)$r['canvas_x'],
        'canvas_y' => (float)$r['canvas_y'],
        'moved_by' => $r['moved_by'] !== null ? (string)$r['moved_by'] : null,
        'moved_at' => $r['moved_at'] !== null ? (string)$r['moved_at'] : null,
    ], $posStmt->fetchAll());

    $relStmt = $pdo->prepare("
        SELECT r.id, r.keyword_a_id, r.keyword_b_id, r.created_by, r.created_at,
               r.note, r.anchor_a, r.anchor_b
        FROM keyword_relations r
        INNER JOIN keywords ka ON ka.id = r.keyword_a_id
        WHERE ka.constellation_id = :cid
        ORDER BY r.id
    ");
    $relStmt->execute([':cid' => $galaxyId]);
    $relations = array_map(fn(array $r) => [
        'id' => (int)$r['id'],
        'a' => (int)$r['keyword_a_id'],
        'b' => (int)$r['keyword_b_id'],
        'created_by' => $r['created_by'] !== null ? (string)$r['created_by'] : null,
        'created_at' => $r['created_at'] !== null ? (string)$r['created_at'] : null,
        'note' => $r['note'] !== null ? (string)$r['note'] : null,
        'anchor_a' => (string)($r['anchor_a'] ?? 'right'),
        'anchor_b' => (string)($r['anchor_b'] ?? 'left'),
    ], $relStmt->fetchAll());

    return [
        'keywords' => $keywords,
        'positions' => $positions,
        'relations' => $relations,
        'canvas_width' => KEYWORD_CANVAS_WIDTH,
        'canvas_height' => KEYWORD_CANVAS_HEIGHT,
    ];
}

/**
 * Record a keyword's new position. Upserts the position row and appends to history.
 * `moved_by` and `moved_at` carry the editor's authorship. Coordinates are clamped
 * to the canvas bounds.
 */
function db_record_keyword_position(int $keywordId, float $x, float $y, ?string $userId): void {
    db_ensure_keyword_canvas_tables();
    $x = max(0.0, min(KEYWORD_CANVAS_WIDTH, $x));
    $y = max(0.0, min(KEYWORD_CANVAS_HEIGHT, $y));
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO keyword_positions (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
            VALUES (:kid, :x, :y, :uid, NOW())
            ON DUPLICATE KEY UPDATE
                canvas_x = VALUES(canvas_x),
                canvas_y = VALUES(canvas_y),
                moved_by = VALUES(moved_by),
                moved_at = VALUES(moved_at)
        ")->execute([':kid' => $keywordId, ':x' => $x, ':y' => $y, ':uid' => $userId]);
        $pdo->prepare("
            INSERT INTO keyword_position_history (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
            VALUES (:kid, :x, :y, :uid, NOW())
        ")->execute([':kid' => $keywordId, ':x' => $x, ':y' => $y, ':uid' => $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reset a keyword's position to a fresh Poisson-disc placement and clear `moved_by`/
 * `moved_at`. The pair distances involving this keyword revert to "neutral default."
 * The reset itself is logged in history (moved_by = the user who requested the reset).
 */
function db_reset_keyword_position(int $keywordId, ?string $userId): void {
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();
    $galaxyId = db_get_keyword_constellation_id($keywordId);
    if ($galaxyId === null) return;

    // Collect existing positions in the galaxy (excluding this keyword) to inform spacing.
    $stmt = $pdo->prepare("
        SELECT p.canvas_x, p.canvas_y
        FROM keyword_positions p
        INNER JOIN keywords k ON k.id = p.keyword_id
        WHERE k.constellation_id = :cid AND p.keyword_id != :kid
    ");
    $stmt->execute([':cid' => $galaxyId, ':kid' => $keywordId]);
    $points = [];
    while ($row = $stmt->fetch()) {
        $points[] = [(float)$row['canvas_x'], (float)$row['canvas_y']];
    }
    $minDist = max(40.0, min(180.0,
        sqrt(KEYWORD_CANVAS_WIDTH * KEYWORD_CANVAS_HEIGHT / max(1, count($points) + 1)) * 0.55
    ));
    [$x, $y] = _poisson_disc_next_point($points, KEYWORD_CANVAS_WIDTH, KEYWORD_CANVAS_HEIGHT, $minDist);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO keyword_positions (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
            VALUES (:kid, :x, :y, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                canvas_x = VALUES(canvas_x),
                canvas_y = VALUES(canvas_y),
                moved_by = NULL,
                moved_at = NULL
        ")->execute([':kid' => $keywordId, ':x' => $x, ':y' => $y]);
        // History row records who *initiated* the reset so the audit log isn't blind.
        $pdo->prepare("
            INSERT INTO keyword_position_history (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
            VALUES (:kid, :x, :y, :uid, NOW())
        ")->execute([':kid' => $keywordId, ':x' => $x, ':y' => $y, ':uid' => $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reset every position in a galaxy to a fresh Poisson-disc cloud. Returns the
 * number of rows reset. Each affected keyword gets a history entry attributing
 * the reset to $userId.
 */
function db_reset_galaxy_positions(int $galaxyId, ?string $userId): int {
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM keywords WHERE constellation_id = :cid ORDER BY id");
    $stmt->execute([':cid' => $galaxyId]);
    $keywordIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($keywordIds)) return 0;

    // Build a fresh cloud from scratch.
    $points = [];
    $w = KEYWORD_CANVAS_WIDTH;
    $h = KEYWORD_CANVAS_HEIGHT;
    $minDist = max(40.0, min(180.0, sqrt($w * $h / count($keywordIds)) * 0.55));
    $coords = [];
    foreach ($keywordIds as $_) {
        [$x, $y] = _poisson_disc_next_point($points, $w, $h, $minDist);
        $coords[] = [$x, $y];
        $points[] = [$x, $y];
    }

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare("
            INSERT INTO keyword_positions (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
            VALUES (:kid, :x, :y, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                canvas_x = VALUES(canvas_x),
                canvas_y = VALUES(canvas_y),
                moved_by = NULL,
                moved_at = NULL
        ");
        $hist = $pdo->prepare("
            INSERT INTO keyword_position_history (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
            VALUES (:kid, :x, :y, :uid, NOW())
        ");
        foreach ($keywordIds as $i => $kid) {
            [$x, $y] = $coords[$i];
            $upsert->execute([':kid' => $kid, ':x' => $x, ':y' => $y]);
            $hist->execute([':kid' => $kid, ':x' => $x, ':y' => $y, ':uid' => $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return count($keywordIds);
}

/**
 * Create a discrete named lateral relation between two keywords. Normalizes pair
 * order (keyword_a < keyword_b) before insert. If the pair has to be swapped
 * for canonical ordering, the anchor sides swap with it so anchor_a always
 * names the side on keyword_a (the lower id). Rejects self-loops. Throws on
 * duplicate pairs (caller catches and returns 409).
 *
 * Both keywords must be in the same galaxy — caller's job to verify the galaxy
 * scope; this function only enforces id-canonicalization and non-self-loop.
 *
 * @return int The new relation's id.
 */
function db_create_keyword_relation(
    int $keywordAId,
    int $keywordBId,
    ?string $userId,
    ?string $note = null,
    string $anchorA = 'right',
    string $anchorB = 'left'
): int {
    db_ensure_keyword_canvas_tables();
    if ($keywordAId === $keywordBId) {
        throw new InvalidArgumentException('Self-loop relations are not allowed.');
    }
    $validSides = ['top', 'right', 'bottom', 'left'];
    if (!in_array($anchorA, $validSides, true)) $anchorA = 'right';
    if (!in_array($anchorB, $validSides, true)) $anchorB = 'left';

    if ($keywordAId < $keywordBId) {
        [$lo, $hi, $loAnchor, $hiAnchor] = [$keywordAId, $keywordBId, $anchorA, $anchorB];
    } else {
        [$lo, $hi, $loAnchor, $hiAnchor] = [$keywordBId, $keywordAId, $anchorB, $anchorA];
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO keyword_relations
            (keyword_a_id, keyword_b_id, created_by, note, anchor_a, anchor_b)
        VALUES (:a, :b, :uid, :note, :anchor_a, :anchor_b)
    ");
    $stmt->execute([
        ':a' => $lo, ':b' => $hi, ':uid' => $userId, ':note' => $note,
        ':anchor_a' => $loAnchor, ':anchor_b' => $hiAnchor,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Update an existing relation's note. Auth (author-only or admin) is the caller's job.
 */
function db_update_keyword_relation(int $relationId, ?string $note): void {
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();
    $pdo->prepare("UPDATE keyword_relations SET note = :note WHERE id = :id")
        ->execute([':note' => $note, ':id' => $relationId]);
}

/**
 * Delete a relation. Auth (author-only or admin) is the caller's job.
 */
function db_delete_keyword_relation(int $relationId): void {
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();
    $pdo->prepare("DELETE FROM keyword_relations WHERE id = :id")->execute([':id' => $relationId]);
}

/**
 * Read a relation row (for auth checks before update/delete).
 * @return array{id:int,a:int,b:int,created_by:?string,created_at:?string,note:?string}|null
 */
function db_get_keyword_relation(int $relationId): ?array {
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, keyword_a_id, keyword_b_id, created_by, created_at, note
        FROM keyword_relations WHERE id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $relationId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'id' => (int)$row['id'],
        'a' => (int)$row['keyword_a_id'],
        'b' => (int)$row['keyword_b_id'],
        'created_by' => $row['created_by'] !== null ? (string)$row['created_by'] : null,
        'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
        'note' => $row['note'] !== null ? (string)$row['note'] : null,
    ];
}

/**
 * Mitchell's best-candidate: generate K random candidates, pick the one whose
 * nearest-existing-point distance is largest. If $minDist is satisfied, return
 * any qualifying candidate; otherwise fall back to the best-of-K. Simple, fast,
 * and visually indistinguishable from full Bridson at the scale Telaris cares
 * about (10–500 keywords per galaxy).
 *
 * @param list<array{0: float, 1: float}> $existing
 * @return array{0: float, 1: float}
 */
function _poisson_disc_next_point(array $existing, float $w, float $h, float $minDist): array {
    $k = 30;
    $bestPoint = null;
    $bestMinDist = -1.0;
    for ($i = 0; $i < $k; $i++) {
        $x = mt_rand(0, (int)($w * 1000)) / 1000.0;
        $y = mt_rand(0, (int)($h * 1000)) / 1000.0;
        $nearest = PHP_FLOAT_MAX;
        foreach ($existing as [$px, $py]) {
            $d2 = ($px - $x) * ($px - $x) + ($py - $y) * ($py - $y);
            if ($d2 < $nearest) $nearest = $d2;
        }
        // Empty canvas → any point is fine
        if (empty($existing)) return [$x, $y];
        $d = sqrt($nearest);
        if ($d >= $minDist) return [$x, $y]; // satisfied — accept immediately
        if ($d > $bestMinDist) {
            $bestMinDist = $d;
            $bestPoint = [$x, $y];
        }
    }
    return $bestPoint ?? [mt_rand(0, (int)$w) * 1.0, mt_rand(0, (int)$h) * 1.0];
}

/**
 * Ensure nodes clustering columns exist (source_facet, media_type,
 * source_created_at). source_facet was previously called mucua_name (a
 * bridge-vocabulary holdover from before the bridge framework existed);
 * on instances that still carry the old column name, it gets renamed in
 * place so the data follows.
 */
function db_ensure_nodes_clustering_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $hasSourceFacet = (bool)$pdo->query("SHOW COLUMNS FROM nodes LIKE 'source_facet'")->fetch();
        if ($hasSourceFacet) return;

        $hasMucuaName = (bool)$pdo->query("SHOW COLUMNS FROM nodes LIKE 'mucua_name'")->fetch();
        if ($hasMucuaName) {
            // Old schema: rename the column so existing data carries over.
            $pdo->exec("ALTER TABLE nodes CHANGE COLUMN mucua_name source_facet VARCHAR(255) NULL");
            return;
        }

        $pdo->exec("ALTER TABLE nodes ADD COLUMN source_facet VARCHAR(255) NULL AFTER show_keywords, ADD COLUMN media_type VARCHAR(50) NULL AFTER source_facet, ADD COLUMN source_created_at VARCHAR(30) NULL AFTER media_type");
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_clustering_columns: ' . $e->getMessage());
    }
}

/** Ensure snapshots and snapshot_schedule tables exist. */
function db_ensure_snapshots_tables(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                created_by VARCHAR(255) NULL,
                trigger_type ENUM('manual','scheduled') NOT NULL DEFAULT 'manual',
                note VARCHAR(500) NULL,
                UNIQUE KEY unique_filename (filename),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS snapshot_schedule (
                id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
                enabled BOOLEAN NOT NULL DEFAULT FALSE,
                hour TINYINT NOT NULL DEFAULT 3,
                keep_days INT NOT NULL DEFAULT 7,
                last_run_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Seed the singleton schedule row.
        $pdo->exec("INSERT IGNORE INTO snapshot_schedule (id) VALUES (1)");

        // Migrate older installs to the simplified schema (enabled / hour / keep_days).
        $cols = $pdo->query("SHOW COLUMNS FROM snapshot_schedule")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (in_array('keep_last', $cols, true) && !in_array('keep_days', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule ADD COLUMN keep_days INT NOT NULL DEFAULT 7");
            $pdo->exec("ALTER TABLE snapshot_schedule DROP COLUMN keep_last");
        }
        if (in_array('frequency', $cols, true) && !in_array('enabled', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule ADD COLUMN enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER id");
            $pdo->exec("UPDATE snapshot_schedule SET enabled = (frequency <> 'off')");
        }
        if (in_array('frequency', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule DROP COLUMN frequency");
        }
        if (in_array('day_of_week', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule DROP COLUMN day_of_week");
        }
        // 'hour' was nullable in older schemas; make it NOT NULL DEFAULT 3.
        $hourCol = $pdo->query("SHOW COLUMNS FROM snapshot_schedule LIKE 'hour'")->fetch(PDO::FETCH_ASSOC);
        if ($hourCol && (($hourCol['Null'] ?? 'YES') === 'YES')) {
            $pdo->exec("UPDATE snapshot_schedule SET hour = 3 WHERE hour IS NULL");
            $pdo->exec("ALTER TABLE snapshot_schedule MODIFY COLUMN hour TINYINT NOT NULL DEFAULT 3");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_snapshots_tables: ' . $e->getMessage());
    }
}

/** Ensure nodes.import_slug column exists. */
function db_ensure_nodes_import_slug_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'import_slug'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN import_slug VARCHAR(255) NULL AFTER source_created_at, ADD INDEX idx_import_slug (constellation_id, import_slug)");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_import_slug_column: ' . $e->getMessage());
    }
}

/**
 * Get all nodes for a constellation keyed by import_slug.
 * Returns [slug => ['id' => int, 'name' => string, 'description' => string, 'media_type' => string, 'source_facet' => string, 'source_created_at' => string, 'keywords' => string[]]].
 */
function db_get_nodes_by_import_slug(int $constellationId): array {
    db_ensure_nodes_import_slug_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name, description, import_slug, media_type, source_facet, source_created_at, url FROM nodes WHERE constellation_id = :cid AND import_slug IS NOT NULL");
    $stmt->execute([':cid' => $constellationId]);
    $nodes = $stmt->fetchAll();

    // Bulk-load all keywords in a single query
    $nodeIds = array_map(fn($n) => (int)$n['id'], $nodes);
    $keywordsMap = db_get_keywords_for_nodes_bulk($nodeIds);

    $result = [];
    foreach ($nodes as $node) {
        $slug = $node['import_slug'];
        if ($slug === '' || $slug === null) continue;
        $nodeId = (int)$node['id'];
        $result[$slug] = [
            'id' => $nodeId,
            'name' => $node['name'] ?? '',
            'description' => $node['description'] ?? '',
            'media_type' => $node['media_type'] ?? '',
            'source_facet' => $node['source_facet'] ?? '',
            'source_created_at' => $node['source_created_at'] ?? '',
            'keywords' => $keywordsMap[$nodeId] ?? [],
            'url' => $node['url'] ?? '',
        ];
    }
    return $result;
}

/**
 * Backfill import_slug for existing imported nodes by extracting from URL.
 */
function db_backfill_import_slugs(int $constellationId): int {
    db_ensure_nodes_import_slug_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, url FROM nodes WHERE constellation_id = :cid AND import_slug IS NULL AND url IS NOT NULL AND url != ''");
    $stmt->execute([':cid' => $constellationId]);
    $updateStmt = $pdo->prepare("UPDATE nodes SET import_slug = :slug WHERE id = :id");
    $count = 0;
    while ($row = $stmt->fetch()) {
        // URL format: .../permalink/acervo/SLUG or .../permalink/blog/artigo/SLUG
        $url = $row['url'];
        $slug = basename($url);
        if ($slug !== '' && $slug !== $url) {
            $updateStmt->execute([':slug' => $slug, ':id' => $row['id']]);
            $count++;
        }
    }
    return $count;
}

/** Set clustering metadata on a node. */
function db_set_node_clustering_metadata(int $nodeId, ?string $sourceFacet, ?string $mediaType, ?string $sourceCreatedAt): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE nodes SET source_facet = :source_facet, media_type = :media_type, source_created_at = :source_created_at WHERE id = :id");
    $stmt->execute([
        ':id' => $nodeId,
        ':source_facet' => $sourceFacet,
        ':media_type' => $mediaType,
        ':source_created_at' => $sourceCreatedAt,
    ]);
}

/** No-op: schema is created by setup only. */
function db_ensure_project_info_table(): void {
}

/** Migrate systems_label_text from old defaults (Nodes:/Nodos:) to new vocabulary (Wormholes: etc.). */
function db_migrate_systems_label_text(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = getDB();
        $map = [
            'en' => ['old' => 'Nodes:', 'new' => 'Wormholes:'],
            'es' => ['old' => 'Nodos:', 'new' => 'Agujeros de Gusano:'],
            'pt' => ['old' => 'Nodos:', 'new' => 'Buracos de Minhoca:'],
        ];
        $stmt = $pdo->prepare("UPDATE project_info SET systems_label_text = :new WHERE locale = :locale AND systems_label_text = :old");
        foreach ($map as $locale => $vals) {
            $stmt->execute([':new' => $vals['new'], ':locale' => $locale, ':old' => $vals['old']]);
        }
    } catch (PDOException $e) {
        error_log('db_migrate_systems_label_text: ' . $e->getMessage());
    }
}

/** Ensure new localization columns exist in project_info. */
function db_ensure_project_info_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $newCols = [
        'sound_label_text' => "VARCHAR(200) NOT NULL DEFAULT 'Sound:'",
        'sound_on_text' => "VARCHAR(200) NOT NULL DEFAULT 'ON'",
        'sound_off_text' => "VARCHAR(200) NOT NULL DEFAULT 'OFF'",
        'launching_text' => "VARCHAR(200) NOT NULL DEFAULT 'Launching'",
        'mission_active_text' => "VARCHAR(200) NOT NULL DEFAULT 'Mission Active'",
        'go_text' => "VARCHAR(200) NOT NULL DEFAULT 'GO'",
        'breadcrumb_all_text' => "VARCHAR(200) NOT NULL DEFAULT 'All'",
        'launch_button_text' => "VARCHAR(200) NOT NULL DEFAULT 'LAUNCH'",
        'no_results_text' => "VARCHAR(200) NOT NULL DEFAULT 'No results'",
        'items_label_text' => "VARCHAR(200) NOT NULL DEFAULT 'items'",
        'other_label_text' => "VARCHAR(200) NOT NULL DEFAULT 'Other'",
        'galaxies_label_text' => "VARCHAR(200) NOT NULL DEFAULT 'Galaxies'",
        'galaxy_count_singular_text' => "VARCHAR(200) NOT NULL DEFAULT '1 galaxy'",
        'galaxy_count_plural_text' => "VARCHAR(200) NOT NULL DEFAULT '%d galaxies'",
        'pdf_loading_text' => "VARCHAR(200) NOT NULL DEFAULT 'Loading PDF…'",
        'pdf_rendering_text' => "VARCHAR(200) NOT NULL DEFAULT 'Rendering pages…'",
        'pdf_pages_singular_text' => "VARCHAR(200) NOT NULL DEFAULT '1 page'",
        'pdf_pages_plural_text' => "VARCHAR(200) NOT NULL DEFAULT '%d pages'",
        'pdf_open_text' => "VARCHAR(200) NOT NULL DEFAULT 'Open in new window'",
        'pdf_download_text' => "VARCHAR(200) NOT NULL DEFAULT 'Download'",
        'pdf_error_load_text' => "VARCHAR(200) NOT NULL DEFAULT 'PDF library failed to load.'",
        'pdf_error_open_text' => "VARCHAR(200) NOT NULL DEFAULT \"Couldn't open PDF.\"",
    ];
    try {
        $pdo = getDB();
        foreach ($newCols as $col => $def) {
            $row = $pdo->query("SHOW COLUMNS FROM project_info LIKE '{$col}'")->fetch();
            if (!$row) {
                $pdo->exec("ALTER TABLE project_info ADD COLUMN {$col} {$def}");
            }
        }
        // Populate defaults for non-en locales
        $defaults = db_default_project_info_rows();
        foreach (['es', 'pt'] as $locale) {
            $sets = [];
            $params = [':locale' => $locale];
            foreach ($newCols as $col => $_) {
                if (isset($defaults[$locale][$col])) {
                    $sets[] = "{$col} = CASE WHEN {$col} = '' OR {$col} = (SELECT {$col} FROM (SELECT {$col} FROM project_info WHERE locale = 'en' LIMIT 1) AS t) THEN :{$col} ELSE {$col} END";
                    $params[":{$col}"] = $defaults[$locale][$col];
                }
            }
            if (!empty($sets)) {
                $pdo->prepare("UPDATE project_info SET " . implode(', ', $sets) . " WHERE locale = :locale")->execute($params);
            }
        }
    } catch (PDOException $e) {
        error_log('db_ensure_project_info_columns: ' . $e->getMessage());
    }
}

/**
 * Insert default project_info rows (one per locale). Used by setup and when table is empty.
 */
function db_insert_default_project_info_rows(PDO $pdo, string $enName = 'Telaris', string $enDescription = 'Weaving memory'): void {
    $defaults = db_default_project_info_rows($enName, $enDescription);
    // Only insert columns that actually exist on disk. New keys appended to
    // PROJECT_INFO_KEYS that don't yet have a matching SQL column resolve
    // through the PHP-default fallback at read time (see
    // db_get_project_info_for_locale) and don't need to be persisted.
    $actualCols = $pdo->query("SHOW COLUMNS FROM project_info")->fetchAll(PDO::FETCH_COLUMN);
    $keys = array_values(array_intersect(PROJECT_INFO_KEYS, $actualCols));
    if (empty($keys)) return;
    $cols = implode(', ', $keys);
    $placeholders = ':' . implode(', :', $keys);
    $updates = [];
    foreach ($keys as $k) {
        $updates[] = "$k = VALUES($k)";
    }
    $updateStr = implode(', ', $updates);

    $stmt = $pdo->prepare("INSERT INTO project_info (locale, $cols) VALUES (:locale, $placeholders) ON DUPLICATE KEY UPDATE $updateStr");
    foreach (PROJECT_INFO_LOCALES as $locale) {
        $params = [':locale' => $locale];
        foreach ($keys as $k) {
            $params[':' . $k] = $defaults[$locale][$k] ?? '';
        }
        $stmt->execute($params);
    }
}


/**
 * Read the description for English (Edit form).
 */
function db_get_project_description(): string {
    try {
        db_ensure_project_info_table();
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT description FROM project_info WHERE locale = 'en' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row !== false && isset($row[0]) ? (string) $row[0] : '';
    } catch (PDOException $e) {
        return '';
    }
}

/**
 * Return English project strings (legacy).
 * @return array{name: string, description: string, iframe_back_text: string, alert_message: string}|null
 */
function db_get_project_info(): ?array {
    $row = db_get_project_info_for_locale('en');
    return $row;
}

/**
 * Upsert project name and description for English. Used by setup and website form.
 */
function db_upsert_project_info(string $name, string $description): void {
    db_ensure_project_info_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO project_info (locale, name, description) VALUES ('en', :name, :description) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)");
    $stmt->execute([':name' => $name, ':description' => $description]);
}

/**
 * Update English project settings only.
 */
function db_update_project_settings(string $name, string $description, string $iframe_back_text, string $alert_message): void {
    db_ensure_project_info_columns();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE project_info SET name = :name, description = :description, iframe_back_text = :iframe_back_text, alert_message = :alert_message WHERE locale = 'en'");
    $stmt->execute([':name' => $name, ':description' => $description, ':iframe_back_text' => $iframe_back_text, ':alert_message' => $alert_message]);
}

/**
 * Return all labels for all locales (for Edit Settings form).
 * Returns flat array: name, name_es, name_pt, description, description_es, ...
 */
function db_get_project_info_all_locales(): ?array {
    try {
        db_ensure_project_info_table();
        $pdo = getDB();
        $stmt = $pdo->query("SELECT * FROM project_info");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        // Initialize with English defaults first
        $defaults = db_default_project_info_rows();
        foreach ($defaults['en'] as $key => $val) {
            $out[$key] = $val;
        }
        foreach (['es', 'pt'] as $l) {
            foreach ($defaults[$l] as $key => $val) {
                $out[$key . '_' . $l] = $val;
            }
        }

        foreach ($rows as $r) {
            $locale = $r['locale'] ?? 'en';
            if ($locale === 'en') {
                $out['default_constellation_id'] = (int)($r['default_constellation_id'] ?? 0);
            }
            foreach (PROJECT_INFO_KEYS as $key) {
                if (isset($r[$key])) {
                    if ($locale === 'en') {
                        $out[$key] = (string) $r[$key];
                    } else {
                        $out[$key . '_' . $locale] = (string) $r[$key];
                    }
                }
            }
        }
        return $out;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Return project strings for the main app for a given locale.
 * $locale one of: en, es, pt. Falls back to English when locale value is empty.
 */
function db_get_project_info_for_locale(string $locale): array {
    try {
        db_ensure_project_info_table();
        db_ensure_project_info_columns();
        db_migrate_systems_label_text();
        $locale = strtolower($locale);
        if (!in_array($locale, PROJECT_INFO_LOCALES, true)) {
            $locale = 'en';
        }
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM project_info WHERE locale = :locale LIMIT 1");
        $stmt->execute([':locale' => $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $enStmt = $pdo->prepare("SELECT * FROM project_info WHERE locale = 'en' LIMIT 1");
        $enStmt->execute();
        $enRow = $enStmt->fetch(PDO::FETCH_ASSOC);
        
        $defaults = db_default_project_info_rows();
        $enDefault = $defaults['en'];
        $localeDefault = $defaults[$locale] ?? $enDefault;

        $out = [];
        foreach (PROJECT_INFO_KEYS as $key) {
            $val = '';
            if ($row && isset($row[$key]) && (string)$row[$key] !== '') {
                $val = (string)$row[$key];
            } elseif ($enRow && isset($enRow[$key]) && (string)$enRow[$key] !== '') {
                $val = (string)$enRow[$key];
            } else {
                // Column missing from DB schema, or both rows empty: use the
                // locale-specific PHP default (correct translation), then EN
                // default as final safety net. Without this, ES/PT silently
                // returned EN text for keys whose DB columns hadn't been added.
                $val = $localeDefault[$key] ?? $enDefault[$key] ?? '';
            }
            $out[$key] = $val;
        }
        $out['default_constellation_id'] = (int)($enRow['default_constellation_id'] ?? 0);
        return $out;
    } catch (PDOException $e) {
        $defaults = db_default_project_info_rows();
        return $defaults['en'];
    }
}

/**
 * Update project settings for all locales (one row per locale in project_info).
 */
function db_update_project_settings_with_locales(array $en, array $es, array $pt, ?int $defaultConstellationId = null): void {
    db_ensure_project_info_table();
    $pdo = getDB();
    
    $keys = PROJECT_INFO_KEYS;
    $cols = implode(', ', $keys);
    $placeholders = ':' . implode(', :', $keys);
    $updates = [];
    foreach ($keys as $k) {
        $updates[] = "$k = VALUES($k)";
    }
    $updateStr = implode(', ', $updates);
    
    // Check if column exists (it should, but just in case for older migrations)
    $stmt = $pdo->query("SHOW COLUMNS FROM project_info LIKE 'default_constellation_id'");
    $hasDefaultCol = $stmt->fetch() !== false;
    
    $sql = "INSERT INTO project_info (locale, $cols" . ($hasDefaultCol ? ", default_constellation_id" : "") . ") 
            VALUES (:locale, $placeholders" . ($hasDefaultCol ? ", :default_constellation_id" : "") . ") 
            ON DUPLICATE KEY UPDATE $updateStr" . ($hasDefaultCol ? ", default_constellation_id = VALUES(default_constellation_id)" : "");
    
    $stmt = $pdo->prepare($sql);
    
    $locales = ['en' => $en, 'es' => $es, 'pt' => $pt];
    $defaults = db_default_project_info_rows();
    
    foreach (PROJECT_INFO_LOCALES as $locale) {
        $data = $locales[$locale] ?? [];
        $params = [':locale' => $locale];
        foreach ($keys as $k) {
            $val = trim((string)($data[$k] ?? ''));
            if ($val === '' && isset($defaults[$locale][$k])) {
                $val = $defaults[$locale][$k];
            }
            $params[':' . $k] = $val;
        }
        if ($hasDefaultCol) {
            $params[':default_constellation_id'] = $defaultConstellationId ?? 0;
        }
        $stmt->execute($params);
    }
}

// ---------------------------------------------------------------------------
// API keys
// ---------------------------------------------------------------------------

function db_validate_api_key(string $apiKey): bool {
    if ($apiKey === '') {
        return false;
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, is_active FROM api_keys WHERE api_key = :api_key AND is_active = TRUE");
        $stmt->execute([':api_key' => $apiKey]);
        $result = $stmt->fetch();
        if ($result) {
            $up = $pdo->prepare("UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id");
            $up->execute([':id' => $result['id']]);
            return true;
        }
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function db_get_api_keys(): array {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT id, api_key, name, description, created_at, last_used_at, updated_at, is_active
        FROM api_keys
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll();
}

function db_insert_api_key(string $apiKey, string $name, ?string $description): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO api_keys (api_key, name, description) VALUES (:api_key, :name, :description)");
    $stmt->execute([':api_key' => $apiKey, ':name' => $name, ':description' => $description]);
}

function db_toggle_api_key(int $id, bool $isActive): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE api_keys SET is_active = :is_active WHERE id = :id");
    $stmt->execute([':id' => $id, ':is_active' => $isActive ? 1 : 0]);
}

function db_delete_api_key(int $id): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

/**
 * Generate and insert default API key. Returns the key or null on failure.
 */
function generateDefaultApiKey(PDO $pdo): ?string {
    try {
        $stmt = $pdo->query("SELECT api_key FROM api_keys WHERE name = 'Default API Key' LIMIT 1");
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing['api_key'];
        }
        $apiKey = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("
            INSERT INTO api_keys (api_key, name, description, is_active)
            VALUES (:api_key, 'Default API Key', 'Automatically generated default API key for the application', TRUE)
        ");
        $stmt->execute([':api_key' => $apiKey]);
        return $apiKey;
    } catch (PDOException $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>|null
 */
function db_get_user_by_email(string $email): ?array {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, email, password, firstname, lastname, type FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function db_update_user_password(string|int $userId, string $hash): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
    $stmt->execute([':password' => $hash, ':id' => $userId]);
}

function db_update_user_last_login(string|int $userId): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET date_last_login = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->execute([':id' => $userId]);
}

// ---------------------------------------------------------------------------
// Password reset tokens
// ---------------------------------------------------------------------------

/**
 * Generate a single-use password-reset token for a user.
 * Stores SHA-256 hash; returns the plaintext token (caller emails it in a URL).
 * Any prior unconsumed tokens for this user are invalidated so a fresh request
 * supersedes outdated links.
 */
function db_create_password_reset_token(string $userId, int $ttlSeconds = 86400): string {
    db_ensure_password_reset_tokens_table();
    $pdo = getDB();
    // Invalidate any previous unused tokens for this user.
    $pdo->prepare("UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND used_at IS NULL")
        ->execute([':uid' => $userId]);
    $token = bin2hex(random_bytes(32)); // 64 hex chars, ~256 bits of entropy
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        INSERT INTO password_reset_tokens (token_hash, user_id, expires_at)
        VALUES (:h, :uid, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :ttl SECOND))
    ");
    $stmt->execute([':h' => $hash, ':uid' => $userId, ':ttl' => max(60, $ttlSeconds)]);
    return $token;
}

/**
 * Look up a valid (unconsumed, unexpired) password-reset token. Returns the user row
 * if the token can be used, null otherwise. Does NOT consume the token — used by the
 * GET handler that decides whether to render the new-password form.
 *
 * @return array<string,mixed>|null
 */
function db_get_user_for_password_reset_token(string $token): ?array {
    if ($token === '' || strlen($token) !== 64) return null;
    db_ensure_password_reset_tokens_table();
    $pdo = getDB();
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.firstname, u.lastname, u.type
        FROM password_reset_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = :h AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
        LIMIT 1
    ");
    $stmt->execute([':h' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

/**
 * Consume a token and update the user's password atomically. Returns true if the password
 * was changed, false if the token was invalid/expired/used.
 */
function db_consume_password_reset_token(string $token, string $newPasswordHash): bool {
    if ($token === '' || strlen($token) !== 64) return false;
    db_ensure_password_reset_tokens_table();
    $pdo = getDB();
    $hash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT user_id FROM password_reset_tokens
            WHERE token_hash = :h AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return false;
        }
        $userId = (string) $row['user_id'];
        $pdo->prepare("UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE token_hash = :h")
            ->execute([':h' => $hash]);
        $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")
            ->execute([':p' => $newPasswordHash, ':id' => $userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('db_consume_password_reset_token error: ' . $e->getMessage());
        return false;
    }
}

function db_user_email_exists(string $email, ?string $excludeId = null): bool {
    $pdo = getDB();
    if ($excludeId !== null) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $stmt->execute([':email' => $email, ':id' => $excludeId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
    }
    return $stmt->fetch() !== false;
}

/**
 * @return list<array<string, mixed>>
 */
function db_get_users(): array {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT id, email, firstname, lastname, type, date_created, date_last_login, updated_at
        FROM users
        ORDER BY date_created DESC
    ");
    return $stmt->fetchAll();
}

function db_insert_user(string $id, string $email, string $hashedPassword, string $firstname, string $lastname, int $type): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO users (id, email, password, firstname, lastname, type)
        VALUES (:id, :email, :password, :firstname, :lastname, :type)
    ");
    $stmt->execute([
        ':id' => $id,
        ':email' => $email,
        ':password' => $hashedPassword,
        ':firstname' => $firstname,
        ':lastname' => $lastname,
        ':type' => $type
    ]);
}

function db_update_user(string $id, string $email, string $firstname, string $lastname, int $type, ?string $hashedPassword = null): void {
    $pdo = getDB();
    if ($hashedPassword !== null) {
        $stmt = $pdo->prepare("
            UPDATE users SET email = :email, firstname = :firstname, lastname = :lastname, password = :password, type = :type WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id, ':email' => $email, ':firstname' => $firstname, ':lastname' => $lastname,
            ':password' => $hashedPassword, ':type' => $type
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users SET email = :email, firstname = :firstname, lastname = :lastname, type = :type WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id, ':email' => $email, ':firstname' => $firstname, ':lastname' => $lastname, ':type' => $type
        ]);
    }
}

function db_delete_user(string $id): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

/** @return list<int> */
function db_get_user_constellation_ids(string $userId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT constellation_id FROM user_constellations WHERE user_id = :user_id ORDER BY constellation_id");
    $stmt->execute([':user_id' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function db_set_user_constellations(string $userId, array $constellationIds): void {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM user_constellations WHERE user_id = :user_id")->execute([':user_id' => $userId]);
    $constellationIds = array_unique(array_map('intval', $constellationIds));
    if ($constellationIds === []) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO user_constellations (user_id, constellation_id) VALUES (:user_id, :constellation_id)");
    foreach ($constellationIds as $cid) {
        $stmt->execute([':user_id' => $userId, ':constellation_id' => $cid]);
    }
}

function hasAdminUser(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE type = 2 LIMIT 1");
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Create admin user (type 2). Returns null on success, error message string on failure.
 */
function createAdminUser(PDO $pdo, string $email, string $password, string $firstname, string $lastname): ?string {
    try {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            return 'Email already exists';
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return 'Failed to hash password';
        }
        $userId = 'admin_' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("
            INSERT INTO users (id, email, password, firstname, lastname, type)
            VALUES (:id, :email, :password, :firstname, :lastname, 2)
        ");
        $stmt->execute([
            ':id' => $userId,
            ':email' => $email,
            ':password' => $hash,
            ':firstname' => $firstname,
            ':lastname' => $lastname
        ]);
        return null;
    } catch (PDOException $e) {
        error_log('createAdminUser PDOException: ' . $e->getMessage());
        return 'Database error while creating user. Please try again.';
    }
}

/**
 * Create user (editor or admin). Returns null on success, error message on failure.
 * $hashedPassword must already be hashed (e.g. by auth hashPassword).
 */
function createUser(PDO $pdo, string $email, string $hashedPassword, string $firstname, string $lastname, int $type): ?string {
    try {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            return 'Email already exists';
        }
        $userId = 'user_' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("
            INSERT INTO users (id, email, password, firstname, lastname, type)
            VALUES (:id, :email, :password, :firstname, :lastname, :type)
        ");
        $stmt->execute([
            ':id' => $userId,
            ':email' => $email,
            ':password' => $hashedPassword,
            ':firstname' => $firstname,
            ':lastname' => $lastname,
            ':type' => $type
        ]);
        return null;
    } catch (PDOException $e) {
        error_log('createUser PDOException: ' . $e->getMessage());
        return 'Database error while creating user. Please try again.';
    }
}

// ---------------------------------------------------------------------------
// Constellations
// ---------------------------------------------------------------------------

/**
 * Generate a URL-friendly slug from a string.
 * Replaces spaces with hyphens, omits special characters, and converts to lowercase.
 */
function db_slugify(string $text): string {
    // Replace spaces with hyphens
    $text = str_replace(' ', '-', $text);
    // Remove all characters that are not alphanumeric or hyphens
    $text = preg_replace('/[^a-z0-9\-]/i', '', $text);
    // Convert to lowercase
    $text = strtolower($text);
    // Collapse multiple hyphens and trim them from ends
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

/**
 * Return the display name for the default constellation: app name from project_info (en) if non-empty, else 'Default'.
 */
function db_default_constellation_name(PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT name FROM project_info WHERE locale = 'en' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $name = $row && isset($row['name']) ? trim((string) $row['name']) : '';
        return $name !== '' ? $name : 'Default';
    } catch (PDOException $e) {
        return 'Default';
    }
}

/**
 * Return the tagline for the default constellation: description from project_info (en) if non-empty, else ''.
 */
function db_default_constellation_tagline(PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT description FROM project_info WHERE locale = 'en' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $tagline = $row && isset($row['description']) ? trim((string) $row['description']) : '';
        return $tagline;
    } catch (PDOException $e) {
        return '';
    }
}

/**
 * Galaxy-typed constellations. Existing callers (editor dropdowns, portal-target pickers,
 * admin galaxy list) all want galaxies only — clusters are managed via db_get_clusters().
 *
 * @return list<array{id: int, name: string, tagline: string}>
 */
function db_get_constellations(): array {
    db_ensure_constellations_import_source_column();
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->query("SELECT id, name, tagline, slug, theme, import_source, created_at, updated_at FROM constellations WHERE `type` = 'galaxy' ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Extract a galaxy's "[PREFIX]" if its name starts with one, otherwise null.
 * Used so the autocomplete endpoints can surface vocabulary from sibling galaxies in
 * the same prefix group (editorial coherence within /[XX] unions).
 */
function db_extract_constellation_prefix(int $constellationId): ?string {
    $info = db_get_constellation_by_id($constellationId);
    if (!$info) return null;
    $name = (string) ($info['name'] ?? '');
    if (preg_match('/^\[([^\]]+)\]/', $name, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Resolve the IDs of all galaxies sharing the given galaxy's "[PREFIX]" prefix
 * (including the galaxy itself). Returns just the input ID if there's no prefix.
 *
 * @return list<int>
 */
function db_get_prefix_sibling_ids(int $constellationId): array {
    $prefix = db_extract_constellation_prefix($constellationId);
    if ($prefix === null) return [$constellationId];
    $rows = db_get_constellations_by_name_prefix($prefix);
    if ($rows === []) return [$constellationId];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    if (!in_array($constellationId, $ids, true)) $ids[] = $constellationId;
    return $ids;
}

/**
 * Find all constellations whose name starts with a literal "[PREFIX]" token (case-insensitive).
 * Used by the visitor view's prefix-grouped multigalaxy mode (e.g. /[TE] unions every galaxy whose
 * name begins with "[TE]"). Trim/case-fold the prefix on the caller side; this just does the SQL.
 *
 * @return list<array{id:int,name:string,slug:?string,theme:string}>
 */
function db_get_constellations_by_name_prefix(string $prefix): array {
    db_ensure_constellations_type_and_cluster_members();
    $needle = '[' . $prefix . ']';
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name, slug, theme FROM constellations WHERE name LIKE :p AND `type` = 'galaxy' ORDER BY id");
    // Escape LIKE wildcards in the supplied prefix; we want a literal prefix match.
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
    $stmt->execute([':p' => $escaped . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'slug' => $r['slug'] ?? null,
            'theme' => (string) ($r['theme'] ?? 'cosmic'),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Galaxy tags (multi-galaxy union by tag, /tag/foo)
// ---------------------------------------------------------------------------

/**
 * Tags currently assigned to a galaxy (slug + label).
 *
 * @return list<array{slug:string,label:string}>
 */
function db_get_tags_for_galaxy(int $constellationId): array {
    db_ensure_galaxy_tags_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT tag_slug, tag_label FROM galaxy_tags WHERE constellation_id = :cid ORDER BY tag_label");
    $stmt->execute([':cid' => $constellationId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['slug' => (string) $r['tag_slug'], 'label' => (string) $r['tag_label']];
    }
    return $out;
}

/**
 * Replace the set of tags on a galaxy. Each input is a free-form label; the slug is derived
 * via db_slugify(). Empty inputs are skipped. Existing rows for this galaxy are deleted before
 * inserting the new set, so callers don't need to diff client-side.
 *
 * @param list<string> $labels
 */
function db_set_tags_for_galaxy(int $constellationId, array $labels, ?string $createdBy = null): void {
    db_ensure_galaxy_tags_table();
    db_ensure_galaxy_tags_provenance_columns();
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // Delete-then-insert means we lose prior creator attribution on tag
        // rotations. That's correct: a tag re-added after removal is a fresh
        // editorial act and the new editor owns it. If a per-tag preservation
        // model is ever needed, switch to a diff-based update here.
        $del = $pdo->prepare("DELETE FROM galaxy_tags WHERE constellation_id = :cid");
        $del->execute([':cid' => $constellationId]);
        $ins = $pdo->prepare("INSERT IGNORE INTO galaxy_tags (constellation_id, tag_slug, tag_label, created_by) VALUES (:cid, :slug, :label, :created_by)");
        $seen = [];
        foreach ($labels as $raw) {
            $label = trim((string) $raw);
            if ($label === '') continue;
            $slug = db_slugify($label);
            if ($slug === '' || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $ins->execute([
                ':cid' => $constellationId,
                ':slug' => $slug,
                ':label' => $label,
                ':created_by' => $createdBy,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * All galaxies that carry a given tag (by slug). Used by the /tag/foo route.
 *
 * @return list<array{id:int,name:string,slug:?string,theme:string,tag_label:string}>
 */
function db_get_galaxies_for_tag(string $tagSlug): array {
    db_ensure_galaxy_tags_table();
    db_ensure_constellations_type_and_cluster_members();
    $tagSlug = trim($tagSlug);
    if ($tagSlug === '') return [];
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.slug, c.theme, gt.tag_label
        FROM galaxy_tags gt
        JOIN constellations c ON c.id = gt.constellation_id
        WHERE gt.tag_slug = :s AND c.`type` = 'galaxy'
        ORDER BY c.id
    ");
    $stmt->execute([':s' => $tagSlug]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'slug' => $r['slug'] ?? null,
            'theme' => (string) ($r['theme'] ?? 'cosmic'),
            'tag_label' => (string) ($r['tag_label'] ?? ''),
        ];
    }
    return $out;
}

/**
 * For a given tag slug, return the most-frequently-used label among assigned galaxies.
 * Stable canonical display when editors have spelled the same tag with different casing.
 */
function db_get_canonical_label_for_tag(string $tagSlug): ?string {
    db_ensure_galaxy_tags_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT tag_label, COUNT(*) AS c
        FROM galaxy_tags
        WHERE tag_slug = :s
        GROUP BY tag_label
        ORDER BY c DESC, tag_label ASC
        LIMIT 1
    ");
    $stmt->execute([':s' => $tagSlug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string) $row['tag_label'] : null;
}

/**
 * All known tags with global counts. Used as the autocomplete fallback pool.
 *
 * @return list<array{slug:string,label:string,count:int}>
 */
function db_get_all_tags_with_counts(): array {
    db_ensure_galaxy_tags_table();
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT tag_slug, tag_label, COUNT(*) AS c
        FROM galaxy_tags
        GROUP BY tag_slug, tag_label
        ORDER BY c DESC, tag_label ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Collapse duplicate slugs with different labels: keep the highest-count row per slug.
    $bySlug = [];
    foreach ($rows as $r) {
        $slug = (string) $r['tag_slug'];
        if (isset($bySlug[$slug])) continue; // already saw a higher-count label
        $bySlug[$slug] = [
            'slug' => $slug,
            'label' => (string) $r['tag_label'],
            'count' => (int) $r['c'],
        ];
    }
    return array_values($bySlug);
}

/**
 * Tags assigned to the listed galaxies (used to score autocomplete suggestions).
 *
 * @param list<int> $constellationIds
 * @return list<array{slug:string,label:string,count:int}>
 */
function db_get_tags_for_galaxies(array $constellationIds): array {
    db_ensure_galaxy_tags_table();
    $ids = array_values(array_unique(array_map('intval', $constellationIds)));
    if ($ids === []) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT tag_slug, tag_label, COUNT(*) AS c
        FROM galaxy_tags
        WHERE constellation_id IN ($placeholders)
        GROUP BY tag_slug, tag_label
        ORDER BY c DESC, tag_label ASC
    ");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $bySlug = [];
    foreach ($rows as $r) {
        $slug = (string) $r['tag_slug'];
        if (isset($bySlug[$slug])) continue;
        $bySlug[$slug] = [
            'slug' => $slug,
            'label' => (string) $r['tag_label'],
            'count' => (int) $r['c'],
        ];
    }
    return array_values($bySlug);
}

/**
 * Keywords used by every node across the listed galaxies (used by wormhole-keyword
 * autocomplete that surfaces sibling-galaxy vocabulary).
 *
 * @param list<int> $constellationIds
 * @return list<array{keyword:string,count:int}>
 */
function db_get_keywords_for_galaxies(array $constellationIds): array {
    $ids = array_values(array_unique(array_map('intval', $constellationIds)));
    if ($ids === []) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT k.keyword, COUNT(DISTINCT nk.node_id) AS c
        FROM keywords k
        JOIN node_keywords nk ON nk.keyword_id = k.id
        JOIN nodes n ON n.id = nk.node_id
        WHERE n.constellation_id IN ($placeholders)
        GROUP BY k.keyword
        ORDER BY c DESC, k.keyword ASC
    ");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['keyword' => (string) $r['keyword'], 'count' => (int) $r['c']];
    }
    return $out;
}

/**
 * Resolve a galaxy's "group" — the union of every galaxy that should be treated
 * as a sibling for cross-galaxy discovery features:
 *   - prefix-family siblings (galaxies sharing a "[XX]" name prefix)
 *   - galaxies sharing any of this galaxy's tags
 *   - co-members of any cluster this galaxy belongs to
 * Always includes the galaxy itself. Result is deduped, returns int IDs only.
 *
 * @return list<int>
 */
function db_get_group_galaxy_ids(int $constellationId): array {
    $ids = [$constellationId];
    foreach (db_get_prefix_sibling_ids($constellationId) as $sibId) {
        $ids[] = (int) $sibId;
    }
    foreach (db_get_tags_for_galaxy($constellationId) as $tag) {
        foreach (db_get_galaxies_for_tag((string) $tag['slug']) as $g) {
            $ids[] = (int) $g['id'];
        }
    }
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT DISTINCT cluster_id FROM galaxy_cluster_members WHERE member_id = :mid");
    $stmt->execute([':mid' => $constellationId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $clusterId) {
        foreach (db_get_cluster_member_ids((int) $clusterId) as $memberId) {
            $ids[] = (int) $memberId;
        }
    }
    return array_values(array_unique(array_map('intval', $ids)));
}

/**
 * Top-N wormholes that share at least one keyword with the source node, drawn from
 * a given pool of galaxies. Cluster nodes are excluded. Within each shared-keyword-count
 * tier, candidates from sibling galaxies (i.e. constellation_id != $sourceGalaxyId) are
 * given a stochastic boost so they're more likely (but not guaranteed) to surface
 * earlier than same-galaxy candidates — prevents the chip row from looking parochial
 * while still allowing same-galaxy candidates through occasionally.
 *
 * @param list<int> $galaxyIds
 * @return list<array{id:int,name:string,constellation_id:int,constellation_slug:?string,shared:int}>
 */
function db_get_related_nodes(int $sourceNodeId, int $sourceGalaxyId, array $galaxyIds, int $limit = 5): array {
    if ($limit <= 0) return [];
    db_ensure_nodes_node_type_index();
    $galaxyIds = array_values(array_unique(array_map('intval', $galaxyIds)));
    if ($galaxyIds === []) return [];
    $placeholders = implode(',', array_fill(0, count($galaxyIds), '?'));

    // Cross-galaxy match by keyword *name* (case-insensitive), not keyword_id —
    // each galaxy has its own copy of "Ideology" with a different ID, so an
    // ID-only join would only find same-galaxy candidates.
    $sql = "
        SELECT n.id, n.name, n.constellation_id, c.slug AS constellation_slug,
               COUNT(DISTINCT LOWER(TRIM(k1.keyword))) AS shared
        FROM node_keywords nk1
        INNER JOIN keywords k1 ON k1.id = nk1.keyword_id
        INNER JOIN keywords k2 ON LOWER(TRIM(k2.keyword)) = LOWER(TRIM(k1.keyword))
        INNER JOIN node_keywords nk2 ON nk2.keyword_id = k2.id AND nk2.node_id != nk1.node_id
        INNER JOIN nodes n ON n.id = nk2.node_id
        INNER JOIN constellations c ON c.id = n.constellation_id
        WHERE nk1.node_id = ? AND n.constellation_id IN ($placeholders) AND n.node_type != 'cluster'
        GROUP BY n.id, n.name, n.constellation_id, c.slug
        ORDER BY shared DESC, (RAND() + IF(n.constellation_id != ?, 0.4, 0)) DESC
        LIMIT ?
    ";
    $pdo = getDB();
    $stmt = $pdo->prepare($sql);
    $idx = 1;
    $stmt->bindValue($idx++, $sourceNodeId, PDO::PARAM_INT);
    foreach ($galaxyIds as $gid) {
        $stmt->bindValue($idx++, $gid, PDO::PARAM_INT);
    }
    $stmt->bindValue($idx++, $sourceGalaxyId, PDO::PARAM_INT);
    $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'constellation_id' => (int) $r['constellation_id'],
            'constellation_slug' => $r['constellation_slug'] !== null ? (string) $r['constellation_slug'] : null,
            'shared' => (int) $r['shared'],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Galaxy clusters (Idea 2 — first-class union object)
// ---------------------------------------------------------------------------
// Clusters are constellation rows with type='cluster'. They have no native wormholes;
// their nodes come from members via the multigalaxy pipeline. The galaxy_cluster_members
// junction stores membership; position is reserved for ordering (defaults to 0 in v1).

/**
 * Member galaxy IDs for a cluster, in insertion order.
 *
 * @return list<int>
 */
function db_get_cluster_member_ids(int $clusterId): array {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT m.member_id
        FROM galaxy_cluster_members m
        JOIN constellations c ON c.id = m.member_id AND c.`type` = 'galaxy'
        WHERE m.cluster_id = :cid
        ORDER BY m.position ASC, m.member_id ASC
    ");
    $stmt->execute([':cid' => $clusterId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Replace the set of members on a cluster. Non-galaxy IDs are silently dropped.
 * Position is the index in the input list.
 *
 * @param list<int> $memberIds
 */
function db_set_cluster_members(int $clusterId, array $memberIds): void {
    db_ensure_constellations_type_and_cluster_members();
    $ids = array_values(array_unique(array_map('intval', $memberIds)));
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM galaxy_cluster_members WHERE cluster_id = :cid");
        $del->execute([':cid' => $clusterId]);

        if ($ids !== []) {
            // Validate each candidate is a galaxy (not a cluster, not the cluster itself).
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $vstmt = $pdo->prepare("SELECT id FROM constellations WHERE id IN ($placeholders) AND `type` = 'galaxy'");
            $vstmt->execute($ids);
            $valid = array_map('intval', $vstmt->fetchAll(PDO::FETCH_COLUMN));
            $validSet = array_flip($valid);

            $ins = $pdo->prepare("INSERT INTO galaxy_cluster_members (cluster_id, member_id, position) VALUES (:cid, :mid, :pos)");
            $position = 0;
            foreach ($ids as $mid) {
                if ($mid === $clusterId) continue;     // cluster can't be its own member
                if (!isset($validSet[$mid])) continue; // non-galaxy or unknown → skip
                $ins->execute([':cid' => $clusterId, ':mid' => $mid, ':pos' => $position++]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Create a cluster row in constellations + populate its members.
 *
 * @param list<int> $memberIds
 */
function db_create_cluster(string $name, string $tagline = '', ?string $slug = null, string $theme = 'cosmic', array $memberIds = [], bool $showGalaxyList = false): int {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $finalSlug = ($slug !== null && $slug !== '') ? $slug : db_slugify($name);
    $stmt = $pdo->prepare("INSERT INTO constellations (name, tagline, slug, theme, `type`, show_galaxy_list) VALUES (:name, :tagline, :slug, :theme, 'cluster', :sgl)");
    $stmt->execute([
        ':name' => $name,
        ':tagline' => $tagline,
        ':slug' => $finalSlug,
        ':theme' => $theme,
        ':sgl' => $showGalaxyList ? 1 : 0,
    ]);
    $clusterId = (int) $pdo->lastInsertId();
    if ($memberIds !== []) {
        db_set_cluster_members($clusterId, $memberIds);
    }
    return $clusterId;
}

/**
 * Update a cluster's metadata. Members are passed separately via db_set_cluster_members.
 */
function db_update_cluster(int $id, string $name, string $tagline = '', ?string $slug = null, string $theme = 'cosmic', bool $showGalaxyList = false): void {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $finalSlug = ($slug !== null && $slug !== '') ? $slug : db_slugify($name);
    $stmt = $pdo->prepare("UPDATE constellations SET name = :name, tagline = :tagline, slug = :slug, theme = :theme, show_galaxy_list = :sgl WHERE id = :id AND `type` = 'cluster'");
    $stmt->execute([
        ':id' => $id,
        ':name' => $name,
        ':tagline' => $tagline,
        ':slug' => $finalSlug,
        ':theme' => $theme,
        ':sgl' => $showGalaxyList ? 1 : 0,
    ]);
}

/**
 * Delete a cluster row. ON DELETE CASCADE on the members FK takes care of the junction.
 */
function db_delete_cluster(int $id): void {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $pdo->prepare("DELETE FROM constellations WHERE id = :id AND `type` = 'cluster'")->execute([':id' => $id]);
}

/**
 * List all clusters with their member counts (for the admin list view).
 *
 * @return list<array{id:int,name:string,tagline:string,slug:?string,theme:string,member_count:int,created_at:?string,updated_at:?string}>
 */
function db_get_clusters(): array {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.tagline, c.slug, c.theme, c.show_galaxy_list, c.created_at, c.updated_at,
               (SELECT COUNT(*) FROM galaxy_cluster_members m WHERE m.cluster_id = c.id) AS member_count
        FROM constellations c
        WHERE c.`type` = 'cluster'
        ORDER BY c.id
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'tagline' => (string) ($r['tagline'] ?? ''),
            'slug' => $r['slug'] ?? null,
            'theme' => (string) ($r['theme'] ?? 'cosmic'),
            'show_galaxy_list' => (bool)($r['show_galaxy_list'] ?? false),
            'member_count' => (int) $r['member_count'],
            'created_at' => $r['created_at'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
        ];
    }
    return $out;
}

/**
 * Server-side paginated, sorted, filtered cluster query — the cluster mirror of
 * db_get_constellations_paginated(). Returns rows with member_count, theme,
 * show_galaxy_list, and the visitor-facing discovery flags (tour_enabled,
 * idle_spotlight_enabled) so the admin list can render the same kind of
 * inline status badges the galaxy list does.
 *
 * @return array{clusters: list<array>, total: int, page: int, per_page: int}
 */
function db_get_clusters_paginated(
    int $page = 1,
    int $perPage = 20,
    ?string $sort = null,
    string $order = 'asc',
    ?string $filter = null
): array {
    db_ensure_constellations_type_and_cluster_members();
    db_ensure_constellations_tour_columns();
    $pdo = getDB();

    $where = ["c.`type` = 'cluster'"];
    $params = [];
    if ($filter !== null && $filter !== '') {
        $filterVal = '%' . $filter . '%';
        $where[] = "(c.name LIKE :filter1 OR c.tagline LIKE :filter2 OR c.slug LIKE :filter3 OR CAST(c.id AS CHAR) LIKE :filter4)";
        $params[':filter1'] = $filterVal;
        $params[':filter2'] = $filterVal;
        $params[':filter3'] = $filterVal;
        $params[':filter4'] = $filterVal;
    }
    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM constellations c {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sortMap = [
        'id' => 'c.id',
        'name' => 'c.name',
        'slug' => 'c.slug',
        'tagline' => 'c.tagline',
        'theme' => 'c.theme',
        'member_count' => 'member_count',
        'created_at' => 'c.created_at',
        'updated_at' => 'c.updated_at',
    ];
    $orderDir = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
    $orderClause = 'ORDER BY c.id ASC';
    if ($sort !== null && isset($sortMap[$sort])) {
        $orderClause = "ORDER BY {$sortMap[$sort]} {$orderDir}, c.id ASC";
    }

    $offset = ($page - 1) * $perPage;
    $dataStmt = $pdo->prepare("
        SELECT c.id, c.name, c.tagline, c.slug, c.theme, c.show_galaxy_list,
               c.tour_enabled, c.idle_spotlight_enabled,
               c.created_at, c.updated_at,
               (SELECT COUNT(*) FROM galaxy_cluster_members m WHERE m.cluster_id = c.id) AS member_count
        FROM constellations c
        {$whereClause}
        {$orderClause}
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $clusters = [];
    foreach ($rows as $r) {
        $clusters[] = [
            'id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'tagline' => (string) ($r['tagline'] ?? ''),
            'slug' => $r['slug'] ?? null,
            'theme' => (string) ($r['theme'] ?? 'cosmic'),
            'show_galaxy_list' => (bool) ($r['show_galaxy_list'] ?? false),
            'tour_enabled' => (bool) ($r['tour_enabled'] ?? false),
            'idle_spotlight_enabled' => (bool) ($r['idle_spotlight_enabled'] ?? false),
            'member_count' => (int) $r['member_count'],
            'created_at' => $r['created_at'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
        ];
    }

    return ['clusters' => $clusters, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

/**
 * Server-side paginated, sorted, filtered constellation query.
 * @return array{constellations: list<array>, total: int, page: int, per_page: int}
 */
function db_get_constellations_paginated(
    int $page = 1,
    int $perPage = 20,
    ?string $sort = null,
    string $order = 'asc',
    ?string $filter = null
): array {
    db_ensure_constellations_import_source_column();
    db_ensure_constellations_tour_columns();
    $pdo = getDB();

    db_ensure_constellations_type_and_cluster_members();
    $where = ["c.`type` = 'galaxy'"];
    $params = [];

    if ($filter !== null && $filter !== '') {
        $filterVal = '%' . $filter . '%';
        $where[] = "(c.name LIKE :filter1 OR c.tagline LIKE :filter2 OR c.slug LIKE :filter3 OR CAST(c.id AS CHAR) LIKE :filter4)";
        $params[':filter1'] = $filterVal;
        $params[':filter2'] = $filterVal;
        $params[':filter3'] = $filterVal;
        $params[':filter4'] = $filterVal;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM constellations c {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sortMap = [
        'id' => 'c.id',
        'name' => 'c.name',
        'slug' => 'c.slug',
        'tagline' => 'c.tagline',
        'created_at' => 'c.created_at',
        'updated_at' => 'c.updated_at',
        'node_count' => 'node_count',
    ];
    $orderDir = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
    $orderClause = 'ORDER BY c.id ASC';
    if ($sort !== null && isset($sortMap[$sort])) {
        $orderClause = "ORDER BY {$sortMap[$sort]} {$orderDir}, c.id ASC";
    }

    $offset = ($page - 1) * $perPage;
    // node_count comes from a derived table (one GROUP BY pass over nodes) instead of
    // a correlated subquery (one COUNT per row, O(N×M)). On a 6000+ node DB the
    // derived-table plan reads the constellation_id index once and is dramatically
    // cheaper. Galaxies with zero nodes still appear thanks to LEFT JOIN + COALESCE.
    $dataStmt = $pdo->prepare("
        SELECT c.id, c.name, c.tagline, c.slug, c.theme, c.import_source, c.tour_enabled,
               c.created_at, c.updated_at,
               COALESCE(nc.node_count, 0) AS node_count
        FROM constellations c
        LEFT JOIN (
            SELECT constellation_id, COUNT(*) AS node_count
            FROM nodes
            GROUP BY constellation_id
        ) nc ON nc.constellation_id = c.id
        {$whereClause}
        {$orderClause}
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    return ['constellations' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

/**
 * Constellations visible to a user: admins see all; editors see only those assigned to them.
 * @param string|null $userId Current user id (session)
 * @param bool $isAdmin Whether the current user is an admin
 * @return list<array{id: int, name: string, tagline: string}>
 */
function db_get_constellations_for_user(?string $userId, bool $isAdmin): array {
    if ($isAdmin) {
        return db_get_constellations();
    }
    if ($userId === null || $userId === '') {
        return [];
    }
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.tagline, c.slug, c.theme, c.import_source, c.created_at, c.updated_at
        FROM constellations c
        INNER JOIN user_constellations uc ON uc.constellation_id = c.id AND uc.user_id = :user_id
        WHERE c.`type` = 'galaxy'
        ORDER BY c.id
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get one constellation by id (name and tagline for main view).
 * @return array{name: string, tagline: string, theme: string}|null
 */
function db_get_constellation_by_id(int $id): ?array {
    db_ensure_constellations_import_source_column();
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT name, tagline, slug, theme, import_source, `type`, show_galaxy_list FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'name' => (string) ($row['name'] ?? ''),
        'tagline' => (string) ($row['tagline'] ?? ''),
        'slug' => $row['slug'],
        'theme' => (string) ($row['theme'] ?? 'cosmic'),
        'import_source' => $row['import_source'] ?? null,
        'type' => (string) ($row['type'] ?? 'galaxy'),
        'show_galaxy_list' => (bool)($row['show_galaxy_list'] ?? false),
    ];
}

function db_set_constellation_import_source(int $id, ?string $importSource): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE constellations SET import_source = :import_source WHERE id = :id");
    $stmt->execute([':import_source' => $importSource, ':id' => $id]);
}

function db_get_constellation_import_source(int $id): ?string {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT import_source FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ($row['import_source'] !== null ? (string)$row['import_source'] : null) : null;
}

function db_clear_constellation_nodes(int $constellationId): void {
    db_bulk_delete_nodes_by_constellation($constellationId);
    // Delete orphan keywords (keywords with no node_keywords references)
    $pdo = getDB();
    $pdo->prepare("
        DELETE k FROM keywords k
        LEFT JOIN node_keywords nk ON nk.keyword_id = k.id
        WHERE k.constellation_id = :cid AND nk.id IS NULL
    ")->execute([':cid' => $constellationId]);
}

/**
 * Bulk-delete every node in a constellation in one SQL round-trip, while preserving
 * the on-disk file cleanup that db_delete_node() does per-node.
 *
 * The naive loop calls db_delete_node() once per row, which means N SELECTs + N DELETEs
 * (and on a big import that's thousands of round-trips). Here we read all asset paths
 * in one query, run a single DELETE, then unlink files after the DB succeeds.
 * node_keywords rows are FK-cascaded on nodes.id.
 */
function db_bulk_delete_nodes_by_constellation(int $constellationId): void {
    $pdo = getDB();
    // 1. Pull every asset path in one query, so file cleanup matches per-row semantics.
    $stmt = $pdo->prepare("
        SELECT image_url, icon_url, audio_url, video_url, pdf_url
        FROM nodes WHERE constellation_id = :cid
    ");
    $stmt->execute([':cid' => $constellationId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return;

    $uploadDir = UPLOAD_DIR;
    $filesToDelete = [];
    foreach ($rows as $row) {
        foreach (['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'] as $col) {
            $val = $row[$col] ?? null;
            if ($val && str_starts_with((string)$val, 'uploads/')) {
                $fullPath = str_replace('uploads/', $uploadDir . '/', (string)$val);
                if (file_exists($fullPath)) {
                    $filesToDelete[] = $fullPath;
                }
            }
        }
    }

    // 2. Single batch DELETE. node_keywords rows cascade via FK.
    $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :cid")
        ->execute([':cid' => $constellationId]);

    // 3. Unlink files only after the DB delete succeeded.
    foreach ($filesToDelete as $path) {
        @unlink($path);
    }
}

/**
 * Get one constellation by slug.
 * @return array{id: int, name: string, tagline: string, theme: string}|null
 */
function db_get_constellation_by_slug(string $slug): ?array {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name, tagline, theme, `type` FROM constellations WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Fallback: check if any constellation name slugifies to this value
        $all = $pdo->query("SELECT id, name, tagline, slug, theme, `type` FROM constellations");
        while ($c = $all->fetch(PDO::FETCH_ASSOC)) {
            if (db_slugify($c['name']) === strtolower($slug)) {
                $row = $c;
                break;
            }
        }
    }

    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'tagline' => (string) ($row['tagline'] ?? ''),
        'theme' => (string) ($row['theme'] ?? 'cosmic'),
        'type' => (string) ($row['type'] ?? 'galaxy'),
    ];
}

/**
 * Check if a constellation name or slug already exists.
 * @return array{name: bool, slug: bool}
 */
function db_constellation_exists(string $name, ?string $slug = null, ?int $excludeId = null): array {
    $pdo = getDB();
    $name = trim($name);
    $slug = ($slug !== null) ? trim($slug) : null;
    
    $out = ['name' => false, 'slug' => false];
    
    // Check name
    $sql = "SELECT id FROM constellations WHERE name = :name";
    if ($excludeId !== null) $sql .= " AND id != :exclude_id";
    $stmt = $pdo->prepare($sql);
    $params = [':name' => $name];
    if ($excludeId !== null) $params[':exclude_id'] = $excludeId;
    $stmt->execute($params);
    if ($stmt->fetch()) $out['name'] = true;
    
    // Check slug
    if ($slug !== null && $slug !== '') {
        $sql = "SELECT id FROM constellations WHERE slug = :slug";
        if ($excludeId !== null) $sql .= " AND id != :exclude_id";
        $stmt = $pdo->prepare($sql);
        $params = [':slug' => $slug];
        if ($excludeId !== null) $params[':exclude_id'] = $excludeId;
        $stmt->execute($params);
        if ($stmt->fetch()) $out['slug'] = true;
    }
    
    return $out;
}

/**
 * Create a new constellation with the next available id. Returns the new id.
 *
 * @param string|null $createdBy Optional user id (users.id, VARCHAR) to record
 *        as the creator. NULL leaves provenance unattributed (system imports,
 *        backup restores, pre-session contexts).
 */
function db_create_constellation(string $name, string $tagline = '', ?string $slug = null, string $theme = 'cosmic', ?string $createdBy = null): int {
    db_ensure_constellations_created_by_column();
    $pdo = getDB();

    $name = trim($name) ?: 'Unnamed';
    if ($slug === null || trim($slug) === '') {
        $slug = db_slugify($name);
    }

    $pdo->prepare("INSERT INTO constellations (name, tagline, slug, theme, created_by) VALUES (:name, :tagline, :slug, :theme, :created_by)")->execute([
        ':name' => $name,
        ':tagline' => trim($tagline),
        ':slug' => trim($slug),
        ':theme' => $theme,
        ':created_by' => $createdBy,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Duplicate a constellation, including all its nodes and keywords.
 * Also copies uploaded files for each node to ensure the duplicate has its own copies.
 */
function db_duplicate_constellation(int $sourceId, string $newName, string $newTagline = '', ?string $newSlug = null): int {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // 1. Get source constellation for theme
        $stmt = $pdo->prepare("SELECT theme FROM constellations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $sourceId]);
        $source = $stmt->fetch();
        if (!$source) throw new Exception("Source constellation not found.");
        
        $theme = $source['theme'] ?? 'cosmic';

        // 2. Create the new constellation
        $newId = db_create_constellation($newName, $newTagline, $newSlug, $theme);

        // 3. Duplicate Keywords
        // Keywords are constellation-specific in this schema.
        $stmt = $pdo->prepare("SELECT id, keyword FROM keywords WHERE constellation_id = :sid");
        $stmt->execute([':sid' => $sourceId]);
        $oldToNewKeywordIds = [];
        $insertKw = $pdo->prepare("INSERT INTO keywords (constellation_id, keyword) VALUES (:cid, :kw)");
        
        while ($kwRow = $stmt->fetch()) {
            $insertKw->execute([':cid' => $newId, ':kw' => $kwRow['keyword']]);
            $oldToNewKeywordIds[$kwRow['id']] = (int)$pdo->lastInsertId();
        }

        // 4. Duplicate Nodes
        $stmt = $pdo->prepare("SELECT * FROM nodes WHERE constellation_id = :sid");
        $stmt->execute([':sid' => $sourceId]);
        $nodes = $stmt->fetchAll();

        $insertNode = $pdo->prepare("
            INSERT INTO nodes (constellation_id, name, description, url, image_url, embed_code, audio_url, audio_autoplay, audio_loop, video_url, video_autoplay, node_type, target_constellation_id, is_accentuated, created_by, animation)
            VALUES (:cid, :name, :description, :url, :image_url, :embed_code, :audio_url, :audio_autoplay, :audio_loop, :video_url, :video_autoplay, :node_type, :target_constellation_id, :is_accentuated, :created_by, :animation)
        ");

        $insertNodeKw = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid)");
        
        $uploadDir = UPLOAD_DIR;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($nodes as $node) {
            $newNodeImageUrl = $node['image_url'];
            $newNodeAudioUrl = $node['audio_url'];
            $newNodeVideoUrl = $node['video_url'];

            // Duplicate files if they are in the uploads directory
            if ($newNodeImageUrl && str_starts_with($newNodeImageUrl, 'uploads/')) {
                $oldPath = str_replace('uploads/', $uploadDir . '/', $newNodeImageUrl);
                if (file_exists($oldPath)) {
                    $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
                    $newFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                    $newPath = $uploadDir . '/' . $newFilename;
                    if (copy($oldPath, $newPath)) {
                        $newNodeImageUrl = 'uploads/' . $newFilename;
                    }
                }
            }

            if ($newNodeAudioUrl && str_starts_with($newNodeAudioUrl, 'uploads/')) {
                $oldPath = str_replace('uploads/', $uploadDir . '/', $newNodeAudioUrl);
                if (file_exists($oldPath)) {
                    $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
                    $newFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                    $newPath = $uploadDir . '/' . $newFilename;
                    if (copy($oldPath, $newPath)) {
                        $newNodeAudioUrl = 'uploads/' . $newFilename;
                    }
                }
            }

            if ($newNodeVideoUrl && str_starts_with($newNodeVideoUrl, 'uploads/')) {
                $oldPath = str_replace('uploads/', $uploadDir . '/', $newNodeVideoUrl);
                if (file_exists($oldPath)) {
                    $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
                    $newFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                    $newPath = $uploadDir . '/' . $newFilename;
                    if (copy($oldPath, $newPath)) {
                        $newNodeVideoUrl = 'uploads/' . $newFilename;
                    }
                }
            }

            $insertNode->execute([
                ':cid' => $newId,
                ':name' => $node['name'],
                ':description' => $node['description'],
                ':url' => $node['url'],
                ':image_url' => $newNodeImageUrl,
                ':embed_code' => $node['embed_code'],
                ':audio_url' => $newNodeAudioUrl,
                ':audio_autoplay' => $node['audio_autoplay'],
                ':audio_loop' => $node['audio_loop'] ?? 0,
                ':video_url' => $newNodeVideoUrl,
                ':video_autoplay' => $node['video_autoplay'],
                ':node_type' => $node['node_type'],
                ':target_constellation_id' => $node['target_constellation_id'],
                ':is_accentuated' => $node['is_accentuated'],
                ':created_by' => $node['created_by'],
                ':animation' => $node['animation']
            ]);
            $newNodeId = (int)$pdo->lastInsertId();

            // Link keywords to the new node
            $stmtKw = $pdo->prepare("SELECT keyword_id FROM node_keywords WHERE node_id = :nid");
            $stmtKw->execute([':nid' => $node['id']]);
            while ($nkRow = $stmtKw->fetch()) {
                $oldKid = $nkRow['keyword_id'];
                if (isset($oldToNewKeywordIds[$oldKid])) {
                    $insertNodeKw->execute([':nid' => $newNodeId, ':kid' => $oldToNewKeywordIds[$oldKid]]);
                }
            }
        }

        $pdo->commit();
        return $newId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Update constellation name and tagline. Id cannot be changed. Default constellation (id=0) can be renamed.
 */
function db_update_constellation(int $id, string $name, string $tagline = '', ?string $slug = null, string $theme = 'cosmic'): void {
    $pdo = getDB();
    
    $name = trim($name) ?: 'Unnamed';
    if ($slug === null || trim($slug) === '') {
        $slug = db_slugify($name);
    }

    $pdo->prepare("UPDATE constellations SET name = :name, tagline = :tagline, slug = :slug, theme = :theme WHERE id = :id")->execute([
        ':name' => $name,
        ':tagline' => trim($tagline),
        ':slug' => trim($slug),
        ':theme' => $theme,
        ':id' => $id
    ]);
}

/**
 * Read the auto-tour config for a constellation.
 * @return array{
 *   tour_enabled: bool,
 *   tour_start_mode: string,
 *   tour_idle_seconds: int,
 *   tour_node_selection: string,
 *   tour_random_count: int,
 *   tour_default_dwell: int,
 *   tour_loop: bool,
 *   tour_keyword_ids: list<int>
 * }|null
 */
function db_get_constellation_tour_config(int $id): ?array {
    db_ensure_constellations_tour_columns();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT tour_enabled, tour_start_mode, tour_idle_seconds, tour_node_selection,
               tour_random_count, tour_default_dwell, tour_loop, keyword_chips_enabled,
               idle_spotlight_enabled, idle_spotlight_selection, idle_spotlight_idle_seconds,
               related_nodes_enabled, show_2d_view
        FROM constellations WHERE id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'tour_enabled' => (bool)$row['tour_enabled'],
        'tour_start_mode' => (string)$row['tour_start_mode'],
        'tour_idle_seconds' => (int)$row['tour_idle_seconds'],
        'tour_node_selection' => (string)$row['tour_node_selection'],
        'tour_random_count' => (int)$row['tour_random_count'],
        'tour_default_dwell' => (int)$row['tour_default_dwell'],
        'tour_loop' => (bool)$row['tour_loop'],
        'tour_keyword_ids' => db_get_tour_keyword_ids($id),
        'keyword_chips_enabled' => (bool)$row['keyword_chips_enabled'],
        'idle_spotlight_enabled' => (bool)$row['idle_spotlight_enabled'],
        'idle_spotlight_selection' => (string)$row['idle_spotlight_selection'],
        'idle_spotlight_idle_seconds' => (int)$row['idle_spotlight_idle_seconds'],
        'related_nodes_enabled' => (bool)$row['related_nodes_enabled'],
        'show_2d_view' => (bool)$row['show_2d_view'],
    ];
}

/**
 * Persist tour config for a constellation. Validates enums; clamps numerics.
 */
function db_set_constellation_tour_config(int $id, array $config): void {
    db_ensure_constellations_tour_columns();
    $validStartModes = ['immediate', 'idle', 'manual'];
    $validSelections = ['all', 'accentuated', 'random_n', 'tagged'];

    $startMode = (string)($config['tour_start_mode'] ?? 'manual');
    if (!in_array($startMode, $validStartModes, true)) {
        $startMode = 'manual';
    }
    $selection = (string)($config['tour_node_selection'] ?? 'all');
    if (!in_array($selection, $validSelections, true)) {
        $selection = 'all';
    }

    $idleSeconds = max(1, (int)($config['tour_idle_seconds'] ?? 30));
    $randomCount = max(1, (int)($config['tour_random_count'] ?? 10));
    $defaultDwell = max(1, (int)($config['tour_default_dwell'] ?? 8));

    $idleSpotlightSelection = (string)($config['idle_spotlight_selection'] ?? 'all');
    if (!in_array($idleSpotlightSelection, ['all', 'accentuated'], true)) {
        $idleSpotlightSelection = 'all';
    }
    $idleSpotlightIdleSeconds = max(1, (int)($config['idle_spotlight_idle_seconds'] ?? 30));

    $pdo = getDB();
    $pdo->prepare("
        UPDATE constellations SET
            tour_enabled = :tour_enabled,
            tour_start_mode = :tour_start_mode,
            tour_idle_seconds = :tour_idle_seconds,
            tour_node_selection = :tour_node_selection,
            tour_random_count = :tour_random_count,
            tour_default_dwell = :tour_default_dwell,
            tour_loop = :tour_loop,
            keyword_chips_enabled = :keyword_chips_enabled,
            idle_spotlight_enabled = :idle_spotlight_enabled,
            idle_spotlight_selection = :idle_spotlight_selection,
            idle_spotlight_idle_seconds = :idle_spotlight_idle_seconds,
            related_nodes_enabled = :related_nodes_enabled,
            show_2d_view = :show_2d_view
        WHERE id = :id
    ")->execute([
        ':tour_enabled' => !empty($config['tour_enabled']) ? 1 : 0,
        ':tour_start_mode' => $startMode,
        ':tour_idle_seconds' => $idleSeconds,
        ':tour_node_selection' => $selection,
        ':tour_random_count' => $randomCount,
        ':tour_default_dwell' => $defaultDwell,
        ':tour_loop' => !empty($config['tour_loop']) ? 1 : 0,
        ':keyword_chips_enabled' => !empty($config['keyword_chips_enabled']) ? 1 : 0,
        ':idle_spotlight_enabled' => !empty($config['idle_spotlight_enabled']) ? 1 : 0,
        ':idle_spotlight_selection' => $idleSpotlightSelection,
        ':idle_spotlight_idle_seconds' => $idleSpotlightIdleSeconds,
        ':related_nodes_enabled' => !empty($config['related_nodes_enabled']) ? 1 : 0,
        ':show_2d_view' => !empty($config['show_2d_view']) ? 1 : 0,
        ':id' => $id,
    ]);
}

/**
 * @return list<int>
 */
function db_get_tour_keyword_ids(int $constellationId): array {
    db_ensure_constellations_tour_columns();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT keyword_id FROM constellation_tour_keywords WHERE constellation_id = :cid ORDER BY keyword_id");
    $stmt->execute([':cid' => $constellationId]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = (int)$row['keyword_id'];
    }
    return $out;
}

/**
 * Replace the set of tour keyword IDs for a constellation. Only IDs that belong to
 * this constellation are persisted; foreign IDs are silently dropped.
 */
function db_set_tour_keyword_ids(int $constellationId, array $keywordIds): void {
    db_ensure_constellations_tour_columns();
    $pdo = getDB();

    $cleanIds = [];
    foreach ($keywordIds as $kid) {
        $kid = (int)$kid;
        if ($kid > 0) {
            $cleanIds[$kid] = true;
        }
    }

    if ($cleanIds !== []) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $check = $pdo->prepare("SELECT id FROM keywords WHERE constellation_id = ? AND id IN ($placeholders)");
        $check->execute(array_merge([$constellationId], array_keys($cleanIds)));
        $allowed = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $allowed = [];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM constellation_tour_keywords WHERE constellation_id = :cid")
            ->execute([':cid' => $constellationId]);
        if ($allowed !== []) {
            $insert = $pdo->prepare("INSERT INTO constellation_tour_keywords (constellation_id, keyword_id) VALUES (:cid, :kid)");
            foreach ($allowed as $kid) {
                $insert->execute([':cid' => $constellationId, ':kid' => $kid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Used by the admin form to warn about autoplay-blocked audio when start_mode = immediate.
 */
function db_constellation_has_audio_nodes(int $constellationId): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT 1 FROM nodes
        WHERE constellation_id = :cid AND audio_url IS NOT NULL AND audio_url != ''
        LIMIT 1
    ");
    $stmt->execute([':cid' => $constellationId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Cluster variant: is there any audio anywhere across the cluster's member galaxies?
 * Powers the same immediate-start warning when the cluster's tour is configured.
 */
function db_cluster_has_audio_nodes(int $clusterId): bool {
    $members = db_get_cluster_member_ids($clusterId);
    if (empty($members)) return false;
    $placeholders = implode(',', array_fill(0, count($members), '?'));
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT 1 FROM nodes
        WHERE constellation_id IN ($placeholders)
          AND audio_url IS NOT NULL AND audio_url != ''
        LIMIT 1
    ");
    $stmt->execute(array_map('intval', $members));
    return (bool)$stmt->fetchColumn();
}

/**
 * Cluster-specific replacement for db_set_tour_keyword_ids().
 *
 * Clusters store tour-tag keywords as plain name strings (the same name can exist
 * across many member galaxies, and the auto-tour matches by lowercased name, not by
 * ID). We persist them by reusing the existing keywords + constellation_tour_keywords
 * tables: each name becomes a keyword row owned by the cluster row, and the junction
 * points at it. Clusters have no native nodes, so the cluster-owned keyword rows are
 * never referenced by node_keywords — we can safely wipe and recreate on every save.
 *
 * @param list<string> $names
 */
function db_set_cluster_tour_keyword_names(int $clusterId, array $names): void {
    $clean = [];
    foreach ($names as $n) {
        $n = trim((string)$n);
        if ($n === '') continue;
        $lc = mb_strtolower($n);
        if (isset($clean[$lc])) continue;
        $clean[$lc] = $n;
    }

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM constellation_tour_keywords WHERE constellation_id = :cid")
            ->execute([':cid' => $clusterId]);
        $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :cid")
            ->execute([':cid' => $clusterId]);
        if (!empty($clean)) {
            $insertKw = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:kw, :cid)");
            $insertJunc = $pdo->prepare("INSERT INTO constellation_tour_keywords (constellation_id, keyword_id) VALUES (:cid, :kid)");
            foreach ($clean as $name) {
                $insertKw->execute([':kw' => $name, ':cid' => $clusterId]);
                $kid = (int)$pdo->lastInsertId();
                $insertJunc->execute([':cid' => $clusterId, ':kid' => $kid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Find all portal nodes that point to a specific constellation.
 * @return list<array{id: int, name: string, constellation_id: int, constellation_name: string}>
 */
function db_get_referencing_portals(int $constellationId): array {
    db_ensure_nodes_node_type_index();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT n.id, n.name, n.constellation_id, c.name AS constellation_name
        FROM nodes n
        JOIN constellations c ON n.constellation_id = c.id
        WHERE n.node_type = 'portal' AND n.target_constellation_id = :id
    ");
    $stmt->execute([':id' => $constellationId]);
    return $stmt->fetchAll();
}

/**
 * Delete a constellation. Fails if id is the default; nodes/keywords in other constellations are unaffected.
 */
function db_delete_constellation(int $id): void {
    if ($id === db_get_default_constellation_id()) {
        throw new InvalidArgumentException('The default constellation cannot be deleted.');
    }
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // 1. Delete portals in OTHER constellations that point to THIS constellation
        $referencing = db_get_referencing_portals($id);
        foreach ($referencing as $ref) {
            db_delete_node((int)$ref['id']);
        }

        // 2. Delete nodes in this constellation in a single batch (reads asset paths,
        // batches the DELETE, then unlinks files — see db_bulk_delete_nodes_by_constellation).
        db_bulk_delete_nodes_by_constellation($id);

        // 3. Delete keywords in this constellation
        $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :id")->execute([':id' => $id]);

        // 4. Delete the constellation itself
        $pdo->prepare("DELETE FROM constellations WHERE id = :id")->execute([':id' => $id]);
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Check if a node name already exists in a given constellation.
 */
function db_node_exists(string $name, int $constellationId, ?int $excludeId = null): bool {
    $pdo = getDB();
    $sql = "SELECT id FROM nodes WHERE name = :name AND constellation_id = :constellation_id";
    if ($excludeId !== null) $sql .= " AND id != :exclude_id";
    $stmt = $pdo->prepare($sql);
    $params = [':name' => trim($name), ':constellation_id' => $constellationId];
    if ($excludeId !== null) $params[':exclude_id'] = $excludeId;
    $stmt->execute($params);
    return $stmt->fetch() !== false;
}

// ---------------------------------------------------------------------------
// Nodes
// ---------------------------------------------------------------------------

/**
 * @return list<array<string, mixed>>
 */
/**
 * @param int|null $constellationId If set, only return nodes in this constellation; null = all nodes (respecting user access)
 * @param string|null $userId User ID for permission filtering
 * @param bool $isAdmin Whether the user has admin access
 * @return list<array<string, mixed>>
 */
function db_get_nodes(?int $constellationId = null, ?string $userId = null, bool $isAdmin = true): array {
    db_ensure_nodes_show_keywords_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_pdf_url_column();
    $pdo = getDB();

    // Admin or specific constellation requested
    if ($isAdmin && $constellationId === null) {
        $stmt = $pdo->query("
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords, n.use_image_as_node,
                   n.source_facet, n.media_type, n.source_created_at,
                   c.name AS constellation_name,
                   tc.slug AS target_constellation_slug
            FROM nodes n
            LEFT JOIN constellations c ON c.id = n.constellation_id
            LEFT JOIN constellations tc ON tc.id = n.target_constellation_id
            ORDER BY n.id
        ");
        return $stmt->fetchAll();
    }

    if ($constellationId !== null) {
        // If not admin, verify access to this specific constellation
        if (!$isAdmin && $userId !== null) {
            $check = $pdo->prepare("SELECT 1 FROM user_constellations WHERE user_id = :user_id AND constellation_id = :cid LIMIT 1");
            $check->execute([':user_id' => $userId, ':cid' => $constellationId]);
            if (!$check->fetch()) {
                return []; // No access
            }
        }

        $stmt = $pdo->prepare("
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords, n.use_image_as_node,
                   n.source_facet, n.media_type, n.source_created_at,
                   c.name AS constellation_name,
                   tc.slug AS target_constellation_slug
            FROM nodes n
            LEFT JOIN constellations c ON c.id = n.constellation_id
            LEFT JOIN constellations tc ON tc.id = n.target_constellation_id
            WHERE n.constellation_id = :constellation_id
            ORDER BY n.id
        ");
        $stmt->execute([':constellation_id' => $constellationId]);
        return $stmt->fetchAll();
    }

    // Editor requesting "all" constellations - show only those they have access to
    if (!$isAdmin && $userId !== null) {
        $stmt = $pdo->prepare("
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords, n.use_image_as_node,
                   n.source_facet, n.media_type, n.source_created_at,
                   c.name AS constellation_name,
                   tc.slug AS target_constellation_slug
            FROM nodes n
            INNER JOIN user_constellations uc ON n.constellation_id = uc.constellation_id AND uc.user_id = :user_id
            LEFT JOIN constellations c ON c.id = n.constellation_id
            LEFT JOIN constellations tc ON tc.id = n.target_constellation_id
            ORDER BY n.id
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    return [];
}

/**
 * Multi-galaxy union: nodes from any of the listed constellation IDs, in id order.
 * Used by the visitor view's ?galaxies=a,b,c mode. Caller is responsible for the access policy
 * (visitor view treats all galaxies as public; editor/admin paths still go through db_get_nodes()).
 *
 * @param list<int> $constellationIds
 * @return list<array<string, mixed>>
 */
function db_get_nodes_for_constellations(array $constellationIds): array {
    db_ensure_nodes_show_keywords_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_pdf_url_column();
    $ids = array_values(array_unique(array_map('intval', $constellationIds)));
    if ($ids === []) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
               n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords, n.use_image_as_node,
               n.source_facet, n.media_type, n.source_created_at,
               c.name AS constellation_name,
               c.theme AS constellation_theme,
               tc.slug AS target_constellation_slug
        FROM nodes n
        LEFT JOIN constellations c ON c.id = n.constellation_id
        LEFT JOIN constellations tc ON tc.id = n.target_constellation_id
        WHERE n.constellation_id IN ($placeholders)
        ORDER BY n.id
    ");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/**
 * Fetch a single node by ID (raw DB row, not formatted).
 */
function db_get_node_by_id(int $nodeId): ?array {
    $pdo = getDB();
    db_ensure_nodes_show_keywords_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_pdf_url_column();
    $stmt = $pdo->prepare("
        SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
               n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords,
               n.source_facet, n.media_type, n.source_created_at,
               c.name AS constellation_name,
               tc.slug AS target_constellation_slug
        FROM nodes n
        LEFT JOIN constellations c ON c.id = n.constellation_id
        LEFT JOIN constellations tc ON tc.id = n.target_constellation_id
        WHERE n.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $nodeId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Server-side paginated, sorted, filtered node query for the editor.
 * @return array{nodes: list<array>, total: int, page: int, per_page: int}
 */
function db_get_nodes_paginated(
    ?int $constellationId,
    ?string $userId,
    bool $isAdmin,
    int $page = 1,
    int $perPage = 25,
    ?string $sort = null,
    string $order = 'asc',
    ?string $filter = null,
    bool $touchedToday = false
): array {
    db_ensure_nodes_show_keywords_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_pdf_url_column();
    $pdo = getDB();

    $columns = "n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
               n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords, n.use_image_as_node,
               n.source_facet, n.media_type, n.source_created_at,
               c.name AS constellation_name,
               tc.slug AS target_constellation_slug";

    // Build FROM and WHERE clauses based on access
    $from = "FROM nodes n LEFT JOIN constellations c ON c.id = n.constellation_id LEFT JOIN constellations tc ON tc.id = n.target_constellation_id";
    $where = [];
    $params = [];

    if ($constellationId !== null) {
        $where[] = "n.constellation_id = :cid";
        $params[':cid'] = $constellationId;
        // Editor access check for specific constellation
        if (!$isAdmin && $userId !== null) {
            $check = $pdo->prepare("SELECT 1 FROM user_constellations WHERE user_id = :uid AND constellation_id = :cid LIMIT 1");
            $check->execute([':uid' => $userId, ':cid' => $constellationId]);
            if (!$check->fetch()) {
                return ['nodes' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
            }
        }
    } elseif (!$isAdmin && $userId !== null) {
        // Editor "all" — restrict to assigned constellations. The `tc` join mirrors the
        // admin branch and is required because the SELECT below pulls tc.slug for portal
        // target resolution; without it, the query fails with "Unknown column tc.slug".
        $from = "FROM nodes n"
            . " INNER JOIN user_constellations uc ON n.constellation_id = uc.constellation_id AND uc.user_id = :uid"
            . " LEFT JOIN constellations c ON c.id = n.constellation_id"
            . " LEFT JOIN constellations tc ON tc.id = n.target_constellation_id";
        $params[':uid'] = $userId;
    } elseif (!$isAdmin) {
        return ['nodes' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
    }

    // Filter (search across name, description, constellation name, keywords)
    if ($filter !== null && $filter !== '') {
        $filterVal = '%' . $filter . '%';
        $where[] = "(n.name LIKE :filter1 OR n.description LIKE :filter2 OR c.name LIKE :filter3 OR EXISTS (SELECT 1 FROM node_keywords nk JOIN keywords k ON k.id = nk.keyword_id WHERE nk.node_id = n.id AND k.keyword LIKE :filter4))";
        $params[':filter1'] = $filterVal;
        $params[':filter2'] = $filterVal;
        $params[':filter3'] = $filterVal;
        $params[':filter4'] = $filterVal;
    }

    if ($touchedToday) {
        // Server-local "today" — matches what an editor would see on their clock.
        $where[] = "n.updated_at >= :today_start";
        $params[':today_start'] = date('Y-m-d') . ' 00:00:00';
    }

    $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $countSql = "SELECT COUNT(*) {$from} {$whereClause}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Sort column whitelist
    $sortMap = [
        'name' => 'n.name',
        'node_type' => 'n.node_type',
        'constellation_name' => 'c.name',
        'is_accentuated' => 'n.is_accentuated',
        'created_at' => 'n.created_at',
        'updated_at' => 'n.updated_at',
        'keywords' => '(SELECT GROUP_CONCAT(k2.keyword ORDER BY k2.keyword) FROM node_keywords nk2 JOIN keywords k2 ON k2.id = nk2.keyword_id WHERE nk2.node_id = n.id)',
    ];
    $orderDir = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
    $orderClause = 'ORDER BY n.id ASC';
    if ($sort !== null && isset($sortMap[$sort])) {
        $orderClause = "ORDER BY {$sortMap[$sort]} {$orderDir}, n.id ASC";
    }

    // Paginate
    $offset = ($page - 1) * $perPage;
    $dataSql = "SELECT {$columns} {$from} {$whereClause} {$orderClause} LIMIT :limit OFFSET :offset";
    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $nodes = $dataStmt->fetchAll();

    return ['nodes' => $nodes, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

/**
 * Fetch keywords for multiple nodes in a single query.
 * @param list<int> $nodeIds
 * @return array<int, list<string>> Map of node_id => keywords
 */
function db_get_keywords_for_nodes_bulk(array $nodeIds): array {
    if ($nodeIds === []) {
        return [];
    }
    $pdo = getDB();
    $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
    $stmt = $pdo->prepare("
        SELECT nk.node_id, k.keyword
        FROM node_keywords nk
        JOIN keywords k ON k.id = nk.keyword_id
        WHERE nk.node_id IN ($placeholders)
        ORDER BY nk.node_id, k.keyword
    ");
    $stmt->execute(array_values($nodeIds));
    $rows = $stmt->fetchAll();
    $result = array_fill_keys($nodeIds, []);
    foreach ($rows as $row) {
        $result[(int)$row['node_id']][] = $row['keyword'];
    }
    return $result;
}

/**
 * Normalize a stored asset URL for API output. Database rows commonly hold relative
 * paths like "uploads/6/165/image.png" (the historical convention). Those work on
 * single-segment visitor URLs but 404 on multi-segment ones like /{slug}/{node-id},
 * because the browser resolves them against the current document path. Prepending
 * "/" makes them site-absolute so they work from any URL depth. Already-absolute
 * paths (leading "/") and full URLs (http://, https://, data:, blob:) pass through
 * untouched.
 */
function db_normalize_asset_url(?string $url): ?string {
    if ($url === null) return null;
    $url = (string) $url;
    if ($url === '') return null;
    if ($url[0] === '/') return $url;
    if (preg_match('#^(https?:)?//|^(data|blob):#i', $url)) return $url;
    return '/' . $url;
}

/**
 * Format multiple node rows for API output, using a single bulk keyword query.
 * @param list<array<string, mixed>> $nodes Raw DB rows
 * @return list<array<string, mixed>> Formatted nodes
 */
function db_format_nodes_bulk(array $nodes): array {
    if ($nodes === []) {
        return [];
    }
    $nodeIds = array_map(fn($n) => (int)$n['id'], $nodes);
    $keywordsMap = db_get_keywords_for_nodes_bulk($nodeIds);
    $result = [];
    foreach ($nodes as $node) {
        $nodeId = (int)$node['id'];
        $keywords = $keywordsMap[$nodeId] ?? [];
        $animation = json_decode($node['animation'], true, 512, JSON_THROW_ON_ERROR);
        $createdAt = db_format_iso8601_utc($node['created_at'] ?? null);
        $updatedAt = db_format_iso8601_utc($node['updated_at'] ?? null);
        $targetConstellationId = null;
        if (isset($node['target_constellation_id']) && $node['target_constellation_id'] !== null && $node['target_constellation_id'] !== '') {
            $targetConstellationId = (int)$node['target_constellation_id'];
        }
        $nodeType = isset($node['node_type']) && (string)$node['node_type'] !== '' ? (string)$node['node_type'] : 'object';
        $result[] = [
            'id' => $nodeId,
            'name' => $node['name'],
            'description' => $node['description'] ?? null,
            'url' => $node['url'] ?? null,
            'image_url' => db_normalize_asset_url($node['image_url'] ?? null),
            'image_attribution' => isset($node['image_attribution']) && $node['image_attribution'] !== null && $node['image_attribution'] !== '' ? (string)$node['image_attribution'] : null,
            'icon_url' => db_normalize_asset_url($node['icon_url'] ?? null),
            'embed_code' => $node['embed_code'] ?? null,
            'audio_url' => db_normalize_asset_url($node['audio_url'] ?? null),
            'audio_autoplay' => (bool)($node['audio_autoplay'] ?? true),
            'audio_loop' => (bool)($node['audio_loop'] ?? false),
            'video_url' => db_normalize_asset_url($node['video_url'] ?? null),
            'video_autoplay' => (bool)($node['video_autoplay'] ?? true),
            'pdf_url' => db_normalize_asset_url($node['pdf_url'] ?? null),
            'keywords' => $keywords,
            'animation' => $animation,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'constellation_id' => isset($node['constellation_id']) ? (int)$node['constellation_id'] : db_get_default_constellation_id(),
            'constellation_name' => isset($node['constellation_name']) && (string)$node['constellation_name'] !== '' ? (string)$node['constellation_name'] : 'Default',
            // Per-node origin-galaxy theme. Multi-galaxy union views render each wormhole's icon
            // with its source galaxy's theme while keeping the scene theme global. Falls back to
            // null when the upstream SQL didn't join (single-galaxy editor paths) — frontend then
            // defaults to the global currentTheme, which is identical in that case.
            'constellation_theme' => isset($node['constellation_theme']) && (string)$node['constellation_theme'] !== '' ? (string)$node['constellation_theme'] : null,
            'node_type' => $nodeType,
            'target_constellation_id' => $targetConstellationId,
            'target_constellation_slug' => isset($node['target_constellation_slug']) && $node['target_constellation_slug'] !== null && $node['target_constellation_slug'] !== '' ? (string)$node['target_constellation_slug'] : null,
            'is_accentuated' => (bool)($node['is_accentuated'] ?? false),
            'show_keywords' => (bool)($node['show_keywords'] ?? false),
            'use_image_as_node' => (bool)($node['use_image_as_node'] ?? false),
            'source_facet' => isset($node['source_facet']) && $node['source_facet'] !== null && $node['source_facet'] !== '' ? (string)$node['source_facet'] : null,
            'media_type' => isset($node['media_type']) && $node['media_type'] !== null && $node['media_type'] !== '' ? (string)$node['media_type'] : null,
            'source_created_at' => isset($node['source_created_at']) && $node['source_created_at'] !== null && $node['source_created_at'] !== '' ? (string)$node['source_created_at'] : null,
        ];
    }
    return $result;
}

/**
 * Format a MySQL DATETIME (or anything strtotime can parse) as ISO 8601 UTC.
 *
 * Hot path: when the input is a standard MySQL DATETIME ('YYYY-MM-DD HH:MM:SS')
 * and PHP's default timezone is UTC, we skip strtotime+gmdate entirely and do a
 * direct string transform. That collapses two libc calls per row to two substring
 * ops, which matters in node-formatting loops that run ~100x per request.
 *
 * Fallback: anything that doesn't match the fast-path shape (non-UTC PHP TZ,
 * already-ISO strings, NULL, etc.) goes through the original strtotime+gmdate
 * path so semantics are preserved.
 */
function db_format_iso8601_utc(?string $sqlDatetime): ?string {
    if ($sqlDatetime === null || $sqlDatetime === '') return null;
    static $tzIsUtc = null;
    if ($tzIsUtc === null) {
        $tzIsUtc = date_default_timezone_get() === 'UTC';
    }
    // Fast path matches gmdate('c', ...) byte-for-byte: 'Y-m-d\TH:i:s+00:00'.
    // (Using 'Z' would be semantically equivalent but might surprise a strict client parser.)
    if ($tzIsUtc && strlen($sqlDatetime) === 19 && $sqlDatetime[10] === ' ') {
        return substr_replace($sqlDatetime, 'T', 10, 1) . '+00:00';
    }
    $ts = strtotime($sqlDatetime);
    return $ts !== false ? gmdate('c', $ts) : $sqlDatetime;
}

/**
 * @return list<string>
 */
function db_get_keywords_for_node(int $nodeId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        WITH node_keywords_cte AS (
            SELECT k.keyword FROM keywords k
            JOIN node_keywords nk ON k.id = nk.keyword_id
            WHERE nk.node_id = :node_id
        )
        SELECT keyword FROM node_keywords_cte ORDER BY keyword
    ");
    $stmt->execute([':node_id' => $nodeId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Format a single node row for API (with keywords and parsed animation).
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function db_format_node(array $node): array {
    $keywords = db_get_keywords_for_node((int)$node['id']);
    $animation = json_decode($node['animation'], true, 512, JSON_THROW_ON_ERROR);
    // Return timestamps as ISO 8601 UTC so the client can display in user's timezone
    $createdAt = db_format_iso8601_utc($node['created_at'] ?? null);
    $updatedAt = db_format_iso8601_utc($node['updated_at'] ?? null);
    $targetConstellationId = null;
    if (isset($node['target_constellation_id']) && $node['target_constellation_id'] !== null && $node['target_constellation_id'] !== '') {
        $targetConstellationId = (int)$node['target_constellation_id'];
    }
    $nodeType = isset($node['node_type']) && (string)$node['node_type'] !== '' ? (string)$node['node_type'] : 'object';
    return [
        'id' => (int)$node['id'],
        'name' => $node['name'],
        'description' => $node['description'] ?? null,
        'url' => $node['url'] ?? null,
        'image_url' => db_normalize_asset_url($node['image_url'] ?? null),
        'icon_url' => db_normalize_asset_url($node['icon_url'] ?? null),
        'embed_code' => $node['embed_code'] ?? null,
        'audio_url' => db_normalize_asset_url($node['audio_url'] ?? null),
        'audio_autoplay' => (bool)($node['audio_autoplay'] ?? true),
        'audio_loop' => (bool)($node['audio_loop'] ?? false),
        'video_url' => db_normalize_asset_url($node['video_url'] ?? null),
        'video_autoplay' => (bool)($node['video_autoplay'] ?? true),
        'pdf_url' => db_normalize_asset_url($node['pdf_url'] ?? null),
        'keywords' => $keywords,
        'animation' => $animation,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'constellation_id' => isset($node['constellation_id']) ? (int)$node['constellation_id'] : db_get_default_constellation_id(),
        'constellation_name' => isset($node['constellation_name']) && (string)$node['constellation_name'] !== '' ? (string)$node['constellation_name'] : 'Default',
        'node_type' => $nodeType,
        'target_constellation_id' => $targetConstellationId,
        'is_accentuated' => (bool)($node['is_accentuated'] ?? false),
        'show_keywords' => (bool)($node['show_keywords'] ?? false),
        'use_image_as_node' => (bool)($node['use_image_as_node'] ?? false),
        'source_facet' => isset($node['source_facet']) && $node['source_facet'] !== null && $node['source_facet'] !== '' ? (string)$node['source_facet'] : null,
        'media_type' => isset($node['media_type']) && $node['media_type'] !== null && $node['media_type'] !== '' ? (string)$node['media_type'] : null,
        'source_created_at' => isset($node['source_created_at']) && $node['source_created_at'] !== null && $node['source_created_at'] !== '' ? (string)$node['source_created_at'] : null,
    ];
}

function db_save_node_keywords(int $nodeId, array $keywords, ?string $createdBy = null): void {
    db_ensure_keywords_created_by_column();
    db_ensure_node_keywords_created_by_column();
    $pdo = getDB();
    $nodeStmt = $pdo->prepare("SELECT constellation_id FROM nodes WHERE id = :id LIMIT 1");
    $nodeStmt->execute([':id' => $nodeId]);
    $nodeRow = $nodeStmt->fetch();
    $constellationId = $nodeRow ? (int)$nodeRow['constellation_id'] : db_get_default_constellation_id();
    $pdo->prepare("DELETE FROM node_keywords WHERE node_id = :node_id")->execute([':node_id' => $nodeId]);
    if ($keywords === []) {
        return;
    }

    // Dedupe + trim. We dedupe case-sensitively here; the DB's unique index uses
    // utf8mb4_unicode_ci so case-variants will collapse to one row on INSERT IGNORE.
    $namesSet = [];
    foreach ($keywords as $keyword) {
        $keyword = trim((string)$keyword);
        if ($keyword === '') continue;
        $namesSet[$keyword] = true;
    }
    $names = array_keys($namesSet);
    if ($names === []) return;

    try {
        // Step 1: upsert every keyword in a single statement. INSERT IGNORE relies on
        // unique_keyword_constellation (keyword, constellation_id). created_by lands
        // on rows that win the insert race; existing keyword rows keep their prior
        // creator attribution.
        $kwPlaceholders = implode(',', array_fill(0, count($names), '(?, ?, ?)'));
        $kwStmt = $pdo->prepare("INSERT IGNORE INTO keywords (keyword, constellation_id, created_by) VALUES $kwPlaceholders");
        $bind = [];
        foreach ($names as $n) {
            $bind[] = $n;
            $bind[] = $constellationId;
            $bind[] = $createdBy;
        }
        $kwStmt->execute($bind);

        // Step 2: pull the IDs back in one query. utf8mb4_unicode_ci matches case-insensitively,
        // so map back by lowercase to find each keyword's resolved row.
        $inPlaceholders = implode(',', array_fill(0, count($names), '?'));
        $idStmt = $pdo->prepare(
            "SELECT id, keyword FROM keywords WHERE constellation_id = ? AND keyword IN ($inPlaceholders)"
        );
        $idStmt->execute(array_merge([$constellationId], $names));
        $idByLower = [];
        while ($row = $idStmt->fetch()) {
            $idByLower[mb_strtolower((string)$row['keyword'])] = (int)$row['id'];
        }

        // Step 3: insert every junction row in a single statement. INSERT IGNORE relies on
        // unique_node_keyword (node_id, keyword_id) to no-op on duplicates. The
        // junction row's created_by attributes *who tagged this wormhole with this
        // keyword* — distinct from who first created the keyword itself.
        $keywordIds = [];
        foreach ($names as $n) {
            $kid = $idByLower[mb_strtolower($n)] ?? 0;
            if ($kid > 0) $keywordIds[$kid] = true;
        }
        if ($keywordIds === []) return;
        $jPlaceholders = implode(',', array_fill(0, count($keywordIds), '(?, ?, ?)'));
        $jStmt = $pdo->prepare("INSERT IGNORE INTO node_keywords (node_id, keyword_id, created_by) VALUES $jPlaceholders");
        $jBind = [];
        foreach (array_keys($keywordIds) as $kid) {
            $jBind[] = $nodeId;
            $jBind[] = $kid;
            $jBind[] = $createdBy;
        }
        $jStmt->execute($jBind);
    } catch (PDOException $e) {
        error_log("db_save_node_keywords: failed to save keywords for node {$nodeId}: " . $e->getMessage());
    }
}

/**
 * Duplicate a node to the same or a different constellation.
 * Copies all content fields and keywords. Generates fresh animation values.
 * Does NOT copy import_slug, source_facet, media_type, or source_created_at.
 */
function db_duplicate_node(int $sourceNodeId, ?int $targetConstellationId = null): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $sourceNodeId]);
    $source = $stmt->fetch();
    if ($source === false) {
        throw new RuntimeException("Source node {$sourceNodeId} not found");
    }

    $constellationId = $targetConstellationId ?? (int)$source['constellation_id'];

    // Generate fresh random animation so the duplicate appears at a different position
    $animation = json_encode([
        'radius' => 5 + rand(0, 3),
        'theta'  => rand(0, 628) / 100,
        'phi'    => rand(0, 314) / 100,
        'speed'  => 0.002 + (rand(0, 4) / 1000),
        'phase'  => rand(0, 628) / 100,
    ], JSON_THROW_ON_ERROR);

    $nodeType = (string)($source['node_type'] ?? 'object') ?: 'object';
    $targetCid = $source['target_constellation_id'] !== null && $source['target_constellation_id'] !== '' ? (int)$source['target_constellation_id'] : null;

    $newId = db_create_node(
        $source['name'] . ' (Copy)',
        $source['description'],
        $source['url'],
        $animation,
        $constellationId,
        $nodeType,
        $targetCid,
        $source['image_url'],
        $source['embed_code'],
        $source['audio_url'],
        (bool)($source['audio_autoplay'] ?? true),
        (bool)($source['is_accentuated'] ?? false),
        $source['video_url'],
        (bool)($source['video_autoplay'] ?? true),
        (bool)($source['audio_loop'] ?? false),
        (bool)($source['show_keywords'] ?? false),
        $source['icon_url'],
        $source['image_attribution'] ?? null,
        (bool)($source['use_image_as_node'] ?? false),
        $source['pdf_url'] ?? null
    );

    if ($newId === 0) {
        throw new RuntimeException("Failed to create duplicate node");
    }

    // Copy keyword associations
    $keywords = db_get_keywords_for_node($sourceNodeId);
    if ($keywords !== []) {
        db_save_node_keywords($newId, $keywords);
    }

    return $newId;
}

function db_create_node(string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false, ?string $videoUrl = null, bool $videoAutoplay = true, bool $audioLoop = false, bool $showKeywords = false, ?string $iconUrl = null, ?string $imageAttribution = null, bool $useImageAsNode = false, ?string $pdfUrl = null, ?string $createdBy = null): int {
    if ($constellationId === null) {
        $constellationId = db_get_default_constellation_id();
    }
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_pdf_url_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, image_url, image_attribution, icon_url, embed_code, audio_url, audio_autoplay, audio_loop, video_url, video_autoplay, pdf_url, animation, constellation_id, node_type, target_constellation_id, is_accentuated, show_keywords, use_image_as_node, created_by)
        VALUES (:name, :description, :url, :image_url, :image_attribution, :icon_url, :embed_code, :audio_url, :audio_autoplay, :audio_loop, :video_url, :video_autoplay, :pdf_url, :animation, :constellation_id, :node_type, :target_constellation_id, :is_accentuated, :show_keywords, :use_image_as_node, :created_by)
    ");
    $stmt->execute([
        ':name' => $name,
        ':description' => $description,
        ':url' => $url,
        ':image_url' => $imageUrl,
        ':image_attribution' => $imageAttribution,
        ':icon_url' => $iconUrl,
        ':embed_code' => $embedCode,
        ':audio_url' => $audioUrl,
        ':audio_autoplay' => $audioAutoplay ? 1 : 0,
        ':audio_loop' => $audioLoop ? 1 : 0,
        ':video_url' => $videoUrl,
        ':video_autoplay' => $videoAutoplay ? 1 : 0,
        ':pdf_url' => $pdfUrl,
        ':animation' => $animation,
        ':constellation_id' => $constellationId,
        ':node_type' => $nodeType,
        ':target_constellation_id' => $targetConstellationId,
        ':is_accentuated' => $isAccentuated ? 1 : 0,
        ':show_keywords' => $showKeywords ? 1 : 0,
        ':use_image_as_node' => $useImageAsNode ? 1 : 0,
        ':created_by' => $createdBy,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_update_node(int $id, string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false, ?string $videoUrl = null, bool $videoAutoplay = true, bool $audioLoop = false, bool $showKeywords = false, ?string $iconUrl = null, ?string $imageAttribution = null, bool $useImageAsNode = false, ?string $pdfUrl = null): void {
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_pdf_url_column();
    $pdo = getDB();
    if ($constellationId !== null) {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, image_url = :image_url, image_attribution = :image_attribution, icon_url = :icon_url, embed_code = :embed_code, audio_url = :audio_url, audio_autoplay = :audio_autoplay, audio_loop = :audio_loop, video_url = :video_url, video_autoplay = :video_autoplay, pdf_url = :pdf_url, animation = :animation, constellation_id = :constellation_id, node_type = :node_type, target_constellation_id = :target_constellation_id, is_accentuated = :is_accentuated, show_keywords = :show_keywords, use_image_as_node = :use_image_as_node WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':url' => $url,
            ':image_url' => $imageUrl,
            ':image_attribution' => $imageAttribution,
            ':icon_url' => $iconUrl,
            ':embed_code' => $embedCode,
            ':audio_url' => $audioUrl,
            ':audio_autoplay' => $audioAutoplay ? 1 : 0,
            ':audio_loop' => $audioLoop ? 1 : 0,
            ':video_url' => $videoUrl,
            ':video_autoplay' => $videoAutoplay ? 1 : 0,
            ':pdf_url' => $pdfUrl,
            ':animation' => $animation,
            ':constellation_id' => $constellationId,
            ':node_type' => $nodeType,
            ':target_constellation_id' => $targetConstellationId,
            ':is_accentuated' => $isAccentuated ? 1 : 0,
            ':show_keywords' => $showKeywords ? 1 : 0,
            ':use_image_as_node' => $useImageAsNode ? 1 : 0
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, image_url = :image_url, image_attribution = :image_attribution, icon_url = :icon_url, embed_code = :embed_code, audio_url = :audio_url, audio_autoplay = :audio_autoplay, audio_loop = :audio_loop, video_url = :video_url, video_autoplay = :video_autoplay, pdf_url = :pdf_url, animation = :animation, node_type = :node_type, target_constellation_id = :target_constellation_id, is_accentuated = :is_accentuated, show_keywords = :show_keywords, use_image_as_node = :use_image_as_node WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':url' => $url,
            ':image_url' => $imageUrl,
            ':image_attribution' => $imageAttribution,
            ':icon_url' => $iconUrl,
            ':embed_code' => $embedCode,
            ':audio_url' => $audioUrl,
            ':audio_autoplay' => $audioAutoplay ? 1 : 0,
            ':audio_loop' => $audioLoop ? 1 : 0,
            ':video_url' => $videoUrl,
            ':video_autoplay' => $videoAutoplay ? 1 : 0,
            ':pdf_url' => $pdfUrl,
            ':animation' => $animation,
            ':node_type' => $nodeType,
            ':target_constellation_id' => $targetConstellationId,
            ':is_accentuated' => $isAccentuated ? 1 : 0,
            ':show_keywords' => $showKeywords ? 1 : 0,
            ':use_image_as_node' => $useImageAsNode ? 1 : 0
        ]);
    }
}

/**
 * Find node IDs in a constellation that have the given keyword id attached.
 * @return list<int>
 */
function db_get_node_ids_with_keyword(int $constellationId, int $keywordId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT n.id FROM nodes n
        INNER JOIN node_keywords nk ON nk.node_id = n.id
        WHERE n.constellation_id = :cid AND nk.keyword_id = :kid
    ");
    $stmt->execute([':cid' => $constellationId, ':kid' => $keywordId]);
    $out = [];
    while (($id = $stmt->fetchColumn()) !== false) $out[] = (int)$id;
    return $out;
}

/**
 * Bulk-move all nodes in $constellationId carrying $keywordId to $targetConstellationId.
 * Returns the number of rows updated. Note: keyword associations are kept (keywords
 * are per-galaxy; the node will retain its association with the source-galaxy keyword).
 * That mirrors the existing per-node bulkMove behavior in the editor.
 */
function db_bulk_move_nodes_by_keyword(int $constellationId, int $keywordId, int $targetConstellationId): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE nodes n
        INNER JOIN node_keywords nk ON nk.node_id = n.id
        SET n.constellation_id = :target
        WHERE n.constellation_id = :cid AND nk.keyword_id = :kid
    ");
    $stmt->execute([':target' => $targetConstellationId, ':cid' => $constellationId, ':kid' => $keywordId]);
    return $stmt->rowCount();
}

/**
 * Bulk-set the use_image_as_node flag on every node in a constellation.
 * Returns the number of rows affected.
 */
function db_bulk_set_nodes_use_image_as_node(int $constellationId, bool $value): int {
    db_ensure_nodes_use_image_as_node_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE nodes SET use_image_as_node = :v WHERE constellation_id = :cid");
    $stmt->execute([':v' => $value ? 1 : 0, ':cid' => $constellationId]);
    return $stmt->rowCount();
}

function db_delete_node(int $id): void {
    $pdo = getDB();

    // Collect file paths to delete AFTER the DB row is removed
    $filesToDelete = [];
    $stmt = $pdo->prepare("SELECT image_url, icon_url, audio_url, video_url, pdf_url FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if ($row) {
        $uploadDir = UPLOAD_DIR;
        foreach (['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'] as $col) {
            if ($row[$col] && str_starts_with($row[$col], 'uploads/')) {
                $fullPath = str_replace('uploads/', $uploadDir . '/', $row[$col]);
                if (file_exists($fullPath)) {
                    $filesToDelete[] = $fullPath;
                }
            }
        }
    }

    // Delete DB row first
    $stmt = $pdo->prepare("DELETE FROM nodes WHERE id = :id");
    $stmt->execute([':id' => $id]);

    // Delete files only after DB deletion succeeds
    foreach ($filesToDelete as $path) {
        @unlink($path);
    }
}

function db_delete_node_file(int $id, string $type): void {
    $pdo = getDB();
    $column = match($type) {
        'image' => 'image_url',
        'icon' => 'icon_url',
        'audio' => 'audio_url',
        'video' => 'video_url',
        'pdf' => 'pdf_url',
        default => throw new InvalidArgumentException('Invalid file type')
    };
    
    $stmt = $pdo->prepare("SELECT $column FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    
    if ($row && $row[$column] && str_starts_with($row[$column], 'uploads/')) {
        $uploadDir = UPLOAD_DIR;
        $fullPath = str_replace('uploads/', $uploadDir . '/', $row[$column]);
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
    
    $stmt = $pdo->prepare("UPDATE nodes SET $column = NULL WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

// ---------------------------------------------------------------------------
// Keywords
// ---------------------------------------------------------------------------

/**
 * @param int|null $nodeId If set, return keywords for that node; otherwise all keywords with usage_count (default constellation).
 * @return list<array<string, mixed>>
 */
/**
 * List keywords for a specific constellation, with usage counts.
 * @return list<array{id: int, keyword: string, usage_count: int}>
 */
function db_get_keywords_for_constellation(int $constellationId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT k.id, k.keyword, COUNT(nk.node_id) AS usage_count
        FROM keywords k
        LEFT JOIN node_keywords nk ON k.id = nk.keyword_id
        WHERE k.constellation_id = :constellation_id
        GROUP BY k.id, k.keyword
        ORDER BY k.keyword
    ");
    $stmt->execute([':constellation_id' => $constellationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn(array $r) => [
        'id' => (int)$r['id'],
        'keyword' => (string)$r['keyword'],
        'usage_count' => (int)$r['usage_count'],
    ], $rows);
}

function db_get_keywords(?int $nodeId = null): array {
    $pdo = getDB();
    if ($nodeId !== null) {
        $stmt = $pdo->prepare("
            WITH node_keywords_cte AS (
                SELECT k.id, k.keyword FROM keywords k
                JOIN node_keywords nk ON k.id = nk.keyword_id
                WHERE nk.node_id = :node_id
            )
            SELECT id, keyword FROM node_keywords_cte ORDER BY keyword
        ");
        $stmt->execute([':node_id' => $nodeId]);
        return $stmt->fetchAll();
    }
    $stmt = $pdo->prepare("
        SELECT k.id, k.keyword, COUNT(nk.node_id) AS usage_count
        FROM keywords k
        LEFT JOIN node_keywords nk ON k.id = nk.keyword_id
        WHERE k.constellation_id = :constellation_id
        GROUP BY k.id, k.keyword
        ORDER BY k.keyword
    ");
    $stmt->execute([':constellation_id' => db_get_default_constellation_id()]);
    return $stmt->fetchAll();
}

function db_create_keyword(string $keyword, ?int $constellationId = null, ?string $createdBy = null): int {
    if ($constellationId === null) {
        $constellationId = db_get_default_constellation_id();
    }
    db_ensure_keywords_created_by_column();
    $pdo = getDB();
    // On the duplicate path, LAST_INSERT_ID(id) returns the existing row's id
    // and created_by stays whatever it was originally (we never overwrite an
    // earlier creator with a later editor).
    $stmt = $pdo->prepare("
        INSERT INTO keywords (keyword, constellation_id, created_by) VALUES (:keyword, :constellation_id, :created_by)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
    ");
    $stmt->execute([
        ':keyword' => $keyword,
        ':constellation_id' => $constellationId,
        ':created_by' => $createdBy,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_node_constellation_id(int $nodeId): ?int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT constellation_id FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $nodeId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['constellation_id'] : null;
}

function db_get_keyword_constellation_id(int $id): ?int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT constellation_id FROM keywords WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? (int)$row['constellation_id'] : null;
}

function db_delete_keyword(int $id): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM keywords WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

/**
 * Case-insensitive lookup of a keyword by name within a galaxy. Returns the
 * row id if it exists, null otherwise. Used by the canvas rename flow to
 * detect a "this name already taken" conflict before issuing an UPDATE.
 */
function db_find_keyword_in_galaxy(string $name, int $constellationId): ?int {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id FROM keywords
        WHERE constellation_id = :cid AND LOWER(keyword) = LOWER(:name)
        LIMIT 1
    ");
    $stmt->execute([':cid' => $constellationId, ':name' => $name]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * Rename a keyword. Caller is responsible for conflict-checking first (see
 * db_find_keyword_in_galaxy). If the UPDATE collides with the unique index on
 * (keyword, constellation_id), the PDOException propagates.
 */
function db_rename_keyword(int $id, string $newName): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE keywords SET keyword = :name WHERE id = :id");
    $stmt->execute([':name' => $newName, ':id' => $id]);
}

/**
 * Merge a source keyword into a target keyword: every reference to source
 * is repointed at target, then source is deleted. Both keywords must live
 * in the same galaxy (caller checks). Idempotent on no-op (source == target).
 *
 * The merge folds:
 *  - node_keywords junction rows (INSERT IGNORE so a wormhole that already
 *    carries the target keyword doesn't get a duplicate row; the source
 *    row gets cascaded away below).
 *  - keyword_relations: rewrite the source-endpoint to target, preserving
 *    canonical (keyword_a_id < keyword_b_id) order and swapping anchor sides
 *    if the canonical order flipped. Relations that would become self-loops
 *    (source-target pair) are skipped — the source row gets cascaded away.
 *    Relations that would collide with an existing target-pair are skipped
 *    via INSERT IGNORE; the older target row wins.
 *  - keyword_positions / keyword_position_history: cascade-deleted with the
 *    source keyword (target keeps its own position).
 */
function db_merge_keywords(int $sourceId, int $targetId): void {
    if ($sourceId === $targetId) return;
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // 1. Move junction rows.
        $pdo->prepare("
            INSERT IGNORE INTO node_keywords (node_id, keyword_id, created_by)
            SELECT node_id, :target, created_by
            FROM node_keywords
            WHERE keyword_id = :source
        ")->execute([':target' => $targetId, ':source' => $sourceId]);
        $pdo->prepare("DELETE FROM node_keywords WHERE keyword_id = :source")
            ->execute([':source' => $sourceId]);

        // 2. Move relations. Fetch + rewrite + INSERT IGNORE; remaining
        //    source-rooted rows are cascade-dropped with the keyword DELETE.
        $stmt = $pdo->prepare("
            SELECT id, keyword_a_id, keyword_b_id, anchor_a, anchor_b, note, created_by, created_at
            FROM keyword_relations
            WHERE keyword_a_id = :source OR keyword_b_id = :source
        ");
        $stmt->execute([':source' => $sourceId]);
        $ins = $pdo->prepare("
            INSERT IGNORE INTO keyword_relations
                (keyword_a_id, keyword_b_id, anchor_a, anchor_b, note, created_by, created_at)
            VALUES (:a, :b, :aa, :ab, :n, :c, :ts)
        ");
        while ($r = $stmt->fetch()) {
            $aId = (int)$r['keyword_a_id'];
            $bId = (int)$r['keyword_b_id'];
            $otherId = ($aId === $sourceId) ? $bId : $aId;
            if ($otherId === $targetId) continue; // would become a self-loop
            $sourceWasA = ($aId === $sourceId);
            $sourceAnchor = $sourceWasA ? $r['anchor_a'] : $r['anchor_b'];
            $otherAnchor = $sourceWasA ? $r['anchor_b'] : $r['anchor_a'];
            $newA = min($targetId, $otherId);
            $newB = max($targetId, $otherId);
            $targetIsA = ($targetId === $newA);
            $ins->execute([
                ':a' => $newA, ':b' => $newB,
                ':aa' => $targetIsA ? $sourceAnchor : $otherAnchor,
                ':ab' => $targetIsA ? $otherAnchor : $sourceAnchor,
                ':n' => $r['note'],
                ':c' => $r['created_by'],
                ':ts' => $r['created_at'],
            ]);
        }

        // 3. Delete source. ON DELETE CASCADE handles remaining junction rows,
        //    relations, positions, position history.
        $pdo->prepare("DELETE FROM keywords WHERE id = :id")
            ->execute([':id' => $sourceId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Connections (derived from nodes + node_keywords)
// ---------------------------------------------------------------------------

/**
 * Return connections (shared keywords) between nodes. When $constellationId is set, only nodes
 * and keywords in that constellation are used so connection node IDs match db_get_nodes($constellationId)
 * and the O(n²) loop never compares nodes from different constellations (avoids broken/invisible links).
 *
 * @param int|null $constellationId If set, only nodes (and keywords) in this constellation; null = all nodes
 * @return list<array{id: int, node1_id: int, node2_id: int, shared_keywords: list<string>, shared_count: int}>
 */
function db_get_connections(?int $constellationId = null): array {
    $pdo = getDB();
    if ($constellationId !== null) {
        $nodesStmt = $pdo->prepare("SELECT n.id, n.name FROM nodes n WHERE n.constellation_id = :constellation_id ORDER BY n.id");
        $nodesStmt->execute([':constellation_id' => $constellationId]);
        $nodes = $nodesStmt->fetchAll();
    } else {
        $nodesStmt = $pdo->query("SELECT n.id, n.name FROM nodes n ORDER BY n.id");
        $nodes = $nodesStmt->fetchAll();
    }

    // Bulk-load all keywords in a single query
    $nodeIds = array_map(fn($n) => (int)$n['id'], $nodes);
    $nodeKeywords = db_get_keywords_for_nodes_bulk($nodeIds);

    // Build inverted index: keyword → list of node IDs that have it
    // This avoids the O(n²) pairwise comparison
    $keywordToNodes = [];
    foreach ($nodeKeywords as $nodeId => $keywords) {
        foreach ($keywords as $kw) {
            $keywordToNodes[$kw][] = $nodeId;
        }
    }

    // Build connections from the inverted index
    // For each keyword, every pair of nodes sharing it gets a connection
    $pairShared = []; // "id1:id2" => [keyword, ...]
    foreach ($keywordToNodes as $kw => $ids) {
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $id1 = min($ids[$i], $ids[$j]);
                $id2 = max($ids[$i], $ids[$j]);
                $pairShared["{$id1}:{$id2}"][] = $kw;
            }
        }
    }

    $connections = [];
    $connectionId = 1;
    foreach ($pairShared as $pair => $shared) {
        [$id1, $id2] = explode(':', $pair);
        $connections[] = [
            'id' => $connectionId++,
            'node1_id' => (int)$id1,
            'node2_id' => (int)$id2,
            'shared_keywords' => $shared,
            'shared_count' => count($shared)
        ];
    }
    return $connections;
}

// ---------------------------------------------------------------------------
// CLI / maintenance
// ---------------------------------------------------------------------------

/**
 * @return list<string>
 */
function getAllTables(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        return array_column($rows, 0);
    } catch (PDOException $e) {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Backup / Snapshot helpers
// ---------------------------------------------------------------------------

/**
 * Recursively delete a directory and all its contents.
 * Safe-bounded: only deletes if the resolved path is inside $allowedRoot.
 */
function db_rrmdir(string $path, string $allowedRoot): void {
    $real = realpath($path);
    $allowedReal = realpath($allowedRoot);
    if ($real === false || $allowedReal === false) {
        return;
    }
    if (strpos($real, rtrim($allowedReal, '/') . '/') !== 0 && $real !== $allowedReal) {
        return; // refuse to touch anything outside the allowed root
    }
    if (!is_dir($real)) {
        if (is_file($real)) {
            @unlink($real);
        }
        return;
    }
    $items = @scandir($real);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $sub = $real . '/' . $item;
        if (is_dir($sub) && !is_link($sub)) {
            db_rrmdir($sub, $allowedReal);
        } else {
            @unlink($sub);
        }
    }
    @rmdir($real);
}

/**
 * Pull the rich representation of one galaxy for a backup dump.
 * Returns null if the galaxy doesn't exist.
 *
 * Output keys: constellation row + 'nodes' (raw rows with 'keyword_ids' resolved
 * to keyword names) + 'keywords' (full rows) + 'editor_emails' + 'is_default'.
 */
function db_get_galaxy_for_dump(int $id): ?array {
    db_ensure_constellations_import_source_column();
    db_ensure_constellations_tour_columns();
    db_ensure_nodes_import_slug_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_show_keywords_column();
    db_ensure_nodes_use_image_as_node_column();
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT id, name, tagline, slug, theme, import_source,
               tour_enabled, tour_start_mode, tour_idle_seconds,
               tour_node_selection, tour_random_count, tour_default_dwell, tour_loop
        FROM constellations WHERE id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    // Keywords for this galaxy
    $kwStmt = $pdo->prepare("SELECT id, keyword FROM keywords WHERE constellation_id = :id ORDER BY id");
    $kwStmt->execute([':id' => $id]);
    $keywords = $kwStmt->fetchAll();

    // Nodes for this galaxy. Pull all relevant columns.
    db_ensure_nodes_pdf_url_column();
    $nodeStmt = $pdo->prepare("
        SELECT id, name, description, url, image_url, image_attribution, icon_url,
               embed_code, audio_url, audio_autoplay, audio_loop,
               video_url, video_autoplay, pdf_url, animation,
               node_type, target_constellation_id, is_accentuated, show_keywords, use_image_as_node,
               source_facet, media_type, source_created_at, import_slug, created_by
        FROM nodes WHERE constellation_id = :id ORDER BY id
    ");
    $nodeStmt->execute([':id' => $id]);
    $nodes = $nodeStmt->fetchAll();

    // Bulk: keyword names per node + target constellation slug per node
    $nodeIds = array_map(fn($n) => (int)$n['id'], $nodes);
    $keywordsByNode = $nodeIds === [] ? [] : db_get_keywords_for_nodes_bulk($nodeIds);

    // Build target_constellation_slug map for portal nodes
    $targetCids = [];
    foreach ($nodes as $n) {
        if ($n['target_constellation_id'] !== null && $n['target_constellation_id'] !== '') {
            $targetCids[(int)$n['target_constellation_id']] = true;
        }
    }
    $targetSlugMap = [];
    if ($targetCids !== []) {
        $ids = array_keys($targetCids);
        $place = implode(',', array_map('intval', $ids));
        $r = $pdo->query("SELECT id, slug FROM constellations WHERE id IN ($place)")->fetchAll();
        foreach ($r as $rr) {
            $targetSlugMap[(int)$rr['id']] = $rr['slug'] ?? null;
        }
    }

    // created_by user IDs → emails (for portability)
    $createdByIds = [];
    foreach ($nodes as $n) {
        if ($n['created_by'] !== null && $n['created_by'] !== '') {
            $createdByIds[$n['created_by']] = true;
        }
    }
    $createdByEmailMap = [];
    if ($createdByIds !== []) {
        $place = implode(',', array_fill(0, count($createdByIds), '?'));
        $stmt2 = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($place)");
        $stmt2->execute(array_keys($createdByIds));
        foreach ($stmt2->fetchAll() as $rr) {
            $createdByEmailMap[$rr['id']] = $rr['email'];
        }
    }

    // Attach per-node enrichment
    foreach ($nodes as &$n) {
        $nid = (int)$n['id'];
        $n['keyword_names'] = $keywordsByNode[$nid] ?? [];
        $tcid = $n['target_constellation_id'] !== null && $n['target_constellation_id'] !== '' ? (int)$n['target_constellation_id'] : null;
        $n['target_constellation_slug'] = $tcid !== null ? ($targetSlugMap[$tcid] ?? null) : null;
        $n['created_by_email'] = $n['created_by'] !== null && $n['created_by'] !== ''
            ? ($createdByEmailMap[$n['created_by']] ?? null)
            : null;
    }
    unset($n);

    // Editors (user_constellations) for this galaxy → emails
    $eStmt = $pdo->prepare("
        SELECT u.email FROM user_constellations uc
        INNER JOIN users u ON u.id = uc.user_id
        WHERE uc.constellation_id = :id
    ");
    $eStmt->execute([':id' => $id]);
    $editorEmails = array_map(fn($r) => $r['email'], $eStmt->fetchAll());

    return [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'tagline' => $row['tagline'],
        'slug' => $row['slug'],
        'theme' => $row['theme'],
        'import_source' => $row['import_source'],
        'is_default' => ((int)$row['id'] === db_get_default_constellation_id()),
        'keywords' => $keywords,
        'nodes' => $nodes,
        'editor_emails' => $editorEmails,
        'tour' => [
            'enabled' => (bool)$row['tour_enabled'],
            'start_mode' => (string)$row['tour_start_mode'],
            'idle_seconds' => (int)$row['tour_idle_seconds'],
            'node_selection' => (string)$row['tour_node_selection'],
            'random_count' => (int)$row['tour_random_count'],
            'default_dwell' => (int)$row['tour_default_dwell'],
            'loop' => (bool)$row['tour_loop'],
            'keyword_ids' => db_get_tour_keyword_ids((int)$row['id']),
        ],
    ];
}

/**
 * Pull all users for a backup dump, including password hashes and assigned galaxy slugs.
 */
function db_get_users_for_dump(): array {
    $pdo = getDB();
    $rows = $pdo->query("
        SELECT id, email, password, firstname, lastname, type, date_created, date_last_login
        FROM users ORDER BY date_created
    ")->fetchAll();

    // Bulk-load editor constellation slugs per user
    $linkRows = $pdo->query("
        SELECT uc.user_id, c.slug
        FROM user_constellations uc
        INNER JOIN constellations c ON c.id = uc.constellation_id
        WHERE c.slug IS NOT NULL AND c.slug != ''
    ")->fetchAll();
    $byUser = [];
    foreach ($linkRows as $r) {
        $byUser[$r['user_id']][] = $r['slug'];
    }

    foreach ($rows as &$u) {
        $u['editor_galaxy_slugs'] = $byUser[$u['id']] ?? [];
    }
    unset($u);
    return $rows;
}

/**
 * Update project_info.default_constellation_id for all locales.
 */
function db_set_default_constellation_id(int $id): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE project_info SET default_constellation_id = :id")->execute([':id' => $id]);
}

/**
 * Insert a user with an explicit id and password hash (used during restore to preserve identity).
 * date_created is preserved if provided.
 */
function db_user_create_raw(string $id, string $email, string $passwordHash, string $firstname, string $lastname, int $type, ?string $dateCreated = null): void {
    $pdo = getDB();
    if ($dateCreated !== null && $dateCreated !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO users (id, email, password, firstname, lastname, type, date_created)
            VALUES (:id, :email, :password, :firstname, :lastname, :type, :date_created)
        ");
        $stmt->execute([
            ':id' => $id, ':email' => $email, ':password' => $passwordHash,
            ':firstname' => $firstname, ':lastname' => $lastname, ':type' => $type,
            ':date_created' => $dateCreated,
        ]);
    } else {
        db_insert_user($id, $email, $passwordHash, $firstname, $lastname, $type);
    }
}

/**
 * Create a node for a restore: takes a full payload array. URLs are pre-resolved strings;
 * keywords are linked separately by the caller. target_constellation_id may be null
 * here and updated later in a second pass once all galaxies exist.
 */
function db_create_node_for_restore(int $constellationId, array $node): int {
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_import_slug_column();
    db_ensure_nodes_show_keywords_column();
    db_ensure_nodes_pdf_url_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO nodes (
            constellation_id, name, description, url,
            image_url, image_attribution, icon_url, embed_code,
            audio_url, audio_autoplay, audio_loop,
            video_url, video_autoplay, pdf_url, animation,
            node_type, target_constellation_id, is_accentuated, show_keywords,
            source_facet, media_type, source_created_at, import_slug, created_by
        ) VALUES (
            :constellation_id, :name, :description, :url,
            :image_url, :image_attribution, :icon_url, :embed_code,
            :audio_url, :audio_autoplay, :audio_loop,
            :video_url, :video_autoplay, :pdf_url, :animation,
            :node_type, :target_constellation_id, :is_accentuated, :show_keywords,
            :source_facet, :media_type, :source_created_at, :import_slug, :created_by
        )
    ");
    $stmt->execute([
        ':constellation_id' => $constellationId,
        ':name' => (string)($node['name'] ?? ''),
        ':description' => $node['description'] ?? null,
        ':url' => $node['url'] ?? null,
        ':image_url' => $node['image_url'] ?? null,
        ':image_attribution' => $node['image_attribution'] ?? null,
        ':icon_url' => $node['icon_url'] ?? null,
        ':embed_code' => $node['embed_code'] ?? null,
        ':audio_url' => $node['audio_url'] ?? null,
        ':audio_autoplay' => !empty($node['audio_autoplay']) ? 1 : 0,
        ':audio_loop' => !empty($node['audio_loop']) ? 1 : 0,
        ':video_url' => $node['video_url'] ?? null,
        ':video_autoplay' => !empty($node['video_autoplay']) ? 1 : 0,
        ':pdf_url' => $node['pdf_url'] ?? null,
        ':animation' => is_string($node['animation'] ?? null) ? $node['animation'] : json_encode($node['animation'] ?? new \stdClass()),
        ':node_type' => (string)($node['node_type'] ?? 'object'),
        ':target_constellation_id' => isset($node['target_constellation_id']) && $node['target_constellation_id'] !== null && $node['target_constellation_id'] !== '' ? (int)$node['target_constellation_id'] : null,
        ':is_accentuated' => !empty($node['is_accentuated']) ? 1 : 0,
        ':show_keywords' => !empty($node['show_keywords']) ? 1 : 0,
        ':source_facet' => $node['source_facet'] ?? null,
        ':media_type' => $node['media_type'] ?? null,
        ':source_created_at' => $node['source_created_at'] ?? null,
        ':import_slug' => $node['import_slug'] ?? null,
        ':created_by' => $node['created_by'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Set the target_constellation_id for a node (used in second pass after all galaxies are created).
 */
function db_set_node_target_constellation(int $nodeId, ?int $targetCid): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE nodes SET target_constellation_id = :tcid WHERE id = :id")
        ->execute([':tcid' => $targetCid, ':id' => $nodeId]);
}

/**
 * Set the created_by user_id for a node (used during restore once users exist).
 */
function db_set_node_created_by(int $nodeId, ?string $userId): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE nodes SET created_by = :uid WHERE id = :id")
        ->execute([':uid' => $userId, ':id' => $nodeId]);
}

/**
 * Find a constellation id by slug, returning null if missing.
 */
function db_get_constellation_id_by_slug(string $slug): ?int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM constellations WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * Delete a galaxy and everything inside it: nodes, keywords, node_keywords,
 * user_constellations rows, portal references from other galaxies, and the
 * uploads/{id}/ directory on disk. Optionally allows deleting the default galaxy.
 */
function db_delete_galaxy_deep(int $id, bool $allowDefault = false): void {
    if (!$allowDefault && $id === db_get_default_constellation_id()) {
        throw new InvalidArgumentException('The default galaxy cannot be deleted.');
    }
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // Null out portal references in OTHER galaxies that target this one
        $pdo->prepare("UPDATE nodes SET target_constellation_id = NULL WHERE target_constellation_id = :id AND constellation_id != :id2")
            ->execute([':id' => $id, ':id2' => $id]);

        // Delete node_keywords for this galaxy's nodes (FK cascade will also handle this, but be explicit)
        $pdo->prepare("DELETE nk FROM node_keywords nk INNER JOIN nodes n ON n.id = nk.node_id WHERE n.constellation_id = :id")
            ->execute([':id' => $id]);

        // Delete this galaxy's nodes
        $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :id")->execute([':id' => $id]);

        // Delete keywords
        $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :id")->execute([':id' => $id]);

        // Delete user_constellations rows (FK ON DELETE CASCADE will also handle this)
        $pdo->prepare("DELETE FROM user_constellations WHERE constellation_id = :id")->execute([':id' => $id]);

        // Delete the constellation itself
        $pdo->prepare("DELETE FROM constellations WHERE id = :id")->execute([':id' => $id]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Wipe the uploads directory for this galaxy. Bounded to UPLOAD_DIR.
    if (defined('UPLOAD_DIR')) {
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . $id;
        db_rrmdir($dir, UPLOAD_DIR);
    }
}

/**
 * Wipe ALL user-data tables for a snapshot restore. Preserves api_keys,
 * project_info, snapshots, snapshot_schedule. Also wipes UPLOAD_DIR contents
 * (per-galaxy subdirectories).
 */
function db_wipe_all_data(): void {
    $pdo = getDB();
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DELETE FROM node_keywords");
        $pdo->exec("DELETE FROM nodes");
        $pdo->exec("DELETE FROM keywords");
        $pdo->exec("DELETE FROM user_constellations");
        $pdo->exec("DELETE FROM constellations");
        $pdo->exec("DELETE FROM users");
        // Reset auto-increment so restored IDs start fresh
        $pdo->exec("ALTER TABLE constellations AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE nodes AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE keywords AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE node_keywords AUTO_INCREMENT = 1");
    } finally {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    // Wipe the per-galaxy uploads subdirectories. We do this by iterating
    // direct children of UPLOAD_DIR rather than nuking the dir itself,
    // so we don't touch any flat-stored files (e.g. duplicated nodes).
    if (defined('UPLOAD_DIR') && is_dir(UPLOAD_DIR)) {
        $items = @scandir(UPLOAD_DIR);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $full = rtrim(UPLOAD_DIR, '/') . '/' . $item;
                // Only descend into numeric directories (galaxy IDs)
                if (is_dir($full) && ctype_digit($item)) {
                    db_rrmdir($full, UPLOAD_DIR);
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// CLI / maintenance (continued)
// ---------------------------------------------------------------------------

/**
 * @return array{dropped: list<string>, errors: list<string>}
 */
function dropAllTables(PDO $pdo): array {
    $dropped = [];
    $errors = [];
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $tables = getAllTables($pdo);
        foreach ($tables as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `$table`");
                $dropped[] = $table;
            } catch (PDOException $e) {
                $errors[] = "Failed to drop table '$table': " . $e->getMessage();
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (PDOException $e2) {
            // ignore
        }
        $errors[] = "Database error: " . $e->getMessage();
    }
    return ['dropped' => $dropped, 'errors' => $errors];
}
