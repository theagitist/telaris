<?php
declare(strict_types=1);

/**
 * Main entry point for Telaris application.
 * Bootstrap loads config, DB, auth, and project info; then the main view is rendered.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/main-view.php';
