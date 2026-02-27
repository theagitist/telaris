<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';

// Set CORS headers for API responses — restrict to same origin
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-HTTP-Method-Override');

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Require API key authentication
requireApiKey();

/** Allowed node_type values (must match nodes.node_type ENUM). */
const NODE_TYPE_VALUES = ['object', 'portal'];

/** Allowed MIME types per upload category. */
const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const ALLOWED_AUDIO_MIMES = ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4', 'audio/aac', 'audio/webm', 'audio/x-m4a'];
const ALLOWED_VIDEO_MIMES = ['video/mp4'];

/** Safe extensions derived from MIME type — avoids trusting client-supplied filenames. */
const MIME_TO_EXT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'audio/mpeg' => 'mp3',
    'audio/ogg'  => 'ogg',
    'audio/wav'  => 'wav',
    'audio/mp4'  => 'm4a',
    'audio/aac'  => 'aac',
    'audio/webm' => 'webm',
    'audio/x-m4a' => 'm4a',
    'video/mp4'  => 'mp4',
];

/** Maximum upload sizes. */
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;   // 10 MB
const MAX_AUDIO_BYTES = 50 * 1024 * 1024;   // 50 MB
const MAX_VIDEO_BYTES = 200 * 1024 * 1024;  // 200 MB

/**
 * Sanitize node_type from request data; return one of NODE_TYPE_VALUES or 'object'.
 */
function sanitizeNodeType(mixed $value): string {
    $s = is_string($value) ? trim($value) : '';
    return in_array($s, NODE_TYPE_VALUES, true) ? $s : 'object';
}

/**
 * Parse target_constellation_id from request data as nullable integer.
 */
function parseTargetConstellationId(mixed $value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $id = (int)$value;
        return $id >= 0 ? $id : null;
    }
    return null;
}

/**
 * Sanitize embed_code: only allow <iframe> tags with safe attributes.
 * Strips all other HTML tags and dangerous attributes (onload, onerror, etc.).
 * Returns sanitized HTML string, or null if input produces no valid output.
 */
function sanitizeEmbedCode(string $html): ?string {
    $html = trim($html);
    if ($html === '') {
        return null;
    }

    // Allowed iframe attributes (src must be http/https)
    $allowedAttrs = [
        'src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen',
        'title', 'loading', 'referrerpolicy', 'sandbox', 'style', 'class',
    ];

    // Parse with DOMDocument
    $dom = new DOMDocument();
    // Suppress warnings from malformed HTML; wrap in a root element
    @$dom->loadHTML('<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);

    $output = '';
    $iframes = $dom->getElementsByTagName('iframe');

    for ($i = 0; $i < $iframes->length; $i++) {
        $iframe = $iframes->item($i);

        // Validate src attribute — must be http/https
        $src = $iframe->getAttribute('src');
        if ($src !== '') {
            $scheme = strtolower((string)(parse_url($src, PHP_URL_SCHEME) ?? ''));
            if (!in_array($scheme, ['http', 'https'], true)) {
                continue; // Skip iframes with dangerous src schemes
            }
        }

        // Rebuild iframe with only allowed attributes
        $safeIframe = $dom->createElement('iframe');
        foreach ($allowedAttrs as $attr) {
            if ($iframe->hasAttribute($attr)) {
                $safeIframe->setAttribute($attr, $iframe->getAttribute($attr));
            }
        }
        // Always add allowfullscreen if it was present (boolean attribute)
        if ($iframe->hasAttribute('allowfullscreen')) {
            $safeIframe->setAttribute('allowfullscreen', '');
        }

        $tempDoc = new DOMDocument();
        $imported = $tempDoc->importNode($safeIframe, true);
        $tempDoc->appendChild($imported);
        $output .= trim($tempDoc->saveHTML());
    }

    return $output !== '' ? $output : null;
}

/**
 * Validate URL: must be a valid URL with http or https scheme only.
 * Blocks javascript:, data:, vbscript:, and other dangerous schemes.
 */
function validateSafeUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    return in_array($scheme, ['http', 'https'], true);
}

/**
 * Validate an uploaded file: check MIME type against allowlist and enforce size limit.
 * Sets $detectedMime to the actual MIME type on success.
 * Returns null on success, or an error string on failure.
 */
function validateUploadedFile(array $file, array $allowedMimes, int $maxBytes, string &$detectedMime = ''): ?string {
    if ($file['size'] > $maxBytes) {
        $mb = round($maxBytes / (1024 * 1024));
        return "File exceeds maximum allowed size ({$mb}MB)";
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string)$finfo->file($file['tmp_name']);
    if (!in_array($detectedMime, $allowedMimes, true)) {
        return "File type not allowed";
    }
    return null;
}

/**
 * If the current session belongs to an editor (not admin), verify they have access
 * to the given constellation. Returns an error message or null on success.
 */
function checkEditorConstellationAccess(int $constellationId): ?string {
    if (!isEditorOrAdminLoggedIn()) {
        return null; // API-key-only callers are not session-restricted
    }
    if (isAdminLoggedIn()) {
        return null; // Admins have access to all constellations
    }
    $userId = $_SESSION['admin_user_id'] ?? null;
    if (!$userId) {
        return null;
    }
    $allowed = db_get_constellations_for_user($userId, false);
    $allowedIds = array_column($allowed, 'id');
    if (!in_array($constellationId, $allowedIds, true)) {
        return 'Access denied to this constellation';
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) === 'PUT') {
    $method = 'PUT';
}

try {
    match ($method) {
        'GET' => (function(): void {
            $currentUserId = $_SESSION['admin_user_id'] ?? null;
            $isAdmin = isAdminLoggedIn();

            $constellationId = null;
            if (isset($_GET['constellation_id'])) {
                if ($_GET['constellation_id'] === 'all') {
                    $constellationId = null; // all nodes (respecting user access)
                } elseif (ctype_digit((string)$_GET['constellation_id'])) {
                    $constellationId = (int) $_GET['constellation_id'];
                }
            }
            if ($constellationId === null && !isset($_GET['constellation_id'])) {
                $constellationId = db_get_default_constellation_id(); // main view without param: show default constellation only
            }
            $nodes = db_get_nodes($constellationId, $currentUserId, $isAdmin);
            $formatted = array_map(fn($node) => db_format_node($node), $nodes);
            echo json_encode($formatted, JSON_THROW_ON_ERROR);
        })(),

        'POST' => (function(): void {
            requireWriteAccess();
            $data = $_POST;
            if (empty($data) && empty($_FILES)) {
                $input = stream_get_contents(fopen('php://input', 'r'), 1048576);
                if (!empty($input)) {
                    $data = json_decode($input, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
            }
            if (empty($data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name is required'], JSON_THROW_ON_ERROR);
                return;
            }
            try {
                $animationData = (isset($data['animation'])) ? (is_array($data['animation']) ? $data['animation'] : json_decode($data['animation'], true)) : [];
                $animationArray = [
                    'radius' => isset($animationData['radius']) ? (float)$animationData['radius'] : (5 + rand(0, 3)),
                    'theta'  => isset($animationData['theta'])  ? (float)$animationData['theta']  : (rand(0, 628) / 100),
                    'phi'    => isset($animationData['phi'])    ? (float)$animationData['phi']    : (rand(0, 314) / 100),
                    'speed'  => isset($animationData['speed'])  ? (float)$animationData['speed']  : (0.002 + (rand(0, 4) / 1000)),
                    'phase'  => isset($animationData['phase'])  ? (float)$animationData['phase']  : (rand(0, 628) / 100),
                ];
                $animation = json_encode($animationArray, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to encode animation data'], JSON_THROW_ON_ERROR);
                return;
            }
            if (!is_string($animation)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to encode JSON data'], JSON_THROW_ON_ERROR);
                return;
            }
            $description = null;
            if (isset($data['description']) && !empty(trim((string)$data['description']))) {
                $description = trim((string)$data['description']);
            }
            $nodeType = sanitizeNodeType($data['node_type'] ?? 'object');
            $url = null;
            if (isset($data['url']) && !empty(trim((string)$data['url']))) {
                $url = trim((string)$data['url']);
                if (!validateSafeUrl($url)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL: only http and https URLs are allowed'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            $name = trim((string)$data['name']);
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Node name cannot be empty'], JSON_THROW_ON_ERROR);
                return;
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : db_get_default_constellation_id();

            // Enforce editor constellation access
            $accessError = checkEditorConstellationAccess($constellationId);
            if ($accessError !== null) {
                http_response_code(403);
                echo json_encode(['error' => $accessError], JSON_THROW_ON_ERROR);
                return;
            }

            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            if ($targetConstellationId !== null && db_get_constellation_by_id($targetConstellationId) === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Target constellation does not exist'], JSON_THROW_ON_ERROR);
                return;
            }

            $imageUrl = (isset($data['image_url']) && !empty(trim((string)$data['image_url']))) ? trim((string)$data['image_url']) : null;
            $embedCode = (isset($data['embed_code']) && !empty(trim((string)$data['embed_code']))) ? sanitizeEmbedCode((string)$data['embed_code']) : null;
            $audioUrl = (isset($data['audio_url']) && !empty(trim((string)$data['audio_url']))) ? trim((string)$data['audio_url']) : null;
            $audioAutoplay = isset($data['audio_autoplay']) ? (bool)$data['audio_autoplay'] : true;
            $videoUrl = (isset($data['video_url']) && !empty(trim((string)$data['video_url']))) ? trim((string)$data['video_url']) : null;
            $videoAutoplay = isset($data['video_autoplay']) ? (bool)$data['video_autoplay'] : true;
            $isAccentuated = isset($data['is_accentuated']) ? (bool)$data['is_accentuated'] : false;

            // Mutual exclusivity: uploaded files take precedence; otherwise URL values decide
            if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
                $audioUrl = null;
            } elseif (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                $videoUrl = null;
            } elseif ($videoUrl) {
                $audioUrl = null;
            } elseif ($audioUrl) {
                $videoUrl = null;
            }

            $nodeId = db_create_node($name, $description, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay);
            if ($nodeId === 0) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create node: Could not retrieve node ID'], JSON_THROW_ON_ERROR);
                return;
            }

            $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
            $nodeRelDir = "uploads/{$constellationId}/{$nodeId}";
            $nodeFullDir = "{$uploadDir}/{$constellationId}/{$nodeId}";
            if (!is_dir($nodeFullDir)) {
                if (!mkdir($nodeFullDir, 0755, true)) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create upload directory. Check server permissions.'], JSON_THROW_ON_ERROR);
                    return;
                }
            }

            $uploadedFiles = false;
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['image_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                    return;
                }
                $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                $imageRelPath = "{$nodeRelDir}/image.{$ext}";
                $imageFullPath = "{$nodeFullDir}/image.{$ext}";
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $imageFullPath)) {
                    $imageUrl = $imageRelPath;
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to save uploaded image'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['audio_file'], ALLOWED_AUDIO_MIMES, MAX_AUDIO_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                    return;
                }
                $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                $audioRelPath = "{$nodeRelDir}/audio.{$ext}";
                $audioFullPath = "{$nodeFullDir}/audio.{$ext}";
                if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $audioFullPath)) {
                    $audioUrl = $audioRelPath;
                    $videoUrl = null; // Ensure exclusivity
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to save uploaded audio'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
                $detectedMime = '';
                $err = validateUploadedFile($_FILES['video_file'], ALLOWED_VIDEO_MIMES, MAX_VIDEO_BYTES, $detectedMime);
                if ($err !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                    return;
                }
                $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                $videoRelPath = "{$nodeRelDir}/video.{$ext}";
                $videoFullPath = "{$nodeFullDir}/video.{$ext}";
                if (move_uploaded_file($_FILES['video_file']['tmp_name'], $videoFullPath)) {
                    $videoUrl = $videoRelPath;
                    $audioUrl = null; // Ensure exclusivity
                    $uploadedFiles = true;
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to save uploaded video'], JSON_THROW_ON_ERROR);
                    return;
                }
            }

            if ($uploadedFiles) {
                db_update_node($nodeId, $name, $description, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay);
            }

            if (isset($data['keywords'])) {
                $keywords = is_array($data['keywords']) ? $data['keywords'] : explode(',', (string)$data['keywords']);
                db_save_node_keywords($nodeId, $keywords);
            }
            echo json_encode(['id' => $nodeId, 'success' => true], JSON_THROW_ON_ERROR);
        })(),

        'PUT' => (function(): void {
            requireWriteAccess();
            $input = stream_get_contents(fopen('php://input', 'r'), 1048576);
            $data = json_decode($input, true);

            // Handle multipart/form-data for PUT (some clients use POST + _method=PUT or just POST for uploads)
            if (empty($data) && !empty($_POST)) {
                $data = $_POST;
            }

            $id = $data['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
                return;
            }
            $animation = (isset($data['animation'])) ? (is_array($data['animation']) ? json_encode($data['animation'], JSON_THROW_ON_ERROR) : $data['animation']) : null;
            $nodeType = sanitizeNodeType($data['node_type'] ?? 'object');
            $url = null;
            if (isset($data['url']) && !empty(trim((string)$data['url']))) {
                $url = trim((string)$data['url']);
                if (!validateSafeUrl($url)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid URL: only http and https URLs are allowed'], JSON_THROW_ON_ERROR);
                    return;
                }
            }
            $constellationId = isset($data['constellation_id']) ? (int)$data['constellation_id'] : null;

            // Enforce editor constellation access — use provided or look up existing
            $accessConstellationId = $constellationId;
            if ($accessConstellationId === null) {
                $accessConstellationId = db_get_node_constellation_id((int)$id);
            }
            if ($accessConstellationId !== null) {
                $accessError = checkEditorConstellationAccess($accessConstellationId);
                if ($accessError !== null) {
                    http_response_code(403);
                    echo json_encode(['error' => $accessError], JSON_THROW_ON_ERROR);
                    return;
                }
            }

            $targetConstellationId = parseTargetConstellationId($data['target_constellation_id'] ?? null);
            if ($targetConstellationId !== null && db_get_constellation_by_id($targetConstellationId) === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Target constellation does not exist'], JSON_THROW_ON_ERROR);
                return;
            }

            $imageUrl = (isset($data['image_url']) && !empty(trim((string)$data['image_url']))) ? trim((string)$data['image_url']) : null;
            $embedCode = (isset($data['embed_code']) && !empty(trim((string)$data['embed_code']))) ? sanitizeEmbedCode((string)$data['embed_code']) : null;
            $audioUrl = (isset($data['audio_url']) && !empty(trim((string)$data['audio_url']))) ? trim((string)$data['audio_url']) : null;
            $audioAutoplay = isset($data['audio_autoplay']) ? (bool)$data['audio_autoplay'] : true;
            $videoUrl = (isset($data['video_url']) && !empty(trim((string)$data['video_url']))) ? trim((string)$data['video_url']) : null;
            $videoAutoplay = isset($data['video_autoplay']) ? (bool)$data['video_autoplay'] : true;
            $isAccentuated = isset($data['is_accentuated']) ? (bool)$data['is_accentuated'] : false;

            // Mutual exclusivity logic for PUT
            $hasAudioFile = isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK;
            $hasVideoFile = isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK;

            if ($hasVideoFile) {
                $audioUrl = null;
            } elseif ($hasAudioFile) {
                $videoUrl = null;
            } elseif ($videoUrl && $videoUrl !== (db_get_nodes((int)$id, null, true)[0]['video_url'] ?? null)) {
                // If a new video URL is provided, clear audio
                $audioUrl = null;
            } elseif ($audioUrl && $audioUrl !== (db_get_nodes((int)$id, null, true)[0]['audio_url'] ?? null)) {
                // If a new audio URL is provided, clear video
                $videoUrl = null;
            }

            // Handle file uploads for PUT
            if ($constellationId !== null) {
                $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
                $nodeRelDir = "uploads/{$constellationId}/{$id}";
                $nodeFullDir = "{$uploadDir}/{$constellationId}/{$id}";
                if (!is_dir($nodeFullDir)) {
                    if (!mkdir($nodeFullDir, 0755, true)) {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to create upload directory. Check server permissions.'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }

                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['image_file'], ALLOWED_IMAGE_MIMES, MAX_IMAGE_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                        return;
                    }
                    $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                    $imageRelPath = "{$nodeRelDir}/image.{$ext}";
                    $imageFullPath = "{$nodeFullDir}/image.{$ext}";
                    if (move_uploaded_file($_FILES['image_file']['tmp_name'], $imageFullPath)) {
                        $imageUrl = $imageRelPath;
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to save uploaded image'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                if ($hasAudioFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['audio_file'], ALLOWED_AUDIO_MIMES, MAX_AUDIO_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                        return;
                    }
                    $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                    $audioRelPath = "{$nodeRelDir}/audio.{$ext}";
                    $audioFullPath = "{$nodeFullDir}/audio.{$ext}";
                    if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $audioFullPath)) {
                        $audioUrl = $audioRelPath;
                        $videoUrl = null; // Enforce exclusivity
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to save uploaded audio'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
                if ($hasVideoFile) {
                    $detectedMime = '';
                    $err = validateUploadedFile($_FILES['video_file'], ALLOWED_VIDEO_MIMES, MAX_VIDEO_BYTES, $detectedMime);
                    if ($err !== null) {
                        http_response_code(400);
                        echo json_encode(['error' => $err], JSON_THROW_ON_ERROR);
                        return;
                    }
                    $ext = MIME_TO_EXT[$detectedMime] ?? 'bin';
                    $videoRelPath = "{$nodeRelDir}/video.{$ext}";
                    $videoFullPath = "{$nodeFullDir}/video.{$ext}";
                    if (move_uploaded_file($_FILES['video_file']['tmp_name'], $videoFullPath)) {
                        $videoUrl = $videoRelPath;
                        $audioUrl = null; // Enforce exclusivity
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to save uploaded video'], JSON_THROW_ON_ERROR);
                        return;
                    }
                }
            }

            db_update_node((int)$id, $data['name'], $data['description'] ?? null, $url, $animation, $constellationId, $nodeType, $targetConstellationId, $imageUrl, $embedCode, $audioUrl, $audioAutoplay, $isAccentuated, $videoUrl, $videoAutoplay);
            if (isset($data['keywords'])) {
                $keywords = is_array($data['keywords']) ? $data['keywords'] : explode(',', (string)$data['keywords']);
                db_save_node_keywords((int)$id, $keywords);
            }
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),

        'DELETE' => (function(): void {
            requireWriteAccess();
            $id = $_GET['id'] ?? null;
            $fileType = $_GET['file_type'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Node ID required'], JSON_THROW_ON_ERROR);
                return;
            }

            // Enforce editor constellation access on delete
            if (isEditorLoggedIn()) {
                $userId = $_SESSION['admin_user_id'] ?? null;
                if ($userId) {
                    $nodeConstellationId = db_get_node_constellation_id((int)$id);
                    if ($nodeConstellationId !== null) {
                        $allowed = db_get_constellations_for_user($userId, false);
                        $allowedIds = array_column($allowed, 'id');
                        if (!in_array($nodeConstellationId, $allowedIds, true)) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Access denied to this constellation'], JSON_THROW_ON_ERROR);
                            return;
                        }
                    }
                }
            }

            if ($fileType && in_array($fileType, ['image', 'audio', 'video'])) {
                db_delete_node_file((int)$id, $fileType);
                echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
                return;
            }
            db_delete_node((int)$id);
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
        })(),

        default => throw new RuntimeException('Method not allowed', 405)
    };
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error'], JSON_THROW_ON_ERROR);
} catch (RuntimeException $e) {
    http_response_code($e->getCode() ?: 405);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
