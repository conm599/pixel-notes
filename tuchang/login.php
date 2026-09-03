<?php
// 账号统一：注册/登录统一走便签主站
$h = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
$dst = preg_match('/^(bianqian|tuchang)\.([a-z0-9.-]+)$/', $h, $m) ? 'bianqian.' . $m[2] : 'bianqian.naxid.top';
header('Location: https://' . $dst . '/login.php', true, 302);
exit;
