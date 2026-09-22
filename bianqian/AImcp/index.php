<?php

require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/StoreFactory.php';
require_once __DIR__ . '/src/MySqlStore.php';
require_once __DIR__ . '/src/JsonFileStore.php';
require_once __DIR__ . '/src/Search.php';
require_once __DIR__ . '/src/Embeddings.php';
require_once __DIR__ . '/src/App.php';

header('Content-Type: text/html; charset=utf-8');

$status = '';

try {
    $app = new App();
    $store = $app->store();

    $driverName = '';
    $driverStatus = '未知';
    $mysqlMeta = '';
    if ($store instanceof MySqlStore) {
        $driverName = 'MySQL (' . ($app->config()['storage']['mysql']['host'] ?? '') . ')';
        $driverStatus = $store->ping() ? '已连接' : '连接失败';
    } elseif ($store instanceof JsonFileStore) {
        $driverName = 'JSON 文件（本地开发/测试兜底）';
        $driverStatus = $store->ping() ? '可写' : '不可写';
    }

    $embName = $app->embeddings()->isConfigured() ? '已配置' : '未配置';
    $embModel = $app->embeddings()->isConfigured()
        ? ($app->config()['embeddings']['model'] ?? '')
        : '';

    $count = $store->count();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $base = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $mcpUrl = $base . '/mcp.php';
    $adminUrl = $base . '/admin.php';
} catch (\Throwable $e) {
    $status = '<p class="err">加载失败：' . htmlspecialchars($e->getMessage()) . '</p>';
    $count = 0;
    $mcpUrl = ''; $adminUrl = ''; $embName = '未知'; $embModel = '';
    $driverName = '未知'; $driverStatus = '未知';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI 记忆库 MCP 服务 · 状态</title>
<style>
body{font-family:-apple-system,"Segoe UI",system-ui,sans-serif;background:#f4f5f7;color:#1d2430;margin:0;padding:24px}
.wrap{max-width:720px;margin:0 auto}
.card{background:#fff;border:1px solid #e2e6ea;border-radius:10px;padding:20px 24px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
h1{font-size:18px;margin:0 0 4px} h2{font-size:14px;color:#5b6572;margin:0 0 16px;font-weight:500}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{text-align:left;padding:8px 10px;border-bottom:1px solid #eef1f4}
th{color:#5b6572;font-weight:600;width:140px}
.k{font-family:ui-monospace,Consolas,monospace;background:#f6f8fa;border:1px solid #e2e6ea;border-radius:5px;padding:10px 12px;font-size:12px;word-break:break-all;display:block}
.ok{color:#1a7f37}.bad{color:#cf222e}.muted{color:#5b6572}
code{background:#f0f2f4;padding:1px 5px;border-radius:4px;font-size:12px}
a{color:#0969da;text-decoration:none}
.err{color:#cf222e;background:#fff5f5;border:1px solid #ffd7d7;padding:10px 12px;border-radius:6px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card"><h1>AI 记忆库 · MCP 服务</h1><h2>状态与接入说明（本页不泄露任何密钥）</h2></div>
  <?php echo $status; ?>
  <div class="card">
    <table>
      <tr><th>PHP 版本</th><td><?php echo htmlspecialchars(PHP_VERSION); ?></td></tr>
      <tr><th>存储驱动</th><td><?php echo htmlspecialchars($driverName); ?> <span class="<?php echo ($driverStatus==='已连接'||$driverStatus==='可写')?'ok':'bad'; ?>">· <?php echo htmlspecialchars($driverStatus); ?></span></td></tr>
      <tr><th>记忆条目数</th><td><?php echo (int)$count; ?></td></tr>
      <tr><th>嵌入服务</th><td><span class="<?php echo $embName==='已配置'?'ok':'muted'; ?>"><?php echo htmlspecialchars($embName); ?></span> <?php echo $embModel!==''?'<code>'.htmlspecialchars($embModel).'</code>':''; ?></td></tr>
      <tr><th>管理页面</th><td><a href="<?php echo htmlspecialchars($adminUrl); ?>"><?php echo htmlspecialchars($adminUrl); ?></a></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>MCP 端点</h2>
    <span class="k"><?php echo htmlspecialchars($mcpUrl); ?></span>
  </div>
  <div class="card">
    <h2>AI 客户端接入示例（以兼容 MCP 的客户端为例）</h2>
    <table>
      <tr><th>Base URL</th><td><?php echo htmlspecialchars($mcpUrl); ?></td></tr>
      <tr><th>鉴权头</th><td><code>Authorization: Bearer &lt;api_token&gt;</code></td></tr>
      <tr><th>可用工具</th><td><code>memory_add</code> · <code>memory_search</code> · <code>memory_list</code> · <code>memory_delete</code></td></tr>
    </table>
  </div>
</div>
</body>
</html>