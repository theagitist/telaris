<?php
declare(strict_types=1);

/**
 * First-login consent gate page (BACKLOG ^consent-gate-first-login).
 *
 * Shown to an EDITOR who still owes consent to the current Terms of Use +
 * Privacy Policy versions. Accept records consent and returns to the editor;
 * decline signs out. Admins and already-current editors are bounced straight
 * to their surface. This page itself does NOT call the consent gate, so there
 * is no redirect loop.
 */

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/consent.php';

// Must be logged in as editor or admin. This page lives in utils/, so it does
// its own check and redirects to the sibling login.php (requireEditorOrAdminLogin
// assumes a web-root caller and would emit utils/login.php, doubling the path).
if (!isEditorOrAdminLoggedIn()) {
    header('Location: login.php?redirect=edit');
    exit();
}

$locale = locale_init_strings()['__locale'] ?? 'en';
$userType = isset($_SESSION['admin_user_type']) ? (int)$_SESSION['admin_user_type'] : -1;
$userId = (string)($_SESSION['admin_user_id'] ?? '');

// Admins are not gated; an already-current editor has nothing to do here.
if ($userType !== USER_TYPE_EDITOR || !consent_user_needs($userId)) {
    header('Location: ' . ($userType === USER_TYPE_ADMIN ? '../admin/index.php' : '../edit/index.php'));
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$submittedToken)) {
        $error = consent_t('error_invalid_request', $locale);
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'decline') {
            logoutAdmin();
            header('Location: login.php');
            exit();
        }
        if ($action === 'accept') {
            if (($_POST['agree'] ?? '') === '1') {
                consent_record_all($userId);
                header('Location: ../edit/index.php');
                exit();
            }
            $error = consent_t('error_must_agree', $locale);
        }
    }
}

$required = consent_required_documents();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($locale); ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?php echo htmlspecialchars(consent_t('page_title', $locale)); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="/css/vendor/daisyui-4.12.10.full.min.css" rel="stylesheet" type="text/css" />
    <?php include __DIR__ . '/../inc/admin-console-theme.php'; ?>
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5 py-10">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-2xl text-white">
        <h1 class="text-white mb-4 text-2xl font-semibold"><?php echo htmlspecialchars(consent_t('heading', $locale)); ?></h1>
        <p class="text-gray-300 mb-5 leading-relaxed"><?php echo htmlspecialchars(consent_t('intro', $locale)); ?></p>

        <div class="bg-gray-800/60 border border-gray-700 rounded p-4 mb-5 text-gray-300 text-sm leading-relaxed">
            <?php echo htmlspecialchars(consent_t('summary', $locale)); ?>
        </div>

        <p class="text-gray-400 mb-2 text-sm"><?php echo htmlspecialchars(consent_t('read_full', $locale)); ?></p>
        <ul class="mb-5 space-y-1">
            <?php foreach ($required as $docType => $version): ?>
                <li>
                    <a href="<?php echo htmlspecialchars(consent_document_url($docType)); ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="text-blue-400 hover:text-blue-300 underline">
                        <?php echo htmlspecialchars(consent_document_label($docType, $locale)); ?>
                    </a>
                    <span class="text-gray-500 text-xs">v<?php echo htmlspecialchars($version); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($error): ?>
            <div class="bg-red-900/30 border border-red-800 text-red-200 p-3 rounded mb-4 text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <label class="flex items-start gap-3 mb-5 cursor-pointer text-gray-200 text-sm">
                <input type="checkbox" name="agree" value="1" class="mt-1 accent-blue-500" required>
                <span><?php echo htmlspecialchars(consent_t('agree_label', $locale)); ?></span>
            </label>

            <div class="flex flex-col sm:flex-row gap-3">
                <button type="submit" name="action" value="accept" class="btn btn-neutral flex-1">
                    <?php echo htmlspecialchars(consent_t('accept_button', $locale)); ?>
                </button>
                <button type="submit" name="action" value="decline" class="btn btn-ghost flex-1 text-gray-400">
                    <?php echo htmlspecialchars(consent_t('decline_button', $locale)); ?>
                </button>
            </div>
        </form>

        <p class="text-gray-500 text-xs mt-5 leading-relaxed"><?php echo htmlspecialchars(consent_t('decline_note', $locale)); ?></p>
    </div>
</body>
</html>
