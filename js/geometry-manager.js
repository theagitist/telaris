import * as THREE from 'three';

/**
 * GeometryManager: centralizes and caches shared geometries to prevent
 * redundant allocations and reduce memory pressure.
 */
export class GeometryManager {
    constructor() {
        this.cache = new Map();
    }

    getOrCreate(id, creator) {
        if (!this.cache.has(id)) {
            this.cache.set(id, creator());
        }
        return this.cache.get(id);
    }

    /** Pre-allocate common geometries for the constellation icons. */
    getSphere(radius = 0.24, detail = 8) {
        return this.getOrCreate(`sphere_${radius}_${detail}`, () => new THREE.SphereGeometry(radius, detail, detail));
    }

    getOctahedron(radius = 0.4, detail = 0) {
        return this.getOrCreate(`octahedron_${radius}_${detail}`, () => new THREE.OctahedronGeometry(radius, detail));
    }

    getIcosahedron(radius = 0.22, detail = 1) {
        return this.getOrCreate(`icosahedron_${radius}_${detail}`, () => new THREE.IcosahedronGeometry(radius, detail));
    }

    getTetrahedron(radius = 0.2, detail = 0) {
        return this.getOrCreate(`tetrahedron_${radius}_${detail}`, () => new THREE.TetrahedronGeometry(radius, detail));
    }

    getCone(radius = 0.06, height = 0.22, radialSegments = 5) {
        return this.getOrCreate(`cone_${radius}_${height}_${radialSegments}`, () => new THREE.ConeGeometry(radius, height, radialSegments));
    }

    getExtrudedStar() {
        return this.getOrCreate('extruded_star', () => {
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
            return new THREE.ExtrudeGeometry(shape, { depth: 0.06, bevelEnabled: false });
        });
    }

    dispose() {
        for (const geo of this.cache.values()) {
            geo.dispose();
        }
        this.cache.clear();
    }
}
