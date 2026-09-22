<?php

class JsonFileStore implements Store
{
    private string $path;

    public function __construct(array $cfg)
    {
        $this->path = isset($cfg['path']) ? (string) $cfg['path'] : '';
    }

    public function add(array $entry): array
    {
        $this->withFile(true, function (array $data) use ($entry): array {
            $items = $data['items'];
            $replaced = false;
            foreach ($items as $index => $item) {
                if (is_array($item) && isset($item['id']) && (string) $item['id'] === (string) $entry['id']) {
                    $items[$index] = $entry;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $items[] = $entry;
            }
            return ['items' => $items];
        });
        return $entry;
    }

    public function update(string $id, array $entry): bool
    {
        $found = false;
        $this->withFile(true, function (array $data) use ($id, $entry, &$found): array {
            $items = $data['items'];
            foreach ($items as $index => $item) {
                if (is_array($item) && isset($item['id']) && (string) $item['id'] === $id) {
                    $items[$index] = $entry;
                    $found = true;
                    break;
                }
            }
            return ['items' => $items];
        });
        return $found;
    }

    public function get(string $id): ?array
    {
        return $this->withFile(false, function (array $data) use ($id): ?array {
            foreach ($data['items'] as $item) {
                if (is_array($item) && isset($item['id']) && (string) $item['id'] === $id) {
                    return $this->normalizeEntry($item);
                }
            }
            return null;
        });
    }

    public function list(int $limit = 20, int $offset = 0, ?string $tag = null): array
    {
        return array_slice($this->filterTag($this->sortEntries($this->loadAll()), $tag), $offset, $limit);
    }

    public function count(?string $tag = null): int
    {
        return count($this->filterTag($this->loadAll(), $tag));
    }

    public function delete(string $id): bool
    {
        $result = $this->withFile(true, function (array $data) use ($id) {
            $kept = [];
            $deleted = false;
            foreach ($data['items'] as $item) {
                if (is_array($item) && isset($item['id']) && (string) $item['id'] === $id) {
                    $deleted = true;
                    continue;
                }
                $kept[] = $item;
            }
            if (!$deleted) {
                return null;
            }
            return ['items' => $kept];
        });
        return is_array($result);
    }

    public function all(): array
    {
        return $this->sortEntries($this->loadAll());
    }

    public function ping(): bool
    {
        if ($this->path === '') {
            return false;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        return is_writable($dir);
    }

    private function withFile(bool $persist, callable $fn)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $handle = fopen($this->path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('无法打开存储文件：' . $this->path);
        }
        flock($handle, LOCK_EX);

        $raw = stream_get_contents($handle);
        $data = ['items' => []];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                $data['items'] = array_values($decoded['items']);
            }
        }

        $result = $fn($data);

        if ($persist && is_array($result)) {
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $encoded);
                fflush($handle);
            }
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        return $result;
    }

    private function loadAll(): array
    {
        return $this->withFile(false, function (array $data): array {
            $entries = [];
            foreach ($data['items'] as $item) {
                if (is_array($item)) {
                    $entries[] = $this->normalizeEntry($item);
                }
            }
            return $entries;
        });
    }

    private function normalizeEntry(array $item): array
    {
        $vector = null;
        if (isset($item['vector']) && is_array($item['vector'])) {
            $vector = array_values(array_map('floatval', $item['vector']));
        }

        return [
            'id' => isset($item['id']) ? (string) $item['id'] : '',
            'content' => isset($item['content']) ? (string) $item['content'] : '',
            'tags' => isset($item['tags']) && is_array($item['tags']) ? array_values($item['tags']) : [],
            'metadata' => isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [],
            'vector' => $vector,
            'dim' => isset($item['dim']) && $item['dim'] !== null ? (int) $item['dim'] : null,
            'model' => isset($item['model']) && $item['model'] !== null ? (string) $item['model'] : null,
            'tokens' => isset($item['tokens']) && is_array($item['tokens']) ? array_values($item['tokens']) : [],
            'created_at' => isset($item['created_at']) ? (int) $item['created_at'] : 0,
            'updated_at' => isset($item['updated_at']) ? (int) $item['updated_at'] : 0,
        ];
    }

    private function sortEntries(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            $at = isset($a['created_at']) ? (int) $a['created_at'] : 0;
            $bt = isset($b['created_at']) ? (int) $b['created_at'] : 0;
            if ($at !== $bt) {
                return $bt <=> $at;
            }
            $ai = isset($a['id']) ? (string) $a['id'] : '';
            $bi = isset($b['id']) ? (string) $b['id'] : '';
            return strcmp($bi, $ai);
        });
        return $entries;
    }

    private function filterTag(array $entries, ?string $tag): array
    {
        if ($tag === null) {
            return $entries;
        }
        $result = [];
        foreach ($entries as $entry) {
            if (isset($entry['tags']) && is_array($entry['tags']) && in_array($tag, $entry['tags'], true)) {
                $result[] = $entry;
            }
        }
        return $result;
    }
}
