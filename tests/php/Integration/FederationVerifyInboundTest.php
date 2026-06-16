<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 4b HTTP-Sig verifier middleware.
 *
 * Exercises federation_verify_inbound() end-to-end with seeded peers / cached
 * coord key / live seen_nonces table. Covers the layered concerns the bare
 * federation_http_sig_verify() does not handle:
 *   - keyid -> {peer | coord} resolution + fingerprint match
 *   - rotation-grace fallback (previous_public_key)
 *   - dual-tag dispatch (tel-message vs tel-relay)
 *   - replay protection (INSERT IGNORE into seen_nonces)
 *
 * Spec: P2P federation plan v10 § HTTP Signature coverage, § Dual-tag
 * endpoints, § Key management.
 */
final class FederationVerifyInboundTest extends TestCase
{
    private const PEER_HOST = 'verify-inbound-test.example.invalid';
    private const COORD_HOST = 'www.telaris.ca';

    private string $peerSecret;
    private string $peerPublic;
    private string $peerFingerprint;

    private string $peerSecretRotated;
    private string $peerPublicRotated;
    private string $peerFingerprintRotated;

    private string $coordSecret;
    private string $coordPublic;
    private string $coordFingerprint;

    private string $coordSecretPrevious;
    private string $coordPublicPrevious;
    private string $coordFingerprintPrevious;

    private int $peerId = 0;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/sig_verify.php';
    }

    protected function setUp(): void
    {
        $this->peerSecret = sodium_crypto_sign_secretkey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x21", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->peerPublic = sodium_crypto_sign_publickey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x21", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->peerFingerprint = $this->fingerprint($this->peerPublic);

        $this->peerSecretRotated = sodium_crypto_sign_secretkey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x22", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->peerPublicRotated = sodium_crypto_sign_publickey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x22", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->peerFingerprintRotated = $this->fingerprint($this->peerPublicRotated);

        $this->coordSecret = sodium_crypto_sign_secretkey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x30", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->coordPublic = sodium_crypto_sign_publickey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x30", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->coordFingerprint = $this->fingerprint($this->coordPublic);

        $this->coordSecretPrevious = sodium_crypto_sign_secretkey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x31", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->coordPublicPrevious = sodium_crypto_sign_publickey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x31", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        );
        $this->coordFingerprintPrevious = $this->fingerprint($this->coordPublicPrevious);

        $this->seedPeer($this->peerPublic, null);
        $this->cacheCoordKey($this->coordPublic, null, null);
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        $pdo->prepare("DELETE FROM peers WHERE hostname = :h")->execute([':h' => self::PEER_HOST]);
        $pdo->prepare("DELETE FROM seen_nonces WHERE origin_host IN (:h1, :h2)")
            ->execute([':h1' => self::PEER_HOST, ':h2' => self::COORD_HOST]);
        foreach ([
            'pluriverse_coord_pub_current',
            'pluriverse_coord_pub_previous',
            'pluriverse_coord_pub_previous_expires_at',
        ] as $k) {
            db_system_meta_delete($k);
        }
    }

    public function testPeerSignedRoundtrip(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{"status":"accepted"}',
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertTrue($result['ok'], 'expected ok=true, got reason=' . ($result['reason'] ?? ''));
        $this->assertSame('peer', $result['caller_kind']);
        $this->assertSame(self::PEER_HOST, $result['caller_host']);
        $this->assertSame($this->peerId, $result['caller_peer_id']);
        $this->assertSame('tel-handshake', $result['matched_tag']);
        $this->assertFalse($result['used_previous_key']);
    }

    public function testCoordSignedRoundtrip(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/key-events-push',
            host: 'recipient.example',
            keyHost: self::COORD_HOST,
            keyFp: $this->coordFingerprint,
            secret: $this->coordSecret,
            tag: 'tel-key-events',
            body: '{"event_type":"compromise"}',
        );
        $result = federation_verify_inbound($req, 'tel-key-events');
        $this->assertTrue($result['ok'], 'expected ok=true, got reason=' . ($result['reason'] ?? ''));
        $this->assertSame('coord', $result['caller_kind']);
        $this->assertSame(self::COORD_HOST, $result['caller_host']);
    }

    public function testUnknownKeyidReturnsUnknownKeyid(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: 'no-such-peer.example.invalid',
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{}',
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_keyid', $result['reason']);
    }

    public function testFingerprintMismatchAgainstPeer(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprintRotated,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{}',
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_keyid', $result['reason']);
    }

    public function testTagMismatchRejected(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-message',
            body: '{}',
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($result['ok']);
        $this->assertSame('signature_tag_mismatch', $result['reason']);
    }

    public function testExpiredSignatureRejected(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{}',
            created: time() - 200,
            expires: time() - 100,
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($result['ok']);
        $this->assertSame('signature_expired', $result['reason']);
    }

    public function testCreatedOutsideSkewRejected(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{}',
            created: time() - 600,
            expires: time() + 600,
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($result['ok']);
        $this->assertSame('signature_expired', $result['reason']);
    }

    public function testReplayProtectionRejectsSecondSubmission(): void
    {
        $nonce = federation_sig_generate_nonce();
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{}',
            nonce: $nonce,
        );
        $first = federation_verify_inbound($req, 'tel-handshake');
        $this->assertTrue($first['ok'], 'first submission should accept');
        $second = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($second['ok']);
        $this->assertSame('signature_replay', $second['reason']);
    }

    public function testMissingNonceRejected(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{}',
            nonce: '',
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($result['ok']);
        $this->assertSame('missing_param_nonce', $result['reason']);
    }

    public function testRotationGraceAcceptsPreviousKey(): void
    {
        $pdo = getDB();
        $pdo->prepare("UPDATE peers SET previous_public_key = :p WHERE hostname = :h")
            ->execute([':p' => $this->peerPublic, ':h' => self::PEER_HOST]);
        $pdo->prepare("UPDATE peers SET public_key = :p WHERE hostname = :h")
            ->execute([':p' => $this->peerPublicRotated, ':h' => self::PEER_HOST]);

        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{}',
        );
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertTrue($result['ok'], 'rotation grace should accept previous key: ' . ($result['reason'] ?? ''));
        $this->assertTrue($result['used_previous_key']);
    }

    public function testDualTagDispatchPeerForcesTelMessage(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/messages',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-message',
            body: '{}',
        );
        $result = federation_verify_inbound(
            $req,
            ['tel-message', 'tel-relay'],
            ['dual_tag_map' => ['peer' => 'tel-message', 'coord' => 'tel-relay']]
        );
        $this->assertTrue($result['ok']);
        $this->assertSame('tel-message', $result['matched_tag']);
    }

    public function testDualTagDispatchCoordForcesTelRelay(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/messages',
            host: 'recipient.example',
            keyHost: self::COORD_HOST,
            keyFp: $this->coordFingerprint,
            secret: $this->coordSecret,
            tag: 'tel-relay',
            body: '{}',
        );
        $result = federation_verify_inbound(
            $req,
            ['tel-message', 'tel-relay'],
            ['dual_tag_map' => ['peer' => 'tel-message', 'coord' => 'tel-relay']]
        );
        $this->assertTrue($result['ok']);
        $this->assertSame('tel-relay', $result['matched_tag']);
    }

    public function testDualTagDispatchRejectsCoordSigningTelMessage(): void
    {
        // Coord-key signing the peer-only tel-message tag is a category
        // confusion. The dual-tag map pins coord->tel-relay, so this gets
        // rejected as signature_tag_mismatch (the param tag doesn't match
        // the dual-tag map's coord pin).
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/messages',
            host: 'recipient.example',
            keyHost: self::COORD_HOST,
            keyFp: $this->coordFingerprint,
            secret: $this->coordSecret,
            tag: 'tel-message',
            body: '{}',
        );
        $result = federation_verify_inbound(
            $req,
            ['tel-message', 'tel-relay'],
            ['dual_tag_map' => ['peer' => 'tel-message', 'coord' => 'tel-relay']]
        );
        $this->assertFalse($result['ok']);
        $this->assertSame('signature_tag_mismatch', $result['reason']);
    }

    public function testCoordPreviousKeyAcceptedDuringGrace(): void
    {
        $this->cacheCoordKey(
            $this->coordPublic,
            $this->coordPublicPrevious,
            date('Y-m-d H:i:s', time() + 3600),
        );
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/key-events-push',
            host: 'recipient.example',
            keyHost: self::COORD_HOST,
            keyFp: $this->coordFingerprintPrevious,
            secret: $this->coordSecretPrevious,
            tag: 'tel-key-events',
            body: '{}',
        );
        $result = federation_verify_inbound($req, 'tel-key-events');
        $this->assertTrue($result['ok'], 'coord previous-key grace should hold: ' . ($result['reason'] ?? ''));
        $this->assertSame('coord', $result['caller_kind']);
    }

    public function testCoordPreviousKeyRejectedAfterGraceExpires(): void
    {
        $this->cacheCoordKey(
            $this->coordPublic,
            $this->coordPublicPrevious,
            date('Y-m-d H:i:s', time() - 3600),
        );
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/key-events-push',
            host: 'recipient.example',
            keyHost: self::COORD_HOST,
            keyFp: $this->coordFingerprintPrevious,
            secret: $this->coordSecretPrevious,
            tag: 'tel-key-events',
            body: '{}',
        );
        $result = federation_verify_inbound($req, 'tel-key-events');
        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_keyid', $result['reason']);
    }

    public function testBodyTamperingRejected(): void
    {
        $req = $this->signedRequest(
            method: 'POST',
            uri: 'https://recipient.example/api/pluriverse/handshake',
            host: 'recipient.example',
            keyHost: self::PEER_HOST,
            keyFp: $this->peerFingerprint,
            secret: $this->peerSecret,
            tag: 'tel-handshake',
            body: '{"status":"accepted"}',
        );
        // Swap the body after signing — content-digest header still references the
        // original. The verifier rebuilds the digest from the new body and the
        // covered content-digest header value mismatches, signature fails.
        $req['body'] = '{"status":"REJECTED-INJECTED"}';
        $result = federation_verify_inbound($req, 'tel-handshake');
        $this->assertFalse($result['ok']);
        $this->assertSame('body_digest_mismatch', $result['reason']);
    }

    public function testGcReapsExpiredNonces(): void
    {
        $pdo = getDB();
        db_ensure_seen_nonces_table();
        $pdo->prepare("DELETE FROM seen_nonces WHERE origin_host = :h")
            ->execute([':h' => self::PEER_HOST]);
        $old = random_bytes(16);
        $fresh = random_bytes(16);
        $pdo->prepare("INSERT INTO seen_nonces (origin_host, nonce, seen_at) VALUES (:h, :n, :t)")
            ->execute([
                ':h' => self::PEER_HOST,
                ':n' => $old,
                ':t' => date('Y-m-d H:i:s', time() - 86400 * 30),
            ]);
        $pdo->prepare("INSERT INTO seen_nonces (origin_host, nonce, seen_at) VALUES (:h, :n, NOW())")
            ->execute([':h' => self::PEER_HOST, ':n' => $fresh]);
        $removed = federation_seen_nonces_gc(7);
        $this->assertGreaterThanOrEqual(1, $removed);
        $remain = $pdo->prepare("SELECT COUNT(*) FROM seen_nonces WHERE origin_host = :h AND nonce = :n");
        $remain->execute([':h' => self::PEER_HOST, ':n' => $old]);
        $this->assertSame(0, (int)$remain->fetchColumn(), 'old nonce should be GCd');
        $remain->execute([':h' => self::PEER_HOST, ':n' => $fresh]);
        $this->assertSame(1, (int)$remain->fetchColumn(), 'fresh nonce should remain');
    }

    // --- helpers -----------------------------------------------------------

    private function fingerprint(string $publicKey): string {
        return rtrim(strtr(base64_encode(substr(hash('sha256', $publicKey, true), 0, 16)), '+/', '-_'), '=');
    }

    private function seedPeer(string $publicKey, ?string $previousKey): void {
        db_ensure_peers_table();
        $pdo = getDB();
        $pdo->prepare("DELETE FROM peers WHERE hostname = :h")->execute([':h' => self::PEER_HOST]);
        $stmt = $pdo->prepare("
            INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, previous_public_key, label, source, trust_state)
            VALUES (:h, :u, :p, :pk, :prev, :l, 'manual', 'discovered') RETURNING id
        ");
        $stmt->execute([
            ':h' => self::PEER_HOST,
            ':u' => 'https://' . self::PEER_HOST,
            ':p' => 'https://' . self::PEER_HOST . '/api/pluriverse',
            ':pk' => $publicKey,
            ':prev' => $previousKey,
            ':l' => 'Verify-inbound test peer',
        ]);
        $this->peerId = (int)$stmt->fetchColumn();
    }

    private function cacheCoordKey(string $current, ?string $previous, ?string $previousExpiresAt): void {
        db_system_meta_set('pluriverse_coord_pub_current', base64_encode($current));
        if ($previous === null) {
            db_system_meta_delete('pluriverse_coord_pub_previous');
            db_system_meta_delete('pluriverse_coord_pub_previous_expires_at');
        } else {
            db_system_meta_set('pluriverse_coord_pub_previous', base64_encode($previous));
            db_system_meta_set('pluriverse_coord_pub_previous_expires_at', (string)$previousExpiresAt);
        }
    }

    private function signedRequest(
        string $method,
        string $uri,
        string $host,
        string $keyHost,
        string $keyFp,
        string $secret,
        string $tag,
        string $body,
        ?int $created = null,
        ?int $expires = null,
        ?string $nonce = null,
    ): array {
        $created ??= time();
        $expires ??= $created + 300;
        $nonceVal = $nonce ?? federation_sig_generate_nonce();

        $request = [
            'method' => $method,
            'target_uri' => $uri,
            'headers' => [
                'Host' => $host,
                'Date' => gmdate('D, d M Y H:i:s', $created) . ' GMT',
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ];

        $params = [
            'keyid' => $keyHost . ':' . $keyFp,
            'tag' => $tag,
            'created' => $created,
            'expires' => $expires,
        ];
        // The bare signer doesn't know about our nonce param; we splice it
        // into the inner-list manually after sign, so the signature still
        // covers the same components but the params include nonce.
        $signed = federation_http_sig_sign($request, $secret, $params);

        if ($nonceVal !== '') {
            $signed = $this->resignWithNonce($request, $secret, $params, $nonceVal);
        }

        $request['headers']['Signature-Input'] = $signed['signature_input'];
        $request['headers']['Signature'] = $signed['signature'];
        if (isset($signed['content_digest'])) {
            $request['headers']['Content-Digest'] = $signed['content_digest'];
        }
        $request['headers']['Content-Length'] = (string)strlen($body);

        return $request;
    }

    /**
     * Re-sign with the nonce parameter included. The signer takes a fixed
     * params shape; we need nonce as an extra Signature-Input param so the
     * signature covers it. Reach into the helpers to add it.
     */
    private function resignWithNonce(array $request, string $secret, array $params, string $nonce): array {
        $method = strtoupper($request['method']);
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);
        $components = $hasBody
            ? array_merge(FEDERATION_HTTP_SIG_BASE_COMPONENTS, FEDERATION_HTTP_SIG_BODY_COMPONENTS)
            : FEDERATION_HTTP_SIG_BASE_COMPONENTS;

        $finalParams = [
            'created' => (int)$params['created'],
            'expires' => (int)$params['expires'],
            'keyid'   => (string)$params['keyid'],
            'alg'     => FEDERATION_HTTP_SIG_ALG,
            'tag'     => (string)$params['tag'],
            'nonce'   => $nonce,
        ];

        if ($hasBody) {
            $body = (string)($request['body'] ?? '');
            $digest = federation_http_sig_content_digest($body);
            $request['headers']['Content-Digest'] = $digest;
            $request['headers']['Content-Length'] = (string)strlen($body);
        }
        $values = federation_http_sig_component_values($request);
        $base = federation_http_sig_build_base($components, $finalParams, $values);
        $signature = sodium_crypto_sign_detached($base, $secret);

        $out = [
            'signature_input' => 'sig1=' . federation_http_sig_serialize_inner_list($components, $finalParams),
            'signature' => 'sig1=:' . base64_encode($signature) . ':',
        ];
        if ($hasBody) {
            $out['content_digest'] = federation_http_sig_content_digest((string)$request['body']);
        }
        return $out;
    }
}
