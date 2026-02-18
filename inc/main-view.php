<?php
/**
 * Main view: HTML shell for the 3D network.
 * Expects: $projectName, $projectTagline, $isEditorOrAdmin, $currentLocale, $projectEditButtonText, $projectLoadingText (set by bootstrap).
 */
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale ?? 'en'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.png" type="image/png">
    <title><?php echo htmlspecialchars(isset($constellationName) ? $constellationName : $projectName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(isset($constellationTagline) ? $constellationTagline : $projectTagline); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars(isset($constellationName) ? $constellationName : $projectName); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars(isset($constellationTagline) ? $constellationTagline : $projectTagline); ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars(isset($constellationName) ? $constellationName : $projectName); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars(isset($constellationTagline) ? $constellationTagline : $projectTagline); ?>">
    <script src="js/tailwind.min.js?v=3.0.0"></script>
    <style>
        :root {
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        
        * {
            font-family: var(--font-mono) !important;
        }

        #node-tooltip {
            /* Transition handled in JS for responsiveness */
        }
        .persistent-tooltip-item {
            transition: opacity 0.75s ease-in-out;
        }
        #webgl-canvas-wrapper {
            background: transparent !important;
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
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            transform: translateX(-10px);
            pointer-events: none;
            backdrop-filter: blur(12px);
            background: rgba(0, 0, 0, 0.5);
            border-left: 2px solid rgba(255, 255, 255, 0.3);
            padding: 1.5rem;
            border-radius: 0 4px 4px 0;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.5);
        }
        body.info-visible #info {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }
        .hud-line {
            height: 1px;
            background: linear-gradient(90deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0) 100%);
            margin: 1rem 0;
        }
        .status-pulse {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #00ffcc;
            border-radius: 50%;
            margin-right: 8px;
            box-shadow: 0 0 8px #00ffcc;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { opacity: 0.4; transform: scale(0.9); }
            50% { opacity: 1; transform: scale(1.1); }
            100% { opacity: 0.4; transform: scale(0.9); }
        }
        #hud-indicator {
            position: absolute;
            top: 1.25rem;
            left: 1.25rem;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            color: #fff;
            opacity: 0.6;
            transition: opacity 0.3s ease;
            cursor: pointer;
            z-index: 90;
        }
        body.info-visible #hud-indicator {
            opacity: 0;
            pointer-events: none;
        }
    </style>
</head>
<body class="overflow-hidden bg-black">
    <div id="hud-indicator" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 12h18M3 6h18M3 18h18"/>
        </svg>
    </div>

    <div id="loading-overlay" aria-live="polite" aria-busy="true">
        <p class="loading-text"><?php echo htmlspecialchars($projectLoadingText ?? 'Loading'); ?></p>
        <svg class="loading-star" viewBox="0 0 40 40" aria-hidden="true">
            <path class="track" d="M20 4 L24.1 14.3 L35.2 15.1 L26.7 22.2 L29.4 32.9 L20 27 L10.6 32.9 L13.3 22.2 L4.8 15.1 L15.9 14.3 Z" fill="none" stroke="var(--loading-color-dim, rgba(255,255,255,0.25))" stroke-width="2" stroke-linejoin="round"/>
            <path class="fill" pathLength="100" d="M20 4 L24.1 14.3 L35.2 15.1 L26.7 22.2 L29.4 32.9 L20 27 L10.6 32.9 L13.3 22.2 L4.8 15.1 L15.9 14.3 Z" fill="none" stroke="var(--loading-color, #fff)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>

    <div id="canvas-container" class="relative" style="position: relative; width: 100vw; height: 100vh; min-height: 100vh;">
        <div id="webgl-canvas-wrapper" class="absolute inset-0" style="z-index: 1;"></div>
        <div id="persistent-tooltips" class="absolute inset-0 pointer-events-none z-[150]"></div>
        <div id="node-tooltip" class="absolute px-3 py-2 rounded text-base pointer-events-none z-[200]" style="opacity: 0; visibility: hidden;"></div>
    </div>
    
    <!-- Tactical HUD Navigation -->
    <div id="info" class="absolute top-5 left-0 text-white z-[100] text-sm">
        <div class="mb-3 flex items-center">
            <span class="status-pulse"></span>
            <span class="tracking-widest uppercase font-bold opacity-80 text-xs">System: Online</span>
        </div>
        
        <div class="hud-line"></div>
        
        <div class="cursor-pointer group" onclick="location.reload()" title="Reload System">
            <h2 class="text-xl font-bold mb-1 tracking-tight uppercase group-hover:text-[#00ffcc] transition-colors">
                <?php echo htmlspecialchars(isset($constellationName) ? $constellationName : $projectName); ?>
            </h2>
            <p class="opacity-60 italic text-xs"><?php echo htmlspecialchars(isset($constellationTagline) ? $constellationTagline : $projectTagline); ?></p>
        </div>

        <div class="hud-line"></div>
        
        <div class="mb-4 relative group/search">
            <input type="text" id="hud-search" placeholder="SCAN SYSTEM..." 
                class="w-full bg-white/5 border border-white/20 rounded px-2 py-1.5 pr-8 text-xs text-[#00ffcc] placeholder:text-white/20 focus:outline-none focus:border-[#00ffcc]/50 focus:bg-white/10 transition-all uppercase tracking-wider">
            <button id="hud-search-clear" class="absolute right-2 top-1/2 -translate-y-1/2 text-white/20 hover:text-[#ff4444] transition-colors" style="display: none;" title="Clear Scan">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="hud-line"></div>

        <div class="space-y-2 opacity-80 mb-6 text-sm">
            <div class="flex justify-between gap-12">
                <span class="uppercase">Systems:</span>
                <span id="hud-nodes" class="font-bold text-[#00ffcc]">--</span>
            </div>
            <div class="flex justify-between gap-12">
                <span class="uppercase">Hyperlinks:</span>
                <span id="hud-connections" class="font-bold text-[#00ffcc]">--</span>
            </div>
            <div class="pt-2 text-xs opacity-60 flex gap-4">
                <span>X:<span id="hud-x">0.0</span></span>
                <span>Y:<span id="hud-y">0.0</span></span>
                <span>Z:<span id="hud-z">0.0</span></span>
            </div>
        </div>

        <div class="flex gap-6 mt-4 font-bold text-xs uppercase">
            <?php if ($isEditorOrAdmin): ?>
                <a href="edit/index.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1"><?php echo htmlspecialchars($projectEditButtonText ?? 'Edit'); ?></a>
                <?php if (isAdminLoggedIn()): ?>
                    <a href="admin/index.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1">Admin</a>
                <?php endif; ?>
                <a href="utils/logout.php" class="opacity-40 hover:opacity-100 transition-opacity">Logout</a>
            <?php else: ?>
                <a href="utils/login.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1">Initialize Auth</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        window.TELARIS_APP_NAME = <?php echo json_encode($projectName); ?>;
        window.TELARIS_IFRAME_BACK_TEXT = <?php echo json_encode($projectIframeBackText ?? 'Go back'); ?>;
        window.TELARIS_ALERT_MESSAGE = <?php echo json_encode($projectAlertMessage ?? "Close this window when you're done to go back to {APPNAME}."); ?>;
        window.TELARIS_CONSTELLATION_ID = <?php echo isset($constellationId) ? (int) $constellationId : 0; ?>;
    </script>
    <script>
    (function() {
        var zoneW = 400, zoneH = 400;
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
                "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/",
                "./telaris-network.js": "./js/telaris-network.js?v=3.0.0",
                "./network-manager.js": "./js/network-manager.js?v=3.0.0",
                "./geometry-manager.js": "./js/geometry-manager.js?v=3.0.0",
                "./api.js": "./js/api.js?v=3.0.0",
                "./telaris-node-icons.js": "./js/telaris-node-icons.js?v=3.0.0"
            }
        }
    </script>
    <script type="module" src="js/main.js?v=3.0.0"></script>
</body>
</html>