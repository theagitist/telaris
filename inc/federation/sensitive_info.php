<?php
declare(strict_types=1);

/**
 * Stage 4e: sensitive-information blocker for outbound message bodies.
 *
 * Scans a message body for high-confidence secret patterns and returns the
 * names of any patterns that matched. The caller decides what to do:
 * the admin compose handler refuses the submit unless the form also sets
 * send_anyway=1; whitelist/audit logging is the caller's responsibility.
 *
 * Patterns intentionally conservative — false-positives waste operator
 * time. The override path exists exactly because a markdown message about
 * "the password= URL parameter" or "the PEM format" shouldn't be silenced
 * outright; the operator can read the warning, confirm the content is
 * intentional, and proceed.
 *
 * Spec: P2P federation plan v10 § In-app messaging → UX rules,
 *       "Sensitive-information blocker. High-confidence secret patterns
 *       rejected with safer-alternative suggestions. Explicit 'send
 *       anyway' override logged."
 */

const FEDERATION_SENSITIVE_INFO_PATTERNS = [
    'private_key_pem' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/',
    'ssh_private_key' => '/-----BEGIN OPENSSH PRIVATE KEY-----/',
    'aws_access_key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'aws_secret_key' => '/\b[A-Za-z0-9\/+=]{40}\b(?=.*\baws[_-]?secret)/i',
    'github_token' => '/\bghp_[A-Za-z0-9]{36,}\b/',
    'jwt_shape' => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{20,}\b/',
    'password_assignment' => '/(?<![A-Za-z0-9_])password\s*[=:]\s*[^\s\'"\n]{6,}/i',
    'api_key_assignment' => '/(?<![A-Za-z0-9_])api[_-]?key\s*[=:]\s*[^\s\'"\n]{16,}/i',
];

/**
 * Scan a body and return the list of matching pattern names. Empty list
 * means no high-confidence secrets detected.
 *
 * @return list<string>
 */
function federation_sensitive_info_scan(string $body): array {
    $hits = [];
    foreach (FEDERATION_SENSITIVE_INFO_PATTERNS as $name => $pattern) {
        if (preg_match($pattern, $body) === 1) {
            $hits[] = $name;
        }
    }
    return $hits;
}

/**
 * Log a send-anyway override. Inserts a pluriverse_log row so a future
 * audit can see when the human operator chose to bypass the warning.
 */
function federation_sensitive_info_log_override(string $recipientHostname, int $handshakeId): void {
    db_ensure_pluriverse_log_tables();
    $actor = $_SESSION['admin_email'] ?? $_SESSION['user']['email'] ?? 'unknown-admin';
    $stmt = getDB()->prepare("
        INSERT INTO pluriverse_log (event_type, actor, target, outcome, details_summary)
        VALUES ('sensitive_info_override', :a, :h, 'warning', :d)
    ");
    $stmt->execute([
        ':a' => (string)$actor,
        ':h' => $recipientHostname,
        ':d' => 'handshake_id=' . $handshakeId,
    ]);
}
