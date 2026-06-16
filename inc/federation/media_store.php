<?php
declare(strict_types=1);

/**
 * Stage 5c-media: content-addressable media store.
 *
 * A published galaxy's media travels by content hash, not by the origin's local
 * upload URL. At publish time each local-upload media file is hashed (sha256),
 * copied once into UPLOAD_DIR/federation-media/<sha256>, and recorded in
 * media_blobs; the envelope then carries the hash instead of a path. A
 * subscribing peer fetches the bytes from GET /media/{sha256} and re-hashes to
 * verify, so a relay or a compromised peer cannot substitute media: the hash is
 * the integrity check and an unguessable 256-bit capability.
 *
 * Dedupe is free (identical files share a hash). ref_count is coarse
 * bookkeeping for a later GC sweep; precise reference counting across
 * publish/retract is deferred (Stage 5 design § What this stage does not solve).
 *
 * Spec: Stage 5 galaxy publish design (5c-media); v10 § Standards and crypto.
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/backup.php';

/**
 * The federation media directory, created if missing. 02775 = group-writable +
 * setgid so blobs written by www-data stay readable by an admin shell user in
 * the same group, matching the snapshots directory convention.
 */
function federation_media_dir(): string {
    if (!defined('UPLOAD_DIR')) {
        throw new RuntimeException('federation_media_dir: UPLOAD_DIR is not defined');
    }
    $dir = rtrim(UPLOAD_DIR, '/') . '/federation-media';
    if (!is_dir($dir)) {
        @mkdir($dir, 02775, true);
    }
    return $dir;
}

/** True if the string is a lowercase hex sha256 (64 chars). */
function federation_media_is_sha256(string $s): bool {
    return (bool)preg_match('/^[a-f0-9]{64}$/', $s);
}

/**
 * Content-address a local-upload media URL: resolve it, hash it, copy it once
 * into the federation media store, and record it in media_blobs. Idempotent on
 * the hash. Returns the content reference, or null when the URL is not a
 * resolvable local upload (external URLs and embeds pass through untouched at
 * the call site).
 *
 * @return array{sha256:string, mime:string, size:int}|null
 */
function federation_media_register_upload(string $url): ?array {
    if (!str_starts_with($url, 'uploads/')) return null;
    $absPath = backup_resolve_upload_path($url);
    if ($absPath === null || !is_file($absPath)) return null;

    $bytes = @file_get_contents($absPath);
    if ($bytes === false) return null;

    $sha256 = hash('sha256', $bytes);
    $size = strlen($bytes);
    $mime = federation_media_detect_mime($absPath);

    $dest = federation_media_dir() . '/' . $sha256;
    if (!is_file($dest)) {
        // Write to a temp sibling then rename, so a concurrent reader never sees
        // a half-written blob at the content-addressed path.
        $tmp = $dest . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $bytes) === false) {
            error_log('federation_media_register_upload: cannot write ' . $tmp);
            return null;
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            // A racing publish may have created it; tolerate that, else fail.
            if (!is_file($dest)) {
                error_log('federation_media_register_upload: cannot place ' . $dest);
                return null;
            }
        }
    }

    federation_media_record($sha256, $dest, $mime, $size);
    return ['sha256' => $sha256, 'mime' => $mime, 'size' => $size];
}

/**
 * Upsert the media_blobs row. New blobs start at ref_count 1; on re-publish the
 * existing row's metadata is refreshed and ref_count left as is (a precise
 * cross-galaxy count + GC sweep is deferred).
 */
function federation_media_record(string $sha256, string $storagePath, string $mime, int $size): void {
    db_ensure_media_blobs_table();
    $stmt = getDB()->prepare("
        INSERT INTO media_blobs (sha256, storage_path, mime, size_bytes, ref_count)
        VALUES (:sha, :path, :mime, :size, 1)
        ON CONFLICT (sha256) DO UPDATE SET
            storage_path = EXCLUDED.storage_path,
            mime = EXCLUDED.mime,
            size_bytes = EXCLUDED.size_bytes
    ");
    $stmt->execute([':sha' => $sha256, ':path' => $storagePath, ':mime' => $mime, ':size' => $size]);
}

/**
 * Look up a stored blob by hash. Returns null on a malformed hash, an unknown
 * hash, or a row whose backing file has gone missing.
 *
 * @return array{sha256:string, storage_path:string, mime:string, size_bytes:int}|null
 */
function federation_media_lookup(string $sha256): ?array {
    if (!federation_media_is_sha256($sha256)) return null;
    db_ensure_media_blobs_table();
    $stmt = getDB()->prepare("SELECT sha256, storage_path, mime, size_bytes FROM media_blobs WHERE sha256 = :sha LIMIT 1");
    $stmt->execute([':sha' => $sha256]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;
    if (!is_file((string)$row['storage_path'])) return null;
    return [
        'sha256' => (string)$row['sha256'],
        'storage_path' => (string)$row['storage_path'],
        'mime' => (string)($row['mime'] ?? 'application/octet-stream'),
        'size_bytes' => (int)$row['size_bytes'],
    ];
}

/**
 * Stats for the federation media store, driving the 5f admin "Media store"
 * subsection. Reports both the database-recorded total (sum of media_blobs
 * size_bytes) and the on-disk total under federation-media/, so an operator
 * can spot drift (orphaned blobs the deferred GC sweep will collect, or
 * blobs registered but missing from disk).
 *
 * @return array{blob_count:int, total_size_bytes:int, store_dir:string, disk_blob_count:int, disk_total_bytes:int}
 */
function federation_media_store_stats(): array {
    db_ensure_media_blobs_table();
    $row = getDB()->query("SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS total FROM media_blobs")
        ->fetch(PDO::FETCH_ASSOC);
    $blobCount = (int)$row['n'];
    $totalBytes = (int)$row['total'];

    $dir = federation_media_dir();
    $diskCount = 0;
    $diskBytes = 0;
    // Single pass over the directory: count + sum sizes, ignore the
    // *.tmp.* writers from federation_media_register_upload mid-rename.
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if (str_contains($name, '.tmp.')) continue;
        $path = $dir . '/' . $name;
        if (!is_file($path)) continue;
        $diskCount++;
        $sz = @filesize($path);
        if ($sz !== false) $diskBytes += (int)$sz;
    }
    return [
        'blob_count' => $blobCount,
        'total_size_bytes' => $totalBytes,
        'store_dir' => $dir,
        'disk_blob_count' => $diskCount,
        'disk_total_bytes' => $diskBytes,
    ];
}

/** Best-effort MIME detection; falls back to application/octet-stream. */
function federation_media_detect_mime(string $absPath): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $absPath);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') return $mime;
        }
    }
    return 'application/octet-stream';
}
