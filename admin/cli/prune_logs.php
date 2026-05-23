#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: prune auth_attempts (>30d) and audit_events (>AUDIT_LOG_KEEP_DAYS).
 *
 * The two log tables already self-prune opportunistically in-process at the
 * first write per request, capped at LIMIT 10000 rows per pass. That keeps
 * concurrent INSERTs from contending on a long row lock, but under high QPS
 * the daily write rate can exceed the daily drain rate and the tables grow
 * unbounded (L-third-7, third-pass audit).
 *
 * This script is the cron-driven companion. Runs nightly outside the
 * request-serving path and drains the rest. The opportunistic in-process
 * prune stays in place so a fresh install without cron still gets some
 * tidy-up.
 *
 * Suggested cron line (once daily):
 *   23 4 * * * cd /var/www/starmaps.polivoxia.ca && php admin/cli/prune_logs.php
 *
 * Options:
 *   --auth-max-age-days=N   override auth_attempts retention (default 30)
 *   --audit-max-age-days=N  override audit_events retention (default tracks
 *                           AUDIT_LOG_KEEP_DAYS, min 7)
 *   --quiet                 silence per-run summary on success
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/db.php';

$opts = getopt('', ['auth-max-age-days:', 'audit-max-age-days:', 'quiet']);
$authDays = isset($opts['auth-max-age-days']) ? max(1, (int)$opts['auth-max-age-days']) : 30;
$auditDays = isset($opts['audit-max-age-days']) ? max(7, (int)$opts['audit-max-age-days']) : null;
$quiet = isset($opts['quiet']);

$stamp = '[' . gmdate('Y-m-d H:i:s') . ' UTC]';

try {
    $deletedAuth = db_prune_auth_attempts($authDays);
    $deletedAudit = db_prune_audit_events($auditDays);
} catch (Throwable $e) {
    fwrite(STDERR, $stamp . ' ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    echo $stamp
        . " pruned auth_attempts (>{$authDays}d): {$deletedAuth} row(s),"
        . ' audit_events (>'
        . ($auditDays !== null ? $auditDays : (defined('AUDIT_LOG_KEEP_DAYS') ? (int)AUDIT_LOG_KEEP_DAYS : 365))
        . "d): {$deletedAudit} row(s)\n";
}
exit(0);
