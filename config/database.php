<?php
/**
 * 数据库连接配置 + 自愈式建表
 * Pixel Notes - 像素便签系统
 */

// 优先读环境变量（本地便携环境用），未设置则走生产常量——生产行为零变化
define('DB_HOST', getenv('PIXEL_DB_HOST') !== false ? getenv('PIXEL_DB_HOST') : 'localhost');
define('DB_PORT', getenv('PIXEL_DB_PORT') !== false ? getenv('PIXEL_DB_PORT') : '3306');
define('DB_NAME', getenv('PIXEL_DB_NAME') !== false ? getenv('PIXEL_DB_NAME') : 'CHANGE_ME');
define('DB_USER', getenv('PIXEL_DB_USER') !== false ? getenv('PIXEL_DB_USER') : 'CHANGE_ME');
define('DB_PASS', getenv('PIXEL_DB_PASS') !== false ? getenv('PIXEL_DB_PASS') : 'CHANGE_ME');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
    }
    return $pdo;
}

/**
 * 确保数据表存在（幂等）
 */
function ensureTables(&$error = null) {
    static $done = false;
    static $lastError = null;
    $error = $lastError;
    if ($done) return true;

    try {
        $pdo = getDB();

        $sqlUsers = "CREATE TABLE IF NOT EXISTS `pn_users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(50) NOT NULL,
            `email` VARCHAR(100) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_username` (`username`),
            UNIQUE KEY `uniq_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sqlNotes = "CREATE TABLE IF NOT EXISTS `pn_notes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL DEFAULT '',
            `content` TEXT,
            `color` VARCHAR(20) NOT NULL DEFAULT 'yellow',
            `pinned` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` INT NOT NULL DEFAULT 0,
            `folder_id` INT UNSIGNED NULL DEFAULT NULL,
            `share_token` VARCHAR(36) NOT NULL DEFAULT '',
            `share_until` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_pinned` (`pinned`),
            KEY `idx_sort` (`user_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sqlAttempts = "CREATE TABLE IF NOT EXISTS `pn_login_attempts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `action` VARCHAR(20) NOT NULL DEFAULT 'login',
            `success` TINYINT(1) NOT NULL DEFAULT 0,
            `attempted_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_fail_lookup` (`username`, `success`, `attempted_at`),
            KEY `idx_ip_lookup` (`ip`, `action`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sqlSettings = "CREATE TABLE IF NOT EXISTS `pn_settings` (
            `skey` VARCHAR(50) NOT NULL,
            `svalue` TEXT NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`skey`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sqlAiKeys = "CREATE TABLE IF NOT EXISTS `pn_ai_keys` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `akey` VARCHAR(64) NOT NULL,
            `remark` VARCHAR(100) NOT NULL DEFAULT '',
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `daily_limit` INT NOT NULL DEFAULT 50,
            `used` INT NOT NULL DEFAULT 0,
            `period` VARCHAR(10) NOT NULL DEFAULT '',
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_akey` (`akey`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sqlAiPrefs = "CREATE TABLE IF NOT EXISTS `pn_user_ai_prefs` (
            `user_id` INT UNSIGNED NOT NULL,
            `mode` VARCHAR(20) NOT NULL DEFAULT 'platform',
            `platform_key` VARCHAR(64) NOT NULL DEFAULT '',
            `own_base_url` VARCHAR(255) NOT NULL DEFAULT '',
            `own_api_key` VARCHAR(255) NOT NULL DEFAULT '',
            `own_model` VARCHAR(100) NOT NULL DEFAULT '',
            `own_proxy` VARCHAR(255) NOT NULL DEFAULT '',
            `send_time` TINYINT(1) NOT NULL DEFAULT 0,
            `style` VARCHAR(500) NOT NULL DEFAULT '',
            `own_deep_think` TINYINT(1) NOT NULL DEFAULT 0,
            `own_body_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `own_body_key` VARCHAR(64) NOT NULL DEFAULT '',
            `own_body_json` VARCHAR(500) NOT NULL DEFAULT '',
            `policy_version` INT NOT NULL DEFAULT 0,
            `own_used` INT NOT NULL DEFAULT 0,
            `own_period` VARCHAR(10) NOT NULL DEFAULT '',
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sqlEmailCodes = "CREATE TABLE IF NOT EXISTS `pn_email_codes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(100) NOT NULL,
            `code` VARCHAR(10) NOT NULL,
            `purpose` VARCHAR(20) NOT NULL,
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `attempts` INT NOT NULL DEFAULT 0,
            `used` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_email` (`email`, `purpose`, `used`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 便签文件夹（支持多层嵌套，parent_id NULL = 根层级）
        $sqlFolders = "CREATE TABLE IF NOT EXISTS `pn_folders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `parent_id` INT UNSIGNED NULL DEFAULT NULL,
            `name` VARCHAR(100) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `share_token` VARCHAR(36) NOT NULL DEFAULT '',
            `share_until` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_user_parent` (`user_id`, `parent_id`),
            KEY `idx_folder_share_token` (`share_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // AI 操作溯源日志（自动分类等，detail 为 JSON 明细，支持撤销）
        $sqlAiActions = "CREATE TABLE IF NOT EXISTS `pn_ai_actions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `action` VARCHAR(30) NOT NULL,
            `detail` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 自愈：给已有 pn_notes 表补充 sort_order / share 列（兼容旧表）
        // 使用临时静默模式，不抛出任何异常
        $oldMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $pdo->exec("ALTER TABLE `pn_notes` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE `pn_notes` ADD INDEX `idx_sort` (`user_id`, `sort_order`)");
        $pdo->exec("ALTER TABLE `pn_notes` ADD COLUMN `share_token` VARCHAR(36) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE `pn_notes` ADD COLUMN `share_until` INT UNSIGNED NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE `pn_notes` ADD INDEX `idx_share_token` (`share_token`)");
        // 文件夹分享列（v7.1 自愈迁移，ERRMODE_SILENT 下列已存在时报错被忽略）
        $pdo->exec("ALTER TABLE `pn_folders` ADD COLUMN `share_token` VARCHAR(36) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE `pn_folders` ADD COLUMN `share_until` INT UNSIGNED NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE `pn_folders` ADD INDEX `idx_folder_share_token` (`share_token`)");
        // 自愈：管理员标识列 + 若无任何管理员则自动提升最早注册的用户
        $pdo->exec("ALTER TABLE `pn_users` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0");
        // 自愈：邮箱已验证标记
        $pdo->exec("ALTER TABLE `pn_users` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0");
        // 自愈：用户自有透明代理地址
        $pdo->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_proxy` VARCHAR(255) NOT NULL DEFAULT ''");
        // 自愈：AI 时间感知开关
        $pdo->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `send_time` TINYINT(1) NOT NULL DEFAULT 0");
        // 自愈：AI 深度思考 + 自定义 Body 参数（仅自有 Key 模式生效）
        $pdo->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_deep_think` TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_body_enabled` TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_body_key` VARCHAR(64) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_body_json` VARCHAR(500) NOT NULL DEFAULT ''");
        // 自愈：便签文件夹（folder_id NULL = 主页）
        $pdo->exec("ALTER TABLE `pn_notes` ADD COLUMN `folder_id` INT UNSIGNED NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE `pn_notes` ADD INDEX `idx_folder` (`user_id`, `folder_id`)");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $oldMode);

        try {
            $cnt = $pdo->query("SELECT COUNT(*) FROM `pn_users` WHERE `is_admin` = 1")->fetchColumn();
            if ((int)$cnt === 0) {
                $pdo->exec("UPDATE `pn_users` SET `is_admin` = 1 ORDER BY `id` ASC LIMIT 1");
            }
        } catch (PDOException $e) { /* 忽略自愈失败 */ }

        try {
            $pdo->exec($sqlUsers);
            $pdo->exec($sqlNotes);
            $pdo->exec($sqlAttempts);
            $pdo->exec($sqlSettings);
            $pdo->exec($sqlAiKeys);
            $pdo->exec($sqlAiPrefs);
            $pdo->exec($sqlEmailCodes);
            $pdo->exec($sqlFolders);
            $pdo->exec($sqlAiActions);
        } catch (PDOException $e) {
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlUsers));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlNotes));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlAttempts));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlSettings));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlAiKeys));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlAiPrefs));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlEmailCodes));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlFolders));
            $pdo->exec(str_replace('utf8mb4', 'utf8', $sqlAiActions));
        }

        $done = true;
        return true;
    } catch (PDOException $e) {
        $lastError = $e->getMessage();
        $error = $lastError;
        return false;
    }
}

/**
 * 读 / 写系统设置（pn_settings）
 */
function getSetting($key, $default = '') {
    try {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT svalue FROM pn_settings WHERE skey = ?");
        $st->execute(array($key));
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (Exception $e) {
        return $default;
    }
}

function setSetting($key, $value) {
    try {
        $pdo = getDB();
        $st = $pdo->prepare("INSERT INTO pn_settings (skey, svalue, updated_at) VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = NOW()");
        $st->execute(array($key, $value));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 当前配额周期标签（北京时间每天 8:00 重置：8点后算新周期）
 */
function aiPeriodNow() {
    $bj = time() + 8 * 3600;              // 北京时间
    $day = gmdate('Y-m-d', $bj);
    $hour = (int)gmdate('G', $bj);
    if ($hour < 8) {
        // 北京时间 0-8 点仍属于前一天的周期
        $day = gmdate('Y-m-d', $bj - 86400);
    }
    return $day;
}

/**
 * 读用户 AI 偏好（跨端同步存储），无记录返回 null
 */
function getUserAiPrefs($userId) {
    try {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT * FROM pn_user_ai_prefs WHERE user_id = ?");
        $st->execute(array((int)$userId));
        $row = $st->fetch();
        return $row === false ? null : $row;
    } catch (Exception $e) {
        return null;
    }
}

function saveUserAiPrefs($userId, $fields) {
    try {
        $pdo = getDB();
        $cols = array_keys($fields);
        $colSql = implode(', ', array_map(function ($k) { return "`" . $k . "`"; }, $cols));
        $setSql = implode(', ', array_map(function ($k) { return "`" . $k . "` = VALUES(`" . $k . "`)"; }, $cols));
        $sql = "INSERT INTO pn_user_ai_prefs (user_id, " . $colSql . ", updated_at)
                VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", NOW())
                ON DUPLICATE KEY UPDATE " . $setSql . ", updated_at = NOW()";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge(array((int)$userId), array_values($fields)));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 当前登录用户是否管理员（直接查库，改库即刻生效）
 */
function isAdminUser() {
    if (!isset($_SESSION['user_id'])) return false;
    try {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT is_admin FROM pn_users WHERE id = ?");
        $st->execute(array((int)$_SESSION['user_id']));
        $v = $st->fetchColumn();
        return ((int)$v) === 1;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 输出安全响应头（nginx 环境下 .htaccess 无效，必须在 PHP 层设置）
 */
function sendSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https: data:; media-src 'self' blob: data: https:; frame-src 'self' https://player.bilibili.com https://www.bilibili.com https://www.youtube.com https://www.youtube-nocookie.com https://m.youtube.com https://youtube.com https://player.vimeo.com https://player.dailymotion.com https://music.163.com https://open.spotify.com; connect-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

/**
 * 以加固参数启动会话（HttpOnly + Secure + SameSite=Lax）
 */
function startSecureSession() {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,      // 站点已强制 HTTPS（301）
        'httponly' => true,    // JS 无法读取会话 Cookie
        'samesite' => 'Lax',   // 缓解 CSRF
    ));
    session_start();
}

/**
 * 客户端 IP
 */
function clientIp() {
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : 'unknown';
}