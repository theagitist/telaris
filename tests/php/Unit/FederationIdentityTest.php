<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for inc/federation/identity.php.
 *
 * Pure-function tests (federation_derive_public_key, federation_compute_fingerprint)
 * use libsodium's deterministic seeded-keypair API so we can pin known vectors.
 * The file-backed accessors are covered by a temp-file round-trip that
 * overrides FEDERATION_SECRET_KEY_PATH via the runtime define.
 */
final class FederationIdentityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Override the key path before the file is required so the production
        // path under <site-root>/secrets/ never gets touched by this test run.
        if (!defined('FEDERATION_SECRET_KEY_PATH')) {
            $tmp = sys_get_temp_dir() . '/federation_identity_test_' . bin2hex(random_bytes(8)) . '.key';
            define('FEDERATION_SECRET_KEY_PATH', $tmp);
        }
        require_once dirname(__DIR__, 3) . '/inc/federation/identity.php';
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(FEDERATION_SECRET_KEY_PATH);
    }

    public function testDerivePublicKeyMatchesSeededKeypair(): void
    {
        // Deterministic keypair: same seed in, same keypair out.
        $seed = str_repeat("\x42", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $expectedSecret = sodium_crypto_sign_secretkey($keypair);
        $expectedPublic = sodium_crypto_sign_publickey($keypair);

        $derived = federation_derive_public_key($expectedSecret);
        $this->assertSame($expectedPublic, $derived);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($derived));
    }

    public function testDerivePublicKeyRejectsWrongLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        federation_derive_public_key('too short');
    }

    public function testFingerprintIsBase64UrlOfFirst16BytesOfSha256(): void
    {
        $publicKey = str_repeat("\x11", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $fingerprint = federation_compute_fingerprint($publicKey);

        // Compute expected independently.
        $hash = hash('sha256', $publicKey, true);
        $expected = rtrim(strtr(base64_encode(substr($hash, 0, 16)), '+/', '-_'), '=');

        $this->assertSame($expected, $fingerprint);
        // base64url(16 bytes) = 22 chars unpadded.
        $this->assertSame(22, strlen($fingerprint));
        // Only base64url alphabet, no padding.
        $this->assertMatchesRegularExpression('#\A[A-Za-z0-9_-]{22}\z#', $fingerprint);
    }

    public function testFingerprintRejectsWrongLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        federation_compute_fingerprint('short');
    }

    public function testFingerprintIsStableAcrossInvocations(): void
    {
        $publicKey = random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $a = federation_compute_fingerprint($publicKey);
        $b = federation_compute_fingerprint($publicKey);
        $this->assertSame($a, $b);
    }

    public function testFileBackedAccessorsRoundTrip(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $expectedPub = sodium_crypto_sign_publickey($keypair);

        $path = federation_secret_key_path();
        file_put_contents($path, $secret);
        chmod($path, 0600);

        // Reset the static cache by exercising a fresh process scope isn't
        // possible inside one test; instead verify load + derive return the
        // expected bytes when the cache is empty (this test runs first when
        // FILE-BACKED tests are ordered alphabetically; for safety, force a
        // re-read via the load helper rather than the cached public_key()).
        $loaded = federation_load_secret_key();
        $this->assertSame($secret, $loaded);

        $derived = federation_derive_public_key($loaded);
        $this->assertSame($expectedPub, $derived);

        $fingerprintFromCompute = federation_compute_fingerprint($derived);
        $this->assertSame(22, strlen($fingerprintFromCompute));
    }

    public function testKeyidFormat(): void
    {
        // Reuse the file from the round-trip test (or write one).
        $path = federation_secret_key_path();
        if (!file_exists($path)) {
            $keypair = sodium_crypto_sign_keypair();
            file_put_contents($path, sodium_crypto_sign_secretkey($keypair));
            chmod($path, 0600);
        }
        $kid = federation_keyid('starmaps.polivoxia.ca');
        $this->assertMatchesRegularExpression(
            '#\Astarmaps\.polivoxia\.ca:[A-Za-z0-9_-]{22}\z#',
            $kid,
            'keyid must be <host>:<22-char-base64url-fingerprint>'
        );
    }

    public function testLoadSecretKeyThrowsWhenMissing(): void
    {
        $path = federation_secret_key_path();
        @unlink($path);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing; run bin\/init-identity/');
        federation_load_secret_key();
    }

    public function testLoadSecretKeyThrowsOnWrongLength(): void
    {
        $path = federation_secret_key_path();
        file_put_contents($path, "garbage");
        chmod($path, 0600);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wrong length/');
        federation_load_secret_key();
    }
}
