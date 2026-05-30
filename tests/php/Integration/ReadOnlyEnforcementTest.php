<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: server-side read-only enforcement for imported and
 * mirrored galaxies (q21 fix).
 *
 * Imported galaxies (constellations.import_source set) and federation mirrors
 * (read_only = TRUE / mirrored_from_peer_id set) are owned upstream. The editor
 * UI hides the write controls, but those are bypassable via direct API calls,
 * so the guard must live server-side. This covers BOTH non-writable kinds:
 *  (a) bridge-imported  (import_source set);
 *  (b) federation mirror (read_only = TRUE + mirrored_from_peer_id set).
 *
 * Two layers are exercised:
 *  - the db_* mutation helpers throw (belt-and-suspenders), and legitimate
 *    internal writers bypass via allowReadOnly: true;
 *  - the API boundary helper api_require_writable_constellation emits HTTP 403
 *    with subcode 403.009 (verified in a subprocess, since api_error exits).
 *
 * A normal (writable) constellation still mutates: no regression.
 *
 * Fixtures mirror GalaxyMirrorTest / GalaxyUnmirrorTest: ephemeral rows, delete
 * by id in teardown (NEVER by real hostname, per the 8ae3963 regression).
 */
final class ReadOnlyEnforcementTest extends TestCase
{
    /** @var list<int> */
    private array $cids = [];
    /** @var list<int> */
    private array $peerIds = [];

    protected function setUp(): void
    {
        db_ensure_constellations_import_source_column();
        db_ensure_federation_attribution_columns();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->cids as $cid) {
            $pdo->prepare("DELETE FROM node_keywords WHERE node_id IN (SELECT id FROM nodes WHERE constellation_id = :c)")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM nodes WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM keywords WHERE constellation_id = :c")->execute([':c' => $cid]);
            $pdo->prepare("DELETE FROM constellations WHERE id = :c")->execute([':c' => $cid]);
        }
        foreach ($this->peerIds as $pid) {
            $pdo->prepare("DELETE FROM peers WHERE id = :p")->execute([':p' => $pid]);
        }
        $this->cids = [];
        $this->peerIds = [];
    }

    public static function nonWritableKinds(): array
    {
        return ['imported' => ['imported'], 'mirror' => ['mirror']];
    }

    /**
     * Create a writable constellation with one node + one keyword, then mark it
     * non-writable per $kind. Returns [cid, nodeId, keywordId].
     *
     * @return array{0:int,1:int,2:int}
     */
    private function makeReadOnly(string $kind): array
    {
        [$cid, $nodeId, $kwId] = $this->makeWritable();
        $pdo = getDB();
        if ($kind === 'imported') {
            $pdo->prepare("UPDATE constellations SET import_source = '{\"kind\":\"mocambos\"}', read_only = FALSE WHERE id = :c")
                ->execute([':c' => $cid]);
        } else { // mirror
            $kp = sodium_crypto_sign_keypair();
            $host = 'ro-origin-' . bin2hex(random_bytes(4)) . '.example.invalid';
            $pdo->prepare("INSERT INTO peers (hostname, url, pluriverse_endpoint, public_key, label) VALUES (:h, :u, :e, :k, 'ro test')")
                ->execute([':h' => $host, ':u' => "https://$host", ':e' => "https://$host/api/pluriverse", ':k' => sodium_crypto_sign_publickey($kp)]);
            $peerId = (int)$pdo->lastInsertId();
            $this->peerIds[] = $peerId;
            $pdo->prepare("UPDATE constellations SET read_only = TRUE, mirrored_from_peer_id = :p WHERE id = :c")
                ->execute([':p' => $peerId, ':c' => $cid]);
        }
        return [$cid, $nodeId, $kwId];
    }

    /** @return array{0:int,1:int,2:int} */
    private function makeWritable(): array
    {
        $sfx = bin2hex(random_bytes(4));
        $cid = db_create_constellation('RO test ' . $sfx, '', 'ro-' . $sfx, 'cosmic');
        $this->cids[] = $cid;
        $nodeId = db_create_node('node-' . $sfx, null, null, '{}', $cid);
        $kwId = db_create_keyword('kw-' . $sfx, $cid, null);
        return [$cid, $nodeId, $kwId];
    }

    /* ---- predicate ----------------------------------------------------- */

    public function testPredicateDetectsImported(): void
    {
        [$cid] = $this->makeReadOnly('imported');
        $this->assertTrue(db_constellation_is_readonly($cid));
    }

    public function testPredicateDetectsMirror(): void
    {
        [$cid] = $this->makeReadOnly('mirror');
        $this->assertTrue(db_constellation_is_readonly($cid));
    }

    public function testPredicateFalseForWritable(): void
    {
        [$cid] = $this->makeWritable();
        $this->assertFalse(db_constellation_is_readonly($cid));
    }

    public function testPredicateFalseForUnknown(): void
    {
        $this->assertFalse(db_constellation_is_readonly(0));
        $this->assertFalse(db_constellation_is_readonly(2000000000));
    }

    /* ---- db_* mutation helpers throw on non-writable ------------------- */

    /** @dataProvider nonWritableKinds */
    public function testUpdateNodeBlocked(string $kind): void
    {
        [, $nodeId] = $this->makeReadOnly($kind);
        $this->expectException(RuntimeException::class);
        db_update_node($nodeId, 'changed', null, null, '{}');
    }

    /** @dataProvider nonWritableKinds */
    public function testDeleteNodeBlocked(string $kind): void
    {
        [, $nodeId] = $this->makeReadOnly($kind);
        $this->expectException(RuntimeException::class);
        db_delete_node($nodeId);
    }

    /** @dataProvider nonWritableKinds */
    public function testSaveNodeKeywordsBlocked(string $kind): void
    {
        [, $nodeId] = $this->makeReadOnly($kind);
        $this->expectException(RuntimeException::class);
        db_save_node_keywords($nodeId, ['illegitimate']);
    }

    /** @dataProvider nonWritableKinds */
    public function testBulkDeleteBlocked(string $kind): void
    {
        [, $nodeId] = $this->makeReadOnly($kind);
        $this->expectException(RuntimeException::class);
        db_bulk_delete_nodes_by_ids([$nodeId]);
    }

    /** @dataProvider nonWritableKinds */
    public function testBulkSetUseImageBlocked(string $kind): void
    {
        [$cid] = $this->makeReadOnly($kind);
        $this->expectException(RuntimeException::class);
        db_bulk_set_nodes_use_image_as_node($cid, true);
    }

    public function testBulkMoveBlockedWhenSourceReadonly(): void
    {
        [$roCid, , $kwId] = $this->makeReadOnly('imported');
        [$writableCid] = $this->makeWritable();
        $this->expectException(RuntimeException::class);
        db_bulk_move_nodes_by_keyword($roCid, $kwId, $writableCid);
    }

    public function testBulkMoveBlockedWhenTargetReadonly(): void
    {
        [$writableCid, , $kwId] = $this->makeWritable();
        [$roCid] = $this->makeReadOnly('mirror');
        $this->expectException(RuntimeException::class);
        db_bulk_move_nodes_by_keyword($writableCid, $kwId, $roCid);
    }

    /* ---- legitimate internal writers bypass ---------------------------- */

    public function testInternalWriterBypassesGuardForKeywords(): void
    {
        [, $nodeId] = $this->makeReadOnly('mirror');
        // The federation materializer / Mocambos sync pass allowReadOnly: true.
        db_save_node_keywords($nodeId, ['mirrored-tag'], allowReadOnly: true);
        $count = (int)getDB()->query("SELECT COUNT(*) FROM node_keywords WHERE node_id = $nodeId")->fetchColumn();
        $this->assertSame(1, $count, 'internal writer with allowReadOnly succeeds');
    }

    public function testInternalWriterBypassesGuardForBulkDelete(): void
    {
        [, $nodeId] = $this->makeReadOnly('imported');
        $deleted = db_bulk_delete_nodes_by_ids([$nodeId], true);
        $this->assertSame(1, $deleted, 'unmirror / sync teardown with allowReadOnly succeeds');
    }

    /* ---- writable constellation: no regression ------------------------- */

    public function testWritableConstellationStillMutable(): void
    {
        [$cid, $nodeId] = $this->makeWritable();
        // update + keywords + bulk-set all succeed.
        db_update_node($nodeId, 'renamed', null, null, '{}');
        db_save_node_keywords($nodeId, ['legit']);
        $this->assertSame(1, (int)getDB()->query("SELECT COUNT(*) FROM node_keywords WHERE node_id = $nodeId")->fetchColumn());
        $affected = db_bulk_set_nodes_use_image_as_node($cid, true);
        $this->assertGreaterThanOrEqual(1, $affected);
        // delete works too.
        db_delete_node($nodeId);
        $this->assertSame(0, (int)getDB()->query("SELECT COUNT(*) FROM nodes WHERE id = $nodeId")->fetchColumn());
    }

    /* ---- API boundary: 403.009 over the wire (subprocess) -------------- */

    private function runGuard(int $cid): string
    {
        $base = dirname(__DIR__, 3);
        $script = 'require ' . var_export($base . '/config.php', true) . ';'
            . 'require ' . var_export($base . '/inc/api-error.php', true) . ';'
            . 'api_require_writable_constellation(' . $cid . ');'
            . 'echo "NO_ERROR";';
        return (string)shell_exec('php -r ' . escapeshellarg($script) . ' 2>/dev/null');
    }

    /** @dataProvider nonWritableKinds */
    public function testApiGuardEmits403009(string $kind): void
    {
        [$cid] = $this->makeReadOnly($kind);
        $out = $this->runGuard($cid);
        $this->assertStringContainsString('"code":"403.009"', $out, 'guard emits the 403.009 problem envelope');
        $this->assertStringContainsString('"status":403', $out);
        $this->assertStringNotContainsString('NO_ERROR', $out, 'guard must not fall through for a read-only galaxy');
    }

    public function testApiGuardSilentForWritable(): void
    {
        [$cid] = $this->makeWritable();
        $out = $this->runGuard($cid);
        $this->assertStringContainsString('NO_ERROR', $out, 'writable galaxy passes the guard');
        $this->assertStringNotContainsString('403.009', $out);
    }
}
