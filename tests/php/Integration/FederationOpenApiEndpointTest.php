<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: GET /api/pluriverse/openapi.json returns a valid OpenAPI
 * 3.1 document.
 *
 * Hits the live SAPI on this VPS (default https://starmaps.polivoxia.ca).
 * Skips if the host is unreachable. The test validates structural
 * properties (openapi version, info.version matching protocol_version,
 * paths present, Last-Modified behaviour) rather than full JSON Schema
 * validation against the OpenAPI meta-schema — swagger-php is the
 * authoritative producer, and a meta-schema validator would add a
 * heavyweight transitive dep just for one test.
 *
 * Spec: P2P federation plan v10 § Pluriverse protocol → Standards and crypto
 *       (line 482), § Instance-side endpoint catalogue (line 469).
 */
final class FederationOpenApiEndpointTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string)(getenv('TELARIS_SMOKE_BASE_URL') ?: 'https://starmaps.polivoxia.ca'), '/');
    }

    public function testOpenApiDocStructure(): void
    {
        $result = $this->fetch('/api/pluriverse/openapi.json');
        if ($result['code'] === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} unreachable; skipping");
        }
        $this->assertSame(200, $result['code'], "expected 200; got {$result['code']}");
        $this->assertStringContainsString('application/json', $result['contentType']);

        $doc = json_decode($result['body'], true);
        $this->assertIsArray($doc, 'response body is not valid JSON');

        // OpenAPI 3.1 contract.
        $this->assertSame('3.1.0', $doc['openapi'] ?? null, 'openapi version must be 3.1.0');

        // info.version must match the protocol_version served by /api/pluriverse/identity.
        $this->assertArrayHasKey('info', $doc);
        $this->assertSame('1.0', $doc['info']['version'] ?? null);
        $this->assertNotEmpty($doc['info']['title'] ?? null);

        // License matches composer (GPL-3.0-or-later for instances; AGPL is
        // the Pluriverse repo, not this one).
        $this->assertSame('GPL-3.0-or-later', $doc['info']['license']['name'] ?? null);

        // Paths must include the identity endpoint and the openapi endpoint itself.
        $this->assertArrayHasKey('paths', $doc);
        $this->assertArrayHasKey('/api/pluriverse/identity', $doc['paths'], 'identity endpoint not documented');
        $this->assertArrayHasKey('/api/pluriverse/openapi.json', $doc['paths'], 'openapi endpoint not documented');

        // The identity endpoint must declare a 200 response with a schema reference.
        $get = $doc['paths']['/api/pluriverse/identity']['get'] ?? null;
        $this->assertIsArray($get, 'identity GET operation missing');
        $this->assertArrayHasKey('200', $get['responses'] ?? []);

        // Components/schemas should declare IdentityEnvelope and ProblemDetails.
        $schemas = $doc['components']['schemas'] ?? [];
        $this->assertArrayHasKey('IdentityEnvelope', $schemas);
        $this->assertArrayHasKey('ProblemDetails', $schemas);
    }

    public function testInfoVersionMatchesIdentityProtocolVersion(): void
    {
        $openapiResult = $this->fetch('/api/pluriverse/openapi.json');
        $identityResult = $this->fetch('/api/pluriverse/identity');

        if ($openapiResult['code'] === 0 || $identityResult['code'] === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} unreachable; skipping");
        }
        if ($identityResult['code'] === 503) {
            $this->markTestSkipped('identity not provisioned; skipping cross-check');
        }

        $doc = json_decode($openapiResult['body'], true);
        $identity = json_decode($identityResult['body'], true);

        $this->assertSame(
            $identity['protocol_version'] ?? null,
            $doc['info']['version'] ?? null,
            'OpenAPI info.version MUST match identity.protocol_version'
        );
    }

    public function testLastModifiedAndConditionalGet(): void
    {
        $result = $this->fetch('/api/pluriverse/openapi.json');
        if ($result['code'] === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} unreachable; skipping");
        }
        $this->assertSame(200, $result['code']);
        $this->assertNotEmpty($result['lastModified'], 'Last-Modified header missing');

        // If-Modified-Since with the served Last-Modified should return 304.
        $cond = $this->fetch('/api/pluriverse/openapi.json', 'GET', $result['lastModified']);
        $this->assertSame(304, $cond['code'], 'expected 304 Not Modified on If-Modified-Since match');

        // If-Modified-Since with an ancient date should return 200.
        $old = $this->fetch('/api/pluriverse/openapi.json', 'GET', 'Wed, 01 Jan 2020 00:00:00 GMT');
        $this->assertSame(200, $old['code'], 'expected 200 on If-Modified-Since older than Last-Modified');
    }

    public function testOpenApiRejectsPost(): void
    {
        $result = $this->fetch('/api/pluriverse/openapi.json', 'POST');
        if ($result['code'] === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} unreachable; skipping");
        }
        $this->assertSame(405, $result['code']);
        $this->assertStringContainsString('GET', $result['allow']);
    }

    /**
     * @return array{code:int, body:string, contentType:string, lastModified:string, allow:string}
     */
    private function fetch(string $path, string $method = 'GET', ?string $ifModifiedSince = null): array
    {
        $ch = curl_init();
        $headers = [];
        if ($ifModifiedSince !== null) {
            $headers[] = 'If-Modified-Since: ' . $ifModifiedSince;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
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

        $headerStr = '';
        $body = '';
        if (is_string($raw)) {
            $headerStr = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
        }
        $contentType = '';
        $lastModified = '';
        $allow = '';
        foreach (explode("\r\n", $headerStr) as $line) {
            if (stripos($line, 'Content-Type:') === 0) $contentType = trim(substr($line, 13));
            elseif (stripos($line, 'Last-Modified:') === 0) $lastModified = trim(substr($line, 14));
            elseif (stripos($line, 'Allow:') === 0) $allow = trim(substr($line, 6));
        }
        return ['code' => $code, 'body' => $body, 'contentType' => $contentType, 'lastModified' => $lastModified, 'allow' => $allow];
    }
}
