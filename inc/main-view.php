<?php
/**
 * Main view: HTML shell for the 3D network.
 * Expects: $projectName, $projectTagline, $isEditorOrAdmin (set by bootstrap).
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($projectName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($projectTagline); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($projectName); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($projectTagline); ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($projectName); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($projectTagline); ?>">
    <script src="js/tailwind.min.js"></script>
    <style>
        #node-tooltip {
            transition: opacity 0.75s ease-in-out;
        }
        .persistent-tooltip-item {
            transition: opacity 0.75s ease-in-out;
        }
        #starfield-background {
            pointer-events: none;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
        }
        .star-dot {
            position: absolute;
            border-radius: 50%;
            background: #fff;
            will-change: opacity;
        }
        @keyframes star-blink {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 1; }
        }
        #loading-overlay {
            position: fixed;
            inset: 0;
            z-index: 300;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        #loading-overlay .loading-text {
            color: var(--loading-color, #fff);
            font-size: 1.125rem;
            font-weight: normal;
            margin-bottom: 0.75rem;
        }
        .loading-star {
            width: 44px;
            height: 44px;
        }
        .loading-star .fill {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: fill-star 1.2s ease-in-out infinite;
        }
        @keyframes fill-star {
            to { stroke-dashoffset: 0; }
        }
        #info {
            opacity: 0;
            transition: opacity 0.35s ease;
            pointer-events: none;
        }
        body.info-visible #info {
            opacity: 0.8;
        }
        body.info-visible #info > div {
            pointer-events: auto;
        }
    </style>
</head>
<body class="overflow-hidden bg-black font-sans">
    <div id="loading-overlay" aria-live="polite" aria-busy="true">
        <p class="loading-text">Loading.</p>
        <svg class="loading-star" viewBox="0 0 40 40" aria-hidden="true">
            <path class="track" d="M20 4 L24.1 14.3 L35.2 15.1 L26.7 22.2 L29.4 32.9 L20 27 L10.6 32.9 L13.3 22.2 L4.8 15.1 L15.9 14.3 Z" fill="none" stroke="var(--loading-color-dim, rgba(255,255,255,0.25))" stroke-width="2" stroke-linejoin="round"/>
            <path class="fill" pathLength="100" d="M20 4 L24.1 14.3 L35.2 15.1 L26.7 22.2 L29.4 32.9 L20 27 L10.6 32.9 L13.3 22.2 L4.8 15.1 L15.9 14.3 Z" fill="none" stroke="var(--loading-color, #fff)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <script>
    (function() {
        var overlay = document.getElementById('loading-overlay');
        if (!overlay) return;
        function hslToRgb(h, s, l) {
            var r, g, b;
            if (s === 0) { r = g = b = l; }
            else {
                function hue2rgb(p, q, t) {
                    if (t < 0) t += 1; if (t > 1) t -= 1;
                    if (t < 1/6) return p + (q - p) * 6 * t;
                    if (t < 1/2) return q;
                    if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                    return p;
                }
                var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
                var p = 2 * l - q;
                r = hue2rgb(p, q, h + 1/3); g = hue2rgb(p, q, h); b = hue2rgb(p, q, h - 1/3);
            }
            return [Math.round(r*255), Math.round(g*255), Math.round(b*255)];
        }
        var h = Math.random();
        var s = 0.5 + Math.random() * 0.15;
        var l = 0.78 + Math.random() * 0.14;
        var rgb = hslToRgb(h, s, l);
        overlay.style.setProperty('--loading-color', 'rgb(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ')');
        overlay.style.setProperty('--loading-color-dim', 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',0.25)');
    })();
    </script>
    <div id="canvas-container" class="relative" style="position: relative; width: 100vw; height: 100vh; min-height: 100vh;">
        <div id="starfield-background" class="absolute z-0" style="inset: 0;" aria-hidden="true"></div>
        <div id="webgl-canvas-wrapper" class="absolute inset-0" style="z-index: 1;"></div>
        <div id="persistent-tooltips" class="absolute inset-0 pointer-events-none z-[150]" style="font-family: inherit;"></div>
        <div id="node-tooltip" class="absolute px-3 py-2 rounded text-sm pointer-events-none z-[200]" style="font-family: inherit; opacity: 0; visibility: hidden;"></div>
    </div>
    <div id="info" class="absolute top-5 left-5 text-white z-[100] text-sm">
        <div class="cursor-pointer hover:opacity-100 transition-opacity" onclick="location.reload()" role="button" tabindex="0" title="Click to reload">
            <h2 class="text-lg font-semibold mb-1"><?php echo htmlspecialchars($projectName); ?></h2>
            <p><?php echo htmlspecialchars($projectTagline); ?></p>
        </div>
    </div>
    <?php if ($isEditorOrAdmin): ?>
    <div class="absolute top-5 right-5 z-[100]">
        <a href="edit/index.php" class="text-white text-sm opacity-80 hover:opacity-100 underline pointer-events-auto">
            Edit
        </a>
    </div>
    <?php endif; ?>

    <script>
        window.TELARIS_APP_NAME = <?php echo json_encode($projectName); ?>;
        window.TELARIS_IFRAME_BACK_TEXT = <?php echo json_encode($projectIframeBackText ?? 'Go back'); ?>;
        window.TELARIS_ALERT_MESSAGE = <?php echo json_encode($projectAlertMessage ?? "Close this window when you're done to go back."); ?>;
    </script>
    <script>
    (function() {
        var zoneW = 220, zoneH = 110;
        function inZone(x, y) { return x < zoneW && y < zoneH; }
        function update(e) {
            var x = e.clientX, y = e.clientY;
            document.body.classList.toggle('info-visible', inZone(x, y));
        }
        document.addEventListener('mousemove', update);
        document.addEventListener('touchmove', function(e) {
            if (e.touches.length) update(e.touches[0]);
        }, { passive: true });
        document.addEventListener('mouseleave', function() { document.body.classList.remove('info-visible'); });
    })();
    </script>
    <script type="importmap">
        {
            "imports": {
                "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
                "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
            }
        }
    </script>
    <script type="module" src="js/main.js"></script>
</body>
</html>
