<?php
/**
 * 文件夹 API（多层嵌套）
 * GET    —— 返回文件夹树（含每层便签计数）
 * POST   —— 新建 {name, parent_id}
 * PUT    —— 改名 {id, name} / 移动 {id, parent_id} / 排序 {reorder:[{id,sort_order}]}
 * DELETE —— 删除空文件夹；非空时内容上移一级（绝不删便签）
 * 所有操作需要登录，所有权校验为准
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

function clipFolderName($s) {
    return function_exists('mb_substr') ? mb_substr(trim((string)$s), 0, 100, 'UTF-8') : substr(trim((string)$s), 0, 100);
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
    $now = date('Y-m-d H:i:s');

    // 校验目标文件夹属于当前用户（parent_id 可为 null=根层级）
    // 返回 true/false；$folderId 仅用于排除自身
    function folderOwned($pdo, $userId, $folderId) {
        if ($folderId === null || $folderId === 0) return true; // 根层级合法
        if ($folderId < 0) return false;
        $st = $pdo->prepare("SELECT id FROM pn_folders WHERE id = ? AND user_id = ?");
        $st->execute(array($folderId, $userId));
        return (bool)$st->fetch();
    }

    // 取某文件夹的全部祖先 id（含自身），用于环检测
    function folderAncestors($pdo, $userId, $folderId) {
        $chain = array();
        $cur = $folderId;
        $guard = 0;
        while ($cur !== null && $cur > 0 && $guard++ < 50) {
            $chain[] = (int)$cur;
            $st = $pdo->prepare("SELECT parent_id FROM pn_folders WHERE id = ? AND user_id = ?");
            $st->execute(array($cur, $userId));
            $row = $st->fetch();
            if (!$row) break;
            $cur = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        }
        return $chain;
    }

    // ================= GET：文件夹树 + 计数 =================
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT id, parent_id, name, sort_order, created_at FROM pn_folders WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
        $st->execute(array($userId));
        $folders = $st->fetchAll();
        if (!is_array($folders)) $folders = array();

        // 便签直接计数（按 folder_id 分组）
        $direct = array();
        $st = $pdo->prepare("SELECT folder_id, COUNT(*) AS c FROM pn_notes WHERE user_id = ? GROUP BY folder_id");
        $st->execute(array($userId));
        foreach ($st->fetchAll() as $row) {
            $fid = $row['folder_id'] === null ? 0 : (int)$row['folder_id'];
            $direct[$fid] = (int)$row['c'];
        }

        // 递归累计：note_count = 自己直接的 + 全部子孙文件夹的
        $parentOf = array();
        foreach ($folders as $f) {
            $parentOf[(int)$f['id']] = $f['parent_id'] === null ? 0 : (int)$f['parent_id'];
        }
        $roll = array();
        foreach ($direct as $fid => $c) {
            $cur = $fid;
            $guard = 0;
            while ($cur > 0 && $guard++ < 50) {
                if (!isset($roll[$cur])) $roll[$cur] = 0;
                $roll[$cur] += $c;
                $cur = isset($parentOf[$cur]) ? $parentOf[$cur] : 0;
            }
        }

        $nodes = array();
        foreach ($folders as $f) {
            $fid = (int)$f['id'];
            $nodes[] = array(
                'id' => $fid,
                'parent_id' => $f['parent_id'] === null ? null : (int)$f['parent_id'],
                'name' => $f['name'],
                'sort_order' => (int)$f['sort_order'],
                'note_count' => isset($roll[$fid]) ? $roll[$fid] : 0,
                'direct_count' => isset($direct[$fid]) ? $direct[$fid] : 0,
                'created_at' => $f['created_at']
            );
        }
        jsonResponse(array('success' => true, 'folders' => $nodes));
    }

    // ================= POST：新建 =================
    if ($method === 'POST') {
        $name = clipFolderName(isset($input['name']) ? $input['name'] : '');
        $parentId = array_key_exists('parent_id', $input) ? $input['parent_id'] : null;
        if ($parentId !== null) $parentId = (int)$parentId;

        if ($name === '') jsonResponse(array('success' => false, 'message' => '文件夹名不能为空'), 200);
        if ($parentId !== null && $parentId !== 0) {
            if (!folderOwned($pdo, $userId, $parentId)) {
                jsonResponse(array('success' => false, 'message' => '目标文件夹不存在'), 200);
            }
            // 深度限制：祖先链长度（含自身）≤ 5
            if (count(folderAncestors($pdo, $userId, $parentId)) >= 5) {
                jsonResponse(array('success' => false, 'message' => '文件夹嵌套过深（最多 5 层）'), 200);
            }
        } else {
            $parentId = null;
        }

        // 同父下重名拒绝
        $st = $pdo->prepare("SELECT id FROM pn_folders WHERE user_id = ? AND parent_id <=> ? AND name = ?");
        $st->execute(array($userId, $parentId, $name));
        if ($st->fetch()) jsonResponse(array('success' => false, 'message' => '同级已有同名文件夹'), 200);

        $maxSt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM pn_folders WHERE user_id = ? AND parent_id <=> ?");
        $maxSt->execute(array($userId, $parentId));
        $nextOrder = ((int)$maxSt->fetchColumn()) + 1;

        $st = $pdo->prepare("INSERT INTO pn_folders (user_id, parent_id, name, sort_order, created_at) VALUES (?, ?, ?, ?, ?)");
        $st->execute(array($userId, $parentId, $name, $nextOrder, $now));
        $fid = (int)$pdo->lastInsertId();

        jsonResponse(array(
            'success' => true,
            'message' => '文件夹已创建',
            'folder' => array('id' => $fid, 'parent_id' => $parentId, 'name' => $name, 'sort_order' => $nextOrder, 'note_count' => 0)
        ), 201);
    }

    // ================= PUT：改名 / 移动 / 排序 =================
    if ($method === 'PUT') {
        // 排序：{reorder: [{id, sort_order}]}
        if (isset($input['reorder']) && is_array($input['reorder'])) {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("UPDATE pn_folders SET sort_order = ? WHERE id = ? AND user_id = ?");
                foreach ($input['reorder'] as $item) {
                    $fid = isset($item['id']) ? intval($item['id']) : 0;
                    $order = isset($item['sort_order']) ? intval($item['sort_order']) : 0;
                    if ($fid > 0) $upd->execute(array($order, $fid, $userId));
                }
                $pdo->commit();
                jsonResponse(array('success' => true, 'message' => '排序已保存'));
            } catch (Exception $e) {
                $pdo->rollBack();
                jsonResponse(array('success' => false, 'message' => '排序保存失败'), 500);
            }
        }

        $fid = isset($input['id']) ? intval($input['id']) : 0;
        if ($fid <= 0) jsonResponse(array('success' => false, 'message' => '无效的文件夹ID'), 400);

        $st = $pdo->prepare("SELECT id FROM pn_folders WHERE id = ? AND user_id = ?");
        $st->execute(array($fid, $userId));
        if (!$st->fetch()) jsonResponse(array('success' => false, 'message' => '文件夹不存在'), 200);

        $fields = array();
        $params = array();

        if (isset($input['name'])) {
            $name = clipFolderName($input['name']);
            if ($name === '') jsonResponse(array('success' => false, 'message' => '文件夹名不能为空'), 200);
            // 改名时同父重名校验（排除自己）
            $st = $pdo->prepare("SELECT id FROM pn_folders WHERE user_id = ? AND parent_id <=> (SELECT parent_id FROM pn_folders WHERE id = ?) AND name = ? AND id <> ?");
            $st->execute(array($userId, $fid, $name, $fid));
            if ($st->fetch()) jsonResponse(array('success' => false, 'message' => '同级已有同名文件夹'), 200);
            $fields[] = "name = ?";
            $params[] = $name;
        }

        // 移动：{parent_id}（null=移到根层级）
        if (array_key_exists('parent_id', $input)) {
            $parentId = $input['parent_id'];
            $parentId = ($parentId === null || $parentId === 0) ? null : (int)$parentId;
            if ($parentId !== null) {
                if (!folderOwned($pdo, $userId, $parentId)) {
                    jsonResponse(array('success' => false, 'message' => '目标文件夹不存在'), 200);
                }
                if ($parentId === $fid) {
                    jsonResponse(array('success' => false, 'message' => '不能移到自己里面'), 200);
                }
                // 环检测：目标不能是自己的后代（即 fid 在目标的祖先链里）
                if (in_array($fid, folderAncestors($pdo, $userId, $parentId))) {
                    jsonResponse(array('success' => false, 'message' => '不能移到自己的子文件夹里'), 200);
                }
                if (count(folderAncestors($pdo, $userId, $parentId)) >= 5) {
                    jsonResponse(array('success' => false, 'message' => '文件夹嵌套过深（最多 5 层）'), 200);
                }
            }
            $fields[] = "parent_id = ?";
            $params[] = $parentId;
        }

        if (count($fields) === 0) {
            jsonResponse(array('success' => false, 'message' => '没有需要更新的字段'), 400);
        }

        $params[] = $fid;
        $sql = "UPDATE pn_folders SET " . implode(', ', $fields) . " WHERE id = ?";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        jsonResponse(array('success' => true, 'message' => '文件夹已更新'));
    }

    // ================= DELETE：内容上移一级 =================
    if ($method === 'DELETE') {
        $fid = isset($input['id']) ? intval($input['id']) : 0;
        if ($fid <= 0) jsonResponse(array('success' => false, 'message' => '无效的文件夹ID'), 400);

        $st = $pdo->prepare("SELECT parent_id FROM pn_folders WHERE id = ? AND user_id = ?");
        $st->execute(array($fid, $userId));
        $row = $st->fetch();
        if (!$row) jsonResponse(array('success' => false, 'message' => '文件夹不存在'), 200);

        $grandParent = $row['parent_id'] === null ? null : (int)$row['parent_id'];

        $pdo->beginTransaction();
        try {
            // 子文件夹上移一级
            $st = $pdo->prepare("UPDATE pn_folders SET parent_id = ? WHERE parent_id = ? AND user_id = ?");
            $st->execute(array($grandParent, $fid, $userId));
            // 便签上移一级
            $st = $pdo->prepare("UPDATE pn_notes SET folder_id = ? WHERE folder_id = ? AND user_id = ?");
            $st->execute(array($grandParent, $fid, $userId));
            // 删空壳
            $st = $pdo->prepare("DELETE FROM pn_folders WHERE id = ? AND user_id = ?");
            $st->execute(array($fid, $userId));
            $pdo->commit();
            jsonResponse(array('success' => true, 'message' => '文件夹已删除，内容已上移一级'));
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(array('success' => false, 'message' => '删除失败'), 500);
        }
    }

    jsonResponse(array('success' => false, 'message' => '不支持的请求方法'), 405);

} catch (PDOException $e) {
    jsonResponse(array('success' => false, 'message' => '数据库错误，请稍后再试'), 500);
} catch (Exception $e) {
    jsonResponse(array('success' => false, 'message' => '服务器内部错误'), 500);
}
