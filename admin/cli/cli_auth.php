<?php
declare(strict_types=1);

/**
 * CLI Authentication/Protection
 * 
 * This file ensures that CLI scripts can only be run from the command line.
 * Include this at the top of any CLI script to prevent web access.
 * 
 * Usage:
 * require_once __DIR__ . '/cli_auth.php';
 */

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    // If accessed via web, return 403 Forbidden
    http_response_code(403);
    header('Content-Type: text/plain');
    die("403 Forbidden\n\nThis script can only be run from the command line.\n");
}

// Change to project root directory for relative paths
$projectRoot = dirname(__DIR__) . '/..';
if (is_dir($projectRoot)) {
    chdir($projectRoot);
}
