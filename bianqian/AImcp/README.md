# AI 记忆库 MCP 服务

部署在 PHP 虚拟主机的「AI 记忆数据库」，任何设备上的 AI 客户端都能通过标准 **MCP 协议**远程读写同一份知识库，实现跨设备共享记忆。纯 PHP 实现、零 Composer 依赖。

```
AI 客户端 ──MCP──▶ mcp.php ──▶ MySQL(记忆+向量)
                        │
                        └──▶ Prompt 外部 API（嵌入/推理精排）
```

## 功能

- **MCP Streamable HTTP 端点** `mcp.php`（JSON-RPC 2.0：initialize / tools/list / tools/call / ping）
- **5 个工具**：
  - `memory_instructions` 读取管理员设定的使用规则（后台可随时改）
  - `memory_add` / `memory_search` / `memory_list` / `memory_delete` 记忆增删查
- **双模检索**（用户可选，`memory_search` 参数 `mode`）：
  - `embedding`：向量语义匹配（跨字面换种说法也能命中）
  - `text`：关键词字面模糊匹配
  - `auto`：默认，嵌入可用则用嵌入否则回退文字
- **搜索按标签精确命中加权 + 向量精排**；相似度阈值 `min_score` 默认 0.5，可调用时传参调整严格度
- **LLM 二次精排增强**（可选）：`memory_search` 传 `rerank=true` 时，先把候选交给推理模型逐条判断，剔掉"沾边但语义不符"的噪声
- **存储双驱动**：MySQL 主驱动（utf8mb4）+ JSON 文件兜底（本地开发/测试）
- **管理后台** `admin.php`：看/搜/加/删记忆 + 编辑给模型的指令 + **一键导出/导入全部数据（含向量）**
- **状态页** `index.php`：部署自检 + 接入地址
- **鉴权**：MCP 用 Bearer / `?ApiKey=` URL 参数 / X-API-Key；管理页独立密码 + Session + CSRF

## 部署

需要一台支持 PHP≥7.4（推荐 8.x）+ MySQL 的虚拟主机，允许 cURL/fsockopen 出站（调嵌入/精排 API 必需）。

1. 上传本项目到网站某个目录（如 `/AImcp/`）
2. `cp config.example.php config.php`，填好：API token、管理密码、MySQL 连接、嵌入服务
3. 首次写入会自动建表（表名可通过 config 前缀自定义），也可手动导入 `install.sql`
4. 访问 `https://<你的域名>/<目录>/index.php` 检查状态是否正常

## MCP 客户端接入

| 配置项 | 值 |
|---|---|
| MCP Server | `https://<域名>/<目录>/mcp.php` |
| 鉴权 | `Authorization: Bearer <api_token>` 或 `mcp.php?ApiKey=<token>` |

可用工具的完整描述由 `tools/list` 返回，客户端会自动发现。

## 配置说明（config.example.php）

```php
'api_token'      // MCP 端点鉴权 token
'admin_password' // 管理后台独立密码
'storage.driver' // auto|mysql|jsonfile（auto：有 MySQL 配置用 mysql，否则 jsonfile）
'embeddings'     // base_url / api_key / model 三项全填才启用向量检索；
                 // 不填则 auto 模式自动回退文字匹配
'rerank'         // LLM 精排增强（可选，`memory_search` 传 rerank=true 才触发）
'search'         // min_score 默认 0.5；tag_weight 标签加权；top_k 默认 5
```

### 嵌入模型实测（2026-08，同一批中文语义对比）

| 模型 | 维度 | 结论 |
|---|---|---|
| **BAAI/bge-m3**（edgefn） | 1024 | **当前默认 ✅ 推荐**：相关 0.85 / 无关 0.31，多语言中文强、上下文 8K、对称模型无需 input_type；按输入 token 计费（约 ¥0.08/百万，嵌入输出免费） |
| nvidia/llama-nemotron-embed-1b-v2 | 2048 | 已 EOL 勿用（曾实测相关 0.70 / 无关 0.06~0.08，需 input_type 同空间） |
| nvidia/nemotron-3-embed-1b | 2048 | 中文理解强但分数拥挤（无关也≈0.63），不适合固定阈值 |
| nvidia/nv-embedqa-e5-v5 | 1024 | 中文弱，相关/无关几乎无区分 |

> 换模型会导致旧向量维度/空间不同，需重新入库（见"导出/导入"）。

## 目录结构

```
├── mcp.php            # MCP 端点
├── admin.php          # 管理后台（含导出/导入）
├── index.php          # 状态页
├── install.sql        # MySQL 建表脚本
├── config.example.php # 配置模板（config.php 已 gitignore，不提交）
├── data/              # JSON 兜底存储 / 指令文件（.htaccess 禁止 Web 直读）
├── src/
│   ├── App.php        # 依赖装配 + 配置访问
│   ├── Store.php      # 存储接口
│   ├── MySqlStore.php # MySQL 驱动
│   ├── JsonFileStore.php # JSON 驱动
│   ├── Search.php     # 分词 / 余弦 / 评分
│   ├── Embeddings.php # 嵌入客户端
│   ├── Rerank.php     # LLM 精排客户端
│   └── Instructions.php # 模型指令存取
└── tests/self_test.php # 在线端到端自测
```

## 开发 / 测试

本机无 PHP 时改用**在线自测**（部署后 HTTP 访问）：

```
https://<域名>/<目录>/tests/self_test.php
```

断言鉴权、增删查、text/embedding 匹配、未知方法、错误 token 拒绝等。

## 安全说明

- MCP 端点必须带 token（Bearer / URL `?ApiKey=` / X-API-Key 三选一）
- 管理页独立密码 + Session + CSRF 防护
- `config.php` 含密钥，已由 `.gitignore` 排除，**勿提交到版本库**
- `data/` 目录 `.htaccess` 禁止 Web 直接访问