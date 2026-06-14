<?php
declare(strict_types=1);

/**
 * Operator-initiated "documents changed" notification (BACKLOG
 * ^consent-gate-first-login follow-up).
 *
 * When the Terms of Use or Privacy Policy change in a CONSIDERABLE way, the
 * operator bumps the version constant (in inc/consent.php / config.php). That
 * bump already re-prompts editors at the consent gate; this module adds the
 * proactive email so editors know BEFORE their next sign-in. Minor or
 * typographical edits do not bump the version, so they raise no alert and send
 * no email; the version bump IS the operator's "considerable" judgement.
 *
 * The send is OPERATOR-INITIATED, never automatic: emailing every editor is an
 * outward-facing action. To make sure it is not forgotten, the admin console
 * shows a PERSISTENT alert whenever a bumped version has not yet been resolved.
 * The alert clears only when the operator either (a) sends the notifications,
 * or (b) explicitly disregards it. Disregarding is deliberately high-friction:
 * the operator must type a localized confirmation phrase, not just click a
 * button (see consent_notify_disregard_phrase / consent_notify_phrase_matches).
 *
 * State lives in two tables (inc/db.php): consent_notice_decisions (per
 * document version: sent | disregarded, what clears the alert) and
 * consent_notifications (per editor+version actually emailed, so a re-send
 * never double-notifies). Scope = editors only, matching the gate.
 *
 * Strings are a self-contained 4-locale map (no English-only fallback at
 * render, the decolonial-identifier rule), mirroring inc/consent.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/consent.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email-template.php';

/**
 * Documents whose currently-enforced version the operator has NOT yet resolved
 * (no consent_notice_decisions row). Empty when the gate is inert or every
 * enforced version has been sent/disregarded.
 *
 * @return array<string,string> documentType => version
 */
function consent_notify_pending_documents(): array {
    if (!consent_enforced()) return [];
    $decisions = db_get_consent_notice_decisions();
    $pending = [];
    foreach (consent_required_documents() as $docType => $version) {
        if (!isset($decisions[$docType][$version])) {
            $pending[$docType] = $version;
        }
    }
    return $pending;
}

/**
 * All active editor accounts (USER_TYPE_EDITOR), as the rows db_get_users
 * returns (id, email, firstname, locale, ...).
 *
 * @return list<array<string,mixed>>
 */
function consent_notify_editors(): array {
    if (!defined('USER_TYPE_EDITOR')) return [];
    $editors = [];
    foreach (db_get_users() as $u) {
        if ((int)($u['type'] ?? -1) === USER_TYPE_EDITOR) {
            $editors[] = $u;
        }
    }
    return $editors;
}

/**
 * The subset of $pending an editor still needs to be emailed about: not yet
 * accepted AND not yet notified.
 *
 * @param array<string,string> $pending
 * @return array<string,string> documentType => version
 */
function consent_notify_needed_for_editor(string $userId, array $pending): array {
    $accepted = db_get_user_accepted_consents($userId);
    $notified = db_get_user_consent_notifications($userId);
    $needed = [];
    foreach ($pending as $docType => $version) {
        if (empty($accepted[$docType][$version]) && empty($notified[$docType][$version])) {
            $needed[$docType] = $version;
        }
    }
    return $needed;
}

/**
 * Editors who should receive an email for $pending (they still need at least
 * one of the pending documents). $editors defaults to all active editors;
 * tests pass a synthetic set to avoid scanning live accounts.
 *
 * @param array<string,string> $pending
 * @param list<array<string,mixed>>|null $editors
 * @return list<array<string,mixed>>
 */
function consent_notify_recipients(array $pending, ?array $editors = null): array {
    if ($pending === []) return [];
    $editors ??= consent_notify_editors();
    $out = [];
    foreach ($editors as $ed) {
        if (consent_notify_needed_for_editor((string)$ed['id'], $pending) !== []) {
            $out[] = $ed;
        }
    }
    return $out;
}

/**
 * Whole alert state for the admin banner, computed once.
 *
 * @return array{active:bool,pending:array<string,string>,recipients:list<array<string,mixed>>}
 */
function consent_notify_alert_state(): array {
    $pending = consent_notify_pending_documents();
    $recipients = consent_notify_recipients($pending);
    return [
        'active' => $pending !== [] && $recipients !== [],
        'pending' => $pending,
        'recipients' => $recipients,
    ];
}

/**
 * Send the notification email to every editor who needs it, record the
 * notifications, and (if anything was sent, or there was simply no one to
 * notify) record the 'sent' decision so the alert clears. Honours MAIL_DRY_RUN.
 *
 * @return array{recipients:int,sent:int,failed:int,documents:array<string,string>,mail_configured:bool,resolved:bool}
 */
function consent_notify_send(?string $adminId, ?array $pending = null, ?array $editors = null): array {
    $pending ??= consent_notify_pending_documents();
    $dryRun = defined('MAIL_DRY_RUN') && MAIL_DRY_RUN;
    $result = [
        'recipients' => 0, 'sent' => 0, 'failed' => 0,
        'documents' => $pending, 'mail_configured' => mail_is_configured(), 'resolved' => false,
    ];
    if ($pending === []) return $result;

    $recipients = consent_notify_recipients($pending, $editors);
    $result['recipients'] = count($recipients);

    // Nothing to send (everyone already accepted/notified): resolve the alert.
    if ($recipients === []) {
        foreach ($pending as $docType => $version) {
            db_record_consent_notice_decision($docType, $version, 'sent', $adminId);
        }
        $result['resolved'] = true;
        return $result;
    }

    // Cannot actually deliver: do not record anything, leave the alert up.
    if (!mail_is_configured() && !$dryRun) {
        return $result;
    }

    foreach ($recipients as $ed) {
        $needed = consent_notify_needed_for_editor((string)$ed['id'], $pending);
        if ($needed === []) continue;
        [$subject, $html, $text] = consent_notify_build_email($ed, $needed);
        $ok = mail_send((string)$ed['email'], $subject, $html, $text, trim((string)($ed['firstname'] ?? '')));
        if ($ok) {
            $result['sent']++;
            foreach ($needed as $docType => $version) {
                db_record_consent_notification((string)$ed['id'], $docType, $version);
            }
        } else {
            $result['failed']++;
        }
    }

    if ($result['sent'] > 0) {
        foreach ($pending as $docType => $version) {
            db_record_consent_notice_decision($docType, $version, 'sent', $adminId);
        }
        $result['resolved'] = true;
    }
    return $result;
}

/**
 * Disregard the alert: record a 'disregarded' decision for every pending
 * version without emailing anyone. Editors are still prompted at the gate on
 * next sign-in. Returns the documents that were disregarded.
 *
 * @return array{documents:array<string,string>}
 */
function consent_notify_disregard(?string $adminId, ?array $pending = null): array {
    $pending ??= consent_notify_pending_documents();
    foreach ($pending as $docType => $version) {
        db_record_consent_notice_decision($docType, $version, 'disregarded', $adminId);
    }
    return ['documents' => $pending];
}

// --- Locale helpers ------------------------------------------------------

/** Current request locale, falling back to the first configured locale. */
function consent_notify_current_locale(): string {
    $default = defined('PROJECT_INFO_LOCALES') ? PROJECT_INFO_LOCALES[0] : 'en';
    if (function_exists('locale_init_strings')) {
        $strings = locale_init_strings();
        $loc = (string)($strings['__locale'] ?? '');
        if ($loc !== '') return $loc;
    }
    return $default;
}

/** A stored editor locale normalized to a supported code, or null if unset/unsupported. */
function consent_notify_normalize_editor_locale(?string $locale): ?string {
    if ($locale === null || $locale === '') return null;
    $supported = defined('PROJECT_INFO_LOCALES') ? PROJECT_INFO_LOCALES : ['en', 'es', 'pt', 'fr'];
    return in_array($locale, $supported, true) ? $locale : null;
}

/** The phrase the operator must type to disregard the alert, in $locale. */
function consent_notify_disregard_phrase(string $locale): string {
    return consent_notify_t('disregard_phrase', $locale);
}

/**
 * True if $typed matches the disregard phrase in ANY supported locale
 * (case-insensitive, trimmed). Matching any locale's phrase keeps the gesture
 * deliberate without trapping an operator on the exact console locale.
 */
function consent_notify_phrase_matches(string $typed): bool {
    $needle = mb_strtolower(trim($typed));
    if ($needle === '') return false;
    $supported = defined('PROJECT_INFO_LOCALES') ? PROJECT_INFO_LOCALES : ['en', 'es', 'pt', 'fr'];
    foreach ($supported as $loc) {
        if ($needle === mb_strtolower(consent_notify_disregard_phrase($loc))) return true;
    }
    return false;
}

// --- Email composition ---------------------------------------------------

/**
 * Build the localized email for one editor about the documents they need.
 * Uses the editor's own locale when set; otherwise stacks all locales so the
 * body is never English-only by default.
 *
 * @param array<string,mixed> $ed
 * @param array<string,string> $needed documentType => version
 * @return array{0:string,1:string,2:string} [subject, html, text]
 */
function consent_notify_build_email(array $ed, array $needed): array {
    $loc = consent_notify_normalize_editor_locale(isset($ed['locale']) ? (string)$ed['locale'] : null);
    $supported = defined('PROJECT_INFO_LOCALES') ? PROJECT_INFO_LOCALES : ['en', 'es', 'pt', 'fr'];
    $locales = $loc !== null ? [$loc] : $supported;
    $firstname = trim((string)($ed['firstname'] ?? ''));

    $subjectLoc = $loc ?? $supported[0];
    $subject = consent_notify_t('email_subject', $subjectLoc);

    // On-brand shell (same format as the other system emails). When the editor
    // has no chosen locale, stack each locale's paragraphs AND its document
    // links (localized labels) so the body is never English-only. There is no
    // CTA: the action is "review on next sign-in".
    $paragraphs = [];
    $links = [];
    foreach ($locales as $l) {
        $paragraphs[] = $firstname !== ''
            ? sprintf(consent_notify_t('email_greeting_named', $l), $firstname)
            : consent_notify_t('email_greeting', $l);
        $paragraphs[] = consent_notify_t('email_intro', $l);
        $paragraphs[] = consent_notify_t('email_next_login', $l);
        $paragraphs[] = consent_notify_t('email_read', $l);
        foreach ($needed as $docType => $version) {
            $label = consent_document_label($docType, $l)
                . ' · ' . consent_notify_t('email_version_word', $l) . ' ' . $version;
            $links[] = ['label' => $label, 'url' => consent_document_url($docType)];
        }
    }

    $rendered = telaris_email_render([
        'heading'    => $subject,
        'paragraphs' => $paragraphs,
        'links'      => $links,
        'locale'     => $subjectLoc,
    ]);
    return [$subject, $rendered['html'], $rendered['text']];
}

// --- Strings -------------------------------------------------------------

/**
 * Self-contained 4-locale string map for the admin alert + the email. No
 * English-only fallback at render (decolonial-identifier rule); gender-neutral;
 * tú (es), você (pt), tu (fr); no em-dashes (operator/editor-facing).
 *
 * @return array<string, array<string,string>>
 */
function consent_notify_strings(): array {
    return [
        'en' => [
            'disregard_phrase'   => 'disregard alert',
            'alert_title'        => 'Editors have not been notified of updated documents',
            'alert_intro'        => 'These documents changed and editors have not been emailed about it:',
            'alert_recipients'   => 'editors will be asked to review them the next time they sign in.',
            'alert_question'     => 'Email them now so they know?',
            'preview_summary'    => 'Preview the email',
            'preview_recipients' => 'This will be sent to the editors who have not yet accepted the new version.',
            'send_button'        => 'Send notifications',
            'disregard_summary'  => 'Disregard this alert',
            'disregard_explain'  => 'Disregarding hides this alert without emailing anyone. Editors are still asked to accept the new version the next time they sign in. To confirm, type the phrase below exactly.',
            'disregard_field'    => 'Type this to confirm:',
            'disregard_button'   => 'Disregard alert',
            'flash_sent'         => 'Notified editors about the updated documents.',
            'flash_sent_none'    => 'There were no editors to notify; the alert has been cleared.',
            'flash_send_failed'  => 'Could not send notifications (mail is not configured or delivery failed). The alert is still active.',
            'flash_disregarded'  => 'Alert disregarded. Editors are still asked to accept the new version on next sign-in.',
            'flash_phrase_bad'   => 'The confirmation phrase did not match, so the alert was not disregarded.',
            'email_subject'      => 'Telaris: the Terms of Use and Privacy Policy were updated',
            'email_greeting'     => 'Hello,',
            'email_greeting_named' => 'Hello %s,',
            'email_intro'        => 'The documents that cover your editor account on Telaris have been updated:',
            'email_version_word' => 'version',
            'email_next_login'   => 'The next time you sign in, you will be asked to review and accept the new version before you continue to the editor.',
            'email_read'         => 'You can read them here:',
        ],
        'es' => [
            'disregard_phrase'   => 'descartar alerta',
            'alert_title'        => 'No se ha avisado a quienes editan sobre los documentos actualizados',
            'alert_intro'        => 'Estos documentos cambiaron y no se ha enviado aviso por correo a quienes editan:',
            'alert_recipients'   => 'personas que editan deberán revisarlos la próxima vez que inicien sesión.',
            'alert_question'     => '¿Avisarles por correo ahora para que lo sepan?',
            'preview_summary'    => 'Ver una vista previa del correo',
            'preview_recipients' => 'Se enviará a quienes editan y aún no han aceptado la nueva versión.',
            'send_button'        => 'Enviar avisos',
            'disregard_summary'  => 'Descartar esta alerta',
            'disregard_explain'  => 'Descartar oculta esta alerta sin enviar ningún correo. A quienes editan se les seguirá pidiendo aceptar la nueva versión la próxima vez que inicien sesión. Para confirmar, escribe la frase de abajo tal cual.',
            'disregard_field'    => 'Escribe esto para confirmar:',
            'disregard_button'   => 'Descartar alerta',
            'flash_sent'         => 'Se avisó a quienes editan sobre los documentos actualizados.',
            'flash_sent_none'    => 'No había a quién avisar; la alerta se ha cerrado.',
            'flash_send_failed'  => 'No se pudieron enviar los avisos (el correo no está configurado o falló el envío). La alerta sigue activa.',
            'flash_disregarded'  => 'Alerta descartada. A quienes editan se les seguirá pidiendo aceptar la nueva versión al iniciar sesión.',
            'flash_phrase_bad'   => 'La frase de confirmación no coincidió, así que la alerta no se descartó.',
            'email_subject'      => 'Telaris: se actualizaron las Condiciones de Uso y la Política de Privacidad',
            'email_greeting'     => 'Hola:',
            'email_greeting_named' => 'Hola %s:',
            'email_intro'        => 'Los documentos que rigen tu cuenta de edición en Telaris se han actualizado:',
            'email_version_word' => 'versión',
            'email_next_login'   => 'La próxima vez que inicies sesión, se te pedirá revisar y aceptar la nueva versión antes de continuar al editor.',
            'email_read'         => 'Puedes leerlos aquí:',
        ],
        'pt' => [
            'disregard_phrase'   => 'descartar alerta',
            'alert_title'        => 'Quem edita não foi avisado sobre os documentos atualizados',
            'alert_intro'        => 'Estes documentos mudaram e ainda não houve aviso por e-mail a quem edita:',
            'alert_recipients'   => 'pessoas que editam deverão revisá-los na próxima vez que entrarem.',
            'alert_question'     => 'Avisar por e-mail agora para que saibam?',
            'preview_summary'    => 'Ver uma prévia do e-mail',
            'preview_recipients' => 'Será enviado a quem edita e ainda não aceitou a nova versão.',
            'send_button'        => 'Enviar avisos',
            'disregard_summary'  => 'Descartar este alerta',
            'disregard_explain'  => 'Descartar oculta este alerta sem enviar nenhum e-mail. Quem edita ainda será solicitado a aceitar a nova versão na próxima vez que entrar. Para confirmar, digite a frase abaixo exatamente.',
            'disregard_field'    => 'Digite isto para confirmar:',
            'disregard_button'   => 'Descartar alerta',
            'flash_sent'         => 'Quem edita foi avisado sobre os documentos atualizados.',
            'flash_sent_none'    => 'Não havia quem avisar; o alerta foi encerrado.',
            'flash_send_failed'  => 'Não foi possível enviar os avisos (e-mail não configurado ou falha no envio). O alerta continua ativo.',
            'flash_disregarded'  => 'Alerta descartado. Quem edita ainda será solicitado a aceitar a nova versão ao entrar.',
            'flash_phrase_bad'   => 'A frase de confirmação não coincidiu, então o alerta não foi descartado.',
            'email_subject'      => 'Telaris: os Termos de Uso e a Política de Privacidade foram atualizados',
            'email_greeting'     => 'Olá,',
            'email_greeting_named' => 'Olá %s,',
            'email_intro'        => 'Os documentos que regem a sua conta de edição no Telaris foram atualizados:',
            'email_version_word' => 'versão',
            'email_next_login'   => 'Na próxima vez que você entrar, será solicitado revisar e aceitar a nova versão antes de continuar para o editor.',
            'email_read'         => 'Você pode lê-los aqui:',
        ],
        'fr' => [
            'disregard_phrase'   => "ignorer l'alerte",
            'alert_title'        => "Les personnes qui éditent n'ont pas été averties des documents mis à jour",
            'alert_intro'        => "Ces documents ont changé et aucun courriel n'a encore été envoyé à qui édite :",
            'alert_recipients'   => "personnes qui éditent devront les vérifier à leur prochaine connexion.",
            'alert_question'     => 'Les avertir par courriel maintenant pour qu\'elles le sachent ?',
            'preview_summary'    => 'Voir un aperçu du courriel',
            'preview_recipients' => "Le courriel ira à qui édite et n'a pas encore accepté la nouvelle version.",
            'send_button'        => 'Envoyer les avis',
            'disregard_summary'  => "Ignorer cette alerte",
            'disregard_explain'  => "Ignorer masque cette alerte sans envoyer de courriel. Il sera toujours demandé à qui édite d'accepter la nouvelle version à la prochaine connexion. Pour confirmer, tape la phrase ci-dessous exactement.",
            'disregard_field'    => 'Tape ceci pour confirmer :',
            'disregard_button'   => "Ignorer l'alerte",
            'flash_sent'         => 'Qui édite a été averti des documents mis à jour.',
            'flash_sent_none'    => "Il n'y avait personne à avertir ; l'alerte a été levée.",
            'flash_send_failed'  => "Impossible d'envoyer les avis (courriel non configuré ou échec d'envoi). L'alerte reste active.",
            'flash_disregarded'  => "Alerte ignorée. Il sera toujours demandé à qui édite d'accepter la nouvelle version à la connexion.",
            'flash_phrase_bad'   => "La phrase de confirmation ne correspondait pas, l'alerte n'a donc pas été ignorée.",
            'email_subject'      => "Telaris : les Conditions d'utilisation et la Politique de confidentialité ont été mises à jour",
            'email_greeting'     => 'Bonjour,',
            'email_greeting_named' => 'Bonjour %s,',
            'email_intro'        => "Les documents qui régissent ton compte d'édition sur Telaris ont été mis à jour :",
            'email_version_word' => 'version',
            'email_next_login'   => "À ta prochaine connexion, il te sera demandé de vérifier et d'accepter la nouvelle version avant de continuer vers l'éditeur.",
            'email_read'         => 'Tu peux les lire ici :',
        ],
    ];
}

/** Localized string for $locale, falling back to the key itself (never English). */
function consent_notify_t(string $key, string $locale): string {
    $all = consent_notify_strings();
    $loc = isset($all[$locale]) ? $locale : 'en';
    return $all[$loc][$key] ?? $key;
}
