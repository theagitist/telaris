<?php
/**
 * Backstage chat panel — shared by the admin console and the editor workspace.
 * Rooms for logged-in authors (editors) and admins: an instance-wide room plus
 * private DMs and subset groups.
 *
 * Self-contained: gates itself on the role, and derives its own API key, CSRF
 * token, and asset version, so it can be `include`d from any author/admin page
 * without the host page wiring anything up.
 *
 * ponytail: scoped inline CSS + one classic script, no build step, no shared
 * layout assumptions. It floats bottom-right and gets out of the way collapsed.
 */

require_once __DIR__ . '/../../utils/auth.php';
require_once __DIR__ . '/../db.php';

if (!isEditorOrAdminLoggedIn()) {
    return; // never render for visitors / regular users
}

if (session_status() === PHP_SESSION_NONE) session_start();
$bcIsAdmin = isAdminLoggedIn();
$bcMeId    = (string)($_SESSION['admin_user_id'] ?? '');
$bcCsrf    = $_SESSION['csrf_token'] ?? '';
$bcApiKey  = getDefaultApiKey(getDB());
if ($bcApiKey === null || $bcCsrf === '') {
    return; // no usable credentials; render nothing rather than a broken widget
}

$bcVersion = isset($appVersion) && $appVersion !== ''
    ? $appVersion
    : trim((string)@file_get_contents(__DIR__ . '/../../VERSION'));
$bcJsUrl = function_exists('asset_versioned_js_url')
    ? asset_versioned_js_url($bcVersion, 'backstage-chat.js')
    : '/js/backstage-chat.js?v=' . rawurlencode($bcVersion);
?>
<style>
#backstage-chat{position:fixed;right:16px;bottom:16px;width:340px;max-width:calc(100vw - 32px);z-index:2147483000;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#0f172a;}
#backstage-chat *{box-sizing:border-box;}
#backstage-chat [data-bc-header]{display:flex;align-items:center;gap:8px;cursor:pointer;background:#1e293b;color:#f8fafc;padding:10px 14px;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.25);user-select:none;font-weight:600;font-size:14px;}
#backstage-chat [data-bc-header]:hover{background:#273449;}
#backstage-chat .bc-title{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
#backstage-chat [data-bc-badge]{background:#ef4444;color:#fff;border-radius:999px;font-size:11px;line-height:1;padding:3px 6px;min-width:18px;text-align:center;}
#backstage-chat .bc-caret{transition:transform .15s ease;opacity:.8;}
#backstage-chat.bc-open .bc-caret{transform:rotate(180deg);}
#backstage-chat [data-bc-panel]{display:none;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);margin-bottom:8px;overflow:hidden;}
#backstage-chat.bc-open [data-bc-panel]{display:block;}
#backstage-chat [data-bc-rooms-bar]{display:flex;gap:8px;align-items:center;padding:8px 10px;border-bottom:1px solid #e2e8f0;background:#fff;}
#backstage-chat [data-bc-room-select]{flex:1;min-width:0;border:1px solid #cbd5e1;border-radius:8px;padding:6px 8px;font:inherit;font-size:13px;background:#fff;color:#0f172a;}
#backstage-chat [data-bc-new-btn]{border:1px solid #cbd5e1;background:#f1f5f9;border-radius:8px;padding:6px 10px;font:inherit;font-size:13px;cursor:pointer;white-space:nowrap;}
#backstage-chat [data-bc-new-btn]:hover{background:#e2e8f0;}
#backstage-chat [data-bc-new-form]{padding:10px;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;flex-direction:column;gap:8px;}
#backstage-chat .bc-kinds{display:flex;gap:14px;font-size:13px;}
#backstage-chat .bc-kinds label{display:flex;align-items:center;gap:5px;cursor:pointer;}
#backstage-chat [data-bc-dm-user],#backstage-chat [data-bc-group-name]{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:7px 9px;font:inherit;font-size:13px;background:#fff;color:#0f172a;}
#backstage-chat .bc-members-label{font-size:12px;color:#64748b;}
#backstage-chat .bc-members{max-height:120px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:4px;}
#backstage-chat .bc-check{display:flex;align-items:center;gap:7px;padding:4px 6px;font-size:13px;cursor:pointer;border-radius:6px;}
#backstage-chat .bc-check:hover{background:#f1f5f9;}
#backstage-chat .bc-dim{color:#94a3b8;font-size:12px;padding:6px;}
#backstage-chat .bc-new-actions{display:flex;gap:8px;justify-content:flex-end;}
#backstage-chat .bc-new-actions button{border:none;border-radius:8px;padding:6px 12px;font:inherit;font-size:13px;font-weight:600;cursor:pointer;}
#backstage-chat [data-bc-create]{background:#2563eb;color:#fff;}
#backstage-chat [data-bc-create]:hover{background:#1d4ed8;}
#backstage-chat [data-bc-create]:disabled{opacity:.5;cursor:default;}
#backstage-chat [data-bc-new-cancel]{background:#e2e8f0;color:#475569;}
#backstage-chat [data-bc-body]{height:300px;max-height:46vh;overflow-y:auto;padding:12px;background:#f8fafc;}
#backstage-chat .bc-empty{color:#64748b;font-size:13px;text-align:center;margin:24px 8px;line-height:1.5;}
#backstage-chat .bc-msg{margin:0 0 12px;}
#backstage-chat .bc-meta{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;font-size:12px;margin-bottom:2px;}
#backstage-chat .bc-name{font-weight:600;color:#0f172a;}
#backstage-chat .bc-role{font-size:10px;text-transform:uppercase;letter-spacing:.03em;padding:1px 5px;border-radius:4px;background:#e2e8f0;color:#475569;}
#backstage-chat .bc-role-2{background:#dbeafe;color:#1d4ed8;}
#backstage-chat .bc-time{color:#94a3b8;margin-left:auto;}
#backstage-chat .bc-text{font-size:14px;line-height:1.45;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere;}
#backstage-chat [data-bc-form]{display:flex;gap:8px;padding:10px;border-top:1px solid #e2e8f0;background:#fff;}
#backstage-chat [data-bc-input]{flex:1;resize:none;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font:inherit;font-size:14px;max-height:120px;color:#0f172a;}
#backstage-chat [data-bc-input]:focus{outline:2px solid #60a5fa;outline-offset:-1px;border-color:#60a5fa;}
#backstage-chat [data-bc-send]{border:none;background:#2563eb;color:#fff;border-radius:8px;padding:0 14px;font:inherit;font-weight:600;cursor:pointer;}
#backstage-chat [data-bc-send]:hover{background:#1d4ed8;}
#backstage-chat [data-bc-send]:disabled{opacity:.5;cursor:default;}
#backstage-chat .bc-room-actions{display:flex;gap:4px;}
#backstage-chat .bc-room-actions button{border:1px solid #cbd5e1;background:#fff;border-radius:7px;padding:5px 8px;font:inherit;font-size:12px;cursor:pointer;color:#475569;}
#backstage-chat .bc-room-actions button:hover{background:#f1f5f9;}
#backstage-chat [data-bc-reports-btn]{position:relative;}
#backstage-chat [data-bc-reports-btn] .bc-rcount{background:#ef4444;color:#fff;border-radius:999px;font-size:10px;padding:1px 5px;margin-left:4px;}
#backstage-chat .bc-msg-actions{display:inline-flex;gap:8px;margin-left:6px;}
#backstage-chat .bc-msg-actions button{border:none;background:none;padding:0;font:inherit;font-size:11px;color:#94a3b8;cursor:pointer;text-decoration:underline;}
#backstage-chat .bc-msg-actions button:hover{color:#475569;}
#backstage-chat [data-bc-banned]{padding:16px;text-align:center;color:#b91c1c;font-size:14px;line-height:1.5;}
#backstage-chat [data-bc-reports]{max-height:300px;overflow-y:auto;padding:10px;border-bottom:1px solid #e2e8f0;background:#fff8f8;}
#backstage-chat .bc-report{border:1px solid #fecaca;border-radius:8px;padding:8px;margin-bottom:8px;background:#fff;font-size:13px;}
#backstage-chat .bc-report h5{margin:0 0 4px;font-size:13px;}
#backstage-chat .bc-report .bc-report-meta{color:#64748b;font-size:12px;margin-bottom:4px;}
#backstage-chat .bc-report .bc-report-sample{background:#f8fafc;border-radius:6px;padding:6px;margin:4px 0;max-height:120px;overflow-y:auto;white-space:pre-wrap;}
#backstage-chat .bc-report-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
#backstage-chat .bc-report-actions select{border:1px solid #cbd5e1;border-radius:6px;padding:4px 6px;font:inherit;font-size:12px;}
#backstage-chat .bc-report-actions button{border:none;border-radius:6px;padding:5px 10px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;}
#backstage-chat .bc-report-actions .bc-ban{background:#dc2626;color:#fff;}
#backstage-chat .bc-report-actions .bc-dismiss{background:#e2e8f0;color:#475569;}
#backstage-chat .bc-icon{border:1px solid #cbd5e1;background:#f1f5f9;border-radius:8px;padding:0 10px;font-size:16px;line-height:1;cursor:pointer;color:#475569;}
#backstage-chat .bc-icon:hover{background:#e2e8f0;}
#backstage-chat .bc-icon.bc-rec{background:#dc2626;border-color:#dc2626;color:#fff;animation:bc-pulse 1s infinite;}
@keyframes bc-pulse{50%{opacity:.6;}}
#backstage-chat .bc-attach{margin-top:4px;}
#backstage-chat .bc-attach img,#backstage-chat .bc-attach video{max-width:100%;max-height:220px;border-radius:8px;display:block;cursor:pointer;}
#backstage-chat .bc-attach audio{width:100%;margin-top:2px;}
#backstage-chat .bc-dl{display:inline-block;margin-top:3px;font-size:12px;color:#2563eb;text-decoration:underline;}
#backstage-chat .bc-text a{color:#2563eb;word-break:break-all;}
@media (prefers-color-scheme: dark){
  #backstage-chat:not([data-theme="light"]){color:#e2e8f0;}
  #backstage-chat:not([data-theme="light"]) [data-bc-panel]{background:#0f172a;border-color:#1e293b;}
  #backstage-chat:not([data-theme="light"]) [data-bc-rooms-bar],
  #backstage-chat:not([data-theme="light"]) [data-bc-form]{background:#0f172a;border-color:#1e293b;}
  #backstage-chat:not([data-theme="light"]) [data-bc-new-form]{background:#0b1220;border-color:#1e293b;}
  #backstage-chat:not([data-theme="light"]) [data-bc-body]{background:#0b1220;}
  #backstage-chat:not([data-theme="light"]) .bc-members{background:#0f172a;border-color:#1e293b;}
  #backstage-chat:not([data-theme="light"]) .bc-check:hover{background:#1e293b;}
  #backstage-chat:not([data-theme="light"]) .bc-name{color:#f1f5f9;}
  #backstage-chat:not([data-theme="light"]) .bc-text{color:#e2e8f0;}
  #backstage-chat:not([data-theme="light"]) [data-bc-room-select],
  #backstage-chat:not([data-theme="light"]) [data-bc-dm-user],
  #backstage-chat:not([data-theme="light"]) [data-bc-group-name],
  #backstage-chat:not([data-theme="light"]) [data-bc-input]{background:#1e293b;border-color:#334155;color:#e2e8f0;}
  #backstage-chat:not([data-theme="light"]) [data-bc-new-btn]{background:#1e293b;border-color:#334155;color:#e2e8f0;}
}
</style>
<div id="backstage-chat" role="complementary" aria-label="<?= t_attr('chat_panel_title') ?>">
  <div data-bc-panel>
    <div data-bc-rooms-bar>
      <select data-bc-room-select aria-label="<?= t_attr('chat_panel_title') ?>"></select>
      <span class="bc-room-actions">
        <button type="button" data-bc-room-leave hidden><?= t_attr('chat_leave') ?></button>
        <button type="button" data-bc-room-del hidden><?= t_attr('chat_delete') ?></button>
      </span>
      <?php if ($bcIsAdmin): ?>
      <button type="button" data-bc-reports-btn><?= t_attr('chat_reports_title') ?></button>
      <?php endif; ?>
      <button type="button" data-bc-new-btn><?= t_attr('chat_new_label') ?></button>
    </div>
    <?php if ($bcIsAdmin): ?>
    <div data-bc-reports hidden></div>
    <?php endif; ?>
    <div data-bc-banned hidden></div>
    <div data-bc-new-form hidden>
      <div class="bc-kinds">
        <label><input type="radio" name="bc-kind" value="dm" checked> <?= t_attr('chat_new_dm') ?></label>
        <label><input type="radio" name="bc-kind" value="group"> <?= t_attr('chat_new_group') ?></label>
      </div>
      <div data-bc-dm-wrap>
        <select data-bc-dm-user aria-label="<?= t_attr('chat_dm_pick') ?>"></select>
      </div>
      <div data-bc-group-wrap hidden>
        <input type="text" data-bc-group-name placeholder="<?= t_attr('chat_group_name_placeholder') ?>" maxlength="120">
        <div class="bc-members-label"><?= t_attr('chat_group_members_label') ?></div>
        <div data-bc-group-members class="bc-members"></div>
      </div>
      <div class="bc-new-actions">
        <button type="button" data-bc-new-cancel><?= t_attr('chat_cancel_label') ?></button>
        <button type="button" data-bc-create><?= t_attr('chat_create_label') ?></button>
      </div>
    </div>
    <div data-bc-body>
      <div data-bc-list></div>
    </div>
    <form data-bc-form>
      <button type="button" data-bc-attach-btn class="bc-icon" title="<?= t_attr('chat_attach') ?>" aria-label="<?= t_attr('chat_attach') ?>">&#128206;</button>
      <button type="button" data-bc-voice-btn class="bc-icon" title="<?= t_attr('chat_voice_start') ?>" aria-label="<?= t_attr('chat_voice_start') ?>">&#127908;</button>
      <input type="file" data-bc-file accept="image/*,video/*" hidden>
      <textarea data-bc-input rows="1" placeholder="<?= t_attr('chat_input_placeholder') ?>" aria-label="<?= t_attr('chat_input_placeholder') ?>"></textarea>
      <button type="submit" data-bc-send><?= t_attr('chat_send_label') ?></button>
    </form>
  </div>
  <div data-bc-header>
    <span class="bc-title"><?= t_attr('chat_toggle_label') ?></span>
    <span data-bc-badge hidden></span>
    <span class="bc-caret" aria-hidden="true">&#9650;</span>
  </div>
</div>
<script>
window.__BACKSTAGE_CHAT = {
  endpoint: '/api/chat.php',
  apiKey: <?= json_encode($bcApiKey) ?>,
  csrf: <?= json_encode($bcCsrf) ?>,
  me: <?= json_encode($bcMeId) ?>,
  isAdmin: <?= $bcIsAdmin ? 'true' : 'false' ?>,
  labels: {
    empty: <?= json_encode(t('chat_empty')) ?>,
    sendError: <?= json_encode(t('chat_send_error')) ?>,
    createError: <?= json_encode(t('chat_create_error')) ?>,
    actionError: <?= json_encode(t('chat_action_error')) ?>,
    roleAdmin: <?= json_encode(t('chat_role_admin')) ?>,
    roleAuthor: <?= json_encode(t('chat_role_author')) ?>,
    everyone: <?= json_encode(t('chat_room_everyone')) ?>,
    newDm: <?= json_encode(t('chat_new_dm')) ?>,
    newGroup: <?= json_encode(t('chat_new_group')) ?>,
    noPeople: <?= json_encode(t('chat_no_people')) ?>,
    del: <?= json_encode(t('chat_delete')) ?>,
    report: <?= json_encode(t('chat_report')) ?>,
    reportPrompt: <?= json_encode(t('chat_report_prompt')) ?>,
    reportSent: <?= json_encode(t('chat_report_sent')) ?>,
    confirmDeleteRoom: <?= json_encode(t('chat_confirm_delete_room')) ?>,
    confirmLeave: <?= json_encode(t('chat_confirm_leave')) ?>,
    reportsTitle: <?= json_encode(t('chat_reports_title')) ?>,
    noReports: <?= json_encode(t('chat_no_reports')) ?>,
    ban: <?= json_encode(t('chat_ban')) ?>,
    dismiss: <?= json_encode(t('chat_dismiss')) ?>,
    ban1d: <?= json_encode(t('chat_ban_1d')) ?>,
    ban7d: <?= json_encode(t('chat_ban_7d')) ?>,
    ban30d: <?= json_encode(t('chat_ban_30d')) ?>,
    banPerm: <?= json_encode(t('chat_ban_perm')) ?>,
    bannedNotice: <?= json_encode(t('chat_banned_notice')) ?>,
    reportedBy: <?= json_encode(t('chat_reported_by')) ?>,
    attach: <?= json_encode(t('chat_attach')) ?>,
    voiceStart: <?= json_encode(t('chat_voice_start')) ?>,
    voiceStop: <?= json_encode(t('chat_voice_stop')) ?>,
    download: <?= json_encode(t('chat_download')) ?>,
    uploadError: <?= json_encode(t('chat_upload_error')) ?>,
    recording: <?= json_encode(t('chat_recording')) ?>,
    voiceUnsupported: <?= json_encode(t('chat_voice_unsupported')) ?>
  }
};
</script>
<script src="<?= htmlspecialchars($bcJsUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
