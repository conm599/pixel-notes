/**
 * Pixel Notes - 登录/注册页外置脚本
 * 支持：密码登录 / 验证码登录 / 找回密码 / 验证码注册
 */
(function () {
  'use strict';

  var errorDiv = document.getElementById('errorMsg');
  var successDiv = document.getElementById('successMsg');

  function showMsg(el, text) {
    if (!el) return;
    el.textContent = text;
    el.style.display = 'block';
  }
  function clearMsgs() {
    if (errorDiv) { errorDiv.style.display = 'none'; }
    if (successDiv) { successDiv.style.display = 'none'; }
  }

  async function callAuth(data) {
    var resp = await fetch('api/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    var raw = await resp.text();
    var result;
    try { result = JSON.parse(raw); }
    catch (e) { throw new Error('服务器返回异常 (HTTP ' + resp.status + ')'); }
    return result;
  }

  // ---------- 面板切换（登录页） ----------
  function switchPanel(name) {
    clearMsgs();
    document.querySelectorAll('.auth-panel').forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-panel') === name);
    });
    document.querySelectorAll('.auth-tab').forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-panel') === name);
    });
  }
  document.querySelectorAll('.auth-tab').forEach(function (t) {
    t.addEventListener('click', function () { switchPanel(t.getAttribute('data-panel')); });
  });
  var gotoReset = document.getElementById('gotoReset');
  if (gotoReset) gotoReset.addEventListener('click', function (e) { e.preventDefault(); switchPanel('reset'); });
  var backLogin = document.getElementById('backLogin');
  if (backLogin) backLogin.addEventListener('click', function (e) { e.preventDefault(); switchPanel('pass'); });

  // ---------- 图片验证码：点击刷新 ----------
  var captchaImg = document.getElementById('captchaImg');
  if (captchaImg) {
    captchaImg.addEventListener('click', function () {
      captchaImg.src = 'captcha.php?t=' + Date.now();
    });
  }
  function refreshCaptcha() {
    if (captchaImg) captchaImg.src = 'captcha.php?t=' + Date.now();
    var ci = document.getElementById('captcha');
    if (ci) ci.value = '';
  }

  // ---------- 发送验证码（带 60 秒倒计时） ----------
  document.querySelectorAll('.btn-send-code').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var emailId = btn.getAttribute('data-email-id');
      var purpose = btn.getAttribute('data-purpose');
      var captchaId = btn.getAttribute('data-captcha-id');
      var emailInput = document.getElementById(emailId);
      if (!emailInput || !emailInput.value.trim()) {
        clearMsgs();
        showMsg(errorDiv, '❌ 请先填写邮箱地址');
        return;
      }
      var payload = { action: 'sendcode', email: emailInput.value.trim(), purpose: purpose };
      // 注册用途需先通过图片人机验证
      if (captchaId) {
        var ci = document.getElementById(captchaId);
        if (!ci || !ci.value.trim()) {
          clearMsgs();
          showMsg(errorDiv, '❌ 请先输入图片验证码（点图片可换一张）');
          return;
        }
        payload.captcha = ci.value.trim();
      }
      btn.disabled = true;
      var old = btn.textContent;
      btn.textContent = '发送中...';
      try {
        var r = await callAuth(payload);
        clearMsgs();
        if (r.success) {
          showMsg(successDiv, '✅ ' + (r.message || '验证码已发送'));
          refreshCaptcha(); // 图片码已消耗，换新图备用（再次发码需重新输入）
          var left = 60;
          btn.textContent = left + 's';
          var timer = setInterval(function () {
            left--;
            if (left <= 0) { clearInterval(timer); btn.disabled = false; btn.textContent = old; }
            else { btn.textContent = left + 's'; }
          }, 1000);
        } else {
          btn.disabled = false;
          btn.textContent = old;
          showMsg(errorDiv, '❌ ' + (r.message || '发送失败'));
          if (r.captcha_failed) refreshCaptcha(); // 图片码错误：换图重输
        }
      } catch (e) {
        btn.disabled = false;
        btn.textContent = old;
        clearMsgs();
        showMsg(errorDiv, '❌ ' + (e.message || '网络错误'));
      }
    });
  });

  // ---------- 提交：成功默认跳转，resetpass 完成后回登录面板 ----------
  async function submitForm(data, opts) {
    clearMsgs();
    try {
      var r = await callAuth(data);
      if (r.success) {
        if (opts && opts.onSuccess) {
          opts.onSuccess(r);
        } else {
          showMsg(successDiv, '✅ ' + r.message + ' 正在跳转...');
          setTimeout(function () { window.location.href = 'index.php'; }, 800);
        }
      } else {
        showMsg(errorDiv, '❌ ' + (r.message || '操作失败'));
      }
    } catch (e) {
      showMsg(errorDiv, '❌ ' + (e.message || '网络错误，请稍后再试'));
    }
  }

  // 注册页
  var registerForm = document.getElementById('registerForm');
  if (registerForm) {
    registerForm.addEventListener('submit', function (e) {
      e.preventDefault();
      submitForm({
        action: 'register',
        username: document.getElementById('username').value.trim(),
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
        code: document.getElementById('code').value.trim()
      });
    });
  }

  // 登录页：密码登录
  var loginForm = document.getElementById('loginForm');
  if (loginForm) {
    loginForm.addEventListener('submit', function (e) {
      e.preventDefault();
      submitForm({
        action: 'login',
        login: document.getElementById('login').value.trim(),
        password: document.getElementById('password').value
      });
    });
  }

  // 登录页：验证码登录
  var codeLoginForm = document.getElementById('codeLoginForm');
  if (codeLoginForm) {
    codeLoginForm.addEventListener('submit', function (e) {
      e.preventDefault();
      submitForm({
        action: 'logincode',
        email: document.getElementById('codeLoginEmail').value.trim(),
        code: document.getElementById('codeLoginCode').value.trim()
      });
    });
  }

  // 登录页：找回密码
  var resetForm = document.getElementById('resetForm');
  if (resetForm) {
    resetForm.addEventListener('submit', function (e) {
      e.preventDefault();
      submitForm({
        action: 'resetpass',
        email: document.getElementById('resetEmail').value.trim(),
        code: document.getElementById('resetCode').value.trim(),
        password: document.getElementById('resetPass').value
      }, {
        onSuccess: function (r) {
          showMsg(successDiv, '✅ ' + r.message);
          resetForm.reset();
          setTimeout(function () { switchPanel('pass'); }, 1500);
        }
      });
    });
  }
})();
