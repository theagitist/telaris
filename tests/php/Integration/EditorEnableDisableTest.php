<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/db.php';

/**
 * Cascading editor enable/disable (Installation > Cluster > Galaxy > User).
 * Exercises the cascade helpers directly. Synthetic rows, self-cleaning,
 * restores the installation-wide flag. Run on its own.
 */
final class EditorEnableDisableTest extends TestCase
{
    private PDO $pdo;
    private int $galaxyId = 0;
    private int $clusterId = 0;
    private string $userId = '';
    private ?string $origInstallFlag = null;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->origInstallFlag = db_system_meta_get('editors_enabled');
        db_set_installation_editors_enabled(true);

        $this->galaxyId = db_create_constellation('aitest-ee-galaxy', '', 'aitest-ee-galaxy', 'abstract', null);
        $this->clusterId = db_create_cluster('aitest-ee-cluster', '', 'aitest-ee-cluster', 'cosmic', [], false, 'inherit');
        db_add_cluster_member($this->clusterId, $this->galaxyId);

        $this->userId = 'aitest-ee-' . bin2hex(random_bytes(4));
        createUser($this->pdo, $this->userId . '@aitest.local', null, 'EE', null, USER_TYPE_EDITOR, null, false);
        $row = db_get_user_by_email($this->userId . '@aitest.local');
        $this->userId = (string)$row['id'];
    }

    protected function tearDown(): void
    {
        if ($this->galaxyId > 0) $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([$this->galaxyId]);
        if ($this->clusterId > 0) $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([$this->clusterId]);
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'aitest-ee-%@aitest.local'");
        // Restore the installation-wide flag exactly as it was.
        if ($this->origInstallFlag === null) {
            $this->pdo->prepare("DELETE FROM system_meta WHERE meta_key = 'editors_enabled'")->execute();
        } else {
            db_system_meta_set('editors_enabled', $this->origInstallFlag);
        }
    }

    public function testDefaultsEnabledEverywhere(): void
    {
        $this->assertTrue(db_installation_editors_enabled());
        $this->assertTrue(db_constellation_editors_enabled($this->galaxyId));
        $this->assertTrue(db_constellation_editors_enabled($this->clusterId));
        $this->assertTrue(db_user_editor_enabled($this->userId));
        $this->assertNull(db_editors_blocked_level_for_galaxy($this->galaxyId));
        $this->assertTrue(db_editor_login_allowed($this->userId));
    }

    public function testInstallationOffCascadesToEverything(): void
    {
        db_set_installation_editors_enabled(false);
        $this->assertSame('installation', db_editors_blocked_level_for_galaxy($this->galaxyId));
        $this->assertFalse(db_editor_login_allowed($this->userId), 'installation off blocks login too');
    }

    public function testClusterOffBlocksItsMemberGalaxy(): void
    {
        db_set_constellation_editors_enabled($this->clusterId, false);
        $this->assertSame('cluster', db_editors_blocked_level_for_galaxy($this->galaxyId));
        // Login is the installation+user axis, so a cluster being off does not block login.
        $this->assertTrue(db_editor_login_allowed($this->userId));
    }

    public function testGalaxyOffBlocksOnlyThatGalaxy(): void
    {
        db_set_constellation_editors_enabled($this->galaxyId, false);
        $this->assertSame('galaxy', db_editors_blocked_level_for_galaxy($this->galaxyId));
    }

    public function testUserOffBlocksLoginButIsNotAGalaxyLevel(): void
    {
        db_set_user_editor_enabled($this->userId, false);
        $this->assertFalse(db_editor_login_allowed($this->userId));
        // The user axis is not part of the galaxy-level cascade.
        $this->assertNull(db_editors_blocked_level_for_galaxy($this->galaxyId));
    }

    public function testInstallationTakesPrecedenceOverGalaxy(): void
    {
        db_set_installation_editors_enabled(false);
        db_set_constellation_editors_enabled($this->galaxyId, false);
        $this->assertSame('installation', db_editors_blocked_level_for_galaxy($this->galaxyId), 'most-restrictive top level reported first');
    }

    public function testReEnableRestoresAccess(): void
    {
        db_set_installation_editors_enabled(false);
        db_set_installation_editors_enabled(true);
        db_set_constellation_editors_enabled($this->clusterId, false);
        db_set_constellation_editors_enabled($this->clusterId, true);
        db_set_user_editor_enabled($this->userId, false);
        db_set_user_editor_enabled($this->userId, true);
        $this->assertNull(db_editors_blocked_level_for_galaxy($this->galaxyId));
        $this->assertTrue(db_editor_login_allowed($this->userId));
    }
}
