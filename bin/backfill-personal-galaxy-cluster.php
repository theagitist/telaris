<?php
declare(strict_types=1);

/*
 * bin/backfill-personal-galaxy-cluster.php
 *
 * Self-heal sweep for editor self-enrollment: ensure every editor-created
 * galaxy is a member of this installation's per-installation cluster (named
 * after the subdomain, e.g. "[GRSJ306]"). Auto-enrolment groups each new
 * personal galaxy into that cluster, but a best-effort failure under a rush of
 * simultaneous signups could leave some galaxies outside it. This adds the
 * stragglers back.
 *
 * Generic and installation-agnostic: the cluster name is derived from this
 * installation's own hostname (enroll_installation_cluster_name), so it does
 * the right thing on every instance. No-op on installations that don't use the
 * per-installation cluster (it never creates an empty cluster).
 *
 * Idempotent and safe to re-run. Run from the instance root:
 *   php bin/backfill-personal-galaxy-cluster.php [--dry-run]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/enroll-actions.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = getDB();
db_ensure_constellations_type_and_cluster_members();

$clusterName = enroll_installation_cluster_name();
if ($clusterName === null) {
    echo "No trusted hostname configured (SITE_BASE_URL / TELARIS_HOSTNAME); cannot determine the cluster. Nothing to do.\n";
    exit(0);
}

// Existing cluster id, if the cluster already exists (we never create an empty
// one: only proceed to create + fill it when there are galaxies to add).
$find = $pdo->prepare("SELECT id FROM constellations WHERE name = :n AND type = 'cluster' ORDER BY id ASC LIMIT 1");
$find->execute([':n' => $clusterName]);
$existingId = $find->fetchColumn();
$existingId = $existingId === false ? null : (int)$existingId;

// Editor-created galaxies not already in the cluster. When the cluster does not
// exist yet, every editor-created galaxy is a candidate.
$sql = "
    SELECT g.id, g.name
    FROM constellations g
    WHERE g.type = 'galaxy'
      AND g.created_by IS NOT NULL
      AND g.created_by IN (SELECT id FROM users WHERE type = 1)
";
$params = [];
if ($existingId !== null) {
    $sql .= " AND g.id NOT IN (SELECT member_id FROM galaxy_cluster_members WHERE cluster_id = :cid)";
    $params[':cid'] = $existingId;
}
$sql .= " ORDER BY g.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$missing = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$missing) {
    echo "Cluster {$clusterName}" . ($existingId !== null ? " (#{$existingId})" : " (not yet created)")
        . ": every editor-created galaxy is already a member. Nothing to backfill.\n";
    exit(0);
}

echo "Cluster {$clusterName}: " . count($missing) . " editor galaxy(ies) to add"
    . ($dryRun ? "  (DRY RUN)\n" : "\n");

if ($dryRun) {
    foreach ($missing as $g) {
        printf("  would add: galaxy #%d  %s\n", (int)$g['id'], (string)$g['name']);
    }
    echo "Done (dry run).\n";
    exit(0);
}

$clusterId = db_find_or_create_named_cluster($clusterName);
$added = 0;
foreach ($missing as $g) {
    try {
        db_add_cluster_member($clusterId, (int)$g['id']);
        $added++;
        printf("  added: galaxy #%d  %s\n", (int)$g['id'], (string)$g['name']);
    } catch (Throwable $e) {
        printf("  FAILED galaxy #%d: %s\n", (int)$g['id'], $e->getMessage());
    }
}
echo "Done. Added {$added} galaxy(ies) to {$clusterName} (#{$clusterId}).\n";
