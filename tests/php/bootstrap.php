<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap: defines DB constants, loads core includes,
 * and injects a test PDO connection via resetDB().
 */

// Load the instance config (constants loaded once; utils/auth.php also requires it).
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

// Point integration tests at the local PostgreSQL test database (NEVER a live
// instance DB) by injecting a test PDO via resetDB(). Gated on the dev-only
// password file existing, so the suite falls back to the config.php connection
// on machines without the local PG test DB. The password lives under ~/apps/keys
// (not in the webroot). Mirrors getDB()'s own PDO options and timezone.
$telarisPgKey = (getenv('HOME') ?: '') . '/apps/keys/telaris-postgres-dev';
if (is_readable($telarisPgKey)) {
    $telarisPgPass = trim((string)file_get_contents($telarisPgKey));
    $telarisTestPdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=starmaps_pg_test',
        'starmaps',
        $telarisPgPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $telarisTestPdo->exec("SET TIME ZONE 'America/Vancouver'");
    $telarisTestPdo->exec('SET statement_timeout = 30000');
    resetDB($telarisTestPdo);

    // Ensure the Postgres-only schema prerequisites SCHEMA.sql cannot carry: the
    // unaccent/updated_at functions, the keyword expression unique index, and the
    // updated_at triggers. Idempotent and guarded.
    if (function_exists('db_ensure_pg_runtime')) {
        try { db_ensure_pg_runtime(); }
        catch (\Throwable $e) { fwrite(STDERR, 'db_ensure_pg_runtime: ' . $e->getMessage() . "\n"); }
    }
}
