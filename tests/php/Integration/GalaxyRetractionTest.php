<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5c galaxy retraction.
 *
 * Two halves:
 *   - Envelope crypto (DB-free, ephemeral keypairs): sign -> verify round-trip
 *     and every rejection path the consumer depends on (tamper, wrong key,
 *     wrong typ, wrong message_type, missing slug). No published_at bound:
 *     retractions are permanent.
 *   - Library + storage (DB): retract guard paths that return before signing
 *     (not_found, mirrored), and the read helpers (for_slug, recent list)
 *     against directly-inserted rows. The signing happy path needs the
 *     instance key (not readable by the CLI user), so it is exercised live as
 *     www-data, not here.
 *
 * Spec: Stage 5 galaxy publish design (5c); P2P federation plan v10 §
 * State-change propagation.
 */
final class GalaxyRetractionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_retraction.php';
    }

    /** @return array{0:string,1:string} [secretKey, publicKey] */
    private function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
    }

    private function sign(array $payload, string $sk, string $typ = FEDERATION_RETRACTION_TYP): string
    {
        return federation_jws_sign($payload, $typ, 'starmaps.polivoxia.ca:fp', $sk);
    }

    private function samplePayload(): array
    {
        return federation_retraction_payload('starmaps.polivoxia.ca', 'coastal-plants', gmdate('c'), 5, 'consent withdrawn');
    }

    public function testSignVerifyRoundTrip(): void
    {
        [$sk, $pk] = $this->keypair();
        $jws = $this->sign($this->samplePayload(), $sk);
        $res = federation_retraction_verify($jws, $pk);
        $this->assertTrue($res['valid'], $res['reason'] ?? '');
        $this->assertSame('retraction', $res['payload']['message_type']);
        $this->assertSame('coastal-plants', $res['payload']['slug']);
        $this->assertSame(5, $res['payload']['final_sequence']);
    }

    public function testRejectsTampered(): void
    {
        [$sk, $pk] = $this->keypair();
        $jws = $this->sign($this->samplePayload(), $sk);
        $res = federation_retraction_verify(substr($jws, 0, -4) . 'AAAA', $pk);
        $this->assertFalse($res['valid']);
        $this->assertSame('signature_invalid', $res['reason']);
    }

    public function testRejectsWrongKey(): void
    {
        [$sk] = $this->keypair();
        [, $otherPk] = $this->keypair();
        $jws = $this->sign($this->samplePayload(), $sk);
        $this->assertFalse(federation_retraction_verify($jws, $otherPk)['valid']);
    }

    public function testRejectsWrongTyp(): void
    {
        [$sk, $pk] = $this->keypair();
        $jws = $this->sign($this->samplePayload(), $sk, 'application/vnd.telaris.pluriverse-envelope.v1+json');
        $res = federation_retraction_verify($jws, $pk);
        $this->assertFalse($res['valid']);
        $this->assertSame('wrong_typ', $res['reason']);
    }

    public function testRejectsWrongMessageType(): void
    {
        [$sk, $pk] = $this->keypair();
        $payload = $this->samplePayload();
        $payload['message_type'] = 'something_else';
        $res = federation_retraction_verify($this->sign($payload, $sk), $pk);
        $this->assertFalse($res['valid']);
        $this->assertSame('not_a_retraction', $res['reason']);
    }

    public function testRejectsMissingSlug(): void
    {
        [$sk, $pk] = $this->keypair();
        $payload = $this->samplePayload();
        $payload['slug'] = '';
        $res = federation_retraction_verify($this->sign($payload, $sk), $pk);
        $this->assertFalse($res['valid']);
        $this->assertSame('missing_slug', $res['reason']);
    }

    // --- library + storage -------------------------------------------------

    public function testRetractUnknownGalaxy(): void
    {
        // A truly nonexistent id. (Not 0: id 0 is the default constellation that
        // setup.php always creates, so it exists with a null slug on every real
        // instance and would return 'galaxy_has_no_slug', not 'galaxy_not_found'.)
        $res = federation_galaxy_retract(987654321, 'admin@test.invalid');
        $this->assertFalse($res['ok']);
        $this->assertSame('galaxy_not_found', $res['error']);
    }

    public function testRetractMirrorIsRefused(): void
    {
        $pdo = getDB();
        $sfx = bin2hex(random_bytes(4));
        $insC = $pdo->prepare("INSERT INTO constellations (name, slug, type, theme, import_source)
                       VALUES (:n, :s, 'galaxy', 'cosmic', :src) RETURNING id");
        $insC->execute([':n' => "retract-mirror-$sfx", ':s' => "retract-mirror-$sfx", ':src' => json_encode(['peer' => 'x'])]);
        $cid = (int)$insC->fetchColumn();
        try {
            $res = federation_galaxy_retract($cid, 'admin@test.invalid');
            $this->assertFalse($res['ok']);
            $this->assertSame('galaxy_is_mirrored', $res['error']);
        } finally {
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
    }

    public function testForSlugAndRecentList(): void
    {
        db_ensure_retracted_galaxies_table();
        $pdo = getDB();
        $sfx = bin2hex(random_bytes(4));
        $retracted = "retracted-$sfx";
        $legacy = "legacy-no-jws-$sfx";
        $pdo->prepare("INSERT INTO retracted_galaxies (slug, retracted_by, reason, retraction_jws)
                       VALUES (:s, 'admin@test.invalid', 'test', 'aaa.bbb.ccc')")
            ->execute([':s' => $retracted]);
        // A legacy row with no envelope must not surface in either read path.
        $pdo->prepare("INSERT INTO retracted_galaxies (slug, retracted_by, reason, retraction_jws)
                       VALUES (:s, 'admin@test.invalid', 'old', NULL)")
            ->execute([':s' => $legacy]);
        try {
            $this->assertSame('aaa.bbb.ccc', federation_retraction_for_slug($retracted));
            $this->assertNull(federation_retraction_for_slug($legacy), 'a row without an envelope yields null');
            $this->assertNull(federation_retraction_for_slug("never-$sfx"));

            $slugs = array_column(federation_recent_retractions(), 'slug');
            $this->assertContains($retracted, $slugs);
            $this->assertNotContains($legacy, $slugs, 'envelope-less rows are excluded from the digest');
        } finally {
            $pdo->prepare("DELETE FROM retracted_galaxies WHERE slug IN (:a, :b)")
                ->execute([':a' => $retracted, ':b' => $legacy]);
        }
    }
}
