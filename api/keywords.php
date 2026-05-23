<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';

header('Content-Type: application/json'); header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// Require API key authentication
requireApiKey();

$method = $_SERVER['REQUEST_METHOD'];

try {
    match ($method) {
        'GET' => (function(): void {
            $nodeId = isset($_GET['node_id']) ? (int)$_GET['node_id'] : null;
            if ($nodeId !== null && $nodeId > 0) {
                echo json_encode(db_get_keywords($nodeId), JSON_THROW_ON_ERROR);
                return;
            }
            // Autocomplete bucketed response for the node-keyword chip input.
            // Two buckets only: current galaxy + prefix-siblings (same [XX] prefix).
            // We deliberately don't include a global bucket — the editor wants vocabulary
            // coherence within the prefix group, not arbitrary keywords from unrelated
            // galaxies (which previously surfaced via db_get_keywords()'s default-galaxy
            // fallback and included orphan rows).
            if (isset($_GET['constellation_id']) && is_numeric($_GET['constellation_id']) && !empty($_GET['autocomplete'])) {
                $cid = (int)$_GET['constellation_id'];
                $current = db_get_keywords_for_galaxies([$cid]);
                $currentNames = array_flip(array_map(fn($k) => $k['keyword'], $current));

                $siblingIds = db_get_prefix_sibling_ids($cid);
                $siblingIds = array_values(array_filter($siblingIds, fn($id) => $id !== $cid));
                $siblings = $siblingIds === [] ? [] : db_get_keywords_for_galaxies($siblingIds);
                $siblings = array_values(array_filter($siblings, fn($k) => !isset($currentNames[$k['keyword']])));

                echo json_encode([
                    'current' => $current,
                    'siblings' => $siblings,
                    'global' => [],
                ], JSON_THROW_ON_ERROR);
                return;
            }
            // Per-constellation keyword list with usage counts (for bulk-by-keyword UI).
            if (isset($_GET['constellation_id']) && is_numeric($_GET['constellation_id'])) {
                $cid = (int)$_GET['constellation_id'];
                echo json_encode(db_get_keywords_for_constellation($cid), JSON_THROW_ON_ERROR);
                return;
            }
            echo json_encode(db_get_keywords(), JSON_THROW_ON_ERROR);
        })(),
        
        'POST' => (function(): void {
            requireWriteAccess();
            $data = json_decode(stream_get_contents(fopen('php://input', 'r'), 1048576), true, flags: JSON_THROW_ON_ERROR);
            $keyword = trim($data['keyword'] ?? '');
            if (empty($keyword)) {
                api_error('400.012', 'A keyword is required.');
            }
            // Keywords are per-galaxy; the caller must name the galaxy and have a seat
            // on it. Pre-fix this endpoint created keywords against the default galaxy
            // with no scope check, letting any editor add vocabulary to galaxies they
            // had no access to.
            $constellationId = $data['constellation_id'] ?? null;
            if ($constellationId === null || !is_numeric($constellationId) || (int)$constellationId <= 0) {
                api_error('400.010', 'A constellation id is required.');
            }
            $constellationId = (int)$constellationId;
            if (db_get_constellation_by_id($constellationId) === null) {
                api_error('404.008', 'The target galaxy does not exist.');
            }
            $accessError = checkEditorConstellationAccess($constellationId);
            if ($accessError !== null) {
                error_log('keywords.php access (create): ' . $accessError);
                api_error('403.005', 'Access denied.');
            }
            $createdBy = $_SESSION['admin_user_id'] ?? null;
            $keywordId = db_create_keyword($keyword, $constellationId, $createdBy);
            echo json_encode(['id' => $keywordId, 'keyword' => $keyword, 'success' => true], JSON_THROW_ON_ERROR);
        })(),

        'DELETE' => (function(): void {
            requireWriteAccess();
            $id = $_GET['id'] ?? null;
            $constellationId = $_GET['constellation_id'] ?? null;
            if (!$id) {
                api_error('400.013', 'A keyword id is required.');
            }
            if ($constellationId === null || !ctype_digit((string)$constellationId)) {
                api_error('400.010', 'A constellation id is required.');
            }
            $actualConstellationId = db_get_keyword_constellation_id((int)$id);
            if ($actualConstellationId === null || $actualConstellationId !== (int)$constellationId) {
                api_error('400.014', 'The keyword does not belong to the specified constellation.');
            }
            // Source-of-truth seat check against the keyword's actual galaxy. Pre-fix
            // any editor could delete any keyword in any galaxy because the access
            // gate was missing entirely.
            $accessError = checkEditorConstellationAccess($actualConstellationId);
            if ($accessError !== null) {
                error_log('keywords.php access (delete): ' . $accessError);
                api_error('403.005', 'Access denied.');
            }
            db_delete_keyword((int)$id);
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        default => api_error('405.001', 'Method not allowed.')
    };
} catch (JsonException $e) {
    api_error('400.001', 'Invalid JSON: %s', [$e->getMessage()]);
} catch (PDOException $e) {
    error_log('keywords.php PDOException: ' . $e->getMessage());
    api_error('500.002', 'Database error.');
} catch (RuntimeException $e) {
    error_log('keywords.php RuntimeException: ' . $e->getMessage());
    api_error('500.001', 'Internal server error.');
}
