import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { THEMES, getTheme } from '../../js/themes.js';

describe('THEMES object', () => {
    const expectedThemes = ['cosmic', 'abstract', 'rectangles', 'stripes', 'tech'];

    it('contains all expected themes', () => {
        for (const id of expectedThemes) {
            assert.ok(THEMES[id], `Missing theme: ${id}`);
        }
    });

    it('each theme has required top-level keys', () => {
        const requiredKeys = ['id', 'name', 'background', 'lighting', 'nodes'];
        for (const [id, theme] of Object.entries(THEMES)) {
            for (const key of requiredKeys) {
                assert.ok(key in theme, `Theme '${id}' missing key '${key}'`);
            }
        }
    });

    it('each theme has id matching its key', () => {
        for (const [key, theme] of Object.entries(THEMES)) {
            assert.equal(theme.id, key, `Theme key '${key}' has mismatched id '${theme.id}'`);
        }
    });

    it('each theme has lighting with ambient and points', () => {
        for (const [id, theme] of Object.entries(THEMES)) {
            assert.ok(theme.lighting.ambient, `Theme '${id}' missing lighting.ambient`);
            assert.ok(typeof theme.lighting.ambient.color === 'number', `Theme '${id}' ambient.color should be a number`);
            assert.ok(typeof theme.lighting.ambient.intensity === 'number', `Theme '${id}' ambient.intensity should be a number`);
            assert.ok(Array.isArray(theme.lighting.points), `Theme '${id}' lighting.points should be an array`);
            assert.ok(theme.lighting.points.length >= 1, `Theme '${id}' should have at least one point light`);
        }
    });

    it('each theme has nodes with a type', () => {
        for (const [id, theme] of Object.entries(THEMES)) {
            assert.ok(['geometry', 'image'].includes(theme.nodes.type),
                `Theme '${id}' nodes.type should be 'geometry' or 'image', got '${theme.nodes.type}'`);
        }
    });

    it('geometry themes have factories array', () => {
        for (const [id, theme] of Object.entries(THEMES)) {
            if (theme.nodes.type === 'geometry') {
                assert.ok(Array.isArray(theme.nodes.factories), `Theme '${id}' with type=geometry should have factories array`);
                assert.ok(theme.nodes.factories.length > 0, `Theme '${id}' factories should not be empty`);
            }
        }
    });

    it('image themes have images array and portalImage', () => {
        for (const [id, theme] of Object.entries(THEMES)) {
            if (theme.nodes.type === 'image') {
                assert.ok(Array.isArray(theme.nodes.images), `Theme '${id}' with type=image should have images array`);
                assert.ok(theme.nodes.images.length > 0, `Theme '${id}' images should not be empty`);
                assert.ok(typeof theme.nodes.portalImage === 'string', `Theme '${id}' should have portalImage string`);
            }
        }
    });
});

describe('getTheme()', () => {
    it('returns cosmic for null/undefined/empty', () => {
        assert.equal(getTheme(null).id, 'cosmic');
        assert.equal(getTheme(undefined).id, 'cosmic');
        assert.equal(getTheme('').id, 'cosmic');
    });

    it('returns correct theme for valid IDs', () => {
        assert.equal(getTheme('cosmic').id, 'cosmic');
        assert.equal(getTheme('abstract').id, 'abstract');
        assert.equal(getTheme('rectangles').id, 'rectangles');
        assert.equal(getTheme('stripes').id, 'stripes');
        assert.equal(getTheme('tech').id, 'tech');
    });

    it('is case-insensitive', () => {
        assert.equal(getTheme('COSMIC').id, 'cosmic');
        assert.equal(getTheme('Abstract').id, 'abstract');
        assert.equal(getTheme('TECH').id, 'tech');
    });

    it('falls back to cosmic for unknown themes', () => {
        assert.equal(getTheme('nonexistent').id, 'cosmic');
        assert.equal(getTheme('galaxy').id, 'cosmic');
    });
});
