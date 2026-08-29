<?php
/**
 * 主页面 - 便签面板
 */
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();
header('Cache-Control: no-store, must-revalidate');
startSecureSession();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pixel Notes - 我的便签</title>
    <link rel="stylesheet" href="css/pixel.css?v=54">
<style>
.demo-banner { background: #2a2200; border-bottom: 2px solid #ffcc00; color: #ffcc00; font-size: 12px; padding: 8px 14px; text-align: center; line-height: 1.8; font-family: monospace, sans-serif; }
.demo-banner a { color: #4af0ff; }
</style>
</head>
<body>
<div class="demo-banner">⚠️ 公开演示环境（DEMO）· 请勿投入生产使用 · 正式版请从官方渠道获取 · 所有 AI 相关功能均未配置、不可用 · 请勿填写真实隐私信息（邮箱 / API Key / 常用密码）</div>
    <!-- 顶部导航 -->
    <nav class="navbar">
        <a href="index.php" class="navbar-brand">
            <span class="icon">🎮</span> PIXEL NOTES
        </a>
        <div class="navbar-user">
            <span>👤 <?= htmlspecialchars($_SESSION['username']) ?></span>
            <?php if (isAdminUser()): ?>
                <a href="admin.php" class="btn btn-outline btn-xs">⚙️ 管理</a>
            <?php endif; ?>
            <a href="tts.php" class="btn btn-outline btn-xs">🔊 朗读</a>
            <div class="nav-settings-wrap">
                <button type="button" id="btnSettings" class="btn btn-outline btn-xs" title="设置">⚙️ 设置</button>
                <div id="settingsMenu" class="settings-menu" style="display:none;">
                    <button type="button" id="btnTutorial" class="settings-menu-item">📖 新手教程</button>
                    <button type="button" id="btnMdColors" class="settings-menu-item">🎨 渲染颜色</button>
                    <a href="https://github.com/conm599/pixel-notes" target="_blank" rel="noopener" class="settings-menu-item">⭐ GitHub 开源地址</a>
                    <button type="button" id="btnChangePass" class="settings-menu-item">🔑 更改密码</button>
                    <button type="button" id="btnDeleteAccount" class="settings-menu-item settings-menu-danger">🗑 注销账号</button>
                </div>
            </div>
            <form method="post" action="logout.php" style="display:inline;margin:0;">
                <button type="submit" class="btn btn-outline btn-xs">退出</button>
            </form>
        </div>
    </nav>

    <!-- 主内容区 -->
    <div class="main-container">
        <!-- 工具栏 -->
        <div class="toolbar">
            <div class="toolbar-left">
                <span class="toolbar-title" id="toolbarTitle">📝 我的便签</span>
                <span class="folder-crumb" id="folderCrumb"></span>
            </div>
            <div class="toolbar-actions">
                <div class="search-box" id="searchBox">
                    <input type="text" id="searchInput" class="search-input" placeholder="🔍 搜索便签 / 文件夹..." autocomplete="off">
                    <div id="searchPanel" class="search-panel" style="display:none"></div>
                </div>
                <button id="btnAiOrganize" class="btn btn-outline btn-sm">✨ AI 整理</button>
                <button id="btnNewFolder" class="btn btn-outline btn-sm">📁 新建文件夹</button>
                <button id="btnNewNote" class="btn btn-primary btn-sm">＋ 新建便签</button>
            </div>
        </div>

        <!-- 便签编辑器（新建 / 编辑 共用） -->
        <div id="newNoteForm" class="new-note-form" style="display:none;">
            <div id="editorMode" class="editor-mode">🆕 新建便签</div>
            <div class="form-group">
                <label class="form-label">标题</label>
                <input type="text" id="newTitle" class="form-input" placeholder="便签标题..." maxlength="200">
            </div>
            <div class="form-group">
                <label class="form-label">内容（支持 Markdown）</label>
                <div id="newToolbar" class="md-toolbar"></div>
                <textarea id="newContent" class="form-input" placeholder="写点什么... 支持 Markdown" rows="5"></textarea>
                <div id="newPreview" class="md-preview md-body" style="display:none;"></div>
                <div class="md-hint">Ctrl+Enter 保存 · Esc 关闭 · # 标题 · **加粗** · `代码` · - 列表 · > 引用 · [链接](https://...)</div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">颜色</label>
                    <div class="color-picker" id="newColorPicker">
                        <span class="color-dot yellow active" data-color="yellow"></span>
                        <span class="color-dot pink" data-color="pink"></span>
                        <span class="color-dot blue" data-color="blue"></span>
                        <span class="color-dot green" data-color="green"></span>
                        <span class="color-dot purple" data-color="purple"></span>
                        <span class="color-dot orange" data-color="orange"></span>
                    </div>
                </div>
                <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">
                    <button id="btnAiEdit" class="btn btn-outline btn-sm btn-ai" type="button">🤖 AI</button>
                    <button id="btnPreviewNew" class="btn btn-outline btn-sm" type="button">👁 预览</button>
                    <button id="btnSaveNew" class="btn btn-primary btn-sm" type="button">💾 保存</button>
                    <button id="btnCancelNew" class="btn btn-danger btn-sm" type="button">取消</button>
                </div>
            </div>
        </div>

        <!-- 便签网格 -->
        <div id="notesGrid" class="notes-grid">
            <div class="loading">加载中...</div>
        </div>
    </div>

    <!-- Toast 提示 -->
    <div id="toast" class="toast" style="display:none;"></div>

    <script src="js/md.js?v=35"></script>
    <script src="js/Sortable.min.js"></script>
    <script src="js/ai-direct.js?v=14"></script>
    <script src="js/selection.js?v=5"></script>
    <script src="js/app.js?v=74"></script>
    <script>
    // 演示站：每次进入弹一次提示（DEMO 分支专属）
    (function () {
        var d = document.createElement('div');
        d.style.cssText = 'position:fixed;left:50%;bottom:18px;transform:translateX(-50%);max-width:560px;width:calc(100vw - 32px);z-index:99999;background:#1a1a2e;border:3px solid #ffcc00;box-shadow:4px 4px 0 #000;padding:14px 18px;color:#e8e8f5;font-size:13px;line-height:1.9;';
        d.innerHTML = '<b style="color:#ffcc00;">⚠️ 演示页面，请勿投入生产使用</b><br>'
            + '这是公开演示环境：注册 / 登录门槛已降低，所有 AI 相关功能均未配置、不可用。<br>'
            + '请勿填写真实邮箱、API Key 或常用密码等任何隐私信息。<br>'
            + '生产部署请使用 main 分支';
        var x = document.createElement('button');
        x.textContent = '知道了';
        x.style.cssText = 'margin-top:10px;padding:6px 16px;background:#ffcc00;color:#000;border:0;font-weight:bold;cursor:pointer;font-family:inherit;';
        x.onclick = function () { d.remove(); };
        d.appendChild(x);
        document.body.appendChild(d);
    })();
    </script>
</body>
</html>