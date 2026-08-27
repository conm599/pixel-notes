<?php
/**
 * 便签公开分享页（token 鉴权，只读）
 * 安全设计（参考图床 s.php）：
 *  - 36 位 UUID v4 token，非枚举不可猜
 *  - share_until 有效期控制，过期即 404 并永久失效
 *  - 无 session 依赖；页面不回传任何作者信息
 *  - 内容渲染在前端由 PixelMD 白名单渲染（与站内一致，XSS 防护同一套）
 */
require_once __DIR__ . '/config/database.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
// 注：nginx 拦截 404 替换响应体，所以"不存在"也用 200 输出自定义错误页
http_response_code(200);

$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
if (strlen($t) !== 36 || !preg_match('/^[a-f0-9-]{36}$/', $t)) {
    $notfound = true;
} else {
    $notfound = false;
}

$note = null;
if (!$notfound) {
    try {
        $pdo = getDB();
        // 自愈：确保 share_token 列存在（首次访问可能列还没建）
        $schemaErr = null;
        ensureTables($schemaErr);

        $st = $pdo->prepare("SELECT title, content, color, updated_at, share_until FROM pn_notes WHERE share_token = ?");
        $st->execute(array($t));
        $note = $st->fetch();

        if (!$note) {
            $notfound = true;
        } elseif ((int)$note['share_until'] > 0 && time() > (int)$note['share_until']) {
            // 过期：清掉 token 让链接永久失效
            $pdo->prepare("UPDATE pn_notes SET share_token = '', share_until = 0 WHERE share_token = ?")->execute(array($t));
            $notfound = true;
        } else {
            // 有效：允许 CDN 短缓存（链接含随机 token，缓存安全）
            header('Cache-Control: public, max-age=300');
        }
    } catch (Exception $e) {
        $notfound = true;
    }
}
// 确保始终 200（nginx 不拦截）
http_response_code(200);
$colorMap = array('yellow' => '#ffd866', 'pink' => '#ff8fbf', 'blue' => '#6fcbff', 'green' => '#80ffb3', 'purple' => '#c8a0ff', 'orange' => '#ffb366');
$accent = isset($note['color']) && isset($colorMap[$note['color']]) ? $colorMap[$note['color']] : '#4af0ff';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $notfound ? '分享不存在' : '分享的便签 - Pixel Notes' ?></title>
<link rel="stylesheet" href="css/pixel.css?v=35">
<script src="js/md.js?v=35"></script>
</head>
<body>
<div class="share-wrap">
<?php if ($notfound): ?>
    <div class="share-empty">
        <div class="icon">💀</div>
        <p>分享不存在或已过期</p>
        <a class="btn btn-outline" href="/">返回首页</a>
    </div>
<?php else: ?>
    <div class="share-card note-<?= htmlspecialchars($note['color'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="share-head">
            <span class="share-badge">📖 分享的便签</span>
            <span class="share-time">🕐 <?= htmlspecialchars($note['updated_at'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="share-title"><?= htmlspecialchars($note['title'] !== '' ? $note['title'] : '无标题', ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="note-content md-body" id="shareContent"></div>
    </div>
    <div class="share-foot">Powered by <a href="/">Pixel Notes</a></div>
    <script>
        document.getElementById('shareContent').innerHTML = window.PixelMD.render(<?= json_encode((string)$note['content'], JSON_UNESCAPED_UNICODE) ?>);
    </script>
<?php endif; ?>
</div>
</body>
</html>
