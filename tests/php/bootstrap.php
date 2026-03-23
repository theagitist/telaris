<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap: defines DB constants, loads core includes,
 * and injects a test PDO connection via resetDB().
 */

// Define DB constants from the real config (integration tests need a real MySQL connection)
require_once __DIR__ . '/../../config.php';

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Load core includes
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/validation.php';
require_once __DIR__ . '/../../inc/media-optimize.php';
require_once __DIR__ . '/../../utils/auth.php';
