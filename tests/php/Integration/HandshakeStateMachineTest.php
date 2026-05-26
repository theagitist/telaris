<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 4d handshake state machine.
 *
 * Exercises handshake_initiate_outbound / apply_inbound_round2 /
 * apply_inbound_round3 / register_inbound_round1_via_relay / expire_stale
 * against the live DB. Uses a temp-path override for the instance secret
 * key (same pattern as FederationIdentityTest) so the test does not need
 * read access to /secrets/.
 *
 * Tests cover the v10 three-round shape and the state transitions the
 * library guards:
 *   - initiate: pending_their_response row + outbound message in 'pending'
 *   - duplicate initiation refused while an active row exists
 *   - inbound round 2 (accepted): peer_keys both ways + queued round 3 + state
 *     transitions to accepted_awaiting_complete + peer.trust_state -> whitelisted
 *   - inbound round 2 (rejected): state rejected + reject_reason persisted
 *   - inbound round 3 (complete): state complete + their_call_us key stored
 *   - wrong-state transitions refused at every door
 *   - relay round-1 receiver creates pending_our_response
 *   - expire_stale flips overdue rows
 *
 * Spec: P2P federation plan v10 § Layer 3 → The handshake.
 */
final class HandshakeStateMachineTest extends TestCase
{
    private const REMOTE_HOST = 'handshake-test.example.invalid';
    private int $peerId = 0;

    public static function setUpBeforeClass(): void
    {
        // FEDERATION_SECRET_KEY_PATH may have been defined (immutable) by an
        // earlier test class (e.g. FederationIdentityTest) which then unlinked
        // the file in its tearDownAfterClass. Always ensure a usable key file
        // exists at whatever path the constant resolves to.
        if (!defined('FEDERATION_SECRET_KEY_PATH')) {
            $path = sys_get_temp_dir() . '/handshake_test_' . bin2hex(random_bytes(8)) . '.key';
            define('FEDERATION_SECRET_KEY_PATH', $path);
        }
        file_put_contents(FEDERATION_SECRET_KEY_PATH, sodium_crypto_sign_secretkey(
            sodium_crypto_sign_seed_keypair(str_repeat("\x88", SODIUM_CRYPTO_SIGN_SEEDBYTES))
        ));
        require_once dirname(__DIR__, 3) . '/inc/federation/handshake.php';
    }

    public static function tearDownAfterClass(): void
    {
        // Leave the key file in place — later test classes may rely on it
        // for the same reason this class had to re-create it. Only the very
        // first owner of FEDERATION_SECRET_KEY_PATH (FederationIdentityTest)
        // unlinks; everyone else is a guest.
    }

    protected function setUp(): void
    {
        $pdo = getDB();
        $pdo->prepare("DELETE FROM handshakes WHERE remote_hostname = :h")->execute([':h' => self::REMOTE_HOST]);
        $pdo->prepare("DELETE FROM pluriverse_messages WHERE peer_id IN (SELECT id FROM peers WHERE hostname = :h)")
            ->execute([':h' => self::REMOTE_HOST]);
        $pdo->prepare("DELETE FROM peer_keys WHERE peer_id IN (SELECT id FROM peers WHERE hostname = :h)")
            ->execute([':h' => self::REMOTE_HOST]);
        $pdo->prepare("DELETE FROM peers WHERE hostname = :h")->execute([':h' => self::REMOTE_HOST]);

        $pdo->prepare("
            INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, source, trust_state)
            VALUES (:h, :u, :p, :k, :l, 'manual', 'contacted')
        ")->execute([
            ':h' => self::REMOTE_HOST,
            ':u' => 'https://' . self::REMOTE_HOST,
            ':p' => 'https://' . self::REMOTE_HOST . '/api/pluriverse',
            ':k' => sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\x99", SODIUM_CRYPTO_SIGN_SEEDBYTES))),
            ':l' => 'handshake-test peer',
        ]);
        $this->peerId = (int)$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        $pdo->prepare("DELETE FROM handshakes WHERE remote_hostname = :h")->execute([':h' => self::REMOTE_HOST]);
        $pdo->prepare("DELETE FROM pluriverse_messages WHERE peer_id = :p")->execute([':p' => $this->peerId]);
        $pdo->prepare("DELETE FROM peer_keys WHERE peer_id = :p")->execute([':p' => $this->peerId]);
        $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]);
    }

    public function testInitiateCreatesRowsAndQueuesOutbound(): void
    {
        $r = handshake_initiate_outbound(self::REMOTE_HOST, 'hello', ['my-galaxy']);
        $this->assertTrue($r['ok'], 'initiate failed: ' . ($r['reason'] ?? ''));
        $this->assertGreaterThan(0, $r['handshake_id']);
        $this->assertGreaterThan(0, $r['message_id']);

        $pdo = getDB();
        $hs = $pdo->prepare("SELECT * FROM handshakes WHERE id = :id");
        $hs->execute([':id' => $r['handshake_id']]);
        $row = $hs->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('us', $row['initiator']);
        $this->assertSame('pending_their_response', $row['status']);
        $this->assertSame($r['thread_id'], $row['thread_id']);

        $msg = $pdo->prepare("SELECT delivery_status, direction, message_type, jws_envelope FROM pluriverse_messages WHERE id = :id");
        $msg->execute([':id' => $r['message_id']]);
        $mrow = $msg->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $mrow['delivery_status']);
        $this->assertSame('outbound', $mrow['direction']);
        $this->assertSame('handshake_request', $mrow['message_type']);
        $this->assertCount(3, explode('.', $mrow['jws_envelope']));
    }

    public function testDuplicateInitiationRefused(): void
    {
        $r1 = handshake_initiate_outbound(self::REMOTE_HOST, 'first', []);
        $this->assertTrue($r1['ok']);

        $r2 = handshake_initiate_outbound(self::REMOTE_HOST, 'second', []);
        $this->assertFalse($r2['ok']);
        $this->assertSame('active_handshake_exists', $r2['reason']);
    }

    public function testApplyInboundRound2AcceptedFullDance(): void
    {
        $r1 = handshake_initiate_outbound(self::REMOTE_HOST, 'init', ['g1']);
        $this->assertTrue($r1['ok']);
        $threadId = $r1['thread_id'];

        $remoteKey = base64_encode(random_bytes(32));
        $reply = [
            'status' => 'accepted',
            'thread_id' => $threadId,
            'peer_key_for_caller' => $remoteKey,
            'label' => 'remote',
            'supported_protocol_versions' => ['1.0'],
        ];
        $r = handshake_apply_inbound_round2($this->peerId, $reply, self::REMOTE_HOST);
        $this->assertTrue($r['ok'], 'round2 accepted failed: ' . ($r['reason'] ?? ''));
        $this->assertSame('accepted', $r['status']);
        $this->assertGreaterThan(0, $r['queued_round3_message_id']);

        $pdo = getDB();
        $hs = $pdo->prepare("SELECT status FROM handshakes WHERE id = :id");
        $hs->execute([':id' => $r['handshake_id']]);
        $this->assertSame('accepted_awaiting_complete', $hs->fetchColumn());

        $keys = $pdo->prepare("SELECT direction, is_active FROM peer_keys WHERE peer_id = :p ORDER BY id");
        $keys->execute([':p' => $this->peerId]);
        $rows = $keys->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('they_call_us', $rows[0]['direction']);
        $this->assertSame('we_call_them', $rows[1]['direction']);

        $r3 = $pdo->prepare("SELECT delivery_status FROM pluriverse_messages WHERE id = :id");
        $r3->execute([':id' => $r['queued_round3_message_id']]);
        $this->assertSame('pending', $r3->fetchColumn());

        $peerState = $pdo->prepare("SELECT trust_state FROM peers WHERE id = :p");
        $peerState->execute([':p' => $this->peerId]);
        $this->assertSame('whitelisted', $peerState->fetchColumn());
    }

    public function testApplyInboundRound2Rejected(): void
    {
        $r1 = handshake_initiate_outbound(self::REMOTE_HOST, 'init', []);
        $this->assertTrue($r1['ok']);

        $r = handshake_apply_inbound_round2($this->peerId, [
            'status' => 'rejected',
            'thread_id' => $r1['thread_id'],
            'reason' => 'no thanks',
        ], self::REMOTE_HOST);
        $this->assertTrue($r['ok']);
        $this->assertSame('rejected', $r['status']);

        $pdo = getDB();
        $row = $pdo->prepare("SELECT status, reject_reason FROM handshakes WHERE id = :id");
        $row->execute([':id' => $r['handshake_id']]);
        $hs = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('rejected', $hs['status']);
        $this->assertSame('no thanks', $hs['reject_reason']);

        // Peer reverts to discovered, NOT whitelisted.
        $peerState = $pdo->prepare("SELECT trust_state FROM peers WHERE id = :p");
        $peerState->execute([':p' => $this->peerId]);
        $this->assertSame('discovered', $peerState->fetchColumn());
    }

    public function testApplyInboundRound2RejectsInvalidPeerKey(): void
    {
        $r1 = handshake_initiate_outbound(self::REMOTE_HOST, 'init', []);
        $this->assertTrue($r1['ok']);
        $r = handshake_apply_inbound_round2($this->peerId, [
            'status' => 'accepted',
            'thread_id' => $r1['thread_id'],
            'peer_key_for_caller' => base64_encode('too short'),
            'label' => 'x',
        ], self::REMOTE_HOST);
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_peer_key', $r['reason']);
    }

    public function testApplyInboundRound2RefusesWrongState(): void
    {
        $r1 = handshake_initiate_outbound(self::REMOTE_HOST, 'init', []);
        $this->assertTrue($r1['ok']);
        // Walk it to accepted_awaiting_complete first.
        handshake_apply_inbound_round2($this->peerId, [
            'status' => 'accepted',
            'thread_id' => $r1['thread_id'],
            'peer_key_for_caller' => base64_encode(random_bytes(32)),
            'label' => 'remote',
        ], self::REMOTE_HOST);
        // Now a second round-2 should refuse.
        $r = handshake_apply_inbound_round2($this->peerId, [
            'status' => 'accepted',
            'thread_id' => $r1['thread_id'],
            'peer_key_for_caller' => base64_encode(random_bytes(32)),
            'label' => 'remote',
        ], self::REMOTE_HOST);
        $this->assertFalse($r['ok']);
        $this->assertStringStartsWith('wrong_state:', $r['reason']);
    }

    public function testApplyInboundRound3CompletesHandshake(): void
    {
        // Setup: us-initiated handshake, fast-forward to accepted_awaiting_complete.
        // Round 3 in v10 is sent BY US TO THEM (we initiated), so the
        // round-3-received path is for handshakes WE initiated, where the
        // *remote* answered "accepted" and then we sent "complete". Wait:
        // re-reading v10... round 3 IS the initiator's complete-back. So the
        // receiver of round 3 is the *acceptor* (B), whose state is
        // accepted_awaiting_complete. Test that.
        $pdo = getDB();
        $threadId = handshake_generate_thread_id();
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        $pdo->prepare("
            INSERT INTO handshakes
                (peer_id, remote_hostname, initiator, status, thread_id, expires_at)
            VALUES (:p, :h, 'them', 'accepted_awaiting_complete', :t, :e)
        ")->execute([':p' => $this->peerId, ':h' => self::REMOTE_HOST, ':t' => $threadId, ':e' => $expiresAt]);
        $hsId = (int)$pdo->lastInsertId();

        $remoteKey = base64_encode(random_bytes(32));
        $r = handshake_apply_inbound_round3($this->peerId, [
            'status' => 'complete',
            'thread_id' => $threadId,
            'peer_key_for_caller' => $remoteKey,
            'label' => 'remote',
            'supported_protocol_versions' => ['1.0'],
        ], self::REMOTE_HOST);
        $this->assertTrue($r['ok'], 'round3 failed: ' . ($r['reason'] ?? ''));
        $this->assertSame('complete', $r['status']);
        $this->assertSame($hsId, $r['handshake_id']);

        $st = $pdo->prepare("SELECT status FROM handshakes WHERE id = :id");
        $st->execute([':id' => $hsId]);
        $this->assertSame('complete', $st->fetchColumn());

        $keys = $pdo->prepare("SELECT direction FROM peer_keys WHERE peer_id = :p");
        $keys->execute([':p' => $this->peerId]);
        $this->assertSame(['they_call_us'], $keys->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testApplyInboundRound3RefusesWrongState(): void
    {
        $r1 = handshake_initiate_outbound(self::REMOTE_HOST, 'init', []);
        $this->assertTrue($r1['ok']);
        // State is pending_their_response, not accepted_awaiting_complete.
        $r = handshake_apply_inbound_round3($this->peerId, [
            'status' => 'complete',
            'thread_id' => $r1['thread_id'],
            'peer_key_for_caller' => base64_encode(random_bytes(32)),
            'label' => 'remote',
        ], self::REMOTE_HOST);
        $this->assertFalse($r['ok']);
        $this->assertStringStartsWith('wrong_state:', $r['reason']);
    }

    public function testApplyInboundRound2NoMatchingHandshake(): void
    {
        $r = handshake_apply_inbound_round2($this->peerId, [
            'status' => 'accepted',
            'thread_id' => 'no-such-thread',
            'peer_key_for_caller' => base64_encode(random_bytes(32)),
            'label' => 'remote',
        ], self::REMOTE_HOST);
        $this->assertFalse($r['ok']);
        $this->assertSame('no_matching_handshake', $r['reason']);
    }

    public function testRegisterInboundRound1ViaRelay(): void
    {
        // Simulate a relay-forwarded handshake_request landing via /messages.
        $pdo = getDB();
        $threadId = handshake_generate_thread_id();
        $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, jws_envelope, delivery_status)
            VALUES (:p, 'inbound', :t, 'handshake_request', :j, 'not_applicable')
        ")->execute([':p' => $this->peerId, ':t' => $threadId, ':j' => 'header.payload.sig']);
        $msgId = (int)$pdo->lastInsertId();

        $r = handshake_register_inbound_round1_via_relay(
            self::REMOTE_HOST,
            $threadId,
            ['requested_galaxies_publish' => ['g1'], 'requested_galaxies_subscribe' => ['g2']],
            $msgId,
        );
        $this->assertTrue($r['ok']);

        $hs = $pdo->prepare("SELECT initiator, status, peer_id, thread_id FROM handshakes WHERE id = :id");
        $hs->execute([':id' => $r['handshake_id']]);
        $row = $hs->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('them', $row['initiator']);
        $this->assertSame('pending_our_response', $row['status']);
        $this->assertSame($threadId, $row['thread_id']);
        $this->assertSame($this->peerId, (int)$row['peer_id']);
    }

    public function testRegisterInboundRound1DuplicateThreadRefused(): void
    {
        $pdo = getDB();
        $threadId = handshake_generate_thread_id();
        $pdo->prepare("
            INSERT INTO handshakes (peer_id, remote_hostname, initiator, status, thread_id, expires_at)
            VALUES (:p, :h, 'them', 'pending_our_response', :t, :e)
        ")->execute([
            ':p' => $this->peerId,
            ':h' => self::REMOTE_HOST,
            ':t' => $threadId,
            ':e' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $r = handshake_register_inbound_round1_via_relay(self::REMOTE_HOST, $threadId, [], 0);
        $this->assertFalse($r['ok']);
        $this->assertSame('duplicate_thread', $r['reason']);
    }

    public function testExpireStaleFlipsPendingRows(): void
    {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO handshakes (peer_id, remote_hostname, initiator, status, thread_id, expires_at)
            VALUES (:p, :h, 'us', 'pending_their_response', :t, :e)
        ")->execute([
            ':p' => $this->peerId,
            ':h' => self::REMOTE_HOST,
            ':t' => handshake_generate_thread_id(),
            ':e' => date('Y-m-d H:i:s', time() - 86400),
        ]);
        $count = handshake_expire_stale();
        $this->assertGreaterThanOrEqual(1, $count);
        $stale = $pdo->prepare("SELECT status FROM handshakes WHERE remote_hostname = :h AND initiator = 'us'");
        $stale->execute([':h' => self::REMOTE_HOST]);
        $this->assertSame('expired', $stale->fetchColumn());
    }

    public function testThreadIdGeneratorIsUnique(): void
    {
        $a = handshake_generate_thread_id();
        $b = handshake_generate_thread_id();
        $this->assertNotSame($a, $b);
        $this->assertSame(22, strlen($a)); // 16 bytes base64url unpadded
    }

    // --- 4e admin-initiated actions -------------------------------------

    public function testAcceptInboundQueuesRound2AndUpdatesState(): void
    {
        $pdo = getDB();
        $threadId = handshake_generate_thread_id();
        $pdo->prepare("
            INSERT INTO handshakes
                (peer_id, remote_hostname, initiator, status, thread_id, expires_at)
            VALUES (:p, :h, 'them', 'pending_our_response', :t, :e)
        ")->execute([
            ':p' => $this->peerId,
            ':h' => self::REMOTE_HOST,
            ':t' => $threadId,
            ':e' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $hsId = (int)$pdo->lastInsertId();

        $r = handshake_accept_inbound($hsId);
        $this->assertTrue($r['ok'], 'accept failed: ' . ($r['reason'] ?? ''));
        $this->assertGreaterThan(0, $r['queued_message_id']);

        $row = $pdo->prepare("SELECT status FROM handshakes WHERE id = :id");
        $row->execute([':id' => $hsId]);
        $this->assertSame('accepted_awaiting_complete', $row->fetchColumn());

        $keys = $pdo->prepare("SELECT direction FROM peer_keys WHERE peer_id = :p ORDER BY id");
        $keys->execute([':p' => $this->peerId]);
        $this->assertSame(['we_call_them'], $keys->fetchAll(PDO::FETCH_COLUMN));

        $msg = $pdo->prepare("SELECT delivery_status, message_type FROM pluriverse_messages WHERE id = :id");
        $msg->execute([':id' => $r['queued_message_id']]);
        $mrow = $msg->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $mrow['delivery_status']);
        $this->assertSame('handshake_response', $mrow['message_type']);
    }

    public function testAcceptInboundRefusesWhenPeerNotInDirectory(): void
    {
        $pdo = getDB();
        // Delete the peer row but leave the handshake referencing a nonexistent hostname.
        $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]);
        $threadId = handshake_generate_thread_id();
        $pdo->prepare("
            INSERT INTO handshakes
                (peer_id, remote_hostname, initiator, status, thread_id, expires_at)
            VALUES (NULL, :h, 'them', 'pending_our_response', :t, :e)
        ")->execute([
            ':h' => 'totally-not-in-directory.example.invalid',
            ':t' => $threadId,
            ':e' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $hsId = (int)$pdo->lastInsertId();
        $r = handshake_accept_inbound($hsId);
        $this->assertFalse($r['ok']);
        $this->assertSame('peer_not_in_directory', $r['reason']);
        $pdo->prepare("DELETE FROM handshakes WHERE id = :id")->execute([':id' => $hsId]);
    }

    public function testRejectInboundQueuesOutboundAndTransitions(): void
    {
        $pdo = getDB();
        $threadId = handshake_generate_thread_id();
        $pdo->prepare("
            INSERT INTO handshakes
                (peer_id, remote_hostname, initiator, status, thread_id, expires_at)
            VALUES (:p, :h, 'them', 'pending_our_response', :t, :e)
        ")->execute([
            ':p' => $this->peerId,
            ':h' => self::REMOTE_HOST,
            ':t' => $threadId,
            ':e' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $hsId = (int)$pdo->lastInsertId();

        $r = handshake_reject_inbound($hsId, 'not federating with you');
        $this->assertTrue($r['ok']);

        $row = $pdo->prepare("SELECT status, reject_reason FROM handshakes WHERE id = :id");
        $row->execute([':id' => $hsId]);
        $hs = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('rejected', $hs['status']);
        $this->assertSame('not federating with you', $hs['reject_reason']);

        // Reject does NOT create peer_keys.
        $kc = $pdo->prepare("SELECT COUNT(*) FROM peer_keys WHERE peer_id = :p");
        $kc->execute([':p' => $this->peerId]);
        $this->assertSame(0, (int)$kc->fetchColumn());
    }

    public function testCancelOutboundLocalOnly(): void
    {
        $r1 = handshake_initiate_outbound(self::REMOTE_HOST, 'init', []);
        $this->assertTrue($r1['ok']);

        $cancelled = handshake_cancel_outbound($r1['handshake_id']);
        $this->assertTrue($cancelled['ok']);

        $pdo = getDB();
        $row = $pdo->prepare("SELECT status FROM handshakes WHERE id = :id");
        $row->execute([':id' => $r1['handshake_id']]);
        $this->assertSame('cancelled', $row->fetchColumn());

        // The initial outbound message should be marked given_up so the
        // dispatcher leaves it alone.
        $msg = $pdo->prepare("SELECT delivery_status FROM pluriverse_messages WHERE id = :id");
        $msg->execute([':id' => $r1['message_id']]);
        $this->assertSame('given_up', $msg->fetchColumn());
    }

    public function testCancelOutboundRefusesInboundHandshake(): void
    {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO handshakes
                (peer_id, remote_hostname, initiator, status, thread_id, expires_at)
            VALUES (:p, :h, 'them', 'pending_our_response', :t, :e)
        ")->execute([
            ':p' => $this->peerId,
            ':h' => self::REMOTE_HOST,
            ':t' => handshake_generate_thread_id(),
            ':e' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $hsId = (int)$pdo->lastInsertId();
        $r = handshake_cancel_outbound($hsId);
        $this->assertFalse($r['ok']);
        $this->assertSame('cannot_cancel_inbound', $r['reason']);
    }

    public function testRetryOutboundResetsFailedMessage(): void
    {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, jws_envelope,
                 delivery_status, attempt_count, next_attempt_at, last_attempt_error)
            VALUES (:p, 'outbound', :t, 'handshake_request', 'h.p.s',
                    'failed', 3, DATE_ADD(NOW(), INTERVAL 1 HOUR), 'http_500')
        ")->execute([':p' => $this->peerId, ':t' => handshake_generate_thread_id()]);
        $mid = (int)$pdo->lastInsertId();

        $r = handshake_retry_outbound($mid);
        $this->assertTrue($r['ok']);
        $st = $pdo->prepare("SELECT delivery_status, next_attempt_at FROM pluriverse_messages WHERE id = :id");
        $st->execute([':id' => $mid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['delivery_status']);
        $this->assertNull($row['next_attempt_at']);
    }

    public function testRetryOutboundRefusesNonRetryable(): void
    {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO pluriverse_messages
                (peer_id, direction, thread_id, message_type, jws_envelope,
                 delivery_status, attempt_count)
            VALUES (:p, 'outbound', :t, 'handshake_request', 'h.p.s', 'delivered', 1)
        ")->execute([':p' => $this->peerId, ':t' => handshake_generate_thread_id()]);
        $mid = (int)$pdo->lastInsertId();
        $r = handshake_retry_outbound($mid);
        $this->assertFalse($r['ok']);
        $this->assertSame('message_not_retryable', $r['reason']);
    }
}
