<?php
/**
 * 便签公开分享页（token 鉴权，只读）
 * 安全设计（参考图床 s.php）：
 *  - 36 位 UUID v4 token，非枚举不可猜
 *  - share_until 有效期控制，过期即 404 并永久失效
 *  - 无 session 依赖；页面不回传任何作者信息
 *  - 内容渲染在前端由 PixelMD 白名单渲染（与站内一致，XSS 防护同一套）
 */
require_once __DIR__ . '/config/database.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
// 注：nginx 拦截 404 替换响应体，所以"不存在"也用 200 输出自定义错误页
http_response_code(200);

$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
$f = isset($_GET['f']) ? (string)$_GET['f'] : '';
// ?t= 便签分享 / ?f= 文件夹分享（两个参数互斥，token 格式同 36 位 UUID）
if ($f !== '' && strlen($f) === 36 && preg_match('/^[a-f0-9-]{36}$/', $f)) {
    $t = '';
    $fToken = $f;
} elseif (strlen($t) !== 36 || !preg_match('/^[a-f0-9-]{36}$/', $t)) {
    $notfound = true;
    $fToken = '';
} else {
    $notfound = false;
    $fToken = '';
}

$note = null;
$folder = null;      // 文件夹分享模式：['name'=>..]
$folderNotes = array();   // 递归收集的便签（含路径标签）
if (!$notfound) {
    try {
        $pdo = getDB();
        // 自愈：确保 share_token 列存在（首次访问可能列还没建）
        $schemaErr = null;
        ensureTables($schemaErr);

        if ($fToken !== '') {
            // ===== 文件夹分享 =====
            $st = $pdo->prepare("SELECT name, share_until FROM pn_folders WHERE share_token = ?");
            $st->execute(array($fToken));
            $folder = $st->fetch();
            if (!$folder) {
                $notfound = true;
            } elseif ((int)$folder['share_until'] > 0 && time() > (int)$folder['share_until']) {
                $pdo->prepare("UPDATE pn_folders SET share_token = '', share_until = 0 WHERE share_token = ?")->execute(array($fToken));
                $notfound = true;
            } else {
                header('Cache-Control: public, max-age=300');
                // 收集该文件夹整个子树（防环：只在同 user_id 的树里走），交给前端做可导航视图
                $stF = $pdo->prepare("SELECT id, user_id, parent_id, name FROM pn_folders WHERE share_token = ?");
                $stF->execute(array($fToken));
                $rootF = $stF->fetch();
                $ownerId = (int)$rootF['user_id'];
                $stAll = $pdo->prepare("SELECT id, parent_id, name FROM pn_folders WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
                $stAll->execute(array($ownerId));
                $byId = array();
                $children = array();
                foreach ($stAll->fetchAll() as $row) {
                    $fid = (int)$row['id'];
                    $byId[$fid] = $row;
                    $pid = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
                    if (!isset($children[$pid])) $children[$pid] = array();
                    $children[$pid][] = $fid;
                }
                // BFS 收集子树（含自身），上限 500 个防异常环
                $collect = array((int)$rootF['id']);
                for ($i = 0; $i < count($collect) && $i < 500; $i++) {
                    $fid = $collect[$i];
                    if (isset($children[$fid])) foreach ($children[$fid] as $cid) if (!in_array($cid, $collect, true)) $collect[] = $cid;
                }
                // 子树文件夹清单（前端导航用）
                $folderNodes = array();
                foreach ($collect as $fid) {
                    if (!isset($byId[$fid])) continue;
                    $folderNodes[] = array(
                        'id' => $fid,
                        'parent_id' => $byId[$fid]['parent_id'] === null ? 0 : (int)$byId[$fid]['parent_id'],
                        'name' => (string)$byId[$fid]['name'],
                    );
                }
                // 子树全部便签（前端按当前层级过滤展示）
                $marks = implode(',', array_fill(0, count($collect), '?'));
                $stN = $pdo->prepare("SELECT id, title, content, color, updated_at, pinned, folder_id FROM pn_notes
                                       WHERE user_id = ? AND folder_id IN ($marks) ORDER BY pinned DESC, sort_order ASC, updated_at DESC");
                $stN->execute(array_merge(array($ownerId), $collect));
                $folderNotes = array();
                foreach ($stN->fetchAll() as $row) {
                    $folderNotes[] = array(
                        'id' => (int)$row['id'],
                        'title' => (string)$row['title'],
                        'content' => (string)$row['content'],
                        'color' => (string)$row['color'],
                        'updated_at' => (string)$row['updated_at'],
                        'pinned' => (int)$row['pinned'],
                        'folder_id' => (int)$row['folder_id'],
                    );
                }
                $folder = array('name' => (string)$rootF['name']);
                $folderPayload = array('rootId' => (int)$rootF['id'], 'folders' => $folderNodes, 'notes' => $folderNotes);
            }
        } else {
            // ===== 便签分享 =====
            $st = $pdo->prepare("SELECT title, content, color, updated_at, share_until FROM pn_notes WHERE share_token = ?");
            $st->execute(array($t));
            $note = $st->fetch();

            if (!$note) {
                $notfound = true;
            } elseif ((int)$note['share_until'] > 0 && time() > (int)$note['share_until']) {
                // 过期：清掉 token 让链接永久失效
                $pdo->prepare("UPDATE pn_notes SET share_token = '', share_until = 0 WHERE share_token = ?")->execute(array($t));
                $notfound = true;
            } else {
                // 有效：允许 CDN 短缓存（链接含随机 token，缓存安全）
                header('Cache-Control: public, max-age=300');
            }
        }
    } catch (Exception $e) {
        $notfound = true;
    }
}
// 确保始终 200（nginx 不拦截）
http_response_code(200);
$colorMap = array('yellow' => '#ffd866', 'pink' => '#ff8fbf', 'blue' => '#6fcbff', 'green' => '#80ffb3', 'purple' => '#c8a0ff', 'orange' => '#ffb366');
$accent = isset($note['color']) && isset($colorMap[$note['color']]) ? $colorMap[$note['color']] : '#4af0ff';?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $notfound ? '分享不存在' : '分享的便签 - Pixel Notes' ?></title>
<link rel="stylesheet" href="css/pixel.css?v=35">
<script src="js/md.js?v=35"></script>
</head>
<body>
<div class="share-wrap">
<?php if ($notfound): ?>
    <div class="share-empty">
        <div class="icon">💀</div>
        <p>分享不存在或已过期</p>
        <a class="btn btn-outline" href="/">返回首页</a>
    </div>
<?php elseif ($folder !== null): ?>
    <div class="share-card">
        <div class="share-head">
            <span class="share-badge">📁 分享的文件夹</span>
            <span class="share-time">只读 · 共 <?= count($folderNotes) ?> 条便签</span>
        </div>
        <h1 class="share-title">📁 <?= htmlspecialchars($folder['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="folder-crumb" id="sfCrumb" style="margin:4px 0 12px;"></div>
        <div class="sf-grid" id="sfGrid"></div>
        <div class="notes-grid" id="sfNotes" style="grid-column:auto;"></div>
    </div>
    <div class="share-foot">Powered by <a href="/">Pixel Notes</a></div>
    <script>
        (function () {
            var DATA = <?= json_encode($folderPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            var byId = {}, childrenOf = {};
            DATA.folders.forEach(function (f) { byId[f.id] = f; });
            DATA.folders.forEach(function (f) {
                var pid = f.parent_id || 0;
                if (!childrenOf[pid]) childrenOf[pid] = [];
                childrenOf[pid].push(f);
            });
            var cur = DATA.rootId;
            function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
            // 子树便签总数（文件夹卡片上显示，与主页口径一致）
            function subtreeCount(fid) {
                var n = 0, stack = [fid], seen = {};
                while (stack.length) {
                    var x = stack.pop();
                    if (seen[x]) continue;
                    seen[x] = 1;
                    DATA.notes.forEach(function (nt) { if (nt.folder_id === x) n++; });
                    (childrenOf[x] || []).forEach(function (c) { stack.push(c.id); });
                }
                return n;
            }
            // 面包屑：根文件夹名起头
            function renderCrumb() {
                var crumb = document.getElementById('sfCrumb');
                var chain = [], c = cur, guard = 0;
                while (c && byId[c] && guard++ < 20) { chain.unshift(byId[c]); c = byId[c].parent_id || 0; }
                var html = '';
                chain.forEach(function (f, i) {
                    if (i > 0) html += '<span class="crumb-sep">/</span>';
                    html += '<a class="crumb' + (f.id === cur ? ' current' : '') + '" data-fid="' + f.id + '">📁 ' + esc(f.name) + '</a>';
                });
                crumb.innerHTML = html;
                crumb.querySelectorAll('.crumb').forEach(function (a) {
                    a.addEventListener('click', function () { cur = parseInt(a.getAttribute('data-fid')); render(); window.scrollTo(0, 0); });
                });
            }
            // ===== 只读全文弹窗（主页阅读弹窗的只读版：无编辑/删除/分享按钮） =====
            var modalEsc = null;
            function closeReadModal() {
                var ov = document.querySelector('.read-modal-overlay');
                if (ov) ov.remove();
                if (modalEsc) { document.removeEventListener('keydown', modalEsc, true); modalEsc = null; }
            }
            function openReadModal(note) {
                closeReadModal();
                var overlay = document.createElement('div');
                overlay.className = 'md-modal-overlay read-modal-overlay';
                var modal = document.createElement('div');
                modal.className = 'md-modal';
                var head = document.createElement('div');
                head.className = 'md-modal-head';
                head.innerHTML = '<div class="md-modal-title">' + esc(note.title || '无标题') + '</div>';
                var closeBtn = document.createElement('button');
                closeBtn.className = 'md-modal-close';
                closeBtn.textContent = '✖ 关闭';
                closeBtn.addEventListener('click', closeReadModal);
                head.appendChild(closeBtn);
                var body = document.createElement('div');
                body.className = 'md-modal-body';
                var contentDiv = document.createElement('div');
                contentDiv.className = 'note-content md-body';
                contentDiv.innerHTML = window.PixelMD.render(note.content || '');
                body.appendChild(contentDiv);
                var foot = document.createElement('div');
                foot.className = 'md-modal-foot';
                foot.innerHTML = '<span class="md-hint">🕐 更新于 ' + esc(note.updated_at || '') + ' · Esc 关闭 · 只读</span>';
                modal.appendChild(head); modal.appendChild(body); modal.appendChild(foot);
                overlay.appendChild(modal);
                overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeReadModal(); });
                modalEsc = function (e) { if (e.key === 'Escape') closeReadModal(); };
                document.addEventListener('keydown', modalEsc, true);
                document.body.appendChild(overlay);
            }
            function render() {
                renderCrumb();
                var grid = document.getElementById('sfGrid');
                var notesWrap = document.getElementById('sfNotes');
                grid.innerHTML = '';
                notesWrap.innerHTML = '';
                // 子文件夹卡片（与主页 folder-card 同款样式）
                (childrenOf[cur] || []).forEach(function (f) {
                    var card = document.createElement('div');
                    card.className = 'folder-card';
                    card.innerHTML = '<div class="folder-icon">📁</div><div class="folder-name">' + esc(f.name) + '</div>'
                        + '<div class="folder-count">' + subtreeCount(f.id) + ' 条便签</div>';
                    card.addEventListener('click', function () { cur = f.id; render(); window.scrollTo(0, 0); });
                    grid.appendChild(card);
                });
                // 当前层级的便签：主页同款 note-card（只读，点击看全文）
                var notes = DATA.notes.filter(function (n) { return n.folder_id === cur; });
                if ((childrenOf[cur] || []).length === 0 && notes.length === 0) {
                    notesWrap.innerHTML = '<div class="share-empty" style="padding:30px 10px;grid-column:1/-1;"><p>这个文件夹是空的</p></div>';
                    return;
                }
                notes.forEach(function (n) {
                    var card = document.createElement('div');
                    card.className = 'note-card ' + esc(n.color) + (n.pinned ? ' pinned' : '');
                    card.innerHTML = '<div class="note-title">' + esc(n.title || '无标题') + '</div>'
                        + '<div class="note-content md-body md-static">' + window.PixelMD.render(n.content || '') + '</div>'
                        + '<div class="note-meta"><span>🕐 ' + esc(n.updated_at || '') + '</span><span style="margin-left:auto;color:#4af0ff;font-size:10px;">👁 点击卡片查看全文</span></div>';
                    // 截断检测（主页同款）+「📖 阅读全文」按钮
                    var nd = card.querySelector('.note-content');
                    var rm = document.createElement('button');
                    rm.className = 'read-more';
                    rm.textContent = '📖 阅读全文';
                    rm.addEventListener('click', function () { openReadModal(n); });
                    if (nd.scrollHeight > nd.clientHeight + 6) {
                        nd.classList.add('clamped');
                        nd.insertAdjacentElement('afterend', rm);
                    }
                    // 点卡片任意位置（链接除外）看全文
                    card.addEventListener('click', function (e) {
                        if (e.target && e.target.closest && e.target.closest('a')) return;
                        openReadModal(n);
                    });
                    notesWrap.appendChild(card);
                });
            }
            render();
        })();
    </script>
<?php else: ?>
    <div class="share-card note-<?= htmlspecialchars($note['color'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="share-head">
            <span class="share-badge">📖 分享的便签</span>
            <span class="share-time">🕐 <?= htmlspecialchars($note['updated_at'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="share-title"><?= htmlspecialchars($note['title'] !== '' ? $note['title'] : '无标题', ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="note-content md-body" id="shareContent"></div>
    </div>
    <div class="share-foot">Powered by <a href="/">Pixel Notes</a></div>
    <script>
        document.getElementById('shareContent').innerHTML = window.PixelMD.render(<?= json_encode((string)$note['content'], JSON_UNESCAPED_UNICODE) ?>);
    </script>
<?php endif; ?>
</div>
</body>
</html>
