<?php
// SKIP 匹配器测试：从 api/ai.php 提取真实函数体 eval（测的是发货代码，不是复制品）
$src = file_get_contents(__DIR__ . '/api/ai.php');
if ($src === false) $src = file_get_contents(__DIR__ . '/api/ai.php');

function grab($src, $name) {
    $start = strpos($src, "function $name");
    if ($start === false) throw new Exception('missing ' . $name);
    $i = strpos($src, '{', $start);
    $depth = 0;
    for ($j = $i, $n = strlen($src); $j < $n; $j++) {
        if ($src[$j] === '{') $depth++;
        elseif ($src[$j] === '}') { $depth--; if ($depth === 0) return substr($src, $start, $j - $start + 1); }
    }
    throw new Exception('brace mismatch ' . $name);
}
eval(grab($src, 'aiRangeOverlap'));
eval(grab($src, 'aiRtrimLine'));
eval(grab($src, 'aiFoldWs'));
eval(grab($src, 'aiApplySkipBlock'));

$pass = 0; $fail = 0;
function chk($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else { $fail++; echo "[FAIL] $name\n"; }
}

$content = "## 项目清单\n第1条：买牛奶\n第2条：写报告\n第3条：交房租\n（这里有一大段无需改动的中间内容）\n（更多无关内容……）\n第9条：归档\n第10条：收尾\n";

// 1. 基本 SKIP：头尾锚圈定跨度整体替换
$skip = "第2条：写报告\n<<<SKIP>>>\n第9条：归档";
$r = aiApplySkipBlock($content, $skip, "第2条：写报告（已改）\n插入的新内容\n第9条：归档");
chk('1 基本跨度替换', $r !== null && strpos($r['c'], "第2条：写报告（已改）") !== false
    && strpos($r['c'], '第3条：交房租') === false && strpos($r['c'], '第10条：收尾') !== false);

// 2. L2 行锚降级：首尾锚带行尾空格，精确级失配 → 行归一命中
$content2 = "开始\n目标行A \n中间\n目标行Z \n结束";
$skip2 = "目标行A\n<<<SKIP>>>\n目标行Z";
$r2 = aiApplySkipBlock($content2, $skip2, 'A→Z 已重写');
chk('2 L2 行锚降级', $r2 !== null && strpos($r2['c'], 'A→Z 已重写') !== false && strpos($r2['c'], '开始') !== false);

// 3. 歧义拒绝：同一对锚在文中出现两次 → 唯一性不过 → null
$content3 = "头\nA行\n中\nZ行\n尾\n头\nA行\n中\nZ行\n尾";
$skip3 = "A行\n<<<SKIP>>>\nZ行";
chk('3 歧义拒绝', aiApplySkipBlock($content3, $skip3, 'X') === null);

// 4. 块隔离：命中区间与 doneRanges 相交 → null
$skip4 = "第2条：写报告\n<<<SKIP>>>\n第3条：交房租";
$r4 = aiApplySkipBlock($content, $skip4, '覆盖', array(array(30, 60)));
chk('4 块隔离相交拒绝', $r4 === null);

// 5. 多个 SKIP → null
$skip5 = "第1条：买牛奶\n<<<SKIP>>>\n第2条：写报告\n<<<SKIP>>>\n第9条：归档";
chk('5 多SKIP拒绝', aiApplySkipBlock($content, $skip5, 'X') === null);

// 6. 跨度必须覆盖到行尾（含中间行）——验证替换后原中间行消失
$r6 = aiApplySkipBlock($content, "第3条：交房租\n<<<SKIP>>>\n第10条：收尾", "整段重写了");
chk('6 中间行被吞', $r6 !== null && strpos($r6['c'], '这里有一大段无需改动的中间内容') === false);

echo "PHP-SKIP-TEST: pass=$pass fail=$fail\n";
exit($fail ? 1 : 0);
