<?php
/**
 * Pixel Suite · 部署配置中心（/admini/）
 * - 首次访问（无 suite-config.php）：安装向导，注册的账号自动成为双站管理员
 * - 初始化后：管理员密码登录，可视化改两站全部硬编码配置（DB/邀请码/密令/配额/TTS...）
 * - 配置优先级：环境变量 PSU_* > suite-config.php（本面板写）> 代码默认值
 * - 配置文件位置：webroot 上一级（/var/www/suite-config.php），nginx 不可达
 */
// 独立会话名：与便签/图床的 PHPSESSID（父域 Cookie）彻底隔离，
// 否则浏览器同时携带两个同名 Cookie 时 PHP 取错会话，登录后会被弹回登录页
session_name('ADMINI_SID');
session_set_cookie_params(array('httponly' => true, 'samesite' => 'Lax', 'secure' => true));
session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'");
header('Cache-Control: no-store, max-age=0');

// ---- 配置文件定位（与两站加载器同逻辑：webroot 上一级优先） ----
$CANDIDATES = array(
    dirname(__DIR__, 2) . '/suite-config.php', // VPS：/var/www/suite-config.php
    dirname(__DIR__) . '/suite-config.php',    // 兜底：webroot 内（.php 后缀不会被下载）
);
$CFG_FILE = '';
foreach ($CANDIDATES as $f) { if (is_file($f)) { $CFG_FILE = $f; break; } }
$CFG_TARGET = is_file($CANDIDATES[0]) ? $CANDIDATES[0] : $CANDIDATES[1];

$INSTALLED = $CFG_FILE !== '';
$cfg = $INSTALLED ? (array)include($CFG_FILE) : array();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- 登录态 ----
$logged = !empty($_SESSION['admini_ok']);
if ($logged && !empty($cfg['admin_hash'])) {
    // 每次请求校验密码未变更
    if (isset($_POST['logout'])) { session_destroy(); header('Location: ./'); exit; }
}
if (!$INSTALLED && isset($_POST['install'])) {
    // ---- 安装向导提交：注册的账号自动成为双站管理员 ----
    $user = trim($_POST['admin_user'] ?? '');
    $pass = (string)($_POST['admin_pass'] ?? '');
    $pass2 = (string)($_POST['admin_pass2'] ?? '');
    $err = '';
    if ($user === '' || strlen($user) > 40) $err = '管理员用户名不能为空';
    elseif (strlen($pass) < 8) $err = '管理员密码至少 8 位';
    elseif ($pass !== $pass2) $err = '两次密码不一致';
    if ($err === '') {
        $new = array(
            'admin_user' => $user,
            'admin_hash' => password_hash($pass, PASSWORD_DEFAULT),
            // 便签 DB
            'bianqian_host' => trim($_POST['bianqian_host'] ?? ''),
            'tuchang_host' => trim($_POST['tuchang_host'] ?? ''),
            'bianqian_db_host' => trim($_POST['bianqian_db_host'] ?? 'localhost'),
            'bianqian_db_port' => trim($_POST['bianqian_db_port'] ?? '3306'),
            'bianqian_db_name' => trim($_POST['bianqian_db_name'] ?? ''),
            'bianqian_db_user' => trim($_POST['bianqian_db_user'] ?? ''),
            'bianqian_db_pass' => (string)($_POST['bianqian_db_pass'] ?? ''),
            // 图床 DB
            'tuchang_db_host' => trim($_POST['tuchang_db_host'] ?? 'localhost'),
            'tuchang_db_name' => trim($_POST['tuchang_db_name'] ?? ''),
            'tuchang_db_user' => trim($_POST['tuchang_db_user'] ?? ''),
            'tuchang_db_pass' => (string)($_POST['tuchang_db_pass'] ?? ''),
            // 图床常量
            'smtp_host' => trim($_POST['smtp_host'] ?? 'smtp.qq.com'),
            'smtp_port' => (int)($_POST['smtp_port'] ?? 465),
            'smtp_user' => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass' => (string)($_POST['smtp_pass'] ?? ''),
            'smtp_from_name' => trim($_POST['smtp_from_name'] ?? 'Pixel Notes'),
            'email_whitelist' => trim($_POST['email_whitelist'] ?? ''),
            'tuchang_invite_code' => trim($_POST['tuchang_invite_code'] ?? ''),
            'tuchang_admin_pass' => (string)($_POST['tuchang_admin_pass'] ?? ''),
            'tuchang_user_quota' => (int)($_POST['tuchang_user_quota'] ?? 20971520),
            'tuchang_max_upload' => (int)($_POST['tuchang_max_upload'] ?? 10485760),
            'tuchang_max_compressed' => (int)($_POST['tuchang_max_compressed'] ?? 4194304),
            'tuchang_max_dim' => (int)($_POST['tuchang_max_dim'] ?? 8192),
            'tuchang_cron_key' => trim($_POST['tuchang_cron_key'] ?? ''),
            'tuchang_preferred_host' => trim($_POST['tuchang_preferred_host'] ?? 'tuchang.naxid.top'),
            // TTS
            'tts_url' => trim($_POST['tts_url'] ?? 'https://edgetts.naxid.top/v1/audio/speech'),
            'tts_token' => trim($_POST['tts_token'] ?? ''),
            'tts_max_text' => (int)($_POST['tts_max_text'] ?? 5000),
        );
        if (($new['tuchang_admin_pass'] ?? '') === '') $new['tuchang_admin_pass'] = $pass; // 默认同步：图床密令=本密码
        $out = "<?php\n// Pixel Suite 共享配置 · 由 /admini 面板生成（" . date('Y-m-d H:i') . "）\n// 敏感文件：切勿提交仓库、切勿放 webroot 可下载位置\nreturn " . var_export($new, true) . ";\n";
        $tmp = $CFG_TARGET . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $out) === false || !@rename($tmp, $CFG_TARGET)) {
            $err = '配置文件写入失败：' . h($CFG_TARGET) . '（检查目录权限）';
        } else {
            @chmod($CFG_TARGET, 0640);
            session_regenerate_id(true);
            $_SESSION['admini_ok'] = 1;
            header('Location: ./');
            exit;
        }
    }
    $cfg = array();
} elseif ($INSTALLED && !$logged && isset($_POST['login'])) {
    // ---- 登录（限频：失败越多睡越久） ----
    $cnt = isset($_SESSION['admini_fail']) ? (int)$_SESSION['admini_fail'] : 0;
    sleep(min(5, $cnt));
    if (password_verify((string)($_POST['admin_pass'] ?? ''), (string)($cfg['admin_hash'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admini_ok'] = 1;
        unset($_SESSION['admini_fail']);
        header('Location: ./');
        exit;
    }
    $_SESSION['admini_fail'] = $cnt + 1;
    $err = '密码错误';
} elseif ($INSTALLED && $logged && isset($_POST['save'])) {
    // ---- 面板保存：读旧值合并（密码留空=不改） ----
    $keys = array('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','email_whitelist','bianqian_host','tuchang_host','bianqian_db_host','bianqian_db_port','bianqian_db_name','bianqian_db_user','bianqian_db_pass',
        'tuchang_db_host','tuchang_db_name','tuchang_db_user','tuchang_db_pass',
        'tuchang_invite_code','tuchang_admin_pass','tuchang_user_quota','tuchang_max_upload','tuchang_max_compressed',
        'tuchang_max_dim','tuchang_cron_key','tuchang_preferred_host',
        'tts_url','tts_token','tts_max_text');
    foreach ($keys as $k) {
        if (!isset($_POST[$k])) continue;
        $v = (string)$_POST[$k];
        if ($v === '' && substr($k, -5) === '_pass' && isset($cfg[$k])) continue; // 密码留空=保持
        $cfg[$k] = (substr($k, -6) === '_quota' || substr($k, -11) === '_compressed' || substr($k, -9) === '_max_text' || substr($k, -8) === '_dim' || substr($k, -6) === '_upload' || substr($k, -5) === '_port')
            ? (int)$v : $v;
    }
    if (!empty($_POST['new_pass'])) {
        if ((string)$_POST['new_pass'] === (string)$_POST['new_pass2'] && strlen((string)$_POST['new_pass']) >= 8) {
            $cfg['admin_hash'] = password_hash((string)$_POST['new_pass'], PASSWORD_DEFAULT);
            $cfg['admin_user'] = trim($_POST['admin_user'] ?? ($cfg['admin_user'] ?? 'admin'));
            if (($cfg['tuchang_admin_pass'] ?? '') === ($cfg['__old_admin'] ?? '') || !empty($_POST['sync_img_pass'])) {
                $cfg['tuchang_admin_pass'] = (string)$_POST['new_pass'];
            }
        }
    }
    unset($cfg['__old_admin']);
    $out = "<?php\n// Pixel Suite 共享配置 · 由 /admini 面板更新（" . date('Y-m-d H:i') . "）\nreturn " . var_export($cfg, true) . ";\n";
    $tmp = $CFG_TARGET . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $out) !== false && @rename($tmp, $CFG_TARGET)) {
        $msg = '已保存并立即生效（无需重启）';
    } else {
        $msg = '写入失败：' . h($CFG_TARGET);
    }
}

$FIELDS = array(
    '邮件 SMTP（注册验证码）' => array(
        'smtp_host' => 'SMTP 服务器', 'smtp_port' => '端口', 'smtp_user' => '发信账号',
        'smtp_pass' => 'SMTP 授权码', 'smtp_from_name' => '发件人名称', 'email_whitelist' => '邮箱白名单(逗号分隔)',
    ),
    '站点域名（公共部署）' => array(
        'bianqian_host' => '便签完整域名', 'tuchang_host' => '图床完整域名',
    ),
    '便签数据库' => array(
        'bianqian_db_host' => '主机', 'bianqian_db_port' => '端口', 'bianqian_db_name' => '库名',
        'bianqian_db_user' => '用户', 'bianqian_db_pass' => '密码',
    ),
    '图床数据库' => array(
        'tuchang_db_host' => '主机', 'tuchang_db_name' => '库名', 'tuchang_db_user' => '用户', 'tuchang_db_pass' => '密码',
    ),
    '图床常量' => array(
        'tuchang_invite_code' => '注册邀请码', 'tuchang_admin_pass' => 'adminws 管理密令',
        'tuchang_user_quota' => '默认配额(字节)', 'tuchang_max_upload' => '上传上限(字节)',
        'tuchang_max_compressed' => '压缩上限(字节)', 'tuchang_max_dim' => '最大边长(px)',
        'tuchang_cron_key' => 'cron 密钥', 'tuchang_preferred_host' => '优选域名(分享副域)',
    ),
    'TTS 语音' => array(
        'tts_url' => 'TTS 接口地址', 'tts_token' => 'TTS Token', 'tts_max_text' => '单次最大字数',
    ),
);
$PASS_KEYS = array('bianqian_db_pass', 'tuchang_db_pass', 'tuchang_admin_pass', 'tts_token', 'tuchang_cron_key');
?><!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pixel Suite 配置中心</title>
<style>
:root { --bg:#0a0e18; --panel:#101828; --line:#2a3a55; --acc:#7aa5e0; --acc2:#9fc3f0; --txt:#e8eef8; --mut:#8fa0b8; --ok:#6fd08f; --err:#f87171; }
* { box-sizing: border-box; }
body { margin:0; padding:24px 16px 60px; background:var(--bg); color:var(--txt); font-family:ui-monospace,Consolas,'Microsoft YaHei',monospace; font-size:14px; line-height:1.7;
  background-image: repeating-linear-gradient(0deg, rgba(122,165,224,0.03) 0 1px, transparent 1px 3px); }
.wrap { max-width: 760px; margin: 0 auto; }
h1 { font-size: 20px; color: var(--acc); text-align:center; letter-spacing:2px; }
h2 { font-size: 14px; color: var(--acc2); border-bottom: 2px solid var(--line); padding-bottom: 6px; margin: 26px 0 12px; }
.card { background: var(--panel); border: 2px solid var(--line); box-shadow: 4px 4px 0 #000; padding: 18px 20px; }
label { display:block; font-size: 12px; color: var(--mut); margin: 10px 0 3px; }
input[type=text], input[type=password], input[type=number] { width:100%; padding: 9px 11px; background:#0a0f1c; border:2px solid var(--line); color:var(--txt); font-family:inherit; font-size:13px; outline:none; }
input:focus { border-color: var(--acc); }
button { margin-top: 16px; width:100%; padding: 11px 0; background: var(--acc); color:#04101c; border:2px solid var(--acc2); font-family:inherit; font-weight:700; font-size:14px; cursor:pointer; box-shadow:3px 3px 0 #000; }
button:hover { background: var(--acc2); }
button.ghost { background: var(--panel); color: var(--txt); border-color: var(--line); }
.msg-ok { color: var(--ok); border:2px solid var(--ok); padding:8px 12px; margin-bottom:12px; }
.msg-err { color: var(--err); border:2px solid var(--err); padding:8px 12px; margin-bottom:12px; }
.hint { color: var(--mut); font-size: 12px; margin-top: 14px; }
.badge { display:inline-block; background:rgba(111,208,143,0.15); color:var(--ok); border:1px solid var(--ok); font-size:11px; padding:1px 8px; margin-left:8px; }
.top { display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px; }
.top a, .top button.link { color: var(--acc); font-size:12px; text-decoration:none; background:none; border:none; cursor:pointer; padding:0; margin:0; width:auto; box-shadow:none; }
</style>
</head>
<body>
<div class="wrap">
<h1>⌘ PIXEL SUITE 配置中心</h1>
<?php if (!$INSTALLED): ?>
  <div class="card">
    <div class="top"><h2 style="margin:0;border:none;padding:0">① 首次安装向导</h2><span class="badge">未初始化</span></div>
    <?php if (!empty($err)): ?><div class="msg-err"><?php echo h($err); ?></div><?php endif; ?>
    <p class="hint">填写完成后写入共享配置文件并锁定安装。<b>这里注册的账号自动成为双站管理员</b>（图床管理密令默认同步为本密码）。所有值之后可在面板随时修改。</p>
    <form method="post">
      <h2>管理员账号</h2>
      <label>用户名</label><input type="text" name="admin_user" required value="admin">
      <label>密码（≥8 位）</label><input type="password" name="admin_pass" required minlength="8">
      <label>确认密码</label><input type="password" name="admin_pass2" required minlength="8">
      <h2>站点域名（公共部署必填；留空=动态推导 bianqian./tuchang. 前缀）</h2>
      <label>便签完整域名（如 notes.example.com）</label><input type="text" name="bianqian_host" placeholder="留空=bianqian.&lt;当前域名&gt;">
      <label>图床完整域名（如 img.example.com）</label><input type="text" name="tuchang_host" placeholder="留空=tuchang.&lt;当前域名&gt;">
      <h2>便签数据库</h2>
      <label>主机</label><input type="text" name="bianqian_db_host" value="localhost">
      <label>端口</label><input type="text" name="bianqian_db_port" value="3306">
      <label>库名</label><input type="text" name="bianqian_db_name" required>
      <label>用户</label><input type="text" name="bianqian_db_user" required>
      <label>密码</label><input type="password" name="bianqian_db_pass">
      <h2>图床数据库</h2>
      <label>主机</label><input type="text" name="tuchang_db_host" value="localhost">
      <label>库名</label><input type="text" name="tuchang_db_name" required>
      <label>用户</label><input type="text" name="tuchang_db_user" required>
      <label>密码</label><input type="password" name="tuchang_db_pass">
      <h2>邮件 SMTP（注册验证码依赖；留空=暂不发信）</h2>
      <label>SMTP 服务器（如 smtp.qq.com）</label><input type="text" name="smtp_host" value="smtp.qq.com">
      <label>端口（465=SSL / 587=STARTTLS）</label><input type="text" name="smtp_port" value="465">
      <label>发信账号</label><input type="text" name="smtp_user">
      <label>SMTP 授权码</label><input type="password" name="smtp_pass">
      <label>发件人名称</label><input type="text" name="smtp_from_name" value="Pixel Notes">
      <h2>图床常量</h2>
      <label>注册邀请码</label><input type="text" name="tuchang_invite_code" required>
      <label>adminws 管理密令（留空=与管理员密码相同）</label><input type="password" name="tuchang_admin_pass">
      <label>默认每用户配额（字节）</label><input type="number" name="tuchang_user_quota" value="20971520">
      <label>上传上限（字节）</label><input type="number" name="tuchang_max_upload" value="10485760">
      <label>压缩后上限（字节）</label><input type="number" name="tuchang_max_compressed" value="4194304">
      <label>图片最大边长（px）</label><input type="number" name="tuchang_max_dim" value="8192">
      <label>cron 清理密钥</label><input type="text" name="tuchang_cron_key" required>
      <label>优选域名（分享链接副域）</label><input type="text" name="tuchang_preferred_host" value="tuchang.naxid.top">
      <h2>TTS 语音</h2>
      <label>接口地址</label><input type="text" name="tts_url" value="https://edgetts.naxid.top/v1/audio/speech">
      <label>Token</label><input type="password" name="tts_token">
      <label>单次最大字数</label><input type="number" name="tts_max_text" value="5000">
      <button type="submit" name="install" value="1">完成安装并锁定</button>
    </form>
  </div>
<?php elseif (!$logged): ?>
  <div class="card">
    <h2>管理员登录</h2>
    <?php if (!empty($err)): ?><div class="msg-err"><?php echo h($err); ?></div><?php endif; ?>
    <form method="post">
      <label>管理员密码（<?php echo h($cfg['admin_user'] ?? 'admin'); ?>）</label>
      <input type="password" name="admin_pass" required autofocus>
      <button type="submit" name="login" value="1">进入配置中心</button>
    </form>
    <p class="hint">配置文件：<?php echo h($CFG_FILE); ?> · 修改请先登录。连续失败会限速。</p>
  </div>
<?php else: ?>
  <div class="top">
    <span>管理员：<?php echo h($cfg['admin_user'] ?? 'admin'); ?></span>
    <form method="post" style="display:inline"><button class="link" type="submit" name="logout" value="1">退出</button></form>
  </div>
  <?php if (!empty($msg)): ?><div class="msg-ok"><?php echo h($msg); ?></div><?php endif; ?>
  <form method="post">
    <?php foreach ($FIELDS as $group => $items): ?>
    <div class="card" style="margin-bottom:16px">
      <h2 style="margin-top:0"><?php echo h($group); ?></h2>
      <?php foreach ($items as $key => $label): ?>
        <label><?php echo h($label); ?> <span style="opacity:0.5"><?php echo h($key); ?></span></label>
        <?php if (in_array($key, $PASS_KEYS, true)): ?>
          <input type="password" name="<?php echo h($key); ?>" placeholder="留空保持不变" autocomplete="new-password">
        <?php else: ?>
          <input type="text" name="<?php echo h($key); ?>" value="<?php echo h($cfg[$key] ?? ''); ?>">
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="card" style="margin-bottom:16px">
      <h2 style="margin-top:0">管理员账号</h2>
      <label>用户名</label><input type="text" name="admin_user" value="<?php echo h($cfg['admin_user'] ?? 'admin'); ?>">
      <label>新密码（留空不改，≥8 位）</label><input type="password" name="new_pass" autocomplete="new-password">
      <label>确认新密码</label><input type="password" name="new_pass2" autocomplete="new-password">
      <label style="display:flex;align-items:center;gap:8px;margin-top:10px"><input type="checkbox" name="sync_img_pass" value="1" style="width:auto" checked> 同步新密码为图床 adminws 管理密令</label>
    </div>
    <button type="submit" name="save" value="1">保存全部（立即生效）</button>
  </form>
  <p class="hint">环境变量 PSU_*（如 PSU_TUCHANG_DB_PASS）优先级高于本面板，适合容器化部署覆盖单项。</p>
</div>
<?php endif; ?>
</body>
</html>
