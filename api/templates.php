<?php
declare(strict_types=1);

/*
 * api/templates.php
 * Editor-session API for wormhole templates (the Editor view's "Templates"
 * tab + the "Create Template" Actions item + the New Wormhole template
 * selector). A template captures a wormhole's content so a new wormhole can be
 * spun up pre-filled from it. Templates are private per editor (owner-scoped);
 * admins see all. Auth is the editor SESSION + per-session CSRF token, the same
 * seam api/hotglue-pages.php uses.
 *
 * Errors are {ok:false, error:'<code>'} with locale-invariant codes; the editor
 * JS maps codes to localized strings (decolonial identifiers).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

/** Emit a JSON envelope and stop. */
function tpl_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Recursively copy a hotglue content directory. */
function tpl_copytree(string $src, string $dst): void {
    if (!is_dir($src)) {
        return;
    }
    @mkdir($dst, 0775, true);
    $items = scandir($src);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s) && !is_link($s)) {
            tpl_copytree($s, $d);
        } else {
            @copy($s, $d);
        }
    }
}

/** Recursively remove a hotglue content directory. */
function tpl_rmtree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            tpl_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    tpl_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// --- Authentication: editor/admin session + CSRF -------------------------
if (!isEditorOrAdminLoggedIn()) {
    tpl_out(['ok' => false, 'error' => 'not_authenticated'], 401);
}
$expectedCsrf = $_SESSION['csrf_token'] ?? '';
$submittedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if ($expectedCsrf === '' || $submittedCsrf === '' || !hash_equals((string)$expectedCsrf, (string)$submittedCsrf)) {
    tpl_out(['ok' => false, 'error' => 'csrf_invalid'], 403);
}

$userId  = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
$isAdmin = isAdminLoggedIn();
$action  = (string)($_POST['action'] ?? '');

$CONTENT_ROOT = __DIR__ . '/../hg/content/';

/** Load a template row by posted id or deny. */
function tpl_require(): array {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        tpl_out(['ok' => false, 'error' => 'invalid'], 400);
    }
    $tpl = db_template_get_by_id($id);
    if ($tpl === null) {
        tpl_out(['ok' => false, 'error' => 'not_found'], 404);
    }
    return $tpl;
}

try {
    switch ($action) {

        case 'list': {
            $rows = db_templates_list_for_user($userId, $isAdmin);
            $out = array_map(static function (array $t) use ($userId): array {
                return [
                    'id'          => (int)$t['id'],
                    'name'        => (string)$t['name'],
                    'has_hotglue' => (bool)$t['has_hotglue'],
                    'is_owner'    => $userId !== null && (string)($t['owner_user_id'] ?? '') === (string)$userId,
                    'updated_at'  => (string)$t['updated_at'],
                    'data'        => is_array($t['data']) ? $t['data'] : [],
                ];
            }, $rows);
            tpl_out(['ok' => true, 'templates' => $out, 'is_admin' => $isAdmin]);
        }

        case 'create_from_node': {
            $nodeId = (int)($_POST['node_id'] ?? 0);
            if ($nodeId <= 0) {
                tpl_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            $node = db_get_node_by_id($nodeId);
            if ($node === null) {
                tpl_out(['ok' => false, 'error' => 'not_found'], 404);
            }
            // Only capture from a wormhole whose galaxy the actor can edit (or admin).
            $cid = isset($node['constellation_id']) ? (int)$node['constellation_id'] : 0;
            if (!$isAdmin && ($cid <= 0 || checkEditorConstellationAccess($cid) !== null)) {
                tpl_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }

            // Capture the content/identity of the wormhole. ponytail: omit
            // constellation_id, created_by, target_constellation_id (galaxy-
            // specific), hotglue_page slug, and animation (orbit is positional,
            // regenerated per new wormhole) — see Wormhole templates plan.
            $data = [
                'name'              => (string)($node['name'] ?? ''),
                'description'       => $node['description'] ?? null,
                'url'               => $node['url'] ?? null,
                'image_url'         => $node['image_url'] ?? null,
                'image_attribution' => $node['image_attribution'] ?? null,
                'icon_url'          => $node['icon_url'] ?? null,
                'embed_code'        => $node['embed_code'] ?? null,
                'audio_url'         => $node['audio_url'] ?? null,
                'audio_autoplay'    => (bool)($node['audio_autoplay'] ?? true),
                'audio_loop'        => (bool)($node['audio_loop'] ?? false),
                'video_url'         => $node['video_url'] ?? null,
                'video_autoplay'    => (bool)($node['video_autoplay'] ?? true),
                'pdf_url'           => $node['pdf_url'] ?? null,
                'node_type'         => (string)($node['node_type'] ?? 'object'),
                'is_accentuated'    => (bool)($node['is_accentuated'] ?? false),
                'show_keywords'     => (bool)($node['show_keywords'] ?? false),
                'use_image_as_node' => (bool)($node['use_image_as_node'] ?? false),
                'media_mode'        => ((string)($node['media_mode'] ?? 'classic') === 'hotglue') ? 'hotglue' : 'classic',
                'keywords'          => db_get_keywords_for_node($nodeId),
            ];

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $name = (string)($node['name'] ?? 'Untitled');
            }

            $isHotglue = $data['media_mode'] === 'hotglue';
            $tpl = db_template_create($name, $userId, $data, $isHotglue);

            // Snapshot the wormhole's hotglue content into a template-owned dir.
            if ($isHotglue) {
                $page = db_hotglue_page_get_or_create_for_node($nodeId, $userId);
                $srcSlug = $page ? (string)$page['slug'] : '';
                // Guard the source slug shape so a crafted value cannot escape root.
                if ($srcSlug !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $srcSlug) === 1) {
                    tpl_copytree($CONTENT_ROOT . $srcSlug, $CONTENT_ROOT . 'template-' . (int)$tpl['id']);
                }
            }

            tpl_out(['ok' => true, 'template' => [
                'id'          => (int)$tpl['id'],
                'name'        => (string)$tpl['name'],
                'has_hotglue' => (bool)$isHotglue,
            ]]);
        }

        case 'rename': {
            $tpl = tpl_require();
            if (!db_template_user_can_edit($tpl, $userId, $isAdmin)) {
                tpl_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                tpl_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            db_template_rename((int)$tpl['id'], $name);
            tpl_out(['ok' => true]);
        }

        case 'delete': {
            $tpl = tpl_require();
            if (!db_template_user_can_edit($tpl, $userId, $isAdmin)) {
                tpl_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            db_template_delete((int)$tpl['id']);
            tpl_rmtree($CONTENT_ROOT . 'template-' . (int)$tpl['id']);
            tpl_out(['ok' => true]);
        }

        case 'clone_hotglue': {
            // Used right after a wormhole is created from a hotglue template:
            // copy the template's snapshot into the new wormhole's hotglue page
            // and flip the wormhole to hotglue media.
            $templateId = (int)($_POST['template_id'] ?? 0);
            $nodeId     = (int)($_POST['node_id'] ?? 0);
            if ($templateId <= 0 || $nodeId <= 0) {
                tpl_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            $tpl = db_template_get_by_id($templateId);
            if ($tpl === null) {
                tpl_out(['ok' => false, 'error' => 'not_found'], 404);
            }
            // The template must be the actor's (or admin's) to clone from.
            if (!db_template_user_can_edit($tpl, $userId, $isAdmin)) {
                tpl_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            if (!$tpl['has_hotglue']) {
                tpl_out(['ok' => true, 'skipped' => true]); // nothing to clone
            }
            // The actor must control the new wormhole's galaxy with a write seat.
            $cid = db_get_node_constellation_id($nodeId);
            if ($cid === null) {
                tpl_out(['ok' => false, 'error' => 'node_not_found'], 404);
            }
            if (checkEditorConstellationAccess($cid) !== null) {
                tpl_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            if (!$isAdmin && !db_user_can_write_constellation($userId, (int)$cid)) {
                tpl_out(['ok' => false, 'error' => 'read_only'], 403);
            }
            $page = db_hotglue_page_get_or_create_for_node($nodeId, $userId);
            if (!$page) {
                tpl_out(['ok' => false, 'error' => 'server_error'], 500);
            }
            $dstSlug = (string)$page['slug'];
            if (preg_match('/^[A-Za-z0-9_-]+$/', $dstSlug) === 1) {
                tpl_copytree($CONTENT_ROOT . 'template-' . $templateId, $CONTENT_ROOT . $dstSlug);
            }
            // Flip the wormhole to hotglue media pointing at this page.
            try {
                db_hotglue_page_assign((int)$page['id'], $nodeId);
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'constellation_read_only') !== false) {
                    tpl_out(['ok' => false, 'error' => 'read_only'], 403);
                }
                tpl_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            tpl_out(['ok' => true]);
        }

        default:
            tpl_out(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (Throwable $e) {
    error_log('api/templates.php: ' . $e->getMessage());
    tpl_out(['ok' => false, 'error' => 'server_error'], 500);
}
