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
require_once __DIR__ . '/../../auth.php';

/**
 * Read input from stdin
 */
function readInput(string $prompt): string {
    echo $prompt;
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle) ?? '');
    fclose($handle);
    return $line;
}

/**
 * Read password from stdin (hidden input)
 */
function readPassword(string $prompt): string {
    echo $prompt;
    system('stty -echo');
    $handle = fopen("php://stdin", "r");
    $password = trim(fgets($handle) ?? '');
    fclose($handle);
    system('stty echo');
    echo "\n";
    return $password;
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
    
    // Last Name
    $lastname = '';
    while (empty($lastname)) {
        $lastname = readInput("Last Name: ");
        if (empty($lastname)) {
            echo "Last name is required.\n";
        }
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
    echo "Last Name: $lastname\n";
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
    $result = createUser($pdo, $email, $hashedPassword, $firstname, $lastname, $userType);
    
    if ($result === null) {
        echo "✓ User created successfully!\n";
        echo "\nUser Details:\n";
        echo "  Name: $firstname $lastname\n";
        echo "  Email: $email\n";
        echo "  Type: " . ($userType === USER_TYPE_ADMIN ? 'Admin' : 'Editor') . "\n";
        echo "\nThe user can now login at /login.php\n";
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
