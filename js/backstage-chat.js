/*
 * Backstage chat — rooms (instance / DMs / subset groups) for logged-in authors
 * and admins, with moderation: delete a message, delete/leave a room, report a
 * user (emailed to admins), and (admins) review reports + ban. Plain classic
 * script, drops into both the admin console and the editor workspace.
 *
 * ponytail: short-poll every 5s, no websockets. Message/label text renders via
 * textContent (never innerHTML). Unread tracked per-room in localStorage.
 */
(function () {
  'use strict';
  var cfg = window.__BACKSTAGE_CHAT;
  if (!cfg || !cfg.endpoint) return;
  var root = document.getElementById('backstage-chat');
  if (!root) return;

  var L = cfg.labels;
  var me = cfg.me || '';
  var isAdmin = !!cfg.isAdmin;
  var q = function (s) { return root.querySelector(s); };
  var listEl = q('[data-bc-list]');
  var bodyEl = q('[data-bc-body]');
  var formEl = q('[data-bc-form]');
  var inputEl = q('[data-bc-input]');
  var sendEl = q('[data-bc-send]');
  var headerEl = q('[data-bc-header]');
  var badgeEl = q('[data-bc-badge]');
  var roomSel = q('[data-bc-room-select]');
  var newBtn = q('[data-bc-new-btn]');
  var newForm = q('[data-bc-new-form]');
  var kindEls = root.querySelectorAll('[name="bc-kind"]');
  var dmWrap = q('[data-bc-dm-wrap]');
  var dmSel = q('[data-bc-dm-user]');
  var groupWrap = q('[data-bc-group-wrap]');
  var groupName = q('[data-bc-group-name]');
  var groupMembers = q('[data-bc-group-members]');
  var createEl = q('[data-bc-create]');
  var cancelEl = q('[data-bc-new-cancel]');
  var roomDelBtn = q('[data-bc-room-del]');
  var roomLeaveBtn = q('[data-bc-room-leave]');
  var reportsBtn = q('[data-bc-reports-btn]');
  var reportsBox = q('[data-bc-reports]');
  var bannedBox = q('[data-bc-banned]');
  var panelEl = q('[data-bc-panel]');
  var attachBtn = q('[data-bc-attach-btn]');
  var voiceBtn = q('[data-bc-voice-btn]');
  var fileInput = q('[data-bc-file]');
  var mediaRec = null, recording = false;

  var POLL_MS = 5000;
  var open = false, polling = false, tick = 0;
  var activeRoom = 0, rooms = [], directory = null, banned = false;
  var lastId = {};

  function storeGet(k) { try { return JSON.parse(window.localStorage.getItem('bc_' + k) || 'null'); } catch (e) { return null; } }
  function storeSet(k, v) { try { window.localStorage.setItem('bc_' + k, JSON.stringify(v)); } catch (e) {} }
  var seen = storeGet('seen') || {};

  function api(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ 'X-API-Key': cfg.apiKey, 'X-CSRF-Token': cfg.csrf }, opts.headers || {});
    return fetch(cfg.endpoint + path, opts).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); });
  }
  function post(path, body) {
    return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
  }

  function fmtTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    return d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  function roleLabel(t) { return t === 2 ? L.roleAdmin : (t === 1 ? L.roleAuthor : ''); }
  function activeRoomObj() { for (var i = 0; i < rooms.length; i++) if (rooms[i].id === activeRoom) return rooms[i]; return null; }
  function roomLabel(r) { return r.is_instance ? L.everyone : (r.label || (r.kind === 'group' ? L.newGroup : L.newDm)); }

  // ---- media + links ----
  function mediaUrl(id, dl) {
    return cfg.endpoint + '?action=media&id=' + id + '&api_key=' + encodeURIComponent(cfg.apiKey) + (dl ? '&dl=1' : '');
  }
  var MEDIA_EXT = {
    image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],
    video: ['mp4', 'webm', 'ogv', 'mov'],
    audio: ['mp3', 'ogg', 'wav', 'm4a']
  };
  function urlMediaKind(url) {
    var m = /\.([a-z0-9]{2,5})(?:[?#]|$)/i.exec(url);
    if (!m) return null;
    var ext = m[1].toLowerCase();
    for (var k in MEDIA_EXT) if (MEDIA_EXT[k].indexOf(ext) !== -1) return k;
    return null;
  }
  function makeMediaEl(kind, src) {
    var el;
    if (kind === 'image') { el = document.createElement('img'); el.loading = 'lazy'; }
    else if (kind === 'video') { el = document.createElement('video'); el.controls = true; }
    else { el = document.createElement('audio'); el.controls = true; }
    el.src = src;
    return el;
  }
  // Linkify URLs in plain text (XSS-safe: text nodes + anchors with the URL as
  // href). Returns an array of external media URLs seen, for inline preview.
  function linkifyInto(container, text) {
    var re = /(https?:\/\/[^\s<]+)/g, last = 0, m, media = [];
    while ((m = re.exec(text)) !== null) {
      if (m.index > last) container.appendChild(document.createTextNode(text.slice(last, m.index)));
      var url = m[0];
      var a = document.createElement('a');
      a.setAttribute('href', url); a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener noreferrer');
      a.textContent = url;
      container.appendChild(a);
      var k = urlMediaKind(url);
      if (k) media.push({ url: url, kind: k });
      last = m.index + url.length;
    }
    if (last < text.length) container.appendChild(document.createTextNode(text.slice(last)));
    return media;
  }
  function renderAttachment(container, m) {
    var wrap = document.createElement('div');
    wrap.className = 'bc-attach';
    var a = m.attachment;
    var src = mediaUrl(m.id);
    var el = makeMediaEl(a.type, src);
    if (a.type === 'image') el.addEventListener('click', function () { window.open(mediaUrl(m.id, 1), '_blank', 'noopener'); });
    wrap.appendChild(el);
    var dl = document.createElement('a');
    dl.className = 'bc-dl'; dl.setAttribute('href', mediaUrl(m.id, 1));
    dl.textContent = L.download + (a.name ? ' (' + a.name + ')' : '');
    wrap.appendChild(dl);
    container.appendChild(wrap);
  }

  // ---- messages ----
  function renderEmpty() {
    if (listEl.childElementCount > 0) return;
    var p = document.createElement('p');
    p.className = 'bc-empty'; p.setAttribute('data-bc-empty', '');
    p.textContent = L.empty; listEl.appendChild(p);
  }
  function deleteMessage(id) {
    post('?action=delete_message', { id: id }).then(function () {
      var el = listEl.querySelector('[data-mid="' + id + '"]');
      if (el) el.remove();
      renderEmpty();
    }).catch(function () { window.alert(L.actionError); });
  }
  function reportUser(userId) {
    var reason = window.prompt(L.reportPrompt);
    if (reason === null) return; // cancelled
    post('?action=report_user', { user_id: userId, room: activeRoom, reason: reason })
      .then(function () { window.alert(L.reportSent); })
      .catch(function () { window.alert(L.actionError); });
  }
  function appendMessage(m) {
    var empty = listEl.querySelector('[data-bc-empty]');
    if (empty) empty.remove();
    var row = document.createElement('div');
    row.className = 'bc-msg'; row.dataset.mid = m.id;
    var meta = document.createElement('div');
    meta.className = 'bc-meta';
    var name = document.createElement('span');
    name.className = 'bc-name'; name.textContent = m.author_name; meta.appendChild(name);
    var rl = roleLabel(m.author_type);
    if (rl) { var b = document.createElement('span'); b.className = 'bc-role bc-role-' + m.author_type; b.textContent = rl; meta.appendChild(b); }
    var time = document.createElement('span');
    time.className = 'bc-time'; time.textContent = fmtTime(m.created_at); meta.appendChild(time);

    var mine = m.author_id && m.author_id === me;
    var acts = document.createElement('span');
    acts.className = 'bc-msg-actions';
    if (mine || isAdmin) {
      var del = document.createElement('button');
      del.type = 'button'; del.textContent = L.del;
      del.addEventListener('click', function () { deleteMessage(m.id); });
      acts.appendChild(del);
    }
    if (m.author_id && !mine) {
      var rep = document.createElement('button');
      rep.type = 'button'; rep.textContent = L.report;
      rep.addEventListener('click', function () { reportUser(m.author_id); });
      acts.appendChild(rep);
    }
    if (acts.childElementCount) meta.appendChild(acts);

    row.appendChild(meta);
    if (m.body) {
      var text = document.createElement('div');
      text.className = 'bc-text';
      var media = linkifyInto(text, m.body);
      row.appendChild(text);
      // Inline preview + download for external media URLs in the text.
      media.forEach(function (mm) {
        var wrap = document.createElement('div');
        wrap.className = 'bc-attach';
        wrap.appendChild(makeMediaEl(mm.kind, mm.url));
        var dl = document.createElement('a');
        dl.className = 'bc-dl'; dl.setAttribute('href', mm.url); dl.setAttribute('download', '');
        dl.setAttribute('target', '_blank'); dl.setAttribute('rel', 'noopener noreferrer');
        dl.textContent = L.download;
        wrap.appendChild(dl);
        row.appendChild(wrap);
      });
    }
    if (m.attachment) renderAttachment(row, m);
    listEl.appendChild(row);
  }
  function scrollDown() { bodyEl.scrollTop = bodyEl.scrollHeight; }

  function totalUnread() {
    var n = 0;
    rooms.forEach(function (r) {
      if (!(open && r.id === activeRoom) && r.last_message_id > (seen[r.id] || 0)) n++;
    });
    return n;
  }
  function setBadge() {
    if (!badgeEl) return;
    var n = open ? 0 : totalUnread();
    if (n > 0) { badgeEl.textContent = String(n); badgeEl.hidden = false; } else badgeEl.hidden = true;
  }

  // ---- rooms ----
  function renderRoomOptions() {
    roomSel.textContent = '';
    rooms.forEach(function (r) {
      var o = document.createElement('option');
      o.value = String(r.id);
      var unread = r.id !== activeRoom && r.last_message_id > (seen[r.id] || 0);
      o.textContent = (unread ? '• ' : '') + roomLabel(r);
      roomSel.appendChild(o);
    });
    if (!rooms.some(function (r) { return r.id === activeRoom; }) && rooms.length) activeRoom = rooms[0].id;
    roomSel.value = String(activeRoom);
    syncRoomActions();
  }
  function syncRoomActions() {
    var r = activeRoomObj();
    roomDelBtn.hidden = !(r && r.can_delete);
    roomLeaveBtn.hidden = !(r && r.can_leave);
  }
  function setBanned(data) {
    banned = true;
    panelEl.querySelectorAll('[data-bc-rooms-bar],[data-bc-new-form],[data-bc-body],[data-bc-form]').forEach(function (el) { el.hidden = true; });
    var msg = L.bannedNotice;
    if (data.until) { var d = new Date(data.until); if (!isNaN(d.getTime())) msg += ' (' + d.toLocaleString() + ')'; }
    bannedBox.textContent = msg; bannedBox.hidden = false;
    if (badgeEl) badgeEl.hidden = true;
  }
  function loadRooms() {
    return api('?action=rooms').then(function (data) {
      if (data && data.banned) { setBanned(data); return; }
      rooms = (data && data.rooms) || [];
      if (!activeRoom && rooms.length) activeRoom = rooms[0].id;
      renderRoomOptions(); setBadge();
    }).catch(function () {});
  }
  function selectRoom(id) {
    activeRoom = id; roomSel.value = String(id); listEl.textContent = ''; lastId[id] = 0;
    syncRoomActions(); pollMessages(true);
  }
  function markSeen(roomId, upTo) { if (upTo > (seen[roomId] || 0)) { seen[roomId] = upTo; storeSet('seen', seen); } }

  function pollMessages(initial) {
    if (!activeRoom || banned) return Promise.resolve();
    var since = initial ? 0 : (lastId[activeRoom] || 0);
    var at = activeRoom;
    return api('?action=messages&room=' + at + '&since=' + since).then(function (data) {
      if (at !== activeRoom) return;
      var msgs = (data && data.messages) || [];
      var bottom = bodyEl.scrollHeight - bodyEl.scrollTop - bodyEl.clientHeight < 40;
      msgs.forEach(function (m) { if ((lastId[activeRoom] || 0) < m.id) { appendMessage(m); lastId[activeRoom] = m.id; } });
      renderEmpty();
      if (open) markSeen(activeRoom, lastId[activeRoom] || 0);
      setBadge();
      if (initial || bottom || open) scrollDown();
    }).catch(function () {});
  }

  function send() {
    var body = (inputEl.value || '').trim();
    if (!body || !activeRoom) return;
    sendEl.disabled = true;
    post('?action=send', { room: activeRoom, body: body }).then(function (data) {
      if (data && data.message && (lastId[activeRoom] || 0) < data.message.id) {
        appendMessage(data.message); lastId[activeRoom] = data.message.id;
        markSeen(activeRoom, data.message.id); scrollDown();
      }
      inputEl.value = '';
    }).catch(function () { window.alert(L.sendError); })
      .then(function () { sendEl.disabled = false; inputEl.focus(); });
  }

  function uploadFile(file, kind, filename) {
    if (!activeRoom || !file) return;
    var fd = new FormData();
    fd.append('file', file, filename || file.name || 'upload');
    fd.append('room', String(activeRoom));
    if (kind) fd.append('kind', kind);
    var cap = (inputEl.value || '').trim();
    if (cap) fd.append('body', cap);
    // No Content-Type header: the browser sets the multipart boundary.
    fetch(cfg.endpoint + '?action=upload', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-API-Key': cfg.apiKey, 'X-CSRF-Token': cfg.csrf }, body: fd
    }).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) {
        if (data && data.message && (lastId[activeRoom] || 0) < data.message.id) {
          appendMessage(data.message); lastId[activeRoom] = data.message.id;
          markSeen(activeRoom, data.message.id); scrollDown();
        }
        inputEl.value = '';
      }).catch(function () { window.alert(L.uploadError); });
  }
  function setRecording(on) {
    recording = on;
    voiceBtn.classList.toggle('bc-rec', on);
    voiceBtn.title = on ? L.voiceStop : L.voiceStart;
  }
  function toggleRecord() {
    if (recording && mediaRec) { mediaRec.stop(); return; }
    if (!navigator.mediaDevices || !window.MediaRecorder) { window.alert(L.voiceUnsupported); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var chunks = [];
      mediaRec = new MediaRecorder(stream);
      mediaRec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      mediaRec.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        var blob = new Blob(chunks, { type: (mediaRec && mediaRec.mimeType) || 'audio/webm' });
        setRecording(false);
        if (blob.size) uploadFile(blob, 'voice', 'voice.webm');
      };
      mediaRec.start(); setRecording(true);
    }).catch(function () { window.alert(L.voiceUnsupported); });
  }

  function deleteRoom() {
    if (!activeRoom || !window.confirm(L.confirmDeleteRoom)) return;
    post('?action=delete_room', { room: activeRoom }).then(function () {
      activeRoom = 0;
      return loadRooms().then(function () { if (rooms.length) selectRoom(rooms[0].id); });
    }).catch(function () { window.alert(L.actionError); });
  }
  function leaveRoom() {
    if (!activeRoom || !window.confirm(L.confirmLeave)) return;
    post('?action=leave_room', { room: activeRoom }).then(function () {
      activeRoom = 0;
      return loadRooms().then(function () { if (rooms.length) selectRoom(rooms[0].id); });
    }).catch(function () { window.alert(L.actionError); });
  }

  // ---- new conversation ----
  function syncKind() {
    var k = root.querySelector('[name="bc-kind"]:checked');
    var g = k && k.value === 'group';
    dmWrap.hidden = g; groupWrap.hidden = !g;
  }
  function loadDirectory() {
    if (directory) return Promise.resolve();
    return api('?action=directory').then(function (data) {
      directory = (data && data.users) || [];
      dmSel.textContent = ''; groupMembers.textContent = '';
      if (!directory.length) { var n = document.createElement('div'); n.className = 'bc-dim'; n.textContent = L.noPeople; groupMembers.appendChild(n); }
      directory.forEach(function (u) {
        var o = document.createElement('option'); o.value = u.id; o.textContent = u.name; dmSel.appendChild(o);
        var lab = document.createElement('label'); lab.className = 'bc-check';
        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.value = u.id;
        var sp = document.createElement('span'); sp.textContent = u.name;
        lab.appendChild(cb); lab.appendChild(sp); groupMembers.appendChild(lab);
      });
    }).catch(function () {});
  }
  function createConversation() {
    var k = root.querySelector('[name="bc-kind"]:checked');
    var g = k && k.value === 'group';
    createEl.disabled = true;
    var p = g
      ? post('?action=create_group', { name: (groupName.value || '').trim(), user_ids: [].slice.call(groupMembers.querySelectorAll('input:checked')).map(function (c) { return c.value; }) })
      : post('?action=create_dm', { user_id: dmSel.value });
    p.then(function (data) {
      if (data && data.room_id) { groupName.value = ''; newForm.hidden = true; return loadRooms().then(function () { selectRoom(data.room_id); }); }
    }).catch(function () { window.alert(L.createError); })
      .then(function () { createEl.disabled = false; });
  }

  // ---- admin reports ----
  function renderReports(list) {
    reportsBox.textContent = '';
    if (!list.length) { var n = document.createElement('div'); n.className = 'bc-dim'; n.textContent = L.noReports; reportsBox.appendChild(n); return; }
    list.forEach(function (rep) {
      var card = document.createElement('div'); card.className = 'bc-report';
      var h = document.createElement('h5'); h.textContent = rep.reported_name; card.appendChild(h);
      var meta = document.createElement('div'); meta.className = 'bc-report-meta';
      meta.textContent = L.reportedBy + ' ' + rep.reporter_name + (rep.reason ? (' — ' + rep.reason) : '');
      card.appendChild(meta);
      if (rep.sample && rep.sample.length) {
        var s = document.createElement('div'); s.className = 'bc-report-sample';
        s.textContent = rep.sample.map(function (x) { return x.body; }).join('\n');
        card.appendChild(s);
      }
      var acts = document.createElement('div'); acts.className = 'bc-report-actions';
      var sel = document.createElement('select');
      [[1, L.ban1d], [7, L.ban7d], [30, L.ban30d], [0, L.banPerm]].forEach(function (d) {
        var o = document.createElement('option'); o.value = String(d[0]); o.textContent = d[1]; sel.appendChild(o);
      });
      var banBtn = document.createElement('button'); banBtn.type = 'button'; banBtn.className = 'bc-ban'; banBtn.textContent = L.ban;
      banBtn.addEventListener('click', function () {
        banBtn.disabled = true;
        post('?action=ban_user', { user_id: rep.reported_user_id, days: parseInt(sel.value, 10) })
          .then(function () { return post('?action=close_report', { id: rep.id }); })
          .then(loadReports).catch(function () { banBtn.disabled = false; window.alert(L.actionError); });
      });
      var dis = document.createElement('button'); dis.type = 'button'; dis.className = 'bc-dismiss'; dis.textContent = L.dismiss;
      dis.addEventListener('click', function () {
        dis.disabled = true;
        post('?action=close_report', { id: rep.id }).then(loadReports).catch(function () { dis.disabled = false; window.alert(L.actionError); });
      });
      acts.appendChild(sel); acts.appendChild(banBtn); acts.appendChild(dis);
      card.appendChild(acts); reportsBox.appendChild(card);
    });
  }
  function loadReports() {
    if (!isAdmin) return Promise.resolve();
    return api('?action=reports').then(function (data) {
      var list = (data && data.reports) || [];
      if (!reportsBox.hidden) renderReports(list);
      reportsBtn.textContent = L.reportsTitle + (list.length ? ' (' + list.length + ')' : '');
    }).catch(function () {});
  }

  // ---- wiring ----
  headerEl.addEventListener('click', function () { setOpen(!open); });
  formEl.addEventListener('submit', function (e) { e.preventDefault(); send(); });
  inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
  roomSel.addEventListener('change', function () { selectRoom(parseInt(roomSel.value, 10)); });
  newBtn.addEventListener('click', function () { if (newForm.hidden) { newForm.hidden = false; loadDirectory().then(syncKind); } else newForm.hidden = true; });
  cancelEl.addEventListener('click', function () { newForm.hidden = true; });
  createEl.addEventListener('click', createConversation);
  [].forEach.call(kindEls, function (el) { el.addEventListener('change', syncKind); });
  roomDelBtn.addEventListener('click', deleteRoom);
  roomLeaveBtn.addEventListener('click', leaveRoom);
  attachBtn.addEventListener('click', function () { fileInput.click(); });
  fileInput.addEventListener('change', function () {
    if (fileInput.files && fileInput.files[0]) uploadFile(fileInput.files[0]);
    fileInput.value = '';
  });
  if (!navigator.mediaDevices || !window.MediaRecorder) { voiceBtn.hidden = true; }
  else voiceBtn.addEventListener('click', toggleRecord);
  if (reportsBtn) reportsBtn.addEventListener('click', function () { reportsBox.hidden = !reportsBox.hidden; if (!reportsBox.hidden) loadReports(); });

  function setOpen(next) {
    open = next;
    root.classList.toggle('bc-open', open);
    storeSet('open', open ? 1 : 0);
    if (open) { markSeen(activeRoom, lastId[activeRoom] || 0); setBadge(); scrollDown(); if (!banned) inputEl.focus(); } else setBadge();
  }

  function loop() {
    if (banned) return;
    tick++;
    pollMessages(false);
    if (tick % 2 === 0) loadRooms();
    if (isAdmin && tick % 4 === 0) loadReports();
  }

  loadRooms().then(function () {
    if (!banned && activeRoom) selectRoom(activeRoom);
    if (isAdmin) loadReports();
    setOpen(storeGet('open') === 1);
    setInterval(loop, POLL_MS);
  });
})();
