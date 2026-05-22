<?php
declare(strict_types=1);

/**
 * Setup script to initialize the database
 * Uses PHP 8.3 and MySQL 8 features
 * Automatically creates config.php from config_default.php template based on user input via web form
 * 
 * IMPORTANT: This script can only be run via web browser, not from CLI
 */

// Prevent CLI execution - only allow web GUI access
if (php_sapi_name() === 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    die("403 Forbidden\n\nThis setup script can only be run from a web browser.\nPlease access it via: http://your-domain.com/admin/setup.php\n");
}

$systemVersion = 'Unknown';
if (file_exists(__DIR__ . '/../VERSION')) {
    $systemVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
}

// Start session for storing schema details
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Security lockout: deny access once the app is fully installed ---
// If config.php exists, the DB is reachable, and at least one admin user exists,
// then setup is complete and this page must require admin login to reconfigure.
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath) && !isset($_GET['reconfigure'])) {
    // App is installed — redirect to admin console
    try {
        require_once $configPath;
        $pdo = getDB();
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE type = 2");
        if ((int)$stmt->fetchColumn() > 0) {
            header('Location: index.php');
            exit();
        }
    } catch (Throwable $e) {
        // DB not reachable — allow setup to proceed for repair
    }
} elseif (file_exists($configPath) && isset($_GET['reconfigure'])) {
    // Reconfigure requested — require admin authentication
    try {
        require_once $configPath;
        require_once __DIR__ . '/../utils/auth.php';
        $pdo = getDB();
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE type = 2");
        if ((int)$stmt->fetchColumn() > 0) {
            // Admin users exist — must be logged in as admin to reconfigure
            if (!isAdminLoggedIn()) {
                header('Location: ../utils/login.php?redirect=admin');
                exit();
            }
        }
    } catch (Throwable $e) {
        // DB not reachable — allow setup to proceed for repair
    }
}

// Required PHP extensions for Telaris
$requiredExtensions = [
    'pdo' => 'PDO (Database abstraction)',
    'pdo_mysql' => 'PDO MySQL (MySQL database support)',
    'json' => 'JSON (JSON encoding/decoding)',
    'mbstring' => 'Multibyte String (String handling)',
];

// Check extension status
$extensionStatus = [];
$allExtensionsLoaded = true;
foreach ($requiredExtensions as $ext => $name) {
    $loaded = extension_loaded($ext);
    $extensionStatus[$name] = $loaded;
    if (!$loaded) {
        $allExtensionsLoaded = false;
    }
}

// Function to extract config values from config.php
function extractConfigValues(): ?array {
    // config.php is in parent directory
    $configPath = dirname(__DIR__) . '/config.php';
    if (!file_exists($configPath)) {
        return null;
    }
    
    $config = [
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'DB_NAME' => 'telaris',
        'DB_USER' => 'telaris',
        'DB_PASS' => '',
    ];
    
    // Read config.php and extract define() values
    $content = file_get_contents($configPath);
    foreach ($config as $key => $default) {
        // Match the value between quotes — greedy (.*) ensures we capture up to the LAST quote before )
        if (preg_match("/define\s*\(\s*['\"]" . $key . "['\"]\s*,\s*['\"](.*)['\"](?:\s*\))/", $content, $matches)) {
            $config[$key] = $matches[1];
        }
    }
    
    return $config;
}

/**
 * Load database schema SQL from SCHEMA.sql in project root.
 * Must stay in sync with inc/db.php (project_info, constellations, user_constellations).
 */
function getDatabaseSchema(): string {
    $path = dirname(__DIR__) . '/SCHEMA.sql';
    if (!is_readable($path)) {
        throw new RuntimeException('Schema file not found or not readable: ' . $path);
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Failed to read schema file: ' . $path);
    }
    return $sql;
}

// Function to create database if it doesn't exist
function createDatabaseIfNotExists(string $host, string $port, string $dbname, string $user, string $pass): ?string {
    try {
        // Connect without specifying database
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
        $pdo = new PDO(
            dsn: $dsn,
            username: $user,
            password: $pass,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        
        // Create database if it doesn't exist
        $pdo->exec(sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            str_replace('`', '``', $dbname)
        ));
        
        return null; // Success
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// Function to test database connection
function testConnection(string $host, string $port, string $dbname, string $user, string $pass): ?string {
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
        $pdo = new PDO(
            dsn: $dsn,
            username: $user,
            password: $pass,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        return null; // Connection successful
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// Function to generate config.php content from config_default.php template
function generateConfigContent(string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPass): string {
    // Read the template file (config_default.php is in parent directory)
    $templatePath = dirname(__DIR__) . '/config_default.php';
    if (!file_exists($templatePath)) {
        throw new Exception('config_default.php template file not found');
    }
    
    $template = file_get_contents($templatePath);
    
    // Replace the database configuration values
    $template = str_replace(
        [
            "define('DB_HOST', '');",
            "define('DB_PORT', '');",
            "define('DB_NAME', '');",
            "define('DB_USER', '');",
            "define('DB_PASS', '');"
        ],
        [
            "define('DB_HOST', '" . addslashes($dbHost) . "');",
            "define('DB_PORT', '" . addslashes($dbPort) . "');",
            "define('DB_NAME', '" . addslashes($dbName) . "');",
            "define('DB_USER', '" . addslashes($dbUser) . "');",
            "define('DB_PASS', '" . addslashes($dbPass) . "');"
        ],
        $template
    );
    
    return $template;
}

// Function to execute database schema
function executeSchema(string $host, string $port, string $dbname, string $user, string $pass, string $websiteName = 'Telaris', string $websiteTagline = 'Weaving memory'): array {
    $details = [
        'success' => false,
        'error' => null,
        'database_created' => false,
        'tables_created' => [],
        'tables_skipped' => [],
        'default_api_key' => null
    ];
    
    try {
        // First, ensure database exists
        $dbError = createDatabaseIfNotExists($host, $port, $dbname, $user, $pass);
        if ($dbError !== null) {
            $details['error'] = "Failed to create database: " . $dbError;
            return $details;
        }
        $details['database_created'] = true;
        
        // Connect to the database
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
        $pdo = new PDO(
            dsn: $dsn,
            username: $user,
            password: $pass,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 30,
            ]
        );
        
        // Execute schema - execute each CREATE TABLE statement separately
        $schema = getDatabaseSchema();
        
        // Remove SQL comments (-- style) but preserve structure
        $lines = explode("\n", $schema);
        $cleanedLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines and comment-only lines
            if (empty($line) || preg_match('/^\s*--/', $line)) {
                continue;
            }
            // Remove inline comments
            $line = preg_replace('/\s*--.*$/', '', $line);
            if (!empty(trim($line))) {
                $cleanedLines[] = $line;
            }
        }
        $schema = implode("\n", $cleanedLines);
        
        // Split by semicolon (which should be at the end of each CREATE TABLE)
        $statements = explode(';', $schema);
        
        // Extract table names from CREATE TABLE statements
        foreach ($statements as $statement) {
            $statement = trim($statement);
            // Skip empty statements
            if (empty($statement)) {
                continue;
            }
            
            // Extract table name from CREATE TABLE statement
            if (preg_match('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $statement, $matches)) {
                $tableName = $matches[1];
                
                // Check if table already exists
                $tableExists = false;
                try {
                    $checkStmt = $pdo->query("SHOW TABLES LIKE '$tableName'");
                    $tableExists = $checkStmt->rowCount() > 0;
                } catch (PDOException $e) {
                    // Table check failed, proceed with creation
                }
                
                if ($tableExists) {
                    $details['tables_skipped'][] = $tableName;
            } else {
                // Execute the statement (add semicolon back since split removes it)
                    $pdo->exec($statement . ';');
                    $details['tables_created'][] = $tableName;
                }
            } else {
                // Execute non-CREATE TABLE statements (like indexes, etc.)
                $pdo->exec($statement . ';');
            }
        }

        // Set default constellation (id=0) name and tagline from setup form
        try {
            $defaultConstellationName = (trim($websiteName) !== '') ? trim($websiteName) : 'Default';
            $defaultConstellationTagline = (trim($websiteTagline) !== '') ? trim($websiteTagline) : '';
            $pdo->prepare("INSERT INTO constellations (id, name, tagline, theme) VALUES (0, :name, :tagline, 'cosmic') ON DUPLICATE KEY UPDATE name = VALUES(name), tagline = VALUES(tagline), theme = VALUES(theme)")->execute([':name' => $defaultConstellationName, ':tagline' => $defaultConstellationTagline]);
        } catch (PDOException $e) {
            // Table may not exist or column tagline missing before migration
        }
        
        // Generate default API key (db.php required for generateDefaultApiKey)
        require_once dirname(__DIR__) . '/inc/db.php';
        $defaultApiKey = generateDefaultApiKey($pdo);
        $details['default_api_key'] = $defaultApiKey;
        
        // project_info: one row per locale (PROJECT_INFO_LOCALES from inc/db.php).
        try {
            $configPath = dirname(__DIR__) . '/config.php';
            if (file_exists($configPath)) {
                require_once $configPath;
            }
            require_once dirname(__DIR__) . '/inc/db.php';
            db_ensure_project_info_table();
            db_insert_default_project_info_rows($pdo, $websiteName, $websiteTagline);
        } catch (Throwable $e) {
            require_once dirname(__DIR__) . '/inc/db.php';
            $defaults = db_default_project_info_rows($websiteName, $websiteTagline);
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
        
        $details['success'] = true;
        return $details;
    } catch (PDOException $e) {
        $details['error'] = $e->getMessage();
        return $details;
    }
}

// Handle check_config request (for JavaScript to check if config.php exists)
if (isset($_GET['check_config']) && $_GET['check_config'] == '1') {
    header('Content-Type: application/json');
    $configPath = dirname(__DIR__) . '/config.php';
    echo json_encode(['exists' => file_exists($configPath)], JSON_THROW_ON_ERROR);
    exit();
}

// Handle continue_setup request (after user creates config.php manually)
if (isset($_GET['continue_setup']) && $_GET['continue_setup'] == '1') {
    $configPath = dirname(__DIR__) . '/config.php';
    if (file_exists($configPath)) {
        // Clear the stored config content from session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['config_content_to_show']);
        // Get database values from the newly created config.php
        $existingConfig = extractConfigValues();
        if ($existingConfig) {
            // Test connection and proceed with schema creation
            $dbHost = $existingConfig['DB_HOST'] ?? 'localhost';
            $dbPort = $existingConfig['DB_PORT'] ?? '3306';
            $dbName = $existingConfig['DB_NAME'] ?? 'telaris';
            $dbUser = $existingConfig['DB_USER'] ?? 'telaris';
            $dbPass = $existingConfig['DB_PASS'] ?? '';
            
            // Use default website info for schema creation (will be updated later)
            $websiteName = 'Telaris';
            $websiteTagline = 'Weaving memory';
            
            // Execute schema
            $schemaDetails = executeSchema($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $websiteName, $websiteTagline);
            if ($schemaDetails['success']) {
                $_SESSION['schema_details'] = $schemaDetails;
                // Redirect to website info form
                header('Location: setup.php?step=website');
                exit();
            } else {
                $connectionError = "Database schema creation failed: " . ($schemaDetails['error'] ?? 'Unknown error');
                $showForm = true;
            }
        }
    } else {
        // Config still doesn't exist, show form with error
        $connectionError = "config.php file not found. Please create it first.";
        $showForm = true;
    }
}

// Check if user wants to reconfigure
$reconfigure = isset($_GET['reconfigure']) && $_GET['reconfigure'] == '1';

// Get existing config values if config.php exists
$existingConfig = extractConfigValues();
$showWebsiteForm = false;
$showForm = false;
$connectionError = null;
$configContentToShow = null; // Store config content if file creation fails
// Default values - use 'telaris' as default for database user
$dbHost = $existingConfig['DB_HOST'] ?? 'localhost';
$dbPort = $existingConfig['DB_PORT'] ?? '3306';
$dbName = $existingConfig['DB_NAME'] ?? 'telaris';
$dbUser = $existingConfig['DB_USER'] ?? 'telaris';
$dbPass = $existingConfig['DB_PASS'] ?? '';

// Website name and tagline
$websiteName = 'Telaris';
$websiteTagline = 'Weaving memory';

// Handle database connection form submission (step 1)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_config'])) {
    // Use default website info for now (will be updated after tables are created)
    $websiteName = 'Telaris';
    $websiteTagline = 'Weaving memory';
    
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? 'telaris');
    $dbUser = trim($_POST['db_user'] ?? 'telaris');
    $dbPass = $_POST['db_pass'] ?? '';
    
    // If password is empty and config.php exists, use existing password
    $configPath = dirname(__DIR__) . '/config.php';
    if (empty($dbPass) && file_exists($configPath)) {
        $existingConfig = extractConfigValues();
        $dbPass = $existingConfig['DB_PASS'] ?? '';
    }
    
    // Test connection before saving
    $connectionError = testConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
    
    if ($connectionError === null) {
        // Connection successful, generate and save config
        $configContent = generateConfigContent($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
        
        // Write config.php (in parent directory)
        $configPath = dirname(__DIR__) . '/config.php';
        if (file_put_contents($configPath, $configContent) === false) {
            // Store config content to show in textarea
            $configContentToShow = $configContent;
            $connectionError = "Error: Could not write config.php. Please check file permissions.";
            // Store in session so it persists across page loads
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['config_content_to_show'] = $configContentToShow;
        } else {
            // Clear any stored config content on success
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            unset($_SESSION['config_content_to_show']);
            // Execute database schema (with default website info for now)
            $schemaDetails = executeSchema($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $websiteName, $websiteTagline);
            if (!$schemaDetails['success']) {
                $connectionError = "Database schema creation failed: " . ($schemaDetails['error'] ?? 'Unknown error');
            } else {
                // Store schema details in session for display
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['schema_details'] = $schemaDetails;
                // Clear any stored config content on success
                unset($_SESSION['config_content_to_show']);
                // Redirect to website info form (step 2)
                header('Location: setup.php?step=website');
                exit();
            }
        }
    }
    // If connection failed or config write failed, show form with error
    $showForm = true;
    // Retrieve config content from session if available
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['config_content_to_show'])) {
        $configContentToShow = $_SESSION['config_content_to_show'];
    }
} else {
    // Check which form to show
    $step = $_GET['step'] ?? null;
    
    $configPath = dirname(__DIR__) . '/config.php';
    if ($reconfigure || !file_exists($configPath)) {
        // New setup or reconfigure - show database form first
        if ($step === 'website') {
            // Show website info form (after tables created)
            $showWebsiteForm = true;
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $websiteName = $_SESSION['website_name'] ?? 'Telaris';
            $websiteTagline = $_SESSION['website_tagline'] ?? 'Weaving memory';
        } else {
            // Show database form first
            $showForm = true;
        }
    } else {
        // Config exists, test connection with extracted values (not from constants)
        // Use extracted values to avoid using potentially outdated constants from config.php
        $connectionError = testConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
        if ($connectionError !== null) {
            // Connection failed, show form
            $showForm = true;
        }
    }
    
    // Check for stored config content in session (from previous failed write attempt)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['config_content_to_show']) && !empty($_SESSION['config_content_to_show'])) {
        $configContentToShow = $_SESSION['config_content_to_show'];
        // Only show form if config.php doesn't exist yet
        $configPath = dirname(__DIR__) . '/config.php';
        if (!file_exists($configPath)) {
            $showForm = true;
        } else {
            // Config.php exists now, clear the stored content
            unset($_SESSION['config_content_to_show']);
            $configContentToShow = null;
        }
    }
}

// Handle website info form submission (step 2 - after tables created)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['website_info'])) {
    $websiteName = trim($_POST['website_name'] ?? 'Telaris');
    $websiteTagline = trim($_POST['website_tagline'] ?? 'Weaving memory');
    
    if (empty($websiteName)) {
        $websiteName = 'Telaris';
    }
    if (empty($websiteTagline)) {
        $websiteTagline = 'Weaving memory';
    }
    
    // Store in session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['website_name'] = $websiteName;
    $_SESSION['website_tagline'] = $websiteTagline;
    
    // Update project_info table with website info and ensure all locale/default columns exist
    try {
        require_once dirname(__DIR__) . '/config.php';
        db_insert_default_project_info_rows(getDB(), $websiteName, $websiteTagline);
        db_ensure_project_info_columns();
    } catch (Exception $e) {
        // Ignore errors, continue to admin user creation
    }
    
    // Redirect to success page (which will show admin user creation form if needed)
    header('Location: setup.php?success=1');
    exit();
}

// Show website info form if needed (step 2 - after tables created)
if ($showWebsiteForm) {
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" href="/favicon.png" type="image/png">
        <title>Telaris - Setup</title>
        <script src="../js/tailwind.min.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
    </head>
    <body class="font-sans max-w-2xl mx-auto my-12 px-5 bg-gray-100">
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h1 class="text-gray-800 mb-2 text-2xl font-semibold">Telaris Setup</h1>
            <div class="flex justify-between items-center mb-8">
                <p class="text-gray-600">Configure your website information</p>
                <div class="bg-gray-100 px-3 py-1 rounded-md border border-gray-200">
                    <span class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Version</span>
                    <span class="ml-1.5 font-mono text-xs font-bold text-gray-700"><?php echo htmlspecialchars($systemVersion); ?></span>
                </div>
            </div>
            
            <?php if (isset($_SESSION['schema_details']) && $_SESSION['schema_details']['success']): ?>
                <div class="bg-green-50 text-green-600 p-4 rounded mb-5">
                    ✓ Database tables created successfully!
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="website_info" value="1">
                <p class="text-sm text-gray-600 mb-5">These values are used for the default constellation and project info. You can change them later in Admin → Global Settings and Constellations.</p>
                <div class="mb-5">
                    <label for="website_name" class="block mb-1.5 text-gray-800 font-medium">Website Name</label>
                    <input type="text" id="website_name" name="website_name" value="<?php echo htmlspecialchars($websiteName); ?>" placeholder="Telaris" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">The name of your website/project. Default: Telaris</span>
                </div>
                
                <div class="mb-5">
                    <label for="website_tagline" class="block mb-1.5 text-gray-800 font-medium">Tagline</label>
                    <input type="text" id="website_tagline" name="website_tagline" value="<?php echo htmlspecialchars($websiteTagline); ?>" placeholder="Weaving memory" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">A short description or tagline. Default: Weaving memory</span>
                </div>
                
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-3 px-8 rounded text-base cursor-pointer w-full">Continue</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Show database configuration form if needed (step 2)
if ($showForm) {
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" href="/favicon.png" type="image/png">
        <title>Telaris - Setup</title>
        <script src="../js/tailwind.min.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
    </head>
    <body class="font-sans max-w-2xl mx-auto my-12 px-5 bg-gray-100">
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h1 class="text-gray-800 mb-2 text-2xl font-semibold">Telaris Setup</h1>
            <div class="flex justify-between items-center mb-8">
                <p class="text-gray-600">Configure your database connection</p>
                <div class="bg-gray-100 px-3 py-1 rounded-md border border-gray-200">
                    <span class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Version</span>
                    <span class="ml-1.5 font-mono text-xs font-bold text-gray-700"><?php echo htmlspecialchars($systemVersion); ?></span>
                </div>
            </div>
            
            <!-- PHP Requirements Check -->
            <div class="mb-8 p-4 bg-gray-50 rounded">
                <h3 class="mb-2.5 text-gray-800 text-lg font-semibold">PHP Requirements</h3>
                <p class="mb-2.5 text-gray-600">PHP Version: <strong><?php echo PHP_VERSION; ?></strong></p>
                <div class="mt-4">
                    <?php foreach ($extensionStatus as $name => $installed): ?>
                        <div class="flex items-center gap-2.5 py-2 <?php echo $installed ? 'text-green-600' : 'text-red-600 font-semibold'; ?>">
                            <span class="font-bold text-xl"><?php echo $installed ? '✓' : '✗'; ?></span>
                            <span><?php echo htmlspecialchars($name); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$allExtensionsLoaded): ?>
                    <div class="bg-yellow-50 text-yellow-800 p-4 rounded mt-4 border-l-4 border-yellow-400">
                        <strong>Warning:</strong> Some required PHP extensions are missing. Please install them before proceeding.
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($connectionError): ?>
                <div class="bg-red-50 text-red-700 p-4 rounded mb-5">
                    <strong>Error:</strong><br>
                    <?php echo htmlspecialchars($connectionError); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($configContentToShow) && !empty($configContentToShow)): ?>
                <div class="bg-yellow-50 border-2 border-yellow-400 rounded p-6 mb-5">
                    <h3 class="text-yellow-800 text-lg font-semibold mb-3">Manual Configuration Required</h3>
                    <p class="text-yellow-700 mb-4">
                        The setup script could not create <code class="bg-yellow-100 px-2 py-1 rounded">config.php</code> automatically. 
                        Please create this file manually on your server with the following content:
                    </p>
                    <div class="mb-4">
                        <label for="config_content" class="block mb-2 text-sm font-medium text-gray-700">
                            Copy the content below and save it as <code class="bg-gray-100 px-2 py-1 rounded">config.php</code> in the root directory:
                        </label>
                        <textarea 
                            id="config_content" 
                            readonly 
                            class="w-full h-96 p-3 border border-gray-300 rounded font-mono text-sm bg-gray-50"
                            onclick="this.select(); document.execCommand('copy');"
                        ><?php echo htmlspecialchars($configContentToShow); ?></textarea>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded p-4">
                        <p class="text-blue-800 text-sm font-medium mb-2">Instructions:</p>
                        <ol class="list-decimal list-inside text-blue-700 text-sm space-y-1">
                            <li>Click on the textarea above to select all the content</li>
                            <li>Copy the content (Ctrl+C or Cmd+C)</li>
                            <li>Create a new file named <code class="bg-blue-100 px-1 rounded">config.php</code> in the root directory of your application</li>
                            <li>Paste the copied content into the file</li>
                            <li>Save the file</li>
                            <li>Click the "Proceed" button below once the file is created</li>
                        </ol>
                    </div>
                    <div class="mt-4 flex gap-3">
                        <button 
                            type="button" 
                            onclick="document.getElementById('config_content').select(); document.execCommand('copy'); alert('Content copied to clipboard!');"
                            class="bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded text-sm"
                        >
                            Copy to Clipboard
                        </button>
                        <button 
                            type="button" 
                            id="proceed_btn"
                            onclick="checkConfigAndProceed()"
                            class="bg-green-500 hover:bg-green-600 text-white py-2 px-4 rounded text-sm font-medium"
                        >
                            Proceed
                        </button>
                    </div>
                    <div id="proceed_status" class="mt-3 text-sm"></div>
                </div>
                <script>
                    function checkConfigAndProceed() {
                        const btn = document.getElementById('proceed_btn');
                        const status = document.getElementById('proceed_status');
                        btn.disabled = true;
                        btn.textContent = 'Checking...';
                        status.innerHTML = '<span class="text-blue-600">Checking if config.php exists...</span>';
                        
                        // Check if config.php exists
                        fetch('setup.php?check_config=1')
                            .then(response => response.json())
                            .then(data => {
                                if (data.exists) {
                                    status.innerHTML = '<span class="text-green-600">✓ config.php found! Continuing setup...</span>';
                                    // Redirect to continue setup
                                    setTimeout(() => {
                                        window.location.href = 'setup.php?continue_setup=1';
                                    }, 1000);
                                } else {
                                    btn.disabled = false;
                                    btn.textContent = 'Proceed';
                                    status.innerHTML = '<span class="text-red-600">✗ config.php not found. Please create the file first.</span>';
                                }
                            })
                            .catch(error => {
                                btn.disabled = false;
                                btn.textContent = 'Proceed';
                                status.innerHTML = '<span class="text-red-600">Error checking file. Please refresh the page manually.</span>';
                            });
                    }
                </script>
            <?php endif; ?>
            
            <?php if (!isset($configContentToShow) || empty($configContentToShow)): ?>
            <form method="POST" action="">
                <input type="hidden" name="db_config" value="1">
                <p class="text-sm text-gray-600 mb-5">Enter your database connection details. Defaults are suggested; leave password empty if your MySQL user has none.</p>
                <div class="mb-5">
                    <label for="db_host" class="block mb-1.5 text-gray-800 font-medium">Database Host</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($dbHost); ?>" placeholder="localhost" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Usually <code>localhost</code> or an IP address. Default: localhost</span>
                </div>
                
                <div class="mb-5">
                    <label for="db_port" class="block mb-1.5 text-gray-800 font-medium">Database Port</label>
                    <input type="number" id="db_port" name="db_port" value="<?php echo htmlspecialchars($dbPort); ?>" placeholder="3306" min="1" max="65535" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Default MySQL port: 3306</span>
                </div>
                
                <div class="mb-5">
                    <label for="db_name" class="block mb-1.5 text-gray-800 font-medium">Database Name</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($dbName); ?>" placeholder="telaris" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">Name of your MySQL database. Default: telaris (created if missing)</span>
                </div>
                
                <div class="mb-5">
                    <label for="db_user" class="block mb-1.5 text-gray-800 font-medium">Database User</label>
                    <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($dbUser); ?>" placeholder="telaris" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block">MySQL username. Default: telaris</span>
                </div>
                
                <div class="mb-5">
                    <label for="db_pass" class="block mb-1.5 text-gray-800 font-medium">Database Password</label>
                    <input type="password" id="db_pass" name="db_pass" value="" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500" placeholder="<?php $configPath = dirname(__DIR__) . '/config.php'; echo file_exists($configPath) && $dbPass !== '' ? '(Leave empty to keep current)' : '(optional)'; ?>">
                    <span class="text-xs text-gray-500 mt-1 block"><?php $configPath = dirname(__DIR__) . '/config.php'; echo file_exists($configPath) ? 'Leave empty to keep current password, or enter a new one.' : 'Leave empty if your MySQL user has no password.'; ?></span>
                </div>
                
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-3 px-8 rounded text-base cursor-pointer w-full"><?php $configPath = dirname(__DIR__) . '/config.php'; echo file_exists($configPath) ? 'Update Configuration' : 'Create Configuration'; ?></button>
            </form>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Config exists and connection is working, show success page
// Only require config.php if we're not showing a form (to avoid loading wrong constants)
if (!$showForm && !$showWebsiteForm) {
    require_once dirname(__DIR__) . '/config.php';
}

// Check for success parameter
$success = isset($_GET['success']) && $_GET['success'] == '1';

$message = null;
$error = null;
$pdo = null;
$adminUserCreated = false;

$schemaDetails = null;

// Only try to connect if config.php exists and we're not showing a form
$configPath = dirname(__DIR__) . '/config.php';
if (!$showForm && !$showWebsiteForm && file_exists($configPath)) {
    require_once $configPath;
    try {
        $pdo = getDB();
    
        if ($pdo) {
            // Get website info from session if available
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $projectName = $_SESSION['website_name'] ?? 'Telaris';
            $projectDescription = $_SESSION['website_tagline'] ?? 'Weaving memory';
            
            // Check if tables exist, if not execute schema
            $tablesCheck = $pdo->query("SHOW TABLES LIKE 'project_info'")->fetch();
            if ($tablesCheck === false) {
            // Tables don't exist, execute schema
            // Use extracted config values instead of constants
            $extractedConfig = extractConfigValues();
            if ($extractedConfig) {
                $schemaDetails = executeSchema(
                    $extractedConfig['DB_HOST'], 
                    $extractedConfig['DB_PORT'], 
                    $extractedConfig['DB_NAME'], 
                    $extractedConfig['DB_USER'], 
                    $extractedConfig['DB_PASS'], 
                    $projectName, 
                    $projectDescription
                );
            } else {
                throw new Exception("Could not extract database configuration");
            }
                if (!$schemaDetails['success']) {
                    throw new Exception("Failed to create database schema: " . ($schemaDetails['error'] ?? 'Unknown error'));
                }
                $message = "Setup complete! Database schema created and project information initialized.";
            } else {
                // Tables exist, check if default API key exists, if not create it
                // Use getDefaultApiKey() from config.php if available, otherwise direct query
                $apiKeyExists = false;
                if (function_exists('getDefaultApiKey')) {
                    $apiKeyExists = getDefaultApiKey($pdo) !== null;
                } else {
                    // Fallback: direct query (used during initial setup)
                    $apiKeyCheck = $pdo->query("SELECT api_key FROM api_keys WHERE name = 'Default API Key' LIMIT 1")->fetch();
                    $apiKeyExists = $apiKeyCheck !== false;
                }
                
                if (!$apiKeyExists) {
                    // Default API key doesn't exist, generate it
                    $defaultApiKey = generateDefaultApiKey($pdo);
                    if ($defaultApiKey) {
                        $schemaDetails = [
                            'success' => true,
                            'default_api_key' => $defaultApiKey,
                            'tables_created' => [],
                            'tables_skipped' => []
                        ];
                    }
                }
                // Tables exist, just ensure project_info has default values
                $message = "Setup complete! Project information initialized.";
            }
            
            // Ensure project_info table has values (use session values if available, otherwise defaults)
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $projectName = $_SESSION['website_name'] ?? 'Telaris';
            $projectDescription = $_SESSION['website_tagline'] ?? 'Weaving memory';
            
            db_insert_default_project_info_rows($pdo, $projectName, $projectDescription);
            db_ensure_project_info_columns();
            
            if (!isset($message)) {
                $message = "Setup complete! Project information initialized.";
            }
        }
    } catch (PDOException $e) {
        $error = "Database Error: " . $e->getMessage();
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get schema details from session if available (from form submission)
if (isset($_SESSION['schema_details'])) {
    $schemaDetails = $_SESSION['schema_details'];
    unset($_SESSION['schema_details']); // Clear after use
}

// Handle admin user creation form
$adminUserError = null;
$showAdminForm = false;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    // Admin user creation form submitted
    if ($pdo) {
        $email = trim($_POST['admin_email'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $passwordConfirm = $_POST['admin_password_confirm'] ?? '';
        $firstname = trim($_POST['admin_firstname'] ?? '');
        $lastname = trim($_POST['admin_lastname'] ?? '');
        
        if (empty($email) || empty($password) || empty($firstname) || empty($lastname)) {
            $adminUserError = 'All fields are required';
        } elseif ($password !== $passwordConfirm) {
            $adminUserError = 'Passwords do not match';
        } elseif (strlen($password) < 8) {
            $adminUserError = 'Password must be at least 8 characters long';
        } else {
            $result = createAdminUser($pdo, $email, $password, $firstname, $lastname);
            if ($result === null) {
                $adminUserCreated = true;
                $message = "Admin user created successfully! You can now login at the admin console.";
            } else {
                $adminUserError = $result;
            }
        }
    } else {
        $adminUserError = 'Database connection not available';
    }
}

// Check if admin user exists
if ($pdo && !$adminUserCreated) {
    $showAdminForm = !hasAdminUser($pdo);
}

// Show results page
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>Telaris - Setup</title>
    <script src="js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
</head>
<body class="font-sans max-w-2xl mx-auto my-12 px-5 bg-gray-100">
    <div class="bg-white p-8 rounded-lg shadow-md">
        <h1 class="text-gray-800 mb-2 text-2xl font-semibold">Telaris Setup</h1>
        <div class="flex justify-end items-center mb-4">
            <div class="bg-gray-100 px-2 py-0.5 rounded border border-gray-200">
                <span class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">v</span>
                <span class="font-mono text-[10px] font-bold text-gray-500"><?php echo htmlspecialchars($systemVersion); ?></span>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="bg-green-50 text-green-600 p-4 rounded mb-5">✓ Configuration file created successfully!</div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="bg-green-50 text-green-600 p-4 rounded mb-5"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-50 text-red-700 p-4 rounded mb-5"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Database Schema Creation Details -->
        <?php if ($schemaDetails !== null && $schemaDetails['success']): ?>
            <div class="mb-5 p-4 bg-blue-50 border border-blue-200 rounded">
                <h3 class="mb-3 text-blue-800 text-lg font-semibold">Database Schema Creation Details</h3>
                
                <?php if ($schemaDetails['database_created']): ?>
                    <div class="mb-3">
                        <span class="text-green-600 font-semibold">✓</span>
                        <span class="text-gray-700">Database <strong><?php echo htmlspecialchars(DB_NAME); ?></strong> created successfully</span>
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <span class="text-gray-500">ℹ</span>
                        <span class="text-gray-700">Database <strong><?php echo htmlspecialchars(DB_NAME); ?></strong> already exists</span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($schemaDetails['tables_created'])): ?>
                    <div class="mb-3">
                        <p class="text-gray-700 font-medium mb-2">Tables Created (<?php echo count($schemaDetails['tables_created']); ?>):</p>
                        <ul class="list-disc list-inside ml-2 space-y-1">
                            <?php foreach ($schemaDetails['tables_created'] as $table): ?>
                                <li class="text-green-600">
                                    <span class="text-green-600 font-semibold">✓</span>
                                    <span class="text-gray-700 font-mono"><?php echo htmlspecialchars($table); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($schemaDetails['tables_skipped'])): ?>
                    <div class="mb-3">
                        <p class="text-gray-700 font-medium mb-2">Tables Already Existed (<?php echo count($schemaDetails['tables_skipped']); ?>):</p>
                        <ul class="list-disc list-inside ml-2 space-y-1">
                            <?php foreach ($schemaDetails['tables_skipped'] as $table): ?>
                                <li class="text-gray-500">
                                    <span class="text-gray-500">⊘</span>
                                    <span class="text-gray-600 font-mono"><?php echo htmlspecialchars($table); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($schemaDetails['tables_created']) && empty($schemaDetails['tables_skipped'])): ?>
                    <div class="text-gray-600 text-sm">No tables were created or skipped.</div>
                <?php endif; ?>
                
                <?php if (!empty($schemaDetails['default_api_key'])): ?>
                    <div class="mt-4 pt-4 border-t border-blue-300">
                        <p class="text-gray-700 font-medium mb-2">✓ Default API Key Generated</p>
                        <p class="text-sm text-gray-600 mb-2">A default API key has been automatically generated and is being used by the application. You can manage API keys in the API Key Management page.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Admin User Creation Form -->
        <?php if ($showAdminForm && !$adminUserCreated): ?>
            <div class="mb-5 p-6 bg-yellow-50 border-2 border-yellow-400 rounded">
                <h3 class="mb-3 text-yellow-800 text-lg font-semibold">Create Admin User</h3>
                <p class="mb-4 text-yellow-700 text-sm">No admin user exists yet. Please create an admin user to access the admin console.</p>
                
                <?php if ($adminUserError): ?>
                    <div class="bg-red-50 text-red-700 p-3 rounded mb-4">
                        <?php echo htmlspecialchars($adminUserError); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" class="space-y-4">
                    <input type="hidden" name="create_admin" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="admin_firstname" class="block mb-1.5 text-gray-800 font-medium">First Name *</label>
                            <input type="text" 
                                   id="admin_firstname" 
                                   name="admin_firstname" 
                                   required 
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="admin_lastname" class="block mb-1.5 text-gray-800 font-medium">Last Name *</label>
                            <input type="text" 
                                   id="admin_lastname" 
                                   name="admin_lastname" 
                                   required 
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <div>
                        <label for="admin_email" class="block mb-1.5 text-gray-800 font-medium">Email *</label>
                        <input type="email" 
                               id="admin_email" 
                               name="admin_email" 
                               required 
                               class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block">This will be your login email</span>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="admin_password" class="block mb-1.5 text-gray-800 font-medium">Password *</label>
                            <input type="password" 
                                   id="admin_password" 
                                   name="admin_password" 
                                   required 
                                   minlength="8"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            <span class="text-xs text-gray-500 mt-1 block">Minimum 8 characters</span>
                        </div>
                        
                        <div>
                            <label for="admin_password_confirm" class="block mb-1.5 text-gray-800 font-medium">Confirm Password *</label>
                            <input type="password" 
                                   id="admin_password_confirm" 
                                   name="admin_password_confirm" 
                                   required 
                                   minlength="8"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                        Create Admin User
                    </button>
                </form>
            </div>
        <?php elseif ($adminUserCreated): ?>
            <div class="mb-5 p-4 bg-green-50 border-2 border-green-500 rounded">
                <p class="text-green-800 font-semibold">✓ Admin user created successfully!</p>
                <p class="text-green-700 text-sm mt-2">You can now login at the <a href="../utils/login.php" class="underline font-medium">login page</a>.</p>
            </div>
        <?php endif; ?>
        
        <div class="mt-5 p-4 bg-gray-50 rounded text-gray-600">
            <p class="font-semibold mb-2">Setup Status:</p>
            <ul class="my-2.5 pl-5">
                <li class="my-1.5">Configuration file: <?php $configPath = dirname(__DIR__) . '/config.php'; echo file_exists($configPath) ? '✓ Created' : '✗ Missing'; ?></li>
                <li class="my-1.5">Database connection: <?php echo isset($pdo) ? '✓ Connected' : '✗ Failed'; ?></li>
                <li class="my-1.5">Project info: <?php echo $message ? '✓ Initialized' : '✗ Not initialized'; ?></li>
            </ul>
            <p class="mt-5">
                <a href="../index.php" class="text-blue-500 hover:underline">Go to Telaris →</a> | 
                <a href="index.php" class="text-blue-500 hover:underline">Admin Console</a> | 
                <a href="setup.php?reconfigure=1" class="text-blue-500 hover:underline">Reconfigure Database</a>
            </p>
        </div>
    </div>
</body>
</html>
