<?php
declare(strict_types=1);

/**
 * Bridge framework — shared library.
 *
 * A "bridge" pulls content from a non-Telaris source (Mocambos / Baobáxia is
 * the first one) into a local constellation. Each named bridge corresponds to
 * a handler file at inc/bridges/{name}.php that declares two entry points:
 *
 *   {name}_handle_request()                    // HTTP, called by api/bridge.php
 *   {name}_run_cli(array $opts, bool $interactive): int   // CLI, called by admin/cli/import_bridge.php
 *
 * Bridges enabled on this instance are listed in TELARIS_BRIDGES in config.php.
 *
 * Per the federation plan: bridge imports are not federation. They run on a
 * single Telaris instance and pull content from outside the network.
 */

// Defensive default. config.php is the canonical place to declare this; if
// not declared (older deploys), treat as empty.
if (!defined('TELARIS_BRIDGES')) {
    define('TELARIS_BRIDGES', []);
}

/**
 * The flat list of bridge names enabled on this instance.
 */
function bridges_active(): array {
    return TELARIS_BRIDGES;
}

/**
 * Whether a given bridge is enabled on this instance.
 */
function bridges_is_active(string $name): bool {
    return in_array($name, TELARIS_BRIDGES, true);
}

/**
 * Validate a bridge name against the allowed character set. Defends against
 * directory traversal when names are taken from $_GET or $argv.
 */
function bridges_name_is_valid(string $name): bool {
    return $name !== '' && preg_match('/^[a-z][a-z0-9_-]*$/', $name) === 1;
}

/**
 * Load the handler file for a bridge. The dispatchers also call
 * bridges_is_active() before invoking this; this function additionally
 * revalidates the name as defence-in-depth so it cannot be used as a path
 * traversal primitive by any future caller that forgets the check. Returns
 * true on success, false if the name is invalid or the handler file is
 * missing.
 *
 * Bridge handler files MUST namespace their global function definitions
 * with the `{name}_` (or `_{name}_` for private helpers) prefix to avoid
 * collisions in the PHP function table when multiple bridges load together.
 */
function bridges_load(string $name): bool {
    if (!bridges_name_is_valid($name)) {
        return false;
    }
    $path = __DIR__ . '/' . $name . '.php';
    if (!is_file($path)) {
        return false;
    }
    require_once $path;
    return true;
}
