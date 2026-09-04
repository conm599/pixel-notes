/**
 * 图床 SPA 视图引擎（学便签 app.js 单页模式）
 * - PHP 一次性输出全部图片卡 + 全量文件夹树 JSON（#suiteData）
 * - 进/切文件夹零请求零跳转：客户端按 folder_id 过滤重渲染
 * - 剪贴板/选择/滚动状态全部在内存中存活（便签同体验）
 * - 上传成功插入当前视图；copybatch/setfolder 后重渲染
 */
(function () {
  'use strict';
  var D = null;          // {folders:[{id,parent_id,name,count,direct_count}], images:[{id,folder_id,...卡片HTML由PHP预渲染}]}
  var cards = {};        // imgId -> {el, folderId, name}
  var curFolder = null;  // null=全部, 0=未归类, N=夹
  var FOLDERS_KEY = 'tuchang_folders';

  function boot() {
    var raw = document.getElementById('suiteData');
    if (!raw) return;
    try { D = JSON.parse(raw.textContent); } catch (e) { return; }
    curFolder = D.curFolder;

    // 收集 PHP 预渲染的全部卡片（含当前视图外的，藏在文档片段里）
    var frag = document.createDocumentFragment();
    D.cards.forEach(function (html) {
      var t = document.createElement('template');
      t.innerHTML = html.trim();
      var el = t.content.firstChild;
      if (el) frag.appendChild(el);
    });
    // 索引
    frag.querySelectorAll('.card').forEach(function (el) {
      var id = parseInt(el.getAttribute('data-id'));
      var fid = parseInt(el.getAttribute('data-folder-id') || '0');
      cards[id] = { el: el, folderId: fid, name: el.getAttribute('data-name') || '' };
    });
    D.cards = null;   // 释放

    bindNav();
    render();
    window.__SPA = {
      render: render,
      getCur: function () { return curFolder; },
      // 上传/复制成功的卡片归属当前视图：插入
      addCard: function (el, folderId) {
        var id = parseInt(el.getAttribute('data-id'));
        cards[id] = { el: el, folderId: folderId, name: el.getAttribute('data-name') || '' };
        if (folderVisible(folderId)) {
          var grid = document.querySelector('.grid');
          if (grid) grid.insertBefore(el, grid.firstChild);
        }
        renderCounts();
      },
      // 移动后刷新归属（setfolder/folder_move/paste）
      moveCard: function (id, folderId) {
        if (cards[id]) cards[id].folderId = folderId;
        render();
      },
      removeCard: function (id) {
        if (cards[id]) { if (cards[id].el.parentNode) cards[id].el.parentNode.removeChild(cards[id].el); delete cards[id]; }
        renderCounts();
      },
      // 文件夹树变化（新建/改名/删除/移动夹）→ 重新拉树（轻量）后重渲染
      refreshFolders: refreshFolders
    };
  }

  function folderVisible(fid) {
    if (curFolder === null) return true;
    if (curFolder === 0) return fid === 0;
    return fid === curFolder;
  }

  // 当前视图可见卡片序列
  function visibleCards() {
    var out = [];
    Object.keys(cards).forEach(function (k) {
      if (folderVisible(cards[k].folderId)) out.push(cards[k].el);
    });
    out.sort(function (a, b) { return parseInt(b.getAttribute('data-id')) - parseInt(a.getAttribute('data-id')); });
    return out;
  }

  function childrenOf(fid) {
    return D.folders.filter(function (f) { return f.parent_id === (fid === null ? null : fid); });
  }

  // ===== 渲染：面包屑 + 文件夹栏 + 网格 =====
  function render() {
    renderCrumbs();
    renderFolderBar();
    renderGrid();
    renderCounts();
    if (window.PixelSelection) PixelSelection.syncUI();
  }

  function renderCrumbs() {
    var c = document.getElementById('folderCrumbs');
    if (!c) return;
    var html = '';
    if (curFolder === null) {
      html = '<span class="crumb on">🗂 全部图片</span>';
    } else if (curFolder === 0) {
      html = '<a class="crumb" href="javascript:void(0)" data-nav="all">🗂 全部图片</a><span class="crumb-sep">›</span><span class="crumb on">📥 未归类</span>';
    } else {
      var chain = [];
      var cur = curFolder, guard = 0;
      var byId = {};
      D.folders.forEach(function (f) { byId[f.id] = f; });
      while (cur && guard++ < 50) {
        if (!byId[cur]) break;
        chain.unshift(cur);
        cur = byId[cur].parent_id;
      }
      html = '<a class="crumb" href="javascript:void(0)" data-nav="all">🗂 全部图片</a>';
      chain.forEach(function (fid, i) {
        html += '<span class="crumb-sep">›</span>';
        if (fid === curFolder) html += '<span class="crumb on">📁 ' + escapeHtml(byId[fid].name) + '</span>';
        else html += '<a class="crumb" href="javascript:void(0)" data-nav="' + fid + '">📁 ' + escapeHtml(byId[fid].name) + '</a>';
      });
    }
    c.innerHTML = html;
  }

  function renderFolderBar() {
    var bar = document.getElementById('folderBar');
    if (!bar) return;
    var baseLevel = (curFolder !== null && curFolder !== 0) ? curFolder : 0;
    var html = '';
    if (curFolder !== null && curFolder !== 0) {
      var byId = {};
      D.folders.forEach(function (f) { byId[f.id] = f; });
      var p = byId[curFolder] ? byId[curFolder].parent_id : 0;
      html += '<div class="folder-card" data-nav="' + (p === null || p === 0 ? 0 : p) + '"><div class="f-icon">↩️</div><div class="f-name">上一级</div></div>';
    }
    if (curFolder === null) {
      html += '<div class="folder-card fdrop" data-fid="0" data-nav="0"><div class="f-icon">📥</div><div class="f-name">未归类</div><div class="f-count">0 张</div></div>';
    }
    childrenOf(baseLevel === 0 && curFolder === null ? 0 : baseLevel).forEach(function (f) {
      html += '<div class="folder-card fdrop" data-fid="' + f.id + '" data-nav="' + f.id + '">' +
        '<div class="f-icon">📁</div><div class="f-name">' + escapeHtml(f.name) + '</div><div class="f-count">' + f.count + ' 张</div>' +
        '<div class="f-act"><button type="button" class="f-ren" title="重命名">✏️</button><button type="button" class="f-del" title="删除文件夹">🗑</button></div></div>';
    });
    html += '<div class="folder-card folder-new" id="folderNew" title="新建文件夹"><div class="f-icon">＋</div><div class="f-name">新建文件夹</div></div>';
    bar.innerHTML = html;
    // 未归类计数
    var un = bar.querySelector('[data-fid="0"] .f-count');
    if (un) {
      var n = 0;
      Object.keys(cards).forEach(function (k) { if (cards[k].folderId === 0) n++; });
      un.textContent = n + ' 张';
    }
    // 各夹计数（递归：夹内直接图 + 子孙夹）
    bar.querySelectorAll('.folder-card[data-fid]:not([data-fid="0"])').forEach(function (el) {
      var fid = parseInt(el.getAttribute('data-fid'));
      el.querySelector('.f-count').textContent = rollupCount(fid) + ' 张';
    });
  }

  function rollupCount(fid) {
    var total = 0;
    Object.keys(cards).forEach(function (k) {
      if (inSubtree(cards[k].folderId, fid)) total++;
    });
    return total;
  }
  // folderId 是否在 fid 的子树内（含自身）
  function inSubtree(folderId, fid) {
    if (folderId === fid) return true;
    var byId = {};
    D.folders.forEach(function (f) { byId[f.id] = f; });
    var cur = folderId, guard = 0;
    while (cur && cur !== 0 && guard++ < 50) {
      var f = byId[cur];
      if (!f) return false;
      cur = f.parent_id;
      if (cur === fid) return true;
    }
    return false;
  }

  function renderGrid() {
    var grid = document.querySelector('.grid');
    if (!grid) return;
    var vis = visibleCards();
    grid.innerHTML = '';
    var frag = document.createDocumentFragment();
    vis.forEach(function (el) { frag.appendChild(el); });
    grid.appendChild(frag);
    // 空态
    var empty = document.querySelector('.empty');
    if (vis.length === 0) {
      if (!empty) {
        empty = document.createElement('div');
        empty.className = 'empty';
        empty.innerHTML = '<div class="big">☁️</div>这里还没有图片';
        grid.parentNode.insertBefore(empty, grid.nextSibling);
      }
    } else if (empty) empty.remove();
  }

  function renderCounts() {
    var cnt = document.querySelector('.grid-title .cnt');
    if (cnt) {
      var total = Object.keys(cards).length;
      var vis = visibleCards().length;
      cnt.textContent = curFolder === null ? total + ' 张 · 点击缩略图可放大查看' : vis + ' 张';
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ===== 导航（零跳转） =====
  function bindNav() {
    document.addEventListener('click', function (e) {
      var nav = e.target.closest && e.target.closest('[data-nav]');
      if (!nav) return;
      // 文件夹操作按钮不触发导航
      if (e.target.closest('.f-ren, .f-del')) return;
      e.preventDefault();
      var v = nav.getAttribute('data-nav');
      curFolder = v === 'all' ? null : parseInt(v);
      window.scrollTo({ top: 0 });
      render();
    });
  }

  // ===== 文件夹树刷新（新建/改名/删除后轻量重拉） =====
  function refreshFolders() {
    var fd = new FormData();
    fd.append('action', 'folder_list');
    fd.append('csrf_token', CSRF);
    fetch(API_MAIN, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r.ok) { D.folders = r.folders; render(); }
      })
      .catch(function () { render(); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
