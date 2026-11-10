<?php
declare(strict_types=1);

/**
 * Galaxy map API: the authored 2D ("geometric") view of a galaxy. Editors place
 * wormholes, draw paths between them and record routes; visitors read the result
 * in the 2D view. Mirrors api/keyword-canvas.php (same auth model).
 *
 * GET  ?galaxy_id=N[&editor=1]  public read; editor=1 (with edit access) seeds
 *                                unplaced wormholes and keeps author fields.
 * POST { action, ... }          requireWriteAccess() + galaxy seat + writable galaxy.
 *   move_node    { node_id, x, y }
 *   unplace_node { node_id }
 *   create_path  { node_a_id, node_b_id, note?, anchor_a?, anchor_b? }
 *   update_path  { relation_id, note }        (author or admin)
 *   delete_path  { relation_id }              (author or admin)
 *   create_route { galaxy_id, name, stops: [node ids] }
 *   rename_route { route_id, name }           (author or admin)
 *   delete_route { route_id }                 (author or admin)
 *
 * Vault: Projects/Telaris/Features/Geometric view (build plan).md
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

requireApiKey();

if (session_status() === PHP_SESSION_NONE) session_start();
$userId = isset($_SESSION['admin_user_id']) ? (string)$_SESSION['admin_user_id'] : null;
$isAdmin = ((int)($_SESSION['admin_user_type'] ?? 0)) === USER_TYPE_ADMIN;

function gm_can_edit(int $galaxyId, bool $isAdmin, ?string $userId): bool {
    if ($isAdmin) return true;
    if ($userId === null) return false;
    return in_array($galaxyId, array_map('intval', db_get_user_constellation_ids($userId)), true);
}

/** Galaxy of a wormhole, after the edit + writable checks; exits on failure. */
function gm_require_node_galaxy(int $nodeId, bool $isAdmin, ?string $userId): int {
    $gid = $nodeId > 0 ? db_get_node_constellation_id($nodeId) : null;
    if ($gid === null) api_error('404.001', 'Node not found.');
    if (!gm_can_edit($gid, $isAdmin, $userId)) api_error('403.004', 'No edit access to this galaxy.');
    api_require_writable_constellation($gid);
    return $gid;
}

function gm_require_author(?string $createdBy, bool $isAdmin, ?string $userId): void {
    if (!$isAdmin && (string)$createdBy !== (string)$userId) {
        api_error('403.006', 'Only the author or an admin can edit this.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $galaxyId = (int)($_GET['galaxy_id'] ?? 0);
    if ($galaxyId <= 0) api_error('400.015', 'A galaxy id is required.');
    $forEditor = !empty($_GET['editor']) && gm_can_edit($galaxyId, $isAdmin, $userId);
    echo json_encode(db_get_galaxy_map($galaxyId, $forEditor), JSON_THROW_ON_ERROR);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('405.001', 'Method not allowed.');

requireWriteAccess();
$raw = file_get_contents('php://input');
$in = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : $_POST;
$action = (string)($in['action'] ?? '');
$note = isset($in['note']) && trim((string)$in['note']) !== '' ? mb_substr(trim((string)$in['note']), 0, 500) : null;
$ok = fn(array $extra = []) => print(json_encode(['ok' => true] + $extra, JSON_THROW_ON_ERROR));

switch ($action) {
    case 'move_node': {
        $nodeId = (int)($in['node_id'] ?? 0);
        if (!isset($in['x'], $in['y'])) api_error('400.016', 'move_node requires node_id, x, y.');
        // Same per-editor throttle as the keyword canvas (each move writes history).
        if (function_exists('apcu_inc') && $userId !== null) {
            $hit = true;
            $n = apcu_inc('gm_move:' . date('YmdHi') . ':' . $userId, 1, $hit, 120);
            if ($n !== false && (int)$n > 120) api_error('429.001', 'Too many moves. Slow down and try again.');
        }
        gm_require_node_galaxy($nodeId, $isAdmin, $userId);
        db_record_node_position($nodeId, (float)$in['x'], (float)$in['y'], $userId);
        $ok(['node_id' => $nodeId]);
        break;
    }
    case 'unplace_node': {
        $nodeId = (int)($in['node_id'] ?? 0);
        gm_require_node_galaxy($nodeId, $isAdmin, $userId);
        db_unplace_node($nodeId);
        $ok(['node_id' => $nodeId]);
        break;
    }
    case 'create_path': {
        $a = (int)($in['node_a_id'] ?? 0);
        $b = (int)($in['node_b_id'] ?? 0);
        if ($a <= 0 || $b <= 0) api_error('400.017', 'create_path requires node_a_id and node_b_id.');
        if ($a === $b) api_error('400.018', 'Self-loop paths are not allowed.');
        $gid = gm_require_node_galaxy($a, $isAdmin, $userId);
        if (db_get_node_constellation_id($b) !== $gid) api_error('400.047', 'Both wormholes must belong to this galaxy.');
        $anchorA = (string)($in['anchor_a'] ?? 'right');
        $anchorB = (string)($in['anchor_b'] ?? 'left');
        try {
            $id = db_create_node_path($a, $b, $userId, $note, $anchorA, $anchorB);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uk_node_path_pair')) api_error('409.003', 'A path between these wormholes already exists.');
            throw $e;
        }
        $swap = $a > $b;
        $ok(['id' => $id, 'a' => min($a, $b), 'b' => max($a, $b),
             'anchor_a' => $swap ? $anchorB : $anchorA, 'anchor_b' => $swap ? $anchorA : $anchorB,
             'created_by' => $userId, 'note' => $note]);
        break;
    }
    case 'update_path':
    case 'delete_path': {
        $path = db_get_node_path((int)($in['relation_id'] ?? 0));
        if ($path === null) api_error('404.015', 'Path or route not found.');
        gm_require_node_galaxy($path['a'], $isAdmin, $userId);
        gm_require_author($path['created_by'], $isAdmin, $userId);
        if ($action === 'update_path') db_update_node_path($path['id'], $note);
        else db_delete_node_path($path['id']);
        $ok(['id' => $path['id'], 'note' => $note]);
        break;
    }
    case 'create_route': {
        $gid = (int)($in['galaxy_id'] ?? 0);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 200);
        $stops = array_values(array_map('intval', is_array($in['stops'] ?? null) ? $in['stops'] : []));
        if ($name === '' || count($stops) < 2) api_error('400.048', 'A route needs a name and at least two stops.');
        if (!gm_can_edit($gid, $isAdmin, $userId)) api_error('403.004', 'No edit access to this galaxy.');
        api_require_writable_constellation($gid);
        foreach ($stops as $nid) {
            if (db_get_node_constellation_id($nid) !== $gid) api_error('400.047', 'Both wormholes must belong to this galaxy.');
        }
        $id = db_create_node_route($gid, $name, $stops, $userId);
        $ok(['route' => ['id' => $id, 'name' => $name, 'created_by' => $userId, 'stops' => $stops]]);
        break;
    }
    case 'rename_route':
    case 'delete_route': {
        $route = db_get_node_route((int)($in['route_id'] ?? 0));
        if ($route === null) api_error('404.015', 'Path or route not found.');
        if (!gm_can_edit($route['constellation_id'], $isAdmin, $userId)) api_error('403.004', 'No edit access to this galaxy.');
        api_require_writable_constellation($route['constellation_id']);
        gm_require_author($route['created_by'], $isAdmin, $userId);
        if ($action === 'rename_route') {
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 200);
            if ($name === '') api_error('400.048', 'A route needs a name and at least two stops.');
            db_rename_node_route($route['id'], $name);
        } else {
            db_delete_node_route($route['id']);
        }
        $ok(['id' => $route['id']]);
        break;
    }
    default:
        api_error('400.030', 'Unknown action: %s.', [htmlspecialchars($action)]);
}
