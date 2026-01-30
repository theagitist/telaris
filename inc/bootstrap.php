<?php
declare(strict_types=1);

/**
 * Bootstrap: config check, DB, auth, project info.
 * Expects to be required from project root (e.g. index.php).
 * Sets: $pdo, $isEditorOrAdmin, $projectName, $projectTagline
 */

$root = dirname(__DIR__);

if (!file_exists($root . '/config.php')) {
    header('Location: admin/setup.php');
    exit();
}

try {
    require_once $root . '/config.php';
    if (empty(DB_HOST) || empty(DB_NAME) || empty(DB_USER)) {
        header('Location: admin/setup.php');
        exit();
    }
    $pdo = getDB();
    $tablesCheck = $pdo->query("SHOW TABLES LIKE 'project_info'")->fetch();
    if ($tablesCheck === false) {
        header('Location: admin/setup.php');
        exit();
    }
} catch (PDOException $e) {
    header('Location: admin/setup.php');
    exit();
} catch (Exception $e) {
    header('Location: admin/setup.php');
    exit();
}

require_once $root . '/auth.php';
$isEditorOrAdmin = isEditorOrAdminLoggedIn();

$projectName = 'Telaris';
$projectTagline = 'Weaving memory';
try {
    $info = $pdo->query("SELECT name, description FROM project_info WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($info && !empty($info['name'])) {
        $projectName = $info['name'];
    }
    if ($info && isset($info['description']) && (string)$info['description'] !== '') {
        $projectTagline = $info['description'];
    }
} catch (PDOException $e) {
    // keep defaults
}
