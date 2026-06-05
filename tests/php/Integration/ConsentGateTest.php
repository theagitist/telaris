<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * First-login consent gate (BACKLOG ^consent-gate-first-login).
 *
 * Covers the DB layer (db_ensure_user_consents_table / db_record_user_consent /
 * db_get_user_accepted_consents) and the inc/consent.php logic: required-document
 * resolution, the inert-by-default safety property, needs-consent including
 * version bumps and partial acceptance, idempotent recording, and the localized
 * strings (4-locale parity, no English-only fallback, no em-dashes).
 *
 * Fixtures follow the suite convention: synthetic user_test_* rows deleted in
 * teardown; consent rows cascade away with the user via the FK.
 */
final class ConsentGateTest extends TestCase
{
    /** @var list<string> */
    private array $userIds = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/inc/consent.php';
    }

    protected function setUp(): void
    {
        db_ensure_user_consents_table();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->userIds as $id) {
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        }
        $this->userIds = [];
    }

    private function newUser(): string
    {
        $id = 'user_test_' . bin2hex(random_bytes(8));
        $this->userIds[] = $id;
        db_insert_user($id, $id . '@example.invalid', password_hash('x', PASSWORD_DEFAULT), 'Robin', '', 1);
        return $id;
    }

    // --- inert-by-default safety -------------------------------------------

    public function testEnforcedFalseByDefault(): void
    {
        // No CONSENT_*_VERSION configured in the test process, so the gate is inert.
        $this->assertSame([], consent_required_documents());
        $this->assertFalse(consent_enforced());
    }

    public function testInertGateNeverPromptsWhenNoDocsRequired(): void
    {
        $id = $this->newUser();
        $this->assertFalse(consent_user_needs($id, []));
    }

    public function testEmptyUserIdNeverNeeds(): void
    {
        $this->assertFalse(consent_user_needs('', ['tos' => '1.0']));
    }

    // --- needs-consent logic -----------------------------------------------

    public function testNeedsConsentWhenNothingAccepted(): void
    {
        $id = $this->newUser();
        $this->assertTrue(consent_user_needs($id, ['tos' => '1.0', 'privacy' => '1.0']));
    }

    public function testSatisfiedAfterAccepting(): void
    {
        $id = $this->newUser();
        $this->assertTrue(db_record_user_consent($id, 'tos', '1.0'));
        $this->assertTrue(db_record_user_consent($id, 'privacy', '1.0'));
        $this->assertFalse(consent_user_needs($id, ['tos' => '1.0', 'privacy' => '1.0']));
    }

    public function testPartialAcceptanceStillNeedsConsent(): void
    {
        $id = $this->newUser();
        db_record_user_consent($id, 'tos', '1.0'); // privacy not accepted
        $this->assertTrue(consent_user_needs($id, ['tos' => '1.0', 'privacy' => '1.0']));
    }

    public function testVersionBumpRePrompts(): void
    {
        $id = $this->newUser();
        db_record_user_consent($id, 'tos', '1.0');
        db_record_user_consent($id, 'privacy', '1.0');
        // Terms bumped to 2.0 => the editor owes consent again.
        $this->assertTrue(consent_user_needs($id, ['tos' => '2.0', 'privacy' => '1.0']));
    }

    // --- DB layer ----------------------------------------------------------

    public function testHistoryPreservedAcrossVersions(): void
    {
        $id = $this->newUser();
        db_record_user_consent($id, 'tos', '1.0');
        db_record_user_consent($id, 'tos', '2.0');
        $accepted = db_get_user_accepted_consents($id);
        $this->assertArrayHasKey('1.0', $accepted['tos']);
        $this->assertArrayHasKey('2.0', $accepted['tos']);
    }

    public function testRecordIsIdempotent(): void
    {
        $id = $this->newUser();
        $this->assertTrue(db_record_user_consent($id, 'tos', '1.0'));
        $this->assertTrue(db_record_user_consent($id, 'tos', '1.0')); // duplicate, no error
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM user_consents WHERE user_id=:u AND document_type='tos' AND document_version='1.0'");
        $stmt->execute([':u' => $id]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testGetAcceptedShape(): void
    {
        $id = $this->newUser();
        db_record_user_consent($id, 'tos', '1.0');
        db_record_user_consent($id, 'privacy', '3.2');
        $accepted = db_get_user_accepted_consents($id);
        $this->assertTrue($accepted['tos']['1.0']);
        $this->assertTrue($accepted['privacy']['3.2']);
        $this->assertArrayNotHasKey('2.0', $accepted['tos'] ?? []);
    }

    // --- localized strings -------------------------------------------------

    public function testConsentStringsParityAcrossLocales(): void
    {
        $all = consent_strings();
        $this->assertSame(['en', 'es', 'pt', 'fr'], array_keys($all));
        $enKeys = array_keys($all['en']);
        foreach (['es', 'pt', 'fr'] as $loc) {
            $this->assertSame($enKeys, array_keys($all[$loc]), "locale $loc key parity");
            foreach ($enKeys as $k) {
                $this->assertNotSame('', trim($all[$loc][$k]), "locale $loc key $k must be non-empty");
            }
        }
    }

    public function testConsentTFallsBackToKeyNotEnglish(): void
    {
        // Unknown key returns the key itself (never an English string).
        $this->assertSame('nope_missing', consent_t('nope_missing', 'es'));
        // Unknown locale resolves to en.
        $this->assertSame(consent_strings()['en']['heading'], consent_t('heading', 'xx'));
        // Known key/locale returns the localized value.
        $this->assertSame(consent_strings()['fr']['accept_button'], consent_t('accept_button', 'fr'));
    }

    public function testConsentStringsHaveNoEmDash(): void
    {
        foreach (consent_strings() as $loc => $map) {
            foreach ($map as $k => $v) {
                $this->assertStringNotContainsString("\u{2014}", $v, "em-dash in {$loc}.{$k}");
            }
        }
    }
}
