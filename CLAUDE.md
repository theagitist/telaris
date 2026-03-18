# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

**Telaris** — a 3D interactive node network visualization application. The PHP/MySQL backend serves data through a REST API; the frontend renders a Three.js 3D scene with nodes, connections, and themes directly in the browser.

Current version: **6.0.0** (tracked in `VERSION` file).

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
php admin/cli/refresh_constellation.php --id=N [--no-media] [--limit=N]
#   --list        List all constellations with import status
```

There are no test suites, linters, or build steps in this project.

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
- Each resource has its own file: `nodes.php`, `connections.php`, `keywords.php`, `constellations.php`
- `apikey.php` — public endpoint that returns the default API key (no auth required)
- `auth.php` — validates `X-API-Key` header (or `Authorization: Bearer` or `?api_key=`)
- `validate.php` — shared input validation helpers
- All API endpoints (except `apikey.php`) require API key authentication via `requireApiKey()`

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
- `window.TELARIS_CONSTELLATION_ID` — current constellation integer ID
- `window.TELARIS_THEME_ID` — theme identifier string (e.g. `'cosmic'`)
- `window.TELARIS_APP_NAME`, `window.TELARIS_ALERT_MESSAGE` — localized strings

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

**Node fields of note:** `image_url`, `video_url`, `audio_url` (with `audio_autoplay` and `audio_loop` flags), `embed_code`, `node_type` (ENUM: `object` or `portal`), `target_constellation_id` (for portal nodes), `is_accentuated`, `mucua_name`, `media_type`, `source_created_at`, `import_slug` (clustering and import sync fields).

### Auto-Clustering

Constellations with >50 nodes are dynamically grouped into navigable clusters. The clustering engine (`inc/clustering.php`) uses a cascade:
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

**Imported constellations** are read-only in the editor.

### Global Search

The search bar queries all nodes in the constellation (not just visible ones) via `&search=QUERY` on the nodes API. Results include `cluster_path` so clicking a result navigates to the correct cluster level.

### File Uploads

Uploaded files are stored at `UPLOAD_DIR` (defined in `config.php`, external to the app directory). Nginx serves them via an alias at `/uploads/`. Nodes can have an image, video, audio, and/or embed code.

### Localization

Supported locales: `en`, `es`, `pt`. Detected from `?lang=` query param or `Accept-Language` header. A language switcher in the HUD allows users to switch. All UI strings come from the `project_info` table (30 localized keys). New columns are auto-migrated via `db_ensure_project_info_columns()`.

### URL Routing

Requires Nginx rewrite rule so `/{number}` and `/{slug}` paths are handled by `index.php`. Supported patterns:
- `/` — default constellation
- `/{number}` — constellation by ID
- `/{slug}` — constellation by slug
- `?constellation_id={number}` — constellation by ID (query param fallback)

### Cache Busting

JavaScript modules and assets use `?v=VERSION` query strings. When bumping the version, update:
1. `VERSION` file
2. The `?v=` strings on `<script>` and `<link>` tags in `inc/main-view.php`, `admin/index.php`, `edit/index.php`
3. The import map version entries
