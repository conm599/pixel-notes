// 陶瓦图床 · dashboard 交互逻辑（自 dashboard.php 拆分）
// CSRF / BASE / CURRENT_UUID 由页面内 bootstrap 脚本提供
var API_MAIN = 'https://' + (window.API_MAIN_HOST || 'tuchang.naxid.top') + '/api.php';   // 优选域（bootstrap 注入，第二域名自动适配）
var API_BACKUP = BASE + 'api.php';                     // 原域（降级）
var API_KEY = ''; // [瀹夊叏淇] 涓嶅湪婧愮爜鏆撮湶锛岃繍琛屾椂 AJAX 鑾峰彇

// 主备降级封装：先走优选，网络错误/5xx 自动切回原域
function apiFetch(fd) {
  return doApi(API_MAIN, fd).catch(function () { return doApi(API_BACKUP, fd); });
}
function doApi(url, fd) {
  return fetch(url + '?key=' + encodeURIComponent(API_KEY), { method: 'POST', body: fd })
    .then(function (r) {
      if (r.status >= 500) throw new Error('server ' + r.status);
      return r.json().catch(function () { return { ok: false, err: '响应解析失败 (HTTP ' + r.status + ')' }; });
    });
}

function toast(msg) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._h);
  t._h = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}
function qStatus(el, text, cls) {
  el.className = 'q-status' + (cls ? ' ' + cls : '');
  el.textContent = text;
}

// ===== 前端压缩（canvas -> WebP 60%） =====
function compressImage(file) {
  return new Promise(function (resolve, reject) {
    if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) return reject(new Error('不支持的格式'));
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      URL.revokeObjectURL(url);
      var w = img.naturalWidth, h = img.naturalHeight;
      if (!w || !h) return reject(new Error('图片无效'));
      var max = 4096;
      if (w > max || h > max) { var s = max / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
      var canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      var ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, w, h);
      canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('压缩失败')); }, 'image/webp', 0.6);
    };
    img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('图片解码失败')); };
    img.src = url;
  });
}

// ===== 上传（智能降级：仅网络/5xx错误才重试，业务错误不重试） =====
// 必须返回 Promise：uploadQueue 依赖 .then 递减并发计数，漏 return 会导致队列派发一张后卡死
function uploadOne(file, expVal) {
  var item = document.createElement('div');
  item.className = 'q-item';
  item.innerHTML = '<span class="q-name"></span><div class="q-bar"><div class="q-fill" style="width:0%"></div></div><span class="q-status">排队中</span>';
  document.getElementById('queue').appendChild(item);
  item.querySelector('.q-name').textContent = file.name;
  var st = item.querySelector('.q-status');
  return compressImage(file).then(function (blob) {
    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('img', blob, file.name);
    fd.append('name', file.name);
    fd.append('expire', expVal);
    fd.append('share', '0'); // Web 上传保持私有（API 模式默认公开）
    fd.append('folder_id', window.__SPA ? (window.__SPA.getCur() === null ? 0 : window.__SPA.getCur()) : 0); // 落当前文件夹
    qStatus(st, '上传中…', '');
    uploadTo(API_MAIN, fd, item).catch(function (e) {
      if (e && e.isBiz) throw e; // 业务错误（4xx）直接抛出，不重试
      if (API_MAIN === API_BACKUP) throw e; // 同域名不重试
      qStatus(st, '切换线路重试…', '');
      return uploadTo(API_BACKUP, fd, item);
    }).then(function (res) {
      item.querySelector('.q-fill').style.width = '100%';
      qStatus(st, '完成', 'done');
      insertCard(res);
      toast('上传成功');
    }).catch(function (e) {
      var msg = e && e.biz ? e.biz : (e && e.message ? e.message : '失败');
      qStatus(st, '失败', 'err');
      toast(msg);
    });
  }).catch(function (err) {
    qStatus(st, '失败', 'err');
    toast(err.message);
  });
}
// ===== 上传队列（并发窗口 2；uploadOne 必须 resolve/reject 才会继续派发） =====
function uploadQueue(files, exp) {
  var list = files.slice(), active = 0, idx = 0;
  function next() {
    while (active < 2 && idx < list.length) {
      (function (f) { active++; uploadOne(f, exp).then(function () { active--; next(); }, function () { active--; next(); }); })(list[idx++]);
    }
  }
  next();
}

function uploadTo(url, fd, item) {
  return new Promise(function (resolve, reject) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url + '?key=' + encodeURIComponent(API_KEY));
    xhr.timeout = 30000; // 30 秒超时，避免无限等待
    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) {
        item.querySelector('.q-fill').style.width = Math.round(e.loaded * 100 / e.total) + '%';
        if (e.loaded >= e.total) qStatus(item.querySelector('.q-status'), '服务器处理中…', '');
      }
    };
    xhr.onload = function () {
      var res;
      try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
      if (xhr.status >= 500 || xhr.status === 0) return reject(new Error('server ' + xhr.status));
      if (xhr.status === 200 && res && res.ok) return resolve(res);
      var e = new Error('biz');
      e.biz = (res && res.err) || '上传失败';
      e.isBiz = true; // 标记业务错误，调用方据此跳过重试
      reject(e);
    };
    xhr.ontimeout = function () { reject(new Error('请求超时（30秒）')); };
    xhr.onerror = function () { reject(new Error('网络错误')); };
    xhr.send(fd);
  });
}

// ===== 上传成功后局部插入卡片（不刷新页面） =====
function insertCard(res) {
  var grid = document.querySelector('.grid');
  var empty = document.querySelector('.empty');
  if (empty) { empty.remove(); }
  if (!grid) {
    // 无列表容器时创建一个
    var gwrap = document.querySelector('.grid-title');
    if (!gwrap) return;
    grid = document.createElement('div');
    grid.className = 'grid';
    gwrap.parentNode.insertBefore(grid, gwrap.nextSibling);
    gwrap.querySelector('.cnt').textContent = '1 张';
  } else {
    var cnt = document.querySelector('.grid-title .cnt');
    if (cnt) cnt.textContent = (grid.children.length + 1) + ' 张';
  }
  var card = document.createElement('div');
  card.className = 'card';
  card.dataset.id = res.id;
  card.dataset.name = res.name || 'image';
  card.dataset.url = res.url;
  card.dataset.shared = '0';
  card.dataset.shareurl = '';
  card.dataset.until = '0';
  var expOptsHtml = '<option value="0">永不过期</option><option value="3600">1 小时</option>' +
    '<option value="86400">1 天</option><option value="604800">7 天</option><option value="2592000">30 天</option>';
  var thumbSrc = res.url;
  card.innerHTML =
    '<div class="thumb-wrap"><img class="thumb" src="' + thumbSrc + '" alt="" loading="lazy"></div>' +
    '<div class="meta"><div class="m-name" title="' + (res.name || 'image') + '">' + (res.name || 'image') + '</div>' +
    '<div class="m-sub"><span>' + fmtSize(res.size) + '</span><span>' + res.w + '×' + res.h + '</span>' +
    '<span class="exp-badge off">永久</span></div></div>' +
    '<div class="ops"><select class="exp-sel">' + expOptsHtml + '</select>' +
    '<button class="sm-btn share-btn">外链</button><button class="sm-btn rename-btn">重命名</button><button class="sm-btn danger del-btn">删除</button></div>';
  if (window.__SPA) {
    // SPA 模式：只送元数据，卡片由 spa.js 构建（上传落当前文件夹；全部视图落未归类）
    var cur = window.__SPA.getCur();
    var fid = cur === null ? 0 : cur;
    window.__SPA.addImg({
      id: res.id, name: res.name || 'image', size: res.size, w: res.w, h: res.h,
      created_at: Math.floor(Date.now() / 1000), expire_at: 0, hits: 0,
      folder_id: fid, shared: 0, share_token: '', share_until: 0,
      thumb: res.url, view: BASE + 'view.php?id=' + res.id + '&u=' + CURRENT_UUID
    });
    return;
  }
  grid.insertBefore(card, grid.firstChild);
}
function fmtSize(b) {
  if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
  if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
  return b + ' B';
}

// ===== 图片操作（主备降级） =====
function act(id, action, extra) {
  var fd = new FormData();
  fd.append('action', action);
  fd.append('id', id);
  if (extra) fd.append(extra[0], extra[1]);
  return apiFetch(fd);
}

// ===== 外链分享弹窗 =====
// ===== rename =====
function doRename(id, oldName) {
  var newName = prompt('\u8f93\u5165\u65b0\u7684\u56fe\u7247\u540d\u79f0\uff1a', oldName);
  if (newName === null) return;
  newName = newName.trim();
  if (newName === '' || newName === oldName) return;
  var fd = new FormData();
  fd.append('action', 'rename');
  fd.append('csrf_token', CSRF);
  fd.append('id', id);
  fd.append('name', newName);
  fetch('api.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (r) {
      if (r.ok) {
        var card = document.querySelector('.card[data-id="' + id + '"]');
        if (card) {
          card.dataset.name = r.name;
          var nameEl = card.querySelector('.m-name');
          if (nameEl) { nameEl.textContent = r.name; nameEl.title = r.name; }
        }
        toast('已重命名');
      } else toast(r.err || '重命名失败');
    });
}

function openShare(id, name) {
  var dlg = document.getElementById('shareDlg');
  dlg.querySelector('.share-name').textContent = name;
  dlg._id = id;
  dlg.querySelector('.share-result').style.display = 'none';
  dlg.style.display = 'flex';
  dlg.scrollIntoView({ block: 'center' }); // 确保立即弹出到视口中央
}
function closeShare() {
  document.getElementById('shareDlg').style.display = 'none';
}
function doShare() {
  var dlg = document.getElementById('shareDlg');
  var dur = dlg.querySelector('.share-dur').value;
  var btn = dlg.querySelector('.share-go');
  btn.disabled = true;
  btn.textContent = '创建中…';
  act(dlg._id, 'share', ['duration', dur]).then(function (r) {
    btn.disabled = false;
    btn.textContent = '创建分享';
    if (r.ok) {
      var box = dlg.querySelector('.share-result');
      box.style.display = 'block';
      var u = document.getElementById('s-url'), u2 = document.getElementById('s-url2'),
          m = document.getElementById('s-md'),
          h = document.getElementById('s-html'), b = document.getElementById('s-bb');
      u.value = r.url;
      u2.value = r.url2 || '';
      m.value = '![](' + r.url + ')';
      h.value = '<img src="' + r.url + '" alt="taowa">';
      b.value = '[img]' + r.url + '[/img]';
      // 局部更新卡片（不刷新页面，避免链接丢失）
      var card = document.querySelector('.card[data-id="' + dlg._id + '"]');
      if (card) markShared(card, r.url, r.until);
      toast('分享已创建');
    } else toast(r.err || '创建失败');
  });
}
// 标记卡片为已分享（徽标 + 按钮文案 + 停止按钮）
function markShared(card, shareUrl, until) {
  card.dataset.shared = '1';
  card.dataset.shareurl = shareUrl;
  card.dataset.until = until;
  var wrap = card.querySelector('.thumb-wrap');
  if (!wrap.querySelector('.share-badge')) {
    var b = document.createElement('span');
    b.className = 'share-badge';
    b.textContent = '已分享';
    wrap.appendChild(b);
  }
  var sb = card.querySelector('.share-btn');
  if (sb) sb.textContent = '更新分享';
  if (!card.querySelector('.unshare-btn')) {
    var u = document.createElement('button');
    u.className = 'sm-btn danger unshare-btn';
    u.textContent = '停止';
    u.addEventListener('click', function () {
      if (!confirm('停止分享？链接将立即失效')) return;
      act(card.dataset.id, 'unshare').then(function (r) {
        if (r.ok) {
          card.dataset.shared = '0';
          card.dataset.shareurl = '';
          card.dataset.until = '0';
          var badge = card.querySelector('.share-badge');
          if (badge) badge.remove();
          var sb2 = card.querySelector('.share-btn');
          if (sb2) sb2.textContent = '外链';
          if (u) u.remove();
          toast('已停止分享');
        } else toast(r.err || '失败');
      });
    });
    sb.parentNode.insertBefore(u, sb.nextSibling);
  }
}

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') { closeShare(); }
});

document.addEventListener('DOMContentLoaded', function () {
  var dz = document.getElementById('dz');
  var file = document.getElementById('file');
  var expSel = document.getElementById('expSel');

  dz.addEventListener('click', function () { file.click(); });
  dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('drag'); });
  dz.addEventListener('dragleave', function () { dz.classList.remove('drag'); });
  dz.addEventListener('drop', function (e) {
    e.preventDefault(); dz.classList.remove('drag');
    var files = Array.prototype.slice.call(e.dataTransfer.files);
    uploadQueue(files, expSel.value);
  });
  file.addEventListener('change', function () {
    var files = Array.prototype.slice.call(file.files);
    uploadQueue(files, expSel.value);
    file.value = '';
  });

  // 复制按钮（所有 input + 复制 组合）
  document.querySelectorAll('.copy-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = b.previousElementSibling;
      inp.select();
      try { document.execCommand('copy'); toast('已复制'); } catch (e) { toast('复制失败'); }
    });
  });

  // 卡片操作
  // 卡片操作：document 级委托（SPA 重建卡片后依然有效）
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.card .share-btn, .card .unshare-btn, .card .rename-btn, .card .del-btn') : null;
    if (!btn) return;
    var card = btn.closest('.card');
    var id = card.dataset.id;
    if (btn.classList.contains('share-btn')) {
      openShare(id, card.querySelector('.m-name').textContent);
    } else if (btn.classList.contains('unshare-btn')) {
      if (!confirm('停止分享？链接将立即失效')) return;
      act(id, 'unshare').then(function (r) {
        if (r.ok) {
          card.dataset.shared = '0';
          card.dataset.shareurl = '';
          card.dataset.until = '0';
          var badge = card.querySelector('.share-badge');
          if (badge) badge.remove();
          card.querySelector('.share-btn').textContent = '外链';
          btn.remove();
          toast('已停止分享');
        } else toast(r.err || '失败');
      });
    } else if (btn.classList.contains('rename-btn')) {
      doRename(id, card.dataset.name);
    } else if (btn.classList.contains('del-btn')) {
      if (!confirm('确定删除这张图片？')) return;
      act(id, 'delete').then(function (r) {
        if (r.ok) {
          card.remove();
          if (window.__SPA) window.__SPA.removeCard(parseInt(id));
          toast('已删除');
        } else toast(r.err || '删除失败');
      });
    }
  });
  document.addEventListener('change', function (e) {
    var sel = e.target.closest ? e.target.closest('.card .exp-sel') : null;
    if (!sel) return;
    var card = sel.closest('.card');
    act(card.dataset.id, 'setexpire', ['expire', sel.value]).then(function (r) {
      toast(r.ok ? '过期时间已更新' : (r.err || '失败'));
      if (r.ok) updateExpireLabel(card, sel.value);
    });
  });

  // 过期标签局部更新
  function updateExpireLabel(card, v) {
    var span = card.querySelector('.m-sub .exp-badge');
    if (!span) return;
    var txt = v == 0 ? '永久' : v == 3600 ? '剩 60 分钟' : v == 86400 ? '剩 24 小时' : v == 604800 ? '剩 7 天' : '剩 30 天';
    span.textContent = txt;
    span.classList.toggle('off', v == 0);
  }

  // 分享弹窗事件
  document.getElementById('shareDlg').addEventListener('click', function (e) {
    if (e.target === this) closeShare();
  });

  // ===== API Key 管理 =====
  function apiAct(action, extra) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    if (extra) fd.append(extra[0], extra[1]);
    return fetch('api.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  function showApiKey(k) {
    var box = document.getElementById('apibox');
    var del = document.getElementById('apidel');
    if (!k) {
      box.style.display = 'none';
      del.style.display = 'none';
      return;
    }
    box.style.display = 'block';
    del.style.display = 'inline-block';
    document.getElementById('apikey').value = k.key;
    document.getElementById('apilast').textContent = '创建于 ' + new Date(k.created_at * 1000).toLocaleString() +
      (k.last_used > 0 ? ' · 最近使用 ' + new Date(k.last_used * 1000).toLocaleString() : ' · 尚未使用');
  }
  var apiKeyReady = apiAct('getkey').then(function (r) { if (r.ok && r.key) { API_KEY = r.key; showApiKey(r); } });
  document.getElementById('apigen').addEventListener('click', function () {
    if (!confirm('生成新的 API Key？旧 Key 将立即失效，使用旧 Key 的程序需更新。')) return;
    apiAct('genkey').then(function (r) {
      if (r.ok) { showApiKey(r); toast('API Key 已生成'); } else toast(r.err || '失败');
    });
  });
  document.getElementById('apidel').addEventListener('click', function () {
    var key = document.getElementById('apikey').value;
    if (!confirm('确定删除这个 API Key？使用它的程序将立即失效。')) return;
    apiAct('delkey', ['key', key]).then(function (r) {
      if (r.ok) { showApiKey(null); toast('已删除'); } else toast(r.err || '失败');
    });
  });

  // ===== 多选 / 批量操作 =====
  var selSet = new Set();
  window.__selSet = selSet;
  var bulkbar = document.getElementById('bulkbar');
  function refreshBulk() {
    var n = selSet.size;
    document.getElementById('bulkCount').textContent = '已选 ' + n + ' 张';
    bulkbar.classList.toggle('show', n > 0);
    document.querySelectorAll('.card').forEach(function (card) {
      card.classList.toggle('picked', selSet.has(card.dataset.id));
      var cb = card.querySelector('.pickbox');
      if (cb) cb.checked = selSet.has(card.dataset.id);
    });
  }
  function selectedIds() {
    return Array.from(selSet);
  }
  document.querySelectorAll('.card').forEach(function (card) {
    var cb = card.querySelector('.pickbox');
    if (!cb) return;
    cb.addEventListener('change', function () {
      if (cb.checked) selSet.add(card.dataset.id); else selSet.delete(card.dataset.id);
      refreshBulk();
    });
  });

  var selAll = document.getElementById('selAll');
  if (selAll) selAll.addEventListener('click', function () {
    var all = document.querySelectorAll('.card');
    var every = all.length > 0 && all.length === selSet.size;
    all.forEach(function (card) {
      if (every) selSet.delete(card.dataset.id); else selSet.add(card.dataset.id);
    });
    refreshBulk();
    selAll.textContent = every ? '全选' : '取消全选';
  });
  document.getElementById('bulkShare').addEventListener('click', function () { bulkShareIds(selectedIds()); });
  document.getElementById('bulkDel').addEventListener('click', function () { bulkDelIds(selectedIds()); });
  document.getElementById('bulkZip').addEventListener('click', function () { bulkZipIds(selectedIds()); });
  document.getElementById('bulkCancel').addEventListener('click', function () {
    selSet.clear();
    refreshBulk();
    var sa = document.getElementById('selAll');
    if (sa) sa.textContent = '全选';
  });

  // 批量分享
  window.__bulkShareIds = function (ids) { bulkShareIds(ids); };   // 跨 IIFE 桥（selection 注入段调用）
  window.__bulkZipIds = function (ids) { bulkZipIds(ids); };
  function bulkShareIds(ids) {
    if (ids.length === 0) return;
    var fd = new FormData();
    fd.append('action', 'sharebatch');
    ids.forEach(function (i) { fd.append('ids[]', i); });
    apiFetch(fd).then(function (res) {
      if (!res.ok) { toast(res.err || '分享失败'); return; }
      var box = document.getElementById('batchLinks');
      box.innerHTML = '';
      res.links.forEach(function (l) {
        var row = document.createElement('div');
        row.style.marginBottom = '8px';
        // 主域名行
        var r1 = document.createElement('div');
        r1.className = 'code-row';
        r1.style.marginBottom = '4px';
        var lab1 = document.createElement('label');
        lab1.textContent = '主';
        var inp1 = document.createElement('input');
        inp1.value = l.url;
        inp1.readOnly = true;
        var btn1 = document.createElement('button');
        btn1.className = 'copy-btn';
        btn1.textContent = '复制';
        btn1.onclick = function () { inp1.select(); try { document.execCommand('copy'); toast('已复制'); } catch (e) {} };
        r1.appendChild(lab1); r1.appendChild(inp1); r1.appendChild(btn1);
        row.appendChild(r1);
        // 优选行
        if (l.url2) {
          var r2 = document.createElement('div');
          r2.className = 'code-row';
          var lab2 = document.createElement('label');
          lab2.textContent = '优选';
          var inp2 = document.createElement('input');
          inp2.value = l.url2;
          inp2.readOnly = true;
          var btn2 = document.createElement('button');
          btn2.className = 'copy-btn';
          btn2.textContent = '复制';
          btn2.onclick = function () { inp2.select(); try { document.execCommand('copy'); toast('已复制'); } catch (e) {} };
          r2.appendChild(lab2); r2.appendChild(inp2); r2.appendChild(btn2);
          row.appendChild(r2);
        }
        box.appendChild(row);
      });
      document.getElementById('shareBatchDlg').style.display = 'flex';
      // 更新卡片已分享状态
      res.links.forEach(function (l) {
        var card = document.querySelector('.card[data-id="' + l.id + '"]');
        if (card) markShared(card, l.url, 0);
      });
      toast('已分享 ' + res.count + ' 张');
    });
  }
  document.getElementById('copyAllLinks').addEventListener('click', function () {
    var links = Array.prototype.map.call(document.querySelectorAll('#batchLinks input'), function (i) { return i.value; });
    if (!links.length) return;
    var txt = links.join('\n');
    var ta = document.createElement('textarea');
    ta.value = txt;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); toast('已复制全部 ' + links.length + ' 个链接'); } catch (e) { toast('复制失败'); }
    document.body.removeChild(ta);
  });
  function closeShareBatch() {
    document.getElementById('shareBatchDlg').style.display = 'none';
  }
  document.getElementById('shareBatchDlg').addEventListener('click', function (e) {
    if (e.target === this) closeShareBatch();
  });

  // 批量删除
  function bulkDelIds(ids) {
    if (ids.length === 0) return;
    if (!confirm('确定删除选中的 ' + ids.length + ' 张图片？')) return;
    var fd = new FormData();
    fd.append('action', 'delbatch');
    ids.forEach(function (i) { fd.append('ids[]', i); });
    apiFetch(fd).then(function (res) {
      if (!res.ok) { toast(res.err || '删除失败'); return; }
      if (window.__SPA) {
        ids.forEach(function (i) { window.__SPA.removeImg(parseInt(i, 10)); });
      } else {
        ids.forEach(function (i) {
          var card = document.querySelector('.card[data-id="' + i + '"]');
          if (card) card.remove();
        });
      }
      selSet.clear();
      refreshBulk();
      toast('已删除 ' + res.deleted + ' 张');
    });
  }

  // 打包下载 ZIP（主备降级：fetch blob 本地保存）
  function bulkZipIds(ids) {
    var q = '&ids=' + ids.join(',');
    doZip(API_MAIN, q).catch(function () { return doZip(API_BACKUP, q); }).catch(function () {
      toast('打包下载失败，请重试');
    });
  }
  function doZip(url, q) {
    return fetch(url + '?key=' + encodeURIComponent(API_KEY) + '&action=zip' + q)
      .then(function (r) {
        if (r.status >= 500) throw new Error('server ' + r.status);
        return r.blob();
      })
      .then(function (blob) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'taowa-images-' + new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-') + '.zip';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(a.href); }, 500);
      });
  }
});

/* ===== 全窗口拖放上传（拖文件进页面任意位置；不再必须拖到顶部上传区） ===== */
(function () {
  var mask = null, depth = 0;
  function ensure() {
    if (mask) return mask;
    mask = document.createElement('div');
    mask.id = 'dropMask';
    mask.innerHTML = '<div class="dm-inner">📥 松手上传到当前文件夹</div>';
    document.body.appendChild(mask);
    return mask;
  }
  function hasFiles(e) {
    try { return Array.prototype.indexOf.call(e.dataTransfer ? e.dataTransfer.types : [], 'Files') !== -1; }
    catch (err) { return false; }
  }
  document.addEventListener('dragenter', function (e) {
    if (!hasFiles(e)) return;
    depth++;
    ensure().classList.add('on');
  });
  document.addEventListener('dragleave', function () {
    depth = Math.max(0, depth - 1);
    if (depth === 0 && mask) mask.classList.remove('on');
  });
  document.addEventListener('dragover', function (e) {
    if (mask && mask.classList.contains('on')) e.preventDefault();   // 允许任意位置 drop
  });
  document.addEventListener('drop', function (e) {
    depth = 0;
    if (mask) mask.classList.remove('on');
    // dz 区域自己处理过（避免双触发）
    if (e.target && e.target.closest && e.target.closest('#dz')) return;
    if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
    e.preventDefault();
    var files = Array.prototype.slice.call(e.dataTransfer.files);
    if (files.length) {
      uploadQueue(files, document.getElementById('expSel').value);
      toast('已加入队列 ' + files.length + ' 张');
    }
  });
})();

/* ===== Windows 风格图片文件夹（新建/改名/删除/拖拽归类） ===== */
(function () {
  function fapi(action, data, done, stage) {
    // 三通道容错：fetch → fetch 重试 → XHR 换通道。
    // 关键语义：stage>0 = 前一次请求响应丢失后的重试——此时服务端【很可能已执行过一次】，
    // 重试拿到的业务错误（同名已存在/文件夹不存在等）多为「首次已成功」的证据，
    // 一律按 unreliable 处理：done(r, true)，调用方重拉真实状态、不报错误。
    stage = stage || 0;
    var key = action + ':' + JSON.stringify(data);
    if (window.__fapiInflight && window.__fapiInflight[key]) return;   // 请求级防重：同参数请求进行中忽略重复（双击/重试双发根除）
    if (!window.__fapiInflight) window.__fapiInflight = {};
    window.__fapiInflight[key] = true;
    var release = function () { if (window.__fapiInflight) delete window.__fapiInflight[key]; };
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    var url = API_MAIN.replace(/\/api\.php$/, '') + '/api.php';
    var finish = function (r, unreliable) { release(); done(r, unreliable); };
    var fallback = function () {
      if (stage === 0) { fapi(action, data, function (r, u) { finish(r, u); }, 1); return; }          // fetch 重试
      if (stage === 1) {                                                 // XHR 换通道
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.onload = function () { try { var r = JSON.parse(xhr.responseText); finish(r, true); } catch (e) { finish({ ok: false, err: '网络响应丢失' }, true); } };
        xhr.onerror = function () { finish({ ok: false, err: '网络响应丢失' }, true); };
        xhr.send(fd);
        return;
      }
      done({ ok: false, err: '网络响应丢失' }, true);
    };
    fetch(url, { method: 'POST', body: fd, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (r) { done(r, false); })
      .catch(fallback);
  }

  // 新建文件夹（document 委托：SPA 重建 folderBar 后依然有效）
  var newBtnLock = 0;
  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#folderNew')) return;
    e.stopPropagation();
    if (Date.now() - newBtnLock < 800) return;   // 防抖：双击不双发
    newBtnLock = Date.now();
    var name = prompt('文件夹名称（≤60 字）：', '');
    if (name === null) return;
    name = name.trim();
    if (name === '') return toast('名称不能为空');
    var createParent = window.__SPA ? window.__SPA.getCur() : CUR_FOLDER;   // 实时读当前夹（新建落当前层）
    fapi('folder_create', { name: name, parent_id: createParent === null ? '' : createParent }, function (r) {
      // 用户定稿：操作后整页刷新（服务器真实状态，零状态同步 bug）；URL 已带 ?folder= 刷新后留在原位
      toast(r.ok ? '已创建「' + r.name + '」' : (r.err || '创建失败'));
      setTimeout(function () { location.reload(); }, 500);
    });
  });

  // 改名 / 删除（委托，阻止冒泡避免触发卡片跳转）
  var bar = document.getElementById('folderBar');
  if (bar) bar.addEventListener('click', function (e) {
    var ren = e.target.closest && e.target.closest('.f-ren');
    var del = e.target.closest && e.target.closest('.f-del');
    var shr = e.target.closest && e.target.closest('.f-share');
    if (!ren && !del && !shr) return;
    e.stopPropagation();
    var card = (ren || del || shr).closest('.folder-card');
    var fid = card.getAttribute('data-fid');
    if (shr) {
      // 打开像素风文件夹分享弹窗（记录当前夹）
      window.__fsFid = fid;
      document.getElementById('fsName').textContent = '📁 ' + (card.querySelector('.f-name').textContent) + ' · 分享';
      document.getElementById('fsResult').style.display = 'none';
      document.getElementById('folderShareDlg').style.display = 'flex';
      if (window.__openFolderShare) window.__openFolderShare();   // 回显当前分享状态
      return;
    }
    if (ren) {
      var cur = card.querySelector('.f-name').textContent;
      var name = prompt('重命名为：', cur);
      if (name === null) return;
      name = name.trim();
      if (name === '' || name === cur) return;
      fapi('folder_rename', { id: fid, name: name }, function (r) {
        toast(r.ok ? '已重命名「' + r.name + '」' : (r.err || '失败'));
        setTimeout(function () { location.reload(); }, 500);
      });
    } else {
      if (!confirm('删除该文件夹？夹内图片自动回到「未归类」，图片不会删除。')) return;
      fapi('folder_delete', { id: fid }, function (r) {
        toast(r.ok ? '文件夹已删除，内容已上移一级' : (r.err || '失败'));
        setTimeout(function () { location.reload(); }, 500);
      });
    }
  });

  // 拖拽归类：卡片 dragstart 记录 id；folder-card dragover/drop 落夹
  var dragId = null;
  document.addEventListener('dragstart', function (e) {
    var card = e.target.closest && e.target.closest('.card');
    if (card) dragId = card.getAttribute('data-id');
  });
  document.addEventListener('dragend', function () { dragId = null; });
  document.querySelectorAll('.folder-card.fdrop').forEach(function (fc) {
    fc.addEventListener('dragover', function (e) { e.preventDefault(); fc.classList.add('dragover'); });
    fc.addEventListener('dragleave', function () { fc.classList.remove('dragover'); });
    fc.addEventListener('drop', function (e) {
      e.preventDefault();
      fc.classList.remove('dragover');
      var id = dragId; dragId = null;
      if (!id) return;
      var fid = fc.getAttribute('data-fid') || '0';
      var cardEl = document.querySelector('.card[data-id="' + id + '"]');
      if (cardEl && cardEl.getAttribute('data-folder-id') === fid) return; // 已在该夹
      fapi('setfolder', { id: id, folder_id: fid }, function (r) {
        if (!r.ok) { toast(r.err || '移动失败'); setTimeout(function () { location.reload(); }, 500); return; }
        toast(fid === '0' ? '已移出至未归类' : '已移入文件夹');
        setTimeout(function () { location.reload(); }, 500);   // 整页刷新按服务器真实状态重渲染
      });
    });
  });
})();


// ===== 单图分享「创建分享」按钮（原内联 onclick，CSP 下改绑定） =====
(function () {
  var b = document.getElementById('shareGoBtn');
  if (b) b.addEventListener('click', function () { if (typeof doShare === 'function') doShare(); });
})();

// ===== 顶栏 API 面板开关 =====
(function () {
  var btn = document.getElementById('apiToggle');
  if (btn) btn.addEventListener('click', function () {
    var panel = document.getElementById('apiPanel');
    if (!panel) return;
    var on = panel.style.display !== 'none';
    panel.style.display = on ? 'none' : '';
    btn.textContent = on ? '🔑 API' : '✕ 收起';
  });
})();

// ===== 弹窗关闭统一委托（CSP 禁内联 onclick，全部改此处绑定） =====
document.addEventListener('click', function (e) {
  var xc = e.target.closest && e.target.closest('[data-close]');
  if (xc) {
    var dlg = document.getElementById(xc.getAttribute('data-close'));
    if (dlg) dlg.style.display = 'none';
    return;
  }
  var ov = e.target.closest && e.target.closest('.modal-mask[data-overlay]');
  if (ov && e.target === ov) ov.style.display = 'none';
});

// ===== 文件夹分享弹窗逻辑（打开即回显当前分享状态；链接主/优选双行带复制） =====
(function () {
  var goBtn = document.getElementById('fsGo');
  if (!goBtn) return;

  function fmtUntil(until) {
    if (!until) return '永久有效';
    var left = until - Math.floor(Date.now() / 1000);
    if (left <= 0) return '已过期';
    var d = Math.floor(left / 86400), h = Math.floor((left % 86400) / 3600);
    return d > 0 ? '剩 ' + d + ' 天 ' + h + ' 小时' : '剩 ' + Math.max(1, h) + ' 小时';
  }

  function renderState(d) {
    var box = document.getElementById('fsResult');
    var untilEl = document.getElementById('fsUntil');
    if (d.shared) {
      document.getElementById('fsUrlMain').value = d.url;
      document.getElementById('fsUrlPref').value = d.url2;
      untilEl.textContent = '当前状态：已分享 · ' + fmtUntil(d.until);
      box.style.display = 'block';
    } else {
      box.style.display = 'none';
      document.getElementById('fsUrlMain').value = '';
      document.getElementById('fsUrlPref').value = '';
      untilEl.textContent = '当前状态：未分享';
    }
  }

  function fetchInfo() {
    var fid = window.__fsFid;
    if (!fid) return;
    fetch(API_MAIN + '?action=folder_share_info&csrf_token=' + encodeURIComponent(CSRF) + '&id=' + fid, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) renderState(d); });
  }

  // f-share 点击打开弹窗（dashboard.js 文件夹委托里设置 __fsFid 后调用）
  window.__openFolderShare = function () { fetchInfo(); };

  goBtn.addEventListener('click', function () {
    var fid = window.__fsFid;
    if (!fid) return;
    var hours = document.getElementById('fsDur').value;
    var fd = new FormData();
    fd.append('action', 'folder_share');
    fd.append('csrf_token', CSRF);
    fd.append('id', fid);
    fd.append('hours', hours);
    fetch(API_MAIN, { method: 'POST', body: fd, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) { toast(r.err || '操作失败'); return; }
        if (r.cancelled) {
          renderState({ shared: 0, url: '', url2: '', until: 0 });
          toast('已撤销文件夹分享（链接立即失效）');
          return;
        }
        renderState({ shared: 1, url: r.url, url2: r.url2, until: r.until });
        toast('分享链接已更新' + (r.until === 0 ? '（永久）' : ''));
        if (window.__SPA) window.__SPA.refreshFolders();
      })
      .catch(function () { toast('网络错误，请重试'); });
  });

  // 复制按钮（委托）
  document.addEventListener('click', function (e) {
    var cp = e.target.closest && e.target.closest('#fsResult .copy-btn[data-copy]');
    if (!cp) return;
    var inp = document.getElementById(cp.getAttribute('data-copy'));
    if (!inp) return;
    inp.select();
    try { document.execCommand('copy'); toast('已复制'); } catch (e) {}
  });
})();

// ===== 单击图片 = 打开大图（选择模式中由 selection 拦截为加选，不会到这里） =====// ===== 单击图片 = 打开大图（选择模式中由 selection 拦截为加选，不会到这里） =====
document.addEventListener('click', function (e) {
  if (window.PixelSelection && window.PixelSelection.isActive()) return;
  var pick = e.target.closest ? e.target.closest('.pick, .pickbox') : null;
  if (pick) return;   // 勾选框由 selection 处理
  var card = e.target.closest ? e.target.closest('.card') : null;
  if (!card) return;
  if (e.target.closest('.ops, .f-act, button, select, a, input')) return;   // 底部按钮原生功能
  var now = Date.now();
  if (card._lastOpen && now - card._lastOpen < 500) return;   // 防手抖双标签
  card._lastOpen = now;
  window.open(BASE + 'view.php?id=' + card.dataset.id + '&u=' + CURRENT_UUID, '_blank');
});

/* ===== Windows 式多选（长按/框选/Ctrl+XCV）注入依赖 ===== */
(function () {
  function initSel() {
    if (!window.PixelSelection) return;
    function fapiRaw(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    return fetch(API_MAIN, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  window.PixelSelection.init({
    grid: document.querySelector('.grid'),
    getCurrentFolderId: function () { return window.__SPA ? window.__SPA.getCur() : CUR_FOLDER; },   // 实时读 SPA 状态（旧全局是页面加载时的死值）
    showToast: function (m) { toast(m); },
    isUiLocked: function () {
      return ['shareDlg', 'shareBatchDlg'].some(function (id) {
        var el = document.getElementById(id);
        return el && el.style.display !== 'none';
      });
    },
    api: fapiRaw,
    folderApi: fapiRaw,
    openDetail: function (id) { window.open(BASE + 'view.php?id=' + id + '&u=' + CURRENT_UUID, '_blank'); },
    bulkShare: function (ids) { if (ids && ids.length) { window.__bulkShareIds(ids); } },
    bulkZip: function (ids) { if (ids && ids.length) { window.__bulkZipIds(ids); } },
    delbatch: function (ids) {
      var fd = new FormData();
      fd.append('action', 'delbatch');
      fd.append('csrf_token', CSRF);
      ids.forEach(function (id) { fd.append('ids[]', id); });
      return fetch(API_MAIN, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    refreshAll: function () {
      // 用户定稿：写操作（粘贴/删除）后整页刷新，按服务器真实状态重渲染（URL 已带 ?folder=，留在当前夹）
      location.reload();
    }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSel);
  else initSel();
})();
