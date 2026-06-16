<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 6e instance side, the peer-blacklist-notice enqueue.
 *
 * federation_send_peer_blacklist_notice() enqueues an advisory notice onto the
 * outbound dispatcher queue when an operator blocks a peer (6d). This asserts
 * the enqueued row and the dispatcher's delivery classification without any
 * network: the body carries blacklisted_hostname / blacklisted_at / reason /
 * category and must NOT carry a reporter field (the reporter is asserted by the
 * HTTP-Sig keyid at delivery), and the dispatcher routes it to the Pluriverse
 * peer-blacklist-notice endpoint with tag tel-bl-notice.
 *
 * The Pluriverse-side endpoint has no PHPUnit suite and is smoke-verified.
 *
 * Spec: Stage 6 trust revocation design (6e).
 */
final class PeerBlacklistNoticeTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/peer_blacklist_notice.php';
        require_once dirname(__DIR__, 3) . '/inc/federation/dispatcher.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_pluriverse_messages_table();
        db_ensure_pluriverse_messages_retry_columns();

        $kp = sodium_crypto_sign_keypair();
        $this->host = 'reported-' . bin2hex(random_bytes(4)) . '.example.invalid';
        $pdo = getDB();
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label, trust_state)
                       VALUES (:h, :u, :e, :k, '6e test', 'blocked') RETURNING id");
        $ins->execute([':h' => $this->host, ':u' => "https://{$this->host}", ':e' => "https://{$this->host}/api/pluriverse", ':k' => sodium_crypto_sign_publickey($kp)]);
        $this->peerId = (int)$ins->fetchColumn();
    }

    protected function tearDown(): void
    {
        // peer FK CASCADEs pluriverse_messages rows.
        if ($this->peerId > 0) {
            getDB()->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]);
        }
    }

    private function fetchRow(int $messageId): array
    {
        $stmt = getDB()->prepare("SELECT * FROM pluriverse_messages WHERE id = :id");
        $stmt->execute([':id' => $messageId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testEnqueueComposesBodyWithoutReporter(): void
    {
        $res = federation_send_peer_blacklist_notice($this->peerId, 'persistent spam from this peer', 'spam');
        $this->assertTrue($res['ok']);
        $row = $this->fetchRow((int)$res['message_id']);

        $this->assertSame('peer_blacklist_notice', $row['message_type']);
        $this->assertSame('outbound', $row['direction']);
        $this->assertSame('pending', $row['delivery_status']);

        $body = json_decode((string)$row['jws_envelope'], true);
        $this->assertSame($this->host, $body['blacklisted_hostname']);
        $this->assertSame('persistent spam from this peer', $body['reason']);
        $this->assertSame('spam', $body['category']);
        $this->assertArrayHasKey('blacklisted_at', $body);
        // Reporter must NOT be in the body: it is asserted by the signing keyid.
        $this->assertArrayNotHasKey('reporter', $body);
        $this->assertArrayNotHasKey('reporter_hostname', $body);
    }

    public function testDispatcherClassifiesNoticeToPluriverseWithTag(): void
    {
        $res = federation_send_peer_blacklist_notice($this->peerId, 'legal takedown', 'legal');
        $row = $this->fetchRow((int)$res['message_id']);

        $shape = federation_dispatcher_classify_delivery($row);
        $this->assertNotNull($shape);
        [$targetUri, $tag, $bodyOut] = $shape;
        $this->assertSame(FEDERATION_DISPATCH_BLACKLIST_NOTICE_URL, $targetUri);
        $this->assertSame('tel-bl-notice', $tag);
        $this->assertSame((string)$row['jws_envelope'], $bodyOut, 'delivered body is the stored notice JSON verbatim');
    }

    public function testUnknownPeerIsHandledGracefully(): void
    {
        $res = federation_send_peer_blacklist_notice(0, 'reason', 'other');
        $this->assertFalse($res['ok']);
        $this->assertSame('peer_not_found', $res['error']);
    }
}
