<?php
declare(strict_types=1);

/**
 * admin/pluriverse-refresh.php
 *
 * POST-only handler for the "Refresh now" button on the admin Pluriverse
 * tab. Runs all three pullers (peers, blacklist, key-events) in series
 * and records a flash message with the outcome.
 *
 * Redirects back to admin?tab=pluriverse.
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: setup.php');
    exit;
}

require_once __DIR__ . '/../utils/auth.php';
requireAdminLogin();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/federation/pluriverse_pull.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php?tab=pluriverse');
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pluriverse_apply_error'] = t('admin_msg_csrf_invalid', 'Invalid or expired security token. Please reload the admin page and try again.');
    header('Location: index.php?tab=pluriverse');
    exit;
}

$results = [
    'peers' => pluriverse_pull_peers(),
    'blacklist' => pluriverse_pull_blacklist(),
    'key-events' => pluriverse_pull_key_events(),
];

$summaryParts = [];
$anyFail = false;
foreach ($results as $name => $r) {
    if (!$r['ok']) {
        $anyFail = true;
        $summaryParts[] = sprintf('%s: FAIL (%s)', $name, $r['error'] ?? 'unknown error');
    } elseif ($r['not_modified']) {
        $summaryParts[] = sprintf('%s: 304', $name);
    } else {
        $summaryParts[] = sprintf(
            '%s: %d processed (+%d new)',
            $name, $r['rows_processed'], $r['rows_inserted']
        );
    }
}

if ($anyFail) {
    $_SESSION['pluriverse_apply_error'] = t('admin_pluriverse_refresh_err', 'Pluriverse refresh failed:') . ' ' . implode(' · ', $summaryParts);
} else {
    $_SESSION['pluriverse_apply_message'] = t('admin_pluriverse_refresh_ok', 'Pluriverse refreshed:') . ' ' . implode(' · ', $summaryParts);
}

header('Location: index.php?tab=pluriverse');
exit;
