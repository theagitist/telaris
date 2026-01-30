/**
 * Constellation-themed node icon creation (star, moon, five-point star, asteroid, sparkle).
 */

import * as THREE from 'three';

function createStarNode(material) {
    const starGroup = new THREE.Group();
    const centerGeometry = new THREE.SphereGeometry(0.24, 8, 8);
    const center = new THREE.Mesh(centerGeometry, material);
    starGroup.add(center);
    const spikeGeometry = new THREE.OctahedronGeometry(0.4, 0);
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

function createMoonNode(material) {
    const group = new THREE.Group();
    const geo = new THREE.SphereGeometry(0.28, 16, 16);
    group.add(new THREE.Mesh(geo, material));
    return group;
}

function createFivePointStarNode(material) {
    const group = new THREE.Group();
    const R = 0.28, r = 0.11;
    const shape = new THREE.Shape();
    for (let i = 0; i < 10; i++) {
        const angle = (i / 5) * Math.PI - Math.PI / 2;
        const radius = (i % 2 === 0) ? R : r;
        const x = Math.cos(angle) * radius;
        const y = Math.sin(angle) * radius;
        if (i === 0) shape.moveTo(x, y);
        else shape.lineTo(x, y);
    }
    shape.closePath();
    const starMat = material.clone();
    starMat.side = THREE.DoubleSide;
    const geo = new THREE.ExtrudeGeometry(shape, { depth: 0.06, bevelEnabled: false });
    const mesh = new THREE.Mesh(geo, starMat);
    group.add(mesh);
    return group;
}

function createAsteroidNode(material) {
    const group = new THREE.Group();
    const geo = new THREE.IcosahedronGeometry(0.22, 1);
    group.add(new THREE.Mesh(geo, material));
    return group;
}

function createSparkleNode(material) {
    const group = new THREE.Group();
    const coreGeo = new THREE.TetrahedronGeometry(0.2, 0);
    group.add(new THREE.Mesh(coreGeo, material));
    const rayMat = material.clone();
    rayMat.emissiveIntensity = material.emissiveIntensity * 1.3;
    const rayGeo = new THREE.ConeGeometry(0.06, 0.22, 5);
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

const iconFactories = [
    createStarNode,
    createMoonNode,
    createFivePointStarNode,
    createAsteroidNode,
    createSparkleNode
];

export function createNodeIcon(material, index) {
    const choice = (index * 1103515245 + 12345) >>> 0;
    return iconFactories[choice % 5](material);
}
