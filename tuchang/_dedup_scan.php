<?php
// ================= 陶瓦图床 · 存量图片 sha 回填 + 冗余合并（一次性维护工具） =================
// 背景：2026-09-25 上线内容去重（img_images.sha + 引用计数删除，见 config.php）。
// 上传去重只对新增生效；存量记录没有 sha、相同内容的图各自占一份磁盘。
// 本工具全表扫描：回填 sha → 相同内容的记录统一改指最早那份物理文件 → 冗余副本按引用计数删除。
// 用法（VPS 上）：php /var/www/tuchang/_dedup_scan.php
// 幂等可重复执行；只删「确认已无任何记录引用」的物理文件，误删风险由引用计数兜底。
if (PHP_SAPI !== 'cli') exit("CLI only\n");
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

if (!img_ensure_dedup_schema()) {
    fwrite(STDERR, "sha 列自愈失败：请检查数据库权限后重试\n");
    exit(1);
}

$st = db()->query('SELECT id, file, size FROM img_images ORDER BY id ASC');
$upd = db()->prepare('UPDATE img_images SET sha = ?, file = ? WHERE id = ?');
$map = array();   // sha => 最早记录的物理文件名（keep 源）
$scanned = 0; $missing = 0; $merged = 0; $freed = 0; $total = 0;

foreach ($st->fetchAll() as $r) {
    $total++;
    if ($total % 500 === 0) echo "…已处理 {$total} 行\n";
    $path = IMG_DIR . $r['file'];
    if (!is_file($path)) { $missing++; continue; }   // 孤儿行（文件已丢）：服务层访问时自清，这里跳过
    $scanned++;
    $sha = hash_file('sha256', $path);
    if ($sha === false) { fwrite(STDERR, "读取失败: {$r['file']}\n"); continue; }

    if (!isset($map[$sha])) {
        // 首见内容：登记 keep 源并回填 sha
        $map[$sha] = $r['file'];
        $upd->execute(array($sha, $r['file'], (int)$r['id']));
        continue;
    }
    if ($map[$sha] === $r['file']) {
        // 已指向 keep 源（重复执行本工具）：只补 sha
        $upd->execute(array($sha, $r['file'], (int)$r['id']));
        continue;
    }
    // 重复内容：本行改指 keep 源，原副本确认无引用后删除
    $upd->execute(array($sha, $map[$sha], (int)$r['id']));
    img_unlink_if_orphan($r['file']);
    $merged++;
    $freed += (int)$r['size'];
}

echo "完成：扫描 {$scanned} 行（缺失文件 {$missing} 行 / 共 {$total} 行）；"
   . "合并重复记录 {$merged} 条；释放磁盘约 " . round($freed / 1048576, 2) . " MB\n";
