<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Stale-account GC (task G): db_gc_unconfirmed_enrollments reclaims only editor
 * accounts that were never confirmed and never signed in and are older than N
 * days. It must never touch vetted users, password-bearing users, users who have
 * logged in, or recent enrolments. Also exercises db_gc_login_tokens.
 *
 * Synthetic 'aitest-gc-' rows, self-cleaning teardown; run this file on its own
 * (not the full integration suite on starmaps).
 */
final class GcUnconfirmedEnrollmentsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    /** Insert a user with explicit attributes; returns its id. */
    private function mkUser(string $tag, ?string $password, int $vetted, ?string $lastLogin, string $created): string
    {
        $id = 'aitest-gc-' . $tag . '-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type, vetted, date_created, date_last_login)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)"
        )->execute([$id, $id . '@aitest.local', $password, 'Gc', 'Test', $vetted, $created, $lastLogin]);
        return $id;
    }

    private function exists(string $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    public function testReclaimsOldUnconfirmedOnly(): void
    {
        $old = date('Y-m-d H:i:s', time() - 40 * 86400);
        $recent = date('Y-m-d H:i:s', time() - 2 * 86400);

        $target      = $this->mkUser('target', null, 0, null, $old);          // old, unconfirmed -> deleted
        $recentUser  = $this->mkUser('recent', null, 0, null, $recent);       // too recent -> kept
        $vetted      = $this->mkUser('vetted', null, 1, null, $old);          // vetted -> kept
        $withPw      = $this->mkUser('withpw', 'x', 0, null, $old);           // has password -> kept
        $loggedIn    = $this->mkUser('loggedin', null, 0, $old, $old);        // logged in -> kept

        $removed = db_gc_unconfirmed_enrollments(30);

        $this->assertGreaterThanOrEqual(1, $removed, 'at least the target row was removed');
        $this->assertFalse($this->exists($target), 'old unconfirmed enrolment is reclaimed');
        $this->assertTrue($this->exists($recentUser), 'recent enrolment is kept');
        $this->assertTrue($this->exists($vetted), 'vetted user is never touched');
        $this->assertTrue($this->exists($withPw), 'password-bearing user is never touched');
        $this->assertTrue($this->exists($loggedIn), 'user who has logged in is never touched');
    }

    public function testCascadeClearsTokensAndSeats(): void
    {
        $old = date('Y-m-d H:i:s', time() - 40 * 86400);
        $target = $this->mkUser('cascade', null, 0, null, $old);
        $gx = db_create_constellation('aitest-gc-gx-' . bin2hex(random_bytes(3)), '', null, 'cosmic');
        db_set_user_constellations($target, [$gx], 'read_only');
        $token = db_create_login_token($target, 'enroll_confirm', 24 * 3600);

        db_gc_unconfirmed_enrollments(30);

        $this->assertFalse($this->exists($target));
        $seat = $this->pdo->prepare("SELECT 1 FROM user_constellations WHERE user_id = ?");
        $seat->execute([$target]);
        $this->assertFalse($seat->fetchColumn(), 'seats cascade-deleted with the user');
        $this->assertNull(db_consume_login_token($token, 'enroll_confirm'), 'token cascade-deleted with the user');

        $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([$gx]);
    }

    public function testGcLoginTokensRemovesExpiredAndUsed(): void
    {
        $user = $this->mkUser('tok', null, 0, null, date('Y-m-d H:i:s'));

        // An expired token and a consumed token (both magic_login), plus a live
        // token under a different purpose so the single-active-token-per-purpose
        // rule in db_create_login_token does not invalidate it.
        $expired = db_create_login_token($user, 'magic_login', 3600);
        $this->pdo->prepare("UPDATE login_tokens SET expires_at = ? WHERE token_hash = ?")
            ->execute([date('Y-m-d H:i:s', time() - 3600), hash('sha256', $expired)]);
        $consumed = db_create_login_token($user, 'enroll_confirm', 3600);
        db_consume_login_token($consumed, 'enroll_confirm');
        $live = db_create_login_token($user, 'vetting', 3600);

        $removed = db_gc_login_tokens();
        $this->assertGreaterThanOrEqual(2, $removed, 'expired and consumed tokens removed');

        // The live token still validates.
        $this->assertNotNull(db_get_user_for_login_token($live, 'vetting'), 'live token survives gc');
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-gc-%' OR email LIKE 'aitest-gc-%@aitest.local'");
        $this->pdo->exec("DELETE FROM constellations WHERE name LIKE 'aitest-gc-gx-%' OR slug LIKE 'aitest-gc-gx-%'");
    }
}
