<?php
// ================= 陶瓦图床 · 入口 =================
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

// 一次性清理旧留言板残留文件
foreach (array('data/msg.php', 'data/grate.php', 'data/rate.php', 'data/count.php', 'data/cache_mc.php',
               'msg.json', 'data/msg.json', 'data/global_rate.json', 'data/count.txt') as $old) {
    if (file_exists(__DIR__ . '/' . $old)) @unlink(__DIR__ . '/' . $old);
}

if (is_logged_in()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
