<?php
declare(strict_types=1);

/**
 * API error envelope helper.
 *
 * Emits an RFC 9457 (Problem Details for HTTP APIs) response with a
 * locale-invariant numeric code of the form <http-status>.<3-digit-subcode>
 * and a localized human-readable message.
 *
 * Decolonial stance: the code is positional + locale-invariant; nothing
 * user-facing defaults to English. The localized message is looked up via
 * t() with the translation key "api_error_<status>_<subcode>". When the
 * lookup misses, the fallback is the code itself, not the English source
 * string: the worst-case user-visible token is the documented identifier.
 *
 * Response shape:
 *   {
 *     "ok":     false,
 *     "type":   "/errors/<code>",
 *     "status": <http-status>,
 *     "code":   "<http-status>.<3-digit-subcode>",
 *     "title":  "<localized message>"
 *   }
 *
 * Plus the HTTP status header set to the corresponding code.
 *
 * # Error code registry
 *
 * Codes are organized by HTTP status class (4xx client errors, 5xx server
 * errors). The 3-digit sub-code disambiguates within the status.
 *
 *   400.001 — invalid JSON                                  (api/*.php)
 *   400.002 — required field missing                        (api/*.php)
 *   400.003 — invalid URL scheme                            (api/nodes.php, utils/frame.php)
 *   400.004 — invalid cluster key format                    (api/nodes.php)
 *   400.005 — galaxies parameter incompatible with page/id  (api/nodes.php)
 *   400.006 — request body is empty                         (api/constellations.php)
 *   400.007 — node name is required                         (api/nodes.php)
 *   400.008 — node name cannot be empty                     (api/nodes.php)
 *   400.009 — node id is required                           (api/nodes.php)
 *   400.010 — constellation id is required                  (api/*.php)
 *   400.011 — constellation name is required                (api/constellations.php)
 *   400.012 — keyword is required                           (api/keywords.php)
 *   400.013 — keyword id is required                        (api/keywords.php)
 *   400.014 — keyword does not belong to this constellation (api/keywords.php)
 *   400.015 — galaxy_id is required                         (api/keyword-canvas.php)
 *   400.016 — move_keyword requires keyword_id, x, y        (api/keyword-canvas.php)
 *   400.017 — create_relation requires both keyword ids     (api/keyword-canvas.php)
 *   400.018 — self-loop relations are not allowed           (api/keyword-canvas.php)
 *   400.019 — both keywords must belong to the same galaxy  (api/keyword-canvas.php)
 *   400.020 — update_relation requires relation_id          (api/keyword-canvas.php)
 *   400.021 — delete_relation requires relation_id          (api/keyword-canvas.php)
 *   400.022 — reset_keyword requires keyword_id             (api/keyword-canvas.php)
 *   400.023 — reset_galaxy requires galaxy_id               (api/keyword-canvas.php)
 *   400.024 — delete_keyword requires keyword_id            (api/keyword-canvas.php)
 *   400.025 — rename_keyword requires keyword_id            (api/keyword-canvas.php)
 *   400.026 — rename_keyword requires a non-empty new name  (api/keyword-canvas.php)
 *   400.027 — keyword name is too long                      (api/keyword-canvas.php)
 *   400.028 — merge_keywords requires source and target ids (api/keyword-canvas.php)
 *   400.029 — cannot merge a keyword into itself            (api/keyword-canvas.php)
 *   400.030 — unknown action                                (api/keyword-canvas.php)
 *   400.031 — bulk-op spec (keyword_id, op) required        (api/nodes.php)
 *   400.032 — target_constellation_id required for move     (api/nodes.php)
 *   400.033 — missing or invalid bridge name                (api/bridge.php)
 *   400.034 — bridge is not enabled                         (api/bridge.php)
 *   400.035 — invalid validation type                       (api/validate.php)
 *   400.036 — file upload failed                            (admin/backup/import.php)
 *   400.037 — missing or invalid phase parameter            (admin/backup/import.php)
 *   400.038 — confirmation required                         (admin/backup/import.php)
 *   400.039 — missing or invalid id                         (admin/snapshots/*.php)
 *   400.040 — confirmation phrase missing or wrong          (admin/snapshots/restore.php)
 *   400.041 — encoding error                                (api/tags.php)
 *   400.042 — failed to encode response                     (api/nodes.php)
 *   400.043 — must select galaxies or users for backup      (admin/backup/export.php)
 *   400.044 — invalid URL format (full URL expected)        (inc/bridges/mocambos/handler.php)
 *   400.045 — no galaxias specified                         (inc/bridges/mocambos/handler.php)
 *   400.046 — upstream URL refused (SSRF guard)             (inc/bridges/mocambos/handler.php)
 *
 *   401.001 — api key is missing                            (api/auth.php)
 *   401.002 — invalid api key                               (api/auth.php)
 *
 *   403.001 — write operations require a session            (api/auth.php)
 *   403.002 — insufficient permissions                      (api/auth.php)
 *   403.003 — invalid security token                        (admin/*.php)
 *   403.004 — no edit access to this galaxy                 (api/keyword-canvas.php, api/nodes.php)
 *   403.005 — access denied                                 (api/nodes.php)
 *   403.006 — only the author or admin can edit             (api/keyword-canvas.php)
 *   403.007 — only the author or admin can delete           (api/keyword-canvas.php)
 *   403.008 — user existence check is admin-only            (api/validate.php)
 *
 *   404.001 — node not found                                (api/nodes.php)
 *   404.002 — galaxy not found                              (api/constellations.php, api/nodes.php)
 *   404.003 — keyword not found                             (api/keyword-canvas.php)
 *   404.004 — relation not found                            (api/keyword-canvas.php)
 *   404.005 — relation references missing keyword           (api/keyword-canvas.php)
 *   404.006 — cluster not found                             (api/constellations.php)
 *   404.007 — source node not found                         (api/nodes.php)
 *   404.008 — target galaxy does not exist                  (api/nodes.php)
 *   404.009 — api key not found                             (api/apikey.php)
 *   404.010 — bridge handler file is missing                (api/bridge.php)
 *   404.011 — bridge has no request handler                 (api/bridge.php)
 *   404.012 — unknown or expired upload                     (admin/backup/import.php)
 *   404.013 — uploaded file is missing                      (admin/backup/import.php)
 *   404.014 — snapshot not found                            (admin/snapshots/download.php)
 *
 *   405.001 — method not allowed                            (api/*.php, admin/*.php)
 *
 *   409.001 — keyword with that name already exists         (api/keyword-canvas.php)
 *   409.002 — a relation between these keywords already exists (api/keyword-canvas.php)
 *
 *   429.001 — too many keyword moves (per-editor rate limit) (api/keyword-canvas.php)
 *
 *   500.001 — internal server error                         (api/*.php, admin/*.php)
 *   500.002 — database error                                (api/*.php)
 *   500.003 — failed to create upload directory             (api/nodes.php)
 *   500.004 — failed to save uploaded file                  (api/nodes.php)
 *   500.005 — failed to save uploaded image                 (api/nodes.php)
 *   500.006 — failed to save uploaded icon                  (api/nodes.php)
 *   500.007 — failed to save uploaded audio                 (api/nodes.php)
 *   500.008 — failed to save uploaded video                 (api/nodes.php)
 *   500.009 — failed to save uploaded pdf                   (api/nodes.php)
 *   500.010 — could not extract a frame from the video      (api/nodes.php)
 *   500.011 — file does not look like a valid pdf           (api/nodes.php)
 *   500.012 — failed to create node                         (api/nodes.php)
 *   500.013 — failed to encode animation data               (api/nodes.php)
 *   500.014 — failed to encode json data                    (api/nodes.php)
 *   500.015 — could not save uploaded file (backup)         (admin/backup/import.php)
 *
 *   502.001 — failed to reach upstream Mocambos API         (inc/bridges/mocambos/handler.php)
 *
 * When adding a new error: append the next subcode within the appropriate
 * status class, add a row above, add the `api_error_<status>_<subcode>`
 * key to PROJECT_INFO_KEYS, add EN/ES/PT defaults to db_default_project_info_rows().
 */

/**
 * Emit a Problem Details error response and exit.
 *
 * @param string $code   The full code, e.g. '404.001'. Must match a row above and
 *                       a key 'api_error_<status>_<subcode>' in PROJECT_INFO_KEYS.
 * @param string $fallback Fallback English string used only if the key is missing
 *                         from the project_info table. When even the fallback is
 *                         missing, the code itself is shown.
 * @param array  $args   Optional sprintf args for templates with %s / %d. The args
 *                       are NOT localized; they are inserted into the localized
 *                       template verbatim (so caller-supplied dynamic values like
 *                       parser-error strings or bridge names pass through).
 * @param array  $extra  Optional extra fields merged into the response (e.g. a
 *                       `detail` field for occurrence-specific context).
 */
function api_error(string $code, string $fallback, array $args = [], array $extra = []): never {
    [$statusStr, $subcode] = array_pad(explode('.', $code, 2), 2, '');
    $status = (int)$statusStr;
    if ($status < 100 || $status > 599) {
        $status = 500;
    }

    $key = 'api_error_' . $statusStr . '_' . $subcode;
    // Decolonial-identifier fallback: when the locale row is missing,
    // surface the locale-invariant code itself (e.g. "404.001"), not
    // an English source string. t() returns the key when the locale
    // row is missing; we detect that and substitute the code.
    if (function_exists('locale_init_strings')) {
        $strings = locale_init_strings();
        $localized = $strings[$key] ?? '';
    } else {
        $localized = '';
    }
    $title = $localized !== '' ? (string)$localized : $code;
    if (!empty($args)) {
        $title = vsprintf($title, $args);
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/problem+json; charset=utf-8');
    }

    $envelope = array_merge([
        'ok'     => false,
        'type'   => '/errors/' . $code,
        'status' => $status,
        'code'   => $code,
        'title'  => $title,
    ], $extra);

    echo json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
