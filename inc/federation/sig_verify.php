<?php
declare(strict_types=1);

/**
 * Stage 4b: HTTP-Sig verifier middleware.
 *
 * Wraps the RFC 9421 primitives in inc/federation/http_sig.php with the
 * application-level concerns the bare verifier deliberately does not know
 * about: key resolution from peers / cached coord key (with rotation grace),
 * replay protection via seen_nonces, dual-tag dispatch.
 *
 * The bare verifier already enforces:
 *   - alg = ed25519 (substitution defence)
 *   - created / date / expires window (±300s)
 *   - tag presence + match against expected
 *   - required-component coverage (RFC 9421 §3 + v10)
 *
 * This middleware adds, around it:
 *   - parse keyid -> {host, fingerprint} -> resolve {peer|coord} public key
 *   - on signature mismatch, retry with previous_public_key (rotation grace)
 *   - dual-tag dispatch for /messages (tel-message vs tel-relay)
 *   - replay protection: INSERT IGNORE into seen_nonces keyed by (host, nonce)
 *
 * Spec: P2P federation plan v10 § HTTP Signature coverage (line 516+),
 * § Dual-tag endpoints (line 559+), § Replay protection.
 */

require_once __DIR__ . '/http_sig.php';
require_once __DIR__ . '/identity.php';

/** Required Signature-Input parameter for replay protection. 16 random bytes, base64url. */
const FEDERATION_SIG_NONCE_PARAM = 'nonce';

/**
 * Inbound HTTP-Sig verification.
 *
 * @param array{method:string, target_uri:string, headers?:array<string,string>, body?:string} $request
 * @param string|list<string> $expected_tag Single tag, or list of allowed tags (dual-tag endpoint).
 * @param array{
 *   dual_tag_map?:array{peer?:string,coord?:string},
 *   max_skew?:int,
 *   skip_replay_check?:bool
 * } $opts
 * @return array{
 *   ok:bool,
 *   reason:string,
 *   caller_keyid?:string,
 *   caller_kind?:string,
 *   caller_host?:string,
 *   caller_peer_id?:int,
 *   matched_tag?:string,
 *   nonce?:string,
 *   used_previous_key?:bool
 * }
 */
function federation_verify_inbound(array $request, string|array $expected_tag, array $opts = []): array {
    $headers = array_change_key_case((array)($request['headers'] ?? []), CASE_LOWER);
    $sigInput = $headers['signature-input'] ?? null;
    if (!is_string($sigInput) || $sigInput === '') {
        return ['ok' => false, 'reason' => 'missing_signature_input'];
    }

    $parsed = _federation_sig_peek_params($sigInput);
    if ($parsed === null) {
        return ['ok' => false, 'reason' => 'malformed_signature_input'];
    }
    $params = $parsed['params'];

    $keyid = isset($params['keyid']) ? (string)$params['keyid'] : '';
    if ($keyid === '') {
        return ['ok' => false, 'reason' => 'missing_param_keyid'];
    }
    $idParts = _federation_sig_parse_keyid($keyid);
    if ($idParts === null) {
        return ['ok' => false, 'reason' => 'malformed_keyid'];
    }
    [$host, $fingerprint] = [$idParts['host'], $idParts['fingerprint']];

    $resolved = federation_resolve_signing_key($host, $fingerprint);
    if ($resolved === null) {
        return ['ok' => false, 'reason' => 'unknown_keyid', 'caller_keyid' => $keyid];
    }

    $allowedTags = is_array($expected_tag) ? array_values($expected_tag) : [$expected_tag];
    if (isset($opts['dual_tag_map']) && is_array($opts['dual_tag_map'])) {
        $kindKey = $resolved['kind'] === 'coord' ? 'coord' : 'peer';
        $forced = $opts['dual_tag_map'][$kindKey] ?? null;
        if (!is_string($forced) || $forced === '') {
            return [
                'ok' => false,
                'reason' => 'dual_tag_unsupported_kind',
                'caller_keyid' => $keyid,
                'caller_kind' => $resolved['kind'],
            ];
        }
        $allowedTags = [$forced];
    }

    $verifyOpts = ['expected_tag' => $allowedTags];
    if (isset($opts['max_skew'])) {
        $verifyOpts['max_skew'] = (int)$opts['max_skew'];
    }

    $verify = federation_http_sig_verify($request, $resolved['public_key'], $verifyOpts);
    $usedPrevious = false;
    if (!$verify['valid']
        && $verify['reason'] === 'signature_invalid'
        && is_string($resolved['previous_public_key'] ?? null)
        && $resolved['previous_public_key'] !== '') {
        $retry = federation_http_sig_verify($request, $resolved['previous_public_key'], $verifyOpts);
        if ($retry['valid']) {
            $verify = $retry;
            $usedPrevious = true;
        }
    }

    if (!$verify['valid']) {
        return [
            'ok' => false,
            'reason' => _federation_sig_reason_code($verify['reason']),
            'caller_keyid' => $keyid,
            'caller_kind' => $resolved['kind'],
            'caller_host' => $host,
        ];
    }

    // Body-integrity check. The bare verifier requires content-digest as a
    // covered component for POST/PUT/PATCH (so the digest header is signed),
    // but it does not validate that the header value matches the actual body
    // bytes. The middleware closes that gap.
    $method = strtoupper((string)($request['method'] ?? 'GET'));
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $body = (string)($request['body'] ?? '');
        $signedDigest = (string)($headers['content-digest'] ?? '');
        $actualDigest = federation_http_sig_content_digest($body);
        if (!hash_equals($actualDigest, $signedDigest)) {
            return [
                'ok' => false,
                'reason' => 'body_digest_mismatch',
                'caller_keyid' => $keyid,
                'caller_kind' => $resolved['kind'],
                'caller_host' => $host,
            ];
        }
    }

    $vparams = isset($verify['params']) && is_array($verify['params']) ? $verify['params'] : [];
    $matchedTag = isset($vparams['tag']) ? (string)$vparams['tag'] : '';

    $skipReplay = (bool)($opts['skip_replay_check'] ?? false);
    $nonce = isset($vparams[FEDERATION_SIG_NONCE_PARAM]) ? (string)$vparams[FEDERATION_SIG_NONCE_PARAM] : '';
    if (!$skipReplay) {
        if ($nonce === '') {
            return [
                'ok' => false,
                'reason' => 'missing_param_nonce',
                'caller_keyid' => $keyid,
                'caller_kind' => $resolved['kind'],
                'caller_host' => $host,
            ];
        }
        $nonceBytes = _federation_sig_decode_nonce($nonce);
        if ($nonceBytes === null) {
            return [
                'ok' => false,
                'reason' => 'malformed_nonce',
                'caller_keyid' => $keyid,
                'caller_kind' => $resolved['kind'],
                'caller_host' => $host,
            ];
        }
        if (!federation_seen_nonce_record($host, $nonceBytes)) {
            return [
                'ok' => false,
                'reason' => 'signature_replay',
                'caller_keyid' => $keyid,
                'caller_kind' => $resolved['kind'],
                'caller_host' => $host,
            ];
        }
    }

    $result = [
        'ok' => true,
        'reason' => '',
        'caller_keyid' => $keyid,
        'caller_kind' => $resolved['kind'],
        'caller_host' => $host,
        'matched_tag' => $matchedTag,
        'nonce' => $nonce,
        'used_previous_key' => $usedPrevious,
    ];
    if ($resolved['kind'] === 'peer' && isset($resolved['peer_id'])) {
        $result['caller_peer_id'] = (int)$resolved['peer_id'];
    }
    return $result;
}

/**
 * Look up the public key for a signing identity. Returns null if unknown.
 *
 * @return array{
 *   kind:string,
 *   public_key:string,
 *   previous_public_key?:string,
 *   peer_id?:int
 * }|null
 */
function federation_resolve_signing_key(string $host, string $fingerprint): ?array {
    $host = strtolower(trim($host));
    if ($host === '') return null;

    if ($host === FEDERATION_PLURIVERSE_HOSTNAME) {
        return _federation_resolve_coord_key($fingerprint);
    }
    return _federation_resolve_peer_key($host, $fingerprint);
}

/** The canonical Pluriverse hostname for coord-key dispatch. */
const FEDERATION_PLURIVERSE_HOSTNAME = 'www.telaris.ca';

/**
 * Atomic seen-nonce check. Returns true on first sight (fresh, recorded),
 * false on replay (already present). Uses INSERT IGNORE so the check is
 * race-free across concurrent verifiers.
 */
function federation_seen_nonce_record(string $host, string $nonceBytes): bool {
    db_ensure_seen_nonces_table();
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT IGNORE INTO seen_nonces (origin_host, nonce) VALUES (:h, :n)");
        $stmt->execute([':h' => $host, ':n' => $nonceBytes]);
        return $stmt->rowCount() === 1;
    } catch (PDOException $e) {
        error_log('federation_seen_nonce_record: ' . $e->getMessage());
        return false;
    }
}

/**
 * Compensating delete for federation_seen_nonce_record: drop a just-recorded
 * nonce so a failed downstream step (e.g. a mirror materialization that threw
 * after the nonce was claimed) does not wedge a legitimate retry as a replay.
 * Safe no-op if the row is already gone.
 */
function federation_seen_nonce_forget(string $host, string $nonceBytes): void {
    db_ensure_seen_nonces_table();
    try {
        getDB()->prepare("DELETE FROM seen_nonces WHERE origin_host = :h AND nonce = :n")
            ->execute([':h' => $host, ':n' => $nonceBytes]);
    } catch (PDOException $e) {
        error_log('federation_seen_nonce_forget: ' . $e->getMessage());
    }
}

/**
 * GC for the seen_nonces table. Default window 7 days (v10 § Replay protection,
 * Global Settings → Pluriverse). Returns rows removed. Cron-driven; not invoked
 * from the request path.
 */
function federation_seen_nonces_gc(int $retentionDays = 7): int {
    db_ensure_seen_nonces_table();
    try {
        $stmt = getDB()->prepare("
            DELETE FROM seen_nonces
            WHERE seen_at < DATE_SUB(NOW(), INTERVAL :d DAY)
        ");
        $stmt->execute([':d' => max(1, $retentionDays)]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('federation_seen_nonces_gc: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Generate a fresh nonce value: 16 random bytes, base64url-encoded (no padding).
 * Outbound signers (4d dispatcher) call this when constructing signed requests.
 */
function federation_sig_generate_nonce(): string {
    $bytes = random_bytes(16);
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

// --- internal helpers -----------------------------------------------------

/**
 * Peek at the Signature-Input params without doing the full verify. Used to
 * pull keyid before key resolution.
 *
 * @return array{components:list<string>,params:array<string,int|string>}|null
 */
function _federation_sig_peek_params(string $sigInputHeader): ?array {
    [$label, $rest] = federation_http_sig_split_label($sigInputHeader);
    if ($label !== 'sig1') return null;
    $parsed = federation_http_sig_parse_inner_list($rest);
    if ($parsed === null) return null;
    return ['components' => $parsed[0], 'params' => $parsed[1]];
}

/**
 * Parse keyid of the form "<host>:<fingerprint>". Returns null if malformed.
 *
 * @return array{host:string,fingerprint:string}|null
 */
function _federation_sig_parse_keyid(string $keyid): ?array {
    $pos = strrpos($keyid, ':');
    if ($pos === false || $pos === 0 || $pos === strlen($keyid) - 1) return null;
    return [
        'host' => substr($keyid, 0, $pos),
        'fingerprint' => substr($keyid, $pos + 1),
    ];
}

/**
 * Decode a base64url (no padding) nonce. Returns raw bytes or null on
 * malformed input. Bounds: 8-64 bytes after decode (defensive: covers the
 * 16-byte canonical shape plus room to grow without rewriting the verifier).
 */
function _federation_sig_decode_nonce(string $nonce): ?string {
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

/**
 * Resolve a peer's signing key from the peers table.
 *
 * @return array{kind:string,public_key:string,previous_public_key?:string,peer_id:int}|null
 */
function _federation_resolve_peer_key(string $host, string $fingerprint): ?array {
    db_ensure_peers_table();
    try {
        $stmt = getDB()->prepare("
            SELECT id, public_key, previous_public_key
            FROM peers WHERE hostname = :h LIMIT 1
        ");
        $stmt->execute([':h' => $host]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $publicKey = (string)$row['public_key'];
        $previousKey = $row['previous_public_key'] !== null ? (string)$row['previous_public_key'] : '';

        if (!_federation_sig_fingerprint_matches($publicKey, $fingerprint)
            && ($previousKey === '' || !_federation_sig_fingerprint_matches($previousKey, $fingerprint))) {
            return null;
        }
        $out = [
            'kind' => 'peer',
            'public_key' => $publicKey,
            'peer_id' => (int)$row['id'],
        ];
        if ($previousKey !== '') $out['previous_public_key'] = $previousKey;
        return $out;
    } catch (PDOException $e) {
        error_log('_federation_resolve_peer_key: ' . $e->getMessage());
        return null;
    }
}

/**
 * Resolve the cached Pluriverse coordination key from system_meta. Stage 4c
 * populates these rows on first contact. Verifies the keyid fingerprint against
 * the cached current (and previous, during rotation grace).
 *
 * @return array{kind:string,public_key:string,previous_public_key?:string}|null
 */
function _federation_resolve_coord_key(string $fingerprint): ?array {
    $current = db_system_meta_get('pluriverse_coord_pub_current');
    if (!is_string($current) || $current === '') return null;
    $currentBytes = base64_decode($current, true);
    if ($currentBytes === false || strlen($currentBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return null;
    }

    $previousBytes = '';
    $previousB64 = db_system_meta_get('pluriverse_coord_pub_previous');
    $previousExpires = db_system_meta_get('pluriverse_coord_pub_previous_expires_at');
    if (is_string($previousB64) && $previousB64 !== ''
        && is_string($previousExpires) && $previousExpires !== ''
        && strtotime($previousExpires) >= time()) {
        $decoded = base64_decode($previousB64, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $previousBytes = $decoded;
        }
    }

    if (!_federation_sig_fingerprint_matches($currentBytes, $fingerprint)
        && ($previousBytes === '' || !_federation_sig_fingerprint_matches($previousBytes, $fingerprint))) {
        return null;
    }

    $out = ['kind' => 'coord', 'public_key' => $currentBytes];
    if ($previousBytes !== '') $out['previous_public_key'] = $previousBytes;
    return $out;
}

/**
 * Constant-time match of fingerprint base64url(SHA-256(public_key)[0..16])
 * against an operator-supplied fingerprint string.
 */
function _federation_sig_fingerprint_matches(string $publicKeyBytes, string $fingerprint): bool {
    $expected = rtrim(strtr(base64_encode(substr(hash('sha256', $publicKeyBytes, true), 0, 16)), '+/', '-_'), '=');
    return hash_equals($expected, $fingerprint);
}

/**
 * Map the bare verifier's reason codes to v10's RFC 9457 problem-code shape.
 * Preserves the fine-grained signal for the calling endpoint to emit as
 * { type, code, detail } in the problem+json body.
 */
function _federation_sig_reason_code(string $reason): string {
    if ($reason === 'signature_invalid') return 'signature_invalid';
    if ($reason === 'wrong_alg') return 'signature_algorithm_disallowed';
    if ($reason === 'wrong_tag') return 'signature_tag_mismatch';
    if ($reason === 'expired') return 'signature_expired';
    if ($reason === 'created_outside_skew' || $reason === 'date_outside_skew') return 'signature_expired';
    if (str_starts_with($reason, 'missing_param_')) return 'signature_missing_param';
    if (str_starts_with($reason, 'missing_component_')) return 'signature_missing_component';
    if (str_starts_with($reason, 'missing_value_for_')) return 'signature_missing_value';
    if ($reason === 'public_key_wrong_length') return 'signature_invalid';
    if ($reason === 'missing_signature_headers') return 'signature_missing_headers';
    if ($reason === 'malformed_signature_input' || $reason === 'unsupported_signature_label'
        || $reason === 'malformed_signature_value' || $reason === 'signature_label_mismatch') {
        return 'signature_malformed';
    }
    return 'signature_invalid';
}

/**
 * Build the {method, target_uri, headers, body} request array that
 * federation_verify_inbound expects, from the current PHP request globals.
 * Shared by the GET peer-read endpoints (stage 5c). GET callers pass no body.
 *
 * @return array{method:string, target_uri:string, headers:array<string,string>, body:string}
 */
function federation_build_inbound_request(string $method, string $body = ''): array {
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (is_string($k) && str_starts_with($k, 'HTTP_')) {
            $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = (string)$v;
        }
    }
    foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $sk => $name) {
        if (isset($_SERVER[$sk])) $headers[$name] = (string)$_SERVER[$sk];
    }
    return [
        'method' => strtoupper($method),
        'target_uri' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . ($_SERVER['REQUEST_URI'] ?? '/'),
        'headers' => $headers,
        'body' => $body,
    ];
}
