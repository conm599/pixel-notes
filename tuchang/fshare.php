<?php
// ================= 陶瓦图床 · 文件夹公开分享（token 访客只读，学便签分层导航） =================
// token 验证 → 显示当前夹：子文件夹卡（可点击进入）+ 本夹直接图片（懒加载，点击放大）
// 与 dashboard 视图一致：不平铺子夹图片；排序 id DESC（新图在前）；不泄露作者信息
define('TAWA_NO_SESSION', true);
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
$valid = strlen($t) === 36 && preg_match('/^[a-f0-9-]{36}$/', $t);

$folder = null;
$imgs = array();
$children = array();
$crumbs = array();
$cur = 0;
$rootId = 0;
$curName = '';
$nameOf = array();
if ($valid) {
    $st = db()->prepare('SELECT id, uid, name, share_until FROM img_folders WHERE share_token = ?');
    $st->execute(array($t));
    $folder = $st->fetch();
    if ($folder && ((int)$folder['share_until'] === 0 || time() <= (int)$folder['share_until'])) {
        // 学便签 share：分层导航（当前夹子夹+本夹直接图片），不平铺
        $uid = (int)$folder['uid'];
        $rootId = (int)$folder['id'];
        $parentOf = array();
        $all = db()->prepare('SELECT id, parent_id, name FROM img_folders WHERE uid = ?');
        $all->execute(array($uid));
        foreach ($all->fetchAll() as $f) {
            $parentOf[(int)$f['id']] = $f['parent_id'] === null ? 0 : (int)$f['parent_id'];
            $nameOf[(int)$f['id']] = $f['name'];
        }

        // 当前浏览的夹（深链接 ?f=，须在 token 树内，防环上限 100）
        $cur = $rootId;
        $fc = isset($_GET['f']) ? (int)$_GET['f'] : $rootId;
        if ($fc > 0 && $fc !== $rootId && isset($parentOf[$fc])) {
            $chain = array(); $c = $fc; $guard = 0;
            while ($c > 0 && $guard++ < 100) {
                $chain[] = $c;
                if (!isset($parentOf[$c])) { $chain = array(); break; }
                $c = $parentOf[$c];
                if ($c === $rootId) { $chain[] = $rootId; break; }
            }
            if (!empty($chain) && end($chain) === $rootId) {
                $cur = $fc;
                $crumbs = array_reverse($chain);
            } else {
                $cur = $rootId;   // 不在树内：回根
            }
        }

        // 当前夹的子夹（名称序，与 dashboard 一致）
        foreach ($parentOf as $id => $pid) {
            if ($pid === $cur) $children[] = array('id' => $id, 'name' => $nameOf[$id]);
        }
        usort($children, function ($a, $b) use ($nameOf) { return strcmp($a['name'], $b['name']); });

        // 当前夹的直接图片（与 dashboard 同序：新图在前）
        $st = db()->prepare('SELECT id, name FROM img_images WHERE uid = ? AND folder_id = ? AND (expire_at = 0 OR expire_at > ?) ORDER BY id DESC');
        $st->execute(array($uid, $cur, time()));
        foreach ($st->fetchAll() as $r) {
            $imgs[] = array('id' => (int)$r['id'], 'name' => $r['name'], 'thumb' => base_url() . 'i.php?id=' . (int)$r['id']);
        }
        $curName = ($cur === $rootId) ? $folder['name'] : $nameOf[$cur];
    } else {
        $folder = null;
    }
}
$until = $folder ? (int)$folder['share_until'] : 0;
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>分享的文件夹 · 陶瓦图床</title>
<link rel="stylesheet" href="css/pixel-blue.css?v=13">
<style>
.fshare-wrap { max-width: 1100px; margin: 0 auto; padding: 26px 18px 60px; }
.fshare-head { text-align: center; margin-bottom: 22px; }
.fshare-head h1 { font-family: 'Press Start 2P', monospace; font-size: 17px; color: var(--accent); margin-bottom: 8px; line-height: 1.8; }
.fshare-head p { font-size: 12px; color: var(--text-secondary); }
.fs-crumbs { text-align: center; margin-bottom: 18px; font-size: 13px; color: var(--text-secondary); }
.fs-crumbs a { color: var(--accent); text-decoration: none; }
.fs-crumbs a:hover { text-decoration: underline; }
.fs-crumbs .on { color: var(--text-primary); }
.fs-crumbs .sep { margin: 0 6px; opacity: 0.6; }
.fs-sec-title { font-size: 13px; color: var(--text-secondary); margin: 18px 0 10px; }
.fs-folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; margin-bottom: 22px; }
.fs-folder-card { background: var(--bg-panel); border: 2px solid var(--border-color); box-shadow: var(--shadow-hard); border-radius: 2px; text-align: center; padding: 14px 8px; text-decoration: none; display: block; }
.fs-folder-card:hover { border-color: var(--accent); }
.fs-folder-card .ic { font-size: 28px; }
.fs-folder-card .nm { font-size: 12px; color: var(--text-primary); margin-top: 6px; font-weight: 700; word-break: break-all; }
.fs-folder-card .ct { font-size: 10px; color: var(--text-secondary); margin-top: 2px; }
.fshare-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
.fshare-card { background: var(--bg-panel); border: 2px solid var(--border-color); box-shadow: var(--shadow-hard); border-radius: 2px; overflow: hidden; cursor: pointer; }
.fshare-card img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: var(--bg-card); }
.fshare-card .nm { padding: 8px 10px; font-size: 12px; color: var(--text-primary); word-break: break-all; line-height: 1.5; }
.fshare-empty { text-align: center; padding: 50px 20px; color: var(--text-secondary); font-size: 14px; }
.fshare-empty .big { font-size: 44px; margin-bottom: 12px; }
.lightbox { position: fixed; inset: 0; z-index: 999; background: rgba(4,8,14,0.93); display: none; align-items: center; justify-content: center; cursor: zoom-out; }
.lightbox.on { display: flex; }
.lightbox img { max-width: 92vw; max-height: 88vh; }
</style>
</head>
<body>
<div class="fshare-wrap">
<?php if (!$folder): ?>
  <div class="fshare-empty"><div class="big">💀</div>分享不存在或已过期</div>
<?php else: ?>
  <div class="fshare-head">
    <h1>📁 <?php echo htmlspecialchars($folder['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p>本层 <?php echo count($imgs); ?> 张图片 ·
       <?php echo $until === 0 ? '永久有效' : '有效期至 ' . date('Y-m-d H:i', $until); ?>
       · 点击图片放大</p>
  </div>

  <?php if ($cur !== $rootId || !empty($crumbs)): ?>
  <div class="fs-crumbs">
    <a href="?t=<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>">📁 <?php echo htmlspecialchars($folder['name'], ENT_QUOTES, 'UTF-8'); ?></a><?php
    foreach ($crumbs as $cid):
        if ($cid === $rootId) continue;
        echo '<span class="sep">›</span>';
        if ($cid === $cur) echo '<span class="on">' . htmlspecialchars($nameOf[$cid], ENT_QUOTES, 'UTF-8') . '</span>';
        else echo '<a href="?t=' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '&f=' . $cid . '">' . htmlspecialchars($nameOf[$cid], ENT_QUOTES, 'UTF-8') . '</a>';
    endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($children)): ?>
  <div class="fs-sec-title">📂 子文件夹</div>
  <div class="fs-folder-grid">
    <?php foreach ($children as $ch): ?>
    <a class="fs-folder-card" href="?t=<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>&f=<?php echo (int)$ch['id']; ?>">
      <div class="ic">📁</div>
      <div class="nm"><?php echo htmlspecialchars($ch['name'], ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="ct">进入查看</div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (count($imgs) === 0): ?>
  <div class="fshare-empty"><div class="big">☁️</div>本层没有图片</div>
  <?php else: ?>
  <div class="fshare-grid">
    <?php foreach ($imgs as $im): ?>
    <div class="fshare-card" data-full="<?php echo htmlspecialchars($im['thumb'], ENT_QUOTES, 'UTF-8'); ?>">
      <img src="<?php echo htmlspecialchars($im['thumb'], ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy" decoding="async">
      <div class="nm"><?php echo htmlspecialchars($im['name'], ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="lightbox" id="lb"><img id="lbImg" alt=""></div>
  <script>
  (function () {
    var lb = document.getElementById('lb'), im = document.getElementById('lbImg');
    document.querySelectorAll('.fshare-card').forEach(function (c) {
      c.addEventListener('click', function () { im.src = c.getAttribute('data-full'); lb.classList.add('on'); });
    });
    lb.addEventListener('click', function () { lb.classList.remove('on'); });
  })();
  </script>
<?php endif; ?>
</div>
</body>
</html>
