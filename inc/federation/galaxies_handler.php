<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/galaxies.json
 *
 * Public list of this instance's visitor-visible galaxies. Returned to
 * anonymous callers; the same data is already visible by browsing the
 * instance's home page. Used by the Pluriverse-side operator-application
 * form's "Load galaxies" button so the operator does not have to retype
 * slugs they could otherwise produce.
 *
 * Filter: `type='galaxy'` (excludes clusters and other non-galaxy
 * entities). The visitor-list flag (`show_galaxy_list`) is intentionally
 * NOT applied here: a galaxy hidden from the visitor home page may still
 * be a candidate for Pluriverse publishing, and the operator picks which
 * to actually publish on the apply form.
 *
 * Response shape:
 *   {
 *     "protocol_version": "1.0",
 *     "galaxies": [
 *       { "slug": "history", "name": "History", "tagline": "..." },
 *       ...
 *     ]
 *   }
 *
 * Rate limit 60 req/min/IP via APCu. Cache-Control: 60 s.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_galaxies:' . date('YmdHi') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many galaxies-list requests from this IP this minute; retry shortly.',
            '/api/pluriverse/galaxies.json'
        );
        return;
    }
}

try {
    $pdo = getDB();
    $rows = $pdo->query("
        SELECT slug, name, tagline
        FROM constellations
        WHERE `type` = 'galaxy'
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('pluriverse/galaxies: ' . $e->getMessage());
    federation_router_problem(
        500,
        'database_error',
        'Could not enumerate galaxies on this instance.',
        '/api/pluriverse/galaxies.json'
    );
    return;
}

$out = [];
foreach ($rows as $row) {
    $slug = (string)($row['slug'] ?? '');
    if ($slug === '') continue;
    $out[] = [
        'slug' => $slug,
        'name' => (string)($row['name'] ?? ''),
        'tagline' => (string)($row['tagline'] ?? ''),
    ];
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'protocol_version' => '1.0',
    'galaxies' => $out,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
