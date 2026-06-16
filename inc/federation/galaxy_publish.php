<?php
declare(strict_types=1);

/**
 * Stage 5c-i: galaxy publish action.
 *
 * federation_galaxy_publish() takes an authored galaxy, assembles its content
 * into the v1 envelope payload (galaxy metadata + nodes + keywords + keyword
 * relations + media references), signs it (5b), and upserts published_galaxies
 * with a bumped strict-monotonic sequence and a freshly cached envelope.
 *
 * Guards (the federation predicate's origin side):
 *   - the galaxy must exist and carry a slug
 *   - it must be authored here (import_source IS NULL); mirrors never re-publish
 *   - its slug must not be retracted (retracted slugs are permanently dead)
 *
 * Media is emitted as forward-compatible { field, src } refs. The sha256
 * content-addressing (media_blobs + the /media endpoint) and the publish-side
 * read endpoints are the paired next sub-chunks (5c-media, 5c-ii).
 *
 * Spec: Stage 5 galaxy publish design (5c).
 */

require_once __DIR__ . '/galaxy_envelope.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/media_store.php';
require_once dirname(__DIR__) . '/db.php';

const FEDERATION_GALAXY_MEDIA_FIELDS = ['image_url', 'icon_url', 'audio_url', 'video_url', 'pdf_url'];

/**
 * Assemble a galaxy's structured content for the envelope. Read-only; no
 * signing, no writes. Returns null if the galaxy doesn't exist.
 *
 * @return array{galaxy:array<string,mixed>, nodes:list<array<string,mixed>>, keyword_relations:list<array<string,mixed>>, slug:string, import_source:mixed}|null
 */
function federation_galaxy_collect_content(int $constellationId): ?array {
    $g = db_get_galaxy_for_dump($constellationId);
    if ($g === null) return null;

    $galaxy = [
        'name' => (string)($g['name'] ?? ''),
        'tagline' => (string)($g['tagline'] ?? ''),
        'theme' => (string)($g['theme'] ?? ''),
    ];

    $nodes = [];
    foreach (($g['nodes'] ?? []) as $n) {
        $media = [];
        foreach (FEDERATION_GALAXY_MEDIA_FIELDS as $field) {
            $url = $n[$field] ?? null;
            if (is_string($url) && $url !== '') {
                $media[] = ['field' => $field, 'src' => $url];
            }
        }
        $node = [
            'name' => (string)($n['name'] ?? ''),
            'description' => (string)($n['description'] ?? ''),
            'url' => (string)($n['url'] ?? ''),
            'node_type' => (string)($n['node_type'] ?? 'object'),
            'is_accentuated' => (bool)($n['is_accentuated'] ?? false),
            'show_keywords' => (bool)($n['show_keywords'] ?? false),
            'keywords' => array_values(array_map('strval', $n['keyword_names'] ?? [])),
            'media' => $media,
        ];
        if (($n['node_type'] ?? '') === 'portal' && !empty($n['target_constellation_slug'])) {
            $node['target_slug'] = (string)$n['target_constellation_slug'];
        }
        $nodes[] = $node;
    }

    // Keyword relations as {from, to} name pairs (canvas hydration is the source
    // of truth for the relation graph).
    $hydration = db_get_keyword_canvas_hydration($constellationId);
    $kwName = [];
    foreach (($hydration['keywords'] ?? []) as $kw) {
        $kwName[(int)$kw['id']] = (string)$kw['name'];
    }
    $relations = [];
    foreach (($hydration['relations'] ?? []) as $rel) {
        $from = $kwName[(int)($rel['a'] ?? 0)] ?? null;
        $to = $kwName[(int)($rel['b'] ?? 0)] ?? null;
        if ($from === null || $to === null) continue;
        $r = ['from' => $from, 'to' => $to];
        if (!empty($rel['note'])) $r['note'] = (string)$rel['note'];
        $relations[] = $r;
    }

    return [
        'galaxy' => $galaxy,
        'nodes' => $nodes,
        'keyword_relations' => $relations,
        'slug' => (string)($g['slug'] ?? ''),
        'import_source' => $g['import_source'] ?? null,
    ];
}

/**
 * Content-address each node's local-upload media. A media ref of the assembled
 * shape { field, src } whose src is a resolvable local upload is registered in
 * the federation media store and rewritten to { field, sha256, mime } so the
 * signed envelope carries the content hash, not the origin's local path; the
 * peer fetches the bytes from GET /media/{sha256}. External-URL refs (and any
 * local upload that fails to resolve) keep their { field, src } shape.
 *
 * @param list<array<string,mixed>> $nodes
 * @return list<array<string,mixed>>
 */
function federation_galaxy_finalize_media(array $nodes): array {
    foreach ($nodes as &$node) {
        if (!isset($node['media']) || !is_array($node['media'])) continue;
        $out = [];
        foreach ($node['media'] as $ref) {
            $field = (string)($ref['field'] ?? '');
            $src = isset($ref['src']) ? (string)$ref['src'] : '';
            if ($field === '') continue;
            $registered = $src !== '' ? federation_media_register_upload($src) : null;
            if ($registered !== null) {
                $out[] = ['field' => $field, 'sha256' => $registered['sha256'], 'mime' => $registered['mime']];
            } elseif ($src !== '') {
                $out[] = ['field' => $field, 'src' => $src];
            }
        }
        $node['media'] = $out;
    }
    unset($node);
    return $nodes;
}

/**
 * Operator-facing view: every authored galaxy on this instance plus its
 * federation status. Drives the 5f admin surface. Mirrored galaxies are
 * filtered out (only authored content can be published / retracted). Each
 * row carries the constellation handle, optional published-row stats, and
 * a derived `status`:
 *
 *   retracted     a retracted_galaxies row exists for this slug
 *   published     a published_galaxies.is_current = TRUE row exists
 *   not_published nothing has been published yet for this slug
 *   stale         was published but the current flag is false (retracted)
 *
 * @return list<array{constellation_id:int, slug:string, name:string, sequence:?int, content_hash:?string, published_at:?string, is_current:?bool, retracted_at:?string, retracted_reason:?string, status:string}>
 */
function federation_published_galaxies_admin_view(): array {
    db_ensure_published_galaxies_table();
    db_ensure_retracted_galaxies_table();
    db_ensure_federation_attribution_columns();
    $rows = getDB()->query("
        SELECT
            c.id AS constellation_id,
            c.name,
            c.slug,
            pg.published_sequence,
            pg.content_hash,
            pg.published_at,
            pg.is_current,
            rg.retracted_at,
            rg.reason AS retracted_reason
        FROM constellations c
        LEFT JOIN published_galaxies pg ON pg.slug = c.slug
        LEFT JOIN retracted_galaxies rg ON rg.slug = c.slug
        WHERE c.import_source IS NULL
          AND c.mirrored_from_peer_id IS NULL
          AND c.slug IS NOT NULL AND c.slug <> ''
        ORDER BY c.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $isCurrent = $r['is_current'] !== null ? (bool)$r['is_current'] : null;
        if ($r['retracted_at'] !== null) {
            $status = 'retracted';
        } elseif ($isCurrent === true) {
            $status = 'published';
        } elseif ($isCurrent === false) {
            // Published row exists but is_current is FALSE without a retraction
            // row — should not happen, but if it does call it stale.
            $status = 'stale';
        } else {
            $status = 'not_published';
        }
        $out[] = [
            'constellation_id' => (int)$r['constellation_id'],
            'slug' => (string)$r['slug'],
            'name' => (string)$r['name'],
            'sequence' => $r['published_sequence'] !== null ? (int)$r['published_sequence'] : null,
            'content_hash' => $r['content_hash'] !== null ? (string)$r['content_hash'] : null,
            'published_at' => $r['published_at'] !== null ? (string)$r['published_at'] : null,
            'is_current' => $isCurrent,
            'retracted_at' => $r['retracted_at'] !== null ? (string)$r['retracted_at'] : null,
            'retracted_reason' => $r['retracted_reason'] !== null ? (string)$r['retracted_reason'] : null,
            'status' => $status,
        ];
    }
    return $out;
}

/**
 * Publish (or re-publish) an authored galaxy: guards, sequence bump, build +
 * sign the envelope, cache it into published_galaxies.
 *
 * @return array{ok:bool, error?:string, slug?:string, sequence?:int, content_hash?:string}
 */
function federation_galaxy_publish(int $constellationId): array {
    db_ensure_published_galaxies_table();
    db_ensure_retracted_galaxies_table();

    $content = federation_galaxy_collect_content($constellationId);
    if ($content === null) {
        return ['ok' => false, 'error' => 'galaxy_not_found'];
    }
    $slug = $content['slug'];
    if ($slug === '') {
        return ['ok' => false, 'error' => 'galaxy_has_no_slug'];
    }
    // Only authored galaxies flow; mirrors carry import_source.
    if (!empty($content['import_source'])) {
        return ['ok' => false, 'error' => 'galaxy_is_mirrored'];
    }

    $pdo = getDB();
    // Retracted slugs are permanently dead.
    $rt = $pdo->prepare("SELECT 1 FROM retracted_galaxies WHERE slug = :slug LIMIT 1");
    $rt->execute([':slug' => $slug]);
    if ($rt->fetchColumn() !== false) {
        return ['ok' => false, 'error' => 'slug_retracted'];
    }

    // Next strict-monotonic sequence for this slug.
    $seqStmt = $pdo->prepare("SELECT published_sequence FROM published_galaxies WHERE slug = :slug LIMIT 1");
    $seqStmt->execute([':slug' => $slug]);
    $existing = $seqStmt->fetchColumn();
    $sequence = $existing === false ? 1 : ((int)$existing + 1);

    // Content-address local-upload media: copy each into the federation media
    // store and rewrite its ref to carry the sha256 the peer will fetch by.
    $nodes = federation_galaxy_finalize_media($content['nodes']);

    $payload = federation_galaxy_envelope_payload(
        $content['galaxy'],
        $nodes,
        $content['keyword_relations'],
        federation_local_hostname(),
        $slug,
        $sequence,
        gmdate('c')
    );
    $contentHash = federation_galaxy_content_hash($payload);
    $envelope = federation_galaxy_envelope_build($payload);

    $up = $pdo->prepare("
        INSERT INTO published_galaxies
            (constellation_id, slug, published_sequence, content_hash, envelope_jws, is_current, published_at)
        VALUES (:cid, :slug, :seq, :hash, :jws, TRUE, NOW())
        ON CONFLICT (slug) DO UPDATE SET
            constellation_id = EXCLUDED.constellation_id,
            published_sequence = EXCLUDED.published_sequence,
            content_hash = EXCLUDED.content_hash,
            envelope_jws = EXCLUDED.envelope_jws,
            is_current = TRUE,
            published_at = NOW()
    ");
    $up->execute([
        ':cid' => $constellationId,
        ':slug' => $slug,
        ':seq' => $sequence,
        ':hash' => $contentHash,
        ':jws' => $envelope,
    ]);

    return ['ok' => true, 'slug' => $slug, 'sequence' => $sequence, 'content_hash' => $contentHash];
}

/**
 * The published galaxies this instance offers to a given peer: current
 * published rows for authored galaxies (not bridge-imported, not federation
 * mirrors) that sit in this instance's publish whitelist for that peer.
 * Serves the federation predicate's origin side; the caller (published.json
 * handler) has already authenticated the peer.
 *
 * @return list<array{slug:string, published_sequence:int, content_hash:string, published_at:string}>
 */
function federation_published_for_peer(int $peerId): array {
    db_ensure_published_galaxies_table();
    db_ensure_galaxy_publish_whitelist_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT pg.slug, pg.published_sequence, pg.content_hash, pg.published_at
        FROM published_galaxies pg
        JOIN galaxy_publish_whitelist w ON w.constellation_id = pg.constellation_id
        JOIN constellations c ON c.id = pg.constellation_id
        WHERE w.peer_id = :peer
          AND pg.is_current = TRUE
          AND c.`type` = 'galaxy'
          AND c.import_source IS NULL
          AND c.mirrored_from_peer_id IS NULL
        ORDER BY pg.slug
    ");
    $stmt->execute([':peer' => $peerId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'slug' => (string)$r['slug'],
            'published_sequence' => (int)$r['published_sequence'],
            'content_hash' => (string)$r['content_hash'],
            'published_at' => (string)$r['published_at'],
        ];
    }
    return $out;
}

/**
 * The cached signed envelope this instance offers a given peer for one slug.
 * Same scoping as federation_published_for_peer (current + authored + in the
 * peer's publish whitelist), narrowed to a single slug; returns null when the
 * slug is unknown, not current, mirrored, or not whitelisted for this peer.
 * The null cases collapse so the handler can answer a uniform 404 without
 * leaking which galaxies exist.
 *
 * @return array{envelope_jws:string, content_hash:string, published_sequence:int, published_at:string}|null
 */
function federation_published_envelope_for_peer(int $peerId, string $slug): ?array {
    db_ensure_published_galaxies_table();
    db_ensure_galaxy_publish_whitelist_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT pg.envelope_jws, pg.content_hash, pg.published_sequence, pg.published_at
        FROM published_galaxies pg
        JOIN galaxy_publish_whitelist w ON w.constellation_id = pg.constellation_id
        JOIN constellations c ON c.id = pg.constellation_id
        WHERE w.peer_id = :peer
          AND pg.slug = :slug
          AND pg.is_current = TRUE
          AND c.`type` = 'galaxy'
          AND c.import_source IS NULL
          AND c.mirrored_from_peer_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([':peer' => $peerId, ':slug' => $slug]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r === false) return null;
    return [
        'envelope_jws' => (string)$r['envelope_jws'],
        'content_hash' => (string)$r['content_hash'],
        'published_sequence' => (int)$r['published_sequence'],
        'published_at' => (string)$r['published_at'],
    ];
}
