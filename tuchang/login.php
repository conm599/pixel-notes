<?php
// 账号统一：注册/登录统一走便签主站
$__suite = (array)@include(__DIR__ . '/../suite-config.php');
$__dst = (isset($__suite['bianqian_url']) && $__suite['bianqian_url'] !== '') ? rtrim($__suite['bianqian_url'], '/') : null;
if ($__dst === null) {
    $h = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
    $__dst = preg_match('/^(bianqian|tuchang)\.([a-z0-9.-]+)$/', $h, $m) ? 'bianqian.' . $m[2] : 'bianqian.naxid.top';
}
header('Location: ' . $__dst . '/login.php', true, 302);
exit;
