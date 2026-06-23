<?php
declare(strict_types=1);

/**
 * Per-instance disk-quota guard (self-service instance tier).
 *
 * Containerized instances run with a quota set by the Orrery as the
 * QUOTA_BYTES constant (rendered from the TELARIS_QUOTA_BYTES env var; 0 =
 * unlimited, the default for dev checkouts and any non-containerized install).
 * The Orrery measures the whole instance tree out-of-band and records usage for
 * the operator; this app-side guard is the live enforcement: it refuses NEW
 * uploads once the upload directory would exceed the quota, while leaving all
 * existing content readable and editable.
 */

/** Recursive byte size of a directory (apparent file sizes). */
function quota_dir_size_bytes(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $total = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $total += $f->getSize();
        }
    }
    return $total;
}

/**
 * Bytes currently used by the instance's uploaded media. We measure UPLOAD_DIR
 * (where every editor-uploaded image/video/audio/pdf/icon lands) against the
 * quota. This deliberately undercounts the full instance tree (snapshots, hg
 * content, logs) the Orrery sees, so the block triggers a little later than the
 * hard total; the Orrery's over-quota flag + operator action cover gross breach.
 * ponytail: full recompute per call; cache or a running DB total only if upload
 * dirs ever get large enough for the walk to bite.
 */
function quota_usage_bytes(): int
{
    return defined('UPLOAD_DIR') ? quota_dir_size_bytes((string)UPLOAD_DIR) : 0;
}

/** Configured quota in bytes (0 = unlimited). */
function quota_limit_bytes(): int
{
    return defined('QUOTA_BYTES') ? (int)QUOTA_BYTES : 0;
}

/**
 * True if accepting $incomingBytes more upload data would exceed the quota.
 * Always false when the quota is unlimited (0), so non-containerized installs
 * and dev checkouts are never gated.
 */
function quota_would_exceed(int $incomingBytes): bool
{
    $limit = quota_limit_bytes();
    if ($limit <= 0) {
        return false;
    }
    return (quota_usage_bytes() + max(0, $incomingBytes)) > $limit;
}
