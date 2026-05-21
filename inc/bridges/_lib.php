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
 * Load the handler file for a bridge. Caller must check bridges_is_active()
 * and bridges_name_is_valid() first; this function does not. Returns true on
 * success, false if the handler file is missing.
 */
function bridges_load(string $name): bool {
    $path = __DIR__ . '/' . $name . '.php';
    if (!is_file($path)) {
        return false;
    }
    require_once $path;
    return true;
}
