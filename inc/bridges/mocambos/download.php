<?php
declare(strict_types=1);

/**
 * Download a file from a Mocambos API URL to local disk using streaming.
 * Returns the local file path on success, null on failure.
 *
 * Used by the orchestrator at inc/bridges/mocambos.php.
 */
function mocambos_download_file(string $url, string $destDir, string $prefix, ?Closure $log = null): ?string {
    $log ??= function(string $level, string $msg) {};

    // Same allow-list + private-IP gate as _mocambos_fetch_json. Refuse before
    // opening any socket; redirects are disabled below so each call validates
    // exactly one upstream.
    $safetyError = _mocambos_validate_safe_url($url);
    if ($safetyError !== null) {
        $log('ERROR', "Download refused: {$safetyError} ({$url})");
        return null;
    }

    $log('DEBUG', "Download: GET {$url}");

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
    ]);

    $src = @fopen($url, 'r', false, $ctx);
    if ($src === false) {
        $log('ERROR', "Download failed: could not open {$url}");
        return null;
    }

    // Detect extension from Content-Type
    $meta = stream_get_meta_data($src);
    $ext = 'bin';
    $contentType = 'unknown';
    $httpStatus = '';
    $headers = $meta['wrapper_data'] ?? [];
    foreach ($headers as $h) {
        // Capture HTTP status line
        if (preg_match('#^HTTP/[\d.]+ (\d+)#', $h, $m)) {
            $httpStatus = $m[1];
        }
        if (stripos($h, 'Content-Type:') === 0) {
            $ct = trim(substr($h, 13));
            $ct = explode(';', $ct)[0]; // strip charset
            $contentType = trim($ct);
            $mimeMap = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
                'video/mp4' => 'mp4', 'video/webm' => 'webm',
                'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav',
                'application/pdf' => 'pdf',
            ];
            $ext = $mimeMap[$contentType] ?? $ext;
            // Reject HTML responses — the API returned an error/landing page, not media
            if (str_starts_with($contentType, 'text/html')) {
                fclose($src);
                $log('WARN', "Download rejected: HTTP {$httpStatus}, Content-Type={$contentType} (expected media) from {$url}");
                return null;
            }
        }
    }

    $log('DEBUG', "Download: HTTP {$httpStatus}, Content-Type={$contentType}");

    $destPath = $destDir . '/' . $prefix . '.' . $ext;
    $dest = fopen($destPath, 'w');
    if ($dest === false) {
        fclose($src);
        $log('ERROR', "Download failed: could not write to {$destPath}");
        return null;
    }

    // Audit pass #4 (M1, v6.10.17): cap per-file download size. Without
    // this, a compromised or buggy upstream serving a 50GB stream would
    // happily keep writing for the full 30 minutes that Mocambos imports
    // are allowed to run, filling the operator's disk. 50 MB matches
    // Telaris's own upload ceiling (MAX_VIDEO_BYTES); media that would
    // exceed this would also be refused on the visitor-upload path.
    $maxBytes = defined('MOCAMBOS_DOWNLOAD_MAX_BYTES') ? (int)MOCAMBOS_DOWNLOAD_MAX_BYTES : 52_428_800;
    $bytes = 0;
    $overcap = false;
    while (!feof($src)) {
        $chunk = fread($src, 8192);
        if ($chunk === false) break;
        fwrite($dest, $chunk);
        $bytes += strlen($chunk);
        if ($bytes > $maxBytes) {
            $overcap = true;
            break;
        }
    }

    fclose($src);
    fclose($dest);

    if ($overcap) {
        @unlink($destPath);
        $log('WARN', "Download refused: upstream stream exceeded {$maxBytes}-byte cap ({$url})");
        return null;
    }

    // Delete 0-byte files
    if ($bytes === 0) {
        @unlink($destPath);
        $log('WARN', "Download failed: 0 bytes received from {$url}");
        return null;
    }

    $sizeKb = round($bytes / 1024, 1);
    $log('INFO', "Download OK: {$destPath} ({$sizeKb} KB, {$contentType})");
    return $destPath;
}
