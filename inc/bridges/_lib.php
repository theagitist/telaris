<?php
declare(strict_types=1);

/**
 * Bridge framework: shared library.
 *
 * A "bridge" pulls content from a non-Telaris source into a local
 * constellation. Each named bridge is a subdirectory under inc/bridges/
 * with a standard file layout:
 *
 *   inc/bridges/{name}/
 *     handler.php       REQUIRED. Exports {name}_handle_request(): void
 *                       (HTTP, called by api/bridge.php) and
 *                       {name}_run_cli(): int (CLI, called by
 *                       admin/cli/import_bridge.php). May also export
 *                       optional hooks: {name}_cli_args_from_source(),
 *                       {name}_cluster_icon_url().
 *     admin.php         OPTIONAL. Exports any subset of:
 *                       {name}_admin_render_button(),
 *                       {name}_admin_render_modal(),
 *                       {name}_admin_render_js().
 *     ...               Any other files the bridge needs (sync helpers,
 *                       download helpers, etc.) are at the bridge author's
 *                       discretion. The framework only knows about the
 *                       two standard names above.
 *
 * Bridges enabled on this instance are listed in TELARIS_BRIDGES in config.php.
 * The framework code here knows about no specific bridge by name; concrete
 * bridges are self-contained plug-ins under inc/bridges/{name}/.
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
 * Load the handler file (inc/bridges/{name}/handler.php) for a bridge. The
 * dispatchers also call bridges_is_active() before invoking this; this
 * function additionally revalidates the name as defence-in-depth so it
 * cannot be used as a path traversal primitive by any future caller that
 * forgets the check. Returns true on success, false if the name is invalid
 * or the handler file is missing.
 *
 * Bridge handler files MUST namespace their global function definitions
 * with the `{name}_` (or `_{name}_` for private helpers) prefix to avoid
 * collisions in the PHP function table when multiple bridges load together.
 */
function bridges_load(string $name): bool {
    if (!bridges_name_is_valid($name)) {
        return false;
    }
    $path = __DIR__ . '/' . $name . '/handler.php';
    if (!is_file($path)) {
        return false;
    }
    require_once $path;
    return true;
}

/**
 * Load the optional admin-UI partial (inc/bridges/{name}/admin.php) for each
 * active bridge, if it ships one. A bridge with no admin surface (CLI-only,
 * for example) is allowed to skip the file entirely.
 *
 * The admin partial may define any subset of the per-hook render functions
 * (see bridges_admin_render()). It must namespace them with the `{name}_`
 * prefix just like the main handler file.
 */
function bridges_admin_load_all(): void {
    foreach (bridges_active() as $name) {
        if (!bridges_name_is_valid($name)) continue;
        $path = __DIR__ . '/' . $name . '/admin.php';
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
 *
 * M-B1 (third-pass audit, v6.10.15): cached in-request by bridge name. The
 * call site at api/nodes.php loops over every constellation in the
 * response, and a response that includes N clustered galaxies from the
 * same bridge would otherwise pay N `require_once handler.php` loads to
 * retrieve a string literal. The cache memoizes the result for the
 * lifetime of the request, so per-bridge it's `require_once` once and
 * memoized lookup thereafter. The `bridges_active`/`bridges_is_active`
 * short-circuit before the cache is checked, so on Polivoxia instances
 * (TELARIS_BRIDGES = []) this is a single in-list comparison.
 *
 * The cache uses an explicit "missing" sentinel via array_key_exists so
 * that a legitimate null result (bridge defines no icon hook) is also
 * cached and short-circuits subsequent calls.
 */
function bridges_cluster_icon_url_for(string $bridgeName): ?string {
    static $cache = [];
    if (array_key_exists($bridgeName, $cache)) {
        return $cache[$bridgeName];
    }
    if (!bridges_is_active($bridgeName)) {
        return $cache[$bridgeName] = null;
    }
    if (!bridges_load($bridgeName)) {
        return $cache[$bridgeName] = null;
    }
    $fn = $bridgeName . '_cluster_icon_url';
    if (!function_exists($fn)) {
        return $cache[$bridgeName] = null;
    }
    $url = $fn();
    $value = is_string($url) && $url !== '' ? $url : null;
    return $cache[$bridgeName] = $value;
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
