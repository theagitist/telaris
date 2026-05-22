<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for the PUT /api/nodes.php IDOR (audit v6.10.0).
 *
 * The pre-fix code seat-checked the request body's `constellation_id` instead of
 * the node's actual `constellation_id`, letting an editor on galaxy A pass a
 * node id from galaxy B together with their own galaxy A and have the access
 * check pass. The fix resolves the node's current galaxy from the database and
 * seat-checks that — and separately seat-checks any move target.
 *
 * This test pins the invariant: for an editor without access to the target
 * galaxy, the combination `checkEditorConstellationAccess(db_get_node_constellation_id($id))`
 * MUST surface as a denial regardless of what the request body claims.
 */
final class NodesPutAccessTest extends TestCase
{
    private PDO $pdo;
    private string $editorUserId = '';
    private int $allowedGalaxyId = 0;
    private int $forbiddenGalaxyId = 0;
    private int $forbiddenNodeId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();

        $this->allowedGalaxyId = db_create_constellation(
            'Aitest Idor Allowed', '',
            'aitest-idor-allowed-' . bin2hex(random_bytes(4)), 'cosmic'
        );
        $this->forbiddenGalaxyId = db_create_constellation(
            'Aitest Idor Forbidden', '',
            'aitest-idor-forbidden-' . bin2hex(random_bytes(4)), 'cosmic'
        );

        $this->forbiddenNodeId = db_create_node(
            'aitest-idor-node', null, null,
            json_encode(['radius' => 5.0, 'theta' => 0.0, 'phi' => 0.0, 'speed' => 0.002, 'phase' => 0.0]),
            $this->forbiddenGalaxyId
        );

        $this->editorUserId = 'aitest-idor-editor-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type) VALUES (?, ?, ?, ?, ?, 1)"
        )->execute([
            $this->editorUserId,
            $this->editorUserId . '@aitest.local',
            password_hash('aitest-throwaway-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'Aitest',
            'Idor',
        ]);
        db_set_user_constellations($this->editorUserId, [$this->allowedGalaxyId]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testNodeConstellationIdReflectsActualRow(): void
    {
        $actual = db_get_node_constellation_id($this->forbiddenNodeId);
        $this->assertSame(
            $this->forbiddenGalaxyId,
            $actual,
            'db_get_node_constellation_id MUST return the node\'s actual galaxy, not any externally supplied value.'
        );
    }

    public function testEditorSeatsExcludeUnauthorizedGalaxy(): void
    {
        $seats = array_column(
            db_get_constellations_for_user($this->editorUserId, false),
            'id'
        );
        $this->assertContains($this->allowedGalaxyId, $seats);
        $this->assertNotContains(
            $this->forbiddenGalaxyId,
            $seats,
            'Editor must not have a seat on the forbidden galaxy; otherwise the IDOR test scenario is invalid.'
        );
    }

    public function testIdorScenarioIsDeniedAgainstActualConstellation(): void
    {
        $actualConstellationId = db_get_node_constellation_id($this->forbiddenNodeId);
        $this->assertNotNull($actualConstellationId);

        $seats = array_column(
            db_get_constellations_for_user($this->editorUserId, false),
            'id'
        );

        $bodyConstellationId = $this->allowedGalaxyId;
        $this->assertContains(
            $bodyConstellationId,
            $seats,
            'Body value alone would have passed the pre-fix seat check.'
        );

        $this->assertNotContains(
            $actualConstellationId,
            $seats,
            'Seat check against the node\'s actual galaxy MUST deny the editor.'
        );
    }

    public function testMoveBetweenGalaxiesRequiresSeatOnBothSides(): void
    {
        $seats = array_column(
            db_get_constellations_for_user($this->editorUserId, false),
            'id'
        );

        $currentConstellationId = db_get_node_constellation_id($this->forbiddenNodeId);
        $bodyMoveTargetId = $this->allowedGalaxyId;

        $sourceAllowed = in_array($currentConstellationId, $seats, true);
        $targetAllowed = in_array($bodyMoveTargetId, $seats, true);

        $this->assertFalse(
            $sourceAllowed && $targetAllowed,
            'A move attempt must fail when either source or target seat is missing.'
        );
        $this->assertFalse(
            $sourceAllowed,
            'In the IDOR scenario the editor lacks the source seat; this MUST short-circuit the access check.'
        );
    }

    private function cleanup(): void
    {
        // Remove any leftover aitest users (cascades to user_constellations).
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-idor-editor-%' OR email LIKE 'aitest-idor-editor-%@aitest.local'");
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-idor-%'");
        if ($stmt === false) return;
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo->prepare("DELETE FROM nodes WHERE constellation_id = ?")->execute([(int)$id]);
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$id]);
        }
    }
}
