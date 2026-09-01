# 🎮 Pixel Notes · 像素便签系统

一个用 **原生 PHP + MySQL + 原生 JavaScript** 打造的像素风自托管便签系统，零框架、零构建工具、零 Composer 依赖——把整个目录丢到支持 PHP 的虚拟主机上，改 3 行数据库配置就能跑。

> ⚠️ **当前为 v7.5-beta**：本版本包含文件夹系统 / AI 整理 Agent / Windows 式多选 / 首屏性能优化（摘要传输 + 零请求切换）等大量新特性，仍在验收期。上一稳定版 v6.8.1 见 [`stable` 分支](https://github.com/conm599/pixel-notes/tree/stable)。

---

## ✨ 功能总览

### 📝 便签核心
- **Markdown 渲染**：自研渲染器（`js/md.js`），支持标题 / 加粗 / 斜体 / **删除线** `~~text~~` / **管道表格**（含对齐语法）/ **两级嵌套列表** / 任务列表 / 引用 / 链接 / 图片 / 代码块
- **内嵌媒体白名单**：支持 `<img>` / `<audio>` / `<video>`（含 `poster` 封面属性）/ `<iframe>`（B站 / YouTube / Vimeo / **Dailymotion / 网易云音乐 / Spotify**），全部走标签与域名白名单，**杜绝 XSS**
- **iframe 播放器体验**：统一 `md-embed` 响应式 16:9 自适应、lazy loading、`allow` 七项权限注入（fullscreen / picture-in-picture / clipboard-write / encrypted-media / autoplay 等）
- **混合内容防御**：媒体 src 中 `//xxx` 协议相对地址自动补 `https:`、`http://` 强制升级；粘贴带反引号的媒体标签自动脱反引号清洗后再过安全校验
- **像素风自绘播放器**：替换浏览器原生控件，风格统一，支持拖动进度条（对不支持 Range 的服务器自动 blob 预缓存兜底）
- **拖拽排序**：便签卡片 / 文件夹卡片均集成 Sortable.js 自由拖拽，本地动画 + 批量 `reorder` 接口同步（`PUT { reorder: [{id, sort_order}, …] }` 一次同步整批）
- **📌 卡片级置顶**：每张便签卡片右上角独立置顶小按钮一键切换，查询 `pinned DESC` 强制优先
- **长文截断 + 阅读全文**：卡片内容超高自动 `.clamped` + 「📖 阅读全文」按钮弹模态框看完整渲染
- **六色便签**：yellow / pink / blue / green / purple / orange 白名单，非法值强制回退 yellow
- **单便签 60000 字硬上限**：`clipContent()` 统一截断，超长静默裁掉不报错
- **公开分享**：一键生成 token 分享链接可设有效期，分享页只读、不泄露作者信息；**分享 URL 协议自适应**（检测 `$_SERVER['HTTPS']`，反代场景不搞错 http/https）

### 📁 文件夹系统（v7.5-beta）
- **多层嵌套**：`pn_folders` 任意深度，移动带环检测，同父重名拒绝
- **递归便签计数**：文件夹卡片底部实时显示该文件夹 + 所有子文件夹的便签总数，分享视图同样显示
- **删除不丢数据**：删除非空文件夹时子文件夹与便签自动上移至其 parent_id
- **文件夹公开分享**：`share.php?f=<uuid>` 36 位 token，BFS 递归导出子树（防环上限 500 节点），渲染可导航只读视图（面包屑 + 子文件夹卡片 + 便签卡片网格）；token 可生成/刷新/失效，过期自动清

### ✂️ Windows 式多选与剪贴板（v7.5-beta）
- **长按 400ms 激活多选**（触屏同样支持）、**粉色框选**（空白拖拽生成半透明选框划过自动入选）、Ctrl+拖拽加选
- **跨文件夹剪贴板**：Ctrl+X 剪切 / C 复制 / V 粘贴，A 文件夹复制 → 切到 B 粘贴，剪切原子移动、复制克隆新记录
- **剪贴板跨会话保留**：取消选中、切目录、重新搜索均不影响剪贴板，直至下次复制或刷新页面
- **Ctrl+A 视图全选**：只选当前目录/搜索结果内卡片，不跨目录
- **syncUI 高亮恢复**：移动/粘贴等写操作后自动恢复粉色选中高亮，一眼定位结果

### 🔍 搜索
- 搜索面板结果条目显示**面包屑路径**（主页 / 父文件夹 / 子文件夹），点击路径片段跳文件夹，点击便签名直达
- 基于内存索引即时过滤（配合首屏摘要索引，2000 字内正文全覆盖）

### 🤖 AI 编辑（接入任意 OpenAI 兼容接口）
- **局部修改协议**：AI 只返回 `<<<SEARCH>>>/<<<REPLACE>>>/<<<END>>>` 替换块，服务器在原文精确位置应用——**用户没让改的部分（链接、媒体、代码块）机制上不可能被动到**；SEARCH 三级匹配全部要求全文唯一命中（重复句式宁可报错不错改）+ 多块隔离（protocol v8）
- **自纠错循环**：替换块匹配失败自动携带上下文纠错重试（最多 3 轮）
- **差异确认**：行级 diff / 渲染预览 / 源码 三视图，点「采纳覆盖」才写回编辑器
- **🧠 深度思考开关**：AI 设置独立复选框，注入 `enable_thinking:true`（通配各厂商），平台密钥/管理员/自有 Key 三模式均生效
- **自定义 Body 参数**：自有 Key 模式可填 Key/JSON 对（如 `reasoning_effort: "high"`）合并进请求 payload，优先级高于深度思考预设
- **澄清提问不扣配额**：多轮 clarify 对话不消耗每日配额，只有最终执行轮扣
- **管理员上游**：接口地址 / API Key / 模型名存数据库、**永不下发浏览器**
- **AI 密钥池批量管理**：批量生成、按账号绑定、独立每日限额、单把禁用/启用/清零/删除全部二次确认；**北京时间每日 8:00 重置节点**（0-8 点属前一天）
- **SMTP 双模式**：465（SSL）与 587（STARTTLS）均支持，端口校验不过直接报错
- **用户自有 Key 三通道**：①平台透明代理（CF Worker，SSRF 防护+每账号每日 500 次）②自填代理浏览器直连不限量 ③仓库自带 `ai-proxy-worker.js` 一键部署
- **隐私优先**：自有 Key 默认只存浏览器 localStorage；勾选「跨端同步」才存服务器，取消即删

### ✨ AI 整理 Agent（v7.5-beta，protocol v7）
- **八种操作**：移动 / 建夹 / 改名 / 删除 / 排序 / 颜色 / 置顶 / 写便签
- **SSE 透明执行**：`text/event-stream` 逐 token 推送，实时打字显示思考过程与工具调用
- **TOOL 块多步调用**：Agent 先只读查询（读便签全文/列文件夹树）再决策，最多 5 轮
- **一键撤销**：`pn_ai_actions` 存 before/after JSON 快照，撤销含「便签原样复活」；资格绑定当前浏览器会话，关标签页失效
- **程序保底校验**：JSON 解析失败重试、id 归属校验、路径段合法性与深度上限，非法项丢弃并标注
- **清单喂法**：每便签 id+标题+正文前 80 字+现有文件夹树，>50 条分批
- **工具栏直达**：「✨ AI 整理」按钮在工具栏第一顺位，直接唤起整理模态框

### ⚡ 性能优化（v7.5-beta）
- **首屏摘要 2000 字**：列表接口 `content` 只返回前 2000 字（UTF-8 `mb_substr`）打 `_more:1`，首屏响应体积省 80%+
- **全文按需拉取**：打开编辑时前端自动 `GET api/notes.php?id=xxx` 拉完整正文（`_full:1`），编辑器先显示加载态防丢截断部分
- **切文件夹零网络请求**：`switchFolder()` → `renderFromMemory()` 纯内存过滤，不发 HTTP，点击瞬间进入
- **环境变量部署**：`config/database.php` 支持 `PIXEL_DB_HOST/PORT/NAME/USER/PASS` 五个环境变量，优先级高于常量，容器化友好

### 🎛️ 编辑器体验
- **Markdown 工具栏 9 按钮**：粗体、斜体、行内代码、代码块、链接、图片、引用、无序列表、任务列表——点选自动包裹选区
- **三态预览切换 👁**：纯源码 / 左右分栏（左源码右预览）/ 纯预览 循环切换，`md.js` 实时渲染
- **快捷键体系**：Ctrl+Enter 保存、Esc 关闭、Ctrl+S 手动同步、Ctrl+N 新建便签、Ctrl+A 全选
- **🎨 渲染颜色自定义（13 项）**：设置菜单🎨面板修改 CSS 变量（背景/标题/正文/链接/引用/代码块/表格/列表等），实时预览，存 localStorage
- **设置菜单统一收纳**：📖 新手教程（触屏/鼠标自适应文案）、🎨 渲染颜色、⭐ GitHub 开源地址

### 🔐 账号体系
- **邮箱验证注册**：6 位验证码、10 分钟有效、错 5 次作废；主流邮箱域名白名单（管理页可增删），过滤临时邮箱
- **登录仅邮箱**：用户名仅作展示昵称，不参与登录，避免撞名歧义
- **发码三重限流**：同邮箱 60s 冷却、同邮箱每小时 8 次、同 IP 每小时 20 次，三层独立计数
- **爆破锁定**：单账号错 5 次锁 15 分钟
- **防邮箱枚举**：注册验证码接口对存在/不存在邮箱均返回「已发送」，不暴露注册状态
- **图片验证码防 OCR**：4 位去混淆字符 + 噪点 + 干扰线 + 26 个暗色小字符诱饵，5 分钟过期、一次性、点击刷新
- **注销账号级联删表**：事务删除 pn_notes / **pn_folders** / **pn_ai_actions（含便签全文快照）** / **pn_email_codes** / pn_user_ai_prefs / pn_ai_keys / pn_login_attempts / pn_users，隐私承诺全兑现
- **首个注册自动管理员**：库中无管理员时最早注册用户自动升为管理员（`ensureTables()` 兜底）
- **三合一登录页**：密码登录 / 验证码免密登录 / 找回密码

### 📦 备份与迁移
- **全库导出 JSON**：10 张表（users/notes/folders/ai_actions/settings/ai_keys/ai_prefs/email_codes/login_attempts/share_logs），文件名带时间戳
- **两步导入**：Step1 上传校验 → Step2 邮箱验证码核验（purpose=backup，15 分钟有效，管理员本人邮箱）
- **增量合并不覆盖**：同邮箱冲突 → 数据并入「其他账号的数据」根文件夹；新邮箱 → 原样建号
- **敏感表防污染**：`pn_settings`、`pn_ai_keys`、`pn_email_codes`、`pn_login_attempts`、`pn_share_logs` 五表强制不参与导入，防备份包武器化
- **导入列名白名单**：列名只认 `SHOW COLUMNS` 真实列集，上传数据非法 key 整行丢弃（防列名 SQL 注入）
- **导入强制降权**：备份导入的新用户 `is_admin` 一律置 0，绝不因导入提权
- **合并邮件通知**：合并后自动向被合并目标邮箱发 SMTP 邮件，列明来源与便签数量

### 🛡️ 安全设计一览
| 措施 | 位置 |
|---|---|
| 会话 Cookie `Secure + HttpOnly + SameSite=Lax` | `config/database.php` |
| 严格 CSP（`script-src 'self'`，无内联脚本）+ X-Frame-Options + nosniff + Referrer-Policy | 全站 PHP 层注入 |
| CSP frame-src 白名单：B站/YouTube/Vimeo + Dailymotion / 网易云音乐 / Spotify | 全站 PHP 层注入 |
| CSRF 令牌 | 管理页表单 |
| 登录爆破锁定 / 发码三重限流 | `api/auth.php` |
| 防邮箱枚举伪响应 | `api/auth.php` |
| IDOR 防御：所有写操作前校验记录 `user_id` 归属 | notes/folders API |
| API Key 掩码显示、留空不覆盖 | `admin.php` |
| 注销走事务、GET 登出同样销毁会话 | `api/auth.php` / `logout.php` |
| 自愈式建表（缺列自动 ALTER；多 worker 空库并发竞态放行兜底；utf8mb4 失败自动降 utf8 重试；`pn_user_ai_prefs` 四列自愈补齐） | `config/database.php` |
| SSRF 防护：自有 Key 强制透明代理 + host IP/域名校验 | `api/ai.php` |
| 备份导入列名白名单 + 敏感表排除 + is_admin 强制 0 | `backup.php` |
| AI 澄清提问（多轮）：拿不准先问用户，澄清轮不扣配额 | `api/ai.php` / `js/ai-direct.js` |

---

## 📦 环境要求

- PHP ≥ 7.4（需 `pdo_mysql`、`gd`（验证码）、`openssl`（SMTP）、`mbstring` 扩展）
- MySQL ≥ 5.7 / MariaDB ≥ 10.3
- HTTPS（会话 Cookie 强制 Secure；可用 Cloudflare 免费证书）

## 🚀 部署步骤

1. **上传代码**：把仓库全部文件上传到网站根目录
2. **建数据库**：创建一个空库（如 `pixel_notes`），**无需手动建表**——首次访问自愈式建表（含后续升级加列）
3. **改配置**：编辑 `config/database.php`（或用环境变量 `PIXEL_DB_*` 覆盖，优先级更高）：
   ```php
   define('DB_NAME', 'pixel_notes');   // 你的库名
   define('DB_USER', 'pixel_notes');   // 你的库用户
   define('DB_PASS', 'CHANGE_ME');     // 你的库密码
   ```
4. **注册首个账号**：打开 `register.php` 注册——**第一个注册的账号自动成为管理员**
5. **管理员配置**（`admin.php`）：AI 上游 / SMTP（465 SSL 或 587 STARTTLS）/ 邮箱白名单 / AI 密钥池
6. 完成 ✅

> nginx 用户注意：`.htaccess` 仅对 Apache 有效；安全响应头已在 PHP 层统一注入，无需额外配置。

## 📁 目录结构

```
├── index.php            # 主应用（便签墙 + 编辑器 + AI 弹窗）
├── login.php            # 登录/验证码登录/找回密码
├── register.php         # 注册（图片验证码 + 邮箱验证码）
├── admin.php            # 管理页（AI 上游/密钥池/SMTP/邮箱白名单）
├── share.php            # 公开分享只读页（便签 ?t= / 文件夹 ?f=）
├── backup.php           # 全库 JSON 备份导出 + 两步导入
├── tts.php              # 朗读工坊
├── mcp.php              # MCP 服务端（AI 记忆库）
├── captcha.php          # 图片验证码生成（防 OCR 诱饵）
├── logout.php           # 登出（GET/POST 均销毁会话）
├── ai-proxy-worker.js   # 可选：用户自部署的 CF Workers 透明代理
├── api/
│   ├── auth.php         # 注册/登录/发码/改密/注销
│   ├── notes.php        # 便签 CRUD + 摘要/全文双模式 + 分享管理
│   ├── folders.php      # 文件夹树 + 分享 token
│   ├── ai.php           # AI 代理（局部修改协议 + 整理 Agent + 配额）
│   └── tts.php          # TTS 代理（令牌不下发）
├── config/
│   ├── database.php     # 数据库配置 + 自愈建表 + 安全头 + 会话
│   └── mailer.php       # 零依赖 SMTP 客户端（socket 实现）
├── js/
│   ├── app.js           # 主应用逻辑（含本地渲染/多选/剪贴板）
│   ├── selection.js     # 多选模式（长按/框选/Ctrl XCV）
│   ├── ai-direct.js     # AI 浏览器直连模块
│   ├── md.js            # 自研 Markdown 渲染器（媒体白名单）
│   ├── admin.js         # 管理页逻辑
│   └── tts.js           # 朗读工坊逻辑
└── css/pixel.css        # 像素风样式
```

## 🤖 给用户用 AI（管理员视角）

1. **平台供给**：管理页批量生成密钥发给用户（或直接绑定到账号），用量实时可见
2. **用户自带 Key**：建议引导用户部署 `ai-proxy-worker.js` 到自己的 Cloudflare Workers，浏览器直连、不经过你的服务器、不限量

## 📄 许可证

[MIT](LICENSE) — 可自由使用、修改、二次开发、商用，保留版权声明即可。

如果这个项目对你有帮助，欢迎 Star ⭐
