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

/**
 * Resolve this instance's canonical federation hostname, the value that goes
 * in the keyid the Pluriverse and peers verify against.
 *
 * Self-healing precedence so a fresh deploy works without any per-instance
 * config edit (config.php is per-site, gitignored, and rewriting it via
 * tooling is error-prone — it can strip the www-data group PHP-FPM needs):
 *
 *   1. Operator-set hostname (admin Global Settings, system_meta
 *      'setting_telaris_hostname') or the TELARIS_HOSTNAME config.php constant,
 *      resolved DB-over-constant via instance_setting_get() — explicit,
 *      canonical, preferred for any instance served under more than one hostname.
 *   2. system_meta 'federation_local_hostname' — the cached value captured
 *      from a prior HTTP request (so CLI/cron tools have it even with no
 *      HTTP_HOST).
 *   3. $_SERVER['HTTP_HOST'] (port stripped) — and on this path we WRITE the
 *      value into system_meta so the next cron invocation can read it. This
 *      is how the hostname propagates to a new instance with zero operator
 *      action: the first authenticated/admin page load records it.
 *   4. gethostname() — last-resort OS hostname; almost certainly wrong for
 *      federation, but better than an empty keyid.
 *
 * The capture in step 3 only fires when steps 1-2 are empty, so an explicit
 * TELARIS_HOSTNAME always wins and a cached value is never silently
 * overwritten by a request arriving under a secondary hostname.
 */
function federation_local_hostname(): string {
    // 1. Operator-set canonical hostname (admin Global Settings, system_meta
    //    'setting_telaris_hostname') OR the config.php TELARIS_HOSTNAME constant,
    //    resolved DB-over-constant through the unified settings layer so moving the
    //    value into the DB never changes the resolved host (and thus the keyid).
    if (function_exists('instance_setting_get')) {
        $explicit = instance_setting_get('telaris_hostname');
        if ($explicit !== '') {
            return $explicit;
        }
    } elseif (defined('TELARIS_HOSTNAME') && TELARIS_HOSTNAME !== '') {
        return (string)TELARIS_HOSTNAME;
    }
    if (function_exists('db_system_meta_get')) {
        $cached = db_system_meta_get('federation_local_hostname');
        if (is_string($cached) && $cached !== '') return $cached;
    }
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        $h = (string)$_SERVER['HTTP_HOST'];
        if (str_contains($h, ':')) $h = (string)strstr($h, ':', true);
        $h = strtolower($h);
        if ($h !== '' && function_exists('db_system_meta_set')) {
            try { db_system_meta_set('federation_local_hostname', $h); }
            catch (Throwable $_) { /* cache write is best-effort */ }
        }
        return $h;
    }
    return (string)(gethostname() ?: 'unknown');
}
