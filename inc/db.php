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
const PROJECT_INFO_KEYS = ['name', 'description', 'iframe_back_text', 'alert_message', 'edit_button_text', 'loading_text', 'back_button_text', 'system_online_text', 'reload_system_text', 'scan_system_text', 'clear_scan_text', 'systems_label_text', 'hyperlinks_label_text', 'initialize_auth_text', 'admin_label_text', 'logout_label_text', 'click_to_view_text', 'tap_to_view_text', 'open_portal_text'];

/** Locales supported (one row per locale in project_info). */
const PROJECT_INFO_LOCALES = ['en', 'es', 'pt'];

/**
 * Get the default constellation ID from project settings.
 */
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
            'clear_scan_text' => 'Clear Search', 'systems_label_text' => 'Nodes:',
            'hyperlinks_label_text' => 'Hyperlinks:', 'initialize_auth_text' => 'Login',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Logout',
            'click_to_view_text' => 'Click to view', 'tap_to_view_text' => 'Tap again to view',
            'open_portal_text' => 'Open the Portal'
        ],
        'es' => [
            'name' => 'Telaris', 'description' => 'Tejiendo memoria', 'iframe_back_text' => 'Volver', 
            'alert_message' => "Estás cruzando hacia la Dimensión Planar\nPara explorar, haz zoom y desplázate en todas las direcciones\nCierra la ventana del navegador para volver a la Dimensión Cósmica.", 
            'edit_button_text' => 'Editar', 'loading_text' => 'Cargando',
            'back_button_text' => 'Volver', 'system_online_text' => 'En línea',
            'reload_system_text' => 'Recargar', 'scan_system_text' => 'BUSCAR...',
            'clear_scan_text' => 'Limpiar Búsqueda', 'systems_label_text' => 'Nodos:',
            'hyperlinks_label_text' => 'Hipervínculos:', 'initialize_auth_text' => 'Iniciar sesión',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Cerrar sesión',
            'click_to_view_text' => 'Haz clic para ver', 'tap_to_view_text' => 'Toca de nuevo para ver',
            'open_portal_text' => 'Abrir el Portal'
        ],
        'pt' => [
            'name' => 'Telaris', 'description' => 'Tecendo memória', 'iframe_back_text' => 'Voltar', 
            'alert_message' => "Você está atravessando para a Dimensão Planar\nPara explorar, use o zoom e role em todas as direções\nFeche a janela do navegador para retornar à Dimensão Cósmica.", 
            'edit_button_text' => 'Editar', 'loading_text' => 'Carregando',
            'back_button_text' => 'Voltar', 'system_online_text' => 'Online',
            'reload_system_text' => 'Recarregar', 'scan_system_text' => 'BUSCAR...',
            'clear_scan_text' => 'Limpar Busca', 'systems_label_text' => 'Nodos:',
            'hyperlinks_label_text' => 'Hiperlinks:', 'initialize_auth_text' => 'Entrar',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Sair',
            'click_to_view_text' => 'Clique para ver', 'tap_to_view_text' => 'Toque novamente para ver',
            'open_portal_text' => 'Abrir o Portal'
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

/** No-op: schema is created by setup only. */
function db_ensure_project_info_table(): void {
}

/** No-op: all columns now exist in SCHEMA.sql. */
function db_ensure_project_info_columns(): void {
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
 * @return list<array{id: int, name: string, tagline: string}>
 */
function db_get_constellations(): array {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT id, name, tagline, slug, theme, created_at, updated_at FROM constellations ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.tagline, c.slug, c.theme, c.created_at, c.updated_at
        FROM constellations c
        INNER JOIN user_constellations uc ON uc.constellation_id = c.id AND uc.user_id = :user_id
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
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT name, tagline, slug, theme FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'name' => (string) ($row['name'] ?? ''),
        'tagline' => (string) ($row['tagline'] ?? ''),
        'slug' => $row['slug'],
        'theme' => (string) ($row['theme'] ?? 'cosmic')
    ];
}

/**
 * Get one constellation by slug.
 * @return array{id: int, name: string, tagline: string, theme: string}|null
 */
function db_get_constellation_by_slug(string $slug): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name, tagline, theme FROM constellations WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        // Fallback: check if any constellation name slugifies to this value
        $all = $pdo->query("SELECT id, name, tagline, slug, theme FROM constellations");
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
        'theme' => (string) ($row['theme'] ?? 'cosmic')
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
        
        $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
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
 * Find all portal nodes that point to a specific constellation.
 * @return list<array{id: int, name: string, constellation_id: int, constellation_name: string}>
 */
function db_get_referencing_portals(int $constellationId): array {
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

        // 2. Delete nodes in this constellation
        // db_delete_node handles file deletion, so we should call it for each node
        $stmt = $pdo->prepare("SELECT id FROM nodes WHERE constellation_id = :id");
        $stmt->execute([':id' => $id]);
        while ($node = $stmt->fetch()) {
            db_delete_node((int)$node['id']);
        }

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
    $pdo = getDB();

    // Admin or specific constellation requested
    if ($isAdmin && $constellationId === null) {
        $stmt = $pdo->query("
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.animation, n.created_at, n.updated_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords,
                   c.name AS constellation_name
            FROM nodes n
            LEFT JOIN constellations c ON c.id = n.constellation_id
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
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.animation, n.created_at, n.updated_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords,
                   c.name AS constellation_name
            FROM nodes n
            LEFT JOIN constellations c ON c.id = n.constellation_id
            WHERE n.constellation_id = :constellation_id
            ORDER BY n.id
        ");
        $stmt->execute([':constellation_id' => $constellationId]);
        return $stmt->fetchAll();
    }

    // Editor requesting "all" constellations - show only those they have access to
    if (!$isAdmin && $userId !== null) {
        $stmt = $pdo->prepare("
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.embed_code, n.audio_url, n.audio_autoplay, n.audio_loop, n.video_url, n.video_autoplay, n.animation, n.created_at, n.updated_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated, n.show_keywords,
                   c.name AS constellation_name
            FROM nodes n
            INNER JOIN user_constellations uc ON n.constellation_id = uc.constellation_id AND uc.user_id = :user_id
            LEFT JOIN constellations c ON c.id = n.constellation_id
            ORDER BY n.id
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    return [];
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
    $createdAt = $node['created_at'] ?? null;
    $updatedAt = $node['updated_at'] ?? null;
    // Return timestamps as ISO 8601 UTC so the client can display in user's timezone
    if ($createdAt !== null && $createdAt !== '') {
        $ts = strtotime($createdAt);
        $createdAt = $ts !== false ? gmdate('c', $ts) : $createdAt;
    }
    if ($updatedAt !== null && $updatedAt !== '') {
        $ts = strtotime($updatedAt);
        $updatedAt = $ts !== false ? gmdate('c', $ts) : $updatedAt;
    }
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
        'image_url' => $node['image_url'] ?? null,
        'embed_code' => $node['embed_code'] ?? null,
        'audio_url' => $node['audio_url'] ?? null,
        'audio_autoplay' => (bool)($node['audio_autoplay'] ?? true),
        'audio_loop' => (bool)($node['audio_loop'] ?? false),
        'video_url' => $node['video_url'] ?? null,
        'video_autoplay' => (bool)($node['video_autoplay'] ?? true),
        'keywords' => $keywords,
        'animation' => $animation,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'constellation_id' => isset($node['constellation_id']) ? (int)$node['constellation_id'] : db_get_default_constellation_id(),
        'constellation_name' => isset($node['constellation_name']) && (string)$node['constellation_name'] !== '' ? (string)$node['constellation_name'] : 'Default',
        'node_type' => $nodeType,
        'target_constellation_id' => $targetConstellationId,
        'is_accentuated' => (bool)($node['is_accentuated'] ?? false),
        'show_keywords' => (bool)($node['show_keywords'] ?? false)
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
    $keywordStmt = $pdo->prepare("
        INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :constellation_id)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
    ");
    $nodeKeywordStmt = $pdo->prepare("
        INSERT INTO node_keywords (node_id, keyword_id)
        VALUES (:node_id, :keyword_id)
        ON DUPLICATE KEY UPDATE node_id=node_id, keyword_id=keyword_id
    ");
    foreach ($keywords as $keyword) {
        $keyword = trim($keyword);
        if ($keyword === '') {
            continue;
        }
        try {
            $keywordStmt->execute([':keyword' => $keyword, ':constellation_id' => $constellationId]);
            $keywordId = (int)$pdo->lastInsertId();
            if ($keywordId === 0) {
                $getIdStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword AND constellation_id = :constellation_id LIMIT 1");
                $getIdStmt->execute([':keyword' => $keyword, ':constellation_id' => $constellationId]);
                $result = $getIdStmt->fetch();
                $keywordId = $result ? (int)$result['id'] : 0;
            }
            if ($keywordId > 0) {
                $nodeKeywordStmt->execute([':node_id' => $nodeId, ':keyword_id' => $keywordId]);
            }
        } catch (PDOException $e) {
            error_log("db_save_node_keywords: failed to save keyword '{$keyword}' for node {$nodeId}: " . $e->getMessage());
        }
    }
}

function db_create_node(string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false, ?string $videoUrl = null, bool $videoAutoplay = true, bool $audioLoop = false, bool $showKeywords = false): int {
    if ($constellationId === null) {
        $constellationId = db_get_default_constellation_id();
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, image_url, embed_code, audio_url, audio_autoplay, audio_loop, video_url, video_autoplay, animation, constellation_id, node_type, target_constellation_id, is_accentuated, show_keywords)
        VALUES (:name, :description, :url, :image_url, :embed_code, :audio_url, :audio_autoplay, :audio_loop, :video_url, :video_autoplay, :animation, :constellation_id, :node_type, :target_constellation_id, :is_accentuated, :show_keywords)
    ");
    $stmt->execute([
        ':name' => $name,
        ':description' => $description,
        ':url' => $url,
        ':image_url' => $imageUrl,
        ':embed_code' => $embedCode,
        ':audio_url' => $audioUrl,
        ':audio_autoplay' => $audioAutoplay ? 1 : 0,
        ':audio_loop' => $audioLoop ? 1 : 0,
        ':video_url' => $videoUrl,
        ':video_autoplay' => $videoAutoplay ? 1 : 0,
        ':animation' => $animation,
        ':constellation_id' => $constellationId,
        ':node_type' => $nodeType,
        ':target_constellation_id' => $targetConstellationId,
        ':is_accentuated' => $isAccentuated ? 1 : 0,
        ':show_keywords' => $showKeywords ? 1 : 0
    ]);
    return (int)$pdo->lastInsertId();
}

function db_update_node(int $id, string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false, ?string $videoUrl = null, bool $videoAutoplay = true, bool $audioLoop = false, bool $showKeywords = false): void {
    $pdo = getDB();
    if ($constellationId !== null) {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, image_url = :image_url, embed_code = :embed_code, audio_url = :audio_url, audio_autoplay = :audio_autoplay, audio_loop = :audio_loop, video_url = :video_url, video_autoplay = :video_autoplay, animation = :animation, constellation_id = :constellation_id, node_type = :node_type, target_constellation_id = :target_constellation_id, is_accentuated = :is_accentuated, show_keywords = :show_keywords WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':url' => $url,
            ':image_url' => $imageUrl,
            ':embed_code' => $embedCode,
            ':audio_url' => $audioUrl,
            ':audio_autoplay' => $audioAutoplay ? 1 : 0,
            ':audio_loop' => $audioLoop ? 1 : 0,
            ':video_url' => $videoUrl,
            ':video_autoplay' => $videoAutoplay ? 1 : 0,
            ':animation' => $animation,
            ':constellation_id' => $constellationId,
            ':node_type' => $nodeType,
            ':target_constellation_id' => $targetConstellationId,
            ':is_accentuated' => $isAccentuated ? 1 : 0,
            ':show_keywords' => $showKeywords ? 1 : 0
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, image_url = :image_url, embed_code = :embed_code, audio_url = :audio_url, audio_autoplay = :audio_autoplay, audio_loop = :audio_loop, video_url = :video_url, video_autoplay = :video_autoplay, animation = :animation, node_type = :node_type, target_constellation_id = :target_constellation_id, is_accentuated = :is_accentuated, show_keywords = :show_keywords WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':url' => $url,
            ':image_url' => $imageUrl,
            ':embed_code' => $embedCode,
            ':audio_url' => $audioUrl,
            ':audio_autoplay' => $audioAutoplay ? 1 : 0,
            ':audio_loop' => $audioLoop ? 1 : 0,
            ':video_url' => $videoUrl,
            ':video_autoplay' => $videoAutoplay ? 1 : 0,
            ':animation' => $animation,
            ':node_type' => $nodeType,
            ':target_constellation_id' => $targetConstellationId,
            ':is_accentuated' => $isAccentuated ? 1 : 0,
            ':show_keywords' => $showKeywords ? 1 : 0
        ]);
    }
}

function db_delete_node(int $id): void {
    $pdo = getDB();

    // Collect file paths to delete AFTER the DB row is removed
    $filesToDelete = [];
    $stmt = $pdo->prepare("SELECT image_url, audio_url, video_url FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if ($row) {
        $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
        foreach (['image_url', 'audio_url', 'video_url'] as $col) {
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
        'audio' => 'audio_url',
        'video' => 'video_url',
        default => throw new InvalidArgumentException('Invalid file type')
    };
    
    $stmt = $pdo->prepare("SELECT $column FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    
    if ($row && $row[$column] && str_starts_with($row[$column], 'uploads/')) {
        $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
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
    $nodeKeywords = [];
    if ($constellationId !== null) {
        foreach ($nodes as $node) {
            $stmt = $pdo->prepare("
                SELECT k.keyword FROM keywords k
                JOIN node_keywords nk ON k.id = nk.keyword_id
                WHERE nk.node_id = :node_id AND k.constellation_id = :constellation_id
            ");
            $stmt->execute([':node_id' => $node['id'], ':constellation_id' => $constellationId]);
            $nodeKeywords[$node['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } else {
        foreach ($nodes as $node) {
            $stmt = $pdo->prepare("
                SELECT k.keyword FROM keywords k
                JOIN node_keywords nk ON k.id = nk.keyword_id
                WHERE nk.node_id = :node_id
            ");
            $stmt->execute([':node_id' => $node['id']]);
            $nodeKeywords[$node['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    $connections = [];
    $connectionId = 1;
    $n = count($nodes);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $id1 = (int)$nodes[$i]['id'];
            $id2 = (int)$nodes[$j]['id'];
            $kw1 = $nodeKeywords[$id1] ?? [];
            $kw2 = $nodeKeywords[$id2] ?? [];
            $shared = array_values(array_intersect($kw1, $kw2));
            if (count($shared) > 0) {
                $connections[] = [
                    'id' => $connectionId++,
                    'node1_id' => $id1,
                    'node2_id' => $id2,
                    'shared_keywords' => $shared,
                    'shared_count' => count($shared)
                ];
            }
        }
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
