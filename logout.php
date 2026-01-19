<?php
declare(strict_types=1);

require_once 'auth.php';

// Logout the user
logoutAdmin();

// Redirect to login page
header('Location: /login.php');
exit();
