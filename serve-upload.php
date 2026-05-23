<?php
declare(strict_types=1);

/**
 * Serves uploaded media files from the external storage directory.
 * Validates the path to prevent directory traversal attacks.
 * Supports HTTP Range requests (required for audio/video playback).
 *
 * AUTH POSTURE (audit Med-K, design decision):
 * Uploads are visitor-public by design. /uploads/{const_id}/{node_id}/file
 * paths are sequential and discoverable, which the audit flagged as a
 * potential leak vector if an editor uploads sensitive media to test
 * rendering. Telaris does not have a published/draft model — everything is
 * live — and the editorial-sovereignty stance is that media is published
 * the moment it is uploaded. Adding a wormhole-state auth gate (only serve
 * when the parent node is "published") would impose the platform-pattern
 * the project explicitly refuses; instead, editors are expected to upload
 * only material they intend to publish, and the documentation surfaces
 * this norm.
 *
 * If a future feature introduces a real draft concept, this is the right
 * place to add a gate: read the parent node's state and 403 when draft.
 */

require_once __DIR__ . '/config.php';

$requestPath = $_SERVER['REQUEST_URI'] ?? '';
// Strip query string
$requestPath = strtok($requestPath, '?');
// Remove /uploads/ prefix
$relativePath = substr($requestPath, strlen('/uploads/'));

if ($relativePath === false || $relativePath === '') {
    http_response_code(404);
    exit;
}

// Normalize and validate: no traversal, no null bytes. The realpath + prefix
// check at line 47 is the primary defence; the segment check here is a
// belt-and-braces gate that won't reject legitimate filenames containing '..'
// as part of a name (e.g. 'my..file.jpg'). Looks for '..' as a path segment.
if (str_contains($relativePath, "\0") || str_contains('/' . $relativePath, '/../')) {
    http_response_code(400);
    exit;
}

$fullPath = realpath(UPLOAD_DIR . '/' . $relativePath);

// realpath returns false if file doesn't exist; also verify it's inside UPLOAD_DIR
if ($fullPath === false || !str_starts_with($fullPath, realpath(UPLOAD_DIR) . '/')) {
    http_response_code(404);
    exit;
}

if (!is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    exit;
}

// Determine MIME type from file contents
$mime = mime_content_type($fullPath);
if ($mime === false) {
    $mime = 'application/octet-stream';
}

$size = filesize($fullPath);
$lastModified = filemtime($fullPath);

// Handle If-Modified-Since for caching
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $ifModified = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($ifModified !== false && $lastModified <= $ifModified) {
        http_response_code(304);
        exit;
    }
}

// Defence in depth: explicit Content-Type plus nosniff so browsers never
// downgrade to MIME sniffing on adversarial bytes. Content-Disposition
// inline keeps the media renderable in the page; basename strips any
// remaining path components from the filename we hand to the browser.
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
$dispositionName = basename($fullPath);
$dispositionAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', $dispositionName) ?? 'file';
header('Content-Disposition: inline; filename="' . $dispositionAscii . '"');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('Cache-Control: public, max-age=86400');
header('Accept-Ranges: bytes');

// Handle Range requests (required for audio/video seeking and some browsers' initial load)
if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m)) {
        http_response_code(416); // Range Not Satisfiable
        header("Content-Range: bytes */$size");
        exit;
    }

    $start = $m[1] !== '' ? (int)$m[1] : 0;
    $end = $m[2] !== '' ? (int)$m[2] : $size - 1;

    if ($start > $end || $start >= $size || $end >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }

    $length = $end - $start + 1;
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
    header("Content-Length: $length");

    $fp = fopen($fullPath, 'rb');
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);
} else {
    header('Content-Length: ' . $size);
    readfile($fullPath);
}
