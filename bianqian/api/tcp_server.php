<?php
/**
 * 桌面状态屏明文 TCP 守护服务 (Raw TCP Socket Server)
 * 专为单片机 (ESP32-C3) 极简接入设计，零 HTTP/HTTPS 协议开销，零 TLS 握手开销
 *
 * 协议规范（请求单行发送，以 \n 结尾）：
 * 1. 极简文本格式（首选推荐，MCU 内存消耗最小）：
 *    - STATUS <API_KEY>      -> 返回状态 JSON + \n，立即关闭连接
 *    - ROAST <API_KEY>       -> 逐字流式回传吐槽（0.1s/字），传输完毕关闭连接
 *    - MOCK_STATUS [KEY]     -> 返回 Mock 状态 JSON
 *    - MOCK_ROAST [KEY]      -> 逐字流式回传 Mock 吐槽
 *
 * 2. JSON 格式（兼容）：
 *    - {"action":"status","key":"<API_KEY>"}
 *    - {"action":"roast","key":"<API_KEY>"}
 *
 * 异常响应：
 *    - ERR 401 UNAUTHORIZED
 *    - ERR 429 TOO MANY REQUESTS
 *    - ERR 503 SERVICE UNAVAILABLE
 *    - ERR 400 BAD REQUEST
 *
 * 运行方式：
 *    php tcp_server.php --port=8888
 *    或由 systemd / supervisor 常驻后台
 */

// 仅限 CLI 模式运行
if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI.\n");
}

require_once __DIR__ . '/../config/database.php';

// 解析命令行参数
$opts = getopt('', array('host:', 'port:'));
$host = isset($opts['host']) ? $opts['host'] : '0.0.0.0';
$port = isset($opts['port']) ? (int)$opts['port'] : 8888;

$address = "tcp://{$host}:{$port}";
$context = stream_context_create();
$server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if (!$server) {
    fwrite(STDERR, "[-] Failed to start TCP server on {$address}: [{$errno}] {$errstr}\n");
    exit(1);
}

// 设置非阻塞
stream_set_blocking($server, false);

fwrite(STDOUT, "[+] Pixel Notes Plain TCP Server started on {$address} (PID: " . getmypid() . ")\n");
fwrite(STDOUT, "[+] Supported commands: STATUS <key>, ROAST <key>, MOCK_STATUS, MOCK_ROAST\n");

// 时区上海
date_default_timezone_set(suite_cfg('app_timezone', 'Asia/Shanghai'));

// 简单的客户端跟踪
$clients = array();

while (true) {
    $read = array($server);
    foreach ($clients as $c) {
        $read[] = $c['socket'];
    }
    $write = null;
    $except = null;

    $numChanged = @stream_select($read, $write, $except, 0, 200000); // 200ms 超时

    if ($numChanged === false) {
        break; // 收到中断信号
    }

    if ($numChanged > 0) {
        // 新连接进入
        if (in_array($server, $read, true)) {
            $clientSocket = @stream_socket_accept($server, 0);
            if ($clientSocket) {
                stream_set_blocking($clientSocket, false);
                $id = (int)$clientSocket;
                $clients[$id] = array(
                    'socket' => $clientSocket,
                    'buffer' => '',
                    'connected_at' => time()
                );
            }
            // 从可读列表中移除 server，剩下的是客户端
            $key = array_search($server, $read, true);
            unset($read[$key]);
        }

        // 处理已有客户端发送的数据
        foreach ($read as $sock) {
            $id = (int)$sock;
            if (!isset($clients[$id])) continue;

            $data = @fread($sock, 1024);
            if ($data === false || $data === '') {
                // 客户端主动断开
                @fclose($sock);
                unset($clients[$id]);
                continue;
            }

            $clients[$id]['buffer'] .= $data;

            // 遇到换行符则解析指令
            if (strpos($clients[$id]['buffer'], "\n") !== false || strpos($clients[$id]['buffer'], "\r") !== false) {
                $rawCmd = trim($clients[$id]['buffer']);
                $clients[$id]['buffer'] = ''; // 清空缓冲区

                if ($rawCmd !== '') {
                    handleTcpRequest($sock, $rawCmd);
                }
                // 处理完毕关闭连接（MCU Pull 模式，一问一答后断开，开销最小）
                @fclose($sock);
                unset($clients[$id]);
            }
        }
    }

    // 清理超时空闲连接（超过 15 秒未发指令的丢弃）
    $now = time();
    foreach ($clients as $id => $c) {
        if ($now - $c['connected_at'] > 15) {
            @fclose($c['socket']);
            unset($clients[$id]);
        }
    }
}

/**
 * 处理单个 TCP 指令
 */
function handleTcpRequest($sock, $raw) {
    $action = '';
    $key = '';

    // 支持 JSON 格式
    if ($raw[0] === '{') {
        $json = @json_decode($raw, true);
        if ($json && is_array($json)) {
            $action = isset($json['action']) ? strtoupper(trim($json['action'])) : '';
            $key = isset($json['key']) ? trim($json['key']) : '';
        }
    } else {
        // 纯文本格式：ACTION <KEY> [ARGS...]
        $tokens = preg_split('/\s+/', trim($raw));
        $action = strtoupper($tokens[0]);
        $key = isset($tokens[1]) ? trim($tokens[1]) : '';
    }

    // 天气代理指令（同端口明文 TCP 代理 uapis.cn 天气服务，需校验 Desk Key 防刷）
    // 格式: WEATHER <KEY> [city|adcode] 或 {"action":"weather","key":"...","city":"..."}
    if ($action === 'WEATHER') {
        $cityOrCode = '';
        if ($raw[0] === '{') {
            $json = @json_decode($raw, true);
            $key = isset($json['key']) ? trim($json['key']) : '';
            if (!empty($json['adcode'])) {
                $cityOrCode = trim((string)$json['adcode']);
            } elseif (!empty($json['city'])) {
                $cityOrCode = trim((string)$json['city']);
            }
        } else {
            // 支持: WEATHER <KEY> | WEATHER <KEY> 北京 | WEATHER <KEY> 110000
            $tokens = preg_split('/\s+/', trim($raw));
            array_shift($tokens); // 去掉 "WEATHER"
            if (!empty($tokens)) {
                $key = array_shift($tokens);
                if (!empty($tokens)) {
                    $cityOrCode = implode(' ', $tokens);
                }
            }
        }

        if ($key === '') {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }
        $owner = getDeskKeyOwner($key);
        if (!$owner) {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }

        try {
            $pdo = getDB();
            if ($pdo) {
                $pdo->prepare("UPDATE `pn_api_keys` SET `last_used_at` = NOW() WHERE `id` = ?")->execute(array((int)$owner['id']));
            }
        } catch (Throwable $e) {}

        $weatherJson = fetchWeatherProxy($cityOrCode);
        if ($weatherJson !== null) {
            @fwrite($sock, $weatherJson . "\n");
        } else {
            @fwrite($sock, "ERR 503 WEATHER SERVICE UNAVAILABLE\n");
        }
        return;
    }

    // 程序员历史上的今天代理指令（同端口明文 TCP，需校验 Desk Key 防刷）
    // 格式: PROGRAMMER_TODAY <KEY> | HISTORY <KEY> 或 {"action":"history","key":"..."}
    if ($action === 'PROGRAMMER_TODAY' || $action === 'HISTORY' || $action === 'DEV_TODAY') {
        if ($raw[0] === '{') {
            $json = @json_decode($raw, true);
            $key = isset($json['key']) ? trim($json['key']) : '';
        } else {
            $tokens = preg_split('/\s+/', trim($raw));
            $key = isset($tokens[1]) ? trim($tokens[1]) : '';
        }

        if ($key === '') {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }
        $owner = getDeskKeyOwner($key);
        if (!$owner) {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }

        try {
            $pdo = getDB();
            if ($pdo) {
                $pdo->prepare("UPDATE `pn_api_keys` SET `last_used_at` = NOW() WHERE `id` = ?")->execute(array((int)$owner['id']));
            }
        } catch (Throwable $e) {}

        $historyJson = fetchProgrammerTodayProxy();
        if ($historyJson !== null) {
            @fwrite($sock, $historyJson . "\n");
        } else {
            @fwrite($sock, "ERR 503 SERVICE UNAVAILABLE\n");
        }
        return;
    }

    // 番茄小说服务免鉴权联调指令
    if ($action === 'MOCK_NOVEL') {
        $tokens = preg_split('/\s+/', trim($raw));
        $page = isset($tokens[1]) ? (int)$tokens[1] : 1;
        forwardFanqieTcp($sock, "MOCK_NOVEL {$page}");
        return;
    }

    // 番茄小说服务代理指令（仅限管理员，带 Desk Key）
    // 指令支持：NOVEL, CHAPTER, SEARCH, CHAPTERS, BOOKS, PICK, INFO, DL, TASK, NOVEL_STATUS, FQ_STATUS, FQ
    $novelActions = array(
        'NOVEL', 'CHAPTER', 'SEARCH', 'CHAPTERS', 'BOOKS', 'PICK',
        'INFO', 'DL', 'TASK', 'NOVEL_STATUS', 'FQ_STATUS', 'FQ', 'NOVEL_SHELF', 'SHELF', 'PROGRESS', 'NOVEL_PROGRESS', 'PAGE'
    );
    if (in_array($action, $novelActions, true)) {
        if ($raw[0] === '{') {
            $json = @json_decode($raw, true);
            $key = isset($json['key']) ? trim($json['key']) : '';
        } else {
            $tokens = preg_split('/\s+/', trim($raw));
            $key = isset($tokens[1]) ? trim($tokens[1]) : '';
        }

        if ($key === '') {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }
        $owner = getDeskKeyOwner($key);
        if (!$owner) {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }
        // 权限严格拦截：仅限管理员使用小说代理服务
        if (empty($owner['is_admin']) || (int)$owner['is_admin'] !== 1) {
            @fwrite($sock, "ERR 403 ADMIN ONLY\n");
            return;
        }

        try {
            $pdo = getDB();
            if ($pdo) {
                $pdo->prepare("UPDATE `pn_api_keys` SET `last_used_at` = NOW() WHERE `id` = ?")->execute(array((int)$owner['id']));
            }
        } catch (Throwable $e) {}

        $fqKey = function_exists('getFanqieKey') ? getFanqieKey() : '5b42436aea8a5296e40b4b95d2ed2ad7';

        if ($action === 'NOVEL_STATUS' || $action === 'FQ_STATUS') {
            $upstreamCmd = "STATUS " . $fqKey;
        } elseif ($action === 'FQ') {
            // 格式: FQ <KEY> <SUB_CMD> [ARGS...]
            if ($raw[0] === '{') {
                $sub = isset($json['cmd']) ? trim($json['cmd']) : 'status';
                $upstreamCmd = strtoupper($sub) . " " . $fqKey;
            } else {
                $parts = preg_split('/\s+/', trim($raw), 3);
                $subCmd = isset($parts[2]) ? trim($parts[2]) : 'STATUS';
                $subTokens = preg_split('/\s+/', $subCmd);
                $realSubAct = array_shift($subTokens);
                $upstreamCmd = strtoupper($realSubAct) . " " . $fqKey . (!empty($subTokens) ? " " . implode(' ', $subTokens) : "");
            }
        } else {
            $userId = (int)$owner['user_id'];

            // 1. 获取推送到设备的 10 本书单（BOOKS / NOVEL_SHELF / SHELF / NOVEL_LIST）
            if ($action === 'BOOKS' || $action === 'NOVEL_SHELF' || $action === 'SHELF' || $action === 'NOVEL_LIST') {
                $userBooks = function_exists('getUserNovels') ? getUserNovels($userId) : array();
                $cur = function_exists('getCurrentUserNovel') ? getCurrentUserNovel($userId) : null;
                $resList = array();
                foreach ($userBooks as $ub) {
                    $pts = !empty($ub['pts']) ? (int)$ub['pts'] : (!empty($ub['updated_at']) ? strtotime($ub['updated_at']) : (!empty($ub['created_at']) ? strtotime($ub['created_at']) : time()));
                    $resList[] = array(
                        'i'      => (int)($ub['i'] ?? 1),
                        'id'     => $ub['book_id'],
                        'title'  => $ub['title'],
                        'author' => $ub['author'],
                        'page'   => max(1, (int)$ub['current_page']),
                        'pages'  => max(1, (int)$ub['total_pages']),
                        'pts'    => (int)$pts,
                    );
                }
                $shelfData = array(
                    'total'   => count($resList),
                    'current' => $cur ? $cur['book_id'] : '',
                    'books'   => $resList
                );
                @fwrite($sock, json_encode($shelfData, JSON_UNESCAPED_UNICODE) . "\n");
                return;
            }

            // 2. 选定当前书籍（PICK / NOVEL_PICK）：支持序号 1~10 或 book_id
            if ($action === 'PICK' || $action === 'NOVEL_PICK') {
                $targetArg = '';
                if ($raw[0] === '{') {
                    $targetArg = isset($json['book']) ? trim($json['book']) : (isset($json['book_id']) ? trim($json['book_id']) : '');
                } else {
                    $tokens = preg_split('/\s+/', trim($raw));
                    $targetArg = isset($tokens[2]) ? trim($tokens[2]) : '';
                }
                $targetBook = function_exists('getUserNovelByIdOrIndex') ? getUserNovelByIdOrIndex($userId, $targetArg) : null;
                if ($targetBook) {
                    if (function_exists('setCurrentUserNovel')) {
                        setCurrentUserNovel($userId, $targetBook['book_id']);
                    }
                    $upstreamCmd = "PICK {$fqKey} {$targetBook['book_id']}";
                    forwardFanqieTcp($sock, $upstreamCmd);
                } else {
                    @fwrite($sock, "ERR 404 NOVEL NOT FOUND\n");
                }
                return;
            }

            // 3. 取页正文（NOVEL）：支持 NOVEL <KEY> <PAGE> [序号1-10 或 BOOK_ID]
            // 中转层统一路由至 CHAPTER 按章精准切片，确保每一章独立分页，下一页必定为下章标题+正文，杜绝跨章粘连！
            if ($action === 'NOVEL') {
                $reqPage = 1;
                $reqBookArg = '';
                if ($raw[0] === '{') {
                    $reqPage = isset($json['page']) ? max(1, (int)$json['page']) : 1;
                    $reqBookArg = isset($json['book']) ? trim($json['book']) : (isset($json['book_id']) ? trim($json['book_id']) : '');
                } else {
                    $tokens = preg_split('/\s+/', trim($raw));
                    $reqPage = isset($tokens[2]) ? max(1, (int)$tokens[2]) : 1;
                    $reqBookArg = isset($tokens[3]) ? trim($tokens[3]) : '';
                }

                $targetBook = function_exists('getUserNovelByIdOrIndex') ? getUserNovelByIdOrIndex($userId, $reqBookArg) : null;
                if (!$targetBook) {
                    $emptyNotice = json_encode(array(
                        'ok'     => 0,
                        'error'  => '该书已从书架移出，进度已清除',
                        'code'   => 404,
                        'reason' => 'BOOK_DELETED',
                        'title'  => '书已移出',
                        'page'   => 1,
                        'pages'  => 1,
                        'text'   => '该小说已从您的书架移出，本地进度已清除。请在网页控制台重新添加。'
                    ), JSON_UNESCAPED_UNICODE) . "\n";
                    @fwrite($sock, $emptyNotice);
                    return;
                }

                $realBookId = $targetBook['book_id'];
                $totalChaps = max(1, (int)$targetBook['total_chapters']);
                $totalPages = max((int)$targetBook['total_pages'], (int)round($totalChaps * 7.2));
                $pagesPerChap = max(0.1, (float)$totalPages / (float)$totalChaps);

                                $map = function_exists('novelPageToChapterOffset')
                    ? novelPageToChapterOffset($reqPage, $totalChaps, $totalPages)
                    : array('chapter' => max(1, min($totalChaps, (int)floor(($reqPage - 1) / $pagesPerChap) + 1)), 'chap_page' => 1);
                $calcChap = $map['chapter'];
                $calcChapPage = $map['chap_page'];

                $upstreamCmd = "CHAPTER {$fqKey} {$calcChap} {$calcChapPage} {$realBookId}";
                forwardFanqieTcp($sock, $upstreamCmd, function($resp) use ($userId, $realBookId, $reqPage, $targetBook, $calcChap, $calcChapPage, $totalPages) {
                    $j = @json_decode($resp, true);
                    if ($j && isset($j['text'])) {
                        $enriched = array(
                            'title'         => $targetBook['title'],
                            'chapter_title' => isset($j['title']) ? $j['title'] : ('第' . $calcChap . '章'),
                            'chapter'       => $calcChap,
                            'page'          => $reqPage,
                            'pages'         => $totalPages,
                            'chap_page'     => isset($j['page']) ? (int)$j['page'] : $calcChapPage,
                            'chap_pages'    => isset($j['pages']) ? (int)$j['pages'] : 1,
                            'text'          => (string)$j['text']
                        );
                        if (function_exists('updateUserNovelPage')) {
                            updateUserNovelPage($userId, $realBookId, $reqPage, $totalPages);
                        }
                        return json_encode($enriched, JSON_UNESCAPED_UNICODE) . "\n";
                    }
                    return $resp;
                });
                return;
            }

            // 4. 按章取页（CHAPTER）：支持 CHAPTER <KEY> <N> [PAGE] [序号1-10 或 BOOK_ID]
            if ($action === 'CHAPTER') {
                $reqChapter = 1;
                $reqPage = 1;
                $reqBookArg = '';
                if ($raw[0] === '{') {
                    $reqChapter = isset($json['n']) ? max(1, (int)$json['n']) : (isset($json['chapter']) ? max(1, (int)$json['chapter']) : 1);
                    $reqPage = isset($json['page']) ? max(1, (int)$json['page']) : 1;
                    $reqBookArg = isset($json['book']) ? trim($json['book']) : (isset($json['book_id']) ? trim($json['book_id']) : '');
                } else {
                    $tokens = preg_split('/\s+/', trim($raw));
                    $reqChapter = isset($tokens[2]) ? max(1, (int)$tokens[2]) : 1;
                    $tok3 = isset($tokens[3]) ? trim($tokens[3]) : '';
                    $tok4 = isset($tokens[4]) ? trim($tokens[4]) : '';

                    if ($tok4 !== '') {
                        $reqPage = max(1, (int)$tok3);
                        $reqBookArg = $tok4;
                    } elseif ($tok3 !== '') {
                        if (strlen($tok3) > 10) {
                            $reqPage = 1;
                            $reqBookArg = $tok3;
                        } else {
                            $reqPage = max(1, (int)$tok3);
                            $reqBookArg = '';
                        }
                    }
                }

                $targetBook = function_exists('getUserNovelByIdOrIndex') ? getUserNovelByIdOrIndex($userId, $reqBookArg) : null;
                if (!$targetBook) {
                    $emptyNotice = json_encode(array(
                        'error'  => '没有可用书目',
                        'code'   => 400,
                        'reason' => 'NO_BOOK'
                    ), JSON_UNESCAPED_UNICODE) . "\n";
                    @fwrite($sock, $emptyNotice);
                    return;
                }

                $realBookId = $targetBook['book_id'];
                $totalChaps = max(1, (int)$targetBook['total_chapters']);
                $totalPages = max((int)$targetBook['total_pages'], (int)round($totalChaps * 7.2));
                $pagesPerChap = max(0.1, (float)$totalPages / (float)$totalChaps);
                $reqChapter = max(1, min($totalChaps, $reqChapter));

                $upstreamCmd = "CHAPTER {$fqKey} {$reqChapter} {$reqPage} {$realBookId}";
                forwardFanqieTcp($sock, $upstreamCmd, function($resp) use ($userId, $realBookId, $reqChapter, $reqPage, $targetBook, $totalPages, $pagesPerChap) {
                    $j = @json_decode($resp, true);
                    if ($j && isset($j['text'])) {
                                                $pInChap = isset($j['page']) ? (int)$j['page'] : $reqPage;
                        $globalPage = function_exists('novelChapterOffsetToPage')
                            ? novelChapterOffsetToPage($reqChapter, $pInChap, $totalChaps, $totalPages)
                            : max(1, (int)round(($reqChapter - 1) * $pagesPerChap) + $pInChap);
                        $enriched = array(
                            'title'         => $targetBook['title'],
                            'chapter_title' => isset($j['title']) ? $j['title'] : ('第' . $reqChapter . '章'),
                            'chapter'       => $reqChapter,
                            'page'          => $globalPage,
                            'pages'         => $totalPages,
                            'chap_page'     => $pInChap,
                            'chap_pages'    => isset($j['pages']) ? (int)$j['pages'] : 1,
                            'text'          => (string)$j['text']
                        );
                        if (function_exists('updateUserNovelPage')) {
                            updateUserNovelPage($userId, $realBookId, $globalPage, $totalPages);
                        }
                        return json_encode($enriched, JSON_UNESCAPED_UNICODE) . "\n";
                    }
                    return $resp;
                });
                return;
            }

            // 5. 章节目录列表（CHAPTERS）：支持 CHAPTERS <KEY> [序号1-10 或 BOOK_ID] [OFFSET] [LIMIT]
            if ($action === 'CHAPTERS') {
                $reqBookArg = '';
                $reqOffset = 0;
                $reqLimit = 20;
                if ($raw[0] === '{') {
                    $reqBookArg = isset($json['book']) ? trim($json['book']) : (isset($json['book_id']) ? trim($json['book_id']) : '');
                    $reqOffset = isset($json['offset']) ? (int)$json['offset'] : 0;
                    $reqLimit = isset($json['limit']) ? (int)$json['limit'] : 20;
                } else {
                    $tokens = preg_split('/\s+/', trim($raw));
                    $reqBookArg = isset($tokens[2]) ? trim($tokens[2]) : '';
                    $reqOffset = isset($tokens[3]) ? (int)$tokens[3] : 0;
                    $reqLimit = isset($tokens[4]) ? (int)$tokens[4] : 20;
                }

                $targetBook = function_exists('getUserNovelByIdOrIndex') ? getUserNovelByIdOrIndex($userId, $reqBookArg) : null;
                $realBookId = $targetBook ? $targetBook['book_id'] : $reqBookArg;

                $upstreamCmd = "CHAPTERS {$fqKey}" . ($realBookId !== '' ? " {$realBookId}" : "") . " {$reqOffset} {$reqLimit}";
                forwardFanqieTcp($sock, $upstreamCmd);
                return;
            }

            // 6. 书籍信息（INFO）：支持 INFO <KEY> [序号1-10 或 BOOK_ID]
            if ($action === 'INFO') {
                $reqBookArg = '';
                if ($raw[0] === '{') {
                    $reqBookArg = isset($json['book']) ? trim($json['book']) : (isset($json['book_id']) ? trim($json['book_id']) : '');
                } else {
                    $tokens = preg_split('/\s+/', trim($raw));
                    $reqBookArg = isset($tokens[2]) ? trim($tokens[2]) : '';
                }

                $targetBook = function_exists('getUserNovelByIdOrIndex') ? getUserNovelByIdOrIndex($userId, $reqBookArg) : null;
                $realBookId = $targetBook ? $targetBook['book_id'] : $reqBookArg;

                $upstreamCmd = "INFO {$fqKey}" . ($realBookId !== '' ? " {$realBookId}" : "");
                forwardFanqieTcp($sock, $upstreamCmd);
                return;
            }

            // 7. 进度上报（PROGRESS）：支持 PROGRESS <KEY> <PAGE> [BOOK_ID] [AT]
            if ($action === 'PROGRESS' || $action === 'NOVEL_PROGRESS' || $action === 'PAGE') {
                $reqPage = 1;
                $reqBookArg = '';
                $reqAt = null;
                if ($raw[0] === '{') {
                    $reqPage = isset($json['page']) ? (int)$json['page'] : 1;
                    $reqBookArg = isset($json['book']) ? trim($json['book']) : (isset($json['book_id']) ? trim($json['book_id']) : '');
                    $reqAt = isset($json['at']) ? (int)$json['at'] : (isset($json['ts']) ? (int)$json['ts'] : null);
                } else {
                    $tokens = preg_split('/\s+/', trim($raw));
                    $reqPage = isset($tokens[2]) ? (int)$tokens[2] : 1;
                    $tok3 = isset($tokens[3]) ? trim($tokens[3]) : '';
                    $tok4 = isset($tokens[4]) ? trim($tokens[4]) : '';
                    if ($tok4 !== '') {
                        $reqBookArg = $tok3;
                        $reqAt = (int)$tok4;
                    } elseif ($tok3 !== '') {
                        if (strlen($tok3) === 10 && ctype_digit($tok3) && (int)$tok3 > 1700000000) {
                            $reqBookArg = '';
                            $reqAt = (int)$tok3;
                        } else {
                            $reqBookArg = $tok3;
                            $reqAt = null;
                        }
                    }
                }

                if (function_exists('updateUserNovelProgress')) {
                    $pRes = updateUserNovelProgress($userId, $reqBookArg, $reqPage, $reqAt);
                } else {
                    $pRes = array('ok' => false, 'code' => 500, 'error' => '未初始化');
                }

                if (empty($pRes['ok'])) {
                    @fwrite($sock, json_encode(array(
                        'ok'     => 0,
                        'error'  => isset($pRes['error']) ? $pRes['error'] : '该书已从书架移出，进度已清除',
                        'code'   => 404,
                        'reason' => 'BOOK_DELETED'
                    ), JSON_UNESCAPED_UNICODE) . "\n");
                    return;
                }

                // 期望回包：{"ok":1,"book_id":"...","title":"...","page":15,"pages":1054,"ts":1794397774,"saved":1}
                $okResp = json_encode(array(
                    'ok'      => 1,
                    'book_id' => $pRes['book_id'],
                    'title'   => $pRes['title'],
                    'page'    => (int)$pRes['page'],
                    'pages'   => (int)$pRes['pages'],
                    'ts'      => (int)$pRes['ts'],
                    'saved'   => (int)$pRes['saved']
                ), JSON_UNESCAPED_UNICODE) . "\n";
                @fwrite($sock, $okResp);
                return;
            }

            // 其它指令按标准转发
            if ($raw[0] === '{') {
                switch ($action) {
                    case 'CHAPTER':
                        $n = isset($json['n']) ? (int)$json['n'] : 1;
                        $p = isset($json['page']) ? (int)$json['page'] : 1;
                        $b = isset($json['book']) ? trim($json['book']) : '';
                        $upstreamCmd = "CHAPTER {$fqKey} {$n} {$p}" . ($b !== '' ? " {$b}" : "");
                        break;
                    case 'SEARCH':
                        $q = isset($json['q']) ? trim($json['q']) : (isset($json['keyword']) ? trim($json['keyword']) : '');
                        $upstreamCmd = "SEARCH {$fqKey} {$q}";
                        break;
                    case 'CHAPTERS':
                        $book = isset($json['book']) ? trim($json['book']) : '';
                        $off = isset($json['offset']) ? (int)$json['offset'] : 0;
                        $lim = isset($json['limit']) ? (int)$json['limit'] : 20;
                        $upstreamCmd = "CHAPTERS {$fqKey} {$book} {$off} {$lim}";
                        break;
                    case 'INFO':
                        $book = isset($json['book']) ? trim($json['book']) : '';
                        $upstreamCmd = "INFO {$fqKey} {$book}";
                        break;
                    case 'DL':
                        $book = isset($json['book']) ? trim($json['book']) : '';
                        $range = isset($json['chapters']) ? trim($json['chapters']) : '';
                        $upstreamCmd = "DL {$fqKey} {$book} {$range}";
                        break;
                    case 'TASK':
                        $tid = isset($json['task_id']) ? trim($json['task_id']) : (isset($json['id']) ? trim($json['id']) : '');
                        $upstreamCmd = "TASK {$fqKey} {$tid}";
                        break;
                    default:
                        $upstreamCmd = "{$action} {$fqKey}";
                        break;
                }
            } else {
                $tokens = preg_split('/\s+/', trim($raw));
                $cmdName = array_shift($tokens);
                array_shift($tokens); // 去掉客户端传入的 key
                $rest = implode(' ', $tokens);
                $upstreamCmd = $cmdName . " " . $fqKey . ($rest !== '' ? " " . $rest : "");
            }
        }

        forwardFanqieTcp($sock, $upstreamCmd);
        return;
    }

    // 一言代理指令（同端口明文 TCP，不缓存，每次实时获取随机名言名句）
    // 格式: SAYING <KEY> | HITOKOTO <KEY> | QUOTE <KEY> 或 {"action":"saying","key":"..."}
    if ($action === 'SAYING' || $action === 'HITOKOTO' || $action === 'QUOTE') {
        if ($raw[0] === '{') {
            $json = @json_decode($raw, true);
            $key = isset($json['key']) ? trim($json['key']) : '';
        } else {
            $tokens = preg_split('/\s+/', trim($raw));
            $key = isset($tokens[1]) ? trim($tokens[1]) : '';
        }

        if ($key === '') {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }
        $owner = getDeskKeyOwner($key);
        if (!$owner) {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }

        try {
            $pdo = getDB();
            if ($pdo) {
                $pdo->prepare("UPDATE `pn_api_keys` SET `last_used_at` = NOW() WHERE `id` = ?")->execute(array((int)$owner['id']));
            }
        } catch (Throwable $e) {}

        $sayingJson = fetchSayingProxy();
        if ($sayingJson !== null) {
            @fwrite($sock, $sayingJson . "\n");
        } else {
            @fwrite($sock, "ERR 503 SERVICE UNAVAILABLE\n");
        }
        return;
    }


    // 留言指令：MSG <KEY> <留言内容> 或 {"action":"msg","key":"...","msg":"..."}
    if ($action === 'MSG' || $action === 'MESSAGE') {
        $msgContent = '';
        if ($raw[0] === '{') {
            $json = @json_decode($raw, true);
            $key = isset($json['key']) ? trim($json['key']) : '';
            $msgContent = isset($json['msg']) ? trim($json['msg']) : (isset($json['message']) ? trim($json['message']) : '');
        } else {
            $parts3 = preg_split('/\s+/', trim($raw), 3);
            $key = isset($parts3[1]) ? trim($parts3[1]) : '';
            $msgContent = isset($parts3[2]) ? trim($parts3[2]) : '';
        }

        if ($key === '') {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }
        $owner = getDeskKeyOwner($key);
        if (!$owner) {
            @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
            return;
        }
        if ($msgContent === '') {
            @fwrite($sock, "ERR 400 EMPTY MESSAGE\n");
            return;
        }

        $userId = (int)$owner['user_id'];
        $pdo = null;
        try { $pdo = getDB(); } catch (Throwable $e) {}
        if (!$pdo) {
            @fwrite($sock, "ERR 500 DB ERROR\n");
            return;
        }

        $msgContent = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $msgContent), 0, 200, 'UTF-8');
        $today = date('Y-m-d');
        $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_desk_memories` WHERE `user_id` = ? AND `role` = 'user' AND `content` LIKE '%用户留言]%' AND DATE(`created_at`) = ?");
        $st->execute(array($userId, $today));
        $count = (int)$st->fetchColumn();
        if ($count >= 10) {
            @fwrite($sock, "ERR 429 DAILY MESSAGE LIMIT REACHED\n");
            return;
        }

        $nowStr = date('Y-m-d H:i');
        $stored = "[{$nowStr} 用户留言]: {$msgContent}";
        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'user', ?, ?, NOW())");
        $st->execute(array($userId, $stored, mb_strlen($stored, 'UTF-8')));

        $remain = max(0, 10 - ($count + 1));
        @fwrite($sock, "OK MESSAGE RECORDED (REMAIN: {$remain})\n");
        return;
    }


    $isMock = in_array($action, array('MOCK_STATUS', 'MOCK_ROAST'), true);

    if ($isMock) {
        if ($action === 'MOCK_STATUS') {
            $data = json_encode(array(
                'ts'    => 1794128220,
                'd'     => '2026-11-08',
                'open'  => 1,
                'diary' => 0,
                'todo'  => 3
            ), JSON_UNESCAPED_SLASHES) . "\n";
            @fwrite($sock, $data);
            return;
        }

        if ($action === 'MOCK_ROAST') {
            $mockText = '今天日记又没写，还好意思看屏幕？';
            sendTcpStream($sock, $mockText);
            return;
        }
    }

    // 鉴权
    if ($key === '') {
        @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
        return;
    }

    $owner = getDeskKeyOwner($key);
    if (!$owner) {
        @fwrite($sock, "ERR 401 UNAUTHORIZED\n");
        return;
    }

    $userId = (int)$owner['user_id'];
    $pdo = null;
    try { $pdo = getDB(); } catch (Throwable $e) {}

    // 记录 Key 最后使用时间
    if ($pdo) {
        try {
            $pdo->prepare("UPDATE `pn_api_keys` SET `last_used_at` = NOW() WHERE `id` = ?")->execute(array((int)$owner['id']));
        } catch (Throwable $e) {}
    }

    // 业务路由
    if ($action === 'STATUS') {
        $now = time();
        $today = date('Y-m-d', $now);

        // 1. open
        $open = 0;
        if (!empty($owner['last_login_at'])) {
            if (date('Y-m-d', strtotime($owner['last_login_at'])) === $today) {
                $open = 1;
            }
        }

        // 2. diary (方案 A: 今日写任意便签均算完成)
        $diary = 0;
        if ($pdo) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_notes` WHERE `user_id` = ? AND (DATE(`updated_at`) = ? OR DATE(`created_at`) = ?)");
                $st->execute(array($userId, $today, $today));
                if ((int)$st->fetchColumn() > 0) {
                    $diary = 1;
                }
            } catch (Throwable $e) {}
        }

        $statusData = array(
            'ts'    => $now,
            'd'     => $today,
            'open'  => $open,
            'diary' => $diary,
            'todo'  => 0
        );

        // 建议特性：STATUS <KEY> [PAGE] [BOOK_ID] 顺带上报进度，为单片机省一次 TCP 连接
        $statPage = null;
        $statBook = '';
        if ($raw[0] === '{') {
            if (isset($json['page']) && is_numeric($json['page'])) {
                $statPage = (int)$json['page'];
                $statBook = isset($json['book']) ? trim($json['book']) : (isset($json['book_id']) ? trim($json['book_id']) : '');
            }
        } else {
            $tokens = preg_split('/\s+/', trim($raw));
            if (isset($tokens[2]) && is_numeric($tokens[2])) {
                $statPage = (int)$tokens[2];
                $statBook = isset($tokens[3]) ? trim($tokens[3]) : '';
            }
        }

        if ($statPage !== null && function_exists('updateUserNovelProgress')) {
            $pgRes = updateUserNovelProgress($userId, $statBook, $statPage, $now);
            if (!empty($pgRes['ok'])) {
                $statusData['pg_saved'] = (int)$pgRes['saved'];
                $statusData['pg_pages'] = (int)$pgRes['pages'];
            } else {
                $statusData['pg_saved'] = 0;
                $statusData['pg_pages'] = 0;
            }
        }

        $resp = json_encode($statusData, JSON_UNESCAPED_SLASHES) . "\n";
        @fwrite($sock, $resp);
        return;
    }

    if ($action === 'ROAST') {
        $now = time();
        $today = date('Y-m-d', $now);

        // 1. 限流：每分钟最多允许 2 次
        $lockDir = sys_get_temp_dir() . '/pn_roast_limits';
        if (!is_dir($lockDir)) @mkdir($lockDir, 0777, true);
        $rateFile = $lockDir . '/rate_' . md5($key);
        $timestamps = array();
        if (is_file($rateFile)) {
            $saved = @file_get_contents($rateFile);
            $arr = @json_decode($saved, true);
            if (is_array($arr)) {
                $timestamps = $arr;
            } elseif (is_numeric(trim($saved))) {
                $timestamps = array((int)trim($saved));
            }
            $timestamps = array_values(array_filter($timestamps, function($ts) use ($now) {
                return ($now - $ts) < 60;
            }));
            if (count($timestamps) >= 2) {
                @fwrite($sock, "ERR 429 TOO MANY REQUESTS (MAX 2 PER MINUTE)\n");
                return;
            }
        }
        $timestamps[] = $now;
        @file_put_contents($rateFile, json_encode($timestamps), LOCK_EX);

        // 2. 状态描述
        $openText = '今天尚未登录便签';
        if (!empty($owner['last_login_at']) && date('Y-m-d', strtotime($owner['last_login_at'])) === $today) {
            $openText = '今天已经登录过便签系统';
        }

        $diaryText = '今天还没写便签日记';
        if ($pdo) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `pn_notes` WHERE `user_id` = ? AND (DATE(`updated_at`) = ? OR DATE(`created_at`) = ?)");
                $st->execute(array($userId, $today, $today));
                if ((int)$st->fetchColumn() > 0) {
                    $diaryText = '今天已经动笔写了便签日记';
                }
            } catch (Throwable $e) {}
        }

        $hour = (int)date('G', $now);
        if ($hour >= 0 && $hour < 6) $period = '深夜黎明';
        elseif ($hour >= 6 && $hour < 11) $period = '早晨上午';
        elseif ($hour >= 11 && $hour < 14) $period = '中午时分';
        elseif ($hour >= 14 && $hour < 18) $period = '下午工作时间';
        elseif ($hour >= 18 && $hour < 23) $period = '晚上休闲时间';
        else $period = '深夜时间';

        $persona = !empty($owner['persona']) ? trim($owner['persona']) : '嘴硬心软、爱吐槽的桌面管家';
        $persona = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $persona), 0, 200, 'UTF-8');

        // 加载记忆
        $memories = tcpLoadDeskMemory($userId, $pdo);

        $messages = array();
        $messages[] = array(
            'role' => 'system',
            'content' => "你是一个硬件桌面伴侣屏幕的吐槽管家。\n" .
                         "请根据用户设定的管家风格偏好和当前便签状态，输出一句精准、短小精悍的吐槽。\n" .
                         "【硬性铁律（必须绝对遵守）】：\n" .
                         "1. 输出字数绝对不能超过 30 个汉字。\n" .
                         "2. 严禁包含任何表情符号 (Emoji)、换行符、引号（\" 或 '）、Markdown 格式。\n" .
                         "3. 字符必须在常用中文 GB2312 编码范围内（硬件屏幕不支持生僻字）。\n" .
                         "4. 只输出这一句话本身，不要加任何前后缀、解释或客套。\n" .
                         "5. 用户可能会在离线时留下便利贴留言（带有时间点与 [用户留言] 标记）。如果历史上下文中有用户的近期留言，请在管家人设下自然呼应该留言（可调侃、吐槽或关心），但仍必须严格遵循上述 30 字与格式铁律。"
        );

        foreach ($memories as $m) {
            $role = $m['role'];
            $content = $m['content'];
            $count = count($messages);
            if ($count > 0 && $messages[$count - 1]['role'] === $role && $role === 'user') {
                $messages[$count - 1]['content'] .= "\n" . $content;
            } else {
                $messages[] = array('role' => $role, 'content' => $content);
            }
        }

        $currentUserPrompt = "[用户管家风格偏好]: {$persona}\n" .
                              "[当前时间]: {$today} " . date('H:i') . " ({$period})\n" .
                              "[便签状态]: {$openText}；{$diaryText}。\n" .
                              "请按照管家风格，对当前状态给出一句 30 字以内的吐槽。";
        $messages[] = array('role' => 'user', 'content' => $currentUserPrompt);

        // 调用大模型
        $aiResult = tcpCallDeskAi($userId, $messages);
        if ($aiResult === null || trim($aiResult) === '') {
            @fwrite($sock, "ERR 503 SERVICE UNAVAILABLE\n");
            return;
        }

        // 字符清洗与 30 字截断
        $cleanText = tcpSanitizeRoast($aiResult);

        // 存记忆
        tcpSaveDeskMemory($userId, $currentUserPrompt, $cleanText, $pdo);

        // 逐字流式分块输出
        sendTcpStream($sock, $cleanText);
        return;
    }

    @fwrite($sock, "ERR 400 BAD REQUEST\n");
}

function sendTcpStream($sock, $text) {
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($text, $i, 1, 'UTF-8');
        @fwrite($sock, $char);
        usleep(100000); // 100ms = 0.1s
    }
    @fwrite($sock, "\n");
}

function tcpSanitizeRoast($text) {
    $text = str_replace(array("\r", "\n", "\t", '"', "'", "`", "\\"), ' ', $text);
    $text = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $text);
    $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
    if (function_exists('iconv')) {
        $gb = @iconv('UTF-8', 'GB2312//IGNORE', $text);
        if ($gb !== false && $gb !== '') {
            $text = @iconv('GB2312', 'UTF-8', $gb);
        }
    }
    $text = trim(preg_replace('/\s+/', '', $text));
    $len = mb_strlen($text, 'UTF-8');
    if ($len > 30) {
        $sub = mb_substr($text, 0, 30, 'UTF-8');
        if (preg_match('/^(.+[，。！？~；])/u', $sub, $m)) {
            $text = $m[1];
        } else {
            $text = $sub;
        }
    }
    return $text;
}

function tcpLoadDeskMemory($userId, $pdo) {
    if (!$pdo) return array();
    try {
        $st = $pdo->prepare("SELECT id, role, content, content_len FROM `pn_desk_memories` WHERE `user_id` = ? ORDER BY `id` ASC");
        $st->execute(array($userId));
        $all = $st->fetchAll();
        if (empty($all)) return array();

        $totalLen = 0;
        foreach ($all as $item) {
            $totalLen += (int)$item['content_len'];
        }

        if ($totalLen >= 200000 && count($all) > 30) {
            $recent = array_slice($all, -30);
            $cutoffId = (int)$recent[0]['id'];
            $pdo->prepare("DELETE FROM `pn_desk_memories` WHERE `user_id` = ? AND `id` < ?")->execute(array($userId, $cutoffId));
            $summaryContent = "[往期互动记忆摘要: 用户长期使用桌面状态屏，曾多次在不同时段被吐槽，保持了连续的互动习惯]";
            $summaryLen = mb_strlen($summaryContent, 'UTF-8');
            $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'assistant', ?, ?, NOW())")
                ->execute(array($userId, $summaryContent, $summaryLen));

            $st = $pdo->prepare("SELECT id, role, content, content_len FROM `pn_desk_memories` WHERE `user_id` = ? ORDER BY `id` ASC");
            $st->execute(array($userId));
            return $st->fetchAll();
        }
        return $all;
    } catch (Throwable $e) {
        return array();
    }
}

function tcpSaveDeskMemory($userId, $userContent, $assistantContent, $pdo) {
    if (!$pdo) return;
    try {
        $userClean = mb_substr($userContent, 0, 300, 'UTF-8');
        $uLen = mb_strlen($userClean, 'UTF-8');
        $aLen = mb_strlen($assistantContent, 'UTF-8');
        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'user', ?, ?, NOW())");
        $st->execute(array($userId, $userClean, $uLen));
        $st = $pdo->prepare("INSERT INTO `pn_desk_memories` (`user_id`, `role`, `content`, `content_len`, `created_at`) VALUES (?, 'assistant', ?, ?, NOW())");
        $st->execute(array($userId, $assistantContent, $aLen));
    } catch (Throwable $e) {}
}

function tcpCallDeskAi($userId, $messages) {
    $userPrefs = getUserAiPrefs($userId);
    $mode = (isset($userPrefs['mode']) && $userPrefs['mode'] === 'own') ? 'own' : 'platform';
    $url = '';
    $key = '';
    $model = '';

    if ($mode === 'own' && !empty($userPrefs['own_api_key'])) {
        $rawTarget = rtrim(trim((string)$userPrefs['own_base_url']), '/');
        $key = trim((string)$userPrefs['own_api_key']);
        $model = trim((string)$userPrefs['own_model']);
        $target = preg_replace('#^https?://#i', '', $rawTarget);
        if (substr($target, -17) !== '/chat/completions') {
            $target .= '/chat/completions';
        }
        $proxyPrefix = rtrim(trim(getSetting('ai_own_proxy', '')), '/');
        if (!empty($proxyPrefix)) {
            $url = $proxyPrefix . '/' . $target;
        } else {
            $url = (strpos($rawTarget, 'http') === 0 ? $rawTarget : 'https://' . $target);
        }
    } else {
        $base = rtrim(trim(getSetting('ai_base_url', '')), '/');
        $key  = trim(getSetting('ai_api_key', ''));
        $model = trim(getSetting('ai_model', ''));
        if (empty($base) || empty($key) || empty($model)) {
            return null;
        }
        $url = (substr($base, -17) === '/chat/completions') ? $base : $base . '/chat/completions';
    }

    if (empty($url) || empty($key) || empty($model)) return null;

    $payload = json_encode(array(
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 80,
        'temperature' => 0.7,
        'stream' => false
    ), JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $key));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code < 200 || $code >= 300 || empty($resp)) return null;
    $json = json_decode($resp, true);
    if (!isset($json['choices'][0]['message']['content'])) return null;
    $reply = $json['choices'][0]['message']['content'];
    $reply = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $reply);
    return trim($reply);
}


/**
 * 代理获取 uapis.cn 天气数据（带 10 分钟本地缓存与故障降级）
 */

function fetchWeatherProxy($cityOrCode = '') {
    $cityOrCode = trim((string)$cityOrCode);
    $cacheKey = 'pn_weather_' . md5($cityOrCode);
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';

    // 30 分钟缓存（1800 秒），过北京时间凌晨 01:00 强制失效
    if (function_exists('isDeskCacheFresh') ? isDeskCacheFresh($cacheFile, 1800) : (is_file($cacheFile) && (time() - filemtime($cacheFile) < 1800))) {
        $cached = @file_get_contents($cacheFile);
        if ($cached && strlen($cached) > 20) {
            return trim($cached);
        }
    }

    $url = 'https://uapis.cn/api/v1/misc/weather';
    if ($cityOrCode !== '') {
        if (preg_match('/^\d{6}$/', $cityOrCode)) {
            $url .= '?adcode=' . urlencode($cityOrCode);
        } else {
            $url .= '?city=' . urlencode($cityOrCode);
        }
    }

    $uapisKey = function_exists('getUapisApiKey') ? getUapisApiKey() : (function_exists('getSetting') ? getSetting('uapis_api_key', 'ATut6bfa8c5f880340b9be8164d872cd97b0') : 'ATut6bfa8c5f880340b9be8164d872cd97b0');
    $headers = array('User-Agent: PixelNotes-DeskScreen/1.0');
    if (!empty($uapisKey)) {
        $headers[] = 'Authorization: Bearer ' . $uapisKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 若 Key 失效或未生效（401），尝试无 Key 访客兜底
    if ($code === 401 && !empty($uapisKey)) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: PixelNotes-DeskScreen/1.0'));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    if ($code === 200 && !empty($resp)) {
        $data = json_decode($resp, true);
        if ($data && (isset($data['weather']) || isset($data['temperature']))) {
            @file_put_contents($cacheFile, $resp, LOCK_EX);
            return trim($resp);
        }
    }

    // 上游失败时若有旧缓存则降级返回旧缓存
    if (is_file($cacheFile)) {
        $stale = @file_get_contents($cacheFile);
        if ($stale) return trim($stale);
    }

    return null;
}



/**
 * 代理获取 uapis.cn 程序员历史上的今天（带 1 小时本地缓存与故障降级）
 */
function fetchProgrammerTodayProxy() {
    $cacheKey = 'pn_programmer_today';
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';

    // 本地缓存 12 小时（43200 秒），过北京时间凌晨 01:00 强制失效（预留 00:00~01:00 供服务商更新）
    if (function_exists('isDeskCacheFresh') ? isDeskCacheFresh($cacheFile, 43200) : (is_file($cacheFile) && (time() - filemtime($cacheFile) < 43200))) {
        $cached = @file_get_contents($cacheFile);
        if ($cached && strlen($cached) > 20) {
            return trim($cached);
        }
    }

    $url = 'https://uapis.cn/api/v1/history/programmer/today';
    $uapisKey = function_exists('getUapisApiKey') ? getUapisApiKey() : (function_exists('getSetting') ? getSetting('uapis_api_key', 'ATut6bfa8c5f880340b9be8164d872cd97b0') : 'ATut6bfa8c5f880340b9be8164d872cd97b0');
    $headers = array('User-Agent: PixelNotes-DeskScreen/1.0');
    if (!empty($uapisKey)) {
        $headers[] = 'Authorization: Bearer ' . $uapisKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 若 Key 失效或未生效（401），尝试无 Key 访客兜底
    if ($code === 401 && !empty($uapisKey)) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: PixelNotes-DeskScreen/1.0'));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    if ($code === 200 && !empty($resp)) {
        $data = json_decode($resp, true);
        if ($data && (isset($data['events']) || isset($data['date']))) {
            @file_put_contents($cacheFile, $resp, LOCK_EX);
            return trim($resp);
        }
    }

    // 上游异常时若有旧缓存降级返回
    if (is_file($cacheFile)) {
        $stale = @file_get_contents($cacheFile);
        if ($stale) return trim($stale);
    }

    return null;
}

/**
 * 代理获取 uapis.cn 一言（不缓存，每次实时获取随机名言名句）
 */
function fetchSayingProxy() {
    $url = 'https://uapis.cn/api/v1/saying';
    $uapisKey = function_exists('getUapisApiKey') ? getUapisApiKey() : (function_exists('getSetting') ? getSetting('uapis_api_key', 'ATut6bfa8c5f880340b9be8164d872cd97b0') : 'ATut6bfa8c5f880340b9be8164d872cd97b0');
    $headers = array('User-Agent: PixelNotes-DeskScreen/1.0');
    if (!empty($uapisKey)) {
        $headers[] = 'Authorization: Bearer ' . $uapisKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 若 Key 失效或未生效（401），尝试无 Key 访客兜底
    if ($code === 401 && !empty($uapisKey)) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: PixelNotes-DeskScreen/1.0'));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    if ($code === 200 && !empty($resp)) {
        $data = json_decode($resp, true);
        if ($data && (isset($data['text']) || isset($data['hitokoto']))) {
            return trim($resp);
        }
    }

    return null;
}


/**
 * 转发 TCP 指令至上游番茄小说服务（单端口 TCP 明文单行模式）
 */
function forwardFanqieTcp($sock, $upstreamCmd, $onResponse = null) {
    $fqHost = function_exists('getFanqieHost') ? getFanqieHost() : 'http://111.170.163.248:53223';
    $parsed = parse_url($fqHost);
    $host = isset($parsed['host']) ? $parsed['host'] : '111.170.163.248';
    $port = isset($parsed['port']) ? (int)$parsed['port'] : 53223;

    $fp = @fsockopen($host, $port, $errno, $errstr, 4);
    if (!$fp) {
        @fwrite($sock, "ERR 503 NOVEL SERVICE UNAVAILABLE\n");
        return;
    }

    stream_set_timeout($fp, 12);
    @fwrite($fp, trim($upstreamCmd) . "\n");

    $resp = '';
    while (!feof($fp)) {
        $chunk = @fgets($fp, 8192);
        if ($chunk === false) break;
        $resp .= $chunk;
        if (strpos($resp, "\n") !== false) {
            break;
        }
    }
    @fclose($fp);

    if ($resp !== '') {
        // 截断保护：确保单次 text 不超过 850 字节（UTF-8），预留 JSON 键名元数据空间，绝不超出单片机 1024 字节静态缓冲
        $jData = @json_decode($resp, true);
        if ($jData && is_array($jData) && isset($jData['text'])) {
            $rawText = (string)$jData['text'];
            if (strlen($rawText) > 850) {
                $jData['text'] = mb_strcut($rawText, 0, 850, 'UTF-8');
            }
            if (!isset($jData['chapter'])) {
                if (preg_match('/第([0-9]+)章/u', $rawText, $tm)) {
                    $jData['chapter'] = (int)$tm[1];
                } elseif (isset($jData['page'])) {
                    $p = (int)$jData['page'];
                    $jData['chapter'] = max(1, (int)round(($p - 1) / 7.2) + 1);
                }
            }
            $resp = json_encode($jData, JSON_UNESCAPED_UNICODE) . "\n";
        }

        if ($onResponse && is_callable($onResponse)) {
            try {
                $mod = call_user_func($onResponse, $resp);
                if (is_string($mod) && $mod !== '') {
                    $resp = $mod;
                }
            } catch (Throwable $e) {}
        }
        if (substr($resp, -1) !== "\n") {
            $resp .= "\n";
        }
        @fwrite($sock, $resp);
    } else {
        @fwrite($sock, "ERR 504 UPSTREAM TIMEOUT\n");
    }
}
