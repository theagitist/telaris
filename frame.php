<?php
declare(strict_types=1);

/**
 * Link frame: opens in a new window with a top toolbar (node-colored) and iframe.
 * If the target sends X-Frame-Options (or CSP frame-ancestors) that block embedding,
 * we redirect the window to the URL so the content loads directly (no toolbar).
 * Query params: url (required), r, g, b (0-255), app (app name for "Go back to [App]").
 */

$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
$r = isset($_GET['r']) ? (int) $_GET['r'] : 60;
$g = isset($_GET['g']) ? (int) $_GET['g'] : 60;
$b = isset($_GET['b']) ? (int) $_GET['b'] : 80;
$app = isset($_GET['app']) ? trim((string) $_GET['app']) : 'Telaris';

// Only allow http/https URLs
$allowed = preg_match('#^https?://#i', $url);
if (!$allowed || $url === '') {
    $url = '';
}

// When we have a URL, check if the target blocks framing; if so, redirect so content loads in this window
if ($url !== '') {
    $blocksFraming = false;
    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'follow_location' => 0,
            'timeout' => 3,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0 (compatible; Telaris/1.0)\r\n"
        ]
    ]);
    $headers = @get_headers($url, false, $context);
    if (is_array($headers)) {
        foreach ($headers as $line) {
            $line = trim($line);
            if (stripos($line, 'X-Frame-Options:') === 0) {
                $value = trim(substr($line, 16));
                if (strtoupper($value) === 'DENY' || stripos($value, 'SAMEORIGIN') !== false) {
                    $blocksFraming = true;
                    break;
                }
            }
            if (stripos($line, 'Content-Security-Policy:') === 0) {
                if (preg_match('/frame-ancestors\s+[\'"]?none[\'"]?/i', $line)) {
                    $blocksFraming = true;
                    break;
                }
            }
        }
    }
    if ($blocksFraming) {
        $appEsc = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
        $message = "Close this window when you're done to go back to " . $app . ".";
        $messageJson = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $urlJson = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . $appEsc . '</title></head><body><script>';
        echo 'alert(' . $messageJson . ');';
        echo 'window.location.href = ' . $urlJson . ';';
        echo '</script></body></html>';
        exit;
    }
}

$r = max(0, min(255, $r));
$g = max(0, min(255, $g));
$b = max(0, min(255, $b));
$dr = (int) round($r * 0.45);
$dg = (int) round($g * 0.45);
$db = (int) round($b * 0.45);
$appEsc = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
$urlEsc = $url !== '' ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $appEsc; ?></title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; font-family: system-ui, sans-serif; }
        body { display: flex; flex-direction: column; background: #000; }
        #toolbar {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            min-height: 48px;
            background: rgba(<?php echo "$dr,$dg,$db"; ?>, 0.96);
            color: #fff;
        }
        #toolbar button {
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            background: transparent;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.5);
            cursor: pointer;
        }
        #toolbar button:hover { opacity: 0.9; }
        #content {
            flex: 1;
            width: 100%;
            border: 0;
            min-height: 0;
            background: #fff;
        }
        #empty { padding: 24px; color: #888; }
    </style>
</head>
<body>
    <div id="toolbar">
        <button type="button" onclick="window.close();" aria-label="Go back to app">← Go back to <?php echo $appEsc; ?></button>
    </div>
    <?php if ($url !== ''): ?>
        <iframe id="content" title="Link content" src="<?php echo $urlEsc; ?>"></iframe>
    <?php else: ?>
        <div id="empty">No URL provided or invalid URL.</div>
    <?php endif; ?>
</body>
</html>
