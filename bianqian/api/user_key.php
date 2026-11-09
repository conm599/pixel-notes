<?php
/**
 * 用户桌面状态屏 API Key 与记忆管理接口 (前台用户会话保护)
 * 职责：申请、查看、重置、删除 Key，设定 AI 性格，以及 3 级安全验证清空 AI 记忆
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
$input = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $input = json_decode($raw, true);
        if (is_array($input) && isset($input['action']) && $action === '') {
            $action = trim($input['action']);
        }
    }
}

/** 邮箱脱敏展示 */
function maskEmailAddress($email) {
    $email = trim((string)$email);
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $len = mb_strlen($name, 'UTF-8');
    if ($len <= 2) {
        $masked = mb_substr($name, 0, 1, 'UTF-8') . '*';
    } else {
        $masked = mb_substr($name, 0, 2, 'UTF-8') . str_repeat('*', min(4, $len - 2)) . mb_substr($name, -1, 1, 'UTF-8');
    }
    return $masked . '@' . $domain;
}

/** 校验 3 级邮箱验证码（防暴力破解，最多尝试 5 次） */
function verifyDeskMemoryEmailCode($pdo, $email, $code) {
    if (!preg_match('/^\d{6}$/', (string)$code)) return false;
    $st = $pdo->prepare("SELECT * FROM pn_email_codes
                         WHERE email = ? AND purpose = 'clear_desk_memory' AND used = 0 AND attempts < 5
                           AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $st->execute(array($email));
    $row = $st->fetch();
    if (!$row) return false;
    if ((string)$row['code'] !== (string)$code) {
        $pdo->prepare("UPDATE pn_email_codes SET attempts = attempts + 1 WHERE id = ?")
            ->execute(array((int)$row['id']));
        return false;
    }
    $pdo->prepare("UPDATE pn_email_codes SET used = 1 WHERE id = ?")
        ->execute(array((int)$row['id']));
    return true;
}

switch ($action) {
    case 'get':
        $keyRow = getUserDeskApiKey($userId);
        $pdo = getDB();
        $memCount = 0;
        $maskedEmail = '';
        if ($pdo) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_desk_memories` WHERE `user_id` = ?");
                $st->execute(array($userId));
                $memCount = (int)$st->fetchColumn();

                $st2 = $pdo->prepare("SELECT email FROM `pn_users` WHERE `id` = ?");
                $st2->execute(array($userId));
                $uRow = $st2->fetch();
                if ($uRow && !empty($uRow['email'])) {
                    $maskedEmail = maskEmailAddress($uRow['email']);
                }
            } catch (Throwable $e) {}
        }

        echo json_encode(array(
            'success'      => true,
            'has_key'      => $keyRow !== null,
            'api_key'      => $keyRow ? $keyRow['api_key'] : '',
            'persona'      => ($keyRow && !empty($keyRow['persona'])) ? $keyRow['persona'] : '嘴硬心软、爱吐槽的桌面管家',
            'created_at'   => $keyRow ? $keyRow['created_at'] : '',
            'last_used_at' => $keyRow ? $keyRow['last_used_at'] : '',
            'memory_count' => $memCount,
            'user_email'   => $maskedEmail,
            'api_base'     => siblingUrl('bianqian', '/api/open.php')
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
        $persona = isset($input['persona']) ? trim((string)$input['persona']) : '';
        $ok = saveUserDeskPersona($userId, $persona);
        echo json_encode(array(
            'success' => $ok,
            'message' => $ok ? '管家性格已保存！' : '保存失败'
        ), JSON_UNESCAPED_UNICODE);
        break;

    // 3 级验证：发送清空记忆邮箱验证码
    case 'send_memory_code':
        $pdo = getDB();
        if (!$pdo) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'message' => '数据库连接异常'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $st = $pdo->prepare("SELECT email FROM pn_users WHERE id = ?");
        $st->execute(array($userId));
        $uRow = $st->fetch();
        if (!$uRow || empty($uRow['email'])) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '当前账号未绑定邮箱，无法进行 3 级验证'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $email = strtolower(trim((string)$uRow['email']));

        // 同邮箱同用途 60 秒冷却
        $st = $pdo->prepare("SELECT COUNT(*) FROM pn_email_codes WHERE email = ? AND purpose = 'clear_desk_memory' AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
        $st->execute(array($email));
        if ((int)$st->fetchColumn() > 0) {
            http_response_code(429);
            echo json_encode(array('success' => false, 'message' => '发送过于频繁，请 60 秒后再试'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 同邮箱 1 小时 8 次上限
        $st = $pdo->prepare("SELECT COUNT(*) FROM pn_email_codes WHERE email = ? AND purpose = 'clear_desk_memory' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute(array($email));
        if ((int)$st->fetchColumn() >= 8) {
            http_response_code(429);
            echo json_encode(array('success' => false, 'message' => '验证码发送次数超限，请 1 小时后再试'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        try { $code = (string)random_int(100000, 999999); }
        catch (Throwable $e) { $code = (string)mt_rand(100000, 999999); }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : '';
        $st = $pdo->prepare("INSERT INTO pn_email_codes (email, code, purpose, ip, attempts, used, created_at, expires_at)
                             VALUES (?, ?, 'clear_desk_memory', ?, 0, 0, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        $st->execute(array($email, $code, $ip));

        require_once __DIR__ . '/../config/mailer.php';
        $r = sendCodeEmail($email, $code, 'clear_desk_memory');
        if (!$r['ok']) {
            $pdo->prepare("DELETE FROM pn_email_codes WHERE email = ? AND code = ? AND purpose = 'clear_desk_memory' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->execute(array($email, $code));
            http_response_code(500);
            echo json_encode(array('success' => false, 'message' => '验证码邮件发送失败：' . $r['err']), JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(array(
            'success' => true,
            'message' => '验证码已发送至安全邮箱 ' . maskEmailAddress($email) . '，10分钟内有效'
        ), JSON_UNESCAPED_UNICODE);
        break;

    // 3 级验证通过：清空 AI 记忆上下文
    case 'clear_memory':
        $pdo = getDB();
        if (!$pdo) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'message' => '数据库连接异常'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $code = isset($input['code']) ? trim((string)$input['code']) : '';
        if (!preg_match('/^\d{6}$/', $code)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '请输入正确的 6 位数字验证码'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $st = $pdo->prepare("SELECT email FROM pn_users WHERE id = ?");
        $st->execute(array($userId));
        $uRow = $st->fetch();
        if (!$uRow || empty($uRow['email'])) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '用户账号异常'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $email = strtolower(trim((string)$uRow['email']));

        if (!verifyDeskMemoryEmailCode($pdo, $email, $code)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '验证码错误或已失效（最多尝试5次）'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 验证通过，清空当前用户在 pn_desk_memories 中的全部上下文记录
        $delSt = $pdo->prepare("DELETE FROM `pn_desk_memories` WHERE `user_id` = ?");
        $delSt->execute(array($userId));
        $deletedCount = $delSt->rowCount();

        echo json_encode(array(
            'success' => true,
            'deleted' => $deletedCount,
            'message' => "已彻底遗忘过往记忆（已清理 {$deletedCount} 条对话上下文），桌面管家将重新开始！"
        ), JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => '未知操作'), JSON_UNESCAPED_UNICODE);
        break;
}
