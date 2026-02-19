/**
 * NetworkManager: owns interaction state (focused node) and connection visibility logic.
 * Decouples 3D rendering from which node is hovered/tapped, and centralizes transition
 * logic so more complex effects (e.g. staggered fade, duration-based transitions) can
 * be added in one place.
 */
export class NetworkManager {
    /**
     * @param {Object} [options]
     * @param {number} [options.fadeSpeed] - Lerp factor per frame for opacity (0–1). Default 0.1.
     * @param {number} [options.visibilityThreshold] - Opacity below which mesh is hidden. Default 0.002.
     */
    constructor(options = {}) {
        this._focusedNode = null;
        this.fadeSpeed = options.fadeSpeed ?? 0.1;
        this.visibilityThreshold = options.visibilityThreshold ?? 0.002;
    }

    /** Set the node that is currently focused (hovered or tapped). Drives which connections are visible. */
    setFocusedNode(node) {
        this._focusedNode = node;
    }

    /** Get the currently focused node, or null. */
    getFocusedNode() {
        return this._focusedNode;
    }

    /**
     * Compute target opacity for a connection based on focused node.
     * Connection is relevant if it links node1 to node2 and focused node is one of them.
     * @param {{ node1: object, node2: object, baseOpacity?: number }} connection
     * @returns {number} Target opacity (0 or baseOpacity)
     */
    getTargetOpacityForConnection(connection) {
        if (!this._focusedNode || !connection.node1.visible || !connection.node2.visible) return 0;
        const base = connection.baseOpacity ?? 0.5;
        const isRelevant = (this._focusedNode === connection.node1 || this._focusedNode === connection.node2);
        return isRelevant ? base : 0;
    }

    /**
     * Update connection visibility: lerp current opacity toward target and apply to meshes.
     * Call this each frame after updating connection positions.
     * @param {Array<{ mesh: { material: { opacity: number }, visible: boolean }, node1: object, node2: object, baseOpacity?: number, currentOpacity: number, targetOpacity: number }>} connections
     * @param {number} [deltaTimeSec=0] - Time since last frame in seconds. If 0, uses per-frame fade (backward compatible).
     * @param {number} [fadeMultiplier] - Optional 0–1 multiplier (e.g. portal fade-in); applied to final opacity.
     */
    updateVisibility(connections, deltaTimeSec = 0, fadeMultiplier = null) {
        if (!connections || connections.length === 0) return;

        // Scale fade by time so behavior is consistent across frame rates when deltaTime is provided
        const t = deltaTimeSec > 0 ? Math.min(this.fadeSpeed * (deltaTimeSec * 60), 1) : this.fadeSpeed;

        for (const conn of connections) {
            // CRITICAL: Force immediate invisibility if either node is filtered out
            if (!conn.node1.visible || !conn.node2.visible) {
                conn.currentOpacity = 0;
                conn.targetOpacity = 0;
                conn.mesh.visible = false;
                if (conn.mesh.material) conn.mesh.material.opacity = 0;
                continue;
            }

            conn.targetOpacity = this.getTargetOpacityForConnection(conn);
            conn.currentOpacity += (conn.targetOpacity - conn.currentOpacity) * t;

            if (Math.abs(conn.currentOpacity - conn.targetOpacity) < 0.002) {
                conn.currentOpacity = conn.targetOpacity;
            }
            
            // Sync with material
            const mat = conn.mesh.material;
            let opacity = conn.currentOpacity;
            
            // Scale by fadeMultiplier if provided (e.g. during portal fade-in)
            if (fadeMultiplier !== null && fadeMultiplier !== undefined) {
                opacity *= fadeMultiplier;
            }
            
            mat.opacity = opacity;
            conn.mesh.visible = opacity > this.visibilityThreshold;

            // Selective Glow: Increase emissive intensity when line is becoming visible
            if (mat.emissiveIntensity !== undefined) {
                if (conn._baseEmissiveIntensity === undefined) {
                    conn._baseEmissiveIntensity = mat.emissiveIntensity;
                }
                const isFocused = this._focusedNode !== null && (this._focusedNode === conn.node1 || this._focusedNode === conn.node2);
                const targetIntensity = isFocused ? 2.0 : conn._baseEmissiveIntensity;
                mat.emissiveIntensity += (targetIntensity - mat.emissiveIntensity) * t;
            }
        }
    }
}
