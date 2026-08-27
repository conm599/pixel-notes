/**
 * PixelMD - 轻量 Markdown 渲染器（像素便签专用）
 *
 * 安全设计：所有原文先经过 HTML 转义，再生成受控标签，
 * 链接/图片仅允许 http(s) 与 mailto，从根源上阻止 XSS。
 *
 * 支持：# 标题 / **加粗** / *斜体* / ~~删除线~~ / `行内代码`
 *      ```代码块``` / - 无序列表 / 1. 有序列表（支持两级嵌套）
 *      > 引用 / [链接](url) / ![图片](url) / <img>图片 / <iframe>嵌入 / <audio>/<video> / 表格
 */
(function (window) {
  'use strict';

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function unescapeAttr(s) {
    return String(s || '')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/&amp;/g, '&');
  }

  // 反转义 + 去首尾反引号（从别处复制的地址常被包裹）
  function cleanAttr(s) {
    return unescapeAttr(s).trim().replace(/^`+|`+$/g, '');
  }

  // 解析（已转义的）属性串 → { name: value }
  function parseAttrs(attrs) {
    var v = {};
    var re = /([a-zA-Z][\w-]*)\s*=\s*(?:&quot;([\s\S]*?)&quot;|&#39;([\s\S]*?)&#39;|([^\s]+))/g;
    var a;
    while ((a = re.exec(attrs))) {
      v[a[1].toLowerCase()] = (a[2] !== undefined ? a[2] : (a[3] !== undefined ? a[3] : a[4]));
    }
    return v;
  }

  function validSize(s) {
    return s && /^\d{1,4}%?$/.test(s);
  }

  /* iframe 仅放行知名音视频站点的播放器域名（白名单） */
  var EMBED_HOSTS = [
    'player.bilibili.com', 'www.bilibili.com',
    'www.youtube.com', 'www.youtube-nocookie.com', 'm.youtube.com', 'youtube.com',
    'player.vimeo.com', 'player.dailymotion.com',
    'music.163.com', 'open.spotify.com'
  ];

  function allowedEmbedUrl(src) {
    var u = cleanAttr(src);
    if (!u || /[<>"'\s]/.test(u)) return '';
    // 协议相对地址（B站等官方嵌入代码常见）与 http → 统一规范化为 https
    if (u.indexOf('//') === 0) u = 'https:' + u;
    else if (/^http:\/\//i.test(u)) u = u.replace(/^http:\/\//i, 'https://');
    if (!/^https:\/\//i.test(u)) return '';
    var h = u.replace(/^https:\/\//i, '').split('/')[0].toLowerCase();
    for (var i = 0; i < EMBED_HOSTS.length; i++) {
      var w = EMBED_HOSTS[i];
      if (h === w || h.slice(-(w.length + 1)) === '.' + w) return u;
    }
    return '';
  }

  function safeUrl(url, isImage) {
    var u = String(url || '').replace(/\s/g, '');
    // 纵深防御：URL 中不允许出现引号/尖括号（含实体形式，防属性逃逸）
    var probe = u.replace(/&(quot|lt|gt|#0?39|#x2[27]|apos);/gi, '\u0001');
    if (/[<>"'`\u0001]/.test(probe)) return '';
    if (/^https?:\/\//i.test(u)) return u;
    if (!isImage && /^mailto:/i.test(u)) return u;
    if (!isImage && u.charAt(0) === '#') return u;
    return '';
  }

  /* ---------- 行内渲染 ---------- */
  function renderInline(text) {
    var stash = [];
    function put(html) {
      stash.push(html);
      return '\u0000' + (stash.length - 1) + '\u0000';
    }

    var t = String(text);

    // 1) 行内代码优先（内部不做其他格式化）
    //    注：反引号前若是引号（如 src="`url`"）不视为代码段——保留给媒体标签解析
    t = t.replace(/(^|[^"`])`([^`]+)`/g, function (m, pre, code) {
      return pre + put('<code class="md-code">' + escapeHtml(code) + '</code>');
    });

    // 2) 转义剩余文本
    t = escapeHtml(t);

    // 3) 内嵌媒体：HTML 形式的 <img> / <iframe> / <audio> / <video>
    //    安全策略：不透传原始 HTML，按“属性白名单重建受控标签”+ iframe 域名白名单
    t = t.replace(/&lt;img\b([\s\S]*?)\/?\s*&gt;/gi, function (m, attrs) {
      var v = parseAttrs(attrs);
      var u = safeUrl(cleanAttr(v.src), true);
      if (!u) return m;
      var out = '<img src="' + u + '" class="md-img"';
      if (v.alt !== undefined) out += ' alt="' + escapeHtml(unescapeAttr(v.alt)) + '"';
      if (v.title !== undefined) out += ' title="' + escapeHtml(unescapeAttr(v.title)) + '"';
      if (validSize(v.width)) out += ' width="' + v.width + '"';
      if (validSize(v.height)) out += ' height="' + v.height + '"';
      return put(out + '>');
    });

    // iframe：仅放行白名单域名的播放器；属性固定，尺寸交给 CSS（响应式 16:9）
    t = t.replace(/&lt;iframe\b([\s\S]*?)&gt;([\s\S]*?)&lt;\/iframe&gt;|&lt;iframe\b([\s\S]*?)\/?\s*&gt;/gi, function (m, open, inner, open2) {
      var v = parseAttrs(open !== undefined ? open : open2);
      var u = allowedEmbedUrl(v.src);
      if (!u) return m;
      var out = '<iframe src="' + u + '" class="md-embed" loading="lazy" allowfullscreen ' +
        'referrerpolicy="strict-origin-when-cross-origin" ' +
        'allow="fullscreen; picture-in-picture; clipboard-write; encrypted-media; autoplay"';
      if (v.title !== undefined) out += ' title="' + escapeHtml(unescapeAttr(v.title)) + '"';
      return put(out + '></iframe>');
    });

    // audio/video：src 走 safeUrl（http/https）；不用原生 controls，渲染自绘像素风播放器外壳
    function playerShell(kind, mediaTag) {
      var fs = kind === 'video'
        ? '<button type="button" class="mp-btn" data-mp="full" aria-label="全屏">⛶</button>'
        : '';
      return '<span class="md-player mp-' + kind + '">' +
        (kind === 'video'
          ? '<span class="mp-screen" data-mp="screen">' + mediaTag + '<span class="mp-big" data-mp="toggle" role="button" aria-label="播放">▶</span></span>'
          : mediaTag) +
        '<span class="mp-ui">' +
        '<button type="button" class="mp-btn mp-toggle" data-mp="toggle" aria-label="播放/暂停">▶</button>' +
        '<span class="mp-time" data-mp="time">0:00 / 0:00</span>' +
        '<span class="mp-bar" data-mp="seek"><span class="mp-buf"></span><span class="mp-fill"></span></span>' +
        '<button type="button" class="mp-btn mp-mute" data-mp="mute" aria-label="静音">♪</button>' +
        fs +
        '</span></span>';
    }

    t = t.replace(/&lt;audio\b([\s\S]*?)&gt;([\s\S]*?)&lt;\/audio&gt;|&lt;audio\b([\s\S]*?)\/?\s*&gt;/gi, function (m, open, inner, open2) {
      var v = parseAttrs(open !== undefined ? open : open2);
      var u = safeUrl(cleanAttr(v.src), true);
      if (!u) return m;
      return put(playerShell('audio', '<audio src="' + u + '" preload="metadata"></audio>'));
    });

    // video：src/poster 走 safeUrl，width/height 数字校验
    t = t.replace(/&lt;video\b([\s\S]*?)&gt;([\s\S]*?)&lt;\/video&gt;|&lt;video\b([\s\S]*?)\/?\s*&gt;/gi, function (m, open, inner, open2) {
      var v = parseAttrs(open !== undefined ? open : open2);
      var u = safeUrl(cleanAttr(v.src), true);
      if (!u) return m;
      var out = '<video src="' + u + '" preload="metadata" playsinline';
      if (validSize(v.width)) out += ' width="' + v.width + '"';
      if (validSize(v.height)) out += ' height="' + v.height + '"';
      if (v.poster) {
        var p = safeUrl(cleanAttr(v.poster), true);
        if (p) out += ' poster="' + p + '"';
      }
      return put(playerShell('video', out + '></video>'));
    });

    // 4) 图片 ![alt](url)
    t = t.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, function (m, alt, url) {
      var u = safeUrl(url, true);
      if (!u) return m;
      return put('<img src="' + u + '" alt="' + escapeHtml(alt) + '" class="md-img">');
    });

    // 5) 链接 [text](url)
    t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, txt, url) {
      var u = safeUrl(url, false);
      if (!u) return m;
      return put('<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + txt + '</a>');
    });

    // 6) 加粗 / 斜体 / 删除线
    t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    t = t.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    t = t.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
    t = t.replace(/(^|[^_\w])_([^_\n]+)_(?=$|[^_\w])/g, '$1<em>$2</em>');
    t = t.replace(/~~([^~]+)~~/g, '<del>$1</del>');

    // 7) 还原占位
    t = t.replace(/\u0000(\d+)\u0000/g, function (m, i) {
      return stash[+i];
    });
    return t;
  }

  /* ---------- 列表渲染（支持两级嵌套） ---------- */
  function renderList(items) {
    if (!items.length) return '';

    function build(start, level, ordered) {
      var out = ordered ? '<ol class="md-ol">' : '<ul class="md-ul">';
      var i = start;
      while (i < items.length && items[i].indent >= level) {
        if (items[i].indent === level) {
          if (items[i].ordered !== ordered) break;
          // 任务列表：- [ ] / - [x]
          var txt = items[i].text;
          var chk = txt.match(/^\[([ xX])\]\s*/);
          var li;
          if (chk && !ordered) {
            li = '<li class="md-task"><span class="md-check">' +
              (chk[1] === ' ' ? '\u2610' : '\u2611') + '</span>' +
              renderInline(txt.slice(chk[0].length));
          } else {
            li = '<li>' + renderInline(txt);
          }
          // 附属内容（引用块/缩进内容）拼进该项
          if (items[i].extra) li += items[i].extra;
          if (i + 1 < items.length && items[i + 1].indent > level) {
            var sub = build(i + 1, items[i + 1].indent, items[i + 1].ordered);
            li += sub.html;
            i = sub.next - 1;
          }
          out += li + '</li>';
          i++;
        } else {
          var sub2 = build(i, items[i].indent, items[i].ordered);
          var idx = out.lastIndexOf('</li>');
          if (idx !== -1) {
            out = out.slice(0, idx) + sub2.html + '</li>';
          } else {
            out += sub2.html;
          }
          i = sub2.next;
        }
      }
      return { html: out + (ordered ? '</ol>' : '</ul>'), next: i };
    }

    return build(0, items[0].indent, items[0].ordered).html;
  }

  /* ---------- 表格渲染 ---------- */
  function parseTable(lines, start) {
    var header = lines[start];
    var sep = lines[start + 1];
    if (!header || header.indexOf('|') === -1) return null;
    if (!/^\s*\|?[\s:|-]+\|[\s:|-]*$/.test(sep || '')) return null;

    var end = start + 2;
    while (end < lines.length && lines[end].indexOf('|') !== -1 && lines[end].trim() !== '') end++;

    var aligns = sep.split('|').map(function (c) {
      c = c.trim();
      var l = c.charAt(0) === ':';
      var r = c.charAt(c.length - 1) === ':';
      return (l && r) ? 'center' : (r ? 'right' : (l ? 'left' : ''));
    });

    function cells(row) {
      return row.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) {
        return c.trim();
      });
    }

    var out = '<table class="md-table"><thead><tr>';
    cells(header).forEach(function (c, i) {
      var a = aligns[i] || '';
      out += '<th' + (a ? ' style="text-align:' + a + '"' : '') + '>' + renderInline(c) + '</th>';
    });
    out += '</tr></thead><tbody>';
    for (var r = start + 2; r < end; r++) {
      out += '<tr>';
      cells(lines[r]).forEach(function (c, i) {
        var a = aligns[i] || '';
        out += '<td' + (a ? ' style="text-align:' + a + '"' : '') + '>' + renderInline(c) + '</td>';
      });
      out += '</tr>';
    }
    out += '</tbody></table>';
    return { html: out, next: end };
  }

  /* ---------- 块级渲染 ---------- */
  function renderLines(lines) {
    var html = [];
    var i = 0;
    var n = lines.length;

    while (i < n) {
      var line = lines[i];

      if (line.trim() === '') { i++; continue; }

      // 围栏代码块
      var fence = line.match(/^\s*(```+|~~~+)\s*(\S*)\s*$/);
      if (fence) {
        var closer = fence[1].charAt(0);
        var buf = [];
        i++;
        while (i < n && !new RegExp('^\\s*' + closer + '{3,}\\s*$').test(lines[i])) {
          buf.push(lines[i]);
          i++;
        }
        i++; // 跳过闭合围栏
        var lang = fence[2] ? ' data-lang="' + escapeHtml(fence[2]) + '"' : '';
        html.push('<pre class="md-pre"' + lang + '><code>' + escapeHtml(buf.join('\n')) + '</code></pre>');
        continue;
      }

      // 标题
      var h = line.match(/^(#{1,6})\s+(.*)$/);
      if (h) {
        var lv = h[1].length;
        html.push('<h' + lv + ' class="md-h md-h' + lv + '">' + renderInline(h[2]) + '</h' + lv + '>');
        i++;
        continue;
      }

      // 分割线
      if (/^\s*([-*_])\s*(\1\s*){2,}$/.test(line)) {
        html.push('<hr class="md-hr">');
        i++;
        continue;
      }

      // 表格
      if (i + 1 < n && line.indexOf('|') !== -1) {
        var tb = parseTable(lines, i);
        if (tb) { html.push(tb.html); i = tb.next; continue; }
      }

      // 引用
      if (/^\s*>/.test(line)) {
        var q = [];
        while (i < n && /^\s*>/.test(lines[i])) {
          q.push(lines[i].replace(/^\s*>\s?/, ''));
          i++;
        }
        html.push('<blockquote class="md-quote">' + renderLines(q) + '</blockquote>');
        continue;
      }

      // 列表（支持宽松模式：列表项后面跟的引用块/空行+续行归属到该项）
      if (/^\s*([-*+]\s+)|(\d+[.)]\s+)/.test(line)) {
        var items = [];
        var pending = [];   // 当前项的附属行（引用等）
        var pendingIndent = 0;
        var flushPending = function () {
          if (!pending.length) return;
          var last = items[items.length - 1];
          if (last) {
            // 引用块作为该项的内容渲染
            last.extra = (last.extra || '') + renderLines(pending);
          }
          pending = [];
        };
        while (i < n) {
          var ln = lines[i];
          var lm = ln.match(/^(\s*)(?:([-*+])|(\d+[.)]))\s+(.*)$/);
          if (lm) {
            var lmIndent = Math.floor(lm[1].replace(/\t/g, '  ').length / 2);
            var lmOrdered = !!lm[3];
            // 混合类型顶格无序列表（如 - [ ] 任务）跟在有序项后 → 归入当前项作为子列表
            if (items.length && !lmOrdered && lmIndent === 0 && items[0].ordered) {
              pending.push(ln);
              i++;
              continue;
            }
            flushPending();
            items.push({
              indent: lmIndent,
              ordered: lmOrdered,
              text: lm[4]
            });
            pendingIndent = lmIndent;
            i++;
            continue;
          }
          // 空行：可能是列表结束，也可能是列表项之间的间隔（后面还有列表就继续）
          if (ln.trim() === '') {
            // 向后看：跳过空行后如果还是列表或引用，则列表继续
            var j = i + 1;
            while (j < n && lines[j].trim() === '') j++;
            if (j < n && (/^\s*([-*+]\s+)|(\d+[.)]\s+)/.test(lines[j]) || /^\s*>/.test(lines[j]))) {
              pending.push('');
              i++;
              continue;
            }
            break;
          }
          // 引用行（可能是未缩进的）→ 归属当前项
          if (/^\s*>/.test(ln)) {
            pending.push(ln);
            i++;
            continue;
          }
          // 缩进的内容行 → 归属当前项
          if (/^\s{2,}/.test(ln) && items.length) {
            pending.push(ln.replace(new RegExp('^\\s{' + (pendingIndent * 2) + '}'), ''));
            i++;
            continue;
          }
          break;
        }
        flushPending();
        // 渲染列表（extra 拼到对应 li 里）
        html.push(renderList(items));
        continue;
      }

      // 段落
      var para = [line];
      i++;
      while (i < n && lines[i].trim() !== '' &&
        !/^(#{1,6})\s+/.test(lines[i]) &&
        !/^\s*(```|~~~)/.test(lines[i]) &&
        !/^\s*>/.test(lines[i]) &&
        !/^\s*([-*+]\s+)/.test(lines[i]) &&
        !/^\s*\d+[.)]\s+/.test(lines[i])) {
        para.push(lines[i]);
        i++;
      }
      html.push('<p class="md-p">' + renderInline(para.join('\n')).replace(/\n/g, '<br>') + '</p>');
    }
    return html.join('\n');
  }

  function render(src) {
    if (src == null) src = '';
    var text = String(src).replace(/\r\n?/g, '\n');
    return renderLines(text.split('\n'));
  }

  /* === 自绘播放器运行时：全局事件委托（捕获阶段），无需在渲染处逐个绑定 === */
  if (typeof document !== 'undefined' && document.addEventListener) {
    function mpFmt(t) {
      if (!isFinite(t) || t < 0) t = 0;
      t = Math.floor(t);
      var m = Math.floor(t / 60), s = t % 60;
      return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function mpRoot(el) {
      var n = el;
      while (n && n !== document) {
        if (n.classList && n.classList.contains('md-player')) return n;
        n = n.parentNode;
      }
      return null;
    }

    function mpSync(e) {
      var media = e.target;
      if (!media || !media.tagName) return;
      var tag = media.tagName.toUpperCase();
      if (tag !== 'AUDIO' && tag !== 'VIDEO') return;
      var root = mpRoot(media);
      if (!root) return;
      var timeEl = root.querySelector('[data-mp="time"]');
      var fill = root.querySelector('.mp-fill');
      var buf = root.querySelector('.mp-buf');
      var tog = root.querySelector('.mp-toggle');
      var mute = root.querySelector('.mp-mute');

      if (e.type === 'error' && media.error) {
        root.classList.add('mp-err');
        if (timeEl) timeEl.textContent = '⚠ 加载失败';
        return;
      }
      if (e.type === 'loadedmetadata') {
        // 元数据就绪即检测可拖性；不可拖（服务器无 Range）→ 立即后台预转存
        if (mpSeekable(media)) return;
        var rootForPre = mpRoot(media);
        if (rootForPre && !media.getAttribute('data-mp-blob')) {
          setTimeout(function () { mpBlobify(rootForPre, media, null, media.paused); }, 0);
        }
        return;
      }
      if (timeEl) timeEl.textContent = mpFmt(media.currentTime) + ' / ' + mpFmt(media.duration);
      if (fill && isFinite(media.duration) && media.duration > 0) {
        fill.style.width = Math.min(100, media.currentTime / media.duration * 100) + '%';
      }
      if (buf && isFinite(media.duration) && media.duration > 0) {
        var end = 0;
        try {
          for (var i = 0; i < media.buffered.length; i++) {
            if (media.buffered.start(i) <= media.currentTime + 0.5) end = media.buffered.end(i);
          }
        } catch (_) {}
        buf.style.width = Math.min(100, end / media.duration * 100) + '%';
      }
      if (tog) tog.textContent = media.paused ? '▶' : '❚❚';
      root.classList.toggle('playing', !media.paused);
      if (mute) mute.classList.toggle('mp-muted', media.muted || media.volume === 0);
    }

    ['timeupdate', 'durationchange', 'loadedmetadata', 'play', 'pause', 'ended',
      'volumechange', 'progress', 'error'].forEach(function (ev) {
      document.addEventListener(ev, mpSync, true);
    });

    // 判断媒体是否真正可拖动（无 Range 支持的源 seekable 为空，拖动会归零）
    function mpSeekable(media) {
      try {
        if (media.seekable && media.seekable.length > 0) {
          var s = media.seekable.start(0), e = media.seekable.end(media.seekable.length - 1);
          if (e - s > 1) return true;
        }
      } catch (_) {}
      return false;
    }

    // 兜底：整文件拉取转 blob URL（绕过服务器无 Range 支持）
    // 元数据加载后立即预转存，首次点击进度条时 blob 已就绪 → 立刻跳转
    function mpBlobify(root, media, targetTime, wasPaused) {
      if (media.getAttribute('data-mp-busy')) return;
      var url = media.getAttribute('data-mp-url') || media.currentSrc || media.src;
      if (!url) return;
      media.setAttribute('data-mp-busy', '1');
      media.setAttribute('data-mp-url', url);
      var timeEl = root.querySelector('[data-mp="time"]');
      if (targetTime == null && timeEl && !media.getAttribute('data-mp-blob')) {
        timeEl.textContent = '⏳ 预载中...';
      }
      fetch(url).then(function (r) {
        var len = parseInt(r.headers.get('content-length') || '0', 10);
        if (len > 157286400) throw new Error('too large'); // >150MB 不转存
        return r.blob();
      }).then(function (b) {
        var old = media.getAttribute('data-mp-blob');
        if (old) { try { URL.revokeObjectURL(old); } catch (_) {} }
        var objUrl = URL.createObjectURL(b);
        media.setAttribute('data-mp-blob', objUrl);
        var cur = media.currentTime;
        var paused = media.paused;
        media.addEventListener('loadedmetadata', function once() {
          media.removeEventListener('loadedmetadata', once);
          var dest = targetTime != null ? targetTime : cur;
          try {
            if (dest > 0.5) media.currentTime = dest;
          } catch (_) {}
          if (!paused && targetTime == null) media.play().catch(function () {});
          else if (!wasPaused && targetTime != null) media.play().catch(function () {});
          media.removeAttribute('data-mp-busy');
          mpRefresh(root, media);
        });
        media.src = objUrl;
        media.load();
      }).catch(function () {
        if (timeEl && targetTime != null) timeEl.textContent = '⚠ 此源不支持拖动';
        media.removeAttribute('data-mp-busy');
      });
    }

    // 主动刷新一次播放器 UI（预转存完成/跳转后同步时间显示）
    function mpRefresh(root, media) {
      var timeEl = root.querySelector('[data-mp="time"]');
      var fill = root.querySelector('.mp-fill');
      if (timeEl) timeEl.textContent = mpFmt(media.currentTime) + ' / ' + mpFmt(media.duration);
      if (fill && isFinite(media.duration) && media.duration > 0) {
        fill.style.width = Math.min(100, media.currentTime / media.duration * 100) + '%';
      }
    }

    function mpSeek(root, media, clientX) {
      var bar = root.querySelector('[data-mp="seek"]');
      if (!bar || !isFinite(media.duration) || media.duration <= 0) return;
      var r = bar.getBoundingClientRect();
      var p = r.width ? (clientX - r.left) / r.width : 0;
      var target = Math.max(0, Math.min(1, p)) * media.duration;
      if (!mpSeekable(media)) {
        mpBlobify(root, media, target, media.paused);
        return;
      }
      media.currentTime = target;
      mpRefresh(root, media);
    }

    var mpDrag = null;

    document.addEventListener('pointerdown', function (e) {
      if (!e.target || !e.target.closest) return;
      var bar = e.target.closest('[data-mp="seek"]');
      if (!bar) return;
      var root = mpRoot(bar);
      var media = root && root.querySelector('audio,video');
      if (!media) return;
      mpDrag = { root: root, media: media };
      mpSeek(root, media, e.clientX);
      e.preventDefault();
    });

    document.addEventListener('pointermove', function (e) {
      if (mpDrag) mpSeek(mpDrag.root, mpDrag.media, e.clientX);
    });

    ['pointerup', 'pointercancel'].forEach(function (ev) {
      document.addEventListener(ev, function () { mpDrag = null; });
    });

    document.addEventListener('click', function (e) {
      if (!e.target || !e.target.closest) return;
      var act = e.target.closest('[data-mp]');
      if (!act) return;
      var type = act.getAttribute('data-mp');
      var root = mpRoot(act);
      if (!root) return;
      if (type === 'screen') {
        var media = root.querySelector('audio,video');
        if (media) { if (media.paused) media.play(); else media.pause(); }
        return;
      }
      if (type === 'toggle') {
        var m2 = root.querySelector('audio,video');
        if (m2) { if (m2.paused) m2.play(); else m2.pause(); }
        return;
      }
      if (type === 'mute') {
        var m3 = root.querySelector('audio,video');
        if (m3) m3.muted = !m3.muted;
        return;
      }
      if (type === 'full') {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (root.requestFullscreen) root.requestFullscreen();
      }
    });
  }

  window.PixelMD = { render: render, escapeHtml: escapeHtml };
})(window);