<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HashPasswordTest extends TestCase
{
    public function testHashAndVerifyRoundTrip(): void
    {
        $password = 'correct-horse-battery-staple';
        $hash = hashPassword($password);

        $this->assertTrue(verifyPassword($password, $hash));
    }

    public function testWrongPasswordFails(): void
    {
        $hash = hashPassword('real-password');

        $this->assertFalse(verifyPassword('wrong-password', $hash));
    }

    public function testHashIsBcrypt(): void
    {
        $hash = hashPassword('test');

        // BCrypt hashes start with $2y$ (PHP's default)
        $this->assertMatchesRegularExpression('/^\$2[aby]\$/', $hash);
    }

    public function testDifferentHashesForSamePassword(): void
    {
        $password = 'same-password';
        $hash1 = hashPassword($password);
        $hash2 = hashPassword($password);

        // Salts differ, so hashes should differ
        $this->assertNotSame($hash1, $hash2);
        // But both should verify
        $this->assertTrue(verifyPassword($password, $hash1));
        $this->assertTrue(verifyPassword($password, $hash2));
    }

    public function testPasswordNeedsRehashWithCurrentAlgorithm(): void
    {
        $hash = hashPassword('test');

        // A freshly hashed password should NOT need rehashing
        $this->assertFalse(passwordNeedsRehash($hash));
    }
}
