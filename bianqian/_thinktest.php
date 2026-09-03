<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');
$base = rtrim(trim(getSetting('ai_base_url', '')), '/');
$key  = trim(getSetting('ai_api_key', ''));
$model = trim(getSetting('ai_model', ''));
echo "model: $model\n";
$url = (substr($base, -17) === '/chat/completions') ? $base : $base . '/chat/completions';
$payload = json_encode(array(
    'model' => $model,
    'messages' => array(
        array('role' => 'system', 'content' => '你是便签编辑代理。'),
        array('role' => 'user', 'content' => "【当前便签内容】\n今天买了牛奶\n\n【编辑指令】把这句话润色得更生动")
    ),
    'max_tokens' => 8000,
    'temperature' => 0.4,
    'enable_thinking' => true,
), JSON_UNESCAPED_UNICODE);

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $key),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => true,
));
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "http: $code\n";
$json = json_decode((string)$body, true);
if (!$json) { echo "raw: " . substr((string)$body, 0, 500) . "\n"; exit; }
$choice = isset($json['choices'][0]) ? $json['choices'][0] : array();
$msg = isset($choice['message']) ? $choice['message'] : array();
echo "keys of message: " . implode(', ', array_keys($msg)) . "\n";
echo "reasoning_content (" . (isset($msg['reasoning_content']) ? 'len=' . strlen((string)$msg['reasoning_content']) : 'ABSENT') . "): ";
if (isset($msg['reasoning_content'])) echo substr((string)$msg['reasoning_content'], 0, 300);
echo "\n---\n";
echo "content: " . substr((string)(isset($msg['content']) ? $msg['content'] : ''), 0, 400) . "\n";
echo "finish_reason: " . (isset($choice['finish_reason']) ? $choice['finish_reason'] : '?') . "\n";
if (isset($json['usage'])) echo "usage: " . json_encode($json['usage']) . "\n";
