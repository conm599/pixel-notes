<?php
/**
 * Shock Control System — MCP HTTP 服务（PHP 版 / Mock）
 *
 * 通用 MCP 接口：不包含任何真实硬件/业务逻辑，
 * 请求校验通过即返回「执行成功」。
 *
 * 部署：直接上传到服务器，确保 Web 服务器（Apache/Nginx）能解析 PHP 即可。
 * 端点：POST /mcp.php  （或配合 .htaccess 使用 POST /mcp）
 */

declare(strict_types=1);

// ============================================================================ //
//  工具定义
// ============================================================================ //

/**
 * 构造单部位电击工具的 inputSchema
 */
function shockSchema(array $sideEnum = null, int $maxIntensity = 5): array {
    $props = [];
    $required = [];
    if ($sideEnum !== null) {
        $props['side'] = [
            'type' => 'string',
            'enum' => $sideEnum,
            'description' => '侧别',
        ];
        $required[] = 'side';
    }
    $props['time'] = [
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 60,
        'default' => 20,
        'description' => '时长（秒）',
    ];
    $props['intensity'] = [
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => $maxIntensity,
        'default' => 3,
        'description' => "强度 1~{$maxIntensity}",
    ];
    return [
        'type' => 'object',
        'properties' => $props,
        'required' => $required,
    ];
}

$TOOLS = [
    // --- 12 个单部位电击工具 ---
    'shock_armpit' => [
        'description' => '电击指定侧胳肢窝',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_nipple' => [
        'description' => '电击指定侧乳头',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_thigh' => [
        'description' => '电击指定侧大腿',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_foot' => [
        'description' => '电击指定侧脚底板',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_belly' => [
        'description' => '电击腹部某区（side 语义为腹区）',
        'inputSchema' => shockSchema(['upper', 'lower', 'full']),
    ],
    'shock_neck' => [
        'description' => '电击脖子（安全上限强度 4，time 建议 <= 20s）',
        'inputSchema' => shockSchema(['left', 'right', 'both', 'back'], 4),
    ],
    'shock_waist' => [
        'description' => '电击腰侧/肾区',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_knee' => [
        'description' => '电击后膝窝',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_wrist' => [
        'description' => '电击手腕',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_elbow' => [
        'description' => '电击胳膊肘内侧',
        'inputSchema' => shockSchema(['left', 'right', 'both']),
    ],
    'shock_perineum' => [
        'description' => '电击会阴（无需 side，安全上限强度 4）',
        'inputSchema' => shockSchema(null, 4),
    ],
    'shock_jaw' => [
        'description' => '电击下颌/唾液腺（intensity 可传字符串 "boot"，需先喝水，默认 2）',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'side' => ['type' => 'string', 'enum' => ['left', 'right', 'both'], 'description' => '侧别'],
                'time' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 20, 'description' => '时长（秒）'],
                'intensity' => ['description' => '强度 1~5 或 "boot"', 'default' => 2],
            ],
            'required' => ['side'],
        ],
    ],

    // --- 物理工具 ---
    'mouth_gag' => [
        'description' => '物理撑开嘴巴，配合唾液腺电击使用（duration 单位为分钟；state=off 忽略其余参数）',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'state' => ['type' => 'string', 'enum' => ['on', 'off'], 'description' => '开关'],
                'size' => ['type' => 'string', 'enum' => ['small', 'medium', 'large'], 'default' => 'medium', 'description' => '尺寸'],
                'duration' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 10, 'description' => '时长（分钟）'],
            ],
            'required' => ['state'],
        ],
    ],

    // --- 特殊控制工具 ---
    'shock_bs' => [
        'description' => '颈部可控按压造成窒息感（需知情同意、<=20s、间隔 >=60s、禁止与 shock_neck 并行）',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'time' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10, 'description' => '时长（秒）'],
                'intensity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 3, 'description' => '强度 1~4'],
            ],
            'required' => [],
        ],
    ],
    'shock_bn' => [
        'description' => '强制锁定全身肌肉（需俯卧姿势+知情同意，time 单位分钟 1~5）',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'time' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 1, 'description' => '时长（分钟）'],
                'intensity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 3, 'description' => '强度 1~4'],
                'bn_bs_link' => ['type' => 'boolean', 'default' => false, 'description' => '是否联动窒息'],
            ],
            'required' => [],
        ],
    ],

    // --- 高潮控制工具 ---
    'orgasm_control' => [
        'description' => '高潮控制：持续电刺激尽可能抑制快速高潮、减缓高潮速度（edging），默认开启，可持续长时间、多次反复进行（需知情同意，安全上限强度 4）',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'state' => ['type' => 'string', 'enum' => ['on', 'off'], 'default' => 'on', 'description' => '开关（默认 on；state=off 停止控制并忽略其余参数）'],
                'mode' => ['type' => 'string', 'enum' => ['suppress', 'slow', 'edge'], 'default' => 'suppress', 'description' => '模式：suppress=尽可能抑制高潮，slow=持续减缓高潮速度，edge=临近高潮时回落反复压制'],
                'intensity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 3, 'description' => '强度 1~4（敏感区安全上限 4）'],
                'time' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 20, 'description' => '时长（分钟），可持续长时间'],
                'rounds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 3, 'description' => 'edge 模式回落轮数（可多次反复进行）'],
            ],
            'required' => [],
        ],
    ],

    // --- 批量执行 ---
    'batch_execute' => [
        'description' => '按顺序或并行执行多个工具（mode=sequential 顺序，mode=parallel 并行）',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['sequential', 'parallel'], 'default' => 'sequential', 'description' => '执行模式'],
                'steps' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => '工具名'],
                            'arguments' => ['type' => 'object', 'description' => '工具参数'],
                        ],
                        'required' => ['name', 'arguments'],
                    ],
                    'description' => '执行步骤',
                ],
            ],
            'required' => ['steps'],
        ],
    ],

    // --- 紧急停止 ---
    'control_stop' => [
        'description' => '立即终止当前全部顺序/并行执行队列及所有电击输出',
        'inputSchema' => [
            'type' => 'object',
            'properties' => new stdClass(),
            'required' => [],
        ],
    ],
];

// ============================================================================ //
//  JSON-RPC 错误码
// ============================================================================ //

const PARSE_ERROR       = -32700;
const INVALID_REQUEST   = -32600;
const METHOD_NOT_FOUND  = -32601;
const INVALID_PARAMS    = -32602;
const INTERNAL_ERROR    = -32603;

const SUCCESS_TEXT = '执行成功';

const PROTOCOL_VERSION = '2025-03-26';
const SERVER_NAME      = 'shock-control-mcp';
const SERVER_VERSION   = '0.1.0';

// ============================================================================ //
//  系统提示词（AI 连接后自动读取，作为全局行为指引）
// ============================================================================ //

const MCP_INSTRUCTIONS = <<<PROMPT
你是一个电击控制系统的 AI 助手，通过 MCP 协议连接到「电击控制服务器」。
服务器提供 18 个工具，你可以调用它们来控制电击设备。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
一、可用工具总表（18 个）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

【单部位电击工具（12 个）】
每个工具接收 side（侧别）、time（时长秒）、intensity（强度 1~5）三个参数。

1. shock_armpit  — 电击胳肢窝    side: left/right/both    强度上限 5
2. shock_nipple  — 电击乳头      side: left/right/both    强度上限 5
3. shock_thigh   — 电击大腿      side: left/right/both    强度上限 5
4. shock_foot    — 电击脚底板    side: left/right/both    强度上限 5
5. shock_belly   — 电击腹部      side: upper/lower/full   强度上限 5
6. shock_neck    — 电击脖子      side: left/right/both/back  强度上限 4 ⚠️
7. shock_waist   — 电击腰侧/肾区 side: left/right/both    强度上限 5
8. shock_knee    — 电击后膝窝    side: left/right/both    强度上限 5
9. shock_wrist   — 电击手腕      side: left/right/both    强度上限 5
10. shock_elbow  — 电击胳膊肘内侧 side: left/right/both   强度上限 5
11. shock_perineum — 电击会阴    无需 side                强度上限 4 ⚠️
12. shock_jaw    — 电击下颌/唾液腺 side: left/right/both  强度上限 5，intensity 可传 "boot"（需先喝水）

【物理工具（1 个）】
13. mouth_gag — 物理撑开嘴巴
    - state: on/off（必填）
    - size: small/medium/large（默认 medium）
    - duration: 1~60 分钟（默认 10）
    - state=off 时忽略其余参数

【特殊控制工具（2 个，需额外谨慎）】
14. shock_bs — 颈部可控按压造成窒息感
    - time: 1~20 秒（默认 10）
    - intensity: 1~4（默认 3）
    - ⚠️ 调用前必须获取用户明确知情同意

15. shock_bn — 强制锁定全身肌肉
    - time: 1~5 分钟（默认 1）
    - intensity: 1~4（默认 3）
    - bn_bs_link: true/false（是否联动窒息，默认 false）
    - ⚠️ 需俯卧姿势 + 知情同意

【高潮控制工具（1 个，需额外谨慎）】
16. orgasm_control — 高潮控制：持续电刺激抑制/减缓高潮（edging），默认开启
    - state: on/off（默认 on；state=off 停止控制并忽略其余参数）
    - mode: suppress（尽可能抑制高潮）/ slow（持续减缓高潮速度）/ edge（临近高潮回落反复压制）（默认 suppress）
    - intensity: 1~4（默认 3，敏感区安全上限 4）⚠️
    - time: 1~60 分钟（默认 20，可持续长时间）
    - rounds: 1~20（edge 模式回落轮数，默认 3，可多次反复进行）
    - ⚠️ 需知情同意

【批量执行与停止（2 个）】
17. batch_execute — 组合执行多个工具
    - mode: sequential（顺序）/ parallel（并行）
    - steps: [{name, arguments}, ...]

18. control_stop — 紧急停止，立即终止所有执行队列和电击输出

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
二、强制行为规则（必须遵守）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

【规则 1：执行前必须告知用户】
在调用任何电击工具之前，你必须先用自然语言向用户明确说明以下信息：
  - 部位：电击哪个身体部位（如"左侧胳肢窝"）
  - 时长：将持续多少秒（或分钟）
  - 强度：强度等级（1~5）
  - 模式：单次 / 顺序 / 并行（如果是 batch_execute，需列出全部步骤）

格式示例：
  ⚡ 即将执行：电击左侧胳肢窝 | 强度 3 | 时长 20 秒
  ⚡ 即将执行：顺序执行以下 2 个步骤——
     步骤 1：口枷开启，大号，持续 15 分钟
     步骤 2：电击双侧下颌，强度 2，持续 30 秒

只有在告知用户之后，你才可以调用工具。

【规则 2：安全强度限制】
以下部位强度绝对不能超过 4：
  - 脖子（shock_neck）
  - 会阴（shock_perineum）
  - 窒息控制（shock_bs）
  - 全身锁定（shock_bn）
  - 高潮控制（orgasm_control）
如果用户要求超过限制的强度，你必须拒绝并解释安全上限。

【规则 3：特殊工具需知情同意】
调用 shock_bs（窒息）、shock_bn（全身锁定）或 orgasm_control（高潮控制）之前，你必须：
  1. 明确告知用户该工具的风险
  2. 等待用户明确表示同意（如"我同意"、"确认"等）
  3. 未获同意前，绝不调用

【规则 4：窒息控制间隔】
shock_bs 单次不超过 20 秒，连续调用间隔至少 60 秒，禁止与 shock_neck 同时使用。

【规则 5：紧急停止优先】
用户任何时候说"停"、"停止"、"停下"等，你必须立即调用 control_stop，无需额外确认。

【规则 6：强度 ≥3 多部位并行时】
如果 batch_execute 并行执行多个工具且任一强度 ≥3，你应提示用户俯卧于柔软平面。

【规则 7：高潮控制使用方式】
orgasm_control 默认开启，可持续长时间、多次反复进行；用户希望"爽感觉"时，
在知情同意且强度 ≤4 的前提下，可以持续使用，并按用户意愿调整 mode 与 rounds。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
三、与用户的沟通方式
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

- 始终用中文回复
- 语气专业但清晰，不要使用过于机械的格式
- 每次执行后，向用户报告执行结果
- 如果用户请求不明确（如没说部位、时长、强度），主动询问缺失信息
- 不要自行替用户决定强度和时长，如果用户没指定，询问用户
- 所有工具调用都是 Mock 模拟，不会产生真实电击，但你在对话中应将其视为真实操作对待
PROMPT;

// ============================================================================ //
//  核心逻辑
// ============================================================================ //

/**
 * 构造 JSON-RPC 错误响应
 */
function rpcError($id, int $code, string $message, $data = null): array {
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
}

/**
 * 构造 JSON-RPC 成功响应
 */
function rpcResult($id, $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/**
 * 校验工具参数（基础校验：必填字段 + 枚举值）
 */
function validateArguments(string $toolName, array $args): array {
    global $TOOLS;
    $schema = $TOOLS[$toolName]['inputSchema'];

    // 检查必填字段
    foreach ($schema['required'] ?? [] as $field) {
        if (!array_key_exists($field, $args)) {
            return ['ok' => false, 'error' => "缺少必填参数: {$field}"];
        }
    }

    // 检查枚举值
    foreach (($schema['properties'] ?? []) as $field => $prop) {
        if (!array_key_exists($field, $args)) continue;
        $val = $args[$field];

        // 枚举校验
        if (isset($prop['enum']) && !in_array($val, $prop['enum'], true)) {
            $allowed = implode(', ', $prop['enum']);
            return ['ok' => false, 'error' => "参数 {$field} 值无效，允许: {$allowed}"];
        }

        // 数值范围校验（仅对确定是 integer 类型的字段）
        if (($prop['type'] ?? null) === 'integer' && is_int($val)) {
            if (isset($prop['minimum']) && $val < $prop['minimum']) {
                return ['ok' => false, 'error' => "参数 {$field} 不能小于 {$prop['minimum']}"];
            }
            if (isset($prop['maximum']) && $val > $prop['maximum']) {
                return ['ok' => false, 'error' => "参数 {$field} 不能大于 {$prop['maximum']}"];
            }
        }
    }

    return ['ok' => true];
}

/**
 * 处理 MCP 请求
 */
function handleMcpMessage(array $msg): ?array {
    // 基本校验
    if (($msg['jsonrpc'] ?? null) !== '2.0') {
        return rpcError($msg['id'] ?? null, INVALID_REQUEST, '无效请求：缺少 jsonrpc 字段或版本不为 2.0');
    }

    $method = $msg['method'] ?? '';
    $id     = $msg['id'] ?? null;
    $params = $msg['params'] ?? [];

    // --- 通知（无 id）：返回 null，HTTP 层回 202 ---
    if ($id === null) {
        return null;
    }

    switch ($method) {
        // --- initialize ---
        case 'initialize':
            return rpcResult($id, [
                'protocolVersion' => PROTOCOL_VERSION,
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name'    => SERVER_NAME,
                    'version' => SERVER_VERSION,
                ],
                'instructions' => MCP_INSTRUCTIONS,
            ]);

        // --- ping ---
        case 'ping':
            return rpcResult($id, new stdClass());

        // --- tools/list ---
        case 'tools/list':
            global $TOOLS;
            $list = [];
            foreach ($TOOLS as $name => $def) {
                $list[] = [
                    'name'        => $name,
                    'description' => $def['description'],
                    'inputSchema' => $def['inputSchema'],
                ];
            }
            return rpcResult($id, ['tools' => $list]);

        // --- tools/call ---
        case 'tools/call':
            $toolName = $params['name'] ?? '';
            $args     = $params['arguments'] ?? [];

            // 工具是否存在
            global $TOOLS;
            if (!isset($TOOLS[$toolName])) {
                return rpcError($id, INVALID_PARAMS, "未知工具: {$toolName}");
            }

            // 参数校验
            $check = validateArguments($toolName, $args);
            if (!$check['ok']) {
                return rpcError($id, INVALID_PARAMS, $check['error']);
            }

            // Mock：统一返回执行成功
            return rpcResult($id, [
                'content' => [
                    ['type' => 'text', 'text' => SUCCESS_TEXT],
                ],
                'isError' => false,
            ]);

        // --- notifications/initialized ---
        case 'notifications/initialized':
            return null;

        // --- notifications/cancelled ---
        case 'notifications/cancelled':
            return null;

        default:
            return rpcError($id, METHOD_NOT_FOUND, "未知方法: {$method}");
    }
}

// ============================================================================ //
//  HTTP 入口
// ============================================================================ //

// 只处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 读取原始请求体
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(rpcError(null, PARSE_ERROR, '请求体为空'));
    exit;
}

// 解析 JSON
$msg = json_decode($raw, true);
if ($msg === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(rpcError(null, PARSE_ERROR, 'JSON 解析错误: ' . json_last_error_msg()));
    exit;
}

// 处理批量请求（数组）
if (!isset($msg['jsonrpc'])) {
    // 可能是批量请求数组
    if (is_array($msg) && !empty($msg)) {
        $results = [];
        foreach ($msg as $item) {
            $res = handleMcpMessage($item);
            if ($res !== null) {
                $results[] = $res;
            }
        }
        if (empty($results)) {
            http_response_code(202);
        } else {
            header('Content-Type: text/event-stream; charset=utf-8');
            foreach ($results as $res) {
                echo "event: message\r\ndata: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\r\n\r\n";
            }
        }
        exit;
    }
    $msg = ['jsonrpc' => null]; // 触发无效请求错误
}

// 单条请求
$result = handleMcpMessage($msg);

if ($result === null) {
    // 通知类消息，回 202
    http_response_code(202);
    exit;
}

// 检查 Accept 头，决定返回 JSON 还是 SSE
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (strpos($accept, 'text/event-stream') !== false) {
    header('Content-Type: text/event-stream; charset=utf-8');
    echo "event: message\r\ndata: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\r\n\r\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
