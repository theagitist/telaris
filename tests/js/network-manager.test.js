import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { NetworkManager } from '../../js/network-manager.js';

describe('NetworkManager', () => {
    let mgr;

    beforeEach(() => {
        mgr = new NetworkManager();
    });

    describe('constructor defaults', () => {
        it('has null focused node', () => {
            assert.equal(mgr.getFocusedNode(), null);
        });

        it('has default fadeSpeed of 0.1', () => {
            assert.equal(mgr.fadeSpeed, 0.1);
        });

        it('has default visibilityThreshold of 0.002', () => {
            assert.equal(mgr.visibilityThreshold, 0.002);
        });
    });

    describe('constructor with custom options', () => {
        it('accepts custom fadeSpeed', () => {
            const m = new NetworkManager({ fadeSpeed: 0.5 });
            assert.equal(m.fadeSpeed, 0.5);
        });

        it('accepts custom visibilityThreshold', () => {
            const m = new NetworkManager({ visibilityThreshold: 0.01 });
            assert.equal(m.visibilityThreshold, 0.01);
        });
    });

    describe('setFocusedNode / getFocusedNode', () => {
        it('stores and retrieves the focused node', () => {
            const node = { id: 1 };
            mgr.setFocusedNode(node);
            assert.equal(mgr.getFocusedNode(), node);
        });

        it('clears focus with null', () => {
            mgr.setFocusedNode({ id: 1 });
            mgr.setFocusedNode(null);
            assert.equal(mgr.getFocusedNode(), null);
        });
    });

    describe('getTargetOpacityForConnection()', () => {
        const nodeA = { visible: true };
        const nodeB = { visible: true };
        const nodeC = { visible: true };

        it('returns 0 when no node is focused', () => {
            const conn = { node1: nodeA, node2: nodeB };
            assert.equal(mgr.getTargetOpacityForConnection(conn), 0);
        });

        it('returns baseOpacity when focused node is node1', () => {
            mgr.setFocusedNode(nodeA);
            const conn = { node1: nodeA, node2: nodeB, baseOpacity: 0.7 };
            assert.equal(mgr.getTargetOpacityForConnection(conn), 0.7);
        });

        it('returns baseOpacity when focused node is node2', () => {
            mgr.setFocusedNode(nodeB);
            const conn = { node1: nodeA, node2: nodeB, baseOpacity: 0.7 };
            assert.equal(mgr.getTargetOpacityForConnection(conn), 0.7);
        });

        it('returns 0 when focused node is neither endpoint', () => {
            mgr.setFocusedNode(nodeC);
            const conn = { node1: nodeA, node2: nodeB, baseOpacity: 0.7 };
            assert.equal(mgr.getTargetOpacityForConnection(conn), 0);
        });

        it('defaults baseOpacity to 0.5', () => {
            mgr.setFocusedNode(nodeA);
            const conn = { node1: nodeA, node2: nodeB };
            assert.equal(mgr.getTargetOpacityForConnection(conn), 0.5);
        });

        it('returns 0 when node1 is not visible', () => {
            mgr.setFocusedNode(nodeA);
            const conn = { node1: { visible: false }, node2: nodeB };
            assert.equal(mgr.getTargetOpacityForConnection(conn), 0);
        });

        it('returns 0 when node2 is not visible', () => {
            mgr.setFocusedNode(nodeA);
            const conn = { node1: nodeA, node2: { visible: false } };
            assert.equal(mgr.getTargetOpacityForConnection(conn), 0);
        });
    });

    describe('updateVisibility()', () => {
        function makeConn(node1, node2, opts = {}) {
            return {
                node1,
                node2,
                baseOpacity: opts.baseOpacity ?? 0.5,
                currentOpacity: opts.currentOpacity ?? 0,
                targetOpacity: 0,
                mesh: {
                    visible: false,
                    material: { opacity: 0 },
                },
            };
        }

        it('handles empty/null connections gracefully', () => {
            assert.doesNotThrow(() => mgr.updateVisibility(null));
            assert.doesNotThrow(() => mgr.updateVisibility([]));
        });

        it('forces invisible when node1 is filtered out', () => {
            const conn = makeConn({ visible: false }, { visible: true }, { currentOpacity: 0.5 });
            conn.mesh.visible = true;
            conn.mesh.material.opacity = 0.5;

            mgr.updateVisibility([conn]);

            assert.equal(conn.currentOpacity, 0);
            assert.equal(conn.mesh.visible, false);
            assert.equal(conn.mesh.material.opacity, 0);
        });

        it('lerps opacity toward target', () => {
            const nodeA = { visible: true };
            const nodeB = { visible: true };
            mgr.setFocusedNode(nodeA);

            const conn = makeConn(nodeA, nodeB, { baseOpacity: 1.0, currentOpacity: 0 });

            // Run several frames
            for (let i = 0; i < 50; i++) {
                mgr.updateVisibility([conn]);
            }

            // After 50 lerp steps at 0.1, should be very close to 1.0
            assert.ok(conn.currentOpacity > 0.99, `Expected near 1.0, got ${conn.currentOpacity}`);
            assert.equal(conn.mesh.visible, true);
        });

        it('applies fadeMultiplier to final opacity', () => {
            const nodeA = { visible: true };
            const nodeB = { visible: true };
            mgr.setFocusedNode(nodeA);

            const conn = makeConn(nodeA, nodeB, { baseOpacity: 1.0, currentOpacity: 1.0 });

            mgr.updateVisibility([conn], 0, 0.5);

            // Material opacity should be ~0.5 (currentOpacity 1.0 * fadeMultiplier 0.5)
            assert.ok(Math.abs(conn.mesh.material.opacity - 0.5) < 0.1,
                `Expected ~0.5 with fadeMultiplier, got ${conn.mesh.material.opacity}`);
        });

        it('hides connection when opacity drops below threshold', () => {
            const nodeA = { visible: true };
            const nodeB = { visible: true };
            // No focused node — target is 0

            const conn = makeConn(nodeA, nodeB, { currentOpacity: 0.001 });
            conn.mesh.visible = true;

            mgr.updateVisibility([conn]);

            // 0.001 * (1 - 0.1) ≈ 0.0009 which is < 0.002 threshold
            assert.equal(conn.mesh.visible, false);
        });
    });
});
