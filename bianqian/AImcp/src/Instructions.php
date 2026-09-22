<?php

class Instructions
{
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function fromConfig(array $config): Instructions
    {
        $path = isset($config['instructions_path']) && trim((string)$config['instructions_path']) !== ''
            ? $config['instructions_path']
            : __DIR__ . '/../data/instructions.txt';
        return new Instructions($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function get(): string
    {
        if (!is_file($this->path)) {
            return '';
        }
        $content = @file_get_contents($this->path);
        return is_string($content) ? $content : '';
    }

    public function set(string $text): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            if (!is_dir($dir)) {
                return false;
            }
        }
        $handle = @fopen($this->path, 'c+b');
        if (!is_resource($handle)) {
            return false;
        }
        flock($handle, LOCK_EX);
        ftruncate($handle, 0);
        rewind($handle);
        $ok = fwrite($handle, $text) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $ok;
    }
}