/**
 * Theme definitions for Telaris.
 * Controls backgrounds, lighting, animations, and icon sets.
 */

export const THEMES = {
    cosmic: {
        id: 'cosmic',
        name: 'Cosmic',
        background: {
            starfield: true,
            nebulas: true
        },
        animations: {
            rocket: true,
            ufo: true,
            comet: true,
            satellites: true,
            stationRing: true
        },
        lighting: {
            ambient: { color: 0xffffff, intensity: 0.3 },
            points: [
                { color: 0x4a90e2, x: 10, y: 10, z: 10 },
                { color: 0xe24a90, x: -10, y: -10, z: 10 },
                { color: 0x90e24a, x: 0, y: 10, z: -10 }
            ]
        },
        nodes: {
            type: 'geometry', // Use geometry factories
            factories: [
                'star',
                'moon',
                'five-point-star',
                'asteroid',
                'sparkle'
            ]
        }
    },
    abstract: {
        id: 'abstract',
        name: 'Abstract',
        background: {
            starfield: false,
            nebulas: false,
            grid: true,
            color: 0x000000
        },
        lighting: {
            ambient: { color: 0xffffff, intensity: 0.6 },
            points: [
                { color: 0xffffff, x: 15, y: 15, z: 15 },
                { color: 0xaaaaaa, x: -15, y: -15, z: -15 }
            ]
        },
        nodes: {
            type: 'image',
            portalImage: '/img/themes/abstract/portal_icon.gif',
            images: Array.from({ length: 73 }, (_, i) => `/img/themes/abstract/icon_${String(i + 1).padStart(3, '0')}.png`)
        }
    },
    rectangles: {
        id: 'rectangles',
        name: 'Rectangles',
        background: {
            starfield: false,
            nebulas: false,
            grid: true,
            color: 0x000000
        },
        lighting: {
            ambient: { color: 0xffffff, intensity: 0.6 },
            points: [
                { color: 0xffffff, x: 15, y: 15, z: 15 },
                { color: 0xaaaaaa, x: -15, y: -15, z: -15 }
            ]
        },
        nodes: {
            type: 'image',
            portalImage: '/img/themes/rectangles/portal_icon.gif',
            images: Array.from({ length: 6 }, (_, i) => `/img/themes/rectangles/icon_${String(i + 1).padStart(3, '0')}.png`)
        }
    },
    stripes: {
        id: 'stripes',
        name: 'Stripes',
        background: {
            starfield: false,
            nebulas: false,
            grid: true,
            color: 0x000000
        },
        lighting: {
            ambient: { color: 0xffffff, intensity: 0.6 },
            points: [
                { color: 0xffffff, x: 15, y: 15, z: 15 },
                { color: 0xaaaaaa, x: -15, y: -15, z: -15 }
            ]
        },
        nodes: {
            type: 'image',
            portalImage: '/img/themes/stripes/portal_icon.gif',
            images: Array.from({ length: 6 }, (_, i) => `/img/themes/stripes/icon_${String(i + 1).padStart(3, '0')}.png`)
        }
    },
    simple: {
        id: 'simple',
        name: 'Simple',
        background: {
            starfield: false,
            nebulas: false,
            color: 0x000000
        },
        animations: {},
        lighting: {
            ambient: { color: 0xffffff, intensity: 0.3 },
            points: [
                { color: 0x4a90e2, x: 10, y: 10, z: 10 },
                { color: 0xe24a90, x: -10, y: -10, z: 10 },
                { color: 0x90e24a, x: 0, y: 10, z: -10 }
            ]
        },
        nodes: {
            type: 'geometry',
            factories: ['sphere']
        }
    },
    tech: {
        id: 'tech',
        name: 'Tech',
        background: {
            starfield: false,
            nebulas: false,
            grid: false,
            techGrid: true,
            color: 0x000205
        },
        lighting: {
            ambient: { color: 0x888888, intensity: 0.5 },
            points: [
                { color: 0x666666, x: 15, y: 15, z: 15 },
                { color: 0x444444, x: -15, y: -15, z: -15 }
            ]
        },
        nodes: {
            type: 'image',
            portalImage: '/img/themes/tech/portal_icon.gif',
            images: Array.from({ length: 12 }, (_, i) => `/img/themes/tech/icon_${String(i + 1).padStart(3, '0')}.png`)
        }
    },
    'light-rainbow': {
        id: 'light-rainbow',
        name: 'Light Rainbow',
        // The only light-background theme. Node labels and overlay controls
        // carry their own translucent dark pills, so they stay legible on
        // the pale paper background. Geometry nodes are emissive, so they
        // glow their own (author-set) colours regardless of scene lighting;
        // the rainbow point-lights add a gentle multicolour wash + speculars.
        background: {
            starfield: false,
            nebulas: false,
            grid: false,
            color: 0xfffdf8
        },
        animations: {},
        lighting: {
            ambient: { color: 0xffffff, intensity: 0.85 },
            points: [
                { color: 0xf2602a, x: 10, y: 10, z: 10 },   // orange
                { color: 0x11b4e3, x: -10, y: -10, z: 10 },  // cyan
                { color: 0xc74b9c, x: 0, y: 10, z: -10 },    // magenta
                { color: 0xfcc147, x: 0, y: -10, z: 10 }     // gold
            ]
        },
        nodes: {
            type: 'geometry',
            factories: ['sphere', 'five-point-star', 'sparkle']
        }
    },
    rhizome: {
        id: 'rhizome',
        name: 'Rhizome',
        // A light-background theme. All connections stay visible at rest, the
        // most-connected wormholes are enlarged, and clicking a wormhole focuses
        // its direct neighbours (hiding the rest and zooming to fit) instead of
        // opening its card. Those behaviours live in telaris-3d.js / wormhole-grid-2d.js,
        // gated on the active theme id being 'rhizome'; this object only sets the
        // light palette and geometry nodes.
        background: {
            starfield: false,
            nebulas: false,
            grid: true,
            gridColors: { center: 0xebedf0, grid: 0xedeff1 }, // barely-there light grey graph paper on the light ground
            color: 0xf6f7f4
        },
        animations: {},
        lighting: {
            ambient: { color: 0xffffff, intensity: 1.0 },
            points: [
                { color: 0x8aa0b8, x: 10, y: 10, z: 10 },
                { color: 0xb0a0c0, x: -10, y: -10, z: 10 }
            ]
        },
        nodes: {
            type: 'geometry',
            factories: ['sphere']
        }
    },
    cornrow: {
        id: 'cornrow',
        name: 'Cornrow',
        // Eglash-cited fractal substrate: the scene background is a self-similar
        // nested/rotated-square weave (built in telaris-3d.js initGlitchyGrid via
        // background.fractal='cornrow'), after Ron Eglash's reading of cornrow
        // braiding as scaling+rotation geometry in African Fractals (1999).
        // Dark ground; node icons reuse the abstract family so only the
        // BACKGROUND changes. citation surfaces in the picker label + docs.
        citation: 'Recursive scaling weave after Ron Eglash, African Fractals (1999): cornrow braiding geometry.',
        background: {
            starfield: false,
            nebulas: false,
            grid: true,
            fractal: 'cornrow',
            gridColors: { center: 0x5a6b8c, grid: 0x5a6b8c }, // muted indigo weave on the dark ground
            color: 0x000000
        },
        lighting: {
            ambient: { color: 0xffffff, intensity: 0.6 },
            points: [
                { color: 0xffffff, x: 15, y: 15, z: 15 },
                { color: 0xaaaaaa, x: -15, y: -15, z: -15 }
            ]
        },
        nodes: {
            type: 'image',
            portalImage: '/img/themes/abstract/portal_icon.gif',
            images: Array.from({ length: 73 }, (_, i) => `/img/themes/abstract/icon_${String(i + 1).padStart(3, '0')}.png`)
        }
    }
};

export function getTheme(id) {
    if (!id) return THEMES.cosmic;
    const normalizedId = id.toLowerCase();
    return THEMES[normalizedId] || THEMES.cosmic;
}
