<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');

define('AI_CHUNK_SIZE', 3000);

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

function aiChat($url, $key, $model, $messages, $maxTokens = 8000, $extra = null) {
    $payloadArr = array('model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => 0.4);
    if (is_array($extra)) { foreach ($extra as $k => $v) { if ($k !== 'model' && $k !== 'messages' && is_string($k)) $payloadArr[$k] = $v; } }
    $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $key),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true,
    ));
    $body = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    if (!isset($json['choices'][0]['message'])) return array('ok' => false, 'text' => '', 'err' => '上游响应异常: ' . substr((string)$body, 0, 150));
    return array('ok' => true, 'text' => (string)$json['choices'][0]['message']['content']);
}

// 1) 切块正确性：块拼接必须还原原文
$para = "这一段介绍果园里的苹果树，春天开花秋天结果，苹果挂满枝头。农民伯伯每天清晨都会来巡视，检查每一棵苹果树的长势，记录土壤湿度与病虫害情况，确保每颗苹果都能健康生长。";
$content = '';
for ($i = 1; $i <= 50; $i++) { $content .= "## 第" . $i . "章\n\n" . $para . "\n\n"; }
$len = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
echo "content length: $len\n";
$chunks = aiChunkText($content);
echo "chunks: " . count($chunks) . " (sizes: ";
$sizes = array();
foreach ($chunks as $c) $sizes[] = (function_exists('mb_strlen') ? mb_strlen($c, 'UTF-8') : strlen($c));
echo implode(',', $sizes) . ")\n";
echo "reassemble exact: " . (implode('', $chunks) === $content ? 'YES' : 'NO') . "\n";

// 2) 真实分段 agent 调用（管理员上游，指令：苹果→香蕉）
$base = rtrim(trim(getSetting('ai_base_url', '')), '/');
$key  = trim(getSetting('ai_api_key', ''));
$model = trim(getSetting('ai_model', ''));
$url = (substr($base, -17) === '/chat/completions') ? $base : $base . '/chat/completions';
$n = count($chunks);
$outline = '';
foreach ($chunks as $ci => $ck) {
    $first = trim(strtok($ck, "\n"));
    $outline .= ($ci + 1) . '. ' . mb_substr($first, 0, 24, 'UTF-8') . "\n";
}
$system = "你是一个便签编辑代理。\n【输出格式】局部修改：每个改动输出一个替换块：\n<<<SEARCH>>>\n（原文精确片段）\n<<<REPLACE>>>\n（修改后）\n<<<END>>>\n若本段完全无需修改，只输出四个字：本段无需修改。\n【硬性规则】不要输出解释，不要围栏。";
$newContent = $content; $applied = 0; $failed = 0;
foreach ($chunks as $ci => $ck) {
    $msgs = array(
        array('role' => 'system', 'content' => $system),
        array('role' => 'user', 'content' => "【便签标题】测试长文\n【全文结构（共" . $n . "段，你处理第 " . ($ci + 1) . " 段）】\n" . $outline . "【本段内容】\n" . $ck . "\n\n【编辑指令】把文中所有的『苹果』改成『香蕉』"),
    );
    $r = aiChat($url, $key, $model, $msgs, 8000, array());
    if (!$r['ok']) { echo "chunk " . ($ci+1) . " ERROR: " . $r['err'] . "\n"; exit; }
    $text = trim($r['text']);
    if (preg_match('/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i', $text, $m)) $text = trim($m[1]);
    if (!preg_match('/<<<SEARCH>>>/i', $text) && preg_match('/无需修改|没有需要/is', $text)) {
        echo "chunk " . ($ci+1) . ": 无需修改\n";
        continue;
    }
    if (preg_match_all('/<<<SEARCH>>>\s*\n([\s\S]*?)\n?<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/i', $text, $mm, PREG_SET_ORDER)) {
        $ca = 0;
        foreach ($mm as $b) {
            $s = rtrim($b[1], "\n"); $rp = rtrim($b[2], "\n");
            $pos = strpos($newContent, $s);
            if ($pos !== false) { $newContent = substr_replace($newContent, $rp, $pos, strlen($s)); $ca++; $applied++; }
            else { $failed++; }
        }
        echo "chunk " . ($ci+1) . ": blocks=" . count($mm) . " applied=$ca\n";
    } else {
        echo "chunk " . ($ci+1) . ": NO BLOCKS (" . mb_substr($text, 0, 40, 'UTF-8') . "...)\n";
    }
}
$before = substr_count($content, '苹果');
$after = substr_count($newContent, '苹果');
$banana = substr_count($newContent, '香蕉');
echo "=== RESULT ===\n";
echo "applied=$applied failed=$failed\n";
echo "苹果 before=$before after=$after ; 香蕉 in result=$banana\n";
echo "length: content=" . strlen($content) . " newContent=" . strlen($newContent) . "\n";
