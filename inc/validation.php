<?php
declare(strict_types=1);

/**
 * Shared validation constants and functions.
 * Extracted from api/nodes.php so they can be tested independently.
 */

/** Allowed node_type values (must match nodes.node_type ENUM). */
const NODE_TYPE_VALUES = ['object', 'portal'];

/** Allowed MIME types per upload category. */
const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const ALLOWED_AUDIO_MIMES = ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4', 'audio/aac', 'audio/webm', 'audio/x-m4a'];
const ALLOWED_VIDEO_MIMES = ['video/mp4'];

/** Video MIME types accepted for frame extraction (broader than upload — file is discarded after). */
const FRAME_EXTRACTABLE_VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm', 'video/mpeg', 'video/3gpp', 'video/x-ms-wmv', 'video/x-flv'];

/** Safe extensions derived from MIME type — avoids trusting client-supplied filenames. */
const MIME_TO_EXT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'audio/mpeg' => 'mp3',
    'audio/ogg'  => 'ogg',
    'audio/wav'  => 'wav',
    'audio/mp4'  => 'm4a',
    'audio/aac'  => 'aac',
    'audio/webm' => 'webm',
    'audio/x-m4a' => 'm4a',
    'video/mp4'       => 'mp4',
    'video/quicktime' => 'mov',
    'video/x-msvideo' => 'avi',
    'video/x-matroska' => 'mkv',
    'video/webm'      => 'webm',
    'video/mpeg'      => 'mpeg',
    'video/3gpp'      => '3gp',
    'video/x-ms-wmv'  => 'wmv',
    'video/x-flv'     => 'flv',
];

/** Maximum upload sizes. */
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;   // 10 MB
const MAX_AUDIO_BYTES = 50 * 1024 * 1024;   // 50 MB
const MAX_VIDEO_BYTES = 200 * 1024 * 1024;  // 200 MB

/**
 * Sanitize node_type from request data; return one of NODE_TYPE_VALUES or 'object'.
 */
function sanitizeNodeType(mixed $value): string {
    $s = is_string($value) ? trim($value) : '';
    return in_array($s, NODE_TYPE_VALUES, true) ? $s : 'object';
}

/**
 * Parse target_constellation_id from request data as nullable integer.
 */
function parseTargetConstellationId(mixed $value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $id = (int)$value;
        return $id >= 0 ? $id : null;
    }
    return null;
}

/**
 * Sanitize embed_code: only allow <iframe> tags with safe attributes.
 * Strips all other HTML tags and dangerous attributes (onload, onerror, etc.).
 * Returns sanitized HTML string, or null if input produces no valid output.
 */
function sanitizeEmbedCode(string $html): ?string {
    $html = trim($html);
    if ($html === '') {
        return null;
    }

    // Allowed iframe attributes (src must be http/https)
    $allowedAttrs = [
        'src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen',
        'title', 'loading', 'referrerpolicy', 'sandbox', 'style', 'class',
    ];

    // Parse with DOMDocument
    $dom = new DOMDocument();
    // Suppress warnings from malformed HTML; wrap in a root element
    @$dom->loadHTML('<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);

    $output = '';
    $iframes = $dom->getElementsByTagName('iframe');

    for ($i = 0; $i < $iframes->length; $i++) {
        $iframe = $iframes->item($i);

        // Validate src attribute — must be http/https
        $src = $iframe->getAttribute('src');
        if ($src !== '') {
            $scheme = strtolower((string)(parse_url($src, PHP_URL_SCHEME) ?? ''));
            if (!in_array($scheme, ['http', 'https'], true)) {
                continue; // Skip iframes with dangerous src schemes
            }
        }

        // Rebuild iframe with only allowed attributes
        $safeIframe = $dom->createElement('iframe');
        foreach ($allowedAttrs as $attr) {
            if ($iframe->hasAttribute($attr)) {
                $safeIframe->setAttribute($attr, $iframe->getAttribute($attr));
            }
        }
        // Always add allowfullscreen if it was present (boolean attribute)
        if ($iframe->hasAttribute('allowfullscreen')) {
            $safeIframe->setAttribute('allowfullscreen', '');
        }

        $tempDoc = new DOMDocument();
        $imported = $tempDoc->importNode($safeIframe, true);
        $tempDoc->appendChild($imported);
        $output .= trim($tempDoc->saveHTML());
    }

    return $output !== '' ? $output : null;
}

/**
 * Validate URL: must be a valid URL with http or https scheme only.
 * Blocks javascript:, data:, vbscript:, and other dangerous schemes.
 */
function validateSafeUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    return in_array($scheme, ['http', 'https'], true);
}

/**
 * Validate an uploaded file: check MIME type against allowlist and enforce size limit.
 * Sets $detectedMime to the actual MIME type on success.
 * Returns null on success, or an error string on failure.
 */
function validateUploadedFile(array $file, array $allowedMimes, int $maxBytes, string &$detectedMime = ''): ?string {
    if ($file['size'] > $maxBytes) {
        $mb = round($maxBytes / (1024 * 1024));
        return "File exceeds maximum allowed size ({$mb}MB)";
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string)$finfo->file($file['tmp_name']);
    if (!in_array($detectedMime, $allowedMimes, true)) {
        return "File type not allowed";
    }
    return null;
}
