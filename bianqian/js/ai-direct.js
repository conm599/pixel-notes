/**
 * Pixel Notes - AI 前端直连模块（独立文件，与 app.js 解耦）
 *
 * 当用户在 AI 设置中填写了自己的透明反代（Workers）地址时，
 * AI 编辑请求从浏览器直接发送到用户自己的代理，完全不经过 Pixel Notes 平台。
 *
 * 实现以 protocol.md v13 为准（分段参数 / prompt 模板 / 纠错话术 / 澄清提问 / TOOL 工具块 / SKIP 锚 / 整理 Agent SSE 的唯一事实源），改动需与 api/ai.php 同步
 *
 * 接口：window.AIDirect.edit({ title, content, instruction, style, proxy, baseUrl, apiKey, model })
 * 返回：Promise<{ success, content, mode, applied, failed, message }>
 */
(function () {
  'use strict';

  // UTF-16 安全清洗（v13.6）：slice 截断 emoji 会产生孤立代理项，JSON.stringify 输出非法转义，
  // PHP json_decode 会直接失败 → 请求被丢弃（表现为「未知操作」）
  function utf16Sanitize(s) {
    s = String(s == null ? '' : s);
    var out = '', i, c, n;
    for (i = 0; i < s.length; i++) {
      c = s.charCodeAt(i);
      if (c >= 0xD800 && c <= 0xDBFF) {
        n = (i + 1 < s.length) ? s.charCodeAt(i + 1) : 0;
        if (n >= 0xDC00 && n <= 0xDFFF) { out += s.charAt(i) + s.charAt(i + 1); i++; }
        else out += '\uFFFD';
      } else if (c >= 0xDC00 && c <= 0xDFFF) {
        out += '\uFFFD';
      } else {
        out += s.charAt(i);
      }
    }
    return out;
  }

  // 出站 JSON 深度清洗（v13.6）：必须在 stringify 之前——stringify 会把孤立代理项
  // 转成 \ud83c 文本转义，之后再清洗就看不到真正的非法字符了。
  function utf16SanitizeDeep(v, depth) {
    if (typeof v === 'string') return utf16Sanitize(v);
    if (v == null || typeof v !== 'object') return v;
    if ((depth || 0) > 8) return v;
    if (Object.prototype.toString.call(v) === '[object Array]') {
      var arr = [];
      for (var i = 0; i < v.length; i++) arr.push(utf16SanitizeDeep(v[i], (depth || 0) + 1));
      return arr;
    }
    var o = {};
    for (var k in v) { if (Object.prototype.hasOwnProperty.call(v, k)) o[k] = utf16SanitizeDeep(v[k], (depth || 0) + 1); }
    return o;
  }

  function normalizeEndpoint(base) {
    var b = String(base || '').trim();
    if (!b) return '';
    if (b.indexOf('://') === -1) b = 'https://' + b;
    if (b.indexOf('https://') !== 0 && b.indexOf('http://') !== 0) return '';
    b = b.replace(/\/+$/, '');
    if (b.slice(-17) === '/chat/completions') return b;
    return b + '/chat/completions';
  }

  function proxyUrl(proxy) {
    var p = String(proxy || '').trim();
    if (!p) return '';
    if (p.indexOf('://') === -1) p = 'https://' + p;
    if (p.indexOf('https://') !== 0 && p.indexOf('http://') !== 0) return '';
    return p.replace(/\/+$/, '');
  }

  function buildSystemPrompt(style, now) {
    var s = '你是一个便签编辑代理。用户会给你一篇 Markdown 便签（可能为空）和一条编辑指令，你要精准地完成编辑。\n'
      + '【输出格式（三选一）】\n'
      + 'A. 局部修改（默认首选）：只改动需要改的地方。每个改动输出一个替换块，格式严格如下：\n'
      + '<<<SEARCH>>>\n'
      + '（便签原文中要被修改的那段文字，必须与原文逐字一致，包括空格、换行、标点）\n'
      + '<<<REPLACE>>>\n'
      + '（修改后的文字）\n'
      + '<<<END>>>\n'
      + '可以有多个替换块，按顺序排列。\n'
      + '【SEARCH 最小化（硬性规则，治 token 浪费）】SEARCH 只放「定位所需的最短锚点」：通常是要修改的那一句/那一行，最多加一行紧邻上下文，严禁为了保险复制整段、整节或大段原文——SEARCH 明显长于 REPLACE 属于浪费，必须改用更短锚点。要定位的位置在很长段落/列表中部时，用 SKIP 省略中段：SEARCH 写成「首行锚点」一行 + 一行 <<<SKIP>>> + 「尾行锚点」一行（每个 SEARCH 最多一个 <<<SKIP>>>），首尾锚点必须是原文中逐字存在的行；引擎会圈定首尾锚点之间的整个跨度整体替换为 REPLACE，所以 REPLACE 必须包含该跨度改写后的完整内容。\n'
      + 'B. 全文重写：仅当指令要求整体重构、全文翻译、全文总结、从零创作时，才直接输出完整的新便签全文。\n'
      + 'C. 澄清提问（当且仅当指令有歧义、缺关键信息或者你拿不准用户到底要改成什么样时使用，优先级最高，出现时必须只输出这个）：\n'
      + '<<<CLARIFY>>>\n'
      + '（一个问题一行，最多 3 个，简洁具体；不要重复已经问过的问题）\n'
      + '<<<END>>>\n'
      + '存在任何疑问就必须先提问：指令有歧义、缺关键信息（主题、风格、长度、格式、语言等）、无法确定用户要改什么、或对用户意图没有把握时，一律用 C 提问，绝对不能猜、不能编造、不能自行假设，宁可多问一句，不可错改一字。直到用户回答后信息足够再执行 A 或 B。\n'
      + '【先问后做，二者互斥】C 是独立的一轮输出：提问那一轮绝不能同时生成任何正文；反过来，一旦选择 A 或 B，输出里就绝不能再出现任何提问、确认、选项或结尾寒暄（如「需要哪种风格？」「有其他想法可补充」一律禁止）。拿不准就先用 C 问清楚，问完再动手，绝不许先编一版内容再附一句反问。\n'
      + '【硬性规则】\n'
      + '1. 绝对禁止删除、改写、移动用户已有的链接、URL、HTML 标签、图片/音频/视频/iframe 嵌入和代码块，除非指令明确要求处理它们\n'
      + '2. 用户没让改的部分必须一字不动，只做最小限度的必要修改，禁止顺手润色或重排\n'
      + '3. 不要输出任何解释、前言、结束语，不要用代码围栏（```）包裹整个输出\n'
      + '4. 保持 Markdown 格式；便签支持：标题/加粗/斜体/列表/引用/链接/图片/任务列表/代码块\n'
      + '5. 便签标题不在你负责范围内，只编辑正文\n'
      + '6. 便签内容为空时【严禁使用 A 格式】：空便签没有任何原文可供 SEARCH 匹配，输出替换块必定失败。指令是创作新内容就直接用 B 格式输出完整新全文；指令像是要编辑已有内容但无从下手时，用 C 澄清提问确认用户想要什么\n'
      + '7. 选择 B（全文重写）时，输出只能是新便签全文本身：开头与结尾都不得有任何提问、选项、说明或客套话；若对风格/格式/长度等拿不准，必须改用 C 先提问，严禁先输出一版再反问\n'
      + '8. SEARCH 锚点最小化：能一句/一行定位就不用多行；长跨度用 <<<SKIP>>> 省略中段（见 A 格式说明）。复制大段原文进 SEARCH 是严重浪费，禁止\n'
      + '【角色】你是一名便签编辑执行器（embodied editor），任务是精确完成用户的编辑指令，不是聊天、不是角色扮演。\n'
      + '【工具铁律】对便签内容的任何改动都必须通过工具调用完成；每轮回复必须至少调用一个工具（原生 function calling；上游不支持时改用文本协议 <<<TOOL>>>{json}<<<END>>>，二者自动切换）。只输出文字而没有任何工具调用＝协议违规，会被系统打回重试——禁止用文字回答用户、禁止复述或展示便签内容、禁止把整篇正文当作结果输出。\n'
      + '工具与参数（原生调用名 / 文本协议 name 相同）：\n'
      + 'replace_text {old_string, new_string} —— 局部替换（默认首选）：old_string 必须逐字复制便签当前内容且唯一，不唯一就带上前一行或后一行；new_string 留空 = 删除该片段。\n'
      + 'append_text {text} —— 追加到便签末尾（用户常要求「往后加」，优先用它；不改动已有内容）。\n'
      + 'write_note {content} —— 整篇重写（仅整篇翻译/整体重构；必须给完整内容，严禁省略占位）。\n'
      + 'read_note {id} / list_folders {path} —— 只读查看（当前便签内容已给你，一般无需读）。\n'
      + 'ask_user {questions:[...]} —— 指令有歧义或缺信息时提问（最多 3 个）。\n'
      + 'finish {} —— 所有改动完成后必须调用，提交结果。\n'
      + '【编辑规则】只输出改动、不要复制大段未变内容；同一处的多次改动用多个工具调用按顺序做；工具返回错误（not_found/ambiguous）时，照抄返回的 did_you_mean 片段修正 old_string 重试，已成功的改动不要重发；严禁因为一次失败就改用整篇重写。\n'
      + '【反跑偏（硬性）】① 不得寒暄、卖萌、自称、加 emoji 装饰；② 不得以「好的/当然/没问题/Sure/OK」等客套开头；③ 不得在结束时反问或邀请继续对话；④ 不得复述指令、不得解释你在做什么超过一句话；⑤ 工具之外的文字只作为进度说明，永远不会写进便签。\n'
      + '【目标】用户目标只有两种终止方式：改完并调用 finish，或调用 ask_user 提问。不要来回闲聊。\n'
      + '\n【图片尺寸】图片默认撑满便签可用宽度。用户嫌图片太大/太小要求调整某张图片的显示大小时，用 HTML 图片标签加 width 数字属性：固定宽度写 <img src="图片URL" width="360">，按容器比例写 <img src="图片URL" width="50%">。严禁 style 属性、严禁 width="300px" 这类带 px 的写法、严禁用 div 包裹缩放——这些都不会生效；Markdown 的 ![alt](url) 写法无法指定尺寸。调整尺寸时只加/改 width，图片 URL 与其余内容一字不动\n'
      + '若上游不支持工具调用，可退回 A/B/C 文本格式：A=替换块，B=整篇全文，C=澄清块（<<<CLARIFY>>>）。\n';
    if (style) s += '\n【用户风格偏好】在不违背上述硬性规则的前提下，尽量按以下风格完成编辑：' + style;
    if (now) s += '\n【当前时间】现在是 ' + now + '（用户本地时间）。涉及时间、日期、星期、节假日等内容的编辑请以此为准，不要虚构时间。';
    return s;
  }

  // 解析澄清提问块 <<<CLARIFY>>>...<<<END>>>，返回问题数组
  // 编辑 Agent 工具（v12）：对工作副本执行改动；聊天文字永不进内容
  function editToolLabel(name) {
    var m = { append_text: '追加内容', replace_text: '局部替换', set_full_text: '整篇写入', write_note: '整篇写入', read_note: '读取便签', list_folder: '查看文件夹', list_folders: '查看文件夹', finish: '完成', ask_user: '提问' };
    return m[name] || name;
  }
  function editToolExec(name, args, ctx) {   // ctx = { work, touched }
    args = args || {};
    function excerpt() {
      var w = ctx.work;
      return { current_length: w.length, current_tail: w.slice(-600) };
    }
    if (name === 'append_text') {
      var t = String(args.text || '').trim();
      if (!t) return { ok: false, error: 'empty_text' };
      ctx.work = ctx.work === '' ? t : ctx.work.replace(/\s+$/, '') + '\n\n' + t;
      ctx.touched = true;
      // appended = 本次追加的内容；current_tail = 追加之后的便签末尾（模型据此确认结果）
      return Object.assign({ ok: true, action: 'append', appended: t.slice(0, 300) }, excerpt());
    }
    if (name === 'replace_text') {
      var se = String(args.old_string !== undefined ? args.old_string : (args.search !== undefined ? args.search : ''));
      var rp = String(args.new_string !== undefined ? args.new_string : (args.replace !== undefined ? args.replace : ''));
      if (!se.trim()) return { ok: false, error: 'empty_search' };
      var cnt = ctx.work.split(se).length - 1;
      if (cnt > 1) return Object.assign({ ok: false, error: 'ambiguous', count: cnt,
        hint: 'old_string 出现 ' + cnt + ' 次；请把前后各 1-2 行一起放进 old_string 使其唯一' }, excerpt());
      var res = matchAndApply(ctx.work, se, rp, []);
      if (res) { ctx.work = res.c; ctx.touched = true; return Object.assign({ ok: true, action: 'replace', matched: 'exact' }, excerpt()); }
      var bm = bestMatchSnippet(ctx.work, se);
      var fb = { ok: false, error: 'not_found', hint: 'old_string 必须逐字复制便签当前内容（含空格/换行/Markdown 符号）。' };
      if (bm) {
        fb.did_you_mean_line = bm.line; fb.did_you_mean = bm.excerpt; fb.similarity = bm.score;
        fb.hint += '便签第 ' + bm.line + ' 行附近有相似内容（相似度 ' + bm.score + '%），请照抄 did_you_mean 修正 old_string 后重试；已成功的改动不要再发。';
      } else {
        fb.hint += '当前便签内容见 current_tail；可先调用 read_note 重读全文再复制。';
      }
      return Object.assign(fb, excerpt());
    }
    if (name === 'set_full_text' || name === 'write_note') {
      var full = args.content !== undefined ? args.content : (args.text !== undefined ? args.text : '');
      ctx.work = String(full).replace(/\r\n/g, '\n');
      ctx.touched = true;
      return Object.assign({ ok: true, action: 'set_full' }, excerpt());
    }
    // 只读工具（read_note / list_folder / list_folders）：复用页面内存数据
    var ro = runLocalTool({ name: name === 'list_folders' ? 'list_folder' : name, path: args.path, id: args.id });
    try { return JSON.parse(ro); } catch (e) { return { ok: false, error: 'tool_failed', raw: ro }; }
  }

  // 原生 tools schema（OpenAI 兼容多厂商；与 api/ai.php aiEditToolsSchema 一致）
  function editToolsSchema() {
    function fn(name, desc, props, required) {
      return { type: 'function', function: { name: name, description: desc,
        parameters: { type: 'object', properties: props || {}, required: required || [], additionalProperties: true } } };
    }
    return [
      fn('replace_text', '局部替换：old_string 必须逐字复制便签当前内容且唯一，不唯一就带上前一行或后一行。', { old_string: { type: 'string' }, new_string: { type: 'string', description: '替换后文字；留空表示删除该片段' } }, ['old_string', 'new_string']),
      fn('append_text', '追加到便签末尾，不改动已有内容。', { text: { type: 'string', description: '要追加的完整 Markdown' } }, ['text']),
      fn('write_note', '整篇重写便签内容（仅整篇翻译/整体重构）。', { content: { type: 'string', description: '完整新内容，严禁省略' } }, ['content']),
      fn('read_note', '读取指定便签的完整内容。', { id: { type: 'integer' } }, ['id']),
      fn('list_folders', '查看文件夹结构与其中的便签清单。', { path: { type: 'string', description: '如「工作/项目A」；根层级用「主页」' } }, []),
      fn('ask_user', '指令有歧义或缺信息时向用户提问（最多 3 个）。', { questions: { type: 'array', items: { type: 'string' } } }, ['questions']),
      fn('finish', '所有改动完成后调用，提交结果。', {}, [])
    ];
  }

  // tool_calls 分片归一：index → {id,name,arguments,raw_arguments}
  function normToolCalls(buf) {
    var out = [];
    Object.keys(buf || {}).sort(function (a, b) { return Number(a) - Number(b); }).forEach(function (k) {
      var t = buf[k];
      if (!t || !t.name) return;
      var args = {};
      try { args = JSON.parse(t.arguments || '{}'); } catch (e) { args = {}; }
      out.push({ id: t.id || ('call_' + k), name: t.name, arguments: args, raw_arguments: t.arguments || '{}' });
    });
    return out;
  }

  // 工具结果一句话摘要（工具行展示）
  function toolBrief(o) {
    if (!o || typeof o !== 'object') return '完成';
    if (o.error) {
      if (o.error === 'ambiguous') return '出现 ' + (o.count || 0) + ' 次，需更多上下文';
      if (o.error === 'not_found') return o.did_you_mean_line ? ('未找到，最接近第 ' + o.did_you_mean_line + ' 行') : '未找到匹配片段';
      if (o.error === 'empty_text' || o.error === 'empty_search') return '参数为空';
      if (o.error === 'note_not_found' || o.error === 'folder_not_found') return '目标不存在';
      return String(o.error);
    }
    if (o.action === 'append') {
      var ap = o.appended ? String(o.appended).replace(/\s+/g, ' ') : '';
      if (ap) return '已追加「' + (ap.length > 30 ? ap.slice(0, 30) + '…' : ap) + '」';
      return '已追加到末尾';
    }
    if (o.action === 'replace') return '已替换';
    if (o.action === 'set_full') return '已整篇写入';
    if (o.notes) return '读取到 ' + o.notes.length + ' 条便签';
    if (o.content !== undefined && o.id !== undefined) return '已读取便签';
    return '完成';
  }

  // 解析 <invoke name="x">…<parameter name="k">v</parameter>…</invoke>
  function parseInvokeParams(block) {
    var args = {}, m, re = /<parameter\s+name\s*=\s*"([^"]+)"\s*>([\s\S]*?)<\/parameter>/ig;
    while ((m = re.exec(String(block || ''))) !== null) args[m[1]] = m[2].trim();
    if (Object.keys(args).length) return args;
    try { var j = JSON.parse(String(block || '').trim()); return (j && typeof j === 'object') ? j : {}; } catch (e) { return {}; }
  }

  // 解析模型写在正文里的工具调用（v13.3）：
  // ① <tool_call>{"name":"x","arguments":{...}}</tool_call>（Qwen/GLM）
  // ② <tool_call><function=write_note>{json}</function></tool_call>（站长实测）
  // ③ <tool_call><invoke name="x"><parameter name="k">v</parameter></invoke></tool_call>（MiniMax 系）
  function parseTextToolCalls(text) {
    var t = String(text || ''), out = [];
    if (!t) return out;
    if (!/<(?:minimax:)?tool_call/i.test(t) && !/<function/i.test(t) && !/<invoke/i.test(t)) return out;
    var re = /<(?:minimax:)?tool_call[^>]*>([\s\S]*?)<\/(?:minimax:)?tool_call>/ig, m;
    while ((m = re.exec(t)) !== null) {
      var inner = m[1].trim();
      var fm = /<function\s*=\s*([A-Za-z0-9_]+)\s*>([\s\S]*?)<\/function>/i.exec(inner);
      if (fm) {
        var a1 = {}; try { a1 = JSON.parse(fm[2].trim()); } catch (e) { a1 = {}; }
        out.push({ name: fm[1], arguments: (a1 && typeof a1 === 'object') ? a1 : {} });
        continue;
      }
      var im = /<invoke[^>]*name\s*=\s*"([^"]+)"[^>]*>([\s\S]*?)<\/invoke>/i.exec(inner);
      if (im) { out.push({ name: im[1], arguments: parseInvokeParams(im[2]) }); continue; }
      try {
        var j = JSON.parse(inner);
        if (j && j.name) {
          var args;
          if (j.arguments && typeof j.arguments === 'object') args = j.arguments;
          else { args = {}; for (var k in j) if (k !== 'name') args[k] = j[k]; }
          out.push({ name: String(j.name), arguments: args });
        }
      } catch (e) {}
    }
    if (!out.length) {
      var re2 = /<function\s*=\s*([A-Za-z0-9_]+)\s*>([\s\S]*?)<\/function>/ig, m2;
      while ((m2 = re2.exec(t)) !== null) {
        var a2 = {}; try { a2 = JSON.parse(m2[2].trim()); } catch (e) { a2 = {}; }
        out.push({ name: m2[1], arguments: (a2 && typeof a2 === 'object') ? a2 : {} });
      }
    }
    return out;
  }

  // 末尾片段（详情展示用）
  function tailSnippet(tail, n) {
    n = n || 300;
    tail = String(tail || '');
    return tail.length > n ? '…' + tail.slice(-n) : tail;
  }

  // 工具输出的人类可读详情（工具卡片展开可见）
  function toolDetail(o, max) {
    max = max || 1200;
    if (!o || typeof o !== 'object') {
      var raw = String(o == null ? '' : o);
      return raw.length > max ? raw.slice(0, max) + '…' : raw;
    }
    if (o.error) {
      var L = ['错误：' + o.error];
      if (o.hint) L.push(o.hint);
      if (o.did_you_mean) L.push('最相似片段（第 ' + o.did_you_mean_line + ' 行）：' + '\n' + o.did_you_mean);
      return L.join('\n');
    }
    var tail = tailSnippet(o.current_tail, 300);
    if (o.action === 'append') return '追加的内容：' + '\n' + (o.appended || '') + '\n' + '—— 追加后的便签末尾 ——' + '\n' + tail;
    if (o.action === 'replace') return '已替换' + (o.matched ? '（匹配方式：' + o.matched + '）' : '') + '\n' + '—— 替换后的便签末尾 ——' + '\n' + tail;
    if (o.action === 'set_full') return '已整篇写入，共 ' + o.current_length + ' 字' + '\n' + '—— 末尾 ——' + '\n' + tail;
    var t;
    try { t = JSON.stringify(o, null, 2); } catch (e) { t = String(o); }
    if (t && t.length > max) t = t.slice(0, max) + '…';
    return t || '';
  }

  // 文本协议工具块解析
  function matchTextTool(text) {
    var m = /<<<TOOL>>>\s*([\s\S]*?)\s*<<<END>>>/i.exec(String(text || ''));
    if (!m) return null;
    var o = null;
    try { o = JSON.parse(m[1].trim()); } catch (e) { return null; }
    if (!o || !o.name) return null;
    return o;
  }

  // 字符级相似度（Dice 系数 ×100，够用于「最相似片段」提示）
  function similarity(a, b) {
    a = String(a); b = String(b);
    if (!a || !b) return 0;
    var set = {}, inter = 0, i, g;
    for (i = 0; i < a.length - 1; i++) { g = a.substr(i, 2); set[g] = (set[g] || 0) + 1; }
    for (i = 0; i < b.length - 1; i++) { g = b.substr(i, 2); if (set[g]) { inter++; set[g]--; } }
    var total = Math.max(1, (a.length - 1) + (b.length - 1));
    return Math.round(200 * inter / total);
  }

  // 失败回灌：原文中最相似片段 + 行号 + 相似度
  function bestMatchSnippet(work, search) {
    var sLines = String(search).split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
    if (!sLines.length) return null;
    var wLines = String(work).split('\n');
    var probe = sLines[0].slice(0, 40);
    var best = null;
    for (var i = 0; i < wLines.length; i++) {
      var line = wLines[i].trim();
      if (!line) continue;
      var sc = similarity(line, probe);
      if (sc >= 45 && (!best || sc > best.score)) best = { line: i + 1, score: sc };
    }
    if (!best) return null;
    var start = Math.max(0, best.line - 1);
    var end = Math.min(wLines.length, start + Math.max(1, sLines.length) + 1);
    best.excerpt = wLines.slice(start, end).join('\n');
    return best;
  }

  function parseClarify(text) {
    var qs = [];
    var m = /<<<CLARIFY>>>\s*\n([\s\S]*?)\n?<<<END>>>/i.exec(String(text || ''));
    if (m) {
      m[1].split('\n').forEach(function (line) {
        line = line.trim().replace(/^\s*[\d\-*.#)・•]+[.\s]*/, '').trim();
        if (!line) return;
        if (line.length > 200) line = line.slice(0, 200);
        if (qs.length >= 3) return;
        qs.push(line);
      });
    }
    return qs;
  }

  // ===== runLocalTool：用页面内存数据执行 TOOL 块（与 api/ai.php aiRunTool 逻辑一致） =====
  function runLocalTool(call) {
    var name = String(call.name || '');
    var notes = (typeof window !== 'undefined' && typeof window.__pixelNotesById === 'object') ? window.__pixelNotesById : null;
    var folders = (typeof window !== 'undefined' && typeof window.__pixelFoldersById === 'object') ? window.__pixelFoldersById : null;
    try {
      if (name === 'list_folder') {
        var path = String(call.path || '').trim();
        var targetFid = null;
        if (path && path !== '主页') {
          var segs = path.split('/').filter(function (s) { return s.trim() !== ''; });
          var parentId = null;
          for (var i = 0; i < segs.length; i++) {
            var found = null;
            Object.keys(folders || {}).forEach(function (k) {
              var f = folders[k];
              if ((f.parent_id || null) === parentId && f.name === segs[i]) found = f;
            });
            if (!found) return JSON.stringify({ error: 'folder_not_found', missing_segment: segs[i] });
            parentId = found.id;
          }
          targetFid = parentId;
        }
        var subFolders = Object.keys(folders || {}).map(function (k) { return folders[k]; })
          .filter(function (f) { return (f.parent_id || null) === targetFid; })
          .sort(function (a, b) { return a.sort_order - b.sort_order; })
          .slice(0, 50)
          .map(function (f) { return { id: f.id, name: f.name }; });
        var notesArr = Object.keys(notes || {}).map(function (k) { return notes[k]; })
          .filter(function (n) { return (n.folder_id || null) === targetFid; })
          .sort(function (a, b) { return (b.pinned - a.pinned) || (b.sort_order - a.sort_order); })
          .slice(0, 100)
          .map(function (n) { return { id: n.id, title: n.title || '', snippet: (n.content || '').slice(0, 80) }; });
        return JSON.stringify({ path: path || '主页', subfolders: subFolders, notes: notesArr });
      }
      if (name === 'read_note') {
        var nid = parseInt(call.id);
        if (isNaN(nid) || nid <= 0) return JSON.stringify({ error: 'invalid_id' });
        var note = (notes && notes[nid]) ? notes[nid] : null;
        if (!note) return JSON.stringify({ error: 'note_not_found' });
        return JSON.stringify({ id: nid, title: note.title || '', content: note.content || '' });
      }
      return JSON.stringify({ error: 'unknown_tool', name: name });
    } catch (e) {
      return JSON.stringify({ error: 'tool_failed', message: String(e) });
    }
  }

  // 澄清问答历史 → 对话轮次（assistant 提问块 + user 回答）
  function clarifyContext(rounds) {
    var out = [];
    (rounds || []).forEach(function (r) {
      out.push({ role: 'assistant', content: '<<<CLARIFY>>>\n' + r.q + '\n<<<END>>>' });
      out.push({ role: 'user', content: '回答：' + r.a });
    });
    return out;
  }

  // 澄清结果统一出口：轮数不限（每轮需用户手动回答，人工熔断）
  function clarifyResult(rounds, questions) {
    return { success: false, need_clarify: true, questions: questions, clarifyRounds: rounds };
  }

  // 与服务端一致的局部修改块解析与应用
  // 输出净化（protocol v4）：全文重写 / 整段重写路径专用，删除全部协议标记串后 trim；
  // 必须在澄清解析与替换块提取之后使用，不得提前
  function cleanOutput(text) {
    return String(text || '').replace(/<<<(?:SEARCH|REPLACE|END|CLARIFY)>>>/gi, '').replace(/<(?:think|thinking)>[\s\S]*?(?:<\/(?:think|thinking)>|$)/gi, '').trim();
  }

  // 宽容匹配辅助：空白集为 [ \t\r\n\f\v　]（ASCII 空白 + 全角空格），与 api/ai.php 逐字一致
  function foldWs(s) {
    return s.replace(/[ \t\r\n\f\v\u3000]+/g, ' ');
  }

  function rtrimLine(s) {
    return s.replace(/[ \t\u3000]+$/g, '');
  }

  function rangeOverlap(a, b) {
    return a[0] < b[1] && b[0] < a[1];
  }

  // 三级宽容匹配替换（protocol v8，与 api/ai.php aiApplyBlock 逐字一致）：
  // 1. 精确子串；2. 行尾空白归一；3. 全空白折叠。
  // v8 加固：① 三级全部要求「全文唯一有效命中」才应用（精确级不再取首次出现）；
  // ② 块隔离——doneRanges 是前序块 REPLACE 已覆盖区间，命中与之相交即无效。
  // 成功返回 { c: 新内容, old: [起,止), new: [起,止) }，失败返回 null。
  function matchAndApply(content, search, replace, doneRanges) {
    if (!search) return null;
    doneRanges = doneRanges || [];
    // 精确级：收集全部出现位置，过滤已替换区间，唯一才应用
    var exact = [];
    var p = content.indexOf(search);
    while (p !== -1) { exact.push(p); p = content.indexOf(search, p + 1); }
    var valid = [];
    for (var ei = 0; ei < exact.length; ei++) {
      var rng = [exact[ei], exact[ei] + search.length];
      var blocked = false;
      for (var di = 0; di < doneRanges.length; di++) { if (rangeOverlap(rng, doneRanges[di])) { blocked = true; break; } }
      if (!blocked) valid.push(rng);
    }
    if (valid.length === 1) {
      var a = valid[0][0], b = valid[0][1];
      return { c: content.slice(0, a) + replace + content.slice(b), old: [a, b], new: [a, a + replace.length] };
    }
    var lines = content.split('\n');
    // 行号 → 偏移前缀和（滑窗命中换算回字符区间，用于块隔离过滤）
    var lineOff = [];
    var off = 0;
    for (var li = 0; li < lines.length; li++) { lineOff[li] = off; off += lines[li].length + 1; }
    var keyFns = [
      function (s) { return s.split('\n').map(rtrimLine).join('\n'); },
      function (s) { return foldWs(s).replace(/^ | $/g, ''); }
    ];
    for (var ki = 0; ki < keyFns.length; ki++) {
      var kf = keyFns[ki];
      var needleKey = kf(search);
      if (!needleKey) continue;
      var hits = [];
      for (var i = 0; i < lines.length; i++) {
        var acc = '';
        for (var j = i; j < lines.length; j++) {
          acc += (j > i ? '\n' : '') + lines[j];
          var k = kf(acc);
          if (k === needleKey) {
            var rng2 = [lineOff[i], j + 1 < lines.length ? lineOff[j + 1] : content.length];
            var blocked2 = false;
            for (var dj = 0; dj < doneRanges.length; dj++) { if (rangeOverlap(rng2, doneRanges[dj])) { blocked2 = true; break; } }
            if (!blocked2) hits.push([i, j, rng2]);
            break;
          }
          if (k.length > needleKey.length) break;
        }
      }
      if (hits.length === 1) {
        var h = hits[0];
        var seg = lines.slice(0, h[0]);
        seg.push(replace);
        var newC = seg.concat(lines.slice(h[1] + 1)).join('\n');
        return { c: newC, old: h[2], new: [h[2][0], h[2][0] + replace.length] };
      }
    }
    return null;
  }

  // 顺序应用多个替换块（protocol v8 块隔离）：
  // 维护已替换区间列表，每次替换后把位于替换点之后的旧区间按长度差平移，
  // 后续块的 SEARCH 只在「未被前序块改动的原文区域」定位。
  function applyBlocks(text, originalContent) {
    var re = /<<<SEARCH>>>\s*\n([\s\S]*?)\n?<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/ig;
    var m = null;
    var applied = 0;
    var failed = 0;
    var bad = [];
    var result = originalContent.replace(/\r\n/g, '\n');
    var done = [];
    while ((m = re.exec(text)) !== null) {
      var search = m[1].replace(/\r\n/g, '\n').replace(/\n+$/, '');
      var replace = m[2].replace(/\r\n/g, '\n').replace(/\n+$/, '');
      var res;
      if (/<<<SKIP>>>/i.test(search)) res = matchSkipApply(result, search, replace, done);   // v9：SKIP 锚块
      else res = matchAndApply(result, search, replace, done);
      if (res !== null) {
        result = res.c;
        var delta = (res.new[1] - res.new[0]) - (res.old[1] - res.old[0]);
        for (var ri = 0; ri < done.length; ri++) {
          if (done[ri][0] >= res.old[1]) { done[ri][0] += delta; done[ri][1] += delta; }
        }
        done.push(res.new);
        applied++;
      } else {
        failed++;
        bad.push(search);
      }
    }
    return { result: result, applied: applied, failed: failed, bad: bad, hasBlocks: applied + failed > 0 };
  }

  // SKIP 锚匹配替换（protocol v9，与 api/ai.php aiApplySkipBlock 逐字一致）：
  // SEARCH 含一个 <<<SKIP>>>，切首锚/尾锚；定位「首锚起点、尾锚终点」成对跨度，整体替换为 REPLACE。
  // L1 首尾锚精确子串成对唯一；L2 首锚首行+尾锚末行行尾空白归一成对唯一；块隔离（doneRanges）沿用。
  function matchSkipApply(content, search, replace, doneRanges) {
    doneRanges = doneRanges || [];
    var parts = String(search).split(/<<<SKIP>>>/i);
    if (parts.length !== 2) return null;
    var head = parts[0].replace(/\n+$/, '');
    var tail = parts[1].replace(/^\n+/, '');
    if (head === '' && tail === '') return null;
    function blocked(a, b) {
      for (var di = 0; di < doneRanges.length; di++) {
        if (a < doneRanges[di][1] && doneRanges[di][0] < b) return true;
      }
      return false;
    }
    // L1：精确子串成对（头锚起点 × 其后的尾锚终点），全组合唯一才应用
    var heads = [];
    if (head === '') heads.push(0);
    else { var p0 = content.indexOf(head); while (p0 !== -1) { heads.push(p0); p0 = content.indexOf(head, p0 + 1); } }
    var valid = [];
    for (var hi = 0; hi < heads.length; hi++) {
      var h = heads[hi];
      if (tail === '') { if (!blocked(h, content.length)) valid.push([h, content.length]); continue; }
      var q = h + head.length;
      var t = content.indexOf(tail, q);
      while (t !== -1) {
        var end = t + tail.length;
        if (!blocked(h, end)) valid.push([h, end]);
        q = t + 1;
        t = content.indexOf(tail, q);
      }
    }
    if (valid.length === 1) {
      var a = valid[0][0], b = valid[0][1];
      return { c: content.slice(0, a) + replace + content.slice(b), old: [a, b], new: [a, a + replace.length] };
    }
    // L2：行锚——首锚首行 / 尾锚末行（行尾空白归一），行对唯一才应用
    if (head === '' || tail === '') return null;
    var hLine = rtrimLine(head.split('\n')[0]);
    var tLines = tail.split('\n');
    var tLine = rtrimLine(tLines[tLines.length - 1]);
    if (!hLine || !tLine) return null;
    var lines = content.split('\n');
    var starts = [], ends = [];
    for (var i = 0; i < lines.length; i++) {
      var rl = rtrimLine(lines[i]);
      if (rl === hLine) starts.push(i);
      if (rl === tLine) ends.push(i);
    }
    if (!starts.length || !ends.length) return null;
    var lineOff = [], off = 0;
    for (var li = 0; li < lines.length; li++) { lineOff[li] = off; off += lines[li].length + 1; }
    var valid2 = [];
    for (var si = 0; si < starts.length; si++) {
      for (var ei = 0; ei < ends.length; ei++) {
        var i2 = starts[si], j2 = ends[ei];
        if (j2 < i2) continue;
        var a2 = lineOff[i2];
        var b2 = (j2 + 1 < lines.length) ? lineOff[j2 + 1] : content.length;
        if (!blocked(a2, b2)) valid2.push([a2, b2]);
      }
    }
    if (valid2.length === 1) {
      var a3 = valid2[0][0], b3 = valid2[0][1];
      return { c: content.slice(0, a3) + replace + content.slice(b3), old: [a3, b3], new: [a3, a3 + replace.length] };
    }
    return null;
  }

  // 单轮请求：返回 { ok, text, message }（ok=false 时 message 为错误说明）
  // onDelta 提供时走流式（stream:true），逐 token 回调；上游不支持流式时自动降级为整段返回（结果不变）
  // 单轮请求：返回 { ok, text, tool_calls, aborted, toolsRejected, message }
  // 传 tools 走原生 function calling；onDelta 存在时走流式；signal 供上层「停止」；
  // onThink 接收推理模型（reasoning_content / reasoning）的思考增量，仅展示、不影响正文与判定。
  async function callOnce(proxy, target, apiKey, model, messages, extra, onDelta, tools, signal, onThink) {
    var payload = { model: model, messages: messages, max_tokens: 16000, temperature: 0.1 };
    if (extra) {
      for (var k in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, k) && k !== 'model' && k !== 'messages') payload[k] = extra[k];
      }
    }
    if (tools && tools.length) { payload.tools = tools; payload.tool_choice = 'auto'; }
    if (onDelta) payload.stream = true;

    // 空闲看门狗（90 秒无字节 → 中断）；用户点「停止」时也走同一 controller
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var idleTimer = null, idleFired = false, userAborted = false;
    if (signal) {
      if (signal.aborted) { userAborted = true; if (ctrl) ctrl.abort(); }
      else if (signal.addEventListener) signal.addEventListener('abort', function () { userAborted = true; if (ctrl) { try { ctrl.abort(); } catch (e4) {} } });
    }
    function armIdle() {
      if (!ctrl) return;
      if (idleTimer) clearTimeout(idleTimer);
      idleTimer = setTimeout(function () { idleFired = true; try { ctrl.abort(); } catch (e2) {} }, 90000);
    }
    function disarmIdle() { if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; } }

    var tcBuf = {};
    function feedToolDelta(list) {
      list.forEach(function (d) {
        var idx = (d.index === undefined || d.index === null) ? 0 : Number(d.index);
        if (!tcBuf[idx]) tcBuf[idx] = { id: '', name: '', arguments: '' };
        if (d.id) tcBuf[idx].id = d.id;
        if (d.function) {
          if (d.function.name) tcBuf[idx].name += d.function.name;
          if (d.function.arguments) tcBuf[idx].arguments += d.function.arguments;
        }
      });
    }

    var resp;
    try {
      armIdle();
      resp = await fetch(proxy + '/' + target, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + apiKey
        },
        body: JSON.stringify(utf16SanitizeDeep(payload, 0)),
        signal: ctrl ? ctrl.signal : undefined
      });
    } catch (e) {
      disarmIdle();
      if (userAborted) return { ok: false, aborted: true, message: '已停止生成' };
      if (idleFired) return { ok: false, message: '上游连续 90 秒没有任何响应，已中断（可重试，或检查透明代理/模型服务状态）' };
      return { ok: false, message: '无法连接你的透明代理（检查地址是否正确、Worker 是否已部署）' };
    }
    if (!onDelta) disarmIdle();

    // 流式：逐块解析上游 SSE，提取 delta.content 与 delta.tool_calls
    if (onDelta && resp.ok && resp.body && typeof resp.body.getReader === 'function') {
      try {
        var reader = resp.body.getReader();
        var dec = new TextDecoder('utf-8');
        var sseBuf = '';
        var raw = '';
        var text = '';
        var sawStream = false;
        while (true) {
          var rd = await reader.read();
          if (rd.done) break;
          var chunk = dec.decode(rd.value, { stream: true });
          if (!chunk) continue;
          armIdle();
          raw += chunk;
          sseBuf += chunk;
          var nl;
          while ((nl = sseBuf.indexOf('\n')) !== -1) {
            var line = sseBuf.slice(0, nl);
            sseBuf = sseBuf.slice(nl + 1);
            line = line.replace(/\r$/, '');
            if (line.indexOf('data:') !== 0) continue;
            var d = line.slice(5).trim();
            if (!d || d === '[DONE]') continue;
            var jj = null;
            try { jj = JSON.parse(d); } catch (e) { jj = null; }
            if (!jj || !jj.choices || !jj.choices[0]) continue;
            var dd = jj.choices[0].delta || {};
            if (dd.tool_calls && dd.tool_calls.length) { feedToolDelta(dd.tool_calls); sawStream = true; }
            // 推理模型的思考增量：单独回调给「深度思考」折叠卡（不算正文，不影响 sawStream 判定）
            var rdelta = '';
            if (typeof dd.reasoning_content === 'string') rdelta = dd.reasoning_content;
            else if (typeof dd.reasoning === 'string') rdelta = dd.reasoning;
            if (rdelta && onThink) onThink(rdelta);
            var delta = '';
            if (typeof dd.content === 'string') delta = dd.content;
            else if (typeof jj.choices[0].text === 'string') delta = jj.choices[0].text;
            if (delta) { text += delta; sawStream = true; onDelta(delta); }
          }
        }
        if (sawStream) { disarmIdle(); return { ok: true, text: text, tool_calls: normToolCalls(tcBuf) }; }
        // 收到 200 但没有任何增量：上游不支持流式（整段 JSON），往下按整段解析
        if (raw.indexOf('"reasoning_content"') !== -1) {
          return { ok: false, message: '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens' };
        }
        var jsonFb = null;
        try { jsonFb = JSON.parse(raw); } catch (e) { jsonFb = null; }
        if (jsonFb && jsonFb.choices && jsonFb.choices[0]) {
          var moFb = jsonFb.choices[0].message || {};
          var ntcFb = Array.isArray(moFb.tool_calls) ? moFb.tool_calls.map(function (t) {
            var f = t.function || {}; var a = {}; try { a = JSON.parse(f.arguments || '{}'); } catch (e) { a = {}; }
            return { id: t.id || '', name: f.name || '', arguments: a, raw_arguments: f.arguments || '{}' };
          }).filter(function (t) { return t.name; }) : [];
          var tFb = String(moFb.content || '').trim();
          if (tFb) return { ok: true, text: tFb, tool_calls: ntcFb };
          if (ntcFb.length) return { ok: true, text: '', tool_calls: ntcFb };
          if (moFb.reasoning_content) return { ok: false, message: '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens' };
          if (jsonFb.choices[0].text) return { ok: true, text: String(jsonFb.choices[0].text).trim() };
        }
        return { ok: false, message: 'AI 返回了空内容', empty: true };
      } catch (e) {
        disarmIdle();
        if (userAborted) return { ok: false, aborted: true, message: '已停止生成' };
        if (idleFired) return { ok: false, message: '上游连续 90 秒没有任何响应，已中断（可重试，或检查透明代理/模型服务状态）' };
        return { ok: false, message: '读取流式响应失败：' + String(e.message || e) };
      }
    }

    var raw = '';
    try { raw = await resp.text(); }
    catch (e) { return { ok: false, message: '读取响应失败（HTTP ' + resp.status + '）' }; }

    var json = null;
    try { json = JSON.parse(raw); } catch (e) { json = null; }

    if (!resp.ok) {
      var msg = 'HTTP ' + resp.status;
      if (json && json.error && json.error.message) msg = String(json.error.message).slice(0, 200);
      else if (raw) msg += '：' + raw.slice(0, 150);
      var rejected = !!(tools && tools.length && (resp.status === 400 || resp.status === 422 || /tool/i.test(msg)));
      return { ok: false, message: '上游错误：' + msg, toolsRejected: rejected };
    }
    if (!json || !json.choices || !json.choices[0]) {
      return { ok: false, message: '响应格式异常：' + raw.slice(0, 150) };
    }

    var mo = json.choices[0].message || {};
    var ntc = Array.isArray(mo.tool_calls) ? mo.tool_calls.map(function (t) {
      var f = t.function || {}; var a = {}; try { a = JSON.parse(f.arguments || '{}'); } catch (e) { a = {}; }
      return { id: t.id || '', name: f.name || '', arguments: a, raw_arguments: f.arguments || '{}' };
    }).filter(function (t) { return t.name; }) : [];
    var text = String(mo.content || '').trim();
    if (!text && ntc.length) return { ok: true, text: '', tool_calls: ntc };
    if (!text && mo.reasoning_content) {
      return { ok: false, message: '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens' };
    }
    if (!text && json.choices[0].text) text = String(json.choices[0].text).trim();
    if (!text) return { ok: false, message: 'AI 返回了空内容', empty: true };
    return { ok: true, text: text, tool_calls: ntc };
  }

  async function edit(opts) {
    var target = normalizeEndpoint(opts.baseUrl);
    var proxy = proxyUrl(opts.proxy);
    var apiKey = String(opts.apiKey || '').trim();
    var model = String(opts.model || '').trim();
    // 澄清问答历史（轮数不限，最多保留 10 轮防滥用，与服务端一致）
    var clarifyRounds = Array.isArray(opts.clarifyRounds)
      ? opts.clarifyRounds.slice(0, 10).map(function (r) {
          return { q: String(r.q || '').slice(0, 200), a: String(r.a || '').slice(0, 500) };
        })
      : [];

    if (!proxy) return { success: false, message: '透明代理地址无效' };
    if (!target) return { success: false, message: '接口地址无效' };
    if (!apiKey || !model) return { success: false, message: '缺少 API Key 或模型名' };

    // 自定义请求体参数：深度思考预设 + 用户自定义 Body（同名时自定义优先）
    var extra = {};
    if (opts.deepThink) extra.enable_thinking = true;
    if (opts.bodyEnabled) {
      var bKey = String(opts.bodyKey || '').trim();
      var bJson = String(opts.bodyJson || '').trim();
      if (!/^[A-Za-z_][A-Za-z0-9_.\-]{0,63}$/.test(bKey)) {
        return { success: false, message: '自定义 Body Key 格式无效（字母开头，可含数字/下划线/点/横线）' };
      }
      var bVal;
      try { bVal = JSON.parse(bJson); } catch (e) {
        return { success: false, message: '自定义 Body JSON 不是合法 JSON（如 true / "high" / {"type":"enabled"}）' };
      }
      extra[bKey] = bVal;
    }

    var messages = [{ role: 'system', content: buildSystemPrompt(opts.style, opts.now) }];
    // 多轮对话历史：放在本轮指令之前（已完成的改动不要重复执行）
    var history = [];
    if (Array.isArray(opts.history)) {
      opts.history.slice(-12).forEach(function (h) {
        if (!h || typeof h.content !== 'string' || !h.content.trim()) return;
        history.push({ role: h.role === 'assistant' ? 'assistant' : 'user', content: utf16Sanitize(h.content.slice(0, 600)) });
      });
    }
    if (history.length) {
      messages.push({ role: 'user', content: '【本会话此前的编辑往来（背景参考，已完成的改动不要重复执行）】' });
      history.forEach(function (m) { messages.push(m); });
    }
    messages.push({
      role: 'user',
      content: '【便签标题】' + (opts.title || '(无标题)') + '\n'
        + '【当前便签内容】\n' + (opts.content ? opts.content : '(空便签)') + '\n\n'
        + '【编辑指令】' + opts.instruction
    });
    // 注入澄清问答历史（若有），AI 见过前文不再重复提问
    clarifyContext(clarifyRounds).forEach(function (m) { messages.push(m); });

    // ===== 长文分段 agent 模式：与服务器端同策略（>4500 字切块逐段处理） =====
    var CHUNK_THRESHOLD = 4500, CHUNK_SIZE = 3000;
    var contentN = String(opts.content || '').replace(/\r\n/g, '\n');
    if (contentN.length > CHUNK_THRESHOLD) {
      var chunks = chunkText(contentN, CHUNK_SIZE);
      var n = chunks.length;
      var outline = '';
      for (var ci = 0; ci < n; ci++) {
        var first = (chunks[ci].split('\n')[0] || chunks[ci]).trim().slice(0, 24);
        outline += (ci + 1) + '. ' + first + '\n';
      }
      var segSystem = buildSystemPrompt(opts.style, opts.now)
        + '\n【分段模式】这是一篇长文，已分 ' + n + ' 段，你只处理「本段内容」这一个段。SEARCH 段必须逐字复制自「本段内容」。若本段完全无需修改，只输出四个字：本段无需修改。本段模式禁止调用任何工具，请直接输出 A（替换块）/B（整段新内容）/C（澄清）文本格式。';
      var newContent = contentN, applied = 0, failed = 0;
      for (var ci = 0; ci < n; ci++) {
        if (opts.onPhase) opts.onPhase('🧩 长文分段：第 ' + (ci + 1) + '/' + n + ' 段…');
        var segMsgs = [
          { role: 'system', content: segSystem },
          {
            role: 'user',
            content: '【便签标题】' + (opts.title || '(无标题)') + '\n'
              + '【全文结构（共' + n + '段，你处理第 ' + (ci + 1) + ' 段）】\n' + outline
              + '【本段内容】\n' + (chunks[ci] || '(空)') + '\n\n'
              + '【编辑指令】' + opts.instruction
          }
        ];
        // 注入澄清问答历史（若有）
        clarifyContext(clarifyRounds).forEach(function (m) { segMsgs.push(m); });
        for (var att = 1; att <= 2; att++) {
          var r = await callOnce(proxy, target, apiKey, model, segMsgs, extra, opts.onDelta, null, opts.signal, opts.onThink);
          if (r.aborted) return { success: false, aborted: true, message: '已停止生成' };
          if (!r.ok && !r.empty) return { success: false, message: '第 ' + (ci + 1) + ' 段处理失败：' + r.message };
          var text = r.text || '';
          var fence = text.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i);
          if (fence) text = fence[1].trim();
          // 澄清提问：拿不准时向用户提问（轮数不限）
          var clarify = parseClarify(text);
          if (clarify.length) return clarifyResult(clarifyRounds, clarify);
          if (!text) {
            if (att < 2) {
              segMsgs.push({ role: 'assistant', content: '（上一轮返回为空）' });
              segMsgs.push({ role: 'user', content: '你上一轮返回了空内容，请重新处理本段。' });
              continue;
            }
            failed++;
            break;
          }
          if (!/<<<SEARCH>>>/i.test(text) && /无需修改|没有需要|不涉及修改|不用修改/.test(text)) break;
          var b = applyBlocks(text, newContent);
          if (b.hasBlocks) {
            if (b.applied > 0) {
              newContent = b.result;
              applied += b.applied;
              failed += b.failed;
              break;
            }
            if (att < 2) {
              segMsgs.push({ role: 'assistant', content: text });
              segMsgs.push({ role: 'user', content: '你输出的替换块无法在「本段内容」中精确匹配。SEARCH 段必须逐字复制本段原文（含空格、换行、标点），请重新输出。' });
              continue;
            }
            failed += b.failed;
          } else {
            // 无替换块：视输出为本段整体重写
            var pos = newContent.indexOf(chunks[ci]);
            if (pos !== -1) {
              newContent = newContent.slice(0, pos) + cleanOutput(text) + newContent.slice(pos + chunks[ci].length);
              applied++;
            } else {
              failed++;
            }
            break;
          }
        }
      }
      return { success: true, mode: 'edits', applied: applied, failed: failed, content: newContent, chunked: true, chunks: n, attempts: 1 };
    }

    // 自纠错循环：工具轮不消耗重试预算；非工具失败（空内容/锚点不匹配）最多 3 轮
    var maxAttempts = 3;
    var ctx = { work: String(opts.content || '').replace(/\r\n/g, '\n'), touched: false };   // v12 工作副本
    var tools = editToolsSchema();
    var nativeTools = true;     // 上游拒绝 tools 时自动降级为文本协议
    var toolRounds = 0;
    var loopGuard = 0;
    var noToolViolations = 0;   // 只回文字不调工具的次数（上限 3，超过即报错）
    var text = '';
    for (var attempt = 1; attempt <= maxAttempts; attempt++) {
      loopGuard++;
      if (loopGuard > 14) break;
      if (opts.onPhase) opts.onPhase(attempt > 1 ? '🔁 自动纠错第 ' + (attempt - 1) + ' 次…' : '🤖 正在生成…');
      var r = await callOnce(proxy, target, apiKey, model, messages, extra, opts.onDelta,
                             nativeTools ? tools : null, opts.signal, opts.onThink);
      // 只有错误明确指向 tools 参数能力才降级；旧 /tool/i 过宽——瞬时错误里带 "tool" 就误判为不支持
      if (!r.ok && nativeTools && (r.toolsRejected || /tool_choice|tools|tool[\s_-]?use|function[\s_-]?call|工具调用|不支持工具|invalid.{0,24}(parameter|schema|properties)|tool\s*\d+\s*function|is not of type/i.test(String(r.message || '')))) {
        nativeTools = false;
        if (opts.onPhase) opts.onPhase('ℹ️ 该模型不支持原生工具调用，切换文本协议');
        messages.push({ role: 'user', content: '【系统】当前上游不支持原生工具调用，请改用文本协议输出（<<<TOOL>>>{json}<<<END>>>）。' });
        r = await callOnce(proxy, target, apiKey, model, messages, extra, opts.onDelta, null, opts.signal, opts.onThink);
      }
      if (r.aborted) return { success: false, aborted: true, message: '已停止生成' };
      if (!r.ok && !r.empty) return { success: false, message: r.message };

      text = r.text || '';
      var fence = text.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i);
      if (fence) text = fence[1].trim();

      // 澄清提问：拿不准就继续问，轮数不限（每轮需用户手动回答，人工熔断）
      var clarify = parseClarify(text);
      if (clarify.length) return clarifyResult(clarifyRounds, clarify);

      // ===== 原生 tool_calls（OpenAI 兼容多厂商：MiniMax/GLM/Kimi/DeepSeek/Gemini 兼容端点）=====
      if (r.tool_calls && r.tool_calls.length) {
        var asstCalls = [], toolMsgs = [];
        for (var ti = 0; ti < r.tool_calls.length; ti++) {
          if (toolRounds >= 8) break;
          toolRounds++;
          var tc = r.tool_calls[ti];
          var tName = String(tc.name || '');
          var tid = 't' + toolRounds;
          if (opts.onTool) opts.onTool({ id: tid, name: tName, label: editToolLabel(tName), round: toolRounds });
          if (opts.onPhase) opts.onPhase('🔧 ' + editToolLabel(tName) + '…');
          asstCalls.push({ id: tc.id, type: 'function',
                           function: { name: tName, arguments: tc.raw_arguments || JSON.stringify(tc.arguments || {}) } });
          if (tName === 'finish') {
            if (opts.onToolResult) opts.onToolResult({ id: tid, name: tName, ok: true, brief: '提交改动' });
            return { success: true, mode: 'full', agent: true, content: ctx.work, attempts: attempt };
          }
          if (tName === 'ask_user') {
            var qsN = (tc.arguments && Array.isArray(tc.arguments.questions))
              ? tc.arguments.questions.map(function (q) { return String(q).trim(); }).filter(Boolean).slice(0, 3) : [];
            if (qsN.length) {
              if (opts.onToolResult) opts.onToolResult({ id: tid, name: tName, ok: true, brief: '向用户提问 ' + qsN.length + ' 个问题' });
              return clarifyResult(clarifyRounds, qsN);
            }
            if (opts.onToolResult) opts.onToolResult({ id: tid, name: tName, ok: false, brief: '问题为空' });
            toolMsgs.push({ role: 'tool', tool_call_id: tc.id, content: JSON.stringify({ ok: false, error: 'empty_questions' }) });
            continue;
          }
          var out = editToolExec(tName, tc.arguments || {}, ctx);
          var outStr = typeof out === 'string' ? out : JSON.stringify(out);
          var outObj = null; try { outObj = JSON.parse(outStr); } catch (e2) { outObj = null; }
          var okFlag = outObj ? (outObj.ok === undefined ? !outObj.error : !!outObj.ok) : true;
          if (opts.onToolResult) opts.onToolResult({ id: tid, name: tName, ok: okFlag, brief: toolBrief(outObj), detail: toolDetail(outObj) });
          toolMsgs.push({ role: 'tool', tool_call_id: tc.id, content: outStr });
        }
        messages.push({ role: 'assistant', content: text !== '' ? text : null, tool_calls: asstCalls });
        for (var mi = 0; mi < toolMsgs.length; mi++) messages.push(toolMsgs[mi]);
        attempt--;   // 工具轮不消耗重试预算（loopGuard 兜底防死循环）
        continue;
      }

      // ===== 文本内嵌工具调用（v13.3：模型按训练格式把调用写在正文里，端点没映射成原生 tool_calls）=====
      var tTools = parseTextToolCalls(text);
      if (tTools.length && toolRounds < 8) {
        var fedBack = '';
        for (var xi = 0; xi < tTools.length; xi++) {
          if (toolRounds >= 8) break;
          toolRounds++;
          var tcn = tTools[xi];
          var xName = String(tcn.name || '');
          var xArgs = tcn.arguments || {};
          var xid = 't' + toolRounds;
          if (opts.onTool) opts.onTool({ id: xid, name: xName, label: editToolLabel(xName), round: toolRounds });
          if (opts.onPhase) opts.onPhase('🔧 ' + editToolLabel(xName) + '…');
          if (xName === 'finish') {
            if (opts.onToolResult) opts.onToolResult({ id: xid, name: xName, ok: true, brief: '提交改动' });
            return { success: true, mode: 'full', agent: true, content: ctx.work, attempts: attempt };
          }
          if (xName === 'ask_user') {
            var xQs = Array.isArray(xArgs.questions) ? xArgs.questions.map(function (q) { return String(q).trim(); }).filter(Boolean).slice(0, 3) : [];
            if (xQs.length) {
              if (opts.onToolResult) opts.onToolResult({ id: xid, name: xName, ok: true, brief: '向用户提问 ' + xQs.length + ' 个问题' });
              return clarifyResult(clarifyRounds, xQs);
            }
            if (opts.onToolResult) opts.onToolResult({ id: xid, name: xName, ok: false, brief: '问题为空' });
            fedBack += '【工具结果】ask_user' + '\n' + JSON.stringify({ ok: false, error: 'empty_questions' }) + '\n\n';
            continue;
          }
          var xOut = editToolExec(xName, xArgs, ctx);
          var xStr = typeof xOut === 'string' ? xOut : JSON.stringify(xOut);
          var xObj = null; try { xObj = JSON.parse(xStr); } catch (xe2) { xObj = null; }
          var xOk = xObj ? (xObj.ok === undefined ? !xObj.error : !!xObj.ok) : true;
          if (opts.onToolResult) opts.onToolResult({ id: xid, name: xName, ok: xOk, brief: toolBrief(xObj), detail: toolDetail(xObj) });
          fedBack += '【工具结果】' + xName + '\n' + xStr + '\n\n';
        }
        // 正文里的调用已执行（散文部分不写入便签）；回喂结果让模型继续
        messages.push({ role: 'assistant', content: text });
        messages.push({ role: 'user', content: fedBack.replace(/\s+$/, '')
          + '\n已执行的改动已生效（正文文字不会写入便签）。还有未完成的改动就继续调用工具，全部完成则调用 finish。' });
        attempt--;
        continue;
      }

      // ===== 文本协议 TOOL 块（上游不支持原生 tools 时的兜底）=====
      var tt = matchTextTool(text);
      if (tt && toolRounds < 8) {
        toolRounds++;
        var ttName = String(tt.name);
        var tid2 = 't' + toolRounds;
        if (opts.onTool) opts.onTool({ id: tid2, name: ttName, label: editToolLabel(ttName), round: toolRounds });
        if (opts.onPhase) opts.onPhase('🔧 ' + editToolLabel(ttName) + '…');
        if (ttName === 'finish') {
          if (opts.onToolResult) opts.onToolResult({ id: tid2, name: ttName, ok: true, brief: '提交改动' });
          return { success: true, mode: 'full', agent: true, content: ctx.work, attempts: attempt };
        }
        if (ttName === 'ask_user') {
          var qsT = Array.isArray(tt.questions)
            ? tt.questions.map(function (q) { return String(q).trim(); }).filter(Boolean).slice(0, 3) : [];
          if (qsT.length) {
            if (opts.onToolResult) opts.onToolResult({ id: tid2, name: ttName, ok: true, brief: '向用户提问 ' + qsT.length + ' 个问题' });
            return clarifyResult(clarifyRounds, qsT);
          }
        }
        var outT = editToolExec(ttName, tt, ctx);
        var outTStr = typeof outT === 'string' ? outT : JSON.stringify(outT);
        var outTObj = null; try { outTObj = JSON.parse(outTStr); } catch (e3) { outTObj = null; }
        if (opts.onToolResult) opts.onToolResult({ id: tid2, name: ttName, ok: !(outTObj && outTObj.error), brief: toolBrief(outTObj), detail: toolDetail(outTObj) });
        messages.push({ role: 'assistant', content: text });
        messages.push({ role: 'user', content: '【工具结果】' + ttName + '\n' + outTStr });
        attempt--;
        continue;
      }

      if (!text) {
        // 空内容：带上下文重试
        if (attempt < maxAttempts) {
          messages.push({ role: 'assistant', content: '（上一轮返回为空）' });
          messages.push({ role: 'user', content: '你上一轮返回了空内容，请重新按格式输出编辑结果。' });
          continue;
        }
        return { success: false, message: r.message || 'AI 返回了空内容' };
      }

      var b = applyBlocks(text, ctx.work);
      if (b.hasBlocks) {
        if (b.applied > 0) {
          ctx.work = b.result; ctx.touched = true;
          return { success: true, mode: 'edits', applied: b.applied, failed: b.failed, content: b.result, attempts: attempt };
        }
        // 空便签兜底（protocol v3）：空便签没有原文可匹配，误用 A 时把全部 REPLACE 段拼成新全文（视同 B）
        if (!String(ctx.work || '').trim()) {
          var rebuilt = '';
          var reRep = /<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/ig;
          var rm;
          while ((rm = reRep.exec(text)) !== null) {
            var part = rm[1].replace(/\r\n/g, '\n').replace(/\n+$/, '');
            if (part.trim()) rebuilt += (rebuilt ? '\n\n' : '') + part;
          }
          if (rebuilt.trim()) return { success: true, mode: 'full', content: rebuilt, attempts: attempt };
        }
        // 全部匹配失败：带上下文重试
        if (attempt < maxAttempts) {
          var fb = '你上一轮输出的替换块全部无法匹配原文（共 ' + b.failed + ' 个）。'
            + 'SEARCH 段必须从【当前便签内容】中逐字精确复制（包括空格、换行、标点、Markdown 符号），禁止凭记忆复述。';
          if (b.bad && b.bad.length) {
            var preview = b.bad.slice(0, 3).map(function (s) {
              return '「' + String(s).trim().replace(/\s+/g, ' ').slice(0, 20) + '…」';
            }).join(' / ');
            fb += '你上轮的 SEARCH 段开头分别是：' + preview;
          }
          fb += '请重新输出替换块完成原指令：' + opts.instruction;
          messages.push({ role: 'assistant', content: text });
          messages.push({ role: 'user', content: fb });
          continue;
        }
        return { success: false, message: 'AI 指出的修改位置无法在原文中匹配，已自动重试 ' + maxAttempts + ' 轮仍失败，请重试或换个说法' };
      }

      // 无工具调用、无替换块：按「协议违规」处理（绝不把聊天文字当便签正文）
      if (ctx.touched) {
        return { success: true, mode: 'full', agent: true, content: ctx.work, attempts: attempt };
      }
      // 正文里还残留工具调用语法 → 解析失败，绝不能写进便签（哪怕便签为空）
      if (/<tool_call|<function\s*=|<invoke|<<<TOOL/i.test(text)) {
        noToolViolations++;
        if (noToolViolations <= 3) {
          if (opts.onPhase) opts.onPhase('⚠️ 输出里混入了工具调用语法，已要求重新输出');
          messages.push({ role: 'assistant', content: text });
          messages.push({ role: 'user', content: '【系统·格式错误】你的输出里混有工具调用语法标记（如 <tool_call>、<function=>）。系统无法解析它们，它们也绝不能出现在便签正文里。请重新输出：直接调用工具，或只输出纯正文内容，不要把工具调用写进正文。' });
          attempt--;
          continue;
        }
        return { success: false, message: '模型输出的工具调用格式无法解析（已重试 ' + noToolViolations + ' 次），请重试' };
      }
      if (!String(opts.content || '').trim()) {
        return { success: true, mode: 'full', content: cleanOutput(text), attempts: attempt };
      }
      noToolViolations++;
      if (noToolViolations <= 3) {
        if (opts.onPhase) opts.onPhase('⚠️ 模型只回了文字，已要求它改用工具（第 ' + noToolViolations + ' 次）');
        messages.push({ role: 'assistant', content: text });
        messages.push({ role: 'user', content: '【系统·协议违规】你没有调用任何工具，只输出了文字。便签编辑必须通过工具落地：replace_text（局部替换；new_string 留空=删除）、append_text（末尾追加）、write_note（整篇重写）、finish（提交）。禁止回答用户、禁止复述便签内容、禁止寒暄。请立刻调用合适的工具完成这条指令；即使认为无需改动，也必须调用 finish 交回结果。' });
        attempt--;
        continue;
      }
      return { success: false, message: '模型只回了文字、没有调用工具执行编辑（已要求改用工具 ' + noToolViolations + ' 次仍未执行），请重试或换用支持工具调用的模型' };
    }
    return { success: false, message: 'AI 编辑失败' };
  }

  // 长文切块：优先换行边界，块为原文精确子串（与服务器端同策略）
  function chunkText(text, size) {
    if (text.length <= size) return [text];
    var chunks = [], start = 0, lastBreak = 0;
    for (var i = 0; i < text.length; i++) {
      if (i - start >= size) {
        var cut = lastBreak > start ? lastBreak : i;
        chunks.push(text.slice(start, cut));
        start = cut;
        lastBreak = start;
      }
      if (text[i] === '\n') lastBreak = i + 1;
    }
    if (start < text.length) chunks.push(text.slice(start));
    return chunks;
  }

  window.AIDirect = { edit: edit, normalizeEndpoint: normalizeEndpoint, proxyUrl: proxyUrl };
})();
