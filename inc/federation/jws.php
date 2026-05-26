<?php
declare(strict_types=1);

/**
 * Stage 4c: minimal JWS Compact Serialization verifier for Ed25519.
 *
 * Telaris uses JWS Compact (RFC 7515) only for signed payload envelopes:
 *   - Key-rotation events (stage 4)
 *   - Galaxy envelopes (stage 5)
 *   - In-app message envelopes (stage 4e)
 *
 * The verifier targets the exact subset v10 specifies:
 *   - alg: EdDSA (RFC 8037) only. No other algorithms accepted.
 *   - kid: "<host>:<fingerprint>" shape — used to discriminate signer
 *     identity at the caller's layer (the verifier itself only checks the
 *     signature against the supplied public key).
 *   - typ: caller-supplied expected value; verifier rejects on mismatch.
 *   - JCS-canonicalized payload — verification is byte-exact, so
 *     canonicalization is signer-side only; the verifier operates on the
 *     raw payload bytes regardless of their canonical-form status.
 *
 * Spec: P2P federation plan v10 § Envelope format, § Key-event payload shape.
 *
 * Status at 4c: verify-only. Signing lands on the Pluriverse side (4g) for
 * relay envelopes and on the instance side later (4d/4e) for outbound
 * messages.
 */

/** Payload bounds (defensive — defends against memory-exhaustion via single oversized envelope). */
const FEDERATION_JWS_MAX_HEADER_BYTES = 4096;
const FEDERATION_JWS_MAX_PAYLOAD_BYTES = 65536;
const FEDERATION_JWS_MAX_SIGNATURE_BYTES = 96;

/**
 * Verify a JWS Compact Serialization (header.payload.signature) against an
 * Ed25519 public key.
 *
 * @param string $jws The Compact-serialized JWS.
 * @param string $publicKey 32-byte Ed25519 public key.
 * @param string $expectedTyp The required "typ" header value
 *   (e.g. "application/vnd.telaris.pluriverse-key-event.v1+json").
 * @return array{
 *   valid:bool,
 *   reason:string,
 *   header?:array<string,mixed>,
 *   payload?:array<string,mixed>,
 *   payload_bytes?:string,
 *   signing_input?:string,
 * }
 */
function federation_jws_verify(string $jws, string $publicKey, string $expectedTyp): array {
    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['valid' => false, 'reason' => 'public_key_wrong_length'];
    }
    if ($jws === '') {
        return ['valid' => false, 'reason' => 'empty_jws'];
    }
    $parts = explode('.', $jws);
    if (count($parts) !== 3) {
        return ['valid' => false, 'reason' => 'malformed_compact_serialization'];
    }
    [$headerB64, $payloadB64, $signatureB64] = $parts;

    if (strlen($headerB64) > FEDERATION_JWS_MAX_HEADER_BYTES) {
        return ['valid' => false, 'reason' => 'header_too_large'];
    }
    if (strlen($payloadB64) > FEDERATION_JWS_MAX_PAYLOAD_BYTES) {
        return ['valid' => false, 'reason' => 'payload_too_large'];
    }
    if (strlen($signatureB64) > FEDERATION_JWS_MAX_SIGNATURE_BYTES) {
        return ['valid' => false, 'reason' => 'signature_too_large'];
    }

    $headerBytes = _federation_jws_b64u_decode($headerB64);
    $payloadBytes = _federation_jws_b64u_decode($payloadB64);
    $signatureBytes = _federation_jws_b64u_decode($signatureB64);
    if ($headerBytes === null || $payloadBytes === null || $signatureBytes === null) {
        return ['valid' => false, 'reason' => 'malformed_base64url'];
    }
    if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return ['valid' => false, 'reason' => 'signature_wrong_length'];
    }

    try {
        $header = json_decode($headerBytes, true, 5, JSON_THROW_ON_ERROR);
    } catch (JsonException $_) {
        return ['valid' => false, 'reason' => 'malformed_header_json'];
    }
    if (!is_array($header)) {
        return ['valid' => false, 'reason' => 'malformed_header_json'];
    }
    $alg = (string)($header['alg'] ?? '');
    if ($alg !== 'EdDSA') {
        return ['valid' => false, 'reason' => 'wrong_alg', 'header' => $header];
    }
    if ($expectedTyp !== '') {
        $typ = (string)($header['typ'] ?? '');
        if ($typ !== $expectedTyp) {
            return ['valid' => false, 'reason' => 'wrong_typ', 'header' => $header];
        }
    }

    $signingInput = $headerB64 . '.' . $payloadB64;
    if (!sodium_crypto_sign_verify_detached($signatureBytes, $signingInput, $publicKey)) {
        return ['valid' => false, 'reason' => 'signature_invalid', 'header' => $header];
    }

    try {
        $payload = json_decode($payloadBytes, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $_) {
        return ['valid' => false, 'reason' => 'malformed_payload_json', 'header' => $header];
    }
    if (!is_array($payload)) {
        return ['valid' => false, 'reason' => 'malformed_payload_json', 'header' => $header];
    }

    return [
        'valid' => true,
        'reason' => '',
        'header' => $header,
        'payload' => $payload,
        'payload_bytes' => $payloadBytes,
        'signing_input' => $signingInput,
    ];
}

/**
 * Convenience: split a kid of the form "<host>:<fingerprint>" without
 * verification. Returns null on malformed input.
 *
 * @return array{host:string,fingerprint:string}|null
 */
function federation_jws_split_kid(string $kid): ?array {
    $pos = strrpos($kid, ':');
    if ($pos === false || $pos === 0 || $pos === strlen($kid) - 1) return null;
    return ['host' => substr($kid, 0, $pos), 'fingerprint' => substr($kid, $pos + 1)];
}

function _federation_jws_b64u_decode(string $s): ?string {
    if ($s === '') return '';
    if (preg_match('/[^A-Za-z0-9_-]/', $s)) return null;
    $b64 = strtr($s, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) $b64 .= str_repeat('=', 4 - $pad);
    $raw = base64_decode($b64, true);
    return $raw === false ? null : $raw;
}
