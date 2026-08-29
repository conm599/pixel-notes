<?php
/**
 * AI 编辑代理 API（OpenAI 兼容接口）
 * action=edit   : AI 编辑便签（平台密钥 / 用户自有 Key 双模式）
 * action=test   : 管理员测试上游连通性
 * action=prefs  : 读取/保存用户 AI 偏好（跨端同步，用户主动勾选）
 *
 * 实现以 protocol.md v8 为准（分段参数 / prompt 模板 / 纠错话术 / 澄清提问 / TOOL 工具块 / 整理 Agent SSE 的唯一事实源），改动需与 js/ai-direct.js 同步
 *
 * 安全设计：
 * - 管理员的上游 Key 存于 pn_settings，永不下发浏览器
 * - 用户自有 Key 默认只存浏览器 localStorage，每次请求由前端带上、内存转发不落盘
 * - 自有 Key 模式强制经管理员配置的透明代理转发（管理页「AI 设置」），未配置则拒绝该模式，服务器永不直连用户目标
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
// 长文分段 agent：超过阈值则切块逐段处理（模型输入 512K 不是瓶颈，瓶颈在单次输出与响应时间）
define('AI_CHUNK_THRESHOLD', 4500);
define('AI_CHUNK_SIZE', 3000);

/**
 * 长文切块：优先在段落/换行边界切，块为原文的精确子串（保证后续能在全文中定位替换）
 */
function aiChunkText($text) {
    $chunks = array();
    $mb = function_exists('mb_strlen');
    $len = $mb ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len <= AI_CHUNK_SIZE) return array($text);
    $start = 0; $lastBreak = 0;
    for ($i = 0; $i < $len; $i++) {
        if ($i - $start >= AI_CHUNK_SIZE) {
            $cut = ($lastBreak > $start) ? $lastBreak : $i;
            $chunks[] = $mb ? mb_substr($text, $start, $cut - $start, 'UTF-8') : substr($text, $start, $cut - $start);
            $start = $cut;
            $lastBreak = $start;
        }
        $ch = $mb ? mb_substr($text, $i, 1, 'UTF-8') : $text[$i];
        if ($ch === "\n") $lastBreak = $i + 1;
    }
    if ($start < $len) $chunks[] = $mb ? mb_substr($text, $start, $len - $start, 'UTF-8') : substr($text, $start);
    return $chunks;
}
define('AI_POLICY_VERSION', 1);
define('AI_OWN_DAILY_LIMIT', 500);
// 澄清轮数不设硬上限：每轮都需用户手动回答才会继续，人工熔断天然存在；仅保留防滥用截断（见输入解析）
define('AI_CLARIFY_INPUT_MAX', 10);
define('AI_CLARIFY_MAX_QUESTIONS', 3);

/**
 * 解析 AI 输出中的澄清提问块 <<<CLARIFY>>>...<<<END>>>，返回问题数组（无则空数组）
 */
function aiParseClarify($text) {
    $questions = array();
    if (is_string($text) && preg_match('/<<<CLARIFY>>>\s*\n([\s\S]*?)\n?<<<END>>>/i', $text, $m)) {
        foreach (explode("\n", trim($m[1])) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = preg_replace('/^\s*[\d\-*.#)・•]+[.\s]*/', '', $line); // 去列表序号
            $line = trim($line);
            if ($line !== '' && mb_strlen($line, 'UTF-8') > 200) $line = mb_substr($line, 0, 200, 'UTF-8');
            if (count($questions) >= AI_CLARIFY_MAX_QUESTIONS) break;
            if ($line !== '') $questions[] = $line;
        }
    }
    return $questions;
}

function jsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
    exit;
}

// ===== SSE 流式输出（protocol v5）：delta=文本片段 phase=阶段进度 done=最终结果 =====
$AI_SSE = false;
function sseStart() {
    global $AI_SSE;
    $AI_SSE = true;
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    // 关键：立即释放 session 文件锁。SSE 流会持续几十秒~几分钟，
    // 不释放会让同一账号的所有其他请求全部阻塞在 session_start() 上（同账号全站卡死，他账号不受影响）
    session_write_close();
}
function sseSend($event, $data) {
    echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0) . "\n\n";
    if (ob_get_level() > 0) @ob_flush();
    flush();
}
/**
 * 统一出口：SSE 模式发 done 事件后退出，非 SSE 模式退回普通 JSON（预检失败路径仍走 JSON）
 */
function aiOut($payload) {
    global $AI_SSE;
    if (!empty($AI_SSE)) { sseSend('done', $payload); exit; }
    jsonOut($payload);
}

/**
 * 输出净化（protocol v4）：全文重写 / 整段重写路径专用。
 * 删除全部协议标记串（标记本身删除、内容保留）后 trim，根治「全文混入孤儿标记」。
 * 必须在澄清解析与替换块提取之后使用，不得提前。
 */
function aiCleanOutput($text) {
    $t = preg_replace('/<<<(?:SEARCH|REPLACE|END|CLARIFY)>>>/i', '', (string)$text);
    // 剥推理模型的思考标签（<think>...</think>、<thinking>...</thinking>），含未闭合的残留头
    $t = preg_replace('/<(?:think|thinking)>[\s\S]*?(?:<\/(?:think|thinking)>|$)/i', '', $t);
    return trim((string)$t);
}

/**
 * 宽容匹配辅助：空白集为 [ \t\r\n\f\v　]（ASCII 空白 + 全角空格），与 js/ai-direct.js 逐字一致
 */
function aiFoldWs($s) {
    return preg_replace('/[ \t\r\n\f\v\x{3000}]+/u', ' ', (string)$s);
}

function aiRtrimLine($s) {
    return preg_replace('/[ \t\x{3000}]+$/u', '', (string)$s);
}

/**
 * 区间重叠判定（块隔离用）：[s1,e1) 与 [s2,e2) 是否相交
 */
function aiRangeOverlap($a, $b) {
    return $a[0] < $b[1] && $b[0] < $a[1];
}

/**
 * 三级宽容匹配替换（protocol v8，与 js/ai-direct.js matchAndApply 逐字一致）：
 * 1. 精确子串匹配；2. 行尾空白归一匹配；3. 全空白折叠归一匹配。
 * v8 加固：① 三级全部要求「全文唯一有效命中」才应用（精确级不再取首次出现；
 *   多处出现视为歧义，与第 2/3 级同标准，杜绝重复句式文本中替换错位置）；
 * ② 块隔离——$doneRanges 是前序块 REPLACE 已覆盖的字符区间（相对 $content），
 *   命中位置与之相交即无效（后块不得改写前块刚输出的新文本）。
 * 第 2/3 级按行滑窗，内层归一化长度超过 SEARCH 归一化长度即提前终止（随行数单调不减）。
 * 成功返回 array('c'=>新内容, 'old'=>array(旧区间), 'new'=>array(新区间))，失败返回 null。
 */
function aiApplyBlock($content, $search, $replace, $doneRanges = array()) {
    if (!is_string($content) || $search === '') return null;
    // 精确级：收集全部出现位置，过滤掉与已替换区间相交的，唯一才应用
    $pos = 0;
    $exact = array();
    while (($p = strpos($content, $search, $pos)) !== false) {
        $exact[] = $p;
        $pos = $p + 1;
    }
    $valid = array();
    foreach ($exact as $p) {
        $rng = array($p, $p + strlen($search));
        $blocked = false;
        foreach ($doneRanges as $dr) { if (aiRangeOverlap($rng, $dr)) { $blocked = true; break; } }
        if (!$blocked) $valid[] = $rng;
    }
    if (count($valid) === 1) {
        list($a, $b) = $valid[0];
        $newC = substr_replace($content, $replace, $a, $b - $a);
        return array('c' => $newC, 'old' => array($a, $b), 'new' => array($a, $a + strlen($replace)));
    }
    $lines = explode("\n", $content);
    // 行号 → 字符偏移前缀和（滑窗命中换算回字符区间，用于块隔离过滤）
    $lineOff = array();
    $off = 0;
    foreach ($lines as $li => $ln) { $lineOff[$li] = $off; $off += strlen($ln) + 1; }
    $keyFns = array(
        function ($s) { return implode("\n", array_map('aiRtrimLine', explode("\n", $s))); },
        function ($s) { return trim(aiFoldWs($s), ' '); },
    );
    foreach ($keyFns as $kf) {
        $needleKey = $kf($search);
        if ($needleKey === '') continue;
        $hits = array();
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $acc = '';
            for ($j = $i; $j < $n; $j++) {
                $acc .= ($j > $i ? "\n" : '') . $lines[$j];
                $k = $kf($acc);
                if ($k === $needleKey) {
                    // 命中区间换算成字符偏移，与已替换区间相交则无效
                    $rng = array($lineOff[$i], $j + 1 < $n ? $lineOff[$j + 1] : strlen($content));
                    $blocked = false;
                    foreach ($doneRanges as $dr) { if (aiRangeOverlap($rng, $dr)) { $blocked = true; break; } }
                    if (!$blocked) $hits[] = array($i, $j, $rng);
                    break;
                }
                if (strlen($k) > strlen($needleKey)) break;
            }
        }
        if (count($hits) === 1) {
            list($i, $j, $rng) = $hits[0];
            $newLines = array_merge(array_slice($lines, 0, $i), array($replace), array_slice($lines, $j + 1));
            $newC = implode("\n", $newLines);
            $newRng = array($rng[0], $rng[0] + strlen($replace));
            return array('c' => $newC, 'old' => $rng, 'new' => $newRng);
        }
    }
    return null;
}

/**
 * 顺序应用多个替换块（protocol v8 块隔离）：
 * 维护已替换区间列表；每次替换后把位于替换点之后的旧区间按长度差平移，
 * 保证后续块的 SEARCH 永远只在「未被前序块改动的原文区域」定位。
 * 返回 array('content'=>, 'applied'=>, 'failed'=>, 'bad'=>)
 */
function aiApplyBlocksSeq($content, $blocks) {
    $done = array();
    $applied = 0; $failed = 0; $bad = array();
    foreach ($blocks as $blk) {
        $search = $blk[0]; $replace = $blk[1];
        $res = aiApplyBlock($content, $search, $replace, $done);
        if ($res === null) { $failed++; $bad[] = $search; continue; }
        $content = $res['c'];
        $delta = ($res['new'][1] - $res['new'][0]) - ($res['old'][1] - $res['old'][0]);
        foreach ($done as &$r) {
            if ($r[0] >= $res['old'][1]) { $r[0] += $delta; $r[1] += $delta; }
        }
        unset($r);
        $done[] = $res['new'];
        $applied++;
    }
    return array('content' => $content, 'applied' => $applied, 'failed' => $failed, 'bad' => $bad);
}

/**
 * 执行 TOOL 白名单工具（只读、只允许当前用户自己的数据），返回 JSON 字符串
 * @param string $name     工具名
 * @param array $args      工具参数
 * @param PDO $pdo         数据库连接
 * @param int $uid         当前登录用户 id
 */
function aiRunTool($name, $args, $pdo, $uid) {
    switch ((string)$name) {
        case 'list_folder': {
            $path = isset($args['path']) ? trim((string)$args['path']) : '';
            if ($path === '' || $path === '主页') $path = null;
            $targetId = null;
            if ($path !== null) {
                $segs = array_values(array_filter(explode('/', $path)));
                if (empty($segs)) return json_encode(array('error' => 'path_invalid'), JSON_UNESCAPED_UNICODE);
                $parentId = null;
                foreach ($segs as $seg) {
                    $st = $pdo->prepare("SELECT id FROM pn_folders WHERE user_id = ? AND parent_id <=> ? AND name = ?");
                    $st->execute(array($uid, $parentId, $seg));
                    $row = $st->fetch();
                    if (!$row) return json_encode(array('error' => 'folder_not_found', 'missing_segment' => $seg), JSON_UNESCAPED_UNICODE);
                    $parentId = (int)$row['id'];
                }
                $targetId = $parentId;
            }
            // 子文件夹
            $st = $pdo->prepare("SELECT id, name FROM pn_folders WHERE user_id = ? AND parent_id <=> ? ORDER BY sort_order ASC LIMIT 50");
            $st->execute(array($uid, $targetId));
            $subFolders = $st->fetchAll();
            // 便签清单（id + 标题 + 前80字摘要）
            $st = $pdo->prepare("SELECT id, title, SUBSTRING(content, 1, 80) AS snippet FROM pn_notes WHERE user_id = ? AND folder_id <=> ? ORDER BY pinned DESC, sort_order ASC LIMIT 100");
            $st->execute(array($uid, $targetId));
            $notes = $st->fetchAll();
            $foldersArr = array();
            foreach ($subFolders as $f) $foldersArr[] = array('id' => (int)$f['id'], 'name' => $f['name']);
            $notesArr = array();
            foreach ($notes as $n) $notesArr[] = array('id' => (int)$n['id'], 'title' => (string)$n['title'], 'snippet' => $n['snippet'] === null ? '' : (string)$n['snippet']);
            return json_encode(array(
                'path' => $path !== null ? $path : '主页',
                'subfolders' => $foldersArr,
                'notes' => $notesArr,
            ), JSON_UNESCAPED_UNICODE);
        }
        case 'read_note': {
            $nid = isset($args['id']) ? (int)$args['id'] : 0;
            if ($nid <= 0) return json_encode(array('error' => 'invalid_id'), JSON_UNESCAPED_UNICODE);
            $st = $pdo->prepare("SELECT title, content FROM pn_notes WHERE id = ? AND user_id = ?");
            $st->execute(array($nid, $uid));
            $row = $st->fetch();
            if (!$row) return json_encode(array('error' => 'note_not_found'), JSON_UNESCAPED_UNICODE);
            return json_encode(array(
                'id' => $nid,
                'title' => (string)$row['title'],
                'content' => (string)$row['content'],
            ), JSON_UNESCAPED_UNICODE);
        }
        default:
            return json_encode(array('error' => 'unknown_tool', 'name' => (string)$name), JSON_UNESCAPED_UNICODE);
    }
}
function aiIsInternalIp($ip) {
    if (!is_string($ip) || $ip === '') return true;
    // 私网(10/8、172.16/12、192.168/16、fc00::/7) + 保留段(127.0.0.0/8、169.254.0.0/16、0.0.0.0/8、::1、fe80::/10 等)
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    return false;
}

/**
 * 解析域名全部 A/AAAA 记录（含 IPv6），失败返回空数组
 */
function aiResolveIps($host) {
    $ips = array();
    if (function_exists('dns_get_record')) {
        $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (isset($r['ipv4']) && filter_var($r['ipv4'], FILTER_VALIDATE_IP)) $ips[] = $r['ipv4'];
                elseif (isset($r['ipv6']) && filter_var($r['ipv6'], FILTER_VALIDATE_IP)) $ips[] = $r['ipv6'];
            }
        }
    }
    if (empty($ips) && function_exists('gethostbyname')) {
        $g = gethostbyname($host);
        if (filter_var($g, FILTER_VALIDATE_IP)) $ips[] = $g;
    }
    return $ips;
}

/**
 * SSRF 防护：URL 的 host 是否安全。解析到内网/环回/链路本地/私网地址或无法解析时返回 false
 */
function aiEndpointHostSafe($url) {
    $p = @parse_url($url);
    if (!is_array($p) || empty($p['host'])) return false;
    $host = strtolower(trim((string)$p['host'], '[]'));
    $host = rtrim($host, '.');
    if ($host === '') return false;
    // localhost 及其子域
    if ($host === 'localhost' || substr($host, -10) === '.localhost') return false;
    // 形似 IP 简写的纯数字/十六进制主机名（如 127.1、2130706433、0x7f000001），curl 可能解析为环回
    if (preg_match('/^\d+(?:\.\d+)*$/', $host) || preg_match('/^0x[0-9a-f]+$/i', $host)) return false;
    // 字面 IP：直接判断
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !aiIsInternalIp($host);
    }
    // 域名：解析全部 A/AAAA，任一命中内网即拒绝；解析失败也拒绝（保守）
    $ips = aiResolveIps($host);
    if (empty($ips)) return false;
    foreach ($ips as $ip) {
        if (aiIsInternalIp($ip)) return false;
    }
    return true;
}

/**
 * 把澄清问答历史注入对话（assistant 提问块 + user 回答），使 AI 见过前文
 */
function aiClarifyContext($rounds) {
    $msgs = array();
    foreach ($rounds as $r) {
        $msgs[] = array('role' => 'assistant', 'content' => "<<<CLARIFY>>>\n" . $r['q'] . "\n<<<END>>>");
        $msgs[] = array('role' => 'user', 'content' => '回答：' . $r['a']);
    }
    return $msgs;
}

/**
 * 归一化用户填写的 base_url：无协议补 https://，返回完整 chat/completions 地址
 * 仅接受公网 http/https，拒绝内网/环回/链路本地/私网地址（SSRF 防护）
 */
function ownEndpoint($baseUrl) {
    $base = trim((string)$baseUrl);
    if ($base === '') return '';
    if (stripos($base, '://') === false) $base = 'https://' . $base;
    // 只接受 http/https
    if (stripos($base, 'https://') !== 0 && stripos($base, 'http://') !== 0) return '';
    if (!aiEndpointHostSafe($base)) return '';
    $base = rtrim($base, '/');
    if (substr($base, -17) === '/chat/completions') return $base;
    return $base . '/chat/completions';
}

/**
 * 调用 OpenAI 兼容 chat/completions（url/key/model 由调用方指定）
 * $onDelta 非空时走流式（stream:true），逐 token 回调转发；上游不支持流式则自动降级为整段返回（结果不变）
 */
function aiChat($url, $key, $model, $messages, $maxTokens = 8000, $extra = null, $onDelta = null) {
    $payloadArr = array(
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.1,
    );
    // 额外请求体参数（仅自有 Key 模式）：深度思考预设 + 用户自定义 Body，后者优先
    if (is_array($extra)) {
        foreach ($extra as $k => $v) {
            if ($k !== 'model' && $k !== 'messages' && is_string($k)) $payloadArr[$k] = $v;
        }
    }
    if ($onDelta !== null) $payloadArr['stream'] = true; // 流式：逐 token 转发；上游不支持时降级为整段
    $payload = json_encode($payloadArr, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);

    $body = false; $status = 0; $text = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ),
            CURLOPT_TIMEOUT        => 130,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        );
        if ($onDelta !== null) {
            // 流式：WRITEFUNCTION 逐块解析上游 SSE 的 data 行，提取 delta.content 即时回调
            $sseBuf = ''; $raw = '';
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $data) use (&$sseBuf, &$text, &$raw, $onDelta) {
                if ($data === '') return 0;
                $raw .= $data;
                $sseBuf .= $data;
                while (($pos = strpos($sseBuf, "\n")) !== false) {
                    $line = substr($sseBuf, 0, $pos);
                    $sseBuf = substr($sseBuf, $pos + 1);
                    $line = rtrim($line, "\r");
                    if (strncmp($line, 'data:', 5) !== 0) continue;
                    $chunk = trim(substr($line, 5));
                    if ($chunk === '' || $chunk === '[DONE]') continue;
                    $j = json_decode($chunk, true);
                    if (!is_array($j) || !isset($j['choices'][0])) continue;
                    $delta = '';
                    if (isset($j['choices'][0]['delta']['content']) && is_string($j['choices'][0]['delta']['content'])) $delta = $j['choices'][0]['delta']['content'];
                    elseif (isset($j['choices'][0]['text']) && is_string($j['choices'][0]['text'])) $delta = $j['choices'][0]['text'];
                    if ($delta !== '') { $text .= $delta; $onDelta($delta); }
                }
                return strlen($data);
            };
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body !== false) {
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($onDelta !== null) $body = $raw; // 流式模式下 body 是原始 SSE 文本
        }
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
    // 流式：优先用已累积的增量文本（上游不支持流式时 $text 为空，自动走整段解析）
    if ($onDelta !== null) {
        if ($status !== 200) {
            $msg = '';
            if (is_array($json)) {
                if (isset($json['error']['message'])) $msg = (string)$json['error']['message'];
                elseif (isset($json['error'])) $msg = json_encode($json['error']);
                elseif (isset($json['message'])) $msg = (string)$json['message'];
            }
            if ($msg === '') $msg = 'HTTP ' . $status;
            return array('ok' => false, 'text' => '', 'err' => '上游错误：' . mb_substr($msg, 0, 200, 'UTF-8'));
        }
        if ($text !== '') return array('ok' => true, 'text' => $text, 'err' => '');
        // 收到 200 但无任何 content 增量：要么上游不支持流式（整段 JSON，往下解析），要么只输出了思考
        if (strpos($bodyStr, '"reasoning_content"') !== false) {
            return array('ok' => false, 'text' => '', 'err' => '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens');
        }
    }
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

    // ================= AI 自动整理便签（清单喂法） =================
    // action=classify：AI 只生成方案（JSON moves），不直接改数据，预览后由 classify_apply 落库

    // 供 classify 内部读取用户全部便签的清单字段
    function classifyLoadManifest($pdo, $uid) {
        $st = $pdo->prepare("SELECT id, title, content, folder_id FROM pn_notes WHERE user_id = ? ORDER BY pinned DESC, sort_order ASC, updated_at DESC");
        $st->execute(array($uid));
        $rows = $st->fetchAll();
        return is_array($rows) ? $rows : array();
    }

    // 供 classify 内部读取用户全部文件夹
    function classifyLoadFolders($pdo, $uid) {
        $st = $pdo->prepare("SELECT id, parent_id, name FROM pn_folders WHERE user_id = ? ORDER BY parent_id, sort_order, id");
        $st->execute(array($uid));
        $rows = $st->fetchAll();
        return is_array($rows) ? $rows : array();
    }

    // 从文件夹 id 回溯完整路径段
    function classifyFolderPath($fid, $folders) {
        if ($fid === null || $fid === 0 || $fid === '0') return array();
        $byId = array();
        foreach ($folders as $f) $byId[(int)$f['id']] = $f;
        $path = array();
        $cur = (int)$fid;
        $guard = 0;
        while ($cur > 0 && isset($byId[$cur]) && $guard++ < 50) {
            array_unshift($path, (string)$byId[$cur]['name']);
            $cur = isset($byId[$cur]['parent_id']) ? (int)$byId[$cur]['parent_id'] : 0;
        }
        return $path;
    }

    if ($action === 'classify') {
        $message = isset($input['message']) ? (string)$input['message'] : '';
        if (trim($message) === '') jsonOut(array('success' => false, 'message' => '没有可整理的便签清单'));

        // ===== 鉴权（与 edit 同款三通道）：政策版本 + 平台密钥/管理员/自有 Key =====
        $policyV = isset($input['policyVersion']) ? (int)$input['policyVersion'] : 0;
        if ($policyV < AI_POLICY_VERSION) {
            jsonOut(array('success' => false, 'need_policy' => true, 'policy_version' => AI_POLICY_VERSION,
                          'message' => '请先阅读并同意 AI 使用政策'));
        }
        $prefs = isset($input['prefs']) && is_array($input['prefs']) ? $input['prefs'] : array();
        $mode = (isset($prefs['mode']) && $prefs['mode'] === 'own') ? 'own' : 'platform';
        $pdo = getDB();
        $keyRow = null;     // 平台密钥行（成功后计费用）
        $ownUsed = -1;      // 自有 Key 已用次数（成功后计费用）

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
                $mode = 'admin';
            } else {
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
            }
        } else {
            // 自有 Key 模式：同 edit——强制走管理员透明代理，每日限 500 次
            $target = ownEndpoint(isset($prefs['ownBaseUrl']) ? $prefs['ownBaseUrl'] : '');
            $key  = isset($prefs['ownApiKey']) ? trim((string)$prefs['ownApiKey']) : '';
            $model = isset($prefs['ownModel']) ? trim((string)$prefs['ownModel']) : '';
            if ($target === '') {
                jsonOut(array('success' => false, 'message' => '自有 Key 接口地址无效：仅支持公网 http/https 地址'));
            }
            if ($key === '' || $model === '') {
                jsonOut(array('success' => false, 'message' => '自有 Key 模式需要在「AI 设置」里填写 API Key 和模型名'));
            }
            $period = aiPeriodNow();
            $row = getUserAiPrefs($uid);
            $ownUsed = ($row && $row['own_period'] === $period) ? (int)$row['own_used'] : 0;
            if ($ownUsed >= AI_OWN_DAILY_LIMIT) {
                jsonOut(array('success' => false, 'message' => '今日自有 Key 调用次数已达上限（' . AI_OWN_DAILY_LIMIT . '，北京时间 8:00 重置）'));
            }
            $proxyPrefix = rtrim(trim(getSetting('ai_own_proxy', '')), '/');
            if (!preg_match('#^https://#i', $proxyPrefix)) {
                jsonOut(array('success' => false, 'message' => '服务器未配置自有 Key 透明代理，请联系管理员在「AI 设置」中填写'));
            }
            $url = $proxyPrefix . '/' . $target;
        }
        // 整理 Agent 要给上游多次往返（工具调用循环），全局限时 150s 会让 SSE 中途被 kill；
        // 这里放宽到 900s——前端有断线兜底，真超时用户能看到明确错误而非假死
        if (function_exists('set_time_limit')) @set_time_limit(900);
        ignore_user_abort(false);   // 浏览器断开连接立即kill（不耗上游 token）

        $system = '你是便签整理助手，能力等同一个文件管理器（类似 Claude Code 的 Agent）。'
            . '最终必须输出严格合法的 JSON 对象（{"ops":[...]}），ops 是操作数组，每个元素为以下操作之一：'
            . '{"op":"move","id":便签id数字,"path":"目标文件夹完整路径"} —— 把便签移入文件夹（"主页"表示根层级）；'
            . '{"op":"mkdir","path":"新文件夹完整路径"} —— 新建文件夹（可一次输出多条实现批量创建）；'
            . '{"op":"rename","path":"现有文件夹完整路径","new_name":"新名字"} —— 重命名文件夹；'
            . '{"op":"delete_folder","path":"现有文件夹完整路径"} —— 删除文件夹（内容自动上移一级，绝不丢便签）；'
            . '{"op":"delete_note","id":便签id数字} —— 删除便签（危险操作，仅当用户明确要求删除时使用）；'
            . '{"op":"sort_notes","path":"文件夹完整路径","by":"name|title_len|updated","order":"asc|desc"} —— 对该文件夹下的便签按规则排序；'
            . '{"op":"color","id":便签id数字,"color":"yellow|pink|blue|green|purple|orange"} —— 修改便签颜色；'
            . '{"op":"pin","id":便签id数字,"pinned":true或false} —— 置顶/取消置顶便签。'
            . 'path 用 / 分隔层级，最多 5 段、每段 1 到 30 个字符。'
            . '只在用户指令范围内操作，不要自作主张；已在合理位置的便签不要移动。没有任何要执行的操作时输出 {"ops":[]}。'
            . "\n"
            . "【工作流程（Agent 模式）】\n"
            . "1. 每一轮你可以先用一两句话简要说明你打算怎么做（用户能看到这些话）\n"
            . "2. 清单里便签内容只有前 80 字摘要。信息不够时不要猜——先查再看，输出工具调用块查看数据：\n"
            . "<<<TOOL>>>\n"
            . "{\"name\":\"list_folder\",\"path\":\"工作/项目A\"}   —— 列出该路径下的子文件夹和便签清单（主页填 \"主页\"）\n"
            . "{\"name\":\"read_note\",\"id\":123}   —— 查看 id 为 123 的便签完整内容\n"
            . "<<<END>>>\n"
            . "3. 收到【工具结果】后继续判断：还需要查就再调工具（不限次数，查够为止），信息足够就输出最终 JSON 方案\n"
            . "4. 最终 JSON 单独一轮输出，那轮不要再有多余解释";

        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $message),
        );

        // ===== SSE Agent 流式执行（protocol v7）：思考文本 / 工具调用 / 工具结果 全程可见 =====
        sseStart();
        $round = 0;
        $toolRounds = 0;
        $jsonAttempts = 0;
        $plan = null;
        while (true) {
            $round++;
            sseSend('phase', array('t' => $round === 1 ? '🤖 开始分析…' : '🤔 第 ' . $round . ' 轮思考…'));

            // 思考文本流式转发：一旦出现 '{' 或 '<<<'（最终 JSON / 工具块开头）就停止转发，避免把协议原文刷进聊天框
            $accum = '';
            $onDelta = function ($t) use (&$accum) {
                $accum .= $t;
                if (strpos($accum, '{') !== false || strpos($accum, '<<<') !== false) return;
                sseSend('delta', array('t' => $t));
            };

            $r = aiChat($url, $key, $model, $messages, 4000, null, $onDelta);
            if (!$r['ok']) aiOut(array('success' => false, 'message' => (string)$r['err']));

            $text = trim($r['text']);
            // 工具调用块：先于 JSON 解析处理（上限 20 次，防失控循环）
            if ($toolRounds < 20 && preg_match('/<<<TOOL>>>\s*([\s\S]*?)\s*<<<END>>>/i', $text, $tm)) {
                $toolCall = json_decode(trim($tm[1]), true);
                if (is_array($toolCall) && isset($toolCall['name'])) {
                    $toolRounds++;
                    $argsBrief = isset($toolCall['path']) ? (string)$toolCall['path'] : (isset($toolCall['id']) ? '#' . $toolCall['id'] : '');
                    sseSend('tool', array('name' => (string)$toolCall['name'], 'args' => $argsBrief, 'round' => $toolRounds));
                    $toolResult = aiRunTool((string)$toolCall['name'], $toolCall, $pdo, $uid);
                    // 结果摘要（全文可能很长，聊天框只显示前 300 字）
                    $brief = $toolResult;
                    if (function_exists('mb_substr')) $brief = mb_substr($toolResult, 0, 300, 'UTF-8') . (mb_strlen($toolResult, 'UTF-8') > 300 ? '…' : '');
                    else $brief = substr($toolResult, 0, 600);
                    sseSend('tool_result', array('name' => (string)$toolCall['name'], 'brief' => $brief));
                    $messages[] = array('role' => 'assistant', 'content' => $text);
                    $messages[] = array('role' => 'user', 'content' => '【工具结果】' . (string)$toolCall['name'] . "\n" . $toolResult . "\n\n请继续判断：还需要查就再调工具，信息足够就输出最终 JSON 方案。");
                    continue;
                }
            }

            // 提取平衡花括号的 JSON 对象（strpos 首个 { 会误抓工具参数/解释文字里的片段）
            $stripped = trim($text);
            $fence = "```";
            if (substr($stripped, 0, 3) === $fence) {          // 开头围栏 ``` 或 ```json
                $nl = strpos($stripped, "\n");
                if ($nl !== false) $stripped = substr($stripped, $nl + 1);
            }
            if (substr($stripped, -3) === $fence) $stripped = substr($stripped, 0, -3);   // 结尾围栏
            $stripped = trim($stripped);
            $plan = null;
            for ($ci = 0; $ci < strlen($stripped); $ci++) {
                if ($stripped[$ci] !== '{') continue;
                $depth = 0; $inStr = false; $esc = false;
                for ($cj = $ci; $cj < strlen($stripped); $cj++) {
                    $ch = $stripped[$cj];
                    if ($esc) { $esc = false; continue; }
                    if ($ch === '\\' && $inStr) { $esc = true; continue; }
                    if ($ch === '"') { $inStr = !$inStr; continue; }
                    if ($inStr) continue;
                    if ($ch === '{') $depth++;
                    elseif ($ch === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $cand = json_decode(substr($stripped, $ci, $cj - $ci + 1), true);
                            // 只接受含 ops 或 moves 键的对象（跳过思考文字里的示例 JSON）
                            if (is_array($cand) && (isset($cand['ops']) || isset($cand['moves']))) { $plan = $cand; break 2; }
                            break;   // 这个 { 不匹配，找下一个
                        }
                    }
                }
            }
            if (is_array($plan)) break;

            // 没解析出方案：错误回喂重试（最多 3 轮，AI 多轮思考时偶尔某轮只输出文字不输出 JSON）
            $jsonAttempts++;
            if ($jsonAttempts >= 3) {
                aiOut(array('success' => false, 'message' => 'AI 连续 3 轮未输出有效方案，请换个说法再试'));
            }
            sseSend('phase', array('t' => '🔁 方案格式异常，自动纠错第 ' . $jsonAttempts . ' 次…'));
            $messages[] = array('role' => 'assistant', 'content' => $r['text']);
            $messages[] = array('role' => 'user', 'content' => '你上一轮的输出不完整或没有 JSON（可能被截断）。现在重新输出最终方案：直接以字符 { 开头、} 结尾的 JSON 对象 {"ops":[...]}，禁止使用代码围栏，禁止任何解释文字，禁止工具调用。');
        }
        if (!is_array($plan)) {
            aiOut(array('success' => false, 'message' => 'AI 返回的 JSON 结构无效'));
        }

        // 解析 + 清洗操作数组；兼容旧协议的 moves 数组
        $validOps = array();
        $rawOps = array();
        if (isset($plan['ops']) && is_array($plan['ops'])) $rawOps = $plan['ops'];
        elseif (isset($plan['moves']) && is_array($plan['moves'])) {
            foreach ($plan['moves'] as $mv) $rawOps[] = array_merge(array('op' => 'move'), is_array($mv) ? $mv : array());
        }
        $colorsOk = array('yellow' => 1, 'pink' => 1, 'blue' => 1, 'green' => 1, 'purple' => 1, 'orange' => 1);
        foreach ($rawOps as $op) {
            if (!is_array($op) || !isset($op['op'])) continue;
            $type = (string)$op['op'];
            $path = isset($op['path']) && is_string($op['path']) ? trim($op['path']) : '';
            if ($type === 'move') {
                $id = isset($op['id']) ? (int)$op['id'] : 0;
                if ($id > 0 && $path !== '') $validOps[] = array('op' => 'move', 'id' => $id, 'path' => $path);
            } elseif ($type === 'mkdir') {
                if ($path !== '' && $path !== '主页') $validOps[] = array('op' => 'mkdir', 'path' => $path);
            } elseif ($type === 'rename') {
                $newName = isset($op['new_name']) && is_string($op['new_name']) ? trim($op['new_name']) : '';
                if ($path !== '' && $path !== '主页' && $newName !== '') $validOps[] = array('op' => 'rename', 'path' => $path, 'new_name' => $newName);
            } elseif ($type === 'delete_folder') {
                if ($path !== '' && $path !== '主页') $validOps[] = array('op' => 'delete_folder', 'path' => $path);
            } elseif ($type === 'delete_note') {
                $id = isset($op['id']) ? (int)$op['id'] : 0;
                if ($id > 0) $validOps[] = array('op' => 'delete_note', 'id' => $id);
            } elseif ($type === 'sort_notes') {
                $by = isset($op['by']) ? (string)$op['by'] : 'name';
                if (!in_array($by, array('name', 'title_len', 'updated'), true)) $by = 'name';
                $order = (isset($op['order']) && $op['order'] === 'desc') ? 'desc' : 'asc';
                $validOps[] = array('op' => 'sort_notes', 'path' => ($path === '' ? '主页' : $path), 'by' => $by, 'order' => $order);
            } elseif ($type === 'color') {
                $id = isset($op['id']) ? (int)$op['id'] : 0;
                $color = isset($op['color']) ? (string)$op['color'] : '';
                if ($id > 0 && isset($colorsOk[$color])) $validOps[] = array('op' => 'color', 'id' => $id, 'color' => $color);
            } elseif ($type === 'pin') {
                $id = isset($op['id']) ? (int)$op['id'] : 0;
                if ($id > 0) $validOps[] = array('op' => 'pin', 'id' => $id, 'pinned' => !empty($op['pinned']) ? 1 : 0);
            }
        }
        // 整理成功才计费 1 次（无论中间调了多少次工具都按整单算，与 edit 一致）
        if ($mode === 'platform' && $keyRow) {
            $period = aiPeriodNow();
            $used = ($keyRow['period'] === $period) ? (int)$keyRow['used'] : 0;
            bumpKeyUsage($keyRow['id'], $period, $used);
        } elseif ($mode === 'own' && $ownUsed >= 0) {
            try {
                $pdo->exec("INSERT INTO pn_user_ai_prefs (user_id, own_used, own_period, updated_at)
                            VALUES (" . $uid . ", 1, '" . $period . "', NOW())
                            ON DUPLICATE KEY UPDATE own_used = " . ($ownUsed + 1) . ", own_period = '" . $period . "', updated_at = NOW()");
            } catch (Exception $e) { /* 计数失败不阻断 */ }
        }
        aiOut(array('success' => true, 'plan' => array('ops' => $validOps)));
    }

    // ================= AI 整理落库（程序校验 + 溯源日志，支持 move/mkdir/rename） =================
    if ($action === 'classify_apply') {
        $pdo = getDB();
        $ops = isset($input['ops']) && is_array($input['ops']) ? $input['ops'] : array();
        // 兼容旧前端只发 moves 的调用
        if (empty($ops) && isset($input['moves']) && is_array($input['moves'])) {
            foreach ($input['moves'] as $mv) $ops[] = array_merge(array('op' => 'move'), is_array($mv) ? $mv : array());
        }
        if (empty($ops)) jsonOut(array('success' => false, 'message' => '没有要执行的操作'));

        $folders = classifyLoadFolders($pdo, $uid);
        $notes = classifyLoadManifest($pdo, $uid);
        $noteIds = array();
        foreach ($notes as $n) $noteIds[(int)$n['id']] = true;

        // 统一校验：路径段 1-30 字、最多 5 段、禁危险字符
        $skipped = array();
        $validSegs = function ($path) use (&$skipped) {
            $segments = array_values(array_filter(array_map('trim', explode('/', (string)$path))));
            if (count($segments) < 1 || count($segments) > 5) return null;
            foreach ($segments as $seg) {
                $len = function_exists('mb_strlen') ? mb_strlen($seg, 'UTF-8') : strlen($seg);
                if ($len < 1 || $len > 30) return null;
                if (preg_match('/[<>"\'\\\\]|^\\s|\\s$/', $seg)) return null;
            }
            return $segments;
        };

        $cleanOps = array();
        foreach ($ops as $op) {
            if (!is_array($op) || !isset($op['op'])) { $skipped[] = array('reason' => 'malformed'); continue; }
            $type = (string)$op['op'];
            if ($type === 'move') {
                $nid = isset($op['id']) ? (int)$op['id'] : 0;
                if ($nid <= 0 || !isset($noteIds[$nid])) { $skipped[] = array('op' => 'move', 'reason' => 'note_not_found'); continue; }
                $path = ($op['path'] === '主页') ? '' : (string)$op['path'];
                $segments = ($path === '') ? array() : $validSegs($path);
                if ($segments === null) { $skipped[] = array('op' => 'move', 'id' => $nid, 'reason' => 'path_invalid'); continue; }
                $cleanOps[] = array('op' => 'move', 'id' => $nid, 'segments' => $segments);
            } elseif ($type === 'mkdir') {
                $segments = $validSegs(isset($op['path']) ? $op['path'] : '');
                if ($segments === null) { $skipped[] = array('op' => 'mkdir', 'reason' => 'path_invalid'); continue; }
                $cleanOps[] = array('op' => 'mkdir', 'segments' => $segments);
            } elseif ($type === 'rename') {
                $segments = $validSegs(isset($op['path']) ? $op['path'] : '');
                $newName = isset($op['new_name']) && is_string($op['new_name']) ? trim($op['new_name']) : '';
                $nameLen = function_exists('mb_strlen') ? mb_strlen($newName, 'UTF-8') : strlen($newName);
                if ($segments === null) { $skipped[] = array('op' => 'rename', 'reason' => 'path_invalid'); continue; }
                if ($nameLen < 1 || $nameLen > 30 || preg_match('/[<>"\'\\\\/]|^\\s|\\s$/', $newName)) {
                    $skipped[] = array('op' => 'rename', 'reason' => 'name_invalid'); continue;
                }
                $cleanOps[] = array('op' => 'rename', 'segments' => $segments, 'new_name' => $newName);
            } elseif ($type === 'delete_folder') {
                $segments = $validSegs(isset($op['path']) ? $op['path'] : '');
                if ($segments === null) { $skipped[] = array('op' => 'delete_folder', 'reason' => 'path_invalid'); continue; }
                $cleanOps[] = array('op' => 'delete_folder', 'segments' => $segments);
            } elseif ($type === 'delete_note') {
                $nid = isset($op['id']) ? (int)$op['id'] : 0;
                if ($nid <= 0 || !isset($noteIds[$nid])) { $skipped[] = array('op' => 'delete_note', 'reason' => 'note_not_found'); continue; }
                $cleanOps[] = array('op' => 'delete_note', 'id' => $nid);
            } elseif ($type === 'sort_notes') {
                $path = isset($op['path']) && is_string($op['path']) ? trim($op['path']) : '主页';
                $segments = ($path === '' || $path === '主页') ? array() : $validSegs($path);
                if ($segments === null) { $skipped[] = array('op' => 'sort_notes', 'reason' => 'path_invalid'); continue; }
                $by = isset($op['by']) ? (string)$op['by'] : 'name';
                if (!in_array($by, array('name', 'title_len', 'updated'), true)) $by = 'name';
                $order = (isset($op['order']) && $op['order'] === 'desc') ? 'desc' : 'asc';
                $cleanOps[] = array('op' => 'sort_notes', 'segments' => $segments, 'by' => $by, 'order' => $order);
            } elseif ($type === 'color') {
                $nid = isset($op['id']) ? (int)$op['id'] : 0;
                $color = isset($op['color']) ? (string)$op['color'] : '';
                if ($nid <= 0 || !isset($noteIds[$nid])) { $skipped[] = array('op' => 'color', 'reason' => 'note_not_found'); continue; }
                if (!in_array($color, array('yellow', 'pink', 'blue', 'green', 'purple', 'orange'), true)) {
                    $skipped[] = array('op' => 'color', 'reason' => 'color_invalid'); continue;
                }
                $cleanOps[] = array('op' => 'color', 'id' => $nid, 'color' => $color);
            } elseif ($type === 'pin') {
                $nid = isset($op['id']) ? (int)$op['id'] : 0;
                if ($nid <= 0 || !isset($noteIds[$nid])) { $skipped[] = array('op' => 'pin', 'reason' => 'note_not_found'); continue; }
                $cleanOps[] = array('op' => 'pin', 'id' => $nid, 'pinned' => !empty($op['pinned']) ? 1 : 0);
            } else {
                $skipped[] = array('reason' => 'unknown_op');
            }
        }

        if (empty($cleanOps)) jsonOut(array('success' => false, 'message' => '所有操作项均未通过校验', 'skipped' => $skipped));

        $pdo->beginTransaction();
        try {
            // path -> id 映射（随创建动态刷新）
            $refreshMap = function () use ($pdo, $uid) {
                $fs = classifyLoadFolders($pdo, $uid);
                $map = array();
                foreach ($fs as $f) { $map[implode('/', classifyFolderPath((int)$f['id'], $fs))] = (int)$f['id']; }
                return $map;
            };
            $folderMap = $refreshMap();

            $now = date('Y-m-d H:i:s');
            $undo = array();   // 撤销动作序列：move 记 from；mkdir 记新建 id；rename 记旧名
            $moved = 0; $created = 0; $renamed = 0; $deletedF = 0; $deletedN = 0; $sorted = 0; $colored = 0; $pinned = 0;

            // 逐段确保路径存在，返回末段 folder_id（用于 move 与 mkdir 的父级创建）
            $ensurePath = function ($segments) use (&$folderMap, $refreshMap, $pdo, $uid, $now, &$created, &$undo) {
                $parentId = null;
                $cur = array();
                foreach ($segments as $seg) {
                    $cur[] = $seg;
                    $curPath = implode('/', $cur);
                    if (isset($folderMap[$curPath])) { $parentId = $folderMap[$curPath]; continue; }
                    $st = $pdo->prepare("INSERT INTO pn_folders (user_id, parent_id, name, sort_order, created_at) VALUES (?, ?, ?, 0, ?)");
                    $st->execute(array($uid, $parentId, $seg, $now));
                    $newId = (int)$pdo->lastInsertId();
                    $undo[] = array('undo' => 'rmdir', 'folder_id' => $newId);
                    $folderMap = $refreshMap();
                    $created++;
                    $parentId = $newId;
                }
                return $parentId;
            };

            foreach ($cleanOps as $op) {
                if ($op['op'] === 'move') {
                    $targetFid = $op['segments'] ? $ensurePath($op['segments']) : null;   // 空段 = 移回主页
                    $st = $pdo->prepare("SELECT folder_id FROM pn_notes WHERE id = ? AND user_id = ?");
                    $st->execute(array($op['id'], $uid));
                    $fromFid = $st->fetchColumn();
                    $fromFid = ($fromFid === false || $fromFid === null) ? null : (int)$fromFid;
                    if ($fromFid === $targetFid) continue;
                    $st = $pdo->prepare("UPDATE pn_notes SET folder_id = ?, updated_at = ? WHERE id = ? AND user_id = ?");
                    $st->execute(array($targetFid, $now, $op['id'], $uid));
                    $undo[] = array('undo' => 'move', 'note_id' => $op['id'], 'from_folder_id' => $fromFid);
                    $moved++;
                } elseif ($op['op'] === 'mkdir') {
                    $ensurePath($op['segments']);   // 已存在的路径直接跳过，缺的逐段创建
                } elseif ($op['op'] === 'rename') {
                    $pathStr = implode('/', $op['segments']);
                    if (!isset($folderMap[$pathStr])) { $skipped[] = array('op' => 'rename', 'path' => $pathStr, 'reason' => 'folder_not_found'); continue; }
                    $fid = $folderMap[$pathStr];
                    $st = $pdo->prepare("SELECT name FROM pn_folders WHERE id = ? AND user_id = ?");
                    $st->execute(array($fid, $uid));
                    $oldName = $st->fetchColumn();
                    if ($oldName === false || $oldName === $op['new_name']) continue;
                    $st = $pdo->prepare("UPDATE pn_folders SET name = ? WHERE id = ? AND user_id = ?");
                    $st->execute(array($op['new_name'], $fid, $uid));
                    $undo[] = array('undo' => 'rename', 'folder_id' => $fid, 'old_name' => (string)$oldName);
                    $renamed++;
                } elseif ($op['op'] === 'delete_folder') {
                    // 删除文件夹：内容上移一级（环安全由 folders.php 同款逻辑保证——只上移到 parent）
                    $pathStr = implode('/', $op['segments']);
                    if (!isset($folderMap[$pathStr])) { $skipped[] = array('op' => 'delete_folder', 'path' => $pathStr, 'reason' => 'folder_not_found'); continue; }
                    $fid = $folderMap[$pathStr];
                    $st = $pdo->prepare("SELECT parent_id FROM pn_folders WHERE id = ? AND user_id = ?");
                    $st->execute(array($fid, $uid));
                    $parentId = $st->fetchColumn();
                    if ($parentId === false) continue;
                    $parentId = ($parentId === null) ? null : (int)$parentId;
                    // 记录该文件夹全部内容快照用于撤销（还原结构）
                    $st = $pdo->prepare("SELECT id, name, parent_id, sort_order FROM pn_folders WHERE user_id = ? AND (id = ? OR parent_id <=> ?)");
                    $st->execute(array($uid, $fid, $fid));
                    $subTree = $st->fetchAll();
                    $st = $pdo->prepare("SELECT id, folder_id, sort_order FROM pn_notes WHERE user_id = ? AND folder_id <=> ?");
                    $st->execute(array($uid, $fid));
                    $subNotes = $st->fetchAll();
                    // 先收集整个子树（含多级嵌套）——用递归收集 id 集
                    $allFolderIds = array($fid);
                    $frontier = array($fid);
                    while (!empty($frontier)) {
                        $ph = implode(',', array_fill(0, count($frontier), '?'));
                        $st = $pdo->prepare("SELECT id FROM pn_folders WHERE user_id = ? AND parent_id IN ($ph)");
                        $st->execute(array_merge(array($uid), $frontier));
                        $next = $st->fetchAll(PDO::FETCH_COLUMN);
                        $frontier = array_map('intval', $next);
                        $allFolderIds = array_merge($allFolderIds, $frontier);
                    }
                    $phAll = implode(',', array_fill(0, count($allFolderIds), '?'));
                    $st = $pdo->prepare("SELECT id, folder_id, sort_order FROM pn_notes WHERE user_id = ? AND folder_id IN ($phAll)");
                    $st->execute(array_merge(array($uid), $allFolderIds));
                    $subNotes = $st->fetchAll();
                    // 执行删除：内容上移到被删文件夹的父级
                    $pdo->prepare("UPDATE pn_notes SET folder_id = ? WHERE user_id = ? AND folder_id IN ($phAll)")
                        ->execute(array_merge(array($parentId, $uid), $allFolderIds));
                    $pdo->prepare("UPDATE pn_folders SET parent_id = ? WHERE user_id = ? AND parent_id IN ($phAll) AND id NOT IN ($phAll)")
                        ->execute(array_merge(array($parentId, $uid), $allFolderIds, $allFolderIds));
                    $pdo->prepare("DELETE FROM pn_folders WHERE user_id = ? AND id IN ($phAll)")
                        ->execute(array_merge(array($uid), $allFolderIds));
                    $undo[] = array('undo' => 'delete_folder', 'folders' => array(), 'notes' => array(),
                                    'note_moves' => array_map(function ($r) { return array('id' => (int)$r['id'], 'folder_id' => $r['folder_id'] === null ? null : (int)$r['folder_id']); }, $subNotes));
                    // 撤销还原需要重建的文件夹结构（逆序重建）——记录完整子树
                    $undo[count($undo) - 1]['folders'] = array_map(function ($r) { return array('id' => (int)$r['id'], 'name' => (string)$r['name'], 'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'], 'sort_order' => (int)$r['sort_order']); }, array_reverse($subTree));
                    $folderMap = $refreshMap();
                    $deletedF++;
                } elseif ($op['op'] === 'delete_note') {
                    // 删除便签：记完整快照用于撤销
                    $st = $pdo->prepare("SELECT id, title, content, color, pinned, folder_id, sort_order FROM pn_notes WHERE id = ? AND user_id = ?");
                    $st->execute(array($op['id'], $uid));
                    $row = $st->fetch();
                    if (!$row) continue;
                    $pdo->prepare("DELETE FROM pn_notes WHERE id = ? AND user_id = ?")->execute(array($op['id'], $uid));
                    $undo[] = array('undo' => 'create_note', 'note' => array(
                        'id' => (int)$row['id'], 'title' => (string)$row['title'], 'content' => (string)$row['content'],
                        'color' => (string)$row['color'], 'pinned' => (int)$row['pinned'],
                        'folder_id' => $row['folder_id'] === null ? null : (int)$row['folder_id'], 'sort_order' => (int)$row['sort_order']
                    ));
                    unset($noteIds[(int)$op['id']]);   // 后续操作不再认这条便签
                    $deletedN++;
                } elseif ($op['op'] === 'sort_notes') {
                    // 对目标文件夹下的直接子便签重排（按标题/标题长度/更新时间）
                    $targetFid = $op['segments'] ? $folderMap[implode('/', $op['segments'])] : null;
                    if ($op['segments'] && !isset($folderMap[implode('/', $op['segments'])])) {
                        $skipped[] = array('op' => 'sort_notes', 'reason' => 'folder_not_found'); continue;
                    }
                    $st = $pdo->prepare("SELECT id, title, sort_order, updated_at FROM pn_notes WHERE user_id = ? AND folder_id <=> ? ORDER BY pinned DESC, sort_order, id");
                    $st->execute(array($uid, $targetFid));
                    $rows = $st->fetchAll();
                    $oldOrder = array();
                    foreach ($rows as $r) $oldOrder[] = array('id' => (int)$r['id'], 'sort_order' => (int)$r['sort_order']);
                    $keyFunc = null;
                    if ($op['by'] === 'title_len') {
                        $keyFunc = function ($r) { $l = function_exists('mb_strlen') ? mb_strlen((string)$r['title'], 'UTF-8') : strlen((string)$r['title']); return array($l, (int)$r['id']); };
                    } elseif ($op['by'] === 'updated') {
                        $keyFunc = function ($r) { return array($r['updated_at'], (int)$r['id']); };
                    } else {
                        $keyFunc = function ($r) { return array((string)$r['title'], (int)$r['id']); };
                    }
                    $keys = array();
                    foreach ($rows as $r) $keys[] = $keyFunc($r);
                                        // 按 key 排（array_multisort 对字符串 key 用自然序）
                    array_multisort($keys, SORT_NATURAL, $rows);
                    if ($op['order'] === 'desc') $rows = array_reverse($rows);
                    $i = 0;
                    $upd = $pdo->prepare("UPDATE pn_notes SET sort_order = ? WHERE id = ? AND user_id = ?");
                    foreach ($rows as $r) { $upd->execute(array($i++, (int)$r['id'], $uid)); }
                    $undo[] = array('undo' => 'sort_notes', 'order' => $oldOrder);
                    $sorted++;
                } elseif ($op['op'] === 'color') {
                    $st = $pdo->prepare("SELECT color FROM pn_notes WHERE id = ? AND user_id = ?");
                    $st->execute(array($op['id'], $uid));
                    $oldColor = $st->fetchColumn();
                    if ($oldColor === false || $oldColor === $op['color']) continue;
                    $pdo->prepare("UPDATE pn_notes SET color = ? WHERE id = ? AND user_id = ?")->execute(array($op['color'], $op['id'], $uid));
                    $undo[] = array('undo' => 'color', 'note_id' => $op['id'], 'old_color' => (string)$oldColor);
                    $colored++;
                } elseif ($op['op'] === 'pin') {
                    $st = $pdo->prepare("SELECT pinned FROM pn_notes WHERE id = ? AND user_id = ?");
                    $st->execute(array($op['id'], $uid));
                    $oldPin = $st->fetchColumn();
                    $newPin = $op['pinned'] ? 1 : 0;
                    if ($oldPin === false || (int)$oldPin === $newPin) continue;
                    $pdo->prepare("UPDATE pn_notes SET pinned = ? WHERE id = ? AND user_id = ?")->execute(array($newPin, $op['id'], $uid));
                    $undo[] = array('undo' => 'pin', 'note_id' => $op['id'], 'old_pinned' => (int)$oldPin);
                    $pinned++;
                }
            }

            // 溯源日志（undo 序列即可完整还原本批操作）
            // 会话制：日志只属于当前浏览器会话；旧会话的日志写入前先清掉（撤销资格不跨会话，服务器不留历史堆积）
            $sessKey = 'pn_classify_sess';
            if (!isset($_SESSION[$sessKey])) {
                $pdo->prepare("DELETE FROM pn_ai_actions WHERE user_id = ? AND action = 'classify'")->execute(array($uid));
                $_SESSION[$sessKey] = 1;
            }
            $logDetail = json_encode(array('ops' => $cleanOps, 'undo' => $undo, 'skipped' => $skipped), JSON_UNESCAPED_UNICODE);
            $st = $pdo->prepare("INSERT INTO pn_ai_actions (user_id, action, detail, created_at) VALUES (?, 'classify', ?, ?)");
            $st->execute(array($uid, $logDetail !== false ? $logDetail : json_encode(array('error' => 'log_encode_failed')), $now));

            $pdo->commit();
            jsonOut(array('success' => true, 'moved' => $moved, 'created' => $created, 'renamed' => $renamed, 'deleted_folders' => $deletedF, 'deleted_notes' => $deletedN, 'sorted' => $sorted, 'colored' => $colored, 'pinned' => $pinned, 'skipped' => $skipped));
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonOut(array('success' => false, 'message' => '执行失败，未做任何改动'), 500);
        }
    }

    // ================= AI 整理撤销（最近一批，支持 move/mkdir/rename；仅限当前会话的日志） =================
    if ($action === 'classify_undo') {
        $pdo = getDB();
        // 会话制：本会话没做过任何整理就没有撤销资格（旧会话日志在 status 检查时已清）
        if (empty($_SESSION['pn_classify_sess'])) {
            jsonOut(array('success' => false, 'message' => '本次会话没有可撤销的整理操作'));
        }
        $st = $pdo->prepare("SELECT id, detail FROM pn_ai_actions WHERE user_id = ? AND action = 'classify' ORDER BY id DESC LIMIT 1");
        $st->execute(array($uid));
        $log = $st->fetch();
        if (!$log) jsonOut(array('success' => false, 'message' => '没有可撤销的 AI 整理操作'));

        $detail = json_decode((string)$log['detail'], true);
        // 新协议：undo 序列；旧协议：restored 序列
        $undoList = array();
        if (is_array($detail) && isset($detail['undo']) && is_array($detail['undo'])) $undoList = $detail['undo'];
        elseif (is_array($detail) && isset($detail['restored']) && is_array($detail['restored'])) {
            foreach ($detail['restored'] as $r) {
                if (is_array($r)) $undoList[] = array('undo' => 'move', 'note_id' => isset($r['note_id']) ? $r['note_id'] : 0, 'from_folder_id' => isset($r['from_folder_id']) ? $r['from_folder_id'] : null);
            }
        }
        if (empty($undoList)) jsonOut(array('success' => false, 'message' => '该批操作没有可还原的条目'));

        $pdo->beginTransaction();
        try {
            $restoredCount = 0;
            // 逆序还原（undo 序列是执行顺序，倒着回滚才能正确处理嵌套创建）
            for ($i = count($undoList) - 1; $i >= 0; $i--) {
                $u = $undoList[$i];
                if (!is_array($u) || !isset($u['undo'])) continue;
                if ($u['undo'] === 'move') {
                    $nid = (int)$u['note_id'];
                    $fromFid = ($u['from_folder_id'] === null || !isset($u['from_folder_id'])) ? null : (int)$u['from_folder_id'];
                    if ($nid <= 0) continue;
                    $st = $pdo->prepare("SELECT id FROM pn_notes WHERE id = ? AND user_id = ?");
                    $st->execute(array($nid, $uid));
                    if (!$st->fetch()) continue;
                    $st = $pdo->prepare("UPDATE pn_notes SET folder_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                    $st->execute(array($fromFid, $nid, $uid));
                    $restoredCount++;
                } elseif ($u['undo'] === 'rename') {
                    $fid = (int)$u['folder_id'];
                    $st = $pdo->prepare("UPDATE pn_folders SET name = ? WHERE id = ? AND user_id = ?");
                    $st->execute(array((string)$u['old_name'], $fid, $uid));
                    $restoredCount++;
                } elseif ($u['undo'] === 'rmdir') {
                    // 删除本批新建的文件夹：内容上移一级（与手动删文件夹一致，绝不丢便签）
                    $fid = (int)$u['folder_id'];
                    $st = $pdo->prepare("SELECT parent_id FROM pn_folders WHERE id = ? AND user_id = ?");
                    $st->execute(array($fid, $uid));
                    $parentId = $st->fetchColumn();
                    if ($parentId === false) continue;   // 已被手动删除
                    $parentId = ($parentId === null) ? null : (int)$parentId;
                    $pdo->prepare("UPDATE pn_notes SET folder_id = ? WHERE folder_id = ? AND user_id = ?")->execute(array($parentId, $fid, $uid));
                    $pdo->prepare("UPDATE pn_folders SET parent_id = ? WHERE parent_id = ? AND user_id = ?")->execute(array($parentId, $fid, $uid));
                    $pdo->prepare("DELETE FROM pn_folders WHERE id = ? AND user_id = ?")->execute(array($fid, $uid));
                    $restoredCount++;
                } elseif ($u['undo'] === 'delete_folder') {
                    // 重建被删的整个子树（folders 快照已按逆序排列：父级先重建），再还原便签归属
                    if (!empty($u['folders']) && is_array($u['folders'])) {
                        $ins = $pdo->prepare("INSERT INTO pn_folders (id, user_id, parent_id, name, sort_order, created_at) VALUES (?, ?, ?, ?, ?, NOW())
                                              ON DUPLICATE KEY UPDATE name = VALUES(name), parent_id = VALUES(parent_id)");
                        foreach ($u['folders'] as $f) {
                            $ins->execute(array((int)$f['id'], $uid, $f['parent_id'], (string)$f['name'], (int)$f['sort_order']));
                        }
                    }
                    if (!empty($u['note_moves']) && is_array($u['note_moves'])) {
                        $upd = $pdo->prepare("UPDATE pn_notes SET folder_id = ? WHERE id = ? AND user_id = ?");
                        foreach ($u['note_moves'] as $nm) {
                            $upd->execute(array($nm['folder_id'], (int)$nm['id'], $uid));
                        }
                    }
                    $restoredCount++;
                } elseif ($u['undo'] === 'create_note') {
                    // 重建被删便签（保留原 id）
                    $n = $u['note'];
                    $pdo->prepare("INSERT INTO pn_notes (id, user_id, title, content, color, pinned, folder_id, sort_order, created_at, updated_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                                   ON DUPLICATE KEY UPDATE title = VALUES(title)")
                        ->execute(array((int)$n['id'], $uid, (string)$n['title'], (string)$n['content'], (string)$n['color'], (int)$n['pinned'], $n['folder_id'], (int)$n['sort_order']));
                    $restoredCount++;
                } elseif ($u['undo'] === 'sort_notes') {
                    $upd = $pdo->prepare("UPDATE pn_notes SET sort_order = ? WHERE id = ? AND user_id = ?");
                    foreach ($u['order'] as $o2) { $upd->execute(array((int)$o2['sort_order'], (int)$o2['id'], $uid)); }
                    $restoredCount++;
                } elseif ($u['undo'] === 'color') {
                    $pdo->prepare("UPDATE pn_notes SET color = ? WHERE id = ? AND user_id = ?")
                        ->execute(array((string)$u['old_color'], (int)$u['note_id'], $uid));
                    $restoredCount++;
                } elseif ($u['undo'] === 'pin') {
                    $pdo->prepare("UPDATE pn_notes SET pinned = ? WHERE id = ? AND user_id = ?")
                        ->execute(array((int)$u['old_pinned'], (int)$u['note_id'], $uid));
                    $restoredCount++;
                }
            }
            // 删除这条溯源日志
            $st = $pdo->prepare("DELETE FROM pn_ai_actions WHERE id = ?");
            $st->execute(array((int)$log['id']));
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonOut(array('success' => false, 'message' => '撤销执行失败'), 500);
        }

        // 是否还有同用户的可撤销记录
        $st = $pdo->prepare("SELECT COUNT(*) FROM pn_ai_actions WHERE user_id = ? AND action = 'classify'");
        $st->execute(array($uid));
        $still = (int)$st->fetchColumn() > 0;

        jsonOut(array('success' => true, 'restored' => $restoredCount, 'still_more' => $still));
    }

    // ================= AI 整理状态（是否有待撤销；新会话首次查询即清理旧会话日志） =================
    if ($action === 'classify_status') {
        $pdo = getDB();
        $sessKey = 'pn_classify_sess';
        if (!isset($_SESSION[$sessKey])) {
            // 新浏览器会话：旧会话的撤销资格作废，日志直接清掉（不占服务器存储）
            $pdo->prepare("DELETE FROM pn_ai_actions WHERE user_id = ? AND action = 'classify'")->execute(array($uid));
            $has = false;
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM pn_ai_actions WHERE user_id = ? AND action = 'classify'");
            $st->execute(array($uid));
            $has = (int)$st->fetchColumn() > 0;
        }
        jsonOut(array('success' => true, 'has_pending' => $has));
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
            $ownBaseUrl = isset($input['own_base_url']) ? substr(trim((string)$input['own_base_url']), 0, 255) : '';
            if ($ownBaseUrl !== '' && ownEndpoint($ownBaseUrl) === '') {
                jsonOut(array('success' => false, 'message' => '接口地址无效：仅支持公网 http/https 地址，禁止内网、本地或无法解析的地址'));
            }
            $fields['own_base_url'] = $ownBaseUrl;
            $fields['own_api_key'] = isset($input['own_api_key']) ? substr(trim((string)$input['own_api_key']), 0, 255) : '';
            $fields['own_model'] = isset($input['own_model']) ? substr(trim((string)$input['own_model']), 0, 100) : '';
            $proxy = isset($input['own_proxy']) ? trim((string)$input['own_proxy']) : '';
            if ($proxy !== '' && !preg_match('#^https://#i', $proxy)) $proxy = '';
            $fields['own_proxy'] = substr($proxy, 0, 255);
            $fields['send_time'] = !empty($input['send_time']) ? 1 : 0;
            $fields['style'] = isset($input['style']) ? substr(trim((string)$input['style']), 0, 500) : '';
            // 深度思考 + 自定义 Body（仅自有 Key 模式生效）
            $fields['own_deep_think'] = !empty($input['own_deep_think']) ? 1 : 0;
            $bEnabled = !empty($input['own_body_enabled']);
            $bKey = isset($input['own_body_key']) ? substr(trim((string)$input['own_body_key']), 0, 64) : '';
            $bJson = isset($input['own_body_json']) ? substr(trim((string)$input['own_body_json']), 0, 500) : '';
            if ($bEnabled) {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]{0,63}$/', $bKey)) {
                    jsonOut(array('success' => false, 'message' => '自定义 Body Key 格式无效（字母开头，可含数字/下划线/点/横线，最长 64 字符）'));
                }
                $bVal = json_decode($bJson, true);
                if ($bVal === null && strtolower($bJson) !== 'null') {
                    jsonOut(array('success' => false, 'message' => '自定义 Body JSON 解析失败，请填写合法 JSON（如 true / "high" / {"type":"enabled"}）'));
                }
            }
            $fields['own_body_enabled'] = $bEnabled ? 1 : 0;
            $fields['own_body_key'] = $bKey;
            $fields['own_body_json'] = $bJson;
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
        // 澄清问答历史：[{q, a}, ...]，轮数不限（人工熔断），每轮 q/a 限长；最多保留 10 轮防滥用
        $clarifyRounds = array();
        $cr = (isset($input['clarifyRounds']) && is_array($input['clarifyRounds'])) ? $input['clarifyRounds'] : array();
        foreach ($cr as $round) {
            if (!is_array($round)) continue;
            $q = isset($round['q']) ? trim((string)$round['q']) : '';
            $a = isset($round['a']) ? trim((string)$round['a']) : '';
            if ($q !== '') {
                $q = function_exists('mb_substr') ? mb_substr($q, 0, 200, 'UTF-8') : substr($q, 0, 400);
                $a = function_exists('mb_substr') ? mb_substr($a, 0, 500, 'UTF-8') : substr($a, 0, 1000);
                $clarifyRounds[] = array('q' => $q, 'a' => $a);
            }
            if (count($clarifyRounds) >= AI_CLARIFY_INPUT_MAX) break;
        }
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
        // 空便签不拦截但严禁 A 格式（SEARCH 无原文可匹配）：创作走 B，拿不准走 C 澄清；模型误用 A 由空便签兜底接管（protocol.md v3）

        // ===== 选择上游：平台密钥 or 用户自有 Key =====
        $mode = (isset($prefs['mode']) && $prefs['mode'] === 'own') ? 'own' : 'platform';
        $pdo = getDB();
        $usage = null;
        $keyRow = null;
        $extra = array();

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
            // 自有 Key 模式：服务器只连管理员配置的透明代理（CF Worker），目标仅作为路径段转发，不直连用户目标
            $target = ownEndpoint(isset($prefs['ownBaseUrl']) ? $prefs['ownBaseUrl'] : '');
            $key  = isset($prefs['ownApiKey']) ? trim((string)$prefs['ownApiKey']) : '';
            $model = isset($prefs['ownModel']) ? trim((string)$prefs['ownModel']) : '';
            if ($target === '') {
                jsonOut(array('success' => false, 'message' => '自有 Key 接口地址无效：仅支持公网 http/https 地址，禁止内网、本地或无法解析的地址'));
            }
            if ($key === '' || $model === '') {
                jsonOut(array('success' => false, 'message' => '自有 Key 模式需要在「AI 设置」里填写 API Key 和模型名'));
            }

            // 自有模式防刷限流：每用户每日（北京时间 8 点周期）500 次
            $period = aiPeriodNow();
            $row = getUserAiPrefs($uid);
            $ownUsed = ($row && $row['own_period'] === $period) ? (int)$row['own_used'] : 0;
            if ($ownUsed >= AI_OWN_DAILY_LIMIT) {
                jsonOut(array('success' => false, 'message' => '今日自有 Key 调用次数已达上限（' . AI_OWN_DAILY_LIMIT . '，北京时间 8:00 重置）',
                              'usage' => array('used' => $ownUsed, 'limit' => AI_OWN_DAILY_LIMIT)));
            }
            $usage = array('used' => $ownUsed, 'limit' => AI_OWN_DAILY_LIMIT);

            // 强制走透明代理：未配置则拒绝自有 Key 模式，杜绝服务器直连用户目标（SSRF 根因）
            $proxyPrefix = rtrim(trim(getSetting('ai_own_proxy', '')), '/');
            if (!preg_match('#^https://#i', $proxyPrefix)) {
                jsonOut(array('success' => false, 'message' => '服务器未配置自有 Key 透明代理，请联系管理员在「AI 设置」中填写 https:// 的 CF Worker 代理地址'));
            }
            $url = $proxyPrefix . '/' . $target;
        }

        // ===== 额外请求体参数 =====
        // 深度思考：所有模式（平台密钥/管理员/自有 Key）均生效；自定义 Body 同名键可覆盖
        if (!empty($prefs['deepThink'])) {
            $extra['enable_thinking'] = true;
        }
        // 自定义 Body：仅自有 Key 模式生效（平台/管理员上游不受用户自定义参数影响）
        if ($mode === 'own' && !empty($prefs['bodyEnabled'])) {
            $bKey = isset($prefs['bodyKey']) ? trim((string)$prefs['bodyKey']) : '';
            $bJson = isset($prefs['bodyJson']) ? trim((string)$prefs['bodyJson']) : '';
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]{0,63}$/', $bKey)) {
                jsonOut(array('success' => false, 'message' => '自定义 Body Key 格式无效（字母开头，可含数字/下划线/点/横线，最长 64 字符）'));
            }
            $bVal = json_decode($bJson, true);
            if ($bVal === null && strtolower($bJson) !== 'null') {
                jsonOut(array('success' => false, 'message' => '自定义 Body JSON 解析失败，请填写合法 JSON（如 true / "high" / {"type":"enabled"}）'));
            }
            $extra[$bKey] = $bVal;
        }

        $system = "你是一个便签编辑代理。用户会给你一篇 Markdown 便签（可能为空）和一条编辑指令，你要精准地完成编辑。\n"
            . "【输出格式（三选一）】\n"
            . "A. 局部修改（默认首选）：只改动需要改的地方。每个改动输出一个替换块，格式严格如下：\n"
            . "<<<SEARCH>>>\n"
            . "（便签原文中要被修改的那段文字，必须与原文逐字一致，包括空格、换行、标点）\n"
            . "<<<REPLACE>>>\n"
            . "（修改后的文字）\n"
            . "<<<END>>>\n"
            . "可以有多个替换块，按顺序排列。SEARCH 段尽量短且在全文中唯一。\n"
            . "B. 全文重写：仅当指令要求整体重构、全文翻译、全文总结、从零创作时，才直接输出完整的新便签全文。\n"
            . "C. 澄清提问（只要存在任何疑问就必须使用，优先级最高，出现时必须只输出这个）：\n"
            . "<<<CLARIFY>>>\n"
            . "（一个问题一行，最多 3 个，简洁具体；不要重复已经问过的问题）\n"
            . "<<<END>>>\n"
            . "存在任何疑问就必须先提问：指令有歧义、缺关键信息（主题、风格、长度、格式、语言等）、无法确定用户要改什么、或对用户意图没有把握时，一律用 C 提问，绝对不能猜、不能编造、不能自行假设，宁可多问一句，不可错改一字。直到用户回答后信息足够再执行 A 或 B。\n"
            . "【先问后做，二者互斥】C 是独立的一轮输出：提问那一轮绝不能同时生成任何正文；反过来，一旦选择 A 或 B，输出里就绝不能再出现任何提问、确认、选项或结尾寒暄（如「需要哪种风格？」「有其他想法可补充」一律禁止）。拿不准就先用 C 问清楚，问完再动手，绝不许先编一版内容再附一句反问。\n"
            . "【硬性规则】\n"
            . "1. 绝对禁止删除、改写、移动用户已有的链接、URL、HTML 标签、图片/音频/视频/iframe 嵌入和代码块，除非指令明确要求处理它们\n"
            . "2. 用户没让改的部分必须一字不动，只做最小限度的必要修改，禁止顺手润色或重排\n"
            . "3. 不要输出任何解释、前言、结束语，不要用代码围栏（```）包裹整个输出\n"
            . "4. 保持 Markdown 格式；便签支持：标题/加粗/斜体/列表/引用/链接/图片/任务列表/代码块\n"
            . "5. 便签标题不在你负责范围内，只编辑正文\n"
            . "6. 便签内容为空时【严禁使用 A 格式】：空便签没有任何原文可供 SEARCH 匹配，输出替换块必定失败。指令是创作新内容就直接用 B 格式输出完整新全文；指令像是要编辑已有内容但无从下手时，用 C 澄清提问确认用户想要什么\n"
            . "7. 选择 B（全文重写）时，输出只能是新便签全文本身：开头与结尾都不得有任何提问、选项、说明或客套话；若对风格/格式/长度等拿不准，必须改用 C 先提问，严禁先输出一版再反问\n"
            . "【工具调用（可选，仅限需要查看其他便签或文件夹内容时）】\n"
            . "你可以调用工具查看文件夹结构或某条便签的内容（只读），调用格式：\n"
            . "<<<TOOL>>>\n"
            . "{\"name\":\"list_folder\",\"path\":\"工作/项目A\"}   —— 列出该路径下的子文件夹和便签清单（主页填 \"主页\"）\n"
            . "{\"name\":\"read_note\",\"id\":123}   —— 查看 id 为 123 的便签完整内容\n"
            . "注意：工具调用的那一轮输出必须是全部内容；收到结果后最多还需要连续调用 4 次，最后必须产出 A/B/C 编辑结果。没有需要查看的内容时不要调用工具。";
        if ($style !== '') {
            $system .= "\n【用户风格偏好】在不违背上述硬性规则的前提下，尽量按以下风格完成编辑：" . $style;
        }
        if ($timeStr !== '') {
            $system .= "\n【当前时间】现在是 " . $timeStr . "（用户本地时间）。涉及时间、日期、星期、节假日等内容的编辑请以此为准，不要虚构时间。";
        }

        $contentN = str_replace("\r\n", "\n", $content);
        $clenN = function_exists('mb_strlen') ? mb_strlen($contentN, 'UTF-8') : strlen($contentN);
        $result = null;

        // ===== SSE 流式开始（protocol v5）：预检全部通过，进入实际 AI 调用；此后管线内统一走 aiOut =====
        // sseStart 内会 session_write_close() 释放锁，节流标记必须在此之前落盘
        $_SESSION['ai_last'] = $now;
        sseStart();
        // 思考标签过滤：在 <think>...</think> 内的 delta 不转发给前端预览
        $_thinkIn = false;
        $onDelta = function ($t) use (&$_thinkIn) {
            while ($t !== '') {
                if ($_thinkIn) {
                    $end = stripos($t, '</think>');
                    if ($end === false) return;   // 整段都在 think 里
                    $t = substr($t, $end + 8);
                    $_thinkIn = false;
                } else {
                    $start = stripos($t, '<think>');
                    if ($start === false) { sseSend('delta', array('t' => $t)); return; }
                    if ($start > 0) sseSend('delta', array('t' => substr($t, 0, $start)));
                    $_thinkIn = true;
                    $t = substr($t, $start + 7);
                }
            }
        };

        // ===== 长文分段 agent 模式：切块逐段下达指令（附全文结构大纲），逐段收集替换块后在全文统一应用 =====
        if ($clenN > AI_CHUNK_THRESHOLD) {
            @set_time_limit(600);
            $chunks = aiChunkText($contentN);
            $n = count($chunks);
            $outline = '';
            foreach ($chunks as $ci => $ck) {
                $first = trim(strtok($ck, "\n"));
                if ($first === '') $first = trim($ck);
                $first = function_exists('mb_substr') ? mb_substr($first, 0, 24, 'UTF-8') : substr($first, 0, 48);
                $outline .= ($ci + 1) . '. ' . $first . "\n";
            }
            $segSystem = $system . "\n【分段模式】这是一篇长文，已分 " . $n . " 段，你只处理「本段内容」这一个段。SEARCH 段必须逐字复制自「本段内容」。若本段完全无需修改，只输出四个字：本段无需修改。";

            $newContent = $contentN;
            $applied = 0; $failed = 0;
            foreach ($chunks as $ci => $ck) {
                sseSend('phase', array('t' => '🧩 长文分段：第 ' . ($ci + 1) . '/' . $n . ' 段…'));
                $segMsgs = array(
                    array('role' => 'system', 'content' => $segSystem),
                    array('role' => 'user', 'content' => "【便签标题】" . ($title !== '' ? $title : '(无标题)') . "\n"
                        . "【全文结构（共" . $n . "段，你处理第 " . ($ci + 1) . " 段）】\n" . $outline
                        . "【本段内容】\n" . ($ck !== '' ? $ck : '(空)') . "\n\n"
                        . "【编辑指令】" . $instruction),
                );
                // 注入澄清问答历史（若有），AI 见过前文不再重复提问
                if (!empty($clarifyRounds)) {
                    foreach (aiClarifyContext($clarifyRounds) as $m) $segMsgs[] = $m;
                }
                for ($att = 1; $att <= 2; $att++) {
                    $r = aiChat($url, $key, $model, $segMsgs, 16000, $extra, $onDelta);
                    if (!$r['ok']) {
                        aiOut(array('success' => false, 'message' => '第 ' . ($ci + 1) . ' 段处理失败：' . $r['err'], 'usage' => $usage));
                    }
                    $_SESSION['ai_last'] = $now;

                    $text = trim($r['text']);
                    if (preg_match('/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i', $text, $m)) $text = trim($m[1]);
                    // 澄清提问：拿不准就继续问，轮数不限（每轮需用户手动回答，人工熔断）
                    $clarify = aiParseClarify($text);
                    if (!empty($clarify)) {
                        aiOut(array('success' => false, 'need_clarify' => true, 'questions' => $clarify,
                                      'clarifyRounds' => $clarifyRounds,
                                      'usage' => $usage));
                    }
                    if ($text === '') {
                        if ($att < 2) {
                            $segMsgs[] = array('role' => 'assistant', 'content' => '（上一轮返回为空）');
                            $segMsgs[] = array('role' => 'user', 'content' => '你上一轮返回了空内容，请重新处理本段。');
                            continue;
                        }
                        $failed++;
                        break;
                    }
                    // 无需修改：跳过本段
                    if (!preg_match('/<<<SEARCH>>>/i', $text) && preg_match('/无需修改|没有需要|不涉及修改|不用修改/is', $text)) {
                        break;
                    }
                    if (preg_match_all('/<<<SEARCH>>>\s*\n([\s\S]*?)\n?<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/i', $text, $mm, PREG_SET_ORDER)) {
                        $pairs = array();
                        foreach ($mm as $b) {
                            $pairs[] = array(
                                str_replace("\r\n", "\n", rtrim($b[1], "\n")),
                                str_replace("\r\n", "\n", rtrim($b[2], "\n")),
                            );
                        }
                        $seq = aiApplyBlocksSeq($newContent, $pairs);
                        $newContent = $seq['content'];
                        $cApplied = $seq['applied'];
                        $applied += $seq['applied'];
                        $failed += $seq['failed'];
                        if ($cApplied > 0) break;
                        if ($att < 2) {
                            $segMsgs[] = array('role' => 'assistant', 'content' => $text);
                            $segMsgs[] = array('role' => 'user', 'content' => '你输出的替换块无法在「本段内容」中精确匹配。SEARCH 段必须逐字复制本段原文（含空格、换行、标点），请重新输出。');
                            continue;
                        }
                    } else {
                        // 无替换块：视输出为本段整体重写（块是原文精确子串，可在全文中定位）
                        $pos = strpos($newContent, $ck);
                        if ($pos !== false) {
                            $newContent = substr_replace($newContent, aiCleanOutput($text), $pos, strlen($ck));
                            $applied++;
                        } else {
                            $failed++;
                        }
                        break;
                    }
                }
            }
            $result = array(
                'success' => true,
                'mode'    => 'edits',
                'applied' => $applied,
                'failed'  => $failed,
                'content' => $newContent,
                'usage'   => $usage,
                'chunked' => true,
                'chunks'  => $n,
                'attempts' => 1,
            );
        }

        if ($result === null) {
        $userMsg = "【便签标题】" . ($title !== '' ? $title : '(无标题)') . "\n"
            . "【当前便签内容】\n" . ($content !== '' ? $content : '(空便签)') . "\n\n"
            . "【编辑指令】" . $instruction;

        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $userMsg),
        );
        // 注入澄清问答历史（若有），AI 见过前文不再重复提问
        if (!empty($clarifyRounds)) {
            foreach (aiClarifyContext($clarifyRounds) as $m) $messages[] = $m;
        }

        // 自纠错循环：SEARCH 块匹配失败时，带上上下文告诉 AI 哪里错了，最多 3 轮
        $maxAttempts = 3;
        $lastErrText = '';
        $result = null;
        $toolRounds = 0;   // 本轮工具调用次数（上限 5）
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            sseSend('phase', array('t' => $attempt > 1 ? '🔁 自动纠错第 ' . ($attempt - 1) . ' 次…' : '🤖 正在生成…'));
            $r = aiChat($url, $key, $model, $messages, 16000, $extra, $onDelta);

            if (!$r['ok']) {
                aiOut(array('success' => false, 'message' => $r['err'], 'usage' => $usage));
            }

            $_SESSION['ai_last'] = $now;

            // 清理模型常见的围栏包裹
            $text = trim($r['text']);
            if (preg_match('/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i', $text, $m)) {
                $text = trim($m[1]);
            }
            // ===== 工具调用块：<<<TOOL>>>{json}<<<END>>> =====
            if ($toolRounds < 5 && preg_match('/<<<TOOL>>>\s*([\s\S]*?)\s*<<<END>>>/i', $text, $tm)) {
                $toolJson = trim($tm[1]);
                $toolCall = json_decode($toolJson, true);
                if (is_array($toolCall) && isset($toolCall['name'])) {
                    $toolRounds++;
                    sseSend('phase', array('t' => '🔧 工具调用：' . (string)$toolCall['name'] . '...'));
                    $toolResult = aiRunTool((string)$toolCall['name'], $toolCall, $pdo, $uid);
                    // 工具结果回喂
                    $messages[] = array('role' => 'assistant', 'content' => $text);
                    $messages[] = array('role' => 'user', 'content' => '【工具结果】' . (string)$toolCall['name'] . "\n" . $toolResult);
                    continue;   // 工具回复后让 AI 继续生成
                }
                // JSON 解析失败：当作普通文本继续处理
            }
            // 澄清提问：拿不准就继续问，轮数不限（每轮需用户手动回答，人工熔断）
            $clarify = aiParseClarify($text);
            if (!empty($clarify)) {
                aiOut(array('success' => false, 'need_clarify' => true, 'questions' => $clarify,
                              'clarifyRounds' => $clarifyRounds,
                              'usage' => $usage));
            }
            if ($text === '') {
                $lastErrText = 'AI 返回了空内容';
                if ($attempt < $maxAttempts) {
                    $messages[] = array('role' => 'assistant', 'content' => '（上一轮返回为空）');
                    $messages[] = array('role' => 'user', 'content' => '你上一轮返回了空内容，请重新按格式输出编辑结果。');
                    continue;
                }
                aiOut(array('success' => false, 'message' => $lastErrText, 'usage' => $usage));
            }

            // ===== 局部修改协议：解析 <<<SEARCH>>>/<<<REPLACE>>>/<<<END>>> 块 =====
            $contentN = str_replace("\r\n", "\n", $content);
            if (preg_match_all('/<<<SEARCH>>>\s*\n([\s\S]*?)\n?<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/i', $text, $mm, PREG_SET_ORDER)) {
                $newContent = $contentN;
                $pairs = array();
                foreach ($mm as $b) {
                    $pairs[] = array(
                        str_replace("\r\n", "\n", rtrim($b[1], "\n")),
                        str_replace("\r\n", "\n", rtrim($b[2], "\n")),
                    );
                }
                $seq = aiApplyBlocksSeq($newContent, $pairs);
                $newContent = $seq['content'];
                $applied = $seq['applied'];
                $failed = $seq['failed'];
                $badSearches = $seq['bad'];
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
                // 空便签兜底（protocol v3）：空便签没有原文可匹配，模型误用 A 时把全部 REPLACE 段拼成新全文（视同 B），不进入重试
                if (trim($content) === '') {
                    $rebuilt = '';
                    foreach ($mm as $b) {
                        $part = str_replace("\r\n", "\n", rtrim($b[2], "\n"));
                        if (trim($part) !== '') $rebuilt .= ($rebuilt !== '' ? "\n\n" : '') . $part;
                    }
                    if (trim($rebuilt) !== '') {
                        $result = array(
                            'success' => true,
                            'mode'    => 'full',
                            'content' => $rebuilt,
                            'usage'   => $usage,
                            'attempts' => $attempt,
                        );
                        break;
                    }
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
                aiOut(array('success' => false, 'message' => $lastErrText . '，已自动重试 ' . $maxAttempts . ' 轮仍失败，请重试或换个说法', 'usage' => $usage));
            }

            // ===== 全文重写模式 =====
            $result = array('success' => true, 'mode' => 'full', 'content' => aiCleanOutput($text), 'usage' => $usage, 'attempts' => $attempt);
            break;        }
        } // 结束单发模式（$result === null 分支）

        if ($result === null) {
            aiOut(array('success' => false, 'message' => $lastErrText !== '' ? $lastErrText : 'AI 编辑失败', 'usage' => $usage));
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

        aiOut($result);
    }

    jsonOut(array('success' => false, 'message' => '未知操作'));
} catch (Exception $e) {
    aiOut(array('success' => false, 'message' => '服务器内部错误'));
}
