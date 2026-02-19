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

        #bg-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
            opacity: 0.6;
        }

        #fade-overlay {
            position: fixed;
            inset: 0;
            background: #000;
            z-index: 100;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.4s ease-in;
        }

        body.traversing {
            opacity: 0;
            transition: opacity 0.8s ease-in-out;
        }

        .footer-note {
            position: fixed;
            bottom: 2rem;
            font-size: 0.65rem;
            opacity: 0.25;
            text-transform: uppercase;
            letter-spacing: 0.1rem;
            z-index: 10;
        }
    </style>
    <script type="importmap">
        {
            "imports": {
                "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js"
            }
        }
    </script>
</head>
<body class="transition-opacity duration-700">
    <div id="fade-overlay"></div>
    <canvas id="bg-canvas"></canvas>

    <div class="content" id="main-content">
        <div class="title">Launching <?php echo htmlspecialchars($nodeName); ?></div>
        <div class="subtitle"><?php echo nl2br(htmlspecialchars($alertMsg)); ?></div>
        <div class="countdown" id="cd">5</div>
    </div>

    <div class="footer-note">
        Mission Active
    </div>

    <script type="module">
        import * as THREE from 'three';

        (function() {
            const url = <?php echo $urlJson; ?>;
            const cdEl = document.getElementById('cd');
            const overlay = document.getElementById('fade-overlay');
            const content = document.getElementById('main-content');
            let count = 5;
            let isLaunching = false;
            let launchStartTime = 0;

            // Background Animation
            const canvas = document.getElementById('bg-canvas');
            const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
            renderer.setSize(window.innerWidth, window.innerHeight);
            renderer.setPixelRatio(window.devicePixelRatio);

            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
            camera.position.z = 5;

            const geometry = new THREE.TorusGeometry(2, 0.6, 16, 100);
            const material = new THREE.MeshBasicMaterial({ 
                color: new THREE.Color(<?php echo $r/255; ?>, <?php echo $g/255; ?>, <?php echo $b/255; ?>),
                wireframe: true,
                transparent: true,
                opacity: 0.15
            });
            const torus = new THREE.Mesh(geometry, material);
            scene.add(torus);

            function animate() {
                requestAnimationFrame(animate);
                
                if (!isLaunching) {
                    torus.rotation.x += 0.005;
                    torus.rotation.y += 0.007;
                } else {
                    const elapsed = performance.now() - launchStartTime;
                    const duration = 1000; // ms
                    const progress = Math.min(elapsed / duration, 1);
                    
                    // Cubic ease-in for more dramatic acceleration
                    const easeProgress = progress * progress * progress;
                    
                    // Zoom camera deep through the torus
                    camera.position.z = 5 - (easeProgress * 15);
                    
                    // Rapidly spin up the torus
                    torus.rotation.x += 0.005 + easeProgress * 0.2;
                    torus.rotation.y += 0.007 + easeProgress * 0.3;
                    
                    // Get brighter as we zoom in (from 0.15 up to 0.8)
                    torus.material.opacity = 0.15 + (easeProgress * 0.65);
                }
                
                renderer.render(scene, camera);
            }
            animate();

            window.addEventListener('resize', () => {
                camera.aspect = window.innerWidth / window.innerHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(window.innerWidth, window.innerHeight);
            });

            // Countdown
            const timer = setInterval(() => {
                count--;
                if (count > 0) {
                    cdEl.innerText = count;
                } else {
                    clearInterval(timer);
                    cdEl.innerText = "GO";
                    
                    // Start immersive warp effect
                    isLaunching = true;
                    launchStartTime = performance.now();
                    
                    // Phase 1: Rapid UI fade
                    content.style.transition = 'opacity 0.3s ease-out';
                    content.style.opacity = '0';
                    
                    // Phase 2: Fade entire viewport to black during the zoom
                    setTimeout(() => {
                        document.body.classList.add('traversing');
                    }, 200);
                    
                    // Final navigation after the warp completes
                    setTimeout(() => {
                        window.location.href = url;
                    }, 1050);
                }
            }, 1000);
        })();
    </script>
</body>
</html>