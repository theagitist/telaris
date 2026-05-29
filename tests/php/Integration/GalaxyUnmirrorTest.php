<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5d-iv mirror drop / fossilize / propagation.
 *
 * Drives the unmirror primitives end to end with ephemeral keypairs (no
 * instance key, no network): a mirror constellation is set up by hand,
 * then exercised through the drop / fossilize / apply-retracted /
 * apply-withdrawn paths. Adversarial cases (bad signature, wrong origin,
 * slug mismatch, missing subscription) are reported in `invalid` rather
 * than aborting the whole list.
 *
 * Spec: Stage 5 galaxy publish design (5d-iv).
 */
final class GalaxyUnmirrorTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';
    private string $sk = '';
    private string $pk = '';
    /** @var list<int> */
    private array $cids = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_unmirror.php';
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_retraction.php';
        require_once dirname(__DIR__, 3) . '/inc/federation/jws.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_federation_attribution_columns();
        db_ensure_remote_retractions_table();
        $kp = sodium_crypto_sign_keypair();
        $this->sk = sodium_crypto_sign_secretkey($kp);
        $this->pk = sodium_crypto_sign_publickey($kp);
        $sfx = bin2hex(random_bytes(4));
        $this->host = "origin-$sfx.example.invalid";
        $pdo = getDB();
        $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, :k, 'unmirror test origin')")
            ->execute([
                ':h' => $this->host,
                ':u' => "https://{$this->host}",
                ':e' => "https://{$this->host}/api/pluriverse",
                ':k' => $this->pk,
            ]);
        $this->peerId = (int)$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE kr FROM keyword_relations kr
                           WHERE kr.keyword_a_id IN (SELECT id FROM keywords WHERE constellation_id = :c1)
                              OR kr.keyword_b_id IN (SELECT id FROM keywords WHERE constellation_id = :c2)")
                ->execute([':c1' => $cid, ':c2' => $cid]);
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        if ($this->peerId > 0) {
            // Peer FK CASCADEs galaxy_subscriptions + remote_retractions.
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]);
        }
    }

    /**
     * Build a minimal mirror constellation + subscription pair, the same shape
     * federation_mirror_materialize would produce. Returns the local
     * constellation id; the caller tracks $this->cids for cleanup.
     */
    private function seedMirror(string $remoteSlug, int $sequence = 1): int
    {
        $pdo = getDB();
        db_ensure_federation_attribution_columns();
        $localSlug = 'mirror-' . bin2hex(random_bytes(3));
        $cid = db_create_constellation('Mirror ' . $remoteSlug, '', $localSlug, 'cosmic');
        $this->cids[] = $cid;
        $importSource = json_encode([
            'kind' => 'federation',
            'origin_host' => $this->host,
            'remote_slug' => $remoteSlug,
            'sequence' => $sequence,
            'content_hash' => str_repeat('a', 64),
        ], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("
            UPDATE constellations
            SET mirrored_from_peer_id = :p, import_source = :imp, read_only = TRUE
            WHERE id = :id
        ")->execute([':p' => $this->peerId, ':imp' => $importSource, ':id' => $cid]);
        $pdo->prepare("
            INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, last_received_sequence, last_content_hash, is_active)
            VALUES (:p, :s, :cid, :seq, :hash, TRUE)
        ")->execute([':p' => $this->peerId, ':s' => $remoteSlug, ':cid' => $cid, ':seq' => $sequence, ':hash' => str_repeat('a', 64)]);
        return $cid;
    }

    /** Sign a retraction envelope with this test's ephemeral key. */
    private function signRetraction(string $slug, string $reason = ''): string
    {
        $payload = federation_retraction_payload($this->host, $slug, gmdate('c'), 1, $reason);
        return federation_jws_sign($payload, FEDERATION_RETRACTION_TYP, $this->host . ':fp', $this->sk);
    }

    /* ---- drop / fossilize primitives ----------------------------------- */

    public function testDropRemovesMirrorAndSubscription(): void
    {
        $cid = $this->seedMirror('coastal-plants');
        $res = federation_unmirror_drop($this->peerId, 'coastal-plants', 'origin_retraction');
        $this->assertTrue($res['ok']);
        $this->assertSame($cid, $res['dropped_constellation_id']);

        $this->cids = array_values(array_diff($this->cids, [$cid])); // we just deleted it
        $exists = (int)getDB()->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn();
        $this->assertSame(0, $exists);

        $subCount = (int)getDB()->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = {$this->peerId} AND remote_slug = 'coastal-plants'")->fetchColumn();
        $this->assertSame(0, $subCount, 'subscription is removed so re-subscription is an explicit operator action');
    }

    public function testDropIsIdempotent(): void
    {
        $cid = $this->seedMirror('coastal-plants');
        federation_unmirror_drop($this->peerId, 'coastal-plants', 'origin_retraction');
        $this->cids = array_values(array_diff($this->cids, [$cid]));
        $res = federation_unmirror_drop($this->peerId, 'coastal-plants', 'origin_retraction');
        $this->assertTrue($res['ok'], 'second call is a no-op success');
        $this->assertNull($res['dropped_constellation_id']);
    }

    public function testDropRefusesNonMirrorConstellation(): void
    {
        // A subscription that mistakenly points at an authored galaxy must not
        // be wiped. (Should never happen in normal flows; defence-in-depth.)
        $pdo = getDB();
        $localSlug = 'authored-' . bin2hex(random_bytes(3));
        $cid = db_create_constellation('Authored', '', $localSlug, 'cosmic');
        $this->cids[] = $cid;
        $pdo->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, is_active) VALUES (:p, :s, :cid, TRUE)")
            ->execute([':p' => $this->peerId, ':s' => 'mismatched', ':cid' => $cid]);

        $res = federation_unmirror_drop($this->peerId, 'mismatched', 'origin_retraction');
        $this->assertFalse($res['ok']);
        $this->assertSame('not_a_mirror_of_this_peer', $res['error']);
        // Authored constellation untouched.
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn());
    }

    public function testFossilizeMarksInactiveWithReason(): void
    {
        $cid = $this->seedMirror('coastal-plants');
        $res = federation_unmirror_fossilize($this->peerId, 'coastal-plants', 'origin_withdrawal');
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['fossilized']);

        $row = getDB()->prepare("SELECT is_active, fossilized_reason, fossilized_at FROM galaxy_subscriptions WHERE peer_id = :p AND remote_slug = 'coastal-plants'");
        $row->execute([':p' => $this->peerId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$r['is_active']);
        $this->assertSame('origin_withdrawal', $r['fossilized_reason']);
        $this->assertNotNull($r['fossilized_at']);

        // Mirror constellation untouched: fossilize keeps prior consent visible.
        $this->assertSame(1, (int)getDB()->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn());
    }

    public function testFossilizePreservesOriginalReason(): void
    {
        $this->seedMirror('coastal-plants');
        federation_unmirror_fossilize($this->peerId, 'coastal-plants', 'origin_withdrawal');
        federation_unmirror_fossilize($this->peerId, 'coastal-plants', 'second_call_reason');
        $r = getDB()->query("SELECT fossilized_reason FROM galaxy_subscriptions WHERE peer_id = {$this->peerId} AND remote_slug = 'coastal-plants'")->fetchColumn();
        $this->assertSame('origin_withdrawal', $r, 'first-reason wins; idempotent fossilize does not overwrite');
    }

    /* ---- apply retracted.json digest ----------------------------------- */

    public function testApplyRetractedDropsMirrorAndRecords(): void
    {
        $cid = $this->seedMirror('coastal-plants');
        $jws = $this->signRetraction('coastal-plants', 'community changed mind');
        $items = [['slug' => 'coastal-plants', 'retracted_at' => gmdate('c'), 'reason' => 'community changed mind', 'retraction_jws' => $jws]];

        $res = federation_pull_apply_retracted($this->peerId, $this->host, $items);
        $this->assertSame(1, $res['processed']);
        $this->assertSame(['coastal-plants'], $res['dropped']);
        $this->assertSame(['coastal-plants'], $res['recorded']);
        $this->assertSame([], $res['invalid']);
        $this->cids = array_values(array_diff($this->cids, [$cid]));

        // remote_retractions row is there + remembers the envelope.
        $row = getDB()->prepare("SELECT reason, retraction_jws FROM remote_retractions WHERE peer_id = :p AND remote_slug = 'coastal-plants'");
        $row->execute([':p' => $this->peerId]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('community changed mind', $r['reason']);
        $this->assertSame($jws, $r['retraction_jws']);
        $this->assertTrue(federation_unmirror_is_remote_retracted($this->peerId, 'coastal-plants'));

        // Subscription gone; constellation gone.
        $this->assertSame(0, (int)getDB()->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = {$this->peerId} AND remote_slug = 'coastal-plants'")->fetchColumn());
        $this->assertSame(0, (int)getDB()->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn());
    }

    public function testApplyRetractedRecordsWithoutMirror(): void
    {
        // A retraction we have no mirror for is still recorded (so future
        // re-subscription attempts can be refused). `dropped` stays empty.
        $jws = $this->signRetraction('never-mirrored');
        $items = [['slug' => 'never-mirrored', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $jws]];
        $res = federation_pull_apply_retracted($this->peerId, $this->host, $items);
        $this->assertSame(1, $res['processed']);
        $this->assertSame([], $res['dropped']);
        $this->assertSame(['never-mirrored'], $res['recorded']);
        $this->assertTrue(federation_unmirror_is_remote_retracted($this->peerId, 'never-mirrored'));
    }

    public function testApplyRetractedRejectsBadSignature(): void
    {
        $cid = $this->seedMirror('coastal-plants');
        $kp2 = sodium_crypto_sign_keypair();
        $payload = federation_retraction_payload($this->host, 'coastal-plants', gmdate('c'), 1, '');
        $jws = federation_jws_sign($payload, FEDERATION_RETRACTION_TYP, $this->host . ':fp', sodium_crypto_sign_secretkey($kp2));
        $items = [['slug' => 'coastal-plants', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $jws]];

        $res = federation_pull_apply_retracted($this->peerId, $this->host, $items);
        $this->assertSame([], $res['dropped'], 'bad sig: mirror untouched');
        $this->assertSame([], $res['recorded']);
        $this->assertCount(1, $res['invalid']);
        $this->assertSame('coastal-plants', $res['invalid'][0]['slug']);
        $this->assertSame('signature_invalid', $res['invalid'][0]['reason']);

        // Mirror + subscription still intact.
        $this->assertSame(1, (int)getDB()->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn());
    }

    public function testApplyRetractedRejectsOriginMismatch(): void
    {
        $this->seedMirror('coastal-plants');
        $payload = federation_retraction_payload('someone-else.invalid', 'coastal-plants', gmdate('c'), 1, '');
        $jws = federation_jws_sign($payload, FEDERATION_RETRACTION_TYP, $this->host . ':fp', $this->sk);
        $items = [['slug' => 'coastal-plants', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $jws]];

        $res = federation_pull_apply_retracted($this->peerId, $this->host, $items);
        $this->assertSame('origin_host_mismatch', $res['invalid'][0]['reason'] ?? '');
        $this->assertSame([], $res['dropped']);
    }

    public function testApplyRetractedRejectsSlugMismatch(): void
    {
        $this->seedMirror('coastal-plants');
        // Sign retraction for slug A, advertise it under slug B in the digest.
        $jws = $this->signRetraction('a-different-slug');
        $items = [['slug' => 'coastal-plants', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $jws]];
        $res = federation_pull_apply_retracted($this->peerId, $this->host, $items);
        $this->assertSame('slug_mismatch', $res['invalid'][0]['reason'] ?? '');
        $this->assertSame([], $res['dropped']);
    }

    public function testApplyRetractedSurvivesOneBadEntry(): void
    {
        // A bad entry in the middle of the list must not stop legitimate
        // entries before/after from being honoured.
        $this->seedMirror('first-good');
        $cid2 = $this->seedMirror('second-good');
        $kpBad = sodium_crypto_sign_keypair();
        $payloadBad = federation_retraction_payload($this->host, 'middle-bad', gmdate('c'), 1, '');
        $badJws = federation_jws_sign($payloadBad, FEDERATION_RETRACTION_TYP, $this->host . ':fp', sodium_crypto_sign_secretkey($kpBad));

        $items = [
            ['slug' => 'first-good', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $this->signRetraction('first-good')],
            ['slug' => 'middle-bad', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $badJws],
            ['slug' => 'second-good', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $this->signRetraction('second-good')],
        ];
        $res = federation_pull_apply_retracted($this->peerId, $this->host, $items);
        $this->assertSame(3, $res['processed']);
        $this->assertSame(['first-good', 'second-good'], $res['dropped']);
        $this->assertSame(['first-good', 'second-good'], $res['recorded']);
        $this->assertCount(1, $res['invalid']);
        $this->assertSame('middle-bad', $res['invalid'][0]['slug']);
        $this->cids = array_values(array_diff($this->cids, [$cid2]));
    }

    public function testApplyRetractedHonoursPreviousKeyRotation(): void
    {
        // The peer rotated. The cached previous_public_key is the one that
        // signed an in-flight retraction; current_public_key is the new one.
        $kpOld = sodium_crypto_sign_keypair();
        $skOld = sodium_crypto_sign_secretkey($kpOld);
        $pkOld = sodium_crypto_sign_publickey($kpOld);
        getDB()->prepare("UPDATE peers SET previous_public_key = :prev WHERE id = :id")
            ->execute([':prev' => $pkOld, ':id' => $this->peerId]);

        $cid = $this->seedMirror('coastal-plants');
        $payload = federation_retraction_payload($this->host, 'coastal-plants', gmdate('c'), 1, '');
        $jws = federation_jws_sign($payload, FEDERATION_RETRACTION_TYP, $this->host . ':fp', $skOld);
        $items = [['slug' => 'coastal-plants', 'retracted_at' => gmdate('c'), 'reason' => '', 'retraction_jws' => $jws]];

        $res = federation_pull_apply_retracted($this->peerId, $this->host, $items);
        $this->assertSame(['coastal-plants'], $res['dropped'], 'rotation grace: previous key still honoured');
        $this->cids = array_values(array_diff($this->cids, [$cid]));
    }

    /* ---- withdrawn -------------------------------------------------------- */

    public function testApplyWithdrawnFossilizesAndSkipsUnknown(): void
    {
        $this->seedMirror('slug-a');
        $this->seedMirror('slug-b');
        // slug-c is in withdrawn[] but never subscribed: row count == 0 in the
        // UPDATE, fossilize returns ok+fossilized=false, helper skips it.
        $res = federation_pull_apply_withdrawn($this->peerId, ['slug-a', 'slug-c', 'slug-b']);
        $this->assertSame(['slug-a', 'slug-b'], $res['fossilized']);

        $rows = getDB()->prepare("SELECT remote_slug, is_active, fossilized_reason FROM galaxy_subscriptions WHERE peer_id = :p ORDER BY remote_slug");
        $rows->execute([':p' => $this->peerId]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->assertSame(0, (int)$r['is_active']);
            $this->assertSame('origin_withdrawal', $r['fossilized_reason']);
        }
    }

    /* ---- 6a uniform-drop primitive ------------------------------------- */

    /** Seed a publish-whitelist row offering one authored galaxy to this peer. */
    private function seedPublishOffer(): int
    {
        $pdo = getDB();
        db_ensure_galaxy_publish_whitelist_table();
        $localSlug = 'authored-' . bin2hex(random_bytes(3));
        $cid = db_create_constellation('Authored offer', '', $localSlug, 'cosmic');
        $this->cids[] = $cid;
        $pdo->prepare("INSERT INTO galaxy_publish_whitelist (peer_id, constellation_id, added_by) VALUES (:p, :c, 'test')")
            ->execute([':p' => $this->peerId, ':c' => $cid]);
        return $cid;
    }

    public function testDropAllRemovesEveryMirrorAndClearsPublishOffer(): void
    {
        $cidA = $this->seedMirror('slug-a');
        $cidB = $this->seedMirror('slug-b');
        $this->seedPublishOffer();
        // has_active_whitelist is TRUE while either side is populated.
        db_recompute_peer_active_whitelist($this->peerId);

        $res = federation_unmirror_drop_all_for_peer($this->peerId, 'pluriverse-blacklist');
        $this->assertTrue($res['ok']);
        sort($res['dropped']);
        $this->assertSame(['slug-a', 'slug-b'], $res['dropped']);
        $this->assertSame([], $res['refused']);
        $this->assertSame(1, $res['publish_entries_cleared']);

        $this->cids = array_values(array_diff($this->cids, [$cidA, $cidB]));
        $pdo = getDB();
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM constellations WHERE id IN ($cidA, $cidB)")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = {$this->peerId}")->fetchColumn(), 'all subscriptions removed');
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_publish_whitelist WHERE peer_id = {$this->peerId}")->fetchColumn());
        $hasWl = (int)$pdo->query("SELECT has_active_whitelist FROM peers WHERE id = {$this->peerId}")->fetchColumn();
        $this->assertSame(0, $hasWl, 'has_active_whitelist recomputed to FALSE after teardown');
    }

    public function testDropAllIncludesFossilizedMirrors(): void
    {
        // A fossilized (withdrawn, prior-consent-stands) mirror is still torn
        // down by a hard untrust: revocation/blacklist overrides the consent.
        $cid = $this->seedMirror('slug-foss');
        federation_unmirror_fossilize($this->peerId, 'slug-foss', 'origin_withdrawal');
        $this->assertSame(0, (int)getDB()->query("SELECT is_active FROM galaxy_subscriptions WHERE peer_id = {$this->peerId} AND remote_slug = 'slug-foss'")->fetchColumn());

        $res = federation_unmirror_drop_all_for_peer($this->peerId, 'pluriverse-revoked');
        $this->assertSame(['slug-foss'], $res['dropped']);
        $this->cids = array_values(array_diff($this->cids, [$cid]));
        $this->assertSame(0, (int)getDB()->query("SELECT COUNT(*) FROM constellations WHERE id = $cid")->fetchColumn());
    }

    public function testDropAllIsIdempotent(): void
    {
        $cid = $this->seedMirror('slug-a');
        federation_unmirror_drop_all_for_peer($this->peerId, 'local-blacklist');
        $this->cids = array_values(array_diff($this->cids, [$cid]));

        $res = federation_unmirror_drop_all_for_peer($this->peerId, 'local-blacklist');
        $this->assertTrue($res['ok']);
        $this->assertSame([], $res['dropped'], 'nothing left to drop on the second sweep');
        $this->assertSame(0, $res['publish_entries_cleared']);
    }

    public function testDropAllLeavesOtherPeersUntouched(): void
    {
        $cidMine = $this->seedMirror('slug-mine');

        // A second, unrelated peer with its own mirror.
        $pdo = getDB();
        $otherKp = sodium_crypto_sign_keypair();
        $otherHost = 'other-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label) VALUES (:h, :u, :e, :k, 'other')")
            ->execute([':h' => $otherHost, ':u' => "https://$otherHost", ':e' => "https://$otherHost/api/pluriverse", ':k' => sodium_crypto_sign_publickey($otherKp)]);
        $otherPeerId = (int)$pdo->lastInsertId();
        $otherSlug = 'mirror-' . bin2hex(random_bytes(3));
        $otherCid = db_create_constellation('Other mirror', '', $otherSlug, 'cosmic');
        $importSource = json_encode(['kind' => 'federation', 'origin_host' => $otherHost, 'remote_slug' => 'slug-other', 'sequence' => 1, 'content_hash' => str_repeat('b', 64)], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE constellations SET mirrored_from_peer_id = :p, import_source = :imp, read_only = TRUE WHERE id = :id")
            ->execute([':p' => $otherPeerId, ':imp' => $importSource, ':id' => $otherCid]);
        $pdo->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, is_active) VALUES (:p, 'slug-other', :cid, TRUE)")
            ->execute([':p' => $otherPeerId, ':cid' => $otherCid]);

        try {
            federation_unmirror_drop_all_for_peer($this->peerId, 'pluriverse-blacklist');
            $this->cids = array_values(array_diff($this->cids, [$cidMine]));

            // The other peer's mirror + subscription survive.
            $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM constellations WHERE id = $otherCid")->fetchColumn());
            $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = $otherPeerId")->fetchColumn());
        } finally {
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $otherCid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $otherCid]);
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $otherPeerId]);
        }
    }

    public function testDropAllEmitsAuditRow(): void
    {
        db_ensure_pluriverse_log_tables();
        $cid = $this->seedMirror('slug-a');
        federation_unmirror_drop_all_for_peer($this->peerId, 'pluriverse-revoked');
        $this->cids = array_values(array_diff($this->cids, [$cid]));

        $row = getDB()->prepare("SELECT outcome, details_summary FROM pluriverse_log WHERE event_type = 'peer_untrust_drop' AND target = :h ORDER BY id DESC LIMIT 1");
        $row->execute([':h' => $this->host]);
        $log = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log, 'a peer_untrust_drop audit row is written');
        $this->assertSame('success', $log['outcome']);
        $this->assertStringContainsString('reason=pluriverse-revoked', $log['details_summary']);
        $this->assertStringContainsString('mirrors_dropped=1', $log['details_summary']);
    }
}
