<?php
declare(strict_types=1);

/**
 * Shared editor/admin session establishment, used by utils/login.php (password
 * + magic-link) and utils/enroll.php (enrolment confirmation). Kept in one place
 * so every sign-in path sets identical session state and fires the one-time
 * first-login welcome email consistently.
 *
 * The redirect targets are relative to the entry-point script; all current
 * callers live in utils/, so '../edit/' and '../admin/' resolve correctly.
 */

require_once __DIR__ . '/enroll-mail.php';

/**
 * Redirect a freshly-authenticated user to their workspace. Admins may be sent
 * to the editor when they asked for it; everyone else goes to the editor.
 */
function redirectUser(int $type, ?string $requestedTarget = null): void {
    if ($type === USER_TYPE_ADMIN) {
        if ($requestedTarget === 'edit') {
            header('Location: ../edit/index.php');
        } else {
            header('Location: ../admin/index.php');
        }
    } else {
        header('Location: ../edit/index.php');
    }
    exit();
}

/**
 * Establish a logged-in session for $user and redirect. Sends the one-time
 * first-login welcome email when this is the user's first sign-in (the row's
 * date_last_login is still null, captured before we update it). $requestedTarget
 * may be null; pass it through from the entry point.
 *
 * @param array<string,mixed> $user
 */
function finalize_user_login(array $user, ?string $requestedTarget, string $locale): void {
    // Editor enable/disable gate: a disabled editor (installation switch off, or
    // their own switch off) is never granted a session. Admins are never gated,
    // so an operator can always sign in to re-enable.
    if ((int)($user['type'] ?? 0) === USER_TYPE_EDITOR && !db_editor_login_allowed((string)($user['id'] ?? ''))) {
        header('Location: login.php?notice=editors_disabled');
        exit();
    }
    $isFirstLogin = empty($user['date_last_login']);
    // Prevent session fixation.
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['admin_user_id'] = $user['id'];
    $_SESSION['admin_user_email'] = $user['email'];
    $_SESSION['admin_user_name'] = trim(((string)($user['firstname'] ?? '')) . ' ' . ((string)($user['lastname'] ?? '')));
    $_SESSION['admin_user_type'] = $user['type'];
    db_update_user_last_login($user['id']);
    if ($isFirstLogin) {
        // Link the editor's own galaxy in the welcome email when they have one
        // (e.g. an auto-created personal galaxy); otherwise send_welcome_email
        // falls back to the instance's main 3D view.
        $personalGalaxyUrl = null;
        $ownedIds = db_get_constellation_ids_created_by((string)$user['id']);
        if (!empty($ownedIds)) {
            $g = db_get_constellation_by_id((int)$ownedIds[0]);
            $slug = is_array($g) ? (string)($g['slug'] ?? '') : '';
            if ($slug !== '') {
                $personalGalaxyUrl = enroll_mail_base_url() . '/' . rawurlencode($slug);
            }
        }
        @send_welcome_email((string)$user['email'], $locale, $personalGalaxyUrl);
    }
    redirectUser((int)$user['type'], $requestedTarget);
}
