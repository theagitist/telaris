<?php
declare(strict_types=1);

/**
 * Stage 4c: Pluriverse coordination key cache.
 *
 * The cached coord key is the anchor of trust for every Pluriverse-signed
 * artefact received by this instance: key-rotation event pushes, relay-
 * forwarded first-contact messages, anomaly notices. The cache is
 * Trust-On-First-Use (TOFU) - the first read fetches the key over HTTPS
 * (TLS-cert-validated) from www.telaris.ca/api/pluriverse/identity and
 * pins it. Subsequent reads return the cached value; the cache is only
 * replaced by a `coord_rotation` event signed by the previous cached key
 * (verify-and-swap) or by `bin/coord-key-update` for compromise recovery.
 *
 * Storage: three rows in `system_meta`:
 *   - pluriverse_coord_pub_current             (base64 of 32 bytes)
 *   - pluriverse_coord_pub_previous            (base64; NULL when no rotation in flight)
 *   - pluriverse_coord_pub_previous_expires_at (timestamp; NULL when no previous slot)
 *
 * Spec: P2P federation plan v10 § Pluriverse coordination key rotation
 * (line 836+) and § Key management (line 816+).
 */

require_once __DIR__ . '/jws.php';

const FEDERATION_COORD_PLURIVERSE_BASE_URL = 'https://www.telaris.ca';
const FEDERATION_COORD_KEY_EVENT_TYP = 'application/vnd.telaris.pluriverse-key-event.v1+json';
const FEDERATION_COORD_PREVIOUS_TOTAL_GRACE_SECONDS = 86400 + 7 * 86400; // 24h confirmation + 7d post-swap grace

/**
 * Return the cached coord public key (32 bytes), fetching TOFU if absent.
 * Returns null if the cache is empty and the fetch fails.
 */
function federation_coord_key_get(bool $allowTofu = true): ?string {
    db_ensure_system_meta_table();
    $cached = db_system_meta_get('pluriverse_coord_pub_current');
    if (is_string($cached) && $cached !== '') {
        $bytes = base64_decode($cached, true);
        if ($bytes !== false && strlen($bytes) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $bytes;
        }
    }
    if (!$allowTofu) return null;
    $result = federation_coord_key_tofu_fetch();
    return $result['ok'] ? $result['public_key'] : null;
}

/**
 * Trust-On-First-Use fetch from www.telaris.ca/api/pluriverse/identity.
 * Stores the returned public key as the cached current. The fetch is gated
 * by HTTPS with TLS-cert validation; first-contact trust rests on that.
 *
 * Returns ['ok' => bool, 'public_key' => bytes, 'fingerprint' => string, 'reason' => string].
 *
 * @return array{ok:bool, public_key?:string, fingerprint?:string, reason?:string}
 */
function federation_coord_key_tofu_fetch(): array {
    $url = FEDERATION_COORD_PLURIVERSE_BASE_URL . '/api/pluriverse/identity';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Telaris/4c coord-key-tofu',
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'reason' => 'curl_error:' . $err];
    }
    if ($code !== 200) {
        return ['ok' => false, 'reason' => 'http_' . $code];
    }
    try {
        $parsed = json_decode((string)$resp, true, 6, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['ok' => false, 'reason' => 'json_parse_failed'];
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'reason' => 'malformed_response'];
    }
    if (($parsed['kind'] ?? '') !== 'pluriverse-coord') {
        return ['ok' => false, 'reason' => 'wrong_kind:' . (string)($parsed['kind'] ?? '')];
    }
    $pubB64 = (string)($parsed['public_key'] ?? '');
    $pubBytes = base64_decode($pubB64, true);
    if ($pubBytes === false || strlen($pubBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['ok' => false, 'reason' => 'invalid_public_key'];
    }
    $fingerprint = rtrim(strtr(base64_encode(substr(hash('sha256', $pubBytes, true), 0, 16)), '+/', '-_'), '=');
    $advertised = (string)($parsed['public_key_fingerprint'] ?? '');
    if ($advertised !== '' && $advertised !== $fingerprint) {
        return ['ok' => false, 'reason' => 'fingerprint_self_mismatch'];
    }

    db_system_meta_set('pluriverse_coord_pub_current', $pubB64);
    return ['ok' => true, 'public_key' => $pubBytes, 'fingerprint' => $fingerprint];
}

/**
 * The cached previous slot, or null when no rotation is in flight or the
 * grace window has expired.
 */
function federation_coord_key_get_previous(): ?string {
    db_ensure_system_meta_table();
    $b64 = db_system_meta_get('pluriverse_coord_pub_previous');
    $exp = db_system_meta_get('pluriverse_coord_pub_previous_expires_at');
    if (!is_string($b64) || $b64 === '') return null;
    if (!is_string($exp) || $exp === '') return null;
    if (strtotime($exp) < time()) return null;
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return null;
    return $bytes;
}

/**
 * Apply a verified coord_rotation event. Verifies the JWS against the cached
 * current (which is the about-to-become-previous), then atomically swaps:
 *   current -> previous (with expires_at = NOW + 24h confirmation + 7d grace)
 *   new from payload -> current
 *
 * On replay of the same rotation event (already applied), returns
 * ok=false reason=already_applied without touching the cache. This keeps
 * the apply idempotent under retried push deliveries.
 *
 * @param string $jwsCompact The JWS Compact Serialization carrying the event.
 * @return array{ok:bool, reason:string, new_fingerprint?:string}
 */
function federation_coord_key_apply_rotation(string $jwsCompact): array {
    $current = federation_coord_key_get(allowTofu: false);
    if ($current === null) {
        return ['ok' => false, 'reason' => 'no_cached_current'];
    }

    $verify = federation_jws_verify($jwsCompact, $current, FEDERATION_COORD_KEY_EVENT_TYP);
    if (!$verify['valid']) {
        return ['ok' => false, 'reason' => 'jws_invalid:' . $verify['reason']];
    }
    $payload = $verify['payload'] ?? [];
    if (!is_array($payload) || ($payload['event_type'] ?? '') !== 'coord_rotation') {
        return ['ok' => false, 'reason' => 'wrong_event_type'];
    }
    $newPubB64 = (string)($payload['new_coord_public_key'] ?? '');
    $newPubBytes = base64_decode($newPubB64, true);
    if ($newPubBytes === false || strlen($newPubBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['ok' => false, 'reason' => 'invalid_new_public_key'];
    }

    $declaredFingerprint = (string)($payload['new_coord_fingerprint'] ?? '');
    $computedFingerprint = rtrim(strtr(base64_encode(substr(hash('sha256', $newPubBytes, true), 0, 16)), '+/', '-_'), '=');
    if ($declaredFingerprint !== '' && $declaredFingerprint !== $computedFingerprint) {
        return ['ok' => false, 'reason' => 'fingerprint_payload_mismatch'];
    }

    if (hash_equals($current, $newPubBytes)) {
        return ['ok' => false, 'reason' => 'already_applied'];
    }

    $previousFp = (string)($payload['previous_coord_fingerprint'] ?? '');
    $currentFp = rtrim(strtr(base64_encode(substr(hash('sha256', $current, true), 0, 16)), '+/', '-_'), '=');
    if ($previousFp !== '' && $previousFp !== $currentFp) {
        return ['ok' => false, 'reason' => 'rotation_anchors_unknown_key'];
    }

    db_system_meta_set('pluriverse_coord_pub_previous', base64_encode($current));
    db_system_meta_set(
        'pluriverse_coord_pub_previous_expires_at',
        date('Y-m-d H:i:s', time() + FEDERATION_COORD_PREVIOUS_TOTAL_GRACE_SECONDS),
    );
    db_system_meta_set('pluriverse_coord_pub_current', $newPubB64);

    return ['ok' => true, 'reason' => '', 'new_fingerprint' => $computedFingerprint];
}

/**
 * Operator-supplied override of the cached coord key. Reached only via
 * bin/coord-key-update with admin re-auth: the CLI fetches the live
 * identity endpoint, hashes the returned key, and compares against the
 * operator-supplied fingerprint. On match the CLI calls this helper.
 *
 * Distinct from the rotation path: there is no JWS proof of authority
 * (because the previous coord key may itself be compromised). Trust here
 * comes from the out-of-band fingerprint exchange.
 *
 * Clears any cached previous slot - this is not a rotation, it is a
 * recovery, and the prior chain of trust is severed.
 */
function federation_coord_key_force_replace(string $publicKeyBytes): void {
    if (strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new InvalidArgumentException('federation_coord_key_force_replace: public key wrong length');
    }
    db_system_meta_set('pluriverse_coord_pub_current', base64_encode($publicKeyBytes));
    db_system_meta_delete('pluriverse_coord_pub_previous');
    db_system_meta_delete('pluriverse_coord_pub_previous_expires_at');
}
