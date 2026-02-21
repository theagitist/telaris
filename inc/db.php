<?php
declare(strict_types=1);

/**
 * Database layer: all DB connection and queries in one place.
 * Expects DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS to be defined (e.g. by config.php).
 */

// ---------------------------------------------------------------------------
// Connection
// ---------------------------------------------------------------------------

/**
 * @return PDO
 * @throws PDOException
 */
function getDB(): PDO {
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
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
            exit;
        }
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
const PROJECT_INFO_KEYS = ['name', 'description', 'iframe_back_text', 'alert_message', 'edit_button_text', 'loading_text', 'back_button_text', 'system_online_text', 'reload_system_text', 'scan_system_text', 'clear_scan_text', 'systems_label_text', 'hyperlinks_label_text', 'initialize_auth_text', 'admin_label_text', 'logout_label_text', 'click_to_view_text', 'tap_to_view_text'];

/** Locales supported (one row per locale in project_info). */
const PROJECT_INFO_LOCALES = ['en', 'es', 'pt'];

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
            'back_button_text' => 'Back', 'system_online_text' => 'System: Online',
            'reload_system_text' => 'Reload System', 'scan_system_text' => 'SCAN SYSTEM...',
            'clear_scan_text' => 'Clear Scan', 'systems_label_text' => 'Systems:',
            'hyperlinks_label_text' => 'Hyperlinks:', 'initialize_auth_text' => 'Initialize Auth',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Logout',
            'click_to_view_text' => 'Click to view', 'tap_to_view_text' => 'Tap again to view'
        ],
        'es' => [
            'name' => 'Telaris', 'description' => 'Tejiendo memoria', 'iframe_back_text' => 'Volver', 
            'alert_message' => "Estás cruzando hacia la Dimensión Planar\nPara explorar, haz zoom y desplázate en todas las direcciones\nCierra la ventana del navegador para volver a la Dimensión Cósmica.", 
            'edit_button_text' => 'Editar', 'loading_text' => 'Cargando',
            'back_button_text' => 'Volver', 'system_online_text' => 'Sistema: En línea',
            'reload_system_text' => 'Recargar Sistema', 'scan_system_text' => 'ESCANEAR SISTEMA...',
            'clear_scan_text' => 'Limpiar Escaneo', 'systems_label_text' => 'Sistemas:',
            'hyperlinks_label_text' => 'Hipervínculos:', 'initialize_auth_text' => 'Inicializar Autenticación',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Cerrar sesión',
            'click_to_view_text' => 'Haz clic para ver', 'tap_to_view_text' => 'Toca de nuevo para ver'
        ],
        'pt' => [
            'name' => 'Telaris', 'description' => 'Tecendo memória', 'iframe_back_text' => 'Voltar', 
            'alert_message' => "Você está atravessando para a Dimensão Planar\nPara explorar, use o zoom e role em todas as direções\nFeche a janela do navegador para retornar à Dimensão Cósmica.", 
            'edit_button_text' => 'Editar', 'loading_text' => 'Carregando',
            'back_button_text' => 'Voltar', 'system_online_text' => 'Sistema: Online',
            'reload_system_text' => 'Recarregar Sistema', 'scan_system_text' => 'ESCANEAR SISTEMA...',
            'clear_scan_text' => 'Limpar Varredura', 'systems_label_text' => 'Sistemas:',
            'hyperlinks_label_text' => 'Hiperlinks:', 'initialize_auth_text' => 'Inicializar Autenticação',
            'admin_label_text' => 'Admin', 'logout_label_text' => 'Sair',
            'click_to_view_text' => 'Clique para ver', 'tap_to_view_text' => 'Toque novamente para ver'
        ],
    ];
}

/** No-op: schema is created by setup only. */
function db_ensure_project_info_table(): void {
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
 * Ensure project_info table has all required columns and default values.
 */
function db_ensure_project_info_columns(): void {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SHOW COLUMNS FROM project_info LIKE 'click_to_view_text'");
        if ($stmt->fetch() === false) {
            $pdo->exec("ALTER TABLE project_info ADD COLUMN click_to_view_text VARCHAR(200) NOT NULL DEFAULT 'Click to view'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM project_info LIKE 'tap_to_view_text'");
        if ($stmt->fetch() === false) {
            $pdo->exec("ALTER TABLE project_info ADD COLUMN tap_to_view_text VARCHAR(200) NOT NULL DEFAULT 'Tap again to view'");
        }

        // Fill empty values for localized hints in existing rows
        $defaults = db_default_project_info_rows();
        foreach (['en', 'es', 'pt'] as $locale) {
            $pdo->prepare("
                UPDATE project_info 
                SET click_to_view_text = :click 
                WHERE locale = :locale AND (click_to_view_text IS NULL OR click_to_view_text = '' OR click_to_view_text = 'Click to view')
            ")->execute([
                ':click' => $defaults[$locale]['click_to_view_text'],
                ':locale' => $locale
            ]);
            $pdo->prepare("
                UPDATE project_info 
                SET tap_to_view_text = :tap 
                WHERE locale = :locale AND (tap_to_view_text IS NULL OR tap_to_view_text = '' OR tap_to_view_text = 'Tap again to view')
            ")->execute([
                ':tap' => $defaults[$locale]['tap_to_view_text'],
                ':locale' => $locale
            ]);
        }
    } catch (PDOException $e) {
        // Table might not exist yet, which is fine during setup
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
        return $out;
    } catch (PDOException $e) {
        $defaults = db_default_project_info_rows();
        return $defaults['en'];
    }
}

/**
 * Update project settings for all locales (one row per locale in project_info).
 */
function db_update_project_settings_with_locales(array $en, array $es, array $pt): void {
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
    
    $stmt = $pdo->prepare("INSERT INTO project_info (locale, $cols) VALUES (:locale, $placeholders) ON DUPLICATE KEY UPDATE $updateStr");
    
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
        $stmt->execute($params);
    }
    // Keep default constellation (id=0) in sync with English app name and tagline when Settings are saved
    $enName = trim((string) ($en['name'] ?? ''));
    $enDescription = trim((string) ($en['description'] ?? ''));
    $pdo->prepare("UPDATE constellations SET name = :name, tagline = :tagline WHERE id = :id")->execute([
        ':name' => $enName !== '' ? $enName : 'Default',
        ':tagline' => $enDescription,
        ':id' => DEFAULT_CONSTELLATION_ID
    ]);
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
        SELECT id, api_key, name, description, created_at, last_used_at, is_active
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
        SELECT id, email, firstname, lastname, type, date_created, date_last_login
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
        return $e->getMessage();
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
        return $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Constellations
// ---------------------------------------------------------------------------

/** Default constellation id (created by setup, cannot be erased). */
const DEFAULT_CONSTELLATION_ID = 0;

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
    $stmt = $pdo->query("SELECT id, name, tagline, slug FROM constellations ORDER BY id");
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
        SELECT c.id, c.name, c.tagline, c.slug
        FROM constellations c
        INNER JOIN user_constellations uc ON uc.constellation_id = c.id AND uc.user_id = :user_id
        ORDER BY c.id
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get one constellation by id (name and tagline for main view).
 * @return array{name: string, tagline: string}|null
 */
function db_get_constellation_by_id(int $id): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT name, tagline, slug FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'name' => (string) ($row['name'] ?? ''),
        'tagline' => (string) ($row['tagline'] ?? ''),
        'slug' => $row['slug']
    ];
}

/**
 * Get one constellation by slug.
 * @return array{id: int, name: string, tagline: string}|null
 */
function db_get_constellation_by_slug(string $slug): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name, tagline FROM constellations WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        // Fallback: check if any constellation name slugifies to this value
        $all = $pdo->query("SELECT id, name, tagline, slug FROM constellations");
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
        'tagline' => (string) ($row['tagline'] ?? '')
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
function db_create_constellation(string $name, string $tagline = '', ?string $slug = null): int {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT COALESCE(MAX(id), -1) + 1 AS next_id FROM constellations");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = (int)($row['next_id'] ?? 1);
    
    $name = trim($name) ?: 'Unnamed';
    if ($slug === null || trim($slug) === '') {
        $slug = db_slugify($name);
    }

    $pdo->prepare("INSERT INTO constellations (id, name, tagline, slug) VALUES (:id, :name, :tagline, :slug)")->execute([
        ':id' => $nextId,
        ':name' => $name,
        ':tagline' => trim($tagline),
        ':slug' => trim($slug)
    ]);
    return $nextId;
}

/**
 * Update constellation name and tagline. Id cannot be changed. Default constellation (id=0) can be renamed.
 */
function db_update_constellation(int $id, string $name, string $tagline = '', ?string $slug = null): void {
    $pdo = getDB();
    
    $name = trim($name) ?: 'Unnamed';
    if ($slug === null || trim($slug) === '') {
        $slug = db_slugify($name);
    }

    $pdo->prepare("UPDATE constellations SET name = :name, tagline = :tagline, slug = :slug WHERE id = :id")->execute([
        ':name' => $name,
        ':tagline' => trim($tagline),
        ':slug' => trim($slug),
        ':id' => $id
    ]);
}

/**
 * Delete a constellation. Fails if id is the default (0); nodes/keywords in other constellations are unaffected.
 */
function db_delete_constellation(int $id): void {
    if ($id === DEFAULT_CONSTELLATION_ID) {
        throw new InvalidArgumentException('The default constellation cannot be deleted.');
    }
    $pdo = getDB();
    $pdo->prepare("DELETE FROM constellations WHERE id = :id")->execute([':id' => $id]);
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
    $pdo = getDB();
    
    // Admin or specific constellation requested
    if ($isAdmin && $constellationId === null) {
        $stmt = $pdo->query("
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.embed_code, n.audio_url, n.audio_autoplay, n.animation, n.created_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated,
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
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.embed_code, n.audio_url, n.audio_autoplay, n.animation, n.created_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated,
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
            SELECT n.id, n.name, n.description, n.url, n.image_url, n.embed_code, n.audio_url, n.audio_autoplay, n.animation, n.created_at, n.constellation_id,
                   n.node_type, n.target_constellation_id, n.is_accentuated,
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
    // Return created_at as ISO 8601 UTC so the client can display in user's timezone
    if ($createdAt !== null && $createdAt !== '') {
        $ts = strtotime($createdAt);
        $createdAt = $ts !== false ? gmdate('c', $ts) : $createdAt;
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
        'keywords' => $keywords,
        'animation' => $animation,
        'created_at' => $createdAt,
        'constellation_id' => isset($node['constellation_id']) ? (int)$node['constellation_id'] : DEFAULT_CONSTELLATION_ID,
        'constellation_name' => isset($node['constellation_name']) && (string)$node['constellation_name'] !== '' ? (string)$node['constellation_name'] : 'Default',
        'node_type' => $nodeType,
        'target_constellation_id' => $targetConstellationId,
        'is_accentuated' => (bool)($node['is_accentuated'] ?? false)
    ];
}

function db_save_node_keywords(int $nodeId, array $keywords): void {
    $pdo = getDB();
    $nodeStmt = $pdo->prepare("SELECT constellation_id FROM nodes WHERE id = :id LIMIT 1");
    $nodeStmt->execute([':id' => $nodeId]);
    $nodeRow = $nodeStmt->fetch();
    $constellationId = $nodeRow ? (int)$nodeRow['constellation_id'] : DEFAULT_CONSTELLATION_ID;
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
        }
    }
}

function db_create_node(string $name, ?string $description, ?string $url, string $animation, int $constellationId = DEFAULT_CONSTELLATION_ID, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, image_url, embed_code, audio_url, audio_autoplay, animation, constellation_id, node_type, target_constellation_id, is_accentuated)
        VALUES (:name, :description, :url, :image_url, :embed_code, :audio_url, :audio_autoplay, :animation, :constellation_id, :node_type, :target_constellation_id, :is_accentuated)
    ");
    $stmt->execute([
        ':name' => $name,
        ':description' => $description,
        ':url' => $url,
        ':image_url' => $imageUrl,
        ':embed_code' => $embedCode,
        ':audio_url' => $audioUrl,
        ':audio_autoplay' => $audioAutoplay ? 1 : 0,
        ':animation' => $animation,
        ':constellation_id' => $constellationId,
        ':node_type' => $nodeType,
        ':target_constellation_id' => $targetConstellationId,
        ':is_accentuated' => $isAccentuated ? 1 : 0
    ]);
    return (int)$pdo->lastInsertId();
}

function db_update_node(int $id, string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null, string $nodeType = 'object', ?int $targetConstellationId = null, ?string $imageUrl = null, ?string $embedCode = null, ?string $audioUrl = null, bool $audioAutoplay = true, bool $isAccentuated = false): void {
    $pdo = getDB();
    if ($constellationId !== null) {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, image_url = :image_url, embed_code = :embed_code, audio_url = :audio_url, audio_autoplay = :audio_autoplay, animation = :animation, constellation_id = :constellation_id, node_type = :node_type, target_constellation_id = :target_constellation_id, is_accentuated = :is_accentuated WHERE id = :id
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
            ':animation' => $animation,
            ':constellation_id' => $constellationId,
            ':node_type' => $nodeType,
            ':target_constellation_id' => $targetConstellationId,
            ':is_accentuated' => $isAccentuated ? 1 : 0
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, image_url = :image_url, embed_code = :embed_code, audio_url = :audio_url, audio_autoplay = :audio_autoplay, animation = :animation, node_type = :node_type, target_constellation_id = :target_constellation_id, is_accentuated = :is_accentuated WHERE id = :id
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
            ':animation' => $animation,
            ':node_type' => $nodeType,
            ':target_constellation_id' => $targetConstellationId,
            ':is_accentuated' => $isAccentuated ? 1 : 0
        ]);
    }
}

function db_delete_node(int $id): void {
    $pdo = getDB();
    
    // Also delete any associated uploaded files
    $stmt = $pdo->prepare("SELECT image_url, audio_url FROM nodes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if ($row) {
        $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
        if ($row['image_url'] && str_starts_with($row['image_url'], 'uploads/')) {
            $fullPath = str_replace('uploads/', $uploadDir . '/', $row['image_url']);
            if (file_exists($fullPath)) unlink($fullPath);
        }
        if ($row['audio_url'] && str_starts_with($row['audio_url'], 'uploads/')) {
            $fullPath = str_replace('uploads/', $uploadDir . '/', $row['audio_url']);
            if (file_exists($fullPath)) unlink($fullPath);
        }
    }

    $stmt = $pdo->prepare("DELETE FROM nodes WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

function db_delete_node_file(int $id, string $type): void {
    $pdo = getDB();
    $column = ($type === 'image') ? 'image_url' : 'audio_url';
    
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
    $stmt->execute([':constellation_id' => DEFAULT_CONSTELLATION_ID]);
    return $stmt->fetchAll();
}

function db_create_keyword(string $keyword, int $constellationId = DEFAULT_CONSTELLATION_ID): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :constellation_id)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
    ");
    $stmt->execute([':keyword' => $keyword, ':constellation_id' => $constellationId]);
    return (int)$pdo->lastInsertId();
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
