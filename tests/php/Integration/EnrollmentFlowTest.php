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
            "SELECT keyword_chips_enabled, related_nodes_enabled, show_2d_view, idle_spotlight_enabled, idle_spotlight_selection, theme"
            . " FROM constellations WHERE id=" . (int)$res['personal_galaxy_id']
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('1', (string)$row['keyword_chips_enabled'], 'keyword chips on');
        $this->assertSame('1', (string)$row['related_nodes_enabled'], 'related wormholes on');
        $this->assertSame('1', (string)$row['show_2d_view'], '2D view switch on');
        $this->assertSame('1', (string)$row['idle_spotlight_enabled'], 'idle spotlight on');
        $this->assertSame('all', (string)$row['idle_spotlight_selection'], 'idle spotlight covers all nodes');
        $this->assertSame('abstract', (string)$row['theme'], 'auto-created personal galaxy defaults to the Abstract theme');
    }

    public function testPersonalGalaxyJoinsPerInstallationCluster(): void
    {
        // Each auto-created personal galaxy is gathered into a single cluster
        // named after the installation subdomain ("[GRSJ306]", "[STARMAPS]", ...),
        // created on the first enrolment and reused after.
        $sub = enroll_installation_subdomain();
        if ($sub === null) {
            $this->markTestSkipped('No trusted hostname configured; subdomain clustering is skipped.');
        }
        $clusterName = '[' . $sub . ']';
        // Did the cluster already exist? (so teardown only deletes what we made.)
        $pre = $this->pdo->prepare("SELECT id FROM constellations WHERE name = :n AND `type` = 'cluster' LIMIT 1");
        $pre->execute([':n' => $clusterName]);
        $preexisting = $pre->fetchColumn();

        $u = $this->makeEnrollee();
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true,
            'naming_convention' => 'email_username', 'galaxy_ids' => [],
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->tempGalaxyIds[] = $res['personal_galaxy_id'];
        $this->assertNotNull($res['personal_galaxy_id']);

        $cidStmt = $this->pdo->prepare("SELECT id FROM constellations WHERE name = :n AND `type` = 'cluster' LIMIT 1");
        $cidStmt->execute([':n' => $clusterName]);
        $clusterId = $cidStmt->fetchColumn();
        $this->assertNotFalse($clusterId, 'per-installation cluster exists after enrolment');
        if ($preexisting === false) {
            $this->tempGalaxyIds[] = (int)$clusterId; // we created it; clean up
        }

        $this->assertContains((int)$res['personal_galaxy_id'], db_get_cluster_member_ids((int)$clusterId), 'personal galaxy is a member of the cluster');

        // A second enrollee reuses the same cluster (no duplicate).
        $u2 = $this->makeEnrollee('Bo');
        $res2 = enroll_apply_config((string)$u2['id'], (string)$u2['email'], (string)$u2['firstname'], $cfg);
        $this->tempGalaxyIds[] = $res2['personal_galaxy_id'];
        $dupCount = (int)$this->pdo->query("SELECT COUNT(*) FROM constellations WHERE name = " . $this->pdo->quote($clusterName) . " AND `type` = 'cluster'")->fetchColumn();
        $this->assertSame(1, $dupCount, 'the per-installation cluster is reused, not duplicated');
        $this->assertContains((int)$res2['personal_galaxy_id'], db_get_cluster_member_ids((int)$clusterId), 'second personal galaxy joins the same cluster');
    }

    public function testDeferredGalaxyBindingSetsUpFirstGalaxyOnly(): void
    {
        // user_choice naming defers creation: the editor makes the galaxy
        // themselves at first login. The FIRST one they create must get the same
        // treatment as an auto-named personal galaxy (visitor features on +
        // per-installation cluster) and consume the pending flag; a SECOND galaxy
        // must be left untouched.
        $sub = enroll_installation_subdomain();
        $u = $this->makeEnrollee();
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true, 'naming_convention' => 'user_choice',
        ]);
        $res = enroll_apply_config((string)$u['id'], (string)$u['email'], (string)$u['firstname'], $cfg);
        $this->assertTrue($res['deferred']);
        $this->assertTrue(db_has_pending_personal_galaxy((string)$u['id']), 'pending flag set, not yet consumed');

        // The editor creates their first galaxy (mirrors create_constellation.php).
        $g1 = db_create_constellation('aitest-enr-grant-' . bin2hex(random_bytes(3)), '', null, 'cosmic', (string)$u['id']);
        $this->tempGalaxyIds[] = $g1;
        db_add_user_constellation((string)$u['id'], $g1, 'read_write');
        enroll_bind_deferred_personal_galaxy((string)$u['id'], $g1);

        $row = $this->pdo->query("SELECT keyword_chips_enabled, related_nodes_enabled, show_2d_view, idle_spotlight_enabled, theme FROM constellations WHERE id=" . (int)$g1)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1', (string)$row['keyword_chips_enabled']);
        $this->assertSame('1', (string)$row['related_nodes_enabled']);
        $this->assertSame('1', (string)$row['show_2d_view']);
        $this->assertSame('1', (string)$row['idle_spotlight_enabled']);
        $this->assertSame('abstract', (string)$row['theme'], 'deferred personal galaxy is themed Abstract');
        $this->assertFalse(db_has_pending_personal_galaxy((string)$u['id']), 'flag consumed by the first galaxy');

        if ($sub !== null) {
            $clusterId = $this->pdo->query("SELECT id FROM constellations WHERE name = " . $this->pdo->quote('[' . $sub . ']') . " AND `type` = 'cluster' LIMIT 1")->fetchColumn();
            $this->assertNotFalse($clusterId);
            $this->assertContains($g1, db_get_cluster_member_ids((int)$clusterId), 'deferred galaxy joined the cluster');
        }

        // A second galaxy created afterwards is an ordinary galaxy: features off,
        // not added to the cluster.
        $g2 = db_create_constellation('aitest-enr-grant-' . bin2hex(random_bytes(3)), '', null, 'cosmic', (string)$u['id']);
        $this->tempGalaxyIds[] = $g2;
        db_add_user_constellation((string)$u['id'], $g2, 'read_write');
        enroll_bind_deferred_personal_galaxy((string)$u['id'], $g2);

        $row2 = $this->pdo->query("SELECT keyword_chips_enabled, related_nodes_enabled, show_2d_view, idle_spotlight_enabled, theme FROM constellations WHERE id=" . (int)$g2)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('0', (string)$row2['keyword_chips_enabled'], 'second galaxy is left as a plain galaxy');
        $this->assertSame('0', (string)$row2['idle_spotlight_enabled']);
        $this->assertSame('cosmic', (string)$row2['theme'], 'second galaxy keeps its created theme, not forced to Abstract');
        if ($sub !== null) {
            $clusterId = $this->pdo->query("SELECT id FROM constellations WHERE name = " . $this->pdo->quote('[' . $sub . ']') . " AND `type` = 'cluster' LIMIT 1")->fetchColumn();
            $this->assertNotContains($g2, db_get_cluster_member_ids((int)$clusterId), 'second galaxy is not gathered into the cluster');
        }
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

    public function testSameNameEnrolleesEachGetTheirOwnPersonalGalaxy(): void
    {
        // Regression: two editors with the SAME name (or one editor enrolling
        // twice). The auto-slug collided on the UNIQUE slug column and the
        // exception was swallowed, leaving the second editor with NO writable
        // galaxy. Both must now get a personal galaxy with a read_write seat.
        $cfg = auto_enroll_normalize_config([
            'enabled' => true, 'create_personal_galaxy' => true,
            'naming_convention' => 'first_name', 'galaxy_ids' => [],
        ]);

        $u1 = $this->makeEnrollee('Jiseon');
        $res1 = enroll_apply_config((string)$u1['id'], (string)$u1['email'], (string)$u1['firstname'], $cfg, '%s');
        $this->tempGalaxyIds[] = $res1['personal_galaxy_id'];

        $u2 = $this->makeEnrollee('Jiseon');
        $res2 = enroll_apply_config((string)$u2['id'], (string)$u2['email'], (string)$u2['firstname'], $cfg, '%s');
        $this->tempGalaxyIds[] = $res2['personal_galaxy_id'];

        $this->assertNotNull($res1['personal_galaxy_id'], 'first same-name enrollee gets a galaxy');
        $this->assertNotNull($res2['personal_galaxy_id'], 'second same-name enrollee gets a galaxy too');
        $this->assertNotSame($res1['personal_galaxy_id'], $res2['personal_galaxy_id'], 'distinct galaxies');

        // Slugs are distinct (the dedupe appended a suffix), names can match.
        $slug1 = (string)$this->pdo->query("SELECT slug FROM constellations WHERE id=" . (int)$res1['personal_galaxy_id'])->fetchColumn();
        $slug2 = (string)$this->pdo->query("SELECT slug FROM constellations WHERE id=" . (int)$res2['personal_galaxy_id'])->fetchColumn();
        $this->assertNotSame($slug1, $slug2, 'deduped slug, no collision');

        // Both owners have a read_write seat on their own galaxy.
        $a1 = db_get_user_constellation_access((string)$u1['id']);
        $a2 = db_get_user_constellation_access((string)$u2['id']);
        $this->assertSame('read_write', $a1[(int)$res1['personal_galaxy_id']] ?? null);
        $this->assertSame('read_write', $a2[(int)$res2['personal_galaxy_id']] ?? null);
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-enr-%' OR email LIKE 'aitest-enr-%@aitest.local'");
        $this->pdo->exec("DELETE FROM system_meta WHERE meta_key LIKE 'enroll_pending_galaxy:aitest-enr-%'");
        foreach (array_filter($this->tempGalaxyIds) as $gid) {
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$gid]);
        }
        $this->pdo->exec("DELETE FROM constellations WHERE slug LIKE 'aitest-enr-grant-%' OR name LIKE 'aitest-enr-grant-%'");
        // Every personal-galaxy test now also creates/joins the per-installation
        // cluster ("[STARMAPS]" on this host). Once its test galaxies are gone it
        // is empty; drop it so runs stay isolated. No real auto-enroll cluster
        // exists on starmaps, so the empty-membership guard makes this safe.
        $sub = enroll_installation_subdomain();
        if ($sub !== null) {
            $name = '[' . $sub . ']';
            $cid = $this->pdo->prepare("SELECT id FROM constellations WHERE name = :n AND `type` = 'cluster' LIMIT 1");
            $cid->execute([':n' => $name]);
            $clusterId = $cid->fetchColumn();
            if ($clusterId !== false) {
                $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM galaxy_cluster_members WHERE cluster_id = " . (int)$clusterId)->fetchColumn();
                if ($cnt === 0) {
                    $this->pdo->prepare("DELETE FROM constellations WHERE id = ? AND `type` = 'cluster'")->execute([(int)$clusterId]);
                }
            }
        }
        $this->tempGalaxyIds = [];
    }
}
