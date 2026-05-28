<?php
declare(strict_types=1);

/**
 * Stage 5d-ii / 5d-iii: galaxy mirror materialization.
 *
 * Takes a fetched galaxy envelope, verifies it against the origin's key,
 * resolves every content-addressed media blob it references, and materializes
 * the whole thing as a read-only local mirror constellation. Mirrors live in
 * `constellations`/`nodes` like any galaxy, distinguished by provenance
 * (mirrored_from_peer_id + import_source + read_only + source_attribution), so
 * the visitor surface (5e) and multigalaxy pipeline render them with no extra
 * special-casing beyond honouring read_only. The federation predicate "only
 * authored galaxies flow" is enforced upstream by the publish path filtering on
 * import_source IS NULL / mirrored_from_peer_id IS NULL.
 *
 * A mirror is fully replaced on every accepted envelope: the envelope is a
 * complete snapshot and its published_sequence is strict-monotonic, so there is
 * no incremental diff to keep (unlike the Mocambos bridge). The local
 * constellation id + slug are reused across re-pulls for stability.
 *
 * Content-addressed media is resolved (cache hit, or fetch + re-hash + store)
 * before the materialization transaction opens. A hash mismatch or fetch
 * failure aborts the pull and releases the replay nonce; the local mirror is
 * never mutated on a failed pull.
 *
 * Spec: Stage 5 galaxy publish design (5d-ii, 5d-iii); v10 § State-change propagation.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_envelope.php';
require_once __DIR__ . '/sig_verify.php';
require_once __DIR__ . '/media_store.php';
require_once __DIR__ . '/galaxy_pull.php';

/** Envelope media-ref field -> node column. */
const FEDERATION_MIRROR_MEDIA_FIELDS = ['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'];

/**
 * Relative URL prefix for a locally cached, content-addressed mirror blob. The
 * full per-blob URL is this prefix + sha256; db_normalize_asset_url turns it
 * into '/uploads/federation-media/{sha256}', which nginx routes through
 * serve-upload.php (which serves any file inside UPLOAD_DIR).
 */
const FEDERATION_MIRROR_MEDIA_URL_PREFIX = 'uploads/federation-media/';

/**
 * Verify a fetched envelope against the peer's key and materialize it. The
 * single entry point for a pulled galaxy: key resolution + rotation grace,
 * signature + freshness (sequence / published_at) via the 5b verifier,
 * origin/slug/hash binding, per-origin nonce replay, content-addressed media
 * resolution, then materialization and subscription bookkeeping.
 *
 * Media handling is fail-closed: every content-addressed sha256 referenced by
 * the envelope is resolved (local cache hit or fetched + re-hashed + stored)
 * before the materialization transaction opens. A hash mismatch or an
 * unrecoverable fetch error aborts the pull, the replay nonce is released so a
 * later retry is not wedged, and the local mirror is not mutated.
 *
 * @param string $expectedContentHash The hash published.json advertised; binds
 *        the envelope to the index entry that triggered the pull.
 * @param int    $lastSeq             The last sequence we accepted for this slug.
 * @param callable|null $mediaFetcher fn(string $sha256): array{ok:bool, bytes?:string, mime?:string, error?:string}
 *        Network-fetches a single blob; integrity is verified in this file, so
 *        a test stub only needs to return bytes (the rehash check still
 *        applies). Null leaves uncached refs unresolved (the 5d-ii posture,
 *        preserved for tests / fetch-disabled paths); cron-driven pulls pass
 *        federation_mirror_default_fetcher().
 * @return array{ok:bool, error?:string, sha256?:string, constellation_id?:int, sequence?:int, content_hash?:string}
 */
function federation_pull_apply_envelope(
    int $peerId,
    string $peerHost,
    string $slug,
    string $jws,
    string $expectedContentHash,
    int $lastSeq,
    ?callable $mediaFetcher = null
): array {
    db_ensure_peers_table();
    $pdo = getDB();
    $keyStmt = $pdo->prepare("SELECT public_key, previous_public_key FROM peers WHERE id = :id LIMIT 1");
    $keyStmt->execute([':id' => $peerId]);
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);
    if ($keyRow === false) {
        return ['ok' => false, 'error' => 'unknown_peer'];
    }
    $currentKey = (string)$keyRow['public_key'];
    $previousKey = $keyRow['previous_public_key'] !== null ? (string)$keyRow['previous_public_key'] : '';

    $verify = federation_galaxy_envelope_verify($jws, $currentKey, $lastSeq);
    if (!$verify['valid'] && $verify['reason'] === 'signature_invalid' && $previousKey !== '') {
        // Rotation grace: the origin may have rotated since we cached its key.
        $verify = federation_galaxy_envelope_verify($jws, $previousKey, $lastSeq);
    }
    if (!$verify['valid']) {
        return ['ok' => false, 'error' => $verify['reason']];
    }
    $payload = $verify['payload'];

    // Bind the envelope to who we pulled it from and what the index advertised.
    if (strcasecmp((string)($payload['origin_host'] ?? ''), $peerHost) !== 0) {
        return ['ok' => false, 'error' => 'origin_host_mismatch'];
    }
    if ((string)($payload['slug'] ?? '') !== $slug) {
        return ['ok' => false, 'error' => 'slug_mismatch'];
    }
    if (!hash_equals($expectedContentHash, federation_galaxy_content_hash($payload))) {
        return ['ok' => false, 'error' => 'content_hash_mismatch'];
    }

    // Per-origin replay defence. Claim the nonce first; if any later step (media
    // resolve or materialize) fails, release it so a legitimate retry is not
    // wedged as a replay.
    $nonceBytes = _federation_mirror_decode_nonce((string)($payload['nonce'] ?? ''));
    if ($nonceBytes === null) {
        return ['ok' => false, 'error' => 'malformed_nonce'];
    }
    if (!federation_seen_nonce_record($peerHost, $nonceBytes)) {
        return ['ok' => false, 'error' => 'envelope_replay'];
    }

    // Resolve all content-addressed media up front. A hash mismatch or fetch
    // failure aborts the pull (fail-closed) and releases the nonce so a future
    // retry can proceed.
    $resolved = federation_mirror_resolve_envelope_media($peerHost, $payload, $mediaFetcher);
    if (!$resolved['ok']) {
        federation_seen_nonce_forget($peerHost, $nonceBytes);
        $out = ['ok' => false, 'error' => $resolved['error']];
        if (isset($resolved['sha256'])) $out['sha256'] = $resolved['sha256'];
        return $out;
    }
    $resolvedUrls = $resolved['urls'];

    try {
        $cid = federation_mirror_materialize($peerId, $peerHost, $slug, $payload, $expectedContentHash, $resolvedUrls);
    } catch (Throwable $e) {
        federation_seen_nonce_forget($peerHost, $nonceBytes);
        error_log('federation_pull_apply_envelope: materialize failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'materialize_failed'];
    }

    return [
        'ok' => true,
        'constellation_id' => $cid,
        'sequence' => (int)$payload['published_sequence'],
        'content_hash' => $expectedContentHash,
    ];
}

/**
 * Build the default media fetcher for production pull paths (cron / admin
 * Refresh-now). The returned callable wraps federation_pull_fetch_media bound
 * to the peer's hostname; the rehash + store check is enforced inside
 * federation_mirror_resolve_envelope_media regardless of which fetcher is used.
 */
function federation_mirror_default_fetcher(string $peerHost): callable {
    return function (string $sha256) use ($peerHost): array {
        return federation_pull_fetch_media($peerHost, $sha256);
    };
}

/**
 * Walk an envelope's nodes, fetch every content-addressed blob not already in
 * the local store, re-hash it, store it under federation-media/{sha256}, and
 * return a sha256 -> local-URL map. References are deduped: two nodes pointing
 * at the same hash trigger only one fetch.
 *
 * Errors:
 *   media_hash_mismatch  fetched bytes did not hash to the claimed sha256
 *   media_unavailable    the fetcher returned ok=false (404, network error, etc.)
 *   media_write_failed   the local disk store could not place the blob
 *
 * When $fetcher is null and a blob is not locally cached, the reference is left
 * unresolved (no error). The node field then materializes as NULL; this is the
 * 5d-ii posture, preserved so existing tests and fetch-disabled callers stay
 * green. Cron callers must pass a real fetcher to get fail-closed semantics.
 *
 * @param array<string,mixed> $payload Verified envelope payload.
 * @return array{ok:bool, urls?:array<string,string>, error?:string, sha256?:string}
 */
function federation_mirror_resolve_envelope_media(
    string $peerHost,
    array $payload,
    ?callable $fetcher = null
): array {
    $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];

    $referenced = [];
    foreach ($nodes as $n) {
        if (!is_array($n)) continue;
        foreach (($n['media'] ?? []) as $ref) {
            if (!is_array($ref)) continue;
            if (!isset($ref['sha256']) || !is_string($ref['sha256'])) continue;
            $sha256 = (string)$ref['sha256'];
            if (federation_media_is_sha256($sha256)) {
                $referenced[$sha256] = true;
            }
        }
    }
    $urls = [];
    if ($referenced === []) {
        return ['ok' => true, 'urls' => $urls];
    }

    $dir = federation_media_dir();
    foreach (array_keys($referenced) as $sha256) {
        if (federation_media_lookup($sha256) !== null) {
            $urls[$sha256] = FEDERATION_MIRROR_MEDIA_URL_PREFIX . $sha256;
            continue;
        }
        if ($fetcher === null) {
            // Soft-skip: leave the node field NULL when no fetcher is wired.
            continue;
        }
        $resp = $fetcher($sha256);
        if (!is_array($resp) || empty($resp['ok'])) {
            return ['ok' => false, 'error' => 'media_unavailable', 'sha256' => $sha256];
        }
        $bytes = (string)($resp['bytes'] ?? '');
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            return ['ok' => false, 'error' => 'media_hash_mismatch', 'sha256' => $sha256];
        }
        $dest = $dir . '/' . $sha256;
        if (!is_file($dest)) {
            // Temp + rename: a concurrent reader (a sibling pull, a peer GET)
            // never sees a half-written blob at the content-addressed path.
            $tmp = $dest . '.tmp.' . bin2hex(random_bytes(6));
            if (@file_put_contents($tmp, $bytes) === false) {
                error_log('federation_mirror_resolve_envelope_media: cannot write ' . $tmp);
                return ['ok' => false, 'error' => 'media_write_failed', 'sha256' => $sha256];
            }
            @chmod($tmp, 0664);
            if (!@rename($tmp, $dest)) {
                @unlink($tmp);
                if (!is_file($dest)) {
                    return ['ok' => false, 'error' => 'media_write_failed', 'sha256' => $sha256];
                }
            }
        }
        $mime = isset($resp['mime']) && is_string($resp['mime']) && $resp['mime'] !== ''
            ? (string)$resp['mime']
            : federation_media_detect_mime($dest);
        federation_media_record($sha256, $dest, $mime, strlen($bytes));
        $urls[$sha256] = FEDERATION_MIRROR_MEDIA_URL_PREFIX . $sha256;
    }
    return ['ok' => true, 'urls' => $urls];
}

/**
 * Create or replace the read-only mirror constellation for one envelope, and
 * update the subscription bookkeeping. Transactional. Returns the local
 * constellation id.
 *
 * @param array<string,mixed>  $payload          Verified envelope payload.
 * @param array<string,string> $resolvedMediaUrls sha256 -> local URL, built by
 *        federation_mirror_resolve_envelope_media. Unresolved hashes are
 *        absent from the map (the corresponding node field materializes NULL).
 */
function federation_mirror_materialize(
    int $peerId,
    string $peerHost,
    string $slug,
    array $payload,
    string $contentHash,
    array $resolvedMediaUrls = []
): int {
    // Pre-warm every schema guard the writes below depend on, so no implicit
    // DDL commit can fire mid-transaction (MySQL auto-commits on ALTER/CREATE).
    db_ensure_federation_attribution_columns();
    db_ensure_keyword_canvas_tables();
    db_ensure_galaxy_subscriptions_table();
    db_ensure_keywords_created_by_column();
    db_ensure_node_keywords_created_by_column();
    db_ensure_nodes_image_attribution_column();
    db_ensure_nodes_icon_url_column();
    db_ensure_nodes_clustering_columns();
    db_ensure_nodes_import_slug_column();
    db_ensure_nodes_show_keywords_column();
    db_ensure_nodes_pdf_url_column();

    $pdo = getDB();
    $galaxy = is_array($payload['galaxy'] ?? null) ? $payload['galaxy'] : [];
    $name = trim((string)($galaxy['name'] ?? '')) ?: $slug;
    $tagline = (string)($galaxy['tagline'] ?? '');
    $theme = trim((string)($galaxy['theme'] ?? '')) ?: 'cosmic';

    // Reuse the existing mirror constellation (stable id + slug) if we already
    // hold this subscription's galaxy; otherwise allocate a fresh, unique local
    // slug. The local slug is just a handle; provenance carries the real one.
    $subStmt = $pdo->prepare("SELECT local_constellation_id FROM galaxy_subscriptions WHERE peer_id = :p AND remote_slug = :s LIMIT 1");
    $subStmt->execute([':p' => $peerId, ':s' => $slug]);
    $existingCid = $subStmt->fetchColumn();
    $cid = ($existingCid !== false && $existingCid !== null) ? (int)$existingCid : 0;
    if ($cid > 0 && db_get_constellation_by_id($cid) === null) {
        $cid = 0; // local row was deleted out from under us
    }

    $pdo->beginTransaction();
    try {
        if ($cid === 0) {
            $localSlug = _federation_mirror_unique_slug($slug, $peerHost);
            $cid = db_create_constellation($name, $tagline, $localSlug, $theme);
        } else {
            // Replace in place: clear contents, refresh metadata. Drop keyword
            // relations first so clearing nodes (which prunes orphan keywords)
            // can never strand a relation FK.
            _federation_mirror_clear_keyword_relations($cid);
            db_clear_constellation_nodes($cid);
            $pdo->prepare("UPDATE constellations SET name = :n, tagline = :t, theme = :th WHERE id = :id")
                ->execute([':n' => $name, ':t' => $tagline, ':th' => $theme, ':id' => $cid]);
        }

        // Provenance stamp. read_only locks the visitor/editor surface; the
        // mirror is sovereign content the origin owns.
        $importSource = json_encode([
            'kind' => 'federation',
            'origin_host' => $peerHost,
            'remote_slug' => $slug,
            'sequence' => (int)$payload['published_sequence'],
            'content_hash' => $contentHash,
        ], JSON_UNESCAPED_SLASHES);
        $sourceAttribution = json_encode([
            'origin_host' => $peerHost,
            'remote_slug' => $slug,
            'mirrored_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("
            UPDATE constellations
            SET mirrored_from_peer_id = :p, import_source = :imp, read_only = TRUE, source_attribution = :sa
            WHERE id = :id
        ")->execute([':p' => $peerId, ':imp' => $importSource, ':sa' => $sourceAttribution, ':id' => $cid]);

        // Nodes + their keyword links.
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $nodeArr = _federation_mirror_node_array($n, $resolvedMediaUrls);
            $nodeId = db_create_node_for_restore($cid, $nodeArr);
            $keywords = [];
            foreach (($n['keywords'] ?? []) as $kw) {
                if (is_string($kw) && trim($kw) !== '') $keywords[] = trim($kw);
            }
            if ($keywords !== []) {
                db_save_node_keywords($nodeId, $keywords);
            }
        }

        // Keyword relations (the canvas graph), wired by name within this mirror.
        _federation_mirror_apply_relations($cid, is_array($payload['keyword_relations'] ?? null) ? $payload['keyword_relations'] : []);

        // Subscription bookkeeping.
        $pdo->prepare("
            UPDATE galaxy_subscriptions
            SET local_constellation_id = :cid,
                last_received_sequence = :seq,
                last_content_hash = :hash,
                last_synced_at = NOW()
            WHERE peer_id = :p AND remote_slug = :s
        ")->execute([
            ':cid' => $cid,
            ':seq' => (int)$payload['published_sequence'],
            ':hash' => $contentHash,
            ':p' => $peerId,
            ':s' => $slug,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $cid;
}

/**
 * Map an envelope node to the db_create_node_for_restore array shape, resolving
 * media refs. External-URL media uses its src directly; content-addressed media
 * is looked up in the resolved-URLs map built before the transaction opened. A
 * hash that is not in the map (no fetcher wired and no local cache) leaves the
 * field NULL.
 *
 * @param array<string,mixed>  $n
 * @param array<string,string> $resolvedMediaUrls sha256 -> local URL
 */
function _federation_mirror_node_array(array $n, array $resolvedMediaUrls): array {
    $media = [];
    foreach (FEDERATION_MIRROR_MEDIA_FIELDS as $field) $media[$field] = null;
    foreach (($n['media'] ?? []) as $ref) {
        if (!is_array($ref)) continue;
        $field = (string)($ref['field'] ?? '');
        if (!in_array($field, FEDERATION_MIRROR_MEDIA_FIELDS, true)) continue;
        if (isset($ref['sha256']) && is_string($ref['sha256']) && $ref['sha256'] !== '') {
            $media[$field] = $resolvedMediaUrls[(string)$ref['sha256']] ?? null;
        } elseif (isset($ref['src']) && is_string($ref['src']) && $ref['src'] !== '') {
            $media[$field] = (string)$ref['src'];
        }
    }
    return [
        'name' => (string)($n['name'] ?? ''),
        'description' => (string)($n['description'] ?? ''),
        'url' => (string)($n['url'] ?? ''),
        'node_type' => (string)($n['node_type'] ?? 'object'),
        'is_accentuated' => !empty($n['is_accentuated']),
        'show_keywords' => !empty($n['show_keywords']),
        'animation' => '{}',
        // Portal targets are resolved in a later pass once sibling mirrors exist.
        'target_constellation_id' => null,
        'image_url' => $media['image_url'],
        'icon_url' => $media['icon_url'],
        'audio_url' => $media['audio_url'],
        'video_url' => $media['video_url'],
        'pdf_url' => $media['pdf_url'],
    ];
}

/**
 * Wire the keyword-relation graph for a freshly materialized mirror. Endpoints
 * are matched by name within the constellation; any endpoint not already
 * created via a node's keyword list is created here so the graph is complete.
 * Duplicate and self-loop relations are skipped.
 *
 * @param list<array<string,mixed>> $relations
 */
function _federation_mirror_apply_relations(int $cid, array $relations): void {
    if ($relations === []) return;
    $pdo = getDB();

    $idByName = [];
    $load = function () use ($pdo, $cid, &$idByName): void {
        $idByName = [];
        $s = $pdo->prepare("SELECT id, keyword FROM keywords WHERE constellation_id = :c");
        $s->execute([':c' => $cid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $idByName[mb_strtolower((string)$r['keyword'])] = (int)$r['id'];
        }
    };
    $load();

    $resolve = function (string $name) use (&$idByName, $cid, $load): ?int {
        $key = mb_strtolower(trim($name));
        if ($key === '') return null;
        if (isset($idByName[$key])) return $idByName[$key];
        db_create_keyword(trim($name), $cid);
        $load();
        return $idByName[$key] ?? null;
    };

    $seen = [];
    foreach ($relations as $rel) {
        if (!is_array($rel)) continue;
        $from = (string)($rel['from'] ?? '');
        $to = (string)($rel['to'] ?? '');
        $aId = $resolve($from);
        $bId = $resolve($to);
        if ($aId === null || $bId === null || $aId === $bId) continue;
        $pairKey = $aId < $bId ? "$aId-$bId" : "$bId-$aId";
        if (isset($seen[$pairKey])) continue;
        $seen[$pairKey] = true;
        $note = isset($rel['note']) && is_string($rel['note']) && $rel['note'] !== '' ? $rel['note'] : null;
        try {
            db_create_keyword_relation($aId, $bId, null, $note);
        } catch (Throwable $_) {
            // Unique-index collision or self-loop: skip, keep materializing.
        }
    }
}

/** Delete every keyword relation whose endpoints belong to this constellation. */
function _federation_mirror_clear_keyword_relations(int $cid): void {
    db_ensure_keyword_canvas_tables();
    getDB()->prepare("
        DELETE kr FROM keyword_relations kr
        WHERE kr.keyword_a_id IN (SELECT id FROM keywords WHERE constellation_id = :c1)
           OR kr.keyword_b_id IN (SELECT id FROM keywords WHERE constellation_id = :c2)
    ")->execute([':c1' => $cid, ':c2' => $cid]);
}

/**
 * Allocate a unique local slug for a new mirror. Prefers the origin slug, then
 * disambiguates with a short origin-host tag, then numeric suffixes, so a local
 * authored galaxy and a same-slug mirror never collide on the UNIQUE index.
 */
function _federation_mirror_unique_slug(string $remoteSlug, string $peerHost): string {
    $base = $remoteSlug !== '' ? $remoteSlug : 'mirror';
    if (db_get_constellation_id_by_slug($base) === null) return $base;

    $hostTag = preg_replace('/[^a-z0-9]+/', '-', strtolower(explode('.', $peerHost)[0] ?? 'peer'));
    $hostTag = trim((string)$hostTag, '-') ?: 'peer';
    $candidate = $base . '-' . $hostTag;
    if (db_get_constellation_id_by_slug($candidate) === null) return $candidate;

    for ($i = 2; $i <= 99; $i++) {
        $try = $candidate . '-' . $i;
        if (db_get_constellation_id_by_slug($try) === null) return $try;
    }
    return $candidate . '-' . bin2hex(random_bytes(3));
}

/** Decode a base64url envelope nonce to raw bytes (8..64), or null if malformed. */
function _federation_mirror_decode_nonce(string $nonce): ?string {
    if ($nonce === '' || strlen($nonce) > 128) return null;
    $b64 = strtr($nonce, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) $b64 .= str_repeat('=', 4 - $pad);
    $raw = base64_decode($b64, true);
    if ($raw === false) return null;
    $len = strlen($raw);
    if ($len < 8 || $len > 64) return null;
    return $raw;
}
