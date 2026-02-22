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
            color: 0x000000
        },
        animations: {
            rocket: false,
            ufo: false,
            comet: false,
            satellites: false,
            stationRing: false
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
            images: [
                'img/themes/abstract/signal-2026-02-21-201428_002.gif',
                'img/themes/abstract/signal-2026-02-21-201428_003.gif',
                'img/themes/abstract/signal-2026-02-21-201428_004.gif',
                'img/themes/abstract/signal-2026-02-21-201428_005.gif',
                'img/themes/abstract/signal-2026-02-21-201526_002.png',
                'img/themes/abstract/signal-2026-02-21-201526_003.png',
                'img/themes/abstract/signal-2026-02-21-201526_004.png',
                'img/themes/abstract/signal-2026-02-21-201526_005.png',
                'img/themes/abstract/generated_abstract_1.svg',
                'img/themes/abstract/generated_abstract_2.svg',
                'img/themes/abstract/generated_abstract_3.svg',
                'img/themes/abstract/generated_abstract_4.svg',
                'img/themes/abstract/generated_abstract_5.svg',
                'img/themes/abstract/generated_abstract_6.svg',
                'img/themes/abstract/generated_abstract_7.svg',
                'img/themes/abstract/generated_abstract_8.svg'
            ]
        }
    }
};

export function getTheme(id) {
    if (!id) return THEMES.cosmic;
    const normalizedId = id.toLowerCase();
    return THEMES[normalizedId] || THEMES.cosmic;
}
