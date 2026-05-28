<?php
declare(strict_types=1);

/**
 * admin/galaxy-pull-refresh.php
 *
 * POST-only handler for the "Refresh galaxies now" button. Runs the peer-pull
 * orchestrator across every eligible peer, bypassing the backoff gate so
 * the operator action is immediate. Records a flash message with the
 * outcome and redirects back to admin?tab=pluriverse.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/galaxy_pull_cycle.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$summary = federation_galaxy_pull_all_eligible(['force' => true]);

$parts = [sprintf('%d ok', $summary['peers_ok'])];
if ($summary['peers_failed'] > 0) {
    $parts[] = sprintf('%d failed', $summary['peers_failed']);
}
$materialized = 0;
$dropped = 0;
$fossilized = 0;
$errorSamples = [];
foreach ($summary['results'] as $r) {
    $materialized += count($r['materialized'] ?? []);
    $dropped += count($r['retracted']['dropped'] ?? []);
    $fossilized += count($r['withdrawn']['fossilized'] ?? []);
    if (!$r['ok'] && isset($r['error']) && count($errorSamples) < 3) {
        $errorSamples[] = sprintf('%s: %s', $r['host'], $r['error']);
    }
}
if ($materialized > 0) $parts[] = sprintf('+%d mirrored', $materialized);
if ($dropped > 0) $parts[] = sprintf('-%d retracted', $dropped);
if ($fossilized > 0) $parts[] = sprintf('%d fossilized', $fossilized);

$summaryLine = $summary['peers_total'] === 0
    ? t('admin_pluriverse_peers_never', 'never')
    : implode(' · ', $parts);

if ($summary['peers_failed'] > 0) {
    $msg = t('admin_galaxy_pull_refresh_err', 'Galaxy refresh failed:') . ' ' . $summaryLine;
    if ($errorSamples !== []) $msg .= ' (' . implode('; ', $errorSamples) . ')';
    $_SESSION['pluriverse_apply_error'] = $msg;
} else {
    $_SESSION['pluriverse_apply_message'] = t('admin_galaxy_pull_refresh_ok', 'Galaxy refresh completed:') . ' ' . $summaryLine;
}

header('Location: index.php?tab=pluriverse');
exit;
