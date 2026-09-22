<?php

final class StoreFactory
{
    public static function create(array $storage): Store
    {
        $driver = isset($storage['driver']) ? (string) $storage['driver'] : 'auto';

        $mysqlCfg = isset($storage['mysql']) && is_array($storage['mysql']) ? $storage['mysql'] : [];
        $jsonCfg = isset($storage['jsonfile']) && is_array($storage['jsonfile']) ? $storage['jsonfile'] : [];

        if ($driver === 'mysql') {
            $user = isset($mysqlCfg['user']) ? trim((string) $mysqlCfg['user']) : '';
            $dbname = isset($mysqlCfg['dbname']) ? trim((string) $mysqlCfg['dbname']) : '';
            if ($user === '' || $dbname === '') {
                throw new RuntimeException('MySQL 配置不完整');
            }
            return new MySqlStore($mysqlCfg);
        }

        if ($driver === 'jsonfile') {
            return new JsonFileStore($jsonCfg);
        }

        if ($driver === 'auto') {
            $user = isset($mysqlCfg['user']) ? trim((string) $mysqlCfg['user']) : '';
            $dbname = isset($mysqlCfg['dbname']) ? trim((string) $mysqlCfg['dbname']) : '';
            if ($user !== '' && $dbname !== '') {
                return new MySqlStore($mysqlCfg);
            }
            return new JsonFileStore($jsonCfg);
        }

        throw new RuntimeException('未知存储驱动');
    }

    public static function makeId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
