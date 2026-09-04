# Pixel Suite · 像素套件（便签 + 图床）

> 📚 **文档导航**
> - 🚀 [傻瓜式部署教程（从零到上线，30 分钟）](docs/部署教程-傻瓜式.md)
> - 📝 [便签使用教程（从入门到大师，四级难度）](docs/便签使用教程-从入门到精通.md)

一个仓库管理同一套服务的两个站点，部署于同一台 VPS，共享登录（父域 Cookie）。

## 结构

```
pixel-suite/
├── README.md               本文档
├── bianqian/   便签 Pixel Notes  → VPS /var/www/hosting（bianqian.naxid.top）
│   ├── index.php / login.php / register.php / logout.php
│   ├── share.php（公开分享只读页）/ backup.php（备份）
│   ├── admin.php（AI/邮件后台）/ admini/（/admini 配置中心）
│   ├── api/（ai.php、auth.php、folders.php、notes.php、tts.php…）
│   ├── css/ js/（app.js v78、md.js v41、selection.js v5、ai-direct.js v15）
│   └── protocol.md（AI 编辑协议 v8）
├── tuchang/    图床（tuchang.naxid.top / mcft.naxid.top）→ VPS /var/www/tuchang
│   ├── dashboard.php（SPA 控制台）/ view.php / login.php / register.php
│   ├── i.php（图片出口）/ s.php（token 分享）/ api.php（全部操作）
│   ├── adminws.php（密令管理台）/ api-doc.php
│   ├── js/（dashboard.js v21、spa.js v7、selection.js v9）
│   ├── css/（pixel-blue.css v13）
│   └── nginx/ 配置归档
├── admini/  # 合并仓办公用配置中心副本（部署时只取 bianqian/admini/）
├── docs/    部署教程-傻瓜式.md / 便签使用教程-从入门到精通.md / tuchang-gitlog.txt
└── tools/   build-tutorial.js（教程 md → JS 数据文件构建）
```

## 动态域名（第二域名绑定）

两站 cookie 父域与站间互跳**全部动态推导**，绑定第二个域名无需改代码：

- `bianqian.<任意域名>` / `tuchang.<任意域名>` 自动共享 `<任意域名>` 父域 Cookie（一次登录双站通用）
- 便签「图床」入口、图床「登录/注册」跳转、图床 API 上传优选域，全部按当前 host 的 `bianqian.` ↔ `tuchang.` 前缀互推
- 非约定前缀的 host 访问时回退默认 `*.naxid.top`
- IP 直访 / localhost / 注册域直访 → host-only Cookie（不影响主站）

绑定时只需：DNS 解析两个子域到 VPS + nginx `server_name` 追加 + 对应证书（CF 泛域名证书或 Let's Encrypt）。

## 红线（改代码前必读）

- 图床分享链接机制（s.php token / view.php / i.php / share_urls / PREFERRED_HOST）**一行不动**
- adminws.php 密令门禁逻辑**一行不动**
- 便签协议 protocol.md 是唯一事实源；改 css/js 必须 bump 引用 `?v=`（VPS HK 缓存 8H），HTML 必须 no-store

## 现行版本号

- 便签：md.js v41 / pixel.css v59 / app.js v78 / selection.js v5 / ai-direct.js v15 / tutorial-data v1
- 图床：pixel-blue.css v13 / dashboard.js v21 / view.js v3 / spa.js v7 / api.php（含 folder 系列与 GET 白名单）
- 配置：/admini/ 面板（suite-config.php 22 项；env PSU_* > 文件 > 代码默认）

## 部署

- 便签 → VPS /var/www/hosting/（paramiko SFTP；**config/database.php 含生产 DB 密码，禁止整文件覆盖，只能 VPS 原位改**）
- 图床 → VPS /var/www/tuchang/（整目录可覆盖，config.php 与本仓同步）
- 部署后 `chown www-data:www-data` 相应文件
