/**
 * 选择模式模块（Windows 桌面式多选）—— 从 app.js 拆出，app.js 通过 PixelSelection.init(ctx) 注入依赖
 *
 * 行为对齐 Windows 桌面：
 * - 长按 500ms 卡片：选中并进入选择模式（兼容旧手势：已选中 1 张便签时长按另一张 = 两者对调）
 * - 选择模式中点击卡片：加选 / 减选；点击空白：清空选择（对齐 Windows）
 * - 空白处按住拖动：粉色半透明框选，实时显示框与命中高亮；Ctrl+拖 = 在已有选择上追加
 * - Ctrl+A 全选 / Ctrl+X 剪切 / Ctrl+C 复制 / Ctrl+V 粘贴（无需处于选择模式，剪贴板有内容即可）/ Esc 退出
 * - 剪贴板独立于选择存在：复制后可反��粘贴；剪切粘贴一次后清空；取消选择不清剪贴板
 */
(function () {
  'use strict';

  var SEL_MS = 500;           // 长按进入选择模式阈值
  var DRAG_THRESHOLD = 4;     // 框选拖动生效阈值（像素）
  var MOVE_CANCEL = 10;       // 长按位移超过此值判定为拖动，取消长按

  var ctx = null;             // app.js 注入的依赖
  var notesGrid = null;
  var bar = null;

  var selMode = false;
  var selectedNotes = {};     // {noteId: true}
  var selectedFolders = {};   // {folderId: true}
  var clipboard = null;       // {type:'cut'|'copy', notes:[id], folders:[id]}

  // ===== 基础状态 =====
  function selCount() { return Object.keys(selectedNotes).length + Object.keys(selectedFolders).length; }

  function ensureBar() {
    if (bar) return;
    bar = document.createElement('div');
    bar.id = 'selBar';
    bar.className = 'sel-bar';
    bar.style.display = 'none';
    bar.innerHTML = '<span class="sel-count"></span>'
      + '<button type="button" class="sel-btn sel-cut" title="Ctrl+X">✂️ 剪切</button>'
      + '<button type="button" class="sel-btn sel-copy" title="Ctrl+C">📋 复制</button>'
      + '<button type="button" class="sel-btn sel-paste" title="Ctrl+V">📥 粘贴</button>'
      + '<button type="button" class="sel-btn sel-swap" title="恰好选中 2 张便签时可用">⇄ 对调</button>'
      + '<button type="button" class="sel-btn sel-all" title="Ctrl+A">☑️ 全选</button>'
      + '<button type="button" class="sel-btn sel-del">🗑 删除</button>'
      + '<button type="button" class="sel-btn sel-exit" title="Esc">✕ 取消选择</button>'
      + '<span class="sel-clip-info"></span>'
      + '<button type="button" class="sel-btn sel-clip-clear">🚫 清空剪贴板</button>';
    document.body.appendChild(bar);
    bar.querySelector('.sel-cut').addEventListener('click', function () { selCut(); });
    bar.querySelector('.sel-copy').addEventListener('click', function () { selCopy(); });
    bar.querySelector('.sel-paste').addEventListener('click', function () { selPaste(); });
    bar.querySelector('.sel-swap').addEventListener('click', function () { selSwap(); });
    bar.querySelector('.sel-all').addEventListener('click', function () { selAll(); });
    bar.querySelector('.sel-del').addEventListener('click', function () { selDelete(); });
    bar.querySelector('.sel-exit').addEventListener('click', function () { exitSelection(); });
    bar.querySelector('.sel-clip-clear').addEventListener('click', function () {
      clipboard = null;
      ctx.showToast('🚫 剪贴板已清空', 'success');
      updateSelUI();
    });
  }

  // 操作栏唯一入口：选中态显示完整栏；无选中但有剪贴板时显示迷你粘贴栏；都没有则隐藏
  function updateSelUI() {
    if (!notesGrid) return;
    notesGrid.querySelectorAll('.note-card').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-id'));
      c.classList.toggle('sel', !!selectedNotes[id]);
    });
    notesGrid.querySelectorAll('.folder-card').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-folder-id'));
      c.classList.toggle('sel', !!selectedFolders[id]);
    });

    ensureBar();
    var n = selCount();
    var hasClip = !!clipboard;
    var full = selMode && n > 0;

    bar.classList.toggle('mini', !full && hasClip);
    bar.querySelector('.sel-count').style.display = full ? '' : 'none';
    ['sel-cut', 'sel-copy', 'sel-swap', 'sel-all', 'sel-del', 'sel-exit'].forEach(function (cls) {
      bar.querySelector('.' + cls).style.display = full ? '' : 'none';
    });
    bar.querySelector('.sel-paste').style.display = hasClip ? '' : 'none';
    bar.querySelector('.sel-clip-info').style.display = hasClip ? '' : 'none';
    bar.querySelector('.sel-clip-clear').style.display = (!full && hasClip) ? '' : 'none';

    if (full) {
      bar.querySelector('.sel-count').textContent = '已选中 ' + n + ' 项';
      var twoNotes = Object.keys(selectedNotes).length === 2 && Object.keys(selectedFolders).length === 0;
      bar.querySelector('.sel-swap').style.display = twoNotes ? '' : 'none';
    }
    if (hasClip) {
      var typ = clipboard.type === 'cut' ? '剪切' : '复制';
      var cnt = clipboard.notes.length + clipboard.folders.length;
      bar.querySelector('.sel-clip-info').textContent = '剪贴板：' + cnt + ' 项（' + typ + '）';
    }
    bar.style.display = (full || hasClip) ? 'flex' : 'none';   // 修复：曾因从不隐藏导致"取消不掉"
  }

  function toggleSelect(kind, id) {
    var map = kind === 'note' ? selectedNotes : selectedFolders;
    if (map[id]) { delete map[id]; } else { map[id] = true; }
    selMode = selCount() > 0;
    updateSelUI();
  }

  // 退出选择模式：只清选择，剪贴板保留（Windows：取消选择不影响剪贴板）
  function exitSelection() {
    selMode = false;
    selectedNotes = {};
    selectedFolders = {};
    updateSelUI();
  }

  // ===== 剪切 / 复制 / 粘贴 / 删除 / 全选 / 对调 =====
  function selCut() {
    if (selCount() === 0) return;
    clipboard = {
      type: 'cut',
      notes: Object.keys(selectedNotes).map(Number),
      folders: Object.keys(selectedFolders).map(Number),
      // 便签快照：粘贴时不依赖当前视图的 notesById（源便签可能不在当前文件夹）
      snap: buildSnap(Object.keys(selectedNotes).map(Number))
    };
    ctx.showToast('✂️ 已剪切 ' + selCount() + ' 项，进入目标文件夹后按 Ctrl+V 粘贴', 'success');
    exitSelection();   // Windows：剪切后图标虚化；这里退出选择但剪贴板保留，粘贴栏常驻
  }

  function buildSnap(ids) {
    var snap = {};
    ids.forEach(function (id) {
      var n = ctx.getNoteById(id);
      if (n) snap[id] = { title: n.title, content: n.content, color: n.color };
    });
    return snap;
  }

  function selCopy() {
    var ncount = Object.keys(selectedNotes).length;
    if (Object.keys(selectedFolders).length > 0) { ctx.showToast('⚠️ 文件夹暂不支持复制，仅便签可复制', 'error'); return; }
    if (ncount === 0) return;
    var ids = Object.keys(selectedNotes).map(Number);
    clipboard = { type: 'copy', notes: ids, folders: [], snap: buildSnap(ids) };
    ctx.showToast('📋 已复制 ' + ncount + ' 条便签，可到任意文件夹反复粘贴（Ctrl+V）', 'success');
    exitSelection();   // 剪贴板保留，迷你粘贴栏提示常驻
  }

  async function selPaste() {
    if (!clipboard) { ctx.showToast('⚠️ 剪贴板是空的', 'error'); return; }
    var targetFid = ctx.getCurrentFolderId();
    var isCopy = clipboard.type === 'copy';
    ctx.showToast('正在粘贴到当前目录…', 'success');
    var done = 0, failed = 0;
    if (isCopy) {
      for (var i = 0; i < clipboard.notes.length; i++) {
        var id = clipboard.notes[i];
        // 优先用复制时的快照；源便签可能已被删除或不在当前视图
        var s = (clipboard.snap && clipboard.snap[id]) || ctx.getNoteById(id);
        if (!s) { failed++; continue; }
        try {
          var r = await ctx.api('POST', { title: s.title ? s.title + '（副本）' : '副本', content: s.content, color: s.color, folder_id: targetFid });
          if (r.success) done++; else failed++;
        } catch (e) { failed++; }
      }
    } else {
      for (var f = 0; f < clipboard.folders.length; f++) {
        try {
          var rf = await ctx.folderApi('PUT', { id: clipboard.folders[f], parent_id: targetFid });
          if (rf.success) done++; else failed++;
        } catch (e) { failed++; }
      }
      for (var j = 0; j < clipboard.notes.length; j++) {
        try {
          var rn = await ctx.api('PUT', { id: clipboard.notes[j], folder_id: targetFid });
          if (rn.success) done++; else failed++;
        } catch (e) { failed++; }
      }
    }
    var typ = isCopy ? '复制' : '移动';
    ctx.showToast(done + ' 项' + typ + '完成' + (failed ? '，' + failed + ' 项失败' : ''), failed ? 'error' : 'success');
    // Windows 行为：复制粘贴后剪贴板保留（可继续粘贴）；剪切粘贴后清空
    if (!isCopy) clipboard = null;
    exitSelection();
    await ctx.refreshAll();
  }

  function selSwap() {
    var ids = Object.keys(selectedNotes);
    if (ids.length !== 2) return;
    swapCardsById(ids[0], ids[1]);
  }

  async function selDelete() {
    var ncount = Object.keys(selectedNotes).length;
    var fcount = Object.keys(selectedFolders).length;
    if (ncount + fcount === 0) return;
    if (!window.confirm('删除选中的 ' + ncount + ' 条便签' + (fcount ? ' 和 ' + fcount + ' 个文件夹（内容上移）' : '') + '？')) return;
    var done = 0, failed = 0;
    var noteIds = Object.keys(selectedNotes).map(Number);
    for (var i = 0; i < noteIds.length; i++) {
      try { var r = await ctx.api('DELETE', { id: noteIds[i] }); if (r.success) done++; else failed++; } catch (e) { failed++; }
    }
    var folderIds = Object.keys(selectedFolders).map(Number);
    for (var j = 0; j < folderIds.length; j++) {
      try { var rf = await ctx.folderApi('DELETE', { id: folderIds[j] }); if (rf.success) done++; else failed++; } catch (e) { failed++; }
    }
    ctx.showToast('🗑 删除完成 ' + done + ' 项' + (failed ? '（' + failed + ' 失败）' : ''), failed ? 'error' : 'success');
    exitSelection();
    await ctx.refreshAll();
  }

  function selAll() {
    if (!notesGrid) return;
    notesGrid.querySelectorAll('.note-card').forEach(function (c) {
      selectedNotes[parseInt(c.getAttribute('data-id'))] = true;
    });
    notesGrid.querySelectorAll('.folder-card').forEach(function (c) {
      selectedFolders[parseInt(c.getAttribute('data-folder-id'))] = true;
    });
    selMode = selCount() > 0;
    updateSelUI();
  }

  // ===== 便签对调（v6.8 长按手势的底层实现，供选择模式复用） =====
  function saveCardOrder() {
    var cards = notesGrid.querySelectorAll('.note-card');
    var reorder = [];
    cards.forEach(function (card, i) {
      reorder.push({ id: parseInt(card.getAttribute('data-id')), sort_order: i });
    });
    ctx.api('PUT', { reorder: reorder }).then(function () {
      ctx.showToast('🔄 位置已对调并保存', 'success');
    }).catch(function () {
      ctx.showToast('❌ 位置保存失败，正在刷新', 'error');
      ctx.refreshAll();
    });
  }

  function swapCardsById(idA, idB) {
    var cardA = notesGrid.querySelector('.note-card[data-id="' + idA + '"]');
    var cardB = notesGrid.querySelector('.note-card[data-id="' + idB + '"]');
    if (!cardA || !cardB) return;
    var na = cardA._noteData, nb = cardB._noteData;
    if (na && nb && (!!na.pinned) !== (!!nb.pinned)) {
      ctx.showToast('⚠️ 置顶便签只能和置顶便签对调', 'error');
      return;
    }
    var placeholder = document.createElement('div');
    notesGrid.insertBefore(placeholder, cardA);
    notesGrid.insertBefore(cardA, cardB);
    notesGrid.insertBefore(cardB, placeholder);
    notesGrid.removeChild(placeholder);
    exitSelection();
    saveCardOrder();
  }

  // ===== 长按 =====
  var lpTimer = null;
  function clearLpTimer() { if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } }

  // 松手后抑制"合成 click 清空选择"的时间窗。
  // 必须用时间戳：全局清选择监听器注册更早、capture 阶段先执行，
  // 一次性 click 监听器拦不住它——只能让清选择逻辑自己检查时间窗。
  var suppressClickUntil = 0;
  function armClickSuppression() { suppressClickUntil = Date.now() + 400; }
  function isClickSuppressed() { return Date.now() < suppressClickUntil; }

  function bindLongPress() {
    notesGrid.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return;
      var nc = e.target.closest ? e.target.closest('.note-card') : null;
      var fc = e.target.closest ? e.target.closest('.folder-card') : null;
      var id = null, kind = null;
      if (nc) { id = parseInt(nc.getAttribute('data-id')); kind = 'note'; }
      else if (fc) { id = parseInt(fc.getAttribute('data-folder-id')); kind = 'folder'; }
      if (id === null || isNaN(id)) return;
      // 按钮/链接/可编辑区域不触发长按
      if (e.target.closest('button, a, input, textarea, .note-actions, .read-more, [contenteditable="true"], .folder-menu-btn')) return;

      var sx = e.clientX, sy = e.clientY;
      clearLpTimer();
      lpTimer = setTimeout(function () {
        lpTimer = null;
        // 兼容 v6.8 长按对调手势：已选中 1 张便签时，长按另一张便签立即对调
        var selNoteIds = Object.keys(selectedNotes);
        if (kind === 'note' && selNoteIds.length === 1 && String(id) !== selNoteIds[0]) {
          swapCardsById(Number(selNoteIds[0]), parseInt(id));
          armClickSuppression();
          return;
        }
        toggleSelect(kind, id);
        armClickSuppression();
      }, SEL_MS);
      var onMove = function (ev) {
        if (Math.abs(ev.clientX - sx) > MOVE_CANCEL || Math.abs(ev.clientY - sy) > MOVE_CANCEL) { clearLpTimer(); cleanup(); }
      };
      var onUp = function () { clearLpTimer(); cleanup(); };
      function cleanup() {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        window.removeEventListener('pointercancel', onUp);
      }
      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', onUp);
      window.addEventListener('pointercancel', onUp);
    });
  }

  // ===== 框选（Windows 桌面式：粉色半透明框 + 实时高亮，覆盖式选择；Ctrl 追加） =====
  // 起点为整个页面任意空白处：只要不是点在卡片/按钮/输入框/弹窗上，按住拖动即可框选
  function bindMarquee() {
    var marquee = null;

    document.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return;
      if (e.pointerType !== 'mouse') return;                 // 触屏用长按
      // 点在卡片/交互元素上不框选（那是点击/长按/按钮的事）
      if (e.target.closest && e.target.closest(
        '.note-card, .folder-card, button, a, input, textarea, select, [contenteditable="true"], .md-modal-overlay, .sel-bar, .folder-menu-btn'
      )) return;
      if (ctx.isUiLocked && ctx.isUiLocked()) return;

      var additive = e.ctrlKey || e.metaKey;                 // Ctrl+拖 = 在已有选择上追加
      var baseNotes = {}, baseFolders = {};
      if (additive) {
        Object.keys(selectedNotes).forEach(function (k) { baseNotes[k] = true; });
        Object.keys(selectedFolders).forEach(function (k) { baseFolders[k] = true; });
      }

      var startX = e.clientX, startY = e.clientY;
      var active = false;
      var rect = null;

      function onMove(ev) {
        var x = Math.min(startX, ev.clientX), y = Math.min(startY, ev.clientY);
        var w = Math.abs(ev.clientX - startX), h = Math.abs(ev.clientY - startY);
        if (!active) {
          if (w < DRAG_THRESHOLD && h < DRAG_THRESHOLD) return;
          active = true;
          marquee = document.createElement('div');
          marquee.className = 'marquee';
          document.body.appendChild(marquee);
          document.body.classList.add('marquee-selecting');
        }
        // 框跟随指针（fixed 定位直接用视口坐标）
        marquee.style.left = x + 'px';
        marquee.style.top = y + 'px';
        marquee.style.width = w + 'px';
        marquee.style.height = h + 'px';
        rect = { x1: x, y1: y, x2: x + w, y2: y + h };
        applyMarqueeSelection(rect, baseNotes, baseFolders);
      }

      function applyMarqueeSelection(r, bN, bF) {
        selectedNotes = {}; selectedFolders = {};
        Object.keys(bN).forEach(function (k) { selectedNotes[k] = true; });
        Object.keys(bF).forEach(function (k) { selectedFolders[k] = true; });
        notesGrid.querySelectorAll('.note-card').forEach(function (c) {
          var b = c.getBoundingClientRect();
          if (b.right > r.x1 && b.left < r.x2 && b.bottom > r.y1 && b.top < r.y2) {
            selectedNotes[parseInt(c.getAttribute('data-id'))] = true;
          }
        });
        notesGrid.querySelectorAll('.folder-card').forEach(function (c) {
          var b = c.getBoundingClientRect();
          if (b.right > r.x1 && b.left < r.x2 && b.bottom > r.y1 && b.top < r.y2) {
            selectedFolders[parseInt(c.getAttribute('data-folder-id'))] = true;
          }
        });
        selMode = selCount() > 0;
        updateSelUI();
      }

      function finish() {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', finish);
        window.removeEventListener('pointercancel', finish);
        document.body.classList.remove('marquee-selecting');
        if (marquee) { marquee.remove(); marquee = null; }
        if (active) armClickSuppression();   // 防止松开后的合成 click 清空刚框选的项
      }
      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', finish);
      window.addEventListener('pointercancel', finish);
    });
  }

  function bindClickAndKeys() {
    // 选择模式中点击卡片 = 加选/减选；点击网格空白 = 清空选择（Windows 桌面行为）
    notesGrid.addEventListener('click', function (e) {
      if (!selMode) return;
      if (isClickSuppressed()) { e.preventDefault(); e.stopPropagation(); return; }   // 长按/框选松开后的合成 click
      var nc = e.target.closest ? e.target.closest('.note-card') : null;
      var fc = e.target.closest ? e.target.closest('.folder-card') : null;
      if (nc) {
        var id = parseInt(nc.getAttribute('data-id'));
        if (!isNaN(id)) { e.preventDefault(); e.stopPropagation(); toggleSelect('note', id); }
        return;
      }
      if (fc) {
        var fid = parseInt(fc.getAttribute('data-folder-id'));
        if (!isNaN(fid)) { e.preventDefault(); e.stopPropagation(); toggleSelect('folder', fid); }
        return;
      }
      // 点在网格空白：清空选择（剪贴板保留）
      exitSelection();
    }, true);

    // 全局：选择模式中点击页面任意空白（工具栏、侧边、页脚等）也清空选择；
    // 卡片、按钮、操作栏、弹窗内的点击不受影响（Windows 桌面语义）
    document.addEventListener('click', function (e) {
      if (!selMode) return;
      if (isClickSuppressed()) { e.stopPropagation(); return; }   // 合成 click 不清选择
      if (e.target.closest && e.target.closest(
        '.note-card, .folder-card, button, a, input, textarea, select, [contenteditable="true"], .md-modal-overlay, .sel-bar'
      )) return;
      exitSelection();
    }, true);

    // 快捷键：capture 阶段监听，避免被其他 handler 拦截或焦点问题
    document.addEventListener('keydown', function (e) {
      var inInput = e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable);
      if (e.key === 'Escape' && selMode) { exitSelection(); return; }
      if (inInput) return;
      if (!(e.ctrlKey || e.metaKey)) return;
      var k = e.key.toLowerCase();
      if (k === 'a' && !(ctx.isUiLocked && ctx.isUiLocked())) { e.preventDefault(); selAll(); }
      else if (k === 'x' && selMode) { e.preventDefault(); selCut(); }
      else if (k === 'c' && selMode) { e.preventDefault(); selCopy(); }
      else if (k === 'v' && clipboard) { e.preventDefault(); selPaste(); }
    }, true);
  }

  // ===== 对外接口 =====
  window.PixelSelection = {
    init: function (context) {
      ctx = context;
      notesGrid = ctx.notesGrid;
      bindLongPress();
      bindMarquee();
      bindClickAndKeys();
      updateSelUI();
    },
    isActive: function () { return selMode; },
    hasClipboard: function () { return !!clipboard; },
    // 视图重建后重新应用选中高亮与操作栏（renderNotes 尾部调用）
    syncUI: function () { updateSelUI(); },
    // 切换文件夹时清空选择（剪贴板保留）
    reset: function () { exitSelection(); }
  };
})();
