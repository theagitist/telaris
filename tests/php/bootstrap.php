<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap: defines DB constants, loads core includes,
 * and injects a test PDO connection via resetDB().
 */

// Define DB constants from the real config (integration tests need a real MySQL connection)
require_once __DIR__ . '/../../config.php';

// Dry-run all outgoing mail during tests. config.php populates the real
// MAIL_SMTP_* relay constants, so without this guard any test that transitively
// calls mail_send (operator drop notices, magic links, bulk-user invites, etc.)
// would deliver a real message. mail_send honours MAIL_DRY_RUN by logging a
// redacted line and returning true instead of contacting the relay.
if (!defined('MAIL_DRY_RUN')) {
    define('MAIL_DRY_RUN', true);
}

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Load core includes
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/validation.php';
require_once __DIR__ . '/../../inc/media-optimize.php';
require_once __DIR__ . '/../../utils/auth.php';
