<?php
declare(strict_types=1);

// Visitor-facing editor self-enrolment (opt-in per installation). Three modes:
//   1. Confirm  - GET ?token=...&purpose=enroll_confirm: consume the token,
//      apply the auto-enroll config (personal galaxy + granted seats), sign in.
//   2. Form     - GET with no token: render the enrol form, only when open.
//   3. Submit   - POST: validate, create an unvetted password-less editor,
//      email a confirmation link.
// Enrolment openness (enabled + under cap) is re-checked server-side on every
// GET and POST; the hidden hamburger link is never trusted on its own.
//
// Captcha is out of scope for v1 (per-IP throttle + consent friction + email
// confirmation are the defences). A committed urgent follow-up adds one right
// after this ships: see vault ROADMAP ^enroll-captcha. Mount point: the POST
// branch below, before createUser().

header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/consent.php';
require_once __DIR__ . '/../inc/enroll-mail.php';
require_once __DIR__ . '/../inc/enroll-actions.php';
require_once __DIR__ . '/../inc/session-login.php';

$authLocale = locale_init_strings()['__locale'] ?? 'en';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;
$notice = null;

// ---------------------------------------------------------------------------
// 1. Confirmation: consume the enroll_confirm token, apply config, sign in.
// ---------------------------------------------------------------------------
$confirmToken = (string)($_GET['token'] ?? '');
if ($confirmToken !== '' && ($_GET['purpose'] ?? '') === 'enroll_confirm') {
    $user = db_consume_login_token($confirmToken, 'enroll_confirm');
    if ($user !== null && (int)$user['type'] === USER_TYPE_EDITOR) {
        $userId = (string)$user['id'];
        $cfg = db_get_auto_enroll_config();

        // Personal galaxy (per naming convention, or deferred) + configured grants.
        enroll_apply_config(
            $userId,
            (string)$user['email'],
            (string)($user['firstname'] ?? ''),
            $cfg,
            t('enroll_galaxy_name_possessive', "%s's galaxy")
        );

        // Consent was recorded at submit; establish the session, fire the welcome
        // email (first login), and land in the editor. finalize redirects + exits.
        finalize_user_login($user, 'edit', $authLocale);
    }
    $error = t('enroll_confirm_invalid', 'That confirmation link is invalid or has expired. You can request enrolment again.');
}

// Effective openness, distinguishing "disabled" from "cap full" for the notice.
$cfg = db_get_auto_enroll_config();
$isOpen = db_auto_enroll_is_open();
$closedReason = null;
if (!$isOpen) {
    $closedReason = ($cfg['enabled'] && $cfg['cap_enabled'] && $cfg['cap'] > 0) ? 'full' : 'disabled';
}

// ---------------------------------------------------------------------------
// 3. Submission.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = t('auth_error_invalid_request', 'Invalid request. Please reload the page and try again.');
    } elseif (!$isOpen) {
        // Server-side refusal; never trust the rendered form alone.
        $error = ($closedReason === 'full')
            ? t('enroll_full_notice', 'Editor enrolment is full on this instance right now. Please try again later.')
            : t('enroll_disabled_notice', 'Editor enrolment is not open on this instance right now.');
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $agreed = !empty($_POST['agree']);

        if ($name === '') {
            $error = t('enroll_name_required', 'Please enter your name.');
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('auth_forgot_error_invalid_email', 'Please enter a valid email address.');
        } elseif (consent_enforced() && !$agreed) {
            $error = consent_t('error_must_agree', $authLocale);
        } else {
            $ip = auth_client_ip();
            $lock = db_auth_throttle_lock_acquire('enroll', $ip);
            try {
                if (!$lock['acquired'] || db_count_recent_auth_attempts('enroll', null, $ip, AUTH_ENROLL_WINDOW_SECONDS, null) >= AUTH_ENROLL_MAX_ATTEMPTS) {
                    db_record_auth_attempt('enroll', $email, $ip, false);
                    $notice = t('enroll_check_email_notice', 'Check your email.');
                } elseif (!enroll_email_domain_allowed($email, $cfg['domains'])) {
                    db_record_auth_attempt('enroll', $email, $ip, false);
                    $error = t('enroll_domain_rejected', 'That email domain is not eligible to enrol on this instance.');
                } else {
                    // CAPTCHA MOUNT POINT (ROADMAP ^enroll-captcha): verify here before creating.
                    if (db_user_email_exists($email)) {
                        // Anti-enumeration: same notice as a fresh enrolment. If the
                        // existing account is an editor/admin, send a magic sign-in
                        // link so a returning editor is not stranded.
                        $existing = db_get_user_by_email($email);
                        if ($existing && in_array((int)($existing['type'] ?? -1), [USER_TYPE_EDITOR, USER_TYPE_ADMIN], true)) {
                            $magic = db_create_login_token((string)$existing['id'], 'magic_login', 900);
                            @send_magic_login_email($email, $magic, $authLocale);
                        }
                    } else {
                        $err = createUser(getDB(), $email, null, $name, null, USER_TYPE_EDITOR, null, false);
                        if ($err === null) {
                            $newUser = db_get_user_by_email($email);
                            if ($newUser) {
                                $newId = (string)$newUser['id'];
                                consent_record_all($newId);
                                $token = db_create_login_token($newId, 'enroll_confirm', 86400); // 24h
                                @send_enroll_confirm_email($email, $token, $authLocale);
                            }
                        } else {
                            error_log('enroll.php: createUser failed: ' . $err);
                        }
                    }
                    db_record_auth_attempt('enroll', $email, $ip, true);
                    $notice = t('enroll_check_email_notice', 'Check your email.');
                }
            } finally {
                db_auth_throttle_lock_release($lock);
            }
        }
    }
}

// TOS / Privacy presentation for the form (reuses the consent strings + URLs).
$tosUrl = consent_document_url(CONSENT_DOC_TOS);
$privacyUrl = consent_document_url(CONSENT_DOC_PRIVACY);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($authLocale); ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?php echo t_attr('enroll_page_title', 'Enrol as an editor - Telaris'); ?></title>
    <script src="../js/tailwind.min.js"></script>
    <link href="/css/vendor/daisyui-4.12.10.full.min.css" rel="stylesheet" type="text/css" />
    <?php include __DIR__ . '/../inc/admin-console-theme.php'; ?>
</head>
<body class="font-sans bg-black min-h-screen flex items-center justify-center px-5">
    <div class="bg-gray-900 border border-gray-800 p-8 rounded-lg shadow-2xl w-full max-w-md text-white">
        <h1 class="text-white mb-2 text-3xl font-semibold text-center"><?php echo t_attr('enroll_heading', 'Enrol as an editor'); ?></h1>

        <?php if ($error): ?>
            <div class="bg-red-900/30 border border-red-800 text-red-200 p-4 rounded mb-5 text-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($notice): ?>
            <div class="bg-emerald-900/30 border border-emerald-800 text-emerald-200 p-4 rounded mb-5 text-sm">
                <?php echo htmlspecialchars($notice); ?>
            </div>
        <?php endif; ?>

        <?php if (!$isOpen && !$notice): ?>
            <div class="bg-gray-800/60 border border-gray-700 text-gray-300 p-4 rounded mb-5 text-sm">
                <?php echo htmlspecialchars($closedReason === 'full'
                    ? t('enroll_full_notice', 'Editor enrolment is full on this instance right now. Please try again later.')
                    : t('enroll_disabled_notice', 'Editor enrolment is not open on this instance right now.')); ?>
            </div>
        <?php endif; ?>

        <?php if ($isOpen && !$notice): ?>
            <p class="text-gray-400 mb-6 text-center text-sm"><?php echo t_attr('enroll_intro', 'Join this Telaris instance as an editor.'); ?></p>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="mb-5">
                    <label for="name" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('enroll_name_label', 'Your name'); ?></label>
                    <input type="text" id="name" name="name" required autofocus
                           class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
                </div>
                <div class="mb-5">
                    <label for="email" class="block mb-1.5 text-gray-300 font-medium"><?php echo t_attr('enroll_email_label', 'Email'); ?></label>
                    <input type="email" id="email" name="email" required
                           class="w-full p-2.5 bg-gray-800 border border-gray-700 rounded text-white text-sm focus:outline-none focus:border-blue-500 transition-colors">
                </div>
                <?php if (consent_enforced()): ?>
                <div class="mb-5 text-xs text-gray-400 leading-relaxed">
                    <p class="mb-2"><?php echo htmlspecialchars(consent_t('summary', $authLocale)); ?></p>
                    <p>
                        <?php echo htmlspecialchars(consent_t('read_full', $authLocale)); ?>
                        <a href="<?php echo htmlspecialchars($tosUrl); ?>" target="_blank" rel="noopener" class="underline hover:text-white"><?php echo htmlspecialchars(consent_t('tos_label', $authLocale)); ?></a>,
                        <a href="<?php echo htmlspecialchars($privacyUrl); ?>" target="_blank" rel="noopener" class="underline hover:text-white"><?php echo htmlspecialchars(consent_t('privacy_label', $authLocale)); ?></a>.
                    </p>
                </div>
                <label class="flex items-start gap-2 mb-6 text-sm text-gray-300 cursor-pointer">
                    <input type="checkbox" name="agree" value="1" required class="mt-1">
                    <span><?php echo htmlspecialchars(consent_t('agree_label', $authLocale)); ?></span>
                </label>
                <?php endif; ?>
                <button type="submit" class="btn btn-neutral w-full"><?php echo t_attr('enroll_submit', 'Request access'); ?></button>
            </form>
        <?php endif; ?>

        <div class="mt-8 text-center pt-6 border-t border-gray-800">
            <a href="login.php" class="block text-gray-400 hover:text-white transition-colors text-sm"><?php echo t_attr('auth_forgot_back_link', '← Back to login'); ?></a>
        </div>
    </div>
</body>
</html>
