/**
 * Pixel Notes - 前端交互逻辑 v5
 * - 新建与编辑共用同一个大编辑面板（同等大小，体验一致）
 * - 长文截断 + 阅读弹窗
 */

(function () {
  'use strict';

  var API_BASE = 'api/notes.php';
  var notesGrid = document.getElementById('notesGrid');
  var toast = document.getElementById('toast');
  var toastTimer = null;
  var notesById = {};

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
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'include',
      cache: 'no-store'
    });
    if (resp.status === 401) {
      window.location.href = 'login.php';
      throw new Error('未登录');
    }
    var raw = await resp.text();
    var result;
    try { result = JSON.parse(raw); }
    catch (e) { throw new Error('服务器响应异常 (HTTP ' + resp.status + ')'); }
    return result;
  }

  // ============== 加载 ==============
  async function loadNotes() {
    try {
      notesGrid.innerHTML = '<div class="loading">加载中...</div>';
      var result = await api('GET');
      if (!result.success) {
        notesGrid.innerHTML = '<div class="empty-state"><div class="icon">💀</div><p>加载失败</p></div>';
        return;
      }
      renderNotes(result.notes);
    } catch (err) {
      if (String(err.message).indexOf('未登录') === -1) {
        notesGrid.innerHTML = '<div class="empty-state"><div class="icon">💀</div><p>网络错误</p></div>';
      }
    }
  }

  function renderNotes(notes) {
    if (!notes || notes.length === 0) {
      notesGrid.innerHTML = ''
        + '<div class="empty-state">'
        + '  <div class="icon">📜</div>'
        + '  <p>还没有便签<br>点击「＋ 新建便签」开始吧！</p>'
        + '</div>';
      return;
    }
    notesGrid.innerHTML = '';
    notes.forEach(function (note) {
      var n = {
        id: parseInt(note.id),
        title: note.title || '',
        content: note.content || '',
        color: note.color || 'yellow',
        pinned: parseInt(note.pinned) || 0,
        sort_order: parseInt(note.sort_order) || 0,
        updated_at: note.updated_at || '',
        share_token: note.share_token || '',
        share_until: parseInt(note.share_until) || 0,
        share_url: note.share_url || ''
      };
      notesById[n.id] = n;
      var card = createNoteCard(n);
      notesGrid.appendChild(card);
      checkClamp(card);
    });
    initDragSort();
  }

  // ============== 工具 ==============
  function mkEl(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = text;
    return el;
  }

  function mkBtn(label, title) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    if (title) b.title = title;
    return b;
  }

  function surround(ta, before, after) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var sel = v.slice(s, e);
    ta.value = v.slice(0, s) + before + sel + after + v.slice(e);
    ta.focus();
    ta.selectionStart = s + before.length;
    ta.selectionEnd = s + before.length + sel.length;
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
  }

  function insertBlock(ta) {
    var s = ta.selectionStart, v = ta.value;
    var before = (s > 0 && v.charAt(s - 1) !== '\n') ? '\n' : '';
    ta.value = v.slice(0, s) + before + '```\n\n```' + v.slice(ta.selectionEnd);
    ta.focus();
    var pos = s + before.length + 4;
    ta.selectionStart = ta.selectionEnd = pos;
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
      ['☑', 'task', '任务 - [ ] '],
      ['❝', 'quote', '引用 > '],
      ['🔗', 'link', '链接 [文字](网址)'],
      ['🖼', 'img', '图片 ![描述](网址)']
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
    nd.addEventListener('click', function (e) {
      if (swapArmedId !== null) return;   // 长按对调选中态：点击不打开
      if (e.target && e.target.closest && e.target.closest('a')) return;
      if (nd.classList.contains('clamped')) {
        openModal(note.id, card);       // 长文 → 弹窗阅读
      } else {
        openEditorForNote(note.id);     // 短文 → 大编辑器
      }
    });
    return nd;
  }

  function buildMeta(note, card) {
    var meta = mkEl('div', 'note-meta');
    meta.appendChild(mkEl('span', null, '🕐 ' + (note.updated_at || '')));
    var actions = mkEl('div', 'note-actions');

    var editBtn = mkBtn('✏️ 编辑', '编辑这篇便签');
    editBtn.addEventListener('click', function () { openEditorForNote(note.id); });

    // 从 share_token 构建 share_url（参考图床 view.php 的做法，不依赖 API 返回 share_url）
    var _shareUrl = note.share_url || (note.share_token && String(note.share_token).length === 36 ? location.origin + '/share.php?t=' + note.share_token : '');
    var shareBtn = mkBtn(_shareUrl ? '🌐 分享' : '🔗 分享', _shareUrl ? '管理公开分享' : '生成公开分享链接');
    if (_shareUrl) { shareBtn.classList.add('btn-shared'); card._shareUrl = _shareUrl; }
    shareBtn.addEventListener('click', function () { openShareDialog(note.id, card); });

    var pinBtn = mkBtn(note.pinned ? '📌 已顶' : '📌 置顶', '置顶/取消置顶');
    pinBtn.addEventListener('click', function () { togglePin(card); });

    var colorBtn = mkBtn('🎨', '切换颜色');
    colorBtn.addEventListener('click', function () { cycleColor(card); });

    var delBtn = mkBtn('🗑 删除', '删除便签');
    delBtn.addEventListener('click', function () { deleteNote(card); });

    actions.appendChild(editBtn);
    actions.appendChild(shareBtn);
    actions.appendChild(pinBtn);
    actions.appendChild(colorBtn);
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
        rm = mkBtn('📖 阅读全文', '点击查看完整内容');
        rm.className = 'read-more';
        rm.addEventListener('click', function () {
          if (swapArmedId !== null) return;   // 长按对调选中态：点击不打开
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
  var btnCancelNew = document.getElementById('btnCancelNew');
  var newColorPicker = document.getElementById('newColorPicker');
  var newTitle = document.getElementById('newTitle');
  var newContent = document.getElementById('newContent');
  var newPreview = document.getElementById('newPreview');
  var btnPreviewNew = document.getElementById('btnPreviewNew');
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
    newNoteForm.classList.remove('edit-mode');
    newNoteForm.style.display = 'none';
    newTitle.value = '';
    newContent.value = '';
    newPreview.style.display = 'none';
    newPreview.innerHTML = '';
    if (btnPreviewNew) btnPreviewNew.textContent = '👁 预览';
    if (editorMode) editorMode.textContent = '';
    btnSaveNew.textContent = '💾 保存';
    // 取消卡片高亮
    document.querySelectorAll('.note-card.editing-source').forEach(function (c) {
      c.classList.remove('editing-source');
    });
  }

  // 新建模式
  btnNewNote.addEventListener('click', function () {
    if (editingId !== null) { hideEditor(); return; }
    if (newNoteForm.style.display === 'none' || newNoteForm.style.display === '') {
      editingId = null;
      newNoteForm.classList.remove('edit-mode');
      if (editorMode) editorMode.textContent = '🆕 新建便签';
      btnSaveNew.textContent = '💾 保存';
      setColorPicker('yellow');
      newNoteForm.style.display = 'block';
      newNoteForm.scrollIntoView({ behavior: 'smooth' });
      newTitle.focus();
    } else {
      hideEditor();
    }
  });

  // 编辑模式：打开同一个大面板并填充原文
  function openEditorForNote(id) {
    var note = notesById[id];
    if (!note) return;
    closeModal();
    editingId = id;

    // 高亮正在编辑的卡片
    document.querySelectorAll('.note-card.editing-source').forEach(function (c) {
      c.classList.remove('editing-source');
    });
    var card = document.querySelector('.note-card[data-id="' + id + '"]');
    if (card) card.classList.add('editing-source');

    newTitle.value = note.title;
    newContent.value = note.content;
    setColorPicker(note.color);
    if (editorMode) editorMode.textContent = '✏️ 正在编辑：' + (note.title || '无标题');
    btnSaveNew.textContent = '💾 保存修改';
    newNoteForm.classList.add('edit-mode');
    newNoteForm.style.display = 'block';
    newNoteForm.scrollIntoView({ behavior: 'smooth' });
    setTimeout(function () { newContent.focus(); }, 250);
  }

  btnCancelNew.addEventListener('click', hideEditor);

  newColorPicker.addEventListener('click', function (e) {
    var dot = e.target.closest('.color-dot');
    if (!dot) return;
    setColorPicker(dot.getAttribute('data-color'));
  });

  if (newToolbar) buildToolbar(newToolbar, function () { return newContent; });

  if (btnPreviewNew) {
    btnPreviewNew.addEventListener('click', function () {
      if (newPreview.style.display === 'none') {
        newPreview.innerHTML = window.PixelMD.render(newContent.value || '*(空)*');
        newPreview.style.display = 'block';
        btnPreviewNew.textContent = '👁 隐藏预览';
      } else {
        newPreview.style.display = 'none';
        btnPreviewNew.textContent = '👁 预览';
      }
    });
  }

  // 保存（新建 POST / 编辑 PUT）
  btnSaveNew.addEventListener('click', async function () {
    var title = newTitle.value.trim();
    var content = newContent.value.trim();

    if (editingId !== null) {
      // === 编辑模式 ===
      if (!title && !content) {
        showToast('⚠️ 标题和内容不能都为空', 'error');
        return;
      }
      try {
        var r = await api('PUT', {
          id: editingId,
          title: title,
          content: content,
          color: selectedColor
        });
        if (r.success) {
          var n = notesById[editingId];
          if (n) {
            n.title = title;
            n.content = content;
            n.color = selectedColor;
            n.updated_at = nowStr();
          }
          hideEditor();
          showToast('💾 已保存', 'success');
          // 原位替换整张卡片：用最新数据重建，保持卡片在网格中的位置不变
          var oldCard = notesGrid.querySelector('.note-card[data-id="' + editingId + '"]');
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
      }
      return;
    }

    // === 新建模式 ===
    if (!title && !content) {
      showToast('⚠️ 标题和内容不能都为空', 'error');
      return;
    }
    try {
      var r2 = await api('POST', { title: title, content: content, color: selectedColor });
      if (r2.success) {
        hideEditor();
        showToast('✅ 便签已创建！', 'success');
        loadNotes();
      } else {
        showToast('❌ ' + (r2.message || '创建失败'), 'error');
      }
    } catch (e) {
      showToast('❌ 创建失败', 'error');
    }
  });

  newContent.addEventListener('keydown', function (e) {
    if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); btnSaveNew.click(); }
    if (e.key === 'Escape') { e.preventDefault(); hideEditor(); }
  });

  // ============== 标题快速保存（卡片上直接改） ==============
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
  function openShareDialog(id, card) {
    closeShareDialog();
    var overlay = mkEl('div', 'md-modal-overlay');
    var modal = mkEl('div', 'md-modal share-modal');

    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '🔗 分享便签'));
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
    var copyBtn = mkBtn('📋 复制链接');
    copyBtn.className = 'btn btn-primary btn-xs';

    statusArea.appendChild(statusBadge);
    statusArea.appendChild(statusLink);
    statusArea.appendChild(statusInfo);
    statusArea.appendChild(copyBtn);

    // 未分享提示
    var noShareHint = mkEl('div', 'share-no-share');
    noShareHint.textContent = '此便签尚未分享，选择有效期后点击下方按钮';

    var foot = mkEl('div', 'md-modal-foot');
    var genBtn = mkBtn('⚡ 生成链接');
    genBtn.className = 'btn btn-primary btn-xs';
    var cancelShareBtn = mkBtn('🚫 取消分享');
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
      // 同步更新卡片上的分享按钮
      if (card) {
        var sb = card.querySelector('button[title="管理公开分享"], button[title="生成公开分享链接"]');
        if (sb) { sb.textContent = '🌐 分享'; sb.title = '管理公开分享'; sb.classList.add('btn-shared'); }
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
      // 同步更新卡片上的分享按钮
      if (card) {
        var sb = card.querySelector('button[title="管理公开分享"], button[title="生成公开分享链接"]');
        if (sb) { sb.textContent = '🔗 分享'; sb.title = '生成公开分享链接'; sb.classList.remove('btn-shared'); }
      }
    }

    genBtn.addEventListener('click', async function () {
      var hours = parseInt(sel.querySelector('.share-opt.active').getAttribute('data-hours'), 10) || 0;
      genBtn.disabled = true;
      genBtn.textContent = '生成中...';
      try {
        var r = await api('PUT', { action: 'share', id: id, hours: hours });
        if (r.success && r.url) {
          showShared(r.url, r.until || 0);
          showToast('✅ 分享已创建，链接已复制', 'success');
          try { await navigator.clipboard.writeText(r.url); } catch (_) {}
          // 同步更新卡片数据，重新打开弹窗时能正确显示已分享状态
          if (card && card._noteData) { card._noteData.share_url = r.url; card._noteData.share_until = r.until || 0; if (r.token) card._noteData.share_token = r.token; }
          if (notesById[id]) { notesById[id].share_url = r.url; notesById[id].share_until = r.until || 0; if (r.token) notesById[id].share_token = r.token; }
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
        var r = await api('PUT', { action: 'share', id: id, hours: -1 });
        if (r.success) {
          showToast('🚫 已取消分享，链接即刻失效', 'success');
          showNotShared();
          if (card && card._noteData) { card._noteData.share_url = ''; card._noteData.share_until = 0; card._noteData.share_token = ''; }
          if (notesById[id]) { notesById[id].share_url = ''; notesById[id].share_until = 0; notesById[id].share_token = ''; }
        } else {
          showToast('❌ ' + (r.message || '操作失败'), 'error');
        }
      } catch (e) { showToast('❌ 网络错误', 'error'); }
    });

    // 从卡片数据读取当前分享状态（优先 share_url，回退到 share_token 构建）
    var note = card ? card._noteData : null;
    var _url = note && (note.share_url || (note.share_token && String(note.share_token).length === 36 ? location.origin + '/share.php?t=' + note.share_token : ''));
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
      body: JSON.stringify(body),
      credentials: 'include',
      cache: 'no-store'
    });
    if (resp.status === 401) {
      window.location.href = 'login.php';
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
      body: JSON.stringify(body),
      credentials: 'include',
      cache: 'no-store'
    });
    if (resp.status === 401) {
      window.location.href = 'login.php';
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
    box1.appendChild(mkEl('div', 'policy-title', '🔒 隐私政策'));
    ['· 你编辑的便签内容和你的指令会被发送到 AI 服务商（平台密钥模式走平台配置的上游；自有 Key 模式经代理直连你填写的服务商）完成处理。',
     '· 你自己填写的 API Key 默认只保存在你当前浏览器的本地存储中，服务器不保存、不记录；仅当你主动勾选「跨端同步」时才会保存到服务器。',
     '· 你的风格偏好、AI 设置同样遵循上述存储规则。',
     '· 我们不会把你的便签内容用于任何其他用途。'
    ].forEach(function (t) { box1.appendChild(mkEl('div', 'policy-line', t)); });

    var box2 = mkEl('div', 'policy-box');
    box2.appendChild(mkEl('div', 'policy-title', '📋 使用政策'));
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
    head.appendChild(mkEl('div', 'md-modal-title', '⚙️ AI 设置'));
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeAiSettings);
    head.appendChild(closeBtn);

    var body = mkEl('div', 'md-modal-body');

    // 模式选择
    var modeRow = mkEl('div', 'ai-set-row');
    var modeLbl = mkEl('div', 'ai-set-label', '使用方式');
    var modeBtns = mkEl('div', 'share-options');
    var bPlat = mkBtn('🔑 平台密钥');
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
    bodyChkLbl.appendChild(mkEl('span', null, '⚙️ 自定义请求 Body 参数（随每次 AI 请求额外发送一个自定义字段）'));
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
    dlLink.textContent = '⬇ 下载 Workers 透明代理脚本';
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
    syncChk.appendChild(mkEl('span', null, '☁️ 保存到我的账号（换设备也能用。注意：包括你自己的 API Key 在内的设置会存到服务器）'));
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
    var saveBtn = mkBtn('💾 保存设置');
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
        saveBtn.textContent = '💾 保存设置';
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

  function openAiDialog() {
    closeAiDialog();
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
    headLeft.appendChild(mkEl('div', 'md-modal-title', '🤖 AI 编辑便签'));
    var setBtn = mkBtn('⚙️ AI 设置');
    setBtn.className = 'btn btn-outline btn-xs ai-open-settings';
    headLeft.appendChild(setBtn);
    head.appendChild(headLeft);
    var closeBtn = mkBtn('✖ 关闭');
    closeBtn.className = 'md-modal-close';
    closeBtn.addEventListener('click', closeAiDialog);
    head.appendChild(closeBtn);
    setBtn.addEventListener('click', function () {
      openAiSettings(function () { renderUsage(); });
    });

    var body = mkEl('div', 'md-modal-body');

    // ---- 输入态 ----
    var hint = mkEl('div', 'ai-hint');
    hint.textContent = '描述你想让 AI 对编辑器里的便签做什么。AI 默认只做局部修改（链接、嵌入内容不会被动到），结果会先展示差异对比，确认采纳后才会覆盖编辑器。';

    var usageBar = mkEl('div', 'ai-usage');

    function renderUsage(u) {
      var p = loadAiPrefs();
      if (u) lastUsage = u;
      if (aiRemoteState && aiRemoteState.is_admin && p.mode !== 'own') {
        usageBar.textContent = '👑 管理员模式：直接使用平台上游，无需密钥、不限量';
        return;
      }
      if (p.mode === 'own' && p.ownProxy) {
        usageBar.textContent = '🛰️ 直连模式：请求由浏览器直接发往你的透明代理，不经过平台、不限量';
        return;
      }
      if (lastUsage) {
        var src = (aiRemoteState && aiRemoteState.assigned_key) ? '分配密钥' : '平台密钥';
        usageBar.textContent = '📊 ' + src + '今日已用 ' + lastUsage.used + ' / ' + (lastUsage.limit > 0 ? lastUsage.limit : '∞') + '（北京时间 8:00 重置）· 当前模式：' + (p.mode === 'own' ? '我自己的 Key' : '平台密钥');
      } else if (aiRemoteState && aiRemoteState.assigned_key) {
        var a = aiRemoteState.assigned_key;
        usageBar.textContent = '📊 分配密钥今日已用 ' + a.used + ' / ' + (a.daily_limit > 0 ? a.daily_limit : '∞') + '（北京时间 8:00 重置）';
      } else if (p.mode !== 'own' && p.platformKey) {
        usageBar.textContent = '⏳ 正在查询密钥用量…';
      } else {
        usageBar.textContent = '📊 尚无用量记录 · 当前模式：' + (p.mode === 'own' ? '我自己的 Key' : '平台密钥');
      }
    }
    var lastUsage = null;

    var ta = mkEl('textarea', 'ai-instruction');
    ta.placeholder = '例如：\n· 帮我润色这段文字并修正错别字\n· 把这篇笔记整理成待办清单\n· 翻译成英文\n· 内容是空的，帮我写一篇关于 XX 的便签';
    ta.setAttribute('maxlength', '2000');

    var status = mkEl('div', 'ai-status');
    status.style.display = 'none';

    // ---- 流式生成预览（protocol v5）：实时显示 AI 输出（剥协议标记），结束转入结果态 ----
    var streamBox = mkEl('div', 'ai-stream');
    streamBox.style.display = 'none';
    var streamPhase = mkEl('div', 'ai-stream-phase');
    var streamText = mkEl('div', 'ai-stream-text');
    streamBox.appendChild(streamPhase);
    streamBox.appendChild(streamText);
    var streamRaw = '';
    var streamRaf = 0;
    function stripAiMarkers(t) {
      return String(t || '').replace(/<<<(?:SEARCH|REPLACE|END|CLARIFY)>>>/gi, '');
    }
    function renderStream() {
      streamRaf = 0;
      var shown = streamRaw;
      if (shown.length > 12000) shown = '…（前面已省略）\n' + shown.slice(-12000);
      streamText.textContent = stripAiMarkers(shown);
      streamBox.scrollTop = streamBox.scrollHeight;
    }
    function showStream(phaseText) {
      streamRaw = '';
      streamText.textContent = '';
      streamPhase.textContent = phaseText || '';
      streamBox.style.display = '';
      streamBox.scrollTop = 0;
    }
    function onStreamPhase(t) {
      if (t) streamPhase.textContent = t;
    }
    function onStreamDelta(t) {
      streamRaw += t;
      if (!streamRaf) streamRaf = requestAnimationFrame(renderStream);
    }
    function hideStream() {
      streamBox.style.display = 'none';
      streamRaw = '';
    }

    // ---- 澄清提问态：AI 拿不准时逐题回答，可多轮 ----
    var clarifyWrap = mkEl('div', 'ai-clarify');
    clarifyWrap.style.display = 'none';

    // ---- 结果确认态 ----
    var aiResult = null;
    var resultWrap = mkEl('div', 'ai-result');
    resultWrap.style.display = 'none';
    var resultInfo = mkEl('div', 'ai-result-info');
    var tabs = mkEl('div', 'ai-tabs');
    var tabDiff = mkBtn('📑 差异');
    var tabRender = mkBtn('👁 渲染');
    var tabSrc = mkBtn('</> 源码');
    tabDiff.className = 'ai-tab'; tabRender.className = 'ai-tab'; tabSrc.className = 'ai-tab';
    tabs.appendChild(tabDiff);
    tabs.appendChild(tabRender);
    tabs.appendChild(tabSrc);
    var diffBox = mkEl('div', 'ai-diff');
    var renderBox = mkEl('div', 'note-content ai-render');
    var srcBox = mkEl('div', 'ai-src');
    resultWrap.appendChild(resultInfo);
    resultWrap.appendChild(tabs);
    resultWrap.appendChild(diffBox);
    resultWrap.appendChild(renderBox);
    resultWrap.appendChild(srcBox);

    var foot = mkEl('div', 'md-modal-foot');
    var runBtn = mkBtn('🤖 开始编辑');
    runBtn.className = 'btn btn-primary btn-xs';
    var acceptBtn = mkBtn('✅ 采纳覆盖');
    acceptBtn.className = 'btn btn-primary btn-xs';
    acceptBtn.style.display = 'none';
    var regenBtn = mkBtn('🔄 重新生成');
    regenBtn.className = 'btn btn-outline btn-xs';
    regenBtn.style.display = 'none';
    var cancelBtn = mkBtn('关闭');
    cancelBtn.className = 'btn btn-outline btn-xs';
    var footHint = mkEl('span', 'md-hint', 'AI 处理可能需要十几秒');

    function showTab(name) {
      tabDiff.classList.toggle('active', name === 'diff');
      tabRender.classList.toggle('active', name === 'render');
      tabSrc.classList.toggle('active', name === 'src');
      diffBox.style.display = name === 'diff' ? '' : 'none';
      renderBox.style.display = name === 'render' ? '' : 'none';
      srcBox.style.display = name === 'src' ? '' : 'none';
    }
    tabDiff.addEventListener('click', function () { showTab('diff'); });
    tabRender.addEventListener('click', function () { showTab('render'); });
    tabSrc.addEventListener('click', function () { showTab('src'); });

    // 行级 LCS diff
    function lineDiff(oldText, newText) {
      var A = oldText.split('\n'), B = newText.split('\n');
      var n = A.length, m = B.length;
      if (n * m > 1200000) return null;
      var dp = [];
      for (var i = 0; i <= n; i++) { dp.push(new Array(m + 1).fill(0)); }
      for (var i = n - 1; i >= 0; i--) {
        for (var j = m - 1; j >= 0; j--) {
          dp[i][j] = A[i] === B[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
      }
      var ops = [];
      var i = 0, j = 0;
      while (i < n && j < m) {
        if (A[i] === B[j]) { ops.push(['same', A[i]]); i++; j++; }
        else if (dp[i + 1][j] >= dp[i][j + 1]) { ops.push(['del', A[i]]); i++; }
        else { ops.push(['add', B[j]]); j++; }
      }
      while (i < n) { ops.push(['del', A[i]]); i++; }
      while (j < m) { ops.push(['add', B[j]]); j++; }
      return ops;
    }

    function buildDiff() {
      diffBox.innerHTML = '';
      var ops = lineDiff(aiResult.original, aiResult.content);
      if (!ops) {
        diffBox.appendChild(mkEl('div', 'diff-skip', '内容太长，无法生成差异视图，请切到源码查看'));
        return;
      }
      // 折叠连续未改动行（超过4行只显示首尾2行）
      var out = [];
      var run = [];
      function flushRun() {
        if (run.length > 4) {
          run.slice(0, 2).forEach(function (l) { out.push(['same', l]); });
          out.push(['skip', (run.length - 4) + ' 行未改动']);
          run.slice(-2).forEach(function (l) { out.push(['same', l]); });
        } else {
          run.forEach(function (l) { out.push(['same', l]); });
        }
        run = [];
      }
      ops.forEach(function (op) {
        if (op[0] === 'same') run.push(op[1]);
        else { flushRun(); out.push(op); }
      });
      flushRun();
      if (out.length === 0) out.push(['skip', '没有变化']);
      out.forEach(function (op) {
        if (op[0] === 'skip') {
          diffBox.appendChild(mkEl('div', 'diff-skip', '⋯ ' + op[1] + ' ⋯'));
        } else {
          var line = mkEl('div', 'diff-line ' + op[0]);
          line.textContent = (op[0] === 'add' ? '+ ' : op[0] === 'del' ? '- ' : '  ') + op[1];
          diffBox.appendChild(line);
        }
      });
    }

    function showResultMode() {
      hideStream();
      hint.style.display = 'none';
      ta.style.display = 'none';
      status.style.display = 'none';
      resultWrap.style.display = '';
      runBtn.style.display = 'none';
      acceptBtn.style.display = '';
      regenBtn.style.display = '';
      var info;
      if (aiResult.chunked) {
        info = '🧩 长文分段处理（共 ' + (aiResult.chunks || '?') + ' 段，逐段下发给 AI）· ';
      } else {
        info = '';
      }
      if (aiResult.mode === 'edits') {
        info += '📑 局部修改：应用了 ' + aiResult.applied + ' 处改动';
        if (aiResult.failed) info += '，另有 ' + aiResult.failed + ' 处位置未匹配被跳过';
      } else {
        info += '📝 全文重写：AI 返回了整篇内容，请仔细核对差异';
      }
      if (aiResult.attempts && aiResult.attempts > 1) {
        info += '（AI 首次输出有误，已自动纠错 ' + (aiResult.attempts - 1) + ' 次后成功）';
      }
      resultInfo.textContent = info + ' · 核对无误后点「✅ 采纳覆盖」';
      buildDiff();
      renderBox.innerHTML = window.PixelMD.render(aiResult.content || '*(空)*');
      srcBox.textContent = aiResult.content;
      showTab('diff');
    }

    function showInputMode() {
      hint.style.display = '';
      ta.style.display = '';
      ta.disabled = false;
      status.style.display = 'none';
      resultWrap.style.display = 'none';
      runBtn.style.display = '';
      acceptBtn.style.display = 'none';
      regenBtn.style.display = 'none';
    }

    // 澄清提问态：AI 拿不准时逐题展示输入框，回答后带历史继续（轮数不限）
    function showClarifyMode(questions, existingRounds) {
      hideClarify();
      hint.style.display = 'none';
      ta.style.display = 'none';
      ta.disabled = true;
      status.style.display = 'none';
      resultWrap.style.display = 'none';
      runBtn.style.display = 'none';
      acceptBtn.style.display = 'none';
      regenBtn.style.display = 'none';
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
      var extraLab = mkEl('label', 'ai-clarify-q', '💬 其他补充（可选）：除了上面的问题，还有什么想告诉 AI 的吗？');
      var extraInp = document.createElement('textarea');
      extraInp.className = 'ai-instruction ai-clarify-answer';
      extraInp.placeholder = '选填。写下任何补充、纠正或额外要求，AI 会一并参考…';
      extraInp.setAttribute('maxlength', '300');
      clarifyWrap.appendChild(extraLab);
      clarifyWrap.appendChild(extraInp);
      var bRow = mkEl('div', 'ai-clarify-btns');
      var submit = mkBtn('✅ 提交回答，继续生成');
      submit.className = 'btn btn-primary btn-xs';
      var abort = mkBtn('取消');
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
        return;
      }
      if (needPolicy()) {
        openPolicyDialog(function () { runBtn.click(); });
        return;
      }
      var prefs = loadAiPrefs();
      runBtn.disabled = true;
      runBtn.style.display = '';
      ta.disabled = true;
      ta.style.display = '';
      runBtn.textContent = 'AI 编辑中';
      var dots = 0;
      var dotTimer = setInterval(function () {
        dots = (dots + 1) % 4;
        runBtn.textContent = 'AI 编辑中' + new Array(dots + 2).join('.');
      }, 400);
      status.style.display = 'none';
      var ok = false;
      function clearUp() {
        clearInterval(dotTimer);
        hideStream();
        if (!ok && !document.querySelector('.policy-modal')) {
          runBtn.disabled = false;
          ta.disabled = false;
          runBtn.textContent = '🤖 开始编辑';
        }
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
            onPhase: onStreamPhase,
            onDelta: onStreamDelta
          });
          clearUp();
          hideStream();
          if (handleClarify(r)) return;
          if (r.success && typeof r.content === 'string') {
            aiResult = {
              original: newContent.value,
              content: r.content,
              mode: r.mode || 'full',
              applied: r.applied || 0,
              failed: r.failed || 0,
              chunked: !!r.chunked,
              chunks: r.chunks || 0,
              attempts: r.attempts || 1,
              instruction: instruction
            };
            ok = true;
            showResultMode();
          } else {
            status.textContent = '❌ ' + (r.message || 'AI 编辑失败');
            status.style.display = 'block';
            showToast('❌ ' + (r.message || 'AI 编辑失败'), 'error');
          }
        } catch (e) {
          clearUp();
          status.textContent = '❌ ' + String(e.message || '直连失败');
          status.style.display = 'block';
        }
        return;
      }
      try {
        showStream('🤖 正在生成…');
        var r = await aiApiStream({
          action: 'edit',
          title: newTitle.value,
          content: newContent.value,
          instruction: instruction,
          policyVersion: AI_POLICY_VERSION,
          clarifyRounds: clarifyRounds,
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
        }, { onDelta: onStreamDelta, onPhase: onStreamPhase });
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
            applied: r.applied || 0,
            failed: r.failed || 0,
            chunked: !!r.chunked,
            chunks: r.chunks || 0,
            attempts: r.attempts || 1,
            instruction: instruction
          };
          if (r.usage) renderUsage(r.usage);
          ok = true;
          showResultMode();
        } else {
          if (r.usage) renderUsage(r.usage);
          status.textContent = '❌ ' + (r.message || 'AI 编辑失败');
          status.style.display = 'block';
          showToast('❌ ' + (r.message || 'AI 编辑失败'), 'error');
        }
      } catch (e) {
        clearUp();
        var msg = String(e.message || '网络错误');
        status.textContent = '❌ ' + msg;
        status.style.display = 'block';
        if (msg.indexOf('未登录') === -1) showToast('❌ ' + msg, 'error');
      }
    }

    runBtn.addEventListener('click', function () { runAiFlow([]); });

    acceptBtn.addEventListener('click', function () {
      if (!aiResult) return;
      newContent.value = aiResult.content;
      if (newPreview.style.display !== 'none') {
        newPreview.innerHTML = window.PixelMD.render(newContent.value);
      }
      showToast('✅ 已采纳 AI 修改并写入编辑器（记得保存便签）', 'success');
      closeAiDialog();
      newContent.focus();
    });

    regenBtn.addEventListener('click', function () {
      if (aiResult && aiResult.instruction) ta.value = aiResult.instruction;
      showInputMode();
      ta.focus();
    });

    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        runBtn.click();
      }
    });

    cancelBtn.addEventListener('click', closeAiDialog);

    foot.appendChild(runBtn);
    foot.appendChild(acceptBtn);
    foot.appendChild(regenBtn);
    foot.appendChild(cancelBtn);
    foot.appendChild(footHint);

    modal.appendChild(head);
    body.appendChild(hint);
    body.appendChild(usageBar);
    body.appendChild(ta);
    body.appendChild(status);
    body.appendChild(streamBox);
    body.appendChild(clarifyWrap);
    body.appendChild(resultWrap);
    modal.appendChild(body);
    modal.appendChild(foot);
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
    head.appendChild(mkEl('div', 'md-modal-title', '🔑 修改密码'));
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
    var okBtn = mkBtn('💾 确认修改');
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
          body: JSON.stringify({ action: 'changepass', oldpass: oldInp.value, newpass: newInp.value })
        });
        if (resp.status === 401) { window.location.href = 'login.php'; return; }
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
      okBtn.textContent = '💾 确认修改';
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
    head.appendChild(mkEl('div', 'md-modal-title', '🗑 注销账号'));
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
    emailInfo.textContent = '📧 正在获取账号邮箱...';

    var codeRow = mkEl('div', 'code-row');
    var codeInput = mkEl('input', 'form-input');
    codeInput.type = 'text';
    codeInput.placeholder = '6 位验证码';
    codeInput.maxLength = 6;
    codeInput.setAttribute('inputmode', 'numeric');
    var sendBtn = mkBtn('📧 发送验证码');
    sendBtn.className = 'btn btn-outline btn-xs';
    codeRow.appendChild(codeInput);
    codeRow.appendChild(sendBtn);

    body.appendChild(warn);
    body.appendChild(emailInfo);
    body.appendChild(mkEl('div', 'ai-set-label', '邮箱验证码（发送到你的注册邮箱）'));
    body.appendChild(codeRow);
    body.appendChild(status);

    var foot = mkEl('div', 'md-modal-foot');
    var confirmBtn = mkBtn('🗑 确认注销（不可恢复）');
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
          body: JSON.stringify({ action: 'check' })
        });
        var r = await resp.json();
        if (r.logged_in && r.user && r.user.email) {
          userEmail = r.user.email;
          emailInfo.textContent = '📧 账号邮箱：' + maskEmail(userEmail) + '（验证码将发送到该邮箱）';
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
        body: JSON.stringify(data)
      });
      if (resp.status === 401) { window.location.href = 'login.php'; throw new Error('请先登录'); }
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
          confirmBtn.textContent = '🗑 确认注销（不可恢复）';
        }
      } catch (e) {
        status.textContent = '❌ ' + (e.message || '网络错误');
        status.style.display = 'block';
        confirmBtn.disabled = false;
        confirmBtn.textContent = '🗑 确认注销（不可恢复）';
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

  // ============== 阅读弹窗 ==============
  var modalEscHandler = null;
  // 媒体元素领养：弹窗复用卡片里已加载的 <audio>/<video>，避免重新加载
  var modalAdopt = null;

  function restoreAdoptedPlayers() {
    if (!modalAdopt) return;
    modalAdopt.pairs.forEach(function (pair) {
      if (pair.slot.parentNode) pair.slot.parentNode.replaceChild(pair.player, pair.slot);
    });
    modalAdopt = null;
  }

  function closeModal() {
    restoreAdoptedPlayers();
    var ov = document.querySelector('.md-modal-overlay');
    if (ov) ov.remove();
    if (modalEscHandler) {
      document.removeEventListener('keydown', modalEscHandler, true);
      modalEscHandler = null;
    }
  }

  function openModal(id, card) {
    var note = notesById[id];
    if (!note) return;
    closeModal();

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
    var editBtn = mkBtn('✏️ 编辑');
    editBtn.className = 'btn btn-primary btn-xs';
    var delBtn = mkBtn('🗑 删除');
    delBtn.className = 'btn btn-danger btn-xs';
    var pinInfo = mkEl('span', 'md-hint',
      '🕐 更新于 ' + (note.updated_at || '') + ' · Esc 关闭');
    foot.appendChild(editBtn);
    foot.appendChild(delBtn);
    foot.appendChild(pinInfo);

    editBtn.addEventListener('click', function () {
      closeModal();
      openEditorForNote(id);
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

    document.body.appendChild(overlay);
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

  function initDragSort() {
    if (typeof Sortable === 'undefined') {
      // SortableJS CDN 没加载成功，500ms后重试
      setTimeout(initDragSort, 500);
      return;
    }
    if (sortableInstance) sortableInstance.destroy();

    sortableInstance = Sortable.create(notesGrid, {
      animation: 350,
      draggable: '.note-card',
      ghostClass: 'dragging',
      chosenClass: 'drag-chosen',
      dragClass: 'drag-ghost',
      filter: '.note-title, .note-actions, .read-more, a, button',
      preventOnFilter: false,
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
  }

  // ============== 长按对调：长按便签 500ms 选中 → 长按另一便签 500ms → 两者位置对调 ==============
  var swapArmedId = null;
  var lpTimer = null;
  var LP_SELECT_MS = 500;   // 长按选中阈值（独立常量，便于单独调整）
  var LP_SWAP_MS = 500;     // 选中后再长按对调的阈值

  function clearLpTimer() {
    if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
  }
  function clearSwapArmed() {
    var prev = notesGrid.querySelector('.note-card.swap-armed');
    if (prev) prev.classList.remove('swap-armed');
    swapArmedId = null;
    clearLpTimer();
  }
  function setSwapArmed(id, card) {
    clearSwapArmed();
    swapArmedId = id;
    card.classList.add('swap-armed');
    showToast('✅ 已选中，长按另一个便签与它对调位置（点空白处或 Esc 取消）', 'success');
  }
  function saveCardOrder() {
    var cards = notesGrid.querySelectorAll('.note-card');
    var reorder = [];
    cards.forEach(function (card, i) {
      reorder.push({ id: parseInt(card.getAttribute('data-id')), sort_order: i });
    });
    api('PUT', { reorder: reorder }).then(function () {
      showToast('🔄 位置已对调并保存', 'success');
    }).catch(function () {
      showToast('❌ 位置保存失败，正在刷新', 'error');
      loadNotes();
    });
  }
  function swapCards(idA, idB) {
    var cardA = notesGrid.querySelector('.note-card[data-id="' + idA + '"]');
    var cardB = notesGrid.querySelector('.note-card[data-id="' + idB + '"]');
    if (!cardA || !cardB) { clearSwapArmed(); return; }
    var na = cardA._noteData, nb = cardB._noteData;
    if (na && nb && (!!na.pinned) !== (!!nb.pinned)) {
      showToast('⚠️ 置顶便签只能和置顶便签对调', 'error');
      clearSwapArmed();
      return;
    }
    // 任意两节点对调：占位节点法
    var placeholder = document.createElement('div');
    notesGrid.insertBefore(placeholder, cardA);
    notesGrid.insertBefore(cardA, cardB);
    notesGrid.insertBefore(cardB, placeholder);
    notesGrid.removeChild(placeholder);
    clearSwapArmed();
    saveCardOrder();
  }
  function bindLongPressSwap() {
    // 选中态下，点击任何非卡片区域取消
    document.addEventListener('pointerdown', function (e) {
      if (swapArmedId !== null && !(e.target.closest && e.target.closest('.note-card'))) {
        clearSwapArmed();
      }
    });
    // 触屏长按的系统菜单 / 桌面右键菜单抑制（卡片有自己的按钮，原生菜单无用）
    notesGrid.addEventListener('contextmenu', function (e) {
      if (e.target.closest && e.target.closest('.note-card')) e.preventDefault();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') clearSwapArmed();
    });
    // 事件委托绑一次，卡片重渲染不受影响
    notesGrid.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return;
      var card = e.target.closest ? e.target.closest('.note-card') : null;
      if (!card) return;
      // 按钮 / 可编辑区域上不触发长按（编辑、置顶等按钮照常工作）
      if (e.target.closest('button, a, input, textarea, .note-actions, .read-more, [contenteditable="true"]')) return;
      var id = parseInt(card.getAttribute('data-id'));
      if (isNaN(id)) return;
      var sx = e.clientX, sy = e.clientY;
      card.classList.add('lp-pressing');   // 按住期间禁文字选中
      clearLpTimer();
      lpTimer = setTimeout(function () {
        lpTimer = null;
        card.classList.remove('lp-pressing');
        if (swapArmedId === null) {
          setSwapArmed(id, card);
        } else if (swapArmedId === id) {
          clearSwapArmed();                // 再长按同一张 = 取消选中
          showToast('已取消选中', 'success');
        } else {
          swapCards(swapArmedId, id);
        }
      }, swapArmedId === null ? LP_SELECT_MS : LP_SWAP_MS);
      var onMove = function (ev) {
        if (Math.abs(ev.clientX - sx) > 10 || Math.abs(ev.clientY - sy) > 10) {
          clearLpTimer();                  // 移动超阈值视为拖拽意图，交给 Sortable
          card.classList.remove('lp-pressing');
          cleanup();
        }
      };
      var onUp = function () {
        clearLpTimer();
        card.classList.remove('lp-pressing');
        cleanup();
      };
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

  // ============== 初始化 ==============
  loadNotes();
  bindLongPressSwap();

})();