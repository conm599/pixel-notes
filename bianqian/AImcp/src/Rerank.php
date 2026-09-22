<?php

class Rerank
{
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function isConfigured(): bool
    {
        foreach (array('base_url', 'model') as $k) {
            if (!isset($this->cfg[$k]) || trim((string)$this->cfg[$k]) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 传入 query 与候选列表（每项含 id/content），让 LLM 判断相关性并返回带分的重排结果。
     */
    public function rerank(string $query, array $candidates): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('重排服务未配置');
        }
        $maxConds = isset($this->cfg['max_candidates']) ? (int)$this->cfg['max_candidates'] : 8;
        $maxLen = isset($this->cfg['max_rerank_content']) ? (int)$this->cfg['max_rerank_content'] : 300;
        $timeout = isset($this->cfg['timeout']) ? (int)$this->cfg['timeout'] : 30;

        $curs = array_slice($candidates, 0, $maxConds);
        if (!$curs) {
            return array();
        }

        // 构造给模型的候选清单
        $lines = array();
        foreach ($curs as $i => $c) {
            $content = (string)$c['content'];
            if (mb_strlen($content, 'UTF-8') > $maxLen) {
                $content = mb_substr($content, 0, $maxLen, 'UTF-8') . '…';
            }
            $lines[] = ($i + 1) . '. ' . $content;
        }

        $prompt = '判断下列候选记忆与查询的相关性。返回最相关的候选项编号，用逗号分隔，只输出编号数字，不要输出其它任何内容。' . "\n\n"
            . '查询：' . $query . "\n\n"
            . '候选：' . "\n" . implode("\n", $lines);

        $url = rtrim((string)$this->cfg['base_url'], '/');
        if (substr_compare($url, '/chat/completions', -16) !== 0) {
            $url .= '/chat/completions';
        }
        $apiKey = isset($this->cfg['api_key']) ? (string)$this->cfg['api_key'] : '';
        $model = (string)$this->cfg['model'];

        $body = array(
            'model' => $model,
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
            'temperature' => 0,
            'max_tokens' => 256,
        );
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        list($status, $content) = $this->postJson($url, $apiKey, $payload, $timeout);

        if ($status >= 300) {
            throw new RuntimeException('精排接口错误 HTTP ' . $status . ': ' . mb_substr($content, 0, 300, 'UTF-8'));
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['choices'][0]['message'])) {
            throw new RuntimeException('精排响应格式错误');
        }
        $msg = $decoded['choices'][0]['message'];
        $contentText = isset($msg['content']) ? (string)$msg['content'] : '';
        $reasonText = isset($msg['reasoning_content']) ? (string)$msg['reasoning_content'] : '';
        // content 若只是思考噪音，则回退用 reasoning_content
        $answer = trim($contentText);
        if ($answer === '' || !preg_match('/\d/', $answer)) {
            $answer = trim($reasonText);
        }
        if ($answer === '') {
            return array();
        }
        // 剥离推理模型常见前缀噪音（thinking/思考），只提编号
        $answer = preg_replace('/^[\s]*(thinking|思考|ai)[\s:\n]*/i', '', $answer);

        // 解析模型返回的编号（1-based），映射回候选
        preg_match_all('/(\d+)/', $answer, $mm);
        $pickedIds = array();
        foreach ($mm[1] as $num) {
            $idx = (int)$num - 1;
            if ($idx >= 0 && $idx < count($curs)) {
                $pickedIds[$curs[$idx]['id']] = true;
            }
        }

        // 保持候选原有顺序（嵌入已排好），只保留被模型挑中的
        $out = array();
        foreach ($curs as $c) {
            if (isset($pickedIds[$c['id']])) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** 用 LLM 判断两条记忆是否描述同一件事（用于查重）；判断失败时返回 null 表示不确定 */
    public function judgeDuplicate(string $a, string $b): ?bool
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $a = trim((string)$a);
        $b = trim((string)$b);
        if ($a === '' || $b === '') {
            return null;
        }
        // 完全逐字相同直接判定重复，不必调 LLM
        if ($a === $b) {
            return true;
        }

        $timeout = isset($this->cfg['timeout']) ? (int)$this->cfg['timeout'] : 25;
        // 截断避免每条太长
        $maxLen = isset($this->cfg['max_rerank_content']) ? (int)$this->cfg['max_rerank_content'] : 300;
        if (mb_strlen($a, 'UTF-8') > $maxLen) {
            $a = mb_substr($a, 0, $maxLen, 'UTF-8') . '…';
        }
        if (mb_strlen($b, 'UTF-8') > $maxLen) {
            $b = mb_substr($b, 0, $maxLen, 'UTF-8') . '…';
        }

        $prompt = '请判断下面两条记忆是否描述的是同一件事（即内容重复、应合并为一条）。只回答"是"或"否"，不要输出其它内容。' . "\n\n"
            . '记忆A：' . $a . "\n\n"
            . '记忆B：' . $b;

        $url = rtrim((string)$this->cfg['base_url'], '/');
        if (substr_compare($url, '/chat/completions', -16) !== 0) {
            $url .= '/chat/completions';
        }
        $apiKey = isset($this->cfg['api_key']) ? (string)$this->cfg['api_key'] : '';
        $model = (string)$this->cfg['model'];

        $body = array(
            'model' => $model,
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
            'temperature' => 0,
            'max_tokens' => 16,
        );
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        list($status, $content) = $this->postJson($url, $apiKey, $payload, $timeout);
        if ($status >= 300) {
            return null;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['choices'][0]['message'])) {
            return null;
        }
        $msg = $decoded['choices'][0]['message'];
        $answer = isset($msg['content']) ? (string)$msg['content'] : '';
        if (trim($answer) === '' && isset($msg['reasoning_content'])) {
            $answer = (string)$msg['reasoning_content'];
        }
        if (preg_match('/否/u', $answer)) {
            return false;
        }
        if (preg_match('/是/u', $answer)) {
            return true;
        }
        return null;
    }

    private function postJson(string $url, string $apiKey, string $payload, int $timeout): array
    {
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
        );
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $content = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return array($status, is_string($content) ? $content : '');
        }

        $streamHeaders = array_merge(array('Content-Length: ' . strlen($payload)), $headers);
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $streamHeaders) . "\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => $timeout,
            ),
        ));
        $content = @file_get_contents($url, false, $ctx);
        if ($content === false) {
            throw new RuntimeException('精排请求网络失败');
        }
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $mm2)) {
                    $status = (int)$mm2[1];
                    break;
                }
            }
        }
        return array($status, $content);
    }
}