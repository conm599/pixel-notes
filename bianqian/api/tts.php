<?php
/**
 * TTS 代理 API
 * POST {text, voice, speed, pitch, volume}
 * 成功: 200 + audio/mpeg 字节流
 * 失败: JSON {success:false, message}
 *
 * 作用：隐藏上游 Bearer 令牌（不暴露在浏览器）、登录校验、
 *       参数白名单、文本长度限制、会话级限流。
 */

require_once __DIR__ . '/../config/database.php';

define('TTS_URL', suite_cfg('tts_url', 'https://edgetts.naxid.top/v1/audio/speech'));
define('TTS_TOKEN', suite_cfg('tts_token', ''));
define('TTS_MAX_TEXT', (int)suite_cfg('tts_max_text', 5000));

startSecureSession();

header('Cache-Control: no-store');
sendSecurityHeaders();

ini_set('display_errors', '0');
error_reporting(E_ALL);

function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
    exit;
}

// 允许的音色白名单（OpenAI 音色名 → Worker 实际映射的 Edge TTS 音色）
// alloy→晓晓  echo→云希  fable→晓伊  onyx→云扬  nova→晓涵  shimmer→晓梦
// 本接口不认 Edge 原生音色名，传入未知音色会静默回落到 alloy，故必须白名单硬校验
function allowedVoices() {
    return array(
        'fable',    // 晓伊 · 女 · 活泼甜润（默认）
        'alloy',    // 晓晓 · 女 · 温柔亲切
        'nova',     // 晓涵 · 女 · 清亮甜美
        'shimmer',  // 晓梦 · 女 · 轻柔温润
        'echo',     // 云希 · 男 · 阳光少年
        'onyx',     // 云扬 · 男 · 沉稳大气
    );
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonOut(array('success' => false, 'message' => '请先登录'), 401);
    }

    // 会话级冷却限流（1.5 秒）
    $now = microtime(true);
    $last = isset($_SESSION['tts_last']) ? (float)$_SESSION['tts_last'] : 0.0;
    if ($now - $last < 1.5) {
        jsonOut(array('success' => false, 'message' => '请求太频繁，请稍候再试'), 429);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = array();

    // 文本校验
    $text = isset($input['text']) ? trim((string)$input['text']) : '';
    if ($text === '') jsonOut(array('success' => false, 'message' => '请输入要朗读的文本'), 400);
    $tlen = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($tlen > TTS_MAX_TEXT) {
        jsonOut(array('success' => false, 'message' => '文本过长（最多' . TTS_MAX_TEXT . '字）'), 400);
    }

    // 音色白名单（未知值回退 fable）
    $voice = isset($input['voice']) ? (string)$input['voice'] : '';
    if (!in_array($voice, allowedVoices(), true)) $voice = 'fable';

    // 语速 0.5 - 2.0（本接口唯一支持的调节参数）
    $speed = isset($input['speed']) ? (float)$input['speed'] : 1.0;
    if ($speed < 0.5 || $speed > 2.0) $speed = 1.0;

    $payload = json_encode(array(
        'model'  => 'tts-1',
        'input'  => $text,
        'voice'  => $voice,
        'speed'  => $speed,
        // 注：本接口不支持 pitch/volume/rate（传了也会被忽略），故不转发
    ), defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);

    // === 调用上游（优先 cURL，回退 file_get_contents）===
    $body = false; $status = 0; $ctype = '';

    if (function_exists('curl_init')) {
        $ch = curl_init(TTS_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . TTS_TOKEN,
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $body = curl_exec($ch);
        if ($body !== false) {
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        } else {
            $err = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . TTS_TOKEN . "\r\n",
            'content' => $payload,
            'timeout' => 120,
            'ignore_errors' => true,
        )));
        $body = @file_get_contents(TTS_URL, false, $ctx);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
                if (stripos($h, 'content-type:') === 0) $ctype = trim(substr($h, 13));
            }
        }
    }

    if ($body === false || $status !== 200 || stripos($ctype, 'audio/') !== 0) {
        jsonOut(array('success' => false, 'message' => '语音服务暂时不可用，请稍后再试'), 502);
    }

    $_SESSION['tts_last'] = $now;

    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;

} catch (Exception $e) {
    jsonOut(array('success' => false, 'message' => '服务器内部错误'), 500);
}