<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Vetting a self-enrolled editor (task E): the DB building blocks the admin
 * update_user handler composes. Vetting sets the flag, issues a single-use
 * 'vetting' login token and an in-app banner flag, and never changes seats; the
 * vetting token then sets the user's first password (the utils/reset.php path).
 *
 * Synthetic 'aitest-vet-' rows, self-cleaning teardown; run on its own.
 */
final class VettingTest extends TestCase
{
    private PDO $pdo;
    private string $userId = '';
    private int $galaxyId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
        $this->userId = 'aitest-vet-' . bin2hex(random_bytes(4));
        // Unvetted, password-less editor with one read_only seat.
        $this->pdo->prepare("INSERT INTO users (id,email,password,firstname,lastname,type,vetted) VALUES (?,?,?,?,?,1,0)")
            ->execute([$this->userId, $this->userId . '@aitest.local', null, 'Vet', 'Test']);
        $this->galaxyId = db_create_constellation('aitest-vet-gx-' . bin2hex(random_bytes(3)), '', null, 'cosmic');
        db_set_user_constellations($this->userId, [$this->galaxyId], 'read_only');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testVettingSetsFlagAndIssuesTokenAndBanner(): void
    {
        // Simulate the admin handler's vetting transition (0 -> 1).
        db_set_user_vetted($this->userId, true);
        $token = db_create_login_token($this->userId, 'vetting', 7 * 86400);
        db_set_vetted_banner_pending($this->userId);

        $row = db_get_user_by_id($this->userId);
        $this->assertSame(1, (int)$row['vetted'], 'user is now vetted');

        // The banner is read-once.
        $this->assertTrue(db_take_vetted_banner_pending($this->userId));
        $this->assertFalse(db_take_vetted_banner_pending($this->userId));

        // The vetting token sets the password (utils/reset.php vetting path).
        $consumed = db_consume_login_token($token, 'vetting');
        $this->assertNotNull($consumed);
        db_update_user_password((string)$consumed['id'], password_hash('a-real-password', PASSWORD_DEFAULT));
        $pw = $this->pdo->query("SELECT password FROM users WHERE id=" . $this->pdo->quote($this->userId))->fetchColumn();
        $this->assertNotNull($pw);
        $this->assertTrue(password_verify('a-real-password', (string)$pw));
    }

    public function testVettingDoesNotChangeSeats(): void
    {
        $before = db_get_user_constellation_access($this->userId);
        db_set_user_vetted($this->userId, true);
        $after = db_get_user_constellation_access($this->userId);
        $this->assertSame($before, $after, 'vetting must not touch seats');
        $this->assertSame('read_only', $after[$this->galaxyId] ?? null);
    }

    public function testVettingTokenIsSingleUse(): void
    {
        $token = db_create_login_token($this->userId, 'vetting', 7 * 86400);
        $this->assertNotNull(db_consume_login_token($token, 'vetting'));
        $this->assertNull(db_consume_login_token($token, 'vetting'));
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-vet-%' OR email LIKE 'aitest-vet-%@aitest.local'");
        $this->pdo->exec("DELETE FROM constellations WHERE name LIKE 'aitest-vet-gx-%' OR slug LIKE 'aitest-vet-gx-%'");
    }
}
