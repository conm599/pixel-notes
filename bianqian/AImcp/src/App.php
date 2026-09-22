<?php

require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/StoreFactory.php';
require_once __DIR__ . '/MySqlStore.php';
require_once __DIR__ . '/JsonFileStore.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/Embeddings.php';
require_once __DIR__ . '/Rerank.php';
require_once __DIR__ . '/Instructions.php';

class App
{
    private $config;
    private $store;
    private $embeddings;
    private $rerank;
    private $instructions;
    private $textSearch;

    public function __construct()
    {
        $cfgPath = dirname(__DIR__) . '/config.php';
        if (!is_file($cfgPath)) {
            throw new RuntimeException('缺少 config.php，请参考 config.example.php 创建');
        }
        $loaded = include $cfgPath;
        $this->config = is_array($loaded) ? $loaded : array();

        $this->initStore();
        $embCfg = isset($this->config['embeddings']) && is_array($this->config['embeddings'])
            ? $this->config['embeddings']
            : array();
        $this->embeddings = new Embeddings($embCfg);
        $rrCfg = isset($this->config['rerank']) && is_array($this->config['rerank'])
            ? $this->config['rerank']
            : array();
        $this->rerank = new Rerank($rrCfg);
        $this->instructions = Instructions::fromConfig(isset($this->config['misc']) && is_array($this->config['misc']) ? $this->config['misc'] : array());
        $this->textSearch = new Search();
    }

    private function initStore(): void
    {
        $storage = isset($this->config['storage']) && is_array($this->config['storage'])
            ? $this->config['storage']
            : array();
        $this->store = StoreFactory::create($storage);
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function embeddings(): Embeddings
    {
        return $this->embeddings;
    }

    public function rerank(): Rerank
    {
        return $this->rerank;
    }

    public function instructions(): Instructions
    {
        return $this->instructions;
    }

    public function search(): Search
    {
        return $this->textSearch;
    }

    public function apiToken(): string
    {
        return isset($this->config['api_token']) ? (string) $this->config['api_token'] : '';
    }

    public function adminPassword(): string
    {
        return isset($this->config['admin_password']) ? (string) $this->config['admin_password'] : '';
    }

    public function config(): array
    {
        return $this->config;
    }

    /** 记录一次检索的命中情况到日志文件（追加一行一条 JSON） */
    public function logSearchHit(string $query, string $mode, array $hitIds): void
    {
        $misc = isset($this->config['misc']) && is_array($this->config['misc']) ? $this->config['misc'] : array();
        $path = isset($misc['search_log_path']) && trim((string)$misc['search_log_path']) !== ''
            ? $misc['search_log_path']
            : dirname(__DIR__) . '/data/search_log.jsonl';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            if (!is_dir($dir)) {
                return;
            }
        }
        $record = array(
            'ts' => (int) (microtime(true) * 1000),
            'query' => $query,
            'mode' => $mode,
            'hit_ids' => $hitIds,
        );
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $handle = @fopen($path, 'a');
        if (is_resource($handle)) {
            flock($handle, LOCK_EX);
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function searchConfig(string $key, $default = null)
    {
        if (isset($this->config['search']) && is_array($this->config['search'])
            && array_key_exists($key, $this->config['search'])) {
            return $this->config['search'][$key];
        }
        return $default;
    }

    public function rerankConfig(string $key, $default = null)
    {
        if (isset($this->config['rerank']) && is_array($this->config['rerank'])
            && array_key_exists($key, $this->config['rerank'])) {
            return $this->config['rerank'][$key];
        }
        return $default;
    }
}