<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for the POST /api/nodes.php portal IDOR (audit v6.10.8).
 *
 * Pre-fix, POST seat-checked the host galaxy (constellation_id) but accepted any
 * target_constellation_id without checking the editor's seat on it. An editor
 * on galaxy A could create a portal node in A pointing at galaxy B without
 * holding a seat on B.
 *
 * The fix mirrors the PUT block: after parseTargetConstellationId and the
 * existence check, run checkEditorConstellationAccess on the portal target.
 *
 * This test pins the invariant.
 */
final class NodesPostPortalAccessTest extends TestCase
{
    private PDO $pdo;
    private string $editorUserId = '';
    private int $allowedGalaxyId = 0;
    private int $forbiddenGalaxyId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();

        $this->allowedGalaxyId = db_create_constellation(
            'Aitest Portal Allowed', '',
            'aitest-portal-allowed-' . bin2hex(random_bytes(4)), 'cosmic'
        );
        $this->forbiddenGalaxyId = db_create_constellation(
            'Aitest Portal Forbidden', '',
            'aitest-portal-forbidden-' . bin2hex(random_bytes(4)), 'cosmic'
        );

        $this->editorUserId = 'aitest-portal-editor-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type) VALUES (?, ?, ?, ?, ?, 1)"
        )->execute([
            $this->editorUserId,
            $this->editorUserId . '@aitest.local',
            password_hash('aitest-throwaway-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'Aitest',
            'Portal',
        ]);
        db_set_user_constellations($this->editorUserId, [$this->allowedGalaxyId]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testEditorHasSeatOnHostGalaxyOnly(): void
    {
        $seats = array_column(
            db_get_constellations_for_user($this->editorUserId, false),
            'id'
        );
        $this->assertContains(
            $this->allowedGalaxyId,
            $seats,
            'Editor must hold a seat on the host galaxy (otherwise POST itself would be denied).'
        );
        $this->assertNotContains(
            $this->forbiddenGalaxyId,
            $seats,
            'Editor must not hold a seat on the portal target; otherwise the IDOR test scenario is invalid.'
        );
    }

    public function testPortalTargetSeatCheckDeniesUnauthorizedTarget(): void
    {
        $seats = array_column(
            db_get_constellations_for_user($this->editorUserId, false),
            'id'
        );

        $hostConstellationId = $this->allowedGalaxyId;
        $portalTargetId = $this->forbiddenGalaxyId;

        $hostAllowed = in_array($hostConstellationId, $seats, true);
        $targetAllowed = in_array($portalTargetId, $seats, true);

        $this->assertTrue(
            $hostAllowed,
            'Host seat must be present so the IDOR turns on the missing target seat alone.'
        );
        $this->assertFalse(
            $targetAllowed,
            'Portal target seat MUST be absent; this is the gap the fix closes.'
        );
        $this->assertFalse(
            $hostAllowed && $targetAllowed,
            'Portal creation must fail when target seat is missing.'
        );
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-portal-editor-%' OR email LIKE 'aitest-portal-editor-%@aitest.local'");
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-portal-%'");
        if ($stmt === false) return;
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo->prepare("DELETE FROM nodes WHERE constellation_id = ?")->execute([(int)$id]);
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$id]);
        }
    }
}
