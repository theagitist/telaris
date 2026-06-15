<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/enroll-actions.php';

/**
 * Editor self-enrollment end-to-end at the DB layer (task D): the unconfirmed
 * account shape, and the confirm-time config application (enroll_apply_config)
 * across naming conventions and granted-seat access levels.
 *
 * Synthetic 'aitest-enr-' rows, self-cleaning teardown; run on its own.
 */
final class EnrollmentFlowTest extends TestCase
{
    private PDO $pdo;
    private array $tempGalaxyIds = [];

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function makeEnrollee(string $name = 'Alice'): array
    {
        $email = 'aitest-enr-' . bin2hex(random_bytes(4)) . '@aitest.local';
        // Mirrors the enroll POST: unvetted, password-less editor.
        $err = createUser(getDB(), $email, null, $name, null, USER_TYPE_EDITOR, null, false);
        $this->assertNull($err, 'createUser should succeed');
        $user = db_get_user_by_email($email);
        $this->assertNotNull($user);
        return $user;
    }

    public function testUnconfirmedAccountShape(): void
    {
        $u = $this->makeEnrollee();
        $row = $this->pdo->query("SELECT vetted, password, type FROM users WHERE id=" . $this->pdo->quote((string)$u['id']))->fetch();
        $this->assertSame('0', (string)$row['vetted'], 'self-enrolled editor is unvetted');
        $this->assertNull($row['password'], 'self-enrolled editor has no password');
        $this->assertSame('1', (string)$row['type'], 'type is editor');
    }

    public function testConfirmCreatesPersonalGalaxyByEmailUsername(): void
    {
        $u = $this->makeEnrollee();
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true,
            'naming_convention' => 'email_username', 'galaxy_ids' => [],
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->tempGalaxyIds[] = $res['personal_galaxy_id'];

        $this->assertNotNull($res['personal_galaxy_id']);
        $name = (string)$this->pdo->query("SELECT name FROM constellations WHERE id=" . (int)$res['personal_galaxy_id'])->fetchColumn();
        $expected = explode('@', (string)$u['email'])[0];
        $this->assertSame($expected, $name);
        // Personal seat is read_write.
        $access = db_get_user_constellation_access((string)$u['id']);
        $this->assertSame('read_write', $access[(int)$res['personal_galaxy_id']] ?? null);
    }

    public function testConfirmFirstNameConventionUsesPossessive(): void
    {
        $u = $this->makeEnrollee('Bréndon');
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true, 'naming_convention' => 'first_name',
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg, '%s possessive');
        $this->tempGalaxyIds[] = $res['personal_galaxy_id'];
        $name = (string)$this->pdo->query("SELECT name FROM constellations WHERE id=" . (int)$res['personal_galaxy_id'])->fetchColumn();
        $this->assertSame('Bréndon possessive', $name);
    }

    public function testUserChoiceDefersGalaxyAndSetsFlag(): void
    {
        $u = $this->makeEnrollee();
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true, 'naming_convention' => 'user_choice',
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->assertNull($res['personal_galaxy_id'], 'user_choice defers galaxy creation');
        $this->assertTrue($res['deferred']);
        // The pending flag is set, and read-and-clear returns true once.
        $this->assertTrue(db_take_pending_personal_galaxy((string)$u['id']));
        $this->assertFalse(db_take_pending_personal_galaxy((string)$u['id']), 'flag is one-time');
    }

    public function testGrantedGalaxiesUseConfiguredAccessLevel(): void
    {
        $u = $this->makeEnrollee();
        $g1 = db_create_constellation('aitest-enr-grant-' . bin2hex(random_bytes(3)), '', null, 'cosmic');
        $g2 = db_create_constellation('aitest-enr-grant-' . bin2hex(random_bytes(3)), '', null, 'cosmic');
        $this->tempGalaxyIds[] = $g1;
        $this->tempGalaxyIds[] = $g2;
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => false,
            'galaxy_ids' => [$g1, $g2], 'access_level' => 'read_only',
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->assertEqualsCanonicalizing([$g1, $g2], $res['granted']);
        $access = db_get_user_constellation_access((string)$u['id']);
        $this->assertSame('read_only', $access[$g1] ?? null);
        $this->assertSame('read_only', $access[$g2] ?? null);
    }

    public function testDomainAllowlistGatesEmail(): void
    {
        // The endpoint calls this before creating the account.
        $this->assertTrue(enroll_email_domain_allowed('a@aitest.local', ['aitest.local']));
        $this->assertFalse(enroll_email_domain_allowed('a@elsewhere.com', ['aitest.local']));
    }

    public function testPersonalGalaxyIsAlwaysReadWriteEvenWhenConfiguredReadOnly(): void
    {
        // Invariant: you own your personal galaxy, so its seat is read_write
        // regardless of the configured access_level for *granted* galaxies. A
        // future refactor that threaded the config level into the personal seat
        // would silently demote owners; this pins it.
        $u = $this->makeEnrollee();
        $granted = db_create_constellation('aitest-enr-grant-' . bin2hex(random_bytes(3)), '', null, 'cosmic');
        $this->tempGalaxyIds[] = $granted;
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true, 'naming_convention' => 'email_username',
            'galaxy_ids' => [$granted], 'access_level' => 'read_only',
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->tempGalaxyIds[] = $res['personal_galaxy_id'];

        $access = db_get_user_constellation_access((string)$u['id']);
        $this->assertSame('read_write', $access[(int)$res['personal_galaxy_id']] ?? null, 'owner keeps read_write on their own galaxy');
        $this->assertSame('read_only', $access[$granted] ?? null, 'granted galaxy honours the configured level');
    }

    public function testPersonalGalaxyShipsWithVisitorFeaturesOn(): void
    {
        // A freshly auto-created personal galaxy must enable the visitor-facing
        // features: keyword chips, related wormholes, the 2D view switch, and
        // idle spotlight (spotlighting all nodes). New galaxies default these off.
        $u = $this->makeEnrollee();
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true,
            'naming_convention' => 'email_username', 'galaxy_ids' => [],
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->tempGalaxyIds[] = $res['personal_galaxy_id'];
        $this->assertNotNull($res['personal_galaxy_id']);

        $row = $this->pdo->query(
            "SELECT keyword_chips_enabled, related_nodes_enabled, show_2d_view, idle_spotlight_enabled, idle_spotlight_selection"
            . " FROM constellations WHERE id=" . (int)$res['personal_galaxy_id']
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('1', (string)$row['keyword_chips_enabled'], 'keyword chips on');
        $this->assertSame('1', (string)$row['related_nodes_enabled'], 'related wormholes on');
        $this->assertSame('1', (string)$row['show_2d_view'], '2D view switch on');
        $this->assertSame('1', (string)$row['idle_spotlight_enabled'], 'idle spotlight on');
        $this->assertSame('all', (string)$row['idle_spotlight_selection'], 'idle spotlight covers all nodes');
    }

    public function testNoPersonalGalaxyWhenDisabled(): void
    {
        $u = $this->makeEnrollee();
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => false, 'galaxy_ids' => [],
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->assertNull($res['personal_galaxy_id'], 'no galaxy created when disabled');
        $this->assertFalse($res['deferred'], 'not deferred when creation is disabled');
        $this->assertSame([], db_get_user_constellation_access((string)$u['id']), 'no seats granted');
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-enr-%' OR email LIKE 'aitest-enr-%@aitest.local'");
        foreach (array_filter($this->tempGalaxyIds) as $gid) {
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$gid]);
        }
        $this->pdo->exec("DELETE FROM constellations WHERE slug LIKE 'aitest-enr-grant-%' OR name LIKE 'aitest-enr-grant-%'");
        $this->tempGalaxyIds = [];
    }
}
