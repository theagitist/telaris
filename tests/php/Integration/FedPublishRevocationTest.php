<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: per-peer publish-revocation signal
 * (BACKLOG ^fed-revoke-vs-withdraw-diff).
 *
 * Covers the origin side (db_set_peer_publish_whitelist records a revocation
 * marker when a galaxy is removed from a peer's whitelist, and rescinds it on
 * re-offer) and the signed-list verification (origin/recipient binding,
 * freshness, rotation grace, tamper rejection) that gates the subscriber's
 * DROP-vs-fossilize branch.
 *
 * Verification cases sign with EPHEMERAL keypairs so they do not depend on the
 * instance identity key; one round-trip case exercises the real signer
 * (build_signed) and skips if the instance key is unavailable.
 *
 * Fixtures: synthetic *.example.invalid hostnames, delete by id in teardown.
 *
 * Spec: v10 § State-change propagation; Stage 6 trust revocation design.
 */
final class FedPublishRevocationTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';
    /** @var list<int> */
    private array $cids = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/publish_revocation.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_publish_whitelist_table();
        db_ensure_galaxy_publish_revocations_table();
        db_ensure_federation_attribution_columns();

        $sfx = bin2hex(random_bytes(4));
        $this->host = "revoke-$sfx.example.invalid";
        $pdo = getDB();
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, trust_state)
                       VALUES (:h, :u, :e, decode(:k, 'hex'), 'revocation test', 'whitelisted') RETURNING id");
        $ins->execute([
                ':h' => $this->host,
                ':u' => "https://{$this->host}",
                ':e' => "https://{$this->host}/api/pluriverse",
                ':k' => bin2hex(random_bytes(32)),
            ]);
        $this->peerId = (int)$ins->fetchColumn();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        if ($this->peerId > 0) {
            // CASCADEs galaxy_publish_whitelist + galaxy_publish_revocations.
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]);
        }
    }

    private function mkGalaxy(): array
    {
        $pdo = getDB();
        $slug = 'rev-authored-' . bin2hex(random_bytes(4));
        $insC = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme, import_source)
                       VALUES (:n, :s, 'galaxy', 'cosmic', NULL) RETURNING id");
        $insC->execute([':n' => $slug, ':s' => $slug]);
        $cid = (int)$insC->fetchColumn();
        $this->cids[] = $cid;
        return [$cid, $slug];
    }

    private function revokedSlugs(): array
    {
        return array_column(federation_publish_revocations_for_peer($this->peerId), 'slug');
    }

    private function payload(string $origin, string $recipient, array $slugs, ?string $generatedAt = null): array
    {
        return [
            'protocol_version' => FEDERATION_PUBLISH_REVOCATION_PROTOCOL,
            'origin_host' => $origin,
            'recipient_host' => $recipient,
            'generated_at' => $generatedAt ?? gmdate('c'),
            'revoked' => array_map(static fn(string $s): array => ['slug' => $s, 'revoked_at' => gmdate('c')], $slugs),
        ];
    }

    // ---- origin side: record on removal, rescind on re-offer ----

    public function testWhitelistRemovalRecordsRevocationAndReofferRescinds(): void
    {
        [$cid, $slug] = $this->mkGalaxy();

        // Offer: no revocation.
        $r = db_set_peer_publish_whitelist($this->peerId, [$cid], 'test');
        $this->assertTrue($r['ok']);
        $this->assertNotContains($slug, $this->revokedSlugs(), 'offering a galaxy records no revocation');

        // Remove from whitelist: revocation recorded for the slug.
        $r = db_set_peer_publish_whitelist($this->peerId, [], 'test');
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['removed']);
        $this->assertContains($slug, $this->revokedSlugs(), 'removing a galaxy from the peer whitelist records a revocation');

        // Re-offer: revocation rescinded (re-offering supersedes).
        $r = db_set_peer_publish_whitelist($this->peerId, [$cid], 'test');
        $this->assertTrue($r['ok']);
        $this->assertNotContains($slug, $this->revokedSlugs(), 're-offering the galaxy rescinds the revocation');
    }

    // ---- subscriber side: signed-list verification ----

    public function testVerifyAcceptsWellFormedSignedList(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        $jws = federation_jws_sign(
            $this->payload('origin.example.invalid', 'me.example.invalid', ['g1', 'g2']),
            FEDERATION_PUBLISH_REVOCATION_TYP, 'origin.example.invalid:fp', $sk
        );
        $v = federation_publish_revocation_verify($jws, $pk, '', 'origin.example.invalid', 'me.example.invalid');
        $this->assertTrue($v['ok'], $v['reason']);
        $this->assertSame(['g1', 'g2'], $v['slugs']);
    }

    public function testVerifyRejectsWrongRecipient(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $jws = federation_jws_sign(
            $this->payload('origin.example.invalid', 'me.example.invalid', ['g1']),
            FEDERATION_PUBLISH_REVOCATION_TYP, 'k', sodium_crypto_sign_secretkey($kp)
        );
        // A revoked.json minted for a different recipient must not apply to us.
        $v = federation_publish_revocation_verify($jws, sodium_crypto_sign_publickey($kp), '', 'origin.example.invalid', 'someone-else.example.invalid');
        $this->assertFalse($v['ok']);
        $this->assertSame('recipient_host_mismatch', $v['reason']);
    }

    public function testVerifyRejectsWrongOrigin(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $jws = federation_jws_sign(
            $this->payload('origin.example.invalid', 'me.example.invalid', ['g1']),
            FEDERATION_PUBLISH_REVOCATION_TYP, 'k', sodium_crypto_sign_secretkey($kp)
        );
        $v = federation_publish_revocation_verify($jws, sodium_crypto_sign_publickey($kp), '', 'other-origin.example.invalid', 'me.example.invalid');
        $this->assertFalse($v['ok']);
        $this->assertSame('origin_host_mismatch', $v['reason']);
    }

    public function testVerifyRejectsForeignSignature(): void
    {
        $signer = sodium_crypto_sign_keypair();
        $other  = sodium_crypto_sign_keypair();
        $jws = federation_jws_sign(
            $this->payload('origin.example.invalid', 'me.example.invalid', ['g1']),
            FEDERATION_PUBLISH_REVOCATION_TYP, 'k', sodium_crypto_sign_secretkey($signer)
        );
        // Verified against a key that did not sign it: forged revocation rejected.
        $v = federation_publish_revocation_verify($jws, sodium_crypto_sign_publickey($other), '', 'origin.example.invalid', 'me.example.invalid');
        $this->assertFalse($v['ok']);
        $this->assertSame('signature_invalid', $v['reason']);
    }

    public function testVerifyRejectsStaleGeneratedAt(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $stale = gmdate('c', time() - (FEDERATION_PUBLISH_REVOCATION_MAX_SKEW_SECONDS + 600));
        $jws = federation_jws_sign(
            $this->payload('origin.example.invalid', 'me.example.invalid', ['g1'], $stale),
            FEDERATION_PUBLISH_REVOCATION_TYP, 'k', sodium_crypto_sign_secretkey($kp)
        );
        $v = federation_publish_revocation_verify($jws, sodium_crypto_sign_publickey($kp), '', 'origin.example.invalid', 'me.example.invalid');
        $this->assertFalse($v['ok']);
        $this->assertSame('generated_at_out_of_bounds', $v['reason']);
    }

    public function testVerifyRejectsWrongTyp(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $jws = federation_jws_sign(
            $this->payload('origin.example.invalid', 'me.example.invalid', ['g1']),
            'application/vnd.telaris.something-else+json', 'k', sodium_crypto_sign_secretkey($kp)
        );
        $v = federation_publish_revocation_verify($jws, sodium_crypto_sign_publickey($kp), '', 'origin.example.invalid', 'me.example.invalid');
        $this->assertFalse($v['ok']);
        $this->assertSame('wrong_typ', $v['reason']);
    }

    public function testVerifyRotationGraceUsesPreviousKey(): void
    {
        $old = sodium_crypto_sign_keypair();
        $new = sodium_crypto_sign_keypair();
        // Signed with the OLD key; we hold NEW as current and OLD as previous.
        $jws = federation_jws_sign(
            $this->payload('origin.example.invalid', 'me.example.invalid', ['g1']),
            FEDERATION_PUBLISH_REVOCATION_TYP, 'k', sodium_crypto_sign_secretkey($old)
        );
        $v = federation_publish_revocation_verify(
            $jws,
            sodium_crypto_sign_publickey($new),
            sodium_crypto_sign_publickey($old),
            'origin.example.invalid',
            'me.example.invalid'
        );
        $this->assertTrue($v['ok'], $v['reason']);
        $this->assertSame(['g1'], $v['slugs']);
    }

    public function testBuildSignedRoundTripsWithLocalKey(): void
    {
        try {
            $secret = federation_load_secret_key();
        } catch (Throwable $e) {
            $this->markTestSkipped('instance identity key not readable in this runner');
        }
        $localPub = federation_derive_public_key($secret);
        $localHost = federation_local_hostname();

        [$cid, $slug] = $this->mkGalaxy();
        db_set_peer_publish_whitelist($this->peerId, [$cid], 'test');
        db_set_peer_publish_whitelist($this->peerId, [], 'test'); // revoke

        $jws = federation_publish_revocation_build_signed($this->peerId, $this->host);
        $v = federation_publish_revocation_verify($jws, $localPub, '', $localHost, $this->host);
        $this->assertTrue($v['ok'], $v['reason']);
        $this->assertContains($slug, $v['slugs'], 'the signed list carries the revoked slug for this recipient');
    }
}
