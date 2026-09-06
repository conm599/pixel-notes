// SKIP 匹配器测试（JS 侧）：从 js/ai-direct.js 提取真实函数体 eval（测的是发货代码）
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, 'js/ai-direct.js'), 'utf8');

function grab(name) {
  const start = src.indexOf('function ' + name);
  if (start === -1) throw new Error('missing ' + name);
  let i = src.indexOf('{', start), depth = 0;
  for (let j = i; j < src.length; j++) {
    if (src[j] === '{') depth++;
    else if (src[j] === '}') { depth--; if (depth === 0) return src.slice(start, j + 1); }
  }
  throw new Error('brace mismatch ' + name);
}
const code = grab('rtrimLine') + '\n' + grab('rangeOverlap') + '\n' + grab('foldWs') + '\n' + grab('matchSkipApply');
eval(code);

let pass = 0, fail = 0;
function chk(name, cond) {
  if (cond) { pass++; console.log('[PASS] ' + name); }
  else { fail++; console.log('[FAIL] ' + name); }
}

const content = "## 项目清单\n第1条：买牛奶\n第2条：写报告\n第3条：交房租\n（这里有一大段无需改动的中间内容）\n（更多无关内容……）\n第9条：归档\n第10条：收尾\n";

const skip = "第2条：写报告\n<<<SKIP>>>\n第9条：归档";
const r = matchSkipApply(content, skip, "第2条：写报告（已改）\n插入的新内容\n第9条：归档");
chk('1 基本跨度替换', r !== null && r.c.includes('第2条：写报告（已改）') && !r.c.includes('第3条：交房租') && r.c.includes('第10条：收尾'));

const content2 = "开始\n目标行A \n中间\n目标行Z \n结束";
const skip2 = "目标行A\n<<<SKIP>>>\n目标行Z";
const r2 = matchSkipApply(content2, skip2, 'A→Z 已重写');
chk('2 L2 行锚降级', r2 !== null && r2.c.includes('A→Z 已重写') && r2.c.includes('开始'));

const content3 = "头\nA行\n中\nZ行\n尾\n头\nA行\n中\nZ行\n尾";
chk('3 歧义拒绝', matchSkipApply(content3, "A行\n<<<SKIP>>>\nZ行", 'X') === null);

chk('4 块隔离相交拒绝', matchSkipApply(content, "第2条：写报告\n<<<SKIP>>>\n第3条：交房租", '覆盖', [[30, 60]]) === null);

chk('5 多SKIP拒绝', matchSkipApply(content, "第1条：买牛奶\n<<<SKIP>>>\n第2条：写报告\n<<<SKIP>>>\n第9条：归档", 'X') === null);

const r6 = matchSkipApply(content, "第3条：交房租\n<<<SKIP>>>\n第10条：收尾", "整段重写了");
chk('6 中间行被吞', r6 !== null && !r6.c.includes('这里有一大段无需改动的中间内容'));

console.log('JS-SKIP-TEST: pass=' + pass + ' fail=' + fail);
process.exit(fail ? 1 : 0);
