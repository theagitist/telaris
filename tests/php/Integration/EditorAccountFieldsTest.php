<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration test: optional last name + pronouns field (q26).
 *
 * Covers the DB layer added in inc/db.php:
 *   - users.lastname is nullable (a user may have no last name)
 *   - users.pronouns stores up to 3 content-guarded entries as a JSON array
 *   - db_user_pronouns_sanitize() content guard (count / length / charset / denylist)
 *   - db_user_pronoun_random() picks a member or returns null
 *
 * Fixtures follow the stage-5/6 convention: synthetic *.example.invalid emails,
 * delete by id in teardown (never touch real rows).
 */
final class EditorAccountFieldsTest extends TestCase
{
    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        db_ensure_users_account_columns();
    }

    protected function tearDown(): void
    {
        $pdo = getDB();
        foreach ($this->userIds as $id) {
            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        }
        $this->userIds = [];
    }

    private function newId(): string
    {
        $id = 'user_test_' . bin2hex(random_bytes(8));
        $this->userIds[] = $id;
        return $id;
    }

    private function fetchUser(string $id): array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT firstname, lastname, pronouns FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'user row should exist');
        return $row;
    }

    // --- last name optional ------------------------------------------------

    public function testInsertUserWithEmptyLastnameStoresNull(): void
    {
        $id = $this->newId();
        db_insert_user($id, $id . '@example.invalid', password_hash('x', PASSWORD_DEFAULT), 'Robin', '', 1);
        $row = $this->fetchUser($id);
        $this->assertNull($row['lastname'], 'empty last name should persist as NULL');
        $this->assertSame('Robin', $row['firstname']);
    }

    public function testInsertUserWithNullLastnameStoresNull(): void
    {
        $id = $this->newId();
        db_insert_user($id, $id . '@example.invalid', password_hash('x', PASSWORD_DEFAULT), 'Robin', null, 1);
        $this->assertNull($this->fetchUser($id)['lastname']);
    }

    public function testCreateUserAcceptsEmptyLastname(): void
    {
        $id = $this->newId(); // unused id reservation; createUser mints its own
        array_pop($this->userIds);
        $email = 'q26-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $err = createUser(getDB(), $email, password_hash('x', PASSWORD_DEFAULT), 'Sam', '', 1);
        $this->assertNull($err, 'createUser should succeed with an empty last name');
        $created = db_get_user_by_email($email);
        $this->assertNotNull($created);
        $this->userIds[] = (string)$created['id'];
        $this->assertNull($this->fetchUser((string)$created['id'])['lastname']);
    }

    public function testFullNameCompositionHasNoTrailingSpaceOrNull(): void
    {
        // Mirror the composition used across the UI: trim(first . ' ' . (last ?? '')).
        $first = 'Robin';
        $last = null;
        $full = trim($first . ' ' . ($last ?? ''));
        $this->assertSame('Robin', $full);
        $this->assertStringNotContainsStringIgnoringCase('null', $full);
    }

    // --- pronouns valid round-trip ----------------------------------------

    public function testPronounsValidRoundTrip(): void
    {
        $cases = [
            ['they/them'],
            ['they/them', 'elle'],
            ['they/them', 'she/her', 'he/him'],
        ];
        foreach ($cases as $entries) {
            $res = db_user_pronouns_sanitize($entries);
            $this->assertTrue($res['ok'], 'should accept ' . json_encode($entries));
            $this->assertNotNull($res['json']);

            $id = $this->newId();
            db_insert_user($id, $id . '@example.invalid', password_hash('x', PASSWORD_DEFAULT), 'Pat', null, 1, $res['json']);
            $stored = $this->fetchUser($id)['pronouns'];
            $this->assertSame($entries, db_user_pronouns_list($stored), 'round-trips as the same list');
        }
    }

    public function testEmptyPronounsAreValidAndStoreNull(): void
    {
        $res = db_user_pronouns_sanitize([]);
        $this->assertTrue($res['ok']);
        $this->assertNull($res['json']);

        $res2 = db_user_pronouns_sanitize(['', '   ']);
        $this->assertTrue($res2['ok']);
        $this->assertNull($res2['json']);

        $id = $this->newId();
        db_insert_user($id, $id . '@example.invalid', password_hash('x', PASSWORD_DEFAULT), 'Pat', null, 1, $res['json']);
        $this->assertNull($this->fetchUser($id)['pronouns']);
    }

    public function testPronounsDedupeCaseInsensitive(): void
    {
        $res = db_user_pronouns_sanitize(['they/them', 'They/Them', 'elle']);
        $this->assertTrue($res['ok']);
        $this->assertSame(['they/them', 'elle'], db_user_pronouns_list($res['json']));
    }

    public function testRequestEntriesMergeCheckedAndCustom(): void
    {
        $entries = db_user_pronouns_entries_from_request(['they/them', 'she/her'], 'xe/xem, ze/zir');
        $this->assertSame(['they/them', 'she/her', 'xe/xem', 'ze/zir'], $entries);
    }

    // --- pronouns rejected -------------------------------------------------

    public function testPronounsRejectTooMany(): void
    {
        $res = db_user_pronouns_sanitize(['they/them', 'she/her', 'he/him', 'elle']);
        $this->assertFalse($res['ok']);
        $this->assertSame('too_many', $res['error']);
    }

    public function testPronounsRejectOverLength(): void
    {
        $res = db_user_pronouns_sanitize([str_repeat('a', USER_PRONOUNS_MAX_LEN + 1)]);
        $this->assertFalse($res['ok']);
        $this->assertSame('too_long', $res['error']);
    }

    /**
     * @dataProvider badCharsetProvider
     */
    public function testPronounsRejectBadCharset(string $entry): void
    {
        $res = db_user_pronouns_sanitize([$entry]);
        $this->assertFalse($res['ok'], 'should reject: ' . $entry);
        $this->assertSame('charset', $res['error']);
    }

    public static function badCharsetProvider(): array
    {
        return [
            'digits' => ['they2them'],
            'url' => ['http://x.test/a'],
            'markup' => ['<b>they</b>'],
            'punctuation' => ['they;them'],
        ];
    }

    public function testPronounsRejectDenylistedTerm(): void
    {
        // Use the first denylist entry so the test tracks the list, not a literal slur.
        $slur = USER_PRONOUNS_DENYLIST[0];
        $res = db_user_pronouns_sanitize([$slur]);
        $this->assertFalse($res['ok']);
        $this->assertSame('denylist', $res['error']);

        // Substring match: a denylisted term embedded in a larger string is caught.
        $res2 = db_user_pronouns_sanitize([$slur . 'self']);
        $this->assertFalse($res2['ok']);
        $this->assertSame('denylist', $res2['error']);
    }

    // --- random pick -------------------------------------------------------

    public function testPronounRandomReturnsMemberOfSet(): void
    {
        $json = json_encode(['they/them', 'elle', 'iel']);
        for ($i = 0; $i < 20; $i++) {
            $pick = db_user_pronoun_random($json);
            $this->assertContains($pick, ['they/them', 'elle', 'iel']);
        }
    }

    public function testPronounRandomNullWhenEmpty(): void
    {
        $this->assertNull(db_user_pronoun_random(null));
        $this->assertNull(db_user_pronoun_random(''));
        $this->assertNull(db_user_pronoun_random('[]'));
        $this->assertNull(db_user_pronoun_random('not json'));
    }
}
