<?php
declare(strict_types=1);

/**
 * Backstage chat API — for logged-in authors (editors) and admins only. Not
 * visitor-facing: BOTH reads and writes require an editor/admin session.
 *
 * Rooms: a singleton instance-wide room every editor/admin sees, plus private
 * 1:1 DMs and named subset groups gated by membership (admins do NOT auto-join).
 *
 * Moderation: message/room delete, leave group, report a user (sampled +
 * emailed to all admins), and timed/indefinite bans (admin only).
 *
 *  GET  ?action=rooms | directory | reports(admin) | [messages] ?room&since
 *  POST ?action=send | create_dm | create_group
 *       ?action=delete_message {id} | delete_room {room} | leave_room {room}
 *       ?action=report_user {user_id, room, reason}
 *       ?action=ban_user {user_id, days?} | unban_user {user_id} | close_report {id}  (admin)
 *
 * ponytail: short-poll, no websockets; one active ban row per user.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../utils/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/api-error.php';
require_once __DIR__ . '/../inc/mail.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json'); header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Access-Control-Allow-Origin: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-CSRF-Token');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
}

requireApiKey();

const CHAT_BODY_MAX = 4000;
const CHAT_GROUP_NAME_MAX = 120;
const CHAT_REASON_MAX = 1000;

function chat_row_out(array $r): array {
    $out = [
        'id'          => (int)$r['id'],
        'room_id'     => isset($r['room_id']) ? (int)$r['room_id'] : 0,
        'author_id'   => isset($r['author_id']) && $r['author_id'] !== null ? (string)$r['author_id'] : '',
        'author_name' => (string)$r['author_name'],
        'author_type' => (int)$r['author_type'],
        'body'        => (string)$r['body'],
        'created_at'  => gmdate('c', strtotime((string)$r['created_at'])),
    ];
    if (!empty($r['attachment_path'])) {
        $out['attachment'] = [
            'type' => (string)($r['attachment_type'] ?? ''),
            'mime' => (string)($r['attachment_mime'] ?? ''),
            'name' => (string)($r['attachment_name'] ?? ''),
            'size' => (int)($r['attachment_size'] ?? 0),
        ];
    }
    return $out;
}

/** Validate an uploaded chat attachment by sniffed MIME + size cap. Returns
 *  ['type','mime','ext','size'] or exits via api_error. $kindHint ('voice')
 *  lets a webm/ogg voice note be stored as audio even if the container sniffs
 *  as video (MediaRecorder quirk); the hint only affects rendering, not the
 *  security allowlist. */
function chat_validate_upload(array $file, string $kindHint = ''): array {
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        api_error('400.001', 'No file was uploaded.');
    }
    $size = (int)($file['size'] ?? 0);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $map = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'],
        'image/gif'  => ['image', 'gif'], 'image/webp' => ['image', 'webp'],
        'video/mp4'  => ['video', 'mp4'], 'video/webm' => ['video', 'webm'], 'video/ogg' => ['video', 'ogv'],
        'audio/webm' => ['audio', 'webm'], 'audio/ogg' => ['audio', 'ogg'],
        'audio/mpeg' => ['audio', 'mp3'], 'audio/mp4' => ['audio', 'm4a'], 'audio/wav' => ['audio', 'wav'],
    ];
    if (!isset($map[$mime])) api_error('400.001', 'Unsupported file type.');
    [$type, $ext] = $map[$mime];
    if ($kindHint === 'voice') {
        // Reclassify a container that is really a voice note.
        if ($mime === 'video/webm' || $mime === 'audio/webm') { $type = 'audio'; $mime = 'audio/webm'; $ext = 'webm'; }
        elseif ($mime === 'video/ogg' || $mime === 'audio/ogg') { $type = 'audio'; $mime = 'audio/ogg'; $ext = 'ogg'; }
    }
    $caps = ['image' => 5 * 1024 * 1024, 'video' => 25 * 1024 * 1024, 'audio' => 10 * 1024 * 1024];
    if ($size <= 0 || $size > $caps[$type]) api_error('413.001', 'File is too large.');
    return ['type' => $type, 'mime' => $mime, 'ext' => $ext, 'size' => $size];
}

function chat_json($payload): void {
    try { echo json_encode($payload, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { api_error('500.001', 'Could not encode response.'); }
    exit;
}

function chat_input(): array {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return $_POST;
}

/** Notify every admin of a new report. Best-effort: a mail failure never fails
 *  the request (the report is already stored and shows in the admin surface). */
function chat_notify_admins_of_report(array $report): void {
    if (!mail_is_configured()) return;
    $recipients = chat_admin_recipients();
    if (!$recipients) return;
    $reported = $report['reported_name'] ?? $report['reported_user_id'];
    $reporter = $report['reporter_name'] ?? '';
    $reason   = $report['reason'] ?? '';
    $lines = [];
    foreach (($report['sample'] ?? []) as $s) {
        $lines[] = '- ' . (string)($s['body'] ?? '');
    }
    $sampleText = $lines ? implode("\n", $lines) : '(no recent messages)';
    $subject = 'Backstage chat: user reported';
    $text = "A user was reported in the backstage chat.\n\n"
          . "Reported: {$reported}\nBy: {$reporter}\n"
          . ($reason !== '' ? "Reason: {$reason}\n" : '')
          . "\nRecent messages:\n{$sampleText}\n\n"
          . "Review and act in the chat panel's Reports view.";
    $html = '<p>A user was reported in the backstage chat.</p>'
          . '<p><strong>Reported:</strong> ' . htmlspecialchars((string)$reported, ENT_QUOTES, 'UTF-8') . '<br>'
          . '<strong>By:</strong> ' . htmlspecialchars((string)$reporter, ENT_QUOTES, 'UTF-8') . '</p>'
          . ($reason !== '' ? '<p><strong>Reason:</strong> ' . htmlspecialchars((string)$reason, ENT_QUOTES, 'UTF-8') . '</p>' : '')
          . '<p><strong>Recent messages:</strong></p><ul>'
          . implode('', array_map(function ($s) {
                return '<li>' . htmlspecialchars((string)($s['body'] ?? ''), ENT_QUOTES, 'UTF-8') . '</li>';
            }, $report['sample'] ?? []))
          . '</ul><p>Review and act in the chat panel\'s Reports view.</p>';
    foreach ($recipients as $r) {
        try { mail_send($r['email'], $subject, $html, $text, $r['name'] ?? null); }
        catch (\Throwable $e) { error_log('chat report mail: ' . $e->getMessage()); }
    }
}

// Backstage: reads are gated too. Refuse anyone who is not a logged-in editor/admin.
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isEditorOrAdminLoggedIn()) {
    api_error('403.002', 'Backstage chat is for logged-in authors and admins.');
}

$meId   = (string)($_SESSION['admin_user_id'] ?? '');
$myName = trim((string)($_SESSION['admin_user_name'] ?? '')) ?: 'User';
$myType = (int)($_SESSION['admin_user_type'] ?? 0);
$isAdmin = $myType === USER_TYPE_ADMIN;
$action = (string)($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// Ban gate. Admins are never banned. A banned user gets a friendly state on the
// rooms poll and a hard 403 on everything else.
$ban = $isAdmin ? null : chat_active_ban($meId);
if ($ban !== null) {
    if ($method === 'GET' && $action === 'rooms') {
        chat_json([
            'banned' => true,
            'until'  => $ban['expires_at'] !== null ? gmdate('c', strtotime((string)$ban['expires_at'])) : null,
            'reason' => $ban['reason'] !== null ? (string)$ban['reason'] : '',
            'rooms'  => [],
        ]);
    }
    api_error('403.002', 'You are banned from the backstage chat.');
}

function chat_require_admin(bool $isAdmin): void {
    if (!$isAdmin) api_error('403.002', 'Admins only.');
}

if ($method === 'GET') {
    if ($action === 'rooms') {
        chat_json(['rooms' => chat_rooms_for_user($meId, $isAdmin)]);
    }
    if ($action === 'directory') {
        $dir = array_values(array_filter(chat_directory(), fn($u) => $u['id'] !== $meId));
        chat_json(['users' => $dir]);
    }
    if ($action === 'reports') {
        chat_require_admin($isAdmin);
        chat_json(['reports' => chat_reports_open()]);
    }
    if ($action === 'media') {
        // Gated binary reader for an attachment. <img>/<video>/<audio> can't send
        // headers, so the api key rides in ?api_key= (already client-known) and the
        // session cookie carries identity for the membership check.
        $mid = (int)($_GET['id'] ?? 0);
        $msg = $mid > 0 ? chat_get_message($mid) : null;
        if (!$msg || empty($msg['attachment_path'])) api_error('404.001', 'Attachment not found.');
        if (!chat_user_in_room($meId, (int)$msg['room_id'])) api_error('403.002', 'You are not a member of that conversation.');
        $abs = chat_media_abs_path((string)$msg['attachment_path']);
        if ($abs === null || !is_file($abs)) api_error('404.001', 'Attachment file is missing.');
        $fn = (string)($msg['attachment_name'] ?? 'file');
        $fn = str_replace(['"', "\r", "\n"], '', $fn);
        $disp = (isset($_GET['dl']) && $_GET['dl']) ? 'attachment' : 'inline';
        header_remove('Content-Type');
        header('Content-Type: ' . (($msg['attachment_mime'] ?? '') ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: ' . $disp . '; filename="' . $fn . '"');
        header('Cache-Control: private, max-age=300');
        readfile($abs);
        exit;
    }
    // default: messages
    $roomId = isset($_GET['room']) ? (int)$_GET['room'] : 0;
    if ($roomId <= 0) $roomId = chat_instance_room_id();
    if (!chat_user_in_room($meId, $roomId)) api_error('403.002', 'You are not a member of that conversation.');
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $rows = chat_fetch_since($roomId, $since > 0 ? $since : 0);
    chat_json(['room_id' => $roomId, 'messages' => array_map('chat_row_out', $rows)]);
}

if ($method !== 'POST') api_error('405.001', 'Method not allowed.');

// Writes: session role (1/2) + CSRF.
requireWriteAccess();

if ($action === 'upload') {
    // Multipart: a single image/video/voice attachment (+ optional caption).
    $roomId = isset($_POST['room']) ? (int)$_POST['room'] : 0;
    if ($roomId <= 0) $roomId = chat_instance_room_id();
    if (!chat_user_in_room($meId, $roomId)) api_error('403.002', 'You are not a member of that conversation.');
    $v = chat_validate_upload($_FILES['file'] ?? [], (string)($_POST['kind'] ?? ''));
    $subdir = chat_media_dir() . '/' . $roomId;
    if (!is_dir($subdir)) @mkdir($subdir, 02775, true);
    $name = bin2hex(random_bytes(16)) . '.' . $v['ext'];
    $dest = $subdir . '/' . $name;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) api_error('500.001', 'Could not store the file.');
    @chmod($dest, 0664);
    $orig = basename((string)($_FILES['file']['name'] ?? ('file.' . $v['ext'])));
    if (mb_strlen($orig) > 200) $orig = mb_substr($orig, 0, 200);
    $body = trim((string)($_POST['body'] ?? ''));
    if (mb_strlen($body) > CHAT_BODY_MAX) $body = mb_substr($body, 0, CHAT_BODY_MAX);
    $row = chat_insert($roomId, $meId !== '' ? $meId : null, $myName, $myType, $body, [
        'path' => $roomId . '/' . $name, 'type' => $v['type'], 'mime' => $v['mime'], 'name' => $orig, 'size' => $v['size'],
    ]);
    chat_json(['message' => chat_row_out($row)]);
}

$input = chat_input();

if ($action === 'create_dm') {
    $other = trim((string)($input['user_id'] ?? ''));
    if ($other === '' || $other === $meId) api_error('400.001', 'Pick another author or admin to message.');
    $known = false;
    foreach (chat_directory() as $u) { if ($u['id'] === $other) { $known = true; break; } }
    if (!$known) api_error('400.001', 'Unknown recipient.');
    chat_json(['room_id' => chat_find_or_create_dm($meId, $other)]);
}

if ($action === 'create_group') {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') api_error('400.001', 'A group name is required.');
    if (mb_strlen($name) > CHAT_GROUP_NAME_MAX) api_error('413.001', 'Group name is too long.');
    $members = $input['user_ids'] ?? [];
    if (!is_array($members)) $members = [];
    chat_json(['room_id' => chat_create_group($meId, $name, $members)]);
}

if ($action === 'delete_message') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) api_error('400.001', 'A message id is required.');
    if (!chat_delete_message($id, $meId, $isAdmin)) api_error('403.002', 'You cannot delete that message.');
    chat_json(['ok' => true, 'id' => $id]);
}

if ($action === 'delete_room') {
    $roomId = (int)($input['room'] ?? 0);
    if ($roomId <= 0) api_error('400.001', 'A room id is required.');
    if (!chat_delete_room($roomId, $meId, $isAdmin)) api_error('403.002', 'You cannot delete that conversation.');
    chat_json(['ok' => true]);
}

if ($action === 'leave_room') {
    $roomId = (int)($input['room'] ?? 0);
    if ($roomId <= 0) api_error('400.001', 'A room id is required.');
    if (!chat_leave_room($roomId, $meId)) api_error('403.002', 'You cannot leave that conversation.');
    chat_json(['ok' => true]);
}

if ($action === 'report_user') {
    $reported = trim((string)($input['user_id'] ?? ''));
    $roomId = (int)($input['room'] ?? 0);
    if ($roomId <= 0) $roomId = chat_instance_room_id();
    if ($reported === '' || $reported === $meId) api_error('400.001', 'Pick someone else to report.');
    if (!in_array((int)(chat_user_type($reported) ?? -1), [1, 2], true)) api_error('400.001', 'Unknown user.');
    if (!chat_user_in_room($meId, $roomId)) api_error('403.002', 'You are not a member of that conversation.');
    $reason = trim((string)($input['reason'] ?? ''));
    if (mb_strlen($reason) > CHAT_REASON_MAX) $reason = mb_substr($reason, 0, CHAT_REASON_MAX);
    $report = chat_create_report($reported, $meId, $myName, $roomId, $reason !== '' ? $reason : null);
    chat_notify_admins_of_report($report);
    chat_json(['ok' => true]);
}

if ($action === 'ban_user') {
    chat_require_admin($isAdmin);
    $target = trim((string)($input['user_id'] ?? ''));
    if ($target === '' || $target === $meId) api_error('400.001', 'Pick a user to ban.');
    $t = chat_user_type($target);
    if ($t === null) api_error('400.001', 'Unknown user.');
    if ($t === USER_TYPE_ADMIN) api_error('403.002', 'Admins cannot be banned.');
    $days = isset($input['days']) && $input['days'] !== '' ? (int)$input['days'] : null; // null/<=0 = indefinite
    $reason = trim((string)($input['reason'] ?? ''));
    chat_ban_user($target, $days, $meId, $reason !== '' ? $reason : null);
    chat_json(['ok' => true]);
}

if ($action === 'unban_user') {
    chat_require_admin($isAdmin);
    $target = trim((string)($input['user_id'] ?? ''));
    if ($target === '') api_error('400.001', 'A user id is required.');
    chat_unban_user($target);
    chat_json(['ok' => true]);
}

if ($action === 'close_report') {
    chat_require_admin($isAdmin);
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) api_error('400.001', 'A report id is required.');
    chat_close_report($id);
    chat_json(['ok' => true]);
}

// default: send a message
$roomId = isset($input['room']) ? (int)$input['room'] : 0;
if ($roomId <= 0) $roomId = chat_instance_room_id();
if (!chat_user_in_room($meId, $roomId)) api_error('403.002', 'You are not a member of that conversation.');
$body = trim((string)($input['body'] ?? ''));
if ($body === '') api_error('400.001', 'A message body is required.');
if (mb_strlen($body) > CHAT_BODY_MAX) api_error('413.001', 'Message is too long.');
$row = chat_insert($roomId, $meId !== '' ? $meId : null, $myName, $myType, $body);
chat_json(['message' => chat_row_out($row)]);
