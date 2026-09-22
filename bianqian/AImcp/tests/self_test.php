<?php

// 部署自测：在虚拟主机上通过 HTTP 对 mcp.php 做端到端验证。
// 用法：浏览器打开 https://<host>/AIMCP/tests/self_test.php
//   或偶尔也可以命令行 php tests/self_test.php（若主机提供 shell）。
// 会真实读取 config.php 的 token，向自身 mcp.php 发请求，逐项断言。

require_once __DIR__ . '/../src/App.php';

header('Content-Type: text/plain; charset=utf-8');

$app = new App();
$token = $app->apiToken();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost:80';
$base = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$mcpUrl = $base . '/mcp.php';

function amcp_rpc(string $url, string $token, string $method, array $params = null)
{
    $body = array('jsonrpc' => '2.0', 'id' => 1, 'method' => $method);
    if ($params !== null) {
        $body['params'] = $params;
    }
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    );
    $payload = json_encode($body);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($code, $raw);
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST',
        'header' => implode("\r\n", array_merge(array('Content-Length: ' . strlen($payload)), $headers)) . "\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 30,
    )));
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $mm)) {
                $code = (int) $mm[1];
                break;
            }
        }
    }
    return array($code, is_string($raw) ? $raw : '');
}

$pass = 0;
$fail = 0;

function amcp_check(string $label, array $r, ?callable $condition = null)
{
    global $pass, $fail;
    list($code, $raw) = $r;
    $decoded = json_decode($raw, true);
    $ok = true;
    if ($condition !== null) {
        $ok = $condition($code, $decoded);
    }
    if ($ok) {
        $pass++;
        echo "[PASS] " . $label . "\n";
    } else {
        $fail++;
        echo "[FAIL] " . $label . " (http=$code) body=" . substr((string)$raw, 0, 300) . "\n";
    }
}

// 1. 错误 token 应 401
amcp_check('错误 token 被拒绝(401)', amcp_rpc($mcpUrl, 'wrong-token', 'initialize'), function ($code) {
    return $code === 401;
});

// 2. 正确 token initialize
amcp_check('initialize 成功', amcp_rpc($mcpUrl, $token, 'initialize'), function ($code, $d) {
    return $code === 200 && isset($d['result']['serverInfo']['name']);
});

// 3. tools/list
amcp_check('tools/list 返回4个工具', amcp_rpc($mcpUrl, $token, 'tools/list'), function ($code, $d) {
    return isset($d['result']['tools']) && count($d['result']['tools']) === 4;
});

// 4. 未知方法 -32601
amcp_check('未知方法返回 -32601', amcp_rpc($mcpUrl, $token, 'foo/bar'), function ($code, $d) {
    return isset($d['error']['code']) && $d['error']['code'] === -32601;
});

// 5. add（自动用嵌入）—— 这里用客户端自带 4 维向量，避免依赖外部 API 造脏数据
$id1 = '';
amcp_check('memory_add 客户端自带向量', amcp_rpc($mcpUrl, $token, 'tools/call', array(
    'name' => 'memory_add',
    'arguments' => array(
        'content' => '服务器备份策略每周末执行一次保留最近两份',
        'tags' => array('运维', '备份'),
        'vector' => array(0.1, 0.2, 0.3, 0.4),
        'model' => 'test/local-4',
    ),
)), function ($code, $d) use (&$id1) {
    if (!isset($d['result']['content'][0]['text'])) {
        return false;
    }
    $inner = json_decode($d['result']['content'][0]['text'], true);
    if (!isset($inner['id']) || empty($inner['has_vector'])) {
        return false;
    }
    $id1 = $inner['id'];
    return true;
});

$id2 = '';
amcp_check('memory_add 纯文本（无向量）', amcp_rpc($mcpUrl, $token, 'tools/call', array(
    'name' => 'memory_add',
    'arguments' => array(
        'content' => '用户勿忘去菜市场买鸡蛋',
        'tags' => array('生活'),
    ),
)), function ($code, $d) use (&$id2) {
    if (!isset($d['result']['content'][0]['text'])) {
        return false;
    }
    $inner = json_decode($d['result']['content'][0]['text'], true);
    if (!isset($inner['id'])) {
        return false;
    }
    $id2 = $inner['id'];
    return true;
});

// 6. delete 全文匹配测试用的再写一条
amcp_check('删除不存在的 id 报错', amcp_rpc($mcpUrl, $token, 'tools/call', array(
    'name' => 'memory_delete',
    'arguments' => array('id' => 'no-such-id-xyz'),
)), function ($code, $d) {
    return isset($d['result']['isError']) && $d['result']['isError'] === true;
});

// 6b. memory_list
amcp_check('memory_list', amcp_rpc($mcpUrl, $token, 'tools/call', array(
    'name' => 'memory_list',
    'arguments' => array('limit' => 5),
)), function ($code, $d) {
    $inner = json_decode($d['result']['content'][0]['text'] ?? '', true);
    return isset($inner['ok']) && $inner['ok'] === true && isset($inner['items']);
});

// 6c. text 搜索「备份」
amcp_check('text 模式命中「备份」', amcp_rpc($mcpUrl, $token, 'tools/call', array(
    'name' => 'memory_search',
    'arguments' => array('query' => '备份', 'mode' => 'text', 'top_k' => 5),
)), function ($code, $d) {
    $inner = json_decode($d['result']['content'][0]['text'] ?? '', true);
    if (!isset($inner['results']) || !$inner['results']) {
        return false;
    }
    return $inner['meta']['mode'] === 'text' && stripos($inner['results'][0]['content'], '备份') !== false;
});

// 6d. auto 未配置嵌入时应回退 text —— 直接在 config 已配置嵌入时跳过断言
$emb = $app->embeddings()->isConfigured();
if (!$emb) {
    amcp_check('auto 未配置嵌入回退 text', amcp_rpc($mcpUrl, $token, 'tools/call', array(
        'name' => 'memory_search',
        'arguments' => array('query' => '买鸡蛋', 'mode' => 'auto'),
    )), function ($code, $d) {
        $inner = json_decode($d['result']['content'][0]['text'] ?? '', true);
        return isset($inner) && $inner['meta']['mode'] === 'text';
    });
} else {
    echo "[SKIP] auto 回退测试（当前已配置嵌入服务，auto 走 embedding）\n";
}

// 7. embedding 模式（需配置嵌入，且库里有等维向量）
$skipped7 = true;
if ($app->embeddings()->isConfigured()) {
    amcp_check('embedding 模式检索', amcp_rpc($mcpUrl, $token, 'tools/call', array(
        'name' => 'memory_search',
        'arguments' => array('query' => '备份策略', 'mode' => 'embedding', 'top_k' => 5),
    )), function ($code, $d) {
        $inner = json_decode($d['result']['content'][0]['text'] ?? '', true);
        return isset($inner['meta']) && in_array($inner['meta']['mode'], array('embedding'), true);
    });
    $skipped7 = false;
} else {
    echo "[SKIP] embedding 模式测试（未配置嵌入服务）\n";
}

// 清理：删除创建的条目
if ($id1 !== '') {
    amcp_check('删除条目1', amcp_rpc($mcpUrl, $token, 'tools/call', array(
        'name' => 'memory_delete', 'arguments' => array('id' => $id1),
    )), function ($code, $d) {
        $inner = json_decode($d['result']['content'][0]['text'] ?? '', true);
        return isset($inner['ok']) && $inner['ok'] === true;
    });
}
if ($id2 !== '') {
    amcp_check('删除条目2', amcp_rpc($mcpUrl, $token, 'tools/call', array(
        'name' => 'memory_delete', 'arguments' => array('id' => $id2),
    )), function ($code, $d) {
        $inner = json_decode($d['result']['content'][0]['text'] ?? '', true);
        return isset($inner['ok']) && $inner['ok'] === true;
    });
}

echo "\n====================\n";
echo "通过: {$pass}  失败: {$fail}\n";
echo $fail === 0 ? "[ALL OK]\n" : "[HAS FAILURES]\n";