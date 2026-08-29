<?php
/**
 * 数据备份与迁移（三级页面：admin.php → 本页，需管理员登录）
 *
 * 导出：全库表结构 + 全部数据 → JSON 文件下载（pn_settings 里敏感的 SMTP 密码等一并导出，文件请妥善保管）
 * 导入：上传备份 JSON → 输入管理员邮箱验证码确认 → 事务内清库重灌（危险操作，导入后当前数据被完全替换）
 *
 * 鉴权：管理员登录 + 导入时邮箱验证码（发码走 api/auth.php purpose=backup，仅限管理员本人邮箱）
 */
require_once __DIR__ . '/config/database.php';

startSecureSession();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
sendSecurityHeaders();

$schemaError = null;
ensureTables($schemaError);
if ($schemaError) { http_response_code(500); die('数据库初始化失败：' . htmlspecialchars((string)$schemaError)); }
$pdo = getDB();
if (!$pdo) { http_response_code(500); die('数据库连接失败'); }

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$uid = (int)$_SESSION['user_id'];
if (!isAdminUser()) { http_response_code(403); die('需要管理员权限'); }

// 管理员账号信息（发验证码要用邮箱）
$st = $pdo->prepare("SELECT username, email FROM pn_users WHERE id = ?");
$st->execute(array($uid));
$me = $st->fetch();
if (!$me) { header('Location: login.php'); exit; }
$adminEmail = (string)$me['email'];

$msg = ''; $msgType = 'ok';

// 与 api/auth.php 逐字一致的验证码工具（auth.php 是 API 入口不可 require，此处复制实现）
function backupRandCode() {
    try { $c = (string)random_int(0, 999999); }
    catch (Exception $e) { $c = (string)mt_rand(0, 999999); }
    return str_pad($c, 6, '0', STR_PAD_LEFT);
}
function backupVerifyEmailCode($pdo, $email, $purpose, $code) {
    if (!preg_match('/^\d{6}$/', (string)$code)) return false;
    $st = $pdo->prepare("SELECT * FROM pn_email_codes
                         WHERE email = ? AND purpose = ? AND used = 0 AND attempts < 5
                           AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $st->execute(array($email, $purpose));
    $row = $st->fetch();
    if (!$row) return false;
    if ((string)$row['code'] !== (string)$code) {
        $pdo->prepare("UPDATE pn_email_codes SET attempts = attempts + 1 WHERE id = ?")
            ->execute(array((int)$row['id']));
        return false;
    }
    $pdo->prepare("UPDATE pn_email_codes SET used = 1 WHERE id = ?")
        ->execute(array((int)$row['id']));
    return true;
}

// 备份覆盖的表（按外键依赖顺序排列，导入时先清后灌）
$TABLES = array('pn_users', 'pn_notes', 'pn_folders', 'pn_ai_actions', 'pn_settings', 'pn_ai_keys', 'pn_user_ai_prefs', 'pn_email_codes', 'pn_login_attempts', 'pn_share_logs');

// 同步管理员被导过来的历史数据通知 target 邮箱（提示他已进入"其他账号的数据"）
function backupNotifyMerge($pdo, $uid, $fromEmail, $addedNotes) {
    try {
        $st = $pdo->prepare("SELECT email, username FROM pn_users WHERE id = ?");
        $st->execute(array($uid));
        $u = $st->fetch();
        if (!$u) return;
        $to = (string)$u['email'];
        require_once __DIR__ . '/config/mailer.php';
        $html = '<div style="font-family:sans-serif;padding:20px;background:#0d0d1f;color:#e8e8f5;">'
            . '<h2 style="color:#4af0ff">数据合并通知</h2>'
            . '管理员通过备份导入把另一账号（' . htmlspecialchars($fromEmail) . '）的 <b>' . (int)$addedNotes . '</b> 条便签合并到了你的账号中，'
            . '统一放在了根目录的「其他账号的数据」文件夹（你现在登录会看到这里）。'
            . '<p style="color:#888;font-size:12px">如不希望混入，可删掉该文件夹——不影响你原有的便签。</p>'
            . '</div>';
        smtpSend($to, '【Pixel Notes】数据合并通知', $html);
    } catch (Exception $e) { /* 邮件失败不拦截导入 */ }
}

// ================= 导出 =================
if (isset($_GET['op']) && $_GET['op'] === 'export') {
    // 重新鉴权一次（防会话中途变化）
    if (!isset($_SESSION['user_id']) || !isAdminUser()) { http_response_code(403); die('需要管理员权限'); }
    $data = array(
        '_meta' => array(
            'app' => 'pixel-notes',
            'version' => 1,
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => (string)$me['username'],
        ),
    );
    foreach ($TABLES as $t) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll();
            $data[$t] = array('columns' => array_map(function ($c) { return $c['Field'] . ' ' . $c['Type'] . ($c['Null'] === 'NO' ? ' NOT NULL' : '') . ($c['Default'] !== null ? ' DEFAULT ' . (is_numeric($c['Default']) ? $c['Default'] : "'" . str_replace("'", "''", $c['Default']) . "'") : ''); }, $cols), 'rows' => array());
            $st = $pdo->query("SELECT * FROM `$t`");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                // 二进制/大对象安全：统一 UTF-8 字符串
                $data[$t]['rows'][] = $row;
            }
        } catch (Exception $e) { /* 单表失败不中断整体导出 */ }
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="pixel-notes-backup-' . date('Ymd-His') . '.json"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

// ================= 导入（分两步：step1 校验文件并发码；step2 验证码确认执行） =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = isset($_POST['op']) ? (string)$_POST['op'] : '';
    if ($op === 'step1') {
        // 校验上传的备份文件
        if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
            $msg = '备份文件上传失败'; $msgType = 'err';
        } elseif ($_FILES['backup']['size'] > 200 * 1024 * 1024) {
            $msg = '备份文件超过 200MB 上限'; $msgType = 'err';
        } else {
            $raw = file_get_contents($_FILES['backup']['tmp_name']);
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['_meta']['app']) || $data['_meta']['app'] !== 'pixel-notes') {
                $msg = '不是有效的 Pixel Notes 备份文件'; $msgType = 'err';
            } else {
                $tableStats = array(); $ok = true;
                foreach ($TABLES as $t) {
                    if (!isset($data[$t]['rows']) || !is_array($data[$t]['rows'])) { $tableStats[$t] = '缺失（将跳过）'; continue; }
                    $tableStats[$t] = count($data[$t]['rows']) . ' 行';
                }
                // 备份内容直接存会话（临时文件跨请求不可靠，用 raw 串）
                $_SESSION['pn_backup_raw'] = $raw;
                $_SESSION['pn_backup_stats'] = $tableStats;
                $_SESSION['pn_backup_meta'] = $data['_meta'];
                // 发验证码（直接调用内部逻辑：写 pn_email_codes + 发邮件）
                require_once __DIR__ . '/config/mailer.php';
                $code = backupRandCode();
                $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';
                $st = $pdo->prepare("SELECT COUNT(*) FROM pn_email_codes WHERE email = ? AND purpose = 'backup' AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)");  // 5 秒足够用户意图明确
                $st->execute(array($adminEmail));
                if ((int)$st->fetchColumn() > 0) {
                    $msg = '验证码发送太频繁，请 60 秒后再试'; $msgType = 'err';
                } else {
                    $st = $pdo->prepare("INSERT INTO pn_email_codes (email, code, purpose, ip, created_at, expires_at) VALUES (?, ?, 'backup', ?, NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
                    $st->execute(array($adminEmail, $code, $ip));
                    $mailOk = sendCodeEmail($adminEmail, $code, 'backup');
                    $msg = $mailOk ? '验证码已发送到管理员邮箱 ' . preg_replace('/^(\S{2}).*(@.*)$/', '$1***$2', $adminEmail) . '，请输入以确认导入' : '验证码邮件发送失败（请检查 SMTP 配置）';
                    $msgType = $mailOk ? 'ok' : 'err';
                    if ($mailOk) $_SESSION['pn_backup_step'] = 2;
                }
            }
        }
    } elseif ($op === 'step2') {
        // 增量合并导入（不覆盖、邮箱冲突则合并通知）
        $code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
        if (!isset($_SESSION['pn_backup_raw'], $_SESSION['pn_backup_step']) || (int)$_SESSION['pn_backup_step'] !== 2) {
            $msg = '请先上传备份文件'; $msgType = 'err';
        } elseif (!backupVerifyEmailCode($pdo, $adminEmail, 'backup', $code)) {
            $msg = '验证码错误或已过期'; $msgType = 'err';
        } else {
            $raw = $_SESSION['pn_backup_raw'];
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['_meta']['app'])) {
                $msg = '备份文件已失效，请重新上传'; $msgType = 'err';
            } else {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $mergeTargets = array(); $perTable = array(); $newUsers = 0;
                // ① 鉴别备份中的用户：冲突邮箱 → 映射到现有账号；不存在则创建新用户
                $uidMap = array();
                if (!empty($data['pn_users']['rows'])) {
                    foreach ($data['pn_users']['rows'] as $u) {
                        if (!is_array($u) || !isset($u['id'], $u['email'])) continue;
                        $bid = (int)$u['id'];
                        $email = strtolower(trim((string)$u['email']));
                        if ($email === '') continue;
                        $stC = $pdo->prepare("SELECT id FROM pn_users WHERE email = ?");
                        $stC->execute(array($email));
                        $exist = $stC->fetch();
                        if ($exist) {
                            $uidMap[$bid] = (int)$exist['id'];
                            $mergeTargets[] = array('bid' => $bid, 'email' => $email, 'target_id' => (int)$exist['id']);
                        } else {
                            $stM = $pdo->prepare("INSERT INTO pn_users (username, email, password_hash, created_at, email_verified, is_admin) VALUES (?, ?, ?, NOW(), ?, ?)");
                            $stM->execute(array((string)($u['username'] ?? $email), $email, (string)($u['password_hash'] ?? ''), (int)($u['email_verified'] ?? 1), (int)($u['is_admin'] ?? 0)));
                            $uidMap[$bid] = (int)$pdo->lastInsertId();
                            $newUsers++;
                        }
                    }
                }
                // ② 数据表按 user_id 映射后用 INSERT IGNORE 合并（不覆盖现有）
                // 记录本次真正新插入的便签 id（INSERT IGNORE 影响行数为 1 才算新进），供 ③ 精确归置
                $importedNoteIds = array();
                foreach ($TABLES as $t) {
                    if ($t === 'pn_users') continue;
                    if (empty($data[$t]['rows']) || !is_array($data[$t]['rows'])) continue;
                    $first = $data[$t]['rows'][0];
                    $cols = array_keys($first);
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    $ins = $pdo->prepare("INSERT IGNORE INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES ($ph)");
                    $cnt = 0;
                    foreach ($data[$t]['rows'] as $row) {
                        if (isset($row['user_id']) && (int)$row['user_id'] > 0 && isset($uidMap[(int)$row['user_id']])) {
                            $row['user_id'] = $uidMap[(int)$row['user_id']];
                        }
                        $vals = array();
                        foreach ($cols as $c) { $v = $row[$c] ?? null; if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE); $vals[] = $v; }
                        $ins->execute($vals); $cnt++;
                        if ($t === 'pn_notes' && $ins->rowCount() === 1 && !empty($row['id'])) {
                            $importedNoteIds[(int)$row['id']] = (int)$row['user_id'];
                        }
                    }
                    if ($cnt > 0) $perTable[] = $t . '(' . $cnt . ')';
                }
                // ③ 合并过的用户：把「本次导入的便签」归置到「其他账号的数据」文件夹 + 发通知
                // 只动本次 INSERT IGNORE 新插入的便签（$importedNoteIds），绝不碰用户原有便签；
                // 若用户已有同名夹（历史批次遗留），本批便签挂时间戳后缀子夹，避免两批数据混在一起
                require_once __DIR__ . '/config/mailer.php';
                foreach ($mergeTargets as $mt) {
                    $uid2 = $mt['target_id'];
                    $myNoteIds = array();
                    foreach ($importedNoteIds as $nid => $nuid) { if ($nuid === $uid2) $myNoteIds[] = $nid; }
                    if (empty($myNoteIds)) continue;
                    $stF = $pdo->prepare("SELECT id FROM pn_folders WHERE user_id = ? AND name = '其他账号的数据' AND parent_id IS NULL LIMIT 1");
                    $stF->execute(array($uid2));
                    $folderId = $stF->fetchColumn();
                    if ($folderId === false) {
                        $pdo->prepare("INSERT INTO pn_folders (user_id, parent_id, name, sort_order, created_at) VALUES (?, NULL, '其他账号的数据', 0, NOW())")->execute(array($uid2));
                        $folderId = (int)$pdo->lastInsertId();
                    }
                    // 导入行带 folder_id 的（备份里就在某个夹里）不动，只归置 folder_id 为 NULL 的
                    $phN = implode(',', array_fill(0, count($myNoteIds), '?'));
                    $pdo->prepare("UPDATE pn_notes SET folder_id = ? WHERE id IN ($phN) AND folder_id IS NULL")
                        ->execute(array_merge(array($folderId), $myNoteIds));
                    backupNotifyMerge($pdo, $uid2, $mt['email'], count($myNoteIds));
                }
                unset($_SESSION['pn_backup_raw'], $_SESSION['pn_backup_stats'], $_SESSION['pn_backup_meta'], $_SESSION['pn_backup_step']);
                $msg = '✅ 增量导入完成：新增用户 ' . $newUsers . ' 个，合并现有账号 ' . count($mergeTargets) . ' 个，共导入 ' . implode('、', $perTable);
                $msgType = 'ok';
            }
        }
    }
}
$pending = isset($_SESSION['pn_backup_raw'], $_SESSION['pn_backup_stats']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pixel Notes - 数据备份与迁移</title>
    <link rel="stylesheet" href="css/pixel.css?v=54">
</head>
<body>
    <nav class="navbar">
        <a href="index.php" class="navbar-brand"><span class="icon">🎮</span> PIXEL NOTES</a>
        <div class="navbar-user">
            <span>👤 <?= htmlspecialchars((string)$me['username']) ?> · 管理员</span>
            <a href="admin.php" class="btn btn-outline btn-xs">← 返回管理面板</a>
            <form method="post" action="logout.php" style="display:inline;margin:0;">
                <button type="submit" class="btn btn-outline btn-xs">退出</button>
            </form>
        </div>
    </nav>

    <div class="main-container">
        <?php if ($msg !== ''): ?><div class="ai-admin-msg <?= $msgType === 'ok' ? 'ok' : 'err' ?>"><?= $msg ?></div><?php endif; ?>

        <div class="toolbar">
            <span class="toolbar-title">📦 数据导出</span>
            <div class="toolbar-actions"><span class="md-hint">全库结构 + 数据打包为 JSON（含 SMTP 凭据等敏感配置，请妥善保管备份文件）</span></div>
        </div>
        <div class="ai-admin-form">
            <p class="md-hint">导出内容：用户 / 便签 / 文件夹 / AI 溯源 / 站点设置 / AI 密钥 / 用户偏好 / 邮件验证码 / 登录尝试 / 分享日志 共 <?= count($TABLES) ?> 张表。</p>
            <a class="btn btn-primary btn-sm" href="backup.php?op=export">⬇️ 下载完整备份（JSON）</a>
        </div>

        <div class="toolbar" style="margin-top:24px;">
            <span class="toolbar-title">♻️ 数据导入（危险操作）</span>
            <div class="toolbar-actions"><span class="md-hint">导入会【清空并完全替换】当前所有数据，事务保护：失败自动回滚</span></div>
        </div>
        <form method="post" enctype="multipart/form-data" class="ai-admin-form" autocomplete="off">
            <input type="hidden" name="op" value="step1">
            <div class="form-group">
                <label class="form-label">选择备份文件（JSON）</label>
                <input type="file" name="backup" accept=".json,application/json" class="form-input" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">📤 上传并进入验证</button>
        </form>

        <?php if ($pending): ?>
        <div class="ai-admin-form" style="border:2px solid #ff6b6b;padding:12px;">
            <p class="md-hint">待导入备份：<b><?= htmlspecialchars((string)$_SESSION['pn_backup_meta']['exported_at']) ?></b>
                由 <b><?= htmlspecialchars((string)$_SESSION['pn_backup_meta']['exported_by']) ?></b> 导出。</p>
            <p class="md-hint">行数概览：<?php
                $parts = array();
                foreach ($_SESSION['pn_backup_stats'] as $t => $c) $parts[] = htmlspecialchars($t) . '=' . htmlspecialchars((string)$c);
                echo implode('，', $parts);
            ?></p>
            <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="op" value="step2">
                <input type="text" name="code" class="form-input" style="max-width:160px" placeholder="邮箱验证码" maxlength="6" required>
                <button type="submit" class="btn btn-danger btn-sm">✅ 验证并执行导入（覆盖现有数据）</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
