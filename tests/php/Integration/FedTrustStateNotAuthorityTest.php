<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: trust_state is a LABEL, not an authority grant.
 *
 * Pins the invariant tracked in BACKLOG ^fed-trust-state-not-authority. On peer
 * re-discovery, pluriverse_pull restores trust_state='whitelisted' from a
 * surviving completed handshake, keyed on hostname (the 8ae3963 behaviour).
 * That is only safe because no authority gates on trust_state alone:
 *   - publish authority gates on galaxy_publish_whitelist (federation_published_for_peer)
 *   - subscribe / materialize authority gates on galaxy_subscriptions
 *   - inbound authentication gates on peers.public_key (HTTP Signature), not trust_state
 * (peer_keys is vestigial here: it is only ever INSERTed by the handshake and
 * never read for auth, so it gates nothing in the current code.)
 *
 * The risk pinned here: a blacklisted-then-rediscovered peer must NOT regain
 * any access just because its trust_state label is restored to 'whitelisted'.
 * The block teardown (6a) clears the whitelist + subscriptions; re-discovery
 * restores only the label, so the peer rides nothing.
 *
 * This scenario was observed live during the 2026-06-04 Stage 6 block demo:
 * starmaps held telaris as trust_state='whitelisted' but the galaxy pull still
 * depended on peers.public_key + the subscription, not on the label.
 *
 * Fixtures follow the stage-5/6 convention: ephemeral keypairs, synthetic
 * *.example.invalid hostnames, delete by id in teardown (NEVER by real
 * hostname, per the 8ae3963 regression).
 *
 * Spec: Stage 6 trust revocation design; v10 § State-change propagation.
 */
final class FedTrustStateNotAuthorityTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';
    /** @var list<int> */
    private array $cids = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_publish.php';
        require_once dirname(__DIR__, 3) . '/inc/federation/peer_block.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_published_galaxies_table();
        db_ensure_galaxy_publish_whitelist_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_federation_attribution_columns();
        db_ensure_pluriverse_log_tables();

        $kp = sodium_crypto_sign_keypair();
        $sfx = bin2hex(random_bytes(4));
        $this->host = "trustlabel-$sfx.example.invalid";
        $pdo = getDB();
        $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, trust_state)
                       VALUES (:h, :u, :e, :k, 'trust-label test', 'whitelisted')")
            ->execute([
                ':h' => $this->host,
                ':u' => "https://{$this->host}",
                ':e' => "https://{$this->host}/api/pluriverse",
                ':k' => sodium_crypto_sign_publickey($kp),
            ]);
        $this->peerId = (int)$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM published_galaxies WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM galaxy_publish_whitelist WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        if ($this->peerId > 0) {
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]); // CASCADEs whitelist + subs
        }
    }

    /** Create an authored (import_source NULL) galaxy with a current published row. Returns [cid, slug]. */
    private function mkAuthoredPublished(): array
    {
        $pdo = getDB();
        $slug = 'authored-' . bin2hex(random_bytes(4));
        $pdo->prepare("INSERT INTO constellations (name, slug, `type`, theme, import_source)
                       VALUES (:n, :s, 'galaxy', 'cosmic', NULL)")
            ->execute([':n' => $slug, ':s' => $slug]);
        $cid = (int)$pdo->lastInsertId();
        $this->cids[] = $cid;
        $pdo->prepare("INSERT INTO published_galaxies
                (constellation_id, slug, published_sequence, content_hash, envelope_jws, is_current)
                VALUES (:c, :s, 1, :h, 'aaa.bbb.ccc', TRUE)")
            ->execute([':c' => $cid, ':s' => $slug, ':h' => str_repeat('0', 64)]);
        return [$cid, $slug];
    }

    private function offerToPeer(int $cid): void
    {
        getDB()->prepare("INSERT INTO galaxy_publish_whitelist (peer_id, constellation_id, added_by) VALUES (:p, :c, 'test')")
            ->execute([':p' => $this->peerId, ':c' => $cid]);
        db_recompute_peer_active_whitelist($this->peerId);
    }

    /** Materialize a read-only mirror + active subscription for this peer. Returns mirror cid. */
    private function seedSubscriptionMirror(string $remoteSlug): int
    {
        $pdo = getDB();
        $localSlug = 'mirror-' . bin2hex(random_bytes(3));
        $cid = db_create_constellation('Mirror ' . $remoteSlug, '', $localSlug, 'cosmic');
        $this->cids[] = $cid;
        $importSource = json_encode([
            'kind' => 'federation', 'origin_host' => $this->host,
            'remote_slug' => $remoteSlug, 'sequence' => 1, 'content_hash' => str_repeat('a', 64),
        ], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE constellations SET mirrored_from_peer_id = :p, import_source = :imp, read_only = TRUE WHERE id = :id")
            ->execute([':p' => $this->peerId, ':imp' => $importSource, ':id' => $cid]);
        $pdo->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, last_received_sequence, last_content_hash, is_active)
                       VALUES (:p, :s, :cid, 1, :hash, TRUE)")
            ->execute([':p' => $this->peerId, ':s' => $remoteSlug, ':cid' => $cid, ':hash' => str_repeat('a', 64)]);
        return $cid;
    }

    private function activeSubCount(): int
    {
        $s = getDB()->prepare("SELECT COUNT(*) FROM galaxy_subscriptions WHERE peer_id = :p AND is_active = TRUE");
        $s->execute([':p' => $this->peerId]);
        return (int)$s->fetchColumn();
    }

    private function offeredSlugs(): array
    {
        return array_column(federation_published_for_peer($this->peerId), 'slug');
    }

    /**
     * trust_state='whitelisted' alone offers nothing: publish authority is the
     * whitelist, not the label. Adding the whitelist row is what grants it.
     */
    public function testWhitelistedLabelAloneGrantsNoPublishAccess(): void
    {
        [$cid, $slug] = $this->mkAuthoredPublished();

        $this->assertSame(
            'whitelisted',
            (string)getDB()->query("SELECT trust_state FROM peers WHERE id = {$this->peerId}")->fetchColumn(),
            'precondition: peer carries the whitelisted label'
        );
        $this->assertNotContains($slug, $this->offeredSlugs(), 'whitelisted label alone offers nothing');

        $this->offerToPeer($cid);
        $this->assertContains($slug, $this->offeredSlugs(), 'the whitelist row, not the label, is what grants the offer');
    }

    /**
     * The core invariant: a block tears down the whitelist + subscriptions, and
     * a later trust_state='whitelisted' restore (the re-discovery label restore)
     * brings back NO authority. A blacklisted-then-rediscovered peer rides nothing.
     */
    public function testBlockThenRelabelWhitelistedRestoresNoAuthority(): void
    {
        [$cid, $slug] = $this->mkAuthoredPublished();
        $this->offerToPeer($cid);
        $mirrorCid = $this->seedSubscriptionMirror('remote-x');

        // Precondition: the peer is offered the galaxy and has an active subscription.
        $this->assertContains($slug, $this->offeredSlugs());
        $this->assertSame(1, $this->activeSubCount());

        // Block: 6a clears the whitelist + subscriptions and drops the mirror.
        $res = federation_peer_block($this->peerId, 'consent', 'consent withdrawn', 'admin@example.invalid');
        $this->assertTrue($res['ok']);
        $this->cids = array_values(array_diff($this->cids, [$mirrorCid])); // dropped; don't double-delete
        $this->assertNotContains($slug, $this->offeredSlugs(), 'block clears the publish offer');
        $this->assertSame(0, $this->activeSubCount(), 'block clears subscriptions');

        // Re-discovery restores ONLY the label.
        getDB()->prepare("UPDATE peers SET trust_state = 'whitelisted' WHERE id = :p")->execute([':p' => $this->peerId]);

        // The label flip restored zero authority.
        $this->assertNotContains($slug, $this->offeredSlugs(), 'relabel does NOT restore the publish offer');
        $this->assertSame(0, $this->activeSubCount(), 'relabel does NOT restore subscriptions');
        $this->assertSame(0, (int)getDB()->query("SELECT has_active_whitelist FROM peers WHERE id = {$this->peerId}")->fetchColumn());
        $this->assertNull(
            getDB()->query("SELECT id FROM constellations WHERE id = {$mirrorCid}")->fetchColumn() ?: null,
            'the dropped mirror is not resurrected by the relabel'
        );
    }

    /**
     * Whitelisting a peer does not subscribe it: subscribe authority is its own
     * table, so the galaxy pull (which only processes peers with an active
     * subscription) has nothing to materialize for a label-only peer.
     */
    public function testWhitelistingDoesNotCreateASubscription(): void
    {
        $this->assertSame(0, $this->activeSubCount(), 'a whitelisted peer has no subscription until one is explicitly added');
    }
}
