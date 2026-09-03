// SSE 客户端解析器单测：直接从 app.js 的 aiApiStream 中提取 feed 实现做行为验证
// （提取而非手抄，保证测的就是线上代码）
// 覆盖：跨 chunk 断行、CRLF 行尾、delta/phase/done 事件、JSON 含中文、非事件行忽略
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, 'js', 'app.js'), 'utf8');

// 提取 aiApiStream 内的 feed 函数（含闭包变量 sseBuf），整段取函数体来构造同构实现
const feedStart = src.indexOf('function feed(chunk) {', src.indexOf('async function aiApiStream'));
if (feedStart < 0) throw new Error('找不到 feed 函数');
let depth = 0, end = -1, i = src.indexOf('{', feedStart);
for (; i < src.length; i++) {
  if (src[i] === '{') depth++;
  else if (src[i] === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
}
const feedSrc = src.slice(feedStart, end);

function makeParser() {
  const events = [];
  let sseBuf = '';
  const blocks = { push: function (b) { events.push(b); } };
  const handlers = null;
  eval(feedSrc);
  return { feed: feed, events: events };
}

let pass = 0, fail = 0;
function t(name, actual, expected) {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (ok) pass++;
  else { fail++; console.log('❌ ' + name + '\n   期望: ' + JSON.stringify(expected) + '\n   实际: ' + JSON.stringify(actual)); }
}

// 1. 正常完整流（模拟服务端输出）
(function () {
  const p = makeParser();
  const sse = 'event: phase\ndata: {"t":"🤖 正在生成…"}\n\n'
    + 'event: delta\ndata: {"t":"你好"}\n\n'
    + 'event: delta\ndata: {"t":"，世界"}\n\n'
    + 'event: done\ndata: {"success":true,"mode":"full","content":"你好，世界"}\n\n';
  p.feed(sse);
  t('完整流事件数', p.events.length, 4);
  t('phase 还原', p.events[0], { ev: 'phase', d: { t: '🤖 正在生成…' } });
  t('delta 拼接', p.events[1].d.t + p.events[2].d.t, '你好，世界');
  t('done 还原', p.events[3].d, { success: true, mode: 'full', content: '你好，世界' });
})();

// 2. 跨 chunk 任意切割：每个字节一个 chunk 喂入，事件不能丢不能碎
(function () {
  const sse = 'event: phase\ndata: {"t":"🧩 长文分段：第 1/3 段…"}\n\n'
    + 'event: delta\ndata: {"t":"第一段文本"}\n\n'
    + 'event: done\ndata: {"success":false,"need_clarify":true,"questions":["问1？","问2？"]}\n\n';
  const p = makeParser();
  // 按 3 字节粒度切块（UTF-8 下可能切碎多字节字符——用字符串 slice 模拟，TextDecoder 才处理字节；这里 chunk 是已解码字符串，粒度切字符即可）
  for (let i = 0; i < sse.length; i += 3) p.feed(sse.slice(i, i + 3));
  t('碎块流事件数', p.events.length, 3);
  t('碎块 phase', p.events[0].d.t, '🧩 长文分段：第 1/3 段…');
  t('碎块 delta', p.events[1].d.t, '第一段文本');
  t('碎块 done questions', p.events[2].d.questions, ['问1？', '问2？']);
})();

// 3. CRLF 行尾（服务端/中间层可能转换）
(function () {
  const p = makeParser();
  p.feed('event: delta\r\ndata: {"t":"CRLF 消息"}\r\n\r\nevent: done\r\ndata: {"success":true}\r\n\r\n');
  t('CRLF 事件数', p.events.length, 2);
  t('CRLF delta', p.events[0].d.t, 'CRLF 消息');
  t('CRLF done', p.events[1].d.success, true);
})();

// 4. 噪音行忽略（注释行、空事件、坏 JSON）
(function () {
  const p = makeParser();
  p.feed(': keep-alive 注释\n\nevent: delta\ndata: 不是JSON\n\n\nevent: delta\ndata: {"t":"好的"}\n\n');
  t('噪音过滤后事件数', p.events.length, 1);
  t('噪音过滤内容', p.events[0].d.t, '好的');
})();

// 5. 流末尾无 done（连接中断）：解析器如实暴露，调用方负责报错
(function () {
  const p = makeParser();
  p.feed('event: delta\ndata: {"t":"断在前"}\n\n');
  const hasDone = p.events.some(e => e.ev === 'done');
  t('中断无 done', hasDone, false);
})();

console.log('通过 ' + pass + ' / ' + (pass + fail) + (fail === 0 ? ' —— 全部通过 ✅' : ''));
process.exit(fail === 0 ? 0 : 1);
