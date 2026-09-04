<?php
// ================= 陶瓦图床 · API（上传/删除/改过期 + API Key 接口） =================
define('TAWA_IMG', true);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
function jout($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr($msg, $code = 400) {
    http_response_code($code);
    jout(array('ok' => false, 'err' => $msg));
}

// ==== 文件夹辅助（对齐便签 folders.php 逻辑：多层/环检测/同父重名/上移删除） ====
// 取某文件夹祖先 id 链（含自身），用于环检测与深度限制
function folderAncestorChain($uid, $folderId) {
    $chain = array();
    $cur = (int)$folderId;
    $guard = 0;
    while ($cur > 0 && $guard++ < 50) {
        $chain[] = $cur;
        $st = db()->prepare('SELECT parent_id FROM img_folders WHERE id = ? AND uid = ?');
        $st->execute(array($cur, $uid));
        $row = $st->fetch();
        if (!$row) break;
        $cur = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
    }
    return $chain;
}
// 确保 API 归档夹存在（根级，名「api」），返回其 id
function ensureApiFolder($uid) {
    $st = db()->prepare('SELECT id FROM img_folders WHERE uid = ? AND parent_id IS NULL AND name = ?');
    $st->execute(array($uid, 'api'));
    $row = $st->fetch();
    if ($row) return (int)$row['id'];
    db()->prepare('INSERT INTO img_folders (uid, parent_id, name, sort_order, created_at) VALUES (?, NULL, ?, 0, ?)')
       ->execute(array($uid, 'api', time()));
    return (int)db()->lastInsertId();
}
// 新建/移动时的 parent 校验（返回 null=根；出错返回错误文案）
function folderCheckParent($uid, $parentId, $excludeId = 0) {
    if ($parentId === null || $parentId === 0) return null;
    $st = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array((int)$parentId, $uid));
    if (!$st->fetch()) return '目标文件夹不存在';
    if ($excludeId > 0 && (int)$parentId === $excludeId) return '不能移到自己里面';
    if ($excludeId > 0 && in_array($excludeId, folderAncestorChain($uid, (int)$parentId))) return '不能移到自己的子文件夹里';
    if (count(folderAncestorChain($uid, (int)$parentId)) >= 5) return '文件夹嵌套过深（最多 5 层）';
    return null;
}

// ============ API Key 鉴权（外部程序/Mod 调用） ============
$apiUid = api_auth_user();
$isApi = $apiUid > 0;

if ($isApi) {
    // API 模式：无 session/CSRF 要求，用 Key 鉴权
    if (!rate_check('api_' . $apiUid, 60, 60)) jerr('请求过于频繁', 429);
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    $uid = $apiUid;
    cleanup_expired();

    // ---- 上传（multipart：img 文件；或 JSON：base64 图片） ----
    if ($action === 'upload') {
        $raw = null;
        if (isset($_FILES['img']) && is_uploaded_file($_FILES['img']['tmp_name'])) {
            $raw = file_get_contents($_FILES['img']['tmp_name']);
            $name = isset($_POST['name']) ? trim(substr(strip_tags($_POST['name']), 0, 200)) : 'api-upload';
        } else {
            $in = json_body();
            if (is_array($in) && isset($in['image'])) {
                $raw = base64_decode($in['image'], true);
                $name = isset($in['name']) ? trim(substr(strip_tags($in['name']), 0, 200)) : 'api-upload';
            }
        }
        if ($raw === null || $raw === false || strlen($raw) <= 0) jerr('未收到图片数据');
        if (strlen($raw) > MAX_UPLOAD) jerr('文件过大（上限 ' . (MAX_UPLOAD / 1048576) . 'MB）');

        // 魔数白名单
        $head = substr($raw, 0, 16);
        $okSig = false;
        if (substr($head, 0, 4) === "RIFF" && substr($head, 8, 4) === "WEBP") $okSig = true;
        elseif (bin2hex(substr($head, 0, 3)) === 'ffd8ff') $okSig = true;
        elseif (bin2hex(substr($head, 0, 4)) === '89504e47') $okSig = true;
        elseif (substr($head, 0, 3) === 'GIF') $okSig = true;
        if (!$okSig) jerr('不支持的图片格式');

        $img = @imagecreatefromstring($raw);
        if ($img === false) jerr('图片解码失败');
        $w = imagesx($img); $h = imagesy($img);
        if ($w < 1 || $h < 1 || $w > MAX_DIM || $h > MAX_DIM) jerr('图片尺寸不合法');
        if ($w > 4096 || $h > 4096) {
            $scale = 4096 / max($w, $h);
            $nw = (int)($w * $scale); $nh = (int)($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst; $w = $nw; $h = $nh;
        }
        $ob = fopen('php://temp', 'w+');
        if (!imagewebp($img, $ob, 60)) { imagedestroy($img); jerr('WebP 编码失败'); }
        rewind($ob);
        $webp = stream_get_contents($ob);
        fclose($ob);
        imagedestroy($img);
        $size = strlen($webp);
        if ($size <= 0 || $size > MAX_COMPRESSED) jerr('压缩后文件超出 ' . (MAX_COMPRESSED / 1048576) . 'MB');

        if (user_used($uid) + $size > user_quota($uid)) {
            jerr('空间配额不足');
        }
        if (!is_dir(IMG_DIR)) @mkdir(IMG_DIR, 0755, true);
        $file = rand_name() . '.webp';
        if (file_put_contents(IMG_DIR . $file, $webp) === false) jerr('存储写入失败', 500);

        $exp = 0;
        if (isset($_POST['expire'])) {
            $v = (int)$_POST['expire'];
            $allowed = array_map('intval', explode(',', EXPIRE_OPTIONS));
            if (in_array($v, $allowed, true)) $exp = $v === 0 ? 0 : time() + $v;
        }
        // folder_id：显式指定优先（multipart/JSON 字段，夹不存在/非本人时忽略）；
        // 未指定时自动归档到「api」夹（根级，无则自动创建）——API 上传与网页上传分区
        $apiFolderId = null;
        $fvRaw = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : (isset($in['folder_id']) ? (int)$in['folder_id'] : 0);
        if ($fvRaw > 0) {
            $fst = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
            $fst->execute(array($fvRaw, $uid));
            if ($fst->fetch()) $apiFolderId = $fvRaw;
        }
        if ($apiFolderId === null) {
            $apiFolderId = ensureApiFolder($uid);
        }
        $ins = db()->prepare('INSERT INTO img_images (uid, name, file, size, w, h, created_at, expire_at, folder_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $ins->execute(array($uid, $name === '' ? 'api-upload' : $name, $file, $size, $w, $h, time(), $exp, $apiFolderId));
        $id = (int)db()->lastInsertId();
        // API 上传默认自动创建公开分享（Mod 等外部程序场景）；传 share=0 保持私有（Web 前端）
        $autoShare = !isset($_POST['share']) || (int)$_POST['share'] === 1;
        if ($autoShare) {
            $tok = uuid_v4();
            db()->prepare('UPDATE img_images SET share_token = ?, share_until = 0 WHERE id = ?')->execute(array($tok, $id));
            list($url, $url2) = share_urls($tok);
        } else {
            $url = base_url() . 'i.php?id=' . $id;
            $url2 = '';
        }
        jout(array('ok' => true, 'id' => $id,
            'url' => $url,
            'url2' => $url2,
            'size' => $size, 'w' => $w, 'h' => $h));
    }

    // ---- 图片列表 ----
    if ($action === 'list') {
        // 可选 folder_id 过滤：0=未归类(folder_id IS NULL)，N=指定夹，缺省=全部（向后兼容）
        $lf = isset($_GET['folder_id']) ? (int)$_GET['folder_id'] : (isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : -1);
        $sql = 'SELECT id, name, size, w, h, created_at, expire_at, hits, folder_id FROM img_images WHERE uid = ?';
        if ($lf === 0) $sql .= ' AND folder_id IS NULL';
        elseif ($lf > 0) $sql .= ' AND folder_id = ' . $lf;
        $sql .= ' ORDER BY id DESC';
        $st = db()->prepare($sql);
        $st->execute(array($uid));
        $rows = array();
        foreach ($st->fetchAll() as $r) {
            $rows[] = array(
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'url' => base_url() . 'i.php?id=' . $r['id'],
                'size' => (int)$r['size'],
                'w' => (int)$r['w'],
                'h' => (int)$r['h'],
                'created_at' => (int)$r['created_at'],
                'expire_at' => (int)$r['expire_at'],
                'hits' => (int)$r['hits'],
                'folder_id' => $r['folder_id'] === null ? 0 : (int)$r['folder_id']
            );
        }
        jout(array('ok' => true, 'count' => count($rows), 'images' => $rows));
    }

    // ---- 文件夹列表（多层树 + 递归累计计数，对齐便签） ----
    if ($action === 'folder_list') {
        $fst = db()->prepare('SELECT id, parent_id, name, sort_order, created_at FROM img_folders WHERE uid = ? ORDER BY sort_order ASC, id ASC');
        $fst->execute(array($uid));
        $fs = $fst->fetchAll();
        $direct = array();
        $st = db()->prepare('SELECT folder_id, COUNT(*) AS c FROM img_images WHERE uid = ? GROUP BY folder_id');
        $st->execute(array($uid));
        foreach ($st->fetchAll() as $r) {
            $fid = $r['folder_id'] === null ? 0 : (int)$r['folder_id'];
            $direct[$fid] = (int)$r['c'];
        }
        $parentOf = array();
        foreach ($fs as $f) $parentOf[(int)$f['id']] = $f['parent_id'] === null ? 0 : (int)$f['parent_id'];
        $roll = array();
        foreach ($direct as $fid => $cn) {
            $cur = $fid; $guard = 0;
            while ($cur > 0 && $guard++ < 50) {
                if (!isset($roll[$cur])) $roll[$cur] = 0;
                $roll[$cur] += $cn;
                $cur = isset($parentOf[$cur]) ? $parentOf[$cur] : 0;
            }
        }
        $out = array();
        foreach ($fs as $f) {
            $fid = (int)$f['id'];
            $out[] = array('id' => $fid,
                'parent_id' => $f['parent_id'] === null ? 0 : (int)$f['parent_id'],
                'name' => $f['name'], 'sort_order' => (int)$f['sort_order'],
                'count' => isset($roll[$fid]) ? $roll[$fid] : 0,
                'direct_count' => isset($direct[$fid]) ? $direct[$fid] : 0,
                'created_at' => (int)$f['created_at']);
        }
        jout(array('ok' => true, 'folders' => $out));
    }

    // ---- 创建文件夹（多层：parent_id 可选，深≤5，同父重名拒绝） ----
    if ($action === 'folder_create') {
        $fname = isset($_POST['name']) ? trim(substr(strip_tags($_POST['name']), 0, 60)) : (isset($in['name']) ? trim(substr(strip_tags($in['name']), 0, 60)) : '');
        $parentId = null;
        $pv = isset($_POST['parent_id']) ? $_POST['parent_id'] : (isset($in['parent_id']) ? $in['parent_id'] : null);
        if ($pv !== null && $pv !== '') $parentId = (int)$pv;
        if ($fname === '') jerr('文件夹名称不能为空');
        if (($e = folderCheckParent($uid, $parentId)) !== null) jerr($e);
        $fst = db()->prepare('SELECT id FROM img_folders WHERE uid = ? AND parent_id <=> ? AND name = ?');
        $fst->execute(array($uid, $parentId, $fname));
        if ($fst->fetch()) jerr('同级已有同名文件夹');
        $fst = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM img_folders WHERE uid = ? AND parent_id <=> ?');
        $fst->execute(array($uid, $parentId));
        $order = (int)$fst->fetchColumn() + 1;
        db()->prepare('INSERT INTO img_folders (uid, parent_id, name, sort_order, created_at) VALUES (?,?,?,?,?)')
           ->execute(array($uid, $parentId, $fname, $order, time()));
        jout(array('ok' => true, 'id' => (int)db()->lastInsertId(), 'name' => $fname, 'parent_id' => $parentId));
    }

    // ---- 重命名文件夹 ----
    if ($action === 'folder_rename') {
        $fid = isset($_POST['id']) ? (int)$_POST['id'] : (isset($in['id']) ? (int)$in['id'] : 0);
        $fname = isset($_POST['name']) ? trim(substr(strip_tags($_POST['name']), 0, 60)) : (isset($in['name']) ? trim(substr(strip_tags($in['name']), 0, 60)) : '');
        if ($fid <= 0 || $fname === '') jerr('参数错误');
        $fst = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
        $fst->execute(array($fid, $uid));
        if (!$fst->fetch()) jerr('文件夹不存在', 404);
        $fst = db()->prepare('SELECT id FROM img_folders WHERE uid = ? AND parent_id <=> (SELECT parent_id FROM img_folders WHERE id = ?) AND name = ? AND id <> ?');
        $fst->execute(array($uid, $fid, $fname, $fid));
        if ($fst->fetch()) jerr('同级已有同名文件夹');
        db()->prepare('UPDATE img_folders SET name = ? WHERE id = ?')->execute(array($fname, $fid));
        jout(array('ok' => true, 'name' => $fname));
    }

    // ---- 移动文件夹（环检测 + 深度限制） ----
    if ($action === 'folder_move') {
        $mid = isset($_POST['id']) ? (int)$_POST['id'] : (isset($in['id']) ? (int)$in['id'] : 0);
        $parentId = null;
        $pv = isset($_POST['parent_id']) ? $_POST['parent_id'] : (isset($in['parent_id']) ? $in['parent_id'] : null);
        if ($pv !== null && $pv !== '') $parentId = (int)$pv;
        if ($mid <= 0) jerr('参数错误');
        $fst = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
        $fst->execute(array($mid, $uid));
        if (!$fst->fetch()) jerr('文件夹不存在', 404);
        if (($e = folderCheckParent($uid, $parentId, $mid)) !== null) jerr($e);
        db()->prepare('UPDATE img_folders SET parent_id = ? WHERE id = ?')->execute(array($parentId, $mid));
        jout(array('ok' => true, 'parent_id' => $parentId));
    }

    // ---- 删除文件夹（夹内图片回未归类，绝不删图） ----
    if ($action === 'folder_delete') {
        $fid = isset($_POST['id']) ? (int)$_POST['id'] : (isset($in['id']) ? (int)$in['id'] : 0);
        if ($fid <= 0) jerr('参数错误');
        $fst = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
        $fst->execute(array($fid, $uid));
        if (!$fst->fetch()) jerr('文件夹不存在', 404);
        $fst = db()->prepare('SELECT parent_id FROM img_folders WHERE id = ?');
        $fst->execute(array($fid));
        $row = $fst->fetch();
        $grand = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        // 子文件夹与图片整体上移一级（对齐便签），绝不删图
        db()->prepare('UPDATE img_folders SET parent_id = ? WHERE parent_id = ? AND uid = ?')->execute(array($grand, $fid, $uid));
        db()->prepare('UPDATE img_images SET folder_id = ? WHERE folder_id = ? AND uid = ?')->execute(array($grand, $fid, $uid));
        db()->prepare('DELETE FROM img_folders WHERE id = ?')->execute(array($fid));
        jout(array('ok' => true));
    }

    // ---- 移动图片进/出文件夹（folder_id 0 或缺省 = 移出到未归类） ----
    if ($action === 'setfolder') {
        $iid = isset($_POST['id']) ? (int)$_POST['id'] : (isset($in['id']) ? (int)$in['id'] : 0);
        $fid = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : (isset($in['folder_id']) ? (int)$in['folder_id'] : 0);
        if ($iid <= 0) jerr('参数错误');
        $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($iid, $uid));
        if (!$st->fetch()) jerr('图片不存在', 404);
        if ($fid > 0) {
            $fst = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
            $fst->execute(array($fid, $uid));
            if (!$fst->fetch()) jerr('目标文件夹不存在', 404);
        }
        db()->prepare('UPDATE img_images SET folder_id = ? WHERE id = ?')->execute(array($fid > 0 ? $fid : null, $iid));
        jout(array('ok' => true, 'folder_id' => $fid > 0 ? $fid : 0));
    }

    // ---- 图片信息 ----
    if ($action === 'get') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if ($id <= 0) jerr('参数错误');
        $st = db()->prepare('SELECT id, name, size, w, h, created_at, expire_at, hits, folder_id FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        $r = $st->fetch();
        if (!$r) jerr('图片不存在', 404);
        jout(array('ok' => true, 'id' => (int)$r['id'], 'name' => $r['name'],
            'folder_id' => $r['folder_id'] === null ? 0 : (int)$r['folder_id'],
            'url' => base_url() . 'i.php?id=' . $r['id'],
            'download' => base_url() . 'api.php?key=' . $_GET['key'] . '&action=download&id=' . $r['id'],
            'size' => (int)$r['size'], 'w' => (int)$r['w'], 'h' => (int)$r['h'],
            'created_at' => (int)$r['created_at'], 'expire_at' => (int)$r['expire_at'], 'hits' => (int)$r['hits']));
    }

    // ---- 下载原图（webp 二进制） ----
    if ($action === 'download') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if ($id <= 0) jerr('参数错误');
        $st = db()->prepare('SELECT id, file, name, size FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        $r = $st->fetch();
        if (!$r) jerr('图片不存在', 404);
        $path = IMG_DIR . $r['file'];
        if (!is_file($path)) jerr('文件丢失', 404);
        header('Content-Type: image/webp');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '_', $r['name']) . '.webp"');
        header('Content-Length: ' . (int)$r['size']);
        readfile($path);
        exit;
    }

    // ---- 创建/更新分享（API 模式） ----
    if ($action === 'share') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $v = isset($_POST['duration']) ? (int)$_POST['duration'] : 0;
        $allowed = array_map('intval', explode(',', EXPIRE_OPTIONS));
        if ($id <= 0 || !in_array($v, $allowed, true)) jerr('参数错误');
        $st = db()->prepare('SELECT id, share_token FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        $row = $st->fetch();
        if (!$row) jerr('图片不存在');
        $tok = !empty($row['share_token']) ? $row['share_token'] : uuid_v4();
        $until = $v === 0 ? 0 : time() + $v;
        db()->prepare('UPDATE img_images SET share_token = ?, share_until = ? WHERE id = ?')
           ->execute(array($tok, $until, $id));
        list($url, $url2) = share_urls($tok);
        jout(array('ok' => true, 'token' => $tok,
            'url' => $url, 'url2' => $url2, 'until' => $until));
    }

    // ---- 取消分享（API 模式） ----
    if ($action === 'unshare') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) jerr('参数错误');
        $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        if (!$st->fetch()) jerr('图片不存在');
        db()->prepare('UPDATE img_images SET share_token = NULL, share_until = 0 WHERE id = ?')->execute(array($id));
        jout(array('ok' => true));
    }

    // ---- 改过期（API 模式） ----
    if ($action === 'setexpire') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $v = isset($_POST['expire']) ? (int)$_POST['expire'] : 0;
        $allowed = array_map('intval', explode(',', EXPIRE_OPTIONS));
        if ($id <= 0 || !in_array($v, $allowed, true)) jerr('参数错误');
        $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        if (!$st->fetch()) jerr('图片不存在');
        $exp = $v === 0 ? 0 : time() + $v;
        db()->prepare('UPDATE img_images SET expire_at = ? WHERE id = ?')->execute(array($exp, $id));
        jout(array('ok' => true));
    }

    // ---- rename (API mode) ----
    if ($action === 'rename') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name = isset($_POST['name']) ? trim(substr(strip_tags($_POST['name']), 0, 200)) : '';
        if ($id <= 0 || $name === '') jerr('bad params');
        $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        if (!$st->fetch()) jerr('not found', 404);
        db()->prepare('UPDATE img_images SET name = ? WHERE id = ?')->execute(array($name, $id));
        jout(array('ok' => true, 'name' => $name));
    }

    // ---- 删除（API 模式） ----
    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) jerr('参数错误');
        $st = db()->prepare('SELECT id, file FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        $row = $st->fetch();
        if (!$row) jerr('图片不存在');
        @unlink(IMG_DIR . $row['file']);
        db()->prepare('DELETE FROM img_images WHERE id = ?')->execute(array($id));
        jout(array('ok' => true));
    }

    // ---- 批量操作（API 模式同样支持） ----
    if ($action === 'sharebatch' || $action === 'delbatch' || $action === 'zip') {
        batch_handlers($uid, $action);
    }

    jerr('未知操作', 400);
}

// ============ 网页登录会话模式 ============
if (!is_logged_in()) jerr('未登录', 401);
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'GET') jerr('方法不允许', 405);
if (!csrf_ok()) jerr('CSRF 校验失败', 403);

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');   // GET 支持：只读操作走 GET 绕开线路丢包
// GET 仅放行只读操作（线路 POST 响应偶发丢包的规避通道）；写操作一律 POST
if ($method === 'GET' && !in_array($action, array('list', 'folder_list', 'folder_share_info'), true)) jerr('写操作请使用 POST', 405);
$uid = (int)$_SESSION['uid'];
cleanup_expired();

// 批量操作（会话模式与 API 模式共用）
if ($action === 'sharebatch' || $action === 'delbatch' || $action === 'zip') {
    batch_handlers($uid, $action);
}

// ============ 文件夹（对齐便签 folders.php：多层嵌套/环检测/同父重名/删除上移一级，绝不删图） ============
if ($action === 'folder_create') {
    $name = trim(substr(strip_tags(isset($_POST['name']) ? $_POST['name'] : ''), 0, 60));
    $parentId = isset($_POST['parent_id']) ? ($_POST['parent_id'] === '' ? null : (int)$_POST['parent_id']) : null;
    if ($name === '') jerr('文件夹名称不能为空');
    if (($e = folderCheckParent($uid, $parentId)) !== null) jerr($e);
    $st = db()->prepare('SELECT id FROM img_folders WHERE uid = ? AND parent_id <=> ? AND name = ?');
    $st->execute(array($uid, $parentId, $name));
    if ($st->fetch()) jerr('同级已有同名文件夹');
    $st = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM img_folders WHERE uid = ? AND parent_id <=> ?');
    $st->execute(array($uid, $parentId));
    $order = (int)$st->fetchColumn() + 1;
    db()->prepare('INSERT INTO img_folders (uid, parent_id, name, sort_order, created_at) VALUES (?,?,?,?,?)')
       ->execute(array($uid, $parentId, $name, $order, time()));
    jout(array('ok' => true, 'id' => (int)db()->lastInsertId(), 'name' => $name, 'parent_id' => $parentId));
}
if ($action === 'folder_rename') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $name = trim(substr(strip_tags(isset($_POST['name']) ? $_POST['name'] : ''), 0, 60));
    if ($id <= 0 || $name === '') jerr('参数错误');
    $st = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('文件夹不存在', 404);
    $st = db()->prepare('SELECT id FROM img_folders WHERE uid = ? AND parent_id <=> (SELECT parent_id FROM img_folders WHERE id = ?) AND name = ? AND id <> ?');
    $st->execute(array($uid, $id, $name, $id));
    if ($st->fetch()) jerr('同级已有同名文件夹');
    db()->prepare('UPDATE img_folders SET name = ? WHERE id = ?')->execute(array($name, $id));
    jout(array('ok' => true, 'name' => $name));
}
if ($action === 'folder_move') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $parentId = isset($_POST['parent_id']) ? ($_POST['parent_id'] === '' ? null : (int)$_POST['parent_id']) : null;
    if ($id <= 0) jerr('参数错误');
    $st = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('文件夹不存在', 404);
    if (($e = folderCheckParent($uid, $parentId, $id)) !== null) jerr($e);
    db()->prepare('UPDATE img_folders SET parent_id = ? WHERE id = ?')->execute(array($parentId, $id));
    jout(array('ok' => true, 'parent_id' => $parentId));
}
if ($action === 'folder_share') {
    // 文件夹公开分享（学便签）：hours=0 永久 / -1 撤销；token 沿用不换（链接稳定）
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $hours = (int)(isset($_POST['hours']) ? $_POST['hours'] : 0);
    if ($id <= 0) jerr('参数错误');
    if ($hours < -1 || $hours > 87600) jerr('无效的有效期');
    $st = db()->prepare('SELECT id, share_token FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    $row = $st->fetch();
    if (!$row) jerr('文件夹不存在', 404);
    if ($hours === -1) {
        db()->prepare('UPDATE img_folders SET share_token = NULL, share_until = 0 WHERE id = ?')->execute(array($id));
        jout(array('ok' => true, 'cancelled' => true));
    }
    $tok = !empty($row['share_token']) ? $row['share_token'] : uuid_v4();
    $until = $hours === 0 ? 0 : time() + $hours * 3600;
    db()->prepare('UPDATE img_folders SET share_token = ?, share_until = ? WHERE id = ?')->execute(array($tok, $until, $id));
    $pref = PREFERRED_HOST;
    jout(array('ok' => true, 'token' => $tok, 'until' => $until,
        'url' => base_url() . 'fshare.php?t=' . $tok,
        'url2' => 'https://' . $pref . '/fshare.php?t=' . $tok));
}

// 文件夹分享状态查询（只读，GET 白名单）
if ($action === 'folder_share_info') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    if ($id <= 0) jerr('参数错误');
    $st = db()->prepare('SELECT name, share_token, share_until FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    $row = $st->fetch();
    if (!$row) jerr('文件夹不存在', 404);
    $tok = (string)$row['share_token'];
    $until = (int)$row['share_until'];
    $shared = strlen($tok) === 36 && ($until === 0 || time() <= $until);
    jout(array('ok' => true, 'name' => $row['name'], 'shared' => $shared ? 1 : 0,
        'until' => $shared ? $until : 0,
        'url' => $shared ? base_url() . 'fshare.php?t=' . $tok : '',
        'url2' => $shared ? 'https://' . PREFERRED_HOST . '/fshare.php?t=' . $tok : ''));
}

if ($action === 'folder_delete') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    if ($id <= 0) jerr('参数错误');
    $st = db()->prepare('SELECT parent_id FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    $row = $st->fetch();
    if (!$row) jerr('文件夹不存在', 404);
    $grand = $row['parent_id'] === null ? null : (int)$row['parent_id'];
    // 子文件夹与图片整体上移一级（对齐便签），绝不删图
    db()->prepare('UPDATE img_folders SET parent_id = ? WHERE parent_id = ? AND uid = ?')->execute(array($grand, $id, $uid));
    db()->prepare('UPDATE img_images SET folder_id = ? WHERE folder_id = ? AND uid = ?')->execute(array($grand, $id, $uid));
    db()->prepare('DELETE FROM img_folders WHERE id = ?')->execute(array($id));
    jout(array('ok' => true));
}
if ($action === 'list') {
    // SPA 数据源（便签同构）：一次拉全部图片+文件夹树
    $st = db()->prepare('SELECT id, name, size, w, h, created_at, expire_at, hits, share_token, share_until, folder_id FROM img_images WHERE uid = ? ORDER BY id DESC');
    $st->execute(array($uid));
    $imgs = array();
    foreach ($st->fetchAll() as $r) {
        $shared = !empty($r['share_token']) && ((int)$r['share_until'] === 0 || time() < (int)$r['share_until']);
        $imgs[] = array(
            'id' => (int)$r['id'], 'name' => $r['name'], 'size' => (int)$r['size'],
            'w' => (int)$r['w'], 'h' => (int)$r['h'],
            'created_at' => (int)$r['created_at'], 'expire_at' => (int)$r['expire_at'],
            'hits' => (int)$r['hits'],
            'folder_id' => $r['folder_id'] === null ? 0 : (int)$r['folder_id'],
            'shared' => $shared ? 1 : 0, 'share_token' => $shared ? $r['share_token'] : '',
            'share_until' => (int)$r['share_until'],
            'thumb' => base_url() . 'i.php?id=' . (int)$r['id'],
            'view' => 'view.php?id=' . (int)$r['id'] . '&u=' . my_uuid()
        );
    }
    $fst = db()->prepare('SELECT id, parent_id, name, sort_order, created_at FROM img_folders WHERE uid = ? ORDER BY sort_order ASC, id ASC');
    $fst->execute(array($uid));
    $fs = $fst->fetchAll();
    $direct = array();
    $st2 = db()->prepare('SELECT folder_id, COUNT(*) AS c FROM img_images WHERE uid = ? GROUP BY folder_id');
    $st2->execute(array($uid));
    foreach ($st2->fetchAll() as $r) {
        $fid = $r['folder_id'] === null ? 0 : (int)$r['folder_id'];
        $direct[$fid] = (int)$r['c'];
    }
    $parentOf = array();
    foreach ($fs as $f) $parentOf[(int)$f['id']] = $f['parent_id'] === null ? 0 : (int)$f['parent_id'];
    $roll = array();
    foreach ($direct as $fid => $cn) {
        $cur = $fid; $guard = 0;
        while ($cur > 0 && $guard++ < 50) {
            if (!isset($roll[$cur])) $roll[$cur] = 0;
            $roll[$cur] += $cn;
            $cur = isset($parentOf[$cur]) ? $parentOf[$cur] : 0;
        }
    }
    $tree = array();
    foreach ($fs as $f) {
        $fid = (int)$f['id'];
        $tree[] = array('id' => $fid,
            'parent_id' => $f['parent_id'] === null ? 0 : (int)$f['parent_id'],
            'name' => $f['name'], 'sort_order' => (int)$f['sort_order'],
            'count' => isset($roll[$fid]) ? $roll[$fid] : 0,
            'direct_count' => isset($direct[$fid]) ? $direct[$fid] : 0,
            'created_at' => (int)$f['created_at']);
    }
    jout(array('ok' => true, 'images' => $imgs, 'folders' => $tree));
}

if ($action === 'copybatch') {
    // 复制图片副本（Ctrl+C/V）：物理复制文件（独立生命周期，删副本不影响原图）；超额即停
    $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : array();
    $fid = (int)(isset($_POST['folder_id']) ? $_POST['folder_id'] : 0);
    if (!is_array($ids) || count($ids) === 0) jerr('参数错误');
    if ($fid > 0) {
        $fst = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
        $fst->execute(array($fid, $uid));
        if (!$fst->fetch()) jerr('目标文件夹不存在', 404);
    }
    $done = 0;
    $sel = db()->prepare('SELECT name, file, size, w, h, expire_at FROM img_images WHERE id = ? AND uid = ?');
    foreach ($ids as $iid) {
        $iid = (int)$iid;
        if ($iid <= 0) continue;
        $sel->execute(array($iid, $uid));
        $r = $sel->fetch();
        if (!$r) continue;
        if (user_used($uid) + (int)$r['size'] > user_quota($uid)) break; // 配额不足即停
        $newFile = rand_name() . '.webp';
        if (!@copy(IMG_DIR . $r['file'], IMG_DIR . $newFile)) continue;
        db()->prepare('INSERT INTO img_images (uid, name, file, size, w, h, created_at, expire_at, folder_id) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute(array($uid, $r['name'] . '（副本）', $newFile, (int)$r['size'], (int)$r['w'], (int)$r['h'], time(), (int)$r['expire_at'], $fid > 0 ? $fid : null));
        $done++;
    }
    jout(array('ok' => true, 'done' => $done));
}

if ($action === 'folder_list') {
    $fst = db()->prepare('SELECT id, parent_id, name, sort_order, created_at FROM img_folders WHERE uid = ? ORDER BY sort_order ASC, id ASC');
    $fst->execute(array($uid));
    $fs = $fst->fetchAll();
    $direct = array();
    $st = db()->prepare('SELECT folder_id, COUNT(*) AS c FROM img_images WHERE uid = ? GROUP BY folder_id');
    $st->execute(array($uid));
    foreach ($st->fetchAll() as $r) {
        $fid = $r['folder_id'] === null ? 0 : (int)$r['folder_id'];
        $direct[$fid] = (int)$r['c'];
    }
    $parentOf = array();
    foreach ($fs as $f) $parentOf[(int)$f['id']] = $f['parent_id'] === null ? 0 : (int)$f['parent_id'];
    $roll = array();
    foreach ($direct as $fid => $cn) {
        $cur = $fid; $guard = 0;
        while ($cur > 0 && $guard++ < 50) {
            if (!isset($roll[$cur])) $roll[$cur] = 0;
            $roll[$cur] += $cn;
            $cur = isset($parentOf[$cur]) ? $parentOf[$cur] : 0;
        }
    }
    $out = array();
    foreach ($fs as $f) {
        $fid = (int)$f['id'];
        $out[] = array('id' => $fid,
            'parent_id' => $f['parent_id'] === null ? 0 : (int)$f['parent_id'],
            'name' => $f['name'], 'sort_order' => (int)$f['sort_order'],
            'count' => isset($roll[$fid]) ? $roll[$fid] : 0,
            'direct_count' => isset($direct[$fid]) ? $direct[$fid] : 0,
            'created_at' => (int)$f['created_at']);
    }
    jout(array('ok' => true, 'folders' => $out));
}
if ($action === 'setfolder') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $fid = (int)(isset($_POST['folder_id']) ? $_POST['folder_id'] : 0); // 0 = 移出（未归类）
    if ($id <= 0) jerr('参数错误');
    $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('图片不存在', 404);
    if ($fid > 0) {
        $st = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
        $st->execute(array($fid, $uid));
        if (!$st->fetch()) jerr('目标文件夹不存在', 404);
    }
    db()->prepare('UPDATE img_images SET folder_id = ? WHERE id = ?')->execute(array($fid > 0 ? $fid : null, $id));
    jout(array('ok' => true));
}

// ============ 上传 ============
if ($action === 'upload') {
    if (!isset($_FILES['img']) || !is_uploaded_file($_FILES['img']['tmp_name'])) {
        jerr('未收到文件');
    }
    $f = $_FILES['img'];
    if ($f['error'] !== UPLOAD_ERR_OK) jerr('上传失败 (err ' . $f['error'] . ')');
    if ($f['size'] <= 0 || $f['size'] > MAX_UPLOAD) jerr('文件过大（上限 ' . (MAX_UPLOAD / 1048576) . 'MB）');

    // 魔数白名单校验（防伪造）
    $head = file_get_contents($f['tmp_name'], false, null, 0, 16);
    if ($head === false || strlen($head) < 4) jerr('无法读取文件');
    $okSig = false;
    if (substr($head, 0, 4) === "RIFF" && substr($head, 8, 4) === "WEBP") $okSig = true;
    elseif (bin2hex(substr($head, 0, 3)) === 'ffd8ff') $okSig = true;
    elseif (bin2hex(substr($head, 0, 4)) === '89504e47') $okSig = true;
    elseif (substr($head, 0, 3) === 'GIF') $okSig = true;
    if (!$okSig) jerr('不支持的图片格式');

    // GD 解码 + 重编码 WebP 60%（剥离 EXIF/隐藏内容）
    $img = @imagecreatefromstring(file_get_contents($f['tmp_name']));
    if ($img === false) jerr('图片解码失败');
    $w = imagesx($img); $h = imagesy($img);
    if ($w < 1 || $h < 1 || $w > MAX_DIM || $h > MAX_DIM) jerr('图片尺寸不合法（最大 ' . MAX_DIM . 'px）');
    if ($w > 4096 || $h > 4096) {
        // 前端已压缩，后端兜底缩小
        $scale = 4096 / max($w, $h);
        $nw = (int)($w * $scale); $nh = (int)($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst; $w = $nw; $h = $nh;
    }
    $ob = fopen('php://temp', 'w+');
    if (!imagewebp($img, $ob, 60)) { imagedestroy($img); jerr('WebP 编码失败'); }
    rewind($ob);
    $webp = stream_get_contents($ob);
    fclose($ob);
    imagedestroy($img);
    $size = strlen($webp);
    if ($size <= 0 || $size > MAX_UPLOAD) jerr('压缩后文件超出 ' . (MAX_COMPRESSED / 1048576) . 'MB');

    // 配额检查（每用户独立配额）
    if (user_used($uid) + $size > user_quota($uid)) {
        jerr('空间配额不足（当前配额 ' . round(user_quota($uid) / 1048576) . 'MB）');
    }

    // 随机文件名落盘
    if (!is_dir(IMG_DIR)) @mkdir(IMG_DIR, 0755, true);
    $file = rand_name() . '.webp';
    if (file_put_contents(IMG_DIR . $file, $webp) === false) jerr('存储写入失败', 500);

    $name = isset($_POST['name']) ? trim(substr(strip_tags($_POST['name']), 0, 200)) : 'image';
    if ($name === '') $name = 'image';
    $exp = 0;
    if (isset($_POST['expire'])) {
        $v = (int)$_POST['expire'];
        $allowed = array_map('intval', explode(',', EXPIRE_OPTIONS));
        if (in_array($v, $allowed, true)) $exp = $v === 0 ? 0 : time() + $v; // 绝对时间戳
    }
    // 归属文件夹（可选；仅接受自己的文件夹）
    $folderId = null;
    if (isset($_POST['folder_id'])) {
        $fv = (int)$_POST['folder_id'];
        if ($fv > 0) {
            $fst = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
            $fst->execute(array($fv, $uid));
            if ($fst->fetch()) $folderId = $fv;
        }
    }
    $ins = db()->prepare('INSERT INTO img_images (uid, name, file, size, w, h, created_at, expire_at, folder_id) VALUES (?,?,?,?,?,?,?,?,?)');
    $ins->execute(array($uid, $name, $file, $size, $w, $h, time(), $exp, $folderId));
    $id = (int)db()->lastInsertId();
    jout(array('ok' => true, 'id' => $id,
        'url' => base_url() . 'i.php?id=' . $id,
        'size' => $size, 'w' => $w, 'h' => $h, 'expire' => $exp));
}

// ============ 删除 ============
if ($action === 'delete') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) jerr('参数错误');
    $st = db()->prepare('SELECT id, file FROM img_images WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    $row = $st->fetch();
    if (!$row) jerr('图片不存在');
    @unlink(IMG_DIR . $row['file']);
    db()->prepare('DELETE FROM img_images WHERE id = ?')->execute(array($id));
    jout(array('ok' => true));
}

// ============ 改过期 ============
if ($action === 'setexpire') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $v = isset($_POST['expire']) ? (int)$_POST['expire'] : 0;
    $allowed = array_map('intval', explode(',', EXPIRE_OPTIONS));
    if ($id <= 0 || !in_array($v, $allowed, true)) jerr('参数错误');
    $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('图片不存在');
    $exp = $v === 0 ? 0 : time() + $v; // 绝对时间戳
    db()->prepare('UPDATE img_images SET expire_at = ? WHERE id = ?')->execute(array($exp, $id));
    jout(array('ok' => true));
}

// ============ rename ============
if ($action === 'rename') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = isset($_POST['name']) ? trim(substr(strip_tags($_POST['name']), 0, 200)) : '';
    if ($id <= 0 || $name === '') jerr('bad params');
    $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('not found', 404);
    db()->prepare('UPDATE img_images SET name = ? WHERE id = ?')->execute(array($name, $id));
    jout(array('ok' => true, 'name' => $name));
}

// ============ 创建/更新分享 ============
if ($action === 'share') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $v = isset($_POST['duration']) ? (int)$_POST['duration'] : 0;
    $allowed = array_map('intval', explode(',', EXPIRE_OPTIONS));
    if ($id <= 0 || !in_array($v, $allowed, true)) jerr('参数错误');
    $st = db()->prepare('SELECT id, share_token FROM img_images WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    $row = $st->fetch();
    if (!$row) jerr('图片不存在');
    $tok = !empty($row['share_token']) ? $row['share_token'] : uuid_v4();
    $until = $v === 0 ? 0 : time() + $v; // 绝对时间戳
    db()->prepare('UPDATE img_images SET share_token = ?, share_until = ? WHERE id = ?')
       ->execute(array($tok, $until, $id));
    list($url, $url2) = share_urls($tok);
    jout(array('ok' => true, 'token' => $tok,
        'url' => $url, 'url2' => $url2,
        'until' => $until));
}

// ============ 取消分享 ============
if ($action === 'unshare') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) jerr('参数错误');
    $st = db()->prepare('SELECT id FROM img_images WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('图片不存在');
    db()->prepare('UPDATE img_images SET share_token = NULL, share_until = 0 WHERE id = ?')->execute(array($id));
    jout(array('ok' => true));
}

// ============ API Key 管理 ============
if ($action === 'getkey') {
    $st = db()->prepare('SELECT api_key, created_at, last_used FROM img_api_keys WHERE uid = ? AND enabled = 1 ORDER BY id DESC LIMIT 1');
    $st->execute(array($uid));
    $r = $st->fetch();
    if (!$r) jout(array('ok' => true, 'key' => ''));
    jout(array('ok' => true, 'key' => $r['api_key'], 'created_at' => (int)$r['created_at'], 'last_used' => (int)$r['last_used']));
}
if ($action === 'genkey') {
    $key = api_key_new();
    // 先插入新 Key（若失败则旧 Key 不受影响）
    db()->prepare('INSERT INTO img_api_keys (uid, api_key, created_at) VALUES (?,?,?)')->execute(array($uid, $key, time()));
    // 立即禁用所有旧 Key（新 Key 刚插入不受影响）
    db()->prepare('UPDATE img_api_keys SET enabled = 0 WHERE uid = ? AND api_key != ?')->execute(array($uid, $key));
    // 清理已禁用的旧 Key
    db()->prepare('DELETE FROM img_api_keys WHERE uid = ? AND enabled = 0')->execute(array($uid));
    jout(array('ok' => true, 'key' => $key));
}
if ($action === 'delkey') {
    $key = isset($_POST['key']) ? (string)$_POST['key'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $key)) jerr('参数错误');
    db()->prepare('DELETE FROM img_api_keys WHERE uid = ? AND api_key = ?')->execute(array($uid, $key));
    jout(array('ok' => true));
}

// ============ 批量操作（sharebatch / delbatch / zip，会话与 API 模式共用） ============
function batch_handlers($uid, $action) {
    // 批量分享
    if ($action === 'sharebatch') {
        $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : array();
        if (count($ids) === 0) jerr('未选择图片');
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        if (count($ids) === 0) jerr('未选择图片');
        if (count($ids) > 50) jerr('一次最多 50 张');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, share_token FROM img_images WHERE uid = ? AND id IN ($ph)");
        $args = array_merge(array($uid), $ids);
        $st->execute($args);
        $rows = $st->fetchAll();
        if (count($rows) === 0) jerr('图片不存在');
        $out = array();
        $upd = db()->prepare('UPDATE img_images SET share_token = ?, share_until = 0 WHERE id = ?');
        foreach ($rows as $r) {
            $tok = !empty($r['share_token']) ? $r['share_token'] : uuid_v4();
            $upd->execute(array($tok, (int)$r['id']));
            list($url, $url2) = share_urls($tok);
            $out[] = array('id' => (int)$r['id'], 'url' => $url, 'url2' => $url2);
        }
        jout(array('ok' => true, 'count' => count($out), 'links' => $out));
    }

    // 批量删除
    if ($action === 'delbatch') {
        $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : array();
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        if (count($ids) === 0) jerr('未选择图片');
        if (count($ids) > 200) jerr('一次最多 200 张');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, file FROM img_images WHERE uid = ? AND id IN ($ph)");
        $args = array_merge(array($uid), $ids);
        $st->execute($args);
        $rows = $st->fetchAll();
        $del = db()->prepare('DELETE FROM img_images WHERE id = ?');
        $n = 0;
        foreach ($rows as $r) {
            @unlink(IMG_DIR . $r['file']);
            $del->execute(array((int)$r['id']));
            $n++;
        }
        jout(array('ok' => true, 'deleted' => $n));
    }

    // 打包下载 ZIP
    if ($action === 'zip') {
        if (!class_exists('ZipArchive')) jerr('服务器未启用 ZIP 扩展', 500);
        $ids = array();
        if (isset($_GET['ids']) && $_GET['ids'] !== '') {
            $ids = array_values(array_unique(array_map('intval', explode(',', $_GET['ids']))));
        } else {
            $st = db()->prepare('SELECT id FROM img_images WHERE uid = ?');
            $st->execute(array($uid));
            foreach ($st->fetchAll() as $r) $ids[] = (int)$r['id'];
        }
        if (count($ids) === 0) jerr('没有可下载的图片');
        if (count($ids) > 500) jerr('一次最多 500 张');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id, file, name, size FROM img_images WHERE uid = ? AND id IN ($ph)");
        $args = array_merge(array($uid), $ids);
        $st->execute($args);
        $rows = $st->fetchAll();
        if (count($rows) === 0) jerr('图片不存在');

        $tmp = tempnam(sys_get_temp_dir(), 'twzip');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) jerr('ZIP 创建失败', 500);
        $used = array();
        foreach ($rows as $r) {
            $path = IMG_DIR . $r['file'];
            if (!is_file($path)) continue;
            $baseName = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', $r['name']);
            $baseName = trim($baseName, '_');
            if ($baseName === '') $baseName = 'image';
            if (substr($baseName, -5) !== '.webp') $baseName .= '.webp';
            $n = 1;
            $target = $baseName;
            while (isset($used[$target])) {
                $target = pathinfo($baseName, PATHINFO_FILENAME) . "_$n.webp";
                $n++;
            }
            $used[$target] = true;
            $zip->addFile($path, $target);
        }
        $zip->close();
        if (!is_file($tmp)) jerr('ZIP 打包失败', 500);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="taowa-images-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}

jerr('未知操作', 400);
