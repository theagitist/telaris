<?php
declare(strict_types=1);

require_once '../config.php';
require_once 'auth.php';

// Require API key authentication
requireApiKey();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getDB();

/**
 * Calculate connections between nodes based on shared keywords
 * Returns array of connections with node IDs and shared keyword count
 */
function calculateConnections(PDO $pdo): array {
    // Get all nodes with their keywords
    $nodesStmt = $pdo->query("
        SELECT n.id, n.name
        FROM nodes n
        ORDER BY n.id
    ");
    $nodes = $nodesStmt->fetchAll();
    
    // Get keywords for each node
    $nodeKeywords = [];
    foreach ($nodes as $node) {
        $keywordStmt = $pdo->prepare("
            SELECT k.keyword 
            FROM keywords k
            JOIN node_keywords nk ON k.id = nk.keyword_id
            WHERE nk.node_id = :node_id
        ");
        $keywordStmt->execute([':node_id' => $node['id']]);
        $nodeKeywords[$node['id']] = $keywordStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Calculate connections based on shared keywords
    $connections = [];
    $connectionId = 1;
    
    for ($i = 0; $i < count($nodes); $i++) {
        for ($j = $i + 1; $j < count($nodes); $j++) {
            $node1Id = (int)$nodes[$i]['id'];
            $node2Id = (int)$nodes[$j]['id'];
            
            $keywords1 = $nodeKeywords[$node1Id] ?? [];
            $keywords2 = $nodeKeywords[$node2Id] ?? [];
            
            // Find shared keywords
            $sharedKeywords = array_intersect($keywords1, $keywords2);
            
            // Create connection if nodes share at least one keyword
            if (count($sharedKeywords) > 0) {
                $connections[] = [
                    'id' => $connectionId++,
                    'node1_id' => $node1Id,
                    'node2_id' => $node2Id,
                    'shared_keywords' => array_values($sharedKeywords),
                    'shared_count' => count($sharedKeywords)
                ];
            }
        }
    }
    
    return $connections;
}

try {
    match ($method) {
        'GET' => (function() use ($pdo): void {
            // Calculate connections based on shared keywords
            $connections = calculateConnections($pdo);
            echo json_encode($connections, JSON_THROW_ON_ERROR);
        })(),
        
        default => throw new RuntimeException('Method not allowed. Connections are calculated automatically based on shared keywords.', 405)
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
