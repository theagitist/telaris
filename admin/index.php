<?php
declare(strict_types=1);

/**
 * Admin Console
 * Main administration interface combining API key management and PHP information
 */

// Check if config.php exists, if not redirect to setup
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit();
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

// Per-request nonce for the (currently report-only) strict CSP. The enforced
// CSP keeps 'unsafe-inline' on script-src because admin/index.php carries
// ~114 inline event handlers (onclick=, onsubmit=, etc.) accumulated over
// years. Migrating them to addEventListener is queued separately as a UI
// refactor; the Report-Only header below collects what would break before
// the flip, written to api/csp-report.php (which logs to error_log).
$cspAdminNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob:; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' 'nonce-{$cspAdminNonce}' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob:; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; report-uri /api/csp-report.php");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/bridges/_lib.php';

// Load each active bridge's admin partial (inc/bridges/{name}-admin.php) so
// the render hooks below can call into them.
bridges_admin_load_all();

$appVersion = trim(@file_get_contents(__DIR__ . '/../VERSION') ?: '0.0.0');

$message = null;
$error = null;
$settingsError = null;
$activeTab = $_GET['tab'] ?? 'constellations';
// If editing a user, ensure we're on the users tab
if (isset($_GET['edit_user'])) {
    $activeTab = 'users';
}
if (isset($_GET['edit_constellation'])) {
    $activeTab = 'constellations';
}

// Project info: only the default constellation id is read in this file
// (the surviving Global Settings form references $projectAll['default_constellation_id']).
// Per-locale strings live in project_info rows and are read by the visitor side, not edited here.
$projectAll = db_get_project_info_all_locales() ?: [];

$systemVersion = 'Unknown';
if (file_exists(__DIR__ . '/../VERSION')) {
    $systemVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
}

// CSRF token management
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';

// Handle API key actions and user management actions
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF token on all POST actions
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Invalid or expired security token. Please try again.';
    } else {
    try {
        match ($_POST['action']) {
            'generate' => (function(): void {
                global $message, $activeTab;
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                if (empty($name)) {
                    throw new Exception('API key name is required');
                }
                $apiKey = bin2hex(random_bytes(32));
                db_insert_api_key($apiKey, $name, $description ?: null);
                $_SESSION['new_api_key'] = $apiKey;
                $_SESSION['new_api_key_name'] = $name;
                header('Location: index.php?tab=api-keys&generated=1');
                exit();
            })(),
            
            'toggle' => (function(): void {
                global $message, $activeTab;
                $id = (int)($_POST['id'] ?? 0);
                $isActive = (bool)($_POST['is_active'] ?? false);
                db_toggle_api_key($id, $isActive);
                $message = 'API key ' . ($isActive ? 'activated' : 'deactivated') . ' successfully.';
                $activeTab = 'api-keys';
            })(),
            
            'delete' => (function(): void {
                global $message, $activeTab;
                $id = (int)($_POST['id'] ?? 0);
                db_delete_api_key($id);
                $message = 'API key deleted successfully.';
                $activeTab = 'api-keys';
            })(),
            
            'create_user' => (function(): void {
                global $message, $error, $activeTab;
                $email = trim($_POST['email'] ?? '');
                $firstname = trim($_POST['firstname'] ?? '');
                $lastname = trim($_POST['lastname'] ?? '');
                $password = $_POST['password'] ?? '';
                $type = (int)($_POST['type'] ?? 0);
                if (empty($email) || empty($firstname) || empty($lastname) || empty($password)) {
                    throw new Exception('All fields are required');
                }
                if (strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format');
                }
                if ($type !== 1 && $type !== 2) {
                    throw new Exception('User type must be Editor (1) or Admin (2)');
                }
                if (db_user_email_exists($email)) {
                    throw new Exception('Email already exists');
                }
                $createConstellation = !empty($_POST['create_constellation']);
                $newConstellationName = trim((string)($_POST['new_constellation_name'] ?? ''));
                if ($createConstellation && $newConstellationName === '') {
                    throw new Exception('Galaxy name is required when "Create new galaxy" is checked.');
                }
                $hashedPassword = hashPassword($password);
                $err = createUser(getDB(), $email, $hashedPassword, $firstname, $lastname, $type);
                if ($err !== null) {
                    throw new Exception($err);
                }
                $newUser = db_get_user_by_email($email);
                if ($newUser && isset($newUser['id'])) {
                    $constellationIds = array_map('intval', array_filter((array)($_POST['constellation_ids'] ?? [])));
                    if ($createConstellation && $newConstellationName !== '') {
                        $adminId = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
                        $newConstellationId = db_create_constellation($newConstellationName, '', null, 'cosmic', $adminId);
                        $constellationIds[] = $newConstellationId;
                    }
                    if ($type === USER_TYPE_EDITOR) {
                        db_set_user_constellations($newUser['id'], $constellationIds);
                    }
                    db_audit_log(
                        action: 'user.create',
                        actorUserId: $_SESSION['admin_user_id'] ?? null,
                        targetType: 'user',
                        targetId: (string)$newUser['id'],
                        details: ['type' => $type, 'galaxies' => count($constellationIds)],
                        ip: auth_client_ip(),
                        actorEmail: $_SESSION['admin_user_email'] ?? null,
                    );
                }
                $message = 'User created successfully.';
                $activeTab = 'users';
            })(),
            
            'update_user' => (function(): void {
                global $message, $error, $activeTab;
                $id = trim($_POST['id'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $firstname = trim($_POST['firstname'] ?? '');
                $lastname = trim($_POST['lastname'] ?? '');
                $password = $_POST['password'] ?? '';
                $type = (int)($_POST['type'] ?? 0);
                if (empty($id) || empty($email) || empty($firstname) || empty($lastname)) {
                    throw new Exception('All required fields must be filled');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format');
                }
                if ($type !== 1 && $type !== 2) {
                    throw new Exception('User type must be Editor (1) or Admin (2)');
                }
                if (db_user_email_exists($email, $id)) {
                    throw new Exception('Email already exists for another user');
                }
                if ($id === ($_SESSION['admin_user_id'] ?? '') && $type !== USER_TYPE_ADMIN) {
                    throw new Exception('You cannot change your own user type');
                }
                if (!empty($password) && strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                $hashedPassword = !empty($password) ? hashPassword($password) : null;
                db_update_user($id, $email, $firstname, $lastname, $type, $hashedPassword);
                $constellationIds = array_map('intval', array_filter((array)($_POST['constellation_ids'] ?? [])));
                db_set_user_constellations($id, $type === USER_TYPE_EDITOR ? $constellationIds : []);
                db_audit_log(
                    action: 'user.update',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'user',
                    targetId: $id,
                    details: [
                        'type' => $type,
                        'password_changed' => $hashedPassword !== null,
                        'galaxies' => count($constellationIds),
                    ],
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'User updated successfully.';
                $activeTab = 'users';
            })(),
            
            'delete_user' => (function(): void {
                global $message, $error, $activeTab;
                $id = trim($_POST['id'] ?? '');
                if (empty($id)) {
                    throw new Exception('User ID is required');
                }
                if ($id === ($_SESSION['admin_user_id'] ?? '')) {
                    throw new Exception('You cannot delete your own account');
                }
                // Server-side confirmation phrase. Mirrors snapshots/restore.php.
                // The client modal collects the operator-typed email and POSTs
                // it as confirm_name; we recompute the expected value from the
                // user's row (not the request body) so a tampered client can't
                // bypass the gate.
                $row = db_get_user_by_id($id);
                if ($row === null) {
                    throw new Exception(t('admin_error_user_not_found', 'User not found.'));
                }
                $expected = (string)($row['email'] ?? '');
                $provided = trim((string)($_POST['confirm_name'] ?? ''));
                if ($expected === '' || strcasecmp($provided, $expected) !== 0) {
                    throw new Exception(t('admin_error_delete_confirm_mismatch', 'Confirmation does not match. Type the exact name to confirm deletion.'));
                }
                db_delete_user($id);
                db_audit_log(
                    action: 'user.delete',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'user',
                    targetId: $id,
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'User deleted successfully.';
                $activeTab = 'users';
            })(),
            
            'create_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                if (empty($name)) {
                    throw new Exception('Galaxy name is required');
                }

                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A galaxy with this ' . implode(' and ', $errs) . ' already exists.');
                }

                $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech'];
                $theme = trim($_POST['theme'] ?? 'cosmic');
                if (!in_array($theme, $allowedThemes, true)) { $theme = 'cosmic'; }
                $createdBy = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
                $newGalaxyId = db_create_constellation($name, $tagline, $slug !== '' ? $slug : null, $theme, $createdBy);
                db_audit_log(
                    action: 'galaxy.create',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'galaxy',
                    targetId: (string)$newGalaxyId,
                    details: ['name' => $name, 'slug' => $finalSlug, 'theme' => $theme],
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'Galaxy created successfully.';
                $activeTab = 'constellations';
            })(),
            
            'update_constellation' => (function(): void {
                global $message, $error, $activeTab;
                require_once __DIR__ . '/../inc/galaxy-update.php';
                $result = handle_galaxy_update_post(
                    $_POST,
                    $_SESSION['admin_user_id'] ?? null,
                    isAdminLoggedIn()
                );
                if (!$result['ok']) {
                    throw new Exception($result['message']);
                }
                $message = $result['message'];
                $activeTab = 'constellations';
            })(),
            
            'duplicate_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $sourceId = (int)($_POST['source_id'] ?? -1);
                $name = trim($_POST['name'] ?? '');
                $tagline = trim($_POST['tagline'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                
                if (empty($name)) {
                    throw new Exception('New galaxy name is required');
                }

                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A galaxy with this ' . implode(' and ', $errs) . ' already exists.');
                }

                $newDupeId = db_duplicate_constellation($sourceId, $name, $tagline, $slug !== '' ? $slug : null);
                db_audit_log(
                    action: 'galaxy.duplicate',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'galaxy',
                    targetId: (string)($newDupeId ?: '?'),
                    details: ['source_id' => $sourceId, 'name' => $name, 'slug' => $finalSlug],
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'Galaxy duplicated successfully.';
                $activeTab = 'constellations';
            })(),
            
            'delete_constellation' => (function(): void {
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                // Server-side confirmation phrase. The client modal collects
                // the operator-typed galaxy name and POSTs it as confirm_name;
                // we recompute the expected value from the constellation row.
                $row = db_get_constellation_by_id($id);
                if ($row === null) {
                    throw new Exception(t('admin_error_galaxy_not_found', 'Galaxy not found.'));
                }
                $expected = (string)($row['name'] ?? '');
                $provided = trim((string)($_POST['confirm_name'] ?? ''));
                if ($expected === '' || strcasecmp($provided, $expected) !== 0) {
                    throw new Exception(t('admin_error_delete_confirm_mismatch', 'Confirmation does not match. Type the exact name to confirm deletion.'));
                }
                db_delete_constellation($id);
                db_audit_log(
                    action: 'galaxy.delete',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'galaxy',
                    targetId: (string)$id,
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'Galaxy deleted successfully.';
                $activeTab = 'constellations';
            })(),

            // ---------- Galaxy clusters (Idea 2) ----------
            // Admin-only — auth gate is the page-level requireAdmin() above. Clusters share the
            // constellations table (type='cluster') so slug uniqueness is enforced by the same
            // db_constellation_exists() helper used for galaxies.
            'create_cluster' => (function(): void {
                require_once __DIR__ . '/../inc/cluster-update.php';
                global $message, $error, $activeTab;
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') throw new Exception('Cluster name is required');
                $tagline = trim((string)($_POST['tagline'] ?? ''));
                $slug = trim((string)($_POST['slug'] ?? ''));
                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A galaxy or cluster with this ' . implode(' and ', $errs) . ' already exists.');
                }
                $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech'];
                $theme = trim((string)($_POST['theme'] ?? 'cosmic'));
                if (!in_array($theme, $allowedThemes, true)) $theme = 'cosmic';
                $members = array_values(array_filter(array_map('intval', (array)($_POST['members'] ?? [])), fn($i) => $i > 0));
                $showGalaxyList = !empty($_POST['show_galaxy_list']);
                db_create_cluster($name, $tagline, $finalSlug, $theme, $members, $showGalaxyList);
                $newClusterId = (int) db_get_constellation_id_by_slug($finalSlug);
                if ($newClusterId > 0) {
                    save_cluster_discovery_config_from_post($newClusterId, $_POST);
                }
                db_audit_log(
                    action: 'cluster.create',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'cluster',
                    targetId: (string)$newClusterId,
                    details: ['name' => $name, 'slug' => $finalSlug, 'theme' => $theme, 'members' => count($members)],
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'Cluster created successfully.';
                $activeTab = 'clusters';
            })(),

            'update_cluster' => (function(): void {
                require_once __DIR__ . '/../inc/cluster-update.php';
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                if ($id <= 0) throw new Exception('Missing cluster id.');
                $existing = db_get_constellation_by_id($id);
                if (!$existing || ($existing['type'] ?? 'galaxy') !== 'cluster') {
                    throw new Exception('Not a cluster.');
                }
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') throw new Exception('Cluster name is required');
                $tagline = trim((string)($_POST['tagline'] ?? ''));
                $slug = trim((string)($_POST['slug'] ?? ''));
                $finalSlug = ($slug !== '') ? $slug : db_slugify($name);
                $exists = db_constellation_exists($name, $finalSlug, $id);
                if ($exists['name'] || $exists['slug']) {
                    $errs = [];
                    if ($exists['name']) $errs[] = 'name "' . htmlspecialchars($name) . '"';
                    if ($exists['slug']) $errs[] = 'slug "' . htmlspecialchars($finalSlug) . '"';
                    throw new Exception('A galaxy or cluster with this ' . implode(' and ', $errs) . ' already exists.');
                }
                $allowedThemes = ['cosmic', 'simple', 'abstract', 'rectangles', 'stripes', 'tech'];
                $theme = trim((string)($_POST['theme'] ?? 'cosmic'));
                if (!in_array($theme, $allowedThemes, true)) $theme = 'cosmic';
                $showGalaxyList = !empty($_POST['show_galaxy_list']);
                db_update_cluster($id, $name, $tagline, $finalSlug, $theme, $showGalaxyList);
                $members = array_values(array_filter(array_map('intval', (array)($_POST['members'] ?? [])), fn($i) => $i > 0));
                db_set_cluster_members($id, $members);
                save_cluster_discovery_config_from_post($id, $_POST);
                db_audit_log(
                    action: 'cluster.update',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'cluster',
                    targetId: (string)$id,
                    details: ['name' => $name, 'slug' => $finalSlug, 'theme' => $theme, 'members' => count($members)],
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'Cluster updated successfully.';
                $activeTab = 'clusters';
            })(),

            'delete_cluster' => (function(): void {
                global $message, $error, $activeTab;
                $id = (int)($_POST['id'] ?? -1);
                if ($id <= 0) throw new Exception('Missing cluster id.');
                $existing = db_get_constellation_by_id($id);
                if (!$existing || ($existing['type'] ?? 'galaxy') !== 'cluster') {
                    throw new Exception('Not a cluster.');
                }
                // Server-side confirmation phrase. See delete_constellation note.
                $expected = (string)($existing['name'] ?? '');
                $provided = trim((string)($_POST['confirm_name'] ?? ''));
                if ($expected === '' || strcasecmp($provided, $expected) !== 0) {
                    throw new Exception(t('admin_error_delete_confirm_mismatch', 'Confirmation does not match. Type the exact name to confirm deletion.'));
                }
                db_delete_cluster($id);
                db_audit_log(
                    action: 'cluster.delete',
                    actorUserId: $_SESSION['admin_user_id'] ?? null,
                    targetType: 'cluster',
                    targetId: (string)$id,
                    ip: auth_client_ip(),
                    actorEmail: $_SESSION['admin_user_email'] ?? null,
                );
                $message = 'Cluster deleted successfully.';
                $activeTab = 'clusters';
            })(),

            // ---------- Bulk user creation ----------
            // Two-step flow: 'preview' parses the textarea and renders a confirmation panel;
            // 'commit' re-parses and creates users + sends setup emails.
            'bulk_users_preview' => (function(): void {
                global $message, $error, $activeTab, $bulkUsersPreview, $bulkUsersInput, $bulkUsersDefaultCreateGalaxy;
                require_once __DIR__ . '/../inc/bulk-users.php';
                $bulkUsersInput = (string)($_POST['bulk_users_input'] ?? '');
                $bulkUsersDefaultCreateGalaxy = !empty($_POST['default_create_galaxy']);
                $bulkUsersPreview = bulk_users_parse($bulkUsersInput, $bulkUsersDefaultCreateGalaxy);
                $activeTab = 'users';
            })(),

            'bulk_users_commit' => (function(): void {
                global $message, $error, $activeTab, $bulkUsersResult;
                require_once __DIR__ . '/../inc/bulk-users.php';
                $input = (string)($_POST['bulk_users_input'] ?? '');
                $defaultCreateGalaxy = !empty($_POST['default_create_galaxy']);
                $rows = bulk_users_parse($input, $defaultCreateGalaxy);
                // SITE_BASE_URL pins the host so a poisoned Host header on the
                // request cannot embed an attacker domain into bulk-welcome emails.
                // Same shape as utils/forgot.php; mirror them together.
                if (defined('SITE_BASE_URL') && is_string(SITE_BASE_URL) && preg_match('#^https?://#', SITE_BASE_URL)) {
                    $baseUrl = rtrim(SITE_BASE_URL, '/');
                } else {
                    error_log('admin/index.php bulk_users_commit: SITE_BASE_URL is not defined in config.php; falling back to Host header (Host-injection risk).');
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $scheme . '://' . $host;
                }
                $bulkUsersResult = bulk_users_apply($rows, $baseUrl);
                $created = $bulkUsersResult['created'];
                $galaxies = $bulkUsersResult['galaxies_created'];
                $message = "Bulk import: {$created} user" . ($created === 1 ? '' : 's') . ' created'
                         . ($galaxies > 0 ? ", {$galaxies} galax" . ($galaxies === 1 ? 'y' : 'ies') . ' created' : '')
                         . ($bulkUsersResult['mail_failed'] > 0 ? " ({$bulkUsersResult['mail_failed']} mail send(s) failed — check error_log)" : '')
                         . ($bulkUsersResult['skipped_exists'] > 0 ? ", {$bulkUsersResult['skipped_exists']} skipped (already exists)" : '')
                         . ($bulkUsersResult['skipped_invalid'] > 0 ? ", {$bulkUsersResult['skipped_invalid']} skipped (invalid)" : '')
                         . '.';
                $activeTab = 'users';
            })(),
            
            'logout' => (function(): void {
                logoutAdmin();
                header('Location: ../utils/login.php');
                exit();
            })(),
            
            'save_settings' => (function(): void {
                global $settingsError, $activeTab;
                try {
                    if (isset($_POST['instance_name'])) {
                        $newName = trim((string)$_POST['instance_name']);
                        if ($newName === '') {
                            throw new InvalidArgumentException('Name is required.');
                        }
                        db_set_instance_name($newName);
                    }
                    if (isset($_POST['default_constellation_id']) && ctype_digit((string)$_POST['default_constellation_id'])) {
                        db_set_default_constellation_id((int)$_POST['default_constellation_id']);
                    }
                    // PDF max size: stored in MB on the form, persisted in bytes. Empty = revert to default.
                    if (isset($_POST['pdf_max_mb'])) {
                        $mb = trim((string)$_POST['pdf_max_mb']);
                        if ($mb === '') {
                            db_set_pdf_max_bytes(null);
                        } elseif (is_numeric($mb)) {
                            $mbNum = max(1, min(2048, (int)$mb)); // clamp to 1MB..2GB
                            db_set_pdf_max_bytes($mbNum * 1024 * 1024);
                        }
                    }
                    header('Location: index.php?tab=settings&saved=1');
                    exit;
                } catch (Throwable $e) {
                    $settingsError = 'Failed to save settings. ' . htmlspecialchars($e->getMessage());
                    $activeTab = 'settings';
                }
            })(),
            
            default => throw new RuntimeException('Invalid action')
        };
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    } // end CSRF-valid block
}

// Get all API keys, users, and constellations
$apiKeys = db_get_api_keys();
$users = db_get_users();
$constellations = db_get_constellations();

// Pluriverse tab: prior application (if any) + the galaxy candidate list.
// URL, Name and operator email are read-only displays sourced server-side
// (current host, db_get_instance_name(), session admin email). The form only
// collects framing + galaxy picks + optional secondary contacts.
$pluriverseApplication = db_get_latest_pluriverse_application();
$pluriverseAdminEmail = $_SESSION['admin_user_email'] ?? '';
$pluriverseDefaultUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$pluriverseInstanceName = db_get_instance_name();

// Group constellations by [Tag] prefix for visual grouping
function extractConstellationGroup(string $name): ?string {
    if (preg_match('/^\[([^\]]+)\]/', $name, $m)) {
        return $m[1];
    }
    return null;
}

$constellationGroupColors = [];
$pastelPalette = [
    '#FEF2F2', '#F0FAF0', '#EFF6FF', '#FFF8F0', '#F8F5FF',
    '#F0FDFA', '#FEFEF0', '#FFF5F5', '#F5F5F7', '#F5FAE8',
];
$groupColorIndex = 0;

// Sort: grouped constellations first (by group name), ungrouped after
usort($constellations, function ($a, $b) {
    $ga = extractConstellationGroup($a['name']);
    $gb = extractConstellationGroup($b['name']);
    if ($ga !== null && $gb === null) return -1;
    if ($ga === null && $gb !== null) return 1;
    if ($ga !== null && $gb !== null && $ga !== $gb) return strcasecmp($ga, $gb);
    return strcasecmp($a['name'], $b['name']);
});

// Assign a pastel color per unique group
foreach ($constellations as $c) {
    $group = extractConstellationGroup($c['name']);
    if ($group !== null && !isset($constellationGroupColors[$group])) {
        $constellationGroupColors[$group] = $pastelPalette[$groupColorIndex % count($pastelPalette)];
        $groupColorIndex++;
    }
}

// Get constellation access mapping for JavaScript
$userConstellationsMap = [];
foreach ($users as $u) {
    if ((int)$u['type'] === USER_TYPE_EDITOR) {
        $userConstellationsMap[$u['id']] = db_get_user_constellation_ids($u['id']);
    }
}

// Check for newly generated key
$newApiKey = $_SESSION['new_api_key'] ?? null;
$newApiKeyName = $_SESSION['new_api_key_name'] ?? null;
if ($newApiKey && isset($_GET['generated'])) {
    unset($_SESSION['new_api_key']);
    unset($_SESSION['new_api_key_name']);
}

// Get PHP information
$phpVersion = PHP_VERSION;
$phpSapi = php_sapi_name();
$loadedExtensions = @get_loaded_extensions();
if (!is_array($loadedExtensions)) {
    $loadedExtensions = [];
}
sort($loadedExtensions);

$phpConfig = [
    'Version' => PHP_VERSION,
    'SAPI' => php_sapi_name(),
    'Server API' => php_sapi_name(),
    'Zend Engine' => @zend_version() ?: 'Unknown',
    'Memory Limit' => @ini_get('memory_limit') ?: 'Not set',
    'Max Execution Time' => (@ini_get('max_execution_time') ?: '0') . ' seconds',
    'Upload Max Filesize' => @ini_get('upload_max_filesize') ?: 'Not set',
    'Post Max Size' => @ini_get('post_max_size') ?: 'Not set',
    'Default Timezone' => @date_default_timezone_get() ?: 'Not set',
    'Error Reporting' => (string)(@ini_get('error_reporting') ?: '0'),
    'Display Errors' => @ini_get('display_errors') ? 'On' : 'Off',
];

$importantExtensions = [
    'pdo' => 'PDO',
    'pdo_mysql' => 'PDO MySQL',
    'json' => 'JSON',
    'mbstring' => 'Multibyte String',
    'curl' => 'cURL',
    'openssl' => 'OpenSSL',
    'zip' => 'ZIP',
    'gd' => 'GD',
    'xml' => 'XML',
    'dom' => 'DOM',
];

$extensionStatus = [];
foreach ($importantExtensions as $ext => $name) {
    $extensionStatus[$name] = @extension_loaded($ext);
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title>Admin Console - Telaris</title>
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" integrity="sha384-yxrQVVFFRZdq4Z/YbeTDzSYbn1W6VnVonm2vAgnxtxUMehcccE4k2NufOz2tJnOe" crossorigin="anonymous" />
</head>
<body class="font-sans bg-gray-100 min-h-screen">
    <!-- Notification Container -->
    <div id="notification-container" class="fixed top-4 left-1/2 -translate-x-1/2 z-[2000] flex flex-col gap-2 w-full max-w-md pointer-events-none"></div>

    <!-- Initial Loading Overlay -->
    <div id="admin-loading-overlay" class="fixed inset-0 z-[1000] bg-gray-100 flex flex-col items-center justify-center transition-opacity duration-300">
        <span class="loading loading-spinner loading-lg text-neutral mb-4"></span>
        <p class="text-gray-600 font-medium"><?= t_attr('admin_loading_console', 'Loading Admin Console...') ?></p>
    </div>

    <div class="max-w-6xl mx-auto py-8 px-5">
        <!-- Header -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-gray-800 text-3xl font-semibold"><?= t_attr('admin_heading_console', 'Admin Console') ?></h1>
                    <p class="text-gray-600 mt-1"><?= sprintf(t('admin_label_welcome', 'Welcome, %s'), htmlspecialchars($_SESSION['admin_user_name'] ?? 'Admin')) ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="../edit/index.php" class="btn btn-neutral">
                        <?= t_attr('admin_btn_edit_content', 'Edit Content') ?>
                    </a>
                    <form method="POST" action="" class="inline">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded">
                            <?= t_attr('admin_btn_logout', 'Logout') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Messages Data (Hidden) -->
        <div id="php-messages" class="hidden">
            <?php if ($newApiKey): ?>
                <div data-type="success" data-title="<?= t_attr('admin_msg_api_key_generated_title', '✓ API Key Generated') ?>">
                    <?= sprintf(t('admin_msg_api_key_generated_body', 'Your API Key: %s (Name: %s). PLEASE COPY IT NOW.'), htmlspecialchars($newApiKey), htmlspecialchars($newApiKeyName)) ?>
                </div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div data-type="success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div data-type="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
                <div data-type="success"><?= t_attr('admin_msg_settings_saved', 'Global settings saved.') ?></div>
            <?php endif; ?>
            <?php if ($settingsError): ?>
                <div data-type="error"><?php echo htmlspecialchars($settingsError); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['pluriverse_apply_message'])): ?>
                <div data-type="success"><?= htmlspecialchars((string)$_SESSION['pluriverse_apply_message']) ?></div>
                <?php unset($_SESSION['pluriverse_apply_message']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['pluriverse_apply_error'])): ?>
                <div data-type="error"><?= htmlspecialchars((string)$_SESSION['pluriverse_apply_error']) ?></div>
                <?php unset($_SESSION['pluriverse_apply_error']); ?>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="mb-6">
            <div class="tabs tabs-lifted">
                <button onclick="showTab('constellations')"
                        id="tab-constellations"
                        class="tab tab-lg <?php echo $activeTab === 'constellations' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_galaxies', 'Galaxies') ?>
                </button>
                <button onclick="showTab('clusters')"
                        id="tab-clusters"
                        class="tab tab-lg <?php echo $activeTab === 'clusters' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_clusters', 'Clusters') ?>
                </button>
                <button onclick="showTab('users')"
                        id="tab-users"
                        class="tab tab-lg <?php echo $activeTab === 'users' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_users', 'Users') ?>
                </button>
                <button onclick="showTab('backup')"
                        id="tab-backup"
                        class="tab tab-lg <?php echo $activeTab === 'backup' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_backup', 'Backup') ?>
                </button>
                <button onclick="showTab('snapshots')"
                        id="tab-snapshots"
                        class="tab tab-lg <?php echo $activeTab === 'snapshots' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_snapshots', 'Snapshots') ?>
                </button>
                <button onclick="showTab('settings')"
                        id="tab-settings"
                        class="tab tab-lg <?php echo $activeTab === 'settings' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_settings', 'Global Settings') ?>
                </button>
                <button onclick="showTab('pluriverse')"
                        id="tab-pluriverse"
                        class="tab tab-lg <?php echo $activeTab === 'pluriverse' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_pluriverse', 'Pluriverse') ?>
                </button>
                <button onclick="showTab('api-keys')"
                        id="tab-api-keys"
                        class="tab tab-lg <?php echo $activeTab === 'api-keys' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_api_keys', 'API Keys') ?>
                </button>
                <button onclick="showTab('php-info')"
                        id="tab-php-info"
                        class="tab tab-lg <?php echo $activeTab === 'php-info' ? 'tab-active' : ''; ?>">
                    <?= t_attr('admin_tab_php_info', 'PHP Information') ?>
                </button>
            </div>
        </div>

        <div class="bg-white rounded-b-lg shadow-md mb-6 -mt-6 pt-6">
            <!-- Users Tab -->
            <div id="content-users" class="p-6 <?php echo $activeTab !== 'users' ? 'hidden' : ''; ?>">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-gray-800 text-base font-semibold"><?= t_attr('admin_heading_users', 'Users') ?> (<?php echo count($users); ?>)</h2>
                            <button type="button" onclick="document.getElementById('create_user_modal').showModal()" class="text-blue-600 hover:text-blue-800 font-medium text-base"><?= t_attr('admin_btn_new_user', 'New User') ?></button>
                            <button type="button" onclick="openBulkUsersModal()" class="text-blue-600 hover:text-blue-800 font-medium text-base"><?= t_attr('admin_btn_bulk_import', 'Bulk import') ?></button>
                        </div>

                        <!-- Top Pagination -->
                        <div id="users-pagination-header" class="flex-1 flex justify-center"></div>

                        <div class="flex items-center gap-2 min-w-[250px]">
                            <label for="search-users" class="text-sm font-medium text-gray-700"><?= t_attr('admin_label_search', 'Search:') ?></label>
                            <input type="text"
                                   id="search-users"
                                   placeholder="<?= t_attr('admin_placeholder_search_users', 'Search users...') ?>"
                                   oninput="applyUserSearch()"
                                   class="flex-1 p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>

                    <?php if (empty($users)): ?>
                        <p class="text-gray-600"><?= t_attr('admin_msg_no_users', 'No users found.') ?></p>
                    <?php else: ?>
                        <div class="border border-gray-300 rounded">
                            <table id="users-list" class="w-full border-collapse">
                                <thead>
                                    <tr class="border-b-2 border-gray-400 bg-gray-100">
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('name')"><?= t_attr('admin_col_user_name', 'Name') ?><span id="sort-indicator-name"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('email')"><?= t_attr('admin_col_user_email', 'Email') ?><span id="sort-indicator-email"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('type')"><?= t_attr('admin_col_user_type', 'Type') ?><span id="sort-indicator-type"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('date_created')"><?= t_attr('admin_col_user_created', 'Created') ?><span id="sort-indicator-date_created"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('date_last_login')"><?= t_attr('admin_col_user_last_login', 'Last Login') ?><span id="sort-indicator-date_last_login"></span></span>
                                        </th>
                                        <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                            <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortUsersByColumn('updated_at')"><?= t_attr('admin_col_user_last_updated', 'Last Updated') ?><span id="sort-indicator-updated_at"></span></span>
                                        </th>
                                        <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2"><?= t_attr('admin_col_actions', 'Actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $typeLabels = [
                                            0 => t('admin_user_type_regular', 'Regular'),
                                            1 => t('admin_user_type_editor', 'Editor'),
                                            2 => t('admin_user_type_admin', 'Admin'),
                                        ];
                                        $typeColors = [0 => 'bg-gray-400', 1 => 'bg-blue-400', 2 => 'bg-purple-400'];
                                        $userType = (int)$user['type'];
                                        $fullName = htmlspecialchars($user['firstname'] . ' ' . $user['lastname']);
                                        $email = htmlspecialchars($user['email']);
                                        $createdTs = strtotime($user['date_created'] ?? '');
                                        $lastLoginTs = !empty($user['date_last_login']) ? strtotime($user['date_last_login']) : false;
                                        $updatedTs = !empty($user['updated_at']) ? strtotime($user['updated_at']) : false;
                                        $createdIso = $createdTs !== false ? gmdate('c', $createdTs) : '';
                                        $lastLoginIso = $lastLoginTs !== false ? gmdate('c', $lastLoginTs) : null;
                                        $updatedIso = $updatedTs !== false ? gmdate('c', $updatedTs) : null;
                                        $isCurrentUser = $user['id'] === ($_SESSION['admin_user_id'] ?? '');
                                        ?>
                                        <tr class="user-row border-b border-gray-300 hover:bg-gray-50" 
                                            data-user-id="<?php echo htmlspecialchars($user['id']); ?>" 
                                            data-name="<?php echo htmlspecialchars(strtolower($user['firstname'] . ' ' . $user['lastname'])); ?>" 
                                            data-email="<?php echo htmlspecialchars(strtolower($user['email'])); ?>" 
                                            data-type="<?php echo $userType; ?>" 
                                            data-date-created="<?php echo $createdTs !== false ? $createdTs : '0'; ?>" 
                                            data-date-last-login="<?php echo $lastLoginTs !== false ? $lastLoginTs : '0'; ?>"
                                            data-updated-at="<?php echo $updatedTs !== false ? $updatedTs : '0'; ?>">
                                            <?php 
                                            $userData = [
                                                'id' => $user['id'],
                                                'firstname' => $user['firstname'],
                                                'lastname' => $user['lastname'],
                                                'email' => $user['email'],
                                                'type' => $user['type']
                                            ];
                                            $userJson = htmlspecialchars(json_encode($userData), ENT_QUOTES, 'UTF-8');
                                            $clickEdit = "editUser($userJson)";
                                            ?>
                                            <td class="py-2 px-2 font-semibold text-gray-800 max-w-[12rem] cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <span class="block truncate" title="<?php echo $fullName; ?>"><?php echo $fullName; ?><?php if ($isCurrentUser): ?> <span class="ml-1 text-xs bg-green-400 text-white px-1.5 py-0.5 rounded"><?= t_attr('admin_badge_you', 'You') ?></span><?php endif; ?></span>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-600 max-w-[14rem] cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <span class="block truncate" title="<?php echo $email; ?>"><?php echo $email; ?></span>
                                            </td>
                                            <td class="py-2 px-2 cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <span class="text-xs <?php echo $typeColors[$userType]; ?> text-white px-2 py-1 rounded"><?php echo $typeLabels[$userType]; ?></span>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <?php if ($createdIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($createdIso); ?>"><?php echo date('y-m-d H:i', $createdTs); ?></span><?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <?php if ($lastLoginIso !== null): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($lastLoginIso); ?>"><?php echo date('y-m-d H:i', $lastLoginTs); ?></span><?php else: ?><?= t_attr('admin_label_never', 'Never') ?><?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="<?php echo $clickEdit; ?>">
                                                <?php if ($updatedIso !== null): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($updatedIso); ?>"><?php echo date('y-m-d H:i', $updatedTs); ?></span><?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td class="py-2 px-2 text-right">
                                                <div class="flex justify-end">
                                                    <div class="dropdown dropdown-end">
                                                        <label tabindex="0" onclick="event.stopPropagation(); closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                                        </label>
                                                        <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-36">
                                                            <li><a onclick="event.stopPropagation(); <?php echo $clickEdit; ?>" class="text-gray-700 text-xs"><?= t_attr('admin_action_edit', 'Edit') ?></a></li>
                                                            <?php if (!$isCurrentUser): ?>
                                                                <?php
                                                                $delMsg = sprintf(t('admin_confirm_delete_user', 'Are you sure you want to delete the user "%s"? This action cannot be undone.'), $fullName);
                                                                $delMsgJs = htmlspecialchars(json_encode($delMsg), ENT_QUOTES, 'UTF-8');
                                                                $delConfirmJs = htmlspecialchars(json_encode((string)($user['email'] ?? '')), ENT_QUOTES, 'UTF-8');
                                                                ?>
                                                                <li><a onclick="event.stopPropagation(); triggerDelete('delete_user', '<?php echo addslashes($user['id']); ?>', <?php echo $delMsgJs; ?>, <?php echo $delConfirmJs; ?>)" class="text-red-600 text-xs"><?= t_attr('admin_action_delete', 'Delete') ?></a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- API Keys Tab -->
            <div id="content-api-keys" class="p-6 <?php echo $activeTab !== 'api-keys' ? 'hidden' : ''; ?>">
                <!-- Generate New API Key Form (hidden by default; shown when New API Key clicked) -->
                <div id="api-key-form-panel" class="mb-8 p-4 bg-blue-50 border border-blue-200 rounded hidden">
                    <h2 class="text-blue-800 text-xl font-semibold mb-4"><?= t_attr('admin_heading_generate_api_key', 'Generate New API Key') ?></h2>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="generate">
                        <div class="mb-4">
                            <label for="name" class="block mb-1.5 text-gray-800 font-medium"><?= t_attr('admin_label_api_key_name', 'Name *') ?></label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   required
                                   placeholder="<?= t_attr('admin_placeholder_api_key_name', 'e.g., Frontend App, Mobile App, Admin') ?>"
                                   class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('admin_help_api_key_name', 'A descriptive name for this API key') ?></span>
                        </div>
                        <div class="mb-4">
                            <label for="description" class="block mb-1.5 text-gray-800 font-medium"><?= t_attr('admin_label_api_key_description', 'Description') ?></label>
                            <textarea id="description"
                                      name="description"
                                      rows="2"
                                      placeholder="<?= t_attr('admin_placeholder_api_key_description', 'Optional description of what this key is used for') ?>"
                                      class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500"></textarea>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                                <?= t_attr('admin_btn_generate_api_key', 'Generate API Key') ?>
                            </button>
                            <button type="button" onclick="document.getElementById('api-key-form-panel').classList.add('hidden');" class="bg-gray-500 hover:bg-gray-600 text-white py-2.5 px-6 rounded text-base cursor-pointer">
                                <?= t_attr('admin_btn_cancel', 'Cancel') ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- API Keys list -->
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <h2 class="text-gray-800 text-base font-semibold"><?= t_attr('admin_heading_api_keys', 'API Keys') ?> (<?php echo count($apiKeys); ?>)</h2>
                        <a href="#" onclick="document.getElementById('api-key-form-panel').classList.remove('hidden'); return false;" class="text-blue-600 hover:text-blue-800 font-medium text-base"><?= t_attr('admin_btn_new_api_key', 'New API Key') ?></a>
                    </div>

                    <?php if (empty($apiKeys)): ?>
                        <p class="text-gray-600"><?= t_attr('admin_msg_no_api_keys', 'No API keys have been generated yet.') ?></p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($apiKeys as $key): ?>
                                <div class="p-4 border border-gray-300 rounded <?php echo $key['is_active'] ? 'bg-white' : 'bg-gray-100'; ?>">
                                    <div class="flex items-start justify-between mb-2">
                                        <div class="flex-1">
                                            <h3 class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($key['name']); ?>
                                                <?php if (!$key['is_active']): ?>
                                                    <span class="ml-2 text-xs bg-gray-400 text-white px-2 py-1 rounded"><?= t_attr('admin_badge_inactive', 'Inactive') ?></span>
                                                <?php endif; ?>
                                            </h3>
                                            <?php if ($key['description']): ?>
                                                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($key['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dropdown dropdown-end">
                                            <label tabindex="0" onclick="closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                            </label>
                                            <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-40">
                                                <li>
                                                    <form method="POST" action="" class="p-0 m-0">
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                                        <input type="hidden" name="is_active" value="<?php echo $key['is_active'] ? '0' : '1'; ?>">
                                                        <button type="submit" class="w-full text-left text-gray-700 text-xs px-3 py-1.5 hover:bg-gray-100 rounded">
                                                            <?php echo $key['is_active'] ? t('admin_action_deactivate', 'Deactivate') : t('admin_action_activate', 'Activate'); ?>
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" action="" class="p-0 m-0" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('admin_confirm_delete_api_key', 'Are you sure you want to delete this API key? This action cannot be undone.')), ENT_QUOTES, 'UTF-8') ?>);">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                                        <button type="submit" class="w-full text-left text-red-600 text-xs px-3 py-1.5 hover:bg-gray-100 rounded">
                                                            <?= t_attr('admin_action_delete', 'Delete') ?>
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500 space-y-1">
                                        <?php
                                        $keyCreatedTs = isset($key['created_at']) && $key['created_at'] !== '' ? strtotime($key['created_at']) : false;
                                        $keyUpdatedTs = isset($key['updated_at']) && $key['updated_at'] !== '' ? strtotime($key['updated_at']) : false;
                                        $keyCreatedIso = $keyCreatedTs !== false ? gmdate('c', $keyCreatedTs) : '';
                                        $keyUpdatedIso = $keyUpdatedTs !== false ? gmdate('c', $keyUpdatedTs) : '';
                                        ?>
                                        <p><strong><?= t_attr('admin_label_created', 'Created:') ?></strong> <?php if ($keyCreatedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyCreatedIso); ?>"><?php echo date('y-m-d H:i', $keyCreatedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                        <?php if (!empty($key['last_used_at'])): ?>
                                            <?php $keyLastUsedTs = strtotime($key['last_used_at']); $keyLastUsedIso = $keyLastUsedTs !== false ? gmdate('c', $keyLastUsedTs) : ''; ?>
                                            <p><strong><?= t_attr('admin_label_last_used', 'Last Used:') ?></strong> <?php if ($keyLastUsedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyLastUsedIso); ?>"><?php echo date('y-m-d H:i', $keyLastUsedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                        <?php else: ?>
                                            <p><strong><?= t_attr('admin_label_last_used', 'Last Used:') ?></strong> <?= t_attr('admin_label_never', 'Never') ?></p>
                                        <?php endif; ?>
                                        <p><strong><?= t_attr('admin_label_last_updated', 'Last Updated:') ?></strong> <?php if ($keyUpdatedIso !== ''): ?><span class="local-datetime" data-datetime-iso="<?php echo htmlspecialchars($keyUpdatedIso); ?>"><?php echo date('y-m-d H:i', $keyUpdatedTs); ?></span><?php else: ?>—<?php endif; ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Constellations Tab -->
            <div id="content-constellations" class="p-6 <?php echo $activeTab !== 'constellations' ? 'hidden' : ''; ?>">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-gray-800 text-base font-semibold"><?= t_attr('admin_heading_galaxies', 'Galaxies') ?> (<span id="constellations-count">...</span>)</h2>
                            <button type="button" onclick="document.getElementById('create_constellation_modal').showModal()" class="text-blue-600 hover:text-blue-800 font-medium text-base"><?= t_attr('admin_btn_new_galaxy', 'New Galaxy') ?></button>
                            <?php bridges_admin_render('button'); ?>
                        </div>

                        <!-- Top Pagination -->
                        <div id="constellations-pagination-header" class="flex-1 flex justify-center"></div>

                        <div class="flex items-center gap-2 min-w-[250px]">
                            <label for="search-constellations" class="text-sm font-medium text-gray-700"><?= t_attr('admin_label_search', 'Search:') ?></label>
                            <input type="text"
                                   id="search-constellations"
                                   placeholder="<?= t_attr('admin_placeholder_search_galaxies', 'Search galaxies...') ?>"
                                   oninput="debouncedConstSearch()"
                                   class="flex-1 p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    <?php
                        $defaultId = (int)($projectAll['default_constellation_id'] ?? 0);
                        $defaultName = 'ID ' . $defaultId;
                        foreach($constellations as $c) {
                            if ((int)$c['id'] === $defaultId) {
                                $defaultName = htmlspecialchars($c['name']) . ' (ID ' . $defaultId . ')';
                                break;
                            }
                        }
                        $defaultBlock = '<strong>' . $defaultName . '</strong>';
                        $settingsLink = '<button onclick="showTab(\'settings\')" class="text-blue-600 hover:underline">' . t('admin_tab_settings', 'Global Settings') . '</button>';
                    ?>
                    <p class="text-sm text-gray-600 mb-4"><?= sprintf(t('admin_help_galaxies_default', 'Each galaxy is a separate set of wormholes and keywords. The current default galaxy, %s, cannot be deleted.'), $defaultBlock) ?><br><?= sprintf(t('admin_help_galaxies_settings_link', 'You can change the default galaxy in the %s tab.'), $settingsLink) ?></p>
                    <div id="copy-url-toast" class="hidden fixed top-4 right-4 z-50 bg-green-600 text-white px-4 py-3 rounded shadow-lg text-sm" role="status" aria-live="polite"><?= t_attr('admin_toast_url_copied', 'URL copied to clipboard.') ?></div>
                    <div id="constellations-list-container"></div>
                </div>
            </div>

            <!-- ========== Clusters Tab (Idea 2) ========== -->
            <div id="content-clusters" class="p-6 <?php echo $activeTab !== 'clusters' ? 'hidden' : ''; ?>">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-gray-800 text-base font-semibold"><?= t_attr('admin_heading_clusters', 'Galaxy Clusters') ?> (<span id="clusters-count">...</span>)</h2>
                            <button type="button" onclick="openClusterCreate()" class="text-blue-600 hover:text-blue-800 font-medium text-base"><?= t_attr('admin_btn_new_cluster', 'New Cluster') ?></button>
                        </div>

                        <!-- Top Pagination -->
                        <div id="clusters-pagination-header" class="flex-1 flex justify-center"></div>

                        <div class="flex items-center gap-2 min-w-[250px]">
                            <label for="search-clusters" class="text-sm font-medium text-gray-700"><?= t_attr('admin_label_search', 'Search:') ?></label>
                            <input type="text"
                                   id="search-clusters"
                                   placeholder="<?= t_attr('admin_placeholder_search_clusters', 'Search clusters...') ?>"
                                   oninput="debouncedClusterSearch()"
                                   class="flex-1 p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 mb-4"><?= t_attr('admin_help_clusters', 'A cluster is a curated union of galaxies with its own slug, title, theme, and permalink. Clusters have no native wormholes; they render the union of their members via the multigalaxy pipeline.') ?></p>
                    <div id="clusters-list-container"></div>
                </div>
            </div>

            <!-- Global Settings Tab -->
            <div id="content-settings" class="p-6 <?php echo $activeTab !== 'settings' ? 'hidden' : ''; ?>">
                <div class="flex justify-between items-center mb-4">
                    <p class="text-gray-600 max-w-2xl"><?= t_attr('admin_help_settings', 'Instance-wide settings for the main app.') ?></p>
                    <div class="bg-gray-100 px-3 py-1.5 rounded-md border border-gray-200">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= t_attr('admin_label_version', 'Version') ?></span>
                        <span class="ml-2 font-mono text-sm font-bold text-gray-700"><?php echo htmlspecialchars($systemVersion); ?></span>
                    </div>
                </div>
                <form method="post" action="" class="max-w-2xl">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <label for="instance_name" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('admin_label_instance_name', 'Name') ?></label>
                        <input type="text" id="instance_name" name="instance_name" required maxlength="255"
                               value="<?= htmlspecialchars(db_get_instance_name()) ?>"
                               class="input input-bordered input-sm w-full bg-white">
                        <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('admin_help_instance_name', 'Public name for this instance. Shown on the visitor side and used as the Pluriverse-directory label when you apply to publish. Defaults to the first segment of the hostname if blank.') ?></span>
                    </div>

                    <div class="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <label for="default_constellation_id" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('admin_label_default_galaxy', 'Default Galaxy') ?></label>
                        <select id="default_constellation_id" name="default_constellation_id" class="select select-bordered select-sm w-full bg-white">
                            <?php
                            $currentOptgroup = null;
                            $inOptgroup = false;
                            foreach ($constellations as $c):
                                $g = extractConstellationGroup($c['name']);
                                if ($g !== $currentOptgroup) {
                                    if ($inOptgroup) { echo '</optgroup>'; $inOptgroup = false; }
                                    if ($g !== null) { echo '<optgroup label="' . htmlspecialchars($g) . '">'; $inOptgroup = true; }
                                    $currentOptgroup = $g;
                                }
                            ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo (isset($projectAll['default_constellation_id']) && (int)$projectAll['default_constellation_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                    [ID: <?php echo (int)$c['id']; ?>] <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach;
                            if ($inOptgroup) echo '</optgroup>';
                            ?>
                        </select>
                        <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('admin_help_default_galaxy', 'Choose which galaxy is shown at the root of the website.') ?></span>
                    </div>

                    <div class="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <label for="pdf_max_mb" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= t_attr('admin_label_pdf_max', 'PDF max size (MB)') ?></label>
                        <input type="number" id="pdf_max_mb" name="pdf_max_mb" min="1" max="2048" step="1"
                               value="<?php echo (int)(db_get_pdf_max_bytes() / (1024 * 1024)); ?>"
                               class="input input-bordered input-sm w-32 bg-white">
                        <span class="text-xs text-gray-500 mt-1 block"><?= t_attr('admin_help_pdf_max', "Largest PDF a wormhole can carry. Default 25 MB. Editors uploading bigger files will get a 'File exceeds maximum allowed size' error.") ?></span>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-neutral"><?= t_attr('admin_btn_save_settings', 'Save settings') ?></button>
                    </div>
                </form>
            </div>

            <!-- Pluriverse Tab -->
            <div id="content-pluriverse" class="p-6 <?php echo $activeTab !== 'pluriverse' ? 'hidden' : ''; ?>">
                <div class="max-w-2xl">
                    <h2 class="text-gray-800 text-lg font-semibold mb-2"><?= t_attr('admin_pluriverse_heading', 'Publish to the Pluriverse') ?></h2>
                    <p class="text-sm text-gray-600 mb-6"><?= t_attr('admin_pluriverse_subheading', 'Federate this instance into the Pluriverse so it appears in the public instance directory at www.telaris.ca. The application carries your URL, name, operator contact, and chosen galaxies, signed by this instance\'s pluriverse.key.') ?></p>

                    <?php if ($pluriverseApplication !== null && in_array($pluriverseApplication['status'], ['pending','verified','published'], true)): ?>
                        <!-- State B: an application is in flight. -->
                        <div class="border border-emerald-300 bg-emerald-50 rounded-lg p-5 mb-4">
                            <h3 class="text-emerald-700 font-semibold mb-3"><?= t_attr('admin_pluriverse_status_heading', 'Membership status') ?></h3>
                            <dl class="grid grid-cols-[12rem_1fr] gap-x-4 gap-y-2 text-sm">
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_status', 'Status') ?></dt>
                                <dd class="font-mono font-semibold text-gray-800"><?= htmlspecialchars($pluriverseApplication['status']) ?></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_submitted', 'Submitted at') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseApplication['submitted_at']) ?></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_name', 'Name') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseApplication['label']) ?></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_email', 'Operator email') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseApplication['operator_email']) ?></dd>
                                <?php if (!empty($pluriverseApplication['remote_fingerprint'])): ?>
                                    <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_fingerprint', 'Public-key fingerprint stored') ?></dt>
                                    <dd class="font-mono text-xs text-gray-700"><?= htmlspecialchars($pluriverseApplication['remote_fingerprint']) ?></dd>
                                <?php endif; ?>
                            </dl>
                            <p class="text-sm text-gray-600 mt-4"><?= t_attr('admin_pluriverse_status_help', 'Check your operator email for a verification link. Both the link and the pending request expire 24 hours after submission. The admins at the Pluriverse review the request after you verify and let you know when your instance is published.') ?></p>
                        </div>
                    <?php elseif ($pluriverseApplication !== null && $pluriverseApplication['status'] === 'expired'): ?>
                        <!-- State C: the prior pending request expired without confirmation. -->
                        <div class="border border-amber-300 bg-amber-50 rounded-lg p-5 mb-4">
                            <h3 class="text-amber-700 font-semibold mb-3"><?= t_attr('admin_pluriverse_status_expired_heading', 'Join request expired') ?></h3>
                            <dl class="grid grid-cols-[12rem_1fr] gap-x-4 gap-y-2 text-sm">
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_status', 'Status') ?></dt>
                                <dd class="font-mono font-semibold text-amber-800"><?= htmlspecialchars($pluriverseApplication['status']) ?></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_submitted', 'Submitted at') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseApplication['submitted_at']) ?></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_name', 'Name') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseApplication['label']) ?></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_status_email', 'Operator email') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseApplication['operator_email']) ?></dd>
                            </dl>
                            <p class="text-sm text-gray-700 mt-4"><?= t_attr('admin_pluriverse_status_expired_body', 'The verification link from your last join request was not opened within 24 hours, so the request expired. You can submit a fresh one with the button below; you will receive a new verification email at your operator address.') ?></p>
                            <form method="POST" action="pluriverse-apply.php" class="mt-4">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded">
                                    <?= t_attr('admin_pluriverse_btn_rejoin', 'Re-join the Pluriverse') ?>
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <!-- State A: no active application; show the form. -->
                        <form method="POST" action="pluriverse-apply.php" class="space-y-5">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                            <!-- Read-only header: identity facts the operator does not need to retype. -->
                            <dl class="grid grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm bg-gray-50 border border-gray-200 rounded p-4">
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_field_url_label', 'Instance URL') ?></dt>
                                <dd class="font-mono text-gray-800 break-all"><?= htmlspecialchars($pluriverseDefaultUrl) ?></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_field_name_label', 'Name') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseInstanceName) ?> <a href="?tab=settings" class="text-xs text-blue-600 hover:text-blue-800 ml-2"><?= t_attr('admin_pluriverse_link_change_name', '(change in Global Settings)') ?></a></dd>
                                <dt class="text-gray-600"><?= t_attr('admin_pluriverse_field_email_label', 'Operator email') ?></dt>
                                <dd class="text-gray-800"><?= htmlspecialchars($pluriverseAdminEmail) ?></dd>
                            </dl>

                            <div>
                                <label class="block text-sm font-medium text-gray-800 mb-1"><?= t_attr('admin_pluriverse_field_contacts_label', 'Secondary contacts') ?></label>
                                <p class="text-xs text-gray-500 mb-2"><?= t_attr('admin_pluriverse_field_contacts_help', 'Optional fallback channels (Matrix, XMPP, etc.). Up to eight.') ?></p>
                                <ol id="pv-contacts-rows" class="space-y-2"></ol>
                                <template id="pv-contact-row-template">
                                    <li class="grid grid-cols-[1fr_2fr_auto] gap-2">
                                        <input type="text" name="contact_service[]" maxlength="64" placeholder="<?= t_attr('admin_pluriverse_contact_service_placeholder', 'service') ?>" class="input input-bordered input-sm bg-white">
                                        <input type="text" name="contact_user_id[]" maxlength="256" placeholder="<?= t_attr('admin_pluriverse_contact_handle_placeholder', 'handle / address') ?>" class="input input-bordered input-sm bg-white">
                                        <button type="button" class="pv-contact-remove px-3 text-gray-500 hover:text-red-600 border border-gray-300 rounded">×</button>
                                    </li>
                                </template>
                                <button type="button" id="pv-contacts-add" class="mt-2 text-sm text-blue-600 hover:text-blue-800">+ <?= t_attr('admin_pluriverse_btn_add_contact', 'Add another') ?></button>
                            </div>

                            <div>
                                <label for="pv_framing" class="block text-sm font-medium text-gray-800 mb-1"><?= t_attr('admin_pluriverse_field_framing_label', 'Editorial framing') ?></label>
                                <textarea id="pv_framing" name="editorial_framing" maxlength="2000" rows="3"
                                          class="textarea textarea-bordered textarea-sm w-full bg-white"></textarea>
                                <p class="text-xs text-gray-500 mt-1"><?= t_attr('admin_pluriverse_field_framing_help', 'A sentence or three. What is this instance for? Optional.') ?></p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-800 mb-1"><?= t_attr('admin_pluriverse_field_galaxies_label', 'Publishable galaxies') ?></label>
                                <?php $pvGalaxyCount = count($constellations); ?>
                                <?php if ($pvGalaxyCount === 0): ?>
                                    <p class="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded p-3"><?= t_attr('admin_pluriverse_field_galaxies_empty', 'No galaxies yet. The application registers this instance now; new galaxies are picked up automatically as you create them.') ?></p>
                                <?php else: ?>
                                    <p class="text-sm text-gray-700 bg-gray-50 border border-gray-200 rounded p-3"><?= htmlspecialchars(sprintf(t('admin_pluriverse_field_galaxies_summary', '%d galaxies on this instance will be published. New galaxies are added automatically as you create them.'), $pvGalaxyCount)) ?></p>
                                    <details class="mt-2">
                                        <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700"><?= t_attr('admin_pluriverse_field_galaxies_disclosure', 'See the list') ?></summary>
                                        <ul class="mt-2 border border-gray-200 rounded max-h-64 overflow-y-auto p-3 bg-white space-y-1">
                                            <?php foreach ($constellations as $g): ?>
                                                <li class="text-sm">
                                                    <code class="text-xs bg-gray-100 px-1 rounded"><?= htmlspecialchars($g['slug']) ?></code>
                                                    <span class="text-gray-700 ml-2"><?= htmlspecialchars($g['name']) ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>

                            <div class="pt-2 border-t border-gray-200">
                                <button type="submit" class="btn btn-primary"><?= t_attr('admin_pluriverse_btn_submit', 'Apply to Pluriverse') ?></button>
                                <p class="text-xs text-gray-500 mt-2"><?= t_attr('admin_pluriverse_submit_help', 'This instance will sign the application with its pluriverse.key (Ed25519) and post it to www.telaris.ca. The Pluriverse will email a verification link to the operator address.') ?></p>
                            </div>
                        </form>
                        <script>
                          (function () {
                            var rows = document.getElementById('pv-contacts-rows');
                            var addBtn = document.getElementById('pv-contacts-add');
                            var tpl = document.getElementById('pv-contact-row-template');
                            var MAX = 8;
                            if (rows && addBtn && tpl) {
                              addBtn.addEventListener('click', function () {
                                if (rows.children.length >= MAX) return;
                                var node = tpl.content.firstElementChild.cloneNode(true);
                                var rm = node.querySelector('.pv-contact-remove');
                                rm.addEventListener('click', function () { rows.removeChild(node); });
                                rows.appendChild(node);
                              });
                            }
                          })();
                        </script>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Backup Tab -->
            <div id="content-backup" class="p-6 <?php echo $activeTab !== 'backup' ? 'hidden' : ''; ?>">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                    <!-- Export -->
                    <section>
                        <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold"><?= t_attr('admin_heading_download_backup', 'Download a backup') ?></h2>
                        <p class="text-sm text-gray-600 mb-4"><?= t_attr('admin_help_download_backup', 'Create a portable backup file containing galaxies and/or users. The default produces a full backup with embedded media.') ?></p>

                        <form id="backup-export-form" method="POST" action="backup/export.php" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                            <div class="border border-gray-300 rounded p-4">
                                <label class="flex items-center gap-2 mb-3">
                                    <input type="checkbox" name="include_galaxies" value="1" checked id="export-include-galaxies" class="checkbox checkbox-sm">
                                    <span class="font-semibold"><?= t_attr('admin_label_galaxies', 'Galaxies') ?></span>
                                </label>
                                <div id="export-galaxies-options" class="ml-6 space-y-2">
                                    <label class="flex items-center gap-2"><input type="radio" name="galaxy_scope" value="all" checked class="radio radio-sm"> <span><?= t_attr('admin_label_all_galaxies', 'All galaxies') ?></span></label>
                                    <label class="flex items-center gap-2"><input type="radio" name="galaxy_scope" value="selected" class="radio radio-sm"> <span><?= t_attr('admin_label_selected_galaxies', 'Selected galaxies only') ?></span></label>
                                    <div id="export-galaxy-picker" class="hidden mt-3 border border-gray-200 rounded p-3 bg-gray-50">
                                        <div id="export-prefix-chips" class="flex flex-wrap gap-1 mb-3"></div>
                                        <div id="export-galaxy-list" class="max-h-64 overflow-y-auto bg-white border border-gray-200 rounded">
                                            <p class="text-xs text-gray-500 p-3"><?= t_attr('admin_msg_loading_galaxies', 'Loading galaxies...') ?></p>
                                        </div>
                                        <div class="flex justify-between mt-2 text-xs">
                                            <button type="button" onclick="exportGalaxiesSelectAll(true)" class="text-blue-600 hover:underline"><?= t_attr('admin_btn_select_all', 'Select all') ?></button>
                                            <button type="button" onclick="exportGalaxiesSelectAll(false)" class="text-blue-600 hover:underline"><?= t_attr('admin_btn_clear', 'Clear') ?></button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border border-gray-300 rounded p-4">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="include_users" value="1" checked class="checkbox checkbox-sm">
                                    <span class="font-semibold"><?= t_attr('admin_label_users_always_all', 'Users (always all)') ?></span>
                                </label>
                                <p class="text-xs text-gray-500 ml-6"><?= t_attr('admin_help_users_export', 'User passwords are exported as hashes. They never appear in plaintext.') ?></p>
                            </div>

                            <div class="border border-gray-300 rounded p-4">
                                <div class="font-semibold mb-2"><?= t_attr('admin_label_media_files', 'Media files') ?></div>
                                <div class="space-y-1 text-sm">
                                    <label class="flex items-center gap-2"><input type="radio" name="media_mode" value="embedded" checked class="radio radio-sm"> <span><?= t_attr('admin_label_media_embedded', 'Embedded: self-contained backup (recommended)') ?></span></label>
                                    <label class="flex items-center gap-2"><input type="radio" name="media_mode" value="refs" class="radio radio-sm"> <span><?= t_attr('admin_label_media_refs', 'References only: smaller file, only restorable on the same server') ?></span></label>
                                    <label class="flex items-center gap-2"><input type="radio" name="media_mode" value="none" class="radio radio-sm"> <span><?= t_attr('admin_label_media_none', 'None: strip all media') ?></span></label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary"><?= t_attr('admin_btn_download_backup', 'Download backup') ?></button>
                        </form>
                    </section>

                    <!-- Import -->
                    <section>
                        <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold"><?= t_attr('admin_heading_restore_backup', 'Restore from a backup') ?></h2>
                        <p class="text-sm text-gray-600 mb-4"><?= t_attr('admin_help_restore_backup', 'Upload a .telaris-backup file. You will see a summary before anything is changed.') ?></p>

                        <div class="space-y-4">
                            <div class="border border-gray-300 rounded p-4">
                                <input type="file" id="backup-import-file" accept=".telaris-backup,application/gzip,application/octet-stream" class="file-input file-input-bordered file-input-sm w-full" onchange="backupOnFilePicked()">
                                <div id="backup-import-file-info" class="hidden mt-2 text-xs text-gray-600"></div>
                                <button type="button" id="backup-import-inspect-btn" onclick="backupInspect()" class="btn btn-neutral btn-sm mt-3"><?= t_attr('admin_btn_inspect_file', 'Inspect file') ?></button>
                                <div id="backup-import-status" class="hidden mt-3 text-sm">
                                    <div id="backup-import-status-text" class="text-gray-700"></div>
                                    <div id="backup-import-progress-wrap" class="hidden mt-1 w-full bg-gray-200 rounded h-2 overflow-hidden">
                                        <div id="backup-import-progress-bar" class="bg-blue-500 h-2 transition-all duration-200" style="width: 0%"></div>
                                    </div>
                                </div>
                            </div>

                            <div id="backup-import-summary" class="hidden border border-blue-300 bg-blue-50 rounded p-4 text-sm">
                                <!-- Filled by JS -->
                            </div>

                            <div id="backup-import-options" class="hidden border border-gray-300 rounded p-4 space-y-4">
                                <div>
                                    <div class="font-semibold mb-2"><?= t_attr('admin_label_galaxies_in_file', 'Galaxies in this file') ?></div>
                                    <div id="import-prefix-chips" class="flex flex-wrap gap-1 mb-2"></div>
                                    <div id="import-galaxy-list" class="max-h-64 overflow-y-auto bg-white border border-gray-200 rounded"></div>
                                    <div class="flex justify-between mt-2 text-xs">
                                        <button type="button" onclick="importGalaxiesSelectAll(true)" class="text-blue-600 hover:underline"><?= t_attr('admin_btn_select_all', 'Select all') ?></button>
                                        <button type="button" onclick="importGalaxiesSelectAll(false)" class="text-blue-600 hover:underline"><?= t_attr('admin_btn_clear', 'Clear') ?></button>
                                    </div>
                                </div>

                                <div>
                                    <div class="font-semibold mb-2"><?= t_attr('admin_label_for_each_galaxy', 'For each selected galaxy') ?></div>
                                    <div class="space-y-1 text-sm">
                                        <label class="flex items-center gap-2"><input type="radio" name="import_conflict" value="overwrite" checked class="radio radio-sm"> <span><?= t_attr('admin_label_overwrite_slug', 'Overwrite if a galaxy with the same slug exists') ?></span></label>
                                        <label class="flex items-center gap-2"><input type="radio" name="import_conflict" value="rename" class="radio radio-sm"> <span><?= t_attr('admin_label_create_as_new', 'Create as new (rename on conflict, suffix:') ?></span>
                                            <input type="text" id="import-rename-suffix" value=" (restored)" class="input input-bordered input-xs ml-1" style="width: 140px;">
                                            <span>)</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="border-t pt-3">
                                    <div class="font-semibold mb-2"><?= t_attr('admin_label_users_in_file', 'Users in this file') ?></div>
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="import-restore-users" checked class="checkbox checkbox-sm"> <span><?= t_attr('admin_label_restore_users', 'Restore users') ?></span></label>
                                    <div class="ml-6 mt-2 space-y-1 text-sm">
                                        <label class="flex items-center gap-2"><input type="radio" name="import_users_mode" value="skip" checked class="radio radio-sm"> <span><?= t_attr('admin_label_skip_existing', 'Skip existing users (match by email)') ?></span></label>
                                        <label class="flex items-center gap-2"><input type="radio" name="import_users_mode" value="replace" class="radio radio-sm"> <span><?= t_attr('admin_label_update_existing', 'Update existing users by email') ?></span></label>
                                        <label class="flex items-center gap-2 ml-6"><input type="checkbox" id="import-users-replace-pw" class="checkbox checkbox-sm"> <span><?= t_attr('admin_label_overwrite_pw', 'Also overwrite password hashes') ?></span></label>
                                    </div>
                                </div>

                                <div class="border-t pt-3">
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="import-restore-media" checked class="checkbox checkbox-sm"> <span><?= t_attr('admin_label_restore_media', 'Restore media files') ?></span></label>
                                </div>

                                <div class="border-t pt-3">
                                    <button type="button" onclick="backupCommit()" class="btn btn-warning"><?= t_attr('admin_btn_restore', 'Restore') ?></button>
                                </div>
                            </div>

                            <div id="backup-import-result" class="hidden border border-green-300 bg-green-50 rounded p-4 text-sm">
                                <!-- Filled by JS -->
                            </div>
                        </div>
                    </section>

                </div>
            </div>

            <!-- Snapshots Tab -->
            <div id="content-snapshots" class="p-6 <?php echo $activeTab !== 'snapshots' ? 'hidden' : ''; ?>">
                <p class="text-sm text-gray-600 mb-4"><?= t_attr('admin_help_snapshots', "Snapshots are local, on-disk full backups of the entire system. Restoring a snapshot wipes everything and replaces it with the snapshot's state. Any snapshots created after the restored one are deleted.") ?></p>

                <!-- Create snapshot -->
                <section class="mb-8 border border-gray-300 rounded p-4">
                    <h2 class="text-lg font-semibold mb-3"><?= t_attr('admin_heading_create_snapshot', 'Create snapshot now') ?></h2>
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="text" id="snapshot-note" placeholder="<?= t_attr('admin_placeholder_snapshot_note', 'Optional note (e.g. before migration)') ?>" class="input input-bordered input-sm flex-1 min-w-[240px]">
                        <button type="button" id="snapshot-create-btn" onclick="snapshotCreate()" class="btn btn-neutral btn-sm"><?= t_attr('admin_btn_create_snapshot', 'Create snapshot') ?></button>
                    </div>
                    <div id="snapshot-create-progress" class="mt-3 hidden">
                        <progress class="progress progress-neutral w-full"></progress>
                        <p id="snapshot-create-progress-label" class="text-xs text-gray-600 mt-1"><?= t_attr('admin_msg_creating_snapshot', 'Creating snapshot. This may take a minute for large instances. Please do not close this tab.') ?></p>
                    </div>
                </section>

                <!-- Schedule -->
                <section class="mb-8 border border-gray-300 rounded p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h2 class="text-lg font-semibold"><?= t_attr('admin_heading_snapshot_scheduler', 'Snapshot scheduler') ?></h2>
                        <label class="text-sm flex items-center gap-3 cursor-pointer select-none">
                            <span class="font-medium"><?= t_attr('admin_label_enable_daily', 'Enable daily snapshots') ?></span>
                            <input type="checkbox" id="schedule-enabled" class="toggle toggle-neutral toggle-sm">
                        </label>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
                        <label class="text-sm"><?= t_attr('admin_label_hour_utc', 'Hour (UTC)') ?>
                            <input type="number" id="schedule-hour" min="0" max="23" value="3" class="input input-bordered input-sm w-full">
                        </label>
                        <label class="text-sm"><?= t_attr('admin_label_keep_days', 'Keep days (auto)') ?>
                            <input type="number" id="schedule-keep-days" min="1" value="7" class="input input-bordered input-sm w-full">
                        </label>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 items-center">
                        <button type="button" onclick="scheduleSave()" class="btn btn-neutral btn-sm"><?= t_attr('admin_btn_save', 'Save') ?></button>
                        <button type="button" onclick="snapshotsLoad()" class="btn btn-ghost btn-sm"><?= t_attr('admin_btn_refresh_status', 'Refresh status') ?></button>
                    </div>

                    <div class="mt-4 pt-3 border-t border-gray-200">
                        <div class="flex flex-wrap gap-4 text-sm">
                            <div>
                                <span class="text-gray-500"><?= t_attr('admin_label_status', 'Status:') ?></span>
                                <span id="scheduler-status-badge" class="ml-1 px-2 py-0.5 rounded text-xs bg-gray-200"><?= t_attr('admin_label_status_loading', 'loading...') ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500"><?= t_attr('admin_label_last_snapshot', 'Last snapshot:') ?></span>
                                <span id="scheduler-last-run" class="ml-1"><?= t_attr('admin_label_never_lower', 'never') ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500"><?= t_attr('admin_label_last_checked', 'Last checked:') ?></span>
                                <span id="scheduler-last-check" class="ml-1"><?= t_attr('admin_label_never_lower', 'never') ?></span>
                            </div>
                        </div>
                        <div id="scheduler-status-detail" class="text-xs text-amber-700 mt-2 hidden"></div>
                        <div class="text-xs text-gray-600 mt-3 mb-1"><?= t_attr('admin_label_recent_activity', 'Recent activity') ?></div>
                        <pre id="scheduler-log" class="bg-gray-900 text-green-200 p-2 rounded text-xs overflow-x-auto max-h-64 whitespace-pre-wrap"><?= t_attr('admin_msg_no_activity', '(no activity yet)') ?></pre>
                    </div>
                </section>

                <!-- List -->
                <section>
                    <h2 class="text-lg font-semibold mb-3"><?= t_attr('admin_heading_available_snapshots', 'Available snapshots') ?></h2>
                    <div id="snapshots-table-wrap">
                        <p class="text-sm text-gray-500"><?= t_attr('admin_msg_loading', 'Loading...') ?></p>
                    </div>
                </section>
            </div>

            <!-- PHP Information Tab -->
            <div id="content-php-info" class="p-6 <?php echo $activeTab !== 'php-info' ? 'hidden' : ''; ?>">
                <!-- PHP Configuration -->
                <div class="mb-6">
                    <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold"><?= t_attr('admin_heading_php_config', 'PHP Configuration') ?></h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
                        <?php foreach ($phpConfig as $label => $value): ?>
                            <div class="p-2.5 bg-gray-50 rounded">
                                <div class="font-semibold text-gray-600 text-sm mb-1"><?php echo htmlspecialchars($label); ?></div>
                                <div class="text-gray-800 text-lg"><?php echo htmlspecialchars((string)$value); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Important Extensions -->
                <div class="mb-6">
                    <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold"><?= t_attr('admin_heading_important_extensions', 'Important Extensions') ?></h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5 mt-4">
                        <?php foreach ($extensionStatus as $name => $installed): ?>
                            <div class="p-3 rounded flex items-center gap-2.5 <?php echo $installed ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-700'; ?>">
                                <span class="font-bold text-xl"><?php echo $installed ? '✓' : '✗'; ?></span>
                                <span><?php echo htmlspecialchars($name); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- All Loaded Extensions -->
                <div>
                    <h2 class="text-blue-500 mb-4 pb-2.5 border-b-2 border-gray-200 text-xl font-semibold"><?= t_attr('admin_heading_all_extensions', 'All Loaded Extensions') ?> (<?php echo count($loadedExtensions); ?>)</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2 mt-4">
                        <?php foreach ($loadedExtensions as $ext): ?>
                            <span class="p-1.5 px-2.5 bg-gray-100 rounded text-sm font-mono"><?php echo htmlspecialchars($ext); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
    
    <script>
        const API_KEY = <?php echo json_encode(getDefaultApiKey()); ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
        window.TELARIS_CSRF_TOKEN = CSRF_TOKEN;
        const API_URL = '../api/validate.php';

        window.TELARIS_ADMIN = <?= json_encode([
            // Galaxy table chrome
            'msgNoGalaxies' => t('admin_msg_no_galaxies', 'No galaxies found.'),
            'colId' => t('admin_col_id', 'ID'),
            'colGalaxyName' => t('admin_col_galaxy_name', 'Name'),
            'colSlug' => t('admin_col_slug', 'Slug'),
            'colTagline' => t('admin_col_tagline', 'Tagline'),
            'colWormholes' => t('admin_col_wormholes', 'Wormholes'),
            'colCreated' => t('admin_col_created', 'Created'),
            'colLastUpdated' => t('admin_col_last_updated', 'Last Updated'),
            'colActions' => t('admin_col_actions', 'Actions'),
            'badgeDefault' => t('admin_badge_default', 'Default'),
            'badgeImported' => t('admin_badge_imported', 'Imported'),
            'titleTourEnabled' => t('admin_title_tour_enabled', 'Auto-tour enabled'),
            'msgErrorLoadingGalaxies' => t('admin_msg_error_loading_galaxies', 'Error loading galaxies: %s'),
            'actionEdit' => t('admin_action_edit', 'Edit'),
            'actionView' => t('admin_action_view', 'View'),
            'actionCopyUrl' => t('admin_action_copy_url', 'Copy URL'),
            'actionKeywordCanvas' => t('admin_action_keyword_canvas', 'Keyword canvas'),
            'actionDuplicate' => t('admin_action_duplicate', 'Duplicate'),
            'actionRefresh' => t('admin_action_refresh', 'Refresh'),
            'actionDelete' => t('admin_action_delete', 'Delete'),
            'confirmDeleteGalaxy' => t('admin_confirm_delete_galaxy', 'Are you sure you want to delete the galaxy "%s"? This will permanently remove ALL wormholes and keywords inside it.'),
            // Clusters table chrome
            'msgNoClustersSearch' => t('admin_msg_no_clusters_search', 'No clusters match this search.'),
            'msgNoClusters' => t('admin_msg_no_clusters', 'No clusters yet.'),
            'colTheme' => t('admin_col_theme', 'Theme'),
            'colMembers' => t('admin_col_members', 'Members'),
            'titleIdleSpotlight' => t('admin_title_idle_spotlight', 'Idle spotlight enabled'),
            'titleGalaxyList' => t('admin_title_galaxy_list', 'Galaxy list shown to visitors'),
            'badgeGalaxyList' => t('admin_badge_galaxy_list', 'Galaxy list'),
            'confirmDeleteCluster' => t('admin_confirm_delete_cluster', 'Delete cluster "%s"? Members (the galaxies inside) are unaffected; only the cluster itself is removed.'),
            'msgErrorLoadingClusters' => t('admin_msg_error_loading_clusters', 'Error loading clusters: %s'),
            // Backup chrome
            'btnInspectFile' => t('admin_btn_inspect_file', 'Inspect file'),
            'labelNoPrefixChip' => t('admin_label_no_prefix_chip', 'No prefix (%d)'),
            'labelWormholeCount' => t('admin_label_wormhole_count', '%d wormholes'),
            'labelDefaultInline' => t('admin_label_default_inline', '(default)'),
            'msgNoGalaxiesInBackup' => t('admin_msg_no_galaxies_in_backup', 'No galaxies in this backup.'),
            'msgFileSelected' => t('admin_msg_file_selected', 'Selected: %s (%s)'),
            'toastChooseBackup' => t('admin_toast_choose_backup', 'Choose a backup file first.'),
            'toastInspectFirst' => t('admin_toast_inspect_first', 'Inspect a file first.'),
            'toastInspectFailed' => t('admin_toast_inspect_failed', 'Inspect failed: %s'),
            'toastFailedPrefix' => t('admin_toast_failed_prefix', 'Failed: %s'),
            'toastNothingSelected' => t('admin_toast_nothing_selected', 'Nothing selected to restore.'),
            'confirmRestore' => t('admin_confirm_restore', "Restore %s into this system?\n\nConflict mode: %s\n\nThis cannot be undone."),
            'toastRestoreComplete' => t('admin_toast_restore_complete', 'Restore complete.'),
            'toastRestoreFailed' => t('admin_toast_restore_failed', 'Restore failed: %s'),
            'labelBackupSummary' => t('admin_label_backup_summary', 'Backup file summary'),
            'textFormatAppCreated' => t('admin_text_format_app_created', 'Format v%s · App %s · Created %s'),
            'textSummaryCounts' => t('admin_text_summary_counts', 'Galaxies: %s · Wormholes: %s · Keywords: %s'),
            'textSummaryUsersMedia' => t('admin_text_summary_users_media', 'Users: %s%s · Media: %s files (%s MB)'),
            'textNoAdminUserWarn' => t('admin_text_no_admin_user_warn', '(no admin user!)'),
            'labelFailures' => t('admin_label_failures', 'Failures:'),
            'headingRestoreComplete' => t('admin_heading_restore_complete', 'Restore complete'),
            'textGalaxiesReport' => t('admin_text_galaxies_report', 'Galaxies: created %s, overwritten %s, renamed %s, skipped %s'),
            'textUsersReport' => t('admin_text_users_report', 'Users: created %s, updated %s, skipped %s'),
            'textMediaReport' => t('admin_text_media_report', 'Media files: written %s, skipped %s'),
            // Snapshots
            'labelDisabled' => t('admin_label_disabled', 'Disabled'),
            'labelActive' => t('admin_label_active', 'Active'),
            'labelNeedsAttention' => t('admin_label_needs_attention', 'Needs attention'),
            'msgCronInactive' => t('admin_msg_cron_inactive', "The system's cron service is not running (%s). Scheduled snapshots will not be taken until cron is started."),
            'msgCronNotInstalled' => t('admin_msg_cron_not_installed', 'Unable to register the scheduler with cron. Try saving again.'),
            'msgSchedulerUnknown' => t('admin_msg_scheduler_unknown', 'Scheduler status unknown.'),
            'msgNoActivity' => t('admin_msg_no_activity', '(no activity yet)'),
            'labelNeverLower' => t('admin_label_never_lower', 'never'),
            'msgNoSnapshots' => t('admin_msg_no_snapshots', 'No snapshots yet. Create one above.'),
            'colSnapshotCreated' => t('admin_col_snapshot_created', 'Created (UTC)'),
            'colSize' => t('admin_col_size', 'Size'),
            'colType' => t('admin_col_type', 'Type'),
            'colCreator' => t('admin_col_creator', 'Creator'),
            'colNote' => t('admin_col_note', 'Note'),
            'labelFileMissing' => t('admin_label_file_missing', '(file missing)'),
            'labelCreatorSystem' => t('admin_label_creator_system', 'system'),
            'actionRestore' => t('admin_action_restore', 'Restore'),
            'actionDownload' => t('admin_action_download', 'Download'),
            'btnCreating' => t('admin_btn_creating', 'Creating...'),
            'msgCreatingElapsed' => t('admin_msg_creating_elapsed', 'Creating snapshot. Elapsed: %ss. This may take a minute for large instances. Please do not close this tab.'),
            'msgCreatingSnapshot' => t('admin_msg_creating_snapshot', 'Creating snapshot. This may take a minute for large instances. Please do not close this tab.'),
            'toastSnapshotCreated' => t('admin_toast_snapshot_created', 'Snapshot created in %ss.'),
            'toastCreateSnapshotFailed' => t('admin_toast_create_snapshot_failed', 'Create snapshot failed: %s'),
            'confirmDeleteSnapshot' => t('admin_confirm_delete_snapshot', 'Delete this snapshot? The file will be permanently removed from disk.'),
            'toastSnapshotDeleted' => t('admin_toast_snapshot_deleted', 'Snapshot deleted.'),
            'toastDeleteFailed' => t('admin_toast_delete_failed', 'Delete failed: %s'),
            'promptRestoreSnapshot' => t('admin_prompt_restore_snapshot', "RESTORE will WIPE the entire system and replace it with the snapshot from %s.\n\nAll snapshots created after that point will also be deleted.\n\nType RESTORE to confirm:"),
            'toastConfirmPhraseMismatch' => t('admin_toast_confirm_phrase_mismatch', 'Confirmation phrase did not match. Restore cancelled.'),
            'confirmNoAdmin' => t('admin_confirm_no_admin', 'WARNING: this snapshot has no admin user. Restoring will lock everyone out of the admin console. Proceed anyway?'),
            'toastRestoreCompleteLogout' => t('admin_toast_restore_complete_logout', 'Restore complete. You may be logged out.'),
            'toastRestoreCompleteReport' => t('admin_toast_restore_complete_report', 'Restore complete. Created %s galaxies, %s users. %s later snapshot(s) deleted. You may be logged out.'),
            'toastFailedLoadGalaxies' => t('admin_toast_failed_load_galaxies', 'Failed to load galaxies: %s'),
            'toastSavedCronWarning' => t('admin_toast_saved_cron_warning', 'Saved, but scheduler could not register with cron: %s'),
            'toastScheduleSaved' => t('admin_toast_schedule_saved', 'Schedule saved.'),
            'toastSaveScheduleFailed' => t('admin_toast_save_schedule_failed', 'Save schedule failed: %s'),
            // C4: modal JS chrome
            'clusterModalCreateTitle' => t('admin_modal_heading_create_cluster', 'Create Cluster'),
            'clusterModalEditTitle' => t('admin_modal_heading_edit_cluster', 'Edit Cluster'),
            'clusterModalDuplicateTitle' => t('admin_modal_heading_duplicate_cluster', 'Duplicate Cluster'),
            'clusterModalCreateSubmit' => t('admin_modal_btn_create_cluster', 'Create Cluster'),
            'clusterModalUpdateSubmit' => t('admin_modal_btn_update_cluster', 'Update Cluster'),
            'countSelectedOne' => t('admin_modal_count_selected_one', '%d selected'),
            'countSelectedMany' => t('admin_modal_count_selected_many', '%d selected'),
            'nameCopySuffix' => t('admin_modal_name_copy_suffix', ' (Copy)'),
            'deletionImpactTitle' => t('admin_modal_deletion_impact_title', '⚠️ Deletion Impact:'),
            'deletionImpactIntro' => t('admin_modal_deletion_impact_intro', 'The following portals in other galaxies point to this network and will also be deleted:'),
            'deletionImpactRow' => t('admin_modal_deletion_impact_row', '<strong>%s</strong> (in galaxy: %s)'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;

        const ADM = window.TELARIS_ADMIN || {};
        function tFmtAdm(tpl, ...args) {
            if (!tpl) return '';
            let i = 0;
            return String(tpl).replace(/%[ds]/g, () => (i < args.length ? String(args[i++]) : ''));
        }

        // Generic dispatcher for the per-galaxy Refresh link. Each bridge's
        // admin partial registers its refresh handler in window.BRIDGES_REFRESH_UI;
        // this function looks up the active bridge from the constellation's
        // import_source and routes the click to it.
        function bridgeRefreshConstellation(constId, name) {
            const source = (window.constImportSources || {})[constId];
            if (!source || !source.source) {
                showMessage('No import source recorded for this galaxy.', 'error');
                return;
            }
            const handler = (window.BRIDGES_REFRESH_UI || {})[source.source];
            if (!handler) {
                showMessage("Bridge '" + source.source + "' has no refresh UI on this instance.", 'error');
                return;
            }
            handler(constId, name);
        }

        function closeAllDropdowns(except) {
            document.querySelectorAll('.dropdown').forEach(d => {
                const label = d.querySelector('[tabindex="0"]');
                if (label && label !== except) label.blur();
            });
        }
        document.addEventListener('click', () => closeAllDropdowns(null));

        async function validateField(type, params) {
            if (!API_KEY) return { valid: true }; // Skip if no API key (shouldn't happen)
            const query = new URLSearchParams({ type, ...params, api_key: API_KEY }).toString();
            try {
                const response = await fetch(`${API_URL}?${query}`);
                return await response.json();
            } catch (e) {
                console.error('Validation failed', e);
                return { valid: true };
            }
        }

        function showMessage(text, type = 'success', title = null) {
            const container = document.getElementById('notification-container');
            if (!container) return;

            const toast = document.createElement('div');
            // Using DaisyUI alert classes for styling
            toast.className = `alert ${type === 'success' ? 'alert-success' : 'alert-error'} shadow-lg mb-2 pointer-events-auto transition-all duration-500 transform -translate-y-4 opacity-0 text-white`;
            
            let content = `<div>`;
            if (title) content += `<h3 class="font-bold text-xs uppercase opacity-80 mb-1">${title}</h3>`;
            content += `<div class="text-sm font-medium">${text}</div></div>`;
            
            toast.innerHTML = content;
            container.appendChild(toast);

            // Trigger animation
            requestAnimationFrame(() => {
                toast.classList.remove('-translate-y-4', 'opacity-0');
            });

            // Auto-remove after 2 seconds
            setTimeout(() => {
                toast.classList.add('-translate-y-4', 'opacity-0');
                setTimeout(() => toast.remove(), 500);
            }, 2000);
        }

        function toggleUserConstellationsSection() {
            const typeSelect = document.getElementById('type');
            const section = document.getElementById('user-constellations-section');
            if (!typeSelect || !section) return;
            const isEditor = typeSelect.value === '1';
            section.classList.toggle('hidden', !isEditor);
        }
        function toggleCreateNewConstellationName() {
            const cb = document.getElementById('create_constellation_cb');
            const wrap = document.getElementById('create-new-constellation-name-wrap');
            if (cb && wrap) wrap.classList.toggle('hidden', !cb.checked);
        }
        function initCreateUserForm() {
            const emailEl = document.getElementById('create-email');
            const nameEl = document.getElementById('create_new_constellation_name');
            const createCb = document.getElementById('create_constellation_cb');
            if (createCb) createCb.addEventListener('change', toggleCreateNewConstellationName);
            if (emailEl && nameEl) {
                emailEl.addEventListener('input', function() {
                    if (nameEl.value === '' || nameEl.getAttribute('data-auto') === '1') {
                        nameEl.value = emailEl.value;
                        nameEl.setAttribute('data-auto', '1');
                    }
                });
                nameEl.addEventListener('input', function() { nameEl.removeAttribute('data-auto'); });
            }
        }

        function setupLiveValidation() {
            const debounce = (fn, delay) => {
                let timeoutId;
                return (...args) => {
                    clearTimeout(timeoutId);
                    timeoutId = setTimeout(() => fn(...args), delay);
                };
            };

            const validateUser = async (emailEl, errorEl, idEl = null) => {
                const email = emailEl.value.trim();
                const form = emailEl.closest('form');
                const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
                if (!email) {
                    errorEl.classList.add('hidden');
                    return;
                }
                const result = await validateField('user', { email, exclude_id: idEl ? idEl.value : null });
                if (result.email) {
                    errorEl.classList.remove('hidden');
                    emailEl.classList.add('border-red-500');
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    errorEl.classList.add('hidden');
                    emailEl.classList.remove('border-red-500');
                    if (submitBtn) {
                        const otherErrors = form.querySelectorAll('.text-red-600:not(.hidden)');
                        if (otherErrors.length === 0) submitBtn.disabled = false;
                    }
                }
            };

            const validateConstellation = async (nameEl, slugEl, nameErrEl, slugErrEl, idEl = null) => {
                const name = nameEl.value.trim();
                const slug = slugEl.value.trim();
                const form = nameEl.closest('form');
                const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
                if (!name && !slug) {
                    nameErrEl.classList.add('hidden');
                    slugErrEl.classList.add('hidden');
                    return;
                }
                const result = await validateField('constellation', { name, slug, exclude_id: idEl ? idEl.value : null });
                let hasError = false;
                if (result.name) {
                    nameErrEl.classList.remove('hidden');
                    nameEl.classList.add('border-red-500');
                    hasError = true;
                } else {
                    nameErrEl.classList.add('hidden');
                    nameEl.classList.remove('border-red-500');
                }
                if (result.slug) {
                    slugErrEl.classList.remove('hidden');
                    slugEl.classList.add('border-red-500');
                    hasError = true;
                } else {
                    slugErrEl.classList.add('hidden');
                    slugEl.classList.remove('border-red-500');
                }
                if (submitBtn) {
                    if (hasError) submitBtn.disabled = true;
                    else {
                        const otherErrors = form.querySelectorAll('.text-red-600:not(.hidden)');
                        if (otherErrors.length === 0) submitBtn.disabled = false;
                    }
                }
            };

            // Create User
            const createEmail = document.getElementById('create-email');
            const createEmailErr = document.getElementById('create-email-error');
            if (createEmail) createEmail.addEventListener('input', debounce(() => validateUser(createEmail, createEmailErr), 500));

            // Edit User
            const modalEmail = document.getElementById('modal-email');
            const modalEmailErr = document.getElementById('modal-email-error');
            const modalUserId = document.getElementById('modal-user-id');
            if (modalEmail) modalEmail.addEventListener('input', debounce(() => validateUser(modalEmail, modalEmailErr, modalUserId), 500));

            // Create Constellation
            const createCName = document.getElementById('create-constellation-name');
            const createCSlug = document.getElementById('create-constellation-slug');
            const createCNameErr = document.getElementById('create-constellation-name-error');
            const createCSlugErr = document.getElementById('create-constellation-slug-error');
            const validateCreateC = debounce(() => validateConstellation(createCName, createCSlug, createCNameErr, createCSlugErr), 500);
            if (createCName) createCName.addEventListener('input', validateCreateC);
            if (createCSlug) createCSlug.addEventListener('input', validateCreateC);

            // Edit Constellation
            const modalCName = document.getElementById('modal-constellation-name');
            const modalCSlug = document.getElementById('modal-constellation-slug');
            const modalCNameErr = document.getElementById('modal-constellation-name-error');
            const modalCSlugErr = document.getElementById('modal-constellation-slug-error');
            const modalCId = document.getElementById('modal-constellation-id');
            const validateModalC = debounce(() => validateConstellation(modalCName, modalCSlug, modalCNameErr, modalCSlugErr, modalCId), 500);
            if (modalCName) modalCName.addEventListener('input', validateModalC);
            if (modalCSlug) modalCSlug.addEventListener('input', validateModalC);

            // Duplicate Constellation
            const dupCName = document.getElementById('duplicate-constellation-name');
            const dupCSlug = document.getElementById('duplicate-constellation-slug');
            const dupCNameErr = document.getElementById('duplicate-constellation-name-error');
            const dupCSlugErr = document.getElementById('duplicate-constellation-slug-error');
            const validateDupC = debounce(() => validateConstellation(dupCName, dupCSlug, dupCNameErr, dupCSlugErr), 500);
            if (dupCName) dupCName.addEventListener('input', validateDupC);
            if (dupCSlug) dupCSlug.addEventListener('input', validateDupC);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const typeSelect = document.getElementById('type');
            if (typeSelect) typeSelect.addEventListener('change', toggleUserConstellationsSection);
            toggleCreateNewConstellationName();
            initCreateUserForm();
            setupLiveValidation();
        });
        function copyConstellationUrl(relativePath, buttonEl) {
            const absoluteUrl = new URL(relativePath, window.location.origin + window.location.pathname).href;
            navigator.clipboard.writeText(absoluteUrl).then(function() {
                const toast = document.getElementById('copy-url-toast');
                if (toast) {
                    toast.classList.remove('hidden');
                    setTimeout(function() { toast.classList.add('hidden'); }, 3000);
                }
                if (buttonEl) {
                    const origTitle = buttonEl.getAttribute('title');
                    buttonEl.setAttribute('title', 'Copied!');
                    setTimeout(function() { buttonEl.setAttribute('title', origTitle || 'Copy galaxy URL'); }, 1500);
                }
            });
        }
        const userConstellationsMap = <?php echo json_encode($userConstellationsMap); ?>;

        // Pagination State
        const paginationState = {
            users: { currentPage: 1, itemsPerPage: 20 }
        };

        function applyPagination(type) {
            const state = paginationState[type];
            const selector = type === 'users' ? 'tr.user-row' : 'tr.constellation-row';
            const searchId = type === 'users' ? 'search-users' : 'search-constellations';
            const query = document.getElementById(searchId).value.toLowerCase().trim();
            
            const allRows = Array.from(document.querySelectorAll(selector));
            
            // First filter based on search
            const visibleFilteredRows = allRows.filter(row => {
                let text = '';
                if (type === 'users') {
                    text = (row.dataset.name || '') + ' ' + (row.dataset.email || '');
                } else {
                    text = (row.dataset.name || '') + ' ' + (row.dataset.tagline || '') + ' ' + (row.dataset.id || '');
                }
                return text.toLowerCase().includes(query);
            });

            const totalItems = visibleFilteredRows.length;
            const totalPages = Math.ceil(totalItems / state.itemsPerPage);
            
            if (state.currentPage > totalPages && totalPages > 0) state.currentPage = totalPages;
            if (state.currentPage < 1) state.currentPage = 1;

            const start = (state.currentPage - 1) * state.itemsPerPage;
            const end = start + state.itemsPerPage;

            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');

            // Show only rows for current page
            visibleFilteredRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                }
            });

            updatePaginationControls(type, totalPages);
        }

        function updatePaginationControls(type, totalPages) {
            const state = paginationState[type];
            const positions = ['top', 'bottom'];
            
            positions.forEach(pos => {
                const containerId = `${type}-pagination-${pos}`;
                const headerContainerId = `${type}-pagination-header`;
                let container = document.getElementById(containerId);

                if (pos === 'top') {
                    const header = document.getElementById(headerContainerId);
                    if (!header) return;
                    
                    if (totalPages <= 1) {
                        header.innerHTML = '';
                        return;
                    }

                    let html = `<div id="${containerId}" class="flex items-center gap-2">`;
                    html += `<button type="button" onclick="goToPage('${type}', ${state.currentPage - 1})" class="btn btn-xs ${state.currentPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === 1 || i === totalPages || (i >= state.currentPage - 2 && i <= state.currentPage + 2)) {
                            html += `<button type="button" onclick="goToPage('${type}', ${i})" class="btn btn-xs ${i === state.currentPage ? 'btn-neutral' : ''}">${i}</button>`;
                        } else if (i === state.currentPage - 3 || i === state.currentPage + 3) {
                            html += `<span class="px-0.5 text-gray-400">...</span>`;
                        }
                    }
                    html += `<button type="button" onclick="goToPage('${type}', ${state.currentPage + 1})" class="btn btn-xs ${state.currentPage === totalPages ? 'btn-disabled' : ''}">»</button>`;
                    html += `</div>`;
                    header.innerHTML = html;
                } else {
                    // Bottom pagination
                    if (!container) {
                        container = document.createElement('div');
                        container.id = containerId;
                        container.className = `flex justify-center items-center gap-2 mt-6 pb-4`;
                        const tableWrap = document.querySelector(type === 'users' ? '#users-list' : 'table.w-full').parentNode;
                        tableWrap.parentNode.insertBefore(container, tableWrap.nextSibling);
                    }

                    if (totalPages <= 1) {
                        container.innerHTML = '';
                        return;
                    }

                    let html = `<button type="button" onclick="goToPage('${type}', ${state.currentPage - 1})" class="btn btn-sm ${state.currentPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === 1 || i === totalPages || (i >= state.currentPage - 2 && i <= state.currentPage + 2)) {
                            html += `<button type="button" onclick="goToPage('${type}', ${i})" class="btn btn-sm ${i === state.currentPage ? 'btn-neutral' : ''}">${i}</button>`;
                        } else if (i === state.currentPage - 3 || i === state.currentPage + 3) {
                            html += `<span class="px-1 text-gray-400">...</span>`;
                        }
                    }
                    html += `<button type="button" onclick="goToPage('${type}', ${state.currentPage + 1})" class="btn btn-sm ${state.currentPage === totalPages ? 'btn-disabled' : ''}">»</button>`;
                    container.innerHTML = html;
                }
            });
        }

        function goToPage(type, page) {
            paginationState[type].currentPage = page;
            applyPagination(type);
        }

        function toggleModalUserConstellations() {
            const typeSelect = document.getElementById('modal-type');
            const section = document.getElementById('modal-user-constellations-section');
            if (!typeSelect || !section) return;
            section.classList.toggle('hidden', typeSelect.value !== '1');
        }

        function editUser(user) {
            document.getElementById('modal-user-id').value = user.id;
            document.getElementById('modal-firstname').value = user.firstname;
            document.getElementById('modal-lastname').value = user.lastname;
            document.getElementById('modal-email').value = user.email;
            document.getElementById('modal-type').value = user.type;
            document.getElementById('modal-password').value = '';

            // Reset and set checkboxes
            const checkboxes = document.querySelectorAll('.modal-user-constellation-checkbox');
            const userAccess = userConstellationsMap[user.id] || [];
            checkboxes.forEach(cb => {
                cb.checked = userAccess.includes(parseInt(cb.value));
            });

            toggleModalUserConstellations();
            document.getElementById('modal-user-id-badge').textContent = '#' + user.id;
            document.getElementById('user_modal').showModal();
        }

        // editConstellation, loadTourConfigIntoModal, updateTourFieldVisibility
        // are loaded from js/galaxy-edit-modal.js (shared with /edit/index.php).

        function duplicateConstellation(c) {
            document.getElementById('duplicate-source-id').value = c.id;
            document.getElementById('duplicate-constellation-source-name').textContent = c.name;
            document.getElementById('duplicate-constellation-name').value = c.name + (ADM.nameCopySuffix || ' (Copy)');
            document.getElementById('duplicate-constellation-slug').value = (c.slug ? c.slug + '-copy' : '');
            document.getElementById('duplicate-constellation-tagline').value = c.tagline;
            document.getElementById('duplicate-constellation-id-badge').textContent = '#' + c.id;
            document.getElementById('duplicate_constellation_modal').showModal();
        }

        // ---------- Bulk users modal ----------
        function openBulkUsersModal() {
            document.getElementById('bulk_users_modal').showModal();
        }
        // Auto-reopen after preview/commit POSTs (the dialog dismisses on each request).
        <?php if (isset($bulkUsersPreview) || isset($bulkUsersResult)): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const m = document.getElementById('bulk_users_modal');
            if (m) m.showModal();
        });
        <?php endif; ?>

        // ---------- Galaxy Cluster modal (Idea 2) ----------
        function _clusterMemberCheckboxes() {
            return Array.from(document.querySelectorAll('#cluster_modal input[data-cluster-member]'));
        }
        function _refreshClusterMembersCount() {
            const checked = _clusterMemberCheckboxes().filter(cb => cb.checked).length;
            const out = document.getElementById('cluster-members-count');
            if (out) out.textContent = tFmtAdm(checked === 1 ? (ADM.countSelectedOne || '%d selected') : (ADM.countSelectedMany || '%d selected'), checked);
        }
        function _resetClusterDiscoveryFields() {
            // Discovery defaults — same shape as a brand-new tour_config row.
            const set = (id, val) => { const el = document.getElementById(id); if (el) { if (el.type === 'checkbox') el.checked = !!val; else el.value = val; } };
            set('cluster-keyword-chips-enabled', false);
            set('cluster-related-nodes-enabled', false);
            set('cluster-show-2d-view', false);
            set('cluster-idle-spotlight-enabled', false);
            set('cluster-idle-spotlight-idle-seconds', 30);
            set('cluster-tour-enabled', false);
            set('cluster-tour-idle-seconds', 30);
            set('cluster-tour-random-count', 10);
            set('cluster-tour-default-dwell', 8);
            set('cluster-tour-loop', true);
            set('cluster-tour-keyword-names', '');
            document.querySelectorAll('#cluster_modal input[name="tour_start_mode"]').forEach(r => r.checked = (r.value === 'manual'));
            document.querySelectorAll('#cluster_modal input[name="tour_node_selection"]').forEach(r => r.checked = (r.value === 'all'));
            document.querySelectorAll('#cluster_modal input[name="idle_spotlight_selection"]').forEach(r => r.checked = (r.value === 'all'));
            const warn = document.getElementById('cluster-tour-immediate-warning');
            if (warn) warn.dataset.hasAudio = '0';
            _updateClusterDiscoveryVisibility();
        }

        function _updateClusterDiscoveryVisibility() {
            const tourEnabled = document.getElementById('cluster-tour-enabled');
            if (tourEnabled) {
                const enabled = tourEnabled.checked;
                document.getElementById('cluster-tour-section').classList.toggle('hidden', !enabled);
                if (enabled) {
                    const startMode = document.querySelector('#cluster_modal input[name="tour_start_mode"]:checked')?.value || 'manual';
                    document.getElementById('cluster-tour-idle-row').classList.toggle('hidden', startMode !== 'idle');
                    const selection = document.querySelector('#cluster_modal input[name="tour_node_selection"]:checked')?.value || 'all';
                    document.getElementById('cluster-tour-random-row').classList.toggle('hidden', selection !== 'random_n');
                    document.getElementById('cluster-tour-tagged-row').classList.toggle('hidden', selection !== 'tagged');
                    const warn = document.getElementById('cluster-tour-immediate-warning');
                    const hasAudio = warn?.dataset.hasAudio === '1';
                    if (warn) warn.classList.toggle('hidden', !(hasAudio && startMode === 'immediate'));
                }
            }
            const idleEnabled = document.getElementById('cluster-idle-spotlight-enabled');
            if (idleEnabled) {
                document.getElementById('cluster-idle-spotlight-section').classList.toggle('hidden', !idleEnabled.checked);
            }
        }

        async function _loadClusterDiscoveryConfig(clusterId) {
            try {
                const r = await fetch(`../api/constellations.php?action=tour_config&id=${clusterId}`, {
                    headers: { 'X-API-Key': API_KEY }
                });
                if (!r.ok) return;
                const cfg = await r.json();
                const set = (id, val) => { const el = document.getElementById(id); if (el) { if (el.type === 'checkbox') el.checked = !!val; else el.value = val; } };
                set('cluster-keyword-chips-enabled', !!cfg.keyword_chips_enabled);
                set('cluster-related-nodes-enabled', !!cfg.related_nodes_enabled);
                set('cluster-show-2d-view', !!cfg.show_2d_view);
                set('cluster-idle-spotlight-enabled', !!cfg.idle_spotlight_enabled);
                set('cluster-idle-spotlight-idle-seconds', cfg.idle_spotlight_idle_seconds ?? 30);
                set('cluster-tour-enabled', !!cfg.tour_enabled);
                set('cluster-tour-idle-seconds', cfg.tour_idle_seconds ?? 30);
                set('cluster-tour-random-count', cfg.tour_random_count ?? 10);
                set('cluster-tour-default-dwell', cfg.tour_default_dwell ?? 8);
                set('cluster-tour-loop', !!cfg.tour_loop);
                set('cluster-tour-keyword-names', Array.isArray(cfg.tour_keyword_names) ? cfg.tour_keyword_names.join(', ') : '');
                document.querySelectorAll('#cluster_modal input[name="tour_start_mode"]').forEach(r => r.checked = (r.value === (cfg.tour_start_mode || 'manual')));
                document.querySelectorAll('#cluster_modal input[name="tour_node_selection"]').forEach(r => r.checked = (r.value === (cfg.tour_node_selection || 'all')));
                document.querySelectorAll('#cluster_modal input[name="idle_spotlight_selection"]').forEach(r => r.checked = (r.value === (cfg.idle_spotlight_selection || 'all')));
                const warn = document.getElementById('cluster-tour-immediate-warning');
                if (warn) warn.dataset.hasAudio = cfg.has_audio_nodes ? '1' : '0';
            } catch (e) { /* keep defaults on failure */ }
            _updateClusterDiscoveryVisibility();
        }

        function openClusterCreate() {
            document.getElementById('cluster-modal-title').textContent = ADM.clusterModalCreateTitle || 'Create Cluster';
            document.getElementById('cluster-modal-id-badge').textContent = '';
            document.getElementById('cluster-form-action').value = 'create_cluster';
            document.getElementById('cluster-form-id').value = '';
            document.getElementById('cluster-name').value = '';
            document.getElementById('cluster-slug').value = '';
            document.getElementById('cluster-tagline').value = '';
            document.getElementById('cluster-theme').value = 'cosmic';
            const sgl = document.getElementById('cluster-show-galaxy-list');
            if (sgl) sgl.checked = false;
            _clusterMemberCheckboxes().forEach(cb => { cb.checked = false; });
            _resetClusterDiscoveryFields();
            document.getElementById('cluster-submit-btn').textContent = ADM.clusterModalCreateSubmit || 'Create Cluster';
            _refreshClusterMembersCount();
            document.getElementById('cluster_modal').showModal();
        }
        async function openClusterEdit(cluster) {
            document.getElementById('cluster-modal-title').textContent = ADM.clusterModalEditTitle || 'Edit Cluster';
            document.getElementById('cluster-modal-id-badge').textContent = '#' + cluster.id;
            document.getElementById('cluster-form-action').value = 'update_cluster';
            document.getElementById('cluster-form-id').value = cluster.id;
            document.getElementById('cluster-name').value = cluster.name || '';
            document.getElementById('cluster-slug').value = cluster.slug || '';
            document.getElementById('cluster-tagline').value = cluster.tagline || '';
            document.getElementById('cluster-theme').value = cluster.theme || 'cosmic';
            const sgl = document.getElementById('cluster-show-galaxy-list');
            if (sgl) sgl.checked = !!cluster.show_galaxy_list;
            _clusterMemberCheckboxes().forEach(cb => { cb.checked = false; });
            _resetClusterDiscoveryFields();
            // Fetch members + discovery config in parallel.
            const membersP = fetch(`../api/constellations.php?action=cluster_members&id=${cluster.id}`, {
                headers: { 'X-API-Key': API_KEY }
            }).then(async r => {
                if (!r.ok) return;
                const data = await r.json();
                const ids = new Set((data.member_ids || []).map(Number));
                _clusterMemberCheckboxes().forEach(cb => {
                    if (ids.has(parseInt(cb.value, 10))) cb.checked = true;
                });
            }).catch(() => { /* leave members empty */ });
            const discoveryP = _loadClusterDiscoveryConfig(cluster.id);
            await Promise.all([membersP, discoveryP]);
            document.getElementById('cluster-submit-btn').textContent = ADM.clusterModalUpdateSubmit || 'Update Cluster';
            _refreshClusterMembersCount();
            document.getElementById('cluster_modal').showModal();
        }
        document.addEventListener('change', (ev) => {
            if (ev.target && ev.target.matches && ev.target.matches('input[data-cluster-member]')) {
                _refreshClusterMembersCount();
            }
        });
        // Live show/hide of discovery sub-sections inside cluster_modal.
        document.addEventListener('change', (ev) => {
            const t = ev.target;
            if (!t || !t.matches) return;
            if (t.matches('#cluster-tour-enabled, #cluster-idle-spotlight-enabled, .cluster-tour-start-mode, .cluster-tour-node-selection')) {
                _updateClusterDiscoveryVisibility();
            }
        });
        // "Preview tour" — opens the cluster's URL with ?tour=preview.
        document.addEventListener('click', (ev) => {
            if (!ev.target || !ev.target.closest) return;
            const btn = ev.target.closest('#cluster-tour-preview');
            if (!btn) return;
            const id = parseInt(document.getElementById('cluster-form-id')?.value, 10);
            if (!id || isNaN(id)) return;
            const slug = document.getElementById('cluster-slug')?.value?.trim();
            const path = slug ? ('../' + encodeURIComponent(slug)) : ('../index.php?constellation_id=' + id);
            const sep = path.includes('?') ? '&' : '?';
            window.open(path + sep + 'tour=preview', '_blank');
        });

        async function triggerDelete(action, id, message, confirmName = null) {
            document.getElementById('delete-action').value = action;
            document.getElementById('delete-id').value = id;
            document.getElementById('delete-confirm-message').innerHTML = message;
            
            const confirmWrap = document.getElementById('delete-name-confirm-wrap');
            const confirmInput = document.getElementById('delete-confirm-name-input');
            const deleteBtn = document.getElementById('delete-confirm-btn');
            const impactWrap = document.getElementById('delete-impact-wrap');
            
            // Reset impact wrap
            impactWrap.innerHTML = '';
            impactWrap.classList.add('hidden');

            if (confirmName) {
                confirmWrap.classList.remove('hidden');
                confirmInput.value = '';
                confirmInput.setAttribute('data-expected', confirmName);
                const confirmHint = document.getElementById('delete-confirm-name-hint');
                if (confirmHint) confirmHint.textContent = confirmName;
                deleteBtn.disabled = true;

                // Fetch impact for constellation deletion
                if (action === 'delete_constellation') {
                    try {
                        const response = await fetch(`../api/constellations.php?action=impact&id=${id}`, {
                            headers: { 'X-API-Key': API_KEY }
                        });
                        const data = await response.json();
                        if (data.referencing_portals && data.referencing_portals.length > 0) {
                            let html = `<div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded text-amber-800 text-xs">`;
                            html += `<p class="font-bold mb-2 uppercase tracking-wide">${escapeHtmlAdmin(ADM.deletionImpactTitle || '⚠️ Deletion Impact:')}</p>`;
                            html += `<p class="mb-2">${escapeHtmlAdmin(ADM.deletionImpactIntro || 'The following portals in other galaxies point to this network and will also be deleted:')}</p>`;
                            html += `<ul class="list-disc list-inside space-y-1">`;
                            const rowTpl = ADM.deletionImpactRow || '<strong>%s</strong> (in galaxy: %s)';
                            data.referencing_portals.forEach(p => {
                                const rendered = rowTpl
                                    .replace('%s', escapeHtmlAdmin(p.name))
                                    .replace('%s', escapeHtmlAdmin(p.constellation_name));
                                html += `<li>${rendered}</li>`;
                            });
                            html += `</ul></div>`;
                            impactWrap.innerHTML = html;
                            impactWrap.classList.remove('hidden');
                        }
                    } catch (e) {
                        console.error('Failed to fetch deletion impact', e);
                    }
                }
            } else {
                confirmWrap.classList.add('hidden');
                deleteBtn.disabled = false;
            }
            
            document.getElementById('delete_confirm_modal').showModal();
        }

        function checkDeleteConfirmName(input) {
            const expected = input.getAttribute('data-expected');
            const deleteBtn = document.getElementById('delete-confirm-btn');
            deleteBtn.disabled = (input.value !== expected);
        }

        function toggleCreateUserConstellations() {
            const typeSelect = document.getElementById('create-type');
            const section = document.getElementById('create-user-constellations-section');
            if (!typeSelect || !section) return;
            section.classList.toggle('hidden', typeSelect.value !== '1');
        }

        function toggleCreateNewConstellationName() {
            const cb = document.getElementById('create_constellation_cb');
            const wrap = document.getElementById('create-new-constellation-name-wrap');
            if (cb && wrap) wrap.classList.toggle('hidden', !cb.checked);
        }

        function initCreateUserModalLogic() {
            const emailEl = document.getElementById('create-email');
            const nameEl = document.getElementById('create_new_constellation_name');
            if (emailEl && nameEl) {
                emailEl.addEventListener('input', function() {
                    if (nameEl.value === '' || nameEl.getAttribute('data-auto') === '1') {
                        nameEl.value = emailEl.value;
                        nameEl.setAttribute('data-auto', '1');
                    }
                });
                nameEl.addEventListener('input', function() { nameEl.removeAttribute('data-auto'); });
            }
        }

        // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            document.getElementById('content-api-keys').classList.add('hidden');
            document.getElementById('content-users').classList.add('hidden');
            const contentConstellations = document.getElementById('content-constellations');
            if (contentConstellations) contentConstellations.classList.add('hidden');
            const contentClusters = document.getElementById('content-clusters');
            if (contentClusters) contentClusters.classList.add('hidden');
            const contentSettings = document.getElementById('content-settings');
            if (contentSettings) contentSettings.classList.add('hidden');
            const contentBackup = document.getElementById('content-backup');
            if (contentBackup) contentBackup.classList.add('hidden');
            const contentSnapshots = document.getElementById('content-snapshots');
            if (contentSnapshots) contentSnapshots.classList.add('hidden');
            const contentPluriverse = document.getElementById('content-pluriverse');
            if (contentPluriverse) contentPluriverse.classList.add('hidden');
            document.getElementById('content-php-info').classList.add('hidden');

            // Remove active styling from all tabs
            const tabs = ['api-keys', 'users', 'constellations', 'clusters', 'settings', 'backup', 'snapshots', 'pluriverse', 'php-info'];
            tabs.forEach(tab => {
                const tabElement = document.getElementById('tab-' + tab);
                if (tabElement) {
                    tabElement.classList.remove('tab-active');
                }
            });
            
            // Show selected tab
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // Lazy-load Snapshots tab on first open
            if (tabName === 'snapshots' && typeof snapshotsLoad === 'function') {
                snapshotsLoad();
            }

            // Lazy-load Clusters tab on first open
            if (tabName === 'clusters' && typeof loadClusters === 'function' && !_clustersLoadedOnce) {
                loadClusters();
            }
            
            // Add active styling to selected tab
            const activeTabEl = document.getElementById('tab-' + tabName);
            if (activeTabEl) {
                activeTabEl.classList.add('tab-active');
            }
            
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        // Format all date/time elements in the user's timezone (browser locale)
        function formatLocalDatetimes() {
            document.querySelectorAll('.local-datetime').forEach(function(span) {
                const iso = span.getAttribute('data-datetime-iso');
                if (iso) {
                    try {
                        const d = new Date(iso);
                        const yy = d.getFullYear().toString().slice(-2);
                        const mm = (d.getMonth() + 1).toString().padStart(2, '0');
                        const dd = d.getDate().toString().padStart(2, '0');
                        const hh = d.getHours().toString().padStart(2, '0');
                        const min = d.getMinutes().toString().padStart(2, '0');
                        span.textContent = `${yy}-${mm}-${dd} ${hh}:${min}`;
                    } catch (e) {}
                }
            });
        }

        // Initialize tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const tab = new URLSearchParams(window.location.search).get('tab') || 'constellations';
            showTab(tab);
            formatLocalDatetimes();
            initCreateUserModalLogic();
            toggleCreateUserConstellations();
            toggleCreateNewConstellationName();

            // Toastify any PHP-rendered messages
            document.querySelectorAll('#php-messages > div').forEach(msg => {
                showMessage(msg.innerHTML, msg.dataset.type, msg.dataset.title);
            });

            // Clean up the URL to prevent messages from reappearing on refresh or subsequent actions
            const url = new URL(window.location);
            let urlChanged = false;
            if (url.searchParams.has('saved')) { url.searchParams.delete('saved'); urlChanged = true; }
            if (url.searchParams.has('generated')) { url.searchParams.delete('generated'); urlChanged = true; }
            if (urlChanged) {
                window.history.replaceState({}, '', url);
            }

            // Initial pagination
            applyPagination('users');
            loadConstellations();
            // If the page landed directly on the clusters tab, prime that list too.
            // (showTab handles subsequent switches.)
            if (document.getElementById('tab-clusters')?.classList.contains('tab-active')) {
                loadClusters();
            }

            // Hide loading overlay
            const overlay = document.getElementById('admin-loading-overlay');
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(() => overlay.remove(), 300);
            }
        });

        function copyConstellationUrl(relativePath, buttonEl) {
            const absoluteUrl = new URL(relativePath, window.location.origin + window.location.pathname).href;
            navigator.clipboard.writeText(absoluteUrl).then(function() {
                const toast = document.getElementById('copy-url-toast');
                if (toast) {
                    toast.classList.remove('hidden');
                    setTimeout(function() { toast.classList.add('hidden'); }, 3000);
                }
                if (buttonEl) {
                    const origTitle = buttonEl.getAttribute('title');
                    buttonEl.setAttribute('title', 'Copied!');
                    setTimeout(function() { buttonEl.setAttribute('title', origTitle || 'Copy galaxy URL'); }, 1500);
                }
            });
        }

        function copyApiKey() {
            const input = document.getElementById('new-api-key');
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
            
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            button.classList.add('btn-success');
            button.classList.remove('btn-neutral');
            
            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('btn-success');
                button.classList.add('btn-neutral');
            }, 2000);
        }
        
        // User list sorting
        let currentUserSortColumn = null;
        let currentUserSortOrder = 'asc';
        
        function sortUsersByColumn(column) {
            if (currentUserSortColumn === column) {
                // Toggle order if clicking same column
                currentUserSortOrder = currentUserSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                // New column, default to ascending
                currentUserSortColumn = column;
                currentUserSortOrder = 'asc';
            }
            updateUserSortIndicators();
            applyUserSorting();
        }
        
        function updateUserSortIndicators() {
            // Reset all indicators
            ['name', 'email', 'type', 'date_created', 'date_last_login', 'updated_at'].forEach(col => {
                const indicator = document.getElementById('sort-indicator-' + col);
                if (indicator) {
                    indicator.innerHTML = '';
                }
            });
            
            // Set indicator for current sort column
            if (currentUserSortColumn) {
                const indicator = document.getElementById('sort-indicator-' + currentUserSortColumn);
                if (indicator) {
                    indicator.innerHTML = currentUserSortOrder === 'asc' ? ' ↑' : ' ↓';
                }
            }
        }
        
        function applyUserSorting() {
            const usersTable = document.getElementById('users-list');
            if (!usersTable) {
                return;
            }
            const tbody = usersTable.querySelector('tbody');
            if (!tbody) {
                return;
            }
            
            const userRows = Array.from(tbody.querySelectorAll('tr.user-row'));
            if (userRows.length === 0) {
                return;
            }
            
            // Sort user rows
            const sortedRows = userRows.sort((a, b) => {
                let aVal, bVal;
                
                switch(currentUserSortColumn) {
                    case 'name':
                        aVal = a.dataset.name || '';
                        bVal = b.dataset.name || '';
                        break;
                    case 'email':
                        aVal = a.dataset.email || '';
                        bVal = b.dataset.email || '';
                        break;
                    case 'type':
                        aVal = parseInt(a.dataset.type) || 0;
                        bVal = parseInt(b.dataset.type) || 0;
                        break;
                    case 'date_created':
                        aVal = parseInt(a.dataset.dateCreated) || 0;
                        bVal = parseInt(b.dataset.dateCreated) || 0;
                        break;
                    case 'date_last_login':
                        aVal = parseInt(a.dataset.dateLastLogin) || 0;
                        bVal = parseInt(b.dataset.dateLastLogin) || 0;
                        break;
                    case 'updated_at':
                        aVal = parseInt(a.dataset.updatedAt) || 0;
                        bVal = parseInt(b.dataset.updatedAt) || 0;
                        break;
                    default:
                        return 0;
                }
                
                if (aVal < bVal) return currentUserSortOrder === 'asc' ? -1 : 1;
                if (aVal > bVal) return currentUserSortOrder === 'asc' ? 1 : -1;
                return 0;
            });
            
            // Re-append sorted rows to tbody
            sortedRows.forEach(row => {
                tbody.appendChild(row);
            });

            applyPagination('users');
        }

        // --- Constellations: server-side pagination ---
        const CONST_API = '../api/constellations.php';
        let constPage = 1;
        const constPerPage = 20;
        let constSortColumn = null;
        let constSortOrder = 'asc';
        let constFilter = '';
        let constTotalPages = 0;

        // Exposed on window so bridge admin partials can read it without
        // depending on the lexical scope of this script block.
        window.constImportSources = {}; // id : import_source object
        const constImportSources = window.constImportSources;
        const pastelPalette = ['#FEF2F2','#F0FAF0','#EFF6FF','#FFF8F0','#F8F5FF','#F0FDFA','#FEFEF0','#FFF5F5','#F5F5F7','#F5FAE8'];
        const groupColorMap = {};
        let groupColorIdx = 0;

        function getGroupColor(name) {
            const m = name.match(/^\[([^\]]+)\]/);
            if (!m) return '';
            const g = m[1];
            if (!groupColorMap[g]) {
                groupColorMap[g] = pastelPalette[groupColorIdx % pastelPalette.length];
                groupColorIdx++;
            }
            return groupColorMap[g];
        }

        function escapeHtmlAdmin(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        const debouncedConstSearch = (() => {
            let timer;
            return () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    constFilter = document.getElementById('search-constellations').value.trim();
                    constPage = 1;
                    loadConstellations();
                }, 300);
            };
        })();

        function sortConstellationsByColumn(column) {
            if (constSortColumn === column) {
                constSortOrder = constSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                constSortColumn = column;
                constSortOrder = 'asc';
            }
            constPage = 1;
            updateConstellationSortIndicators();
            loadConstellations();
        }

        function updateConstellationSortIndicators() {
            ['id', 'name', 'slug', 'tagline', 'node_count', 'created_at', 'updated_at'].forEach(col => {
                const indicator = document.getElementById('sort-indicator-const-' + col);
                if (indicator) indicator.innerHTML = '';
            });
            if (constSortColumn) {
                const indicator = document.getElementById('sort-indicator-const-' + constSortColumn);
                if (indicator) indicator.innerHTML = constSortOrder === 'asc' ? ' ↑' : ' ↓';
            }
        }

        function constGoToPage(page) {
            if (page < 1 || page > constTotalPages) return;
            constPage = page;
            loadConstellations();
        }

        function updateConstPagination() {
            const headerContainer = document.getElementById('constellations-pagination-header');
            if (headerContainer) headerContainer.innerHTML = '';

            const oldBottom = document.getElementById('constellations-pagination-bottom');
            if (oldBottom) oldBottom.remove();

            if (constTotalPages <= 1) return;

            const createHTML = (isTop) => {
                let html = `<div id="constellations-pagination-${isTop ? 'top' : 'bottom'}" class="flex items-center gap-2 ${isTop ? '' : 'mt-6 pb-4 flex justify-center'}">`;
                html += `<button type="button" onclick="constGoToPage(${constPage - 1})" class="btn btn-xs ${constPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                for (let i = 1; i <= constTotalPages; i++) {
                    if (i === 1 || i === constTotalPages || (i >= constPage - 2 && i <= constPage + 2)) {
                        html += `<button type="button" onclick="constGoToPage(${i})" class="btn btn-xs ${i === constPage ? 'btn-neutral' : ''}">${i}</button>`;
                    } else if (i === constPage - 3 || i === constPage + 3) {
                        html += `<span class="px-0.5 text-gray-400">...</span>`;
                    }
                }
                html += `<button type="button" onclick="constGoToPage(${constPage + 1})" class="btn btn-xs ${constPage === constTotalPages ? 'btn-disabled' : ''}">»</button>`;
                html += `</div>`;
                return html;
            };

            if (headerContainer) headerContainer.innerHTML = createHTML(true);

            const container = document.getElementById('constellations-list-container');
            if (container) {
                const bottom = document.createElement('div');
                bottom.id = 'constellations-pagination-bottom';
                bottom.innerHTML = createHTML(false);
                container.appendChild(bottom);
            }
        }

        async function loadConstellations() {
            const container = document.getElementById('constellations-list-container');
            if (!container) return;

            const params = new URLSearchParams();
            params.set('page', constPage);
            params.set('per_page', constPerPage);
            if (constSortColumn) {
                params.set('sort', constSortColumn);
                params.set('order', constSortOrder);
            }
            if (constFilter) params.set('filter', constFilter);

            try {
                const response = await fetch(CONST_API + '?' + params.toString(), {
                    headers: { 'X-API-Key': API_KEY }
                });
                if (!response.ok) throw new Error('Failed to load constellations');
                const result = await response.json();

                const constellations = result.constellations;
                constellations.forEach(c => {
                    if (c.import_source) constImportSources[c.id] = c.import_source;
                });
                const total = result.total;
                constTotalPages = Math.ceil(total / constPerPage);

                if (constPage > constTotalPages && constTotalPages > 0) {
                    constPage = constTotalPages;
                    return loadConstellations();
                }

                const countEl = document.getElementById('constellations-count');
                if (countEl) countEl.textContent = total;

                if (constellations.length === 0) {
                    container.innerHTML = '<p class="text-gray-600 py-4">' + escapeHtmlAdmin(ADM.msgNoGalaxies || 'No galaxies found.') + '</p>';
                    updateConstPagination();
                    return;
                }

                let html = `<div class="border border-gray-300 rounded">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="border-b-2 border-gray-400 bg-gray-100">
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('id')">${escapeHtmlAdmin(ADM.colId || 'ID')}<span id="sort-indicator-const-id"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('name')">${escapeHtmlAdmin(ADM.colGalaxyName || 'Name')}<span id="sort-indicator-const-name"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('slug')">${escapeHtmlAdmin(ADM.colSlug || 'Slug')}<span id="sort-indicator-const-slug"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('tagline')">${escapeHtmlAdmin(ADM.colTagline || 'Tagline')}<span id="sort-indicator-const-tagline"></span></span>
                                </th>
                                <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('node_count')">${escapeHtmlAdmin(ADM.colWormholes || 'Wormholes')}<span id="sort-indicator-const-node_count"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('created_at')">${escapeHtmlAdmin(ADM.colCreated || 'Created')}<span id="sort-indicator-const-created_at"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortConstellationsByColumn('updated_at')">${escapeHtmlAdmin(ADM.colLastUpdated || 'Last Updated')}<span id="sort-indicator-const-updated_at"></span></span>
                                </th>
                                <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2">${escapeHtmlAdmin(ADM.colActions || 'Actions')}</th>
                            </tr>
                        </thead>
                        <tbody>`;

                constellations.forEach(c => {
                    const bgColor = getGroupColor(c.name);
                    const bgStyle = bgColor ? ` style="background-color: ${bgColor}"` : '';
                    const hoverClass = bgColor ? '' : ' hover:bg-gray-50';
                    const slug = c.slug || '';
                    const viewRel = c.is_default ? '../index.php' : (slug ? '../' + encodeURIComponent(slug) : '../index.php?constellation_id=' + c.id);
                    const cJson = JSON.stringify({ id: c.id, name: c.name, tagline: c.tagline, slug: slug, theme: c.theme });
                    const cJsonAttr = escapeHtmlAdmin(cJson);
                    const clickEdit = `editConstellation(${cJsonAttr})`;

                    const createdAt = c.created_at ? new Date(c.created_at) : null;
                    const updatedAt = c.updated_at ? new Date(c.updated_at) : null;
                    const fmtDate = (d) => d ? `${d.getFullYear().toString().slice(-2)}-${(d.getMonth()+1).toString().padStart(2,'0')}-${d.getDate().toString().padStart(2,'0')} ${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}` : '—';

                    const delMsg = JSON.stringify(tFmtAdm(ADM.confirmDeleteGalaxy || 'Are you sure you want to delete the galaxy "%s"? This will permanently remove ALL wormholes and keywords inside it.', c.name));
                    const cNameJson = JSON.stringify(c.name);

                    html += `<tr class="constellation-row border-b border-gray-300${hoverClass}"${bgStyle}>
                        <td class="py-2 px-2 font-mono text-gray-800 cursor-pointer whitespace-nowrap" onclick="${clickEdit}">${c.id}</td>
                        <td class="py-2 px-2 font-semibold text-gray-800 cursor-pointer" onclick="${clickEdit}">
                            ${escapeHtmlAdmin(c.name)}
                            ${c.is_default ? '<span class="ml-2 text-xs bg-green-400 text-white px-1.5 py-0.5 rounded">' + escapeHtmlAdmin(ADM.badgeDefault || 'Default') + '</span>' : ''}
                            ${c.import_source ? '<span class="ml-2 text-xs bg-purple-400 text-white px-1.5 py-0.5 rounded">' + escapeHtmlAdmin(ADM.badgeImported || 'Imported') + '</span>' : ''}
                            ${c.tour_enabled ? '<span class="ml-2 inline-flex items-center text-xs bg-blue-500 text-white px-1.5 py-0.5 rounded" title="' + escapeHtmlAdmin(ADM.titleTourEnabled || 'Auto-tour enabled') + '"><svg viewBox="0 0 24 24" width="10" height="10" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>' : ''}
                        </td>
                        <td class="py-2 px-2 font-mono text-xs text-blue-600 cursor-pointer" onclick="${clickEdit}">${escapeHtmlAdmin(slug)}</td>
                        <td class="py-2 px-2 text-gray-600 text-sm max-w-xs truncate cursor-pointer" onclick="${clickEdit}" title="${escapeHtmlAdmin(c.tagline)}">${escapeHtmlAdmin(c.tagline)}</td>
                        <td class="py-2 px-2 text-right whitespace-nowrap">
                            <a href="../edit/?${slug ? 'slug=' + encodeURIComponent(slug) : 'constellation_id=' + c.id}" class="text-blue-600 hover:text-blue-800 hover:underline text-sm font-medium">${c.node_count}</a>
                        </td>
                        <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="${clickEdit}">${fmtDate(createdAt)}</td>
                        <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="${clickEdit}">${fmtDate(updatedAt)}</td>
                        <td class="py-2 px-2 text-right">
                            <div class="flex justify-end">
                                <div class="dropdown dropdown-end">
                                    <label tabindex="0" onclick="event.stopPropagation(); closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                    </label>
                                    <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-44">
                                        <li><a onclick="event.stopPropagation(); editConstellation(${cJsonAttr})" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionEdit || 'Edit')}</a></li>
                                        <li><a href="${escapeHtmlAdmin(viewRel)}" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionView || 'View')}</a></li>
                                        <li><a onclick="event.stopPropagation(); copyConstellationUrl('${escapeHtmlAdmin(viewRel)}', this)" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionCopyUrl || 'Copy URL')}</a></li>
                                        <li><a href="../edit/keyword-canvas.php?galaxy_id=${c.id}" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionKeywordCanvas || 'Keyword canvas')}</a></li>
                                        <li><a onclick="event.stopPropagation(); duplicateConstellation(${cJsonAttr})" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionDuplicate || 'Duplicate')}</a></li>
                                        ${c.import_source ? `<li><a onclick="event.stopPropagation(); bridgeRefreshConstellation(${c.id}, ${escapeHtmlAdmin(cNameJson)})" class="text-purple-600 text-xs">${escapeHtmlAdmin(ADM.actionRefresh || 'Refresh')}</a></li>` : ''}
                                        ${!c.is_default ? `<li><a onclick="event.stopPropagation(); triggerDelete('delete_constellation', '${c.id}', ${escapeHtmlAdmin(delMsg)}, ${escapeHtmlAdmin(cNameJson)})" class="text-red-600 text-xs">${escapeHtmlAdmin(ADM.actionDelete || 'Delete')}</a></li>` : ''}
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>`;
                });

                html += `</tbody></table></div>`;
                container.innerHTML = html;

                updateConstPagination();
                updateConstellationSortIndicators();
                formatLocalDatetimes();
            } catch (e) {
                container.innerHTML = '<p class="text-red-600">' + escapeHtmlAdmin(tFmtAdm(ADM.msgErrorLoadingGalaxies || 'Error loading galaxies: %s', e.message)) + '</p>';
            }
        }

        // --- Clusters: server-side pagination (mirrors the galaxy list) ---
        let clusterPage = 1;
        const clusterPerPage = 20;
        let clusterSortColumn = null;
        let clusterSortOrder = 'asc';
        let clusterFilter = '';
        let clusterTotalPages = 0;
        let _clustersLoadedOnce = false;

        const debouncedClusterSearch = (() => {
            let timer;
            return () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    clusterFilter = document.getElementById('search-clusters').value.trim();
                    clusterPage = 1;
                    loadClusters();
                }, 300);
            };
        })();

        function sortClustersByColumn(column) {
            if (clusterSortColumn === column) {
                clusterSortOrder = clusterSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                clusterSortColumn = column;
                clusterSortOrder = 'asc';
            }
            clusterPage = 1;
            updateClusterSortIndicators();
            loadClusters();
        }

        function updateClusterSortIndicators() {
            ['id', 'name', 'slug', 'tagline', 'theme', 'member_count', 'created_at', 'updated_at'].forEach(col => {
                const indicator = document.getElementById('sort-indicator-cluster-' + col);
                if (indicator) indicator.innerHTML = '';
            });
            if (clusterSortColumn) {
                const indicator = document.getElementById('sort-indicator-cluster-' + clusterSortColumn);
                if (indicator) indicator.innerHTML = clusterSortOrder === 'asc' ? ' ↑' : ' ↓';
            }
        }

        function clusterGoToPage(page) {
            if (page < 1 || page > clusterTotalPages) return;
            clusterPage = page;
            loadClusters();
        }

        function updateClusterPagination() {
            const headerContainer = document.getElementById('clusters-pagination-header');
            if (headerContainer) headerContainer.innerHTML = '';

            const oldBottom = document.getElementById('clusters-pagination-bottom');
            if (oldBottom) oldBottom.remove();

            if (clusterTotalPages <= 1) return;

            const createHTML = (isTop) => {
                let html = `<div id="clusters-pagination-${isTop ? 'top' : 'bottom'}" class="flex items-center gap-2 ${isTop ? '' : 'mt-6 pb-4 flex justify-center'}">`;
                html += `<button type="button" onclick="clusterGoToPage(${clusterPage - 1})" class="btn btn-xs ${clusterPage === 1 ? 'btn-disabled' : ''}">«</button>`;
                for (let i = 1; i <= clusterTotalPages; i++) {
                    if (i === 1 || i === clusterTotalPages || (i >= clusterPage - 2 && i <= clusterPage + 2)) {
                        html += `<button type="button" onclick="clusterGoToPage(${i})" class="btn btn-xs ${i === clusterPage ? 'btn-neutral' : ''}">${i}</button>`;
                    } else if (i === clusterPage - 3 || i === clusterPage + 3) {
                        html += `<span class="px-0.5 text-gray-400">...</span>`;
                    }
                }
                html += `<button type="button" onclick="clusterGoToPage(${clusterPage + 1})" class="btn btn-xs ${clusterPage === clusterTotalPages ? 'btn-disabled' : ''}">»</button>`;
                html += `</div>`;
                return html;
            };

            if (headerContainer) headerContainer.innerHTML = createHTML(true);

            const container = document.getElementById('clusters-list-container');
            if (container) {
                const bottom = document.createElement('div');
                bottom.id = 'clusters-pagination-bottom';
                bottom.innerHTML = createHTML(false);
                container.appendChild(bottom);
            }
        }

        async function loadClusters() {
            _clustersLoadedOnce = true;
            const container = document.getElementById('clusters-list-container');
            if (!container) return;

            const params = new URLSearchParams();
            params.set('action', 'clusters_paginated');
            params.set('page', clusterPage);
            params.set('per_page', clusterPerPage);
            if (clusterSortColumn) {
                params.set('sort', clusterSortColumn);
                params.set('order', clusterSortOrder);
            }
            if (clusterFilter) params.set('filter', clusterFilter);

            try {
                const response = await fetch(CONST_API + '?' + params.toString(), {
                    headers: { 'X-API-Key': API_KEY }
                });
                if (!response.ok) throw new Error('Failed to load clusters');
                const result = await response.json();

                const clusters = result.clusters || [];
                const total = result.total || 0;
                clusterTotalPages = Math.ceil(total / clusterPerPage);

                if (clusterPage > clusterTotalPages && clusterTotalPages > 0) {
                    clusterPage = clusterTotalPages;
                    return loadClusters();
                }

                const countEl = document.getElementById('clusters-count');
                if (countEl) countEl.textContent = total;

                if (clusters.length === 0) {
                    container.innerHTML = clusterFilter
                        ? '<p class="text-gray-600 py-4">' + escapeHtmlAdmin(ADM.msgNoClustersSearch || 'No clusters match this search.') + '</p>'
                        : '<p class="text-sm text-gray-500 italic py-4">' + escapeHtmlAdmin(ADM.msgNoClusters || 'No clusters yet.') + '</p>';
                    updateClusterPagination();
                    return;
                }

                let html = `<div class="border border-gray-300 rounded">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="border-b-2 border-gray-400 bg-gray-100">
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('id')">${escapeHtmlAdmin(ADM.colId || 'ID')}<span id="sort-indicator-cluster-id"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('name')">${escapeHtmlAdmin(ADM.colGalaxyName || 'Name')}<span id="sort-indicator-cluster-name"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('slug')">${escapeHtmlAdmin(ADM.colSlug || 'Slug')}<span id="sort-indicator-cluster-slug"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('tagline')">${escapeHtmlAdmin(ADM.colTagline || 'Tagline')}<span id="sort-indicator-cluster-tagline"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('theme')">${escapeHtmlAdmin(ADM.colTheme || 'Theme')}<span id="sort-indicator-cluster-theme"></span></span>
                                </th>
                                <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('member_count')">${escapeHtmlAdmin(ADM.colMembers || 'Members')}<span id="sort-indicator-cluster-member_count"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('created_at')">${escapeHtmlAdmin(ADM.colCreated || 'Created')}<span id="sort-indicator-cluster-created_at"></span></span>
                                </th>
                                <th class="text-left text-xs font-semibold text-gray-700 py-2 px-2 whitespace-nowrap">
                                    <span class="cursor-pointer hover:bg-gray-200 px-2 py-1 rounded inline-block" onclick="sortClustersByColumn('updated_at')">${escapeHtmlAdmin(ADM.colLastUpdated || 'Last Updated')}<span id="sort-indicator-cluster-updated_at"></span></span>
                                </th>
                                <th class="text-right text-xs font-semibold text-gray-700 py-2 px-2">${escapeHtmlAdmin(ADM.colActions || 'Actions')}</th>
                            </tr>
                        </thead>
                        <tbody>`;

                clusters.forEach(cl => {
                    const bgColor = getGroupColor(cl.name);
                    const bgStyle = bgColor ? ` style="background-color: ${bgColor}"` : '';
                    const hoverClass = bgColor ? '' : ' hover:bg-gray-50';
                    const slug = cl.slug || '';
                    const viewRel = slug ? '../' + encodeURIComponent(slug) : '../index.php?constellation_id=' + cl.id;
                    const cJson = JSON.stringify({
                        id: cl.id, name: cl.name, tagline: cl.tagline, slug: slug, theme: cl.theme,
                        show_galaxy_list: !!cl.show_galaxy_list
                    });
                    const cJsonAttr = escapeHtmlAdmin(cJson);
                    const clickEdit = `openClusterEdit(${cJsonAttr})`;

                    const createdAt = cl.created_at ? new Date(cl.created_at) : null;
                    const updatedAt = cl.updated_at ? new Date(cl.updated_at) : null;
                    const fmtDate = (d) => d ? `${d.getFullYear().toString().slice(-2)}-${(d.getMonth()+1).toString().padStart(2,'0')}-${d.getDate().toString().padStart(2,'0')} ${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}` : '—';

                    const delMsg = JSON.stringify(tFmtAdm(ADM.confirmDeleteCluster || 'Delete cluster "%s"? Members (the galaxies inside) are unaffected; only the cluster itself is removed.', cl.name));
                    const cNameJson = JSON.stringify(cl.name);

                    const tourBadge = cl.tour_enabled
                        ? '<span class="ml-2 inline-flex items-center text-xs bg-blue-500 text-white px-1.5 py-0.5 rounded" title="' + escapeHtmlAdmin(ADM.titleTourEnabled || 'Auto-tour enabled') + '"><svg viewBox="0 0 24 24" width="10" height="10" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>'
                        : '';
                    const idleBadge = cl.idle_spotlight_enabled
                        ? '<span class="ml-2 inline-flex items-center text-xs bg-blue-400 text-white px-1.5 py-0.5 rounded" title="' + escapeHtmlAdmin(ADM.titleIdleSpotlight || 'Idle spotlight enabled') + '"><svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 5v2M12 17v2M5 12h2M17 12h2"/></svg></span>'
                        : '';
                    const galaxyListBadge = cl.show_galaxy_list
                        ? '<span class="ml-2 text-xs bg-gray-500 text-white px-1.5 py-0.5 rounded" title="' + escapeHtmlAdmin(ADM.titleGalaxyList || 'Galaxy list shown to visitors') + '">' + escapeHtmlAdmin(ADM.badgeGalaxyList || 'Galaxy list') + '</span>'
                        : '';

                    html += `<tr class="cluster-row border-b border-gray-300${hoverClass}"${bgStyle}>
                        <td class="py-2 px-2 font-mono text-gray-800 cursor-pointer whitespace-nowrap" onclick="${clickEdit}">${cl.id}</td>
                        <td class="py-2 px-2 font-semibold text-gray-800 cursor-pointer" onclick="${clickEdit}">
                            ${escapeHtmlAdmin(cl.name)}
                            ${tourBadge}
                            ${idleBadge}
                            ${galaxyListBadge}
                        </td>
                        <td class="py-2 px-2 font-mono text-xs"><a href="${escapeHtmlAdmin(viewRel)}" target="_blank" rel="noopener" class="text-blue-600 hover:underline">${escapeHtmlAdmin(slug)}</a></td>
                        <td class="py-2 px-2 text-gray-600 text-sm max-w-xs truncate cursor-pointer" onclick="${clickEdit}" title="${escapeHtmlAdmin(cl.tagline)}">${escapeHtmlAdmin(cl.tagline)}</td>
                        <td class="py-2 px-2 font-mono text-xs text-gray-700 cursor-pointer" onclick="${clickEdit}">${escapeHtmlAdmin(cl.theme)}</td>
                        <td class="py-2 px-2 text-right whitespace-nowrap cursor-pointer" onclick="${clickEdit}">${cl.member_count}</td>
                        <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="${clickEdit}">${fmtDate(createdAt)}</td>
                        <td class="py-2 px-2 text-xs text-gray-500 whitespace-nowrap cursor-pointer" onclick="${clickEdit}">${fmtDate(updatedAt)}</td>
                        <td class="py-2 px-2 text-right">
                            <div class="flex justify-end">
                                <div class="dropdown dropdown-end">
                                    <label tabindex="0" onclick="event.stopPropagation(); closeAllDropdowns(this)" class="btn btn-ghost btn-xs px-1.5">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="4" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="16" r="1.5"/></svg>
                                    </label>
                                    <ul tabindex="0" class="dropdown-content z-[50] menu menu-sm p-1 shadow-lg bg-white rounded-lg border border-gray-200 w-40">
                                        <li><a onclick="event.stopPropagation(); openClusterEdit(${cJsonAttr})" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionEdit || 'Edit')}</a></li>
                                        <li><a href="${escapeHtmlAdmin(viewRel)}" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionView || 'View')}</a></li>
                                        <li><a onclick="event.stopPropagation(); copyConstellationUrl('${escapeHtmlAdmin(viewRel)}', this)" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionCopyUrl || 'Copy URL')}</a></li>
                                        <li><a onclick="event.stopPropagation(); duplicateCluster(${cJsonAttr})" class="text-gray-700 text-xs">${escapeHtmlAdmin(ADM.actionDuplicate || 'Duplicate')}</a></li>
                                        <li><a onclick="event.stopPropagation(); triggerDelete('delete_cluster', '${cl.id}', ${escapeHtmlAdmin(delMsg)}, ${escapeHtmlAdmin(cNameJson)})" class="text-red-600 text-xs">${escapeHtmlAdmin(ADM.actionDelete || 'Delete')}</a></li>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>`;
                });

                html += `</tbody></table></div>`;
                container.innerHTML = html;

                updateClusterPagination();
                updateClusterSortIndicators();
                formatLocalDatetimes();
            } catch (e) {
                container.innerHTML = '<p class="text-red-600">' + escapeHtmlAdmin(tFmtAdm(ADM.msgErrorLoadingClusters || 'Error loading clusters: %s', e.message)) + '</p>';
            }
        }

        /**
         * Duplicate flow: open the cluster create modal pre-filled with the source
         * cluster's settings + a "(Copy)" name suffix. Slug is cleared so the server
         * generates a fresh one on save. Members + discovery config are fetched
         * fresh from the API.
         */
        async function duplicateCluster(cluster) {
            openClusterCreate();
            document.getElementById('cluster-modal-title').textContent = ADM.clusterModalDuplicateTitle || 'Duplicate Cluster';
            document.getElementById('cluster-name').value = (cluster.name || '') + (ADM.nameCopySuffix || ' (Copy)');
            document.getElementById('cluster-slug').value = '';
            document.getElementById('cluster-tagline').value = cluster.tagline || '';
            document.getElementById('cluster-theme').value = cluster.theme || 'cosmic';
            const sgl = document.getElementById('cluster-show-galaxy-list');
            if (sgl) sgl.checked = !!cluster.show_galaxy_list;
            // Member checkboxes + discovery config in parallel.
            try {
                const [mr, _] = await Promise.all([
                    fetch(`../api/constellations.php?action=cluster_members&id=${cluster.id}`, {
                        headers: { 'X-API-Key': API_KEY }
                    }),
                    _loadClusterDiscoveryConfig(cluster.id),
                ]);
                if (mr && mr.ok) {
                    const data = await mr.json();
                    const ids = new Set((data.member_ids || []).map(Number));
                    _clusterMemberCheckboxes().forEach(cb => {
                        if (ids.has(parseInt(cb.value, 10))) cb.checked = true;
                    });
                }
            } catch (e) { /* fall through with whatever loaded */ }
            _refreshClusterMembersCount();
        }

        function applyUserSearch() {
            paginationState.users.currentPage = 1;
            applyPagination('users');
        }

        // ====================================================================
        // Backup tab
        // ====================================================================

        let backupGalaxiesCache = null;
        let backupImportTempId = null;
        let backupImportSummary = null;

        async function backupLoadGalaxiesForExport() {
            if (backupGalaxiesCache) return backupGalaxiesCache;
            try {
                // Paginate through the constellations API (per_page is capped at 100 server-side)
                const all = [];
                let page = 1;
                while (true) {
                    const r = await fetch(CONST_API + '?page=' + page + '&per_page=100', { headers: { 'X-API-Key': API_KEY } });
                    if (!r.ok) throw new Error('Failed to load galaxies');
                    const data = await r.json();
                    const rows = data.constellations || [];
                    rows.forEach(c => all.push({
                        id: c.id, name: c.name, slug: c.slug, node_count: c.node_count || 0,
                    }));
                    const total = data.total || 0;
                    if (all.length >= total || rows.length === 0) break;
                    page++;
                    if (page > 200) break; // hard safety cap
                }
                backupGalaxiesCache = all;
                return backupGalaxiesCache;
            } catch (e) {
                showMessage(tFmtAdm(ADM.toastFailedLoadGalaxies || 'Failed to load galaxies: %s', escapeHtmlAdmin(e.message)), 'error');
                return [];
            }
        }

        function backupGetPrefix(name) {
            const m = (name || '').match(/^\s*\[([^\]]{1,16})\]/);
            return m ? m[1] : null;
        }

        function backupRenderPrefixChips(galaxies, container, onChipClick) {
            if (!container) return;
            const counts = new Map();
            let noPrefix = 0;
            galaxies.forEach(g => {
                const p = backupGetPrefix(g.name);
                if (p === null) noPrefix++;
                else counts.set(p, (counts.get(p) || 0) + 1);
            });
            const chips = [];
            Array.from(counts.entries()).sort((a, b) => a[0].localeCompare(b[0])).forEach(([p, n]) => {
                chips.push(`<button type="button" data-prefix="${escapeHtmlAdmin(p)}" class="px-2 py-1 text-xs bg-blue-100 hover:bg-blue-200 text-blue-800 rounded">[${escapeHtmlAdmin(p)}] (${n})</button>`);
            });
            if (noPrefix > 0) {
                chips.push(`<button type="button" data-prefix="" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded">${escapeHtmlAdmin(tFmtAdm(ADM.labelNoPrefixChip || 'No prefix (%d)', noPrefix))}</button>`);
            }
            container.innerHTML = chips.join('');
            container.querySelectorAll('button[data-prefix]').forEach(btn => {
                btn.addEventListener('click', () => onChipClick(btn.getAttribute('data-prefix')));
            });
        }

        async function backupRenderExportGalaxyList() {
            const galaxies = await backupLoadGalaxiesForExport();
            const list = document.getElementById('export-galaxy-list');
            if (!list) return;
            if (galaxies.length === 0) {
                list.innerHTML = '<p class="text-xs text-gray-500 p-3">' + escapeHtmlAdmin(ADM.msgNoGalaxies || 'No galaxies found.') + '</p>';
                return;
            }
            list.innerHTML = galaxies.map(g => `
                <label class="flex items-center gap-2 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="galaxy_ids[]" value="${g.id}" data-name="${escapeHtmlAdmin(g.name)}" class="checkbox checkbox-sm">
                    <span class="flex-1 text-sm">${escapeHtmlAdmin(g.name)}</span>
                    <span class="text-xs text-gray-500">${escapeHtmlAdmin(tFmtAdm(ADM.labelWormholeCount || '%d wormholes', g.node_count))}</span>
                </label>
            `).join('');
            backupRenderPrefixChips(galaxies, document.getElementById('export-prefix-chips'), (prefix) => {
                const matching = Array.from(list.querySelectorAll('input[type="checkbox"]')).filter(cb => {
                    const p = backupGetPrefix(cb.getAttribute('data-name') || '');
                    return prefix === '' ? p === null : p === prefix;
                });
                const allChecked = matching.length > 0 && matching.every(cb => cb.checked);
                matching.forEach(cb => { cb.checked = !allChecked; });
            });
        }

        function exportGalaxiesSelectAll(state) {
            document.querySelectorAll('#export-galaxy-list input[type="checkbox"]').forEach(cb => cb.checked = state);
        }

        document.addEventListener('change', (e) => {
            if (e.target.matches('input[name="galaxy_scope"]')) {
                const picker = document.getElementById('export-galaxy-picker');
                if (picker) picker.classList.toggle('hidden', e.target.value !== 'selected');
                if (e.target.value === 'selected') backupRenderExportGalaxyList();
            }
            if (e.target.id === 'export-include-galaxies') {
                document.getElementById('export-galaxies-options')?.classList.toggle('opacity-50', !e.target.checked);
                document.querySelectorAll('#export-galaxies-options input').forEach(el => el.disabled = !e.target.checked);
            }
        });

        function backupFormatBytes(b) {
            if (b > 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
            if (b > 1048576) return (b / 1048576).toFixed(1) + ' MB';
            if (b > 1024) return (b / 1024).toFixed(1) + ' KB';
            return b + ' B';
        }

        function backupOnFilePicked() {
            const fileEl = document.getElementById('backup-import-file');
            const info = document.getElementById('backup-import-file-info');
            if (!fileEl.files || fileEl.files.length === 0) {
                info.classList.add('hidden');
                return;
            }
            const f = fileEl.files[0];
            info.textContent = tFmtAdm(ADM.msgFileSelected || 'Selected: %s (%s)', f.name, backupFormatBytes(f.size));
            info.classList.remove('hidden');
            // Hide any previous results
            document.getElementById('backup-import-summary').classList.add('hidden');
            document.getElementById('backup-import-options').classList.add('hidden');
            document.getElementById('backup-import-result').classList.add('hidden');
            document.getElementById('backup-import-status').classList.add('hidden');
        }

        function backupSetStatus(text, opts) {
            opts = opts || {};
            const wrap = document.getElementById('backup-import-status');
            const txt = document.getElementById('backup-import-status-text');
            const progWrap = document.getElementById('backup-import-progress-wrap');
            const bar = document.getElementById('backup-import-progress-bar');
            wrap.classList.remove('hidden');
            txt.innerHTML = text;
            if (typeof opts.progress === 'number') {
                progWrap.classList.remove('hidden');
                bar.style.width = Math.max(0, Math.min(100, opts.progress)) + '%';
                bar.classList.remove('bg-red-500', 'bg-green-500');
                bar.classList.add(opts.progress >= 100 ? 'bg-blue-500' : 'bg-blue-500');
            } else {
                progWrap.classList.add('hidden');
            }
            if (opts.error) {
                txt.classList.add('text-red-700');
                txt.classList.remove('text-gray-700');
            } else {
                txt.classList.add('text-gray-700');
                txt.classList.remove('text-red-700');
            }
        }

        async function backupInspect() {
            const fileEl = document.getElementById('backup-import-file');
            if (!fileEl.files || fileEl.files.length === 0) {
                showMessage(ADM.toastChooseBackup || 'Choose a backup file first.', 'error');
                return;
            }
            const file = fileEl.files[0];
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('backup_file', file);
            const btn = document.getElementById('backup-import-inspect-btn');
            btn.disabled = true;
            btn.textContent = 'Inspecting...';
            backupSetStatus(`Preparing upload of <strong>${escapeHtmlAdmin(file.name)}</strong> (${backupFormatBytes(file.size)})...`, { progress: 0 });

            const t0 = Date.now();
            try {
                const data = await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'backup/import.php?phase=inspect');
                    let parseTimer = null;
                    let dots = 0;
                    xhr.upload.onprogress = (e) => {
                        if (!e.lengthComputable) return;
                        const pct = (e.loaded / e.total) * 100;
                        const speed = (e.loaded / Math.max(1, (Date.now() - t0) / 1000));
                        if (pct < 100) {
                            backupSetStatus(
                                `Uploading: ${pct.toFixed(0)}% &middot; ${backupFormatBytes(e.loaded)} / ${backupFormatBytes(e.total)} &middot; ${backupFormatBytes(speed)}/s`,
                                { progress: pct }
                            );
                        }
                    };
                    xhr.upload.onload = () => {
                        backupSetStatus(
                            `Upload complete (${backupFormatBytes(file.size)}). Server is now parsing the backup, decoding gzip, and validating media checksums. For large files this can take 10-30 seconds.`,
                            { progress: 100 }
                        );
                        // Animate dots so the user sees the page is alive
                        parseTimer = setInterval(() => {
                            dots = (dots + 1) % 4;
                            const el = document.getElementById('backup-import-status-text');
                            if (el && el.dataset.parsing === '1') {
                                el.querySelector('.parse-dots').textContent = '.'.repeat(dots);
                            }
                        }, 400);
                        const txt = document.getElementById('backup-import-status-text');
                        txt.dataset.parsing = '1';
                        txt.innerHTML += ' <span class="parse-dots text-blue-600 font-bold"></span>';
                    };
                    xhr.onload = () => {
                        if (parseTimer) clearInterval(parseTimer);
                        if (xhr.status >= 200 && xhr.status < 300) {
                            try { resolve(JSON.parse(xhr.responseText)); }
                            catch (e) { reject(new Error('Invalid JSON in server response')); }
                        } else {
                            try {
                                const err = JSON.parse(xhr.responseText);
                                reject(new Error(err.error || ('HTTP ' + xhr.status)));
                            } catch (e) {
                                reject(new Error('HTTP ' + xhr.status + ': ' + (xhr.responseText.slice(0, 200) || 'unknown error')));
                            }
                        }
                    };
                    xhr.onerror = () => { if (parseTimer) clearInterval(parseTimer); reject(new Error('Network error during upload (check file size limits)')); };
                    xhr.ontimeout = () => { if (parseTimer) clearInterval(parseTimer); reject(new Error('Request timed out')); };
                    xhr.send(fd);
                });
                if (!data.ok) throw new Error(data.error || 'Inspection failed');
                backupImportTempId = data.temp_id;
                backupImportSummary = data.summary;
                backupRenderImportSummary(data.summary);
                backupRenderImportGalaxyList(data.summary.galaxies || []);
                document.getElementById('backup-import-summary').classList.remove('hidden');
                document.getElementById('backup-import-options').classList.remove('hidden');
                document.getElementById('backup-import-result').classList.add('hidden');
                const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
                backupSetStatus(`Done in ${elapsed}s. Review the summary below, choose your options, then Restore.`, {});
            } catch (e) {
                backupSetStatus(tFmtAdm(ADM.toastFailedPrefix || 'Failed: %s', escapeHtmlAdmin(e.message)), { error: true });
                showMessage(tFmtAdm(ADM.toastInspectFailed || 'Inspect failed: %s', escapeHtmlAdmin(e.message)), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = ADM.btnInspectFile || 'Inspect file';
            }
        }

        function backupRenderImportSummary(s) {
            const el = document.getElementById('backup-import-summary');
            const mb = (s.media_bytes || 0) / 1048576;
            const adminWarn = !s.has_admin_user && s.user_count > 0 ? ' <span class="text-red-700 font-semibold">' + escapeHtmlAdmin(ADM.textNoAdminUserWarn || '(no admin user!)') + '</span>' : '';
            // textSummaryUsersMedia keeps two %s near the start so the (no admin user!) HTML can be injected unescaped between user_count and the media counts.
            const usersMediaParts = tFmtAdm(ADM.textSummaryUsersMedia || 'Users: %s%s · Media: %s files (%s MB)', 'USERCOUNT', 'ADMINWARN', 'MEDIACOUNT', 'MB');
            const usersMediaLine = escapeHtmlAdmin(usersMediaParts)
                .replace('USERCOUNT', String(s.user_count))
                .replace('ADMINWARN', adminWarn)
                .replace('MEDIACOUNT', String(s.media_blob_count))
                .replace('MB', mb.toFixed(1));
            el.innerHTML = `
                <div class="font-semibold mb-1">${escapeHtmlAdmin(ADM.labelBackupSummary || 'Backup file summary')}</div>
                <div>${escapeHtmlAdmin(tFmtAdm(ADM.textFormatAppCreated || 'Format v%s · App %s · Created %s', s.format_version, s.app_version, s.created_at))}</div>
                <div>${escapeHtmlAdmin(tFmtAdm(ADM.textSummaryCounts || 'Galaxies: %s · Wormholes: %s · Keywords: %s', s.galaxy_count, s.node_count, s.keyword_count))}</div>
                <div>${usersMediaLine}</div>
            `;
        }

        function backupRenderImportGalaxyList(galaxies) {
            const list = document.getElementById('import-galaxy-list');
            if (!list) return;
            if (galaxies.length === 0) {
                list.innerHTML = '<p class="text-xs text-gray-500 p-3">' + escapeHtmlAdmin(ADM.msgNoGalaxiesInBackup || 'No galaxies in this backup.') + '</p>';
                document.getElementById('import-prefix-chips').innerHTML = '';
                return;
            }
            list.innerHTML = galaxies.map(g => `
                <label class="flex items-center gap-2 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" data-ref="${escapeHtmlAdmin(g.ref)}" data-name="${escapeHtmlAdmin(g.name)}" checked class="checkbox checkbox-sm import-galaxy-cb">
                    <span class="flex-1 text-sm">${escapeHtmlAdmin(g.name)}${g.is_default ? ' <span class="text-xs text-purple-600">' + escapeHtmlAdmin(ADM.labelDefaultInline || '(default)') + '</span>' : ''}</span>
                    <span class="text-xs text-gray-500">${escapeHtmlAdmin(tFmtAdm(ADM.labelWormholeCount || '%d wormholes', g.node_count))}</span>
                </label>
            `).join('');
            backupRenderPrefixChips(galaxies, document.getElementById('import-prefix-chips'), (prefix) => {
                const matching = Array.from(list.querySelectorAll('input.import-galaxy-cb')).filter(cb => {
                    const p = backupGetPrefix(cb.getAttribute('data-name') || '');
                    return prefix === '' ? p === null : p === prefix;
                });
                const allChecked = matching.length > 0 && matching.every(cb => cb.checked);
                matching.forEach(cb => { cb.checked = !allChecked; });
            });
        }

        function importGalaxiesSelectAll(state) {
            document.querySelectorAll('#import-galaxy-list input.import-galaxy-cb').forEach(cb => cb.checked = state);
        }

        async function backupCommit() {
            if (!backupImportTempId) {
                showMessage(ADM.toastInspectFirst || 'Inspect a file first.', 'error');
                return;
            }
            const conflict = document.querySelector('input[name="import_conflict"]:checked')?.value || 'overwrite';
            const renameSuffix = document.getElementById('import-rename-suffix')?.value || ' (restored)';
            const restoreUsers = document.getElementById('import-restore-users')?.checked ?? true;
            const usersMode = document.querySelector('input[name="import_users_mode"]:checked')?.value || 'skip';
            const usersReplacePw = document.getElementById('import-users-replace-pw')?.checked ?? false;
            const restoreMedia = document.getElementById('import-restore-media')?.checked ?? true;

            const galaxiesOpts = {};
            let selectedCount = 0;
            document.querySelectorAll('#import-galaxy-list input.import-galaxy-cb').forEach(cb => {
                const ref = cb.getAttribute('data-ref');
                if (!ref) return;
                if (cb.checked) {
                    galaxiesOpts[ref] = { include: true, conflict, rename_suffix: renameSuffix };
                    selectedCount++;
                }
            });

            const userCount = (backupImportSummary?.user_count || 0);
            if (selectedCount === 0 && (!restoreUsers || userCount === 0)) {
                showMessage(ADM.toastNothingSelected || 'Nothing selected to restore.', 'error');
                return;
            }
            const scopePart = `${selectedCount} galaxy/galaxies` + (restoreUsers ? ` and up to ${userCount} user(s)` : '');
            const proceed = confirm(tFmtAdm(ADM.confirmRestore || "Restore %s into this system?\n\nConflict mode: %s\n\nThis cannot be undone.", scopePart, conflict.toUpperCase()));
            if (!proceed) return;

            const payload = {
                csrf_token: CSRF_TOKEN,
                temp_id: backupImportTempId,
                confirm: true,
                mode: 'granular',
                restore_users: restoreUsers,
                restore_media: restoreMedia,
                users_replace_existing: usersMode === 'replace',
                users_replace_password: usersMode === 'replace' && usersReplacePw,
                rename_suffix_default: renameSuffix,
                galaxies: galaxiesOpts,
            };
            try {
                const r = await fetch('backup/import.php?phase=commit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify(payload),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Restore failed');
                const rep = data.report;
                const failedHtml = (rep.galaxies_failed && rep.galaxies_failed.length)
                    ? '<div class="mt-2 text-red-700">' + escapeHtmlAdmin(ADM.labelFailures || 'Failures:') + '<ul class="list-disc ml-6">' + rep.galaxies_failed.map(f => `<li>${escapeHtmlAdmin(f.name || f.ref)}: ${escapeHtmlAdmin(f.error)}</li>`).join('') + '</ul></div>'
                    : '';
                const el = document.getElementById('backup-import-result');
                el.innerHTML = `
                    <div class="font-semibold mb-1">${escapeHtmlAdmin(ADM.headingRestoreComplete || 'Restore complete')}</div>
                    <div>${escapeHtmlAdmin(tFmtAdm(ADM.textGalaxiesReport || 'Galaxies: created %s, overwritten %s, renamed %s, skipped %s', rep.galaxies_created, rep.galaxies_overwritten, rep.galaxies_renamed, rep.galaxies_skipped))}</div>
                    <div>${escapeHtmlAdmin(tFmtAdm(ADM.textUsersReport || 'Users: created %s, updated %s, skipped %s', rep.users_created, rep.users_updated, rep.users_skipped))}</div>
                    <div>${escapeHtmlAdmin(tFmtAdm(ADM.textMediaReport || 'Media files: written %s, skipped %s', rep.media_files_written, rep.media_files_skipped))}</div>
                    ${failedHtml}
                `;
                el.classList.remove('hidden');
                backupImportTempId = null;
                showMessage(ADM.toastRestoreComplete || 'Restore complete.', 'success');
            } catch (e) {
                showMessage(tFmtAdm(ADM.toastRestoreFailed || 'Restore failed: %s', escapeHtmlAdmin(e.message)), 'error');
            }
        }

        // ====================================================================
        // Snapshots tab
        // ====================================================================

        async function snapshotsLoad() {
            const wrap = document.getElementById('snapshots-table-wrap');
            try {
                const r = await fetch('snapshots/list.php');
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Failed to load snapshots');
                snapshotsRenderScheduler(data.schedule, data.cron);
                snapshotsRenderTable(data.snapshots || []);
            } catch (e) {
                wrap.innerHTML = '<p class="text-red-600">' + escapeHtmlAdmin(e.message) + '</p>';
            }
        }

        function snapshotsRenderScheduler(s, c) {
            if (!s) return;
            document.getElementById('schedule-enabled').checked = !!s.enabled;
            document.getElementById('schedule-hour').value = (s.hour ?? 3);
            document.getElementById('schedule-keep-days').value = (s.keep_days ?? 7);

            const neverLower = ADM.labelNeverLower || 'never';
            document.getElementById('scheduler-last-run').textContent = s.last_run_at ? (s.last_run_at + ' UTC') : neverLower;
            document.getElementById('scheduler-last-check').textContent = (c && c.log_mtime) ? c.log_mtime : neverLower;

            const badge = document.getElementById('scheduler-status-badge');
            const detail = document.getElementById('scheduler-status-detail');
            let label, cls, msg = '';
            if (!s.enabled) {
                label = ADM.labelDisabled || 'Disabled';
                cls = 'bg-gray-200 text-gray-700';
            } else if (c && c.service_active && c.installed) {
                label = ADM.labelActive || 'Active';
                cls = 'bg-green-100 text-green-800';
            } else {
                label = ADM.labelNeedsAttention || 'Needs attention';
                cls = 'bg-amber-100 text-amber-800';
                if (c && !c.service_active) {
                    msg = tFmtAdm(ADM.msgCronInactive || "The system's cron service is not running (%s). Scheduled snapshots will not be taken until cron is started.", c.service_message || 'inactive');
                } else if (c && !c.installed) {
                    msg = ADM.msgCronNotInstalled || 'Unable to register the scheduler with cron. Try saving again.';
                } else {
                    msg = ADM.msgSchedulerUnknown || 'Scheduler status unknown.';
                }
            }
            badge.textContent = label;
            badge.className = 'ml-1 px-2 py-0.5 rounded text-xs ' + cls;
            if (msg) {
                detail.textContent = msg;
                detail.classList.remove('hidden');
            } else {
                detail.classList.add('hidden');
            }

            const logEl = document.getElementById('scheduler-log');
            const logTxt = (c && c.recent_log && c.recent_log.length) ? c.recent_log : '';
            logEl.textContent = logTxt || (ADM.msgNoActivity || '(no activity yet)');
        }

        function snapshotsRenderTable(rows) {
            const wrap = document.getElementById('snapshots-table-wrap');
            if (!rows.length) {
                wrap.innerHTML = '<p class="text-sm text-gray-500">' + escapeHtmlAdmin(ADM.msgNoSnapshots || 'No snapshots yet. Create one above.') + '</p>';
                return;
            }
            const fmtBytes = (b) => {
                if (b > 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
                if (b > 1048576) return (b / 1048576).toFixed(1) + ' MB';
                if (b > 1024) return (b / 1024).toFixed(1) + ' KB';
                return b + ' B';
            };
            let html = `<div class="border border-gray-300 rounded overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead><tr class="border-b-2 border-gray-400 bg-gray-100">
                        <th class="text-left p-2">${escapeHtmlAdmin(ADM.colSnapshotCreated || 'Created (UTC)')}</th>
                        <th class="text-left p-2">${escapeHtmlAdmin(ADM.colSize || 'Size')}</th>
                        <th class="text-left p-2">${escapeHtmlAdmin(ADM.colType || 'Type')}</th>
                        <th class="text-left p-2">${escapeHtmlAdmin(ADM.colCreator || 'Creator')}</th>
                        <th class="text-left p-2">${escapeHtmlAdmin(ADM.colNote || 'Note')}</th>
                        <th class="text-right p-2">${escapeHtmlAdmin(ADM.colActions || 'Actions')}</th>
                    </tr></thead><tbody>`;
            rows.forEach(r => {
                const missing = !r.file_exists ? ' <span class="text-red-700 text-xs">' + escapeHtmlAdmin(ADM.labelFileMissing || '(file missing)') + '</span>' : '';
                const creatorFallback = r.trigger_type === 'scheduled' ? (ADM.labelCreatorSystem || 'system') : '—';
                html += `<tr class="border-b border-gray-200 hover:bg-gray-50">
                    <td class="p-2 whitespace-nowrap">${escapeHtmlAdmin(r.created_at)}${missing}</td>
                    <td class="p-2 whitespace-nowrap">${fmtBytes(parseInt(r.size_bytes, 10) || 0)}</td>
                    <td class="p-2"><span class="text-xs px-2 py-0.5 rounded ${r.trigger_type === 'manual' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'}">${escapeHtmlAdmin(r.trigger_type)}</span></td>
                    <td class="p-2">${escapeHtmlAdmin(r.creator_email || creatorFallback)}</td>
                    <td class="p-2">${escapeHtmlAdmin(r.note || '')}</td>
                    <td class="p-2 text-right whitespace-nowrap">
                        <button type="button" onclick="snapshotRestoreClick(${r.id}, '${escapeHtmlAdmin(r.created_at)}')" class="text-orange-600 hover:underline text-xs mr-2">${escapeHtmlAdmin(ADM.actionRestore || 'Restore')}</button>
                        <a href="snapshots/download.php?id=${r.id}" class="text-blue-600 hover:underline text-xs mr-2">${escapeHtmlAdmin(ADM.actionDownload || 'Download')}</a>
                        <button type="button" onclick="snapshotDeleteClick(${r.id})" class="text-red-600 hover:underline text-xs">${escapeHtmlAdmin(ADM.actionDelete || 'Delete')}</button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            wrap.innerHTML = html;
        }

        async function snapshotCreate() {
            const note = document.getElementById('snapshot-note').value;
            const btn = document.getElementById('snapshot-create-btn');
            const progress = document.getElementById('snapshot-create-progress');
            const label = document.getElementById('snapshot-create-progress-label');
            const originalLabel = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading loading-spinner loading-xs"></span> ' + escapeHtmlAdmin(ADM.btnCreating || 'Creating...');
            progress.classList.remove('hidden');
            const t0 = Date.now();
            const tick = setInterval(() => {
                const s = Math.floor((Date.now() - t0) / 1000);
                label.textContent = tFmtAdm(ADM.msgCreatingElapsed || 'Creating snapshot. Elapsed: %ss. This may take a minute for large instances. Please do not close this tab.', s);
            }, 1000);
            try {
                const r = await fetch('snapshots/create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN, note }),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Create failed');
                showMessage(tFmtAdm(ADM.toastSnapshotCreated || 'Snapshot created in %ss.', Math.floor((Date.now() - t0) / 1000)), 'success');
                document.getElementById('snapshot-note').value = '';
                snapshotsLoad();
            } catch (e) {
                showMessage(tFmtAdm(ADM.toastCreateSnapshotFailed || 'Create snapshot failed: %s', escapeHtmlAdmin(e.message)), 'error');
            } finally {
                clearInterval(tick);
                btn.disabled = false;
                btn.innerHTML = originalLabel;
                progress.classList.add('hidden');
                label.textContent = ADM.msgCreatingSnapshot || 'Creating snapshot. This may take a minute for large instances. Please do not close this tab.';
            }
        }

        async function snapshotDeleteClick(id) {
            if (!confirm(ADM.confirmDeleteSnapshot || 'Delete this snapshot? The file will be permanently removed from disk.')) return;
            try {
                const r = await fetch('snapshots/delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN, id }),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Delete failed');
                showMessage(ADM.toastSnapshotDeleted || 'Snapshot deleted.', 'success');
                snapshotsLoad();
            } catch (e) {
                showMessage(tFmtAdm(ADM.toastDeleteFailed || 'Delete failed: %s', escapeHtmlAdmin(e.message)), 'error');
            }
        }

        async function snapshotRestoreClick(id, createdAt) {
            const phrase = prompt(tFmtAdm(ADM.promptRestoreSnapshot || "RESTORE will WIPE the entire system and replace it with the snapshot from %s.\n\nAll snapshots created after that point will also be deleted.\n\nType RESTORE to confirm:", createdAt));
            if (phrase !== 'RESTORE') {
                if (phrase !== null) showMessage(ADM.toastConfirmPhraseMismatch || 'Confirmation phrase did not match. Restore cancelled.', 'error');
                return;
            }
            let confirmNoAdmin = false;
            try {
                const r = await fetch('snapshots/restore.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN, id, confirm_text: 'RESTORE', confirm_no_admin: confirmNoAdmin }),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) {
                    if (data.error && data.error.indexOf('no admin user') !== -1) {
                        if (!confirm(ADM.confirmNoAdmin || 'WARNING: this snapshot has no admin user. Restoring will lock everyone out of the admin console. Proceed anyway?')) return;
                        // Retry with override
                        const r2 = await fetch('snapshots/restore.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                            body: JSON.stringify({ csrf_token: CSRF_TOKEN, id, confirm_text: 'RESTORE', confirm_no_admin: true }),
                        });
                        const data2 = await r2.json();
                        if (!r2.ok || !data2.ok) throw new Error(data2.error || 'Restore failed');
                        showMessage(ADM.toastRestoreCompleteLogout || 'Restore complete. You may be logged out.', 'success');
                        return;
                    }
                    throw new Error(data.error || 'Restore failed');
                }
                const rep = data.report;
                showMessage(tFmtAdm(ADM.toastRestoreCompleteReport || 'Restore complete. Created %s galaxies, %s users. %s later snapshot(s) deleted. You may be logged out.', rep.galaxies_created, rep.users_created, rep.snapshots_deleted_after_restore), 'success');
                snapshotsLoad();
            } catch (e) {
                showMessage(tFmtAdm(ADM.toastRestoreFailed || 'Restore failed: %s', escapeHtmlAdmin(e.message)), 'error');
            }
        }

        async function scheduleSave() {
            const payload = {
                csrf_token: CSRF_TOKEN,
                enabled: document.getElementById('schedule-enabled').checked,
                hour: document.getElementById('schedule-hour').value,
                keep_days: document.getElementById('schedule-keep-days').value,
            };
            try {
                const r = await fetch('snapshots/schedule.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify(payload),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) throw new Error(data.error || 'Save failed');
                if (data.warning) {
                    showMessage(tFmtAdm(ADM.toastSavedCronWarning || 'Saved, but scheduler could not register with cron: %s', escapeHtmlAdmin(data.warning)), 'error');
                } else {
                    showMessage(ADM.toastScheduleSaved || 'Schedule saved.', 'success');
                }
                snapshotsRenderScheduler(data.schedule, data.cron);
            } catch (e) {
                showMessage(tFmtAdm(ADM.toastSaveScheduleFailed || 'Save schedule failed: %s', escapeHtmlAdmin(e.message)), 'error');
            }
        }
    </script>

    <?php /* Per-bridge JS contributions. Each active bridge emits its own <script> block. */ ?>
    <?php bridges_admin_render('js'); ?>

    <!-- Bulk Users Import Modal -->
    <dialog id="bulk_users_modal" class="modal">
        <div class="modal-box bg-white !pt-0 max-w-3xl">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= htmlspecialchars(t('admin_modal_heading_bulk_users', 'Bulk import users')) ?></h3>
            </div>
            <div class="mt-4">
                <?php
                    $bulkUsersPreview = $bulkUsersPreview ?? null;
                    $bulkUsersInput = $bulkUsersInput ?? '';
                    $bulkUsersResult = $bulkUsersResult ?? null;
                    $bulkUsersDefaultCreateGalaxy = $bulkUsersDefaultCreateGalaxy ?? true;
                ?>

                <?php if ($bulkUsersResult): ?>
                    <p class="text-sm text-gray-700 mb-4"><?php
                        $createdN = (int)$bulkUsersResult['created'];
                        echo sprintf(
                            $createdN === 1
                                ? t('admin_modal_bulk_users_imported_one', 'Imported <strong>%d</strong> user.')
                                : t('admin_modal_bulk_users_imported_many', 'Imported <strong>%d</strong> users.'),
                            $createdN
                        );
                        $g = (int)($bulkUsersResult['galaxies_created'] ?? 0);
                        if ($g > 0) {
                            echo sprintf(
                                $g === 1
                                    ? t('admin_modal_bulk_users_galaxies_created_one', ' Created <strong>%d</strong> galaxy.')
                                    : t('admin_modal_bulk_users_galaxies_created_many', ' Created <strong>%d</strong> galaxies.'),
                                $g
                            );
                        }
                        $skE = (int)$bulkUsersResult['skipped_exists'];
                        if ($skE > 0) {
                            echo sprintf(
                                $skE === 1
                                    ? t('admin_modal_bulk_users_skipped_exists_one', ' Skipped <strong>%d</strong> already-existing email.')
                                    : t('admin_modal_bulk_users_skipped_exists_many', ' Skipped <strong>%d</strong> already-existing emails.'),
                                $skE
                            );
                        }
                        $skI = (int)$bulkUsersResult['skipped_invalid'];
                        if ($skI > 0) {
                            echo sprintf(
                                $skI === 1
                                    ? t('admin_modal_bulk_users_skipped_invalid_one', ' Skipped <strong>%d</strong> invalid row.')
                                    : t('admin_modal_bulk_users_skipped_invalid_many', ' Skipped <strong>%d</strong> invalid rows.'),
                                $skI
                            );
                        }
                        $mF = (int)$bulkUsersResult['mail_failed'];
                        if ($mF > 0) {
                            echo sprintf(
                                $mF === 1
                                    ? t('admin_modal_bulk_users_mail_failed_one', ' <strong>%d</strong> setup email failed to send.')
                                    : t('admin_modal_bulk_users_mail_failed_many', ' <strong>%d</strong> setup emails failed to send.'),
                                $mF
                            );
                        }
                    ?></p>
                    <div class="overflow-y-auto max-h-80 border border-gray-200 rounded">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 text-gray-600 uppercase tracking-wider">
                                <tr>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_line', 'Line')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_email', 'Email')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_outcome', 'Outcome')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_galaxy', 'Galaxy')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_note', 'Note')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bulkUsersResult['rows'] as $r): ?>
                                    <tr class="border-t border-gray-100">
                                        <td class="py-1 px-3 text-gray-500"><?php echo (int)$r['line']; ?></td>
                                        <td class="py-1 px-3 font-mono text-gray-800"><?php echo htmlspecialchars((string)$r['email']); ?></td>
                                        <td class="py-1 px-3">
                                            <?php
                                                $oc = (string)($r['outcome'] ?? '');
                                                $color = match($oc) {
                                                    'created' => 'text-emerald-700',
                                                    'created_mail_failed' => 'text-amber-700',
                                                    'skipped' => 'text-gray-500',
                                                    'create_failed' => 'text-red-700',
                                                    default => 'text-gray-500',
                                                };
                                            ?>
                                            <span class="<?php echo $color; ?>"><?php echo htmlspecialchars($oc); ?></span>
                                        </td>
                                        <td class="py-1 px-3 font-mono text-gray-700">
                                            <?php
                                                $gs = (string)($r['galaxy_slug'] ?? '');
                                                if ($gs !== '') {
                                                    echo '<a class="text-blue-700 hover:underline" target="_blank" href="/' . htmlspecialchars(rawurlencode($gs)) . '">' . htmlspecialchars($gs) . '</a>';
                                                } else {
                                                    echo '<span class="text-gray-400">—</span>';
                                                }
                                            ?>
                                        </td>
                                        <td class="py-1 px-3 text-gray-600"><?php echo htmlspecialchars((string)($r['note'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-action">
                        <button type="button" class="btn btn-neutral" onclick="document.getElementById('bulk_users_modal').close(); window.location.href='?tab=users';"><?= htmlspecialchars(t('admin_modal_btn_done', 'Done')) ?></button>
                    </div>

                <?php elseif ($bulkUsersPreview !== null): ?>
                    <p class="text-sm text-gray-700 mb-3"><?= t('admin_modal_bulk_users_preview_intro', 'Review the parsed list. Click <strong>Confirm import</strong> to create the new accounts and email each one a one-time setup link.') ?></p>
                    <div class="overflow-y-auto max-h-80 border border-gray-200 rounded">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 text-gray-600 uppercase tracking-wider">
                                <tr>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_line', 'Line')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_email', 'Email')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_name', 'Name')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_role', 'Role')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_galaxy', 'Galaxy')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_status', 'Status')) ?></th>
                                    <th class="text-left py-2 px-3"><?= htmlspecialchars(t('admin_modal_bulk_users_col_note', 'Note')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bulkUsersPreview as $r): ?>
                                    <tr class="border-t border-gray-100">
                                        <td class="py-1 px-3 text-gray-500"><?php echo (int)$r['line']; ?></td>
                                        <td class="py-1 px-3 font-mono text-gray-800"><?php echo htmlspecialchars((string)$r['email']); ?></td>
                                        <td class="py-1 px-3"><?php echo htmlspecialchars(trim($r['firstname'] . ' ' . $r['lastname'])); ?></td>
                                        <td class="py-1 px-3 font-mono text-gray-600"><?php echo htmlspecialchars((string)$r['role']); ?></td>
                                        <td class="py-1 px-3 font-mono text-gray-700">
                                            <?php
                                                $gp = (string)($r['galaxy_slug_preview'] ?? '');
                                                if ($gp === '') {
                                                    echo '<span class="text-gray-400">—</span>';
                                                } else {
                                                    echo htmlspecialchars($gp);
                                                    if (!empty($r['creates_galaxy_overridden'])) {
                                                        echo ' <span class="text-amber-700 not-italic">' . htmlspecialchars(t('admin_modal_bulk_users_row_override', '(row override)')) . '</span>';
                                                    }
                                                }
                                            ?>
                                        </td>
                                        <td class="py-1 px-3">
                                            <?php
                                                $color = match((string)$r['status']) {
                                                    'new' => 'text-emerald-700',
                                                    'exists' => 'text-gray-500',
                                                    'invalid' => 'text-red-700',
                                                    default => 'text-gray-500',
                                                };
                                            ?>
                                            <span class="<?php echo $color; ?>"><?php echo htmlspecialchars((string)$r['status']); ?></span>
                                        </td>
                                        <td class="py-1 px-3 text-gray-600"><?php echo htmlspecialchars((string)($r['note'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <form method="POST" action="" class="modal-action">
                        <input type="hidden" name="action" value="bulk_users_commit">
                        <input type="hidden" name="bulk_users_input" value="<?php echo htmlspecialchars($bulkUsersInput); ?>">
                        <?php if ($bulkUsersDefaultCreateGalaxy): ?>
                            <input type="hidden" name="default_create_galaxy" value="1">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-neutral"><?= htmlspecialchars(t('admin_modal_btn_confirm_import', 'Confirm import')) ?></button>
                        <button type="button" class="btn" onclick="document.getElementById('bulk_users_modal').close(); window.location.href='?tab=users';"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
                    </form>

                <?php else: ?>
                    <p class="text-sm text-gray-600 mb-3"><?= htmlspecialchars(t('admin_modal_bulk_users_form_intro', 'Paste a list of users, one per line, columns comma-separated. Only the email is required; everything else is optional.')) ?></p>
                    <ol class="text-xs text-gray-600 mb-3 list-decimal pl-5 space-y-1">
                        <li><?= t('admin_modal_bulk_users_field_email', '<strong>email</strong>: required') ?></li>
                        <li><?= t('admin_modal_bulk_users_field_first_name', '<strong>first name</strong>') ?></li>
                        <li><?= t('admin_modal_bulk_users_field_last_name', '<strong>last name</strong>') ?></li>
                        <li><?= t('admin_modal_bulk_users_field_type', '<strong>type</strong>: <code>Editor</code> (default) or <code>Admin</code>') ?></li>
                        <li><?= t('admin_modal_bulk_users_field_create_galaxy', '<strong>create galaxy?</strong>: <code>yes</code> / <code>no</code>. Empty inherits the checkbox below; a value here overrides it.') ?></li>
                    </ol>
                    <p class="text-xs text-gray-600 mb-1"><?= t('admin_modal_bulk_users_example_label', '<strong>Example:</strong>') ?></p>
                    <pre class="text-xs text-gray-700 bg-gray-50 border border-gray-200 rounded p-2 mb-3 font-mono">elena.fernandez@example.org, Elena, Fernández
m.silva@example.org
roberto.aguilar@example.org, Roberto, Aguilar, Admin, no</pre>
                    <p class="text-xs text-gray-500 mb-3"><?= t('admin_modal_bulk_users_footer_help', 'Each new user gets a welcome email with a one-time setup link (7-day TTL) to set their password. When a galaxy is created for them, the email also includes the galaxy URL and the login link. Existing emails are skipped; lines starting with <code>#</code> are ignored.') ?></p>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="bulk_users_preview">
                        <textarea name="bulk_users_input" rows="10" class="w-full p-3 border border-gray-300 rounded text-xs font-mono focus:outline-none focus:border-blue-500" placeholder="<?= t_attr('admin_modal_bulk_users_textarea_placeholder', 'email, firstname, lastname, type, create-galaxy') ?>"></textarea>
                        <label class="flex items-start gap-2 mt-3 text-sm text-gray-700 cursor-pointer">
                            <input type="checkbox" name="default_create_galaxy" value="1" class="rounded border-gray-300 mt-0.5" checked>
                            <span>
                                <span class="font-medium"><?= htmlspecialchars(t('admin_modal_bulk_users_label_create_galaxy_each', 'Create a galaxy for each new user')) ?></span>
                                <span class="block text-xs text-gray-500"><?= t('admin_modal_bulk_users_help_create_galaxy_each', 'Slug taken from the email name (before the <code>@</code>); collisions get a short random suffix. Editors are assigned to their own galaxy; admins see every galaxy already. Override per row in the 5th column.') ?></span>
                            </span>
                        </label>
                        <div class="modal-action">
                            <button type="submit" class="btn btn-neutral"><?= htmlspecialchars(t('admin_modal_btn_preview', 'Preview')) ?></button>
                            <button type="button" class="btn" onclick="document.getElementById('bulk_users_modal').close()"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Create User Modal -->
    <dialog id="create_user_modal" class="modal">
        <div class="modal-box max-w-2xl bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= htmlspecialchars(t('admin_modal_heading_create_user', 'Create New User')) ?></h3>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="create_user">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="create-firstname" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_first_name', 'First Name *')) ?></label>
                        <input type="text" id="create-firstname" name="firstname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_first_name', "The user's given name.")) ?></span>
                    </div>
                    <div>
                        <label for="create-lastname" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_last_name', 'Last Name *')) ?></label>
                        <input type="text" id="create-lastname" name="lastname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_last_name', "The user's family name.")) ?></span>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="create-email" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_email', 'Email *')) ?></label>
                    <input type="email" id="create-email" name="email" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-email-error" class="text-xs text-red-600 mt-1 hidden"><?= htmlspecialchars(t('admin_modal_err_email_in_use', 'This email is already in use.')) ?></span>
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_email', 'Login identifier and contact address.')) ?></span>
                </div>

                <div class="mb-4">
                    <label for="create-password" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_password', 'Password *')) ?></label>
                    <input type="password" id="create-password" name="password" required minlength="8" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_password_min', 'Minimum 8 characters.')) ?></span>
                </div>

                <div class="mb-4">
                    <label for="create-type" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_user_type', 'User Type *')) ?></label>
                    <select id="create-type" name="type" required onchange="toggleCreateUserConstellations()" class="select select-bordered select-sm w-full bg-white">
                        <option value="1"><?= htmlspecialchars(t('admin_modal_opt_user_type_editor', 'Editor')) ?></option>
                        <option value="2"><?= htmlspecialchars(t('admin_modal_opt_user_type_admin', 'Admin')) ?></option>
                    </select>
                    <span class="text-xs text-gray-500 mt-1 block">
                        <?= htmlspecialchars(t('admin_modal_help_user_type', 'Editor: Can edit wormholes in assigned galaxies only | Admin: Full access to all galaxies.')) ?>
                    </span>
                </div>

                <div class="mb-4 p-3 border border-gray-200 rounded bg-white">
                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                        <input type="checkbox" id="create_constellation_cb" name="create_constellation" value="1" class="rounded border-gray-300" checked onchange="toggleCreateNewConstellationName()">
                        <span class="text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_create_galaxy_for_user', 'Create a new galaxy for this user')) ?></span>
                    </label>
                    <p class="text-xs text-gray-500 mb-2"><?= htmlspecialchars(t('admin_modal_help_create_galaxy_for_user', 'A new galaxy is created with the name below and the user is granted access to it (Editors only).')) ?></p>
                    <div id="create-new-constellation-name-wrap">
                        <label for="create_new_constellation_name" class="block mb-1 text-gray-700 text-sm"><?= htmlspecialchars(t('admin_modal_label_new_galaxy_name', 'Galaxy name *')) ?></label>
                        <input type="text" id="create_new_constellation_name" name="new_constellation_name" placeholder="<?= t_attr('admin_modal_placeholder_new_galaxy_name', 'Defaults to email above') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                        <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_new_galaxy_name', 'Name for the automatically created galaxy.')) ?></span>
                    </div>
                </div>

                <div id="create-user-constellations-section" class="mb-4">
                    <label class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_galaxy_access_editors', 'Galaxy access (Editors only)')) ?></label>
                    <div class="border border-gray-200 rounded p-3 bg-white max-h-48 overflow-y-auto">
                        <?php
                        $prevGroup = false;
                        foreach ($constellations as $c):
                            $g = extractConstellationGroup($c['name']);
                            $bgColor = $g !== null ? ($constellationGroupColors[$g] ?? '') : '';
                            if ($g !== $prevGroup) {
                                if ($prevGroup !== false && $prevGroup !== null) echo '</div>';
                                if ($g !== null) echo '<div class="rounded mb-1 mt-1 px-1" style="background-color: ' . htmlspecialchars($constellationGroupColors[$g] ?? '') . '">';
                                $prevGroup = $g;
                            }
                        ?>
                            <label class="flex items-center gap-2 py-1 text-sm cursor-pointer hover:opacity-80 rounded px-2">
                                <input type="checkbox" name="constellation_ids[]" value="<?php echo (int)$c['id']; ?>" class="rounded border-gray-300">
                                <span class="font-mono text-gray-600"><?php echo (int)$c['id']; ?></span>
                                <span class="text-gray-800"><?php echo htmlspecialchars($c['name']); ?></span>
                            </label>
                        <?php endforeach;
                        if ($prevGroup !== false && $prevGroup !== null) echo '</div>';
                        ?>
                    </div>
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_galaxy_access_editors', 'Editors can only see and edit wormholes in the galaxies checked above. Admins see all galaxies.')) ?></span>
                </div>

                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral"><?= htmlspecialchars(t('admin_modal_btn_create_user', 'Create User')) ?></button>
                    <button type="button" class="btn" onclick="document.getElementById('create_user_modal').close()"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <?php bridges_admin_render("modal"); ?>

    <!-- Create Constellation Modal -->
    <dialog id="create_constellation_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= htmlspecialchars(t('admin_modal_heading_create_galaxy', 'Create New Galaxy')) ?></h3>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="create_constellation">

                <div class="mb-4">
                    <label for="create-constellation-name" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_galaxy_name', 'Name *')) ?></label>
                    <input type="text" id="create-constellation-name" name="name" required placeholder="<?= t_attr('admin_modal_placeholder_galaxy_name', 'e.g. Main network, Archive') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-constellation-name-error" class="text-xs text-red-600 mt-1 hidden"><?= htmlspecialchars(t('admin_modal_err_name_in_use', 'This name is already in use.')) ?></span>
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_galaxy_name', 'Unique name for the new wormhole network.')) ?></span>
                </div>

                <div class="mb-4">
                    <label for="create-constellation-slug" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_url_slug', 'URL Slug')) ?></label>
                    <input type="text" id="create-constellation-slug" name="slug" placeholder="<?= t_attr('admin_modal_placeholder_url_slug', 'e.g. archive') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="create-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden"><?= htmlspecialchars(t('admin_modal_err_slug_in_use', 'This slug is already in use.')) ?></span>
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_url_slug', 'Custom URL path. If left blank, one will be generated from the name. Letters, numbers, and hyphens only.')) ?></span>
                </div>

                <div class="mb-4">
                    <label for="create-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_tagline', 'Tagline')) ?></label>
                    <input type="text" id="create-constellation-tagline" name="tagline" placeholder="<?= t_attr('admin_modal_placeholder_tagline', 'e.g. Weaving memory') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_tagline', 'Shown in the main view when this galaxy is open.')) ?></span>
                </div>

                <div class="mb-4">
                    <label for="create-constellation-theme" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_visual_theme', 'Visual Theme')) ?></label>
                    <select id="create-constellation-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                        <option value="cosmic"><?= htmlspecialchars(t('admin_modal_opt_theme_cosmic', 'Cosmic (Stars, Planets, Rockets)')) ?></option>
                        <option value="simple"><?= htmlspecialchars(t('admin_modal_opt_theme_simple', 'Simple (Colored Spheres)')) ?></option>
                        <option value="abstract"><?= htmlspecialchars(t('admin_modal_opt_theme_abstract', 'Abstract (Geometric GIF Icons)')) ?></option>
                        <option value="rectangles"><?= htmlspecialchars(t('admin_modal_opt_theme_rectangles', 'Rectangles (Custom Rectangle Icons)')) ?></option>
                        <option value="stripes"><?= htmlspecialchars(t('admin_modal_opt_theme_stripes', 'Stripes (Custom Stripe Icons)')) ?></option>
                        <option value="tech"><?= htmlspecialchars(t('admin_modal_opt_theme_tech', 'Tech (Circuit Board Icons)')) ?></option>
                    </select>
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_visual_theme', 'Determines the background, icons and animations.')) ?></span>
                </div>

                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral"><?= htmlspecialchars(t('admin_modal_btn_create_galaxy', 'Create Galaxy')) ?></button>
                    <button type="button" class="btn" onclick="document.getElementById('create_constellation_modal').close()"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Cluster create/edit modal (Idea 2) -->
    <dialog id="cluster_modal" class="modal">
        <div class="modal-box bg-white !pt-0 max-w-3xl">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 id="cluster-modal-title" class="font-bold text-xl"><?= htmlspecialchars(t('admin_modal_heading_create_cluster', 'Create Cluster')) ?></h3>
                <span id="cluster-modal-id-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" id="cluster-form-action" value="create_cluster">
                <input type="hidden" name="id" id="cluster-form-id" value="">

                <div class="mb-4">
                    <label for="cluster-name" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_galaxy_name', 'Name *')) ?></label>
                    <input type="text" id="cluster-name" name="name" required placeholder="<?= t_attr('admin_modal_placeholder_cluster_name', 'e.g. Tracing the Earth') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label for="cluster-slug" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_url_slug', 'URL Slug')) ?></label>
                    <input type="text" id="cluster-slug" name="slug" placeholder="<?= t_attr('admin_modal_placeholder_cluster_slug', 'e.g. tracing-the-earth') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span class="text-xs text-gray-500 mt-1 block"><?= t('admin_modal_help_cluster_slug', 'Visitors land at <code>/&lt;slug&gt;</code>. If left blank, one is generated from the name.') ?></span>
                </div>

                <div class="mb-4">
                    <label for="cluster-tagline" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_tagline', 'Tagline')) ?></label>
                    <input type="text" id="cluster-tagline" name="tagline" placeholder="<?= t_attr('admin_modal_placeholder_cluster_tagline', 'e.g. A curated cluster') ?>" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label for="cluster-theme" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_visual_theme', 'Visual Theme')) ?></label>
                    <select id="cluster-theme" name="theme" class="select select-bordered select-sm w-full bg-white">
                        <option value="cosmic"><?= htmlspecialchars(t('admin_modal_opt_cluster_theme_cosmic', 'Cosmic')) ?></option>
                        <option value="abstract"><?= htmlspecialchars(t('admin_modal_opt_cluster_theme_abstract', 'Abstract')) ?></option>
                        <option value="rectangles"><?= htmlspecialchars(t('admin_modal_opt_cluster_theme_rectangles', 'Rectangles')) ?></option>
                        <option value="stripes"><?= htmlspecialchars(t('admin_modal_opt_cluster_theme_stripes', 'Stripes')) ?></option>
                        <option value="tech"><?= htmlspecialchars(t('admin_modal_opt_cluster_theme_tech', 'Tech')) ?></option>
                    </select>
                    <span class="text-xs text-gray-500 mt-1 block"><?= htmlspecialchars(t('admin_modal_help_cluster_theme', "Scene theme. Each wormhole's icon still uses its source galaxy's theme.")) ?></span>
                </div>

                <div class="mb-4 border-t border-gray-200 pt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cluster-show-galaxy-list" name="show_galaxy_list" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_show_galaxy_list', 'Show galaxy list to visitors')) ?></span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('admin_modal_help_show_galaxy_list', "When on, visitors see a list of the cluster's member galaxies in the bottom-right corner; clicking dims wormholes from other galaxies. Off by default for clusters since the curated framing is usually meant to read as one experience.")) ?></p>
                </div>

                <div class="mb-4">
                    <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_member_galaxies', 'Member galaxies *')) ?></label>
                    <p class="text-xs text-gray-500 mb-2"><?= htmlspecialchars(t('admin_modal_help_member_galaxies', "Visitors see the union of these galaxies' wormholes. Bridges (subtle dashed lines) connect wormholes sharing keyword text across galaxies.")) ?></p>
                    <div class="border border-gray-300 rounded p-2 max-h-64 overflow-y-auto bg-white">
                        <?php foreach ($constellations as $g): ?>
                            <label class="flex items-center gap-2 py-1 cursor-pointer hover:bg-gray-50 px-1 rounded">
                                <input type="checkbox" name="members[]" value="<?php echo (int)$g['id']; ?>" data-cluster-member="1" class="checkbox checkbox-neutral checkbox-xs">
                                <span class="text-sm text-gray-800"><?php echo htmlspecialchars($g['name']); ?></span>
                                <?php if (!empty($g['slug'])): ?>
                                    <span class="text-xs text-gray-400 font-mono">/<?php echo htmlspecialchars((string)$g['slug']); ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p id="cluster-members-count" class="text-xs text-gray-500 mt-1"><?= sprintf(htmlspecialchars(t('admin_modal_count_selected_many', '%d selected')), 0) ?></p>
                </div>

                <!-- Discovery features (cluster-scoped) -->
                <div class="mb-4 border-t border-gray-200 pt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cluster-keyword-chips-enabled" name="keyword_chips_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_keyword_chips', 'Keyword chips')) ?></span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('admin_modal_help_keyword_chips', 'Pool the most-used keywords across all visible wormholes (every member galaxy) into a filter chip strip at the top of the cluster. Click a chip to dim non-matching wormholes.')) ?></p>
                </div>

                <div class="mb-4 border-t border-gray-200 pt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cluster-related-nodes-enabled" name="related_nodes_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_related_wormholes', 'Related wormholes')) ?></span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('admin_modal_help_related_wormholes', "When a wormhole's info card is open, dim unrelated ones and surface up to 5 related wormholes (sharing keywords) as click-to-jump chips at the bottom of the card. Pools across the whole cluster; chips can surface wormholes from any member galaxy.")) ?></p>
                </div>

                <div class="mb-4 border-t border-gray-200 pt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cluster-show-2d-view" name="show_2d_view" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_2d_view', '2D view switch')) ?></span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('admin_modal_help_2d_view', "Show a top-center \"3D / 2D\" toggle so visitors can flip from the 3D scene to a flat grid of wormhole chips. Visitor's preference persists in their browser.")) ?></p>
                </div>

                <div class="mb-4 border-t border-gray-200 pt-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cluster-idle-spotlight-enabled" name="idle_spotlight_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                        <span class="text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_idle_spotlight', 'Idle spotlight')) ?></span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('admin_modal_help_idle_spotlight', 'When the visitor is idle, fly the camera to one random wormhole anywhere in the cluster and open its info card. Closes when media ends or after the dwell timer.')) ?></p>

                    <div id="cluster-idle-spotlight-section" class="mt-4 pl-6 border-l-2 border-gray-200 space-y-4 hidden">
                        <div>
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_pick_from', 'Pick from')) ?></label>
                            <div class="space-y-1">
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="idle_spotlight_selection" value="all" class="radio radio-neutral radio-sm cluster-idle-spotlight-selection">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_pick_all_wormholes', 'All wormholes (across every member galaxy)')) ?></span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="idle_spotlight_selection" value="accentuated" class="radio radio-neutral radio-sm cluster-idle-spotlight-selection">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_pick_accentuated', 'Only accentuated wormholes')) ?></span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label for="cluster-idle-spotlight-idle-seconds" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_trigger_after_seconds', 'Trigger after (seconds idle)')) ?></label>
                            <input type="number" id="cluster-idle-spotlight-idle-seconds" name="idle_spotlight_idle_seconds" min="1" value="30" class="input input-bordered input-sm w-32 bg-white">
                        </div>
                    </div>
                </div>

                <div class="mb-4 border-t border-gray-200 pt-4">
                    <div class="flex items-center justify-between gap-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="cluster-tour-enabled" name="tour_enabled" value="1" class="toggle toggle-neutral toggle-sm">
                            <span class="text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_auto_tour', 'Auto-tour')) ?></span>
                        </label>
                        <button type="button" id="cluster-tour-preview" class="btn btn-xs btn-outline" title="<?= t_attr('admin_modal_title_preview_tour', 'Save first, then preview the tour in a new tab') ?>"><?= htmlspecialchars(t('admin_modal_btn_preview_tour', 'Preview tour')) ?></button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('admin_modal_help_auto_tour', 'Automatically navigate visitors through wormholes across the cluster, opening each card and playing media. Desktop and iPad only.')) ?></p>

                    <div id="cluster-tour-section" class="mt-4 pl-6 border-l-2 border-gray-200 space-y-4 hidden">
                        <div>
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_start_mode', 'Start Mode')) ?></label>
                            <div class="space-y-1">
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_start_mode" value="manual" class="radio radio-neutral radio-sm cluster-tour-start-mode">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_start_manual', 'Manual. Visitor clicks a Play button to start.')) ?></span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_start_mode" value="idle" class="radio radio-neutral radio-sm cluster-tour-start-mode">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_start_idle', 'Idle. Start after visitor is inactive for a while.')) ?></span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_start_mode" value="immediate" class="radio radio-neutral radio-sm cluster-tour-start-mode">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_start_immediate', 'Immediate. Start a few seconds after the cluster loads.')) ?></span>
                                </label>
                            </div>
                        </div>

                        <div id="cluster-tour-idle-row" class="hidden">
                            <label for="cluster-tour-idle-seconds" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_idle_threshold', 'Idle threshold (seconds)')) ?></label>
                            <input type="number" id="cluster-tour-idle-seconds" name="tour_idle_seconds" min="1" value="30" class="input input-bordered input-sm w-32 bg-white">
                        </div>

                        <div id="cluster-tour-immediate-warning" class="hidden alert alert-warning text-sm py-2">
                            <span><?= htmlspecialchars(t('admin_modal_warn_immediate_audio', 'One or more member galaxies contain audio wormholes. Browsers block autoplay-with-sound until the visitor interacts with the page, so the first audio in an immediate-start tour may stay silent or stall.')) ?></span>
                        </div>

                        <div>
                            <label class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_which_wormholes', 'Which wormholes to tour')) ?></label>
                            <div class="space-y-1">
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="all" class="radio radio-neutral radio-sm cluster-tour-node-selection">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_tour_all', 'All wormholes (random order each run)')) ?></span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="accentuated" class="radio radio-neutral radio-sm cluster-tour-node-selection">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_tour_accentuated', 'Only accentuated wormholes')) ?></span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="random_n" class="radio radio-neutral radio-sm cluster-tour-node-selection">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_tour_random_n', 'A random sample of N wormholes')) ?></span>
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="radio" name="tour_node_selection" value="tagged" class="radio radio-neutral radio-sm cluster-tour-node-selection">
                                    <span><?= htmlspecialchars(t('admin_modal_opt_tour_tagged', 'Wormholes tagged with one of these keywords')) ?></span>
                                </label>
                            </div>
                        </div>

                        <div id="cluster-tour-random-row" class="hidden">
                            <label for="cluster-tour-random-count" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_random_count', 'How many wormholes per tour')) ?></label>
                            <input type="number" id="cluster-tour-random-count" name="tour_random_count" min="1" value="10" class="input input-bordered input-sm w-32 bg-white">
                        </div>

                        <div id="cluster-tour-tagged-row" class="hidden">
                            <label for="cluster-tour-keyword-names" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_tour_keywords', 'Keywords (any match, comma-separated)')) ?></label>
                            <input type="text" id="cluster-tour-keyword-names" name="tour_keyword_names" placeholder="<?= t_attr('admin_modal_placeholder_tour_keywords', 'e.g. Ideology, Resistance, Land') ?>" class="w-full p-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                            <span class="text-xs text-gray-500 mt-1 block"><?= t('admin_modal_help_tour_keywords', 'Matches by keyword name (case-insensitive) across every member galaxy. Useful when the same tag (e.g. <code>Ideology</code>) exists in several galaxies but with different keyword IDs.') ?></span>
                        </div>

                        <div>
                            <label for="cluster-tour-default-dwell" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_dwell_seconds', 'Pause on wormholes without media (seconds)')) ?></label>
                            <input type="number" id="cluster-tour-default-dwell" name="tour_default_dwell" min="1" value="8" class="input input-bordered input-sm w-32 bg-white">
                        </div>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="cluster-tour-loop" name="tour_loop" value="1" class="toggle toggle-neutral toggle-sm">
                            <span class="text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_loop_tour', 'Loop the tour when it finishes')) ?></span>
                        </label>
                    </div>
                </div>

                <div class="modal-action">
                    <button type="submit" id="cluster-submit-btn" class="btn btn-neutral"><?= htmlspecialchars(t('admin_modal_btn_create_cluster', 'Create Cluster')) ?></button>
                    <button type="button" class="btn" onclick="document.getElementById('cluster_modal').close()"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- User Edit Modal -->
    <dialog id="user_modal" class="modal">
        <div class="modal-box max-w-2xl bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl"><?= htmlspecialchars(t('admin_modal_heading_edit_user', 'Edit User')) ?></h3>
                <span id="modal-user-id-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" id="modal-user-id" name="id">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="modal-firstname" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_first_name', 'First Name *')) ?></label>
                        <input type="text" id="modal-firstname" name="firstname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label for="modal-lastname" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_last_name', 'Last Name *')) ?></label>
                        <input type="text" id="modal-lastname" name="lastname" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="modal-email" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_email', 'Email *')) ?></label>
                    <input type="email" id="modal-email" name="email" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="modal-email-error" class="text-xs text-red-600 mt-1 hidden"><?= htmlspecialchars(t('admin_modal_err_email_in_use', 'This email is already in use.')) ?></span>
                </div>

                <div class="mb-4">
                    <label for="modal-password" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_password_optional', 'Password (leave blank to keep current)')) ?></label>
                    <input type="password" id="modal-password" name="password" minlength="8" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div class="mb-4">
                    <label for="modal-type" class="block mb-1.5 text-gray-800 font-medium text-sm"><?= htmlspecialchars(t('admin_modal_label_user_type', 'User Type *')) ?></label>
                    <select id="modal-type" name="type" required onchange="toggleModalUserConstellations()" class="select select-bordered select-sm w-full bg-white">
                        <option value="1"><?= htmlspecialchars(t('admin_modal_opt_user_type_editor', 'Editor')) ?></option>
                        <option value="2"><?= htmlspecialchars(t('admin_modal_opt_user_type_admin', 'Admin')) ?></option>
                    </select>
                </div>

                <div id="modal-user-constellations-section" class="mb-4 hidden">
                    <label class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_galaxy_access_editors', 'Galaxy access (Editors only)')) ?></label>
                    <div class="border border-gray-200 rounded p-3 bg-white max-h-48 overflow-y-auto">
                        <?php
                        $prevGroup2 = false;
                        foreach ($constellations as $c):
                            $g2 = extractConstellationGroup($c['name']);
                            if ($g2 !== $prevGroup2) {
                                if ($prevGroup2 !== false && $prevGroup2 !== null) echo '</div>';
                                if ($g2 !== null) echo '<div class="rounded mb-1 mt-1 px-1" style="background-color: ' . htmlspecialchars($constellationGroupColors[$g2] ?? '') . '">';
                                $prevGroup2 = $g2;
                            }
                        ?>
                            <label class="flex items-center gap-2 py-1 text-sm cursor-pointer hover:opacity-80 rounded px-2">
                                <input type="checkbox" name="constellation_ids[]" value="<?php echo (int)$c['id']; ?>" class="modal-user-constellation-checkbox rounded border-gray-300">
                                <span class="font-mono text-gray-600"><?php echo (int)$c['id']; ?></span>
                                <span class="text-gray-800"><?php echo htmlspecialchars($c['name']); ?></span>
                            </label>
                        <?php endforeach;
                        if ($prevGroup2 !== false && $prevGroup2 !== null) echo '</div>';
                        ?>
                    </div>
                </div>
                
                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral"><?= htmlspecialchars(t('admin_modal_btn_update_user', 'Update User')) ?></button>
                    <button type="button" class="btn" onclick="document.getElementById('user_modal').close()"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Constellation Edit Modal -->
    <?php $isAdmin = true; require __DIR__ . '/../inc/partials/galaxy-edit-modal.php'; ?>

    <!-- Duplicate Constellation Modal -->
    <dialog id="duplicate_constellation_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-neutral text-neutral-content rounded-t-2xl flex items-center justify-between">
                <h3 class="font-bold text-xl"><?= htmlspecialchars(t('admin_modal_heading_duplicate_galaxy', 'Duplicate Galaxy')) ?></h3>
                <span id="duplicate-constellation-id-badge" class="text-xs opacity-70 font-mono"></span>
            </div>
            <p class="text-sm text-gray-600 mb-4 mt-4"><?= htmlspecialchars(t('admin_modal_label_duplicating', 'Duplicating:')) ?> <strong id="duplicate-constellation-source-name"></strong></p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="duplicate_constellation">
                <input type="hidden" id="duplicate-source-id" name="source_id">
                
                <div class="mb-4">
                    <label for="duplicate-constellation-name" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_new_name', 'New Name *')) ?></label>
                    <input type="text" id="duplicate-constellation-name" name="name" required class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="duplicate-constellation-name-error" class="text-xs text-red-600 mt-1 hidden"><?= htmlspecialchars(t('admin_modal_err_name_in_use', 'This name is already in use.')) ?></span>
                </div>

                <div class="mb-4">
                    <label for="duplicate-constellation-slug" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_new_url_slug', 'New URL Slug')) ?></label>
                    <input type="text" id="duplicate-constellation-slug" name="slug" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                    <span id="duplicate-constellation-slug-error" class="text-xs text-red-600 mt-1 hidden"><?= htmlspecialchars(t('admin_modal_err_slug_in_use', 'This slug is already in use.')) ?></span>
                </div>
                
                <div class="mb-4">
                    <label for="duplicate-constellation-tagline" class="block mb-1.5 text-gray-800 font-medium"><?= htmlspecialchars(t('admin_modal_label_new_tagline', 'New Tagline')) ?></label>
                    <input type="text" id="duplicate-constellation-tagline" name="tagline" class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div class="modal-action">
                    <button type="submit" class="btn btn-neutral"><?= htmlspecialchars(t('admin_modal_btn_duplicate', 'Duplicate')) ?></button>
                    <button type="button" class="btn" onclick="document.getElementById('duplicate_constellation_modal').close()"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Delete Confirmation Modal -->
    <dialog id="delete_confirm_modal" class="modal">
        <div class="modal-box bg-white !pt-0">
            <div class="-mx-6 px-6 py-4 bg-error text-error-content rounded-t-2xl">
                <h3 class="font-bold text-xl"><?= htmlspecialchars(t('admin_modal_heading_confirm_deletion', 'Confirm Deletion')) ?></h3>
            </div>
            <div id="delete-confirm-message" class="text-gray-600 mb-6 mt-4"></div>

            <div id="delete-impact-wrap" class="mb-6 hidden"></div>

            <div class="modal-action">
                <form id="delete-form" method="POST" action="">
                    <input type="hidden" name="action" id="delete-action" value="">
                    <input type="hidden" name="id" id="delete-id" value="">
                    <div id="delete-name-confirm-wrap" class="mb-6 hidden w-full">
                        <label for="delete-confirm-name-input" class="block mb-2 text-sm font-medium text-gray-700"><?= htmlspecialchars(t('admin_modal_label_type_to_confirm', 'To confirm, type the following exactly:')) ?></label>
                        <div id="delete-confirm-name-hint" class="mb-2 px-2 py-1 bg-gray-100 border border-gray-300 rounded text-sm font-mono text-gray-800 break-all"></div>
                        <input type="text"
                               id="delete-confirm-name-input"
                               name="confirm_name"
                               oninput="checkDeleteConfirmName(this)"
                               placeholder="<?= t_attr('admin_modal_placeholder_type_name', 'Type name here...') ?>"
                               class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-error">
                    </div>
                    <button type="submit" id="delete-confirm-btn" class="btn btn-error text-white"><?= htmlspecialchars(t('admin_modal_btn_delete', 'Delete')) ?></button>
                </form>
                <button type="button" class="btn" onclick="document.getElementById('delete_confirm_modal').close()"><?= htmlspecialchars(t('admin_btn_cancel', 'Cancel')) ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>
<script>
// Auto-inject CSRF token into all POST forms
document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(form => {
    if (!form.querySelector('input[name="csrf_token"]')) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = <?php echo json_encode($csrfToken); ?>;
        form.appendChild(input);
    }
});
</script>
<script>
    window.GALAXY_EDIT_API_URL = '../api/constellations.php';
    window.GALAXY_EDIT_API_KEY = <?php echo json_encode(getDefaultApiKey()); ?>;
    window.TELARIS_GXM = <?= json_encode([
        'statusLoadingKeywords' => t('editor_gxm_status_loading_keywords', 'Loading…'),
        'noKeywordsYet' => t('editor_gxm_no_keywords_yet', 'No keywords yet for this galaxy.'),
        'loadFailedKeywords' => t('editor_gxm_load_failed_keywords', 'Failed to load.'),
        'labelUseImagesAsIcons' => t('editor_gxm_label_use_images_as_icons', 'use images as icons'),
        'labelRevertToThemeIcons' => t('editor_gxm_label_revert_to_theme_icons', 'revert all to theme icons'),
        'confirmApplyToAll' => t('editor_gxm_confirm_apply_to_all', 'Apply "%s" to every wormhole in this galaxy?'),
        'statusWorking' => t('editor_gxm_status_working', 'Working…'),
        'statusUpdatedOne' => t('editor_gxm_status_updated_one', 'Updated %d wormhole. Reload the visitor view to see the change.'),
        'statusUpdatedMany' => t('editor_gxm_status_updated_many', 'Updated %d wormholes. Reload the visitor view to see the change.'),
        'labelFailedPrefix' => t('editor_gxm_label_failed_prefix', 'Failed: %s'),
        'errUpdateFailedFallback' => t('editor_gxm_err_update_failed_fallback', 'Update failed'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
</script>
<script src="../js/galaxy-edit-modal.js?v=<?php echo $appVersion; ?>"></script>
</body>
</html>
