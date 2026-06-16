<?php
declare(strict_types=1);

/**
 * Stage 5c: galaxy retraction.
 *
 * Retraction is the one-way, permanent withdrawal of an authored galaxy from
 * the federation. The origin mints a signed retraction envelope (JWS Compact,
 * EdDSA), records it in retracted_galaxies, and stops offering the galaxy
 * (published_galaxies.is_current -> FALSE). A retracted slug can never be
 * reused (federation_galaxy_publish refuses it).
 *
 * The retraction is served two ways: the canonical public per-slug endpoint
 * (/galaxies/{slug}.retracted, no auth, so any holder of a stale mirror can
 * verify the withdrawal) and the per-peer retracted.json digest. Unlike the
 * galaxy envelope there is no freshness window: a retraction is timeless, so a
 * peer that has been offline for months still honours it on its next pull. The
 * consumer (5d) drops the mirror on a verified retraction.
 *
 * Spec: Stage 5 galaxy publish design (5c); P2P federation plan v10 §
 * State-change propagation, § threat model.
 */

require_once __DIR__ . '/jws.php';
require_once __DIR__ . '/identity.php';
require_once dirname(__DIR__) . '/db.php';

const FEDERATION_RETRACTION_TYP = 'application/vnd.telaris.pluriverse-retraction.v1+json';
const FEDERATION_RETRACTION_PROTOCOL = '1.0';
const FEDERATION_RETRACTED_LIST_DEFAULT_LIMIT = 100;

/**
 * The canonical retraction payload. Pure structure; no DB, no signing, so it
 * stays unit-testable alongside the envelope. `final_sequence` is the last
 * published_sequence the origin emitted for this slug (0 if it was never
 * published), so a consumer knows the retraction supersedes everything it may
 * hold.
 *
 * @return array<string,mixed>
 */
function federation_retraction_payload(
    string $originHost,
    string $slug,
    string $retractedAt,
    int $finalSequence,
    string $reason = '',
    ?string $nonce = null
): array {
    return [
        'protocol_version' => FEDERATION_RETRACTION_PROTOCOL,
        'message_type' => 'retraction',
        'origin_host' => $originHost,
        'slug' => $slug,
        'retracted_at' => $retractedAt,
        'final_sequence' => $finalSequence,
        'reason' => $reason,
        'nonce' => $nonce ?? rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
    ];
}

/** Build and sign the retraction envelope with this instance's key. */
function federation_retraction_build(array $payload): string {
    $secret = federation_load_secret_key();
    $kid = federation_keyid(federation_local_hostname());
    return federation_jws_sign($payload, FEDERATION_RETRACTION_TYP, $kid, $secret);
}

/**
 * Verify an inbound retraction envelope: base JWS verify (signature + typ) plus
 * the structural checks. DB-free. No time bound: retractions are permanent, so
 * a long-offline peer must still honour an old one.
 *
 * @return array{valid:bool, reason:string, payload?:array<string,mixed>, header?:array<string,mixed>}
 */
function federation_retraction_verify(string $jws, string $originPublicKey): array {
    $res = federation_jws_verify($jws, $originPublicKey, FEDERATION_RETRACTION_TYP);
    if (!$res['valid']) {
        return $res;
    }
    $p = $res['payload'];
    if (($p['protocol_version'] ?? '') !== FEDERATION_RETRACTION_PROTOCOL) {
        return ['valid' => false, 'reason' => 'unsupported_protocol_version', 'header' => $res['header']];
    }
    if (($p['message_type'] ?? '') !== 'retraction') {
        return ['valid' => false, 'reason' => 'not_a_retraction', 'header' => $res['header']];
    }
    if (!is_string($p['slug'] ?? null) || $p['slug'] === '') {
        return ['valid' => false, 'reason' => 'missing_slug', 'header' => $res['header']];
    }
    if (!is_string($p['retracted_at'] ?? null) || strtotime((string)$p['retracted_at']) === false) {
        return ['valid' => false, 'reason' => 'bad_retracted_at', 'header' => $res['header']];
    }
    return ['valid' => true, 'reason' => '', 'payload' => $p, 'header' => $res['header']];
}

/**
 * Retract an authored galaxy: mint the signed retraction, record it, and stop
 * offering the galaxy. One-way and idempotent: a second call on an
 * already-retracted slug is a no-op success (and back-fills retraction_jws if a
 * pre-5c row lacked it). Mirrors cannot be retracted here (you do not own them).
 *
 * @return array{ok:bool, error?:string, slug?:string, retraction_jws?:string, already_retracted?:bool}
 */
function federation_galaxy_retract(int $constellationId, string $retractedBy, ?string $reason = null): array {
    db_ensure_retracted_galaxies_table();
    db_ensure_published_galaxies_table();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT slug, type, import_source FROM constellations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $constellationId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($c === false) {
        return ['ok' => false, 'error' => 'galaxy_not_found'];
    }
    $slug = (string)($c['slug'] ?? '');
    if ($slug === '') {
        return ['ok' => false, 'error' => 'galaxy_has_no_slug'];
    }
    if (!empty($c['import_source'])) {
        return ['ok' => false, 'error' => 'galaxy_is_mirrored'];
    }

    // Already retracted? Idempotent: back-fill the envelope if a legacy row
    // predates the retraction_jws column, otherwise return the cached one.
    $ex = $pdo->prepare("SELECT retraction_jws FROM retracted_galaxies WHERE slug = :slug LIMIT 1");
    $ex->execute([':slug' => $slug]);
    $exRow = $ex->fetch(PDO::FETCH_ASSOC);

    $finalSeq = $pdo->prepare("SELECT published_sequence FROM published_galaxies WHERE slug = :slug LIMIT 1");
    $finalSeq->execute([':slug' => $slug]);
    $seq = $finalSeq->fetchColumn();
    $finalSequence = $seq === false ? 0 : (int)$seq;

    if ($exRow !== false && !empty($exRow['retraction_jws'])) {
        return ['ok' => true, 'slug' => $slug, 'retraction_jws' => (string)$exRow['retraction_jws'], 'already_retracted' => true];
    }

    $payload = federation_retraction_payload(
        federation_local_hostname(),
        $slug,
        gmdate('c'),
        $finalSequence,
        (string)($reason ?? '')
    );
    $jws = federation_retraction_build($payload);

    $up = $pdo->prepare("
        INSERT INTO retracted_galaxies (constellation_id, slug, retracted_by, reason, retraction_jws)
        VALUES (:cid, :slug, :by, :reason, :jws)
        ON CONFLICT (slug) DO UPDATE SET
            retraction_jws = COALESCE(retracted_galaxies.retraction_jws, EXCLUDED.retraction_jws),
            retracted_by = COALESCE(retracted_galaxies.retracted_by, EXCLUDED.retracted_by),
            reason = COALESCE(retracted_galaxies.reason, EXCLUDED.reason)
    ");
    $up->execute([
        ':cid' => $constellationId,
        ':slug' => $slug,
        ':by' => $retractedBy,
        ':reason' => $reason,
        ':jws' => $jws,
    ]);

    // Stop offering the galaxy. The published_galaxies row is kept for history.
    $pdo->prepare("UPDATE published_galaxies SET is_current = FALSE WHERE slug = :slug")
        ->execute([':slug' => $slug]);

    return ['ok' => true, 'slug' => $slug, 'retraction_jws' => $jws, 'already_retracted' => false];
}

/**
 * The cached signed retraction envelope for one slug, or null if the slug is
 * not retracted (or a legacy row lacks the envelope). Serves the public
 * /galaxies/{slug}.retracted endpoint.
 */
function federation_retraction_for_slug(string $slug): ?string {
    db_ensure_retracted_galaxies_table();
    $stmt = getDB()->prepare("SELECT retraction_jws FROM retracted_galaxies WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $jws = $stmt->fetchColumn();
    if ($jws === false || $jws === null || $jws === '') return null;
    return (string)$jws;
}

/**
 * Recent retractions for the per-peer retracted.json digest, newest first.
 * Retractions are inherently public (the per-slug endpoint has no auth), so
 * this is not whitelist-scoped; it lists every retraction this instance has
 * signed and still holds an envelope for.
 *
 * @return list<array{slug:string, retracted_at:string, reason:string, retraction_jws:string}>
 */
function federation_recent_retractions(int $limit = FEDERATION_RETRACTED_LIST_DEFAULT_LIMIT): array {
    db_ensure_retracted_galaxies_table();
    $limit = max(1, min(500, $limit));
    $stmt = getDB()->prepare("
        SELECT slug, retracted_at, reason, retraction_jws
        FROM retracted_galaxies
        WHERE retraction_jws IS NOT NULL AND retraction_jws <> ''
        ORDER BY retracted_at DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'slug' => (string)$r['slug'],
            'retracted_at' => (string)$r['retracted_at'],
            'reason' => (string)($r['reason'] ?? ''),
            'retraction_jws' => (string)$r['retraction_jws'],
        ];
    }
    return $out;
}
