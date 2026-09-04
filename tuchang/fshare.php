<?php
// ================= 陶瓦图床 · 文件夹公开分享（token 访客只读网格） =================
// 学便签 share.php?f= 模式：token 验证 → 渲染夹内图片网格；不泄露作者信息；过期显示失效页
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
if ($valid) {
    $st = db()->prepare('SELECT id, uid, name, share_until FROM img_folders WHERE share_token = ?');
    $st->execute(array($t));
    $folder = $st->fetch();
    if ($folder && ($folder['share_until'] === 0 || time() <= (int)$folder['share_until'])) {
        // 夹内图片（含子文件夹的，递归收集）
        $uid = (int)$folder['uid'];
        $fid = (int)$folder['id'];
        // 收集子树 id（防环上限 100）
        $ids = array($fid);
        $all = db()->prepare('SELECT id, parent_id FROM img_folders WHERE uid = ?');
        $all->execute(array($uid));
        $parentOf = array();
        foreach ($all->fetchAll() as $f) $parentOf[(int)$f['id']] = $f['parent_id'] === null ? 0 : (int)$f['parent_id'];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($parentOf as $id => $pid) {
                if (in_array($pid, $ids, true) && !in_array($id, $ids, true) && count($ids) < 100) { $ids[] = $id; $changed = true; }
            }
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, name FROM img_images WHERE uid = ? AND folder_id IN ($ph) AND (expire_at = 0 OR expire_at > ?) ORDER BY id DESC");
        $st->execute(array_merge(array($uid), $ids, array(time())));
        foreach ($st->fetchAll() as $r) {
            $imgs[] = array('id' => (int)$r['id'], 'name' => $r['name'], 'thumb' => base_url() . 'i.php?id=' . (int)$r['id']);
        }
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
.fshare-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
.fshare-card { background: var(--bg-panel); border: 2px solid var(--border-color); box-shadow: var(--shadow-hard); border-radius: 2px; overflow: hidden; }
.fshare-card img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; }
.fshare-card .nm { padding: 8px 10px; font-size: 12px; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fshare-empty { text-align: center; padding: 60px 20px; color: var(--text-secondary); font-size: 14px; }
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
    <p>共 <?php echo count($imgs); ?> 张图片 ·
       <?php echo $until === 0 ? '永久有效' : '有效期至 ' . date('Y-m-d H:i', $until); ?>
       · 点击图片放大</p>
  </div>
<?php if (count($imgs) === 0): ?>
  <div class="fshare-empty"><div class="big">☁️</div>文件夹里还没有图片</div>
<?php else: ?>
  <div class="fshare-grid">
    <?php foreach ($imgs as $im): ?>
    <div class="fshare-card" data-full="<?php echo htmlspecialchars($im['thumb'], ENT_QUOTES, 'UTF-8'); ?>">
      <img src="<?php echo htmlspecialchars($im['thumb'], ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
      <div class="nm"><?php echo htmlspecialchars($im['name'], ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
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
<?php endif; ?>
</div>
</body>
</html>
