<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 5d-i pull diff + published.json normalization.
 *
 * federation_pull_diff classifies each active subscription against the peer's
 * published index. The signed-GET transport (federation_peer_signed_get) is
 * not exercised here: it needs the instance key and a live peer, and is
 * verified in the 5d-v wiring. The diff + the wire-shape normalizer are the
 * pure decision logic and the highest-value units.
 *
 * Spec: Stage 5 galaxy publish design (5d-i).
 */
final class GalaxyPullDiffTest extends TestCase
{
    private int $peerId = 0;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/galaxy_pull.php';
    }

    protected function setUp(): void
    {
        db_ensure_peers_table();
        db_ensure_galaxy_subscriptions_table();
        $pdo = getDB();
        $sfx = bin2hex(random_bytes(4));
        $ins = $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label)
                       VALUES (:h, :u, :e, decode(:k, 'hex'), 'pull diff peer') RETURNING id");
        $ins->execute([
                ':h' => "pull-$sfx.example.invalid",
                ':u' => "https://pull-$sfx.example.invalid",
                ':e' => "https://pull-$sfx.example.invalid/api/pluriverse",
                ':k' => bin2hex(random_bytes(32)),
            ]);
        $this->peerId = (int)$ins->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->peerId > 0) {
            getDB()->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $this->peerId]); // CASCADEs subscriptions
        }
    }

    private function subscribe(string $slug, ?string $lastHash, ?int $lastSeq): void
    {
        getDB()->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, last_content_hash, last_received_sequence, is_active)
                          VALUES (:p, :s, :h, :q, TRUE)")
            ->execute([':p' => $this->peerId, ':s' => $slug, ':h' => $lastHash, ':q' => $lastSeq]);
    }

    private function entry(string $slug, int $seq, string $hash): array
    {
        return ['slug' => $slug, 'published_sequence' => $seq, 'content_hash' => $hash, 'published_at' => gmdate('c')];
    }

    public function testClassifies(): void
    {
        $h = fn(string $s) => hash('sha256', $s);
        // never-pulled subscription, offered -> to_pull
        $this->subscribe('fresh', null, null);
        // mirrored at an old hash + seq, offered at a new hash + higher seq -> to_pull
        $this->subscribe('changed', $h('old'), 2);
        // mirrored at the current hash -> unchanged
        $this->subscribe('same', $h('cur'), 5);
        // subscribed but not offered -> withdrawn
        $this->subscribe('gone', $h('x'), 3);
        // hash changed but sequence did not advance -> stale (rollback/replay)
        $this->subscribe('rolledback', $h('a'), 9);

        $published = [
            $this->entry('fresh', 1, $h('fresh-content')),
            $this->entry('changed', 7, $h('new')),
            $this->entry('same', 5, $h('cur')),
            $this->entry('rolledback', 9, $h('b')),       // same seq, different hash
            $this->entry('not-subscribed', 1, $h('ns')),  // offered but we do not subscribe -> ignored
        ];

        $d = federation_pull_diff($this->peerId, $published);

        $this->assertSame(['changed', 'fresh'], $this->sortedSlugs($d['to_pull']));
        $this->assertSame(['same'], $d['unchanged']);
        $this->assertSame(['gone'], $d['withdrawn']);
        $this->assertSame(['rolledback'], $d['stale']);

        // to_pull rows carry what materialization needs.
        $changed = $this->row($d['to_pull'], 'changed');
        $this->assertSame(7, $changed['published_sequence']);
        $this->assertSame($h('new'), $changed['content_hash']);
        $this->assertSame(2, $changed['last_received_sequence']);
    }

    public function testInactiveSubscriptionIgnored(): void
    {
        getDB()->prepare("INSERT INTO galaxy_subscriptions (peer_id, remote_slug, is_active) VALUES (:p, 'inactive', FALSE)")
            ->execute([':p' => $this->peerId]);
        $d = federation_pull_diff($this->peerId, [$this->entry('inactive', 1, hash('sha256', 'z'))]);
        $this->assertSame([], $d['to_pull']);
        $this->assertSame([], $d['withdrawn']);
    }

    public function testNormalizeDropsMalformed(): void
    {
        $good = hash('sha256', 'ok');
        $raw = [
            ['slug' => 'ok', 'published_sequence' => 3, 'content_hash' => $good, 'published_at' => 'now'],
            ['slug' => 'Bad_Upper', 'published_sequence' => 1, 'content_hash' => $good], // bad slug
            ['slug' => 'badhash', 'published_sequence' => 1, 'content_hash' => 'xyz'],   // bad hash
            ['slug' => 'noseq', 'content_hash' => $good],                                 // missing seq
            'not-an-array',
            ['slug' => 'strseq', 'published_sequence' => '4', 'content_hash' => $good],   // numeric string ok
        ];
        $norm = federation_pull_normalize_published($raw);
        $slugs = array_column($norm, 'slug');
        sort($slugs);
        $this->assertSame(['ok', 'strseq'], $slugs);
        $this->assertSame(4, $norm[array_search('strseq', array_column($norm, 'slug'), true)]['published_sequence']);
    }

    /** @param list<array<string,mixed>> $rows */
    private function sortedSlugs(array $rows): array
    {
        $s = array_map(fn($r) => (string)$r['slug'], $rows);
        sort($s);
        return $s;
    }

    /** @param list<array<string,mixed>> $rows */
    private function row(array $rows, string $slug): array
    {
        foreach ($rows as $r) if ($r['slug'] === $slug) return $r;
        $this->fail("row $slug not found");
    }
}
