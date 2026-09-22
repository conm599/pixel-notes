<?php
/**
 * AI 记忆库 MCP 服务 —— 配置模板
 * 使用方法：复制本文件为 config.php 并填写真实值。
 * config.php 已被 .gitignore 排除，不会被提交到版本库。
 */

return [
    // MCP 端点鉴权 Token（AI 客户端请求头：Authorization: Bearer <token> 或 X-API-Key: <token>）
    // 请改成一段足够长的随机字符串
    'api_token' => 'change-me-to-a-long-random-token',

    // 浏览器管理页 admin.php 的独立登录密码（与 api_token 分离）
    'admin_password' => 'change-me-admin-password',

    // 存储设置：driver 可选 auto | mysql | jsonfile
    // auto = 配置了 MySQL 就用 MySQL，否则回退 jsonfile
    'storage' => [
        'driver' => 'auto',
        'mysql' => [
            'host'   => 'localhost',
            'port'   => 3306,
            'dbname' => '',            // 数据库名（在虚拟主机控制面板确认/创建）
            'user'   => '',            // 数据库账号
            'pass'   => '',            // 数据库密码
            'table'  => 'ai_memories', // 表名（前缀可自定义）
        ],
        'jsonfile' => [
            'path' => __DIR__ . '/data/memory.json', // JSON 兜底存储文件位置
        ],
    ],

    // 嵌入服务：base_url / api_key / model 三项全部非空时启用 embedding 检索；
    // 否则 auto 模式自动回退文字匹配。
    // 实测结论（2026-08，同一批中文语义对比）：
    //   nvidia/llama-nemotron-embed-1b-v2  2048 维，推荐默认 ✅
    //     相关=0.70 / 无关=0.06~0.08，分数分布清晰，配合 min_score=0.5 能干净过滤无关项
    //   需 input_type（query/query 或 passage/passage 同空间）
    //   nvidia/nemotron-3-embed-1b        2048 维，中文理解强但分数拥挤（无关也≈0.63），不适合固定阈值
    //   nvidia/nv-embedqa-e5-v5            1024 维，中文弱（相关/无关几乎无区分）
    // 换模型后会导致旧向量维度不匹配或空间不同，需重新入库。
    'embeddings' => [
        'base_url' => 'https://integrate.api.nvidia.com/v1',
        'api_key'  => '',                          // 填入 nvapi- 开头的 Key；留空则禁用 embedding
        'model'    => 'nvidia/llama-nemotron-embed-1b-v2',

        // llama-nemotron-embed-1b-v2 是对称模型，文档/查询的 input_type 建议保持同值以保证同空间。
        'input_type_passage' => 'query',
        'input_type_query'   => 'query',

        'extra_body' => [],       // 需要追加的其它请求字段，例如 ['truncate' => 'END']
        'timeout'    => 20,       // 出站请求超时（秒）
    ],

    // 检索策略：
    //   min_score  = 综合分数下限，低于此分的结果被过滤（默认 0.5）
    //   tag_weight = 查询命中该条记忆的标签时追加的加权分（精确命中优先）
    //   top_k      = 默认返回条数
    'search' => [
        'min_score'  => 0.5,
        'tag_weight' => 2.0,
        'top_k'      => 5,
    ],

    // LLM 精排增强（可选，默认关闭）。
    // 只有当 AI 调用 memory_search 传 rerank=true 才启用：嵌入先召回一批 → 这个推理模型逐条判断真正相关的。
    // base_url/model/api_key 留空则视为未配置，即使传 rerank=true 也只做普通检索。
    // 该推理模型上下文很大（约 96K 可输入），以下两个值可放心调大；但候选越多、每条越长，单次精排越慢。
    'rerank' => [
        'base_url' => 'https://api.edgefn.net/v1',
        'api_key'  => '',                        // 填入 edgefn 的 sk- 开头 token；留空则禁用
        'model'    => 'DeepSeek-R1-0528-Qwen3-8B',
        'max_candidates' => 20,                  // 精排最多取前 N 个候选（可随上下文增大）
        'max_rerank_content' => 600,             // 每条候选正文最多送多少字给模型
        'timeout'  => 45,                        // 精排调用超时（秒），候选多需更大
    ],
];
