/**
 * Pixel Notes - AI 前端直连模块（独立文件，与 app.js 解耦）
 *
 * 当用户在 AI 设置中填写了自己的透明反代（Workers）地址时，
 * AI 编辑请求从浏览器直接发送到用户自己的代理，完全不经过 Pixel Notes 平台。
 *
 * 实现以 protocol.md v7 为准（分段参数 / prompt 模板 / 纠错话术 / 澄清提问 / TOOL 工具块 / 整理 Agent SSE 的唯一事实源），改动需与 api/ai.php 同步
 *
 * 接口：window.AIDirect.edit({ title, content, instruction, style, proxy, baseUrl, apiKey, model })
 * 返回：Promise<{ success, content, mode, applied, failed, message }>
 */
(function () {
  'use strict';

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
      + '可以有多个替换块，按顺序排列。SEARCH 段尽量短且在全文中唯一。\n'
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
      + '【工具调用（可选）】当你需要查看便签所在文件夹或其他文件夹里有什么时，可以调用工具。输出格式：\n'
      + '<<<TOOL>>>\n'
      + '{"name":"list_folder","path":"工作/项目A"}  —— 查看指定路径文件夹的便签清单；查看主页根层级用 {"name":"list_folder","path":"主页"}\n'
      + '或 {"name":"read_note","id":123}  —— 查看 id 为 123 的便签完整内容\n'
      + '说明：工具调用是你的输出的**全部内容**；收到工具结果后你继续推理，最多可连续调用 5 次。不要调用不在白名单里的任何工具。\n'
      + '最后输出最终编辑结果（A/B/C 格式）';
    if (style) s += '\n【用户风格偏好】在不违背上述硬性规则的前提下，尽量按以下风格完成编辑：' + style;
    if (now) s += '\n【当前时间】现在是 ' + now + '（用户本地时间）。涉及时间、日期、星期、节假日等内容的编辑请以此为准，不要虚构时间。';
    return s;
  }

  // 解析澄清提问块 <<<CLARIFY>>>...<<<END>>>，返回问题数组
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
    return String(text || '').replace(/<<<(?:SEARCH|REPLACE|END|CLARIFY)>>>/gi, '').trim();
  }

  // 宽容匹配辅助：空白集为 [ \t\r\n\f\v　]（ASCII 空白 + 全角空格），与 api/ai.php 逐字一致
  function foldWs(s) {
    return s.replace(/[ \t\r\n\f\v\u3000]+/g, ' ');
  }

  function rtrimLine(s) {
    return s.replace(/[ \t\u3000]+$/g, '');
  }

  // 三级宽容匹配替换（protocol v4，与 api/ai.php aiApplyBlock 逐字一致）：
  // 1. 精确子串；2. 行尾空白归一；3. 全空白折叠归一。第 2/3 级按行滑窗、全文唯一命中才应用，
  // 内层归一化长度超过 SEARCH 归一化长度即提前终止。成功返回新内容，失败返回 null。
  function matchAndApply(content, search, replace) {
    if (!search) return null;
    var idx = content.indexOf(search);
    if (idx !== -1) {
      return content.slice(0, idx) + replace + content.slice(idx + search.length);
    }
    var lines = content.split('\n');
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
          if (k === needleKey) { hits.push([i, j]); break; }
          if (k.length > needleKey.length) break;
        }
      }
      if (hits.length === 1) {
        var seg = lines.slice(0, hits[0][0]);
        seg.push(replace);
        return seg.concat(lines.slice(hits[0][1] + 1)).join('\n');
      }
    }
    return null;
  }

  function applyBlocks(text, originalContent) {
    var re = /<<<SEARCH>>>\s*\n([\s\S]*?)\n?<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/ig;
    var m = null;
    var applied = 0;
    var failed = 0;
    var bad = [];
    var result = originalContent.replace(/\r\n/g, '\n');
    while ((m = re.exec(text)) !== null) {
      var search = m[1].replace(/\r\n/g, '\n').replace(/\n+$/, '');
      var replace = m[2].replace(/\r\n/g, '\n').replace(/\n+$/, '');
      var nc = matchAndApply(result, search, replace);
      if (nc !== null) {
        result = nc;
        applied++;
      } else {
        failed++;
        bad.push(search);
      }
    }
    return { result: result, applied: applied, failed: failed, bad: bad, hasBlocks: applied + failed > 0 };
  }

  // 单轮请求：返回 { ok, text, message }（ok=false 时 message 为错误说明）
  // onDelta 提供时走流式（stream:true），逐 token 回调；上游不支持流式时自动降级为整段返回（结果不变）
  async function callOnce(proxy, target, apiKey, model, messages, extra, onDelta) {
    var payload = { model: model, messages: messages, max_tokens: 16000, temperature: 0.1 };
    // 额外请求体参数：深度思考预设 + 用户自定义 Body（后者优先，同名覆盖）
    if (extra) {
      for (var k in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, k) && k !== 'model' && k !== 'messages') payload[k] = extra[k];
      }
    }
    if (onDelta) payload.stream = true;
    var resp;
    try {
      resp = await fetch(proxy + '/' + target, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + apiKey
        },
        body: JSON.stringify(payload)
      });
    } catch (e) {
      return { ok: false, message: '无法连接你的透明代理（检查地址是否正确、Worker 是否已部署）' };
    }

    // 流式：ReadableStream 逐块解析上游 SSE，提取 delta.content 即时回调
    if (onDelta && resp.ok && resp.body && typeof resp.body.getReader === 'function') {
      try {
        var reader = resp.body.getReader();
        var dec = new TextDecoder('utf-8');
        var sseBuf = '';
        var raw = '';
        var text = '';
        var sawDelta = false;
        while (true) {
          var rd = await reader.read();
          if (rd.done) break;
          var chunk = dec.decode(rd.value, { stream: true });
          if (!chunk) continue;
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
            var j = null;
            try { j = JSON.parse(d); } catch (e) { j = null; }
            if (!j || !j.choices || !j.choices[0]) continue;
            var delta = '';
            if (j.choices[0].delta && typeof j.choices[0].delta.content === 'string') delta = j.choices[0].delta.content;
            else if (typeof j.choices[0].text === 'string') delta = j.choices[0].text;
            if (delta) { text += delta; sawDelta = true; onDelta(delta); }
          }
        }
        if (sawDelta) return { ok: true, text: text };
        // 收到 200 但没有任何 content 增量：上游不支持流式（整段 JSON），往下按整段解析
        if (raw.indexOf('"reasoning_content"') !== -1) {
          return { ok: false, message: '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens' };
        }
        var jsonFb = null;
        try { jsonFb = JSON.parse(raw); } catch (e) { jsonFb = null; }
        if (jsonFb && jsonFb.choices && jsonFb.choices[0]) {
          var moFb = jsonFb.choices[0].message || {};
          var tFb = String(moFb.content || '').trim();
          if (!tFb && moFb.reasoning_content) {
            return { ok: false, message: '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens' };
          }
          if (tFb) return { ok: true, text: tFb };
          if (jsonFb.choices[0].text) return { ok: true, text: String(jsonFb.choices[0].text).trim() };
        }
        return { ok: false, message: 'AI 返回了空内容', empty: true };
      } catch (e) {
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
      return { ok: false, message: '上游错误：' + msg };
    }
    if (!json || !json.choices || !json.choices[0]) {
      return { ok: false, message: '响应格式异常：' + raw.slice(0, 150) };
    }

    var mo = json.choices[0].message || {};
    var text = String(mo.content || '').trim();
    if (!text && mo.reasoning_content) {
      return { ok: false, message: '模型只返回了思考过程没有正文，请换用非推理模型或调大 max_tokens' };
    }
    if (!text && json.choices[0].text) text = String(json.choices[0].text).trim();
    if (!text) return { ok: false, message: 'AI 返回了空内容', empty: true };
    return { ok: true, text: text };
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

    var messages = [
      { role: 'system', content: buildSystemPrompt(opts.style, opts.now) },
      {
        role: 'user',
        content: '【便签标题】' + (opts.title || '(无标题)') + '\n'
          + '【当前便签内容】\n' + (opts.content ? opts.content : '(空便签)') + '\n\n'
          + '【编辑指令】' + opts.instruction
      }
    ];
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
        + '\n【分段模式】这是一篇长文，已分 ' + n + ' 段，你只处理「本段内容」这一个段。SEARCH 段必须逐字复制自「本段内容」。若本段完全无需修改，只输出四个字：本段无需修改。';
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
          var r = await callOnce(proxy, target, apiKey, model, segMsgs, extra, opts.onDelta);
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

    // 自纠错循环：SEARCH 块匹配失败时，带上上下文告诉 AI 哪里错了，最多 3 轮
    var maxAttempts = 3;
    for (var attempt = 1; attempt <= maxAttempts; attempt++) {
      if (opts.onPhase) opts.onPhase(attempt > 1 ? '🔁 自动纠错第 ' + (attempt - 1) + ' 次…' : '🤖 正在生成…');
      var r = await callOnce(proxy, target, apiKey, model, messages, extra, opts.onDelta);
      if (!r.ok && !r.empty) return { success: false, message: r.message };

      var text = r.text || '';
      // 去掉整段围栏包裹
      var fence = text.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i);
      if (fence) text = fence[1].trim();

      // 澄清提问：拿不准就继续问，轮数不限（每轮需用户手动回答，人工熔断）
      var clarify = parseClarify(text);
      if (clarify.length) return clarifyResult(clarifyRounds, clarify);

      // ===== TOOL 块（浏览器端执行，数据全在页面内存 notesById/foldersById 里） =====
      var toolRounds = 0;
      while (toolRounds < 5) {
        var toolMatch = text.match(/<<<TOOL>>>\s*([\s\S]*?)\s*<<<END>>>/i);
        if (!toolMatch) break;
        var toolCall = null;
        try { toolCall = JSON.parse(toolMatch[1].trim()); } catch (e) { break; }
        if (!toolCall || !toolCall.name) break;
        toolRounds++;
        if (opts.onPhase) opts.onPhase('🔧 工具调用：' + toolCall.name + '...');
        var toolResult = runLocalTool(toolCall);
        messages.push({ role: 'assistant', content: text });
        messages.push({ role: 'user', content: '【工具结果】' + toolCall.name + '\n' + toolResult });
        var r2 = await callOnce(proxy, target, apiKey, model, messages, extra, opts.onDelta);
        if (!r2.ok) return { success: false, message: r2.message };
        text = (r2.text || '').trim();
        var fence2 = text.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i);
        if (fence2) text = fence2[1].trim();
        var clarify2 = parseClarify(text);
        if (clarify2.length) return clarifyResult(clarifyRounds, clarify2);
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

      var b = applyBlocks(text, opts.content || '');
      if (b.hasBlocks) {
        if (b.applied > 0) {
          return { success: true, mode: 'edits', applied: b.applied, failed: b.failed, content: b.result, attempts: attempt };
        }
        // 空便签兜底（protocol v3）：空便签没有原文可匹配，模型误用 A 时把全部 REPLACE 段拼成新全文（视同 B），不进入重试
        if (!String(opts.content || '').trim()) {
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

      // 全文重写模式
      return { success: true, mode: 'full', content: cleanOutput(text), attempts: attempt };
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
