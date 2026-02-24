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
