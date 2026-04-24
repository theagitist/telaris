# CLI Scripts

This directory contains command-line scripts for administrative tasks.

## Security

All scripts in this directory are protected from web access. They can only be run from the server command line.

## Usage

All CLI scripts should include the protection file at the top:

```php
<?php
declare(strict_types=1);

// Ensure this script can only be run from CLI
require_once __DIR__ . '/cli_auth.php';

// Your script code here...
```

## Available Scripts

### create_user.php

Creates a new user (admin or editor) in the database.

**Usage:**
```bash
php admin/cli/create_user.php
```

The script will interactively prompt for:
- First Name
- Last Name
- Email (validated for format and uniqueness)
- Password (minimum 8 characters, with confirmation)
- User Type (Editor or Admin)

**Features:**
- Password input is hidden for security
- Email validation and duplicate checking
- Password confirmation
- User type selection (Editor or Admin)
- Summary confirmation before creation

### hard_reset.php

Performs a complete hard reset of the Telaris installation:
- Drops all tables from the database
- Deletes config.php (if it exists)

**Usage:**
```bash
# Interactive mode (requires confirmation)
php admin/cli/hard_reset.php

# Force mode (no confirmation)
php admin/cli/hard_reset.php --force
```

**Warning:** This is a destructive operation that cannot be undone! This will completely reset the installation back to an unconfigured state.

### backup_export.php

Build a portable `.telaris-backup` file (gzipped JSON) containing galaxies and/or users.

**Usage:**
```bash
# Full backup with embedded media
php admin/cli/backup_export.php --output=/path/to/backup.telaris-backup

# Specific galaxies, no users, references only
php admin/cli/backup_export.php --output=part.telaris-backup --galaxies=1,5,7 --no-users --media=refs

# Users only, no media
php admin/cli/backup_export.php --output=users.telaris-backup --no-galaxies --media=none
```

**Options:**
- `--output=FILE` — required, must not already exist
- `--galaxies=all` (default) or `--galaxies=1,5,7`
- `--no-galaxies`, `--no-users` — exclude either category
- `--media=embedded` (default) | `refs` | `none`
- `--quiet` — print only errors

### backup_import.php

Restore from a `.telaris-backup` file. Inspects first, then prompts for confirmation.

**Usage:**
```bash
# Inspect only (no changes made)
php admin/cli/backup_import.php --input=backup.telaris-backup --inspect-only

# Restore, overwriting galaxies whose slug already exists (default mode)
php admin/cli/backup_import.php --input=backup.telaris-backup

# Restore as new galaxies with a custom suffix
php admin/cli/backup_import.php --input=backup.telaris-backup --mode=rename --rename-suffix=" (v2)"

# Non-interactive (skip prompt)
php admin/cli/backup_import.php --input=backup.telaris-backup --force
```

**Options:**
- `--input=FILE` — required
- `--mode=overwrite` (default) | `rename`
- `--rename-suffix=" (restored)"` — used in rename mode and on slug collisions
- `--skip-users` — don't restore users
- `--replace-users` — update existing users by email instead of skipping
- `--replace-passwords` — also overwrite password hashes (only with `--replace-users`)
- `--no-media` — skip writing media files
- `--inspect-only` — print summary and exit
- `--force` — skip the y/N prompt
- `--quiet` — minimal output

### snapshot_create.php

Create a local on-disk snapshot of the entire system (full backup, embedded media). Stored in `SNAPSHOTS_DIR` and tracked in the `snapshots` DB table.

**Usage:**
```bash
php admin/cli/snapshot_create.php
php admin/cli/snapshot_create.php --note="before migration"
```

### snapshot_list.php

List all snapshots on disk with id, timestamp, size, type (manual/scheduled), and note.

**Usage:**
```bash
php admin/cli/snapshot_list.php
```

### snapshot_restore.php

Restore a snapshot. **Wipes the entire system** and replaces it with the snapshot's state. All snapshots created after the restored one are also deleted (linear-timeline semantics).

**Usage:**
```bash
# By snapshot id (see snapshot_list.php)
php admin/cli/snapshot_restore.php --id=5

# By file path (no later-snapshot deletion)
php admin/cli/snapshot_restore.php --file=/path/to/file.telaris-backup

# Skip the RESTORE confirmation prompt
php admin/cli/snapshot_restore.php --id=5 --force

# Permit a snapshot that contains no admin user (would lock everyone out)
php admin/cli/snapshot_restore.php --id=5 --allow-no-admin
```

**Warning:** Destructive. Requires typing `RESTORE` to confirm unless `--force` is set.

### snapshot_run_scheduled.php

Cron target. When the scheduler is enabled, creates a daily snapshot at or after the configured UTC hour (once per UTC day), then deletes scheduled snapshots older than `keep_days` (default 7). Manual snapshots are kept forever. Every invocation prints a single timestamped status line so cron liveness is visible in the admin log panel.

**Usage:**
```bash
php admin/cli/snapshot_run_scheduled.php
```

**Cron installation is transparent** — toggling "Enable daily snapshots" in the admin Snapshots tab automatically installs (or removes) a line in the PHP user's (www-data) crontab that runs this script every 15 minutes and pipes output to `logs/snapshot_cron.log`. Running this script manually from the CLI is also safe: it just prints status and exits.

## Creating New CLI Scripts

When creating new CLI scripts:

1. Include `cli_auth.php` at the top
2. Add a shebang line: `#!/usr/bin/env php`
3. Make the script executable: `chmod +x admin/cli/your_script.php`
4. Add proper error handling
5. Document usage in this README

Example template:

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI Script: Your Script Name
 * 
 * Description of what this script does.
 * 
 * Usage: php admin/cli/your_script.php [options]
 */

// Ensure this script can only be run from CLI
require_once __DIR__ . '/cli_auth.php';

// Load configuration if needed
require_once __DIR__ . '/../../config.php';

// Your script code here
```
