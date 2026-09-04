/**
 * 图床 SPA 视图引擎 v2（完全学便签：PHP 零卡片渲染，数据驱动全量重建）
 * - 数据源：api.php?action=list（元数据 JSON，几 KB；不含图片本体）
 * - 进/切文件夹零请求零跳转：客户端按 folder_id 过滤重渲染
 * - 小水管铁律：缩略图 data-src + IntersectionObserver 懒加载，进视口才下载；
 *   切走的文件夹不加载；看过的图走 i.php immutable 缓存，回看不耗流量
 * - 剪贴板/选择状态在内存存活（SPA 单页世界）
 */
(function () {
  'use strict';
  var state = { images: [], folders: [], cur: (typeof CUR_FOLDER !== 'undefined' ? CUR_FOLDER : null), loaded: false };   // 初值来自 PHP（?folder= 深链接/刷新回原夹）
  var grid = null;
  var io = null;   // 缩略图懒加载观察器

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
  }
  function byId(fid) {
    for (var i = 0; i < state.folders.length; i++) if (state.folders[i].id === fid) return state.folders[i];
    return null;
  }
  function childrenOf(fid) {
    return state.folders.filter(function (f) { return f.parent_id === fid; });
  }
  // 递归累计：fid 子树内图片数
  function rollupCount(fid) {
    var n = 0;
    state.images.forEach(function (im) {
      var cur = im.folder_id, guard = 0;
      while (cur && cur !== 0 && guard++ < 50) {
        if (cur === fid) { n++; return; }
        var f = byId(cur);
        if (!f) return;
        cur = f.parent_id;
      }
    });
    return n;
  }

  // ===== 数据加载：走 GET（list 只读；POST 响应在部分线路偶发丢失，GET 稳定） =====
  // 失败静默保留旧数据（删除/移动后的刷新失败也绝不弹「网络错误」挤掉成功提示）
  function load(silent) {
    return fetch(API_MAIN + '?action=list&csrf_token=' + encodeURIComponent(CSRF))
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) { if (!silent) toast(r.err || '加载失败'); return; }
        state.images = r.images;
        state.folders = r.folders;
        state.loaded = true;
        render();
      })
      .catch(function () {
        if (silent) { render(); return; }   // 静默模式：保旧数据重渲染，不弹错
        load(true);                          // 自动静默重试一次
      });
  }

  // ===== 视图过滤 =====
  function visibleImages() {
    if (state.cur === null) return state.images;
    if (state.cur === 0) return state.images.filter(function (im) { return im.folder_id === 0; });
    return state.images.filter(function (im) { return im.folder_id === state.cur; });
  }

  // ===== 渲染 =====
  function render() {
    if (!grid) grid = document.querySelector('.grid');
    renderCrumbs();
    renderFolderBar();
    renderGrid();
    if (window.PixelSelection) PixelSelection.syncUI();
  }

  function renderCrumbs() {
    var c = document.getElementById('folderCrumbs');
    if (!c) return;
    var html = '';
    if (state.cur === null) {
      html = '<span class="crumb on">🗂 全部图片</span>';
    } else if (state.cur === 0) {
      html = '<a class="crumb" href="javascript:void(0)" data-nav="all">🗂 全部图片</a><span class="crumb-sep">›</span><span class="crumb on">📥 未归类</span>';
    } else {
      var chain = [], cur = state.cur, guard = 0;
      while (cur && guard++ < 50) {
        var f = byId(cur);
        if (!f) break;
        chain.unshift(f);
        cur = f.parent_id;
      }
      html = '<a class="crumb" href="javascript:void(0)" data-nav="all">🗂 全部图片</a>';
      chain.forEach(function (f) {
        html += '<span class="crumb-sep">›</span>';
        if (f.id === state.cur) html += '<span class="crumb on">📁 ' + esc(f.name) + '</span>';
        else html += '<a class="crumb" href="javascript:void(0)" data-nav="' + f.id + '">📁 ' + esc(f.name) + '</a>';
      });
    }
    c.innerHTML = html;
  }

  function renderFolderBar() {
    var bar = document.getElementById('folderBar');
    if (!bar) return;
    var html = '';
    var inFolder = state.cur !== null && state.cur !== 0;
    if (inFolder) {
      var f = byId(state.cur);
      var p = f ? f.parent_id : 0;
      html += '<div class="folder-card" data-nav="' + (p === null || p === 0 ? 0 : p) + '"><div class="f-icon">↩️</div><div class="f-name">上一级</div></div>';
    }
    if (state.cur === null) {
      html += '<div class="folder-card fdrop" data-fid="0" data-nav="0"><div class="f-icon">📥</div><div class="f-name">未归类</div><div class="f-count">' + state.images.filter(function (im) { return im.folder_id === 0; }).length + ' 张</div></div>';
    }
    var baseLevel = inFolder ? state.cur : 0;
    childrenOf(baseLevel).forEach(function (f) {
      html += '<div class="folder-card fdrop" data-fid="' + f.id + '" data-nav="' + f.id + '">' +
        '<div class="f-icon">📁</div><div class="f-name">' + esc(f.name) + '</div><div class="f-count">' + rollupCount(f.id) + ' 张</div>' +
        '<div class="f-act"><button type="button" class="f-share" title="公开分享">🔗</button><button type="button" class="f-ren" title="重命名">✏️</button><button type="button" class="f-del" title="删除文件夹">🗑</button></div></div>';
    });
    html += '<div class="folder-card folder-new" id="folderNew" title="新建文件夹"><div class="f-icon">＋</div><div class="f-name">新建文件夹</div></div>';
    bar.innerHTML = html;
  }

  function expSelHtml(img) {
    var v = 0;
    var remain = img.expire_at > 0 ? img.expire_at - Math.floor(Date.now() / 1000) : 0;
    if (remain > 0) {
      if (remain <= 3600) v = 3600;
      else if (remain <= 86400) v = 86400;
      else if (remain <= 604800) v = 604800;
      else v = 2592000;
    }
    var opts = [[0, '永不过期'], [3600, '1 小时'], [86400, '1 天'], [604800, '7 天'], [2592000, '30 天']]
      .map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === v ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
    return '<select class="exp-sel">' + opts + '</select>';
  }

  function makeCard(img) {
    var expired = img.expire_at > 0 && img.expire_at <= Math.floor(Date.now() / 1000);
    var card = document.createElement('div');
    card.className = 'card';
    card.setAttribute('data-id', img.id);
    card.setAttribute('data-folder-id', img.folder_id);
    card.setAttribute('draggable', 'true');
    card.setAttribute('data-name', img.name);
    card.setAttribute('data-url', img.view);
    card.setAttribute('data-shared', img.shared ? '1' : '0');
    card.setAttribute('data-shareurl', img.shared ? img.thumb + '&t=' + img.share_token : '');
    card.setAttribute('data-until', img.share_until);
    var remainTxt = expired ? '已过期' : (img.expire_at > 0 ? '剩 ' + Math.max(1, Math.ceil((img.expire_at - Math.floor(Date.now() / 1000)) / 60)) + ' 分钟' : '永久');
    card.innerHTML =
      '<div class="thumb-wrap">' +
        '<label class="pick" title="多选"><input type="checkbox" class="pickbox"></label>' +
        // 小水管铁律：data-src 占位，IntersectionObserver 进视口才真正下载
        '<img class="thumb" alt="" data-src="' + esc(img.thumb) + '">' +
        (img.shared ? '<span class="share-badge">已分享</span>' : '') +
      '</div>' +
      '<div class="meta"><div class="m-name" title="' + esc(img.name) + '">' + esc(img.name) + '</div>' +
      '<div class="m-sub"><span>' + fmtSize(img.size) + '</span><span>' + img.w + '×' + img.h + '</span>' +
      '<span>' + img.hits + ' 次浏览</span>' +
      '<span class="' + (expired ? 'exp-badge' : 'exp-badge off') + '">' + remainTxt + '</span></div></div>' +
      '<div class="ops">' + expSelHtml(img) +
      '<button class="sm-btn share-btn">' + (img.shared ? '更新分享' : '外链') + '</button>' +
      '<button class="sm-btn rename-btn">重命名</button>' +
      (img.shared ? '<button class="sm-btn danger unshare-btn">停止</button>' : '') +
      '<button class="sm-btn danger del-btn">删除</button></div>';
    return card;
  }

  function renderGrid() {
    if (!grid) grid = document.querySelector('.grid');
    // 小水管铁律：「全部图片」视图只显示文件夹（图片零加载）；进具体文件夹/未归类才渲染该夹图片（懒加载）
    if (state.cur === null) {
      grid.innerHTML = '';
      var empty = document.querySelector('.empty');
      if (empty) {
        empty.style.display = '';
        empty.innerHTML = '<div class="big">📁</div>共 ' + state.images.length + ' 张图片 · 点击上方文件夹进入查看';
      }
      return;
    }
    var vis = visibleImages();
    var frag = document.createDocumentFragment();
    vis.forEach(function (img) { frag.appendChild(makeCard(img)); });
    grid.innerHTML = '';
    grid.appendChild(frag);
    observeThumbs(grid);   // 懒加载：只观察本次渲染的卡，视口外不下载
    // 空态
    var empty = document.querySelector('.empty');
    if (vis.length === 0) {
      if (empty) {
        empty.style.display = '';
        empty.innerHTML = '<div class="big">☁️</div>这里还没有图片';
      } else {
        var d = document.createElement('div');
        d.className = 'empty';
        d.innerHTML = '<div class="big">☁️</div>这里还没有图片';
        grid.parentNode.insertBefore(d, grid.nextSibling);
      }
    } else if (empty) empty.style.display = 'none';
    // 计数
    var cnt = document.querySelector('.grid-title .cnt');
    if (cnt) cnt.textContent = vis.length + ' 张 · 点击缩略图可放大查看';
  }

  // ===== 缩略图懒加载（小水管保护核心） =====
  function observeThumbs(root) {
    var imgs = root.querySelectorAll('img.thumb[data-src]');
    if (!('IntersectionObserver' in window)) {
      // 老浏览器兜底：直接加载（功能优先）
      imgs.forEach(function (im) { im.src = im.getAttribute('data-src'); im.removeAttribute('data-src'); });
      return;
    }
    if (!io) {
      io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;      // 视口外：一个字节都不下载
          var im = en.target;
          im.src = im.getAttribute('data-src');
          im.removeAttribute('data-src');
          io.unobserve(im);
        });
      }, { rootMargin: '250px' });   // 提前 250px 预载，滚动顺滑且不浪费
    }
    imgs.forEach(function (im) { io.observe(im); });
  }

  // ===== 导航（零跳转） =====
  function bindNav() {
    document.addEventListener('click', function (e) {
      var nav = e.target.closest && e.target.closest('[data-nav]');
      if (!nav) return;
      if (e.target.closest('.f-ren, .f-del')) return;
      e.preventDefault();
      var v = nav.getAttribute('data-nav');
      state.cur = v === 'all' ? null : parseInt(v);
      // 同步地址栏：写操作后的整页刷新会回到当前文件夹（不会跳回首页）
      history.replaceState(null, '', 'dashboard.php' + (state.cur === null ? '' : '?folder=' + state.cur));
      window.scrollTo({ top: 0 });
      render();
    });
  }

  // ===== 对外 API =====
  window.__SPA = {
    render: render,
    getCur: function () { return state.cur; },
    // 上传成功：元数据入模型（卡片按当前视图决定显隐）
    addImg: function (img) {
      state.images.unshift(img);
      render();
    },
    // 移动后更新归属
    moveImg: function (id, folderId) {
      state.images.forEach(function (im) { if (im.id === parseInt(id)) im.folder_id = folderId; });
      render();
    },
    removeImg: function (id) {
      state.images = state.images.filter(function (im) { return im.id !== parseInt(id); });
      render();
    },
    // 文件夹增删改 / 批量操作后：完整重拉（元数据轻量；图片走缓存不重下）
    reload: function () { load(true); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { bindNav(); boot2(); });
  else { bindNav(); boot2(); }
  function boot2() { load(); }
})();
