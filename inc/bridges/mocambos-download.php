<?php
declare(strict_types=1);

/**
 * Download a file from a Mocambos API URL to local disk using streaming.
 * Returns the local file path on success, null on failure.
 *
 * Shared between api/mocambos.php and admin/cli/import_mocambos.php.
 */
function mocambos_download_file(string $url, string $destDir, string $prefix, ?Closure $log = null): ?string {
    $log ??= function(string $level, string $msg) {};

    $log('DEBUG', "Download: GET {$url}");

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => true,
            'max_redirects' => 5,
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

    $bytes = 0;
    while (!feof($src)) {
        $chunk = fread($src, 8192);
        if ($chunk === false) break;
        fwrite($dest, $chunk);
        $bytes += strlen($chunk);
    }

    fclose($src);
    fclose($dest);

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
