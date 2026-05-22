<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test for db_prune_keyword_position_history (Fix 12).
 *
 * keyword_position_history is append-only by design (one row per canvas
 * drag). Without periodic pruning it dwarfs the rest of the DB after months
 * of editorial work. The pruner drops rows whose moved_at is older than the
 * configured cutoff; this test pins that behaviour at both edges of the
 * window.
 */
final class PruneHistoryTest extends TestCase
{
    private PDO $pdo;
    private int $galaxyId = 0;
    private int $keywordId = 0;

    protected function setUp(): void
    {
        $this->pdo = getDB();
        $this->cleanup();
        db_ensure_keyword_canvas_tables();
        $this->galaxyId = db_create_constellation(
            'Aitest Prune Galaxy', '',
            'aitest-prune-' . bin2hex(random_bytes(4)), 'cosmic'
        );
        $this->keywordId = db_create_keyword('aitest-prune-kw', $this->galaxyId);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testPruneRemovesOldRowsOnly(): void
    {
        $this->pdo->prepare("INSERT INTO keyword_position_history (keyword_id, canvas_x, canvas_y) VALUES (?, ?, ?)")
            ->execute([$this->keywordId, 10, 20]);
        $this->pdo->prepare("INSERT INTO keyword_position_history (keyword_id, canvas_x, canvas_y) VALUES (?, ?, ?)")
            ->execute([$this->keywordId, 30, 40]);
        $this->pdo->prepare("UPDATE keyword_position_history SET moved_at = (NOW() - INTERVAL 100 DAY) WHERE keyword_id = ? AND canvas_x = ?")
            ->execute([$this->keywordId, 10]);

        db_prune_keyword_position_history(90);

        $remainingForOurKeyword = $this->countRowsForKeyword();
        $this->assertSame(1, $remainingForOurKeyword, 'Recent row must survive the prune; old row must be gone.');

        $stillHasOldX = (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_position_history WHERE keyword_id = {$this->keywordId} AND canvas_x = 10")->fetchColumn();
        $this->assertSame(0, $stillHasOldX);
    }

    public function testPruneRespectsCustomAgeWindow(): void
    {
        $this->pdo->prepare("INSERT INTO keyword_position_history (keyword_id, canvas_x, canvas_y) VALUES (?, ?, ?)")
            ->execute([$this->keywordId, 5, 5]);
        $this->pdo->prepare("UPDATE keyword_position_history SET moved_at = (NOW() - INTERVAL 10 DAY) WHERE keyword_id = ?")
            ->execute([$this->keywordId]);

        db_prune_keyword_position_history(30);
        $this->assertSame(1, $this->countRowsForKeyword(), 'Row inside the 30-day window must survive.');

        db_prune_keyword_position_history(5);
        $this->assertSame(0, $this->countRowsForKeyword(), 'Row outside the 5-day window must be removed.');
    }

    public function testPruneAcceptsEmptyState(): void
    {
        $this->assertSame(0, $this->countRowsForKeyword());
        db_prune_keyword_position_history(90);
        $this->assertSame(0, $this->countRowsForKeyword());
    }

    private function countRowsForKeyword(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM keyword_position_history WHERE keyword_id = {$this->keywordId}")->fetchColumn();
    }

    private function cleanup(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM constellations WHERE slug LIKE 'aitest-prune-%'");
        if ($stmt === false) return;
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo->prepare("DELETE FROM keywords WHERE constellation_id = ?")->execute([(int)$id]);
            $this->pdo->prepare("DELETE FROM constellations WHERE id = ?")->execute([(int)$id]);
        }
    }
}
