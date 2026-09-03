<?php
// ================= 陶瓦图床 · 图片直链（兼容旧链接；图库内已改用静态路径） =================
define('TAWA_NO_SESSION', true);
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');

// 失效统一输出占位图（notfound_img 定义在 config.php：200 + webp 保证 img 标签渲染）

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { notfound_img(); }

cleanup_expired();

$st = db()->prepare('SELECT id, uid, file, expire_at, size, share_token, share_until FROM img_images WHERE id = ?');
$st->execute(array($id));
$row = $st->fetch();
if (!$row) { notfound_img(); }

// 访问权限：登录者本人的图片直接可看；否则必须带有效分享 token
$self = is_logged_in() && (int)$_SESSION['uid'] === (int)$row['uid'];
if (!$self) {
    if ($row['share_token'] === null || $row['share_token'] === '') {
        notfound_img(); // 未分享/已撤销分享，对外显示占位图
    }
    $t = isset($_GET['t']) ? (string)$_GET['t'] : '';
    if (!hash_equals($row['share_token'], $t)) {
        notfound_img();
    }
    if ($row['share_until'] > 0 && time() > $row['share_until']) {
        notfound_img(); // 分享已到期
    }
}

// 图片本身过期
if ($row['expire_at'] > 0 && time() > $row['expire_at']) {
    @unlink(IMG_DIR . $row['file']);
    db()->prepare('DELETE FROM img_images WHERE id = ?')->execute(array($id));
    notfound_img();
}

$path = IMG_DIR . $row['file'];
if (!is_file($path)) {
    db()->prepare('DELETE FROM img_images WHERE id = ?')->execute(array($id));
    notfound_img();
}

// 命中计数：仅外部 token 访问计数（自己查看不计）
if (!$self) {
    db()->prepare('UPDATE img_images SET hits = hits + 1 WHERE id = ?')->execute(array($id));
}

$fmtime = filemtime($path);

// 缓存策略：外部 token 访问一律不缓存（保证浏览量计数 + 停止分享立即失效）；
// 自己查看可长缓存（immutable = 浏览器永不重新验证）
if (!$self) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
} else {
    header('Cache-Control: public, max-age=31536000, immutable');
    header_remove('Pragma');
    header_remove('Expires');
}

// ETag + Last-Modified（基于文件实际修改时间，稳定不变）
header('ETag: "' . $row['id'] . '-' . $fmtime . '"');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fmtime) . ' GMT');

// 304 Not Modified：浏览器缓存命中时跳过传输，节省带宽
if ($self) {
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $row['id'] . '-' . $fmtime) {
        http_response_code(304);
        exit;
    }
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $fmtime) {
        http_response_code(304);
        exit;
    }
}

header('Content-Type: image/webp');
header('Content-Length: ' . $row['size']);
readfile($path);
