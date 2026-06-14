<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: stage 6f operator drop-notification composer.
 *
 * mail_send dry-runs in the test environment (the bootstrap defines
 * MAIL_DRY_RUN, so no message leaves the host even though config.php populates
 * the real MAIL_SMTP_* relay constants), so the public
 * federation_notify_operator_mirror_dropped is exercised only for its guard
 * behaviour; the per-locale composition is tested directly through
 * _federation_drop_locale_content (a real DB read against project_info),
 * which returns the shell content pieces {subject, paragraphs, bullets}.
 *
 * Spec: Stage 6 trust revocation design (6f).
 */
final class DropNoticeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/federation/drop_notice.php';
    }

    private function sampleDropped(): array
    {
        return [
            ['slug' => 'coastal-plants', 'origin_host' => 'starmaps.polivoxia.ca'],
            ['slug' => 'tide-pools', 'origin_host' => 'starmaps.polivoxia.ca'],
        ];
    }

    public function testUsersLocaleColumnIsEnsured(): void
    {
        db_ensure_users_locale_column();
        $col = getDB()->query("SHOW COLUMNS FROM users LIKE 'locale'")->fetch();
        $this->assertNotFalse($col, 'users.locale column exists after ensure');
        // Idempotent second call does not throw.
        db_ensure_users_locale_column();
        $this->assertTrue(true);
    }

    public function testRenderEnglishCarriesSlugsOriginReasonAndGreeting(): void
    {
        $r = _federation_drop_locale_content('en', $this->sampleDropped(), 'pluriverse-revoked', 'Adri');
        $this->assertSame('Federated galaxies removed', $r['subject']);
        $paras = implode("\n", $r['paragraphs']);
        $bullets = implode("\n", $r['bullets']);
        // Greeting uses the named form.
        $this->assertStringContainsString('Hi Adri,', $paras);
        // Both galaxies + origin appear as bullet items.
        $this->assertStringContainsString('coastal-plants (mirrored from starmaps.polivoxia.ca)', $bullets);
        $this->assertStringContainsString('tide-pools (mirrored from starmaps.polivoxia.ca)', $bullets);
        // Reason line present for a known reason token.
        $this->assertStringContainsString("the origin instance's federation membership was revoked", $paras);
    }

    public function testRenderSpanishIsLocalizedNotEnglish(): void
    {
        $r = _federation_drop_locale_content('es', $this->sampleDropped(), 'pluriverse-blacklist', '');
        $this->assertSame('Galaxias federadas eliminadas', $r['subject']);
        $paras = implode("\n", $r['paragraphs']);
        // Anonymous greeting (no name) in Spanish.
        $this->assertStringContainsString('Hola,', $paras);
        // Decolonial: the Spanish body must not fall back to the English reason.
        $this->assertStringContainsString('la instancia de origen fue bloqueada en el Pluriverse', $paras);
        $this->assertStringNotContainsString('the origin instance was blocked', $paras);
    }

    public function testUnknownReasonOmitsReasonLine(): void
    {
        // 'origin_withdrawal' is a fossilize reason, never a drop; if it ever
        // reaches the notifier it should simply produce no reason line rather
        // than an untranslated token.
        $r = _federation_drop_locale_content('en', $this->sampleDropped(), 'origin_withdrawal', '');
        $this->assertStringNotContainsString('Reason:', implode("\n", $r['paragraphs']));
        $this->assertStringContainsString('coastal-plants', implode("\n", $r['bullets']));
    }

    public function testNotifyIsNoOpForEmptyDropList(): void
    {
        // No exception, no work; the guard returns before touching recipients.
        federation_notify_operator_mirror_dropped([], 'pluriverse-revoked');
        $this->assertTrue(true);
    }

    public function testUserLocaleRoundTrip(): void
    {
        // 6f-ii storage: set, read back via db_get_users, then clear.
        $pdo = getDB();
        $id = 'loctest-' . bin2hex(random_bytes(5));
        db_insert_user($id, $id . '@example.invalid', password_hash('x', PASSWORD_DEFAULT), 'Loc', 'Test', 2);
        try {
            db_set_user_locale($id, 'pt');
            $row = $this->findUser($id);
            $this->assertSame('pt', $row['locale'] ?? null);

            // Empty string clears to NULL (multilingual fallback).
            db_set_user_locale($id, '');
            $row = $this->findUser($id);
            $this->assertArrayHasKey('locale', $row);
            $this->assertNull($row['locale']);
        } finally {
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        }
    }

    private function findUser(string $id): array
    {
        foreach (db_get_users() as $u) {
            if ((string)$u['id'] === $id) {
                return $u;
            }
        }
        $this->fail("user $id not found via db_get_users");
    }
}
