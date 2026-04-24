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
define('SNAPSHOTS_DIR', __DIR__ . '/telaris-snapshots'); // Where local system snapshots are stored. In production, use an absolute path outside the app directory and prefix with the site name (e.g. /var/backups/starmaps-snapshots) so it is not mistaken for another app's backups.

require_once __DIR__ . '/inc/db.php';
