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
    <link rel="stylesheet" href="css/pixel.css?v=43">
</head>
<body>
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
                    <label class="form-label" for="email">邮箱</label>
                    <input type="email" id="email" class="form-input" placeholder="主流邮箱或 @naxid.top" required autocomplete="email">
                    <p class="md-hint">支持 QQ / Gmail / Outlook / 163 等主流邮箱及 @naxid.top，需接收验证码</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="captcha">人机验证</label>
                    <div class="code-row">
                        <input type="text" id="captcha" class="form-input" placeholder="图中字符" required maxlength="4" autocomplete="off" spellcheck="false">
                        <img id="captchaImg" src="captcha.php" alt="captcha" title="点击刷新" class="cap-img">
                    </div>
                    <p class="md-hint">看不清？点击图片换一张</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="code">邮箱验证码</label>
                    <div class="code-row">
                        <input type="text" id="code" class="form-input" placeholder="6 位数字" required maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                        <button type="button" class="btn btn-outline btn-send-code" data-email-id="email" data-purpose="register" data-captcha-id="captcha">发送验证码</button>
                    </div>
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
