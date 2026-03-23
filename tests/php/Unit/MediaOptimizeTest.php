<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for inc/media-optimize.php functions.
 */
final class MediaOptimizeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/telaris_media_test_' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
    }

    // --- media_tool_available ---

    public function testMediaToolAvailableFindsCommonTools(): void
    {
        // These should exist on the test server
        $this->assertTrue(media_tool_available('ls'));
        $this->assertTrue(media_tool_available('php'));
    }

    public function testMediaToolAvailableReturnsFalseForMissing(): void
    {
        $this->assertFalse(media_tool_available('nonexistent_binary_xyz_12345'));
    }

    // --- optimize_image ---

    public function testOptimizeImageResizesLargeJpeg(): void
    {
        if (!media_tool_available('convert') && !media_tool_available('magick')) {
            $this->markTestSkipped('ImageMagick not available');
        }

        // Create a 2000x1000 red JPEG
        $img = imagecreatetruecolor(2000, 1000);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        $path = $this->tmpDir . '/large.jpg';
        imagejpeg($img, $path, 100);
        imagedestroy($img);

        $this->assertFileExists($path);
        $before = getimagesize($path);
        $this->assertSame(2000, $before[0]);

        optimize_image($path, 1344);

        $after = getimagesize($path);
        $this->assertNotFalse($after);
        $this->assertLessThanOrEqual(1344, $after[0]);
        // Height should scale proportionally
        $this->assertLessThanOrEqual(672, $after[1]);
    }

    public function testOptimizeImageSkipsSmallImage(): void
    {
        // Create a 200x100 JPEG — already under maxWidth
        $img = imagecreatetruecolor(200, 100);
        $path = $this->tmpDir . '/small.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        $sizeBefore = filesize($path);
        $infoBefore = getimagesize($path);

        optimize_image($path, 1344);

        $infoAfter = getimagesize($path);
        $this->assertSame($infoBefore[0], $infoAfter[0]);
        $this->assertSame($infoBefore[1], $infoAfter[1]);
    }

    public function testOptimizeImageResizesPng(): void
    {
        if (!media_tool_available('convert') && !media_tool_available('magick')) {
            $this->markTestSkipped('ImageMagick not available');
        }

        $img = imagecreatetruecolor(1800, 900);
        $path = $this->tmpDir . '/large.png';
        imagepng($img, $path);
        imagedestroy($img);

        optimize_image($path, 1344);

        $after = getimagesize($path);
        $this->assertNotFalse($after);
        $this->assertLessThanOrEqual(1344, $after[0]);
    }

    public function testOptimizeImageHandlesNonexistentFile(): void
    {
        // Should not throw or error
        optimize_image('/nonexistent/path/image.jpg');
        $this->assertTrue(true);
    }

    public function testOptimizeImageHandlesNonImageFile(): void
    {
        $path = $this->tmpDir . '/notimage.jpg';
        file_put_contents($path, 'this is not an image');
        optimize_image($path);
        // File should still exist, unchanged
        $this->assertSame('this is not an image', file_get_contents($path));
    }

    // --- optimize_icon ---

    public function testOptimizeIconUsesSmallMaxWidth(): void
    {
        if (!media_tool_available('convert') && !media_tool_available('magick')) {
            $this->markTestSkipped('ImageMagick not available');
        }

        $img = imagecreatetruecolor(800, 800);
        $path = $this->tmpDir . '/icon.jpg';
        imagejpeg($img, $path, 100);
        imagedestroy($img);

        optimize_icon($path);

        $after = getimagesize($path);
        $this->assertNotFalse($after);
        $this->assertLessThanOrEqual(256, $after[0]);
        $this->assertLessThanOrEqual(256, $after[1]);
    }

    // --- optimize_audio ---

    public function testOptimizeAudioConvertsToMono(): void
    {
        if (!media_tool_available('ffmpeg') || !media_tool_available('ffprobe')) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        // Generate a short stereo WAV, then convert to MP3 for testing
        $wavPath = $this->tmpDir . '/test.wav';
        $mp3Path = $this->tmpDir . '/test.mp3';

        // Create 1-second stereo silence as WAV
        exec('ffmpeg -y -f lavfi -i anullsrc=r=44100:cl=stereo -t 1 ' . escapeshellarg($wavPath) . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Could not generate test WAV');
        }

        // Convert to stereo MP3 at 256kbps
        exec('ffmpeg -y -i ' . escapeshellarg($wavPath) . ' -b:a 256k -ac 2 ' . escapeshellarg($mp3Path) . ' 2>/dev/null', $out, $code);
        @unlink($wavPath);
        if ($code !== 0) {
            $this->markTestSkipped('Could not generate test MP3');
        }

        // Verify it's stereo before optimization
        $probeBefore = json_decode(shell_exec('ffprobe -v quiet -print_format json -show_streams ' . escapeshellarg($mp3Path)), true);
        $channelsBefore = 2;
        foreach ($probeBefore['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? '') === 'audio') {
                $channelsBefore = (int)$s['channels'];
                break;
            }
        }
        $this->assertSame(2, $channelsBefore);

        optimize_audio($mp3Path);

        // Verify it's now mono
        $probeAfter = json_decode(shell_exec('ffprobe -v quiet -print_format json -show_streams ' . escapeshellarg($mp3Path)), true);
        $channelsAfter = 0;
        foreach ($probeAfter['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? '') === 'audio') {
                $channelsAfter = (int)$s['channels'];
                break;
            }
        }
        $this->assertSame(1, $channelsAfter);
    }

    public function testOptimizeAudioSkipsAlreadyOptimized(): void
    {
        if (!media_tool_available('ffmpeg') || !media_tool_available('ffprobe')) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        // Create a mono 128kbps MP3
        $mp3Path = $this->tmpDir . '/optimized.mp3';
        exec('ffmpeg -y -f lavfi -i anullsrc=r=44100:cl=mono -t 1 -b:a 128k ' . escapeshellarg($mp3Path) . ' 2>/dev/null');

        $sizeBefore = filesize($mp3Path);
        $mtimeBefore = filemtime($mp3Path);

        // Small sleep so mtime would differ if file were rewritten
        usleep(100000);
        optimize_audio($mp3Path);

        // File should not have been rewritten (skip condition: mono + ≤160kbps)
        $this->assertSame($mtimeBefore, filemtime($mp3Path));
    }

    public function testOptimizeAudioHandlesNonexistentFile(): void
    {
        optimize_audio('/nonexistent/path/audio.mp3');
        $this->assertTrue(true);
    }

    // --- optimize_video ---

    public function testOptimizeVideoDownscalesTo720p(): void
    {
        if (!media_tool_available('ffmpeg') || !media_tool_available('ffprobe')) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        // Generate a 1-second 1920x1080 video
        $mp4Path = $this->tmpDir . '/test.mp4';
        exec('ffmpeg -y -f lavfi -i color=c=blue:s=1920x1080:d=1 -c:v libx264 -preset ultrafast -crf 30 ' . escapeshellarg($mp4Path) . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Could not generate test video');
        }

        optimize_video($mp4Path);

        $probe = json_decode(shell_exec('ffprobe -v quiet -print_format json -show_streams ' . escapeshellarg($mp4Path)), true);
        $width = 0;
        $height = 0;
        foreach ($probe['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? '') === 'video') {
                $width = (int)$s['width'];
                $height = (int)$s['height'];
                break;
            }
        }
        $this->assertLessThanOrEqual(1280, $width);
        $this->assertLessThanOrEqual(720, $height);
    }

    public function testOptimizeVideoSkipsSmallVideo(): void
    {
        if (!media_tool_available('ffmpeg') || !media_tool_available('ffprobe')) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        // Generate a 1-second 640x360 H.264 video (already under 720p)
        $mp4Path = $this->tmpDir . '/small.mp4';
        exec('ffmpeg -y -f lavfi -i color=c=red:s=640x360:d=1 -c:v libx264 -preset ultrafast -crf 30 ' . escapeshellarg($mp4Path) . ' 2>/dev/null');

        $sizeBefore = filesize($mp4Path);
        $mtimeBefore = filemtime($mp4Path);

        usleep(100000);
        optimize_video($mp4Path);

        // Should be skipped (h264, ≤720p, low bitrate)
        $this->assertSame($mtimeBefore, filemtime($mp4Path));
    }

    // --- extract_video_frame ---

    public function testExtractVideoFrameCreatesJpeg(): void
    {
        if (!media_tool_available('ffmpeg')) {
            $this->markTestSkipped('ffmpeg not available');
        }

        // Generate a 1-second blue video
        $mp4Path = $this->tmpDir . '/frame_test.mp4';
        exec('ffmpeg -y -f lavfi -i color=c=blue:s=320x240:d=1 -c:v libx264 -preset ultrafast ' . escapeshellarg($mp4Path) . ' 2>/dev/null');

        $jpgPath = $this->tmpDir . '/frame.jpg';
        $result = extract_video_frame($mp4Path, $jpgPath);

        $this->assertTrue($result);
        $this->assertFileExists($jpgPath);
        $this->assertGreaterThan(0, filesize($jpgPath));

        $info = getimagesize($jpgPath);
        $this->assertNotFalse($info);
        $this->assertSame(320, $info[0]);
        $this->assertSame(240, $info[1]);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function testExtractVideoFrameFailsOnNonVideo(): void
    {
        $txtPath = $this->tmpDir . '/notavideo.txt';
        file_put_contents($txtPath, 'not a video');
        $jpgPath = $this->tmpDir . '/frame_fail.jpg';

        $result = extract_video_frame($txtPath, $jpgPath);
        $this->assertFalse($result);
    }

    public function testExtractVideoFrameFailsOnNonexistent(): void
    {
        $result = extract_video_frame('/nonexistent/video.mp4', $this->tmpDir . '/out.jpg');
        $this->assertFalse($result);
    }
}
