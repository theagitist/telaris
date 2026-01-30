<?php
declare(strict_types=1);

/**
 * Bootstrap: config check, DB, auth, project info.
 * Expects to be required from project root (e.g. index.php).
 * Sets: $isEditorOrAdmin, $projectName, $projectTagline
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
    if (!db_has_project_table()) {
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
$info = db_get_project_info();
if ($info && !empty($info['name'])) {
    $projectName = $info['name'];
}
if ($info && isset($info['description']) && (string)$info['description'] !== '') {
    $projectTagline = $info['description'];
}
