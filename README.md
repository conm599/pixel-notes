# Pixel Suite · 像素套件（便签 + 图床）

> **主文档 V12**（2026-09-24 更新）：两个子项目（bianqian 便签 / tuchang 图床）的说明已合并为本文件，子项目级 README 已移除——根 README 即唯一项目文档，版本号以代码实际 `?v=` 引用为准。

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

## 一、现行版本号（V15 基准，以模板 `?v=` 引用为准）

> ⚠️ JS/CSS **文件头注释里的版本号普遍滞后**（如 app.js 头写 v5、tts.js 头写 v3），真正生效的版本号是 PHP 模板引用处的 `?v=` 缓存穿透参数。

**便签 bianqian/**

| 文件 | 版本 | 职责 |
|---|---|---|
| js/app.js | **v146** | 前端总交互：抽屉导航（E 键）、上下文右键菜单、网盘式移动弹窗、快捷键（T//）、搜索弹层、全屏编辑器实时预览、像素输入弹窗、弹窗过渡双模式 + 关闭对称淡出（X/遮罩/Esc 路径合并）、AI 全屏对话（aiChatShell 公共外壳，编辑/整理同架构；聊天式 SSE 流式 + 打字机渲染 + 深度思考折叠卡 + 工具折叠卡 + 断线自动重试 3 次 + 停止并入发送键 + ⚡一键整理入口 + 内容块按时间顺序交错）、AI 设置/编辑流（SSE 解析）、图床联动初始化、保存防竞态、文件夹分享角标即时同步 |
| js/md.js | **v43** | PixelMD 渲染器（表格/任务列表/高亮/上下标/脚注/媒体白名单；AI 图片尺寸 `<img width>` 支持，px 后缀容错；表格横向滚动容器） |
| css/pixel.css | **v112**（全站统一） | 粉色像素风设计系统（含像素图标三态、文件夹分享角标、抽屉/搜索弹层/移动弹窗样式） |
| css/icons.css | **v7** | 像素图标类名表（v1 实心 / v2 线稿双套 WebP，50+ 语义） |
| js/iconset.js | **v1** | 图标三态切换（混合/经典实心/线稿，父域 Cookie `pn_iconset` 两站互通） |
| js/selection.js | **v7** | Windows 式多选/剪贴板（鼠标长按 500ms、触屏 700ms）+ 图标操作栏 + 「移动到」批量入口 |
| js/ai-direct.js | **v25** | 浏览器直连 AI（协议 v9：SKIP 锚匹配 + 空闲看门狗；推理模型 reasoning 增量回调） |
| js/imgbridge.js | **v4** | 便签↔图床图片联动桥（唯一联动入口） |
| js/tts.js | **v11** | 朗读工坊（语速/音色/卡拉OK字幕/SRT/Canvas 合成字幕视频） |
| js/auth.js | **v9** | 登录/注册页脚本 |
| js/admin.js | **v4** | 管理页（AI 连通性测试、密钥复制） |
| js/tutorial-data.js | **v2** | 教程数据（`tools/build-tutorial.js` 生成，勿手改）——v2 全量覆盖 v5 交互与快捷键 |
| js/Sortable.min.js | 1.15.2 | 第三方拖拽排序 |
| protocol.md | **v9** | AI 编辑协议（保留 v5→v9 全部历史章节） |
| api/ai.php | 2693 行 | AI 核心（edit 编辑 Agent + classify 整理 Agent + 密钥池 + SSRF 防护 + 图片尺寸模板；工具回执含改动处上下文字段；文本协议 tool_call 系工具解析两流通用；think 剥离工厂化；classify max_tokens 对齐 16000） |
| api/auth.php | 469 行 | 注册/登录/验证码/改密/注销账号，三层限流 |
| api/notes.php | 404 行 | 便签 CRUD/分享/排序 + 图床引用同步 + 2000 字摘要传输 |
| api/folders.php | 325 行 | 文件夹树（嵌套/环检测/删除内容上移 + 分享状态） |
| api/tts.php | 134 行 | TTS 代理（隐藏上游 Token、限流、模型/音色走配置） |

**图床 tuchang/**

| 文件 | 版本 | 职责 |
|---|---|---|
| js/dashboard.js | **v30** | 控制台主逻辑（主备 API 降级、WebP 压缩、上传队列并发 2、批量操作、文件夹分享丢包对账） |
| css/pixel-blue.css | **v19** | 像素蓝设计系统（Press Start 2P、硬边框、扫描线） |
| js/spa.js | **v12** | SPA 视图引擎 v2（list 一次拉全量、客户端过滤、切夹零请求、文件夹分享角标） |
| js/selection.js | **v10** | Windows 桌面式多选（自便签移植，剪贴板 sessionStorage 持久化） |
| js/view.js | **v3** | 图片详情页交互 |

配置中心：`/admini/` 面板（suite-config.php 22+ 项 + internal_key；读取链 `PSU_* 环境变量 > suite-config.php > 代码默认`）。

### V9 相对旧文档的勘误与新增

- ✏️ 勘误：根 README v8.2 写 dashboard.js v25 → 实际 **v26**；旧便签 README 声称「长按 400ms」→ 实际鼠标 **500ms / 触屏 700ms**；旧便签 README 写协议 v7 → 实际 **v9**
- ➕ 补齐旧文档缺失的版本：tts.js v11 / auth.js v9 / admin.js v4
- ➕ 补齐旧文档缺失的章节：便签↔图床图片联动、TTS 朗读工坊、备份系统、/admini 配置中心、动态域名体系

### V10 新增（2026-09-23）

- 🖥 **便签全屏编辑器**：新建/编辑共用编辑器从页面内联面板升级为全屏覆盖层（顶栏 + ✕ 关闭 + 淡入淡出），移动端一致体验
- 🎞 **View Transitions 灵动岛动效**：便签卡片 ↔ 详情弹窗之间的 morph 过渡（原生 `document.startViewTransition`，不支持的浏览器自动降级）；修复两处深层 bug——共存型 morph 重复 `view-transition-name` 被浏览器 abort（`invalid state`）、新旧 CSS 规则同优先级静默覆盖（新组件样式统一置文件末尾 + 提权选择器，附文件内注释警示）
- 💬 **AI 编辑弹窗对话式重构**：历史记录区 + 底部输入条（输入框自动增高、撤回/图片/发送三钮）；AI 设置入口迁入导航栏设置菜单
- 🛠 **AI 工具链修复**：`read_note` 恒失败 `invalid_id`——提示词注入当前便签 id、前端补传 `noteId`、服务端 id 容错与回退当前便签；InputBar 图片按钮改「先弹文件选择器 → 图床上传链路」
- 📊 **Markdown 表格横向滑动**：`white-space: nowrap`，窄卡片/访客分享页内不再逐字竖排挤压
- 🎨 **图标系统 v1/v2 + 字体自托管**：Press Start 2P 本地托管（`assets/fonts/`），CSP 收紧移除 Google Fonts 依赖
- 🔍 **AImcp 子项目**：仓库内置 MCP 检索服务（PHP；MySQL/JSON 双存储 + 嵌入/重排 + 管理页 + install.sql）

### V11 新增（2026-09-24）

- 🖱 **上下文右键菜单（动态生成）**：空白处=新建便签/文件夹；便签=打开/编辑/置顶/切换颜色/分享（两态文案）/移动到…/删除；文件夹=打开/建子夹/改名/移动/分享/删除（内容上移）——像资源管理器一样按对象动态变化
- 🚚 **网盘式移动弹窗**：右键「移动到…」→ 单击选中（确认按钮跟随）、双击进入子层级、路径条点击跳级；弹窗内可直接新建文件夹；原地移动自动禁用；文件夹移动排除自身及后代；**触屏单击直接进层**（双击在手机不可靠）
- 🖥 **PC 抽屉式导航**：顶栏只留 LOGO+汉堡（**E** 键或点击伸出），用户名/朗读/图床/设置/退出全部收进抽屉——PC 与手机同一套抽屉 DOM；抽屉补「注销账号」入口
- ⌨️ **键盘快捷键（MC 式）**：`T` 或 `/` 呼出搜索弹层、`E` 开抽屉；输入框聚焦/编辑器打开时自动让路；搜索弹层 Esc/点遮罩关闭，选中结果自动收起
- 📝 **编辑器实时预览**：Markdown 编辑区下方即时渲染（200ms 防抖、空内容自动隐藏、工具栏/AI 写入/图片直链全路径同步刷新）；AI 按钮改纯文字样式移入工具栏最前；去掉底部「取消」按钮与冗余提示文字（右上角 ✕ 唯一关闭入口）；保存按钮独占颜色行右侧
- 📌 **选择条图标化 + 批量移动**：底部操作栏改 34px 纯图标方块（去全部文字防溢出），新增「移动到」按钮——手机长按选中即可批量移动便签/文件夹
- 🐛 **「阅读全文」判定稳定性修复**：图片异步加载完成会让内容长高而截断判定只跑一次 → 有图长文首刷按钮消失、刷新后又出现；现在每张图片 load/error 后重跑判定，并加窗口 resize 防抖重算
- 📖 **新手教程 v2**：教程全量重写——新建/搜索入口对齐新交互、右键菜单、网盘式移动弹窗、选择条移动入口、快捷键速查表、实时预览说明（源文件 `docs/便签使用教程-从入门到精通.md`，`tools/build-tutorial.js` 构建）

### V12 新增（2026-09-24）

- 🖊 **像素风输入弹窗**：新建文件夹 / 文件夹改名不再使用浏览器原生 `prompt`——`openPromptDialog`（标题 + 输入框 + 确定，回车提交 / Esc 或点遮罩取消、自动聚焦全选、空名拦截），与移动弹窗同一套视觉体系
- 🥚 **弹窗过渡双模式**（抽屉设置组开关「🥚 弹窗过渡」）：
  - **默认**：便签卡片 ↔ 阅读弹窗的灵动岛 morph（View Transitions）
  - **编辑式**：弹窗完全退出 View Transition，走全屏编辑器同款策略（overlay 淡入、关闭直接移除）——**机制上不可能出现快照穿模**，适合便签常被滚到屏幕边缘的使用习惯
  - 两套并存，切换即生效（localStorage 记忆）；实现上 `PNVT.morph` 在编辑式模式下直接执行 mutate 返回，零 VT 开销

### V13 新增（2026-09-24）

- 🔚 **编辑式弹窗关闭对称淡出**：此前编辑式模式只有打开有 fadeIn、关闭是瞬时移除，观感不对称——现在关闭先播 150ms `modalFadeOut`（与打开同长）再移除；淡出期间屏蔽连点（防重入标记 + 260ms 保险丝兜底），陈旧的收尾回调有守卫，不会误动后续新弹窗的领养播放器状态；默认 morph 模式一行未动
- 🔀 **关闭触发路径合并**：X 按钮 / 点遮罩 / Esc 统一为无参 `closeModal()`。顺带修复一个历史遗留 bug——X 按钮原先把 click 事件对象当成 `quiet` 参数传入（truthy），走了静默分支导致关闭永远无动画（默认 morph 模式同样受影响，只是有 morph 兜底不易察觉）；`closeModal` 入口增加 `quiet === true` 归一化防复发

### V14 新增（2026-09-24）

- 🧠 **AI 深度思考全链路**：
  - 输入框旁新增 🌐 快捷开关（平台密钥 / 管理员 / 自有 Key 三模式统一生效，一键切换 `enable_thinking`，不用进设置页）
  - 思考内容实时呈现为「💭 深度思考（X.Xs）」折叠卡：首字开始计时、正文出现即冻结时长并自动收起；**多轮工具调用各自起一张卡**（思考→调工具→再思考），不再出现续写串卡或静默丢失
  - 服务端新增 SSE `think` 事件（转发上游 `reasoning_content`；原先 `<think>` 文本块内的思考是被丢弃的），浏览器直连模式同步支持
- ⌨️ **聊天式流式体验**：正文按「打字机」逐字吐出（积压越多吐得越快），上游/代理一次给一大块也保持逐字观感；生成中发送键变 spinner，**再点一次即停止**（不再有独立停止按钮）；连接中断自动重试最多 3 次（用户停止 / 未登录 / 业务错误不重试）
- 🛠 **工具回执可自证**：`replace_text` 成功后额外返回 `changed`（改动处前后内容）与字段语义说明——此前只回文档末尾片段（`current_tail`），改动不在末尾时它跟改前一样，模型会误判「替换没生效」而反复纠结
- ✨ **AI 编辑弹窗全屏化**：改为铺满视口的全屏对话页（内容限宽 900px 居中），与全屏编辑器观感统一

### V15 新增（2026-09-25）

- 🏗 **AI 对话架构收敛**：新增 `aiChatShell` 公共全屏外壳 + 公共按钮工厂（⚡一键整理 / 🌐 深度思考 / ↩ 撤销），**AI 编辑与 AI 整理两个对话共用同一套结构、样式与流式机制**（弹窗级作用域隔离，互不串台）；AI 整理对话同步全屏化，底部动作行与编辑对话同款图标
- 🧵 **内容块按时间顺序交错**：气泡内的思考卡 / 工具卡 / 正文块按「第 N 轮：思考 → 调工具 → 正文」严格排布（此前正文是固定元素，多轮的工具卡会全部堆在正文上方）；空正文块流式期隐藏、收尾移除
- 🧠 **深度思考链路补全**：整理流程同样吃 🌐 开关（此前不生效）；`<think>` 文本块剥离工厂化（`aiThinkAwareDeltaFactory`），编辑/整理两流共用，思考不再混进正文
- 🛠 **整理功能性修复**：classify 循环接入文本协议工具解析（`<tool_call>{...}</tool_call>` / `<function=x>` / `<invoke>` 三种写法，与编辑流同款）——端点未映射原生 tool_calls 的模型也能真正执行整理；空方案且零工具调用时打回一次要求先核实；classify `max_tokens` 4000 → 16000 对齐编辑流（深度思考的 reasoning 计入输出预算，4000 会截断轮次逼出空方案）
- 📋 一键自动整理注入内部指令走手动同路径（不带指令时模型倾向跳过工具直接给结论）

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
│   ├── css/ js/（版本见上表）/ assets/icons/（像素图标双套 WebP）
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
- **AI 图片尺寸**：AI 调整图片大小时输出 `<img src width="360">`（HTML 白名单属性，支持固定像素与百分比；渲染端 px 后缀自动容错）
- **公开分享**：便签 token（36 位 UUID，可选有效期，过期自动清）+ **文件夹公开分享**（`share.php?f=`，BFS 递归导出子树上限 500，面包屑导航）；分享页只读、CSP nonce、不泄露作者信息

### 3.2 文件夹系统

- `pn_folders` 任意深度嵌套，移动带环检测，同父重名拒绝
- 递归便签计数（含所有子文件夹）；删除非空文件夹时子项自动上移，绝不丢数据
- **已分享角标**：文件夹公开分享后卡片即时显示角标（分享/取消分享后区域性更新，零刷新）；操作菜单自动防溢出（贴屏幕边缘自动钳制/翻转）
- **网盘式移动弹窗（v11）**：右键「移动到…」→ 单击选中（确认按钮文案跟随）、双击进入子层级、路径条点击跳级；弹窗内可直接新建文件夹；原地移动自动禁用；文件夹移动排除自身及全部后代；触屏单击直接进层

### 3.3 Windows 式多选与剪贴板（selection.js v7）

- 鼠标长按 **500ms** / 触屏 **700ms** 进入选择模式，橡皮筋框选，Ctrl+A/X/C/V 跨文件夹剪贴板，v6.8 长按对调手势兼容
- 底部操作栏纯图标化（34px 方块：移动到/剪切/复制/粘贴/全选/删除/取消）；**「移动到」批量入口（v11）**——手机长按选中后点它即可弹出网盘式移动弹窗，批量移动自动排除选中文件夹及后代

### 3.4 AI 能力（api/ai.php + ai-direct.js v16，协议 v9）

- **通道**：① 平台密钥（北京时间每日 8:00 重置）② 自有 Key（经管理员透明代理，每用户每日限额）③ 自有代理浏览器直连（`ai-direct.js` + 用户自部署 `ai-proxy-worker.js` 绕 CORS）
- **AI 编辑**：OpenAI 兼容接口、任意模型；深度思考开关；SSE 流式逐 token 转发（上游不支持自动降级）；澄清提问多轮（**不扣每日配额**，只有最终执行轮扣）、自纠错重试（最多 3 轮）；**差异确认**：行级 diff / 渲染预览 / 源码三视图，点采纳才写回；**协议 v9**：SEARCH 锚点最小化省 token、`<<<SKIP>>>` 省略锚、两级 SKIP 匹配 + 块隔离、SSE 心跳（15s `: hb` 防 nginx 掐断）、直连空闲看门狗
- **自有 Key 隐私**：默认只存浏览器 localStorage，勾选「跨端同步」才存服务器（own_body_key/own_body_json 支持自定义 Body 参数如 `reasoning_effort`），取消同步即删；管理员上游配置永不下发浏览器
- **整理 Agent（classify）**：八种操作（移动/建夹/改名/删除/排序/颜色/置顶/写便签），工具白名单（`list_folder` / `read_note`），整理轮上限 20、编辑轮工具上限 5；before/after 快照存 `pn_ai_actions` 支持撤销，撤销资格绑定浏览器会话
- **安全**：SSRF 防护（`aiEndpointHostSafe` DNS 钉取）、JSON-only 防 CSRF、错 5 次锁 15 分钟

### 3.5 TTS 朗读工坊（tts.php + tts.js v11 + api/tts.php）

- 独立页面：语速/音色调节、逐字卡拉OK字幕、SRT 字幕下载、Canvas 合成字幕视频（MP4/WebM）
- `api/tts.php` 代理隐藏上游 Bearer Token，登录校验 + 参数白名单 + 限流
- **OpenAI 兼容可配置**：`/admini/` 面板可改接口地址、模型名（`tts_model`）与音色列表（`tts_voices` 逗号分隔），前端音色下拉随配置生成——任何 OpenAI 兼容上游即插即用

### 3.6 便签↔图床图片联动（imgbridge.js v3，2026-09-06 上线）

详见 [第五章](#五便签--图床图片联动已上线)。便签侧要点：三入口（编辑器粘贴 / 工具栏📤含手机相册 / AI 对话框🖼）、首次同意《图床使用规范》三键弹窗、保存 diff 同步图床引用（消失 → 30 天反悔期，复活 → 恢复永久）、上传中禁止保存防竞态。

### 3.7 站点管理

- **admin.php**：AI 接口配置（key 掩码防覆盖）+ 测试连接、AI 密钥池（genkey/delkey/togglekey/resetkey，绑定用户+每日限额）、SMTP 保存+测试发信、邮箱白名单
- **backup.php**：导出全库 JSON / 导入（管理员邮箱验证码 + 事务清库重灌）/ 跨账号便签合并
- **captcha.php**：4 位去混淆图形验证码（5 分钟过期、一次性）；**注销账号**（auth.php `deleteaccount`）
- **/admini/ 配置中心**（admini/index.php）：独立会话 `ADMINI_SID`；首次访问安装向导；可视化改 7 组 25+ 项配置（SMTP/域名/双站 DB/图床常量/TTS 含模型与音色列表/自动备份开关），写入 webroot 上一级 `suite-config.php`（nginx 不可达）

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

- **dashboard.php SPA**：配额条 / API Key 面板 / 上传队列 / 文件夹树（嵌套 ≤5 层、环检测、删除子项上移绝不删图）/ SPA 网格懒加载 / Windows 式多选剪贴板 / 批量分享、批量 ZIP、批量删除 / 五格式分享链接 / 「🏠 便签」互跳 / 文件夹卡「已分享」角标
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

### 6.5 像素图标系统（两站共用）

- `assets/icons/{v1,v2}/*.webp` 双套图标（V1 实心阴影 / V2 描边线稿，50+ 语义，WebP 无损透明，单枚 <1KB）+ `css/icons.css` 类名表 + `js/iconset.js` 三态切换
- 用户可在设置菜单选择 混合（默认：设置/AI 用线稿、其余实心）/ 经典实心 / 线稿，选择存父域 Cookie `pn_iconset`，**两站自动跟随**，零 SQL
- 新增语义：`agnes-image` 生成 → 品牌调色板（#4af0ff / #ff6b9d / #12122a）吸附 → 网格降采样 → WebP，两站 assets 各放一份

### 6.6 自动备份轮换（/var/www/backups，2026-09-12 上线）

- **备份单元（4 个轮换）**：`config`（suite-config.php）/ `db_bianqian` / `db_tuchang`（mysqldump --single-transaction）/ `assets`（图床图片目录 private_img_store，webroot 之外）
- **开启即全量两份**（A/B 冗余；assets-A/B 为全量基线，永久保留——是老图片的唯一副本）
- **每日增量**：cron（UTC 19:17 = 北京 03:17）按单元清单每天轮换一个文件；assets 只打包最近 7 天内新增/修改的图片
- **7 天保留**：备份文件按 mtime 满 7 天自动清理（基线豁免），存储占用有界
- **开关**：`/admini/` 面板「自动备份」`backup_enabled`（1=开 0=关），关闭后 cron 自动跳过
- **恢复方法**：config 直接拷回；DB `mysql 库名 < dump文件`；图片解 tar 至 `private_img_store`；只要数据库在，配置/代码（git）/图片（基线+增量）均可恢复
- 安装：上传 `pixel_backup.php` 至备份目录 → `php pixel_backup.php init` → 写 cron `17 19 * * * root /usr/bin/php /var/www/backups/pixel_backup.php daily`

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

1. `bianqian/api/ai.php` 文件头注释仍写「以 protocol.md v8 为准」，实际已实现 v9
2. `admini/suite-config.example.php` 缺 `internal_key`/`tts_model`/`tts_voices`/`backup_enabled` 等代码实际引用的键
3. `tuchang/assets/glass.css` 已被 pixel-blue.css 取代，无引用（遗留文件）
4. 图床 icons.css 引用 `play_pink.32.webp` 但文件缺失（登录/TTS 主按钮图标 404，显示为空缺）
