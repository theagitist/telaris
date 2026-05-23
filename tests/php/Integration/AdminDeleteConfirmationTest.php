<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for the M-E2 fix (audit v6.10.10): server-side confirmation
 * phrase enforcement on admin destructive actions.
 *
 * Pre-fix, admin/index.php's delete_user / delete_constellation / delete_cluster
 * handlers called db_delete_*() with no server-side check that the operator
 * typed the entity's name. CSRF stopped external triggers, but a mis-clicked
 * dropdown deleted with no server-side seatbelt.
 *
 * Post-fix, the handlers look up the entity by id, recompute the expected
 * confirmation phrase from the DB row (NOT the request body), and refuse if
 * the operator-typed value doesn't match (case-insensitive trim).
 *
 * This test pins the invariants the handlers depend on.
 */
final class AdminDeleteConfirmationTest extends TestCase
{
    private PDO $pdo;
    private string $userId = '';
    private int $galaxyId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();

        $this->userId = 'aitest-delete-confirm-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type) VALUES (?, ?, ?, ?, ?, 1)"
        )->execute([
            $this->userId,
            $this->userId . '@aitest.local',
            password_hash('aitest-throwaway-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'Aitest',
            'Delete',
        ]);

        $this->galaxyId = db_create_constellation(
            'Aitest Delete Galaxy',
            '',
            'aitest-delete-' . bin2hex(random_bytes(4)),
            'cosmic'
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testUserLookupResolvesEmail(): void
    {
        $row = db_get_user_by_id($this->userId);
        $this->assertNotNull($row, 'db_get_user_by_id MUST return the user row from id (handlers rely on this for the expected confirmation value).');
        $this->assertSame($this->userId . '@aitest.local', $row['email']);
    }

    public function testUserLookupReturnsNullForMissingId(): void
    {
        $row = db_get_user_by_id('aitest-does-not-exist-' . bin2hex(random_bytes(4)));
        $this->assertNull($row, 'db_get_user_by_id MUST return null for a non-existent id; the handler refuses the delete in this case.');
    }

    public function testGalaxyLookupResolvesName(): void
    {
        $row = db_get_constellation_by_id($this->galaxyId);
        $this->assertNotNull($row);
        $this->assertSame('Aitest Delete Galaxy', $row['name']);
    }

    public function testConfirmationPhraseInvariantsForGalaxy(): void
    {
        $row = db_get_constellation_by_id($this->galaxyId);
        $this->assertNotNull($row);
        $expected = (string)$row['name'];

        // The handler's gate is: strcasecmp(trim($provided), $expected) === 0.
        $this->assertSame(
            0,
            strcasecmp(trim('  Aitest Delete Galaxy  '), $expected),
            'Confirmation MUST accept exact match with surrounding whitespace and matching case.'
        );
        $this->assertSame(
            0,
            strcasecmp(trim('aitest delete galaxy'), $expected),
            'Confirmation MUST accept case-folded match.'
        );
        $this->assertNotSame(
            0,
            strcasecmp(trim('not the same'), $expected),
            'Confirmation MUST reject a different name.'
        );
        $this->assertNotSame(
            0,
            strcasecmp(trim(''), $expected),
            'Confirmation MUST reject an empty string.'
        );
        $this->assertNotSame(
            0,
            strcasecmp(trim('Aitest Delete Galax'), $expected),
            'Confirmation MUST reject a prefix.'
        );
    }

    public function testConfirmationPhraseInvariantsForUser(): void
    {
        $row = db_get_user_by_id($this->userId);
        $this->assertNotNull($row);
        $expected = (string)$row['email'];

        $this->assertSame(
            0,
            strcasecmp(trim('  ' . strtoupper($expected) . '  '), $expected),
            'Confirmation MUST accept the user email with surrounding whitespace and case-folded.'
        );
        $this->assertNotSame(0, strcasecmp(trim(''), $expected));
        $this->assertNotSame(0, strcasecmp(trim('not-the-email@example.com'), $expected));
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-delete-confirm-%' OR email LIKE 'aitest-delete-confirm-%@aitest.local'");
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-delete-%'");
        if ($stmt === false) return;
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo->prepare("DELETE FROM nodes WHERE constellation_id = ?")->execute([(int)$id]);
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$id]);
        }
    }
}
