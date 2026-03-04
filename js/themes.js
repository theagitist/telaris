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
            portalImage: 'img/themes/abstract/portal_icon.gif',
            images: Array.from({ length: 73 }, (_, i) => `img/themes/abstract/icon_${String(i + 1).padStart(3, '0')}.png`)
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
            portalImage: 'img/themes/rectangles/portal_icon.gif',
            images: Array.from({ length: 6 }, (_, i) => `img/themes/rectangles/icon_${String(i + 1).padStart(3, '0')}.png`)
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
            portalImage: 'img/themes/stripes/portal_icon.gif',
            images: Array.from({ length: 6 }, (_, i) => `img/themes/stripes/icon_${String(i + 1).padStart(3, '0')}.png`)
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
            portalImage: 'img/themes/tech/portal_icon.gif',
            images: Array.from({ length: 12 }, (_, i) => `img/themes/tech/icon_${String(i + 1).padStart(3, '0')}.png`)
        }
    }
};

export function getTheme(id) {
    if (!id) return THEMES.cosmic;
    const normalizedId = id.toLowerCase();
    return THEMES[normalizedId] || THEMES.cosmic;
}
