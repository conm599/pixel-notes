<?php
/**
 * 用户认证 API（安全加固版）
 * action: sendcode | register | login | logincode | resetpass | changepass | testmail | check | logout
 *
 * 加固措施：
 * - 仅接受 JSON 请求体（根治 CSRF）
 * - 注册/重置：必须邮箱验证码（6 位，10 分钟有效，错误 5 次作废）
 * - 邮箱白名单：仅主流邮箱 + naxid.top（管理员可改），挡掉临时邮箱
 * - 同邮箱 60 秒冷却、8 次/小时；同 IP 20 次/小时；登录失败 15 分钟 5 次锁定
 * - 会话 Cookie：HttpOnly + Secure + SameSite=Lax
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';

startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sendSecurityHeaders();

ini_set('display_errors', '0');
error_reporting(E_ALL);

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
    exit;
}

/** 校验验证码：成功标记已用，失败累计 attempts；返回 bool */
function verifyEmailCode($pdo, $email, $purpose, $code) {
    if (!preg_match('/^\d{6}$/', (string)$code)) return false;
    $st = $pdo->prepare("SELECT * FROM pn_email_codes
                         WHERE email = ? AND purpose = ? AND used = 0 AND attempts < 5
                           AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $st->execute(array($email, $purpose));
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

function randCode() {
    try { $c = (string)random_int(0, 999999); }
    catch (Exception $e) { $c = (string)mt_rand(0, 999999); }
    return str_pad($c, 6, '0', STR_PAD_LEFT);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = array();
    $action = isset($input['action']) ? $input['action'] : '';

    $schemaError = null;
    if ($action !== '' && $action !== 'check') {
        if (!ensureTables($schemaError)) {
            jsonResponse(array('success' => false, 'message' => '系统繁忙，请稍后再试'), 500);
        }
    }

    $pdo = getDB();
    $ip = clientIp();
    // 清理 24 小时前的旧验证码与登录失败记录
    $pdo->exec("DELETE FROM pn_email_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $pdo->exec("DELETE FROM pn_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

    // ================= 发送验证码 =================
    if ($action === 'sendcode') {
        $email = strtolower(trim(isset($input['email']) ? $input['email'] : ''));
        $purpose = isset($input['purpose']) ? $input['purpose'] : '';
        if (!in_array($purpose, array('register', 'login', 'reset', 'delete', 'backup'), true)) {
            jsonResponse(array('success' => false, 'message' => '用途参数非法'), 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(array('success' => false, 'message' => '请输入有效的邮箱地址'), 400);
        }
        if (!emailDomainAllowed($email)) {
            jsonResponse(array('success' => false, 'message' => '暂不支持该邮箱服务商，请使用主流邮箱（QQ/Gmail/Outlook/163 等）或 @naxid.top'), 400);
        }

        // 注册发码前必须通过图片人机验证（一次性：无论对错，校验后立即作废，须重新输入）
        if ($purpose === 'register') {
            $cap = isset($input['captcha']) ? strtoupper(trim((string)$input['captcha'])) : '';
            $capOk = $cap !== ''
                && isset($_SESSION['captcha_code'], $_SESSION['captcha_expire'])
                && time() <= $_SESSION['captcha_expire']
                && hash_equals($_SESSION['captcha_code'], $cap);
            $capRetry = isset($_SESSION['captcha_code']); // 是否真校验了（区别于"从未加载过"）
            unset($_SESSION['captcha_code'], $_SESSION['captcha_expire']);
            if (!$capOk) {
                jsonResponse(array('success' => false, 'message' => $capRetry ? '图片验证码错误或已过期' : '请先输入图片验证码', 'captcha_failed' => true), 400);
            }
        }

        // 同邮箱 60 秒冷却
        $st = $pdo->prepare("SELECT COUNT(*) FROM pn_email_codes WHERE email = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
        $st->execute(array($email, $purpose));
        if ((int)$st->fetchColumn() > 0) {
            jsonResponse(array('success' => false, 'message' => '发送太频繁，请 60 秒后再试'), 429);
        }
        // 同邮箱每小时 8 次
        $st = $pdo->prepare("SELECT COUNT(*) FROM pn_email_codes WHERE email = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute(array($email, $purpose));
        if ((int)$st->fetchColumn() >= 8) {
            jsonResponse(array('success' => false, 'message' => '该邮箱今日验证码请求过多，请稍后再试'), 429);
        }
        // 同 IP 每小时 20 次
        $st = $pdo->prepare("SELECT COUNT(*) FROM pn_email_codes WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute(array($ip));
        if ((int)$st->fetchColumn() >= 20) {
            jsonResponse(array('success' => false, 'message' => '请求过于频繁，请稍后再试'), 429);
        }

        // 注册：邮箱不能已存在
        if ($purpose === 'register') {
            $st = $pdo->prepare("SELECT id FROM pn_users WHERE email = ?");
            $st->execute(array($email));
            if ($st->fetch()) {
                jsonResponse(array('success' => false, 'message' => '该邮箱已被注册'), 409);
            }
        } elseif ($purpose === 'backup') {
            // 数据备份导入：仅限已登录的管理员，且必须用自己账号绑定的邮箱
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(array('success' => false, 'message' => '请先登录'), 401);
            }
            if (!isAdminUser()) {
                jsonResponse(array('success' => false, 'message' => '需要管理员权限'), 403);
            }
            $st = $pdo->prepare("SELECT id FROM pn_users WHERE email = ? AND id = ?");
            $st->execute(array($email, (int)$_SESSION['user_id']));
            if (!$st->fetch()) {
                jsonResponse(array('success' => false, 'message' => '邮箱与当前管理员账号不匹配'), 400);
            }
        } elseif ($purpose === 'delete') {
            // 注销：必须是已登录用户自己的邮箱
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(array('success' => false, 'message' => '请先登录'), 401);
            }
            $st = $pdo->prepare("SELECT id FROM pn_users WHERE email = ? AND id = ?");
            $st->execute(array($email, (int)$_SESSION['user_id']));
            if (!$st->fetch()) {
                jsonResponse(array('success' => false, 'message' => '邮箱与当前账号不匹配'), 400);
            }
        } else {
            // 登录/重置：账号不存在则伪成功（防枚举），但不实际发信
            $st = $pdo->prepare("SELECT id FROM pn_users WHERE email = ?");
            $st->execute(array($email));
            if (!$st->fetch()) {
                jsonResponse(array('success' => true, 'message' => '如果该邮箱存在，验证码已发送'));
            }
        }

        $code = randCode();
        $st = $pdo->prepare("INSERT INTO pn_email_codes (email, code, purpose, ip, attempts, used, created_at, expires_at)
                             VALUES (?, ?, ?, ?, 0, 0, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        $st->execute(array($email, $code, $purpose, $ip));
        $r = sendCodeEmail($email, $code, $purpose);
        if (!$r['ok']) {
            $pdo->prepare("DELETE FROM pn_email_codes WHERE email = ? AND code = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)")
                ->execute(array($email, $code));
            jsonResponse(array('success' => false, 'message' => '验证码发送失败：' . $r['err']), 500);
        }
        jsonResponse(array('success' => true, 'message' => '验证码已发送到你的邮箱（10 分钟内有效）'));
    }

    // ================= 注册（需验证码） =================
    if ($action === 'register') {
        // 注册限流：同 IP 1 小时内最多 5 次
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pn_login_attempts
                               WHERE action = 'register' AND ip = ?
                                 AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute(array($ip));
        if ((int)$stmt->fetchColumn() >= 5) {
            jsonResponse(array('success' => false, 'message' => '注册过于频繁，请1小时后再试'), 429);
        }

        $username = trim(isset($input['username']) ? $input['username'] : '');
        $email    = strtolower(trim(isset($input['email']) ? $input['email'] : ''));
        $password = isset($input['password']) ? $input['password'] : '';
        $code     = isset($input['code']) ? $input['code'] : '';

        $ulen = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
        if ($ulen < 2 || $ulen > 30) {
            jsonResponse(array('success' => false, 'message' => '用户名需要2-30个字符'), 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !emailDomainAllowed($email)) {
            jsonResponse(array('success' => false, 'message' => '邮箱无效或不被支持，请使用主流邮箱或 @naxid.top'), 400);
        }
        if (strlen($password) < 8) {
            jsonResponse(array('success' => false, 'message' => '密码至少需要8个字符'), 400);
        }
        if (!verifyEmailCode($pdo, $email, 'register', $code)) {
            jsonResponse(array('success' => false, 'message' => '验证码错误或已过期'), 400);
        }

        $stmt = $pdo->prepare("INSERT INTO pn_login_attempts (username, ip, action, success, attempted_at)
                               VALUES (?, ?, 'register', 1, NOW())");
        $stmt->execute(array(substr($username, 0, 100), $ip));

        $stmt = $pdo->prepare("SELECT id FROM pn_users WHERE email = ?");
        $stmt->execute(array($email));
        if ($stmt->fetch()) {
            jsonResponse(array('success' => false, 'message' => '用户名或邮箱已被注册'), 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            jsonResponse(array('success' => false, 'message' => '系统错误，请稍后再试'), 500);
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO pn_users (username, email, password_hash, created_at, email_verified)
                               VALUES (?, ?, ?, ?, 1)");
        $stmt->execute(array($username, $email, $hash, $now));

        $userId = (int)$pdo->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;

        jsonResponse(array(
            'success' => true,
            'message' => '注册成功！',
            'user' => array('username' => $username)
        ));
    }

    // ================= 密码登录 =================
    if ($action === 'login') {
        $login    = trim(isset($input['login']) ? $input['login'] : '');
        $password = isset($input['password']) ? $input['password'] : '';

        if ($login === '' || $password === '') {
            jsonResponse(array('success' => false, 'message' => '请输入邮箱/用户名和密码'), 400);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pn_login_attempts
                               WHERE action = 'login' AND success = 0
                                 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                                 AND (username = ? OR ip = ?)");
        $stmt->execute(array($login, $ip));
        if ((int)$stmt->fetchColumn() >= 5) {
            jsonResponse(array('success' => false, 'message' => '尝试次数过多，请15分钟后再试'), 429);
        }

        $stmt = $pdo->prepare("SELECT id, username, password_hash, email_verified FROM pn_users WHERE email = ?");
        $stmt->execute(array($login));
        $user = $stmt->fetch();

        $ok = $user && password_verify($password, $user['password_hash']);
        if (!$ok) {
            $stmt = $pdo->prepare("INSERT INTO pn_login_attempts (username, ip, action, success, attempted_at)
                                   VALUES (?, ?, 'login', 0, NOW())");
            $stmt->execute(array(substr($login, 0, 100), $ip));
            jsonResponse(array('success' => false, 'message' => '邮箱/用户名或密码错误'), 401);
        }
        if ((int)$user['email_verified'] !== 1) {
            jsonResponse(array('success' => false, 'message' => '该账号邮箱未验证，无法登录（如需恢复请联系管理员）'), 403);
        }

        $stmt = $pdo->prepare("DELETE FROM pn_login_attempts WHERE action = 'login' AND username = ?");
        $stmt->execute(array($login));

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];

        jsonResponse(array(
            'success' => true,
            'message' => '登录成功！',
            'user' => array('username' => $user['username'])
        ));
    }

    // ================= 验证码登录 =================
    if ($action === 'logincode') {
        $email = strtolower(trim(isset($input['email']) ? $input['email'] : ''));
        $code  = isset($input['code']) ? $input['code'] : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(array('success' => false, 'message' => '请输入有效的邮箱'), 400);
        }
        if (!verifyEmailCode($pdo, $email, 'login', $code)) {
            jsonResponse(array('success' => false, 'message' => '验证码错误或已过期'), 401);
        }
        $st = $pdo->prepare("SELECT id, username, email_verified FROM pn_users WHERE email = ?");
        $st->execute(array($email));
        $user = $st->fetch();
        if (!$user) {
            jsonResponse(array('success' => false, 'message' => '验证码错误或已过期'), 401);
        }
        if ((int)$user['email_verified'] !== 1) {
            jsonResponse(array('success' => false, 'message' => '该账号邮箱未验证，无法登录'), 403);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        jsonResponse(array('success' => true, 'message' => '登录成功！', 'user' => array('username' => $user['username'])));
    }

    // ================= 重置密码 =================
    if ($action === 'resetpass') {
        $email    = strtolower(trim(isset($input['email']) ? $input['email'] : ''));
        $code     = isset($input['code']) ? $input['code'] : '';
        $password = isset($input['password']) ? $input['password'] : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(array('success' => false, 'message' => '请输入有效的邮箱'), 400);
        }
        if (strlen($password) < 8) {
            jsonResponse(array('success' => false, 'message' => '密码至少需要8个字符'), 400);
        }
        if (!verifyEmailCode($pdo, $email, 'reset', $code)) {
            jsonResponse(array('success' => false, 'message' => '验证码错误或已过期'), 400);
        }
        $st = $pdo->prepare("SELECT id FROM pn_users WHERE email = ?");
        $st->execute(array($email));
        $user = $st->fetch();
        if (!$user) {
            jsonResponse(array('success' => false, 'message' => '验证码错误或已过期'), 400);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE pn_users SET password_hash = ? WHERE id = ?")
            ->execute(array($hash, (int)$user['id']));
        jsonResponse(array('success' => true, 'message' => '密码已重置，请用新密码登录', 'goto' => 'login'));
    }

    // ================= 修改密码（已登录） =================
    if ($action === 'changepass') {
        if (!isset($_SESSION['user_id'])) {
            jsonResponse(array('success' => false, 'message' => '请先登录'), 401);
        }
        $old = isset($input['oldpass']) ? $input['oldpass'] : '';
        $new = isset($input['newpass']) ? $input['newpass'] : '';
        if (strlen($new) < 8) {
            jsonResponse(array('success' => false, 'message' => '新密码至少需要8个字符'), 400);
        }
        $st = $pdo->prepare("SELECT password_hash FROM pn_users WHERE id = ?");
        $st->execute(array((int)$_SESSION['user_id']));
        $u = $st->fetch();
        if (!$u || !password_verify($old, $u['password_hash'])) {
            jsonResponse(array('success' => false, 'message' => '原密码错误'), 401);
        }
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE pn_users SET password_hash = ? WHERE id = ?")
            ->execute(array($hash, (int)$_SESSION['user_id']));
        jsonResponse(array('success' => true, 'message' => '密码已修改，下次登录请用新密码'));
    }

    // ================= 注销账号（已登录，需邮箱验证码） =================
    if ($action === 'deleteaccount') {
        if (!isset($_SESSION['user_id'])) {
            jsonResponse(array('success' => false, 'message' => '请先登录'), 401);
        }
        $uid = (int)$_SESSION['user_id'];
        $email = strtolower(trim(isset($input['email']) ? $input['email'] : ''));
        $code  = isset($input['code']) ? $input['code'] : '';

        $st = $pdo->prepare("SELECT email FROM pn_users WHERE id = ?");
        $st->execute(array($uid));
        $row = $st->fetch();
        if (!$row || strtolower((string)$row['email']) !== $email) {
            jsonResponse(array('success' => false, 'message' => '邮箱与当前账号不匹配'), 400);
        }
        if (!verifyEmailCode($pdo, $email, 'delete', $code)) {
            jsonResponse(array('success' => false, 'message' => '验证码错误或已过期'), 400);
        }

        // 事务删除该用户所有数据（便签/AI偏好/绑定的AI密钥/账号）
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM pn_notes WHERE user_id = ?")->execute(array($uid));
            $pdo->prepare("DELETE FROM pn_user_ai_prefs WHERE user_id = ?")->execute(array($uid));
            $pdo->prepare("DELETE FROM pn_ai_keys WHERE user_id = ?")->execute(array($uid));
            $pdo->prepare("DELETE FROM pn_login_attempts WHERE username = ?")->execute(array($_SESSION['username']));
            $pdo->prepare("DELETE FROM pn_users WHERE id = ?")->execute(array($uid));
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(array('success' => false, 'message' => '注销失败，数据已保留，请稍后再试'), 500);
        }

        $_SESSION = array();
        session_destroy();
        jsonResponse(array('success' => true, 'message' => '账号已注销，所有数据已删除', 'goto' => 'login'));
    }

    // ================= 管理员测试发信 =================
    if ($action === 'testmail') {
        if (!isAdminUser()) {
            jsonResponse(array('success' => false, 'message' => '需要管理员权限'), 403);
        }
        $to = trim(isset($input['to']) ? $input['to'] : '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(array('success' => false, 'message' => '收件邮箱格式无效'), 400);
        }
        $html = '<div style="font-family:sans-serif;padding:24px;color:#333">'
            . '<h3>&#127918; Pixel Notes 邮件测试</h3>'
            . '<p>这是一封测试邮件，用于确认 SMTP 配置正常工作。</p>'
            . '<p style="color:#888;font-size:12px">' . date('Y-m-d H:i:s') . '</p></div>';
        $r = smtpSend($to, '【Pixel Notes】邮件测试', $html);
        if ($r['ok']) jsonResponse(array('success' => true, 'message' => '邮件已发送至 ' . $to . '（若长期未收到请检查 SMTP 配置与邮箱垃圾箱）'));
        jsonResponse(array('success' => false, 'message' => $r['err']), 500);
    }

    // ================= 检查登录状态 =================
    if ($action === 'check') {
        if (isset($_SESSION['user_id'])) {
            $em = '';
            try {
                $st = getDB()->prepare("SELECT email FROM pn_users WHERE id = ?");
                $st->execute(array((int)$_SESSION['user_id']));
                $r = $st->fetchColumn();
                if ($r) $em = (string)$r;
            } catch (Exception $e) { /* 忽略 */ }
            jsonResponse(array('success' => true, 'logged_in' => true, 'user' => array(
                'id' => $_SESSION['user_id'],
                'username' => isset($_SESSION['username']) ? $_SESSION['username'] : '',
                'email' => $em
            )));
        }
        jsonResponse(array('success' => true, 'logged_in' => false));
    }

    // ================= 退出 =================
    if ($action === 'logout') {
        $_SESSION = array();
        session_destroy();
        jsonResponse(array('success' => true, 'message' => '已退出登录'));
    }

    jsonResponse(array('success' => false, 'message' => '未知操作'), 400);

} catch (PDOException $e) {
    jsonResponse(array('success' => false, 'message' => '数据库错误，请稍后再试'), 500);
} catch (Exception $e) {
    jsonResponse(array('success' => false, 'message' => '服务器内部错误'), 500);
}
