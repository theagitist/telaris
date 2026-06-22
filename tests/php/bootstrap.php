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

// Point integration tests at the isolated `telaris_starmaps_test` database
// (NEVER a live instance DB) by injecting a test PDO via resetDB(). The
// connection follows the app config.php DB endpoint (DB_HOST/DB_PORT/DB_SSL_CA,
// the managed cluster since the Phase 0 migration), reusing DB_USER/DB_PASS but
// swapping in the `_test` database, so no host or secret is hardcoded here.
// Mirrors getDB()'s own PDO options and timezone.
if (defined('DB_HOST')) {
    $telarisTestPort = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '5432';
    $telarisTestDsn  = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, $telarisTestPort, 'telaris_starmaps_test');
    if (defined('DB_SSL_CA') && DB_SSL_CA !== '') {
        $telarisTestDsn .= sprintf(';sslmode=verify-ca;sslrootcert=%s', DB_SSL_CA);
    }
    $telarisTestPdo = new PDO(
        $telarisTestDsn,
        DB_USER,
        defined('DB_PASS') ? DB_PASS : '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $telarisTestPdo->exec("SET TIME ZONE 'UTC'");
    $telarisTestPdo->exec('SET statement_timeout = 30000');
    resetDB($telarisTestPdo);

    // Ensure the Postgres-only schema prerequisites SCHEMA.sql cannot carry: the
    // unaccent/updated_at functions, the keyword expression unique index, and the
    // updated_at triggers. Idempotent and guarded.
    if (function_exists('db_ensure_pg_runtime')) {
        try { db_ensure_pg_runtime(); }
        catch (\Throwable $e) { fwrite(STDERR, 'db_ensure_pg_runtime: ' . $e->getMessage() . "\n"); }
    }

    // Seed the minimal baseline a fresh test DB lacks but that the live instance
    // DB the suite used to run against always had: an 'en' project_info row (read
    // by settings/getters) and the id=0 default constellation. Idempotent.
    try {
        $telarisTestPdo->exec("INSERT INTO project_info (locale) VALUES ('en') ON CONFLICT (locale) DO NOTHING");
        $telarisTestPdo->exec("INSERT INTO constellations (id, name, tagline, theme) VALUES (0, 'Default', '', 'cosmic') ON CONFLICT (id) DO NOTHING");
    } catch (\Throwable $e) {
        fwrite(STDERR, 'test baseline seed: ' . $e->getMessage() . "\n");
    }
}
