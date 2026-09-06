<?php
/**
 * 笔记 CRUD API（安全加固版）
 * GET / POST / PUT / DELETE，所有操作需要登录
 *
 * 加固：移除 debug 泄露、空内容校验、长度截断、所有权校验保持
 */

require_once __DIR__ . '/../config/database.php';

startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sendSecurityHeaders();

ini_set('display_errors', '0');
error_reporting(E_ALL);

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
    exit;
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(array('success' => false, 'message' => '请先登录'), 401);
    }

    $userId = (int)$_SESSION['user_id'];
    $pdo = getDB();

    $schemaError = null;
    if (!ensureTables($schemaError)) {
        jsonResponse(array('success' => false, 'message' => '系统繁忙，请稍后再试'), 500);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = array();

    $allowedColors = array('yellow', 'pink', 'blue', 'green', 'purple', 'orange');
    $now = date('Y-m-d H:i:s');

    function clipTitle($s) {
        return function_exists('mb_substr') ? mb_substr(trim($s), 0, 200, 'UTF-8') : substr(trim($s), 0, 200);
    }
    function clipContent($s) {
        return function_exists('mb_substr') ? mb_substr((string)$s, 0, 60000, 'UTF-8') : substr((string)$s, 0, 60000);
    }

    // ================= 便签↔图床联动 =================
    define('IMG_POLICY_VER', (int)suite_cfg('img_policy_ver', 1)); // 政策升级后用户需重新确认

    // 从正文提取图床直链 token（严格 UUID；兼容 markdown 的 & 与 HTML 的 &amp;）
    function noteImgTokens($content) {
        $tokens = array();
        if (preg_match_all('/i\.php\?id=\d+&(?:amp;)?t=([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', (string)$content, $m)) {
            $tokens = array_values(array_unique(array_map('strtolower', $m[1])));
        }
        return $tokens;
    }
    // 调图床内部端点（服务端对服务端；尽力而为：任何失败都不阻塞便签保存）
    function imgInternalCall($op, $userId, $tokens) {
        if (!$tokens) return 0;
        // VPS 机内回环存在 hairpin 问题：可配 tuchang_internal_url=https://127.0.0.1，curl 自动带 Host 头路由到图床 server 块
        $cfg = suite_cfg('tuchang_internal_url', '');
        if ($cfg !== '') {
            $url = rtrim($cfg, '/') . '/api.php';
            $hdrs = array('X-Internal-Key: ' . suite_cfg('internal_key', ''), 'Host: ' . siblingHost('tuchang'));
        } else {
            $url = siblingUrl('tuchang', '/api.php');
            $hdrs = array('X-Internal-Key: ' . suite_cfg('internal_key', ''));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array('action' => 'internal_imgs', 'op' => $op, 'pn_uid' => $userId, 'tokens' => json_encode(array_values($tokens)))),
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) return 0;
        $j = json_decode($body, true);
        return (is_array($j) && !empty($j['ok'])) ? (int)$j['affected'] : 0;
    }
    // 保存后引用同步：消失的引用 → 30 天反悔期（仅当全库再无引用，防多便签共用图误杀）；活现的引用 → 恢复永久
    function imgSyncOnSave($userId, $oldContent, $newContent) {
        $old = noteImgTokens($oldContent);
        $new = noteImgTokens($newContent);
        $released = array_diff($old, $new);
        $restored = array_diff($new, $old);
        $n = 0;
        if ($released) {
            $chk = $GLOBALS['pdo']->prepare("SELECT COUNT(*) FROM pn_notes WHERE user_id = ? AND content LIKE ?");
            $still = array();
            foreach ($released as $t) {
                $chk->execute(array($userId, '%' . $t . '%'));
                if ((int)$chk->fetchColumn() === 0) $still[] = $t;
            }
            if ($still) $n = imgInternalCall('grace', $userId, $still);
        }
        if ($restored) imgInternalCall('restore', $userId, $restored);
        return $n;
    }

    // ================= GET =================
    if ($method === 'GET') {
        // 单条全文模式：?id=123 —— 编辑器/AI 打开时按需拉完整正文（首屏列表只传摘要）
        if (isset($_GET['id'])) {
            $st = $pdo->prepare("SELECT id, title, content, color, pinned, sort_order, folder_id, created_at, updated_at, share_token, share_until
                                 FROM pn_notes WHERE id = ? AND user_id = ?");
            $st->execute(array((int)$_GET['id'], $userId));
            $n = $st->fetch();
            if (!$n) jsonResponse(array('success' => false, 'message' => '便签不存在'), 404);
            $n['pinned'] = (int)$n['pinned'];
            $n['sort_order'] = (int)$n['sort_order'];
            $n['folder_id'] = $n['folder_id'] === null ? null : (int)$n['folder_id'];
            $base = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            if (!empty($n['share_token']) && strlen($n['share_token']) === 36) {
                $until = (int)$n['share_until'];
                if ($until > 0 && time() > $until) { $n['share_token'] = ''; $n['share_until'] = 0; }
                else { $n['share_url'] = $base . '/share.php?t=' . $n['share_token']; $n['share_until'] = $until; }
            }
            $n['_full'] = 1;
            jsonResponse(array('success' => true, 'note' => $n));
        }
        // 列表模式：content 只传前 2000 字摘要（首屏瘦身，正文点开编辑时按需 ?id= 拉全文）
        $stmt = $pdo->prepare("SELECT id, title, content, color, pinned, sort_order, folder_id, created_at, updated_at, share_token, share_until
                               FROM pn_notes WHERE user_id = ?
                               ORDER BY pinned DESC, sort_order ASC, updated_at DESC");
        $stmt->execute(array($userId));
        $notes = $stmt->fetchAll();
        if (!is_array($notes)) $notes = array();
        $base = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        foreach ($notes as &$n) {
            if (isset($n['pinned'])) $n['pinned'] = (int)$n['pinned'];
            if (isset($n['sort_order'])) $n['sort_order'] = (int)$n['sort_order'];
            if (array_key_exists('folder_id', $n)) $n['folder_id'] = $n['folder_id'] === null ? null : (int)$n['folder_id'];
            if (!empty($n['share_token']) && strlen($n['share_token']) === 36) {
                $until = (int)$n['share_until'];
                if ($until > 0 && time() > $until) { $n['share_token'] = ''; $n['share_until'] = 0; continue; }
                $n['share_url'] = $base . '/share.php?t=' . $n['share_token'];
                $n['share_until'] = $until;
            } else {
                $n['share_until'] = (int)$n['share_until'];
            }
            // 摘要截断：正文超过 2000 字只传前 2000 字并打 _more 标记（卡片渲染与搜索索引够用）
            $clen = function_exists('mb_strlen') ? mb_strlen((string)$n['content'], 'UTF-8') : strlen((string)$n['content']);
            if ($clen > 2000) {
                $n['content'] = function_exists('mb_substr') ? mb_substr((string)$n['content'], 0, 2000, 'UTF-8') : substr((string)$n['content'], 0, 2000);
                $n['_more'] = 1;
            }
        }
        jsonResponse(array('success' => true, 'notes' => $notes));
    }

    // ================= POST =================
    if ($method === 'POST') {
        $title   = clipTitle(isset($input['title']) ? $input['title'] : '');
        $content = trim(isset($input['content']) ? (string)$input['content'] : '');
        $color   = isset($input['color']) ? $input['color'] : 'yellow';
        $pinned  = !empty($input['pinned']) ? 1 : 0;
        $folderId = array_key_exists('folder_id', $input) ? $input['folder_id'] : null;
        $folderId = ($folderId === null || $folderId === 0) ? null : (int)$folderId;

        if ($title === '' && $content === '') {
            jsonResponse(array('success' => false, 'message' => '标题和内容不能都为空'), 400);
        }
        $content = clipContent($content);
        if (!in_array($color, $allowedColors)) $color = 'yellow';
        // 文件夹归属校验（防 IDOR）
        if ($folderId !== null) {
            $fSt = $pdo->prepare("SELECT id FROM pn_folders WHERE id = ? AND user_id = ?");
            $fSt->execute(array($folderId, $userId));
            if (!$fSt->fetch()) {
                jsonResponse(array('success' => false, 'message' => '目标文件夹不存在'), 200);
            }
        }

        // 新便签排到最后：sort_order = 该用户当前最大值 + 1
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM pn_notes WHERE user_id = ?");
        $maxStmt->execute(array($userId));
        $nextOrder = ((int)$maxStmt->fetchColumn()) + 1;

        $stmt = $pdo->prepare("INSERT INTO pn_notes (user_id, title, content, color, pinned, sort_order, folder_id, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(array($userId, $title, $content, $color, $pinned, $nextOrder, $folderId, $now, $now));
        $noteId = (int)$pdo->lastInsertId();

        // 便签↔图床联动：新便签内容中的引用（如整条复制）→ 恢复对应图片永久
        imgSyncOnSave($userId, '', $content);

        jsonResponse(array(
            'success' => true,
            'message' => '笔记已创建',
            'note' => array(
                'id' => $noteId, 'title' => $title, 'content' => $content,
                'color' => $color, 'pinned' => $pinned, 'folder_id' => $folderId,
                'created_at' => $now, 'updated_at' => $now
            )
        ), 201);
    }

    // ================= PUT =================
    if ($method === 'PUT') {
        // 便签↔图床联动：同意状态查询 { action:'img_consent_status' }
        if (isset($input['action']) && $input['action'] === 'img_consent_status') {
            $st = $pdo->prepare("SELECT img_consent, img_policy_ver FROM pn_users WHERE id = ?");
            $st->execute(array($userId));
            $u = $st->fetch();
            $consent = $u ? (int)$u['img_consent'] : 0;
            $ver = $u ? (int)$u['img_policy_ver'] : 0;
            if ($ver < IMG_POLICY_VER) $consent = 0; // 政策升级后视为未确认
            jsonResponse(array('success' => true, 'consent' => $consent, 'ver' => $ver, 'policy_ver' => IMG_POLICY_VER));
        }
        // 便签↔图床联动：设置同意状态 { action:'img_consent_set', agree: 1|-1|0 }
        if (isset($input['action']) && $input['action'] === 'img_consent_set') {
            $agree = isset($input['agree']) ? (int)$input['agree'] : 0;
            if (!in_array($agree, array(1, -1, 0), true)) jsonResponse(array('success' => false, 'message' => '参数错误'), 400);
            $st = $pdo->prepare("UPDATE pn_users SET img_consent = ?, img_consent_at = ?, img_policy_ver = ? WHERE id = ?");
            $st->execute(array($agree, time(), IMG_POLICY_VER, $userId));
            jsonResponse(array('success' => true, 'consent' => $agree));
        }

        // 分享管理：{ action:'share', id, hours }  hours=0 永久 / -1 取消分享
        if (isset($input['action']) && $input['action'] === 'share') {
            $noteId = isset($input['id']) ? intval($input['id']) : 0;
            $hours  = isset($input['hours']) ? intval($input['hours']) : 0;
            if ($noteId <= 0) {
                jsonResponse(array('success' => false, 'message' => '无效的笔记ID'), 200);
            }
            if ($hours < -1 || $hours > 31536000) {
                jsonResponse(array('success' => false, 'message' => '无效的有效期'), 200);
            }

            // 所有权校验（防 IDOR）
            $stmt = $pdo->prepare("SELECT id FROM pn_notes WHERE id = ? AND user_id = ?");
            $stmt->execute(array($noteId, $userId));
            if (!$stmt->fetch()) {
                jsonResponse(array('success' => false, 'message' => '笔记不存在'), 200);
            }

            // 取消分享：清空 token
            if ($hours === -1) {
                $stmt = $pdo->prepare("UPDATE pn_notes SET share_token = '', share_until = 0 WHERE id = ? AND user_id = ?");
                $stmt->execute(array($noteId, $userId));
                jsonResponse(array('success' => true, 'message' => '已取消分享', 'cancelled' => true));
            }

            // 已有 token 则沿用（链接稳定），否则生成 UUID v4
            $stmt = $pdo->prepare("SELECT share_token FROM pn_notes WHERE id = ? AND user_id = ?");
            $stmt->execute(array($noteId, $userId));
            $token = (string)$stmt->fetchColumn();
            if (strlen($token) !== 36) {
                $bytes = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
                $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
                $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
                $token = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            }

            $until = $hours > 0 ? time() + $hours * 3600 : 0;
            $stmt = $pdo->prepare("UPDATE pn_notes SET share_token = ?, share_until = ? WHERE id = ? AND user_id = ?");
            $stmt->execute(array($token, $until, $noteId, $userId));

            jsonResponse(array(
                'success' => true,
                'message' => $hours > 0 ? '分享已创建（' . $hours . '小时后过期）' : '分享已创建（永久）',
                'token' => $token,
                'until' => $until,
                'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/share.php?t=' . $token
            ));
        }

        // 批量排序更新：{ reorder: [{id:1, sort_order:0}, {id:2, sort_order:1}, ...] }
        if (isset($input['reorder']) && is_array($input['reorder'])) {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("UPDATE pn_notes SET sort_order = ? WHERE id = ? AND user_id = ?");
                foreach ($input['reorder'] as $item) {
                    $nid = isset($item['id']) ? intval($item['id']) : 0;
                    $order = isset($item['sort_order']) ? intval($item['sort_order']) : 0;
                    if ($nid > 0) {
                        $upd->execute(array($order, $nid, $userId));
                    }
                }
                $pdo->commit();
                jsonResponse(array('success' => true, 'message' => '排序已保存'));
            } catch (Exception $e) {
                $pdo->rollBack();
                jsonResponse(array('success' => false, 'message' => '排序保存失败'), 500);
            }
        }

        $noteId = isset($input['id']) ? intval($input['id']) : 0;
        if ($noteId <= 0) {
            jsonResponse(array('success' => false, 'message' => '无效的笔记ID'), 400);
        }

        // 所有权校验（防 IDOR）+ 取旧正文（联动 diff 基准）
        // 注：返回 200 + success:false 而非 404 —— 主机 nginx 会拦截 404 状态并替换响应体
        $stmt = $pdo->prepare("SELECT id, content FROM pn_notes WHERE id = ? AND user_id = ?");
        $stmt->execute(array($noteId, $userId));
        $ownerRow = $stmt->fetch();
        if (!$ownerRow) {
            jsonResponse(array('success' => false, 'message' => '笔记不存在'), 200);
        }

        $fields = array();
        $params = array();

        if (isset($input['title']))   { $fields[] = "title = ?";   $params[] = clipTitle($input['title']); }
        if (isset($input['content'])) { $fields[] = "content = ?"; $params[] = clipContent($input['content']); }
        if (isset($input['color'])) {
            $c = in_array($input['color'], $allowedColors) ? $input['color'] : 'yellow';
            $fields[] = "color = ?"; $params[] = $c;
        }
        if (isset($input['pinned']))  { $fields[] = "pinned = ?";  $params[] = !empty($input['pinned']) ? 1 : 0; }
        // 移动便签到文件夹（null=回主页），需校验目标归属（防 IDOR）
        if (array_key_exists('folder_id', $input)) {
            $folderId = $input['folder_id'];
            $folderId = ($folderId === null || $folderId === 0) ? null : (int)$folderId;
            if ($folderId !== null) {
                $fSt = $pdo->prepare("SELECT id FROM pn_folders WHERE id = ? AND user_id = ?");
                $fSt->execute(array($folderId, $userId));
                if (!$fSt->fetch()) {
                    jsonResponse(array('success' => false, 'message' => '目标文件夹不存在'), 200);
                }
            }
            $fields[] = "folder_id = ?";
            $params[] = $folderId;
        }

        if (count($fields) === 0) {
            jsonResponse(array('success' => false, 'message' => '没有需要更新的字段'), 400);
        }

        $fields[] = "updated_at = ?";
        $params[] = $now;
        $params[] = $noteId;

        $sql = "UPDATE pn_notes SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // 便签↔图床联动：保存后引用同步（消失→30天反悔期，复活→恢复永久），前端按 img_released toast
        $imgReleased = 0;
        if (isset($input['content'])) {
            $imgReleased = imgSyncOnSave($userId, (string)$ownerRow['content'], clipContent($input['content']));
        }

        jsonResponse(array('success' => true, 'message' => '笔记已更新', 'img_released' => $imgReleased));
    }

    // ================= DELETE =================
    if ($method === 'DELETE') {
        $noteId = 0;
        if (isset($input['id'])) $noteId = intval($input['id']);

        if ($noteId <= 0) {
            jsonResponse(array('success' => false, 'message' => '无效的笔记ID'), 400);
        }

        // 取旧正文（联动：整条删除=全部引用释放）
        $stmt = $pdo->prepare("SELECT content FROM pn_notes WHERE id = ? AND user_id = ?");
        $stmt->execute(array($noteId, $userId));
        $oldContent = (string)$stmt->fetchColumn();

        $stmt = $pdo->prepare("DELETE FROM pn_notes WHERE id = ? AND user_id = ?");
        $stmt->execute(array($noteId, $userId));

        if ($stmt->rowCount() === 0) {
            // 200 而非 404：避免 nginx 拦截替换 JSON 响应体
            jsonResponse(array('success' => false, 'message' => '笔记不存在'), 200);
        }

        // 便签↔图床联动：删除便签 → 释放其图片引用（图床保留 30 天）
        $imgReleased = imgSyncOnSave($userId, $oldContent, '');

        jsonResponse(array('success' => true, 'message' => '笔记已删除', 'img_released' => $imgReleased));
    }

    jsonResponse(array('success' => false, 'message' => '不支持的请求方法'), 405);

} catch (PDOException $e) {
    jsonResponse(array('success' => false, 'message' => '数据库错误，请稍后再试'), 500);
} catch (Exception $e) {
    jsonResponse(array('success' => false, 'message' => '服务器内部错误'), 500);
}