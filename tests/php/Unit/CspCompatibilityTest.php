<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Scans public-facing HTML templates for inline event handlers (onclick=, onload=, etc.)
 * that are incompatible with nonce-based Content Security Policy.
 *
 * This test catches the exact class of breakage from the CSP nonce incident:
 * adding a nonce-based CSP while inline event handlers exist silently breaks them.
 *
 * Note: admin/index.php, edit/index.php, and admin/setup.php deliberately use
 * 'unsafe-inline' in their CSP, so they are excluded from this check.
 */
final class CspCompatibilityTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /**
     * Public-facing templates that must NOT contain inline event handlers.
     * These files use (or should use) nonce-based CSP.
     */
    private const PUBLIC_TEMPLATES = [
        'inc/main-view.php',
    ];

    /**
     * Inline event handler attribute pattern.
     * Matches HTML attributes like onclick="...", onload="...", etc.
     * Does NOT match JS property assignments like `el.onclick = fn` (no preceding =/"/' before `on`).
     */
    private const INLINE_HANDLER_PATTERN = '/\s+on(?:click|dblclick|mouseover|mouseout|mousedown|mouseup|mousemove|mouseenter|mouseleave|keydown|keyup|keypress|load|error|change|submit|focus|blur|input|reset|select|scroll|resize|unload|beforeunload|contextmenu|drag|dragend|dragenter|dragleave|dragover|dragstart|drop|copy|cut|paste|touchstart|touchend|touchmove)\s*=/i';

    #[DataProvider('publicTemplateProvider')]
    public function testNoInlineEventHandlers(string $relativePath): void
    {
        $fullPath = self::PROJECT_ROOT . '/' . $relativePath;
        $this->assertFileExists($fullPath, "Template file not found: {$relativePath}");

        $content = file_get_contents($fullPath);

        // Check each line for inline handlers so we can report line numbers
        $lines = explode("\n", $content);
        $violations = [];
        foreach ($lines as $lineNum => $line) {
            if (preg_match(self::INLINE_HANDLER_PATTERN, $line, $matches)) {
                $violations[] = sprintf(
                    '  Line %d: %s (found: %s)',
                    $lineNum + 1,
                    trim($line),
                    trim($matches[0])
                );
            }
        }

        $this->assertEmpty(
            $violations,
            "Inline event handlers found in {$relativePath} (incompatible with nonce CSP):\n"
            . implode("\n", $violations)
            . "\n\nUse addEventListener() or a <script nonce=\"...\"> block instead."
        );
    }

    public static function publicTemplateProvider(): array
    {
        $cases = [];
        foreach (self::PUBLIC_TEMPLATES as $path) {
            $cases[$path] = [$path];
        }
        return $cases;
    }
}
