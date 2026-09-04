<?php
// ================= 陶瓦图床 · 配置与函数库 =================
if (!defined('TAWA_IMG')) { http_response_code(403); exit; }

// ================= 强制 HTTPS（防降级 / 中间人攻击） =================
$isHttps = !empty($_SERVER['HTTPS']) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if (!$isHttps) {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}
header('Strict-Transport-Security: max-age=31536000');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '86400'); // 登录态服务端有效期 24H（每次请求滑动续期）

// ==== 套件共享配置（/admini 面板管理）：环境变量 PSU_* > 共享配置文件 > 代码默认 ====
// 探测顺序：VPS 布局 /var/www/suite-config.php（两站 webroot 的上一级自动共享）→ 项目根 suite-config.php（本地测试）
$GLOBALS['SUITE_CFG'] = array();
foreach (array(@__DIR__ . '/../suite-config.php', @__DIR__ . '/../../suite-config.php') as $__suiteFile) {
    if (is_file($__suiteFile)) { $GLOBALS['SUITE_CFG'] = (array)include($__suiteFile); break; }
}
function suite_cfg($key, $default) {
    $e = getenv('PSU_' . strtoupper($key));
    if ($e === false || $e === '') $e = getenv('PIXEL_' . strtoupper($key)); // 兼容旧 PIXEL_DB_* 约定
    if ($e !== false && $e !== '') return $e;
    return isset($GLOBALS['SUITE_CFG'][$key]) && $GLOBALS['SUITE_CFG'][$key] !== '' ? $GLOBALS['SUITE_CFG'][$key] : $default;
}

// ================= 配置区（按需修改） =================
define('DB_HOST', suite_cfg('tuchang_db_host', 'localhost'));
define('DB_USER', suite_cfg('tuchang_db_user', ''));
define('DB_PASS', suite_cfg('tuchang_db_pass', '')); // 凭证由 /admini 面板或环境变量提供，不写死在代码
define('DB_NAME', suite_cfg('tuchang_db_name', ''));
define('INVITE_CODE', suite_cfg('tuchang_invite_code', ''));       // 注册邀请码（/admini 面板可改）
define('ADMIN_PASS', suite_cfg('tuchang_admin_pass', '')); // adminws 管理密码（/admini 面板可改）
define('USER_QUOTA', (int)suite_cfg('tuchang_user_quota', 20 * 1024 * 1024)); // 默认每用户配额（adminws 可单独调整）
define('MAX_UPLOAD', (int)suite_cfg('tuchang_max_upload', 10 * 1024 * 1024)); // 上传文件最大（mod 截图 PNG 较大，后端压缩后须 ≤4MB）
define('MAX_COMPRESSED', (int)suite_cfg('tuchang_max_compressed', 4 * 1024 * 1024)); // 压缩后最大
define('MAX_DIM', (int)suite_cfg('tuchang_max_dim', 8192));                // 图片最大边长
define('IMG_DIR', suite_cfg('tuchang_img_dir', __DIR__ . '/../private_img_store/'));
define('CRON_KEY', suite_cfg('tuchang_cron_key', ''));      // cron 清理密钥
define('EXPIRE_OPTIONS', suite_cfg('tuchang_expire_options', '0,3600,86400,604800,2592000')); // 永不过期,1小时,1天,7天,30天
define('PREFERRED_HOST', suite_cfg('tuchang_preferred_host', 'tuchang.naxid.top')); // 优选域名（CF Worker 反代），分享链接副域名

// 生成分享双链接：主域名 + 优选域名
function share_urls($tok) {
    global $isHttps;
    $scheme = $isHttps ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $main = $scheme . '://' . $host . '/s.php?t=' . $tok;
    $pref = $scheme . '://' . PREFERRED_HOST . '/s.php?t=' . $tok;
    return array($main, $pref);
}

// ================= 动态域名支持（第二域名绑定同一套服务） =================
// 从当前请求 host 算 Cookie 父域：tuchang.example.com → .example.com；IP/localhost/注册域直访 → ''（host-only）
function cookieParentDomain() {
    $h = strtolower(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    $h = preg_replace('/:\d+$/', '', $h);
    if ($h === '' || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $h) || $h === 'localhost') return '';
    $parts = explode('.', $h);
    if (count($parts) <= 2) return '';
    return '.' . $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
}
// 兄弟站 host：bianqian.<域名> ↔ tuchang.<域名> 动态互推；非约定前缀退回默认 naxid.top
function siblingHost($want) {
    // 1) 显式配置优先（/admini 面板 bianqian_host/tuchang_host，公共部署者的任意子域名）
    $cfg = suite_cfg($want . '_host', '');
    if ($cfg !== '') return $cfg;
    // 2) 动态推导：当前 host 前缀互换（bianqian.<域> ↔ tuchang.<域>）
    $h = strtolower(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    $h = preg_replace('/:\d+$/', '', $h);
    if (preg_match('/^(bianqian|tuchang)\.([a-z0-9.-]+)$/', $h, $m)) return $want . '.' . $m[2];
    // 3) 回退默认
    return $want . '.naxid.top';
}

// ================= Session 安全 =================
session_set_cookie_params(array(
    'lifetime' => 86400, // 登录态 Cookie 24H（配合服务端 gc_maxlifetime 同值）
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => true,
    'domain'   => cookieParentDomain() // 动态父域：自动适配第二域名
));
// 账号统一过渡：无条件清除 host-only 会话 Cookie 残留（图床会话依赖父域 Cookie，host-only 旧 Cookie 排前会被优先误读）
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', array(
        'expires' => time() - 3600, 'path' => '/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax'
    ));
}
if (defined('TAWA_NO_SESSION')) {
    session_cache_limiter('');
    // 图片出口：仅恢复已有会话（不新建，避免 Set-Cookie 破坏浏览器缓存）
    if (isset($_COOKIE[session_name()])) session_start();
} else {
    session_start();
    // 滑动续期：已登录每次请求重发 24H Cookie（否则固定 24H 后即使一直活跃也会掉线）
    if (!empty($_SESSION['uid'])) {
        setcookie(session_name(), session_id(), array(
            'expires' => time() + 86400, 'path' => '/', 'domain' => cookieParentDomain(),
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax'
        ));
    }
}

header('Content-Type: text/html; charset=utf-8');
// HTML 页面永不缓存（前端资源靠 ?v= 版本号管理失效；i.php 图片出口走 TAWA_NO_SESSION 分支不受影响）
if (!defined('TAWA_NO_SESSION')) {
    header('Cache-Control: no-store, max-age=0');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data: blob:; style-src \'self\' \'unsafe-inline\'; script-src \'self\' \'unsafe-inline\'; connect-src \'self\'; font-src \'self\'; object-src \'none\'; base-uri \'self\'; frame-ancestors \'none\'');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

// ================= 数据库（PDO 单例） =================
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ));
    }
    return $pdo;
}

// ================= 工具函数 =================
// 失效占位图：链接失效/未分享/token 错误时输出 <10KB 的 404 占位图（webp）
// 用 200 + 图片响应（而非 404 状态码）：浏览器 <img> 对 404 响应体不渲染，
// 200 才能让嵌了外链的页面显示占位图而不是裂图空白；直接打开也能看到 404 图
function notfound_img() {
    $f = __DIR__ . '/assets/notfound.webp';
    if (!is_file($f)) $f = __DIR__ . '/assets/notfound.png'; // 兜底旧 PNG
    if (is_file($f)) {
        header('Content-Type: ' . (substr($f, -5) === '.webp' ? 'image/webp' : 'image/png'));
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . filesize($f));
        readfile($f);
    } else {
        // 兜底 1x1 透明 PNG（assets 丢失时不至于 500）
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: 70');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }
    exit;
}
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function fmt_size($b) {
    if ($b >= 1048576) return round($b / 1048576, 2) . ' MB';
    if ($b >= 1024) return round($b / 1024, 1) . ' KB';
    return $b . ' B';
}
function base_url() {
    global $isHttps;
    return ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/';
}
function now() {
    return time();
}
function rand_name() {
    return bin2hex(random_bytes(16));
}
function uuid_v4() {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0F) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3F) | 0x80);
    $h = bin2hex($d);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}
// 账号统一：便签主站登录态自动映射图床账号（img_users.pn_uid 关联 pn_users.id）
function ensure_pn_account() {
    if (!isset($_SESSION['user_id'])) return;
    $pnUid = (int)$_SESSION['user_id'];
    $st = db()->prepare('SELECT id, username, uuid FROM img_users WHERE pn_uid = ? LIMIT 1');
    $st->execute(array($pnUid));
    $row = $st->fetch();
    if (!$row) {
        $uname = isset($_SESSION['username']) ? $_SESSION['username'] : ('pn-' . $pnUid);
        $st2 = db()->prepare('SELECT id, uuid FROM img_users WHERE username = ? AND pn_uid IS NULL LIMIT 1');
        $st2->execute(array($uname));
        $old = $st2->fetch();
        if ($old) {
            db()->prepare('UPDATE img_users SET pn_uid = ? WHERE id = ?')->execute(array($pnUid, (int)$old['id']));
            $row = array('id' => (int)$old['id'], 'username' => $uname, 'uuid' => $old['uuid']);
        } else {
            $uuid = uuid_v4();
            $ins = db()->prepare('INSERT INTO img_users (username, uuid, pass_hash, created_at, quota, quota_b, pn_uid) VALUES (?, ?, ?, ?, 0, ?, ?)');
            $ins->execute(array($uname, $uuid, 'pn-sso', now(), USER_QUOTA, $pnUid));
            $row = array('id' => (int)db()->lastInsertId(), 'username' => $uname, 'uuid' => $uuid);
        }
    }
    $_SESSION['uid'] = (int)$row['id'];
    $_SESSION['uname'] = $row['username'];
    $_SESSION['uuid'] = $row['uuid'];
}
function is_logged_in() {
    if (isset($_SESSION['uid']) && isset($_SESSION['uname'])) return true;
    if (isset($_SESSION['user_id'])) { ensure_pn_account(); return isset($_SESSION['uid']) && isset($_SESSION['uname']); }
    return false;
}
function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}
function login_user($uid, $uname) {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$uid;
    $_SESSION['uname'] = $uname;
    // 懒补 UUID v4 并存入 session（详情页链接携带）
    $st = db()->prepare('SELECT uuid FROM img_users WHERE id = ?');
    $st->execute(array((int)$uid));
    $row = $st->fetch();
    if (!$row || empty($row['uuid'])) {
        $row = array('uuid' => uuid_v4());
        db()->prepare('UPDATE img_users SET uuid = ? WHERE id = ?')->execute(array($row['uuid'], (int)$uid));
    }
    $_SESSION['uuid'] = $row['uuid'];
}
function my_uuid() {
    if (!empty($_SESSION['uuid'])) return $_SESSION['uuid'];
    if (is_logged_in()) {
        $st = db()->prepare('SELECT uuid FROM img_users WHERE id = ?');
        $st->execute(array((int)$_SESSION['uid']));
        $row = $st->fetch();
        $u = $row && !empty($row['uuid']) ? $row['uuid'] : uuid_v4();
        if (!$row || empty($row['uuid'])) {
            db()->prepare('UPDATE img_users SET uuid = ? WHERE id = ?')->execute(array($u, (int)$_SESSION['uid']));
        }
        $_SESSION['uuid'] = $u;
        return $u;
    }
    return '';
}

// ================= CSRF =================
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_ok() {
    // 支持 POST 与 GET（GET 仅用于只读操作如 folder_list，绕开用户线路 POST 响应丢包）
    $t = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($t === '' && isset($_GET['csrf_token'])) $t = (string)$_GET['csrf_token'];
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t);
}

// ================= 频率限制（守卫文件，data/rate_*.php） =================
function rate_check($key, $limit, $window) {
    $f = __DIR__ . '/data/r_' . md5($key) . '.php';
    $raw = @file_get_contents($f);
    $arr = $raw !== false && strlen($raw) > 14 ? json_decode(substr($raw, 14), true) : null;
    if (!is_array($arr)) $arr = array();
    $t = time();
    $arr = array_values(array_filter($arr, function ($x) use ($t, $window) { return $t - (int)$x < $window; }));
    if (count($arr) >= $limit) return false;
    $arr[] = $t;
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0755, true);
    @file_put_contents($f, "<?php exit; ?>" . json_encode($arr));
    return true;
}

// ================= 图片配额（每用户独立，adminws 可调） =================
function user_used($uid) {
    $st = db()->prepare('SELECT COALESCE(SUM(size),0) AS u FROM img_images WHERE uid = ?');
    $st->execute(array((int)$uid));
    return (int)$st->fetch()['u'];
}
function user_quota($uid) {
    $st = db()->prepare('SELECT quota_b FROM img_users WHERE id = ?');
    $st->execute(array((int)$uid));
    $row = $st->fetch();
    return $row && (int)$row['quota_b'] > 0 ? (int)$row['quota_b'] : USER_QUOTA;
}

// ================= 惰性过期清理 =================
function cleanup_expired() {
    $st = db()->prepare('SELECT id, file FROM img_images WHERE expire_at > 0 AND expire_at < ?');
    $st->execute(array(time()));
    $rows = $st->fetchAll();
    $del = db()->prepare('DELETE FROM img_images WHERE id = ?');
    foreach ($rows as $r) {
        @unlink(IMG_DIR . $r['file']);
        $del->execute(array((int)$r['id']));
    }
    // 清理过期分享（token 置空，前端不再显示"已分享"）
    db()->prepare('UPDATE img_images SET share_token = NULL, share_until = 0 WHERE share_until > 0 AND share_until < ?')->execute(array(time()));
    $rateDir = __DIR__ . '/data/';
    foreach (glob($rateDir . 'r_*.php') as $rf) {
        if (is_file($rf) && (time() - filemtime($rf)) > 3600) {
            @unlink($rf);
        }
    }
    return count($rows);
}

// ================= API Key 鉴权 =================
function api_key_new() {
    return bin2hex(random_bytes(32)); // 64 hex
}
// 解析 JSON 请求体（可重复调用）
function json_body() {
    static $in = null;
    if ($in === null) {
        $raw = file_get_contents('php://input');
        $in = json_decode($raw, true);
    }
    return is_array($in) ? $in : array();
}
// 从 Authorization: Bearer xxx / ?key=xxx / POST key / JSON body key 解析 API Key，返回 uid 或 0
function api_auth_user() {
    $key = '';
    $hdr = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $hdr, $m)) {
        $key = strtolower($m[1]);
    } elseif (isset($_GET['key']) && preg_match('/^[a-f0-9]{64}$/', $_GET['key'])) {
        $key = strtolower($_GET['key']);
    } elseif (isset($_POST['key']) && preg_match('/^[a-f0-9]{64}$/', $_POST['key'])) {
        $key = strtolower($_POST['key']);
    } elseif ($key === '') {
        $jb = json_body();
        if (isset($jb['key']) && preg_match('/^[a-f0-9]{64}$/', $jb['key'])) {
            $key = strtolower($jb['key']);
        }
    }
    if ($key === '') return 0;
    $st = db()->prepare('SELECT uid, enabled FROM img_api_keys WHERE api_key = ?');
    $st->execute(array($key));
    $row = $st->fetch();
    if (!$row || !(int)$row['enabled']) return 0;
    db()->prepare('UPDATE img_api_keys SET last_used = ? WHERE api_key = ?')->execute(array(time(), $key));
    return (int)$row['uid'];
}
