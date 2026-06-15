<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * db_get_connections() with fuzzy keyword matching on vs off, asserted through
 * the public db_* helpers against the live schema.
 *
 * Pins the core contract of the fuzzy feature at the data layer:
 *   - fuzzy OFF reproduces today's exact-string behaviour;
 *   - fuzzy ON adds connections between variant keywords (colonial/colonialism);
 *   - the add-only invariant: every exact match still connects with fuzzy on.
 *
 * Fixtures: ephemeral constellation per test, torn down by id.
 */
final class FuzzyConnectionsTest extends TestCase
{
    /** @var list<int> */
    private array $cids = [];

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM node_keywords WHERE node_id IN (SELECT id FROM nodes WHERE constellation_id = :c)")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        $this->cids = [];
    }

    private function makeConstellation(): int
    {
        $sfx = bin2hex(random_bytes(4));
        $cid = db_create_constellation('Fuzzy Conn ' . $sfx, '', 'fconn-' . $sfx, 'cosmic');
        $this->cids[] = $cid;
        return $cid;
    }

    private function makeNode(int $cid, string $name, array $keywords): int
    {
        $anim = '{"radius":5,"theta":1.2,"phi":0.3,"speed":0.0025,"phase":0}';
        $nodeId = db_create_node($name, '', null, $anim, $cid);
        db_save_node_keywords($nodeId, $keywords, null);
        return $nodeId;
    }

    /** @param list<array{node1_id:int,node2_id:int}> $conns */
    private function connected(array $conns, int $a, int $b): bool
    {
        $lo = min($a, $b);
        $hi = max($a, $b);
        foreach ($conns as $c) {
            if ((int)$c['node1_id'] === $lo && (int)$c['node2_id'] === $hi) {
                return true;
            }
        }
        return false;
    }

    public function testFuzzyOffDoesNotConnectVariants(): void
    {
        $cid = $this->makeConstellation();
        $a = $this->makeNode($cid, 'A', ['colonial']);
        $b = $this->makeNode($cid, 'B', ['colonialism']);

        $conns = db_get_connections($cid, false);
        $this->assertFalse($this->connected($conns, $a, $b), 'Exact matching must not connect colonial/colonialism.');
    }

    public function testFuzzyOnConnectsVariants(): void
    {
        $cid = $this->makeConstellation();
        $a = $this->makeNode($cid, 'A', ['colonial']);
        $b = $this->makeNode($cid, 'B', ['colonialism']);
        $c = $this->makeNode($cid, 'C', ['feminism']);

        $conns = db_get_connections($cid, true);
        $this->assertTrue($this->connected($conns, $a, $b), 'Fuzzy must connect colonial/colonialism.');
        $this->assertFalse($this->connected($conns, $a, $c), 'Unrelated keyword must stay unconnected.');
    }

    public function testFuzzyOnConnectsTypo(): void
    {
        $cid = $this->makeConstellation();
        $a = $this->makeNode($cid, 'A', ['colonialism']);
        $b = $this->makeNode($cid, 'B', ['clonialism']);

        $conns = db_get_connections($cid, true);
        $this->assertTrue($this->connected($conns, $a, $b), 'Fuzzy must connect a typo to its keyword.');
    }

    public function testExactMatchSurvivesUnderFuzzy(): void
    {
        // Add-only invariant: a link that exact matching produced must still exist with fuzzy on.
        $cid = $this->makeConstellation();
        $a = $this->makeNode($cid, 'A', ['diaspora']);
        $b = $this->makeNode($cid, 'B', ['diaspora']);

        $this->assertTrue($this->connected(db_get_connections($cid, false), $a, $b));
        $this->assertTrue($this->connected(db_get_connections($cid, true), $a, $b));
    }

    public function testCompoundKeywordSharesToken(): void
    {
        $cid = $this->makeConstellation();
        $a = $this->makeNode($cid, 'A', ['gender-colonialism']);
        $b = $this->makeNode($cid, 'B', ['gender']);
        $c = $this->makeNode($cid, 'C', ['colonialism']);

        $conns = db_get_connections($cid, true);
        $this->assertTrue($this->connected($conns, $a, $b), 'Compound shares the gender token.');
        $this->assertTrue($this->connected($conns, $a, $c), 'Compound shares the colonialism token.');
    }
}
