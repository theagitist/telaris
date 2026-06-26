<?php
declare(strict_types=1);

/*
 * api/hotglue-pages.php
 * Editor-session API for standalone hotglue pages (the Editor view's
 * "Hotglue content" tab). Unlike api/nodes.php (shared API-key auth), every
 * action here is scoped to the logged-in editor's identity, because a page's
 * edit rights derive from ownership: an editor owns the pages they create, an
 * admin sees all, and once a page is assigned to a wormhole the editors seated
 * on that wormhole's galaxy can edit it too. Authentication is therefore the
 * editor SESSION plus the per-session CSRF token (header X-CSRF-Token or a
 * csrf_token field), the same seam the editor's other AJAX writes use.
 *
 * Errors are returned as {ok:false, error:'<code>'} with locale-invariant
 * codes; the editor JS maps codes to localized strings (decolonial identifiers:
 * no English message is the authoritative surface).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

/** Emit a JSON envelope and stop. */
function hgp_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Recursively copy a hotglue page's on-disk content directory. */
function hgp_copytree(string $src, string $dst): void {
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
            hgp_copytree($s, $d);
        } else {
            @copy($s, $d);
        }
    }
}

/** Recursively remove a hotglue page's on-disk content directory. */
function hgp_rmtree(string $dir): void {
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
            hgp_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    hgp_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// --- Authentication: editor/admin session + CSRF -------------------------
if (!isEditorOrAdminLoggedIn()) {
    hgp_out(['ok' => false, 'error' => 'not_authenticated'], 401);
}
$expectedCsrf = $_SESSION['csrf_token'] ?? '';
$submittedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if ($expectedCsrf === '' || $submittedCsrf === '' || !hash_equals((string)$expectedCsrf, (string)$submittedCsrf)) {
    hgp_out(['ok' => false, 'error' => 'csrf_invalid'], 403);
}

$userId  = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
$isAdmin = isAdminLoggedIn();
$action  = (string)($_POST['action'] ?? '');

/** Load a page row by posted id or deny. */
function hgp_require_page(): array {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        hgp_out(['ok' => false, 'error' => 'invalid'], 400);
    }
    $page = db_hotglue_page_get_by_id($id);
    if ($page === null) {
        hgp_out(['ok' => false, 'error' => 'not_found'], 404);
    }
    return $page;
}

try {
    switch ($action) {

        case 'list': {
            $pages = db_hotglue_pages_list_for_user($userId, $isAdmin);
            $out = array_map(static function (array $p): array {
                return [
                    'id'           => (int)$p['id'],
                    'slug'         => (string)$p['slug'],
                    'title'        => (string)$p['title'],
                    'node_id'      => $p['node_id'] !== null ? (int)$p['node_id'] : null,
                    'node_name'    => $p['node_name'] !== null ? (string)$p['node_name'] : null,
                    'galaxy_id'    => $p['node_constellation_id'] !== null ? (int)$p['node_constellation_id'] : null,
                    'galaxy_name'  => $p['galaxy_name'] !== null ? (string)$p['galaxy_name'] : null,
                    'galaxy_slug'  => $p['galaxy_slug'] !== null ? (string)$p['galaxy_slug'] : null,
                    'is_owner'     => $userId !== null && (string)($p['owner_user_id'] ?? '') === (string)$userId,
                    'updated_at'   => (string)$p['updated_at'],
                ];
            }, $pages);
            hgp_out(['ok' => true, 'pages' => $out, 'is_admin' => $isAdmin]);
        }

        case 'wormholes': {
            $rows = db_hotglue_assignable_wormholes($userId, $isAdmin);
            $out = array_map(static function (array $r): array {
                return [
                    'id'          => (int)$r['id'],
                    'name'        => (string)$r['name'],
                    'galaxy_id'   => (int)$r['galaxy_id'],
                    'galaxy_name' => $r['galaxy_name'] !== null ? (string)$r['galaxy_name'] : '',
                    'media_mode'  => (string)($r['media_mode'] ?? 'classic'),
                ];
            }, $rows);
            hgp_out(['ok' => true, 'wormholes' => $out]);
        }

        case 'create': {
            $title = trim((string)($_POST['title'] ?? ''));
            if (mb_strlen($title) > 255) {
                $title = mb_substr($title, 0, 255);
            }
            $page = db_hotglue_page_create($title, $userId);
            hgp_out(['ok' => true, 'page' => [
                'id'    => (int)$page['id'],
                'slug'  => (string)$page['slug'],
                'title' => (string)$page['title'],
            ]]);
        }

        case 'rename': {
            $page = hgp_require_page();
            if (!db_hotglue_page_user_can_edit($page, $userId, $isAdmin)) {
                hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            $title = trim((string)($_POST['title'] ?? ''));
            if (mb_strlen($title) > 255) {
                $title = mb_substr($title, 0, 255);
            }
            db_hotglue_page_rename((int)$page['id'], $title);
            hgp_out(['ok' => true]);
        }

        case 'assign': {
            $page = hgp_require_page();
            if (!db_hotglue_page_user_can_edit($page, $userId, $isAdmin)) {
                hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            $nodeId = (int)($_POST['node_id'] ?? 0);
            if ($nodeId <= 0) {
                hgp_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            $cid = db_get_node_constellation_id($nodeId);
            if ($cid === null) {
                hgp_out(['ok' => false, 'error' => 'node_not_found'], 404);
            }
            // The actor must control the target wormhole's galaxy.
            if (checkEditorConstellationAccess($cid) !== null) {
                hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            // ...and must have a read_write (not read_only) seat on it.
            if (!$isAdmin && !db_user_can_write_constellation($userId, (int)$cid)) {
                hgp_out(['ok' => false, 'error' => 'read_only'], 403);
            }
            try {
                $displaced = db_hotglue_page_assign((int)$page['id'], $nodeId);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'constellation_read_only') !== false) {
                    hgp_out(['ok' => false, 'error' => 'read_only'], 403);
                }
                hgp_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            hgp_out(['ok' => true, 'displaced' => $displaced]);
        }

        case 'unassign': {
            $page = hgp_require_page();
            if (!db_hotglue_page_user_can_edit($page, $userId, $isAdmin)) {
                hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            // If assigned, the actor must control the assigned wormhole's galaxy
            // (it flips that node back to classic), unless they are an admin.
            if ($page['node_id'] !== null && !$isAdmin) {
                $cid = db_get_node_constellation_id((int)$page['node_id']);
                if ($cid !== null && checkEditorConstellationAccess($cid) !== null) {
                    hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
                }
                if ($cid !== null && !db_user_can_write_constellation($userId, (int)$cid)) {
                    hgp_out(['ok' => false, 'error' => 'read_only'], 403);
                }
            }
            try {
                db_hotglue_page_unassign((int)$page['id']);
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'constellation_read_only') !== false) {
                    hgp_out(['ok' => false, 'error' => 'read_only'], 403);
                }
                hgp_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            hgp_out(['ok' => true]);
        }

        case 'resolve_for_node': {
            $nodeId = (int)($_POST['node_id'] ?? 0);
            if ($nodeId <= 0) {
                hgp_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            $cid = db_get_node_constellation_id($nodeId);
            if ($cid === null) {
                hgp_out(['ok' => false, 'error' => 'node_not_found'], 404);
            }
            // The actor must control the wormhole's galaxy (or be admin).
            if (checkEditorConstellationAccess($cid) !== null) {
                hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            // ...and must have a read_write (not read_only) seat on it: this can
            // create a hotglue page, which is an edit of the wormhole's media.
            if (!$isAdmin && !db_user_can_write_constellation($userId, (int)$cid)) {
                hgp_out(['ok' => false, 'error' => 'read_only'], 403);
            }
            $page = db_hotglue_page_get_or_create_for_node($nodeId, $userId);
            if (!$page) {
                hgp_out(['ok' => false, 'error' => 'server_error'], 500);
            }
            hgp_out(['ok' => true, 'page' => [
                'id'      => (int)$page['id'],
                'slug'    => (string)$page['slug'],
                'title'   => (string)$page['title'],
                'node_id' => $page['node_id'] !== null ? (int)$page['node_id'] : null,
            ]]);
        }

        case 'duplicate': {
            $src = hgp_require_page();
            if (!db_hotglue_page_user_can_edit($src, $userId, $isAdmin)) {
                hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                $title = (string)$src['title'];
            }
            if (mb_strlen($title) > 255) {
                $title = mb_substr($title, 0, 255);
            }
            // The copy is always created UNASSIGNED and owned by the current user
            // (a wormhole shows only one page; the caller decides whether to assign it).
            $new = db_hotglue_page_create($title, $userId);
            $srcSlug = (string)$src['slug'];
            $newSlug = (string)$new['slug'];
            if (preg_match('/^(?:page|node)-[0-9]+$/', $srcSlug) === 1 && preg_match('/^page-[0-9]+$/', $newSlug) === 1) {
                hgp_copytree(__DIR__ . '/../hg/content/' . $srcSlug, __DIR__ . '/../hg/content/' . $newSlug);
            }
            hgp_out(['ok' => true, 'page' => [
                'id'    => (int)$new['id'],
                'slug'  => $newSlug,
                'title' => (string)$new['title'],
            ], 'src_was_assigned' => $src['node_id'] !== null]);
        }

        case 'delete': {
            $page = hgp_require_page();
            $owner = (string)($page['owner_user_id'] ?? '');
            $canDelete = $isAdmin
                || ($userId !== null && $owner === (string)$userId)
                || ($owner === '' && db_hotglue_page_user_can_edit($page, $userId, $isAdmin));
            if (!$canDelete) {
                hgp_out(['ok' => false, 'error' => 'not_authorized'], 403);
            }
            $slug = (string)$page['slug'];
            try {
                db_hotglue_page_delete((int)$page['id']);
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'constellation_read_only') !== false) {
                    hgp_out(['ok' => false, 'error' => 'read_only'], 403);
                }
                hgp_out(['ok' => false, 'error' => 'invalid'], 400);
            }
            // Remove the on-disk content tree. Slug is page-<id>; guard the shape
            // so a crafted row can never escape the content root.
            if (preg_match('/^page-[0-9]+$/', $slug) === 1) {
                hgp_rmtree(__DIR__ . '/../hg/content/' . $slug);
            }
            hgp_out(['ok' => true]);
        }

        default:
            hgp_out(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (Throwable $e) {
    error_log('api/hotglue-pages.php: ' . $e->getMessage());
    hgp_out(['ok' => false, 'error' => 'server_error'], 500);
}
