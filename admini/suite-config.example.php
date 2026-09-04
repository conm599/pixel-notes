<?php
// Pixel Suite 共享配置 · 模板（复制到 webroot 上一级 /var/www/suite-config.php 并填入真实值）
// 敏感文件：切勿提交仓库、切勿放 webroot 可下载位置；由 /admini 面板生成/管理
// 优先级：环境变量 PSU_* > 本文件 > 代码默认值
return array(
    'admin_user' => 'admin',            // /admini 面板管理员
    'admin_hash' => '',                 // password_hash(...)，安装向导自动生成
    // 站点域名（公共部署必填：任意子域名前缀，如 notes.example.com / img.example.com）
    // 留空 = 动态推导（bianqian./tuchang. 前缀互换，回退 *.naxid.top）
    'bianqian_host' => '',
    'tuchang_host' => '',
    // 便签数据库
    'bianqian_db_host' => 'localhost',
    'bianqian_db_port' => '3306',
    'bianqian_db_name' => '',
    'bianqian_db_user' => '',
    'bianqian_db_pass' => '',
    // 图床数据库
    'tuchang_db_host' => 'localhost',
    'tuchang_db_name' => '',
    'tuchang_db_user' => '',
    'tuchang_db_pass' => '',
    // 图床常量
    'tuchang_invite_code' => '',
    'tuchang_admin_pass' => '',         // adminws 管理密令
    'tuchang_user_quota' => 20971520,   // 20MB
    'tuchang_max_upload' => 10485760,   // 10MB
    'tuchang_max_compressed' => 4194304,// 4MB
    'tuchang_max_dim' => 8192,
    'tuchang_cron_key' => '',
    'tuchang_preferred_host' => 'tuchang.naxid.top',
    // TTS 语音
    'tts_url' => 'https://edgetts.naxid.top/v1/audio/speech',
    'tts_token' => '',
    'tts_max_text' => 5000,
);
