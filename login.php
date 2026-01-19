<?php
declare(strict_types=1);

// Set Content-Type header to ensure proper rendering
header('Content-Type: text/html; charset=UTF-8');

require_once 'auth.php';

// Get redirect target from query parameter or default to admin
$redirect = $_GET['redirect'] ?? 'admin';

// If already logged in as admin, redirect based on target
if (isAdminLoggedIn()) {
    if ($redirect === 'edit') {
        header('Location: edit/index.php');
    } else {
        header('Location: admin/index.php');
    }
    exit();
}

// If already logged in as editor, redirect to edit or show message
if (isEditorLoggedIn()) {
    if ($redirect === 'edit') {
        header('Location: edit/index.php');
        exit();
    } else {
        $editorMessage = true;
    }
}

$error = null;

// Handle login form submission
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirectTarget = $_POST['redirect'] ?? 'admin';
    
    if (empty($email) || empty($password)) {
        $error = 'Email and password are required';
    } else {
        $user = authenticateUser($email, $password);
        
        if ($user) {
            // Set session variables
            $_SESSION['admin_user_id'] = $user['id'];
            $_SESSION['admin_user_email'] = $user['email'];
            $_SESSION['admin_user_name'] = $user['firstname'] . ' ' . $user['lastname'];
            $_SESSION['admin_user_type'] = $user['type'];
            
            // Check user type and redirect accordingly
            if ((int)$user['type'] === USER_TYPE_ADMIN) {
                // Admin: redirect based on target
                if ($redirectTarget === 'edit') {
                    header('Location: edit/index.php');
                } else {
                    header('Location: admin/index.php');
                }
                exit();
            } elseif ((int)$user['type'] === USER_TYPE_EDITOR) {
                // Editor: redirect to edit page or show message
                if ($redirectTarget === 'edit') {
                    header('Location: edit/index.php');
                    exit();
                } else {
                    $editorMessage = true;
                }
            }
        } else {
            $error = 'Invalid email or password. Only editor and admin users can login here.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Telaris</title>
    <script src="js/tailwind.min.js"></script>
</head>
<body class="font-sans bg-gray-100 min-h-screen flex items-center justify-center px-5">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 class="text-gray-800 mb-2 text-3xl font-semibold text-center">Telaris Login</h1>
        <p class="text-gray-600 mb-8 text-center">
            <?php 
            if ($redirect === 'edit') {
                echo 'Node Editor';
            } else {
                echo 'Administration';
            }
            ?>
        </p>
        
        <?php if (isset($editorMessage) && $editorMessage): ?>
            <div class="bg-yellow-50 text-yellow-800 p-4 rounded mb-5">
                <p class="font-semibold">Editor Account</p>
                <p class="text-sm mt-1">You are logged in as an editor. Editors can edit nodes but cannot access the admin console. Only administrators can access the admin console.</p>
                <p class="mt-3">
                    <a href="edit/index.php" class="text-yellow-800 hover:underline text-sm font-medium mr-3">Go to Editor</a>
                    <a href="logout.php" class="text-yellow-800 hover:underline text-sm font-medium">Logout</a>
                </p>
            </div>
        <?php elseif ($error): ?>
            <div class="bg-red-50 text-red-700 p-4 rounded mb-5">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
            <div class="mb-5">
                <label for="email" class="block mb-1.5 text-gray-800 font-medium">Email</label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       required 
                       autofocus
                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
            </div>
            
            <div class="mb-5">
                <label for="password" class="block mb-1.5 text-gray-800 font-medium">Password</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       required
                       class="w-full p-2.5 border border-gray-300 rounded text-sm focus:outline-none focus:border-blue-500">
            </div>
            
            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white py-3 px-8 rounded text-base cursor-pointer w-full">
                Login
            </button>
        </form>
        
        <div class="mt-6 text-center">
            <a href="index.php" class="text-blue-500 hover:underline text-sm">← Back to Telaris</a>
        </div>
    </div>
</body>
</html>
