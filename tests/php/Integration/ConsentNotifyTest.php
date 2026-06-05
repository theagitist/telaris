<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Operator-initiated "documents changed" notification (BACKLOG
 * ^consent-gate-first-login follow-up).
 *
 * Covers the recipient logic, send (dry-run) recording + idempotency, the
 * disregard decision, the high-friction phrase match, the localized strings
 * (4-locale parity, no English-only fallback, no em-dashes), and the email
 * builder (single-locale + multilingual fallback).
 *
 * Isolation: the suite runs against the live instance DB, and
 * consent_notice_decisions is GLOBAL (per document+version, not per-user), so
 * every test uses SYNTHETIC document versions (prefix 'tnf-') and synthetic
 * user_test_* accounts, injected via the optional $pending / $editors params.
 * Real (tos/privacy, 1.0) decisions and real editor accounts are never touched.
 * Teardown deletes the synthetic users (consent_notifications cascade) and the
 * synthetic decision rows.
 */
final class ConsentNotifyTest extends TestCase
{
    /** @var list<string> */
    private array $userIds = [];
    private const TVER = 'tnf-1';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/consent_notify.php';
    }

    protected function setUp(): void
    {
        db_ensure_consent_notice_tables();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->userIds as $id) {
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        }
        $this->userIds = [];
        // Drop any synthetic decision rows this suite created.
        $pdo->exec("DELETE FROM consent_notice_decisions WHERE document_version LIKE 'tnf-%'");
    }

    /** @return array<string,mixed> a synthetic editor row shaped like db_get_users */
    private function newEditor(string $locale = 'en'): array
    {
        $id = 'user_test_' . bin2hex(random_bytes(8));
        $this->userIds[] = $id;
        db_insert_user($id, $id . '@example.invalid', password_hash('x', PASSWORD_DEFAULT), 'Robin', '', 1);
        if ($locale !== '') {
            db_set_user_locale($id, $locale);
        }
        return ['id' => $id, 'email' => $id . '@example.invalid', 'firstname' => 'Robin', 'locale' => $locale, 'type' => 1];
    }

    // --- recipient logic ---------------------------------------------------

    public function testNeededExcludesAcceptedAndNotified(): void
    {
        $ed = $this->newEditor();
        $pending = ['tos' => self::TVER, 'privacy' => self::TVER];
        $this->assertSame($pending, consent_notify_needed_for_editor($ed['id'], $pending));

        db_record_user_consent($ed['id'], 'tos', self::TVER);          // accepted tos
        db_record_consent_notification($ed['id'], 'privacy', self::TVER); // already emailed about privacy
        $this->assertSame([], consent_notify_needed_for_editor($ed['id'], $pending));
    }

    public function testRecipientsOnlyThoseWhoNeed(): void
    {
        $a = $this->newEditor();
        $b = $this->newEditor();
        db_record_user_consent($b['id'], 'tos', self::TVER); // b already accepted
        $pending = ['tos' => self::TVER];
        $recips = consent_notify_recipients($pending, [$a, $b]);
        $ids = array_map(fn($r) => $r['id'], $recips);
        $this->assertContains($a['id'], $ids);
        $this->assertNotContains($b['id'], $ids);
    }

    // --- send (dry-run) ----------------------------------------------------

    public function testSendRecordsNotificationsAndDecisionAndIsIdempotent(): void
    {
        $ed = $this->newEditor();
        $pending = ['tos' => self::TVER, 'privacy' => self::TVER];

        $r = consent_notify_send('cli-test', $pending, [$ed]);
        $this->assertSame(1, $r['recipients']);
        $this->assertSame(1, $r['sent']);          // MAIL_DRY_RUN => send "succeeds"
        $this->assertTrue($r['resolved']);

        // Notification rows recorded for the editor.
        $notified = db_get_user_consent_notifications($ed['id']);
        $this->assertArrayHasKey(self::TVER, $notified['tos']);
        $this->assertArrayHasKey(self::TVER, $notified['privacy']);

        // Decision recorded => no longer pending for these synthetic versions.
        $decisions = db_get_consent_notice_decisions();
        $this->assertSame('sent', $decisions['tos'][self::TVER] ?? null);
        $this->assertSame('sent', $decisions['privacy'][self::TVER] ?? null);

        // Idempotent: a second pass finds nobody left to notify.
        $this->assertSame([], consent_notify_recipients($pending, [$ed]));
        $r2 = consent_notify_send('cli-test', $pending, [$ed]);
        $this->assertSame(0, $r2['recipients']);
        $this->assertTrue($r2['resolved']);
    }

    // --- disregard ---------------------------------------------------------

    public function testDisregardRecordsDecisionWithoutNotifying(): void
    {
        $ed = $this->newEditor();
        $pending = ['tos' => self::TVER];
        consent_notify_disregard('cli-test', $pending);
        $decisions = db_get_consent_notice_decisions();
        $this->assertSame('disregarded', $decisions['tos'][self::TVER] ?? null);
        // No email recorded for the editor.
        $this->assertArrayNotHasKey('tos', db_get_user_consent_notifications($ed['id']));
    }

    // --- high-friction phrase ---------------------------------------------

    public function testPhraseMatchesEachLocaleAndRejectsOthers(): void
    {
        $this->assertTrue(consent_notify_phrase_matches('disregard alert'));
        $this->assertTrue(consent_notify_phrase_matches('  DESCARTAR ALERTA '));   // es, case/space tolerant
        $this->assertTrue(consent_notify_phrase_matches("ignorer l'alerte"));       // fr
        $this->assertFalse(consent_notify_phrase_matches(''));
        $this->assertFalse(consent_notify_phrase_matches('dismiss'));
        $this->assertFalse(consent_notify_phrase_matches('disregard'));
    }

    // --- localized strings -------------------------------------------------

    public function testStringsParityAcrossLocales(): void
    {
        $all = consent_notify_strings();
        $this->assertSame(['en', 'es', 'pt', 'fr'], array_keys($all));
        $enKeys = array_keys($all['en']);
        foreach (['es', 'pt', 'fr'] as $loc) {
            $this->assertSame($enKeys, array_keys($all[$loc]), "locale $loc key parity");
            foreach ($enKeys as $k) {
                $this->assertNotSame('', trim($all[$loc][$k]), "locale $loc key $k must be non-empty");
            }
        }
    }

    public function testStringsHaveNoEmDash(): void
    {
        foreach (consent_notify_strings() as $loc => $map) {
            foreach ($map as $k => $v) {
                $this->assertStringNotContainsString("\u{2014}", $v, "em-dash in {$loc}.{$k}");
            }
        }
    }

    public function testTFallsBackToKeyNotEnglish(): void
    {
        $this->assertSame('nope_missing', consent_notify_t('nope_missing', 'es'));
        $this->assertSame(consent_notify_strings()['en']['send_button'], consent_notify_t('send_button', 'xx'));
        $this->assertSame(consent_notify_strings()['fr']['disregard_button'], consent_notify_t('disregard_button', 'fr'));
    }

    // --- email builder -----------------------------------------------------

    public function testBuildEmailSingleLocaleHasVersionLabelAndLink(): void
    {
        [$subject, $html] = consent_notify_build_email(['locale' => 'es', 'firstname' => 'Sam'], ['tos' => '2.0']);
        $this->assertStringContainsString('Condiciones de Uso', $html); // es label
        $this->assertStringContainsString('2.0', $html);
        $this->assertStringContainsString('href="https://www.telaris.ca/terms"', $html);
        $this->assertStringContainsString('Sam', $html);
        $this->assertNotSame('', $subject);
    }

    public function testBuildEmailMultilingualWhenLocaleUnknown(): void
    {
        [, $html] = consent_notify_build_email(['locale' => null, 'firstname' => ''], ['privacy' => '1.0']);
        // Stacks all four locale labels for Privacy.
        $this->assertStringContainsString('Privacy Policy', $html);
        $this->assertStringContainsString('Política de Privacidad', $html);
        $this->assertStringContainsString('Política de Privacidade', $html);
        $this->assertStringContainsString('Politique de confidentialité', $html);
    }
}
