<?php
/**
 * 部署上传工具（仅站长使用，走 HTTPS 直传，替代不稳定的 FTP）
 *
 * 用法（curl 直传，无需代理）：
 *   上传：curl -s -X POST "https://站点/_deploy.php?token=密令&path=js/app.js" --data-binary "@本地文件"
 *   校验：curl -s "https://站点/_deploy.php?token=密令&path=js/app.js&op=hash"
 * 上传响应含服务端写盘后回读的 sha256，与本地比对即可完成校验闭环。
 *
 * 安全设计：
 * - 随机 48 位 hex 密令，hash_equals 防时序攻击；无密令的请求一律 404
 * - 路径白名单：仅根目录文件或 api/ js/ css/ config/ 一级子目录，字符集限 [A-Za-z0-9._-]
 * - 禁止覆盖 _deploy.php 自身（防止半截上传把自己写坏，更新它请走 FTP）
 * - 上限 3MB；写盘 LOCK_EX
 *
 * 本文件含密令，绝不能推送到 GitHub（同 config 规则），只在服务器与本地工程目录存在
 */

define('DEPLOY_TOKEN', '010071db203ac695923919d2684184341d760747a7942954');
define('DEPLOY_MAX_BYTES', 3 * 1024 * 1024);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '' || !hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(404);
    echo json_encode(array('success' => false, 'message' => 'Not Found'));
    exit;
}

$path = isset($_GET['path']) ? trim((string)$_GET['path']) : '';
if (!preg_match('#^(?:(?:api|js|css|config)/)?[A-Za-z0-9._\-]+$#', $path) || $path === '_deploy.php') {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => '非法路径（仅根目录或 api/js/css/config 一级子目录，禁覆盖 _deploy.php）'));
    exit;
}

$root = dirname(__FILE__);
$target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
$dir = dirname($target);
$realDir = realpath($dir);
$realRoot = realpath($root);
if ($realDir === false || $realRoot === false || strpos($realDir, $realRoot) !== 0) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => '路径解析失败'));
    exit;
}

$op = isset($_GET['op']) ? (string)$_GET['op'] : 'upload';

if ($op === 'hash') {
    if (!is_file($target)) {
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => '文件不存在'));
        exit;
    }
    echo json_encode(array('success' => true, 'sha256' => hash_file('sha256', $target), 'size' => filesize($target)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => '仅支持 POST 上传'));
    exit;
}

$body = file_get_contents('php://input');
if ($body === false || $body === '') {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => '请求体为空'));
    exit;
}
if (strlen($body) > DEPLOY_MAX_BYTES) {
    http_response_code(413);
    echo json_encode(array('success' => false, 'message' => '文件超过 3MB 上限'));
    exit;
}

$written = @file_put_contents($target, $body, LOCK_EX);
if ($written === false || $written !== strlen($body)) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => '写盘失败（写入 ' . (int)$written . '/' . strlen($body) . ' 字节）'));
    exit;
}
// 写盘后回读校验（防半截写）：返回服务端哈希，客户端与本地比对完成闭环
echo json_encode(array(
    'success' => true,
    'path'    => $path,
    'size'    => $written,
    'sha256'  => hash_file('sha256', $target),
));
