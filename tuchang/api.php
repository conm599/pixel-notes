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
        $ins = db()->prepare('INSERT INTO img_images (uid, name, file, size, w, h, created_at, expire_at) VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute(array($uid, $name === '' ? 'api-upload' : $name, $file, $size, $w, $h, time(), $exp));
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
        $st = db()->prepare('SELECT id, name, size, w, h, created_at, expire_at, hits FROM img_images WHERE uid = ? ORDER BY id DESC');
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
                'hits' => (int)$r['hits']
            );
        }
        jout(array('ok' => true, 'count' => count($rows), 'images' => $rows));
    }

    // ---- 图片信息 ----
    if ($action === 'get') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
        if ($id <= 0) jerr('参数错误');
        $st = db()->prepare('SELECT id, name, size, w, h, created_at, expire_at, hits FROM img_images WHERE id = ? AND uid = ?');
        $st->execute(array($id, $uid));
        $r = $st->fetch();
        if (!$r) jerr('图片不存在', 404);
        jout(array('ok' => true, 'id' => (int)$r['id'], 'name' => $r['name'],
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('方法不允许', 405);
if (!csrf_ok()) jerr('CSRF 校验失败', 403);

$action = isset($_POST['action']) ? $_POST['action'] : '';
$uid = (int)$_SESSION['uid'];
cleanup_expired();

// 批量操作（会话模式与 API 模式共用）
if ($action === 'sharebatch' || $action === 'delbatch' || $action === 'zip') {
    batch_handlers($uid, $action);
}

// ============ 文件夹（Windows 风格归类，仅会话模式；内容上移绝不删图） ============
if ($action === 'folder_create') {
    $name = trim(substr(strip_tags(isset($_POST['name']) ? $_POST['name'] : ''), 0, 60));
    if ($name === '') jerr('文件夹名称不能为空');
    $st = db()->prepare('SELECT id FROM img_folders WHERE uid = ? AND name = ?');
    $st->execute(array($uid, $name));
    if ($st->fetch()) jerr('同名文件夹已存在');
    db()->prepare('INSERT INTO img_folders (uid, name, created_at) VALUES (?,?,?)')->execute(array($uid, $name, time()));
    jout(array('ok' => true, 'id' => (int)db()->lastInsertId(), 'name' => $name));
}
if ($action === 'folder_rename') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $name = trim(substr(strip_tags(isset($_POST['name']) ? $_POST['name'] : ''), 0, 60));
    if ($id <= 0 || $name === '') jerr('参数错误');
    $st = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('文件夹不存在', 404);
    $st = db()->prepare('SELECT id FROM img_folders WHERE uid = ? AND name = ? AND id <> ?');
    $st->execute(array($uid, $name, $id));
    if ($st->fetch()) jerr('同名文件夹已存在');
    db()->prepare('UPDATE img_folders SET name = ? WHERE id = ?')->execute(array($name, $id));
    jout(array('ok' => true, 'name' => $name));
}
if ($action === 'folder_delete') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    if ($id <= 0) jerr('参数错误');
    $st = db()->prepare('SELECT id FROM img_folders WHERE id = ? AND uid = ?');
    $st->execute(array($id, $uid));
    if (!$st->fetch()) jerr('文件夹不存在', 404);
    db()->prepare('UPDATE img_images SET folder_id = NULL WHERE folder_id = ? AND uid = ?')->execute(array($id, $uid));
    db()->prepare('DELETE FROM img_folders WHERE id = ?')->execute(array($id));
    jout(array('ok' => true));
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
