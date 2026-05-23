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

/**
 * Thrown when the snapshot lock cannot be acquired. Cron catches this and
 * skips the cycle; manual triggers surface it to the operator as "another
 * snapshot is in progress."
 */
final class SnapshotLockHeldException extends RuntimeException {}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function snapshot_filename_for(string $stamp): string {
    return 'snapshot-' . $stamp . '.telaris-backup';
}

function snapshot_full_path(string $filename): string {
    return rtrim(backup_snapshots_dir(), '/') . '/' . basename($filename);
}

/**
 * Acquire a per-instance snapshot lock. Two layers:
 *   1. flock on a per-snapshot-dir lockfile — blocks overlapping processes on
 *      the same host.
 *   2. MySQL GET_LOCK — covers the case where the DB is shared across hosts
 *      (it isn't here, but cheap defence-in-depth).
 * Returns a lock handle for snapshot_release_lock, or throws
 * SnapshotLockHeldException when the lock is unavailable.
 */
function snapshot_acquire_lock(): array {
    $lockPath = rtrim(backup_snapshots_dir(), '/') . '/.snapshot.lock';
    $fp = @fopen($lockPath, 'c');
    if ($fp === false) {
        throw new RuntimeException("Cannot open snapshot lock file: {$lockPath}");
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        throw new SnapshotLockHeldException('Another snapshot is in progress (file lock held).');
    }
    $mysqlKey = 'telaris_snapshot';
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([$mysqlKey]);
        $got = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        flock($fp, LOCK_UN);
        fclose($fp);
        throw $e;
    }
    if ($got !== 1) {
        flock($fp, LOCK_UN);
        fclose($fp);
        throw new SnapshotLockHeldException('Another snapshot is in progress (DB lock held).');
    }
    return ['fp' => $fp, 'mysql_key' => $mysqlKey];
}

function snapshot_release_lock(array $lock): void {
    if (isset($lock['mysql_key'])) {
        try {
            $stmt = getDB()->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lock['mysql_key']]);
        } catch (Throwable $_) {
            // Best-effort.
        }
    }
    if (isset($lock['fp']) && is_resource($lock['fp'])) {
        @flock($lock['fp'], LOCK_UN);
        @fclose($lock['fp']);
    }
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

    // backup_build_dump holds the entire DB + embedded media in memory before
    // gzipping. With the default php-fpm 128M limit this OOMs once the corpus
    // gets non-trivial (observed at ~30MB output). Bump the ceiling for this
    // request — CLI runs already have unlimited memory, so this only affects
    // web/cron paths.
    @ini_set('memory_limit', '512M');
    // Allow long FPM requests but cap them. A 30-minute ceiling is well above
    // observed dump times for any plausible corpus and short enough that a
    // wedged request can't pin an FPM worker indefinitely. CLI ignores this.
    @set_time_limit(1800);

    // Hold the per-instance lock for the full duration. Throws
    // SnapshotLockHeldException if another snapshot is already running, which
    // snapshot_run_if_due treats as "skip this cycle".
    $lock = snapshot_acquire_lock();

    try {
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
        unset($dump);

        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO snapshots (filename, size_bytes, created_by, trigger_type, note, sha256) VALUES (:f, :s, :u, :t, :n, :h)");
        $stmt->execute([
            ':f' => $filename,
            ':s' => $written['size_bytes'],
            ':u' => $userId,
            ':t' => $trigger,
            ':n' => $note !== null && $note !== '' ? $note : null,
            ':h' => $written['sha256'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    } finally {
        snapshot_release_lock($lock);
    }
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
    $stmt = $pdo->prepare("SELECT id, filename, created_at, size_bytes, created_by, trigger_type, note, sha256 FROM snapshots WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function snapshot_delete(int $id): void {
    $row = snapshot_get($id);
    if ($row === null) return;
    $path = snapshot_full_path($row['filename']);
    if (file_exists($path) && !@unlink($path)) {
        $err = error_get_last();
        error_log("snapshot_delete: unlink failed for {$path}: " . ($err['message'] ?? 'unknown'));
    }
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
    // Integrity hash recorded at write time. Legacy snapshots predate the
    // column and carry NULL; in that case verification is skipped rather than
    // refusing to restore. New snapshots always carry a value.
    $expectedSha256 = isset($row['sha256']) && $row['sha256'] !== '' ? (string)$row['sha256'] : null;

    // Admin-presence guard. backup_inspect_file also runs the integrity check
    // when the expected hash is supplied, so a sha256 mismatch throws here
    // before any DB mutation.
    $summary = backup_inspect_file($path, $expectedSha256);
    if (!$confirmNoAdmin && empty($summary['has_admin_user'])) {
        throw new RuntimeException('Snapshot contains no admin user; restoring would lock everyone out. Pass confirmNoAdmin=true to override.');
    }

    // Restore is mutually exclusive with create and with any concurrent restore.
    // Without this lock, a cron snapshot_create and an operator-triggered
    // snapshot_restore could interleave (cron dumps tables mid-wipe) and produce
    // a corrupted snapshot file. Throws SnapshotLockHeldException on contention.
    $lock = snapshot_acquire_lock();

    try {
        // Bump limits to match snapshot_create — restore touches the same volume
        // of data and runs the same path under FPM.
        @ini_set('memory_limit', '512M');
        @set_time_limit(1800);

        $report = backup_restore_from_file($path, [
            'mode' => 'wipe_all',
            'restore_users' => true,
            'restore_media' => true,
            'users_replace_existing' => false,
            'users_replace_password' => false,
            'expected_sha256' => $expectedSha256,
        ]);

        // Delete all snapshots with created_at > this snapshot's timestamp
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, filename FROM snapshots WHERE created_at > :t");
        $stmt->execute([':t' => $row['created_at']]);
        $toDelete = $stmt->fetchAll();
        foreach ($toDelete as $d) {
            $p = snapshot_full_path($d['filename']);
            if (file_exists($p) && !@unlink($p)) {
                $err = error_get_last();
                error_log("snapshot housekeeping: unlink failed for {$p}: " . ($err['message'] ?? 'unknown'));
            }
        }
        if ($toDelete !== []) {
            $ids = array_map(fn($d) => (int)$d['id'], $toDelete);
            $place = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM snapshots WHERE id IN ($place)")->execute($ids);
        }
        $report['snapshots_deleted_after_restore'] = count($toDelete);
        $report['restored_snapshot_id'] = (int)$row['id'];
        return $report;
    } finally {
        snapshot_release_lock($lock);
    }
}

// ---------------------------------------------------------------------------
// Schedule
// ---------------------------------------------------------------------------

function snapshot_get_schedule(): array {
    db_ensure_snapshots_tables();
    $pdo = getDB();
    $row = $pdo->query("SELECT id, enabled, hour, keep_days, last_run_at, updated_at FROM snapshot_schedule WHERE id = 1 LIMIT 1")->fetch();
    if (!$row) {
        $pdo->exec("INSERT IGNORE INTO snapshot_schedule (id) VALUES (1)");
        $row = $pdo->query("SELECT id, enabled, hour, keep_days, last_run_at, updated_at FROM snapshot_schedule WHERE id = 1 LIMIT 1")->fetch();
    }
    $row['enabled'] = (bool)$row['enabled'];
    return $row;
}

function snapshot_set_schedule(bool $enabled, int $hour, int $keepDays): void {
    db_ensure_snapshots_tables();
    if ($hour < 0 || $hour > 23) {
        throw new InvalidArgumentException('Invalid hour (0-23).');
    }
    if ($keepDays < 1) $keepDays = 1;
    $pdo = getDB();
    $pdo->prepare("UPDATE snapshot_schedule SET enabled = :e, hour = :h, keep_days = :k WHERE id = 1")
        ->execute([':e' => $enabled ? 1 : 0, ':h' => $hour, ':k' => $keepDays]);
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
        if (file_exists($p) && !@unlink($p)) {
            $err = error_get_last();
            error_log("snapshot housekeeping: unlink failed for {$p}: " . ($err['message'] ?? 'unknown'));
        }
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
    if (!$sch['enabled']) return null;

    $nowTs = time();
    $lastTs = $sch['last_run_at'] !== null ? strtotime($sch['last_run_at'] . ' UTC') : 0;
    $hour = (int)($sch['hour'] ?? 3);
    $todayUtc = gmdate('Y-m-d', $nowTs);
    $scheduledTs = strtotime($todayUtc . ' ' . sprintf('%02d:00:00', $hour) . ' UTC');
    $lastDay = $lastTs > 0 ? gmdate('Y-m-d', $lastTs) : '';
    $isDue = ($nowTs >= $scheduledTs) && ($lastDay !== $todayUtc);

    if (!$isDue) return null;

    try {
        $newId = snapshot_create(null, 'scheduled', null);
    } catch (SnapshotLockHeldException $e) {
        error_log('snapshot_run_if_due: ' . $e->getMessage() . '; skipping this cycle');
        return null;
    }
    snapshot_trim_scheduled((int)$sch['keep_days']);

    $pdo = getDB();
    $pdo->exec("UPDATE snapshot_schedule SET last_run_at = CURRENT_TIMESTAMP WHERE id = 1");
    db_audit_log(
        action: 'snapshot.create.scheduled',
        actorUserId: null,
        targetType: 'snapshot',
        targetId: (string)$newId,
        details: ['hour' => $hour, 'keep_days' => (int)$sch['keep_days']],
    );
    return $newId;
}
