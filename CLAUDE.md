# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

**Telaris** — a 3D interactive node network visualization application. The PHP/MySQL backend serves data through a REST API; the frontend renders a Three.js 3D scene with nodes, connections, and themes directly in the browser.

Current version: **6.9.x** (tracked in `VERSION` file).

**Versioning convention.** Patch bumps for incremental work; minor/major only when explicitly directed. The `VERSION` file is read at runtime to drive Safari-safe ES module path-versioning (`/js/vX.Y.Z/foo.js` → alias). Bump on any visitor-side JS change so old browsers don't run stale modules.

## Dev and production sites

Telaris runs as two sibling sites on this VPS:

- `/var/www/telaris.polivoxia.ca` — production
- `/var/www/starmaps.polivoxia.ca` — development sibling and **source of truth**

Both share the codebase via the `git@github.com:theagitist/telaris.git` repo, but each has its own database and `UPLOAD_DIR` set in its own `config.php`. Develop on **starmaps**; bring changes to telaris only after they've been validated. Code flows starmaps → telaris, never the reverse. Database content, uploads, snapshots, and `config.php` are per-site and must not be cross-pollinated.

## Vocabulary Mapping (Code → UI)

The codebase uses internal names that differ from what users see in the UI:

| Code / DB / API | User-Facing (EN) | User-Facing (ES) | User-Facing (PT) |
|---|---|---|---|
| `constellation` | Galaxy | Galaxia | Galáxia |
| `node` | Wormhole | Agujero de Gusano | Buraco de Minhoca |
| `portal` | Portal | Portal | Portal |

All DB tables, API endpoints, PHP/JS variable names, and URL parameters keep the original internal names (`constellation`, `node`, `portal`). Only UI-facing labels, titles, messages, and tooltips use the new vocabulary.

## Tech Stack

- **Backend**: PHP 8.3+ with PDO (strict types throughout), MySQL 8+, Nginx
- **Frontend**: Three.js via ES module import map (no build step), TailwindCSS (local `js/tailwind.min.js`), DaisyUI 4 (CDN)
- **No build system** — JS/CSS changes take effect immediately; cache-busting is done via `?v=VERSION` query strings

## Key Commands

```bash
# Create a new admin/editor user
php admin/cli/create_user.php

# Full hard reset (drops all tables, deletes config.php)
php admin/cli/hard_reset.php [--force]

# Initial setup is browser-only — navigate to /admin/setup.php

# Import a Mocambos galaxia (interactive — prompts for URL, galaxia, options)
php admin/cli/import_mocambos.php

# Import a Mocambos galaxia (non-interactive — for automation/cron)
php admin/cli/import_mocambos.php --api-base=https://oya.mocambos.net/api/v2 --galaxia=SLUG
#   --list        List available galaxias and exit
#   --no-media    Skip media downloads (faster, nodes still created)
#   --limit=N     Import only the first N items
#   --quiet       Minimal output (errors and summary only)
#   --full        Full re-import (skip incremental diff, delete all nodes first)

# Refresh a Mocambos constellation (interactive — shows list, prompts)
php admin/cli/refresh_constellation.php

# Refresh a Mocambos constellation (non-interactive — for automation/cron)
php admin/cli/refresh_constellation.php --id=N [--no-media] [--limit=N] [--full]
#   --list        List all constellations with import status
#   --full        Full re-import (skip incremental diff, delete all nodes first)

# Backup: portable .telaris-backup file (gzipped JSON)
php admin/cli/backup_export.php --output=FILE [--galaxies=all|1,5,7] [--no-galaxies] [--no-users] [--media=embedded|refs|none] [--quiet]
php admin/cli/backup_import.php --input=FILE [--mode=overwrite|rename] [--rename-suffix=" (restored)"] [--skip-users] [--replace-users [--replace-passwords]] [--no-media] [--inspect-only] [--force] [--quiet]

# Snapshots: full system backups stored on disk in SNAPSHOTS_DIR (or fallback <UPLOAD_DIR>/../snapshots)
php admin/cli/snapshot_create.php [--note="..."]
php admin/cli/snapshot_list.php
php admin/cli/snapshot_restore.php (--id=N | --file=PATH) [--force] [--allow-no-admin]
php admin/cli/snapshot_run_scheduled.php   # cron target; checks the schedule and runs if due
```

Unit tests use PHPUnit: `vendor/bin/phpunit --testsuite unit`. No linters or build steps.

## Architecture

### Backend Layer

All code uses `declare(strict_types=1)`.

**Entry points:**
- `index.php` — loads `inc/bootstrap.php` then `inc/main-view.php`
- `admin/index.php` — admin console (requires admin login)
- `edit/index.php` — node editor (requires editor or admin login)
- `admin/setup.php` — one-time web-based setup wizard

**Core includes:**
- `config.php` — defines `DB_*` constants and `UPLOAD_DIR` (not committed; `config_default.php` is the committed template)
- `inc/db.php` — **all** database logic lives here (connection and queries). Never bypass this file to touch the DB. The `getDB()` function returns a singleton PDO.
- `inc/bootstrap.php` — validates config/DB, detects locale (en/es/pt), resolves constellation from URL path (`/{id}`, `/{slug}`, or `?constellation_id=N`), sets template variables
- `inc/main-view.php` — the HTML shell for the 3D visualization; receives variables from bootstrap

**API directory (`api/`):**
- Each resource has its own file: `nodes.php`, `connections.php`, `keywords.php`, `constellations.php`, `tags.php`
- `apikey.php` — public endpoint that returns the default API key (no auth required)
- `auth.php` — validates `X-API-Key` header (or `Authorization: Bearer` or `?api_key=`)
- `validate.php` — shared input validation helpers
- `mocambos.php` — Mocambos import (web UI)
- All API endpoints (except `apikey.php`) require API key authentication via `requireApiKey()`

**Notable endpoints / query-string modes:**
- `nodes.php?galaxies=1,5,7&no_cluster=1` — multigalaxy union (visitor view)
- `nodes.php?related_to=NODE_ID&limit=N` — group-wide related wormholes for the info-card chip row (see "Related wormholes" below)
- `keywords.php?constellation_id=N&autocomplete=1` — bucketed `{current, siblings, global}` for editor autocomplete (frontend merges + dedupes + sorts alphabetically; pill-styled in the dropdown)
- `tags.php?galaxy_id=N` — same bucketed shape for galaxy-tag autocomplete; `?galaxy_id=N&assigned=1` returns only the tags currently on that galaxy
- `constellations.php?action=cluster_members&id=N` — member galaxy IDs for the admin cluster edit modal

**Auth (`utils/auth.php`):**
- Session-based auth for browser interfaces (`requireEditorOrAdminLogin()`, `requireAdminLogin()`)
- Three user types: 0=regular, 1=editor, 2=admin
- Editors see only constellations assigned to them; admins see all

### Frontend Layer

All JavaScript uses ES modules. Three.js is loaded via an import map defined in `inc/main-view.php`.

**Module hierarchy:**
```
main.js
  └── TelarisNetwork (telaris-network.js)
        ├── apiFetch (api.js)          — adds X-API-Key header to all fetch calls
        ├── createNodeIcon (telaris-node-icons.js) — per-theme node 3D geometry/sprites
        ├── NetworkManager (network-manager.js)    — connection focus/fade state
        ├── GeometryManager (geometry-manager.js)  — cached THREE geometries
        └── getTheme (themes.js)                   — theme definitions
```

**Key globals injected by PHP into the page:**
- `window.TELARIS_API_KEY` — fetched by JS from `api/apikey.php` on init
- `window.TELARIS_CONSTELLATION_ID` — current constellation integer ID (the cluster's own ID in cluster mode; the first listed member in emergent unions)
- `window.TELARIS_THEME_ID` — scene theme identifier string (e.g. `'cosmic'`)
- `window.TELARIS_MULTI_GALAXY_IDS` — list of int member IDs in multigalaxy mode, else `null`
- `window.TELARIS_MULTI_GALAXY_TITLE` — synthesized title in multigalaxy mode (cluster name, `[XXX]`, tag label, or "A + B + N more")
- `window.TELARIS_GALAXY_LIST_ENABLED` / `TELARIS_GALAXY_LIST` — bottom-right strip
- `window.TELARIS_PDF_*` — localized PDF viewer chrome (loading / rendering / N pages / open / download / errors)
- `window.TELARIS_APP_NAME`, `window.TELARIS_ALERT_MESSAGE`, etc. — other localized strings

### Theme System

Themes are defined in `js/themes.js` (exported `THEMES` object). Available themes: `cosmic`, `abstract`, `rectangles`, `stripes`, `tech`. Each theme controls background elements, lighting, animations, and node icon type (`geometry` factories vs. image sprites/planes). Each constellation has a `theme` column in the DB.

### Database / Schema

All tables use InnoDB + utf8mb4. `SCHEMA.sql` is the sole source of truth for the schema (no runtime migrations).

Key relationships:
- `nodes` → `constellations` (many-to-one)
- `keywords` → `constellations` (many-to-one, unique per constellation)
- `node_keywords` — many-to-many junction between nodes and keywords
- Connections between nodes are **computed** (not stored) — any two nodes sharing a keyword are connected
- `project_info` — one row per locale (en, es, pt) for all UI strings
- `user_constellations` — links editor users to permitted constellations
- `api_keys` — API keys with name, description, is_active flag, and usage tracking

**Node fields of note:** `image_url`, `video_url`, `audio_url` (with `audio_autoplay` and `audio_loop` flags), `pdf_url`, `embed_code`, `image_attribution` (a generic credit field — applies to whichever primary visual is active), `icon_url`, `use_image_as_node`, `node_type` (ENUM: `object` or `portal`), `target_constellation_id` (for portal nodes), `is_accentuated`, `show_keywords`, `mucua_name`, `media_type`, `source_created_at`, `import_slug` (clustering and import sync fields).

**Primary-visual mutex.** A wormhole has at most one of `{image_url, video_url, pdf_url}` set at a time. Audio is independent — pairs cleanly with any primary visual. Enforced by `applyVisualMutex()` in `api/nodes.php` (priority pdf > image > video on conflict). Existing video↔audio mutex (audio dropped when video set) is preserved.

**Constellation extras (multigalaxy).** `constellations.type` ENUM(`'galaxy'`, `'cluster'`); `show_galaxy_list` bool (per-cluster opt-in for the visitor's galaxy-list strip). New tables: `galaxy_tags(constellation_id, tag_slug, tag_label)` for /tag/foo unions; `galaxy_cluster_members(cluster_id, member_id, position)` for cluster member lists. Existing galaxy queries (`db_get_constellations`, `db_get_constellations_paginated`, `db_get_constellations_for_user`, `db_get_constellations_by_name_prefix`, `db_get_galaxies_for_tag`) filter `type='galaxy'` so clusters don't bleed into editor dropdowns.

**Other tables added since v6.5:** `password_reset_tokens(token_hash, user_id, expires_at, used_at)` (single-use, SHA-256 hashed, 24h TTL by default). `project_info.pdf_max_bytes` global setting (default 25 MB; configurable via admin Global Settings).

### Auto-Clustering

Constellations with many nodes are dynamically grouped into navigable clusters. The clustering engine (`inc/clustering.php`) uses adaptive logic: the base threshold is 80 nodes, but clustering is only applied if the result is meaningful. Quality checks skip clustering when:
- Fewer than 3 groups would be produced
- One dominant group contains >80% of all nodes
- More than half the items are single-node promotions (too fragmented)

When clustering does apply, it uses a cascade:
1. **Mucua** (origin community) — for Mocambos imports
2. **Media type** (imagem, video, audio, arquivo, blog)
3. **Date** (year, then year-month)
4. **Alphabetical** (A-F, G-L, M-R, S-Z)

Each cluster appears as a special 3D node. Clicking drills in; back button and breadcrumb navigate the hierarchy. The API supports `&cluster=KEY` and `&no_cluster=1` params.

### Mocambos Integration

**Import files:**
- `api/mocambos.php` — web UI import (validate, list galaxias, import)
- `admin/cli/import_mocambos.php` — CLI import (interactive or non-interactive)
- `admin/cli/refresh_constellation.php` — CLI refresh (delegates to import)
- `inc/mocambos-sync.php` — shared incremental diff logic
- `inc/mocambos-download.php` — shared media download function

**Incremental refresh:** Re-imports compute a diff by matching nodes on `import_slug`. Only additions, modifications, and deletions are applied. Use `--full` flag (CLI) or `full_refresh: true` (API) to force a full re-import.

### Backup & Snapshots

**Engine:** `inc/backup.php` builds, inspects, and restores `.telaris-backup` files (gzipped JSON envelope, format version 1). Cross-references inside the dump use `ref` strings (gal-N, kw-N, node-N, media-N), slugs, and emails so restores are independent of auto-increment IDs on the target instance. `inc/snapshots.php` is a thin layer that stores backups on disk and tracks them in the `snapshots` table.

**What's included:** galaxies (constellations + nodes + keywords + node-keyword links + per-galaxy editor assignments) and users (with hashed passwords + assigned galaxy slugs). Media is `embedded` (base64), `refs` (URLs only), or `none`.

**What's excluded** (instance-local): `api_keys`, `project_info`, `snapshots`, `snapshot_schedule`.

**Restore modes:** `granular` (per-galaxy: overwrite-in-place by slug match, or rename-with-suffix on conflict) and `wipe_all` (snapshot path: deletes everything except instance-local tables, recreates from the dump, repoints `default_constellation_id`). Snapshot restore also deletes any snapshots with `created_at` newer than the restored one (linear-timeline semantics).

**Storage:** snapshot files live in `SNAPSHOTS_DIR` (defined in `config_default.php`, defaults to `<UPLOAD_DIR>/../snapshots` if undefined). Admin endpoints under `admin/backup/` and `admin/snapshots/` are session-auth gated; CSRF token validated on every mutation.

**Memory and timeout:** `snapshot_create()` in `inc/snapshots.php` calls `ini_set('memory_limit', '512M')` + `set_time_limit(0)` at entry. `backup_build_dump` holds the whole DB + embedded media in memory before gzipping (~44 MB for a moderate install), which OOMs against PHP-FPM's default 128 M. The bump is a no-op for CLI (already unlimited) and necessary for the admin UI / cron paths.

**Scheduler cron** (`inc/cron.php`). When the admin enables the daily snapshot toggle, the app installs a `*/15 * * * *` entry into the PHP user's (`www-data`) own crontab — no sysadmin step needed. The block is bracketed by **site-tagged markers** (`# >>> telaris snapshot scheduler: <hostname> >>>`) so multiple Telaris instances on the same host coexist in one crontab; each install only touches its own block. Pre-site-tag legacy blocks are migrated on next install only if their body references the current site's script path (`cron_strip_block` checks). `admin/snapshots/list.php` self-heals on every load: if the schedule is enabled but the cron entry is missing, it reinstalls.

**Imported constellations** are read-only in the editor.

**Node URLs:** Built using slug aliases from the Mocambos API: `{mucua_public_uri}/pt-BR/midia/{galaxia_slug}/{mucua_slug}/{item_slug}`. Each mucua can have its own `public_uri` (e.g. `oya.mocambos.net`, `baobaxia.net`); falls back to the API host when not set.

**Media downloads:** Use the content hash from the API's `content[].hash_sum` field: `{api_base}/{galaxia_slug}/{mucua_slug}/acervo/download/{item_slug}/{hash_sum}`.

### Node Duplication

Nodes can be duplicated (single or bulk) to the same or a different constellation via the editor. The API accepts `POST` with `{ "duplicate_from": nodeId, "constellation_id": targetId }`. Duplicated nodes get a "(Copy)" name suffix, fresh random animation, and all keyword associations copied. Import metadata (`import_slug`, `mucua_name`, etc.) is NOT copied.

### Server-Side Pagination

The editor and admin console use server-side pagination for large datasets:

**Nodes API** (`api/nodes.php`):
- `?page=N&per_page=N&sort=COLUMN&order=asc|desc&filter=TEXT` — paginated envelope `{nodes, total, page, per_page}`
- `?id=N` — single node fetch (for edit modal)
- Without `?page=`, returns the flat array (used by the 3D frontend)

**Constellations API** (`api/constellations.php`):
- `?page=N&per_page=N&sort=COLUMN&order=asc|desc&filter=TEXT` — paginated envelope `{constellations, total, page, per_page}` with `node_count` and `is_default` fields
- Without `?page=`, returns the flat array (used by dropdowns, 3D frontend)

### Performance Optimizations

- `db_format_nodes_bulk()` / `db_get_keywords_for_nodes_bulk()` — batch keyword loading in a single query instead of one per node (N+1 fix)
- `db_get_connections()` uses an inverted index algorithm (keyword→nodes map) instead of O(n²) pairwise comparison
- `db_get_nodes_by_import_slug()` uses bulk keyword loading for import diff computation

### Global Search

The search bar queries all nodes in the constellation (not just visible ones) via `&search=QUERY` on the nodes API. Results include `cluster_path` so clicking a result navigates to the correct cluster level.

### File Uploads

Uploaded files are stored at `UPLOAD_DIR` (defined in `config.php`, external to the app directory). Nginx serves them via an alias at `/uploads/`. Nodes can have an image, video, audio, and/or embed code.

### Asset URL absolution

**Visitor URLs come in multiple shapes** — `/`, `/{slug}`, `/{slug}/{node-id}` (permalink), `/[XX]` (prefix union), `/tag/foo`, etc. Relative URLs resolve against the current document path, so a path like `js/v6.9.21/foo.js` works on `/{slug}` but resolves to `/{slug}/js/...` on `/{slug}/{node-id}` and 404s. **Everything that ends up in the browser must therefore be site-absolute** (leading `/`) or a full URL.

Chokepoints to use, never bypass:

- **PHP API output for stored URLs**: `db_normalize_asset_url(?string $url): ?string` in `inc/db.php` prepends `/` to relative paths; passes through paths that already start with `/`, `http(s)://`, `data:`, or `blob:`. Applied to `image_url`, `icon_url`, `audio_url`, `video_url`, `pdf_url` in both `db_format_node` and `db_format_nodes_bulk`.
- **Versioned JS**: `asset_versioned_js_url($appVersion, $rel)` in `inc/bootstrap.php` returns `/js/vX.Y.Z/<rel>`. Used by `<script src=>` and importmap values in `inc/main-view.php` (importmap keys keep their `./` form; *values* are absolute).
- **Theme sprite paths**: `js/themes.js` lists icons as `/img/themes/<theme>/icon_NNN.png` — absolute.
- **API calls from JS**: `js/telaris-3d.js` and friends use `/api/...` — never `api/...`.

When adding a new visitor-facing asset reference, route it through `db_normalize_asset_url` (for stored URLs) or hardcode the absolute path. Symptoms of a missed one: the feature works on `/{slug}` but breaks on a permalink. Common failure modes — invisible sprite textures (silent texture-load failure), 404 chains during bootstrap that leave the scene blank.

### Discovery features (per-galaxy opt-in)

A family of per-galaxy toggles in the admin/editor's "Discovery" section of the galaxy edit modal. Every flag is off by default — existing galaxies look identical until an editor opts in. All settings live on the `constellations` table and are managed by `db_get_constellation_tour_config` / `db_set_constellation_tour_config`. New columns auto-migrate via `db_ensure_constellations_tour_columns()`. The shared form handler is `inc/galaxy-update.php`'s `handle_galaxy_update_post()`, used by both `admin/index.php` and `edit/index.php`.

**Auto-tour** (`tour_enabled` + `tour_start_mode` / `tour_idle_seconds` / `tour_node_selection` / `tour_random_count` / `tour_default_dwell` / `tour_loop`, plus the `constellation_tour_keywords` junction for `node_selection = tagged`). Auto-navigates visitors through nodes, opening each rich-media card and playing audio/video to its `ended` event (or for the dwell duration, scaled by description reading time at 180 wpm). Bezier camera arc with random perpendicular swing; spotlight halo + floating label that ease in over ~600ms; non-spotlight wormholes dim to 30% opacity/emissive. Three start modes: `manual` (Play button), `idle` (after N seconds inactive), `immediate` (3s grace then start). Four node selections: `all`, `accentuated`, `random_n`, `tagged`. The Discovery section also has a "Preview tour" button that opens `?tour=preview` to audition without saving the start mode. Mobile (viewport < 768px) is excluded.

**Idle spotlight** (`idle_spotlight_enabled`, `idle_spotlight_selection`, `idle_spotlight_idle_seconds`). When the visitor has been idle for N seconds, fly the camera to a random wormhole (all or accentuated only) and open its info card with the same halo + dim + dwell-bar treatment as the tour. After the card closes the idle watch re-arms. Co-exists with the auto-tour; the second one to fire bails if the card is already open.

**Keyword chip strip** (`keyword_chips_enabled`). Top-N most-used keywords for the current galaxy as text-only filter chips at the bottom of the visitor view; click to dim non-matching wormholes (OR-match across active chips). Up to 40 chips emitted in random order, CSS-clipped to two lines, so a different sample surfaces each load.

**Related wormholes** (`related_nodes_enabled`). When an info card opens, dim everything except the current node + nodes sharing keywords; show up to 5 click-to-jump chips at the bottom of the card. Click → tour-style camera fly to the target. **The chip pool spans the galaxy's *group*** (prefix-family siblings ∪ tag-shared galaxies ∪ cluster co-members ∪ self), so a wormhole's chips can surface related wormholes from sibling galaxies even when the visitor is browsing a single galaxy. Cross-galaxy candidates get a stochastic order boost (~70% chance of outranking same-galaxy candidates within each shared-keyword tier) so the chip row doesn't look parochial. Backed by `db_get_group_galaxy_ids()` and `db_get_related_nodes()` in `inc/db.php`, served via `api/nodes.php?related_to=NODE_ID&limit=N`. Keyword matching is by lowercased name (not `keyword_id`) so it works across galaxies where each galaxy has its own copy of e.g. "Ideology". Clicking a chip whose target is in a sibling galaxy navigates to `/{slug}/{node-id}` (the visitor permalink); same-scene clicks reuse the existing fly-to flow.

**Per-node use-image-as-icon** (`nodes.use_image_as_node`, default off). Renders the node's `image_url` as the 3D icon instead of the theme icon. The Discovery section has a bulk action that flips it on/off for every wormhole in the galaxy in one POST.

**Visitor permalinks.** `/{galaxy-slug-or-id}/{wormhole-id}` opens that wormhole's card on load (with camera fly). `?node=ID` is a query-string fallback. The card has a "share" icon next to the close button that copies the permalink. The portal-back button (top-left) is also shown when `document.referrer` is same-origin; clicking it falls back to `history.back()` when the portal/cluster navigation stack is empty — so cross-galaxy chip clicks are reversible. On permalink loads, the load fade-in animation is **skipped entirely** (both the initial `_portalFadeInMultiplier=0` in `loadData` and the post-warp fade in `showBeginButton`) so the target scene appears instantly rather than fading up over ~3s while the auto-open camera fly fights with the multiplier.

**Frontend modules.** Each lives in its own file: `js/auto-tour.js` (`TourController`), `js/idle-spotlight.js` (`IdleSpotlightController`), `js/keyword-chips.js` (`KeywordChipsController`). Per-card behaviors (related row, dim, halo, label, ease-in) are in `js/telaris-3d.js`. The shared edit modal is the partial at `inc/partials/galaxy-edit-modal.php` plus `js/galaxy-edit-modal.js`. All discovery configs are injected into `window.TELARIS_*` globals by `inc/bootstrap.php` + `inc/main-view.php`. Backup/restore preserves all tour fields + keyword selections via `inc/backup.php`.

### Editor productivity

`/edit/` accepts `?slug=foo` in addition to `?constellation_id=N` (slug takes precedence). The wormhole list has three quick controls next to "New Wormhole":
- **Touched today** — toggle that filters to nodes whose `updated_at >= today 00:00`. Implemented as a `touched_today=1` query param on `api/nodes.php` paginated mode, threaded into `db_get_nodes_paginated()`.
- **Bulk by keyword** — modal that lists keywords in the current galaxy with usage counts; pick one + an action (delete or move-to-galaxy). API: `POST /api/nodes.php` with `{action: 'bulk_by_keyword', constellation_id, keyword_id, op: 'delete'|'move'|'count', target_constellation_id?}`. Backed by `db_get_node_ids_with_keyword()` and `db_bulk_move_nodes_by_keyword()`.
- **Keyboard shortcuts** — `n` (new wormhole), `/` (focus search), `t` (touched-today filter), `g` (galaxy settings), `?` (help modal). Ignored while typing in any input or while a `<dialog>` is open. The `?` button next to the controls opens the help.

### Multigalaxy (cross-constellation views)

Visitors can see wormholes from multiple galaxies in a single 3D scene through four mechanisms (full design + history in `docs/multigalaxy.md`):

1. **Query string** — `?galaxies=slug-or-id,slug-or-id,...` unions the listed galaxies. Cheapest entry, no editorial step.
2. **Name prefix** — `/[XXX]` (also `/%5BXXX%5D`) unions every galaxy whose name starts with the literal `[XXX]` token. Case-insensitive.
3. **Tag** — `/tag/<slug>` unions every galaxy carrying that tag. Tags are managed in the galaxy edit modal (chip input with autocomplete from siblings + global). Slug derivation via `db_slugify`; canonical display label is the most-frequent label across assigned galaxies.
4. **Galaxy Cluster type** — first-class object with its own slug, title, theme, tagline, permalink. Admin-only management via the dedicated **Clusters** tab in `/admin/`. Strictly a view (no native wormholes).

All four converge on the same downstream pipeline. `inc/bootstrap.php` populates `$multiGalaxyIds` (list of int member galaxy IDs) plus `$multiGalaxyTitle`; `inc/main-view.php` exposes them as `window.TELARIS_MULTI_GALAXY_IDS` and `window.TELARIS_MULTI_GALAXY_TITLE`. The frontend fetches `api/nodes.php?galaxies=…` instead of `?constellation_id=…` and renders the union.

**Per-node origin theme.** Each wormhole's 3D icon uses its source galaxy's theme so it stays recognizable across themes; the scene theme remains global (the union's chosen theme). Driven by `nodes.constellation_theme` (joined into the node payload by `db_format_nodes_bulk`) and per-node branching in `js/telaris-3d.js`'s `createNodes`.

**Cross-galaxy bridges.** When two wormholes from different galaxies share a keyword text, they're connected with a subtle dashed line (`THREE.LineDashedMaterial`) instead of the cylinder used for intra-galaxy connections. Detection is client-side (`n1.userData.constellation_id !== n2.userData.constellation_id` in `createConnections`). Per-galaxy discovery features (auto-tour, idle spotlight, related-nodes) are disabled in any union view. Keyword chips are the exception: they pool from every visible wormhole, so the strip is enabled in a union view iff the current constellation (cluster row, when applicable) or at least one member galaxy has `keyword_chips_enabled`. Click-to-dim then filters across the whole union.

**Galaxy list strip.** Bottom-right slide-up button labelled "Galaxies · N" reveals a chip column for the active members. Click a galaxy chip to dim wormholes from other galaxies (multi-select OR-match). Default ON for emergent unions (`?galaxies=`, `/[XX]`, `/tag/`); per-cluster toggle in admin (default OFF). Implemented in `js/galaxy-list-strip.js`; dim multiplier added to `updateNodes` in `js/telaris-3d.js`.

### PDF wormhole media

A wormhole can carry a PDF as its primary visual. Schema: `nodes.pdf_url` auto-migrated by `db_ensure_nodes_pdf_url_column()`. Validation: MIME type `application/pdf` + magic-byte sniff (`fileHasPdfMagic` checks for `%PDF-`). Size cap default 25 MB; admin can change via the **PDF max size (MB)** input on the Global Settings tab (stored in `project_info.pdf_max_bytes`; read at upload time via `effectivePdfMaxBytes()` so the cap is hot-reloaded without a redeploy).

Visitor renderer: `js/pdf-viewer.js` lazy-loads Mozilla **PDF.js** (vendored at `js/vendor/pdfjs/pdf.js` + `pdf.worker.js`; renamed from .mjs so nginx serves them as `application/javascript`). Rich-media card slot `rm-pdf-wrap` includes a status line, "Open in new window" + "Download" links, and a scrollable canvas pane. CSP gains `worker-src 'self' blob:` for the PDF.js worker. `.gitignore` has a negation `!js/vendor/` so PDF.js is committed even though the top-level `vendor/` rule is in force.

### SMTP / mail

`MAIL_*` config constants in `config.php` (placeholders in `config_default.php`). Outgoing mail through Mailgun via PHPMailer 7 (vendored via composer; needs `composer install` on each instance). Helper at `inc/mail.php` exposes `mail_send($to, $subject, $html, $textFallback?, $toName?)` and `mail_is_configured()`; failures are logged but never thrown.

### Password reset flow

`/utils/forgot.php` accepts an email and emits a one-time reset link (no email enumeration — same generic message regardless of whether the email exists or whether mail succeeds). `/utils/reset.php?token=…` validates the token (single-use, 24h TTL, SHA-256 hashed in `password_reset_tokens`) and lets the user set a new password via `password_hash(..., PASSWORD_DEFAULT)`. `db_create_password_reset_token` invalidates any prior unconsumed tokens for the same user when a fresh request comes in. "Forgot your password?" link added to `/utils/login.php`.

### Bulk user creation (admin)

`inc/bulk-users.php` exports `bulk_users_parse(string $input): list<row>` and `bulk_users_apply(list<row>, string $resetUrlBase): array<report>`. Editors paste TSV (auto-detected, paste-from-spreadsheet friendly) or CSV with columns `email[, firstname, lastname, role, galaxies]`. Existing emails are skipped with a per-row report; new users get a placeholder password they never see plus a 7-day setup reset link by email (uses the same token flow as forgot-password). Admin UI: dialog `#bulk_users_modal` with two-step preview→commit, opened via the "Bulk import" button on the Users tab.

### Localization

Supported locales: `en`, `es`, `pt`. Detected from `?lang=` query param or `Accept-Language` header. A language switcher in the HUD allows users to switch. All visitor-facing UI strings come from the `project_info` table (~42 localized keys; keys after v6.9 cover multigalaxy taglines and PDF viewer chrome). New columns are auto-migrated via `db_ensure_project_info_columns()`.

**Editor and admin pages remain English by convention.** Mail templates also default to English. Visitor-facing only is the bar.

### URL Routing

Requires Nginx rewrite rule so `/{number}` and `/{slug}` paths are handled by `index.php`. Supported patterns:
- `/` — default constellation
- `/{number}` — constellation by ID (galaxy or cluster)
- `/{slug}` — constellation by slug (galaxy or cluster). Cluster slugs pivot to multigalaxy mode automatically.
- `/{slug-or-id}/{node-id}` — open that wormhole's info card on load (visitor permalink)
- `/[XXX]` (or `/%5BXXX%5D`) — multigalaxy union of every galaxy whose name starts with the literal `[XXX]`
- `/tag/<slug>` — multigalaxy union of every galaxy carrying the tag
- `?constellation_id={number}` — constellation by ID (query param fallback)
- `?galaxies=slug-or-id,…` — multigalaxy query-string union; with optional `&theme=<id>` to override the inherited theme
- `?node={number}` — open that wormhole's card (query param fallback)
- `?tour=preview` — force the auto-tour to start regardless of the configured start mode (used by the editor's Preview button)

### CSP / Cloudflare gotcha

This site sits behind Cloudflare. Two operational notes:

- **Cloudflare Insights** auto-injects a beacon from `static.cloudflareinsights.com`. Every page with a strict CSP allows it explicitly: `script-src` adds `https://static.cloudflareinsights.com`, `connect-src` adds `https://cloudflareinsights.com`. Files: `inc/main-view.php`, `edit/index.php`, `admin/index.php`, `utils/login.php`, `utils/forgot.php`, `utils/reset.php`, `utils/frame.php`.
- **Cloudflare Rocket Loader**, if enabled for the domain, rewrites inline event handlers (`onfocus`, `oninput`, `onclick`) and silently breaks parts of the editor in ways CSP can't fix. Disable it in the Cloudflare dashboard for any Telaris hostname.

### Cache Busting

JavaScript assets are served from versioned **paths**, not query strings: `/js/v6.6.9/main.js`. The path-based scheme is the only reliable way to bust Safari's ES module cache, which ignores query strings for module dedup. The version is read from the `VERSION` file at runtime by `inc/bootstrap.php` (into `$appVersion`) and emitted by `asset_versioned_js_url()`; `<script>` tags and import map entries use this helper.

The nginx vhost must include an alias rule that maps `/js/vX.Y.Z/foo.js` to `/js/foo.js` and sets `Cache-Control: immutable`. `inc/bootstrap.php` probes the rule once per version (`asset_versioned_paths_ok`) and caches the result at `var/nginx-paths-VERSION.ok`. If the probe fails, `inc/main-view.php` renders a fixed-position red banner at the top of every page that shows the exact nginx snippet to install. The banner disappears once the rule serves `200 OK` for the current version's `main.js`.
