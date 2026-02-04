<?php
declare(strict_types=1);

/**
 * Node link handler: opens in a new window, shows message with countdown progress bar and Close button, then redirects.
 * Styled with the clicked node's color (r, g, b). Query params: url (required), r, g, b (0-255), app, alert_msg.
 */

$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
$r = isset($_GET['r']) ? (int) $_GET['r'] : 60;
$g = isset($_GET['g']) ? (int) $_GET['g'] : 60;
$b = isset($_GET['b']) ? (int) $_GET['b'] : 80;
$app = isset($_GET['app']) ? trim((string) $_GET['app']) : 'Telaris';
$alertMessage = isset($_GET['alert_msg']) ? trim((string) $_GET['alert_msg']) : "Close this window when you're done to go back to " . $app . ".";

$r = max(0, min(255, $r));
$g = max(0, min(255, $g));
$b = max(0, min(255, $b));
$dr = (int) round($r * 0.25);
$dg = (int) round($g * 0.25);
$db = (int) round($b * 0.25);

$allowed = preg_match('#^https?://#i', $url);
if (!$allowed || $url === '') {
    $appEsc = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . $appEsc . '</title></head><body><p>No URL provided or invalid URL.</p></body></html>';
    exit;
}

$appEsc = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
$messageEsc = htmlspecialchars($alertMessage, ENT_QUOTES, 'UTF-8');
$urlJson = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?php echo $appEsc; ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; box-sizing: border-box; background: #000; }
        .alert-window { background: rgb(<?php echo "$r,$g,$b"; ?>); border: 2px solid rgba(255,255,255,0.3); border-radius: 8px; padding: 24px; max-width: 360px; box-shadow: 0 4px 24px rgba(0,0,0,0.5); }
        .message { margin: 0 0 16px 0; text-align: center; font-size: 1rem; color: #000; line-height: 1.4; }
        .progress-wrap { width: 100%; max-width: 200px; height: 4px; background: rgba(0,0,0,0.25); border-radius: 2px; overflow: hidden; margin: 0 auto 16px; }
        .progress-bar { height: 100%; width: 100%; background: rgba(0,0,0,0.5); border-radius: 2px; transition: width 2s linear; opacity: 0.5; }
        .progress-bar.done { width: 0%; }
        .btn-wrap { text-align: center; }
        .btn { padding: 8px 20px; font-size: 0.95rem; border-radius: 6px; border: 1px solid rgba(0,0,0,0.5); background: rgba(255,255,255,0.9); color: #000; cursor: pointer; }
        .btn:hover { background: #fff; }
    </style>
</head>
<body>
    <div class="alert-window">
        <p class="message"><?php echo $messageEsc; ?></p>
        <div class="progress-wrap" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" id="progressBar"></div>
        </div>
        <div class="btn-wrap">
            <button type="button" class="btn" id="closeBtn">Close</button>
        </div>
    </div>
    <script>
        (function() {
            var url = <?php echo $urlJson; ?>;
            var bar = document.getElementById('progressBar');
            var closeBtn = document.getElementById('closeBtn');
            function go() {
                window.location.href = url;
            }
            closeBtn.addEventListener('click', go);
            bar.offsetHeight;
            bar.classList.add('done');
            var t = setTimeout(go, 2000);
            closeBtn.addEventListener('click', function() { clearTimeout(t); });
        })();
    </script>
</body>
</html>
