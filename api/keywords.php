<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
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
                http_response_code(400);
                echo json_encode(['error' => 'Keyword required'], JSON_THROW_ON_ERROR);
                return;
            }
            $keywordId = db_create_keyword($keyword);
            echo json_encode(['id' => $keywordId, 'keyword' => $keyword, 'success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        'DELETE' => (function(): void {
            requireWriteAccess();
            $id = $_GET['id'] ?? null;
            $constellationId = $_GET['constellation_id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Keyword ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            if ($constellationId === null || !ctype_digit((string)$constellationId)) {
                http_response_code(400);
                echo json_encode(['error' => 'constellation_id required'], JSON_THROW_ON_ERROR);
                return;
            }
            $actualConstellationId = db_get_keyword_constellation_id((int)$id);
            if ($actualConstellationId === null || $actualConstellationId !== (int)$constellationId) {
                http_response_code(403);
                echo json_encode(['error' => 'Keyword does not belong to the specified constellation'], JSON_THROW_ON_ERROR);
                return;
            }
            db_delete_keyword((int)$id);
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        default => throw new RuntimeException('Method not allowed', 405)
    };
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('keywords.php PDOException: ' . $e->getMessage());
    echo json_encode(['error' => 'Database error'], JSON_THROW_ON_ERROR);
} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 405);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
