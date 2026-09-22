<?php

interface Store
{
    /**
     * 写入一条已规范化的记忆条目，返回存储后的条目。
     * $entry 必须包含：id, content, tags(array), metadata(array),
     * vector(?float[]), dim(?int), model(?string), tokens(array),
     * created_at(int), updated_at(int)
     */
    public function add(array $entry): array;

    /** 更新指定 id 的完整条目（覆盖该 id 的全部字段，保留主键 id），返回是否命中 */
    public function update(string $id, array $entry): bool;

    public function get(string $id): ?array;

    /** 按 created_at 倒序返回，可按标签过滤 */
    public function list(int $limit = 20, int $offset = 0, ?string $tag = null): array;

    public function count(?string $tag = null): int;

    public function delete(string $id): bool;

    /** 返回全部条目（供向量检索遍历） */
    public function all(): array;

    /** 连通性探测：MySQL 为连接测试，JSON 文件为可写测试 */
    public function ping(): bool;
}
