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

                // 统计今日留言次数（每日上限 10 次）
                $today = date('Y-m-d');
                $stMsg = $pdo->prepare("SELECT COUNT(*) FROM `pn_desk_memories` WHERE `user_id` = ? AND `role` = 'user' AND `content` LIKE '%用户留言]%' AND DATE(`created_at`) = ?");
                $stMsg->execute(array($userId, $today));
                $todayMsgCount = (int)$stMsg->fetchColumn();
                $todayMsgRemain = max(0, 10 - $todayMsgCount);
            } catch (Throwable $e) {}
        }

        $novelBooks = function_exists('getUserNovels') ? getUserNovels($userId) : array();
        $currentNovel = function_exists('getCurrentUserNovel') ? getCurrentUserNovel($userId) : null;
        $isAdmin = false;
        if ($pdo) {
            try {
                $stAdmin = $pdo->prepare("SELECT is_admin FROM `pn_users` WHERE `id` = ?");
                $stAdmin->execute(array($userId));
                $isAdmin = ((int)$stAdmin->fetchColumn() === 1);
            } catch (Throwable $e) {}
        }

        echo json_encode(array(
            'success'          => true,
            'is_admin'         => $isAdmin,
            'novel_books'      => $novelBooks,
            'current_novel'    => $currentNovel,
            'has_key'          => $keyRow !== null,
            'api_key'          => $keyRow ? $keyRow['api_key'] : '',
            'persona'          => ($keyRow && !empty($keyRow['persona'])) ? $keyRow['persona'] : '嘴硬心软、爱吐槽的桌面管家',
            'created_at'       => $keyRow ? $keyRow['created_at'] : '',
            'last_used_at'     => $keyRow ? $keyRow['last_used_at'] : '',
            'memory_count'     => $memCount,
            'user_email'       => $maskedEmail,
            'today_msg_count'  => isset($todayMsgCount) ? $todayMsgCount : 0,
            'today_msg_remain' => isset($todayMsgRemain) ? $todayMsgRemain : 10,
            'today_msg_max'    => 10,
            'api_base'         => siblingUrl('bianqian', '/api/open.php')
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

    // 用户给管家留言（每日最多 10 次，不触发 AI，直接注入记忆末尾）
    case 'leave_message':
        $pdo = getDB();
        if (!$pdo) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'message' => '数据库连接异常'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $msg = isset($input['message']) ? trim((string)$input['message']) : '';
        if ($msg === '') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '留言内容不能为空'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $msg = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $msg), 0, 200, 'UTF-8');

        $today = date('Y-m-d');
        $stMsg = $pdo->prepare("SELECT COUNT(*) FROM `pn_desk_memories` WHERE `user_id` = ? AND `role` = 'user' AND `content` LIKE '%用户留言]%' AND DATE(`created_at`) = ?");
        $stMsg->execute(array($userId, $today));
        $todayMsgCount = (int)$stMsg->fetchColumn();
        if ($todayMsgCount >= 10) {
            http_response_code(429);
            echo json_encode(array('success' => false, 'message' => '今日给管家的留言次数已达上限（每天最多10次），明天再来吧~'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $nowStr = date('Y-m-d H:i');
        $storedContent = "[{$nowStr} 用户留言]: {$msg}";
        $uLen = mb_strlen($storedContent, 'UTF-8');

        // 直接注入记忆表末尾，不触发 AI
        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'user', ?, ?, NOW())");
        $st->execute(array($userId, $storedContent, $uLen));

        $newRemain = max(0, 10 - ($todayMsgCount + 1));
        echo json_encode(array(
            'success' => true,
            'message' => '便利贴已贴好！管家下次醒来时就会看到你的留言~',
            'remain'  => $newRemain,
            'count'   => $todayMsgCount + 1,
            'stored'  => $storedContent
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

    // 小说书架管理：查询当前书架
    case 'shelf_list':
        $novelBooks = function_exists('getUserNovels') ? getUserNovels($userId) : array();
        $currentNovel = function_exists('getCurrentUserNovel') ? getCurrentUserNovel($userId) : null;
        echo json_encode(array(
            'success' => true,
            'total'   => count($novelBooks),
            'max'     => 10,
            'current' => $currentNovel,
            'books'   => $novelBooks
        ), JSON_UNESCAPED_UNICODE);
        break;

    // 小说书架管理：站内搜索番茄小说（仅限管理员权限）
    case 'shelf_search':
        $pdo = getDB();
        $isAdmin = false;
        if ($pdo) {
            $stA = $pdo->prepare("SELECT is_admin FROM `pn_users` WHERE `id` = ?");
            $stA->execute(array($userId));
            $isAdmin = ((int)$stA->fetchColumn() === 1);
        }
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(array('success' => false, 'message' => '小说搜索服务当前仅限管理员使用'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : (isset($input['q']) ? trim((string)$input['q']) : '');
        if ($q === '') {
            echo json_encode(array('success' => true, 'items' => array()), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fqHost = function_exists('getFanqieHost') ? getFanqieHost() : 'http://111.170.163.248:53223';
        $fqKey  = function_exists('getFanqieKey') ? getFanqieKey() : '5b42436aea8a5296e40b4b95d2ed2ad7';
        $searchUrl = $fqHost . '/api/v1/search?q=' . urlencode($q) . '&pages=1';
        $ch = curl_init($searchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-API-Key: ' . $fqKey));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $items = array();
        if ($code === 200 && !empty($resp)) {
            $dec = json_decode($resp, true);
            if ($dec && isset($dec['data']['items']) && is_array($dec['data']['items'])) {
                $items = $dec['data']['items'];
            } elseif ($dec && isset($dec['books']) && is_array($dec['books'])) {
                $items = $dec['books'];
            }
        }

        echo json_encode(array('success' => true, 'items' => $items), JSON_UNESCAPED_UNICODE);
        break;

    // 小说书架管理：添加小说到书架（限制最多 10 本）
    case 'shelf_add':
        $bookId = isset($input['book_id']) ? trim((string)$input['book_id']) : (isset($_REQUEST['book_id']) ? trim((string)$_REQUEST['book_id']) : '');
        $title  = isset($input['title']) ? trim((string)$input['title']) : (isset($_REQUEST['title']) ? trim((string)$_REQUEST['title']) : '');
        $author = isset($input['author']) ? trim((string)$input['author']) : (isset($_REQUEST['author']) ? trim((string)$_REQUEST['author']) : '');
        $chaps  = isset($input['chapters']) ? (int)$input['chapters'] : 0;
        $pages  = isset($input['pages']) ? (int)$input['pages'] : 0;

        if ($bookId === '' || $title === '') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '书籍ID或标题不能为空'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 若缺少章节或页数，尝试向上游 info 接口补全
        $fqHost = function_exists('getFanqieHost') ? getFanqieHost() : 'http://111.170.163.248:53223';
        $fqKey  = function_exists('getFanqieKey') ? getFanqieKey() : '5b42436aea8a5296e40b4b95d2ed2ad7';
        if ($chaps <= 0 || $pages <= 0) {
            $infoUrl = $fqHost . '/api/v1/mcu/info?book=' . urlencode($bookId);
            $ch = curl_init($infoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-API-Key: ' . $fqKey));
            $infoResp = @curl_exec($ch);
            @curl_close($ch);
            if ($infoResp) {
                $infoJson = @json_decode($infoResp, true);
                if ($infoJson && is_array($infoJson)) {
                    if (!empty($infoJson['chapters'])) $chaps = (int)$infoJson['chapters'];
                    if (!empty($infoJson['pages'])) $pages = (int)$infoJson['pages'];
                    if (empty($author) && !empty($infoJson['author'])) $author = trim((string)$infoJson['author']);
                }
            }
        }

        $res = function_exists('addUserNovel') ? addUserNovel($userId, $bookId, $title, $author, $chaps, $pages) : array('ok' => false, 'error' => '功能未初始化');
        if (!empty($res['ok'])) {
            echo json_encode(array(
                'success' => true,
                'message' => '已成功添加《' . $title . '》到书架！',
                'books'   => getUserNovels($userId),
                'current' => getCurrentUserNovel($userId)
            ), JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => $res['error'] ?? '添加失败'), JSON_UNESCAPED_UNICODE);
        }
        break;

    // 小说书架管理：移出书架
    case 'shelf_remove':
        $bookId = isset($input['book_id']) ? trim((string)$input['book_id']) : (isset($_REQUEST['book_id']) ? trim((string)$_REQUEST['book_id']) : '');
        if ($bookId === '') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '书籍ID不能为空'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $res = function_exists('removeUserNovel') ? removeUserNovel($userId, $bookId) : array('ok' => false);
        echo json_encode(array(
            'success' => !empty($res['ok']),
            'message' => !empty($res['ok']) ? '已从书架移出' : '移出失败',
            'books'   => getUserNovels($userId),
            'current' => getCurrentUserNovel($userId)
        ), JSON_UNESCAPED_UNICODE);
        break;

    // 小说书架管理：推送到 MCU（设为当前激活书籍）
    case 'shelf_push':
    case 'shelf_set_current':
        $bookId = isset($input['book_id']) ? trim((string)$input['book_id']) : (isset($_REQUEST['book_id']) ? trim((string)$_REQUEST['book_id']) : '');
        if ($bookId === '') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => '书籍ID不能为空'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $res = function_exists('setCurrentUserNovel') ? setCurrentUserNovel($userId, $bookId) : array('ok' => false);
        if (!empty($res['ok'])) {
            echo json_encode(array(
                'success' => true,
                'message' => '已成功推送《' . ($res['title'] ?? '') . '》至 MCU 设备端！',
                'books'   => getUserNovels($userId),
                'current' => getCurrentUserNovel($userId)
            ), JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(400);
            echo json_encode(array('success' => false, 'message' => $res['error'] ?? '推送失败'), JSON_UNESCAPED_UNICODE);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => '未知操作'), JSON_UNESCAPED_UNICODE);
        break;
}
