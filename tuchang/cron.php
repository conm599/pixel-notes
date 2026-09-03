<?php
// ================= 陶瓦图床 · 过期清理（面板监控任务触发） =================
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'err' => 'forbidden'));
    exit;
}

$n = cleanup_expired();
echo json_encode(array('ok' => true, 'cleaned' => $n, 'ts' => time()));
