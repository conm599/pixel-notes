<?php

class MySqlStore implements Store
{
    private ?PDO $pdo = null;

    private string $host;
    private int $port;
    private string $dbname;
    private string $user;
    private string $pass;
    private string $table;

    public function __construct(array $cfg)
    {
        $this->host = isset($cfg['host']) ? (string) $cfg['host'] : 'localhost';
        $this->port = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
        $this->dbname = isset($cfg['dbname']) ? (string) $cfg['dbname'] : '';
        $this->user = isset($cfg['user']) ? (string) $cfg['user'] : '';
        $this->pass = isset($cfg['pass']) ? (string) $cfg['pass'] : '';
        $this->table = isset($cfg['table']) ? (string) $cfg['table'] : 'ai_memories';
    }

    public function add(array $entry): array
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO `' . $this->table . '` '
            . '(`id`, `content`, `tags`, `metadata`, `vector`, `dim`, `model`, `tokens`, `created_at`, `updated_at`) '
            . 'VALUES (:id, :content, :tags, :metadata, :vector, :dim, :model, :tokens, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':id' => isset($entry['id']) ? (string) $entry['id'] : '',
            ':content' => isset($entry['content']) ? (string) $entry['content'] : '',
            ':tags' => $this->encodeJson(isset($entry['tags']) ? $entry['tags'] : null),
            ':metadata' => $this->encodeJson(isset($entry['metadata']) ? $entry['metadata'] : null),
            ':vector' => $this->encodeJson(isset($entry['vector']) ? $entry['vector'] : null),
            ':dim' => isset($entry['dim']) && $entry['dim'] !== null ? (int) $entry['dim'] : null,
            ':model' => isset($entry['model']) && $entry['model'] !== null ? (string) $entry['model'] : null,
            ':tokens' => $this->encodeJson(isset($entry['tokens']) ? $entry['tokens'] : null),
            ':created_at' => isset($entry['created_at']) ? (int) $entry['created_at'] : 0,
            ':updated_at' => isset($entry['updated_at']) ? (int) $entry['updated_at'] : 0,
        ]);
        return $entry;
    }

    public function update(string $id, array $entry): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE `' . $this->table . '` SET '
            . '`content` = :content, `tags` = :tags, `metadata` = :metadata, `vector` = :vector, '
            . '`dim` = :dim, `model` = :model, `tokens` = :tokens, `updated_at` = :updated_at '
            . 'WHERE `id` = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':content' => isset($entry['content']) ? (string) $entry['content'] : '',
            ':tags' => $this->encodeJson(isset($entry['tags']) ? $entry['tags'] : null),
            ':metadata' => $this->encodeJson(isset($entry['metadata']) ? $entry['metadata'] : null),
            ':vector' => $this->encodeJson(isset($entry['vector']) ? $entry['vector'] : null),
            ':dim' => isset($entry['dim']) && $entry['dim'] !== null ? (int) $entry['dim'] : null,
            ':model' => isset($entry['model']) && $entry['model'] !== null ? (string) $entry['model'] : null,
            ':tokens' => $this->encodeJson(isset($entry['tokens']) ? $entry['tokens'] : null),
            ':updated_at' => isset($entry['updated_at']) ? (int) $entry['updated_at'] : (int) (microtime(true) * 1000),
        ]);
        return $stmt->rowCount() > 0;
    }

    public function get(string $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM `' . $this->table . '` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return $this->decodeRow($row);
    }

    public function list(int $limit = 20, int $offset = 0, ?string $tag = null): array
    {
        return array_slice($this->filterTag($this->loadSorted(), $tag), $offset, $limit);
    }

    public function count(?string $tag = null): int
    {
        if ($tag === null) {
            $row = $this->pdo()->query('SELECT COUNT(*) AS `c` FROM `' . $this->table . '`')->fetch();
            return is_array($row) && isset($row['c']) ? (int) $row['c'] : 0;
        }
        return count($this->filterTag($this->loadSorted(), $tag));
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo()->prepare('DELETE FROM `' . $this->table . '` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function all(): array
    {
        return $this->loadSorted();
    }

    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = 'mysql:host=' . $this->host . ';port=' . $this->port . ';dbname=' . $this->dbname . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, $this->user, $this->pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->ensureTable();
        }
        return $this->pdo;
    }

    private function ensureTable(): void
    {
        if ($this->table === '' || preg_match('/[^A-Za-z0-9_]/', $this->table) === 1) {
            throw new RuntimeException('非法存储表名：' . $this->table);
        }
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
  `id` VARCHAR(64) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `tags` TEXT NULL,
  `metadata` TEXT NULL,
  `vector` MEDIUMTEXT NULL,
  `dim` INT NULL,
  `model` VARCHAR(128) NULL,
  `tokens` TEXT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->pdo->exec($sql);
    }

    private function loadSorted(): array
    {
        $rows = $this->pdo()
            ->query('SELECT * FROM `' . $this->table . '` ORDER BY `created_at` DESC, `id` DESC')
            ->fetchAll();
        $entries = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $entries[] = $this->decodeRow($row);
            }
        }
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

    private function encodeJson($value): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : null;
    }

    private function decodeRow(array $row): array
    {
        $tags = [];
        if (isset($row['tags']) && is_string($row['tags']) && $row['tags'] !== '') {
            $decoded = json_decode($row['tags'], true);
            if (is_array($decoded)) {
                $tags = array_values($decoded);
            }
        }

        $metadata = [];
        if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $vector = null;
        if (isset($row['vector']) && is_string($row['vector']) && $row['vector'] !== '') {
            $decoded = json_decode($row['vector'], true);
            if (is_array($decoded)) {
                $vector = array_values(array_map('floatval', $decoded));
            }
        }

        $tokens = [];
        if (isset($row['tokens']) && is_string($row['tokens']) && $row['tokens'] !== '') {
            $decoded = json_decode($row['tokens'], true);
            if (is_array($decoded)) {
                $tokens = array_values($decoded);
            }
        }

        return [
            'id' => isset($row['id']) ? (string) $row['id'] : '',
            'content' => isset($row['content']) ? (string) $row['content'] : '',
            'tags' => $tags,
            'metadata' => $metadata,
            'vector' => $vector,
            'dim' => isset($row['dim']) && $row['dim'] !== null ? (int) $row['dim'] : null,
            'model' => isset($row['model']) && $row['model'] !== null ? (string) $row['model'] : null,
            'tokens' => $tokens,
            'created_at' => isset($row['created_at']) ? (int) $row['created_at'] : 0,
            'updated_at' => isset($row['updated_at']) ? (int) $row['updated_at'] : 0,
        ];
    }
}
