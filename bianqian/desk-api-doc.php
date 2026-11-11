<?php
/**
 * 桌面状态屏开放接口与单片机接入技术文档
 * 公开访问，无需登录鉴权
 */
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();

$openApiBase = siblingUrl('bianqian', '/api/open.php');
$tcpHost = '154.219.110.21';
$tcpPort = 8888;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>桌面状态屏 API 与单片机接入文档 · Pixel Notes</title>
    <link rel="stylesheet" href="css/pixel.css?v=112">
    <link rel="stylesheet" href="css/icons.css?v=7">
    <link rel="icon" href="favicon.ico">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <style>
        :root {
            --accent2: #4af0ff;
            --accent-pink: #ff6b9d;
            --code-bg: rgba(13, 13, 31, 0.95);
        }
        body {
            background: #0b0b1a;
            color: #d1d1eb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }
        .doc-container {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 20px 80px;
        }
        .doc-header {
            border-bottom: 2px solid rgba(74, 240, 255, 0.2);
            padding-bottom: 24px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .doc-header-left h1 {
            font-size: 22px;
            color: var(--accent2);
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .doc-header-left p {
            margin: 0;
            font-size: 13px;
            color: #8a8ab0;
        }
        .doc-header-nav {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: rgba(74, 240, 255, 0.08);
            border: 1px solid var(--accent2);
            color: var(--accent2);
            text-decoration: none;
            font-size: 12px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .nav-btn:hover {
            background: var(--accent2);
            color: #0b0b1a;
        }
        .doc-section {
            background: rgba(22, 22, 46, 0.7);
            border: 1px solid rgba(138, 138, 176, 0.2);
            border-radius: 8px;
            padding: 22px 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .doc-section h2 {
            font-size: 16px;
            color: var(--accent-pink);
            border-bottom: 1px dashed rgba(255, 107, 157, 0.3);
            padding-bottom: 8px;
            margin-top: 0;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .doc-section h3 {
            font-size: 14px;
            color: var(--accent2);
            margin: 18px 0 8px;
        }
        .endpoint-card {
            background: rgba(11, 11, 26, 0.85);
            border: 1px solid rgba(74, 240, 255, 0.25);
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 14px;
        }
        .endpoint-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .badge-method {
            font-size: 11px;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 3px;
            text-transform: uppercase;
        }
        .badge-get { background: rgba(74, 240, 255, 0.15); color: var(--accent2); border: 1px solid var(--accent2); }
        .badge-tcp { background: rgba(0, 255, 136, 0.15); color: #00ff88; border: 1px solid #00ff88; }
        .badge-opt { background: rgba(138, 138, 176, 0.2); color: #8a8ab0; border: 1px solid #8a8ab0; }
        .endpoint-url {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            color: #fff;
        }
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin: 12px 0;
        }
        .table-custom th {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 2px solid rgba(74, 240, 255, 0.2);
            color: var(--accent2);
        }
        .table-custom td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(138, 138, 176, 0.15);
            color: #d1d1eb;
        }
        .table-custom code {
            font-family: ui-monospace, monospace;
            color: var(--accent2);
            background: rgba(0,0,0,0.3);
            padding: 2px 5px;
            border-radius: 3px;
        }
        pre {
            background: var(--code-bg);
            border: 1px solid rgba(74, 240, 255, 0.2);
            border-radius: 6px;
            padding: 14px 16px;
            font-size: 12px;
            line-height: 1.6;
            overflow-x: auto;
            color: #98c379;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            margin: 10px 0;
        }
        .alert-box {
            background: rgba(74, 240, 255, 0.07);
            border-left: 4px solid var(--accent2);
            padding: 10px 14px;
            font-size: 12px;
            margin: 12px 0;
            border-radius: 0 4px 4px 0;
            line-height: 1.7;
        }
        .alert-danger {
            background: rgba(255, 74, 110, 0.08);
            border-left: 4px solid var(--danger);
            color: #ffa8ba;
        }
    </style>
</head>
<body>
<div class="doc-container">

    <!-- 顶栏导航 -->
    <header class="doc-header">
        <div class="doc-header-left">
            <h1><i class="ic ic-gamepad"></i> 桌面状态屏 API 技术文档 (v1.2)</h1>
            <p>专为 ESP32-C3 墨水屏 / IPS 硬件屏幕与桌面伴侣打造 · 支持同端口明文 TCP 与标准 HTTP/S</p>
        </div>
        <div class="doc-header-nav">
            <a href="index.php" class="nav-btn"><i class="ic ic-back"></i> 返回便签主页</a>
            <a href="https://github.com/conm599/pixel-notes" target="_blank" rel="noopener" class="nav-btn"><i class="ic ic-star"></i> GitHub 源码</a>
        </div>
    </header>

    <!-- 1. 核心设计与快速概览 -->
    <section class="doc-section">
        <h2><i class="ic ic-info"></i> 1. 设计哲学与核心原则</h2>
        <p>传统硬件接入 Web 接口往往面临 <b>HTTPS/TLS 握手内存消耗大（~40KB RAM）</b>、<b>HTTP 请求头解析繁琐</b> 等痛点。Pixel Notes 桌面状态屏开放接口专门针对 ESP32-C3 等低算力单片机进行了全栈优化：</p>
        <ul>
            <li><b>免 HTTP/TLS 协议栈的同端口明文 TCP 直连（首选）</b>：单片机直接通过 Raw TCP Socket 连接 <code>8888</code> 端口，发单行指令即收紧凑 JSON，单次耗时压缩至 10~50ms，节约 80% 单片机堆内存。</li>
            <li><b>客户端主动拉取（Pull-Only）</b>：无需维护长连接 WebSocket，极简轮询，ESP32 可以在传输完毕后立即进入 Deep Sleep 低功耗休眠。</li>
            <li><b>安全字符集与 30 字截断</b>：吐槽文本强制转换为 <b>GB2312 字符集</b>并自动截断至 30 字内，无 Emoji/换行符，防墨水屏点阵乱码。</li>
            <li><b>同端口公网天气代理</b>：集成 <code>WEATHER</code> 代理指令与 30 分钟本地缓存（过北京时间凌晨 01:00 自动失效），无需单片机二次发起网络请求或维护第三方 Token。</li>
            <li><b>3 级安全防误触遗忘机制</b>：管家吐槽支持上下文继承；若想换人设重新开始，提供严密的 3 级安全邮箱验证码清空通道。</li>
        </ul>
    </section>

    <!-- 2. 鉴权与密钥申请 -->
    <section class="doc-section">
        <h2><i class="ic ic-key"></i> 2. 密钥申请与鉴权方式</h2>
        <div class="alert-box">
            <b>密钥申请方式</b>：登录便签系统，点击右上角「设置」菜单（手机端为抽屉栏）→「桌面状态屏」，点击「生成密钥」即可获得专属 <code>pn_desk_xxxx</code> 令牌。
        </div>

        <h3>HTTP/HTTPS 请求鉴权（3 种方式，任选其一）</h3>
        <table class="table-custom">
            <thead>
                <tr>
                    <th>方式</th>
                    <th>格式示例</th>
                    <th>推荐程度</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><b>自定义请求头</b></td>
                    <td><code>X-API-Key: pn_desk_973f824dcd77d5ae7d387ab6f31c24c9</code></td>
                    <td><span class="badge-method badge-get">首选推荐</span> 安全且无 URL 泄露风险</td>
                </tr>
                <tr>
                    <td><b>标准 Bearer 头</b></td>
                    <td><code>Authorization: Bearer pn_desk_xxxx</code></td>
                    <td><span class="badge-method badge-opt">标准兼容</span> 适用于标准 HTTP 库与网关</td>
                </tr>
                <tr>
                    <td><b>URL 查询参数</b></td>
                    <td><code>?key=pn_desk_xxxx</code> 或 <code>?api_key=pn_desk_xxxx</code></td>
                    <td><span class="badge-method badge-opt">备用</span> 适用于无 Header 自定义能力的简易客户端</td>
                </tr>
            </tbody>
        </table>
    </section>

    <!-- 3. 同端口明文 TCP 规范 -->
    <section class="doc-section">
        <h2><i class="ic ic-terminal"></i> 3. 明文 TCP Socket 直连接入（Port 8888，MCU 首选）</h2>
        <div class="alert-box">
            <b>服务端连接地址</b>：<code><?php echo htmlspecialchars($tcpHost); ?>:<?php echo (int)$tcpPort; ?></code>（或域名 <code>bianqian.naxid.top:<?php echo (int)$tcpPort; ?></code>）<br>
            <b>协议规则</b>：单片机发送单行指令（以 <code>\n</code> 结尾），服务端处理完毕回传数据并<b>由服务端主动关闭连接</b>，单片机 0 负担。
        </div>

        <table class="table-custom">
            <thead>
                <tr>
                    <th>指令功能</th>
                    <th>单片机发送内容（以 \n 结尾）</th>
                    <th>服务端响应内容与行为</th>
                    <th>响应延迟</th>
                </tr>
            </thead>
            <tbody>

                <tr>
                    <td><b>获取管家吐槽</b></td>
                    <td><code>ROAST &lt;API_KEY&gt;\n</code></td>
                    <td>以 0.1s/字 逐字流式向 Socket 吐出纯文本，传输完毕追加 <code>\n</code> 并断开（模型接口限流放宽至<b>每分钟最多 2 次</b>）</td>
                    <td>~4-6s</td>
                </tr>
                <tr>
                    <td><b>同端口代理天气</b></td>
                    <td><code>WEATHER &lt;API_KEY&gt; [city|adcode]\n</code></td>
                    <td>需携带有效 Desk Key（保护第三方积分额度），代理 uapis 天气服务并带 30 分钟本地缓存（过北京时间凌晨 01:00 自动失效），回传紧凑天气 JSON 并断开</td>
                    <td><b>&lt;0.05s</b> (缓存命中)</td>
                </tr>
                <tr>
                    <td><b>给管家离线留言</b></td>
                    <td><code>MSG &lt;API_KEY&gt; &lt;留言内容&gt;\n</code></td>
                    <td>每天限 10 次，自动添加时间点注入记忆末尾，不实时触发 AI，回传 <code>OK MESSAGE RECORDED (REMAIN: X)\n</code> 并断开</td>
                    <td><b>&lt;0.05s</b></td>
                </tr>
                <tr>
                    <td><b>程序员历史今天</b></td>
                    <td><code>PROGRAMMER_TODAY &lt;API_KEY&gt;\n</code> (或 <code>HISTORY &lt;API_KEY&gt;\n</code>)</td>
                    <td>需携带有效 Desk Key，同端口代理 uapis 程序员历史事件集（带 12 小时本地缓存（过北京时间凌晨 01:00 自动失效）），回传完整事件 JSON 并断开</td>
                    <td><b>&lt;0.05s</b> (缓存命中)</td>
                </tr>
                <tr>
                    <td><b>随机一言名句</b></td>
                    <td><code>SAYING &lt;API_KEY&gt;\n</code> (或 <code>HITOKOTO\n</code>)</td>
                    <td>需携带有效 Desk Key，同端口代理 uapis 一言（<b>不缓存</b>，每次调用实时获取随机金句），回传紧凑 JSON 并断开</td>
                    <td><b>~0.15s</b> (实时直连)</td>
                </tr>
                <tr>
                    <td><b>1. 拉取 10 本书单 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>BOOKS &lt;API_KEY&gt;\n</code> (或 <code>NOVEL_SHELF\n</code>)</td>
                    <td><b>第一级：全量获取推送到设备的 10 本书单菜单</b>。回传单行紧凑 JSON（仅 300~500 字节，远低于 1200 字节静态缓冲）：<code>{"total":1,"current":"...","books":[{"i":1,"id":"...","title":"凡人修仙","author":"...","page":2,"pages":7753,"pts":1794397774}]}</code>。设备端以此渲染列表菜单，按键上下选择想看哪本。每本书含 <code>pts</code>（进度最后更新时间戳，10位 Unix 秒），设备用于判定“本地断网兜底进度是否比云端新”，<code>page</code> 无进度时返回 1（不返 0），<code>pages</code> 永远 &gt; 0。</td>
                    <td><b>&lt;0.05s</b></td>
                </tr>
                <tr>
                    <td><b>2. 进入正文阅读 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>NOVEL &lt;API_KEY&gt; &lt;PAGE&gt; [序号1-10]\n</code></td>
                    <td><b>第二级：点进选中的书读正文（300 字切片）</b>。例如读第 2 本书第 1 页发 <code>NOVEL &lt;KEY&gt; 1 2\n</code>（单片机只需记整数序号 1~10！）。服务端固定按 300 字切片（单包 ~800 字节，严格 &lt;1KB，纯 UTF-8 剔除非 GB2312 字符无 Emoji，绝不爆 8KB 环形缓冲与 79KB 空闲堆），回传 <code>{"title":...,"page":1,"pages":...,"text":...}\n</code>。断电续读仅需将 <code>book_i</code> 和 <code>page</code> 写入 NVS。</td>
                    <td><b>~0.08s</b></td>
                </tr>
                <tr>
                    <td><b>获取今日状态</b></td>
                    <td><code>STATUS &lt;API_KEY&gt; [PAGE] [BOOK_ID]\n</code></td>
                    <td>回传 <code>{"ts":...,"d":...,"open":1,"diary":1,"todo":0}\n</code> 并断开。<b>支持顺带上报小说阅读进度</b>（省一次 TCP 连接）：当携带 <code>PAGE</code> 时，回包自动追加 <code>"pg_saved":1,"pg_pages":1054</code>。</td>
                    <td><b>~0.1s</b></td>
                </tr>
                <tr>
                    <td><b>3. 上报阅读进度 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>PROGRESS &lt;API_KEY&gt; &lt;PAGE&gt; [BOOK_ID] [AT]\n</code></td>
                    <td><b>单片机防擦写 Flash 专用</b>：自主上报已读页码与设备估算秒 <code>AT</code>。时序消歧回传：<code>{"ok":1,"book_id":"...","title":"...","page":15,"pages":1054,"ts":...,"saved":1}</code>（若断网补报比云端旧则返回 <code>saved:0</code> 并返回云端最新页码；若已被移出书架返回 <code>BOOK_DELETED</code> 提示单片机清空本地标记）。</td>
                    <td><b>&lt;0.05s</b></td>
                </tr>
                <tr>
                    <td><b>番茄单章拉取 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>CHAPTER &lt;API_KEY&gt; &lt;N&gt; [PAGE] [1-10序号或ID]\n</code></td>
                    <td>只拉取某章节切片（首屏更快）。中转层智能补齐：缺省书目自动取当前/第一本，也支持直接传书架序号 1~10；缺省页码默认第 1 页</td>
                    <td>~0.6s</td>
                </tr>
                <tr>
                    <td><b>番茄站内搜索 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>SEARCH &lt;API_KEY&gt; &lt;关键词&gt;\n</code></td>
                    <td>番茄站内搜索书籍（返回 <code>book_id</code> / <code>title</code> / <code>author</code> 等）</td>
                    <td>~2.2s</td>
                </tr>
                <tr>
                    <td><b>番茄章节目录 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>CHAPTERS &lt;API_KEY&gt; [1-10序号或ID] [OFF] [LIM]\n</code></td>
                    <td>获取章节目录列表与总章数。缺省书目自动取当前/第一本，支持传 1~10</td>
                    <td>&lt;0.1s</td>
                </tr>
                <tr>
                    <td><b>番茄选中预热 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>PICK &lt;API_KEY&gt; &lt;BOOK&gt;\n</code></td>
                    <td>选中书籍并在服务端后台开启全本预热，以便翻页实现毫秒级响应</td>
                    <td>&lt;0.1s</td>
                </tr>
                <tr>
                    <td><b>番茄服务状态 <span class="badge" style="background:#ff4d4f;color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;">仅限管理员</span></b></td>
                    <td><code>NOVEL_STATUS &lt;API_KEY&gt;\n</code></td>
                    <td>查询上游番茄下载服务在线状态（设备数、当前预热书、总调用等）</td>
                    <td>&lt;0.08s</td>
                </tr>
                <tr>
                    <td><b>Mock 小说正文 (联调)</b></td>
                    <td><code>MOCK_NOVEL [PAGE]\n</code></td>
                    <td>固定样例小说正文，免鉴权用于设备端分页/字库渲染联调</td>
                    <td>&lt;0.05s</td>
                </tr>
                <tr>
                    <td><b>Mock 状态 (联调)</b></td>
                    <td><code>MOCK_STATUS\n</code></td>
                    <td>回传固定联调状态 JSON（免鉴权）</td>
                    <td>&lt;0.1s</td>
                </tr>
                <tr>
                    <td><b>Mock 吐槽 (联调)</b></td>
                    <td><code>MOCK_ROAST\n</code></td>
                    <td>逐字吐出固定 Mock 吐槽文本（免鉴权）</td>
                    <td>~1.6s</td>
                </tr>
            </tbody>
        </table>

        <h3>天气指令参数示例</h3>
        <ul>
            <li><code>WEATHER &lt;API_KEY&gt; 北京\n</code>：携带密钥按中文城市名精准查询；</li>
            <li><code>WEATHER &lt;API_KEY&gt; 110000\n</code>：携带密钥按 6 位行政区划代码查询；</li>
            <li><code>WEATHER &lt;API_KEY&gt;\n</code>：无城市参数时自动按服务器公网出口 IP 进行地理定位；</li>
            <li><code>{"action":"weather","key":"&lt;API_KEY&gt;","city":"上海"}\n</code>：亦兼容标准 JSON 格式。</li>
        </ul>
    </section>

    <!-- 4. HTTP 接口清单 -->
    <section class="doc-section">
        <h2><i class="ic ic-globe"></i> 4. HTTP / HTTPS 接口清单（基址：<?php echo htmlspecialchars($openApiBase); ?>）</h2>

        <div class="endpoint-card">
            <div class="endpoint-title">
                <span class="badge-method badge-get">GET</span>
                <span class="endpoint-url"><?php echo htmlspecialchars($openApiBase); ?>?endpoint=status</span>
            </div>
            <p>获取当前用户的今日活跃与日记完成状态（只读，不暴露日记正文）。</p>
            <pre>{
  "ts": 1794229999,
  "d": "2026-11-09",
  "open": 1,
  "diary": 1,
  "todo": 0
}</pre>
        </div>

        <div class="endpoint-card">
            <div class="endpoint-title">
                <span class="badge-method badge-get">GET</span>
                <span class="endpoint-url"><?php echo htmlspecialchars($openApiBase); ?>?endpoint=roast</span>
            </div>
            <p>根据管家性格设定、时段与便签状态生成 30 字以内的短句吐槽（HTTP 伪流式逐字输出，频控 60 秒 1 次）。</p>
        </div>

        <div class="endpoint-card">
            <div class="endpoint-title">
                <span class="badge-method badge-get">GET</span>
                <span class="endpoint-url"><?php echo htmlspecialchars($openApiBase); ?>?endpoint=weather&city=北京</span>
            </div>
            <p>公网天气代理接口（需 Header <code>X-API-Key</code> 或 <code>?key=...</code>，保护第三方额度，带 30 分钟本地缓存（过北京时间凌晨 01:00 自动失效）与故障兜底）。</p>
        </div>

        <div class="endpoint-card">
            <div class="endpoint-title">
                <span class="badge-method badge-get">GET</span>
                <span class="endpoint-url"><?php echo htmlspecialchars($openApiBase); ?>?endpoint=novel&action=novel&page=1</span>
            </div>
            <p><b>番茄小说阅读与搜索代理（<span style="color:#ff4d4f;font-weight:bold;">仅限管理员</span>）</b>：需 Header <code>X-API-Key: &lt;管理员DeskKey&gt;</code>。支持 <code>action=novel</code>（取页阅读，单页 300 字）、<code>action=search&q=凡人</code>（站内搜索）、<code>action=chapter&n=12&page=1</code>（单章分页）、<code>action=chapters&book=xxx</code>（目录）、<code>action=pick&book=xxx</code>（预热当前书）、<code>action=status</code>（服务状态）。非管理员调用返回 HTTP 403。</p>
            <pre>GET ?endpoint=novel&action=novel&page=1 响应示例:
{
  "title": "凡人修仙：我的身体状态每日刷新",
  "page": 1,
  "pages": 3169,
  "text": "第1章 身体状态会被强制刷新 ...（单页 300 字纯净文本，剔除非 GB2312 字符）..."
}</pre>
        </div>

        <div class="endpoint-card">
            <div class="endpoint-title">
                <span class="badge-method badge-get">GET</span>
                <span class="endpoint-url"><?php echo htmlspecialchars($openApiBase); ?>?endpoint=saying</span>
            </div>
            <p>随机一言代理接口（需 Header <code>X-API-Key</code>，<b>不缓存</b>，每次调用即时返回最新随机语录/名言名句）。</p>
            <pre>GET 响应示例:
{
  "text": "时光再美，怎如初见。"
}</pre>
        </div>

        <div class="endpoint-card">
            <div class="endpoint-title">
                <span class="badge-method badge-get" style="color:#6fd08f;border-color:#6fd08f;background:rgba(80,200,120,0.15)">POST</span>
                <span class="endpoint-url"><?php echo htmlspecialchars($openApiBase); ?>?endpoint=message</span>
            </div>
            <p>给管家离线留言（需 Header <code>X-API-Key</code>，每日上限 10 次，不触发 AI，自动追加时间戳并注入记忆末尾，在下一次亮屏吐槽时呼应）。</p>
            <pre>POST Body: {"message": "今天跑步5公里，累并快乐着"}</pre>
            <pre>{
  "province": "北京市",
  "city": "北京",
  "adcode": "110000",
  "weather": "晴",
  "weather_icon": "100",
  "temperature": 5,
  "wind_direction": "西南风",
  "wind_power": "2级",
  "humidity": 56,
  "report_time": "10 分钟前发布"
}</pre>
        </div>
    </section>

    <!-- 5. ESP32 Arduino 代码实战 -->
    <section class="doc-section">
        <h2><i class="ic ic-code"></i> 5. ESP32-C3 Arduino 极简代码实战（免 TLS/HTTP 库）</h2>
        <p>使用标准基础 <code>WiFiClient</code>，彻底移除 <code>WiFiClientSecure</code> 与 <code>HTTPClient</code>，零握手负担：</p>

        <pre><code class="language-cpp">#include &lt;WiFi.h&gt;
#include &lt;ArduinoJson.h&gt;

const char* SERVER_IP = "<?php echo htmlspecialchars($tcpHost); ?>";
const uint16_t SERVER_PORT = <?php echo (int)$tcpPort; ?>;
const char* API_KEY = "您的_pn_desk_密钥";

// 1. 获取便签状态（耗时仅约 100ms）
void fetchStatusTcp() {
    WiFiClient client;
    if (client.connect(SERVER_IP, SERVER_PORT, 5000)) {
        client.printf("STATUS %s\n", API_KEY);
        String line = client.readStringUntil('\n');
        client.stop();

        StaticJsonDocument&lt;256&gt; doc;
        if (!deserializeJson(doc, line)) {
            int open  = doc["open"];  // 今日是否打开过便签
            int diary = doc["diary"]; // 今日是否写过便签日记
            Serial.printf("便签状态: open=%d, diary=%d\n", open, diary);
            // TODO: 送入墨水屏/状态指示灯
        }
    }
}

// 2. 同端口代理获取天气（耗时 &lt;50ms）
void fetchWeatherTcp(const char* city = "北京") {
    WiFiClient client;
    if (client.connect(SERVER_IP, SERVER_PORT, 5000)) {
        if (city && strlen(city) > 0) {
            client.printf("WEATHER %s %s\n", API_KEY, city);
        } else {
            client.printf("WEATHER %s\n", API_KEY);
        }
        String line = client.readStringUntil('\n');
        client.stop();

        StaticJsonDocument&lt;512&gt; doc;
        if (!deserializeJson(doc, line)) {
            const char* weather = doc["weather"];
            int temp = doc["temperature"];
            int humidity = doc["humidity"];
            Serial.printf("天气: %s, 气温: %d℃, 湿度: %d%%\n", weather, temp, humidity);
            // TODO: 绘制到天气组件
        }
    }
}

// 3. 流式逐字接收 AI 吐槽
void fetchRoastTcp() {
    WiFiClient client;
    if (client.connect(SERVER_IP, SERVER_PORT, 5000)) {
        client.printf("ROAST %s\n", API_KEY);
        Serial.print("管家吐槽: ");
        
        while (client.connected() || client.available()) {
            while (client.available()) {
                char c = client.read();
                Serial.print(c); // 送入字库渲染
            }
            delay(10);
        }
        Serial.println();
        client.stop();
    }
}</code></pre>
    </section>

    <!-- 6. 3 级验证清空记忆 -->
    <section class="doc-section">
        <h2><i class="ic ic-shield"></i> 6. AI 记忆上下文与 3 级安全验证清空说明</h2>
        <p>为保持管家吐槽的趣味性与连贯性，后端会自动维护用户的多轮吐槽记忆，并内置 200KB 自动滑动窗口压缩。若用户希望重置管家人设或开启全新关系：</p>
        <div class="alert-box alert-danger">
            <b>3 级安全邮箱验证机制</b>：<br>
            清空历史记忆属于不可撤销操作，必须在便签系统网页端（「桌面状态屏」弹窗）点击「清空记忆」，并输入向账号绑定邮箱发送的 6 位动态验证码，验证通过后方可物理重置数据库中的历史上下文。
        </div>
    </section>

    <footer style="text-align:center;color:#8a8ab0;font-size:12px;margin-top:40px;">
        Pixel Notes 桌面状态屏开发团队 · 开源协议 MIT · 2026
    </footer>

</div>
</body>
</html>
