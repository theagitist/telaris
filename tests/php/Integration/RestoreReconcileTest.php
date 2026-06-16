<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: federation reconciliation after a wipe_all restore (q22).
 *
 * A wipe_all restore mints fresh constellations from the dump: import_source is
 * re-applied but read_only / mirrored_from_peer_id come back as defaults
 * (FALSE / NULL), and the instance-local governance tables survive the wipe.
 * federation_reconcile_after_restore() runs at the end of the restore to
 *   - drop mirrors the source retracted or whose peer is banned (consent), and
 *   - relink surviving mirrors (read_only + mirrored_from_peer_id) so the q21
 *     guard protects them and the pull machinery recognizes them again.
 *
 * These tests reproduce the post-replay state directly (constellation with
 * import_source set, read_only FALSE, mirrored_from_peer_id NULL) and run the
 * reconciliation scoped to their own fixture ids, mirroring the direct-call
 * style of GalaxyMirrorTest / GalaxyUnmirrorTest. A real end-to-end restore is
 * not used: backup_restore_from_file('wipe_all') would wipe the shared dev DB.
 *
 * Fixtures: ephemeral *.example.invalid hosts, delete by id in teardown.
 */
final class RestoreReconcileTest extends TestCase
{
    /** @var list<int> */
    private array $cids = [];
    /** @var list<int> */
    private array $peerIds = [];
    /** @var list<string> */
    private array $blEntries = [];
    /** @var list<string> */
    private array $retractedSlugs = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/restore_reconcile.php';
    }

    protected function setUp(): void
    {
        db_ensure_constellations_import_source_column();
        db_ensure_federation_attribution_columns();
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_pluriverse_blacklist_table();
        db_ensure_retracted_galaxies_table();
        db_ensure_remote_retractions_table();
        db_ensure_pluriverse_log_tables();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        foreach ($this->retractedSlugs as $slug) {
            $pdo->prepare("DELETE FROM retracted_galaxies WHERE slug = :s")->execute([':s' => $slug]);
        }
        foreach ($this->peerIds as $pid) {
            // peer FK CASCADEs galaxy_subscriptions + remote_retractions.
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $pid]);
        }
        foreach ($this->blEntries as $v) {
            $pdo->prepare("DELETE FROM pluriverse_blacklist WHERE entry_value = :v")->execute([':v' => $v]);
        }
        $this->cids = [];
        $this->peerIds = [];
        $this->blEntries = [];
        $this->retractedSlugs = [];
    }

    private function makePeer(string $host, string $trustState = 'whitelisted'): int
    {
        $pdo = getDB();
        $kp = sodium_crypto_sign_keypair();
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, trust_state)
                       VALUES (:h, :u, :e, :k, 'q22 test', :ts) RETURNING id");
        $ins->execute([':h' => $host, ':u' => "https://$host", ':e' => "https://$host/api/pluriverse", ':k' => sodium_crypto_sign_publickey($kp), ':ts' => $trustState]);
        $pid = (int)$ins->fetchColumn();
        $this->peerIds[] = $pid;
        return $pid;
    }

    /**
     * Reproduce a restored federation mirror: import_source set, but read_only
     * FALSE and mirrored_from_peer_id NULL (what wipe_all replay leaves behind).
     * Adds one node so a drop has content to remove.
     */
    private function makeRestoredMirror(string $originHost, string $remoteSlug): int
    {
        $sfx = bin2hex(random_bytes(3));
        $cid = db_create_constellation('Mirror ' . $remoteSlug . ' ' . $sfx, '', 'm-' . $sfx, 'cosmic');
        $this->cids[] = $cid;
        db_create_node('n-' . $sfx, null, null, '{}', $cid);
        $importSource = json_encode([
            'kind' => 'federation', 'origin_host' => $originHost,
            'remote_slug' => $remoteSlug, 'sequence' => 1, 'content_hash' => str_repeat('a', 64),
        ], JSON_UNESCAPED_SLASHES);
        db_set_constellation_import_source($cid, $importSource);
        // Defensive: confirm the simulated post-replay state.
        getDB()->prepare("UPDATE constellations SET read_only = FALSE, mirrored_from_peer_id = NULL WHERE id = :c")->execute([':c' => $cid]);
        return $cid;
    }

    private function constellationExists(int $cid): bool
    {
        return (bool)getDB()->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn();
    }

    /* ---- Bug 1: resurrection of withdrawn / banned content ------------- */

    public function testRemoteRetractedMirrorIsDropped(): void
    {
        $host = 'origin-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $peerId = $this->makePeer($host);
        $cid = $this->makeRestoredMirror($host, 'coastal-plants');
        federation_unmirror_record_remote_retraction($peerId, 'coastal-plants', gmdate('c'), 'community withdrew', 'jws');

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertFalse($this->constellationExists($cid), 'retracted mirror must not survive restore');
        $this->assertSame('restore-remote-retracted', $report['dropped'][0]['reason'] ?? null);
        $this->assertContains('coastal-plants', array_column($report['dropped'], 'remote_slug'));
    }

    public function testBlacklistedPeerMirrorIsDropped(): void
    {
        $host = 'banned-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $this->makePeer($host);
        getDB()->prepare("INSERT INTO pluriverse_blacklist (entry_type, entry_value, reason, added_at) VALUES ('hostname', :v, 'abuse', NOW())")
            ->execute([':v' => $host]);
        $this->blEntries[] = $host;
        $cid = $this->makeRestoredMirror($host, 'tide-pools');

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertFalse($this->constellationExists($cid), 'banned peer mirror must not survive restore');
        $this->assertSame('restore-pluriverse-blacklist', $report['dropped'][0]['reason'] ?? null);
    }

    public function testBlockedPeerMirrorIsDropped(): void
    {
        $host = 'blocked-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $this->makePeer($host, 'blocked');
        $cid = $this->makeRestoredMirror($host, 'reef-fish');

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertFalse($this->constellationExists($cid));
        $this->assertSame('restore-peer-blocked', $report['dropped'][0]['reason'] ?? null);
    }

    public function testOwnRetractedSlugMirrorIsDropped(): void
    {
        $host = 'origin-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $this->makePeer($host);
        $cid = $this->makeRestoredMirror($host, 'kelp');
        $slug = (string)getDB()->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();
        getDB()->prepare("INSERT INTO retracted_galaxies (constellation_id, slug, retracted_by, reason, retraction_jws) VALUES (:c, :s, 'admin', 'r', 'jws')")
            ->execute([':c' => $cid, ':s' => $slug]);
        $this->retractedSlugs[] = $slug;

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertFalse($this->constellationExists($cid));
        $this->assertSame('restore-retracted', $report['dropped'][0]['reason'] ?? null);
    }

    /* ---- Bug 2: surviving mirror relinked ------------------------------ */

    public function testSurvivingMirrorIsRelinked(): void
    {
        $host = 'good-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $peerId = $this->makePeer($host);
        $cid = $this->makeRestoredMirror($host, 'mangroves');
        $localSlug = (string)getDB()->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();
        // The surviving subscription: after the wipe deletes the old
        // constellation, the FK (ON DELETE SET NULL) nulls its local pointer.
        getDB()->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, is_active) VALUES (:p, 'mangroves', NULL, TRUE)")
            ->execute([':p' => $peerId]);

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertTrue($this->constellationExists($cid), 'valid mirror survives');
        $this->assertContains($localSlug, $report['relinked']);

        $row = getDB()->prepare("SELECT read_only, mirrored_from_peer_id FROM constellations WHERE id = :c");
        $row->execute([':c' => $cid]);
        $c = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$c['read_only'], 'relinked mirror is read_only again');
        $this->assertSame($peerId, (int)$c['mirrored_from_peer_id'], 'mirrored_from_peer_id restored');

        $subCid = (int)getDB()->query("SELECT local_constellation_id FROM galaxy_subscriptions WHERE peer_id = $peerId AND remote_slug = 'mangroves'")->fetchColumn();
        $this->assertSame($cid, $subCid, 'stale subscription repointed to the restored constellation');
    }

    public function testOrphanMirrorWithNoPeerKeptReadOnly(): void
    {
        $host = 'gone-' . bin2hex(random_bytes(4)) . '.example.invalid'; // no peer row
        $cid = $this->makeRestoredMirror($host, 'lost-galaxy');
        $localSlug = (string)getDB()->query("SELECT slug FROM constellations WHERE id = $cid")->fetchColumn();

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertTrue($this->constellationExists($cid), 'orphan mirror is kept, not dropped');
        $this->assertContains($localSlug, $report['orphaned']);
        $row = getDB()->prepare("SELECT read_only, mirrored_from_peer_id FROM constellations WHERE id = :c");
        $row->execute([':c' => $cid]);
        $c = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$c['read_only'], 'orphan kept read_only so q21 still protects it');
        $this->assertNull($c['mirrored_from_peer_id']);
    }

    public function testMocambosImportIsRestampedReadOnly(): void
    {
        $sfx = bin2hex(random_bytes(3));
        $cid = db_create_constellation('Mocambos ' . $sfx, '', 'moc-' . $sfx, 'cosmic');
        $this->cids[] = $cid;
        db_set_constellation_import_source($cid, json_encode(['kind' => 'mocambos', 'source' => 'baobaxia'], JSON_UNESCAPED_SLASHES));
        getDB()->prepare("UPDATE constellations SET read_only = FALSE WHERE id = :c")->execute([':c' => $cid]);

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertContains('moc-' . $sfx, $report['restamped']);
        $this->assertSame(1, (int)getDB()->query("SELECT read_only FROM constellations WHERE id = $cid")->fetchColumn());
    }

    /* ---- Regression: normal local galaxy untouched --------------------- */

    public function testNormalLocalGalaxyStaysWritable(): void
    {
        $sfx = bin2hex(random_bytes(3));
        $cid = db_create_constellation('Authored ' . $sfx, '', 'auth-' . $sfx, 'cosmic');
        $this->cids[] = $cid;

        $report = federation_reconcile_after_restore([$cid]);
        $this->assertTrue($this->constellationExists($cid));
        $this->assertSame([], $report['dropped']);
        $this->assertNotContains('auth-' . $sfx, $report['relinked']);
        $this->assertNotContains('auth-' . $sfx, $report['restamped']);
        $this->assertSame(0, (int)getDB()->query("SELECT read_only FROM constellations WHERE id = $cid")->fetchColumn(), 'authored galaxy stays writable');
    }
}
