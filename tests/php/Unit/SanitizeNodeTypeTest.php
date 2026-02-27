<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SanitizeNodeTypeTest extends TestCase
{
    #[DataProvider('nodeTypeProvider')]
    public function testSanitizeNodeType(mixed $input, string $expected): void
    {
        $this->assertSame($expected, sanitizeNodeType($input));
    }

    public static function nodeTypeProvider(): array
    {
        return [
            'valid object' => ['object', 'object'],
            'valid portal' => ['portal', 'portal'],
            'invalid type defaults to object' => ['unknown', 'object'],
            'empty string defaults to object' => ['', 'object'],
            'null defaults to object' => [null, 'object'],
            'integer defaults to object' => [42, 'object'],
            'array defaults to object' => [['object'], 'object'],
            'whitespace trimmed' => [' portal ', 'portal'],
            'case sensitive' => ['Portal', 'object'],
            'SQL injection attempt' => ["object'; DROP TABLE nodes;--", 'object'],
        ];
    }
}
