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
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/api-error.php';
require_once __DIR__ . '/../../inc/backup.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('405.001', 'Method not allowed.');
}

$phase = $_GET['phase'] ?? '';
if ($phase !== 'inspect' && $phase !== 'commit') {
    api_error('400.037', 'Missing or invalid phase parameter.');
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
        api_error('403.003', 'Invalid security token. Reload the page and try again.');
    }

    if (!isset($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        api_error('400.036', 'File upload failed (code %d).', [(int)$err]);
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $tempId = bin2hex(random_bytes(16));
    $dest = backup_import_temp_path($tempId);

    if (!@move_uploaded_file($tmp, $dest)) {
        api_error('500.015', 'Could not save the uploaded backup file.');
    }
    @chmod($dest, 0600);

    try {
        $summary = backup_inspect_file($dest);
    } catch (Throwable $e) {
        @unlink($dest);
        error_log('backup/import.php inspect: ' . $e->getMessage());
        api_error('500.001', 'Internal server error.');
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
    api_error('403.003', 'Invalid security token. Reload the page and try again.');
}

$tempId = (string)($payload['temp_id'] ?? '');
$tracked = $_SESSION['backup_imports'][$tempId] ?? null;
if ($tracked === null) {
    api_error('404.012', 'Unknown or expired upload. Please re-select the file.');
}
$path = $tracked['path'];
if (!is_file($path)) {
    unset($_SESSION['backup_imports'][$tempId]);
    api_error('404.013', 'Uploaded file is missing. Please re-select it.');
}

if (empty($payload['confirm'])) {
    api_error('400.038', 'Confirmation required.');
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
    db_audit_log(
        action: 'backup.import.failed',
        actorUserId: $_SESSION['admin_user_id'] ?? null,
        targetType: 'backup',
        targetId: $tempId,
        details: ['mode' => $mode, 'error' => $e->getMessage()],
        ip: auth_client_ip(),
        actorEmail: $_SESSION['admin_user_email'] ?? null,
    );
    error_log('backup/import.php commit: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}

db_audit_log(
    action: 'backup.import',
    actorUserId: $_SESSION['admin_user_id'] ?? null,
    targetType: 'backup',
    targetId: $tempId,
    details: [
        'mode' => $mode,
        'restore_users' => $opts['restore_users'],
        'restore_media' => $opts['restore_media'],
        'galaxies_count' => count($opts['galaxies']),
        'report_summary' => is_array($report) ? array_intersect_key($report, array_flip(['galaxies_imported', 'users_imported', 'media_imported', 'errors_count'])) : null,
    ],
    ip: auth_client_ip(),
    actorEmail: $_SESSION['admin_user_email'] ?? null,
);

// Clean up the temp file after a successful commit
@unlink($path);
unset($_SESSION['backup_imports'][$tempId]);

echo json_encode(['ok' => true, 'report' => $report]);
