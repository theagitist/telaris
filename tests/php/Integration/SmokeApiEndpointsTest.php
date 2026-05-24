<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Smoke test: every public api/*.php endpoint responds without a 500.
 *
 * The point is not to validate behaviour (other tests cover that). The point
 * is to catch routing / autoload / fatal-error regressions when downstream
 * work (federation stage 1's new /api/pluriverse/ endpoints, composer dep
 * additions in 1d/1e, future M-E3-style cross-codebase sweeps) accidentally
 * breaks one of the existing surfaces.
 *
 * Each endpoint is hit via curl against the local SAPI (the live nginx +
 * PHP-FPM stack on this VPS). Expected status set is the set of "endpoint
 * reached PHP and returned an HTTP response on purpose" codes: 200, 401,
 * 403, 404, 405, 429. A 500 means a PHP fatal or an unhandled exception
 * reached the response surface — the regression we want to catch.
 *
 * Run requires the site to be reachable at TELARIS_SMOKE_BASE_URL (default
 * https://starmaps.polivoxia.ca). Override via env var for telaris or local
 * dev. Tests skip with a clear message if the base URL is unreachable.
 */
final class SmokeApiEndpointsTest extends TestCase
{
    /** Endpoints that handle requests. Helper files (api/auth.php) excluded. */
    private const ENDPOINTS = [
        '/api/apikey.php',
        '/api/bridge.php',
        '/api/connections.php',
        '/api/constellations.php',
        '/api/csp-report.php',
        '/api/keyword-canvas.php',
        '/api/keywords.php',
        '/api/nodes.php',
        '/api/tags.php',
        '/api/validate.php',
        // Federation (stage 1c+; URL has no .php suffix — routed through
        // index.php → inc/federation/router.php).
        '/api/pluriverse/identity',
        '/api/pluriverse/openapi.json',
    ];

    /** Set of "PHP reached the response surface intentionally" codes. */
    private const OK_STATUS = [200, 204, 301, 302, 400, 401, 403, 404, 405, 415, 429];

    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string)(getenv('TELARIS_SMOKE_BASE_URL') ?: 'https://starmaps.polivoxia.ca'), '/');

        // Reachability gate. If the site isn't up, this whole suite is moot.
        $rootCode = $this->fetchStatus('/');
        if ($rootCode === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} is unreachable from this host; skipping smoke");
        }
    }

    public static function endpointProvider(): array
    {
        return array_map(fn($e) => [$e], self::ENDPOINTS);
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testGetEndpointDoesNotFatal(string $path): void
    {
        $code = $this->fetchStatus($path);
        $this->assertContains(
            $code,
            self::OK_STATUS,
            "GET {$path} returned HTTP {$code}; expected a non-500 status. A 500 means the endpoint hit a PHP fatal."
        );
    }

    /**
     * The 12 status codes in OK_STATUS are exactly the set we treat as "PHP
     * intentionally returned this." If a test ever sees a code outside the
     * set, the set is wrong (a new legitimate status got added) OR something
     * broke. Pin the set so a refactor that introduces a 502/503/504 path
     * fails this test loudly rather than silently accepting it.
     */
    public function testOkStatusSetIsLockedDown(): void
    {
        $expected = [200, 204, 301, 302, 400, 401, 403, 404, 405, 415, 429];
        $this->assertSame($expected, self::OK_STATUS, 'OK_STATUS set drifted; update intentionally.');
    }

    private function fetchStatus(string $path): int
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }
}
