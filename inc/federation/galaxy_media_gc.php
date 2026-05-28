<?php
declare(strict_types=1);

/**
 * Stage 5f-vii: federation media garbage-collection sweep.
 *
 * The federation media store is content-addressed and deduped across mirrors,
 * so precise refcounting on publish/retract is the deferred posture (see
 * inc/federation/media_store.php). An orphaned blob is a leak — not a
 * correctness problem — so this sweep is an operator action, not a hot path.
 *
 * What "live" means: any node row whose `image_url` / `icon_url` /
 * `audio_url` / `video_url` / `pdf_url` field starts with
 * `uploads/federation-media/` is the canonical reference. Mirrors point at
 * those after 5d-iii materialization; locally-authored uploads that the
 * publish path content-addressed also do (the rewrite in
 * federation_galaxy_finalize_media stores the same shape). The set of all
 * such sha256s is the live set.
 *
 * Concurrency: a galaxy-pull cycle that is currently fetching a new blob
 * could register media_blobs + write the file *after* this sweep has built
 * the live set, but *before* it walks the disk. To avoid clobbering a
 * legitimate in-flight write, the sweep refuses to delete any disk blob
 * whose mtime is within MIN_AGE_SECONDS of now (default 1 hour). The mid-
 * rename `*.tmp.*` files are also skipped (their final filename has no dot).
 *
 * Order:
 *   1. Scan the federation-media directory, note (path, sha256, size, mtime).
 *   2. AFTER the disk scan, collect the live set from nodes. Building the
 *      live set last means newly-fetched blobs registered between step 1
 *      and step 2 are seen as live, not as orphans.
 *   3. For each disk file: skip if young, skip if sha256 in live set,
 *      else delete.
 *   4. For each media_blobs row whose sha256 isn't on disk anymore AND
 *      isn't in the live set, drop the row.
 *
 * Spec: Stage 5 galaxy publish design (5f); v10 § Standards and crypto.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/media_store.php';
require_once __DIR__ . '/galaxy_mirror.php';  // FEDERATION_MIRROR_MEDIA_URL_PREFIX

const FEDERATION_MEDIA_GC_DEFAULT_MIN_AGE_SECONDS = 3600;

/**
 * Collect the live set of federation-media sha256s referenced by any node.
 * UNION queries the five asset columns. Filters by the canonical prefix so
 * external URLs / external embeds / uploads pointing elsewhere are ignored.
 *
 * @return array<string,true> sha256 -> true (set semantics, keys only).
 */
function federation_media_gc_collect_live_sha256s(): array {
    $pdo = getDB();
    $cols = ['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'];
    $clauses = [];
    foreach ($cols as $c) {
        $clauses[] = "SELECT {$c} AS url FROM nodes WHERE {$c} LIKE 'uploads/federation-media/%'";
    }
    $sql = implode(' UNION ', $clauses);
    $live = [];
    foreach ($pdo->query($sql) as $r) {
        $url = (string)$r['url'];
        $sha256 = substr($url, strlen(FEDERATION_MIRROR_MEDIA_URL_PREFIX));
        if (federation_media_is_sha256($sha256)) {
            $live[$sha256] = true;
        }
    }
    return $live;
}

/**
 * Scan UPLOAD_DIR/federation-media/ and return one record per content-addressed
 * blob currently on disk. Mid-rename `*.tmp.*` files are skipped. Filenames
 * that are not a clean sha256 (somehow ended up there) are skipped too;
 * the sweep refuses to touch what it cannot identify.
 *
 * @return list<array{sha256:string, path:string, size:int, mtime:int}>
 */
function federation_media_gc_scan_disk(): array {
    $dir = federation_media_dir();
    $out = [];
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if (str_contains($name, '.tmp.')) continue;
        if (!federation_media_is_sha256($name)) continue;
        $path = $dir . '/' . $name;
        if (!is_file($path)) continue;
        $out[] = [
            'sha256' => $name,
            'path' => $path,
            'size' => (int)(@filesize($path) ?: 0),
            'mtime' => (int)(@filemtime($path) ?: 0),
        ];
    }
    return $out;
}

/**
 * Drive the full sweep. With $dryRun=true returns the same shape it would on
 * a real run but without unlinking files or deleting rows: useful from the
 * binary's `--dry-run` flag and the admin pre-sweep preview.
 *
 * @return array{
 *   dry_run:bool,
 *   live_count:int,
 *   disk_scanned:int,
 *   too_young:int,
 *   disk_orphans:list<array{sha256:string, size:int, mtime:int}>,
 *   disk_orphans_freed_bytes:int,
 *   db_orphans:list<string>,
 *   min_age_seconds:int,
 *   error:?string
 * }
 */
function federation_media_gc_sweep(bool $dryRun = false, int $minAgeSeconds = FEDERATION_MEDIA_GC_DEFAULT_MIN_AGE_SECONDS): array {
    db_ensure_media_blobs_table();
    $report = [
        'dry_run' => $dryRun,
        'live_count' => 0,
        'disk_scanned' => 0,
        'too_young' => 0,
        'disk_orphans' => [],
        'disk_orphans_freed_bytes' => 0,
        'db_orphans' => [],
        'min_age_seconds' => $minAgeSeconds,
        'error' => null,
    ];

    // Order matters: disk scan FIRST, then live set. A new blob registered
    // between these two reads is captured by the live set and protected.
    $files = federation_media_gc_scan_disk();
    $report['disk_scanned'] = count($files);

    $live = federation_media_gc_collect_live_sha256s();
    $report['live_count'] = count($live);

    $cutoff = time() - $minAgeSeconds;
    $diskHashes = [];
    foreach ($files as $f) {
        $diskHashes[$f['sha256']] = true;
        if ($f['mtime'] > $cutoff) {
            $report['too_young']++;
            continue;
        }
        if (isset($live[$f['sha256']])) {
            continue;
        }
        $report['disk_orphans'][] = ['sha256' => $f['sha256'], 'size' => $f['size'], 'mtime' => $f['mtime']];
        $report['disk_orphans_freed_bytes'] += $f['size'];
        if (!$dryRun) {
            if (!@unlink($f['path'])) {
                error_log('federation_media_gc_sweep: unlink failed for ' . $f['path']);
            }
        }
    }

    // media_blobs rows whose backing file is gone AND who are not in the live
    // set are also orphans (a previous sweep removed the file, or the row was
    // recorded but the file write never landed). Live-set rows whose file is
    // missing are surfaced too; they will re-fetch on the next pull.
    $pdo = getDB();
    $rows = $pdo->query("SELECT sha256 FROM media_blobs")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $sha256) {
        $sha256 = (string)$sha256;
        if (isset($diskHashes[$sha256])) continue;
        if (isset($live[$sha256])) continue;
        $report['db_orphans'][] = $sha256;
        if (!$dryRun) {
            $pdo->prepare("DELETE FROM media_blobs WHERE sha256 = :s")->execute([':s' => $sha256]);
        }
    }

    return $report;
}
