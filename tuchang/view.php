<?php
// ================= 陶瓦图床 · 图片详情页（新标签页打开） =================
define('TAWA_IMG', true);
require __DIR__ . '/config.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: dashboard.php'); exit; }

// 防撞库：链接必须携带本用户的专属 uuid
$u = isset($_GET['u']) ? (string)$_GET['u'] : '';
$myUuid = my_uuid();
if ($u === '' || !hash_equals($myUuid, $u)) { header('Location: dashboard.php'); exit; }

$uid = (int)$_SESSION['uid'];
$st = db()->prepare('SELECT id, file, name, size, w, h, created_at, expire_at, hits, share_token, share_until FROM img_images WHERE id = ? AND uid = ?');
$st->execute(array($id, $uid));
$img = $st->fetch();
if (!$img) { header('Location: dashboard.php'); exit; }
cleanup_expired();

$base = base_url();
$csrf = csrf_token();
$url = $base . 'i.php?id=' . $id;
$staticUrl = $url; // 静态路径：浏览器可缓存，不重复拉取
$shared = !empty($img['share_token']);
$shareUrl = $shared ? $base . 's.php?t=' . $img['share_token'] : '';
$expired = $img['expire_at'] > 0 && time() > $img['expire_at'];
$expOpts = array(0 => '永不过期', 3600 => '1 小时', 86400 => '1 天', 604800 => '7 天', 2592000 => '30 天');
$remain = $img['expire_at'] > 0 ? $img['expire_at'] - time() : 0;
$selV = 0;
if ($remain > 0) {
    if ($remain <= 3600) $selV = 3600;
    elseif ($remain <= 86400) $selV = 86400;
    elseif ($remain <= 604800) $selV = 604800;
    else $selV = 2592000;
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($img['name']); ?> · 陶瓦图床</title>

<link rel="stylesheet" href="css/pixel-blue.css?v=13">
</head>
<body class="view-mode">
<div class="view">
  <div class="view-main">
    <img src="<?php echo e($staticUrl); ?>" alt="<?php echo e($img['name']); ?>">
  </div>
  <div class="view-side glass">
    <div class="v-name"><?php echo e($img['name']); ?></div>
    <div class="v-meta">
      尺寸 <b><?php echo (int)$img['w']; ?>×<?php echo (int)$img['h']; ?></b><br>
      大小 <b><?php echo fmt_size($img['size']); ?></b><br>
      上传 <b><?php echo date('Y-m-d H:i', (int)$img['created_at']); ?></b><br>
      浏览 <b><?php echo (int)$img['hits']; ?></b> 次
    </div>

    <div class="v-sep"></div>
    <div class="v-title">重命名</div>
    <div style="display:flex;gap:8px">
      <input id="renameInput" value="<?php echo e($img['name']); ?>" style="flex:1;padding:8px 10px;border-radius:10px;font-size:12px;border:1px solid var(--border);background:rgba(0,0,0,0.25);color:var(--ink);outline:none;font-family:inherit">
      <button class="v-btn ghost" style="margin-top:0;width:auto;padding:8px 14px;font-size:13px" onclick="doRename()">💾 保存</button>
    </div>

    <div class="v-sep"></div>
    <div class="v-title">图片过期</div>
    <select class="exp-sel" id="expSel">
      <?php foreach ($expOpts as $v => $t): ?>
        <option value="<?php echo $v; ?>" <?php echo $selV == $v ? 'selected' : ''; ?>><?php echo $t; ?></option>
      <?php endforeach; ?>
    </select>
    <div class="v-remain" id="expRemain">
      <?php echo $expired ? '已过期' : ($img['expire_at'] > 0 ? '剩余 <b>' . ceil($remain / 60) . '</b> 分钟后自动删除' : '永久保存'); ?>
    </div>

    <div class="v-sep"></div>
    <div class="v-title">公开分享</div>
    <div class="v-share-box" id="shareBox">
      <?php if ($shared):
          $curHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']));
          $prefUrl = 'https://' . PREFERRED_HOST . '/s.php?t=' . $img['share_token'];
          $sameHost = ($curHost === strtolower(PREFERRED_HOST));
      ?>
        <?php if (!$sameHost): ?>
          <div class="v-url-label">优选线路（推荐外发）</div>
          <input class="v-share-url" id="shareUrl" readonly value="<?php echo e($prefUrl); ?>">
          <div class="v-url-label">当前域名</div>
          <input class="v-share-url" id="shareUrlAlt" readonly value="<?php echo e($shareUrl); ?>">
        <?php else: ?>
          <input class="v-share-url" id="shareUrl" readonly value="<?php echo e($shareUrl); ?>">
        <?php endif; ?>
        <div class="v-remain" id="shareRemain">
          <?php
          if ($img['share_until'] > 0) {
              $min = max(1, ceil(($img['share_until'] - time()) / 60));
              echo '分享剩余 <b>' . $min . '</b> 分钟';
          } else {
              echo '永久分享';
          }
          ?>
        </div>
        <button class="v-btn" onclick="copyShare()">复制分享链接</button>
        <button class="v-btn stop" onclick="doUnshare()">停止分享</button>
      <?php else: ?>
        <select class="share-dur" id="durSel">
          <option value="3600">1 小时</option>
          <option value="86400">1 天</option>
          <option value="604800">7 天</option>
          <option value="2592000">30 天</option>
          <option value="0">永久有效</option>
        </select>
        <button class="v-btn" onclick="doShare()">创建分享链接</button>
      <?php endif; ?>
    </div>

    <div class="v-actions">
      <a class="link-btn" href="dashboard.php" style="text-align:center">← 返回图库</a>
      <button class="v-btn ghost" onclick="window.close()">关闭标签页</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script>
var CSRF = <?php echo json_encode($csrf); ?>;
var IMGID = <?php echo (int)$id; ?>;
var IMGNAME = <?php echo json_encode($img["name"]); ?>;
</script>
<script src="js/view.js?v=3"></script>
</body>
</html>
