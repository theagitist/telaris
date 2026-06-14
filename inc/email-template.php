<?php
declare(strict_types=1);

/**
 * On-brand transactional email shell for Telaris system mail (no-reply@telaris.ca).
 *
 * Brand canon (vault Documentation/Brand book/{Palette,Voice and tone}):
 *   - System palette: Void #000000 ground, Aurora white #e8eef0 body text,
 *     Aurora 60 for secondary text, Wormhole mint #00ffcc as a SIGNAL only
 *     (the CTA border/label, the wordmark dot, the hairline), never body text.
 *   - Monospace everywhere.
 *   - Tagline "weaving memory" (lowercase, letter-spaced) in the footer.
 *   - Voice: sentence case, no exclamation, no promotional flourish; lead with
 *     the action, then the facts, then the link.
 *
 * Email clients are hostile to modern CSS, so every style is inline and the
 * layout is a single centred container. Returns both an HTML body and a
 * plain-text alternative so mail_send() has a real multipart message.
 */

/**
 * Render a Telaris system email.
 *
 * @param array{
 *   heading: string,
 *   paragraphs: list<string>,
 *   cta?: array{label:string,url:string}|null,
 *   note?: string|null,
 *   locale?: string
 * } $opts
 * @return array{html:string,text:string}
 */
function telaris_email_render(array $opts): array
{
    $heading    = (string)($opts['heading'] ?? '');
    $paragraphs = array_values(array_filter((array)($opts['paragraphs'] ?? []), 'is_string'));
    $cta        = $opts['cta'] ?? null;
    $note       = isset($opts['note']) ? (string)$opts['note'] : null;
    $tagline    = 'weaving memory';

    $void      = '#000000';
    $panel     = '#0a0a0c';
    $aurora    = '#e8eef0';
    $aurora60  = 'rgba(232,238,240,0.6)';
    $aurora28  = 'rgba(232,238,240,0.28)';
    $mint      = '#00ffcc';
    $mintLine  = 'rgba(0,255,204,0.4)';
    $border    = 'rgba(255,255,255,0.08)';
    $mono      = "'SF Mono','JetBrains Mono',Menlo,Consolas,'Liberation Mono',monospace";

    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $paraHtml = '';
    foreach ($paragraphs as $p) {
        $paraHtml .= '<p style="margin:0 0 18px;color:' . $aurora . ';font-size:16px;line-height:1.7;">' . $esc($p) . '</p>';
    }

    $ctaHtml = '';
    if (is_array($cta) && !empty($cta['url']) && !empty($cta['label'])) {
        $ctaHtml =
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;">'
            . '<tr><td style="border:1px solid ' . $mintLine . ';border-radius:4px;">'
            . '<a href="' . $esc((string)$cta['url']) . '" '
            . 'style="display:inline-block;padding:13px 26px;font-family:' . $mono . ';font-size:15px;'
            . 'letter-spacing:0.04em;color:' . $mint . ';text-decoration:none;">'
            . $esc((string)$cta['label']) . '</a>'
            . '</td></tr></table>';
    }

    $noteHtml = '';
    if ($note !== null && $note !== '') {
        $noteHtml = '<p style="margin:0 0 8px;color:' . $aurora60 . ';font-size:13px;line-height:1.65;">' . $esc($note) . '</p>';
    }

    // Plain-link fallback under the button so a stripped CTA is still actionable.
    $linkFallbackHtml = '';
    if (is_array($cta) && !empty($cta['url'])) {
        $linkFallbackHtml =
            '<p style="margin:0 0 16px;color:' . $aurora60 . ';font-size:13px;line-height:1.65;word-break:break-all;">'
            . $esc((string)$cta['url']) . '</p>';
    }

    $html =
        '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
        . '<meta name="color-scheme" content="dark"></head>'
        . '<body style="margin:0;padding:0;background:' . $void . ';">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $void . ';">'
        . '<tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="max-width:480px;background:' . $panel . ';border:1px solid ' . $border . ';border-radius:8px;">'
        . '<tr><td style="padding:28px 28px 8px;">'
        // wordmark: Aurora white, letter-spaced, with a mint status dot (signal)
        . '<div style="font-family:' . $mono . ';font-size:18px;letter-spacing:0.22em;color:' . $aurora . ';text-transform:uppercase;">'
        . 'Telaris <span style="color:' . $mint . ';">&bull;</span></div>'
        . '<div style="height:1px;background:' . $mintLine . ';margin:14px 0 22px;"></div>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px;">'
        . '<h1 style="margin:0 0 18px;font-family:' . $mono . ';font-size:21px;font-weight:600;line-height:1.4;color:' . $aurora . ';">' . $esc($heading) . '</h1>'
        . '<div style="font-family:' . $mono . ';">' . $paraHtml . $ctaHtml . $linkFallbackHtml . $noteHtml . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px 28px;">'
        . '<div style="height:1px;background:' . $border . ';margin:0 0 16px;"></div>'
        . '<div style="font-family:' . $mono . ';font-size:12px;letter-spacing:0.18em;color:' . $aurora28 . ';text-transform:lowercase;">' . $esc($tagline) . '</div>'
        . '<p style="margin:10px 0 0;font-family:' . $mono . ';font-size:12px;line-height:1.6;color:' . $aurora28 . ';">'
        . 'This message was sent by an automated address. Replies are not monitored.</p>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    // Plain-text alternative.
    $textLines = [$heading, ''];
    foreach ($paragraphs as $p) {
        $textLines[] = $p;
        $textLines[] = '';
    }
    if (is_array($cta) && !empty($cta['url']) && !empty($cta['label'])) {
        $textLines[] = strtoupper((string)$cta['label']) . ': ' . (string)$cta['url'];
        $textLines[] = '';
    }
    if ($note !== null && $note !== '') {
        $textLines[] = $note;
        $textLines[] = '';
    }
    $textLines[] = '--';
    $textLines[] = 'Telaris · ' . $tagline;
    $textLines[] = 'This message was sent by an automated address. Replies are not monitored.';
    $text = implode("\n", $textLines);

    return ['html' => $html, 'text' => $text];
}
