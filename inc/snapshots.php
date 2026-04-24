<?php
declare(strict_types=1);

/**
 * Snapshot layer: thin wrapper over inc/backup.php that stores backups on
 * disk in SNAPSHOTS_DIR and tracks them in the snapshots DB table.
 *
 * Snapshot semantics:
 *  - A snapshot is a full backup (all galaxies + users + embedded media).
 *  - Restoring a snapshot wipes everything and replays it (mode=wipe_all).
 *  - After a successful restore, all snapshots with created_at > the restored
 *    snapshot's created_at are deleted (rows + files), enforcing a linear timeline.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup.php';

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function snapshot_filename_for(string $stamp): string {
    return 'snapshot-' . $stamp . '.telaris-backup';
}

function snapshot_full_path(string $filename): string {
    return rtrim(backup_snapshots_dir(), '/') . '/' . basename($filename);
}

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

/**
 * Create a snapshot of the current system. Returns the new snapshot row id.
 *
 * @param ?string $note Optional human-readable note shown in the UI.
 * @param string $trigger 'manual' | 'scheduled'
 * @param ?string $userId Admin user id who triggered it (null for cron/system).
 */
function snapshot_create(?string $note = null, string $trigger = 'manual', ?string $userId = null): int {
    db_ensure_snapshots_tables();

    $stamp = gmdate('Y-m-d\TH-i-s');
    // Avoid collision if two snapshots are taken in the same second
    $base = snapshot_filename_for($stamp);
    $filename = $base;
    $i = 2;
    while (file_exists(snapshot_full_path($filename))) {
        $filename = preg_replace('/\.telaris-backup$/', '-' . $i . '.telaris-backup', $base);
        $i++;
    }

    $dump = backup_build_dump([
        'include_galaxies' => true,
        'include_users' => true,
        'galaxy_ids' => [],
        'media_mode' => 'embedded',
    ]);
    $path = snapshot_full_path($filename);
    $written = backup_write_to_file($dump, $path);

    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO snapshots (filename, size_bytes, created_by, trigger_type, note) VALUES (:f, :s, :u, :t, :n)");
    $stmt->execute([
        ':f' => $filename,
        ':s' => $written['size_bytes'],
        ':u' => $userId,
        ':t' => $trigger,
        ':n' => $note !== null && $note !== '' ? $note : null,
    ]);
    return (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// List / get / delete
// ---------------------------------------------------------------------------

/**
 * List all snapshots, newest first. Each entry includes 'creator_email' if known.
 */
function snapshot_list(): array {
    db_ensure_snapshots_tables();
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT s.id, s.filename, s.created_at, s.size_bytes, s.created_by, s.trigger_type, s.note,
               u.email AS creator_email
        FROM snapshots s
        LEFT JOIN users u ON u.id = s.created_by
        ORDER BY s.created_at DESC, s.id DESC
    ");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['file_exists'] = file_exists(snapshot_full_path($r['filename']));
    }
    unset($r);
    return $rows;
}

function snapshot_get(int $id): ?array {
    db_ensure_snapshots_tables();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, filename, created_at, size_bytes, created_by, trigger_type, note FROM snapshots WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function snapshot_delete(int $id): void {
    $row = snapshot_get($id);
    if ($row === null) return;
    $path = snapshot_full_path($row['filename']);
    if (file_exists($path)) @unlink($path);
    $pdo = getDB();
    $pdo->prepare("DELETE FROM snapshots WHERE id = :id")->execute([':id' => $id]);
}

// ---------------------------------------------------------------------------
// Restore
// ---------------------------------------------------------------------------

/**
 * Restore a snapshot. This wipes the system and replays the snapshot, then
 * deletes all snapshots with created_at > the restored snapshot's created_at.
 *
 * Returns the restore report from backup_restore_from_file with extra fields.
 *
 * @throws RuntimeException on missing file or no admin user in snapshot
 *   (unless $confirmNoAdmin is true).
 */
function snapshot_restore(int $id, bool $confirmNoAdmin = false): array {
    $row = snapshot_get($id);
    if ($row === null) {
        throw new RuntimeException('Snapshot not found.');
    }
    $path = snapshot_full_path($row['filename']);
    if (!file_exists($path)) {
        throw new RuntimeException('Snapshot file is missing on disk.');
    }
    // Admin-presence guard
    $summary = backup_inspect_file($path);
    if (!$confirmNoAdmin && empty($summary['has_admin_user'])) {
        throw new RuntimeException('Snapshot contains no admin user; restoring would lock everyone out. Pass confirmNoAdmin=true to override.');
    }

    $report = backup_restore_from_file($path, [
        'mode' => 'wipe_all',
        'restore_users' => true,
        'restore_media' => true,
        'users_replace_existing' => false,
        'users_replace_password' => false,
    ]);

    // Delete all snapshots with created_at > this snapshot's timestamp
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, filename FROM snapshots WHERE created_at > :t");
    $stmt->execute([':t' => $row['created_at']]);
    $toDelete = $stmt->fetchAll();
    foreach ($toDelete as $d) {
        $p = snapshot_full_path($d['filename']);
        if (file_exists($p)) @unlink($p);
    }
    if ($toDelete !== []) {
        $ids = array_map(fn($d) => (int)$d['id'], $toDelete);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM snapshots WHERE id IN ($place)")->execute($ids);
    }
    $report['snapshots_deleted_after_restore'] = count($toDelete);
    $report['restored_snapshot_id'] = (int)$row['id'];
    return $report;
}

// ---------------------------------------------------------------------------
// Schedule
// ---------------------------------------------------------------------------

function snapshot_get_schedule(): array {
    db_ensure_snapshots_tables();
    $pdo = getDB();
    $row = $pdo->query("SELECT id, frequency, hour, day_of_week, keep_days, last_run_at, updated_at FROM snapshot_schedule WHERE id = 1 LIMIT 1")->fetch();
    if (!$row) {
        // Defensive seed
        $pdo->exec("INSERT IGNORE INTO snapshot_schedule (id, frequency) VALUES (1, 'off')");
        $row = $pdo->query("SELECT id, frequency, hour, day_of_week, keep_days, last_run_at, updated_at FROM snapshot_schedule WHERE id = 1 LIMIT 1")->fetch();
    }
    return $row;
}

function snapshot_set_schedule(string $frequency, ?int $hour, ?int $dayOfWeek, int $keepDays): void {
    db_ensure_snapshots_tables();
    $valid = ['off', 'daily', 'weekly'];
    if (!in_array($frequency, $valid, true)) {
        throw new InvalidArgumentException('Invalid frequency.');
    }
    if ($hour !== null && ($hour < 0 || $hour > 23)) {
        throw new InvalidArgumentException('Invalid hour (0-23).');
    }
    if ($dayOfWeek !== null && ($dayOfWeek < 0 || $dayOfWeek > 6)) {
        throw new InvalidArgumentException('Invalid day of week (0=Sunday, 6=Saturday).');
    }
    if ($keepDays < 1) $keepDays = 1;
    $pdo = getDB();
    $pdo->prepare("UPDATE snapshot_schedule SET frequency = :f, hour = :h, day_of_week = :d, keep_days = :k WHERE id = 1")
        ->execute([':f' => $frequency, ':h' => $hour, ':d' => $dayOfWeek, ':k' => $keepDays]);
}

/**
 * Delete 'scheduled' snapshots older than $keepDays days. Manual snapshots are kept forever.
 * Returns count deleted.
 */
function snapshot_trim_scheduled(int $keepDays): int {
    db_ensure_snapshots_tables();
    if ($keepDays < 1) $keepDays = 1;
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, filename FROM snapshots
        WHERE trigger_type = 'scheduled'
          AND created_at < (NOW() - INTERVAL :d DAY)
    ");
    $stmt->bindValue(':d', $keepDays, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if ($rows === []) return 0;
    foreach ($rows as $r) {
        $p = snapshot_full_path($r['filename']);
        if (file_exists($p)) @unlink($p);
    }
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM snapshots WHERE id IN ($place)")->execute($ids);
    return count($rows);
}

/**
 * Decide whether a scheduled snapshot is due, and if so create it and trim.
 * Returns the new snapshot id, or null if not due (or schedule is off).
 */
function snapshot_run_if_due(): ?int {
    $sch = snapshot_get_schedule();
    if ($sch['frequency'] === 'off') return null;

    $nowTs = time();
    $lastTs = $sch['last_run_at'] !== null ? strtotime($sch['last_run_at'] . ' UTC') : 0;
    $isDue = false;

    switch ($sch['frequency']) {
        case 'daily': {
            // Due if at-or-past today's scheduled hour AND last_run wasn't already today (UTC)
            $hour = $sch['hour'] ?? 3;
            $todayUtc = gmdate('Y-m-d', $nowTs);
            $scheduledTs = strtotime($todayUtc . ' ' . sprintf('%02d:00:00', $hour) . ' UTC');
            $lastDay = $lastTs > 0 ? gmdate('Y-m-d', $lastTs) : '';
            $isDue = ($nowTs >= $scheduledTs) && ($lastDay !== $todayUtc);
            break;
        }
        case 'weekly': {
            $dow = $sch['day_of_week'] ?? 0;
            $hour = $sch['hour'] ?? 3;
            $todayUtcDow = (int)gmdate('w', $nowTs); // 0=Sun
            $todayUtc = gmdate('Y-m-d', $nowTs);
            $scheduledTs = strtotime($todayUtc . ' ' . sprintf('%02d:00:00', $hour) . ' UTC');
            $lastDay = $lastTs > 0 ? gmdate('Y-m-d', $lastTs) : '';
            $isDue = ($todayUtcDow === $dow) && ($nowTs >= $scheduledTs) && ($lastDay !== $todayUtc);
            break;
        }
    }

    if (!$isDue) return null;

    $newId = snapshot_create(null, 'scheduled', null);
    snapshot_trim_scheduled((int)$sch['keep_days']);

    $pdo = getDB();
    $pdo->exec("UPDATE snapshot_schedule SET last_run_at = CURRENT_TIMESTAMP WHERE id = 1");
    return $newId;
}
