<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Auto-enroll config persistence + openness (editor self-enrollment, task A/D).
 *
 * The config is a single global row in system_meta, so setUp saves the live
 * value and tearDown restores it; tests never leave the instance's real
 * enrolment state changed. Synthetic 'aitest-cap-' editors are cleaned up.
 * Run this file on its own.
 */
final class AutoEnrollConfigTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string,mixed> */
    private array $savedConfig = [];

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->savedConfig = db_get_auto_enroll_config();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        db_set_auto_enroll_config($this->savedConfig);
    }

    public function testDefaultIsDisabled(): void
    {
        db_system_meta_delete(AUTO_ENROLL_META_KEY);
        $cfg = db_get_auto_enroll_config();
        $this->assertFalse($cfg['enabled']);
        $this->assertFalse(db_auto_enroll_is_open());
    }

    public function testRoundTripNormalizes(): void
    {
        db_set_auto_enroll_config([
            'enabled' => true,
            'create_personal_galaxy' => true,
            'naming_convention' => 'first_name',
            'domains' => 'UBC.ca, @gmail.com',  // string form, normalized to array
            'galaxy_ids' => ['3', 3, 1],
            'access_level' => 'read_only',
            'cap_enabled' => true,
            'cap' => '7',
        ]);
        $cfg = db_get_auto_enroll_config();
        $this->assertTrue($cfg['enabled']);
        $this->assertTrue($cfg['create_personal_galaxy']);
        $this->assertSame('first_name', $cfg['naming_convention']);
        $this->assertSame(['ubc.ca', 'gmail.com'], $cfg['domains']);
        $this->assertSame([1, 3], $cfg['galaxy_ids']);
        $this->assertSame('read_only', $cfg['access_level']);
        $this->assertTrue($cfg['cap_enabled']);
        $this->assertSame(7, $cfg['cap']);
    }

    public function testGarbageJsonFallsBackToSafeDefaults(): void
    {
        db_system_meta_set(AUTO_ENROLL_META_KEY, '}{not json');
        $cfg = db_get_auto_enroll_config();
        $this->assertFalse($cfg['enabled']);
        $this->assertSame(ENROLL_NAMING_DEFAULT, $cfg['naming_convention']);
    }

    public function testCapClosesEnrolmentWhenReached(): void
    {
        $base = db_count_unvetted_editors();
        // Cap exactly at the current unvetted count: enrolment is closed.
        db_set_auto_enroll_config(['enabled' => true, 'cap_enabled' => true, 'cap' => max(1, $base)]);
        if ($base >= 1) {
            $this->assertFalse(db_auto_enroll_is_open(), 'At or over cap must be closed.');
        }
        // Raise the cap above the count: open again.
        db_set_auto_enroll_config(['enabled' => true, 'cap_enabled' => true, 'cap' => $base + 5]);
        $this->assertTrue(db_auto_enroll_is_open(), 'Under cap must be open.');

        // Adding unvetted editors consumes headroom.
        for ($i = 0; $i < 5; $i++) {
            $uid = 'aitest-cap-' . bin2hex(random_bytes(4));
            $this->pdo->prepare("INSERT INTO users (id,email,password,firstname,lastname,type,vetted) VALUES (?,?,?,?,?,1,0)")
                ->execute([$uid, $uid . '@aitest.local', null, 'Cap', 'Test']);
        }
        $this->assertSame($base + 5, db_count_unvetted_editors());
        $this->assertFalse(db_auto_enroll_is_open(), 'Cap reached after adding unvetted editors closes enrolment.');
    }

    public function testVettedEditorsDoNotCountAgainstCap(): void
    {
        $base = db_count_unvetted_editors();
        $uid = 'aitest-cap-' . bin2hex(random_bytes(4));
        // A vetted editor (vetted=1) must NOT be counted.
        $this->pdo->prepare("INSERT INTO users (id,email,password,firstname,lastname,type,vetted) VALUES (?,?,?,?,?,1,1)")
            ->execute([$uid, $uid . '@aitest.local', password_hash('x', PASSWORD_DEFAULT), 'Vet', 'Test']);
        $this->assertSame($base, db_count_unvetted_editors());
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-cap-%' OR email LIKE 'aitest-cap-%@aitest.local'");
    }
}
