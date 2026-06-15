<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/db.php';
require_once __DIR__ . '/../../../inc/mail.php';

/**
 * DB-backed mail settings + scalar instance settings (system_meta over config.php
 * fallback). Synthetic system_meta rows, self-cleaning: every test restores the
 * keys it touched so the live instance config is untouched. Run on its own.
 */
final class MailSettingsTest extends TestCase
{
    private ?string $origMail = null;
    private ?string $origLocale = null;
    private ?string $origHostname = null;

    protected function setUp(): void
    {
        getDB();
        $this->origMail = db_system_meta_get(MAIL_SETTINGS_META_KEY);
        $this->origLocale = db_system_meta_get('setting_default_locale');
        $this->origHostname = db_system_meta_get('setting_telaris_hostname');
    }

    protected function tearDown(): void
    {
        foreach ([
            MAIL_SETTINGS_META_KEY => $this->origMail,
            'setting_default_locale' => $this->origLocale,
            'setting_telaris_hostname' => $this->origHostname,
        ] as $key => $orig) {
            if ($orig === null) {
                db_system_meta_delete($key);
            } else {
                db_system_meta_set($key, $orig);
            }
        }
    }

    public function testStoredSettingsOverrideConstants(): void
    {
        mail_settings_save([
            'host' => 'smtp.aitest.local', 'port' => '2525', 'user' => 'u',
            'pass' => 'secret', 'secure' => 'ssl', 'from_address' => 'no-reply@aitest.local', 'from_name' => 'AITest',
        ]);
        $s = mail_settings_get();
        $this->assertSame('smtp.aitest.local', $s['host']);
        $this->assertSame('2525', $s['port']);
        $this->assertSame('ssl', $s['secure']);
        $this->assertSame('secret', $s['pass']);
        $this->assertTrue(mail_is_configured());
    }

    public function testBlankStoredValueFallsBackToConstant(): void
    {
        // Store a partial config: host set, user blank. The blank user must fall
        // back to the config.php constant rather than blanking the effective value.
        mail_settings_save([
            'host' => 'smtp.aitest.local', 'port' => '', 'user' => '',
            'pass' => '', 'secure' => '', 'from_address' => '', 'from_name' => '',
        ]);
        $s = mail_settings_get();
        $this->assertSame('smtp.aitest.local', $s['host'], 'stored host wins');
        // port falls back to a non-empty value (constant or the 587 default).
        $this->assertNotSame('', $s['port'], 'blank stored port falls back');
    }

    public function testSecureNormalizedToKnownValue(): void
    {
        mail_settings_save([
            'host' => 'h', 'port' => '1', 'user' => 'u', 'pass' => 'p',
            'secure' => 'GARBAGE', 'from_address' => 'a@b.c', 'from_name' => '',
        ]);
        $this->assertSame('tls', mail_settings_get()['secure'], 'unknown encryption normalizes to tls');
    }

    public function testInstanceSettingOverridesAndClears(): void
    {
        instance_setting_set('telaris_hostname', 'override.telaris.test');
        $this->assertSame('override.telaris.test', instance_setting_get('telaris_hostname'));
        // Clearing reverts to the config.php fallback (non-empty on a real install).
        instance_setting_set('telaris_hostname', '');
        $this->assertNotSame('override.telaris.test', instance_setting_get('telaris_hostname'));
    }

    public function testDefaultLocaleOverrideDrivesResolution(): void
    {
        instance_setting_set('default_locale', 'es');
        $this->assertSame('es', locale_resolve_from_request(null, null), 'operator default applies with no request hints');
        $this->assertSame('fr', locale_resolve_from_request('fr', null), 'explicit query lang still wins');
        instance_setting_set('default_locale', '');
        $this->assertSame(PROJECT_INFO_LOCALES[0], locale_resolve_from_request(null, null), 'cleared reverts to built-in default');
    }
}
