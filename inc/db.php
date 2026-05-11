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
const PROJECT_INFO_KEYS = ['name', 'description', 'iframe_back_text', 'alert_message', 'edit_button_text', 'loading_text', 'back_button_text', 'system_online_text', 'reload_system_text', 'scan_system_text', 'clear_scan_text', 'systems_label_text', 'hyperlinks_label_text', 'initialize_auth_text', 'admin_label_text', 'logout_label_text', 'click_to_view_text', 'tap_to_view_text', 'open_portal_text', 'sound_label_text', 'sound_on_text', 'sound_off_text', 'launching_text', 'mission_active_text', 'go_text', 'breadcrumb_all_text', 'launch_button_text', 'no_results_text', 'items_label_text', 'other_label_text', 'galaxies_label_text', 'galaxy_count_singular_text', 'galaxy_count_plural_text', 'pdf_loading_text', 'pdf_rendering_text', 'pdf_pages_singular_text', 'pdf_pages_plural_text', 'pdf_open_text', 'pdf_download_text', 'pdf_error_load_text', 'pdf_error_open_text'];

/** Locales supported (one row per locale in project_info). */
const PROJECT_INFO_LOCALES = ['en', 'es', 'pt'];

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
 * expensive once Mocambos imports push the row count past tens of thousands.
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
 * Ensure the keyword-canvas tables exist. See `Polivoxia/Projects/Telaris/Keyword canvas — design.md`
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

/** Ensure nodes clustering columns exist (mucua_name, media_type, source_created_at). */
function db_ensure_nodes_clustering_columns(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $row = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'mucua_name'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE nodes ADD COLUMN mucua_name VARCHAR(255) NULL AFTER show_keywords, ADD COLUMN media_type VARCHAR(50) NULL AFTER mucua_name, ADD COLUMN source_created_at VARCHAR(30) NULL AFTER media_type");
        }
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
 * Returns [slug => ['id' => int, 'name' => string, 'description' => string, 'media_type' => string, 'mucua_name' => string, 'source_created_at' => string, 'keywords' => string[]]].
 */
function db_get_nodes_by_import_slug(int $constellationId): array {
    db_ensure_nodes_import_slug_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name, description, import_slug, media_type, mucua_name, source_created_at, url FROM nodes WHERE constellation_id = :cid AND import_slug IS NOT NULL");
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
            'mucua_name' => $node['mucua_name'] ?? '',
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
function db_set_node_clustering_metadata(int $nodeId, ?string $mucuaName, ?string $mediaType, ?string $sourceCreatedAt): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE nodes SET mucua_name = :mucua_name, media_type = :media_type, source_created_at = :source_created_at WHERE id = :id");
    $stmt->execute([
        ':id' => $nodeId,
        ':mucua_name' => $mucuaName,
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
    $keys = PROJECT_INFO_KEYS;
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
        
        $out = [];
        foreach (PROJECT_INFO_KEYS as $key) {
            $val = '';
            if ($row && isset($row[$key]) && (string)$row[$key] !== '') {
                $val = (string)$row[$key];
            } elseif ($enRow && isset($enRow[$key]) && (string)$enRow[$key] !== '') {
                $val = (string)$enRow[$key];
            } else {
                $val = $enDefault[$key] ?? '';
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
function db_set_tags_for_galaxy(int $constellationId, array $labels): void {
    db_ensure_galaxy_tags_table();
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM galaxy_tags WHERE constellation_id = :cid");
        $del->execute([':cid' => $constellationId]);
        $ins = $pdo->prepare("INSERT IGNORE INTO galaxy_tags (constellation_id, tag_slug, tag_label) VALUES (:cid, :slug, :label)");
        $seen = [];
        foreach ($labels as $raw) {
            $label = trim((string) $raw);
            if ($label === '') continue;
            $slug = db_slugify($label);
            if ($slug === '' || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $ins->execute([':cid' => $constellationId, ':slug' => $slug, ':label' => $label]);
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
 */
function db_create_constellation(string $name, string $tagline = '', ?string $slug = null, string $theme = 'cosmic'): int {
    $pdo = getDB();

    $name = trim($name) ?: 'Unnamed';
    if ($slug === null || trim($slug) === '') {
        $slug = db_slugify($name);
    }

    $pdo->prepare("INSERT INTO constellations (name, tagline, slug, theme) VALUES (:name, :tagline, :slug, :theme)")->execute([
        ':name' => $name,
        ':tagline' => trim($tagline),
        ':slug' => trim($slug),
        ':theme' => $theme
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
               related_nodes_enabled
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
            related_nodes_enabled = :related_nodes_enabled
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
                   n.mucua_name, n.media_type, n.source_created_at,
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
                   n.mucua_name, n.media_type, n.source_created_at,
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
                   n.mucua_name, n.media_type, n.source_created_at,
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
               n.mucua_name, n.media_type, n.source_created_at,
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
               n.mucua_name, n.media_type, n.source_created_at,
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
               n.mucua_name, n.media_type, n.source_created_at,
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
        // Editor "all" — restrict to assigned constellations
        $from = "FROM nodes n INNER JOIN user_constellations uc ON n.constellation_id = uc.constellation_id AND uc.user_id = :uid LEFT JOIN constellations c ON c.id = n.constellation_id";
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
            'mucua_name' => isset($node['mucua_name']) && $node['mucua_name'] !== null && $node['mucua_name'] !== '' ? (string)$node['mucua_name'] : null,
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
        'mucua_name' => isset($node['mucua_name']) && $node['mucua_name'] !== null && $node['mucua_name'] !== '' ? (string)$node['mucua_name'] : null,
        'media_type' => isset($node['media_type']) && $node['media_type'] !== null && $node['media_type'] !== '' ? (string)$node['media_type'] : null,
        'source_created_at' => isset($node['source_created_at']) && $node['source_created_at'] !== null && $node['source_created_at'] !== '' ? (string)$node['source_created_at'] : null,
    ];
}

function db_save_node_keywords(int $nodeId, array $keywords): void {
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
        // unique_keyword_constellation (keyword, constellation_id).
        $kwPlaceholders = implode(',', array_fill(0, count($names), '(?, ?)'));
        $kwStmt = $pdo->prepare("INSERT IGNORE INTO keywords (keyword, constellation_id) VALUES $kwPlaceholders");
        $bind = [];
        foreach ($names as $n) {
            $bind[] = $n;
            $bind[] = $constellationId;
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
        // unique_node_keyword (node_id, keyword_id) to no-op on duplicates.
        $keywordIds = [];
        foreach ($names as $n) {
            $kid = $idByLower[mb_strtolower($n)] ?? 0;
            if ($kid > 0) $keywordIds[$kid] = true;
        }
        if ($keywordIds === []) return;
        $jPlaceholders = implode(',', array_fill(0, count($keywordIds), '(?, ?)'));
        $jStmt = $pdo->prepare("INSERT IGNORE INTO node_keywords (node_id, keyword_id) VALUES $jPlaceholders");
        $jBind = [];
        foreach (array_keys($keywordIds) as $kid) {
            $jBind[] = $nodeId;
            $jBind[] = $kid;
        }
        $jStmt->execute($jBind);
    } catch (PDOException $e) {
        error_log("db_save_node_keywords: failed to save keywords for node {$nodeId}: " . $e->getMessage());
    }
}

/**
 * Duplicate a node to the same or a different constellation.
 * Copies all content fields and keywords. Generates fresh animation values.
 * Does NOT copy import_slug, mucua_name, media_type, or source_created_at.
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

function db_create_node(string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false, ?string $videoUrl = null, bool $videoAutoplay = true, bool $audioLoop = false, bool $showKeywords = false, ?string $iconUrl = null, ?string $imageAttribution = null, bool $useImageAsNode = false, ?string $pdfUrl = null): int {
    if ($constellationId === null) {
        $constellationId = db_get_default_constellation_id();
    }
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_use_image_as_node_column();
    db_ensure_nodes_pdf_url_column();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, image_url, image_attribution, icon_url, embed_code, audio_url, audio_autoplay, audio_loop, video_url, video_autoplay, pdf_url, animation, constellation_id, node_type, target_constellation_id, is_accentuated, show_keywords, use_image_as_node)
        VALUES (:name, :description, :url, :image_url, :image_attribution, :icon_url, :embed_code, :audio_url, :audio_autoplay, :audio_loop, :video_url, :video_autoplay, :pdf_url, :animation, :constellation_id, :node_type, :target_constellation_id, :is_accentuated, :show_keywords, :use_image_as_node)
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
        ':use_image_as_node' => $useImageAsNode ? 1 : 0
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

function db_create_keyword(string $keyword, ?int $constellationId = null): int {
    if ($constellationId === null) {
        $constellationId = db_get_default_constellation_id();
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :constellation_id)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
    ");
    $stmt->execute([':keyword' => $keyword, ':constellation_id' => $constellationId]);
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
               mucua_name, media_type, source_created_at, import_slug, created_by
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
            mucua_name, media_type, source_created_at, import_slug, created_by
        ) VALUES (
            :constellation_id, :name, :description, :url,
            :image_url, :image_attribution, :icon_url, :embed_code,
            :audio_url, :audio_autoplay, :audio_loop,
            :video_url, :video_autoplay, :pdf_url, :animation,
            :node_type, :target_constellation_id, :is_accentuated, :show_keywords,
            :mucua_name, :media_type, :source_created_at, :import_slug, :created_by
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
        ':mucua_name' => $node['mucua_name'] ?? null,
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
