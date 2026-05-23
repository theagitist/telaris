<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the audit_events helpers (audit Med-I).
 *
 * Pre-fix the codebase had no central audit log for admin / editor
 * mutations; the only append-only history was keyword_position_history.
 * db_audit_log(action, actor, target_type, target_id, details, ip, email)
 * records each meaningful action so an operator can answer "who deleted
 * the snapshot last week?" without grepping web-server logs.
 *
 * The helper is fail-open by design: a broken audit pipeline must never
 * stop the work it observes. The tests pin the shape (column population),
 * the JSON encoding of details, and the never-throw contract.
 */
final class AuditLogTest extends TestCase
{
    private PDO $pdo;
    private string $actorId = '';

    protected function setUp(): void
    {
        $this->pdo = getDB();
        db_ensure_audit_events_table();
        $this->actorId = 'aitest-audit-actor-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type) VALUES (?, ?, ?, 'Aitest', 'Audit', 2)"
        )->execute([
            $this->actorId,
            $this->actorId . '@aitest.local',
            password_hash('throwaway-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        ]);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$this->actorId]);
    }

    public function testEnsureIsIdempotent(): void
    {
        db_ensure_audit_events_table();
        db_ensure_audit_events_table();
        $found = $this->pdo->query("SHOW TABLES LIKE 'audit_events'")->fetch();
        $this->assertNotFalse($found);
    }

    public function testLogPopulatesColumns(): void
    {
        db_audit_log(
            action: 'user.create',
            actorUserId: $this->actorId,
            targetType: 'aitest-target',
            targetId: 'aitest-123',
            details: ['extra' => 'value', 'count' => 5],
            ip: '203.0.113.7',
            actorEmail: $this->actorId . '@aitest.local',
        );
        // Use the synthetic actor id as the SELECT filter so we find the row
        // this test wrote regardless of unrelated production user.create rows.
        $row = $this->pdo->prepare(
            "SELECT action, actor_user_id, actor_email_tag, target_type, target_id, details_json, ip FROM audit_events WHERE actor_user_id = ? AND target_id = ? ORDER BY id DESC LIMIT 1"
        );
        $row->execute([$this->actorId, 'aitest-123']);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($r);
        $this->assertSame('user.create', $r['action']);
        $this->assertSame($this->actorId, $r['actor_user_id']);
        $this->assertNotEmpty($r['actor_email_tag']);
        $this->assertSame('aitest-target', $r['target_type']);
        $this->assertSame('aitest-123', $r['target_id']);
        $this->assertSame('203.0.113.7', $r['ip']);
        $details = json_decode((string)$r['details_json'], true);
        $this->assertIsArray($details);
        $this->assertSame('value', $details['extra']);
        $this->assertSame(5, $details['count']);
    }

    public function testLogAcceptsAllOptionalFieldsNull(): void
    {
        db_audit_log(action: 'galaxy.delete', targetId: 'aitest-only-target');
        $r = $this->pdo->prepare("SELECT * FROM audit_events WHERE target_id = ? ORDER BY id DESC LIMIT 1");
        $r->execute(['aitest-only-target']);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('galaxy.delete', $row['action']);
        $this->assertNull($row['actor_user_id']);
        $this->assertNull($row['details_json']);
    }

    public function testUnknownActionIsRecordedWithPrefix(): void
    {
        // Whitelist defence-in-depth: anything outside AUDIT_LOG_KNOWN_ACTIONS
        // lands with `unknown.` prefix and triggers an error_log breadcrumb.
        db_audit_log(action: 'aitest.never.added.to.whitelist', targetId: 'aitest-unknown-target');
        $r = $this->pdo->prepare("SELECT action FROM audit_events WHERE target_id = ? ORDER BY id DESC LIMIT 1");
        $r->execute(['aitest-unknown-target']);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertStringStartsWith('unknown.', (string)$row['action']);
    }

    public function testActorCascadesToNullOnUserDelete(): void
    {
        // FK ON DELETE SET NULL ensures audit history outlives the actor's account.
        $ephemeral = 'aitest-audit-ephemeral-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO users (id, email, password, firstname, lastname, type) VALUES (?, ?, ?, 'Aitest', 'Ephemeral', 1)"
        )->execute([$ephemeral, $ephemeral . '@aitest.local', password_hash('x', PASSWORD_DEFAULT)]);

        db_audit_log(action: 'user.delete', actorUserId: $ephemeral, targetId: 'aitest-cascade-target');

        $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$ephemeral]);
        $r = $this->pdo->prepare("SELECT actor_user_id FROM audit_events WHERE target_id = ? ORDER BY id DESC LIMIT 1");
        $r->execute(['aitest-cascade-target']);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertNull($row['actor_user_id'], 'Audit row must survive the actor delete with NULL actor.');
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM audit_events WHERE target_id LIKE 'aitest-%'");
        $this->pdo->exec("DELETE FROM users WHERE id LIKE 'aitest-audit-ephemeral-%'");
    }
}
