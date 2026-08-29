<?php
/**
 * 用户注册页面（邮箱验证码注册）
 */
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();
header('Cache-Control: no-store, must-revalidate');
startSecureSession();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - Pixel Notes</title>
    <link rel="stylesheet" href="css/pixel.css?v=54">
<style>
.demo-banner { background: #2a2200; border-bottom: 2px solid #ffcc00; color: #ffcc00; font-size: 12px; padding: 8px 14px; text-align: center; line-height: 1.8; font-family: monospace, sans-serif; }
.demo-banner a { color: #4af0ff; }
</style>
</head>
<body>
<div class="demo-banner">⚠️ 公开演示环境（DEMO）· 请勿投入生产使用 · 正式版请从官方渠道获取 · 所有 AI 相关功能均未配置、不可用 · 请勿填写真实隐私信息（邮箱 / API Key / 常用密码）</div>
    <div class="auth-container">
        <div class="auth-box">
            <h1>🎮<br>PIXEL NOTES</h1>
            <p class="subtitle">开始你的冒险！</p>

            <div id="errorMsg" class="error-msg" style="display:none;"></div>
            <div id="successMsg" class="success-msg" style="display:none;"></div>

            <form id="registerForm">
                <div class="form-group">
                    <label class="form-label" for="username">用户名</label>
                    <input type="text" id="username" class="form-input" placeholder="2-30 个字符" required autocomplete="username">
                </div>
                <div class="form-group">
                    <p class="md-hint" style="color:#ffcc00;">⚡ 演示模式：只需用户名和密码即可注册，无需邮箱验证。<br>⚠️ 请勿填写任何真实隐私信息（密码请勿与你在其他网站使用的相同）。</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">密码</label>
                    <input type="password" id="password" class="form-input" placeholder="至少 8 个字符" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">🎉 创建账户</button>
                </div>
            </form>
            <p class="auth-link">已有账户？<a href="login.php">点此登录</a></p>
        </div>
    </div>

    <script src="js/auth.js?v=9"></script>
</body>
</html>
