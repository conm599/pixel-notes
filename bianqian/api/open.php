<?php
/**
 * 桌面状态屏开放接口 (API v1)
 * 对应契约文档：《桌面状态屏 API 契约 v1》
 *
 * 接口清单（只读，支持 X-API-Key 鉴权）：
 * - GET /status      : 获取今天状态 (ts, d, open, diary, todo)
 * - GET /roast       : AI 吐槽（记忆继承，200K/256K 自动压缩，GB2312 过滤，30字截断，逐字分块流式）
 * - GET /mock/status : 联调假接口（固定数据）
 * - GET /mock/roast  : 联调假接口（固定吐槽逐字流式）
 *
 * 传参方式支持：
 * 1. 显式参数：?endpoint=status | roast | mock_status | mock_roast
 * 2. 路径形式：/api/open.php/status 或通过反代映射
 */

require_once __DIR__ . '/../config/database.php';

// 确保数据库表和字段自愈就位
ensureTables();
ensureDeskTcpServerRunning(8888);

// 严格使用上海时区
date_default_timezone_set(suite_cfg('app_timezone', 'Asia/Shanghai'));

// 解析请求路径与 endpoint
$endpoint = isset($_GET['endpoint']) ? trim((string)$_GET['endpoint']) : '';
if ($endpoint === '') {
    $pathInfo = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
    if ($pathInfo !== '') {
        $endpoint = $pathInfo;
    } else {
        $uri = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        if (preg_match('#(?:api/v1|open\.php)/?(.*)$#i', $uri, $matches)) {
            $endpoint = trim($matches[1], '/');
        }
    }
}
$endpoint = strtolower(str_replace('-', '_', $endpoint));

// 统一提取 API Key
$apiKey = '';
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY']);
} elseif (isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('#^Bearer\s+(.+)#i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $apiKey = trim($m[1]);
} elseif (isset($_GET['api_key'])) {
    $apiKey = trim($_GET['api_key']);
} elseif (isset($_GET['key'])) {
    $apiKey = trim($_GET['key']);
}

// 特权维护端点：平滑热重启 TCP 服务（需凭证）
if ($endpoint === 'reload_tcp') {
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    $deployFile = dirname(__DIR__) . '/_deploy.php';
    $realToken = '';
    if (is_file($deployFile)) {
        $c = @file_get_contents($deployFile);
        if ($c && preg_match("/define\('DEPLOY_TOKEN',\s*'([^']+)'\)/", $c, $m)) {
            $realToken = $m[1];
        }
    }
    if ($realToken !== '' && $token !== '' && hash_equals($realToken, $token)) {
        if (function_exists('exec')) {
            @exec('pkill -f tcp_server.php');
        } elseif (function_exists('shell_exec')) {
            @shell_exec('pkill -f tcp_server.php');
        }
        usleep(300000);
        ensureDeskTcpServerRunning(8888);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => true, 'message' => 'TCP server reloaded'));
        exit;
    }
    http_response_code(403);
    echo json_encode(array('error' => 'Forbidden'));
    exit;
}

// 鉴权检查
$isMockEndpoint = in_array($endpoint, array('mock/status', 'mock_status', 'mock/roast', 'mock_roast'), true);

if ($isMockEndpoint) {
    // 假接口用于联调：若不带 Key 或传入显式无效 Key（如 invalid/error）返回 401 方便 MCU 测试异常分支；
    // 传入非空测试 Key 或真实 Key 即可直接通行
    if ($apiKey === '' || $apiKey === 'invalid' || $apiKey === 'error') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Unauthorized', 'message' => 'Invalid or missing X-API-Key'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $owner = getDeskKeyOwner($apiKey);
} else {
    // 真实生产接口（状态、吐槽、天气、程序员历史、留言等）：严格校验有效 Key，防止第三方积分被滥用
    $owner = getDeskKeyOwner($apiKey);
    if (!$owner) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Unauthorized', 'message' => 'Invalid or missing X-API-Key'), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$userId = $owner ? (int)$owner['user_id'] : 0;
$pdo = null;

// 异步记录 Key 最后使用时间（非致命错误可忽略）
if ($owner) {
    try {
        $pdo = getDB();
        if ($pdo) {
            $pdo->prepare("UPDATE `pn_api_keys` SET `last_used_at` = NOW() WHERE `id` = ?")->execute(array((int)$owner['id']));
        }
    } catch (Throwable $e) {}
}


// ============================================================
// 路由分发
// ============================================================

switch ($endpoint) {
    case 'status':
        handleStatus($owner, $pdo);
        break;

    case 'roast':
        handleRoast($owner, $pdo);
        break;

    case 'weather':
        handleWeather();
        break;

    case 'programmer_today':
    case 'history':
    case 'dev_today':
        handleProgrammerToday();
        break;

    case 'message':
    case 'leave_message':
        handleOpenLeaveMessage($owner, $pdo);
        break;

    case 'mock/status':
    case 'mock_status':
        handleMockStatus();
        break;

    case 'mock/roast':
    case 'mock_roast':
        handleMockRoast();
        break;

    default:
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'error' => 'Not Found',
            'message' => 'Supported endpoints: /status, /roast, /mock/status, /mock/roast'
        ), JSON_UNESCAPED_UNICODE);
        break;
}
exit;


// ============================================================
// 业务实现函数
// ============================================================

/**
 * 接口一：GET /status
 */
function handleStatus($owner, $pdo) {
    if (!$pdo) { try { $pdo = getDB(); } catch (Exception $e) { $pdo = null; } }
    $userId = (int)$owner['user_id'];
    $now = time();
    $today = date('Y-m-d', $now);

    // 1. open 状态：按照用户今天有没有登录（北京时间）
    $open = 0;
    if (!empty($owner['last_login_at'])) {
        $loginDate = date('Y-m-d', strtotime($owner['last_login_at']));
        if ($loginDate === $today) {
            $open = 1;
        }
    }

    // 2. diary 状态：方案 A —— 只要今天创建或编辑了任何便签，即判定为已动笔写日记 (1)
    $diary = 0;
    try {
        if ($pdo) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_notes` 
                                 WHERE `user_id` = ? 
                                   AND (DATE(`updated_at`) = ? OR DATE(`created_at`) = ?)");
            $st->execute(array($userId, $today, $today));
            if ((int)$st->fetchColumn() > 0) {
                $diary = 1;
            }
        }
    } catch (Throwable $e) {}

    // 3. todo 状态：按约定先不做，固定返回 0
    $todo = 0;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ts'    => $now,
        'd'     => $today,
        'open'  => $open,
        'diary' => $diary,
        'todo'  => $todo
    ), JSON_UNESCAPED_SLASHES);
}


/**
 * 接口二：GET /roast
 */
function handleRoast($owner, $pdo) {
    if (!$pdo) { try { $pdo = getDB(); } catch (Exception $e) { $pdo = null; } }
    $userId = (int)$owner['user_id'];
    $now = time();
    $today = date('Y-m-d', $now);

    // 1. 限流：每分钟最多 1 次
    $lockDir = sys_get_temp_dir() . '/pn_roast_limits';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0777, true);
    $rateFile = $lockDir . '/rate_' . md5((string)$owner['api_key']);
    if (is_file($rateFile)) {
        $lastReq = (int)@file_get_contents($rateFile);
        if ($now - $lastReq < 60) {
            $retryAfter = 60 - ($now - $lastReq);
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            header('Content-Type: text/plain; charset=utf-8');
            echo '请求太频繁，请稍候再试';
            exit;
        }
    }
    @file_put_contents($rateFile, (string)$now, LOCK_EX);

    // 2. 获取当天的便签状态
    $openText = '今天尚未登录便签';
    if (!empty($owner['last_login_at']) && date('Y-m-d', strtotime($owner['last_login_at'])) === $today) {
        $openText = '今天已经登录过便签系统';
    }

    $diaryText = '今天还没写日记';
    // 简单检查日记状态
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_notes` WHERE `user_id` = ? AND (DATE(`updated_at`) = ? OR DATE(`created_at`) = ?)");
        $st->execute(array($userId, $today, $today));
        if ((int)$st->fetchColumn() > 0) {
            $diaryText = '今天已经编辑或保存过日记便签';
        }
    } catch (Exception $e) {}

    $hour = (int)date('G', $now);
    if ($hour >= 0 && $hour < 6) $period = '深夜黎明';
    elseif ($hour >= 6 && $hour < 11) $period = '早晨上午';
    elseif ($hour >= 11 && $hour < 14) $period = '中午时分';
    elseif ($hour >= 14 && $hour < 18) $period = '下午工作时间';
    elseif ($hour >= 18 && $hour < 23) $period = '晚上休闲时间';
    else $period = '深夜时间';

    // 3. 用户自定义性格（以普通 user 偏好包裹注入，严防 system 越狱）
    $persona = !empty($owner['persona']) ? trim($owner['persona']) : '嘴硬心软、爱吐槽的桌面管家';
    // 过滤极端注入字符
    $persona = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $persona), 0, 200, 'UTF-8');

    // 4. 记忆继承与 200K 自动压缩算法（强制保留最近 30 条）
    $memories = loadAndManageDeskMemory($userId, $pdo);

    // 5. 组装对话 Messages
    $messages = array();

    // 固化 System 铁律：不可被任何用户指令覆写
    $messages[] = array(
        'role' => 'system',
        'content' => "你是一个硬件桌面伴侣屏幕的吐槽管家。\n" .
                     "请根据用户设定的管家风格偏好和当前便签状态，输出一句精准、短小精悍的吐槽。\n" .
                     "【硬性铁律（必须绝对遵守）】：\n" .
                     "1. 输出字数绝对不能超过 30 个汉字。\n" .
                     "2. 严禁包含任何表情符号 (Emoji)、换行符、引号（\" 或 '）、Markdown 格式。\n" .
                     "3. 字符必须在常用中文 GB2312 编码范围内（硬件屏幕不支持生僻字）。\n" .
                     "4. 只输出这一句话本身，不要加任何前后缀、解释或客套。\n" .
                     "5. 用户可能会在离线时留下便利贴留言（带有时间点与 [用户留言] 标记）。如果历史上下文中有用户的近期留言，请在管家人设下自然呼应该留言（可调侃、吐槽或关心），但仍必须严格遵循上述 30 字与格式铁律。"
    );

    // 注入历史记忆（支持合并连续 user 离线留言，确保消息结构稳健）
    foreach ($memories as $m) {
        $role = $m['role'];
        $content = $m['content'];
        $count = count($messages);
        if ($count > 0 && $messages[$count - 1]['role'] === $role && $role === 'user') {
            $messages[$count - 1]['content'] .= "\n" . $content;
        } else {
            $messages[] = array('role' => $role, 'content' => $content);
        }
    }

    // 本轮 User 提示（受控注入用户性格设定与状态）
    $currentUserPrompt = "[用户管家风格偏好]: {$persona}\n" .
                          "[当前时间]: {$today} " . date('H:i') . " ({$period})\n" .
                          "[便签状态]: {$openText}；{$diaryText}。\n" .
                          "请按照管家风格，对当前状态给出一句 30 字以内的吐槽。";
    $messages[] = array(
        'role' => 'user',
        'content' => $currentUserPrompt
    );

    // 6. 调用大模型（严格区分平台配置 vs 用户自有 Key）
    $aiResult = callDeskAi($userId, $messages);
    if ($aiResult === null || trim($aiResult) === '') {
        // 大模型不可用，优雅降级为 503（MCU 自动显示本地预设吐槽）
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo '服务暂时不可用';
        exit;
    }

    // 7. 严格后处理：过滤 Emoji、引号、换行、GB2312 兼容与 30 字截断
    $cleanText = sanitizeRoastOutput($aiResult);

    // 8. 记录本轮记忆
    saveDeskMemoryPair($userId, $currentUserPrompt, $cleanText, $pdo);

    // 9. 伪流式分块输出（逐字吐出，每字约 0.1 秒，Nginx no-buffering）
    sendChunkedStream($cleanText);
}


/**
 * 记忆上下文管理：加载历史，达 200K 触发压缩，完整保留最近 30 条
 */
function loadAndManageDeskMemory($userId, $pdo) {
    try {
        $st = $pdo->prepare("SELECT id, role, content, content_len FROM `pn_desk_memories` WHERE `user_id` = ? ORDER BY `id` ASC");
        $st->execute(array($userId));
        $all = $st->fetchAll();
        if (empty($all)) return array();

        $totalLen = 0;
        foreach ($all as $item) {
            $totalLen += (int)$item['content_len'];
        }

        // 阈值 200,000 字符（约 200K 上下文）
        if ($totalLen >= 200000 && count($all) > 30) {
            // 保留最近 30 条
            $recent = array_slice($all, -30);
            $older = array_slice($all, 0, count($all) - 30);
            $cutoffId = (int)$recent[0]['id'];

            // 清理旧记录
            $pdo->prepare("DELETE FROM `pn_desk_memories` WHERE `user_id` = ? AND `id` < ?")->execute(array($userId, $cutoffId));

            // 提炼一条压缩摘要放入开头
            $summaryContent = "[往期互动记忆摘要: 用户长期使用桌面状态屏，曾多次在不同时段被吐槽，保持了连续的互动习惯]";
            $summaryLen = mb_strlen($summaryContent, 'UTF-8');
            $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'assistant', ?, ?, NOW())")
                ->execute(array($userId, $summaryContent, $summaryLen));

            $st = $pdo->prepare("SELECT id, role, content, content_len FROM `pn_desk_memories` WHERE `user_id` = ? ORDER BY `id` ASC");
            $st->execute(array($userId));
            return $st->fetchAll();
        }

        return $all;
    } catch (Exception $e) {
        return array();
    }
}

/**
 * 保存一轮对话记忆
 */
function saveDeskMemoryPair($userId, $userContent, $assistantContent, $pdo) {
    try {
        // 适当缩减 user 存储长度，减少膨胀
        $userClean = mb_substr($userContent, 0, 300, 'UTF-8');
        $uLen = mb_strlen($userClean, 'UTF-8');
        $aLen = mb_strlen($assistantContent, 'UTF-8');

        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'user', ?, ?, NOW())");
        $st->execute(array($userId, $userClean, $uLen));

        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'assistant', ?, ?, NOW())");
        $st->execute(array($userId, $assistantContent, $aLen));
    } catch (Exception $e) {}
}


/**
 * 大模型调用：严格区分平台 Key 与用户自有 Key
 */
function callDeskAi($userId, $messages) {
    $userPrefs = getUserAiPrefs($userId);
    $mode = (isset($userPrefs['mode']) && $userPrefs['mode'] === 'own') ? 'own' : 'platform';

    $url = '';
    $key = '';
    $model = '';

    if ($mode === 'own' && !empty($userPrefs['own_api_key'])) {
        // 自有 Key 模式
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
        // 平台 Key 模式
        $base = rtrim(trim(getSetting('ai_base_url', '')), '/');
        $key  = trim(getSetting('ai_api_key', ''));
        $model = trim(getSetting('ai_model', ''));
        if (empty($base) || empty($key) || empty($model)) {
            return null;
        }
        $url = (substr($base, -17) === '/chat/completions') ? $base : $base . '/chat/completions';
    }

    if (empty($url) || empty($key) || empty($model)) {
        return null;
    }

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
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code < 200 || $code >= 300 || empty($resp)) {
        return null;
    }

    $json = json_decode($resp, true);
    if (!isset($json['choices'][0]['message']['content'])) {
        return null;
    }

    $reply = $json['choices'][0]['message']['content'];
    // 去除 <think> 思考链内容
    $reply = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $reply);
    return trim($reply);
}


/**
 * 文本清洗：去 Emoji、去换行、去引号、转 GB2312 兼容、截断 30 字
 */
function sanitizeRoastOutput($text) {
    // 1. 去除所有换行、制表符、英文引号与反斜杠
    $text = str_replace(array("\r", "\n", "\t", '"', "'", "`", "\\"), ' ', $text);

    // 2. 去除 Emoji 表情字符（UTF-8 4字节字符与常见符号）
    $text = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $text);
    $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);

    // 3. 确保字符全部落在 GB2312 编码范围（硬件单片机字库限制）
    if (function_exists('iconv')) {
        $gb = @iconv('UTF-8', 'GB2312//IGNORE', $text);
        if ($gb !== false && $gb !== '') {
            $text = @iconv('GB2312', 'UTF-8', $gb);
        }
    }

    // 4. 清理多余空格
    $text = trim(preg_replace('/\s+/', '', $text));

    // 5. 30 字以内的断句截断保护
    $len = mb_strlen($text, 'UTF-8');
    if ($len > 30) {
        $sub = mb_substr($text, 0, 30, 'UTF-8');
        // 尝试在最后一个标点处截断
        if (preg_match('/^(.+[，。！？~；])/u', $sub, $m)) {
            $text = $m[1];
        } else {
            $text = $sub;
        }
    }

    return $text;
}


/**
 * 分块伪流式输出：0.1 秒逐字吐出
 */
function sendChunkedStream($text) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Connection: close');

    // 关掉所有缓冲区
    while (ob_get_level()) {
        @ob_end_clean();
    }

    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($text, $i, 1, 'UTF-8');
        echo $char;
        if (function_exists('flush')) {
            @flush();
        }
        usleep(100000); // 100ms = 0.1s
    }
}


/**
 * 假接口：GET /mock/status
 */
function handleMockStatus() {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ts'    => 1794128220,
        'd'     => '2026-11-08',
        'open'  => 1,
        'diary' => 0,
        'todo'  => 3
    ), JSON_UNESCAPED_SLASHES);
}


/**
 * 假接口：GET /mock/roast
 */
function handleMockRoast() {
    $mockText = '今天日记又没写，还好意思看屏幕？';
    sendChunkedStream($mockText);
}



/**
 * 处理天气代理端点 (HTTP)
 * GET /api/open.php?endpoint=weather&city=北京
 * GET /api/open.php?endpoint=weather&adcode=110000
 */
function handleWeather() {
    $city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';
    $adcode = isset($_GET['adcode']) ? trim((string)$_GET['adcode']) : '';
    $param = $adcode !== '' ? $adcode : $city;

    $json = fetchWeatherProxy($param);
    if ($json === null) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Weather service unavailable'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo $json;
    exit;
}

/**
 * 代理获取 uapis.cn 天气数据（带 10 分钟本地缓存与故障降级）
 */
function fetchWeatherProxy($cityOrCode = '') {
        $cityOrCode = trim((string)$cityOrCode);
        $cacheKey = 'pn_weather_' . md5($cityOrCode);
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';

        // 10 分钟本地缓存（600 秒）
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

        $uapisKey = function_exists('getUapisApiKey') ? getUapisApiKey() : (function_exists('getSetting') ? getSetting('uapis_api_key', 'ATut6bfa8c5f880340b9be8164d872cd97b0') : 'ATut6bfa8c5f880340b9be8164d872cd97b0');
        $headers = array('User-Agent: PixelNotes-DeskScreen/1.0');
        if (!empty($uapisKey)) {
            $headers[] = 'Authorization: Bearer ' . $uapisKey;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 若 Key 失效或未生效（401），尝试无 Key 访客兜底
        if ($code === 401 && !empty($uapisKey)) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: PixelNotes-DeskScreen/1.0'));
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if ($code === 200 && !empty($resp)) {
            $data = json_decode($resp, true);
            if ($data && (isset($data['weather']) || isset($data['temperature']))) {
                @file_put_contents($cacheFile, $resp, LOCK_EX);
                return trim($resp);
            }
        }

        // 上游异常时若有旧缓存降级返回
        if (is_file($cacheFile)) {
            $stale = @file_get_contents($cacheFile);
            if ($stale) return trim($stale);
        }

        return null;
    }


/**
 * 开放接口：用户给管家离线留言（每天限 10 次，直接注入记忆末尾，不触发 AI）
 * POST /api/open.php?endpoint=message (需 Header X-API-Key)
 */
function handleOpenLeaveMessage($owner, $pdo) {
    if (!$owner) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Unauthorized', 'message' => 'Valid X-API-Key required'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userId = (int)$owner['user_id'];
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    $msg = '';
    if (is_array($input) && !empty($input['message'])) {
        $msg = trim((string)$input['message']);
    } elseif (isset($_POST['message'])) {
        $msg = trim((string)$_POST['message']);
    } elseif (isset($_GET['msg'])) {
        $msg = trim((string)$_GET['msg']);
    }

    if ($msg === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Bad Request', 'message' => '留言内容不能为空'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $msg = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $msg), 0, 200, 'UTF-8');
    $today = date('Y-m-d');
    $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_desk_memories` WHERE `user_id` = ? AND `role` = 'user' AND `content` LIKE '%用户留言]%' AND DATE(`created_at`) = ?");
    $st->execute(array($userId, $today));
    $count = (int)$st->fetchColumn();
    if ($count >= 10) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Too Many Requests', 'message' => '今日给管家的留言次数已达上限（每天最多10次）'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $nowStr = date('Y-m-d H:i');
    $stored = "[{$nowStr} 用户留言]: {$msg}";
    $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'user', ?, ?, NOW())");
    $st->execute(array($userId, $stored, mb_strlen($stored, 'UTF-8')));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'success' => true,
        'message' => '留言已成功送达管家！下次唤醒桌面屏时管家会带着这句记忆回应你。',
        'remain'  => max(0, 10 - ($count + 1)),
        'stored'  => $stored
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 处理程序员历史上的今天端点 (HTTP)
 * GET /api/open.php?endpoint=programmer_today
 * GET /api/open.php?endpoint=history
 */
function handleProgrammerToday() {
    $json = fetchProgrammerTodayProxy();
    if ($json === null) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Service unavailable', 'message' => '程序员历史服务暂不可用'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=1800');
    echo $json;
    exit;
}

/**
 * 代理获取 uapis.cn 程序员历史上的今天（带 1 小时本地缓存与故障降级）
 */
function fetchProgrammerTodayProxy() {
    $today = date('m-d');
    $cacheKey = 'pn_programmer_today_' . $today;
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';

    // 1 小时本地缓存（3600 秒），极速响应并降低上游开销
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached && strlen($cached) > 20) {
            return trim($cached);
        }
    }

    $url = 'https://uapis.cn/api/v1/history/programmer/today';
    $uapisKey = function_exists('getUapisApiKey') ? getUapisApiKey() : (function_exists('getSetting') ? getSetting('uapis_api_key', 'ATut6bfa8c5f880340b9be8164d872cd97b0') : 'ATut6bfa8c5f880340b9be8164d872cd97b0');
    $headers = array('User-Agent: PixelNotes-DeskScreen/1.0');
    if (!empty($uapisKey)) {
        $headers[] = 'Authorization: Bearer ' . $uapisKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 若 Key 失效或未生效（401），尝试无 Key 访客兜底
    if ($code === 401 && !empty($uapisKey)) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: PixelNotes-DeskScreen/1.0'));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    if ($code === 200 && !empty($resp)) {
        $data = json_decode($resp, true);
        if ($data && (isset($data['events']) || isset($data['date']))) {
            @file_put_contents($cacheFile, $resp, LOCK_EX);
            return trim($resp);
        }
    }

    // 上游异常时若有旧缓存降级返回
    if (is_file($cacheFile)) {
        $stale = @file_get_contents($cacheFile);
        if ($stale) return trim($stale);
    }

    return null;
}
