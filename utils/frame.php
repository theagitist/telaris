<?php
declare(strict_types=1);

/**
 * Simplified Launch Interface: clean text and countdown.
 */

$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
$r = isset($_GET['r']) ? (int) $_GET['r'] : 0;
$g = isset($_GET['g']) ? (int) $_GET['g'] : 255;
$b = isset($_GET['b']) ? (int) $_GET['b'] : 204;
$nodeName = isset($_GET['node_name']) ? trim((string) $_GET['node_name']) : 'System';
$appName = isset($_GET['app']) ? trim((string) $_GET['app']) : 'Telaris';
$alertMsg = isset($_GET['alert_msg']) ? trim((string) $_GET['alert_msg']) : 'Close this window to come back';

$urlJson = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Launching: <?php echo htmlspecialchars($nodeName); ?> - <?php echo htmlspecialchars($appName); ?></title>
    <style>
        :root {
            --accent: rgb(<?php echo "$r,$g,$b"; ?>);
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        body {
            background: #000;
            color: #fff;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-family: var(--font-mono);
            text-align: center;
        }

        .content {
            margin-bottom: 3rem;
            z-index: 10;
            max-width: 80%;
        }

        .title {
            font-size: 1.25rem;
            text-transform: uppercase;
            letter-spacing: 0.15rem;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            font-size: 0.75rem;
            opacity: 0.5;
            text-transform: uppercase;
            letter-spacing: 0.05rem;
            margin-bottom: 2.5rem;
            line-height: 1.4;
        }

        .countdown {
            font-size: 3rem;
            font-weight: bold;
            color: var(--accent);
            text-shadow: 0 0 20px var(--accent);
        }

        .footer-note {
            position: fixed;
            bottom: 2rem;
            font-size: 0.65rem;
            opacity: 0.25;
            text-transform: uppercase;
            letter-spacing: 0.1rem;
        }
    </style>
</head>
<body>
    <div class="content">
        <div class="title">Launching <?php echo htmlspecialchars($nodeName); ?></div>
        <div class="subtitle"><?php echo nl2br(htmlspecialchars($alertMsg)); ?></div>
        <div class="countdown" id="cd">5</div>
    </div>

    <div class="footer-note">
        Mission Active
    </div>

    <script>
        (function() {
            const url = <?php echo $urlJson; ?>;
            const cdEl = document.getElementById('cd');
            let count = 5;

            const timer = setInterval(() => {
                count--;
                if (count > 0) {
                    cdEl.innerText = count;
                } else {
                    clearInterval(timer);
                    cdEl.innerText = "GO";
                    setTimeout(() => {
                        window.location.href = url;
                    }, 300);
                }
            }, 1000);
        })();
    </script>
</body>
</html>