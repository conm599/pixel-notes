# 🎮 Pixel Notes · 像素便签系统

一个用 **原生 PHP + MySQL + 原生 JavaScript** 打造的像素风自托管便签系统，零框架、零构建工具、零 Composer 依赖——把整个目录丢到支持 PHP 的虚拟主机上，改 3 行数据库配置就能跑。

> 自托管在线示例：https://bianqian.naxid.top

---

## ✨ 功能总览

### 📝 便签核心
- **Markdown 渲染**：自研渲染器（`js/md.js`），支持标题 / 加粗 / 斜体 / 列表 / 任务列表 / 引用 / 链接 / 图片 / 代码块
- **内嵌媒体白名单**：支持 `<img>` / `<audio>` / `<video>` / `<iframe>`（B站 / YouTube / Vimeo 等），全部走标签与域名白名单，**杜绝 XSS**
- **像素风自绘播放器**：替换浏览器原生控件，风格统一，支持拖动进度条（对不支持 Range 的服务器自动 blob 预缓存兜底）
- **拖拽排序**：便签卡片自由排序（Sortable.js）
- **公开分享**：一键生成 token 分享链接，可设有效期，分享页只读、不泄露作者信息
- **朗读工坊（TTS）**：便签一键转语音，逐词卡拉 OK 字幕，TTS 令牌只存服务端不下发浏览器

### 🤖 AI 编辑（接入任意 OpenAI 兼容接口）
- **局部修改协议**：AI 只返回 `<<<SEARCH>>>/<<<REPLACE>>>/<<<END>>>` 替换块，服务器在原文精确位置应用——**用户没让改的部分（链接、媒体、代码块）机制上不可能被动到**
- **自纠错循环**：替换块匹配失败时自动携带对话上下文纠错重试（最多 3 轮），失败率大幅下降
- **差异确认**：结果先展示 行级 diff / 渲染预览 / 源码 三视图，用户点「采纳覆盖」才写回编辑器
- **管理员上游**：接口地址 / API Key / 模型名在管理页配置，存数据库、**永不下发浏览器**
- **AI 密钥配额**：管理员给用户发密钥（可绑定账号、设每日限额、禁用/清零/删除均二次确认），北京时间每日 8:00 重置；管理员本人免密钥不限量
- **用户自有 Key 三通道**：
  1. 不填代理 → 经平台服务器透明代理转发（每账号每日 500 次防刷）
  2. 填自己的透明代理 → 浏览器直连，完全不经过平台、不限量
  3. 仓库自带 `ai-proxy-worker.js`，一键部署到自己的 Cloudflare Workers（免费）
- **隐私优先**：自有 Key 默认只存浏览器 localStorage；仅用户主动勾选「跨端同步」才存服务器，取消勾选即删除服务器数据
- **个性化**：风格偏好注入系统提示词；可选「时间感知」让 AI 获取浏览器当前时间（日期/星期/节假日不瞎编）；首次使用强制同意隐私政策与使用政策（版本化，随时可在设置里重看）

### 🔐 账号体系
- **邮箱验证注册**：6 位验证码、10 分钟有效、错 5 次作废；主流邮箱域名白名单（管理页可增删），过滤临时邮箱
- **三合一登录页**：密码登录 / 验证码免密登录 / 找回密码
- **图片验证码防 OCR**：4 位去混淆字符（去掉 O0I1L）+ 噪点 + 干扰线 + **26 个暗色小字符诱饵**（人眼靠「大而亮」秒认真码，OCR 分割困难），5 分钟过期、一次性、点击刷新
- **注销账号**：邮箱验证码核验 + 二次确认，事务删除全部数据
- **改密 / 设置菜单**：导航「⚙️ 设置」统一入口

### 🛡️ 安全设计一览
| 措施 | 位置 |
|---|---|
| 会话 Cookie `Secure + HttpOnly + SameSite=Lax` | `config/database.php` |
| 严格 CSP（`script-src 'self'`，无内联脚本）+ X-Frame-Options + nosniff + Referrer-Policy | 全站 PHP 层注入 |
| CSRF 令牌 | 管理页表单 |
| 登录爆破锁定 / 发码多级限流（邮箱 60s 冷却、每小时 8 次、IP 20 次） | `api/auth.php` |
| 防邮箱枚举（不存在的邮箱返回伪成功响应） | `api/auth.php` |
| API Key 掩码显示、留空不覆盖 | `admin.php` |
| 注销走事务、GET 登出同样销毁会话 | `api/auth.php` / `logout.php` |
| 自愈式建表（缺列自动 ALTER，首次访问即完成部署） | `config/database.php` |

---

## 📦 环境要求

- PHP ≥ 7.4（需 `pdo_mysql`、`gd`（验证码）、`openssl`（SMTP）扩展）
- MySQL ≥ 5.7 / MariaDB ≥ 10.3
- HTTPS（会话 Cookie 强制 Secure；可用 Cloudflare 免费证书）

## 🚀 部署步骤

1. **上传代码**：把仓库全部文件上传到网站根目录
2. **建数据库**：MySQL 里创建一个空库（如 `pixel_notes`），**无需手动建表**——首次访问时程序会自愈式建表（含后续升级加列）
3. **改配置**：编辑 `config/database.php`：
   ```php
   define('DB_NAME', 'pixel_notes');   // 你的库名
   define('DB_USER', 'pixel_notes');   // 你的库用户
   define('DB_PASS', 'CHANGE_ME');     // 你的库密码
   ```
4. **注册首个账号**：打开 `register.php` 注册——**第一个注册的账号自动成为管理员**
5. **管理员配置**（`admin.php`）：
   - **AI 设置**：填 OpenAI 兼容接口地址（如 `https://api.example.com/v1`）、API Key、模型名，点「测试连接」验证
   - **SMTP 设置**：发件服务器（如 QQ 邮箱 `smtp.qq.com:465` + 授权码），用于注册/登录/找回/注销验证码邮件
   - **邮箱白名单**：按需增删允许注册的邮箱域名
   - **AI 密钥**：给用户生成密钥、设每日限额（北京时间 8:00 重置）
6. 完成 ✅

> nginx 用户注意：`.htaccess` 仅对 Apache 有效；安全响应头已在 PHP 层统一注入，无需额外配置。

## 📁 目录结构

```
├── index.php            # 主应用（便签墙 + 编辑器 + AI 弹窗）
├── login.php            # 登录/验证码登录/找回密码
├── register.php         # 注册（图片验证码 + 邮箱验证码）
├── admin.php            # 管理页（AI 上游/密钥/SMTP/邮箱白名单）
├── share.php            # 公开分享只读页
├── tts.php              # 朗读工坊
├── captcha.php          # 图片验证码生成（防 OCR 诱饵）
├── logout.php           # 登出（GET/POST 均销毁会话）
├── ai-proxy-worker.js   # 可选：用户自部署的 CF Workers 透明代理
├── api/
│   ├── auth.php         # 注册/登录/发码/改密/注销
│   ├── notes.php        # 便签 CRUD + 分享管理
│   ├── ai.php           # AI 代理（局部修改协议 + 自纠错 + 配额）
│   └── tts.php          # TTS 代理（令牌不下发）
├── config/
│   ├── database.php     # 数据库配置 + 自愈建表 + 安全头 + 会话
│   └── mailer.php       # 零依赖 SMTP 客户端（socket 实现）
├── js/
│   ├── app.js           # 主应用逻辑
│   ├── ai-direct.js     # AI 浏览器直连模块（独立文件，便于维护）
│   ├── auth.js          # 登录/注册页逻辑
│   ├── md.js            # 自研 Markdown 渲染器（媒体白名单）
│   ├── admin.js         # 管理页逻辑
│   └── tts.js           # 朗读工坊逻辑
└── css/pixel.css        # 像素风样式
```

## 🤖 给用户用 AI（管理员视角）

1. **平台供给**：管理页生成密钥发给用户（或直接绑定到账号），用户在「AI 设置 → 平台密钥」填入即可，用量实时可见
2. **用户自带 Key**：用户在「AI 设置 → 我自己的 Key」填自己的接口地址 + Key + 模型名；建议引导用户部署 `ai-proxy-worker.js` 到自己的 Cloudflare Workers 并填入代理地址，实现浏览器直连、不经过你的服务器、不限量

### 自部署透明代理（用户侧，可选）

1. 打开 Cloudflare Dashboard → Workers & Pages → Create Worker
2. 把仓库里 `ai-proxy-worker.js` 的内容全部粘贴进去，Deploy
3. 把 Worker 地址（形如 `https://xxx.your-name.workers.dev`）填到「AI 设置 → 透明代理」

## 📄 许可证

[MIT](LICENSE) — 可自由使用、修改、二次开发、商用，保留版权声明即可。

如果这个项目对你有帮助，欢迎 Star ⭐
