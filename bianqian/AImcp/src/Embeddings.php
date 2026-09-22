<?php

class Embeddings
{
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function isConfigured(): bool
    {
        foreach (array('base_url', 'api_key', 'model') as $k) {
            if (!isset($this->cfg[$k]) || trim((string)$this->cfg[$k]) === '') {
                return false;
            }
        }
        return true;
    }

    public function embed(string $text, string $purpose = 'query'): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('嵌入服务未配置');
        }
        $url = rtrim((string)$this->cfg['base_url'], '/');
        if (substr_compare($url, '/embeddings', -11) !== 0) {
            $url .= '/embeddings';
        }

        $body = array(
            'input' => $text,
            'model' => $this->cfg['model'],
            'encoding_format' => 'float',
        );

        $inputType = $purpose === 'passage'
            ? (isset($this->cfg['input_type_passage']) ? $this->cfg['input_type_passage'] : null)
            : (isset($this->cfg['input_type_query']) ? $this->cfg['input_type_query'] : null);
        if (is_string($inputType) && $inputType !== '') {
            $body['input_type'] = $inputType;
        }
        if (!empty($this->cfg['extra_body']) && is_array($this->cfg['extra_body'])) {
            $body = array_merge($body, $this->cfg['extra_body']);
        }

        $timeout = isset($this->cfg['timeout']) ? (int)$this->cfg['timeout'] : 20;
        $apiKey = $this->cfg['api_key'];
        $payload = json_encode($body);

        list($status, $content) = $this->postJson($url, $apiKey, $payload, $timeout);

        if ($status >= 300) {
            throw new RuntimeException('嵌入接口错误 HTTP ' . $status . ': ' . mb_substr($content, 0, 300, 'UTF-8'));
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['data'][0]['embedding']) || !is_array($decoded['data'][0]['embedding'])) {
            throw new RuntimeException('嵌入响应格式错误');
        }
        $vector = array();
        foreach ($decoded['data'][0]['embedding'] as $v) {
            $vector[] = (float)$v;
        }
        return array('vector' => $vector, 'model' => (string)$this->cfg['model']);
    }

    private function postJson(string $url, string $apiKey, string $payload, int $timeout): array
    {
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        );

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
            throw new RuntimeException('嵌入请求网络失败');
        }
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $mm)) {
                    $status = (int)$mm[1];
                    break;
                }
            }
        }
        return array($status, $content);
    }
}