// 陶瓦图床 · view 图片详情逻辑（自 view.php 拆分）
// CSRF / IMGID / IMGNAME 由页面内 bootstrap 提供
function doRename() {
  var newName = document.getElementById('renameInput').value.trim();
  if (!newName || newName === IMGNAME) { toast('名称未变化'); return; }
  var fd = new FormData();
  fd.append('action', 'rename');
  fd.append('csrf_token', CSRF);
  fd.append('id', IMGID);
  fd.append('name', newName);
  fetch('api.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (r) {
      if (r.ok) {
        IMGNAME = r.name;
        document.querySelector('.v-name').textContent = r.name;
        document.title = r.name + ' - 陶瓦图床';
        toast('已重命名');
      } else toast(r.err || '重命名失败');
    });
}
function toast(msg) {
  var t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(t._h);
  t._h = setTimeout(function () { t.classList.remove('show'); }, 2200);
}
function act(action, extra) {
  var fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', CSRF);
  fd.append('id', IMGID);
  if (extra) fd.append(extra[0], extra[1]);
  return fetch('api.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
}
function doShare() {
  var dur = document.getElementById('durSel').value;
  act('share', ['duration', dur]).then(function (r) {
    if (r.ok) {
      var box = document.getElementById('shareBox');
      var same = r.url === r.url2;
      var html = same ? '' : '<div class="v-url-label">优选线路（推荐外发）</div>';
      html += '<input class="v-share-url" id="shareUrl" readonly value="' + (same ? r.url : r.url2) + '">';
      if (!same) html += '<div class="v-url-label">当前域名</div><input class="v-share-url" id="shareUrlAlt" readonly value="' + r.url + '">';
      html += '<div class="v-remain" id="shareRemain">' + (r.until > 0 ? '分享剩余 <b>' + Math.max(1, Math.ceil((r.until - Date.now() / 1000) / 60)) + '</b> 分钟' : '永久分享') + '</div>' +
        '<button class="v-btn" onclick="copyShare()">复制分享链接</button>' +
        '<button class="v-btn stop" onclick="doUnshare()">停止分享</button>';
      box.innerHTML = html;
      toast('分享已创建');
    } else toast(r.err || '创建失败');
  });
}
function copyShare() {
  var inp = document.getElementById('shareUrl');
  inp.select();
  try { document.execCommand('copy'); toast('链接已复制'); } catch (e) { toast('复制失败'); }
}
function doUnshare() {
  if (!confirm('停止分享？链接将立即失效')) return;
  act('unshare').then(function (r) {
    if (r.ok) {
      var box = document.getElementById('shareBox');
      box.innerHTML = '<select class="share-dur" id="durSel">' +
        '<option value="3600">1 小时</option><option value="86400">1 天</option>' +
        '<option value="604800">7 天</option><option value="2592000">30 天</option>' +
        '<option value="0">永久有效</option></select>' +
        '<button class="v-btn" onclick="doShare()">创建分享链接</button>';
      toast('已停止分享');
    } else toast(r.err || '失败');
  });
}
document.getElementById('expSel').addEventListener('change', function () {
  act('setexpire', ['expire', this.value]).then(function (r) {
    toast(r.ok ? '过期时间已更新' : (r.err || '失败'));
    if (r.ok) {
      var remain = document.getElementById('expRemain');
      var v = document.getElementById('expSel').value;
      remain.innerHTML = v == 0 ? '永久保存' : '已设置过期时间（到期自动删除）';
    }
  });
});
