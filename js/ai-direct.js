/**
 * Pixel Notes - AI 前端直连模块（独立文件，与 app.js 解耦）
 *
 * 当用户在 AI 设置中填写了自己的透明反代（Workers）地址时，
 * AI 编辑请求从浏览器直接发送到用户自己的代理，完全不经过 Pixel Notes 平台。
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
      + '【输出格式（二选一）】\n'
      + 'A. 局部修改（默认首选）：只改动需要改的地方。每个改动输出一个替换块，格式严格如下：\n'
      + '<<<SEARCH>>>\n'
      + '（便签原文中要被修改的那段文字，必须与原文逐字一致，包括空格、换行、标点）\n'
      + '<<<REPLACE>>>\n'
      + '（修改后的文字）\n'
      + '<<<END>>>\n'
      + '可以有多个替换块，按顺序排列。SEARCH 段尽量短且在全文中唯一。\n'
      + 'B. 全文重写：仅当指令要求整体重构、全文翻译、全文总结、从零创作时，才直接输出完整的新便签全文。\n'
      + '【硬性规则】\n'
      + '1. 绝对禁止删除、改写、移动用户已有的链接、URL、HTML 标签、图片/音频/视频/iframe 嵌入和代码块，除非指令明确要求处理它们\n'
      + '2. 用户没让改的部分必须一字不动，只做最小限度的必要修改，禁止顺手润色或重排\n'
      + '3. 不要输出任何解释、前言、结束语，不要用代码围栏（```）包裹整个输出\n'
      + '4. 保持 Markdown 格式；便签支持：标题/加粗/斜体/列表/引用/链接/图片/任务列表/代码块\n'
      + '5. 便签标题不在你负责范围内，只编辑正文\n'
      + '6. 便签内容为空且指令是创作类时，用 B 格式直接创作';
    if (style) s += '\n【用户风格偏好】在不违背上述硬性规则的前提下，尽量按以下风格完成编辑：' + style;
    if (now) s += '\n【当前时间】现在是 ' + now + '（用户本地时间）。涉及时间、日期、星期、节假日等内容的编辑请以此为准，不要虚构时间。';
    return s;
  }

  // 与服务端一致的局部修改块解析与应用
  function applyBlocks(text, originalContent) {
    var re = /<<<SEARCH>>>\s*\n([\s\S]*?)\n?<<<REPLACE>>>\s*\n([\s\S]*?)\n?<<<END>>>/ig;
    var m = null;
    var applied = 0;
    var failed = 0;
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
      }
    }
    return { result: result, applied: applied, failed: failed, hasBlocks: applied + failed > 0 };
  }

  // 单轮请求：返回 { ok, text, message }（ok=false 时 message 为错误说明）
  async function callOnce(proxy, target, apiKey, model, messages) {
    var resp;
    try {
      resp = await fetch(proxy + '/' + target, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + apiKey
        },
        body: JSON.stringify({ model: model, messages: messages, max_tokens: 8000, temperature: 0.4 })
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

    if (!proxy) return { success: false, message: '透明代理地址无效' };
    if (!target) return { success: false, message: '接口地址无效' };
    if (!apiKey || !model) return { success: false, message: '缺少 API Key 或模型名' };

    var messages = [
      { role: 'system', content: buildSystemPrompt(opts.style, opts.now) },
      {
        role: 'user',
        content: '【便签标题】' + (opts.title || '(无标题)') + '\n'
          + '【当前便签内容】\n' + (opts.content ? opts.content : '(空便签)') + '\n\n'
          + '【编辑指令】' + opts.instruction
      }
    ];

    // 自纠错循环：SEARCH 块匹配失败时，带上上下文告诉 AI 哪里错了，最多 3 轮
    var maxAttempts = 3;
    for (var attempt = 1; attempt <= maxAttempts; attempt++) {
      var r = await callOnce(proxy, target, apiKey, model, messages);
      if (!r.ok && !r.empty) return { success: false, message: r.message };

      var text = r.text || '';
      // 去掉整段围栏包裹
      var fence = text.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n?```$/i);
      if (fence) text = fence[1].trim();

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
          messages.push({ role: 'assistant', content: text });
          messages.push({
            role: 'user',
            content: '你上一轮输出的替换块全部无法匹配原文（共 ' + b.failed + ' 个）。'
              + 'SEARCH 段必须从【当前便签内容】中逐字精确复制（包括空格、换行、标点、Markdown 符号），禁止凭记忆复述。'
              + '请重新输出替换块完成原指令：' + opts.instruction
          });
          continue;
        }
        return { success: false, message: 'AI 指出的修改位置无法在原文中匹配，已自动重试 ' + maxAttempts + ' 轮仍失败，请重试或换个说法' };
      }

      // 全文重写模式
      return { success: true, mode: 'full', content: text, attempts: attempt };
    }
    return { success: false, message: 'AI 编辑失败' };
  }

  window.AIDirect = { edit: edit, normalizeEndpoint: normalizeEndpoint, proxyUrl: proxyUrl };
})();
