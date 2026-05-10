<?php
declare(strict_types=1);

/**
 * Outgoing mail helper. Talks to the SMTP relay configured by MAIL_SMTP_* constants
 * in config.php; PHPMailer handles AUTH, STARTTLS, encoding, and multipart bodies.
 *
 * mail_is_configured(): returns true if all required constants are non-empty.
 * mail_send(): sends a single message; returns true on success, false otherwise.
 *              On failure, the PHPMailer error is written to error_log; never thrown.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * @return bool True iff the SMTP relay constants are populated; otherwise mail_send is a no-op.
 */
function mail_is_configured(): bool {
    return defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== ''
        && defined('MAIL_SMTP_PORT') && MAIL_SMTP_PORT !== ''
        && defined('MAIL_SMTP_USER') && MAIL_SMTP_USER !== ''
        && defined('MAIL_SMTP_PASS') && MAIL_SMTP_PASS !== ''
        && defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '';
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
    if (!mail_is_configured()) {
        error_log('mail_send: MAIL_SMTP_* not configured; skipping send to ' . $to);
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_SMTP_HOST;
        $mail->Port = (int) MAIL_SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_SMTP_USER;
        $mail->Password = MAIL_SMTP_PASS;
        $secure = defined('MAIL_SMTP_SECURE') ? strtolower((string) MAIL_SMTP_SECURE) : 'tls';
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

        $fromName = defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : '';
        $mail->setFrom(MAIL_FROM_ADDRESS, $fromName);
        $mail->addAddress($to, $toName ?? '');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text ?? trim(strip_tags($html));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('mail_send error to ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('mail_send exception to ' . $to . ': ' . $e->getMessage());
        return false;
    }
}
