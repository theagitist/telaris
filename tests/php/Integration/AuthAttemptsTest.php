<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the auth_attempts throttling helpers (Fix 6).
 *
 * Pre-fix the auth surfaces (login, forgot, reset) accepted unlimited
 * attempts. The auth_attempts table records each try (action + email + IP
 * + success); db_count_recent_auth_attempts feeds the gate at the entry
 * points. These tests verify:
 *   - The table is created idempotently by db_ensure_auth_attempts_table.
 *   - Records insert with the expected shape (action, email, ip, success).
 *   - Counting honours the window, action, email, ip, and success filter.
 *   - Counting can run with any of the axes nulled out.
 */
final class AuthAttemptsTest extends TestCase
{
    private PDO $pdo;
    private string $ip = '';
    private string $email = '';
    private string $altEmail = '';

    protected function setUp(): void
    {
        $this->pdo = getDB();
        db_ensure_auth_attempts_table();
        $this->ip = '203.0.113.' . random_int(1, 250);
        $suffix = bin2hex(random_bytes(4));
        $this->email = "aitest-throttle-{$suffix}@aitest.local";
        $this->altEmail = "aitest-throttle-alt-{$suffix}@aitest.local";
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testEnsureIsIdempotent(): void
    {
        db_ensure_auth_attempts_table();
        db_ensure_auth_attempts_table();
        $found = $this->pdo->query("SELECT to_regclass('public.auth_attempts')")->fetchColumn();
        $this->assertNotNull($found);
    }

    public function testRecordInsertsRow(): void
    {
        db_record_auth_attempt('login', $this->email, $this->ip, false);
        $stmt = $this->pdo->prepare("SELECT action, email, ip, success FROM auth_attempts WHERE ip = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('login', $row['action']);
        $this->assertSame($this->email, $row['email']);
        $this->assertSame($this->ip, $row['ip']);
        $this->assertSame(0, (int)$row['success']);
    }

    public function testCountFiltersByActionAndSuccess(): void
    {
        db_record_auth_attempt('login', $this->email, $this->ip, false);
        db_record_auth_attempt('login', $this->email, $this->ip, false);
        db_record_auth_attempt('login', $this->email, $this->ip, true);
        db_record_auth_attempt('forgot', $this->email, $this->ip, false);

        $failedLogins = db_count_recent_auth_attempts('login', $this->email, $this->ip, 300, false);
        $this->assertSame(2, $failedLogins);

        $allLogins = db_count_recent_auth_attempts('login', $this->email, $this->ip, 300, null);
        $this->assertSame(3, $allLogins);

        $forgots = db_count_recent_auth_attempts('forgot', $this->email, $this->ip, 300, null);
        $this->assertSame(1, $forgots);
    }

    public function testCountIsIpScopedWhenEmailNulled(): void
    {
        db_record_auth_attempt('login', $this->email,    $this->ip, false);
        db_record_auth_attempt('login', $this->altEmail, $this->ip, false);
        $byIpAnyEmail = db_count_recent_auth_attempts('login', null, $this->ip, 300, false);
        $this->assertSame(2, $byIpAnyEmail);
    }

    public function testCountIsEmailScopedWhenIpNulled(): void
    {
        $otherIp = '203.0.113.99';
        db_record_auth_attempt('login', $this->email, $this->ip,    false);
        db_record_auth_attempt('login', $this->email, $otherIp,     false);
        $byEmailAnyIp = db_count_recent_auth_attempts('login', $this->email, null, 300, false);
        $this->assertSame(2, $byEmailAnyIp);
    }

    public function testCountIgnoresOldRows(): void
    {
        db_record_auth_attempt('login', $this->email, $this->ip, false);
        // Backdate the row beyond the test window.
        $this->pdo->prepare("UPDATE auth_attempts SET created_at = (NOW() - INTERVAL '10 MINUTE') WHERE email = ?")
            ->execute([$this->email]);
        $recent = db_count_recent_auth_attempts('login', $this->email, $this->ip, 60, false);
        $this->assertSame(0, $recent);
    }

    private function cleanup(): void
    {
        $this->pdo->prepare("DELETE FROM auth_attempts WHERE email IN (?, ?) OR ip = ?")
            ->execute([$this->email, $this->altEmail, $this->ip]);
        $this->pdo->prepare("DELETE FROM auth_attempts WHERE email LIKE 'aitest-throttle-%@aitest.local'")
            ->execute();
    }
}
