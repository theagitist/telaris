<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Logout the user
logoutAdmin();

// Redirect to home page
header('Location: ../index.php');
exit();
