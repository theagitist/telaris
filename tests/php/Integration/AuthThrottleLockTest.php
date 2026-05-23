<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for the M-C1 fix (audit v6.10.11): MySQL named-lock
 * around the auth-throttle gate to close the count → record TOCTOU.
 *
 * Pre-fix, N parallel requests from the same IP could each read
 * `count = THRESHOLD - 1` from db_count_recent_auth_attempts and each
 * proceed to db_record_auth_attempt, leaving N - 1 extra rows above
 * the cap.
 *
 * Post-fix, db_auth_throttle_lock_acquire takes a per-(action, IP)
 * MySQL GET_LOCK that the gate holds through count + bcrypt + record.
 * Different IPs do not serialize on each other.
 *
 * These tests pin the invariants the helpers depend on. They cover the
 * single-connection nesting case (same conn can re-acquire), basic
 * acquire/release symmetry, and that release on a non-acquired lock is
 * a no-op (so callers can wrap try/finally without sentinels).
 */
final class AuthThrottleLockTest extends TestCase
{
    public function testAcquireReleaseSymmetry(): void
    {
        $ip = '198.51.100.' . random_int(1, 254);
        $lock = db_auth_throttle_lock_acquire('aitest_login_' . bin2hex(random_bytes(2)), $ip);
        $this->assertTrue($lock['acquired'], 'GET_LOCK on a free key MUST succeed.');
        $this->assertNotEmpty($lock['key']);
        $this->assertStringContainsString($ip, $lock['key'], 'Lock key MUST include the IP so per-IP scoping holds.');
        db_auth_throttle_lock_release($lock);
        $this->addToAssertionCount(1);
    }

    public function testReleaseOfUnacquiredLockIsNoop(): void
    {
        // The try/finally pattern at every gate calls release() on the
        // outcome of acquire() regardless of whether it succeeded. The
        // helper MUST tolerate ['acquired' => false].
        db_auth_throttle_lock_release(['acquired' => false]);
        db_auth_throttle_lock_release(['acquired' => false, 'key' => 'arbitrary']);
        $this->addToAssertionCount(1);
    }

    public function testReacquireOnSameConnectionNests(): void
    {
        // MySQL GET_LOCK allows the same connection to acquire the same
        // lock multiple times (nests). The gate doesn't rely on this, but
        // a buggy refactor that double-acquires shouldn't dead-lock the
        // request. Verify the second acquire returns 1 (acquired).
        $action = 'aitest_login_' . bin2hex(random_bytes(2));
        $ip = '198.51.100.' . random_int(1, 254);
        $lock1 = db_auth_throttle_lock_acquire($action, $ip);
        $lock2 = db_auth_throttle_lock_acquire($action, $ip);
        $this->assertTrue($lock1['acquired']);
        $this->assertTrue($lock2['acquired'], 'Same-connection re-acquire of GET_LOCK MUST nest.');
        db_auth_throttle_lock_release($lock2);
        db_auth_throttle_lock_release($lock1);
        $this->addToAssertionCount(1);
    }

    public function testActionKeyIsolatesGates(): void
    {
        $ip = '198.51.100.' . random_int(1, 254);
        $actionA = 'aitest_login_' . bin2hex(random_bytes(2));
        $actionB = 'aitest_forgot_' . bin2hex(random_bytes(2));
        $lockA = db_auth_throttle_lock_acquire($actionA, $ip);
        $lockB = db_auth_throttle_lock_acquire($actionB, $ip);
        $this->assertTrue($lockA['acquired']);
        $this->assertTrue(
            $lockB['acquired'],
            'Different actions on the same IP MUST get distinct locks (login vs forgot vs reset vs api_key do not serialize on each other).'
        );
        $this->assertNotSame($lockA['key'], $lockB['key']);
        db_auth_throttle_lock_release($lockB);
        db_auth_throttle_lock_release($lockA);
    }

    public function testIpAxisIsolatesGates(): void
    {
        $action = 'aitest_login_' . bin2hex(random_bytes(2));
        $lock1 = db_auth_throttle_lock_acquire($action, '198.51.100.1');
        $lock2 = db_auth_throttle_lock_acquire($action, '198.51.100.2');
        $this->assertTrue($lock1['acquired']);
        $this->assertTrue(
            $lock2['acquired'],
            'Different IPs on the same action MUST get distinct locks (attacks from one IP do not serialize honest users elsewhere).'
        );
        $this->assertNotSame($lock1['key'], $lock2['key']);
        db_auth_throttle_lock_release($lock2);
        db_auth_throttle_lock_release($lock1);
    }
}
