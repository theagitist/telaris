<?php
declare(strict_types=1);

/**
 * Bootstrap: config check, DB, auth, project info.
 * Expects to be required from project root (e.g. index.php).
 * Sets: $isEditorOrAdmin, $projectName, $projectTagline, $projectIframeBackText, $projectAlertMessage
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

require_once $root . '/utils/auth.php';
$isEditorOrAdmin = isEditorOrAdminLoggedIn();

$projectName = 'Telaris';
$projectTagline = 'Weaving memory';
$projectIframeBackText = 'Go back';
$projectAlertMessage = "Close this window when you're done to go back.";
$info = db_get_project_info();
if ($info && !empty($info['name'])) {
    $projectName = $info['name'];
}
if ($info && isset($info['description']) && (string)$info['description'] !== '') {
    $projectTagline = $info['description'];
}
if ($info && isset($info['iframe_back_text']) && (string)$info['iframe_back_text'] !== '') {
    $projectIframeBackText = $info['iframe_back_text'];
}
if ($info && isset($info['alert_message']) && (string)$info['alert_message'] !== '') {
    $projectAlertMessage = $info['alert_message'];
}
