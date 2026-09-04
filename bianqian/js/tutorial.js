/**
 * 新手教程弹窗：读取 window.TUTORIAL_MD（tools/build-tutorial.js 从 docs 构建生成），
 * 用便签自带的 PixelMD 渲染——教程本身就是 MD 能力的演示。
 */
(function () {
  'use strict';

  function openTutorial() {
    if (document.getElementById('tutorialOverlay')) return;
    if (!window.TUTORIAL_MD || !window.PixelMD) return;

    var overlay = mkEl('div', 'md-modal-overlay');
    overlay.id = 'tutorialOverlay';
    var modal = mkEl('div', 'md-modal');
    var head = mkEl('div', 'md-modal-head');
    head.appendChild(mkEl('div', 'md-modal-title', '📖 使用教程（从入门到大师）'));
    var closeBtn = mkEl('button', 'md-modal-close', '✕');
    closeBtn.type = 'button';
    head.appendChild(closeBtn);
    modal.appendChild(head);

    var body = mkEl('div', 'md-modal-body');
    var content = mkEl('div', 'note-content md-body md-static');
    content.style.maxHeight = 'none';
    content.style.overflow = 'visible';
    content.style.cursor = 'default';
    content.innerHTML = window.PixelMD.render(window.TUTORIAL_MD);
    body.appendChild(content);
    modal.appendChild(body);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    function close() {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', onKey);
  }

  // 顶栏「📖 教程」按钮（挂在图床入口旁）
  document.addEventListener('DOMContentLoaded', function () {
    var bar = document.querySelector('.top-actions') || document.querySelector('.btn-group') || document.body;
    var btn = mkEl('a', 'btn btn-outline btn-xs');
    btn.href = 'javascript:void(0)';
    btn.textContent = '📖 教程';
    btn.addEventListener('click', openTutorial);
    var ref = document.querySelector('.top-actions a[href*="tuchang"]') ||
              document.querySelector('a[href*="tts.php"]');
    if (ref && ref.parentNode === bar) bar.insertBefore(btn, ref);
    else bar.appendChild(btn);
  });

  // mkEl 兜底（app.js 的工具函数，若未加载则用简化版）
  function mkEl(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text != null) el.textContent = text;
    return el;
  }
})();
