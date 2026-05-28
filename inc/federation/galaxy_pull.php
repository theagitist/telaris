<?php
declare(strict_types=1);

/**
 * Stage 5d: galaxy pull consumer.
 *
 * The subscribe side of the data plane. For each peer this instance subscribes
 * from, it pulls the peer's published index, diffs it against what it already
 * mirrors, fetches the changed envelopes, verifies + materializes them, and
 * honours retractions. This file is the foundation (5d-i): the signed
 * peer-read transport and the index diff; materialization, media, and
 * retraction handling layer on top in 5d-ii..iv.
 *
 * Signed peer-to-peer reads, not the unsigned Pluriverse pull in
 * pluriverse_pull.php: the instance signs each GET with its own identity key
 * (the key the 4d dispatcher signs with), tag tel-pull + a replay nonce. The
 * serving peer resolves the caller via peers.public_key and scopes the
 * response by peer_id. No separate per-peer key is involved.
 *
 * Spec: Stage 5 galaxy publish design (5d); P2P federation plan v10 §
 * State-change propagation, § Layer 3 (the bilateral whitelist).
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/http_sig.php';

if (!defined('FEDERATION_PULL_TIMEOUT_CONNECT')) define('FEDERATION_PULL_TIMEOUT_CONNECT', 10);
if (!defined('FEDERATION_PULL_TIMEOUT_TOTAL')) define('FEDERATION_PULL_TIMEOUT_TOTAL', 30);
// Defensive ceiling on an index/envelope body, mirroring the JWS payload cap.
const FEDERATION_PULL_MAX_BODY_BYTES = 2 * 1024 * 1024;
// Ceiling for a single content-addressed media blob. Set above the 55 MB upload
// limit nginx allows on the publish side, so anything an origin could publish
// can be mirrored. Larger blobs are out of scope for now (large-file streaming
// is a 5f operator-surface concern).
const FEDERATION_PULL_MAX_MEDIA_BYTES = 60 * 1024 * 1024;

/**
 * Issue a signed tel-pull GET to a peer. Signs the request with this instance's
 * identity key so the peer's verifier can resolve us by hostname + fingerprint
 * and scope the response to our subscription. Honours conditional GET via the
 * optional if_none_match.
 *
 * @param array{if_none_match?:string, accept?:string, max_bytes?:int} $opts
 * @return array{status:int, headers:array<string,string>, body:string, error:?string}
 */
function federation_peer_signed_get(string $peerHost, string $pathAndQuery, array $opts = []): array {
    $peerHost = strtolower(trim($peerHost));
    if ($peerHost === '') {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'empty_peer_host'];
    }
    if ($pathAndQuery === '' || $pathAndQuery[0] !== '/') {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'bad_path'];
    }

    try {
        $secret = federation_load_secret_key();
    } catch (Throwable $e) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'identity_unavailable'];
    }
    $public = federation_derive_public_key($secret);
    $fingerprint = federation_compute_fingerprint($public);
    $keyid = federation_local_hostname() . ':' . $fingerprint;

    $targetUri = 'https://' . $peerHost . $pathAndQuery;
    $headers = [
        'Host' => $peerHost,
        'Date' => gmdate('D, d M Y H:i:s') . ' GMT',
        'Accept' => (string)($opts['accept'] ?? 'application/json'),
    ];
    $signed = federation_http_sig_sign(
        ['method' => 'GET', 'target_uri' => $targetUri, 'headers' => $headers, 'body' => ''],
        $secret,
        ['keyid' => $keyid, 'tag' => 'tel-pull', 'nonce' => federation_http_sig_generate_nonce()]
    );
    $headers['Signature-Input'] = $signed['signature_input'];
    $headers['Signature'] = $signed['signature'];
    if (isset($opts['if_none_match']) && (string)$opts['if_none_match'] !== '') {
        $headers['If-None-Match'] = (string)$opts['if_none_match'];
    }

    $maxBytes = isset($opts['max_bytes']) && (int)$opts['max_bytes'] > 0
        ? (int)$opts['max_bytes']
        : FEDERATION_PULL_MAX_BODY_BYTES;
    return federation_pull_curl_get($targetUri, $headers, $maxBytes);
}

/**
 * Bare signed-GET transport. Split out so the signing in
 * federation_peer_signed_get stays free of curl wiring. The body is bounded by
 * $maxBodyBytes (defaults to the index/envelope cap; media downloads pass a
 * larger ceiling).
 *
 * @param array<string,string> $headers
 * @return array{status:int, headers:array<string,string>, body:string, error:?string}
 */
function federation_pull_curl_get(string $targetUri, array $headers, int $maxBodyBytes = FEDERATION_PULL_MAX_BODY_BYTES): array {
    $curlHeaders = [];
    foreach ($headers as $k => $v) $curlHeaders[] = $k . ': ' . $v;

    $respHeaders = [];
    $ch = curl_init($targetUri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CONNECTTIMEOUT => FEDERATION_PULL_TIMEOUT_CONNECT,
        CURLOPT_TIMEOUT => FEDERATION_PULL_TIMEOUT_TOTAL,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'telaris-galaxy-pull/1.0',
        CURLOPT_BUFFERSIZE => 65536,
        CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$respHeaders): int {
            $len = strlen($line);
            $t = trim($line);
            if ($t === '' || stripos($t, 'HTTP/') === 0) return $len;
            $colon = strpos($t, ':');
            if ($colon === false) return $len;
            $respHeaders[strtolower(trim(substr($t, 0, $colon)))] = trim(substr($t, $colon + 1));
            return $len;
        },
    ]);
    $body = curl_exec($ch);
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['status' => 0, 'headers' => $respHeaders, 'body' => '', 'error' => 'curl:' . ($err !== '' ? $err : 'unknown')];
    }
    if (strlen((string)$body) > $maxBodyBytes) {
        return ['status' => $status, 'headers' => $respHeaders, 'body' => '', 'error' => 'body_too_large'];
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => (string)$body, 'error' => null];
}

/**
 * Fetch + parse a peer's published index. Returns the normalized published
 * entries, or an error. A 304 is surfaced as not_modified with no entries.
 *
 * @return array{ok:bool, status:int, not_modified:bool, published:list<array{slug:string, published_sequence:int, content_hash:string, published_at:string}>, error:?string}
 */
function federation_pull_fetch_published(string $peerHost, ?string $ifNoneMatch = null): array {
    $resp = federation_peer_signed_get($peerHost, '/api/pluriverse/published.json', [
        'if_none_match' => $ifNoneMatch ?? '',
    ]);
    if ($resp['error'] !== null) {
        return ['ok' => false, 'status' => $resp['status'], 'not_modified' => false, 'published' => [], 'error' => $resp['error']];
    }
    if ($resp['status'] === 304) {
        return ['ok' => true, 'status' => 304, 'not_modified' => true, 'published' => [], 'error' => null];
    }
    if ($resp['status'] !== 200) {
        return ['ok' => false, 'status' => $resp['status'], 'not_modified' => false, 'published' => [], 'error' => 'http_' . $resp['status']];
    }
    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['published']) || !is_array($data['published'])) {
        return ['ok' => false, 'status' => 200, 'not_modified' => false, 'published' => [], 'error' => 'malformed_published_json'];
    }
    return ['ok' => true, 'status' => 200, 'not_modified' => false, 'published' => federation_pull_normalize_published($data['published']), 'error' => null];
}

/**
 * Normalize and validate raw published.json entries, dropping malformed ones.
 *
 * @param array<mixed> $raw
 * @return list<array{slug:string, published_sequence:int, content_hash:string, published_at:string}>
 */
function federation_pull_normalize_published(array $raw): array {
    $out = [];
    foreach ($raw as $e) {
        if (!is_array($e)) continue;
        $slug = (string)($e['slug'] ?? '');
        $hash = (string)($e['content_hash'] ?? '');
        $seq = $e['published_sequence'] ?? null;
        if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) continue;
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) continue;
        if (!is_int($seq) && !(is_string($seq) && ctype_digit($seq))) continue;
        $out[] = [
            'slug' => $slug,
            'published_sequence' => (int)$seq,
            'content_hash' => $hash,
            'published_at' => (string)($e['published_at'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Fetch a single galaxy envelope from a peer. Sends If-None-Match with the
 * last content hash we mirrored so an unchanged galaxy returns 304 and costs no
 * body. Returns the raw JWS Compact string on 200.
 *
 * @return array{ok:bool, status:int, not_modified:bool, jws:string, error:?string}
 */
function federation_pull_fetch_envelope(string $peerHost, string $slug, ?string $lastContentHash = null): array {
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        return ['ok' => false, 'status' => 0, 'not_modified' => false, 'jws' => '', 'error' => 'bad_slug'];
    }
    $opts = ['accept' => 'application/jose'];
    if ($lastContentHash !== null && $lastContentHash !== '') {
        $opts['if_none_match'] = '"' . $lastContentHash . '"';
    }
    $resp = federation_peer_signed_get($peerHost, '/api/pluriverse/galaxies/' . $slug, $opts);
    if ($resp['error'] !== null) {
        return ['ok' => false, 'status' => $resp['status'], 'not_modified' => false, 'jws' => '', 'error' => $resp['error']];
    }
    if ($resp['status'] === 304) {
        return ['ok' => true, 'status' => 304, 'not_modified' => true, 'jws' => '', 'error' => null];
    }
    if ($resp['status'] !== 200) {
        return ['ok' => false, 'status' => $resp['status'], 'not_modified' => false, 'jws' => '', 'error' => 'http_' . $resp['status']];
    }
    $jws = trim($resp['body']);
    if ($jws === '' || substr_count($jws, '.') !== 2) {
        return ['ok' => false, 'status' => 200, 'not_modified' => false, 'jws' => '', 'error' => 'malformed_envelope'];
    }
    return ['ok' => true, 'status' => 200, 'not_modified' => false, 'jws' => $jws, 'error' => null];
}

/**
 * Diff a peer's published index against this instance's active subscriptions
 * for that peer. Classifies each active subscription, ignoring published
 * galaxies we do not subscribe to.
 *
 *   to_pull    subscribed AND offered AND (hash changed) AND (sequence advanced)
 *   unchanged  subscribed AND offered AND hash equals what we last mirrored
 *   withdrawn  subscribed but the origin no longer offers it (fossilize, 5d-iv)
 *
 * A hash change with a non-advancing sequence is flagged stale (a rollback or
 * replay) and parked in `stale`, never pulled: the envelope verifier would
 * reject it on the monotonic-sequence check anyway, and surfacing it lets the
 * operator see a misbehaving origin.
 *
 * @param list<array{slug:string, published_sequence:int, content_hash:string, published_at:string}> $published
 * @return array{to_pull:list<array<string,mixed>>, unchanged:list<string>, withdrawn:list<string>, stale:list<string>}
 */
function federation_pull_diff(int $peerId, array $published): array {
    db_ensure_galaxy_subscriptions_table();
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT remote_slug, last_content_hash, last_received_sequence
        FROM galaxy_subscriptions
        WHERE peer_id = :p AND is_active = TRUE
    ");
    $stmt->execute([':p' => $peerId]);

    $offered = [];
    foreach ($published as $e) $offered[$e['slug']] = $e;

    $toPull = [];
    $unchanged = [];
    $withdrawn = [];
    $stale = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
        $slug = (string)$sub['remote_slug'];
        $lastHash = $sub['last_content_hash'] !== null ? (string)$sub['last_content_hash'] : null;
        $lastSeq = $sub['last_received_sequence'] !== null ? (int)$sub['last_received_sequence'] : 0;

        if (!isset($offered[$slug])) {
            $withdrawn[] = $slug;
            continue;
        }
        $e = $offered[$slug];
        if ($lastHash !== null && hash_equals($lastHash, $e['content_hash'])) {
            $unchanged[] = $slug;
            continue;
        }
        if ($e['published_sequence'] <= $lastSeq) {
            $stale[] = $slug;
            continue;
        }
        $toPull[] = [
            'slug' => $slug,
            'content_hash' => $e['content_hash'],
            'published_sequence' => $e['published_sequence'],
            'last_content_hash' => $lastHash,
            'last_received_sequence' => $lastSeq,
        ];
    }
    return ['to_pull' => $toPull, 'unchanged' => $unchanged, 'withdrawn' => $withdrawn, 'stale' => $stale];
}

/**
 * Fetch a single content-addressed media blob from a peer. The mirror code
 * re-hashes the returned bytes before storing them, so this transport does not
 * vouch for integrity. The serving peer applies its own per-IP rate limit; the
 * 60 MB body cap here is a downstream defence against a misbehaving peer
 * shipping a much larger payload than the upload limit allows.
 *
 * Returns the bytes on a 200 and the peer-advertised content-type when known;
 * any non-200 (including 304, which the media endpoint does not currently emit)
 * is surfaced as an error so callers can fail closed.
 *
 * @return array{ok:bool, status:int, bytes:string, mime:string, error:?string}
 */
function federation_pull_fetch_media(string $peerHost, string $sha256): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
        return ['ok' => false, 'status' => 0, 'bytes' => '', 'mime' => '', 'error' => 'bad_sha256'];
    }
    $resp = federation_peer_signed_get($peerHost, '/api/pluriverse/media/' . $sha256, [
        'accept' => '*/*',
        'max_bytes' => FEDERATION_PULL_MAX_MEDIA_BYTES,
    ]);
    if ($resp['error'] !== null) {
        return ['ok' => false, 'status' => $resp['status'], 'bytes' => '', 'mime' => '', 'error' => $resp['error']];
    }
    if ($resp['status'] !== 200) {
        return ['ok' => false, 'status' => $resp['status'], 'bytes' => '', 'mime' => '', 'error' => 'http_' . $resp['status']];
    }
    $mime = $resp['headers']['content-type'] ?? '';
    // Strip parameters from Content-Type (charset, boundary, etc.) for storage.
    if (($semi = strpos($mime, ';')) !== false) $mime = trim(substr($mime, 0, $semi));
    return ['ok' => true, 'status' => 200, 'bytes' => $resp['body'], 'mime' => (string)$mime, 'error' => null];
}
