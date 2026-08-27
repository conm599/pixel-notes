<?php
/**
 * 管理页面 - AI 接口配置（仅管理员）
 * 配置存 pn_settings：ai_base_url / ai_api_key / ai_model
 * API Key 只存服务端，页面仅显示掩码，不下发完整 Key。
 */
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();
header('Cache-Control: no-store, must-revalidate');
startSecureSession();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
ensureTables($_);
if (!isAdminUser()) {
    header('Location: index.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, (string)$_POST['csrf'])) {
        $error = '会话已过期，请重试';
    } elseif (isset($_POST['op']) && $_POST['op'] === 'genkey') {
        // ===== 生成 AI 密钥 =====
        $remark = trim(isset($_POST['remark']) ? (string)$_POST['remark'] : '');
        $limit  = (int)(isset($_POST['daily_limit']) ? $_POST['daily_limit'] : 50);
        $bindUser = trim(isset($_POST['bind_user']) ? (string)$_POST['bind_user'] : '');
        if ($limit < 0 || $limit > 100000) $limit = 50;
        if (mb_strlen($remark, 'UTF-8') > 100) $remark = mb_substr($remark, 0, 100, 'UTF-8');

        $bindId = 0;
        if ($bindUser !== '') {
            try {
                $st = getDB()->prepare("SELECT id FROM pn_users WHERE username = ?");
                $st->execute(array($bindUser));
                $u = $st->fetchColumn();
                if (!$u) {
                    $error = '用户「' . htmlspecialchars($bindUser) . '」不存在';
                } else {
                    $bindId = (int)$u;
                }
            } catch (Exception $e) { $error = '查询用户失败'; }
        }
        if ($error === '') {
            try {
                $akey = 'pn-' . bin2hex(random_bytes(16));
                $st = getDB()->prepare("INSERT INTO pn_ai_keys (akey, remark, user_id, daily_limit, used, period, enabled, created_at)
                                        VALUES (?, ?, ?, ?, 0, '', 1, NOW())");
                $st->execute(array($akey, $remark, $bindId, $limit));
                $saved = true;
                $newKey = $akey;
            } catch (Exception $e) { $error = '生成失败：' . $e->getMessage(); }
        }
    } elseif (isset($_POST['op']) && $_POST['op'] === 'delkey') {
        try {
            $st = getDB()->prepare("DELETE FROM pn_ai_keys WHERE id = ?");
            $st->execute(array((int)$_POST['kid']));
            $saved = true;
        } catch (Exception $e) { $error = '删除失败'; }
    } elseif (isset($_POST['op']) && $_POST['op'] === 'togglekey') {
        try {
            $st = getDB()->prepare("UPDATE pn_ai_keys SET enabled = 1 - enabled WHERE id = ?");
            $st->execute(array((int)$_POST['kid']));
            $saved = true;
        } catch (Exception $e) { $error = '操作失败'; }
    } elseif (isset($_POST['op']) && $_POST['op'] === 'resetkey') {
        try {
            $st = getDB()->prepare("UPDATE pn_ai_keys SET used = 0 WHERE id = ?");
            $st->execute(array((int)$_POST['kid']));
            $saved = true;
        } catch (Exception $e) { $error = '操作失败'; }
    } elseif (isset($_POST['op']) && $_POST['op'] === 'smtp_save') {
        $host = trim(isset($_POST['smtp_host']) ? (string)$_POST['smtp_host'] : '');
        $port = (int)(isset($_POST['smtp_port']) ? $_POST['smtp_port'] : 465);
        $user = trim(isset($_POST['smtp_user']) ? (string)$_POST['smtp_user'] : '');
        $pass = trim(isset($_POST['smtp_pass']) ? (string)$_POST['smtp_pass'] : '');
        $fname = trim(isset($_POST['smtp_from_name']) ? (string)$_POST['smtp_from_name'] : '');
        $wl    = trim(isset($_POST['email_whitelist']) ? (string)$_POST['email_whitelist'] : '');
        $passPlaceholder = '••••••••（保持不变）';
        if ($pass === $passPlaceholder) $pass = null;
        if ($port !== 465 && $port !== 587) { $error = 'SMTP 端口仅支持 465（SSL）或 587（STARTTLS）'; }
        else {
            setSetting('smtp_host', $host !== '' ? $host : 'smtp.qq.com');
            setSetting('smtp_port', (string)$port);
            setSetting('smtp_user', $user);
            if ($pass !== null) setSetting('smtp_pass', $pass);
            setSetting('smtp_from_name', $fname !== '' ? $fname : 'Pixel Notes');
            if ($wl !== '') setSetting('email_whitelist', $wl);
            $saved = true;
        }
    } else {
        $base = trim(isset($_POST['base_url']) ? (string)$_POST['base_url'] : '');
        $key  = trim(isset($_POST['api_key']) ? (string)$_POST['api_key'] : '');
        $model = trim(isset($_POST['model']) ? (string)$_POST['model'] : '');
        $ownProxy = trim(isset($_POST['own_proxy']) ? (string)$_POST['own_proxy'] : '');

        // Key 允许留空 = 不修改（掩码占位）
        $keyPlaceholder = '••••••••（保持不变）';
        if ($key === '' || $key === $keyPlaceholder) $key = null;

        if ($base !== '' && !preg_match('#^https://#i', $base)) {
            $error = '接口地址必须以 https:// 开头';
        } elseif ($ownProxy !== '' && !preg_match('#^https://#i', $ownProxy)) {
            $error = '自有 Key 透明代理必须以 https:// 开头';
        } elseif ($model !== '' && strlen($model) > 100) {
            $error = '模型名太长';
        } else {
            if ($base !== '') setSetting('ai_base_url', $base);
            if ($key !== null) setSetting('ai_api_key', $key);
            if ($model !== '') setSetting('ai_model', $model);
            setSetting('ai_own_proxy', rtrim($ownProxy, '/'));
            $saved = true;
        }
    }
}

$curBase  = getSetting('ai_base_url', '');
$curModel = getSetting('ai_model', '');
$curKey   = getSetting('ai_api_key', '');
$curOwnProxy = getSetting('ai_own_proxy', '');
$keyMask  = $curKey === '' ? '' : (strlen($curKey) > 12 ? substr($curKey, 0, 6) . str_repeat('•', 8) . substr($curKey, -4) : str_repeat('•', 12));
$hasConfig = ($curBase !== '' && $curKey !== '' && $curModel !== '');

// 密钥列表（带绑定用户名与当前周期用量）
$aiKeys = array();
$curPeriod = aiPeriodNow();
try {
    $aiKeys = getDB()->query(
        "SELECT k.*, u.username AS bind_name FROM pn_ai_keys k
         LEFT JOIN pn_users u ON u.id = k.user_id
         ORDER BY k.id DESC LIMIT 200"
    )->fetchAll();
} catch (Exception $e) { $aiKeys = array(); }

// SMTP / 邮箱白名单
require_once __DIR__ . '/config/mailer.php';
$smtpHost  = getSetting('smtp_host', 'smtp.qq.com');
$smtpPort  = getSetting('smtp_port', '465');
$smtpUser  = getSetting('smtp_user', '');
$smtpPass  = getSetting('smtp_pass', '');
$smtpMask  = $smtpPass === '' ? '' : '••••••••（保持不变）';
$smtpName  = getSetting('smtp_from_name', 'Pixel Notes');
$mailWhitelist = getSetting('email_whitelist', '');
if ($mailWhitelist === '') $mailWhitelist = defaultEmailWhitelist();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pixel Notes - 管理面板</title>
    <link rel="stylesheet" href="css/pixel.css?v=40">
</head>
<body>
    <nav class="navbar">
        <a href="index.php" class="navbar-brand"><span class="icon">🎮</span> PIXEL NOTES</a>
        <div class="navbar-user">
            <span>👤 <?= htmlspecialchars($_SESSION['username']) ?> · 管理员</span>
            <a href="index.php" class="btn btn-outline btn-xs">← 返回便签</a>
            <form method="post" action="logout.php" style="display:inline;margin:0;">
                <button type="submit" class="btn btn-outline btn-xs">退出</button>
            </form>
        </div>
    </nav>

    <div class="main-container">
        <div class="toolbar">
            <span class="toolbar-title">⚙️ AI 接口配置</span>
            <div class="toolbar-actions">
                <span class="md-hint">OpenAI 兼容接口 · Key 仅存服务端</span>
            </div>
        </div>

        <?php if ($saved): ?>
            <div class="ai-admin-msg ok">✅ 配置已保存<?php if ($hasConfig): ?>，AI 编辑功能已就绪<?php endif; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="ai-admin-msg err">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!$hasConfig): ?>
            <div class="ai-admin-msg warn">⚠️ 尚未配置完整：填写接口地址、API Key、模型名并保存后，编辑器的 🤖 AI 按钮才可用</div>
        <?php endif; ?>

        <form method="post" class="ai-admin-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

            <div class="form-group">
                <label class="form-label">接口地址（Base URL）</label>
                <input type="url" name="base_url" class="form-input" placeholder="https://api.openai.com/v1"
                       value="<?= htmlspecialchars($curBase) ?>" maxlength="300">
                <div class="md-hint">OpenAI 兼容格式，填到 /v1 即可（自动拼接 /chat/completions）。兼容中转站、OneAPI、vLLM 等。</div>
            </div>

            <div class="form-group">
                <label class="form-label">API Key</label>
                <input type="text" name="api_key" class="form-input" placeholder="sk-..."
                       value="<?= htmlspecialchars($keyMask) ?>" maxlength="500" autocomplete="off">
                <div class="md-hint">当前：<?= $keyMask !== '' ? htmlspecialchars($keyMask) : '未设置' ?> · 留空或保持掩码不变则不修改。Key 只存服务端数据库，不会发给浏览器。</div>
            </div>

            <div class="form-group">
                <label class="form-label">模型名</label>
                <input type="text" name="model" class="form-input" placeholder="gpt-4o-mini"
                       value="<?= htmlspecialchars($curModel) ?>" maxlength="100">
                <div class="md-hint">例如：gpt-4o-mini / deepseek-chat / qwen-plus / glm-4-flash …（取决于你的接口支持什么）</div>
            </div>

            <div class="form-group">
                <label class="form-label">自有 Key 透明代理（可选）</label>
                <input type="text" name="own_proxy" class="form-input" placeholder="https://your-proxy.example.com（留空则服务器直连用户填写的接口）"
                       value="<?= htmlspecialchars($curOwnProxy) ?>" maxlength="255">
                <div class="md-hint">用户「自己的 Key」模式经此代理转发（格式：把目标完整 URL 拼在代理地址后面即可的 Workers 脚本，仓库内 ai-proxy-worker.js 可自部署）。留空 = 直连</div>
            </div>

            <div class="form-row">
            <div class="form-group" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">💾 保存配置</button>
                <button type="button" id="btnTestAi" class="btn btn-outline btn-sm">🔌 测试连接</button>
                <span id="testResult" class="md-hint"></span>
            </div>
        </div>
        </form>

        <div class="toolbar" style="margin-top:28px;">
            <span class="toolbar-title">🔑 AI 密钥管理（发放给用户）</span>
            <div class="toolbar-actions">
                <span class="md-hint">配额按北京时间每日 8:00 重置</span>
            </div>
        </div>

        <?php if (!empty($newKey)): ?>
            <div class="ai-admin-msg ok">
                ✅ 新密钥已生成：<code class="ai-key-code"><?= htmlspecialchars($newKey) ?></code>
                <button type="button" class="btn btn-outline btn-xs ai-copy-key" data-key="<?= htmlspecialchars($newKey) ?>">📋 复制</button>
                <span class="md-hint">仅此次显示完整密钥，请立即复制保存并发给用户</span>
            </div>
        <?php endif; ?>

        <form method="post" class="ai-admin-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="op" value="genkey">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">备注（给谁的）</label>
                    <input type="text" name="remark" class="form-input" placeholder="例如：张三的专属密钥" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">每日限额（次）</label>
                    <input type="number" name="daily_limit" class="form-input" value="50" min="1" max="100000">
                </div>
                <div class="form-group">
                    <label class="form-label">推送到账号（可选）</label>
                    <input type="text" name="bind_user" class="form-input" placeholder="用户名，留空则谁拿到都能用" maxlength="50">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">🔑 生成密钥</button>
                </div>
            </div>
            <div class="md-hint">「推送到账号」填了用户名：密钥自动绑定该账号，该用户登录后无需输入即可使用，其他人拿到也无效；留空：通用密钥，发给谁谁手动填入即可。</div>
        </form>

        <?php if (empty($aiKeys)): ?>
            <div class="md-hint" style="margin-top:14px;">还没有生成过任何密钥。</div>
        <?php else: ?>
        <div class="ai-key-table-wrap">
        <table class="ai-key-table">
            <thead>
                <tr>
                    <th>密钥</th>
                    <th>备注</th>
                    <th>绑定</th>
                    <th>今日用量</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($aiKeys as $k):
                $used = $k['period'] === $curPeriod ? (int)$k['used'] : 0;
                $mask = substr($k['akey'], 0, 11) . '…' . substr($k['akey'], -4);
            ?>
                <tr class="<?= $k['enabled'] ? '' : 'disabled-row' ?>">
                    <td><code class="ai-key-code"><?= htmlspecialchars($mask) ?></code>
                        <button type="button" class="btn btn-outline btn-xs ai-copy-key" data-key="<?= htmlspecialchars($k['akey']) ?>">📋</button>
                    </td>
                    <td><?= htmlspecialchars($k['remark'] !== '' ? $k['remark'] : '—') ?></td>
                    <td><?= $k['user_id'] > 0 ? '👤 ' . htmlspecialchars((string)$k['bind_name']) : '通用' ?></td>
                    <td><?= $used ?> / <?= $k['daily_limit'] > 0 ? (int)$k['daily_limit'] : '∞' ?></td>
                    <td><?= $k['enabled'] ? '<span class="st-on">启用</span>' : '<span class="st-off">已禁用</span>' ?></td>
                    <td>
                        <form method="post" style="display:inline;" class="key-confirm" data-confirm="确定<?= $k['enabled'] ? '禁用' : '启用' ?>这把密钥？&#10;<?= $k['enabled'] ? '禁用后使用该密钥的用户将立即无法调用 AI。' : '启用后该密钥恢复可用。' ?>">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="kid" value="<?= (int)$k['id'] ?>">
                            <button type="submit" name="op" value="togglekey" class="btn btn-outline btn-xs"><?= $k['enabled'] ? '🚫 禁用' : '▶️ 启用' ?></button>
                        </form>
                        <form method="post" style="display:inline;" class="key-confirm" data-confirm="确定将该密钥的今日用量清零？&#10;（<?= $used ?> → 0，该密钥用户今天会重新获得完整额度）">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="kid" value="<?= (int)$k['id'] ?>">
                            <button type="submit" name="op" value="resetkey" class="btn btn-outline btn-xs">♻️ 清零</button>
                        </form>
                        <form method="post" style="display:inline;" class="key-confirm" data-confirm="⚠️ 确定删除这把密钥？&#10;此操作不可恢复，使用该密钥的用户将立即无法调用 AI。">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="kid" value="<?= (int)$k['id'] ?>">
                            <button type="submit" name="op" value="delkey" class="btn btn-outline btn-xs btn-danger">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <div class="toolbar" style="margin-top:28px;">
            <span class="toolbar-title">📧 邮件服务（SMTP）与注册邮箱白名单</span>
            <div class="toolbar-actions">
                <span class="md-hint">用于注册/登录/找回密码的验证码邮件</span>
            </div>
        </div>

        <form method="post" class="ai-admin-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="op" value="smtp_save">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">SMTP 服务器</label>
                    <input type="text" name="smtp_host" class="form-input" value="<?= htmlspecialchars($smtpHost) ?>" placeholder="smtp.qq.com">
                </div>
                <div class="form-group">
                    <label class="form-label">端口</label>
                    <input type="number" name="smtp_port" class="form-input" value="<?= htmlspecialchars($smtpPort) ?>" min="465" max="587" placeholder="465">
                </div>
                <div class="form-group">
                    <label class="form-label">发件账号</label>
                    <input type="text" name="smtp_user" class="form-input" value="<?= htmlspecialchars($smtpUser) ?>" placeholder="sender@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">授权码</label>
                    <input type="password" name="smtp_pass" class="form-input" value="<?= htmlspecialchars($smtpMask) ?>" placeholder="留空保持不变">
                </div>
                <div class="form-group">
                    <label class="form-label">发件人名称</label>
                    <input type="text" name="smtp_from_name" class="form-input" value="<?= htmlspecialchars($smtpName) ?>" maxlength="50">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">注册邮箱白名单（逗号分隔的域名，支持这些域的邮箱才能注册）</label>
                <textarea name="email_whitelist" class="form-input" rows="3" style="resize:vertical;"><?= htmlspecialchars($mailWhitelist) ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary btn-sm">💾 保存邮件配置</button>
                    <input type="email" id="testMailTo" class="form-input" style="max-width:240px;" placeholder="测试收件邮箱">
                    <button type="button" id="btnTestMail" class="btn btn-outline btn-sm">📨 测试发信</button>
                    <span id="mailResult" class="md-hint"></span>
                </div>
            </div>
            <div class="md-hint">QQ 邮箱的「授权码」在 QQ 邮箱设置 → 账户 → POP3/SMTP 服务中生成，不是 QQ 密码。端口 465 走 SSL，587 走 STARTTLS。</div>
        </form>
    </div>

    <div id="toast" class="toast" style="display:none;"></div>

    <script src="js/admin.js?v=4"></script>
</body>
</html>
