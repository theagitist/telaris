<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 4c Pluriverse coord-key cache.
 *
 * Exercises:
 *   - federation_coord_key_get() with TOFU enabled / disabled
 *   - federation_coord_key_tofu_fetch() against live www.telaris.ca
 *   - federation_coord_key_apply_rotation() happy path + idempotency +
 *     mismatch cases
 *   - federation_coord_key_force_replace() clears the previous slot
 *
 * TOFU fetch tests skip when the Pluriverse is unreachable. Rotation
 * tests use synthetic keypairs and seed the cache directly so they do
 * not depend on the network.
 *
 * Spec: P2P federation plan v10 § Pluriverse coordination key rotation.
 */
final class FederationCoordKeyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/coord_key.php';
    }

    protected function setUp(): void
    {
        foreach ([
            'pluriverse_coord_pub_current',
            'pluriverse_coord_pub_previous',
            'pluriverse_coord_pub_previous_expires_at',
        ] as $k) {
            db_system_meta_delete($k);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    private function fp(string $publicKey): string
    {
        return rtrim(strtr(base64_encode(substr(hash('sha256', $publicKey, true), 0, 16)), '+/', '-_'), '=');
    }

    private function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return array{secret:string, public:string}
     */
    private function makeKey(int $seedByte): array
    {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat(chr($seedByte), SODIUM_CRYPTO_SIGN_SEEDBYTES));
        return [
            'secret' => sodium_crypto_sign_secretkey($pair),
            'public' => sodium_crypto_sign_publickey($pair),
        ];
    }

    private function signKeyEvent(array $oldKey, array $payload): string
    {
        $header = [
            'alg' => 'EdDSA',
            'kid' => 'www.telaris.ca:' . $this->fp($oldKey['public']),
            'typ' => FEDERATION_COORD_KEY_EVENT_TYP,
        ];
        $headerB64 = $this->b64u(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = $this->b64u(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = sodium_crypto_sign_detached($headerB64 . '.' . $payloadB64, $oldKey['secret']);
        return $headerB64 . '.' . $payloadB64 . '.' . $this->b64u($sig);
    }

    public function testGetWithoutTofuReturnsNullWhenEmpty(): void
    {
        $this->assertNull(federation_coord_key_get(allowTofu: false));
    }

    public function testTofuFetchAgainstLivePluriverse(): void
    {
        $reachable = $this->reachable();
        if (!$reachable) {
            $this->markTestSkipped('www.telaris.ca unreachable; skipping TOFU fetch test');
        }
        $r = federation_coord_key_tofu_fetch();
        $this->assertTrue($r['ok'], 'TOFU fetch failed: ' . ($r['reason'] ?? ''));
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string)$r['public_key']));
        // Cache was populated.
        $cached = federation_coord_key_get(allowTofu: false);
        $this->assertNotNull($cached);
        $this->assertSame($r['public_key'], $cached);
    }

    public function testApplyRotationHappyPath(): void
    {
        $oldKey = $this->makeKey(0x50);
        $newKey = $this->makeKey(0x51);
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($oldKey['public']));

        $jws = $this->signKeyEvent($oldKey, [
            'event_type' => 'coord_rotation',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'previous_coord_fingerprint' => $this->fp($oldKey['public']),
            'new_coord_public_key' => base64_encode($newKey['public']),
            'new_coord_fingerprint' => $this->fp($newKey['public']),
            'confirmation_window_hours' => 24,
        ]);

        $r = federation_coord_key_apply_rotation($jws);
        $this->assertTrue($r['ok'], 'apply_rotation failed: ' . ($r['reason'] ?? ''));
        $this->assertSame($this->fp($newKey['public']), $r['new_fingerprint']);

        $current = federation_coord_key_get(allowTofu: false);
        $this->assertSame($newKey['public'], $current, 'current slot should hold new key');
        $previous = federation_coord_key_get_previous();
        $this->assertSame($oldKey['public'], $previous, 'previous slot should hold old key');
    }

    public function testApplyRotationIdempotentOnReplay(): void
    {
        $oldKey = $this->makeKey(0x60);
        $newKey = $this->makeKey(0x61);
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($oldKey['public']));

        $jws = $this->signKeyEvent($oldKey, [
            'event_type' => 'coord_rotation',
            'previous_coord_fingerprint' => $this->fp($oldKey['public']),
            'new_coord_public_key' => base64_encode($newKey['public']),
            'new_coord_fingerprint' => $this->fp($newKey['public']),
            'confirmation_window_hours' => 24,
        ]);

        $r1 = federation_coord_key_apply_rotation($jws);
        $this->assertTrue($r1['ok']);

        // Replay: same event, but the cache now holds the new key. The first
        // failure should be the JWS signature check (signed by old key, but
        // current cache is new), not a re-application.
        $r2 = federation_coord_key_apply_rotation($jws);
        $this->assertFalse($r2['ok']);
        $this->assertStringStartsWith('jws_invalid:', $r2['reason']);
    }

    public function testApplyRotationRejectsWrongSigner(): void
    {
        $oldKey = $this->makeKey(0x70);
        $attackerKey = $this->makeKey(0x71);
        $newKey = $this->makeKey(0x72);
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($oldKey['public']));

        $jws = $this->signKeyEvent($attackerKey, [
            'event_type' => 'coord_rotation',
            'previous_coord_fingerprint' => $this->fp($oldKey['public']),
            'new_coord_public_key' => base64_encode($newKey['public']),
            'new_coord_fingerprint' => $this->fp($newKey['public']),
            'confirmation_window_hours' => 24,
        ]);

        $r = federation_coord_key_apply_rotation($jws);
        $this->assertFalse($r['ok']);
        $this->assertStringStartsWith('jws_invalid:', $r['reason']);
    }

    public function testApplyRotationRejectsWrongPayloadFingerprint(): void
    {
        $oldKey = $this->makeKey(0x80);
        $newKey = $this->makeKey(0x81);
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($oldKey['public']));

        $jws = $this->signKeyEvent($oldKey, [
            'event_type' => 'coord_rotation',
            'previous_coord_fingerprint' => $this->fp($oldKey['public']),
            'new_coord_public_key' => base64_encode($newKey['public']),
            'new_coord_fingerprint' => 'WrongFingerprintInThePayload',
            'confirmation_window_hours' => 24,
        ]);

        $r = federation_coord_key_apply_rotation($jws);
        $this->assertFalse($r['ok']);
        $this->assertSame('fingerprint_payload_mismatch', $r['reason']);
    }

    public function testApplyRotationRejectsUnknownPriorAnchor(): void
    {
        $oldKey = $this->makeKey(0x90);
        $newKey = $this->makeKey(0x91);
        $bogusFp = 'BogusPriorAnchor123';
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($oldKey['public']));

        $jws = $this->signKeyEvent($oldKey, [
            'event_type' => 'coord_rotation',
            'previous_coord_fingerprint' => $bogusFp,
            'new_coord_public_key' => base64_encode($newKey['public']),
            'new_coord_fingerprint' => $this->fp($newKey['public']),
            'confirmation_window_hours' => 24,
        ]);

        $r = federation_coord_key_apply_rotation($jws);
        $this->assertFalse($r['ok']);
        $this->assertSame('rotation_anchors_unknown_key', $r['reason']);
    }

    public function testApplyRotationReturnsAlreadyAppliedOnSameKey(): void
    {
        $key = $this->makeKey(0xA0);
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($key['public']));

        $jws = $this->signKeyEvent($key, [
            'event_type' => 'coord_rotation',
            'previous_coord_fingerprint' => $this->fp($key['public']),
            'new_coord_public_key' => base64_encode($key['public']),
            'new_coord_fingerprint' => $this->fp($key['public']),
            'confirmation_window_hours' => 24,
        ]);

        $r = federation_coord_key_apply_rotation($jws);
        $this->assertFalse($r['ok']);
        $this->assertSame('already_applied', $r['reason']);
    }

    public function testForceReplaceClearsPreviousSlot(): void
    {
        $oldKey = $this->makeKey(0xB0);
        $newKey = $this->makeKey(0xB1);
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($oldKey['public']));
        db_system_meta_set('pluriverse_coord_pub_previous', base64_encode($this->makeKey(0xB2)['public']));
        db_system_meta_set('pluriverse_coord_pub_previous_expires_at', date('Y-m-d H:i:s', time() + 3600));

        federation_coord_key_force_replace($newKey['public']);

        $this->assertSame($newKey['public'], federation_coord_key_get(allowTofu: false));
        $this->assertNull(federation_coord_key_get_previous());
        $this->assertNull(db_system_meta_get('pluriverse_coord_pub_previous'));
        $this->assertNull(db_system_meta_get('pluriverse_coord_pub_previous_expires_at'));
    }

    public function testApplyRotationFailsWhenNoCachedCurrent(): void
    {
        $key = $this->makeKey(0xC0);
        $jws = $this->signKeyEvent($key, [
            'event_type' => 'coord_rotation',
            'previous_coord_fingerprint' => $this->fp($key['public']),
            'new_coord_public_key' => base64_encode($this->makeKey(0xC1)['public']),
            'new_coord_fingerprint' => $this->fp($this->makeKey(0xC1)['public']),
            'confirmation_window_hours' => 24,
        ]);
        $r = federation_coord_key_apply_rotation($jws);
        $this->assertFalse($r['ok']);
        $this->assertSame('no_cached_current', $r['reason']);
    }

    public function testPreviousSlotExpires(): void
    {
        $key = $this->makeKey(0xD0);
        $prev = $this->makeKey(0xD1);
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($key['public']));
        db_system_meta_set('pluriverse_coord_pub_previous', base64_encode($prev['public']));

        db_system_meta_set('pluriverse_coord_pub_previous_expires_at', date('Y-m-d H:i:s', time() + 3600));
        $this->assertSame($prev['public'], federation_coord_key_get_previous());

        db_system_meta_set('pluriverse_coord_pub_previous_expires_at', date('Y-m-d H:i:s', time() - 60));
        $this->assertNull(federation_coord_key_get_previous());
    }

    private function reachable(): bool
    {
        $ch = curl_init(FEDERATION_COORD_PLURIVERSE_BASE_URL . '/api/pluriverse/identity');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }
}
