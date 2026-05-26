<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for federation_sensitive_info_scan().
 *
 * The scanner is conservative by design: high-confidence patterns only,
 * because false-positives waste operator time and the override path
 * exists for the cases the scanner can't classify cleanly.
 *
 * Spec: P2P federation plan v10 § In-app messaging → UX rules.
 */
final class SensitiveInfoScanTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // sensitive_info.php only depends on the constant array + preg_match;
        // we can load it standalone for the unit test. db_ensure_* is only
        // touched by the log-override helper which we do not exercise here.
        require_once dirname(__DIR__, 3) . '/inc/federation/sensitive_info.php';
    }

    public function testCleanBodyReturnsEmpty(): void
    {
        $hits = federation_sensitive_info_scan("Hello, we'd like to federate galaxy A with you. Cheers.");
        $this->assertSame([], $hits);
    }

    public function testPrivateKeyPemDetected(): void
    {
        $body = "Here's our key:\n-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEAxxxx\n-----END RSA PRIVATE KEY-----";
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('private_key_pem', $hits);
    }

    public function testOpensshPrivateKeyDetected(): void
    {
        $body = "-----BEGIN OPENSSH PRIVATE KEY-----\nstuff\n-----END OPENSSH PRIVATE KEY-----";
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('ssh_private_key', $hits);
    }

    public function testAwsAccessKeyDetected(): void
    {
        $body = 'Our deploy uses AKIAIOSFODNN7EXAMPLE for S3 access.';
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('aws_access_key', $hits);
    }

    public function testJwtShapeDetected(): void
    {
        $body = 'Token: eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NSJ9.cThIIoDvwdueQB468K5xDc5633seEFoqwxjF_xSJyQQ';
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('jwt_shape', $hits);
    }

    public function testPasswordAssignmentDetected(): void
    {
        $body = 'connection string: password=hunter2 host=db.example';
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('password_assignment', $hits);
    }

    public function testApiKeyAssignmentDetected(): void
    {
        $body = 'api_key=sk_live_abcdef1234567890XYZ';
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('api_key_assignment', $hits);
    }

    public function testGithubTokenDetected(): void
    {
        $body = 'Use this token to push: ghp_ABCDEFghijklmnopqrstuvwxyz0123456789';
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('github_token', $hits);
    }

    public function testDiscussionOfPasswordWordIsNotFlagged(): void
    {
        $body = 'The password reset flow lives in utils/reset.php; the operator runs it.';
        $hits = federation_sensitive_info_scan($body);
        $this->assertSame([], $hits, 'plain prose about passwords should not trip the scanner');
    }

    public function testMultiplePatternsAllReported(): void
    {
        $body = "key:\n-----BEGIN PRIVATE KEY-----\nfoo\n-----END PRIVATE KEY-----\nAlso AKIAIOSFODNN7EXAMPLE";
        $hits = federation_sensitive_info_scan($body);
        $this->assertContains('private_key_pem', $hits);
        $this->assertContains('aws_access_key', $hits);
    }
}
