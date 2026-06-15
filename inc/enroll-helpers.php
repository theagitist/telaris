<?php
declare(strict_types=1);

/**
 * Editor self-enrollment: pure, database-free decision helpers.
 *
 * These functions hold the logic behind the auto-enroll feature (is enrollment
 * open right now, is this config well-formed, is this email's domain allowed,
 * what should a new personal galaxy be named) with NO database or request
 * dependency, so they can be unit-tested in isolation and reused by both the
 * DB layer (inc/db.php) and the request layer (utils/enroll.php, the main-view
 * link, the admin save handler).
 *
 * Canonical design: vault Projects/Telaris/Features/Editor self-enrollment.md.
 */

/** Valid personal-galaxy naming conventions. user_choice = defer to first login. */
const ENROLL_NAMING_CONVENTIONS = ['full_email', 'email_username', 'first_name', 'full_name', 'user_choice'];
// Default to the first name: galaxy names are public (3D view + URL slug), so the
// email-based conventions would expose the editor's address. Admins can still pick
// them explicitly with the privacy warning shown in the Auto-enroll modal.
const ENROLL_NAMING_DEFAULT = 'first_name';

/** Per-seat access levels. Mirrors user_constellations.access_level. */
const ENROLL_ACCESS_LEVELS = ['read_write', 'read_only'];
const ENROLL_ACCESS_DEFAULT = 'read_write';

/**
 * The predicate behind db_auto_enroll_is_open(): enrollment is open when the
 * feature is enabled AND either there is no cap, the cap is non-positive (which
 * we treat as "no cap"), or the current unvetted-editor count is below the cap.
 * The DB wrapper supplies $count from db_count_unvetted_editors().
 */
function auto_enroll_compute_open(bool $enabled, bool $capEnabled, int $cap, int $count): bool
{
    if (!$enabled) {
        return false;
    }
    if (!$capEnabled || $cap <= 0) {
        return true;
    }
    return $count < $cap;
}

/**
 * Coerce a raw (untrusted, e.g. decoded JSON or posted form) config array into
 * the canonical auto-enroll config shape with safe defaults. Enrollment is
 * disabled unless explicitly enabled. Unknown enum values fall back safely.
 *
 * @param array<string,mixed> $raw
 * @return array{enabled:bool,create_personal_galaxy:bool,naming_convention:string,domains:list<string>,galaxy_ids:list<int>,access_level:string,cap_enabled:bool,cap:int}
 */
function auto_enroll_normalize_config(array $raw): array
{
    $naming = is_string($raw['naming_convention'] ?? null) ? $raw['naming_convention'] : ENROLL_NAMING_DEFAULT;
    if (!in_array($naming, ENROLL_NAMING_CONVENTIONS, true)) {
        $naming = ENROLL_NAMING_DEFAULT;
    }

    $access = is_string($raw['access_level'] ?? null) ? $raw['access_level'] : ENROLL_ACCESS_DEFAULT;
    if (!in_array($access, ENROLL_ACCESS_LEVELS, true)) {
        $access = ENROLL_ACCESS_DEFAULT;
    }

    $galaxyIds = [];
    if (is_array($raw['galaxy_ids'] ?? null)) {
        foreach ($raw['galaxy_ids'] as $g) {
            $gi = (int) $g;
            if ($gi > 0) {
                $galaxyIds[$gi] = true;
            }
        }
    }
    $galaxyIds = array_keys($galaxyIds);
    sort($galaxyIds);

    $capEnabled = !empty($raw['cap_enabled']);
    $cap = (int) ($raw['cap'] ?? 0);
    if ($cap < 0) {
        $cap = 0;
    }
    // Normalize a meaningless cap (disabled, or <= 0) to a single canonical state.
    if (!$capEnabled || $cap <= 0) {
        $capEnabled = false;
        $cap = 0;
    }

    return [
        'enabled'                => !empty($raw['enabled']),
        'create_personal_galaxy' => !empty($raw['create_personal_galaxy']),
        'naming_convention'      => $naming,
        'domains'                => enroll_normalize_domains($raw['domains'] ?? []),
        'galaxy_ids'             => $galaxyIds,
        'access_level'           => $access,
        'cap_enabled'            => $capEnabled,
        'cap'                    => $cap,
    ];
}

/**
 * Normalize an email-domain allowlist. Accepts either an array of strings or a
 * single string with comma / whitespace / semicolon / newline separators.
 * Lowercases, trims, tolerates a leading "@", drops anything that is not a
 * plausible domain, and de-duplicates. An empty result means "allow any domain".
 *
 * @param mixed $raw
 * @return list<string>
 */
function enroll_normalize_domains($raw): array
{
    if (is_array($raw)) {
        $items = $raw;
    } elseif (is_string($raw)) {
        $items = preg_split('/[\s,;]+/', $raw) ?: [];
    } else {
        return [];
    }

    $out = [];
    foreach ($items as $d) {
        if (!is_string($d)) {
            continue;
        }
        $d = ltrim(strtolower(trim($d)), '@');
        if ($d === '') {
            continue;
        }
        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $d)) {
            continue;
        }
        $out[$d] = true;
    }
    return array_keys($out);
}

/**
 * Is $email's domain permitted by $domains? An empty allowlist permits any
 * domain. Matching is case-insensitive and subdomain-inclusive: an allowlist
 * entry "ubc.ca" permits both "ubc.ca" and "students.ubc.ca" but not
 * "notubc.ca". A malformed email (no "@") is rejected when an allowlist exists.
 *
 * @param list<string> $domains
 */
function enroll_email_domain_allowed(string $email, array $domains): bool
{
    if ($domains === []) {
        return true;
    }
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $emailDomain = strtolower(substr($email, $at + 1));
    if ($emailDomain === '') {
        return false;
    }
    foreach ($domains as $d) {
        $d = strtolower((string) $d);
        if ($d === '') {
            continue;
        }
        if ($emailDomain === $d || str_ends_with($emailDomain, '.' . $d)) {
            return true;
        }
    }
    return false;
}

/**
 * Compute the name for a new editor's auto-created personal galaxy, per the
 * configured naming convention. Returns null when no name can be derived,
 * including the deferred 'user_choice' convention (the caller then prompts the
 * editor at first login) and any unknown convention.
 *
 * $possessiveTemplate is a printf template for the 'first_name' convention so
 * the localized "%s's galaxy" string can be injected at the call site while
 * this helper stays database- and locale-free for unit testing.
 */
function enroll_personal_galaxy_name(string $convention, string $email, string $firstname, string $possessiveTemplate = "%s's galaxy"): ?string
{
    switch ($convention) {
        case 'full_email':
            $e = trim($email);
            return $e !== '' ? $e : null;
        case 'email_username':
            $e = trim($email);
            $at = strpos($e, '@');
            $u = $at === false ? $e : trim(substr($e, 0, $at));
            return $u !== '' ? $u : null;
        case 'first_name':
            $f = trim($firstname);
            return $f !== '' ? sprintf($possessiveTemplate, $f) : null;
        case 'full_name':
            // The enrolment form's single name field holds the editor's full name,
            // so the galaxy is named after it as entered (e.g. "Alex Rose"); the
            // slug then derives to "alex-rose" via db_slugify at create time.
            $n = trim($firstname);
            return $n !== '' ? $n : null;
        case 'user_choice':
        default:
            return null;
    }
}

/**
 * The installation's subdomain label, uppercased, used to name the
 * per-installation cluster that gathers auto-enrolled editors' personal
 * galaxies (e.g. "grsj306.telaris.ca" -> "GRSJ306", bracketed by the caller as
 * "[GRSJ306]"). Resolved from trusted config (SITE_BASE_URL, then
 * TELARIS_HOSTNAME), never the request Host header (attacker-controllable, and
 * empty under CLI). Returns null when no trusted hostname is configured, so the
 * caller can skip grouping rather than create a bogus cluster.
 */
function enroll_installation_subdomain(): ?string
{
    $host = function_exists('instance_setting_get') ? instance_setting_get('site_base_url') : '';
    if ($host === '') {
        $host = function_exists('instance_setting_get') ? instance_setting_get('telaris_hostname') : '';
    }
    if ($host === '') {
        return null;
    }
    // Strip scheme, then any path/port; take the first DNS label.
    $host = (string)preg_replace('#^https?://#i', '', $host);
    $host = (string)preg_replace('#[/:].*$#', '', $host);
    $label = explode('.', strtolower(trim($host)))[0] ?? '';
    $label = (string)preg_replace('/[^a-z0-9-]/', '', $label);
    return $label !== '' ? strtoupper($label) : null;
}

/**
 * The bracketed name of the per-installation cluster that gathers auto-enrolled
 * editors' personal galaxies (e.g. "[GRSJ306]"), or null when no trusted
 * hostname is configured. Single source of truth so enrolment and the backfill
 * sweep always target the same cluster, on every installation.
 */
function enroll_installation_cluster_name(): ?string
{
    $sub = enroll_installation_subdomain();
    return $sub !== null ? '[' . $sub . ']' : null;
}
