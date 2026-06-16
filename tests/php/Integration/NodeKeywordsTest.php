<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contract for keyword persistence on a wormhole, asserted entirely through
 * the public db_* helpers (db_save_node_keywords / db_get_keywords_for_node).
 *
 * Why this file exists: keyword save is one of the most exercised write paths
 * (every wormhole edit, every Mocambos resync, every federation mirror pull
 * runs it) and it has already broken in production once. The numeric-string
 * regression below (commit a68d6d4) threw a TypeError under strict_types on
 * any node carrying a purely-numeric keyword, which silently corrupted ordinary
 * keyword saves, not just federation. These tests pin the whole contract so the
 * next regression is caught before a user hits it.
 *
 * They assert through the helper API, not raw SQL, so they encode the
 * behaviour that must hold identically before and after the planned MySQL to
 * PostgreSQL migration (see ROADMAP ^pg-migration) and survive the rewrite
 * unchanged. The case-insensitive dedupe contract in particular is a migration
 * tripwire: it depends on a case-insensitive unique constraint on
 * (keyword, constellation_id), which Postgres must reproduce (citext or a
 * lower() expression index) for these assertions to keep passing.
 *
 * Fixtures follow the ReadOnlyEnforcementTest pattern: ephemeral rows in an
 * ephemeral constellation, torn down by id (never by name) so a shared live
 * starmaps DB is left pristine.
 */
final class NodeKeywordsTest extends TestCase
{
    /** @var list<int> constellation ids created during the test */
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

    /**
     * Create an ephemeral constellation with one node and return [cid, nodeId].
     *
     * @return array{0:int,1:int}
     */
    private function makeNode(): array
    {
        $sfx = bin2hex(random_bytes(4));
        $cid = db_create_constellation('KW test ' . $sfx, '', 'kw-' . $sfx, 'cosmic');
        $this->cids[] = $cid;
        $nodeId = db_create_node('node-' . $sfx, null, null, '{}', $cid);
        return [$cid, $nodeId];
    }

    /* ---- the production regression ------------------------------------- */

    /**
     * The a68d6d4 bug: array_keys() coerces numeric-string keys back to ints,
     * the int flows into mb_strtolower(), and strict_types throws a TypeError.
     * It must not throw, and the numeric keywords must round-trip as strings.
     */
    public function testNumericStringKeywordsDoNotThrowAndRoundTrip(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['1', '2', '3']);
        $this->assertSame(['1', '2', '3'], db_get_keywords_for_node($nodeId));
    }

    public function testMixedNumericAndTextKeywords(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['1', 'alpha', '42']);
        $got = db_get_keywords_for_node($nodeId);
        sort($got, SORT_STRING);
        $this->assertSame(['1', '42', 'alpha'], $got);
    }

    /* ---- ordinary contract --------------------------------------------- */

    public function testKeywordsRoundTrip(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['delta', 'alpha', 'charlie']);
        // db_get_keywords_for_node orders by keyword.
        $this->assertSame(['alpha', 'charlie', 'delta'], db_get_keywords_for_node($nodeId));
    }

    public function testWhitespaceIsTrimmed(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['   spaced   ']);
        $this->assertSame(['spaced'], db_get_keywords_for_node($nodeId));
    }

    public function testBlankAndEmptyKeywordsAreDropped(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['valid', '', '   ', "\t"]);
        $this->assertSame(['valid'], db_get_keywords_for_node($nodeId));
    }

    /**
     * The dedupe leans on a case-insensitive unique constraint on
     * (keyword, constellation_id). 'Foo'/'foo'/'FOO' must collapse to one
     * stored keyword and one junction row. This is the migration tripwire:
     * Postgres is case-sensitive by default, so the constraint must be
     * reproduced explicitly for this to keep passing.
     */
    public function testCaseInsensitiveDedupeToOneKeyword(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['Foo', 'foo', 'FOO']);
        $got = db_get_keywords_for_node($nodeId);
        $this->assertCount(1, $got, 'case-variants of one keyword must collapse to a single row');
        $this->assertSame('foo', mb_strtolower($got[0]));
    }

    /**
     * Companion migration tripwire to the case-insensitive dedupe above. MySQL's
     * utf8mb4_unicode_ci collation is ALSO accent-insensitive, so 'café' and
     * 'cafe' collide on the (keyword, constellation_id) unique index and collapse
     * to a single stored keyword today. Postgres' default collation is
     * accent-sensitive, so the migration must reproduce this with a unique index
     * on lower(unaccent(keyword)) (the unaccent extension) for this to keep
     * passing. Pins the current behaviour so the rewrite cannot silently start
     * treating accented multilingual variants as distinct keywords. See
     * ROADMAP ^pg-migration, decision 2.
     */
    public function testAccentInsensitiveDedupeToOneKeyword(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['café', 'cafe', 'CAFÉ']);
        $got = db_get_keywords_for_node($nodeId);
        $this->assertCount(1, $got, 'accent/case-variants of one keyword must collapse to a single row');
    }

    public function testEmptyArrayClearsAllKeywords(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['a', 'b', 'c']);
        $this->assertCount(3, db_get_keywords_for_node($nodeId));
        db_save_node_keywords($nodeId, []);
        $this->assertSame([], db_get_keywords_for_node($nodeId));
    }

    /**
     * Save is a full replace (delete-then-insert), not an append. A second
     * save must leave exactly the second set, with no leftovers from the first.
     */
    public function testResaveReplacesRatherThanAppends(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['alpha', 'beta']);
        db_save_node_keywords($nodeId, ['beta', 'gamma']);
        $this->assertSame(['beta', 'gamma'], db_get_keywords_for_node($nodeId));
    }

    public function testUnicodeKeywordsRoundTrip(): void
    {
        [, $nodeId] = $this->makeNode();
        db_save_node_keywords($nodeId, ['café', '日本語', 'naïve']);
        $got = db_get_keywords_for_node($nodeId);
        $this->assertContains('café', $got);
        $this->assertContains('日本語', $got);
        $this->assertContains('naïve', $got);
        $this->assertCount(3, $got);
    }

    public function testKeywordsAreScopedToTheirOwnNode(): void
    {
        [$cid] = $this->makeNode();
        // Two nodes in the same constellation must not see each other's tags.
        $sfx = bin2hex(random_bytes(4));
        $nodeA = db_create_node('a-' . $sfx, null, null, '{}', $cid);
        $nodeB = db_create_node('b-' . $sfx, null, null, '{}', $cid);
        db_save_node_keywords($nodeA, ['shared', 'only-a']);
        db_save_node_keywords($nodeB, ['shared', 'only-b']);
        $this->assertSame(['only-a', 'shared'], db_get_keywords_for_node($nodeA));
        $this->assertSame(['only-b', 'shared'], db_get_keywords_for_node($nodeB));
    }

    public function testNodeWithNoKeywordsReturnsEmptyArray(): void
    {
        [, $nodeId] = $this->makeNode();
        $this->assertSame([], db_get_keywords_for_node($nodeId));
    }
}
