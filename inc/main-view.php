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
    </style>
</head>
<body class="overflow-hidden bg-black font-sans">
    <div id="canvas-container" class="relative" style="position: relative; width: 100vw; height: 100vh; min-height: 100vh;">
        <div id="starfield-background" class="absolute z-0" style="inset: 0;" aria-hidden="true"></div>
        <div id="webgl-canvas-wrapper" class="absolute inset-0" style="z-index: 1;"></div>
        <div id="persistent-tooltips" class="absolute inset-0 pointer-events-none z-[150]" style="font-family: inherit;"></div>
        <div id="node-tooltip" class="absolute px-3 py-2 rounded text-sm pointer-events-none z-[200]" style="font-family: inherit; opacity: 0; visibility: hidden;"></div>
    </div>
    <div id="info" class="absolute top-5 left-5 text-white z-[100] text-sm opacity-80 pointer-events-none">
        <h2 class="text-lg font-semibold mb-1"><?php echo htmlspecialchars($projectName); ?></h2>
        <p><?php echo htmlspecialchars($projectTagline); ?></p>
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
