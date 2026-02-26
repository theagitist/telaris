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

- **Main Visualization**: `https://your-domain.com/` or `https://your-domain.com/index.php` — shows the default constellation (id 0). To open a specific constellation by id, use `https://your-domain.com/{id}` (e.g. `/5`) or `?constellation_id={id}`; the path form may require a rewrite rule so `/{number}` is handled by `index.php`.
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
- `theme` VARCHAR(50) NOT NULL DEFAULT 'cosmic' - Visual theme identifier (cosmic, abstract, rectangles, stripes)

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

**Note**: All tables use InnoDB engine with utf8mb4 charset and utf8mb4_unicode_ci collation.

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

### Backend
- Database-driven node management.
- Keywords system for tagging and categorizing nodes.
- Many-to-many relationship between nodes and keywords.
- Automatic connection calculation based on shared keywords.
- User authentication and authorization with secure password hashing (bcrypt).
- API key authentication for API endpoints.

## Version History

### Version 5.4
- **Audio Integration**: Introduced an ethereal, generative background soundscape for immersive navigation.
- **Interactive Sound Effects**: Added randomized, low-fi glitch and electric sounds for node interaction and window feedback.
- **Enhanced Loading Flow**: Implemented a "BEGIN" button sequence with a 3D-style pulsating indicator and smooth network fade-in.
- **Sound Controls**: Integrated a global sound toggle in the HUD navigation panel.

### Version 5.3
- **MP4 Video Support**: Added full support for uploading and playing MP4 videos on nodes, with integrated autoplay settings.
- **Improved Node Media Handling**: Implemented mutual exclusivity for audio and video assets per node to optimize storage and playback.
- **Upload Progress Tracking**: Integrated real-time progress bars and percentage indicators for media uploads in the Node Editor.
- **Automated Database Migrations**: Centralized and automated the runtime database schema updates to ensure consistency across all environments.
- **Enhanced Storage Management**: Resolved permission and path issues for external storage mounts using symbolic links.

### Version 5.2.3
- **Video Playback Fixes**: Resolved an issue where video assets were not correctly mapped to node data in the visualization.
- **Improved Cache Busting**: Synchronized project versioning to ensure latest frontend logic is loaded.
- **Stability**: Refined database connection handling and automated migrations.

### Version 5.2.2
- **Constellation Duplication**: Added ability to clone entire constellations including all nodes, keywords, and physical assets (images/audio).
- **Portal Description Window**: Implemented an intermediate information window for Portal nodes with descriptions, requiring explicit user confirmation ("Open the Portal") before traversal.
- **Enhanced Launch Flow**: Replaced the automatic countdown with a manual launch button for all nodes containing descriptions in the frame view.
- **Improved Localization**: Integrated new localized strings for portal and launch interaction across English, Spanish, and Portuguese.

### Version 5.2.1
- **UI Consistency Fix**: Standardized all dropdown list boxes across the Admin Console and Node Editor to use a refined, uniform aesthetic (DaisyUI select-sm style).
- **Asset Version Synchronization**: Unified project versioning across all core files and implemented synchronized cache-busting strings for all JavaScript modules.

### Version 5.2.0
- **Stripes Theme**: Added a new theme utilizing custom stripe icons, continuing the Abstract theme architecture.
- **Enhanced Asset Management**: Implemented strict standard file (644) and directory (755) permission enforcement for all theme assets.

### Version 5.1.0
- **Rectangles Theme**: Introduced a new theme based on the Abstract architecture but utilizing custom rectangle icons.
- **Theme Visibility Fixes**: Resolved a critical issue where image-based icons (sprites and planes) were not correctly tagged for the animation loop, ensuring full visibility across all themes.
- **Advanced Cache Busting**: Implemented comprehensive version-based cache busting for the theme system and all core JavaScript modules via an updated import map.
- **Asset Permission Standardization**: Standardized file permissions for all theme-specific assets to ensure consistent server-side delivery.

### Version 5.0.0
- **Visual Themes System**: Introduced a core architecture for switchable themes. Each constellation can now have its own unique visual identity (backgrounds, lighting, animations, and icons).
- **Abstract Theme**: Implemented a new "Abstract" theme featuring a glitchy 3D grid background, randomized image-based icons with animated glitch effects, and specific lighting.
- **HUD Terminology Refinement**: Updated the main navigation HUD to use more theme-neutral terms (e.g., "NODES" instead of "SYSTEMS", "SEARCH" instead of "SCAN SYSTEM") and added full localization for these terms.
- **System Version Visibility**: Added the current system version to the Admin Global Settings and Setup pages for easier troubleshooting.
- **Standardized Asset Pipeline**: Implemented an automated process for converting and sequentially naming theme-specific icons.

### Version 4.0.0
- **Selectable Default Constellation**: Implemented dynamic default constellation support. Administrators can now choose which network appears at the root of the site via the Admin Console.
- **Bulk Node Actions**: Added checkboxes and a bulk action bar to the Node Editor, allowing for simultaneous deletion or moving of multiple nodes across constellations.
- **Safe Constellation Deletion**: Enhanced the deletion workflow with mandatory name confirmation and automatic cascading cleanup of referencing portals from other networks.
- **CRT-Style Tooltips**: Upgraded the node tooltip system with real-time 3D tracking, continuous hover detection, CRT-style rounded corners, and a subtle interlaced background effect.
- **Improved UI Notifications**: Replaced static layout-disrupting messages with floating, auto-hiding top-center toasts in both Admin and Edit views.
- **Automatic Schema Updates**: Integrated the new `default_constellation_id` setting into the automated database synchronization logic.

### Version 3.5.0
- **Tactical HUD Interface**: Completely redesigned the navigation panel into a cockpit-inspired HUD with glassmorphism.
- **Fuzzy Search**: Added real-time "SCAN SYSTEM" functionality to the HUD, allowing users to filter nodes and connections instantly.
- **Launch Redesign**: Revamped the redirection window with a minimalist SVG rocket launch sequence and dynamic status updates.
- **Typography Unification**: Switched all UI and 3D elements to a unified monospace font for a consistent technical aesthetic.
- **Cache Busting**: Implemented version-based cache busting for all JS modules and assets to ensure immediate delivery of updates.
- **Visual Refinement**: Tuned camera distances, node scaling, and connection thickness for better visual balance and intimacy.
- **Rendering Fixes**: Resolved transparency layering issues with the station ring and nebulas using strict render order management.

### Version 3.0.0
- **Editor constellation access**: Editors see only constellations assigned to them; admins see all. New `user_constellations` table links users (editors) to constellations.
- **Edit User**: Multi-select list to choose which constellations an Editor can access (Admin Console → Users → Edit).
- **Create User**: Option "Create a new constellation for this user" (on by default) with a name field (defaults to email); creates the constellation and grants the user access when type is Editor.
- **Admin Constellations**: View button opens constellation main view in a new window; Copy button copies constellation URL with confirmation toast.
- **Setup**: Schema creates all required tables (including `user_constellations`); forms use defaults and placeholders; no DB backwards-compatibility code.
- **Database**: All ensure/migration logic removed; schema is created by setup only.

### Version 2.0.2
- **Constellations**: Added support for multiple constellations (sets of nodes and keywords). New `constellations` table; nodes and keywords have `constellation_id`. Setup creates a default constellation (id=0) that cannot be erased.

### Version 2.0.1
- **Favicon**: Sun icon on black background added as favicon across all pages.
- **Mouse wheel zoom**: Mouse wheel zooms; trackpad zoom remains pinch-only, two-finger scroll rotates.
- **Admin UI**: User list uses HTML table for reliable header/content alignment; sorting reorders full rows.
- **Database**: Removed all backwards-compatibility and migration code; `project_info` is one row per locale (en, es, pt) only.

### Version 2.0.0
- **Modular structure**: Main app split into `inc/bootstrap.php`, `inc/main-view.php`; slim `index.php` only loads these.
- **Database layer**: All DB access moved to `inc/db.php` (connection, project info, API keys, users, nodes, keywords, connections, CLI helpers). Config and API/admin/edit/setup/cli use `inc/db.php` only.
- **Frontend modules**: 3D logic in ES modules: `js/main.js`, `js/telaris-network.js`, `js/telaris-node-icons.js`, `js/api.js`. Three.js loaded via import map.

### Version 1.0.9
- Removed legacy root-level `setup.php` entrypoint; `admin/setup.php` is now the only setup script

### Version 1.0.8
- Fixed login redirect to preserve destination (edit vs admin) after authentication
- Improved connection line visibility and positioning
- Connection lines now properly connect at the center of each node
- Thinner connection lines (1px minimum, 7px maximum based on shared keywords)

### Version 1.0.7
- Added context-aware login redirects
- Improved node editor and admin console interfaces
- Enhanced connection visualization

## License

See LICENSE file for details.
