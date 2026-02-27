<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValidateSafeUrlTest extends TestCase
{
    #[DataProvider('safeUrlProvider')]
    public function testValidateSafeUrl(string $url, bool $expected): void
    {
        $this->assertSame($expected, validateSafeUrl($url));
    }

    public static function safeUrlProvider(): array
    {
        return [
            'https URL' => ['https://example.com', true],
            'http URL' => ['http://example.com/path?q=1', true],
            'javascript scheme' => ['javascript:alert(1)', false],
            'data scheme' => ['data:text/html,<h1>XSS</h1>', false],
            'vbscript scheme' => ['vbscript:MsgBox("XSS")', false],
            'ftp scheme' => ['ftp://files.example.com', false],
            'file scheme' => ['file:///etc/passwd', false],
            'empty string' => ['', false],
            'just text' => ['not a url', false],
            'missing scheme' => ['example.com', false],
            'HTTPS uppercase' => ['HTTPS://EXAMPLE.COM', true],
            'mixed case scheme' => ['HtTp://example.com', true],
        ];
    }
}
