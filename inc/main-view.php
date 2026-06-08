<?php
/**
 * Main view: HTML shell for the 3D network.
 * Expects: $projectName, $projectTagline, $isEditorOrAdmin, $currentLocale, $projectEditButtonText, $projectLoadingText (set by bootstrap).
 */
$cspNonce = base64_encode(random_bytes(16));
// CSP notes:
// - script-src uses a per-request nonce; no 'unsafe-inline' on scripts.
// - style-src keeps 'unsafe-inline' because Tailwind + daisyui inject inline
//   styles at runtime. Removing it requires a build step that emits a static
//   stylesheet; design choice rather than a security gap given the lack of
//   user-controlled content in any style context.
// - frame-ancestors * is deliberate: Telaris promotes embedding galaxies on
//   partner sites. SAFETY-CRITICAL: the visitor view MUST NOT add any
//   auth-gated or otherwise sensitive click target (anything that mutates
//   state, opens admin paths, or reveals other-user data). All current
//   interactions are visitor-public reads.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}' https://static.cloudflareinsights.com; worker-src 'self' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self' https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors *");
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
    <script src="<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'tailwind.min.js')); ?>"></script>
    <link href="/css/vendor/daisyui-4.12.10.full.min.css" rel="stylesheet" type="text/css" />
    <style>
        :root {
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        
        * {
            font-family: var(--font-mono) !important;
        }

        #portal-back-button:hover {
            background: rgba(0, 255, 204, 0.1) !important;
            border-color: rgba(0, 255, 204, 0.8) !important;
        }

        #node-tooltip {
            position: absolute;
            right: 3rem;
            left: auto;
            max-width: 280px;
            transition: opacity 0.3s ease;
            border-radius: 12px;
            /* CRT Scanline / Interlace effect */
            background-image: linear-gradient(
                rgba(0, 0, 0, 0.1) 50%,
                rgba(0, 0, 0, 0) 50%
            );
            background-size: 100% 4px;
        }
        #tooltip-line-svg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 190;
            transition: opacity 0.3s ease;
        }
        .persistent-tooltip-item {
            transition: opacity 0.75s ease-in-out;
        }
        #webgl-canvas-wrapper {
            background: transparent !important;
            overflow: hidden;
        }
        #telaris-bg-canvas {
            /* blur handled by BokehPass depth-of-field in JS */
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
        #loading-overlay .loading-text {
            color: var(--loading-color, #fff);
            font-size: 1.125rem;
            font-weight: normal;
            margin-bottom: 0.75rem;
        }
        #loading-torus-canvas {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
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
<?php if (empty($nginxVersionedPathsOk)): ?>
    <!-- Visible to everyone because the app's JS will not load until the admin installs the rule. -->
    <div id="nginx-config-warning" style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#7f1d1d;color:#fff;padding:14px 20px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.5;border-bottom:2px solid #fca5a5;box-shadow:0 4px 12px rgba(0,0,0,0.5);">
        <div style="max-width:900px;margin:0 auto;">
            <strong style="font-size:14px;display:block;margin-bottom:6px;"><?php echo t_attr('visitor_nginx_warning_heading', 'Telaris configuration: nginx versioned-asset rule not installed'); ?></strong>
            <?php echo sprintf(t('visitor_nginx_warning_intro', "JavaScript modules will not be served. Add this block to the server's nginx vhost (replacing the docroot if different), then run %s."), t('visitor_nginx_warning_reload', '<code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>')); ?>
            <pre style="background:rgba(0,0,0,0.45);padding:10px 12px;margin:10px 0 4px;border-radius:4px;overflow:auto;font-size:12px;color:#fecaca;">location ~ ^/js/v[0-9]+\.[0-9]+\.[0-9]+/(.+\.js)$ {
    alias <?php echo htmlspecialchars($root); ?>/js/$1;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    # Repeat the security headers since add_header inheritance breaks here:
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
}</pre>
            <span style="opacity:0.85;font-size:12px;"><?php echo sprintf(t('visitor_nginx_warning_footer', 'This banner disappears automatically once the rule serves %s with HTTP 200.'), '<code>/js/v' . htmlspecialchars($appVersion) . '/main.js</code>'); ?></span>
        </div>
    </div>
<?php endif; ?>
    <div id="loading-overlay" aria-live="polite" aria-busy="true">
        <canvas id="loading-torus-canvas" aria-hidden="true"></canvas>
        <p class="loading-text"><?php echo htmlspecialchars($projectLoadingText ?? 'Loading'); ?></p>
    </div>

    <button id="hud-indicator" aria-label="<?php echo htmlspecialchars($projectNavToggleAriaText ?? 'Toggle navigation menu'); ?>" aria-expanded="false" type="button">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 12h18M3 6h18M3 18h18"/>
        </svg>
    </button>

    <div id="canvas-container" class="relative" style="position: relative; width: 100vw; height: 100vh; min-height: 100vh;">
        <button type="button" id="portal-back-button" aria-label="<?php echo htmlspecialchars($projectBackButtonText ?? 'Back'); ?> to previous galaxy"
                class="absolute top-5 right-5 z-[80] cursor-pointer"
                style="display: none; padding: 0.5rem 0.75rem; font-family: var(--font-mono); font-size: 0.7rem; letter-spacing: 0.15em; text-transform: uppercase; color: #00ffcc; background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(0, 255, 204, 0.3); border-radius: 2px; backdrop-filter: blur(4px); transition: all 0.2s;"
        >
            ← <?php echo htmlspecialchars($projectBackButtonText ?? 'Back'); ?>
        </button>
        <div id="webgl-canvas-wrapper" class="absolute inset-0" style="z-index: 1;"></div>

        <!-- 2D grid view: an alternative wormhole layout. Hidden by default;
             toggled visible via the top-center view switch. Populated by
             js/wormhole-grid-2d.js. Background is a dim dot grid (same design-
             tool feel the keyword canvas uses). -->
        <div id="wormhole-grid-2d" class="absolute inset-0 hidden"
             style="z-index: 1; background-color: #000; background-image: radial-gradient(circle, rgba(113,113,122,0.45) 1.5px, transparent 2px); background-size: 28px 28px; overflow: auto;">
            <svg id="wormhole-grid-2d-lines" class="absolute inset-0 pointer-events-none" style="width: 100%; height: 100%;"></svg>
            <div id="wormhole-grid-2d-cards" class="absolute inset-0"></div>
        </div>

        <?php if (!empty($show2dView)): ?>
        <!-- View-mode switch (top-center). Per-galaxy opt-in (constellations.
             show_2d_view). Visitor preference persists in localStorage. -->
        <div id="view-mode-switch" class="fixed top-3 left-1/2 -translate-x-1/2 z-[220] flex items-center gap-0 bg-black/50 border border-white/20 rounded-full backdrop-blur-sm" style="padding: 2px;">
            <button type="button" id="view-mode-3d" data-mode="3d"
                    class="text-xs uppercase tracking-[0.18em] px-3 py-1 rounded-full transition-colors"
                    aria-pressed="true"
                    style="color: #000; background: #fff;">
                3D
            </button>
            <button type="button" id="view-mode-2d" data-mode="2d"
                    class="text-xs uppercase tracking-[0.18em] px-3 py-1 rounded-full transition-colors"
                    aria-pressed="false"
                    style="color: #fff; background: transparent;">
                2D
            </button>
        </div>
        <!-- Synchronously paint the switch in its restored state BEFORE main.js
             runs, so visitors with a '2d' localStorage preference don't see the
             switch hydrate from 3D-selected to 2D-selected. main.js still does
             the full setMode() after init; this just prevents the flash. -->
        <script nonce="<?php echo htmlspecialchars($cspNonce); ?>">
        (function () {
            try {
                if (localStorage.getItem('telaris.viewMode') !== '2d') return;
                var b3 = document.getElementById('view-mode-3d');
                var b2 = document.getElementById('view-mode-2d');
                if (!b3 || !b2) return;
                b3.setAttribute('aria-pressed', 'false');
                b2.setAttribute('aria-pressed', 'true');
                b3.style.color = '#fff';
                b3.style.background = 'transparent';
                b2.style.color = '#000';
                b2.style.background = '#fff';
            } catch (e) { /* private mode etc. */ }
        })();
        </script>
        <?php endif; ?>

        <div id="persistent-tooltips" class="absolute inset-0 pointer-events-none z-[150]"></div>
        <svg id="tooltip-line-svg" style="opacity: 0;" aria-hidden="true"><polyline id="tooltip-line" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>
        <div id="node-tooltip" class="px-3 py-2 rounded text-base pointer-events-none z-[200]" style="opacity: 0; visibility: hidden;"></div>

        <!-- Keyword chips strip (bottom-center). Populated by js/keyword-chips.js. -->
        <div id="keyword-chips-strip" class="hidden fixed bottom-3 left-1/2 -translate-x-1/2 z-[210] flex flex-wrap gap-x-3 gap-y-0 justify-center max-w-[80vw] overflow-hidden" style="font-size: 0.85rem; line-height: 1.4; max-height: 2.4rem;"></div>

        <!-- Galaxy list strip (visitor multigalaxy filter, bottom-right). Slide-up menu:
             a button reveals the chip panel. Populated by js/galaxy-list-strip.js.
             Whole strip hidden by default; controller un-hides if window.TELARIS_GALAXY_LIST_ENABLED is true. -->
        <div id="galaxy-list-strip" class="hidden fixed bottom-3 right-3 z-[210] flex flex-col items-end gap-2" style="font-size: 0.85rem; line-height: 1.4;">
            <div id="galaxy-list-panel" class="flex flex-col gap-1 items-end max-h-[60vh] overflow-y-auto" style="opacity: 0; transform: translateY(8px); transition: opacity 180ms ease, transform 180ms ease; pointer-events: none;"></div>
            <button id="galaxy-list-toggle" type="button" aria-expanded="false" style="background:rgba(0,0,0,0.55); border:1px solid rgba(255,255,255,0.18); border-radius:9999px; padding:0.35rem 0.85rem; color:#fff; font-weight:500; cursor:pointer; backdrop-filter:blur(4px); display:flex; align-items:center; gap:0.5rem; transition:border-color 150ms, background 150ms;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" id="galaxy-list-toggle-icon" style="transition: transform 180ms ease;" aria-hidden="true">
                    <path d="M6 9l6 6 6-6"/>
                </svg>
                <span id="galaxy-list-toggle-label"><?php echo htmlspecialchars($projectGalaxiesLabelText ?? 'Galaxies'); ?></span>
            </button>
        </div>

        <!-- Auto-tour HUD: Play button (manual start) and during-tour player overlay -->
        <button id="tour-play-btn" class="hidden fixed bottom-6 right-6 z-[260] bg-black/70 hover:bg-black/90 border border-white/30 hover:border-[#00ffcc] text-white text-xs uppercase tracking-[0.18em] px-4 py-2 rounded-[2px] transition-all" type="button" aria-label="<?php echo htmlspecialchars($projectTourStartAriaText ?? 'Start tour'); ?>">
            <span class="inline-flex items-center gap-2">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                <span id="tour-play-btn-label"><?php echo htmlspecialchars($projectTourLabelText ?? 'Tour'); ?></span>
            </span>
        </button>

        <div id="tour-hud" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 z-[260] bg-black/80 border border-white/30 rounded px-4 py-2 text-white text-xs uppercase tracking-[0.18em] flex items-center gap-3" role="status" aria-live="polite">
            <span id="tour-hud-progress" class="font-mono text-[#00ffcc]">1 / 1</span>
            <div class="w-32 h-1 bg-white/10 rounded overflow-hidden">
                <div id="tour-hud-progress-bar" class="h-full bg-[#00ffcc] transition-all" style="width: 0%"></div>
            </div>
            <button id="tour-hud-prev" class="hover:text-[#00ffcc] transition-colors" type="button" aria-label="<?php echo htmlspecialchars($projectTourPreviousAriaText ?? 'Previous'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button id="tour-hud-pause" class="hover:text-[#00ffcc] transition-colors" type="button" aria-label="<?php echo htmlspecialchars($projectTourPauseAriaText ?? 'Pause'); ?>">
                <svg id="tour-hud-pause-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>
                <svg id="tour-hud-resume-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true" class="hidden"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button id="tour-hud-next" class="hover:text-[#00ffcc] transition-colors" type="button" aria-label="<?php echo htmlspecialchars($projectTourNextAriaText ?? 'Next'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M16 6h2v12h-2zm-9.5 0L15 12l-8.5 6z"/></svg>
            </button>
            <button id="tour-hud-exit" class="hover:text-red-400 transition-colors" type="button" aria-label="<?php echo htmlspecialchars($projectTourExitAriaText ?? 'Exit tour'); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Rich Media Window Overlay -->
        <div id="rich-media-overlay" class="fixed inset-0 z-[250] flex items-center justify-center p-4 bg-black/40 backdrop-blur-md hidden transition-opacity duration-500 opacity-0">
            <div id="rich-media-window" class="bg-[#0a0a0c]/90 border border-white/20 rounded-lg shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto relative text-white transition-all duration-500 ease-out transform scale-50 opacity-0"
                 style="box-shadow: 0 0 50px -10px rgba(0, 255, 204, 0.3);">
                <!-- Tour dwell countdown bar (no playable media). Shrinks from both sides at once. -->
                <div id="tour-dwell-bar-track" class="hidden absolute bottom-0 left-0 right-0 h-1 bg-white/5 overflow-hidden" aria-hidden="true">
                    <div id="tour-dwell-bar" class="h-full bg-[#00ffcc] origin-center" style="transform: scaleX(1)"></div>
                </div>

                <!-- Share permalink + Close -->
                <button id="rm-share-btn" class="absolute top-4 right-12 text-white/50 hover:text-white transition-colors z-10" title="<?php echo htmlspecialchars($projectShareLinkTitleText ?? 'Copy link to this wormhole'); ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10 14a5 5 0 007.07 0l3-3a5 5 0 00-7.07-7.07l-1.5 1.5"/>
                        <path d="M14 10a5 5 0 00-7.07 0l-3 3a5 5 0 007.07 7.07l1.5-1.5"/>
                    </svg>
                </button>
                <button id="rm-close-btn" class="absolute top-4 right-4 text-white/50 hover:text-white transition-colors z-10">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>

                <!-- Content -->
                <div class="p-6 md:p-8">
                    <h2 id="rm-title" class="text-2xl font-bold mb-4 tracking-tight uppercase border-b-2 pb-2" style="border-color: var(--node-accent-muted);"></h2>
                    
                    <div id="rm-media-container" class="space-y-6">
                        <!-- Image -->
                        <div id="rm-image-wrap" class="hidden relative">
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

                        <!-- PDF (rendered by js/pdf-viewer.js using vendored PDF.js). -->
                        <div id="rm-pdf-wrap" class="hidden">
                            <div id="rm-pdf-toolbar" class="flex items-center justify-between mb-2 text-xs uppercase tracking-wider text-white/60">
                                <span id="rm-pdf-status"><?php echo htmlspecialchars($projectPdfLoadingText ?? 'Loading PDF…'); ?></span>
                                <span class="flex items-center gap-3">
                                    <a id="rm-pdf-open" href="#" target="_blank" rel="noopener" class="hover:text-white transition-colors"><?php echo htmlspecialchars($projectPdfOpenText ?? 'Open in new window'); ?></a>
                                    <span class="opacity-30">|</span>
                                    <a id="rm-pdf-download" href="#" target="_blank" rel="noopener" class="hover:text-white transition-colors" download><?php echo htmlspecialchars($projectPdfDownloadText ?? 'Download'); ?></a>
                                </span>
                            </div>
                            <div id="rm-pdf-pages" class="max-h-[50vh] overflow-y-auto overscroll-contain rounded-md border bg-black/30" style="border-color: var(--node-accent-muted);"></div>
                        </div>

                        <!-- Credit / attribution shared across image / video / PDF. Stored on
                             nodes.image_attribution (legacy column name; semantics broadened). -->
                        <div id="rm-credit-wrap" class="hidden -mt-4 text-right text-[11px] text-white/60 italic">
                            <span id="rm-credit"></span>
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
                        <div id="rm-description" class="text-gray-300 leading-relaxed text-sm md:text-base whitespace-pre-wrap max-h-[40vh] overflow-y-auto pr-1" style="scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.2) transparent;"></div>

                        <!-- Keywords -->
                        <div id="rm-keywords-wrap" class="hidden">
                            <div id="rm-keywords" class="flex flex-wrap gap-2"></div>
                        </div>

                        <!-- URL / Action Button -->
                        <div id="rm-url-wrap" class="hidden pt-4">
                            <button id="rm-url-button" class="w-full py-3 bg-transparent border text-xs font-bold uppercase tracking-[0.22em] transition-all hover:bg-white/10"
                                    style="border-color: var(--node-accent-muted); color: var(--node-accent);">
                                <?php echo htmlspecialchars($projectLaunchButtonText ?? 'LAUNCH'); ?>...
                            </button>
                        </div>

                        <!-- Related nodes (single thin row, populated by telaris-3d when galaxy enables it) -->
                        <div id="rm-related-wrap" class="hidden pt-2">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-white/40 mb-1"><?php echo htmlspecialchars($projectRelatedLabelText ?? 'Related'); ?></div>
                            <div id="rm-related" class="flex flex-nowrap items-center gap-3 overflow-x-auto" style="scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.2) transparent;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tactical HUD Navigation -->
    <div id="info" class="absolute top-5 left-0 text-white z-[100] text-sm pt-14">
        <div id="constellation-reload" class="cursor-pointer group" title="<?php echo htmlspecialchars($projectReloadSystemText ?? 'Reload System'); ?>">
            <h2 id="constellation-title" class="text-xl font-bold mb-1 tracking-tight uppercase group-hover:text-[#00ffcc] transition-colors">
                <?php echo htmlspecialchars(isset($constellationName) ? $constellationName : $projectName); ?>
            </h2>
            <p id="constellation-tagline" class="opacity-60 italic text-xs"><?php echo htmlspecialchars(isset($constellationTagline) ? $constellationTagline : $projectTagline); ?></p>
            <?php
            // Stage 5e: mirror provenance badge. Only renders on single-
            // constellation pages where the constellation is a federation
            // mirror (multigalaxy mode leaves $constellationMirrorOrigin null).
            if (!empty($constellationMirrorOrigin) && is_array($constellationMirrorOrigin)):
                $__originHost = (string)$constellationMirrorOrigin['origin_host'];
                $__remoteSlug = (string)$constellationMirrorOrigin['remote_slug'];
                $__originUrl = 'https://' . $__originHost . '/' . rawurlencode($__remoteSlug);
            ?>
                <p id="constellation-mirror-origin" class="mt-1 text-[10px] uppercase tracking-[0.18em] text-[#00ffcc]/70" data-origin-host="<?= htmlspecialchars($__originHost) ?>" data-remote-slug="<?= htmlspecialchars($__remoteSlug) ?>">
                    <?= t_attr('visitor_mirror_label', 'Mirrored from') ?>
                    <a href="<?= htmlspecialchars($__originUrl) ?>" target="_blank" rel="noopener noreferrer"
                       title="<?= t_attr('visitor_mirror_view_on_origin', 'View on origin') ?>"
                       class="underline decoration-dotted hover:text-[#00ffcc]"><?= htmlspecialchars($__originHost) ?></a>
                </p>
            <?php endif; ?>
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
            <div id="hud-search-results" class="absolute left-0 right-0 top-full mt-1 z-[200] max-h-64 overflow-y-auto rounded border border-white/20 bg-black/90 backdrop-blur-md" style="display: none;"></div>
        </div>

        <div class="hud-line"></div>

        <div class="space-y-2 opacity-80 mb-6 text-sm">
            <div class="flex justify-between gap-12">
                <span class="uppercase tracking-[0.18em]"><?php echo htmlspecialchars($projectSystemsLabelText ?? 'Nodes:'); ?></span>
                <span id="hud-nodes" class="font-bold text-[#00ffcc]">--</span>
            </div>
            <div class="flex justify-between gap-12">
                <span class="uppercase tracking-[0.18em]"><?php echo htmlspecialchars($projectHyperlinksLabelText ?? 'Hyperlinks:'); ?></span>
                <span id="hud-connections" class="font-bold text-[#00ffcc]">--</span>
            </div>
            <div class="flex justify-between gap-12">
                <span class="uppercase tracking-[0.18em]"><?php echo htmlspecialchars($projectSoundLabelText ?? 'Sound:'); ?></span>
                <button id="hud-sound-toggle" class="font-bold text-[#00ffcc] hover:text-white transition-colors cursor-pointer uppercase tracking-[0.18em]" data-on="<?php echo htmlspecialchars($projectSoundOnText ?? 'ON'); ?>" data-off="<?php echo htmlspecialchars($projectSoundOffText ?? 'OFF'); ?>"><?php echo htmlspecialchars($projectSoundOnText ?? 'ON'); ?></button>
            </div>
            <div class="flex justify-between gap-12">
                <span class="uppercase tracking-[0.18em]"><?php echo htmlspecialchars($projectLangLabelText ?? 'Lang:'); ?></span>
                <span class="font-bold text-xs uppercase tracking-[0.18em]"><?php
                    $links = [];
                    foreach (PROJECT_INFO_LOCALES as $loc) {
                        if ($loc === ($currentLocale ?? PROJECT_INFO_LOCALES[0])) {
                            $links[] = '<span class="text-[#00ffcc]">' . strtoupper($loc) . '</span>';
                        } else {
                            $links[] = '<a href="#" class="lang-switch opacity-40 hover:opacity-100 hover:text-[#00ffcc] transition-all cursor-pointer" data-lang="' . $loc . '">' . strtoupper($loc) . '</a>';
                        }
                    }
                    echo implode(' <span class="opacity-20">|</span> ', $links);
                ?></span>
            </div>
        </div>

        <?php if (!empty($keywordViewGalaxyId) && ($isEditorOrAdmin || !empty($keywordChipsEnabled))): ?>
        <!-- Keyword view: the per-galaxy keyword map. In multi-galaxy contexts
             (clusters or emergent unions) we route to the FIRST member galaxy;
             the canvas is strictly per-galaxy. Public read-only for visitors
             (shown on galaxies that use keyword chips); editors/admins with a
             seat get the full authoring surface. Read access is served by
             /edit/keyword-canvas.php; all writes stay gated in the API. -->
        <div class="mt-4 text-xs uppercase">
            <a href="/edit/keyword-canvas.php?galaxy_id=<?php echo (int)$keywordViewGalaxyId; ?>&amp;back=visitor"
               class="font-bold hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1">
                Keyword view
            </a>
        </div>
        <?php endif; ?>

        <div class="flex gap-6 mt-4 font-bold text-xs uppercase">
            <?php if ($isEditorOrAdmin): ?>
                <a href="/edit/index.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1"><?php echo htmlspecialchars($projectEditButtonText ?? 'Edit'); ?></a>
                <?php if (isAdminLoggedIn()): ?>
                    <a href="/admin/index.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1"><?php echo htmlspecialchars($projectAdminLabelText ?? 'Admin'); ?></a>
                <?php endif; ?>
                <a href="/utils/logout.php" class="opacity-40 hover:opacity-100 transition-opacity"><?php echo htmlspecialchars($projectLogoutLabelText ?? 'Logout'); ?></a>
            <?php else: ?>
                <a href="/utils/login.php" target="_blank" rel="noopener" class="hover:text-[#00ffcc] transition-colors border-b border-white/20 pb-1"><?php echo htmlspecialchars($projectInitializeAuthText ?? 'Login'); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <script nonce="<?php echo htmlspecialchars($cspNonce); ?>">
        document.querySelectorAll('.lang-switch').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                var url = new URL(window.location.href);
                url.searchParams.set('lang', el.dataset.lang);
                window.location.href = url.toString();
            });
        });
        window.TELARIS_APP_NAME = <?php echo json_encode($projectName); ?>;
        window.TELARIS_IFRAME_BACK_TEXT = <?php echo json_encode($projectIframeBackText ?? 'Go back'); ?>;
        window.TELARIS_ALERT_MESSAGE = <?php echo json_encode($projectAlertMessage ?? "Close this window when you're done to go back to {APPNAME}."); ?>;
        window.TELARIS_CONSTELLATION_ID = <?php echo isset($constellationId) ? (int) $constellationId : 0; ?>;
        window.TELARIS_CONSTELLATION_SLUG = <?php echo json_encode($constellationSlug ?? null); ?>;
        window.TELARIS_THEME_ID = <?php echo json_encode($constellationTheme ?? 'cosmic'); ?>;
        window.TELARIS_CLICK_TO_VIEW = <?php echo json_encode($projectClickToViewText ?? 'Click to view'); ?>;
        window.TELARIS_TAP_TO_VIEW = <?php echo json_encode($projectTapToViewText ?? 'Tap again to view'); ?>;
        window.TELARIS_OPEN_PORTAL_TEXT = <?php echo json_encode($projectOpenPortalText ?? 'Open the Portal'); ?>;
        window.TELARIS_BREADCRUMB_ALL = <?php echo json_encode($projectBreadcrumbAllText ?? 'All'); ?>;
        window.TELARIS_LAUNCH_TEXT = <?php echo json_encode($projectLaunchButtonText ?? 'LAUNCH'); ?>;
        window.TELARIS_NO_RESULTS_TEXT = <?php echo json_encode($projectNoResultsText ?? 'No results'); ?>;
        window.TELARIS_SOUND_ON = <?php echo json_encode($projectSoundOnText ?? 'ON'); ?>;
        window.TELARIS_SOUND_OFF = <?php echo json_encode($projectSoundOffText ?? 'OFF'); ?>;
        window.TELARIS_ITEMS_LABEL = <?php echo json_encode($projectItemsLabelText ?? 'items'); ?>;
        window.TELARIS_OTHER_LABEL = <?php echo json_encode($projectOtherLabelText ?? 'Other'); ?>;
        window.TELARIS_LOADING_TEXT = <?php echo json_encode($projectLoadingText ?? 'Loading'); ?>;
        window.TELARIS_LAUNCHING_TEXT = <?php echo json_encode($projectLaunchingText ?? 'Launching'); ?>;
        window.TELARIS_MISSION_ACTIVE_TEXT = <?php echo json_encode($projectMissionActiveText ?? 'Mission Active'); ?>;
        window.TELARIS_GO_TEXT = <?php echo json_encode($projectGoText ?? 'GO'); ?>;
        window.TELARIS_TOUR_CONFIG = <?php echo json_encode($tourConfig ?? null); ?>;
        window.TELARIS_KEYWORD_CHIPS_ENABLED = <?php echo !empty($keywordChipsEnabled) ? 'true' : 'false'; ?>;
        window.TELARIS_IDLE_SPOTLIGHT_CONFIG = <?php echo json_encode($idleSpotlightConfig ?? null); ?>;
        window.TELARIS_RELATED_NODES_ENABLED = <?php echo !empty($relatedNodesEnabled) ? 'true' : 'false'; ?>;
        window.TELARIS_INITIAL_NODE_ID = <?php echo $initialNodeId !== null ? (int)$initialNodeId : 'null'; ?>;
        window.TELARIS_MULTI_GALAXY_IDS = <?php echo json_encode(!empty($multiGalaxyIds) ? array_values($multiGalaxyIds) : null); ?>;
        window.TELARIS_MULTI_GALAXY_TITLE = <?php echo json_encode($multiGalaxyTitle ?? null); ?>;
        window.TELARIS_GALAXY_LIST_ENABLED = <?php echo !empty($galaxyListEnabled) ? 'true' : 'false'; ?>;
        window.TELARIS_GALAXY_LIST = <?php echo json_encode(!empty($galaxyListMembers) ? $galaxyListMembers : null); ?>;
        window.TELARIS_2D_VIEW_ENABLED = <?php echo !empty($show2dView) ? 'true' : 'false'; ?>;
        window.TELARIS_GALAXIES_LABEL = <?php echo json_encode($projectGalaxiesLabelText ?? 'Galaxies'); ?>;
        window.TELARIS_PDF_LOADING_TEXT = <?php echo json_encode($projectPdfLoadingText ?? 'Loading PDF…'); ?>;
        window.TELARIS_PDF_RENDERING_TEXT = <?php echo json_encode($projectPdfRenderingText ?? 'Rendering pages…'); ?>;
        window.TELARIS_PDF_PAGES_SINGULAR_TEXT = <?php echo json_encode($projectPdfPagesSingularText ?? '1 page'); ?>;
        window.TELARIS_PDF_PAGES_PLURAL_TEXT = <?php echo json_encode($projectPdfPagesPluralText ?? '%d pages'); ?>;
        window.TELARIS_PDF_ERROR_LOAD_TEXT = <?php echo json_encode($projectPdfErrorLoadText ?? 'PDF library failed to load.'); ?>;
        window.TELARIS_PDF_ERROR_OPEN_TEXT = <?php echo json_encode($projectPdfErrorOpenText ?? "Couldn't open PDF."); ?>;
        window.TELARIS_NODE_NAME_FALLBACK_TEXT = <?php echo json_encode($projectNodeNameFallbackText ?? 'System'); ?>;
        window.TELARIS_UNTITLED_TEXT = <?php echo json_encode($projectUntitledText ?? 'Untitled'); ?>;
        window.TELARIS_CHIP_OPEN_PREFIX_TEXT = <?php echo json_encode($projectChipOpenPrefixText ?? 'Open'); ?>;
        window.TELARIS_SEARCH_RESULT_TEXT = <?php echo json_encode($projectSearchResultText ?? 'Search result'); ?>;
        window.TELARIS_SEARCH_RESULTS_TEXT = <?php echo json_encode($projectSearchResultsText ?? 'Search results'); ?>;
    </script>
    <script nonce="<?php echo htmlspecialchars($cspNonce); ?>">
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
        // Inline event handler replacements (CSP nonce-compatible)
        var rmOverlay = document.getElementById('rich-media-overlay');
        if (rmOverlay) rmOverlay.addEventListener('click', function(e) {
            if (e.target === this && window.telarisNetwork) window.telarisNetwork.closeRichMediaWindow();
        });
        var rmCloseBtn = document.getElementById('rm-close-btn');
        if (rmCloseBtn) rmCloseBtn.addEventListener('click', function() {
            if (window.telarisNetwork) window.telarisNetwork.closeRichMediaWindow();
        });
        // Share permalink: copies /{galaxy-slug-or-id}/{node-id} to clipboard.
        var rmShareBtn = document.getElementById('rm-share-btn');
        if (rmShareBtn) rmShareBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var app = window.telarisApp;
            var focused = app && app.networkManager ? app.networkManager.getFocusedNode() : null;
            if (!focused) return;
            var nodeId = focused.userData && focused.userData.id;
            if (!nodeId) return;
            var slug = window.TELARIS_CONSTELLATION_SLUG || (window.TELARIS_CONSTELLATION_ID + '');
            var url = window.location.origin + '/' + encodeURIComponent(slug) + '/' + nodeId;
            (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function() {
                rmShareBtn.title = 'Link copied!';
                rmShareBtn.style.color = '#00ffcc';
                setTimeout(function() {
                    rmShareBtn.title = 'Copy link to this wormhole';
                    rmShareBtn.style.color = '';
                }, 1500);
            }).catch(function() {
                window.prompt('Copy this link:', url);
            });
        });
        var reloadEl = document.getElementById('constellation-reload');
        if (reloadEl) reloadEl.addEventListener('click', function() { location.reload(); });
    })();
    </script>
    <script type="importmap" nonce="<?php echo htmlspecialchars($cspNonce); ?>">
        {
            "imports": {
                "three": "/js/vendor/three-0.160.0/build/three.module.js",
                "three/addons/": "/js/vendor/three-0.160.0/examples/jsm/"<?php /* Vendored locally from the official three@0.160.0 npm tarball (no CDN, no IP leak, importmap closure served same-origin). Version pinned in the dir name; do NOT bump to 0.170+ without a manual visual regression pass on the 3D scene (BokehPass, UnrealBloomPass, custom shaders), the Tech-theme corridor effect, and Portal-torus animations. Queued in [[Documentation/ROADMAP]] as a focused regression session. */ ?>,
                "./telaris-3d.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'telaris-3d.js')); ?>",
                "./network-manager.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'network-manager.js')); ?>",
                "./geometry-manager.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'geometry-manager.js')); ?>",
                "./api.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'api.js')); ?>",
                "./telaris-node-icons.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'telaris-node-icons.js')); ?>",
                "./themes.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'themes.js')); ?>",
                "./telaris-soundscape.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'telaris-soundscape.js')); ?>",
                "./auto-tour.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'auto-tour.js')); ?>",
                "./tour-shared.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'tour-shared.js')); ?>",
                "./keyword-chips.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'keyword-chips.js')); ?>",
                "./idle-spotlight.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'idle-spotlight.js')); ?>",
                "./galaxy-list-strip.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'galaxy-list-strip.js')); ?>",
                "./wormhole-grid-2d.js": "<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'wormhole-grid-2d.js')); ?>"
            }
        }
    </script>
    <script type="module" nonce="<?php echo htmlspecialchars($cspNonce); ?>">
        import * as THREE from 'three';

        const canvas = document.getElementById('loading-torus-canvas');
        if (canvas) {
            const w = window.innerWidth;
            const h = window.innerHeight;

            const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
            renderer.setSize(w, h);
            renderer.setPixelRatio(window.devicePixelRatio);

            const scene = new THREE.Scene();
            const aspect = w / h;
            const camera = new THREE.PerspectiveCamera(75, aspect, 0.1, 1000);
            // Push camera back so the torus appears ~180px on screen
            const idleZ = 5 * (Math.min(w, h) / 180);
            camera.position.z = idleZ;

            const geometry = new THREE.TorusGeometry(2, 0.6, 16, 100);
            const material = new THREE.MeshBasicMaterial({
                color: new THREE.Color(0x00ffcc),
                wireframe: true,
                transparent: true,
                opacity: 0.15
            });
            const torus = new THREE.Mesh(geometry, material);
            scene.add(torus);

            let animId = null;
            let warpState = null;

            window.addEventListener('resize', () => {
                const rw = window.innerWidth;
                const rh = window.innerHeight;
                renderer.setSize(rw, rh);
                camera.aspect = rw / rh;
                camera.updateProjectionMatrix();
            });

            function animate() {
                animId = requestAnimationFrame(animate);

                if (warpState) {
                    const elapsed = performance.now() - warpState.startTime;
                    const progress = Math.min(elapsed / 1000, 1);
                    const ease = progress * progress * progress;

                    // Zoom from idle distance through and past the torus
                    camera.position.z = idleZ - (ease * (idleZ + 10));
                    torus.rotation.x += 0.005 + ease * 0.2;
                    torus.rotation.y += 0.007 + ease * 0.3;
                    material.opacity = 0.15 + (ease * 0.65);

                    if (progress >= 1 && warpState.onDone) {
                        const cb = warpState.onDone;
                        warpState.onDone = null;
                        cb();
                    }
                } else {
                    torus.rotation.x += 0.005;
                    torus.rotation.y += 0.007;
                }

                renderer.render(scene, camera);
            }
            animate();

            window._loadingTorus = {
                setReady() {
                    material.opacity = 0.3;
                    material.color.set(0x66ddbb);
                },
                startWarp(onDone) {
                    warpState = { startTime: performance.now(), onDone };
                },
                reset() {
                    camera.position.z = idleZ;
                    material.opacity = 0.15;
                    material.color.set(0x00ffcc);
                    warpState = null;
                },
                dispose() {
                    if (animId) cancelAnimationFrame(animId);
                    geometry.dispose();
                    material.dispose();
                    renderer.dispose();
                    window._loadingTorus = null;
                }
            };
        }
    </script>
    <script src="<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'telaris-soundscape.js')); ?>"></script>
    <script type="module" src="<?php echo htmlspecialchars(asset_versioned_js_url($appVersion, 'main.js')); ?>"></script>
</body>
</html>