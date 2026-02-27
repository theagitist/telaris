<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SanitizeEmbedCodeTest extends TestCase
{
    public function testValidHttpsIframePreserved(): void
    {
        $input = '<iframe src="https://www.youtube.com/embed/abc" width="560" height="315"></iframe>';
        $result = sanitizeEmbedCode($input);
        $this->assertNotNull($result);
        $this->assertStringContainsString('src="https://www.youtube.com/embed/abc"', $result);
        $this->assertStringContainsString('width="560"', $result);
    }

    public function testJavascriptSrcRejected(): void
    {
        $input = '<iframe src="javascript:alert(1)"></iframe>';
        $result = sanitizeEmbedCode($input);
        $this->assertNull($result);
    }

    public function testDataSrcRejected(): void
    {
        $input = '<iframe src="data:text/html,<script>alert(1)</script>"></iframe>';
        $result = sanitizeEmbedCode($input);
        $this->assertNull($result);
    }

    public function testOnloadAttributeStripped(): void
    {
        $input = '<iframe src="https://example.com" onload="alert(1)"></iframe>';
        $result = sanitizeEmbedCode($input);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('onload', $result);
        $this->assertStringContainsString('src="https://example.com"', $result);
    }

    public function testScriptTagStripped(): void
    {
        $input = '<script>alert("xss")</script><iframe src="https://example.com"></iframe>';
        $result = sanitizeEmbedCode($input);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('src="https://example.com"', $result);
    }

    public function testEmptyStringReturnsNull(): void
    {
        $this->assertNull(sanitizeEmbedCode(''));
    }

    public function testWhitespaceOnlyReturnsNull(): void
    {
        $this->assertNull(sanitizeEmbedCode('   '));
    }

    public function testNonIframeHtmlReturnsNull(): void
    {
        $input = '<div>Hello</div><p>World</p>';
        $this->assertNull(sanitizeEmbedCode($input));
    }

    public function testAllowfullscreenPreserved(): void
    {
        $input = '<iframe src="https://example.com" allowfullscreen></iframe>';
        $result = sanitizeEmbedCode($input);
        $this->assertNotNull($result);
        $this->assertStringContainsString('allowfullscreen', $result);
    }
}
