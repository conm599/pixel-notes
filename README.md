# Pixel Suite · 像素套件（便签 + 图床）

> **主文档 V9**（2026-09-08 合并重写）：两个子项目（bianqian 便签 / tuchang 图床）的说明已合并为本文件，子项目级 README 已移除——根 README 即唯一项目文档，版本号以代码实际 `?v=` 引用为准。

> 📚 **文档导航**
> - 🚀 [傻瓜式部署教程（从零到上线，30 分钟）](docs/部署教程-傻瓜式.md)
> - 📝 [便签使用教程（从入门到大师，四级难度）](docs/便签使用教程-从入门到精通.md)
> - 🖼 [便签↔图床图片联动计划与实施记录](docs/计划-便签图床图片联动.md)
> - 🤖 [AI 编辑协议 protocol.md（唯一事实源）](bianqian/protocol.md)

一个仓库管理同一套服务的两个站点，部署于同一台 VPS，共享登录（父域 Cookie）：

| 站点 | 目录 | 域名 | VPS 路径 |
|---|---|---|---|
| 📝 便签 Pixel Notes | `bianqian/` | bianqian.naxid.top | /var/www/hosting |
| 🖼 陶瓦图床 | `tuchang/` | tuchang.naxid.top / mcft.naxid.top（CF 回源优选） | /var/www/tuchang |

技术栈：原生 PHP + MySQL + 原生 JavaScript，零框架、零构建、零 Composer 依赖。

---

## 一、现行版本号（V9 基准，以模板 `?v=` 引用为准）

> ⚠️ JS/CSS **文件头注释里的版本号普遍滞后**（如 app.js 头写 v5、tts.js 头写 v3），真正生效的版本号是 PHP 模板引用处的 `?v=` 缓存穿透参数。

**便签 bianqian/**

| 文件 | 版本 | 职责 |
|---|---|---|
| js/app.js | **v85** | 前端总交互：编辑面板、AI 设置/编辑流（SSE 解析）、图床联动初始化、保存防竞态 |
| js/md.js | **v41** | PixelMD 渲染器（表格/任务列表/高亮/上下标/脚注/媒体白名单） |
| css/pixel.css | **v60**（index；share 用 v59、login/register/admin/tts 用 v54） | 粉色像素风设计系统 |
| js/selection.js | **v5** | Windows 式多选/剪贴板（鼠标长按 500ms、触屏 700ms） |
| js/ai-direct.js | **v16** | 浏览器直连 AI（协议 v9：SKIP 锚匹配 + 空闲看门狗） |
| js/imgbridge.js | **v3** | 便签↔图床图片联动桥（唯一联动入口） |
| js/tts.js | **v11** | 朗读工坊（语速/音色/卡拉OK字幕/SRT/Canvas 合成字幕视频） |
| js/auth.js | **v9** | 登录/注册页脚本 |
| js/admin.js | **v4** | 管理页（AI 连通性测试、密钥复制） |
| js/tutorial-data.js | v1 | 教程数据（`tools/build-tutorial.js` 生成，勿手改） |
| js/Sortable.min.js | 1.15.2 | 第三方拖拽排序 |
| protocol.md | **v9** | AI 编辑协议（保留 v5→v9 全部历史章节） |
| api/ai.php | 1941 行 | AI 核心（edit + classify 整理 Agent + 密钥池 + SSRF 防护）⚠️ 文件头注释仍写 v8，实际已实现 v9 |
| api/auth.php | 454 行 | 注册/登录/验证码/改密/注销账号，三层限流 |
| api/notes.php | 404 行 | 便签 CRUD/分享/排序 + 图床引用同步 + 2000 字摘要传输 |
| api/folders.php | 325 行 | 文件夹树（嵌套/环检测/删除内容上移） |
| api/tts.php | 139 行 | TTS 代理（隐藏上游 Token、限流） |

**图床 tuchang/**

| 文件 | 版本 | 职责 |
|---|---|---|
| js/dashboard.js | **v26** | 控制台主逻辑（主备 API 降级、WebP 压缩、上传队列并发 2、批量操作） |
| css/pixel-blue.css | **v13** | 像素蓝设计系统（Press Start 2P、硬边框、扫描线） |
| js/spa.js | **v8** | SPA 视图引擎 v2（list 一次拉全量、客户端过滤、切夹零请求） |
| js/selection.js | **v9** | Windows 桌面式多选（自便签移植，剪贴板 sessionStorage 持久化） |
| js/view.js | **v3** | 图片详情页交互 |

配置中心：`/admini/` 面板（suite-config.php 22+ 项 + internal_key；读取链 `PSU_* 环境变量 > suite-config.php > 代码默认`）。

### V9 相对旧文档的勘误与新增

- ✏️ 勘误：根 README v8.2 写 dashboard.js v25 → 实际 **v26**；旧便签 README 声称「长按 400ms」→ 实际鼠标 **500ms / 触屏 700ms**；旧便签 README 写协议 v7 → 实际 **v9**
- ➕ 补齐旧文档缺失的版本：tts.js v11 / auth.js v9 / admin.js v4
- ➕ 补齐旧文档缺失的章节：便签↔图床图片联动、TTS 朗读工坊、备份系统、/admini 配置中心、动态域名体系

---

## 二、目录结构

```
pixel-suite/
├── README.md               本文档（V9 合并版）
├── bianqian/   便签 → VPS /var/www/hosting
│   ├── index.php（主面板）/ login.php / register.php / logout.php
│   ├── share.php（便签+文件夹公开分享）/ backup.php（备份/导入/合并）
│   ├── tts.php（朗读工坊）/ captcha.php（图形验证码）
│   ├── admin.php（AI/邮件管理页）/ admini/（/admini 部署配置中心）
│   ├── api/（ai / auth / notes / folders / tts 五个 PHP）
│   ├── config/（database.php 建表自愈 + mailer.php 零依赖 SMTP）
│   ├── css/ js/（版本见上表）
│   ├── ai-proxy-worker.js（CF Workers 透明反代模板，用户自部署绕 CORS）
│   ├── protocol.md（AI 编辑协议 v9，唯一事实源）
│   └── _deploy/_diag/_test_* 等下划线开发文件（生产可删）
├── tuchang/    图床 → VPS /var/www/tuchang
│   ├── index.php（重定向入口）/ dashboard.php（SPA 控制台外壳）
│   ├── view.php（详情页，防撞库）/ i.php（图片直链出口）/ s.php（token 分享图）
│   ├── fshare.php（文件夹分享访客页）/ api.php（全部业务 API）
│   ├── api-doc.php（对外 API 文档）/ adminws.php（管理台）
│   ├── cron.php（过期清理，?key= 触发）/ captcha.php
│   ├── config.php（套件共享配置 + 会话/CSRF/配额/占位图）
│   ├── js/ css/（版本见上表）/ assets/notfound.webp（失效占位图）
│   ├── data/（限频守卫文件，gitignore）/ nginx/（mcft.conf + revproxy.conf 归档）
│   └── login.php / register.php（仅 302 跳便签主站，账号统一）
├── admini/     合并仓办公用配置中心副本（部署时只取 bianqian/admini/）
├── docs/       部署教程 / 便签教程 / 联动计划与实施记录 / tuchang-gitlog.txt
└── tools/      build-tutorial.js（教程 md → JS 数据构建）
```

---

## 三、便签 Pixel Notes（bianqian/）

### 3.1 便签核心

- **Markdown 渲染**（md.js v41 自研 PixelMD）：标题/加粗/斜体/删除线/行内代码/代码块/**管道表格（含对齐语法）**/两级嵌套列表/任务列表/引用/链接/图片；扩展语法：高亮 `==x==`、上标 `^x^`、下标 `~x~`、脚注 `[^n]`
- **内嵌媒体白名单**：`<img>` / `<audio>` / `<video>`（含 poster）/ `<iframe>`（B站 / YouTube / Vimeo / Dailymotion / 网易云 / Spotify），按属性白名单重建受控标签杜绝 XSS；混合内容防御（协议相对地址补 https）；像素风自绘播放器
- **拖拽排序**：便签卡片 / 文件夹卡片 Sortable.js 自由拖拽，批量 `reorder` 接口一次同步
- **卡片级置顶**：`pinned DESC` 强制优先；**六色便签**白名单；**单便签 60000 字硬上限**
- **首屏性能**：列表 2000 字摘要传输 + 零请求文件夹切换（`renderFromMemory()` 纯内存过滤），`?id=` 按需拉单条全文；搜索面板条目带面包屑路径可点击跳转
- **公开分享**：便签 token（36 位 UUID，可选有效期，过期自动清）+ **文件夹公开分享**（`share.php?f=`，BFS 递归导出子树上限 500，面包屑导航）；分享页只读、CSP nonce、不泄露作者信息

### 3.2 文件夹系统

- `pn_folders` 任意深度嵌套，移动带环检测，同父重名拒绝
- 递归便签计数（含所有子文件夹）；删除非空文件夹时子项自动上移，绝不丢数据

### 3.3 Windows 式多选与剪贴板（selection.js v5）

- 鼠标长按 **500ms** / 触屏 **700ms** 进入选择模式，橡皮筋框选，Ctrl+A/X/C/V 跨文件夹剪贴板，v6.8 长按对调手势兼容

### 3.4 AI 能力（api/ai.php + ai-direct.js v16，协议 v9）

- **通道**：① 平台密钥（北京时间每日 8:00 重置）② 自有 Key（经管理员透明代理，每用户每日限额）③ 自有代理浏览器直连（`ai-direct.js` + 用户自部署 `ai-proxy-worker.js` 绕 CORS）
- **AI 编辑**：OpenAI 兼容接口、任意模型；深度思考开关；SSE 流式逐 token 转发（上游不支持自动降级）；澄清提问多轮（**不扣每日配额**，只有最终执行轮扣）、自纠错重试（最多 3 轮）；**差异确认**：行级 diff / 渲染预览 / 源码三视图，点采纳才写回；**协议 v9**：SEARCH 锚点最小化省 token、`<<<SKIP>>>` 省略锚、两级 SKIP 匹配 + 块隔离、SSE 心跳（15s `: hb` 防 nginx 掐断）、直连空闲看门狗
- **自有 Key 隐私**：默认只存浏览器 localStorage，勾选「跨端同步」才存服务器（own_body_key/own_body_json 支持自定义 Body 参数如 `reasoning_effort`），取消同步即删；管理员上游配置永不下发浏览器
- **整理 Agent（classify）**：八种操作（移动/建夹/改名/删除/排序/颜色/置顶/写便签），工具白名单（`list_folder` / `read_note`），整理轮上限 20、编辑轮工具上限 5；before/after 快照存 `pn_ai_actions` 支持撤销，撤销资格绑定浏览器会话
- **安全**：SSRF 防护（`aiEndpointHostSafe` DNS 钉取）、JSON-only 防 CSRF、错 5 次锁 15 分钟

### 3.5 TTS 朗读工坊（tts.php + tts.js v11 + api/tts.php）

- 独立页面：语速/音色调节（OpenAI 名 → Edge 中文音色映射）、逐字卡拉OK字幕、SRT 字幕下载、Canvas 合成字幕视频（MP4/WebM）
- `api/tts.php` 代理隐藏上游 Bearer Token，登录校验 + 参数白名单 + 限流

### 3.6 便签↔图床图片联动（imgbridge.js v3，2026-09-06 上线）

详见 [第五章](#五便签--图床图片联动已上线)。便签侧要点：三入口（编辑器粘贴 / 工具栏📤含手机相册 / AI 对话框🖼）、首次同意《图床使用规范》三键弹窗、保存 diff 同步图床引用（消失 → 30 天反悔期，复活 → 恢复永久）、上传中禁止保存防竞态。

### 3.7 站点管理

- **admin.php**：AI 接口配置（key 掩码防覆盖）+ 测试连接、AI 密钥池（genkey/delkey/togglekey/resetkey，绑定用户+每日限额）、SMTP 保存+测试发信、邮箱白名单
- **backup.php**：导出全库 JSON / 导入（管理员邮箱验证码 + 事务清库重灌）/ 跨账号便签合并
- **captcha.php**：4 位去混淆图形验证码（5 分钟过期、一次性）；**注销账号**（auth.php `deleteaccount`）
- **/admini/ 配置中心**（admini/index.php）：独立会话 `ADMINI_SID`；首次访问安装向导；可视化改 6 组 22+ 项配置（SMTP/域名/双站 DB/图床常量/TTS），写入 webroot 上一级 `suite-config.php`（nginx 不可达）

---

## 四、陶瓦图床（tuchang/）

### 4.1 上传与存储

- **上传方式**：全窗口拖拽 + 点击多选（`<input multiple>`），队列并发 2 + 进度条；前端 canvas 压缩 WebP 60%（≤4096px），后端 GD 重编码二次兜底（剥 EXIF、魔数白名单 JPG/PNG/WebP/GIF）
- **有效期**：永久 / 1h / 1 天 / 7 天 / 30 天可选；限频 30 张 / 10 分钟
- **存储**：图片落盘 webroot 之外私有目录（`../private_img_store/`），配额默认 20MB/用户（管理台可单调至 100GB）

### 4.2 图片出口与分享（红线机制，一行不动）

- **i.php 直链出口**：双轨缓存——本人 immutable 一年 + ETag/304 省带宽；外部 token 访问 no-store（计数准确、停分享秒失效）；外部访问 hits+1；失效一律 200 + `notfound.webp` 占位图
- **s.php 单图分享**：36 位 hex token，输出主域名 + PREFERRED_HOST 优选双链接（五格式：主域/优选/Markdown/HTML/BBcode）
- **fshare.php 文件夹分享**：token 只读访客页，分层导航 + 懒加载 + lightbox，不泄露作者信息
- **view.php 详情页防撞库**：URL 必须带本人 `?u=<uuid>`，`hash_equals` 校验

### 4.3 API 体系（api.php，51KB）

1. **API Key 模式**（Bearer/`?key=`/POST/JSON 四通道，60 次/分）：upload / list / folder_list / folder_create·rename·move·delete / setfolder / get / download / share / unshare / setexpire / rename / delete / sharebatch / delbatch / zip（打包下载）；上传自动归档「api」夹
2. **会话模式**（登录 + CSRF）：上述全部 + copybatch（复制=物理副本）+ folder_share（文件夹分享）+ getkey/genkey/delkey + upload `folder=notes`（联动归档）；GET 白名单仅放 list/folder_list/folder_share_info（绕线路 POST 丢包）
3. **内部端点 `internal_imgs`**：`X-Internal-Key` 强校验，服务端对服务端，`op=grace` / `op=restore`（联动专用，见第五章）
- **api-doc.php**：对外 API 文档页（含 Java/Minecraft Mod 示例）

### 4.4 控制台与管理

- **dashboard.php SPA**：配额条 / API Key 面板 / 上传队列 / 文件夹树（嵌套 ≤5 层、环检测、删除子项上移绝不删图）/ SPA 网格懒加载 / Windows 式多选剪贴板 / 批量分享、批量 ZIP、批量删除 / 五格式分享链接 / 「🏠 便签」互跳
- **adminws.php 管理台**：管理密码 + 验证码登录（限频 10 次/600 秒）；用户列表（配额/已用）、单独调配额、删户（验证码 + 二级令牌双校验，连图片文件一并删）
- **cron.php**：`?key=CRON_KEY` 触发惰性过期清理（过期图落盘删除、过期 token 置空、限频文件清理），由面板监控任务调用
- **账号统一**：login/register 仅 302 跳便签主站；`ensure_pn_account()` 以 `img_users.pn_uid ↔ pn_users.id` 自动建档关联

---

## 五、便签 ↔ 图床图片联动（已上线）

> 计划与实施全记录：[docs/计划-便签图床图片联动.md](docs/计划-便签图床图片联动.md)（v1.1 已获批，2026-09-06 上线生产，QA 全绿）

**用户流程**：编辑器粘贴图片 / 点 📤（工具栏，三端含手机相册）/ AI 对话框粘贴或点 🖼 → 首次弹出《图床使用规范》（✅同意 / ⏭暂不 / 🚫不再询问，可设置里改）→ 浏览器直连图床上传（同 PHP 会话，不经便签服务器中转）→ 自动归档根级「便签」夹（删除可自动重建）→ 永久分享 → 光标插入直链 `i.php?id=<id>&t=<token>`。

**删除反悔机制（服务端闭环）**：便签保存/删除时服务端 diff 提取直链 token → 消失引用经内部端点设 **30 天反悔期**（先全库校验无其他便签引用防误杀；只动永久图，不覆盖用户自设有效期）→ 引用复活（Ctrl+Z）恢复永久 → 前端 toast 明示。

**技术通道**：

| 环节 | 实现 |
|---|---|
| 跨域直连 | 图床 `corsOriginOk()` 仅放行同 host / 兄弟站（bianqian.\<域\>）/ 显式配置 / localhost |
| CSRF | 图床 `GET action=csrf` 只读播种端点（no-store） |
| 会话 | 父域 Cookie 共享登录 + 双 Cookie 抢救（`pnRescueDoubleCookie` / `tawaRescueDoubleCookie`，9-07 上线修手机登录循环） |
| 服务端间 | `action=internal_imgs` + `X-Internal-Key` 头对 `internal_key` 强校验；`pn_uid` 换算图床 uid；可配 `tuchang_internal_url=127.0.0.1` 绕 hairpin |
| 数据库 | 便签 `pn_users` + img_consent / img_consent_at / img_policy_ver 三列（自愈 ALTER）；图床**零表变更** |
| 隐私语义 | 链接即能力——便签公开分享=图公开；图床删除后便签显示 notfound 占位图 |

---

## 六、共享架构

### 6.1 共享登录（父域 Cookie）

- session Cookie 挂动态父域（24H 滑动过期，HttpOnly+Secure+SameSite=Lax），一次登录双站通用
- `siblingHost()` 规则：显式配置 > 当前 host `bianqian.`↔`tuchang.` 前缀互换 > 回退 `*.naxid.top`；IP/localhost → host-only Cookie
- 双 Cookie 抢救：同名多 PHPSESSID 并存时自动切换到含用户身份的有效会话（治手机 WebView 登录循环）
- `PSU_LOCAL_HTTP=1` 放宽本地 http 开发

### 6.2 动态域名（第二域名绑定）

两站 cookie 父域与站间互跳全部动态推导，绑第二个域名无需改代码：DNS 解析两个子域 + nginx `server_name` 追加 + 证书即可。`mcft.naxid.top` 为第三 vhost（Cloudflare Origin 证书走 CF 回源直连线路，nginx/mcft.conf）。

### 6.3 配置体系

```
PSU_* 环境变量（兼容旧 PIXEL_*） > suite-config.php（webroot 上一级） > 代码默认值
```

- 模板：`admini/suite-config.example.php`（admin 账号、双站 DB、图床 8 常量、TTS）
- ⚠️ 代码实际还引用 `internal_key` / `img_policy_ver` / `tuchang_internal_url` / `app_timezone` / `email_whitelist`，example 模板未列出，实际由 /admini 安装向导生成
- 便签 `config/database.php`：自愈式建表（幂等 CREATE + ALTER 迁移）+ `siblingUrl()` 动态域名 + 安全会话；`config/mailer.php`：零依赖 SMTP（465 SSL / 587 STARTTLS），白名单优先

### 6.4 数据库总览

- 便签库（9 表，InnoDB utf8mb4）：`pn_users` / `pn_notes` / `pn_folders` / `pn_settings` / `pn_login_attempts` / `pn_email_codes` / `pn_ai_keys`（密钥池）/ `pn_user_ai_prefs` / `pn_ai_actions`（AI 撤销快照）
- 图床库（4 表）：`img_users` / `img_folders` / `img_images` / `img_api_keys`

---

## 七、红线（改代码前必读）

1. 图床分享链接机制（s.php token / view.php / i.php / share_urls / PREFERRED_HOST）**一行不动**，只允许调用
2. adminws.php 密令门禁逻辑**一行不动**
3. 便签协议 protocol.md 是唯一事实源；改 css/js 必须 bump 引用 `?v=`（VPS HK 缓存 8H），HTML 必须 no-store
4. 便签 `config/database.php` **含生产 DB 密码，禁止整文件覆盖，只能 VPS 原位改**（改完必须 curl 实际响应头验证，不能只看 php -l）
5. AI 编辑协议改动必须先改 protocol.md，再同步 api/ai.php 与 ai-direct.js 两端

## 八、部署

- 便签 → VPS /var/www/hosting/（paramiko SFTP 或 `_deploy.php` curl+token 通道；php -l + sha256 闭环）
- 图床 → VPS /var/www/tuchang/（整目录可覆盖，config.php 与本仓同步）
- 部署序（联动类改动）：**先图床后便签**（新端点先就位）
- 部署后 `chown www-data:www-data` 相应文件；详细步骤见 [docs/部署教程-傻瓜式.md](docs/部署教程-傻瓜式.md)

**本地开发**：`php -S localhost:8080 -t bianqian` + `php -S localhost:8081 -t tuchang`，启动注入 `PSU_*_DB_*` 环境变量；同机不同端口 Cookie 天然互通（会话桥）；本地 http 下 CSP 需 `PSU_LOCAL_IMG_HOST=http://localhost:8081`。

## 九、开发与测试文件（下划线开头，生产可删）

| 文件 | 用途 |
|---|---|
| bianqian/_deploy.php | 站长部署上传工具（curl + token 密令直传，sha256 校验闭环） |
| bianqian/_diag.php / _chkcols.php | 诊断：DB 列自愈 / 打印 prefs 列 |
| bianqian/_test_match.js / _test_skip.* / _test_sse.js | AI 匹配器 / SKIP 锚 / SSE 解析单测（从发货代码提取真实函数体测） |
| bianqian/_chunktest.php / _thinktest.php | 长文分段逻辑 / 上游连通性测试 |
| docs/tuchang-gitlog.txt | 图床 9-6 前 git log 快照（之后的联动开发见计划文档 §12） |

## 十、已知不一致点（待顺手修）

1. `bianqian/share.php` 引用 pixel.css `?v=59`，index.php 已是 v60（差一版）
2. `bianqian/api/ai.php` 文件头注释仍写「以 protocol.md v8 为准」，实际已实现 v9
3. `admini/suite-config.example.php` 缺 `internal_key` 等五个代码实际引用的键
4. `tuchang/assets/glass.css` 已被 pixel-blue.css 取代，无引用（遗留文件）
