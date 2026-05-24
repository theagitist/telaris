<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/identity
 *
 * Returns the instance's federation identity envelope: hostname, label,
 * Telaris version, protocol version, base64-encoded public key, fingerprint,
 * and the configured Pluriverse coordination endpoint.
 *
 * Spec: P2P federation plan v10 § Pluriverse protocol → Instance-side
 *       endpoint catalogue (line 457) and § Instance-identity storage (line 120).
 *
 * Authentication: none. Public-read.
 * Rate limit: 60 req/min/IP via APCu (best-effort; nginx limit_req remains
 * the load-bearing protection — defence in depth).
 *
 * No state writes; no DB writes; reads project_info(en).name for the label
 * and the in-process-cached public key derived from secrets/pluriverse.key.
 */

require_once __DIR__ . '/identity.php';

// Best-effort per-IP rate limit: 60 requests per minute per source IP.
// Mirrors api/csp-report.php pattern.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_identity:' . date('YmdHi') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many identity requests from this IP this minute; retry shortly.',
            '/api/pluriverse/identity'
        );
        return;
    }
}

try {
    $publicKey = federation_public_key();
    $fingerprint = federation_public_key_fingerprint();
} catch (Throwable $e) {
    // pluriverse.key missing or unreadable. The operator hasn't completed
    // `bin/init-identity`. Surface as 503 so monitoring distinguishes
    // not-yet-provisioned from "endpoint broken".
    error_log('pluriverse/identity: ' . $e->getMessage());
    federation_router_problem(
        503,
        'identity_unavailable',
        'This instance has not been provisioned with a federation identity yet.',
        '/api/pluriverse/identity'
    );
    return;
}

$hostname = (string)($_SERVER['HTTP_HOST'] ?? gethostname() ?: 'unknown');
// Strip port if present (X-Forwarded-Host or direct).
if (str_contains($hostname, ':')) {
    $hostname = (string)strstr($hostname, ':', true);
}

$versionFile = dirname(__DIR__, 2) . '/VERSION';
$telarisVersion = is_readable($versionFile) ? trim((string)@file_get_contents($versionFile)) : 'unknown';

// Label comes from project_info(en).name. The English row is the canonical
// machine-readable label; locale-translated rows are visitor chrome, not
// machine identity.
$label = $hostname;
if (function_exists('db_get_project_info')) {
    try {
        $info = db_get_project_info();
        if (is_array($info) && isset($info['name']) && is_string($info['name']) && $info['name'] !== '') {
            $label = $info['name'];
        }
    } catch (Throwable $e) {
        // Fall back to hostname; identity is best-effort on label.
        error_log('pluriverse/identity: project_info lookup failed: ' . $e->getMessage());
    }
}

$pluriverseEndpoint = defined('TELARIS_PLURIVERSE_ENDPOINT')
    ? (string)TELARIS_PLURIVERSE_ENDPOINT
    : 'https://www.telaris.ca/api/pluriverse/identity';

$payload = [
    'kind' => 'telaris-instance',
    'hostname' => $hostname,
    'label' => $label,
    'telaris_version' => $telarisVersion,
    'protocol_version' => '1.0',
    'public_key' => base64_encode($publicKey),
    'public_key_fingerprint' => $fingerprint,
    'pluriverse_endpoint' => $pluriverseEndpoint,
];

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
