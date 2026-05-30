#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI Script: Create User
 * 
 * Creates a new user (admin or editor) in the database.
 * 
 * Usage: php admin/cli/create_user.php
 */

// Ensure this script can only be run from CLI
require_once __DIR__ . '/cli_auth.php';

// Load configuration and auth functions
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../utils/auth.php';

/**
 * Read one line from stdin.
 *
 * Uses the persistent STDIN handle (predefined in PHP CLI). Earlier versions
 * opened php://stdin per call and closed it, which silently breaks under
 * piped input (the second fgets returns false on EOF). STDIN survives across
 * calls in both interactive and piped modes.
 */
function readInput(string $prompt): string {
    echo $prompt;
    $line = fgets(STDIN);
    return trim($line === false ? '' : $line);
}

/**
 * Read a password from stdin without echoing it back to the terminal.
 *
 * stty toggles are silenced (with @) because they raise a warning when
 * stdin is not a TTY (e.g., piped input); in that case the toggle is a
 * no-op and the read still works.
 */
function readPassword(string $prompt): string {
    echo $prompt;
    @system('stty -echo');
    $line = fgets(STDIN);
    @system('stty echo');
    echo "\n";
    return trim($line === false ? '' : $line);
}

// Main execution
echo "Telaris - Create User\n";
echo "=====================\n\n";

// Check database configuration
if (empty(DB_HOST) || empty(DB_NAME)) {
    echo "ERROR: Database configuration is incomplete.\n";
    echo "Please run setup.php first to configure the database.\n";
    exit(1);
}

try {
    // Connect to database
    echo "Connecting to database: " . DB_NAME . "@" . DB_HOST . ":" . DB_PORT . "\n";
    $pdo = getDB();
    echo "Connected successfully.\n\n";
    
    // Collect user information
    echo "Enter user information:\n";
    echo "----------------------\n\n";
    
    // First Name
    $firstname = '';
    while (empty($firstname)) {
        $firstname = readInput("First Name: ");
        if (empty($firstname)) {
            echo "First name is required.\n";
        }
    }
    
    // Last Name (optional)
    $lastname = readInput("Last Name (optional): ");

    // Pronouns (optional; comma-separated, up to 3). Re-prompt on a content-guard
    // rejection; an empty answer is fine and stores nothing.
    $pronouns = null;
    while (true) {
        $pronounsRaw = readInput("Pronouns (optional, comma-separated, e.g. they/them): ");
        $pr = db_user_pronouns_sanitize(db_user_pronouns_entries_from_request(null, $pronounsRaw));
        if ($pr['ok']) {
            $pronouns = $pr['json'];
            break;
        }
        echo "That pronoun entry was rejected (" . $pr['error'] . "). Please try again or leave blank.\n";
    }

    // Email
    $email = '';
    while (empty($email)) {
        $email = readInput("Email: ");
        if (empty($email)) {
            echo "Email is required.\n";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "Invalid email format.\n";
            $email = '';
        }
    }
    
    // Password
    $password = '';
    while (empty($password) || strlen($password) < 8) {
        $password = readPassword("Password (min 8 characters): ");
        if (empty($password)) {
            echo "Password is required.\n";
        } elseif (strlen($password) < 8) {
            echo "Password must be at least 8 characters long.\n";
        }
    }
    
    // Confirm Password
    $passwordConfirm = '';
    while ($password !== $passwordConfirm) {
        $passwordConfirm = readPassword("Confirm Password: ");
        if ($password !== $passwordConfirm) {
            echo "Passwords do not match. Please try again.\n";
        }
    }
    
    // User Type
    echo "\nUser Type:\n";
    echo "  1. Editor (can edit nodes, cannot access admin console)\n";
    echo "  2. Admin (full access to admin console and node editor)\n";
    
    $typeChoice = '';
    $userType = 0;
    while (!in_array($typeChoice, ['1', '2'])) {
        $typeChoice = readInput("Select user type (1 or 2): ");
        if ($typeChoice === '1') {
            $userType = USER_TYPE_EDITOR;
        } elseif ($typeChoice === '2') {
            $userType = USER_TYPE_ADMIN;
        } else {
            echo "Invalid choice. Please enter 1 or 2.\n";
        }
    }
    
    // Display summary
    echo "\n";
    echo "User Information Summary:\n";
    echo "------------------------\n";
    echo "First Name: $firstname\n";
    echo "Last Name: " . ($lastname !== '' ? $lastname : '(none)') . "\n";
    echo "Pronouns: " . ($pronouns !== null ? implode(', ', db_user_pronouns_list($pronouns)) : '(none)') . "\n";
    echo "Email: $email\n";
    echo "User Type: " . ($userType === USER_TYPE_ADMIN ? 'Admin' : 'Editor') . "\n";
    echo "\n";
    
    // Confirmation
    $confirm = readInput("Create this user? (yes/no): ");
    if (strtolower($confirm) !== 'yes') {
        echo "\nOperation cancelled.\n";
        exit(0);
    }
    
    // Create user
    echo "\nCreating user...\n";
    $hashedPassword = hashPassword($password);
    $result = createUser($pdo, $email, $hashedPassword, $firstname, $lastname, $userType, $pronouns);
    
    if ($result === null) {
        $created = db_get_user_by_email($email);
        db_audit_log(
            action: 'user.create.cli',
            actorUserId: null,
            targetType: 'user',
            targetId: (string)($created['id'] ?? '') ?: null,
            details: ['type' => $userType],
            actorEmail: $email,
        );
        echo "✓ User created successfully!\n";
        echo "\nUser Details:\n";
        echo "  Name: " . trim("$firstname $lastname") . "\n";
        echo "  Email: $email\n";
        echo "  Type: " . ($userType === USER_TYPE_ADMIN ? 'Admin' : 'Editor') . "\n";
        echo "\nThe user can now login at /utils/login.php\n";
    } else {
        echo "✗ ERROR: Failed to create user: $result\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "\n✗ ERROR: Failed to connect to database: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
