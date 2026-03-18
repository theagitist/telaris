/**
 * Constellation-themed node icon creation (star, moon, five-point star, asteroid, sparkle).
 * Optimized to use GeometryManager for shared geometry reuse.
 */

import * as THREE from 'three';
import { getTheme } from './themes.js';

const textureLoader = new THREE.TextureLoader();

// ── Torus wireframe portal icon ───────────────────────────────────────────────
let _torusTexture = null;

function getTorusWireframeTexture() {
    if (_torusTexture) return _torusTexture;

    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, size, size);

    const cx = size / 2;
    const cy = size / 2;
    const R = 78;  // major radius (px)
    const r  = 26; // tube radius  (px)

    // 3/4 perspective tilt
    const tiltX = Math.PI * 0.21; // ~38° around X
    const tiltY = Math.PI * 0.09; // ~16° around Y

    function project(x3, y3, z3) {
        const x1 = x3 * Math.cos(tiltY) + z3 * Math.sin(tiltY);
        const z1 = -x3 * Math.sin(tiltY) + z3 * Math.cos(tiltY);
        const y2 = y3 * Math.cos(tiltX) - z1 * Math.sin(tiltX);
        return { sx: cx + x1, sy: cy - y2 };
    }

    const nU = 14; // segments around the big circle
    const nV = 9;  // segments around the tube cross-section

    function drawWires() {
        // Tube-cross-section circles (fixed u, vary v)
        for (let i = 0; i < nU; i++) {
            const u = (i / nU) * Math.PI * 2;
            ctx.beginPath();
            for (let j = 0; j <= nV; j++) {
                const v = (j / nV) * Math.PI * 2;
                const p = project(
                    (R + r * Math.cos(v)) * Math.cos(u),
                    (R + r * Math.cos(v)) * Math.sin(u),
                    r * Math.sin(v)
                );
                j === 0 ? ctx.moveTo(p.sx, p.sy) : ctx.lineTo(p.sx, p.sy);
            }
            ctx.closePath();
            ctx.stroke();
        }
        // Spine circles (fixed v, vary u)
        for (let j = 0; j < nV; j++) {
            const v = (j / nV) * Math.PI * 2;
            ctx.beginPath();
            for (let i = 0; i <= nU; i++) {
                const u = (i / nU) * Math.PI * 2;
                const p = project(
                    (R + r * Math.cos(v)) * Math.cos(u),
                    (R + r * Math.cos(v)) * Math.sin(u),
                    r * Math.sin(v)
                );
                i === 0 ? ctx.moveTo(p.sx, p.sy) : ctx.lineTo(p.sx, p.sy);
            }
            ctx.closePath();
            ctx.stroke();
        }
    }

    // Glow pass
    ctx.strokeStyle = 'rgba(80, 220, 140, 0.30)';
    ctx.lineWidth = 5;
    ctx.shadowColor = 'rgba(40, 180, 100, 0.6)';
    ctx.shadowBlur = 14;
    ctx.lineCap = 'round';
    drawWires();

    // Sharp pass
    ctx.strokeStyle = 'rgba(110, 240, 165, 0.95)';
    ctx.lineWidth = 1.6;
    ctx.shadowBlur = 5;
    drawWires();

    _torusTexture = new THREE.CanvasTexture(canvas);
    return _torusTexture;
}

function createTorusPortalSprite(material) {
    const spriteMaterial = new THREE.SpriteMaterial({
        map: getTorusWireframeTexture(),
        color: 0xffffff,
        transparent: true,
        opacity: material.opacity,
        sizeAttenuation: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
    });
    spriteMaterial.isSpriteMaterial = true;
    const sprite = new THREE.Sprite(spriteMaterial);
    sprite.scale.set(2.0, 2.0, 1);
    sprite.isSprite = true;
    sprite.isPortal = true;
    sprite.renderOrder = 100;
    return sprite;
}

function createStarNode(material, gm) {
    const starGroup = new THREE.Group();
    const centerGeometry = gm.getSphere(0.24, 8);
    const center = new THREE.Mesh(centerGeometry, material);
    starGroup.add(center);
    const spikeGeometry = gm.getOctahedron(0.4, 0);
    const spikeMaterial = material.clone();
    spikeMaterial.emissiveIntensity = material.emissiveIntensity * 1.2;
    const directions = [
        [0, 1, 0], [0, -1, 0], [1, 0, 0], [-1, 0, 0],
        [0, 0, 1], [0, 0, -1],
        [0.7, 0.7, 0], [-0.7, 0.7, 0], [0.7, -0.7, 0], [-0.7, -0.7, 0]
    ];
    directions.forEach((dir) => {
        const spike = new THREE.Mesh(spikeGeometry, spikeMaterial);
        spike.position.set(dir[0] * 0.32, dir[1] * 0.32, dir[2] * 0.32);
        spike.scale.set(0.3, 0.3, 0.3);
        starGroup.add(spike);
    });
    return starGroup;
}

function createMoonNode(material, gm) {
    const group = new THREE.Group();
    const geo = gm.getSphere(0.28, 16);
    group.add(new THREE.Mesh(geo, material));
    return group;
}

function createFivePointStarNode(material, gm) {
    const group = new THREE.Group();
    const starMat = material.clone();
    starMat.side = THREE.DoubleSide;
    const geo = gm.getExtrudedStar();
    const mesh = new THREE.Mesh(geo, starMat);
    group.add(mesh);
    return group;
}

function createAsteroidNode(material, gm) {
    const group = new THREE.Group();
    const geo = gm.getIcosahedron(0.22, 1);
    group.add(new THREE.Mesh(geo, material));
    return group;
}

function createSparkleNode(material, gm) {
    const group = new THREE.Group();
    const coreGeo = gm.getTetrahedron(0.2, 0);
    group.add(new THREE.Mesh(coreGeo, material));
    const rayMat = material.clone();
    rayMat.emissiveIntensity = material.emissiveIntensity * 1.3;
    const rayGeo = gm.getCone(0.06, 0.22, 5);
    const up = new THREE.Vector3(0, 1, 0);
    const dirs = [[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]];
    dirs.forEach(([x, y, z]) => {
        const ray = new THREE.Mesh(rayGeo, rayMat);
        const out = new THREE.Vector3(x, y, z).normalize();
        ray.position.copy(out).multiplyScalar(0.18);
        ray.quaternion.setFromUnitVectors(up, out);
        group.add(ray);
    });
    return group;
}

function createImageNode(imageUrl, material) {
    const texture = textureLoader.load(imageUrl);
    const spriteMaterial = new THREE.SpriteMaterial({ 
        map: texture, 
        color: 0xffffff,
        transparent: true,
        opacity: material.opacity,
        sizeAttenuation: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false
    });
    spriteMaterial.isSpriteMaterial = true; // For update logic
    const sprite = new THREE.Sprite(spriteMaterial);
    sprite.scale.set(1.8, 1.8, 1);
    sprite.isSprite = true; // Mark as sprite for update logic
    sprite.renderOrder = 100;
    return sprite;
}

function createImageMeshNode(imageUrl, material) {
    const texture = textureLoader.load(imageUrl);
    // Use Sprite (billboard) so it always faces the camera and blends cleanly,
    // matching the transparency behaviour of regular image nodes.
    // Warm amber tint (vs pure white for regular nodes) marks it as a portal.
    const spriteMaterial = new THREE.SpriteMaterial({
        map: texture,
        color: 0xffcc88,
        transparent: true,
        opacity: material.opacity,
        sizeAttenuation: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
    });
    spriteMaterial.isSpriteMaterial = true;
    const sprite = new THREE.Sprite(spriteMaterial);
    sprite.scale.set(1.8, 1.8, 1);
    sprite.isSprite = true;
    sprite.renderOrder = 100;
    return sprite;
}

function createPortalNode(material, gm) {
    const group = new THREE.Group();

    // The visible torus
    let geometry;
    if (gm && typeof gm.getTorus === 'function') {
        geometry = gm.getTorus(0.28, 0.04, 16, 32);
    } else {
        console.warn("Falling back to direct TorusGeometry - check geometry-manager.js structure.");
        geometry = new THREE.TorusGeometry(0.28, 0.04, 16, 32);
    }
    const mesh = new THREE.Mesh(geometry, material);
    group.add(mesh);

    // Invisible hitbox so clicking the hole or near the thin wires still triggers the portal
    const hitboxGeo = new THREE.SphereGeometry(0.5, 8, 8);
    const hitboxMat = new THREE.MeshBasicMaterial({ 
        transparent: true, 
        opacity: 0,
        depthWrite: false,
        side: THREE.DoubleSide
    });
    const hitbox = new THREE.Mesh(hitboxGeo, hitboxMat);
    hitbox.name = "portal_hitbox";
    group.add(hitbox);

    group.isPortal = true; // For animation (rotate whole portal as one)
    return group;
}

const iconFactories = {
    'star': createStarNode,
    'moon': createMoonNode,
    'five-point-star': createFivePointStarNode,
    'asteroid': createAsteroidNode,
    'sparkle': createSparkleNode
};

export function createNodeIcon(material, index, gm, type = 'object', themeId = 'cosmic', iconUrl = null) {
    if (iconUrl) {
        return createImageNode(iconUrl, material);
    }

    const theme = getTheme(themeId);

    if (type === 'portal') {
        // Torus wireframe sprite — looks like the other image nodes but is clearly a portal
        return createTorusPortalSprite(material);
    }

    if (theme.nodes.type === 'image') {
        const images = theme.nodes.images;
        const choice = (index * 1103515245 + 12345) >>> 0;
        return createImageNode(images[choice % images.length], material);
    } else {
        const factories = theme.nodes.factories;
        const choice = (index * 1103515245 + 12345) >>> 0;
        const factoryId = factories[choice % factories.length];
        const factory = iconFactories[factoryId] || createStarNode;
        return factory(material, gm);
    }
}
