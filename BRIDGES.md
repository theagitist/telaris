# Bridges: writing a new one

A **Bridge** pulls content from a non-Telaris source into a local galaxy. Each bridge is a self-contained plug-in: a subdirectory under `inc/bridges/`, plus an optional asset directory under `img/bridges/`. Adding a new bridge does not modify any generic file. The framework code names no specific bridge anywhere; everything bridge-specific lives in the bridge's own directory.

The first concrete bridge is Mocambos. Use `inc/bridges/mocambos/` as a reference implementation when in doubt about the contracts described here.

> [!important] Bridges are not federation
> A Bridge import runs on a single Telaris instance and pulls content from outside the network. It does not require the Pluriverse. The federation that subsequently shares the imported galaxies between Telaris instances is a separate layer (P2P federation, v10 plan).

## Quick start

A minimal bridge called `kosmos`:

```sh
mkdir -p inc/bridges/kosmos
mkdir -p img/bridges/kosmos
$EDITOR inc/bridges/kosmos/handler.php
```

Then in `config.php`:

```php
define('TELARIS_BRIDGES', ['kosmos']);
```

That's the whole installation. The HTTP dispatcher serves `api/bridge.php?name=kosmos&action=...`; the CLI dispatcher accepts `admin/cli/import_bridge.php kosmos ...`; the admin UI iterates active bridges and renders any contributions Kosmos exposes; `bridges_load('kosmos')` requires `inc/bridges/kosmos/handler.php`. No edits to `admin/index.php`, `api/bridge.php`, `_lib.php`, or anywhere else.

## File layout

```
inc/bridges/
└── kosmos/
    ├── handler.php       REQUIRED. HTTP + CLI entry points, optional hooks.
    ├── admin.php         OPTIONAL. Admin UI render functions.
    └── ...               Any other files the bridge needs. The framework
                          does not load these by name; require_once them
                          from handler.php using __DIR__ . '/whatever.php'.

img/bridges/
└── kosmos/
    └── ...               Per-bridge static assets (icons, themes, etc.).
                          Served by nginx as ordinary files.
```

The framework only knows about two standard filenames inside the bridge directory: `handler.php` and `admin.php`. Everything else is at the bridge author's discretion.

## handler.php

The handler file is required. It exports two functions, both with no parameters, plus any number of optional hooks. All global identifiers it defines must be prefixed with the bridge name (or `_` + bridge name for private helpers) to avoid collisions when multiple bridges load together.

### Required: HTTP entry

```php
function kosmos_handle_request(): void;
```

Called by `api/bridge.php` after auth, CORS, name validation, and the active-bridge check. The handler reads from `$_SERVER['REQUEST_METHOD']`, `$_GET['action']`, and `php://input` as it sees fit, and emits its response.

Conventions:
- Use `?action=<verb>` to dispatch within the handler. A Kosmos handler might have `validate`, `list`, `import`, `refresh`. Choose action names that make sense for the source.
- For short responses, emit a single JSON object via `echo json_encode($result, JSON_THROW_ON_ERROR)`.
- For long-running imports, switch to newline-delimited JSON streaming (one event per line). The Mocambos handler does this for the import action; see its `_mocambos_http_import()` function for the pattern.
- Call `requireWriteAccess()` for any action that mutates state. The dispatcher only calls `requireApiKey()`; finer-grained authorisation is the handler's responsibility.
- Wrap mutating actions with `set_time_limit(0)` if they can take more than a few seconds.

The dispatcher catches any uncaught `Throwable` and converts it into a generic HTTP 500 JSON envelope (the exception detail goes to `error_log()`). The handler does not need its own top-level try/catch unless it has streaming-specific concerns.

### Required: CLI entry

```php
function kosmos_run_cli(): int;
```

Called by `admin/cli/import_bridge.php` after the bridge-name positional has been consumed. The dispatcher pre-parses no flags; the handler calls `getopt()` with its own flag schema. `getopt()` ignores the bridge-name positional automatically, so a command like `php admin/cli/import_bridge.php kosmos --query=mango` will reach `kosmos_run_cli()` with `getopt('', ['query:'])` returning `['query' => 'mango']`.

Returns the process exit code (0 = success).

Conventions:
- Detect interactive mode (no flags or only `--quiet`) and prompt for what you need. Mocambos's pattern: `$interactive = empty($opts) || (count($opts) === 1 && isset($opts['quiet']));`
- Emit progress to stdout with a coloured prefix per severity. The Mocambos handler's `_mocambos_cli_logger()` factory is a useful template.
- Write to stderr on error and return a non-zero exit code.

### Optional handler hooks

The same `handler.php` may also export any subset of these. Each is called by the framework when the corresponding feature is invoked.

#### `kosmos_cli_args_from_source(array $source): ?array`

Returns the CLI argv tail that would re-import a constellation whose `import_source` JSON was stamped by this bridge. Used by `admin/cli/refresh_constellation.php` to route refresh-from-stored-source through the bridge.

The `$source` argument is the parsed `import_source` JSON. The bridge knows what shape it wrote there. Return `null` if the stored source is incomplete (missing fields, etc.). Return an array of `--flag=value` strings on success.

```php
function kosmos_cli_args_from_source(array $source): ?array {
    $apiBase = $source['api_base'] ?? '';
    $query = $source['query'] ?? '';
    if ($apiBase === '' || $query === '') return null;
    return ['--api-base=' . $apiBase, '--query=' . $query];
}
```

Bridges that do not implement this hook still work; their constellations cannot be refreshed via `refresh_constellation.php` and need to be re-imported by the operator running `import_bridge.php` directly.

#### `kosmos_cluster_icon_url(): string`

Returns a URL to apply as the icon on auto-generated cluster pseudo-nodes inside galaxies this bridge imported. Called from `api/nodes.php` via `bridges_cluster_icon_url_for()` only when the constellation's `import_source.source` matches this bridge.

```php
function kosmos_cluster_icon_url(): string {
    return 'img/bridges/kosmos/cluster.svg';
}
```

If you do not implement this, cluster pseudo-nodes use the default icon.

## admin.php

The admin partial is optional. If present, the framework loads it on every admin page render and calls any of these three functions that exist, at the matching slot. Each function echoes (does not return) its contribution.

### `kosmos_admin_render_button(): void`

Contributes a button to the galaxy-list header (where *New Galaxy* and *Import from Mocambos* live). One bridge, one button is the usual shape.

```php
function kosmos_admin_render_button(): void {
?>
    <button type="button" onclick="openKosmosImportModal()" class="text-yellow-600 hover:text-yellow-800 font-medium text-base">Import from Kosmos</button>
<?php
}
```

### `kosmos_admin_render_modal(): void`

Contributes a `<dialog>` element to the page body. This is where the bridge's import UI lives: input fields, a list of available items from the source, progress display, etc.

Conventions:
- Prefix every HTML element id and CSS class with the bridge name to avoid collisions: `<dialog id="kosmos_import_modal">`, `<input id="kosmos-query">`, etc.
- The modal can call back to the bridge's HTTP endpoint via `fetch('../api/bridge.php?name=kosmos&action=...')` (see the JS slot below).

### `kosmos_admin_render_js(): void`

Contributes a `<script>` block. Holds the JS that drives the modal: opening it, fetching from `api/bridge.php`, rendering progress, etc.

Conventions:
- Wrap the whole block in an IIFE so the bridge does not pollute the global JS namespace beyond what it explicitly attaches to `window`.
- The bridge's button uses inline `onclick="openKosmosImportModal()"`. Expose entry points explicitly: `window.openKosmosImportModal = openKosmosImportModal;`.
- Use the framework-provided globals: `API_KEY` (for `X-API-Key` headers), `showMessage(text, type)` (toast helper), `loadConstellations()` (reload galaxy list after import).
- For per-galaxy refresh, register a handler in `window.BRIDGES_REFRESH_UI`:

```js
window.BRIDGES_REFRESH_UI = window.BRIDGES_REFRESH_UI || {};
window.BRIDGES_REFRESH_UI['kosmos'] = function(constId, name) {
    // Open the modal pre-populated with the constellation's stored source
    // and run the refresh import path.
};
```

The generic `bridgeRefreshConstellation()` dispatcher in `admin/index.php` reads the constellation's `import_source.source` field and routes to the matching handler in this registry.

## Internal helpers

Anything the bridge needs beyond the two standard files goes in additional files inside the bridge directory. Names are at the bridge author's discretion. Require them from `handler.php` using `__DIR__`:

```php
// inc/bridges/kosmos/handler.php
require_once __DIR__ . '/fetch.php';
require_once __DIR__ . '/parse.php';
```

The Mocambos bridge does this with `sync.php` (incremental-diff logic) and `download.php` (streaming media download).

Naming convention for global identifiers inside these helpers:
- Public functions used across the bridge's files: `kosmos_<verb>()` (e.g. `kosmos_compute_diff`).
- Private helpers used only within one file: `_kosmos_<verb>()` (e.g. `_kosmos_resolve_smid`).

Do not define generic-sounding helper names like `_resolve_smid()` or `_normalize_tags()` without the bridge prefix. PHP's function table is global; two bridges declaring the same unprefixed helper fail to load together with a fatal "function already defined" error. The framework's unit tests cover the canonical Mocambos namespacing as a reference.

## Static assets

Static files the bridge wants visitor-side (cluster icons, custom themes, decorations) go under `img/bridges/{name}/`. Serve them with relative URLs from PHP: `'img/bridges/kosmos/cluster.svg'`. Nginx handles them as ordinary files.

The framework provides one path that consumes such an asset automatically: `kosmos_cluster_icon_url()` (the visitor-side cluster icon override, described above). Other assets are referenced by the bridge's own code as needed.

## Naming conventions

| Item | Convention | Notes |
|---|---|---|
| Bridge name | `^[a-z][a-z0-9_-]*$` | Lowercase, starts with a letter, may contain digits, underscores, hyphens. Enforced by `bridges_name_is_valid()`. |
| Public PHP function | `{name}_*` | `kosmos_handle_request`, `kosmos_run_cli`, `kosmos_compute_diff`. |
| Private PHP function | `_{name}_*` | `_kosmos_resolve_smid`, `_kosmos_normalize_tags`. |
| HTML element id | `{name}_*` or `{name}-*` | `kosmos_import_modal`, `kosmos-url-input`. |
| CSS class | `{name}-*` | `kosmos-item-checkbox`. |
| JS function exposed on window | `{name}*` | `openKosmosImportModal`, `doKosmosImport`. |
| Bridge's JS registry entry | `window.BRIDGES_REFRESH_UI['{name}']` | The framework registry name is fixed; the key is the bridge name. |
| Static asset path | `img/bridges/{name}/...` | Per-bridge subdirectory under the standard image folder. |

## Schema

The bridge writes nodes to the global `nodes` table via `inc/db.php` helpers. The columns most relevant to bridges are:

| Column | What goes in |
|---|---|
| `name`, `description`, `url` | Standard node fields. |
| `import_slug` | A stable string identifier from the source. Re-imports match existing nodes by this column to compute the diff. |
| `source_facet` | A neutral per-node facet column (Mocambos writes its mucua name here; another bridge could write the upstream's region, namespace, category, etc.). The clustering engine groups by this column when it is populated. |
| `media_type` | A free-string type label the source provides. The clustering engine uses this as a secondary axis. |
| `source_created_at` | Original creation timestamp from the source (used as a clustering / date-bucketing axis). |

The bridge also stamps the constellation's `import_source` column with a JSON object describing the import:

```json
{
  "source": "kosmos",
  "api_base": "https://example.com/api",
  "query": "..."
}
```

The `source` field must equal the bridge name (so refresh dispatch and other framework-level lookups work). The rest of the object is the bridge's choice; whatever you put there will come back to you in `kosmos_cli_args_from_source()`.

Set the import_source via `db_set_constellation_import_source($constellationId, $jsonString)`. Read it back with `db_get_constellation_import_source($constellationId)`.

## Enabling and disabling

`config.php` carries `TELARIS_BRIDGES`, a flat array of enabled bridge names:

```php
define('TELARIS_BRIDGES', []);                       // no bridges (default)
define('TELARIS_BRIDGES', ['mocambos']);             // one bridge
define('TELARIS_BRIDGES', ['mocambos', 'kosmos']);   // two bridges
```

When a bridge is not in this array:
- Its admin button does not render (the framework's `bridges_admin_render('button')` iterator filters by active membership).
- Its admin modal and JS are not loaded.
- The HTTP dispatcher returns 404 for `api/bridge.php?name=<name>&...`.
- The CLI dispatcher refuses `admin/cli/import_bridge.php <name> ...`.
- Existing constellations imported by that bridge stay in the database but cannot be refreshed via the framework.

## Testing

Bridges should ship tests under `tests/php/Unit/`. Convention: name the test file after the bridge (`KosmosSyncTest.php`, `KosmosParseTest.php`).

Required path from the test file to the bridge:

```php
require_once __DIR__ . '/../../../inc/bridges/kosmos/sync.php';
```

The framework's own contract is verified by `BridgesLibTest`. Bridges don't need to retest framework invariants (name validation, defence-in-depth in `bridges_load()`, etc.); they test their own internal logic.

Run the suite with:

```sh
php vendor/bin/phpunit --testsuite unit
```

## Framework API reference

These functions live in `inc/bridges/_lib.php` and are available to anything that requires it. A bridge handler does not usually call them directly; they exist for the dispatchers and the admin renderer. Documented here for completeness.

| Function | What it does |
|---|---|
| `bridges_active(): array` | Returns the configured `TELARIS_BRIDGES` array. |
| `bridges_is_active(string $name): bool` | Membership test against `TELARIS_BRIDGES`. |
| `bridges_name_is_valid(string $name): bool` | Regex check `^[a-z][a-z0-9_-]*$`. Defends against path traversal when names come from `$_GET` or `$argv`. |
| `bridges_load(string $name): bool` | `require_once inc/bridges/{name}/handler.php`. Re-checks `bridges_name_is_valid()` as defence-in-depth before any filesystem access. |
| `bridges_admin_load_all(): void` | Requires the optional `inc/bridges/{name}/admin.php` partial for each active bridge. Called from `admin/index.php` before the render hooks. |
| `bridges_admin_render(string $hook): void` | Calls `{name}_admin_render_{hook}()` for each active bridge that defines it. Current hooks: `button`, `modal`, `js`. |
| `bridges_cluster_icon_url_for(string $name): ?string` | Returns the bridge's `{name}_cluster_icon_url()` if defined and active, else null. |

## What not to do

- **Do not name your bridge in any generic file.** No literal `'kosmos'` in `admin/index.php`, `api/bridge.php`, `inc/clustering.php`, etc. Generic code locates bridges via `TELARIS_BRIDGES` membership and the framework lib. If you find yourself wanting to write `if ($source === 'kosmos')` somewhere outside `inc/bridges/kosmos/`, the correct shape is a new optional hook on the handler interface plus a generic dispatcher that calls it.
- **Do not skip the bridge-name prefix on global identifiers.** PHP's function table is global; collisions are fatal at load time.
- **Do not bypass the dispatcher.** Reach the HTTP entry through `api/bridge.php?name=...` and the CLI entry through `admin/cli/import_bridge.php <name> ...`. Direct calls into the handler file from elsewhere defeat the active-bridge gating and the name validation.
- **Do not write Mocambos-shaped data into `source_facet` for a different bridge.** The column is neutral; put whatever facet your source provides there.

## Reference implementation

The Mocambos bridge is `inc/bridges/mocambos/`. Read it alongside this document when in doubt:

- `inc/bridges/mocambos/handler.php`: shows the HTTP and CLI dispatch pattern, the streaming-NDJSON import path, both optional hooks (`mocambos_cli_args_from_source`, `mocambos_cluster_icon_url`), the shared `_mocambos_import_galaxia()` core called by both entry points.
- `inc/bridges/mocambos/admin.php`: shows the three render functions, the IIFE-wrapped JS, the `window.BRIDGES_REFRESH_UI` registration.
- `inc/bridges/mocambos/sync.php`, `download.php`: shows internal helpers using the `_mocambos_` private prefix.
- `tests/php/Unit/MocambosSyncTest.php`: shows the test conventions and the require path into a bridge directory.
- `img/bridges/mocambos/cluster.svg`: shows the static-asset convention.

## See also

- [`README.md`](README.md): operator-facing overview of Bridges, including how to enable one and how to import from Mocambos.
- `inc/bridges/_lib.php`: the framework source. The header comment summarises the contract from the framework's side.
- `tests/php/Unit/BridgesLibTest.php`: the framework's own test suite, useful as a contract assertion.
