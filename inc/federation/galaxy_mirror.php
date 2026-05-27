<?php
declare(strict_types=1);

/**
 * Stage 5d-ii: galaxy mirror materialization.
 *
 * Takes a fetched galaxy envelope, verifies it against the origin's key, and
 * materializes it as a read-only local mirror constellation. Mirrors live in
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
 * Spec: Stage 5 galaxy publish design (5d-ii); v10 § State-change propagation.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/galaxy_envelope.php';
require_once __DIR__ . '/sig_verify.php';

/** Envelope media-ref field -> node column. */
const FEDERATION_MIRROR_MEDIA_FIELDS = ['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'];

/**
 * Verify a fetched envelope against the peer's key and materialize it. The
 * single entry point for a pulled galaxy: key resolution + rotation grace,
 * signature + freshness (sequence / published_at) via the 5b verifier,
 * origin/slug/hash binding, per-origin nonce replay, then materialization and
 * subscription bookkeeping.
 *
 * @param string $expectedContentHash The hash published.json advertised; binds
 *        the envelope to the index entry that triggered the pull.
 * @param int    $lastSeq             The last sequence we accepted for this slug.
 * @param callable|null $mediaResolver fn(string $sha256, string $mime, string $field): ?string
 *        Returns a local URL for a content-addressed blob, or null if not yet
 *        available (5d-ii passes null; 5d-iii supplies a fetching resolver).
 * @return array{ok:bool, error?:string, constellation_id?:int, sequence?:int, content_hash?:string}
 */
function federation_pull_apply_envelope(
    int $peerId,
    string $peerHost,
    string $slug,
    string $jws,
    string $expectedContentHash,
    int $lastSeq,
    ?callable $mediaResolver = null
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

    // Per-origin replay defence. Claim the nonce first; if materialization then
    // throws, release it so a legitimate retry is not wedged as a replay.
    $nonceBytes = _federation_mirror_decode_nonce((string)($payload['nonce'] ?? ''));
    if ($nonceBytes === null) {
        return ['ok' => false, 'error' => 'malformed_nonce'];
    }
    if (!federation_seen_nonce_record($peerHost, $nonceBytes)) {
        return ['ok' => false, 'error' => 'envelope_replay'];
    }

    try {
        $cid = federation_mirror_materialize($peerId, $peerHost, $slug, $payload, $expectedContentHash, $mediaResolver);
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
 * Create or replace the read-only mirror constellation for one envelope, and
 * update the subscription bookkeeping. Transactional. Returns the local
 * constellation id.
 *
 * @param array<string,mixed> $payload Verified envelope payload.
 * @param callable|null $mediaResolver See federation_pull_apply_envelope.
 */
function federation_mirror_materialize(
    int $peerId,
    string $peerHost,
    string $slug,
    array $payload,
    string $contentHash,
    ?callable $mediaResolver = null
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
            $nodeArr = _federation_mirror_node_array($n, $mediaResolver);
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
 * is resolved via the callback (null in 5d-ii leaves the field empty until
 * 5d-iii caches the blob).
 *
 * @param array<string,mixed> $n
 */
function _federation_mirror_node_array(array $n, ?callable $mediaResolver): array {
    $media = [];
    foreach (FEDERATION_MIRROR_MEDIA_FIELDS as $field) $media[$field] = null;
    foreach (($n['media'] ?? []) as $ref) {
        if (!is_array($ref)) continue;
        $field = (string)($ref['field'] ?? '');
        if (!in_array($field, FEDERATION_MIRROR_MEDIA_FIELDS, true)) continue;
        if (isset($ref['sha256']) && is_string($ref['sha256']) && $ref['sha256'] !== '') {
            $media[$field] = $mediaResolver !== null
                ? $mediaResolver((string)$ref['sha256'], (string)($ref['mime'] ?? ''), $field)
                : null;
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
