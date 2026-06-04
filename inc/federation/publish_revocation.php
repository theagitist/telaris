<?php
declare(strict_types=1);

/**
 * Per-peer publish-revocation signal (BACKLOG ^fed-revoke-vs-withdraw-diff).
 *
 * The stage-5 pull diff sees a subscribed-but-no-longer-offered slug as
 * "withdrawn" and conservatively fossilizes the mirror (prior consent stands).
 * That is correct for a benign disappearance (the origin globally un-published
 * the galaxy, or withdrew from the Pluriverse). It is WRONG for a deliberate
 * per-peer un-publish (the origin revoked THIS peer's access), which must DROP
 * the mirror.
 *
 * The subscriber cannot tell the two apart from published.json alone, so the
 * origin emits an explicit, signed, per-peer revocation list (revoked.json,
 * the per-peer analog of retracted.json). The subscriber drops only the
 * withdrawn slugs the origin has explicitly revoked for it; everything else
 * still fossilizes. A revocation is a drop signal, so it is origin-signed (a
 * forged one would force a destructive drop); verification mirrors the envelope
 * trust model (instance key + rotation grace), and binds origin + recipient +
 * freshness so a revoked.json cannot be replayed across peers or over time.
 *
 * Spec: v10 § State-change propagation; Stage 6 trust revocation design.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/jws.php';
require_once __DIR__ . '/identity.php';

const FEDERATION_PUBLISH_REVOCATION_TYP = 'application/vnd.telaris.pluriverse-revocations.v1+json';
const FEDERATION_PUBLISH_REVOCATION_PROTOCOL = '1.0';
// Generous freshness bound: the origin stamps generated_at at serve time, so a
// valid revoked.json is always near-now. A wider clock gap fails closed (the
// subscriber falls back to fossilize), so this never causes a spurious drop.
const FEDERATION_PUBLISH_REVOCATION_MAX_SKEW_SECONDS = 3600;

/**
 * The current per-peer revocation markers (newest first). Origin side.
 *
 * @return list<array{slug:string, revoked_at:string}>
 */
function federation_publish_revocations_for_peer(int $peerId): array {
    db_ensure_galaxy_publish_revocations_table();
    $stmt = getDB()->prepare("
        SELECT slug, revoked_at
        FROM galaxy_publish_revocations
        WHERE peer_id = :p
        ORDER BY revoked_at DESC, slug ASC
    ");
    $stmt->execute([':p' => $peerId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = ['slug' => (string)$row['slug'], 'revoked_at' => (string)$row['revoked_at']];
    }
    return $out;
}

/**
 * Build + sign the revoked.json payload for one recipient peer. Origin side.
 * Signed with this instance's identity key; the payload binds origin_host,
 * recipient_host, and generated_at.
 */
function federation_publish_revocation_build_signed(int $peerId, string $recipientHost): string {
    $payload = [
        'protocol_version' => FEDERATION_PUBLISH_REVOCATION_PROTOCOL,
        'origin_host' => federation_local_hostname(),
        'recipient_host' => strtolower(trim($recipientHost)),
        'generated_at' => gmdate('c'),
        'revoked' => federation_publish_revocations_for_peer($peerId),
    ];
    $secret = federation_load_secret_key();
    $kid = federation_keyid(federation_local_hostname());
    return federation_jws_sign($payload, FEDERATION_PUBLISH_REVOCATION_TYP, $kid, $secret);
}

/**
 * Verify a revoked.json JWS and return the revoked slug set. Subscriber side.
 * Fails closed: any verification problem returns ok=false with an empty slug
 * set, so the caller fossilizes (never drops) on uncertainty.
 *
 * @return array{ok:bool, slugs:list<string>, reason:string}
 */
function federation_publish_revocation_verify(
    string $jws,
    string $originPublicKey,
    string $previousPublicKey,
    string $expectedOriginHost,
    string $expectedRecipientHost
): array {
    $res = federation_jws_verify($jws, $originPublicKey, FEDERATION_PUBLISH_REVOCATION_TYP);
    if (!$res['valid'] && ($res['reason'] ?? '') === 'signature_invalid' && $previousPublicKey !== '') {
        // Rotation grace: the origin may have rotated since we cached its key.
        $res = federation_jws_verify($jws, $previousPublicKey, FEDERATION_PUBLISH_REVOCATION_TYP);
    }
    if (!$res['valid']) {
        return ['ok' => false, 'slugs' => [], 'reason' => (string)($res['reason'] ?? 'signature_invalid')];
    }

    $p = $res['payload'];
    if (($p['protocol_version'] ?? '') !== FEDERATION_PUBLISH_REVOCATION_PROTOCOL) {
        return ['ok' => false, 'slugs' => [], 'reason' => 'unsupported_protocol_version'];
    }
    if (strcasecmp((string)($p['origin_host'] ?? ''), trim($expectedOriginHost)) !== 0) {
        return ['ok' => false, 'slugs' => [], 'reason' => 'origin_host_mismatch'];
    }
    if (strcasecmp((string)($p['recipient_host'] ?? ''), trim($expectedRecipientHost)) !== 0) {
        return ['ok' => false, 'slugs' => [], 'reason' => 'recipient_host_mismatch'];
    }
    $gen = is_string($p['generated_at'] ?? null) ? strtotime($p['generated_at']) : false;
    if ($gen === false) {
        return ['ok' => false, 'slugs' => [], 'reason' => 'bad_generated_at'];
    }
    if (abs(time() - $gen) > FEDERATION_PUBLISH_REVOCATION_MAX_SKEW_SECONDS) {
        return ['ok' => false, 'slugs' => [], 'reason' => 'generated_at_out_of_bounds'];
    }

    $slugs = [];
    foreach ((array)($p['revoked'] ?? []) as $entry) {
        if (is_array($entry) && is_string($entry['slug'] ?? null) && $entry['slug'] !== '') {
            $slugs[] = $entry['slug'];
        }
    }
    return ['ok' => true, 'slugs' => $slugs, 'reason' => ''];
}
