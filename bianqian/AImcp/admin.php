<?php

require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/StoreFactory.php';
require_once __DIR__ . '/src/MySqlStore.php';
require_once __DIR__ . '/src/JsonFileStore.php';
require_once __DIR__ . '/src/Search.php';
require_once __DIR__ . '/src/Embeddings.php';
require_once __DIR__ . '/src/App.php';

session_start();

$app = new App();
$expectedPassword = $app->adminPassword();

function amcp_admin_csrfToken(): string
{
    if (empty($_SESSION['amcp_csrf'])) {
        $_SESSION['amcp_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['amcp_csrf'];
}

$isLoggedIn = !empty($_SESSION['amcp_auth']);

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'login') {
        $pw = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($expectedPassword !== '' && hash_equals($expectedPassword, $pw)) {
            $_SESSION['amcp_auth'] = true;
        }
        header('Location: admin.php');
        exit;
    }

    if ($action === 'logout') {
        unset($_SESSION['amcp_auth']);
        header('Location: admin.php');
        exit;
    }

    if ($isLoggedIn) {
        $csrf = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
        if ($csrf !== '' && hash_equals((string) (isset($_SESSION['amcp_csrf']) ? $_SESSION['amcp_csrf'] : ''), $csrf)) {
            if ($action === 'save_instructions') {
                $app->instructions()->set(isset($_POST['instructions']) ? (string) $_POST['instructions'] : '');
                header('Location: admin.php?saved=instructions');
                exit;
            }

            if ($action === 'import') {
                $importStatus = 'error';
                $imported = 0;
                if (isset($_FILES['backup_file']) && is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
                    $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
                    $data = json_decode($raw, true);
                    if (is_array($data) && isset($data['memories']) && is_array($data['memories'])) {
                        foreach ($data['memories'] as $m) {
                            if (!isset($m['content']) || trim((string) $m['content']) === '') {
                                continue;
                            }
                            $entry = array(
                                'id' => isset($m['id']) && $m['id'] !== '' ? (string) $m['id'] : StoreFactory::makeId(),
                                'content' => (string) $m['content'],
                                'tags' => isset($m['tags']) && is_array($m['tags']) ? array_values(array_map('strval', $m['tags'])) : array(),
                                'metadata' => isset($m['metadata']) && is_array($m['metadata']) ? $m['metadata'] : array(),
                                'vector' => isset($m['vector']) && is_array($m['vector']) ? array_values(array_map('floatval', $m['vector'])) : null,
                                'dim' => isset($m['dim']) ? (int) $m['dim'] : (isset($m['vector']) && is_array($m['vector']) ? count($m['vector']) : null),
                                'model' => isset($m['model']) ? (string) $m['model'] : null,
                                'tokens' => Search::tokenize((string) $m['content']),
                                'created_at' => isset($m['created_at']) ? (int) $m['created_at'] : (int) (microtime(true) * 1000),
                                'updated_at' => isset($m['updated_at']) ? (int) $m['updated_at'] : (int) (microtime(true) * 1000),
                            );
                            try {
                                $app->store()->add($entry);
                                $imported++;
                            } catch (\Throwable $e) {
                                // 单条失败（如 id 冲突）跳过，不中断整体导入
                            }
                        }
                        if (isset($data['instructions']) && is_string($data['instructions'])) {
                            $app->instructions()->set($data['instructions']);
                        }
                        $importStatus = 'ok';
                    }
                }
                header('Location: admin.php?imported=' . urlencode($importStatus . '=' . $imported));
                exit;
            }

            if ($action === 'add') {
                $content = isset($_POST['content']) ? trim((string) $_POST['content']) : '';
                $tagsRaw = isset($_POST['tags']) ? (string) $_POST['tags'] : '';
                $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw)), static function ($t) {
                    return $t !== '';
                }));

                if ($content !== '') {
                    $now = (int) (microtime(true) * 1000);
                    $entry = array(
                        'id' => StoreFactory::makeId(),
                        'content' => $content,
                        'tags' => $tags,
                        'metadata' => array(),
                        'vector' => null,
                        'dim' => null,
                        'model' => null,
                        'tokens' => Search::tokenize($content),
                        'created_at' => $now,
                        'updated_at' => $now,
                    );
                    if ($app->embeddings()->isConfigured()) {
                        try {
                            $embedded = $app->embeddings()->embed($content, 'passage');
                            $entry['vector'] = $embedded['vector'];
                            $entry['dim'] = count($embedded['vector']);
                            $entry['model'] = $embedded['model'];
                        } catch (\Throwable $e) {
                            // 嵌入失败不影响保存，留空向量
                        }
                    }
                    $app->store()->add($entry);
                }
            } elseif ($action === 'delete') {
                $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
                if ($id !== '') {
                    $app->store()->delete($id);
                }
            }
        }
        header('Location: admin.php?q=' . urlencode(isset($_GET['q']) ? (string) $_GET['q'] : ''));
        exit;
    }
}

// 导出：生成 JSON 备份文件并下载
if ($isLoggedIn && isset($_GET['export'])) {
    $backup = array(
        'format' => 'ai-memory-mcp-backup',
        'version' => 1,
        'exported_at' => (int) (microtime(true) * 1000),
        'instructions' => $app->instructions()->get(),
        'memories' => array(),
    );
    foreach ($app->store()->all() as $entry) {
        $backup['memories'][] = array(
            'id' => $entry['id'],
            'content' => $entry['content'],
            'tags' => $entry['tags'],
            'metadata' => $entry['metadata'],
            'vector' => $entry['vector'],
            'dim' => $entry['dim'],
            'model' => $entry['model'],
            'created_at' => $entry['created_at'],
            'updated_at' => $entry['updated_at'],
        );
    }
    $json = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ai-memory-backup-' . date('Ymd-His') . '.json"');
    echo $json;
    exit;
}

$searchQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$page = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);
$limit = 10;
$offset = ($page - 1) * $limit;

$total = 0;
$entries = array();

if ($isLoggedIn) {
    $total = $app->store()->count();
    if ($searchQuery !== '') {
        $qTokens = Search::tokenize($searchQuery);
        $scored = array();
        foreach ($app->store()->all() as $entry) {
            $score = Search::textScore($qTokens, $entry['tokens']);
            if ($score > 0.0) {
                $scored[] = array($entry, $score);
            }
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a[1] !== $b[1]) {
                return $b[1] <=> $a[1];
            }
            return $b[0]['created_at'] <=> $a[0]['created_at'];
        });
        $entries = array();
        foreach (array_slice($scored, $offset, $limit) as $pair) {
            $entries[] = $pair[0];
        }
        $total = count($scored);
    } else {
        $entries = $app->store()->list($limit, $offset);
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI 记忆库 · 管理</title>
<style>
body{font-family:-apple-system,"Segoe UI",system-ui,sans-serif;background:#f4f5f7;color:#1d2430;margin:0;padding:24px}
.wrap{max-width:860px;margin:0 auto}
.card{background:#fff;border:1px solid #e2e6ea;border-radius:10px;padding:20px 24px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
h1{font-size:18px;margin:0 0 4px}h2{font-size:14px;color:#5b6572;margin:0 0 14px;font-weight:500}
.tbar{font-size:14px}
input[type=text],input[type=password],textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d0d5db;border-radius:6px;font-size:13px;font-family:inherit}
textarea{min-height:64px;resize:vertical}
button{background:#0969da;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-size:13px;cursor:pointer}
button.ghost{background:transparent;color:#cf222e;border:1px solid #e2b6b6;padding:4px 10px}
.row{border-bottom:1px solid #eef1f4;padding:10px 0}
.row:last-child{border-bottom:none}
.meta{color:#5b6572;font-size:12px;margin-top:4px}
.tag{display:inline-block;background:#eef2ff;color:#3b4bd8;border-radius:20px;padding:1px 9px;font-size:11px;margin-right:4px}
.pg{margin-top:14px;font-size:13px}
.pg a{color:#0969da;text-decoration:none;margin-right:8px}
.muted{color:#5b6572}.empty{color:#8a94a0;padding:14px 0}
.logout{float:right;font-size:12px;color:#5b6572}
</style>
</head>
<body>
<div class="wrap">
<?php if (!$isLoggedIn): ?>
  <div class="card" style="max-width:360px;margin:60px auto">
    <h1>登录</h1><h2>AI 记忆库管理页</h2>
    <form method="post" action="admin.php">
      <input type="hidden" name="action" value="login">
      <input type="password" name="password" placeholder="管理员密码" style="margin-bottom:12px">
      <button type="submit">登录</button>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <h1>AI 记忆库 · 管理 <a class="logout" href="admin.php" onclick="event.preventDefault();document.getElementById('logoutForm').submit()">退出</a></h1>
    <form id="logoutForm" method="post" action="admin.php" style="display:none"><input type="hidden" name="action" value="logout"></form>
    <h2>共 <?php echo (int)$total; ?> 条记忆</h2>

    <?php if (isset($_GET['saved']) && $_GET['saved'] === 'instructions'): ?>
      <div class="muted" style="background:#eefaf0;border:1px solid #c6e6cd;border-radius:6px;padding:8px 12px;margin-bottom:12px">指令已保存</div>
    <?php endif; ?>
    <?php if (isset($_GET['imported'])): ?>
      <?php $imp = explode('=', urldecode($_GET['imported']), 2); $impOk = isset($imp[0]) && $imp[0] === 'ok'; ?>
      <div class="muted" style="background:<?php echo $impOk ? '#eefaf0;border:1px solid #c6e6cd' : '#fff5f5;border:1px solid #ffd7d7'; ?>;border-radius:6px;padding:8px 12px;margin-bottom:12px">
        <?php echo $impOk ? '导入成功，共恢复 ' . (isset($imp[1]) ? (int)$imp[1] : 0) . ' 条记忆' : '导入失败或格式不正确'; ?>
      </div>
    <?php endif; ?>

    <div style="border-top:1px solid #eef1f4;padding:12px 0;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <a href="admin.php?export=1" style="background:#0969da;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-size:13px;text-decoration:none">一键导出全部数据</a>
      <form method="post" action="admin.php" enctype="multipart/form-data" style="display:flex;align-items:center;gap:8px">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(amcp_admin_csrfToken()); ?>">
        <input type="file" name="backup_file" accept=".json" required style="font-size:13px">
        <button type="submit" style="background:#57606a">导入备份</button>
      </form>
      <span class="muted" style="font-size:12px">备份含全部记忆(含向量)与模型指令；导入会追加而非覆盖</span>
    </div>

    <form method="post" action="admin.php" style="margin-bottom:18px;border-top:1px solid #eef1f4;padding-top:14px">
      <input type="hidden" name="action" value="save_instructions">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(amcp_admin_csrfToken()); ?>">
      <textarea name="instructions" style="min-height:96px" placeholder="给 AI 模型的使用规则（模型可通过 memory_instructions 工具读取）。留空表示无特殊规则。"><?php echo htmlspecialchars($app->instructions()->get()); ?></textarea>
      <button type="submit" style="margin-top:8px">保存指令</button>
    </form>

    <form method="post" action="admin.php" style="margin-bottom:18px">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(amcp_admin_csrfToken()); ?>">
      <textarea name="content" placeholder="输入新的记忆内容…" required></textarea>
      <input type="text" name="tags" placeholder="标签（用英文逗号分隔，可选）" style="margin:8px 0 12px">
      <button type="submit">添加记忆</button>
    </form>

    <form method="get" action="admin.php" class="tbar">
      <input type="text" name="q" placeholder="搜索记忆…" value="<?php echo htmlspecialchars($searchQuery); ?>" style="display:inline-block;width:70%">
      <button type="submit" style="background:#57606a">搜索</button>
    </form>
  </div>

  <div class="card">
    <?php if (!$entries): ?>
      <div class="empty">暂无记忆条目<?php echo $searchQuery!==''?'（未匹配到「'.htmlspecialchars($searchQuery).'」）':''; ?></div>
    <?php else: ?>
      <?php foreach ($entries as $e): ?>
        <div class="row">
          <div><?php echo nl2br(htmlspecialchars($e['content'])); ?></div>
          <div class="meta">
            <?php if ($e['tags']): foreach ($e['tags'] as $t): ?><span class="tag"><?php echo htmlspecialchars($t); ?></span><?php endforeach; endif; ?>
            <?php echo $e['vector']!==null?'有向量':'无向量'; ?> · 模型 <?php echo $e['model']!==null?htmlspecialchars($e['model']):'无'; ?> · <?php echo date('Y-m-d H:i', (int)($e['created_at']/1000)); ?>
            <form method="post" action="admin.php" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(amcp_admin_csrfToken()); ?>">
              <input type="hidden" name="id" value="<?php echo htmlspecialchars($e['id']); ?>">
              <button type="submit" class="ghost" onclick="return confirm('确定删除这条记忆？')">删除</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php
      $pages = max(1, (int) ceil($total / $limit));
      if ($pages > 1):
      ?>
      <div class="pg">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <a href="admin.php?page=<?php echo $i; ?>&q=<?php echo urlencode($searchQuery); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
</body>
</html>