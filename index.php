<?php
declare(strict_types=1);

/**
 * Main entry point for Telaris application.
 * Bootstrap loads config, DB, auth, and project info; then the main view is rendered.
 */

require_once __DIR__ . '/inc/bootstrap.php';

// Federation routes (/api/pluriverse/*) short-circuit the visitor main view.
// Nginx try_files falls these URLs through to index.php (no .php suffix on
// the wire); the router dispatches to the matching endpoint handler or
// emits an RFC 9457 Problem Details 404.
$reqPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
if (str_starts_with($reqPath, '/api/pluriverse/')) {
    require __DIR__ . '/inc/federation/router.php';
    exit;
}

require_once __DIR__ . '/inc/main-view.php';
