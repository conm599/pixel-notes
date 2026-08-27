<?php
/**
 * 退出登录（GET/POST 均彻底销毁会话）
 * 原先仅 POST 销毁会话，GET 只跳转，共用电脑场景下用户会误以为已退出。
 */
require_once __DIR__ . '/config/database.php';

startSecureSession();

$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'],
        isset($p['domain']) ? $p['domain'] : '',
        isset($p['secure']) ? $p['secure'] : false,
        isset($p['httponly']) ? $p['httponly'] : false);
}
session_destroy();
header('Location: login.php');
exit;
