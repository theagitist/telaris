#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI Script: Hard Reset
 * 
 * This script performs a complete reset of the Telaris installation:
 * - Deletes all tables from the database
 * - Deletes config.php (if it exists)
 * 
 * WARNING: This is a destructive operation and cannot be undone!
 * 
 * Usage: php admin/cli/hard_reset.php [--force]
 */

// Ensure this script can only be run from CLI
require_once __DIR__ . '/cli_auth.php';

// Check if config.php exists before trying to load it
$configPath = __DIR__ . '/../../config.php';
$configExists = file_exists($configPath);

// Load configuration if it exists (needed to connect to database)
if ($configExists) {
    require_once $configPath;
}

// getAllTables and dropAllTables are in inc/db.php (loaded via config.php)

// Main execution
echo "Telaris - Hard Reset\n";
echo "====================\n\n";

// Check for --force flag
$force = in_array('--force', $argv ?? []);

// Check database configuration (only if config.php exists)
if ($configExists) {
    if (empty(DB_HOST) || empty(DB_NAME)) {
        echo "WARNING: Database configuration in config.php is incomplete.\n";
        echo "Skipping database operations.\n\n";
        $configExists = false; // Don't try to connect
    }
} else {
    echo "NOTE: config.php not found. Skipping database operations.\n\n";
}

$pdo = null;
$tablesDropped = false;

// Try to connect to database and drop tables if config.php exists
if ($configExists) {
    try {
        // Connect to database
        echo "Connecting to database: " . DB_NAME . "@" . DB_HOST . ":" . DB_PORT . "\n";
        $pdo = getDB();
        echo "Connected successfully.\n\n";
        
        // Get current tables
        $tables = getAllTables($pdo);
        
        if (!empty($tables)) {
            // Display tables that will be dropped
            echo "The following tables will be DROPPED:\n";
            foreach ($tables as $table) {
                echo "  - $table\n";
            }
            echo "\n";
        } else {
            echo "No tables found in the database.\n\n";
        }
        
    } catch (PDOException $e) {
        echo "\n✗ WARNING: Failed to connect to database: " . $e->getMessage() . "\n";
        echo "Continuing with config.php deletion only...\n\n";
        $pdo = null;
    }
}

// Display what will be deleted
$willDelete = [];
if ($pdo !== null) {
    $tables = getAllTables($pdo);
    if (!empty($tables)) {
        $willDelete[] = count($tables) . " database table(s)";
    }
}
if ($configExists) {
    $willDelete[] = "config.php";
}

if (empty($willDelete)) {
    echo "Nothing to delete. Database has no tables and config.php does not exist.\n";
    exit(0);
}

echo "The following will be DELETED:\n";
foreach ($willDelete as $item) {
    echo "  - $item\n";
}
echo "\n";

// Confirmation prompt (unless --force is used)
if (!$force) {
    echo "WARNING: This operation will DELETE ALL DATA and configuration and cannot be undone!\n";
    echo "Type 'yes' or 'y' to continue, or anything else to cancel: ";
    
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle) ?? '');
    fclose($handle);
    
    $confirmation = strtolower($line);
    if ($confirmation !== 'yes' && $confirmation !== 'y') {
        echo "\nOperation cancelled.\n";
        exit(0);
    }
    echo "\n";
} else {
    echo "WARNING: --force flag detected. Proceeding without confirmation...\n\n";
}

// Drop all tables if database connection is available
if ($pdo !== null) {
    $tables = getAllTables($pdo);
    if (!empty($tables)) {
        // Audit log goes to the table that is about to be dropped, so the
        // structured-DB record won't survive. error_log captures it in the
        // FPM/PHP-CLI log channel instead — that's the only stable trail
        // for a hard reset.
        error_log(sprintf(
            'hard_reset.php: dropping %d table(s); user=%s (%s); force=%s',
            count($tables),
            posix_geteuid() ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'root',
            gethostname() ?: '?',
            $force ? 'true' : 'false',
        ));
        echo "Dropping tables...\n";
        $result = dropAllTables($pdo);
        
        // Display results
        if (!empty($result['dropped'])) {
            echo "\n✓ Successfully dropped " . count($result['dropped']) . " table(s):\n";
            foreach ($result['dropped'] as $table) {
                echo "  - $table\n";
            }
            $tablesDropped = true;
        }
        
        if (!empty($result['errors'])) {
            echo "\n✗ Errors encountered while dropping tables:\n";
            foreach ($result['errors'] as $error) {
                echo "  - $error\n";
            }
        }
    }
}

// Delete config.php if it exists
if ($configExists) {
    echo "\nDeleting config.php...\n";
    if (unlink($configPath)) {
        echo "✓ Successfully deleted config.php\n";
    } else {
        echo "✗ ERROR: Failed to delete config.php. Please check file permissions.\n";
        exit(1);
    }
}

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "Hard Reset Complete\n";
echo str_repeat("=", 50) . "\n";
if ($tablesDropped) {
    echo "✓ All database tables have been dropped.\n";
}
if ($configExists) {
    echo "✓ config.php has been deleted.\n";
}
echo "\nYou can now run setup.php to reconfigure the application.\n";
