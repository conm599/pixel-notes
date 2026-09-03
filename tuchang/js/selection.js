/**
 * 选择模式模块（Windows 桌面式多选）—— 自便签 selection.js 移植，dashboard.js 通过 PixelSelection.init(ctx) 注入依赖
 *
 * 行为对齐 Windows 桌面：
 * - 长按 500ms 卡片：选中并进入选择模式（触屏 700ms，与拖拽入夹不冲突：按住不动=选择、拖动=拖拽）
 * - 选择模式中点击卡片：加选 / 减选；点击空白：清空选择
 * - 空白处按住拖动：半透明框选，实时命中高亮；Ctrl+拖 = 在已有选择上追加
 * - Ctrl+A 全选 / Ctrl+X 剪切 / Ctrl+C 复制 / Ctrl+V 粘贴 / Esc 退出
 * - 剪贴板独立于选择：剪切粘贴一次后清空；复制可反复粘贴；取消选择不清剪贴板
 * - 图床语义：剪切+粘贴 = 移动到当前文件夹；复制+粘贴 = 创建图片副本（物理复制文件）
 */
(function () {
  'use strict';

  var SEL_MS = 500;
  var SEL_MS_TOUCH = 700;
  var DRAG_THRESHOLD = 4;
  var MOVE_CANCEL = 10;

  var ctx = null;
  var grid = null;
  var bar = null;

  var selMode = false;
  var selectedImgs = {};      // {imgId: true}
  var selectedFolders = {};   // {folderId: true}
  var clipboard = null;       // {type:'cut'|'copy', imgs:[id], folders:[id]}

  // 剪贴板持久化：图床进文件夹是整页跳转，内存会丢——sessionStorage 保存，跨页面存活
  var CLIP_KEY = 'tuchangClipboard';
  function saveClipboard() {
    try {
      if (clipboard) sessionStorage.setItem(CLIP_KEY, JSON.stringify(clipboard));
      else sessionStorage.removeItem(CLIP_KEY);
    } catch (e) { /* 隐私模式等场景忽略 */ }
  }
  function restoreClipboard() {
    try {
      var raw = sessionStorage.getItem(CLIP_KEY);
      if (raw) {
        var c = JSON.parse(raw);
        if (c && (c.type === 'cut' || c.type === 'copy') && ((c.imgs || []).length + (c.folders || []).length) > 0) clipboard = c;
      }
    } catch (e) { clipboard = null; }
  }

  function selCount() { return Object.keys(selectedImgs).length + Object.keys(selectedFolders).length; }

  function ensureBar() {
    if (bar) return;
    bar = document.createElement('div');
    bar.id = 'selBar';
    bar.className = 'sel-bar';
    bar.style.display = 'none';
    bar.innerHTML = '<span class="sel-count"></span>'
      + '<button type="button" class="sel-btn sel-cut" title="Ctrl+X">✂️ 剪切</button>'
      + '<button type="button" class="sel-btn sel-copy" title="Ctrl+C">📋 复制</button>'
      + '<button type="button" class="sel-btn sel-paste" title="Ctrl+V">📥 粘贴到当前文件夹</button>'
      + '<button type="button" class="sel-btn sel-all" title="Ctrl+A">☑️ 全选</button>'
      + '<button type="button" class="sel-btn sel-del">🗑 删除</button>'
      + '<button type="button" class="sel-btn sel-exit" title="Esc">✕ 取消选择</button>'
      + '<span class="sel-clip-info"></span>'
      + '<button type="button" class="sel-btn sel-clip-clear">🚫 清空剪贴板</button>';
    document.body.appendChild(bar);
    bar.querySelector('.sel-cut').addEventListener('click', function () { selCut(); });
    bar.querySelector('.sel-copy').addEventListener('click', function () { selCopy(); });
    bar.querySelector('.sel-paste').addEventListener('click', function () { selPaste(); });
    bar.querySelector('.sel-all').addEventListener('click', function () { selAll(); });
    bar.querySelector('.sel-del').addEventListener('click', function () { selDelete(); });
    bar.querySelector('.sel-exit').addEventListener('click', function () { exitSelection(); });
    bar.querySelector('.sel-clip-clear').addEventListener('click', function () {
      clipboard = null;
      saveClipboard();
      ctx.showToast('🚫 剪贴板已清空');
      updateSelUI();
    });
  }

  function updateSelUI() {
    if (!grid) return;
    grid.querySelectorAll('.card').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-id'));
      c.classList.toggle('sel', !!selectedImgs[id]);
    });
    grid.querySelectorAll('.folder-card').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-fid'));
      c.classList.toggle('sel', !!selectedFolders[id]);
    });

    ensureBar();
    var n = selCount();
    var hasClip = !!clipboard;
    var full = selMode && n > 0;

    document.body.classList.toggle('sel-active', full || hasClip);   // 隐藏原 checkbox 批量栏，两套多选不同时出现
    bar.classList.toggle('mini', !full && hasClip);
    bar.querySelector('.sel-count').style.display = full ? '' : 'none';
    ['sel-cut', 'sel-copy', 'sel-all', 'sel-del', 'sel-exit'].forEach(function (cls) {
      bar.querySelector('.' + cls).style.display = full ? '' : 'none';
    });
    bar.querySelector('.sel-paste').style.display = hasClip ? '' : 'none';
    bar.querySelector('.sel-clip-info').style.display = hasClip ? '' : 'none';
    bar.querySelector('.sel-clip-clear').style.display = (!full && hasClip) ? '' : 'none';

    if (full) bar.querySelector('.sel-count').textContent = '已选中 ' + n + ' 项';
    if (hasClip) {
      var typ = clipboard.type === 'cut' ? '剪切' : '复制';
      var cnt = clipboard.imgs.length + clipboard.folders.length;
      bar.querySelector('.sel-clip-info').textContent = '剪贴板：' + cnt + ' 项（' + typ + '）';
    }
    bar.style.display = (full || hasClip) ? 'flex' : 'none';
  }

  function toggleSelect(kind, id) {
    var map = kind === 'img' ? selectedImgs : selectedFolders;
    if (map[id]) { delete map[id]; } else { map[id] = true; }
    selMode = selCount() > 0;
    updateSelUI();
  }

  function exitSelection() {
    selMode = false;
    selectedImgs = {};
    selectedFolders = {};
    updateSelUI();
  }

  // ===== 剪切 / 复制 / 粘贴 / 删除 / 全选 =====
  function selCut() {
    if (selCount() === 0) return;
    clipboard = {
      type: 'cut',
      imgs: Object.keys(selectedImgs).map(Number),
      folders: Object.keys(selectedFolders).map(Number)
    };
    saveClipboard();
    ctx.showToast('✂️ 已剪切 ' + selCount() + ' 项，进入目标文件夹后按 Ctrl+V 粘贴');
    exitSelection();
  }

  function selCopy() {
    if (Object.keys(selectedFolders).length > 0) { ctx.showToast('⚠️ 文件夹暂不支持复制，仅图片可复制'); return; }
    var n = Object.keys(selectedImgs).length;
    if (n === 0) return;
    clipboard = { type: 'copy', imgs: Object.keys(selectedImgs).map(Number), folders: [] };
    saveClipboard();
    ctx.showToast('📋 已复制 ' + n + ' 张图片，可到任意文件夹反复粘贴（Ctrl+V，粘贴时创建副本）');
    exitSelection();
  }

  async function selPaste() {
    if (!clipboard) { ctx.showToast('⚠️ 剪贴板是空的'); return; }
    var target = ctx.getCurrentFolderId();
    if (target === null) { ctx.showToast('⚠️ 当前是「全部图片」视图，请先进入具体文件夹再粘贴'); return; }
    var isCopy = clipboard.type === 'copy';
    var done = 0, failed = 0;

    if (isCopy) {
      var r = await ctx.api('copybatch', { ids: JSON.stringify(clipboard.imgs), folder_id: target });
      if (r.ok) done = r.done || 0;
      failed = clipboard.imgs.length - done;
    } else {
      for (var f = 0; f < clipboard.folders.length; f++) {
        try {
          var rf = await ctx.folderApi('folder_move', { id: clipboard.folders[f], parent_id: target === 0 ? '' : target });
          if (rf.ok) done++; else failed++;
        } catch (e) { failed++; }
      }
      for (var j = 0; j < clipboard.imgs.length; j++) {
        try {
          var rn = await ctx.api('setfolder', { id: clipboard.imgs[j], folder_id: target });
          if (rn.ok) done++; else failed++;
        } catch (e) { failed++; }
      }
    }
    var typ = isCopy ? '复制' : '移动';
    ctx.showToast(done + ' 项' + typ + '完成' + (failed ? '，' + failed + ' 项失败' : ''));
    if (!isCopy) { clipboard = null; saveClipboard(); }   // Windows：剪切粘贴后清空，复制粘贴保留
    exitSelection();
    ctx.refreshAll();
  }

  async function selDelete() {
    var n = Object.keys(selectedImgs).length;
    var fc = Object.keys(selectedFolders).length;
    if (n + fc === 0) return;
    if (!window.confirm('删除选中的 ' + n + ' 张图片' + (fc ? ' 和 ' + fc + ' 个文件夹（内容上移一级）' : '') + '？')) return;
    var done = 0, failed = 0;
    var imgIds = Object.keys(selectedImgs).map(Number);
    if (imgIds.length > 0) {
      try {
        var r = await ctx.delbatch(imgIds);
        if (r.ok) done = r.deleted || 0; else failed += imgIds.length;
      } catch (e) { failed += imgIds.length; }
    }
    for (var j = 0; j < fc; j++) {
      try {
        var rf = await ctx.folderApi('folder_delete', { id: Object.keys(selectedFolders)[j] });
        if (rf.ok) done++; else failed++;
      } catch (e) { failed++; }
    }
    ctx.showToast('🗑 删除完成 ' + done + ' 项' + (failed ? '（' + failed + ' 失败）' : ''));
    exitSelection();
    ctx.refreshAll();
  }

  function selAll() {
    if (!grid) return;
    grid.querySelectorAll('.card').forEach(function (c) {
      selectedImgs[parseInt(c.getAttribute('data-id'))] = true;
    });
    grid.querySelectorAll('.folder-card.fdrop').forEach(function (c) {
      selectedFolders[parseInt(c.getAttribute('data-fid'))] = true;
    });
    selMode = selCount() > 0;
    updateSelUI();
  }

  // ===== 长按（图床无对调手势，直接进选择） =====
  var lpTimer = null;
  function clearLpTimer() { if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } }
  var suppressClickUntil = 0;
  function armClickSuppression() { suppressClickUntil = Date.now() + 400; }
  function isClickSuppressed() { return Date.now() < suppressClickUntil; }

  function bindLongPress() {
    grid.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return;
      var ic = e.target.closest ? e.target.closest('.card') : null;
      var fc = e.target.closest ? e.target.closest('.folder-card') : null;
      var id = null, kind = null;
      if (ic) { id = parseInt(ic.getAttribute('data-id')); kind = 'img'; }
      else if (fc) { id = parseInt(fc.getAttribute('data-fid')); kind = 'folder'; }
      if (id === null || isNaN(id)) return;
      if (e.target.closest('button, a, input, textarea, select, .f-act, .ops, .meta')) return;

      var sx = e.clientX, sy = e.clientY;
      clearLpTimer();
      var lpMs = e.pointerType === 'touch' ? SEL_MS_TOUCH : SEL_MS;
      lpTimer = setTimeout(function () {
        lpTimer = null;
        toggleSelect(kind, id);
        armClickSuppression();
      }, lpMs);
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

  // ===== 框选（Windows 桌面式：半透明框 + 实时命中，Ctrl 追加） =====
  function bindMarquee() {
    var marquee = null;
    document.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return;
      if (e.pointerType !== 'mouse') return;
      if (e.target.closest && e.target.closest(
        '.card, .folder-card, button, a, input, textarea, select, .modal-mask, .sel-bar, .dropzone, .topbar, .crumbs'
      )) return;
      if (ctx.isUiLocked()) return;

      var additive = e.ctrlKey || e.metaKey;
      var baseImgs = {}, baseFolders = {};
      if (additive) {
        Object.keys(selectedImgs).forEach(function (k) { baseImgs[k] = true; });
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
        marquee.style.left = x + 'px';
        marquee.style.top = y + 'px';
        marquee.style.width = w + 'px';
        marquee.style.height = h + 'px';
        rect = { x1: x, y1: y, x2: x + w, y2: y + h };
        applyMarqueeSelection(rect, baseImgs, baseFolders);
      }

      function applyMarqueeSelection(r, bI, bF) {
        selectedImgs = {}; selectedFolders = {};
        Object.keys(bI).forEach(function (k) { selectedImgs[k] = true; });
        Object.keys(bF).forEach(function (k) { selectedFolders[k] = true; });
        grid.querySelectorAll('.card').forEach(function (c) {
          var b = c.getBoundingClientRect();
          if (b.right > r.x1 && b.left < r.x2 && b.bottom > r.y1 && b.top < r.y2) {
            selectedImgs[parseInt(c.getAttribute('data-id'))] = true;
          }
        });
        grid.querySelectorAll('.folder-card').forEach(function (c) {
          var b = c.getBoundingClientRect();
          if (b.right > r.x1 && b.left < r.x2 && b.bottom > r.y1 && b.top < r.y2) {
            selectedFolders[parseInt(c.getAttribute('data-fid'))] = true;
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
        if (active) armClickSuppression();
      }
      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', finish);
      window.addEventListener('pointercancel', finish);
    });
  }

  function bindClickAndKeys() {
    grid.addEventListener('click', function (e) {
      if (!selMode) return;
      if (isClickSuppressed()) { e.preventDefault(); e.stopPropagation(); return; }
      var ic = e.target.closest ? e.target.closest('.card') : null;
      var fc = e.target.closest ? e.target.closest('.folder-card') : null;
      if (ic) {
        var id = parseInt(ic.getAttribute('data-id'));
        if (!isNaN(id)) { e.preventDefault(); e.stopPropagation(); toggleSelect('img', id); }
        return;
      }
      if (fc) {
        var fid = parseInt(fc.getAttribute('data-fid'));
        if (!isNaN(fid)) { e.preventDefault(); e.stopPropagation(); toggleSelect('folder', fid); }
        return;
      }
      exitSelection();
    }, true);

    document.addEventListener('click', function (e) {
      if (!selMode) return;
      if (isClickSuppressed()) { e.stopPropagation(); return; }
      if (e.target.closest && e.target.closest(
        '.card, .folder-card, button, a, input, textarea, select, .modal-mask, .sel-bar, .dropzone'
      )) return;
      exitSelection();
    }, true);

    document.addEventListener('keydown', function (e) {
      var inInput = e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable);
      if (e.key === 'Escape' && selMode) { exitSelection(); return; }
      if (inInput) return;
      if (!(e.ctrlKey || e.metaKey)) return;
      var k = e.key.toLowerCase();
      if (k === 'a' && !ctx.isUiLocked()) { e.preventDefault(); selAll(); }
      else if (k === 'x' && selMode) { e.preventDefault(); selCut(); }
      else if (k === 'c' && selMode) { e.preventDefault(); selCopy(); }
      else if (k === 'v' && clipboard) { e.preventDefault(); selPaste(); }
    }, true);
  }

  // ===== 对外接口 =====
  window.PixelSelection = {
    init: function (context) {
      ctx = context;
      grid = ctx.grid;
      restoreClipboard();
      bindLongPress();
      bindMarquee();
      bindClickAndKeys();
      updateSelUI();
    },
    isActive: function () { return selMode; },
    hasClipboard: function () { return !!clipboard; },
    syncUI: function () { updateSelUI(); },
    reset: function () { exitSelection(); }
  };
})();
