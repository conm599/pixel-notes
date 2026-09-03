<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo 'server database.php md5: ' . md5_file(__DIR__ . '/config/database.php') . "\n";
echo 'contains own_deep_think: ' . (strpos(file_get_contents(__DIR__ . '/config/database.php'), 'own_deep_think') !== false ? 'yes' : 'no') . "\n";
try {
    getDB()->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_deep_think` TINYINT(1) NOT NULL DEFAULT 0");
    echo "ALTER own_deep_think: OK\n";
} catch (Exception $e) {
    echo 'ALTER own_deep_think: ' . $e->getMessage() . "\n";
}
try {
    getDB()->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_body_enabled` TINYINT(1) NOT NULL DEFAULT 0");
    echo "ALTER own_body_enabled: OK\n";
} catch (Exception $e) {
    echo 'ALTER own_body_enabled: ' . $e->getMessage() . "\n";
}
try {
    getDB()->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_body_key` VARCHAR(64) NOT NULL DEFAULT ''");
    echo "ALTER own_body_key: OK\n";
} catch (Exception $e) {
    echo 'ALTER own_body_key: ' . $e->getMessage() . "\n";
}
try {
    getDB()->exec("ALTER TABLE `pn_user_ai_prefs` ADD COLUMN `own_body_json` VARCHAR(500) NOT NULL DEFAULT ''");
    echo "ALTER own_body_json: OK\n";
} catch (Exception $e) {
    echo 'ALTER own_body_json: ' . $e->getMessage() . "\n";
}
$cols = getDB()->query("SHOW COLUMNS FROM pn_user_ai_prefs")->fetchAll(PDO::FETCH_COLUMN);
echo "columns: " . implode(', ', $cols) . "\n";
