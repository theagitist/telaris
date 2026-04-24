<?php
declare(strict_types=1);

// Database configuration
define('DB_HOST', '');
define('DB_PORT', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('UPLOAD_DIR', __DIR__ . '/uploads'); // In production, use an absolute path outside the app directory
define('LOG_DIR', __DIR__ . '/logs');
define('SNAPSHOTS_DIR', __DIR__ . '/snapshots'); // Where local system snapshots are stored. Use an absolute path outside the app directory in production.

require_once __DIR__ . '/inc/db.php';
