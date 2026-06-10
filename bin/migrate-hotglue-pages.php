<?php
declare(strict_types=1);

/*
 * bin/migrate-hotglue-pages.php
 *
 * One-time, idempotent backfill of the hotglue_pages registry for instances
 * that ran hotglue before standalone pages existed. It:
 *   (A) ensures a hotglue_pages row for every wormhole currently in hotglue
 *       mode, linked to that wormhole (assigned); and
 *   (B) ensures an unassigned row for every leftover page directory under
 *       hg/content/ that no wormhole points at.
 * Migrated rows carry owner_user_id = NULL: assigned ones are still editable
 * via the galaxy seat exactly as before; unassigned leftovers are admin-visible
 * until an admin claims or deletes them.
 *
 * Safe to re-run (rows are matched by slug and never duplicated) and a no-op on
 * instances with no hotglue content. Run as www-data so it can read hg/content:
 *   sudo -u www-data php bin/migrate-hotglue-pages.php [--dry-run]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';

$dryRun = in_array('--dry-run', $argv, true);
$contentDir = realpath(__DIR__ . '/../hg/content');
// Hotglue's own non-page entries; never treat these as editor pages.
$reserved = ['shared', 'head', 'start'];

$pdo = getDB();
db_ensure_hotglue_pages_table();
db_ensure_nodes_hotglue_columns();

$createdAssigned = 0;
$linkedExisting  = 0;
$createdOrphan   = 0;
$skipped         = 0;
$seen            = [];   // slugs handled this run (so Pass B never re-reports Pass A, incl. dry-run)

/** Insert a hotglue_pages row with a fixed slug (migration keeps the on-disk name). */
$insertRow = function (string $slug, string $title, ?int $nodeId) use ($pdo, $dryRun): void {
    if ($dryRun) { return; }
    $pdo->prepare("INSERT INTO hotglue_pages (slug, title, owner_user_id, node_id) VALUES (:s, :t, NULL, :n)")
        ->execute([':s' => $slug, ':t' => $title, ':n' => $nodeId]);
};

// --- Pass A: wormholes currently in hotglue mode -> assigned rows ----------
$nodes = $pdo->query("SELECT id, name, hotglue_page FROM nodes WHERE media_mode = 'hotglue'")->fetchAll();
foreach ($nodes as $n) {
    $nodeId = (int)$n['id'];
    $slug = (is_string($n['hotglue_page']) && trim($n['hotglue_page']) !== '') ? trim($n['hotglue_page']) : ('node-' . $nodeId);
    $title = trim((string)($n['name'] ?? '')) !== '' ? (string)$n['name'] : $slug;
    $seen[$slug] = true;
    $existing = db_hotglue_page_get_by_slug($slug);
    if ($existing === null) {
        $insertRow($slug, $title, $nodeId);
        $createdAssigned++;
        echo "assigned  $slug -> node $nodeId ($title)\n";
    } elseif ((int)($existing['node_id'] ?? 0) !== $nodeId) {
        if (!$dryRun) {
            $pdo->prepare("UPDATE hotglue_pages SET node_id = :n WHERE id = :id")->execute([':n' => $nodeId, ':id' => (int)$existing['id']]);
        }
        $linkedExisting++;
        echo "linked    $slug -> node $nodeId\n";
    } else {
        $skipped++;
    }
}

// --- Pass B: leftover page directories with no wormhole -> unassigned rows --
if ($contentDir !== false && is_dir($contentDir)) {
    foreach ((scandir($contentDir) ?: []) as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $reserved, true)) { continue; }
        if (!is_dir($contentDir . '/' . $entry)) { continue; }
        $slug = $entry;
        if (isset($seen[$slug]) || db_hotglue_page_get_by_slug($slug) !== null) { $skipped++; continue; }
        $seen[$slug] = true;
        // Derive a title: a node-<id> dir borrows the wormhole name if it exists.
        $title = $slug;
        if (preg_match('/^node-([0-9]+)$/', $slug, $m) === 1) {
            $node = db_get_node_by_id((int)$m[1]);
            if ($node && trim((string)($node['name'] ?? '')) !== '') { $title = (string)$node['name']; }
        }
        $insertRow($slug, $title, null);
        $createdOrphan++;
        echo "orphan    $slug (unassigned, \"$title\")\n";
    }
}

echo "\n";
echo ($dryRun ? "[dry-run] " : "") . "done: assigned=$createdAssigned linked=$linkedExisting orphan=$createdOrphan skipped=$skipped\n";
