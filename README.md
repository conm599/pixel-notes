# Pixel Suite · 像素套件（便签 + 图床）

> 📚 **文档导航**
> - 🚀 [傻瓜式部署教程（从零到上线，30 分钟）](docs/部署教程-傻瓜式.md)
> - 📝 [便签使用教程（从入门到大师，四级难度）](docs/便签使用教程-从入门到精通.md)

一个仓库管理同一套服务的两个站点，部署于同一台 VPS（），共享登录（父域 Cookie）。

## 结构

```
pixel-suite/
├── bianqian/   便签 Pixel Notes  → VPS /var/www/hosting   （bianqian.naxid.top）
├── tuchang/    陶瓦图床          → VPS /var/www/tuchang    （tuchang.naxid.top / mcft.naxid.top 走 CF）
└── docs/       图床旧仓 git 历史存档（合并前独立仓库的 log）
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

- 便签：md.js v40 / pixel.css v57 / app.js v78
- 图床：pixel-blue.css v6 / dashboard.js v3 / view.js v2

## 部署

- 便签 → VPS /var/www/hosting/（paramiko SFTP；**config/database.php 含生产 DB 密码，禁止整文件覆盖，只能 VPS 原位改**）
- 图床 → VPS /var/www/tuchang/（整目录可覆盖，config.php 与本仓同步）
- 部署后 `chown www-data:www-data` 相应文件
