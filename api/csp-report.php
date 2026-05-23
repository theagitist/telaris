<?php
declare(strict_types=1);

/**
 * CSP violation report sink.
 *
 * Receives application/csp-report POSTs from Content-Security-Policy-Report-Only
 * headers on admin/edit (where the report-only policy is stricter than the
 * enforced policy — see inc/main-view.php's notes on style-src and frame-ancestors).
 *
 * The body is logged verbatim to PHP's error_log so the operator can audit
 * what would break before flipping the report-only policy into enforcing
 * mode. No DB writes, no auth — the endpoint is intentionally small and
 * idempotent. Rate-limit / size-cap to keep a misbehaving client from
 * flooding the log.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/api-error.php';

http_response_code(204);
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}
$body = stream_get_contents(fopen('php://input', 'r'), 16384);
if (!is_string($body) || $body === '') {
    return;
}
$body = mb_substr($body, 0, 8192);
$decoded = json_decode($body, true);
$report = is_array($decoded) ? ($decoded['csp-report'] ?? $decoded) : $body;
$summary = is_array($report)
    ? json_encode([
        'document_uri' => $report['document-uri'] ?? null,
        'violated_directive' => $report['violated-directive'] ?? null,
        'blocked_uri' => $report['blocked-uri'] ?? null,
        'source_file' => $report['source-file'] ?? null,
        'line_number' => $report['line-number'] ?? null,
        'sample' => isset($report['script-sample']) ? mb_substr((string)$report['script-sample'], 0, 200) : null,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : (string)$report;
error_log('csp-report: ' . $summary);
