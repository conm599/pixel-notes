// 行为单测：从 ai-direct.js 抽取 cleanOutput/foldWs/rtrimLine/matchAndApply/applyBlocks 做行为验证
// matchAndApply 自 v8 起返回 { c, old, new } 而非裸字符串；单测断言统一取 .c
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, 'js/ai-direct.js'), 'utf8');

// 括号配平提取函数体
function extract(name) {
  const marker = 'function ' + name + '(';
  const start = src.indexOf(marker);
  if (start < 0) throw new Error('找不到函数 ' + name);
  let i = src.indexOf('{', start), depth = 0, end = -1;
  for (; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
  }
  return src.slice(start, end);
}

const bundle = ['cleanOutput', 'foldWs', 'rtrimLine', 'rangeOverlap', 'matchAndApply', 'applyBlocks']
  .map(extract).join('\n');
eval(bundle);

let pass = 0, fail = 0;
function t(name, actual, expected) {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (ok) pass++;
  else { fail++; console.log('❌ ' + name + '\n   期望: ' + JSON.stringify(expected) + '\n   实际: ' + JSON.stringify(actual)); }
}
// 断言取 matchAndApply 返回的 .c（v8 结构）
function m(content, search, replace) {
  const r = matchAndApply(content, search, replace);
  return r === null ? null : r.c;
}

// 1. 用户遇到的场景：全文 + 孤儿标记 → 净化
t('净化孤儿标记', cleanOutput('这是一篇新全文。\n\n<<<REPLACE>>>\n<<<END>>>'), '这是一篇新全文。');
t('净化内联标记保留内容', cleanOutput('前文<<<SEARCH>>>标记<<<REPLACE>>>内容<<<END>>>后文'), '前文标记内容后文');
t('净化空串', cleanOutput('   <<<END>>>  '), '');
t('净化大小写不敏感', cleanOutput('x<<<replace>>>y'), 'xy');

// 2. 精确匹配（回归）
t('精确匹配', m('AAA目标BBB', '目标', '替换'), 'AAA替换BBB');
t('精确匹配多行', m('a\n旧\nb', '旧', '新'), 'a\n新\nb');

// 3. 行尾空白差异：原文行尾有空格，SEARCH 没有 → key2 命中
t('行尾空白归一', m('第一行 \n第二行\n第三行', '第一行\n第二行', '新内容'), '新内容\n第三行');
// 精确匹配把 SEARCH 当子串：前缀缩进保留（SEARCH 不含缩进时仍命中缩进后的内容）
t('精确匹配跨前缀缩进', m('    代码\n普通', '代码\n普通', 'X'), '    X');

// 4. 全空白折叠
t('空白折叠(半角多空格)', m('你好   世界', '你好 世界', 'XX'), 'XX');
t('空白折叠(全角空格)', m('你好　世界', '你好 世界', 'XX'), 'XX');
t('空白折叠(跨行+空行)', m('你好\n\n\n世界', '你好 世界', 'XX'), 'XX');

// 5. 唯一性约束（v8：精确级同样要求唯一命中）
t('多命中拒绝', m('重复段\n隔开\n重复段', '重复 段', 'X'), null);
// v8 加固：精确级多处出现也拒绝（旧逻辑取首次出现会错改重复句式文本）
t('精确级多命中拒绝(v8)', m('abab', 'ab', 'X'), null);
t('精确级唯一命中', m('abc', 'b', 'X'), 'aXc');

// 6. 完全不匹配 → null
t('无匹配', m('完全不同的内容', '不存在的搜索', 'X'), null);

// 7. applyBlocks 整合：合法块 + 孤儿标记共存
const r = applyBlocks('<<<SEARCH>>>\n旧文\n<<<REPLACE>>>\n新文\n<<<END>>>\n\n<<<REPLACE>>>\n<<<END>>>', '开头\n旧文\n结尾');
t('块应用成功', [r.applied, r.failed, r.result], [1, 0, '开头\n新文\n结尾']);

// 8. 块全失败记录
const r2 = applyBlocks('<<<SEARCH>>>\n不存在的原文\n<<<REPLACE>>>\n新内容\n<<<END>>>', '');
t('块失败记录', [r2.applied, r2.failed, r2.bad.length], [0, 1, 1]);

// 9. 宽容匹配命中后整段替换保留原文其余部分
const r3 = applyBlocks('<<<SEARCH>>>\n标题内容 \n<<<REPLACE>>>\n新标题\n<<<END>>>', '前缀\n标题内容 \n后缀');
t('宽容块应用', [r3.applied, r3.failed, r3.result], [1, 0, '前缀\n新标题\n后缀']);

// 10. v8 块隔离：后块的 SEARCH 匹配进前块 REPLACE 输出区域时必须拒绝
// 场景复现（用户事故「14 条末句跑到 16 条末尾」）：块1 把 A 句替换进新文本，
// 块2 的 SEARCH 恰好与新写入文本相同 → 旧行为会再改一次（错位链），v8 必须失败
const isoText = '第一段原文。\n中间句。\n第三段原文。';
const isoBlocks = '<<<SEARCH>>>\n第一段原文。\n<<<REPLACE>>>\n重写后的句子。\n<<<END>>>\n<<<SEARCH>>>\n重写后的句子。\n<<<REPLACE>>>\n又一句话。\n<<<END>>>';
const rIso = applyBlocks(isoBlocks, isoText);
t('块隔离:后块命中前块输出拒绝', [rIso.applied, rIso.failed, rIso.result], [1, 1, '重写后的句子。\n中间句。\n第三段原文。']);

// 11. 块隔离：两块改不同区域互不干扰（回归）
const twoText = '甲区域。\n乙区域。\n丙区域。';
const twoBlocks = '<<<SEARCH>>>\n甲区域。\n<<<REPLACE>>>\n甲新。\n<<<END>>>\n<<<SEARCH>>>\n丙区域。\n<<<REPLACE>>>\n丙新。\n<<<END>>>';
const rTwo = applyBlocks(twoBlocks, twoText);
t('块隔离:两块不同区域', [rTwo.applied, rTwo.failed, rTwo.result], [2, 0, '甲新。\n乙区域。\n丙新。']);

// 12. 块隔离 + 区间平移：前块变长后，后块命中仍正确（坐标已平移）
const shiftText = 'AAAA\nBBBB';
const shiftBlocks = '<<<SEARCH>>>\nAAAA\n<<<REPLACE>>>\nAAAAAAAAAAAA\n<<<END>>>\n<<<SEARCH>>>\nBBBB\n<<<REPLACE>>>\nBBBB2\n<<<END>>>';
const rShift = applyBlocks(shiftBlocks, shiftText);
t('块隔离:前块变长后块仍命中', [rShift.applied, rShift.failed, rShift.result], [2, 0, 'AAAAAAAAAAAA\nBBBB2']);

// 13. 编号列表场景（用户便签形态）：重复句式的行只在唯一命中时替换
const listText = '1. 任务一：完成X\n2. 任务二：完成X\n3. 任务三：完成Y';
const listBlocks = '<<<SEARCH>>>\n任务三：完成Y\n<<<REPLACE>>>\n任务三：完成Z\n<<<END>>>';
const rList = applyBlocks(listBlocks, listText);
t('编号列表唯一命中', [rList.applied, rList.failed, rList.result], [1, 0, '1. 任务一：完成X\n2. 任务二：完成X\n3. 任务三：完成Z']);
// 「完成X」出现两次 → 精确级拒绝（旧行为会错改第一条）
const listBlocks2 = '<<<SEARCH>>>\n完成X\n<<<REPLACE>>>\n已完成\n<<<END>>>';
const rList2 = applyBlocks(listBlocks2, listText);
t('编号列表重复句式拒绝', [rList2.applied, rList2.failed], [0, 1]);

console.log('通过 ' + pass + ' / ' + (pass + fail) + (fail === 0 ? ' —— 全部通过 ✅' : ''));
process.exit(fail === 0 ? 0 : 1);
