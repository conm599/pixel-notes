/**
 * Pixel Notes - 前端交互逻辑 v5
 * - 新建与编辑共用同一个大编辑面板（同等大小，体验一致）
 * - 长文截断 + 阅读弹窗
 */

(function () {
  'use strict';

  // ============ View Transitions 工具：卡片 ↔ 详情弹窗 ↔ 全屏编辑器 连续 morph ============
  // sourceEl 在旧视图命名 → mutate() 同步改 DOM → targetEl 在新视图接管同名 → 浏览器自动放大/缩小过渡
  // 浏览器不支持（startViewTransition 不存在）时退化为直接切换（原行为），零风险降级
  var PNVT = (function () {
    var NAME = 'pn-note';
    function supported() { return typeof document.startViewTransition === 'function'; }
    function morph(sourceEl, mutate, targetEl) {
      if (!supported()) { mutate(); return; }
      if (sourceEl && sourceEl.style) sourceEl.style.viewTransitionName = NAME;
      // 灵动岛模式：root 层立即切换（页面瞬时就位，无 cross-fade），pn-note 层独立拉伸/缩回
      document.documentElement.classList.add('vt-close');
      var cleaned = false;
      function cleanup() {
        if (cleaned) return; cleaned = true;
        document.documentElement.classList.remove('vt-close');
        if (sourceEl && sourceEl.style) sourceEl.style.viewTransitionName = '';
        if (targetEl && targetEl.style) targetEl.style.viewTransitionName = '';
      }
      var vt;
      try {
        vt = document.startViewTransition(function () {
          // 旧快照已在 startViewTransition 时捕获，mutate 后立刻清源命名——
          // 否则共存型 morph（源卡片 + 新目标同名）会因重复 view-transition-name 被 abort
          if (sourceEl && sourceEl.style) sourceEl.style.viewTransitionName = '';
          mutate();
          if (targetEl && targetEl.style) targetEl.style.viewTransitionName = NAME;
        });
      } catch (e) {
        cleanup(); mutate(); return;
      }
      // 保险丝：transition 挂起/僵死时强制收尾，避免残留状态拖垮页面（曾出现浏览器无响应）
      var guard = setTimeout(function () {
        try { vt.skipTransition(); } catch (e) {}
        cleanup();
      }, 700);
      vt.finished.finally(function () { clearTimeout(guard); cleanup(); });
      return vt;
    }
    return { morph: morph, supported: supported, NAME: NAME };
  })();

  var API_BASE = 'api/notes.php';
  var FOLDER_API = 'api/folders.php';
  var notesGrid = document.getElementById('notesGrid');
  var toast = document.getElementById('toast');
  var toastTimer = null;
  var notesById = {};
  var notesOrder = [];   // 便签 id 顺序（后端排序结果），整数 key 对象枚举会按数值升序不能用

  // 文件夹状态（多层嵌套）
  var foldersById = {};          // id → folder 对象
  var currentFolderId = null;    // null = 主页（根层级）

  // ============== Toast ==============
  function showToast(message, type) {
    toast.textContent = message;
    toast.className = 'toast ' + (type || 'success');
    toast.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.style.display = 'none'; }, 2500);
  }

  // ============== API ==============
  async function api(method, body) {
    var resp = await fetch(API_BASE, {
      method: method,
      headers: { 'Content-Type': 'application/json' },
      body: body ? jbody(body) : undefined,
      credentials: 'include',
      cache: 'no-store'
    });
    if (resp.status === 401) {
      await check401();   // 先向服务端复核会话，真失效才跳登录（防瞬时假 401 踢人）
      throw new Error('未登录');
    }
    var raw = await resp.text();
    var result;
    try { result = JSON.parse(raw); }
    catch (e) { throw new Error('服务器响应异常 (HTTP ' + resp.status + ')'); }
    return result;
  }

  // 401 复核（治「莫名其妙被弹回登录页」）：收到 401 先打 auth check 确认会话真的失效；
  // 服务端说还活着（瞬时假 401：网络抖动/网关瞬断等）就留在本页提示重试，绝不弹登录页。
  async function check401() {
    try {
      var r = await fetch('api/auth.php', {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: jbody({ action: 'check' })
      });
      var j = null;
      try { j = JSON.parse(await r.text()); } catch (e) { j = null; }
      // 注意：check 未登录时也返回 success:true（logged_in:false），判据必须是 logged_in
      if (j && j.success && j.logged_in) { showToast('🔐 登录状态有效（刚才是瞬时故障），请重试刚才的操作', 'info'); return true; }
    } catch (e) { /* 复核失败按真失效处理 */ }
    window.location.href = 'login.php';
    return false;
  }

  // 便签/文件夹数据引用给 ai-direct.js 的 TOOL 块（浏览器端执行同逻辑）
  try {
    window.__pixelNotesById = notesById;
    window.__pixelFoldersById = foldersById;
    window.__pixelFolderChain = folderChain;
  } catch (e) { /* 忽略 */ }

  // ============== 加载 ==============
  async function loadFolders() {
    try {
      var resp = await fetch(FOLDER_API, { credentials: 'include', cache: 'no-store' });
      var result = await resp.json();
      if (result.success && Array.isArray(result.folders)) {
        foldersById = {};
        result.folders.forEach(function (f) {
          foldersById[f.id] = {
            id: f.id,
            parent_id: f.parent_id,
            name: f.name,
            sort_order: parseInt(f.sort_order) || 0,
            note_count: parseInt(f.note_count) || 0,
            share_token: f.share_token || '',
            share_until: parseInt(f.share_until) || 0,
            share_url: f.share_url || ''
          };
        });
        renderBreadcrumb();
      }
    } catch (e) { /* 文件夹加载失败不阻塞便签 */ }
  }

  // 面包屑路径：从 currentFolderId 回溯到根
  function folderChain(fid) {
    var chain = [];
    var cur = fid, guard = 0;
    while (cur !== null && cur !== undefined && foldersById[cur] && guard++ < 50) {
      chain.unshift(foldersById[cur]);
      cur = foldersById[cur].parent_id;
    }
    return chain;
  }

  function renderBreadcrumb() {
    var bar = document.getElementById('folderCrumb');
    if (!bar) return;
    bar.innerHTML = '';
    var home = mkEl('a', 'crumb', '<i class="ic ic-home"></i> 主页');
    home.addEventListener('click', function () { switchFolder(null); });
    bar.appendChild(home);
    var chain = folderChain(currentFolderId);
    chain.forEach(function (f, i) {
      bar.appendChild(mkEl('span', 'crumb-sep', '/'));
      var isLast = (i === chain.length - 1);
      var link = mkEl('a', 'crumb' + (isLast ? ' current' : ''), '<i class="ic ic-folder"></i> ' + f.name);
      if (!isLast) {
        var fid = f.id;
        link.addEventListener('click', function () { switchFolder(fid); });
      }
      bar.appendChild(link);
    });
  }

  function switchFolder(fid) {
    currentFolderId = fid === null ? null : fid;
    // 同步地址栏 #folder=N：刷新/分享链接后回到当前文件夹（不触发请求）
    try { history.replaceState(null, '', currentFolderId === null ? location.pathname : location.pathname + '#folder=' + currentFolderId); } catch (e) {}
    if (window.PixelSelection) window.PixelSelection.reset();   // 切目录清选择（剪贴板保留）
    renderBreadcrumb();
    renderFromMemory();   // 本地过滤渲染，零网络请求——点击瞬间进入
  }

  async function loadNotes() {
    try {
      notesGrid.innerHTML = '<div class="loading">加载中...</div>';
      var result = await api('GET');
      if (!result.success) {
        notesGrid.innerHTML = '<div class="empty-state"><div class="icon"><i class="ic ic-skull ic-lg"></i></div><p>加载失败</p></div>';
        return;
      }
      renderNotes(result.notes);
    } catch (err) {
      if (String(err.message).indexOf('未登录') === -1) {
        notesGrid.innerHTML = '<div class="empty-state"><div class="icon"><i class="ic ic-skull ic-lg"></i></div><p>网络错误</p></div>';
      }
    }
  }

  // 全量重载（便签+文件夹），视图状态保持
  function refreshView() {
    var fidAtCall = currentFolderId;
    api('GET').then(function (result) {
      if (!result.success) return;
      renderNotes(result.notes);
    }).catch(function () {});
  }

  function renderNotes(notes) {
    // 仅更新内存索引与顺序（由网络响应触发）；渲染走 renderFromMemory
    notesById = {};
    notesOrder = [];   // 后端返回顺序（ORDER BY pinned/sort_order）——整数 key 对象会被 JS 按数值升序枚举，顺序必须另存数组
    (notes || []).forEach(function (note) {
      var rawFid = (note.folder_id === null || note.folder_id === undefined) ? null : parseInt(note.folder_id);
      var n = {
        id: parseInt(note.id),
        title: note.title || '',
        content: note.content || '',
        color: note.color || 'yellow',
        pinned: parseInt(note.pinned) || 0,
        sort_order: parseInt(note.sort_order) || 0,
        folder_id: (rawFid === null || isNaN(rawFid)) ? null : rawFid,
        updated_at: note.updated_at || '',
        share_token: note.share_token || '',
        share_until: parseInt(note.share_until) || 0,
        share_url: note.share_url || '',
        _more: parseInt(note._more) || 0   // 1=content 只是摘要（前2000字），打开编辑时需按需拉全文
      };
      notesById[n.id] = n;
      notesOrder.push(n.id);
    });
    renderFromMemory();
  }

  // 用内存索引按当前目录过滤渲染——切文件夹零请求，点击瞬间进入（数据首屏已在内存）
  function renderFromMemory() {
    var allNotes = notesOrder.map(function (id) { return notesById[id]; }).filter(Boolean);
    notesGrid.innerHTML = '';

    // 当前层级下的子文件夹卡片（排在便签前面）
    var childFolders = Object.keys(foldersById).map(function (k) { return foldersById[k]; })
      .filter(function (f) { return (f.parent_id || null) === currentFolderId; })
      .sort(function (a, b) { return a.sort_order - b.sort_order || a.id - b.id; });
    childFolders.forEach(function (f) {
      notesGrid.appendChild(createFolderCard(f));
    });

    var viewNotes = allNotes.filter(function (n) { return n.folder_id === currentFolderId; });
    if (viewNotes.length === 0 && childFolders.length === 0) {
      var isHome = currentFolderId === null;
      notesGrid.innerHTML = ''
        + '<div class="empty-state">'
        + '  <div class="icon">' + (isHome ? '📜' : '📂') + '</div>'
        + '  <p>' + (isHome ? '还没有便签<br>点击「＋ 新建便签」开始吧！' : '这个文件夹还是空的<br>拖拽便签到此处，或在此处新建') + '</p>'
        + '</div>';
    } else {
      viewNotes.forEach(function (n) {
        var card = createNoteCard(n);
        notesGrid.appendChild(card);
        checkClamp(card);
      });
    }
    initDragSort();
    if (window.PixelSelection) window.PixelSelection.syncUI();   // 视图重建后恢复选中高亮
  }

  // ============== 工具 ==============
  function mkEl(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) {
      // 以 <i 开头的字面量是内部图标标记（ic-*），走 innerHTML；其余一律 textContent 防注入
      if (text.indexOf('<i ') === 0) el.innerHTML = text;
      else el.textContent = text;
    }
    return el;
  }

  // ---- UTF-16 安全（v13.6）----
  // slice() 截断表情符号会产生「孤立代理项」，JSON.stringify 输出 \uD8xx 这类非法转义，
  // PHP json_decode 直接失败 → 整个请求被丢弃（表现为「未知操作」）。出站 JSON 一律先清洗。
  function utf16Sanitize(s) {
    s = String(s == null ? '' : s);
    var out = '', i, c, n;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 0xD800 && c <= 0xDBFF) {           // 高位代理项：必须紧跟低位才算合法
        n = (i + 1 < s.length) ? s.charCodeAt(i + 1) : 0;
        if (n >= 0xDC00 && n <= 0xDFFF) { out += s.charAt(i) + s.charAt(i + 1); i++; }
        else out += '\uFFFD';
      } else if (c >= 0xDC00 && c <= 0xDFFF) {   // 孤立低位代理项
        out += '\uFFFD';
      } else {
        out += s.charAt(i);
      }
    }
    return out;
  }
  // 安全截断：先切再清洗（切点落在 emoji 中间也不会产出非法字符）
  function safeSlice(str, n) { return utf16Sanitize(String(str == null ? '' : str).slice(0, n)); }
  // 出站 JSON（所有 POST body 都走它）
  // 注意：必须在 stringify 之前清洗——stringify 会把孤立代理项转成 \ud83c 这种文本转义，
  // 之后再清洗就看不到真正的非法字符了。
  function utf16SanitizeDeep(v, depth) {
    if (typeof v === 'string') return utf16Sanitize(v);
    if (v == null || typeof v !== 'object') return v;
    if ((depth || 0) > 8) return v;
    if (Object.prototype.toString.call(v) === '[object Array]') {
      var arr = [];
      for (var i = 0; i < v.length; i++) arr.push(utf16SanitizeDeep(v[i], (depth || 0) + 1));
      return arr;
    }
    var o = {};
    for (var k in v) { if (Object.prototype.hasOwnProperty.call(v, k)) o[k] = utf16SanitizeDeep(v[k], (depth || 0) + 1); }
    return o;
  }
  function jbody(obj) { return JSON.stringify(utf16SanitizeDeep(obj, 0)); }

  function mkBtn(label, title) {
    var b = document.createElement('button');
    b.type = 'button';
    if (label && label.indexOf('<i ') === 0) b.innerHTML = label;
    else b.textContent = label;
    if (title) b.title = title;
    return b;
  }

  // 图标 + 纯文本（text 走 createTextNode，安全拼接用户内容）
  function setIconText(el, iconName, text) {
    if (!el) return;
    el.innerHTML = '<i class="ic ic-' + iconName + '"></i> ';
    el.appendChild(document.createTextNode(text));
  }

  // AI 阶段文案 → 专属机器人图标（加载中带旋转动效）
  var PHASE_ICONS = { '🤖': 'robot', '🔧': 'gear', '🔁': 'recycle', '🧩': 'grid' };
  function phaseIconHTML(t) {
    var head = t.slice(0, 3);
    if (PHASE_ICONS[head]) {
      var spinning = head === '🤖' ? ' ic-spin' : '';
      return '<i class="ic ic-' + PHASE_ICONS[head] + spin + '"></i> ' + t.slice(3).replace(/^ /, '');
    }
    return null;
  }
  function setPhaseText(el, t) {
    if (!el) return;
    var html = phaseIconHTML(t);
    if (html) el.innerHTML = html;
    else el.textContent = t;
  }

  // ============== 文件夹 API ==============
  async function folderApi(method, body) {
    var resp = await fetch(FOLDER_API, {
      method: method,
      headers: { 'Content-Type': 'application/json' },
      body: body ? jbody(body) : undefined,
      credentials: 'include',
      cache: 'no-store'
    });
    if (resp.status === 401) { await check401(); throw new Error('未登录'); }
    var result = await resp.json();
    return result;
  }

  // ============== 文件夹卡片 ==============
  function createFolderCard(folder) {
    var card = mkEl('div', 'folder-card');
    card.setAttribute('data-folder-id', folder.id);
    var icon = mkEl('div', 'folder-icon', '<i class="ic ic-folder"></i>');
    var name = mkEl('div', 'folder-name', folder.name);
    var count = mkEl('div', 'folder-count', folder.note_count + ' 条便签');
    card.appendChild(icon);
    card.appendChild(name);
    card.appendChild(count);
    if (folder.share_token) {
      var badge = mkEl('span', 'share-badge', '已分享');
      badge.title = '文件夹已公开分享';
      card.appendChild(badge);
    }

    card.addEventListener('click', function () {
      if (window.PixelSelection && window.PixelSelection.isActive()) return;   // 选择模式：点击由全局选择逻辑接管
      switchFolder(folder.id);
    });

    // 小菜单：改名 / 移动 / 删除
    var menuBtn = mkBtn('⋮', '文件夹操作');
    menuBtn.className = 'folder-menu-btn';
    menuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      openFolderMenu(folder, menuBtn);
    });
    card.appendChild(menuBtn);
    return card;
  }

  // 文件夹卡「已分享」角标即时同步（分享/取消分享后区域性更新，不整页刷新）
  function syncFolderBadge(id) {
    var f = foldersById[id];
    var card = document.querySelector('.folder-card[data-folder-id="' + id + '"]');
    if (!card) return;
    var shared = !!(f && f.share_token);
    var b = card.querySelector('.share-badge');
    if (shared && !b) {
      var el = mkEl('span', 'share-badge', '已分享');
      el.title = '文件夹已公开分享';
      card.appendChild(el);
    } else if (!shared && b) {
      b.remove();
    }
  }

  // 浮层菜单定位：优先锚点下方，越界自动翻转/钳制，绝不溢出视口（右缘/底缘修复）
  function placeMenu(menu, anchor) {
    menu.style.position = 'fixed';
    menu.style.left = '0px';
    menu.style.top = '0px';
    menu.style.visibility = 'hidden';
    document.body.appendChild(menu);
    var r = anchor.getBoundingClientRect();
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    var left = r.right - mw;
    left = Math.min(Math.max(8, left), window.innerWidth - mw - 8);
    var top = r.bottom + 4;
    if (top + mh > window.innerHeight - 8) top = r.top - mh - 4;          // 翻转到锚点上方
    if (top + mh > window.innerHeight - 8) top = window.innerHeight - mh - 8;  // 锚点也在屏外 → 贴底钳制
    if (top < 8) top = 8;
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.visibility = '';
  }

  // 文件夹操作菜单（悬停浮层）
  var folderMenuEl = null;
  function closeFolderMenu() {
    if (folderMenuEl) { folderMenuEl.remove(); folderMenuEl = null; }
  }
  function openFolderMenu(folder, anchor) {
    closeFolderMenu();
    var menu = mkEl('div', 'folder-menu');

    function addItem(label, icon, fn) {
      var item = mkEl('div', 'folder-menu-item');
      item.appendChild(mkEl('span', null, icon + ' ' + label));
      item.addEventListener('click', function (e) { e.stopPropagation(); closeFolderMenu(); fn(); });
      menu.appendChild(item);
    }

    addItem('在里面新建子文件夹', '<i class="ic ic-folder"></i>', function () { promptNewFolder(folder.id); });
    addItem('改名', '<i class="ic ic-pencil"></i>', function () { promptRenameFolder(folder); });
    addItem('移动到…', '<i class="ic ic-back"></i>', function () { promptMoveFolder(folder); });
    addItem('分享', '<i class="ic ic-link"></i>', function () { openShareDialog(folder.id, foldersById[folder.id] || folder, 'folder'); });
    addItem('删除（内容上移）', '<i class="ic ic-trash"></i>', function () { promptDeleteFolder(folder); });

    placeMenu(menu, anchor);
    folderMenuEl = menu;
    document.addEventListener('pointerdown', function h(e) {
      if (folderMenuEl && !folderMenuEl.contains(e.target)) {
        closeFolderMenu(); document.removeEventListener('pointerdown', h, true);
      }
    }, true);
  }

  // 像素风输入弹窗（v127）：替代原生 prompt——新建文件夹 / 文件夹改名共用
  // callback(name) 在点「确定」或回车时触发；✕ / Esc / 点遮罩 = 不动
  function openPromptDialog(title, label, defaultValue, callback) {
    var overlay = mkEl('div', 'md-modal-overlay');
    overlay.style.zIndex = '21000';
    var modal = mkEl('div', 'md-modal prompt-dialog');
    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-pencil"></i> ' + title));
    var closeBtn = mkBtn('<i class="ic ic-close"></i>', '取消');
    closeBtn.className = 'md-modal-close';
    head.appendChild(closeBtn);
    modal.appendChild(head);
    var body = mkEl('div', 'md-modal-body prompt-dialog-body');
    body.appendChild(mkEl('div', 'prompt-label', label));
    var input = mkEl('input', 'prompt-input');
    input.type = 'text';
    input.maxLength = 60;
    input.value = defaultValue || '';
    input.placeholder = '输入名称，回车确认';
    body.appendChild(input);
    modal.appendChild(body);
    var foot = mkEl('div', 'md-modal-foot prompt-dialog-foot');
    var okBtn = mkBtn('<i class="ic ic-checkall"></i> 确定', '确认');
    okBtn.className = 'btn btn-primary btn-sm';
    foot.appendChild(mkEl('span', 'move-foot-spacer'));
    foot.appendChild(okBtn);
    modal.appendChild(foot);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    function close() { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }
    function submit() {
      var name = input.value.trim();
      if (!name) { showToast('❌ 名称不能为空', 'error'); input.focus(); return; }
      close();
      callback(name);
    }
    closeBtn.addEventListener('click', close);
    okBtn.addEventListener('click', submit);
    overlay.addEventListener('pointerdown', function (e) { if (e.target === overlay) close(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') submit();
      if (e.key === 'Escape') close();
    });
    setTimeout(function () { input.focus(); input.select(); }, 60);
  }

  async function promptNewFolder(parentId) {
    openPromptDialog(parentId ? '新建子文件夹' : '新建文件夹', '文件夹名：', '', async function (name) {
      try {
        var r = await folderApi('POST', { name: name, parent_id: parentId });
        if (r.success) { showToast('✅ 文件夹已创建', 'success'); await loadFolders(); refreshView(); }
        else showToast('❌ ' + (r.message || '创建失败'), 'error');
      } catch (e) { showToast('❌ 创建失败', 'error'); }
    });
  }

  async function promptRenameFolder(folder) {
    openPromptDialog('文件夹改名', '新的名称：', folder.name, async function (name) {
      try {
        var r = await folderApi('PUT', { id: folder.id, name: name });
        if (r.success) { showToast('✅ 已改名', 'success'); await loadFolders(); refreshView(); }
        else showToast('❌ ' + (r.message || '改名失败'), 'error');
      } catch (e) { showToast('❌ 改名失败', 'error'); }
    });
  }

  // 目录选择器：把某文件夹/便签移到目标父级（含虚拟根）——v116 起走目录选择弹窗（自己及后代自动排除）
  function promptMoveFolder(folder) {
    openMoveDialog('folder', folder);
  }

  async function moveFolderTo(folderId, parentId) {
    try {
      var r = await folderApi('PUT', { id: folderId, parent_id: parentId });
      if (r.success) { showToast('✅ 文件夹已移动', 'success'); await loadFolders(); refreshView(); }
      else showToast('❌ ' + (r.message || '移动失败'), 'error');
    } catch (e) { showToast('❌ 移动失败', 'error'); }
  }

  async function promptDeleteFolder(folder) {
    var hint = folder.note_count > 0
      ? '该文件夹含 ' + folder.note_count + ' 条便签，删除后内容会上移到父级。确认删除「' + folder.name + '」？'
      : '确认删除空文件夹「' + folder.name + '」？';
    if (!confirm(hint)) return;
    try {
      var r = await folderApi('DELETE', { id: folder.id });
      if (r.success) {
        showToast('🗑 已删除（内容上移）', 'success');
        if (currentFolderId === folder.id) switchFolder(currentFolderId ? foldersById[folder.id].parent_id : null);
        await loadFolders(); refreshView();
      } else showToast('❌ ' + (r.message || '删除失败'), 'error');
    } catch (e) { showToast('❌ 删除失败', 'error'); }
  }

  // 把便签移动到某文件夹（跨层移动走这个，不是拖拽）——v116 起走目录选择弹窗
  function promptMoveNote(note) {
    openMoveDialog('note', note);
  }

  // ============== 移动到…目录选择弹窗（v116：替代远古 prompt 序号法） ==============
  // 交互参考网盘式「选择目标文件夹」：单击选中（确认按钮跟随），双击进入子层级，
  // 路径条点击跳级；确认目标 = 选中项（未选中时为当前浏览层级）。
  function openMoveDialog(kind, obj) {
    // kind: 'note' | 'folder' | 'batch'（选择条批量移动，obj={notes:[id],folders:[id]}）
    // 文件夹移动排除自己及后代（绝不移进自己）
    var overlay = mkEl('div', 'md-modal-overlay');
    overlay.style.zIndex = '21000';
    var modal = mkEl('div', 'md-modal move-dialog');
    var browseId = null;    // 当前浏览层级（null = 主页根）
    var selectedId = null;  // 单击选中的文件夹（null = 未选中，确认即当前浏览层级）
    var selFolder = kind === 'folder' ? obj : null;

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title',
      '<i class="ic ic-folder"></i> ' + (kind === 'note'
        ? '移动「' + chartTrunc(obj.title || '无标题', 16) + '」到…'
        : kind === 'folder'
          ? '移动文件夹「' + chartTrunc(obj.name || '', 16) + '」到…'
          : '移动 ' + (obj.notes.length + obj.folders.length) + ' 项到…')));
    var closeBtn = mkBtn('<i class="ic ic-close"></i>', '取消');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', close);
    head.appendChild(closeBtn);
    modal.appendChild(head);

    var body = mkEl('div', 'md-modal-body move-dialog-body');
    var crumb = mkEl('div', 'move-crumb');
    var list = mkEl('div', 'move-list');
    body.appendChild(crumb);
    body.appendChild(list);
    modal.appendChild(body);

    var foot = mkEl('div', 'md-modal-foot move-dialog-foot');
    var newBtn = mkBtn('<i class="ic ic-folder"></i> 新建文件夹', '在此层级新建子文件夹');
    newBtn.className = 'btn btn-outline btn-sm';
    var spacer = mkEl('span', 'move-foot-spacer');
    // 底部不再放「取消」——右上角 ✕ 是唯一关闭入口（与编辑器同款约定，手机端可一行放下）
    var okBtn = mkBtn('', '确认移动');
    okBtn.className = 'btn btn-primary btn-sm';
    foot.appendChild(newBtn);
    foot.appendChild(spacer);
    foot.appendChild(okBtn);
    modal.appendChild(foot);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    function close() { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }
    overlay.addEventListener('pointerdown', function (e) { if (e.target === overlay) close(); });

    // 排除集合（文件夹移动：自己 + 全部后代；批量：所有选中文件夹 + 后代）
    var exclude = {};
    if (kind === 'folder' && obj.id) {
      (function mark(pid) {
        Object.keys(foldersById).forEach(function (k) {
          if (foldersById[k].parent_id === pid) { exclude[k] = true; mark(foldersById[k].id); }
        });
      })(obj.id);
      exclude[obj.id] = true;
    }
    if (kind === 'batch') {
      (obj.folders || []).forEach(function (fid) {
        (function mark(pid) {
          Object.keys(foldersById).forEach(function (k) {
            if (foldersById[k].parent_id === pid) { exclude[k] = true; mark(foldersById[k].id); }
          });
        })(fid);
        exclude[fid] = true;
      });
    }

    function chainOf(fid) {
      var chain = [];
      var cur = fid ? foldersById[fid] : null;
      while (cur) { chain.unshift(cur); cur = cur.parent_id ? foldersById[cur.parent_id] : null; }
      return chain;
    }

    function targetId() { return selectedId !== null ? selectedId : browseId; }

    function render() {
      // 路径条：🏠 主页 / A / B（点击跳级）
      crumb.innerHTML = '';
      var chain = chainOf(browseId);
      var rootSeg = mkEl('span', 'move-crumb-seg' + (browseId === null ? ' active' : ''), '🏠 主页');
      rootSeg.addEventListener('click', function () { browseId = null; selectedId = null; render(); });
      crumb.appendChild(rootSeg);
      chain.forEach(function (f) {
        crumb.appendChild(mkEl('span', 'move-crumb-sep', '/'));
        var seg = mkEl('span', 'move-crumb-seg' + (browseId === f.id ? ' active' : ''), f.name);
        seg.addEventListener('click', function () { browseId = f.id; selectedId = null; render(); });
        crumb.appendChild(seg);
      });
      // 列表：当前浏览层级的子文件夹
      list.innerHTML = '';
      var kids = Object.keys(foldersById).map(function (k) { return foldersById[k]; })
        .filter(function (f) { return (f.parent_id || null) === browseId && !exclude[f.id]; })
        .sort(function (a, b) { return a.sort_order - b.sort_order || a.id - b.id; });
      if (!kids.length) list.appendChild(mkEl('div', 'move-empty', '（这层没有子文件夹）'));
      var isTouch = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || 'ontouchstart' in window;   // 手机：单击直接进层
      kids.forEach(function (f) {
        var row = mkEl('div', 'move-row' + (!isTouch && selectedId === f.id ? ' active' : ''));
        row.appendChild(mkEl('span', 'move-row-icon', '<i class="ic ic-folder"></i>'));
        row.appendChild(mkEl('span', 'move-row-name', f.name));
        row.appendChild(mkEl('span', 'move-row-count', (f.note_count || 0) + ' 条'));
        if (isTouch) {
          row.addEventListener('click', function () {          // 触屏：单击直接进入（双击在手机不可靠）
            browseId = f.id; selectedId = null; render();
          });
        } else {
          row.addEventListener('click', function () {          // 桌面：单击选中，确认按钮跟随
            selectedId = f.id; render();
          });
          row.addEventListener('dblclick', function () {       // 桌面：双击进入该层级
            browseId = f.id; selectedId = null; render();
          });
        }
        list.appendChild(row);
      });
      // 确认按钮：文案跟随目标层级；单对象原地移动时禁用（批量允许部分原地，PUT 同值无害）
      var tName = targetId() === null ? '主页' : (foldersById[targetId()] ? chartTrunc(foldersById[targetId()].name, 12) : '主页');
      okBtn.textContent = '移动到「' + tName + '」';   // 纯文字（ic-check 图标集里没有，别再放空占位）
      var inPlace = kind === 'note'
        ? obj.folder_id === targetId()
        : kind === 'folder'
          ? (obj.parent_id || null) === targetId()
          : false;
      okBtn.disabled = !!inPlace;
    }

    okBtn.addEventListener('click', function () {
      var t = targetId();
      if (kind === 'note') {
        api('PUT', { id: obj.id, folder_id: t }).then(function (r) {
          if (r.success) { showToast('📁 已移动', 'success'); close(); loadFolders(); refreshView(); }
          else showToast('❌ ' + (r.message || '移动失败'), 'error');
        });
      } else if (kind === 'folder') {
        close();
        moveFolderTo(obj.id, t);
      } else {
        // 批量：便签 PUT folder_id + 文件夹 PUT parent_id，全部完成后统一刷新
        var jobs = [];
        (obj.notes || []).forEach(function (nid) { jobs.push(api('PUT', { id: nid, folder_id: t })); });
        (obj.folders || []).forEach(function (fid) { jobs.push(folderApi('PUT', { id: fid, parent_id: t })); });
        Promise.all(jobs).then(function (rs) {
          var ok = rs.every(function (r) { return r && r.success; });
          close();
          if (ok) {
            showToast('📁 已移动 ' + rs.length + ' 项', 'success');
            if (window.PixelSelection) window.PixelSelection.reset();
            loadFolders(); refreshView();
          } else showToast('❌ 部分移动失败', 'error');
        });
      }
    });

    // 新建文件夹：inline 输入行（回车创建，Esc/失焦取消或提交）
    newBtn.addEventListener('click', function () {
      if (list.querySelector('.move-newrow')) return;
      var row = mkEl('div', 'move-newrow');
      var input = mkEl('input', 'move-newinput');
      input.placeholder = '新文件夹名，回车创建（Esc 取消）';
      row.appendChild(input);
      list.insertBefore(row, list.firstChild);
      input.focus();
      var done = false;
      function commit(save) {
        if (done) return;
        done = true;
        var name = input.value.trim();
        if (row.parentNode) row.parentNode.removeChild(row);
        if (!save || !name) return;
        folderApi('POST', { name: name, parent_id: browseId }).then(function (r) {
          if (r.success) { showToast('✅ 文件夹已创建', 'success'); loadFolders().then(render); }
          else showToast('❌ ' + (r.message || '创建失败'), 'error');
        });
      }
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') commit(true);
        if (e.key === 'Escape') commit(false);
      });
      input.addEventListener('blur', function () { commit(true); });
    });

    render();
  }

  function surround(ta, before, after) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var sel = v.slice(s, e);
    ta.value = v.slice(0, s) + before + sel + after + v.slice(e);
    ta.focus();
    ta.selectionStart = s + before.length;
    ta.selectionEnd = s + before.length + sel.length;
    try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (err) { /* 老浏览器忽略 */ }
  }

  function linePrefix(ta, prefix) {
    var v = ta.value;
    var ls = v.lastIndexOf('\n', ta.selectionStart - 1) + 1;
    var le = ta.selectionEnd;
    var le2 = v.indexOf('\n', le);
    if (le2 === -1) le2 = v.length;
    var block = v.slice(ls, le2);
    var out = block.split('\n').map(function (l) { return prefix + l; }).join('\n');
    ta.value = v.slice(0, ls) + out + v.slice(le2);
    ta.focus();
    ta.selectionStart = ls;
    ta.selectionEnd = ls + out.length;
    try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (err) { /* 老浏览器忽略 */ }
  }

  function insertBlock(ta) {
    var s = ta.selectionStart, v = ta.value;
    var before = (s > 0 && v.charAt(s - 1) !== '\n') ? '\n' : '';
    ta.value = v.slice(0, s) + before + '```\n\n```' + v.slice(ta.selectionEnd);
    ta.focus();
    var pos = s + before.length + 4;
    ta.selectionStart = ta.selectionEnd = pos;
    try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (err) { /* 老浏览器忽略 */ }
  }

  function buildToolbar(bar, getTa) {
    var defs = [
      ['B', 'bold', '加粗 **文字**'],
      ['I', 'italic', '斜体 *文字*'],
      ['S', 'strike', '删除线 ~~文字~~'],
      ['H', 'heading', '标题 ## '],
      ['</>', 'code', '行内代码 `文字`'],
      ['{ }', 'codeblock', '代码块'],
      ['•', 'ul', '无序列表 - '],
      ['1.', 'ol', '有序列表 1. '],
      ['<i class="ic ic-checkall"></i>', 'task', '任务 - [ ] '],
      ['❝', 'quote', '引用 > '],
      ['<i class="ic ic-link"></i>', 'link', '链接 [文字](网址)'],
      ['<i class="ic ic-image"></i>', 'img', '图片 ![描述](网址)'],
      ['<i class="ic ic-upload"></i>', 'imgup', '上传图片到图床（自动转直链；粘贴图片同样生效）']
    ];
    defs.forEach(function (d) {
      var b = mkBtn(d[0], d[2]);
      b.className = 'md-btn';
      b.addEventListener('click', function () {
        var ta = getTa();
        if (!ta) return;
        switch (d[1]) {
          case 'bold': surround(ta, '**', '**'); break;
          case 'italic': surround(ta, '*', '*'); break;
          case 'strike': surround(ta, '~~', '~~'); break;
          case 'heading': linePrefix(ta, '## '); break;
          case 'code': surround(ta, '`', '`'); break;
          case 'codeblock': insertBlock(ta); break;
          case 'ul': linePrefix(ta, '- '); break;
          case 'ol': linePrefix(ta, '1. '); break;
          case 'task': linePrefix(ta, '- [ ] '); break;
          case 'quote': linePrefix(ta, '> '); break;
          case 'link': surround(ta, '[', '](https://)'); break;
          case 'img': surround(ta, '![', '](https://)'); break;
          case 'imgup':
            // 便签↔图床联动：选图上传 → 自动转直链（三端同链路，含手机相册）
            if (typeof ImgBridge === 'undefined') { showToast('❌ 图床联动模块未加载', 'error'); break; }
            var up = document.createElement('input');
            up.type = 'file';
            up.accept = 'image/*';
            // 手机 Chrome：未挂载到 DOM 的 <input type=file> 偶发 click 不振起原生选择器 → 挂上再点
            up.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;pointer-events:none;';
            document.body.appendChild(up);
            up.addEventListener('change', function () {
              var f = up.files && up.files[0];
              up.remove();
              if (f) ImgBridge.insert(ta, f);
            });
            up.click();
            break;
        }
      });
      bar.appendChild(b);
    });
  }

  // ============== 卡片 ==============
  function createNoteCard(note) {
    var card = mkEl('div', 'note-card ' + note.color + (note.pinned ? ' pinned' : ''));
    card.setAttribute('data-id', note.id);
    card.setAttribute('data-color', note.color);
    card._noteData = note;

    // 标题：可直接点击快速编辑
    var title = mkEl('div', 'note-title', note.title);
    title.contentEditable = 'true';
    title.setAttribute('data-field', 'title');
    title.title = '点击编辑标题';
    title.addEventListener('blur', function () { saveTitle(card); });
    title.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); title.blur(); }
    });

    // 内容：渲染后的 MD
    var content = makeContentDiv(note, card);
    var meta = buildMeta(note, card);

    card.appendChild(title);
    card.appendChild(content);
    card.appendChild(meta);
    return card;
  }

  function makeContentDiv(note, card) {
    var nd = mkEl('div', 'note-content md-body md-static');
    nd.innerHTML = window.PixelMD.render(note.content);
    // 阅读全文判定稳定性修复（v126）：图片异步加载完成会让内容长高，若只在渲染时判定一次，
    // 「有图长文」在首刷（图片未加载）时会被误判为短文、按钮消失，刷新（缓存秒加载）后又出现。
    // 给每张未完成的图片挂 load/error → 重跑截断检测即可 100% 稳定。
    nd.querySelectorAll('img').forEach(function (img) {
      if (!img.complete) {
        img.addEventListener('load', function () { checkClamp(card); }, { once: true });
        img.addEventListener('error', function () { checkClamp(card); }, { once: true });
      }
    });
    nd.addEventListener('click', function (e) {
      if (window.PixelSelection && window.PixelSelection.isActive()) return;   // 选择模式：点击不打开
      if (e.target && e.target.closest && e.target.closest('a')) return;
      if (nd.classList.contains('clamped')) {
        openModal(note.id, card);       // 长文 → 弹窗阅读（卡片 morph 放大）
      } else {
        openEditorForNote(note.id, card); // 短文 → 全屏编辑器（卡片 morph 放大）
      }
    });
    return nd;
  }

  // 窗口尺寸变化后网格列宽会变，截断状态需要重算（防抖 200ms）
  var clampResizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(clampResizeTimer);
    clampResizeTimer = setTimeout(function () {
      document.querySelectorAll('.note-card').forEach(checkClamp);
    }, 200);
  });

  function buildMeta(note, card) {
    var meta = mkEl('div', 'note-meta');
    meta.appendChild(mkEl('span', null, '🕐 ' + (note.updated_at || '')));
    var actions = mkEl('div', 'note-actions');

    var editBtn = mkBtn('<i class="ic ic-pencil"></i>', '编辑这篇便签');
    editBtn.addEventListener('click', function () { openEditorForNote(note.id, card); });

    // 从 share_token 构建 share_url（参考图床 view.php 的做法，不依赖 API 返回 share_url）
    var _shareUrl = note.share_url || (note.share_token && String(note.share_token).length === 36 ? location.origin + '/share.php?t=' + note.share_token : '');
    var shareBtn = mkBtn(_shareUrl ? '🌐' : '<i class="ic ic-link"></i>', _shareUrl ? '管理公开分享' : '生成公开分享链接');   // 已分享用 🌐（图标集暂无 globe，先用系统 emoji）；未分享用 link 图片图标
    if (_shareUrl) { shareBtn.classList.add('btn-shared'); card._shareUrl = _shareUrl; }
    shareBtn.addEventListener('click', function () { openShareDialog(note.id, card); });

    var pinBtn = mkBtn('<i class="ic ic-pin"></i>', note.pinned ? '取消置顶' : '置顶');
    pinBtn.addEventListener('click', function () { togglePin(card); });

    var colorBtn = mkBtn('<i class="ic ic-palette"></i>', '切换颜色');
    colorBtn.addEventListener('click', function () { cycleColor(card); });

    var moveBtn = mkBtn('<i class="ic ic-folder"></i>', '移动到文件夹');
    moveBtn.addEventListener('click', function (e) { e.stopPropagation(); promptMoveNote(note); });
    // 合并时被错误带回：按设计此按钮不展示（仅隐藏、保留代码；需恢复时删掉下面这行即可）
    moveBtn.style.display = 'none';

    var delBtn = mkBtn('<i class="ic ic-trash"></i>', '删除便签');
    delBtn.addEventListener('click', function () { deleteNote(card); });

    actions.appendChild(editBtn);
    actions.appendChild(shareBtn);
    actions.appendChild(pinBtn);
    actions.appendChild(colorBtn);
    actions.appendChild(moveBtn);
    actions.appendChild(delBtn);
    meta.appendChild(actions);
    return meta;
  }

  // 长文截断检测
  function checkClamp(card) {
    var nd = card.querySelector('.note-content');
    if (!nd) return;
    var rm = card.querySelector('.read-more');
    if (nd.scrollHeight > nd.clientHeight + 6) {
      nd.classList.add('clamped');
      if (!rm) {
        rm = mkBtn('<i class="ic ic-book"></i> 阅读全文', '点击查看完整内容');
        rm.className = 'read-more';
        rm.addEventListener('click', function () {
          if (window.PixelSelection && window.PixelSelection.isActive()) return;   // 选择模式：点击不打开
          openModal(parseInt(card.getAttribute('data-id')), card);
        });
        nd.insertAdjacentElement('afterend', rm);
      }
      rm.style.display = '';
    } else {
      nd.classList.remove('clamped');
      if (rm) rm.style.display = 'none';
    }
  }

  // ============== 统一大编辑器（新建 / 编辑 共用） ==============
  var newNoteForm = document.getElementById('newNoteForm');
  var btnNewNote = document.getElementById('btnNewNote');
  var btnSaveNew = document.getElementById('btnSaveNew');
  var newColorPicker = document.getElementById('newColorPicker');
  var newTitle = document.getElementById('newTitle');
  var newContent = document.getElementById('newContent');
  var newPreview = document.getElementById('newPreview');
  var newToolbar = document.getElementById('newToolbar');
  var editorMode = document.getElementById('editorMode');
  var selectedColor = 'yellow';
  var editingId = null;   // null = 新建模式；数字 = 正在编辑的笔记 id

  function setColorPicker(color) {
    selectedColor = color;
    newColorPicker.querySelectorAll('.color-dot').forEach(function (d) {
      d.classList.toggle('active', d.getAttribute('data-color') === color);
    });
  }

  function hideEditor() {
    editingId = null;
    var doHide = function (guardClose) {
      // 淡出期间又被重新打开（closing 已被 openEditorForNote/btnNewNote 清掉）：放弃本次关闭
      if (guardClose && !newNoteForm.classList.contains('closing')) return;
      newNoteForm.classList.remove('edit-mode');
      newNoteForm.classList.remove('closing');
      newNoteForm.style.display = 'none';
      newTitle.value = '';
      newContent.value = '';
      newPreview.style.display = 'none';
      newPreview.innerHTML = '';
      if (editorMode) editorMode.textContent = '';
      btnSaveNew.innerHTML = '<i class="ic ic-save-pink"></i> 保存';
      // 取消卡片高亮
      document.querySelectorAll('.note-card.editing-source').forEach(function (c) {
        c.classList.remove('editing-source');
      });
    };
    // 关闭 = 干净淡出（编辑器不再参与 View Transition：全屏 VT 快照曾导致浏览器无响应）
    if (newNoteForm.style.display === 'block') {
      newNoteForm.classList.add('closing');
      setTimeout(function () { doHide(true); }, 150);
    } else {
      doHide(false);
    }
  }

  // 新建模式
  btnNewNote.addEventListener('click', function () {
    if (editingId !== null) { hideEditor(); return; }
    if (newNoteForm.style.display === 'none' || newNoteForm.style.display === '') {
      editingId = null;
      newNoteForm.classList.remove('edit-mode');
      if (editorMode) editorMode.innerHTML = '<i class="ic ic-plus"></i> 新建便签';
      btnSaveNew.innerHTML = '<i class="ic ic-save-pink"></i> 保存';
      setColorPicker('yellow');
      renderLivePreview(newContent.value);   // 空内容 → 预览区保持隐藏
      newNoteForm.style.display = 'block';   // 全屏覆盖层，CSS 进入动画（淡入上浮）
      newTitle.focus();
    } else {
      hideEditor();
    }
  });

  // 编辑模式：打开同一个大面板并填充原文（摘要便签先按需拉全文）
  async function openEditorForNote(id, sourceCard) {
    var note = notesById[id];
    if (!note) return;
    editingId = id;

    // 显示全屏编辑器（CSS 淡入上浮进入动画；不再参与 View Transition——
    // 全屏 VT 快照曾导致浏览器无响应，稳定优先）
    var showEditor = function () {
      closeModal(true);   // 静默移除详情弹窗（若有）
      newNoteForm.classList.remove('edit-mode');
      newNoteForm.classList.remove('closing');
      newNoteForm.style.display = 'block';
    };
    showEditor();

    if (note._more) {
      // 首屏只传了 2000 字摘要：先亮出面板给加载反馈，拉到全文后再填充
      newTitle.value = note.title;
      newContent.value = '';
      renderLivePreview('');   // 清掉上一篇残留的预览
      if (editorMode) editorMode.textContent = '⏳ 正在加载全文…';
      try {
        var resp = await fetch(API_BASE + '?id=' + id, { credentials: 'include', cache: 'no-store' });
        var r = await resp.json();
        if (r.success && r.note && editingId === id) {
          note.content = r.note.content || '';
          note._more = 0;
        } else if (editingId === id) {
          if (editorMode) editorMode.textContent = '⚠️ 全文加载失败（将使用摘要编辑）';
        }
      } catch (e) {
        if (editingId === id && editorMode) editorMode.textContent = '⚠️ 全文加载失败（将使用摘要编辑）';
      }
    }
    if (editingId !== id) return;   // 等待期间用户切换了编辑对象

    // 高亮正在编辑的卡片
    document.querySelectorAll('.note-card.editing-source').forEach(function (c) {
      c.classList.remove('editing-source');
    });
    var card = document.querySelector('.note-card[data-id="' + id + '"]');
    if (card) card.classList.add('editing-source');

    newTitle.value = note.title;
    newContent.value = note.content;
    renderLivePreview(note.content);   // 打开即渲染实时预览
    setColorPicker(note.color);
    setIconText(editorMode, 'pencil', '正在编辑：' + (note.title || '无标题'));
    btnSaveNew.innerHTML = '<i class="ic ic-save-pink"></i> 保存修改';
    newNoteForm.classList.add('edit-mode');
    setTimeout(function () { newContent.focus(); }, 250);
  }

  // 全屏编辑器右上角 ✕ 关闭（底部取消按钮已移除，✕ 是唯一关闭入口）
  var editorCloseBtn = document.getElementById('editorClose');
  if (editorCloseBtn) editorCloseBtn.addEventListener('click', hideEditor);

  newColorPicker.addEventListener('click', function (e) {
    var dot = e.target.closest('.color-dot');
    if (!dot) return;
    setColorPicker(dot.getAttribute('data-color'));
  });

  if (newToolbar) buildToolbar(newToolbar, function () { return newContent; });

  // ---- 实时预览：编辑区内容一变，下方立刻渲染 Markdown ----
  // 空内容时隐藏预览区（不摆一个空框）；程序改值（工具栏/AI/图片直链）走 dispatch 或显式调用
  function renderLivePreview(text) {
    if (!newPreview) return;
    if (!text || !text.trim()) {
      newPreview.style.display = 'none';
      newPreview.innerHTML = '';
      return;
    }
    newPreview.innerHTML = window.PixelMD.render(text);
    newPreview.style.display = 'block';
  }
  var previewTimer = null;
  newContent.addEventListener('input', function () {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(function () { renderLivePreview(newContent.value); }, 200);
  });

  // 保存（新建 POST / 编辑 PUT）
  var saveBusy = false; // 防重复提交锁：网络慢时连点/误点只发一次请求
  btnSaveNew.addEventListener('click', async function () {
    if (saveBusy) return;
    // 便签↔图床联动：图片上传进行中禁止保存（防占位符落库）
    if (typeof ImgBridge !== 'undefined' && ImgBridge.isBusy()) {
      showToast('⏳ 图片还在上传到图床，稍等一下喵', 'error');
      return;
    }
    var title = newTitle.value.trim();
    var content = newContent.value.trim();

    if (editingId !== null) {
      // === 编辑模式 ===
      if (!title && !content) {
        showToast('⚠️ 标题和内容不能都为空', 'error');
        return;
      }
      saveBusy = true; btnSaveNew.disabled = true; btnSaveNew.textContent = '💾 保存中…';
      var saveId = editingId;   // 锁存本次保存目标：hideEditor 会清空 editingId，原位替换必须用保存前的 id
      try {
        var r = await api('PUT', {
          id: saveId,
          title: title,
          content: content,
          color: selectedColor
        });
        if (r.success) {
          var n = notesById[saveId];
          if (n) {
            n.title = title;
            n.content = content;
            n.color = selectedColor;
            n.updated_at = nowStr();
            n._more = 0;   // 编辑器内容即全文，清除摘要标记
          }
          hideEditor();
          showToast('💾 已保存', 'success');
          // 原位替换整张卡片：用最新数据重建，保持卡片在网格中的位置不变
          var oldCard = notesGrid.querySelector('.note-card[data-id="' + saveId + '"]');
          if (oldCard && n) {
            var freshCard = createNoteCard(n);
            oldCard.parentNode.replaceChild(freshCard, oldCard);
            checkClamp(freshCard);
          }
        } else {
          showToast('❌ ' + (r.message || '保存失败'), 'error');
        }
      } catch (e) {
        if (String(e.message).indexOf('未登录') === -1) showToast('❌ 保存失败', 'error');
      } finally {
        saveBusy = false; btnSaveNew.disabled = false; btnSaveNew.innerHTML = '<i class="ic ic-save-pink"></i> 保存修改';
      }
      return;
    }

    // === 新建模式 ===
    if (!title && !content) {
      showToast('⚠️ 标题和内容不能都为空', 'error');
      return;
    }
    saveBusy = true; btnSaveNew.disabled = true; btnSaveNew.textContent = '💾 保存中…';
    try {
      var r2 = await api('POST', { title: title, content: content, color: selectedColor, folder_id: currentFolderId });
      if (r2.success) {
        hideEditor();
        showToast('✅ 便签已创建！', 'success');
        loadNotes();
      } else {
        showToast('❌ ' + (r2.message || '创建失败'), 'error');
      }
    } catch (e) {
      showToast('❌ 创建失败', 'error');
    } finally {
      saveBusy = false; btnSaveNew.disabled = false; btnSaveNew.textContent = '💾 保存';
    }
  });

  newContent.addEventListener('keydown', function (e) {
    if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); btnSaveNew.click(); }
    if (e.key === 'Escape') { e.preventDefault(); hideEditor(); }
  });

  // ============== 搜索 + 推荐（客户端，数据全在内存） ==============
  var searchInput = document.getElementById('searchInput');
  var searchPanel = document.getElementById('searchPanel');
  var searchBox = document.getElementById('searchBox');

  // 子序列匹配检测：query 的每个字符依次出现在 text 中
  function isSubsequence(q, t) {
    var i = 0;
    for (var j = 0; j < t.length && i < q.length; j++) {
      if (t[j] === q[i]) i++;
    }
    return i === q.length;
  }

  // 模糊打分：越小越好；返回 null 表示不匹配
  function fuzzyScore(query, note) {
    var q = query.toLowerCase().trim();
    if (!q) return null;
    var title = (note.title || '').toLowerCase();
    var content = (note.content || '').toLowerCase();
    if (title === q) return 0;           // 标题精确
    if (title.indexOf(q) === 0) return 5; // 标题前缀
    if (title.indexOf(q) !== -1) return 10; // 标题子串
    if (isSubsequence(q, title)) return 25; // 标题子序列（中文逐字匹配）
    if (content.indexOf(q) !== -1) return 40; // 内容子串
    if (isSubsequence(q, content)) return 60; // 内容子序列
    return null;
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  function buildSearchResults(query) {
    var out = [];
    var q = query.toLowerCase().trim();

    // 文件夹匹配（按名称）
    Object.keys(foldersById).forEach(function (k) {
      var f = foldersById[k];
      var name = (f.name || '').toLowerCase();
      if (!q || name === q || name.indexOf(q) !== -1 || isSubsequence(q, name)) {
        var chain = folderChain(f.id).map(function (x) { return x.name; }).join(' / ');
        out.push({ type: 'folder', fid: f.id, label: '📁 ' + chartTrunc(f.name, 28), path: chain });
      }
    });

    // 便签匹配
    Object.keys(notesById).forEach(function (k) {
      var n = notesById[k];
      var score = fuzzyScore(q, n);
      if (score !== null) {
        var chain = n.folder_id ? folderChain(n.folder_id).map(function (x) { return x.name; }).join(' / ') : '';
        out.push({ type: 'note', nid: n.id, score: score, label: chartTrunc(n.title || '无标题', 28), path: chain });
      }
    });

    out.sort(function (a, b) {
      var ta = a.type === 'folder' ? 0 : 1;
      var tb = b.type === 'folder' ? 0 : 1;
      return ta !== tb ? ta - tb : (a.score || 0) - (b.score || 0);
    });
    return out.slice(0, 12);
  }

  // 推荐（搜索框为空时）：当前目录的子文件夹 + 置顶/最近便签
  function buildRecommendations() {
    var out = [];
    var childFolders = Object.keys(foldersById).map(function (k) { return foldersById[k]; })
      .filter(function (f) { return (f.parent_id || null) === currentFolderId; })
      .slice(0, 4);
    childFolders.forEach(function (f) {
      var chain = folderChain(f.id).map(function (x) { return x.name; }).join(' / ');
      out.push({ type: 'folder', fid: f.id, label: '📁 ' + chartTrunc(f.name, 28), path: chain });
    });
    var viewNotes = Object.keys(notesById).map(function (k) { return notesById[k]; })
      .filter(function (n) { return n.folder_id === currentFolderId; })
      .sort(function (a, b) { return (b.pinned - a.pinned) || (b.updated_at || '').localeCompare(a.updated_at || ''); })
      .slice(0, 4);
    viewNotes.forEach(function (n) {
      out.push({ type: 'note', nid: n.id, label: (n.pinned ? '📌 ' : '') + chartTrunc(n.title || '无标题', 28), path: '' });
    });
    return out;
  }

  function chartTrunc(s, max) {
    return s.length > max ? s.slice(0, max - 1) + '…' : s;
  }

  function renderSearchPanel(items, isRecommend) {
    searchPanel.innerHTML = '';
    var head = mkEl('div', 'search-head', isRecommend ? '⇨ 本目录推荐' : '⇨ 命中结果');
    searchPanel.appendChild(head);
    if (items.length === 0) {
      searchPanel.appendChild(mkEl('div', 'search-empty', '没有匹配项'));
    } else {
      items.forEach(function (it) {
        var row = mkEl('div', 'search-item');
        row.appendChild(mkEl('div', 'search-item-label', it.label));
        if (it.path) row.appendChild(mkEl('div', 'search-item-path', it.path));
        row.addEventListener('click', function () {
          if (it.type === 'folder') {
            switchFolder(it.fid);
            searchInput.value = '';
            closeSearchOverlay();
          } else {
            var n = notesById[it.nid];
            if (!n) return;
            var targetFolder = n.folder_id;
            if (targetFolder !== currentFolderId) switchFolder(targetFolder);
            searchInput.value = '';
            closeSearchOverlay();
            setTimeout(function () {
              var card = notesGrid.querySelector('.note-card[data-id="' + it.nid + '"]');
              if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.classList.add('search-flash');
                setTimeout(function () { card.classList.remove('search-flash'); }, 1200);
              }
            }, 100);
          }
        });
        searchPanel.appendChild(row);
      });
    }
    searchPanel.style.display = 'block';
  }

  function hideSearchPanel() { searchPanel.style.display = 'none'; }

  searchInput.addEventListener('input', function () {
    var q = searchInput.value.trim();
    if (!q) { renderSearchPanel(buildRecommendations(), true); return; }
    renderSearchPanel(buildSearchResults(q), false);
  });
  searchInput.addEventListener('focus', function () {
    if (!searchInput.value.trim()) renderSearchPanel(buildRecommendations(), true);
    else renderSearchPanel(buildSearchResults(searchInput.value.trim()), false);
  });
  searchInput.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { searchInput.value = ''; closeSearchOverlay(); }
  });
  document.addEventListener('pointerdown', function (e) {
    if (searchBox && !searchBox.contains(e.target)) hideSearchPanel();
  });

  // ============== AI 整理对话框（多轮对话记忆 + 一键自动 + 追加指示） ==============
  // 对话状态只存在 sessionStorage（关闭标签页即失效，不持久化）
  var AI_CHATS_KEY = 'pixel_notes_ai_classify_chats';
  var classifyOverlay = null;      // AI 整理聊天对话框
  var classifyPreviewOverlay = null; // AI 整理方案预览面板（与聊天框是两个独立模态）
  var aiClassifyPending = null;    // 待执行的 AI 分类方案（预览确认后使用）

  function loadAiChats() {
    try {
      var raw = sessionStorage.getItem(AI_CHATS_KEY);
      if (raw) { var d = JSON.parse(raw); if (Array.isArray(d)) return d; }
    } catch (e) {}
    return [{ role: 'assistant', content: '您好！我可以帮您把便签整理进文件夹。点击「一键自动整理」让 AI 给出方案，或在下方输入框描述您的整理思路（如「只把 Python 学习相关的放进 学习」）。' }];
  }
  function saveAiChats(chats) { try { sessionStorage.setItem(AI_CHATS_KEY, JSON.stringify(chats)); } catch (e) {} }
  function clearAiChats() {
    try { sessionStorage.removeItem(AI_CHATS_KEY); } catch (e) {}
  }

  function openClassifyDialog() {
    if (classifyOverlay) return;
    var overlay = mkEl('div', 'md-modal-overlay');
    overlay.style.zIndex = '20000';

    var modal = mkEl('div', 'md-modal classify-modal');

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-robot"></i> AI 整理助手'));
    var closeBtn = mkBtn('<i class="ic ic-close"></i> 关闭', '关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeClassifyDialog);
    head.appendChild(closeBtn);
    modal.appendChild(head);

    // 对话区
    var chatLog = mkEl('div', 'classify-chat-log', '');
    chatLog.id = 'aiChatLog';

    var chats = loadAiChats();
    chats.forEach(function (c) { renderChatBubble(chatLog, c.role, c.content); });

    modal.appendChild(chatLog);

    // 一键整理区（撤销按钮也收进这里——它只对 AI 整理生效，放工具栏占地方）
    var oneClickWrap = mkEl('div', 'classify-oneclick');
    var oneClickBtn = mkBtn('⚡ 一键自动整理', '让 AI 处理所有未整理的便签');
    oneClickBtn.className = 'btn btn-primary btn-sm';
    oneClickBtn.addEventListener('click', function () {
      appendUserChat('[一键自动整理]（没写指令）');
      runAiClassifyInternal(null);
    });
    oneClickWrap.appendChild(oneClickBtn);

    var undoBtn = mkBtn('<i class="ic ic-back"></i> 撤销上次整理', '撤销本会话最近一次 AI 整理（仅限本次浏览器会话）');
    undoBtn.id = 'btnUndoAiOff';
    undoBtn.className = 'btn btn-outline btn-sm';
    undoBtn.disabled = true;   // 常驻但禁用态起步：无日志时灰显"无可撤销"，有日志时点亮
    undoBtn.addEventListener('click', function () { if (!undoBtn.disabled) undoLastClassify(); });
    oneClickWrap.appendChild(undoBtn);

    // 输入行
    var inputWrap = mkEl('div', 'classify-input-wrap');
    var input = mkEl('input', 'classify-input');
    input.type = 'text';
    input.placeholder = '告诉 AI 您的整理偏好（如：只整理和 Python 相关的便签）';
    input.maxLength = 500;
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); submitChat(); }
    });

    var sendBtn = mkBtn('发送', '发送整理指示');
    sendBtn.className = 'btn btn-outline btn-sm';
    sendBtn.addEventListener('click', submitChat);

    var clearHistBtn = mkBtn('<i class="ic ic-trash"></i> 清对话', '清空对话记录（本页重启后保留）');
    clearHistBtn.className = 'btn btn-outline btn-sm';
    clearHistBtn.addEventListener('click', function () { clearAiChats(); chatLog.innerHTML = ''; renderChatBubble(chatLog, 'assistant', '对话已清空。'); });

    function submitChat() {
      var text = input.value.trim();
      if (!text) { showToast('⚠️ 先输入指令', 'error'); return; }
      input.value = '';
      appendUserChat(text);
      runAiClassifyInternal(text);
    }

    function appendUserChat(text) {
      renderChatBubble(chatLog, 'user', text);
      var chatsNow = loadAiChats();
      chatsNow.push({ role: 'user', content: text });
      saveAiChats(chatsNow);
    }

    inputWrap.appendChild(input); inputWrap.appendChild(sendBtn); inputWrap.appendChild(clearHistBtn);
    modal.appendChild(oneClickWrap); modal.appendChild(inputWrap);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    classifyOverlay = overlay;

    input.focus();
    checkPendingClassify();   // 打开拟态框即刷新撤销按钮（按钮已收进本框）
  }

  function closeClassifyDialog() {
    if (classifyOverlay) { classifyOverlay.remove(); classifyOverlay = null; }
    currentFolderId = currentFolderId;   // keep current view
  }

  function renderChatBubble(chatLog, role, text) {
    if (!chatLog) return;
    var b = mkEl('div', 'chat-bubble ' + role, '');
    b.innerHTML = '';
    var textEl = mkEl('div', 'chat-text');
    textEl.textContent = text;
    b.appendChild(textEl);
    chatLog.appendChild(b);
    chatLog.scrollTop = chatLog.scrollHeight;
  }

  function runAiClassifyInternal(extraInstruction) {
    var manifest = buildClassifyManifest();
    if (manifest.length === 0) { showToast('⚠️ 没有便签可整理', 'error'); return; }

    var chatLog = document.getElementById('aiChatLog');
    // Agent 式气泡：实时显示思考流 / 工具调用卡片 / 最终结果
    var agentBubble = null, thinkEl = null, toolsEl = null;
    if (chatLog) {
      agentBubble = mkEl('div', 'chat-bubble assistant agent');
      var phaseEl = mkEl('div', 'agent-phase');
      setPhaseText(phaseEl, '🤖 开始分析…');
      thinkEl = mkEl('div', 'chat-text agent-think');
      toolsEl = mkEl('div', 'agent-tools');
      agentBubble.appendChild(phaseEl);
      agentBubble.appendChild(thinkEl);
      agentBubble.appendChild(toolsEl);
      chatLog.appendChild(agentBubble);
      chatLog.scrollTop = chatLog.scrollHeight;
    }
    function setPhase(t) { if (agentBubble) { agentBubble.querySelector('.agent-phase').textContent = t; chatLog.scrollTop = chatLog.scrollHeight; } }
    function appendThink(t) { if (thinkEl) { thinkEl.textContent += t; chatLog.scrollTop = chatLog.scrollHeight; } }
    function appendTool(name, args, brief) {
      if (!toolsEl) return;
      var card = mkEl('div', 'tool-card');
      var head = mkEl('div', 'tool-card-head', '🔧 ' + name + (args ? '（' + args + '）' : ''));
      card.appendChild(head);
      if (brief) { var body = mkEl('div', 'tool-card-body'); body.textContent = brief; card.appendChild(body); }
      toolsEl.appendChild(card);
      chatLog.scrollTop = chatLog.scrollHeight;
    }
    // 最终把 Agent 气泡收尾成一句总结（存进对话记忆）
    function finalizeAgent(text) {
      if (chatLog && agentBubble) {
        // 移除思考过程，只留一句总结，便于回看历史
        agentBubble.querySelector('.agent-phase').textContent = text;
        thinkEl.remove(); toolsEl.remove();
        chatLog.scrollTop = chatLog.scrollHeight;
      }
    }

    var folderTree = buildFolderTreeManifest();
    var promptText = '请整理以下便签与文件夹。现有文件夹结构如下：\n' + (folderTree.length ? folderTree.join('\n') : '(无)\n');
    promptText += '\n便签清单（id | 标题 | 内容前80字摘要 | 当前路径）：\n';
    manifest.forEach(function (m) { promptText += m.id + ' | ' + (m.title || '(无标题)') + ' | ' + m.snippet + ' | ' + m.current_path + '\n'; });
    promptText += '\n操作协议：{"ops":[...]}，每个 op 为 move/mkdir/rename/delete_folder/delete_note/sort_notes/color/pin 之一（详见系统说明）；path 最多5段每段1-30字符，"主页"=根层级；只在用户指令范围内操作。';
    if (extraInstruction) promptText += '\n用户附加指令：' + extraInstruction + '（必须严格遵守）';
    promptText += '\n';

    // 携带与编辑相同的鉴权参数（政策版本 + 平台/自有 Key 双模式）
    var classPrefs = loadAiPrefs();
    aiApiStream({
      action: 'classify',
      message: promptText,
      policyVersion: AI_POLICY_VERSION,
      prefs: {
        mode: classPrefs.mode,
        platformKey: classPrefs.platformKey,
        ownBaseUrl: classPrefs.ownBaseUrl,
        ownApiKey: classPrefs.ownApiKey,
        ownModel: classPrefs.ownModel
      }
    }, {
      onPhase: function (t) { setPhase(t); },
      onDelta: function (t) { appendThink(t); },
      onTool: function (d) { appendTool(d.name, d.args, null); },
      onToolResult: function (d) {
        // 工具结果追加到最近一张工具卡片里
        if (!toolsEl) return;
        var last = toolsEl.lastElementChild;
        if (last) {
          var body = mkEl('div', 'tool-card-body');
          body.textContent = d.brief || '';
          last.appendChild(body);
          chatLog.scrollTop = chatLog.scrollHeight;
        }
      }
    })
      .then(function (result) {
        if (!result.success) {
          finalizeAgent('❌ ' + (result.message || 'AI 返回失败'));
          showToast('❌ ' + (result.message || 'AI 返回失败'), 'error');
          return;
        }
        var plan = result.plan;
        var ops = plan && plan.ops ? plan.ops : [];
        if (ops.length === 0) {
          finalizeAgent('✅ 分析完成：无需任何操作。');
          var chats = loadAiChats(); chats.push({ role: 'assistant', content: '无需操作' }); saveAiChats(chats); return;
        }
        var moveCount = ops.filter(function (o) { return o.op === 'move'; }).length;
        var mkdirCount = ops.filter(function (o) { return o.op === 'mkdir'; }).length;
        var renameCount = ops.filter(function (o) { return o.op === 'rename'; }).length;
        var delFolderCount = ops.filter(function (o) { return o.op === 'delete_folder'; }).length;
        var delNoteCount = ops.filter(function (o) { return o.op === 'delete_note'; }).length;
        var sortCount = ops.filter(function (o) { return o.op === 'sort_notes'; }).length;
        var colorCount = ops.filter(function (o) { return o.op === 'color'; }).length;
        var pinCount = ops.filter(function (o) { return o.op === 'pin'; }).length;
        var parts = [];
        if (moveCount) parts.push('移动 ' + moveCount + ' 条便签');
        if (mkdirCount) parts.push('新建 ' + mkdirCount + ' 个文件夹');
        if (renameCount) parts.push('改名 ' + renameCount + ' 个文件夹');
        if (delFolderCount) parts.push('删除 ' + delFolderCount + ' 个文件夹');
        if (delNoteCount) parts.push('删除 ' + delNoteCount + ' 条便签');
        if (sortCount) parts.push('排序 ' + sortCount + ' 处');
        if (colorCount) parts.push('改色 ' + colorCount + ' 条');
        if (pinCount) parts.push('置顶 ' + pinCount + ' 条');
        var msg = '📦 建议：' + parts.join('，') + '。请在预览面板确认具体方案。';
        finalizeAgent(msg);
        var chats = loadAiChats();
        chats.push({ role: 'assistant', content: msg }); saveAiChats(chats);
        aiClassifyPending = ops;
        renderClassifyPreview(ops, manifest);
      })
      .catch(function (err) {
        finalizeAgent('❌ 网络错误：' + err);
      });
  }

  function buildClassifyManifest() {
    return Object.keys(notesById).map(function (k) {
      var n = notesById[k];
      var snippet = (function () {
        var t = (n.content || '').replace(/\s+/g, ' ').trim();
        return t.length > 80 ? safeSlice(t, 77) + '...' : t;
      })();
      var path = n.folder_id ? folderChain(n.folder_id).map(function (f) { return f.name; }).join(' / ') : '主页';
      return { id: n.id, title: n.title || '(无标题)', snippet: snippet, current_path: path };
    });
  }

  function buildFolderTreeManifest() {
    var lines = [];
    function walk(parentId, depth) {
      Object.keys(foldersById).map(function (k) { return foldersById[k]; })
        .filter(function (f) { return (f.parent_id || null) === parentId; })
        .sort(function (a, b) { return a.sort_order - b.sort_order || a.id - b.id; })
        .forEach(function (f) {
          lines.push(new Array(depth + 1).join('  ') + f.name);
          walk(f.id, depth + 1);
        });
    }
    walk(null, 0);
    return lines;
  }

  // 预览面板：分组展示 + 确认/取消
  function closeClassifyPreview() {
    if (classifyPreviewOverlay) { classifyPreviewOverlay.remove(); classifyPreviewOverlay = null; }
    aiClassifyPending = null;
  }
  function renderClassifyPreview(ops, manifest) {
    closeClassifyPreview();
    var byId = {};
    manifest.forEach(function (m) { byId[m.id] = m; });

    var overlay = mkEl('div', 'md-modal-overlay');
    overlay.style.zIndex = '20100';   // 必须高于 AI 整理聊天框（20000），否则方案预览被聊天框遮挡、视觉上“夹在中间”
    var modal = mkEl('div', 'md-modal');
    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-robot"></i> AI 整理方案预览（确认后执行）'));
    var closeBtn = mkBtn('<i class="ic ic-close"></i> 关闭', '关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeClassifyPreview);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');

    // 三种操作分组渲染：move 按目标路径分组；mkdir / rename 逐条列出
    var groups = {};
    ops.forEach(function (o) {
      if (o.op !== 'move') return;
      var path = o.path || '主页';
      if (!groups[path]) groups[path] = [];
      groups[path].push(o);
    });
    Object.keys(groups).forEach(function (path) {
      var grp = mkEl('div', 'classify-group');
      grp.appendChild(mkEl('div', 'classify-path', '<i class="ic ic-folder"></i> ' + path));
      var ul = mkEl('ul', 'classify-notes');
      groups[path].forEach(function (o) {
        var it = byId[o.id];
        if (!it) return;
        var li = mkEl('li', null, '• ' + (it.title || '(无标题)') + '（当前：' + it.current_path + '） → ' + path);
        ul.appendChild(li);
      });
      grp.appendChild(ul);
      body.appendChild(grp);
    });
    var mkdirs = ops.filter(function (o) { return o.op === 'mkdir'; });
    if (mkdirs.length) {
      var grpM = mkEl('div', 'classify-group');
      grpM.appendChild(mkEl('div', 'classify-path', '📂 新建文件夹'));
      var ulM = mkEl('ul', 'classify-notes');
      mkdirs.forEach(function (o) { ulM.appendChild(mkEl('li', null, '• ' + o.path)); });
      grpM.appendChild(ulM);
      body.appendChild(grpM);
    }
    var renames = ops.filter(function (o) { return o.op === 'rename'; });
    if (renames.length) {
      var grpR = mkEl('div', 'classify-group');
      grpR.appendChild(mkEl('div', 'classify-path', '<i class="ic ic-pencil"></i> 重命名'));
      var ulR = mkEl('ul', 'classify-notes');
      renames.forEach(function (o) { ulR.appendChild(mkEl('li', null, '• ' + o.path + ' → ' + o.new_name)); });
      grpR.appendChild(ulR);
      body.appendChild(grpR);
    }
    var delFolders = ops.filter(function (o) { return o.op === 'delete_folder'; });
    if (delFolders.length) {
      var grpDF = mkEl('div', 'classify-group');
      grpDF.appendChild(mkEl('div', 'classify-path', '<i class="ic ic-trash"></i> 删除文件夹（内容上移）'));
      var ulDF = mkEl('ul', 'classify-notes');
      delFolders.forEach(function (o) { ulDF.appendChild(mkEl('li', null, '• ' + o.path)); });
      grpDF.appendChild(ulDF);
      body.appendChild(grpDF);
    }
    var delNotes = ops.filter(function (o) { return o.op === 'delete_note'; });
    if (delNotes.length) {
      var grpDN = mkEl('div', 'classify-group');
      grpDN.appendChild(mkEl('div', 'classify-path', '<i class="ic ic-trash"></i> 删除便签'));
      var ulDN = mkEl('ul', 'classify-notes');
      delNotes.forEach(function (o) {
        var it = byId[o.id];
        ulDN.appendChild(mkEl('li', null, '• #' + o.id + (it ? ' ' + (it.title || '(无标题)') : '')));
      });
      grpDN.appendChild(ulDN);
      body.appendChild(grpDN);
    }
    var sorts = ops.filter(function (o) { return o.op === 'sort_notes'; });
    if (sorts.length) {
      var grpS = mkEl('div', 'classify-group');
      grpS.appendChild(mkEl('div', 'classify-path', '🔢 排序'));
      var ulS = mkEl('ul', 'classify-notes');
      sorts.forEach(function (o) {
        var byName = { name: '标题', title_len: '标题长度', updated: '更新时间' };
        ulS.appendChild(mkEl('li', null, '• ' + (o.path || '主页') + '：按' + (byName[o.by] || o.by) + (o.order === 'desc' ? '降序' : '升序')));
      });
      grpS.appendChild(ulS);
      body.appendChild(grpS);
    }
    var colors = ops.filter(function (o) { return o.op === 'color'; });
    if (colors.length) {
      var grpC = mkEl('div', 'classify-group');
      grpC.appendChild(mkEl('div', 'classify-path', '<i class="ic ic-palette"></i> 修改颜色'));
      var ulC = mkEl('ul', 'classify-notes');
      colors.forEach(function (o) {
        var it = byId[o.id];
        ulC.appendChild(mkEl('li', null, '• #' + o.id + (it ? ' ' + (it.title || '(无标题)') : '') + ' → ' + o.color));
      });
      grpC.appendChild(ulC);
      body.appendChild(grpC);
    }
    var pins = ops.filter(function (o) { return o.op === 'pin'; });
    if (pins.length) {
      var grpP = mkEl('div', 'classify-group');
      grpP.appendChild(mkEl('div', 'classify-path', '<i class="ic ic-pin"></i> 置顶'));
      var ulP = mkEl('ul', 'classify-notes');
      pins.forEach(function (o) {
        var it = byId[o.id];
        ulP.appendChild(mkEl('li', null, '• #' + o.id + (it ? ' ' + (it.title || '(无标题)') : '') + (o.pinned ? ' → 置顶' : ' → 取消置顶')));
      });
      grpP.appendChild(ulP);
      body.appendChild(grpP);
    }

    var foot = mkEl('div', 'md-modal-foot');
    var btnGroup = mkEl('div', 'classify-btns');
    var btnApply = mkBtn('✅ 确认执行（共 ' + ops.length + ' 项操作）', '执行整理');
    btnApply.className = 'btn btn-primary btn-sm';
    btnApply.addEventListener('click', function () { applyClassify(ops); closeClassifyPreview(); });

    var btnCancel = mkBtn('<i class="ic ic-close"></i> 取消', '取消');
    btnCancel.className = 'btn btn-outline btn-sm';
    btnCancel.addEventListener('click', closeClassifyPreview);

    btnGroup.appendChild(btnApply);
    btnGroup.appendChild(btnCancel);
    foot.appendChild(btnGroup);
    body.appendChild(foot);

    modal.appendChild(head);
    modal.appendChild(body);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    classifyPreviewOverlay = overlay;
  }

  async function applyClassify(ops) {
    showToast('📦 正在执行整理...', 'success');
    try {
      var resp = await fetch('api/ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: jbody({ action: 'classify_apply', ops: ops }),
        credentials: 'include',
        cache: 'no-store'
      });
      var result = await resp.json();
      if (!result.success) {
        showToast('❌ ' + (result.message || '执行失败'), 'error');
        return;
      }
      var parts = [];
      if (result.moved) parts.push(result.moved + ' 条便签移动');
      if (result.created) parts.push(result.created + ' 个文件夹新建');
      if (result.renamed) parts.push(result.renamed + ' 个文件夹改名');
      if (result.deleted_folders) parts.push(result.deleted_folders + ' 个文件夹删除');
      if (result.deleted_notes) parts.push(result.deleted_notes + ' 条便签删除');
      if (result.sorted) parts.push(result.sorted + ' 处排序');
      if (result.colored) parts.push(result.colored + ' 条改色');
      if (result.pinned) parts.push(result.pinned + ' 条置顶变更');
      if (result.skipped && result.skipped.length) parts.push(result.skipped.length + ' 项跳过');
      if (result.orphans_fixed) parts.push(result.orphans_fixed + ' 条孤儿便签归位主页');
      showToast('✅ 整理完成：' + (parts.join('，') || '无实际变更') + '（可撤销）', 'success');
      aiClassifyPending = null;
      await loadFolders(); refreshView();
      document.getElementById('btnUndoAiOff').disabled = false;
    } catch (e) {
      showToast('❌ 执行失败', 'error');
    }
  }

  async function undoLastClassify() {
    try {
      var resp = await fetch('api/ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: jbody({ action: 'classify_undo' }),
        credentials: 'include',
        cache: 'no-store'
      });
      var result = await resp.json();
      if (!result.success) {
        showToast('❌ ' + (result.message || '无可撤销操作'), 'error');
        return;
      }
      var chatLog = document.getElementById('aiChatLog');
      if (chatLog) renderChatBubble(chatLog, 'assistant', '↩️ 已撤销上次整理，' + result.restored + ' 项操作已还原。');
      showToast('↩️ 已撤销，' + result.restored + ' 项操作已还原', 'success');
      await loadFolders(); refreshView();
      if (!result.still_more) document.getElementById('btnUndoAiOff').disabled = true;
    } catch (e) {
      showToast('❌ 撤销失败', 'error');
    }
  }

  async function checkPendingClassify() {
    try {
      var resp = await fetch('api/ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: jbody({ action: 'classify_status' }),
        credentials: 'include',
        cache: 'no-store'
      });
      var result = await resp.json();
      if (result.success && result.has_pending) {
        document.getElementById('btnUndoAiOff').disabled = false;
      }
    } catch (e) { /* 不阻塞 */ }
  }

  async function saveTitle(card) {
    var id = parseInt(card.getAttribute('data-id'));
    var el = card.querySelector('[data-field="title"]');
    if (!el || !notesById[id]) return;
    var value = (el.textContent || '').trim();
    if (notesById[id].title === value) return;
    notesById[id].title = value;
    notesById[id].updated_at = nowStr();
    try {
      var r = await api('PUT', { id: id, title: value });
      if (r.success) {
        showToast('💾 标题已保存', 'success');
        // 原位替换卡片，让时间戳等元信息同步更新
        var fresh = createNoteCard(notesById[id]);
        if (card.parentNode) {
          card.parentNode.replaceChild(fresh, card);
          checkClamp(fresh);
        }
      }
    } catch (e) {
      if (String(e.message).indexOf('未登录') === -1) showToast('❌ 保存失败', 'error');
    }
  }

  // ============== 分享弹窗 ==============
  // kind='note'（默认，card 为便签卡 DOM，数据在 card._noteData）
  // kind='folder'（card 参数改传 folder 数据对象，字段 share_url/share_until/share_token）
  function openShareDialog(id, card, kind) {
    closeShareDialog();
    var isFolder = kind === 'folder';
    var apiFn = isFolder ? folderApi : api;
    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal share-modal');

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', isFolder ? '<i class="ic ic-folder"></i> 分享文件夹' : '<i class="ic ic-link"></i> 分享便签'));
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeShareDialog);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');

    // 有效期选择
    var sel = mkEl('div', 'share-options');
    [['24', '24 小时'], ['168', '7 天'], ['720', '30 天'], ['0', '永久']].forEach(function (opt) {
      var b = mkBtn(opt[1]);
      b.className = 'btn btn-outline btn-xs share-opt' + (opt[0] === '0' ? ' active' : '');
      b.setAttribute('data-hours', opt[0]);
      b.addEventListener('click', function () {
        sel.querySelectorAll('.share-opt').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
      });
      sel.appendChild(b);
    });

    // 已分享状态区
    var statusArea = mkEl('div', 'share-status-area');
    statusArea.style.display = 'none';

    var statusBadge = mkEl('div', 'share-status-badge');
    var statusLink = mkEl('div', 'share-link-box');
    var statusInfo = mkEl('div', 'share-status-info');
    var copyBtn = mkBtn('<i class="ic ic-copy"></i> 复制链接');
    copyBtn.className = 'btn btn-primary btn-xs';

    statusArea.appendChild(statusBadge);
    statusArea.appendChild(statusLink);
    statusArea.appendChild(statusInfo);
    statusArea.appendChild(copyBtn);

    // 未分享提示
    var noShareHint = mkEl('div', 'share-no-share');
    noShareHint.textContent = isFolder ? '此文件夹尚未分享，选择有效期后点击下方按钮（分享内容 = 文件夹内全部便签，含子文件夹）' : '此便签尚未分享，选择有效期后点击下方按钮';

    var foot = mkEl('div', 'md-modal-foot');
    var genBtn = mkBtn('⚡ 生成链接');
    genBtn.className = 'btn btn-primary btn-xs';
    var cancelShareBtn = mkBtn('<i class="ic ic-ban"></i> 取消分享');
    cancelShareBtn.className = 'btn btn-danger btn-xs';
    cancelShareBtn.style.display = 'none';

    // 格式化剩余时间
    function fmtRemain(until) {
      if (until <= 0) return '永久有效';
      var diff = until - Math.floor(Date.now() / 1000);
      if (diff <= 0) return '已过期';
      if (diff < 3600) return Math.floor(diff / 60) + ' 分钟后过期';
      if (diff < 86400) return Math.floor(diff / 3600) + ' 小时后过期';
      return Math.floor(diff / 86400) + ' 天后过期';
    }

    // 显示已分享状态
    function showShared(url, until) {
      statusArea.style.display = '';
      noShareHint.style.display = 'none';
      genBtn.style.display = 'none';
      sel.style.display = 'none';
      cancelShareBtn.style.display = '';
      statusBadge.textContent = '✅ 已分享';
      statusBadge.className = 'share-status-badge shared';
      // 同步更新卡片上的分享按钮（仅便签模式；文件夹模式的 card 是纯数据对象）
      if (card && !isFolder) {
        var sb = card.querySelector('button[title="管理公开分享"], button[title="生成公开分享链接"]');
        if (sb) { sb.textContent = '🌐'; sb.title = '管理公开分享'; sb.classList.add('btn-shared'); }
      }
      statusLink.textContent = url;
      statusInfo.textContent = fmtRemain(until);
      copyBtn.onclick = function () {
        navigator.clipboard.writeText(url).then(function () {
          showToast('📋 链接已复制', 'success');
        }).catch(function () {
          showToast('🔗 请手动选中复制', 'success');
        });
      };
    }

    // 显示未分享状态
    function showNotShared() {
      statusArea.style.display = 'none';
      noShareHint.style.display = '';
      genBtn.style.display = '';
      sel.style.display = 'flex';
      cancelShareBtn.style.display = 'none';
      // 同步更新卡片上的分享按钮（仅便签模式）
      if (card && !isFolder) {
        var sb = card.querySelector('button[title="管理公开分享"], button[title="生成公开分享链接"]');
        if (sb) { sb.innerHTML = '<i class="ic ic-link"></i>'; sb.title = '生成公开分享链接'; sb.classList.remove('btn-shared'); }
      }
    }

    genBtn.addEventListener('click', async function () {
      var hours = parseInt(sel.querySelector('.share-opt.active').getAttribute('data-hours'), 10) || 0;
      genBtn.disabled = true;
      genBtn.textContent = '生成中...';
      try {
        var r = await apiFn('PUT', { action: 'share', id: id, hours: hours });
        if (r.success && r.url) {
          showShared(r.url, r.until || 0);
          showToast('✅ 分享已创建，链接已复制', 'success');
          try { await navigator.clipboard.writeText(r.url); } catch (_) {}
          // 同步更新数据，重新打开弹窗时能正确显示已分享状态
          if (isFolder) {
            if (foldersById[id]) { foldersById[id].share_url = r.url; foldersById[id].share_until = r.until || 0; foldersById[id].share_token = r.token || ''; }
            if (card) { card.share_url = r.url; card.share_until = r.until || 0; card.share_token = r.token || ''; }
            syncFolderBadge(id);
          } else {
            if (card && card._noteData) { card._noteData.share_url = r.url; card._noteData.share_until = r.until || 0; if (r.token) card._noteData.share_token = r.token; }
            if (notesById[id]) { notesById[id].share_url = r.url; notesById[id].share_until = r.until || 0; if (r.token) notesById[id].share_token = r.token; }
          }
        } else {
          showToast('❌ ' + (r.message || '分享失败'), 'error');
        }
      } catch (e) {
        showToast('❌ 网络错误', 'error');
      }
      genBtn.disabled = false;
      genBtn.textContent = '⚡ 生成链接';
    });

    cancelShareBtn.addEventListener('click', async function () {
      try {
        var r = await apiFn('PUT', { action: 'share', id: id, hours: -1 });
        if (r.success) {
          showToast('🚫 已取消分享，链接即刻失效', 'success');
          showNotShared();
          if (isFolder) {
            if (foldersById[id]) { foldersById[id].share_url = ''; foldersById[id].share_until = 0; foldersById[id].share_token = ''; }
            if (card) { card.share_url = ''; card.share_until = 0; card.share_token = ''; }
            syncFolderBadge(id);
          } else {
            if (card && card._noteData) { card._noteData.share_url = ''; card._noteData.share_until = 0; card._noteData.share_token = ''; }
            if (notesById[id]) { notesById[id].share_url = ''; notesById[id].share_until = 0; notesById[id].share_token = ''; }
          }
        } else {
          showToast('❌ ' + (r.message || '操作失败'), 'error');
        }
      } catch (e) { showToast('❌ 网络错误', 'error'); }
    });

    // 从数据读取当前分享状态（优先 share_url，回退到 share_token 构建；文件夹走 ?f= 参数）
    var note = isFolder ? card : (card ? card._noteData : null);
    var _url = note && (note.share_url || (note.share_token && String(note.share_token).length === 36
      ? location.origin + '/share.php?' + (isFolder ? 'f=' : 't=') + note.share_token : ''));
    if (_url) {
      showShared(_url, note.share_until || 0);
    } else {
      showNotShared();
    }

    foot.appendChild(genBtn);
    foot.appendChild(cancelShareBtn);

    modal.appendChild(head);
    body.appendChild(sel);
    body.appendChild(noShareHint);
    body.appendChild(statusArea);
    modal.appendChild(body);
    modal.appendChild(foot);
    overlay.appendChild(modal);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeShareDialog(); });
    document.body.appendChild(overlay);
  }

  function closeShareDialog() {
    var ov = document.querySelector('.share-modal');
    if (ov) ov.closest('.md-modal-overlay').remove();
  }

  // ============== AI 编辑弹窗 ==============
  var btnAiEdit = document.getElementById('btnAiEdit');

  async function aiApi(body) {
    var resp = await fetch('api/ai.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: jbody(body),
      credentials: 'include',
      cache: 'no-store'
    });
    if (resp.status === 401) {
      await check401();   // 真失效才跳登录（aiApi）
      throw new Error('未登录');
    }
    var raw = await resp.text();
    var result;
    try { result = JSON.parse(raw); }
    catch (e) { throw new Error('服务器响应异常 (HTTP ' + resp.status + ')'); }
    return result;
  }

  function closeAiDialog() {
    var ov = document.querySelector('.ai-modal');
    if (ov) ov.closest('.md-modal-overlay').remove();
  }

  // AI 编辑流式请求（protocol v5）：解析服务端 SSE（delta/phase/done），done 返回最终结果对象
  // 非 SSE 响应（预检失败：未登录/配额/政策等普通 JSON）直接解析返回，行为与 aiApi 一致
  async function aiApiStream(body, handlers) {
    var resp = await fetch('api/ai.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: jbody(body),
      credentials: 'include',
      cache: 'no-store',
      signal: handlers && handlers.signal ? handlers.signal : undefined
    });
    if (resp.status === 401) {
      await check401();   // 真失效才跳登录（aiApiStream）
      throw new Error('未登录');
    }
    var ct = resp.headers.get('Content-Type') || '';
    if (ct.indexOf('text/event-stream') === -1) {
      var rawJ = await resp.text();
      try { return JSON.parse(rawJ); }
      catch (e) { throw new Error('服务器响应异常 (HTTP ' + resp.status + ')'); }
    }
    var blocks = [];
    var sseBuf = '';
    function feed(chunk) {
      sseBuf += chunk.replace(/\r\n/g, '\n'); // CRLF 归一：中间层可能转换行尾，归一后统一按 \n 处理
      var idx;
      while ((idx = sseBuf.indexOf('\n\n')) !== -1) {
        var blk = sseBuf.slice(0, idx);
        sseBuf = sseBuf.slice(idx + 2);
        var ev = '', data = '';
        blk.split('\n').forEach(function (line) {
          if (line.indexOf('event:') === 0) ev = line.slice(6).trim();
          else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
        });
        if (!ev || !data) continue;
        var d = null;
        try { d = JSON.parse(data); } catch (e) { d = null; }
        if (!d) continue;
        blocks.push({ ev: ev, d: d });
        if (handlers) {
          if (ev === 'delta' && typeof handlers.onDelta === 'function') handlers.onDelta(d.t || '');
          if (ev === 'phase' && typeof handlers.onPhase === 'function') handlers.onPhase(d.t || '');
          if (ev === 'tool' && typeof handlers.onTool === 'function') handlers.onTool(d);
          if (ev === 'tool_result' && typeof handlers.onToolResult === 'function') handlers.onToolResult(d);
        }
      }
    }
    if (resp.body && typeof resp.body.getReader === 'function') {
      var reader = resp.body.getReader();
      var dec = new TextDecoder('utf-8');
      while (true) {
        var rd = await reader.read();
        if (rd.done) break;
        feed(dec.decode(rd.value, { stream: true }));
      }
    } else {
      // 老浏览器无 ReadableStream：整段读回再重放事件（无实时预览，结果不变）
      feed(await resp.text());
    }
    var done = null;
    for (var i = blocks.length - 1; i >= 0; i--) {
      if (blocks[i].ev === 'done') { done = blocks[i].d; break; }
    }
    if (!done) throw new Error('连接中断（未收到生成结果）');
    return done;
  }

  // ============== AI 偏好 / 政策 / 设置 ==============
  var AI_POLICY_VERSION = 1;
  var AI_PREFS_KEY = 'pn_ai_prefs';

  function loadAiPrefs() {
    try {
      var p = JSON.parse(localStorage.getItem(AI_PREFS_KEY) || '{}');
      return {
        mode: p.mode === 'own' ? 'own' : 'platform',
        platformKey: p.platformKey || '',
        ownBaseUrl: p.ownBaseUrl || '',
        ownApiKey: p.ownApiKey || '',
        ownModel: p.ownModel || '',
        ownProxy: p.ownProxy || '',
        sendTime: !!p.sendTime,
        style: p.style || '',
        ownDeepThink: !!p.ownDeepThink,
        ownBodyEnabled: !!p.ownBodyEnabled,
        ownBodyKey: p.ownBodyKey || '',
        ownBodyJson: p.ownBodyJson || '',
        sync: !!p.sync,
        policyVersion: parseInt(p.policyVersion) || 0
      };
    } catch (e) {
      return { mode: 'platform', platformKey: '', ownBaseUrl: '', ownApiKey: '', ownModel: '', ownProxy: '', sendTime: false, style: '', ownDeepThink: false, ownBodyEnabled: false, ownBodyKey: '', ownBodyJson: '', sync: false, policyVersion: 0 };
    }
  }

  function saveAiPrefsLocal(p) {
    try { localStorage.setItem(AI_PREFS_KEY, JSON.stringify(p)); } catch (e) {}
  }

  function aiPrefsToRemote(p) {
    return {
      mode: p.mode,
      platform_key: p.platformKey,
      own_base_url: p.ownBaseUrl,
      own_api_key: p.ownApiKey,
      own_model: p.ownModel,
      own_proxy: p.ownProxy,
      send_time: p.sendTime ? 1 : 0,
      style: p.style,
      own_deep_think: p.ownDeepThink ? 1 : 0,
      own_body_enabled: p.ownBodyEnabled ? 1 : 0,
      own_body_key: p.ownBodyKey || '',
      own_body_json: p.ownBodyJson || ''
    };
  }

  function remoteToAiPrefs(row) {
    if (!row) return null;
    return {
      mode: row.mode === 'own' ? 'own' : 'platform',
      platformKey: row.platform_key || '',
      ownBaseUrl: row.own_base_url || '',
      ownApiKey: row.own_api_key || '',
      ownModel: row.own_model || '',
      ownProxy: row.own_proxy || '',
      sendTime: !!parseInt(row.send_time),
      style: row.style || '',
      ownDeepThink: !!parseInt(row.own_deep_think),
      ownBodyEnabled: !!parseInt(row.own_body_enabled),
      ownBodyKey: row.own_body_key || '',
      ownBodyJson: row.own_body_json || '',
      policyVersion: parseInt(row.policy_version) || 0
    };
  }

  async function saveAiPrefsRemote(p, withPolicy) {
    var body = { action: 'prefs', op: 'save' };
    var r = aiPrefsToRemote(p);
    for (var k in r) body[k] = r[k];
    if (withPolicy) body.policy_agreed = 1;
    try {
      await aiApi(body);
      return true;
    } catch (e) { return false; }
  }

  // 拉取服务器状态：分配的密钥 + 同步的偏好（登录后调用一次）
  var aiRemoteState = null;
  var aiUsageCache = null;   // 最近一次用量查询结果（AI 弹窗与 AI 设置共用显示）
  // 用量/模式说明文案（AI 设置弹窗内展示，不占 AI 对话界面）
  function aiUsageText() {
    var p = loadAiPrefs();
    if (aiRemoteState && aiRemoteState.is_admin && p.mode !== 'own') return '👑 管理员模式：直接使用平台上游，无需密钥、不限量';
    if (p.mode === 'own' && p.ownProxy) return '🛰️ 直连模式：请求由浏览器直接发往你的透明代理，不经过平台、不限量';
    if (aiUsageCache) {
      var src = (aiRemoteState && aiRemoteState.assigned_key) ? '分配密钥' : '平台密钥';
      return '📊 ' + src + '今日已用 ' + aiUsageCache.used + ' / ' + (aiUsageCache.limit > 0 ? aiUsageCache.limit : '∞') + '（北京时间 8:00 重置）· 当前模式：' + (p.mode === 'own' ? '我自己的 Key' : '平台密钥');
    }
    if (aiRemoteState && aiRemoteState.assigned_key) {
      var a = aiRemoteState.assigned_key;
      return '📊 分配密钥今日已用 ' + a.used + ' / ' + (a.daily_limit > 0 ? a.daily_limit : '∞') + '（北京时间 8:00 重置）';
    }
    if (p.mode !== 'own' && p.platformKey) return '⏳ 正在查询密钥用量…';
    return '📊 尚无用量记录 · 当前模式：' + (p.mode === 'own' ? '我自己的 Key' : '平台密钥');
  }
  async function refreshAiRemote(force) {
    try {
      var r = await aiApi({ action: 'prefs', op: 'get' });
      if (r.success) {
        aiRemoteState = r;
        // 服务器偏好（曾勾选同步保存过的账号才有）
        var rp = r.prefs ? remoteToAiPrefs(r.prefs) : null;
        var hasRemote = !!(rp && (rp.platformKey || rp.ownBaseUrl || rp.style));
        if (hasRemote) {
          var local = loadAiPrefs();
          // 本机已勾选同步 → 服务器优先（跨端一致）；本机没有任何设置 → 采用服务器（新设备首次）
          if (local.sync || (!local.platformKey && !local.ownBaseUrl && !local.ownApiKey)) {
            local.mode = rp.mode;
            local.platformKey = rp.platformKey;
            local.ownBaseUrl = rp.ownBaseUrl;
            local.ownApiKey = rp.ownApiKey;
            local.ownModel = rp.ownModel;
            local.ownProxy = rp.ownProxy;
            local.sendTime = rp.sendTime;
            local.style = rp.style;
            local.policyVersion = Math.max(local.policyVersion, rp.policyVersion);
            local.sync = true;
            saveAiPrefsLocal(local);
          }
        }
      }
    } catch (e) {}
  }

  // ============== 政策弹窗 ==============
  function closePolicyDialog() {
    var ov = document.querySelector('.policy-modal');
    if (ov) ov.closest('.md-modal-overlay').remove();
  }

  function openPolicyDialog(onAgreed) {
    closePolicyDialog();
    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal policy-modal');

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '📜 AI 功能使用政策'));
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closePolicyDialog);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');

    var box1 = mkEl('div', 'policy-box');
    box1.appendChild(mkEl('div', 'policy-title', '<i class="ic ic-lock"></i> 隐私政策'));
    ['· 你编辑的便签内容和你的指令会被发送到 AI 服务商（平台密钥模式走平台配置的上游；自有 Key 模式经代理直连你填写的服务商）完成处理。',
     '· 你自己填写的 API Key 默认只保存在你当前浏览器的本地存储中，服务器不保存、不记录；仅当你主动勾选「跨端同步」时才会保存到服务器。',
     '· 你的风格偏好、AI 设置同样遵循上述存储规则。',
     '· 我们不会把你的便签内容用于任何其他用途。'
    ].forEach(function (t) { box1.appendChild(mkEl('div', 'policy-line', t)); });

    var box2 = mkEl('div', 'policy-box');
    box2.appendChild(mkEl('div', 'policy-title', '<i class="ic ic-copy"></i> 使用政策'));
    ['· 平台密钥由管理员发放，有每日用量限制（北京时间 8:00 重置），禁止共享、转卖或滥用。',
     '· 自有 Key 不填代理时经平台代理转发，每账号每日限 500 次；填写自己的透明代理后由浏览器直连，不经过平台、不限量。',
     '· 请勿通过 AI 功能处理违法违规内容。',
     '· AI 输出可能有误，采纳前请核对（本功能提供差异对比供你检查）。'
    ].forEach(function (t) { box2.appendChild(mkEl('div', 'policy-line', t)); });

    body.appendChild(box1);
    body.appendChild(box2);

    var foot = mkEl('div', 'md-modal-foot');
    var agreeBtn = mkBtn('✅ 我已阅读并同意');
    agreeBtn.className = 'btn btn-primary btn-xs';
    var declineBtn = mkBtn('暂不使用');
    declineBtn.className = 'btn btn-outline btn-xs';
    foot.appendChild(agreeBtn);
    foot.appendChild(declineBtn);

    agreeBtn.addEventListener('click', async function () {
      var p = loadAiPrefs();
      p.policyVersion = AI_POLICY_VERSION;
      saveAiPrefsLocal(p);
      closePolicyDialog();
      showToast('✅ 已同意 AI 使用政策', 'success');
      if (p.sync) await saveAiPrefsRemote(p, true);
      if (typeof onAgreed === 'function') onAgreed();
    });
    declineBtn.addEventListener('click', closePolicyDialog);

    modal.appendChild(head);
    modal.appendChild(body);
    modal.appendChild(foot);
    overlay.appendChild(modal);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closePolicyDialog(); });
    document.body.appendChild(overlay);
  }

  function needPolicy() {
    var p = loadAiPrefs();
    return p.policyVersion < AI_POLICY_VERSION;
  }

  // 时间感知：读取浏览器当前时间（含星期与时区）
  function formatLocalNow() {
    var d = new Date();
    var wd = ['日', '一', '二', '三', '四', '五', '六'][d.getDay()];
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    return d.getFullYear() + '年' + (d.getMonth() + 1) + '月' + d.getDate() + '日 星期' + wd
      + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
      + '（时区 ' + (d.getTimezoneOffset() === 0 ? 'UTC' : 'UTC' + (d.getTimezoneOffset() > 0 ? '-' : '+') + Math.abs(d.getTimezoneOffset() / 60) + '）');
  }

  // ============== AI 设置弹窗 ==============
  function closeAiSettings() {
    var ov = document.querySelector('.ai-settings-modal');
    if (ov) ov.closest('.md-modal-overlay').remove();
  }

  function openAiSettings(onSaved) {
    closeAiSettings();
    var p = loadAiPrefs();
    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal ai-settings-modal');

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-gear"></i> AI 设置'));
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeAiSettings);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');

    // 用量/模式状态（从 AI 对话界面迁来这里展示）
    var usageRow = mkEl('div', 'ai-set-usage');
    usageRow.textContent = aiUsageText();
    body.appendChild(usageRow);

    // 模式选择
    var modeRow = mkEl('div', 'ai-set-row');
    var modeLbl = mkEl('div', 'ai-set-label', '使用方式');
    var modeBtns = mkEl('div', 'share-options');
    var bPlat = mkBtn('<i class="ic ic-key"></i> 平台密钥');
    bPlat.className = 'btn btn-outline btn-xs share-opt' + (p.mode !== 'own' ? ' active' : '');
    var bOwn = mkBtn('🧬 我自己的 Key');
    bOwn.className = 'btn btn-outline btn-xs share-opt' + (p.mode === 'own' ? ' active' : '');
    modeBtns.appendChild(bPlat);
    modeBtns.appendChild(bOwn);
    bPlat.addEventListener('click', function () { bPlat.classList.add('active'); bOwn.classList.remove('active'); platArea.style.display = ''; ownArea.style.display = 'none'; });
    bOwn.addEventListener('click', function () { bOwn.classList.add('active'); bPlat.classList.remove('active'); platArea.style.display = 'none'; ownArea.style.display = ''; });
    modeRow.appendChild(modeLbl);
    modeRow.appendChild(modeBtns);

    // 平台密钥区
    var platArea = mkEl('div', 'ai-set-area');
    var assignedBox = mkEl('div', 'ai-assigned');
    var keyInput = mkEl('input', 'form-input ai-input');
    keyInput.type = 'text';
    keyInput.placeholder = 'pn-…（管理员发给你的密钥）';
    keyInput.value = p.platformKey;
    keyInput.setAttribute('maxlength', '64');
    platArea.appendChild(assignedBox);
    platArea.appendChild(mkEl('div', 'ai-set-label', '手动填入密钥（如果管理员没有绑定你的账号）'));
    platArea.appendChild(keyInput);

    // 自有 Key 区
    var ownArea = mkEl('div', 'ai-set-area');
    if (p.mode !== 'own') ownArea.style.display = 'none';
    ['ownBaseUrl|接口地址|https://api.example.com/v1（https:// 可省略）|255',
     'ownApiKey|API Key|sk-…|255',
     'ownModel|模型名|例如 gpt-4o-mini / deepseek-chat|100',
     'ownProxy|透明代理（可选，强烈推荐）|https://your-worker.workers.dev 部署后请求直连你的代理|255'
    ].forEach(function (spec) {
      var s = spec.split('|');
      ownArea.appendChild(mkEl('div', 'ai-set-label', s[1]));
      var inp = mkEl('input', 'form-input ai-input');
      inp.type = 'text';
      inp.placeholder = s[2];
      inp.value = p[s[0]] || '';
      inp.setAttribute('maxlength', s[3]);
      inp.setAttribute('data-field', s[0]);
      ownArea.appendChild(inp);
    });
    // 自定义 Body 参数（仅自有 Key 模式）
    var bodyBox = mkEl('div', 'ai-body-box');
    var bodyChkRow = mkEl('div', 'ai-set-row');
    var bodyChkLbl = mkEl('label', 'ai-sync-label');
    var bchk = mkEl('input');
    bchk.type = 'checkbox';
    bchk.checked = p.ownBodyEnabled;
    bchk.className = 'ai-sync-chk';
    bodyChkLbl.appendChild(bchk);
    bodyChkLbl.appendChild(mkEl('span', null, '<i class="ic ic-gear"></i> 自定义请求 Body 参数（随每次 AI 请求额外发送一个自定义字段）'));
    bodyChkRow.appendChild(bodyChkLbl);
    bodyBox.appendChild(bodyChkRow);
    var bKeyInp = mkEl('input', 'form-input ai-input');
    bKeyInp.type = 'text';
    bKeyInp.placeholder = 'Body Key（参数名），如 enable_thinking / reasoning_effort / temperature';
    bKeyInp.value = p.ownBodyKey;
    bKeyInp.setAttribute('maxlength', '64');
    var bJsonInp = mkEl('textarea', 'ai-instruction');
    bJsonInp.placeholder = 'Body JSON（参数值，合法 JSON），如 true / "high" / {"type":"enabled","budget_tokens":1024}';
    bJsonInp.value = p.ownBodyJson;
    bJsonInp.setAttribute('maxlength', '500');
    bJsonInp.style.minHeight = '54px';
    var bodyHint = mkEl('div', 'ai-own-hint');
    bodyHint.appendChild(mkEl('div', null, '💡 例：开启深度思考（Qwen 系）→ Key 填 enable_thinking，JSON 填 true；OpenAI o 系 → Key 填 reasoning_effort，JSON 填 "high"。与深度思考开关同名时以此处为准。仅「我自己的 Key」模式生效，平台密钥模式忽略。'));
    bodyBox.appendChild(bKeyInp);
    bodyBox.appendChild(bJsonInp);
    bodyBox.appendChild(bodyHint);
    ownArea.appendChild(bodyBox);

    var ownHint = mkEl('div', 'ai-own-hint');
    var ownHintTop = mkEl('div', null, '💡 Key 默认只存本浏览器；不填代理时经平台代理转发（每账号每日 500 次）；填写自己的透明代理后由浏览器直连你的代理，完全不经过平台、不限量。');
    ownHint.appendChild(ownHintTop);
    var dlRow = mkEl('div', 'ai-proxy-dl');
    var dlLink = mkEl('a');
    dlLink.href = 'ai-proxy-worker.js';
    dlLink.setAttribute('download', 'ai-proxy-worker.js');
    dlLink.innerHTML = '<i class="ic ic-download"></i> 下载 Workers 透明代理脚本';
    dlRow.appendChild(dlLink);
    var dlTip = mkEl('span', null, '→ 部署到你的 Cloudflare Workers（免费），把 Worker 地址填到上面即可');
    dlRow.appendChild(dlTip);
    ownHint.appendChild(dlRow);
    ownArea.appendChild(ownHint);

    // 风格
    var styleArea = mkEl('div', 'ai-set-area');
    styleArea.appendChild(mkEl('div', 'ai-set-label', '风格偏好（AI 会尽量按这个口吻编辑）'));
    var styleTa = mkEl('textarea', 'ai-instruction ai-style-ta');
    styleTa.placeholder = '例如：轻松幽默、口语化 / 正式书面语、多用列表 / 简洁干练，少废话';
    styleTa.value = p.style;
    styleTa.setAttribute('maxlength', '500');
    styleArea.appendChild(styleTa);

    // 时间感知开关
    var timeRow = mkEl('div', 'ai-set-row');
    var timeLbl = mkEl('div', 'ai-set-label', '时间感知');
    var timeChk = mkEl('label', 'ai-sync-label');
    var tchk = mkEl('input');
    tchk.type = 'checkbox';
    tchk.checked = p.sendTime;
    tchk.className = 'ai-sync-chk';
    timeChk.appendChild(tchk);
    timeChk.appendChild(mkEl('span', null, '🕐 让 AI 知道现在的时间（读取你浏览器的时间，涉及日期/星期/节假日的编辑不会瞎编）。不开启则不发送任何时间信息'));
    timeRow.appendChild(timeLbl);
    timeRow.appendChild(timeChk);

    // 深度思考开关（平台密钥 / 管理员 / 自有 Key 模式均生效）
    var thinkRow = mkEl('div', 'ai-set-row');
    var thinkLbl = mkEl('div', 'ai-set-label', '深度思考');
    var thinkChk = mkEl('label', 'ai-sync-label');
    var dchk = mkEl('input');
    dchk.type = 'checkbox';
    dchk.checked = p.ownDeepThink;
    dchk.className = 'ai-sync-chk';
    thinkChk.appendChild(dchk);
    thinkChk.appendChild(mkEl('span', null, '🧠 发送 enable_thinking: true，AI 思考更细致但响应明显更慢。所有模式均生效（前提是所用的模型支持思考）；参数名不同的厂商可用「自定义 Body」覆盖（仅自有 Key 模式）'));
    thinkRow.appendChild(thinkLbl);
    thinkRow.appendChild(thinkChk);

    // 同步
    var syncRow = mkEl('div', 'ai-set-row');
    var syncLbl = mkEl('div', 'ai-set-label', '跨端同步');
    var syncChk = mkEl('label', 'ai-sync-label');
    var chk = mkEl('input');
    chk.type = 'checkbox';
    chk.checked = p.sync;
    chk.className = 'ai-sync-chk';
    syncChk.appendChild(chk);
    syncChk.appendChild(mkEl('span', null, '<i class="ic ic-cloud"></i> 保存到我的账号（换设备也能用。注意：包括你自己的 API Key 在内的设置会存到服务器）'));
    syncRow.appendChild(syncLbl);
    syncRow.appendChild(syncChk);

    body.appendChild(modeRow);
    body.appendChild(platArea);
    body.appendChild(ownArea);
    body.appendChild(styleArea);
    body.appendChild(timeRow);
    body.appendChild(thinkRow);
    body.appendChild(syncRow);

    // 政策查看入口（随时可再看）
    var policyRow = mkEl('div', 'ai-policy-view');
    var policyLink = mkEl('a');
    policyLink.href = '#';
    policyLink.textContent = '📜 查看 AI 使用政策与隐私政策';
    policyLink.addEventListener('click', function (e) {
      e.preventDefault();
      openPolicyDialog();
    });
    policyRow.appendChild(policyLink);
    body.appendChild(policyRow);

    var foot = mkEl('div', 'md-modal-foot');
    var saveBtn = mkBtn('<i class="ic ic-save"></i> 保存设置');
    saveBtn.className = 'btn btn-primary btn-xs';
    var cancelBtn = mkBtn('关闭');
    cancelBtn.className = 'btn btn-outline btn-xs';
    foot.appendChild(saveBtn);
    foot.appendChild(cancelBtn);

    // 显示管理员分配的密钥状态
    function renderAssigned() {
      if (aiRemoteState && aiRemoteState.is_admin) {
        assignedBox.style.display = '';
        assignedBox.textContent = '👑 你是管理员：平台密钥模式下直接使用管理页配置的上游，无需填任何密钥、不限量。';
        return;
      }
      if (aiRemoteState && aiRemoteState.assigned_key) {
        var a = aiRemoteState.assigned_key;
        assignedBox.style.display = '';
        assignedBox.textContent = '✅ 管理员已为你分配密钥' + (a.remark ? '（' + a.remark + '）' : '') + '，无需手动输入 · 今日已用 ' + a.used + '/' + (a.daily_limit > 0 ? a.daily_limit : '∞');
      } else {
        assignedBox.style.display = '';
        assignedBox.textContent = '管理员还没有为你分配密钥';
      }
    }
    renderAssigned();

    saveBtn.addEventListener('click', async function () {
      var np = loadAiPrefs();
      np.mode = bOwn.classList.contains('active') ? 'own' : 'platform';
      np.platformKey = keyInput.value.trim();
      np.ownBaseUrl = '';
      np.ownApiKey = '';
      np.ownModel = '';
      np.ownProxy = '';
      ownArea.querySelectorAll('input[data-field]').forEach(function (inp) {
        np[inp.getAttribute('data-field')] = inp.value.trim();
      });
      np.style = styleTa.value.trim();
      np.sendTime = tchk.checked;
      np.ownDeepThink = dchk.checked;
      np.ownBodyEnabled = bchk.checked;
      np.ownBodyKey = bKeyInp.value.trim();
      np.ownBodyJson = bJsonInp.value.trim();
      if (np.ownBodyEnabled) {
        if (!/^[A-Za-z_][A-Za-z0-9_.\-]{0,63}$/.test(np.ownBodyKey)) {
          showToast('⚠️ Body Key 格式无效（字母开头，可含数字/下划线/点/横线，最长 64 字符）', 'error');
          return;
        }
        var bTest = null;
        try { bTest = JSON.parse(np.ownBodyJson); } catch (e) { bTest = undefined; }
        if (bTest === undefined) {
          showToast('⚠️ Body JSON 不是合法 JSON（如 true / "high" / {"type":"enabled"}）', 'error');
          return;
        }
      }
      np.sync = chk.checked;
      if (np.mode === 'own' && (!np.ownBaseUrl || !np.ownApiKey || !np.ownModel)) {
        showToast('⚠️ 自有 Key 模式需要填写接口地址、API Key 和模型名', 'error');
        return;
      }
      saveAiPrefsLocal(np);
      if (np.sync) {
        saveBtn.disabled = true;
        saveBtn.textContent = '同步中...';
        var ok = await saveAiPrefsRemote(np, false);
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="ic ic-save"></i> 保存设置';
        showToast(ok ? '✅ 设置已保存并同步到账号' : '✅ 已保存到本地（同步服务器失败）', ok ? 'success' : 'error');
      } else {
        // 取消同步：删除服务器上已存的偏好，换设备不会再被旧设置覆盖
        aiApi({ action: 'prefs', op: 'clear' }).catch(function () {});
        showToast('✅ 设置已保存到本浏览器（已清除服务器上的同步数据）', 'success');
      }
      if (typeof onSaved === 'function') onSaved(np);
      closeAiSettings();
    });
    cancelBtn.addEventListener('click', closeAiSettings);

    modal.appendChild(head);
    modal.appendChild(body);
    modal.appendChild(foot);
    overlay.appendChild(modal);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeAiSettings(); });
    document.body.appendChild(overlay);
  }

  // ============================================================
  // AI 全屏审阅页（v13）
  // 顶部 48px（取消 / 标题 / 全部接受）+ 中部 hunk 逐处复选 + 底部 56px（拒绝全部 / 接受选中 k-n）
  // 支撑：自写行级 LCS diff、按勾选重建全文、版本快照撤回、生成中工具行、错误归一
  // ============================================================
  var AI_SNAPSHOT_MAX = 20;
  var aiReviewEl = null;          // 审阅页根节点（null = 未打开）
  var aiReviewState = 'idle';     // 状态机 idle|thinking|streaming|tool_call|diff_ready|awaiting_confirm|applying|done|rejected|error|cancelled
  var aiReviewPlan = null;        // {segs, hunks, tooLarge}
  var aiReviewSelected = [];      // 每个 hunk 是否勾选
  var aiReviewResult = null;      // 本次 AI 结果对象
  var aiReviewOpts = null;        // {onClose, onRegenerate}
  var aiReviewPrev = '';          // 本次 AI 的原文（编辑器内容）
  var aiSnapshots = [];           // 版本快照 [{before, after, at}]，上限 20
  var aiAbortCtrl = null;         // 当前生成请求的 AbortController
  var aiHistory = [];             // 多轮对话历史：本次打开 AI 对话框内累积的 {role, content}
  var aiToolTrace = [];           // 本轮工具调用轨迹（标签，用于生成给模型的进度摘要）
  var aiToolDetails = [];         // 本轮工具明细 [{label, brief, ok, detail}]（用于对话气泡里的工具卡片）

  function aiAbortRun() {
    if (aiAbortCtrl) { try { aiAbortCtrl.abort(); } catch (e) {} }
    aiAbortCtrl = null;
  }

  function aiSetState(s) {
    aiReviewState = s;
    if (aiReviewEl) aiReviewEl.setAttribute('data-state', s);
  }

  // 关闭审阅页并回调（onClose 里会恢复 AI 对话框输入态）
  function aiCloseReviewAnd(state, overrideCb) {
    var cb = (typeof overrideCb === 'function') ? overrideCb : (aiReviewOpts && aiReviewOpts.onClose);
    aiSetState(state);
    closeAiReview();
    if (typeof cb === 'function') cb();
  }

  // ---- 行级 LCS（自写约 45 行；monaco 95MB / @pierre-diffs 7MB / jsdiff 601KB 都不划算）----
  function aiLineOps(A, B) {
    var n = A.length, m = B.length, ops = [], i, j;
    if (n === 0) { for (i = 0; i < m; i++) ops.push(['add', B[i]]); return ops; }
    if (m === 0) { for (i = 0; i < n; i++) ops.push(['del', A[i]]); return ops; }
    if (n * m > 2000000) return null;   // 超大文档放弃逐行比对（约 1400×1400 行），走「整篇一处」兜底
    var w = m + 1;
    var dp = new Int32Array((n + 1) * w);
    for (i = n - 1; i >= 0; i--) {
      var row = i * w, nxt = (i + 1) * w;
      for (j = m - 1; j >= 0; j--) {
        dp[row + j] = (A[i] === B[j]) ? dp[nxt + j + 1] + 1
          : (dp[nxt + j] >= dp[row + j + 1] ? dp[nxt + j] : dp[row + j + 1]);
      }
    }
    var a = 0, b = 0;
    while (a < n && b < m) {
      if (A[a] === B[b]) { ops.push(['same', A[a]]); a++; b++; }
      else if (dp[(a + 1) * w + b] >= dp[a * w + b + 1]) { ops.push(['del', A[a]]); a++; }
      else { ops.push(['add', B[b]]); b++; }
    }
    while (a < n) { ops.push(['del', A[a]]); a++; }
    while (b < m) { ops.push(['add', B[b]]); b++; }
    return ops;
  }

  // 归并成「未改动段 / 改动段」；每个改动段 = 一个 hunk（展示时前后各带 3 行上下文）
  function aiBuildPlan(oldText, newText) {
    var A = String(oldText == null ? '' : oldText).split('\n');
    var B = String(newText == null ? '' : newText).split('\n');
    var ops = aiLineOps(A, B), i;
    var tooLarge = false;
    if (!ops) {
      tooLarge = true; ops = [];
      for (i = 0; i < A.length; i++) ops.push(['del', A[i]]);
      for (i = 0; i < B.length; i++) ops.push(['add', B[i]]);
    }
    var segs = [], p = 0;
    while (p < ops.length) {
      if (ops[p][0] === 'same') {
        var ls = [];
        while (p < ops.length && ops[p][0] === 'same') { ls.push(ops[p][1]); p++; }
        segs.push({ type: 'same', lines: ls });
      } else {
        var od = [], nw = [];
        while (p < ops.length && ops[p][0] !== 'same') {
          if (ops[p][0] === 'del') od.push(ops[p][1]); else nw.push(ops[p][1]);
          p++;
        }
        segs.push({ type: 'change', old: od, new: nw });
      }
    }
    var CTX = 3, hunks = [], oldNo = 1, newNo = 1;
    for (var s = 0; s < segs.length; s++) {
      var seg = segs[s];
      seg.oldStart = oldNo; seg.newStart = newNo;
      if (seg.type === 'same') { oldNo += seg.lines.length; newNo += seg.lines.length; continue; }
      seg.hunkIndex = hunks.length;
      var rows = [];
      var prev = s > 0 ? segs[s - 1] : null;
      if (prev && prev.type === 'same') {
        var lead = prev.lines.slice(Math.max(0, prev.lines.length - CTX));
        var lOld = seg.oldStart - lead.length, lNew = seg.newStart - lead.length;
        for (var li = 0; li < lead.length; li++) rows.push({ t: 'ctx', text: lead[li], oldNo: lOld + li, newNo: lNew + li });
      }
      for (var di = 0; di < seg.old.length; di++) rows.push({ t: 'del', text: seg.old[di], oldNo: seg.oldStart + di, newNo: null });
      for (var ai2 = 0; ai2 < seg.new.length; ai2++) rows.push({ t: 'add', text: seg.new[ai2], oldNo: null, newNo: seg.newStart + ai2 });
      var nx = s + 1 < segs.length ? segs[s + 1] : null;
      if (nx && nx.type === 'same') {
        var trail = nx.lines.slice(0, CTX);
        var tOld = seg.oldStart + seg.old.length, tNew = seg.newStart + seg.new.length;
        for (var ti = 0; ti < trail.length; ti++) rows.push({ t: 'ctx', text: trail[ti], oldNo: tOld + ti, newNo: tNew + ti });
      }
      hunks.push({
        index: seg.hunkIndex,
        oldStart: seg.oldStart, newStart: seg.newStart,
        oldLines: seg.old.length, newLines: seg.new.length,
        adds: seg.new.length, dels: seg.old.length,
        rows: rows
      });
      oldNo += seg.old.length; newNo += seg.new.length;
    }
    return { segs: segs, hunks: hunks, tooLarge: tooLarge };
  }

  // 按勾选情况重建全文（勾中=用新内容，未勾=保留原内容）
  function aiApplyPlan(plan, selected) {
    var out = [];
    for (var s = 0; s < plan.segs.length; s++) {
      var seg = plan.segs[s];
      if (seg.type === 'same') {
        for (var i = 0; i < seg.lines.length; i++) out.push(seg.lines[i]);
      } else {
        var use = selected[seg.hunkIndex] ? seg.new : seg.old;
        for (var k = 0; k < use.length; k++) out.push(use[k]);
      }
    }
    return out.join('\n');
  }

  function aiPendingText() {
    if (!aiReviewPlan) return newContent.value;
    return aiApplyPlan(aiReviewPlan, aiReviewSelected);
  }

  // ---- 错误归一成 kind（借鉴 Chatbox）：低调灰底卡片，不再满屏红框 ----
  var AI_ERR_TEXT = {
    aborted: '已停止生成', auth: '登录状态失效，请重新登录',
    quota: 'AI 额度不足', 'context-limit': '内容超出模型上下文长度',
    'tool-failed': 'AI 工具调用失败', network: '网络连接失败',
    http: '上游接口返回错误', unknown: 'AI 编辑失败'
  };
  function aiErrKind(msg, status) {
    var m = String(msg || '');
    if (status === 401 || /未登录|登录已过期|会话失效/.test(m)) return 'auth';
    if (/abort|已停止|主动中断/.test(m)) return 'aborted';
    if (/配额|额度|次数|用量|quota|rate limit|too many/i.test(m)) return 'quota';
    if (/上下文|token|过长|too long|context length|maximum context/i.test(m)) return 'context-limit';
    if (/工具|tool_?call/i.test(m)) return 'tool-failed';
    if (/网络|fetch|NetworkError|Failed to|连不上|连接失败|超时|timeout|连接中断/i.test(m)) return 'network';
    if (/HTTP\s*\d|上游|服务器/.test(m)) return 'http';
    return 'unknown';
  }
  function aiErrCard(msg, status, onRetry) {
    var kind = aiErrKind(msg, status);
    var text = AI_ERR_TEXT[kind];
    var card = mkEl('div', 'ai-err-card');
    card.setAttribute('data-kind', kind);
    card.appendChild(mkEl('span', 'ai-err-ic', kind === 'aborted' ? '⏹️' : '⚠️'));
    var body = mkEl('div', 'ai-err-body');
    body.appendChild(mkEl('div', 'ai-err-kind', text));
    if (msg && String(msg).indexOf(text) === -1) body.appendChild(mkEl('div', 'ai-err-msg', String(msg)));
    card.appendChild(body);
    if (typeof onRetry === 'function') {
      var rb = mkBtn('重试');
      rb.className = 'btn btn-outline btn-xs ai-err-retry';
      rb.addEventListener('click', function () {
        if (card.parentNode) card.parentNode.removeChild(card);
        onRetry();
      });
      card.appendChild(rb);
    }
    return card;
  }

  var AI_TOOL_LABEL = {
    append_text: '追加内容', replace_text: '局部替换', set_full_text: '整篇写入', write_note: '整篇写入',
    read_note: '读取便签', list_folder: '查看文件夹', list_folders: '查看文件夹', finish: '完成', ask_user: '提问'
  };

  // ---- 写入编辑器（唯一出口）+ 版本快照 ----
  function aiWriteEditor(text) {
    if (typeof text !== 'string') return;
    aiSnapshots.push({ before: newContent.value, after: text, at: Date.now() });
    if (aiSnapshots.length > AI_SNAPSHOT_MAX) aiSnapshots.shift();
    newContent.value = text;
    renderLivePreview(text);
  }

  function aiUndoLast() {
    if (!aiSnapshots.length) { showToast('没有可撤回的 AI 改动', 'error'); return false; }
    var snap = aiSnapshots.pop();
    newContent.value = snap.before;
    renderLivePreview(snap.before);
    return true;
  }

  // 改动摘要：把勾中的 hunk 概括成「旧→新」短句（给模型做上下文，也给用户看）
  function aiChangeSummary() {
    var plan = aiReviewPlan, sel = aiReviewSelected;
    if (!plan || !plan.hunks) return '无改动';
    function cut(t) { t = String(t == null ? '' : t).trim(); return t.length > 18 ? safeSlice(t, 18) + '…' : t; }
    var parts = [], selected = 0;
    for (var i = 0; i < plan.hunks.length; i++) {
      if (!sel[i]) continue;
      selected++;
      if (parts.length >= 3) continue;
      var h = plan.hunks[i], del = '', add = '';
      for (var r = 0; r < h.rows.length; r++) {
        if (!del && h.rows[r].t === 'del') del = h.rows[r].text;
        if (!add && h.rows[r].t === 'add') add = h.rows[r].text;
      }
      if (del && add) parts.push('“' + cut(del) + '”→“' + cut(add) + '”');
      else if (del) parts.push('删除“' + cut(del) + '”');
      else if (add) parts.push('新增“' + cut(add) + '”');
    }
    if (!parts.length) return '空行调整';
    return parts.join('；') + (selected > parts.length ? '；等 ' + selected + ' 处' : '');
  }

  // ---- 对话式消息渲染（v13.5）----
  function aiMsgTime() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return p(d.getHours()) + ':' + p(d.getMinutes());
  }

  // 可折叠工具卡片（对应参考产品的工具调用块）
  function aiToolCard(label, brief, ok, detail) {
    var card = mkEl('div', 'ai-tool-card');
    card.setAttribute('data-status', ok === false ? 'error' : 'success');
    var head = mkEl('div', 'ai-tool-head');
    head.appendChild(mkEl('span', 'ai-tool-ic', ok === false ? '⚠️' : '🔧'));
    head.appendChild(mkEl('span', 'ai-tool-name', label || '工具'));
    head.appendChild(mkEl('span', 'ai-tool-brief', brief || ''));
    if (detail) head.appendChild(mkEl('span', 'ai-tool-arrow', '\u203a'));
    card.appendChild(head);
    if (detail) {
      var body = mkEl('div', 'ai-tool-body', detail);
      body.style.display = 'none';
      card.appendChild(body);
      head.addEventListener('click', function () {
        var open = body.style.display !== 'none';
        body.style.display = open ? 'none' : '';
        card.classList.toggle('open', !open);
      });
    }
    return card;
  }

  // 追加一轮对话：用户气泡 + AI 气泡（含工具卡片/说明/动作行）
  function aiConvAppend(userText, aiText, tools) {
    var box = document.querySelector('.ai-conv');
    if (!box) return;
    box.style.display = '';

    var u = mkEl('div', 'ai-msg ai-msg-user');
    var uh = mkEl('div', 'ai-msg-head');
    uh.appendChild(mkEl('span', 'ai-msg-time', aiMsgTime()));
    uh.appendChild(mkEl('span', 'ai-msg-name', '你'));
    u.appendChild(uh);
    u.appendChild(mkEl('div', 'ai-msg-bubble', userText));
    box.appendChild(u);

    var a = mkEl('div', 'ai-msg ai-msg-ai');
    var ah = mkEl('div', 'ai-msg-head');
    ah.appendChild(mkEl('span', 'ai-avatar', 'AI'));
    ah.appendChild(mkEl('span', 'ai-msg-name', '便签 AI'));
    ah.appendChild(mkEl('span', 'ai-msg-time', aiMsgTime()));
    a.appendChild(ah);
    (tools || []).forEach(function (t) {
      a.appendChild(aiToolCard(t.label, t.brief, t.ok, t.detail));
    });
    if (aiText) a.appendChild(mkEl('div', 'ai-msg-text', aiText));

    var acts = mkEl('div', 'ai-msg-actions');
    var cp = mkBtn('<i class="ic ic-copy"></i>', '复制这段说明');
    cp.className = 'ai-act';
    cp.addEventListener('click', function () {
      try {
        navigator.clipboard.writeText(aiText || '');
        showToast('📋 已复制', 'success');
      } catch (e) { showToast('复制失败', 'error'); }
    });
    var rf = mkBtn('<i class="ic ic-recycle"></i>', '用同一句指令重试');
    rf.className = 'ai-act';
    rf.addEventListener('click', function () {
      var inp = document.querySelector('.ai-modal .ai-instruction');
      if (inp) { inp.value = userText; inp.focus(); }
      var run = document.querySelector('.ai-modal .md-modal-foot .btn-primary');
      if (run) run.click();
    });
    acts.appendChild(cp);
    acts.appendChild(rf);
    a.appendChild(acts);

    box.appendChild(a);
    box.scrollTop = box.scrollHeight;
  }

  function aiConvNote(text) {
    var box = document.querySelector('.ai-conv');
    if (!box) return;
    box.style.display = '';
    box.appendChild(mkEl('div', 'ai-msg-note', text));
    box.scrollTop = box.scrollHeight;
  }

  function closeAiReview() {
    if (aiReviewEl && aiReviewEl.parentNode) aiReviewEl.parentNode.removeChild(aiReviewEl);
    aiReviewEl = null; aiReviewPlan = null; aiReviewSelected = [];
    aiReviewResult = null; aiReviewOpts = null;
  }

  function aiShowReviewTab(name) {
    if (!aiReviewEl) return;
    var panes = aiReviewEl.querySelectorAll('.rv-pane');
    var order = { diff: 0, render: 1, src: 2 };
    for (var i = 0; i < panes.length; i++) {
      panes[i].style.display = (i === order[name]) ? '' : 'none';
    }
    var tabs = aiReviewEl.querySelectorAll('.rv-tab');
    for (var t = 0; t < tabs.length; t++) {
      tabs[t].classList.toggle('active', tabs[t].getAttribute('data-tab') === name);
    }
  }

  function aiRenderHunks() {
    if (!aiReviewEl) return;
    var pane = aiReviewEl.querySelector('.rv-pane-diff');
    if (!pane) return;
    pane.innerHTML = '';
    var plan = aiReviewPlan;
    if (!plan || !plan.hunks.length) {
      pane.appendChild(mkEl('div', 'rv-empty', 'AI 没有产生任何改动，可以直接关闭。'));
      return;
    }
    if (plan.tooLarge) {
      pane.appendChild(mkEl('div', 'rv-empty', '内容过长，无法逐行比对，已合并为「整篇改动」一处。'));
    }
    plan.hunks.forEach(function (h) {
      var card = mkEl('div', 'rv-hunk');
      card.setAttribute('data-hunk', String(h.index));
      var head = mkEl('label', 'rv-hunk-head');
      var chk = document.createElement('input');
      chk.type = 'checkbox';
      chk.className = 'rv-chk';
      chk.checked = !!aiReviewSelected[h.index];
      chk.addEventListener('change', function () {
        aiReviewSelected[h.index] = chk.checked;
        card.classList.toggle('rv-off', !chk.checked);
        aiSyncSelection();
      });
      head.appendChild(chk);
      head.appendChild(mkEl('span', 'rv-hunk-title', '第 ' + (h.index + 1) + ' 处'));
      head.appendChild(mkEl('span', 'rv-hunk-loc', h.newLines ? ('第 ' + h.oldStart + ' 行起，替换为 ' + h.newLines + ' 行') : ('第 ' + h.oldStart + ' 行起，删除 ' + h.dels + ' 行')));
      var stat = mkEl('span', 'rv-hunk-stat');
      if (h.dels) stat.appendChild(mkEl('b', 'del', '-' + h.dels));
      if (h.adds) {
        if (h.dels) stat.appendChild(document.createTextNode(' '));
        stat.appendChild(mkEl('b', 'add', '+' + h.adds));
      }
      head.appendChild(stat);
      card.appendChild(head);
      var lines = mkEl('div', 'rv-lines');
      h.rows.forEach(function (r) {
        var ln = mkEl('div', 'rv-line ' + r.t);
        var no = r.t === 'add' ? r.newNo : r.oldNo;
        ln.appendChild(mkEl('span', 'rv-no', no == null ? '' : String(no)));
        ln.appendChild(mkEl('span', 'rv-code', (r.t === 'add' ? '+ ' : r.t === 'del' ? '- ' : '  ') + r.text));
        lines.appendChild(ln);
      });
      card.appendChild(lines);
      card.classList.toggle('rv-off', !chk.checked);
      pane.appendChild(card);
    });
  }

  function aiSyncSelection() {
    if (!aiReviewEl) return;
    var k = 0, i;
    for (i = 0; i < aiReviewSelected.length; i++) if (aiReviewSelected[i]) k++;
    var n = aiReviewSelected.length;
    var sel = aiReviewEl.querySelector('.rv-accept-sel');
    if (sel) { sel.textContent = '接受选中 ' + k + '/' + n; sel.disabled = (k === 0); }
    var src = aiReviewEl.querySelector('.rv-pane-src');
    if (src) src.textContent = n ? aiPendingText() : (aiReviewResult && aiReviewResult.content) || '';
    var rd = aiReviewEl.querySelector('.rv-pane-render');
    if (rd) rd.innerHTML = window.PixelMD.render((n ? aiPendingText() : (aiReviewResult && aiReviewResult.content)) || '*(空)*');
    aiUpdateMeta(k, n);
  }

  function aiUpdateMeta(k, n) {
    if (!aiReviewEl) return;
    var r = aiReviewResult || {};
    var parts = [];
    if (n === 0) parts.push('AI 未产生改动');
    else parts.push('共 ' + n + ' 处改动 · 已选 ' + k + ' 处');
    if (r.agent) parts.push('AI 工具改写');
    else if (r.mode === 'edits' && r.applied) parts.push('替换块 ' + r.applied + ' 个');
    if (r.failed) parts.push('未匹配跳过 ' + r.failed + ' 处');
    if (r.attempts && r.attempts > 1) parts.push('自动纠错 ' + (r.attempts - 1) + ' 次');
    if (r.chunked) parts.push('长文分段 ' + (r.chunks || '?') + ' 段');
    if (aiReviewPlan && aiReviewPlan.tooLarge) parts.push('已合并为一处');
    var info = aiReviewEl.querySelector('.rv-meta-info');
    if (info) info.textContent = '🧩 ' + parts.join(' · ');
    var sub = aiReviewEl.querySelector('.rv-sub');
    if (sub) sub.textContent = n ? (k + '/' + n + ' 处待接受') : '无改动';
  }

  function aiDoApply() {
    if (!aiReviewEl || !aiReviewPlan) return;
    var k = 0, i;
    for (i = 0; i < aiReviewSelected.length; i++) if (aiReviewSelected[i]) k++;
    if (!aiReviewPlan.hunks.length) { showToast('AI 没有产生改动', 'error'); return; }
    if (!k) { showToast('⚠️ 请至少勾选一处改动', 'error'); return; }
    var summary = aiChangeSummary();
    var instr = aiReviewResult ? (aiReviewResult.instruction || '') : '';
    aiSetState('applying');
    aiWriteEditor(aiPendingText());
    // 多轮历史：带「具体改了什么」，模型下一轮才知道前文，而不是只知道调过哪个工具
    if (instr) {
      aiHistory.push({ role: 'user', content: instr });
      aiHistory.push({ role: 'assistant', content: '（已写入便签：' + summary + '）' });
      if (aiHistory.length > 12) aiHistory = aiHistory.slice(-12);
    }
    aiConvAppend(instr || '（编辑指令）', '已写入编辑器 · ' + summary, aiToolDetails.slice());
    showToast('✅ 已写入 ' + k + ' 处改动（记得保存便签），可直接继续下一条指令', 'success');
    aiCloseReviewAnd('done');   // 直接回到对话输入，不需要「继续对话」按钮
  }

  function aiMountReview() {
    var root = aiReviewEl;
    if (!root) return;
    root.innerHTML = '';
    var n = aiReviewPlan ? aiReviewPlan.hunks.length : 0;

    // 顶部 48px
    var head = mkEl('div', 'rv-head');
    var cancel = mkBtn('<i class="ic ic-close"></i> 取消');
    cancel.className = 'btn btn-outline btn-xs';
    cancel.addEventListener('click', function () { aiCloseReviewAnd('cancelled'); });
    var tw = mkEl('div', 'rv-title-wrap');
    tw.appendChild(mkEl('div', 'rv-title', '审阅 AI 改动'));
    tw.appendChild(mkEl('div', 'rv-sub'));
    var acceptAll = mkBtn('<i class="ic ic-checkall"></i> 全部接受');
    acceptAll.className = 'btn btn-primary btn-xs';
    acceptAll.addEventListener('click', function () {
      for (var i = 0; i < aiReviewSelected.length; i++) aiReviewSelected[i] = true;
      aiDoApply();
    });
    head.appendChild(cancel); head.appendChild(tw); head.appendChild(acceptAll);

    // 元信息 + 视图切换
    var meta = mkEl('div', 'rv-meta');
    meta.appendChild(mkEl('div', 'rv-meta-info'));
    var tabs = mkEl('div', 'rv-tabs');
    var defs = [['diff', '审阅'], ['render', '预览'], ['src', '源码']];
    defs.forEach(function (d) {
      var b = mkBtn(d[1]);
      b.className = 'rv-tab';
      b.setAttribute('data-tab', d[0]);
      b.addEventListener('click', function () { aiShowReviewTab(d[0]); });
      tabs.appendChild(b);
    });
    meta.appendChild(tabs);

    // 三个视图（diff 为 pane 0，与 aiShowReviewTab 的顺序约定一致）
    var body = mkEl('div', 'rv-body');
    var paneDiff = mkEl('div', 'rv-pane rv-pane-diff');
    var paneRender = mkEl('div', 'note-content ai-render rv-pane rv-pane-render');
    var paneSrc = mkEl('div', 'ai-src rv-pane rv-pane-src');
    paneRender.style.display = 'none';
    paneSrc.style.display = 'none';
    body.appendChild(paneDiff); body.appendChild(paneRender); body.appendChild(paneSrc);

    // 底部 56px
    var foot = mkEl('div', 'rv-foot');
    var rejectAll = mkBtn('拒绝全部');
    rejectAll.className = 'btn btn-outline btn-xs rv-reject-all';
    rejectAll.addEventListener('click', function () {
      aiCloseReviewAnd('rejected');
      showToast('已放弃本次 AI 改动，便签内容未改动', 'success');
    });
    var acceptSel = mkBtn('接受选中');
    acceptSel.className = 'btn btn-primary btn-xs rv-accept-sel';
    acceptSel.addEventListener('click', aiDoApply);
    foot.appendChild(rejectAll); foot.appendChild(acceptSel);

    root.appendChild(head); root.appendChild(meta); root.appendChild(body); root.appendChild(foot);

    aiRenderHunks();
    aiShowReviewTab('diff');
    aiSyncSelection();
  }

  // 打开审阅页：result = {original, content, mode, applied, failed, attempts, chunked, agent, ...}
  function openAiReview(result, opts) {
    closeAiReview();
    aiReviewResult = result || {};
    aiReviewOpts = opts || {};
    aiReviewPrev = typeof result.original === 'string' ? result.original : newContent.value;
    aiReviewPlan = aiBuildPlan(aiReviewPrev, typeof result.content === 'string' ? result.content : '');
    aiReviewSelected = [];
    for (var i = 0; i < aiReviewPlan.hunks.length; i++) aiReviewSelected.push(true);   // 默认全选：取消勾选即拒绝该处
    aiReviewEl = mkEl('div', 'ai-review');
    document.body.appendChild(aiReviewEl);
    aiMountReview();
    aiSetState('diff_ready');
    aiSetState('awaiting_confirm');
  }

  function openAiDialog() {
    closeAiDialog();
    aiHistory = [];      // 每次打开对话框 = 新会话（会话内多轮，关闭即结束）
    aiToolTrace = [];
    // 首次使用必须先同意政策
    if (needPolicy()) {
      openPolicyDialog(function () { openAiDialog(); });
      return;
    }
    // 拉取服务器状态（分配密钥/同步偏好）
    function queryManualUsage() {
      var p = loadAiPrefs();
      if (aiRemoteState && aiRemoteState.is_admin) return;
      if (p.mode === 'own') return;
      if (aiRemoteState && aiRemoteState.assigned_key) return;
      if (!p.platformKey) return;
      aiApi({ action: 'usage', key: p.platformKey }).then(function (r) {
        if (r.success && r.usage) {
          lastUsage = r.usage;
          renderUsage();
        }
      }).catch(function () {});
    }
    if (!aiRemoteState) refreshAiRemote().then(function () { renderUsage(); queryManualUsage(); });
    else queryManualUsage();
    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal ai-modal');

    var head = mkEl('div', 'md-modal-head');
    var headLeft = mkEl('div', 'md-modal-head-left');
    headLeft.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-robot"></i> AI 编辑便签'));
    head.appendChild(headLeft);
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeAiDialog);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body ai-chat-body');
    var chatLog = mkEl('div', 'ai-chat-log');   // 历史记录区（大区域，滚动）

    // ---- 输入态（界面保持简洁：引导语由输入框 placeholder 承担，用量信息在 AI 设置里） ----
    var usageBar = mkEl('div', 'ai-usage');

    // 多轮会话记录（可见上下文）
    var convBox = mkEl('div', 'ai-conv');
    convBox.style.display = 'none';

    function renderUsage(u) {
      if (u) aiUsageCache = u;
      usageBar.textContent = aiUsageText();   // 用量文案统一为公共函数；此元素不再展示（用量移入 AI 设置）
    }

    var ta = mkEl('textarea', 'ai-instruction');
    ta.placeholder = '把需求告诉 AI';
    ta.setAttribute('maxlength', '2000');

    var status = mkEl('div', 'ai-status');
    status.style.display = 'none';

    // ---- 流式生成预览（protocol v5）：实时显示 AI 输出（剥协议标记）+ 工具调用行 + 可中断 ----
    var streamBox = mkEl('div', 'ai-stream');
    streamBox.style.display = 'none';
    var streamHead = mkEl('div', 'ai-stream-head');
    var streamPhase = mkEl('div', 'ai-stream-phase');
    var streamStop = mkBtn('<i class="ic ic-close"></i> 停止');
    streamStop.className = 'btn btn-outline btn-xs ai-stream-stop';
    streamStop.addEventListener('click', function () { aiAbortRun(); });
    streamHead.appendChild(streamPhase);
    streamHead.appendChild(streamStop);
    var streamText = mkEl('div', 'ai-stream-text');
    var toolLog = mkEl('div', 'ai-tool-log');
    toolLog.style.display = 'none';
    streamBox.appendChild(streamHead);
    streamBox.appendChild(streamText);
    streamBox.appendChild(toolLog);
    var streamRaw = '';
    var streamRaf = 0;
    var streamFollow = true;   // 滚动跟随：用户上滑离底超过 48px 就暂停自动跟随
    streamBox.addEventListener('scroll', function () {
      streamFollow = (streamBox.scrollHeight - streamBox.scrollTop - streamBox.clientHeight) < 48;
    });
    function stripAiMarkers(t) {
      return String(t || '').replace(/<<<(?:SEARCH|REPLACE|END|CLARIFY)>>>/gi, '');
    }
    function renderStream() {
      streamRaf = 0;
      var shown = streamRaw;
      if (shown.length > 12000) shown = '…（前面已省略）\n' + shown.slice(-12000);
      streamText.textContent = stripAiMarkers(shown);
      if (streamFollow) streamBox.scrollTop = streamBox.scrollHeight;
    }
    function showStream(phaseText) {
      streamRaw = '';
      streamText.textContent = '';
      clearToolLog();
      aiToolTrace = [];
      aiToolDetails = [];
      setPhaseText(streamPhase, phaseText || '');
      streamBox.style.display = '';
      streamFollow = true;
      streamBox.scrollTop = 0;
    }
    function onStreamPhase(t) {
      if (t) setPhaseText(streamPhase, t);
    }
    function onStreamDelta(t) {
      aiSetState('streaming');
      streamRaw += t;
      if (!streamRaf) streamRaf = requestAnimationFrame(renderStream);
    }
    function clearToolLog() {
      toolLog.innerHTML = '';
      toolLog.style.display = 'none';
    }
    // 工具行 status：running → success|error（data-status 着色）
    function onStreamTool(d) {
      if (!d) return;
      aiSetState('tool_call');
      toolLog.style.display = '';
      var row = mkEl('div', 'ai-tool-row');
      var key = String(d.id != null ? d.id : (d.round != null ? d.round : toolLog.children.length + 1));
      row.setAttribute('data-key', key);
      row.setAttribute('data-status', 'running');
      row.appendChild(mkEl('span', 'ai-tool-ic', '🔧'));
      var tLabel = d.label || AI_TOOL_LABEL[d.name] || d.name || '工具';
      row.appendChild(mkEl('span', 'ai-tool-name', tLabel));
      if (aiToolTrace[aiToolTrace.length - 1] !== tLabel) aiToolTrace.push(tLabel);
      var brief = mkEl('span', 'ai-tool-brief', '进行中…');
      row.appendChild(brief);
      row.appendChild(mkEl('span', 'ai-tool-st', '进行中'));
      toolLog.appendChild(row);
      if (streamFollow) toolLog.scrollTop = toolLog.scrollHeight;
    }
    function onStreamToolResult(d) {
      if (!d) return;
      var key = String(d.id != null ? d.id : '');
      var row = null, rows = toolLog.querySelectorAll('.ai-tool-row');
      for (var i = rows.length - 1; i >= 0; i--) {
        if (rows[i].getAttribute('data-key') === key) { row = rows[i]; break; }
      }
      if (!row && rows.length) row = rows[rows.length - 1];
      if (!row) return;
      var ok = d.ok !== false;
      row.setAttribute('data-status', ok ? 'success' : 'error');
      var brief = row.querySelector('.ai-tool-brief');
      if (brief) {
        brief.textContent = String(d.brief || (ok ? '完成' : '失败'));
        if (!ok) brief.style.color = 'var(--danger)';
      }
      var st = row.querySelector('.ai-tool-st');
      if (st) st.textContent = ok ? '完成' : '失败';
      aiToolDetails.push({ label: (row.querySelector('.ai-tool-name') || {}).textContent || '工具',
                           brief: String(d.brief || (ok ? '完成' : '失败')), ok: ok,
                           detail: String(d.detail || '') });
    }
    function hideStream() {
      streamBox.style.display = 'none';
      streamRaw = '';
    }

    // ---- 澄清提问态：AI 拿不准时逐题回答，可多轮 ----
    var clarifyWrap = mkEl('div', 'ai-clarify');
    clarifyWrap.style.display = 'none';

    // ---- 结果确认态：交给全屏审阅页（v13），旧内嵌差异视图已移除 ----
    var aiResult = null;

    function showResultMode() {
      hideStream();
      ta.value = '';   // 聊天式：这一轮已发出并进入审阅，清空输入框方便直接写下一句
      ta.style.display = 'none';
      status.style.display = 'none';
      composer.style.display = 'none';
      openAiReview(aiResult, { onClose: showInputMode, onFinish: closeAiDialog });
    }

    function showInputMode() {
      ta.style.display = '';
      ta.disabled = false;
      status.style.display = 'none';
      composer.style.display = '';
      syncSend(false);
    }

    // 澄清提问态：AI 拿不准时逐题展示输入框，回答后带历史继续（轮数不限）
    function showClarifyMode(questions, existingRounds) {
      hideClarify();
      ta.style.display = 'none';
      ta.disabled = true;
      status.style.display = 'none';
      composer.style.display = 'none';
      var roundNo = (existingRounds.length + 1);
      var tip = mkEl('div', 'ai-clarify-tip');
      tip.textContent = '🤔 AI 说它还拿不准，需要先向你确认 ' + questions.length + ' 个问题（第 ' + roundNo + ' 轮问询）。回答后继续生成；不想答了可点取消。';
      clarifyWrap.appendChild(tip);
      var inputs = [];
      questions.forEach(function (q, i) {
        var lab = mkEl('label', 'ai-clarify-q', (i + 1) + '. ' + q);
        var inp = document.createElement('textarea');
        inp.className = 'ai-instruction ai-clarify-answer';
        inp.placeholder = '输入你的回答…';
        inp.setAttribute('maxlength', '500');
        clarifyWrap.appendChild(lab);
        clarifyWrap.appendChild(inp);
        inputs.push({ q: q, inp: inp });
      });
      // AI 问题之外的主动补充（可选）：并入本轮最后一问的回答注入对话
      var extraLab = mkEl('label', 'ai-clarify-q', '<i class="ic ic-subtitle"></i> 其他补充（可选）：除了上面的问题，还有什么想告诉 AI 的吗？');
      var extraInp = document.createElement('textarea');
      extraInp.className = 'ai-instruction ai-clarify-answer';
      extraInp.placeholder = '选填。写下任何补充、纠正或额外要求，AI 会一并参考…';
      extraInp.setAttribute('maxlength', '300');
      clarifyWrap.appendChild(extraLab);
      clarifyWrap.appendChild(extraInp);
      var bRow = mkEl('div', 'ai-clarify-btns');
      var submit = mkBtn('<i class="ic ic-checkall"></i> 提交回答，继续生成');
      submit.className = 'btn btn-primary btn-xs';
      var abort = mkBtn('<i class="ic ic-close"></i> 取消');
      abort.className = 'btn btn-outline btn-xs';
      bRow.appendChild(submit);
      bRow.appendChild(abort);
      clarifyWrap.appendChild(bRow);
      clarifyWrap.style.display = '';
      submit.addEventListener('click', function () {
        var next = existingRounds.slice();
        var hasEmpty = false;
        inputs.forEach(function (it) {
          var a = it.inp.value.trim();
          if (!a) hasEmpty = true;
          next.push({ q: it.q, a: a });
        });
        if (hasEmpty) {
          showToast('⚠️ 请回答全部问题后再提交', 'error');
          return;
        }
        var extra = extraInp.value.trim();
        if (extra && next.length) {
          next[next.length - 1].a += '\n【其他补充】' + extra;
        }
        hideClarify();
        runAiFlow(next);
      });
      abort.addEventListener('click', function () {
        hideClarify();
        showInputMode();
      });
      setTimeout(function () { if (inputs[0]) inputs[0].inp.focus(); }, 30);
    }

    function hideClarify() {
      clarifyWrap.innerHTML = '';
      clarifyWrap.style.display = 'none';
    }

    // 主流程：可携带澄清历史多轮执行
    async function runAiFlow(clarifyRounds) {
      var instruction = ta.value.trim();
      if (!instruction) {
        showToast('⚠️ 请先描述你想让 AI 做什么', 'error');
        ta.focus();
        runBtn.disabled = false;
        syncSend(false);
        return;
      }
      if (needPolicy()) {
        openPolicyDialog(function () { runBtn.click(); });
        return;
      }
      var prefs = loadAiPrefs();
      runBtn.disabled = true;
      syncSend(true);
      ta.disabled = true;
      ta.style.display = '';
      var dots = 0;
      var dotTimer = setInterval(function () {
        dots = (dots + 1) % 4;
        syncSend(true);
      }, 400);
      status.style.display = 'none';
      var staleErr = body.querySelector('.ai-err-card');
      if (staleErr && staleErr.parentNode) staleErr.parentNode.removeChild(staleErr);
      aiSetState('thinking');
      aiAbortCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      var ok = false;
      function clearUp() {
        clearInterval(dotTimer);
        hideStream();
        aiAbortCtrl = null;
        if (!ok && !document.querySelector('.policy-modal')) {
          runBtn.disabled = false;
          syncSend(false);
          ta.disabled = false;
          syncSend(false);
        }
      }
      // 错误统一成低调卡片（kind 归一），替代满屏红框
      function showAiError(msg, httpStatus) {
        status.innerHTML = '';
        status.style.display = 'none';
        var old = body.querySelector('.ai-err-card');
        if (old && old.parentNode) old.parentNode.removeChild(old);
        var card = aiErrCard(msg, httpStatus, function () { runAiFlow(clarifyRounds); });
        body.appendChild(card);
      }
      // 失败收尾：主动停止保留已收内容；其余走错误卡片
      function handleRunError(e) {
        var aborted = !!(e && (e.name === 'AbortError' || /abort|已停止/.test(String(e.message || ''))));
        if (aborted) {
          clearInterval(dotTimer);
          aiAbortCtrl = null;
          runBtn.disabled = false;
          syncSend(false);
          ta.disabled = false;
          syncSend(false);
          setPhaseText(streamPhase, '⏹️ 已停止（保留已生成内容供参考）');
          showAiError('已停止生成', 0);
          return;
        }
        clearUp();
        var msg = String(e && e.message ? e.message : '网络错误');
        showAiError(msg, /未登录/.test(msg) ? 401 : 0);
      }
      // 澄清响应：需要用户回答时转入澄清态（本次请求不计配额）
      function handleClarify(r) {
        if (r && r.need_clarify && Array.isArray(r.questions) && r.questions.length) {
          hideStream();
          showClarifyMode(r.questions, r.clarifyRounds || clarifyRounds);
          return true;
        }
        return false;
      }
      // 自有代理直连模式：请求完全不经过平台服务器（独立模块 ai-direct.js 处理）
      if (prefs.mode === 'own' && prefs.ownProxy && window.AIDirect) {
        try {
          showStream('🤖 正在生成…');
          var r = await window.AIDirect.edit({
            title: newTitle.value,
            content: newContent.value,
            instruction: instruction,
            style: prefs.style,
            now: prefs.sendTime ? formatLocalNow() : '',
            proxy: prefs.ownProxy,
            baseUrl: prefs.ownBaseUrl,
            apiKey: prefs.ownApiKey,
            model: prefs.ownModel,
            deepThink: prefs.ownDeepThink,
            bodyEnabled: prefs.ownBodyEnabled,
            bodyKey: prefs.ownBodyKey,
            bodyJson: prefs.ownBodyJson,
            clarifyRounds: clarifyRounds,
            history: aiHistory.slice(-12),
            onPhase: onStreamPhase,
            onDelta: onStreamDelta,
            onTool: onStreamTool,
            onToolResult: onStreamToolResult,
            signal: aiAbortCtrl ? aiAbortCtrl.signal : undefined
          });
          clearUp();
          hideStream();
          if (handleClarify(r)) return;
          if (r.success && typeof r.content === 'string') {
            aiResult = {
              original: newContent.value,
              content: r.content,
              mode: r.mode || 'full',
              agent: !!r.agent,
              applied: r.applied || 0,
              failed: r.failed || 0,
              chunked: !!r.chunked,
              chunks: r.chunks || 0,
              attempts: r.attempts || 1,
              instruction: instruction,
              tools: aiToolTrace.slice()
            };
            ok = true;
            showResultMode();
          } else {
            showAiError(r.message || 'AI 编辑失败', 0);
          }
        } catch (e) {
          handleRunError(e);
        }
        return;
      }
      try {
        showStream('🤖 正在生成…');
        var r = await aiApiStream({
          action: 'edit',
          noteId: (typeof editingId === 'number' && editingId > 0) ? editingId : 0,
          title: newTitle.value,
          content: newContent.value,
          instruction: instruction,
          policyVersion: AI_POLICY_VERSION,
          clarifyRounds: clarifyRounds,
          history: aiHistory.slice(-12),
          prefs: {
            mode: prefs.mode,
            platformKey: prefs.platformKey,
            ownBaseUrl: prefs.ownBaseUrl,
            ownApiKey: prefs.ownApiKey,
            ownModel: prefs.ownModel,
            style: prefs.style,
            time: prefs.sendTime ? formatLocalNow() : '',
            deepThink: prefs.ownDeepThink,
            bodyEnabled: prefs.ownBodyEnabled,
            bodyKey: prefs.ownBodyKey,
            bodyJson: prefs.ownBodyJson
          }
        }, { onDelta: onStreamDelta, onPhase: onStreamPhase, onTool: onStreamTool, onToolResult: onStreamToolResult,
             signal: aiAbortCtrl ? aiAbortCtrl.signal : undefined });
        clearUp();
        hideStream();
        if (r.need_policy) {
          openPolicyDialog(function () { runBtn.click(); });
        } else if (handleClarify(r)) {
          return;
        } else if (r.success && typeof r.content === 'string') {
          aiResult = {
            original: newContent.value,
            content: r.content,
            mode: r.mode || 'full',
            agent: !!r.agent,
            applied: r.applied || 0,
            failed: r.failed || 0,
            chunked: !!r.chunked,
            chunks: r.chunks || 0,
            attempts: r.attempts || 1,
            instruction: instruction,
            tools: aiToolTrace.slice()
          };
          if (r.usage) renderUsage(r.usage);
          ok = true;
          showResultMode();
        } else {
          if (r.usage) renderUsage(r.usage);
          showAiError(r.message || 'AI 编辑失败', 0);
        }
      } catch (e) {
        handleRunError(e);
      }
    }

    // 对话式布局（用户草图）：以上全在历史记录区，底部只留输入条。
    // runBtn 不在 DOM 中，仅作流程触发器与状态载体；发送键显示流程状态（思考中 spinner）。
    var runBtn = mkBtn('<i class="ic ic-robot-pink"></i> 开始编辑');
    runBtn.className = 'btn btn-primary btn-xs';
    var undoBtn = mkBtn('<i class="ic ic-recycle"></i>', '撤回上一步 AI 改动');
    undoBtn.className = 'ai-icon-btn';
    undoBtn.type = 'button';
    undoBtn.addEventListener('click', function () {
      if (!aiUndoLast()) return;
      aiConvNote('↩️ 已撤回上一步 AI 写入');
      // 告知模型撤回事件，避免下一轮误以为改动还在
      aiHistory.push({ role: 'assistant', content: '（用户撤回了上一步改动）' });
      showToast('↩️ 已撤回上一步 AI 改动', 'success');
    });

    runBtn.addEventListener('click', function () { runAiFlow([]); });

    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        runBtn.click();
      }
    });

    // 输入框自动增高：单行 46px 起步，随内容长高（封顶 160px 后内部滚动）
    ta.addEventListener('input', function () {
      ta.style.height = 'auto';
      ta.style.height = Math.min(ta.scrollHeight + 2, 160) + 'px';
    });

    // 发送键状态同步：流程忙碌 = spinner，空闲 = 发送图标
    var sendBtn;
    function syncSend(busy) {
      if (!sendBtn) return;
      if (busy) { sendBtn.disabled = true; sendBtn.innerHTML = '<i class="ic ic-robot-pink ic-spin"></i>'; }
      else { sendBtn.disabled = false; sendBtn.innerHTML = '<i class="ic ic-send"></i>'; }
    }

    // 底部输入条：输入框 + 右下（撤回 / 图片 / 发送）——图片走图床联动插入直链
    var composer = mkEl('div', 'ai-composer');
    var composerActions = mkEl('div', 'ai-composer-actions');
    var imgBtn = mkBtn('<i class="ic ic-image"></i>', '插入图片（上传到图床）');
    imgBtn.className = 'ai-icon-btn';
    imgBtn.type = 'button';
    imgBtn.addEventListener('click', function () {
      if (typeof ImgBridge === 'undefined') { showToast('❌ 图床联动模块未加载', 'error'); return; }
      // 先弹原生文件选择器，拿到 file 再走 ImgBridge 上传链路；
      //（ImgBridge.insert(ta) 不含选文件逻辑，直接调用只会闪一下「上传中」占位符再被删掉）
      var inp = document.createElement('input');
      inp.type = 'file';
      inp.accept = 'image/*';
      // 手机 Chrome：未挂载到 DOM 的 <input type=file> 偶发 click 不振起原生选择器 → 挂上再点
      inp.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;pointer-events:none;';
      document.body.appendChild(inp);
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        inp.remove();
        if (f) ImgBridge.insert(ta, f);
      });
      inp.click();
    });
    composerActions.appendChild(undoBtn);
    composerActions.appendChild(imgBtn);
    composer.appendChild(ta);
    sendBtn = mkBtn('<i class="ic ic-send"></i>', '发送（Ctrl+Enter）');
    sendBtn.className = 'ai-send';
    sendBtn.type = 'button';
    sendBtn.addEventListener('click', function () { runBtn.click(); });
    composerActions.appendChild(sendBtn);
    composer.appendChild(composerActions);

    chatLog.appendChild(convBox);
    chatLog.appendChild(streamBox);
    chatLog.appendChild(status);
    chatLog.appendChild(clarifyWrap);
    body.appendChild(chatLog);
    body.appendChild(composer);
    modal.appendChild(head);
    modal.appendChild(body);
    overlay.appendChild(modal);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeAiDialog(); });
    document.body.appendChild(overlay);
    renderUsage();
    setTimeout(function () { ta.focus(); }, 50);
  }

  if (btnAiEdit) {
    btnAiEdit.addEventListener('click', function () {
      if (newNoteForm.style.display === 'none' || newNoteForm.style.display === '') {
        showToast('⚠️ 请先新建或打开一篇便签，再使用 AI 编辑', 'error');
        return;
      }
      openAiDialog();
    });
  }

  // ============== 修改密码弹窗 ==============
  function closeChangePassDialog() {
    var ov = document.querySelector('.changepass-modal');
    if (ov) ov.closest('.md-modal-overlay').remove();
  }

  function openChangePassDialog() {
    closeChangePassDialog();
    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal changepass-modal');

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-key"></i> 修改密码'));
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeChangePassDialog);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');
    var status = mkEl('div', 'ai-status');
    status.style.display = 'none';

    var oldInp = mkEl('input', 'form-input ai-input');
    oldInp.type = 'password';
    oldInp.placeholder = '当前密码';
    oldInp.autocomplete = 'current-password';
    var newInp = mkEl('input', 'form-input ai-input');
    newInp.type = 'password';
    newInp.placeholder = '新密码（至少 8 个字符）';
    newInp.autocomplete = 'new-password';
    var new2Inp = mkEl('input', 'form-input ai-input');
    new2Inp.type = 'password';
    new2Inp.placeholder = '再次输入新密码';
    new2Inp.autocomplete = 'new-password';
    body.appendChild(mkEl('div', 'ai-set-label', '当前密码'));
    body.appendChild(oldInp);
    body.appendChild(mkEl('div', 'ai-set-label', '新密码'));
    body.appendChild(newInp);
    body.appendChild(mkEl('div', 'ai-set-label', '确认新密码'));
    body.appendChild(new2Inp);
    body.appendChild(status);

    var foot = mkEl('div', 'md-modal-foot');
    var okBtn = mkBtn('<i class="ic ic-save"></i> 确认修改');
    okBtn.className = 'btn btn-primary btn-xs';
    var cancelBtn = mkBtn('关闭');
    cancelBtn.className = 'btn btn-outline btn-xs';
    foot.appendChild(okBtn);
    foot.appendChild(cancelBtn);
    foot.appendChild(mkEl('span', 'md-hint', '修改成功后下次登录生效'));

    okBtn.addEventListener('click', async function () {
      status.style.display = 'none';
      if (newInp.value.length < 8) {
        status.textContent = '❌ 新密码至少需要 8 个字符';
        status.style.display = 'block';
        return;
      }
      if (newInp.value !== new2Inp.value) {
        status.textContent = '❌ 两次输入的新密码不一致';
        status.style.display = 'block';
        return;
      }
      okBtn.disabled = true;
      okBtn.textContent = '提交中...';
      try {
        var resp = await fetch('api/auth.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          cache: 'no-store',
          body: jbody({ action: 'changepass', oldpass: oldInp.value, newpass: newInp.value })
        });
        if (resp.status === 401) { await check401(); return; }
        var r = await resp.json();
        if (r.success) {
          showToast('✅ ' + r.message, 'success');
          closeChangePassDialog();
        } else {
          status.textContent = '❌ ' + (r.message || '修改失败');
          status.style.display = 'block';
        }
      } catch (e) {
        status.textContent = '❌ 网络错误，请稍后再试';
        status.style.display = 'block';
      }
      okBtn.disabled = false;
      okBtn.innerHTML = '<i class="ic ic-save"></i> 确认修改';
    });
    cancelBtn.addEventListener('click', closeChangePassDialog);

    modal.appendChild(head);
    modal.appendChild(body);
    modal.appendChild(foot);
    overlay.appendChild(modal);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeChangePassDialog(); });
    document.body.appendChild(overlay);
    setTimeout(function () { oldInp.focus(); }, 50);
  }

  var btnChangePass = document.getElementById('btnChangePass');
  if (btnChangePass) {
    btnChangePass.addEventListener('click', function () {
      closeSettingsMenu();
      openChangePassDialog();
    });
  }

  // ============== 注销账号弹窗（需邮箱验证码核验） ==============
  function closeDeleteAccountDialog() {
    var ov = document.querySelector('.delaccount-modal');
    if (ov) ov.closest('.md-modal-overlay').remove();
  }

  function maskEmail(email) {
    var at = email.indexOf('@');
    if (at < 1) return email;
    var name = email.slice(0, at);
    var domain = email.slice(at);
    var head = name.slice(0, Math.min(2, name.length));
    return head + '***' + domain;
  }

  function openDeleteAccountDialog() {
    closeDeleteAccountDialog();
    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal delaccount-modal');

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-trash"></i> 注销账号'));
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeDeleteAccountDialog);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');

    var warn = mkEl('div', 'ai-status');
    warn.textContent = '⚠️ 注销后账号和所有便签、AI 设置将被永久删除，无法恢复！需要邮箱验证码核验身份。';
    warn.style.display = 'block';

    var status = mkEl('div', 'ai-status');
    status.style.display = 'none';

    var emailInfo = mkEl('div', 'ai-assigned');
    emailInfo.innerHTML = '<i class="ic ic-mail"></i> 正在获取账号邮箱...';

    var codeRow = mkEl('div', 'code-row');
    var codeInput = mkEl('input', 'form-input');
    codeInput.type = 'text';
    codeInput.placeholder = '6 位验证码';
    codeInput.maxLength = 6;
    codeInput.setAttribute('inputmode', 'numeric');
    var sendBtn = mkBtn('<i class="ic ic-mail"></i> 发送验证码');
    sendBtn.className = 'btn btn-outline btn-xs';
    codeRow.appendChild(codeInput);
    codeRow.appendChild(sendBtn);

    body.appendChild(warn);
    body.appendChild(emailInfo);
    body.appendChild(mkEl('div', 'ai-set-label', '邮箱验证码（发送到你的注册邮箱）'));
    body.appendChild(codeRow);
    body.appendChild(status);

    var foot = mkEl('div', 'md-modal-foot');
    var confirmBtn = mkBtn('<i class="ic ic-trash"></i> 确认注销（不可恢复）');
    confirmBtn.className = 'btn btn-primary btn-xs btn-danger';
    var cancelBtn = mkBtn('取消');
    cancelBtn.className = 'btn btn-outline btn-xs';
    foot.appendChild(confirmBtn);
    foot.appendChild(cancelBtn);

    var userEmail = '';

    // 获取当前账号邮箱（脱敏展示）
    (async function () {
      try {
        var resp = await fetch('api/auth.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          cache: 'no-store',
          body: jbody({ action: 'check' })
        });
        var r = await resp.json();
        if (r.logged_in && r.user && r.user.email) {
          userEmail = r.user.email;
          setIconText(emailInfo, 'mail', '账号邮箱：' + maskEmail(userEmail) + '（验证码将发送到该邮箱）');
        } else {
          emailInfo.textContent = '⚠️ 无法获取账号邮箱，请刷新页面后重试';
          sendBtn.disabled = true;
        }
      } catch (e) {
        emailInfo.textContent = '⚠️ 网络错误，请稍后重试';
      }
    })();

    async function callAuth(data) {
      var resp = await fetch('api/auth.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        cache: 'no-store',
        body: jbody(data)
      });
      if (resp.status === 401) { await check401(); throw new Error('请先登录'); }
      return resp.json();
    }

    sendBtn.addEventListener('click', async function () {
      if (!userEmail) return;
      status.style.display = 'none';
      sendBtn.disabled = true;
      var old = sendBtn.textContent;
      sendBtn.textContent = '发送中...';
      try {
        var r = await callAuth({ action: 'sendcode', email: userEmail, purpose: 'delete' });
        if (r.success) {
          showToast('✅ ' + r.message, 'success');
          var left = 60;
          sendBtn.textContent = left + 's';
          var timer = setInterval(function () {
            left--;
            if (left <= 0) { clearInterval(timer); sendBtn.disabled = false; sendBtn.textContent = old; }
            else { sendBtn.textContent = left + 's'; }
          }, 1000);
        } else {
          sendBtn.disabled = false;
          sendBtn.textContent = old;
          status.textContent = '❌ ' + (r.message || '发送失败');
          status.style.display = 'block';
        }
      } catch (e) {
        sendBtn.disabled = false;
        sendBtn.textContent = old;
        status.textContent = '❌ ' + (e.message || '网络错误');
        status.style.display = 'block';
      }
    });

    confirmBtn.addEventListener('click', async function () {
      status.style.display = 'none';
      var code = codeInput.value.trim();
      if (!/^\d{6}$/.test(code)) {
        status.textContent = '❌ 请输入 6 位邮箱验证码';
        status.style.display = 'block';
        return;
      }
      if (!window.confirm('⚠️ 最后确认：注销后所有数据永久删除，无法恢复。确定继续吗？')) {
        return;
      }
      confirmBtn.disabled = true;
      confirmBtn.textContent = '注销中...';
      try {
        var r = await callAuth({ action: 'deleteaccount', email: userEmail, code: code });
        if (r.success) {
          showToast('🗑 ' + r.message, 'success');
          setTimeout(function () { window.location.href = 'login.php'; }, 1200);
        } else {
          status.textContent = '❌ ' + (r.message || '注销失败');
          status.style.display = 'block';
          confirmBtn.disabled = false;
          confirmBtn.innerHTML = '<i class="ic ic-trash"></i> 确认注销（不可恢复）';
        }
      } catch (e) {
        status.textContent = '❌ ' + (e.message || '网络错误');
        status.style.display = 'block';
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="ic ic-trash"></i> 确认注销（不可恢复）';
      }
    });
    cancelBtn.addEventListener('click', closeDeleteAccountDialog);

    modal.appendChild(head);
    modal.appendChild(body);
    modal.appendChild(foot);
    overlay.appendChild(modal);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeDeleteAccountDialog(); });
    document.body.appendChild(overlay);
  }

  var btnDeleteAccount = document.getElementById('btnDeleteAccount');
  if (btnDeleteAccount) {
    btnDeleteAccount.addEventListener('click', function () {
      closeSettingsMenu();
      openDeleteAccountDialog();
    });
  }

  // ============== 设置菜单（改密 / 注销账号） ==============
  var btnSettings = document.getElementById('btnSettings');
  var settingsMenu = document.getElementById('settingsMenu');

  function closeSettingsMenu() {
    if (settingsMenu) settingsMenu.style.display = 'none';
  }

  function toggleSettingsMenu() {
    if (!settingsMenu) return;
    settingsMenu.style.display = settingsMenu.style.display === 'none' ? 'block' : 'none';
  }

  // ============== 工具栏自适应（v114：搜索已移入弹层，只做面包屑降级） ==============
  function adaptiveToolbar() {
    var tl = document.querySelector('.toolbar-left');
    var crumb = document.getElementById('folderCrumb');
    if (!tl || !crumb) return;
    document.body.classList.remove('crumb-noicon');
    if (window.innerWidth > 768) return;
    if (tl.scrollWidth > tl.clientWidth + 1) {
      tl.classList.add('crumb-noicon');           // 面包屑过长先去图标
    }
  }
  // ---- 搜索弹层（v114：搜索框移出工具栏；PC 按 T 或 / 呼出，手机走抽屉/搜索钮；Esc/点遮罩关闭） ----
  var searchOverlay = document.getElementById('searchOverlay');
  function openSearchOverlay() {
    if (!searchOverlay) return;
    searchOverlay.style.display = 'flex';
    var inp = document.getElementById('searchInput');
    if (inp) setTimeout(function () { inp.focus(); }, 60);
  }
  function closeSearchOverlay() {
    if (!searchOverlay || searchOverlay.style.display === 'none') return;
    searchOverlay.style.display = 'none';
    hideSearchPanel();
  }
  if (searchOverlay) {
    searchOverlay.addEventListener('pointerdown', function (e) {
      if (e.target === searchOverlay) closeSearchOverlay();   // 点遮罩空白处关闭
    });
  }
  var searchToggle = document.getElementById('btnSearchToggle');
  if (searchToggle) searchToggle.addEventListener('click', openSearchOverlay);
  (function () {
    var crumbEl = document.getElementById('folderCrumb');
    if (crumbEl) new MutationObserver(adaptiveToolbar).observe(crumbEl, { childList: true });
    window.addEventListener('resize', adaptiveToolbar);
  })();
  adaptiveToolbar();

  // ============== 移动端抽屉菜单（v88） ==============
  var mm = document.getElementById('mobileMenu');
  var mmOverlay = document.getElementById('mobileOverlay');
  var mmBtn = document.getElementById('btnMobileMenu');
  function mmOpen() {
    if (!mm) return;
    mm.classList.add('open');
    if (mmOverlay) mmOverlay.classList.add('open');
    document.body.classList.add('mm-open');
    if (mmBtn) mmBtn.setAttribute('aria-expanded', 'true');
    mm.setAttribute('aria-hidden', 'false');
  }
  function mmClose() {
    if (!mm) return;
    mm.classList.remove('open');
    if (mmOverlay) mmOverlay.classList.remove('open');
    document.body.classList.remove('mm-open');
    if (mmBtn) mmBtn.setAttribute('aria-expanded', 'false');
    mm.setAttribute('aria-hidden', 'true');
  }
  if (mmBtn && mm) {
    mmBtn.addEventListener('click', function () {
      mm.classList.contains('open') ? mmClose() : mmOpen();
    });
    if (mmOverlay) mmOverlay.addEventListener('click', mmClose);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') mmClose(); });
    // 菜单项：转接触发器（复用既有 id 处理器）
    function mmBind(mid, fn) {
      var el = document.getElementById(mid);
      if (el) el.addEventListener('click', function () { mmClose(); setTimeout(fn, 60); });
    }
    mmBind('mNew', function () { var b = document.getElementById('btnNewNote'); if (b) b.click(); });
    mmBind('mAi', function () { var b = document.getElementById('btnAiOrganize'); if (b) b.click(); });
    mmBind('mFolder', function () { var b = document.getElementById('btnNewFolder'); if (b) b.click(); });
    mmBind('mSearch', function () { openSearchOverlay(); });
    mmBind('mDelete', function () { var b = document.getElementById('btnDeleteAccount'); if (b) b.click(); });
    mmBind('mTutorial', function () { var b = document.getElementById('btnTutorial'); if (b) b.click(); });
    mmBind('mImgBridge', function () { var b = document.getElementById('btnImgBridge'); if (b) b.click(); });
    mmBind('mMdColors', function () { var b = document.getElementById('btnMdColors'); if (b) b.click(); });
    mmBind('mIconset', function () { var b = document.getElementById('btnIconset'); if (b) b.click(); else if (window.PixelIconset) window.PixelIconset.set(window.PixelIconset.get() === 'mix' ? 'v1' : (window.PixelIconset.get() === 'v1' ? 'v2' : 'mix')); });
    mmBind('mChangePass', function () { var b = document.getElementById('btnChangePass'); if (b) b.click(); });
    mmBind('mLogout', function () { var f = document.querySelector('form[action="logout.php"]'); if (f) f.submit(); });
    // FAB：新建便签
    var fab = document.getElementById('fabNew');
    if (fab) fab.addEventListener('click', function () { var b = document.getElementById('btnNewNote'); if (b) b.click(); });
    // 编辑器开合时标 body，FAB 让位
    if (newNoteForm) {
      new MutationObserver(function () {
        var open = newNoteForm.style.display !== 'none';
        document.body.classList.toggle('editor-open', open);
      }).observe(newNoteForm, { attributes: true, attributeFilter: ['style'] });
    }
  }

  // ============== PC 键盘快捷键（v114，MC 式：T 或 / 搜索、E 抽屉） ==============
  // 手机不生效（无实体键盘）；任何输入框聚焦时、编辑器/弹窗开着时不抢占按键
  document.addEventListener('keydown', function (e) {
    if (window.innerWidth <= 768) return;
    var tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return;
    if (newNoteForm && newNoteForm.style.display !== 'none') return;   // 全屏编辑器优先
    var k = e.key.toLowerCase();
    if (k === 't' || e.key === '/') {
      e.preventDefault();
      openSearchOverlay();
    } else if (k === 'e') {
      e.preventDefault();
      if (mm && !mm.classList.contains('open')) mmOpen();
    }
  });

  // ============== PC 右键菜单（v115：上下文感知，Windows 式） ==============
  // 三类：空白（新建类） / 便签卡片（打开、编辑、置顶、换色、分享、移动、删除） / 文件夹卡片（打开、子文件夹、改名、移动、分享、删除）
  // 手机不触发（无 contextmenu）；编辑器/弹窗/搜索弹层开着时不弹
  var ctxMenuEl = null;
  function closeCtxMenu() {
    if (ctxMenuEl) { ctxMenuEl.remove(); ctxMenuEl = null; }
  }
  function openCtxMenu(x, y, items) {
    closeCtxMenu();
    var menu = mkEl('div', 'ctx-menu');
    items.forEach(function (it) {
      var b = mkEl('button', 'ctx-item' + (it.danger ? ' ctx-danger' : ''));
      b.type = 'button';
      if (it.icon) b.innerHTML = it.icon;
      b.appendChild(document.createTextNode(it.label));
      b.addEventListener('click', function (e) { e.stopPropagation(); closeCtxMenu(); it.fn(); });
      menu.appendChild(b);
    });
    document.body.appendChild(menu);
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    var left = Math.min(Math.max(8, x), window.innerWidth - mw - 8);
    var top = Math.min(Math.max(8, y), window.innerHeight - mh - 8);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    ctxMenuEl = menu;
  }
  function blankCtxItems() {
    return [
      { label: '新建便签', icon: '<i class="ic ic-plus-pink"></i>', fn: function () { var b = document.getElementById('btnNewNote'); if (b) b.click(); } },
      { label: '新建文件夹', icon: '<i class="ic ic-folder"></i>', fn: function () { var b = document.getElementById('btnNewFolder'); if (b) b.click(); } }
    ];
  }
  function noteCtxItems(note, card) {
    var shared = !!(note.share_url || (note.share_token && String(note.share_token).length === 36));
    return [
      { label: '打开', icon: '<i class="ic ic-note"></i>', fn: function () { var c = card.querySelector('.note-content'); if (c) c.click(); } },
      { label: '编辑', icon: '<i class="ic ic-pencil"></i>', fn: function () { openEditorForNote(note.id, card); } },
      { label: note.pinned ? '取消置顶' : '置顶', icon: '<i class="ic ic-pin"></i>', fn: function () { togglePin(card); } },
      { label: '切换颜色', icon: '<i class="ic ic-palette"></i>', fn: function () { cycleColor(card); } },
      { label: shared ? '管理公开分享' : '生成公开分享链接', icon: shared ? '🌐' : '<i class="ic ic-link"></i>', fn: function () { openShareDialog(note.id, card); } },
      { label: '移动到…', icon: '<i class="ic ic-back"></i>', fn: function () { promptMoveNote(note); } },
      { label: '删除', icon: '<i class="ic ic-trash"></i>', danger: true, fn: function () { deleteNote(card); } }
    ];
  }
  function folderCtxItems(folder) {
    var f = foldersById[folder.id] || folder;
    return [
      { label: '打开', icon: '<i class="ic ic-folder"></i>', fn: function () { switchFolder(folder.id); } },
      { label: '在里面新建子文件夹', icon: '<i class="ic ic-plus"></i>', fn: function () { promptNewFolder(folder.id); } },
      { label: '改名', icon: '<i class="ic ic-pencil"></i>', fn: function () { promptRenameFolder(f); } },
      { label: '移动到…', icon: '<i class="ic ic-back"></i>', fn: function () { promptMoveFolder(f); } },
      { label: '分享', icon: '<i class="ic ic-link"></i>', fn: function () { openShareDialog(folder.id, f, 'folder'); } },
      { label: '删除（内容上移）', icon: '<i class="ic ic-trash"></i>', danger: true, fn: function () { promptDeleteFolder(f); } }
    ];
  }
  document.addEventListener('contextmenu', function (e) {
    if (window.innerWidth <= 768) return;
    if (!e.target || !e.target.closest) return;   // 罕见宿主（如 document 本身）无 closest，防御
    if (e.target.closest('.new-note-form, .md-modal-overlay, .search-overlay, .ai-modal, .ctx-menu')) return;
    closeFolderMenu();   // 关掉可能开着的文件夹浮层，避免叠菜单
    var nCard = e.target.closest('.note-card');
    if (nCard) {
      var nid = parseInt(nCard.getAttribute('data-id'));
      var note = nCard._noteData || notesById[nid];
      if (!note) return;
      e.preventDefault();
      openCtxMenu(e.clientX, e.clientY, noteCtxItems(note, nCard));
      return;
    }
    var fCard = e.target.closest('.folder-card');
    if (fCard) {
      var fid = parseInt(fCard.getAttribute('data-folder-id'));
      if (!foldersById[fid]) return;
      e.preventDefault();
      openCtxMenu(e.clientX, e.clientY, folderCtxItems(foldersById[fid]));
      return;
    }
    if (e.target.closest('input, textarea, select, a, button, [contenteditable]')) return;
    e.preventDefault();
    openCtxMenu(e.clientX, e.clientY, blankCtxItems());
  });
  document.addEventListener('click', closeCtxMenu);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeCtxMenu(); });
  window.addEventListener('blur', closeCtxMenu);

  if (btnSettings && settingsMenu) {
    btnSettings.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleSettingsMenu();
    });
    document.addEventListener('click', function (e) {
      if (settingsMenu.style.display !== 'none' && !e.target.closest('.nav-settings-wrap')) {
        closeSettingsMenu();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeSettingsMenu();
    });
    settingsMenu.querySelectorAll('.settings-menu-item').forEach(function (item) {
      item.addEventListener('click', closeSettingsMenu);
    });
  }

  // ============== 新手教程（设置菜单入口，详细版） ==============
  // 内容主源：window.TUTORIAL_MD（docs/便签使用教程 md 构建而来，tools/build-tutorial.js 生成）
  // 本文件内的章节版仅作 TUTORIAL_MD 缺失时的回退
  function openTutorial() {
    if (window.TUTORIAL_MD && window.PixelMD) {
      var overlay = mkEl('div', 'md-modal-overlay');
      overlay.style.zIndex = '21000';
      var modal = mkEl('div', 'md-modal tutorial-modal');
      var head = mkEl('div', 'md-modal-head');
      head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-book"></i> 使用教程 · 从入门到大师'));
      var closeBtn = mkBtn('<i class="ic ic-close"></i> 关闭', '关闭教程');
      closeBtn.className = 'md-modal-close';
      closeBtn.addEventListener('click', function () { document.body.removeChild(overlay); });
      head.appendChild(closeBtn);
      modal.appendChild(head);
      var body = mkEl('div', 'md-modal-body');
      var content = mkEl('div', 'note-content md-body md-static');
      content.style.maxHeight = 'none';
      content.style.overflow = 'visible';
      content.style.cursor = 'default';
      content.innerHTML = window.PixelMD.render(window.TUTORIAL_MD);
      body.appendChild(content);
      modal.appendChild(body);
      overlay.appendChild(modal);
      document.body.appendChild(overlay);
      var close = function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); };
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
      document.addEventListener('keydown', function onKey(e) {
        if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
      });
      return;
    }
    var isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    var overlay = mkEl('div', 'md-modal-overlay');
    overlay.style.zIndex = '21000';

    var modal = mkEl('div', 'md-modal tutorial-modal');
    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-book"></i> 新手教程 · 从零玩转 Pixel Notes'));
    var closeBtn = mkBtn('<i class="ic ic-close"></i> 关闭', '关闭教程');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', function () { document.body.removeChild(overlay); });
    head.appendChild(closeBtn);
    modal.appendChild(head);

    var body = mkEl('div', 'md-modal-body');

    // 教程内容：按章节组织，行内小标签高亮操作名
    function sec(icon, title, rows) {
      var s = mkEl('div', 'tut-sec');
      s.appendChild(mkEl('div', 'tut-sec-title', icon + ' ' + title));
      var ul = mkEl('ul', 'tut-list');
      rows.forEach(function (r) { var li = mkEl('li', null, ''); li.innerHTML = r; ul.appendChild(li); });
      s.appendChild(ul);
      return s;
    }

    body.appendChild(sec('🗂️', '文件夹', [
      '顶部「📁 新建文件夹」在当前层级建夹；点文件夹卡进入，左上角面包屑（🏠 主页 / 📁 工作）随时跳回任意层级。',
      '卡片右上角「⋮」：改名、移动、删除（删除只删夹本身，里面的便签和子文件夹全部上移一级，<b>永远不会丢便签</b>）、建子文件夹。',
      '顶部搜索框能搜便签也能搜文件夹，结果里直接显示所在路径，点击直达。'
    ]));

    body.appendChild(sec('👆', '选中与对调' + (isTouch ? '（手机）' : '（鼠标）'), isTouch ? [
      '<b>按住卡片不动约 0.7 秒</b>：进入选择模式（卡片出现勾选高亮），再点其他卡片可加选/减选。',
      '已选中 1 张时，<b>按住另一张不动</b>：两张卡片位置直接对调（便签和便签、文件夹和文件夹、便签和文件夹之间都行）。',
      '选中恰好 2 张时，底部操作栏出现「⇄ 对调」按钮，点它效果相同。',
      '点空白处或按「✕ 取消选择」退出选择模式。'
    ] : [
      '<b>长按卡片 0.5 秒</b>：进入选择模式（卡片出现勾选高亮），点击其他卡片加选/减选，点空白清空选择。',
      '已选中 1 张时，<b>长按另一张</b>：两张卡片位置直接对调（便签和便签、文件夹和文件夹、便签和文件夹之间都行）。',
      '选中恰好 2 张时，底部操作栏出现「⇄ 对调」按钮，点它效果相同。',
      '<b>空白处按住拖动</b>：拉出粉色框选矩形，框住的卡片全部选中；按住 Ctrl 再拉 = 在已选基础上追加。'
    ]));

    body.appendChild(sec('⌨️', '快捷键' + (isTouch ? '（外接键盘时可用）' : ''), [
      '<b>Ctrl+X / Ctrl+C / Ctrl+V</b>：剪切 / 复制 / 粘贴选中的便签（可跨文件夹移动；复制可反复粘贴，剪切粘贴一次后清空）。',
      '<b>Ctrl+A</b>：全选当前目录的便签和文件夹；<b>Esc</b>：退出选择模式 / 关闭弹窗。',
      '剪贴板独立于选择存在：取消选择后剪贴板内容仍在，底部迷你栏常驻「📥 粘贴」，到目标文件夹点它即可。'
    ]));

    body.appendChild(sec('↕️', '排序', isTouch ? [
      '<b>按住卡片约 0.25 秒后拖动</b>：进入排序拖拽，松手保存新顺序。',
      '直接滑动（按住立刻就划）不会触发拖拽——页面正常滚动，这是防误触设计。',
      '置顶便签排在最前，只能和置顶便签调换位置。'
    ] : [
      '直接按住卡片<b>拖动</b>即可排序（便签、文件夹各自独立排序互不干扰），松手自动保存。',
      '便签卡上<b>长按 0.5 秒选中、再长按另一张 0.5 秒</b>=两张对调（第 6 节手势的选择模式捷径）。',
      '置顶便签排在最前，只能和置顶便签调换位置。'
    ]));

    body.appendChild(sec('<i class="ic ic-robot"></i>', 'AI 功能', [
      '便签编辑器里的「🤖 AI」：写指令（如"把第 3 条改成…"）让 AI 局部修改。AI 只改你指定的部分，改完先给你看<b>差异对比</b>，点「✅ 采纳覆盖」才真正生效，不满意直接取消。',
      '拿不准时 AI 会先<b>提问</b>（"需要哪种风格？"），回答后它继续；提问不消耗配额。',
      '顶部「✨ AI 整理」：像 Claude Code 一样的透明 Agent——你能实时看到它在想什么、查了哪些便签，最后给出整理方案（移动/建夹/改名/排序/颜色/置顶…），预览确认才执行，且<b>可一键撤销</b>（本次会话内）。',
      '长文也没问题：超过 4500 字自动分段处理，每段独立校对。'
    ]));

    body.appendChild(sec('<i class="ic ic-robot"></i>', '更多', [
      '卡片下方「📌」置顶、「🎨」换色（六色）、「🔗」生成公开分享链接（只读、可设有效期）。',
      '便签支持 Markdown：标题/加粗/列表/任务清单/代码块，可内嵌图片、音频、视频和B站链接。',
      '「🔊 朗读」把便签转语音，逐词卡拉 OK 字幕跟读。',
      '设置里可以改密码、注销账号（注销需邮箱验证码二次确认）。'
    ]));

    modal.appendChild(body);
    overlay.appendChild(modal);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) document.body.removeChild(overlay); });
    document.body.appendChild(overlay);
  }

  var btnTutorial = document.getElementById('btnTutorial');
  if (btnTutorial) btnTutorial.addEventListener('click', openTutorial);
  // 便签↔图床联动：设置入口（查看/修改同意状态）
  var btnImgBridge = document.getElementById('btnImgBridge');
  if (btnImgBridge) btnImgBridge.addEventListener('click', function () {
    if (typeof ImgBridge === 'undefined') { showToast('❌ 图床联动模块未加载', 'error'); return; }
    ImgBridge.manage();
  });

  // AI 设置入口（导航栏设置菜单，替代原 AI 弹窗顶栏入口——弹窗保持简洁）
  var btnAiSettings = document.getElementById('btnAiSettings');
  if (btnAiSettings) btnAiSettings.addEventListener('click', function () {
    openAiSettings(function () {});
  });

  // ============== 渲染强调色自定义（设置菜单入口，存 localStorage） ==============
  var MD_COLORS_KEY = 'pixel_notes_md_colors';

  function loadMdColors() {
    try { var d = JSON.parse(localStorage.getItem(MD_COLORS_KEY) || '{}'); return (d && typeof d === 'object') ? d : {}; }
    catch (e) { return {}; }
  }
  // 把用户自定义色写到 CSS 变量；空 = 清除自定义、回落到主题默认
  var MD_COLOR_KEYS = ['strong', 'em', 'del', 'heading', 'link', 'quote', 'quoteBorder', 'check', 'inlineCode', 'code', 'codeBg', 'tableHead', 'hr'];
  function applyMdColors(c) {
    var root = document.documentElement;
    MD_COLOR_KEYS.forEach(function (k) {
      if (c && c[k]) root.style.setProperty('--md-' + k + '-color', c[k]);
      else root.style.removeProperty('--md-' + k + '-color');
    });
  }
  // 页面加载即应用（不打开弹窗也要生效）
  applyMdColors(loadMdColors());

  function openMdColors() {
    if (document.querySelector('.md-colors-overlay')) return;
    var overlay = mkEl('div', 'md-modal-overlay md-colors-overlay');
    overlay.style.zIndex = '21000';
    var modal = mkEl('div', 'md-modal md-colors-modal');
    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '<i class="ic ic-palette"></i> 渲染颜色自定义'));
    var closeBtn = mkBtn('<i class="ic ic-close"></i>', '关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeNoSave);
    head.appendChild(closeBtn);
    modal.appendChild(head);

    var body = mkEl('div', 'md-modal-body');
    body.appendChild(mkEl('p', 'md-hint', 'Markdown 渲染全部元素的颜色都可自定义，只保存在本浏览器；改色实时预览。'));

    // 全部可调项（probe 用于没自定义过时读主题当前计算色作为初始值）
    var groups = [
      { name: '文字强调', items: [
        { key: 'strong', label: '加粗 **粗**', probe: '<strong>x</strong>' },
        { key: 'em', label: '斜体 *斜*', probe: '<em>x</em>' },
        { key: 'del', label: '删除线 ~~删~~', probe: '<del>x</del>' },
      ]},
      { name: '结构与链接', items: [
        { key: 'heading', label: '标题 #', probe: '<span class="md-h">x</span>' },
        { key: 'link', label: '链接 [文字](url)', probe: '<a href="#">x</a>' },
        { key: 'quote', label: '引用文字 >', probe: '<blockquote class="md-quote">x</blockquote>' },
        { key: 'quoteBorder', label: '引用边框', probe: '<blockquote class="md-quote">x</blockquote>', cssProp: 'borderLeftColor' },
        { key: 'check', label: '任务勾选 ☑', probe: '<span class="md-check">x</span>' },
      ]},
      { name: '代码', items: [
        { key: 'inlineCode', label: '行内代码 `x`', probe: '<code class="md-code">x</code>' },
        { key: 'code', label: '代码块文字', probe: '<pre class="md-pre"><code>x</code></pre>' },
        { key: 'codeBg', label: '代码块背景', probe: '<pre class="md-pre"><code>x</code></pre>', cssProp: 'backgroundColor' },
      ]},
      { name: '其他', items: [
        { key: 'tableHead', label: '表格表头', probe: '<table class="md-table"><th>x</th></table>' },
        { key: 'hr', label: '分割线 ---', probe: '<span class="md-body">x</span>', cssProp: null, special: 'hr' },
      ]},
    ];
    var savedColors = loadMdColors();
    var rows = [];
    groups.forEach(function (g) {
      var gTitle = mkEl('div', 'md-color-group-title', g.name);
      body.appendChild(gTitle);
      g.items.forEach(function (r) { r.group = g.name; rows.push(r); });
    });

    var inputs = {};
    rows.forEach(function (r) {
      var wrap = mkEl('div', null, '');
      wrap.style.cssText = 'display:flex;align-items:center;gap:12px;margin:6px 0;';
      var lab = mkEl('label', null, r.label);
      lab.style.cssText = 'flex:1;font-size:12.5px;color:#c8c8e0;';
      var inp = document.createElement('input');
      inp.type = 'color';
      inp.className = 'md-color-input';
      if (savedColors[r.key]) inp.value = savedColors[r.key];
      else inp.value = computedColor(r);
      inp.addEventListener('input', renderPreview);
      inputs[r.key] = inp;
      wrap.appendChild(lab); wrap.appendChild(inp);
      body.appendChild(wrap);
    });
    function computedColor(r) {
      var probe = mkEl('span', 'md-body');
      probe.innerHTML = r.probe;
      probe.style.display = 'none';
      document.body.appendChild(probe);
      var el = probe.querySelector('th') || probe.firstChild;   // 表头 probe 的目标是内层 <th>
      // 特殊项：分割线读 CSS 变量兜底色
      var col;
      if (r.special === 'hr') col = getComputedStyle(document.documentElement).getPropertyValue('--border-color') || '#2a2a45';
      else col = getComputedStyle(el)[r.cssProp || 'color'];
      document.body.removeChild(probe);
      var m = String(col).match(/\d+/g);
      return (r.special === 'hr') ? rgbToHex(col) : (m ? '#' + m.slice(0, 3).map(function (n) { return ('0' + parseInt(n).toString(16)).slice(-2); }).join('') : '#ffffff');
    }
    function rgbToHex(v) {
      var t = String(v).trim();
      var m = t.match(/^#?([0-9a-fA-F]{6})$/);
      if (m) return '#' + m[1];
      var mm = t.match(/\d+/g);
      return mm ? '#' + mm.slice(0, 3).map(function (n) { return ('0' + parseInt(n).toString(16)).slice(-2); }).join('') : '#2a2a45';
    }
    function inputsToColors() {
      var out = {};
      rows.forEach(function (r) { out[r.key] = inputs[r.key].value || ''; });
      return out;
    }

    // 实时预览（改色即时写 CSS 变量；关闭不保存则还原）
    var preview = mkEl('div', 'md-body md-color-preview');
    function renderPreview() {
      applyMdColors(inputsToColors());
      preview.innerHTML = window.PixelMD.render(
        '# 标题一\n## 标题二\n普通正文对照，**加粗强调**，*斜体*，~~删除~~，[链接](#)，行内`代码`\n- [x] 已完成任务\n- [ ] 未完成任务\n\n> 引用块：这是一段引用文字\n\n```\n代码块内容\nconst x = 1;\n```\n\n| 表头A | 表头B |\n|---|---|\n| 单元格 | 单元格 |\n\n---');
    }
    renderPreview();
    body.appendChild(preview);

    var btnWrap = mkEl('div', null, '');
    btnWrap.style.cssText = 'display:flex;gap:10px;margin-top:14px;';
    var saveBtn = mkBtn('<i class="ic ic-save"></i> 保存', '保存全部自定义颜色（仅本浏览器生效）');
    saveBtn.className = 'btn btn-primary btn-sm';
    saveBtn.style.flex = '1';
    saveBtn.addEventListener('click', function () {
      try { localStorage.setItem(MD_COLORS_KEY, JSON.stringify(inputsToColors())); } catch (e) {}
      applyMdColors(inputsToColors());
      showToast('🎨 渲染颜色已保存（本浏览器生效）', 'success');
      document.body.removeChild(overlay);
    });
    var resetBtn = mkBtn('<i class="ic ic-back"></i> 全部恢复默认', '清除全部自定义，回到主题默认色');
    resetBtn.className = 'btn btn-outline btn-sm';
    resetBtn.style.flex = '1';
    resetBtn.addEventListener('click', function () {
      try { localStorage.removeItem(MD_COLORS_KEY); } catch (e) {}
      applyMdColors(null);
      showToast('↩️ 已恢复主题默认色', 'success');
      document.body.removeChild(overlay);
    });
    btnWrap.appendChild(saveBtn); btnWrap.appendChild(resetBtn);
    body.appendChild(btnWrap);

    modal.appendChild(body);
    overlay.appendChild(modal);
    function closeNoSave() { applyMdColors(loadMdColors()); document.body.removeChild(overlay); }
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeNoSave(); });
    document.body.appendChild(overlay);
  }

  var btnMdColors = document.getElementById('btnMdColors');
  if (btnMdColors) btnMdColors.addEventListener('click', openMdColors);

  // ============== 阅读弹窗 ==============
  var modalEscHandler = null;
  // 媒体元素领养：弹窗复用卡片里已加载的 <audio>/<video>，避免重新加载
  var modalAdopt = null;
  var modalSourceCard = null;   // 当前详情弹窗的源卡片（关闭时 morph 缩回原位）

  function restoreAdoptedPlayers() {
    if (!modalAdopt) return;
    modalAdopt.pairs.forEach(function (pair) {
      if (pair.slot.parentNode) pair.slot.parentNode.replaceChild(pair.player, pair.slot);
    });
    modalAdopt = null;
  }

  function closeModal(quiet) {
    var ov = document.querySelector('.md-modal-overlay');
    if (!ov) { restoreAdoptedPlayers(); return; }
    // v128 穿模自然化：弹窗本体立即消失（pn-note 快照接管视觉），但暗幕保留 ~0.24s 渐退——
    // 快照飞回卡片期间背景渐亮，不再出现「无暗幕的卡片硬压在邻居上」的生硬穿模感
    var doClose = function () {
      restoreAdoptedPlayers();
      var modalEl = ov.querySelector('.md-modal');
      if (modalEl && modalEl.parentNode) modalEl.remove();
      if (ov.parentNode) {
        ov.classList.add('closing');
        setTimeout(function () { if (ov.parentNode) ov.remove(); }, 260);
      }
      if (modalEscHandler) {
        document.removeEventListener('keydown', modalEscHandler, true);
        modalEscHandler = null;
      }
    };
    // 非静默关闭：弹窗 morph 缩回源卡片（灵动岛原路径返回；root 切换由 morph 内部处理）；源卡片已不在 DOM 时退化为淡出
    if (!quiet && PNVT.supported()) {
      var modalEl2 = ov.querySelector('.md-modal');
      var srcCard = modalSourceCard;
      // 收回期间冻结卡片过渡（约 2px 的 hover 态切换被快照切换放大，影响极小，保留此冻结减小可见度）
      if (srcCard && srcCard.isConnected) {
        srcCard.classList.add('no-trans');
        setTimeout(function () { srcCard.classList.remove('no-trans'); }, 300);
      }
      PNVT.morph((modalEl2 && modalEl2.isConnected) ? modalEl2 : null, doClose, srcCard);
    } else {
      doClose();
    }
    modalSourceCard = null;
  }

  function openModal(id, card) {
    var note = notesById[id];
    if (!note) return;
    closeModal(true);   // 静默清理旧弹窗（正向 morph 由本次 VT 接管）
    modalSourceCard = card || null;

    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal');

    var head = mkEl('div', 'md-modal-head');
    var titleEl = mkEl('div', 'md-modal-title', note.title || '无标题');
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeModal);
    head.appendChild(titleEl);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');
    var contentDiv = mkEl('div', 'note-content md-body');
    contentDiv.innerHTML = window.PixelMD.render(note.content);
    body.appendChild(contentDiv);

    // 领养：同一份 MD 渲染出的 .md-player 顺序一致，直接节点搬运（保留加载/播放状态）
    modalAdopt = null;
    if (card) {
      var cardPlayers = card.querySelectorAll('.note-content .md-player');
      var modalPlayers = contentDiv.querySelectorAll('.md-player');
      if (cardPlayers.length && modalPlayers.length === cardPlayers.length) {
        var pairs = [];
        for (var i = 0; i < cardPlayers.length; i++) {
          var slot = document.createComment('mp-slot');
          cardPlayers[i].parentNode.insertBefore(slot, cardPlayers[i]);
          modalPlayers[i].parentNode.replaceChild(cardPlayers[i], modalPlayers[i]);
          pairs.push({ slot: slot, player: cardPlayers[i] });
        }
        modalAdopt = { pairs: pairs };
      }
    }

    var foot = mkEl('div', 'md-modal-foot');
    var editBtn = mkBtn('<i class="ic ic-pencil"></i> 编辑');
    editBtn.className = 'btn btn-primary btn-xs';
    var delBtn = mkBtn('<i class="ic ic-trash"></i> 删除');
    delBtn.className = 'btn btn-danger btn-xs';
    var pinInfo = mkEl('span', 'md-hint',
      '🕐 更新于 ' + (note.updated_at || '') + ' · Esc 关闭');
    foot.appendChild(editBtn);
    foot.appendChild(delBtn);
    foot.appendChild(pinInfo);

    editBtn.addEventListener('click', function () {
      openEditorForNote(id, modalSourceCard);   // 弹窗 → 全屏编辑器：morph 接管（openEditorForNote 内静默移除弹窗）
    });
    delBtn.addEventListener('click', function () { deleteNote(card); });

    modal.appendChild(head);
    modal.appendChild(body);
    modal.appendChild(foot);
    overlay.appendChild(modal);

    overlay.addEventListener('mousedown', function (e) {
      if (e.target === overlay) closeModal();
    });

    modalEscHandler = function (e) { if (e.key === 'Escape') closeModal(); };
    document.addEventListener('keydown', modalEscHandler, true);

    // 卡片 → 详情弹窗：View Transition 原位放大（无源卡片时直接弹出）
    // 注意：modal 作为 targetEl 传入，由 morph 的 cleanup 统一清命名——
    // 否则弹窗上的 pn-note 残留会让后续 morph（如弹窗→编辑器）因重复命名被 abort
    if (modalSourceCard && modalSourceCard.isConnected && PNVT.supported()) {
      PNVT.morph(modalSourceCard, function () {
        document.body.appendChild(overlay);
      }, modal);
    } else {
      document.body.appendChild(overlay);
    }
  }

  // ============== 置顶 / 换色 / 删除 ==============
  async function togglePin(card) {
    var id = parseInt(card.getAttribute('data-id'));
    var newPinned = card.classList.contains('pinned') ? 0 : 1;
    try {
      var r = await api('PUT', { id: id, pinned: newPinned });
      if (r.success) {
        showToast(newPinned ? '📌 已置顶' : '📌 已取消置顶', 'success');
        if (notesById[id]) notesById[id].pinned = newPinned;
        // 用最新数据重建卡片（pin 按钮文字、📌角标等都同步刷新）
        var fresh = createNoteCard(notesById[id]);
        if (newPinned) {
          // 置顶：新卡片放第一个位置
          var firstCard = notesGrid.querySelector('.note-card');
          if (firstCard && firstCard !== card) {
            notesGrid.insertBefore(fresh, firstCard);
            card.remove();
          } else {
            card.parentNode.replaceChild(fresh, card);
          }
        } else {
          // 取消置顶：原位替换，位置不动
          card.parentNode.replaceChild(fresh, card);
        }
        checkClamp(fresh);
        saveReorderSilent();
      }
    } catch (e) { showToast('❌ 操作失败', 'error'); }
  }

  // 静默保存排序（不弹出toast）
  function saveReorderSilent() {
    var cards = notesGrid.querySelectorAll('.note-card');
    var reorder = [];
    cards.forEach(function (card, i) {
      var id = parseInt(card.getAttribute('data-id'));
      reorder.push({ id: id, sort_order: i });
    });
    api('PUT', { reorder: reorder }).catch(function () {});
  }

  async function cycleColor(card) {
    var colors = ['yellow', 'pink', 'blue', 'green', 'purple', 'orange'];
    var newColor = colors[(colors.indexOf(card.getAttribute('data-color')) + 1) % colors.length];
    var id = parseInt(card.getAttribute('data-id'));
    try {
      var r = await api('PUT', { id: id, color: newColor });
      if (r.success) {
        if (notesById[id]) notesById[id].color = newColor;
        showToast('🎨 颜色已切换', 'success');
        // 原位替换卡片，保持位置、刷新全部内部状态
        var fresh = createNoteCard(notesById[id]);
        if (card.parentNode) {
          card.parentNode.replaceChild(fresh, card);
          checkClamp(fresh);
        }
      }
    } catch (e) { showToast('❌ 操作失败', 'error'); }
  }

  async function deleteNote(card) {
    if (!confirm('确定要删除这张便签吗？此操作不可撤销！')) return;
    var id = parseInt(card.getAttribute('data-id'));
    closeModal();
    try {
      var r = await api('DELETE', { id: id });
      if (r.success) {
        delete notesById[id];
        if (editingId === id) hideEditor();
        card.style.transition = 'all 0.2s';
        card.style.transform = 'scale(0.8)';
        card.style.opacity = '0';
        setTimeout(function () {
          card.remove();
          if (notesGrid.children.length === 0) {
            notesGrid.innerHTML = ''
              + '<div class="empty-state">'
              + '  <div class="icon">📜</div>'
              + '  <p>还没有便签<br>点击「＋ 新建便签」开始吧！</p>'
              + '</div>';
          }
        }, 200);
        showToast('🗑 已删除', 'success');
      }
    } catch (e) { showToast('❌ 删除失败', 'error'); }
  }

  function nowStr() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
      ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  // ============== 拖拽排序 (SortableJS) ==============
  var sortableInstance = null;
  var sortableFolderInstance = null;   // 文件夹排序（与便签排序分开：两组互不混拖）

  function initDragSort() {
    if (typeof Sortable === 'undefined') {
      // SortableJS CDN 没加载成功，500ms后重试
      setTimeout(initDragSort, 500);
      return;
    }
    if (sortableInstance) sortableInstance.destroy();
    if (sortableFolderInstance) sortableFolderInstance.destroy();

    sortableInstance = Sortable.create(notesGrid, {
      animation: 350,
      draggable: '.note-card',
      ghostClass: 'dragging',
      chosenClass: 'drag-chosen',
      dragClass: 'drag-ghost',
      filter: '.note-title, .note-actions, .read-more, a, button',
      preventOnFilter: false,
      // 手机端防误触：触屏需按住 250ms 才进入拖拽，滑动超 10px 自动取消还给页面滚动；鼠标不受影响
      delay: 250,
      delayOnTouchOnly: true,
      touchStartThreshold: 10,
      onEnd: function () {
        var cards = notesGrid.querySelectorAll('.note-card');
        var reorder = [];
        cards.forEach(function (card, i) {
          var id = parseInt(card.getAttribute('data-id'));
          reorder.push({ id: id, sort_order: i });
        });
        api('PUT', { reorder: reorder }).then(function () {
          showToast('🔄 排序已保存', 'success');
        }).catch(function () {
          showToast('❌ 排序保存失败', 'error');
        });
      }
    });

    // 文件夹互拖排序：同一网格但 draggable 只认 folder-card，与便签组天然隔离
    sortableFolderInstance = Sortable.create(notesGrid, {
      animation: 350,
      draggable: '.folder-card',
      ghostClass: 'dragging',
      chosenClass: 'drag-chosen',
      dragClass: 'drag-ghost',
      filter: '.folder-name, .folder-count, .folder-menu-btn, a, button',
      preventOnFilter: false,
      // 手机端防误触：与便签组同款触屏参数
      delay: 250,
      delayOnTouchOnly: true,
      touchStartThreshold: 10,
      onEnd: function () {
        var cards = notesGrid.querySelectorAll('.folder-card');
        var reorder = [];
        cards.forEach(function (card, i) {
          var id = parseInt(card.getAttribute('data-folder-id'));
          reorder.push({ id: id, sort_order: i });
        });
        folderApi('PUT', { reorder: reorder }).then(function () {
          showToast('📁 文件夹顺序已保存', 'success');
          loadFolders().then(refreshView);   // 计数不变，但顺序刷新要重渲染
        }).catch(function () {
          showToast('❌ 文件夹排序保存失败', 'error');
        });
      }
    });
  }



  // ============== 初始化 ==============
  document.getElementById('btnNewFolder').addEventListener('click', function () {
    promptNewFolder(currentFolderId);
  });
  document.getElementById('btnAiOrganize').addEventListener('click', function () {
    openClassifyDialog();
  });

  // 恢复地址栏文件夹视图（页可直接分享/刷新回同一目录）
  (function () {
    var m = location.hash.match(/^#folder=(\d+)$/);
    if (m) currentFolderId = parseInt(m[1], 10);
  })();
  // 便签↔图床联动初始化（配置由 index.php 的 JSON 数据块 imgBridgeCfg 注入：tuchangBase / policyHtml / policyVer；
  // CSP script-src 'self' 禁内联可执行脚本，故不能用 window 变量，这里解析数据块）
  var imgCfg = {};
  try {
    var imgCfgEl = document.getElementById('imgBridgeCfg');
    if (imgCfgEl) imgCfg = JSON.parse(imgCfgEl.textContent || '{}') || {};
  } catch (e) { imgCfg = {}; } // 配置块异常时联动静默关闭，不影响便签本体
  if (typeof ImgBridge !== 'undefined' && imgCfg.tuchangBase) {
    ImgBridge.init({
      tuchangBase: imgCfg.tuchangBase,
      notesApi: 'api/notes.php',
      policyHtml: imgCfg.policyHtml,
      policyVer: imgCfg.policyVer,
      toast: showToast
    });
  }
  loadFolders().then(function () { loadNotes(); checkPendingClassify(); });
  PixelSelection.init({
    notesGrid: notesGrid,
    api: api,
    folderApi: folderApi,
    showToast: showToast,
    getCurrentFolderId: function () { return currentFolderId; },
    getNoteById: function (id) { return notesById[id]; },
    refreshAll: async function () { await loadFolders(); refreshView(); },
    isUiLocked: function () { return !!(document.querySelector('.md-modal-overlay')); },
    openMoveDialog: openMoveDialog   // 选择条「移动到…」入口（手机长按选中后可用）
  });
  PixelSelection.syncUI();

})();