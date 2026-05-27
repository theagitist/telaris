<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5b galaxy publish envelope (JWS Compact, EdDSA).
 *
 * Exercises the sign/verify round-trip and every rejection path the data
 * plane depends on:
 *   - sign -> verify happy path, payload survives intact
 *   - content hash is reproducible (canonical serialization is stable)
 *   - replayed / non-monotonic published_sequence is rejected
 *   - a tampered envelope fails signature verification
 *   - a wrong public key fails verification
 *   - a published_at outside the 30-day sanity bound is rejected
 *   - a wrong typ header is rejected
 *
 * Uses synthetic keypairs, so it is DB- and network-free. The per-origin
 * nonce-store replay check lives in the pull consumer (5d) and is not
 * exercised here.
 *
 * Spec: Stage 5 galaxy publish design (5b); P2P federation plan v10 §
 * Standards and crypto, § threat model #10.
 */
final class GalaxyEnvelopeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_envelope.php';
    }

    /** @return array{0:string,1:string} [secretKey, publicKey] */
    private function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
    }

    private function samplePayload(int $sequence = 3, ?string $publishedAt = null): array
    {
        return federation_galaxy_envelope_payload(
            ['name' => 'Coastal plants', 'tagline' => 'demo', 'theme' => 'cosmic'],
            [[
                'name' => 'Beach Strawberry',
                'node_type' => 'object',
                'keywords' => ['edible', 'native'],
                'media' => [['sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg']],
            ]],
            [['from' => 'edible', 'to' => 'native']],
            'starmaps.polivoxia.ca',
            'coastal-plants',
            $sequence,
            $publishedAt ?? gmdate('c')
        );
    }

    public function testSignVerifyRoundTrip(): void
    {
        [$sk, $pk] = $this->keypair();
        $payload = $this->samplePayload(3);
        $jws = federation_jws_sign($payload, FEDERATION_GALAXY_ENVELOPE_TYP, 'starmaps.polivoxia.ca:fp', $sk);

        $this->assertSame(2, substr_count($jws, '.'), 'JWS Compact has three parts');

        $res = federation_galaxy_envelope_verify($jws, $pk, 2);
        $this->assertTrue($res['valid'], $res['reason'] ?? '');
        $this->assertSame(3, $res['payload']['published_sequence']);
        $this->assertSame('coastal-plants', $res['payload']['slug']);
        $this->assertSame('Beach Strawberry', $res['payload']['nodes'][0]['name']);
    }

    public function testContentHashIsReproducible(): void
    {
        $payload = $this->samplePayload(3, '2026-05-27T00:00:00+00:00');
        $this->assertSame(
            federation_galaxy_content_hash($payload),
            federation_galaxy_content_hash($payload),
            'canonical serialization must be byte-stable'
        );
        // Key order in the source array must not change the hash.
        $reordered = array_reverse($payload, true);
        $this->assertSame(
            federation_galaxy_content_hash($payload),
            federation_galaxy_content_hash($reordered),
            'canonicalization sorts keys, so source order is irrelevant'
        );
    }

    public function testRejectsReplayedSequence(): void
    {
        [$sk, $pk] = $this->keypair();
        $jws = federation_jws_sign($this->samplePayload(3), FEDERATION_GALAXY_ENVELOPE_TYP, 'h:fp', $sk);
        $res = federation_galaxy_envelope_verify($jws, $pk, 3); // already seen seq 3
        $this->assertFalse($res['valid']);
        $this->assertSame('sequence_not_monotonic', $res['reason']);
    }

    public function testRejectsTamperedEnvelope(): void
    {
        [$sk, $pk] = $this->keypair();
        $jws = federation_jws_sign($this->samplePayload(3), FEDERATION_GALAXY_ENVELOPE_TYP, 'h:fp', $sk);
        $tampered = substr($jws, 0, -4) . 'AAAA';
        $res = federation_galaxy_envelope_verify($tampered, $pk, 2);
        $this->assertFalse($res['valid']);
        $this->assertSame('signature_invalid', $res['reason']);
    }

    public function testRejectsWrongKey(): void
    {
        [$sk] = $this->keypair();
        [, $otherPk] = $this->keypair();
        $jws = federation_jws_sign($this->samplePayload(3), FEDERATION_GALAXY_ENVELOPE_TYP, 'h:fp', $sk);
        $res = federation_galaxy_envelope_verify($jws, $otherPk, 2);
        $this->assertFalse($res['valid']);
        $this->assertSame('signature_invalid', $res['reason']);
    }

    public function testRejectsStalePublishedAt(): void
    {
        [$sk, $pk] = $this->keypair();
        $stale = $this->samplePayload(3, gmdate('c', time() - 40 * 86400));
        $jws = federation_jws_sign($stale, FEDERATION_GALAXY_ENVELOPE_TYP, 'h:fp', $sk);
        $res = federation_galaxy_envelope_verify($jws, $pk, 2);
        $this->assertFalse($res['valid']);
        $this->assertSame('published_at_out_of_bounds', $res['reason']);
    }

    public function testRejectsWrongTyp(): void
    {
        [$sk, $pk] = $this->keypair();
        $jws = federation_jws_sign($this->samplePayload(3), FEDERATION_GALAXY_ENVELOPE_TYP, 'h:fp', $sk);
        $res = federation_jws_verify($jws, $pk, 'application/vnd.telaris.pluriverse-message.v1+json');
        $this->assertFalse($res['valid']);
        $this->assertSame('wrong_typ', $res['reason']);
    }
}
