<?php
declare(strict_types=1);

/**
 * Post-upload media optimization functions.
 * All functions optimize files IN-PLACE. If the required tool is unavailable
 * or optimization fails, the original file is left untouched.
 *
 * THREADING NOTE (audit Med-J, queued):
 * Optimization currently runs synchronously inside the upload request and
 * shells out to convert / jpegoptim / ffmpeg / cwebp. Large videos can pin
 * an FPM worker for tens of seconds. Migrating to a background queue is a
 * real architectural change — queue table, worker process, retry / DLQ,
 * UI "processing" state. Tracked on ROADMAP under ^async-media-pipeline.
 * Until then, the set_time_limit cap (inc/snapshots.php pattern) and the
 * FPM pool's max_children are the operational safeguards.
 */

/**
 * Check if a command-line tool is available.
 */
function media_tool_available(string $bin): bool {
    static $cache = [];
    if (isset($cache[$bin])) return $cache[$bin];
    exec('which ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
    $cache[$bin] = $code === 0;
    return $cache[$bin];
}

/**
 * Optimize an uploaded image in-place.
 * Resizes to $maxWidth (retina-ready), strips metadata, compresses.
 *
 * @param string $filePath Absolute path to the image file
 * @param int $maxWidth Maximum width in pixels (default 1344 = 2x 672px info box)
 */
function optimize_image(string $filePath, int $maxWidth = 1344): void {
    if (!is_file($filePath) || !is_readable($filePath)) return;

    $info = @getimagesize($filePath);
    if ($info === false) return;

    $mime = $info['mime'] ?? '';

    // Determine the convert binary (ImageMagick 7 uses 'magick', 6 uses 'convert')
    $convertBin = null;
    if (media_tool_available('magick')) {
        $convertBin = 'magick';
    } elseif (media_tool_available('convert')) {
        $convertBin = 'convert';
    }

    if ($mime === 'image/jpeg') {
        // Resize + strip + compress with ImageMagick
        if ($convertBin !== null) {
            $escaped = escapeshellarg($filePath);
            exec("{$convertBin} {$escaped} -resize {$maxWidth}x\\> -strip -quality 82 -interlace Plane {$escaped} 2>/dev/null", $out, $code);
        }
        // Further optimize with jpegoptim
        if (media_tool_available('jpegoptim')) {
            exec('jpegoptim --strip-all --max=82 --quiet ' . escapeshellarg($filePath) . ' 2>/dev/null');
        }
        // Privacy floor: if neither ImageMagick nor jpegoptim was available
        // the image still has its EXIF block (potentially GPS coords from a
        // phone). Re-encode through GD when it's loaded; GD does not preserve
        // EXIF on write. This is lossy at quality 82 but the upload would
        // otherwise carry location data straight to visitors.
        if ($convertBin === null && !media_tool_available('jpegoptim') && extension_loaded('gd')) {
            $img = @imagecreatefromjpeg($filePath);
            if ($img !== false) {
                @imagejpeg($img, $filePath, 82);
                imagedestroy($img);
            }
        }
    } elseif ($mime === 'image/png') {
        // Resize with ImageMagick
        if ($convertBin !== null) {
            $escaped = escapeshellarg($filePath);
            exec("{$convertBin} {$escaped} -resize {$maxWidth}x\\> -strip {$escaped} 2>/dev/null");
        }
        // Optimize with optipng
        if (media_tool_available('optipng')) {
            exec('optipng -o2 -quiet ' . escapeshellarg($filePath) . ' 2>/dev/null');
        }
        // PNG EXIF fallback (rare but possible; some phones embed XMP in PNGs).
        if ($convertBin === null && !media_tool_available('optipng') && extension_loaded('gd')) {
            $img = @imagecreatefrompng($filePath);
            if ($img !== false) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                @imagepng($img, $filePath);
                imagedestroy($img);
            }
        }
    } elseif ($mime === 'image/webp') {
        // Resize with ImageMagick, then re-compress with cwebp
        if ($convertBin !== null) {
            $escaped = escapeshellarg($filePath);
            exec("{$convertBin} {$escaped} -resize {$maxWidth}x\\> -strip {$escaped} 2>/dev/null");
        }
        if (media_tool_available('cwebp')) {
            $tmp = $filePath . '.tmp.webp';
            exec('cwebp -q 80 -quiet ' . escapeshellarg($filePath) . ' -o ' . escapeshellarg($tmp) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && is_file($tmp)) {
                rename($tmp, $filePath);
            } else {
                @unlink($tmp);
            }
        }
    }
    // GIF: leave as-is (animations would be destroyed)
}

/**
 * Extract the first frame of a video file as a JPEG image.
 * Returns the path to the generated JPEG, or null on failure.
 *
 * @param string $videoPath Absolute path to the video file
 * @param string $outputPath Absolute path for the output JPEG
 */
function extract_video_frame(string $videoPath, string $outputPath): bool {
    if (!is_file($videoPath) || !media_tool_available('ffmpeg')) return false;

    $escaped = escapeshellarg($videoPath);
    $outEscaped = escapeshellarg($outputPath);
    exec("ffmpeg -y -i {$escaped} -vframes 1 -q:v 2 {$outEscaped} 2>/dev/null", $out, $code);

    return $code === 0 && is_file($outputPath) && filesize($outputPath) > 0;
}

/**
 * Optimize an uploaded icon in-place (small 3D scene element).
 */
function optimize_icon(string $filePath): void {
    optimize_image($filePath, 256);
}

/**
 * Optimize an uploaded audio file in-place.
 * Re-encodes to mono, 44.1kHz, 128kbps in the same container format.
 */
function optimize_audio(string $filePath): void {
    if (!is_file($filePath) || !is_readable($filePath)) return;
    if (!media_tool_available('ffprobe') || !media_tool_available('ffmpeg')) return;

    $escaped = escapeshellarg($filePath);

    // Probe current file
    $probeJson = shell_exec("ffprobe -v quiet -print_format json -show_format -show_streams {$escaped} 2>/dev/null");
    if ($probeJson === null) return;
    $probe = json_decode($probeJson, true);
    if (!is_array($probe)) return;

    $format = $probe['format']['format_name'] ?? '';
    $bitrate = (int)($probe['format']['bit_rate'] ?? 0);
    $channels = 2;
    foreach ($probe['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'audio') {
            $channels = (int)($stream['channels'] ?? 2);
            break;
        }
    }

    // Skip if already optimized (mono, ≤160kbps)
    if ($channels <= 1 && $bitrate > 0 && $bitrate <= 160000) return;

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $tmp = $filePath . '.tmp.' . $ext;

    // Choose codec based on container format
    $codecArgs = match ($ext) {
        'mp3' => '-c:a libmp3lame -b:a 128k',
        'ogg' => '-c:a libvorbis -b:a 128k',
        'm4a', 'aac' => '-c:a aac -b:a 128k',
        'webm' => '-c:a libopus -b:a 96k',
        default => '-c:a libmp3lame -b:a 128k', // wav and others → re-encode as same ext
    };

    $tmpEscaped = escapeshellarg($tmp);
    set_time_limit(120);
    exec("ffmpeg -y -i {$escaped} -ac 1 -ar 44100 {$codecArgs} {$tmpEscaped} 2>/dev/null", $out, $code);

    if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
        rename($tmp, $filePath);
    } else {
        @unlink($tmp);
    }
}

/**
 * Optimize an uploaded video file in-place.
 * Re-encodes to H.264 720p, CRF 28, AAC 128kbps, with faststart for streaming.
 */
function optimize_video(string $filePath): void {
    if (!is_file($filePath) || !is_readable($filePath)) return;
    if (!media_tool_available('ffprobe') || !media_tool_available('ffmpeg')) return;

    $escaped = escapeshellarg($filePath);

    // Probe current file
    $probeJson = shell_exec("ffprobe -v quiet -print_format json -show_format -show_streams {$escaped} 2>/dev/null");
    if ($probeJson === null) return;
    $probe = json_decode($probeJson, true);
    if (!is_array($probe)) return;

    $bitrate = (int)($probe['format']['bit_rate'] ?? 0);
    $width = 0;
    $height = 0;
    $codec = '';
    foreach ($probe['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'video') {
            $width = (int)($stream['width'] ?? 0);
            $height = (int)($stream['height'] ?? 0);
            $codec = $stream['codec_name'] ?? '';
            break;
        }
    }

    // Skip if already optimized (H.264, ≤720p, ≤2Mbps)
    if ($codec === 'h264' && $height <= 720 && $width <= 1280 && $bitrate > 0 && $bitrate <= 2000000) return;

    $tmp = $filePath . '.tmp.mp4';
    $tmpEscaped = escapeshellarg($tmp);

    set_time_limit(300);
    exec("ffmpeg -y -i {$escaped} -vf \"scale='min(1280,iw)':'min(720,ih)':force_original_aspect_ratio=decrease\" -c:v libx264 -crf 28 -preset fast -c:a aac -b:a 128k -movflags +faststart {$tmpEscaped} 2>/dev/null", $out, $code);

    if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
        rename($tmp, $filePath);
    } else {
        @unlink($tmp);
    }
}
