<?php
// ================= 陶瓦图床 · 主界面 =================
define('TAWA_IMG', true);
require __DIR__ . '/config.php';
require_login();

$uid = (int)$_SESSION['uid'];
$uname = $_SESSION['uname'];
$myUuid = my_uuid();
cleanup_expired();

$used = user_used($uid);
$quota = user_quota($uid);
$quotaPct = $used >= $quota ? 100 : (int)round($used * 100 / $quota);

// 内部传输 API Key（跨域优选调用用，无需 Cookie；无 Key 时自动生成）
$st = db()->prepare('SELECT api_key FROM img_api_keys WHERE uid = ? AND enabled = 1 ORDER BY id DESC LIMIT 1');
$st->execute(array($uid));
$krow = $st->fetch();
if (!$krow) {
    $apiKey = api_key_new();
    db()->prepare('INSERT INTO img_api_keys (uid, api_key, created_at) VALUES (?,?,?)')->execute(array($uid, $apiKey, time()));
} else {
    $apiKey = $krow['api_key'];
}

// 初始视图（SPA 前端接管：all=全部 / 0=未归类 / N=指定夹；切夹零请求零跳转）
$folderView = isset($_GET['folder']) ? (string)$_GET['folder'] : 'all';
$curFolder = ($folderView === 'all') ? null : (int)$folderView;

// 图片列表（SPA 全量：视图过滤交给前端 spa.js，切夹零请求零跳转）
$st = db()->prepare('SELECT id, file, name, size, w, h, created_at, expire_at, hits, share_token, share_until, folder_id FROM img_images WHERE uid = ? ORDER BY id DESC');
$st->execute(array($uid));
$imgs = $st->fetchAll();

$base = base_url();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>陶瓦图床 · <?php echo e($uname); ?></title>
<link rel="stylesheet" href="css/pixel-blue.css?v=13">
</head>
<body>
<div class="app">

  <div class="topbar">
    <div class="brand">陶瓦<span>图床</span></div>
    <div class="top-user">
<?php if (!empty($_SESSION['is_admin'])): // 与 adminws 门禁同一标记 ?>
      <a class="link-btn" href="adminws.php" title="管理后台">⚙ 管理</a>
<?php endif; ?>
      <button type="button" class="link-btn" id="apiToggle" title="开发者 API Key">🔑 API</button>
      <a class="link-btn" href="<?php echo e('https://' . siblingHost('bianqian') . '/index.php'); ?>" title="返回便签">🏠 便签</a>
      <div class="avatar"><?php echo e(strtoupper(substr($uname, 0, 1))); ?></div>
      <span><?php echo e($uname); ?></span>
      <a class="link-btn" href="logout.php">退出</a>
    </div>
  </div>

  <div class="glass quota">
    <span class="quota-label">空间</span>
    <div class="quota-bar"><div class="quota-fill" style="width:<?php echo $quotaPct; ?>%"></div></div>
    <span class="quota-num"><b><?php echo fmt_size($used); ?></b> / <?php echo round($quota / 1048576); ?> MB</span>
  </div>

  <div class="glass" id="apiPanel" style="padding:18px 22px;margin-bottom:18px;display:none">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <div style="font-size:14px;font-weight:700">开发者 API</div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px">
          供外部程序 / Minecraft Mod 调用：上传、列表、下载（无需登录，Bearer 鉴权）<br>
          <a href="api-doc.php" style="color:var(--accent);text-decoration:underline">查看 API 使用说明 →</a>
        </div>
      </div>
      <button class="sm-btn" id="apigen" style="padding:9px 16px">生成 API Key</button>
      <button class="sm-btn danger" id="apidel" style="padding:9px 16px;display:none">删除 Key</button>
    </div>
    <div id="apibox" style="display:none;margin-top:14px">
      <div class="code-row">
        <label>API Key</label>
        <input id="apikey" readonly>
        <button class="copy-btn">复制</button>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:8px;line-height:1.8">
        用法：<code style="color:var(--accent)">Authorization: Bearer &lt;key&gt;</code> 或 <code style="color:var(--accent)">?key=&lt;key&gt;</code><br>
        <span id="apilast" style="color:var(--muted)"></span>
      </div>
    </div>
  </div>

  <div class="dropzone" id="dz">
    <div class="dz-icon">🖼</div>
    <div class="dz-title">拖拽图片到这里，或点击选择</div>
    <div class="dz-sub">自动压缩为 <span>WebP 60%</span> · 支持 JPG / PNG / WebP / GIF · 单张上限 4MB</div>
    <input type="file" id="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
    <div style="margin-top:14px">
      <select id="expSel" style="padding:8px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(0,0,0,0.25);color:var(--ink);font-size:13px;outline:none">
        <option value="0">永不过期</option>
        <option value="3600">1 小时后过期</option>
        <option value="86400">1 天后过期</option>
        <option value="604800">7 天后过期</option>
        <option value="2592000">30 天后过期</option>
      </select>
    </div>
  </div>

  <div class="queue" id="queue"></div>

  <div class="crumbs" id="folderCrumbs"></div>
  <div class="folder-bar" id="folderBar"></div>

  <div class="grid-title">
    <h2>我的图片</h2>
    <span class="cnt"><?php echo count($imgs); ?> 张 · 点击缩略图可放大查看
      <?php if (count($imgs) > 0): ?> · <a href="javascript:void(0)" id="selAll" style="color:var(--accent);text-decoration:none">全选</a><?php endif; ?>
    </span>
  </div>

  <div class="grid"></div>
  <div class="empty" style="display:none"><div class="big">☁️</div>这里还没有图片</div>

  <div class="footer">
    陶瓦图床 · 前端压缩 WebP 60% · 图片自动剥离元数据<br>
    Powered by 7毛钱虚拟主机 · PHP <?php echo phpversion(); ?>
  </div>
</div>

<div class="toast" id="toast"></div>

<!-- 多选操作栏 -->
<div class="bulkbar glass" id="bulkbar">
  <span class="bulk-count" id="bulkCount">已选 0 张</span>
  <button class="sm-btn" id="bulkShare">批量分享</button>
  <button class="sm-btn" id="bulkZip">打包下载 ZIP</button>
  <button class="sm-btn danger" id="bulkDel">批量删除</button>
  <button class="sm-btn" id="bulkCancel">取消</button>
</div>

<!-- 批量分享结果弹窗 -->
<div class="modal-mask" id="shareBatchDlg" style="display:none" data-overlay="1">
  <div class="modal glass">
    <button class="modal-x" data-close="shareBatchDlg" aria-label="关闭">✕</button>
    <h3>批量分享完成</h3>
    <div class="share-result" style="margin-top:4px">
      <button class="copy-btn" style="width:100%;margin-bottom:10px;padding:9px" id="copyAllLinks">复制全部链接</button>
      <div class="batch-links" id="batchLinks" style="max-height:280px;overflow-y:auto"></div>
    </div>
  </div>
</div>

<!-- 分享拟态框 -->
<div class="modal-mask" id="shareDlg" style="display:none" data-overlay="1">
  <div class="modal glass">
    <button class="modal-x" data-close="shareDlg" aria-label="关闭">✕</button>
    <h3 class="share-name">分享图片</h3>
    <label class="share-label">分享时长</label>
    <select class="share-dur" style="width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(0,0,0,0.25);color:var(--ink);font-size:14px;outline:none">
      <option value="3600">1 小时</option>
      <option value="86400">1 天</option>
      <option value="604800">7 天</option>
      <option value="2592000">30 天</option>
      <option value="0">永久有效</option>
    </select>
    <button class="btn-primary share-go" style="margin-top:16px" id="shareGoBtn">创建分享</button>
    <div class="share-result" style="display:none;margin-top:16px">
      <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px">分享链接（携带 token，未登录访问需 token）</h3>
      <div class="code-grid">
        <div class="code-row"><label>主域名</label><input id="s-url" readonly><button class="copy-btn">复制</button></div>
        <div class="code-row"><label>优选</label><input id="s-url2" readonly><button class="copy-btn">复制</button></div>
        <div class="code-row"><label>Markdown</label><input id="s-md" readonly><button class="copy-btn">复制</button></div>
        <div class="code-row"><label>HTML</label><input id="s-html" readonly><button class="copy-btn">复制</button></div>
        <div class="code-row"><label>BBcode</label><input id="s-bb" readonly><button class="copy-btn">复制</button></div>
      </div>
    </div>
  </div>
</div>

<!-- 文件夹分享弹窗 -->
<div class="modal-mask" id="folderShareDlg" style="display:none" data-overlay="1">
  <div class="modal glass">
    <button class="modal-x" data-close="folderShareDlg" aria-label="关闭">✕</button>
    <h3 class="share-name" id="fsName">文件夹分享</h3>
    <label class="share-label">分享时长</label>
    <select class="share-dur" id="fsDur" style="width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(0,0,0,0.25);color:var(--ink);font-size:14px;outline:none">
      <option value="1">1 小时</option>
      <option value="24" selected>1 天</option>
      <option value="168">7 天</option>
      <option value="720">30 天</option>
      <option value="0">永久有效</option>
      <option value="-1">撤销分享（链接失效）</option>
    </select>
    <button class="btn-primary" id="fsGo" style="margin-top:16px;width:100%">生成 / 更新链接</button>
    <div class="share-result" id="fsResult" style="display:none;margin-top:16px">
      <h3 style="font-size:13px;color:var(--muted);margin-bottom:10px">分享链接（携带 token，未登录访问需 token）</h3>
      <div class="code-row" style="margin-bottom:6px"><label>主域名</label><input id="fsUrlMain" readonly><button class="copy-btn" data-copy="fsUrlMain">复制</button></div>
      <div class="code-row"><label>优选</label><input id="fsUrlPref" readonly><button class="copy-btn" data-copy="fsUrlPref">复制</button></div>
      <div id="fsUntil" style="font-size:12px;color:var(--muted);margin-top:8px"></div>
    </div>
  </div>
</div>

<script>
var CSRF = <?php echo json_encode($csrf); ?>;
var BASE = <?php echo json_encode($base); ?>;
var CURRENT_UUID = <?php echo json_encode($myUuid); ?>;
var API_MAIN_HOST = <?php echo json_encode(siblingHost('tuchang')); ?>;
var CUR_FOLDER = <?php echo json_encode($curFolder); ?>;
</script>
<script src="js/spa.js?v=8"></script>
<script src="js/selection.js?v=9"></script>
<script src="js/dashboard.js?v=26"></script>
</body>
</html>
