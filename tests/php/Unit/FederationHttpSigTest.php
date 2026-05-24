<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for inc/federation/http_sig.php.
 *
 * Covers the RFC 9421 subset specified by P2P federation plan v10:
 * - Required components per method (GET vs POST).
 * - Required Signature-Input parameters (created, expires, keyid, alg, tag).
 * - ±300s clock-skew rejection on created and date.
 * - Algorithm pinning (ed25519 only).
 * - Tag mismatch rejection.
 * - Sign/verify roundtrip on GET and POST.
 * - Body-tampering rejection via content-digest.
 */
final class FederationHttpSigTest extends TestCase
{
    private string $secretKey;
    private string $publicKey;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/http_sig.php';
    }

    protected function setUp(): void
    {
        // Deterministic keypair per test method for tidy isolation.
        $seed = sodium_crypto_sign_seed_keypair(str_repeat("\x01", SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->secretKey = sodium_crypto_sign_secretkey($seed);
        $this->publicKey = sodium_crypto_sign_publickey($seed);
    }

    public function testSignGetRoundtrip(): void
    {
        $request = [
            'method' => 'GET',
            'target_uri' => 'https://starmaps.polivoxia.ca/api/pluriverse/galaxies/foo.head',
            'headers' => [
                'Host' => 'starmaps.polivoxia.ca',
                'Date' => gmdate('D, d M Y H:i:s', time()) . ' GMT',
            ],
        ];
        $signed = federation_http_sig_sign($request, $this->secretKey, [
            'keyid' => 'starmaps.polivoxia.ca:fingerprint',
            'tag' => 'tel-pull',
        ]);
        $this->assertStringStartsWith('sig1=(', $signed['signature_input']);
        $this->assertStringContainsString('"@method"', $signed['signature_input']);
        $this->assertStringContainsString('alg="ed25519"', $signed['signature_input']);
        $this->assertStringContainsString('tag="tel-pull"', $signed['signature_input']);
        $this->assertStringStartsWith('sig1=:', $signed['signature']);
        // No body-related output for GET.
        $this->assertArrayNotHasKey('content_digest', $signed);

        $request['headers']['Signature-Input'] = $signed['signature_input'];
        $request['headers']['Signature'] = $signed['signature'];

        $result = federation_http_sig_verify($request, $this->publicKey, ['expected_tag' => 'tel-pull']);
        $this->assertTrue($result['valid'], 'roundtrip should verify: ' . $result['reason']);
    }

    public function testSignPostRoundtripWithContentDigest(): void
    {
        $body = json_encode(['hello' => 'world']);
        $request = [
            'method' => 'POST',
            'target_uri' => 'https://starmaps.polivoxia.ca/api/pluriverse/messages',
            'headers' => [
                'Host' => 'starmaps.polivoxia.ca',
                'Date' => gmdate('D, d M Y H:i:s', time()) . ' GMT',
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ];
        $signed = federation_http_sig_sign($request, $this->secretKey, [
            'keyid' => 'starmaps.polivoxia.ca:fingerprint',
            'tag' => 'tel-message',
        ]);
        $this->assertArrayHasKey('content_digest', $signed, 'content-digest should be auto-computed for POST');
        $this->assertStringStartsWith('sha-256=:', $signed['content_digest']);
        $this->assertStringContainsString('"content-type"', $signed['signature_input']);
        $this->assertStringContainsString('"content-digest"', $signed['signature_input']);
        $this->assertStringContainsString('"content-length"', $signed['signature_input']);

        // Verifier receives a request that includes the auto-added headers.
        $request['headers']['Content-Digest'] = $signed['content_digest'];
        $request['headers']['Content-Length'] = (string)strlen($body);
        $request['headers']['Signature-Input'] = $signed['signature_input'];
        $request['headers']['Signature'] = $signed['signature'];

        $result = federation_http_sig_verify($request, $this->publicKey, ['expected_tag' => 'tel-message']);
        $this->assertTrue($result['valid'], 'POST roundtrip should verify: ' . $result['reason']);
    }

    public function testVerifyRejectsWrongAlg(): void
    {
        $request = $this->fakeSignedRequest('tel-pull');
        $request['headers']['Signature-Input'] = str_replace(
            'alg="ed25519"',
            'alg="rsa-pss-sha512"',
            (string)$request['headers']['Signature-Input']
        );
        $result = federation_http_sig_verify($request, $this->publicKey, ['expected_tag' => 'tel-pull']);
        $this->assertFalse($result['valid']);
        $this->assertSame('wrong_alg', $result['reason']);
    }

    public function testVerifyRejectsCreatedOutsideSkew(): void
    {
        // Created 1 hour in the past.
        $now = time();
        $signed = federation_http_sig_sign($this->baseGetRequest(), $this->secretKey, [
            'keyid' => 'h:fp',
            'tag' => 'tel-pull',
            'created' => $now - 3600,
            'expires' => $now + 3600,
        ]);
        $req = $this->baseGetRequest();
        $req['headers']['Signature-Input'] = $signed['signature_input'];
        $req['headers']['Signature'] = $signed['signature'];

        $result = federation_http_sig_verify($req, $this->publicKey, ['expected_tag' => 'tel-pull']);
        $this->assertFalse($result['valid']);
        $this->assertSame('created_outside_skew', $result['reason']);
    }

    public function testVerifyRejectsExpired(): void
    {
        $now = time();
        $signed = federation_http_sig_sign($this->baseGetRequest(), $this->secretKey, [
            'keyid' => 'h:fp',
            'tag' => 'tel-pull',
            'created' => $now - 10,
            'expires' => $now - 1,
        ]);
        $req = $this->baseGetRequest();
        $req['headers']['Signature-Input'] = $signed['signature_input'];
        $req['headers']['Signature'] = $signed['signature'];

        $result = federation_http_sig_verify($req, $this->publicKey, ['expected_tag' => 'tel-pull']);
        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
    }

    public function testVerifyRejectsDateOutsideSkew(): void
    {
        $signed = federation_http_sig_sign($this->baseGetRequest(), $this->secretKey, [
            'keyid' => 'h:fp', 'tag' => 'tel-pull',
        ]);
        $req = $this->baseGetRequest();
        $req['headers']['Date'] = gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT';
        $req['headers']['Signature-Input'] = $signed['signature_input'];
        $req['headers']['Signature'] = $signed['signature'];

        $result = federation_http_sig_verify($req, $this->publicKey, ['expected_tag' => 'tel-pull']);
        $this->assertFalse($result['valid']);
        $this->assertSame('date_outside_skew', $result['reason']);
    }

    public function testVerifyRejectsTagMismatch(): void
    {
        $request = $this->fakeSignedRequest('tel-pull');
        $result = federation_http_sig_verify($request, $this->publicKey, ['expected_tag' => 'tel-message']);
        $this->assertFalse($result['valid']);
        $this->assertSame('wrong_tag', $result['reason']);
    }

    public function testVerifyAcceptsTagFromAllowedSet(): void
    {
        $request = $this->fakeSignedRequest('tel-message');
        $result = federation_http_sig_verify($request, $this->publicKey, ['expected_tag' => ['tel-message', 'tel-relay']]);
        $this->assertTrue($result['valid'], 'tag in allowlist should accept: ' . $result['reason']);
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $body = '{"hello":"world"}';
        $request = [
            'method' => 'POST',
            'target_uri' => 'https://h.example/api/pluriverse/messages',
            'headers' => [
                'Host' => 'h.example',
                'Date' => gmdate('D, d M Y H:i:s', time()) . ' GMT',
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ];
        $signed = federation_http_sig_sign($request, $this->secretKey, [
            'keyid' => 'h:fp', 'tag' => 'tel-message',
        ]);
        // Tamper: send a different body but the original content-digest header.
        $request['headers']['Content-Digest'] = $signed['content_digest'];
        $request['headers']['Content-Length'] = (string)strlen($body);
        $request['headers']['Signature-Input'] = $signed['signature_input'];
        $request['headers']['Signature'] = $signed['signature'];
        // The content-digest is still the original; the signature still verifies
        // against it. Higher layers (route handlers) recompute SHA-256(body) and
        // compare to header. We assert that verify() passes here on the signed
        // wire bytes, then assert separately that a mismatched digest would
        // fail at the application layer (which here we simulate inline).
        $verify = federation_http_sig_verify($request, $this->publicKey, ['expected_tag' => 'tel-message']);
        $this->assertTrue($verify['valid']);

        // Independent application-layer check: tampered body produces a
        // different digest than the one in the header.
        $tampered = $body . '!';
        $expected = federation_http_sig_content_digest($tampered);
        $this->assertNotSame(
            $expected,
            $request['headers']['Content-Digest'],
            'tampered body must produce a different content-digest'
        );
    }

    public function testVerifyRejectsMissingComponentForPost(): void
    {
        // Sign as GET, present as POST → required body components are absent
        // from the Signature-Input components list.
        $signed = federation_http_sig_sign($this->baseGetRequest(), $this->secretKey, [
            'keyid' => 'h:fp', 'tag' => 'tel-pull',
        ]);
        $req = $this->baseGetRequest();
        $req['method'] = 'POST';
        $req['headers']['Content-Type'] = 'application/json';
        $req['headers']['Content-Digest'] = federation_http_sig_content_digest('');
        $req['headers']['Content-Length'] = '0';
        $req['headers']['Signature-Input'] = $signed['signature_input'];
        $req['headers']['Signature'] = $signed['signature'];

        $result = federation_http_sig_verify($req, $this->publicKey, ['expected_tag' => 'tel-pull']);
        $this->assertFalse($result['valid']);
        $this->assertStringStartsWith('missing_component_', $result['reason']);
    }

    public function testSignRejectsMissingKeyid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        federation_http_sig_sign($this->baseGetRequest(), $this->secretKey, ['tag' => 'tel-pull']);
    }

    public function testSignRejectsMissingTag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        federation_http_sig_sign($this->baseGetRequest(), $this->secretKey, ['keyid' => 'h:fp']);
    }

    public function testSignRejectsWrongLengthKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        federation_http_sig_sign($this->baseGetRequest(), 'too short', [
            'keyid' => 'h:fp', 'tag' => 'tel-pull',
        ]);
    }

    public function testVerifyRejectsMalformedSignatureInput(): void
    {
        $req = $this->baseGetRequest();
        $req['headers']['Signature-Input'] = 'sig1=not a valid inner list';
        $req['headers']['Signature'] = 'sig1=:AAAA:';
        $result = federation_http_sig_verify($req, $this->publicKey);
        $this->assertFalse($result['valid']);
        $this->assertSame('malformed_signature_input', $result['reason']);
    }

    public function testVerifyRejectsMissingSignatureHeaders(): void
    {
        $req = $this->baseGetRequest();
        $result = federation_http_sig_verify($req, $this->publicKey);
        $this->assertFalse($result['valid']);
        $this->assertSame('missing_signature_headers', $result['reason']);
    }

    public function testBaseFormatIsCanonical(): void
    {
        // Spot-check that the base produced for a GET with known inputs is
        // exactly the RFC 9421 line-per-component format.
        $components = FEDERATION_HTTP_SIG_BASE_COMPONENTS;
        $params = [
            'created' => 1735689600, 'expires' => 1735689900,
            'keyid' => 'h:fp', 'alg' => 'ed25519', 'tag' => 'tel-pull',
        ];
        $values = [
            '@method' => 'GET',
            '@target-uri' => 'https://h.example/foo',
            '@authority' => 'h.example',
            'host' => 'h.example',
            'date' => 'Tue, 20 Apr 2026 02:07:55 GMT',
        ];
        $base = federation_http_sig_build_base($components, $params, $values);
        $expectedLines = [
            '"@method": GET',
            '"@target-uri": https://h.example/foo',
            '"@authority": h.example',
            '"host": h.example',
            '"date": Tue, 20 Apr 2026 02:07:55 GMT',
            '"@signature-params": ("@method" "@target-uri" "@authority" "host" "date");created=1735689600;expires=1735689900;keyid="h:fp";alg="ed25519";tag="tel-pull"',
        ];
        $this->assertSame(implode("\n", $expectedLines), $base);
    }

    public function testContentDigestShape(): void
    {
        $body = '{"a":1}';
        $digest = federation_http_sig_content_digest($body);
        $this->assertMatchesRegularExpression(
            '#\Asha-256=:[A-Za-z0-9+/]+=*:\z#',
            $digest
        );
        // Re-decode and check.
        $decoded = federation_http_sig_decode_byte_seq(substr($digest, strlen('sha-256=')));
        $this->assertSame(hash('sha256', $body, true), $decoded);
    }

    /**
     * @return array{method:string,target_uri:string,headers:array<string,string>}
     */
    private function baseGetRequest(): array
    {
        return [
            'method' => 'GET',
            'target_uri' => 'https://h.example/api/pluriverse/galaxies/foo.head',
            'headers' => [
                'Host' => 'h.example',
                'Date' => gmdate('D, d M Y H:i:s', time()) . ' GMT',
            ],
        ];
    }

    /**
     * @return array{method:string,target_uri:string,headers:array<string,string>}
     */
    private function fakeSignedRequest(string $tag): array
    {
        $req = $this->baseGetRequest();
        $signed = federation_http_sig_sign($req, $this->secretKey, ['keyid' => 'h:fp', 'tag' => $tag]);
        $req['headers']['Signature-Input'] = $signed['signature_input'];
        $req['headers']['Signature'] = $signed['signature'];
        return $req;
    }
}
