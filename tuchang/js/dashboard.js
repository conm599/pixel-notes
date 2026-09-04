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
  // 双击卡片 = 打开详情页（单击已被 Windows 式多选接管：选中/取消）
  document.addEventListener('dblclick', function (e) {
    var card = e.target.closest ? e.target.closest('.card') : null;
    if (!card) return;
    window.open(BASE + 'view.php?id=' + card.dataset.id + '&u=' + CURRENT_UUID, '_blank');
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

/* ===== Windows 风格图片文件夹（新建/改名/删除/拖拽归类） ===== */
(function () {
  function fapi(action, data, done) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    for (var k in data) fd.append(k, data[k]);
    fetch(API_MAIN.replace(/\/api\.php$/, '') + '/api.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (r) { done(r); })
      .catch(function () { done({ ok: false, err: '网络错误' }); });
  }

  // 新建文件夹
  var newBtn = document.getElementById('folderNew');
  if (newBtn) newBtn.addEventListener('click', function () {
    var name = prompt('文件夹名称（≤60 字）：', '');
    if (name === null) return;
    name = name.trim();
    if (name === '') return toast('名称不能为空');
    fapi('folder_create', { name: name, parent_id: CUR_FOLDER === null ? '' : CUR_FOLDER }, function (r) {
      if (r.ok) {
        toast('已创建「' + r.name + '」');
        if (window.__SPA) window.__SPA.refreshFolders();
        else location.reload();
      }
      else toast(r.err || '创建失败');
    });
  });

  // 改名 / 删除（委托，阻止冒泡避免触发卡片跳转）
  var bar = document.getElementById('folderBar');
  if (bar) bar.addEventListener('click', function (e) {
    var ren = e.target.closest && e.target.closest('.f-ren');
    var del = e.target.closest && e.target.closest('.f-del');
    if (!ren && !del) return;
    e.stopPropagation();
    var card = (ren || del).closest('.folder-card');
    var fid = card.getAttribute('data-fid');
    if (ren) {
      var cur = card.querySelector('.f-name').textContent;
      var name = prompt('重命名为：', cur);
      if (name === null) return;
      name = name.trim();
      if (name === '' || name === cur) return;
      fapi('folder_rename', { id: fid, name: name }, function (r) {
        if (r.ok) {
          toast('已重命名');
          if (window.__SPA) window.__SPA.refreshFolders();
        }
        else toast(r.err || '失败');
      });
    } else {
      if (!confirm('删除该文件夹？夹内图片自动回到「未归类」，图片不会删除。')) return;
      fapi('folder_delete', { id: fid }, function (r) {
        if (r.ok) {
          toast('文件夹已删除，内容已上移一级');
          if (window.__SPA) window.__SPA.refreshFolders();   // SPA 重拉树重渲染（含被删夹的图片归属）
          else location.reload();
        } else toast(r.err || '失败');
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
        if (!r.ok) return toast(r.err || '移动失败');
        toast(fid === '0' ? '已移出至未归类' : '已移入「' + (fc.querySelector('.f-name').textContent) + '」');
        // 非全部视图：卡片已不属于当前视图，直接移除
        if (CUR_FOLDER !== null && cardEl) cardEl.remove();
        // 全部视图：更新卡片归属标记
        if (cardEl) cardEl.setAttribute('data-folder-id', fid);
      });
    });
  });
})();


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
    bulkShare: function (ids) { if (ids && ids.length) { bulkShareIds(ids); } },
    bulkZip: function (ids) { if (ids && ids.length) { bulkZipIds(ids); } },
    delbatch: function (ids) {
      var fd = new FormData();
      fd.append('action', 'delbatch');
      fd.append('csrf_token', CSRF);
      ids.forEach(function (id) { fd.append('ids[]', id); });
      return fetch(API_MAIN, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    },
    refreshAll: function () {
      // SPA 局部刷新：完整重拉元数据（list 很轻；图片本体走缓存不重下），选择/剪贴板内存存活
      if (window.__SPA) window.__SPA.reload();
      else location.reload();
    }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSel);
  else initSel();
})();
