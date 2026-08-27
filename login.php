<?php
/**
 * 用户登录页面（邮箱+密码 / 邮箱+验证码 双模式，支持找回密码）
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
    <title>登录 - Pixel Notes</title>
    <link rel="stylesheet" href="css/pixel.css?v=40">
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <h1>🎮<br>PIXEL NOTES</h1>
            <p class="subtitle">欢迎回来，冒险者！</p>

            <div class="auth-tabs">
                <button type="button" class="auth-tab active" data-panel="pass">🔐 密码登录</button>
                <button type="button" class="auth-tab" data-panel="code">📧 验证码登录</button>
            </div>

            <div id="errorMsg" class="error-msg" style="display:none;"></div>
            <div id="successMsg" class="success-msg" style="display:none;"></div>

            <!-- 面板一：密码登录 -->
            <form id="loginForm" class="auth-panel active" data-panel="pass">
                <div class="form-group">
                    <label class="form-label" for="login">邮箱 / 用户名</label>
                    <input type="text" id="login" class="form-input" placeholder="邮箱或用户名" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">密码</label>
                    <input type="password" id="password" class="form-input" placeholder="输入密码" required autocomplete="current-password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">▶ 登录</button>
                </div>
                <p class="auth-link" style="text-align:right;"><a href="#" id="gotoReset">忘记密码？</a></p>
            </form>

            <!-- 面板二：验证码登录 -->
            <form id="codeLoginForm" class="auth-panel" data-panel="code">
                <div class="form-group">
                    <label class="form-label" for="codeLoginEmail">邮箱</label>
                    <input type="email" id="codeLoginEmail" class="form-input" placeholder="your@email.com" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label class="form-label" for="codeLoginCode">验证码</label>
                    <div class="code-row">
                        <input type="text" id="codeLoginCode" class="form-input" placeholder="6 位数字" required maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                        <button type="button" class="btn btn-outline btn-send-code" data-email-id="codeLoginEmail" data-purpose="login">发送验证码</button>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">▶ 验证并登录</button>
                </div>
            </form>

            <!-- 面板三：找回密码 -->
            <form id="resetForm" class="auth-panel" data-panel="reset">
                <p class="md-hint" style="margin-bottom:14px;">📨 重置密码：输入注册邮箱 → 收验证码 → 设置新密码</p>
                <div class="form-group">
                    <label class="form-label" for="resetEmail">注册邮箱</label>
                    <input type="email" id="resetEmail" class="form-input" placeholder="your@email.com" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label class="form-label" for="resetCode">验证码</label>
                    <div class="code-row">
                        <input type="text" id="resetCode" class="form-input" placeholder="6 位数字" required maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                        <button type="button" class="btn btn-outline btn-send-code" data-email-id="resetEmail" data-purpose="reset">发送验证码</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="resetPass">新密码</label>
                    <input type="password" id="resetPass" class="form-input" placeholder="至少 8 个字符" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">🔑 重置密码</button>
                </div>
                <p class="auth-link" style="text-align:center;"><a href="#" id="backLogin">← 返回登录</a></p>
            </form>

            <p class="auth-link">还没有账户？<a href="register.php">点此注册</a></p>
        </div>
    </div>

    <script src="js/auth.js?v=9"></script>
</body>
</html>
