<?php
/**
 * 最小 SMTP 客户端（无外部依赖）
 * - 支持 465（隐式 SSL）与 587（STARTTLS）
 * - 配置存 pn_settings：smtp_host / smtp_port / smtp_user / smtp_pass / smtp_from_name
 * - 邮箱白名单存 pn_settings：email_whitelist（逗号分隔域名）
 */

require_once __DIR__ . '/database.php';

function defaultEmailWhitelist() {
    return 'qq.com,foxmail.com,163.com,126.com,sina.com,sohu.com,yeah.net,139.com,189.cn,aliyun.com,gmail.com,outlook.com,hotmail.com,live.com,icloud.com,me.com,yahoo.com,yahoo.co.jp,protonmail.com,naxid.top';
}

/**
 * 邮箱域名是否在白名单（主流邮箱 + naxid.top，管理员可在后台调整）
 */
function emailDomainAllowed($email) {
    $at = strrpos($email, '@');
    if ($at === false) return false;
    $domain = strtolower(trim(substr($email, $at + 1)));
    if ($domain === '') return false;
    $list = trim(getSetting('email_whitelist', ''));
    if ($list === '') $list = defaultEmailWhitelist();
    $allowed = array();
    foreach (explode(',', $list) as $d) {
        $d = strtolower(trim($d));
        if ($d !== '') $allowed[] = $d;
    }
    return in_array($domain, $allowed, true);
}

function smtpRead($fp, $expect) {
    $data = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    if ($expect !== '' && strncmp($data, $expect, strlen($expect)) !== 0) {
        return array(false, 'SMTP 通信错误（期望 ' . $expect . '，收到 ' . mb_substr(trim($data), 0, 160, 'UTF-8') . '）');
    }
    return array(true, $data);
}

/**
 * 发送 HTML 邮件。返回 array('ok'=>bool, 'err'=>string)
 */
function smtpSend($toEmail, $subject, $htmlBody) {
    $host = trim(getSetting('smtp_host', 'smtp.qq.com'));
    $port = (int)getSetting('smtp_port', 465);
    $user = trim(getSetting('smtp_user', ''));
    $pass = trim(getSetting('smtp_pass', ''));
    $name = trim(getSetting('smtp_from_name', ''));
    if ($name === '') $name = 'Pixel Notes';
    if ($user === '' || $pass === '') {
        return array('ok' => false, 'err' => '邮件服务未配置（管理员需在管理页填写 SMTP 信息）');
    }
    if ($host === '') $host = 'smtp.qq.com';

    $secure = ($port === 465) ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($secure . $host . ':' . $port, $errno, $errstr, 15);
    if (!$fp) {
        return array('ok' => false, 'err' => '无法连接邮件服务器 ' . $host . ':' . $port . '（' . $errstr . '）');
    }
    stream_set_timeout($fp, 25);

    $fail = null;
    $step = function ($cmd, $expect) use ($fp, &$fail) {
        if ($fail !== null) return;
        if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
        $r = smtpRead($fp, $expect);
        if (!$r[0]) $fail = $r[1];
    };

    $step(null, '220');
    $step('EHLO pixelnotes', '250');
    if ($fail === null && $port !== 465) {
        $step('STARTTLS', '220');
        if ($fail === null) {
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $fail = 'STARTTLS 握手失败';
            } else {
                $step('EHLO pixelnotes', '250');
            }
        }
    }
    $step('AUTH LOGIN', '334');
    if ($fail === null) {
        fwrite($fp, base64_encode($user) . "\r\n");
        $r = smtpRead($fp, '334');
        if (!$r[0]) $fail = $r[1];
    }
    if ($fail === null) {
        fwrite($fp, base64_encode($pass) . "\r\n");
        $r = smtpRead($fp, '235');
        if (!$r[0]) $fail = 'SMTP 授权失败（检查账号与授权码）：' . mb_substr($r[1], 0, 120, 'UTF-8');
    }
    $step('MAIL FROM:<' . $user . '>', '250');
    $step('RCPT TO:<' . $toEmail . '>', '250');
    $step('DATA', '354');

    if ($fail === null) {
        $headers = 'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $user . ">\r\n"
            . 'To: <' . $toEmail . ">\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Date: ' . date('r') . "\r\n";
        $msg = $headers . "\r\n" . chunk_split(base64_encode($htmlBody));
        $msg = preg_replace('/^\./m', '..', $msg);
        fwrite($fp, $msg . "\r\n.\r\n");
        $r = smtpRead($fp, '250');
        if (!$r[0]) $fail = '邮件被服务器拒收：' . mb_substr($r[1], 0, 120, 'UTF-8');
    }

    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    if ($fail !== null) return array('ok' => false, 'err' => $fail);
    return array('ok' => true, 'err' => '');
}

/**
 * 生成并发送验证码邮件。返回 array('ok'=>bool, 'err'=>string)
 */
function sendCodeEmail($email, $code, $purpose) {
    $titles = array(
        'register' => '注册验证码',
        'login'    => '登录验证码',
        'reset'    => '重置密码验证码',
        'delete'   => '注销账号验证码',
    );
    $title = isset($titles[$purpose]) ? $titles[$purpose] : '验证码';
    $html = '<div style="max-width:480px;margin:0 auto;padding:28px 24px;font-family:-apple-system,Segoe UI,Microsoft YaHei,sans-serif;background:#0d0d1f;border-radius:8px;color:#e8e8f5">'
        . '<div style="font-size:14px;letter-spacing:2px;color:#ff6b9d;font-weight:bold;margin-bottom:18px">&#127918; PIXEL NOTES</div>'
        . '<h2 style="font-size:16px;color:#4af0ff;margin:0 0 14px">' . $title . '</h2>'
        . '<p style="font-size:14px;line-height:1.9;margin:0 0 18px">你的验证码是：</p>'
        . '<div style="font-size:30px;font-weight:bold;letter-spacing:8px;color:#00ff88;border:2px dashed #2a2a5a;padding:14px 18px;text-align:center;margin-bottom:18px">' . $code . '</div>'
        . '<p style="font-size:12px;color:#8888a8;line-height:1.9;margin:0">10 分钟内有效。如非本人操作，请忽略本邮件。<br>&#128274; 便签 Pixel Notes 团队</p>'
        . '</div>';
    return smtpSend($email, '【Pixel Notes】' . $title . '：' . $code, $html);
}
