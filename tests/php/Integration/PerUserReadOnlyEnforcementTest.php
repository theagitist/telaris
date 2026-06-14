<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Per-user read-only seat enforcement (editor self-enrollment, task B).
 *
 * Pins the DB-layer choke-point db_user_can_write_constellation() and the
 * supporting helpers db_get_user_constellation_access() and the single-seat
 * upsert db_add_user_constellation() (which must NOT flatten other seats'
 * access levels — that would be a privilege-escalation regression).
 *
 * Runs against the live DB with synthetic 'aitest-puro-' rows and self-cleaning
 * teardown; run this file on its own (never the full integration suite on
 * starmaps, which deletes the live federation peer row).
 */
final class PerUserReadOnlyEnforcementTest extends TestCase
{
    private PDO $pdo;
    private string $editorUserId = '';
    private int $rwGalaxyId = 0;
    private int $roGalaxyId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();

        $this->rwGalaxyId = db_create_constellation('Aitest Puro RW', '', 'aitest-puro-rw-' . bin2hex(random_bytes(4)), 'cosmic');
        $this->roGalaxyId = db_create_constellation('Aitest Puro RO', '', 'aitest-puro-ro-' . bin2hex(random_bytes(4)), 'cosmic');

        $this->editorUserId = 'aitest-puro-editor-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type) VALUES (?, ?, ?, ?, ?, 1)"
        )->execute([
            $this->editorUserId,
            $this->editorUserId . '@aitest.local',
            password_hash('aitest-throwaway-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'Aitest',
            'Puro',
        ]);
        // One read_write seat and one read_only seat.
        db_set_user_constellations($this->editorUserId, [$this->rwGalaxyId], 'read_write');
        db_add_user_constellation($this->editorUserId, $this->roGalaxyId, 'read_only');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testReadWriteSeatCanWrite(): void
    {
        $this->assertTrue(db_user_can_write_constellation($this->editorUserId, $this->rwGalaxyId));
    }

    public function testReadOnlySeatCannotWrite(): void
    {
        $this->assertFalse(db_user_can_write_constellation($this->editorUserId, $this->roGalaxyId));
    }

    public function testNoSeatCannotWrite(): void
    {
        $orphan = db_create_constellation('Aitest Puro None', '', 'aitest-puro-none-' . bin2hex(random_bytes(4)), 'cosmic');
        $this->assertFalse(db_user_can_write_constellation($this->editorUserId, $orphan));
    }

    public function testAdminOrNullContextAlwaysWritable(): void
    {
        // A null user id is the admin / API-key context: this layer does not restrict it.
        $this->assertTrue(db_user_can_write_constellation(null, $this->roGalaxyId));
    }

    public function testAccessMapReflectsSeats(): void
    {
        $map = db_get_user_constellation_access($this->editorUserId);
        $this->assertSame('read_write', $map[$this->rwGalaxyId] ?? null);
        $this->assertSame('read_only', $map[$this->roGalaxyId] ?? null);
    }

    public function testAddSeatPreservesOtherSeatsAccessLevels(): void
    {
        // Regression: adding a new read_write seat must NOT upgrade the existing
        // read_only seat (the whole-set replace bug). Simulate the auto-seat path.
        $newGalaxy = db_create_constellation('Aitest Puro New', '', 'aitest-puro-new-' . bin2hex(random_bytes(4)), 'cosmic');
        db_add_user_constellation($this->editorUserId, $newGalaxy, 'read_write');

        $map = db_get_user_constellation_access($this->editorUserId);
        $this->assertSame('read_only', $map[$this->roGalaxyId] ?? null, 'Existing read_only seat must survive an unrelated seat addition.');
        $this->assertSame('read_write', $map[$this->rwGalaxyId] ?? null);
        $this->assertSame('read_write', $map[$newGalaxy] ?? null);
    }

    public function testAddSeatCanUpdateExistingSeatLevel(): void
    {
        // Re-adding the same galaxy with a different level updates just that seat.
        db_add_user_constellation($this->editorUserId, $this->roGalaxyId, 'read_write');
        $this->assertTrue(db_user_can_write_constellation($this->editorUserId, $this->roGalaxyId));
        // And the other seat is untouched.
        $this->assertTrue(db_user_can_write_constellation($this->editorUserId, $this->rwGalaxyId));
    }

    public function testSetUserConstellationsAppliesOneLevelToWholeSet(): void
    {
        db_set_user_constellations($this->editorUserId, [$this->rwGalaxyId, $this->roGalaxyId], 'read_only');
        $map = db_get_user_constellation_access($this->editorUserId);
        $this->assertSame('read_only', $map[$this->rwGalaxyId] ?? null);
        $this->assertSame('read_only', $map[$this->roGalaxyId] ?? null);
    }

    public function testNonexistentConstellationFailsClosed(): void
    {
        // A constellation id that does not exist (and that the user holds no seat
        // for) must be non-writable. Fail-closed at the choke-point.
        $this->assertFalse(db_user_can_write_constellation($this->editorUserId, 999000111));
    }

    public function testInvalidAccessLevelCoercesToReadWriteDefault(): void
    {
        // The write-enforcement floor: an out-of-range access level must never be
        // persisted as-is (a CHECK-less engine like SQLite would otherwise accept
        // it and db_user_can_write_constellation would treat the unknown value as
        // non-writable, silently locking the seat). The helpers coerce to the
        // ENROLL_ACCESS_DEFAULT (read_write).
        db_set_user_constellations($this->editorUserId, [$this->rwGalaxyId], 'sudo');
        $this->assertSame('read_write', db_get_user_constellation_access($this->editorUserId)[$this->rwGalaxyId] ?? null);

        db_add_user_constellation($this->editorUserId, $this->roGalaxyId, '');
        $this->assertSame('read_write', db_get_user_constellation_access($this->editorUserId)[$this->roGalaxyId] ?? null);
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-puro-editor-%' OR email LIKE 'aitest-puro-editor-%@aitest.local'");
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-puro-%'");
        if ($stmt === false) return;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->pdo->prepare("DELETE FROM nodes WHERE constellation_id = ?")->execute([(int)$id]);
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$id]);
        }
    }
}
