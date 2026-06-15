<?php
declare(strict_types=1);

/**
 * Outgoing mail helper. Talks to an SMTP relay configured EITHER from the admin
 * Global Settings (stored in system_meta, key 'mail_smtp_config') OR, as a
 * fallback, from the MAIL_SMTP_* constants in config.php. The DB settings take
 * precedence key-by-key so an operator can configure mail from the UI without
 * editing config.php (which carries the www-data group-perms hazard). PHPMailer
 * handles AUTH, STARTTLS, encoding, and multipart bodies.
 *
 * mail_settings_get(): the effective settings (DB over constants).
 * mail_settings_save(): persist a settings array to system_meta.
 * mail_is_configured(): true iff the effective settings are complete.
 * mail_send(): sends a single message; returns true on success, false otherwise.
 *              On failure, the PHPMailer error is written to error_log; never thrown.
 *              Recipient address is redacted to a SHA-256 prefix in logs so the
 *              error log is not a PII channel.
 * mail_send_test(): send a branded "mail is working" message to one address.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Stable, opaque tag for an email address — first 12 hex chars of its SHA-256.
 * Used in error_log to identify which recipient a failure relates to without
 * writing the address itself. Hashing is deterministic so support can still
 * cross-reference between a user-reported address and a log line.
 */
function mail_recipient_tag(string $to): string {
    return 'addr:' . substr(hash('sha256', strtolower(trim($to))), 0, 12);
}

/**
 * system_meta key that holds the operator-set SMTP config (JSON, incl. the SMTP
 * password). SECURITY INVARIANT: this is the one settings value that is a secret.
 * system_meta is NOT part of any backup, snapshot, or federation export today
 * (backup.php captures only galaxies/clusters/users/nodes/hotglue), so the
 * password never leaves the instance in a portable file. If a future change adds
 * system_meta to any export, exclude this key (redact the 'pass' field).
 */
const MAIL_SETTINGS_META_KEY = 'mail_smtp_config';

/**
 * Effective SMTP settings: operator values stored in system_meta override the
 * config.php constants key-by-key (a blank stored value falls back to the
 * constant, so a partial DB config still inherits the rest from config.php).
 *
 * @return array{host:string,port:string,user:string,pass:string,secure:string,from_address:string,from_name:string}
 */
function mail_settings_get(): array {
    $effective = [
        'host'         => defined('MAIL_SMTP_HOST') ? (string) MAIL_SMTP_HOST : '',
        'port'         => defined('MAIL_SMTP_PORT') ? (string) MAIL_SMTP_PORT : '587',
        'user'         => defined('MAIL_SMTP_USER') ? (string) MAIL_SMTP_USER : '',
        'pass'         => defined('MAIL_SMTP_PASS') ? (string) MAIL_SMTP_PASS : '',
        'secure'       => defined('MAIL_SMTP_SECURE') ? (string) MAIL_SMTP_SECURE : 'tls',
        'from_address' => defined('MAIL_FROM_ADDRESS') ? (string) MAIL_FROM_ADDRESS : '',
        'from_name'    => defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : '',
    ];
    if (!function_exists('db_system_meta_get')) {
        return $effective;
    }
    $raw = db_system_meta_get(MAIL_SETTINGS_META_KEY);
    if (!is_string($raw) || $raw === '') {
        return $effective;
    }
    $stored = json_decode($raw, true);
    if (!is_array($stored)) {
        return $effective;
    }
    foreach (array_keys($effective) as $k) {
        if (isset($stored[$k]) && is_string($stored[$k]) && $stored[$k] !== '') {
            $effective[$k] = $stored[$k];
        }
    }
    return $effective;
}

/**
 * Persist an SMTP settings array to system_meta. Only the recognized keys are
 * stored. Returns true on success. Throwing is left to the caller's discretion;
 * a failure here is logged and surfaced as false.
 */
function mail_settings_save(array $settings): bool {
    if (!function_exists('db_system_meta_set')) {
        error_log('mail_settings_save: db_system_meta_set unavailable; cannot persist');
        return false;
    }
    $allowed = ['host', 'port', 'user', 'pass', 'secure', 'from_address', 'from_name'];
    $clean = [];
    foreach ($allowed as $k) {
        $clean[$k] = isset($settings[$k]) && is_string($settings[$k]) ? trim($settings[$k]) : '';
    }
    // Normalize the encryption mode to the three values mail_send() understands.
    $clean['secure'] = in_array(strtolower($clean['secure']), ['tls', 'ssl', 'none'], true)
        ? strtolower($clean['secure'])
        : 'tls';
    try {
        db_system_meta_set(MAIL_SETTINGS_META_KEY, json_encode($clean, JSON_UNESCAPED_SLASHES));
        return true;
    } catch (Throwable $e) {
        error_log('mail_settings_save: ' . (function_exists('db_safe_error_descriptor') ? db_safe_error_descriptor($e) : 'persist failed'));
        return false;
    }
}

/**
 * @return bool True iff the effective SMTP settings are complete; otherwise mail_send is a no-op.
 */
function mail_is_configured(): bool {
    $s = mail_settings_get();
    return $s['host'] !== '' && $s['port'] !== '' && $s['user'] !== ''
        && $s['pass'] !== '' && $s['from_address'] !== '';
}

/**
 * Send a transactional email.
 *
 * @param string $to        Recipient address.
 * @param string $subject   Plain-text subject line.
 * @param string $html      HTML body (used as the primary part).
 * @param string|null $text Optional plain-text alternative; auto-derived from $html if null.
 * @param string|null $toName Optional display name for the recipient.
 */
function mail_send(string $to, string $subject, string $html, ?string $text = null, ?string $toName = null): bool {
    // Dry-run short-circuit: when MAIL_DRY_RUN is defined and truthy (the test
    // bootstrap sets it), no message leaves the host. The recipient is redacted
    // to a SHA-256 tag and the subject logged so a test run is still traceable,
    // and true is returned so callers that branch on send-success behave as if
    // the message went out. Production never defines this constant, so live
    // sends are unaffected.
    if (defined('MAIL_DRY_RUN') && MAIL_DRY_RUN) {
        error_log('mail_send: MAIL_DRY_RUN active; not sending to ' . mail_recipient_tag($to) . ' subject=' . $subject);
        return true;
    }

    if (!mail_is_configured()) {
        error_log('mail_send: MAIL_SMTP_* not configured; skipping send to ' . mail_recipient_tag($to));
        return false;
    }

    $s = mail_settings_get();
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $s['host'];
        $mail->Port = (int) $s['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $s['user'];
        $mail->Password = $s['pass'];
        $secure = strtolower($s['secure']);
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $mail->setFrom($s['from_address'], $s['from_name']);
        $mail->addAddress($to, $toName ?? '');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text ?? trim(strip_tags($html));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('mail_send error to ' . mail_recipient_tag($to) . ': ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('mail_send exception to ' . mail_recipient_tag($to) . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Send a branded "mail is working" test message to one address, used by the
 * admin Mail settings "Send test email" button. Renders through the on-brand
 * shell. Returns true on success. Honours MAIL_DRY_RUN like mail_send.
 *
 * @param string $to     Recipient (typically the signed-in admin's own email).
 * @param string $locale UI locale for the message copy (en|es|pt|fr).
 */
function mail_send_test(string $to, string $locale = 'en'): bool {
    $locale = in_array($locale, ['en', 'es', 'pt', 'fr'], true) ? $locale : 'en';
    $copy = [
        'en' => [
            'subject'  => 'Telaris test email',
            'heading'  => 'Mail is working',
            'para'     => 'This is a test message from your Telaris instance. If you are reading it, the SMTP settings are correct and transactional email (sign-in links, enrolment confirmations, password resets) will be delivered.',
        ],
        'es' => [
            'subject'  => 'Correo de prueba de Telaris',
            'heading'  => 'El correo funciona',
            'para'     => 'Este es un mensaje de prueba de tu instancia de Telaris. Si lo estás leyendo, los ajustes de SMTP son correctos y el correo transaccional (enlaces de inicio de sesión, confirmaciones de alta, restablecimientos de contraseña) se entregará.',
        ],
        'pt' => [
            'subject'  => 'Email de teste do Telaris',
            'heading'  => 'O email está funcionando',
            'para'     => 'Esta é uma mensagem de teste da sua instância do Telaris. Se você está lendo, os ajustes de SMTP estão corretos e o email transacional (links de entrada, confirmações de cadastro, redefinições de senha) será entregue.',
        ],
        'fr' => [
            'subject'  => "Courriel de test Telaris",
            'heading'  => 'Le courriel fonctionne',
            'para'     => "Ceci est un message de test de ton instance Telaris. Si tu le lis, les réglages SMTP sont corrects et les courriels transactionnels (liens de connexion, confirmations d'inscription, réinitialisations de mot de passe) seront livrés.",
        ],
    ];
    $c = $copy[$locale];
    require_once __DIR__ . '/email-template.php';
    $rendered = telaris_email_render([
        'heading'    => $c['heading'],
        'paragraphs' => [$c['para']],
        'locale'     => $locale,
    ]);
    return mail_send($to, $c['subject'], $rendered['html'], $rendered['text']);
}
