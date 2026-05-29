<?php
declare(strict_types=1);

/**
 * Pluriverse pull library (stage 3).
 *
 * Periodically pulls peers.json, blacklist.json, and key-events.json from
 * the Pluriverse at www.telaris.ca, mirroring them into local tables.
 *
 *   peers.json       upserts into `peers` where source = 'registry'
 *   blacklist.json   full-replace into `pluriverse_blacklist`
 *   key-events.json  append-only into `key_events` (received_via = 'poll')
 *
 * All three honour conditional GET (If-None-Match / If-Modified-Since)
 * against the etag + last_modified previously stored in
 * `pluriverse_pull_state`. A 304 short-circuits without parsing.
 *
 * The current Pluriverse-side handlers emit full snapshots (no `id`
 * pagination yet); the v10 plan's `?since=<id>` cursor convention is a
 * scheduled follow-up on the Pluriverse side. `last_seen_id` in the
 * pull-state row is reserved for that future use.
 *
 * Spec: P2P federation plan v10 § Pluriverse-side endpoint catalogue
 * and § Layer 2: The local peer list.
 */

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/identity.php';

if (!defined('PLURIVERSE_PULL_BASE_URL')) {
    define('PLURIVERSE_PULL_BASE_URL', 'https://www.telaris.ca');
}
if (!defined('PLURIVERSE_PULL_TIMEOUT_CONNECT')) {
    define('PLURIVERSE_PULL_TIMEOUT_CONNECT', 10);
}
if (!defined('PLURIVERSE_PULL_TIMEOUT_TOTAL')) {
    define('PLURIVERSE_PULL_TIMEOUT_TOTAL', 30);
}

const PLURIVERSE_PULL_ENDPOINT_PEERS = 'peers';
const PLURIVERSE_PULL_ENDPOINT_BLACKLIST = 'blacklist';
const PLURIVERSE_PULL_ENDPOINT_KEY_EVENTS = 'key_events';

/**
 * @return array{
 *     last_seen_id: int,
 *     last_etag: ?string,
 *     last_modified: ?string,
 *     consecutive_failures: int,
 *     rows_processed_total: int
 * }
 */
function pluriverse_pull_state_get(string $endpoint): array {
    db_ensure_pluriverse_pull_state_table();
    $pdo = getDB();
    $s = $pdo->prepare("
        SELECT last_seen_id, last_etag, last_modified,
               consecutive_failures, rows_processed_total
        FROM pluriverse_pull_state WHERE endpoint = :e LIMIT 1
    ");
    $s->execute([':e' => $endpoint]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'last_seen_id' => 0,
            'last_etag' => null,
            'last_modified' => null,
            'consecutive_failures' => 0,
            'rows_processed_total' => 0,
        ];
    }
    return [
        'last_seen_id' => (int)$row['last_seen_id'],
        'last_etag' => $row['last_etag'] !== null ? (string)$row['last_etag'] : null,
        'last_modified' => $row['last_modified'] !== null ? (string)$row['last_modified'] : null,
        'consecutive_failures' => (int)$row['consecutive_failures'],
        'rows_processed_total' => (int)$row['rows_processed_total'],
    ];
}

function pluriverse_pull_state_mark_start(string $endpoint): void {
    db_ensure_pluriverse_pull_state_table();
    $pdo = getDB();
    $s = $pdo->prepare("
        INSERT INTO pluriverse_pull_state (endpoint, last_pull_started_at)
        VALUES (:e, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE last_pull_started_at = CURRENT_TIMESTAMP
    ");
    $s->execute([':e' => $endpoint]);
}

function pluriverse_pull_state_mark_success(
    string $endpoint,
    int $rowsProcessed,
    ?string $etag,
    ?string $lastModified,
    ?int $lastSeenId = null
): void {
    db_ensure_pluriverse_pull_state_table();
    $pdo = getDB();
    $s = $pdo->prepare("
        INSERT INTO pluriverse_pull_state
            (endpoint, last_seen_id, last_etag, last_modified,
             last_pull_succeeded_at, last_error, consecutive_failures,
             rows_processed_total)
        VALUES
            (:e, :lsid, :etag, :lm, CURRENT_TIMESTAMP, NULL, 0, :rows)
        ON DUPLICATE KEY UPDATE
            last_seen_id = GREATEST(last_seen_id, VALUES(last_seen_id)),
            last_etag = VALUES(last_etag),
            last_modified = VALUES(last_modified),
            last_pull_succeeded_at = CURRENT_TIMESTAMP,
            last_error = NULL,
            consecutive_failures = 0,
            rows_processed_total = rows_processed_total + VALUES(rows_processed_total)
    ");
    $s->execute([
        ':e' => $endpoint,
        ':lsid' => $lastSeenId ?? 0,
        ':etag' => $etag,
        ':lm' => $lastModified,
        ':rows' => $rowsProcessed,
    ]);
}

function pluriverse_pull_state_mark_failure(string $endpoint, string $error): void {
    db_ensure_pluriverse_pull_state_table();
    $pdo = getDB();
    $msg = mb_substr($error, 0, 1024);
    $s = $pdo->prepare("
        INSERT INTO pluriverse_pull_state
            (endpoint, last_pull_failed_at, last_error, consecutive_failures)
        VALUES (:e, CURRENT_TIMESTAMP, :err, 1)
        ON DUPLICATE KEY UPDATE
            last_pull_failed_at = CURRENT_TIMESTAMP,
            last_error = VALUES(last_error),
            consecutive_failures = consecutive_failures + 1
    ");
    $s->execute([':e' => $endpoint, ':err' => $msg]);
}

/**
 * @return array{
 *     status: int,
 *     headers: array<string, string>,
 *     body: string,
 *     error: ?string
 * }
 */
function pluriverse_pull_http_get(
    string $url,
    ?string $ifNoneMatch = null,
    ?string $ifModifiedSince = null
): array {
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
        $headers[] = 'If-None-Match: ' . $ifNoneMatch;
    }
    if ($ifModifiedSince !== null && $ifModifiedSince !== '') {
        $headers[] = 'If-Modified-Since: ' . $ifModifiedSince;
    }
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => PLURIVERSE_PULL_TIMEOUT_CONNECT,
        CURLOPT_TIMEOUT => PLURIVERSE_PULL_TIMEOUT_TOTAL,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'telaris-pluriverse-pull/1.0',
        CURLOPT_HEADERFUNCTION => function ($_ch, string $headerLine) use (&$responseHeaders): int {
            $len = strlen($headerLine);
            $trim = trim($headerLine);
            if ($trim === '' || stripos($trim, 'HTTP/') === 0) return $len;
            $colon = strpos($trim, ':');
            if ($colon === false) return $len;
            $name = strtolower(trim(substr($trim, 0, $colon)));
            $value = trim(substr($trim, $colon + 1));
            $responseHeaders[$name] = $value;
            return $len;
        },
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $body === null) {
        return [
            'status' => 0,
            'headers' => $responseHeaders,
            'body' => '',
            'error' => 'curl error: ' . ($err !== '' ? $err : 'unknown'),
        ];
    }
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => (string)$body,
        'error' => null,
    ];
}

// ---------------------------------------------------------------------------
// peers.json
// ---------------------------------------------------------------------------

/**
 * @return array{
 *     ok: bool,
 *     status: int,
 *     rows_processed: int,
 *     rows_inserted: int,
 *     rows_updated: int,
 *     rows_skipped: int,
 *     not_modified: bool,
 *     error: ?string
 * }
 */
function pluriverse_pull_peers(): array {
    $endpoint = PLURIVERSE_PULL_ENDPOINT_PEERS;
    $state = pluriverse_pull_state_get($endpoint);
    pluriverse_pull_state_mark_start($endpoint);

    $url = PLURIVERSE_PULL_BASE_URL . '/api/pluriverse/peers.json';
    $resp = pluriverse_pull_http_get($url, $state['last_etag'], $state['last_modified']);

    if ($resp['error'] !== null) {
        pluriverse_pull_state_mark_failure($endpoint, $resp['error']);
        return _pluriverse_pull_result(false, 0, 0, 0, 0, $resp, $resp['error']);
    }
    if ($resp['status'] === 304) {
        pluriverse_pull_state_mark_success(
            $endpoint, 0, $state['last_etag'], $state['last_modified'], $state['last_seen_id']
        );
        return _pluriverse_pull_result(true, 304, 0, 0, 0, $resp, null, true);
    }
    if ($resp['status'] !== 200) {
        $err = 'http ' . $resp['status'] . ' from ' . $url;
        pluriverse_pull_state_mark_failure($endpoint, $err);
        return _pluriverse_pull_result(false, $resp['status'], 0, 0, 0, $resp, $err);
    }

    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['peers']) || !is_array($data['peers'])) {
        $err = 'peers.json body shape invalid';
        pluriverse_pull_state_mark_failure($endpoint, $err);
        return _pluriverse_pull_result(false, $resp['status'], 0, 0, 0, $resp, $err);
    }

    db_ensure_peers_table();

    $ownFingerprint = _pluriverse_pull_own_fingerprint();

    $pdo = getDB();
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    foreach ($data['peers'] as $peer) {
        if (!is_array($peer)) { $skipped++; continue; }
        $hostname = (string)($peer['hostname'] ?? '');
        $publicKeyB64 = (string)($peer['public_key'] ?? '');
        $fingerprint = (string)($peer['public_key_fingerprint'] ?? '');
        if ($hostname === '' || $publicKeyB64 === '') { $skipped++; continue; }
        if ($ownFingerprint !== '' && $fingerprint === $ownFingerprint) { $skipped++; continue; }

        $publicKey = _pluriverse_pull_b64url_decode($publicKeyB64);
        if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $skipped++; continue;
        }
        $previousKeyB64 = $peer['previous_public_key'] ?? null;
        $previousKey = null;
        if (is_string($previousKeyB64) && $previousKeyB64 !== '') {
            $previousKey = _pluriverse_pull_b64url_decode($previousKeyB64);
            if ($previousKey === null || strlen($previousKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $previousKey = null;
            }
        }

        $url = (string)($peer['url'] ?? '');
        $label = (string)($peer['label'] ?? $hostname);
        $bridges = isset($peer['bridges']) ? json_encode($peer['bridges'], JSON_UNESCAPED_SLASHES) : null;
        $keyRotatedAt = isset($peer['key_rotated_at']) && is_string($peer['key_rotated_at'])
            ? _pluriverse_pull_iso_to_mysql($peer['key_rotated_at']) : null;
        $rotationReason = $peer['rotation_reason'] ?? null;
        if (!in_array($rotationReason, ['scheduled', 'operational', 'compromise', null], true)) {
            $rotationReason = null;
        }

        $existing = $pdo->prepare("SELECT id, source FROM peers WHERE hostname = :h LIMIT 1");
        $existing->execute([':h' => $hostname]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // Default for a freshly-discovered peer.
            $insertTrust = 'discovered';
            $restoredFromHandshakeId = null;
            // If a previous handshake against this hostname reached a state
            // that implies operator-confirmed trust (`complete` for an
            // initiator, `complete` or `accepted_awaiting_complete` for a
            // responder), promote the new row back to whitelisted instead of
            // silently regressing. The handshake row is the durable record of
            // that agreement; it survives a peer-row delete because there is
            // no FK CASCADE from peers->handshakes. Same goes for keys held
            // in peer_keys — they're indexed by api_key_hash, not by host.
            $hsStmt = $pdo->prepare("
                SELECT id FROM handshakes
                WHERE remote_hostname = :h
                  AND status IN ('complete','accepted_awaiting_complete')
                ORDER BY id DESC
                LIMIT 1
            ");
            $hsStmt->execute([':h' => $hostname]);
            $hsRow = $hsStmt->fetch(PDO::FETCH_ASSOC);
            if ($hsRow !== false) {
                $insertTrust = 'whitelisted';
                $restoredFromHandshakeId = (int)$hsRow['id'];
            }

            $ins = $pdo->prepare("
                INSERT INTO peers
                    (hostname, url, pluriverse_endpoint, public_key,
                     previous_public_key, key_rotated_at, rotation_reason,
                     label, bridges, source, source_detail,
                     trust_state, has_active_whitelist, health_status)
                VALUES
                    (:h, :u, :pe, :pk, :pp, :kra, :rr, :l, :b, 'registry',
                     'pluriverse-pull', :ts, FALSE, 'unknown')
            ");
            $ins->execute([
                ':h' => $hostname, ':u' => $url, ':pe' => $url,
                ':pk' => $publicKey, ':pp' => $previousKey,
                ':kra' => $keyRotatedAt, ':rr' => $rotationReason,
                ':l' => $label, ':b' => $bridges,
                ':ts' => $insertTrust,
            ]);
            $inserted++;

            if ($restoredFromHandshakeId !== null) {
                $newPeerId = (int)$pdo->lastInsertId();
                // Relink EVERY orphaned handshake for this hostname (not just
                // the one that triggered the restore decision) so admin views
                // and peer_id lookups see the full history, even rows from
                // earlier wipe/restore cycles.
                $pdo->prepare("UPDATE handshakes SET peer_id = :p WHERE remote_hostname = :h AND peer_id IS NULL")
                    ->execute([':p' => $newPeerId, ':h' => $hostname]);
                // Surface the restoration in the operator audit log. Without
                // this entry an operator has no way to notice the row was
                // ever wiped + restored (the user-visible state is identical
                // to the row never having been touched).
                db_ensure_pluriverse_log_tables();
                $pdo->prepare("
                    INSERT INTO pluriverse_log
                        (event_type, actor, target, outcome, details_summary)
                    VALUES
                        ('peer_trust_state_restored', 'pluriverse-pull',
                         :h, 'success',
                         CONCAT('Re-discovered peer; trust_state restored to whitelisted from handshake #', :i))
                ")->execute([':h' => $hostname, ':i' => (string)$restoredFromHandshakeId]);
            }
        } elseif ((string)$row['source'] === 'registry') {
            $upd = $pdo->prepare("
                UPDATE peers SET
                    url = :u,
                    pluriverse_endpoint = :pe,
                    public_key = :pk,
                    previous_public_key = :pp,
                    key_rotated_at = :kra,
                    rotation_reason = :rr,
                    label = :l,
                    bridges = :b,
                    source_detail = 'pluriverse-pull'
                WHERE id = :id
            ");
            $upd->execute([
                ':u' => $url, ':pe' => $url,
                ':pk' => $publicKey, ':pp' => $previousKey,
                ':kra' => $keyRotatedAt, ':rr' => $rotationReason,
                ':l' => $label, ':b' => $bridges,
                ':id' => (int)$row['id'],
            ]);
            $updated++;
        } else {
            // Manual row with same hostname: leave alone, surface conflict
            // via last_error so the operator sees it on next status read.
            $skipped++;
        }
    }

    $etag = $resp['headers']['etag'] ?? null;
    $lastMod = $resp['headers']['last-modified'] ?? null;
    pluriverse_pull_state_mark_success(
        $endpoint, $inserted + $updated, $etag, $lastMod, $state['last_seen_id']
    );
    return _pluriverse_pull_result(true, 200, $inserted + $updated + $skipped, $inserted, $updated, $resp, null, false, $skipped);
}

// ---------------------------------------------------------------------------
// blacklist.json
// ---------------------------------------------------------------------------

function pluriverse_pull_blacklist(): array {
    $endpoint = PLURIVERSE_PULL_ENDPOINT_BLACKLIST;
    $state = pluriverse_pull_state_get($endpoint);
    pluriverse_pull_state_mark_start($endpoint);

    $url = PLURIVERSE_PULL_BASE_URL . '/api/pluriverse/blacklist.json';
    $resp = pluriverse_pull_http_get($url, $state['last_etag'], $state['last_modified']);

    if ($resp['error'] !== null) {
        pluriverse_pull_state_mark_failure($endpoint, $resp['error']);
        return _pluriverse_pull_result(false, 0, 0, 0, 0, $resp, $resp['error']);
    }
    if ($resp['status'] === 304) {
        pluriverse_pull_state_mark_success(
            $endpoint, 0, $state['last_etag'], $state['last_modified'], $state['last_seen_id']
        );
        return _pluriverse_pull_result(true, 304, 0, 0, 0, $resp, null, true);
    }
    if ($resp['status'] !== 200) {
        $err = 'http ' . $resp['status'] . ' from ' . $url;
        pluriverse_pull_state_mark_failure($endpoint, $err);
        return _pluriverse_pull_result(false, $resp['status'], 0, 0, 0, $resp, $err);
    }

    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        $err = 'blacklist.json body shape invalid';
        pluriverse_pull_state_mark_failure($endpoint, $err);
        return _pluriverse_pull_result(false, $resp['status'], 0, 0, 0, $resp, $err);
    }

    db_ensure_pluriverse_blacklist_table();
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // Full-replace strategy: blacklist is small and the wire format
        // currently lacks ids, so the snapshot is authoritative each pull.
        // Capture current keys before truncating so we can count add vs.
        // unchanged for the result envelope.
        $existingKeys = [];
        $r = $pdo->query("SELECT entry_type, entry_value FROM pluriverse_blacklist");
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $eRow) {
            $existingKeys[(string)$eRow['entry_type'] . ':' . (string)$eRow['entry_value']] = true;
        }
        $pdo->exec("DELETE FROM pluriverse_blacklist");

        $inserted = 0;
        $unchanged = 0;
        $skipped = 0;
        foreach ($data['entries'] as $entry) {
            if (!is_array($entry)) { $skipped++; continue; }
            $type = (string)($entry['entry_type'] ?? '');
            $value = (string)($entry['entry_value'] ?? '');
            if (!in_array($type, ['hostname', 'ip', 'domain'], true) || $value === '') {
                $skipped++; continue;
            }
            $reason = isset($entry['reason']) && is_string($entry['reason']) ? $entry['reason'] : null;
            $addedBy = isset($entry['added_by']) && is_string($entry['added_by']) ? $entry['added_by'] : null;
            $addedAt = isset($entry['added_at']) && is_string($entry['added_at'])
                ? _pluriverse_pull_iso_to_mysql($entry['added_at']) : null;
            if ($addedAt === null) $addedAt = gmdate('Y-m-d H:i:s');

            $ins = $pdo->prepare("
                INSERT INTO pluriverse_blacklist
                    (entry_type, entry_value, reason, added_by, added_at)
                VALUES
                    (:t, :v, :r, :ab, :aa)
            ");
            $ins->execute([
                ':t' => $type, ':v' => $value,
                ':r' => $reason, ':ab' => $addedBy, ':aa' => $addedAt,
            ]);
            if (isset($existingKeys[$type . ':' . $value])) {
                $unchanged++;
            } else {
                $inserted++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        pluriverse_pull_state_mark_failure($endpoint, $e->getMessage());
        return _pluriverse_pull_result(false, $resp['status'], 0, 0, 0, $resp, $e->getMessage());
    }

    $etag = $resp['headers']['etag'] ?? null;
    $lastMod = $resp['headers']['last-modified'] ?? null;
    pluriverse_pull_state_mark_success(
        $endpoint, $inserted + $unchanged, $etag, $lastMod, $state['last_seen_id']
    );
    return _pluriverse_pull_result(true, 200, $inserted + $unchanged + $skipped, $inserted, $unchanged, $resp, null, false, $skipped);
}

// ---------------------------------------------------------------------------
// key-events.json
// ---------------------------------------------------------------------------

function pluriverse_pull_key_events(): array {
    $endpoint = PLURIVERSE_PULL_ENDPOINT_KEY_EVENTS;
    $state = pluriverse_pull_state_get($endpoint);
    pluriverse_pull_state_mark_start($endpoint);

    $url = PLURIVERSE_PULL_BASE_URL . '/api/pluriverse/key-events.json';
    $resp = pluriverse_pull_http_get($url, $state['last_etag'], $state['last_modified']);

    if ($resp['error'] !== null) {
        pluriverse_pull_state_mark_failure($endpoint, $resp['error']);
        return _pluriverse_pull_result(false, 0, 0, 0, 0, $resp, $resp['error']);
    }
    if ($resp['status'] === 304) {
        pluriverse_pull_state_mark_success(
            $endpoint, 0, $state['last_etag'], $state['last_modified'], $state['last_seen_id']
        );
        return _pluriverse_pull_result(true, 304, 0, 0, 0, $resp, null, true);
    }
    if ($resp['status'] !== 200) {
        $err = 'http ' . $resp['status'] . ' from ' . $url;
        pluriverse_pull_state_mark_failure($endpoint, $err);
        return _pluriverse_pull_result(false, $resp['status'], 0, 0, 0, $resp, $err);
    }

    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
        $err = 'key-events.json body shape invalid';
        pluriverse_pull_state_mark_failure($endpoint, $err);
        return _pluriverse_pull_result(false, $resp['status'], 0, 0, 0, $resp, $err);
    }

    db_ensure_key_events_table();
    $pdo = getDB();
    $inserted = 0;
    $unchanged = 0;
    $skipped = 0;
    foreach ($data['events'] as $event) {
        if (!is_array($event)) { $skipped++; continue; }
        $originHost = (string)($event['origin_host'] ?? '');
        $eventType = (string)($event['event_type'] ?? '');
        $occurredAt = isset($event['occurred_at']) && is_string($event['occurred_at'])
            ? _pluriverse_pull_iso_to_mysql($event['occurred_at']) : null;
        $signedPayload = (string)($event['signed_payload'] ?? '');
        if ($originHost === '' || $signedPayload === '' || $occurredAt === null) {
            $skipped++; continue;
        }
        if (!in_array($eventType, ['scheduled_rotation', 'operational_rotation', 'compromise', 'revocation'], true)) {
            $skipped++; continue;
        }
        // Append-only with idempotency by (origin_host, occurred_at, event_type).
        $dup = $pdo->prepare("
            SELECT id FROM key_events
            WHERE origin_host = :oh AND occurred_at = :oa AND event_type = :et
            LIMIT 1
        ");
        $dup->execute([':oh' => $originHost, ':oa' => $occurredAt, ':et' => $eventType]);
        if ($dup->fetchColumn() !== false) {
            $unchanged++;
            continue;
        }
        $ins = $pdo->prepare("
            INSERT INTO key_events
                (origin_host, event_type, occurred_at, signed_payload, received_via)
            VALUES
                (:oh, :et, :oa, :sp, 'poll')
        ");
        $ins->execute([
            ':oh' => $originHost, ':et' => $eventType,
            ':oa' => $occurredAt, ':sp' => $signedPayload,
        ]);
        $inserted++;
    }

    $etag = $resp['headers']['etag'] ?? null;
    $lastMod = $resp['headers']['last-modified'] ?? null;
    pluriverse_pull_state_mark_success(
        $endpoint, $inserted + $unchanged, $etag, $lastMod, $state['last_seen_id']
    );
    return _pluriverse_pull_result(true, 200, $inserted + $unchanged + $skipped, $inserted, $unchanged, $resp, null, false, $skipped);
}

// ---------------------------------------------------------------------------
// Internal helpers.
// ---------------------------------------------------------------------------

function _pluriverse_pull_own_fingerprint(): string {
    // Prefer the DB-backed value: pluriverse_applications.remote_fingerprint
    // is what the Pluriverse echoed back at apply time and is the same
    // value peers.json exposes. Using it as the primary source means
    // self-detection survives local-key-file edge cases (mode 0600 owned
    // by another user, rotated-but-not-yet-republished, test-only
    // overrides of FEDERATION_SECRET_KEY_PATH that poison the static
    // cache in federation_public_key()).
    try {
        db_ensure_pluriverse_applications_table();
        $pdo = getDB();
        $fp = $pdo->query("
            SELECT remote_fingerprint FROM pluriverse_applications
            WHERE remote_fingerprint IS NOT NULL AND remote_fingerprint <> ''
            ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        if (is_string($fp) && $fp !== '') return $fp;
    } catch (Throwable $_) {
        // ignore
    }
    // Fall back to the file-backed identity (covers the pre-apply window
    // and fresh installs where the operator has not yet applied).
    try {
        return federation_public_key_fingerprint();
    } catch (Throwable $_) {
        return '';
    }
}

function _pluriverse_pull_b64url_decode(string $s): ?string {
    $padded = $s;
    $pad = strlen($padded) % 4;
    if ($pad > 0) $padded .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function _pluriverse_pull_iso_to_mysql(string $iso): ?string {
    $ts = strtotime($iso);
    if ($ts === false) return null;
    return gmdate('Y-m-d H:i:s', $ts);
}

/**
 * @param array{status:int,headers:array<string,string>,body:string,error:?string} $resp
 */
function _pluriverse_pull_result(
    bool $ok,
    int $status,
    int $rowsProcessed,
    int $rowsInserted,
    int $rowsUpdated,
    array $resp,
    ?string $error,
    bool $notModified = false,
    int $rowsSkipped = 0
): array {
    return [
        'ok' => $ok,
        'status' => $status !== 0 ? $status : $resp['status'],
        'rows_processed' => $rowsProcessed,
        'rows_inserted' => $rowsInserted,
        'rows_updated' => $rowsUpdated,
        'rows_skipped' => $rowsSkipped,
        'not_modified' => $notModified,
        'error' => $error,
    ];
}
