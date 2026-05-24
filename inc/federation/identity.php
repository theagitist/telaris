<?php
declare(strict_types=1);

/**
 * Federation identity (stage 1b).
 *
 * The instance's Ed25519 identity lives in a single file at
 * `<site-root>/secrets/pluriverse.key` (64 bytes — libsodium secret-key form).
 * The public key is derived on demand and cached in PHP-process memory for
 * the lifetime of the worker. No DB table holds the instance's own identity.
 *
 * Spec: P2P federation plan v10 § Key management.
 *
 * Public-key fingerprint format: base64url(SHA-256(public_key)[0..16]),
 * no padding. The fingerprint is the suffix half of the `kid` value used
 * in JWS Protected Headers and HTTP Signature `keyid` parameters
 * (format: `<host>:<fingerprint>`).
 *
 * Production callers use the file-backed accessors:
 *   federation_load_secret_key()
 *   federation_public_key()
 *   federation_public_key_fingerprint()
 *   federation_keyid($hostname)
 *
 * Tests call the pure-function pair on supplied bytes:
 *   federation_derive_public_key($secret)
 *   federation_compute_fingerprint($public)
 *
 * Override the key path for tests / non-standard installs by defining
 * FEDERATION_SECRET_KEY_PATH before this file is loaded.
 */

function federation_secret_key_path(): string {
    if (defined('FEDERATION_SECRET_KEY_PATH')) {
        return (string)FEDERATION_SECRET_KEY_PATH;
    }
    return dirname(__DIR__, 2) . '/secrets/pluriverse.key';
}

function federation_load_secret_key(): string {
    $path = federation_secret_key_path();
    if (!file_exists($path)) {
        throw new RuntimeException(
            'federation_load_secret_key: ' . $path . ' missing; run bin/init-identity'
        );
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('federation_load_secret_key: ' . $path . ' unreadable');
    }
    $expected = SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
    if (strlen($bytes) !== $expected) {
        throw new RuntimeException(
            'federation_load_secret_key: ' . $path
            . ' wrong length (got ' . strlen($bytes) . ', expected ' . $expected . ')'
        );
    }
    return $bytes;
}

function federation_derive_public_key(string $secretKey): string {
    if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new InvalidArgumentException(
            'federation_derive_public_key: secret key must be '
            . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' bytes'
        );
    }
    return sodium_crypto_sign_publickey_from_secretkey($secretKey);
}

function federation_public_key(): string {
    static $pubkey = null;
    if ($pubkey === null) {
        $pubkey = federation_derive_public_key(federation_load_secret_key());
    }
    return $pubkey;
}

function federation_compute_fingerprint(string $publicKey): string {
    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new InvalidArgumentException(
            'federation_compute_fingerprint: public key must be '
            . SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES . ' bytes'
        );
    }
    $hash = hash('sha256', $publicKey, true);
    return rtrim(strtr(base64_encode(substr($hash, 0, 16)), '+/', '-_'), '=');
}

function federation_public_key_fingerprint(): string {
    return federation_compute_fingerprint(federation_public_key());
}

function federation_keyid(string $hostname): string {
    return $hostname . ':' . federation_public_key_fingerprint();
}
