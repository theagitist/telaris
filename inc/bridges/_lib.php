<?php
declare(strict_types=1);

/**
 * Bridge framework: shared library.
 *
 * A "bridge" pulls content from a non-Telaris source into a local
 * constellation. Each named bridge corresponds to a handler file at
 * inc/bridges/{name}.php that declares two entry points:
 *
 *   {name}_handle_request(): void  // HTTP, called by api/bridge.php
 *   {name}_run_cli(): int          // CLI, called by admin/cli/import_bridge.php
 *
 * Bridges enabled on this instance are listed in TELARIS_BRIDGES in config.php.
 * The framework code here knows about no specific bridge by name; concrete
 * bridges are self-contained plug-ins under inc/bridges/{name}*.php.
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

/**
 * Load the optional admin-UI partial for each active bridge
 * (inc/bridges/{name}-admin.php), if it ships one. A bridge with no admin
 * surface (CLI-only, for example) is allowed to skip the file entirely.
 *
 * The admin partial may define any subset of the per-hook render functions
 * (see bridges_admin_render()). It must namespace them with the `{name}_`
 * prefix just like the main handler file.
 */
function bridges_admin_load_all(): void {
    foreach (bridges_active() as $name) {
        if (!bridges_name_is_valid($name)) continue;
        $path = __DIR__ . '/' . $name . '-admin.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
}

/**
 * Look up the cluster-icon URL that a bridge wants applied to its imported
 * constellations' auto-generated cluster nodes. Returns null if the bridge
 * is not active, has no handler file, or does not define the optional
 * `{name}_cluster_icon_url()` function. Used by api/nodes.php to let a
 * bridge customize the visitor-side rendering of cluster pseudo-nodes
 * inside galaxies it imported.
 */
function bridges_cluster_icon_url_for(string $bridgeName): ?string {
    if (!bridges_is_active($bridgeName)) return null;
    if (!bridges_load($bridgeName)) return null;
    $fn = $bridgeName . '_cluster_icon_url';
    if (!function_exists($fn)) return null;
    $url = $fn();
    return is_string($url) && $url !== '' ? $url : null;
}

/**
 * Render hook: call {name}_admin_render_{hook}() for every active bridge
 * that defines it. The current hooks are:
 *
 *   - 'button': contribute a button to the galaxy-list header.
 *   - 'modal':  contribute a <dialog> / modal element to the page body.
 *   - 'js':     contribute a <script> block (consts, functions, and the
 *               window.BRIDGES_REFRESH_UI[{name}] registration if the
 *               bridge supports refresh-from-stored-source).
 *
 * Each render function echoes (does not return) its contribution. Hooks
 * a bridge does not implement are skipped silently. The hook name must
 * match `[a-z][a-z0-9_]*` (no separators, no path-shaped input).
 */
function bridges_admin_render(string $hook): void {
    if (preg_match('/^[a-z][a-z0-9_]*$/', $hook) !== 1) {
        return;
    }
    foreach (bridges_active() as $name) {
        if (!bridges_name_is_valid($name)) continue;
        $fn = $name . '_admin_render_' . $hook;
        if (function_exists($fn)) {
            $fn();
        }
    }
}
