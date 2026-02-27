<?php
/**
 * Main view: HTML shell for the 3D network.
 * Expects: $projectName, $projectTagline, $isEditorOrAdmin, $currentLocale, $projectEditButtonText, $projectLoadingText (set by bootstrap).
 */
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self' https://cdn.jsdelivr.net; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale ?? 'en'); ?>" data-theme="light">
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
    <script src="js/tailwind.min.js?v=5.3"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" />
    <style>
        :root {
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        
        * {
            font-family: var(--font-mono) !important;
        }

        #node-tooltip {
            transition: opacity 0.3s ease, transform 0.3s ease;
            border-radius: 12px;
            /* CRT Scanline / Interlace effect */
            background-image: linear-gradient(
                rgba(0, 0, 0, 0.1) 50%, 
                rgba(0, 0, 0, 0) 50%
            );
            background-size: 100% 4px;
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
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(10px);
            transition: background 1s ease, backdrop-filter 1s ease, opacity 1s ease;
            cursor: default;
        }
        #loading-overlay.ready {
            cursor: pointer;
        }
        #loading-overlay .loading-text {
            color: var(--loading-color, #fff);
            font-size: 1.125rem;
            font-weight: normal;
            margin-bottom: 0.75rem;
        }
        .loading-circle {
            width: 50px;
            height: 50px;
            margin-bottom: 2rem;
            transition: all 0.5s ease;
            filter: drop-shadow(0 0 8px var(--loading-color, #00ffcc));
            overflow: visible;
        }
        .loading-circle svg {
            overflow: visible;
        }
        #loading-overlay.ready .loading-circle {
            filter: drop-shadow(0 0 15px #fff);
            transform: scale(1.1);
        }
        .loading-circle circle {
            fill: none;
            stroke: var(--loading-color, #00ffcc);
            stroke-width: 4;
            transition: all 0.5s ease;
            transform-origin: center;
        }
        #loading-overlay:not(.ready) .loading-circle circle {
            animation: circle-pulse 2s ease-in-out infinite;
        }
        #loading-overlay.ready .loading-circle circle {
            stroke: #fff;
            stroke-width: 6;
            animation: circle-pulse-ready 3s ease-in-out infinite;
        }
        @keyframes circle-pulse {
            0%, 100% { opacity: 0.4; stroke-width: 4; }
            50% { opacity: 1; stroke-width: 6; }
        }
        @keyframes circle-pulse-ready {
            0%, 100% { opacity: 0.7; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.05); }
        }
        #begin-button {
            display: none;
            padding: 0.6rem 1.8rem;
            background: rgba(0, 255, 204, 0.05);
            border: 1px solid rgba(0, 255, 204, 0.3);
            color: #00ffcc;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            pointer-events: none; /* Let overlay handle the click */
            transition: all 0.3s ease;
        }
        #loading-overlay.ready:hover #begin-button {
            background: rgba(0, 255, 204, 0.15);
            border-color: #00ffcc;
            box-shadow: 0 0 20px rgba(0, 255, 204, 0.2);
        }

        #info {
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            transform: translateX(-10px);
            pointer-events: none;
            backdrop-filter: blur(12px);
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 1.5rem;
            border-radius: 4px;
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
            z-index: 110;
        }
        #hud-indicator:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.2);
        }
        #hud-indicator:focus {
            outline: 2px solid #00ffcc;
            outline-offset: 2px;
            opacity: 1;
        }
    </style>
</head>
<body class="overflow-hidden bg-black">
    <div id="loading-overlay" aria-live="polite" aria-busy="true">
        <div class="loading-circle">
            <svg viewBox="0 0 100 100" aria-hidden="true" style="width:100%; height:100%;">
                <circle cx="50" cy="50" r="45" />
            </svg>
        </div>
        <p class="loading-text"><?php echo htmlspecialchars($projectLoadingText ?? 'Loading'); ?></p>
        <button id="begin-button">BEGIN</button>
    </div>

    <button id="hud-indicator" aria-label="Toggle navigation menu" aria-expanded="false" type="button">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 12h18M3 6h18M3 18h18"/>
        </svg>
    </button>

    <div id="canvas-container" class="relative" style="position: relative; width: 100vw; height: 100vh; min-height: 100vh;">
        <button type="button" id="portal-back-button" aria-label="<?php echo htmlspecialchars($projectBackButtonText ?? 'Back'); ?> to previous constellation"
                class="absolute top-5 right-5 z-[80] cursor-pointer"
                style="display: none; padding: 0.5rem 0.75rem; font-family: var(--font-mono); font-size: 0.7rem; letter-spacing: 0.15em; text-transform: uppercase; color: #00ffcc; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(0, 255, 204, 0.3); border-radius: 2px; backdrop-filter: blur(4px); transition: all 0.2s;"
                onmouseover="this.style.background='rgba(0, 255, 204, 0.1)'; this.style.borderColor='rgba(0, 255, 204, 0.8)';"
                onmouseout="this.style.background='rgba(0, 0, 0, 0.4)'; this.style.borderColor='rgba(0, 255, 204, 0.3)';"
        >
            ← <?php echo htmlspecialchars($projectBackButtonText ?? 'Back'); ?>
        </button>
        <div id="webgl-canvas-wrapper" class="absolute inset-0" style="z-index: 1;"></div>
        <div id="persistent-tooltips" class="absolute inset-0 pointer-events-none z-[150]"></div>
        <div id="node-tooltip" class="absolute px-3 py-2 rounded text-base pointer-events-none z-[200]" style="opacity: 0; visibility: hidden;"></div>

        <!-- Rich Media Window Overlay -->
        <div id="rich-media-overlay" class="fixed inset-0 z-[250] flex items-center justify-center p-4 bg-black/40 backdrop-blur-md hidden transition-opacity duration-500 opacity-0" 
             onclick="if(event.target === this) { 
                 window.telarisNetwork.closeRichMediaWindow();
             }">
            <div id="rich-media-window" class="bg-[#0a0a0c]/90 border border-white/20 rounded-lg shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto relative text-white transition-all duration-500 ease-out transform scale-50 opacity-0"
                 style="box-shadow: 0 0 50px -10px rgba(0, 255, 204, 0.3);">
                <!-- Close Button -->
                <button onclick="window.telarisNetwork.closeRichMediaWindow()" class="absolute top-4 right-4 text-white/50 hover:text-white transition-colors z-10">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>

                <!-- Content -->
                <div class="p-6 md:p-8">
                    <h2 id="rm-title" class="text-2xl font-bold mb-4 tracking-tight uppercase border-b-2 pb-2" style="border-color: var(--node-accent-muted);"></h2>
                    
                    <div id="rm-media-container" class="space-y-6">
                        <!-- Image -->
                        <div id="rm-image-wrap" class="hidden">
                            <img id="rm-image" src="" alt="" class="w-full h-auto rounded-md border" style="border-color: var(--node-accent-muted);">
                        </div>

                        <!-- Embed -->
                        <div id="rm-embed-wrap" class="hidden aspect-video">
                            <div id="rm-embed" class="w-full h-full"></div>
                        </div>

                        <!-- Video -->
                        <div id="rm-video-wrap" class="hidden">
                            <video id="rm-video" controls preload="auto" class="w-full h-auto rounded-md border" style="border-color: var(--node-accent-muted); width: 100% !important;"></video>
                        </div>

                        <!-- Audio -->
                        <div id="rm-audio-wrap" class="hidden">
                            <audio id="rm-audio" preload="auto"></audio>
                            <div class="flex items-center gap-4 bg-white/5 border border-white/10 rounded-lg p-3" style="border-color: var(--node-accent-muted);">
                                <button id="rm-audio-play-pause" class="hover:opacity-80 transition-opacity" style="color: var(--node-accent);">
                                    <svg id="rm-play-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                    <svg id="rm-pause-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" class="hidden"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                                </button>
                                <button id="rm-audio-stop" class="hover:opacity-80 transition-opacity" style="color: var(--node-accent);">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 6h12v12H6z"/></svg>
                                </button>
                                <div class="flex-1 h-1 bg-white/10 rounded-full overflow-hidden cursor-pointer relative" id="rm-audio-progress-container">
                                    <div id="rm-audio-progress" class="absolute top-0 left-0 h-full w-0 transition-all duration-100" style="background-color: var(--node-accent);"></div>
                                </div>
                                <span id="rm-audio-time" class="text-[10px] font-mono opacity-50 tabular-nums" style="color: var(--node-accent);">0:00</span>
                            </div>
                        </div>

                        <!-- Description -->
                        <div id="rm-description" class="text-gray-300 leading-relaxed text-sm md:text-base whitespace-pre-wrap"></div>

                        <!-- URL / Action Button -->
                        <div id="rm-url-wrap" class="hidden pt-4">
                            <button id="rm-url-button" class="w-full py-3 bg-transparent border text-xs font-bold uppercase tracking-widest transition-all hover:bg-white/10"
                                    style="border-color: var(--node-accent-muted); color: var(--node-accent);">
                                LAUNCH...
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tactical HUD Navigation -->
    <div id="info" class="absolute top-5 left-0 text-white z-[100] text-sm pt-14">
        <div class="cursor-pointer group" onclick="location.reload()" title="<?php echo htmlspecialchars($projectReloadSystemText ?? 'Reload System'); ?>">
            <h2 id="constellation-title" class="text-xl font-bold mb-1 tracking-tight uppercase group-hover:text-[#00ffcc] transition-colors">
                <?php echo htmlspecialchars(isset($constellationName) ? $constellationName : $projectName); ?>
            </h2>
            <p id="constellation-tagline" class="opacity-60 italic text-xs"><?php echo htmlspecialchars(isset($constellationTagline) ? $constellationTagline : $projectTagline); ?></p>
        </div>

        <div class="hud-line"></div>
        
        <div class="mb-4 relative group/search">
            <input type="text" id="hud-search" placeholder="<?php echo htmlspecialchars($projectScanSystemText ?? 'SEARCH...'); ?>" 
                class="w-full bg-white/5 border border-white/20 rounded px-2 py-1.5 pr-8 text-xs text-[#00ffcc] placeholder:text-white/20 focus:outline-none focus:border-[#00ffcc]/50 focus:bg-white/10 transition-all uppercase tracking-wider">
            <button id="hud-search-clear" class="absolute right-2 top-1/2 -translate-y-1/2 text-white/20 hover:text-[#ff4444] transition-colors" style="display: none;" title="<?php echo htmlspecialchars($projectClearScanText ?? 'Clear Search'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="hud-line"></div>

        <div class="space-y-2 opacity-80 mb-6 text-sm">
            <div class="flex justify-between gap-12">
                <span class="uppercase"><?php echo htmlspecialchars($projectSystemsLabelText ?? 'Nodes:'); ?></span>
                <span id="hud-nodes" class="font-bold text-[#00ffcc]">--</span>
            </div>
            <div class="flex justify-between gap-12">
                <span class="uppercase"><?php echo htmlspecialchars($projectHyperlinksLabelText ?? 'Hyperlinks:'); ?></span>
                <span id="hud-connections" class="font-bold text-[#00ffcc]">--</span>
            </div>
            <div class="flex justify-between gap-12">
                <span class="uppercase">Sound:</span>
                <button id="hud-sound-toggle" class="font-bold text-[#00ffcc] hover:text-white transition-colors cursor-pointer uppercase">ON</button>
            </div>
        </div>

        <div class="flex gap-6 mt-4 font-bold text-xs uppercase">
            <?php if ($isEditorOrAdmin): ?>
                <a href="edit/index.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1"><?php echo htmlspecialchars($projectEditButtonText ?? 'Edit'); ?></a>
                <?php if (isAdminLoggedIn()): ?>
                    <a href="admin/index.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1"><?php echo htmlspecialchars($projectAdminLabelText ?? 'Admin'); ?></a>
                <?php endif; ?>
                <a href="utils/logout.php" class="opacity-40 hover:opacity-100 transition-opacity"><?php echo htmlspecialchars($projectLogoutLabelText ?? 'Logout'); ?></a>
            <?php else: ?>
                <a href="utils/login.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1"><?php echo htmlspecialchars($projectInitializeAuthText ?? 'Login'); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        window.TELARIS_APP_NAME = <?php echo json_encode($projectName); ?>;
        window.TELARIS_IFRAME_BACK_TEXT = <?php echo json_encode($projectIframeBackText ?? 'Go back'); ?>;
        window.TELARIS_ALERT_MESSAGE = <?php echo json_encode($projectAlertMessage ?? "Close this window when you're done to go back to {APPNAME}."); ?>;
        window.TELARIS_CONSTELLATION_ID = <?php echo isset($constellationId) ? (int) $constellationId : 0; ?>;
        window.TELARIS_THEME_ID = <?php echo json_encode($constellationTheme ?? 'cosmic'); ?>;
        window.TELARIS_CLICK_TO_VIEW = <?php echo json_encode($projectClickToViewText ?? 'Click to view'); ?>;
        window.TELARIS_TAP_TO_VIEW = <?php echo json_encode($projectTapToViewText ?? 'Tap again to view'); ?>;
        window.TELARIS_OPEN_PORTAL_TEXT = <?php echo json_encode($projectOpenPortalText ?? 'Open the Portal'); ?>;
    </script>
    <script>
    (function() {
        var hudIndicator = document.getElementById('hud-indicator');
        var infoPanel = document.getElementById('info');
        var searchInput = document.getElementById('hud-search');

        function updateIcon(isVisible) {
            var svg = hudIndicator.querySelector('svg');
            if (isVisible) {
                svg.innerHTML = '<path d="M18 6L6 18M6 6l12 12"/>';
                hudIndicator.setAttribute('aria-expanded', 'true');
            } else {
                svg.innerHTML = '<path d="M3 12h18M3 6h18M3 18h18"/>';
                hudIndicator.setAttribute('aria-expanded', 'false');
            }
        }

        function toggleMenu(e) {
            e.stopPropagation();
            var isVisible = document.body.classList.toggle('info-visible');
            updateIcon(isVisible);
            if (isVisible && searchInput) {
                setTimeout(function() { searchInput.focus(); }, 150);
            } else if (!isVisible && searchInput) {
                searchInput.blur();
            }
        }

        if (hudIndicator) {
            hudIndicator.addEventListener('click', toggleMenu);
        }

        // Close when clicking outside
        document.addEventListener('click', function(e) {
            if (document.body.classList.contains('info-visible')) {
                if (!infoPanel.contains(e.target) && !hudIndicator.contains(e.target)) {
                    document.body.classList.remove('info-visible');
                    updateIcon(false);
                    if (searchInput) searchInput.blur();
                }
            }
        });

        // Prevent panel clicks from closing it
        if (infoPanel) {
            infoPanel.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
    })();
    </script>
    <script type="importmap">
        {
            "imports": {
                "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
                "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/",
                "./telaris-network.js": "./js/telaris-network.js?v=5.4.6",
                "./network-manager.js": "./js/network-manager.js?v=5.3",
                "./geometry-manager.js": "./js/geometry-manager.js?v=5.3",
                "./api.js": "./js/api.js?v=5.3",
                "./telaris-node-icons.js": "./js/telaris-node-icons.js?v=5.3",
                "./themes.js": "./js/themes.js?v=5.4.6",
                "./telaris-soundscape.js": "./js/telaris-soundscape.js?v=5.3"
            }
        }
    </script>
    <script src="js/telaris-soundscape.js?v=5.3"></script>
    <script type="module" src="js/main.js?v=5.3"></script>
</body>
</html>