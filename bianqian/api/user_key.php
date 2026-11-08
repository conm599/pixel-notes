<?php
/**
 * 用户桌面状态屏 API Key 管理接口 (前台用户会话保护)
 * 职责：申请、查看、重置、删除 Key 以及设定 AI 性格
 */

require_once __DIR__ . '/../config/database.php';

sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

startSecureSession();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(array('success' => false, 'message' => '未登录'), JSON_UNESCAPED_UNICODE);
    exit;
}

ensureTables();

$userId = (int)$_SESSION['user_id'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['action'])) $action = trim($input['action']);
}

switch ($action) {
    case 'get':
        $keyRow = getUserDeskApiKey($userId);
        echo json_encode(array(
            'success' => true,
            'has_key' => $keyRow !== null,
            'api_key' => $keyRow ? $keyRow['api_key'] : '',
            'persona' => ($keyRow && !empty($keyRow['persona'])) ? $keyRow['persona'] : '嘴硬心软、爱吐槽的桌面管家',
            'created_at' => $keyRow ? $keyRow['created_at'] : '',
            'last_used_at' => $keyRow ? $keyRow['last_used_at'] : '',
            'api_base' => siblingUrl('bianqian', '/api/open.php')
        ), JSON_UNESCAPED_UNICODE);
        break;

    case 'reset':
        $newKey = generateUserDeskApiKey($userId);
        if ($newKey) {
            echo json_encode(array(
                'success' => true,
                'message' => '密钥已成功生成/重置！',
                'api_key' => $newKey
            ), JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(array('success' => false, 'message' => '生成密钥失败'), JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'delete':
        $ok = deleteUserDeskApiKey($userId);
        echo json_encode(array(
            'success' => $ok,
            'message' => $ok ? '密钥已成功删除，硬件端将立即失效' : '删除失败'
        ), JSON_UNESCAPED_UNICODE);
        break;

    case 'save_persona':
        $input = json_decode(file_get_contents('php://input'), true);
        $persona = isset($input['persona']) ? trim((string)$input['persona']) : '';
        $ok = saveUserDeskPersona($userId, $persona);
        echo json_encode(array(
            'success' => $ok,
            'message' => $ok ? '管家性格已保存！' : '保存失败'
        ), JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => '未知操作'), JSON_UNESCAPED_UNICODE);
        break;
}
