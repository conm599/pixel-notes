// 管理页 - 测试 AI 连通性 + 复制密钥
(function () {
  var btn = document.getElementById('btnTestAi');
  var out = document.getElementById('testResult');
  if (btn) {
    btn.addEventListener('click', async function () {
      btn.disabled = true;
      btn.textContent = '⏳ 测试中...';
      out.textContent = '';
      try {
        var resp = await fetch('api/ai.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          cache: 'no-store',
          body: JSON.stringify({ action: 'test' })
        });
        if (resp.status === 401) { window.location.href = 'login.php'; return; }
        var r = await resp.json();
        out.textContent = r.success ? '✅ ' + (r.message || '连接成功') : '❌ ' + (r.message || '连接失败');
      } catch (e) {
        out.textContent = '❌ 网络错误，无法访问接口';
      }
      btn.disabled = false;
      btn.innerHTML = '<i class="ic ic-plug"></i> 测试连接';
    });
  }

  document.querySelectorAll('.ai-copy-key').forEach(function (b) {
    b.addEventListener('click', function () {
      var key = b.getAttribute('data-key') || '';
      navigator.clipboard.writeText(key).then(function () {
        var old = b.textContent;
        b.textContent = '✅ 已复制';
        setTimeout(function () { b.textContent = old; }, 1200);
      }).catch(function () {
        window.prompt('请手动复制：', key);
      });
    });
  });

  // 密钥管理：危险操作二次确认（外置脚本实现，内联 onsubmit 会被 CSP 拦截）
  document.querySelectorAll('form.key-confirm').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var msg = f.getAttribute('data-confirm') || '确定执行该操作？';
      if (!window.confirm(msg)) e.preventDefault();
    });
  });

  // 测试发信
  var mailBtn = document.getElementById('btnTestMail');
  var mailOut = document.getElementById('mailResult');
  var mailTo = document.getElementById('testMailTo');
  if (mailBtn) {
    mailBtn.addEventListener('click', async function () {
      var to = mailTo ? mailTo.value.trim() : '';
      if (!to) { mailOut.textContent = '⚠️ 请先填写测试收件邮箱'; return; }
      mailBtn.disabled = true;
      mailBtn.textContent = '⏳ 发送中...';
      mailOut.textContent = '';
      try {
        var resp = await fetch('api/auth.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          cache: 'no-store',
          body: JSON.stringify({ action: 'testmail', to: to })
        });
        if (resp.status === 401) { window.location.href = 'login.php'; return; }
        var r = await resp.json();
        mailOut.textContent = r.success ? '✅ ' + (r.message || '已发送') : '❌ ' + (r.message || '发送失败');
      } catch (e) {
        mailOut.textContent = '❌ 网络错误';
      }
      mailBtn.disabled = false;
      mailBtn.innerHTML = '<i class="ic ic-send"></i> 测试发信';
    });
  }

  // ===== 用户搜索（AI 密钥「推送到账号」选择器，2026-09-27）=====
  var bindUser = document.getElementById('bindUser');
  var sug = document.getElementById('bindUserSug');
  if (bindUser && sug) {
    var sugTimer = 0;
    var hideSug = function () { sug.style.display = 'none'; sug.innerHTML = ''; };
    bindUser.addEventListener('input', function () {
      clearTimeout(sugTimer);
      var q = bindUser.value.trim();
      if (q === '') { hideSug(); return; }
      sugTimer = setTimeout(function () {
        fetch('admin.php?op=search_users&q=' + encodeURIComponent(q), { credentials: 'include', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d || !d.success || !d.users || !d.users.length) { hideSug(); return; }
            sug.innerHTML = '';
            d.users.forEach(function (u) {
              var it = document.createElement('div');
              it.style.cssText = 'padding:8px 10px;cursor:pointer;font-size:13px;';
              it.innerHTML = '<b>' + u.username + '</b> <span style="opacity:.6">' + u.email + '</span>';
              it.addEventListener('mouseenter', function () { it.style.background = 'rgba(255,255,255,.08)'; });
              it.addEventListener('mouseleave', function () { it.style.background = ''; });
              // mousedown 抢在 input blur 之前，避免候选框先消失
              it.addEventListener('mousedown', function (e) {
                e.preventDefault();
                bindUser.value = u.username;
                hideSug();
              });
              sug.appendChild(it);
            });
            sug.style.display = 'block';
          })
          .catch(function () { /* 静默 */ });
      }, 250);
    });
    bindUser.addEventListener('blur', function () { setTimeout(hideSug, 150); });
  }

  // ===== 用户列表过滤（客户端本地过滤，只作用于两张用户表，2026-09-27）=====
  var uf = document.getElementById('userFilter');
  if (uf) {
    var tables = ['userTable', 'legacyUserTable']
      .map(function (id) { return document.getElementById(id); })
      .filter(Boolean);
    uf.addEventListener('input', function () {
      var q = uf.value.trim().toLowerCase();
      tables.forEach(function (tb) {
        var rows = tb.querySelectorAll('tbody tr');
        for (var i = 0; i < rows.length; i++) {
          rows[i].style.display = (!q || rows[i].textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
        }
      });
    });
  }
})();
