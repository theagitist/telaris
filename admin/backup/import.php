<?php
declare(strict_types=1);

/**
 * Admin endpoint: inspect or commit a backup file.
 *
 * Phase 1 (inspect):
 *   POST multipart/form-data, ?phase=inspect
 *   Fields: backup_file (file), csrf_token
 *   Returns JSON: { ok: true, summary: {...}, temp_id: "..." }
 *   The uploaded file is parked in a session-scoped temp path for phase 2.
 *
 * Phase 2 (commit):
 *   POST application/json, ?phase=commit
 *   Body: { csrf_token, temp_id, mode, restore_users, users_replace_existing,
 *           users_replace_password, restore_media, galaxies: { ref: {include, conflict, rename_suffix} },
 *           rename_suffix_default, confirm }
 *   Returns JSON: { ok: true, report: {...} }
 *
 * CSRF: validated for both phases (form field on inspect, header/body on commit).
 * Auth: admin session only.
 */

require_once __DIR__ . '/../../utils/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/backup.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$phase = $_GET['phase'] ?? '';
if ($phase !== 'inspect' && $phase !== 'commit') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid phase parameter']);
    exit;
}

function backup_import_temp_path(string $tempId): string {
    // Bound to admin user id to prevent cross-session access
    $userId = $_SESSION['admin_user_id'] ?? 'unknown';
    $hash = preg_replace('/[^a-z0-9]/i', '', $tempId);
    return sys_get_temp_dir() . '/telaris-import-' . preg_replace('/[^a-z0-9_]/i', '', $userId) . '-' . $hash . '.bin';
}

if ($phase === 'inspect') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
        exit;
    }

    if (!isset($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File upload failed (code ' . (int)$err . ')']);
        exit;
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $tempId = bin2hex(random_bytes(16));
    $dest = backup_import_temp_path($tempId);

    if (!@move_uploaded_file($tmp, $dest)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not save uploaded file']);
        exit;
    }
    @chmod($dest, 0600);

    try {
        $summary = backup_inspect_file($dest);
    } catch (Throwable $e) {
        @unlink($dest);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    // Track the temp id in the session so we know which files belong to this admin
    if (!isset($_SESSION['backup_imports']) || !is_array($_SESSION['backup_imports'])) {
        $_SESSION['backup_imports'] = [];
    }
    $_SESSION['backup_imports'][$tempId] = ['path' => $dest, 'created' => time()];

    echo json_encode(['ok' => true, 'temp_id' => $tempId, 'summary' => $summary]);
    exit;
}

// phase === 'commit'
$body = file_get_contents('php://input');
$payload = json_decode($body ?: '{}', true);
if (!is_array($payload)) $payload = [];

$csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
    exit;
}

$tempId = (string)($payload['temp_id'] ?? '');
$tracked = $_SESSION['backup_imports'][$tempId] ?? null;
if ($tracked === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown or expired upload. Please re-select the file.']);
    exit;
}
$path = $tracked['path'];
if (!is_file($path)) {
    unset($_SESSION['backup_imports'][$tempId]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Uploaded file is missing. Please re-select.']);
    exit;
}

if (empty($payload['confirm'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Confirmation required']);
    exit;
}

$mode = (string)($payload['mode'] ?? 'granular');
if (!in_array($mode, ['wipe_all', 'granular'], true)) $mode = 'granular';

$opts = [
    'mode' => $mode,
    'restore_users' => (bool)($payload['restore_users'] ?? true),
    'restore_media' => (bool)($payload['restore_media'] ?? true),
    'users_replace_existing' => (bool)($payload['users_replace_existing'] ?? false),
    'users_replace_password' => (bool)($payload['users_replace_password'] ?? false),
    'rename_suffix_default' => (string)($payload['rename_suffix_default'] ?? ' (restored)'),
    'galaxies' => is_array($payload['galaxies'] ?? null) ? $payload['galaxies'] : [],
];

try {
    $report = backup_restore_from_file($path, $opts);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Clean up the temp file after a successful commit
@unlink($path);
unset($_SESSION['backup_imports'][$tempId]);

echo json_encode(['ok' => true, 'report' => $report]);
