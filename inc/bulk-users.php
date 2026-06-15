<?php
declare(strict_types=1);

/**
 * Bulk user creation.
 *
 * Format: CSV, one user per line. Columns:
 *
 *   email[, firstname[, lastname[, type[, creates_galaxy]]]]
 *
 *   email           required, valid email syntax
 *   firstname       optional
 *   lastname        optional
 *   type            optional: "admin" or "editor" (default editor; case-insensitive)
 *   creates_galaxy  optional row override of the GUI "create a galaxy for each user"
 *                   checkbox: truthy ("yes" / "y" / "true" / "1") or falsy
 *                   ("no" / "n" / "false" / "0"); empty inherits the GUI default.
 *
 * When the GUI default (or row override) is true, each new user gets a personal
 * galaxy:
 *   - slug   = slugified email local-part, with a "-NNN" suffix on collision
 *              (against existing galaxies or other galaxies created in the same
 *              batch).
 *   - name   = "firstname lastname" if any name was given, else the local-part.
 *   - theme  = cosmic (the default).
 *   - assignment: editors get the galaxy attached via user_constellations; admins
 *     see every galaxy anyway, so no junction row is written for them.
 *
 * The welcome email tells the user their username (their email), gives them a
 * one-time link to set their password (7-day TTL, single-use, same token flow
 * as forgot-password), the visitor URL of their personal galaxy (if one was
 * created), and the login URL to start adding wormholes.
 */

require_once __DIR__ . '/db.php';

/**
 * Parse the textarea into row records, marking each with status:
 *   - new:     will be created
 *   - exists:  email already in use; skipped
 *   - invalid: parse error or invalid email
 *
 * @param string $input              raw textarea contents
 * @param bool   $defaultCreateGalaxy GUI checkbox state (the per-row column 5
 *                                    overrides this when set)
 * @return list<array{
 *   line:int, raw:string, status:string, email:string,
 *   firstname:string, lastname:string, role:string,
 *   creates_galaxy:bool, creates_galaxy_overridden:bool,
 *   galaxy_slug_preview:string, note:?string
 * }>
 */
function bulk_users_parse(string $input, bool $defaultCreateGalaxy = true): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($input));
    if ($lines === false) return [];

    $rows = [];
    $seenEmails = [];
    // Track preview slugs claimed *within this paste* so the preview reflects
    // intra-batch collisions too (e.g. two users with the same email local-part
    // from different domains).
    $previewClaimedSlugs = [];

    // L-third-6 (audit v6.10.13): hard cap on field length. Admin trust
    // boundary, so this is advisory rather than security-bleeding, but
    // unbounded names / emails could land oversized values into VARCHAR(255)
    // columns or break downstream UI rendering. 200 chars is well above any
    // realistic email or human name and well under the column ceiling.
    $fieldCap = 200;
    $truncatedLines = [];

    foreach ($lines as $i => $raw) {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '#')) continue;

        $cols = array_map('trim', explode(',', $raw));
        $emailRaw = (string)($cols[0] ?? '');
        $firstRaw = (string)($cols[1] ?? '');
        $lastRaw = (string)($cols[2] ?? '');
        $email = strtolower(mb_substr($emailRaw, 0, $fieldCap));
        $firstname = mb_substr($firstRaw, 0, $fieldCap);
        $lastname = mb_substr($lastRaw, 0, $fieldCap);
        if (mb_strlen($emailRaw) > $fieldCap || mb_strlen($firstRaw) > $fieldCap || mb_strlen($lastRaw) > $fieldCap) {
            $truncatedLines[] = $i + 1;
        }
        $typeRaw = strtolower((string)($cols[3] ?? ''));
        $role = ($typeRaw === 'admin') ? 'admin' : 'editor';

        $createColRaw = strtolower((string)($cols[4] ?? ''));
        if ($createColRaw === '') {
            $createsGalaxy = $defaultCreateGalaxy;
            $createsGalaxyOverridden = false;
        } else {
            $createsGalaxy = in_array($createColRaw, ['1', 'true', 'yes', 'y', 't'], true);
            $createsGalaxyOverridden = true;
        }

        $localPart = '';
        if ($email !== '' && strpos($email, '@') !== false) {
            $localPart = substr($email, 0, (int)strpos($email, '@'));
        }

        $galaxySlugPreview = '';
        if ($createsGalaxy && $localPart !== '') {
            $base = db_slugify($localPart);
            if ($base === '') $base = 'user';
            $candidate = $base;
            $collision = (db_get_constellation_by_slug($candidate) !== null)
                      || in_array($candidate, $previewClaimedSlugs, true);
            $galaxySlugPreview = $collision ? ($base . '-NNN') : $candidate;
            // Claim the slug only when we actually expect to use it.
            if (!$collision) $previewClaimedSlugs[] = $candidate;
        }

        $row = [
            'line' => $i + 1,
            'raw' => $raw,
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'role' => $role,
            'creates_galaxy' => $createsGalaxy,
            'creates_galaxy_overridden' => $createsGalaxyOverridden,
            'galaxy_slug_preview' => $galaxySlugPreview,
            'note' => null,
            'status' => 'new',
        ];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row['status'] = 'invalid';
            $row['note'] = 'Email missing or malformed';
        } elseif (isset($seenEmails[$email])) {
            $row['status'] = 'invalid';
            $row['note'] = 'Duplicate email earlier in the list';
        } elseif (db_get_user_by_email($email) !== null) {
            $row['status'] = 'exists';
            $row['note'] = 'Email already in use';
        }
        if (in_array($i + 1, $truncatedLines, true)) {
            $row['note'] = ($row['note'] !== null ? $row['note'] . ' · ' : '')
                . 'Field truncated to ' . $fieldCap . ' chars';
        }

        if ($row['status'] === 'new') $seenEmails[$email] = true;

        $rows[] = $row;
    }
    return $rows;
}

/**
 * Apply a previously-parsed batch: create the 'new' rows, optionally auto-create
 * a personal galaxy for each, then send each user a one-time setup email.
 *
 * Mail failures don't roll back the user / galaxy creation — the admin can
 * re-issue a reset link from the user editor if mail is down.
 *
 * @param list<array> $rows     output of bulk_users_parse()
 * @param string      $baseUrl  scheme+host (e.g. "https://starmaps.polivoxia.ca")
 *                              used to build all absolute URLs in the email
 * @return array{
 *   created:int, galaxies_created:int, skipped_exists:int, skipped_invalid:int,
 *   mail_failed:int, rows:list<array>
 * }
 */
function bulk_users_apply(array $rows, string $baseUrl): array {
    require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/email-template.php';

    $appName = mail_settings_get()['from_name'] !== '' ? mail_settings_get()['from_name'] : 'Telaris';
    $created = 0; $galaxiesCreated = 0;
    $skippedExists = 0; $skippedInvalid = 0; $mailFailed = 0;
    $outRows = [];

    $claimedSlugs = [];
    // Resolved once for every audit row in the loop below.
    $adminUserId = $_SESSION['admin_user_id'] ?? null;

    // Audit pass #5 / Race M5 (v6.10.18): per-admin advisory lock around the
    // commit loop. An admin who double-clicks Commit (or the browser retries
    // a slow POST) would otherwise run two parallel commits — each tries to
    // INSERT the same user rows, both fail on UNIQUE(email), the second's
    // db_create_password_reset_token call would silently replace the first
    // batch's still-fresh token, breaking the welcome-email link the user
    // received. Per-admin keying lets two different admins commit
    // simultaneously without serializing on each other. Best-effort: on
    // contention we return immediately without applying any rows.
    $pdo = getDB();
    $bulkLockKey = 'telaris:bulk_users:' . ($adminUserId ?? 'anon');
    $bulkLockStmt = $pdo->prepare("SELECT GET_LOCK(:k, 0)");
    $bulkLockStmt->execute([':k' => $bulkLockKey]);
    $bulkLockResult = $bulkLockStmt->fetchColumn();
    if ($bulkLockResult !== 1 && $bulkLockResult !== '1') {
        return [
            'created' => 0,
            'galaxies_created' => 0,
            'skipped_exists' => 0,
            'skipped_invalid' => 0,
            'mail_failed' => 0,
            'rows' => [],
            'error' => 'Another bulk-users commit is in progress for this admin; refresh and try again.',
        ];
    }
    // Lock auto-releases at request end via connection close.

    foreach ($rows as $row) {
        $r = $row;
        if ($row['status'] !== 'new') {
            if ($row['status'] === 'exists') $skippedExists++;
            elseif ($row['status'] === 'invalid') $skippedInvalid++;
            $r['outcome'] = 'skipped';
            $outRows[] = $r;
            continue;
        }

        try {
            $type = $row['role'] === 'admin' ? 2 : 1;
            $userId = ($row['role'] === 'admin' ? 'admin_' : 'user_') . bin2hex(random_bytes(8));
            $placeholder = bin2hex(random_bytes(16));
            $hashed = password_hash($placeholder, PASSWORD_DEFAULT);
            db_insert_user($userId, $row['email'], $hashed, $row['firstname'], $row['lastname'], $type);

            $galaxySlug = null;
            $galaxyId = null;
            if (!empty($row['creates_galaxy'])) {
                $localPart = substr($row['email'], 0, (int)strpos($row['email'], '@'));
                $base = db_slugify($localPart);
                if ($base === '') $base = 'user';

                $slug = $base;
                $attempt = 0;
                while (db_get_constellation_by_slug($slug) !== null || in_array($slug, $claimedSlugs, true)) {
                    $attempt++;
                    if ($attempt > 50) {
                        $slug = $base . '-' . bin2hex(random_bytes(6));
                        break;
                    }
                    $slug = $base . '-' . (string)random_int(100, 999);
                }

                $name = trim($row['firstname'] . ' ' . $row['lastname']);
                if ($name === '') $name = $localPart;

                $galaxyId = db_create_constellation($name, '', $slug, 'cosmic');
                $galaxySlug = $slug;
                $claimedSlugs[] = $slug;
                $galaxiesCreated++;

                // Editors are scoped to their assigned galaxies; admins see all.
                if ($type === 1) {
                    db_set_user_constellations($userId, [$galaxyId]);
                }
            }

            $token = db_create_password_reset_token($userId, 86400 * 7);
            $resetUrl = rtrim($baseUrl, '/') . '/utils/reset.php?token=' . urlencode($token);
            $loginUrl = rtrim($baseUrl, '/') . '/utils/login.php';
            $galaxyUrl = $galaxySlug !== null
                ? rtrim($baseUrl, '/') . '/' . rawurlencode($galaxySlug)
                : null;

            $displayName = trim($row['firstname'] . ' ' . $row['lastname']);
            $greetingText = $displayName !== '' ? $displayName : 'there';
            $subject = 'Welcome to ' . $appName;

            // On-brand shell, same format as the enrolment emails. Set-password is
            // the primary CTA (and the plain-text fallback URL); galaxy and sign-in
            // are secondary links so the recipient never has to click a bare host.
            // Bulk import carries no per-recipient locale, so this copy is English;
            // localizing it would need a locale column in the CSV (follow-up).
            $links = [];
            if ($galaxyUrl !== null) {
                $links[] = ['label' => 'Your galaxy', 'url' => $galaxyUrl];
            }
            $links[] = ['label' => 'Log in to start adding wormholes', 'url' => $loginUrl];

            $rendered = telaris_email_render([
                'heading'    => $subject,
                'paragraphs' => [
                    "Hi $greetingText,",
                    "An account has been created for you on $appName.",
                    "Your username is your email address: {$row['email']}",
                ],
                'cta'        => ['label' => 'Set your password', 'url' => $resetUrl],
                'links'      => $links,
                'note'       => 'The password link is valid for 7 days and can only be used once.',
                'locale'     => 'en',
            ]);

            $sent = mail_send($row['email'], $subject, $rendered['html'], $rendered['text'], $displayName !== '' ? $displayName : null);

            $created++;
            if (!$sent) $mailFailed++;
            $r['outcome'] = $sent ? 'created' : 'created_mail_failed';
            $r['galaxy_slug'] = $galaxySlug;
            $outRows[] = $r;
            db_audit_log(
                action: 'user.create.bulk',
                actorUserId: $adminUserId,
                targetType: 'user',
                targetId: $userId,
                details: [
                    'type' => $type,
                    'created_galaxy' => $galaxyId !== null,
                    'galaxy_slug' => $galaxySlug,
                    'mail_sent' => $sent,
                ],
                ip: auth_client_ip(),
                actorEmail: $row['email'],
            );
        } catch (Throwable $e) {
            // Redact the email — error_log isn't a PII channel.
            $tag = function_exists('mail_recipient_tag')
                ? mail_recipient_tag((string)($row['email'] ?? ''))
                : 'addr:' . substr(hash('sha256', strtolower(trim((string)($row['email'] ?? '')))), 0, 12);
            error_log('bulk_users_apply error for ' . $tag . ': ' . $e->getMessage());
            $r['outcome'] = 'create_failed';
            $r['note'] = 'Internal error';
            $skippedInvalid++;
            $outRows[] = $r;
        }
    }

    return [
        'created' => $created,
        'galaxies_created' => $galaxiesCreated,
        'skipped_exists' => $skippedExists,
        'skipped_invalid' => $skippedInvalid,
        'mail_failed' => $mailFailed,
        'rows' => $outRows,
    ];
}
