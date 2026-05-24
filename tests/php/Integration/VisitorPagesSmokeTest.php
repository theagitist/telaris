<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Smoke test: the operator-and-visitor-facing PHP pages render without a
 * PHP fatal error. Catches t_attr / route / asset-version regressions that
 * the PHPUnit unit suite can't see because they fire at render time.
 *
 * Same shape as SmokeApiEndpointsTest: curl against the live SAPI on this
 * VPS, accept only the set of intentional HTTP statuses, treat 500 as the
 * specific failure mode we want to surface.
 *
 * Pages tested are the ones that the audit thread learned to manually
 * curl-check after each cross-codebase sweep (M-E3, the t() escape work,
 * the API key wiring); making it automated removes the manual step.
 */
final class VisitorPagesSmokeTest extends TestCase
{
    private const PAGES = [
        '/',                       // visitor 3D scene
        '/utils/login.php',        // login form
        '/utils/forgot.php',       // password-reset request form
        '/edit/',                  // editor home (302 to login when unauthenticated)
        '/admin/',                 // admin home (302 to login when unauthenticated)
    ];

    private const OK_STATUS = [200, 204, 301, 302, 401, 403, 404];

    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string)(getenv('TELARIS_SMOKE_BASE_URL') ?: 'https://starmaps.polivoxia.ca'), '/');
        $rootCode = $this->fetchStatusAndBody('/')['code'];
        if ($rootCode === 0) {
            $this->markTestSkipped("base URL {$this->baseUrl} is unreachable from this host; skipping smoke");
        }
    }

    public static function pageProvider(): array
    {
        return array_map(fn($p) => [$p], self::PAGES);
    }

    /**
     * @dataProvider pageProvider
     */
    public function testPageDoesNotFatal(string $path): void
    {
        $r = $this->fetchStatusAndBody($path);
        $this->assertContains(
            $r['code'],
            self::OK_STATUS,
            "GET {$path} returned HTTP {$r['code']}; expected a non-500 status."
        );

        // Inspect the response body for PHP-fatal patterns. A request can
        // return a 200 with the body itself containing "Fatal error:" or
        // "PHP Stack trace:" if display_errors is on or an HTML wrapper
        // got concatenated to error output. Either is a regression.
        $body = (string)$r['body'];
        $needles = [
            'Fatal error:',
            'Parse error:',
            'Uncaught Error:',
            'Uncaught Exception',
            'PHP Stack trace:',
            'Stack trace:\n#0',
        ];
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "GET {$path} body contains PHP error pattern \"{$needle}\""
            );
        }
    }

    /**
     * @return array{code:int, body:string}
     */
    private function fetchStatusAndBody(string $path): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }
}
