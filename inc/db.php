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
const PROJECT_INFO_KEYS = ['name', 'description', 'iframe_back_text', 'alert_message', 'edit_button_text', 'loading_text'];

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
        'en' => ['name' => $enName, 'description' => $enDescription, 'iframe_back_text' => 'Go back', 'alert_message' => "Close this window when you're done to go back to {APPNAME}.", 'edit_button_text' => 'Edit', 'loading_text' => 'Loading'],
        'es' => ['name' => 'Telaris', 'description' => 'Tejiendo memoria', 'iframe_back_text' => 'Volver', 'alert_message' => 'Cierra esta ventana cuando termines para volver a {APPNAME}.', 'edit_button_text' => 'Editar', 'loading_text' => 'Cargando'],
        'pt' => ['name' => 'Telaris', 'description' => 'Tecendo memória', 'iframe_back_text' => 'Voltar', 'alert_message' => 'Feche esta janela quando terminar para voltar a {APPNAME}.', 'edit_button_text' => 'Editar', 'loading_text' => 'Carregando'],
    ];
}

/**
 * Ensure project_info table exists with one row per locale (en, es, pt).
 */
function db_ensure_project_info_table(): void {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'project_info'");
    if ($stmt->fetch() === false) {
        $pdo->exec("
            CREATE TABLE project_info (
                locale VARCHAR(10) NOT NULL PRIMARY KEY,
                name VARCHAR(2000) NOT NULL DEFAULT '',
                description VARCHAR(2000) NOT NULL DEFAULT '',
                iframe_back_text VARCHAR(2000) NOT NULL DEFAULT '',
                alert_message VARCHAR(2000) NOT NULL DEFAULT '',
                edit_button_text VARCHAR(200) NOT NULL DEFAULT 'Edit',
                loading_text VARCHAR(200) NOT NULL DEFAULT 'Loading'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        db_insert_default_project_info_rows($pdo);
        return;
    }
    $count = (int) $pdo->query("SELECT COUNT(*) FROM project_info")->fetchColumn();
    if ($count === 0) {
        db_insert_default_project_info_rows($pdo);
        return;
    }
    $existing = $pdo->query("SELECT locale FROM project_info")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(PROJECT_INFO_LOCALES, $existing);
    if ($missing === []) {
        return;
    }
    $defaults = db_default_project_info_rows();
    $stmt = $pdo->prepare("INSERT INTO project_info (locale, name, description, iframe_back_text, alert_message, edit_button_text, loading_text) VALUES (:locale, :name, :description, :iframe_back_text, :alert_message, :edit_button_text, :loading_text)");
    foreach ($missing as $locale) {
        $d = $defaults[$locale];
        $stmt->execute([
            ':locale' => $locale,
            ':name' => $d['name'],
            ':description' => $d['description'],
            ':iframe_back_text' => $d['iframe_back_text'],
            ':alert_message' => $d['alert_message'],
            ':edit_button_text' => $d['edit_button_text'],
            ':loading_text' => $d['loading_text'],
        ]);
    }
}

/**
 * Insert default project_info rows (one per locale). Used by setup and when table is empty.
 */
function db_insert_default_project_info_rows(PDO $pdo, string $enName = 'Telaris', string $enDescription = 'Weaving memory'): void {
    $defaults = db_default_project_info_rows($enName, $enDescription);
    $stmt = $pdo->prepare("INSERT INTO project_info (locale, name, description, iframe_back_text, alert_message, edit_button_text, loading_text) VALUES (:locale, :name, :description, :iframe_back_text, :alert_message, :edit_button_text, :loading_text) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), iframe_back_text = VALUES(iframe_back_text), alert_message = VALUES(alert_message), edit_button_text = VALUES(edit_button_text), loading_text = VALUES(loading_text)");
    foreach (PROJECT_INFO_LOCALES as $locale) {
        $d = $defaults[$locale];
        $stmt->execute([
            ':locale' => $locale,
            ':name' => $d['name'],
            ':description' => $d['description'],
            ':iframe_back_text' => $d['iframe_back_text'],
            ':alert_message' => $d['alert_message'],
            ':edit_button_text' => $d['edit_button_text'],
            ':loading_text' => $d['loading_text'],
        ]);
    }
}

/**
 * Ensure project_info table exists (one row per locale). Called by setup and by code that reads project info.
 */
function db_ensure_project_info_columns(): void {
    db_ensure_project_info_table();
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
    $stmt = $pdo->prepare("INSERT INTO project_info (locale, name, description, iframe_back_text, alert_message, edit_button_text, loading_text) VALUES ('en', :name, :description, 'Go back', 'Close this window when you''re done to go back to {APPNAME}.', 'Edit', 'Loading') ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)");
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
        $stmt = $pdo->query("SELECT locale, name, description, iframe_back_text, alert_message, edit_button_text, loading_text FROM project_info");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [
            'name' => 'Telaris', 'description' => '', 'iframe_back_text' => 'Go back',
            'alert_message' => "Close this window when you're done to go back to {APPNAME}.", 'edit_button_text' => 'Edit', 'loading_text' => 'Loading',
            'name_es' => '', 'name_pt' => '', 'description_es' => '', 'description_pt' => '',
            'iframe_back_text_es' => '', 'iframe_back_text_pt' => '',
            'alert_message_es' => '', 'alert_message_pt' => '', 'edit_button_text_es' => '', 'edit_button_text_pt' => '',
            'loading_text_es' => '', 'loading_text_pt' => '',
        ];
        foreach ($rows as $r) {
            $locale = $r['locale'] ?? 'en';
            if ($locale === 'en') {
                $out['name'] = (string) ($r['name'] ?? '');
                $out['description'] = (string) ($r['description'] ?? '');
                $out['iframe_back_text'] = (string) ($r['iframe_back_text'] ?? '');
                $out['alert_message'] = (string) ($r['alert_message'] ?? '');
                $out['edit_button_text'] = (string) ($r['edit_button_text'] ?? 'Edit');
                $out['loading_text'] = (string) ($r['loading_text'] ?? 'Loading');
            } else {
                $out['name_' . $locale] = (string) ($r['name'] ?? '');
                $out['description_' . $locale] = (string) ($r['description'] ?? '');
                $out['iframe_back_text_' . $locale] = (string) ($r['iframe_back_text'] ?? '');
                $out['alert_message_' . $locale] = (string) ($r['alert_message'] ?? '');
                $out['edit_button_text_' . $locale] = (string) ($r['edit_button_text'] ?? 'Editar');
                $out['loading_text_' . $locale] = (string) ($r['loading_text'] ?? 'Cargando');
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
        $stmt = $pdo->prepare("SELECT name, description, iframe_back_text, alert_message, edit_button_text, loading_text FROM project_info WHERE locale = :locale LIMIT 1");
        $stmt->execute([':locale' => $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $enStmt = $pdo->prepare("SELECT name, description, iframe_back_text, alert_message, edit_button_text, loading_text FROM project_info WHERE locale = 'en' LIMIT 1");
        $enStmt->execute();
        $enRow = $enStmt->fetch(PDO::FETCH_ASSOC);
        $en = [
            'name' => (string) ($enRow['name'] ?? 'Telaris'),
            'description' => (string) ($enRow['description'] ?? 'Weaving memory'),
            'iframe_back_text' => (string) ($enRow['iframe_back_text'] ?? 'Go back'),
            'alert_message' => (string) ($enRow['alert_message'] ?? "Close this window when you're done to go back to {APPNAME}."),
            'edit_button_text' => (string) ($enRow['edit_button_text'] ?? 'Edit'),
            'loading_text' => (string) ($enRow['loading_text'] ?? 'Loading'),
        ];
        if ($row) {
            $out = [
                'name' => (string) ($row['name'] ?? '') ?: $en['name'],
                'description' => (string) ($row['description'] ?? '') ?: $en['description'],
                'iframe_back_text' => (string) ($row['iframe_back_text'] ?? '') ?: $en['iframe_back_text'],
                'alert_message' => (string) ($row['alert_message'] ?? '') ?: $en['alert_message'],
                'edit_button_text' => (string) ($row['edit_button_text'] ?? '') ?: $en['edit_button_text'],
                'loading_text' => (string) ($row['loading_text'] ?? '') ?: $en['loading_text'],
            ];
            return $out;
        }
        return $en;
    } catch (PDOException $e) {
        return [
            'name' => 'Telaris',
            'description' => 'Weaving memory',
            'iframe_back_text' => 'Go back',
            'alert_message' => "Close this window when you're done to go back to {APPNAME}.",
            'edit_button_text' => 'Edit',
            'loading_text' => 'Loading',
        ];
    }
}

/**
 * Update project settings for all locales (one row per locale in project_info).
 */
function db_update_project_settings_with_locales(array $en, array $es, array $pt): void {
    db_ensure_project_info_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO project_info (locale, name, description, iframe_back_text, alert_message, edit_button_text, loading_text) VALUES (:locale, :name, :description, :iframe_back_text, :alert_message, :edit_button_text, :loading_text) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), iframe_back_text = VALUES(iframe_back_text), alert_message = VALUES(alert_message), edit_button_text = VALUES(edit_button_text), loading_text = VALUES(loading_text)");
    $locales = ['en' => $en, 'es' => $es, 'pt' => $pt];
    $defaults = db_default_project_info_rows();
    foreach (PROJECT_INFO_LOCALES as $locale) {
        $data = $locales[$locale] ?? [];
        $d = [
            'name' => (string) ($data['name'] ?? '') ?: $defaults[$locale]['name'],
            'description' => (string) ($data['description'] ?? '') ?: $defaults[$locale]['description'],
            'iframe_back_text' => (string) ($data['iframe_back_text'] ?? '') ?: $defaults[$locale]['iframe_back_text'],
            'alert_message' => (string) ($data['alert_message'] ?? '') ?: $defaults[$locale]['alert_message'],
            'edit_button_text' => (string) ($data['edit_button_text'] ?? '') ?: $defaults[$locale]['edit_button_text'],
            'loading_text' => (string) ($data['loading_text'] ?? '') ?: $defaults[$locale]['loading_text'],
        ];
        $stmt->execute([
            ':locale' => $locale,
            ':name' => $d['name'],
            ':description' => $d['description'],
            ':iframe_back_text' => $d['iframe_back_text'],
            ':alert_message' => $d['alert_message'],
            ':edit_button_text' => $d['edit_button_text'],
            ':loading_text' => $d['loading_text'],
        ]);
    }
    // Keep default constellation (id=0) in sync with English app name and tagline when Settings are saved
    db_ensure_constellations();
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

/**
 * Ensure user_constellations table exists (for editor constellation access).
 */
function db_ensure_user_constellations_table(): void {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_constellations'");
    if ($stmt->fetch() === false) {
        $pdo->exec("
            CREATE TABLE user_constellations (
                user_id VARCHAR(255) NOT NULL,
                constellation_id INT NOT NULL,
                PRIMARY KEY (user_id, constellation_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_constellation_id (constellation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/** @return list<int> */
function db_get_user_constellation_ids(string $userId): array {
    db_ensure_user_constellations_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT constellation_id FROM user_constellations WHERE user_id = :user_id ORDER BY constellation_id");
    $stmt->execute([':user_id' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function db_set_user_constellations(string $userId, array $constellationIds): void {
    db_ensure_user_constellations_table();
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
    db_ensure_constellations();
    $pdo = getDB();
    $stmt = $pdo->query("SELECT id, name, tagline FROM constellations ORDER BY id");
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
    db_ensure_constellations();
    db_ensure_user_constellations_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.tagline
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
    db_ensure_constellations();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT name, tagline FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'name' => (string) ($row['name'] ?? ''),
        'tagline' => (string) ($row['tagline'] ?? '')
    ];
}

/**
 * Create a new constellation with the next available id. Returns the new id.
 */
function db_create_constellation(string $name, string $tagline = ''): int {
    db_ensure_constellations();
    $pdo = getDB();
    $stmt = $pdo->query("SELECT COALESCE(MAX(id), -1) + 1 AS next_id FROM constellations");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = (int)($row['next_id'] ?? 1);
    $pdo->prepare("INSERT INTO constellations (id, name, tagline) VALUES (:id, :name, :tagline)")->execute([
        ':id' => $nextId,
        ':name' => trim($name) ?: 'Unnamed',
        ':tagline' => trim($tagline)
    ]);
    return $nextId;
}

/**
 * Update constellation name and tagline. Id cannot be changed. Default constellation (id=0) can be renamed.
 */
function db_update_constellation(int $id, string $name, string $tagline = ''): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE constellations SET name = :name, tagline = :tagline WHERE id = :id")->execute([
        ':name' => trim($name) ?: 'Unnamed',
        ':tagline' => trim($tagline),
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
 * Ensure the default constellation (id=0) exists. Does not overwrite existing row.
 * Schema (table and columns) is created by setup only.
 */
function db_ensure_constellations(): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => DEFAULT_CONSTELLATION_ID]);
    if ($stmt->fetch() === false) {
        $defaultName = db_default_constellation_name($pdo);
        $defaultTagline = db_default_constellation_tagline($pdo);
        $pdo->prepare("INSERT INTO constellations (id, name, tagline) VALUES (:id, :name, :tagline)")->execute([
            ':id' => DEFAULT_CONSTELLATION_ID,
            ':name' => $defaultName,
            ':tagline' => $defaultTagline
        ]);
    }
}

// ---------------------------------------------------------------------------
// Nodes
// ---------------------------------------------------------------------------

/**
 * @return list<array<string, mixed>>
 */
/**
 * @param int|null $constellationId If set, only return nodes in this constellation; null = all nodes
 * @return list<array<string, mixed>>
 */
function db_get_nodes(?int $constellationId = null): array {
    db_ensure_constellations();
    $pdo = getDB();
    if ($constellationId !== null) {
        $stmt = $pdo->prepare("
            SELECT n.id, n.name, n.description, n.url, n.animation, n.created_at, n.constellation_id,
                   c.name AS constellation_name
            FROM nodes n
            LEFT JOIN constellations c ON c.id = n.constellation_id
            WHERE n.constellation_id = :constellation_id
            ORDER BY n.id
        ");
        $stmt->execute([':constellation_id' => $constellationId]);
        return $stmt->fetchAll();
    }
    $stmt = $pdo->query("
        SELECT n.id, n.name, n.description, n.url, n.animation, n.created_at, n.constellation_id,
               c.name AS constellation_name
        FROM nodes n
        LEFT JOIN constellations c ON c.id = n.constellation_id
        ORDER BY n.id
    ");
    return $stmt->fetchAll();
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
    return [
        'id' => (int)$node['id'],
        'name' => $node['name'],
        'description' => $node['description'] ?? null,
        'url' => $node['url'] ?? null,
        'keywords' => $keywords,
        'animation' => $animation,
        'created_at' => $createdAt,
        'constellation_id' => isset($node['constellation_id']) ? (int)$node['constellation_id'] : DEFAULT_CONSTELLATION_ID,
        'constellation_name' => isset($node['constellation_name']) && (string)$node['constellation_name'] !== '' ? (string)$node['constellation_name'] : 'Default'
    ];
}

function db_save_node_keywords(int $nodeId, array $keywords): void {
    db_ensure_constellations();
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
            error_log("Error saving keyword '{$keyword}' for node {$nodeId}: " . $e->getMessage());
        }
    }
}

function db_create_node(string $name, ?string $description, ?string $url, string $animation, int $constellationId = DEFAULT_CONSTELLATION_ID): int {
    db_ensure_constellations();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, animation, constellation_id)
        VALUES (:name, :description, :url, :animation, :constellation_id)
    ");
    $stmt->execute([
        ':name' => $name,
        ':description' => $description,
        ':url' => $url,
        ':animation' => $animation,
        ':constellation_id' => $constellationId
    ]);
    return (int)$pdo->lastInsertId();
}

function db_update_node(int $id, string $name, ?string $description, ?string $url, string $animation, ?int $constellationId = null): void {
    $pdo = getDB();
    if ($constellationId !== null) {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, animation = :animation, constellation_id = :constellation_id WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':url' => $url,
            ':animation' => $animation,
            ':constellation_id' => $constellationId
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE nodes SET name = :name, description = :description, url = :url, animation = :animation WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':url' => $url,
            ':animation' => $animation
        ]);
    }
}

function db_delete_node(int $id): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM nodes WHERE id = :id");
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
    db_ensure_constellations();
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
    db_ensure_constellations();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :constellation_id)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
    ");
    $stmt->execute([':keyword' => $keyword, ':constellation_id' => $constellationId]);
    return (int)$pdo->lastInsertId();
}

function db_delete_keyword(int $id): void {
    db_ensure_constellations();
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM keywords WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

// ---------------------------------------------------------------------------
// Connections (derived from nodes + node_keywords)
// ---------------------------------------------------------------------------

/**
 * @return list<array{id: int, node1_id: int, node2_id: int, shared_keywords: list<string>, shared_count: int}>
 */
function db_get_connections(): array {
    db_ensure_constellations();
    $pdo = getDB();
    $nodesStmt = $pdo->query("SELECT n.id, n.name FROM nodes n ORDER BY n.id");
    $nodes = $nodesStmt->fetchAll();
    $nodeKeywords = [];
    foreach ($nodes as $node) {
        $stmt = $pdo->prepare("
            SELECT k.keyword FROM keywords k
            JOIN node_keywords nk ON k.id = nk.keyword_id
            WHERE nk.node_id = :node_id
        ");
        $stmt->execute([':node_id' => $node['id']]);
        $nodeKeywords[$node['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
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
