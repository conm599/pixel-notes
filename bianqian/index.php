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
    <link rel="stylesheet" href="css/pixel.css?v=60">
    <link rel="stylesheet" href="css/icons.css?v=2">
    <script src="js/iconset.js?v=1"></script>
    <link rel="icon" href="favicon.ico">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
</head>
<body>
    <!-- 顶部导航 -->
    <nav class="navbar">
        <a href="index.php" class="navbar-brand">
            <span class="icon">🎮</span> PIXEL NOTES
        </a>
        <div class="navbar-user">
            <span><i class="ic ic-user"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
            <?php if (isAdminUser()): ?>
                <a href="admin.php" class="btn btn-outline btn-xs"><i class="ic ic-gear"></i> 管理</a>
            <?php endif; ?>
            <a href="tts.php" class="btn btn-outline btn-xs"><i class="ic ic-speaker"></i> 朗读</a>
            <a href="<?php echo htmlspecialchars(siblingUrl('tuchang', '/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline btn-xs" target="_blank" rel="noopener"><i class="ic ic-image"></i> 图床</a>
            <div class="nav-settings-wrap">
                <button type="button" id="btnSettings" class="btn btn-outline btn-xs" title="设置"><i class="ic ic-gear"></i> 设置</button>
                <div id="settingsMenu" class="settings-menu" style="display:none;">
                    <button type="button" id="btnTutorial" class="settings-menu-item"><i class="ic ic-book"></i> 新手教程</button>
                    <button type="button" id="btnImgBridge" class="settings-menu-item"><i class="ic ic-image"></i> 图床图片</button>
                    <button type="button" id="btnMdColors" class="settings-menu-item"><i class="ic ic-palette"></i> 渲染颜色</button>
                    <button type="button" id="btnIconset" class="settings-menu-item">图标风格：混合</button>
                    <a href="https://github.com/conm599/pixel-notes" target="_blank" rel="noopener" class="settings-menu-item"><i class="ic ic-star"></i> GitHub 开源地址</a>
                    <button type="button" id="btnChangePass" class="settings-menu-item"><i class="ic ic-key"></i> 更改密码</button>
                    <button type="button" id="btnDeleteAccount" class="settings-menu-item settings-menu-danger"><i class="ic ic-trash"></i> 注销账号</button>
                </div>
            </div>
            <form method="post" action="logout.php" style="display:inline;margin:0;">
                <button type="submit" class="btn btn-outline btn-xs"><i class="ic ic-exit"></i> 退出</button>
            </form>
        </div>
    </nav>

    <!-- 主内容区 -->
    <div class="main-container">
        <!-- 工具栏 -->
        <div class="toolbar">
            <div class="toolbar-left">
                <span class="toolbar-title" id="toolbarTitle"><i class="ic ic-note"></i> 我的便签</span>
                <span class="folder-crumb" id="folderCrumb"></span>
            </div>
            <div class="toolbar-actions">
                <div class="search-box" id="searchBox">
                    <input type="text" id="searchInput" class="search-input" placeholder="🔍 搜索便签 / 文件夹..." autocomplete="off">
                    <div id="searchPanel" class="search-panel" style="display:none"></div>
                </div>
                <button id="btnAiOrganize" class="btn btn-outline btn-sm"><i class="ic ic-sparkle"></i> AI 整理</button>
                <button id="btnNewFolder" class="btn btn-outline btn-sm"><i class="ic ic-folder"></i> 新建文件夹</button>
                <button id="btnNewNote" class="btn btn-primary btn-sm"><i class="ic ic-plus-pink"></i> 新建便签</button>
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
                    <button id="btnAiEdit" class="btn btn-outline btn-sm btn-ai" type="button"><i class="ic ic-sparkle"></i> AI</button>
                    <button id="btnPreviewNew" class="btn btn-outline btn-sm" type="button"><i class="ic ic-eye"></i> 预览</button>
                    <button id="btnSaveNew" class="btn btn-primary btn-sm" type="button"><i class="ic ic-save-pink"></i> 保存</button>
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

    <script src="js/md.js?v=41"></script>
    <script src="js/tutorial-data.js?v=1"></script>

    <script src="js/Sortable.min.js"></script>
    <script src="js/ai-direct.js?v=16"></script>
    <script src="js/selection.js?v=6"></script>
    <!-- 联动配置以 JSON 数据块注入（CSP script-src 'self' 禁止内联可执行脚本，数据块不执行不受限）；app.js 解析后传给 ImgBridge -->
    <script type="application/json" id="imgBridgeCfg">{"tuchangBase": <?php echo json_encode(siblingUrl('tuchang', '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>,
        "policyVer": <?php echo (int)suite_cfg('img_policy_ver', 1); ?>,
        "policyHtml": <?php echo json_encode('<p>图片将上传到你的图床账户「便签」文件夹：</p><ul><li>上传后自动转为<b>可公开访问</b>的图片链接（拿到链接即可查看）</li><li>图片随便签存续；<b>从便签删除后，图床仍保留 30 天</b>供反悔，到期自动清理</li><li>需要长期保留的图片，请到图床把它改为「永久」或自定义时长</li><li>在图床删除图片后，便签中该处将显示占位图</li><li>单张 ≤10MB（自动压缩为 WebP），占用图床配额</li><li>禁止上传违法违规内容；可在图床随时管理或删除</li></ul>', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>
    }</script>
    <script src="js/imgbridge.js?v=4"></script>
    <script src="js/app.js?v=86"></script>
</body>
</html>