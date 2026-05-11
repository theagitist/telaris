<?php
declare(strict_types=1);

/**
 * Keyword canvas API — hydration + write actions for the per-galaxy keyword
 * relationship editor.
 *
 * See `Polivoxia/Projects/Telaris/Keyword canvas — design.md` in the user's
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

// CORS-ish headers (kept minimal; same-origin in practice).
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
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

/** Bail with a JSON error and an HTTP status code. */
function kc_fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    exit();
}

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
    if ($galaxyId <= 0) kc_fail(400, 'galaxy_id is required');

    // Hydration is read-only but still scoped to editors/admins of the galaxy.
    // (Visitors see no canvas in v1.) We require a session here too — calling
    // requireWriteAccess() is the simplest way to enforce session presence.
    requireWriteAccess();
    if (!kc_can_edit_galaxy($galaxyId)) kc_fail(403, 'No edit access to this galaxy');

    $payload = db_get_keyword_canvas_hydration($galaxyId);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit();
}

// ---------------------------------------------------------------------------
// POST — write actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kc_fail(405, 'Method not allowed');
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
            kc_fail(400, 'move_keyword requires keyword_id, x, y');
        }
        $galaxyId = db_get_keyword_constellation_id($keywordId);
        if ($galaxyId === null) kc_fail(404, 'Keyword not found');
        if (!kc_can_edit_galaxy($galaxyId)) kc_fail(403, 'No edit access to this galaxy');

        db_record_keyword_position($keywordId, $x, $y, $userId);
        echo json_encode(['ok' => true, 'keyword_id' => $keywordId], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'create_relation': {
        $a = (int)($input['keyword_a_id'] ?? 0);
        $b = (int)($input['keyword_b_id'] ?? 0);
        $note = isset($input['note']) && $input['note'] !== '' ? (string)$input['note'] : null;
        if ($a <= 0 || $b <= 0) kc_fail(400, 'create_relation requires keyword_a_id and keyword_b_id');
        if ($a === $b) kc_fail(400, 'Self-loop relations are not allowed');

        $galaxyA = db_get_keyword_constellation_id($a);
        $galaxyB = db_get_keyword_constellation_id($b);
        if ($galaxyA === null || $galaxyB === null) kc_fail(404, 'Keyword not found');
        if ($galaxyA !== $galaxyB) kc_fail(400, 'Both keywords must belong to the same galaxy');
        if (!kc_can_edit_galaxy($galaxyA)) kc_fail(403, 'No edit access to this galaxy');

        try {
            $id = db_create_keyword_relation($a, $b, $userId, $note);
            echo json_encode([
                'ok' => true,
                'id' => $id,
                'a' => min($a, $b),
                'b' => max($a, $b),
                'created_by' => $userId,
                'note' => $note,
            ], JSON_THROW_ON_ERROR);
        } catch (PDOException $e) {
            // Most likely: duplicate pair (uk_pair).
            if (str_contains($e->getMessage(), 'uk_pair') || (int)$e->errorInfo[1] === 1062) {
                kc_fail(409, 'A relation between these keywords already exists');
            }
            throw $e;
        }
        exit();
    }

    case 'update_relation': {
        $relationId = (int)($input['relation_id'] ?? 0);
        $note = isset($input['note']) && $input['note'] !== '' ? (string)$input['note'] : null;
        if ($relationId <= 0) kc_fail(400, 'update_relation requires relation_id');

        $rel = db_get_keyword_relation($relationId);
        if ($rel === null) kc_fail(404, 'Relation not found');
        $galaxyId = db_get_keyword_constellation_id($rel['a']);
        if ($galaxyId === null) kc_fail(404, 'Relation references missing keyword');
        if (!kc_can_edit_galaxy($galaxyId)) kc_fail(403, 'No edit access to this galaxy');
        // Author-or-admin: only the original author can edit; admins can edit anyone's.
        if (!kc_is_admin() && (string)$rel['created_by'] !== (string)$userId) {
            kc_fail(403, 'Only the author or an admin can edit this relation');
        }
        db_update_keyword_relation($relationId, $note);
        echo json_encode(['ok' => true, 'id' => $relationId, 'note' => $note], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'delete_relation': {
        $relationId = (int)($input['relation_id'] ?? 0);
        if ($relationId <= 0) kc_fail(400, 'delete_relation requires relation_id');

        $rel = db_get_keyword_relation($relationId);
        if ($rel === null) kc_fail(404, 'Relation not found');
        $galaxyId = db_get_keyword_constellation_id($rel['a']);
        if ($galaxyId === null) kc_fail(404, 'Relation references missing keyword');
        if (!kc_can_edit_galaxy($galaxyId)) kc_fail(403, 'No edit access to this galaxy');
        // Same rule as update: author or admin.
        if (!kc_is_admin() && (string)$rel['created_by'] !== (string)$userId) {
            kc_fail(403, 'Only the author or an admin can delete this relation');
        }
        db_delete_keyword_relation($relationId);
        echo json_encode(['ok' => true, 'id' => $relationId], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'reset_keyword': {
        $keywordId = (int)($input['keyword_id'] ?? 0);
        if ($keywordId <= 0) kc_fail(400, 'reset_keyword requires keyword_id');
        $galaxyId = db_get_keyword_constellation_id($keywordId);
        if ($galaxyId === null) kc_fail(404, 'Keyword not found');
        if (!kc_can_edit_galaxy($galaxyId)) kc_fail(403, 'No edit access to this galaxy');

        db_reset_keyword_position($keywordId, $userId);
        echo json_encode(['ok' => true, 'keyword_id' => $keywordId], JSON_THROW_ON_ERROR);
        exit();
    }

    case 'reset_galaxy': {
        $galaxyId = (int)($input['galaxy_id'] ?? 0);
        if ($galaxyId <= 0) kc_fail(400, 'reset_galaxy requires galaxy_id');
        if (!kc_can_edit_galaxy($galaxyId)) kc_fail(403, 'No edit access to this galaxy');

        $count = db_reset_galaxy_positions($galaxyId, $userId);
        echo json_encode(['ok' => true, 'reset_count' => $count], JSON_THROW_ON_ERROR);
        exit();
    }

    default:
        kc_fail(400, 'Unknown action: ' . htmlspecialchars($action));
}
