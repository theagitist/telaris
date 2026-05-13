# Telaris - Weaving memory

A 3D interactive node network visualization.

## Setup Instructions

### 1. Web Server Requirements

The application requires:
- **PHP 8.3+** with PDO MySQL extension
- **MySQL 8+**
- **Nginx** (or Apache with mod_rewrite)
- Web server with SSL support (recommended)

### 2. Configuration

Access the setup script in your browser:
```
https://your-domain.com/admin/setup.php
```

Alternatively, accessing `/admin` without configuration will automatically redirect to the setup script.

**Note**: The setup script can only be accessed via web browser, not from the command line.

The setup script follows this 4-step process:

1. **Configure Database Connection**: Prompt for database credentials
   - Database Host (default: localhost)
   - Database Port (default: 3306)
   - Database Name (default: telaris)
   - Database User (default: telaris)
   - Database Password
   - **PHP Requirements**: PHP version and required extensions are displayed on this screen

2. **Create Database Schema**: Automatically creates all required tables
   - Project info table (initialized with default values)
   - Users table
   - Constellations table (default constellation id=0 created by setup and cannot be erased)
   - Nodes table (with JSON column for animation; each node belongs to a constellation)
   - Keywords table (scoped per constellation)
   - Node-keywords junction table
   - API keys table

3. **Configure Website Information**: Prompt for website name and tagline
   - Website Name (default: Telaris)
   - Tagline (default: Weaving memory)
   - Updates the project_info table with your custom values

4. **Create Admin User**: After website configuration, you'll be prompted to create an admin user
   - First Name
   - Last Name
   - Email (used for login)
   - Password (minimum 8 characters)

**Note**: If the setup script cannot write `config.php` due to file permissions, it will display the configuration content in a textarea for manual creation.

### 3. Access Points

After setup, you can access:

- **Main Visualization**: `https://your-domain.com/` or `https://your-domain.com/index.php` — shows the default constellation (id 0). To open a specific constellation by id, use `https://your-domain.com/{id}` (e.g. `/5`) or `?constellation_id={id}`; the path form may require a rewrite rule so `/{number}` is handled by `index.php`. To deep-link a specific wormhole, use `https://your-domain.com/{slug-or-id}/{wormhole-id}` (or `?node={wormhole-id}` as a fallback) — the wormhole's info card opens automatically with a camera fly. The card's share icon copies that permalink.
- **Login Page**: `https://your-domain.com/utils/login.php`
- **Admin Console**: `https://your-domain.com/admin/` (requires admin login)
- **Node Editor**: `https://your-domain.com/edit/` (requires editor or admin login)

## User Types

The application supports three user types:

- **Regular User** (type 0): No special access
- **Editor** (type 1): Can edit nodes through the `/edit/` interface but cannot access admin console
- **Admin** (type 2): Full access to admin console and node editor

### Creating Users

Users can be created:
1. **During Setup**: Admin user is created via `admin/setup.php`
2. **Via Admin Console**: Logged-in admins can create new users through `/admin` interface
3. **Via CLI Script**: Use `admin/cli/create_user.php` to create admin or editor users
4. **Via Database**: Direct SQL insertion (passwords must be hashed using `password_hash()`)

**Important**: All passwords are automatically hashed and salted using PHP's `password_hash()` function with bcrypt.

### CLI Scripts

The application includes CLI scripts in `admin/cli/` for administrative tasks:

- **create_user.php**: Create new admin or editor users interactively
- **hard_reset.php**: Complete reset - drops all database tables and deletes config.php

See `admin/cli/README.md` for detailed documentation on CLI scripts.

## Database Structure

The application uses MySQL 8+ with the following tables:

### constellations
Lists all constellations (each constellation is a set of nodes and keywords). The default constellation has id=0 and is created by setup; it cannot be erased. The main view shows the current constellation’s name and tagline in the top-left info area.
- `id` INT NOT NULL PRIMARY KEY - Constellation identifier (immutable; 0 = default)
- `name` VARCHAR(255) NOT NULL DEFAULT '' - Display name
- `tagline` VARCHAR(500) NOT NULL DEFAULT '' - Short tagline shown in the main view with the constellation name
- `theme` VARCHAR(50) NOT NULL DEFAULT 'cosmic' - Visual theme identifier (cosmic, abstract, rectangles, stripes, tech)

### users
Stores user accounts with authentication information.
- `id` VARCHAR(255) PRIMARY KEY - Unique user identifier
- `email` VARCHAR(255) NOT NULL UNIQUE - User email (used for login)
- `password` VARCHAR(255) NOT NULL - Hashed password (bcrypt)
- `firstname` VARCHAR(100) NOT NULL - User's first name
- `lastname` VARCHAR(100) NOT NULL - User's last name
- `type` INT NOT NULL DEFAULT 0 - User type (0=regular, 1=editor, 2=admin)
- `date_created` TIMESTAMP - Account creation timestamp
- `date_last_login` TIMESTAMP NULL - Last login timestamp

### nodes
Stores 3D network nodes with JSON columns for structured data (MySQL 8 feature). Each node belongs to one constellation.
- `id` INT AUTO_INCREMENT PRIMARY KEY - Node identifier
- `constellation_id` INT NOT NULL DEFAULT 0 - Constellation (FK → constellations.id)
- `name` VARCHAR(255) NOT NULL - Node name
- `description` TEXT - Node description
- `url` VARCHAR(500) NULL - Optional URL for the node (opens in new window when clicked)
- `created_by` VARCHAR(255) NULL - User ID who created the node (FK → users.id)
- `animation` JSON NOT NULL - Animation parameters: `{"radius": float, "theta": float, "phi": float, "speed": float, "phase": float}`
- `created_at` TIMESTAMP - Creation timestamp
- `updated_at` TIMESTAMP - Last update timestamp

### keywords
Stores keywords/tags that can be associated with nodes; each keyword belongs to one constellation (unique per constellation).
- `id` INT AUTO_INCREMENT PRIMARY KEY - Keyword identifier
- `constellation_id` INT NOT NULL DEFAULT 0 - Constellation (FK → constellations.id)
- `keyword` VARCHAR(100) NOT NULL - Keyword text (UNIQUE with constellation_id)
- `created_at` TIMESTAMP - Creation timestamp

### node_keywords
Junction table for many-to-many relationship between nodes and keywords.
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `node_id` INT NOT NULL - Node ID (FK → nodes.id, CASCADE DELETE)
- `keyword_id` INT NOT NULL - Keyword ID (FK → keywords.id, CASCADE DELETE)
- `created_at` TIMESTAMP - Creation timestamp
- UNIQUE constraint on (node_id, keyword_id)

**Note**: Connections between nodes are calculated automatically based on shared keywords. Nodes that share one or more keywords will be connected in the visualization.

### project_info
Stores project metadata with one row per locale (en, es, pt).
- `locale` VARCHAR(10) NOT NULL PRIMARY KEY - Locale code (en, es, pt)
- `name` VARCHAR(2000) NOT NULL DEFAULT '' - Project name
- `description` VARCHAR(2000) NOT NULL DEFAULT '' - Project description / tagline
- `iframe_back_text` VARCHAR(2000) NOT NULL DEFAULT '' - Button text for iframe back link
- `alert_message` VARCHAR(2000) NOT NULL DEFAULT '' - Message shown when closing node link window
- `edit_button_text` VARCHAR(200) NOT NULL DEFAULT 'Edit' - Label for Edit button
- `loading_text` VARCHAR(200) NOT NULL DEFAULT 'Loading' - Loading indicator text

### api_keys
Stores API keys for authentication.
- `id` INT AUTO_INCREMENT PRIMARY KEY - API key identifier
- `api_key` VARCHAR(64) NOT NULL UNIQUE - The API key string
- `name` VARCHAR(255) NOT NULL - Descriptive name for the key
- `description` TEXT - Optional description
- `created_at` TIMESTAMP - Creation timestamp
- `last_used_at` TIMESTAMP NULL - Last usage timestamp
- `is_active` BOOLEAN NOT NULL DEFAULT TRUE - Whether the key is active

### snapshots
Tracks local on-disk full-system snapshots. Excluded from backup dumps (instance-local state).
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `filename` VARCHAR(255) NOT NULL UNIQUE - Snapshot filename inside SNAPSHOTS_DIR
- `created_at` TIMESTAMP - When the snapshot was taken
- `size_bytes` BIGINT - On-disk size
- `created_by` VARCHAR(255) NULL - User ID (FK → users.id, ON DELETE SET NULL)
- `trigger_type` ENUM('manual','scheduled') - How the snapshot was created
- `note` VARCHAR(500) NULL - Optional human-readable note

### snapshot_schedule
Single-row table holding the snapshot scheduler settings.
- `id` TINYINT NOT NULL PRIMARY KEY DEFAULT 1 (always 1)
- `enabled` BOOLEAN NOT NULL DEFAULT FALSE - Master on/off for the daily scheduler
- `hour` TINYINT NOT NULL DEFAULT 3 - Hour of day (0-23, UTC) the daily snapshot should run
- `keep_days` INT NOT NULL DEFAULT 7 - Age-based retention: scheduled snapshots older than this many days are deleted after each scheduled run. Manual snapshots are kept forever.
- `last_run_at` TIMESTAMP NULL - Most recent scheduled run

**Note**: All tables use InnoDB engine with utf8mb4 charset and utf8mb4_unicode_ci collation.

## Testing

The project has a unit and integration test suite covering PHP backend logic and JavaScript frontend modules.

### Prerequisites

- **PHP**: PHPUnit 11 (installed via Composer)
- **JS**: Node.js 22+ built-in test runner (zero dependencies)

### Setup

```bash
# Install PHPUnit (one-time)
php composer install
```

No `npm install` is needed — the JS tests use Node's built-in test runner with no dependencies.

### Running Tests

```bash
# Run everything
npm run test:all

# PHP tests only
php vendor/bin/phpunit

# PHP unit tests only
php vendor/bin/phpunit --testsuite unit

# PHP integration tests only
php vendor/bin/phpunit --testsuite integration

# JS tests only
node --test tests/js/*.test.js
```

### Test Structure

```
tests/
  php/
    bootstrap.php                          # Loads config, db, validation, auth, media-optimize
    Unit/
      DbSlugifyTest.php                    # db_slugify() edge cases
      ValidateSafeUrlTest.php              # URL scheme validation
      SanitizeNodeTypeTest.php             # Node type sanitization
      SanitizeEmbedCodeTest.php            # iframe allowlist, XSS filtering
      HashPasswordTest.php                 # Hash/verify round-trip
      CspCompatibilityTest.php             # Scans templates for inline event handlers
      ClusteringTest.php                   # Adaptive clustering, quality checks, hierarchy
      MocambosSyncTest.php                 # Incremental diff detection for imports
      MediaOptimizeTest.php                # Image/audio/video optimization, frame extraction
      FormatNodesBulkTest.php              # Node API output format, field presence
      CronStripBlockTest.php               # cron_strip_block() preserves unrelated lines
    Integration/
      MigrationAutoIncrementTest.php       # AUTO_INCREMENT + FK migration
      MigrationApiKeysActiveTest.php       # is_active column migration
      BackupRoundTripTest.php              # Export -> file -> import preserves galaxies/keywords/links
  js/
    themes.test.js                         # THEMES structure, getTheme() fallback
    network-manager.test.js                # Focus state, opacity, visibility
```

### What the Tests Cover

**PHP Unit Tests** validate pure functions extracted into `inc/validation.php`, `inc/media-optimize.php`, and `utils/auth.php` — URL validation, embed code sanitization, node type handling, password hashing, slug generation, media optimization (image resize, audio re-encoding, video downscaling, video frame extraction), and node API output format validation. The CSP compatibility test scans public-facing HTML templates for inline event handlers (`onclick=`, `onload=`, etc.) that break nonce-based Content Security Policy.

**PHP Integration Tests** exercise runtime database migrations against a real MySQL connection using temporary test tables (suffixed `_aitest` / `_test`) that are created and dropped per test. The critical test reproduces the AUTO_INCREMENT migration that must drop and re-add foreign keys — the exact scenario that broke production.

**JS Tests** validate the theme registry (`THEMES` object structure, `getTheme()` lookup and fallback) and `NetworkManager` (focus state, opacity lerping, visibility thresholds, fade multiplier).

### Configuration

- `phpunit.xml` — PHPUnit configuration at project root
- `package.json` — npm test scripts (no runtime dependencies)
- Integration tests use the same database connection as the application (from `config.php`)

## Features

### Frontend
- **Visual Themes Support**: Extensible theme system allowing each network to have a unique look and feel.
- **Stripes Theme**: A duplicate of the Abstract theme featuring custom stripe icons.
- **Rectangles Theme**: A duplicate of the Abstract theme featuring custom rectangle icons from a specific asset set.
- **Abstract Theme**: A glitchy, geometric theme using animated icons and a 3D grid background.
- **Cosmic Theme**: The classic starfield aesthetic with planets, rockets, and UFO animations.
- **Tactical HUD Navigation**: Semitransparent cockpit-style interface with system status and real-time filtering.
- **Fuzzy Search**: Real-time filtering of nodes and connections by name or keyword.
- **Dynamic Launch Sequence**: Simplified, immersive rocket launch animation when transitioning to external node links.
- **Monospace Typography**: Unified system-wide "NASA-style" typography for all UI elements and 3D labels.
- 3D visualization with organic animations and vivid pastel-colored node icons.
- Light, semi-transparent vivid pastel connections between nodes based on shared keywords.
- Interactive hover labels and clickable nodes with URL support.
- Orbit controls for camera navigation (drag to rotate, scroll to zoom).
- Idle auto-rotation - the scene slowly rotates when the user is inactive.
- Real-time data loading from API.
- **Image Attribution Overlay**: Optional text overlay on node images in the info view, showing source credits at the bottom-right corner.
- **Node Preview**: View Node action in the editor shows a full info box preview (matching the main view's look and feel) with image, audio, video, description, and keywords.
- **View Constellation**: Quick action to open a node's constellation in the main view from the editor.
- **Editor**: Server-side paginated node list with sortable columns, debounced search, kebab dropdown action menus (View Node, View Constellation, Edit, Duplicate, Delete), and bulk operations (Move, Duplicate, Delete).
- **Admin Console**: Server-side paginated constellation list with sortable node count column, kebab dropdown action menus (Edit, View, Copy URL, Duplicate, Refresh, Delete).

### Backend
- Database-driven node management.
- **Node Duplication**: Duplicate nodes (single or bulk) to the same or a different constellation, copying all content and keyword associations.
- Keywords system for tagging and categorizing nodes.
- Many-to-many relationship between nodes and keywords.
- Automatic connection calculation based on shared keywords (using inverted index algorithm).
- **Server-Side Pagination**: Nodes and constellations APIs support paginated, sorted, and filtered queries for efficient handling of large datasets (10K+ nodes).
- **Media Optimization**: Uploaded images are resized to 1344px (2x retina), icons to 256px; audio is re-encoded to mono 128kbps; video is downscaled to 720p H.264 CRF 28. All optimization is in-place and silent on failure.
- **Video Frame Extraction**: Uploading a video file in the image field automatically extracts the first frame as a JPEG thumbnail (supports MP4, MOV, AVI, MKV, WebM, and other formats).
- **Uploaded File Serving**: Media files stored outside the document root are served via a PHP proxy (`serve-upload.php`) with MIME detection, HTTP Range support for audio/video seeking, and directory traversal protection.
- **Bulk Keyword Loading**: Optimized database access with batch queries to eliminate N+1 performance issues.
- **Constellation Refresh**: Imported constellations can be refreshed directly from the admin dropdown with in-modal confirmation.
- **Backup & Restore**: Admins can download a portable `.telaris-backup` file (gzipped JSON, optional embedded media) of selected galaxies and/or all users, then re-import on the same or a different instance. Two-phase upload wizard inspects the file before any changes are written, with per-galaxy overwrite-or-rename conflict modes and bracket-prefix bulk selection (`[TE]`, `[FT]`, etc.). Live upload progress and server-parse status reduce the perceived "frozen" wait on large files.
- **Snapshots**: Local on-disk full-system backups stored in `SNAPSHOTS_DIR` (recommended: a site-prefixed path outside the app dir such as `/var/backups/starmaps-snapshots`). The Snapshots admin tab supports manual creation, deletion, download, and restore (with `RESTORE` confirmation phrase). Restoring wipes the system back to the snapshot's state and deletes any snapshots created after that point (linear-timeline semantics). Manual snapshot creation shows an indeterminate progress bar with an elapsed-seconds counter so the page doesn't look frozen on large instances. A single daily scheduler (on/off, chosen UTC hour) drives automatic snapshots with age-based retention (default: keep 7 days of scheduled snapshots; manual snapshots kept forever). Enabling the scheduler transparently installs a crontab entry for the PHP user; disabling removes it. The panel shows an aggregate Active / Disabled / Needs-attention status, last-run timestamp, and recent scheduler log output.
- User authentication and authorization with secure password hashing (bcrypt).
- API key authentication for API endpoints.

## Recent changes

### Version 6.9.x — Multigalaxy, PDFs, mail, snapshots polish, keyword canvas, 2D wormhole view

- **2D wormhole view** — alternative visitor-side layout to the 3D scene: every wormhole renders as a small pastel chip on a dot-grid background, distributed via Poisson-disc seeding with strict axis-aligned-rectangle overlap guarantees (Poisson rejects overlapping candidates, a post-settle resolver iterates until clean). Top-center segmented "3D / 2D" switch, per-galaxy opt-in via the Discovery section's new "2D view switch" toggle. Visitor preference persists in `localStorage` (with an inline pre-state script that prevents the switch from flashing on load). Multi-galaxy contexts inherit from the first galaxy in emergent unions or the cluster's own setting. Cards carry their pastel from the canonical palette (`CHIP_FG[colorIndexFor(name)]`); connection lines blend the two endpoints' pastels, scale thickness by shared-keyword count, invisible by default + brighten on hover with endpoints clipped to chip rect edges. Hover panel reuses the 3D scene's `#node-tooltip` (pinned top-right) with a stepped orthogonal connector line. Keyword-chip filter at the bottom works in 2D too (reads `app.activeKeywords`). Clicks: cluster drills in, portal navigates, wormhole opens the info card.
- **Keyword canvas (editor surface)** — per-galaxy `/edit/keyword-canvas.php?galaxy_id=N` route: every keyword renders as a draggable pastel chip on a dim dot-grid SVG. Two authoring layers — continuous positions (drag chips, per-edit attributed via `keyword_positions.moved_by`, append-only history) and discrete named lines (click/drag anchor dots between chips to record an attributed `keyword_relations` row with optional editorial note; lines glue to specific anchor sides). Physics (Stage 1 + 2): springs from `keyword_relations` lines, Coulomb repulsion globally, smoothstep ease-in on every kick; idle float gives every chip a tiny deterministic orbit. Hover thickens + brightens connected lines; new-line flash + anchor pulse during draw. Clicking a chip opens an inspector modal — rename or delete a keyword (with a conflict modal that offers "Change name" or "Merge" when the new name already exists in the galaxy; merge repoints every reference and deletes source).
- **Info window matches the chip color** — `showRichMediaWindow` derives its accent (`--node-accent`, border, ambient glow) from the same `CHIP_FG[colorIndexFor(name)]` pastel everywhere else uses, so opening a wormhole feels like its chip "expanding" into the panel.
- **Editorial provenance data layer** — every editorial table records `created_by` going forward: `nodes`, `keywords`, `node_keywords` (per-tag-application), `galaxy_tags`, `constellations`. All `_by` columns are `VARCHAR(255)` to match `users.id`, FK `ON DELETE SET NULL`, indexed. Legacy rows stay NULL by design ("pre-provenance era" — don't backfill). UI surfacing tracked separately in TODOs.
- **Multigalaxy views** — visitors can see wormholes from multiple galaxies in one 3D scene through four mechanisms: `?galaxies=a,b,c` query-string union, `/[XXX]` name-prefix family union, `/tag/<slug>` tag union, and a first-class **Galaxy Cluster** type with its own slug, theme, and admin-only management UI. Cross-galaxy connections render as dashed bridges. Each wormhole keeps its source galaxy's theme so icons stay recognizable across themes. Bottom-right slide-up strip lists the union members and lets visitors dim non-selected galaxies. The keyword chip strip works in union views too, pooling keywords from every visible wormhole.
- **Cross-galaxy related wormholes** — the info-card "related wormholes" chip row now draws from the source galaxy's *group* (prefix-family siblings ∪ tag-shared galaxies ∪ cluster co-members), so chips can surface wormholes from sibling galaxies. Cross-galaxy chips navigate to the target's permalink; same-scene chips reuse the existing fly-to.
- **PDF wormhole media** — wormholes can carry a PDF as their primary visual, rendered in the info card via vendored Mozilla PDF.js. Admin-configurable max size (default 25 MB). Three-way mutex with image and video; audio remains independent.
- **Outbound mail** — SMTP via Mailgun (PHPMailer 7). Powers a single-use, no-enumeration password reset flow and the bulk-user-creation email-invite stream.
- **Bulk user creation with auto-galaxy** — paste a CSV list of users in the admin Users tab (`email[, firstname, lastname, type, creates_galaxy]`); preview before commit; each new user can be auto-assigned their own galaxy (slug from the email name, collision-safe) and gets a welcome email with their username, the password-setup link, the galaxy URL, and the login URL.
- **Editor productivity** — touched-today filter, bulk-by-keyword (delete or move to galaxy), `/edit/?slug=foo` routing, and keyboard shortcuts (`n`, `/`, `t`, `g`, `?`).
- **Path-versioned JS, site-absolute everywhere** — `/js/vX.Y.Z/foo.js` busts Safari's ES module cache reliably. All asset URLs (theme sprites, API calls, DB-stored upload URLs) are emitted absolute, so visitor permalinks like `/{slug}/{node-id}` don't break on relative-path resolution.
- **Snapshot scheduler is multi-tenant** — the daily-snapshot cron entry is installed transparently by the admin toggle (no sysadmin step) and tagged per-site, so multiple Telaris instances on one host coexist in the same crontab without overwriting each other's entry.

_Older version history (6.7.x and earlier, back to v1.0.7) is maintained in the project's internal notes._

## License

See LICENSE file for details.
