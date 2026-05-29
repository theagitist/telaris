<?php
declare(strict_types=1);

/**
 * Stage 6f: notify this instance's operator(s) when federated mirrors are
 * dropped (governance untrust via 6a, or origin retraction during a pull
 * cycle). The instance self-notifies its own admins rather than relying on a
 * relay to email "affected peer admins": the Pluriverse relay deliberately
 * does not store subscription graphs (who-mirrors-what lives only on the
 * subscribing instance, for data-minimization), so only the dropping instance
 * knows it dropped a mirror and who its operators are. Deviation from v10
 * line 356, recorded in the Stage 6 design note.
 *
 * Locale: composed in each admin's chosen locale (users.locale). An admin who
 * has not chosen one gets a multilingual body covering every supported locale
 * rather than a silent English default (decolonial-identifier stance).
 *
 * Best-effort: a send failure is logged by mail_send and never thrown. The
 * caller's teardown has already committed; the email is a courtesy.
 *
 * Spec: Stage 6 trust revocation design (6f); v10 § State-change propagation.
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/mail.php';

/** Map a teardown reason token to its localized reason-string key. */
const FEDERATION_DROP_REASON_KEYS = [
    'origin_retraction'    => 'email_drop_reason_retraction',
    'pluriverse-blacklist' => 'email_drop_reason_blacklist',
    'pluriverse-revoked'   => 'email_drop_reason_revoked',
    'local-blacklist'      => 'email_drop_reason_local',
];

/**
 * Read a project_info string for a specific locale's strings array, falling
 * back to the bare key (never English) when the row is missing or empty.
 */
function _federation_drop_t(array $strings, string $key): string {
    $v = $strings[$key] ?? '';
    return $v !== '' ? (string)$v : $key;
}

/**
 * Render the body of the notification in one locale. Returns {html, text}.
 *
 * @param list<array{slug:string, origin_host:string}> $dropped
 */
function _federation_drop_render_locale(string $locale, array $dropped, string $reason, string $greetingName): array {
    $s = db_get_project_info_for_locale($locale);

    $greeting = $greetingName !== ''
        ? sprintf(_federation_drop_t($s, 'auth_email_greeting_named'), $greetingName)
        : _federation_drop_t($s, 'auth_email_greeting_anon');
    $intro = _federation_drop_t($s, 'email_drop_intro');
    $outro = _federation_drop_t($s, 'email_drop_outro');

    $itemFmt = _federation_drop_t($s, 'email_drop_item');
    $items = [];
    foreach ($dropped as $d) {
        $items[] = sprintf($itemFmt, (string)$d['slug'], (string)$d['origin_host']);
    }

    $reasonLine = '';
    $reasonKey = FEDERATION_DROP_REASON_KEYS[$reason] ?? null;
    if ($reasonKey !== null) {
        $reasonLine = sprintf(_federation_drop_t($s, 'email_drop_reason_label'), _federation_drop_t($s, $reasonKey));
    }

    // HTML
    $html = '<p>' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<ul>';
    foreach ($items as $line) {
        $html .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul>';
    if ($reasonLine !== '') {
        $html .= '<p>' . htmlspecialchars($reasonLine, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $html .= '<p>' . htmlspecialchars($outro, ENT_QUOTES, 'UTF-8') . '</p>';

    // Plain text
    $text = $greeting . "\n\n" . $intro . "\n";
    foreach ($items as $line) {
        $text .= '  - ' . $line . "\n";
    }
    if ($reasonLine !== '') {
        $text .= "\n" . $reasonLine . "\n";
    }
    $text .= "\n" . $outro . "\n";

    return ['html' => $html, 'text' => $text, 'subject' => _federation_drop_t($s, 'email_drop_subject')];
}

/**
 * Notify all admin operators that one or more mirrors were dropped.
 *
 * @param list<array{slug:string, origin_host:string}> $dropped  Galaxies removed.
 * @param string $reason  One of the FEDERATION_DROP_REASON_KEYS tokens; an
 *                        unrecognized token simply omits the reason line.
 */
function federation_notify_operator_mirror_dropped(array $dropped, string $reason): void {
    if ($dropped === [] || !mail_is_configured()) {
        return;
    }
    db_ensure_users_locale_column();

    // Recipients: every admin (USER_TYPE_ADMIN = 2). One email per admin, in
    // their own locale.
    $rows = getDB()->query("SELECT email, firstname, locale FROM users WHERE type = 2 AND email <> ''")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return;
    }

    foreach ($rows as $r) {
        $email = (string)$r['email'];
        $name = (string)($r['firstname'] ?? '');
        $loc = $r['locale'] !== null ? locale_normalize_code((string)$r['locale']) : null;

        if ($loc !== null && in_array($loc, PROJECT_INFO_LOCALES, true)) {
            $rendered = _federation_drop_render_locale($loc, $dropped, $reason, $name);
            $subject = $rendered['subject'];
            $html = $rendered['html'];
            $text = $rendered['text'];
        } else {
            // Locale not chosen: multilingual body, every supported locale in
            // sequence. No single language is privileged as the default.
            $subjects = [];
            $htmlParts = [];
            $textParts = [];
            foreach (PROJECT_INFO_LOCALES as $l) {
                $rendered = _federation_drop_render_locale($l, $dropped, $reason, $name);
                $subjects[$rendered['subject']] = true;
                $htmlParts[] = $rendered['html'];
                $textParts[] = $rendered['text'];
            }
            $subject = implode(' / ', array_keys($subjects));
            $html = implode('<hr>', $htmlParts);
            $text = implode("\n\n--------\n\n", $textParts);
        }

        @mail_send($email, $subject, $html, $text, $name !== '' ? $name : null);
    }
}
