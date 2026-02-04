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
Lists all constellations (each constellation is a set of nodes and keywords). The default constellation has id=0 and is created by setup; it cannot be erased.
- `id` INT NOT NULL PRIMARY KEY - Constellation identifier (immutable; 0 = default)
- `name` VARCHAR(255) NOT NULL DEFAULT '' - Display name

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
- 3D visualization with organic animations
- Large star-shaped node icons (4x scaled)
- Light, semi-transparent vivid pastel connections between nodes based on shared keywords
- Vivid pastel-colored node icons for enhanced visual appeal
- Interactive hover labels showing node names
- Clickable nodes - clicking a node with a URL opens it in a new window
- Cursor changes to pointer when hovering over nodes with URLs
- Orbit controls for camera navigation (drag to rotate, scroll to zoom)
- Idle auto-rotation - the scene slowly rotates when the user is inactive
- Real-time data loading from API

### Backend
- Database-driven node management
- Keywords system for tagging and categorizing nodes
- Many-to-many relationship between nodes and keywords
- Automatic connection calculation based on shared keywords
- Node URL support - optional URL attribute per node
- User authentication and authorization
- Context-aware login redirects (edit page redirects back to edit after login)
- Secure password hashing (bcrypt with automatic salting)
- API key authentication for API endpoints

### Node Editor Interface
- Tabbed interface for adding new nodes and listing existing nodes
- Inline editing - edit nodes directly in the list at their position
- Compact spreadsheet-like layout - efficient use of vertical space with all node information visible in columns
- Clickable column headers for sorting:
  - Click any column header (Name, URL, Keywords, Created) to sort by that column
  - Click again to toggle between ascending and descending order
  - Visual indicators (↑/↓) show the current sort column and direction
- Fuzzy search functionality:
  - Real-time search as you type
  - Searches across node name, description, URL, and keywords
  - Works seamlessly with sorting
- Date created display - shows when each node was created
- Admin Console button - visible only to admin users (type 2)

### Admin Console Interface
- User management with compact spreadsheet-like layout matching the node editor style
- Clickable column headers for sorting user list:
  - Sort by Name, Email, Type, Created, or Last Login
  - Visual indicators (↑/↓) show the current sort column and direction
- Create, edit, and delete users
- API key management
- PHP information display

## Security

- **Password Security**: All passwords are hashed using PHP's `password_hash()` with `PASSWORD_DEFAULT` (bcrypt), which includes automatic salting. Each password gets a unique salt.
- **Session Management**: User authentication uses secure session management
- **User Authorization**: Role-based access control (regular, editor, admin)
- **SQL Injection Protection**: All database queries use prepared statements

## File Structure

```
telaris.polivoxia.ca/
├── admin/                 # Admin console
│   ├── index.php         # Admin dashboard (redirects to setup.php if not configured)
│   ├── setup.php         # Initial setup wizard (web GUI only)
│   ├── cli/              # CLI scripts
│   │   ├── cli_auth.php  # CLI access protection
│   │   ├── create_user.php # Create users via CLI
│   │   ├── hard_reset.php  # Hard reset (drops tables + deletes config.php)
│   │   └── README.md     # CLI scripts documentation
│   └── README.md         # Admin documentation
├── api/                   # API endpoints
│   ├── auth.php          # API authentication
│   ├── connections.php   # Connections API (calculated from keywords)
│   ├── keywords.php      # Keywords API
│   └── nodes.php         # Nodes API
├── edit/                  # Node editor interface
│   └── index.php         # Node management UI (supports URL editing)
├── inc/                   # PHP includes (bootstrap, view, database layer)
│   ├── bootstrap.php     # Config check, DB, auth, project info
│   ├── db.php            # All database access (connection + queries)
│   └── main-view.php     # Main HTML shell and 3D canvas markup
├── js/                    # Frontend (ES modules + Tailwind)
│   ├── api.js            # API fetch helper (uses API key)
│   ├── main.js           # Entry point; initializes 3D network
│   ├── telaris-network.js # 3D scene, nodes, connections, tooltips, controls
│   ├── telaris-node-icons.js # Constellation-themed node mesh creation
│   └── tailwind.min.js   # Tailwind CSS
├── config_default.php    # Configuration template (with empty database values)
├── config.php            # Generated configuration (created by admin/setup.php, in .gitignore)
├── index.php             # Main entry; loads bootstrap + main view
├── utils/
│   ├── auth.php          # User authentication helpers
│   ├── frame.php         # New-window frame for node links (toolbar + iframe/redirect)
│   ├── login.php         # Login page with context-aware redirects
│   └── logout.php        # Logout handler
├── README.md             # This file
└── VERSION               # Current version (e.g. 2.0.1)
```

## Browser Support

Modern browsers with WebGL support:
- Chrome (recommended)
- Firefox
- Safari
- Edge

## Troubleshooting

### 404 Errors on Directories
If accessing `/edit` or `/admin` returns 404, ensure your Nginx configuration includes:
```nginx
location / {
    try_files $uri $uri/ $uri/index.php =404;
}
```

To support constellation URLs like `/5` (open constellation by id), add a rewrite before the above so numeric paths are handled by `index.php`:
```nginx
location ~ ^/[0-9]+$ {
    rewrite ^/([0-9]+)$ /index.php?constellation_id=$1 last;
}
```

### Authentication Issues
- Verify user type in database (0 = regular, 1 = editor, 2 = admin)
- Check that passwords are properly hashed
- Ensure session cookies are enabled in browser

### Configuration Issues
- If `config.php` cannot be created automatically, the setup script will display the content in a textarea for manual creation
- Ensure the web server has write permissions to the root directory for automatic `config.php` creation
- `config.php` is in `.gitignore` and should not be committed to version control

### Hard Reset
To completely reset the installation (drop all tables and delete config.php), use:
```bash
php admin/cli/hard_reset.php
```

The script will prompt for confirmation. Type `yes` or `y` to proceed, or use the `--force` flag to skip confirmation:
```bash
php admin/cli/hard_reset.php --force
```

This will return the application to an unconfigured state, allowing you to run `admin/setup.php` again.

## User Interaction

### Visual Features
- **Node Icons**: Large star-shaped 3D icons (4x scaled from original size)
- **Hover Labels**: When hovering over a node, a tooltip appears showing the node's name
- **Clickable Nodes**: Nodes with URLs are clickable - clicking opens the URL in a new window
- **Cursor Feedback**: Cursor changes to a pointer when hovering over nodes with URLs
- **Connections**: Colored lines connect nodes that share keywords

### Navigation
- **Rotate View**: Click and drag to rotate the camera around the scene
- **Zoom**: Use mouse wheel to zoom in/out
- **Pan**: (If enabled) Right-click and drag or use middle mouse button

## Version History

### Version 2.0.2
- **Constellations**: Added support for multiple constellations (sets of nodes and keywords). New `constellations` table; nodes and keywords now have `constellation_id`. Setup creates a default constellation (id=0) that cannot be erased. Existing nodes and keywords are migrated to the default constellation automatically.

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
