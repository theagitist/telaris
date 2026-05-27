<?php
declare(strict_types=1);

/**
 * Stage 5b: galaxy publish envelope (JWS Compact Serialization, EdDSA).
 *
 * An authored galaxy is canonical-serialized into a versioned payload and
 * signed with this instance's Ed25519 key. A whitelisted-and-subscribed peer
 * verifies the signature against the origin's public key (resolved from the
 * JWS kid against the Pluriverse), then applies the stage-5 freshness checks:
 * strict-monotonic published_sequence and a published_at sanity bound. The
 * per-origin nonce-store replay check is layered in the pull consumer (5d),
 * which has DB access; this module stays DB-free and unit-testable.
 *
 * Spec: P2P federation plan v10 § Standards and crypto, § threat model #10.
 */

require_once __DIR__ . '/jws.php';
require_once __DIR__ . '/identity.php';

const FEDERATION_GALAXY_ENVELOPE_TYP = 'application/vnd.telaris.pluriverse-envelope.v1+json';
const FEDERATION_GALAXY_ENVELOPE_PROTOCOL = '1.0';
// published_at sanity bound, defence-in-depth alongside the monotonic sequence.
const FEDERATION_GALAXY_PUBLISHED_AT_MAX_SKEW_SECONDS = 30 * 86400;

/**
 * Assemble the canonical payload for an authored galaxy. Pure structure; no
 * DB, no signing. The content hash and the signature both operate on the
 * canonical serialization of this array. The DB-row -> shape mapping is the
 * caller's job (wired in 5c); this keeps the envelope independently testable.
 *
 * @param array<string,mixed> $galaxy  galaxy metadata (name, tagline, theme, ...)
 * @param list<array<string,mixed>> $nodes  wormholes, each with keywords + media refs
 * @param list<array<string,mixed>> $keywordRelations  keyword relation pairs
 * @param string $originHost
 * @param string $slug
 * @param int    $sequence  strict-monotonic published_sequence (>= 1)
 * @param string $publishedAt  ISO-8601 timestamp
 * @param string|null $nonce  16-byte b64url nonce; generated if null
 * @return array<string,mixed>
 */
function federation_galaxy_envelope_payload(
    array $galaxy,
    array $nodes,
    array $keywordRelations,
    string $originHost,
    string $slug,
    int $sequence,
    string $publishedAt,
    ?string $nonce = null
): array {
    return [
        'protocol_version' => FEDERATION_GALAXY_ENVELOPE_PROTOCOL,
        'origin_host' => $originHost,
        'slug' => $slug,
        'published_sequence' => $sequence,
        'published_at' => $publishedAt,
        'nonce' => $nonce ?? rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        'galaxy' => $galaxy,
        'nodes' => array_values($nodes),
        'keyword_relations' => array_values($keywordRelations),
    ];
}

/** sha256 (hex) of the canonical payload bytes. Reproducible across instances. */
function federation_galaxy_content_hash(array $payload): string {
    return hash('sha256', federation_jws_canonical_json($payload));
}

/**
 * Build and sign the envelope with this instance's key. Returns the JWS
 * Compact string; the kid is this instance's keyid.
 */
function federation_galaxy_envelope_build(array $payload): string {
    $secret = federation_load_secret_key();
    $kid = federation_keyid(federation_local_hostname());
    return federation_jws_sign($payload, FEDERATION_GALAXY_ENVELOPE_TYP, $kid, $secret);
}

/**
 * Verify an inbound galaxy envelope: base JWS verify (signature + typ) plus the
 * stage-5 freshness checks. DB-free; the caller supplies last_sequence_seen and
 * performs the nonce-store replay check separately (5d).
 *
 * @return array{valid:bool, reason:string, payload?:array<string,mixed>, header?:array<string,mixed>}
 */
function federation_galaxy_envelope_verify(string $jws, string $originPublicKey, int $lastSequenceSeen): array {
    $res = federation_jws_verify($jws, $originPublicKey, FEDERATION_GALAXY_ENVELOPE_TYP);
    if (!$res['valid']) {
        return $res;
    }
    $p = $res['payload'];

    if (($p['protocol_version'] ?? '') !== FEDERATION_GALAXY_ENVELOPE_PROTOCOL) {
        return ['valid' => false, 'reason' => 'unsupported_protocol_version', 'header' => $res['header']];
    }
    $seq = $p['published_sequence'] ?? null;
    if (!is_int($seq) || $seq < 1 || $seq <= $lastSequenceSeen) {
        return ['valid' => false, 'reason' => 'sequence_not_monotonic', 'header' => $res['header']];
    }
    $pubAt = is_string($p['published_at'] ?? null) ? strtotime($p['published_at']) : false;
    if ($pubAt === false) {
        return ['valid' => false, 'reason' => 'bad_published_at', 'header' => $res['header']];
    }
    if (abs(time() - $pubAt) > FEDERATION_GALAXY_PUBLISHED_AT_MAX_SKEW_SECONDS) {
        return ['valid' => false, 'reason' => 'published_at_out_of_bounds', 'header' => $res['header']];
    }

    return ['valid' => true, 'reason' => '', 'payload' => $p, 'header' => $res['header']];
}
