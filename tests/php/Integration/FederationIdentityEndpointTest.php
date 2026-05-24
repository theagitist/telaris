<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: GET /api/pluriverse/identity returns the documented
 * JSON envelope shape.
 *
 * Hits the live SAPI on this VPS (default https://starmaps.polivoxia.ca).
 * Skips with a clear message if the host is unreachable or has not been
 * provisioned with a federation identity yet (503 identity_unavailable).
 *
 * Spec: P2P federation plan v10 § Pluriverse protocol → Instance-side
 *       endpoint catalogue (line 457).
 */
final class FederationIdentityEndpointTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string)(getenv('TELARIS_SMOKE_BASE_URL') ?: 'https://starmaps.polivoxia.ca'), '/');
    }

    public function testIdentityEndpointReturnsExpectedShape(): void
    {
        $result = $this->fetchJson('/api/pluriverse/identity');

        if ($result['code'] === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} unreachable; skipping");
        }
        if ($result['code'] === 503) {
            $this->markTestSkipped(
                'instance has not been provisioned with a federation identity yet '
                . '(run bin/init-identity); skipping endpoint shape test'
            );
        }

        $this->assertSame(200, $result['code'], "expected 200 OK; got {$result['code']}");
        $this->assertStringContainsString(
            'application/json',
            $result['contentType'],
            'expected Content-Type application/json'
        );

        $body = json_decode($result['body'], true);
        $this->assertIsArray($body, 'response body is not valid JSON');

        // Required fields per v10 plan + active-memory spec.
        foreach (['hostname', 'label', 'telaris_version', 'protocol_version', 'public_key', 'public_key_fingerprint', 'pluriverse_endpoint'] as $field) {
            $this->assertArrayHasKey($field, $body, "missing field: {$field}");
            $this->assertIsString($body[$field], "field {$field} must be a string");
            $this->assertNotSame('', $body[$field], "field {$field} must not be empty");
        }

        // Protocol version pinned at stage 1.
        $this->assertSame('1.0', $body['protocol_version']);

        // Telaris version comes from VERSION; current shape is N.N.N.
        $this->assertMatchesRegularExpression('#\A\d+\.\d+\.\d+\z#', $body['telaris_version']);

        // public_key is base64 of 32 Ed25519 bytes.
        $rawKey = base64_decode($body['public_key'], true);
        $this->assertIsString($rawKey, 'public_key is not valid base64');
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($rawKey), 'public_key wrong length after base64 decode');

        // fingerprint is base64url of first 16 bytes of SHA-256(public_key);
        // 22 characters, alphabet [A-Za-z0-9_-], no padding.
        $this->assertMatchesRegularExpression(
            '#\A[A-Za-z0-9_-]{22}\z#',
            $body['public_key_fingerprint'],
            'fingerprint must be 22-char base64url, no padding'
        );

        // Verify the fingerprint actually matches the public key (no
        // server-side drift between the field and the bytes it should
        // describe).
        $expectedFp = rtrim(strtr(base64_encode(substr(hash('sha256', $rawKey, true), 0, 16)), '+/', '-_'), '=');
        $this->assertSame(
            $expectedFp,
            $body['public_key_fingerprint'],
            'public_key_fingerprint does not match public_key'
        );

        // pluriverse_endpoint should be an https URL.
        $this->assertMatchesRegularExpression(
            '#\Ahttps://#',
            $body['pluriverse_endpoint'],
            'pluriverse_endpoint must be an https URL'
        );

        // hostname should be a non-empty bare host (no port, no scheme).
        $this->assertDoesNotMatchRegularExpression('#[:/]#', $body['hostname'], 'hostname must not contain : or /');

        // Cache headers set per handler.
        $this->assertStringContainsString('max-age=60', $result['cacheControl']);
    }

    public function testIdentityRejectsPost(): void
    {
        $result = $this->fetchJson('/api/pluriverse/identity', 'POST');
        if ($result['code'] === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} unreachable; skipping");
        }
        $this->assertSame(405, $result['code'], "POST should return 405 Method Not Allowed; got {$result['code']}");
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('method_not_allowed', $body['code'] ?? null);
        $this->assertStringContainsString('GET', $result['allow'], 'Allow header should list GET');
    }

    /**
     * @return array{code: int, body: string, contentType: string, cacheControl: string, allow: string}
     */
    private function fetchJson(string $path, string $method = 'GET'): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADER => true,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = '';
        $body = '';
        if (is_string($raw)) {
            $headers = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
        }
        $contentType = '';
        $cacheControl = '';
        $allow = '';
        foreach (explode("\r\n", $headers) as $line) {
            if (stripos($line, 'Content-Type:') === 0) $contentType = trim(substr($line, 13));
            elseif (stripos($line, 'Cache-Control:') === 0) $cacheControl = trim(substr($line, 14));
            elseif (stripos($line, 'Allow:') === 0) $allow = trim(substr($line, 6));
        }
        return [
            'code' => $code,
            'body' => $body,
            'contentType' => $contentType,
            'cacheControl' => $cacheControl,
            'allow' => $allow,
        ];
    }
}
