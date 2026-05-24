<?php
declare(strict_types=1);

/**
 * Bridge: Mocambos / Baobáxia.
 *
 * Pulls galaxias from a Mocambos / Baobáxia instance into Telaris constellations.
 * Entry points are mocambos_handle_request() for HTTP (called by api/bridge.php)
 * and mocambos_run_cli() for the shell (called by admin/cli/import_bridge.php).
 * mocambos_cli_args_from_source() is the optional refresh hook called by
 * admin/cli/refresh_constellation.php.
 *
 * The actual per-galaxia import is in _mocambos_import_galaxia(); both entry
 * points produce a streamMsg / logger callback pair and delegate to it.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../api-error.php';
require_once __DIR__ . '/download.php';
require_once __DIR__ . '/sync.php';

// ── SSRF defence ─────────────────────────────────────────────────────────────

/**
 * The default Mocambos / Baobáxia hosts the bridge is willing to talk to.
 * Operators override via the TELARIS_BRIDGE_MOCAMBOS_HOSTS constant in
 * config.php. Match is exact or by trailing-dot suffix (".mocambos.net"
 * matches "oya.mocambos.net" and "timbuktu.mocambos.net" without permitting
 * "evilmocambos.net.attacker.tld").
 */
const MOCAMBOS_DEFAULT_ALLOWED_HOSTS = [
    'mocambos.net',
    'baobaxia.net',
];

/**
 * Return null when $url is safe for the Mocambos bridge to fetch from, or a
 * short reason string when it is not. Checks:
 *   1. URL parses, scheme is http/https.
 *   2. Host is on the operator's allow-list (or one of its subdomains).
 *   3. Host is not an IP literal in a private/loopback/link-local range.
 *   4. Host resolves to public IPs only; any A or AAAA in a private range
 *      rejects the whole host (no partial trust).
 *
 * This narrows the SSRF surface to operator-configured upstreams. It is not
 * TOCTOU-proof against DNS rebinding; defence in depth: redirects are
 * disabled in download.php and _mocambos_fetch_json revalidates on each call.
 */
function _mocambos_validate_safe_url(string $url): ?string {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return 'Invalid URL format.';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
        return 'URL is missing host or scheme.';
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return 'Only http and https URLs are allowed.';
    }
    $host = strtolower($parts['host']);

    $allow = defined('TELARIS_BRIDGE_MOCAMBOS_HOSTS') && is_array(TELARIS_BRIDGE_MOCAMBOS_HOSTS)
        ? TELARIS_BRIDGE_MOCAMBOS_HOSTS
        : MOCAMBOS_DEFAULT_ALLOWED_HOSTS;
    $hostAllowed = false;
    foreach ($allow as $entry) {
        $entry = strtolower(trim((string)$entry));
        if ($entry === '') continue;
        if ($host === $entry || str_ends_with($host, '.' . $entry)) {
            $hostAllowed = true;
            break;
        }
    }
    if (!$hostAllowed) {
        return 'Host is not on the configured Mocambos allow-list.';
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return _mocambos_ip_is_public($host) ? null : 'IP literal is in a private, loopback, or link-local range.';
    }

    // Per-request resolve cache. An import run hits the same upstream host
    // many times (/galaxia, /mucua, /acervo/find, /blog/find, media
    // downloads, etc.); without caching, every call paid one or two
    // dns_get_record + gethostbynamel waits. With the cache, the validator
    // resolves once per host per request and reuses the IP list. Cache scope
    // is the request; long-term DNS rebinding is bounded by the request
    // lifetime, which combined with set_time_limit(1800) on the bridge
    // handler caps the trust window.
    static $resolveCache = [];
    if (isset($resolveCache[$host])) {
        $resolved = $resolveCache[$host];
    } else {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $resolved = [];
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (isset($rec['ip'])) $resolved[] = (string)$rec['ip'];
                if (isset($rec['ipv6'])) $resolved[] = (string)$rec['ipv6'];
            }
        }
        if ($resolved === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $resolved = $ipv4;
            }
        }
        $resolveCache[$host] = $resolved;
    }
    if ($resolved === []) {
        return 'Host could not be resolved.';
    }
    foreach ($resolved as $ip) {
        if (!_mocambos_ip_is_public($ip)) {
            return 'Host resolves to a private, loopback, or link-local IP.';
        }
    }
    return null;
}

/**
 * Public-internet IP check using PHP's filter ranges:
 *   NO_PRIV_RANGE rejects 10/8, 172.16/12, 192.168/16, fc00::/7.
 *   NO_RES_RANGE rejects 0/8, 127/8, 169.254/16, the IANA-special documented
 *   ranges, the multicast block, ::1, and fe80::/10.
 */
function _mocambos_ip_is_public(string $ip): bool {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

// ── HTTP entry point ─────────────────────────────────────────────────────────

function mocambos_handle_request(): void {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET' && $action === 'validate') {
        requireWriteAccess();
        _mocambos_http_validate();
    } elseif ($method === 'GET' && $action === 'galaxias') {
        requireWriteAccess();
        _mocambos_http_list_galaxias();
    } elseif ($method === 'POST' && $action === 'import') {
        requireWriteAccess();
        // Allow a long import but cap it: 30 minutes is well above observed
        // import times and prevents a wedged Mocambos upstream from pinning
        // an FPM worker forever.
        set_time_limit(1800);
        _mocambos_http_import();
    } else {
        api_error('405.001', 'Method not allowed.');
    }
}

// ── Optional presentation hooks ──────────────────────────────────────────────

/**
 * Visitor-side: the icon URL applied to auto-generated cluster pseudo-nodes
 * in constellations this bridge imported. Called from api/nodes.php via
 * bridges_cluster_icon_url_for().
 */
function mocambos_cluster_icon_url(): string {
    return 'img/bridges/mocambos/cluster.svg';
}

// ── Refresh hook (optional handler interface) ────────────────────────────────

/**
 * Build the CLI argv tail that re-imports a constellation whose
 * import_source JSON was stamped by this bridge. Returns null if the
 * stored source cannot be refreshed (missing fields, etc.). Used by
 * admin/cli/refresh_constellation.php to route refresh from a stored
 * source back through the bridge-specific import path.
 */
function mocambos_cli_args_from_source(array $source): ?array {
    $apiBase = $source['api_base'] ?? '';
    $galaxiaSlug = $source['galaxia_slug'] ?? '';
    if ($apiBase === '' || $galaxiaSlug === '') {
        return null;
    }
    return [
        '--api-base=' . $apiBase,
        '--galaxia=' . $galaxiaSlug,
    ];
}

// ── CLI entry point ──────────────────────────────────────────────────────────

/**
 * CLI entry point. Parses Mocambos-specific flags via getopt() on the global
 * $argv (PHP getopt ignores the bridge-name positional automatically) and
 * runs the import. Returns the process exit code.
 *
 * Recognised flags: --api-base=URL, --galaxia=SLUG, --list, --no-media,
 * --limit=N, --quiet, --full. With no flags, drops into interactive mode.
 */
function mocambos_run_cli(): int {
    $opts = getopt('', ['api-base:', 'galaxia:', 'list', 'no-media', 'limit:', 'quiet', 'full']);
    $interactive = empty($opts) || (count($opts) === 1 && isset($opts['quiet']));

    $quiet = isset($opts['quiet']);
    $fullRefresh = isset($opts['full']);
    $apiBase = trim($opts['api-base'] ?? '');
    $listMode = isset($opts['list']);
    $galaxiaSlug = trim($opts['galaxia'] ?? '');
    $noMedia = isset($opts['no-media']);
    $limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;

    $log = _mocambos_cli_logger($quiet);

    if ($apiBase === '') {
        if ($interactive) {
            echo "\n\033[1m" . t('mocambos_h_cli_header', 'Mocambos Import') . "\033[0m\n\n";
            $apiBase = _mocambos_cli_prompt(t('mocambos_h_cli_prompt_api_base', 'Mocambos API base URL'), 'https://oya.mocambos.net/api/v2');
        } else {
            fwrite(STDERR, t('mocambos_h_cli_err_api_base_required', 'Error: --api-base is required.') . "\n");
            fwrite(STDERR, t('mocambos_h_cli_err_usage', 'Usage: php admin/cli/import_bridge.php mocambos --api-base=URL --galaxia=SLUG') . "\n");
            return 1;
        }
    }
    $apiBase = rtrim($apiBase, '/');

    $log('INFO', sprintf(t('mocambos_h_cli_connecting', 'Connecting to %s...'), $apiBase));

    $galaxias = _mocambos_fetch_json($apiBase . '/galaxia');
    if (!is_array($galaxias)) {
        $log('ERROR', sprintf(t('mocambos_h_cli_fetch_galaxias_failed', 'Failed to fetch galaxia list from %s.'), $apiBase . '/galaxia'));
        return 1;
    }

    $mucuaMaps = _mocambos_fetch_mucua_maps($apiBase);
    $log('INFO', sprintf(t('mocambos_h_cli_found_counts', 'Found %d galaxia(s), %d mucua(s).'), count($galaxias), count($mucuaMaps['name'])));

    $galaxiaInfoMap = [];
    $galaxiaIndexed = [];
    foreach ($galaxias as $i => $g) {
        $slug = $g['slug'] ?? '';
        $galaxiaInfoMap[$slug] = $g;
        $galaxiaIndexed[$i + 1] = $g;
    }

    // List-only mode.
    if ($listMode) {
        echo "\n" . sprintf(t('mocambos_h_cli_available_galaxias_at', 'Available galaxias at %s:'), $apiBase) . "\n\n";
        printf("  %-40s %-20s %s\n", t('mocambos_h_cli_col_slug', 'SLUG'), t('mocambos_h_cli_col_name', 'NAME'), t('mocambos_h_cli_col_smid', 'SMID'));
        printf("  %-40s %-20s %s\n", str_repeat('-', 40), str_repeat('-', 20), str_repeat('-', 36));
        foreach ($galaxias as $g) {
            printf("  %-40s %-20s %s\n", $g['slug'] ?? '?', $g['name'] ?? '?', $g['smid'] ?? '?');
        }
        echo "\n";
        return 0;
    }

    // Interactive: choose galaxia.
    if ($galaxiaSlug === '' && $interactive) {
        db_ensure_constellations_import_source_column();
        $constellations = db_get_constellations();
        $importedSlugs = [];
        foreach ($constellations as $c) {
            if ($c['import_source'] !== null) {
                $src = json_decode($c['import_source'], true);
                if (is_array($src) && isset($src['galaxia_slug'])) {
                    $importedSlugs[$src['galaxia_slug']] = true;
                }
            }
        }
        echo "\n" . t('mocambos_h_cli_available_galaxias', 'Available galaxias:') . "\n\n";
        foreach ($galaxiaIndexed as $num => $g) {
            $name = $g['name'] ?? $g['slug'] ?? '?';
            $slug = $g['slug'] ?? '?';
            $imported = isset($importedSlugs[$slug]) ? ' \033[33m' . t('mocambos_h_cli_already_imported', '(already imported)') . '\033[0m' : '';
            printf("  \033[1m%d)\033[0m %s; %s%s\n", $num, $name, $slug, $imported);
        }
        echo "\n";
        $choice = _mocambos_cli_prompt(t('mocambos_h_cli_prompt_select_galaxia', 'Select galaxia number (or type slug)'));
        if (ctype_digit($choice) && isset($galaxiaIndexed[(int)$choice])) {
            $galaxiaSlug = $galaxiaIndexed[(int)$choice]['slug'] ?? '';
        } else {
            $galaxiaSlug = $choice;
        }
        if ($galaxiaSlug === '') {
            echo t('mocambos_h_cli_no_galaxia_selected', 'No galaxia selected.') . "\n";
            return 0;
        }
    }

    if ($galaxiaSlug === '') {
        fwrite(STDERR, t('mocambos_h_cli_err_galaxia_required', 'Error: --galaxia=SLUG is required.') . "\n");
        return 1;
    }

    // Locate the galaxia (try partial match).
    $galInfo = $galaxiaInfoMap[$galaxiaSlug] ?? null;
    if ($galInfo === null) {
        foreach ($galaxiaInfoMap as $slug => $info) {
            if (str_contains($slug, $galaxiaSlug)) {
                $galInfo = $info;
                $galaxiaSlug = $slug;
                $log('INFO', sprintf(t('mocambos_h_cli_matched_slug', 'Matched galaxia slug: %s.'), $slug));
                break;
            }
        }
    }
    if ($galInfo === null) {
        $log('ERROR', sprintf(t('mocambos_h_cli_galaxia_not_found', 'Galaxia "%s" not found. Use --list to see available galaxias.'), $galaxiaSlug));
        return 1;
    }

    if ($interactive) {
        echo "\n";
        $noMedia = !_mocambos_cli_confirm(t('mocambos_h_cli_prompt_download_media', 'Download media files? (slower but includes images/audio/video)'));
        $limitInput = _mocambos_cli_prompt(t('mocambos_h_cli_prompt_limit', 'Limit number of items? (enter number, or press Enter for all)'), '0');
        $limit = (int)$limitInput;
    }

    $galaxiaSmid = $galInfo['smid'] ?? '';
    $galaxiaName = $galInfo['name'] ?? $galaxiaSlug;
    $defaultMucua = $galInfo['default_mucua'] ?? '';
    $mucuaSlug = '';
    if ($defaultMucua !== '' && isset($mucuaMaps['slug'][$defaultMucua])) {
        $mucuaSlug = $mucuaMaps['slug'][$defaultMucua];
    }

    if ($interactive) {
        echo "\n";
        echo "  " . t('mocambos_h_cli_summary_galaxia', 'Galaxia:') . "  {$galaxiaName} ({$galaxiaSlug})\n";
        echo "  " . t('mocambos_h_cli_summary_api', 'API:') . "      {$apiBase}\n";
        echo "  " . t('mocambos_h_cli_summary_media', 'Media:') . "    " . ($noMedia ? t('mocambos_h_cli_value_skip', 'skip') : t('mocambos_h_cli_value_download', 'download')) . "\n";
        echo "  " . t('mocambos_h_cli_summary_limit', 'Limit:') . "    " . ($limit > 0 ? $limit : t('mocambos_h_cli_value_all', 'all')) . "\n";
        echo "\n";
        if (!_mocambos_cli_confirm(t('mocambos_h_cli_prompt_proceed', 'Proceed with import?'))) {
            echo t('mocambos_h_cli_aborted', 'Aborted.') . "\n";
            return 0;
        }
        echo "\n";
    }

    $log('INFO', sprintf(t('mocambos_h_cli_galaxia_info', 'Galaxia: %s (slug=%s, smid=%s).'), $galaxiaName, $galaxiaSlug, $galaxiaSmid));

    // Fetch all items, filter by galaxia.
    $allItems = _mocambos_fetch_all_items($apiBase, function(string $msg) use ($log) { $log('INFO', $msg); });
    $galaxiaItems = array_values(array_filter($allItems, fn($item) => ($item['galaxia_smid'] ?? '') === $galaxiaSmid));
    $log('INFO', sprintf(t('mocambos_h_cli_total_items', 'Total items for this galaxia: %d.'), count($galaxiaItems)));

    if ($limit > 0 && count($galaxiaItems) > $limit) {
        $galaxiaItems = array_slice($galaxiaItems, 0, $limit);
        $log('WARN', sprintf(t('mocambos_h_cli_limited_to', 'Limited to %d items (--limit).'), $limit));
    }

    // streamMsg for CLI mode just routes through the colored logger.
    $streamMsg = function(string $type, string $message, array $extra = []) use ($log) {
        $level = match($type) {
            'error' => 'ERROR',
            'warning' => 'WARN',
            'success' => 'OK',
            'download' => 'DL',
            default => 'INFO',
        };
        $log($level, $message);
    };

    $logger = function(string $level, string $msg) {
        // No-op for the shared download helper. CLI logs are handled by streamMsg.
    };

    $startTime = microtime(true);

    $result = _mocambos_import_galaxia([
        'api_base' => $apiBase,
        'galaxia_slug' => $galaxiaSlug,
        'galaxia_smid' => $galaxiaSmid,
        'galaxia_name' => $galaxiaName,
        'galaxia_desc' => $galInfo['description'] ?? '',
        'mucua_slug' => $mucuaSlug,
        'galaxia_items' => $galaxiaItems,
        'mucua_name_map' => $mucuaMaps['name'],
        'mucua_slug_map' => $mucuaMaps['slug'],
        'mucua_uri_map' => $mucuaMaps['uri'],
        'full_refresh' => $fullRefresh,
        'skip_media' => $noMedia,
    ], $streamMsg, $logger);

    $elapsed = round(microtime(true) - $startTime, 1);
    echo "\n";
    $log('INFO', str_repeat('─', 50));
    $log('INFO', sprintf(t('mocambos_h_cli_constellation_label', 'Constellation: %s (id %d).'), $galaxiaName, $result['constellation_id']));
    $log('INFO', sprintf(t('mocambos_h_cli_imported_summary', 'Imported: %d/%d items in %ss.'), $result['imported_count'], $result['expected_count'], $elapsed));
    if (count($result['errors']) > 0) {
        $log('WARN', sprintf(t('mocambos_h_cli_errors_count', 'Errors: %d.'), count($result['errors'])));
    }
    if ($noMedia) {
        $log('INFO', t('mocambos_h_cli_media_skipped', 'Media downloads skipped (--no-media).'));
    }
    $log('INFO', $result['is_new']
        ? t('mocambos_h_cli_constellation_new', 'New constellation created.')
        : t('mocambos_h_cli_constellation_existing', 'Existing constellation re-imported.'));
    echo "\n";
    return 0;
}

// ── HTTP action handlers ─────────────────────────────────────────────────────

function _mocambos_http_validate(): void {
    $apiBase = trim($_GET['api_base'] ?? '');
    if ($apiBase === '' || !filter_var($apiBase, FILTER_VALIDATE_URL)) {
        api_error('400.044', 'Invalid URL format. Expected a full URL like https://hostname/api/v2.');
    }
    if (!preg_match('#^https?://#', $apiBase)) {
        api_error('400.003', 'Invalid URL: only http and https URLs are allowed.');
    }
    $safetyError = _mocambos_validate_safe_url($apiBase);
    if ($safetyError !== null) {
        error_log('mocambos.handler validate refused upstream: ' . $safetyError . ' (' . $apiBase . ')');
        api_error('400.046', 'Refusing to fetch from this upstream: %s', [$safetyError]);
    }

    $checks = [];
    $allOk = true;

    $probe = function(string $url) {
        // Audit pass #4 (2026-05-24) SSRF gap: the matching helpers
        // _mocambos_fetch_json (handler.php:1064) and mocambos_download_file
        // (download.php:24) set follow_location=0 / max_redirects=0, but this
        // validate probe didn't. PHP's HTTP wrapper otherwise follows 302
        // redirects up to 20 times, so an allow-listed upstream (or one whose
        // DNS got raced) could 302 the probe into private IP space
        // (169.254.169.254 metadata, internal services). The hostname-allowlist
        // + IP-resolution gate at _mocambos_validate_safe_url runs only on the
        // user-supplied $apiBase, not on redirect targets. Refusing to follow
        // closes the gap.
        $ctx = stream_context_create(['http' => [
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/[\d.]+ (\d+)#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
        }
        if ($body === false) {
            return [false, 0, null, t('mocambos_h_check_connection_failed', 'Connection failed; could not reach the server.')];
        }
        return [$status >= 200 && $status < 300, $status, $body, null];
    };

    // /galaxia
    [$ok, $status, $body, $err] = $probe($apiBase . '/galaxia');
    if (!$ok) {
        $checks[] = ['endpoint' => '/galaxia', 'status' => 'fail', 'http_status' => $status,
            'detail' => $err ?? sprintf(t('mocambos_h_check_galaxia_http_fail', 'HTTP %d; expected 200.'), $status)];
        $allOk = false;
    } else {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $checks[] = ['endpoint' => '/galaxia', 'status' => 'fail', 'http_status' => $status,
                'detail' => sprintf(t('mocambos_h_check_galaxia_not_array', 'Response is not a valid JSON array. Received: %s'), mb_substr((string)$body, 0, 200))];
            $allOk = false;
        } elseif (count($data) === 0) {
            $checks[] = ['endpoint' => '/galaxia', 'status' => 'warn', 'http_status' => $status,
                'detail' => t('mocambos_h_check_galaxia_empty', 'Returned an empty array; no galaxias available to import.')];
        } else {
            $first = $data[0];
            $missing = [];
            foreach (['name', 'slug', 'default_mucua'] as $field) {
                if (!isset($first[$field]) || $first[$field] === '') $missing[] = $field;
            }
            if ($missing) {
                $checks[] = ['endpoint' => '/galaxia', 'status' => 'fail', 'http_status' => $status,
                    'detail' => sprintf(t('mocambos_h_check_galaxia_missing_fields', 'Galaxia objects are missing required fields: %s.'), implode(', ', $missing))];
                $allOk = false;
            } else {
                $checks[] = ['endpoint' => '/galaxia', 'status' => 'ok', 'http_status' => $status,
                    'detail' => sprintf(t('mocambos_h_check_galaxia_ok', 'Found %d galaxia(s). Structure looks correct.'), count($data))];
            }
        }
    }

    // /mucua
    [$ok, $status, $body, $err] = $probe($apiBase . '/mucua');
    if (!$ok) {
        $checks[] = ['endpoint' => '/mucua', 'status' => 'fail', 'http_status' => $status,
            'detail' => $err ?? sprintf(t('mocambos_h_check_mucua_http_fail', 'HTTP %d; expected 200.'), $status)];
        $allOk = false;
    } else {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $checks[] = ['endpoint' => '/mucua', 'status' => 'fail', 'http_status' => $status,
                'detail' => sprintf(t('mocambos_h_check_mucua_not_array', 'Response is not a valid JSON array. Received: %s'), mb_substr((string)$body, 0, 200))];
            $allOk = false;
        } elseif (count($data) === 0) {
            $checks[] = ['endpoint' => '/mucua', 'status' => 'warn', 'http_status' => $status,
                'detail' => t('mocambos_h_check_mucua_empty', 'Returned an empty array; no mucuas found.')];
        } else {
            $first = $data[0];
            $missing = [];
            foreach (['smid', 'slug'] as $field) {
                if (!isset($first[$field]) || $first[$field] === '') $missing[] = $field;
            }
            if ($missing) {
                $checks[] = ['endpoint' => '/mucua', 'status' => 'fail', 'http_status' => $status,
                    'detail' => sprintf(t('mocambos_h_check_mucua_missing_fields', 'Mucua objects are missing required fields: %s.'), implode(', ', $missing))];
                $allOk = false;
            } else {
                $checks[] = ['endpoint' => '/mucua', 'status' => 'ok', 'http_status' => $status,
                    'detail' => sprintf(t('mocambos_h_check_mucua_ok', 'Found %d mucua(s). Structure looks correct.'), count($data))];
            }
        }
    }

    // /acervo/find
    [$ok, $status, $body, $err] = $probe($apiBase . '/acervo/find?pag_tamanho=1');
    if (!$ok) {
        $checks[] = ['endpoint' => '/acervo/find', 'status' => 'fail', 'http_status' => $status,
            'detail' => $err ?? sprintf(t('mocambos_h_check_acervo_http_fail', 'HTTP %d; expected 200.'), $status)];
        $allOk = false;
    } else {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items'])) {
            $checks[] = ['endpoint' => '/acervo/find', 'status' => 'fail', 'http_status' => $status,
                'detail' => sprintf(t('mocambos_h_check_acervo_no_items', 'Response missing "items" key. Received: %s'), mb_substr((string)$body, 0, 200))];
            $allOk = false;
        } else {
            $itemCount = $data['item_count'] ?? count($data['items']);
            $checks[] = ['endpoint' => '/acervo/find', 'status' => 'ok', 'http_status' => $status,
                'detail' => sprintf(t('mocambos_h_check_acervo_ok', 'Returned %d media item(s) total.'), $itemCount)];
        }
    }

    // /blog/find (optional)
    [$ok, $status, $body, $err] = $probe($apiBase . '/blog/find?pag_tamanho=1');
    if (!$ok) {
        $checks[] = ['endpoint' => '/blog/find', 'status' => 'warn', 'http_status' => $status,
            'detail' => $err ?? sprintf(t('mocambos_h_check_blog_http_fail', 'HTTP %d; expected 200.'), $status)];
    } else {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items'])) {
            $checks[] = ['endpoint' => '/blog/find', 'status' => 'warn', 'http_status' => $status,
                'detail' => t('mocambos_h_check_blog_no_items', 'Response missing "items" key. Blog articles will not be imported.')];
        } else {
            $itemCount = $data['item_count'] ?? count($data['items']);
            $checks[] = ['endpoint' => '/blog/find', 'status' => 'ok', 'http_status' => $status,
                'detail' => sprintf(t('mocambos_h_check_blog_ok', 'Returned %d blog article(s) total.'), $itemCount)];
        }
    }

    echo json_encode(['valid' => $allOk, 'checks' => $checks], JSON_THROW_ON_ERROR);
}

function _mocambos_http_list_galaxias(): void {
    $apiBase = trim($_GET['api_base'] ?? '');
    if ($apiBase === '' || !filter_var($apiBase, FILTER_VALIDATE_URL)) {
        api_error('400.044', 'Invalid URL format. Expected a full URL like https://hostname/api/v2.');
    }
    $safetyError = _mocambos_validate_safe_url($apiBase);
    if ($safetyError !== null) {
        error_log('mocambos.handler list refused upstream: ' . $safetyError . ' (' . $apiBase . ')');
        api_error('400.046', 'Refusing to fetch from this upstream: %s', [$safetyError]);
    }

    $galaxias = _mocambos_fetch_json($apiBase . '/galaxia');
    if (!is_array($galaxias)) {
        api_error('502.001', 'Failed to reach the upstream Mocambos API at %s.', [$apiBase]);
    }

    $mucuaMaps = _mocambos_fetch_mucua_maps($apiBase);

    db_ensure_constellations_import_source_column();
    $constellations = db_get_constellations();

    $result = [];
    foreach ($galaxias as $g) {
        $slug = $g['slug'] ?? '';
        $name = $g['name'] ?? $slug;
        $defaultMucuaUuid = $g['default_mucua'] ?? '';
        $mucuaSlug = $mucuaMaps['slug'][$defaultMucuaUuid] ?? '';

        $imported = false;
        $constellationId = null;
        foreach ($constellations as $c) {
            if ($c['import_source'] !== null && $c['import_source'] !== '') {
                $source = json_decode($c['import_source'], true);
                if (is_array($source) && ($source['galaxia_slug'] ?? '') === $slug) {
                    $imported = true;
                    $constellationId = (int)$c['id'];
                    break;
                }
            }
        }

        $result[] = [
            'name' => $name,
            'slug' => $slug,
            'smid' => $g['smid'] ?? '',
            'mucua_slug' => $mucuaSlug,
            'imported' => $imported,
            'constellation_id' => $constellationId,
        ];
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
}

function _mocambos_http_import(): void {
    $input = stream_get_contents(fopen('php://input', 'r'), 10485760);
    $data = json_decode($input, true);
    if (!is_array($data)) {
        api_error('400.001', 'Invalid JSON: %s', [json_last_error_msg()]);
    }

    $apiBase = trim($data['api_base'] ?? 'https://timbuktu.mocambos.net/api/v2');
    $safetyError = _mocambos_validate_safe_url($apiBase);
    if ($safetyError !== null) {
        error_log('mocambos.handler import refused upstream: ' . $safetyError . ' (' . $apiBase . ')');
        api_error('400.046', 'Refusing to fetch from this upstream: %s', [$safetyError]);
    }
    $fullRefresh = !empty($data['full_refresh']);
    $galaxias = $data['galaxias'] ?? [];
    if (!is_array($galaxias) || empty($galaxias)) {
        api_error('400.045', 'No galaxias specified.');
    }

    // Switch to streaming newline-delimited JSON for real-time progress.
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_flush();

    $logDir = defined('LOG_DIR') ? LOG_DIR : (__DIR__ . '/../../logs');
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/mocambos-import-' . date('Y-m-d_H-i-s') . '.log';
    $logFp = fopen($logFile, 'w');

    $writeLog = function(string $level, string $message) use ($logFp) {
        if ($logFp) {
            $ts = date('Y-m-d H:i:s');
            fwrite($logFp, "[{$ts}] [{$level}] {$message}\n");
            fflush($logFp);
        }
    };

    $writeLog('INFO', 'Import started — api_base=' . $apiBase . ', galaxias=' . json_encode(array_column($galaxias, 'galaxia_slug')));
    db_audit_log(
        action: 'bridge.mocambos.import.start',
        actorUserId: $_SESSION['admin_user_id'] ?? null,
        targetType: 'bridge',
        targetId: 'mocambos',
        details: [
            'api_base' => $apiBase,
            'galaxia_slugs' => array_column($galaxias, 'galaxia_slug'),
            'full_refresh' => $fullRefresh,
        ],
        ip: auth_client_ip(),
        actorEmail: $_SESSION['admin_user_email'] ?? null,
    );

    $streamMsg = function(string $type, string $message, array $extra = []) use ($writeLog) {
        $line = json_encode(array_merge(['type' => $type, 'message' => $message], $extra), JSON_THROW_ON_ERROR);
        echo $line . "\n";
        flush();
        $level = match($type) { 'error' => 'ERROR', 'warning' => 'WARN', default => 'INFO' };
        $writeLog($level, $message);
    };

    db_ensure_constellations_import_source_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_import_slug_column();

    // One mucua fetch shared across all selected galaxias.
    $mucuaMaps = _mocambos_fetch_mucua_maps($apiBase);
    $streamMsg('info', sprintf(t('mocambos_h_resolved_mucua_names', 'Resolved %d mucua names.'), count($mucuaMaps['name'])));

    // Fetch all items once across all selected galaxias.
    $streamMsg('info', t('mocambos_h_fetching_media', 'Fetching media items from the Mocambos API...'));
    $allItems = _mocambos_fetch_all_items($apiBase, function(string $msg) use ($streamMsg) {
        $streamMsg('info', $msg);
    });
    $streamMsg('info', sprintf(t('mocambos_h_total_items_fetched', 'Total items fetched: %d.'), count($allItems)));

    // Galaxia info map for names that may not be in selection payload.
    $galaxiaInfoMap = [];
    $galInfo = _mocambos_fetch_json($apiBase . '/galaxia');
    if (is_array($galInfo)) {
        foreach ($galInfo as $gl) {
            $galaxiaInfoMap[$gl['slug'] ?? ''] = $gl;
        }
    }

    $results = [];

    foreach ($galaxias as $gal) {
        $galaxiaSlug = $gal['galaxia_slug'] ?? '';
        $mucuaSlug = $gal['mucua_slug'] ?? '';
        $galaxiaSmid = $gal['galaxia_smid'] ?? '';
        if ($galaxiaSlug === '') continue;

        if ($galaxiaSmid === '' && isset($galaxiaInfoMap[$galaxiaSlug])) {
            $galaxiaSmid = $galaxiaInfoMap[$galaxiaSlug]['smid'] ?? '';
            $writeLog('INFO', "Resolved smid for {$galaxiaSlug} from galaxia list: {$galaxiaSmid}");
        }

        $galaxiaItems = array_values(array_filter($allItems, fn($item) => ($item['galaxia_smid'] ?? '') === $galaxiaSmid));
        $writeLog('INFO', "--- Galaxia: {$galaxiaSlug} (smid={$galaxiaSmid}), mucua: {$mucuaSlug}, items: " . count($galaxiaItems) . " ---");
        $streamMsg('info', sprintf(t('mocambos_h_processing_galaxia', 'Processing galaxia: %s (%d items).'), $galaxiaSlug, count($galaxiaItems)));

        $galInfo = $galaxiaInfoMap[$galaxiaSlug] ?? [];

        $result = _mocambos_import_galaxia([
            'api_base' => $apiBase,
            'galaxia_slug' => $galaxiaSlug,
            'galaxia_smid' => $galaxiaSmid,
            'galaxia_name' => $galInfo['name'] ?? $galaxiaSlug,
            'galaxia_desc' => $galInfo['description'] ?? '',
            'mucua_slug' => $mucuaSlug,
            'galaxia_items' => $galaxiaItems,
            'mucua_name_map' => $mucuaMaps['name'],
            'mucua_slug_map' => $mucuaMaps['slug'],
            'mucua_uri_map' => $mucuaMaps['uri'],
            'full_refresh' => $fullRefresh,
            'skip_media' => false,
        ], $streamMsg, $writeLog);

        $results[] = [
            'galaxia_slug' => $galaxiaSlug,
            'constellation_id' => $result['constellation_id'],
            'is_new' => $result['is_new'],
            'expected_count' => $result['expected_count'],
            'imported_count' => $result['imported_count'],
            'verified_count' => $result['verified_count'],
            'errors' => $result['errors'],
        ];
    }

    $writeLog('INFO', 'Import complete — ' . count($results) . ' galaxia(s) processed');
    if ($logFp) {
        $writeLog('INFO', 'Log file: ' . $logFile);
        fclose($logFp);
    }
    db_audit_log(
        action: 'bridge.mocambos.import.finish',
        actorUserId: $_SESSION['admin_user_id'] ?? null,
        targetType: 'bridge',
        targetId: 'mocambos',
        details: [
            'galaxias_processed' => count($results),
            'totals' => array_map(
                fn($r) => [
                    'slug' => $r['galaxia_slug'],
                    'constellation_id' => $r['constellation_id'],
                    'is_new' => $r['is_new'],
                    'imported' => $r['imported_count'],
                    'expected' => $r['expected_count'],
                    'errors' => count($r['errors'] ?? []),
                ],
                $results,
            ),
        ],
        ip: auth_client_ip(),
        actorEmail: $_SESSION['admin_user_email'] ?? null,
    );
    $streamMsg('done', t('mocambos_h_import_complete', 'Import complete.'), ['success' => true, 'results' => $results]);
}

// ── Shared import core ───────────────────────────────────────────────────────

/**
 * Import a single galaxia. Used by both the HTTP and CLI entry points.
 *
 * $params keys: api_base, galaxia_slug, galaxia_smid, galaxia_name,
 *               galaxia_desc, mucua_slug, galaxia_items, source_facet_map,
 *               mucua_slug_map, mucua_uri_map, full_refresh (bool),
 *               skip_media (bool).
 *
 * $streamMsg(type, message [, extra]) is the user-facing progress callback.
 * $logger(level, message) writes to whatever persistent log the caller keeps;
 * it is also the callback used by mocambos_download_file().
 */
function _mocambos_import_galaxia(array $params, Closure $streamMsg, Closure $logger): array {
    $apiBase       = $params['api_base'];
    $galaxiaSlug   = $params['galaxia_slug'];
    $galaxiaSmid   = $params['galaxia_smid'];
    $galaxiaName   = $params['galaxia_name'];
    $galaxiaDesc   = $params['galaxia_desc'];
    $mucuaSlug     = $params['mucua_slug'];
    $galaxiaItems  = $params['galaxia_items'];
    $mucuaNameMap  = $params['mucua_name_map'];
    $mucuaSlugMap  = $params['mucua_slug_map'];
    $mucuaUriMap   = $params['mucua_uri_map'];
    $fullRefresh   = (bool)$params['full_refresh'];
    $skipMedia     = (bool)($params['skip_media'] ?? false);

    // Audit pass #5 / Race M4 (v6.10.18): per-galaxia advisory lock. Two
    // concurrent imports for the same galaxia_slug would each compute a diff
    // against possibly-different snapshots of `nodes`, then run interleaved
    // INSERT/UPDATE/DELETE — producing duplicate nodes (idx_import_slug is
    // an INDEX, not a UNIQUE) and inconsistent state. We hash the slug to
    // keep the lock key bounded since galaxia_slug is upstream-controlled.
    // On contention the second import returns immediately with a clear error
    // in the report; the caller surfaces it via streamMsg.
    $pdo = getDB();
    $lockKey = 'telaris:mocambos_import:' . hash('sha256', (string)$galaxiaSlug);
    $stmt = $pdo->prepare("SELECT GET_LOCK(:k, 0)");
    $stmt->execute([':k' => $lockKey]);
    $lockResult = $stmt->fetchColumn();
    if ($lockResult !== 1 && $lockResult !== '1') {
        $msg = sprintf(t('mocambos_h_concurrent_import', 'Concurrent import already in progress for galaxy %s; try again later.'), $galaxiaSlug);
        $streamMsg('error', $msg);
        $logger('ERROR', $msg);
        return [
            'constellation_id' => null,
            'is_new' => false,
            'is_incremental' => false,
            'expected_count' => 0,
            'imported_count' => 0,
            'verified_count' => 0,
            'media_count' => 0,
            'media_errors' => 0,
            'errors' => [$msg],
        ];
    }
    // Lock auto-releases at request end via connection close. We don't add an
    // explicit RELEASE here because the import body is the entire purpose of
    // the request from this point on; if the caller batches multiple slugs in
    // one request each gets its own lock key.

    $downloadBase = preg_replace('#/api/v2/?$#', '', $apiBase);
    $errors = [];
    $isNew = true;
    $constellationId = null;
    $isIncremental = false;

    db_ensure_constellations_import_source_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_import_slug_column();

    // Locate existing constellation, if any.
    foreach (db_get_constellations() as $c) {
        if ($c['import_source'] !== null && $c['import_source'] !== '') {
            $source = json_decode($c['import_source'], true);
            if (is_array($source) && ($source['galaxia_slug'] ?? '') === $galaxiaSlug) {
                $constellationId = (int)$c['id'];
                $isNew = false;
                break;
            }
        }
    }

    if ($constellationId !== null && $fullRefresh) {
        $logger('INFO', "Full refresh; clearing all nodes for re-import");
        $streamMsg('info', t('mocambos_h_full_refresh_clearing', 'Full refresh; clearing existing nodes...'));
        db_clear_constellation_nodes($constellationId);
    } elseif ($constellationId !== null) {
        $logger('INFO', "Existing constellation found (ID {$constellationId}), checking for incremental sync");
        $streamMsg('info', t('mocambos_h_re_importing_diff', 'Re-importing; computing diff...'));

        $backfilled = db_backfill_import_slugs($constellationId);
        if ($backfilled > 0) {
            $streamMsg('info', sprintf(t('mocambos_h_backfilled_slugs', 'Backfilled %d import slugs.'), $backfilled));
        }

        $existingBySlug = db_get_nodes_by_import_slug($constellationId);
        $diff = mocambos_compute_diff($existingBySlug, $galaxiaItems, $mucuaNameMap);
        $streamMsg('info', sprintf(t('mocambos_h_diff_summary', 'Diff: %d new, %d modified, %d deleted, %d unchanged.'), count($diff['added']), count($diff['modified']), count($diff['deleted']), $diff['unchanged']));

        if (!empty($diff['deleted'])) {
            $streamMsg('info', sprintf(t('mocambos_h_deleting_removed', 'Deleting %d removed items...'), count($diff['deleted'])));
            mocambos_apply_deletions($diff['deleted'], $constellationId, getDB());
        }
        if (!empty($diff['modified'])) {
            $streamMsg('info', sprintf(t('mocambos_h_updating_modified', 'Updating %d modified items...'), count($diff['modified'])));
            mocambos_apply_modifications($diff['modified'], $constellationId, getDB());
        }
        $isIncremental = true;
        $galaxiaItems = array_map(fn($a) => $a['item'], $diff['added']);
    } else {
        $baseSlug = db_slugify($galaxiaName);
        $slug = $baseSlug;
        $suffix = 2;
        while (true) {
            try {
                $constellationId = db_create_constellation($galaxiaName, $galaxiaDesc ?: '', $slug, 'abstract');
                $streamMsg('success', sprintf(t('mocambos_h_created_constellation', 'Created constellation: %s (id %d).'), $galaxiaName, $constellationId));
                break;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry') && $suffix <= 20) {
                    $slug = $baseSlug . '-' . $suffix++;
                } else {
                    throw $e;
                }
            }
        }
    }

    // Stamp import_source immediately so it shows as imported even if the run is interrupted.
    $importSource = json_encode([
        'source' => 'mocambos',
        'api_base' => $apiBase,
        'galaxia_slug' => $galaxiaSlug,
        'mucua_slug' => $mucuaSlug,
    ], JSON_THROW_ON_ERROR);
    db_set_constellation_import_source($constellationId, $importSource);

    $expectedCount = count($galaxiaItems);
    $importedCount = 0;

    // ── Phase 1: batch-insert new nodes ────────────────────────────────────
    $streamMsg('info', $isIncremental
        ? sprintf(t('mocambos_h_adding_new_nodes', 'Adding %d new nodes...'), $expectedCount)
        : sprintf(t('mocambos_h_phase1_creating', 'Phase 1: creating %d nodes...'), $expectedCount));
    $pdo = getDB();
    $insertStmt = $pdo->prepare("
        INSERT INTO nodes (name, description, url, animation, constellation_id, node_type, audio_autoplay, video_autoplay, source_facet, media_type, source_created_at, import_slug)
        VALUES (:name, :description, :url, :animation, :constellation_id, 'object', 1, 1, :source_facet, :media_type, :source_created_at, :import_slug)
    ");
    $kwInsertStmt = $pdo->prepare("INSERT INTO keywords (keyword, constellation_id) VALUES (:keyword, :cid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $kwLookupStmt = $pdo->prepare("SELECT id FROM keywords WHERE keyword = :keyword AND constellation_id = :cid LIMIT 1");
    $nkInsertStmt = $pdo->prepare("INSERT INTO node_keywords (node_id, keyword_id) VALUES (:nid, :kid) ON DUPLICATE KEY UPDATE node_id=node_id");

    $batchSize = 500;
    $pdo->beginTransaction();
    $nodeIdMap = [];

    foreach ($galaxiaItems as $itemIndex => $item) {
        try {
            $nodeName = $item['title'] ?? ($item['slug'] ?? 'unknown');
            $nodeDesc = $item['description'] ?? '';
            $mediaType = $item['type'] ?? 'arquivo';
            $tags = $item['tags'] ?? [];
            if (!is_array($tags)) $tags = [];

            $itemMucuaSmidVal = $item['mucua_smid'] ?? '';
            $itemMucuaSlugForUrl = $mucuaSlugMap[$itemMucuaSmidVal] ?? '';
            $itemFrontendBase = $mucuaUriMap[$itemMucuaSmidVal] ?? $downloadBase;
            $nodeUrl = $itemFrontendBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlugForUrl . '/' . ($item['slug'] ?? '');

            $animation = _mocambos_random_animation();

            $itemMucuaSmid = $item['mucua_smid'] ?? null;
            $resolvedMucuaName = ($itemMucuaSmid !== null && isset($mucuaNameMap[$itemMucuaSmid])) ? $mucuaNameMap[$itemMucuaSmid] : null;
            $itemMediaType = ($item['_source_type'] ?? '') === 'blog' ? 'blog' : $mediaType;

            $insertStmt->execute([
                ':name' => $nodeName,
                ':description' => $nodeDesc ?: null,
                ':url' => $nodeUrl,
                ':animation' => $animation,
                ':constellation_id' => $constellationId,
                ':source_facet' => $resolvedMucuaName,
                ':media_type' => $itemMediaType,
                ':source_created_at' => $item['created'] ?? null,
                ':import_slug' => $item['slug'] ?? null,
            ]);
            $nodeId = (int)$pdo->lastInsertId();
            $nodeIdMap[$itemIndex] = $nodeId;

            foreach ($tags as $tag) {
                $tag = trim($tag);
                if ($tag === '') continue;
                $kwInsertStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
                $kwId = (int)$pdo->lastInsertId();
                if ($kwId === 0) {
                    $kwLookupStmt->execute([':keyword' => $tag, ':cid' => $constellationId]);
                    $kwId = (int)($kwLookupStmt->fetchColumn() ?: 0);
                }
                if ($kwId > 0) {
                    $nkInsertStmt->execute([':nid' => $nodeId, ':kid' => $kwId]);
                }
            }

            $importedCount++;
            if ($importedCount % $batchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                $streamMsg('info', sprintf(t('mocambos_h_nodes_created_progress', '  %d/%d nodes created.'), $importedCount, $expectedCount));
            }
        } catch (Throwable $e) {
            $errors[] = sprintf(t('mocambos_h_failed_to_create_node', 'Failed to create node: %s (%s).'), $item['slug'] ?? '?', $e->getMessage());
            $logger('ERROR', 'Node create error: ' . $e->getMessage());
        }
    }
    $pdo->commit();
    $streamMsg('success', sprintf(t('mocambos_h_phase1_complete', 'Phase 1 complete: %d/%d nodes created.'), $importedCount, $expectedCount));

    // ── Phase 2: download media files (unless skipped) ─────────────────────
    $mediaCount = 0;
    $mediaErrors = 0;

    if (!$skipMedia) {
        $streamMsg('info', t('mocambos_h_phase2_downloading', 'Phase 2: downloading media files...'));
        $uploadDir = UPLOAD_DIR;

        foreach ($galaxiaItems as $itemIndex => $item) {
            $nodeId = $nodeIdMap[$itemIndex] ?? null;
            if ($nodeId === null) continue;

            $itemSlug = $item['slug'] ?? 'unknown';
            $nodeName = $item['title'] ?? $itemSlug;
            $nodeDesc = $item['description'] ?? '';
            $mediaType = $item['type'] ?? 'arquivo';

            $itemMucuaSmidVal = $item['mucua_smid'] ?? '';
            $itemMucuaSlugForUrl = $mucuaSlugMap[$itemMucuaSmidVal] ?? '';
            $itemFrontendBase = $mucuaUriMap[$itemMucuaSmidVal] ?? $downloadBase;
            $nodeUrl = $itemFrontendBase . '/pt-BR/midia/' . $galaxiaSlug . '/' . $itemMucuaSlugForUrl . '/' . $itemSlug;
            $animation = _mocambos_random_animation();

            $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
            $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
            if (!is_dir($nodeFullDir)) @mkdir($nodeFullDir, 0775, true);

            $imageUrl = null;
            $iconUrl = null;
            $audioUrl = null;
            $videoUrl = null;
            $needsUpdate = false;

            $counter = ($itemIndex + 1) . "/{$expectedCount}";

            $contentHash = '';
            $content = $item['content'] ?? [];
            if (is_array($content) && !empty($content) && isset($content[0]['hash_sum'])) {
                $contentHash = $content[0]['hash_sum'];
            }
            $dlMucuaSlug = $mucuaSlugMap[$item['mucua_smid'] ?? ''] ?? $mucuaSlug;
            $dlBase = $downloadBase . '/' . $galaxiaSlug . '/' . $dlMucuaSlug;
            $hashSuffix = $contentHash !== '' ? '/' . $contentHash : '';

            if ($mediaType === 'imagem') {
                $streamMsg('download', sprintf(t('mocambos_h_downloading_image', '(%s) Downloading image: %s'), $counter, $nodeName));
                $localPath = mocambos_download_file($dlBase . '/acervo/download/' . $itemSlug . $hashSuffix, $nodeFullDir, 'image', $logger);
                if ($localPath !== null) {
                    $relPath = $nodeRelDir . '/' . basename($localPath);
                    $imageUrl = $relPath;
                    $iconUrl = $relPath;
                    $needsUpdate = true;
                } else {
                    $mediaErrors++;
                }
                if ($iconUrl === null) {
                    $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                    if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
                }
            } elseif ($mediaType === 'video') {
                $streamMsg('download', sprintf(t('mocambos_h_downloading_video', '(%s) Downloading video: %s'), $counter, $nodeName));
                $localPath = mocambos_download_file($dlBase . '/acervo/download/' . $itemSlug . $hashSuffix, $nodeFullDir, 'video', $logger);
                if ($localPath !== null) { $videoUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true; } else { $mediaErrors++; }
                $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
            } elseif ($mediaType === 'audio') {
                $streamMsg('download', sprintf(t('mocambos_h_downloading_audio', '(%s) Downloading audio: %s'), $counter, $nodeName));
                $localPath = mocambos_download_file($dlBase . '/acervo/download/' . $itemSlug . $hashSuffix, $nodeFullDir, 'audio', $logger);
                if ($localPath !== null) { $audioUrl = $nodeRelDir . '/' . basename($localPath); $needsUpdate = true; } else { $mediaErrors++; }
                $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
            } else {
                $thumbPath = mocambos_download_file($dlBase . '/acervo/thumbnail/' . $itemSlug, $nodeFullDir, 'icon', $logger);
                if ($thumbPath !== null) { $iconUrl = $nodeRelDir . '/' . basename($thumbPath); $needsUpdate = true; }
            }

            if ($needsUpdate) {
                db_update_node($nodeId, $nodeName, $nodeDesc ?: null, $nodeUrl, $animation, $constellationId, 'object', null, $imageUrl, null, $audioUrl, true, false, $videoUrl, true, false, false, $iconUrl);
                $mediaCount++;
            }
        }
        $streamMsg('success', $mediaErrors > 0
            ? sprintf(t('mocambos_h_phase2_complete_with_errors', 'Phase 2 complete: %d media files downloaded (%d failed).'), $mediaCount, $mediaErrors)
            : sprintf(t('mocambos_h_phase2_complete', 'Phase 2 complete: %d media files downloaded.'), $mediaCount));
        if ($mediaErrors > 0) $errors[] = sprintf(t('mocambos_h_media_downloads_failed', '%d media downloads failed.'), $mediaErrors);
    }

    $actualNodes = db_get_nodes($constellationId);
    $actualCount = count($actualNodes);
    $errCount = count($errors);
    $streamMsg($errCount > 0 ? 'warning' : 'success',
        $errCount > 0
            ? sprintf(t('mocambos_h_galaxia_done_with_errors', 'Galaxia %s done: %d/%d items imported (%d errors).'), $galaxiaSlug, $importedCount, $expectedCount, $errCount)
            : sprintf(t('mocambos_h_galaxia_done', 'Galaxia %s done: %d/%d items imported.'), $galaxiaSlug, $importedCount, $expectedCount));

    return [
        'constellation_id' => $constellationId,
        'is_new' => $isNew,
        'is_incremental' => $isIncremental,
        'expected_count' => $expectedCount,
        'imported_count' => $importedCount,
        'verified_count' => $actualCount,
        'media_count' => $mediaCount,
        'media_errors' => $mediaErrors,
        'errors' => $errors,
    ];
}

// ── Data-fetch helpers ───────────────────────────────────────────────────────

function _mocambos_fetch_json(string $url): mixed {
    $safetyError = _mocambos_validate_safe_url($url);
    if ($safetyError !== null) {
        error_log('mocambos.fetch_json refused: ' . $safetyError . ' (' . $url . ')');
        return null;
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    return json_decode($body, true);
}

/**
 * Build the three mucua maps: smid → name, smid → slug, smid → public_uri.
 */
function _mocambos_fetch_mucua_maps(string $apiBase): array {
    $name = [];
    $slug = [];
    $uri = [];
    $mucuaList = _mocambos_fetch_json($apiBase . '/mucua');
    if (is_array($mucuaList)) {
        foreach ($mucuaList as $m) {
            $mSmid = $m['smid'] ?? $m['uuid'] ?? null;
            if ($mSmid === null) continue;
            $name[$mSmid] = $m['name'] ?? $m['slug'] ?? (string)$mSmid;
            $slug[$mSmid] = $m['slug'] ?? (string)$mSmid;
            $pUri = $m['public_uri'] ?? null;
            if ($pUri !== null && $pUri !== '') {
                $uri[$mSmid] = rtrim($pUri, '/');
            }
        }
    }
    return ['name' => $name, 'slug' => $slug, 'uri' => $uri];
}

/**
 * Pull every item from /acervo/find and /blog/find, tagging each with
 * _source_type so the import loop can distinguish them. $progress receives
 * a one-line message per page fetched.
 */
function _mocambos_fetch_all_items(string $apiBase, ?Closure $progress = null): array {
    $progress ??= function(string $msg) {};
    $allItems = [];

    $page = 1;
    while (true) {
        $data = _mocambos_fetch_json($apiBase . '/acervo/find?pag_tamanho=100&pag_atual=' . $page);
        if (!is_array($data) || !isset($data['items'])) break;
        $pageCount = (int)($data['page_count'] ?? 1);
        foreach ($data['items'] as $item) {
            $item['_source_type'] = 'acervo';
            $allItems[] = $item;
        }
        $progress("Fetched acervo page {$page}/{$pageCount} (" . count($allItems) . " items so far)");
        if ($page >= $pageCount) break;
        $page++;
    }

    $page = 1;
    while (true) {
        $data = _mocambos_fetch_json($apiBase . '/blog/find?pag_tamanho=100&pag_atual=' . $page);
        if (!is_array($data) || !isset($data['items'])) break;
        $pageCount = (int)($data['page_count'] ?? 1);
        foreach ($data['items'] as $item) {
            $item['_source_type'] = 'blog';
            $allItems[] = $item;
        }
        if ($pageCount > 0) {
            $progress("Fetched blog page {$page}/{$pageCount}");
        }
        if ($page >= $pageCount) break;
        $page++;
    }

    return $allItems;
}

function _mocambos_random_animation(): string {
    return json_encode([
        'radius' => 5 + rand(0, 3),
        'theta' => rand(0, 628) / 100,
        'phi' => rand(0, 314) / 100,
        'speed' => 0.002 + (rand(0, 4) / 1000),
        'phase' => rand(0, 628) / 100,
    ], JSON_THROW_ON_ERROR);
}

// ── CLI input helpers ────────────────────────────────────────────────────────

function _mocambos_cli_logger(bool $quiet): Closure {
    return function(string $level, string $msg, bool $verbose = false) use ($quiet) {
        if ($quiet && ($verbose || $level === 'INFO')) return;
        $ts = date('H:i:s');
        $prefix = match($level) {
            'ERROR' => "\033[31m[ERR]\033[0m",
            'WARN'  => "\033[33m[WRN]\033[0m",
            'OK'    => "\033[32m[OK ]\033[0m",
            'DL'    => "\033[35m[DL ]\033[0m",
            default => "\033[36m[INF]\033[0m",
        };
        echo "{$prefix} {$ts} {$msg}\n";
    };
}

function _mocambos_cli_prompt(string $prompt, string $default = ''): string {
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $prompt . $suffix . ': ';
    $input = trim(fgets(STDIN) ?: '');
    return $input !== '' ? $input : $default;
}

function _mocambos_cli_confirm(string $prompt): bool {
    echo $prompt . ' [y/N]: ';
    $input = strtolower(trim(fgets(STDIN) ?: ''));
    return $input === 'y' || $input === 'yes';
}
