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
      btn.textContent = '🔌 测试连接';
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
      mailBtn.textContent = '📨 测试发信';
    });
  }
})();
