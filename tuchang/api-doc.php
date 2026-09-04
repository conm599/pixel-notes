<?php
// ================= 陶瓦图床 · API 使用说明 =================
define('TAWA_IMG', true);
require __DIR__ . '/config.php';
$base = base_url();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API 使用说明 · 陶瓦图床</title>
<link rel="stylesheet" href="css/pixel-blue.css?v=13">
<style>
/* API 文档页像素蓝适配（基于 pixel-blue 变量，硬边框+硬阴影） */
.doc-wrap { max-width: 860px; margin: 0 auto; padding: 24px 20px 60px; }
.doc-header { text-align: center; margin-bottom: 32px; }
.doc-header h1 { font-family: 'Press Start 2P', var(--font-main); font-size: 20px; color: var(--accent); text-shadow: 0 0 12px rgba(148,190,230,0.4); margin-bottom: 10px; line-height: 1.7; }
.doc-header p { color: var(--text-secondary); font-size: 13px; }
.doc-section { margin-bottom: 36px; }
.doc-section h2 { font-size: 16px; font-weight: 700; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid var(--border-color); color: var(--text-primary); }
.doc-section h3 { font-size: 14px; font-weight: 700; margin: 18px 0 8px; color: var(--accent); }
.doc-section p { font-size: 13px; line-height: 1.8; color: var(--text-primary); margin-bottom: 8px; }
.doc-section ul { list-style: none; padding: 0; }
.doc-section li { font-size: 13px; line-height: 2; padding-left: 16px; position: relative; }
.doc-section li::before { content: '·'; position: absolute; left: 0; color: var(--accent); font-weight: bold; }
.endpoint { background: var(--bg-panel); border-radius: 2px; padding: 16px 18px; margin-bottom: 14px; border: 2px solid var(--border-color); box-shadow: var(--shadow-hard); }
.endpoint-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
.method { font-family: var(--font-main); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 2px; text-transform: uppercase; border: 1px solid var(--border-color); }
.method.get { background: rgba(148,190,230,0.15); color: var(--accent); }
.method.post { background: rgba(80,200,120,0.15); color: #6fd08f; }
.endpoint-path { font-family: ui-monospace, monospace; font-size: 12px; color: var(--text-primary); word-break: break-all; }
.param-table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 12px; }
.param-table th { text-align: left; padding: 6px 10px; border-bottom: 2px solid var(--border-color); color: var(--text-secondary); font-weight: 600; }
.param-table td { padding: 6px 10px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); }
.param-table td:first-child { font-family: ui-monospace, monospace; color: var(--accent); white-space: nowrap; }
.param-table td:nth-child(2) { color: var(--text-secondary); white-space: nowrap; }
.code-block { background: rgba(0,0,0,0.4); border-radius: 2px; padding: 14px 16px; margin: 8px 0; overflow-x: auto; border: 2px solid var(--border-color); box-shadow: var(--shadow-hard); }
.code-block pre { margin: 0; font-size: 12px; line-height: 1.7; white-space: pre-wrap; word-break: break-all; color: var(--text-primary); }
.code-block .lang { font-size: 10px; color: var(--text-secondary); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 1px; }
.resp-block { background: rgba(0,0,0,0.3); border-radius: 2px; padding: 10px 14px; margin: 6px 0; border-left: 4px solid var(--accent); }
.resp-block pre { margin: 0; font-size: 11px; line-height: 1.6; white-space: pre-wrap; word-break: break-all; color: var(--text-primary); }
.badge { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 2px; margin-left: 6px; vertical-align: middle; border: 1px solid var(--border-color); }
.badge.req { background: rgba(239,68,68,0.15); color: #f87171; }
.badge.opt { background: rgba(100,116,139,0.15); color: #94a3b8; }
.note { background: rgba(148,190,230,0.08); border-radius: 2px; padding: 10px 14px; margin: 8px 0; font-size: 12px; line-height: 1.7; border-left: 4px solid var(--accent); }
.warn { background: rgba(245,158,11,0.08); border-radius: 2px; padding: 10px 14px; margin: 8px 0; font-size: 12px; line-height: 1.7; border-left: 4px solid var(--warning); }
.back-link { display: inline-block; margin-bottom: 20px; font-size: 13px; color: var(--accent); text-decoration: none; }
.back-link:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="app">
<div class="doc-wrap">

<div class="doc-header">
  <h1>陶瓦图床 API 使用说明</h1>
  <p>供外部程序 / Minecraft Mod / 自动化脚本调用 · Bearer Key 鉴权 · 无需登录</p>
</div>

<a class="back-link" href="dashboard.php">← 返回控制台</a>

<!-- ==================== 鉴权 ==================== -->
<div class="doc-section">
  <h2>鉴权方式</h2>
  <p>所有 API 请求需要携带 API Key，支持以下三种方式（任选其一）：</p>

  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method get">Header</span>
      <span class="endpoint-path">Authorization: Bearer &lt;your-api-key&gt;</span>
    </div>
  </div>

  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method get">Query</span>
      <span class="endpoint-path">?key=&lt;your-api-key&gt;</span>
    </div>
  </div>

  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method post">Body</span>
      <span class="endpoint-path">key=&lt;your-api-key&gt;（POST 表单字段）</span>
    </div>
  </div>

  <div class="note">
    API Key 为 64 位十六进制字符串，在控制台点击「生成 API Key」获取。请妥善保管，泄露后可在控制台删除并重新生成。
  </div>
</div>

<!-- ==================== 文件夹 ==================== -->
<div class="doc-section">
  <h2>文件夹（归类管理）</h2>
  <p>图片可归入文件夹；删除文件夹时夹内图片自动回到「未归类」，不会删除图片。</p>

  <div class="endpoint">
    <div class="endpoint-head"><span class="method get">GET/POST</span><span class="endpoint-path">?key=KEY&amp;action=folder_list</span></div>
    <div class="resp-block"><pre>{"ok":true,"folders":[{"id":1,"name":"截图","count":12,"created_at":1788440000}]}</pre></div>
  </div>

  <div class="endpoint">
    <div class="endpoint-head"><span class="method post">POST</span><span class="endpoint-path">action=folder_create&amp;name=截图</span></div>
    <div class="resp-block"><pre>{"ok":true,"id":1,"name":"截图"}   // 同名（同用户）拒绝</pre></div>
  </div>

  <div class="endpoint">
    <div class="endpoint-head"><span class="method post">POST</span><span class="endpoint-path">action=folder_rename&amp;id=1&amp;name=新名</span></div>
    <div class="resp-block"><pre>{"ok":true,"name":"新名"}   // 夹不存在返回 404</pre></div>
  </div>

  <div class="endpoint">
    <div class="endpoint-head"><span class="method post">POST</span><span class="endpoint-path">action=folder_delete&amp;id=1</span></div>
    <div class="resp-block"><pre>{"ok":true}   // 夹内图片自动回未归类，不删图</pre></div>
  </div>

  <div class="endpoint">
    <div class="endpoint-head"><span class="method post">POST</span><span class="endpoint-path">action=setfolder&amp;id=42&amp;folder_id=1</span></div>
    <div class="resp-block"><pre>{"ok":true,"folder_id":1}   // folder_id=0 或缺省 = 移出到未归类</pre></div>
  </div>

  <div class="note">
    <b>上传直接入夹</b>：upload 请求带 <code>folder_id</code> 字段（multipart 或 JSON 均可）；夹不存在或非本人时<b>静默回退未归类</b>（旧客户端零影响）。<br>
    <b>列表过滤</b>：list 可带 <code>folder_id</code> 参数（0=未归类，N=指定夹，缺省=全部）；list/get 返回值新增 <code>folder_id</code> 字段（未归类为 0），旧字段不变。
  </div>
</div>

<!-- ==================== 限制 ==================== -->
<div class="doc-section">
  <h2>限制说明</h2>
  <ul>
    <li><b>频率限制：</b>每 60 秒最多 60 次请求（超出返回 429）</li>
    <li><b>上传上限：</b>原始文件 ≤ 10MB，压缩后 ≤ 4MB</li>
    <li><b>图片尺寸：</b>最大边长 8192px，超出自动等比缩小</li>
    <li><b>支持格式：</b>JPG / PNG / WebP / GIF（后端统一转码为 WebP 60%）</li>
    <li><b>空间配额：</b>默认 20MB（可在管理后台单独调整）</li>
    <li><b>图片列表：</b>最多返回最近 100 张</li>
  </ul>
</div>

<!-- ==================== 上传 ==================== -->
<div class="doc-section">
  <h2>上传图片</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method post">POST</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=upload</span>
    </div>
    <p>支持两种上传方式：multipart 表单上传 或 JSON base64 上传。</p>

    <h3>方式一：multipart 表单</h3>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>action</td><td>string</td><td><span class="badge req">必填</span></td><td>固定值 <code>upload</code></td></tr>
      <tr><td>img</td><td>file</td><td><span class="badge req">必填</span></td><td>图片文件（JPG/PNG/WebP/GIF）</td></tr>
      <tr><td>name</td><td>string</td><td><span class="badge opt">可选</span></td><td>图片名称，默认 <code>api-upload</code>，最长 200 字符</td></tr>
      <tr><td>expire</td><td>int</td><td><span class="badge opt">可选</span></td><td>过期时间（秒）：0永久 / 3600 / 86400 / 604800 / 2592000</td></tr>
      <tr><td>share</td><td>int</td><td><span class="badge opt">可选</span></td><td>是否自动创建分享：1是（默认）/ 0否（私有）</td></tr>
    </table>

    <h3>方式二：JSON base64</h3>
    <table class="param-table">
      <tr><th>字段</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>image</td><td>string</td><td><span class="badge req">必填</span></td><td>Base64 编码的图片数据</td></tr>
      <tr><td>name</td><td>string</td><td><span class="badge opt">可选</span></td><td>图片名称</td></tr>
    </table>
    <p style="font-size:12px;color:var(--muted)">JSON 方式 Content-Type 需设为 <code>application/json</code>，action 通过 URL 参数传递。</p>

    <h3>成功响应</h3>
    <div class="resp-block"><pre>{
  "ok": true,
  "id": 42,
  "url": "https://tuchang.naxid.top/i.php?id=42&t=abc123...",
  "url2": "",
  "size": 153600,
  "w": 800,
  "h": 600
}</pre></div>
    <p style="font-size:12px;color:var(--muted)">
      <b>url</b>：分享链接（share=1 时带 token，可直接公开访问）；share=0 时为 i.php?id=X（需登录才能查看）<br>
      <b>url2</b>：优选域名链接（如有配置），否则为空字符串
    </p>
  </div>

  <h3>代码示例</h3>
  <div class="code-block">
    <div class="lang">curl</div>
<pre>curl -X POST "<?php echo e($base); ?>api.php?key=YOUR_KEY&action=upload" \
  -F "img=@/path/to/screenshot.png" \
  -F "name=我的截图" \
  -F "expire=86400" \
  -F "share=1"</pre>
  </div>

  <div class="code-block">
    <div class="lang">Python</div>
<pre>import requests

url = "<?php echo e($base); ?>api.php"
params = {"key": "YOUR_KEY", "action": "upload"}
files = {"img": open("screenshot.png", "rb")}
data = {"name": "我的截图", "expire": "86400", "share": "1"}

resp = requests.post(url, params=params, files=files, data=data)
result = resp.json()
print(result["url"])  # 分享链接</pre>
  </div>

  <div class="code-block">
    <div class="lang">JavaScript (fetch)</div>
<pre>const fd = new FormData();
fd.append('img', fileInput.files[0]);
fd.append('name', '我的截图');
fd.append('share', '1');

const resp = await fetch('<?php echo e($base); ?>api.php?key=YOUR_KEY&action=upload', {
  method: 'POST',
  body: fd
});
const result = await resp.json();
console.log(result.url);</pre>
  </div>

  <div class="code-block">
    <div class="lang">Java (Minecraft Mod)</div>
<pre>HttpPost post = new HttpPost("<?php echo e($base); ?>api.php?key=YOUR_KEY&action=upload");
MultipartEntityBuilder builder = MultipartEntityBuilder.create();
builder.addBinaryBody("img", new File("screenshot.png"));
builder.addTextBody("name", "截图");
builder.addTextBody("share", "1");
post.setEntity(builder.build());
HttpResponse resp = httpClient.execute(post);
String json = EntityUtils.toString(resp.getEntity());
// 用 Gson 解析 json 获取 url 字段</pre>
  </div>
</div>

<!-- ==================== 列表 ==================== -->
<div class="doc-section">
  <h2>获取图片列表</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method get">GET</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=list</span>
    </div>
    <p>返回当前用户最近 100 张图片的信息。</p>

    <h3>成功响应</h3>
    <div class="resp-block"><pre>{
  "ok": true,
  "count": 2,
  "images": [
    {
      "id": 42,
      "name": "我的截图",
      "url": "https://tuchang.naxid.top/i.php?id=42",
      "size": 153600,
      "w": 800,
      "h": 600,
      "created_at": 1723000000,
      "expire_at": 0,
      "hits": 15
    },
    {
      "id": 41,
      "name": "api-upload",
      "url": "https://tuchang.naxid.top/i.php?id=41",
      "size": 81920,
      "w": 400,
      "h": 300,
      "created_at": 1722900000,
      "expire_at": 1722986400,
      "hits": 3
    }
  ]
}</pre></div>
    <p style="font-size:12px;color:var(--muted)"><b>expire_at</b>：0 表示永不过期；非零为 Unix 时间戳。<b>hits</b>：外部访问次数。</p>
  </div>

  <div class="code-block">
    <div class="lang">curl</div>
<pre>curl "<?php echo e($base); ?>api.php?key=YOUR_KEY&action=list"</pre>
  </div>
</div>

<!-- ==================== 图片信息 ==================== -->
<div class="doc-section">
  <h2>获取图片信息</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method get">GET</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=get&id=&lt;id&gt;</span>
    </div>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>id</td><td>int</td><td><span class="badge req">必填</span></td><td>图片 ID</td></tr>
    </table>

    <h3>成功响应</h3>
    <div class="resp-block"><pre>{
  "ok": true,
  "id": 42,
  "name": "我的截图",
  "url": "https://tuchang.naxid.top/i.php?id=42",
  "download": "https://tuchang.naxid.top/api.php?key=YOUR_KEY&action=download&id=42",
  "size": 153600,
  "w": 800,
  "h": 600,
  "created_at": 1723000000,
  "expire_at": 0,
  "hits": 15
}</pre></div>
  </div>
</div>

<!-- ==================== 下载 ==================== -->
<div class="doc-section">
  <h2>下载图片</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method get">GET</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=download&id=&lt;id&gt;</span>
    </div>
    <p>直接返回 WebP 二进制数据，Content-Type 为 <code>image/webp</code>，带 Content-Disposition 下载头。</p>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>id</td><td>int</td><td><span class="badge req">必填</span></td><td>图片 ID</td></tr>
    </table>

    <div class="code-block">
      <div class="lang">curl 下载到文件</div>
<pre>curl -o image.webp "<?php echo e($base); ?>api.php?key=YOUR_KEY&action=download&id=42"</pre>
    </div>
  </div>
</div>

<!-- ==================== 分享 ==================== -->
<div class="doc-section">
  <h2>创建 / 更新分享</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method post">POST</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=share</span>
    </div>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>action</td><td>string</td><td><span class="badge req">必填</span></td><td>固定值 <code>share</code></td></tr>
      <tr><td>id</td><td>int</td><td><span class="badge req">必填</span></td><td>图片 ID</td></tr>
      <tr><td>duration</td><td>int</td><td><span class="badge req">必填</span></td><td>分享时长（秒）：0永久 / 3600 / 86400 / 604800 / 2592000</td></tr>
    </table>

    <h3>成功响应</h3>
    <div class="resp-block"><pre>{
  "ok": true,
  "token": "a1b2c3d4-e5f6-...",
  "url": "https://tuchang.naxid.top/i.php?id=42&t=a1b2c3d4-...",
  "url2": "",
  "until": 0
}</pre></div>
    <p style="font-size:12px;color:var(--muted)"><b>until</b>：0 表示永久有效；非零为到期 Unix 时间戳。</p>
  </div>
</div>

<!-- ==================== 取消分享 ==================== -->
<div class="doc-section">
  <h2>取消分享</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method post">POST</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=unshare</span>
    </div>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>id</td><td>int</td><td><span class="badge req">必填</span></td><td>图片 ID</td></tr>
    </table>
    <div class="resp-block"><pre>{"ok": true}</pre></div>
  </div>
</div>

<!-- ==================== 设置过期 ==================== -->
<div class="doc-section">
  <h2>设置过期时间</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method post">POST</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=setexpire</span>
    </div>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>id</td><td>int</td><td><span class="badge req">必填</span></td><td>图片 ID</td></tr>
      <tr><td>expire</td><td>int</td><td><span class="badge req">必填</span></td><td>过期时间（秒）：0永久 / 3600 / 86400 / 604800 / 2592000</td></tr>
    </table>
    <div class="resp-block"><pre>{"ok": true}</pre></div>
  </div>
</div>

<!-- ==================== 重命名 ==================== -->
<div class="doc-section">
  <h2>重命名图片</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method post">POST</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=rename</span>
    </div>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>id</td><td>int</td><td><span class="badge req">必填</span></td><td>图片 ID</td></tr>
      <tr><td>name</td><td>string</td><td><span class="badge req">必填</span></td><td>新名称，最长 200 字符（自动去除 HTML 标签）</td></tr>
    </table>
    <div class="resp-block"><pre>{"ok": true, "name": "新名称"}</pre></div>
  </div>
</div>

<!-- ==================== 删除 ==================== -->
<div class="doc-section">
  <h2>删除图片</h2>
  <div class="endpoint">
    <div class="endpoint-head">
      <span class="method post">POST</span>
      <span class="endpoint-path"><?php echo e($base); ?>api.php?key=&lt;key&gt;&action=delete</span>
    </div>
    <table class="param-table">
      <tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr>
      <tr><td>id</td><td>int</td><td><span class="badge req">必填</span></td><td>图片 ID</td></tr>
    </table>
    <div class="resp-block"><pre>{"ok": true}</pre></div>
    <div class="warn">删除操作不可撤销，图片文件会从服务器永久移除。</div>
  </div>
</div>

<!-- ==================== 错误处理 ==================== -->
<div class="doc-section">
  <h2>错误处理</h2>
  <p>所有错误均返回 JSON 格式，HTTP 状态码反映错误类型：</p>
  <table class="param-table">
    <tr><th>HTTP 状态码</th><th>含义</th></tr>
    <tr><td>400</td><td>参数错误 / 未知操作</td></tr>
    <tr><td>401</td><td>未登录（会话模式）</td></tr>
    <tr><td>403</td><td>CSRF 校验失败（会话模式）</td></tr>
    <tr><td>404</td><td>图片不存在 / 文件丢失</td></tr>
    <tr><td>429</td><td>请求频率超限（60次/60秒）</td></tr>
    <tr><td>500</td><td>服务器内部错误</td></tr>
  </table>
  <div class="resp-block"><pre>{"ok": false, "err": "图片不存在"}</pre></div>
</div>

<!-- ==================== 完整示例 ==================== -->
<div class="doc-section">
  <h2>完整示例：Minecraft Mod 截图上传</h2>
  <div class="code-block">
    <div class="lang">Java</div>
<pre>// 1. 截图保存到临时文件
File screenshot = new File("screenshot.png");
ImageIO.write(bufferedImage, "png", screenshot);

// 2. 上传到图床
HttpPost post = new HttpPost(
    "<?php echo e($base); ?>api.php?key=YOUR_KEY&action=upload"
);
MultipartEntityBuilder builder = MultipartEntityBuilder.create();
builder.addBinaryBody("img", screenshot);
builder.addTextBody("name", "MC截图_" + System.currentTimeMillis());
builder.addTextBody("share", "1");  // 自动创建分享链接
post.setEntity(builder.build());

HttpResponse resp = httpClient.execute(post);
String json = EntityUtils.toString(resp.getEntity());
JsonObject obj = new Gson().fromJson(json, JsonObject.class);

if (obj.get("ok").getAsBoolean()) {
    String shareUrl = obj.get("url").getAsString();
    // 3. 将分享链接发送到聊天框 / 显示在 GUI
    player.sendMessage(new TextComponent("截图已上传: " + shareUrl));
}</pre>
  </div>
</div>

<div class="doc-section">
  <p style="text-align:center;color:var(--muted);font-size:12px;margin-top:40px">
    陶瓦图床 API · Powered by 7毛钱虚拟主机 · PHP <?php echo phpversion(); ?><br>
    <a href="dashboard.php" style="color:var(--accent);text-decoration:none">返回控制台</a>
  </p>
</div>

</div>
</div>
</body>
</html>
