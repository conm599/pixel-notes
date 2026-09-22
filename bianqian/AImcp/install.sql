-- AI 记忆库数据表（MySQL 5.7+ / MariaDB 10.3+）
-- 可在虚拟主机数据库管理里导入本文件；MySqlStore 首次运行也会自动建表（CREATE TABLE IF NOT EXISTS）

CREATE TABLE IF NOT EXISTS `ai_memories` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
