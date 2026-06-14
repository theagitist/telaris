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
require_once dirname(__DIR__) . '/email-template.php';

/** Map a teardown reason token to its localized reason-string key. */
const FEDERATION_DROP_REASON_KEYS = [
    'origin_retraction'    => 'email_drop_reason_retraction',
    'pluriverse-blacklist' => 'email_drop_reason_blacklist',
    'pluriverse-revoked'   => 'email_drop_reason_revoked',
    'local-blacklist'      => 'email_drop_reason_local',
    'publish_revoked'      => 'email_drop_reason_publish_revoked',
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
 * Content pieces of the notification in one locale, for the email shell.
 * Returns {subject, paragraphs, bullets}. No CTA: this is informational.
 *
 * @param list<array{slug:string, origin_host:string}> $dropped
 * @return array{subject:string, paragraphs:list<string>, bullets:list<string>}
 */
function _federation_drop_locale_content(string $locale, array $dropped, string $reason, string $greetingName): array {
    $s = db_get_project_info_for_locale($locale);

    $greeting = $greetingName !== ''
        ? sprintf(_federation_drop_t($s, 'auth_email_greeting_named'), $greetingName)
        : _federation_drop_t($s, 'auth_email_greeting_anon');

    $itemFmt = _federation_drop_t($s, 'email_drop_item');
    $bullets = [];
    foreach ($dropped as $d) {
        $bullets[] = sprintf($itemFmt, (string)$d['slug'], (string)$d['origin_host']);
    }

    $paragraphs = [$greeting, _federation_drop_t($s, 'email_drop_intro')];
    $reasonKey = FEDERATION_DROP_REASON_KEYS[$reason] ?? null;
    if ($reasonKey !== null) {
        $paragraphs[] = sprintf(_federation_drop_t($s, 'email_drop_reason_label'), _federation_drop_t($s, $reasonKey));
    }
    $paragraphs[] = _federation_drop_t($s, 'email_drop_outro');

    return [
        'subject'    => _federation_drop_t($s, 'email_drop_subject'),
        'paragraphs' => $paragraphs,
        'bullets'    => $bullets,
    ];
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
            $c = _federation_drop_locale_content($loc, $dropped, $reason, $name);
            $subject = $c['subject'];
            $rendered = telaris_email_render([
                'heading'    => $c['subject'],
                'paragraphs' => $c['paragraphs'],
                'bullets'    => $c['bullets'],
                'locale'     => $loc,
            ]);
        } else {
            // Locale not chosen: multilingual body, every supported locale in
            // sequence in one on-brand shell. No single language is privileged
            // as the default; the dropped-galaxy list renders once (the data is
            // locale-independent), in the primary supported locale's wording.
            $primary = PROJECT_INFO_LOCALES[0];
            $subjects = [];
            $paragraphs = [];
            $bullets = null;
            foreach (PROJECT_INFO_LOCALES as $l) {
                $c = _federation_drop_locale_content($l, $dropped, $reason, $name);
                $subjects[$c['subject']] = true;
                $paragraphs = array_merge($paragraphs, $c['paragraphs']);
                if ($bullets === null) {
                    $bullets = $c['bullets'];
                }
            }
            $subject = implode(' / ', array_keys($subjects));
            $rendered = telaris_email_render([
                'heading'    => _federation_drop_t(db_get_project_info_for_locale($primary), 'email_drop_subject'),
                'paragraphs' => $paragraphs,
                'bullets'    => $bullets ?? [],
                'locale'     => $primary,
            ]);
        }

        @mail_send($email, $subject, $rendered['html'], $rendered['text'], $name !== '' ? $name : null);
    }
}
