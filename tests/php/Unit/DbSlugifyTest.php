<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DbSlugifyTest extends TestCase
{
    #[DataProvider('slugifyProvider')]
    public function testSlugify(string $input, string $expected): void
    {
        $this->assertSame($expected, db_slugify($input));
    }

    public static function slugifyProvider(): array
    {
        return [
            'simple lowercase' => ['hello world', 'hello-world'],
            'mixed case' => ['Hello World', 'hello-world'],
            'special characters removed' => ['café & résumé!', 'caf-rsum'],
            'multiple spaces' => ['foo   bar', 'foo-bar'],
            'leading/trailing hyphens' => ['---hello---', 'hello'],
            'consecutive hyphens collapsed' => ['a--b---c', 'a-b-c'],
            'empty string' => ['', ''],
            'only special chars' => ['@#$%^&*()', ''],
            'numbers preserved' => ['project 123', 'project-123'],
            'already a slug' => ['my-cool-slug', 'my-cool-slug'],
            'unicode stripped' => ['日本語テスト', ''],
            'tabs and newlines' => ["tab\there\nnewline", 'tabherenewline'],
        ];
    }
}
