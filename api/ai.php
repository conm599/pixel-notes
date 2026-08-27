<?php
/**
 * AI 编辑代理 API（OpenAI 兼容接口）
 * action=edit   : AI 编辑便签（平台密钥 / 用户自有 Key 双模式）
 * action=test   : 管理员测试上游连通性
 * action=prefs  : 读取/保存用户 AI 偏好（跨端同步，用户主动勾选）
 *
 * 安全设计：
 * - 管理员的上游 Key 存于 pn_settings，永不下发浏览器
 * - 用户自有 Key 默认只存浏览器 localStorage，每次请求由前端带上、内存转发不落盘
 * - 自有 Key 模式经管理员配置的透明代理转发（管理页「AI 设置」），未配置时直连目标，无硬编码
 * - 平台密钥按北京时间每日 8:00 重置配额；自有 Key 模式每用户每日 100 次防刷
 */

require_once __DIR__ . '/../config/database.php';

startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sendSecurityHeaders();

ini_set('display_errors', '0');
error_reporting(E_ALL);
if (function_exists('set_time_limit')) @set_time_limit(150);

define('AI_MAX_CONTENT', 30000);
define('AI_MAX_INSTRUCTION', 2000);
define('AI_POLICY_VERSION', 1);
define('AI_OWN_DAILY_LIMIT', 500);

function jsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
    exit;
}

/**
 * 归一化用户填写的 base_url：无协议补 https://，返回完整 chat/completions 地址
 */
function ownEndpoint($baseUrl) {
    $base = trim((string)$baseUrl);
    if ($base === '') return '';
    if (stripos($base, '://') === false) $base = 'https://' . $base;
    // 只接受 http/https
    if (stripos($base, 'https://') !== 0 && stripos($base, 'http://') !== 0) return '';
    $base = rtrim($base, '/');
    if (substr($base, -17) === '/chat/completions') return $base;
    return $base . '/chat/completions';
}

/**
 * 调用 OpenAI 兼容 chat/completions（url/key/model 由调用方指定）
 */
function aiChat($url, $key, $model, $messages, $maxTokens = 8000) {
    $payload = json_encode(array(
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.4,
    ), defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);

    $body = false; $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 130,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $body = curl_exec($ch);
        if ($body !== false) $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n",
            'content' => $payload,
            'timeout' => 130,
            'ignore_errors' => true,
        )));
        $body = @file_get_contents($url, false, $ctx);
        $status = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
            }
        }
    }

    if ($body === false) return array('ok' => false, 'text' => '', 'err' => '无法连接 AI 接口');
    $bodyStr = (string)$body;
    $json = json_decode($bodyStr, true);
    if (!is_array($json)) {
        return array('ok' => false, 'text' => '', 'err' => 'AI 接口返回了无法解析的内容：' . mb_substr($bodyStr, 0, 150, 'UTF-8'));
    }

    if ($status !== 200) {
        $msg = '';
        if (isset($json['error']['message'])) $msg = (string)$json['error']['message'];
        elseif (isset($json['error'])) $msg = json_encode($json['error']);
        elseif (isset($json['message'])) $msg = (string)$json['message'];
        if ($msg === '') $msg = 'HTTP ' . $status;
        return array('ok' => false, 'text' => '', 'err' => '上游错误：' . mb_substr($msg, 0, 200, 'UTF-8'));
    }

    $msgObj = isset($json['choices'][0]['message']) && is_array($json['choices'][0]['message']) ? $json['choices'][0]['message'] : array();
    $text = isset($msgObj['content']) ? trim((string)$msgObj['content']) : '';
    $reasoning = isset($msgObj['reasoning_content']) ? trim((string)$msgObj['reasoning_content']) : '';
    if ($text === '' && $reasoning !== '') {
        return array('ok' => false, 'text' => '', 'err' => '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens');
    }
    if ($text === '' && isset($json['choices'][0]['text'])) $text = trim((string)$json['choices'][0]['text']);
    if ($text === '') {
        return array('ok' => false, 'text' => '', 'err' => 'AI 返回了空内容，原始响应：' . mb_substr($bodyStr, 0, 200, 'UTF-8'));
    }
    return array('ok' => true, 'text' => $text, 'err' => '');
}

/**
 * 按密钥记录重置过期计数并检查配额，返回 array(ok, err, usage)
 */
function checkKeyQuota($row) {
    $period = aiPeriodNow();
    $used = (int)$row['used'];
    $limit = (int)$row['daily_limit'];
    if ($row['period'] !== $period) $used = 0;
    if ($limit > 0 && $used >= $limit) {
        return array('ok' => false, 'err' => '今日配额已用完（' . $used . '/' . $limit . '，北京时间 8:00 重置）', 'usage' => array('used' => $used, 'limit' => $limit));
    }
    return array('ok' => true, 'err' => '', 'usage' => array('used' => $used, 'limit' => $limit));
}

function bumpKeyUsage($keyId, $period, $used) {
    try {
        $pdo = getDB();
        $st = $pdo->prepare("UPDATE pn_ai_keys SET used = ?, period = ? WHERE id = ?");
        $st->execute(array($used + 1, $period, (int)$keyId));
    } catch (Exception $e) { /* 计数失败不阻断 */ }
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonOut(array('success' => false, 'message' => '请先登录'), 401);
    }
    $uid = (int)$_SESSION['user_id'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = array();
    $action = isset($input['action']) ? (string)$input['action'] : '';

    // ================= 管理员测试上游 =================
    if ($action === 'test') {
        if (!isAdminUser()) jsonOut(array('success' => false, 'message' => '需要管理员权限'));
        $base = rtrim(trim(getSetting('ai_base_url', '')), '/');
        $key = trim(getSetting('ai_api_key', ''));
        $model = trim(getSetting('ai_model', ''));
        if ($base === '' || $key === '' || $model === '') {
            jsonOut(array('success' => false, 'message' => 'AI 未配置（请先填写接口地址、Key、模型名）'));
        }
        if (substr($base, -17) !== '/chat/completions') $base .= '/chat/completions';
        $r = aiChat($base, $key, $model, array(
            array('role' => 'user', 'content' => '请回复两个字：正常'),
        ), 512);
        if ($r['ok']) {
            jsonOut(array('success' => true, 'message' => '连接成功，AI 回复：' . mb_substr($r['text'], 0, 50, 'UTF-8')));
        }
        jsonOut(array('success' => false, 'message' => $r['err']));
    }

    // ================= 用户偏好：读取 / 保存 =================
    if ($action === 'prefs') {
        $op = isset($input['op']) ? (string)$input['op'] : 'get';
        $pdo = getDB();
        if ($op === 'clear') {
            // 取消同步：删除服务器上存储的偏好（含自有 Key）
            try {
                $pdo->prepare("DELETE FROM pn_user_ai_prefs WHERE user_id = ?")->execute(array($uid));
            } catch (Exception $e) { /* 忽略 */ }
            jsonOut(array('success' => true, 'cleared' => true));
        }
        if ($op === 'save') {
            $fields = array();
            $fields['mode'] = (isset($input['mode']) && $input['mode'] === 'own') ? 'own' : 'platform';
            $fields['platform_key'] = isset($input['platform_key']) ? substr(trim((string)$input['platform_key']), 0, 64) : '';
            $fields['own_base_url'] = isset($input['own_base_url']) ? substr(trim((string)$input['own_base_url']), 0, 255) : '';
            $fields['own_api_key'] = isset($input['own_api_key']) ? substr(trim((string)$input['own_api_key']), 0, 255) : '';
            $fields['own_model'] = isset($input['own_model']) ? substr(trim((string)$input['own_model']), 0, 100) : '';
            $proxy = isset($input['own_proxy']) ? trim((string)$input['own_proxy']) : '';
            if ($proxy !== '' && !preg_match('#^https://#i', $proxy)) $proxy = '';
            $fields['own_proxy'] = substr($proxy, 0, 255);
            $fields['send_time'] = !empty($input['send_time']) ? 1 : 0;
            $fields['style'] = isset($input['style']) ? substr(trim((string)$input['style']), 0, 500) : '';
            if (!empty($input['policy_agreed'])) $fields['policy_version'] = AI_POLICY_VERSION;
            saveUserAiPrefs($uid, $fields);
        }
        // 返回：服务器同步的偏好 + 平台密钥状态
        $prefs = getUserAiPrefs($uid);
        $assigned = null;
        try {
            $st = $pdo->prepare("SELECT akey, remark, daily_limit, used, period, enabled FROM pn_ai_keys
                                 WHERE user_id = ? AND enabled = 1 ORDER BY id ASC LIMIT 1");
            $st->execute(array($uid));
            $row = $st->fetch();
            if ($row) {
                $period = aiPeriodNow();
                $used = $row['period'] === $period ? (int)$row['used'] : 0;
                $assigned = array(
                    'remark' => $row['remark'],
                    'daily_limit' => (int)$row['daily_limit'],
                    'used' => $used,
                );
            }
        } catch (Exception $e) { /* 表未就绪 */ }
        jsonOut(array(
            'success' => true,
            'prefs' => $prefs,
            'assigned_key' => $assigned,
            'is_admin' => isAdminUser(),
            'policy_version' => AI_POLICY_VERSION,
        ));
    }

    // ================= 查询平台密钥用量（不消耗配额） =================
    if ($action === 'usage') {
        $pdo = getDB();
        $qkey = isset($input['key']) ? trim((string)$input['key']) : '';
        if ($qkey === '') {
            $row = getUserAiPrefs($uid);
            if ($row) $qkey = trim((string)$row['platform_key']);
        }
        if ($qkey === '') jsonOut(array('success' => false, 'message' => '没有可查询的密钥'));
        try {
            $st = $pdo->prepare("SELECT remark, daily_limit, used, period, enabled FROM pn_ai_keys
                                 WHERE akey = ? AND (user_id = 0 OR user_id = ?) LIMIT 1");
            $st->execute(array($qkey, $uid));
            $row = $st->fetch();
            if (!$row || !(int)$row['enabled']) {
                jsonOut(array('success' => false, 'message' => '密钥无效（不存在、已禁用或不是分配给你的）'));
            }
            $period = aiPeriodNow();
            $used = $row['period'] === $period ? (int)$row['used'] : 0;
            jsonOut(array('success' => true, 'usage' => array(
                'used' => $used,
                'limit' => (int)$row['daily_limit'],
                'remark' => (string)$row['remark'],
            )));
        } catch (Exception $e) {
            jsonOut(array('success' => false, 'message' => '查询失败'));
        }
    }

    // ================= AI 编辑便签 =================
    if ($action === 'edit') {
        $now = microtime(true);
        $last = isset($_SESSION['ai_last']) ? (float)$_SESSION['ai_last'] : 0.0;
        if ($now - $last < 3) {
            jsonOut(array('success' => false, 'message' => '请求太频繁，请稍候再试'));
        }

        // 政策同意校验（前端必须带最新版本号，防绕过）
        $policyV = isset($input['policyVersion']) ? (int)$input['policyVersion'] : 0;
        if ($policyV < AI_POLICY_VERSION) {
            jsonOut(array('success' => false, 'need_policy' => true, 'policy_version' => AI_POLICY_VERSION,
                          'message' => '请先阅读并同意 AI 使用政策'));
        }

        $title = isset($input['title']) ? trim((string)$input['title']) : '';
        $content = isset($input['content']) ? (string)$input['content'] : '';
        $instruction = isset($input['instruction']) ? trim((string)$input['instruction']) : '';
        $prefs = isset($input['prefs']) && is_array($input['prefs']) ? $input['prefs'] : array();
        $style = isset($prefs['style']) ? trim((string)$prefs['style']) : '';
        // 时间感知：前端传用户浏览器当前时间（服务器不做任何时区假设）
        $timeStr = isset($prefs['time']) ? trim((string)$prefs['time']) : '';
        if ($timeStr !== '') {
            $timeStr = preg_replace('/[\x00-\x1F\x7F]/', '', $timeStr);
            if (function_exists('mb_substr')) $timeStr = mb_substr($timeStr, 0, 60, 'UTF-8');
            else $timeStr = substr($timeStr, 0, 60);
        }

        if ($instruction === '') jsonOut(array('success' => false, 'message' => '请描述你想让 AI 做什么'));
        $ilen = function_exists('mb_strlen') ? mb_strlen($instruction, 'UTF-8') : strlen($instruction);
        $clen = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
        if ($ilen > AI_MAX_INSTRUCTION) {
            jsonOut(array('success' => false, 'message' => '指令太长（最多' . AI_MAX_INSTRUCTION . '字）'));
        }
        if ($clen > AI_MAX_CONTENT) {
            jsonOut(array('success' => false, 'message' => '便签内容太长，AI 无法处理'));
        }
        if ($title === '' && trim($content) === '' && strpos($instruction, '写') === false && strpos($instruction, '生成') === false) {
            jsonOut(array('success' => false, 'message' => '便签是空的，请先写点内容，或让 AI「生成/写」点东西'));
        }

        // ===== 选择上游：平台密钥 or 用户自有 Key =====
        $mode = (isset($prefs['mode']) && $prefs['mode'] === 'own') ? 'own' : 'platform';
        $pdo = getDB();
        $usage = null;
        $keyRow = null;

        if ($mode === 'platform') {
            $isAdmin = isAdminUser();
            $base = rtrim(trim(getSetting('ai_base_url', '')), '/');
            $key  = trim(getSetting('ai_api_key', ''));
            $model = trim(getSetting('ai_model', ''));
            if ($base === '' || $key === '' || $model === '') {
                jsonOut(array('success' => false, 'message' => '平台 AI 上游尚未配置，请联系管理员'));
            }
            $url = (substr($base, -17) === '/chat/completions') ? $base : $base . '/chat/completions';

            if ($isAdmin) {
                // 管理员：直接用自己配置的上游，无需密钥、不限量
                $mode = 'admin';
            } else {
                // 普通用户：优先当前账号绑定的密钥；其次手动输入的密钥（不绑定或绑定给本人）
                $st = $pdo->prepare("SELECT * FROM pn_ai_keys WHERE enabled = 1 AND user_id = ? ORDER BY id ASC LIMIT 1");
                $st->execute(array($uid));
                $keyRow = $st->fetch();
                $inputKey = isset($prefs['platformKey']) ? trim((string)$prefs['platformKey']) : '';
                if (!$keyRow && $inputKey !== '') {
                    $st = $pdo->prepare("SELECT * FROM pn_ai_keys WHERE akey = ? AND enabled = 1 AND (user_id = 0 OR user_id = ?) LIMIT 1");
                    $st->execute(array($inputKey, $uid));
                    $keyRow = $st->fetch();
                    if (!$keyRow) jsonOut(array('success' => false, 'message' => '平台密钥无效（不存在、已被禁用或不是分配给你的）'));
                }
                if (!$keyRow) {
                    jsonOut(array('success' => false, 'message' => '管理员还没有为你分配 AI 密钥，也没有填写有效的平台密钥。可在「AI 设置」里改用自己的 API Key'));
                }
                $q = checkKeyQuota($keyRow);
                if (!$q['ok']) jsonOut(array('success' => false, 'message' => $q['err'], 'usage' => $q['usage']));
                $usage = $q['usage'];
            }
        } else {
            // 自有 Key 模式：经 CF Worker 透明代理转发，无 SSRF 风险
            $target = ownEndpoint(isset($prefs['ownBaseUrl']) ? $prefs['ownBaseUrl'] : '');
            $key  = isset($prefs['ownApiKey']) ? trim((string)$prefs['ownApiKey']) : '';
            $model = isset($prefs['ownModel']) ? trim((string)$prefs['ownModel']) : '';
            if ($target === '' || $key === '' || $model === '') {
                jsonOut(array('success' => false, 'message' => '自有 Key 模式需要在「AI 设置」里填写接口地址、API Key 和模型名'));
            }

            // 自有模式防刷限流：每用户每日（北京时间 8 点周期）100 次
            $period = aiPeriodNow();
            $row = getUserAiPrefs($uid);
            $ownUsed = ($row && $row['own_period'] === $period) ? (int)$row['own_used'] : 0;
            if ($ownUsed >= AI_OWN_DAILY_LIMIT) {
                jsonOut(array('success' => false, 'message' => '今日自有 Key 调用次数已达上限（' . AI_OWN_DAILY_LIMIT . '，北京时间 8:00 重置）',
                              'usage' => array('used' => $ownUsed, 'limit' => AI_OWN_DAILY_LIMIT)));
            }
            $usage = array('used' => $ownUsed, 'limit' => AI_OWN_DAILY_LIMIT);

            // 透明代理前缀由管理员在管理页配置（存 pn_settings）；未配置则服务器直连目标
            $proxyPrefix = rtrim(trim(getSetting('ai_own_proxy', '')), '/');
            $url = ($proxyPrefix !== '') ? $proxyPrefix . '/' . $target : $target;
        }

        $system = "你是一个便签编辑代理。用户会给你一篇 Markdown 便签（可能为空）和一条编辑指令，你要精准地完成编辑。\n"
            . "【输出格式（二选一）】\n"
            . "A. 局部修改（默认首选）：只改动需要改的地方。每个改动输出一个替换块，格式严格如下：\n"
            . "<<<SEARCH>>>\n"
            . "（便签原文中要被修改的那段文字，必须与原文逐字一致，包括空格、换行、标点）\n"
            . "<<<REPLACE>>>\n"
            . "（修改后的文字）\n"
            . "<<<END>>>\n"
            . "可以有多个替换块，按顺序排列。SEARCH 段尽量短且在全文中唯一。\n"
            . "B. 全文重写：仅当指令要求整体重构、全文翻译、全文总结、从零创作时，才直接输出完整的新便签全文。\n"
            . "【硬性规则】\n"
            . "1. 绝对禁止删除、改写、移动用户已有的链接、URL、HTML 标签、图片/音频/视频/iframe 嵌入和代码块，除非指令明确要求处理它们\n"
            . "2. 用户没让改的部分必须一字不动，只做最小限度的必要修改，禁止顺手润色或重排\n"
            . "3. 不要输出任何解释、前言、结束语，不要用代码围栏（```）包裹整个输出\n"
            . "4. 保持 Markdown 格式；便签支持：标题/加粗/斜体/列表/引用/链接/图片/任务列表/代码块\n"
            . "5. 便签标题不在你负责范围内，只编辑正文\n"
            . "6. 便签内容为空且指令是创作类时，用 B 格式直接创作";
        if ($style !== '') {
            $system .= "\n【用户风格偏好】在不违背上述硬性规则的前提下，尽量按以下风格完成编辑：" . $style;
        }
        if ($timeStr !== '') {
            $system .= "\n【当前时间】现在是 " . $timeStr . "（用户本地时间）。涉及时间、日期、星期、节假日等内容的编辑请以此为准，不要虚构时间。";
        }

        $userMsg = "【便签标题】" . ($title !== '' ? $title : '(无标题)') . "\n"
            . "【当前便签内容】\n" . ($content !== '' ? $content : '(空便签)') . "\n\n"
            . "【编辑指令】" . $instruction;

        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $userMsg),
        );

        // 自纠错循环：SEARCH 块匹配失败时，带上上下文告诉 AI 哪里错了，最多 3 轮
        $maxAttempts = 3;
        $lastErrText = '';
        $result = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $r = aiChat($url, $key, $model, $messages);

            if (!$r['ok']) {
                jsonOut(array('success' => false, 'message' => $r['err'], 'usage' => $usage));
            }

            $_SESSION['ai_last'] = $now;

            // 清理模型常见的围栏包裹
            $text = trim($r['text']);
            if (preg_match('/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i', $text, $m)) {
                $text = trim($m[1]);
            }
            if ($text === '') {
                $lastErrText = 'AI 返回了空内容';
                if ($attempt < $maxAttempts) {
                    $messages[] = array('role' => 'assistant', 'content' => '（上一轮返回为空）');
                    $messages[] = array('role' => 'user', 'content' => '你上一轮返回了空内容，请重新按格式输出编辑结果。');
                    continue;
                }
                jsonOut(array('success' => false, 'message' => $lastErrText, 'usage' => $usage));
            }

            // ===== 局部修改协议：解析 <<<SEARCH>>>/<<<REPLACE>>>/<<<END>>> 块 =====
            $contentN = str_replace("\r\n", "\n", $content);
            if (preg_match_all('/<<<SEARCH>>>\s*\n([\s\S]*?)\n?<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/i', $text, $mm, PREG_SET_ORDER)) {
                $applied = 0; $failed = 0;
                $newContent = $contentN;
                $badSearches = array();
                foreach ($mm as $b) {
                    $search = str_replace("\r\n", "\n", rtrim($b[1], "\n"));
                    $replace = str_replace("\r\n", "\n", rtrim($b[2], "\n"));
                    if ($search !== '' && strpos($newContent, $search) !== false) {
                        $newContent = substr_replace($newContent, $replace, strpos($newContent, $search), strlen($search));
                        $applied++;
                    } else {
                        $failed++;
                        $badSearches[] = $search;
                    }
                }
                if ($applied > 0) {
                    $result = array(
                        'success' => true,
                        'mode'    => 'edits',
                        'applied' => $applied,
                        'failed'  => $failed,
                        'content' => $newContent,
                        'usage'   => $usage,
                        'attempts' => $attempt,
                    );
                    break;
                }
                // 全部匹配失败：带上下文重试
                $lastErrText = 'AI 指出的修改位置无法在原文中匹配（模型复述原文有误）';
                if ($attempt < $maxAttempts) {
                    $feedback = "你上一轮输出的替换块全部无法匹配原文（共 " . count($badSearches) . " 个）。"
                        . "SEARCH 段必须从【当前便签内容】中逐字精确复制（包括空格、换行、标点、Markdown 符号），禁止凭记忆复述。";
                    if (!empty($badSearches)) {
                        $feedback .= "你上轮的 SEARCH 段开头分别是：" . implode(' / ', array_map(function ($s) {
                            $t = trim(preg_replace('/\s+/', ' ', $s));
                            return function_exists('mb_substr') ? '「' . mb_substr($t, 0, 20, 'UTF-8') . '…」' : '「' . substr($t, 0, 30) . '…」';
                        }, array_slice($badSearches, 0, 3)));
                    }
                    $feedback .= "请重新输出替换块完成原指令：" . $instruction;
                    $messages[] = array('role' => 'assistant', 'content' => $text);
                    $messages[] = array('role' => 'user', 'content' => $feedback);
                    continue;
                }
                jsonOut(array('success' => false, 'message' => $lastErrText . '，已自动重试 ' . $maxAttempts . ' 轮仍失败，请重试或换个说法', 'usage' => $usage));
            }

            // ===== 全文重写模式 =====
            $result = array('success' => true, 'mode' => 'full', 'content' => $text, 'usage' => $usage, 'attempts' => $attempt);
            break;
        }

        if ($result === null) {
            jsonOut(array('success' => false, 'message' => $lastErrText !== '' ? $lastErrText : 'AI 编辑失败', 'usage' => $usage));
        }

        // 成功后才计数
        if ($mode === 'platform' && $keyRow) {
            $period = aiPeriodNow();
            $used = $keyRow['period'] === $period ? (int)$keyRow['used'] : 0;
            bumpKeyUsage($keyRow['id'], $period, $used);
        } elseif ($mode === 'own') {
            try {
                $pdo->exec("INSERT INTO pn_user_ai_prefs (user_id, own_used, own_period, updated_at)
                            VALUES (" . $uid . ", 1, '" . $period . "', NOW())
                            ON DUPLICATE KEY UPDATE own_used = " . ($ownUsed + 1) . ", own_period = '" . $period . "', updated_at = NOW()");
            } catch (Exception $e) { /* 计数失败不阻断 */ }
        }
        if ($usage) $usage['used'] = $usage['used'] + 1;

        jsonOut($result);
    }

    jsonOut(array('success' => false, 'message' => '未知操作'));

} catch (Exception $e) {
    jsonOut(array('success' => false, 'message' => '服务器内部错误'));
}
