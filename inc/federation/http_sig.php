<?php
declare(strict_types=1);

/**
 * RFC 9421 HTTP Message Signatures — sign and verify.
 *
 * Minimal implementation targeting the exact subset v10 specifies:
 * - Algorithm: ed25519 (RFC 9421 + libsodium).
 * - Single signature label `sig1`. Multi-signature support is out of scope.
 * - Required components per method (see FEDERATION_HTTP_SIG_BASE_COMPONENTS
 *   and FEDERATION_HTTP_SIG_BODY_COMPONENTS).
 * - Required Signature-Input params: created, expires, keyid, alg, tag.
 *
 * Spec: P2P federation plan v10 § HTTP Signature coverage (line 516+).
 *
 * Status at stage 1e: helper-only. No endpoint enforces signatures at this
 * stage; that switches on in stage 3+. The unit test exercises sign/verify
 * roundtrip and the rejection paths so the helper is contract-stable when
 * later stages start wiring it into request paths.
 *
 * Design choice: roll our own rather than pull in a third-party RFC 9421
 * library. The PHP ecosystem does not have a stable, audited RFC 9421
 * implementation. The signing primitive is one libsodium call; the
 * Structured Field encoding for our fixed component set is bounded and
 * testable. web-token/jwt-framework, originally pinned in v10 line 119
 * for JWS + HTTP Sigs, only covers JWS — that dep lands in stage 4 when
 * the JWS envelope ships.
 */

require_once __DIR__ . '/identity.php';

/** Components every authenticated request MUST cover. */
const FEDERATION_HTTP_SIG_BASE_COMPONENTS = ['@method', '@target-uri', '@authority', 'host', 'date'];

/** Additional components for POST / PUT / PATCH. */
const FEDERATION_HTTP_SIG_BODY_COMPONENTS = ['content-type', 'content-digest', 'content-length'];

/** Max clock skew (seconds) for the `created` and `date` checks. */
const FEDERATION_HTTP_SIG_MAX_SKEW = 300;

/** Algorithm name (RFC 9421 §6.2.4). v10 requires this. */
const FEDERATION_HTTP_SIG_ALG = 'ed25519';

/**
 * Sign a request per RFC 9421 §3.
 *
 * @param array{method:string, target_uri:string, headers?:array<string,string>, body?:string} $request
 * @param string $secretKey libsodium-form Ed25519 secret (64 bytes)
 * @param array{keyid:string, tag:string, created?:int, expires?:int} $params
 * @return array{signature_input:string, signature:string, content_digest?:string}
 */
function federation_http_sig_sign(array $request, string $secretKey, array $params): array {
    if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new InvalidArgumentException('federation_http_sig_sign: secret key wrong length');
    }
    $method = strtoupper((string)($request['method'] ?? 'GET'));
    $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);
    $components = $hasBody
        ? array_merge(FEDERATION_HTTP_SIG_BASE_COMPONENTS, FEDERATION_HTTP_SIG_BODY_COMPONENTS)
        : FEDERATION_HTTP_SIG_BASE_COMPONENTS;

    $now = time();
    $finalParams = [
        'created' => (int)($params['created'] ?? $now),
        'expires' => (int)($params['expires'] ?? ($now + FEDERATION_HTTP_SIG_MAX_SKEW)),
        'keyid'   => (string)($params['keyid'] ?? ''),
        'alg'     => FEDERATION_HTTP_SIG_ALG,
        'tag'     => (string)($params['tag'] ?? ''),
    ];
    if ($finalParams['keyid'] === '') {
        throw new InvalidArgumentException('federation_http_sig_sign: keyid is required');
    }
    if ($finalParams['tag'] === '') {
        throw new InvalidArgumentException('federation_http_sig_sign: tag is required');
    }

    // Backfill auto-computable body headers so the signature covers them.
    $result = [];
    if ($hasBody) {
        $body = (string)($request['body'] ?? '');
        $headers = array_change_key_case((array)($request['headers'] ?? []), CASE_LOWER);
        if (!isset($headers['content-digest']) || $headers['content-digest'] === '') {
            $digest = federation_http_sig_content_digest($body);
            $request['headers']['content-digest'] = $digest;
            $result['content_digest'] = $digest;
        }
        if (!isset($headers['content-length']) || $headers['content-length'] === '') {
            $request['headers']['content-length'] = (string)strlen($body);
        }
    }

    $values = federation_http_sig_component_values($request);
    foreach ($components as $c) {
        if (!array_key_exists($c, $values) || $values[$c] === '') {
            throw new InvalidArgumentException('federation_http_sig_sign: missing value for ' . $c);
        }
    }
    $base = federation_http_sig_build_base($components, $finalParams, $values);
    $signature = sodium_crypto_sign_detached($base, $secretKey);

    $result['signature_input'] = 'sig1=' . federation_http_sig_serialize_inner_list($components, $finalParams);
    $result['signature'] = 'sig1=:' . base64_encode($signature) . ':';
    return $result;
}

/**
 * Verify a signed request.
 *
 * @param array{method:string, target_uri:string, headers?:array<string,string>, body?:string} $request
 * @param string $publicKey 32-byte Ed25519 public key
 * @param array{expected_tag?:string|list<string>, max_skew?:int} $expectations
 * @return array{valid:bool, reason:string, params?:array<string,scalar>}
 */
function federation_http_sig_verify(array $request, string $publicKey, array $expectations = []): array {
    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['valid' => false, 'reason' => 'public_key_wrong_length'];
    }
    $headers = array_change_key_case((array)($request['headers'] ?? []), CASE_LOWER);
    $sigInput = $headers['signature-input'] ?? null;
    $sigValue = $headers['signature'] ?? null;
    if (!is_string($sigInput) || !is_string($sigValue)) {
        return ['valid' => false, 'reason' => 'missing_signature_headers'];
    }

    [$inputLabel, $inputRest] = federation_http_sig_split_label($sigInput);
    if ($inputLabel !== 'sig1') {
        return ['valid' => false, 'reason' => 'unsupported_signature_label'];
    }
    $parsed = federation_http_sig_parse_inner_list($inputRest);
    if ($parsed === null) {
        return ['valid' => false, 'reason' => 'malformed_signature_input'];
    }
    [$components, $params] = $parsed;

    foreach (['created', 'expires', 'keyid', 'alg', 'tag'] as $req) {
        if (!array_key_exists($req, $params)) {
            return ['valid' => false, 'reason' => 'missing_param_' . $req];
        }
    }
    if ($params['alg'] !== FEDERATION_HTTP_SIG_ALG) {
        return ['valid' => false, 'reason' => 'wrong_alg', 'params' => $params];
    }
    $maxSkew = (int)($expectations['max_skew'] ?? FEDERATION_HTTP_SIG_MAX_SKEW);
    $now = time();
    if (abs($now - (int)$params['created']) > $maxSkew) {
        return ['valid' => false, 'reason' => 'created_outside_skew', 'params' => $params];
    }
    if ($now > (int)$params['expires']) {
        return ['valid' => false, 'reason' => 'expired', 'params' => $params];
    }
    if (isset($headers['date'])) {
        $dateTs = @strtotime((string)$headers['date']);
        if ($dateTs === false || abs($now - $dateTs) > $maxSkew) {
            return ['valid' => false, 'reason' => 'date_outside_skew', 'params' => $params];
        }
    }
    if (isset($expectations['expected_tag'])) {
        $allowed = is_array($expectations['expected_tag']) ? $expectations['expected_tag'] : [(string)$expectations['expected_tag']];
        if (!in_array($params['tag'], $allowed, true)) {
            return ['valid' => false, 'reason' => 'wrong_tag', 'params' => $params];
        }
    }

    $method = strtoupper((string)($request['method'] ?? 'GET'));
    $required = in_array($method, ['POST', 'PUT', 'PATCH'], true)
        ? array_merge(FEDERATION_HTTP_SIG_BASE_COMPONENTS, FEDERATION_HTTP_SIG_BODY_COMPONENTS)
        : FEDERATION_HTTP_SIG_BASE_COMPONENTS;
    foreach ($required as $c) {
        if (!in_array($c, $components, true)) {
            return ['valid' => false, 'reason' => 'missing_component_' . $c, 'params' => $params];
        }
    }

    [$sigLabel, $sigRest] = federation_http_sig_split_label($sigValue);
    if ($sigLabel !== 'sig1') {
        return ['valid' => false, 'reason' => 'signature_label_mismatch'];
    }
    $sigBytes = federation_http_sig_decode_byte_seq($sigRest);
    if ($sigBytes === null) {
        return ['valid' => false, 'reason' => 'malformed_signature_value'];
    }

    $values = federation_http_sig_component_values($request);
    foreach ($components as $c) {
        if (!array_key_exists($c, $values) || $values[$c] === '') {
            return ['valid' => false, 'reason' => 'missing_value_for_' . $c, 'params' => $params];
        }
    }
    $base = federation_http_sig_build_base($components, $params, $values);
    if (!sodium_crypto_sign_verify_detached($sigBytes, $base, $publicKey)) {
        return ['valid' => false, 'reason' => 'signature_invalid', 'params' => $params];
    }
    return ['valid' => true, 'reason' => '', 'params' => $params];
}

/**
 * Compute the content-digest header value for a body per RFC 9421 §4.5 +
 * RFC 9530 (Digest Fields). Always sha-256, base64-encoded, structured field
 * byte-sequence form.
 */
function federation_http_sig_content_digest(string $body): string {
    return 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
}

/**
 * Build the canonical signature base per RFC 9421 §2.5.
 *
 * Each covered component is emitted as `"<name>": <value>\n`. The final line
 * is `"@signature-params": <serialized inner list>` with NO trailing newline.
 *
 * @param list<string> $components
 * @param array<string,scalar> $params
 * @param array<string,string> $values
 */
function federation_http_sig_build_base(array $components, array $params, array $values): string {
    $lines = [];
    foreach ($components as $c) {
        if (!array_key_exists($c, $values)) {
            throw new InvalidArgumentException('federation_http_sig_build_base: no value for ' . $c);
        }
        $lines[] = '"' . $c . '": ' . $values[$c];
    }
    $lines[] = '"@signature-params": ' . federation_http_sig_serialize_inner_list($components, $params);
    return implode("\n", $lines);
}

/**
 * Extract component values from a request dict. Handles derived components
 * (@method, @target-uri, @authority) and HTTP-header components (case-folded).
 *
 * @return array<string,string>
 */
function federation_http_sig_component_values(array $request): array {
    $method = strtoupper((string)($request['method'] ?? 'GET'));
    $targetUri = (string)($request['target_uri'] ?? '');
    $parsed = parse_url($targetUri);
    $authority = '';
    if (is_array($parsed) && isset($parsed['host'])) {
        $authority = strtolower((string)$parsed['host']);
        if (isset($parsed['port'])) {
            $authority .= ':' . (int)$parsed['port'];
        }
    }
    $headers = array_change_key_case((array)($request['headers'] ?? []), CASE_LOWER);

    $values = [
        '@method'      => $method,
        '@target-uri'  => $targetUri,
        '@authority'   => $authority,
        'host'         => (string)($headers['host'] ?? $authority),
        'date'         => (string)($headers['date'] ?? ''),
    ];
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $values['content-type']   = (string)($headers['content-type'] ?? '');
        $values['content-digest'] = (string)($headers['content-digest'] ?? '');
        $values['content-length'] = (string)($headers['content-length'] ?? '');
    }
    return $values;
}

/**
 * Serialize the inner list + parameters per RFC 8941 / RFC 9421.
 * Output shape: ("@method" "@target-uri" ...);created=123;expires=456;keyid="...";alg="ed25519";tag="..."
 *
 * @param list<string> $components
 * @param array<string,scalar> $params
 */
function federation_http_sig_serialize_inner_list(array $components, array $params): string {
    $out = '(' . implode(' ', array_map(fn($c) => '"' . $c . '"', $components)) . ')';
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $out .= ';' . $k . '=' . $v;
        } else {
            $out .= ';' . $k . '="' . $v . '"';
        }
    }
    return $out;
}

/**
 * "sig1=..." → ['sig1', '...']. The remainder may start with `(` (Signature-Input)
 * or `:` (Signature byte-sequence).
 *
 * @return array{0:string,1:string}
 */
function federation_http_sig_split_label(string $header): array {
    $pos = strpos($header, '=');
    if ($pos === false) return ['', $header];
    return [substr($header, 0, $pos), substr($header, $pos + 1)];
}

/**
 * Parse "(comp1 comp2 ...);name1=val;name2=val" into [components, params].
 * Strict to the shape produced by federation_http_sig_serialize_inner_list:
 * integer or quoted-string param values only.
 *
 * @return array{0:list<string>,1:array<string,int|string>}|null
 */
function federation_http_sig_parse_inner_list(string $s): ?array {
    if ($s === '' || $s[0] !== '(') return null;
    $close = strpos($s, ')');
    if ($close === false) return null;
    $inside = substr($s, 1, $close - 1);
    $rest = substr($s, $close + 1);

    $components = [];
    if (trim($inside) !== '') {
        foreach (preg_split('/\s+/', trim($inside)) ?: [] as $tok) {
            if ($tok === '') continue;
            if (strlen($tok) < 2 || $tok[0] !== '"' || $tok[strlen($tok) - 1] !== '"') return null;
            $components[] = substr($tok, 1, -1);
        }
    }

    $params = [];
    if ($rest !== '') {
        if ($rest[0] !== ';') return null;
        $rest = substr($rest, 1);
        foreach (explode(';', $rest) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            $eq = strpos($pair, '=');
            if ($eq === false) return null;
            $k = substr($pair, 0, $eq);
            $v = substr($pair, $eq + 1);
            if ($k === '') return null;
            if (strlen($v) >= 2 && $v[0] === '"' && $v[strlen($v) - 1] === '"') {
                $params[$k] = substr($v, 1, -1);
            } elseif (ctype_digit(ltrim($v, '-'))) {
                $params[$k] = (int)$v;
            } else {
                return null;
            }
        }
    }
    return [$components, $params];
}

/**
 * ":<base64>:" → raw bytes. Returns null on malformed input.
 */
function federation_http_sig_decode_byte_seq(string $s): ?string {
    if (strlen($s) < 2 || $s[0] !== ':' || $s[strlen($s) - 1] !== ':') return null;
    $b64 = substr($s, 1, -1);
    $raw = base64_decode($b64, true);
    return $raw === false ? null : $raw;
}
