<?php
declare(strict_types=1);

/**
 * Database layer: all DB connection and queries in one place.
 * Expects DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS to be defined (e.g. by config.php).
 */

// Pure, database-free decision helpers for editor self-enrollment
// (auto_enroll_compute_open, auto_enroll_normalize_config, enroll_email_domain_allowed,
// enroll_personal_galaxy_name). Required here so the DB wrappers below can reuse them.
require_once __DIR__ . '/enroll-helpers.php';

// Pure, database-free fuzzy keyword matching pipeline (keyword_fuzzy_build_groups
// and helpers). Used by db_get_connections() and node serialization to group
// editor keywords that name the same concept for multi-galaxy relationship lines.
require_once __DIR__ . '/keyword-fuzzy.php';

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
        $port = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '5432';
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, $port, DB_NAME);
        // Enforce TLS to a managed DB (e.g. DigitalOcean) when a CA cert is configured.
        // verify-ca validates the chain but not the server hostname, because the managed
        // cluster's cert CN does not match the private VPC endpoint hostname (the same
        // posture the MySQL connection used before the Postgres migration).
        if (defined('DB_SSL_CA') && DB_SSL_CA !== '') {
            $dsn .= sprintf(';sslmode=verify-ca;sslrootcert=%s', DB_SSL_CA);
        }
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO(
            $dsn,
            DB_USER,
            defined('DB_PASS') ? DB_PASS : '',
            $opts
        );
        // Pin the session timezone to UTC so CURRENT_TIMESTAMP/NOW() match the UTC
        // timestamps the app computes in PHP (gmdate, e.g. token expiry: "computed in
        // PHP (UTC, matches the DB clock)"). The pre-migration MySQL server ran in UTC
        // (NOW() == UTC_TIMESTAMP()), so this preserves behaviour. Also bounds runaway
        // statements. PHP is request-scoped, so a SET per connection is cheap.
        $tz = defined('DB_TIMEZONE') && DB_TIMEZONE !== '' ? DB_TIMEZONE : 'UTC';
        $pdo->exec("SET TIME ZONE '" . str_replace("'", "''", (string)$tz) . "'");
        $pdo->exec('SET statement_timeout = 30000');
        return $pdo;
    } catch (PDOException $e) {
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Postgres schema prerequisites (functions, triggers, expression indexes)
//
// These replace MySQL constructs that have no Postgres equivalent and that the
// ";"-splitting SCHEMA.sql loader cannot carry (a "$$" function body). They are
// idempotent and guarded so they run at most once per request; setup.php invokes
// db_ensure_pg_runtime() right after loading SCHEMA.sql, and the keyword write
// path calls db_ensure_keywords_unaccent_index() lazily.
// ---------------------------------------------------------------------------

/**
 * The unaccent extension (provisioning normally installs it; we try in case the
 * role can), an IMMUTABLE wrapper over the 2-arg unaccent (the 1-arg form is only
 * STABLE, so it cannot be used in an index), and the shared updated_at trigger
 * function that replaces MySQL's ON UPDATE CURRENT_TIMESTAMP. Idempotent.
 */
function db_ensure_pg_prerequisites(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $pdo = getDB();
    // The extension is a provisioning concern (a managed-cluster admin installs it);
    // attempt it for self-owned local databases, ignore if the role lacks rights.
    try { $pdo->exec('CREATE EXTENSION IF NOT EXISTS unaccent'); }
    catch (PDOException $e) { /* expected when the app role cannot create extensions */ }
    try {
        $pdo->exec('CREATE OR REPLACE FUNCTION immutable_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $func$ SELECT public.unaccent(\'public.unaccent\', $1) $func$');
        $pdo->exec('CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger LANGUAGE plpgsql AS $func$ BEGIN NEW.updated_at := CURRENT_TIMESTAMP; RETURN NEW; END; $func$');
    } catch (PDOException $e) {
        error_log('db_ensure_pg_prerequisites: ' . $e->getMessage());
    }
}

/**
 * The accent + case-insensitive unique on keywords (keyword, constellation_id),
 * reproducing MySQL's utf8mb4_unicode_ci behaviour as an expression index. Required
 * by the ON CONFLICT upserts in db_save_node_keywords / db_create_keyword. Idempotent.
 */
function db_ensure_keywords_unaccent_index(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_pg_prerequisites();
    try {
        getDB()->exec('CREATE UNIQUE INDEX IF NOT EXISTS unique_keyword_constellation ON keywords (lower(immutable_unaccent(keyword)), constellation_id)');
    } catch (PDOException $e) {
        error_log('db_ensure_keywords_unaccent_index: ' . $e->getMessage());
    }
}

/**
 * Attach the shared set_updated_at() BEFORE UPDATE trigger to every table that has
 * an updated_at column (replacing MySQL's per-column ON UPDATE CURRENT_TIMESTAMP).
 * Discovered dynamically so new tables with an updated_at are covered automatically.
 * The trigger sets updated_at unconditionally on every UPDATE; no code path relies on
 * passing its own updated_at value. Idempotent.
 */
function db_ensure_updated_at_triggers(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_pg_prerequisites();
    try {
        $pdo = getDB();
        $tables = $pdo->query(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND column_name = 'updated_at'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string)$t)) continue; // defensive: our own names only
            $trg = 'trg_set_updated_at_' . $t;
            $pdo->exec("DROP TRIGGER IF EXISTS $trg ON $t");
            $pdo->exec("CREATE TRIGGER $trg BEFORE UPDATE ON $t FOR EACH ROW EXECUTE FUNCTION set_updated_at()");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_updated_at_triggers: ' . $e->getMessage());
    }
}

/**
 * One-shot Postgres runtime bootstrap: prerequisites, the keyword expression index,
 * and the updated_at triggers. Called by admin/setup.php after the schema load and
 * safe to call again (each step is guarded/idempotent).
 */
function db_ensure_pg_runtime(): void {
    db_ensure_pg_prerequisites();
    db_ensure_keywords_unaccent_index();
    db_ensure_updated_at_triggers();
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
    'editor_banner_imported_read_only', 'editor_banner_seat_read_only', 'editor_heading_wormholes', 'editor_btn_new_wormhole', 'editor_btn_shortcuts_title', 'editor_label_search', 'editor_placeholder_search_wormholes',
    'editor_col_name', 'editor_col_type', 'editor_col_galaxy', 'editor_col_url', 'editor_col_keywords', 'editor_col_created', 'editor_col_updated', 'editor_col_actions', 'editor_col_acc', 'editor_col_acc_title',
    'editor_msg_loading_wormholes', 'editor_msg_retrieving_wormholes',
    'editor_heading_no_wormholes', 'editor_text_empty_state_help', 'editor_text_create_wormhole_link', 'editor_heading_error_loading',
    'editor_error_api_key_missing', 'editor_error_api_key_missing_fetch', 'editor_error_invalid_json', 'editor_error_invalid_format', 'editor_error_invalid_data_format',
    'editor_text_no_keywords', 'editor_label_node_type_portal', 'editor_label_node_type_object',
    'editor_badge_accentuated', 'editor_badge_accentuated_title', 'editor_badge_has_url', 'editor_badge_has_url_title', 'editor_badge_has_desc', 'editor_badge_has_desc_title', 'editor_badge_has_img', 'editor_badge_has_img_title', 'editor_badge_has_emb', 'editor_badge_has_emb_title', 'editor_badge_has_aud', 'editor_badge_has_aud_title', 'editor_badge_has_vid', 'editor_badge_has_vid_title', 'editor_badge_has_hotglue', 'editor_badge_has_hotglue_title', 'editor_title_accentuated',
    'editor_action_view_wormhole', 'editor_action_view_galaxy', 'editor_action_edit', 'editor_action_duplicate', 'editor_action_delete',
    'editor_toast_bulk_move_success', 'editor_toast_bulk_move_failed', 'editor_toast_bulk_move_error', 'editor_toast_duplicate_success', 'editor_error_failed_duplicate', 'editor_toast_duplicate_error_generic', 'editor_toast_bulk_duplicate_success', 'editor_toast_bulk_duplicate_failed', 'editor_toast_bulk_duplicate_error', 'editor_confirm_bulk_delete', 'editor_toast_bulk_delete_success', 'editor_toast_bulk_delete_failed', 'editor_toast_bulk_delete_error',
    'editor_toast_url_copied', 'editor_title_url_copied', 'editor_toast_galaxy_created', 'editor_toast_error_creating_galaxy', 'editor_prompt_new_galaxy_name',
    'editor_modal_heading_add_wormhole', 'editor_modal_heading_edit_wormhole', 'editor_label_name_required', 'editor_error_name_exists', 'editor_help_name', 'editor_label_galaxy', 'editor_help_constellation', 'editor_label_wormhole_type', 'editor_help_node_type', 'editor_label_keywords', 'editor_placeholder_add_keyword', 'editor_help_keywords_add', 'editor_label_accentuate_wormhole', 'editor_help_accentuate', 'editor_label_show_keywords', 'editor_help_show_keywords', 'editor_label_target_galaxy', 'editor_help_target_galaxy', 'editor_btn_create_new_galaxy', 'editor_label_description', 'editor_help_description', 'editor_label_url', 'editor_placeholder_url', 'editor_help_url', 'editor_label_primary_visual', 'editor_tab_image', 'editor_tab_video', 'editor_tab_pdf', 'editor_help_visual_mutex', 'editor_label_image_url_file', 'editor_label_use_as_icon', 'editor_placeholder_image_url', 'editor_placeholder_video_url', 'editor_label_autoplay_video', 'editor_placeholder_pdf_url', 'editor_help_pdf', 'editor_placeholder_credit', 'editor_help_credit', 'editor_label_icon_url_file', 'editor_placeholder_icon_url', 'editor_help_icon', 'editor_label_audio_url_file', 'editor_placeholder_audio_url', 'editor_label_autoplay', 'editor_label_loop', 'editor_help_audio',
    'editor_text_uploading', 'editor_btn_add_wormhole', 'editor_btn_cancel', 'editor_divider_media', 'editor_btn_delete_file', 'editor_btn_update_wormhole',
    'editor_view_basic', 'editor_view_advanced', 'editor_view_toggle_label',
    'editor_tab_classic', 'editor_tab_media', 'editor_tab_hotglue', 'editor_btn_edit_hotglue', 'editor_help_hotglue', 'editor_hotglue_create_note', 'editor_untitled_wormhole', 'editor_hotglue_modal_heading', 'editor_btn_hotglue_done',
    'editor_viewtab_wormholes', 'editor_viewtab_hotglue', 'editor_hg_heading', 'editor_hg_btn_new', 'editor_hg_search_placeholder', 'editor_hg_col_title', 'editor_hg_col_assigned', 'editor_hg_loading', 'editor_hg_title_placeholder', 'editor_hg_title_hint', 'editor_hg_assign_label', 'editor_hg_assign_none', 'editor_hg_untitled', 'editor_hg_empty', 'editor_hg_no_match', 'editor_hg_unassigned', 'editor_hg_save_failed', 'editor_hg_confirm_replace', 'editor_hg_confirm_delete', 'editor_hg_err_not_authorized', 'editor_hg_err_read_only', 'editor_hg_err_generic', 'editor_hg_in_galaxy', 'editor_hg_name_label', 'editor_hg_selected_suffix', 'editor_hg_bulk_unassign', 'editor_hg_bulk_delete', 'editor_hg_confirm_bulk_delete', 'editor_hg_galaxy_empty', 'editor_hg_create_link', 'editor_hg_copy_suffix', 'editor_hg_dup_notice', 'editor_hg_action_view_in_wormhole', 'editor_hg_action_view_in_galaxy', 'editor_hg_action_view_directly', 'editor_hg_action_copy_url', 'editor_hg_btn_revisions',
    'editor_viewtab_templates', 'editor_action_create_template', 'editor_tpl_heading', 'editor_tpl_search_placeholder', 'editor_tpl_col_name', 'editor_tpl_col_hotglue', 'editor_tpl_loading', 'editor_tpl_selector_title', 'editor_tpl_selector_blank', 'editor_tpl_untitled', 'editor_tpl_empty_hint', 'editor_tpl_no_match', 'editor_tpl_hotglue_yes', 'editor_tpl_action_rename', 'editor_tpl_rename_prompt', 'editor_tpl_confirm_delete', 'editor_tpl_created_toast', 'editor_tpl_deleted_toast',
    'editor_modal_heading_confirm_delete', 'editor_btn_delete',
    'editor_modal_heading_move_wormholes', 'editor_text_move_count_wormholes', 'editor_label_destination_galaxy', 'editor_btn_move_wormholes',
    'editor_modal_heading_duplicate_wormhole', 'editor_text_duplicate_to', 'editor_btn_duplicate',
    'editor_modal_heading_duplicate_wormholes', 'editor_text_duplicate_count_wormholes', 'editor_btn_duplicate_wormholes',
    'editor_btn_open_link', 'editor_btn_apply', 'editor_label_target_prefix',
    
    
    
    'editor_modal_heading_shortcuts', 'editor_shortcut_new_wormhole', 'editor_shortcut_focus_search', 'editor_shortcut_galaxy_settings', 'editor_shortcut_close_modal', 'editor_shortcut_open_help', 'editor_note_shortcuts_typing', 'editor_btn_close',
    'editor_toast_updated_successfully', 'editor_toast_created_successfully', 'editor_error_failed_update', 'editor_error_failed_create', 'editor_error_network_upload', 'editor_error_name_required', 'editor_error_loading_node', 'editor_confirm_delete_file', 'editor_toast_file_deleted', 'editor_error_deleting_file', 'editor_confirm_delete_node', 'editor_error_delete_wormhole', 'editor_toast_deleted_successfully', 'editor_error_deleting_wormhole', 'editor_error_fatal_loading', 'editor_error_could_not_load',
    'editor_autosave_saving', 'editor_autosave_saved', 'editor_autosave_failed',
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
    'admin_label_fuzzy_keywords', 'admin_help_fuzzy_keywords',
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
    // Stage 3: local peer list + refresh-now.
    'admin_pluriverse_peers_heading', 'admin_pluriverse_peers_subheading',
    'admin_pluriverse_btn_refresh',
    'admin_pluriverse_peers_last_ok', 'admin_pluriverse_peers_never', 'admin_pluriverse_peers_failures', 'admin_pluriverse_peers_last_err',
    'admin_pluriverse_peers_empty',
    'admin_pluriverse_peers_col_label', 'admin_pluriverse_peers_col_hostname', 'admin_pluriverse_peers_col_source', 'admin_pluriverse_peers_col_fingerprint', 'admin_pluriverse_peers_col_trust_state', 'admin_pluriverse_peers_col_last_seen',
    'admin_pluriverse_peers_source_registry', 'admin_pluriverse_peers_source_manual', 'admin_pluriverse_peers_source_manual_help',
    'admin_pluriverse_peers_manual_banner',
    'admin_pluriverse_refresh_ok', 'admin_pluriverse_refresh_err',
    'admin_pluriverse_enforce_blocked',
    // Stage 6d: operator local block / unblock (per-peer untrust lever).
    'admin_peer_block_col_actions', 'admin_peer_block_btn', 'admin_peer_block_heading',
    'admin_peer_block_warn', 'admin_peer_block_field_category',
    'admin_peer_block_cat_spam', 'admin_peer_block_cat_harmful', 'admin_peer_block_cat_legal',
    'admin_peer_block_cat_consent', 'admin_peer_block_cat_other',
    'admin_peer_block_field_reason', 'admin_peer_block_reason_ph', 'admin_peer_block_field_password',
    'admin_peer_block_confirm_btn', 'admin_peer_block_blocked_label', 'admin_peer_block_reason_shown',
    'admin_peer_block_unblock_btn', 'admin_peer_block_unblock_help',
    'admin_peer_block_ok', 'admin_peer_block_unblock_ok',
    'admin_peer_block_err_notfound', 'admin_peer_block_err_action', 'admin_peer_block_err_category',
    'admin_peer_block_err_reason', 'admin_peer_block_err_password_required', 'admin_peer_block_err_password_wrong',
    // Stage 5d-v: galaxy-pull refresh-now (peer-to-peer galaxy mirror refresh).
    'admin_galaxy_pull_btn_refresh', 'admin_galaxy_pull_refresh_ok', 'admin_galaxy_pull_refresh_err',
    // Stage 5f: operator surface — publish, retract, full-fidelity export.
    'admin_pub_section_heading', 'admin_pub_section_subheading',
    'admin_pub_col_galaxy', 'admin_pub_col_slug', 'admin_pub_col_status', 'admin_pub_col_sequence', 'admin_pub_col_published_at', 'admin_pub_col_actions',
    'admin_pub_status_published', 'admin_pub_status_not_published', 'admin_pub_status_retracted', 'admin_pub_status_stale',
    'admin_pub_empty',
    'admin_pub_btn_publish', 'admin_pub_btn_republish', 'admin_pub_btn_retract', 'admin_pub_btn_download_backup',
    'admin_pub_retract_label_slug', 'admin_pub_retract_help', 'admin_pub_retract_label_reason', 'admin_pub_retract_reason_placeholder',
    'admin_pub_retract_open', 'admin_pub_retract_warn',
    'admin_galaxy_publish_err_missing', 'admin_galaxy_publish_err', 'admin_galaxy_publish_ok',
    'admin_galaxy_retract_err_not_found', 'admin_galaxy_retract_err_confirm', 'admin_galaxy_retract_err',
    'admin_galaxy_retract_ok', 'admin_galaxy_retract_already',
    'admin_galaxy_backup_err_not_authored', 'admin_galaxy_backup_err',
    'admin_pub_retracted_on',
    // Stage 5f: mirrored galaxies + honoured retractions inspection.
    'admin_mir_section_heading', 'admin_mir_section_subheading', 'admin_mir_empty',
    'admin_mir_col_origin', 'admin_mir_col_remote_slug', 'admin_mir_col_local', 'admin_mir_col_seq', 'admin_mir_col_hash', 'admin_mir_col_last_sync', 'admin_mir_col_status',
    'admin_mir_status_active', 'admin_mir_status_pending', 'admin_mir_status_fossilized', 'admin_mir_status_paused',
    'admin_mir_node_count_suffix',
    'admin_rmtret_section_heading', 'admin_rmtret_section_subheading', 'admin_rmtret_empty',
    'admin_rmtret_col_origin', 'admin_rmtret_col_slug', 'admin_rmtret_col_retracted_at', 'admin_rmtret_col_reason', 'admin_rmtret_col_honored_at',
    // Stage 5f: federation media store stats.
    'admin_ms_section_heading', 'admin_ms_section_subheading',
    'admin_ms_label_blobs_db', 'admin_ms_label_blobs_disk', 'admin_ms_label_size_db', 'admin_ms_label_size_disk', 'admin_ms_label_path', 'admin_ms_drift_warn',
    // Stage 5e: visitor-side mirror provenance + editor read-only enrichment.
    'visitor_mirror_label', 'visitor_mirror_view_on_origin',
    'editor_banner_mirror_federation',
    // Stage 5f-vii: media GC sweep.
    'admin_ms_gc_btn', 'admin_ms_gc_ok', 'admin_ms_gc_blobs', 'admin_ms_gc_rows', 'admin_ms_gc_freed', 'admin_ms_gc_protected',
    'admin_pluriverse_manual_disclosure', 'admin_pluriverse_manual_warn_heading', 'admin_pluriverse_manual_warn_body',
    'admin_pluriverse_manual_field_hostname', 'admin_pluriverse_manual_field_url', 'admin_pluriverse_manual_field_label',
    'admin_pluriverse_manual_field_pubkey', 'admin_pluriverse_manual_field_pubkey_help',
    'admin_pluriverse_manual_field_password',
    'admin_pluriverse_manual_btn_add',
    'admin_pluriverse_manual_added',
    'admin_pluriverse_manual_err_hostname', 'admin_pluriverse_manual_err_url', 'admin_pluriverse_manual_err_label', 'admin_pluriverse_manual_err_pubkey',
    'admin_pluriverse_manual_err_password_required', 'admin_pluriverse_manual_err_password_wrong',
    'admin_pluriverse_manual_err_duplicate',
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
    'admin_msg_no_galaxies', 'admin_msg_no_galaxies_search', 'admin_msg_galaxies_empty', 'admin_link_create_galaxy', 'admin_msg_clusters_empty', 'admin_link_create_cluster',
    'admin_col_id', 'admin_col_galaxy_name', 'admin_col_slug', 'admin_col_tagline', 'admin_col_wormholes', 'admin_col_created', 'admin_col_last_updated',
    'admin_badge_default', 'admin_badge_imported', 'admin_title_tour_enabled',
    'admin_msg_error_loading_galaxies',
    'admin_action_view', 'admin_action_copy_url', 'admin_action_keyword_canvas', 'admin_action_fractal_profile', 'admin_action_duplicate', 'admin_action_refresh',
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
    'admin_modal_label_pronouns', 'admin_modal_help_pronouns', 'admin_modal_label_pronouns_custom', 'admin_modal_placeholder_pronouns_custom',
    'pronoun_common_set',
    'pronouns_error_too_many', 'pronouns_error_too_long', 'pronouns_error_charset', 'pronouns_error_denylist',
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
    'admin_modal_label_cluster_fuzzy', 'admin_modal_help_cluster_fuzzy',
    'admin_modal_fuzzy_inherit', 'admin_modal_fuzzy_on', 'admin_modal_fuzzy_off',
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
    'admin_setup_pronouns_label', 'admin_setup_pronouns_help',
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
    'loginlink_link_label', 'loginlink_expired_error', 'loginlink_page_title',
    'loginlink_heading', 'loginlink_subtitle', 'loginlink_generic_notice', 'loginlink_submit',
    'auth_login_emaillink_button', 'auth_login_have_password',
    'enroll_menu_link', 'enroll_page_title', 'enroll_heading', 'enroll_intro',
    'enroll_name_label', 'enroll_email_label', 'enroll_submit', 'enroll_check_email_notice',
    'enroll_domain_rejected', 'enroll_disabled_notice', 'enroll_full_notice',
    'enroll_confirm_invalid', 'enroll_galaxy_name_possessive', 'enroll_pending_galaxy_banner',
    'enroll_name_required',
    'admin_btn_auto_enroll', 'admin_badge_unvetted', 'admin_unvetted_title',
    'admin_modal_label_vetted', 'admin_modal_help_vetted', 'auto_enroll_saved',
    'admin_auto_enroll_heading', 'admin_auto_enroll_intro', 'admin_auto_enroll_enable',
    'admin_auto_enroll_enable_warning', 'admin_auto_enroll_create_galaxy', 'admin_auto_enroll_naming_label',
    'admin_auto_enroll_naming_email_username', 'admin_auto_enroll_naming_full_email',
    'admin_auto_enroll_naming_first_name', 'admin_auto_enroll_naming_full_name', 'admin_auto_enroll_naming_user_choice',
    'admin_auto_enroll_naming_privacy_note',
    'admin_auto_enroll_galaxies_label', 'admin_auto_enroll_select_all', 'admin_auto_enroll_select_none',
    'admin_auto_enroll_group_hint', 'admin_auto_enroll_access_rw', 'admin_auto_enroll_access_ro',
    'admin_auto_enroll_domains_label', 'admin_auto_enroll_domains_ph', 'admin_auto_enroll_cap_label',
    'admin_auto_enroll_cap_count', 'admin_auto_enroll_save',
    'editor_vetted_banner', 'admin_delete_personal_galaxy',
    'auth_email_subject', 'auth_email_greeting_named', 'auth_email_greeting_anon',
    'auth_email_intro', 'auth_email_cta', 'auth_email_expiry',
    'auth_email_text_intro', 'auth_email_text_outro',
    // Stage 6f: federation mirror-dropped operator notification email.
    'email_drop_subject', 'email_drop_intro', 'email_drop_item', 'email_drop_reason_label',
    'email_drop_reason_retraction', 'email_drop_reason_blacklist', 'email_drop_reason_revoked',
    'email_drop_reason_local', 'email_drop_reason_publish_revoked', 'email_drop_outro',
    // Stage 6f-ii: admin per-user notification-locale selector.
    'admin_user_locale_label', 'admin_user_locale_unset', 'admin_user_locale_saved', 'admin_user_locale_invalid',
    'admin_user_pw_btn', 'admin_user_pw_too_short', 'admin_user_pw_updated',
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
    'gem_theme_light_rainbow', 'gem_theme_rhizome', 'gem_theme_cornrow', 'gem_theme_adire',
    'theme_credit_cornrow', 'theme_credit_adire',
    'rhizome_back',
    'gem_tags_label', 'gem_tags_placeholder', 'gem_tags_help',
    'gem_bulk_actions_label', 'gem_bulk_actions_help',
    'gem_bulk_use_images_btn', 'gem_bulk_revert_icons_btn',
    'gem_keyword_chips_label', 'gem_keyword_chips_help',
    'gem_related_label', 'gem_related_help',
    'gem_2d_view_label', 'gem_2d_view_help',
    'gem_group_nodes_label', 'gem_group_nodes_help',
    'gem_heavy_inertia_label', 'gem_heavy_inertia_help',
    'gem_fractal_title', 'gem_fractal_subtitle', 'gem_fractal_intro', 'gem_fractal_loading',
    'gem_fractal_details_toggle', 'gem_fractal_fit_label',
    'gem_fractal_dB_label', 'gem_fractal_width_label', 'gem_fractal_spectrum_label',
    'gem_fractal_gen_dims_label', 'gem_fractal_gamma_label',
    'gem_fractal_stat_nodes', 'gem_fractal_stat_edges', 'gem_fractal_stat_meandeg',
    'gem_fractal_stat_components', 'gem_fractal_stat_diameter',
    'gem_fractal_dB_low', 'gem_fractal_dB_mid', 'gem_fractal_dB_high',
    'gem_fractal_width_narrow', 'gem_fractal_width_wide',
    'gem_fractal_reason_empty', 'gem_fractal_reason_too_small', 'gem_fractal_reason_too_shallow',
    'gem_fractal_reason_too_large', 'gem_fractal_reason_cluster', 'gem_fractal_error',
    'gem_sound_theme_label', 'gem_sound_theme_default', 'gem_sound_theme_rhizome',
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
    'api_error_403_009', 'api_error_403_010',
    'api_error_403_011', 'api_error_403_012', 'api_error_403_013', 'api_error_403_014',
    'auth_editors_disabled_notice',
    'admin_label_editors_enabled', 'admin_help_editors_enabled',
    'admin_label_cluster_editors_enabled', 'admin_help_cluster_editors_enabled',
    'admin_label_galaxy_editors_enabled', 'admin_help_galaxy_editors_enabled',
    'admin_label_user_editor_enabled', 'admin_help_user_editor_enabled',
    'admin_settings_site_heading',
    'admin_label_site_hostname', 'admin_help_site_hostname',
    'admin_label_site_base_url', 'admin_help_site_base_url',
    'admin_label_default_locale', 'admin_help_default_locale', 'admin_default_locale_automatic',
    'admin_settings_mail_heading', 'admin_settings_mail_intro',
    'admin_mail_not_configured', 'admin_mail_configured',
    'admin_label_mail_host', 'admin_label_mail_port', 'admin_label_mail_user',
    'admin_label_mail_pass', 'admin_help_mail_pass', 'admin_mail_pass_set',
    'admin_label_mail_from_address', 'admin_label_mail_from_name',
    'admin_label_mail_secure', 'admin_mail_secure_tls', 'admin_mail_secure_ssl', 'admin_mail_secure_none',
    'admin_btn_send_test_email', 'admin_help_send_test_email',
    'admin_msg_mailtest_ok', 'admin_msg_mailtest_unconfigured', 'admin_msg_mailtest_noaddr', 'admin_msg_mailtest_fail',
    'admin_auto_enroll_mail_warning',
    'api_error_404_001', 'api_error_404_002', 'api_error_404_003', 'api_error_404_004',
    'api_error_404_005', 'api_error_404_006', 'api_error_404_007', 'api_error_404_008',
    'api_error_404_009', 'api_error_404_010', 'api_error_404_011', 'api_error_404_012',
    'api_error_404_013', 'api_error_404_014',
    'api_error_405_001',
    'api_error_409_001', 'api_error_409_002',
    'api_error_413_001',
    'api_error_500_001', 'api_error_500_002', 'api_error_500_003', 'api_error_500_004',
    'api_error_500_005', 'api_error_500_006', 'api_error_500_007', 'api_error_500_008',
    'api_error_500_009', 'api_error_500_010', 'api_error_500_011', 'api_error_500_012',
    'api_error_500_013', 'api_error_500_014', 'api_error_500_015',
    'api_error_502_001',

    // C7c: inc/galaxy-update.php result messages (rendered as editor/admin toasts).
    'galaxy_update_missing_id', 'galaxy_update_not_authorized', 'galaxy_update_no_access',
    'galaxy_update_read_only',
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
    'viewer_maximize_text', 'viewer_restore_text', 'viewer_close_text',
    'viewer_open_hotglue_newtab_text',

    // Stage 3 follow-up: shared CSRF-failure flash message (used by every admin
    // POST handler that checks the synchronizer token).
    'admin_msg_csrf_invalid',

    // Stage 4e: Pending-handshakes admin panel + compose + handler flashes.
    'admin_handshake_section_heading', 'admin_handshake_section_subheading', 'admin_handshake_empty',
    'admin_handshake_inbound_heading', 'admin_handshake_outbound_heading', 'admin_handshake_history_heading',
    'admin_handshake_th_sender', 'admin_handshake_th_remote', 'admin_handshake_th_received',
    'admin_handshake_th_request_excerpt', 'admin_handshake_th_expires', 'admin_handshake_th_state',
    'admin_handshake_th_delivery', 'admin_handshake_th_direction', 'admin_handshake_th_updated',
    'admin_handshake_th_reason', 'admin_handshake_actions',
    'admin_handshake_btn_accept', 'admin_handshake_btn_reject', 'admin_handshake_btn_reject_confirm',
    'admin_handshake_btn_cancel', 'admin_handshake_reject_prompt', 'admin_handshake_confirm_cancel',
    'admin_handshake_state_pending_their_response', 'admin_handshake_state_pending_our_response',
    'admin_handshake_state_accepted_awaiting_complete', 'admin_handshake_state_complete',
    'admin_handshake_state_rejected', 'admin_handshake_state_expired', 'admin_handshake_state_cancelled',
    'admin_handshake_initiator_us', 'admin_handshake_initiator_them',
    'admin_handshake_delivery_not_applicable', 'admin_handshake_delivery_pending',
    'admin_handshake_delivery_delivered', 'admin_handshake_delivery_failed',
    'admin_handshake_delivery_given_up', 'admin_handshake_delivery_unknown',
    'admin_handshake_attempts_n',
    'admin_handshake_compose_btn_show', 'admin_handshake_compose_subheading',
    'admin_handshake_compose_field_recipient', 'admin_handshake_compose_field_recipient_help',
    'admin_handshake_compose_field_subject',
    'admin_handshake_compose_field_body', 'admin_handshake_compose_field_body_help',
    'admin_handshake_compose_field_pub_galaxies', 'admin_handshake_compose_field_pub_help',
    'admin_handshake_compose_field_sub_galaxies', 'admin_handshake_compose_field_sub_help',
    'admin_handshake_compose_send_anyway', 'admin_handshake_compose_btn_send',
    'admin_handshake_accept_ok', 'admin_handshake_accept_err',
    'admin_handshake_reject_ok', 'admin_handshake_reject_err',
    'admin_handshake_cancel_ok', 'admin_handshake_cancel_err',
    'admin_handshake_initiate_ok', 'admin_handshake_initiate_err',
    'admin_handshake_default_reject_reason', 'admin_handshake_err_missing_id',
    'admin_handshake_err_peer_not_in_directory', 'admin_handshake_err_invalid_recipient',
    'admin_handshake_err_body_required', 'admin_handshake_err_sensitive_info',
    'admin_handshake_err_active_exists',
    // Whitelist editor (stage 4f)
    'admin_whitelist_section_heading', 'admin_whitelist_section_subheading',
    'admin_whitelist_no_peers', 'admin_whitelist_no_authored', 'admin_whitelist_no_subscriptions',
    'admin_whitelist_trust_state_label',
    'admin_whitelist_count_publish', 'admin_whitelist_count_subscribe',
    'admin_whitelist_hint_post_handshake',
    'admin_whitelist_publish_heading', 'admin_whitelist_publish_help', 'admin_whitelist_publish_save_btn',
    'admin_whitelist_subscribe_heading', 'admin_whitelist_subscribe_help',
    'admin_whitelist_subscribe_th_slug', 'admin_whitelist_subscribe_th_last_sync', 'admin_whitelist_subscribe_th_actions',
    'admin_whitelist_subscribe_field_slug',
    'admin_whitelist_subscribe_btn_add', 'admin_whitelist_subscribe_btn_remove',
    'admin_whitelist_subscribe_confirm_remove',
    'admin_whitelist_publish_save_ok', 'admin_whitelist_publish_save_err',
    'admin_whitelist_subscription_add_ok', 'admin_whitelist_subscription_add_exists', 'admin_whitelist_subscription_add_err',
    'admin_whitelist_subscription_remove_ok', 'admin_whitelist_subscription_remove_err',
    'admin_whitelist_err_missing_peer', 'admin_whitelist_err_unknown_peer',
    'admin_whitelist_err_mirrored', 'admin_whitelist_err_invalid_slug',
    'admin_whitelist_err_unknown_subscription', 'admin_whitelist_err_peer_mismatch',
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
    // Operator-set default locale (admin Global Settings) overrides the built-in
    // first-locale default, so an instance whose audience is e.g. Spanish can
    // greet a visitor with no language preference in their language. An explicit
    // ?lang and a matching Accept-Language still win over it.
    $operatorDefault = instance_setting_get('default_locale');
    $default = (in_array($operatorDefault, PROJECT_INFO_LOCALES, true)) ? $operatorDefault : PROJECT_INFO_LOCALES[0];
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
        $pdo->exec("ALTER TABLE project_info ADD COLUMN IF NOT EXISTS pdf_max_bytes BIGINT NULL DEFAULT NULL");
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

/**
 * Ensure project_info.fuzzy_keyword_matching exists. Installation-level default
 * (stored on the 'en' row only). 0 = off (exact matching), 1 = on. Off by default.
 */
function db_ensure_fuzzy_keyword_matching_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE project_info ADD COLUMN IF NOT EXISTS fuzzy_keyword_matching SMALLINT NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        error_log('db_ensure_fuzzy_keyword_matching_column: ' . $e->getMessage());
    }
}

/**
 * Installation-level fuzzy keyword matching default. When on, multi-galaxy 3D
 * views connect wormholes whose keywords name the same concept (colonial /
 * colonialism, typos, shared tokens). Per-cluster overrides take precedence at
 * render time (see inc/bootstrap.php). Off by default.
 */
function db_get_fuzzy_keyword_matching(): bool {
    db_ensure_fuzzy_keyword_matching_column();
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT fuzzy_keyword_matching FROM project_info WHERE locale = 'en' LIMIT 1");
        $row = $stmt->fetch();
        return $row ? ((int)$row['fuzzy_keyword_matching'] === 1) : false;
    } catch (PDOException $e) {
        error_log('db_get_fuzzy_keyword_matching: ' . $e->getMessage());
        return false;
    }
}

function db_set_fuzzy_keyword_matching(bool $enabled): void {
    db_ensure_fuzzy_keyword_matching_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE project_info SET fuzzy_keyword_matching = :v WHERE locale = 'en'");
    $stmt->execute([':v' => $enabled ? 1 : 0]);
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
        $stmt = $pdo->query("SELECT to_regclass('public.project_info')");
        return $stmt->fetchColumn() !== null;
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
            'open_portal_text' => 'Enter',
            'sound_label_text' => 'Sound:', 'sound_on_text' => 'ON', 'sound_off_text' => 'OFF',
            'launching_text' => 'You are traversing the interior', 'mission_active_text' => 'Mission Active', 'go_text' => 'GO',
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
            'editor_banner_seat_read_only' => 'You have read-only access to this galaxy. You can view its wormholes, keywords, and pages, but cannot make changes.',
            'editor_heading_wormholes' => 'Wormholes',
            'editor_btn_new_wormhole' => 'New Wormhole',
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
            'editor_text_create_wormhole_link' => 'Create a new wormhole',
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
            'editor_badge_has_hotglue' => 'HG',
            'editor_badge_has_hotglue_title' => 'Has Hotglue',
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
            'editor_view_basic' => 'Basic view',
            'editor_view_advanced' => 'Advanced view',
            'editor_view_toggle_label' => 'Editor detail level',
            'editor_btn_delete_file' => 'Delete',
            'editor_btn_update_wormhole' => 'Update Wormhole',
            'editor_tab_classic' => 'Classic',
            'editor_tab_media' => 'Media',
            'editor_tab_hotglue' => 'Hotglue',
            'editor_btn_edit_hotglue' => 'Edit hotglue content',
            'editor_help_hotglue' => 'Compose this wormhole\'s media as a freeform hotglue page. Whichever tab is selected when you save is what visitors see.',
            'editor_hotglue_create_note' => 'Enter a name above to create the wormhole, then compose its hotglue page here.',
            'editor_untitled_wormhole' => 'Untitled wormhole',
            'editor_hotglue_modal_heading' => 'Edit hotglue content',
            'editor_btn_hotglue_done' => 'Done',
            'editor_viewtab_wormholes' => 'Wormholes',
            'editor_viewtab_hotglue' => 'Hotglue content',
            'editor_viewtab_templates' => 'Templates',
            'editor_action_create_template' => 'Create Template',
            'editor_tpl_heading' => 'Templates',
            'editor_tpl_search_placeholder' => 'Search templates...',
            'editor_tpl_col_name' => 'Name',
            'editor_tpl_col_hotglue' => 'Hotglue',
            'editor_tpl_loading' => 'Loading templates...',
            'editor_tpl_selector_title' => 'Base the next new wormhole on a template',
            'editor_tpl_selector_blank' => 'No template',
            'editor_tpl_untitled' => 'Untitled template',
            'editor_tpl_empty_hint' => 'No templates yet. Open a wormhole\'s Actions menu and choose "Create Template" to make one.',
            'editor_tpl_no_match' => 'No templates match your search.',
            'editor_tpl_hotglue_yes' => 'Includes hotglue content',
            'editor_tpl_action_rename' => 'Rename',
            'editor_tpl_rename_prompt' => 'New name for this template:',
            'editor_tpl_confirm_delete' => 'Delete this template? This cannot be undone. Wormholes already created from it are not affected.',
            'editor_tpl_created_toast' => 'Template created',
            'editor_tpl_deleted_toast' => 'Template deleted',
            'editor_hg_heading' => 'Hotglue content',
            'editor_hg_btn_new' => 'New page',
            'editor_hg_search_placeholder' => 'Search pages...',
            'editor_hg_col_title' => 'Title',
            'editor_hg_col_assigned' => 'Assigned wormhole',
            'editor_hg_loading' => 'Loading pages...',
            'editor_hg_title_placeholder' => 'Page title',
            'editor_hg_title_hint' => 'Rename this page',
            'editor_hg_assign_label' => 'Assigned wormhole:',
            'editor_hg_assign_none' => 'Not assigned',
            'editor_hg_untitled' => 'Untitled page',
            'editor_hg_empty' => 'There are no Hotglue pages yet. You can %s.',
            'editor_hg_galaxy_empty' => 'There are no Hotglue pages assigned to any wormholes in the selected Galaxy. You can %s, or select another Galaxy.',
            'editor_hg_create_link' => 'Create a new page',
            'editor_hg_copy_suffix' => '(copy)',
            'editor_hg_dup_notice' => 'The copy was created without a wormhole assignment (a wormhole can show only one page). Do you want to assign it to a wormhole now? Choose Cancel to leave it unassigned.',
            'editor_hg_action_view_in_wormhole' => 'View in wormhole',
            'editor_hg_action_view_in_galaxy' => 'View in galaxy',
            'editor_hg_action_view_directly' => 'View in browser',
            'editor_hg_action_copy_url' => 'Copy direct URL',
            'editor_hg_btn_revisions' => 'Revisions',
            'editor_hg_no_match' => 'No pages match your search.',
            'editor_hg_unassigned' => 'Not assigned',
            'editor_hg_save_failed' => 'Save failed',
            'editor_hg_confirm_replace' => 'This wormhole already shows a hotglue page. Replace it? The page it shows now will become unassigned (it is not deleted).',
            'editor_hg_confirm_delete' => 'Delete this hotglue page? This permanently removes its content. If it is assigned to a wormhole, that wormhole returns to classic media.',
            'editor_hg_err_not_authorized' => 'You do not have access to do that.',
            'editor_hg_err_read_only' => 'That galaxy is read-only.',
            'editor_hg_err_generic' => 'Something went wrong. Please try again.',
            'editor_hg_in_galaxy' => 'in %s',
            'editor_hg_name_label' => 'Page Name',
            'editor_hg_selected_suffix' => 'pages selected',
            'editor_hg_bulk_unassign' => 'Unassign Selected',
            'editor_hg_bulk_delete' => 'Delete Selected',
            'editor_hg_confirm_bulk_delete' => 'Delete the selected hotglue pages? This permanently removes their content. Any assigned wormholes return to classic media.',
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
            'editor_modal_heading_shortcuts' => 'Keyboard shortcuts',
            'editor_shortcut_new_wormhole' => 'New wormhole',
            'editor_shortcut_focus_search' => 'Focus the search box',
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
            'editor_autosave_saving' => 'Saving…',
            'editor_autosave_saved' => 'All changes saved',
            'editor_autosave_failed' => 'Save failed; keep editing to retry',
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
            'admin_label_fuzzy_keywords' => 'Fuzzy keyword matching',
            'admin_help_fuzzy_keywords' => 'When on, multi-galaxy views connect wormholes whose keywords name the same idea even when the words differ (for example colonial, colonialism, and typos). Off draws lines only between exact keyword matches. Each cluster can override this default.',
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
            'admin_pluriverse_peers_heading' => 'Local peer list',
            'admin_pluriverse_peers_subheading' => 'Other instances this site knows about. Pulled from the Pluriverse on a schedule. No content flows until a bilateral whitelist is established with each peer (stage 4+).',
            'admin_pluriverse_btn_refresh' => 'Refresh now',
            'admin_pluriverse_peers_last_ok' => 'Last successful pull:',
            'admin_pluriverse_peers_never' => 'never',
            'admin_pluriverse_peers_failures' => 'Consecutive failures:',
            'admin_pluriverse_peers_last_err' => 'Last error:',
            'admin_pluriverse_peers_empty' => 'No peers known yet. They appear here after the next Pluriverse pull, or use Refresh now to fetch immediately.',
            'admin_pluriverse_peers_col_label' => 'Name',
            'admin_pluriverse_peers_col_hostname' => 'Hostname',
            'admin_pluriverse_peers_col_source' => 'Source',
            'admin_pluriverse_peers_col_fingerprint' => 'Fingerprint',
            'admin_pluriverse_peers_col_trust_state' => 'Trust state',
            'admin_pluriverse_peers_col_last_seen' => 'Last seen',
            'admin_pluriverse_peers_source_registry' => 'Pluriverse',
            'admin_pluriverse_peers_source_manual' => 'Manual',
            'admin_pluriverse_peers_source_manual_help' => 'Not Pluriverse-vouched.',
            'admin_pluriverse_peers_manual_banner' => 'Manual peer added by %s on %s; verify intended.',
            'admin_pluriverse_refresh_ok' => 'Pluriverse refreshed:',
            'admin_pluriverse_refresh_err' => 'Pluriverse refresh failed:',
            'admin_pluriverse_enforce_blocked' => 'peer(s) blocked and their mirrors dropped',
            'admin_peer_block_col_actions' => 'Actions',
            'admin_peer_block_btn' => 'Block this peer',
            'admin_peer_block_heading' => 'Block this peer',
            'admin_peer_block_warn' => 'Blocking drops every galaxy you mirror from this peer and stops offering yours to it. The content is removed, not paused; you cannot auto-restore it later, only re-subscribe deliberately. Re-enter your password to confirm.',
            'admin_peer_block_field_category' => 'Category',
            'admin_peer_block_cat_spam' => 'Spam or abuse',
            'admin_peer_block_cat_harmful' => 'Harmful content',
            'admin_peer_block_cat_legal' => 'Legal or takedown',
            'admin_peer_block_cat_consent' => 'Consent withdrawn',
            'admin_peer_block_cat_other' => 'Other',
            'admin_peer_block_field_reason' => 'Reason',
            'admin_peer_block_reason_ph' => 'Why you are blocking this peer (recorded locally)',
            'admin_peer_block_field_password' => 'Re-enter your password',
            'admin_peer_block_confirm_btn' => 'Confirm block',
            'admin_peer_block_blocked_label' => 'Blocked',
            'admin_peer_block_reason_shown' => 'Reason:',
            'admin_peer_block_unblock_btn' => 'Unblock',
            'admin_peer_block_unblock_help' => 'Returns the peer to discovered. Mirrors are not restored.',
            'admin_peer_block_ok' => 'Peer blocked. %d mirror(s) dropped and any publish offer to it cleared.',
            'admin_peer_block_unblock_ok' => 'Peer unblocked and returned to discovered. Its mirrors were not restored; re-subscribe deliberately if you want its galaxies again.',
            'admin_peer_block_err_notfound' => 'That peer could not be found. Reload the admin page and try again.',
            'admin_peer_block_err_action' => 'Unrecognized peer action.',
            'admin_peer_block_err_category' => 'Choose a category for the block.',
            'admin_peer_block_err_reason' => 'A reason is required (up to 1024 characters).',
            'admin_peer_block_err_password_required' => 'Re-enter your password to confirm.',
            'admin_peer_block_err_password_wrong' => 'Password does not match this admin account.',
            'admin_galaxy_pull_btn_refresh' => 'Refresh galaxies now',
            'admin_galaxy_pull_refresh_ok' => 'Galaxy refresh completed:',
            'admin_galaxy_pull_refresh_err' => 'Galaxy refresh failed:',
            'admin_pub_section_heading' => 'Your published galaxies',
            'admin_pub_section_subheading' => 'Authored galaxies you can publish, re-publish, retract, or export. Peers mirror the signed envelope; a full-fidelity backup is the operator action below, separate from the federation envelope.',
            'admin_pub_col_galaxy' => 'Galaxy',
            'admin_pub_col_slug' => 'Slug',
            'admin_pub_col_status' => 'Status',
            'admin_pub_col_sequence' => 'Sequence',
            'admin_pub_col_published_at' => 'Last published',
            'admin_pub_col_actions' => 'Actions',
            'admin_pub_status_published' => 'Published',
            'admin_pub_status_not_published' => 'Not published',
            'admin_pub_status_retracted' => 'Retracted',
            'admin_pub_status_stale' => 'Stale',
            'admin_pub_empty' => 'No authored galaxies on this instance yet. Create a galaxy first; it will appear here once it has a slug.',
            'admin_pub_btn_publish' => 'Publish now',
            'admin_pub_btn_republish' => 'Re-publish',
            'admin_pub_btn_retract' => 'Retract',
            'admin_pub_btn_download_backup' => 'Download full backup',
            'admin_pub_retract_label_slug' => 'Type the slug to confirm',
            'admin_pub_retract_help' => 'Retraction is permanent and one-way: the slug becomes unreusable and subscribing peers drop their mirror on the next pull. Type the slug to confirm.',
            'admin_pub_retract_label_reason' => 'Reason (optional, public)',
            'admin_pub_retract_reason_placeholder' => 'Why are you retracting this galaxy?',
            'admin_pub_retract_open' => 'Open retract panel',
            'admin_pub_retract_warn' => 'Permanent.',
            'admin_galaxy_publish_err_missing' => 'Missing or invalid galaxy reference.',
            'admin_galaxy_publish_err' => 'Publish failed:',
            'admin_galaxy_publish_ok' => 'Galaxy published:',
            'admin_galaxy_retract_err_not_found' => 'Galaxy not found.',
            'admin_galaxy_retract_err_confirm' => 'Typed confirmation did not match the slug. Retraction not performed.',
            'admin_galaxy_retract_err' => 'Retract failed:',
            'admin_galaxy_retract_ok' => 'Galaxy retracted:',
            'admin_galaxy_retract_already' => 'Slug was already retracted; envelope is intact:',
            'admin_galaxy_backup_err_not_authored' => 'This galaxy cannot be exported: not an authored galaxy.',
            'admin_galaxy_backup_err' => 'Backup build failed:',
            'admin_pub_retracted_on' => 'retracted',
            'admin_mir_section_heading' => 'Mirrored galaxies',
            'admin_mir_section_subheading' => 'Galaxies you subscribe from other peers, materialized locally as read-only mirrors. Pulled on the galaxy-pull cron schedule.',
            'admin_mir_empty' => 'No mirrored galaxies yet. Subscriptions appear here once a handshake whitelist authorises a subscription and a pull cycle completes.',
            'admin_mir_col_origin' => 'Origin',
            'admin_mir_col_remote_slug' => 'Remote slug',
            'admin_mir_col_local' => 'Local mirror',
            'admin_mir_col_seq' => 'Sequence',
            'admin_mir_col_hash' => 'Content hash',
            'admin_mir_col_last_sync' => 'Last sync',
            'admin_mir_col_status' => 'Status',
            'admin_mir_status_active' => 'Active',
            'admin_mir_status_pending' => 'Pending first pull',
            'admin_mir_status_fossilized' => 'Fossilized',
            'admin_mir_status_paused' => 'Paused',
            'admin_mir_node_count_suffix' => 'nodes',
            'admin_rmtret_section_heading' => 'Honoured retractions',
            'admin_rmtret_section_subheading' => 'Slugs that origin peers retracted; the mirror was dropped at the time of honoring. The signed envelope is retained so the event can be re-verified.',
            'admin_rmtret_empty' => 'No origin retractions honoured yet.',
            'admin_rmtret_col_origin' => 'Origin',
            'admin_rmtret_col_slug' => 'Slug',
            'admin_rmtret_col_retracted_at' => 'Retracted at',
            'admin_rmtret_col_reason' => 'Reason',
            'admin_rmtret_col_honored_at' => 'Honoured at',
            'admin_ms_section_heading' => 'Federation media store',
            'admin_ms_section_subheading' => 'Content-addressed media blobs shared across mirrors. The database row count is what the federation API serves; the on-disk count is the underlying storage. Drift = a deferred GC sweep is in order.',
            'admin_ms_label_blobs_db' => 'Recorded blobs',
            'admin_ms_label_blobs_disk' => 'On-disk blobs',
            'admin_ms_label_size_db' => 'Recorded size',
            'admin_ms_label_size_disk' => 'On-disk size',
            'admin_ms_label_path' => 'Path',
            'admin_ms_drift_warn' => 'On-disk count differs from the database; orphaned blobs are present (deferred sweep).',
            'visitor_mirror_label' => 'Mirrored from',
            'visitor_mirror_view_on_origin' => 'View on origin',
            'editor_banner_mirror_federation' => 'This galaxy is mirrored from %s and is read-only. Updates flow via the galaxy-pull cron, or use Refresh galaxies now in the admin Pluriverse tab.',
            'admin_ms_gc_btn' => 'Run media GC sweep',
            'admin_ms_gc_ok' => 'Media GC swept:',
            'admin_ms_gc_blobs' => 'orphan blobs',
            'admin_ms_gc_rows' => 'orphan rows',
            'admin_ms_gc_freed' => 'freed',
            'admin_ms_gc_protected' => 'protected in-flight',
            'admin_pluriverse_manual_disclosure' => 'Advanced: add a manual peer',
            'admin_pluriverse_manual_warn_heading' => 'Why this is gated',
            'admin_pluriverse_manual_warn_body' => 'A manual peer bypasses the Pluriverse trust chain: nothing has verified that this hostname and public key actually belong to the operator you intend to reach. The row is added with a not-Pluriverse-vouched flag and a persistent banner so you and other admins can audit it later. Re-enter your password below to confirm.',
            'admin_pluriverse_manual_field_hostname' => 'Hostname',
            'admin_pluriverse_manual_field_url' => 'URL',
            'admin_pluriverse_manual_field_label' => 'Name',
            'admin_pluriverse_manual_field_pubkey' => 'Ed25519 public key (base64url)',
            'admin_pluriverse_manual_field_pubkey_help' => 'Obtain this out of band from the peer operator. It is the value of pluriverse.key.public on the remote instance.',
            'admin_pluriverse_manual_field_password' => 'Re-enter your password',
            'admin_pluriverse_manual_btn_add' => 'Add manual peer',
            'admin_pluriverse_manual_added' => 'Manual peer %s added. Treat it as not Pluriverse-vouched until you confirm with the operator out of band.',
            'admin_pluriverse_manual_err_hostname' => 'Hostname must be a lowercase DNS name (e.g. example.org).',
            'admin_pluriverse_manual_err_url' => 'URL must begin with https://.',
            'admin_pluriverse_manual_err_label' => 'Name is required (1-255 characters).',
            'admin_pluriverse_manual_err_pubkey' => 'Public key must be a 32-byte Ed25519 key encoded as base64url.',
            'admin_pluriverse_manual_err_password_required' => 'Re-enter your password to confirm.',
            'admin_pluriverse_manual_err_password_wrong' => 'Password does not match this admin account.',
            'admin_pluriverse_manual_err_duplicate' => 'A peer for hostname %s already exists (source: %s).',
            'admin_msg_csrf_invalid' => 'Invalid or expired security token. Please reload the admin page and try again.',
            // Stage 4e: pending-handshakes panel + compose + handler flashes.
            'admin_handshake_section_heading' => 'Pending handshakes',
            'admin_handshake_section_subheading' => 'Three-round federation handshakes currently in flight. Inbound requests come in via the Pluriverse relay; outbound requests dispatch on the next pluriverse-dispatch cron tick.',
            'admin_handshake_empty' => 'No handshakes yet.',
            'admin_handshake_inbound_heading' => 'Inbound — awaiting your decision',
            'admin_handshake_outbound_heading' => 'Outbound — waiting for remote',
            'admin_handshake_history_heading' => 'Recent history (terminal handshakes, 30-day window)',
            'admin_handshake_th_sender' => 'Sender',
            'admin_handshake_th_remote' => 'Remote',
            'admin_handshake_th_received' => 'Received',
            'admin_handshake_th_request_excerpt' => 'Request body (excerpt)',
            'admin_handshake_th_expires' => 'Expires',
            'admin_handshake_th_state' => 'State',
            'admin_handshake_th_delivery' => 'Delivery',
            'admin_handshake_th_direction' => 'Direction',
            'admin_handshake_th_updated' => 'Updated',
            'admin_handshake_th_reason' => 'Reason',
            'admin_handshake_actions' => 'Actions',
            'admin_handshake_btn_accept' => 'Accept',
            'admin_handshake_btn_reject' => 'Reject',
            'admin_handshake_btn_reject_confirm' => 'Confirm reject',
            'admin_handshake_btn_cancel' => 'Cancel',
            'admin_handshake_reject_prompt' => 'Reason (optional)',
            'admin_handshake_confirm_cancel' => 'Cancel this outbound handshake?',
            'admin_handshake_state_pending_their_response' => 'Waiting for their reply',
            'admin_handshake_state_pending_our_response' => 'Awaiting your decision',
            'admin_handshake_state_accepted_awaiting_complete' => 'Accepted, awaiting completion',
            'admin_handshake_state_complete' => 'Complete',
            'admin_handshake_state_rejected' => 'Rejected',
            'admin_handshake_state_expired' => 'Expired',
            'admin_handshake_state_cancelled' => 'Cancelled',
            'admin_handshake_initiator_us' => 'Initiated by us',
            'admin_handshake_initiator_them' => 'Initiated by them',
            'admin_handshake_delivery_not_applicable' => 'n/a',
            'admin_handshake_delivery_pending' => 'Queued',
            'admin_handshake_delivery_delivered' => 'Delivered',
            'admin_handshake_delivery_failed' => 'Failed, retrying',
            'admin_handshake_delivery_given_up' => 'Given up',
            'admin_handshake_delivery_unknown' => 'unknown',
            'admin_handshake_attempts_n' => '%d attempts',
            'admin_handshake_compose_btn_show' => 'Initiate a handshake…',
            'admin_handshake_compose_subheading' => 'Send a signed handshake request through the Pluriverse relay. The remote operator receives an email and surfaces the request in their own admin Inbox.',
            'admin_handshake_compose_field_recipient' => 'Recipient hostname',
            'admin_handshake_compose_field_recipient_help' => 'Hostname (no scheme) of a published Pluriverse instance.',
            'admin_handshake_compose_field_subject' => 'Subject (optional)',
            'admin_handshake_compose_field_body' => 'Message body (markdown)',
            'admin_handshake_compose_field_body_help' => 'Visible to the remote operator after they log in. Will be scanned for high-confidence secret patterns; see the override below.',
            'admin_handshake_compose_field_pub_galaxies' => 'Galaxies you offer to publish to them',
            'admin_handshake_compose_field_pub_help' => 'Comma-separated slugs of your authored galaxies. Optional.',
            'admin_handshake_compose_field_sub_galaxies' => 'Galaxies you want to subscribe from them',
            'admin_handshake_compose_field_sub_help' => 'Comma-separated slugs of their authored galaxies. Optional.',
            'admin_handshake_compose_send_anyway' => 'Send anyway if the body looks like it contains a secret',
            'admin_handshake_compose_btn_send' => 'Queue handshake request',
            'admin_handshake_accept_ok' => 'Handshake accepted; reply queued for the next dispatcher tick.',
            'admin_handshake_accept_err' => 'Could not accept the handshake:',
            'admin_handshake_reject_ok' => 'Handshake rejected; the remote will be notified on the next dispatcher tick.',
            'admin_handshake_reject_err' => 'Could not reject the handshake:',
            'admin_handshake_cancel_ok' => 'Handshake cancelled. Any queued outbound was abandoned; the remote is not notified.',
            'admin_handshake_cancel_err' => 'Could not cancel the handshake:',
            'admin_handshake_initiate_ok' => 'Handshake request queued. Delivery to the Pluriverse relay happens on the next dispatcher tick.',
            'admin_handshake_initiate_err' => 'Could not queue the handshake request:',
            'admin_handshake_default_reject_reason' => 'No reason provided.',
            'admin_handshake_err_missing_id' => 'Missing handshake id.',
            'admin_handshake_err_peer_not_in_directory' => 'The remote instance is not in the Pluriverse directory yet. Wait for the next peer pull (or click Refresh now) and try again.',
            'admin_handshake_err_invalid_recipient' => 'Recipient hostname is missing or malformed.',
            'admin_handshake_err_body_required' => 'A message body is required for a handshake request.',
            'admin_handshake_err_sensitive_info' => 'Your message contains content that looks like a secret (%s). Edit the message and try again, or check "Send anyway" to override.',
            'admin_handshake_err_active_exists' => 'An active handshake to that hostname is already in flight; cancel it before initiating another.',
            'admin_whitelist_section_heading' => 'Per-peer publish and subscribe lists',
            'admin_whitelist_section_subheading' => 'Which of your authored galaxies you would publish to each peer, and which of theirs you want to subscribe from. Takes effect after a successful handshake; you can pre-load intent before that point.',
            'admin_whitelist_no_peers' => 'No peers yet. Lists become editable once peers appear in the Local Peer List.',
            'admin_whitelist_no_authored' => 'No authored galaxies yet.',
            'admin_whitelist_no_subscriptions' => 'No subscriptions yet.',
            'admin_whitelist_trust_state_label' => 'Trust:',
            'admin_whitelist_count_publish' => 'publish',
            'admin_whitelist_count_subscribe' => 'subscribe',
            'admin_whitelist_hint_post_handshake' => 'No handshake has completed with this peer yet; the whitelist takes effect when one does.',
            'admin_whitelist_publish_heading' => 'Galaxies we publish to them',
            'admin_whitelist_publish_help' => 'Only authored galaxies appear here. Mirrored galaxies cannot be re-published.',
            'admin_whitelist_publish_save_btn' => 'Save publish list',
            'admin_whitelist_subscribe_heading' => 'Galaxies we subscribe from them',
            'admin_whitelist_subscribe_help' => 'Add a remote galaxy slug to subscribe to. A multiselect arrives once the published-galaxies endpoint is in place.',
            'admin_whitelist_subscribe_th_slug' => 'Remote slug',
            'admin_whitelist_subscribe_th_last_sync' => 'Last sync',
            'admin_whitelist_subscribe_th_actions' => 'Actions',
            'admin_whitelist_subscribe_field_slug' => 'Remote slug',
            'admin_whitelist_subscribe_btn_add' => 'Add subscription',
            'admin_whitelist_subscribe_btn_remove' => 'Remove',
            'admin_whitelist_subscribe_confirm_remove' => 'Remove this subscription?',
            'admin_whitelist_publish_save_ok' => 'Publish list saved (%1$d added, %2$d removed).',
            'admin_whitelist_publish_save_err' => 'Could not save the publish list.',
            'admin_whitelist_subscription_add_ok' => 'Subscription added.',
            'admin_whitelist_subscription_add_exists' => 'That subscription is already active; nothing changed.',
            'admin_whitelist_subscription_add_err' => 'Could not add the subscription.',
            'admin_whitelist_subscription_remove_ok' => 'Subscription removed.',
            'admin_whitelist_subscription_remove_err' => 'Could not remove the subscription.',
            'admin_whitelist_err_missing_peer' => 'Missing peer id.',
            'admin_whitelist_err_unknown_peer' => 'That peer no longer exists.',
            'admin_whitelist_err_mirrored' => 'Cannot publish a mirrored galaxy onward; only authored galaxies are allowed.',
            'admin_whitelist_err_invalid_slug' => 'The remote slug is empty or too long.',
            'admin_whitelist_err_unknown_subscription' => 'That subscription no longer exists.',
            'admin_whitelist_err_peer_mismatch' => 'That subscription belongs to a different peer.',
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
            'admin_msg_no_galaxies_search' => 'No galaxies match your search.',
            'admin_msg_galaxies_empty' => 'There are no galaxies yet. You can %s.',
            'admin_link_create_galaxy' => 'create a new galaxy',
            'admin_msg_clusters_empty' => 'There are no clusters yet. You can %s.',
            'admin_link_create_cluster' => 'create a new cluster',
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
            'admin_action_fractal_profile' => 'Galaxy shape',
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
            'admin_modal_label_last_name' => 'Last Name',
            'admin_modal_help_last_name' => 'The user\'s family name. Optional.',
            'admin_modal_label_pronouns' => 'Pronouns',
            'admin_modal_help_pronouns' => 'Optional. Choose up to 3, or add your own. Leave empty if you prefer.',
            'admin_modal_label_pronouns_custom' => 'Add your own',
            'admin_modal_placeholder_pronouns_custom' => 'comma-separated, e.g. they/them',
            'pronoun_common_set' => 'they/them,she/her,he/him,ze/zir,xe/xem',
            'pronouns_error_too_many' => 'Choose at most 3 pronoun sets.',
            'pronouns_error_too_long' => 'Each pronoun entry must be 30 characters or fewer.',
            'pronouns_error_charset' => 'Pronouns may use only letters, spaces, and the marks / - and the apostrophe.',
            'pronouns_error_denylist' => 'That entry cannot be used as a pronoun.',
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
            'admin_modal_label_cluster_fuzzy' => 'Fuzzy keyword matching',
            'admin_modal_help_cluster_fuzzy' => 'Connect wormholes whose keywords name the same idea even when the words differ (colonial, colonialism, typos). Inherit follows the installation default; On or Off overrides it for this cluster only.',
            'admin_modal_fuzzy_inherit' => 'Use the installation default',
            'admin_modal_fuzzy_on' => 'On for this cluster',
            'admin_modal_fuzzy_off' => 'Off for this cluster',
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
            'admin_setup_last_name_label' => 'Last Name',
            'admin_setup_pronouns_label' => 'Pronouns',
            'admin_setup_pronouns_help' => 'Optional. Choose up to 3, or add your own. Leave empty if you prefer.',
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
            'loginlink_link_label' => 'No password? Email me a sign-in link',
            'loginlink_expired_error' => 'That sign-in link is invalid or has expired. Request a new one below.',
            'loginlink_page_title' => 'Email a sign-in link - Telaris',
            'loginlink_heading' => 'Email me a sign-in link',
            'loginlink_subtitle' => 'We will email you a one-time link to sign in without a password.',
            'loginlink_generic_notice' => 'If an account exists for that email, a sign-in link has been sent.',
            'loginlink_submit' => 'Send sign-in link',
            'auth_login_emaillink_button' => 'Email me a sign-in link',
            'auth_login_have_password' => 'I have a password',
            'enroll_menu_link' => 'Enrol as Editor',
            'enroll_page_title' => 'Enrol as an editor - Telaris',
            'enroll_heading' => 'Enrol as an editor',
            'enroll_intro' => 'Join this Telaris instance as an editor. Enter your name and email, agree to the Terms of Use and the Privacy Policy, and we will email you a link to confirm.',
            'enroll_name_label' => 'Your name',
            'enroll_email_label' => 'Email',
            'enroll_submit' => 'Request access',
            'enroll_check_email_notice' => 'Check your email. If your address can enrol, a confirmation link is on its way. The link expires in 24 hours.',
            'enroll_domain_rejected' => 'Enrolment on this instance is limited to certain email domains, and that address is not one of them.',
            'enroll_disabled_notice' => 'Editor enrolment is not open on this instance right now.',
            'enroll_full_notice' => 'Editor enrolment is full on this instance right now. Please try again later.',
            'enroll_confirm_invalid' => 'That confirmation link is invalid or has expired. You can request enrolment again.',
            'enroll_galaxy_name_possessive' => "%s's galaxy",
            'enroll_pending_galaxy_banner' => 'Welcome. When you are ready, create your first galaxy to start adding wormholes.',
            'enroll_name_required' => 'Please enter your name.',
            'admin_btn_auto_enroll' => 'Auto enroll',
            'admin_badge_unvetted' => 'Unvetted',
            'admin_unvetted_title' => 'Self-enrolled; not yet vetted by an admin',
            'admin_modal_label_vetted' => 'Vetted',
            'admin_modal_help_vetted' => 'Vetting a self-enrolled editor emails them a link to set a password and shows them an in-app notice. It does not change what they can edit. Unvetted editors sign in with an emailed link each time.',
            'auto_enroll_saved' => 'Auto-enroll settings saved.',
            'admin_auto_enroll_heading' => 'Editor self-enrolment',
            'admin_auto_enroll_intro' => 'Let people join this instance as editors on their own. Off by default. You stay in control: self-enrolled editors are flagged Unvetted until you vet them, and they only edit galaxies you grant.',
            'admin_auto_enroll_enable' => 'Enable self-enrolment on this installation',
            'admin_auto_enroll_enable_warning' => 'With this on, anyone with a valid email (subject to any domain limit and cap below) can join as an Editor. They still only edit the galaxies you grant them, and stay Unvetted until you vet them. Enable self-enrolment?',
            'admin_auto_enroll_create_galaxy' => 'Create a personal galaxy for each new editor',
            'admin_auto_enroll_naming_label' => 'New galaxy naming convention',
            'admin_auto_enroll_naming_email_username' => 'Email username only (alex)',
            'admin_auto_enroll_naming_full_email' => 'Full email (alex@example.com)',
            'admin_auto_enroll_naming_first_name' => "First name's galaxy",
            'admin_auto_enroll_naming_full_name' => 'Full name (alex-rose)',
            'admin_auto_enroll_naming_user_choice' => 'Let the user choose at first sign-in',
            'admin_auto_enroll_naming_privacy_note' => "Galaxy names are shown publicly in the 3D view and the page URL. The email options put the editor's address on display; prefer the first name or letting the user choose.",
            'admin_auto_enroll_galaxies_label' => 'Grant access to these galaxies',
            'admin_auto_enroll_select_all' => 'All',
            'admin_auto_enroll_select_none' => 'None',
            'admin_auto_enroll_group_hint' => 'Tip: click a [PREFIX] to toggle that group.',
            'admin_auto_enroll_access_rw' => 'Read and write',
            'admin_auto_enroll_access_ro' => 'Read only',
            'admin_auto_enroll_domains_label' => 'Limit to email domains (optional)',
            'admin_auto_enroll_domains_ph' => 'e.g. ubc.ca, gmail.com (blank = any)',
            'admin_auto_enroll_cap_label' => 'Cap the number of self-enrolled editors',
            'admin_auto_enroll_cap_count' => 'Currently %d self-enrolled editor(s).',
            'admin_auto_enroll_save' => 'Save settings',
            'editor_vetted_banner' => 'An administrator has vetted your account. You can set a password from the link we emailed you, for faster sign-in. The emailed link keeps working either way.',
            'admin_delete_personal_galaxy' => "Also delete this user's %d personal galaxy/galaxies (created by them) and their wormholes. Shared galaxies are not affected.",
            'auth_email_subject' => 'Reset your %s password',
            'auth_email_greeting_named' => 'Hi %s,',
            'auth_email_greeting_anon' => 'Hi,',
            'auth_email_intro' => 'We received a request to reset your password. Click the link below to set a new one:',
            'auth_email_cta' => 'Reset password',
            'auth_email_expiry' => 'This link expires in 24 hours and can only be used once. If you did not request a reset, you can safely ignore this email; your password will not change.',
            'auth_email_text_intro' => "We received a request to reset your password.\n\nReset link (24h, single-use):\n",
            'auth_email_text_outro' => "\n\nIf you did not request a reset, ignore this email.",
            'email_drop_subject' => 'Federated galaxies removed',
            'email_drop_intro' => 'One or more federated galaxies this instance was mirroring have been removed:',
            'email_drop_item' => '%1$s (mirrored from %2$s)',
            'email_drop_reason_label' => 'Reason: %s',
            'email_drop_reason_retraction' => 'the origin retracted the galaxy',
            'email_drop_reason_blacklist' => 'the origin instance was blocked on the Pluriverse',
            'email_drop_reason_revoked' => "the origin instance's federation membership was revoked",
            'email_drop_reason_local' => 'you blocked the origin instance',
            'email_drop_reason_publish_revoked' => 'the origin revoked your access to the galaxy',
            'email_drop_outro' => 'The mirrored content has been removed from this instance. This is expected when trust is withdrawn or a galaxy is retracted; no action is needed.',
            'admin_user_locale_label' => 'Notification language',
            'admin_user_locale_unset' => 'Not set (all languages)',
            'admin_user_locale_saved' => 'Notification language updated.',
            'admin_user_pw_btn' => 'Update password',
            'admin_user_pw_too_short' => 'Password must be at least 8 characters.',
            'admin_user_pw_updated' => 'Password updated.',
            'admin_user_locale_invalid' => 'Unsupported language.',
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
            'gem_theme_light_rainbow' => 'Light Rainbow (Light Background, Rainbow Shapes)',
            'gem_theme_rhizome' => 'Rhizome (Light, Connection Map)',
            'gem_theme_cornrow' => 'Cornrow (Fractal weave, after Eglash)',
            'gem_theme_adire' => 'Adire (Fractal lattice, after Eglash)',
            'theme_credit_cornrow' => 'Fractal substrate: cornrow braiding geometry. After Ron Eglash, African Fractals (1999).',
            'theme_credit_adire' => 'Fractal substrate: Yoruba Adire indigo resist patterns. After Ron Eglash, African Fractals (1999).',
            'rhizome_back' => 'Back to overview',
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
            'gem_group_nodes_label' => 'Group wormholes',
            'gem_group_nodes_help' => 'When a galaxy has many wormholes, bundle them into navigable groups instead of showing all at once. On by default. Turn off to always show every wormhole, however many there are.',
            'gem_heavy_inertia_label' => 'Heavy movement',
            'gem_heavy_inertia_help' => 'Give this galaxy a weighty, high-inertia feel: rotating and zooming are slower and the view keeps gliding after you let go, so a dense galaxy feels massive. Off by default.',
            'gem_fractal_title' => 'How this galaxy is shaped',
            'gem_fractal_subtitle' => 'Fractal profile · read-only',
            'gem_fractal_intro' => 'A quick read on how this galaxy\'s wormholes connect to each other through shared keywords.',
            'gem_fractal_loading' => 'Reading the galaxy…',
            'gem_fractal_details_toggle' => 'Show the measurements',
            'gem_fractal_fit_label' => 'fit quality',
            'gem_fractal_dB_label' => 'Fractal dimension (d_B)',
            'gem_fractal_width_label' => 'Unevenness (spectrum width)',
            'gem_fractal_spectrum_label' => 'Connection texture, f(α)',
            'gem_fractal_gen_dims_label' => 'Generalized dimensions (D0/D1/D2)',
            'gem_fractal_gamma_label' => 'Hub dominance (degree exponent γ)',
            'gem_fractal_stat_nodes' => 'Wormholes',
            'gem_fractal_stat_edges' => 'Connections',
            'gem_fractal_stat_meandeg' => 'Avg links',
            'gem_fractal_stat_components' => 'Connected pieces',
            'gem_fractal_stat_diameter' => 'Steps across',
            'gem_fractal_dB_low' => 'The wormholes form a chain: most paths run through a few hub keywords.',
            'gem_fractal_dB_mid' => 'The wormholes form a spread-out web, with many independent paths between them.',
            'gem_fractal_dB_high' => 'The wormholes form a tight cluster: almost everything is a step or two from everything else.',
            'gem_fractal_width_narrow' => 'Keyword linking is fairly even across the galaxy.',
            'gem_fractal_width_wide' => 'Keyword linking is uneven: some parts are densely connected, others sparse.',
            'gem_fractal_reason_empty' => 'This galaxy has no wormholes yet.',
            'gem_fractal_reason_too_small' => 'Too few connected wormholes to read a shape yet.',
            'gem_fractal_reason_too_shallow' => 'This galaxy is small and tightly linked, so there is no clear shape to read: almost every wormhole is a step or two from every other.',
            'gem_fractal_reason_too_large' => 'This galaxy is too large to read on the spot.',
            'gem_fractal_reason_cluster' => 'This reads one galaxy at a time. Open a member galaxy to see its shape.',
            'gem_fractal_error' => 'Could not read this galaxy.',
            'gem_sound_theme_label' => 'Sound Theme',
            'gem_sound_theme_default' => 'Default (Ambient)',
            'gem_sound_theme_rhizome' => 'Rhizome (Glitchy, High-Pitched)',
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
            'api_error_403_009' => 'This galaxy is read-only: it is imported or mirrored from another instance and cannot be edited here.',
            'api_error_403_010' => 'You have read-only access to this galaxy. You can view its contents but cannot change them.',
            'api_error_403_011' => 'Editing is currently disabled on this installation.',
            'api_error_403_012' => 'Editing is disabled for this cluster.',
            'api_error_403_013' => 'Editing is disabled for this galaxy.',
            'api_error_403_014' => 'Your editor account is disabled. Editing is turned off.',
            'auth_editors_disabled_notice' => 'Editing is currently disabled here. Please contact the operator if you think this is a mistake.',
            'admin_label_editors_enabled' => 'Allow editors',
            'admin_help_editors_enabled' => 'When off, editors cannot sign in or make changes anywhere on this installation. Accounts and content are kept; admins are unaffected.',
            'admin_label_cluster_editors_enabled' => 'Allow editors',
            'admin_help_cluster_editors_enabled' => 'When off, editors cannot edit any galaxy in this cluster. Admins are unaffected.',
            'admin_label_galaxy_editors_enabled' => 'Allow editors',
            'admin_help_galaxy_editors_enabled' => 'When off, editors cannot edit this galaxy. Admins are unaffected.',
            'admin_label_user_editor_enabled' => 'Editor enabled',
            'admin_help_user_editor_enabled' => 'When off, this editor cannot sign in or make changes. Their account and galaxies are kept.',
            'admin_settings_site_heading' => 'Site',
            'admin_label_site_hostname' => 'Public hostname',
            'admin_help_site_hostname' => 'Canonical hostname for this instance (no scheme, no trailing slash). Used to build links in outgoing email and as the federation identity host. Leave blank to use the value from config.php.',
            'admin_label_site_base_url' => 'Base URL (optional override)',
            'admin_help_site_base_url' => 'Full base URL with scheme, used in preference to the hostname when set. Leave blank unless this instance is served from a non-standard scheme or path.',
            'admin_label_default_locale' => 'Default language',
            'admin_help_default_locale' => 'Language shown to a visitor whose browser asks for no language Telaris speaks. Automatic falls back to the first supported language. An explicit choice in the address bar always wins.',
            'admin_default_locale_automatic' => 'Automatic (visitor browser preference)',
            'admin_settings_mail_heading' => 'Email (SMTP)',
            'admin_settings_mail_intro' => 'Required for sign-in links, enrolment confirmations, and password resets. When this is blank, those emails silently do not send.',
            'admin_mail_not_configured' => 'Mail is not configured. Transactional email will not be sent until the SMTP settings below are complete.',
            'admin_mail_configured' => 'Mail is configured. Use the test button below to confirm delivery.',
            'admin_label_mail_host' => 'SMTP host',
            'admin_label_mail_port' => 'Port',
            'admin_label_mail_user' => 'Username',
            'admin_label_mail_pass' => 'Password',
            'admin_help_mail_pass' => 'Leave blank to keep the stored password.',
            'admin_mail_pass_set' => '(unchanged)',
            'admin_label_mail_from_address' => 'From address',
            'admin_label_mail_from_name' => 'From name',
            'admin_label_mail_secure' => 'Encryption',
            'admin_mail_secure_tls' => 'STARTTLS (587)',
            'admin_mail_secure_ssl' => 'SSL (465)',
            'admin_mail_secure_none' => 'None (not recommended)',
            'admin_btn_send_test_email' => 'Send test email',
            'admin_help_send_test_email' => 'Sends a test message to your admin email address.',
            'admin_msg_mailtest_ok' => 'Test email sent. Check your inbox to confirm delivery.',
            'admin_msg_mailtest_unconfigured' => 'Mail is not configured. Fill in the SMTP settings below and save before sending a test.',
            'admin_msg_mailtest_noaddr' => 'Your admin account has no email address on file, so there is nowhere to send the test.',
            'admin_msg_mailtest_fail' => 'Test email could not be sent. Check the SMTP settings and the server mail log.',
            'admin_auto_enroll_mail_warning' => 'Email is not configured on this instance, so enrolment confirmation links cannot be sent and self-enrolment will not work. Set up email in Global Settings first.',

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

            'api_error_413_001' => 'Storage quota reached: remove some existing media before uploading more.',

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
            'galaxy_update_read_only' => 'You have read-only access to this galaxy. You can view it but cannot change it.',
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
            'viewer_maximize_text' => 'Maximize',
            'viewer_restore_text' => 'Restore',
            'viewer_close_text' => 'Close',
            'viewer_open_hotglue_newtab_text' => 'View Content Full Screen',
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
            'open_portal_text' => 'Entrar',
            'sound_label_text' => 'Sonido:', 'sound_on_text' => 'SÍ', 'sound_off_text' => 'NO',
            'launching_text' => 'Estás atravesando el interior', 'mission_active_text' => 'Misión Activa', 'go_text' => 'YA',
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
            'editor_banner_seat_read_only' => 'Tienes acceso de solo lectura a esta galaxia. Puedes ver sus agujeros de gusano, palabras clave y páginas, pero no puedes hacer cambios.',
            'editor_heading_wormholes' => 'Agujeros de gusano',
            'editor_btn_new_wormhole' => 'Nuevo agujero de gusano',
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
            'editor_text_create_wormhole_link' => 'crear un nuevo agujero de gusano',
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
            'editor_badge_has_hotglue' => 'HG',
            'editor_badge_has_hotglue_title' => 'Tiene hotglue',
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
            'editor_view_basic' => 'Vista básica',
            'editor_view_advanced' => 'Vista avanzada',
            'editor_view_toggle_label' => 'Nivel de detalle del editor',
            'editor_btn_delete_file' => 'Eliminar',
            'editor_btn_update_wormhole' => 'Actualizar agujero de gusano',
            'editor_tab_classic' => 'Clásico',
            'editor_tab_media' => 'Multimedia',
            'editor_tab_hotglue' => 'Hotglue',
            'editor_btn_edit_hotglue' => 'Editar contenido hotglue',
            'editor_help_hotglue' => 'Compón el contenido de este agujero de gusano como una página hotglue de formato libre. La pestaña seleccionada al guardar es lo que se mostrará a quien visite.',
            'editor_hotglue_create_note' => 'Escribe un nombre arriba para crear el agujero de gusano, luego compón aquí su página hotglue.',
            'editor_untitled_wormhole' => 'Agujero de gusano sin título',
            'editor_hotglue_modal_heading' => 'Editar contenido hotglue',
            'editor_btn_hotglue_done' => 'Listo',
            'editor_viewtab_wormholes' => 'Agujeros de gusano',
            'editor_viewtab_hotglue' => 'Contenido hotglue',
            'editor_viewtab_templates' => 'Plantillas',
            'editor_action_create_template' => 'Crear plantilla',
            'editor_tpl_heading' => 'Plantillas',
            'editor_tpl_search_placeholder' => 'Buscar plantillas...',
            'editor_tpl_col_name' => 'Nombre',
            'editor_tpl_col_hotglue' => 'Hotglue',
            'editor_tpl_loading' => 'Cargando plantillas...',
            'editor_tpl_selector_title' => 'Basa el próximo agujero de gusano en una plantilla',
            'editor_tpl_selector_blank' => 'Sin plantilla',
            'editor_tpl_untitled' => 'Plantilla sin título',
            'editor_tpl_empty_hint' => 'Todavía no hay plantillas. Abre el menú Acciones de un agujero de gusano y elige "Crear plantilla" para crear una.',
            'editor_tpl_no_match' => 'Ninguna plantilla coincide con tu búsqueda.',
            'editor_tpl_hotglue_yes' => 'Incluye contenido de Hotglue',
            'editor_tpl_action_rename' => 'Cambiar nombre',
            'editor_tpl_rename_prompt' => 'Nuevo nombre para esta plantilla:',
            'editor_tpl_confirm_delete' => '¿Eliminar esta plantilla? Esta acción no se puede deshacer. Los agujeros de gusano ya creados a partir de ella no se ven afectados.',
            'editor_tpl_created_toast' => 'Plantilla creada',
            'editor_tpl_deleted_toast' => 'Plantilla eliminada',
            'editor_hg_heading' => 'Contenido hotglue',
            'editor_hg_btn_new' => 'Nueva página',
            'editor_hg_search_placeholder' => 'Buscar páginas...',
            'editor_hg_col_title' => 'Título',
            'editor_hg_col_assigned' => 'Agujero de gusano asignado',
            'editor_hg_loading' => 'Cargando páginas...',
            'editor_hg_title_placeholder' => 'Título de la página',
            'editor_hg_title_hint' => 'Renombrar esta página',
            'editor_hg_assign_label' => 'Agujero de gusano asignado:',
            'editor_hg_assign_none' => 'Sin asignar',
            'editor_hg_untitled' => 'Página sin título',
            'editor_hg_empty' => 'Todavía no hay páginas hotglue. Puedes %s.',
            'editor_hg_galaxy_empty' => 'No hay páginas hotglue asignadas a ningún agujero de gusano en la galaxia seleccionada. Puedes %s, o seleccionar otra galaxia.',
            'editor_hg_create_link' => 'crear una nueva página',
            'editor_hg_copy_suffix' => '(copia)',
            'editor_hg_dup_notice' => 'La copia se creó sin asignación a un agujero de gusano (un agujero de gusano solo puede mostrar una página). ¿Quieres asignarla a un agujero de gusano ahora? Elige Cancelar para dejarla sin asignar.',
            'editor_hg_action_view_in_wormhole' => 'Ver en el agujero de gusano',
            'editor_hg_action_view_in_galaxy' => 'Ver en la galaxia',
            'editor_hg_action_view_directly' => 'Ver en el navegador',
            'editor_hg_action_copy_url' => 'Copiar URL directa',
            'editor_hg_btn_revisions' => 'Revisiones',
            'editor_hg_no_match' => 'Ninguna página coincide con tu búsqueda.',
            'editor_hg_unassigned' => 'Sin asignar',
            'editor_hg_save_failed' => 'No se pudo guardar',
            'editor_hg_confirm_replace' => '¿Reemplazar? Este agujero de gusano ya muestra una página hotglue. La página que muestra ahora quedará sin asignar (no se elimina).',
            'editor_hg_confirm_delete' => '¿Eliminar esta página hotglue? Se borrará su contenido de forma permanente. Si está asignada a un agujero de gusano, ese agujero vuelve a los medios clásicos.',
            'editor_hg_err_not_authorized' => 'No tienes acceso para hacer eso.',
            'editor_hg_err_read_only' => 'Esa galaxia es de solo lectura.',
            'editor_hg_err_generic' => 'Algo salió mal. Inténtalo de nuevo.',
            'editor_hg_in_galaxy' => 'en %s',
            'editor_hg_name_label' => 'Nombre de la página',
            'editor_hg_selected_suffix' => 'páginas seleccionadas',
            'editor_hg_bulk_unassign' => 'Desasignar seleccionadas',
            'editor_hg_bulk_delete' => 'Eliminar seleccionadas',
            'editor_hg_confirm_bulk_delete' => '¿Eliminar las páginas hotglue seleccionadas? Se borrará su contenido de forma permanente. Los agujeros de gusano asignados vuelven a los medios clásicos.',
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
            'editor_modal_heading_shortcuts' => 'Atajos de teclado',
            'editor_shortcut_new_wormhole' => 'Nuevo agujero de gusano',
            'editor_shortcut_focus_search' => 'Enfocar el campo de búsqueda',
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
            'editor_autosave_saving' => 'Guardando…',
            'editor_autosave_saved' => 'Todos los cambios guardados',
            'editor_autosave_failed' => 'No se pudo guardar; sigue editando para reintentar',
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
            'admin_label_fuzzy_keywords' => 'Coincidencia aproximada de palabras clave',
            'admin_help_fuzzy_keywords' => 'Cuando se activa, las vistas multigalaxia conectan agujeros de gusano cuyas palabras clave nombran la misma idea aunque las palabras difieran (por ejemplo colonial, colonialismo y erratas). Desactivado, solo traza líneas entre coincidencias exactas. Cada cúmulo puede anular esta opción.',
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
            'admin_pluriverse_peers_heading' => 'Lista local de instancias pares',
            'admin_pluriverse_peers_subheading' => 'Otras instancias que este sitio conoce. Se obtienen del Pluriverse en un horario regular. Ningún contenido fluye hasta que haya una lista blanca bilateral con cada par (etapa 4+).',
            'admin_pluriverse_btn_refresh' => 'Actualizar ahora',
            'admin_pluriverse_peers_last_ok' => 'Última obtención exitosa:',
            'admin_pluriverse_peers_never' => 'nunca',
            'admin_pluriverse_peers_failures' => 'Fallos consecutivos:',
            'admin_pluriverse_peers_last_err' => 'Último error:',
            'admin_pluriverse_peers_empty' => 'Aún no hay instancias pares. Aparecerán aquí después de la próxima obtención desde el Pluriverse, o usa Actualizar ahora para obtenerlas de inmediato.',
            'admin_pluriverse_peers_col_label' => 'Nombre',
            'admin_pluriverse_peers_col_hostname' => 'Nombre de host',
            'admin_pluriverse_peers_col_source' => 'Origen',
            'admin_pluriverse_peers_col_fingerprint' => 'Huella',
            'admin_pluriverse_peers_col_trust_state' => 'Estado de confianza',
            'admin_pluriverse_peers_col_last_seen' => 'Última actividad',
            'admin_pluriverse_peers_source_registry' => 'Pluriverse',
            'admin_pluriverse_peers_source_manual' => 'Manual',
            'admin_pluriverse_peers_source_manual_help' => 'No avalada por el Pluriverse.',
            'admin_pluriverse_peers_manual_banner' => 'Par manual añadido por %s el %s; verificar la intención.',
            'admin_pluriverse_refresh_ok' => 'Pluriverse actualizado:',
            'admin_pluriverse_refresh_err' => 'La actualización del Pluriverse falló:',
            'admin_pluriverse_enforce_blocked' => 'instancia(s) bloqueada(s) y sus espejos retirados',
            'admin_peer_block_col_actions' => 'Acciones',
            'admin_peer_block_btn' => 'Bloquear esta instancia',
            'admin_peer_block_heading' => 'Bloquear esta instancia',
            'admin_peer_block_warn' => 'Bloquear retira todas las galaxias que reflejas de esta instancia y deja de ofrecerle las tuyas. El contenido se elimina, no se pausa; no podrás restaurarlo de forma automática, solo volver a suscribirte de manera deliberada. Vuelve a ingresar tu contraseña para confirmar.',
            'admin_peer_block_field_category' => 'Categoría',
            'admin_peer_block_cat_spam' => 'Spam o abuso',
            'admin_peer_block_cat_harmful' => 'Contenido dañino',
            'admin_peer_block_cat_legal' => 'Legal o retirada',
            'admin_peer_block_cat_consent' => 'Consentimiento retirado',
            'admin_peer_block_cat_other' => 'Otro',
            'admin_peer_block_field_reason' => 'Motivo',
            'admin_peer_block_reason_ph' => 'Por qué bloqueas esta instancia (se registra localmente)',
            'admin_peer_block_field_password' => 'Vuelve a ingresar tu contraseña',
            'admin_peer_block_confirm_btn' => 'Confirmar bloqueo',
            'admin_peer_block_blocked_label' => 'Bloqueada',
            'admin_peer_block_reason_shown' => 'Motivo:',
            'admin_peer_block_unblock_btn' => 'Desbloquear',
            'admin_peer_block_unblock_help' => 'Devuelve la instancia al estado descubierta. Los espejos no se restauran.',
            'admin_peer_block_ok' => 'Instancia bloqueada. Se retiraron %d espejo(s) y se eliminó cualquier oferta de publicación hacia ella.',
            'admin_peer_block_unblock_ok' => 'Instancia desbloqueada y devuelta al estado descubierta. Sus espejos no se restauraron; vuelve a suscribirte de forma deliberada si quieres sus galaxias otra vez.',
            'admin_peer_block_err_notfound' => 'No se encontró esa instancia. Recarga la página de administración e inténtalo de nuevo.',
            'admin_peer_block_err_action' => 'Acción de instancia no reconocida.',
            'admin_peer_block_err_category' => 'Elige una categoría para el bloqueo.',
            'admin_peer_block_err_reason' => 'Se requiere un motivo (hasta 1024 caracteres).',
            'admin_peer_block_err_password_required' => 'Vuelve a ingresar tu contraseña para confirmar.',
            'admin_peer_block_err_password_wrong' => 'La contraseña no coincide con esta cuenta de administración.',
            'admin_galaxy_pull_btn_refresh' => 'Actualizar galaxias ahora',
            'admin_galaxy_pull_refresh_ok' => 'Actualización de galaxias completada:',
            'admin_galaxy_pull_refresh_err' => 'La actualización de galaxias falló:',
            'admin_pub_section_heading' => 'Tus galaxias publicadas',
            'admin_pub_section_subheading' => 'Las galaxias que escribiste y que puedes publicar, volver a publicar, retractar o exportar. Otras instancias replican el sobre firmado; el respaldo completo de abajo es la acción operacional, separada del sobre de federación.',
            'admin_pub_col_galaxy' => 'Galaxia',
            'admin_pub_col_slug' => 'Identificador',
            'admin_pub_col_status' => 'Estado',
            'admin_pub_col_sequence' => 'Secuencia',
            'admin_pub_col_published_at' => 'Última publicación',
            'admin_pub_col_actions' => 'Acciones',
            'admin_pub_status_published' => 'Publicada',
            'admin_pub_status_not_published' => 'No publicada',
            'admin_pub_status_retracted' => 'Retractada',
            'admin_pub_status_stale' => 'Obsoleta',
            'admin_pub_empty' => 'Aún no hay galaxias escritas en esta instancia. Crea una galaxia; aparecerá aquí cuando tenga identificador.',
            'admin_pub_btn_publish' => 'Publicar ahora',
            'admin_pub_btn_republish' => 'Volver a publicar',
            'admin_pub_btn_retract' => 'Retractar',
            'admin_pub_btn_download_backup' => 'Descargar respaldo completo',
            'admin_pub_retract_label_slug' => 'Escribe el identificador para confirmar',
            'admin_pub_retract_help' => 'La retractación es permanente y de una sola vía: el identificador queda inutilizable y las instancias suscritas eliminarán su réplica en el siguiente ciclo. Escribe el identificador para confirmar.',
            'admin_pub_retract_label_reason' => 'Motivo (opcional, público)',
            'admin_pub_retract_reason_placeholder' => '¿Por qué retractas esta galaxia?',
            'admin_pub_retract_open' => 'Abrir panel de retractación',
            'admin_pub_retract_warn' => 'Permanente.',
            'admin_galaxy_publish_err_missing' => 'Referencia de galaxia faltante o inválida.',
            'admin_galaxy_publish_err' => 'La publicación falló:',
            'admin_galaxy_publish_ok' => 'Galaxia publicada:',
            'admin_galaxy_retract_err_not_found' => 'Galaxia no encontrada.',
            'admin_galaxy_retract_err_confirm' => 'La confirmación escrita no coincide con el identificador. La retractación no se realizó.',
            'admin_galaxy_retract_err' => 'La retractación falló:',
            'admin_galaxy_retract_ok' => 'Galaxia retractada:',
            'admin_galaxy_retract_already' => 'El identificador ya estaba retractado; el sobre está intacto:',
            'admin_galaxy_backup_err_not_authored' => 'Esta galaxia no se puede exportar: no es una galaxia escrita localmente.',
            'admin_galaxy_backup_err' => 'El respaldo falló:',
            'admin_pub_retracted_on' => 'retractada',
            'admin_mir_section_heading' => 'Galaxias replicadas',
            'admin_mir_section_subheading' => 'Galaxias a las que estás suscrito desde otras instancias, materializadas localmente como réplicas de solo lectura. Se actualizan en cada ciclo del cron galaxy-pull.',
            'admin_mir_empty' => 'Aún no hay galaxias replicadas. Las suscripciones aparecen aquí cuando una lista de un acuerdo bilateral autoriza la suscripción y se completa un ciclo de actualización.',
            'admin_mir_col_origin' => 'Origen',
            'admin_mir_col_remote_slug' => 'Identificador remoto',
            'admin_mir_col_local' => 'Réplica local',
            'admin_mir_col_seq' => 'Secuencia',
            'admin_mir_col_hash' => 'Resumen del contenido',
            'admin_mir_col_last_sync' => 'Última sincronización',
            'admin_mir_col_status' => 'Estado',
            'admin_mir_status_active' => 'Activa',
            'admin_mir_status_pending' => 'Esperando la primera sincronización',
            'admin_mir_status_fossilized' => 'Fosilizada',
            'admin_mir_status_paused' => 'En pausa',
            'admin_mir_node_count_suffix' => 'agujeros de gusano',
            'admin_rmtret_section_heading' => 'Retractaciones honradas',
            'admin_rmtret_section_subheading' => 'Identificadores que las instancias de origen retractaron; la réplica se eliminó al honrar la retractación. El sobre firmado se conserva para poder re-verificar el evento.',
            'admin_rmtret_empty' => 'Aún no se ha honrado ninguna retractación de origen.',
            'admin_rmtret_col_origin' => 'Origen',
            'admin_rmtret_col_slug' => 'Identificador',
            'admin_rmtret_col_retracted_at' => 'Retractada en',
            'admin_rmtret_col_reason' => 'Motivo',
            'admin_rmtret_col_honored_at' => 'Honrada en',
            'admin_ms_section_heading' => 'Almacén de medios de federación',
            'admin_ms_section_subheading' => 'Archivos de medios direccionados por contenido, compartidos entre réplicas. El conteo en la base de datos es lo que la API de federación sirve; el conteo en disco es el almacenamiento subyacente. Una diferencia indica que hace falta un barrido de limpieza.',
            'admin_ms_label_blobs_db' => 'Archivos registrados',
            'admin_ms_label_blobs_disk' => 'Archivos en disco',
            'admin_ms_label_size_db' => 'Tamaño registrado',
            'admin_ms_label_size_disk' => 'Tamaño en disco',
            'admin_ms_label_path' => 'Ruta',
            'admin_ms_drift_warn' => 'El conteo en disco difiere del de la base de datos; hay archivos huérfanos (barrido pendiente).',
            'visitor_mirror_label' => 'Replicada desde',
            'visitor_mirror_view_on_origin' => 'Ver en el origen',
            'editor_banner_mirror_federation' => 'Esta galaxia es una réplica de %s y es de solo lectura. Las actualizaciones llegan por el cron galaxy-pull, o puedes usar Actualizar galaxias ahora en la pestaña Pluriverse del panel de administración.',
            'admin_ms_gc_btn' => 'Limpiar archivos huérfanos',
            'admin_ms_gc_ok' => 'Limpieza completada:',
            'admin_ms_gc_blobs' => 'archivos huérfanos',
            'admin_ms_gc_rows' => 'registros huérfanos',
            'admin_ms_gc_freed' => 'liberados',
            'admin_ms_gc_protected' => 'protegidos en tránsito',
            'admin_pluriverse_manual_disclosure' => 'Avanzado: añadir una instancia par manualmente',
            'admin_pluriverse_manual_warn_heading' => 'Por qué esto está restringido',
            'admin_pluriverse_manual_warn_body' => 'Una instancia par manual evita la cadena de confianza del Pluriverse: nada ha verificado que este nombre de host y esta clave pública realmente correspondan a la operación que pretendes contactar. La fila se añade con una marca de no avalada por el Pluriverse y un aviso persistente para que la administración pueda revisarla después. Reescribe tu contraseña abajo para confirmar.',
            'admin_pluriverse_manual_field_hostname' => 'Nombre de host',
            'admin_pluriverse_manual_field_url' => 'URL',
            'admin_pluriverse_manual_field_label' => 'Nombre',
            'admin_pluriverse_manual_field_pubkey' => 'Clave pública Ed25519 (base64url)',
            'admin_pluriverse_manual_field_pubkey_help' => 'Obtén este valor fuera de banda con la operación par. Es el valor de pluriverse.key.public en la instancia remota.',
            'admin_pluriverse_manual_field_password' => 'Reescribe tu contraseña',
            'admin_pluriverse_manual_btn_add' => 'Añadir instancia par manual',
            'admin_pluriverse_manual_added' => 'Instancia par manual %s añadida. Trátala como no avalada por el Pluriverse hasta que lo confirmes fuera de banda con la otra operación.',
            'admin_pluriverse_manual_err_hostname' => 'El nombre de host debe ser un DNS en minúsculas (por ejemplo, example.org).',
            'admin_pluriverse_manual_err_url' => 'La URL debe empezar por https://.',
            'admin_pluriverse_manual_err_label' => 'El nombre es obligatorio (1-255 caracteres).',
            'admin_pluriverse_manual_err_pubkey' => 'La clave pública debe ser una clave Ed25519 de 32 bytes codificada en base64url.',
            'admin_pluriverse_manual_err_password_required' => 'Reescribe tu contraseña para confirmar.',
            'admin_pluriverse_manual_err_password_wrong' => 'La contraseña no coincide con esta cuenta de administración.',
            'admin_pluriverse_manual_err_duplicate' => 'Ya existe una instancia par para el nombre de host %s (origen: %s).',
            'admin_msg_csrf_invalid' => 'Token de seguridad inválido o caducado. Recarga la página de administración y vuelve a intentarlo.',
            // Stage 4e: panel de apretones de mano pendientes.
            'admin_handshake_section_heading' => 'Apretones de mano pendientes',
            'admin_handshake_section_subheading' => 'Apretones de mano de federación en curso (tres rondas). Las solicitudes entrantes llegan a través del relevo del Pluriverso; las salientes se despachan en el próximo ciclo del cron pluriverse-dispatch.',
            'admin_handshake_empty' => 'Aún no hay apretones de mano.',
            'admin_handshake_inbound_heading' => 'Entrantes — esperando tu decisión',
            'admin_handshake_outbound_heading' => 'Salientes — esperando a la otra instancia',
            'admin_handshake_history_heading' => 'Historial reciente (apretones terminados, ventana de 30 días)',
            'admin_handshake_th_sender' => 'Remitente',
            'admin_handshake_th_remote' => 'Remoto',
            'admin_handshake_th_received' => 'Recibido',
            'admin_handshake_th_request_excerpt' => 'Cuerpo del mensaje (extracto)',
            'admin_handshake_th_expires' => 'Caduca',
            'admin_handshake_th_state' => 'Estado',
            'admin_handshake_th_delivery' => 'Entrega',
            'admin_handshake_th_direction' => 'Dirección',
            'admin_handshake_th_updated' => 'Actualizado',
            'admin_handshake_th_reason' => 'Motivo',
            'admin_handshake_actions' => 'Acciones',
            'admin_handshake_btn_accept' => 'Aceptar',
            'admin_handshake_btn_reject' => 'Rechazar',
            'admin_handshake_btn_reject_confirm' => 'Confirmar rechazo',
            'admin_handshake_btn_cancel' => 'Cancelar',
            'admin_handshake_reject_prompt' => 'Motivo (opcional)',
            'admin_handshake_confirm_cancel' => '¿Cancelar este apretón de mano saliente?',
            'admin_handshake_state_pending_their_response' => 'Esperando su respuesta',
            'admin_handshake_state_pending_our_response' => 'Esperando tu decisión',
            'admin_handshake_state_accepted_awaiting_complete' => 'Aceptado, esperando confirmación final',
            'admin_handshake_state_complete' => 'Completo',
            'admin_handshake_state_rejected' => 'Rechazado',
            'admin_handshake_state_expired' => 'Caducado',
            'admin_handshake_state_cancelled' => 'Cancelado',
            'admin_handshake_initiator_us' => 'Iniciado desde aquí',
            'admin_handshake_initiator_them' => 'Iniciado por la otra instancia',
            'admin_handshake_delivery_not_applicable' => 'no aplica',
            'admin_handshake_delivery_pending' => 'En cola',
            'admin_handshake_delivery_delivered' => 'Entregado',
            'admin_handshake_delivery_failed' => 'Fallo, reintentando',
            'admin_handshake_delivery_given_up' => 'Abandonado',
            'admin_handshake_delivery_unknown' => 'desconocido',
            'admin_handshake_attempts_n' => '%d intentos',
            'admin_handshake_compose_btn_show' => 'Iniciar un apretón de mano…',
            'admin_handshake_compose_subheading' => 'Envía una solicitud firmada de apretón de mano a través del relevo del Pluriverso. Quien opera la instancia remota recibe un correo y ve la solicitud en su propio panel.',
            'admin_handshake_compose_field_recipient' => 'Nombre de host del destinatario',
            'admin_handshake_compose_field_recipient_help' => 'Nombre de host (sin esquema) de una instancia publicada en el Pluriverso.',
            'admin_handshake_compose_field_subject' => 'Asunto (opcional)',
            'admin_handshake_compose_field_body' => 'Cuerpo del mensaje (markdown)',
            'admin_handshake_compose_field_body_help' => 'Visible para quien opera la instancia remota al iniciar sesión. Se analiza en busca de patrones de secretos de alta confianza; mira la opción de anulación más abajo.',
            'admin_handshake_compose_field_pub_galaxies' => 'Galaxias que ofreces publicar hacia esa instancia',
            'admin_handshake_compose_field_pub_help' => 'Slugs separados por comas de tus galaxias autoras. Opcional.',
            'admin_handshake_compose_field_sub_galaxies' => 'Galaxias que deseas suscribir desde esa instancia',
            'admin_handshake_compose_field_sub_help' => 'Slugs separados por comas de las galaxias autoras de la otra instancia. Opcional.',
            'admin_handshake_compose_send_anyway' => 'Enviar igualmente aunque el cuerpo parezca contener un secreto',
            'admin_handshake_compose_btn_send' => 'Encolar solicitud de apretón de mano',
            'admin_handshake_accept_ok' => 'Apretón de mano aceptado; la respuesta queda en cola para el próximo ciclo del despachador.',
            'admin_handshake_accept_err' => 'No se pudo aceptar el apretón de mano:',
            'admin_handshake_reject_ok' => 'Apretón de mano rechazado; la otra instancia será notificada en el próximo ciclo del despachador.',
            'admin_handshake_reject_err' => 'No se pudo rechazar el apretón de mano:',
            'admin_handshake_cancel_ok' => 'Apretón de mano cancelado. Cualquier mensaje saliente en cola se abandonó; la otra instancia no es notificada.',
            'admin_handshake_cancel_err' => 'No se pudo cancelar el apretón de mano:',
            'admin_handshake_initiate_ok' => 'Solicitud de apretón de mano encolada. La entrega al relevo del Pluriverso ocurre en el próximo ciclo del despachador.',
            'admin_handshake_initiate_err' => 'No se pudo encolar la solicitud de apretón de mano:',
            'admin_handshake_default_reject_reason' => 'Sin motivo indicado.',
            'admin_handshake_err_missing_id' => 'Falta el identificador del apretón de mano.',
            'admin_handshake_err_peer_not_in_directory' => 'La instancia remota aún no está en el directorio del Pluriverso. Espera la próxima obtención (o haz clic en Actualizar ahora) e inténtalo otra vez.',
            'admin_handshake_err_invalid_recipient' => 'Falta el nombre de host del destinatario o está malformado.',
            'admin_handshake_err_body_required' => 'Una solicitud de apretón de mano requiere un cuerpo de mensaje.',
            'admin_handshake_err_sensitive_info' => 'Tu mensaje contiene contenido que parece un secreto (%s). Edítalo e inténtalo de nuevo, o marca "Enviar igualmente" para anular la verificación.',
            'admin_handshake_err_active_exists' => 'Ya hay un apretón de mano activo hacia ese host; cancélalo antes de iniciar otro.',
            'admin_whitelist_section_heading' => 'Listas de publicación y suscripción por par',
            'admin_whitelist_section_subheading' => 'Cuáles de tus galaxias propias publicarías a cada par, y cuáles de las suyas quieres suscribir. Surte efecto tras un apretón de mano exitoso; puedes precargar la intención antes.',
            'admin_whitelist_no_peers' => 'Aún no hay pares. Las listas se pueden editar cuando aparezcan pares en la Lista local de pares.',
            'admin_whitelist_no_authored' => 'Aún no hay galaxias propias.',
            'admin_whitelist_no_subscriptions' => 'Aún no hay suscripciones.',
            'admin_whitelist_trust_state_label' => 'Confianza:',
            'admin_whitelist_count_publish' => 'publicar',
            'admin_whitelist_count_subscribe' => 'suscribir',
            'admin_whitelist_hint_post_handshake' => 'Aún no se ha completado ningún apretón de mano con este par; la lista surte efecto cuando se complete uno.',
            'admin_whitelist_publish_heading' => 'Galaxias que publicamos a este par',
            'admin_whitelist_publish_help' => 'Solo aparecen galaxias propias. Las galaxias replicadas no se pueden volver a publicar.',
            'admin_whitelist_publish_save_btn' => 'Guardar lista de publicación',
            'admin_whitelist_subscribe_heading' => 'Galaxias a las que nos suscribimos en este par',
            'admin_whitelist_subscribe_help' => 'Agrega el slug de una galaxia remota para suscribirte. Una selección múltiple llegará cuando esté listo el endpoint de galaxias publicadas.',
            'admin_whitelist_subscribe_th_slug' => 'Slug remoto',
            'admin_whitelist_subscribe_th_last_sync' => 'Última sinc.',
            'admin_whitelist_subscribe_th_actions' => 'Acciones',
            'admin_whitelist_subscribe_field_slug' => 'Slug remoto',
            'admin_whitelist_subscribe_btn_add' => 'Agregar suscripción',
            'admin_whitelist_subscribe_btn_remove' => 'Quitar',
            'admin_whitelist_subscribe_confirm_remove' => '¿Quitar esta suscripción?',
            'admin_whitelist_publish_save_ok' => 'Lista de publicación guardada (%1$d añadidas, %2$d quitadas).',
            'admin_whitelist_publish_save_err' => 'No se pudo guardar la lista de publicación.',
            'admin_whitelist_subscription_add_ok' => 'Suscripción agregada.',
            'admin_whitelist_subscription_add_exists' => 'Esa suscripción ya está activa; no hubo cambios.',
            'admin_whitelist_subscription_add_err' => 'No se pudo agregar la suscripción.',
            'admin_whitelist_subscription_remove_ok' => 'Suscripción quitada.',
            'admin_whitelist_subscription_remove_err' => 'No se pudo quitar la suscripción.',
            'admin_whitelist_err_missing_peer' => 'Falta el id del par.',
            'admin_whitelist_err_unknown_peer' => 'Ese par ya no existe.',
            'admin_whitelist_err_mirrored' => 'No se puede publicar una galaxia replicada; solo se permiten galaxias propias.',
            'admin_whitelist_err_invalid_slug' => 'El slug remoto está vacío o es demasiado largo.',
            'admin_whitelist_err_unknown_subscription' => 'Esa suscripción ya no existe.',
            'admin_whitelist_err_peer_mismatch' => 'Esa suscripción pertenece a otro par.',
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
            'admin_msg_no_galaxies_search' => 'Ninguna galaxia coincide con tu búsqueda.',
            'admin_msg_galaxies_empty' => 'Todavía no hay galaxias. Puedes %s.',
            'admin_link_create_galaxy' => 'crear una nueva galaxia',
            'admin_msg_clusters_empty' => 'Todavía no hay cúmulos. Puedes %s.',
            'admin_link_create_cluster' => 'crear un nuevo cúmulo',
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
            'admin_action_fractal_profile' => 'Forma de la galaxia',
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
            'admin_modal_label_last_name' => 'Apellido',
            'admin_modal_help_last_name' => 'El apellido asociado a la cuenta. Opcional.',
            'admin_modal_label_pronouns' => 'Pronombres',
            'admin_modal_help_pronouns' => 'Opcional. Elige hasta 3 o agrega los tuyos. Puedes dejarlo en blanco.',
            'admin_modal_label_pronouns_custom' => 'Agrega los tuyos',
            'admin_modal_placeholder_pronouns_custom' => 'separados por comas, p. ej. elle',
            'pronoun_common_set' => 'elle,ella,él',
            'pronouns_error_too_many' => 'Elige como máximo 3 conjuntos de pronombres.',
            'pronouns_error_too_long' => 'Cada pronombre debe tener 30 caracteres o menos.',
            'pronouns_error_charset' => 'Los pronombres solo admiten letras, espacios y los signos / - y el apóstrofo.',
            'pronouns_error_denylist' => 'Esa entrada no se puede usar como pronombre.',
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
            'admin_modal_label_cluster_fuzzy' => 'Coincidencia aproximada de palabras clave',
            'admin_modal_help_cluster_fuzzy' => 'Conecta agujeros de gusano cuyas palabras clave nombran la misma idea aunque las palabras difieran (colonial, colonialismo, erratas). Heredar sigue la opción predeterminada de la instalación; Activado o Desactivado la anula solo para este cúmulo.',
            'admin_modal_fuzzy_inherit' => 'Usar la opción predeterminada de la instalación',
            'admin_modal_fuzzy_on' => 'Activada para este cúmulo',
            'admin_modal_fuzzy_off' => 'Desactivada para este cúmulo',
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
            'admin_setup_last_name_label' => 'Apellido',
            'admin_setup_pronouns_label' => 'Pronombres',
            'admin_setup_pronouns_help' => 'Opcional. Elige hasta 3 o agrega los tuyos. Puedes dejarlo en blanco.',
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
            'loginlink_link_label' => '¿Sin contraseña? Envíame un enlace de acceso',
            'loginlink_expired_error' => 'Ese enlace de acceso no es válido o ha caducado. Solicita uno nuevo abajo.',
            'loginlink_page_title' => 'Enviar un enlace de acceso - Telaris',
            'loginlink_heading' => 'Envíame un enlace de acceso',
            'loginlink_subtitle' => 'Te enviaremos por correo un enlace de un solo uso para entrar sin contraseña.',
            'loginlink_generic_notice' => 'Si existe una cuenta con ese correo, se ha enviado un enlace de acceso.',
            'loginlink_submit' => 'Enviar enlace de acceso',
            'auth_login_emaillink_button' => 'Envíame un enlace de acceso',
            'auth_login_have_password' => 'Tengo contraseña',
            'enroll_menu_link' => 'Unirme como editor',
            'enroll_page_title' => 'Unirse como editor - Telaris',
            'enroll_heading' => 'Únete como editor',
            'enroll_intro' => 'Únete a esta instancia de Telaris como editor. Escribe tu nombre y correo, acepta las Condiciones de Uso y la Política de Privacidad, y te enviaremos un enlace para confirmar.',
            'enroll_name_label' => 'Tu nombre',
            'enroll_email_label' => 'Correo',
            'enroll_submit' => 'Solicitar acceso',
            'enroll_check_email_notice' => 'Revisa tu correo. Si tu dirección puede unirse, el enlace de confirmación está en camino. El enlace caduca en 24 horas.',
            'enroll_domain_rejected' => 'En esta instancia, unirse como editor está limitado a ciertos dominios de correo, y esa dirección no es uno de ellos.',
            'enroll_disabled_notice' => 'En este momento no está abierta la incorporación de editores en esta instancia.',
            'enroll_full_notice' => 'En este momento la incorporación de editores está completa en esta instancia. Inténtalo más tarde.',
            'enroll_confirm_invalid' => 'Ese enlace de confirmación no es válido o ha caducado. Puedes solicitar unirte de nuevo.',
            'enroll_galaxy_name_possessive' => 'Galaxia de %s',
            'enroll_pending_galaxy_banner' => 'Te damos la bienvenida. Cuando quieras, crea tu primera galaxia para empezar a añadir agujeros de gusano.',
            'enroll_name_required' => 'Escribe tu nombre.',
            'admin_btn_auto_enroll' => 'Auto-registro',
            'admin_badge_unvetted' => 'Sin verificar',
            'admin_unvetted_title' => 'Se unió por su cuenta; aún sin verificar por un administrador',
            'admin_modal_label_vetted' => 'Verificado',
            'admin_modal_help_vetted' => 'Verificar a un editor que se unió por su cuenta le envía un enlace para crear una contraseña y le muestra un aviso en la aplicación. No cambia lo que puede editar. Sin verificar, entra con un enlace por correo cada vez.',
            'auto_enroll_saved' => 'Ajustes de auto-registro guardados.',
            'admin_auto_enroll_heading' => 'Auto-registro de editores',
            'admin_auto_enroll_intro' => 'Permite que las personas se unan a esta instancia como editores por su cuenta. Desactivado por defecto. Tú mantienes el control: quienes se unen así quedan marcados como Sin verificar hasta que los verifiques, y solo editan las galaxias que concedas.',
            'admin_auto_enroll_enable' => 'Activar el auto-registro en esta instalación',
            'admin_auto_enroll_enable_warning' => 'Con esto activado, cualquier persona con un correo válido (según el límite de dominios y el cupo de abajo) puede unirse como editor. Solo edita las galaxias que concedas, y queda Sin verificar hasta que la verifiques. ¿Activar el auto-registro?',
            'admin_auto_enroll_create_galaxy' => 'Crear una galaxia personal para cada nuevo editor',
            'admin_auto_enroll_naming_label' => 'Convención de nombre de la nueva galaxia',
            'admin_auto_enroll_naming_email_username' => 'Solo el usuario del correo (cruz)',
            'admin_auto_enroll_naming_full_email' => 'Correo completo (cruz@example.com)',
            'admin_auto_enroll_naming_first_name' => 'La galaxia de su nombre',
            'admin_auto_enroll_naming_full_name' => 'Nombre completo (cruz-rivera)',
            'admin_auto_enroll_naming_user_choice' => 'Que elija al iniciar sesión por primera vez',
            'admin_auto_enroll_naming_privacy_note' => 'Los nombres de las galaxias se muestran públicamente en la vista 3D y en la URL de la página. Las opciones de correo dejan a la vista la dirección de quien edita; es preferible usar el nombre de pila o dejar que la persona elija.',
            'admin_auto_enroll_galaxies_label' => 'Conceder acceso a estas galaxias',
            'admin_auto_enroll_select_all' => 'Todas',
            'admin_auto_enroll_select_none' => 'Ninguna',
            'admin_auto_enroll_group_hint' => 'Consejo: haz clic en un [PREFIJO] para alternar ese grupo.',
            'admin_auto_enroll_access_rw' => 'Lectura y escritura',
            'admin_auto_enroll_access_ro' => 'Solo lectura',
            'admin_auto_enroll_domains_label' => 'Limitar a dominios de correo (opcional)',
            'admin_auto_enroll_domains_ph' => 'p. ej. ubc.ca, gmail.com (vacío = cualquiera)',
            'admin_auto_enroll_cap_label' => 'Limitar el número de editores auto-registrados',
            'admin_auto_enroll_cap_count' => 'Actualmente %d editor(es) auto-registrado(s).',
            'admin_auto_enroll_save' => 'Guardar ajustes',
            'editor_vetted_banner' => 'Un administrador ha verificado tu cuenta. Puedes crear una contraseña desde el enlace que te enviamos por correo, para entrar más rápido. El enlace por correo sigue funcionando igual.',
            'admin_delete_personal_galaxy' => 'Eliminar también las %d galaxia(s) personal(es) de esta persona (creadas por ella) y sus agujeros de gusano. Las galaxias compartidas no se ven afectadas.',
            'auth_email_subject' => 'Restablece tu contraseña de %s',
            'auth_email_greeting_named' => 'Hola %s,',
            'auth_email_greeting_anon' => 'Hola,',
            'auth_email_intro' => 'Recibimos una solicitud para restablecer tu contraseña. Pulsa el enlace para establecer una nueva:',
            'auth_email_cta' => 'Restablecer contraseña',
            'auth_email_expiry' => 'El enlace caduca en 24 horas y solo puede usarse una vez. Si no solicitaste el restablecimiento, puedes ignorar este correo; tu contraseña no cambiará.',
            'auth_email_text_intro' => "Recibimos una solicitud para restablecer tu contraseña.\n\nEnlace de restablecimiento (24h, un solo uso):\n",
            'auth_email_text_outro' => "\n\nSi no solicitaste el restablecimiento, ignora este correo.",
            'email_drop_subject' => 'Galaxias federadas eliminadas',
            'email_drop_intro' => 'Se eliminaron una o más galaxias federadas que esta instancia replicaba:',
            'email_drop_item' => '%1$s (replicada desde %2$s)',
            'email_drop_reason_label' => 'Motivo: %s',
            'email_drop_reason_retraction' => 'la instancia de origen retiró la galaxia',
            'email_drop_reason_blacklist' => 'la instancia de origen fue bloqueada en el Pluriverse',
            'email_drop_reason_revoked' => 'se revocó la membresía de federación de la instancia de origen',
            'email_drop_reason_local' => 'bloqueaste la instancia de origen',
            'email_drop_reason_publish_revoked' => 'la instancia de origen revocó tu acceso a la galaxia',
            'email_drop_outro' => 'El contenido replicado se eliminó de esta instancia. Esto es lo esperado cuando se retira la confianza o se retira una galaxia; no necesitas hacer nada.',
            'admin_user_locale_label' => 'Idioma de las notificaciones',
            'admin_user_locale_unset' => 'Sin definir (todos los idiomas)',
            'admin_user_locale_saved' => 'Idioma de las notificaciones actualizado.',
            'admin_user_pw_btn' => 'Actualizar contraseña',
            'admin_user_pw_too_short' => 'La contraseña debe tener al menos 8 caracteres.',
            'admin_user_pw_updated' => 'Contraseña actualizada.',
            'admin_user_locale_invalid' => 'Idioma no admitido.',
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
            'gem_theme_light_rainbow' => 'Arcoíris claro (fondo claro, formas arcoíris)',
            'gem_theme_rhizome' => 'Rizoma (claro, mapa de conexiones)',
            'gem_theme_cornrow' => 'Trenza (tejido fractal, según Eglash)',
            'gem_theme_adire' => 'Adire (celosía fractal, según Eglash)',
            'theme_credit_cornrow' => 'Sustrato fractal: geometría de trenzado cornrow. Según Ron Eglash, African Fractals (1999).',
            'theme_credit_adire' => 'Sustrato fractal: patrones de reserva índigo Adire yoruba. Según Ron Eglash, African Fractals (1999).',
            'rhizome_back' => 'Volver a la vista general',
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
            'gem_group_nodes_label' => 'Agrupar agujeros de gusano',
            'gem_group_nodes_help' => 'Cuando una galaxia tiene muchos agujeros de gusano, los agrupa en conjuntos navegables en lugar de mostrarlos todos a la vez. Activado por defecto. Desactívalo para mostrar siempre todos los agujeros de gusano, sean los que sean.',
            'gem_heavy_inertia_label' => 'Movimiento pesado',
            'gem_heavy_inertia_help' => 'Da a esta galaxia una sensación de peso e inercia alta: girar y hacer zoom son más lentos y la vista sigue deslizándose después de soltar, para que una galaxia densa se sienta masiva. Desactivado por defecto.',
            'gem_fractal_title' => 'Cómo está formada esta galaxia',
            'gem_fractal_subtitle' => 'Perfil fractal · solo lectura',
            'gem_fractal_intro' => 'Una lectura rápida de cómo se conectan entre sí los agujeros de gusano de esta galaxia a través de palabras clave compartidas.',
            'gem_fractal_loading' => 'Leyendo la galaxia…',
            'gem_fractal_details_toggle' => 'Ver las medidas',
            'gem_fractal_fit_label' => 'calidad del ajuste',
            'gem_fractal_dB_label' => 'Dimensión fractal (d_B)',
            'gem_fractal_width_label' => 'Desigualdad (ancho del espectro)',
            'gem_fractal_spectrum_label' => 'Textura de conexión, f(α)',
            'gem_fractal_gen_dims_label' => 'Dimensiones generalizadas (D0/D1/D2)',
            'gem_fractal_gamma_label' => 'Dominio de nodos centrales (exponente de grado γ)',
            'gem_fractal_stat_nodes' => 'Agujeros de gusano',
            'gem_fractal_stat_edges' => 'Conexiones',
            'gem_fractal_stat_meandeg' => 'Enlaces prom.',
            'gem_fractal_stat_components' => 'Piezas conectadas',
            'gem_fractal_stat_diameter' => 'Pasos de extremo a extremo',
            'gem_fractal_dB_low' => 'Los agujeros de gusano forman una cadena: la mayoría de los caminos pasan por unas pocas palabras clave centrales.',
            'gem_fractal_dB_mid' => 'Los agujeros de gusano forman una red extendida, con muchos caminos independientes entre ellos.',
            'gem_fractal_dB_high' => 'Los agujeros de gusano forman un grupo compacto: casi todo queda a uno o dos pasos de lo demás.',
            'gem_fractal_width_narrow' => 'El enlace por palabras clave es bastante parejo en toda la galaxia.',
            'gem_fractal_width_wide' => 'El enlace por palabras clave es desigual: algunas partes están muy conectadas y otras poco.',
            'gem_fractal_reason_empty' => 'Esta galaxia todavía no tiene agujeros de gusano.',
            'gem_fractal_reason_too_small' => 'Hay muy pocos agujeros de gusano conectados para leer una forma todavía.',
            'gem_fractal_reason_too_shallow' => 'Esta galaxia es pequeña y está muy enlazada, así que no hay una forma clara que leer: casi todos los agujeros de gusano quedan a uno o dos pasos de los demás.',
            'gem_fractal_reason_too_large' => 'Esta galaxia es demasiado grande para leerla al momento.',
            'gem_fractal_reason_cluster' => 'Esto se lee una galaxia a la vez. Abre una galaxia miembro para ver su forma.',
            'gem_fractal_error' => 'No se pudo leer esta galaxia.',
            'gem_sound_theme_label' => 'Tema de sonido',
            'gem_sound_theme_default' => 'Predeterminado (ambiental)',
            'gem_sound_theme_rhizome' => 'Rizoma (con fallos, agudo)',
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
            'api_error_403_009' => 'Esta galaxia es de solo lectura: se importó o se refleja desde otra instancia y no se puede editar aquí.',
            'api_error_403_010' => 'Tienes acceso de solo lectura a esta galaxia. Puedes ver su contenido, pero no modificarlo.',
            'api_error_403_011' => 'La edición está desactivada en esta instalación en este momento.',
            'api_error_403_012' => 'La edición está desactivada para este cúmulo.',
            'api_error_403_013' => 'La edición está desactivada para esta galaxia.',
            'api_error_403_014' => 'Tu cuenta de edición está desactivada. La edición está apagada.',
            'auth_editors_disabled_notice' => 'La edición está desactivada aquí en este momento. Si crees que es un error, contacta a quien administra la instalación.',
            'admin_label_editors_enabled' => 'Permitir edición',
            'admin_help_editors_enabled' => 'Si está desactivado, quienes editan no pueden iniciar sesión ni hacer cambios en toda la instalación. Se conservan las cuentas y el contenido; no afecta a administración.',
            'admin_label_cluster_editors_enabled' => 'Permitir edición',
            'admin_help_cluster_editors_enabled' => 'Si está desactivado, no se puede editar ninguna galaxia de este cúmulo. No afecta a administración.',
            'admin_label_galaxy_editors_enabled' => 'Permitir edición',
            'admin_help_galaxy_editors_enabled' => 'Si está desactivado, no se puede editar esta galaxia. No afecta a administración.',
            'admin_label_user_editor_enabled' => 'Edición activada',
            'admin_help_user_editor_enabled' => 'Si está desactivado, esta persona no puede iniciar sesión ni hacer cambios. Se conservan su cuenta y sus galaxias.',
            'admin_settings_site_heading' => 'Sitio',
            'admin_label_site_hostname' => 'Nombre de host público',
            'admin_help_site_hostname' => 'Nombre de host canónico de esta instancia (sin esquema ni barra final). Se usa para construir enlaces en el correo saliente y como host de identidad de federación. Déjalo en blanco para usar el valor de config.php.',
            'admin_label_site_base_url' => 'URL base (anulación opcional)',
            'admin_help_site_base_url' => 'URL base completa con esquema, usada en lugar del nombre de host cuando se define. Déjala en blanco salvo que esta instancia se sirva con un esquema o una ruta no estándar.',
            'admin_label_default_locale' => 'Idioma predeterminado',
            'admin_help_default_locale' => 'Idioma que se muestra a quien visita cuando su navegador no pide ningún idioma que Telaris hable. Automático recurre al primer idioma disponible. Una elección explícita en la barra de direcciones siempre tiene prioridad.',
            'admin_default_locale_automatic' => 'Automático (preferencia del navegador)',
            'admin_settings_mail_heading' => 'Correo (SMTP)',
            'admin_settings_mail_intro' => 'Necesario para los enlaces de inicio de sesión, las confirmaciones de alta y los restablecimientos de contraseña. Cuando está en blanco, esos correos no se envían sin avisar.',
            'admin_mail_not_configured' => 'El correo no está configurado. No se enviará correo transaccional hasta que los ajustes de SMTP de abajo estén completos.',
            'admin_mail_configured' => 'El correo está configurado. Usa el botón de prueba de abajo para confirmar la entrega.',
            'admin_label_mail_host' => 'Host SMTP',
            'admin_label_mail_port' => 'Puerto',
            'admin_label_mail_user' => 'Usuario',
            'admin_label_mail_pass' => 'Contraseña',
            'admin_help_mail_pass' => 'Déjala en blanco para conservar la contraseña guardada.',
            'admin_mail_pass_set' => '(sin cambios)',
            'admin_label_mail_from_address' => 'Dirección de remitente',
            'admin_label_mail_from_name' => 'Nombre de remitente',
            'admin_label_mail_secure' => 'Cifrado',
            'admin_mail_secure_tls' => 'STARTTLS (587)',
            'admin_mail_secure_ssl' => 'SSL (465)',
            'admin_mail_secure_none' => 'Ninguno (no recomendado)',
            'admin_btn_send_test_email' => 'Enviar correo de prueba',
            'admin_help_send_test_email' => 'Envía un mensaje de prueba a tu dirección de correo de administración.',
            'admin_msg_mailtest_ok' => 'Correo de prueba enviado. Revisa tu bandeja de entrada para confirmar la entrega.',
            'admin_msg_mailtest_unconfigured' => 'El correo no está configurado. Completa los ajustes de SMTP de abajo y guárdalos antes de enviar una prueba.',
            'admin_msg_mailtest_noaddr' => 'Tu cuenta de administración no tiene una dirección de correo registrada, así que no hay a dónde enviar la prueba.',
            'admin_msg_mailtest_fail' => 'No se pudo enviar el correo de prueba. Revisa los ajustes de SMTP y el registro de correo del servidor.',
            'admin_auto_enroll_mail_warning' => 'El correo no está configurado en esta instancia, así que no se pueden enviar los enlaces de confirmación de alta y el alta automática no funcionará. Configura el correo en Ajustes globales primero.',

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

            'api_error_413_001' => 'Se alcanzó el límite de almacenamiento: elimina parte del contenido existente antes de subir más.',

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
            'galaxy_update_read_only' => 'Tienes acceso de solo lectura a esta galaxia. Puedes verla, pero no modificarla.',
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
            'viewer_maximize_text' => 'Maximizar',
            'viewer_restore_text' => 'Restaurar',
            'viewer_close_text' => 'Cerrar',
            'viewer_open_hotglue_newtab_text' => 'Ver el contenido en pantalla completa',
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
            'open_portal_text' => 'Entrar',
            'sound_label_text' => 'Som:', 'sound_on_text' => 'SIM', 'sound_off_text' => 'NÃO',
            'launching_text' => 'Você está atravessando o interior', 'mission_active_text' => 'Missão Ativa', 'go_text' => 'VAI',
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
            'editor_banner_seat_read_only' => 'Você tem acesso somente leitura a esta galáxia. Pode ver seus buracos de minhoca, palavras-chave e páginas, mas não pode fazer alterações.',
            'editor_heading_wormholes' => 'Buracos de minhoca',
            'editor_btn_new_wormhole' => 'Novo buraco de minhoca',
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
            'editor_text_create_wormhole_link' => 'criar um novo buraco de minhoca',
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
            'editor_badge_has_hotglue' => 'HG',
            'editor_badge_has_hotglue_title' => 'Tem hotglue',
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
            'editor_view_basic' => 'Visão básica',
            'editor_view_advanced' => 'Visão avançada',
            'editor_view_toggle_label' => 'Nível de detalhe do editor',
            'editor_btn_delete_file' => 'Excluir',
            'editor_btn_update_wormhole' => 'Atualizar buraco de minhoca',
            'editor_tab_classic' => 'Clássico',
            'editor_tab_media' => 'Mídia',
            'editor_tab_hotglue' => 'Hotglue',
            'editor_btn_edit_hotglue' => 'Editar conteúdo hotglue',
            'editor_help_hotglue' => 'Componha o conteúdo deste buraco de minhoca como uma página hotglue de formato livre. A aba selecionada ao salvar é o que será exibido a quem visitar.',
            'editor_hotglue_create_note' => 'Digite um nome acima para criar o buraco de minhoca, depois componha aqui sua página hotglue.',
            'editor_untitled_wormhole' => 'Buraco de minhoca sem título',
            'editor_hotglue_modal_heading' => 'Editar conteúdo hotglue',
            'editor_btn_hotglue_done' => 'Concluído',
            'editor_viewtab_wormholes' => 'Buracos de minhoca',
            'editor_viewtab_hotglue' => 'Conteúdo hotglue',
            'editor_viewtab_templates' => 'Modelos',
            'editor_action_create_template' => 'Criar modelo',
            'editor_tpl_heading' => 'Modelos',
            'editor_tpl_search_placeholder' => 'Buscar modelos...',
            'editor_tpl_col_name' => 'Nome',
            'editor_tpl_col_hotglue' => 'Hotglue',
            'editor_tpl_loading' => 'Carregando modelos...',
            'editor_tpl_selector_title' => 'Baseie o próximo buraco de minhoca em um modelo',
            'editor_tpl_selector_blank' => 'Sem modelo',
            'editor_tpl_untitled' => 'Modelo sem título',
            'editor_tpl_empty_hint' => 'Ainda não há modelos. Abra o menu Ações de um buraco de minhoca e escolha "Criar modelo" para criar um.',
            'editor_tpl_no_match' => 'Nenhum modelo corresponde à sua busca.',
            'editor_tpl_hotglue_yes' => 'Inclui conteúdo do Hotglue',
            'editor_tpl_action_rename' => 'Renomear',
            'editor_tpl_rename_prompt' => 'Novo nome para este modelo:',
            'editor_tpl_confirm_delete' => 'Excluir este modelo? Esta ação não pode ser desfeita. Os buracos de minhoca já criados a partir dele não são afetados.',
            'editor_tpl_created_toast' => 'Modelo criado',
            'editor_tpl_deleted_toast' => 'Modelo excluído',
            'editor_hg_heading' => 'Conteúdo hotglue',
            'editor_hg_btn_new' => 'Nova página',
            'editor_hg_search_placeholder' => 'Buscar páginas...',
            'editor_hg_col_title' => 'Título',
            'editor_hg_col_assigned' => 'Buraco de minhoca atribuído',
            'editor_hg_loading' => 'Carregando páginas...',
            'editor_hg_title_placeholder' => 'Título da página',
            'editor_hg_title_hint' => 'Renomear esta página',
            'editor_hg_assign_label' => 'Buraco de minhoca atribuído:',
            'editor_hg_assign_none' => 'Sem atribuição',
            'editor_hg_untitled' => 'Página sem título',
            'editor_hg_empty' => 'Ainda não há páginas hotglue. Você pode %s.',
            'editor_hg_galaxy_empty' => 'Não há páginas hotglue atribuídas a nenhum buraco de minhoca na galáxia selecionada. Você pode %s, ou selecionar outra galáxia.',
            'editor_hg_create_link' => 'criar uma nova página',
            'editor_hg_copy_suffix' => '(cópia)',
            'editor_hg_dup_notice' => 'A cópia foi criada sem atribuição a um buraco de minhoca (um buraco de minhoca só pode mostrar uma página). Quer atribuí-la a um buraco de minhoca agora? Escolha Cancelar para deixá-la sem atribuição.',
            'editor_hg_action_view_in_wormhole' => 'Ver no buraco de minhoca',
            'editor_hg_action_view_in_galaxy' => 'Ver na galáxia',
            'editor_hg_action_view_directly' => 'Ver no navegador',
            'editor_hg_action_copy_url' => 'Copiar URL direta',
            'editor_hg_btn_revisions' => 'Revisões',
            'editor_hg_no_match' => 'Nenhuma página corresponde à sua busca.',
            'editor_hg_unassigned' => 'Sem atribuição',
            'editor_hg_save_failed' => 'Falha ao salvar',
            'editor_hg_confirm_replace' => 'Substituir? Este buraco de minhoca já mostra uma página hotglue. A página que ele mostra agora ficará sem atribuição (não é excluída).',
            'editor_hg_confirm_delete' => 'Excluir esta página hotglue? Isso remove o conteúdo permanentemente. Se estiver atribuída a um buraco de minhoca, esse buraco volta para a mídia clássica.',
            'editor_hg_err_not_authorized' => 'Você não tem acesso para fazer isso.',
            'editor_hg_err_read_only' => 'Essa galáxia é somente leitura.',
            'editor_hg_err_generic' => 'Algo deu errado. Tente novamente.',
            'editor_hg_in_galaxy' => 'em %s',
            'editor_hg_name_label' => 'Nome da página',
            'editor_hg_selected_suffix' => 'páginas selecionadas',
            'editor_hg_bulk_unassign' => 'Desatribuir selecionadas',
            'editor_hg_bulk_delete' => 'Excluir selecionadas',
            'editor_hg_confirm_bulk_delete' => 'Excluir as páginas hotglue selecionadas? Isso remove o conteúdo permanentemente. Os buracos de minhoca atribuídos voltam para a mídia clássica.',
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
            'editor_modal_heading_shortcuts' => 'Atalhos do teclado',
            'editor_shortcut_new_wormhole' => 'Novo buraco de minhoca',
            'editor_shortcut_focus_search' => 'Focar o campo de busca',
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
            'editor_autosave_saving' => 'Salvando…',
            'editor_autosave_saved' => 'Todas as alterações salvas',
            'editor_autosave_failed' => 'Falha ao salvar; continue editando para tentar de novo',
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
            'admin_label_fuzzy_keywords' => 'Correspondência aproximada de palavras-chave',
            'admin_help_fuzzy_keywords' => 'Quando ativado, as vistas multigaláxia conectam buracos de minhoca cujas palavras-chave nomeiam a mesma ideia mesmo quando as palavras diferem (por exemplo colonial, colonialismo e erros de digitação). Desativado, traça linhas apenas entre correspondências exatas. Cada aglomerado pode substituir esta opção.',
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
            'admin_pluriverse_peers_heading' => 'Lista local de instâncias pares',
            'admin_pluriverse_peers_subheading' => 'Outras instâncias que este site conhece. São obtidas do Pluriverse em um horário regular. Nenhum conteúdo flui até haver uma lista branca bilateral com cada par (etapa 4+).',
            'admin_pluriverse_btn_refresh' => 'Atualizar agora',
            'admin_pluriverse_peers_last_ok' => 'Última obtenção bem-sucedida:',
            'admin_pluriverse_peers_never' => 'nunca',
            'admin_pluriverse_peers_failures' => 'Falhas consecutivas:',
            'admin_pluriverse_peers_last_err' => 'Último erro:',
            'admin_pluriverse_peers_empty' => 'Ainda não há instâncias pares. Aparecerão aqui após a próxima obtenção do Pluriverse, ou use Atualizar agora para obter imediatamente.',
            'admin_pluriverse_peers_col_label' => 'Nome',
            'admin_pluriverse_peers_col_hostname' => 'Nome do host',
            'admin_pluriverse_peers_col_source' => 'Origem',
            'admin_pluriverse_peers_col_fingerprint' => 'Impressão',
            'admin_pluriverse_peers_col_trust_state' => 'Estado de confiança',
            'admin_pluriverse_peers_col_last_seen' => 'Última atividade',
            'admin_pluriverse_peers_source_registry' => 'Pluriverse',
            'admin_pluriverse_peers_source_manual' => 'Manual',
            'admin_pluriverse_peers_source_manual_help' => 'Não avalizada pelo Pluriverse.',
            'admin_pluriverse_peers_manual_banner' => 'Par manual adicionado por %s em %s; verificar a intenção.',
            'admin_pluriverse_refresh_ok' => 'Pluriverse atualizado:',
            'admin_pluriverse_refresh_err' => 'A atualização do Pluriverse falhou:',
            'admin_pluriverse_enforce_blocked' => 'instância(s) bloqueada(s) e seus espelhos removidos',
            'admin_peer_block_col_actions' => 'Ações',
            'admin_peer_block_btn' => 'Bloquear esta instância',
            'admin_peer_block_heading' => 'Bloquear esta instância',
            'admin_peer_block_warn' => 'Bloquear remove todas as galáxias que você espelha desta instância e para de oferecer as suas a ela. O conteúdo é removido, não pausado; você não poderá restaurá-lo automaticamente depois, apenas se inscrever de novo de forma deliberada. Digite sua senha de novo para confirmar.',
            'admin_peer_block_field_category' => 'Categoria',
            'admin_peer_block_cat_spam' => 'Spam ou abuso',
            'admin_peer_block_cat_harmful' => 'Conteúdo nocivo',
            'admin_peer_block_cat_legal' => 'Legal ou remoção',
            'admin_peer_block_cat_consent' => 'Consentimento retirado',
            'admin_peer_block_cat_other' => 'Outro',
            'admin_peer_block_field_reason' => 'Motivo',
            'admin_peer_block_reason_ph' => 'Por que você está bloqueando esta instância (registrado localmente)',
            'admin_peer_block_field_password' => 'Digite sua senha de novo',
            'admin_peer_block_confirm_btn' => 'Confirmar bloqueio',
            'admin_peer_block_blocked_label' => 'Bloqueada',
            'admin_peer_block_reason_shown' => 'Motivo:',
            'admin_peer_block_unblock_btn' => 'Desbloquear',
            'admin_peer_block_unblock_help' => 'Devolve a instância ao estado descoberta. Os espelhos não são restaurados.',
            'admin_peer_block_ok' => 'Instância bloqueada. %d espelho(s) removido(s) e qualquer oferta de publicação a ela foi limpa.',
            'admin_peer_block_unblock_ok' => 'Instância desbloqueada e devolvida ao estado descoberta. Seus espelhos não foram restaurados; inscreva-se de novo de forma deliberada se quiser as galáxias dela outra vez.',
            'admin_peer_block_err_notfound' => 'Essa instância não foi encontrada. Recarregue a página de administração e tente de novo.',
            'admin_peer_block_err_action' => 'Ação de instância não reconhecida.',
            'admin_peer_block_err_category' => 'Escolha uma categoria para o bloqueio.',
            'admin_peer_block_err_reason' => 'É necessário um motivo (até 1024 caracteres).',
            'admin_peer_block_err_password_required' => 'Digite sua senha de novo para confirmar.',
            'admin_peer_block_err_password_wrong' => 'A senha não corresponde a esta conta de administração.',
            'admin_galaxy_pull_btn_refresh' => 'Atualizar galáxias agora',
            'admin_galaxy_pull_refresh_ok' => 'Atualização de galáxias concluída:',
            'admin_galaxy_pull_refresh_err' => 'A atualização de galáxias falhou:',
            'admin_pub_section_heading' => 'Suas galáxias publicadas',
            'admin_pub_section_subheading' => 'As galáxias que você criou e pode publicar, republicar, retratar ou exportar. Outras instâncias espelham o envelope assinado; o backup de fidelidade completa abaixo é a ação operacional, separada do envelope de federação.',
            'admin_pub_col_galaxy' => 'Galáxia',
            'admin_pub_col_slug' => 'Identificador',
            'admin_pub_col_status' => 'Status',
            'admin_pub_col_sequence' => 'Sequência',
            'admin_pub_col_published_at' => 'Última publicação',
            'admin_pub_col_actions' => 'Ações',
            'admin_pub_status_published' => 'Publicada',
            'admin_pub_status_not_published' => 'Não publicada',
            'admin_pub_status_retracted' => 'Retratada',
            'admin_pub_status_stale' => 'Obsoleta',
            'admin_pub_empty' => 'Ainda não há galáxias criadas localmente nesta instância. Crie uma galáxia; ela aparecerá aqui quando tiver identificador.',
            'admin_pub_btn_publish' => 'Publicar agora',
            'admin_pub_btn_republish' => 'Republicar',
            'admin_pub_btn_retract' => 'Retratar',
            'admin_pub_btn_download_backup' => 'Baixar backup completo',
            'admin_pub_retract_label_slug' => 'Digite o identificador para confirmar',
            'admin_pub_retract_help' => 'A retratação é permanente e de via única: o identificador fica inutilizável e as instâncias inscritas removerão o espelho no próximo ciclo. Digite o identificador para confirmar.',
            'admin_pub_retract_label_reason' => 'Motivo (opcional, público)',
            'admin_pub_retract_reason_placeholder' => 'Por que está retratando esta galáxia?',
            'admin_pub_retract_open' => 'Abrir painel de retratação',
            'admin_pub_retract_warn' => 'Permanente.',
            'admin_galaxy_publish_err_missing' => 'Referência de galáxia ausente ou inválida.',
            'admin_galaxy_publish_err' => 'A publicação falhou:',
            'admin_galaxy_publish_ok' => 'Galáxia publicada:',
            'admin_galaxy_retract_err_not_found' => 'Galáxia não encontrada.',
            'admin_galaxy_retract_err_confirm' => 'A confirmação digitada não corresponde ao identificador. A retratação não foi executada.',
            'admin_galaxy_retract_err' => 'A retratação falhou:',
            'admin_galaxy_retract_ok' => 'Galáxia retratada:',
            'admin_galaxy_retract_already' => 'O identificador já estava retratado; o envelope está intacto:',
            'admin_galaxy_backup_err_not_authored' => 'Esta galáxia não pode ser exportada: não é uma galáxia criada localmente.',
            'admin_galaxy_backup_err' => 'O backup falhou:',
            'admin_pub_retracted_on' => 'retratada',
            'admin_mir_section_heading' => 'Galáxias espelhadas',
            'admin_mir_section_subheading' => 'Galáxias que você assina de outras instâncias, materializadas localmente como espelhos somente leitura. Atualizadas a cada ciclo do cron galaxy-pull.',
            'admin_mir_empty' => 'Ainda não há galáxias espelhadas. As assinaturas aparecem aqui quando uma lista de um acordo bilateral autoriza a assinatura e um ciclo de atualização é concluído.',
            'admin_mir_col_origin' => 'Origem',
            'admin_mir_col_remote_slug' => 'Identificador remoto',
            'admin_mir_col_local' => 'Espelho local',
            'admin_mir_col_seq' => 'Sequência',
            'admin_mir_col_hash' => 'Resumo do conteúdo',
            'admin_mir_col_last_sync' => 'Última sincronização',
            'admin_mir_col_status' => 'Status',
            'admin_mir_status_active' => 'Ativa',
            'admin_mir_status_pending' => 'Aguardando primeira sincronização',
            'admin_mir_status_fossilized' => 'Fossilizada',
            'admin_mir_status_paused' => 'Pausada',
            'admin_mir_node_count_suffix' => 'buracos de minhoca',
            'admin_rmtret_section_heading' => 'Retratações honradas',
            'admin_rmtret_section_subheading' => 'Identificadores que as instâncias de origem retrataram; o espelho foi removido no momento da honra. O envelope assinado é preservado para que o evento possa ser reverificado.',
            'admin_rmtret_empty' => 'Nenhuma retratação de origem honrada ainda.',
            'admin_rmtret_col_origin' => 'Origem',
            'admin_rmtret_col_slug' => 'Identificador',
            'admin_rmtret_col_retracted_at' => 'Retratada em',
            'admin_rmtret_col_reason' => 'Motivo',
            'admin_rmtret_col_honored_at' => 'Honrada em',
            'admin_ms_section_heading' => 'Armazenamento de mídia de federação',
            'admin_ms_section_subheading' => 'Arquivos de mídia endereçados por conteúdo, compartilhados entre espelhos. A contagem no banco de dados é o que a API de federação serve; a contagem em disco é o armazenamento subjacente. Uma diferença indica que falta uma varredura de limpeza.',
            'admin_ms_label_blobs_db' => 'Arquivos registrados',
            'admin_ms_label_blobs_disk' => 'Arquivos em disco',
            'admin_ms_label_size_db' => 'Tamanho registrado',
            'admin_ms_label_size_disk' => 'Tamanho em disco',
            'admin_ms_label_path' => 'Caminho',
            'admin_ms_drift_warn' => 'A contagem em disco difere da do banco; existem arquivos órfãos (varredura pendente).',
            'visitor_mirror_label' => 'Espelhada de',
            'visitor_mirror_view_on_origin' => 'Ver na origem',
            'editor_banner_mirror_federation' => 'Esta galáxia é espelhada de %s e é apenas leitura. As atualizações chegam pelo cron galaxy-pull, ou você pode usar Atualizar galáxias agora na aba Pluriverse do painel administrativo.',
            'admin_ms_gc_btn' => 'Limpar arquivos órfãos',
            'admin_ms_gc_ok' => 'Limpeza concluída:',
            'admin_ms_gc_blobs' => 'arquivos órfãos',
            'admin_ms_gc_rows' => 'registros órfãos',
            'admin_ms_gc_freed' => 'liberados',
            'admin_ms_gc_protected' => 'protegidos em trânsito',
            'admin_pluriverse_manual_disclosure' => 'Avançado: adicionar uma instância par manualmente',
            'admin_pluriverse_manual_warn_heading' => 'Por que isso é restrito',
            'admin_pluriverse_manual_warn_body' => 'Uma instância par manual contorna a cadeia de confiança do Pluriverse: nada verificou se este nome de host e esta chave pública realmente correspondem à operação que se pretende contatar. A linha é adicionada com uma marca de não avalizada pelo Pluriverse e um aviso persistente para que a administração possa revisá-la depois. Digite a senha novamente abaixo para confirmar.',
            'admin_pluriverse_manual_field_hostname' => 'Nome do host',
            'admin_pluriverse_manual_field_url' => 'URL',
            'admin_pluriverse_manual_field_label' => 'Nome',
            'admin_pluriverse_manual_field_pubkey' => 'Chave pública Ed25519 (base64url)',
            'admin_pluriverse_manual_field_pubkey_help' => 'Obtenha este valor fora de banda com a operação par. É o valor de pluriverse.key.public na instância remota.',
            'admin_pluriverse_manual_field_password' => 'Digite sua senha novamente',
            'admin_pluriverse_manual_btn_add' => 'Adicionar instância par manual',
            'admin_pluriverse_manual_added' => 'Instância par manual %s adicionada. Trate-a como não avalizada pelo Pluriverse até confirmar fora de banda com a outra operação.',
            'admin_pluriverse_manual_err_hostname' => 'O nome do host deve ser um DNS em minúsculas (por exemplo, example.org).',
            'admin_pluriverse_manual_err_url' => 'A URL deve começar por https://.',
            'admin_pluriverse_manual_err_label' => 'O nome é obrigatório (1-255 caracteres).',
            'admin_pluriverse_manual_err_pubkey' => 'A chave pública deve ser uma chave Ed25519 de 32 bytes codificada em base64url.',
            'admin_pluriverse_manual_err_password_required' => 'Digite a senha novamente para confirmar.',
            'admin_pluriverse_manual_err_password_wrong' => 'A senha não corresponde a esta conta de administração.',
            'admin_pluriverse_manual_err_duplicate' => 'Já existe uma instância par para o nome de host %s (origem: %s).',
            'admin_msg_csrf_invalid' => 'Token de segurança inválido ou expirado. Recarregue a página de administração e tente novamente.',
            // Stage 4e: painel de apertos de mão pendentes.
            'admin_handshake_section_heading' => 'Apertos de mão pendentes',
            'admin_handshake_section_subheading' => 'Apertos de mão de federação em curso (três rodadas). As solicitações de entrada chegam pelo retransmissor do Pluriverso; as de saída são despachadas no próximo ciclo do cron pluriverse-dispatch.',
            'admin_handshake_empty' => 'Ainda não há apertos de mão.',
            'admin_handshake_inbound_heading' => 'Entrantes — aguardando sua decisão',
            'admin_handshake_outbound_heading' => 'Salientes — aguardando a outra instância',
            'admin_handshake_history_heading' => 'Histórico recente (apertos terminados, janela de 30 dias)',
            'admin_handshake_th_sender' => 'Remetente',
            'admin_handshake_th_remote' => 'Remoto',
            'admin_handshake_th_received' => 'Recebido',
            'admin_handshake_th_request_excerpt' => 'Corpo da mensagem (trecho)',
            'admin_handshake_th_expires' => 'Expira',
            'admin_handshake_th_state' => 'Estado',
            'admin_handshake_th_delivery' => 'Entrega',
            'admin_handshake_th_direction' => 'Direção',
            'admin_handshake_th_updated' => 'Atualizado',
            'admin_handshake_th_reason' => 'Motivo',
            'admin_handshake_actions' => 'Ações',
            'admin_handshake_btn_accept' => 'Aceitar',
            'admin_handshake_btn_reject' => 'Rejeitar',
            'admin_handshake_btn_reject_confirm' => 'Confirmar rejeição',
            'admin_handshake_btn_cancel' => 'Cancelar',
            'admin_handshake_reject_prompt' => 'Motivo (opcional)',
            'admin_handshake_confirm_cancel' => 'Cancelar este aperto de mão de saída?',
            'admin_handshake_state_pending_their_response' => 'Aguardando resposta da outra instância',
            'admin_handshake_state_pending_our_response' => 'Aguardando sua decisão',
            'admin_handshake_state_accepted_awaiting_complete' => 'Aceito, aguardando confirmação final',
            'admin_handshake_state_complete' => 'Completo',
            'admin_handshake_state_rejected' => 'Rejeitado',
            'admin_handshake_state_expired' => 'Expirado',
            'admin_handshake_state_cancelled' => 'Cancelado',
            'admin_handshake_initiator_us' => 'Iniciado aqui',
            'admin_handshake_initiator_them' => 'Iniciado pela outra instância',
            'admin_handshake_delivery_not_applicable' => 'não se aplica',
            'admin_handshake_delivery_pending' => 'Na fila',
            'admin_handshake_delivery_delivered' => 'Entregue',
            'admin_handshake_delivery_failed' => 'Falhou, tentando novamente',
            'admin_handshake_delivery_given_up' => 'Desistido',
            'admin_handshake_delivery_unknown' => 'desconhecido',
            'admin_handshake_attempts_n' => '%d tentativas',
            'admin_handshake_compose_btn_show' => 'Iniciar um aperto de mão…',
            'admin_handshake_compose_subheading' => 'Envie uma solicitação assinada de aperto de mão pelo retransmissor do Pluriverso. Quem opera a instância remota recebe um e-mail e vê a solicitação no seu próprio painel.',
            'admin_handshake_compose_field_recipient' => 'Nome de host do destinatário',
            'admin_handshake_compose_field_recipient_help' => 'Nome de host (sem esquema) de uma instância publicada no Pluriverso.',
            'admin_handshake_compose_field_subject' => 'Assunto (opcional)',
            'admin_handshake_compose_field_body' => 'Corpo da mensagem (markdown)',
            'admin_handshake_compose_field_body_help' => 'Visível para quem opera a instância remota ao entrar. Será analisado em busca de padrões de segredos de alta confiança; veja a opção de anulação abaixo.',
            'admin_handshake_compose_field_pub_galaxies' => 'Galáxias que você oferece publicar para essa instância',
            'admin_handshake_compose_field_pub_help' => 'Slugs separados por vírgulas das suas galáxias autorais. Opcional.',
            'admin_handshake_compose_field_sub_galaxies' => 'Galáxias que você quer assinar dessa instância',
            'admin_handshake_compose_field_sub_help' => 'Slugs separados por vírgulas das galáxias autorais da outra instância. Opcional.',
            'admin_handshake_compose_send_anyway' => 'Enviar mesmo assim se o corpo parecer conter um segredo',
            'admin_handshake_compose_btn_send' => 'Enfileirar solicitação de aperto de mão',
            'admin_handshake_accept_ok' => 'Aperto de mão aceito; a resposta foi enfileirada para o próximo ciclo do despachador.',
            'admin_handshake_accept_err' => 'Não foi possível aceitar o aperto de mão:',
            'admin_handshake_reject_ok' => 'Aperto de mão rejeitado; a outra instância será notificada no próximo ciclo do despachador.',
            'admin_handshake_reject_err' => 'Não foi possível rejeitar o aperto de mão:',
            'admin_handshake_cancel_ok' => 'Aperto de mão cancelado. Qualquer mensagem saliente na fila foi abandonada; a outra instância não é notificada.',
            'admin_handshake_cancel_err' => 'Não foi possível cancelar o aperto de mão:',
            'admin_handshake_initiate_ok' => 'Solicitação de aperto de mão enfileirada. A entrega ao retransmissor do Pluriverso ocorre no próximo ciclo do despachador.',
            'admin_handshake_initiate_err' => 'Não foi possível enfileirar a solicitação de aperto de mão:',
            'admin_handshake_default_reject_reason' => 'Sem motivo informado.',
            'admin_handshake_err_missing_id' => 'Identificador do aperto de mão ausente.',
            'admin_handshake_err_peer_not_in_directory' => 'A instância remota ainda não está no diretório do Pluriverso. Espere a próxima obtenção de pares (ou clique em Atualizar agora) e tente de novo.',
            'admin_handshake_err_invalid_recipient' => 'O nome de host do destinatário está ausente ou malformado.',
            'admin_handshake_err_body_required' => 'Uma solicitação de aperto de mão exige um corpo de mensagem.',
            'admin_handshake_err_sensitive_info' => 'Sua mensagem contém conteúdo que parece um segredo (%s). Edite e tente novamente, ou marque "Enviar mesmo assim" para anular a verificação.',
            'admin_handshake_err_active_exists' => 'Já existe um aperto de mão ativo para esse host; cancele-o antes de iniciar outro.',
            'admin_whitelist_section_heading' => 'Listas de publicação e assinatura por par',
            'admin_whitelist_section_subheading' => 'Quais das galáxias que você criou seriam publicadas para cada par, e quais delas você quer assinar. Surte efeito após um aperto de mão bem-sucedido; você pode pré-carregar a intenção antes.',
            'admin_whitelist_no_peers' => 'Ainda não há pares. As listas tornam-se editáveis quando os pares aparecem na Lista local de pares.',
            'admin_whitelist_no_authored' => 'Ainda não há galáxias próprias.',
            'admin_whitelist_no_subscriptions' => 'Ainda não há assinaturas.',
            'admin_whitelist_trust_state_label' => 'Confiança:',
            'admin_whitelist_count_publish' => 'publicar',
            'admin_whitelist_count_subscribe' => 'assinar',
            'admin_whitelist_hint_post_handshake' => 'Nenhum aperto de mão foi concluído com este par ainda; a lista surte efeito quando isso acontecer.',
            'admin_whitelist_publish_heading' => 'Galáxias que publicamos para este par',
            'admin_whitelist_publish_help' => 'Apenas galáxias próprias aparecem aqui. Galáxias espelhadas não podem ser republicadas.',
            'admin_whitelist_publish_save_btn' => 'Salvar lista de publicação',
            'admin_whitelist_subscribe_heading' => 'Galáxias que assinamos deste par',
            'admin_whitelist_subscribe_help' => 'Adicione o slug de uma galáxia remota para assinar. Uma seleção múltipla chega quando o endpoint de galáxias publicadas estiver disponível.',
            'admin_whitelist_subscribe_th_slug' => 'Slug remoto',
            'admin_whitelist_subscribe_th_last_sync' => 'Última sinc.',
            'admin_whitelist_subscribe_th_actions' => 'Ações',
            'admin_whitelist_subscribe_field_slug' => 'Slug remoto',
            'admin_whitelist_subscribe_btn_add' => 'Adicionar assinatura',
            'admin_whitelist_subscribe_btn_remove' => 'Remover',
            'admin_whitelist_subscribe_confirm_remove' => 'Remover esta assinatura?',
            'admin_whitelist_publish_save_ok' => 'Lista de publicação salva (%1$d adicionadas, %2$d removidas).',
            'admin_whitelist_publish_save_err' => 'Não foi possível salvar a lista de publicação.',
            'admin_whitelist_subscription_add_ok' => 'Assinatura adicionada.',
            'admin_whitelist_subscription_add_exists' => 'Essa assinatura já está ativa; nada mudou.',
            'admin_whitelist_subscription_add_err' => 'Não foi possível adicionar a assinatura.',
            'admin_whitelist_subscription_remove_ok' => 'Assinatura removida.',
            'admin_whitelist_subscription_remove_err' => 'Não foi possível remover a assinatura.',
            'admin_whitelist_err_missing_peer' => 'Falta o id do par.',
            'admin_whitelist_err_unknown_peer' => 'Esse par não existe mais.',
            'admin_whitelist_err_mirrored' => 'Não é possível republicar uma galáxia espelhada; apenas galáxias próprias são permitidas.',
            'admin_whitelist_err_invalid_slug' => 'O slug remoto está vazio ou é longo demais.',
            'admin_whitelist_err_unknown_subscription' => 'Essa assinatura não existe mais.',
            'admin_whitelist_err_peer_mismatch' => 'Essa assinatura pertence a outro par.',
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
            'admin_msg_no_galaxies_search' => 'Nenhuma galáxia corresponde à sua busca.',
            'admin_msg_galaxies_empty' => 'Ainda não há galáxias. Você pode %s.',
            'admin_link_create_galaxy' => 'criar uma nova galáxia',
            'admin_msg_clusters_empty' => 'Ainda não há aglomerados. Você pode %s.',
            'admin_link_create_cluster' => 'criar um novo aglomerado',
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
            'admin_action_fractal_profile' => 'Forma da galáxia',
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
            'admin_modal_label_last_name' => 'Sobrenome',
            'admin_modal_help_last_name' => 'O sobrenome associado à conta. Opcional.',
            'admin_modal_label_pronouns' => 'Pronomes',
            'admin_modal_help_pronouns' => 'Opcional. Escolha até 3 ou adicione os seus. Pode deixar em branco.',
            'admin_modal_label_pronouns_custom' => 'Adicione os seus',
            'admin_modal_placeholder_pronouns_custom' => 'separados por vírgulas, p. ex. elu',
            'pronoun_common_set' => 'elu,ela,ele',
            'pronouns_error_too_many' => 'Escolha no máximo 3 conjuntos de pronomes.',
            'pronouns_error_too_long' => 'Cada pronome deve ter 30 caracteres ou menos.',
            'pronouns_error_charset' => 'Os pronomes só aceitam letras, espaços e os sinais / - e o apóstrofo.',
            'pronouns_error_denylist' => 'Essa entrada não pode ser usada como pronome.',
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
            'admin_modal_label_cluster_fuzzy' => 'Correspondência aproximada de palavras-chave',
            'admin_modal_help_cluster_fuzzy' => 'Conecta buracos de minhoca cujas palavras-chave nomeiam a mesma ideia mesmo quando as palavras diferem (colonial, colonialismo, erros de digitação). Herdar segue a opção padrão da instalação; Ativado ou Desativado substitui apenas para este aglomerado.',
            'admin_modal_fuzzy_inherit' => 'Usar a opção padrão da instalação',
            'admin_modal_fuzzy_on' => 'Ativada para este aglomerado',
            'admin_modal_fuzzy_off' => 'Desativada para este aglomerado',
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
            'admin_setup_last_name_label' => 'Sobrenome',
            'admin_setup_pronouns_label' => 'Pronomes',
            'admin_setup_pronouns_help' => 'Opcional. Escolha até 3 ou adicione os seus. Pode deixar em branco.',
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
            'loginlink_link_label' => 'Sem senha? Envie-me um link de acesso',
            'loginlink_expired_error' => 'Esse link de acesso é inválido ou expirou. Solicite um novo abaixo.',
            'loginlink_page_title' => 'Enviar um link de acesso - Telaris',
            'loginlink_heading' => 'Envie-me um link de acesso',
            'loginlink_subtitle' => 'Enviaremos por e-mail um link de uso único para entrar sem senha.',
            'loginlink_generic_notice' => 'Se existir uma conta com esse e-mail, um link de acesso foi enviado.',
            'loginlink_submit' => 'Enviar link de acesso',
            'auth_login_emaillink_button' => 'Envie-me um link de acesso',
            'auth_login_have_password' => 'Tenho senha',
            'enroll_menu_link' => 'Entrar como editor',
            'enroll_page_title' => 'Entrar como editor - Telaris',
            'enroll_heading' => 'Entre como editor',
            'enroll_intro' => 'Junte-se a esta instância do Telaris como editor. Informe o seu nome e e-mail, aceite os Termos de Uso e a Política de Privacidade, e enviaremos um link para confirmar.',
            'enroll_name_label' => 'O seu nome',
            'enroll_email_label' => 'E-mail',
            'enroll_submit' => 'Solicitar acesso',
            'enroll_check_email_notice' => 'Verifique o seu e-mail. Se o seu endereço puder entrar, o link de confirmação está a caminho. O link expira em 24 horas.',
            'enroll_domain_rejected' => 'Nesta instância, entrar como editor é limitado a certos domínios de e-mail, e esse endereço não é um deles.',
            'enroll_disabled_notice' => 'A entrada de editores não está aberta nesta instância no momento.',
            'enroll_full_notice' => 'A entrada de editores está completa nesta instância no momento. Tente novamente mais tarde.',
            'enroll_confirm_invalid' => 'Esse link de confirmação é inválido ou expirou. Você pode solicitar a entrada novamente.',
            'enroll_galaxy_name_possessive' => 'Galáxia de %s',
            'enroll_pending_galaxy_banner' => 'Boas-vindas. Quando quiser, crie a sua primeira galáxia para começar a adicionar buracos de minhoca.',
            'enroll_name_required' => 'Informe o seu nome.',
            'admin_btn_auto_enroll' => 'Auto-inscrição',
            'admin_badge_unvetted' => 'Não verificado',
            'admin_unvetted_title' => 'Entrou por conta própria; ainda não verificado por um administrador',
            'admin_modal_label_vetted' => 'Verificado',
            'admin_modal_help_vetted' => 'Verificar um editor que entrou por conta própria envia a ele um link para criar uma senha e mostra um aviso no aplicativo. Não muda o que ele pode editar. Sem verificação, ele entra com um link por e-mail a cada vez.',
            'auto_enroll_saved' => 'Configurações de auto-inscrição salvas.',
            'admin_auto_enroll_heading' => 'Auto-inscrição de editores',
            'admin_auto_enroll_intro' => 'Permita que as pessoas entrem nesta instância como editores por conta própria. Desativado por padrão. Você mantém o controle: quem entra assim fica marcado como Não verificado até você verificar, e só edita as galáxias que você conceder.',
            'admin_auto_enroll_enable' => 'Ativar a auto-inscrição nesta instalação',
            'admin_auto_enroll_enable_warning' => 'Com isto ativado, qualquer pessoa com um e-mail válido (conforme o limite de domínios e o teto abaixo) pode entrar como Editor. Ela só edita as galáxias que você conceder e fica Não verificada até você verificar. Ativar a auto-inscrição?',
            'admin_auto_enroll_create_galaxy' => 'Criar uma galáxia pessoal para cada novo editor',
            'admin_auto_enroll_naming_label' => 'Convenção de nome da nova galáxia',
            'admin_auto_enroll_naming_email_username' => 'Apenas o usuário do e-mail (ariel)',
            'admin_auto_enroll_naming_full_email' => 'E-mail completo (ariel@example.com)',
            'admin_auto_enroll_naming_first_name' => 'A galáxia do seu nome',
            'admin_auto_enroll_naming_full_name' => 'Nome completo (ariel-souza)',
            'admin_auto_enroll_naming_user_choice' => 'Deixar escolher no primeiro acesso',
            'admin_auto_enroll_naming_privacy_note' => 'Os nomes das galáxias aparecem publicamente na visão 3D e na URL da página. As opções de e-mail deixam à mostra o endereço de quem edita; prefira o primeiro nome ou deixar a pessoa escolher.',
            'admin_auto_enroll_galaxies_label' => 'Conceder acesso a estas galáxias',
            'admin_auto_enroll_select_all' => 'Todas',
            'admin_auto_enroll_select_none' => 'Nenhuma',
            'admin_auto_enroll_group_hint' => 'Dica: clique em um [PREFIXO] para alternar esse grupo.',
            'admin_auto_enroll_access_rw' => 'Leitura e escrita',
            'admin_auto_enroll_access_ro' => 'Somente leitura',
            'admin_auto_enroll_domains_label' => 'Limitar a domínios de e-mail (opcional)',
            'admin_auto_enroll_domains_ph' => 'ex.: ubc.ca, gmail.com (vazio = qualquer)',
            'admin_auto_enroll_cap_label' => 'Limitar o número de editores auto-inscritos',
            'admin_auto_enroll_cap_count' => 'Atualmente %d editor(es) auto-inscrito(s).',
            'admin_auto_enroll_save' => 'Salvar configurações',
            'editor_vetted_banner' => 'Um administrador verificou a sua conta. Você pode criar uma senha pelo link que enviamos por e-mail, para entrar mais rápido. O link por e-mail continua funcionando.',
            'admin_delete_personal_galaxy' => 'Excluir também a(s) %d galáxia(s) pessoal(is) desta pessoa (criadas por ela) e os seus buracos de minhoca. Galáxias compartilhadas não são afetadas.',
            'auth_email_subject' => 'Redefina sua senha do %s',
            'auth_email_greeting_named' => 'Olá %s,',
            'auth_email_greeting_anon' => 'Olá,',
            'auth_email_intro' => 'Recebemos um pedido para redefinir sua senha. Clique no link para definir uma nova:',
            'auth_email_cta' => 'Redefinir senha',
            'auth_email_expiry' => 'O link expira em 24 horas e só pode ser usado uma vez. Se você não solicitou a redefinição, pode ignorar este e-mail; sua senha não mudará.',
            'auth_email_text_intro' => "Recebemos um pedido para redefinir sua senha.\n\nLink de redefinição (24h, uso único):\n",
            'auth_email_text_outro' => "\n\nSe você não solicitou a redefinição, ignore este e-mail.",
            'email_drop_subject' => 'Galáxias federadas removidas',
            'email_drop_intro' => 'Uma ou mais galáxias federadas que esta instância espelhava foram removidas:',
            'email_drop_item' => '%1$s (espelhada de %2$s)',
            'email_drop_reason_label' => 'Motivo: %s',
            'email_drop_reason_retraction' => 'a instância de origem retratou a galáxia',
            'email_drop_reason_blacklist' => 'a instância de origem foi bloqueada no Pluriverse',
            'email_drop_reason_revoked' => 'a associação de federação da instância de origem foi revogada',
            'email_drop_reason_local' => 'você bloqueou a instância de origem',
            'email_drop_reason_publish_revoked' => 'a instância de origem revogou o seu acesso à galáxia',
            'email_drop_outro' => 'O conteúdo espelhado foi removido desta instância. Isso é esperado quando a confiança é retirada ou uma galáxia é retratada; nenhuma ação é necessária.',
            'admin_user_locale_label' => 'Idioma das notificações',
            'admin_user_locale_unset' => 'Não definido (todos os idiomas)',
            'admin_user_locale_saved' => 'Idioma das notificações atualizado.',
            'admin_user_pw_btn' => 'Atualizar senha',
            'admin_user_pw_too_short' => 'A senha precisa ter pelo menos 8 caracteres.',
            'admin_user_pw_updated' => 'Senha atualizada.',
            'admin_user_locale_invalid' => 'Idioma não suportado.',
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
            'gem_theme_light_rainbow' => 'Arco-íris claro (fundo claro, formas arco-íris)',
            'gem_theme_rhizome' => 'Rizoma (claro, mapa de conexões)',
            'gem_theme_cornrow' => 'Trança (tecido fractal, segundo Eglash)',
            'gem_theme_adire' => 'Adire (treliça fractal, segundo Eglash)',
            'theme_credit_cornrow' => 'Substrato fractal: geometria de tranças cornrow. Segundo Ron Eglash, African Fractals (1999).',
            'theme_credit_adire' => 'Substrato fractal: padrões de reserva índigo Adire ioruba. Segundo Ron Eglash, African Fractals (1999).',
            'rhizome_back' => 'Voltar à visão geral',
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
            'gem_group_nodes_label' => 'Agrupar buracos de minhoca',
            'gem_group_nodes_help' => 'Quando uma galáxia tem muitos buracos de minhoca, agrupa-os em conjuntos navegáveis em vez de mostrar todos de uma vez. Ativado por padrão. Desative para mostrar sempre todos os buracos de minhoca, sejam quantos forem.',
            'gem_heavy_inertia_label' => 'Movimento pesado',
            'gem_heavy_inertia_help' => 'Dá a esta galáxia uma sensação de peso e inércia alta: girar e dar zoom ficam mais lentos e a vista continua a deslizar depois de soltar, para que uma galáxia densa pareça maciça. Desativado por padrão.',
            'gem_fractal_title' => 'Como esta galáxia é formada',
            'gem_fractal_subtitle' => 'Perfil fractal · somente leitura',
            'gem_fractal_intro' => 'Uma leitura rápida de como os buracos de minhoca desta galáxia se conectam entre si por palavras-chave compartilhadas.',
            'gem_fractal_loading' => 'Lendo a galáxia…',
            'gem_fractal_details_toggle' => 'Ver as medidas',
            'gem_fractal_fit_label' => 'qualidade do ajuste',
            'gem_fractal_dB_label' => 'Dimensão fractal (d_B)',
            'gem_fractal_width_label' => 'Desigualdade (largura do espectro)',
            'gem_fractal_spectrum_label' => 'Textura de conexão, f(α)',
            'gem_fractal_gen_dims_label' => 'Dimensões generalizadas (D0/D1/D2)',
            'gem_fractal_gamma_label' => 'Domínio de nós centrais (expoente de grau γ)',
            'gem_fractal_stat_nodes' => 'Buracos de minhoca',
            'gem_fractal_stat_edges' => 'Conexões',
            'gem_fractal_stat_meandeg' => 'Ligações méd.',
            'gem_fractal_stat_components' => 'Peças conectadas',
            'gem_fractal_stat_diameter' => 'Passos de ponta a ponta',
            'gem_fractal_dB_low' => 'Os buracos de minhoca formam uma cadeia: a maioria dos caminhos passa por poucas palavras-chave centrais.',
            'gem_fractal_dB_mid' => 'Os buracos de minhoca formam uma teia espalhada, com muitos caminhos independentes entre eles.',
            'gem_fractal_dB_high' => 'Os buracos de minhoca formam um grupo compacto: quase tudo fica a um ou dois passos do resto.',
            'gem_fractal_width_narrow' => 'A ligação por palavras-chave é bastante uniforme em toda a galáxia.',
            'gem_fractal_width_wide' => 'A ligação por palavras-chave é desigual: algumas partes são muito conectadas e outras pouco.',
            'gem_fractal_reason_empty' => 'Esta galáxia ainda não tem buracos de minhoca.',
            'gem_fractal_reason_too_small' => 'Há poucos buracos de minhoca conectados para ler uma forma ainda.',
            'gem_fractal_reason_too_shallow' => 'Esta galáxia é pequena e muito ligada, então não há uma forma clara para ler: quase todo buraco de minhoca fica a um ou dois passos dos outros.',
            'gem_fractal_reason_too_large' => 'Esta galáxia é grande demais para ler na hora.',
            'gem_fractal_reason_cluster' => 'Isto lê uma galáxia por vez. Abra uma galáxia membro para ver a forma dela.',
            'gem_fractal_error' => 'Não foi possível ler esta galáxia.',
            'gem_sound_theme_label' => 'Tema de som',
            'gem_sound_theme_default' => 'Padrão (ambiente)',
            'gem_sound_theme_rhizome' => 'Rizoma (com falhas, agudo)',
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
            'api_error_403_009' => 'Esta galáxia é somente leitura: foi importada ou espelhada de outra instância e não pode ser editada aqui.',
            'api_error_403_010' => 'Você tem acesso somente leitura a esta galáxia. Pode ver o conteúdo, mas não alterá-lo.',
            'api_error_403_011' => 'A edição está desativada nesta instalação no momento.',
            'api_error_403_012' => 'A edição está desativada para este aglomerado.',
            'api_error_403_013' => 'A edição está desativada para esta galáxia.',
            'api_error_403_014' => 'Sua conta de edição está desativada. A edição está desligada.',
            'auth_editors_disabled_notice' => 'A edição está desativada aqui no momento. Se você acha que é um engano, fale com quem administra a instalação.',
            'admin_label_editors_enabled' => 'Permitir edição',
            'admin_help_editors_enabled' => 'Quando desativado, quem edita não consegue entrar nem fazer alterações em toda a instalação. As contas e o conteúdo são mantidos; não afeta a administração.',
            'admin_label_cluster_editors_enabled' => 'Permitir edição',
            'admin_help_cluster_editors_enabled' => 'Quando desativado, não é possível editar nenhuma galáxia deste aglomerado. Não afeta a administração.',
            'admin_label_galaxy_editors_enabled' => 'Permitir edição',
            'admin_help_galaxy_editors_enabled' => 'Quando desativado, não é possível editar esta galáxia. Não afeta a administração.',
            'admin_label_user_editor_enabled' => 'Edição ativada',
            'admin_help_user_editor_enabled' => 'Quando desativado, esta pessoa não consegue entrar nem fazer alterações. A conta e as galáxias são mantidas.',
            'admin_settings_site_heading' => 'Site',
            'admin_label_site_hostname' => 'Nome de host público',
            'admin_help_site_hostname' => 'Nome de host canônico desta instância (sem esquema nem barra final). Usado para construir links no email enviado e como host de identidade de federação. Deixe em branco para usar o valor do config.php.',
            'admin_label_site_base_url' => 'URL base (substituição opcional)',
            'admin_help_site_base_url' => 'URL base completa com esquema, usada no lugar do nome de host quando definida. Deixe em branco a menos que esta instância seja servida com um esquema ou caminho não padrão.',
            'admin_label_default_locale' => 'Idioma padrão',
            'admin_help_default_locale' => 'Idioma mostrado a quem visita quando o navegador não pede nenhum idioma que o Telaris fale. Automático recorre ao primeiro idioma disponível. Uma escolha explícita na barra de endereços sempre prevalece.',
            'admin_default_locale_automatic' => 'Automático (preferência do navegador)',
            'admin_settings_mail_heading' => 'Email (SMTP)',
            'admin_settings_mail_intro' => 'Necessário para os links de entrada, as confirmações de cadastro e as redefinições de senha. Quando está em branco, esses emails não são enviados sem aviso.',
            'admin_mail_not_configured' => 'O email não está configurado. Nenhum email transacional será enviado até que os ajustes de SMTP abaixo estejam completos.',
            'admin_mail_configured' => 'O email está configurado. Use o botão de teste abaixo para confirmar a entrega.',
            'admin_label_mail_host' => 'Host SMTP',
            'admin_label_mail_port' => 'Porta',
            'admin_label_mail_user' => 'Usuário',
            'admin_label_mail_pass' => 'Senha',
            'admin_help_mail_pass' => 'Deixe em branco para manter a senha armazenada.',
            'admin_mail_pass_set' => '(sem alteração)',
            'admin_label_mail_from_address' => 'Endereço de remetente',
            'admin_label_mail_from_name' => 'Nome de remetente',
            'admin_label_mail_secure' => 'Criptografia',
            'admin_mail_secure_tls' => 'STARTTLS (587)',
            'admin_mail_secure_ssl' => 'SSL (465)',
            'admin_mail_secure_none' => 'Nenhuma (não recomendado)',
            'admin_btn_send_test_email' => 'Enviar email de teste',
            'admin_help_send_test_email' => 'Envia uma mensagem de teste para o seu email de administração.',
            'admin_msg_mailtest_ok' => 'Email de teste enviado. Verifique sua caixa de entrada para confirmar a entrega.',
            'admin_msg_mailtest_unconfigured' => 'O email não está configurado. Preencha os ajustes de SMTP abaixo e salve antes de enviar um teste.',
            'admin_msg_mailtest_noaddr' => 'Sua conta de administração não tem um endereço de email registrado, então não há para onde enviar o teste.',
            'admin_msg_mailtest_fail' => 'Não foi possível enviar o email de teste. Verifique os ajustes de SMTP e o registro de email do servidor.',
            'admin_auto_enroll_mail_warning' => 'O email não está configurado nesta instância, então os links de confirmação de cadastro não podem ser enviados e o autocadastro não vai funcionar. Configure o email em Configurações globais primeiro.',

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

            'api_error_413_001' => 'Limite de armazenamento atingido: remova parte do conteúdo existente antes de enviar mais.',

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
            'galaxy_update_read_only' => 'Você tem acesso somente leitura a esta galáxia. Pode vê-la, mas não alterá-la.',
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
            'viewer_maximize_text' => 'Maximizar',
            'viewer_restore_text' => 'Restaurar',
            'viewer_close_text' => 'Fechar',
            'viewer_open_hotglue_newtab_text' => 'Ver o conteúdo em tela cheia',
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
            'open_portal_text' => 'Entrer',
            'sound_label_text' => 'Son :', 'sound_on_text' => 'OUI', 'sound_off_text' => 'NON',
            'launching_text' => 'Vous traversez l\'intérieur', 'mission_active_text' => 'Mission active', 'go_text' => 'GO',
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
            'editor_banner_seat_read_only' => 'Vous avez un accès en lecture seule à cette galaxie. Vous pouvez voir ses trous de ver, mots-clés et pages, mais vous ne pouvez pas faire de modifications.',
            'editor_heading_wormholes' => 'Trous de ver',
            'editor_btn_new_wormhole' => 'Nouveau trou de ver',
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
            'editor_text_create_wormhole_link' => 'créer un nouveau trou de ver',
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
            'editor_badge_has_hotglue' => 'HG',
            'editor_badge_has_hotglue_title' => 'A du hotglue',
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
            'editor_view_basic' => 'Vue simple',
            'editor_view_advanced' => 'Vue avancée',
            'editor_view_toggle_label' => 'Niveau de détail de l\'éditeur',
            'editor_btn_delete_file' => 'Supprimer',
            'editor_btn_update_wormhole' => 'Mettre à jour le trou de ver',
            'editor_tab_classic' => 'Classique',
            'editor_tab_media' => 'Média',
            'editor_tab_hotglue' => 'Hotglue',
            'editor_btn_edit_hotglue' => 'Modifier le contenu hotglue',
            'editor_help_hotglue' => 'Composez le contenu de ce trou de ver comme une page hotglue en forme libre. L\'onglet sélectionné lors de l\'enregistrement est ce qui sera montré aux personnes qui visitent.',
            'editor_hotglue_create_note' => 'Saisis un nom ci-dessus pour créer le trou de ver, puis compose ici sa page hotglue.',
            'editor_untitled_wormhole' => 'Trou de ver sans titre',
            'editor_hotglue_modal_heading' => 'Modifier le contenu hotglue',
            'editor_btn_hotglue_done' => 'Terminé',
            'editor_viewtab_wormholes' => 'Trous de ver',
            'editor_viewtab_hotglue' => 'Contenu hotglue',
            'editor_viewtab_templates' => 'Modèles',
            'editor_action_create_template' => 'Créer un modèle',
            'editor_tpl_heading' => 'Modèles',
            'editor_tpl_search_placeholder' => 'Rechercher des modèles...',
            'editor_tpl_col_name' => 'Nom',
            'editor_tpl_col_hotglue' => 'Hotglue',
            'editor_tpl_loading' => 'Chargement des modèles...',
            'editor_tpl_selector_title' => 'Baser le prochain trou de ver sur un modèle',
            'editor_tpl_selector_blank' => 'Aucun modèle',
            'editor_tpl_untitled' => 'Modèle sans titre',
            'editor_tpl_empty_hint' => 'Aucun modèle pour le moment. Ouvre le menu Actions d\'un trou de ver et choisis "Créer un modèle" pour en créer un.',
            'editor_tpl_no_match' => 'Aucun modèle ne correspond à ta recherche.',
            'editor_tpl_hotglue_yes' => 'Inclut du contenu Hotglue',
            'editor_tpl_action_rename' => 'Renommer',
            'editor_tpl_rename_prompt' => 'Nouveau nom pour ce modèle :',
            'editor_tpl_confirm_delete' => 'Supprimer ce modèle ? Cette action est irréversible. Les trous de ver déjà créés à partir de ce modèle ne sont pas affectés.',
            'editor_tpl_created_toast' => 'Modèle créé',
            'editor_tpl_deleted_toast' => 'Modèle supprimé',
            'editor_hg_heading' => 'Contenu hotglue',
            'editor_hg_btn_new' => 'Nouvelle page',
            'editor_hg_search_placeholder' => 'Rechercher des pages...',
            'editor_hg_col_title' => 'Titre',
            'editor_hg_col_assigned' => 'Trou de ver attribué',
            'editor_hg_loading' => 'Chargement des pages...',
            'editor_hg_title_placeholder' => 'Titre de la page',
            'editor_hg_title_hint' => 'Renommer cette page',
            'editor_hg_assign_label' => 'Trou de ver attribué :',
            'editor_hg_assign_none' => 'Aucune attribution',
            'editor_hg_untitled' => 'Page sans titre',
            'editor_hg_empty' => 'Il n\'y a pas encore de pages hotglue. Tu peux %s.',
            'editor_hg_galaxy_empty' => 'Aucune page hotglue n\'est attribuée à un trou de ver dans la galaxie sélectionnée. Tu peux %s, ou sélectionner une autre galaxie.',
            'editor_hg_create_link' => 'créer une nouvelle page',
            'editor_hg_copy_suffix' => '(copie)',
            'editor_hg_dup_notice' => 'La copie a été créée sans attribution à un trou de ver (un trou de ver ne peut afficher qu\'une seule page). Veux-tu l\'attribuer à un trou de ver maintenant ? Choisis Annuler pour la laisser sans attribution.',
            'editor_hg_action_view_in_wormhole' => 'Voir dans le trou de ver',
            'editor_hg_action_view_in_galaxy' => 'Voir dans la galaxie',
            'editor_hg_action_view_directly' => 'Voir dans le navigateur',
            'editor_hg_action_copy_url' => 'Copier l\'URL directe',
            'editor_hg_btn_revisions' => 'Révisions',
            'editor_hg_no_match' => 'Aucune page ne correspond à ta recherche.',
            'editor_hg_unassigned' => 'Aucune attribution',
            'editor_hg_save_failed' => 'Échec de l\'enregistrement',
            'editor_hg_confirm_replace' => 'Remplacer ? Ce trou de ver affiche déjà une page hotglue. La page qu\'il affiche maintenant ne sera plus attribuée (elle n\'est pas supprimée).',
            'editor_hg_confirm_delete' => 'Supprimer cette page hotglue ? Cela retire son contenu définitivement. Si elle est attribuée à un trou de ver, ce trou revient aux médias classiques.',
            'editor_hg_err_not_authorized' => 'Tu n\'as pas accès pour faire cela.',
            'editor_hg_err_read_only' => 'Cette galaxie est en lecture seule.',
            'editor_hg_err_generic' => 'Une erreur est survenue. Réessaie.',
            'editor_hg_in_galaxy' => 'dans %s',
            'editor_hg_name_label' => 'Nom de la page',
            'editor_hg_selected_suffix' => 'pages sélectionnées',
            'editor_hg_bulk_unassign' => 'Désattribuer la sélection',
            'editor_hg_bulk_delete' => 'Supprimer la sélection',
            'editor_hg_confirm_bulk_delete' => 'Supprimer les pages hotglue sélectionnées ? Cela retire leur contenu définitivement. Les trous de ver attribués reviennent aux médias classiques.',
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
            'editor_modal_heading_shortcuts' => 'Raccourcis clavier',
            'editor_shortcut_new_wormhole' => 'Nouveau trou de ver',
            'editor_shortcut_focus_search' => 'Mettre le focus sur la recherche',
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
            'editor_autosave_saving' => 'Enregistrement…',
            'editor_autosave_saved' => 'Toutes les modifications enregistrées',
            'editor_autosave_failed' => 'Échec de l\'enregistrement; continuez à modifier pour réessayer',
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
            'admin_label_fuzzy_keywords' => 'Correspondance approximative des mots-clés',
            'admin_help_fuzzy_keywords' => 'Lorsque activée, les vues multigalaxie relient les trous de ver dont les mots-clés nomment la même idée même quand les mots diffèrent (par exemple colonial, colonialisme et fautes de frappe). Désactivée, elle ne trace des lignes qu\'entre des correspondances exactes. Chaque amas peut remplacer ce réglage.',
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
            'admin_pluriverse_peers_heading' => 'Liste locale des instances pairs',
            'admin_pluriverse_peers_subheading' => 'Les autres instances que ce site connaît. Récupérées du Pluriverse selon un horaire régulier. Aucun contenu ne circule tant qu\'une liste blanche bilatérale n\'est pas établie avec chaque pair (étape 4+).',
            'admin_pluriverse_btn_refresh' => 'Actualiser maintenant',
            'admin_pluriverse_peers_last_ok' => 'Dernière récupération réussie :',
            'admin_pluriverse_peers_never' => 'jamais',
            'admin_pluriverse_peers_failures' => 'Échecs consécutifs :',
            'admin_pluriverse_peers_last_err' => 'Dernière erreur :',
            'admin_pluriverse_peers_empty' => 'Aucun pair connu pour le moment. Ils apparaîtront ici après la prochaine récupération du Pluriverse, ou utilise Actualiser maintenant pour récupérer immédiatement.',
            'admin_pluriverse_peers_col_label' => 'Nom',
            'admin_pluriverse_peers_col_hostname' => 'Nom d\'hôte',
            'admin_pluriverse_peers_col_source' => 'Origine',
            'admin_pluriverse_peers_col_fingerprint' => 'Empreinte',
            'admin_pluriverse_peers_col_trust_state' => 'État de confiance',
            'admin_pluriverse_peers_col_last_seen' => 'Dernière activité',
            'admin_pluriverse_peers_source_registry' => 'Pluriverse',
            'admin_pluriverse_peers_source_manual' => 'Manuel',
            'admin_pluriverse_peers_source_manual_help' => 'Non vérifié par le Pluriverse.',
            'admin_pluriverse_peers_manual_banner' => 'Pair manuel ajouté par %s le %s ; vérifier l\'intention.',
            'admin_pluriverse_refresh_ok' => 'Pluriverse actualisé :',
            'admin_pluriverse_refresh_err' => 'L\'actualisation du Pluriverse a échoué :',
            'admin_pluriverse_enforce_blocked' => 'instance(s) bloquée(s) et leurs miroirs retirés',
            'admin_peer_block_col_actions' => 'Actions',
            'admin_peer_block_btn' => 'Bloquer cette instance',
            'admin_peer_block_heading' => 'Bloquer cette instance',
            'admin_peer_block_warn' => 'Le blocage retire toutes les galaxies que tu reflètes depuis cette instance et cesse de lui offrir les tiennes. Le contenu est supprimé, pas mis en pause ; tu ne pourras pas le restaurer automatiquement ensuite, seulement te réabonner de façon délibérée. Saisis de nouveau ton mot de passe pour confirmer.',
            'admin_peer_block_field_category' => 'Catégorie',
            'admin_peer_block_cat_spam' => 'Spam ou abus',
            'admin_peer_block_cat_harmful' => 'Contenu nuisible',
            'admin_peer_block_cat_legal' => 'Légal ou retrait',
            'admin_peer_block_cat_consent' => 'Consentement retiré',
            'admin_peer_block_cat_other' => 'Autre',
            'admin_peer_block_field_reason' => 'Motif',
            'admin_peer_block_reason_ph' => 'Pourquoi tu bloques cette instance (enregistré localement)',
            'admin_peer_block_field_password' => 'Saisis de nouveau ton mot de passe',
            'admin_peer_block_confirm_btn' => 'Confirmer le blocage',
            'admin_peer_block_blocked_label' => 'Bloquée',
            'admin_peer_block_reason_shown' => 'Motif :',
            'admin_peer_block_unblock_btn' => 'Débloquer',
            'admin_peer_block_unblock_help' => 'Ramène l\'instance à l\'état découverte. Les miroirs ne sont pas restaurés.',
            'admin_peer_block_ok' => 'Instance bloquée. %d miroir(s) retiré(s) et toute offre de publication vers elle effacée.',
            'admin_peer_block_unblock_ok' => 'Instance débloquée et ramenée à l\'état découverte. Ses miroirs n\'ont pas été restaurés ; réabonne-toi de façon délibérée si tu veux de nouveau ses galaxies.',
            'admin_peer_block_err_notfound' => 'Cette instance est introuvable. Recharge la page d\'administration et réessaie.',
            'admin_peer_block_err_action' => 'Action d\'instance non reconnue.',
            'admin_peer_block_err_category' => 'Choisis une catégorie pour le blocage.',
            'admin_peer_block_err_reason' => 'Un motif est requis (jusqu\'à 1024 caractères).',
            'admin_peer_block_err_password_required' => 'Saisis de nouveau ton mot de passe pour confirmer.',
            'admin_peer_block_err_password_wrong' => 'Le mot de passe ne correspond pas à ce compte d\'administration.',
            'admin_galaxy_pull_btn_refresh' => 'Actualiser les galaxies maintenant',
            'admin_galaxy_pull_refresh_ok' => 'Actualisation des galaxies terminée :',
            'admin_galaxy_pull_refresh_err' => 'L\'actualisation des galaxies a échoué :',
            'admin_pub_section_heading' => 'Tes galaxies publiées',
            'admin_pub_section_subheading' => 'Les galaxies que tu as créées et que tu peux publier, republier, rétracter ou exporter. Les autres instances miroitent l\'enveloppe signée ; la sauvegarde fidèle ci-dessous est l\'action d\'opération, séparée de l\'enveloppe de fédération.',
            'admin_pub_col_galaxy' => 'Galaxie',
            'admin_pub_col_slug' => 'Identifiant',
            'admin_pub_col_status' => 'Statut',
            'admin_pub_col_sequence' => 'Séquence',
            'admin_pub_col_published_at' => 'Dernière publication',
            'admin_pub_col_actions' => 'Actions',
            'admin_pub_status_published' => 'Publiée',
            'admin_pub_status_not_published' => 'Non publiée',
            'admin_pub_status_retracted' => 'Rétractée',
            'admin_pub_status_stale' => 'Obsolète',
            'admin_pub_empty' => 'Pas encore de galaxies créées sur cette instance. Crée une galaxie ; elle apparaîtra ici quand elle aura un identifiant.',
            'admin_pub_btn_publish' => 'Publier maintenant',
            'admin_pub_btn_republish' => 'Republier',
            'admin_pub_btn_retract' => 'Rétracter',
            'admin_pub_btn_download_backup' => 'Télécharger la sauvegarde complète',
            'admin_pub_retract_label_slug' => 'Tape l\'identifiant pour confirmer',
            'admin_pub_retract_help' => 'La rétractation est permanente et à sens unique : l\'identifiant devient inutilisable et les instances abonnées supprimeront leur miroir au prochain cycle. Tape l\'identifiant pour confirmer.',
            'admin_pub_retract_label_reason' => 'Motif (optionnel, public)',
            'admin_pub_retract_reason_placeholder' => 'Pourquoi rétractes-tu cette galaxie ?',
            'admin_pub_retract_open' => 'Ouvrir le panneau de rétractation',
            'admin_pub_retract_warn' => 'Permanent.',
            'admin_galaxy_publish_err_missing' => 'Référence de galaxie manquante ou invalide.',
            'admin_galaxy_publish_err' => 'La publication a échoué :',
            'admin_galaxy_publish_ok' => 'Galaxie publiée :',
            'admin_galaxy_retract_err_not_found' => 'Galaxie introuvable.',
            'admin_galaxy_retract_err_confirm' => 'La confirmation tapée ne correspond pas à l\'identifiant. La rétractation n\'a pas été effectuée.',
            'admin_galaxy_retract_err' => 'La rétractation a échoué :',
            'admin_galaxy_retract_ok' => 'Galaxie rétractée :',
            'admin_galaxy_retract_already' => 'L\'identifiant était déjà rétracté ; l\'enveloppe est intacte :',
            'admin_galaxy_backup_err_not_authored' => 'Cette galaxie ne peut pas être exportée : ce n\'est pas une galaxie créée localement.',
            'admin_galaxy_backup_err' => 'La sauvegarde a échoué :',
            'admin_pub_retracted_on' => 'rétractée',
            'admin_mir_section_heading' => 'Galaxies miroitées',
            'admin_mir_section_subheading' => 'Galaxies auxquelles tu es abonné·e depuis d\'autres instances, matérialisées localement comme miroirs en lecture seule. Mises à jour à chaque cycle du cron galaxy-pull.',
            'admin_mir_empty' => 'Pas encore de galaxies miroitées. Les abonnements apparaissent ici lorsqu\'une liste d\'un accord bilatéral autorise l\'abonnement et qu\'un cycle de récupération s\'est terminé.',
            'admin_mir_col_origin' => 'Origine',
            'admin_mir_col_remote_slug' => 'Identifiant distant',
            'admin_mir_col_local' => 'Miroir local',
            'admin_mir_col_seq' => 'Séquence',
            'admin_mir_col_hash' => 'Empreinte du contenu',
            'admin_mir_col_last_sync' => 'Dernière synchronisation',
            'admin_mir_col_status' => 'Statut',
            'admin_mir_status_active' => 'Active',
            'admin_mir_status_pending' => 'En attente de la première synchronisation',
            'admin_mir_status_fossilized' => 'Fossilisée',
            'admin_mir_status_paused' => 'En pause',
            'admin_mir_node_count_suffix' => 'trous de ver',
            'admin_rmtret_section_heading' => 'Rétractations honorées',
            'admin_rmtret_section_subheading' => 'Identifiants que les instances d\'origine ont rétractés ; le miroir a été supprimé au moment de l\'honorer. L\'enveloppe signée est conservée pour que l\'événement puisse être revérifié.',
            'admin_rmtret_empty' => 'Aucune rétractation d\'origine honorée pour le moment.',
            'admin_rmtret_col_origin' => 'Origine',
            'admin_rmtret_col_slug' => 'Identifiant',
            'admin_rmtret_col_retracted_at' => 'Rétractée le',
            'admin_rmtret_col_reason' => 'Motif',
            'admin_rmtret_col_honored_at' => 'Honorée le',
            'admin_ms_section_heading' => 'Réserve de médias de fédération',
            'admin_ms_section_subheading' => 'Fichiers de médias adressés par contenu, partagés entre les miroirs. Le décompte en base de données est ce que l\'API de fédération sert ; le décompte sur disque est le stockage sous-jacent. Une différence indique qu\'un balayage de nettoyage est nécessaire.',
            'admin_ms_label_blobs_db' => 'Fichiers enregistrés',
            'admin_ms_label_blobs_disk' => 'Fichiers sur disque',
            'admin_ms_label_size_db' => 'Taille enregistrée',
            'admin_ms_label_size_disk' => 'Taille sur disque',
            'admin_ms_label_path' => 'Chemin',
            'admin_ms_drift_warn' => 'Le décompte sur disque diffère de la base ; des fichiers orphelins sont présents (balayage en attente).',
            'visitor_mirror_label' => 'Miroitée depuis',
            'visitor_mirror_view_on_origin' => 'Voir à l\'origine',
            'editor_banner_mirror_federation' => 'Cette galaxie est miroitée depuis %s et est en lecture seule. Les mises à jour arrivent par le cron galaxy-pull, ou tu peux utiliser Actualiser les galaxies maintenant dans l\'onglet Pluriverse du panneau d\'administration.',
            'admin_ms_gc_btn' => 'Nettoyer les fichiers orphelins',
            'admin_ms_gc_ok' => 'Nettoyage terminé :',
            'admin_ms_gc_blobs' => 'fichiers orphelins',
            'admin_ms_gc_rows' => 'lignes orphelines',
            'admin_ms_gc_freed' => 'libérés',
            'admin_ms_gc_protected' => 'protégés en cours',
            'admin_pluriverse_manual_disclosure' => 'Avancé : ajouter un pair manuellement',
            'admin_pluriverse_manual_warn_heading' => 'Pourquoi c\'est restreint',
            'admin_pluriverse_manual_warn_body' => 'Un pair manuel contourne la chaîne de confiance du Pluriverse : rien n\'a vérifié que ce nom d\'hôte et cette clé publique appartiennent vraiment à l\'opération que tu veux joindre. La ligne est ajoutée avec un drapeau non vérifié par le Pluriverse et une bannière persistante pour que l\'administration puisse la réviser plus tard. Saisis ton mot de passe ci-dessous pour confirmer.',
            'admin_pluriverse_manual_field_hostname' => 'Nom d\'hôte',
            'admin_pluriverse_manual_field_url' => 'URL',
            'admin_pluriverse_manual_field_label' => 'Nom',
            'admin_pluriverse_manual_field_pubkey' => 'Clé publique Ed25519 (base64url)',
            'admin_pluriverse_manual_field_pubkey_help' => 'Obtiens cette valeur hors bande auprès de l\'opération pair. C\'est la valeur de pluriverse.key.public sur l\'instance distante.',
            'admin_pluriverse_manual_field_password' => 'Saisis à nouveau ton mot de passe',
            'admin_pluriverse_manual_btn_add' => 'Ajouter un pair manuel',
            'admin_pluriverse_manual_added' => 'Pair manuel %s ajouté. Considère-le comme non vérifié par le Pluriverse jusqu\'à ce que tu le confirmes hors bande avec l\'autre opération.',
            'admin_pluriverse_manual_err_hostname' => 'Le nom d\'hôte doit être un DNS en minuscules (par exemple, example.org).',
            'admin_pluriverse_manual_err_url' => 'L\'URL doit commencer par https://.',
            'admin_pluriverse_manual_err_label' => 'Le nom est obligatoire (1-255 caractères).',
            'admin_pluriverse_manual_err_pubkey' => 'La clé publique doit être une clé Ed25519 de 32 octets encodée en base64url.',
            'admin_pluriverse_manual_err_password_required' => 'Saisis à nouveau ton mot de passe pour confirmer.',
            'admin_pluriverse_manual_err_password_wrong' => 'Le mot de passe ne correspond pas à ce compte d\'administration.',
            'admin_pluriverse_manual_err_duplicate' => 'Un pair pour le nom d\'hôte %s existe déjà (origine : %s).',
            'admin_msg_csrf_invalid' => 'Jeton de sécurité invalide ou expiré. Recharge la page d\'administration et réessaie.',
            // Stage 4e : panneau des poignées de main en attente.
            'admin_handshake_section_heading' => 'Poignées de main en attente',
            'admin_handshake_section_subheading' => 'Poignées de main de fédération en cours (trois tours). Les demandes entrantes arrivent via le relais du Pluriverse ; les sortantes sont distribuées au prochain tour du cron pluriverse-dispatch.',
            'admin_handshake_empty' => 'Aucune poignée de main pour le moment.',
            'admin_handshake_inbound_heading' => 'Entrantes — en attente de ta décision',
            'admin_handshake_outbound_heading' => 'Sortantes — en attente de la réponse de l\'autre instance',
            'admin_handshake_history_heading' => 'Historique récent (poignées terminales, fenêtre de 30 jours)',
            'admin_handshake_th_sender' => 'Expéditeur',
            'admin_handshake_th_remote' => 'Distant',
            'admin_handshake_th_received' => 'Reçu',
            'admin_handshake_th_request_excerpt' => 'Corps du message (extrait)',
            'admin_handshake_th_expires' => 'Expire',
            'admin_handshake_th_state' => 'État',
            'admin_handshake_th_delivery' => 'Livraison',
            'admin_handshake_th_direction' => 'Direction',
            'admin_handshake_th_updated' => 'Mis à jour',
            'admin_handshake_th_reason' => 'Motif',
            'admin_handshake_actions' => 'Actions',
            'admin_handshake_btn_accept' => 'Accepter',
            'admin_handshake_btn_reject' => 'Refuser',
            'admin_handshake_btn_reject_confirm' => 'Confirmer le refus',
            'admin_handshake_btn_cancel' => 'Annuler',
            'admin_handshake_reject_prompt' => 'Motif (facultatif)',
            'admin_handshake_confirm_cancel' => 'Annuler cette poignée de main sortante ?',
            'admin_handshake_state_pending_their_response' => 'En attente de leur réponse',
            'admin_handshake_state_pending_our_response' => 'En attente de ta décision',
            'admin_handshake_state_accepted_awaiting_complete' => 'Acceptée, en attente de la confirmation finale',
            'admin_handshake_state_complete' => 'Complète',
            'admin_handshake_state_rejected' => 'Refusée',
            'admin_handshake_state_expired' => 'Expirée',
            'admin_handshake_state_cancelled' => 'Annulée',
            'admin_handshake_initiator_us' => 'Initiée ici',
            'admin_handshake_initiator_them' => 'Initiée par l\'autre instance',
            'admin_handshake_delivery_not_applicable' => 'sans objet',
            'admin_handshake_delivery_pending' => 'En file',
            'admin_handshake_delivery_delivered' => 'Livré',
            'admin_handshake_delivery_failed' => 'Échec, nouvel essai',
            'admin_handshake_delivery_given_up' => 'Abandonné',
            'admin_handshake_delivery_unknown' => 'inconnu',
            'admin_handshake_attempts_n' => '%d tentatives',
            'admin_handshake_compose_btn_show' => 'Initier une poignée de main…',
            'admin_handshake_compose_subheading' => 'Envoie une demande signée de poignée de main via le relais du Pluriverse. L\'instance distante reçoit un courriel et voit la demande dans son propre panneau.',
            'admin_handshake_compose_field_recipient' => 'Nom d\'hôte du destinataire',
            'admin_handshake_compose_field_recipient_help' => 'Nom d\'hôte (sans schéma) d\'une instance publiée dans le Pluriverse.',
            'admin_handshake_compose_field_subject' => 'Sujet (facultatif)',
            'admin_handshake_compose_field_body' => 'Corps du message (markdown)',
            'admin_handshake_compose_field_body_help' => 'Visible pour l\'instance distante après connexion. Le corps est analysé pour des motifs de secrets de haute confiance ; vois l\'option de contournement plus bas.',
            'admin_handshake_compose_field_pub_galaxies' => 'Galaxies que tu offres de publier vers cette instance',
            'admin_handshake_compose_field_pub_help' => 'Slugs séparés par des virgules de tes galaxies autorales. Facultatif.',
            'admin_handshake_compose_field_sub_galaxies' => 'Galaxies que tu veux suivre depuis cette instance',
            'admin_handshake_compose_field_sub_help' => 'Slugs séparés par des virgules des galaxies autorales de cette instance. Facultatif.',
            'admin_handshake_compose_send_anyway' => 'Envoyer quand même si le corps semble contenir un secret',
            'admin_handshake_compose_btn_send' => 'Mettre en file la demande de poignée de main',
            'admin_handshake_accept_ok' => 'Poignée de main acceptée ; la réponse est en file pour le prochain tour du distributeur.',
            'admin_handshake_accept_err' => 'Impossible d\'accepter la poignée de main :',
            'admin_handshake_reject_ok' => 'Poignée de main refusée ; l\'autre instance sera notifiée au prochain tour du distributeur.',
            'admin_handshake_reject_err' => 'Impossible de refuser la poignée de main :',
            'admin_handshake_cancel_ok' => 'Poignée de main annulée. Tout message sortant en file a été abandonné ; l\'autre instance n\'est pas notifiée.',
            'admin_handshake_cancel_err' => 'Impossible d\'annuler la poignée de main :',
            'admin_handshake_initiate_ok' => 'Demande de poignée de main mise en file. La livraison au relais du Pluriverse arrive au prochain tour du distributeur.',
            'admin_handshake_initiate_err' => 'Impossible de mettre en file la demande de poignée de main :',
            'admin_handshake_default_reject_reason' => 'Aucun motif fourni.',
            'admin_handshake_err_missing_id' => 'Identifiant de poignée de main manquant.',
            'admin_handshake_err_peer_not_in_directory' => 'L\'instance distante n\'est pas encore dans le répertoire du Pluriverse. Attends la prochaine obtention de pairs (ou clique sur Actualiser maintenant) et réessaie.',
            'admin_handshake_err_invalid_recipient' => 'Le nom d\'hôte du destinataire est absent ou mal formé.',
            'admin_handshake_err_body_required' => 'Une demande de poignée de main nécessite un corps de message.',
            'admin_handshake_err_sensitive_info' => 'Ton message contient du contenu qui ressemble à un secret (%s). Modifie-le et réessaie, ou coche « Envoyer quand même » pour contourner.',
            'admin_handshake_err_active_exists' => 'Une poignée de main active vers cet hôte est déjà en cours ; annule-la avant d\'en initier une autre.',
            'admin_whitelist_section_heading' => 'Listes de publication et d\'abonnement par pair',
            'admin_whitelist_section_subheading' => 'Lesquelles de tes galaxies tu publierais à chaque pair, et lesquelles des leurs tu veux abonner. Prend effet après une poignée de main réussie ; tu peux précharger l\'intention avant.',
            'admin_whitelist_no_peers' => 'Aucun pair pour le moment. Les listes deviennent modifiables dès que des pairs apparaissent dans la Liste locale des pairs.',
            'admin_whitelist_no_authored' => 'Aucune galaxie créée localement pour le moment.',
            'admin_whitelist_no_subscriptions' => 'Aucun abonnement pour le moment.',
            'admin_whitelist_trust_state_label' => 'Confiance :',
            'admin_whitelist_count_publish' => 'publier',
            'admin_whitelist_count_subscribe' => 'abonner',
            'admin_whitelist_hint_post_handshake' => 'Aucune poignée de main n\'est encore terminée avec ce pair ; la liste prendra effet quand ce sera fait.',
            'admin_whitelist_publish_heading' => 'Galaxies que nous publions vers ce pair',
            'admin_whitelist_publish_help' => 'Seules les galaxies créées localement apparaissent ici. Les galaxies miroir ne peuvent pas être republiées.',
            'admin_whitelist_publish_save_btn' => 'Enregistrer la liste de publication',
            'admin_whitelist_subscribe_heading' => 'Galaxies auxquelles nous nous abonnons chez ce pair',
            'admin_whitelist_subscribe_help' => 'Ajoute le slug d\'une galaxie distante pour t\'abonner. Une sélection multiple arrivera quand le point d\'accès des galaxies publiées sera en place.',
            'admin_whitelist_subscribe_th_slug' => 'Slug distant',
            'admin_whitelist_subscribe_th_last_sync' => 'Dernière sync.',
            'admin_whitelist_subscribe_th_actions' => 'Actions',
            'admin_whitelist_subscribe_field_slug' => 'Slug distant',
            'admin_whitelist_subscribe_btn_add' => 'Ajouter un abonnement',
            'admin_whitelist_subscribe_btn_remove' => 'Retirer',
            'admin_whitelist_subscribe_confirm_remove' => 'Retirer cet abonnement ?',
            'admin_whitelist_publish_save_ok' => 'Liste de publication enregistrée (%1$d ajoutées, %2$d retirées).',
            'admin_whitelist_publish_save_err' => 'Impossible d\'enregistrer la liste de publication.',
            'admin_whitelist_subscription_add_ok' => 'Abonnement ajouté.',
            'admin_whitelist_subscription_add_exists' => 'Cet abonnement est déjà actif ; rien n\'a changé.',
            'admin_whitelist_subscription_add_err' => 'Impossible d\'ajouter l\'abonnement.',
            'admin_whitelist_subscription_remove_ok' => 'Abonnement retiré.',
            'admin_whitelist_subscription_remove_err' => 'Impossible de retirer l\'abonnement.',
            'admin_whitelist_err_missing_peer' => 'Identifiant de pair manquant.',
            'admin_whitelist_err_unknown_peer' => 'Ce pair n\'existe plus.',
            'admin_whitelist_err_mirrored' => 'Impossible de republier une galaxie miroir ; seules les galaxies créées localement sont autorisées.',
            'admin_whitelist_err_invalid_slug' => 'Le slug distant est vide ou trop long.',
            'admin_whitelist_err_unknown_subscription' => 'Cet abonnement n\'existe plus.',
            'admin_whitelist_err_peer_mismatch' => 'Cet abonnement appartient à un autre pair.',
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
            'admin_msg_no_galaxies_search' => 'Aucune galaxie ne correspond à ta recherche.',
            'admin_msg_galaxies_empty' => 'Il n\'y a pas encore de galaxies. Tu peux %s.',
            'admin_link_create_galaxy' => 'créer une nouvelle galaxie',
            'admin_msg_clusters_empty' => 'Il n\'y a pas encore d\'amas. Tu peux %s.',
            'admin_link_create_cluster' => 'créer un nouvel amas',
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
            'admin_action_fractal_profile' => 'Forme de la galaxie',
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
            'admin_modal_label_last_name' => 'Nom de famille',
            'admin_modal_help_last_name' => 'Le nom de famille associé au compte. Facultatif.',
            'admin_modal_label_pronouns' => 'Pronoms',
            'admin_modal_help_pronouns' => 'Facultatif. Choisis jusqu\'à 3 ou ajoute les tiens. Tu peux laisser vide.',
            'admin_modal_label_pronouns_custom' => 'Ajoute les tiens',
            'admin_modal_placeholder_pronouns_custom' => 'séparés par des virgules, p. ex. iel',
            'pronoun_common_set' => 'iel,elle,il',
            'pronouns_error_too_many' => 'Choisis au maximum 3 ensembles de pronoms.',
            'pronouns_error_too_long' => 'Chaque pronom doit faire 30 caractères ou moins.',
            'pronouns_error_charset' => 'Les pronoms n\'acceptent que les lettres, les espaces et les signes / - et l\'apostrophe.',
            'pronouns_error_denylist' => 'Cette entrée ne peut pas servir de pronom.',
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
            'admin_modal_label_cluster_fuzzy' => 'Correspondance approximative des mots-clés',
            'admin_modal_help_cluster_fuzzy' => 'Relie les trous de ver dont les mots-clés nomment la même idée même quand les mots diffèrent (colonial, colonialisme, fautes de frappe). Hériter suit le réglage par défaut de l\'installation ; Activée ou Désactivée le remplace pour cet amas uniquement.',
            'admin_modal_fuzzy_inherit' => 'Utiliser le réglage par défaut de l\'installation',
            'admin_modal_fuzzy_on' => 'Activée pour cet amas',
            'admin_modal_fuzzy_off' => 'Désactivée pour cet amas',
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
            'admin_setup_last_name_label' => 'Nom de famille',
            'admin_setup_pronouns_label' => 'Pronoms',
            'admin_setup_pronouns_help' => 'Facultatif. Choisis jusqu\'à 3 ou ajoute les tiens. Tu peux laisser vide.',
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
            'loginlink_link_label' => 'Pas de mot de passe ? Envoyez-moi un lien de connexion',
            'loginlink_expired_error' => 'Ce lien de connexion est invalide ou a expiré. Demandez-en un nouveau ci-dessous.',
            'loginlink_page_title' => 'Envoyer un lien de connexion - Telaris',
            'loginlink_heading' => 'Envoyez-moi un lien de connexion',
            'loginlink_subtitle' => 'Nous vous enverrons par courriel un lien à usage unique pour vous connecter sans mot de passe.',
            'loginlink_generic_notice' => 'S\'il existe un compte avec ce courriel, un lien de connexion a été envoyé.',
            'loginlink_submit' => 'Envoyer le lien de connexion',
            'auth_login_emaillink_button' => 'Envoie-moi un lien de connexion',
            'auth_login_have_password' => 'J\'ai un mot de passe',
            'enroll_menu_link' => 'Devenir éditeur',
            'enroll_page_title' => 'Devenir éditeur - Telaris',
            'enroll_heading' => 'Devenir éditeur',
            'enroll_intro' => "Rejoins cette instance Telaris comme éditeur. Indique ton nom et ton courriel, accepte les Conditions d'utilisation et la Politique de confidentialité, et nous t'enverrons un lien de confirmation.",
            'enroll_name_label' => 'Ton nom',
            'enroll_email_label' => 'Courriel',
            'enroll_submit' => "Demander l'accès",
            'enroll_check_email_notice' => 'Vérifie ton courriel. Si ton adresse peut rejoindre, le lien de confirmation est en route. Le lien expire dans 24 heures.',
            'enroll_domain_rejected' => "Sur cette instance, devenir éditeur est limité à certains domaines de courriel, et cette adresse n'en fait pas partie.",
            'enroll_disabled_notice' => "L'inscription des éditeurs n'est pas ouverte sur cette instance pour le moment.",
            'enroll_full_notice' => "L'inscription des éditeurs est complète sur cette instance pour le moment. Réessaie plus tard.",
            'enroll_confirm_invalid' => 'Ce lien de confirmation est invalide ou a expiré. Tu peux redemander à rejoindre.',
            'enroll_galaxy_name_possessive' => 'Galaxie de %s',
            'enroll_pending_galaxy_banner' => 'Bienvenue. Quand tu seras prêt, crée ta première galaxie pour commencer à ajouter des trous de ver.',
            'enroll_name_required' => 'Indique ton nom.',
            'admin_btn_auto_enroll' => 'Auto-inscription',
            'admin_badge_unvetted' => 'Non vérifié',
            'admin_unvetted_title' => "Inscrit de lui-même ; pas encore vérifié par un administrateur",
            'admin_modal_label_vetted' => 'Vérifié',
            'admin_modal_help_vetted' => "Vérifier un éditeur inscrit de lui-même lui envoie un lien pour définir un mot de passe et lui affiche un avis dans l'application. Cela ne change pas ce qu'il peut modifier. Sans vérification, il se connecte avec un lien par courriel à chaque fois.",
            'auto_enroll_saved' => "Paramètres d'auto-inscription enregistrés.",
            'admin_auto_enroll_heading' => 'Auto-inscription des éditeurs',
            'admin_auto_enroll_intro' => "Laisse les gens rejoindre cette instance comme éditeurs d'eux-mêmes. Désactivé par défaut. Tu gardes le contrôle : les inscrits de cette façon sont marqués Non vérifié jusqu'à ce que tu les vérifies, et n'éditent que les galaxies que tu accordes.",
            'admin_auto_enroll_enable' => "Activer l'auto-inscription sur cette installation",
            'admin_auto_enroll_enable_warning' => "Avec ceci activé, toute personne ayant un courriel valide (selon la limite de domaines et le plafond ci-dessous) peut rejoindre comme Éditeur. Elle n'édite que les galaxies que tu accordes et reste Non vérifiée jusqu'à ce que tu la vérifies. Activer l'auto-inscription ?",
            'admin_auto_enroll_create_galaxy' => 'Créer une galaxie personnelle pour chaque nouvel éditeur',
            'admin_auto_enroll_naming_label' => 'Convention de nom de la nouvelle galaxie',
            'admin_auto_enroll_naming_email_username' => "Identifiant du courriel seulement (camille)",
            'admin_auto_enroll_naming_full_email' => 'Courriel complet (camille@example.com)',
            'admin_auto_enroll_naming_first_name' => 'La galaxie de son prénom',
            'admin_auto_enroll_naming_full_name' => 'Nom complet (camille-roy)',
            'admin_auto_enroll_naming_user_choice' => "Laisser choisir à la première connexion",
            'admin_auto_enroll_naming_privacy_note' => "Les noms de galaxie sont affichés publiquement dans la vue 3D et dans l'URL de la page. Les options de courriel exposent l'adresse de la personne qui édite; privilégie le prénom ou le choix par la personne.",
            'admin_auto_enroll_galaxies_label' => 'Accorder l\'accès à ces galaxies',
            'admin_auto_enroll_select_all' => 'Toutes',
            'admin_auto_enroll_select_none' => 'Aucune',
            'admin_auto_enroll_group_hint' => 'Astuce : clique sur un [PRÉFIXE] pour basculer ce groupe.',
            'admin_auto_enroll_access_rw' => 'Lecture et écriture',
            'admin_auto_enroll_access_ro' => 'Lecture seule',
            'admin_auto_enroll_domains_label' => 'Limiter à des domaines de courriel (facultatif)',
            'admin_auto_enroll_domains_ph' => 'p. ex. ubc.ca, gmail.com (vide = tous)',
            'admin_auto_enroll_cap_label' => "Plafonner le nombre d'éditeurs auto-inscrits",
            'admin_auto_enroll_cap_count' => "Actuellement %d éditeur(s) auto-inscrit(s).",
            'admin_auto_enroll_save' => 'Enregistrer les paramètres',
            'editor_vetted_banner' => "Un administrateur a vérifié ton compte. Tu peux définir un mot de passe depuis le lien envoyé par courriel, pour te connecter plus vite. Le lien par courriel continue de fonctionner.",
            'admin_delete_personal_galaxy' => "Supprimer aussi les %d galaxie(s) personnelle(s) de cette personne (créées par elle) et leurs trous de ver. Les galaxies partagées ne sont pas affectées.",
            'auth_email_subject' => 'Réinitialise ton mot de passe %s',
            'auth_email_greeting_named' => 'Bonjour %s,',
            'auth_email_greeting_anon' => 'Bonjour,',
            'auth_email_intro' => 'Nous avons reçu une demande de réinitialisation de mot de passe. Clique sur le lien pour en définir un nouveau :',
            'auth_email_cta' => 'Réinitialiser le mot de passe',
            'auth_email_expiry' => 'Le lien expire dans 24 heures et ne peut être utilisé qu\'une seule fois. Si tu n\'as pas demandé la réinitialisation, tu peux ignorer ce courriel ; ton mot de passe ne changera pas.',
            'auth_email_text_intro' => "Nous avons reçu une demande de réinitialisation de mot de passe.\n\nLien de réinitialisation (24h, usage unique) :\n",
            'auth_email_text_outro' => "\n\nSi tu n\'as pas demandé la réinitialisation, ignore ce courriel.",
            'email_drop_subject' => 'Galaxies fédérées supprimées',
            'email_drop_intro' => 'Une ou plusieurs galaxies fédérées que cette instance reflétait ont été supprimées :',
            'email_drop_item' => '%1$s (reflétée depuis %2$s)',
            'email_drop_reason_label' => 'Raison : %s',
            'email_drop_reason_retraction' => "l'instance d'origine a retiré la galaxie",
            'email_drop_reason_blacklist' => "l'instance d'origine a été bloquée sur le Pluriverse",
            'email_drop_reason_revoked' => "l'adhésion à la fédération de l'instance d'origine a été révoquée",
            'email_drop_reason_local' => "tu as bloqué l'instance d'origine",
            'email_drop_reason_publish_revoked' => "l'instance d'origine a révoqué votre accès à la galaxie",
            'email_drop_outro' => "Le contenu reflété a été supprimé de cette instance. C'est le comportement attendu lorsque la confiance est retirée ou qu'une galaxie est retirée ; aucune action n'est nécessaire.",
            'admin_user_locale_label' => 'Langue des notifications',
            'admin_user_locale_unset' => 'Non définie (toutes les langues)',
            'admin_user_locale_saved' => 'Langue des notifications mise à jour.',
            'admin_user_pw_btn' => 'Mettre à jour le mot de passe',
            'admin_user_pw_too_short' => 'Le mot de passe doit comporter au moins 8 caractères.',
            'admin_user_pw_updated' => 'Mot de passe mis à jour.',
            'admin_user_locale_invalid' => 'Langue non prise en charge.',
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
            'gem_theme_light_rainbow' => 'Arc-en-ciel clair (fond clair, formes arc-en-ciel)',
            'gem_theme_rhizome' => 'Rhizome (clair, carte des connexions)',
            'gem_theme_cornrow' => 'Tresse (tissage fractal, d&#039;après Eglash)',
            'gem_theme_adire' => 'Adire (treillis fractal, d&#039;après Eglash)',
            'theme_credit_cornrow' => "Substrat fractal : géométrie du tressage cornrow. D'après Ron Eglash, African Fractals (1999).",
            'theme_credit_adire' => "Substrat fractal : motifs de réserve indigo Adire yoruba. D'après Ron Eglash, African Fractals (1999).",
            'rhizome_back' => 'Retour à la vue générale',
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
            'gem_group_nodes_label' => 'Regrouper les trous de ver',
            'gem_group_nodes_help' => 'Quand une galaxie compte beaucoup de trous de ver, les regrouper en ensembles navigables au lieu de tous les afficher d\'un coup. Activé par défaut. Désactive-le pour toujours afficher tous les trous de ver, quel qu\'en soit le nombre.',
            'gem_heavy_inertia_label' => 'Mouvement lourd',
            'gem_heavy_inertia_help' => 'Donne à cette galaxie une sensation de poids et de forte inertie : la rotation et le zoom sont plus lents et la vue continue de glisser après le relâchement, pour qu\'une galaxie dense semble massive. Désactivé par défaut.',
            'gem_fractal_title' => 'Comment cette galaxie est formée',
            'gem_fractal_subtitle' => 'Profil fractal · lecture seule',
            'gem_fractal_intro' => 'Un aperçu rapide de la façon dont les trous de ver de cette galaxie se relient entre eux par des mots-clés partagés.',
            'gem_fractal_loading' => 'Lecture de la galaxie…',
            'gem_fractal_details_toggle' => 'Voir les mesures',
            'gem_fractal_fit_label' => "qualité de l'ajustement",
            'gem_fractal_dB_label' => 'Dimension fractale (d_B)',
            'gem_fractal_width_label' => 'Irrégularité (largeur du spectre)',
            'gem_fractal_spectrum_label' => 'Texture des connexions, f(α)',
            'gem_fractal_gen_dims_label' => 'Dimensions généralisées (D0/D1/D2)',
            'gem_fractal_gamma_label' => 'Prédominance des pôles (exposant de degré γ)',
            'gem_fractal_stat_nodes' => 'Trous de ver',
            'gem_fractal_stat_edges' => 'Connexions',
            'gem_fractal_stat_meandeg' => 'Liens moy.',
            'gem_fractal_stat_components' => 'Morceaux reliés',
            'gem_fractal_stat_diameter' => "Pas d'un bout à l'autre",
            'gem_fractal_dB_low' => "Les trous de ver forment une chaîne : la plupart des chemins passent par quelques mots-clés centraux.",
            'gem_fractal_dB_mid' => 'Les trous de ver forment une toile étendue, avec de nombreux chemins indépendants entre eux.',
            'gem_fractal_dB_high' => 'Les trous de ver forment un groupe compact : presque tout se trouve à un ou deux pas du reste.',
            'gem_fractal_width_narrow' => 'Le maillage par mots-clés est assez régulier dans toute la galaxie.',
            'gem_fractal_width_wide' => "Le maillage par mots-clés est irrégulier : certaines parties sont très connectées, d'autres peu.",
            'gem_fractal_reason_empty' => "Cette galaxie n'a pas encore de trous de ver.",
            'gem_fractal_reason_too_small' => 'Trop peu de trous de ver connectés pour lire une forme pour le moment.',
            'gem_fractal_reason_too_shallow' => "Cette galaxie est petite et très maillée, il n'y a donc pas de forme claire à lire : presque chaque trou de ver est à un ou deux pas des autres.",
            'gem_fractal_reason_too_large' => 'Cette galaxie est trop grande pour être lue sur le moment.',
            'gem_fractal_reason_cluster' => 'Ceci se lit une galaxie à la fois. Ouvre une galaxie membre pour voir sa forme.',
            'gem_fractal_error' => 'Impossible de lire cette galaxie.',
            'gem_sound_theme_label' => 'Thème sonore',
            'gem_sound_theme_default' => 'Par défaut (ambiant)',
            'gem_sound_theme_rhizome' => 'Rhizome (glitch, aigu)',
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
            'api_error_403_009' => 'Cette galaxie est en lecture seule : elle est importée ou en miroir depuis une autre instance et ne peut pas être modifiée ici.',
            'api_error_403_010' => 'Vous avez un accès en lecture seule à cette galaxie. Vous pouvez voir son contenu, mais pas le modifier.',
            'api_error_403_011' => 'La modification est désactivée sur cette installation pour le moment.',
            'api_error_403_012' => 'La modification est désactivée pour cet amas.',
            'api_error_403_013' => 'La modification est désactivée pour cette galaxie.',
            'api_error_403_014' => 'Ton compte de modification est désactivé. La modification est coupée.',
            'auth_editors_disabled_notice' => 'La modification est désactivée ici pour le moment. Si tu penses que c\'est une erreur, contacte la personne qui administre l\'installation.',
            'admin_label_editors_enabled' => 'Autoriser la modification',
            'admin_help_editors_enabled' => 'Désactivé, les personnes qui modifient ne peuvent ni se connecter ni faire de changements sur toute l\'installation. Les comptes et le contenu sont conservés ; l\'administration n\'est pas affectée.',
            'admin_label_cluster_editors_enabled' => 'Autoriser la modification',
            'admin_help_cluster_editors_enabled' => 'Désactivé, aucune galaxie de cet amas ne peut être modifiée. L\'administration n\'est pas affectée.',
            'admin_label_galaxy_editors_enabled' => 'Autoriser la modification',
            'admin_help_galaxy_editors_enabled' => 'Désactivé, cette galaxie ne peut pas être modifiée. L\'administration n\'est pas affectée.',
            'admin_label_user_editor_enabled' => 'Modification activée',
            'admin_help_user_editor_enabled' => 'Désactivé, cette personne ne peut ni se connecter ni faire de changements. Son compte et ses galaxies sont conservés.',
            'admin_settings_site_heading' => 'Site',
            'admin_label_site_hostname' => 'Nom d\'hôte public',
            'admin_help_site_hostname' => 'Nom d\'hôte canonique de cette instance (sans protocole ni barre oblique finale). Sert à construire les liens dans le courriel sortant et comme hôte d\'identité de fédération. Laisse vide pour utiliser la valeur de config.php.',
            'admin_label_site_base_url' => 'URL de base (remplacement facultatif)',
            'admin_help_site_base_url' => 'URL de base complète avec le protocole, utilisée à la place du nom d\'hôte quand elle est définie. Laisse vide sauf si cette instance est servie avec un protocole ou un chemin non standard.',
            'admin_label_default_locale' => 'Langue par défaut',
            'admin_help_default_locale' => 'Langue présentée à qui visite quand son navigateur ne demande aucune langue que Telaris parle. Automatique se rabat sur la première langue disponible. Un choix explicite dans la barre d\'adresse l\'emporte toujours.',
            'admin_default_locale_automatic' => 'Automatique (préférence du navigateur)',
            'admin_settings_mail_heading' => 'Courriel (SMTP)',
            'admin_settings_mail_intro' => 'Nécessaire pour les liens de connexion, les confirmations d\'inscription et les réinitialisations de mot de passe. Quand c\'est vide, ces courriels ne partent pas sans prévenir.',
            'admin_mail_not_configured' => 'Le courriel n\'est pas configuré. Aucun courriel transactionnel ne sera envoyé tant que les réglages SMTP ci-dessous ne sont pas complets.',
            'admin_mail_configured' => 'Le courriel est configuré. Utilise le bouton de test ci-dessous pour confirmer la livraison.',
            'admin_label_mail_host' => 'Hôte SMTP',
            'admin_label_mail_port' => 'Port',
            'admin_label_mail_user' => 'Utilisateur',
            'admin_label_mail_pass' => 'Mot de passe',
            'admin_help_mail_pass' => 'Laisse vide pour conserver le mot de passe enregistré.',
            'admin_mail_pass_set' => '(inchangé)',
            'admin_label_mail_from_address' => 'Adresse d\'expéditeur',
            'admin_label_mail_from_name' => 'Nom d\'expéditeur',
            'admin_label_mail_secure' => 'Chiffrement',
            'admin_mail_secure_tls' => 'STARTTLS (587)',
            'admin_mail_secure_ssl' => 'SSL (465)',
            'admin_mail_secure_none' => 'Aucun (non recommandé)',
            'admin_btn_send_test_email' => 'Envoyer un courriel de test',
            'admin_help_send_test_email' => 'Envoie un message de test à ton adresse de courriel d\'administration.',
            'admin_msg_mailtest_ok' => 'Courriel de test envoyé. Vérifie ta boîte de réception pour confirmer la livraison.',
            'admin_msg_mailtest_unconfigured' => 'Le courriel n\'est pas configuré. Remplis les réglages SMTP ci-dessous et enregistre avant d\'envoyer un test.',
            'admin_msg_mailtest_noaddr' => 'Ton compte d\'administration n\'a aucune adresse de courriel enregistrée, il n\'y a donc nulle part où envoyer le test.',
            'admin_msg_mailtest_fail' => 'Le courriel de test n\'a pas pu être envoyé. Vérifie les réglages SMTP et le journal de courriel du serveur.',
            'admin_auto_enroll_mail_warning' => 'Le courriel n\'est pas configuré sur cette instance, donc les liens de confirmation d\'inscription ne peuvent pas être envoyés et l\'auto-inscription ne fonctionnera pas. Configure le courriel dans les Réglages globaux d\'abord.',

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

            'api_error_413_001' => 'Quota de stockage atteint : retirez du contenu existant avant d\'en téléverser davantage.',

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
            'galaxy_update_read_only' => 'Vous avez un accès en lecture seule à cette galaxie. Vous pouvez la voir, mais pas la modifier.',
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
            'viewer_maximize_text' => 'Agrandir',
            'viewer_restore_text' => 'Rétablir',
            'viewer_close_text' => 'Fermer',
            'viewer_open_hotglue_newtab_text' => 'Voir le contenu en plein écran',
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
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS show_keywords BOOLEAN NOT NULL DEFAULT FALSE");
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
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS use_image_as_node BOOLEAN NOT NULL DEFAULT FALSE");
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_use_image_as_node_column: ' . $e->getMessage());
    }
}

/**
 * Ensure nodes.media_mode + nodes.hotglue_page columns exist (hotglue media
 * integration). media_mode is 'classic' (the existing image/audio/embed/pdf
 * media block) or 'hotglue' (an embedded per-node hotglue page). hotglue_page
 * holds the hotglue page base name when it differs from the default node-<id>
 * (e.g. an imported self-hosted page); NULL means use the default.
 */
function db_ensure_nodes_hotglue_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS media_mode VARCHAR(16) NOT NULL DEFAULT 'classic'");
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS hotglue_page VARCHAR(255) NULL DEFAULT NULL");
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_hotglue_columns: ' . $e->getMessage());
    }
}

/**
 * Ensure the hotglue_pages registry table exists. A hotglue page is a freeform
 * hotglue canvas with its own identity, independent of any wormhole. slug is the
 * on-disk content-dir name under hg/content/ ("page-<id>" for editor-created
 * pages, "node-<id>" for the legacy per-wormhole pages backfilled by the
 * migration). owner_user_id is the editor who created it (NULL for migrated
 * rows). node_id links the page to a wormhole when assigned (NULL = unassigned,
 * the page still exists). Deleting a wormhole sets node_id back to NULL so the
 * page survives unassigned.
 */
function db_ensure_hotglue_pages_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS hotglue_pages (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                slug VARCHAR(255) NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL DEFAULT '',
                owner_user_id VARCHAR(255) NULL DEFAULT NULL,
                node_id INT NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_hotglue_pages_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL
            );
            CREATE INDEX IF NOT EXISTS idx_hotglue_pages_owner ON hotglue_pages (owner_user_id);
            CREATE INDEX IF NOT EXISTS idx_hotglue_pages_node ON hotglue_pages (node_id);
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_hotglue_pages_table: ' . $e->getMessage());
    }
}

function db_ensure_templates_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS templates (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                name VARCHAR(255) NOT NULL DEFAULT '',
                owner_user_id VARCHAR(255) NULL DEFAULT NULL,
                data JSONB NOT NULL DEFAULT '{}',
                has_hotglue BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_templates_owner ON templates (owner_user_id);
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_templates_table: ' . $e->getMessage());
    }
}

function db_ensure_constellations_import_source_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS import_source VARCHAR(500) NULL DEFAULT NULL");
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
        $pdo->exec("
            ALTER TABLE constellations
                ADD COLUMN IF NOT EXISTS tour_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN IF NOT EXISTS tour_start_mode VARCHAR(16) NOT NULL DEFAULT 'manual' CHECK (tour_start_mode IN ('immediate','idle','manual')),
                ADD COLUMN IF NOT EXISTS tour_idle_seconds INT NOT NULL DEFAULT 30,
                ADD COLUMN IF NOT EXISTS tour_node_selection VARCHAR(16) NOT NULL DEFAULT 'all' CHECK (tour_node_selection IN ('all','accentuated','random_n','tagged')),
                ADD COLUMN IF NOT EXISTS tour_random_count INT NOT NULL DEFAULT 10,
                ADD COLUMN IF NOT EXISTS tour_default_dwell INT NOT NULL DEFAULT 8,
                ADD COLUMN IF NOT EXISTS tour_loop BOOLEAN NOT NULL DEFAULT TRUE
        ");
        // keyword_chips_enabled was added later; check separately so older instances pick it up.
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS keyword_chips_enabled BOOLEAN NOT NULL DEFAULT FALSE");
        // idle_spotlight_* added later; check separately.
        $pdo->exec("
            ALTER TABLE constellations
                ADD COLUMN IF NOT EXISTS idle_spotlight_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN IF NOT EXISTS idle_spotlight_selection VARCHAR(16) NOT NULL DEFAULT 'all' CHECK (idle_spotlight_selection IN ('all','accentuated')),
                ADD COLUMN IF NOT EXISTS idle_spotlight_idle_seconds INT NOT NULL DEFAULT 30
        ");
        // related_nodes_enabled added later; check separately.
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS related_nodes_enabled BOOLEAN NOT NULL DEFAULT FALSE");
        // show_2d_view: opt-in per galaxy / cluster. When TRUE, the visitor
        // view shows a top-center "3D / 2D" segmented switch (and remembers
        // the visitor's choice in localStorage).
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS show_2d_view BOOLEAN NOT NULL DEFAULT FALSE");
        // group_nodes: when TRUE (default), large galaxies auto-cluster their
        // wormholes into navigable groups (inc/clustering.php). Set FALSE to always
        // show every wormhole flat, no matter how many.
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS group_nodes BOOLEAN NOT NULL DEFAULT TRUE");
        // heavy_inertia: opt-in per galaxy. When TRUE, the 3D controls get a
        // deliberately heavy feel (slow, weighty rotate + zoom, long coast) so a
        // dense galaxy reads as massive. FALSE (default) keeps the normal feel.
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS heavy_inertia BOOLEAN NOT NULL DEFAULT FALSE");
        // sound_theme: independent per-galaxy audio preset (js/telaris-soundscape.js
        // SOUND_PRESETS), separate from the visual `theme` column. 'default' = the
        // original soundscape; 'rhizome' = a glitchy, high-pitched, noise-forward one.
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS sound_theme VARCHAR(20) NOT NULL DEFAULT 'default'");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS constellation_tour_keywords (
                constellation_id INT NOT NULL,
                keyword_id INT NOT NULL,
                PRIMARY KEY (constellation_id, keyword_id),
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE,
                FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_ctk_constellation_id ON constellation_tour_keywords (constellation_id);
            CREATE INDEX IF NOT EXISTS idx_ctk_keyword_id ON constellation_tour_keywords (keyword_id);
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
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS type VARCHAR(16) NOT NULL DEFAULT 'galaxy' CHECK (type IN ('galaxy','cluster'))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_constellations_type ON constellations (type)");
        // Per-cluster opt-in for the visitor's galaxy-list strip. Emergent unions
        // (?galaxies=, /[XX], /tag/) default to ON; clusters default to OFF since the
        // curator has authored a unified experience.
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS show_galaxy_list BOOLEAN NOT NULL DEFAULT FALSE");
        // Per-cluster override for fuzzy keyword matching in the multi-galaxy view.
        // 'inherit' defers to the installation default (project_info.fuzzy_keyword_matching);
        // 'on'/'off' force it for this cluster. Only meaningful on type='cluster' rows.
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS fuzzy_keyword_matching VARCHAR(16) NOT NULL DEFAULT 'inherit' CHECK (fuzzy_keyword_matching IN ('inherit','on','off'))");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS galaxy_cluster_members (
                cluster_id INT NOT NULL,
                member_id INT NOT NULL,
                position INT NOT NULL DEFAULT 0,
                PRIMARY KEY (cluster_id, member_id),
                FOREIGN KEY (cluster_id) REFERENCES constellations(id) ON DELETE CASCADE,
                FOREIGN KEY (member_id)  REFERENCES constellations(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_gcm_cluster_id ON galaxy_cluster_members (cluster_id);
            CREATE INDEX IF NOT EXISTS idx_gcm_member_id ON galaxy_cluster_members (member_id);
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
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_prt_user_id ON password_reset_tokens (user_id);
            CREATE INDEX IF NOT EXISTS idx_prt_expires_at ON password_reset_tokens (expires_at);
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
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_galaxy_tags_tag_slug ON galaxy_tags (tag_slug);
            CREATE INDEX IF NOT EXISTS idx_galaxy_tags_constellation_id ON galaxy_tags (constellation_id);
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
        $pdo->exec("ALTER TABLE keywords
                ADD COLUMN IF NOT EXISTS created_by VARCHAR(255) NULL DEFAULT NULL;
            CREATE INDEX IF NOT EXISTS idx_keywords_created_by ON keywords (created_by);
            ALTER TABLE keywords
                DROP CONSTRAINT IF EXISTS fk_keywords_created_by,
                ADD CONSTRAINT fk_keywords_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
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
        $pdo->exec("ALTER TABLE node_keywords
                ADD COLUMN IF NOT EXISTS created_by VARCHAR(255) NULL DEFAULT NULL;
            CREATE INDEX IF NOT EXISTS idx_node_keywords_created_by ON node_keywords (created_by);
            ALTER TABLE node_keywords
                DROP CONSTRAINT IF EXISTS fk_node_keywords_created_by,
                ADD CONSTRAINT fk_node_keywords_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
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
        $pdo->exec("ALTER TABLE galaxy_tags ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
        $pdo->exec("ALTER TABLE galaxy_tags
                ADD COLUMN IF NOT EXISTS created_by VARCHAR(255) NULL DEFAULT NULL;
            CREATE INDEX IF NOT EXISTS idx_galaxy_tags_created_by ON galaxy_tags (created_by);
            ALTER TABLE galaxy_tags
                DROP CONSTRAINT IF EXISTS fk_galaxy_tags_created_by,
                ADD CONSTRAINT fk_galaxy_tags_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
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
        $pdo->exec("ALTER TABLE constellations
                ADD COLUMN IF NOT EXISTS created_by VARCHAR(255) NULL DEFAULT NULL;
            CREATE INDEX IF NOT EXISTS idx_constellations_created_by ON constellations (created_by);
            ALTER TABLE constellations
                DROP CONSTRAINT IF EXISTS fk_constellations_created_by,
                ADD CONSTRAINT fk_constellations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
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
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS image_attribution VARCHAR(255) NULL DEFAULT NULL");
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
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS icon_url VARCHAR(500) NULL DEFAULT NULL");
    } catch (PDOException $e) {
        error_log('db_ensure_nodes_icon_url_column: ' . $e->getMessage());
    }
}

/**
 * Stage 6f: per-user preferred locale (2-letter code, one of
 * PROJECT_INFO_LOCALES). NULL means "not chosen"; operator notifications then
 * fall back to a multilingual body rather than defaulting to any one language
 * (the decolonial-identifier stance: no silent English default). Set via the
 * admin user surface (6f-ii).
 */
function db_ensure_users_locale_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS locale VARCHAR(5) NULL DEFAULT NULL");
    } catch (PDOException $e) {
        error_log('db_ensure_users_locale_column: ' . $e->getMessage());
    }
}

/**
 * Make users.lastname nullable. Historically NOT NULL; first name is the only
 * required name part now, so a last name may be absent (stored NULL). Additive
 * and idempotent: only flips the column when it is still NOT NULL.
 */
function db_ensure_users_lastname_nullable(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE users ALTER COLUMN lastname DROP NOT NULL");
    } catch (PDOException $e) {
        error_log('db_ensure_users_lastname_nullable: ' . $e->getMessage());
    }
}

/**
 * Ensure users.pronouns exists. Stores up to USER_PRONOUNS_MAX entries as a JSON
 * array of strings (e.g. ["they/them","elle"]); NULL means not provided. Pronouns
 * are always optional. Additive and idempotent.
 */
function db_ensure_users_pronouns_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS pronouns VARCHAR(255) NULL DEFAULT NULL");
    } catch (PDOException $e) {
        error_log('db_ensure_users_pronouns_column: ' . $e->getMessage());
    }
}

/**
 * Editor self-enrollment: users.vetted (TINYINT bool). Manually-created and
 * existing users are vetted (DEFAULT 1 backfills them); self-enrolled editors
 * are inserted as 0. Vetting is a trust + convenience gate (it unlocks an
 * optional password), NOT an edit gate: an unvetted editor can already edit
 * assigned content. Additive and idempotent.
 */
function db_ensure_users_vetted_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS vetted SMALLINT NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
        error_log('db_ensure_users_vetted_column: ' . $e->getMessage());
    }
}

/**
 * Editor self-enrollment: make users.password nullable. A NULL password means
 * "no password yet" (an unvetted self-enrolled editor who logs in only via an
 * emailed magic link). Guarded on the column's Null flag so the MODIFY runs once.
 */
function db_ensure_users_password_nullable(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE users ALTER COLUMN password DROP NOT NULL");
    } catch (PDOException $e) {
        error_log('db_ensure_users_password_nullable: ' . $e->getMessage());
    }
}

/** Run the account-field migrations together. Cheap (each is statically guarded). */
function db_ensure_users_account_columns(): void {
    db_ensure_users_lastname_nullable();
    db_ensure_users_pronouns_column();
    db_ensure_users_vetted_column();
    db_ensure_users_password_nullable();
}

/** Set (or clear) a user's vetted flag. Trust + convenience only; never edit access. */
function db_set_user_vetted(string $id, bool $vetted): void {
    db_ensure_users_vetted_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET vetted = :v WHERE id = :id");
    $stmt->execute([':v' => $vetted ? 1 : 0, ':id' => $id]);
}

/**
 * Consent records for the first-login consent gate (BACKLOG ^consent-gate-first-login).
 *
 * One row per (user, document, accepted version). History is preserved across
 * version bumps: accepting v2 of the Terms does not delete the v1 row, so the
 * record of what each editor agreed to, and when, stays auditable. The UNIQUE
 * key makes re-recording the same acceptance idempotent.
 *
 * The actual document text + the "current version" live in inc/consent.php; this
 * table only stores the fact and time of acceptance, with no IP or other PII
 * beyond the user id (matches the documented fix shape and the project's PII
 * minimization stance).
 */
function db_ensure_user_consents_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS user_consents (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                document_version VARCHAR(32) NOT NULL,
                consented_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_user_doc_version ON user_consents (user_id, document_type, document_version);
            CREATE INDEX IF NOT EXISTS idx_user_consents_user ON user_consents (user_id);
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_user_consents_table: ' . $e->getMessage());
    }
}

/**
 * Record that $userId accepted version $version of document $documentType
 * (e.g. 'tos', 'privacy'). Idempotent: re-recording the same acceptance is a
 * no-op via the UNIQUE key. Returns true on success.
 */
function db_record_user_consent(string $userId, string $documentType, string $version): bool {
    db_ensure_user_consents_table();
    try {
        $stmt = getDB()->prepare("
            INSERT INTO user_consents (user_id, document_type, document_version)
            VALUES (:u, :d, :v)
            ON CONFLICT (user_id, document_type, document_version) DO NOTHING
        ");
        return $stmt->execute([':u' => $userId, ':d' => $documentType, ':v' => $version]);
    } catch (PDOException $e) {
        error_log('db_record_user_consent: ' . db_safe_error_descriptor($e));
        return false;
    }
}

/**
 * Return the set of document versions $userId has accepted, as
 * [documentType => [version => true]] for O(1) membership checks.
 *
 * @return array<string, array<string, bool>>
 */
function db_get_user_accepted_consents(string $userId): array {
    db_ensure_user_consents_table();
    try {
        $stmt = getDB()->prepare("SELECT document_type, document_version FROM user_consents WHERE user_id = :u");
        $stmt->execute([':u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['document_type']][(string)$row['document_version']] = true;
        }
        return $out;
    } catch (PDOException $e) {
        error_log('db_get_user_accepted_consents: ' . $e->getMessage());
        return [];
    }
}

/**
 * Consent-notification tables (operator-initiated "documents changed" email).
 *
 * consent_notice_decisions: one row per (document_type, document_version) the
 *   operator has resolved, with the decision ('sent' | 'disregarded'). Its
 *   presence is what clears the persistent admin alert for that version; a
 *   later version bump has no row, so the alert re-raises. No FK (the deciding
 *   admin may later be deleted; we keep the audit row).
 * consent_notifications: one row per (user, document_type, document_version)
 *   actually emailed, so a re-send never double-notifies an editor. Cascades
 *   away with the user.
 */
function db_ensure_consent_notice_tables(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS consent_notice_decisions (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                document_type VARCHAR(32) NOT NULL,
                document_version VARCHAR(32) NOT NULL,
                decision VARCHAR(16) NOT NULL,
                decided_by VARCHAR(255) NULL,
                decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_doc_version ON consent_notice_decisions (document_type, document_version);
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS consent_notifications (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                document_version VARCHAR(32) NOT NULL,
                notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_notif_user_doc_version ON consent_notifications (user_id, document_type, document_version);
            CREATE INDEX IF NOT EXISTS idx_consent_notifications_user ON consent_notifications (user_id);
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_consent_notice_tables: ' . $e->getMessage());
    }
}

/**
 * Record the operator's decision for a (documentType, version): 'sent' or
 * 'disregarded'. Idempotent on the (doc, version) unique key; a later decision
 * for the same pair updates it (e.g. disregarded then sent). Returns true on
 * success.
 */
function db_record_consent_notice_decision(string $documentType, string $version, string $decision, ?string $adminId): bool {
    db_ensure_consent_notice_tables();
    try {
        $stmt = getDB()->prepare("
            INSERT INTO consent_notice_decisions (document_type, document_version, decision, decided_by)
            VALUES (:d, :v, :dec, :by)
            ON CONFLICT (document_type, document_version) DO UPDATE SET decision = EXCLUDED.decision, decided_by = EXCLUDED.decided_by, decided_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([':d' => $documentType, ':v' => $version, ':dec' => $decision, ':by' => $adminId]);
    } catch (PDOException $e) {
        error_log('db_record_consent_notice_decision: ' . $e->getMessage());
        return false;
    }
}

/**
 * Return resolved decisions as [documentType => [version => decision]].
 *
 * @return array<string, array<string, string>>
 */
function db_get_consent_notice_decisions(): array {
    db_ensure_consent_notice_tables();
    try {
        $stmt = getDB()->query("SELECT document_type, document_version, decision FROM consent_notice_decisions");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['document_type']][(string)$row['document_version']] = (string)$row['decision'];
        }
        return $out;
    } catch (PDOException $e) {
        error_log('db_get_consent_notice_decisions: ' . $e->getMessage());
        return [];
    }
}

/**
 * Record that $userId was emailed about version $version of $documentType.
 * Idempotent via the unique key. Returns true on success.
 */
function db_record_consent_notification(string $userId, string $documentType, string $version): bool {
    db_ensure_consent_notice_tables();
    try {
        $stmt = getDB()->prepare("
            INSERT INTO consent_notifications (user_id, document_type, document_version)
            VALUES (:u, :d, :v)
            ON CONFLICT (user_id, document_type, document_version) DO NOTHING
        ");
        return $stmt->execute([':u' => $userId, ':d' => $documentType, ':v' => $version]);
    } catch (PDOException $e) {
        error_log('db_record_consent_notification: ' . $e->getMessage());
        return false;
    }
}

/**
 * Versions $userId has already been emailed about, as
 * [documentType => [version => true]].
 *
 * @return array<string, array<string, bool>>
 */
function db_get_user_consent_notifications(string $userId): array {
    db_ensure_consent_notice_tables();
    try {
        $stmt = getDB()->prepare("SELECT document_type, document_version FROM consent_notifications WHERE user_id = :u");
        $stmt->execute([':u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['document_type']][(string)$row['document_version']] = true;
        }
        return $out;
    } catch (PDOException $e) {
        error_log('db_get_user_consent_notifications: ' . $e->getMessage());
        return [];
    }
}

/**
 * Maximum number of pronoun entries an account may store, and the per-entry
 * character cap. Enforced server-side (not just in the UI).
 */
const USER_PRONOUNS_MAX = 3;
const USER_PRONOUNS_MAX_LEN = 30;

/**
 * Content guard denylist for the free-text pronoun field. This is a floor, not
 * full moderation: a small case-insensitive set of unambiguous slurs / hateful
 * or obviously spiteful terms, matched as substrings (so inflections and
 * concatenations are caught too). Keep additions HERE, in one place, so the
 * list stays easy to extend. Entries are lowercase; matching lowercases input.
 *
 * The charset guard already rejects digits, URLs, and markup, so this list only
 * needs to cover letter-only hateful terms. English plus a few obvious
 * cross-locale slurs.
 */
const USER_PRONOUNS_DENYLIST = [
    // anti-LGBTQ slurs
    'faggot', 'fagot', 'tranny', 'trannie', 'shemale', 'dyke',
    // racial / ethnic slurs
    'nigger', 'nigga', 'chink', 'spic', 'kike', 'wetback', 'coon', 'gook',
    // ableist / misogynist slurs commonly used spitefully
    'retard', 'retarded',
    // obvious cross-locale slurs
    'maricon', 'puto', 'veado', 'viado', 'negre',
];

/**
 * Decode a stored pronouns JSON value into a plain list of non-empty strings.
 * Returns [] for null / empty / malformed input.
 *
 * @return list<string>
 */
function db_user_pronouns_list(?string $pronounsJson): array {
    if ($pronounsJson === null || $pronounsJson === '') return [];
    $decoded = json_decode($pronounsJson, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $v) {
        if (is_string($v) && trim($v) !== '') $out[] = $v;
    }
    return $out;
}

/**
 * Sanitize + validate a set of raw pronoun entries (e.g. the checked common
 * options merged with the free-text field, comma/newline split by the caller).
 *
 * Trims, drops empties, dedupes case-insensitively (first spelling wins), then
 * applies the content guard: max count, per-entry length, allowed charset, and
 * the denylist. Pronouns are optional, so an empty set is valid (ok=true,
 * json=null).
 *
 * @param list<string> $entries
 * @return array{ok:bool, json:?string, error:?string}
 *   On success: json is a JSON array string, or null when no entries were given.
 *   On failure: error is an i18n key suffix ('too_many'|'too_long'|'charset'|'denylist').
 */
function db_user_pronouns_sanitize(array $entries): array {
    $seen = [];
    $clean = [];
    foreach ($entries as $raw) {
        $e = trim((string)$raw);
        if ($e === '') continue;
        $k = mb_strtolower($e, 'UTF-8');
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $clean[] = $e;
    }

    if ($clean === []) {
        return ['ok' => true, 'json' => null, 'error' => null];
    }
    if (count($clean) > USER_PRONOUNS_MAX) {
        return ['ok' => false, 'json' => null, 'error' => 'too_many'];
    }
    foreach ($clean as $e) {
        if (mb_strlen($e, 'UTF-8') > USER_PRONOUNS_MAX_LEN) {
            return ['ok' => false, 'json' => null, 'error' => 'too_long'];
        }
        // Allowed: Unicode letters, space, slash, hyphen, apostrophe. This
        // rejects digits, URLs (no ':' or '.'), and markup ('<', '>').
        if (!preg_match("/^[\\p{L} \\/'-]+$/u", $e)) {
            return ['ok' => false, 'json' => null, 'error' => 'charset'];
        }
        $low = mb_strtolower($e, 'UTF-8');
        foreach (USER_PRONOUNS_DENYLIST as $bad) {
            if ($bad !== '' && mb_strpos($low, $bad) !== false) {
                return ['ok' => false, 'json' => null, 'error' => 'denylist'];
            }
        }
    }

    $json = json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return ['ok' => true, 'json' => ($json === false ? null : $json), 'error' => null];
}

/**
 * Build the raw entry list from a request: the checked common options plus the
 * free-text field (comma- or newline-separated). The caller passes the result
 * to db_user_pronouns_sanitize().
 *
 * @param mixed $checked    the pronouns[] POST value (array of strings) or null
 * @param mixed $customRaw  the free-text POST value (string) or null
 * @return list<string>
 */
function db_user_pronouns_entries_from_request($checked, $customRaw): array {
    $entries = [];
    foreach ((array)$checked as $c) {
        if (is_string($c)) $entries[] = $c;
    }
    $custom = is_string($customRaw) ? $customRaw : '';
    if ($custom !== '') {
        $parts = preg_split('/[,\n\r]+/', $custom);
        if ($parts !== false) {
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $entries[] = $p;
            }
        }
    }
    return $entries;
}

/**
 * The locale's common pronoun options, parsed from the localized
 * 'pronoun_common_set' string (a comma-separated list). Used to render the
 * picker chips. Returns [] when the key is missing (t() returns the key).
 *
 * @return list<string>
 */
function db_user_pronoun_common_options(): array {
    $raw = t('pronoun_common_set');
    if ($raw === 'pronoun_common_set' || trim($raw) === '') return [];
    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
}

/**
 * "If a pronoun must be used, pick one at random." Returns one entry chosen at
 * random from the stored set, or null if none is stored.
 *
 * NOTE: the project's standing rule is gender-neutral, pronoun-avoiding phrasing
 * everywhere, so there may be no current call site. The helper exists for any
 * future surface where a third-person pronoun is genuinely unavoidable; do not
 * invent gendered copy just to call it.
 */
function db_user_pronoun_random(?string $pronounsJson): ?string {
    $list = db_user_pronouns_list($pronounsJson);
    if ($list === []) return null;
    return $list[random_int(0, count($list) - 1)];
}

/** Ensure nodes.pdf_url column exists. */
function db_ensure_nodes_pdf_url_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS pdf_url VARCHAR(500) NULL DEFAULT NULL");
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
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_node_type ON nodes (node_type)");
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
            )
        ");
        // Supporting index for the moved_by FK (Postgres does not auto-index FK columns).
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_keyword_positions_moved_by ON keyword_positions (moved_by)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS keyword_relations (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                keyword_a_id INT NOT NULL,
                keyword_b_id INT NOT NULL,
                created_by VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                note TEXT NULL,
                anchor_a VARCHAR(8) NOT NULL DEFAULT 'right',
                anchor_b VARCHAR(8) NOT NULL DEFAULT 'left',
                CONSTRAINT uk_pair UNIQUE (keyword_a_id, keyword_b_id),
                CONSTRAINT chk_canonical CHECK (keyword_a_id < keyword_b_id),
                FOREIGN KEY (keyword_a_id) REFERENCES keywords(id) ON DELETE CASCADE,
                FOREIGN KEY (keyword_b_id) REFERENCES keywords(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        // Idempotent migration: if keyword_relations was created before the
        // anchor_a/anchor_b columns landed, add them now with sensible defaults.
        $pdo->exec("ALTER TABLE keyword_relations ADD COLUMN IF NOT EXISTS anchor_a VARCHAR(8) NOT NULL DEFAULT 'right'");
        $pdo->exec("ALTER TABLE keyword_relations ADD COLUMN IF NOT EXISTS anchor_b VARCHAR(8) NOT NULL DEFAULT 'left'");
        // Supporting indexes for FK columns Postgres does not auto-index (keyword_a_id is
        // already the leading column of uk_pair; keyword_b_id and created_by are not).
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_keyword_relations_keyword_b_id ON keyword_relations (keyword_b_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_keyword_relations_created_by ON keyword_relations (created_by)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS keyword_position_history (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                keyword_id INT NOT NULL,
                canvas_x FLOAT NOT NULL,
                canvas_y FLOAT NOT NULL,
                moved_by VARCHAR(255) NULL,
                moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_kph_keyword ON keyword_position_history (keyword_id);
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
        INSERT INTO keyword_positions (keyword_id, canvas_x, canvas_y, moved_by, moved_at)
        VALUES (:kid, :x, :y, NULL, NULL)
        ON CONFLICT (keyword_id) DO NOTHING
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
            ON CONFLICT (keyword_id) DO UPDATE SET
                canvas_x = EXCLUDED.canvas_x,
                canvas_y = EXCLUDED.canvas_y,
                moved_by = EXCLUDED.moved_by,
                moved_at = EXCLUDED.moved_at
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
            ON CONFLICT (keyword_id) DO UPDATE SET
                canvas_x = EXCLUDED.canvas_x,
                canvas_y = EXCLUDED.canvas_y,
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
            ON CONFLICT (keyword_id) DO UPDATE SET
                canvas_x = EXCLUDED.canvas_x,
                canvas_y = EXCLUDED.canvas_y,
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
    $stmt = $pdo->prepare("DELETE FROM keyword_position_history WHERE moved_at < (NOW() - ({$age} * INTERVAL '1 day'))");
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
    $stmt = $pdo->prepare("DELETE FROM auth_attempts WHERE created_at < (NOW() - ({$age} * INTERVAL '1 day'))");
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
    $stmt = $pdo->prepare("DELETE FROM audit_events WHERE created_at < (NOW() - ({$keep} * INTERVAL '1 day'))");
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
        RETURNING id
    ");
    $stmt->execute([
        ':a' => $lo, ':b' => $hi, ':uid' => $userId, ':note' => $note,
        ':anchor_a' => $loAnchor, ':anchor_b' => $hiAnchor,
    ]);
    return (int)$stmt->fetchColumn();
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
        $hasSourceFacet = $pdo->query("SELECT to_regclass('public.nodes') IS NOT NULL AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'nodes' AND column_name = 'source_facet')")->fetchColumn();
        if ($hasSourceFacet) return;

        $hasMucuaName = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'nodes' AND column_name = 'mucua_name')")->fetchColumn();
        if ($hasMucuaName) {
            // Old schema: rename the column so existing data carries over.
            $pdo->exec("ALTER TABLE nodes RENAME COLUMN mucua_name TO source_facet");
            return;
        }

        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS source_facet VARCHAR(255) NULL, ADD COLUMN IF NOT EXISTS media_type VARCHAR(50) NULL, ADD COLUMN IF NOT EXISTS source_created_at VARCHAR(30) NULL");
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
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                created_by VARCHAR(255) NULL,
                trigger_type VARCHAR(16) NOT NULL DEFAULT 'manual' CHECK (trigger_type IN ('manual','scheduled')),
                note VARCHAR(500) NULL,
                sha256 CHAR(64) NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS unique_filename ON snapshots (filename);
            CREATE INDEX IF NOT EXISTS idx_snapshots_created_at ON snapshots (created_at);
        ");
        // Idempotent backfill: older installs predate the integrity column.
        // NULL means "no recorded checksum" — restore proceeds without
        // verification rather than refusing to restore legacy snapshots.
        $pdo->exec("ALTER TABLE snapshots ADD COLUMN IF NOT EXISTS sha256 CHAR(64) NULL");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS snapshot_schedule (
                id SMALLINT NOT NULL PRIMARY KEY DEFAULT 1,
                enabled BOOLEAN NOT NULL DEFAULT FALSE,
                hour SMALLINT NOT NULL DEFAULT 3,
                keep_days INT NOT NULL DEFAULT 7,
                last_run_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Seed the singleton schedule row.
        $pdo->exec("INSERT INTO snapshot_schedule (id) VALUES (1) ON CONFLICT (id) DO NOTHING");

        // Migrate older installs to the simplified schema (enabled / hour / keep_days).
        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'snapshot_schedule'")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (in_array('keep_last', $cols, true) && !in_array('keep_days', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule ADD COLUMN keep_days INT NOT NULL DEFAULT 7");
            $pdo->exec("ALTER TABLE snapshot_schedule DROP COLUMN keep_last");
        }
        if (in_array('frequency', $cols, true) && !in_array('enabled', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule ADD COLUMN enabled BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("UPDATE snapshot_schedule SET enabled = (frequency <> 'off')");
        }
        if (in_array('frequency', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule DROP COLUMN frequency");
        }
        if (in_array('day_of_week', $cols, true)) {
            $pdo->exec("ALTER TABLE snapshot_schedule DROP COLUMN day_of_week");
        }
        // 'hour' was nullable in older schemas; make it NOT NULL DEFAULT 3.
        $hourCol = $pdo->query("SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'snapshot_schedule' AND column_name = 'hour'")->fetch(PDO::FETCH_ASSOC);
        if ($hourCol && (($hourCol['is_nullable'] ?? 'YES') === 'YES')) {
            $pdo->exec("UPDATE snapshot_schedule SET hour = 3 WHERE hour IS NULL");
            $pdo->exec("ALTER TABLE snapshot_schedule ALTER COLUMN hour SET DEFAULT 3");
            $pdo->exec("ALTER TABLE snapshot_schedule ALTER COLUMN hour SET NOT NULL");
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
        $pdo->exec("ALTER TABLE nodes ADD COLUMN IF NOT EXISTS import_slug VARCHAR(255) NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_import_slug ON nodes (constellation_id, import_slug)");
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
        'pdf_error_open_text' => "VARCHAR(200) NOT NULL DEFAULT 'Couldn''t open PDF.'",
    ];
    try {
        $pdo = getDB();
        foreach ($newCols as $col => $def) {
            $pdo->exec("ALTER TABLE project_info ADD COLUMN IF NOT EXISTS {$col} {$def}");
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
    $actualCols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'project_info'")->fetchAll(PDO::FETCH_COLUMN);
    $keys = array_values(array_intersect(PROJECT_INFO_KEYS, $actualCols));
    if (empty($keys)) return;
    $cols = implode(', ', $keys);
    $placeholders = ':' . implode(', :', $keys);
    $updates = [];
    foreach ($keys as $k) {
        $updates[] = "$k = EXCLUDED.$k";
    }
    $updateStr = implode(', ', $updates);

    $stmt = $pdo->prepare("INSERT INTO project_info (locale, $cols) VALUES (:locale, $placeholders) ON CONFLICT (locale) DO UPDATE SET $updateStr");
    foreach (PROJECT_INFO_LOCALES as $locale) {
        $params = [':locale' => $locale];
        foreach ($keys as $k) {
            $params[':' . $k] = $defaults[$locale][$k] ?? '';
        }
        $stmt->execute($params);
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
        db_ensure_users_vetted_column();
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, email, password, firstname, lastname, type, vetted, date_last_login FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function db_get_user_by_id(string $userId): ?array {
    try {
        db_ensure_users_vetted_column();
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, email, firstname, lastname, type, vetted FROM users WHERE id = :id LIMIT 1");
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
        VALUES (:h, :uid, CURRENT_TIMESTAMP + (:ttl * INTERVAL '1 second'))
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
        error_log('db_consume_password_reset_token error: ' . db_safe_error_descriptor($e));
        return false;
    }
}

// ---------------------------------------------------------------------------
// Login tokens (editor self-enrollment): one table for several single-use,
// emailed-link purposes. Mirrors password_reset_tokens but adds `purpose` so
// magic-login (15 min), enroll-confirm (24 h), and vetting set-password (7 d)
// links share one substrate. 64-hex token, SHA-256 at rest, single-use.
// expires_at is computed in PHP (UTC, matches the DB clock) rather than via
// MySQL DATE_ADD(... INTERVAL ...) for PostgreSQL portability.
// ---------------------------------------------------------------------------

const LOGIN_TOKEN_PURPOSES = ['magic_login', 'enroll_confirm', 'vetting'];

function db_ensure_login_tokens_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS login_tokens (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                purpose VARCHAR(24) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_login_tokens_user_purpose ON login_tokens (user_id, purpose);
            CREATE INDEX IF NOT EXISTS idx_login_tokens_expires_at ON login_tokens (expires_at);
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_login_tokens_table: ' . $e->getMessage());
    }
}

/**
 * Create a single-use login token of a given purpose. Stores the SHA-256 hash,
 * returns the plaintext (the caller emails it in a URL). Any prior unused token
 * of the SAME purpose for this user is invalidated so a fresh request supersedes
 * stale links. Throws on an unknown purpose (programming error).
 */
function db_create_login_token(string $userId, string $purpose, int $ttlSeconds): string {
    if (!in_array($purpose, LOGIN_TOKEN_PURPOSES, true)) {
        throw new InvalidArgumentException('db_create_login_token: unknown purpose ' . $purpose);
    }
    db_ensure_login_tokens_table();
    $pdo = getDB();
    $pdo->prepare("UPDATE login_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND purpose = :p AND used_at IS NULL")
        ->execute([':uid' => $userId, ':p' => $purpose]);
    $token = bin2hex(random_bytes(32)); // 64 hex chars, ~256 bits
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + max(60, $ttlSeconds));
    $stmt = $pdo->prepare("
        INSERT INTO login_tokens (token_hash, user_id, purpose, expires_at)
        VALUES (:h, :uid, :p, :exp)
    ");
    $stmt->execute([':h' => $hash, ':uid' => $userId, ':p' => $purpose, ':exp' => $expiresAt]);
    return $token;
}

/**
 * Look up a valid (unconsumed, unexpired) login token of a given purpose without
 * consuming it. Returns the user row for the GET handler that decides whether to
 * render a page, null otherwise.
 *
 * @return array<string,mixed>|null
 */
function db_get_user_for_login_token(string $token, string $purpose): ?array {
    if ($token === '' || strlen($token) !== 64) return null;
    if (!in_array($purpose, LOGIN_TOKEN_PURPOSES, true)) return null;
    db_ensure_login_tokens_table();
    $pdo = getDB();
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.firstname, u.lastname, u.type, u.vetted, u.date_last_login
        FROM login_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = :h AND t.purpose = :p AND t.used_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP
        LIMIT 1
    ");
    $stmt->execute([':h' => $hash, ':p' => $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

/**
 * Atomically consume a login token of a given purpose. Returns the user row on
 * success (the caller then establishes a session / sets a password), null if the
 * token is invalid, expired, used, or of the wrong purpose. Single-use via
 * used_at under FOR UPDATE.
 *
 * @return array<string,mixed>|null
 */
function db_consume_login_token(string $token, string $purpose): ?array {
    if ($token === '' || strlen($token) !== 64) return null;
    if (!in_array($purpose, LOGIN_TOKEN_PURPOSES, true)) return null;
    db_ensure_login_tokens_table();
    $pdo = getDB();
    $hash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT user_id FROM login_tokens
            WHERE token_hash = :h AND purpose = :p AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':h' => $hash, ':p' => $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return null;
        }
        $userId = (string)$row['user_id'];
        $pdo->prepare("UPDATE login_tokens SET used_at = CURRENT_TIMESTAMP WHERE token_hash = :h")
            ->execute([':h' => $hash]);
        $userStmt = $pdo->prepare("SELECT id, email, firstname, lastname, type, vetted, date_last_login FROM users WHERE id = :id LIMIT 1");
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        return $user !== false ? $user : null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('db_consume_login_token error: ' . db_safe_error_descriptor($e));
        return null;
    }
}

/** Delete expired or already-consumed login tokens. Returns rows removed. */
function db_gc_login_tokens(): int {
    db_ensure_login_tokens_table();
    try {
        $pdo = getDB();
        return (int)$pdo->exec("DELETE FROM login_tokens WHERE expires_at < CURRENT_TIMESTAMP OR used_at IS NOT NULL");
    } catch (PDOException $e) {
        error_log('db_gc_login_tokens: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Reclaim abandoned self-enrolments: editor accounts that were never confirmed
 * and never signed in, older than $days. The triple guard
 * (type editor + vetted=0 + password IS NULL + date_last_login IS NULL) makes
 * this safe; admin-created and bulk-imported users are vetted=1, and anyone who
 * confirmed or logged in has date_last_login set, so neither is ever touched.
 * The cutoff is computed in PHP (PHP tz = DB tz = UTC) to stay engine-portable
 * (no MySQL DATE_SUB/INTERVAL). FK ON DELETE CASCADE clears their login_tokens
 * and seats. Returns rows removed.
 */
function db_gc_unconfirmed_enrollments(int $days = 30): int {
    db_ensure_users_account_columns();
    if ($days < 1) { $days = 1; }
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "DELETE FROM users
             WHERE type = 1 AND vetted = 0 AND password IS NULL
               AND date_last_login IS NULL AND date_created < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return (int)$stmt->rowCount();
    } catch (PDOException $e) {
        error_log('db_gc_unconfirmed_enrollments: ' . $e->getMessage());
        return 0;
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
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            action VARCHAR(16) NOT NULL,
            email VARCHAR(255) NULL,
            ip VARCHAR(45) NOT NULL,
            success SMALLINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_lookup ON auth_attempts (action, email, ip, created_at);
        CREATE INDEX IF NOT EXISTS idx_ip_window ON auth_attempts (ip, created_at);
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
            $pdo->exec("DELETE FROM auth_attempts WHERE id IN (SELECT id FROM auth_attempts WHERE created_at < (NOW() - INTERVAL '30 day') LIMIT 10000)");
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
/**
 * Session-scoped advisory lock: the Postgres analogue of MySQL GET_LOCK/RELEASE_LOCK.
 * Postgres advisory locks are keyed by a bigint, so the string name is hashed to a
 * stable signed 64-bit key. pg_try_advisory_lock is non-blocking, so we poll up to
 * $timeoutSeconds to mirror GET_LOCK's bounded wait. The lock releases on
 * pg_advisory_unlock or at session (request) end, matching MySQL's connection-scoped
 * release-on-disconnect semantics. Returns ['key'=>int, 'acquired'=>bool].
 */
function db_advisory_key(string $name): int {
    $bytes = substr(hash('sha256', $name, true), 0, 8);
    /** @var array{1:int} $u */
    $u = unpack('q', $bytes);
    return $u[1];
}

function db_advisory_lock_acquire(string $name, int $timeoutSeconds): array {
    $pdo = getDB();
    $key = db_advisory_key($name);
    $stmt = $pdo->prepare("SELECT pg_try_advisory_lock(:k::bigint)");
    $deadline = microtime(true) + max(0, $timeoutSeconds);
    $acquired = false;
    while (true) {
        $stmt->execute([':k' => $key]);
        $acquired = (bool)$stmt->fetchColumn();
        if ($acquired || microtime(true) >= $deadline) break;
        usleep(100000); // 100ms between attempts
    }
    // 'key' keeps the human-readable name (callers/tests inspect it); 'lock_id' is
    // the numeric advisory key used to release.
    return ['key' => $name, 'lock_id' => $key, 'acquired' => $acquired];
}

function db_advisory_lock_release(array $lock): void {
    if (empty($lock['acquired'])) return;
    try {
        getDB()->prepare("SELECT pg_advisory_unlock(:k::bigint)")->execute([':k' => $lock['lock_id']]);
    } catch (Throwable $_) {
        // Best-effort. Session close at end-of-request releases the lock anyway.
    }
}

function db_node_lock_acquire(int $nodeId): array {
    return db_advisory_lock_acquire('telaris:node:' . $nodeId, 5);
}

function db_node_lock_release(array $lock): void {
    db_advisory_lock_release($lock);
}

function db_auth_throttle_lock_acquire(string $action, string $ip): array {
    // 5s wait is generous enough for normal serialization but short enough that a
    // wedged worker can't pin the gate for long. On contention the caller fails
    // closed (treats as throttled).
    return db_advisory_lock_acquire('telaris:auth_throttle:' . $action . ':' . ($ip !== '' ? $ip : '-'), 5);
}

function db_auth_throttle_lock_release(array $lock): void {
    db_advisory_lock_release($lock);
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
    $sql = "SELECT COUNT(*) FROM auth_attempts WHERE action = :action AND created_at >= (NOW() - (" . max(1, (int)$windowSeconds) . " * INTERVAL '1 second'))";
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
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            action VARCHAR(64) NOT NULL,
            actor_user_id VARCHAR(255) NULL,
            actor_email_tag VARCHAR(64) NULL,
            target_type VARCHAR(32) NULL,
            target_id VARCHAR(64) NULL,
            details_json JSONB NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_action_time ON audit_events (action, created_at);
        CREATE INDEX IF NOT EXISTS idx_actor_time ON audit_events (actor_user_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_target ON audit_events (target_type, target_id);
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
                $pdo->exec("DELETE FROM audit_events WHERE id IN (SELECT id FROM audit_events WHERE created_at < (NOW() - INTERVAL '{$keepDays} day') LIMIT 10000)");
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
    db_ensure_users_locale_column();
    db_ensure_users_account_columns();
    db_ensure_users_editor_enabled_column();
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT id, email, firstname, lastname, pronouns, type, vetted, editor_enabled, locale, date_created, date_last_login, updated_at
        FROM users
        ORDER BY date_created DESC
    ");
    return $stmt->fetchAll();
}

/**
 * Stage 6f-ii: set (or clear) a user's preferred notification locale. A null
 * or empty value clears the preference (operator notifications then fall back
 * to a multilingual body). The caller validates that a non-empty $locale is
 * one of PROJECT_INFO_LOCALES.
 */
function db_set_user_locale(string $id, ?string $locale): void {
    db_ensure_users_locale_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET locale = :loc WHERE id = :id");
    $stmt->execute([':loc' => ($locale === '' ? null : $locale), ':id' => $id]);
}

function db_insert_user(string $id, string $email, ?string $hashedPassword, string $firstname, ?string $lastname, int $type, ?string $pronouns = null, bool $vetted = true): void {
    db_ensure_users_account_columns();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO users (id, email, password, firstname, lastname, pronouns, type, vetted)
        VALUES (:id, :email, :password, :firstname, :lastname, :pronouns, :type, :vetted)
    ");
    $stmt->execute([
        ':id' => $id,
        ':email' => $email,
        ':password' => ($hashedPassword === null || $hashedPassword === '') ? null : $hashedPassword,
        ':firstname' => $firstname,
        ':lastname' => ($lastname === null || $lastname === '') ? null : $lastname,
        ':pronouns' => ($pronouns === null || $pronouns === '') ? null : $pronouns,
        ':type' => $type,
        ':vetted' => $vetted ? 1 : 0
    ]);
}

function db_update_user(string $id, string $email, string $firstname, ?string $lastname, int $type, ?string $hashedPassword = null, ?string $pronouns = null): void {
    db_ensure_users_account_columns();
    $pdo = getDB();
    $lastVal = ($lastname === null || $lastname === '') ? null : $lastname;
    $pronounVal = ($pronouns === null || $pronouns === '') ? null : $pronouns;
    if ($hashedPassword !== null) {
        $stmt = $pdo->prepare("
            UPDATE users SET email = :email, firstname = :firstname, lastname = :lastname, pronouns = :pronouns, password = :password, type = :type WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id, ':email' => $email, ':firstname' => $firstname, ':lastname' => $lastVal,
            ':pronouns' => $pronounVal, ':password' => $hashedPassword, ':type' => $type
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users SET email = :email, firstname = :firstname, lastname = :lastname, pronouns = :pronouns, type = :type WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id, ':email' => $email, ':firstname' => $firstname, ':lastname' => $lastVal,
            ':pronouns' => $pronounVal, ':type' => $type
        ]);
    }
}

function db_delete_user(string $id): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

/**
 * Editor self-enrollment: per-seat access level on user_constellations. Today a
 * seat is binary (its presence = read-write); this adds an explicit level so a
 * seat can be read-only (the editor lists/opens the galaxy's components in the
 * Edit view but cannot mutate them). VARCHAR + CHECK (not ENUM) for Postgres
 * portability; DEFAULT 'read_write' backfills existing seats to current behaviour.
 */
function db_ensure_user_constellations_access_level_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE user_constellations
                ADD COLUMN IF NOT EXISTS access_level VARCHAR(16) NOT NULL DEFAULT 'read_write'
                CHECK (access_level IN ('read_write','read_only'))");
    } catch (PDOException $e) {
        error_log('db_ensure_user_constellations_access_level_column: ' . $e->getMessage());
    }
}

/**
 * Galaxy ids a user created (their "personal" galaxies). Used by delete-user to
 * offer removing them; galaxies created by someone else are never included.
 * @return list<int>
 */
function db_get_constellation_ids_created_by(string $userId): array {
    if ($userId === '') return [];
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT id FROM constellations WHERE created_by = :u AND type = 'galaxy' ORDER BY id");
        $stmt->execute([':u' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        error_log('db_get_constellation_ids_created_by: ' . $e->getMessage());
        return [];
    }
}

/**
 * Owned-galaxy counts for every creator in one grouped query, so the admin user
 * list does not run a per-row COUNT (it used to call the helper above twice per
 * user). Returns [user_id => count]; users with no owned galaxy are absent (treat
 * as 0). Mirrors the type='galaxy' filter above.
 *
 * @return array<string,int>
 */
function db_count_galaxies_by_creator(): array {
    $pdo = getDB();
    try {
        $stmt = $pdo->query("SELECT created_by, COUNT(*) AS n FROM constellations WHERE created_by IS NOT NULL AND type = 'galaxy' GROUP BY created_by");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['created_by']] = (int)$row['n'];
        }
        return $out;
    } catch (PDOException $e) {
        error_log('db_count_galaxies_by_creator: ' . $e->getMessage());
        return [];
    }
}

/** @return list<int> */
function db_get_user_constellation_ids(string $userId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT constellation_id FROM user_constellations WHERE user_id = :user_id ORDER BY constellation_id");
    $stmt->execute([':user_id' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Per-seat access levels for a user as [constellation_id => 'read_write'|'read_only'].
 * Used to render read-only seats without edit affordances and (via
 * db_user_can_write_constellation) to enforce write access server-side.
 *
 * @return array<int,string>
 */
function db_get_user_constellation_access(string $userId): array {
    db_ensure_user_constellations_access_level_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT constellation_id, access_level FROM user_constellations WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $userId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $level = (string)$row['access_level'];
        $out[(int)$row['constellation_id']] = in_array($level, ENROLL_ACCESS_LEVELS, true) ? $level : ENROLL_ACCESS_DEFAULT;
    }
    return $out;
}

/**
 * Can this editor write to this galaxy? True when the user holds a read_write
 * seat for it; false for a read_only seat or no seat. A null user id (no editor
 * context, e.g. an admin or API-key caller) returns true: this gate is only for
 * per-user editor seats, the existing admin/API-key authorization is unchanged.
 */
function db_user_can_write_constellation(?string $userId, int $constellationId): bool {
    if ($userId === null || $userId === '' || $constellationId <= 0) {
        return true;
    }
    db_ensure_user_constellations_access_level_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT access_level FROM user_constellations WHERE user_id = :uid AND constellation_id = :cid LIMIT 1");
    $stmt->execute([':uid' => $userId, ':cid' => $constellationId]);
    $level = $stmt->fetchColumn();
    if ($level === false) {
        return false;
    }
    return (string)$level !== 'read_only';
}

// ---------------------------------------------------------------------------
// Editor enable/disable (cascading: Installation > Cluster > Galaxy > User).
//
// Each level defaults to ENABLED. The effective permission for an editor to act
// is the AND of every level: installation, the user, the galaxy, and every
// cluster the galaxy belongs to (most-restrictive wins). Admins are never gated
// by this (they manage the installation and must be able to re-enable). The
// flags only gate the EDITOR role; they never delete accounts or content.
//   - Installation: system_meta key 'editors_enabled' ('0' disables).
//   - Cluster + Galaxy: constellations.editors_enabled (TINYINT, default 1).
//   - User: users.editor_enabled (TINYINT, default 1).
// ---------------------------------------------------------------------------

function db_ensure_constellations_editors_enabled_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS editors_enabled SMALLINT NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
        error_log('db_ensure_constellations_editors_enabled_column: ' . $e->getMessage());
    }
}

function db_ensure_users_editor_enabled_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS editor_enabled SMALLINT NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
        error_log('db_ensure_users_editor_enabled_column: ' . $e->getMessage());
    }
}

/** Installation-wide editor switch. Defaults to enabled when unset. */
function db_installation_editors_enabled(): bool {
    $v = db_system_meta_get('editors_enabled');
    return $v === null ? true : ($v !== '0');
}

function db_set_installation_editors_enabled(bool $enabled): void {
    db_system_meta_set('editors_enabled', $enabled ? '1' : '0');
}

/** Per-constellation (cluster OR galaxy) editor switch. Defaults to enabled. */
function db_constellation_editors_enabled(int $constellationId): bool {
    if ($constellationId <= 0) return true;
    db_ensure_constellations_editors_enabled_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT editors_enabled FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $constellationId]);
    $v = $stmt->fetchColumn();
    return $v === false ? true : ((int)$v === 1);
}

function db_set_constellation_editors_enabled(int $constellationId, bool $enabled): void {
    if ($constellationId <= 0) return;
    db_ensure_constellations_editors_enabled_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE constellations SET editors_enabled = :v WHERE id = :id");
    $stmt->execute([':v' => $enabled ? 1 : 0, ':id' => $constellationId]);
}

/** Per-user editor switch. Defaults to enabled. */
function db_user_editor_enabled(?string $userId): bool {
    if ($userId === null || $userId === '') return true;
    db_ensure_users_editor_enabled_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT editor_enabled FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $v = $stmt->fetchColumn();
    return $v === false ? true : ((int)$v === 1);
}

function db_set_user_editor_enabled(string $userId, bool $enabled): void {
    if ($userId === '') return;
    db_ensure_users_editor_enabled_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET editor_enabled = :v WHERE id = :id");
    $stmt->execute([':v' => $enabled ? 1 : 0, ':id' => $userId]);
}

/** The cluster ids a galaxy belongs to (its parent clusters). */
function db_get_parent_cluster_ids(int $galaxyId): array {
    if ($galaxyId <= 0) return [];
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT cluster_id FROM galaxy_cluster_members WHERE member_id = :mid");
    $stmt->execute([':mid' => $galaxyId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Whether editors may edit this galaxy, cascading installation + the galaxy +
 * every parent cluster (NOT the user; that is the login axis). Returns the
 * blocking level for messaging: 'installation' | 'cluster' | 'galaxy' | null.
 */
function db_editors_blocked_level_for_galaxy(int $galaxyId): ?string {
    if (!db_installation_editors_enabled()) return 'installation';
    if ($galaxyId > 0) {
        if (!db_constellation_editors_enabled($galaxyId)) return 'galaxy';
        foreach (db_get_parent_cluster_ids($galaxyId) as $cid) {
            if (!db_constellation_editors_enabled($cid)) return 'cluster';
        }
    }
    return null;
}

/** Whether an editor may log in / hold an editing session (installation + user). */
function db_editor_login_allowed(?string $userId): bool {
    if (!db_installation_editors_enabled()) return false;
    return db_user_editor_enabled($userId);
}

/**
 * Replace a user's galaxy seats. $accessLevel applies to all seats in this call
 * (the admin UI sets one level for the granted set); validated against
 * ENROLL_ACCESS_LEVELS, falling back to read_write.
 */
function db_set_user_constellations(string $userId, array $constellationIds, string $accessLevel = ENROLL_ACCESS_DEFAULT): void {
    db_ensure_user_constellations_access_level_column();
    if (!in_array($accessLevel, ENROLL_ACCESS_LEVELS, true)) {
        $accessLevel = ENROLL_ACCESS_DEFAULT;
    }
    $pdo = getDB();
    $pdo->prepare("DELETE FROM user_constellations WHERE user_id = :user_id")->execute([':user_id' => $userId]);
    $constellationIds = array_unique(array_map('intval', $constellationIds));
    if ($constellationIds === []) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO user_constellations (user_id, constellation_id, access_level) VALUES (:user_id, :constellation_id, :access_level)");
    foreach ($constellationIds as $cid) {
        $stmt->execute([':user_id' => $userId, ':constellation_id' => $cid, ':access_level' => $accessLevel]);
    }
}

/**
 * Add (or update the access level of) a SINGLE galaxy seat for a user, leaving
 * the user's other seats untouched. Use this for incremental seat additions
 * (e.g. auto-seating a galaxy's creator) so existing read_only seats are not
 * flattened to read_write the way db_set_user_constellations (whole-set replace)
 * would. Portable upsert (no MySQL ON DUPLICATE KEY) for the Postgres migration.
 */
function db_add_user_constellation(string $userId, int $constellationId, string $accessLevel = ENROLL_ACCESS_DEFAULT): void {
    db_ensure_user_constellations_access_level_column();
    if (!in_array($accessLevel, ENROLL_ACCESS_LEVELS, true)) {
        $accessLevel = ENROLL_ACCESS_DEFAULT;
    }
    $pdo = getDB();
    $chk = $pdo->prepare("SELECT 1 FROM user_constellations WHERE user_id = :u AND constellation_id = :c LIMIT 1");
    $chk->execute([':u' => $userId, ':c' => $constellationId]);
    if ($chk->fetchColumn()) {
        $pdo->prepare("UPDATE user_constellations SET access_level = :lvl WHERE user_id = :u AND constellation_id = :c")
            ->execute([':lvl' => $accessLevel, ':u' => $userId, ':c' => $constellationId]);
    } else {
        $pdo->prepare("INSERT INTO user_constellations (user_id, constellation_id, access_level) VALUES (:u, :c, :lvl)")
            ->execute([':u' => $userId, ':c' => $constellationId, ':lvl' => $accessLevel]);
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
 * Safe error descriptor for logging a DB exception on the users / token / auth
 * tables, where the raw driver message can incidentally echo a column value
 * (e.g. "Duplicate entry 'someone@example.com' for key 'email'" or a reset
 * token), leaking PII into error_log. Returns the SQLSTATE when present plus the
 * exception class, never the driver message. Use this instead of $e->getMessage()
 * for catch blocks around INSERT/UPDATE on those tables. DDL/schema (db_ensure_*)
 * catch blocks keep the full message: a CREATE/ALTER error carries no row value.
 */
function db_safe_error_descriptor(Throwable $e): string {
    $code = $e->getCode();
    $sqlstate = (is_string($code) && $code !== '') ? $code : (is_int($code) && $code !== 0 ? (string)$code : 'unknown');
    return 'SQLSTATE ' . $sqlstate . ' (' . get_class($e) . ')';
}

/**
 * Create admin user (type 2). Returns null on success, error message string on failure.
 */
function createAdminUser(PDO $pdo, string $email, string $password, string $firstname, ?string $lastname, ?string $pronouns = null): ?string {
    try {
        db_ensure_users_account_columns();
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
            INSERT INTO users (id, email, password, firstname, lastname, pronouns, type)
            VALUES (:id, :email, :password, :firstname, :lastname, :pronouns, 2)
        ");
        $stmt->execute([
            ':id' => $userId,
            ':email' => $email,
            ':password' => $hash,
            ':firstname' => $firstname,
            ':lastname' => ($lastname === null || $lastname === '') ? null : $lastname,
            ':pronouns' => ($pronouns === null || $pronouns === '') ? null : $pronouns
        ]);
        return null;
    } catch (PDOException $e) {
        error_log('createAdminUser PDOException: ' . db_safe_error_descriptor($e));
        return 'Database error while creating user. Please try again.';
    }
}

/**
 * Create user (editor or admin). Returns null on success, error message on failure.
 * $hashedPassword must already be hashed (e.g. by auth hashPassword).
 */
function createUser(PDO $pdo, string $email, ?string $hashedPassword, string $firstname, ?string $lastname, int $type, ?string $pronouns = null, bool $vetted = true): ?string {
    try {
        db_ensure_users_account_columns();
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            return 'Email already exists';
        }
        $userId = 'user_' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("
            INSERT INTO users (id, email, password, firstname, lastname, pronouns, type, vetted)
            VALUES (:id, :email, :password, :firstname, :lastname, :pronouns, :type, :vetted)
        ");
        $stmt->execute([
            ':id' => $userId,
            ':email' => $email,
            ':password' => ($hashedPassword === null || $hashedPassword === '') ? null : $hashedPassword,
            ':firstname' => $firstname,
            ':lastname' => ($lastname === null || $lastname === '') ? null : $lastname,
            ':pronouns' => ($pronouns === null || $pronouns === '') ? null : $pronouns,
            ':type' => $type,
            ':vetted' => $vetted ? 1 : 0
        ]);
        return null;
    } catch (PDOException $e) {
        error_log('createUser PDOException: ' . db_safe_error_descriptor($e));
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
 * Slugify a name into a constellation slug that is guaranteed free, appending
 * "-2", "-3", ... until no existing constellation holds it. The slug column is
 * UNIQUE, so two galaxies sharing a name (two editors named "Alex", or one
 * editor who enrolled twice) would otherwise collide and the INSERT would throw.
 * Whatever the editor put as their name is enough; this makes creation succeed.
 */
function db_unique_constellation_slug(string $name): string {
    $base = db_slugify($name);
    if ($base === '') {
        $base = 'galaxy';
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT 1 FROM constellations WHERE slug = :s LIMIT 1");
    $slug = $base;
    $n = 1;
    while (true) {
        $stmt->execute([':s' => $slug]);
        if ($stmt->fetchColumn() === false) {
            return $slug;
        }
        $n++;
        $slug = $base . '-' . $n;
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
    $stmt = $pdo->query("SELECT id, name, tagline, slug, theme, import_source, created_at, updated_at FROM constellations WHERE type = 'galaxy' ORDER BY id");
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
    $stmt = $pdo->prepare("SELECT id, name, slug, theme FROM constellations WHERE name LIKE :p AND type = 'galaxy' ORDER BY id");
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
        $ins = $pdo->prepare("INSERT INTO galaxy_tags (constellation_id, tag_slug, tag_label, created_by) VALUES (:cid, :slug, :label, :created_by) ON CONFLICT (constellation_id, tag_slug) DO NOTHING");
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
        WHERE gt.tag_slug = :s AND c.type = 'galaxy'
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
        JOIN constellations c ON c.id = m.member_id AND c.type = 'galaxy'
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
            $vstmt = $pdo->prepare("SELECT id FROM constellations WHERE id IN ($placeholders) AND type = 'galaxy'");
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
function db_create_cluster(string $name, string $tagline = '', ?string $slug = null, string $theme = 'cosmic', array $memberIds = [], bool $showGalaxyList = false, string $fuzzyMatching = 'inherit'): int {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $finalSlug = ($slug !== null && $slug !== '') ? $slug : db_slugify($name);
    $fm = in_array($fuzzyMatching, ['inherit', 'on', 'off'], true) ? $fuzzyMatching : 'inherit';
    $stmt = $pdo->prepare("INSERT INTO constellations (name, tagline, slug, theme, type, show_galaxy_list, fuzzy_keyword_matching) VALUES (:name, :tagline, :slug, :theme, 'cluster', :sgl, :fm) RETURNING id");
    $stmt->execute([
        ':name' => $name,
        ':tagline' => $tagline,
        ':slug' => $finalSlug,
        ':theme' => $theme,
        ':sgl' => $showGalaxyList ? 1 : 0,
        ':fm' => $fm,
    ]);
    $clusterId = (int) $stmt->fetchColumn();
    if ($memberIds !== []) {
        db_set_cluster_members($clusterId, $memberIds);
    }
    return $clusterId;
}

/**
 * Find the cluster with this exact name, or create it. Used by editor
 * self-enrollment to gather every auto-created personal galaxy into a single
 * per-installation cluster (named after the subdomain, e.g. "[GRSJ306]")
 * without duplicating it on each enrolment. Matches only type='cluster' rows;
 * returns the lowest id on the (unexpected) chance of duplicates.
 */
function db_find_or_create_named_cluster(string $name): int {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM constellations WHERE name = :name AND type = 'cluster' ORDER BY id ASC LIMIT 1");
    $stmt->execute([':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    // Not found: create it. Under simultaneous enrolments (a whole class signing
    // up at once) two callers can reach here together; the UNIQUE slug means the
    // loser's INSERT throws. That used to bubble up and silently drop the
    // galaxy from the cluster. Treat a create failure as "someone else just
    // created it": re-select and return the now-existing row. Only re-throw if
    // it still isn't there (a genuine, non-race failure).
    try {
        return db_create_cluster($name, '', null, 'cosmic', [], false, 'inherit');
    } catch (Throwable $e) {
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        throw $e;
    }
}

/**
 * Add a single galaxy to a cluster, preserving the existing membership.
 * No-op when the member is already present, is the cluster itself, or is not a
 * galaxy. Appends at the next free position. (db_set_cluster_members replaces
 * the whole set; this incremental add is what enrolment needs.)
 */
function db_add_cluster_member(int $clusterId, int $memberId): void {
    db_ensure_constellations_type_and_cluster_members();
    if ($clusterId === $memberId) {
        return;
    }
    $pdo = getDB();
    $chk = $pdo->prepare("SELECT 1 FROM constellations WHERE id = :id AND type = 'galaxy'");
    $chk->execute([':id' => $memberId]);
    if ($chk->fetchColumn() === false) {
        return; // non-galaxy or unknown id
    }
    $ex = $pdo->prepare("SELECT 1 FROM galaxy_cluster_members WHERE cluster_id = :cid AND member_id = :mid");
    $ex->execute([':cid' => $clusterId, ':mid' => $memberId]);
    if ($ex->fetchColumn() !== false) {
        return; // already a member
    }
    $posStmt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM galaxy_cluster_members WHERE cluster_id = :cid");
    $posStmt->execute([':cid' => $clusterId]);
    $pos = (int)$posStmt->fetchColumn();
    // INSERT IGNORE: PRIMARY KEY (cluster_id, member_id) makes a concurrent add
    // of the same galaxy a harmless no-op rather than a thrown duplicate-key
    // error (the membership is already the desired end state).
    $ins = $pdo->prepare("INSERT INTO galaxy_cluster_members (cluster_id, member_id, position) VALUES (:cid, :mid, :pos) ON CONFLICT (cluster_id, member_id) DO NOTHING");
    $ins->execute([':cid' => $clusterId, ':mid' => $memberId, ':pos' => $pos]);
}

/**
 * Update a cluster's metadata. Members are passed separately via db_set_cluster_members.
 */
function db_update_cluster(int $id, string $name, string $tagline = '', ?string $slug = null, string $theme = 'cosmic', bool $showGalaxyList = false, string $fuzzyMatching = 'inherit'): void {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $finalSlug = ($slug !== null && $slug !== '') ? $slug : db_slugify($name);
    $fm = in_array($fuzzyMatching, ['inherit', 'on', 'off'], true) ? $fuzzyMatching : 'inherit';
    $stmt = $pdo->prepare("UPDATE constellations SET name = :name, tagline = :tagline, slug = :slug, theme = :theme, show_galaxy_list = :sgl, fuzzy_keyword_matching = :fm WHERE id = :id AND type = 'cluster'");
    $stmt->execute([
        ':id' => $id,
        ':name' => $name,
        ':tagline' => $tagline,
        ':slug' => $finalSlug,
        ':theme' => $theme,
        ':sgl' => $showGalaxyList ? 1 : 0,
        ':fm' => $fm,
    ]);
}

/**
 * Delete a cluster row. ON DELETE CASCADE on the members FK takes care of the junction.
 */
function db_delete_cluster(int $id): void {
    db_ensure_constellations_type_and_cluster_members();
    $pdo = getDB();
    $pdo->prepare("DELETE FROM constellations WHERE id = :id AND type = 'cluster'")->execute([':id' => $id]);
}

/**
 * List all clusters with their member counts (for the admin list view).
 *
 * @return list<array{id:int,name:string,tagline:string,slug:?string,theme:string,member_count:int,created_at:?string,updated_at:?string}>
 */
function db_get_clusters(): array {
    db_ensure_constellations_type_and_cluster_members();
    db_ensure_constellations_editors_enabled_column();
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.tagline, c.slug, c.theme, c.show_galaxy_list, c.fuzzy_keyword_matching, c.editors_enabled, c.created_at, c.updated_at,
               (SELECT COUNT(*) FROM galaxy_cluster_members m WHERE m.cluster_id = c.id) AS member_count
        FROM constellations c
        WHERE c.type = 'cluster'
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
            'fuzzy_keyword_matching' => (string)($r['fuzzy_keyword_matching'] ?? 'inherit'),
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

    $where = ["c.type = 'cluster'"];
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
        SELECT c.id, c.name, c.tagline, c.slug, c.theme, c.show_galaxy_list, c.fuzzy_keyword_matching,
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
            'fuzzy_keyword_matching' => (string) ($r['fuzzy_keyword_matching'] ?? 'inherit'),
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
    db_ensure_constellations_editors_enabled_column();
    db_ensure_constellations_tour_columns();
    $pdo = getDB();

    db_ensure_constellations_type_and_cluster_members();
    $where = ["c.type = 'galaxy'"];
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
               c.editors_enabled, c.created_at, c.updated_at,
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
        WHERE c.type = 'galaxy'
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
    db_ensure_constellations_editors_enabled_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT name, tagline, slug, theme, import_source, type, show_galaxy_list, fuzzy_keyword_matching, editors_enabled FROM constellations WHERE id = :id LIMIT 1");
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
        'fuzzy_keyword_matching' => (string)($row['fuzzy_keyword_matching'] ?? 'inherit'),
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
    $stmt = $pdo->prepare("SELECT id, name, tagline, slug, theme, import_source, type, show_galaxy_list, fuzzy_keyword_matching FROM constellations WHERE id IN ($place)");
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
            'fuzzy_keyword_matching' => (string)($row['fuzzy_keyword_matching'] ?? 'inherit'),
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
        DELETE FROM keywords k
        WHERE k.constellation_id = :cid
          AND NOT EXISTS (SELECT 1 FROM node_keywords nk WHERE nk.keyword_id = k.id)
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
    $stmt = $pdo->prepare("SELECT id, name, tagline, theme, type FROM constellations WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Fallback: check if any constellation name slugifies to this value
        $all = $pdo->query("SELECT id, name, tagline, slug, theme, type FROM constellations");
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

    $stmt = $pdo->prepare("INSERT INTO constellations (name, tagline, slug, theme, created_by) VALUES (:name, :tagline, :slug, :theme, :created_by) RETURNING id");
    $stmt->execute([
        ':name' => $name,
        ':tagline' => trim($tagline),
        ':slug' => trim($slug),
        ':theme' => $theme,
        ':created_by' => $createdBy,
    ]);
    return (int)$stmt->fetchColumn();
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
        $insertKw = $pdo->prepare("INSERT INTO keywords (constellation_id, keyword) VALUES (:cid, :kw) RETURNING id");

        while ($kwRow = $stmt->fetch()) {
            $insertKw->execute([':cid' => $newId, ':kw' => $kwRow['keyword']]);
            $oldToNewKeywordIds[$kwRow['id']] = (int)$insertKw->fetchColumn();
        }

        // 4. Duplicate Nodes
        $stmt = $pdo->prepare("SELECT * FROM nodes WHERE constellation_id = :sid");
        $stmt->execute([':sid' => $sourceId]);
        $nodes = $stmt->fetchAll();

        $insertNode = $pdo->prepare("
            INSERT INTO nodes (constellation_id, name, description, url, image_url, embed_code, audio_url, audio_autoplay, audio_loop, video_url, video_autoplay, node_type, target_constellation_id, is_accentuated, created_by, animation)
            VALUES (:cid, :name, :description, :url, :image_url, :embed_code, :audio_url, :audio_autoplay, :audio_loop, :video_url, :video_autoplay, :node_type, :target_constellation_id, :is_accentuated, :created_by, :animation)
            RETURNING id
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
            $newNodeId = (int)$insertNode->fetchColumn();

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
 * Set just the visual theme of a galaxy, leaving everything else untouched. Used
 * by editor self-enrollment to default auto-created personal galaxies to the
 * Abstract theme without disturbing name/slug/tagline.
 */
function db_set_constellation_theme(int $id, string $theme): void {
    getDB()->prepare("UPDATE constellations SET theme = :theme WHERE id = :id")->execute([
        ':theme' => $theme,
        ':id' => $id,
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
               related_nodes_enabled, show_2d_view, group_nodes, heavy_inertia, sound_theme
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
        'group_nodes' => (bool)$row['group_nodes'],
        'heavy_inertia' => (bool)$row['heavy_inertia'],
        'sound_theme' => (string)($row['sound_theme'] ?? 'default'),
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
                   related_nodes_enabled, show_2d_view, group_nodes, heavy_inertia, sound_theme
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
                'group_nodes' => (bool)$row['group_nodes'],
                'heavy_inertia' => (bool)$row['heavy_inertia'],
                'sound_theme' => (string)($row['sound_theme'] ?? 'default'),
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

    // sound_theme: validated against the known presets; anything else (or absent)
    // falls back to 'default', so partial callers never set an invalid preset.
    $soundTheme = (string)($config['sound_theme'] ?? 'default');
    if (!in_array($soundTheme, ['default', 'rhizome'], true)) {
        $soundTheme = 'default';
    }

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
            show_2d_view = :show_2d_view,
            group_nodes = :group_nodes,
            heavy_inertia = :heavy_inertia,
            sound_theme = :sound_theme
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
        // Default TRUE when the caller omits the key, matching the column default,
        // so partial callers (e.g. personal-galaxy defaults) never flip it off.
        ':group_nodes' => (!array_key_exists('group_nodes', $config) || !empty($config['group_nodes'])) ? 1 : 0,
        // Defaults FALSE when the caller omits the key (matching the column
        // default), so partial callers never turn heavy inertia on by accident.
        ':heavy_inertia' => !empty($config['heavy_inertia']) ? 1 : 0,
        ':sound_theme' => $soundTheme,
        ':id' => $id,
    ]);
}

/**
 * Turn on the visitor-experience features we want every auto-created personal
 * galaxy (editor self-enrollment) to ship with: keyword chips, related
 * wormholes, the 2D view switch, and idle spotlight (spotlighting all nodes).
 *
 * A freshly created galaxy has all of these OFF by default; this is a focused
 * UPDATE of just those columns, so it never disturbs tour settings or anything
 * else on the row. Idempotent and safe to call once right after creation.
 */
function db_enable_personal_galaxy_default_features(int $id): void {
    db_ensure_constellations_tour_columns();
    getDB()->prepare("
        UPDATE constellations SET
            keyword_chips_enabled = TRUE,
            related_nodes_enabled = TRUE,
            show_2d_view = TRUE,
            idle_spotlight_enabled = TRUE,
            idle_spotlight_selection = 'all'
        WHERE id = :id
    ")->execute([':id' => $id]);
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
            $insertKw = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:kw, :cid) RETURNING id");
            $insertJunc = $pdo->prepare("INSERT INTO constellation_tour_keywords (constellation_id, keyword_id) VALUES (:cid, :kid)");
            foreach ($clean as $name) {
                $insertKw->execute([':kw' => $name, ':cid' => $clusterId]);
                $kid = (int)$insertKw->fetchColumn();
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
            // Structural delete: a referencing portal may live in a read-only
            // galaxy (a mirror that links into this one). Tearing it down with
            // the galaxy is legitimate, not an editorial mutation, so bypass.
            db_bulk_delete_nodes_by_ids(array_map(fn($r) => (int)$r['id'], $referencing), true);
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
        db_ensure_nodes_hotglue_columns();
        $ensured = true;
    }
    $pdo = getDB();

    // Admin or specific constellation requested
    if ($isAdmin && $constellationId === null) {
        $stmt = $pdo->query("
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords, n.use_image_as_node,
                   n.source_facet, n.media_type, n.source_created_at, n.media_mode, n.hotglue_page,
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
                   n.source_facet, n.media_type, n.source_created_at, n.media_mode, n.hotglue_page,
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
                   n.source_facet, n.media_type, n.source_created_at, n.media_mode, n.hotglue_page,
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
    db_ensure_nodes_hotglue_columns();
    $ids = array_values(array_unique(array_map('intval', $constellationIds)));
    if ($ids === []) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
               n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords, n.use_image_as_node,
               n.source_facet, n.media_type, n.source_created_at, n.media_mode, n.hotglue_page,
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
    db_ensure_nodes_hotglue_columns();
    $stmt = $pdo->prepare("
        SELECT n.id, n.name, n.description, n.url, n.image_url, n.image_attribution, n.icon_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.pdf_url, n.animation, n.created_at, n.updated_at, n.constellation_id,
               n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords,
               n.source_facet, n.media_type, n.source_created_at, n.media_mode, n.hotglue_page,
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
               n.source_facet, n.media_type, n.source_created_at, n.media_mode, n.hotglue_page,
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
        'keywords' => "(SELECT string_agg(k2.keyword::text, ',' ORDER BY k2.keyword) FROM node_keywords nk2 JOIN keywords k2 ON k2.id = nk2.keyword_id WHERE nk2.node_id = n.id)",
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
            'media_mode' => (isset($node['media_mode']) && (string)$node['media_mode'] === 'hotglue') ? 'hotglue' : 'classic',
            'hotglue_page' => isset($node['hotglue_page']) && $node['hotglue_page'] !== null && $node['hotglue_page'] !== '' ? (string)$node['hotglue_page'] : null,
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
        'media_mode' => (isset($node['media_mode']) && (string)$node['media_mode'] === 'hotglue') ? 'hotglue' : 'classic',
        'hotglue_page' => isset($node['hotglue_page']) && $node['hotglue_page'] !== null && $node['hotglue_page'] !== '' ? (string)$node['hotglue_page'] : null,
    ];
}

function db_save_node_keywords(int $nodeId, array $keywords, ?string $createdBy = null, bool $allowReadOnly = false): void {
    db_ensure_keywords_created_by_column();
    db_ensure_node_keywords_created_by_column();
    db_ensure_keywords_unaccent_index();
    $pdo = getDB();
    $nodeStmt = $pdo->prepare("SELECT constellation_id FROM nodes WHERE id = :id LIMIT 1");
    $nodeStmt->execute([':id' => $nodeId]);
    $nodeRow = $nodeStmt->fetch();
    $constellationId = $nodeRow ? (int)$nodeRow['constellation_id'] : db_get_default_constellation_id();
    db_assert_constellation_writable($constellationId, $allowReadOnly);
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
    // array_keys coerces numeric-string keywords ("1", "2") to int keys, so
    // force them back to strings. Otherwise the int flows into the param binds
    // and into mb_strtolower() below, which throws under strict_types on a
    // node carrying a purely-numeric keyword (e.g. a federation mirror pull).
    $names = array_map('strval', array_keys($namesSet));
    if ($names === []) return;

    try {
        // Step 1: upsert every keyword in a single statement. ON CONFLICT DO NOTHING
        // relies on the accent+case-insensitive expression unique index
        // unique_keyword_constellation (lower(immutable_unaccent(keyword)), constellation_id);
        // created_by lands on rows that win the insert race; existing keyword rows keep
        // their prior creator attribution. Duplicate conflict keys within this one
        // statement (e.g. 'Cafe' and 'cafe') are skipped, collapsing to a single row.
        $kwPlaceholders = implode(',', array_fill(0, count($names), '(?, ?, ?)'));
        $kwStmt = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id, created_by) VALUES $kwPlaceholders ON CONFLICT (lower(immutable_unaccent(keyword)), constellation_id) DO NOTHING");
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
        $jStmt = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id, created_by) VALUES $jPlaceholders ON CONFLICT (node_id, keyword_id) DO NOTHING");
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
        RETURNING id
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
    return (int)$stmt->fetchColumn();
}

function db_update_node(int $id, string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false, ?string $videoUrl = null, bool $videoAutoplay = true, bool $audioLoop = false, bool $showKeywords = false, ?string $iconUrl = null, ?string $imageAttribution = null, bool $useImageAsNode = false, ?string $pdfUrl = null, bool $allowReadOnly = false): void {
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_pdf_url_column();
    // Read-only guard: refuse to mutate a node that lives in a non-writable
    // galaxy, and refuse to move one into a non-writable galaxy. Internal
    // writers (Mocambos re-sync) pass allowReadOnly: true.
    if (!$allowReadOnly) {
        $currentConstellationId = db_get_node_constellation_id($id);
        if ($currentConstellationId !== null) {
            db_assert_constellation_writable($currentConstellationId, false);
        }
        if ($constellationId !== null) {
            db_assert_constellation_writable($constellationId, false);
        }
    }
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
    // Both ends mutate: nodes leave the source galaxy and land in the target.
    db_assert_constellation_writable($constellationId, false);
    db_assert_constellation_writable($targetConstellationId, false);
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
    db_assert_constellation_writable($constellationId, false);
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
function db_bulk_delete_nodes_by_ids(array $ids, bool $allowReadOnly = false): int {
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));
    if ($ids === []) return 0;
    $pdo = getDB();
    $place = implode(',', array_fill(0, count($ids), '?'));

    // Read-only guard: refuse if ANY target node lives in a non-writable
    // galaxy. Internal teardown writers (unmirror, structural galaxy delete,
    // Mocambos sync) pass allowReadOnly: true.
    if (!$allowReadOnly) {
        db_ensure_constellations_import_source_column();
        db_ensure_federation_attribution_columns();
        $guard = $pdo->prepare("
            SELECT 1 FROM nodes n
            INNER JOIN constellations c ON c.id = n.constellation_id
            WHERE n.id IN ($place)
              AND ((c.import_source IS NOT NULL AND c.import_source <> '')
                   OR c.read_only = TRUE
                   OR c.mirrored_from_peer_id IS NOT NULL)
            LIMIT 1
        ");
        $guard->execute($ids);
        if ($guard->fetchColumn()) {
            throw new RuntimeException('constellation_read_only');
        }
    }

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
    db_ensure_keywords_unaccent_index();
    $pdo = getDB();
    // On the duplicate path, the no-op DO UPDATE (set created_by to its own current
    // value) lets RETURNING hand back the existing row's id while leaving the original
    // creator untouched (we never overwrite an earlier creator with a later editor).
    // Conflict is the accent+case-insensitive expression unique index.
    $stmt = $pdo->prepare("
        INSERT INTO keywords (keyword, constellation_id, created_by) VALUES (:keyword, :constellation_id, :created_by)
        ON CONFLICT (lower(immutable_unaccent(keyword)), constellation_id) DO UPDATE SET created_by = keywords.created_by
        RETURNING id
    ");
    $stmt->execute([
        ':keyword' => $keyword,
        ':constellation_id' => $constellationId,
        ':created_by' => $createdBy,
    ]);
    return (int)$stmt->fetchColumn();
}

function db_get_node_constellation_id(int $nodeId): ?int {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT constellation_id FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $nodeId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['constellation_id'] : null;
}

/**
 * The hotglue page base name for a node: the stored hotglue_page when set (e.g.
 * an imported self-hosted page), otherwise the default "node-<id>". This is the
 * single source of truth for the node <-> hotglue-page mapping; both the viewer
 * iframe and the editor build their /hg/ URLs from it.
 */
function db_node_hotglue_page(int $nodeId): string {
    db_ensure_nodes_hotglue_columns();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT hotglue_page FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $nodeId]);
    $v = $stmt->fetchColumn();
    $v = is_string($v) ? trim($v) : '';
    return $v !== '' ? $v : ('node-' . $nodeId);
}

/**
 * Set a node's media mode ('classic' | 'hotglue'). Honours the read-only galaxy
 * guard (throws constellation_read_only:<id> for imported/mirrored galaxies)
 * unless $allowReadOnly is true (internal/restore paths).
 */
function db_set_node_media_mode(int $nodeId, string $mode, bool $allowReadOnly = false): void {
    db_ensure_nodes_hotglue_columns();
    $mode = ($mode === 'hotglue') ? 'hotglue' : 'classic';
    $cid = db_get_node_constellation_id($nodeId);
    if ($cid !== null) {
        db_assert_constellation_writable($cid, $allowReadOnly);
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE nodes SET media_mode = :m WHERE id = :id");
    $stmt->execute([':m' => $mode, ':id' => $nodeId]);
}

// ---------------------------------------------------------------------------
// Standalone hotglue pages (hotglue_pages registry).
//
// These pages have their own identity and can exist with no wormhole. Edit
// access: the owner, any admin, or (when assigned) an editor with a seat on the
// assigned wormhole's galaxy. db_hotglue_page_user_can_edit is the single source
// of truth, shared by the API layer and the hotglue auth bridge.
// ---------------------------------------------------------------------------

function db_hotglue_page_get_by_id(int $id): ?array {
    db_ensure_hotglue_pages_table();
    $pdo = getDB();
    $st = $pdo->prepare("SELECT * FROM hotglue_pages WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    return $r ?: null;
}

function db_hotglue_page_get_by_slug(string $slug): ?array {
    db_ensure_hotglue_pages_table();
    $pdo = getDB();
    $st = $pdo->prepare("SELECT * FROM hotglue_pages WHERE slug = :s LIMIT 1");
    $st->execute([':s' => $slug]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * Create a standalone hotglue page. The slug is derived from the auto-increment
 * id ("page-<id>") so it is stable and collision-free; the content dir is
 * materialized lazily by hotglue's controller_edit the first time the owner
 * opens /hg/?page-<id>/edit. Returns the created row.
 */
function db_hotglue_page_create(string $title, ?string $ownerUserId): array {
    db_ensure_hotglue_pages_table();
    $pdo = getDB();
    $title = trim($title);
    if ($title === '') {
        $title = 'Untitled';
    }
    $owner = ($ownerUserId !== null && $ownerUserId !== '') ? $ownerUserId : null;
    // Insert with a temporary unique slug, then rewrite it from the new id.
    $tmp = 'pending-' . bin2hex(random_bytes(8));
    $insStmt = $pdo->prepare("INSERT INTO hotglue_pages (slug, title, owner_user_id) VALUES (:slug, :title, :owner) RETURNING id");
    $insStmt->execute([':slug' => $tmp, ':title' => $title, ':owner' => $owner]);
    $id = (int)$insStmt->fetchColumn();
    $slug = 'page-' . $id;
    $pdo->prepare("UPDATE hotglue_pages SET slug = :slug WHERE id = :id")->execute([':slug' => $slug, ':id' => $id]);
    return db_hotglue_page_get_by_id($id) ?? ['id' => $id, 'slug' => $slug, 'title' => $title, 'owner_user_id' => $owner, 'node_id' => null];
}

/**
 * The hotglue_pages row for a wormhole's page, creating it if absent so the
 * per-wormhole hotglue editor is registry-backed (Page Name works) exactly like
 * the standalone flow. Matches by node_id first, then by the node's effective
 * page slug (node-<id> or a custom hotglue_page); when creating, it keeps that
 * existing slug since the on-disk content already lives there.
 */
function db_hotglue_page_get_or_create_for_node(int $nodeId, ?string $ownerUserId): ?array {
    db_ensure_hotglue_pages_table();
    db_ensure_nodes_hotglue_columns();
    $pdo = getDB();
    $st = $pdo->prepare("SELECT * FROM hotglue_pages WHERE node_id = :n LIMIT 1");
    $st->execute([':n' => $nodeId]);
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    $slug = db_node_hotglue_page($nodeId); // node-<id> or a stored custom page name
    $existing = db_hotglue_page_get_by_slug($slug);
    if ($existing) {
        if ((int)($existing['node_id'] ?? 0) !== $nodeId) {
            $pdo->prepare("UPDATE hotglue_pages SET node_id = :n WHERE id = :id")->execute([':n' => $nodeId, ':id' => (int)$existing['id']]);
        }
        return db_hotglue_page_get_by_id((int)$existing['id']);
    }
    $node = db_get_node_by_id($nodeId);
    $title = ($node && trim((string)($node['name'] ?? '')) !== '') ? (string)$node['name'] : $slug;
    $owner = ($ownerUserId !== null && $ownerUserId !== '') ? $ownerUserId : null;
    $insStmt = $pdo->prepare("INSERT INTO hotglue_pages (slug, title, owner_user_id, node_id) VALUES (:s, :t, :o, :n) RETURNING id");
    $insStmt->execute([':s' => $slug, ':t' => $title, ':o' => $owner, ':n' => $nodeId]);
    return db_hotglue_page_get_by_id((int)$insStmt->fetchColumn());
}

function db_hotglue_page_rename(int $id, string $title): void {
    db_ensure_hotglue_pages_table();
    $pdo = getDB();
    $pdo->prepare("UPDATE hotglue_pages SET title = :t WHERE id = :id")->execute([':t' => trim($title), ':id' => $id]);
}

/**
 * Assign a hotglue page to a wormhole. Transactional: displaces any page already
 * on that node (clears its node_id, the page survives unassigned), clears the
 * pointer on this page's previous node (back to classic), points this page at
 * the node, and flips the node to media_mode='hotglue' + hotglue_page=<slug>.
 * Honours the target galaxy read-only guard. Returns the slug of the displaced
 * page (or null). Throws on a missing page/node or a read-only target.
 */
function db_hotglue_page_assign(int $pageId, int $nodeId, bool $allowReadOnly = false): ?string {
    db_ensure_hotglue_pages_table();
    db_ensure_nodes_hotglue_columns();
    $page = db_hotglue_page_get_by_id($pageId);
    if ($page === null) {
        throw new RuntimeException('hotglue_page_not_found');
    }
    $cid = db_get_node_constellation_id($nodeId);
    if ($cid === null) {
        throw new RuntimeException('node_not_found');
    }
    db_assert_constellation_writable($cid, $allowReadOnly);
    $pdo = getDB();
    $owned = !$pdo->inTransaction();
    if ($owned) $pdo->beginTransaction();
    try {
        $displaced = null;
        // A different page already on this node is bumped to unassigned.
        $st = $pdo->prepare("SELECT id, slug FROM hotglue_pages WHERE node_id = :n AND id <> :pid LIMIT 1");
        $st->execute([':n' => $nodeId, ':pid' => $pageId]);
        $other = $st->fetch();
        if ($other) {
            $displaced = (string)$other['slug'];
            $pdo->prepare("UPDATE hotglue_pages SET node_id = NULL WHERE id = :id")->execute([':id' => (int)$other['id']]);
        }
        // If this page was on a different node, that node reverts to classic.
        if ($page['node_id'] !== null && (int)$page['node_id'] !== $nodeId) {
            $pdo->prepare("UPDATE nodes SET media_mode='classic', hotglue_page=NULL WHERE id = :id")->execute([':id' => (int)$page['node_id']]);
        }
        $pdo->prepare("UPDATE hotglue_pages SET node_id = :n WHERE id = :id")->execute([':n' => $nodeId, ':id' => $pageId]);
        $pdo->prepare("UPDATE nodes SET media_mode='hotglue', hotglue_page = :slug WHERE id = :id")->execute([':slug' => $page['slug'], ':id' => $nodeId]);
        if ($owned) $pdo->commit();
        return $displaced;
    } catch (Throwable $e) {
        if ($owned && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Unassign a hotglue page from its wormhole. The wormhole reverts to classic
 * media (its prior classic settings are preserved on the node row); the page
 * survives unassigned. Honours the galaxy read-only guard.
 */
function db_hotglue_page_unassign(int $pageId, bool $allowReadOnly = false): void {
    db_ensure_hotglue_pages_table();
    $page = db_hotglue_page_get_by_id($pageId);
    if ($page === null || $page['node_id'] === null) {
        if ($page !== null) {
            getDB()->prepare("UPDATE hotglue_pages SET node_id = NULL WHERE id = :id")->execute([':id' => $pageId]);
        }
        return;
    }
    $nodeId = (int)$page['node_id'];
    // Validate (and trigger any first-call db_ensure_* DDL) BEFORE opening the
    // transaction: DDL implicitly commits in MySQL and would orphan the commit.
    $cid = db_get_node_constellation_id($nodeId);
    if ($cid !== null) {
        db_assert_constellation_writable($cid, $allowReadOnly);
    }
    $pdo = getDB();
    $owned = !$pdo->inTransaction();
    if ($owned) $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE nodes SET media_mode='classic', hotglue_page=NULL WHERE id = :id")->execute([':id' => $nodeId]);
        $pdo->prepare("UPDATE hotglue_pages SET node_id = NULL WHERE id = :id")->execute([':id' => $pageId]);
        if ($owned) $pdo->commit();
    } catch (Throwable $e) {
        if ($owned && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Delete a hotglue page row (unassigning it first so its wormhole reverts to
 * classic). The caller is responsible for removing the hg/content/<slug> tree
 * on disk.
 */
function db_hotglue_page_delete(int $pageId, bool $allowReadOnly = false): void {
    db_ensure_hotglue_pages_table();
    db_hotglue_page_unassign($pageId, $allowReadOnly);
    getDB()->prepare("DELETE FROM hotglue_pages WHERE id = :id")->execute([':id' => $pageId]);
}

/**
 * Pages visible to a user: an admin sees all; an editor sees pages they own
 * plus pages assigned to a wormhole in a galaxy they have a seat on. Rows are
 * hydrated with the assigned wormhole + galaxy for display.
 */
function db_hotglue_pages_list_for_user(?string $userId, bool $isAdmin): array {
    db_ensure_hotglue_pages_table();
    $pdo = getDB();
    $base = "
        SELECT hp.id, hp.slug, hp.title, hp.owner_user_id, hp.node_id, hp.created_at, hp.updated_at,
               n.name AS node_name, n.constellation_id AS node_constellation_id,
               c.name AS galaxy_name, c.slug AS galaxy_slug
        FROM hotglue_pages hp
        LEFT JOIN nodes n ON n.id = hp.node_id
        LEFT JOIN constellations c ON c.id = n.constellation_id
    ";
    if ($isAdmin) {
        return $pdo->query($base . " ORDER BY hp.updated_at DESC")->fetchAll() ?: [];
    }
    $st = $pdo->prepare($base . " WHERE hp.owner_user_id = :uid ORDER BY hp.updated_at DESC");
    $st->execute([':uid' => $userId]);
    $own = $st->fetchAll() ?: [];
    $allowed = array_column(db_get_constellations_for_user($userId, false), 'id');
    if (empty($allowed)) {
        return $own;
    }
    $in = implode(',', array_map('intval', $allowed));
    $assigned = $pdo->query($base . " WHERE n.constellation_id IN ($in) ORDER BY hp.updated_at DESC")->fetchAll() ?: [];
    $byId = [];
    foreach ($own as $r) { $byId[(int)$r['id']] = $r; }
    foreach ($assigned as $r) { $byId[(int)$r['id']] = $r; }
    return array_values($byId);
}

/**
 * Wormholes an editor may assign a hotglue page to: object nodes in galaxies the
 * user can write to (admins: all non-read-only galaxies). Returns [{id, name,
 * galaxy_id, galaxy_name}] ordered by galaxy then node, for the assignment
 * dropdown. Read-only (imported / mirrored) galaxies are excluded.
 */
function db_hotglue_assignable_wormholes(?string $userId, bool $isAdmin): array {
    $galaxies = db_get_constellations_for_user($userId, $isAdmin);
    $ids = [];
    foreach ($galaxies as $g) {
        $gid = (int)$g['id'];
        if ($gid > 0 && !db_constellation_is_readonly($gid)) {
            $ids[] = $gid;
        }
    }
    if (empty($ids)) {
        return [];
    }
    $pdo = getDB();
    $in = implode(',', array_map('intval', $ids));
    db_ensure_nodes_hotglue_columns();
    $rows = $pdo->query("
        SELECT n.id, n.name, n.constellation_id AS galaxy_id, c.name AS galaxy_name, n.media_mode
        FROM nodes n
        LEFT JOIN constellations c ON c.id = n.constellation_id
        WHERE n.constellation_id IN ($in) AND n.node_type = 'object'
        ORDER BY c.name, n.name, n.id
    ")->fetchAll();
    return $rows ?: [];
}

/**
 * Single source of truth for "may this user edit this page": admins always;
 * the owner always; otherwise only if the page is assigned to a wormhole in a
 * galaxy the editor holds a seat on. Read-only galaxy enforcement is layered on
 * top by the write paths (assign/bridge), not here.
 */
function db_hotglue_page_user_can_edit(array $page, ?string $userId, bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }
    if ($userId !== null && $userId !== '' && (string)($page['owner_user_id'] ?? '') === (string)$userId) {
        return true;
    }
    $nodeId = (isset($page['node_id']) && $page['node_id'] !== null) ? (int)$page['node_id'] : 0;
    if ($nodeId > 0) {
        $cid = db_get_node_constellation_id($nodeId);
        if ($cid !== null) {
            $allowed = array_column(db_get_constellations_for_user($userId, false), 'id');
            if (in_array($cid, $allowed, true)) {
                return true;
            }
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Wormhole templates (templates registry).
//
// A template captures the content/identity of a wormhole (the JSONB `data`
// column) so an editor can spin up new wormholes pre-filled from it. Templates
// are private per editor (owner_user_id); admins see all. When the source
// wormhole was hotglue, has_hotglue is set and a snapshot of its content dir
// lives at hg/content/template-<id> (the API layer owns the on-disk copy/clean,
// exactly like the hotglue_pages duplicate flow).
// ---------------------------------------------------------------------------

function db_template_get_by_id(int $id): ?array {
    db_ensure_templates_table();
    $pdo = getDB();
    $st = $pdo->prepare("SELECT * FROM templates WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['data'] = json_decode((string)($r['data'] ?? '{}'), true) ?: [];
    $r['has_hotglue'] = (bool)$r['has_hotglue'];
    return $r;
}

/**
 * Create a template. $data is the captured node field set (see api/templates.php
 * create_from_node); it is stored verbatim as JSONB. Returns the created row.
 */
function db_template_create(string $name, ?string $ownerUserId, array $data, bool $hasHotglue): array {
    db_ensure_templates_table();
    $pdo = getDB();
    $name = trim($name);
    if ($name === '') {
        $name = 'Untitled';
    }
    if (mb_strlen($name) > 255) {
        $name = mb_substr($name, 0, 255);
    }
    $owner = ($ownerUserId !== null && $ownerUserId !== '') ? $ownerUserId : null;
    $st = $pdo->prepare("INSERT INTO templates (name, owner_user_id, data, has_hotglue) VALUES (:n, :o, :d, :h) RETURNING id");
    $st->execute([
        ':n' => $name,
        ':o' => $owner,
        ':d' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ':h' => $hasHotglue ? 'true' : 'false',
    ]);
    $id = (int)$st->fetchColumn();
    return db_template_get_by_id($id) ?? ['id' => $id, 'name' => $name, 'owner_user_id' => $owner, 'data' => $data, 'has_hotglue' => $hasHotglue];
}

function db_template_rename(int $id, string $name): void {
    db_ensure_templates_table();
    $name = trim($name);
    if (mb_strlen($name) > 255) {
        $name = mb_substr($name, 0, 255);
    }
    getDB()->prepare("UPDATE templates SET name = :n, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute([':n' => $name, ':id' => $id]);
}

/** Delete a template row. The caller removes hg/content/template-<id> on disk. */
function db_template_delete(int $id): void {
    db_ensure_templates_table();
    getDB()->prepare("DELETE FROM templates WHERE id = :id")->execute([':id' => $id]);
}

/** Templates visible to a user: an admin sees all; an editor sees their own. */
function db_templates_list_for_user(?string $userId, bool $isAdmin): array {
    db_ensure_templates_table();
    $pdo = getDB();
    if ($isAdmin) {
        $rows = $pdo->query("SELECT * FROM templates ORDER BY updated_at DESC")->fetchAll() ?: [];
    } else {
        $st = $pdo->prepare("SELECT * FROM templates WHERE owner_user_id = :uid ORDER BY updated_at DESC");
        $st->execute([':uid' => $userId]);
        $rows = $st->fetchAll() ?: [];
    }
    foreach ($rows as &$r) {
        $r['data'] = json_decode((string)($r['data'] ?? '{}'), true) ?: [];
        $r['has_hotglue'] = (bool)$r['has_hotglue'];
    }
    unset($r);
    return $rows;
}

/** Single source of truth for "may this user edit this template": owner or admin. */
function db_template_user_can_edit(array $tpl, ?string $userId, bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }
    return $userId !== null && $userId !== '' && (string)($tpl['owner_user_id'] ?? '') === (string)$userId;
}

/**
 * True if a constellation is non-writable by editorial action: it is either a
 * bridge import (import_source set) or a federation mirror (read_only TRUE or
 * mirrored_from_peer_id set). Such a galaxy is owned upstream; local edits are
 * illegitimate and get clobbered on the next Mocambos sync or galaxy pull, so
 * the write APIs refuse them server-side (the editor UI already hides the
 * controls, but those are bypassable via direct API calls). One query.
 *
 * Legitimate internal writers (federation materialization, Mocambos sync,
 * unmirror, structural galaxy delete) populate or tear down these galaxies on
 * purpose; they opt out of the helper-level guard via the $allowReadOnly
 * parameter on the mutation helpers below. This predicate is the shared truth
 * the API boundary and those helpers both consult.
 */
function db_constellation_is_readonly(int $constellationId): bool {
    if ($constellationId <= 0) {
        return false;
    }
    db_ensure_constellations_import_source_column();
    db_ensure_federation_attribution_columns();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT 1 FROM constellations
        WHERE id = :id
          AND ((import_source IS NOT NULL AND import_source <> '')
               OR read_only = TRUE
               OR mirrored_from_peer_id IS NOT NULL)
        LIMIT 1
    ");
    $stmt->execute([':id' => $constellationId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Belt-and-suspenders guard for the node/keyword mutation helpers: throw if the
 * target constellation is read-only, unless the caller is a legitimate internal
 * writer that passed $allowReadOnly = true. Throws RuntimeException (the same
 * signal db_duplicate_node uses) so a future API caller that forgets the
 * boundary guard fails loudly rather than silently clobbering mirrored content.
 */
function db_assert_constellation_writable(int $constellationId, bool $allowReadOnly): void {
    if ($allowReadOnly) {
        return;
    }
    if (db_constellation_is_readonly($constellationId)) {
        throw new RuntimeException('constellation_read_only:' . $constellationId);
    }
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
            INSERT INTO node_keywords (node_id, keyword_id, created_by)
            SELECT node_id, :target, created_by
            FROM node_keywords
            WHERE keyword_id = :source
            ON CONFLICT (node_id, keyword_id) DO NOTHING
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
            INSERT INTO keyword_relations
                (keyword_a_id, keyword_b_id, anchor_a, anchor_b, note, created_by, created_at)
            VALUES (:a, :b, :aa, :ab, :n, :c, :ts)
            ON CONFLICT (keyword_a_id, keyword_b_id) DO NOTHING
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
function db_get_connections(?int $constellationId = null, bool $fuzzy = false): array {
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

    // When fuzzy matching is on, index by fuzzy cluster key (so "colonial" and
    // "colonialism" share a connection) instead of the raw keyword. The cluster
    // representative doubles as the human-readable label in shared_keywords.
    // Fuzzy only ever adds links; exact matches always survive (see keyword-fuzzy.php).
    if ($fuzzy) {
        $built = keyword_fuzzy_build_groups($nodeKeywords);
        $itemsByNode = $built['groups'];
    } else {
        $itemsByNode = $nodeKeywords;
    }

    // Build inverted index: key → list of node IDs that have it
    // This avoids the O(n²) pairwise comparison
    $keyToNodes = [];
    foreach ($itemsByNode as $nodeId => $items) {
        foreach ($items as $key) {
            $keyToNodes[(string)$key][] = (int)$nodeId;
        }
    }

    // Build connections from the inverted index
    // For each key, every pair of nodes sharing it gets a connection
    $pairShared = []; // "id1:id2" => [label, ...]
    foreach ($keyToNodes as $key => $ids) {
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $id1 = min($ids[$i], $ids[$j]);
                $id2 = max($ids[$i], $ids[$j]);
                $pairShared["{$id1}:{$id2}"][] = (string)$key;
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
        $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
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
    db_ensure_nodes_hotglue_columns();
    $nodeStmt = $pdo->prepare("
        SELECT id, name, description, url, image_url, image_attribution, icon_url,
               embed_code, audio_url, audio_autoplay, audio_loop,
               video_url, video_autoplay, pdf_url, animation,
               node_type, target_constellation_id, is_accentuated, show_keywords, use_image_as_node,
               source_facet, media_type, source_created_at, import_slug, created_by,
               media_mode, hotglue_page
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
    db_ensure_users_account_columns();
    $rows = $pdo->query("
        SELECT id, email, password, firstname, lastname, pronouns, type, date_created, date_last_login
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
function db_user_create_raw(string $id, string $email, string $passwordHash, string $firstname, ?string $lastname, int $type, ?string $dateCreated = null, ?string $pronouns = null): void {
    db_ensure_users_account_columns();
    $pdo = getDB();
    if ($dateCreated !== null && $dateCreated !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO users (id, email, password, firstname, lastname, pronouns, type, date_created)
            VALUES (:id, :email, :password, :firstname, :lastname, :pronouns, :type, :date_created)
        ");
        $stmt->execute([
            ':id' => $id, ':email' => $email, ':password' => $passwordHash,
            ':firstname' => $firstname,
            ':lastname' => ($lastname === null || $lastname === '') ? null : $lastname,
            ':pronouns' => ($pronouns === null || $pronouns === '') ? null : $pronouns,
            ':type' => $type,
            ':date_created' => $dateCreated,
        ]);
    } else {
        db_insert_user($id, $email, $passwordHash, $firstname, $lastname, $type, $pronouns);
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
    db_ensure_nodes_hotglue_columns();
    $pdo = getDB();
    // media_mode falls back to 'classic' for legacy dumps without the column.
    $mediaMode = (string)($node['media_mode'] ?? 'classic');
    if ($mediaMode !== 'hotglue') { $mediaMode = 'classic'; }
    $hotgluePage = isset($node['hotglue_page']) && $node['hotglue_page'] !== '' ? (string)$node['hotglue_page'] : null;
    $stmt = $pdo->prepare("
        INSERT INTO nodes (
            constellation_id, name, description, url,
            image_url, image_attribution, icon_url, embed_code,
            audio_url, audio_autoplay, audio_loop,
            video_url, video_autoplay, pdf_url, animation,
            node_type, target_constellation_id, is_accentuated, show_keywords,
            source_facet, media_type, source_created_at, import_slug, created_by,
            media_mode, hotglue_page
        ) VALUES (
            :constellation_id, :name, :description, :url,
            :image_url, :image_attribution, :icon_url, :embed_code,
            :audio_url, :audio_autoplay, :audio_loop,
            :video_url, :video_autoplay, :pdf_url, :animation,
            :node_type, :target_constellation_id, :is_accentuated, :show_keywords,
            :source_facet, :media_type, :source_created_at, :import_slug, :created_by,
            :media_mode, :hotglue_page
        )
        RETURNING id
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
        ':media_mode' => $mediaMode,
        ':hotglue_page' => $hotgluePage,
    ]);
    return (int)$stmt->fetchColumn();
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
    // Child-first delete order satisfies the FK constraints without disabling them.
    $pdo->exec("DELETE FROM node_keywords");
    $pdo->exec("DELETE FROM nodes");
    $pdo->exec("DELETE FROM keywords");
    $pdo->exec("DELETE FROM user_constellations");
    $pdo->exec("DELETE FROM constellations");
    $pdo->exec("DELETE FROM users");
    // Reset identity sequences so restored IDs start fresh
    $pdo->exec("ALTER TABLE constellations ALTER COLUMN id RESTART WITH 1");
    $pdo->exec("ALTER TABLE nodes ALTER COLUMN id RESTART WITH 1");
    $pdo->exec("ALTER TABLE keywords ALTER COLUMN id RESTART WITH 1");
    $pdo->exec("ALTER TABLE node_keywords ALTER COLUMN id RESTART WITH 1");

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
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                hostname VARCHAR(255) NOT NULL,
                url VARCHAR(512) NOT NULL,
                pluriverse_endpoint VARCHAR(512) NOT NULL,
                public_key BYTEA NOT NULL,
                previous_public_key BYTEA NULL,
                key_rotated_at TIMESTAMP NULL,
                rotation_reason VARCHAR(16) NULL CHECK (rotation_reason IN ('scheduled','operational','compromise')),
                label VARCHAR(255) NOT NULL,
                bridges JSONB NULL,
                source VARCHAR(16) NOT NULL DEFAULT 'manual' CHECK (source IN ('registry','manual')),
                source_detail VARCHAR(255) NULL,
                trust_state VARCHAR(16) NOT NULL DEFAULT 'discovered' CHECK (trust_state IN ('discovered','contacted','whitelisted','blocked')),
                has_active_whitelist BOOLEAN NOT NULL DEFAULT FALSE,
                local_nickname VARCHAR(255) NULL,
                local_blacklisted_reason TEXT NULL,
                last_seen_at TIMESTAMP NULL,
                health_status VARCHAR(16) NOT NULL DEFAULT 'unknown' CHECK (health_status IN ('up','degraded','down','unknown')),
                manual_added_by VARCHAR(255) NULL,
                manual_added_at TIMESTAMP NULL,
                manual_reauth_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_hostname UNIQUE (hostname)
            );
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
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                peer_id INT NOT NULL,
                api_key_hash BYTEA NOT NULL,
                direction VARCHAR(16) NOT NULL CHECK (direction IN ('they_call_us','we_call_them')),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                CONSTRAINT uniq_api_key_hash UNIQUE (api_key_hash),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_peer_direction ON peer_keys (peer_id, direction);
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
                peer_id INT NOT NULL,
                constellation_id INT NOT NULL,
                added_by VARCHAR(255) NULL,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (peer_id, constellation_id),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE,
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE
            );
        ");
        // Supporting index for the constellation_id FK (peer_id is already the leading
        // PK column; Postgres does not auto-index FK columns).
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gpw_constellation_id ON galaxy_publish_whitelist (constellation_id)");
    } catch (PDOException $e) {
        error_log('db_ensure_galaxy_publish_whitelist_table: ' . $e->getMessage());
    }
}

/**
 * Per-peer publish-revocation markers. When an operator removes a galaxy from a
 * peer's publish whitelist, we record (peer_id, slug) here so the peer's signed
 * revoked.json can tell the subscriber to DROP the mirror, distinct from a
 * benign disappearance (which fossilizes). Keyed by slug (not constellation_id)
 * because the subscriber knows only the remote slug, and the marker must outlive
 * a galaxy deletion. Sticky until the galaxy is re-offered to that peer.
 *
 * Spec: BACKLOG ^fed-revoke-vs-withdraw-diff; v10 § State-change propagation.
 */
function db_ensure_galaxy_publish_revocations_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS galaxy_publish_revocations (
                peer_id INT NOT NULL,
                slug VARCHAR(255) NOT NULL,
                revoked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (peer_id, slug),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE
            );
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_galaxy_publish_revocations_table: ' . $e->getMessage());
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
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                peer_id INT NOT NULL,
                remote_slug VARCHAR(255) NOT NULL,
                local_constellation_id INT NULL,
                last_synced_at TIMESTAMP NULL,
                last_content_hash VARCHAR(128) NULL,
                last_received_sequence BIGINT NULL,
                last_rejected_sequence BIGINT NULL,
                added_by VARCHAR(255) NULL,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                CONSTRAINT uniq_peer_remote UNIQUE (peer_id, remote_slug),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE,
                FOREIGN KEY (local_constellation_id) REFERENCES constellations(id) ON DELETE SET NULL
            );
        ");
        // Stage 5d-iv: distinguish "stopped pulling because origin withdrew /
        // was revoked" from "operator-paused" (both have is_active = FALSE).
        $pdo->exec("ALTER TABLE galaxy_subscriptions ADD COLUMN IF NOT EXISTS fossilized_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE galaxy_subscriptions ADD COLUMN IF NOT EXISTS fossilized_reason VARCHAR(100) NULL");
        // Supporting index for the local_constellation_id FK (peer_id is already the
        // leading column of uniq_peer_remote; Postgres does not auto-index FK columns).
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_galaxy_subscriptions_local_constellation_id ON galaxy_subscriptions (local_constellation_id)");
    } catch (PDOException $e) {
        error_log('db_ensure_galaxy_subscriptions_table: ' . $e->getMessage());
    }
}

/**
 * Stage 5d-v: per-peer galaxy-pull bookkeeping. One row per peer the
 * orchestrator has touched. `next_pull_at` is the backoff gate: NULL or past =
 * eligible, future = on cooldown. `consecutive_failures` drives the backoff
 * schedule (federation_galaxy_pull_backoff_seconds). On success the failure
 * state is reset; on failure the count bumps and next_pull_at advances.
 */
function db_ensure_peer_pull_state_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS peer_pull_state (
                peer_id INT PRIMARY KEY,
                last_pull_started_at TIMESTAMP NULL,
                last_pull_succeeded_at TIMESTAMP NULL,
                last_pull_failed_at TIMESTAMP NULL,
                next_pull_at TIMESTAMP NULL,
                consecutive_failures INT NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL,
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE
            );
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_peer_pull_state_table: ' . $e->getMessage());
    }
}

/**
 * Stage 5d-iv: inbound retractions this instance has honoured. Distinct from
 * retracted_galaxies (this instance's OWN outbound retractions). The signed
 * envelope is preserved so the admin surface can re-verify provenance, and so
 * subsequent re-subscription attempts to the same (peer, slug) can be refused.
 */
function db_ensure_remote_retractions_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS remote_retractions (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                peer_id INT NOT NULL,
                remote_slug VARCHAR(255) NOT NULL,
                retracted_at TIMESTAMP NOT NULL,
                reason TEXT NULL,
                retraction_jws TEXT NOT NULL,
                honored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_peer_slug UNIQUE (peer_id, remote_slug),
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE
            );
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_remote_retractions_table: ' . $e->getMessage());
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
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                constellation_id INT NULL,
                slug VARCHAR(255) NOT NULL,
                retracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                retracted_by VARCHAR(255) NULL,
                reason TEXT NULL,
                CONSTRAINT uniq_retracted_slug UNIQUE (slug),
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE SET NULL
            );
        ");
        // Stage 5c: the cached origin-signed retraction envelope (JWS Compact),
        // served verbatim from /galaxies/{slug}.retracted and retracted.json.
        $pdo->exec("ALTER TABLE retracted_galaxies ADD COLUMN IF NOT EXISTS retraction_jws TEXT NULL");
        // Supporting index for the constellation_id FK (Postgres does not auto-index FK columns).
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_retracted_galaxies_constellation_id ON retracted_galaxies (constellation_id)");
    } catch (PDOException $e) {
        error_log('db_ensure_retracted_galaxies_table: ' . $e->getMessage());
    }
}

// Stage 5 (galaxy publish): origin-side record of what this instance has
// published, one row per authored galaxy ever published. The slug is the
// immutable federation identifier; published_sequence is strict-monotonic and
// bumped on every re-publish; envelope_jws caches the signed envelope so reads
// don't re-sign per request. content_hash is the sha256 of the canonical
// payload (64 hex chars).
function db_ensure_published_galaxies_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS published_galaxies (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                constellation_id INT NOT NULL,
                slug VARCHAR(255) NOT NULL,
                published_sequence BIGINT NOT NULL DEFAULT 1,
                content_hash CHAR(64) NOT NULL,
                envelope_jws TEXT NOT NULL,
                is_current BOOLEAN NOT NULL DEFAULT TRUE,
                published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_published_slug UNIQUE (slug),
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_published_constellation ON published_galaxies (constellation_id);
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_published_galaxies_table: ' . $e->getMessage());
    }
}

// Stage 5: content-addressable media store, keyed by the blob's sha256. The
// bytes live on disk under UPLOAD_DIR/federation-media/; this table is the
// index. Used by both the origin (serving its own media) and the consumer
// (caching pulled media); identical media dedupes on the hash. ref_count
// tracks how many galaxies reference a blob for later GC.
function db_ensure_media_blobs_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS media_blobs (
                sha256 CHAR(64) PRIMARY KEY,
                storage_path VARCHAR(512) NOT NULL,
                mime VARCHAR(127) NULL,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                ref_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_media_blobs_table: ' . $e->getMessage());
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
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                peer_id INT NOT NULL,
                direction VARCHAR(16) NOT NULL CHECK (direction IN ('inbound','outbound')),
                thread_id VARCHAR(64) NOT NULL,
                message_type VARCHAR(32) NOT NULL,
                subject VARCHAR(255) NULL,
                body TEXT NULL,
                payload JSONB NULL,
                jws_envelope TEXT NOT NULL,
                is_read BOOLEAN NOT NULL DEFAULT FALSE,
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_peer_thread ON pluriverse_messages (peer_id, thread_id);
            CREATE INDEX IF NOT EXISTS idx_unread ON pluriverse_messages (peer_id, is_read);
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
                nonce BYTEA NOT NULL,
                seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (origin_host, nonce)
            );
            CREATE INDEX IF NOT EXISTS idx_seen_at ON seen_nonces (seen_at);
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
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                origin_host VARCHAR(255) NOT NULL,
                event_type VARCHAR(32) NOT NULL CHECK (event_type IN ('scheduled_rotation','operational_rotation','compromise','revocation')),
                occurred_at TIMESTAMP NOT NULL,
                signed_payload TEXT NOT NULL,
                received_via VARCHAR(8) NOT NULL CHECK (received_via IN ('push','poll')),
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_origin_occurred ON key_events (origin_host, occurred_at);
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
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                event_type VARCHAR(64) NOT NULL,
                actor VARCHAR(255) NULL,
                target VARCHAR(255) NULL,
                outcome VARCHAR(16) NOT NULL CHECK (outcome IN ('success','failure','warning')),
                details_summary VARCHAR(1024) NULL,
                ip_hash BYTEA NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_event_type ON pluriverse_log (event_type, created_at);
            CREATE INDEX IF NOT EXISTS idx_actor ON pluriverse_log (actor, created_at);
        ");
        // LIKE-copy needs the source table to exist; idempotent via IF NOT EXISTS.
        $pdo->exec("CREATE TABLE IF NOT EXISTS pluriverse_log_archive (LIKE pluriverse_log INCLUDING ALL)");
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
        $constraint = function(string $table, string $name) use ($pdo): bool {
            $stmt = $pdo->prepare("
                SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = current_schema() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n
                LIMIT 1
            ");
            $stmt->execute([':t' => $table, ':n' => $name]);
            return (bool)$stmt->fetchColumn();
        };

        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS mirrored_from_peer_id INT NULL DEFAULT NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_constellations_mirrored_from_peer ON constellations (mirrored_from_peer_id)");
        if (!$constraint('constellations', 'fk_constellations_mirrored_from_peer')) {
            try {
                $pdo->exec("ALTER TABLE constellations
                    ADD CONSTRAINT fk_constellations_mirrored_from_peer
                        FOREIGN KEY (mirrored_from_peer_id) REFERENCES peers(id) ON DELETE CASCADE");
            } catch (PDOException $e) {
                error_log('db_ensure_federation_attribution_columns: FK add skipped: ' . $e->getMessage());
            }
        }
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS read_only BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE constellations ADD COLUMN IF NOT EXISTS source_attribution JSONB NULL DEFAULT NULL");

        foreach (['nodes', 'keywords', 'node_keywords', 'keyword_relations'] as $table) {
            $pdo->exec("ALTER TABLE \"$table\"
                ADD COLUMN IF NOT EXISTS author_attribution_text VARCHAR(255) NULL DEFAULT NULL");
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
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                entry_type VARCHAR(16) NOT NULL CHECK (entry_type IN ('hostname','ip','domain')),
                entry_value VARCHAR(255) NOT NULL,
                reason TEXT NULL,
                added_by VARCHAR(255) NULL,
                added_at TIMESTAMP NOT NULL,
                pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_entry UNIQUE (entry_type, entry_value)
            );
            CREATE INDEX IF NOT EXISTS idx_entry_value ON pluriverse_blacklist (entry_value);
        ");
        $col = function(string $colName) use ($pdo): bool {
            $s = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = current_schema()
                                AND TABLE_NAME = 'pluriverse_blacklist'
                                AND COLUMN_NAME = :c LIMIT 1");
            $s->execute([':c' => $colName]);
            return (bool)$s->fetchColumn();
        };
        if ($col('entity_type')) {
            $pdo->exec("ALTER TABLE pluriverse_blacklist
                        RENAME COLUMN entity_type TO entry_type");
        }
        if ($col('entity_value')) {
            $pdo->exec("ALTER TABLE pluriverse_blacklist
                        RENAME COLUMN entity_value TO entry_value");
        }
        $pdo->exec("ALTER TABLE pluriverse_blacklist ADD COLUMN IF NOT EXISTS added_by VARCHAR(255) NULL");
        $idxOld = $pdo->prepare("SELECT 1 FROM pg_indexes
                                 WHERE schemaname = current_schema()
                                 AND tablename = 'pluriverse_blacklist'
                                 AND indexname = 'uniq_entity' LIMIT 1");
        $idxOld->execute();
        if ($idxOld->fetchColumn()) {
            try {
                $pdo->exec("DROP INDEX IF EXISTS uniq_entity");
                $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_entry ON pluriverse_blacklist (entry_type, entry_value)");
            } catch (PDOException $e) {
                error_log('db_ensure_pluriverse_blacklist_table: uniq index migrate skipped: ' . $e->getMessage());
            }
        }
        $idxOld2 = $pdo->prepare("SELECT 1 FROM pg_indexes
                                  WHERE schemaname = current_schema()
                                  AND tablename = 'pluriverse_blacklist'
                                  AND indexname = 'idx_entity_value' LIMIT 1");
        $idxOld2->execute();
        if ($idxOld2->fetchColumn()) {
            try {
                $pdo->exec("DROP INDEX IF EXISTS idx_entity_value");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_entry_value ON pluriverse_blacklist (entry_value)");
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
                last_seen_id BIGINT NOT NULL DEFAULT 0,
                last_etag VARCHAR(64) NULL,
                last_modified VARCHAR(64) NULL,
                last_pull_started_at TIMESTAMP NULL,
                last_pull_succeeded_at TIMESTAMP NULL,
                last_pull_failed_at TIMESTAMP NULL,
                last_error VARCHAR(1024) NULL,
                consecutive_failures INT NOT NULL DEFAULT 0,
                rows_processed_total BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
        $pdo->exec("ALTER TABLE pluriverse_pull_state ADD COLUMN IF NOT EXISTS last_etag VARCHAR(64) NULL");
        $pdo->exec("ALTER TABLE pluriverse_pull_state ADD COLUMN IF NOT EXISTS last_modified VARCHAR(64) NULL");
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_pull_state_table: ' . $e->getMessage());
    }
}

/**
 * Read the local peer list for the admin Pluriverse tab. Returns a flat
 * array sorted by source (registry first, manual last) then label; binary
 * key columns are converted to base64url and the fingerprint is derived
 * from the public key.
 *
 * @return list<array{
 *     id: int,
 *     hostname: string,
 *     url: string,
 *     label: string,
 *     source: string,
 *     source_detail: ?string,
 *     fingerprint: string,
 *     last_seen_at: ?string,
 *     health_status: string,
 *     trust_state: string,
 *     local_blacklisted_reason: ?string,
 *     manual_added_by: ?string,
 *     manual_added_at: ?string,
 *     bridges: array<int,mixed>
 * }>
 */
function db_get_local_peers(): array {
    db_ensure_peers_table();
    $pdo = getDB();
    $rows = $pdo->query("
        SELECT id, hostname, url, label, source, source_detail,
               encode(public_key, 'hex') AS public_key, last_seen_at, health_status, trust_state,
               local_blacklisted_reason, manual_added_by, manual_added_at, bridges
        FROM peers
        ORDER BY (source = 'manual') ASC, label ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $pk = $r['public_key'] !== null && $r['public_key'] !== '' ? hex2bin((string)$r['public_key']) : '';
        $fp = strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? rtrim(strtr(base64_encode(substr(hash('sha256', $pk, true), 0, 16)), '+/', '-_'), '=')
            : '';
        $bridges = [];
        if (!empty($r['bridges'])) {
            $decoded = json_decode((string)$r['bridges'], true);
            if (is_array($decoded)) $bridges = $decoded;
        }
        $out[] = [
            'id' => (int)$r['id'],
            'hostname' => (string)$r['hostname'],
            'url' => (string)$r['url'],
            'label' => (string)$r['label'],
            'source' => (string)$r['source'],
            'source_detail' => $r['source_detail'] !== null ? (string)$r['source_detail'] : null,
            'fingerprint' => $fp,
            'last_seen_at' => $r['last_seen_at'] !== null ? (string)$r['last_seen_at'] : null,
            'health_status' => (string)$r['health_status'],
            'trust_state' => (string)$r['trust_state'],
            'local_blacklisted_reason' => $r['local_blacklisted_reason'] !== null ? (string)$r['local_blacklisted_reason'] : null,
            'manual_added_by' => $r['manual_added_by'] !== null ? (string)$r['manual_added_by'] : null,
            'manual_added_at' => $r['manual_added_at'] !== null ? (string)$r['manual_added_at'] : null,
            'bridges' => $bridges,
        ];
    }
    return $out;
}

/**
 * Authored galaxies for the publish-whitelist editor (4f). A galaxy is
 * authored iff it was created locally — i.e. mirrored_from_peer_id IS NULL.
 * Mirrored galaxies cannot be published onward; they're someone else's
 * authoritative content.
 *
 * @return list<array{id:int,name:string,slug:string}>
 */
function db_get_authored_galaxies(): array {
    db_ensure_federation_attribution_columns();
    $pdo = getDB();
    $rows = $pdo->query("
        SELECT id, name, slug
        FROM constellations
        WHERE type = 'galaxy' AND mirrored_from_peer_id IS NULL
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'slug' => (string)$r['slug'],
        ];
    }
    return $out;
}

/**
 * Current publish-whitelist for one peer. Returns the list of authored
 * constellation IDs we are willing to publish to that peer. Used by the
 * admin Whitelist editor (4f) to pre-check the boxes; mirrored constellations
 * are filtered out defensively even though writes refuse them.
 *
 * @return list<int>
 */
function db_get_peer_publish_whitelist(int $peerId): array {
    db_ensure_galaxy_publish_whitelist_table();
    db_ensure_federation_attribution_columns();
    $stmt = getDB()->prepare("
        SELECT w.constellation_id
        FROM galaxy_publish_whitelist w
        JOIN constellations c ON c.id = w.constellation_id
        WHERE w.peer_id = :p AND c.mirrored_from_peer_id IS NULL
        ORDER BY c.name
    ");
    $stmt->execute([':p' => $peerId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Active subscriptions for one peer. The editor lists these and lets the
 * admin add or remove rows. local_constellation_id is NULL until stage 5
 * starts mirroring; until then, only remote_slug is meaningful.
 *
 * @return list<array{id:int,remote_slug:string,local_constellation_id:?int,last_synced_at:?string,is_active:bool}>
 */
function db_get_peer_subscriptions(int $peerId): array {
    db_ensure_galaxy_subscriptions_table();
    $stmt = getDB()->prepare("
        SELECT id, remote_slug, local_constellation_id, last_synced_at, is_active
        FROM galaxy_subscriptions
        WHERE peer_id = :p
        ORDER BY is_active DESC, remote_slug ASC
    ");
    $stmt->execute([':p' => $peerId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'remote_slug' => (string)$r['remote_slug'],
            'local_constellation_id' => $r['local_constellation_id'] !== null ? (int)$r['local_constellation_id'] : null,
            'last_synced_at' => $r['last_synced_at'] !== null ? (string)$r['last_synced_at'] : null,
            'is_active' => (bool)$r['is_active'],
        ];
    }
    return $out;
}

/**
 * Recompute peers.has_active_whitelist for one peer. The flag is TRUE iff
 * either side carries at least one row pointing at the peer (publish OR
 * active subscription). Stage 4 callers (the three admin handlers) drive
 * this after every mutation so the flag stays consistent without relying
 * on triggers.
 */
function db_recompute_peer_active_whitelist(int $peerId): void {
    db_ensure_peers_table();
    db_ensure_galaxy_publish_whitelist_table();
    db_ensure_galaxy_subscriptions_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT
            EXISTS(SELECT 1 FROM galaxy_publish_whitelist WHERE peer_id = :p1) AS has_pub,
            EXISTS(SELECT 1 FROM galaxy_subscriptions WHERE peer_id = :p2 AND is_active = TRUE) AS has_sub
    ");
    $stmt->execute([':p1' => $peerId, ':p2' => $peerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['has_pub' => 0, 'has_sub' => 0];
    $has = ((int)$row['has_pub'] === 1) || ((int)$row['has_sub'] === 1);
    $upd = $pdo->prepare("UPDATE peers SET has_active_whitelist = :v WHERE id = :p");
    $upd->execute([':v' => $has ? 1 : 0, ':p' => $peerId]);
}

/**
 * Replace the publish-whitelist for one peer with the given set of
 * authored-galaxy IDs. Transactional diff: rows for IDs no longer in the
 * set are deleted; new IDs are inserted; existing rows untouched. Refuses
 * any constellation that isn't an authored galaxy (mirrored or wrong type)
 * by returning ['ok'=>false,'reason'=>'mirrored_in_publish_set'] without
 * changing state.
 *
 * @param list<int> $constellationIds
 * @return array{ok:bool,reason?:string,added:int,removed:int}
 */
function db_set_peer_publish_whitelist(int $peerId, array $constellationIds, ?string $adminActor): array {
    db_ensure_peers_table();
    db_ensure_galaxy_publish_whitelist_table();
    db_ensure_galaxy_publish_revocations_table();
    db_ensure_federation_attribution_columns();
    $pdo = getDB();

    $exists = $pdo->prepare("SELECT id FROM peers WHERE id = :p LIMIT 1");
    $exists->execute([':p' => $peerId]);
    if ($exists->fetchColumn() === false) {
        return ['ok' => false, 'reason' => 'unknown_peer', 'added' => 0, 'removed' => 0];
    }

    $clean = [];
    foreach ($constellationIds as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) $clean[$cid] = true;
    }
    $clean = array_keys($clean);

    if ($clean !== []) {
        $place = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $pdo->prepare("
            SELECT id FROM constellations
            WHERE id IN ($place) AND type = 'galaxy' AND mirrored_from_peer_id IS NULL
        ");
        $stmt->execute($clean);
        $allowed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($allowed) !== count($clean)) {
            return ['ok' => false, 'reason' => 'mirrored_in_publish_set', 'added' => 0, 'removed' => 0];
        }
    }

    $existingStmt = $pdo->prepare("SELECT constellation_id FROM galaxy_publish_whitelist WHERE peer_id = :p");
    $existingStmt->execute([':p' => $peerId]);
    $existing = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

    $toAdd = array_values(array_diff($clean, $existing));
    $toRemove = array_values(array_diff($existing, $clean));

    if ($toAdd === [] && $toRemove === []) {
        db_recompute_peer_active_whitelist($peerId);
        return ['ok' => true, 'added' => 0, 'removed' => 0];
    }

    $pdo->beginTransaction();
    try {
        if ($toRemove !== []) {
            $place = implode(',', array_fill(0, count($toRemove), '?'));
            $del = $pdo->prepare("DELETE FROM galaxy_publish_whitelist WHERE peer_id = ? AND constellation_id IN ($place)");
            $del->execute(array_merge([$peerId], $toRemove));
        }
        if ($toAdd !== []) {
            $ins = $pdo->prepare("INSERT INTO galaxy_publish_whitelist (peer_id, constellation_id, added_by) VALUES (:p, :c, :a) ON CONFLICT (peer_id, constellation_id) DO NOTHING");
            foreach ($toAdd as $cid) {
                $ins->execute([':p' => $peerId, ':c' => $cid, ':a' => $adminActor]);
            }
        }
        // Per-peer publish revocations: removing a galaxy from this peer's
        // whitelist records a revocation marker (by slug) so the peer's signed
        // revoked.json tells the subscriber to DROP the mirror rather than
        // fossilize it. Re-offering the galaxy rescinds the marker. Sticky
        // otherwise (survives a later galaxy deletion).
        if ($toRemove !== []) {
            $place = implode(',', array_fill(0, count($toRemove), '?'));
            $slugStmt = $pdo->prepare("SELECT slug FROM constellations WHERE id IN ($place) AND slug IS NOT NULL AND slug <> ''");
            $slugStmt->execute($toRemove);
            $revoke = $pdo->prepare("INSERT INTO galaxy_publish_revocations (peer_id, slug) VALUES (:p, :s)
                                     ON CONFLICT (peer_id, slug) DO UPDATE SET revoked_at = CURRENT_TIMESTAMP");
            foreach ($slugStmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $revoke->execute([':p' => $peerId, ':s' => (string)$slug]);
            }
        }
        if ($toAdd !== []) {
            $place = implode(',', array_fill(0, count($toAdd), '?'));
            $slugStmt = $pdo->prepare("SELECT slug FROM constellations WHERE id IN ($place) AND slug IS NOT NULL AND slug <> ''");
            $slugStmt->execute($toAdd);
            $rescind = $pdo->prepare("DELETE FROM galaxy_publish_revocations WHERE peer_id = :p AND slug = :s");
            foreach ($slugStmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $rescind->execute([':p' => $peerId, ':s' => (string)$slug]);
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('db_set_peer_publish_whitelist: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'db_error', 'added' => 0, 'removed' => 0];
    }

    db_recompute_peer_active_whitelist($peerId);
    return ['ok' => true, 'added' => count($toAdd), 'removed' => count($toRemove)];
}

/**
 * Add (or reactivate) a subscription row for one peer + remote slug. If a
 * row with that (peer_id, remote_slug) already exists, flip its is_active
 * back to TRUE rather than failing — the editor's "remove" is soft, so
 * re-adding should restore. Returns reason='exists_active' when the row
 * was already active so the UI can show "no change".
 *
 * @return array{ok:bool,reason?:string,subscription_id?:int}
 */
function db_add_peer_subscription(int $peerId, string $remoteSlug, ?string $adminActor): array {
    db_ensure_peers_table();
    db_ensure_galaxy_subscriptions_table();
    $slug = trim($remoteSlug);
    if ($slug === '' || mb_strlen($slug) > 255) {
        return ['ok' => false, 'reason' => 'invalid_slug'];
    }
    $pdo = getDB();

    $peerStmt = $pdo->prepare("SELECT id FROM peers WHERE id = :p LIMIT 1");
    $peerStmt->execute([':p' => $peerId]);
    if ($peerStmt->fetchColumn() === false) {
        return ['ok' => false, 'reason' => 'unknown_peer'];
    }

    $find = $pdo->prepare("SELECT id, is_active FROM galaxy_subscriptions WHERE peer_id = :p AND remote_slug = :s LIMIT 1");
    $find->execute([':p' => $peerId, ':s' => $slug]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $subId = (int)$row['id'];
        if ((bool)$row['is_active'] === true) {
            return ['ok' => true, 'reason' => 'exists_active', 'subscription_id' => $subId];
        }
        $pdo->prepare("UPDATE galaxy_subscriptions SET is_active = TRUE WHERE id = :id")->execute([':id' => $subId]);
        db_recompute_peer_active_whitelist($peerId);
        return ['ok' => true, 'subscription_id' => $subId];
    }

    $ins = $pdo->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, added_by) VALUES (:p, :s, :a) RETURNING id");
    $ins->execute([':p' => $peerId, ':s' => $slug, ':a' => $adminActor]);
    $subId = (int)$ins->fetchColumn();
    db_recompute_peer_active_whitelist($peerId);
    return ['ok' => true, 'subscription_id' => $subId];
}

/**
 * Hard-delete a subscription row. peer_id is required and must match the
 * subscription's stored peer_id; that defends against a bare-id POST being
 * used to delete some other peer's row. After delete, the active-whitelist
 * flag for the affected peer is recomputed.
 *
 * @return array{ok:bool,reason?:string}
 */
function db_remove_peer_subscription(int $peerId, int $subscriptionId): array {
    db_ensure_galaxy_subscriptions_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT peer_id FROM galaxy_subscriptions WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $subscriptionId]);
    $rowPeer = $stmt->fetchColumn();
    if ($rowPeer === false) {
        return ['ok' => false, 'reason' => 'unknown_subscription'];
    }
    if ((int)$rowPeer !== $peerId) {
        return ['ok' => false, 'reason' => 'peer_mismatch'];
    }
    $pdo->prepare("DELETE FROM galaxy_subscriptions WHERE id = :id")->execute([':id' => $subscriptionId]);
    db_recompute_peer_active_whitelist($peerId);
    return ['ok' => true];
}

/**
 * Read the per-endpoint Pluriverse pull state. Used by the admin UI to
 * show "last refreshed at" stamps next to the local peer list.
 *
 * @return array<string, array{
 *     last_pull_succeeded_at: ?string,
 *     last_pull_failed_at: ?string,
 *     consecutive_failures: int,
 *     last_error: ?string
 * }>
 */
function db_get_pluriverse_pull_state_summary(): array {
    db_ensure_pluriverse_pull_state_table();
    $rows = getDB()->query("
        SELECT endpoint, last_pull_succeeded_at, last_pull_failed_at,
               consecutive_failures, last_error
        FROM pluriverse_pull_state
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['endpoint']] = [
            'last_pull_succeeded_at' => $r['last_pull_succeeded_at'] !== null ? (string)$r['last_pull_succeeded_at'] : null,
            'last_pull_failed_at' => $r['last_pull_failed_at'] !== null ? (string)$r['last_pull_failed_at'] : null,
            'consecutive_failures' => (int)$r['consecutive_failures'],
            'last_error' => $r['last_error'] !== null ? (string)$r['last_error'] : null,
        ];
    }
    return $out;
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
        $tables = getAllTables($pdo);
        foreach ($tables as $table) {
            try {
                // CASCADE drops dependent FK constraints, so table order does not matter.
                $pdo->exec("DROP TABLE IF EXISTS \"$table\" CASCADE");
                $dropped[] = $table;
            } catch (PDOException $e) {
                $errors[] = "Failed to drop table '$table': " . $e->getMessage();
            }
        }
    } catch (PDOException $e) {
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
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                operator_email VARCHAR(254) NOT NULL,
                label VARCHAR(255) NOT NULL,
                remote_instance_id INT NULL,
                remote_fingerprint VARCHAR(64) NULL,
                pluriverse_url VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','verified','published','rejected','blacklisted','withdrawn','expired','revoked','outdated')),
                last_polled_at TIMESTAMP NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_status ON pluriverse_applications (status, submitted_at);
        ");
        // 2026-05-25: the status set grows 'expired', then 'revoked'/'outdated'
        // to cover every Pluriverse-side admission_status the status-sync poll
        // can surface back. Refresh the CHECK so older installs accept the new
        // values. Idempotent: drop-if-exists then re-add.
        try {
            $pdo->exec("ALTER TABLE pluriverse_applications DROP CONSTRAINT IF EXISTS pluriverse_applications_status_check");
            $pdo->exec("ALTER TABLE pluriverse_applications ADD CONSTRAINT pluriverse_applications_status_check CHECK (status IN ('pending','verified','published','rejected','blacklisted','withdrawn','expired','revoked','outdated'))");
        } catch (PDOException $e) {
            error_log('db_ensure_pluriverse_applications_table: status check refresh skipped: ' . $e->getMessage());
        }
        // 2026-05-25: last_polled_at tracks the last status-sync round-trip
        // so the admin page can rate-limit polls to once per 5 min.
        $pdo->exec("ALTER TABLE pluriverse_applications ADD COLUMN IF NOT EXISTS last_polled_at TIMESTAMP NULL");
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
        WHERE status = 'pending' AND submitted_at < (NOW() - INTERVAL '24 hour')
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
        RETURNING id
    ");
    $stmt->execute([
        ':email' => $email,
        ':label' => $label,
        ':url' => $pluriverseUrl,
        ':rid' => $remoteId,
        ':fp' => $remoteFingerprint,
    ]);
    return (int)$stmt->fetchColumn();
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

// ---------------------------------------------------------------------------
// Stage 4a: schema gap-fill for the handshake state machine, coord-key cache,
// and outbound retry queue on pluriverse_messages.
//
// Three additions only. Application code that uses these tables (the HTTP-Sig
// verifier middleware, the handshake endpoint, the coord-key cache helpers,
// the retry dispatcher) lands in 4b-4d.
// ---------------------------------------------------------------------------

/**
 * Tiny key/value table for instance-level state that doesn't deserve its own
 * dedicated schema. Stage 4 uses three rows: the cached Pluriverse coordination
 * public key (current + previous slot + previous grace expiry). Future callers
 * may add their own keys without a migration. Keys are namespaced by convention
 * (e.g. 'pluriverse_coord_pub_current'); no enforcement.
 */
function db_ensure_system_meta_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_meta (
                meta_key VARCHAR(64) PRIMARY KEY,
                meta_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_system_meta_table: ' . $e->getMessage());
    }
}

function db_system_meta_get(string $key): ?string {
    db_ensure_system_meta_table();
    $stmt = getDB()->prepare("SELECT meta_value FROM system_meta WHERE meta_key = :k LIMIT 1");
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetchColumn();
    return $row === false ? null : (string)$row;
}

function db_system_meta_set(string $key, string $value): void {
    db_ensure_system_meta_table();
    $stmt = getDB()->prepare("
        INSERT INTO system_meta (meta_key, meta_value) VALUES (:k, :v)
        ON CONFLICT (meta_key) DO UPDATE SET meta_value = EXCLUDED.meta_value
    ");
    $stmt->execute([':k' => $key, ':v' => $value]);
}

/**
 * Operator-tunable scalar settings, each backed by system_meta with a config.php
 * constant as fallback. The DB value (when non-empty) wins; otherwise the
 * constant; otherwise ''. config.php stays the first-run seed and the home of
 * everything that cannot move (DB creds, filesystem paths). Mail SMTP settings
 * are a cohesive group handled separately as a JSON blob in inc/mail.php.
 *
 * Logical key => the config.php constant it falls back to (null = no constant).
 * system_meta rows are namespaced with a 'setting_' prefix.
 */
const INSTANCE_SETTING_FALLBACK = [
    'telaris_hostname' => 'TELARIS_HOSTNAME',
    'site_base_url'    => 'SITE_BASE_URL',
    'default_locale'   => null,
];

function instance_setting_get(string $key): string {
    $v = db_system_meta_get('setting_' . $key);
    if (is_string($v) && $v !== '') {
        return $v;
    }
    $const = INSTANCE_SETTING_FALLBACK[$key] ?? null;
    if ($const !== null && defined($const)) {
        $cv = constant($const);
        if (is_string($cv) && $cv !== '') {
            return $cv;
        }
    }
    return '';
}

function instance_setting_set(string $key, string $value): void {
    db_system_meta_set('setting_' . $key, trim($value));
}

function db_system_meta_delete(string $key): void {
    db_ensure_system_meta_table();
    $stmt = getDB()->prepare("DELETE FROM system_meta WHERE meta_key = :k");
    $stmt->execute([':k' => $key]);
}

// ---------------------------------------------------------------------------
// Editor self-enrollment config (stored as one JSON value in system_meta).
// ---------------------------------------------------------------------------

const AUTO_ENROLL_META_KEY = 'auto_enroll_config';

/**
 * Read the auto-enroll config, normalized to the canonical shape. Returns safe
 * defaults (enabled = false) when unset or unparseable, so a fresh instance is
 * closed to self-enrollment until an admin turns it on.
 *
 * @return array{enabled:bool,create_personal_galaxy:bool,naming_convention:string,domains:list<string>,galaxy_ids:list<int>,access_level:string,cap_enabled:bool,cap:int}
 */
function db_get_auto_enroll_config(): array {
    $raw = db_system_meta_get(AUTO_ENROLL_META_KEY);
    $decoded = [];
    if (is_string($raw) && $raw !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }
    return auto_enroll_normalize_config($decoded);
}

/** Validate + persist the auto-enroll config (normalized, JSON-encoded). */
function db_set_auto_enroll_config(array $config): void {
    $normalized = auto_enroll_normalize_config($config);
    db_system_meta_set(AUTO_ENROLL_META_KEY, json_encode($normalized, JSON_UNESCAPED_SLASHES));
}

/** Count self-enrolled editors not yet vetted (the population the cap limits). */
function db_count_unvetted_editors(): int {
    db_ensure_users_vetted_column();
    $pdo = getDB();
    // type = 1 is USER_TYPE_EDITOR (literal here to match this layer's convention,
    // e.g. hasAdminUser uses `type = 2`; the constant lives in utils/auth.php).
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE type = 1 AND vetted = 0");
    return (int)$stmt->fetchColumn();
}

/**
 * Is self-enrollment open right now? Enabled AND (no cap OR under cap). Reused by
 * both the main-view "Enroll as Editor" link (visibility) and the enroll endpoint
 * (server-side refusal); never trust the hidden link alone.
 */
function db_auto_enroll_is_open(): bool {
    $cfg = db_get_auto_enroll_config();
    if (!$cfg['enabled']) {
        return false;
    }
    $count = ($cfg['cap_enabled'] && $cfg['cap'] > 0) ? db_count_unvetted_editors() : 0;
    return auto_enroll_compute_open($cfg['enabled'], $cfg['cap_enabled'], $cfg['cap'], $count);
}

/**
 * Deferred personal-galaxy creation (naming_convention = user_choice). At
 * enrolment confirm we cannot name the galaxy, so we flag the user; the editor
 * surface shows a one-time "create your first galaxy" banner on next load.
 * Stored as a namespaced system_meta key.
 */
function db_set_pending_personal_galaxy(string $userId): void {
    if ($userId === '') return;
    db_system_meta_set('enroll_pending_galaxy:' . $userId, '1');
}

/**
 * Peek the pending-personal-galaxy flag without clearing it. The editor surface
 * uses this to keep showing the "create your first galaxy" prompt until the
 * editor actually creates one (which then consumes the flag via db_take_*).
 */
function db_has_pending_personal_galaxy(string $userId): bool {
    if ($userId === '') return false;
    return db_system_meta_get('enroll_pending_galaxy:' . $userId) === '1';
}

/** Read-and-clear the pending-personal-galaxy flag. Returns true if it was set. */
function db_take_pending_personal_galaxy(string $userId): bool {
    if ($userId === '') return false;
    $key = 'enroll_pending_galaxy:' . $userId;
    if (db_system_meta_get($key) === '1') {
        db_system_meta_delete($key);
        return true;
    }
    return false;
}

/**
 * "You were vetted, you can set a password now" in-app banner, shown once on the
 * editor's next load after an admin vets them (paired with the vetting email).
 */
function db_set_vetted_banner_pending(string $userId): void {
    if ($userId === '') return;
    db_system_meta_set('vetted_banner:' . $userId, '1');
}

/** Read-and-clear the vetted banner flag. Returns true if it was set. */
function db_take_vetted_banner_pending(string $userId): bool {
    if ($userId === '') return false;
    $key = 'vetted_banner:' . $userId;
    if (db_system_meta_get($key) === '1') {
        db_system_meta_delete($key);
        return true;
    }
    return false;
}

/**
 * Stage 4 handshake state machine. One row per handshake (lifecycle: initiated
 * → pending_their_response → accepted_awaiting_complete → complete, OR ending
 * in rejected / expired / cancelled). Distinct from pluriverse_messages, which
 * stores the JWS-wrapped message envelopes exchanged at each round; this table
 * holds the state and the foreign keys back to those messages.
 *
 * peer_id is NULL until the first reply lands, because the round-1 request is
 * addressed by hostname (the other side may not have us as a peer yet).
 */
function db_ensure_handshakes_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_peers_table();
    db_ensure_pluriverse_messages_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS handshakes (
                id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                peer_id INT NULL,
                remote_hostname VARCHAR(255) NOT NULL,
                initiator VARCHAR(8) NOT NULL CHECK (initiator IN ('us','them')),
                status VARCHAR(32) NOT NULL CHECK (status IN (
                    'pending_their_response',
                    'pending_our_response',
                    'accepted_awaiting_complete',
                    'complete',
                    'rejected',
                    'expired',
                    'cancelled'
                )),
                requested_galaxies_publish JSONB NULL,
                requested_galaxies_subscribe JSONB NULL,
                thread_id VARCHAR(64) NOT NULL,
                initial_message_id INT NULL,
                response_message_id INT NULL,
                complete_message_id INT NULL,
                reject_reason TEXT NULL,
                retry_attempts INT NOT NULL DEFAULT 0,
                next_retry_at TIMESTAMP NULL,
                last_retry_error TEXT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (peer_id) REFERENCES peers(id) ON DELETE SET NULL,
                FOREIGN KEY (initial_message_id) REFERENCES pluriverse_messages(id) ON DELETE SET NULL,
                FOREIGN KEY (response_message_id) REFERENCES pluriverse_messages(id) ON DELETE SET NULL,
                FOREIGN KEY (complete_message_id) REFERENCES pluriverse_messages(id) ON DELETE SET NULL
            );
            CREATE INDEX IF NOT EXISTS idx_status_retry ON handshakes (status, next_retry_at);
            CREATE INDEX IF NOT EXISTS idx_hostname ON handshakes (remote_hostname);
            CREATE INDEX IF NOT EXISTS idx_peer ON handshakes (peer_id);
            CREATE INDEX IF NOT EXISTS idx_thread ON handshakes (thread_id);
            CREATE INDEX IF NOT EXISTS idx_handshakes_initial_message_id ON handshakes (initial_message_id);
            CREATE INDEX IF NOT EXISTS idx_handshakes_response_message_id ON handshakes (response_message_id);
            CREATE INDEX IF NOT EXISTS idx_handshakes_complete_message_id ON handshakes (complete_message_id);
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_handshakes_table: ' . $e->getMessage());
    }
}

/**
 * Outbound retry queue on pluriverse_messages. Inbound rows + non-deliverable
 * outbound rows keep delivery_status = 'not_applicable'. Outbound rows that
 * need delivery start 'pending'; the cron-driven dispatcher (4d) walks
 * (delivery_status IN ('pending','failed') AND next_attempt_at <= NOW())
 * and POSTs to the recipient's /api/pluriverse/messages or handshake endpoint
 * with HTTP-Sig. On 2xx → 'delivered'. On retry exhaustion → 'given_up'.
 *
 * Additive ALTER, information_schema-guarded for idempotency.
 */
function db_ensure_pluriverse_messages_retry_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_pluriverse_messages_table();
    try {
        $pdo = getDB();
        $pdo->exec("ALTER TABLE pluriverse_messages
                    ADD COLUMN IF NOT EXISTS delivery_status
                    VARCHAR(16) NOT NULL DEFAULT 'not_applicable'
                    CHECK (delivery_status IN ('not_applicable','pending','delivered','failed','given_up'))");
        $pdo->exec("ALTER TABLE pluriverse_messages
                    ADD COLUMN IF NOT EXISTS attempt_count INT NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE pluriverse_messages
                    ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE pluriverse_messages
                    ADD COLUMN IF NOT EXISTS last_attempt_error TEXT NULL");
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_retry ON pluriverse_messages (delivery_status, next_attempt_at)");
        } catch (PDOException $e) {
            error_log('db_ensure_pluriverse_messages_retry_columns: idx skipped: ' . $e->getMessage());
        }
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_messages_retry_columns: ' . $e->getMessage());
    }
}
