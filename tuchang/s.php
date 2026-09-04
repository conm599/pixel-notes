<?php
// ================= 陶瓦图床 · 公开分享图入口（token 鉴权，杜绝缓存） =================
define('TAWA_NO_SESSION', true);
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
if (strlen($t) !== 36 || !preg_match('/^[a-f0-9-]{36}$/', $t)) {
    notfound_img();
}

cleanup_expired();

$st = db()->prepare('SELECT id, uid, file, expire_at, size, share_token, share_until FROM img_images WHERE share_token = ?');
$st->execute(array($t));
$row = $st->fetch();
if (!$row) {
    notfound_img();
}

// 分享到期
if ($row['share_until'] > 0 && time() > $row['share_until']) {
    notfound_img();
}
// 图片本身过期
if ($row['expire_at'] > 0 && time() > $row['expire_at']) {
    @unlink(IMG_DIR . $row['file']);
    db()->prepare('DELETE FROM img_images WHERE id = ?')->execute(array((int)$row['id']));
    notfound_img();
}

$path = IMG_DIR . $row['file'];
if (!is_file($path)) {
    db()->prepare('DELETE FROM img_images WHERE id = ?')->execute(array((int)$row['id']));
    notfound_img();
}

// 命中计数
db()->prepare('UPDATE img_images SET hits = hits + 1 WHERE id = ?')->execute(array((int)$row['id']));

header('Content-Type: image/webp');
header('Content-Length: ' . $row['size']);
readfile($path);
