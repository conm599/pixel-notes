<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');
$cols = getDB()->query("SHOW COLUMNS FROM pn_user_ai_prefs LIKE 'own_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "own_* columns: " . implode(', ', $cols) . "\n";
