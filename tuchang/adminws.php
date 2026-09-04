<?php
// ================= 陶瓦图床 · 管理后台（adminws） =================
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

$isAdmin = !empty($_SESSION['is_admin']);
$msg = '';

// 登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
    if (!csrf_ok()) {
        $msg = '请求来源校验失败，请刷新重试';
    } elseif (!rate_check('admin_' . $_SERVER['REMOTE_ADDR'], 10, 600)) {
        $msg = '尝试过于频繁，请 5 分钟后再试';
    } else {
        // 验证码校验
        $capOk = isset($_POST['captcha']) && isset($_SESSION['captcha_code'])
                 && isset($_SESSION['captcha_expire']) && time() <= $_SESSION['captcha_expire']
                 && strtoupper(trim($_POST['captcha'])) === $_SESSION['captcha_code'];
        if (!$capOk) {
            $msg = '验证码错误或已过期';
        } elseif (hash_equals(ADMIN_PASS, (string)$_POST['pw'])) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = 1;
            $_SESSION['admin_del_token'] = uuid_v4(); // 生成二级安全令牌
            $isAdmin = true;
        } else {
            $msg = '密码错误';
        }
    }
    unset($_SESSION['captcha_code'], $_SESSION['captcha_expire']);
}

// 操作
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && csrf_ok()) {
    $action = $_POST['action'];
    if ($action === 'setquota') {
        $target = (int)$_POST['target'];
        $mb = (float)$_POST['quota_mb'];
        if ($target > 0 && $mb >= 0 && $mb <= 102400) {   // 上限 100GB（quota_b 已改 BIGINT 防溢出）
            $bytes = (int)round($mb * 1048576);
            db()->prepare('UPDATE img_users SET quota_b = ? WHERE id = ?')->execute(array($bytes, $target));
            $msg = '配额已更新';
        } else {
            $msg = '参数不合法';
        }
    }
    if ($action === 'deluser') {
        $target = (int)$_POST['target'];
        // 验证码校验
        $capOk = isset($_POST['captcha']) && isset($_SESSION['captcha_code'])
                 && isset($_SESSION['captcha_expire']) && time() <= $_SESSION['captcha_expire']
                 && strtoupper(trim($_POST['captcha'])) === $_SESSION['captcha_code'];
        // 二级令牌校验
        $tokOk = isset($_POST['del_token']) && isset($_SESSION['admin_del_token'])
                 && hash_equals($_SESSION['admin_del_token'], trim($_POST['del_token']));
        if (!$capOk) {
            $msg = '删除失败：验证码错误或已过期';
        } elseif (!$tokOk) {
            $msg = '删除失败：二级安全令牌不正确';
        } elseif ($target > 0) {
            // 删除该用户所有图片（文件 + 记录）再删用户
            $st = db()->prepare('SELECT id, file FROM img_images WHERE uid = ?');
            $st->execute(array($target));
            foreach ($st->fetchAll() as $im) {
                @unlink(IMG_DIR . $im['file']);
            }
            db()->prepare('DELETE FROM img_images WHERE uid = ?')->execute(array($target));
            db()->prepare('DELETE FROM img_users WHERE id = ?')->execute(array($target));
            $msg = '用户已删除（含全部图片）';
        }
        unset($_SESSION['captcha_code'], $_SESSION['captcha_expire']);
    }
}

// 确保已登录管理员有令牌
if ($isAdmin && empty($_SESSION['admin_del_token'])) {
    $_SESSION['admin_del_token'] = uuid_v4();
}

if (!$isAdmin) {
    $csrf = csrf_token();
    ?>
    <!DOCTYPE html>
    <html lang="zh">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理后台 · 陶瓦图床</title>
    <link rel="stylesheet" href="css/pixel-blue.css?v=13">
    </head>
    <body class="auth-page">
    <div class="glass auth-card">
      <div class="brand">管理<span>后台</span></div>
      <p class="brand-sub">Taowa Admin Console</p>
      <?php if ($msg !== ''): ?><div class="notice err"><?php echo e($msg); ?></div><?php endif; ?>
      <form method="post" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
        <label>管理密码</label>
        <input type="password" name="pw" maxlength="100" autocomplete="current-password" required autofocus>
        <label>验证码</label>
        <div class="cap-row">
          <input type="text" name="captcha" maxlength="4" placeholder="图中字符" required>
          <img src="captcha.php" alt="captcha" title="点击刷新" onclick="this.src='captcha.php?t='+Date.now()" class="cap-img">
        </div>
        <button type="submit" class="btn-primary">进 入</button>
      </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ===== 用户列表 =====
$users = db()->query('SELECT id, username, uuid, created_at, quota_b FROM img_users ORDER BY id')
    ->fetchAll();
$usedMap = array();
foreach ($users as $u) {
    $usedMap[$u['id']] = user_used($u['id']);
}
$csrf = csrf_token();
$delToken = $_SESSION['admin_del_token'];
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理后台 · 陶瓦图床</title>
<link rel="stylesheet" href="css/pixel-blue.css?v=13">
<style>
.app { max-width: 900px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 10px 12px; text-align: left; border-bottom: 2px solid var(--border-color); }
th { color: var(--text-secondary); font-size: 11px; font-weight: 600; text-transform: uppercase; }
td .uuid { font-family: ui-monospace, monospace; font-size: 11px; color: var(--text-secondary); }
.q-inp { width: 70px; padding: 6px 8px; border-radius: 2px; border: 2px solid var(--border-color); background: rgba(0,0,0,0.25); color: var(--text-primary); font-size: 13px; outline: none; }
.q-unit { font-size: 11px; color: var(--text-secondary); }
.bar { width: 90px; height: 8px; border-radius: 0; background: var(--bg-card); border: 1px solid var(--border-color); overflow: hidden; display: inline-block; vertical-align: middle; }
.bar i { display: block; height: 100%; background: repeating-linear-gradient(90deg, var(--accent) 0 8px, var(--accent2) 8px 16px); }
.row-act { display: flex; gap: 6px; align-items: center; }
.token-box { background: rgba(239,68,68,0.06); border: 2px solid rgba(239,68,68,0.35); border-radius: 2px; padding: 14px 18px; margin-bottom: 16px; box-shadow: var(--shadow-hard); }
.token-box .t-label { font-size: 12px; font-weight: 700; color: #f87171; margin-bottom: 6px; }
.token-box .t-row { display: flex; align-items: center; gap: 10px; }
.token-box code { font-family: ui-monospace, monospace; font-size: 14px; color: var(--accent); background: rgba(0,0,0,0.3); padding: 8px 12px; border-radius: 2px; flex: 1; word-break: break-all; }
.cap-row { display: flex; gap: 10px; align-items: center; }
.cap-row input { flex: 1; }
.cap-img { height: 38px; border-radius: 2px; cursor: pointer; border: 2px solid var(--border-color); }
</style>
</head>
<body>
<div class="app">
  <div class="topbar">
    <div class="brand">陶瓦<span>图床</span> <span style="font-size:13px;color:var(--muted)">管理后台</span></div>
    <div class="top-user">
      <a class="link-btn" href="dashboard.php">返回图库</a>
      <a class="link-btn danger" href="logout.php">退出</a>
    </div>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="notice <?php echo (strpos($msg, '失败') !== false) ? 'err' : 'ok'; ?>" style="margin-bottom:14px"><?php echo e($msg); ?></div>
  <?php endif; ?>

  <!-- 二级安全令牌 -->
  <div class="token-box">
    <div class="t-label">二级安全令牌（删除用户时需输入）</div>
    <div class="t-row">
      <code id="delTokenVal"><?php echo e($delToken); ?></code>
      <button class="sm-btn" onclick="copyToken()">复制</button>
    </div>
  </div>

  <div class="glass" style="padding:8px 16px;overflow-x:auto">
    <table>
      <thead>
        <tr><th>ID</th><th>用户名</th><th>UUID</th><th>注册时间</th><th>已用 / 配额</th><th>调整配额(MB)</th><th>操作</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
            $used = $usedMap[$u['id']];
            $quota = (int)$u['quota_b'] > 0 ? (int)$u['quota_b'] : USER_QUOTA;
            $pct = $quota > 0 ? min(100, (int)round($used * 100 / $quota)) : 0;
        ?>
        <tr>
          <td><?php echo (int)$u['id']; ?></td>
          <td><?php echo e($u['username']); ?></td>
          <td><span class="uuid"><?php echo e($u['uuid']); ?></span></td>
          <td><?php echo date('Y-m-d H:i', (int)$u['created_at']); ?></td>
          <td>
            <span class="bar"><i style="width:<?php echo $pct; ?>%"></i></span>
            <?php echo fmt_size($used); ?> / <?php echo round($quota / 1048576); ?> MB
          </td>
          <td>
            <form method="post" class="row-act">
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
              <input type="hidden" name="action" value="setquota">
              <input type="hidden" name="target" value="<?php echo (int)$u['id']; ?>">
              <input class="q-inp" type="number" name="quota_mb" min="0" max="102400" step="1" value="<?php echo round($quota / 1048576); ?>">
              <span class="q-unit">MB</span>
              <button class="sm-btn" type="submit">保存</button>
            </form>
          </td>
          <td>
            <button class="sm-btn danger" onclick="openDelModal(<?php echo (int)$u['id']; ?>, '<?php echo e($u['username']); ?>')">删除用户</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="footer">
    管理后台 · 入口 <?php echo e(basename(__FILE__)); ?> · 修改密码：config.php 中 ADMIN_PASS
  </div>
</div>

<!-- 删除用户确认弹窗 -->
<div class="modal-mask" id="delModal" style="display:none">
  <div class="modal glass" style="max-width:420px">
    <button class="modal-x" onclick="closeDelModal()" aria-label="关闭">✕</button>
    <h3 style="color:#ef4444">确认删除用户</h3>
    <p style="font-size:13px;color:var(--ink);margin:8px 0 16px">
      即将删除用户「<b id="delUserName"></b>」及其全部图片，此操作不可撤销。
    </p>
    <form method="post" id="delForm">
      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="deluser">
      <input type="hidden" name="target" id="delTarget" value="">

      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">验证码</label>
      <div class="cap-row" style="margin-bottom:14px">
        <input type="text" name="captcha" maxlength="4" placeholder="输入图中字符" required style="flex:1;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(0,0,0,0.25);color:var(--ink);font-size:14px;outline:none">
        <img src="captcha.php" alt="captcha" id="delCaptcha" title="点击刷新" onclick="this.src='captcha.php?t='+Date.now()" class="cap-img">
      </div>

      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">二级安全令牌</label>
      <input type="text" name="del_token" maxlength="36" placeholder="输入页面顶部显示的令牌" required style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(0,0,0,0.25);color:var(--ink);font-size:14px;outline:none;margin-bottom:16px;font-family:ui-monospace,monospace">

      <button type="submit" class="btn-primary" style="width:100%;background:#ef4444">确认删除</button>
    </form>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
function openDelModal(userId, userName) {
  document.getElementById('delTarget').value = userId;
  document.getElementById('delUserName').textContent = userName;
  document.getElementById('delCaptcha').src = 'captcha.php?t=' + Date.now();
  document.getElementById('delModal').style.display = 'flex';
}
function closeDelModal() {
  document.getElementById('delModal').style.display = 'none';
}
function copyToken() {
  var token = document.getElementById('delTokenVal').textContent;
  navigator.clipboard.writeText(token).then(function() {
    var t = document.getElementById('toast');
    t.textContent = '令牌已复制';
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
  });
}
// 点击遮罩关闭
document.getElementById('delModal').addEventListener('click', function(e) {
  if (e.target === this) closeDelModal();
});
</script>
</body>
</html>
