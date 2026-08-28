/**
 * Pixel Notes - AI 前端直连模块（独立文件，与 app.js 解耦）
 *
 * 当用户在 AI 设置中填写了自己的透明反代（Workers）地址时，
 * AI 编辑请求从浏览器直接发送到用户自己的代理，完全不经过 Pixel Notes 平台。
 *
 * 实现以 protocol.md v2 为准（分段参数 / prompt 模板 / 纠错话术 / 澄清提问的唯一事实源），改动需与 api/ai.php 同步
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
      + '【硬性规则】\n'
      + '1. 绝对禁止删除、改写、移动用户已有的链接、URL、HTML 标签、图片/音频/视频/iframe 嵌入和代码块，除非指令明确要求处理它们\n'
      + '2. 用户没让改的部分必须一字不动，只做最小限度的必要修改，禁止顺手润色或重排\n'
      + '3. 不要输出任何解释、前言、结束语，不要用代码围栏（```）包裹整个输出\n'
      + '4. 保持 Markdown 格式；便签支持：标题/加粗/斜体/列表/引用/链接/图片/任务列表/代码块\n'
      + '5. 便签标题不在你负责范围内，只编辑正文\n'
      + '6. 便签内容为空时：指令是创作新内容就直接用 B 格式创作；指令像是要编辑已有内容但你无从下手时，用 C 澄清提问确认用户想要什么';
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
      var idx = search !== '' ? result.indexOf(search) : -1;
      if (idx !== -1) {
        result = result.slice(0, idx) + replace + result.slice(idx + search.length);
        applied++;
      } else {
        failed++;
        bad.push(search);
      }
    }
    return { result: result, applied: applied, failed: failed, bad: bad, hasBlocks: applied + failed > 0 };
  }

  // 单轮请求：返回 { ok, text, message }（ok=false 时 message 为错误说明）
  async function callOnce(proxy, target, apiKey, model, messages, extra) {
    var payload = { model: model, messages: messages, max_tokens: 16000, temperature: 0.1 };
    // 额外请求体参数：深度思考预设 + 用户自定义 Body（后者优先，同名覆盖）
    if (extra) {
      for (var k in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, k) && k !== 'model' && k !== 'messages') payload[k] = extra[k];
      }
    }
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
          var r = await callOnce(proxy, target, apiKey, model, segMsgs, extra);
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
              newContent = newContent.slice(0, pos) + text + newContent.slice(pos + chunks[ci].length);
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
      var r = await callOnce(proxy, target, apiKey, model, messages, extra);
      if (!r.ok && !r.empty) return { success: false, message: r.message };

      var text = r.text || '';
      // 去掉整段围栏包裹
      var fence = text.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i);
      if (fence) text = fence[1].trim();

      // 澄清提问：拿不准时向用户提问（轮数不限）
      var clarify = parseClarify(text);
      if (clarify.length) return clarifyResult(clarifyRounds, clarify);

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
      return { success: true, mode: 'full', content: text, attempts: attempt };
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
