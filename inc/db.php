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
    'admin_tab_galaxies', 'admin_tab_clusters', 'admin_tab_users', 'admin_tab_backup', 'admin_tab_snapshots', 'admin_tab_settings', 'admin_tab_pluriverse', 'admin_tab_api_keys', 'admin_tab_php_info',
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
    'admin_label_instance_name', 'admin_help_instance_name',
    'admin_label_pdf_max', 'admin_help_pdf_max', 'admin_btn_save_settings',
    // Pluriverse tab (admin/index.php?tab=pluriverse + admin/pluriverse-apply.php).
    'admin_pluriverse_heading', 'admin_pluriverse_subheading',
    'admin_pluriverse_status_heading', 'admin_pluriverse_status_status', 'admin_pluriverse_status_submitted', 'admin_pluriverse_status_name', 'admin_pluriverse_status_email', 'admin_pluriverse_status_fingerprint', 'admin_pluriverse_status_help',
    'admin_pluriverse_status_expired_heading', 'admin_pluriverse_status_expired_body', 'admin_pluriverse_btn_rejoin',
    'admin_pluriverse_field_url_label', 'admin_pluriverse_field_url_help',
    'admin_pluriverse_field_name_label', 'admin_pluriverse_field_name_help',
    'admin_pluriverse_field_email_label', 'admin_pluriverse_field_email_help',
    'admin_pluriverse_field_framing_label', 'admin_pluriverse_field_framing_help',
    'admin_pluriverse_field_galaxies_label', 'admin_pluriverse_field_galaxies_summary', 'admin_pluriverse_field_galaxies_empty', 'admin_pluriverse_field_galaxies_disclosure',
    'admin_pluriverse_field_contacts_label', 'admin_pluriverse_field_contacts_help',
    'admin_pluriverse_btn_add_contact', 'admin_pluriverse_contact_service_placeholder', 'admin_pluriverse_contact_handle_placeholder',
    'admin_pluriverse_btn_submit', 'admin_pluriverse_submit_help',
    'admin_pluriverse_link_change_name',
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
    'admin_modal_label_type_galaxy_name', 'admin_modal_label_type_to_confirm', 'admin_modal_placeholder_type_name',
    'admin_modal_btn_delete',
    'admin_modal_deletion_impact_title', 'admin_modal_deletion_impact_intro', 'admin_modal_deletion_impact_row',
    'admin_error_user_not_found', 'admin_error_galaxy_not_found', 'admin_error_delete_confirm_mismatch',
    'admin_setup_perms_heading', 'admin_setup_perms_intro', 'admin_setup_perms_advice',

    // C5: admin/setup.php (post-DB strings). Pre-DB strings live in $SETUP_PRE_DB_STRINGS
    // inside admin/setup.php itself, because they render before the project_info table exists.
    'admin_setup_website_info_subtitle',
    'admin_setup_db_tables_created',
    'admin_setup_website_name_label', 'admin_setup_website_name_help',
    'admin_setup_tagline_label', 'admin_setup_tagline_help',
    'admin_setup_website_info_footer_help', 'admin_setup_website_info_continue',
    'admin_setup_schema_details_heading',
    'admin_setup_schema_db_created', 'admin_setup_schema_db_exists',
    'admin_setup_schema_tables_created_one', 'admin_setup_schema_tables_created_many',
    'admin_setup_schema_tables_existed_one', 'admin_setup_schema_tables_existed_many',
    'admin_setup_schema_no_tables',
    'admin_setup_schema_api_key_heading', 'admin_setup_schema_api_key_help',
    'admin_setup_admin_user_heading', 'admin_setup_admin_user_intro',
    'admin_setup_first_name_label', 'admin_setup_last_name_label',
    'admin_setup_email_label', 'admin_setup_email_help',
    'admin_setup_password_label', 'admin_setup_password_help',
    'admin_setup_confirm_password_label',
    'admin_setup_create_admin_btn',
    'admin_setup_admin_user_created', 'admin_setup_admin_user_can_login', 'admin_setup_admin_user_login_link',
    'admin_setup_config_created_flash',
    'admin_setup_complete_with_schema', 'admin_setup_complete_no_schema',
    'admin_setup_db_error_prefix', 'admin_setup_error_prefix',
    'admin_setup_status_heading',
    'admin_setup_config_file_label', 'admin_setup_config_file_created', 'admin_setup_config_file_missing',
    'admin_setup_db_connection_label', 'admin_setup_db_connection_connected', 'admin_setup_db_connection_failed',
    'admin_setup_project_info_label', 'admin_setup_project_info_initialized', 'admin_setup_project_info_not_initialized',
    'admin_setup_link_go_to_telaris', 'admin_setup_link_admin_console', 'admin_setup_link_reconfigure_db',
    'admin_setup_validation_all_fields_required', 'admin_setup_validation_passwords_mismatch',
    'admin_setup_validation_password_too_short', 'admin_setup_validation_db_unavailable',

    // C5b: utils/login.php + utils/forgot.php + utils/reset.php (auth chrome).
    'auth_login_page_title', 'auth_login_heading', 'auth_login_subtitle',
    'auth_email_label', 'auth_password_label',
    'auth_login_submit', 'auth_login_forgot_link', 'auth_login_back_link',
    'auth_error_invalid_request',
    'auth_error_throttled',
    'auth_login_error_required', 'auth_login_error_invalid',
    'auth_forgot_page_title', 'auth_forgot_heading', 'auth_forgot_subtitle',
    'auth_forgot_generic_notice', 'auth_forgot_error_invalid_email',
    'auth_forgot_submit', 'auth_forgot_back_link',
    'auth_email_subject', 'auth_email_greeting_named', 'auth_email_greeting_anon',
    'auth_email_intro', 'auth_email_expiry',
    'auth_email_text_intro', 'auth_email_text_outro',
    'auth_reset_page_title', 'auth_reset_heading',
    'auth_reset_success_message', 'auth_reset_btn_go_to_login',
    'auth_reset_invalid_token_message', 'auth_reset_btn_request_new_link',
    'auth_reset_intro_html',
    'auth_reset_new_password_label', 'auth_reset_password_help',
    'auth_reset_confirm_password_label', 'auth_reset_submit',
    'auth_reset_error_password_too_short', 'auth_reset_error_password_mismatch',

    // C7a: inc/partials/galaxy-edit-modal.php (Edit Galaxy modal, shared editor + admin).
    'gem_heading',
    'gem_name_label', 'gem_name_duplicate_error',
    'gem_tagline_label',
    'gem_slug_label', 'gem_slug_placeholder', 'gem_slug_duplicate_error', 'gem_slug_help',
    'gem_theme_label',
    'gem_theme_cosmic', 'gem_theme_simple', 'gem_theme_abstract',
    'gem_theme_rectangles', 'gem_theme_stripes', 'gem_theme_tech',
    'gem_tags_label', 'gem_tags_placeholder', 'gem_tags_help',
    'gem_bulk_actions_label', 'gem_bulk_actions_help',
    'gem_bulk_use_images_btn', 'gem_bulk_revert_icons_btn',
    'gem_keyword_chips_label', 'gem_keyword_chips_help',
    'gem_related_label', 'gem_related_help',
    'gem_2d_view_label', 'gem_2d_view_help',
    'gem_idle_spotlight_label', 'gem_idle_spotlight_help',
    'gem_pick_from_label',
    'gem_idle_pick_all', 'gem_idle_pick_accentuated',
    'gem_idle_trigger_label',
    'gem_autotour_label', 'gem_autotour_preview_btn', 'gem_autotour_preview_title',
    'gem_autotour_help',
    'gem_start_mode_label',
    'gem_start_mode_manual', 'gem_start_mode_idle', 'gem_start_mode_immediate',
    'gem_idle_threshold_label',
    'gem_immediate_audio_warning',
    'gem_which_nodes_label',
    'gem_nodes_all', 'gem_nodes_accentuated', 'gem_nodes_random_n', 'gem_nodes_tagged',
    'gem_random_count_label',
    'gem_keywords_label', 'gem_keywords_help',
    'gem_dwell_label',
    'gem_loop_label',
    'gem_submit_btn', 'gem_cancel_btn', 'gem_close_btn',

    // C7b: API error envelope titles. Code format <http-status>.<3-digit-subcode>
    // (RFC 9457 Problem Details). Locale-invariant code; localized title.
    // See inc/api-error.php for the registry. Keys are api_error_<status>_<subcode>.
    'api_error_400_001', 'api_error_400_002', 'api_error_400_003', 'api_error_400_004',
    'api_error_400_005', 'api_error_400_006', 'api_error_400_007', 'api_error_400_008',
    'api_error_400_009', 'api_error_400_010', 'api_error_400_011', 'api_error_400_012',
    'api_error_400_013', 'api_error_400_014', 'api_error_400_015', 'api_error_400_016',
    'api_error_400_017', 'api_error_400_018', 'api_error_400_019', 'api_error_400_020',
    'api_error_400_021', 'api_error_400_022', 'api_error_400_023', 'api_error_400_024',
    'api_error_400_025', 'api_error_400_026', 'api_error_400_027', 'api_error_400_028',
    'api_error_400_029', 'api_error_400_030', 'api_error_400_031', 'api_error_400_032',
    'api_error_400_033', 'api_error_400_034', 'api_error_400_035', 'api_error_400_036',
    'api_error_400_037', 'api_error_400_038', 'api_error_400_039', 'api_error_400_040',
    'api_error_400_041', 'api_error_400_042', 'api_error_400_043',
    'api_error_400_044', 'api_error_400_045', 'api_error_400_046',
    'api_error_401_001', 'api_error_401_002',
    'api_error_403_001', 'api_error_403_002', 'api_error_403_003', 'api_error_403_004',
    'api_error_403_005', 'api_error_403_006', 'api_error_403_007', 'api_error_403_008',
    'api_error_404_001', 'api_error_404_002', 'api_error_404_003', 'api_error_404_004',
    'api_error_404_005', 'api_error_404_006', 'api_error_404_007', 'api_error_404_008',
    'api_error_404_009', 'api_error_404_010', 'api_error_404_011', 'api_error_404_012',
    'api_error_404_013', 'api_error_404_014',
    'api_error_405_001',
    'api_error_409_001', 'api_error_409_002',
    'api_error_500_001', 'api_error_500_002', 'api_error_500_003', 'api_error_500_004',
    'api_error_500_005', 'api_error_500_006', 'api_error_500_007', 'api_error_500_008',
    'api_error_500_009', 'api_error_500_010', 'api_error_500_011', 'api_error_500_012',
    'api_error_500_013', 'api_error_500_014', 'api_error_500_015',
    'api_error_502_001',

    // C7c: inc/galaxy-update.php result messages (rendered as editor/admin toasts).
    'galaxy_update_missing_id', 'galaxy_update_not_authorized', 'galaxy_update_no_access',
    'galaxy_update_name_required',
    'galaxy_update_duplicate_name', 'galaxy_update_duplicate_slug', 'galaxy_update_duplicate_both',
    'galaxy_update_success',

    // C7d: inc/bridges/mocambos/admin.php (Mocambos bridge import modal + JS).
    // Inactive until 'mocambos' is in TELARIS_BRIDGES, but localized in advance
    // so the bridge ships with no English-default surfaces.
    'mocambos_btn_import_from', 'mocambos_modal_heading',
    'mocambos_label_api_url', 'mocambos_help_api_url', 'mocambos_btn_connect',
    'mocambos_text_loading', 'mocambos_btn_back',
    'mocambos_text_connected_to', 'mocambos_text_select_intro',
    'mocambos_text_starting_import',
    'mocambos_text_refresh_intro', 'mocambos_text_refresh_confirm_instruction',
    'mocambos_placeholder_refresh_confirm', 'mocambos_btn_refresh',
    'mocambos_btn_cancel', 'mocambos_btn_import_selected', 'mocambos_btn_close',
    'mocambos_btn_modal_backdrop_close',
    'mocambos_js_validation_report_title', 'mocambos_js_validation_url_prefix',
    'mocambos_js_validation_date_prefix', 'mocambos_js_validating_api',
    'mocambos_js_enter_url', 'mocambos_js_validation_failed_intro',
    'mocambos_js_copied', 'mocambos_js_copy_report',
    'mocambos_js_could_not_validate', 'mocambos_js_network_error',
    'mocambos_js_fetch_failed', 'mocambos_js_no_galaxias',
    'mocambos_js_badge_imported', 'mocambos_js_connect_failed',
    'mocambos_js_select_at_least_one',
    'mocambos_js_confirm_refresh_intro', 'mocambos_js_confirm_refresh_continue',
    'mocambos_js_import_failed_generic', 'mocambos_js_import_complete_status',
    'mocambos_js_status_label_new', 'mocambos_js_status_label_refreshed',
    'mocambos_js_items_count',
    'mocambos_js_completed_success', 'mocambos_js_completed_errors',
    'mocambos_js_refresh_complete_log', 'mocambos_js_refresh_complete_status',
    'mocambos_js_refresh_failed_status',
    'mocambos_js_missing_source', 'mocambos_js_refreshing',
    'mocambos_js_error_prefix', 'mocambos_js_unknown_error',

    // C7e: inc/bridges/mocambos/handler.php (HTTP streamMsg + validation + JSON
    // errors + CLI output). All strings localized; codes for JSON errors are
    // in the existing api_error_* registry (RFC 9457). Templated strings use
    // sprintf placeholders.
    // HTTP streamMsg payloads (rendered in the live import-log console).
    'mocambos_h_resolved_mucua_names', 'mocambos_h_fetching_media',
    'mocambos_h_total_items_fetched', 'mocambos_h_processing_galaxia',
    'mocambos_h_import_complete', 'mocambos_h_full_refresh_clearing',
    'mocambos_h_re_importing_diff', 'mocambos_h_backfilled_slugs',
    'mocambos_h_diff_summary',
    'mocambos_h_deleting_removed', 'mocambos_h_updating_modified',
    'mocambos_h_created_constellation',
    'mocambos_h_adding_new_nodes', 'mocambos_h_phase1_creating',
    'mocambos_h_nodes_created_progress', 'mocambos_h_phase1_complete',
    'mocambos_h_phase2_downloading', 'mocambos_h_downloading_image',
    'mocambos_h_downloading_video', 'mocambos_h_downloading_audio',
    'mocambos_h_phase2_complete', 'mocambos_h_phase2_complete_with_errors',
    'mocambos_h_galaxia_done', 'mocambos_h_galaxia_done_with_errors', 'mocambos_h_concurrent_import',
    'mocambos_h_failed_to_create_node', 'mocambos_h_media_downloads_failed',
    // Validation check details (rendered in the validation report).
    'mocambos_h_check_connection_failed', 'mocambos_h_check_galaxia_http_fail',
    'mocambos_h_check_galaxia_not_array', 'mocambos_h_check_galaxia_empty',
    'mocambos_h_check_galaxia_missing_fields', 'mocambos_h_check_galaxia_ok',
    'mocambos_h_check_mucua_http_fail', 'mocambos_h_check_mucua_not_array',
    'mocambos_h_check_mucua_empty', 'mocambos_h_check_mucua_missing_fields',
    'mocambos_h_check_mucua_ok',
    'mocambos_h_check_acervo_http_fail', 'mocambos_h_check_acervo_no_items',
    'mocambos_h_check_acervo_ok',
    'mocambos_h_check_blog_http_fail', 'mocambos_h_check_blog_no_items',
    'mocambos_h_check_blog_ok',
    // CLI output (terminal mode; defaults to en in CLI since no Accept-Language).
    'mocambos_h_cli_header', 'mocambos_h_cli_prompt_api_base',
    'mocambos_h_cli_err_api_base_required', 'mocambos_h_cli_err_usage',
    'mocambos_h_cli_connecting', 'mocambos_h_cli_fetch_galaxias_failed',
    'mocambos_h_cli_found_counts',
    'mocambos_h_cli_available_galaxias_at', 'mocambos_h_cli_col_slug',
    'mocambos_h_cli_col_name', 'mocambos_h_cli_col_smid',
    'mocambos_h_cli_available_galaxias', 'mocambos_h_cli_already_imported',
    'mocambos_h_cli_prompt_select_galaxia', 'mocambos_h_cli_no_galaxia_selected',
    'mocambos_h_cli_err_galaxia_required', 'mocambos_h_cli_matched_slug',
    'mocambos_h_cli_galaxia_not_found',
    'mocambos_h_cli_prompt_download_media', 'mocambos_h_cli_prompt_limit',
    'mocambos_h_cli_summary_galaxia', 'mocambos_h_cli_summary_api',
    'mocambos_h_cli_summary_media', 'mocambos_h_cli_summary_limit',
    'mocambos_h_cli_value_skip', 'mocambos_h_cli_value_download',
    'mocambos_h_cli_value_all',
    'mocambos_h_cli_prompt_proceed', 'mocambos_h_cli_aborted',
    'mocambos_h_cli_galaxia_info', 'mocambos_h_cli_total_items',
    'mocambos_h_cli_limited_to',
    'mocambos_h_cli_constellation_label', 'mocambos_h_cli_imported_summary',
    'mocambos_h_cli_errors_count', 'mocambos_h_cli_media_skipped',
    'mocambos_h_cli_constellation_new', 'mocambos_h_cli_constellation_existing',

    // C7f: edit/keyword-canvas.php (PHP chrome). The JS-side already uses
    // editor_kc_* (C2); these are page-level + modal chrome strings.
    'editor_kc_page_title',
    'editor_kc_err_missing_galaxy_id', 'editor_kc_err_galaxy_not_found',
    'editor_kc_err_clusters_no_canvas', 'editor_kc_err_no_edit_access',
    'editor_kc_back_link', 'editor_kc_page_title_template',
    'editor_kc_empty_state', 'editor_kc_mobile_block',
    'editor_kc_note_modal_title', 'editor_kc_note_modal_intro',
    'editor_kc_note_modal_cancel', 'editor_kc_note_modal_save',
    'editor_kc_keyword_modal_title', 'editor_kc_keyword_modal_new_name_label',
    'editor_kc_keyword_modal_cancel', 'editor_kc_keyword_modal_delete',
    'editor_kc_keyword_modal_rename',
    'editor_kc_conflict_modal_title', 'editor_kc_conflict_modal_body_suffix',
    'editor_kc_conflict_modal_options_intro',
    'editor_kc_conflict_modal_change', 'editor_kc_conflict_modal_merge',
    'editor_kc_line_modal_title', 'editor_kc_line_modal_noauth',
    'editor_kc_line_modal_close', 'editor_kc_line_modal_edit', 'editor_kc_line_modal_delete',
    'editor_kc_backdrop_close',
    'editor_kc_help_button', 'editor_kc_help_title', 'editor_kc_help_purpose',
    'editor_kc_help_intro',
    'editor_kc_help_move_label', 'editor_kc_help_move_body',
    'editor_kc_help_connect_label', 'editor_kc_help_connect_body',
    'editor_kc_help_edit_label', 'editor_kc_help_edit_body',
    'editor_kc_help_pan_label', 'editor_kc_help_pan_body',
    'editor_kc_help_zoom_label', 'editor_kc_help_zoom_body',
    'editor_kc_help_cancel_label', 'editor_kc_help_cancel_body',
    'editor_kc_help_close',

    // C7h: operator-only nginx-config warning banner in inc/main-view.php.
    'visitor_nginx_warning_heading', 'visitor_nginx_warning_intro',
    'visitor_nginx_warning_reload', 'visitor_nginx_warning_footer',
];

/**
 * Locales supported (one row per locale in project_info). The default locale
 * is the first entry. To add a new locale (e.g. French), append its 2-letter
 * code here AND add a matching block in db_default_project_info_rows().
 */
const PROJECT_INFO_LOCALES = ['en', 'es', 'pt', 'fr'];

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
 * Translate a single project_info key against the current locale.
 *
 * When the locale row for the key is missing or empty, returns the key
 * itself, NOT the supplied English fallback. This implements the
 * decolonial-identifier stance: no user-facing string defaults to
 * English. When a translation is missing the worst-case visible token
 * is the locale-invariant key (a documented identifier), not an
 * unstated English default that would re-enroll English as the global
 * lingua franca.
 *
 * The $fallback parameter is preserved for the function signature and
 * serves as inline source-code documentation for the developer reading
 * the call site. It is not used in production rendering.
 *
 * Returns a raw string. Reach for bare t() only when one of these
 * applies:
 *   - sprintf composition where the key carries an HTML format string
 *     with %s / %d placeholders (call site escapes the args).
 *   - json_encode contexts (JSON does its own escaping; pass it raw).
 *   - Plain-text email body composition.
 *   - The handful of keys whose value legitimately contains HTML markup
 *     (e.g. admin_modal_bulk_users_field_email = '<strong>email</strong>').
 *
 * For ALL other render contexts — every `<?= t(...) ?>` /
 * `<?php echo t(...) ?>` in HTML body or attribute position — use
 * **t_attr()** instead. The M-E3 audit (third pass, v6.10.14) swept the
 * codebase to use t_attr() at every direct echo site so admin-writable
 * project_info values cannot inject markup via the render path.
 */
function t(string $key, string $fallback = ''): string {
    $strings = locale_init_strings();
    $val = $strings[$key] ?? '';
    return $val !== '' ? (string)$val : $key;
}

/**
 * t() wrapped in htmlspecialchars (ENT_QUOTES, UTF-8). Safe to drop into
 * any HTML output context — attribute value, element body, title.
 *
 * The "attr" in the name is historical; the function escapes for both
 * attribute and body contexts. Treat it as `t_html()` in your head.
 *
 * Direct render sites (`<?= t_attr('key') ?>`, `<?php echo t_attr('key') ?>`)
 * are the safe default. Reach for bare t() only when one of the cases in
 * t()'s docblock above applies.
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
            'admin_tab_pluriverse' => 'Pluriverse',
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
            'admin_label_instance_name' => 'Name',
            'admin_help_instance_name' => 'Public name for this instance. Shown on the visitor side and used as the Pluriverse-directory label when you apply to publish. Defaults to the first segment of the hostname if blank.',
            'admin_label_pdf_max' => 'PDF max size (MB)',
            'admin_help_pdf_max' => "Largest PDF a wormhole can carry. Default 25 MB. Editors uploading bigger files will get a 'File exceeds maximum allowed size' error.",
            'admin_btn_save_settings' => 'Save settings',
            // Pluriverse tab.
            'admin_pluriverse_heading' => 'Join the Pluriverse',
            'admin_pluriverse_subheading' => 'Federate this instance into the Pluriverse so it appears in the public instance directory at www.telaris.ca. The request carries your URL, name, operator contact, and chosen galaxies, signed by this instance\'s pluriverse.key.',
            'admin_pluriverse_status_heading' => 'Membership status',
            'admin_pluriverse_status_status' => 'Status',
            'admin_pluriverse_status_submitted' => 'Submitted at',
            'admin_pluriverse_status_name' => 'Name',
            'admin_pluriverse_status_email' => 'Operator email',
            'admin_pluriverse_status_fingerprint' => 'Public-key fingerprint stored',
            'admin_pluriverse_status_help' => 'Check your operator email for a verification link. Both the link and the pending request expire 24 hours after submission. The admins at the Pluriverse review the request after you verify and let you know when your instance is published.',
            'admin_pluriverse_status_expired_heading' => 'Join request expired',
            'admin_pluriverse_status_expired_body' => 'The verification link from your last join request was not opened within 24 hours, so the request expired. You can submit a fresh one with the button below; you will receive a new verification email at your operator address.',
            'admin_pluriverse_btn_rejoin' => 'Re-join the Pluriverse',
            'admin_pluriverse_field_url_label' => 'Instance URL',
            'admin_pluriverse_field_url_help' => 'Canonical https URL of this instance. The hostname is derived from this.',
            'admin_pluriverse_field_name_label' => 'Name',
            'admin_pluriverse_field_name_help' => 'Short public name for this instance, unique across the Pluriverse. If a name is taken you will be told to pick another.',
            'admin_pluriverse_field_email_label' => 'Operator email',
            'admin_pluriverse_field_email_help' => 'Magic-link target. Encrypted at rest on the Pluriverse. Edit if you want a different address from your admin account.',
            'admin_pluriverse_field_framing_label' => 'Editorial framing',
            'admin_pluriverse_field_framing_help' => 'A sentence or three. What is this instance for? Optional.',
            'admin_pluriverse_field_galaxies_label' => 'Publishable galaxies',
            'admin_pluriverse_field_galaxies_summary' => '%d galaxies on this instance will be published. New galaxies are added automatically as you create them.',
            'admin_pluriverse_field_galaxies_empty' => 'No galaxies yet. Joining registers this instance now; new galaxies are picked up automatically as you create them.',
            'admin_pluriverse_field_galaxies_disclosure' => 'See the list',
            'admin_pluriverse_field_contacts_label' => 'Secondary contacts',
            'admin_pluriverse_field_contacts_help' => 'Optional fallback channels (Matrix, XMPP, etc.). Up to eight.',
            'admin_pluriverse_btn_add_contact' => 'Add another',
            'admin_pluriverse_contact_service_placeholder' => 'service',
            'admin_pluriverse_contact_handle_placeholder' => 'handle / address',
            'admin_pluriverse_btn_submit' => 'Join the Pluriverse',
            'admin_pluriverse_submit_help' => 'This instance will sign the request with its pluriverse.key (Ed25519) and post it to www.telaris.ca. The Pluriverse will email a verification link to the operator address.',
            'admin_pluriverse_link_change_name' => '(change in Global Settings)',
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
            'admin_modal_label_type_to_confirm' => 'To confirm, type the following exactly:',
            'admin_modal_placeholder_type_name' => 'Type name here...',
            'admin_modal_btn_delete' => 'Delete',
            'admin_modal_deletion_impact_title' => '⚠️ Deletion Impact:',
            'admin_modal_deletion_impact_intro' => 'The following portals in other galaxies point to this network and will also be deleted:',
            'admin_modal_deletion_impact_row' => '<strong>%s</strong> (in galaxy: %s)',
            'admin_error_user_not_found' => 'User not found.',
            'admin_error_galaxy_not_found' => 'Galaxy not found.',
            'admin_error_delete_confirm_mismatch' => 'Confirmation does not match. Type the exact name to confirm deletion.',
            'admin_setup_perms_heading' => 'Next step (host hardening):',
            'admin_setup_perms_intro' => 'config.php is now set to mode',
            'admin_setup_perms_advice' => 'Run sudo php bin/setup-host.php from the site root to apply canonical host configuration (nginx snippet, logrotate rule, and 0640 owner=operator on config.php).',

            // C5: admin/setup.php (post-DB)
            'admin_setup_website_info_subtitle' => 'Configure your website information',
            'admin_setup_db_tables_created' => '✓ Database tables created successfully!',
            'admin_setup_website_name_label' => 'Website Name',
            'admin_setup_website_name_help' => 'The name of your website or project. Default: Telaris',
            'admin_setup_tagline_label' => 'Tagline',
            'admin_setup_tagline_help' => 'A short description or tagline. Default: Weaving memory',
            'admin_setup_website_info_footer_help' => 'These values are used for the default galaxy and project info. They can be changed later in Admin → Global Settings and Galaxies.',
            'admin_setup_website_info_continue' => 'Continue',
            'admin_setup_schema_details_heading' => 'Database Schema Creation Details',
            'admin_setup_schema_db_created' => 'Database <strong>%s</strong> created successfully',
            'admin_setup_schema_db_exists' => 'Database <strong>%s</strong> already exists',
            'admin_setup_schema_tables_created_one' => 'Tables Created (%d):',
            'admin_setup_schema_tables_created_many' => 'Tables Created (%d):',
            'admin_setup_schema_tables_existed_one' => 'Tables Already Existed (%d):',
            'admin_setup_schema_tables_existed_many' => 'Tables Already Existed (%d):',
            'admin_setup_schema_no_tables' => 'No tables were created or skipped.',
            'admin_setup_schema_api_key_heading' => '✓ Default API Key Generated',
            'admin_setup_schema_api_key_help' => 'A default API key has been automatically generated and is being used by the application. API keys can be managed in the API Key Management page.',
            'admin_setup_admin_user_heading' => 'Create Admin User',
            'admin_setup_admin_user_intro' => 'No admin user exists yet. Create one to access the admin console.',
            'admin_setup_first_name_label' => 'First Name *',
            'admin_setup_last_name_label' => 'Last Name *',
            'admin_setup_email_label' => 'Email *',
            'admin_setup_email_help' => 'This will be the login email.',
            'admin_setup_password_label' => 'Password *',
            'admin_setup_password_help' => 'Minimum 8 characters',
            'admin_setup_confirm_password_label' => 'Confirm Password *',
            'admin_setup_create_admin_btn' => 'Create Admin User',
            'admin_setup_admin_user_created' => '✓ Admin user created successfully!',
            'admin_setup_admin_user_can_login' => 'You can now login at the %s.',
            'admin_setup_admin_user_login_link' => 'login page',
            'admin_setup_config_created_flash' => '✓ Configuration file created successfully!',
            'admin_setup_complete_with_schema' => 'Setup complete. Database schema created and project information initialized.',
            'admin_setup_complete_no_schema' => 'Setup complete. Project information initialized.',
            'admin_setup_db_error_prefix' => 'Database Error:',
            'admin_setup_error_prefix' => 'Error:',
            'admin_setup_status_heading' => 'Setup Status:',
            'admin_setup_config_file_label' => 'Configuration file:',
            'admin_setup_config_file_created' => '✓ Created',
            'admin_setup_config_file_missing' => '✗ Missing',
            'admin_setup_db_connection_label' => 'Database connection:',
            'admin_setup_db_connection_connected' => '✓ Connected',
            'admin_setup_db_connection_failed' => '✗ Failed',
            'admin_setup_project_info_label' => 'Project info:',
            'admin_setup_project_info_initialized' => '✓ Initialized',
            'admin_setup_project_info_not_initialized' => '✗ Not initialized',
            'admin_setup_link_go_to_telaris' => 'Go to Telaris →',
            'admin_setup_link_admin_console' => 'Admin Console',
            'admin_setup_link_reconfigure_db' => 'Reconfigure Database',
            'admin_setup_validation_all_fields_required' => 'All fields are required.',
            'admin_setup_validation_passwords_mismatch' => 'Passwords do not match.',
            'admin_setup_validation_password_too_short' => 'Password must be at least 8 characters long.',
            'admin_setup_validation_db_unavailable' => 'Database connection not available.',

            // C5b: utils/login.php + utils/forgot.php + utils/reset.php
            'auth_login_page_title' => 'Login - Telaris',
            'auth_login_heading' => 'Telaris Login',
            'auth_login_subtitle' => 'Access the constellation workspace',
            'auth_email_label' => 'Email',
            'auth_password_label' => 'Password',
            'auth_login_submit' => 'Sign In',
            'auth_login_forgot_link' => 'Forgot your password?',
            'auth_login_back_link' => '← Back to Constellation',
            'auth_error_invalid_request' => 'Invalid request. Please reload the page and try again.',
            'auth_error_throttled' => 'Too many attempts. Please try again later.',
            'auth_login_error_required' => 'Email and password are required',
            'auth_login_error_invalid' => 'Invalid email or password. Only editor and admin users can login here.',
            'auth_forgot_page_title' => 'Reset Password - Telaris',
            'auth_forgot_heading' => 'Forgot password',
            'auth_forgot_subtitle' => 'We will email you a one-time link to set a new password.',
            'auth_forgot_generic_notice' => 'If an account exists for that email, a password reset link has been sent.',
            'auth_forgot_error_invalid_email' => 'Please enter a valid email address.',
            'auth_forgot_submit' => 'Send reset link',
            'auth_forgot_back_link' => '← Back to login',
            'auth_email_subject' => 'Reset your %s password',
            'auth_email_greeting_named' => 'Hi %s,',
            'auth_email_greeting_anon' => 'Hi,',
            'auth_email_intro' => 'We received a request to reset your password. Click the link below to set a new one:',
            'auth_email_expiry' => 'This link expires in 24 hours and can only be used once. If you did not request a reset, you can safely ignore this email; your password will not change.',
            'auth_email_text_intro' => "We received a request to reset your password.\n\nReset link (24h, single-use):\n",
            'auth_email_text_outro' => "\n\nIf you did not request a reset, ignore this email.",
            'auth_reset_page_title' => 'Set new password - Telaris',
            'auth_reset_heading' => 'Set new password',
            'auth_reset_success_message' => 'Password updated. You can now log in with your new password.',
            'auth_reset_btn_go_to_login' => 'Go to login',
            'auth_reset_invalid_token_message' => 'This reset link is invalid or has expired. Please request a new one.',
            'auth_reset_btn_request_new_link' => 'Request a new link',
            'auth_reset_intro_html' => 'Setting a new password for <strong>%s</strong>.',
            'auth_reset_new_password_label' => 'New password',
            'auth_reset_password_help' => 'At least 8 characters.',
            'auth_reset_confirm_password_label' => 'Confirm new password',
            'auth_reset_submit' => 'Update password',
            'auth_reset_error_password_too_short' => 'Password must be at least 8 characters.',
            'auth_reset_error_password_mismatch' => 'Passwords do not match.',

            // C7a: inc/partials/galaxy-edit-modal.php
            'gem_heading' => 'Edit Galaxy',
            'gem_name_label' => 'Name *',
            'gem_name_duplicate_error' => 'This name is already in use.',
            'gem_tagline_label' => 'Tagline',
            'gem_slug_label' => 'URL Slug',
            'gem_slug_placeholder' => 'e.g. archive',
            'gem_slug_duplicate_error' => 'This slug is already in use.',
            'gem_slug_help' => 'Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.',
            'gem_theme_label' => 'Visual Theme',
            'gem_theme_cosmic' => 'Cosmic (Stars, Planets, Rockets)',
            'gem_theme_simple' => 'Simple (Colored Spheres)',
            'gem_theme_abstract' => 'Abstract (Geometric GIF Icons)',
            'gem_theme_rectangles' => 'Rectangles (Custom Rectangle Icons)',
            'gem_theme_stripes' => 'Stripes (Custom Stripe Icons)',
            'gem_theme_tech' => 'Tech (Circuit Board Icons)',
            'gem_tags_label' => 'Tags',
            'gem_tags_placeholder' => 'Add tag...',
            'gem_tags_help' => 'Visitors can browse the union of every galaxy carrying a tag at <code>/tag/&lt;tag&gt;</code>. Type to add; press Enter or comma. Suggestions surface tags already used in this galaxy and in sibling galaxies sharing your <code>[XX]</code> prefix.',
            'gem_bulk_actions_label' => 'Bulk wormhole actions',
            'gem_bulk_actions_help' => 'Apply to every wormhole in this galaxy at once. Per-wormhole toggles still override afterward.',
            'gem_bulk_use_images_btn' => 'Use images as icons (all wormholes)',
            'gem_bulk_revert_icons_btn' => 'Revert all to theme icons',
            'gem_keyword_chips_label' => 'Keyword chips',
            'gem_keyword_chips_help' => "Show the most-used keywords as filter chips at the top of the galaxy. Click a chip to dim wormholes that don't match.",
            'gem_related_label' => 'Related wormholes',
            'gem_related_help' => "When a wormhole's info card is open, dim unrelated wormholes in the scene and show up to 5 related ones (sharing keywords) as click-to-jump chips at the bottom of the card. Random sample each time.",
            'gem_2d_view_label' => '2D view switch',
            'gem_2d_view_help' => 'Show a top-center "3D / 2D" toggle so visitors can flip from the 3D scene to a flat grid of wormhole chips. The preference persists in the browser.',
            'gem_idle_spotlight_label' => 'Idle spotlight',
            'gem_idle_spotlight_help' => 'After a period of inactivity, fly the camera to one random wormhole and open its info card. Closes when media ends or after the dwell timer.',
            'gem_pick_from_label' => 'Pick from',
            'gem_idle_pick_all' => 'All wormholes',
            'gem_idle_pick_accentuated' => 'Only accentuated wormholes',
            'gem_idle_trigger_label' => 'Trigger after (seconds idle)',
            'gem_autotour_label' => 'Auto-tour',
            'gem_autotour_preview_btn' => 'Preview tour',
            'gem_autotour_preview_title' => 'Save first, then preview the tour in a new tab',
            'gem_autotour_help' => 'Automatically navigate through nodes, opening each card and playing media. Desktop and iPad only.',
            'gem_start_mode_label' => 'Start Mode',
            'gem_start_mode_manual' => 'Manual. Starts when a Play button is clicked.',
            'gem_start_mode_idle' => 'Idle. Starts after a period of inactivity.',
            'gem_start_mode_immediate' => 'Immediate. Starts a few seconds after the galaxy loads.',
            'gem_idle_threshold_label' => 'Idle threshold (seconds)',
            'gem_immediate_audio_warning' => 'This galaxy contains audio nodes. Browsers block autoplay-with-sound until there is some interaction with the page, so the first audio in an immediate-start tour may stay silent or stall.',
            'gem_which_nodes_label' => 'Which nodes to tour',
            'gem_nodes_all' => 'All nodes (random order each run)',
            'gem_nodes_accentuated' => 'Only accentuated nodes',
            'gem_nodes_random_n' => 'A random sample of N nodes',
            'gem_nodes_tagged' => 'Nodes tagged with one of these keywords',
            'gem_random_count_label' => 'How many nodes per tour',
            'gem_keywords_label' => 'Keywords (any match)',
            'gem_keywords_help' => 'Nodes matching any of the selected keywords are shown to visitors.',
            'gem_dwell_label' => 'Pause on nodes without media (seconds)',
            'gem_loop_label' => 'Loop the tour when it finishes',
            'gem_submit_btn' => 'Update Galaxy',
            'gem_cancel_btn' => 'Cancel',
            'gem_close_btn' => 'close',

            // C7b: API error envelope titles (RFC 9457). Code format <http-status>.<3-digit-subcode>.
            'api_error_400_001' => 'Invalid JSON: %s',
            'api_error_400_002' => 'A required field is missing.',
            'api_error_400_003' => 'Invalid URL: only http and https URLs are allowed.',
            'api_error_400_004' => 'Invalid cluster key format.',
            'api_error_400_005' => 'The galaxies parameter is incompatible with page/id.',
            'api_error_400_006' => 'Request body is empty.',
            'api_error_400_007' => 'Node name is required.',
            'api_error_400_008' => 'Node name cannot be empty.',
            'api_error_400_009' => 'Node id is required.',
            'api_error_400_010' => 'A constellation id is required.',
            'api_error_400_011' => 'A constellation name is required.',
            'api_error_400_012' => 'A keyword is required.',
            'api_error_400_013' => 'A keyword id is required.',
            'api_error_400_014' => 'The keyword does not belong to the specified constellation.',
            'api_error_400_015' => 'A galaxy id is required.',
            'api_error_400_016' => 'move_keyword requires keyword_id, x, y.',
            'api_error_400_017' => 'create_relation requires keyword_a_id and keyword_b_id.',
            'api_error_400_018' => 'Self-loop relations are not allowed.',
            'api_error_400_019' => 'Both keywords must belong to the same galaxy.',
            'api_error_400_020' => 'update_relation requires relation_id.',
            'api_error_400_021' => 'delete_relation requires relation_id.',
            'api_error_400_022' => 'reset_keyword requires keyword_id.',
            'api_error_400_023' => 'reset_galaxy requires galaxy_id.',
            'api_error_400_024' => 'delete_keyword requires keyword_id.',
            'api_error_400_025' => 'rename_keyword requires keyword_id.',
            'api_error_400_026' => 'rename_keyword requires a non-empty new name.',
            'api_error_400_027' => 'Keyword name is too long (max 100 characters).',
            'api_error_400_028' => 'merge_keywords requires source_id and target_id.',
            'api_error_400_029' => 'Cannot merge a keyword into itself.',
            'api_error_400_030' => 'Unknown action: %s.',
            'api_error_400_031' => 'constellation_id, keyword_id, and op (delete|move|count) are required.',
            'api_error_400_032' => 'target_constellation_id is required for move.',
            'api_error_400_033' => 'Missing or invalid bridge name.',
            'api_error_400_034' => "Bridge '%s' is not enabled on this instance.",
            'api_error_400_035' => 'Invalid validation type.',
            'api_error_400_036' => 'File upload failed (code %d).',
            'api_error_400_037' => 'Missing or invalid phase parameter.',
            'api_error_400_038' => 'Confirmation required.',
            'api_error_400_039' => 'Missing or invalid id.',
            'api_error_400_040' => 'Confirmation phrase is missing or wrong (must be RESTORE).',
            'api_error_400_041' => 'Encoding error.',
            'api_error_400_042' => 'Failed to encode the response.',
            'api_error_400_043' => 'Select at least galaxies or users to back up.',
            'api_error_400_044' => 'Invalid URL format. Expected a full URL like https://hostname/api/v2.',
            'api_error_400_045' => 'No galaxias specified.',
            'api_error_400_046' => 'Refusing to fetch from this upstream: %s',

            'api_error_401_001' => 'API key is missing. Provide it via the X-API-Key header, the Authorization: Bearer header, or the api_key query parameter.',
            'api_error_401_002' => 'Invalid API key.',

            'api_error_403_001' => 'Write operations require an authenticated session. Please log in.',
            'api_error_403_002' => 'Insufficient permissions for write operations.',
            'api_error_403_003' => 'Invalid security token. Reload the page and try again.',
            'api_error_403_004' => 'No edit access to this galaxy.',
            'api_error_403_005' => 'Access denied.',
            'api_error_403_006' => 'Only the author or an admin can edit this relation.',
            'api_error_403_007' => 'Only the author or an admin can delete this relation.',
            'api_error_403_008' => 'User existence checks are restricted to administrative sessions.',

            'api_error_404_001' => 'Node not found.',
            'api_error_404_002' => 'Galaxy not found.',
            'api_error_404_003' => 'Keyword not found.',
            'api_error_404_004' => 'Relation not found.',
            'api_error_404_005' => 'Relation references a missing keyword.',
            'api_error_404_006' => 'Cluster not found.',
            'api_error_404_007' => 'Source node not found.',
            'api_error_404_008' => 'The target galaxy does not exist.',
            'api_error_404_009' => 'API key not found.',
            'api_error_404_010' => "Bridge '%s' handler file is missing.",
            'api_error_404_011' => "Bridge '%s' has no request handler.",
            'api_error_404_012' => 'Unknown or expired upload. Please re-select the file.',
            'api_error_404_013' => 'Uploaded file is missing. Please re-select it.',
            'api_error_404_014' => 'Snapshot not found.',

            'api_error_405_001' => 'Method not allowed.',

            'api_error_409_001' => 'A keyword with that name already exists.',
            'api_error_409_002' => 'A relation between these keywords already exists.',

            'api_error_500_001' => 'Internal server error.',
            'api_error_500_002' => 'Database error.',
            'api_error_500_003' => 'Failed to create the upload directory. Check server permissions.',
            'api_error_500_004' => 'Failed to save the uploaded file.',
            'api_error_500_005' => 'Failed to save the uploaded image.',
            'api_error_500_006' => 'Failed to save the uploaded icon.',
            'api_error_500_007' => 'Failed to save the uploaded audio.',
            'api_error_500_008' => 'Failed to save the uploaded video.',
            'api_error_500_009' => 'Failed to save the uploaded PDF.',
            'api_error_500_010' => 'Could not extract a frame from the uploaded video.',
            'api_error_500_011' => 'The file does not look like a valid PDF.',
            'api_error_500_012' => 'Failed to create the node: could not retrieve its id.',
            'api_error_500_013' => 'Failed to encode the animation data.',
            'api_error_500_014' => 'Failed to encode the JSON data.',
            'api_error_500_015' => 'Could not save the uploaded backup file.',
            'api_error_502_001' => 'Failed to reach the upstream Mocambos API at %s.',

            // C7c: galaxy-update result messages.
            'galaxy_update_missing_id' => 'Missing galaxy id.',
            'galaxy_update_not_authorized' => 'Not authorized.',
            'galaxy_update_no_access' => 'You do not have access to this galaxy.',
            'galaxy_update_name_required' => 'Galaxy name is required.',
            'galaxy_update_duplicate_name' => 'A galaxy with the name "%s" already exists.',
            'galaxy_update_duplicate_slug' => 'A galaxy with the slug "%s" already exists.',
            'galaxy_update_duplicate_both' => 'A galaxy with the name "%s" and slug "%s" already exists.',
            'galaxy_update_success' => 'Galaxy updated successfully.',

            // C7d: Mocambos bridge admin UI (chrome + JS strings).
            'mocambos_btn_import_from' => 'Import from Mocambos',
            'mocambos_modal_heading' => 'Import from Mocambos',
            'mocambos_label_api_url' => 'Mocambos API URL',
            'mocambos_help_api_url' => 'The base API URL of the Mocambos instance (e.g. https://hostname/api/v2). You can also paste the docs URL; /docs will be stripped automatically.',
            'mocambos_btn_connect' => 'Connect',
            'mocambos_text_loading' => 'Fetching available galaxias...',
            'mocambos_btn_back' => 'Back',
            'mocambos_text_connected_to' => 'Connected to:',
            'mocambos_text_select_intro' => 'Select galaxias to import. Each will become a new galaxy. Already-imported ones will be refreshed.',
            'mocambos_text_starting_import' => 'Starting import...',
            'mocambos_text_refresh_intro' => 'This will sync wormholes with the remote Mocambos source (incremental update).',
            'mocambos_text_refresh_confirm_instruction' => 'To confirm, type the galaxy name <strong id="refresh-confirm-name" class="text-gray-900">%s</strong> below:',
            'mocambos_placeholder_refresh_confirm' => 'Type galaxy name to confirm',
            'mocambos_btn_refresh' => 'Refresh',
            'mocambos_btn_cancel' => 'Cancel',
            'mocambos_btn_import_selected' => 'Import Selected',
            'mocambos_btn_close' => 'Close',
            'mocambos_btn_modal_backdrop_close' => 'close',
            'mocambos_js_validation_report_title' => 'Mocambos API Validation Report',
            'mocambos_js_validation_url_prefix' => 'URL:',
            'mocambos_js_validation_date_prefix' => 'Date:',
            'mocambos_js_validating_api' => 'Validating API...',
            'mocambos_js_enter_url' => 'Please enter a Mocambos API URL.',
            'mocambos_js_validation_failed_intro' => 'API validation failed. The following issues were found:',
            'mocambos_js_copied' => 'Copied!',
            'mocambos_js_copy_report' => 'Copy report to clipboard',
            'mocambos_js_could_not_validate' => 'Could not validate: %s',
            'mocambos_js_network_error' => 'Network error',
            'mocambos_js_fetch_failed' => 'Failed to fetch galaxias',
            'mocambos_js_no_galaxias' => 'No galaxias found at this URL.',
            'mocambos_js_badge_imported' => 'Imported',
            'mocambos_js_connect_failed' => 'Failed to connect to Mocambos API',
            'mocambos_js_select_at_least_one' => 'Please select at least one galaxia to import.',
            'mocambos_js_confirm_refresh_intro' => 'The following galaxies will be refreshed, replacing all current content including any edits:',
            'mocambos_js_confirm_refresh_continue' => 'Continue?',
            'mocambos_js_import_failed_generic' => 'Import failed',
            'mocambos_js_import_complete_status' => 'Import complete',
            'mocambos_js_status_label_new' => 'New',
            'mocambos_js_status_label_refreshed' => 'Refreshed',
            'mocambos_js_items_count' => '%d of %d items',
            'mocambos_js_completed_success' => 'Import completed successfully.',
            'mocambos_js_completed_errors' => 'Import completed with some errors.',
            'mocambos_js_refresh_complete_log' => 'Refresh complete.',
            'mocambos_js_refresh_complete_status' => 'Refresh complete',
            'mocambos_js_refresh_failed_status' => 'Refresh failed',
            'mocambos_js_missing_source' => 'Missing import source info for this galaxy.',
            'mocambos_js_refreshing' => 'Refreshing "%s"...',
            'mocambos_js_error_prefix' => 'Error: %s',
            'mocambos_js_unknown_error' => 'Unknown error',

            // C7e: handler.php strings — HTTP streamMsg, validation checks, CLI output.
            // streamMsg (live import log).
            'mocambos_h_resolved_mucua_names' => 'Resolved %d mucua names.',
            'mocambos_h_fetching_media' => 'Fetching media items from the Mocambos API...',
            'mocambos_h_total_items_fetched' => 'Total items fetched: %d.',
            'mocambos_h_processing_galaxia' => 'Processing galaxia: %s (%d items).',
            'mocambos_h_import_complete' => 'Import complete.',
            'mocambos_h_full_refresh_clearing' => 'Full refresh; clearing existing nodes...',
            'mocambos_h_re_importing_diff' => 'Re-importing; computing diff...',
            'mocambos_h_backfilled_slugs' => 'Backfilled %d import slugs.',
            'mocambos_h_diff_summary' => 'Diff: %d new, %d modified, %d deleted, %d unchanged.',
            'mocambos_h_deleting_removed' => 'Deleting %d removed items...',
            'mocambos_h_updating_modified' => 'Updating %d modified items...',
            'mocambos_h_created_constellation' => 'Created constellation: %s (id %d).',
            'mocambos_h_adding_new_nodes' => 'Adding %d new nodes...',
            'mocambos_h_phase1_creating' => 'Phase 1: creating %d nodes...',
            'mocambos_h_nodes_created_progress' => '  %d/%d nodes created.',
            'mocambos_h_phase1_complete' => 'Phase 1 complete: %d/%d nodes created.',
            'mocambos_h_phase2_downloading' => 'Phase 2: downloading media files...',
            'mocambos_h_downloading_image' => '(%s) Downloading image: %s',
            'mocambos_h_downloading_video' => '(%s) Downloading video: %s',
            'mocambos_h_downloading_audio' => '(%s) Downloading audio: %s',
            'mocambos_h_phase2_complete' => 'Phase 2 complete: %d media files downloaded.',
            'mocambos_h_phase2_complete_with_errors' => 'Phase 2 complete: %d media files downloaded (%d failed).',
            'mocambos_h_galaxia_done' => 'Galaxia %s done: %d/%d items imported.',
            'mocambos_h_galaxia_done_with_errors' => 'Galaxia %s done: %d/%d items imported (%d errors).',
            'mocambos_h_concurrent_import' => 'Concurrent import already in progress for galaxy %s; try again later.',
            'mocambos_h_failed_to_create_node' => 'Failed to create node: %s (%s).',
            'mocambos_h_media_downloads_failed' => '%d media downloads failed.',
            // Validation check details (validation report).
            'mocambos_h_check_connection_failed' => 'Connection failed; could not reach the server.',
            'mocambos_h_check_galaxia_http_fail' => 'HTTP %d; expected 200. This endpoint must return a JSON array of galaxia objects.',
            'mocambos_h_check_galaxia_not_array' => 'Response is not a valid JSON array. Received: %s',
            'mocambos_h_check_galaxia_empty' => 'Returned an empty array; no galaxias available to import.',
            'mocambos_h_check_galaxia_missing_fields' => 'Galaxia objects are missing required fields: %s. Each galaxia must have: name, slug, default_mucua.',
            'mocambos_h_check_galaxia_ok' => 'Found %d galaxia(s). Structure looks correct.',
            'mocambos_h_check_mucua_http_fail' => 'HTTP %d; expected 200. This endpoint must return a JSON array of mucua objects.',
            'mocambos_h_check_mucua_not_array' => 'Response is not a valid JSON array. Received: %s',
            'mocambos_h_check_mucua_empty' => 'Returned an empty array; no mucuas found. Media downloads may not work.',
            'mocambos_h_check_mucua_missing_fields' => 'Mucua objects are missing required fields: %s. Each mucua must have: smid, slug.',
            'mocambos_h_check_mucua_ok' => 'Found %d mucua(s). Structure looks correct.',
            'mocambos_h_check_acervo_http_fail' => 'HTTP %d; expected 200. This endpoint must return a paginated JSON object with an "items" array.',
            'mocambos_h_check_acervo_no_items' => 'Response missing "items" key. Expected {item_count, page_count, items: [...]}. Received: %s',
            'mocambos_h_check_acervo_ok' => 'Returned %d media item(s) total. Structure looks correct.',
            'mocambos_h_check_blog_http_fail' => 'HTTP %d; expected 200. Blog articles will not be imported.',
            'mocambos_h_check_blog_no_items' => 'Response missing "items" key. Blog articles will not be imported.',
            'mocambos_h_check_blog_ok' => 'Returned %d blog article(s) total. Structure looks correct.',
            // CLI output.
            'mocambos_h_cli_header' => 'Mocambos Import',
            'mocambos_h_cli_prompt_api_base' => 'Mocambos API base URL',
            'mocambos_h_cli_err_api_base_required' => 'Error: --api-base is required.',
            'mocambos_h_cli_err_usage' => 'Usage: php admin/cli/import_bridge.php mocambos --api-base=URL --galaxia=SLUG',
            'mocambos_h_cli_connecting' => 'Connecting to %s...',
            'mocambos_h_cli_fetch_galaxias_failed' => 'Failed to fetch galaxia list from %s.',
            'mocambos_h_cli_found_counts' => 'Found %d galaxia(s), %d mucua(s).',
            'mocambos_h_cli_available_galaxias_at' => 'Available galaxias at %s:',
            'mocambos_h_cli_col_slug' => 'SLUG',
            'mocambos_h_cli_col_name' => 'NAME',
            'mocambos_h_cli_col_smid' => 'SMID',
            'mocambos_h_cli_available_galaxias' => 'Available galaxias:',
            'mocambos_h_cli_already_imported' => '(already imported)',
            'mocambos_h_cli_prompt_select_galaxia' => 'Select galaxia number (or type slug)',
            'mocambos_h_cli_no_galaxia_selected' => 'No galaxia selected.',
            'mocambos_h_cli_err_galaxia_required' => 'Error: --galaxia=SLUG is required.',
            'mocambos_h_cli_matched_slug' => 'Matched galaxia slug: %s.',
            'mocambos_h_cli_galaxia_not_found' => 'Galaxia "%s" not found. Use --list to see available galaxias.',
            'mocambos_h_cli_prompt_download_media' => 'Download media files? (slower but includes images/audio/video)',
            'mocambos_h_cli_prompt_limit' => 'Limit number of items? (enter number, or press Enter for all)',
            'mocambos_h_cli_summary_galaxia' => 'Galaxia:',
            'mocambos_h_cli_summary_api' => 'API:',
            'mocambos_h_cli_summary_media' => 'Media:',
            'mocambos_h_cli_summary_limit' => 'Limit:',
            'mocambos_h_cli_value_skip' => 'skip',
            'mocambos_h_cli_value_download' => 'download',
            'mocambos_h_cli_value_all' => 'all',
            'mocambos_h_cli_prompt_proceed' => 'Proceed with import?',
            'mocambos_h_cli_aborted' => 'Aborted.',
            'mocambos_h_cli_galaxia_info' => 'Galaxia: %s (slug=%s, smid=%s).',
            'mocambos_h_cli_total_items' => 'Total items for this galaxia: %d.',
            'mocambos_h_cli_limited_to' => 'Limited to %d items (--limit).',
            'mocambos_h_cli_constellation_label' => 'Constellation: %s (id %d).',
            'mocambos_h_cli_imported_summary' => 'Imported: %d/%d items in %ss.',
            'mocambos_h_cli_errors_count' => 'Errors: %d.',
            'mocambos_h_cli_media_skipped' => 'Media downloads skipped (--no-media).',
            'mocambos_h_cli_constellation_new' => 'New constellation created.',
            'mocambos_h_cli_constellation_existing' => 'Existing constellation re-imported.',

            // C7f: edit/keyword-canvas.php (PHP chrome).
            'editor_kc_page_title' => 'Keyword canvas',
            'editor_kc_err_missing_galaxy_id' => 'Missing <code>?galaxy_id=N</code>.',
            'editor_kc_err_galaxy_not_found' => 'Galaxy not found.',
            'editor_kc_err_clusters_no_canvas' => 'Clusters have no native keywords; the canvas only applies to galaxies. Open the canvas on a member galaxy instead.',
            'editor_kc_err_no_edit_access' => 'You do not have edit access to this galaxy.',
            'editor_kc_back_link' => '← Back',
            'editor_kc_page_title_template' => 'Keyword canvas; %s',
            'editor_kc_empty_state' => 'No keywords in this galaxy yet. Add some wormholes with keywords first.',
            'editor_kc_mobile_block' => 'Open the keyword canvas on a desktop browser to author keyword relationships. The interactions need a larger screen and a mouse or trackpad.',
            'editor_kc_note_modal_title' => 'Relation note',
            'editor_kc_note_modal_intro' => "Optional editorial framing; what does this relation carry that a shared keyword can't say alone?",
            'editor_kc_note_modal_cancel' => 'Cancel',
            'editor_kc_note_modal_save' => 'Save',
            'editor_kc_keyword_modal_title' => 'Keyword',
            'editor_kc_keyword_modal_new_name_label' => 'New name',
            'editor_kc_keyword_modal_cancel' => 'Cancel',
            'editor_kc_keyword_modal_delete' => 'Delete',
            'editor_kc_keyword_modal_rename' => 'Rename',
            'editor_kc_conflict_modal_title' => 'Keyword already exists',
            'editor_kc_conflict_modal_body_suffix' => 'already exists in this galaxy.',
            'editor_kc_conflict_modal_options_intro' => "<strong>Change name</strong>: keep this keyword separate and pick a different name.<br><strong>Merge</strong>: fold this keyword into the existing one; every wormhole tagged with it, every line on the canvas, gets repointed at the existing keyword. This one will be deleted. No undo.",
            'editor_kc_conflict_modal_change' => 'Change name',
            'editor_kc_conflict_modal_merge' => 'Merge',
            'editor_kc_line_modal_title' => 'Relation',
            'editor_kc_line_modal_noauth' => 'Only the original author or an admin can edit or delete this relation.',
            'editor_kc_line_modal_close' => 'Close',
            'editor_kc_line_modal_edit' => 'Edit note',
            'editor_kc_line_modal_delete' => 'Delete',
            'editor_kc_backdrop_close' => 'close',
            'editor_kc_help_button' => 'Help',
            'editor_kc_help_title' => 'Quick guide',
            'editor_kc_help_purpose' => 'Use this view to map how keywords in this galaxy relate to each other. The closer they are, the stronger their relationship. Drag chips to set their proximity, and draw lines between them to record specific semantic connections.',
            'editor_kc_help_intro' => 'How to use it:',
            'editor_kc_help_move_label' => 'Move a keyword',
            'editor_kc_help_move_body' => 'Drag a chip to reposition it.',
            'editor_kc_help_connect_label' => 'Connect two keywords',
            'editor_kc_help_connect_body' => 'Click an anchor dot on one chip, then click an anchor on another. Or drag from anchor to anchor.',
            'editor_kc_help_edit_label' => 'Edit or delete a line',
            'editor_kc_help_edit_body' => 'Click an existing line to open it.',
            'editor_kc_help_pan_label' => 'Pan the view',
            'editor_kc_help_pan_body' => 'Hold Space and drag, or middle-click and drag.',
            'editor_kc_help_zoom_label' => 'Zoom',
            'editor_kc_help_zoom_body' => 'Use the mouse wheel. Zooms toward the cursor.',
            'editor_kc_help_cancel_label' => 'Cancel',
            'editor_kc_help_cancel_body' => 'Press Esc while drawing a line to cancel.',
            'editor_kc_help_close' => 'Close',

            // C7h: inc/main-view.php nginx-config warning banner (operator-only,
            // fires when the versioned-asset rule is missing from the vhost).
            'visitor_nginx_warning_heading' => 'Telaris configuration: nginx versioned-asset rule not installed',
            'visitor_nginx_warning_intro' => 'JavaScript modules will not be served. Add this block to the server\'s nginx vhost (replacing the docroot if different), then run %s.',
            'visitor_nginx_warning_reload' => '<code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>',
            'visitor_nginx_warning_footer' => 'This banner disappears automatically once the rule serves %s with HTTP 200.',
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
            'editor_error_no_api_key' => '⚠️ Error: no se encontró ninguna clave de API activa. Contacta a la administración del sitio.',
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
            'editor_error_api_key_missing_fetch' => 'Error: falta la clave de API. Contacta a la administración del sitio.',
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
            'admin_tab_pluriverse' => 'Pluriverse',
            'admin_tab_api_keys' => 'Llaves de API',
            'admin_tab_php_info' => 'Información de PHP',
            'admin_heading_users' => 'Usuarias',
            'admin_btn_new_user' => 'Nueva cuenta',
            'admin_btn_bulk_import' => 'Importación masiva',
            'admin_label_search' => 'Buscar:',
            'admin_placeholder_search_users' => 'Buscar cuentas...',
            'admin_msg_no_users' => 'No se encontraron cuentas.',
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
            'admin_confirm_delete_user' => '¿Seguro que quieres eliminar la cuenta "%s"? Esta acción no se puede deshacer.',
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
            'admin_label_instance_name' => 'Nombre',
            'admin_help_instance_name' => 'Nombre público de esta instancia. Se muestra en el lado visitante y se usa como etiqueta en el directorio del Pluriverse al solicitar publicación. Si queda en blanco, se usa la primera etiqueta del nombre de host.',
            'admin_label_pdf_max' => 'Tamaño máximo de PDF (MB)',
            'admin_help_pdf_max' => "PDF más grande que puede contener un agujero de gusano. Por defecto 25 MB. Al subir archivos más grandes aparece el error 'El archivo supera el tamaño máximo permitido'.",
            'admin_btn_save_settings' => 'Guardar ajustes',
            // Pluriverse tab.
            'admin_pluriverse_heading' => 'Únete al Pluriverse',
            'admin_pluriverse_subheading' => 'Federa esta instancia en el Pluriverse para que aparezca en el directorio público en www.telaris.ca. La solicitud lleva tu URL, nombre, contacto de la operación y galaxias elegidas, firmada por la pluriverse.key de esta instancia.',
            'admin_pluriverse_status_heading' => 'Estado de la membresía',
            'admin_pluriverse_status_status' => 'Estado',
            'admin_pluriverse_status_submitted' => 'Enviada',
            'admin_pluriverse_status_name' => 'Nombre',
            'admin_pluriverse_status_email' => 'Correo de la operación',
            'admin_pluriverse_status_fingerprint' => 'Huella de clave pública guardada',
            'admin_pluriverse_status_help' => 'Revisa tu correo de operación para el enlace de verificación. Tanto el enlace como la solicitud pendiente caducan 24 horas después del envío. La administración del Pluriverse revisa la solicitud después de la verificación y avisa cuando la instancia esté publicada.',
            'admin_pluriverse_status_expired_heading' => 'Solicitud de ingreso caducada',
            'admin_pluriverse_status_expired_body' => 'El enlace de verificación de tu última solicitud de ingreso no se abrió en 24 horas, así que la solicitud caducó. Puedes enviar una nueva con el botón de abajo; recibirás un nuevo correo de verificación en tu dirección de operación.',
            'admin_pluriverse_btn_rejoin' => 'Volver a unirme a la Pluriverse',
            'admin_pluriverse_field_url_label' => 'URL de la instancia',
            'admin_pluriverse_field_url_help' => 'URL https canónica de esta instancia. El nombre de host se deriva de aquí.',
            'admin_pluriverse_field_name_label' => 'Nombre',
            'admin_pluriverse_field_name_help' => 'Nombre público corto para esta instancia, único en todo el Pluriverse. Si está tomado, se te pedirá elegir otro.',
            'admin_pluriverse_field_email_label' => 'Correo de la operación',
            'admin_pluriverse_field_email_help' => 'Destino del enlace mágico. Cifrado en reposo en el Pluriverse. Edítalo si prefieres una dirección distinta a la de tu cuenta de administración.',
            'admin_pluriverse_field_framing_label' => 'Encuadre editorial',
            'admin_pluriverse_field_framing_help' => 'Una o tres frases. ¿Para qué sirve esta instancia? Opcional.',
            'admin_pluriverse_field_galaxies_label' => 'Galaxias publicables',
            'admin_pluriverse_field_galaxies_summary' => '%d galaxias de esta instancia se publicarán. Las nuevas galaxias se añaden automáticamente conforme las crees.',
            'admin_pluriverse_field_galaxies_empty' => 'Aún no hay galaxias. La solicitud registra esta instancia ahora; las nuevas galaxias se recogen automáticamente conforme las crees.',
            'admin_pluriverse_field_galaxies_disclosure' => 'Ver la lista',
            'admin_pluriverse_field_contacts_label' => 'Contactos secundarios',
            'admin_pluriverse_field_contacts_help' => 'Canales alternativos opcionales (Matrix, XMPP, etc.). Hasta ocho.',
            'admin_pluriverse_btn_add_contact' => 'Agregar otro',
            'admin_pluriverse_contact_service_placeholder' => 'servicio',
            'admin_pluriverse_contact_handle_placeholder' => 'identificador / dirección',
            'admin_pluriverse_btn_submit' => 'Unirse al Pluriverse',
            'admin_pluriverse_submit_help' => 'Esta instancia firmará la solicitud con su pluriverse.key (Ed25519) y la enviará a www.telaris.ca. El Pluriverse enviará un enlace de verificación al correo de la operación.',
            'admin_pluriverse_link_change_name' => '(cambiar en Ajustes globales)',
            'admin_heading_download_backup' => 'Descargar un respaldo',
            'admin_help_download_backup' => 'Crea un archivo de respaldo portable con galaxias y/o cuentas. La opción por defecto produce un respaldo completo con los archivos multimedia incrustados.',
            'admin_label_galaxies' => 'Galaxias',
            'admin_label_all_galaxies' => 'Todas las galaxias',
            'admin_label_selected_galaxies' => 'Solo galaxias seleccionadas',
            'admin_msg_loading_galaxies' => 'Cargando galaxias...',
            'admin_btn_select_all' => 'Seleccionar todo',
            'admin_btn_clear' => 'Limpiar',
            'admin_label_users_always_all' => 'Usuarias (siempre todas)',
            'admin_help_users_export' => 'Las contraseñas de las cuentas se exportan como hashes. Nunca aparecen en texto plano.',
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
            'admin_label_restore_users' => 'Restaurar cuentas',
            'admin_label_skip_existing' => 'Saltar cuentas existentes (coincidir por correo)',
            'admin_label_update_existing' => 'Actualizar cuentas existentes por correo',
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
            'admin_title_galaxy_list' => 'Lista de galaxias visible para quien visita',
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
            'admin_text_no_admin_user_warn' => '(¡sin cuenta de administración!)',
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
            'admin_confirm_no_admin' => 'ADVERTENCIA: esta instantánea no tiene cuenta de administración. Restaurarla bloqueará el acceso a la consola de administración. ¿Continuar de todos modos?',
            'admin_toast_restore_complete_logout' => 'Restauración completa. Es posible que se cierre tu sesión.',
            'admin_toast_restore_complete_report' => 'Restauración completa. %s galaxias creadas, %s cuentas. %s instantánea(s) posterior(es) eliminadas. Es posible que se cierre tu sesión.',
            'admin_toast_failed_load_galaxies' => 'Error al cargar las galaxias: %s',
            'admin_toast_saved_cron_warning' => 'Guardado, pero el programador no pudo registrarse con cron: %s',
            'admin_toast_schedule_saved' => 'Programación guardada.',
            'admin_toast_save_schedule_failed' => 'Error al guardar la programación: %s',
            // C4: admin/index.php (modales)
            'admin_modal_heading_bulk_users' => 'Importar cuentas en lote',
            'admin_modal_bulk_users_imported_one' => 'Se importó <strong>%d</strong> cuenta.',
            'admin_modal_bulk_users_imported_many' => 'Se importaron <strong>%d</strong> cuentas.',
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
            'admin_modal_bulk_users_form_intro' => 'Pega una lista de cuentas, una por línea, con columnas separadas por comas. Solo el correo es obligatorio; todo lo demás es opcional.',
            'admin_modal_bulk_users_field_email' => '<strong>correo</strong>: obligatorio',
            'admin_modal_bulk_users_field_first_name' => '<strong>nombre</strong>',
            'admin_modal_bulk_users_field_last_name' => '<strong>apellido</strong>',
            'admin_modal_bulk_users_field_type' => '<strong>tipo</strong>: <code>Editor</code> (por defecto) o <code>Admin</code>',
            'admin_modal_bulk_users_field_create_galaxy' => '<strong>¿crear galaxia?</strong>: <code>sí</code> / <code>no</code>. Vacío hereda la casilla de abajo; un valor aquí la anula.',
            'admin_modal_bulk_users_example_label' => '<strong>Ejemplo:</strong>',
            'admin_modal_bulk_users_footer_help' => 'Cada nueva cuenta recibe un correo de bienvenida con un enlace de configuración de un solo uso (TTL de 7 días) para establecer la contraseña. Cuando se crea una galaxia asociada, el correo incluye además la URL de la galaxia y el enlace de inicio de sesión. Los correos ya existentes se omiten; las líneas que comienzan con <code>#</code> se ignoran.',
            'admin_modal_bulk_users_textarea_placeholder' => 'correo, nombre, apellido, tipo, crear-galaxia',
            'admin_modal_bulk_users_label_create_galaxy_each' => 'Crear una galaxia para cada nueva cuenta',
            'admin_modal_bulk_users_help_create_galaxy_each' => 'El slug se toma del nombre del correo (antes de <code>@</code>); las colisiones reciben un sufijo aleatorio corto. Las cuentas de edición quedan asignadas a su propia galaxia; las cuentas de administración ya ven todas las galaxias. Anula por fila en la 5.ª columna.',
            'admin_modal_heading_create_user' => 'Crear nueva cuenta',
            'admin_modal_label_first_name' => 'Nombre *',
            'admin_modal_help_first_name' => 'El nombre de pila asociado a la cuenta.',
            'admin_modal_label_last_name' => 'Apellido *',
            'admin_modal_help_last_name' => 'El apellido asociado a la cuenta.',
            'admin_modal_label_email' => 'Correo *',
            'admin_modal_err_email_in_use' => 'Este correo ya está en uso.',
            'admin_modal_help_email' => 'Identificador de inicio de sesión y dirección de contacto.',
            'admin_modal_label_password' => 'Contraseña *',
            'admin_modal_help_password_min' => 'Mínimo 8 caracteres.',
            'admin_modal_label_user_type' => 'Tipo de cuenta *',
            'admin_modal_opt_user_type_editor' => 'Edición',
            'admin_modal_opt_user_type_admin' => 'Administración',
            'admin_modal_help_user_type' => 'Edición: solo puede editar agujeros de gusano en las galaxias asignadas | Administración: acceso completo a todas las galaxias.',
            'admin_modal_label_create_galaxy_for_user' => 'Crear una galaxia nueva para esta cuenta',
            'admin_modal_help_create_galaxy_for_user' => 'Se crea una galaxia con el nombre de abajo y se le concede acceso a ella (solo para cuentas de edición).',
            'admin_modal_label_new_galaxy_name' => 'Nombre de la galaxia *',
            'admin_modal_placeholder_new_galaxy_name' => 'Por defecto, el correo de arriba',
            'admin_modal_help_new_galaxy_name' => 'Nombre para la galaxia creada automáticamente.',
            'admin_modal_label_galaxy_access_editors' => 'Acceso a galaxias (solo para cuentas de edición)',
            'admin_modal_help_galaxy_access_editors' => 'Las cuentas de edición solo pueden ver y editar agujeros de gusano en las galaxias marcadas arriba. Las cuentas de administración ven todas las galaxias.',
            'admin_modal_btn_create_user' => 'Crear cuenta',
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
            'admin_modal_help_cluster_slug' => 'Quien visita llega a <code>/&lt;slug&gt;</code>. Si se deja vacío, se genera a partir del nombre.',
            'admin_modal_placeholder_cluster_tagline' => 'p. ej. Un cúmulo curado',
            'admin_modal_opt_cluster_theme_cosmic' => 'Cósmico',
            'admin_modal_opt_cluster_theme_abstract' => 'Abstracto',
            'admin_modal_opt_cluster_theme_rectangles' => 'Rectángulos',
            'admin_modal_opt_cluster_theme_stripes' => 'Franjas',
            'admin_modal_opt_cluster_theme_tech' => 'Tecnológico',
            'admin_modal_help_cluster_theme' => 'Tema de la escena. El ícono de cada agujero de gusano sigue usando el tema de su galaxia de origen.',
            'admin_modal_label_show_galaxy_list' => 'Mostrar la lista de galaxias a quien visita',
            'admin_modal_help_show_galaxy_list' => 'Cuando se activa, quien visita ve una lista de las galaxias miembro del cúmulo en la esquina inferior derecha; al hacer clic se atenúan los agujeros de gusano de otras galaxias. Desactivado por defecto en cúmulos, ya que el encuadre curado suele leerse como una sola experiencia.',
            'admin_modal_label_member_galaxies' => 'Galaxias miembro *',
            'admin_modal_help_member_galaxies' => 'Quien visita ve la unión de los agujeros de gusano de estas galaxias. Los puentes (líneas discontinuas sutiles) conectan agujeros de gusano que comparten texto de palabra clave entre galaxias.',
            'admin_modal_count_selected_one' => '%d seleccionada',
            'admin_modal_count_selected_many' => '%d seleccionadas',
            'admin_modal_label_keyword_chips' => 'Fichas de palabras clave',
            'admin_modal_help_keyword_chips' => 'Reúne las palabras clave más usadas entre todos los agujeros de gusano visibles (todas las galaxias miembro) en una tira de fichas de filtro en la parte superior del cúmulo. Haz clic en una ficha para atenuar los agujeros de gusano que no coincidan.',
            'admin_modal_label_related_wormholes' => 'Agujeros de gusano relacionados',
            'admin_modal_help_related_wormholes' => 'Cuando la tarjeta de información de un agujero de gusano está abierta, atenúa los no relacionados y muestra hasta 5 agujeros de gusano relacionados (que compartan palabras clave) como fichas de salto en la parte inferior de la tarjeta. Reúne en todo el cúmulo; las fichas pueden surgir de cualquier galaxia miembro.',
            'admin_modal_label_2d_view' => 'Interruptor de vista 2D',
            'admin_modal_help_2d_view' => 'Muestra un conmutador "3D / 2D" en la parte superior central para pasar de la escena 3D a una cuadrícula plana de fichas de agujeros de gusano. La preferencia de cada visita persiste en el navegador.',
            'admin_modal_label_idle_spotlight' => 'Foco al estar inactiva',
            'admin_modal_help_idle_spotlight' => 'Tras un período de inactividad, la cámara vuela a un agujero de gusano aleatorio en cualquier parte del cúmulo y abre su tarjeta de información. Se cierra cuando termina el contenido o tras el temporizador de permanencia.',
            'admin_modal_label_pick_from' => 'Elegir entre',
            'admin_modal_opt_pick_all_wormholes' => 'Todos los agujeros de gusano (de todas las galaxias miembro)',
            'admin_modal_opt_pick_accentuated' => 'Solo agujeros de gusano destacados',
            'admin_modal_label_trigger_after_seconds' => 'Activar después de (segundos de inactividad)',
            'admin_modal_label_auto_tour' => 'Recorrido automático',
            'admin_modal_title_preview_tour' => 'Guarda primero y luego previsualiza el recorrido en una pestaña nueva',
            'admin_modal_btn_preview_tour' => 'Previsualizar recorrido',
            'admin_modal_help_auto_tour' => 'Lleva automáticamente por agujeros de gusano de todo el cúmulo, abriendo cada tarjeta y reproduciendo el contenido. Solo escritorio e iPad.',
            'admin_modal_label_start_mode' => 'Modo de inicio',
            'admin_modal_opt_start_manual' => 'Manual. Se inicia haciendo clic en un botón de reproducción.',
            'admin_modal_opt_start_idle' => 'Inactiva. Se inicia tras un rato de inactividad.',
            'admin_modal_opt_start_immediate' => 'Inmediato. Inicia unos segundos después de cargarse el cúmulo.',
            'admin_modal_label_idle_threshold' => 'Umbral de inactividad (segundos)',
            'admin_modal_warn_immediate_audio' => 'Una o más galaxias miembro contienen agujeros de gusano con audio. Los navegadores bloquean la reproducción automática con sonido hasta que haya alguna interacción con la página, así que el primer audio en un recorrido de inicio inmediato puede quedar en silencio o detenerse.',
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
            'admin_modal_heading_edit_user' => 'Editar cuenta',
            'admin_modal_label_password_optional' => 'Contraseña (déjala vacía para conservar la actual)',
            'admin_modal_btn_update_user' => 'Actualizar cuenta',
            'admin_modal_heading_duplicate_galaxy' => 'Duplicar galaxia',
            'admin_modal_label_duplicating' => 'Duplicando:',
            'admin_modal_label_new_name' => 'Nuevo nombre *',
            'admin_modal_label_new_url_slug' => 'Nuevo slug de URL',
            'admin_modal_label_new_tagline' => 'Nuevo lema',
            'admin_modal_btn_duplicate' => 'Duplicar',
            'admin_modal_heading_confirm_deletion' => 'Confirmar eliminación',
            'admin_modal_label_type_galaxy_name' => 'Escribe el nombre de la galaxia para confirmar:',
            'admin_modal_label_type_to_confirm' => 'Para confirmar, escribe exactamente lo siguiente:',
            'admin_modal_placeholder_type_name' => 'Escribe el nombre aquí...',
            'admin_modal_btn_delete' => 'Eliminar',
            'admin_modal_deletion_impact_title' => '⚠️ Impacto de la eliminación:',
            'admin_modal_deletion_impact_intro' => 'Los siguientes portales en otras galaxias apuntan a esta red y también se eliminarán:',
            'admin_modal_deletion_impact_row' => '<strong>%s</strong> (en la galaxia: %s)',
            'admin_error_user_not_found' => 'No se encontró la cuenta.',
            'admin_error_galaxy_not_found' => 'No se encontró la galaxia.',
            'admin_error_delete_confirm_mismatch' => 'La confirmación no coincide. Escribe el nombre exacto para confirmar la eliminación.',
            'admin_setup_perms_heading' => 'Próximo paso (fortalecimiento del host):',
            'admin_setup_perms_intro' => 'config.php ahora tiene el modo',
            'admin_setup_perms_advice' => 'Ejecuta sudo php bin/setup-host.php desde la raíz del sitio para aplicar la configuración canónica del host (snippet de nginx, regla de logrotate y 0640 propietario=operador en config.php).',

            // C5: admin/setup.php (post-DB)
            'admin_setup_website_info_subtitle' => 'Configura la información del sitio',
            'admin_setup_db_tables_created' => '✓ ¡Tablas de la base de datos creadas correctamente!',
            'admin_setup_website_name_label' => 'Nombre del sitio',
            'admin_setup_website_name_help' => 'El nombre del sitio o proyecto. Por defecto: Telaris',
            'admin_setup_tagline_label' => 'Lema',
            'admin_setup_tagline_help' => 'Una descripción breve o lema. Por defecto: Tejiendo memoria',
            'admin_setup_website_info_footer_help' => 'Estos valores se usan para la galaxia por defecto y la información del proyecto. Se pueden cambiar más adelante en Admin → Configuración global y Galaxias.',
            'admin_setup_website_info_continue' => 'Continuar',
            'admin_setup_schema_details_heading' => 'Detalles de la creación del esquema',
            'admin_setup_schema_db_created' => 'Base de datos <strong>%s</strong> creada correctamente',
            'admin_setup_schema_db_exists' => 'La base de datos <strong>%s</strong> ya existe',
            'admin_setup_schema_tables_created_one' => 'Tabla creada (%d):',
            'admin_setup_schema_tables_created_many' => 'Tablas creadas (%d):',
            'admin_setup_schema_tables_existed_one' => 'Tabla ya existente (%d):',
            'admin_setup_schema_tables_existed_many' => 'Tablas ya existentes (%d):',
            'admin_setup_schema_no_tables' => 'No se crearon ni omitieron tablas.',
            'admin_setup_schema_api_key_heading' => '✓ Clave API por defecto generada',
            'admin_setup_schema_api_key_help' => 'Se ha generado automáticamente una clave API por defecto que ya está en uso. Las claves API se gestionan desde la página de gestión de claves API.',
            'admin_setup_admin_user_heading' => 'Crear cuenta de administración',
            'admin_setup_admin_user_intro' => 'Aún no existe ninguna cuenta de administración. Crea una para acceder a la consola de administración.',
            'admin_setup_first_name_label' => 'Nombre *',
            'admin_setup_last_name_label' => 'Apellido *',
            'admin_setup_email_label' => 'Correo electrónico *',
            'admin_setup_email_help' => 'Este será el correo de inicio de sesión.',
            'admin_setup_password_label' => 'Contraseña *',
            'admin_setup_password_help' => 'Mínimo 8 caracteres',
            'admin_setup_confirm_password_label' => 'Confirmar contraseña *',
            'admin_setup_create_admin_btn' => 'Crear cuenta de administración',
            'admin_setup_admin_user_created' => '✓ ¡Cuenta de administración creada correctamente!',
            'admin_setup_admin_user_can_login' => 'Ya puedes iniciar sesión en la %s.',
            'admin_setup_admin_user_login_link' => 'página de inicio de sesión',
            'admin_setup_config_created_flash' => '✓ ¡Archivo de configuración creado correctamente!',
            'admin_setup_complete_with_schema' => 'Instalación completa. Esquema de la base de datos creado e información del proyecto inicializada.',
            'admin_setup_complete_no_schema' => 'Instalación completa. Información del proyecto inicializada.',
            'admin_setup_db_error_prefix' => 'Error de base de datos:',
            'admin_setup_error_prefix' => 'Error:',
            'admin_setup_status_heading' => 'Estado de la instalación:',
            'admin_setup_config_file_label' => 'Archivo de configuración:',
            'admin_setup_config_file_created' => '✓ Creado',
            'admin_setup_config_file_missing' => '✗ Falta',
            'admin_setup_db_connection_label' => 'Conexión a la base de datos:',
            'admin_setup_db_connection_connected' => '✓ Conectada',
            'admin_setup_db_connection_failed' => '✗ Falló',
            'admin_setup_project_info_label' => 'Información del proyecto:',
            'admin_setup_project_info_initialized' => '✓ Inicializada',
            'admin_setup_project_info_not_initialized' => '✗ Sin inicializar',
            'admin_setup_link_go_to_telaris' => 'Ir a Telaris →',
            'admin_setup_link_admin_console' => 'Consola de administración',
            'admin_setup_link_reconfigure_db' => 'Reconfigurar base de datos',
            'admin_setup_validation_all_fields_required' => 'Todos los campos son obligatorios.',
            'admin_setup_validation_passwords_mismatch' => 'Las contraseñas no coinciden.',
            'admin_setup_validation_password_too_short' => 'La contraseña debe tener al menos 8 caracteres.',
            'admin_setup_validation_db_unavailable' => 'Conexión a la base de datos no disponible.',

            // C5b: utils/login.php + utils/forgot.php + utils/reset.php
            'auth_login_page_title' => 'Inicio de sesión - Telaris',
            'auth_login_heading' => 'Iniciar sesión en Telaris',
            'auth_login_subtitle' => 'Accede al espacio de la constelación',
            'auth_email_label' => 'Correo',
            'auth_password_label' => 'Contraseña',
            'auth_login_submit' => 'Iniciar sesión',
            'auth_login_forgot_link' => '¿Olvidaste la contraseña?',
            'auth_login_back_link' => '← Volver a la constelación',
            'auth_error_invalid_request' => 'Solicitud no válida. Recarga la página e inténtalo de nuevo.',
            'auth_error_throttled' => 'Demasiados intentos. Inténtalo de nuevo más tarde.',
            'auth_login_error_required' => 'El correo y la contraseña son obligatorios',
            'auth_login_error_invalid' => 'Correo o contraseña no válidos. Solo las cuentas de edición y de administración pueden iniciar sesión aquí.',
            'auth_forgot_page_title' => 'Restablecer contraseña - Telaris',
            'auth_forgot_heading' => 'Recuperar contraseña',
            'auth_forgot_subtitle' => 'Te enviaremos un enlace de un solo uso para establecer una contraseña nueva.',
            'auth_forgot_generic_notice' => 'Si existe una cuenta con ese correo, se ha enviado un enlace para restablecer la contraseña.',
            'auth_forgot_error_invalid_email' => 'Introduce una dirección de correo válida.',
            'auth_forgot_submit' => 'Enviar enlace de restablecimiento',
            'auth_forgot_back_link' => '← Volver al inicio de sesión',
            'auth_email_subject' => 'Restablece tu contraseña de %s',
            'auth_email_greeting_named' => 'Hola %s,',
            'auth_email_greeting_anon' => 'Hola,',
            'auth_email_intro' => 'Recibimos una solicitud para restablecer tu contraseña. Pulsa el enlace para establecer una nueva:',
            'auth_email_expiry' => 'El enlace caduca en 24 horas y solo puede usarse una vez. Si no solicitaste el restablecimiento, puedes ignorar este correo; tu contraseña no cambiará.',
            'auth_email_text_intro' => "Recibimos una solicitud para restablecer tu contraseña.\n\nEnlace de restablecimiento (24h, un solo uso):\n",
            'auth_email_text_outro' => "\n\nSi no solicitaste el restablecimiento, ignora este correo.",
            'auth_reset_page_title' => 'Establecer nueva contraseña - Telaris',
            'auth_reset_heading' => 'Establecer nueva contraseña',
            'auth_reset_success_message' => 'Contraseña actualizada. Ya puedes iniciar sesión con la nueva contraseña.',
            'auth_reset_btn_go_to_login' => 'Ir al inicio de sesión',
            'auth_reset_invalid_token_message' => 'Este enlace de restablecimiento no es válido o ha caducado. Solicita uno nuevo.',
            'auth_reset_btn_request_new_link' => 'Solicitar un nuevo enlace',
            'auth_reset_intro_html' => 'Estableciendo una nueva contraseña para <strong>%s</strong>.',
            'auth_reset_new_password_label' => 'Nueva contraseña',
            'auth_reset_password_help' => 'Al menos 8 caracteres.',
            'auth_reset_confirm_password_label' => 'Confirmar nueva contraseña',
            'auth_reset_submit' => 'Actualizar contraseña',
            'auth_reset_error_password_too_short' => 'La contraseña debe tener al menos 8 caracteres.',
            'auth_reset_error_password_mismatch' => 'Las contraseñas no coinciden.',

            // C7a: inc/partials/galaxy-edit-modal.php
            'gem_heading' => 'Editar galaxia',
            'gem_name_label' => 'Nombre *',
            'gem_name_duplicate_error' => 'Este nombre ya está en uso.',
            'gem_tagline_label' => 'Lema',
            'gem_slug_label' => 'Ruta de URL',
            'gem_slug_placeholder' => 'p. ej. archivo',
            'gem_slug_duplicate_error' => 'Esta ruta ya está en uso.',
            'gem_slug_help' => 'Ruta personalizada de la URL. Si se deja vacía, se genera a partir del nombre. Solo letras, números y guiones.',
            'gem_theme_label' => 'Tema visual',
            'gem_theme_cosmic' => 'Cósmico (estrellas, planetas, cohetes)',
            'gem_theme_simple' => 'Simple (esferas de colores)',
            'gem_theme_abstract' => 'Abstracto (iconos GIF geométricos)',
            'gem_theme_rectangles' => 'Rectángulos (iconos rectangulares personalizados)',
            'gem_theme_stripes' => 'Rayas (iconos de rayas personalizados)',
            'gem_theme_tech' => 'Tech (iconos de placa de circuito)',
            'gem_tags_label' => 'Etiquetas',
            'gem_tags_placeholder' => 'Añadir etiqueta...',
            'gem_tags_help' => 'Quien visita puede explorar la unión de todas las galaxias con una etiqueta en <code>/tag/&lt;tag&gt;</code>. Escribe para añadir; pulsa Enter o coma. Las sugerencias muestran etiquetas ya usadas en esta galaxia y en galaxias hermanas que comparten el prefijo <code>[XX]</code>.',
            'gem_bulk_actions_label' => 'Acciones en bloque sobre agujeros de gusano',
            'gem_bulk_actions_help' => 'Se aplican a todos los agujeros de gusano de esta galaxia a la vez. Los conmutadores individuales pueden anular después.',
            'gem_bulk_use_images_btn' => 'Usar imágenes como iconos (todos los agujeros de gusano)',
            'gem_bulk_revert_icons_btn' => 'Volver a los iconos del tema en todos',
            'gem_keyword_chips_label' => 'Fichas de palabras clave',
            'gem_keyword_chips_help' => 'Muestra las palabras clave más usadas como fichas de filtro en la parte superior de la galaxia. Pulsa una ficha para atenuar los agujeros de gusano que no coincidan.',
            'gem_related_label' => 'Agujeros de gusano relacionados',
            'gem_related_help' => 'Cuando se abre la tarjeta de información de un agujero de gusano, atenúa los no relacionados en la escena y muestra hasta 5 relacionados (que comparten palabras clave) como fichas para saltar al final de la tarjeta. Muestra cada vez una selección aleatoria.',
            'gem_2d_view_label' => 'Conmutador de vista 2D',
            'gem_2d_view_help' => 'Muestra un conmutador "3D / 2D" en la parte superior central para pasar de la escena 3D a una cuadrícula plana de fichas de agujeros de gusano. La preferencia persiste en el navegador.',
            'gem_idle_spotlight_label' => 'Foco al estar inactiva',
            'gem_idle_spotlight_help' => 'Tras un período de inactividad, la cámara vuela a un agujero de gusano aleatorio y se abre su tarjeta de información. Se cierra cuando termina el contenido o tras el temporizador de permanencia.',
            'gem_pick_from_label' => 'Elegir entre',
            'gem_idle_pick_all' => 'Todos los agujeros de gusano',
            'gem_idle_pick_accentuated' => 'Solo los agujeros de gusano destacados',
            'gem_idle_trigger_label' => 'Activar tras (segundos de inactividad)',
            'gem_autotour_label' => 'Recorrido automático',
            'gem_autotour_preview_btn' => 'Vista previa del recorrido',
            'gem_autotour_preview_title' => 'Guarda primero y luego prueba el recorrido en una pestaña nueva',
            'gem_autotour_help' => 'Navega automáticamente por los nodos, abriendo cada tarjeta y reproduciendo el contenido. Solo escritorio e iPad.',
            'gem_start_mode_label' => 'Modo de inicio',
            'gem_start_mode_manual' => 'Manual. Se inicia al pulsar un botón de reproducción.',
            'gem_start_mode_idle' => 'Inactivo. Se inicia tras un período de inactividad.',
            'gem_start_mode_immediate' => 'Inmediato. Se inicia unos segundos después de cargar la galaxia.',
            'gem_idle_threshold_label' => 'Umbral de inactividad (segundos)',
            'gem_immediate_audio_warning' => 'Esta galaxia contiene nodos con audio. Los navegadores bloquean la reproducción automática con sonido hasta que haya alguna interacción con la página, así que el primer audio en un recorrido de inicio inmediato puede quedar en silencio o detenerse.',
            'gem_which_nodes_label' => 'Qué nodos incluir en el recorrido',
            'gem_nodes_all' => 'Todos los nodos (orden aleatorio en cada ejecución)',
            'gem_nodes_accentuated' => 'Solo nodos destacados',
            'gem_nodes_random_n' => 'Una muestra aleatoria de N nodos',
            'gem_nodes_tagged' => 'Nodos etiquetados con una de estas palabras clave',
            'gem_random_count_label' => 'Cuántos nodos por recorrido',
            'gem_keywords_label' => 'Palabras clave (cualquier coincidencia)',
            'gem_keywords_help' => 'Se mostrarán los nodos que coincidan con cualquiera de las palabras clave seleccionadas.',
            'gem_dwell_label' => 'Pausa en nodos sin contenido (segundos)',
            'gem_loop_label' => 'Repetir el recorrido al terminar',
            'gem_submit_btn' => 'Actualizar galaxia',
            'gem_cancel_btn' => 'Cancelar',
            'gem_close_btn' => 'cerrar',

            // C7b: títulos de errores de API (RFC 9457). Código <estado-http>.<subcódigo-de-3-dígitos>.
            'api_error_400_001' => 'JSON no válido: %s',
            'api_error_400_002' => 'Falta un campo obligatorio.',
            'api_error_400_003' => 'URL no válida: solo se permiten URLs http y https.',
            'api_error_400_004' => 'Formato de clave de cúmulo no válido.',
            'api_error_400_005' => 'El parámetro galaxies es incompatible con page/id.',
            'api_error_400_006' => 'El cuerpo de la petición está vacío.',
            'api_error_400_007' => 'El nombre del nodo es obligatorio.',
            'api_error_400_008' => 'El nombre del nodo no puede estar vacío.',
            'api_error_400_009' => 'El id del nodo es obligatorio.',
            'api_error_400_010' => 'Se requiere un id de constelación.',
            'api_error_400_011' => 'Se requiere un nombre de constelación.',
            'api_error_400_012' => 'Se requiere una palabra clave.',
            'api_error_400_013' => 'Se requiere un id de palabra clave.',
            'api_error_400_014' => 'La palabra clave no pertenece a la constelación indicada.',
            'api_error_400_015' => 'Se requiere un id de galaxia.',
            'api_error_400_016' => 'move_keyword requiere keyword_id, x, y.',
            'api_error_400_017' => 'create_relation requiere keyword_a_id y keyword_b_id.',
            'api_error_400_018' => 'No se permiten relaciones consigo misma.',
            'api_error_400_019' => 'Ambas palabras clave deben pertenecer a la misma galaxia.',
            'api_error_400_020' => 'update_relation requiere relation_id.',
            'api_error_400_021' => 'delete_relation requiere relation_id.',
            'api_error_400_022' => 'reset_keyword requiere keyword_id.',
            'api_error_400_023' => 'reset_galaxy requiere galaxy_id.',
            'api_error_400_024' => 'delete_keyword requiere keyword_id.',
            'api_error_400_025' => 'rename_keyword requiere keyword_id.',
            'api_error_400_026' => 'rename_keyword requiere un nombre nuevo no vacío.',
            'api_error_400_027' => 'El nombre de palabra clave es demasiado largo (máximo 100 caracteres).',
            'api_error_400_028' => 'merge_keywords requiere source_id y target_id.',
            'api_error_400_029' => 'No se puede fusionar una palabra clave consigo misma.',
            'api_error_400_030' => 'Acción desconocida: %s.',
            'api_error_400_031' => 'Se requieren constellation_id, keyword_id y op (delete|move|count).',
            'api_error_400_032' => 'target_constellation_id es obligatorio para move.',
            'api_error_400_033' => 'Falta el nombre del puente o no es válido.',
            'api_error_400_034' => "El puente '%s' no está habilitado en esta instancia.",
            'api_error_400_035' => 'Tipo de validación no válido.',
            'api_error_400_036' => 'Falló la subida del archivo (código %d).',
            'api_error_400_037' => 'Falta el parámetro phase o no es válido.',
            'api_error_400_038' => 'Se requiere confirmación.',
            'api_error_400_039' => 'Falta el id o no es válido.',
            'api_error_400_040' => 'Falta la frase de confirmación o es incorrecta (debe ser RESTORE).',
            'api_error_400_041' => 'Error de codificación.',
            'api_error_400_042' => 'No se pudo codificar la respuesta.',
            'api_error_400_043' => 'Selecciona al menos galaxias o cuentas para respaldar.',
            'api_error_400_044' => 'Formato de URL no válido. Se esperaba una URL completa como https://hostname/api/v2.',
            'api_error_400_045' => 'No se especificó ninguna galaxia.',
            'api_error_400_046' => 'Se rechaza la conexión con este servidor remoto: %s',

            'api_error_401_001' => 'Falta la clave de API. Proporciónala por el encabezado X-API-Key, por Authorization: Bearer, o por el parámetro api_key de la URL.',
            'api_error_401_002' => 'Clave de API no válida.',

            'api_error_403_001' => 'Las operaciones de escritura requieren una sesión autenticada. Inicia sesión.',
            'api_error_403_002' => 'Permisos insuficientes para operaciones de escritura.',
            'api_error_403_003' => 'Token de seguridad no válido. Recarga la página e inténtalo de nuevo.',
            'api_error_403_004' => 'Sin acceso de edición a esta galaxia.',
            'api_error_403_005' => 'Acceso denegado.',
            'api_error_403_006' => 'Solo quien creó la relación o una cuenta de administración pueden editarla.',
            'api_error_403_007' => 'Solo quien creó la relación o una cuenta de administración pueden eliminarla.',
            'api_error_403_008' => 'La verificación de la existencia de una cuenta se restringe a sesiones de administración.',

            'api_error_404_001' => 'Nodo no encontrado.',
            'api_error_404_002' => 'Galaxia no encontrada.',
            'api_error_404_003' => 'Palabra clave no encontrada.',
            'api_error_404_004' => 'Relación no encontrada.',
            'api_error_404_005' => 'La relación referencia una palabra clave inexistente.',
            'api_error_404_006' => 'Cúmulo no encontrado.',
            'api_error_404_007' => 'Nodo de origen no encontrado.',
            'api_error_404_008' => 'La galaxia de destino no existe.',
            'api_error_404_009' => 'Clave de API no encontrada.',
            'api_error_404_010' => "Falta el archivo del manejador del puente '%s'.",
            'api_error_404_011' => "El puente '%s' no tiene manejador de peticiones.",
            'api_error_404_012' => 'Carga desconocida o caducada. Vuelve a seleccionar el archivo.',
            'api_error_404_013' => 'Falta el archivo subido. Vuelve a seleccionarlo.',
            'api_error_404_014' => 'Instantánea no encontrada.',

            'api_error_405_001' => 'Método no permitido.',

            'api_error_409_001' => 'Ya existe una palabra clave con ese nombre.',
            'api_error_409_002' => 'Ya existe una relación entre estas palabras clave.',

            'api_error_500_001' => 'Error interno del servidor.',
            'api_error_500_002' => 'Error de base de datos.',
            'api_error_500_003' => 'No se pudo crear el directorio de subidas. Revisa los permisos del servidor.',
            'api_error_500_004' => 'No se pudo guardar el archivo subido.',
            'api_error_500_005' => 'No se pudo guardar la imagen subida.',
            'api_error_500_006' => 'No se pudo guardar el icono subido.',
            'api_error_500_007' => 'No se pudo guardar el audio subido.',
            'api_error_500_008' => 'No se pudo guardar el vídeo subido.',
            'api_error_500_009' => 'No se pudo guardar el PDF subido.',
            'api_error_500_010' => 'No se pudo extraer un fotograma del vídeo subido.',
            'api_error_500_011' => 'El archivo no parece un PDF válido.',
            'api_error_500_012' => 'No se pudo crear el nodo: no se pudo recuperar su id.',
            'api_error_500_013' => 'No se pudieron codificar los datos de animación.',
            'api_error_500_014' => 'No se pudieron codificar los datos JSON.',
            'api_error_500_015' => 'No se pudo guardar el archivo de respaldo subido.',
            'api_error_502_001' => 'No se pudo alcanzar la API de Mocambos en %s.',

            // C7c: mensajes del resultado de actualización de galaxia.
            'galaxy_update_missing_id' => 'Falta el id de la galaxia.',
            'galaxy_update_not_authorized' => 'Sin autorización.',
            'galaxy_update_no_access' => 'Sin acceso a esta galaxia.',
            'galaxy_update_name_required' => 'El nombre de la galaxia es obligatorio.',
            'galaxy_update_duplicate_name' => 'Ya existe una galaxia con el nombre "%s".',
            'galaxy_update_duplicate_slug' => 'Ya existe una galaxia con la ruta "%s".',
            'galaxy_update_duplicate_both' => 'Ya existe una galaxia con el nombre "%s" y la ruta "%s".',
            'galaxy_update_success' => 'Galaxia actualizada correctamente.',

            // C7d: UI de administración del puente Mocambos (chrome + cadenas JS).
            'mocambos_btn_import_from' => 'Importar desde Mocambos',
            'mocambos_modal_heading' => 'Importar desde Mocambos',
            'mocambos_label_api_url' => 'URL de la API de Mocambos',
            'mocambos_help_api_url' => 'La URL base de la API de la instancia de Mocambos (p. ej. https://hostname/api/v2). También puedes pegar la URL de la documentación; /docs se elimina automáticamente.',
            'mocambos_btn_connect' => 'Conectar',
            'mocambos_text_loading' => 'Obteniendo las galaxias disponibles...',
            'mocambos_btn_back' => 'Atrás',
            'mocambos_text_connected_to' => 'Conectado a:',
            'mocambos_text_select_intro' => 'Selecciona las galaxias a importar. Cada una se convierte en una galaxia nueva. Las que ya estén importadas se actualizan.',
            'mocambos_text_starting_import' => 'Iniciando la importación...',
            'mocambos_text_refresh_intro' => 'Esto sincroniza los agujeros de gusano con la fuente remota de Mocambos (actualización incremental).',
            'mocambos_text_refresh_confirm_instruction' => 'Para confirmar, escribe abajo el nombre de la galaxia <strong id="refresh-confirm-name" class="text-gray-900">%s</strong>:',
            'mocambos_placeholder_refresh_confirm' => 'Escribe el nombre de la galaxia para confirmar',
            'mocambos_btn_refresh' => 'Actualizar',
            'mocambos_btn_cancel' => 'Cancelar',
            'mocambos_btn_import_selected' => 'Importar selección',
            'mocambos_btn_close' => 'Cerrar',
            'mocambos_btn_modal_backdrop_close' => 'cerrar',
            'mocambos_js_validation_report_title' => 'Informe de validación de la API de Mocambos',
            'mocambos_js_validation_url_prefix' => 'URL:',
            'mocambos_js_validation_date_prefix' => 'Fecha:',
            'mocambos_js_validating_api' => 'Validando la API...',
            'mocambos_js_enter_url' => 'Introduce una URL de la API de Mocambos.',
            'mocambos_js_validation_failed_intro' => 'La validación de la API falló. Se encontraron los siguientes problemas:',
            'mocambos_js_copied' => 'Copiado',
            'mocambos_js_copy_report' => 'Copiar informe al portapapeles',
            'mocambos_js_could_not_validate' => 'No se pudo validar: %s',
            'mocambos_js_network_error' => 'Error de red',
            'mocambos_js_fetch_failed' => 'No se pudieron obtener las galaxias',
            'mocambos_js_no_galaxias' => 'No se encontraron galaxias en esta URL.',
            'mocambos_js_badge_imported' => 'Importada',
            'mocambos_js_connect_failed' => 'No se pudo conectar con la API de Mocambos',
            'mocambos_js_select_at_least_one' => 'Selecciona al menos una galaxia para importar.',
            'mocambos_js_confirm_refresh_intro' => 'Las siguientes galaxias se actualizarán, reemplazando todo el contenido actual incluidas las ediciones:',
            'mocambos_js_confirm_refresh_continue' => '¿Continuar?',
            'mocambos_js_import_failed_generic' => 'Importación fallida',
            'mocambos_js_import_complete_status' => 'Importación completa',
            'mocambos_js_status_label_new' => 'Nueva',
            'mocambos_js_status_label_refreshed' => 'Actualizada',
            'mocambos_js_items_count' => '%d de %d elementos',
            'mocambos_js_completed_success' => 'Importación completada correctamente.',
            'mocambos_js_completed_errors' => 'Importación completada con algunos errores.',
            'mocambos_js_refresh_complete_log' => 'Actualización completa.',
            'mocambos_js_refresh_complete_status' => 'Actualización completa',
            'mocambos_js_refresh_failed_status' => 'Actualización fallida',
            'mocambos_js_missing_source' => 'Falta la información de origen de importación para esta galaxia.',
            'mocambos_js_refreshing' => 'Actualizando "%s"...',
            'mocambos_js_error_prefix' => 'Error: %s',
            'mocambos_js_unknown_error' => 'Error desconocido',

            // C7e: cadenas de handler.php (streamMsg HTTP, validación, salida CLI).
            'mocambos_h_resolved_mucua_names' => 'Se resolvieron %d nombres de mucua.',
            'mocambos_h_fetching_media' => 'Obteniendo elementos multimedia de la API de Mocambos...',
            'mocambos_h_total_items_fetched' => 'Total de elementos obtenidos: %d.',
            'mocambos_h_processing_galaxia' => 'Procesando galaxia: %s (%d elementos).',
            'mocambos_h_import_complete' => 'Importación completa.',
            'mocambos_h_full_refresh_clearing' => 'Actualización completa; eliminando los nodos existentes...',
            'mocambos_h_re_importing_diff' => 'Reimportando; calculando diferencias...',
            'mocambos_h_backfilled_slugs' => 'Se rellenaron %d slugs de importación.',
            'mocambos_h_diff_summary' => 'Diff: %d nuevos, %d modificados, %d eliminados, %d sin cambios.',
            'mocambos_h_deleting_removed' => 'Eliminando %d elementos retirados...',
            'mocambos_h_updating_modified' => 'Actualizando %d elementos modificados...',
            'mocambos_h_created_constellation' => 'Constelación creada: %s (id %d).',
            'mocambos_h_adding_new_nodes' => 'Añadiendo %d nodos nuevos...',
            'mocambos_h_phase1_creating' => 'Fase 1: creando %d nodos...',
            'mocambos_h_nodes_created_progress' => '  %d/%d nodos creados.',
            'mocambos_h_phase1_complete' => 'Fase 1 completa: %d/%d nodos creados.',
            'mocambos_h_phase2_downloading' => 'Fase 2: descargando archivos multimedia...',
            'mocambos_h_downloading_image' => '(%s) Descargando imagen: %s',
            'mocambos_h_downloading_video' => '(%s) Descargando vídeo: %s',
            'mocambos_h_downloading_audio' => '(%s) Descargando audio: %s',
            'mocambos_h_phase2_complete' => 'Fase 2 completa: %d archivos multimedia descargados.',
            'mocambos_h_phase2_complete_with_errors' => 'Fase 2 completa: %d archivos multimedia descargados (%d fallaron).',
            'mocambos_h_galaxia_done' => 'Galaxia %s lista: %d/%d elementos importados.',
            'mocambos_h_galaxia_done_with_errors' => 'Galaxia %s lista: %d/%d elementos importados (%d errores).',
            'mocambos_h_concurrent_import' => 'Ya hay una importación en curso para la galaxia %s; intenta de nuevo más tarde.',
            'mocambos_h_failed_to_create_node' => 'No se pudo crear el nodo: %s (%s).',
            'mocambos_h_media_downloads_failed' => '%d descargas multimedia fallaron.',
            'mocambos_h_check_connection_failed' => 'Falló la conexión; no se pudo alcanzar el servidor.',
            'mocambos_h_check_galaxia_http_fail' => 'HTTP %d; se esperaba 200. Este endpoint debe devolver un array JSON de objetos galaxia.',
            'mocambos_h_check_galaxia_not_array' => 'La respuesta no es un array JSON válido. Recibido: %s',
            'mocambos_h_check_galaxia_empty' => 'Devolvió un array vacío; no hay galaxias disponibles para importar.',
            'mocambos_h_check_galaxia_missing_fields' => 'A los objetos galaxia les faltan campos obligatorios: %s. Cada galaxia debe tener: name, slug, default_mucua.',
            'mocambos_h_check_galaxia_ok' => 'Se encontraron %d galaxia(s). La estructura parece correcta.',
            'mocambos_h_check_mucua_http_fail' => 'HTTP %d; se esperaba 200. Este endpoint debe devolver un array JSON de objetos mucua.',
            'mocambos_h_check_mucua_not_array' => 'La respuesta no es un array JSON válido. Recibido: %s',
            'mocambos_h_check_mucua_empty' => 'Devolvió un array vacío; no se encontraron mucuas. Las descargas multimedia pueden no funcionar.',
            'mocambos_h_check_mucua_missing_fields' => 'A los objetos mucua les faltan campos obligatorios: %s. Cada mucua debe tener: smid, slug.',
            'mocambos_h_check_mucua_ok' => 'Se encontraron %d mucua(s). La estructura parece correcta.',
            'mocambos_h_check_acervo_http_fail' => 'HTTP %d; se esperaba 200. Este endpoint debe devolver un objeto JSON paginado con un array "items".',
            'mocambos_h_check_acervo_no_items' => 'La respuesta no contiene la clave "items". Se esperaba {item_count, page_count, items: [...]}. Recibido: %s',
            'mocambos_h_check_acervo_ok' => 'Devolvió %d elemento(s) multimedia en total. La estructura parece correcta.',
            'mocambos_h_check_blog_http_fail' => 'HTTP %d; se esperaba 200. Los artículos de blog no se importarán.',
            'mocambos_h_check_blog_no_items' => 'La respuesta no contiene la clave "items". Los artículos de blog no se importarán.',
            'mocambos_h_check_blog_ok' => 'Devolvió %d artículo(s) de blog en total. La estructura parece correcta.',
            'mocambos_h_cli_header' => 'Importación de Mocambos',
            'mocambos_h_cli_prompt_api_base' => 'URL base de la API de Mocambos',
            'mocambos_h_cli_err_api_base_required' => 'Error: se requiere --api-base.',
            'mocambos_h_cli_err_usage' => 'Uso: php admin/cli/import_bridge.php mocambos --api-base=URL --galaxia=SLUG',
            'mocambos_h_cli_connecting' => 'Conectando con %s...',
            'mocambos_h_cli_fetch_galaxias_failed' => 'No se pudo obtener la lista de galaxias desde %s.',
            'mocambos_h_cli_found_counts' => 'Se encontraron %d galaxia(s), %d mucua(s).',
            'mocambos_h_cli_available_galaxias_at' => 'Galaxias disponibles en %s:',
            'mocambos_h_cli_col_slug' => 'SLUG',
            'mocambos_h_cli_col_name' => 'NOMBRE',
            'mocambos_h_cli_col_smid' => 'SMID',
            'mocambos_h_cli_available_galaxias' => 'Galaxias disponibles:',
            'mocambos_h_cli_already_imported' => '(ya importada)',
            'mocambos_h_cli_prompt_select_galaxia' => 'Selecciona el número de la galaxia (o escribe el slug)',
            'mocambos_h_cli_no_galaxia_selected' => 'No se seleccionó ninguna galaxia.',
            'mocambos_h_cli_err_galaxia_required' => 'Error: se requiere --galaxia=SLUG.',
            'mocambos_h_cli_matched_slug' => 'Slug de galaxia coincidente: %s.',
            'mocambos_h_cli_galaxia_not_found' => 'La galaxia "%s" no se encontró. Usa --list para ver las galaxias disponibles.',
            'mocambos_h_cli_prompt_download_media' => '¿Descargar archivos multimedia? (más lento pero incluye imágenes/audio/vídeo)',
            'mocambos_h_cli_prompt_limit' => '¿Limitar el número de elementos? (introduce un número, o pulsa Enter para todos)',
            'mocambos_h_cli_summary_galaxia' => 'Galaxia:',
            'mocambos_h_cli_summary_api' => 'API:',
            'mocambos_h_cli_summary_media' => 'Multimedia:',
            'mocambos_h_cli_summary_limit' => 'Límite:',
            'mocambos_h_cli_value_skip' => 'omitir',
            'mocambos_h_cli_value_download' => 'descargar',
            'mocambos_h_cli_value_all' => 'todos',
            'mocambos_h_cli_prompt_proceed' => '¿Proceder con la importación?',
            'mocambos_h_cli_aborted' => 'Cancelado.',
            'mocambos_h_cli_galaxia_info' => 'Galaxia: %s (slug=%s, smid=%s).',
            'mocambos_h_cli_total_items' => 'Total de elementos para esta galaxia: %d.',
            'mocambos_h_cli_limited_to' => 'Limitado a %d elementos (--limit).',
            'mocambos_h_cli_constellation_label' => 'Constelación: %s (id %d).',
            'mocambos_h_cli_imported_summary' => 'Importados: %d/%d elementos en %ss.',
            'mocambos_h_cli_errors_count' => 'Errores: %d.',
            'mocambos_h_cli_media_skipped' => 'Descargas multimedia omitidas (--no-media).',
            'mocambos_h_cli_constellation_new' => 'Constelación nueva creada.',
            'mocambos_h_cli_constellation_existing' => 'Constelación existente reimportada.',

            // C7f: edit/keyword-canvas.php (chrome PHP).
            'editor_kc_page_title' => 'Lienzo de palabras clave',
            'editor_kc_err_missing_galaxy_id' => 'Falta <code>?galaxy_id=N</code>.',
            'editor_kc_err_galaxy_not_found' => 'Galaxia no encontrada.',
            'editor_kc_err_clusters_no_canvas' => 'Los cúmulos no tienen palabras clave propias; el lienzo solo se aplica a galaxias. Abre el lienzo en una galaxia miembro.',
            'editor_kc_err_no_edit_access' => 'Sin acceso de edición a esta galaxia.',
            'editor_kc_back_link' => '← Atrás',
            'editor_kc_page_title_template' => 'Lienzo de palabras clave; %s',
            'editor_kc_empty_state' => 'Esta galaxia aún no tiene palabras clave. Añade primero algunos agujeros de gusano con palabras clave.',
            'editor_kc_mobile_block' => 'Abre el lienzo de palabras clave en un navegador de escritorio para crear relaciones entre palabras clave. Las interacciones necesitan una pantalla más grande y un ratón o trackpad.',
            'editor_kc_note_modal_title' => 'Nota de relación',
            'editor_kc_note_modal_intro' => 'Encuadre editorial opcional; ¿qué transmite esta relación que una palabra clave compartida no puede decir por sí sola?',
            'editor_kc_note_modal_cancel' => 'Cancelar',
            'editor_kc_note_modal_save' => 'Guardar',
            'editor_kc_keyword_modal_title' => 'Palabra clave',
            'editor_kc_keyword_modal_new_name_label' => 'Nuevo nombre',
            'editor_kc_keyword_modal_cancel' => 'Cancelar',
            'editor_kc_keyword_modal_delete' => 'Eliminar',
            'editor_kc_keyword_modal_rename' => 'Renombrar',
            'editor_kc_conflict_modal_title' => 'La palabra clave ya existe',
            'editor_kc_conflict_modal_body_suffix' => 'ya existe en esta galaxia.',
            'editor_kc_conflict_modal_options_intro' => '<strong>Cambiar nombre</strong>: mantén esta palabra clave separada y elige un nombre distinto.<br><strong>Fusionar</strong>: integra esta palabra clave en la existente; todos los agujeros de gusano marcados con ella, todas las líneas del lienzo, se redirigen a la palabra clave existente. Esta se eliminará. Sin opción de deshacer.',
            'editor_kc_conflict_modal_change' => 'Cambiar nombre',
            'editor_kc_conflict_modal_merge' => 'Fusionar',
            'editor_kc_line_modal_title' => 'Relación',
            'editor_kc_line_modal_noauth' => 'Solo quien creó la relación o una cuenta de administración pueden editarla o eliminarla.',
            'editor_kc_line_modal_close' => 'Cerrar',
            'editor_kc_line_modal_edit' => 'Editar nota',
            'editor_kc_line_modal_delete' => 'Eliminar',
            'editor_kc_backdrop_close' => 'cerrar',
            'editor_kc_help_button' => 'Ayuda',
            'editor_kc_help_title' => 'Guía rápida',
            'editor_kc_help_purpose' => 'Usa esta vista para mapear cómo se relacionan entre sí las palabras clave de esta galaxia. Cuanto más cerca estén, más fuerte es su relación. Arrastra las fichas para definir su proximidad y dibuja líneas entre ellas para registrar conexiones semánticas específicas.',
            'editor_kc_help_intro' => 'Cómo usarlo:',
            'editor_kc_help_move_label' => 'Mover una palabra clave',
            'editor_kc_help_move_body' => 'Arrastra una ficha para reubicarla.',
            'editor_kc_help_connect_label' => 'Conectar dos palabras clave',
            'editor_kc_help_connect_body' => 'Pulsa un punto de anclaje de una ficha y luego en uno de otra. O arrastra de un punto al otro.',
            'editor_kc_help_edit_label' => 'Editar o eliminar una línea',
            'editor_kc_help_edit_body' => 'Pulsa una línea existente para abrirla.',
            'editor_kc_help_pan_label' => 'Mover la vista',
            'editor_kc_help_pan_body' => 'Mantén Espacio y arrastra, o arrastra con el botón central del ratón.',
            'editor_kc_help_zoom_label' => 'Zoom',
            'editor_kc_help_zoom_body' => 'Usa la rueda del ratón. El zoom se centra en el cursor.',
            'editor_kc_help_cancel_label' => 'Cancelar',
            'editor_kc_help_cancel_body' => 'Pulsa Esc mientras dibujas una línea para cancelarla.',
            'editor_kc_help_close' => 'Cerrar',

            // C7h: aviso de configuración de nginx en inc/main-view.php.
            'visitor_nginx_warning_heading' => 'Configuración de Telaris: regla nginx de activos versionados no instalada',
            'visitor_nginx_warning_intro' => 'Los módulos de JavaScript no se servirán. Añade este bloque al vhost nginx del servidor (sustituyendo el docroot si es diferente), y luego ejecuta %s.',
            'visitor_nginx_warning_reload' => '<code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>',
            'visitor_nginx_warning_footer' => 'Este aviso desaparece automáticamente cuando la regla sirve %s con HTTP 200.',
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
            'editor_error_no_api_key' => '⚠️ Erro: nenhuma chave de API ativa encontrada. Entre em contato com a administração do site.',
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
            'editor_error_api_key_missing_fetch' => 'Erro: a chave de API está ausente. Entre em contato com a administração do site.',
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
            'editor_gxm_status_updated_one' => '%d buraco de minhoca atualizado. Recarregue a visão de visitante para ver a mudança.',
            'editor_gxm_status_updated_many' => '%d buracos de minhoca atualizados. Recarregue a visão de visitante para ver a mudança.',
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
            'admin_tab_pluriverse' => 'Pluriverse',
            'admin_tab_api_keys' => 'Chaves de API',
            'admin_tab_php_info' => 'Informação do PHP',
            'admin_heading_users' => 'Usuárias',
            'admin_btn_new_user' => 'Nova conta',
            'admin_btn_bulk_import' => 'Importação em lote',
            'admin_label_search' => 'Buscar:',
            'admin_placeholder_search_users' => 'Buscar contas...',
            'admin_msg_no_users' => 'Nenhuma conta encontrada.',
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
            'admin_confirm_delete_user' => 'Tem certeza de que deseja excluir a conta "%s"? Esta ação não pode ser desfeita.',
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
            'admin_label_instance_name' => 'Nome',
            'admin_help_instance_name' => 'Nome público desta instância. Aparece no lado visitante e é usado como rótulo no diretório do Pluriverse ao solicitar publicação. Se ficar em branco, usa-se o primeiro rótulo do nome de host.',
            'admin_label_pdf_max' => 'Tamanho máximo de PDF (MB)',
            'admin_help_pdf_max' => "Maior PDF que um buraco de minhoca pode conter. Padrão 25 MB. Ao enviar arquivos maiores aparece o erro 'O arquivo excede o tamanho máximo permitido'.",
            'admin_btn_save_settings' => 'Salvar configurações',
            // Pluriverse tab.
            'admin_pluriverse_heading' => 'Junte-se ao Pluriverse',
            'admin_pluriverse_subheading' => 'Federe esta instância no Pluriverse para que apareça no diretório público em www.telaris.ca. A solicitação carrega sua URL, nome, contato da operação e galáxias escolhidas, assinada pela pluriverse.key desta instância.',
            'admin_pluriverse_status_heading' => 'Status da associação',
            'admin_pluriverse_status_status' => 'Status',
            'admin_pluriverse_status_submitted' => 'Enviada',
            'admin_pluriverse_status_name' => 'Nome',
            'admin_pluriverse_status_email' => 'E-mail da operação',
            'admin_pluriverse_status_fingerprint' => 'Impressão da chave pública guardada',
            'admin_pluriverse_status_help' => 'Verifique seu e-mail da operação para o link de verificação. Tanto o link quanto a solicitação pendente expiram 24 horas após o envio. A administração do Pluriverse revisa a solicitação após a verificação e avisa quando a instância for publicada.',
            'admin_pluriverse_status_expired_heading' => 'Solicitação de entrada expirada',
            'admin_pluriverse_status_expired_body' => 'O link de verificação da sua última solicitação de entrada não foi aberto em 24 horas, então a solicitação expirou. Você pode enviar uma nova com o botão abaixo; vai receber um novo e-mail de verificação no seu endereço da operação.',
            'admin_pluriverse_btn_rejoin' => 'Voltar a entrar na Pluriverse',
            'admin_pluriverse_field_url_label' => 'URL da instância',
            'admin_pluriverse_field_url_help' => 'URL https canônica desta instância. O nome de host é derivado daqui.',
            'admin_pluriverse_field_name_label' => 'Nome',
            'admin_pluriverse_field_name_help' => 'Nome público curto para esta instância, único em todo o Pluriverse. Se já estiver em uso, será pedido outro.',
            'admin_pluriverse_field_email_label' => 'E-mail da operação',
            'admin_pluriverse_field_email_help' => 'Destino do link mágico. Criptografado em repouso no Pluriverse. Edite se preferir um endereço diferente da conta de administração.',
            'admin_pluriverse_field_framing_label' => 'Enquadramento editorial',
            'admin_pluriverse_field_framing_help' => 'Uma ou três frases. Para que serve esta instância? Opcional.',
            'admin_pluriverse_field_galaxies_label' => 'Galáxias publicáveis',
            'admin_pluriverse_field_galaxies_summary' => '%d galáxias desta instância serão publicadas. Novas galáxias são adicionadas automaticamente conforme você as cria.',
            'admin_pluriverse_field_galaxies_empty' => 'Ainda não há galáxias. A solicitação registra esta instância agora; novas galáxias são captadas automaticamente conforme você as cria.',
            'admin_pluriverse_field_galaxies_disclosure' => 'Ver a lista',
            'admin_pluriverse_field_contacts_label' => 'Contatos secundários',
            'admin_pluriverse_field_contacts_help' => 'Canais alternativos opcionais (Matrix, XMPP, etc.). Até oito.',
            'admin_pluriverse_btn_add_contact' => 'Adicionar outro',
            'admin_pluriverse_contact_service_placeholder' => 'serviço',
            'admin_pluriverse_contact_handle_placeholder' => 'identificador / endereço',
            'admin_pluriverse_btn_submit' => 'Juntar-se ao Pluriverse',
            'admin_pluriverse_submit_help' => 'Esta instância assinará a solicitação com sua pluriverse.key (Ed25519) e a enviará para www.telaris.ca. O Pluriverse enviará um link de verificação ao e-mail da operação.',
            'admin_pluriverse_link_change_name' => '(alterar em Configurações globais)',
            'admin_heading_download_backup' => 'Baixar um backup',
            'admin_help_download_backup' => 'Crie um arquivo de backup portátil com galáxias e/ou contas. A opção padrão produz um backup completo com mídia incorporada.',
            'admin_label_galaxies' => 'Galáxias',
            'admin_label_all_galaxies' => 'Todas as galáxias',
            'admin_label_selected_galaxies' => 'Apenas galáxias selecionadas',
            'admin_msg_loading_galaxies' => 'Carregando galáxias...',
            'admin_btn_select_all' => 'Selecionar tudo',
            'admin_btn_clear' => 'Limpar',
            'admin_label_users_always_all' => 'Usuárias (sempre todas)',
            'admin_help_users_export' => 'As senhas das contas são exportadas como hashes. Nunca aparecem em texto plano.',
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
            'admin_label_restore_users' => 'Restaurar contas',
            'admin_label_skip_existing' => 'Pular contas existentes (combinar por e-mail)',
            'admin_label_update_existing' => 'Atualizar contas existentes por e-mail',
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
            'admin_title_galaxy_list' => 'Lista de galáxias visível para quem visita',
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
            'admin_text_no_admin_user_warn' => '(sem conta de administração!)',
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
            'admin_confirm_no_admin' => 'AVISO: este snapshot não tem conta de administração. Restaurá-lo bloqueará o acesso ao console de administração. Continuar mesmo assim?',
            'admin_toast_restore_complete_logout' => 'Restauração completa. Sua sessão pode ser encerrada.',
            'admin_toast_restore_complete_report' => 'Restauração completa. %s galáxias criadas, %s contas. %s snapshot(s) posterior(es) excluído(s). Sua sessão pode ser encerrada.',
            'admin_toast_failed_load_galaxies' => 'Falha ao carregar galáxias: %s',
            'admin_toast_saved_cron_warning' => 'Salvo, mas o agendador não conseguiu registrar com o cron: %s',
            'admin_toast_schedule_saved' => 'Agendamento salvo.',
            'admin_toast_save_schedule_failed' => 'Falha ao salvar o agendamento: %s',
            // C4: admin/index.php (modais)
            'admin_modal_heading_bulk_users' => 'Importar contas em lote',
            'admin_modal_bulk_users_imported_one' => 'Importou-se <strong>%d</strong> conta.',
            'admin_modal_bulk_users_imported_many' => 'Importaram-se <strong>%d</strong> contas.',
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
            'admin_modal_bulk_users_form_intro' => 'Cole uma lista de contas, uma por linha, com colunas separadas por vírgula. Apenas o e-mail é obrigatório; o resto é opcional.',
            'admin_modal_bulk_users_field_email' => '<strong>e-mail</strong>: obrigatório',
            'admin_modal_bulk_users_field_first_name' => '<strong>primeiro nome</strong>',
            'admin_modal_bulk_users_field_last_name' => '<strong>sobrenome</strong>',
            'admin_modal_bulk_users_field_type' => '<strong>tipo</strong>: <code>Editor</code> (padrão) ou <code>Admin</code>',
            'admin_modal_bulk_users_field_create_galaxy' => '<strong>criar galáxia?</strong>: <code>sim</code> / <code>não</code>. Vazio herda a caixa abaixo; um valor aqui a substitui.',
            'admin_modal_bulk_users_example_label' => '<strong>Exemplo:</strong>',
            'admin_modal_bulk_users_footer_help' => 'Cada nova conta recebe um e-mail de boas-vindas com um link de configuração de uso único (TTL de 7 dias) para definir a senha. Quando uma galáxia é criada e associada, o e-mail inclui também a URL da galáxia e o link de login. E-mails já existentes são ignorados; linhas que começam com <code>#</code> são ignoradas.',
            'admin_modal_bulk_users_textarea_placeholder' => 'e-mail, nome, sobrenome, tipo, criar-galáxia',
            'admin_modal_bulk_users_label_create_galaxy_each' => 'Criar uma galáxia para cada nova conta',
            'admin_modal_bulk_users_help_create_galaxy_each' => 'O slug é tomado do nome do e-mail (antes do <code>@</code>); colisões recebem um sufixo aleatório curto. As contas de edição ficam atribuídas à própria galáxia; as contas de administração já veem todas as galáxias. Substitua por linha na 5.ª coluna.',
            'admin_modal_heading_create_user' => 'Criar nova conta',
            'admin_modal_label_first_name' => 'Primeiro nome *',
            'admin_modal_help_first_name' => 'O nome próprio associado à conta.',
            'admin_modal_label_last_name' => 'Sobrenome *',
            'admin_modal_help_last_name' => 'O sobrenome associado à conta.',
            'admin_modal_label_email' => 'E-mail *',
            'admin_modal_err_email_in_use' => 'Este e-mail já está em uso.',
            'admin_modal_help_email' => 'Identificador de login e endereço de contato.',
            'admin_modal_label_password' => 'Senha *',
            'admin_modal_help_password_min' => 'Mínimo de 8 caracteres.',
            'admin_modal_label_user_type' => 'Tipo de conta *',
            'admin_modal_opt_user_type_editor' => 'Edição',
            'admin_modal_opt_user_type_admin' => 'Administração',
            'admin_modal_help_user_type' => 'Edição: só pode editar buracos de minhoca nas galáxias atribuídas | Administração: acesso completo a todas as galáxias.',
            'admin_modal_label_create_galaxy_for_user' => 'Criar uma nova galáxia para esta conta',
            'admin_modal_help_create_galaxy_for_user' => 'Cria-se uma galáxia com o nome abaixo e concede-se acesso a ela (apenas para contas de edição).',
            'admin_modal_label_new_galaxy_name' => 'Nome da galáxia *',
            'admin_modal_placeholder_new_galaxy_name' => 'Por padrão, o e-mail acima',
            'admin_modal_help_new_galaxy_name' => 'Nome para a galáxia criada automaticamente.',
            'admin_modal_label_galaxy_access_editors' => 'Acesso a galáxias (apenas para contas de edição)',
            'admin_modal_help_galaxy_access_editors' => 'As contas de edição só veem e editam buracos de minhoca nas galáxias marcadas acima. As contas de administração veem todas as galáxias.',
            'admin_modal_btn_create_user' => 'Criar conta',
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
            'admin_modal_help_cluster_slug' => 'Quem visita chega a <code>/&lt;slug&gt;</code>. Se ficar vazio, é gerado a partir do nome.',
            'admin_modal_placeholder_cluster_tagline' => 'p. ex. Um aglomerado curado',
            'admin_modal_opt_cluster_theme_cosmic' => 'Cósmico',
            'admin_modal_opt_cluster_theme_abstract' => 'Abstrato',
            'admin_modal_opt_cluster_theme_rectangles' => 'Retângulos',
            'admin_modal_opt_cluster_theme_stripes' => 'Faixas',
            'admin_modal_opt_cluster_theme_tech' => 'Tecnológico',
            'admin_modal_help_cluster_theme' => 'Tema da cena. O ícone de cada buraco de minhoca continua usando o tema da galáxia de origem.',
            'admin_modal_label_show_galaxy_list' => 'Mostrar a lista de galáxias a quem visita',
            'admin_modal_help_show_galaxy_list' => 'Quando ativado, quem visita vê uma lista das galáxias membras do aglomerado no canto inferior direito; ao clicar, atenuam-se os buracos de minhoca de outras galáxias. Desativado por padrão para aglomerados, pois o enquadramento curado costuma ler-se como uma experiência única.',
            'admin_modal_label_member_galaxies' => 'Galáxias membras *',
            'admin_modal_help_member_galaxies' => 'Quem visita vê a união dos buracos de minhoca destas galáxias. Pontes (linhas tracejadas sutis) conectam buracos de minhoca que compartilham texto de palavra-chave entre galáxias.',
            'admin_modal_count_selected_one' => '%d selecionada',
            'admin_modal_count_selected_many' => '%d selecionadas',
            'admin_modal_label_keyword_chips' => 'Fichas de palavras-chave',
            'admin_modal_help_keyword_chips' => 'Reúne as palavras-chave mais usadas em todos os buracos de minhoca visíveis (todas as galáxias membras) numa tira de fichas de filtro no topo do aglomerado. Clique numa ficha para atenuar os buracos de minhoca que não correspondem.',
            'admin_modal_label_related_wormholes' => 'Buracos de minhoca relacionados',
            'admin_modal_help_related_wormholes' => 'Quando o cartão de informações de um buraco de minhoca está aberto, atenua os não relacionados e exibe até 5 buracos de minhoca relacionados (que compartilham palavras-chave) como fichas de salto na parte inferior do cartão. Reúne em todo o aglomerado; as fichas podem surgir de qualquer galáxia membra.',
            'admin_modal_label_2d_view' => 'Interruptor de vista 2D',
            'admin_modal_help_2d_view' => 'Mostra um alternador "3D / 2D" no topo central para passar da cena 3D para uma grade plana de fichas de buracos de minhoca. A preferência de cada visita persiste no navegador.',
            'admin_modal_label_idle_spotlight' => 'Holofote em inatividade',
            'admin_modal_help_idle_spotlight' => 'Após um período de inatividade, a câmara voa para um buraco de minhoca aleatório em qualquer parte do aglomerado e abre o cartão de informações. Fecha quando o conteúdo termina ou após o temporizador de permanência.',
            'admin_modal_label_pick_from' => 'Escolher entre',
            'admin_modal_opt_pick_all_wormholes' => 'Todos os buracos de minhoca (em todas as galáxias membras)',
            'admin_modal_opt_pick_accentuated' => 'Apenas buracos de minhoca destacados',
            'admin_modal_label_trigger_after_seconds' => 'Acionar após (segundos de inatividade)',
            'admin_modal_label_auto_tour' => 'Passeio automático',
            'admin_modal_title_preview_tour' => 'Salve primeiro e depois pré-visualize o passeio numa nova aba',
            'admin_modal_btn_preview_tour' => 'Pré-visualizar passeio',
            'admin_modal_help_auto_tour' => 'Leva automaticamente por buracos de minhoca em todo o aglomerado, abrindo cada cartão e reproduzindo o conteúdo. Apenas desktop e iPad.',
            'admin_modal_label_start_mode' => 'Modo de início',
            'admin_modal_opt_start_manual' => 'Manual. Começa ao clicar num botão de reprodução.',
            'admin_modal_opt_start_idle' => 'Inativa. Começa após um período de inatividade.',
            'admin_modal_opt_start_immediate' => 'Imediato. Começa alguns segundos após o aglomerado carregar.',
            'admin_modal_label_idle_threshold' => 'Limite de inatividade (segundos)',
            'admin_modal_warn_immediate_audio' => 'Uma ou mais galáxias membras contêm buracos de minhoca com áudio. Os navegadores bloqueiam a reprodução automática com som até que haja alguma interação com a página, então o primeiro áudio num passeio de início imediato pode ficar em silêncio ou travar.',
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
            'admin_modal_heading_edit_user' => 'Editar conta',
            'admin_modal_label_password_optional' => 'Senha (deixe em branco para manter a atual)',
            'admin_modal_btn_update_user' => 'Atualizar conta',
            'admin_modal_heading_duplicate_galaxy' => 'Duplicar galáxia',
            'admin_modal_label_duplicating' => 'Duplicando:',
            'admin_modal_label_new_name' => 'Novo nome *',
            'admin_modal_label_new_url_slug' => 'Novo slug de URL',
            'admin_modal_label_new_tagline' => 'Novo slogan',
            'admin_modal_btn_duplicate' => 'Duplicar',
            'admin_modal_heading_confirm_deletion' => 'Confirmar exclusão',
            'admin_modal_label_type_galaxy_name' => 'Digite o nome da galáxia para confirmar:',
            'admin_modal_label_type_to_confirm' => 'Para confirmar, digite exatamente o seguinte:',
            'admin_modal_placeholder_type_name' => 'Digite o nome aqui...',
            'admin_modal_btn_delete' => 'Excluir',
            'admin_modal_deletion_impact_title' => '⚠️ Impacto da exclusão:',
            'admin_modal_deletion_impact_intro' => 'Os seguintes portais em outras galáxias apontam para esta rede e também serão excluídos:',
            'admin_modal_deletion_impact_row' => '<strong>%s</strong> (na galáxia: %s)',
            'admin_error_user_not_found' => 'Conta não encontrada.',
            'admin_error_galaxy_not_found' => 'Galáxia não encontrada.',
            'admin_error_delete_confirm_mismatch' => 'A confirmação não coincide. Digite o nome exato para confirmar a exclusão.',
            'admin_setup_perms_heading' => 'Próximo passo (fortalecimento do host):',
            'admin_setup_perms_intro' => 'config.php agora está no modo',
            'admin_setup_perms_advice' => 'Execute sudo php bin/setup-host.php a partir da raiz do site para aplicar a configuração canônica do host (snippet do nginx, regra do logrotate e 0640 proprietário=operador em config.php).',

            // C5: admin/setup.php (post-DB)
            'admin_setup_website_info_subtitle' => 'Configure as informações do site',
            'admin_setup_db_tables_created' => '✓ Tabelas do banco de dados criadas com sucesso!',
            'admin_setup_website_name_label' => 'Nome do site',
            'admin_setup_website_name_help' => 'O nome do site ou projeto. Padrão: Telaris',
            'admin_setup_tagline_label' => 'Slogan',
            'admin_setup_tagline_help' => 'Uma descrição curta ou slogan. Padrão: Tecendo memória',
            'admin_setup_website_info_footer_help' => 'Estes valores são usados para a galáxia padrão e as informações do projeto. É possível alterá-los depois em Admin → Configurações globais e Galáxias.',
            'admin_setup_website_info_continue' => 'Continuar',
            'admin_setup_schema_details_heading' => 'Detalhes da criação do esquema',
            'admin_setup_schema_db_created' => 'Banco de dados <strong>%s</strong> criado com sucesso',
            'admin_setup_schema_db_exists' => 'O banco de dados <strong>%s</strong> já existe',
            'admin_setup_schema_tables_created_one' => 'Tabela criada (%d):',
            'admin_setup_schema_tables_created_many' => 'Tabelas criadas (%d):',
            'admin_setup_schema_tables_existed_one' => 'Tabela já existente (%d):',
            'admin_setup_schema_tables_existed_many' => 'Tabelas já existentes (%d):',
            'admin_setup_schema_no_tables' => 'Nenhuma tabela foi criada ou ignorada.',
            'admin_setup_schema_api_key_heading' => '✓ Chave de API padrão gerada',
            'admin_setup_schema_api_key_help' => 'Uma chave de API padrão foi gerada automaticamente e já está em uso. As chaves de API podem ser gerenciadas na página de gerenciamento de chaves de API.',
            'admin_setup_admin_user_heading' => 'Criar conta de administração',
            'admin_setup_admin_user_intro' => 'Ainda não existe nenhuma conta de administração. Crie uma para acessar o console de administração.',
            'admin_setup_first_name_label' => 'Nome *',
            'admin_setup_last_name_label' => 'Sobrenome *',
            'admin_setup_email_label' => 'E-mail *',
            'admin_setup_email_help' => 'Este será o e-mail de acesso.',
            'admin_setup_password_label' => 'Senha *',
            'admin_setup_password_help' => 'Mínimo 8 caracteres',
            'admin_setup_confirm_password_label' => 'Confirmar senha *',
            'admin_setup_create_admin_btn' => 'Criar conta de administração',
            'admin_setup_admin_user_created' => '✓ Conta de administração criada com sucesso!',
            'admin_setup_admin_user_can_login' => 'Já é possível entrar na %s.',
            'admin_setup_admin_user_login_link' => 'página de login',
            'admin_setup_config_created_flash' => '✓ Arquivo de configuração criado com sucesso!',
            'admin_setup_complete_with_schema' => 'Instalação completa. Esquema do banco de dados criado e informações do projeto inicializadas.',
            'admin_setup_complete_no_schema' => 'Instalação completa. Informações do projeto inicializadas.',
            'admin_setup_db_error_prefix' => 'Erro no banco de dados:',
            'admin_setup_error_prefix' => 'Erro:',
            'admin_setup_status_heading' => 'Status da instalação:',
            'admin_setup_config_file_label' => 'Arquivo de configuração:',
            'admin_setup_config_file_created' => '✓ Criado',
            'admin_setup_config_file_missing' => '✗ Ausente',
            'admin_setup_db_connection_label' => 'Conexão com o banco de dados:',
            'admin_setup_db_connection_connected' => '✓ Conectado',
            'admin_setup_db_connection_failed' => '✗ Falhou',
            'admin_setup_project_info_label' => 'Informações do projeto:',
            'admin_setup_project_info_initialized' => '✓ Inicializadas',
            'admin_setup_project_info_not_initialized' => '✗ Não inicializadas',
            'admin_setup_link_go_to_telaris' => 'Ir para Telaris →',
            'admin_setup_link_admin_console' => 'Console de administração',
            'admin_setup_link_reconfigure_db' => 'Reconfigurar banco de dados',
            'admin_setup_validation_all_fields_required' => 'Todos os campos são obrigatórios.',
            'admin_setup_validation_passwords_mismatch' => 'As senhas não coincidem.',
            'admin_setup_validation_password_too_short' => 'A senha deve ter pelo menos 8 caracteres.',
            'admin_setup_validation_db_unavailable' => 'Conexão com o banco de dados indisponível.',

            // C5b: utils/login.php + utils/forgot.php + utils/reset.php
            'auth_login_page_title' => 'Login - Telaris',
            'auth_login_heading' => 'Login no Telaris',
            'auth_login_subtitle' => 'Acesse o espaço da constelação',
            'auth_email_label' => 'E-mail',
            'auth_password_label' => 'Senha',
            'auth_login_submit' => 'Entrar',
            'auth_login_forgot_link' => 'Esqueceu a senha?',
            'auth_login_back_link' => '← Voltar à constelação',
            'auth_error_invalid_request' => 'Requisição inválida. Recarregue a página e tente de novo.',
            'auth_error_throttled' => 'Muitas tentativas. Tente novamente mais tarde.',
            'auth_login_error_required' => 'O e-mail e a senha são obrigatórios',
            'auth_login_error_invalid' => 'E-mail ou senha inválidos. Apenas contas de edição e de administração podem entrar aqui.',
            'auth_forgot_page_title' => 'Redefinir senha - Telaris',
            'auth_forgot_heading' => 'Recuperar senha',
            'auth_forgot_subtitle' => 'Vamos enviar um link de uso único para definir uma senha nova.',
            'auth_forgot_generic_notice' => 'Se existir uma conta com esse e-mail, um link para redefinir a senha foi enviado.',
            'auth_forgot_error_invalid_email' => 'Informe um endereço de e-mail válido.',
            'auth_forgot_submit' => 'Enviar link de redefinição',
            'auth_forgot_back_link' => '← Voltar ao login',
            'auth_email_subject' => 'Redefina sua senha do %s',
            'auth_email_greeting_named' => 'Olá %s,',
            'auth_email_greeting_anon' => 'Olá,',
            'auth_email_intro' => 'Recebemos um pedido para redefinir sua senha. Clique no link para definir uma nova:',
            'auth_email_expiry' => 'O link expira em 24 horas e só pode ser usado uma vez. Se você não solicitou a redefinição, pode ignorar este e-mail; sua senha não mudará.',
            'auth_email_text_intro' => "Recebemos um pedido para redefinir sua senha.\n\nLink de redefinição (24h, uso único):\n",
            'auth_email_text_outro' => "\n\nSe você não solicitou a redefinição, ignore este e-mail.",
            'auth_reset_page_title' => 'Definir nova senha - Telaris',
            'auth_reset_heading' => 'Definir nova senha',
            'auth_reset_success_message' => 'Senha atualizada. Já é possível entrar com a nova senha.',
            'auth_reset_btn_go_to_login' => 'Ir para o login',
            'auth_reset_invalid_token_message' => 'Este link de redefinição é inválido ou expirou. Solicite um novo.',
            'auth_reset_btn_request_new_link' => 'Solicitar um link novo',
            'auth_reset_intro_html' => 'Definindo uma nova senha para <strong>%s</strong>.',
            'auth_reset_new_password_label' => 'Nova senha',
            'auth_reset_password_help' => 'Pelo menos 8 caracteres.',
            'auth_reset_confirm_password_label' => 'Confirmar nova senha',
            'auth_reset_submit' => 'Atualizar senha',
            'auth_reset_error_password_too_short' => 'A senha precisa ter pelo menos 8 caracteres.',
            'auth_reset_error_password_mismatch' => 'As senhas não coincidem.',

            // C7a: inc/partials/galaxy-edit-modal.php
            'gem_heading' => 'Editar galáxia',
            'gem_name_label' => 'Nome *',
            'gem_name_duplicate_error' => 'Este nome já está em uso.',
            'gem_tagline_label' => 'Lema',
            'gem_slug_label' => 'Caminho da URL',
            'gem_slug_placeholder' => 'ex. arquivo',
            'gem_slug_duplicate_error' => 'Este caminho já está em uso.',
            'gem_slug_help' => 'Caminho de URL personalizado. Se ficar em branco, é gerado a partir do nome. Apenas letras, números e hifens.',
            'gem_theme_label' => 'Tema visual',
            'gem_theme_cosmic' => 'Cósmico (estrelas, planetas, foguetes)',
            'gem_theme_simple' => 'Simples (esferas coloridas)',
            'gem_theme_abstract' => 'Abstrato (ícones GIF geométricos)',
            'gem_theme_rectangles' => 'Retângulos (ícones retangulares personalizados)',
            'gem_theme_stripes' => 'Listras (ícones de listras personalizados)',
            'gem_theme_tech' => 'Tech (ícones de placa de circuito)',
            'gem_tags_label' => 'Tags',
            'gem_tags_placeholder' => 'Adicionar tag...',
            'gem_tags_help' => 'Quem visita pode explorar a união de todas as galáxias com uma tag em <code>/tag/&lt;tag&gt;</code>. Digite para adicionar; pressione Enter ou vírgula. As sugestões mostram tags já usadas nesta galáxia e em galáxias irmãs que compartilham o prefixo <code>[XX]</code>.',
            'gem_bulk_actions_label' => 'Ações em massa sobre buracos de minhoca',
            'gem_bulk_actions_help' => 'Aplicam-se a todos os buracos de minhoca desta galáxia de uma só vez. As alternâncias individuais podem substituí-las depois.',
            'gem_bulk_use_images_btn' => 'Usar imagens como ícones (todos os buracos de minhoca)',
            'gem_bulk_revert_icons_btn' => 'Reverter todos aos ícones do tema',
            'gem_keyword_chips_label' => 'Fichas de palavras-chave',
            'gem_keyword_chips_help' => 'Mostra as palavras-chave mais usadas como fichas de filtro no topo da galáxia. Clique numa ficha para atenuar os buracos de minhoca que não correspondem.',
            'gem_related_label' => 'Buracos de minhoca relacionados',
            'gem_related_help' => 'Quando o cartão de informações de um buraco de minhoca está aberto, atenua os não relacionados na cena e mostra até 5 relacionados (que compartilham palavras-chave) como fichas para saltar na parte de baixo do cartão. Cada vez aparece uma amostra aleatória.',
            'gem_2d_view_label' => 'Alternador de vista 2D',
            'gem_2d_view_help' => 'Mostra um alternador "3D / 2D" no topo central para passar da cena 3D para uma grade plana de fichas de buracos de minhoca. A preferência persiste no navegador.',
            'gem_idle_spotlight_label' => 'Foco em inatividade',
            'gem_idle_spotlight_help' => 'Após um período de inatividade, a câmara voa para um buraco de minhoca aleatório e abre o cartão de informações. Fecha quando o conteúdo termina ou após o temporizador de permanência.',
            'gem_pick_from_label' => 'Escolher entre',
            'gem_idle_pick_all' => 'Todos os buracos de minhoca',
            'gem_idle_pick_accentuated' => 'Apenas buracos de minhoca destacados',
            'gem_idle_trigger_label' => 'Acionar após (segundos de inatividade)',
            'gem_autotour_label' => 'Passeio automático',
            'gem_autotour_preview_btn' => 'Pré-visualizar passeio',
            'gem_autotour_preview_title' => 'Salve primeiro e depois pré-visualize o passeio numa nova aba',
            'gem_autotour_help' => 'Navega automaticamente pelos nós, abrindo cada cartão e reproduzindo o conteúdo. Apenas desktop e iPad.',
            'gem_start_mode_label' => 'Modo de início',
            'gem_start_mode_manual' => 'Manual. Começa ao clicar num botão de reprodução.',
            'gem_start_mode_idle' => 'Inativo. Começa após um período de inatividade.',
            'gem_start_mode_immediate' => 'Imediato. Começa alguns segundos depois de a galáxia carregar.',
            'gem_idle_threshold_label' => 'Limite de inatividade (segundos)',
            'gem_immediate_audio_warning' => 'Esta galáxia contém nós com áudio. Os navegadores bloqueiam a reprodução automática com som até que haja alguma interação com a página, então o primeiro áudio num passeio de início imediato pode ficar em silêncio ou travar.',
            'gem_which_nodes_label' => 'Quais nós incluir no passeio',
            'gem_nodes_all' => 'Todos os nós (ordem aleatória em cada execução)',
            'gem_nodes_accentuated' => 'Apenas nós destacados',
            'gem_nodes_random_n' => 'Uma amostra aleatória de N nós',
            'gem_nodes_tagged' => 'Nós marcados com uma destas palavras-chave',
            'gem_random_count_label' => 'Quantos nós por passeio',
            'gem_keywords_label' => 'Palavras-chave (qualquer correspondência)',
            'gem_keywords_help' => 'Aparecem os nós que correspondem a qualquer uma das palavras-chave selecionadas.',
            'gem_dwell_label' => 'Pausa em nós sem conteúdo (segundos)',
            'gem_loop_label' => 'Repetir o passeio ao terminar',
            'gem_submit_btn' => 'Atualizar galáxia',
            'gem_cancel_btn' => 'Cancelar',
            'gem_close_btn' => 'fechar',

            // C7b: títulos de erros da API (RFC 9457). Código <status-http>.<subcódigo-de-3-dígitos>.
            'api_error_400_001' => 'JSON inválido: %s',
            'api_error_400_002' => 'Falta um campo obrigatório.',
            'api_error_400_003' => 'URL inválida: apenas URLs http e https são permitidas.',
            'api_error_400_004' => 'Formato de chave de aglomerado inválido.',
            'api_error_400_005' => 'O parâmetro galaxies é incompatível com page/id.',
            'api_error_400_006' => 'O corpo da requisição está vazio.',
            'api_error_400_007' => 'O nome do nó é obrigatório.',
            'api_error_400_008' => 'O nome do nó não pode ficar vazio.',
            'api_error_400_009' => 'O id do nó é obrigatório.',
            'api_error_400_010' => 'É necessário um id de constelação.',
            'api_error_400_011' => 'É necessário um nome de constelação.',
            'api_error_400_012' => 'É necessária uma palavra-chave.',
            'api_error_400_013' => 'É necessário um id de palavra-chave.',
            'api_error_400_014' => 'A palavra-chave não pertence à constelação indicada.',
            'api_error_400_015' => 'É necessário um id de galáxia.',
            'api_error_400_016' => 'move_keyword precisa de keyword_id, x, y.',
            'api_error_400_017' => 'create_relation precisa de keyword_a_id e keyword_b_id.',
            'api_error_400_018' => 'Relações consigo mesma não são permitidas.',
            'api_error_400_019' => 'Ambas as palavras-chave precisam pertencer à mesma galáxia.',
            'api_error_400_020' => 'update_relation precisa de relation_id.',
            'api_error_400_021' => 'delete_relation precisa de relation_id.',
            'api_error_400_022' => 'reset_keyword precisa de keyword_id.',
            'api_error_400_023' => 'reset_galaxy precisa de galaxy_id.',
            'api_error_400_024' => 'delete_keyword precisa de keyword_id.',
            'api_error_400_025' => 'rename_keyword precisa de keyword_id.',
            'api_error_400_026' => 'rename_keyword precisa de um nome novo não vazio.',
            'api_error_400_027' => 'O nome da palavra-chave é longo demais (máximo 100 caracteres).',
            'api_error_400_028' => 'merge_keywords precisa de source_id e target_id.',
            'api_error_400_029' => 'Não é possível fundir uma palavra-chave consigo mesma.',
            'api_error_400_030' => 'Ação desconhecida: %s.',
            'api_error_400_031' => 'São necessários constellation_id, keyword_id e op (delete|move|count).',
            'api_error_400_032' => 'target_constellation_id é obrigatório para move.',
            'api_error_400_033' => 'Falta o nome da ponte ou é inválido.',
            'api_error_400_034' => "A ponte '%s' não está habilitada nesta instância.",
            'api_error_400_035' => 'Tipo de validação inválido.',
            'api_error_400_036' => 'Falha no envio do arquivo (código %d).',
            'api_error_400_037' => 'Falta o parâmetro phase ou é inválido.',
            'api_error_400_038' => 'Confirmação necessária.',
            'api_error_400_039' => 'Falta o id ou é inválido.',
            'api_error_400_040' => 'Falta a frase de confirmação ou está incorreta (precisa ser RESTORE).',
            'api_error_400_041' => 'Erro de codificação.',
            'api_error_400_042' => 'Não foi possível codificar a resposta.',
            'api_error_400_043' => 'Selecione ao menos galáxias ou contas para fazer o backup.',
            'api_error_400_044' => 'Formato de URL inválido. Esperava-se uma URL completa como https://hostname/api/v2.',
            'api_error_400_045' => 'Nenhuma galáxia especificada.',
            'api_error_400_046' => 'Conexão recusada com este servidor remoto: %s',

            'api_error_401_001' => 'Falta a chave de API. Forneça-a pelo cabeçalho X-API-Key, por Authorization: Bearer, ou pelo parâmetro api_key da URL.',
            'api_error_401_002' => 'Chave de API inválida.',

            'api_error_403_001' => 'As operações de escrita precisam de uma sessão autenticada. Faça login.',
            'api_error_403_002' => 'Permissões insuficientes para operações de escrita.',
            'api_error_403_003' => 'Token de segurança inválido. Recarregue a página e tente de novo.',
            'api_error_403_004' => 'Sem acesso de edição a esta galáxia.',
            'api_error_403_005' => 'Acesso negado.',
            'api_error_403_006' => 'Apenas quem criou a relação ou uma conta de administração pode editá-la.',
            'api_error_403_007' => 'Apenas quem criou a relação ou uma conta de administração pode apagá-la.',
            'api_error_403_008' => 'A verificação de existência de conta é restrita a sessões de administração.',

            'api_error_404_001' => 'Nó não encontrado.',
            'api_error_404_002' => 'Galáxia não encontrada.',
            'api_error_404_003' => 'Palavra-chave não encontrada.',
            'api_error_404_004' => 'Relação não encontrada.',
            'api_error_404_005' => 'A relação aponta para uma palavra-chave inexistente.',
            'api_error_404_006' => 'Aglomerado não encontrado.',
            'api_error_404_007' => 'Nó de origem não encontrado.',
            'api_error_404_008' => 'A galáxia de destino não existe.',
            'api_error_404_009' => 'Chave de API não encontrada.',
            'api_error_404_010' => "Falta o arquivo do manipulador da ponte '%s'.",
            'api_error_404_011' => "A ponte '%s' não tem manipulador de requisições.",
            'api_error_404_012' => 'Envio desconhecido ou expirado. Selecione o arquivo novamente.',
            'api_error_404_013' => 'O arquivo enviado está ausente. Selecione-o novamente.',
            'api_error_404_014' => 'Snapshot não encontrado.',

            'api_error_405_001' => 'Método não permitido.',

            'api_error_409_001' => 'Já existe uma palavra-chave com esse nome.',
            'api_error_409_002' => 'Já existe uma relação entre essas palavras-chave.',

            'api_error_500_001' => 'Erro interno do servidor.',
            'api_error_500_002' => 'Erro de banco de dados.',
            'api_error_500_003' => 'Não foi possível criar o diretório de envios. Verifique as permissões do servidor.',
            'api_error_500_004' => 'Não foi possível salvar o arquivo enviado.',
            'api_error_500_005' => 'Não foi possível salvar a imagem enviada.',
            'api_error_500_006' => 'Não foi possível salvar o ícone enviado.',
            'api_error_500_007' => 'Não foi possível salvar o áudio enviado.',
            'api_error_500_008' => 'Não foi possível salvar o vídeo enviado.',
            'api_error_500_009' => 'Não foi possível salvar o PDF enviado.',
            'api_error_500_010' => 'Não foi possível extrair um quadro do vídeo enviado.',
            'api_error_500_011' => 'O arquivo não parece um PDF válido.',
            'api_error_500_012' => 'Não foi possível criar o nó: não foi possível recuperar o id.',
            'api_error_500_013' => 'Não foi possível codificar os dados de animação.',
            'api_error_500_014' => 'Não foi possível codificar os dados JSON.',
            'api_error_500_015' => 'Não foi possível salvar o arquivo de backup enviado.',
            'api_error_502_001' => 'Não foi possível alcançar a API do Mocambos em %s.',

            // C7c: mensagens do resultado da atualização da galáxia.
            'galaxy_update_missing_id' => 'Falta o id da galáxia.',
            'galaxy_update_not_authorized' => 'Sem autorização.',
            'galaxy_update_no_access' => 'Sem acesso a esta galáxia.',
            'galaxy_update_name_required' => 'O nome da galáxia é obrigatório.',
            'galaxy_update_duplicate_name' => 'Já existe uma galáxia com o nome "%s".',
            'galaxy_update_duplicate_slug' => 'Já existe uma galáxia com o caminho "%s".',
            'galaxy_update_duplicate_both' => 'Já existe uma galáxia com o nome "%s" e o caminho "%s".',
            'galaxy_update_success' => 'Galáxia atualizada com sucesso.',

            // C7d: UI de administração da ponte Mocambos (chrome + strings JS).
            'mocambos_btn_import_from' => 'Importar do Mocambos',
            'mocambos_modal_heading' => 'Importar do Mocambos',
            'mocambos_label_api_url' => 'URL da API do Mocambos',
            'mocambos_help_api_url' => 'A URL base da API da instância do Mocambos (p. ex. https://hostname/api/v2). Também é possível colar a URL da documentação; /docs é removido automaticamente.',
            'mocambos_btn_connect' => 'Conectar',
            'mocambos_text_loading' => 'Obtendo as galáxias disponíveis...',
            'mocambos_btn_back' => 'Voltar',
            'mocambos_text_connected_to' => 'Conectado a:',
            'mocambos_text_select_intro' => 'Selecione as galáxias para importar. Cada uma se torna uma galáxia nova. As que já estiverem importadas são atualizadas.',
            'mocambos_text_starting_import' => 'Iniciando a importação...',
            'mocambos_text_refresh_intro' => 'Isto sincroniza os buracos de minhoca com a fonte remota do Mocambos (atualização incremental).',
            'mocambos_text_refresh_confirm_instruction' => 'Para confirmar, digite abaixo o nome da galáxia <strong id="refresh-confirm-name" class="text-gray-900">%s</strong>:',
            'mocambos_placeholder_refresh_confirm' => 'Digite o nome da galáxia para confirmar',
            'mocambos_btn_refresh' => 'Atualizar',
            'mocambos_btn_cancel' => 'Cancelar',
            'mocambos_btn_import_selected' => 'Importar seleção',
            'mocambos_btn_close' => 'Fechar',
            'mocambos_btn_modal_backdrop_close' => 'fechar',
            'mocambos_js_validation_report_title' => 'Relatório de validação da API do Mocambos',
            'mocambos_js_validation_url_prefix' => 'URL:',
            'mocambos_js_validation_date_prefix' => 'Data:',
            'mocambos_js_validating_api' => 'Validando a API...',
            'mocambos_js_enter_url' => 'Informe uma URL da API do Mocambos.',
            'mocambos_js_validation_failed_intro' => 'A validação da API falhou. Foram encontrados os seguintes problemas:',
            'mocambos_js_copied' => 'Copiado',
            'mocambos_js_copy_report' => 'Copiar relatório para a área de transferência',
            'mocambos_js_could_not_validate' => 'Não foi possível validar: %s',
            'mocambos_js_network_error' => 'Erro de rede',
            'mocambos_js_fetch_failed' => 'Não foi possível obter as galáxias',
            'mocambos_js_no_galaxias' => 'Nenhuma galáxia encontrada nesta URL.',
            'mocambos_js_badge_imported' => 'Importada',
            'mocambos_js_connect_failed' => 'Não foi possível conectar com a API do Mocambos',
            'mocambos_js_select_at_least_one' => 'Selecione ao menos uma galáxia para importar.',
            'mocambos_js_confirm_refresh_intro' => 'As seguintes galáxias serão atualizadas, substituindo todo o conteúdo atual inclusive as edições:',
            'mocambos_js_confirm_refresh_continue' => 'Continuar?',
            'mocambos_js_import_failed_generic' => 'Importação falhou',
            'mocambos_js_import_complete_status' => 'Importação concluída',
            'mocambos_js_status_label_new' => 'Nova',
            'mocambos_js_status_label_refreshed' => 'Atualizada',
            'mocambos_js_items_count' => '%d de %d itens',
            'mocambos_js_completed_success' => 'Importação concluída com sucesso.',
            'mocambos_js_completed_errors' => 'Importação concluída com alguns erros.',
            'mocambos_js_refresh_complete_log' => 'Atualização concluída.',
            'mocambos_js_refresh_complete_status' => 'Atualização concluída',
            'mocambos_js_refresh_failed_status' => 'Atualização falhou',
            'mocambos_js_missing_source' => 'Falta a informação de origem da importação para esta galáxia.',
            'mocambos_js_refreshing' => 'Atualizando "%s"...',
            'mocambos_js_error_prefix' => 'Erro: %s',
            'mocambos_js_unknown_error' => 'Erro desconhecido',

            // C7e: strings de handler.php (streamMsg HTTP, validação, saída CLI).
            'mocambos_h_resolved_mucua_names' => '%d nomes de mucua resolvidos.',
            'mocambos_h_fetching_media' => 'Obtendo itens de mídia da API do Mocambos...',
            'mocambos_h_total_items_fetched' => 'Total de itens obtidos: %d.',
            'mocambos_h_processing_galaxia' => 'Processando galáxia: %s (%d itens).',
            'mocambos_h_import_complete' => 'Importação concluída.',
            'mocambos_h_full_refresh_clearing' => 'Atualização completa; limpando os nós existentes...',
            'mocambos_h_re_importing_diff' => 'Reimportando; calculando diferenças...',
            'mocambos_h_backfilled_slugs' => '%d slugs de importação preenchidos.',
            'mocambos_h_diff_summary' => 'Diff: %d novos, %d modificados, %d removidos, %d sem alteração.',
            'mocambos_h_deleting_removed' => 'Removendo %d itens excluídos...',
            'mocambos_h_updating_modified' => 'Atualizando %d itens modificados...',
            'mocambos_h_created_constellation' => 'Constelação criada: %s (id %d).',
            'mocambos_h_adding_new_nodes' => 'Adicionando %d nós novos...',
            'mocambos_h_phase1_creating' => 'Fase 1: criando %d nós...',
            'mocambos_h_nodes_created_progress' => '  %d/%d nós criados.',
            'mocambos_h_phase1_complete' => 'Fase 1 concluída: %d/%d nós criados.',
            'mocambos_h_phase2_downloading' => 'Fase 2: baixando arquivos de mídia...',
            'mocambos_h_downloading_image' => '(%s) Baixando imagem: %s',
            'mocambos_h_downloading_video' => '(%s) Baixando vídeo: %s',
            'mocambos_h_downloading_audio' => '(%s) Baixando áudio: %s',
            'mocambos_h_phase2_complete' => 'Fase 2 concluída: %d arquivos de mídia baixados.',
            'mocambos_h_phase2_complete_with_errors' => 'Fase 2 concluída: %d arquivos de mídia baixados (%d falharam).',
            'mocambos_h_galaxia_done' => 'Galáxia %s pronta: %d/%d itens importados.',
            'mocambos_h_galaxia_done_with_errors' => 'Galáxia %s pronta: %d/%d itens importados (%d erros).',
            'mocambos_h_concurrent_import' => 'Já há uma importação em andamento para a galáxia %s; tente novamente mais tarde.',
            'mocambos_h_failed_to_create_node' => 'Não foi possível criar o nó: %s (%s).',
            'mocambos_h_media_downloads_failed' => '%d downloads de mídia falharam.',
            'mocambos_h_check_connection_failed' => 'Falha de conexão; não foi possível alcançar o servidor.',
            'mocambos_h_check_galaxia_http_fail' => 'HTTP %d; esperava-se 200. Este endpoint precisa retornar um array JSON de objetos galaxia.',
            'mocambos_h_check_galaxia_not_array' => 'A resposta não é um array JSON válido. Recebido: %s',
            'mocambos_h_check_galaxia_empty' => 'Retornou um array vazio; não há galaxias disponíveis para importar.',
            'mocambos_h_check_galaxia_missing_fields' => 'Faltam campos obrigatórios nos objetos galaxia: %s. Cada galaxia precisa ter: name, slug, default_mucua.',
            'mocambos_h_check_galaxia_ok' => '%d galaxia(s) encontrada(s). A estrutura parece correta.',
            'mocambos_h_check_mucua_http_fail' => 'HTTP %d; esperava-se 200. Este endpoint precisa retornar um array JSON de objetos mucua.',
            'mocambos_h_check_mucua_not_array' => 'A resposta não é um array JSON válido. Recebido: %s',
            'mocambos_h_check_mucua_empty' => 'Retornou um array vazio; nenhum mucua encontrado. Os downloads de mídia podem não funcionar.',
            'mocambos_h_check_mucua_missing_fields' => 'Faltam campos obrigatórios nos objetos mucua: %s. Cada mucua precisa ter: smid, slug.',
            'mocambos_h_check_mucua_ok' => '%d mucua(s) encontrada(s). A estrutura parece correta.',
            'mocambos_h_check_acervo_http_fail' => 'HTTP %d; esperava-se 200. Este endpoint precisa retornar um objeto JSON paginado com um array "items".',
            'mocambos_h_check_acervo_no_items' => 'A resposta não contém a chave "items". Esperava-se {item_count, page_count, items: [...]}. Recebido: %s',
            'mocambos_h_check_acervo_ok' => 'Retornou %d item(ns) de mídia no total. A estrutura parece correta.',
            'mocambos_h_check_blog_http_fail' => 'HTTP %d; esperava-se 200. Os artigos de blog não serão importados.',
            'mocambos_h_check_blog_no_items' => 'A resposta não contém a chave "items". Os artigos de blog não serão importados.',
            'mocambos_h_check_blog_ok' => 'Retornou %d artigo(s) de blog no total. A estrutura parece correta.',
            'mocambos_h_cli_header' => 'Importação do Mocambos',
            'mocambos_h_cli_prompt_api_base' => 'URL base da API do Mocambos',
            'mocambos_h_cli_err_api_base_required' => 'Erro: --api-base é obrigatório.',
            'mocambos_h_cli_err_usage' => 'Uso: php admin/cli/import_bridge.php mocambos --api-base=URL --galaxia=SLUG',
            'mocambos_h_cli_connecting' => 'Conectando a %s...',
            'mocambos_h_cli_fetch_galaxias_failed' => 'Não foi possível obter a lista de galaxias de %s.',
            'mocambos_h_cli_found_counts' => '%d galaxia(s) encontrada(s), %d mucua(s).',
            'mocambos_h_cli_available_galaxias_at' => 'Galaxias disponíveis em %s:',
            'mocambos_h_cli_col_slug' => 'SLUG',
            'mocambos_h_cli_col_name' => 'NOME',
            'mocambos_h_cli_col_smid' => 'SMID',
            'mocambos_h_cli_available_galaxias' => 'Galaxias disponíveis:',
            'mocambos_h_cli_already_imported' => '(já importada)',
            'mocambos_h_cli_prompt_select_galaxia' => 'Selecione o número da galaxia (ou digite o slug)',
            'mocambos_h_cli_no_galaxia_selected' => 'Nenhuma galaxia selecionada.',
            'mocambos_h_cli_err_galaxia_required' => 'Erro: --galaxia=SLUG é obrigatório.',
            'mocambos_h_cli_matched_slug' => 'Slug de galaxia correspondente: %s.',
            'mocambos_h_cli_galaxia_not_found' => 'A galaxia "%s" não foi encontrada. Use --list para ver as galaxias disponíveis.',
            'mocambos_h_cli_prompt_download_media' => 'Baixar arquivos de mídia? (mais lento, mas inclui imagens/áudio/vídeo)',
            'mocambos_h_cli_prompt_limit' => 'Limitar o número de itens? (digite um número, ou pressione Enter para todos)',
            'mocambos_h_cli_summary_galaxia' => 'Galaxia:',
            'mocambos_h_cli_summary_api' => 'API:',
            'mocambos_h_cli_summary_media' => 'Mídia:',
            'mocambos_h_cli_summary_limit' => 'Limite:',
            'mocambos_h_cli_value_skip' => 'pular',
            'mocambos_h_cli_value_download' => 'baixar',
            'mocambos_h_cli_value_all' => 'todos',
            'mocambos_h_cli_prompt_proceed' => 'Prosseguir com a importação?',
            'mocambos_h_cli_aborted' => 'Cancelado.',
            'mocambos_h_cli_galaxia_info' => 'Galaxia: %s (slug=%s, smid=%s).',
            'mocambos_h_cli_total_items' => 'Total de itens para esta galaxia: %d.',
            'mocambos_h_cli_limited_to' => 'Limitado a %d itens (--limit).',
            'mocambos_h_cli_constellation_label' => 'Constelação: %s (id %d).',
            'mocambos_h_cli_imported_summary' => 'Importados: %d/%d itens em %ss.',
            'mocambos_h_cli_errors_count' => 'Erros: %d.',
            'mocambos_h_cli_media_skipped' => 'Downloads de mídia pulados (--no-media).',
            'mocambos_h_cli_constellation_new' => 'Constelação nova criada.',
            'mocambos_h_cli_constellation_existing' => 'Constelação existente reimportada.',

            // C7f: edit/keyword-canvas.php (chrome PHP).
            'editor_kc_page_title' => 'Tela de palavras-chave',
            'editor_kc_err_missing_galaxy_id' => 'Falta <code>?galaxy_id=N</code>.',
            'editor_kc_err_galaxy_not_found' => 'Galáxia não encontrada.',
            'editor_kc_err_clusters_no_canvas' => 'Os aglomerados não têm palavras-chave próprias; a tela só se aplica a galáxias. Abra a tela numa galáxia membro.',
            'editor_kc_err_no_edit_access' => 'Sem acesso de edição a esta galáxia.',
            'editor_kc_back_link' => '← Voltar',
            'editor_kc_page_title_template' => 'Tela de palavras-chave; %s',
            'editor_kc_empty_state' => 'Esta galáxia ainda não tem palavras-chave. Adicione primeiro alguns buracos de minhoca com palavras-chave.',
            'editor_kc_mobile_block' => 'Abra a tela de palavras-chave num navegador de desktop para criar relações entre palavras-chave. As interações precisam de uma tela maior e um mouse ou trackpad.',
            'editor_kc_note_modal_title' => 'Nota de relação',
            'editor_kc_note_modal_intro' => 'Enquadramento editorial opcional; o que esta relação carrega que uma palavra-chave compartilhada não pode dizer sozinha?',
            'editor_kc_note_modal_cancel' => 'Cancelar',
            'editor_kc_note_modal_save' => 'Salvar',
            'editor_kc_keyword_modal_title' => 'Palavra-chave',
            'editor_kc_keyword_modal_new_name_label' => 'Novo nome',
            'editor_kc_keyword_modal_cancel' => 'Cancelar',
            'editor_kc_keyword_modal_delete' => 'Excluir',
            'editor_kc_keyword_modal_rename' => 'Renomear',
            'editor_kc_conflict_modal_title' => 'A palavra-chave já existe',
            'editor_kc_conflict_modal_body_suffix' => 'já existe nesta galáxia.',
            'editor_kc_conflict_modal_options_intro' => '<strong>Mudar nome</strong>: mantenha esta palavra-chave separada e escolha um nome diferente.<br><strong>Fundir</strong>: incorpore esta palavra-chave na existente; todos os buracos de minhoca marcados com ela, todas as linhas na tela, são redirecionados para a palavra-chave existente. Esta será excluída. Sem opção de desfazer.',
            'editor_kc_conflict_modal_change' => 'Mudar nome',
            'editor_kc_conflict_modal_merge' => 'Fundir',
            'editor_kc_line_modal_title' => 'Relação',
            'editor_kc_line_modal_noauth' => 'Apenas quem criou a relação ou uma conta de administração pode editá-la ou excluí-la.',
            'editor_kc_line_modal_close' => 'Fechar',
            'editor_kc_line_modal_edit' => 'Editar nota',
            'editor_kc_line_modal_delete' => 'Excluir',
            'editor_kc_backdrop_close' => 'fechar',
            'editor_kc_help_button' => 'Ajuda',
            'editor_kc_help_title' => 'Guia rápido',
            'editor_kc_help_purpose' => 'Use esta visão para mapear como as palavras-chave desta galáxia se relacionam entre si. Quanto mais perto estiverem, mais forte é sua relação. Arraste as fichas para definir a proximidade e desenhe linhas entre elas para registrar conexões semânticas específicas.',
            'editor_kc_help_intro' => 'Como usar:',
            'editor_kc_help_move_label' => 'Mover uma palavra-chave',
            'editor_kc_help_move_body' => 'Arraste uma ficha para reposicioná-la.',
            'editor_kc_help_connect_label' => 'Conectar duas palavras-chave',
            'editor_kc_help_connect_body' => 'Clique num ponto de ancoragem de uma ficha e depois num de outra. Ou arraste de um ponto ao outro.',
            'editor_kc_help_edit_label' => 'Editar ou excluir uma linha',
            'editor_kc_help_edit_body' => 'Clique numa linha existente para abri-la.',
            'editor_kc_help_pan_label' => 'Mover a visão',
            'editor_kc_help_pan_body' => 'Mantenha Espaço e arraste, ou arraste com o botão central do mouse.',
            'editor_kc_help_zoom_label' => 'Zoom',
            'editor_kc_help_zoom_body' => 'Use a roda do mouse. O zoom se centra no cursor.',
            'editor_kc_help_cancel_label' => 'Cancelar',
            'editor_kc_help_cancel_body' => 'Pressione Esc enquanto desenha uma linha para cancelá-la.',
            'editor_kc_help_close' => 'Fechar',

            // C7h: aviso de configuração de nginx em inc/main-view.php.
            'visitor_nginx_warning_heading' => 'Configuração do Telaris: regra nginx de ativos versionados não instalada',
            'visitor_nginx_warning_intro' => 'Os módulos JavaScript não serão servidos. Adicione este bloco ao vhost nginx do servidor (substituindo o docroot se for diferente), e depois execute %s.',
            'visitor_nginx_warning_reload' => '<code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>',
            'visitor_nginx_warning_footer' => 'Este aviso desaparece automaticamente quando a regra serve %s com HTTP 200.',
        ],
        'fr' => [
            'name' => 'Telaris', 'description' => 'Tisser la mémoire', 'iframe_back_text' => 'Retour',
            'alert_message' => "Tu traverses vers la Dimension Planaire\nPour explorer, fais un zoom et fais défiler dans toutes les directions\nFerme la fenêtre du navigateur pour revenir à la Dimension Cosmique.",
            'edit_button_text' => 'Modifier', 'loading_text' => 'Chargement',
            'back_button_text' => 'Retour', 'system_online_text' => 'En ligne',
            'reload_system_text' => 'Recharger', 'scan_system_text' => 'RECHERCHE...',
            'clear_scan_text' => 'Effacer la recherche', 'systems_label_text' => 'Trous de ver :',
            'hyperlinks_label_text' => 'Hyperliens :', 'initialize_auth_text' => 'Connexion',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Déconnexion',
            'click_to_view_text' => 'Clique pour voir', 'tap_to_view_text' => 'Touche encore pour voir',
            'open_portal_text' => 'Ouvrir le portail',
            'sound_label_text' => 'Son :', 'sound_on_text' => 'OUI', 'sound_off_text' => 'NON',
            'launching_text' => 'Lancement', 'mission_active_text' => 'Mission active', 'go_text' => 'GO',
            'breadcrumb_all_text' => 'Tout', 'launch_button_text' => 'LANCER',
            'no_results_text' => 'Aucun résultat', 'items_label_text' => 'éléments', 'other_label_text' => 'Autre',
            'galaxies_label_text' => 'Galaxies',
            'galaxy_count_singular_text' => '1 galaxie',
            'galaxy_count_plural_text' => '%d galaxies',
            'pdf_loading_text' => 'Chargement du PDF…',
            'pdf_rendering_text' => 'Rendu des pages…',
            'pdf_pages_singular_text' => '1 page',
            'pdf_pages_plural_text' => '%d pages',
            'pdf_open_text' => 'Ouvrir dans une nouvelle fenêtre',
            'pdf_download_text' => 'Télécharger',
            'pdf_error_load_text' => 'Impossible de charger la bibliothèque PDF.',
            'pdf_error_open_text' => 'Impossible d\'ouvrir le PDF.',
            'tour_label_text' => 'Visite',
            'tour_start_aria_text' => 'Démarrer la visite',
            'tour_previous_aria_text' => 'Précédent',
            'tour_pause_aria_text' => 'Pause',
            'tour_next_aria_text' => 'Suivant',
            'tour_exit_aria_text' => 'Quitter la visite',
            'nav_toggle_aria_text' => 'Basculer le menu de navigation',
            'share_link_title_text' => 'Copier le lien vers ce trou de ver',
            'related_label_text' => 'En lien',
            'lang_label_text' => 'Langue :',
            'node_name_fallback_text' => 'Système',
            'untitled_text' => 'Sans titre',
            'chip_open_prefix_text' => 'Ouvrir',
            'search_result_text' => 'Résultat',
            'search_results_text' => 'Résultats',
            // Editor chunk C1 (edit/index.php)
            'editor_page_title' => 'Modifier les trous de ver',
            'editor_user_role_admin' => 'Administration',
            'editor_user_role_editor' => 'Édition',
            'editor_label_current_galaxy' => 'Galaxie actuelle :',
            'editor_option_all_galaxies_admin' => 'Toutes les galaxies',
            'editor_option_all_galaxies_editor' => 'Toutes mes galaxies',
            'editor_btn_view' => 'Voir',
            'editor_btn_galaxy_settings_title' => 'Paramètres de la galaxie',
            'editor_btn_settings' => 'Paramètres',
            'editor_btn_keyword_canvas_title' => 'Créer des relations entre mots-clés',
            'editor_btn_canvas' => 'Toile',
            'editor_btn_copy_url_title' => 'Copier l\'URL de la galaxie',
            'editor_btn_admin_console' => 'Console d\'administration',
            'editor_btn_logout' => 'Déconnexion',
            'editor_error_no_api_key' => '⚠️ Erreur : aucune clé d\'API active trouvée. Contacte l\'administration du site.',
            'editor_bulk_selected_suffix' => 'trous de ver sélectionnés',
            'editor_btn_clear_selection' => 'Effacer la sélection',
            'editor_btn_bulk_move' => 'Déplacer la sélection',
            'editor_btn_bulk_duplicate' => 'Dupliquer la sélection',
            'editor_btn_bulk_delete' => 'Supprimer la sélection',
            'editor_banner_imported_read_only' => 'Cette galaxie a été importée d\'une source externe et est en lecture seule. Utilise l\'action Rafraîchir dans la liste des galaxies du panneau d\'administration pour synchroniser les changements.',
            'editor_heading_wormholes' => 'Trous de ver',
            'editor_btn_new_wormhole' => 'Nouveau trou de ver',
            'editor_btn_touched_today_title' => 'Afficher uniquement les trous de ver modifiés aujourd\'hui',
            'editor_btn_touched_today' => 'Modifiés aujourd\'hui',
            'editor_btn_bulk_keyword_title' => 'Supprimer ou déplacer en masse tout trou de ver de cette galaxie portant un mot-clé donné',
            'editor_btn_bulk_by_keyword' => 'Action en masse par mot-clé…',
            'editor_btn_shortcuts_title' => 'Raccourcis clavier (? pour ouvrir)',
            'editor_label_search' => 'Rechercher :',
            'editor_placeholder_search_wormholes' => 'Rechercher des trous de ver...',
            'editor_col_name' => 'Nom',
            'editor_col_type' => 'Type',
            'editor_col_galaxy' => 'Galaxie',
            'editor_col_url' => 'URL',
            'editor_col_keywords' => 'Mots-clés',
            'editor_col_created' => 'Création',
            'editor_col_updated' => 'Modification',
            'editor_col_actions' => 'Actions',
            'editor_col_acc' => 'Acc',
            'editor_col_acc_title' => 'État d\'accentuation',
            'editor_msg_loading_wormholes' => 'Chargement des trous de ver...',
            'editor_msg_retrieving_wormholes' => 'Récupération des trous de ver...',
            'editor_heading_no_wormholes' => 'Aucun trou de ver trouvé.',
            'editor_text_empty_state_help' => 'Ajuste la recherche ou ajoute un nouveau trou de ver pour commencer.',
            'editor_heading_error_loading' => 'Erreur lors du chargement des trous de ver',
            'editor_error_api_key_missing' => 'La clé d\'API est manquante.',
            'editor_error_api_key_missing_fetch' => 'Erreur : la clé d\'API est manquante. Contacte l\'administration du site.',
            'editor_error_invalid_json' => 'Réponse JSON invalide du serveur',
            'editor_error_invalid_format' => 'Format de réponse invalide',
            'editor_error_invalid_data_format' => 'Erreur : format de données invalide reçu.',
            'editor_text_no_keywords' => 'Aucun mot-clé',
            'editor_label_node_type_portal' => 'Portail',
            'editor_label_node_type_object' => 'Objet',
            'editor_badge_accentuated' => 'ACC',
            'editor_badge_accentuated_title' => 'Trou de ver accentué',
            'editor_badge_has_url' => 'URL',
            'editor_badge_has_url_title' => 'A une URL',
            'editor_badge_has_desc' => 'DESC',
            'editor_badge_has_desc_title' => 'A une description',
            'editor_badge_has_img' => 'IMG',
            'editor_badge_has_img_title' => 'A une image',
            'editor_badge_has_emb' => 'EMB',
            'editor_badge_has_emb_title' => 'A une intégration',
            'editor_badge_has_aud' => 'AUD',
            'editor_badge_has_aud_title' => 'A un audio',
            'editor_badge_has_vid' => 'VID',
            'editor_badge_has_vid_title' => 'A une vidéo',
            'editor_title_accentuated' => 'Accentué',
            'editor_action_view_wormhole' => 'Voir le trou de ver',
            'editor_action_view_galaxy' => 'Voir la galaxie',
            'editor_action_edit' => 'Modifier',
            'editor_action_duplicate' => 'Dupliquer',
            'editor_action_delete' => 'Supprimer',
            'editor_toast_bulk_move_success' => '%d trous de ver déplacés.',
            'editor_toast_bulk_move_failed' => 'Échec du déplacement de %d trous de ver.',
            'editor_toast_bulk_move_error' => 'Une erreur est survenue lors du déplacement en masse.',
            'editor_toast_duplicate_success' => 'Trou de ver dupliqué.',
            'editor_error_failed_duplicate' => 'Échec de la duplication',
            'editor_toast_duplicate_error_generic' => 'Une erreur est survenue lors de la duplication.',
            'editor_toast_bulk_duplicate_success' => '%d trous de ver dupliqués.',
            'editor_toast_bulk_duplicate_failed' => 'Échec de la duplication de %d trous de ver.',
            'editor_toast_bulk_duplicate_error' => 'Une erreur est survenue lors de la duplication en masse.',
            'editor_confirm_bulk_delete' => 'Confirmer la suppression de %d trous de ver sélectionnés ? Cette action est irréversible.',
            'editor_toast_bulk_delete_success' => '%d trous de ver supprimés.',
            'editor_toast_bulk_delete_failed' => 'Échec de la suppression de %d trous de ver.',
            'editor_toast_bulk_delete_error' => 'Une erreur est survenue lors de la suppression en masse.',
            'editor_toast_url_copied' => 'URL copiée dans le presse-papiers',
            'editor_title_url_copied' => 'Copié !',
            'editor_toast_galaxy_created' => 'Galaxie « %s » créée.',
            'editor_toast_error_creating_galaxy' => 'Erreur lors de la création de la galaxie : %s',
            'editor_prompt_new_galaxy_name' => 'Nom de la nouvelle galaxie :',
            'editor_modal_heading_add_wormhole' => 'Ajouter un nouveau trou de ver',
            'editor_modal_heading_edit_wormhole' => 'Modifier le trou de ver',
            'editor_label_name_required' => 'Nom *',
            'editor_error_name_exists' => 'Ce nom de trou de ver existe déjà dans cette galaxie.',
            'editor_help_name' => 'Titre principal du trou de ver affiché dans le réseau.',
            'editor_label_galaxy' => 'Galaxie',
            'editor_help_constellation' => 'À quelle galaxie ce trou de ver appartient.',
            'editor_label_wormhole_type' => 'Type de trou de ver',
            'editor_help_node_type' => 'Objet est un élément standard ; Portail mène à une autre galaxie.',
            'editor_label_keywords' => 'Mots-clés',
            'editor_placeholder_add_keyword' => 'Ajouter un mot-clé...',
            'editor_help_keywords_add' => 'Tape et appuie sur Entrée ou virgule pour ajouter des mots-clés. Les suggestions montrent les mots-clés déjà utilisés dans cette galaxie et dans les galaxies sœurs partageant ton préfixe `[XX]`.',
            'editor_label_accentuate_wormhole' => 'Accentuer le trou de ver',
            'editor_help_accentuate' => 'Rend ce trou de ver plus grand et plus visible dans le réseau.',
            'editor_label_show_keywords' => 'Afficher les mots-clés',
            'editor_help_show_keywords' => 'Affiche les mots-clés de ce trou de ver dans sa fenêtre d\'informations.',
            'editor_label_target_galaxy' => 'Galaxie cible',
            'editor_help_target_galaxy' => 'La galaxie de destination vers laquelle ce portail mène.',
            'editor_btn_create_new_galaxy' => 'Créer une nouvelle galaxie',
            'editor_label_description' => 'Description',
            'editor_help_description' => 'Texte détaillé affiché lorsque le trou de ver est sélectionné.',
            'editor_label_url' => 'URL',
            'editor_placeholder_url' => 'https://example.com',
            'editor_help_url' => 'URL à ouvrir lorsque le trou de ver est cliqué (facultatif).',
            'editor_label_primary_visual' => 'Visuel principal',
            'editor_tab_image' => 'Image',
            'editor_tab_video' => 'Vidéo (MP4)',
            'editor_tab_pdf' => 'PDF',
            'editor_help_visual_mutex' => 'Choisis-en un. Changer d\'onglet et enregistrer efface les autres.',
            'editor_label_image_url_file' => 'URL ou fichier d\'image',
            'editor_label_use_as_icon' => 'Utiliser comme icône du trou de ver',
            'editor_placeholder_image_url' => 'https://example.com/image.jpg',
            'editor_placeholder_video_url' => 'https://example.com/video.mp4',
            'editor_label_autoplay_video' => 'Lire la vidéo automatiquement',
            'editor_placeholder_pdf_url' => 'https://example.com/document.pdf',
            'editor_help_pdf' => 'Téléverse un PDF ou colle un lien.',
            'editor_placeholder_credit' => 'Crédit ou attribution...',
            'editor_help_credit' => 'Crédit facultatif affiché sur le visuel dans la boîte d\'informations (image, vidéo ou PDF).',
            'editor_label_icon_url_file' => 'URL ou fichier d\'icône',
            'editor_placeholder_icon_url' => 'https://example.com/icon.png',
            'editor_help_icon' => 'Icône personnalisée affichée dans la scène 3D (remplace l\'icône du thème).',
            'editor_label_audio_url_file' => 'URL ou fichier audio',
            'editor_placeholder_audio_url' => 'https://example.com/audio.mp3',
            'editor_label_autoplay' => 'Lecture automatique',
            'editor_label_loop' => 'En boucle',
            'editor_help_audio' => 'Indépendant du visuel principal : l\'audio peut accompagner image, vidéo ou PDF.',
            'editor_text_uploading' => 'Téléversement...',
            'editor_btn_add_wormhole' => 'Ajouter le trou de ver',
            'editor_btn_cancel' => 'Annuler',
            'editor_divider_media' => 'Média',
            'editor_btn_delete_file' => 'Supprimer',
            'editor_btn_update_wormhole' => 'Mettre à jour le trou de ver',
            'editor_modal_heading_confirm_delete' => 'Confirmer la suppression',
            'editor_btn_delete' => 'Supprimer',
            'editor_modal_heading_move_wormholes' => 'Déplacer les trous de ver',
            'editor_text_move_count_wormholes' => 'Déplacer %d trous de ver sélectionnés vers une autre galaxie.',
            'editor_label_destination_galaxy' => 'Galaxie de destination',
            'editor_btn_move_wormholes' => 'Déplacer les trous de ver',
            'editor_modal_heading_duplicate_wormhole' => 'Dupliquer le trou de ver',
            'editor_text_duplicate_to' => 'Dupliquer « %s » dans :',
            'editor_btn_duplicate' => 'Dupliquer',
            'editor_modal_heading_duplicate_wormholes' => 'Dupliquer les trous de ver',
            'editor_text_duplicate_count_wormholes' => 'Dupliquer %d trous de ver sélectionnés dans :',
            'editor_btn_duplicate_wormholes' => 'Dupliquer les trous de ver',
            'editor_btn_open_link' => 'Ouvrir le lien',
            'editor_btn_apply' => 'Appliquer',
            'editor_label_target_prefix' => 'Cible :',
            'editor_modal_heading_bulk_keyword' => 'Action en masse par mot-clé',
            'editor_text_bulk_keyword_help' => 'Choisis un mot-clé dans la galaxie actuelle. Décide ensuite de supprimer tous les trous de ver qui le portent, ou de les déplacer dans une autre galaxie.',
            'editor_label_keyword' => 'Mot-clé',
            'editor_option_loading' => 'Chargement…',
            'editor_label_action' => 'Action',
            'editor_option_delete_matching' => 'Supprimer les trous de ver correspondants',
            'editor_option_move_matching' => 'Les déplacer vers une autre galaxie',
            'editor_text_pick_keyword' => 'Choisis un mot-clé pour voir le total.',
            'editor_error_pick_specific_galaxy' => 'Choisis d\'abord une galaxie précise (pas « Toutes les galaxies »).',
            'editor_option_no_keywords' => '(aucun mot-clé dans cette galaxie)',
            'editor_option_pick_one' => 'choisis-en un',
            'editor_option_error_keywords' => 'Erreur lors du chargement des mots-clés',
            'editor_option_pick_galaxy' => 'choisis une galaxie',
            'editor_preview_move_one' => '1 trou de ver sera déplacé vers la galaxie choisie.',
            'editor_preview_move_many' => '%d trous de ver seront déplacés vers la galaxie choisie.',
            'editor_preview_move_pick_target_one' => '1 trou de ver sera déplacé. Choisis d\'abord une galaxie cible.',
            'editor_preview_move_pick_target_many' => '%d trous de ver seront déplacés. Choisis d\'abord une galaxie cible.',
            'editor_preview_delete_one' => '1 trou de ver sera supprimé définitivement.',
            'editor_preview_delete_many' => '%d trous de ver seront supprimés définitivement.',
            'editor_confirm_bulk_delete_keyword_one' => 'Supprimer définitivement 1 trou de ver portant « %s » ? Cette action est irréversible.',
            'editor_confirm_bulk_delete_keyword_many' => 'Supprimer définitivement %d trous de ver portant « %s » ? Cette action est irréversible.',
            'editor_confirm_bulk_move_keyword_one' => 'Déplacer 1 trou de ver portant « %s » vers la galaxie sélectionnée ?',
            'editor_confirm_bulk_move_keyword_many' => 'Déplacer %d trous de ver portant « %s » vers la galaxie sélectionnée ?',
            'editor_toast_bulk_deleted_one' => '1 trou de ver supprimé.',
            'editor_toast_bulk_deleted_many' => '%d trous de ver supprimés.',
            'editor_toast_bulk_moved_one' => '1 trou de ver déplacé.',
            'editor_toast_bulk_moved_many' => '%d trous de ver déplacés.',
            'editor_toast_bulk_action_failed' => 'Échec de l\'action en masse : %s',
            'editor_modal_heading_shortcuts' => 'Raccourcis clavier',
            'editor_shortcut_new_wormhole' => 'Nouveau trou de ver',
            'editor_shortcut_focus_search' => 'Mettre le focus sur la recherche',
            'editor_shortcut_toggle_touched' => 'Basculer le filtre « Modifiés aujourd\'hui »',
            'editor_shortcut_galaxy_settings' => 'Ouvrir les paramètres de la galaxie (galaxie actuelle)',
            'editor_shortcut_close_modal' => 'Fermer toute fenêtre modale ouverte',
            'editor_shortcut_open_help' => 'Ouvrir cette aide',
            'editor_note_shortcuts_typing' => 'Les raccourcis sont ignorés pendant la saisie dans un champ de texte.',
            'editor_btn_close' => 'Fermer',
            'editor_toast_updated_successfully' => 'Trou de ver mis à jour',
            'editor_toast_created_successfully' => 'Trou de ver créé',
            'editor_error_failed_update' => 'Échec de la mise à jour du trou de ver',
            'editor_error_failed_create' => 'Échec de la création du trou de ver',
            'editor_error_network_upload' => 'Erreur réseau pendant le téléversement',
            'editor_error_name_required' => 'Le nom du trou de ver est obligatoire',
            'editor_error_loading_node' => 'Erreur lors du chargement du trou de ver : %s',
            'editor_confirm_delete_file' => 'Confirmer la suppression de ce fichier %s téléversé ?',
            'editor_toast_file_deleted' => 'Fichier %s supprimé',
            'editor_error_deleting_file' => 'Erreur lors de la suppression du fichier : %s',
            'editor_confirm_delete_node' => 'Confirmer la suppression de « %s » ? Cette action est irréversible.',
            'editor_error_delete_wormhole' => 'Échec de la suppression du trou de ver',
            'editor_toast_deleted_successfully' => 'Trou de ver supprimé',
            'editor_error_deleting_wormhole' => 'Erreur lors de la suppression du trou de ver : %s',
            'editor_error_fatal_loading' => 'Erreur fatale lors du chargement des trous de ver : %s',
            'editor_error_could_not_load' => 'Erreur : impossible de charger les trous de ver. %s',
            'editor_kc_status_loading' => 'Chargement…',
            'editor_kc_status_no_keywords' => 'Pas encore de mots-clés',
            'editor_kc_status_ready' => 'Prêt',
            'editor_kc_status_saving' => 'Enregistrement…',
            'editor_kc_status_saved' => 'Enregistré',
            'editor_kc_status_deleting' => 'Suppression…',
            'editor_kc_status_deleted' => 'Supprimé',
            'editor_kc_status_merging' => 'Fusion…',
            'editor_kc_status_merged' => 'Fusionné',
            'editor_kc_status_renamed' => 'Renommé',
            'editor_kc_status_already_related' => 'Déjà en relation',
            'editor_kc_status_drag_or_click' => 'Glisse vers un autre point d\'ancrage, ou clique sur un point (Échap pour annuler)',
            'editor_kc_status_load_failed' => 'Erreur de chargement : %s',
            'editor_kc_status_save_failed' => 'Erreur d\'enregistrement : %s',
            'editor_kc_status_create_failed' => 'Erreur de création : %s',
            'editor_kc_status_delete_failed' => 'Erreur de suppression : %s',
            'editor_kc_status_rename_failed' => 'Erreur de renommage : %s',
            'editor_kc_status_merge_failed' => 'Erreur de fusion : %s',
            'editor_kc_status_update_failed' => 'Erreur de mise à jour : %s',
            'editor_kc_modal_title_new_relation' => 'Nouvelle relation',
            'editor_kc_modal_title_edit_relation' => 'Modifier la note de la relation',
            'editor_kc_label_authored_by' => 'Créée par %s',
            'editor_kc_label_no_author_recorded' => 'Aucune autorité enregistrée',
            'editor_kc_label_no_author_short' => '(sans autorité)',
            'editor_kc_err_empty_name' => 'Choisis un nom non vide.',
            'editor_kc_err_name_taken_galaxy' => 'Ce nom est déjà pris dans cette galaxie',
            'editor_kc_err_name_taken_conflict' => 'Ce nom est déjà pris ; change-le ou fusionne.',
            'editor_kc_err_missing_config' => 'La configuration de la page est manquante (window.TELARIS_KC)',
            'editor_gxm_status_loading_keywords' => 'Chargement…',
            'editor_gxm_no_keywords_yet' => 'Pas encore de mots-clés pour cette galaxie.',
            'editor_gxm_load_failed_keywords' => 'Erreur de chargement.',
            'editor_gxm_label_use_images_as_icons' => 'utiliser les images comme icônes',
            'editor_gxm_label_revert_to_theme_icons' => 'rétablir tous les icônes du thème',
            'editor_gxm_confirm_apply_to_all' => 'Appliquer « %s » à chaque trou de ver de cette galaxie ?',
            'editor_gxm_status_working' => 'En cours…',
            'editor_gxm_status_updated_one' => '%d trou de ver mis à jour. Recharge la vue de visite pour voir le changement.',
            'editor_gxm_status_updated_many' => '%d trous de ver mis à jour. Recharge la vue de visite pour voir le changement.',
            'editor_gxm_label_failed_prefix' => 'Erreur : %s',
            'editor_gxm_err_update_failed_fallback' => 'Échec de la mise à jour',
            // C3: admin/index.php
            'admin_loading_console' => 'Chargement de la console d\'administration...',
            'admin_heading_console' => 'Console d\'administration',
            'admin_label_welcome' => 'Bonjour, %s',
            'admin_btn_edit_content' => 'Modifier le contenu',
            'admin_btn_logout' => 'Déconnexion',
            'admin_msg_api_key_generated_title' => '✓ Clé d\'API générée',
            'admin_msg_api_key_generated_body' => 'Ta clé d\'API : %s (Nom : %s). COPIE-LA MAINTENANT.',
            'admin_msg_settings_saved' => 'Paramètres globaux enregistrés.',
            'admin_tab_galaxies' => 'Galaxies',
            'admin_tab_clusters' => 'Amas',
            'admin_tab_users' => 'Comptes',
            'admin_tab_backup' => 'Sauvegarde',
            'admin_tab_snapshots' => 'Instantanés',
            'admin_tab_settings' => 'Paramètres globaux',
            'admin_tab_pluriverse' => 'Pluriverse',
            'admin_tab_api_keys' => 'Clés d\'API',
            'admin_tab_php_info' => 'Informations PHP',
            'admin_heading_users' => 'Comptes',
            'admin_btn_new_user' => 'Nouveau compte',
            'admin_btn_bulk_import' => 'Importation en lot',
            'admin_label_search' => 'Rechercher :',
            'admin_placeholder_search_users' => 'Rechercher des comptes...',
            'admin_msg_no_users' => 'Aucun compte trouvé.',
            'admin_col_user_name' => 'Nom',
            'admin_col_user_email' => 'Courriel',
            'admin_col_user_type' => 'Type',
            'admin_col_user_created' => 'Création',
            'admin_col_user_last_login' => 'Dernière connexion',
            'admin_col_user_last_updated' => 'Dernière modification',
            'admin_col_actions' => 'Actions',
            'admin_user_type_regular' => 'Régulier',
            'admin_user_type_editor' => 'Édition',
            'admin_user_type_admin' => 'Administration',
            'admin_badge_you' => 'Toi',
            'admin_label_never' => 'Jamais',
            'admin_action_edit' => 'Modifier',
            'admin_action_delete' => 'Supprimer',
            'admin_confirm_delete_user' => 'Confirmer la suppression du compte « %s » ? Cette action est irréversible.',
            'admin_heading_generate_api_key' => 'Générer une nouvelle clé d\'API',
            'admin_label_api_key_name' => 'Nom *',
            'admin_placeholder_api_key_name' => 'p. ex. App frontale, App mobile, Admin',
            'admin_help_api_key_name' => 'Un nom descriptif pour cette clé d\'API',
            'admin_label_api_key_description' => 'Description',
            'admin_placeholder_api_key_description' => 'Description facultative de l\'usage de cette clé',
            'admin_btn_generate_api_key' => 'Générer la clé d\'API',
            'admin_btn_cancel' => 'Annuler',
            'admin_heading_api_keys' => 'Clés d\'API',
            'admin_btn_new_api_key' => 'Nouvelle clé d\'API',
            'admin_msg_no_api_keys' => 'Aucune clé d\'API n\'a encore été générée.',
            'admin_badge_inactive' => 'Inactive',
            'admin_action_deactivate' => 'Désactiver',
            'admin_action_activate' => 'Activer',
            'admin_confirm_delete_api_key' => 'Confirmer la suppression de cette clé d\'API ? Cette action est irréversible.',
            'admin_label_created' => 'Création :',
            'admin_label_last_used' => 'Dernière utilisation :',
            'admin_label_last_updated' => 'Dernière modification :',
            'admin_heading_galaxies' => 'Galaxies',
            'admin_btn_new_galaxy' => 'Nouvelle galaxie',
            'admin_placeholder_search_galaxies' => 'Rechercher des galaxies...',
            'admin_help_galaxies_default' => 'Chaque galaxie est un ensemble indépendant de trous de ver et de mots-clés. La galaxie par défaut actuelle, %s, ne peut pas être supprimée.',
            'admin_help_galaxies_settings_link' => 'Tu peux changer la galaxie par défaut dans l\'onglet %s.',
            'admin_toast_url_copied' => 'URL copiée dans le presse-papiers.',
            'admin_heading_clusters' => 'Amas de galaxies',
            'admin_btn_new_cluster' => 'Nouvel amas',
            'admin_placeholder_search_clusters' => 'Rechercher des amas...',
            'admin_help_clusters' => 'Un amas est une union choisie de galaxies avec son propre slug, titre, thème et lien permanent. Les amas n\'ont pas de trous de ver propres ; ils affichent l\'union des galaxies membres via le pipeline multi-galaxie.',
            'admin_help_settings' => 'Paramètres qui s\'appliquent à toute l\'instance de l\'application principale.',
            'admin_label_version' => 'Version',
            'admin_label_default_galaxy' => 'Galaxie par défaut',
            'admin_help_default_galaxy' => 'Choisis quelle galaxie est affichée à la racine du site.',
            'admin_label_instance_name' => 'Nom',
            'admin_help_instance_name' => 'Nom public de cette instance. Affiché côté visite et utilisé comme libellé dans l\'annuaire du Pluriverse au moment de postuler. Par défaut, le premier segment du nom d\'hôte si laissé vide.',
            'admin_label_pdf_max' => 'Taille maximale du PDF (Mo)',
            'admin_help_pdf_max' => "Plus grand PDF qu\'un trou de ver peut contenir. Par défaut 25 Mo. En téléversant des fichiers plus gros, l\'erreur « Le fichier dépasse la taille maximale autorisée » apparaît.",
            'admin_btn_save_settings' => 'Enregistrer les paramètres',
            // Pluriverse tab.
            'admin_pluriverse_heading' => 'Rejoindre le Pluriverse',
            'admin_pluriverse_subheading' => 'Fédère cette instance dans le Pluriverse pour qu\'elle apparaisse dans le répertoire public à www.telaris.ca. La demande transporte ton URL, le nom, le contact de l\'opération et les galaxies choisies, signée par la pluriverse.key de cette instance.',
            'admin_pluriverse_status_heading' => 'État de l\'adhésion',
            'admin_pluriverse_status_status' => 'État',
            'admin_pluriverse_status_submitted' => 'Envoyée',
            'admin_pluriverse_status_name' => 'Nom',
            'admin_pluriverse_status_email' => 'Courriel de l\'opération',
            'admin_pluriverse_status_fingerprint' => 'Empreinte de clé publique enregistrée',
            'admin_pluriverse_status_help' => 'Vérifie le courriel de l\'opération pour le lien de vérification. Le lien et la demande en attente expirent tous deux 24 heures après l\'envoi. L\'administration du Pluriverse examine la demande après la vérification et signale quand l\'instance est publiée.',
            'admin_pluriverse_status_expired_heading' => 'Demande d\'adhésion expirée',
            'admin_pluriverse_status_expired_body' => 'Le lien de vérification de ta dernière demande d\'adhésion n\'a pas été ouvert dans les 24 heures, alors la demande a expiré. Tu peux en envoyer une nouvelle avec le bouton ci-dessous; tu recevras un nouveau courriel de vérification à ton adresse d\'opération.',
            'admin_pluriverse_btn_rejoin' => 'Rejoindre à nouveau la Pluriverse',
            'admin_pluriverse_field_url_label' => 'URL de l\'instance',
            'admin_pluriverse_field_url_help' => 'URL https canonique de cette instance. Le nom d\'hôte est dérivé de ce champ.',
            'admin_pluriverse_field_name_label' => 'Nom',
            'admin_pluriverse_field_name_help' => 'Nom public court de cette instance, unique dans tout le Pluriverse. S\'il est déjà pris, il faudra en choisir un autre.',
            'admin_pluriverse_field_email_label' => 'Courriel de l\'opération',
            'admin_pluriverse_field_email_help' => 'Destinataire du lien magique. Chiffré au repos sur le Pluriverse. Modifie si tu préfères une adresse différente du compte d\'administration.',
            'admin_pluriverse_field_framing_label' => 'Cadrage éditorial',
            'admin_pluriverse_field_framing_help' => 'Une à trois phrases. À quoi sert cette instance? Facultatif.',
            'admin_pluriverse_field_galaxies_label' => 'Galaxies publiables',
            'admin_pluriverse_field_galaxies_summary' => '%d galaxies de cette instance seront publiées. Les nouvelles galaxies sont ajoutées automatiquement au fur et à mesure de leur création.',
            'admin_pluriverse_field_galaxies_empty' => 'Pas encore de galaxie. La candidature inscrit cette instance maintenant; les nouvelles galaxies sont récupérées automatiquement au fur et à mesure de leur création.',
            'admin_pluriverse_field_galaxies_disclosure' => 'Voir la liste',
            'admin_pluriverse_field_contacts_label' => 'Contacts secondaires',
            'admin_pluriverse_field_contacts_help' => 'Canaux de secours facultatifs (Matrix, XMPP, etc.). Jusqu\'à huit.',
            'admin_pluriverse_btn_add_contact' => 'Ajouter un autre',
            'admin_pluriverse_contact_service_placeholder' => 'service',
            'admin_pluriverse_contact_handle_placeholder' => 'identifiant / adresse',
            'admin_pluriverse_btn_submit' => 'Rejoindre le Pluriverse',
            'admin_pluriverse_submit_help' => 'Cette instance signera la candidature avec sa pluriverse.key (Ed25519) puis l\'enverra à www.telaris.ca. Le Pluriverse enverra un lien de vérification au courriel de l\'opération.',
            'admin_pluriverse_link_change_name' => '(modifier dans Paramètres globaux)',
            'admin_heading_download_backup' => 'Télécharger une sauvegarde',
            'admin_help_download_backup' => 'Crée une archive de sauvegarde portable avec les galaxies et/ou les comptes. L\'option par défaut produit une sauvegarde complète avec les médias intégrés.',
            'admin_label_galaxies' => 'Galaxies',
            'admin_label_all_galaxies' => 'Toutes les galaxies',
            'admin_label_selected_galaxies' => 'Seulement les galaxies sélectionnées',
            'admin_msg_loading_galaxies' => 'Chargement des galaxies...',
            'admin_btn_select_all' => 'Tout sélectionner',
            'admin_btn_clear' => 'Effacer',
            'admin_label_users_always_all' => 'Comptes (toujours tous)',
            'admin_help_users_export' => 'Les mots de passe sont exportés sous forme de hachages. Ils n\'apparaissent jamais en clair.',
            'admin_label_media_files' => 'Fichiers médias',
            'admin_label_media_embedded' => 'Intégrés : sauvegarde autonome (recommandé)',
            'admin_label_media_refs' => 'Références seulement : archive plus petite, restaurable uniquement sur le même serveur',
            'admin_label_media_none' => 'Aucun : tous les médias sont écartés',
            'admin_btn_download_backup' => 'Télécharger la sauvegarde',
            'admin_heading_restore_backup' => 'Restaurer à partir d\'une sauvegarde',
            'admin_help_restore_backup' => 'Téléverse un fichier .telaris-backup. Un résumé s\'affiche avant qu\'aucun changement ne soit appliqué.',
            'admin_btn_inspect_file' => 'Inspecter le fichier',
            'admin_label_galaxies_in_file' => 'Galaxies dans ce fichier',
            'admin_label_for_each_galaxy' => 'Pour chaque galaxie sélectionnée',
            'admin_label_overwrite_slug' => 'Écraser s\'il existe déjà une galaxie avec le même slug',
            'admin_label_create_as_new' => 'Créer comme nouvelle (renommer en cas de conflit, suffixe :',
            'admin_label_users_in_file' => 'Comptes dans ce fichier',
            'admin_label_restore_users' => 'Restaurer les comptes',
            'admin_label_skip_existing' => 'Ignorer les comptes existants (correspondance par courriel)',
            'admin_label_update_existing' => 'Mettre à jour les comptes existants par courriel',
            'admin_label_overwrite_pw' => 'Écraser aussi les hachages de mots de passe',
            'admin_label_restore_media' => 'Restaurer les fichiers médias',
            'admin_btn_restore' => 'Restaurer',
            'admin_help_snapshots' => 'Les instantanés sont des sauvegardes locales complètes sur disque de tout le système. Restaurer un instantané efface tout et remplace par l\'état de l\'instantané. Tout instantané créé après celui restauré est supprimé.',
            'admin_heading_create_snapshot' => 'Créer un instantané maintenant',
            'admin_placeholder_snapshot_note' => 'Note facultative (p. ex. avant migration)',
            'admin_btn_create_snapshot' => 'Créer un instantané',
            'admin_msg_creating_snapshot' => 'Création de l\'instantané. Cela peut prendre une minute sur les grandes instances. Ne ferme pas cet onglet.',
            'admin_heading_snapshot_scheduler' => 'Planificateur d\'instantanés',
            'admin_label_enable_daily' => 'Activer les instantanés quotidiens',
            'admin_label_hour_utc' => 'Heure (UTC)',
            'admin_label_keep_days' => 'Jours à conserver (auto)',
            'admin_btn_save' => 'Enregistrer',
            'admin_btn_refresh_status' => 'Rafraîchir l\'état',
            'admin_label_status' => 'État :',
            'admin_label_last_snapshot' => 'Dernier instantané :',
            'admin_label_last_checked' => 'Dernière vérification :',
            'admin_label_status_loading' => 'chargement...',
            'admin_label_never_lower' => 'jamais',
            'admin_label_recent_activity' => 'Activité récente',
            'admin_msg_no_activity' => '(aucune activité pour l\'instant)',
            'admin_heading_available_snapshots' => 'Instantanés disponibles',
            'admin_msg_loading' => 'Chargement...',
            'admin_heading_php_config' => 'Configuration PHP',
            'admin_heading_important_extensions' => 'Extensions importantes',
            'admin_heading_all_extensions' => 'Toutes les extensions chargées',
            'admin_msg_no_galaxies' => 'Aucune galaxie trouvée.',
            'admin_col_id' => 'ID',
            'admin_col_galaxy_name' => 'Nom',
            'admin_col_slug' => 'Slug',
            'admin_col_tagline' => 'Devise',
            'admin_col_wormholes' => 'Trous de ver',
            'admin_col_created' => 'Création',
            'admin_col_last_updated' => 'Dernière modification',
            'admin_badge_default' => 'Par défaut',
            'admin_badge_imported' => 'Importée',
            'admin_title_tour_enabled' => 'Visite automatique activée',
            'admin_msg_error_loading_galaxies' => 'Erreur lors du chargement des galaxies : %s',
            'admin_action_view' => 'Visualiser',
            'admin_action_copy_url' => 'Copier l\'URL',
            'admin_action_keyword_canvas' => 'Toile de mots-clés',
            'admin_action_duplicate' => 'Dupliquer',
            'admin_action_refresh' => 'Rafraîchir',
            'admin_confirm_delete_galaxy' => 'Confirmer la suppression de la galaxie « %s » ? Cela supprimera définitivement TOUS les trous de ver et mots-clés qu\'elle contient.',
            'admin_msg_no_clusters_search' => 'Aucun amas ne correspond à cette recherche.',
            'admin_msg_no_clusters' => 'Pas encore d\'amas.',
            'admin_col_theme' => 'Thème',
            'admin_col_members' => 'Membres',
            'admin_title_idle_spotlight' => 'Projecteur en veille activé',
            'admin_title_galaxy_list' => 'Liste de galaxies visible côté visite',
            'admin_badge_galaxy_list' => 'Liste de galaxies',
            'admin_confirm_delete_cluster' => 'Supprimer l\'amas « %s » ? Ses membres (les galaxies à l\'intérieur) ne sont pas affectées ; seul l\'amas lui-même est supprimé.',
            'admin_msg_error_loading_clusters' => 'Erreur lors du chargement des amas : %s',
            'admin_label_no_prefix_chip' => 'Sans préfixe (%d)',
            'admin_label_wormhole_count' => '%d trous de ver',
            'admin_label_default_inline' => '(par défaut)',
            'admin_msg_no_galaxies_in_backup' => 'Aucune galaxie dans cette sauvegarde.',
            'admin_msg_file_selected' => 'Sélectionné : %s (%s)',
            'admin_toast_choose_backup' => 'Choisis d\'abord un fichier de sauvegarde.',
            'admin_toast_inspect_first' => 'Inspecte d\'abord un fichier.',
            'admin_toast_inspect_failed' => 'L\'inspection a échoué : %s',
            'admin_toast_failed_prefix' => 'Erreur : %s',
            'admin_toast_nothing_selected' => 'Rien de sélectionné à restaurer.',
            'admin_confirm_restore' => "Restaurer %s sur ce système ?\n\nMode de conflit : %s\n\nCette action est irréversible.",
            'admin_toast_restore_complete' => 'Restauration terminée.',
            'admin_toast_restore_failed' => 'La restauration a échoué : %s',
            'admin_label_backup_summary' => 'Résumé du fichier de sauvegarde',
            'admin_text_format_app_created' => 'Format v%s · App %s · Créé %s',
            'admin_text_summary_counts' => 'Galaxies : %s · Trous de ver : %s · Mots-clés : %s',
            'admin_text_summary_users_media' => 'Comptes : %s%s · Médias : %s fichiers (%s Mo)',
            'admin_text_no_admin_user_warn' => '(pas de compte d\'administration !)',
            'admin_label_failures' => 'Échecs :',
            'admin_heading_restore_complete' => 'Restauration terminée',
            'admin_text_galaxies_report' => 'Galaxies : créées %s, écrasées %s, renommées %s, ignorées %s',
            'admin_text_users_report' => 'Comptes : créés %s, mis à jour %s, ignorés %s',
            'admin_text_media_report' => 'Fichiers médias : écrits %s, ignorés %s',
            'admin_label_disabled' => 'Désactivé',
            'admin_label_active' => 'Actif',
            'admin_label_needs_attention' => 'Nécessite attention',
            'admin_msg_cron_inactive' => 'Le service cron du système n\'est pas en cours d\'exécution (%s). Les instantanés planifiés ne seront pas pris tant que cron n\'est pas démarré.',
            'admin_msg_cron_not_installed' => 'Impossible d\'enregistrer le planificateur auprès de cron. Essaie d\'enregistrer à nouveau.',
            'admin_msg_scheduler_unknown' => 'État du planificateur inconnu.',
            'admin_msg_no_snapshots' => 'Pas encore d\'instantanés. Crées-en un ci-dessus.',
            'admin_col_snapshot_created' => 'Création (UTC)',
            'admin_col_size' => 'Taille',
            'admin_col_type' => 'Type',
            'admin_col_creator' => 'Origine',
            'admin_col_note' => 'Note',
            'admin_label_file_missing' => '(fichier manquant)',
            'admin_label_creator_system' => 'système',
            'admin_action_restore' => 'Restaurer',
            'admin_action_download' => 'Télécharger',
            'admin_btn_creating' => 'Création...',
            'admin_msg_creating_elapsed' => 'Création de l\'instantané. Temps : %ss. Cela peut prendre une minute sur les grandes instances. Ne ferme pas cet onglet.',
            'admin_toast_snapshot_created' => 'Instantané créé en %ss.',
            'admin_toast_create_snapshot_failed' => 'Erreur lors de la création de l\'instantané : %s',
            'admin_confirm_delete_snapshot' => 'Supprimer cet instantané ? Le fichier sera retiré définitivement du disque.',
            'admin_toast_snapshot_deleted' => 'Instantané supprimé.',
            'admin_toast_delete_failed' => 'Échec de la suppression : %s',
            'admin_prompt_restore_snapshot' => "RESTAURER EFFACERA le système entier et le remplacera par l\'instantané du %s.\n\nTous les instantanés créés après ce point seront aussi supprimés.\n\nTape RESTORE pour confirmer :",
            'admin_toast_confirm_phrase_mismatch' => 'La phrase de confirmation ne correspond pas. Restauration annulée.',
            'admin_confirm_no_admin' => 'AVERTISSEMENT : cet instantané n\'a pas de compte d\'administration. Le restaurer bloquera l\'accès à la console d\'administration. Continuer quand même ?',
            'admin_toast_restore_complete_logout' => 'Restauration terminée. Ta session peut être déconnectée.',
            'admin_toast_restore_complete_report' => 'Restauration terminée. %s galaxies créées, %s comptes. %s instantané(s) ultérieur(s) supprimé(s). Ta session peut être déconnectée.',
            'admin_toast_failed_load_galaxies' => 'Échec du chargement des galaxies : %s',
            'admin_toast_saved_cron_warning' => 'Enregistré, mais le planificateur n\'a pas pu s\'enregistrer auprès de cron : %s',
            'admin_toast_schedule_saved' => 'Planification enregistrée.',
            'admin_toast_save_schedule_failed' => 'Échec de l\'enregistrement de la planification : %s',
            // C4: admin/index.php (modales)
            'admin_modal_heading_bulk_users' => 'Importer des comptes en lot',
            'admin_modal_bulk_users_imported_one' => '<strong>%d</strong> compte importé.',
            'admin_modal_bulk_users_imported_many' => '<strong>%d</strong> comptes importés.',
            'admin_modal_bulk_users_galaxies_created_one' => ' <strong>%d</strong> galaxie créée.',
            'admin_modal_bulk_users_galaxies_created_many' => ' <strong>%d</strong> galaxies créées.',
            'admin_modal_bulk_users_skipped_exists_one' => ' <strong>%d</strong> courriel déjà existant ignoré.',
            'admin_modal_bulk_users_skipped_exists_many' => ' <strong>%d</strong> courriels déjà existants ignorés.',
            'admin_modal_bulk_users_skipped_invalid_one' => ' <strong>%d</strong> ligne invalide ignorée.',
            'admin_modal_bulk_users_skipped_invalid_many' => ' <strong>%d</strong> lignes invalides ignorées.',
            'admin_modal_bulk_users_mail_failed_one' => ' <strong>%d</strong> courriel de configuration n\'a pas pu être envoyé.',
            'admin_modal_bulk_users_mail_failed_many' => ' <strong>%d</strong> courriels de configuration n\'ont pas pu être envoyés.',
            'admin_modal_bulk_users_col_line' => 'Ligne',
            'admin_modal_bulk_users_col_email' => 'Courriel',
            'admin_modal_bulk_users_col_outcome' => 'Résultat',
            'admin_modal_bulk_users_col_galaxy' => 'Galaxie',
            'admin_modal_bulk_users_col_note' => 'Note',
            'admin_modal_bulk_users_col_name' => 'Nom',
            'admin_modal_bulk_users_col_role' => 'Rôle',
            'admin_modal_bulk_users_col_status' => 'État',
            'admin_modal_btn_done' => 'Terminé',
            'admin_modal_btn_confirm_import' => 'Confirmer l\'importation',
            'admin_modal_btn_preview' => 'Aperçu',
            'admin_modal_bulk_users_preview_intro' => 'Vérifie la liste interprétée. Clique sur <strong>Confirmer l\'importation</strong> pour créer les nouveaux comptes et envoyer à chacun un lien de configuration à usage unique.',
            'admin_modal_bulk_users_row_override' => '(remplacement par ligne)',
            'admin_modal_bulk_users_form_intro' => 'Colle une liste de comptes, un par ligne, avec les colonnes séparées par des virgules. Seul le courriel est obligatoire ; le reste est facultatif.',
            'admin_modal_bulk_users_field_email' => '<strong>courriel</strong> : obligatoire',
            'admin_modal_bulk_users_field_first_name' => '<strong>prénom</strong>',
            'admin_modal_bulk_users_field_last_name' => '<strong>nom de famille</strong>',
            'admin_modal_bulk_users_field_type' => '<strong>type</strong> : <code>Editor</code> (par défaut) ou <code>Admin</code>',
            'admin_modal_bulk_users_field_create_galaxy' => '<strong>créer une galaxie ?</strong> : <code>oui</code> / <code>non</code>. Vide hérite de la case ci-dessous ; une valeur ici la remplace.',
            'admin_modal_bulk_users_example_label' => '<strong>Exemple :</strong>',
            'admin_modal_bulk_users_footer_help' => 'Chaque nouveau compte reçoit un courriel de bienvenue avec un lien de configuration à usage unique (TTL de 7 jours) pour définir le mot de passe. Lorsqu\'une galaxie est créée et associée, le courriel inclut aussi l\'URL de la galaxie et le lien de connexion. Les courriels déjà existants sont ignorés ; les lignes commençant par <code>#</code> sont ignorées.',
            'admin_modal_bulk_users_textarea_placeholder' => 'courriel, prénom, nom, type, créer-galaxie',
            'admin_modal_bulk_users_label_create_galaxy_each' => 'Créer une galaxie pour chaque nouveau compte',
            'admin_modal_bulk_users_help_create_galaxy_each' => 'Le slug est tiré du nom du courriel (avant le <code>@</code>) ; les collisions reçoivent un suffixe aléatoire court. Les comptes d\'édition sont attribués à leur propre galaxie ; les comptes d\'administration voient déjà toutes les galaxies. Remplace par ligne dans la 5e colonne.',
            'admin_modal_heading_create_user' => 'Créer un nouveau compte',
            'admin_modal_label_first_name' => 'Prénom *',
            'admin_modal_help_first_name' => 'Le prénom associé au compte.',
            'admin_modal_label_last_name' => 'Nom de famille *',
            'admin_modal_help_last_name' => 'Le nom de famille associé au compte.',
            'admin_modal_label_email' => 'Courriel *',
            'admin_modal_err_email_in_use' => 'Ce courriel est déjà utilisé.',
            'admin_modal_help_email' => 'Identifiant de connexion et adresse de contact.',
            'admin_modal_label_password' => 'Mot de passe *',
            'admin_modal_help_password_min' => 'Minimum 8 caractères.',
            'admin_modal_label_user_type' => 'Type de compte *',
            'admin_modal_opt_user_type_editor' => 'Édition',
            'admin_modal_opt_user_type_admin' => 'Administration',
            'admin_modal_help_user_type' => 'Édition : ne peut modifier que les trous de ver dans les galaxies attribuées | Administration : accès complet à toutes les galaxies.',
            'admin_modal_label_create_galaxy_for_user' => 'Créer une nouvelle galaxie pour ce compte',
            'admin_modal_help_create_galaxy_for_user' => 'Une galaxie est créée avec le nom ci-dessous et un accès lui est accordé (uniquement pour les comptes d\'édition).',
            'admin_modal_label_new_galaxy_name' => 'Nom de la galaxie *',
            'admin_modal_placeholder_new_galaxy_name' => 'Par défaut, le courriel ci-dessus',
            'admin_modal_help_new_galaxy_name' => 'Nom pour la galaxie créée automatiquement.',
            'admin_modal_label_galaxy_access_editors' => 'Accès aux galaxies (uniquement pour les comptes d\'édition)',
            'admin_modal_help_galaxy_access_editors' => 'Les comptes d\'édition ne voient et ne modifient que les trous de ver dans les galaxies cochées ci-dessus. Les comptes d\'administration voient toutes les galaxies.',
            'admin_modal_btn_create_user' => 'Créer le compte',
            'admin_modal_heading_create_galaxy' => 'Créer une nouvelle galaxie',
            'admin_modal_label_galaxy_name' => 'Nom *',
            'admin_modal_placeholder_galaxy_name' => 'p. ex. Réseau principal, Archive',
            'admin_modal_err_name_in_use' => 'Ce nom est déjà utilisé.',
            'admin_modal_help_galaxy_name' => 'Nom unique pour le nouveau réseau de trous de ver.',
            'admin_modal_label_url_slug' => 'Slug d\'URL',
            'admin_modal_placeholder_url_slug' => 'p. ex. archive',
            'admin_modal_err_slug_in_use' => 'Ce slug est déjà utilisé.',
            'admin_modal_help_url_slug' => 'Chemin d\'URL personnalisé. S\'il reste vide, il sera généré à partir du nom. Seulement lettres, chiffres et tirets.',
            'admin_modal_label_tagline' => 'Devise',
            'admin_modal_placeholder_tagline' => 'p. ex. Tisser la mémoire',
            'admin_modal_help_tagline' => 'Apparaît dans la vue principale lorsque cette galaxie est ouverte.',
            'admin_modal_label_visual_theme' => 'Thème visuel',
            'admin_modal_opt_theme_cosmic' => 'Cosmique (étoiles, planètes, fusées)',
            'admin_modal_opt_theme_simple' => 'Simple (sphères colorées)',
            'admin_modal_opt_theme_abstract' => 'Abstrait (icônes GIF géométriques)',
            'admin_modal_opt_theme_rectangles' => 'Rectangles (icônes rectangulaires personnalisées)',
            'admin_modal_opt_theme_stripes' => 'Rayures (icônes de rayures personnalisées)',
            'admin_modal_opt_theme_tech' => 'Tech (icônes de circuits)',
            'admin_modal_help_visual_theme' => 'Détermine le fond, les icônes et les animations.',
            'admin_modal_btn_create_galaxy' => 'Créer la galaxie',
            'admin_modal_heading_create_cluster' => 'Créer un amas',
            'admin_modal_heading_edit_cluster' => 'Modifier l\'amas',
            'admin_modal_heading_duplicate_cluster' => 'Dupliquer l\'amas',
            'admin_modal_placeholder_cluster_name' => 'p. ex. Traçant la Terre',
            'admin_modal_placeholder_cluster_slug' => 'p. ex. tracant-la-terre',
            'admin_modal_help_cluster_slug' => 'Pour qui visite, l\'arrivée se fait sur <code>/&lt;slug&gt;</code>. S\'il reste vide, il est généré à partir du nom.',
            'admin_modal_placeholder_cluster_tagline' => 'p. ex. Un amas choisi',
            'admin_modal_opt_cluster_theme_cosmic' => 'Cosmique',
            'admin_modal_opt_cluster_theme_abstract' => 'Abstrait',
            'admin_modal_opt_cluster_theme_rectangles' => 'Rectangles',
            'admin_modal_opt_cluster_theme_stripes' => 'Rayures',
            'admin_modal_opt_cluster_theme_tech' => 'Tech',
            'admin_modal_help_cluster_theme' => 'Thème de la scène. L\'icône de chaque trou de ver utilise toujours le thème de sa galaxie d\'origine.',
            'admin_modal_label_show_galaxy_list' => 'Afficher la liste des galaxies côté visite',
            'admin_modal_help_show_galaxy_list' => 'Lorsque activée, on voit côté visite une liste des galaxies membres de l\'amas dans le coin inférieur droit ; un clic atténue les trous de ver des autres galaxies. Désactivée par défaut pour les amas, puisque le cadrage choisi se lit habituellement comme une expérience unique.',
            'admin_modal_label_member_galaxies' => 'Galaxies membres *',
            'admin_modal_help_member_galaxies' => 'Côté visite, on voit l\'union des trous de ver de ces galaxies. Des ponts (lignes pointillées subtiles) relient les trous de ver qui partagent du texte de mot-clé entre galaxies.',
            'admin_modal_count_selected_one' => '%d sélectionnée',
            'admin_modal_count_selected_many' => '%d sélectionnées',
            'admin_modal_label_keyword_chips' => 'Étiquettes de mots-clés',
            'admin_modal_help_keyword_chips' => 'Rassemble les mots-clés les plus utilisés à travers tous les trous de ver visibles (toutes les galaxies membres) dans une bande d\'étiquettes de filtre en haut de l\'amas. Clique sur une étiquette pour atténuer les trous de ver qui ne correspondent pas.',
            'admin_modal_label_related_wormholes' => 'Trous de ver en lien',
            'admin_modal_help_related_wormholes' => 'Lorsque la fiche d\'informations d\'un trou de ver est ouverte, atténue ceux qui ne sont pas en lien et affiche jusqu\'à 5 trous de ver en lien (qui partagent des mots-clés) comme étiquettes de saut au bas de la fiche. Rassemble à travers tout l\'amas ; les étiquettes peuvent provenir de n\'importe quelle galaxie membre.',
            'admin_modal_label_2d_view' => 'Bascule de vue 2D',
            'admin_modal_help_2d_view' => 'Montre une bascule « 3D / 2D » en haut au centre pour passer de la scène 3D à une grille plate d\'étiquettes de trous de ver. La préférence de chaque visite persiste dans le navigateur.',
            'admin_modal_label_idle_spotlight' => 'Projecteur en veille',
            'admin_modal_help_idle_spotlight' => 'Après une période d\'inactivité, la caméra vole vers un trou de ver aléatoire n\'importe où dans l\'amas et ouvre la fiche d\'informations. Se ferme quand le contenu se termine ou après le minuteur de séjour.',
            'admin_modal_label_pick_from' => 'Choisir parmi',
            'admin_modal_opt_pick_all_wormholes' => 'Tous les trous de ver (dans toutes les galaxies membres)',
            'admin_modal_opt_pick_accentuated' => 'Seulement les trous de ver accentués',
            'admin_modal_label_trigger_after_seconds' => 'Déclencher après (secondes d\'inactivité)',
            'admin_modal_label_auto_tour' => 'Visite automatique',
            'admin_modal_title_preview_tour' => 'Enregistre d\'abord, puis aperçois la visite dans un nouvel onglet',
            'admin_modal_btn_preview_tour' => 'Aperçu de la visite',
            'admin_modal_help_auto_tour' => 'Mène automatiquement à travers les trous de ver de tout l\'amas, en ouvrant chaque fiche et en lisant le contenu. Bureau et iPad seulement.',
            'admin_modal_label_start_mode' => 'Mode de démarrage',
            'admin_modal_opt_start_manual' => 'Manuel. Commence en cliquant sur un bouton de lecture.',
            'admin_modal_opt_start_idle' => 'En veille. Commence après une période d\'inactivité.',
            'admin_modal_opt_start_immediate' => 'Immédiat. Commence quelques secondes après le chargement de l\'amas.',
            'admin_modal_label_idle_threshold' => 'Seuil d\'inactivité (secondes)',
            'admin_modal_warn_immediate_audio' => 'Une ou plusieurs galaxies membres contiennent des trous de ver avec audio. Les navigateurs bloquent la lecture automatique avec son tant qu\'aucune interaction n\'a eu lieu avec la page, alors le premier audio d\'une visite à démarrage immédiat peut rester silencieux ou se figer.',
            'admin_modal_label_which_wormholes' => 'Quels trous de ver parcourir',
            'admin_modal_opt_tour_all' => 'Tous les trous de ver (ordre aléatoire à chaque exécution)',
            'admin_modal_opt_tour_accentuated' => 'Seulement les trous de ver accentués',
            'admin_modal_opt_tour_random_n' => 'Un échantillon aléatoire de N trous de ver',
            'admin_modal_opt_tour_tagged' => 'Trous de ver marqués avec l\'un de ces mots-clés',
            'admin_modal_label_random_count' => 'Combien de trous de ver par visite',
            'admin_modal_label_tour_keywords' => 'Mots-clés (toute correspondance, séparés par virgules)',
            'admin_modal_placeholder_tour_keywords' => 'p. ex. Idéologie, Résistance, Terre',
            'admin_modal_help_tour_keywords' => 'Correspond par nom de mot-clé (sans distinction de casse) à travers toutes les galaxies membres. Utile quand la même étiquette (p. ex. <code>Idéologie</code>) existe dans plusieurs galaxies avec des identifiants différents.',
            'admin_modal_label_dwell_seconds' => 'Pause sur les trous de ver sans contenu (secondes)',
            'admin_modal_label_loop_tour' => 'Recommencer la visite à la fin',
            'admin_modal_btn_create_cluster' => 'Créer l\'amas',
            'admin_modal_btn_update_cluster' => 'Mettre à jour l\'amas',
            'admin_modal_name_copy_suffix' => ' (Copie)',
            'admin_modal_heading_edit_user' => 'Modifier le compte',
            'admin_modal_label_password_optional' => 'Mot de passe (laisser vide pour conserver l\'actuel)',
            'admin_modal_btn_update_user' => 'Mettre à jour le compte',
            'admin_modal_heading_duplicate_galaxy' => 'Dupliquer la galaxie',
            'admin_modal_label_duplicating' => 'Duplication :',
            'admin_modal_label_new_name' => 'Nouveau nom *',
            'admin_modal_label_new_url_slug' => 'Nouveau slug d\'URL',
            'admin_modal_label_new_tagline' => 'Nouvelle devise',
            'admin_modal_btn_duplicate' => 'Dupliquer',
            'admin_modal_heading_confirm_deletion' => 'Confirmer la suppression',
            'admin_modal_label_type_galaxy_name' => 'Tape le nom de la galaxie pour confirmer :',
            'admin_modal_label_type_to_confirm' => 'Pour confirmer, tape exactement ce qui suit :',
            'admin_modal_placeholder_type_name' => 'Tape le nom ici...',
            'admin_modal_btn_delete' => 'Supprimer',
            'admin_modal_deletion_impact_title' => '⚠️ Impact de la suppression :',
            'admin_modal_deletion_impact_intro' => 'Les portails suivants dans d\'autres galaxies pointent vers ce réseau et seront aussi supprimés :',
            'admin_modal_deletion_impact_row' => '<strong>%s</strong> (dans la galaxie : %s)',
            'admin_error_user_not_found' => 'Compte introuvable.',
            'admin_error_galaxy_not_found' => 'Galaxie introuvable.',
            'admin_error_delete_confirm_mismatch' => 'La confirmation ne correspond pas. Tape le nom exact pour confirmer la suppression.',
            'admin_setup_perms_heading' => 'Étape suivante (durcissement de l\'hôte) :',
            'admin_setup_perms_intro' => 'config.php est maintenant en mode',
            'admin_setup_perms_advice' => 'Lance sudo php bin/setup-host.php depuis la racine du site pour appliquer la configuration canonique de l\'hôte (snippet nginx, règle logrotate et 0640 propriétaire=opérateur sur config.php).',

            // C5: admin/setup.php (post-DB)
            'admin_setup_website_info_subtitle' => 'Configure les informations du site',
            'admin_setup_db_tables_created' => '✓ Tables de la base de données créées !',
            'admin_setup_website_name_label' => 'Nom du site',
            'admin_setup_website_name_help' => 'Le nom du site ou du projet. Par défaut : Telaris',
            'admin_setup_tagline_label' => 'Devise',
            'admin_setup_tagline_help' => 'Une courte description ou devise. Par défaut : Tisser la mémoire',
            'admin_setup_website_info_footer_help' => 'Ces valeurs sont utilisées pour la galaxie par défaut et les informations du projet. Tu peux les changer plus tard dans Admin → Paramètres globaux et Galaxies.',
            'admin_setup_website_info_continue' => 'Continuer',
            'admin_setup_schema_details_heading' => 'Détails de la création du schéma',
            'admin_setup_schema_db_created' => 'Base de données <strong>%s</strong> créée',
            'admin_setup_schema_db_exists' => 'La base de données <strong>%s</strong> existe déjà',
            'admin_setup_schema_tables_created_one' => 'Table créée (%d) :',
            'admin_setup_schema_tables_created_many' => 'Tables créées (%d) :',
            'admin_setup_schema_tables_existed_one' => 'Table déjà existante (%d) :',
            'admin_setup_schema_tables_existed_many' => 'Tables déjà existantes (%d) :',
            'admin_setup_schema_no_tables' => 'Aucune table n\'a été créée ou ignorée.',
            'admin_setup_schema_api_key_heading' => '✓ Clé d\'API par défaut générée',
            'admin_setup_schema_api_key_help' => 'Une clé d\'API par défaut a été générée automatiquement et est déjà en usage. Les clés d\'API peuvent être gérées sur la page de gestion des clés d\'API.',
            'admin_setup_admin_user_heading' => 'Créer le compte d\'administration',
            'admin_setup_admin_user_intro' => 'Il n\'existe pas encore de compte d\'administration. Crées-en un pour accéder à la console d\'administration.',
            'admin_setup_first_name_label' => 'Prénom *',
            'admin_setup_last_name_label' => 'Nom de famille *',
            'admin_setup_email_label' => 'Courriel *',
            'admin_setup_email_help' => 'Ce sera le courriel d\'accès.',
            'admin_setup_password_label' => 'Mot de passe *',
            'admin_setup_password_help' => 'Minimum 8 caractères',
            'admin_setup_confirm_password_label' => 'Confirmer le mot de passe *',
            'admin_setup_create_admin_btn' => 'Créer le compte d\'administration',
            'admin_setup_admin_user_created' => '✓ Compte d\'administration créé !',
            'admin_setup_admin_user_can_login' => 'Tu peux maintenant te connecter sur la %s.',
            'admin_setup_admin_user_login_link' => 'page de connexion',
            'admin_setup_config_created_flash' => '✓ Fichier de configuration créé !',
            'admin_setup_complete_with_schema' => 'Installation terminée. Schéma de la base créé et informations du projet initialisées.',
            'admin_setup_complete_no_schema' => 'Installation terminée. Informations du projet initialisées.',
            'admin_setup_db_error_prefix' => 'Erreur de base de données :',
            'admin_setup_error_prefix' => 'Erreur :',
            'admin_setup_status_heading' => 'État de l\'installation :',
            'admin_setup_config_file_label' => 'Fichier de configuration :',
            'admin_setup_config_file_created' => '✓ Créé',
            'admin_setup_config_file_missing' => '✗ Manquant',
            'admin_setup_db_connection_label' => 'Connexion à la base de données :',
            'admin_setup_db_connection_connected' => '✓ Connectée',
            'admin_setup_db_connection_failed' => '✗ Échec',
            'admin_setup_project_info_label' => 'Informations du projet :',
            'admin_setup_project_info_initialized' => '✓ Initialisées',
            'admin_setup_project_info_not_initialized' => '✗ Non initialisées',
            'admin_setup_link_go_to_telaris' => 'Aller à Telaris →',
            'admin_setup_link_admin_console' => 'Console d\'administration',
            'admin_setup_link_reconfigure_db' => 'Reconfigurer la base de données',
            'admin_setup_validation_all_fields_required' => 'Tous les champs sont obligatoires.',
            'admin_setup_validation_passwords_mismatch' => 'Les mots de passe ne correspondent pas.',
            'admin_setup_validation_password_too_short' => 'Le mot de passe doit faire au moins 8 caractères.',
            'admin_setup_validation_db_unavailable' => 'Connexion à la base de données indisponible.',

            // C5b: utils/login.php + utils/forgot.php + utils/reset.php
            'auth_login_page_title' => 'Connexion - Telaris',
            'auth_login_heading' => 'Connexion à Telaris',
            'auth_login_subtitle' => 'Accède à l\'espace constellation',
            'auth_email_label' => 'Courriel',
            'auth_password_label' => 'Mot de passe',
            'auth_login_submit' => 'Se connecter',
            'auth_login_forgot_link' => 'Mot de passe oublié ?',
            'auth_login_back_link' => '← Retour à la constellation',
            'auth_error_invalid_request' => 'Requête invalide. Recharge la page et réessaie.',
            'auth_error_throttled' => 'Trop de tentatives. Réessaie plus tard.',
            'auth_login_error_required' => 'Le courriel et le mot de passe sont obligatoires',
            'auth_login_error_invalid' => 'Courriel ou mot de passe invalide. Seuls les comptes d\'édition et d\'administration peuvent se connecter ici.',
            'auth_forgot_page_title' => 'Réinitialiser le mot de passe - Telaris',
            'auth_forgot_heading' => 'Récupérer le mot de passe',
            'auth_forgot_subtitle' => 'Nous envoyons un lien à usage unique pour définir un nouveau mot de passe.',
            'auth_forgot_generic_notice' => 'S\'il existe un compte avec ce courriel, un lien de réinitialisation a été envoyé.',
            'auth_forgot_error_invalid_email' => 'Indique une adresse de courriel valide.',
            'auth_forgot_submit' => 'Envoyer le lien de réinitialisation',
            'auth_forgot_back_link' => '← Retour à la connexion',
            'auth_email_subject' => 'Réinitialise ton mot de passe %s',
            'auth_email_greeting_named' => 'Bonjour %s,',
            'auth_email_greeting_anon' => 'Bonjour,',
            'auth_email_intro' => 'Nous avons reçu une demande de réinitialisation de mot de passe. Clique sur le lien pour en définir un nouveau :',
            'auth_email_expiry' => 'Le lien expire dans 24 heures et ne peut être utilisé qu\'une seule fois. Si tu n\'as pas demandé la réinitialisation, tu peux ignorer ce courriel ; ton mot de passe ne changera pas.',
            'auth_email_text_intro' => "Nous avons reçu une demande de réinitialisation de mot de passe.\n\nLien de réinitialisation (24h, usage unique) :\n",
            'auth_email_text_outro' => "\n\nSi tu n\'as pas demandé la réinitialisation, ignore ce courriel.",
            'auth_reset_page_title' => 'Définir un nouveau mot de passe - Telaris',
            'auth_reset_heading' => 'Définir un nouveau mot de passe',
            'auth_reset_success_message' => 'Mot de passe mis à jour. Tu peux maintenant te connecter avec le nouveau.',
            'auth_reset_btn_go_to_login' => 'Aller à la connexion',
            'auth_reset_invalid_token_message' => 'Ce lien de réinitialisation est invalide ou a expiré. Demandes-en un nouveau.',
            'auth_reset_btn_request_new_link' => 'Demander un nouveau lien',
            'auth_reset_intro_html' => 'Définition d\'un nouveau mot de passe pour <strong>%s</strong>.',
            'auth_reset_new_password_label' => 'Nouveau mot de passe',
            'auth_reset_password_help' => 'Au moins 8 caractères.',
            'auth_reset_confirm_password_label' => 'Confirmer le nouveau mot de passe',
            'auth_reset_submit' => 'Mettre à jour le mot de passe',
            'auth_reset_error_password_too_short' => 'Le mot de passe doit faire au moins 8 caractères.',
            'auth_reset_error_password_mismatch' => 'Les mots de passe ne correspondent pas.',

            // C7a: inc/partials/galaxy-edit-modal.php
            'gem_heading' => 'Modifier la galaxie',
            'gem_name_label' => 'Nom *',
            'gem_name_duplicate_error' => 'Ce nom est déjà utilisé.',
            'gem_tagline_label' => 'Devise',
            'gem_slug_label' => 'Chemin de l\'URL',
            'gem_slug_placeholder' => 'p. ex. archive',
            'gem_slug_duplicate_error' => 'Ce chemin est déjà utilisé.',
            'gem_slug_help' => 'Chemin d\'URL personnalisé. S\'il reste vide, il est généré à partir du nom. Seulement lettres, chiffres et tirets.',
            'gem_theme_label' => 'Thème visuel',
            'gem_theme_cosmic' => 'Cosmique (étoiles, planètes, fusées)',
            'gem_theme_simple' => 'Simple (sphères colorées)',
            'gem_theme_abstract' => 'Abstrait (icônes GIF géométriques)',
            'gem_theme_rectangles' => 'Rectangles (icônes rectangulaires personnalisées)',
            'gem_theme_stripes' => 'Rayures (icônes de rayures personnalisées)',
            'gem_theme_tech' => 'Tech (icônes de circuits)',
            'gem_tags_label' => 'Étiquettes',
            'gem_tags_placeholder' => 'Ajouter une étiquette...',
            'gem_tags_help' => 'Côté visite, on peut explorer l\'union de toutes les galaxies portant une étiquette sur <code>/tag/&lt;tag&gt;</code>. Tape pour ajouter ; appuie sur Entrée ou virgule. Les suggestions montrent les étiquettes déjà utilisées dans cette galaxie et dans les galaxies sœurs partageant le préfixe <code>[XX]</code>.',
            'gem_bulk_actions_label' => 'Actions en masse sur les trous de ver',
            'gem_bulk_actions_help' => 'S\'appliquent à tous les trous de ver de cette galaxie d\'un coup. Les bascules individuelles peuvent les remplacer ensuite.',
            'gem_bulk_use_images_btn' => 'Utiliser les images comme icônes (tous les trous de ver)',
            'gem_bulk_revert_icons_btn' => 'Tout rétablir aux icônes du thème',
            'gem_keyword_chips_label' => 'Étiquettes de mots-clés',
            'gem_keyword_chips_help' => 'Affiche les mots-clés les plus utilisés comme étiquettes de filtre en haut de la galaxie. Clique sur une étiquette pour atténuer les trous de ver qui ne correspondent pas.',
            'gem_related_label' => 'Trous de ver en lien',
            'gem_related_help' => 'Lorsque la fiche d\'informations d\'un trou de ver est ouverte, atténue ceux qui ne sont pas en lien dans la scène et montre jusqu\'à 5 en lien (qui partagent des mots-clés) comme étiquettes de saut au bas de la fiche. Un échantillon aléatoire apparaît à chaque fois.',
            'gem_2d_view_label' => 'Bascule de vue 2D',
            'gem_2d_view_help' => 'Affiche une bascule « 3D / 2D » en haut au centre pour passer de la scène 3D à une grille plate d\'étiquettes de trous de ver. La préférence persiste dans le navigateur.',
            'gem_idle_spotlight_label' => 'Projecteur en veille',
            'gem_idle_spotlight_help' => 'Après une période d\'inactivité, la caméra vole vers un trou de ver aléatoire et ouvre la fiche d\'informations. Se ferme quand le contenu se termine ou après le minuteur de séjour.',
            'gem_pick_from_label' => 'Choisir parmi',
            'gem_idle_pick_all' => 'Tous les trous de ver',
            'gem_idle_pick_accentuated' => 'Seulement les trous de ver accentués',
            'gem_idle_trigger_label' => 'Déclencher après (secondes d\'inactivité)',
            'gem_autotour_label' => 'Visite automatique',
            'gem_autotour_preview_btn' => 'Aperçu de la visite',
            'gem_autotour_preview_title' => 'Enregistre d\'abord, puis aperçois la visite dans un nouvel onglet',
            'gem_autotour_help' => 'Navigue automatiquement à travers les nœuds, en ouvrant chaque fiche et en lisant le contenu. Bureau et iPad seulement.',
            'gem_start_mode_label' => 'Mode de démarrage',
            'gem_start_mode_manual' => 'Manuel. Commence en cliquant sur un bouton de lecture.',
            'gem_start_mode_idle' => 'En veille. Commence après une période d\'inactivité.',
            'gem_start_mode_immediate' => 'Immédiat. Commence quelques secondes après le chargement de la galaxie.',
            'gem_idle_threshold_label' => 'Seuil d\'inactivité (secondes)',
            'gem_immediate_audio_warning' => 'Cette galaxie contient des nœuds avec audio. Les navigateurs bloquent la lecture automatique avec son tant qu\'aucune interaction n\'a eu lieu avec la page, alors le premier audio d\'une visite à démarrage immédiat peut rester silencieux ou se figer.',
            'gem_which_nodes_label' => 'Quels nœuds inclure dans la visite',
            'gem_nodes_all' => 'Tous les nœuds (ordre aléatoire à chaque exécution)',
            'gem_nodes_accentuated' => 'Seulement les nœuds accentués',
            'gem_nodes_random_n' => 'Un échantillon aléatoire de N nœuds',
            'gem_nodes_tagged' => 'Nœuds marqués avec l\'un de ces mots-clés',
            'gem_random_count_label' => 'Combien de nœuds par visite',
            'gem_keywords_label' => 'Mots-clés (toute correspondance)',
            'gem_keywords_help' => 'Les nœuds qui correspondent à l\'un des mots-clés sélectionnés apparaissent.',
            'gem_dwell_label' => 'Pause sur les nœuds sans contenu (secondes)',
            'gem_loop_label' => 'Recommencer la visite à la fin',
            'gem_submit_btn' => 'Mettre à jour la galaxie',
            'gem_cancel_btn' => 'Annuler',
            'gem_close_btn' => 'fermer',

            // C7b: titres d'erreurs de l'API (RFC 9457). Code <statut-http>.<sous-code-à-3-chiffres>.
            'api_error_400_001' => 'JSON invalide : %s',
            'api_error_400_002' => 'Un champ obligatoire est manquant.',
            'api_error_400_003' => 'URL invalide : seules les URLs http et https sont autorisées.',
            'api_error_400_004' => 'Format de clé d\'amas invalide.',
            'api_error_400_005' => 'Le paramètre galaxies est incompatible avec page/id.',
            'api_error_400_006' => 'Le corps de la requête est vide.',
            'api_error_400_007' => 'Le nom du nœud est obligatoire.',
            'api_error_400_008' => 'Le nom du nœud ne peut pas être vide.',
            'api_error_400_009' => 'L\'identifiant du nœud est obligatoire.',
            'api_error_400_010' => 'Un identifiant de constellation est requis.',
            'api_error_400_011' => 'Un nom de constellation est requis.',
            'api_error_400_012' => 'Un mot-clé est requis.',
            'api_error_400_013' => 'Un identifiant de mot-clé est requis.',
            'api_error_400_014' => 'Le mot-clé n\'appartient pas à la constellation indiquée.',
            'api_error_400_015' => 'Un identifiant de galaxie est requis.',
            'api_error_400_016' => 'move_keyword nécessite keyword_id, x, y.',
            'api_error_400_017' => 'create_relation nécessite keyword_a_id et keyword_b_id.',
            'api_error_400_018' => 'Les relations avec soi-même ne sont pas autorisées.',
            'api_error_400_019' => 'Les deux mots-clés doivent appartenir à la même galaxie.',
            'api_error_400_020' => 'update_relation nécessite relation_id.',
            'api_error_400_021' => 'delete_relation nécessite relation_id.',
            'api_error_400_022' => 'reset_keyword nécessite keyword_id.',
            'api_error_400_023' => 'reset_galaxy nécessite galaxy_id.',
            'api_error_400_024' => 'delete_keyword nécessite keyword_id.',
            'api_error_400_025' => 'rename_keyword nécessite keyword_id.',
            'api_error_400_026' => 'rename_keyword nécessite un nouveau nom non vide.',
            'api_error_400_027' => 'Le nom du mot-clé est trop long (maximum 100 caractères).',
            'api_error_400_028' => 'merge_keywords nécessite source_id et target_id.',
            'api_error_400_029' => 'Impossible de fusionner un mot-clé avec lui-même.',
            'api_error_400_030' => 'Action inconnue : %s.',
            'api_error_400_031' => 'constellation_id, keyword_id et op (delete|move|count) sont requis.',
            'api_error_400_032' => 'target_constellation_id est obligatoire pour move.',
            'api_error_400_033' => 'Le nom du pont est manquant ou invalide.',
            'api_error_400_034' => "Le pont « %s » n\'est pas activé sur cette instance.",
            'api_error_400_035' => 'Type de validation invalide.',
            'api_error_400_036' => 'Échec du téléversement du fichier (code %d).',
            'api_error_400_037' => 'Le paramètre phase est manquant ou invalide.',
            'api_error_400_038' => 'Confirmation requise.',
            'api_error_400_039' => 'L\'identifiant est manquant ou invalide.',
            'api_error_400_040' => 'La phrase de confirmation est manquante ou incorrecte (doit être RESTORE).',
            'api_error_400_041' => 'Erreur d\'encodage.',
            'api_error_400_042' => 'Impossible d\'encoder la réponse.',
            'api_error_400_043' => 'Sélectionne au moins des galaxies ou des comptes à sauvegarder.',
            'api_error_400_044' => 'Format d\'URL invalide. Une URL complète est attendue, comme https://hostname/api/v2.',
            'api_error_400_045' => 'Aucune galaxie spécifiée.',
            'api_error_400_046' => 'Connexion refusée à ce serveur distant : %s',

            'api_error_401_001' => 'Clé d\'API manquante. Fournis-la via l\'en-tête X-API-Key, via Authorization: Bearer, ou via le paramètre api_key de l\'URL.',
            'api_error_401_002' => 'Clé d\'API invalide.',

            'api_error_403_001' => 'Les opérations d\'écriture nécessitent une session authentifiée. Connecte-toi.',
            'api_error_403_002' => 'Permissions insuffisantes pour les opérations d\'écriture.',
            'api_error_403_003' => 'Jeton de sécurité invalide. Recharge la page et réessaie.',
            'api_error_403_004' => 'Pas d\'accès d\'édition à cette galaxie.',
            'api_error_403_005' => 'Accès refusé.',
            'api_error_403_006' => 'Seul le compte ayant créé la relation ou un compte d\'administration peut la modifier.',
            'api_error_403_007' => 'Seul le compte ayant créé la relation ou un compte d\'administration peut la supprimer.',
            'api_error_403_008' => 'La vérification d\'existence de compte est réservée aux sessions d\'administration.',

            'api_error_404_001' => 'Nœud introuvable.',
            'api_error_404_002' => 'Galaxie introuvable.',
            'api_error_404_003' => 'Mot-clé introuvable.',
            'api_error_404_004' => 'Relation introuvable.',
            'api_error_404_005' => 'La relation pointe vers un mot-clé inexistant.',
            'api_error_404_006' => 'Amas introuvable.',
            'api_error_404_007' => 'Nœud d\'origine introuvable.',
            'api_error_404_008' => 'La galaxie de destination n\'existe pas.',
            'api_error_404_009' => 'Clé d\'API introuvable.',
            'api_error_404_010' => "Le fichier du gestionnaire du pont « %s » est manquant.",
            'api_error_404_011' => "Le pont « %s » n\'a pas de gestionnaire de requêtes.",
            'api_error_404_012' => 'Téléversement inconnu ou expiré. Sélectionne le fichier à nouveau.',
            'api_error_404_013' => 'Le fichier téléversé est manquant. Sélectionne-le à nouveau.',
            'api_error_404_014' => 'Instantané introuvable.',

            'api_error_405_001' => 'Méthode non autorisée.',

            'api_error_409_001' => 'Un mot-clé avec ce nom existe déjà.',
            'api_error_409_002' => 'Une relation entre ces mots-clés existe déjà.',

            'api_error_500_001' => 'Erreur interne du serveur.',
            'api_error_500_002' => 'Erreur de base de données.',
            'api_error_500_003' => 'Impossible de créer le répertoire de téléversement. Vérifie les permissions du serveur.',
            'api_error_500_004' => 'Impossible d\'enregistrer le fichier téléversé.',
            'api_error_500_005' => 'Impossible d\'enregistrer l\'image téléversée.',
            'api_error_500_006' => 'Impossible d\'enregistrer l\'icône téléversée.',
            'api_error_500_007' => 'Impossible d\'enregistrer l\'audio téléversé.',
            'api_error_500_008' => 'Impossible d\'enregistrer la vidéo téléversée.',
            'api_error_500_009' => 'Impossible d\'enregistrer le PDF téléversé.',
            'api_error_500_010' => 'Impossible d\'extraire une image de la vidéo téléversée.',
            'api_error_500_011' => 'Le fichier ne ressemble pas à un PDF valide.',
            'api_error_500_012' => 'Impossible de créer le nœud : identifiant non récupérable.',
            'api_error_500_013' => 'Impossible d\'encoder les données d\'animation.',
            'api_error_500_014' => 'Impossible d\'encoder les données JSON.',
            'api_error_500_015' => 'Impossible d\'enregistrer le fichier de sauvegarde téléversé.',
            'api_error_502_001' => 'Impossible de joindre l\'API Mocambos sur %s.',

            // C7c: messages du résultat de la mise à jour de la galaxie.
            'galaxy_update_missing_id' => 'L\'identifiant de la galaxie est manquant.',
            'galaxy_update_not_authorized' => 'Non autorisé.',
            'galaxy_update_no_access' => 'Pas d\'accès à cette galaxie.',
            'galaxy_update_name_required' => 'Le nom de la galaxie est obligatoire.',
            'galaxy_update_duplicate_name' => 'Une galaxie avec le nom « %s » existe déjà.',
            'galaxy_update_duplicate_slug' => 'Une galaxie avec le chemin « %s » existe déjà.',
            'galaxy_update_duplicate_both' => 'Une galaxie avec le nom « %s » et le chemin « %s » existe déjà.',
            'galaxy_update_success' => 'Galaxie mise à jour.',

            // C7d: UI d'administration du pont Mocambos (chrome + chaînes JS).
            'mocambos_btn_import_from' => 'Importer depuis Mocambos',
            'mocambos_modal_heading' => 'Importer depuis Mocambos',
            'mocambos_label_api_url' => 'URL de l\'API Mocambos',
            'mocambos_help_api_url' => 'L\'URL de base de l\'API de l\'instance Mocambos (p. ex. https://hostname/api/v2). On peut aussi coller l\'URL de la documentation ; /docs est retiré automatiquement.',
            'mocambos_btn_connect' => 'Se connecter',
            'mocambos_text_loading' => 'Récupération des galaxies disponibles...',
            'mocambos_btn_back' => 'Retour',
            'mocambos_text_connected_to' => 'Connecté à :',
            'mocambos_text_select_intro' => 'Sélectionne les galaxies à importer. Chacune devient une nouvelle galaxie. Celles déjà importées sont mises à jour.',
            'mocambos_text_starting_import' => 'Démarrage de l\'importation...',
            'mocambos_text_refresh_intro' => 'Cela synchronise les trous de ver avec la source Mocambos distante (mise à jour incrémentale).',
            'mocambos_text_refresh_confirm_instruction' => 'Pour confirmer, tape ci-dessous le nom de la galaxie <strong id="refresh-confirm-name" class="text-gray-900">%s</strong> :',
            'mocambos_placeholder_refresh_confirm' => 'Tape le nom de la galaxie pour confirmer',
            'mocambos_btn_refresh' => 'Rafraîchir',
            'mocambos_btn_cancel' => 'Annuler',
            'mocambos_btn_import_selected' => 'Importer la sélection',
            'mocambos_btn_close' => 'Fermer',
            'mocambos_btn_modal_backdrop_close' => 'fermer',
            'mocambos_js_validation_report_title' => 'Rapport de validation de l\'API Mocambos',
            'mocambos_js_validation_url_prefix' => 'URL :',
            'mocambos_js_validation_date_prefix' => 'Date :',
            'mocambos_js_validating_api' => 'Validation de l\'API...',
            'mocambos_js_enter_url' => 'Indique une URL de l\'API Mocambos.',
            'mocambos_js_validation_failed_intro' => 'La validation de l\'API a échoué. Les problèmes suivants ont été trouvés :',
            'mocambos_js_copied' => 'Copié',
            'mocambos_js_copy_report' => 'Copier le rapport dans le presse-papiers',
            'mocambos_js_could_not_validate' => 'Impossible de valider : %s',
            'mocambos_js_network_error' => 'Erreur de réseau',
            'mocambos_js_fetch_failed' => 'Impossible de récupérer les galaxies',
            'mocambos_js_no_galaxias' => 'Aucune galaxie trouvée à cette URL.',
            'mocambos_js_badge_imported' => 'Importée',
            'mocambos_js_connect_failed' => 'Impossible de se connecter à l\'API Mocambos',
            'mocambos_js_select_at_least_one' => 'Sélectionne au moins une galaxie à importer.',
            'mocambos_js_confirm_refresh_intro' => 'Les galaxies suivantes seront mises à jour, remplaçant tout le contenu actuel y compris les modifications :',
            'mocambos_js_confirm_refresh_continue' => 'Continuer ?',
            'mocambos_js_import_failed_generic' => 'L\'importation a échoué',
            'mocambos_js_import_complete_status' => 'Importation terminée',
            'mocambos_js_status_label_new' => 'Nouvelle',
            'mocambos_js_status_label_refreshed' => 'Mise à jour',
            'mocambos_js_items_count' => '%d sur %d éléments',
            'mocambos_js_completed_success' => 'Importation terminée.',
            'mocambos_js_completed_errors' => 'Importation terminée avec quelques erreurs.',
            'mocambos_js_refresh_complete_log' => 'Mise à jour terminée.',
            'mocambos_js_refresh_complete_status' => 'Mise à jour terminée',
            'mocambos_js_refresh_failed_status' => 'La mise à jour a échoué',
            'mocambos_js_missing_source' => 'Les informations de source d\'importation pour cette galaxie sont manquantes.',
            'mocambos_js_refreshing' => 'Mise à jour de « %s »...',
            'mocambos_js_error_prefix' => 'Erreur : %s',
            'mocambos_js_unknown_error' => 'Erreur inconnue',

            // C7e: chaînes de handler.php (streamMsg HTTP, validation, sortie CLI).
            'mocambos_h_resolved_mucua_names' => '%d noms de mucua résolus.',
            'mocambos_h_fetching_media' => 'Récupération des éléments de média depuis l\'API Mocambos...',
            'mocambos_h_total_items_fetched' => 'Total des éléments récupérés : %d.',
            'mocambos_h_processing_galaxia' => 'Traitement de la galaxie : %s (%d éléments).',
            'mocambos_h_import_complete' => 'Importation terminée.',
            'mocambos_h_full_refresh_clearing' => 'Mise à jour complète ; effacement des nœuds existants...',
            'mocambos_h_re_importing_diff' => 'Réimportation ; calcul des différences...',
            'mocambos_h_backfilled_slugs' => '%d slugs d\'importation remplis.',
            'mocambos_h_diff_summary' => 'Diff : %d nouveaux, %d modifiés, %d supprimés, %d sans changement.',
            'mocambos_h_deleting_removed' => 'Suppression de %d éléments retirés...',
            'mocambos_h_updating_modified' => 'Mise à jour de %d éléments modifiés...',
            'mocambos_h_created_constellation' => 'Constellation créée : %s (id %d).',
            'mocambos_h_adding_new_nodes' => 'Ajout de %d nouveaux nœuds...',
            'mocambos_h_phase1_creating' => 'Phase 1 : création de %d nœuds...',
            'mocambos_h_nodes_created_progress' => '  %d/%d nœuds créés.',
            'mocambos_h_phase1_complete' => 'Phase 1 terminée : %d/%d nœuds créés.',
            'mocambos_h_phase2_downloading' => 'Phase 2 : téléchargement des fichiers médias...',
            'mocambos_h_downloading_image' => '(%s) Téléchargement de l\'image : %s',
            'mocambos_h_downloading_video' => '(%s) Téléchargement de la vidéo : %s',
            'mocambos_h_downloading_audio' => '(%s) Téléchargement de l\'audio : %s',
            'mocambos_h_phase2_complete' => 'Phase 2 terminée : %d fichiers médias téléchargés.',
            'mocambos_h_phase2_complete_with_errors' => 'Phase 2 terminée : %d fichiers médias téléchargés (%d ont échoué).',
            'mocambos_h_galaxia_done' => 'Galaxie %s prête : %d/%d éléments importés.',
            'mocambos_h_galaxia_done_with_errors' => 'Galaxie %s prête : %d/%d éléments importés (%d erreurs).',
            'mocambos_h_concurrent_import' => 'Une importation est déjà en cours pour la galaxie %s ; réessaie plus tard.',
            'mocambos_h_failed_to_create_node' => 'Impossible de créer le nœud : %s (%s).',
            'mocambos_h_media_downloads_failed' => '%d téléchargements de médias ont échoué.',
            'mocambos_h_check_connection_failed' => 'Échec de la connexion ; impossible de joindre le serveur.',
            'mocambos_h_check_galaxia_http_fail' => 'HTTP %d ; 200 attendu. Ce point d\'accès doit retourner un tableau JSON d\'objets galaxia.',
            'mocambos_h_check_galaxia_not_array' => 'La réponse n\'est pas un tableau JSON valide. Reçu : %s',
            'mocambos_h_check_galaxia_empty' => 'A retourné un tableau vide ; aucune galaxia disponible pour l\'importation.',
            'mocambos_h_check_galaxia_missing_fields' => 'Champs obligatoires manquants dans les objets galaxia : %s. Chaque galaxia doit avoir : name, slug, default_mucua.',
            'mocambos_h_check_galaxia_ok' => '%d galaxia(s) trouvée(s). La structure semble correcte.',
            'mocambos_h_check_mucua_http_fail' => 'HTTP %d ; 200 attendu. Ce point d\'accès doit retourner un tableau JSON d\'objets mucua.',
            'mocambos_h_check_mucua_not_array' => 'La réponse n\'est pas un tableau JSON valide. Reçu : %s',
            'mocambos_h_check_mucua_empty' => 'A retourné un tableau vide ; aucun mucua trouvé. Les téléchargements de médias pourraient ne pas fonctionner.',
            'mocambos_h_check_mucua_missing_fields' => 'Champs obligatoires manquants dans les objets mucua : %s. Chaque mucua doit avoir : smid, slug.',
            'mocambos_h_check_mucua_ok' => '%d mucua(s) trouvée(s). La structure semble correcte.',
            'mocambos_h_check_acervo_http_fail' => 'HTTP %d ; 200 attendu. Ce point d\'accès doit retourner un objet JSON paginé avec un tableau « items ».',
            'mocambos_h_check_acervo_no_items' => 'La réponse ne contient pas la clé « items ». Attendu : {item_count, page_count, items: [...]}. Reçu : %s',
            'mocambos_h_check_acervo_ok' => 'A retourné %d élément(s) de média au total. La structure semble correcte.',
            'mocambos_h_check_blog_http_fail' => 'HTTP %d ; 200 attendu. Les articles de blog ne seront pas importés.',
            'mocambos_h_check_blog_no_items' => 'La réponse ne contient pas la clé « items ». Les articles de blog ne seront pas importés.',
            'mocambos_h_check_blog_ok' => 'A retourné %d article(s) de blog au total. La structure semble correcte.',
            'mocambos_h_cli_header' => 'Importation Mocambos',
            'mocambos_h_cli_prompt_api_base' => 'URL de base de l\'API Mocambos',
            'mocambos_h_cli_err_api_base_required' => 'Erreur : --api-base est obligatoire.',
            'mocambos_h_cli_err_usage' => 'Usage : php admin/cli/import_bridge.php mocambos --api-base=URL --galaxia=SLUG',
            'mocambos_h_cli_connecting' => 'Connexion à %s...',
            'mocambos_h_cli_fetch_galaxias_failed' => 'Impossible de récupérer la liste des galaxias de %s.',
            'mocambos_h_cli_found_counts' => '%d galaxia(s) trouvée(s), %d mucua(s).',
            'mocambos_h_cli_available_galaxias_at' => 'Galaxias disponibles sur %s :',
            'mocambos_h_cli_col_slug' => 'SLUG',
            'mocambos_h_cli_col_name' => 'NOM',
            'mocambos_h_cli_col_smid' => 'SMID',
            'mocambos_h_cli_available_galaxias' => 'Galaxias disponibles :',
            'mocambos_h_cli_already_imported' => '(déjà importée)',
            'mocambos_h_cli_prompt_select_galaxia' => 'Sélectionne le numéro de la galaxia (ou tape le slug)',
            'mocambos_h_cli_no_galaxia_selected' => 'Aucune galaxia sélectionnée.',
            'mocambos_h_cli_err_galaxia_required' => 'Erreur : --galaxia=SLUG est obligatoire.',
            'mocambos_h_cli_matched_slug' => 'Slug de galaxia correspondant : %s.',
            'mocambos_h_cli_galaxia_not_found' => 'La galaxia « %s » est introuvable. Utilise --list pour voir les galaxias disponibles.',
            'mocambos_h_cli_prompt_download_media' => 'Télécharger les fichiers médias ? (plus lent, mais inclut images/audio/vidéo)',
            'mocambos_h_cli_prompt_limit' => 'Limiter le nombre d\'éléments ? (tape un nombre, ou appuie sur Entrée pour tous)',
            'mocambos_h_cli_summary_galaxia' => 'Galaxia :',
            'mocambos_h_cli_summary_api' => 'API :',
            'mocambos_h_cli_summary_media' => 'Média :',
            'mocambos_h_cli_summary_limit' => 'Limite :',
            'mocambos_h_cli_value_skip' => 'ignorer',
            'mocambos_h_cli_value_download' => 'télécharger',
            'mocambos_h_cli_value_all' => 'tous',
            'mocambos_h_cli_prompt_proceed' => 'Procéder à l\'importation ?',
            'mocambos_h_cli_aborted' => 'Annulé.',
            'mocambos_h_cli_galaxia_info' => 'Galaxia : %s (slug=%s, smid=%s).',
            'mocambos_h_cli_total_items' => 'Total des éléments pour cette galaxia : %d.',
            'mocambos_h_cli_limited_to' => 'Limité à %d éléments (--limit).',
            'mocambos_h_cli_constellation_label' => 'Constellation : %s (id %d).',
            'mocambos_h_cli_imported_summary' => 'Importés : %d/%d éléments en %ss.',
            'mocambos_h_cli_errors_count' => 'Erreurs : %d.',
            'mocambos_h_cli_media_skipped' => 'Téléchargements de médias ignorés (--no-media).',
            'mocambos_h_cli_constellation_new' => 'Nouvelle constellation créée.',
            'mocambos_h_cli_constellation_existing' => 'Constellation existante réimportée.',

            // C7f: edit/keyword-canvas.php (chrome PHP).
            'editor_kc_page_title' => 'Toile de mots-clés',
            'editor_kc_err_missing_galaxy_id' => 'Manque <code>?galaxy_id=N</code>.',
            'editor_kc_err_galaxy_not_found' => 'Galaxie introuvable.',
            'editor_kc_err_clusters_no_canvas' => 'Les amas n\'ont pas de mots-clés propres ; la toile ne s\'applique qu\'aux galaxies. Ouvre la toile dans une galaxie membre.',
            'editor_kc_err_no_edit_access' => 'Pas d\'accès d\'édition à cette galaxie.',
            'editor_kc_back_link' => '← Retour',
            'editor_kc_page_title_template' => 'Toile de mots-clés ; %s',
            'editor_kc_empty_state' => 'Cette galaxie n\'a pas encore de mots-clés. Ajoute d\'abord quelques trous de ver avec des mots-clés.',
            'editor_kc_mobile_block' => 'Ouvre la toile de mots-clés dans un navigateur de bureau pour créer des relations entre mots-clés. Les interactions nécessitent un écran plus grand et une souris ou un pavé tactile.',
            'editor_kc_note_modal_title' => 'Note de relation',
            'editor_kc_note_modal_intro' => 'Cadrage éditorial facultatif ; qu\'est-ce que cette relation porte qu\'un mot-clé partagé ne peut dire seul ?',
            'editor_kc_note_modal_cancel' => 'Annuler',
            'editor_kc_note_modal_save' => 'Enregistrer',
            'editor_kc_keyword_modal_title' => 'Mot-clé',
            'editor_kc_keyword_modal_new_name_label' => 'Nouveau nom',
            'editor_kc_keyword_modal_cancel' => 'Annuler',
            'editor_kc_keyword_modal_delete' => 'Supprimer',
            'editor_kc_keyword_modal_rename' => 'Renommer',
            'editor_kc_conflict_modal_title' => 'Le mot-clé existe déjà',
            'editor_kc_conflict_modal_body_suffix' => 'existe déjà dans cette galaxie.',
            'editor_kc_conflict_modal_options_intro' => '<strong>Changer le nom</strong> : garde ce mot-clé séparé et choisis-en un différent.<br><strong>Fusionner</strong> : intègre ce mot-clé à celui existant ; tous les trous de ver marqués avec lui, toutes les lignes de la toile, sont redirigés vers le mot-clé existant. Celui-ci sera supprimé. Pas d\'annulation possible.',
            'editor_kc_conflict_modal_change' => 'Changer le nom',
            'editor_kc_conflict_modal_merge' => 'Fusionner',
            'editor_kc_line_modal_title' => 'Relation',
            'editor_kc_line_modal_noauth' => 'Seul le compte ayant créé la relation ou un compte d\'administration peut la modifier ou la supprimer.',
            'editor_kc_line_modal_close' => 'Fermer',
            'editor_kc_line_modal_edit' => 'Modifier la note',
            'editor_kc_line_modal_delete' => 'Supprimer',
            'editor_kc_backdrop_close' => 'fermer',
            'editor_kc_help_button' => 'Aide',
            'editor_kc_help_title' => 'Guide rapide',
            'editor_kc_help_purpose' => 'Utilise cette vue pour cartographier les relations entre les mots-clés de cette galaxie. Plus ils sont proches, plus leur relation est forte. Glisse les étiquettes pour définir la proximité et trace des lignes entre elles pour enregistrer des connexions sémantiques précises.',
            'editor_kc_help_intro' => 'Comment utiliser :',
            'editor_kc_help_move_label' => 'Déplacer un mot-clé',
            'editor_kc_help_move_body' => 'Glisse une étiquette pour la repositionner.',
            'editor_kc_help_connect_label' => 'Connecter deux mots-clés',
            'editor_kc_help_connect_body' => 'Clique sur un point d\'ancrage d\'une étiquette puis sur celui d\'une autre. Ou glisse d\'un point à l\'autre.',
            'editor_kc_help_edit_label' => 'Modifier ou supprimer une ligne',
            'editor_kc_help_edit_body' => 'Clique sur une ligne existante pour l\'ouvrir.',
            'editor_kc_help_pan_label' => 'Déplacer la vue',
            'editor_kc_help_pan_body' => 'Maintiens Espace et glisse, ou glisse avec le bouton du milieu de la souris.',
            'editor_kc_help_zoom_label' => 'Zoom',
            'editor_kc_help_zoom_body' => 'Utilise la molette de la souris. Le zoom se centre sur le curseur.',
            'editor_kc_help_cancel_label' => 'Annuler',
            'editor_kc_help_cancel_body' => 'Appuie sur Échap pendant que tu traces une ligne pour l\'annuler.',
            'editor_kc_help_close' => 'Fermer',

            // C7h: avertissement de configuration nginx dans inc/main-view.php.
            'visitor_nginx_warning_heading' => 'Configuration Telaris : règle nginx pour les ressources versionnées non installée',
            'visitor_nginx_warning_intro' => 'Les modules JavaScript ne seront pas servis. Ajoute ce bloc au vhost nginx du serveur (en remplaçant le docroot s\'il diffère), puis exécute %s.',
            'visitor_nginx_warning_reload' => '<code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>',
            'visitor_nginx_warning_footer' => 'Cet avertissement disparaît automatiquement quand la règle sert %s avec HTTP 200.',
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
 * Prune keyword_position_history rows older than $maxAgeDays. Append-only by
 * design, but every canvas drag adds a row; without pruning the table dwarfs
 * the rest of the DB after months of editorial work. Operator runs this via
 * admin/cli/prune_history.php on a daily cron. Returns the number of rows
 * removed.
 */
function db_prune_keyword_position_history(int $maxAgeDays = 90): int {
    db_ensure_keyword_canvas_tables();
    $pdo = getDB();
    $age = max(1, (int)$maxAgeDays);
    $stmt = $pdo->prepare("DELETE FROM keyword_position_history WHERE moved_at < (NOW() - INTERVAL {$age} DAY)");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * L-third-7 (audit v6.10.13): unbounded prune of auth_attempts.
 *
 * The opportunistic in-process prune in db_record_auth_attempt is capped at
 * LIMIT 10000 per call so a long-stale table doesn't hold a long row lock
 * against concurrent INSERTs. Under high QPS the daily write rate can exceed
 * the daily drain rate, leaving the table to grow. This helper, called from
 * admin/cli/prune_logs.php via cron, drains the rest in one nightly pass
 * outside the request-serving path.
 */
function db_prune_auth_attempts(int $maxAgeDays = 30): int {
    db_ensure_auth_attempts_table();
    $pdo = getDB();
    $age = max(1, (int)$maxAgeDays);
    $stmt = $pdo->prepare("DELETE FROM auth_attempts WHERE created_at < (NOW() - INTERVAL {$age} DAY)");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * L-third-7 (audit v6.10.13): unbounded prune of audit_events. Sibling of
 * db_prune_auth_attempts. The default age tracks AUDIT_LOG_KEEP_DAYS
 * (operator-tunable, default 365, minimum 7 enforced).
 */
function db_prune_audit_events(?int $maxAgeDays = null): int {
    db_ensure_audit_events_table();
    $pdo = getDB();
    $keep = $maxAgeDays !== null
        ? max(7, (int)$maxAgeDays)
        : max(7, defined('AUDIT_LOG_KEEP_DAYS') ? (int)AUDIT_LOG_KEEP_DAYS : 365);
    $stmt = $pdo->prepare("DELETE FROM audit_events WHERE created_at < (NOW() - INTERVAL {$keep} DAY)");
    $stmt->execute();
    return $stmt->rowCount();
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
                sha256 CHAR(64) NULL,
                UNIQUE KEY unique_filename (filename),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Idempotent backfill: older installs predate the integrity column.
        // NULL means "no recorded checksum" — restore proceeds without
        // verification rather than refusing to restore legacy snapshots.
        $snapCols = $pdo->query("SHOW COLUMNS FROM snapshots")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('sha256', $snapCols, true)) {
            $pdo->exec("ALTER TABLE snapshots ADD COLUMN sha256 CHAR(64) NULL AFTER note");
        }
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

function db_get_user_by_id(string $userId): ?array {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, email, firstname, lastname, type FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
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

// ── Auth attempt throttling ──────────────────────────────────────────────────
//
// Sliding-window counters for login / forgot / reset. Pre-fix the auth
// surfaces accepted unlimited attempts. The auth_attempts table records each
// try (action + email + IP + success) and the count helpers below feed the
// gate at each entry point. Window and limit constants live next to the
// callers (utils/auth.php).

function db_ensure_auth_attempts_table(): void {
    $pdo = getDB();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auth_attempts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(16) NOT NULL,
            email VARCHAR(255) NULL,
            ip VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lookup (action, email, ip, created_at),
            INDEX idx_ip_window (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function db_record_auth_attempt(string $action, ?string $email, string $ip, bool $success): void {
    db_ensure_auth_attempts_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO auth_attempts (action, email, ip, success) VALUES (:action, :email, :ip, :success)
    ");
    $stmt->execute([
        ':action' => $action,
        ':email' => ($email !== null && $email !== '') ? mb_substr($email, 0, 255) : null,
        ':ip' => $ip !== '' ? mb_substr($ip, 0, 45) : '-',
        ':success' => $success ? 1 : 0,
    ]);
    // Opportunistic prune once per process: drop rows older than 30 days.
    // Capped at 10000 rows per pass so a long-stale table can't hold a
    // long row lock against concurrent INSERTs. Subsequent processes pick
    // up where this one stopped.
    static $pruned = false;
    if (!$pruned) {
        $pruned = true;
        try {
            $pdo->exec("DELETE FROM auth_attempts WHERE created_at < (NOW() - INTERVAL 30 DAY) LIMIT 10000");
        } catch (Throwable $_) {
            // Best-effort.
        }
    }
}

/**
 * Per-(action, IP) advisory lock around the auth-throttle gate. The audit
 * (M-C1, third pass) flagged a TOCTOU window between
 * db_count_recent_auth_attempts and db_record_auth_attempt: N parallel
 * requests from the same IP could each read count=THRESHOLD-1 and each
 * insert, exceeding the cap by N-1. Holding a per-(action, IP) MySQL named
 * lock serializes the count + (bcrypt) + record sequence for that IP
 * without serializing across IPs.
 *
 * On acquire failure (timeout, transient error) the caller treats it as
 * "throttled" (fail closed) — a brief contention burst from a single IP
 * is exactly the case the throttle is designed for.
 *
 * Connection-level locks release at end-of-request even if the caller
 * forgets, so a fatal error mid-gate doesn't leak the lock permanently.
 */
/**
 * Per-node advisory lock around the PUT /api/nodes.php upload/update path
 * (audit pass #5 / Race H1, v6.10.18). Two simultaneous PUTs on the same
 * node id were racing on fixed paths in uploads/{cid}/{id}/ — second writer
 * could overwrite or unlink the first writer's tmp file mid-extract. Holding
 * a per-node MySQL named lock serializes mutations on the same node row
 * without serializing across nodes.
 *
 * 5s wait is generous enough for sequential clicks but short enough that
 * a wedged worker can't pin the node for long. Connection-level lock
 * releases at end-of-request even if PHP fatal-errors before release.
 */
function db_node_lock_acquire(int $nodeId): array {
    $pdo = getDB();
    $key = 'telaris:node:' . $nodeId;
    $stmt = $pdo->prepare("SELECT GET_LOCK(:k, 5)");
    $stmt->execute([':k' => $key]);
    $result = $stmt->fetchColumn();
    return ['key' => $key, 'acquired' => $result === 1 || $result === '1'];
}

function db_node_lock_release(array $lock): void {
    if (empty($lock['acquired'])) return;
    $pdo = getDB();
    try {
        $pdo->prepare("SELECT RELEASE_LOCK(:k)")->execute([':k' => $lock['key']]);
    } catch (Throwable $_) {
        // Best-effort. Connection close at end-of-request will release anyway.
    }
}

function db_auth_throttle_lock_acquire(string $action, string $ip): array {
    $pdo = getDB();
    $key = 'telaris:auth_throttle:' . $action . ':' . ($ip !== '' ? $ip : '-');
    // 5s wait is generous enough for normal serialization but short
    // enough that a wedged worker can't pin the gate for long. On
    // contention the caller fails closed (treats as throttled).
    $stmt = $pdo->prepare("SELECT GET_LOCK(:k, 5)");
    $stmt->execute([':k' => $key]);
    $result = $stmt->fetchColumn();
    return ['key' => $key, 'acquired' => $result === 1 || $result === '1'];
}

function db_auth_throttle_lock_release(array $lock): void {
    if (empty($lock['acquired'])) return;
    $pdo = getDB();
    try {
        $pdo->prepare("SELECT RELEASE_LOCK(:k)")->execute([':k' => $lock['key']]);
    } catch (Throwable $_) {
        // Best-effort. Connection close at end-of-request will release anyway.
    }
}

/**
 * Count attempts in the recent window. Pass null for $email or $ip to skip
 * that axis. $successFilter null counts everything, true counts only
 * successes, false counts only failures.
 */
function db_count_recent_auth_attempts(
    string $action,
    ?string $email,
    ?string $ip,
    int $windowSeconds,
    ?bool $successFilter = false
): int {
    db_ensure_auth_attempts_table();
    $pdo = getDB();
    $sql = "SELECT COUNT(*) FROM auth_attempts WHERE action = :action AND created_at >= (NOW() - INTERVAL " . max(1, (int)$windowSeconds) . " SECOND)";
    $params = [':action' => $action];
    if ($email !== null && $email !== '') {
        $sql .= " AND email = :email";
        $params[':email'] = $email;
    }
    if ($ip !== null && $ip !== '') {
        $sql .= " AND ip = :ip";
        $params[':ip'] = $ip;
    }
    if ($successFilter !== null) {
        $sql .= " AND success = " . ($successFilter ? '1' : '0');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// ── Audit log ────────────────────────────────────────────────────────────────
//
// Append-only record of meaningful operator and editorial actions: who did
// what, to which target, when, from which IP. Distinct from auth_attempts
// (which is about authentication rate-limiting) and the per-row created_by
// columns (which record content authorship). The audit log captures
// administrative actions whose history doesn't survive any single row:
// user provisioning, snapshot restores, galaxy deletions, bridge imports,
// schema migrations.
//
// Retention is operator-tunable. Add the following line to config.php to
// override the 365-day default (minimum 7 days enforced):
//
//   define('AUDIT_LOG_KEEP_DAYS', 730);   // keep audit history two years
//
// The prune runs opportunistically on the first audit write per request,
// capped at 10000 rows per pass so a long-stale table can't hold a long
// row lock against concurrent INSERTs.

function db_ensure_audit_events_table(): void {
    $pdo = getDB();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_events (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(64) NOT NULL,
            actor_user_id VARCHAR(255) NULL,
            actor_email_tag VARCHAR(64) NULL,
            target_type VARCHAR(32) NULL,
            target_id VARCHAR(64) NULL,
            details_json JSON NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action_time (action, created_at),
            INDEX idx_actor_time (actor_user_id, created_at),
            INDEX idx_target (target_type, target_id),
            CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Whitelist of action strings db_audit_log accepts. Callers passing anything
 * outside this set get their action prefixed as `unknown.` and logged via
 * error_log so a typo or a renamed event surfaces during code review
 * rather than silently polluting the audit_events table. Keep this list
 * sorted; add new actions as their hook sites land.
 */
const AUDIT_LOG_KNOWN_ACTIONS = [
    'backup.export',
    'backup.import',
    'backup.import.failed',
    'bridge.mocambos.import.finish',
    'bridge.mocambos.import.start',
    'cluster.create',
    'cluster.delete',
    'cluster.update',
    'galaxy.create',
    'galaxy.delete',
    'galaxy.duplicate',
    'password.reset.consumed',
    'snapshot.create.cli',
    'snapshot.create.manual',
    'snapshot.create.scheduled',
    'snapshot.delete',
    'snapshot.download',
    'snapshot.restore',
    'snapshot.restore.failed',
    'snapshot.schedule.update',
    'user.create',
    'user.create.bulk',
    'user.create.cli',
    'user.delete',
    'user.update',
];

/**
 * Record an audit event. All arguments are optional except $action; failures
 * are swallowed (audit logging must never break the work it observes). The
 * caller can pass null for actor / target / details when they are not
 * known or not applicable.
 *
 * Action strings outside AUDIT_LOG_KNOWN_ACTIONS land in the table prefixed
 * with `unknown.` and trigger an error_log breadcrumb. That's the defensive
 * floor against a future caller passing an attacker-influenced string into
 * the action slot (no such caller exists today; the floor exists so it
 * stays that way).
 */
function db_audit_log(
    string $action,
    ?string $actorUserId = null,
    ?string $targetType = null,
    ?string $targetId = null,
    ?array $details = null,
    ?string $ip = null,
    ?string $actorEmail = null
): void {
    try {
        db_ensure_audit_events_table();
        $pdo = getDB();
        if (!in_array($action, AUDIT_LOG_KNOWN_ACTIONS, true)) {
            error_log('db_audit_log: unknown action "' . mb_substr($action, 0, 64) . '" — recording as unknown.* and continuing');
            $action = 'unknown.' . mb_substr($action, 0, 56);
        }
        $actorTag = null;
        if ($actorEmail !== null && $actorEmail !== '') {
            // Mirror mail_recipient_tag: stable SHA-256 prefix that lets ops
            // cross-reference between a reported address and a log row without
            // storing the address itself. Inlined to avoid coupling on
            // inc/mail.php being loaded (the helper is fail-open by design).
            $actorTag = 'addr:' . substr(hash('sha256', strtolower(trim($actorEmail))), 0, 12);
        }
        $stmt = $pdo->prepare("
            INSERT INTO audit_events (action, actor_user_id, actor_email_tag, target_type, target_id, details_json, ip)
            VALUES (:action, :actor, :tag, :ttype, :tid, :details, :ip)
        ");
        $stmt->execute([
            ':action' => mb_substr($action, 0, 64),
            ':actor' => $actorUserId !== null && $actorUserId !== '' ? mb_substr($actorUserId, 0, 255) : null,
            ':tag' => $actorTag,
            ':ttype' => $targetType !== null && $targetType !== '' ? mb_substr($targetType, 0, 32) : null,
            ':tid' => $targetId !== null && $targetId !== '' ? mb_substr($targetId, 0, 64) : null,
            ':details' => $details !== null ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':ip' => $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null,
        ]);
        // Opportunistic prune once per process: drop rows older than retention.
        // Capped at 10000 rows per pass so a long-stale table can't hold a
        // long row lock against concurrent INSERTs. Subsequent processes pick
        // up where this one stopped.
        static $pruned = false;
        if (!$pruned) {
            $pruned = true;
            $keepDays = defined('AUDIT_LOG_KEEP_DAYS') ? max(7, (int)AUDIT_LOG_KEEP_DAYS) : 365;
            try {
                $pdo->exec("DELETE FROM audit_events WHERE created_at < (NOW() - INTERVAL {$keepDays} DAY) LIMIT 10000");
            } catch (Throwable $e) {
                error_log('db_audit_log: prune failed: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        // Audit logging never breaks the caller. Surface the failure so ops
        // notices that the audit pipeline is broken.
        error_log('db_audit_log failed (' . $action . '): ' . $e->getMessage());
    }
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
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
        $pdo->beginTransaction();
    }
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
        if ($ownTxn) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
        $filterVal = '%' . addcslashes($filter, '%_\\') . '%';
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
        $filterVal = '%' . addcslashes($filter, '%_\\') . '%';
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

/**
 * Bulk variant of db_get_constellation_by_id. Returns an array indexed by id
 * so callers can look up each member without per-row queries. The visitor
 * multigalaxy bootstrap previously fired one query per cluster member; this
 * collapses that fan-out to a single round trip.
 *
 * @return array<int, array{name:string, tagline:string, slug:?string, theme:string, import_source:?string, type:string, show_galaxy_list:bool}>
 */
function db_get_constellations_by_ids(array $ids): array {
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));
    if ($ids === []) return [];
    db_ensure_constellations_import_source_column();
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, tagline, slug, theme, import_source, `type`, show_galaxy_list FROM constellations WHERE id IN ($place)");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = [
            'name' => (string)($row['name'] ?? ''),
            'tagline' => (string)($row['tagline'] ?? ''),
            'slug' => $row['slug'],
            'theme' => (string)($row['theme'] ?? 'cosmic'),
            'import_source' => $row['import_source'] ?? null,
            'type' => (string)($row['type'] ?? 'galaxy'),
            'show_galaxy_list' => (bool)($row['show_galaxy_list'] ?? false),
        ];
    }
    return $out;
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
 * Bulk variant of db_get_constellation_tour_config. Returns an array indexed
 * by constellation id; absent ids yield no entry. Folds the per-cluster-member
 * fan-out in the multigalaxy bootstrap (two queries per member) into two
 * queries total: one for the tour columns, one for the tour-keyword rows.
 *
 * @return array<int, array{tour_enabled:bool, tour_start_mode:string, tour_idle_seconds:int, tour_node_selection:string, tour_random_count:int, tour_default_dwell:int, tour_loop:bool, tour_keyword_ids:list<int>, keyword_chips_enabled:bool, idle_spotlight_enabled:bool, idle_spotlight_selection:string, idle_spotlight_idle_seconds:int, related_nodes_enabled:bool, show_2d_view:bool}>
 */
function db_get_tour_configs_for_ids(array $ids): array {
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));
    if ($ids === []) return [];
    db_ensure_constellations_tour_columns();
    $pdo = getDB();
    $place = implode(',', array_fill(0, count($ids), '?'));

    // Two SELECTs (tour columns + keyword junction) wrapped in a transaction
    // so they read a consistent snapshot under InnoDB's default REPEATABLE READ
    // isolation. Without this, a concurrent db_set_tour_keyword_ids landing
    // between the queries could yield a stale-keyword frame against fresh tour
    // columns; visitor-side this is benign (one out-of-date frame), but the
    // ordering invariant is cheap to make explicit. Skip when already in a
    // transaction so callers stay in charge of the boundary.
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, tour_enabled, tour_start_mode, tour_idle_seconds, tour_node_selection,
                   tour_random_count, tour_default_dwell, tour_loop, keyword_chips_enabled,
                   idle_spotlight_enabled, idle_spotlight_selection, idle_spotlight_idle_seconds,
                   related_nodes_enabled, show_2d_view
            FROM constellations WHERE id IN ($place)
        ");
        $stmt->execute($ids);
        $configs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['id'];
            $configs[$cid] = [
                'tour_enabled' => (bool)$row['tour_enabled'],
                'tour_start_mode' => (string)$row['tour_start_mode'],
                'tour_idle_seconds' => (int)$row['tour_idle_seconds'],
                'tour_node_selection' => (string)$row['tour_node_selection'],
                'tour_random_count' => (int)$row['tour_random_count'],
                'tour_default_dwell' => (int)$row['tour_default_dwell'],
                'tour_loop' => (bool)$row['tour_loop'],
                'tour_keyword_ids' => [],
                'keyword_chips_enabled' => (bool)$row['keyword_chips_enabled'],
                'idle_spotlight_enabled' => (bool)$row['idle_spotlight_enabled'],
                'idle_spotlight_selection' => (string)$row['idle_spotlight_selection'],
                'idle_spotlight_idle_seconds' => (int)$row['idle_spotlight_idle_seconds'],
                'related_nodes_enabled' => (bool)$row['related_nodes_enabled'],
                'show_2d_view' => (bool)$row['show_2d_view'],
            ];
        }
        if ($configs === []) {
            if ($ownTxn) $pdo->commit();
            return [];
        }

        $foundIds = array_keys($configs);
        $place2 = implode(',', array_fill(0, count($foundIds), '?'));
        $kstmt = $pdo->prepare("SELECT constellation_id, keyword_id FROM constellation_tour_keywords WHERE constellation_id IN ($place2) ORDER BY constellation_id, keyword_id");
        $kstmt->execute($foundIds);
        foreach ($kstmt->fetchAll(PDO::FETCH_ASSOC) as $kr) {
            $configs[(int)$kr['constellation_id']]['tour_keyword_ids'][] = (int)$kr['keyword_id'];
        }
        if ($ownTxn) $pdo->commit();
        return $configs;
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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

    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare("DELETE FROM constellation_tour_keywords WHERE constellation_id = :cid")
            ->execute([':cid' => $constellationId]);
        if ($allowed !== []) {
            $insert = $pdo->prepare("INSERT INTO constellation_tour_keywords (constellation_id, keyword_id) VALUES (:cid, :kid)");
            foreach ($allowed as $kid) {
                $insert->execute([':cid' => $constellationId, ':kid' => $kid]);
            }
        }
        if ($ownTxn) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
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
        // 1. Delete portals in OTHER constellations that point to THIS constellation.
        // Bulk-delete to keep the round trips constant — pre-fix this was a per-row
        // N+1 on every galaxy delete that had referencing portals.
        $referencing = db_get_referencing_portals($id);
        if ($referencing !== []) {
            db_bulk_delete_nodes_by_ids(array_map(fn($r) => (int)$r['id'], $referencing));
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
    // Schema-ensure probes are no-ops in steady state but each was a DB round
    // trip on every call; the visitor scene loader hits this once per page.
    // Run the probes at most once per request.
    static $ensured = false;
    if (!$ensured) {
        db_ensure_nodes_show_keywords_column();
        db_ensure_nodes_use_image_as_node_column();
        db_ensure_nodes_icon_url_column();
        db_ensure_nodes_image_attribution_column();
        db_ensure_nodes_clustering_columns();
        db_ensure_nodes_pdf_url_column();
        $ensured = true;
    }
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
        $filterVal = '%' . addcslashes($filter, '%_\\') . '%';
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
    db_bulk_delete_nodes_by_ids([$id]);
}

/**
 * Bulk-delete every node whose id is in $ids. Reads all asset paths in one
 * query, runs a single DELETE, then unlinks files after the DB commits.
 * Mirrors db_bulk_delete_nodes_by_constellation but scoped by id list.
 * node_keywords cascades from nodes.id.
 *
 * Replaces the api/nodes.php bulk-by-keyword delete loop (one round trip per
 * node) with a constant number of round trips regardless of $ids count.
 */
function db_bulk_delete_nodes_by_ids(array $ids): int {
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));
    if ($ids === []) return 0;
    $pdo = getDB();
    $place = implode(',', array_fill(0, count($ids), '?'));

    // 1. Read every asset path in one SELECT.
    $stmt = $pdo->prepare("SELECT image_url, icon_url, audio_url, video_url, pdf_url FROM nodes WHERE id IN ($place)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();
    if ($rows === []) return 0;

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

    // 2. Single batch DELETE; node_keywords cascade on nodes.id.
    $del = $pdo->prepare("DELETE FROM nodes WHERE id IN ($place)");
    $del->execute($ids);
    $deleted = $del->rowCount();

    // 3. Unlink files only after the DB delete succeeded.
    foreach ($filesToDelete as $path) {
        @unlink($path);
    }
    return $deleted;
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
        $ids = array_map('intval', array_keys($targetCids));
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, slug FROM constellations WHERE id IN ($place)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $rr) {
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
    // The ALTER TABLE … AUTO_INCREMENT = 1 statements below are implicit-COMMIT
    // DDL under MySQL. If called inside a caller-managed transaction, the wipe
    // would commit halfway and the outer commit/rollBack would no-op silently.
    // No current caller does this; the guard closes a sharp edge.
    if ($pdo->inTransaction()) {
        throw new RuntimeException('db_wipe_all_data: must not run inside a transaction (DDL would implicit-commit).');
    }
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
// Federation schema (stage 1a)
// ---------------------------------------------------------------------------
//
// Telaris-side federation tables and additive columns. All idempotent, lazy
// at first call. Spec: P2P federation plan v10 § Schema → Telaris-side.
//
// Foreign-key topology means several helpers chain into db_ensure_peers_table
// before touching their own table. The chains are explicit rather than
// implicit so a single helper can be invoked from a smoke path without
// surprise.
//
// No code calls these yet at stage 1a; they exist so subsequent stages
// (1b identity keys, 1c identity endpoint, 1d OpenAPI, 1e HTTP Signatures)
// find the schema present when they need it. The DbEnsureIdempotencyTest
// auto-discovers and exercises them; the helper-count floor rises with them.

function db_ensure_peers_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS peers (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                hostname VARCHAR(255) NOT NULL,
                url VARCHAR(512) NOT NULL,
                pluriverse_endpoint VARCHAR(512) NOT NULL,
                public_key VARBINARY(32) NOT NULL,
                previous_public_key VARBINARY(32) NULL,
                key_rotated_at TIMESTAMP NULL,
                rotation_reason ENUM('scheduled','operational','compromise') NULL,
                label VARCHAR(255) NOT NULL,
                bridges JSON NULL,
                source ENUM('registry','manual') NOT NULL DEFAULT 'manual',
                source_detail VARCHAR(255) NULL,
                trust_state ENUM('discovered','contacted','whitelisted','blocked') NOT NULL DEFAULT 'discovered',
                has_active_whitelist BOOLEAN NOT NULL DEFAULT FALSE,
                local_nickname VARCHAR(255) NULL,
                local_blacklisted_reason TEXT NULL,
                last_seen_at TIMESTAMP NULL,
                health_status ENUM('up','degraded','down','unknown') NOT NULL DEFAULT 'unknown',
                manual_added_by VARCHAR(255) NULL,
                manual_added_at TIMESTAMP NULL,
                manual_reauth_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_hostname (hostname)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_peers_table: ' . $e->getMessage());
    }
}

function db_ensure_peer_keys_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS peer_keys (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                peer_id INT UNSIGNED NOT NULL,
                api_key_hash VARBINARY(32) NOT NULL,
                direction ENUM('they_call_us','we_call_them') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                UNIQUE KEY uniq_api_key_hash (api_key_hash),
                INDEX idx_peer_direction (peer_id, direction),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_peer_keys_table: ' . $e->getMessage());
    }
}

function db_ensure_galaxy_publish_whitelist_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS galaxy_publish_whitelist (
                peer_id INT UNSIGNED NOT NULL,
                constellation_id INT NOT NULL,
                added_by VARCHAR(255) NULL,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (peer_id, constellation_id),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE,
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_galaxy_publish_whitelist_table: ' . $e->getMessage());
    }
}

function db_ensure_galaxy_subscriptions_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS galaxy_subscriptions (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                peer_id INT UNSIGNED NOT NULL,
                remote_slug VARCHAR(255) NOT NULL,
                local_constellation_id INT NULL,
                last_synced_at TIMESTAMP NULL,
                last_content_hash VARCHAR(128) NULL,
                last_received_sequence BIGINT UNSIGNED NULL,
                last_rejected_sequence BIGINT UNSIGNED NULL,
                added_by VARCHAR(255) NULL,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                UNIQUE KEY uniq_peer_remote (peer_id, remote_slug),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE,
                FOREIGN KEY (local_constellation_id) REFERENCES constellations(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_galaxy_subscriptions_table: ' . $e->getMessage());
    }
}

function db_ensure_retracted_galaxies_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS retracted_galaxies (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                constellation_id INT NULL,
                slug VARCHAR(255) NOT NULL,
                retracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                retracted_by VARCHAR(255) NULL,
                reason TEXT NULL,
                UNIQUE KEY uniq_slug (slug),
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_retracted_galaxies_table: ' . $e->getMessage());
    }
}

function db_ensure_pluriverse_messages_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pluriverse_messages (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                peer_id INT UNSIGNED NOT NULL,
                direction ENUM('inbound','outbound') NOT NULL,
                thread_id VARCHAR(64) NOT NULL,
                message_type VARCHAR(32) NOT NULL,
                subject VARCHAR(255) NULL,
                body MEDIUMTEXT NULL,
                payload JSON NULL,
                jws_envelope MEDIUMTEXT NOT NULL,
                is_read BOOLEAN NOT NULL DEFAULT FALSE,
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_peer_thread (peer_id, thread_id),
                INDEX idx_unread (peer_id, is_read),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_messages_table: ' . $e->getMessage());
    }
}

function db_ensure_seen_nonces_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS seen_nonces (
                origin_host VARCHAR(255) NOT NULL,
                nonce VARBINARY(32) NOT NULL,
                seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (origin_host, nonce),
                INDEX idx_seen_at (seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_seen_nonces_table: ' . $e->getMessage());
    }
}

function db_ensure_key_events_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS key_events (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                origin_host VARCHAR(255) NOT NULL,
                event_type ENUM('scheduled_rotation','operational_rotation','compromise','revocation') NOT NULL,
                occurred_at TIMESTAMP NOT NULL,
                signed_payload MEDIUMTEXT NOT NULL,
                received_via ENUM('push','poll') NOT NULL,
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_origin_occurred (origin_host, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_key_events_table: ' . $e->getMessage());
    }
}

// Creates both pluriverse_log and pluriverse_log_archive. The archive shares
// the hot table's shape; nightly archival moves rows >90 days from hot to
// archive in batched transactions, and a separate job GCs archive rows
// >1 year. Both jobs are stage 4+; the tables exist from stage 1a so the
// archival pipeline can be enabled later without a schema bump.
function db_ensure_pluriverse_log_tables(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pluriverse_log (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                event_type VARCHAR(64) NOT NULL,
                actor VARCHAR(255) NULL,
                target VARCHAR(255) NULL,
                outcome ENUM('success','failure','warning') NOT NULL,
                details_summary VARCHAR(1024) NULL,
                ip_hash VARBINARY(32) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type, created_at),
                INDEX idx_actor (actor, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // LIKE-copy needs the source table to exist; idempotent via IF NOT EXISTS.
        $pdo->exec("CREATE TABLE IF NOT EXISTS pluriverse_log_archive LIKE pluriverse_log");
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_log_tables: ' . $e->getMessage());
    }
}

// Additive columns on existing Telaris tables for federation provenance.
// constellations: mirrored_from_peer_id (FK peers), read_only, source_attribution (JSON).
// nodes, keywords, node_keywords, keyword_relations: author_attribution_text.
// Each column-add is guarded by SHOW COLUMNS so re-runs are no-ops.
function db_ensure_federation_attribution_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    // keyword_relations is created lazily by db_ensure_keyword_canvas_tables()
    // on first canvas use. On instances where no editor has touched the canvas
    // yet, the table is missing and our ALTER below would fail. Chain it so
    // the federation column-add is safe on every Telaris instance.
    db_ensure_keyword_canvas_tables();
    try {
        $pdo = getDB();
        $col = function(string $table, string $colName) use ($pdo): bool {
            $stmt = $pdo->prepare("
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
                LIMIT 1
            ");
            $stmt->execute([':t' => $table, ':c' => $colName]);
            return (bool)$stmt->fetchColumn();
        };
        $constraint = function(string $table, string $name) use ($pdo): bool {
            $stmt = $pdo->prepare("
                SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n
                LIMIT 1
            ");
            $stmt->execute([':t' => $table, ':n' => $name]);
            return (bool)$stmt->fetchColumn();
        };

        if (!$col('constellations', 'mirrored_from_peer_id')) {
            $pdo->exec("ALTER TABLE constellations
                ADD COLUMN mirrored_from_peer_id INT UNSIGNED NULL DEFAULT NULL,
                ADD INDEX idx_constellations_mirrored_from_peer (mirrored_from_peer_id)");
        }
        if (!$constraint('constellations', 'fk_constellations_mirrored_from_peer')) {
            try {
                $pdo->exec("ALTER TABLE constellations
                    ADD CONSTRAINT fk_constellations_mirrored_from_peer
                        FOREIGN KEY (mirrored_from_peer_id) REFERENCES peers(id) ON DELETE CASCADE");
            } catch (PDOException $e) {
                error_log('db_ensure_federation_attribution_columns: FK add skipped: ' . $e->getMessage());
            }
        }
        if (!$col('constellations', 'read_only')) {
            $pdo->exec("ALTER TABLE constellations
                ADD COLUMN read_only BOOLEAN NOT NULL DEFAULT FALSE");
        }
        if (!$col('constellations', 'source_attribution')) {
            $pdo->exec("ALTER TABLE constellations
                ADD COLUMN source_attribution JSON NULL DEFAULT NULL");
        }

        foreach (['nodes', 'keywords', 'node_keywords', 'keyword_relations'] as $table) {
            if (!$col($table, 'author_attribution_text')) {
                $pdo->exec("ALTER TABLE `$table`
                    ADD COLUMN author_attribution_text VARCHAR(255) NULL DEFAULT NULL");
            }
        }
    } catch (PDOException $e) {
        error_log('db_ensure_federation_attribution_columns: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Federation schema (stage 3): Pluriverse pull
// ---------------------------------------------------------------------------
//
// Local mirror of the Pluriverse blacklist (entity_type: hostname / ip / domain)
// plus a small state table tracking the cursor + last-success / last-error per
// pull endpoint. Together with the existing peers + key_events tables (stage 1),
// these are everything the stage-3 pullers write into.

function db_ensure_pluriverse_blacklist_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pluriverse_blacklist (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                entry_type ENUM('hostname','ip','domain') NOT NULL,
                entry_value VARCHAR(255) NOT NULL,
                reason TEXT NULL,
                added_by VARCHAR(255) NULL,
                added_at TIMESTAMP NOT NULL,
                pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_entry (entry_type, entry_value),
                INDEX idx_entry_value (entry_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $col = function(string $colName) use ($pdo): bool {
            $s = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = 'pluriverse_blacklist'
                                AND COLUMN_NAME = :c LIMIT 1");
            $s->execute([':c' => $colName]);
            return (bool)$s->fetchColumn();
        };
        if ($col('entity_type')) {
            $pdo->exec("ALTER TABLE pluriverse_blacklist
                        CHANGE COLUMN entity_type entry_type ENUM('hostname','ip','domain') NOT NULL");
        }
        if ($col('entity_value')) {
            $pdo->exec("ALTER TABLE pluriverse_blacklist
                        CHANGE COLUMN entity_value entry_value VARCHAR(255) NOT NULL");
        }
        if (!$col('added_by')) {
            $pdo->exec("ALTER TABLE pluriverse_blacklist
                        ADD COLUMN added_by VARCHAR(255) NULL AFTER reason");
        }
        $r = $pdo->query("SELECT EXTRA FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'pluriverse_blacklist'
                          AND COLUMN_NAME = 'id' LIMIT 1");
        $extra = (string)$r->fetchColumn();
        if (stripos($extra, 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE pluriverse_blacklist
                        MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
        }
        $idxOld = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS
                                 WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'pluriverse_blacklist'
                                 AND INDEX_NAME = 'uniq_entity' LIMIT 1");
        $idxOld->execute();
        if ($idxOld->fetchColumn()) {
            try {
                $pdo->exec("ALTER TABLE pluriverse_blacklist DROP INDEX uniq_entity");
                $pdo->exec("ALTER TABLE pluriverse_blacklist ADD UNIQUE KEY uniq_entry (entry_type, entry_value)");
            } catch (PDOException $e) {
                error_log('db_ensure_pluriverse_blacklist_table: uniq index migrate skipped: ' . $e->getMessage());
            }
        }
        $idxOld2 = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS
                                  WHERE TABLE_SCHEMA = DATABASE()
                                  AND TABLE_NAME = 'pluriverse_blacklist'
                                  AND INDEX_NAME = 'idx_entity_value' LIMIT 1");
        $idxOld2->execute();
        if ($idxOld2->fetchColumn()) {
            try {
                $pdo->exec("ALTER TABLE pluriverse_blacklist DROP INDEX idx_entity_value");
                $pdo->exec("ALTER TABLE pluriverse_blacklist ADD INDEX idx_entry_value (entry_value)");
            } catch (PDOException $e) {
                error_log('db_ensure_pluriverse_blacklist_table: idx rename skipped: ' . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_blacklist_table: ' . $e->getMessage());
    }
}

function db_ensure_pluriverse_pull_state_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pluriverse_pull_state (
                endpoint VARCHAR(64) PRIMARY KEY,
                last_seen_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_etag VARCHAR(64) NULL,
                last_modified VARCHAR(64) NULL,
                last_pull_started_at TIMESTAMP NULL,
                last_pull_succeeded_at TIMESTAMP NULL,
                last_pull_failed_at TIMESTAMP NULL,
                last_error VARCHAR(1024) NULL,
                consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
                rows_processed_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $col = function(string $colName) use ($pdo): bool {
            $s = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = 'pluriverse_pull_state'
                                AND COLUMN_NAME = :c LIMIT 1");
            $s->execute([':c' => $colName]);
            return (bool)$s->fetchColumn();
        };
        if (!$col('last_etag')) {
            $pdo->exec("ALTER TABLE pluriverse_pull_state
                        ADD COLUMN last_etag VARCHAR(64) NULL AFTER last_seen_id");
        }
        if (!$col('last_modified')) {
            $pdo->exec("ALTER TABLE pluriverse_pull_state
                        ADD COLUMN last_modified VARCHAR(64) NULL AFTER last_etag");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_pull_state_table: ' . $e->getMessage());
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
            // Best-effort; we are already in an error path. Log so a stuck
            // FOREIGN_KEY_CHECKS=0 connection state has a breadcrumb.
            error_log('hard_reset re-enable FK_CHECKS failed: ' . $e2->getMessage());
        }
        $errors[] = "Database error: " . $e->getMessage();
    }
    return ['dropped' => $dropped, 'errors' => $errors];
}

/**
 * Canonical instance name for the federation surface.
 *
 * Returns the operator-set `name` from the EN project_info row when it is
 * non-empty (the EN row is the locale-invariant identifier per the identity
 * envelope's existing convention). Otherwise derives a sensible default from
 * the request hostname's leftmost dot-separated label, lowercased
 * (starmaps.polivoxia.ca -> "starmaps", telaris.polivoxia.ca -> "telaris",
 * www.telaris.ca -> "www"). Operators change this via the Global Settings
 * tab; the apply flow + the identity envelope both read through this helper.
 */
function db_get_instance_name(): string {
    try {
        $pdo = getDB();
        db_ensure_project_info_table();
        $stmt = $pdo->prepare("SELECT name FROM project_info WHERE locale = 'en' LIMIT 1");
        $stmt->execute();
        $name = trim((string)($stmt->fetchColumn() ?: ''));
        if ($name !== '') return $name;
    } catch (Throwable $e) {
        error_log('db_get_instance_name: ' . $e->getMessage());
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (str_contains($host, ':')) {
        $host = (string)strstr($host, ':', true);
    }
    $host = strtolower($host);
    if ($host === '') return '';
    $parts = explode('.', $host);
    return $parts[0];
}

function db_set_instance_name(string $name): void {
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Instance name must not be empty.');
    }
    if (mb_strlen($name) > 255) {
        throw new InvalidArgumentException('Instance name must be 255 characters or fewer.');
    }
    db_ensure_project_info_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE project_info SET name = :n WHERE locale = 'en'");
    $stmt->execute([':n' => $name]);
}

// ---------------------------------------------------------------------------
// pluriverse_applications: local record of THIS instance's submission to the
// Pluriverse. At most one active (pending|verified|published) row at a time.
// Status drifts from the Pluriverse's record; this table is a UI breadcrumb
// for the operator, not a source of truth for federation state.
// ---------------------------------------------------------------------------

function db_ensure_pluriverse_applications_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pluriverse_applications (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                operator_email VARCHAR(254) NOT NULL,
                label VARCHAR(255) NOT NULL,
                remote_instance_id INT UNSIGNED NULL,
                remote_fingerprint VARCHAR(64) NULL,
                pluriverse_url VARCHAR(255) NOT NULL,
                status ENUM('pending','verified','published','rejected','blacklisted','withdrawn','expired','revoked','outdated') NOT NULL DEFAULT 'pending',
                last_polled_at TIMESTAMP NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status, submitted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // 2026-05-25: ENUM grows 'expired', then 'revoked'/'outdated' to cover
        // every Pluriverse-side admission_status the status-sync poll can
        // surface back. Probe COLUMN_TYPE to avoid the MODIFY when the table
        // already has the latest shape.
        $info = $pdo->query("
            SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pluriverse_applications' AND COLUMN_NAME = 'status'
        ")->fetchColumn();
        if (is_string($info) && (strpos($info, "'expired'") === false
                                  || strpos($info, "'revoked'") === false
                                  || strpos($info, "'outdated'") === false)) {
            $pdo->exec("ALTER TABLE pluriverse_applications MODIFY COLUMN status ENUM('pending','verified','published','rejected','blacklisted','withdrawn','expired','revoked','outdated') NOT NULL DEFAULT 'pending'");
        }
        // 2026-05-25: last_polled_at tracks the last status-sync round-trip
        // so the admin page can rate-limit polls to once per 5 min.
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pluriverse_applications'
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_polled_at', array_map('strval', $cols), true)) {
            $pdo->exec("ALTER TABLE pluriverse_applications ADD COLUMN last_polled_at TIMESTAMP NULL AFTER status");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_applications_table: ' . $e->getMessage());
    }
}

/**
 * Lazy sweep: flip any local pending row past its 24h window to 'expired'.
 * Mirrors the Pluriverse-side helper so the instance UI reflects expiry
 * even before the operator re-applies. The 24-hour horizon matches the
 * Pluriverse's verify_by_at deadline minted at apply time.
 */
function db_expire_stale_pluriverse_applications(): int {
    db_ensure_pluriverse_applications_table();
    $stmt = getDB()->prepare("
        UPDATE pluriverse_applications
        SET status = 'expired'
        WHERE status = 'pending' AND submitted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Most recent application row, or null if none. The operator can submit again
 * once any prior row is withdrawn / rejected / expired; the form is shown if
 * either no row exists or the latest is in a terminal status. Runs the
 * stale-pending sweep before returning so a row in 'pending' past its 24h
 * window surfaces as 'expired' to callers.
 */
function db_get_latest_pluriverse_application(): ?array {
    db_ensure_pluriverse_applications_table();
    db_expire_stale_pluriverse_applications();
    $row = getDB()->query("SELECT * FROM pluriverse_applications ORDER BY id DESC LIMIT 1")->fetch();
    return is_array($row) ? $row : null;
}

function db_pluriverse_has_active_application(): bool {
    $row = db_get_latest_pluriverse_application();
    if ($row === null) return false;
    return !in_array($row['status'], ['rejected', 'withdrawn', 'expired'], true);
}

function db_record_pluriverse_application(string $email, string $label, string $pluriverseUrl, ?int $remoteId, ?string $remoteFingerprint): int {
    db_ensure_pluriverse_applications_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO pluriverse_applications (operator_email, label, pluriverse_url, remote_instance_id, remote_fingerprint, status)
        VALUES (:email, :label, :url, :rid, :fp, 'pending')
    ");
    $stmt->execute([
        ':email' => $email,
        ':label' => $label,
        ':url' => $pluriverseUrl,
        ':rid' => $remoteId,
        ':fp' => $remoteFingerprint,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Sign a GET to the Pluriverse asking for THIS instance's current
 * admission_status, parse the response, and update the latest local
 * pluriverse_applications row to match. Rate-limited to once every
 * five minutes via last_polled_at. Idempotent: if no application row
 * exists, or if the row is too fresh to re-poll, returns without
 * doing anything.
 *
 * Why signed GET (no body): the Pluriverse identifies the asking
 * instance from the signer's keyid (`<hostname>:<fingerprint>`). It
 * looks up the matching instance row and returns the current status.
 * Other instances' status cannot be asked for.
 *
 * Failure modes (logged but silent to the caller, since the admin tab
 * always rendered fine before status sync existed):
 *   - secrets/pluriverse.key missing → bin/init-identity has not run
 *   - Pluriverse unreachable → network blip, retry next poll window
 *   - 404 from Pluriverse → instance row was deleted on the Pluriverse,
 *     local row left as is (operator can re-join)
 */
function db_refresh_pluriverse_remote_status(): void {
    db_ensure_pluriverse_applications_table();

    $pdo = getDB();
    $row = $pdo->query("SELECT * FROM pluriverse_applications ORDER BY id DESC LIMIT 1")->fetch();
    if (!is_array($row)) return;

    // Rate limit: once per 5 minutes per row.
    if ($row['last_polled_at'] !== null
        && strtotime((string)$row['last_polled_at']) >= time() - 300
    ) {
        return;
    }

    $remote = db_fetch_pluriverse_remote_status_signed();
    // Always touch last_polled_at, even on transient failure, so a down
    // Pluriverse doesn't get hammered every admin pageload.
    $pdo->prepare("UPDATE pluriverse_applications SET last_polled_at = NOW() WHERE id = :id")
        ->execute([':id' => (int)$row['id']]);

    if ($remote === null) return;
    if (!isset($remote['admission_status'])) return;
    $newStatus = (string)$remote['admission_status'];
    $valid = ['pending','verified','published','rejected','blacklisted','withdrawn','expired','revoked','outdated'];
    if (!in_array($newStatus, $valid, true)) return;
    if ($newStatus === (string)$row['status']) return;

    $pdo->prepare("UPDATE pluriverse_applications SET status = :s WHERE id = :id")
        ->execute([':s' => $newStatus, ':id' => (int)$row['id']]);
}

/**
 * Sign + send the GET, parse the JSON response, return the decoded
 * array or null on any failure (network, auth, parse, status mismatch).
 *
 * Caller (db_refresh_pluriverse_remote_status) decides what to do with
 * the result; this is a thin transport wrapper.
 */
function db_fetch_pluriverse_remote_status_signed(): ?array {
    require_once __DIR__ . '/federation/identity.php';
    require_once __DIR__ . '/federation/http_sig.php';

    $url = 'https://www.telaris.ca/api/pluriverse/operators/status';
    $host = 'www.telaris.ca';

    try {
        $secretKey = federation_load_secret_key();
        $localHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($localHost === '') return null;
        $keyid = federation_keyid($localHost);
        $now = time();
        $request = [
            'method' => 'GET',
            'target_uri' => $url,
            'headers' => [
                'host' => $host,
                'date' => gmdate('D, d M Y H:i:s', $now) . ' GMT',
            ],
            'body' => '',
        ];
        $signed = federation_http_sig_sign($request, $secretKey, [
            'keyid' => $keyid,
            'tag' => 'pluriverse-status',
            'created' => $now,
            'expires' => $now + 60,
        ]);
    } catch (Throwable $e) {
        error_log('status-sync: signing failed: ' . $e->getMessage());
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'Date: ' . $request['headers']['date'],
            'Signature-Input: ' . $signed['signature_input'],
            'Signature: ' . $signed['signature'],
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $resp === null) {
        error_log("status-sync: curl error: {$curlErr}");
        return null;
    }
    if ($httpCode !== 200) {
        // 404 (no remote row), 401 (sig issue), etc. are real signals but
        // we let the caller leave the local row alone; only log so we can
        // notice rotted state during operator support.
        error_log("status-sync: HTTP {$httpCode} from Pluriverse: " . substr((string)$resp, 0, 200));
        return null;
    }

    try {
        $parsed = json_decode((string)$resp, true, 6, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('status-sync: JSON parse failed: ' . $e->getMessage());
        return null;
    }
    return is_array($parsed) ? $parsed : null;
}
