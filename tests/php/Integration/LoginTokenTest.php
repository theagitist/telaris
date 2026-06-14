<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unified login_tokens table (editor self-enrollment, task A/C): create, peek,
 * consume, single-use, expiry, and purpose isolation.
 *
 * Runs against the live DB with a synthetic 'aitest-tok-' user and self-cleaning
 * teardown; run this file on its own (not the full integration suite on starmaps).
 */
final class LoginTokenTest extends TestCase
{
    private PDO $pdo;
    private string $userId = '';

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
        $this->userId = 'aitest-tok-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type, vetted) VALUES (?, ?, ?, ?, ?, 1, 0)"
        )->execute([$this->userId, $this->userId . '@aitest.local', null, 'Tok', 'Test']);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testCreateReturns64HexToken(): void
    {
        $token = db_create_login_token($this->userId, 'magic_login', 900);
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testPeekDoesNotConsume(): void
    {
        $token = db_create_login_token($this->userId, 'magic_login', 900);
        $this->assertNotNull(db_get_user_for_login_token($token, 'magic_login'));
        // Still valid after a peek.
        $this->assertNotNull(db_consume_login_token($token, 'magic_login'));
    }

    public function testConsumeIsSingleUse(): void
    {
        $token = db_create_login_token($this->userId, 'magic_login', 900);
        $first = db_consume_login_token($token, 'magic_login');
        $this->assertNotNull($first);
        $this->assertSame($this->userId, $first['id']);
        $this->assertNull(db_consume_login_token($token, 'magic_login'));
    }

    public function testPurposeIsolation(): void
    {
        $token = db_create_login_token($this->userId, 'magic_login', 900);
        // Wrong purpose neither peeks nor consumes.
        $this->assertNull(db_get_user_for_login_token($token, 'vetting'));
        $this->assertNull(db_consume_login_token($token, 'enroll_confirm'));
        // Correct purpose still works.
        $this->assertNotNull(db_consume_login_token($token, 'magic_login'));
    }

    public function testNewTokenInvalidatesPriorUnusedOfSamePurpose(): void
    {
        $old = db_create_login_token($this->userId, 'magic_login', 900);
        $new = db_create_login_token($this->userId, 'magic_login', 900);
        $this->assertNull(db_consume_login_token($old, 'magic_login'), 'Issuing a fresh token must supersede the prior unused one.');
        $this->assertNotNull(db_consume_login_token($new, 'magic_login'));
    }

    public function testDifferentPurposesCoexist(): void
    {
        $magic = db_create_login_token($this->userId, 'magic_login', 900);
        $vet = db_create_login_token($this->userId, 'vetting', 900);
        // Issuing the vetting token must not invalidate the magic_login token.
        $this->assertNotNull(db_consume_login_token($magic, 'magic_login'));
        $this->assertNotNull(db_consume_login_token($vet, 'vetting'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        // Insert an already-expired token directly (the helper enforces a 60s floor).
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->pdo->prepare(
            "INSERT INTO login_tokens (token_hash, user_id, purpose, expires_at) VALUES (?, ?, 'magic_login', DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE))"
        )->execute([$hash, $this->userId]);
        $this->assertNull(db_get_user_for_login_token($token, 'magic_login'));
        $this->assertNull(db_consume_login_token($token, 'magic_login'));
    }

    public function testInvalidTokenShapeRejected(): void
    {
        $this->assertNull(db_get_user_for_login_token('', 'magic_login'));
        $this->assertNull(db_consume_login_token('too-short', 'magic_login'));
    }

    public function testUnknownPurposeThrowsOnCreate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db_create_login_token($this->userId, 'not_a_purpose', 900);
    }

    private function cleanup(): void
    {
        // login_tokens cascade away with the user.
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-tok-%' OR email LIKE 'aitest-tok-%@aitest.local'");
    }
}
