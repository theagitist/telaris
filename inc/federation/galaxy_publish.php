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

    $payload = federation_galaxy_envelope_payload(
        $content['galaxy'],
        $content['nodes'],
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
        ON DUPLICATE KEY UPDATE
            constellation_id = VALUES(constellation_id),
            published_sequence = VALUES(published_sequence),
            content_hash = VALUES(content_hash),
            envelope_jws = VALUES(envelope_jws),
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
