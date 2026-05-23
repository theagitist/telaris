<?php
declare(strict_types=1);

/**
 * Keyword canvas API — hydration + write actions for the per-galaxy keyword
 * relationship editor.
 *
 * See `Academia/Projects/Telaris/Features/Keyword canvas/design.md` in the user's
 * vault for the political/decolonial rationale and the full design.
 *
 * Auth:
 *  - All endpoints require X-API-Key (or Authorization: Bearer / ?api_key=).
 *  - All POST endpoints additionally require an editor or admin session.
 *  - Per-action checks verify the user has a seat on the galaxy the action
 *    affects (admins skip the seat check).
 *  - update_relation and delete_relation additionally check author-or-admin.
 *
 * Actions (POST body or query params; JSON body preferred):
 *  - move_keyword      { keyword_id, x, y }
 *  - create_relation   { keyword_a_id, keyword_b_id, note? }
 *  - update_relation   { relation_id, note }
 *  - delete_relation   { relation_id }
 *  - reset_keyword     { keyword_id }
 *  - reset_galaxy      { galaxy_id }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

// CORS-ish headers (kept minimal; same-origin in practice).
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json'); header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

requireApiKey();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Current user's id (string per the users table), or null if no session. */
function kc_current_user_id(): ?string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $id = $_SESSION['admin_user_id'] ?? null;
    return $id !== null ? (string)$id : null;
}

/** True if the current session belongs to an admin. */
function kc_is_admin(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return ((int)($_SESSION['admin_user_type'] ?? 0)) === USER_TYPE_ADMIN;
}

/**
 * True if the current user can edit the given galaxy. Admins always can; editors
 * need a seat (row in user_constellations). The caller is responsible for first
 * calling requireWriteAccess(), which guarantees an editor-or-admin session.
 */
function kc_can_edit_galaxy(int $galaxyId): bool {
    if (kc_is_admin()) return true;
    $uid = kc_current_user_id();
    if ($uid === null) return false;
    $seatIds = db_get_user_constellation_ids($uid);
    return in_array($galaxyId, array_map('intval', $seatIds), true);
}

/** Read POST input — supports JSON body and form-encoded fallback. */
function kc_post_input(): array {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return $_POST;
}

// ---------------------------------------------------------------------------
// GET — hydration
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $galaxyId = isset($_GET['galaxy_id']) ? (int)$_GET['galaxy_id'] : 0;
    if ($galaxyId <= 0) api_error('400.015', 'A galaxy id is required.');

    // Hydration is read-only but still scoped to editors/admins of the galaxy.
    // (Visitors see no canvas in v1.) We require a session here too — calling
    // requireWriteAccess() is the simplest way to enforce session presence.
    requireWriteAccess();
    if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');

    $payload = db_get_keyword_canvas_hydration($galaxyId);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit();
}

// ---------------------------------------------------------------------------
// POST — write actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('405.001', 'Method not allowed.');
}

requireWriteAccess();
$input = kc_post_input();
$action = (string)($input['action'] ?? '');
$userId = kc_current_user_id();

switch ($action) {
    case 'move_keyword': {
        $keywordId = (int)($input['keyword_id'] ?? 0);
        $x = isset($input['x']) ? (float)$input['x'] : null;
        $y = isset($input['y']) ? (float)$input['y'] : null;
        if ($keywordId <= 0 || $x === null || $y === null) {
            api_error('400.016', 'move_keyword requires keyword_id, x, y.');
        }

        // L-third-4 (audit v6.10.13): per-editor token-bucket rate limit.
        // Each move records a row in keyword_position_history; a scripted
        // client without throttling can flood the table at thousands of
        // inserts/sec. Cap at ~60 writes per 60 seconds per userId via
        // APCu (in-memory); the 90-day prune in db_prune_keyword_position_history
        // still drains stale rows, but this gate keeps the floodable surface
        // bounded. If APCu is unavailable, the rate limit is a no-op (current
        // behaviour). The bucket is keyed by user id, not IP: honest editors
        // behind a shared NAT shouldn't serialize on each other.
        if (function_exists('apcu_inc') && $userId !== '' && $userId !== null) {
            $bucket = 'kc_move:' . date('YmdHi') . ':' . $userId;
            $rateOk = true;
            $count = apcu_inc($bucket, 1, $rateOk, 120);
            if ($count !== false && (int)$count > 60) {
                http_response_code(429);
                api_error('429.001', 'Too many keyword moves. Slow down and try again.');
            }
        }

        $galaxyId = db_get_keyword_constellation_id($keywordId);
        if ($galaxyId === null) api_error('404.003', 'Keyword not found.');
        if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');

        db_record_keyword_position($keywordId, $x, $y, $userId);
        echo json_encode(['ok' => true, 'keyword_id' => $keywordId], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'create_relation': {
        $a = (int)($input['keyword_a_id'] ?? 0);
        $b = (int)($input['keyword_b_id'] ?? 0);
        $note = isset($input['note']) && $input['note'] !== '' ? (string)$input['note'] : null;
        $anchorA = isset($input['anchor_a']) ? (string)$input['anchor_a'] : 'right';
        $anchorB = isset($input['anchor_b']) ? (string)$input['anchor_b'] : 'left';
        if ($a <= 0 || $b <= 0) api_error('400.017', 'create_relation requires keyword_a_id and keyword_b_id.');
        if ($a === $b) api_error('400.018', 'Self-loop relations are not allowed.');

        $galaxyA = db_get_keyword_constellation_id($a);
        $galaxyB = db_get_keyword_constellation_id($b);
        if ($galaxyA === null || $galaxyB === null) api_error('404.003', 'Keyword not found.');
        if ($galaxyA !== $galaxyB) api_error('400.019', 'Both keywords must belong to the same galaxy.');
        if (!kc_can_edit_galaxy($galaxyA)) api_error('403.004', 'No edit access to this galaxy.');

        try {
            $id = db_create_keyword_relation($a, $b, $userId, $note, $anchorA, $anchorB);
            // Canonical pair order: keyword_a < keyword_b. If we swapped above,
            // the anchors swap too — so anchor_a names the side on the lower id.
            $isSwapped = $a > $b;
            echo json_encode([
                'ok' => true,
                'id' => $id,
                'a' => min($a, $b),
                'b' => max($a, $b),
                'anchor_a' => $isSwapped ? $anchorB : $anchorA,
                'anchor_b' => $isSwapped ? $anchorA : $anchorB,
                'created_by' => $userId,
                'note' => $note,
            ], JSON_THROW_ON_ERROR);
        } catch (PDOException $e) {
            // Most likely: duplicate pair (uk_pair).
            if (str_contains($e->getMessage(), 'uk_pair') || (int)$e->errorInfo[1] === 1062) {
                api_error('409.002', 'A relation between these keywords already exists.');
            }
            throw $e;
        }
        exit();
    }

    case 'update_relation': {
        $relationId = (int)($input['relation_id'] ?? 0);
        $note = isset($input['note']) && $input['note'] !== '' ? (string)$input['note'] : null;
        if ($relationId <= 0) api_error('400.020', 'update_relation requires relation_id.');

        $rel = db_get_keyword_relation($relationId);
        if ($rel === null) api_error('404.004', 'Relation not found.');
        $galaxyId = db_get_keyword_constellation_id($rel['a']);
        if ($galaxyId === null) api_error('404.005', 'Relation references a missing keyword.');
        if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');
        // Author-or-admin: only the original author can edit; admins can edit anyone's.
        if (!kc_is_admin() && (string)$rel['created_by'] !== (string)$userId) {
            api_error('403.006', 'Only the author or an admin can edit this relation.');
        }
        db_update_keyword_relation($relationId, $note);
        echo json_encode(['ok' => true, 'id' => $relationId, 'note' => $note], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'delete_relation': {
        $relationId = (int)($input['relation_id'] ?? 0);
        if ($relationId <= 0) api_error('400.021', 'delete_relation requires relation_id.');

        $rel = db_get_keyword_relation($relationId);
        if ($rel === null) api_error('404.004', 'Relation not found.');
        $galaxyId = db_get_keyword_constellation_id($rel['a']);
        if ($galaxyId === null) api_error('404.005', 'Relation references a missing keyword.');
        if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');
        // Same rule as update: author or admin.
        if (!kc_is_admin() && (string)$rel['created_by'] !== (string)$userId) {
            api_error('403.007', 'Only the author or an admin can delete this relation.');
        }
        db_delete_keyword_relation($relationId);
        echo json_encode(['ok' => true, 'id' => $relationId], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'reset_keyword': {
        $keywordId = (int)($input['keyword_id'] ?? 0);
        if ($keywordId <= 0) api_error('400.022', 'reset_keyword requires keyword_id.');
        $galaxyId = db_get_keyword_constellation_id($keywordId);
        if ($galaxyId === null) api_error('404.003', 'Keyword not found.');
        if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');

        db_reset_keyword_position($keywordId, $userId);
        echo json_encode(['ok' => true, 'keyword_id' => $keywordId], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'reset_galaxy': {
        $galaxyId = (int)($input['galaxy_id'] ?? 0);
        if ($galaxyId <= 0) api_error('400.023', 'reset_galaxy requires galaxy_id.');
        if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');

        $count = db_reset_galaxy_positions($galaxyId, $userId);
        echo json_encode(['ok' => true, 'reset_count' => $count], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'delete_keyword': {
        // Deletes the keyword row. ON DELETE CASCADE drops every reference:
        // node_keywords junctions, keyword_positions, keyword_position_history,
        // keyword_relations on either side.
        $keywordId = (int)($input['keyword_id'] ?? 0);
        if ($keywordId <= 0) api_error('400.024', 'delete_keyword requires keyword_id.');
        $galaxyId = db_get_keyword_constellation_id($keywordId);
        if ($galaxyId === null) api_error('404.003', 'Keyword not found.');
        if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');

        db_delete_keyword($keywordId);
        echo json_encode(['ok' => true, 'keyword_id' => $keywordId], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'rename_keyword': {
        $keywordId = (int)($input['keyword_id'] ?? 0);
        $newName = isset($input['new_name']) ? trim((string)$input['new_name']) : '';
        if ($keywordId <= 0) api_error('400.025', 'rename_keyword requires keyword_id.');
        if ($newName === '') api_error('400.026', 'rename_keyword requires a non-empty new name.');
        if (mb_strlen($newName) > 100) api_error('400.027', 'Keyword name is too long (max 100 characters).');

        $galaxyId = db_get_keyword_constellation_id($keywordId);
        if ($galaxyId === null) api_error('404.003', 'Keyword not found.');
        if (!kc_can_edit_galaxy($galaxyId)) api_error('403.004', 'No edit access to this galaxy.');

        $existingId = db_find_keyword_in_galaxy($newName, $galaxyId);
        if ($existingId !== null && $existingId !== $keywordId) {
            // 409 + the existing id so the client can offer merge.
            api_error('409.001', 'A keyword with that name already exists.', [], [
                'existing_id' => $existingId,
                'existing_name' => $newName,
            ]);
        }
        try {
            db_rename_keyword($keywordId, $newName);
        } catch (PDOException $e) {
            // Defensive: the unique index could still bite under a race.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                api_error('409.001', 'A keyword with that name already exists.');
            }
            throw $e;
        }
        echo json_encode([
            'ok' => true,
            'keyword_id' => $keywordId,
            'new_name' => $newName,
        ], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'merge_keywords': {
        // Repoints every reference to source onto target, then deletes source.
        // Both keywords must live in the same galaxy.
        $sourceId = (int)($input['source_id'] ?? 0);
        $targetId = (int)($input['target_id'] ?? 0);
        if ($sourceId <= 0 || $targetId <= 0) api_error('400.028', 'merge_keywords requires source_id and target_id.');
        if ($sourceId === $targetId) api_error('400.029', 'Cannot merge a keyword into itself.');
        $sourceGalaxy = db_get_keyword_constellation_id($sourceId);
        $targetGalaxy = db_get_keyword_constellation_id($targetId);
        if ($sourceGalaxy === null || $targetGalaxy === null) api_error('404.003', 'Keyword not found.');
        if ($sourceGalaxy !== $targetGalaxy) api_error('400.019', 'Both keywords must belong to the same galaxy.');
        if (!kc_can_edit_galaxy($sourceGalaxy)) api_error('403.004', 'No edit access to this galaxy.');

        db_merge_keywords($sourceId, $targetId);
        echo json_encode([
            'ok' => true,
            'source_id' => $sourceId,
            'target_id' => $targetId,
        ], JSON_THROW_ON_ERROR);
        exit();
    }

    default:
        api_error('400.030', 'Unknown action: %s.', [htmlspecialchars($action)]);
}
