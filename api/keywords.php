<?php
declare(strict_types=1);

require_once '../config.php';
require_once 'auth.php';

// Require API key authentication
requireApiKey();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getDB();

try {
    match ($method) {
        'GET' => (function() use ($pdo): void {
            // Get all keywords or keywords for a specific node
            $nodeId = $_GET['node_id'] ?? null;
            
            if ($nodeId) {
                // Get keywords for a specific node using MySQL 8 CTE
                $stmt = $pdo->prepare("
                    WITH node_keywords_cte AS (
                        SELECT k.id, k.keyword 
                        FROM keywords k
                        JOIN node_keywords nk ON k.id = nk.keyword_id
                        WHERE nk.node_id = :node_id
                    )
                    SELECT id, keyword 
                    FROM node_keywords_cte
                    ORDER BY keyword
                ");
                $stmt->execute([':node_id' => $nodeId]);
                $keywords = $stmt->fetchAll();
            } else {
                // Get all keywords with usage count using window functions (MySQL 8)
                $stmt = $pdo->query("
                    SELECT 
                        k.id, 
                        k.keyword, 
                        COUNT(nk.node_id) as usage_count
                    FROM keywords k
                    LEFT JOIN node_keywords nk ON k.id = nk.keyword_id
                    GROUP BY k.id, k.keyword
                    ORDER BY k.keyword
                ");
                $keywords = $stmt->fetchAll();
            }
            
            echo json_encode($keywords, JSON_THROW_ON_ERROR);
        })(),
        
        'POST' => (function() use ($pdo): void {
            // Create a new keyword (or get existing) using MySQL 8 INSERT ... ON DUPLICATE KEY UPDATE
            $data = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
            $keyword = trim($data['keyword'] ?? '');
            
            if (empty($keyword)) {
                http_response_code(400);
                echo json_encode(['error' => 'Keyword required'], JSON_THROW_ON_ERROR);
                return;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO keywords (keyword) 
                VALUES (:keyword)
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
            ");
            $stmt->execute([':keyword' => $keyword]);
            
            $keywordId = (int)$pdo->lastInsertId();
            echo json_encode([
                'id' => $keywordId, 
                'keyword' => $keyword, 
                'success' => true
            ], JSON_THROW_ON_ERROR);
        })(),
        
        'DELETE' => (function() use ($pdo): void {
            // Delete a keyword
            $id = $_GET['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Keyword ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            
            $stmt = $pdo->prepare("DELETE FROM keywords WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),
        
        default => throw new RuntimeException('Method not allowed', 405)
    };
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 405);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
