<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5f mirror-inspection + honoured-retractions views.
 *
 * federation_mirror_inspection_view() lists every subscription with its
 * derived status (active / pending / fossilized / paused) and a join onto
 * the local mirror constellation (when materialized) for the node count.
 * federation_remote_retractions_recent() lists the audit trail left by
 * federation_pull_apply_retracted (5d-iv) after the mirror was dropped.
 *
 * Spec: Stage 5 galaxy publish design (5f).
 */
final class MirrorInspectionViewTest extends TestCase
{
    private int $peerId = 0;
    private string $host = '';
    /** @var list<int> */
    private array $cids = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_unmirror.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        db_ensure_federation_attribution_columns();
        db_ensure_remote_retractions_table();
        $sfx = bin2hex(random_bytes(4));
        $this->host = "inspect-$sfx.example.invalid";
        $pdo = getDB();
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, :k, 'inspect test') RETURNING id");
        $ins->execute([
                ':h' => $this->host,
                ':u' => "https://{$this->host}",
                ':e' => "https://{$this->host}/api/pluriverse",
                ':k' => random_bytes(32),
            ]);
        $this->peerId = (int)$ins->fetchColumn();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("UPDATE galaxy_subscriptions SET local_constellation_id = NULL WHERE local_constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        if ($this->peerId > 0) {
            // CASCADEs subscriptions and remote_retractions.
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]);
        }
    }

    private function mkMirror(string $remoteSlug): int
    {
        $cid = db_create_constellation('Mirror ' . $remoteSlug, '', 'mirror-' . bin2hex(random_bytes(3)), 'cosmic');
        $this->cids[] = $cid;
        getDB()->prepare("UPDATE constellations SET mirrored_from_peer_id = :p, read_only = TRUE WHERE id = :id")
            ->execute([':p' => $this->peerId, ':id' => $cid]);
        return $cid;
    }

    private function mkSub(string $remoteSlug, ?int $cid, bool $isActive = true, ?string $fossilizedReason = null): void
    {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO galaxy_subscriptions (peer_id, remote_slug, local_constellation_id, last_received_sequence, last_content_hash, is_active, fossilized_at, fossilized_reason)
            VALUES (:p, :s, :cid, :seq, :hash, :act, :fat, :fr)
        ")->execute([
            ':p' => $this->peerId,
            ':s' => $remoteSlug,
            ':cid' => $cid,
            ':seq' => $cid !== null ? 1 : null,
            ':hash' => $cid !== null ? str_repeat('a', 64) : null,
            ':act' => $isActive ? 1 : 0,
            ':fat' => $fossilizedReason !== null ? gmdate('Y-m-d H:i:s') : null,
            ':fr' => $fossilizedReason,
        ]);
    }

    public function testActiveMirrorAppearsWithNodeCount(): void
    {
        $cid = $this->mkMirror('coastal-plants');
        $pdo = getDB();
        // Two cheap node rows to test the node_count join.
        for ($i = 0; $i < 2; $i++) {
            $pdo->prepare("INSERT INTO nodes (constellation_id, name, animation) VALUES (:c, :n, '{}')")
                ->execute([':c' => $cid, ':n' => "n-$i-" . bin2hex(random_bytes(2))]);
        }
        $this->mkSub('coastal-plants', $cid);

        $view = federation_mirror_inspection_view();
        $row = null;
        foreach ($view as $r) if ($r['remote_slug'] === 'coastal-plants' && $r['peer_id'] === $this->peerId) $row = $r;
        $this->assertNotNull($row);
        $this->assertSame('active', $row['status']);
        $this->assertSame($cid, $row['local_constellation_id']);
        $this->assertSame(2, $row['node_count']);
        $this->assertSame(1, $row['last_received_sequence']);
        $this->assertSame($this->host, $row['origin_host']);
    }

    public function testPendingSubscriptionAppearsWithoutMirror(): void
    {
        $this->mkSub('not-yet-pulled', null);
        $view = federation_mirror_inspection_view();
        $row = null;
        foreach ($view as $r) if ($r['remote_slug'] === 'not-yet-pulled' && $r['peer_id'] === $this->peerId) $row = $r;
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['local_constellation_id']);
        $this->assertSame(0, $row['node_count']);
    }

    public function testFossilizedSurfacesReasonField(): void
    {
        $cid = $this->mkMirror('withdrawn-by-origin');
        $this->mkSub('withdrawn-by-origin', $cid, false, 'origin_withdrawal');
        $view = federation_mirror_inspection_view();
        $row = null;
        foreach ($view as $r) if ($r['remote_slug'] === 'withdrawn-by-origin' && $r['peer_id'] === $this->peerId) $row = $r;
        $this->assertNotNull($row);
        $this->assertSame('fossilized', $row['status']);
        $this->assertSame('origin_withdrawal', $row['fossilized_reason']);
        $this->assertNotNull($row['fossilized_at']);
    }

    public function testPausedSubscriptionDistinguishedFromFossilized(): void
    {
        // is_active=FALSE with NO fossilized_at = operator-paused.
        $cid = $this->mkMirror('paused-by-operator');
        $this->mkSub('paused-by-operator', $cid, false, null);
        $view = federation_mirror_inspection_view();
        $row = null;
        foreach ($view as $r) if ($r['remote_slug'] === 'paused-by-operator' && $r['peer_id'] === $this->peerId) $row = $r;
        $this->assertNotNull($row);
        $this->assertSame('paused', $row['status']);
        $this->assertNull($row['fossilized_reason']);
    }

    public function testRemoteRetractionsRecentIsNewestFirst(): void
    {
        $pdo = getDB();
        // Insert two retractions with different honored_at values.
        $pdo->prepare("INSERT INTO remote_retractions (peer_id, remote_slug, retracted_at, reason, retraction_jws, honored_at)
                       VALUES (:p, 'older', '2026-05-01 00:00:00', 'r1', 'aaa.bbb.ccc', '2026-05-01 10:00:00')")
            ->execute([':p' => $this->peerId]);
        $pdo->prepare("INSERT INTO remote_retractions (peer_id, remote_slug, retracted_at, reason, retraction_jws, honored_at)
                       VALUES (:p, 'newer', '2026-05-15 00:00:00', 'r2', 'ddd.eee.fff', '2026-05-15 10:00:00')")
            ->execute([':p' => $this->peerId]);

        $list = federation_remote_retractions_recent(10);
        $mine = array_values(array_filter($list, fn($r) => $r['peer_id'] === $this->peerId));
        $this->assertCount(2, $mine);
        $this->assertSame('newer', $mine[0]['remote_slug'], 'newest honored_at first');
        $this->assertSame('older', $mine[1]['remote_slug']);
        $this->assertSame($this->host, $mine[0]['origin_host']);
        $this->assertSame('r2', $mine[0]['reason']);
    }
}
