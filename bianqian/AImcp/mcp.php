<?php

if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}

require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/StoreFactory.php';
require_once __DIR__ . '/src/MySqlStore.php';
require_once __DIR__ . '/src/JsonFileStore.php';
require_once __DIR__ . '/src/Search.php';
require_once __DIR__ . '/src/Embeddings.php';
require_once __DIR__ . '/src/App.php';

function amcp_respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function amcp_error(?string $id, int $code, string $message): void
{
    $err = array(
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => array('code' => $code, 'message' => $message),
    );
    amcp_respond(200, $err);
}

set_exception_handler(static function (\Throwable $e) {
    amcp_error(null, -32603, '内部错误: ' . $e->getMessage());
});

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'mcp.php 仅接受 POST'));
    exit;
}

if (!function_exists('mb_strlen')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '缺少 PHP mbstring 扩展';
    exit;
}

$app = new App();
$configuredToken = $app->apiToken();

$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = (string) $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
$authKey = isset($_SERVER['HTTP_X_API_KEY']) ? (string) $_SERVER['HTTP_X_API_KEY'] : '';
$provided = '';
foreach (array('ApiKey', 'api_key', 'token') as $qk) {
    if (isset($_GET[$qk]) && (string) $_GET[$qk] !== '') {
        $provided = (string) $_GET[$qk];
        break;
    }
}
if ($provided === '' && $authKey !== '') {
    $provided = $authKey;
}
if ($provided === '' && trim($authHeader) !== '') {
    if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        $provided = trim($m[1]);
    }
}

if ($configuredToken !== '' && !hash_equals($configuredToken, $provided)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => array('code' => -32001, 'message' => '未授权'),
    ));
    exit;
}

$raw = @file_get_contents('php://input');
$msg = json_decode($raw, true);
if (!is_array($msg)) {
    amcp_error(null, -32700, '解析错误：无效的 JSON');
}

$method = isset($msg['method']) ? (string) $msg['method'] : '';
$id = isset($msg['id']) ? $msg['id'] : null;

if (strpos($method, 'notifications/') === 0) {
    amcp_respond(202, array('jsonrpc' => '2.0', 'id' => null));
}

if ($method === 'initialize') {
    amcp_respond(200, array(
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => array(
            'protocolVersion' => '2024-11-05',
            'capabilities' => array('tools' => new stdClass()),
            'serverInfo' => array('name' => 'ai-memory-mcp', 'version' => '1.0.0'),
        ),
    ));
}

if ($method === 'ping') {
    amcp_respond(200, array('jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()));
}

if ($method === 'tools/list') {
    amcp_respond(200, array(
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => array('tools' => array(
            array(
                'name' => 'memory_instructions',
                'description' => '读取管理员设定的使用规则与注意事项。开始使用本记忆库前建议先调用它了解规则（如调用偏好、何时开启精排、标签约定等）；无规则时返回空字符串',
                'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
            ),
            array(
                'name' => 'memory_add',
                'description' => '新增一条记忆条目；可带 tags、metadata；可自行提供 vector（跳过服务端嵌入），否则在嵌入服务已配置时由服务端生成向量',
                'inputSchema' => array('type' => 'object', 'properties' => array(
                    'content' => array('type' => 'string', 'description' => '记忆内容'),
                    'tags' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => '标签数组（可选）'),
                    'metadata' => array('type' => 'object', 'description' => '任意附加元数据（可选）'),
                    'vector' => array('type' => 'array', 'items' => array('type' => 'number'), 'description' => '客户端预计算向量（可选）'),
                    'model' => array('type' => 'string', 'description' => '向量对应模型名（配合 vector 使用，可选）'),
                ), 'required' => array('content')),
            ),
            array(
                'name' => 'memory_update',
                'description' => '修改一条已有记忆：可按需改 content / tags / metadata；保留原 id 与创建时间。传入的新 content 会自动重新分词并重算向量；未提供的字段保持原值不变',
                'inputSchema' => array('type' => 'object', 'properties' => array(
                    'id' => array('type' => 'string', 'description' => '要修改的记忆 id'),
                    'content' => array('type' => 'string', 'description' => '新的记忆内容（可选，不传则保留原文）'),
                    'tags' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => '新的标签数组（可选，不传则保留原标签）'),
                    'metadata' => array('type' => 'object', 'description' => '新的元数据（可选，不传则保留原值）'),
                ), 'required' => array('id')),
            ),
            array(
                'name' => 'memory_search',
                'description' => '检索记忆库。两步(可选三步)检索：(1)标签精确命中优先加权 (2)向量语义精排。向量已够用时直接可用；若想要更高精度，可设 rerank=true 触发 LLM 二次精排（模型自己判断哪些候选真正相关，默认不启用以免额外调用）。可按需传 min_score 控严格度——不传默认 0.5；想要更宽松的模糊召回传 0.2~0.4，只留高置信命中传 0.6~0.7。embedding=语义匹配(换种说法提问)；text=字面模糊；auto=推荐默认',
                'inputSchema' => array('type' => 'object', 'properties' => array(
                    'query' => array('type' => 'string', 'description' => '检索词/意图描述'),
                    'mode' => array('type' => 'string', 'enum' => array('auto', 'embedding', 'text'), 'description' => 'auto=自动(默认)；embedding=语义；text=字面'),
                    'top_k' => array('type' => 'integer', 'description' => '返回条数，默认5'),
                    'tags' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => '按标签过滤（可选）'),
                    'min_score' => array('type' => 'number', 'description' => '相似度阈值(默认0.5)。传低值(0.2~0.4)模糊召回更多；传高值(0.6~0.7)只留强相关'),
                    'rerank' => array('type' => 'boolean', 'description' => '设为true则对候选做LLM二次精排（更准但会更慢/额外调用一次推理模型）；默认false'),
                ), 'required' => array('query')),
            ),
            array(
                'name' => 'memory_list',
                'description' => '按时间倒序分页列出记忆条目',
                'inputSchema' => array('type' => 'object', 'properties' => array(
                    'limit' => array('type' => 'integer', 'description' => '条数，默认20'),
                    'offset' => array('type' => 'integer', 'description' => '偏移，默认0'),
                    'tag' => array('type' => 'string', 'description' => '按标签过滤（可选）'),
                )),
            ),
            array(
                'name' => 'memory_delete',
                'description' => '删除指定记忆条目',
                'inputSchema' => array('type' => 'object', 'properties' => array(
                    'id' => array('type' => 'string', 'description' => '条目 id'),
                ), 'required' => array('id')),
            ),
        )),
    ));
}

if ($method === 'tools/call') {
    $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : array();
    $name = isset($params['name']) ? (string) $params['name'] : '';
    $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();

    try {
        $result = amcp_tool($app, $name, $arguments);
    } catch (\Throwable $e) {
        amcp_respond(200, array(
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => array(
                'isError' => true,
                'content' => array(array('type' => 'text', 'text' => json_encode(array(
                    'ok' => false,
                    'error' => $e->getMessage(),
                ), JSON_UNESCAPED_UNICODE))),
            ),
        ));
    }

    amcp_respond(200, array(
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => array(
            'content' => array(array('type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
        ),
    ));
}

amcp_error($id, -32601, '方法不存在：' . $method);

function amcp_tool(App $app, string $name, array $arguments): array
{
    switch ($name) {
        case 'memory_instructions':
            return amcp_instructions($app);
        case 'memory_add':
            return amcp_add($app, $arguments);
        case 'memory_update':
            return amcp_update($app, $arguments);
        case 'memory_search':
            return amcp_search($app, $arguments);
        case 'memory_list':
            return amcp_list($app, $arguments);
        case 'memory_delete':
            return amcp_delete($app, $arguments);
        default:
            throw new RuntimeException('未知工具：' . $name);
    }
}

function amcp_instructions(App $app): array
{
    // 静态规则文本（后台 instructions 编辑框内容）
    $static = $app->instructions()->get();

    // 动态核心档案：拉取所有 pinned 记忆（tag 含 pinned 或 metadata.pinned 为真），按创建时间倒序
    $profile = array();
    foreach ($app->store()->all() as $entry) {
        $isPinned = in_array('pinned', $entry['tags'], true)
            || (isset($entry['metadata']['pinned']) && $entry['metadata']['pinned']);
        if ($isPinned) {
            $profile[] = $entry;
        }
    }
    usort($profile, static function (array $a, array $b): int {
        return $b['created_at'] <=> $a['created_at'];
    });

    $profileLines = array();
    foreach ($profile as $p) {
        $line = $p['content'];
        if ($p['tags']) {
            $line .= ' [标签: ' . implode(',', $p['tags']) . ']';
        }
        $profileLines[] = '- ' . $line;
    }

    $profileText = $profileLines ? implode("\n", $profileLines) : '(暂无 pinned 记忆)';

    $full = $static;
    if ($full !== '') {
        $full .= "\n\n";
    }
    $full .= "【核心档案】\n" . $profileText;

    return array('ok' => true, 'instructions' => $full, 'pinned_count' => count($profile));
}

function amcp_add(App $app, array $arguments): array
{
    if (!isset($arguments['content']) || trim((string) $arguments['content']) === '') {
        throw new RuntimeException('content 不能为空');
    }
    $content = (string) $arguments['content'];
    $tags = isset($arguments['tags']) && is_array($arguments['tags']) ? array_values($arguments['tags']) : array();
    $metadata = isset($arguments['metadata']) && is_array($arguments['metadata']) ? $arguments['metadata'] : array();
    $tokens = Search::tokenize($content);
    $now = (int) (microtime(true) * 1000);

    $entry = array(
        'id' => StoreFactory::makeId(),
        'content' => $content,
        'tags' => array_map('strval', $tags),
        'metadata' => $metadata,
        'vector' => null,
        'dim' => null,
        'model' => null,
        'tokens' => $tokens,
        'created_at' => $now,
        'updated_at' => $now,
    );

    $hasVector = false;
    if (isset($arguments['vector']) && is_array($arguments['vector']) && count($arguments['vector']) > 0) {
        $entry['vector'] = array_values(array_map('floatval', $arguments['vector']));
        $entry['dim'] = count($entry['vector']);
        $entry['model'] = isset($arguments['model']) ? (string) $arguments['model'] : (isset($app->config()['embeddings']['model']) ? (string) $app->config()['embeddings']['model'] : null);
        $hasVector = true;
    } elseif ($app->embeddings()->isConfigured()) {
        $embedded = $app->embeddings()->embed($content, 'passage');
        $entry['vector'] = $embedded['vector'];
        $entry['dim'] = count($embedded['vector']);
        $entry['model'] = $embedded['model'];
        $hasVector = true;
    }

    // 疑似重复检测：
    //   1) token 覆盖率极低(无关) → 跳过；极高(逐字重复) → 直接判定；
    //   2) 中等(同领域/可能重复) → 调 LLM 语义判断是否同一件事，避免共享词/标签导致的误报
    $duplicateHint = null;
    $dupCandidates = array();   // 覆盖率中等、待 LLM 判断的候选
    $dupLocked = null;          // 覆盖率极高，直接判定重复
    $lastCoverage = 0.0;        // 记录最高覆盖率（调试）
    $querySet = array_flip($tokens);
    foreach ($app->store()->all() as $existing) {
        if ((string)$existing['id'] === (string)$entry['id']) {
            continue;
        }
        // token 覆盖率 = 新内容词表在既有条目里命中的比例（衡量字面重叠）
        $etSet = array_flip($existing['tokens']);
        $hit = 0;
        foreach ($querySet as $tk => $_) {
            if (isset($etSet[$tk])) {
                $hit++;
            }
        }
        $tTotal = count($tokens);
        $coverage = $tTotal > 0 ? $hit / $tTotal : 0.0;
        if ($coverage > $lastCoverage) {
            $lastCoverage = $coverage;
        }

        if ($coverage >= 0.9) {
            $dupLocked = array($existing['id'], $coverage);
            break;
        } elseif ($coverage >= 0.4) {
            $dupCandidates[] = array($existing, $coverage);
        }
    }

    if ($dupLocked !== null) {
        $duplicateHint = '疑似与已有条目重复(字面重叠 ' . round($dupLocked[1] * 100) . '%)，建议用 memory_update 修改 id=' . $dupLocked[0] . ' 而非新增';
    } elseif ($dupCandidates && $app->rerank()->isConfigured()) {
        // 取重叠度最高的一个候选交给 LLM 判断
        usort($dupCandidates, static function (array $a, array $b): int {
            return $b[1] <=> $a[1];
        });
        $top = $dupCandidates[0];
        $dupJudge = $app->rerank()->judgeDuplicate($content, (string)$top[0]['content']);
        if ($dupJudge === true) {
            $duplicateHint = '疑似与已有条目重复(经语义判断为同一件事)，建议用 memory_update 修改 id=' . $top[0]['id'] . ' 而非新增';
        }
    }

    $app->store()->add($entry);
    $result = array('ok' => true, 'id' => $entry['id'], 'has_vector' => $hasVector, '__dbg' => 'v'.(string)round($dupCoverage ?? 0, 3));
    if ($duplicateHint !== null) {
        $result['warning'] = $duplicateHint;
    }
    return $result;
}

function amcp_update(App $app, array $arguments): array
{
    if (!isset($arguments['id']) || trim((string) $arguments['id']) === '') {
        throw new RuntimeException('id 不能为空');
    }
    $id = (string) $arguments['id'];
    $existing = $app->store()->get($id);
    if ($existing === null) {
        throw new RuntimeException('条目不存在：' . $id);
    }

    // 只更新用户传入的字段，未提供字段保留原值
    $content = isset($arguments['content']) ? (string) $arguments['content'] : $existing['content'];
    $tags = isset($arguments['tags']) && is_array($arguments['tags'])
        ? array_values(array_map('strval', $arguments['tags']))
        : $existing['tags'];
    $metadata = isset($arguments['metadata']) && is_array($arguments['metadata'])
        ? $arguments['metadata']
        : $existing['metadata'];

    $now = (int) (microtime(true) * 1000);
    $entry = array(
        'id' => $id,
        'content' => $content,
        'tags' => $tags,
        'metadata' => $metadata,
        'vector' => $existing['vector'],
        'dim' => $existing['dim'],
        'model' => $existing['model'],
        'tokens' => Search::tokenize($content),
        'created_at' => $existing['created_at'],
        'updated_at' => $now,
    );

    // 内容变了则重算向量（若嵌入可用）；tags/metadata 单独改则沿用原向量
    $contentChanged = $content !== $existing['content'];
    $hasVector = $entry['vector'] !== null;
    if ($contentChanged) {
        if ($app->embeddings()->isConfigured()) {
            $embedded = $app->embeddings()->embed($content, 'passage');
            $entry['vector'] = $embedded['vector'];
            $entry['dim'] = count($embedded['vector']);
            $entry['model'] = $embedded['model'];
            $hasVector = true;
        } else {
            $entry['vector'] = null;
            $entry['dim'] = null;
            $entry['model'] = null;
            $hasVector = false;
        }
    }

    $app->store()->update($id, $entry);
    return array('ok' => true, 'id' => $id, 'content_changed' => $contentChanged, 'has_vector' => $hasVector);
}

function amcp_search(App $app, array $arguments): array
{
    $mode = isset($arguments['mode']) ? (string) $arguments['mode'] : 'auto';
    $query = isset($arguments['query']) ? (string) $arguments['query'] : '';
    if (trim($query) === '') {
        throw new RuntimeException('query 不能为空');
    }
    $topK = isset($arguments['top_k']) ? (int) $arguments['top_k'] : (int) $app->searchConfig('top_k', 5);
    if ($topK < 1) {
        $topK = (int) $app->searchConfig('top_k', 5);
    }
    $minScore = isset($arguments['min_score']) ? (float) $arguments['min_score'] : (float) $app->searchConfig('min_score', 0.5);
    $tagWeight = (float) $app->searchConfig('tag_weight', 2.0);
    $tagsFilter = isset($arguments['tags']) && is_array($arguments['tags'])
        ? array_map('strval', $arguments['tags'])
        : array();

    $useEmbedding = ($mode === 'embedding') || ($mode === 'auto' && $app->embeddings()->isConfigured());
    if ($mode === 'embedding' && !$app->embeddings()->isConfigured()) {
        throw new RuntimeException('嵌入服务未配置，无法使用 embedding 模式');
    }

    // 若强制 embedding 但库里无任何向量条目则直接空结果
    if ($useEmbedding && !$app->embeddings()->isConfigured()) {
        $useEmbedding = false;
    }

    $entries = $app->store()->all();
    if ($tagsFilter) {
        $entries = array_values(array_filter($entries, static function (array $e) use ($tagsFilter) {
            return count(array_intersect($tagsFilter, $e['tags'])) > 0;
        }));
    }

    // 查询包含的词（用于标签粗筛判定）
    $qText = mb_strtolower($query, 'UTF-8');
    $qTokens = Search::tokenize($query);

    // 阶段一：标签命中（精确），无条件高权重
    $tagged = array();
    $rest = array();
    foreach ($entries as $entry) {
        $hit = false;
        foreach ($entry['tags'] as $t) {
            $tl = mb_strtolower(trim((string) $t), 'UTF-8');
            if ($tl !== '' && strpos($qText, $tl) !== false) {
                $hit = true;
                break;
            }
        }
        if ($hit) {
            $tagged[] = $entry;
        } else {
            $rest[] = $entry;
        }
    }

    // 阶段二：计算分数并按阈值过滤
    $qVector = null;
    $dim = null;
    if ($useEmbedding) {
        $embedded = $app->embeddings()->embed($query, 'query');
        $qVector = $embedded['vector'];
        $dim = count($qVector);
    }

    $scored = array();
    $skipped = 0;
    $pool = array_merge($tagged, $rest);

    foreach ($pool as $entry) {
        $vectorScore = 0.0;
        $hasVector = false;
        if ($qVector !== null && is_array($entry['vector']) && count($entry['vector']) === $dim) {
            $c = Search::cosine($qVector, $entry['vector']);
            $vectorScore = $c !== null ? max(0.0, (float) $c) : 0.0;
            $hasVector = true;
        } elseif ($qVector !== null) {
            $skipped++;
        }

        $textScore = Search::textScore($qTokens, $entry['tokens']);
        $inTagged = in_array($entry, $tagged, true);

        // 综合分数：
        //   embedding 模式下以向量语义分为主（标签命中仅作小加成），避免 textScore 抬分无关条目
        //   无向量/纯文字模式回退用 textScore
        if ($qVector !== null && $hasVector) {
            $score = $vectorScore;
            if ($inTagged) {
                $score += $tagWeight;
            }
        } else {
            $score = $textScore;
            if ($inTagged) {
                $score += $tagWeight;
            }
        }

        // 阈值过滤：低于 min_score 的一律剔除
        if ($score < $minScore) {
            continue;
        }
        $scored[] = array($entry, $score, $vectorScore, $textScore, $inTagged);
    }

    usort($scored, static function (array $a, array $b): int {
        if (abs($a[1] - $b[1]) > 0.00001) {
            return $b[1] <=> $a[1];
        }
        return $b[0]['created_at'] <=> $a[0]['created_at'];
    });

    $reranked = false;
    $wantRerank = isset($arguments['rerank']) ? (bool)$arguments['rerank'] : false;
    // 精排候选池：取 min_score 过滤后、排序完的候选（限制在配置的候选数内），不含标签强行拉高的干扰
    if ($wantRerank && $app->rerank()->isConfigured() && $scored) {
        $rrCands = array();
        foreach (array_slice($scored, 0, $app->rerankConfig('max_candidates', 8)) as $pair) {
            $e = $pair[0];
            $rrCands[] = array('id' => $e['id'], 'content' => $e['content']);
        }
        $picked = $app->rerank()->rerank($query, $rrCands);
        $pickedIds = array();
        foreach ($picked as $p) {
            $pickedIds[$p['id']] = true;
        }
        $scored = array_values(array_filter($scored, static function ($pair) use ($pickedIds) {
            return isset($pickedIds[$pair[0]['id']]);
        }));
        usort($scored, static function (array $a, array $b): int {
            if (abs($a[1] - $b[1]) > 0.00001) {
                return $b[1] <=> $a[1];
            }
            return $b[0]['created_at'] <=> $a[0]['created_at'];
        });
        $reranked = true;
    }

    $results = array();
    foreach (array_slice($scored, 0, $topK) as $pair) {
        $results[] = amcp_resultItem($pair[0], $pair[1]);
    }

    $modeUsed = $qVector !== null ? 'embedding' : 'text';

    // 命中日志：记录本次检索召回的条目 id，供后续分析死数据
    $hitIds = array();
    foreach ($results as $res) {
        $hitIds[] = $res['id'];
    }
    try {
        $app->logSearchHit($query, $modeUsed, $hitIds);
    } catch (\Throwable $e) {
        // 日志失败不影响检索结果
    }

    return array(
        'meta' => array(
            'mode' => $modeUsed,
            'min_score' => $minScore,
            'skipped' => $skipped,
            'tagged_hits' => count($tagged),
            'reranked' => $reranked,
        ),
        'results' => $results,
    );
}

function amcp_list(App $app, array $arguments): array
{
    $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 20;
    $offset = isset($arguments['offset']) ? (int) $arguments['offset'] : 0;
    $tag = isset($arguments['tag']) && $arguments['tag'] !== '' ? (string) $arguments['tag'] : null;
    if ($limit < 1) {
        $limit = 20;
    }
    if ($offset < 0) {
        $offset = 0;
    }
    $total = $app->store()->count($tag);
    $items = array();
    foreach ($app->store()->list($limit, $offset, $tag) as $entry) {
        $items[] = array(
            'id' => $entry['id'],
            'content' => $entry['content'],
            'tags' => $entry['tags'],
            'created_at' => $entry['created_at'],
            'created_at_readable' => date('Y-m-d H:i', (int)($entry['created_at'] / 1000)),
            'has_vector' => $entry['vector'] !== null,
            'dim' => $entry['dim'],
            'model' => $entry['model'],
        );
    }
    return array('ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'items' => $items);
}

function amcp_delete(App $app, array $arguments): array
{
    if (!isset($arguments['id']) || trim((string) $arguments['id']) === '') {
        throw new RuntimeException('id 不能为空');
    }
    $deleted = $app->store()->delete((string) $arguments['id']);
    if (!$deleted) {
        throw new RuntimeException('条目不存在：' . $arguments['id']);
    }
    return array('ok' => true, 'id' => (string) $arguments['id']);
}

function amcp_resultItem(array $entry, float $score): array
{
    return array(
        'id' => $entry['id'],
        'score' => round($score, 4),
        'content' => $entry['content'],
        'tags' => $entry['tags'],
        'created_at' => $entry['created_at'],
        'created_at_readable' => date('Y-m-d H:i', (int)($entry['created_at'] / 1000)),
    );
}