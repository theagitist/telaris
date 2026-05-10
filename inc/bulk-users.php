<?php
declare(strict_types=1);

/**
 * Bulk user creation helpers.
 *
 * The textarea accepts one user per line. Columns are tab-separated when the input is
 * pasted from a spreadsheet, comma-separated when typed by hand (auto-detected).
 *
 *   email[\tfirstname[\tlastname[\trole[\tgalaxies]]]]
 *   email[, firstname[, lastname[, role[, galaxies]]]]
 *
 * In comma-separated mode the galaxies column is pipe-separated (since comma is taken).
 * In tab-separated mode galaxies stay comma-separated as in spreadsheet exports.
 *
 * role: editor (default) | admin
 * galaxies: list of galaxy slugs (NOT IDs)
 */

require_once __DIR__ . '/db.php';

/**
 * Parse the textarea into row records, marking each with status:
 *   - new:    will be created
 *   - exists: email already in use; skipped
 *   - invalid: parse error or invalid email
 *
 * Galaxy slugs are validated against db_get_constellations(); unknown slugs are reported
 * but don't block the row.
 *
 * @return list<array{
 *   line:int, raw:string, status:string, email:string,
 *   firstname:string, lastname:string, role:string,
 *   galaxy_slugs:list<string>, unknown_galaxy_slugs:list<string>,
 *   note:?string
 * }>
 */
function bulk_users_parse(string $input): array {
    $allGalaxies = db_get_constellations();
    $slugToId = [];
    foreach ($allGalaxies as $g) {
        if (!empty($g['slug'])) $slugToId[strtolower((string)$g['slug'])] = (int)$g['id'];
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($input));
    if ($lines === false) return [];
    // Auto-detect: if any line contains a tab, treat all lines as TSV.
    $hasTab = false;
    foreach ($lines as $l) { if (strpos($l, "\t") !== false) { $hasTab = true; break; } }

    $rows = [];
    $seenEmails = [];
    foreach ($lines as $i => $raw) {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '#')) continue;

        if ($hasTab) {
            $cols = explode("\t", $raw);
            $galaxiesField = $cols[4] ?? '';
            $galaxyParts = $galaxiesField === '' ? [] : array_map('trim', explode(',', $galaxiesField));
        } else {
            $cols = array_map('trim', explode(',', $raw));
            $galaxiesField = $cols[4] ?? '';
            // In CSV mode galaxies are pipe-separated to avoid clashing with the row delimiter.
            $galaxyParts = $galaxiesField === '' ? [] : array_map('trim', explode('|', $galaxiesField));
        }

        $email = strtolower(trim($cols[0] ?? ''));
        $firstname = trim((string)($cols[1] ?? ''));
        $lastname = trim((string)($cols[2] ?? ''));
        $role = strtolower(trim((string)($cols[3] ?? 'editor')));
        if ($role === '' || !in_array($role, ['editor', 'admin'], true)) $role = 'editor';

        $galaxyParts = array_values(array_filter($galaxyParts, fn($s) => $s !== ''));
        $knownSlugs = [];
        $unknownSlugs = [];
        foreach ($galaxyParts as $slug) {
            $key = strtolower($slug);
            if (isset($slugToId[$key])) $knownSlugs[] = $slug;
            else $unknownSlugs[] = $slug;
        }

        $row = [
            'line' => $i + 1,
            'raw' => $raw,
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'role' => $role,
            'galaxy_slugs' => $knownSlugs,
            'unknown_galaxy_slugs' => $unknownSlugs,
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

        if ($row['status'] !== 'invalid' && $row['status'] !== 'exists' && !empty($unknownSlugs)) {
            $row['note'] = 'Unknown galaxy slug(s): ' . implode(', ', $unknownSlugs);
        }

        if ($row['status'] === 'new') $seenEmails[$email] = true;

        $rows[] = $row;
    }
    return $rows;
}

/**
 * Apply a previously-parsed batch: create the 'new' rows, send each a one-time setup email,
 * attach galaxy permissions when listed.
 *
 * Returns counts and per-row outcomes for the result screen. Mail failures don't roll back the
 * user creation — the admin can re-issue a reset link from the user editor if mail is down.
 *
 * @param list<array> $rows  Output of bulk_users_parse()
 * @param string $resetUrlBase  Scheme+host so the email can build the absolute reset URL.
 * @return array{created:int, skipped_exists:int, skipped_invalid:int, mail_failed:int, rows:list<array>}
 */
function bulk_users_apply(array $rows, string $resetUrlBase): array {
    $allGalaxies = db_get_constellations();
    $slugToId = [];
    foreach ($allGalaxies as $g) {
        if (!empty($g['slug'])) $slugToId[strtolower((string)$g['slug'])] = (int)$g['id'];
    }

    $appName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? (string)MAIL_FROM_NAME : 'Telaris';
    $created = 0; $skippedExists = 0; $skippedInvalid = 0; $mailFailed = 0;
    $outRows = [];

    require_once __DIR__ . '/mail.php';

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
            $userId = ($row['role'] === 'admin' ? 'admin_' : 'user_') . bin2hex(random_bytes(8));
            $type = $row['role'] === 'admin' ? 2 : 1;
            // Insert with an unguessable placeholder hash; the user sets a real password via the
            // reset link in their welcome email (placeholder is never derivable / never accepted).
            $randomPlaceholder = bin2hex(random_bytes(16));
            $hashed = password_hash($randomPlaceholder, PASSWORD_DEFAULT);
            db_insert_user($userId, $row['email'], $hashed, $row['firstname'], $row['lastname'], $type);

            // Galaxy permissions (if editor + slugs given). Admins see everything anyway.
            if ($type === 1 && !empty($row['galaxy_slugs'])) {
                $galaxyIds = [];
                foreach ($row['galaxy_slugs'] as $slug) {
                    $key = strtolower($slug);
                    if (isset($slugToId[$key])) $galaxyIds[] = $slugToId[$key];
                }
                if (!empty($galaxyIds)) db_set_user_constellations($userId, $galaxyIds);
            }

            // Send setup email with single-use reset link (same flow as forgot-password).
            $token = db_create_password_reset_token($userId, 86400 * 7); // 7-day TTL for first-time setup
            $resetUrl = rtrim($resetUrlBase, '/') . '/utils/reset.php?token=' . urlencode($token);
            $name = trim($row['firstname'] . ' ' . $row['lastname']);
            $greeting = $name !== '' ? 'Hi ' . htmlspecialchars($name) . ',' : 'Hi,';
            $subject = 'Welcome to ' . $appName . ' — set your password';
            $html = '<p>' . $greeting . '</p>'
                  . '<p>An account has been created for you on ' . htmlspecialchars($appName) . '. Click the link below to set your password and sign in:</p>'
                  . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>'
                  . '<p>This link is valid for 7 days and can only be used once. If the link expires before you use it, you can request a new one from the login page.</p>'
                  . '<p>— ' . htmlspecialchars($appName) . '</p>';
            $text = "An account has been created for you on $appName.\n\nSet your password (link valid 7 days, single-use):\n$resetUrl\n";
            $sent = mail_send($row['email'], $subject, $html, $text, $name !== '' ? $name : null);

            $created++;
            if (!$sent) $mailFailed++;
            $r['outcome'] = $sent ? 'created' : 'created_mail_failed';
            $outRows[] = $r;
        } catch (Throwable $e) {
            error_log('bulk_users_apply error for ' . $row['email'] . ': ' . $e->getMessage());
            $r['outcome'] = 'create_failed';
            $r['note'] = 'Internal error';
            $skippedInvalid++;
            $outRows[] = $r;
        }
    }

    return [
        'created' => $created,
        'skipped_exists' => $skippedExists,
        'skipped_invalid' => $skippedInvalid,
        'mail_failed' => $mailFailed,
        'rows' => $outRows,
    ];
}
