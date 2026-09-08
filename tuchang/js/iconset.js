/* Pixel Suite 图标风格切换 v1 —— <html data-iconset> + 父域 Cookie（pn_iconset，1 年），
   纯前端实现零 SQL；head 同步加载，首帧前生效无闪跳。改本文件须 bump ?v= */
(function () {
  'use strict';
  var KEY = 'pn_iconset';
  var VALS = ['mix', 'v1', 'v2'];
  var LABELS = { mix: '混合', v1: '经典实心', v2: '线稿' };

  function get() {
    var m = document.cookie.match(/(?:^|;\s*)pn_iconset=(v1|v2|mix)(?:;|$)/);
    return m ? m[1] : 'mix';
  }
  function parentDomain() {
    var h = location.hostname;
    if (/^\d{1,3}(\.\d{1,3}){3}$/.test(h) || h === 'localhost') return '';
    var p = h.split('.');
    return p.length <= 2 ? '' : '.' + p[p.length - 2] + '.' + p[p.length - 1];
  }
  function save(v) {
    var c = KEY + '=' + v + '; path=/; max-age=31536000; SameSite=Lax';
    var pd = parentDomain();
    if (pd) c += '; domain=' + pd;
    if (location.protocol === 'https:') c += '; Secure';
    document.cookie = c;
  }
  function apply(v) {
    document.documentElement.setAttribute('data-iconset', v);
    var btn = document.getElementById('btnIconset');
    if (btn) btn.textContent = '图标风格：' + LABELS[v];
  }
  function cycle() {
    var next = VALS[(VALS.indexOf(get()) + 1) % VALS.length];
    save(next);
    apply(next);
  }
  function wire() {
    var btn = document.getElementById('btnIconset');
    if (btn) btn.addEventListener('click', cycle);
  }
  apply(get());
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
  window.PixelIconset = {
    get: get,
    set: function (v) { if (VALS.indexOf(v) >= 0) { save(v); apply(v); } }
  };
})();
