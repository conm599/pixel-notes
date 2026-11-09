<?php
/**
 * 桌面状态屏明文 TCP 守护服务 (Raw TCP Socket Server)
 * 专为单片机 (ESP32-C3) 极简接入设计，零 HTTP/HTTPS 协议开销，零 TLS 握手开销
 *
 * 协议规范（请求单行发送，以 \n 结尾）：
 * 1. 极简文本格式（首选推荐，MCU 内存消耗最小）：
 *    - STATUS <API_KEY>      -> 返回状态 JSON + \n，立即关闭连接
 *    - ROAST <API_KEY>       -> 逐字流式回传吐槽（0.1s/字），传输完毕关闭连接
 *    - MOCK_STATUS [KEY]     -> 返回 Mock 状态 JSON
 *    - MOCK_ROAST [KEY]      -> 逐字流式回传 Mock 吐槽
 *
 * 2. JSON 格式（兼容）：
 *    - {"action":"status","key":"<API_KEY>"}
 *    - {"action":"roast","key":"<API_KEY>"}
 *
 * 异常响应：
 *    - ERR 401 UNAUTHORIZED
 *    - ERR 429 TOO MANY REQUESTS
 *    - ERR 503 SERVICE UNAVAILABLE
 *    - ERR 400 BAD REQUEST
 *
 * 运行方式：
 *    php tcp_server.php --port=8888
 *    或由 systemd / supervisor 常驻后台
 */

// 仅限 CLI 模式运行
if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI.\n");
}

require_once __DIR__ . '/../config/database.php';

// 解析命令行参数
$opts = getopt('', array('host:', 'port:'));
$host = isset($opts['host']) ? $opts['host'] : '0.0.0.0';
$port = isset($opts['port']) ? (int)$opts['port'] : 8888;

$address = "tcp://{$host}:{$port}";
$context = stream_context_create();
$server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if (!$server) {
    fwrite(STDERR, "[-] Failed to start TCP server on {$address}: [{$errno}] {$errstr}\n");
    exit(1);
}

// 设置非阻塞
stream_set_blocking($server, false);

fwrite(STDOUT, "[+] Pixel Notes Plain TCP Server started on {$address} (PID: " . getmypid() . ")\n");
fwrite(STDOUT, "[+] Supported commands: STATUS <key>, ROAST <key>, MOCK_STATUS, MOCK_ROAST\n");

// 时区上海
date_default_timezone_set(suite_cfg('app_timezone', 'Asia/Shanghai'));

// 简单的客户端跟踪
$clients = array();

while (true) {
    $read = array($server);
    foreach ($clients as $c) {
        $read[] = $c['socket'];
    }
    $write = null;
    $except = null;

    $numChanged = @stream_select($read, $write, $except, 0, 200000); // 200ms 超时

    if ($numChanged === false) {
        break; // 收到中断信号
    }

    if ($numChanged > 0) {
        // 新连接进入
        if (in_array($server, $read, true)) {
            $clientSocket = @stream_socket_accept($server, 0);
            if ($clientSocket) {
                stream_set_blocking($clientSocket, false);
                $id = (int)$clientSocket;
                $clients[$id] = array(
                    'socket' => $clientSocket,
                    'buffer' => '',
                    'connected_at' => time()
                );
            }
            // 从可读列表中移除 server，剩下的是客户端
            $key = array_search($server, $read, true);
            unset($read[$key]);
        }

        // 处理已有客户端发送的数据
        foreach ($read as $sock) {
            $id = (int)$sock;
            if (!isset($clients[$id])) continue;

            $data = @fread($sock, 1024);
            if ($data === false || $data === '') {
                // 客户端主动断开
                @fclose($sock);
                unset($clients[$id]);
                continue;
            }

            $clients[$id]['buffer'] .= $data;

            // 遇到换行符则解析指令
            if (strpos($clients[$id]['buffer'], "\n") !== false || strpos($clients[$id]['buffer'], "\r") !== false) {
                $rawCmd = trim($clients[$id]['buffer']);
                $clients[$id]['buffer'] = ''; // 清空缓冲区

                if ($rawCmd !== '') {
                    handleTcpRequest($sock, $rawCmd);
                }
                // 处理完毕关闭连接（MCU Pull 模式，一问一答后断开，开销最小）
                @fclose($sock);
                unset($clients[$id]);
            }
        }
    }

    // 清理超时空闲连接（超过 15 秒未发指令的丢弃）
    $now = time();
    foreach ($clients as $id => $c) {
        if ($now - $c['connected_at'] > 15) {
            @fclose($c['socket']);
            unset($clients[$id]);
        }
    }
}

/**
 * 处理单个 TCP 指令
 */
function handleTcpRequest($sock, $raw) {
    $action = '';
    $key = '';

    // 支持 JSON 格式
    if ($raw[0] === '{') {
        $json = @json_decode($raw, true);
        if ($json && is_array($json)) {
            $action = isset($json['action']) ? strtoupper(trim($json['action'])) : '';
            $key = isset($json['key']) ? trim($json['key']) : '';
        }
    } else {
        // 纯文本格式：STATUS <KEY> 或 ROAST <KEY>
        $parts = preg_split('/\s+/', $raw, 2);
        $action = strtoupper($parts[0]);
        $key = isset($parts[1]) ? trim($parts[1]) : '';
    }

    // 天气代理指令（同端口明文 TCP 代理 uapis.cn 天气服务）
    if ($action === 'WEATHER') {
        $cityOrCode = '';
        if ($raw[0] === '{') {
            $json = @json_decode($raw, true);
            if (!empty($json['adcode'])) {
                $cityOrCode = trim((string)$json['adcode']);
            } elseif (!empty($json['city'])) {
                $cityOrCode = trim((string)$json['city']);
            }
        } else {
            // 支持: WEATHER | WEATHER 北京 | WEATHER 110000 | WEATHER pn_desk_xxxx 北京
            $tokens = preg_split('/\s+/', trim($raw));
            array_shift($tokens); // 去掉 "WEATHER"
            foreach ($tokens as $token) {
                if (strpos($token, 'pn_desk_') === 0) {
                    $key = $token;
                } else {
                    $cityOrCode = $token;
                }
            }
        }

        $weatherJson = fetchWeatherProxy($cityOrCode);
        if ($weatherJson !== null) {
            @fwrite($sock, $weatherJson . "\n");
        } else {
            @fwrite($sock, "ERR 503 WEATHER SERVICE UNAVAILABLE\n");
        }
        return;
    }

    $isMock = in_array($action, array('MOCK_STATUS', 'MOCK_ROAST'), true);

    if ($isMock) {
        if ($action === 'MOCK_STATUS') {
            $data = json_encode(array(
                'ts'    => 1794128220,
                'd'     => '2026-11-08',
                'open'  => 1,
                'diary' => 0,
                'todo'  => 3
            ), JSON_UNESCAPED_SLASHES) . "\n";
            @fwrite($sock, $data);
            return;
        }

        if ($action === 'MOCK_ROAST') {
            $mockText = '今天日记又没写，还好意思看屏幕？';
            sendTcpStream($sock, $mockText);
            return;
        }
    }

    // 鉴权
    if ($key === '') {
        @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
        return;
    }

    $owner = getDeskKeyOwner($key);
    if (!$owner) {
        @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
        return;
    }

    $userId = (int)$owner['user_id'];
    $pdo = null;
    try { $pdo = getDB(); } catch (Throwable $e) {}

    // 记录 Key 最后使用时间
    if ($pdo) {
        try {
            $pdo->prepare("UPDATE `pn_api_keys` SET `last_used_at` = NOW() WHERE `id` = ?")->execute(array((int)$owner['id']));
        } catch (Throwable $e) {}
    }

    // 业务路由
    if ($action === 'STATUS') {
        $now = time();
        $today = date('Y-m-d', $now);

        // 1. open
        $open = 0;
        if (!empty($owner['last_login_at'])) {
            if (date('Y-m-d', strtotime($owner['last_login_at'])) === $today) {
                $open = 1;
            }
        }

        // 2. diary (方案 A: 今日写任意便签均算完成)
        $diary = 0;
        if ($pdo) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_notes` WHERE `user_id` = ? AND (DATE(`updated_at`) = ? OR DATE(`created_at`) = ?)");
                $st->execute(array($userId, $today, $today));
                if ((int)$st->fetchColumn() > 0) {
                    $diary = 1;
                }
            } catch (Throwable $e) {}
        }

        $resp = json_encode(array(
            'ts'    => $now,
            'd'     => $today,
            'open'  => $open,
            'diary' => $diary,
            'todo'  => 0
        ), JSON_UNESCAPED_SLASHES) . "\n";

        @fwrite($sock, $resp);
        return;
    }

    if ($action === 'ROAST') {
        $now = time();
        $today = date('Y-m-d', $now);

        // 1. 限流 60 秒 1 次
        $lockDir = sys_get_temp_dir() . '/pn_roast_limits';
        if (!is_dir($lockDir)) @mkdir($lockDir, 0777, true);
        $rateFile = $lockDir . '/rate_' . md5($key);
        if (is_file($rateFile)) {
            $lastReq = (int)@file_get_contents($rateFile);
            if ($now - $lastReq < 60) {
                @fwrite($sock, "ERR 429 TOO MANY REQUESTS\n");
                return;
            }
        }
        @file_put_contents($rateFile, (string)$now, LOCK_EX);

        // 2. 状态描述
        $openText = '今天尚未登录便签';
        if (!empty($owner['last_login_at']) && date('Y-m-d', strtotime($owner['last_login_at'])) === $today) {
            $openText = '今天已经登录过便签系统';
        }

        $diaryText = '今天还没写便签日记';
        if ($pdo) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_notes` WHERE `user_id` = ? AND (DATE(`updated_at`) = ? OR DATE(`created_at`) = ?)");
                $st->execute(array($userId, $today, $today));
                if ((int)$st->fetchColumn() > 0) {
                    $diaryText = '今天已经动笔写了便签日记';
                }
            } catch (Throwable $e) {}
        }

        $hour = (int)date('G', $now);
        if ($hour >= 0 && $hour < 6) $period = '深夜黎明';
        elseif ($hour >= 6 && $hour < 11) $period = '早晨上午';
        elseif ($hour >= 11 && $hour < 14) $period = '中午时分';
        elseif ($hour >= 14 && $hour < 18) $period = '下午工作时间';
        elseif ($hour >= 18 && $hour < 23) $period = '晚上休闲时间';
        else $period = '深夜时间';

        $persona = !empty($owner['persona']) ? trim($owner['persona']) : '嘴硬心软、爱吐槽的桌面管家';
        $persona = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $persona), 0, 200, 'UTF-8');

        // 加载记忆
        $memories = tcpLoadDeskMemory($userId, $pdo);

        $messages = array();
        $messages[] = array(
            'role' => 'system',
            'content' => "你是一个硬件桌面伴侣屏幕的吐槽管家。\n" .
                         "请根据用户设定的管家风格偏好和当前便签状态，输出一句精准、短小精悍的吐槽。\n" .
                         "【硬性铁律（必须绝对遵守）】：\n" .
                         "1. 输出字数绝对不能超过 30 个汉字。\n" .
                         "2. 严禁包含任何表情符号 (Emoji)、换行符、引号（\" 或 '）、Markdown 格式。\n" .
                         "3. 字符必须在常用中文 GB2312 编码范围内（硬件屏幕不支持生僻字）。\n" .
                         "4. 只输出这一句话本身，不要加任何前后缀、解释或客套。"
        );

        foreach ($memories as $m) {
            $messages[] = array('role' => $m['role'], 'content' => $m['content']);
        }

        $currentUserPrompt = "[用户管家风格偏好]: {$persona}\n" .
                              "[当前时间]: {$today} " . date('H:i') . " ({$period})\n" .
                              "[便签状态]: {$openText}；{$diaryText}。\n" .
                              "请按照管家风格，对当前状态给出一句 30 字以内的吐槽。";
        $messages[] = array('role' => 'user', 'content' => $currentUserPrompt);

        // 调用大模型
        $aiResult = tcpCallDeskAi($userId, $messages);
        if ($aiResult === null || trim($aiResult) === '') {
            @fwrite($sock, "ERR 503 SERVICE UNAVAILABLE\n");
            return;
        }

        // 字符清洗与 30 字截断
        $cleanText = tcpSanitizeRoast($aiResult);

        // 存记忆
        tcpSaveDeskMemory($userId, $currentUserPrompt, $cleanText, $pdo);

        // 逐字流式分块输出
        sendTcpStream($sock, $cleanText);
        return;
    }

    @fwrite($sock, "ERR 400 BAD REQUEST\n");
}

function sendTcpStream($sock, $text) {
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($text, $i, 1, 'UTF-8');
        @fwrite($sock, $char);
        usleep(100000); // 100ms = 0.1s
    }
    @fwrite($sock, "\n");
}

function tcpSanitizeRoast($text) {
    $text = str_replace(array("\r", "\n", "\t", '"', "'", "`", "\\"), ' ', $text);
    $text = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $text);
    $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
    if (function_exists('iconv')) {
        $gb = @iconv('UTF-8', 'GB2312//IGNORE', $text);
        if ($gb !== false && $gb !== '') {
            $text = @iconv('GB2312', 'UTF-8', $gb);
        }
    }
    $text = trim(preg_replace('/\s+/', '', $text));
    $len = mb_strlen($text, 'UTF-8');
    if ($len > 30) {
        $sub = mb_substr($text, 0, 30, 'UTF-8');
        if (preg_match('/^(.+[，。！？~；])/u', $sub, $m)) {
            $text = $m[1];
        } else {
            $text = $sub;
        }
    }
    return $text;
}

function tcpLoadDeskMemory($userId, $pdo) {
    if (!$pdo) return array();
    try {
        $st = $pdo->prepare("SELECT id, role, content, content_len FROM `pn_desk_memories` WHERE `user_id` = ? ORDER BY `id` ASC");
        $st->execute(array($userId));
        $all = $st->fetchAll();
        if (empty($all)) return array();

        $totalLen = 0;
        foreach ($all as $item) {
            $totalLen += (int)$item['content_len'];
        }

        if ($totalLen >= 200000 && count($all) > 30) {
            $recent = array_slice($all, -30);
            $cutoffId = (int)$recent[0]['id'];
            $pdo->prepare("DELETE FROM `pn_desk_memories` WHERE `user_id` = ? AND `id` < ?")->execute(array($userId, $cutoffId));
            $summaryContent = "[往期互动记忆摘要: 用户长期使用桌面状态屏，曾多次在不同时段被吐槽，保持了连续的互动习惯]";
            $summaryLen = mb_strlen($summaryContent, 'UTF-8');
            $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'assistant', ?, ?, NOW())")
                ->execute(array($userId, $summaryContent, $summaryLen));

            $st = $pdo->prepare("SELECT id, role, content, content_len FROM `pn_desk_memories` WHERE `user_id` = ? ORDER BY `id` ASC");
            $st->execute(array($userId));
            return $st->fetchAll();
        }
        return $all;
    } catch (Throwable $e) {
        return array();
    }
}

function tcpSaveDeskMemory($userId, $userContent, $assistantContent, $pdo) {
    if (!$pdo) return;
    try {
        $userClean = mb_substr($userContent, 0, 300, 'UTF-8');
        $uLen = mb_strlen($userClean, 'UTF-8');
        $aLen = mb_strlen($assistantContent, 'UTF-8');
        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'user', ?, ?, NOW())");
        $st->execute(array($userId, $userClean, $uLen));
        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'assistant', ?, ?, NOW())");
        $st->execute(array($userId, $assistantContent, $aLen));
    } catch (Throwable $e) {}
}

function tcpCallDeskAi($userId, $messages) {
    $userPrefs = getUserAiPrefs($userId);
    $mode = (isset($userPrefs['mode']) && $userPrefs['mode'] === 'own') ? 'own' : 'platform';
    $url = '';
    $key = '';
    $model = '';

    if ($mode === 'own' && !empty($userPrefs['own_api_key'])) {
        $rawTarget = rtrim(trim((string)$userPrefs['own_base_url']), '/');
        $key = trim((string)$userPrefs['own_api_key']);
        $model = trim((string)$userPrefs['own_model']);
        $target = preg_replace('#^https?://#i', '', $rawTarget);
        if (substr($target, -17) !== '/chat/completions') {
            $target .= '/chat/completions';
        }
        $proxyPrefix = rtrim(trim(getSetting('ai_own_proxy', '')), '/');
        if (!empty($proxyPrefix)) {
            $url = $proxyPrefix . '/' . $target;
        } else {
            $url = (strpos($rawTarget, 'http') === 0 ? $rawTarget : 'https://' . $target);
        }
    } else {
        $base = rtrim(trim(getSetting('ai_base_url', '')), '/');
        $key  = trim(getSetting('ai_api_key', ''));
        $model = trim(getSetting('ai_model', ''));
        if (empty($base) || empty($key) || empty($model)) {
            return null;
        }
        $url = (substr($base, -17) === '/chat/completions') ? $base : $base . '/chat/completions';
    }

    if (empty($url) || empty($key) || empty($model)) return null;

    $payload = json_encode(array(
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 80,
        'temperature' => 0.7,
        'stream' => false
    ), JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $key));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code < 200 || $code >= 300 || empty($resp)) return null;
    $json = json_decode($resp, true);
    if (!isset($json['choices'][0]['message']['content'])) return null;
    $reply = $json['choices'][0]['message']['content'];
    $reply = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $reply);
    return trim($reply);
}


/**
 * 代理获取 uapis.cn 天气数据（带 10 分钟本地缓存与故障降级）
 */

function fetchWeatherProxy($cityOrCode = '') {
    $cityOrCode = trim((string)$cityOrCode);
    $cacheKey = 'pn_weather_' . md5($cityOrCode);
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';

    // 10 分钟缓存（600 秒），防止频繁请求被上游限流并极大降低 MCU 响应延迟
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 600)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached && strlen($cached) > 20) {
            return trim($cached);
        }
    }

    $url = 'https://uapis.cn/api/v1/misc/weather';
    if ($cityOrCode !== '') {
        if (preg_match('/^\d{6}$/', $cityOrCode)) {
            $url .= '?adcode=' . urlencode($cityOrCode);
        } else {
            $url .= '?city=' . urlencode($cityOrCode);
        }
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PixelNotes-DeskScreen/1.0');
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && !empty($resp)) {
        $data = json_decode($resp, true);
        if ($data && (isset($data['weather']) || isset($data['temperature']))) {
            @file_put_contents($cacheFile, $resp, LOCK_EX);
            return trim($resp);
        }
    }

    // 上游失败时若有旧缓存则降级返回旧缓存
    if (is_file($cacheFile)) {
        $stale = @file_get_contents($cacheFile);
        if ($stale) return trim($stale);
    }

    return null;
}


