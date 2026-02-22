/**
 * Constellation-themed node icon creation (star, moon, five-point star, asteroid, sparkle).
 * Optimized to use GeometryManager for shared geometry reuse.
 */

import * as THREE from 'three';
import { getTheme } from './themes.js';

const textureLoader = new THREE.TextureLoader();

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
    sprite.scale.set(1.5, 1.5, 1);
    sprite.isSprite = true; // Mark as sprite for update logic
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

export function createNodeIcon(material, index, gm, type = 'object', themeId = 'cosmic') {
    if (type === 'portal') {
        return createPortalNode(material, gm);
    }

    const theme = getTheme(themeId);
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
