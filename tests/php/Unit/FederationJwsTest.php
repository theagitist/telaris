<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for inc/federation/jws.php (stage 4c JWS Compact Serialization verifier).
 *
 * Covers the v10 subset: EdDSA only, kid + typ headers, byte-exact payload
 * verification. Payload bound checks. Signing roundtrip for tests that
 * generate signed payloads to verify (libsodium primitives directly; the
 * verifier itself is verify-only at 4c).
 */
final class FederationJwsTest extends TestCase
{
    private string $secret;
    private string $public;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/jws.php';
    }

    protected function setUp(): void
    {
        $seed = sodium_crypto_sign_seed_keypair(str_repeat("\x40", SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->secret = sodium_crypto_sign_secretkey($seed);
        $this->public = sodium_crypto_sign_publickey($seed);
    }

    private function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function sign(array $header, array $payload): string
    {
        $headerB64 = $this->b64u(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = $this->b64u(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = sodium_crypto_sign_detached($headerB64 . '.' . $payloadB64, $this->secret);
        return $headerB64 . '.' . $payloadB64 . '.' . $this->b64u($signature);
    }

    public function testRoundtripHappyPath(): void
    {
        $jws = $this->sign(
            ['alg' => 'EdDSA', 'kid' => 'www.telaris.ca:fp', 'typ' => 'application/test+json'],
            ['event_type' => 'demo', 'value' => 42],
        );
        $r = federation_jws_verify($jws, $this->public, 'application/test+json');
        $this->assertTrue($r['valid'], 'expected valid: ' . ($r['reason'] ?? ''));
        $this->assertSame('demo', $r['payload']['event_type']);
        $this->assertSame(42, $r['payload']['value']);
    }

    public function testWrongAlgRejected(): void
    {
        $jws = $this->sign(
            ['alg' => 'RS256', 'kid' => 'w.t.ca:fp', 'typ' => 'application/test+json'],
            ['x' => 1],
        );
        $r = federation_jws_verify($jws, $this->public, 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('wrong_alg', $r['reason']);
    }

    public function testWrongTypRejected(): void
    {
        $jws = $this->sign(
            ['alg' => 'EdDSA', 'kid' => 'w.t.ca:fp', 'typ' => 'application/wrong+json'],
            ['x' => 1],
        );
        $r = federation_jws_verify($jws, $this->public, 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('wrong_typ', $r['reason']);
    }

    public function testTamperedPayloadRejected(): void
    {
        $jws = $this->sign(
            ['alg' => 'EdDSA', 'kid' => 'w.t.ca:fp', 'typ' => 'application/test+json'],
            ['x' => 1],
        );
        [$h, $p, $s] = explode('.', $jws);
        $tamperedPayload = $this->b64u(json_encode(['x' => 2]));
        $tampered = $h . '.' . $tamperedPayload . '.' . $s;
        $r = federation_jws_verify($tampered, $this->public, 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('signature_invalid', $r['reason']);
    }

    public function testMalformedCompactRejected(): void
    {
        $r = federation_jws_verify('only.one.dot.too.many', $this->public, 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('malformed_compact_serialization', $r['reason']);
    }

    public function testMalformedBase64urlRejected(): void
    {
        $r = federation_jws_verify('!!!.!!!.!!!', $this->public, 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('malformed_base64url', $r['reason']);
    }

    public function testWrongSignatureLengthRejected(): void
    {
        $headerB64 = $this->b64u(json_encode(['alg' => 'EdDSA', 'kid' => 'w', 'typ' => 'application/test+json']));
        $payloadB64 = $this->b64u(json_encode(['x' => 1]));
        $jws = $headerB64 . '.' . $payloadB64 . '.' . $this->b64u(str_repeat("\x00", 16));
        $r = federation_jws_verify($jws, $this->public, 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('signature_wrong_length', $r['reason']);
    }

    public function testEmptyExpectedTypSkipsTypCheck(): void
    {
        $jws = $this->sign(
            ['alg' => 'EdDSA', 'kid' => 'w', 'typ' => 'whatever'],
            ['x' => 1],
        );
        $r = federation_jws_verify($jws, $this->public, '');
        $this->assertTrue($r['valid']);
    }

    public function testKidSplit(): void
    {
        $kid = federation_jws_split_kid('starmaps.polivoxia.ca:abc123');
        $this->assertSame('starmaps.polivoxia.ca', $kid['host']);
        $this->assertSame('abc123', $kid['fingerprint']);

        $bad = federation_jws_split_kid('no-colon');
        $this->assertNull($bad);

        $emptyHost = federation_jws_split_kid(':fp');
        $this->assertNull($emptyHost);

        $emptyFp = federation_jws_split_kid('host:');
        $this->assertNull($emptyFp);
    }

    public function testPayloadTooLargeRejected(): void
    {
        $bigPayloadB64 = $this->b64u(str_repeat('x', FEDERATION_JWS_MAX_PAYLOAD_BYTES));
        $headerB64 = $this->b64u(json_encode(['alg' => 'EdDSA', 'kid' => 'w', 'typ' => 'application/test+json']));
        $jws = $headerB64 . '.' . $bigPayloadB64 . 'extra.' . $this->b64u(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES));
        $r = federation_jws_verify($jws, $this->public, 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('payload_too_large', $r['reason']);
    }

    public function testPublicKeyWrongLengthRejected(): void
    {
        $r = federation_jws_verify('a.b.c', 'too-short', 'application/test+json');
        $this->assertFalse($r['valid']);
        $this->assertSame('public_key_wrong_length', $r['reason']);
    }
}
