<?php
declare(strict_types=1);

/**
 * Simplified Launch Interface: clean text, countdown, and a minimalist SVG rocket.
 */

$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
$r = isset($_GET['r']) ? (int) $_GET['r'] : 0;
$g = isset($_GET['g']) ? (int) $_GET['g'] : 255;
$b = isset($_GET['b']) ? (int) $_GET['b'] : 204;
$nodeName = isset($_GET['node_name']) ? trim((string) $_GET['node_name']) : 'System';

$urlJson = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Launching: <?php echo htmlspecialchars($nodeName); ?></title>
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
        }

        .countdown {
            font-size: 3rem;
            font-weight: bold;
            color: var(--accent);
            text-shadow: 0 0 20px var(--accent);
        }

        /* Rocket Animation */
        .rocket-stage {
            position: relative;
            width: 60px;
            height: 120px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .rocket-svg {
            width: 40px;
            height: auto;
            transition: transform 0.8s cubic-bezier(0.45, 0, 0.55, 1);
        }

        body.launched .rocket-svg {
            transform: translateY(-130vh);
        }

        .exhaust-flame {
            width: 12px;
            height: 40px;
            background: linear-gradient(to bottom, var(--accent), transparent);
            border-radius: 0 0 100px 100px;
            margin-top: 4px;
            opacity: 0;
            filter: blur(4px);
        }

        body.shaking .exhaust-flame {
            opacity: 1;
            animation: flicker 0.1s infinite;
        }

        @keyframes flicker {
            0%, 100% { height: 35px; opacity: 0.7; transform: scaleX(1); }
            50% { height: 50px; opacity: 1; transform: scaleX(1.2); }
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
        <div class="subtitle">Close this window to come back</div>
        <div class="countdown" id="cd">3</div>
    </div>

    <div class="rocket-stage">
        <svg class="rocket-svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <!-- Rocket Body & Fins -->
            <path fill="#ffffff" d="M12,2C12,2 7,5.66 7,12C7,14 7,15 7,15L5,17V19L10,18L12,22L14,18L19,19V17L17,15C17,15 17,14 17,12C17,5.66 12,2 12,2" />
            <!-- Nose Cone (Red Tip) -->
            <path fill="#ff4444" d="M12,2C12,2 9.5,3.8 8.5,6.5C10.5,5.5 13.5,5.5 15.5,6.5C14.5,3.8 12,2 12,2Z" />
            <!-- Window -->
            <circle fill="#333333" cx="12" cy="12" r="2" />
        </svg>
        <div class="exhaust-flame"></div>
    </div>

    <div class="footer-note">
        Mission Active
    </div>

    <script>
        (function() {
            const url = <?php echo $urlJson; ?>;
            const cdEl = document.getElementById('cd');
            let count = 3;

            const timer = setInterval(() => {
                count--;
                if (count > 0) {
                    cdEl.innerText = count;
                } else if (count === 0) {
                    cdEl.innerText = "GO";
                    document.body.classList.add('shaking');
                } else {
                    clearInterval(timer);
                    document.body.classList.remove('shaking');
                    document.body.classList.add('launched');
                    setTimeout(() => {
                        window.location.href = url;
                    }, 600);
                }
            }, 1000);
        })();
    </script>
</body>
</html>