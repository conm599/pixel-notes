/**
 * Pixel Notes - 朗读工坊前端逻辑 v3
 * - 从便签导入（自动清除 Markdown 标记）
 * - 语速调节 + 音色（OpenAI名→Edge中文音色映射）
 * - 逐字卡拉OK字幕（估算时间轴）
 * - SRT 字幕下载 / Canvas 合成带字幕视频下载（MP4/WebM）
 */

(function () {
  'use strict';

  var els = {
    text: document.getElementById('ttsText'),
    charCount: document.getElementById('charCount'),
    voice: document.getElementById('ttsVoice'),
    speed: document.getElementById('ttsSpeed'),
    speedVal: document.getElementById('speedVal'),
    btnSpeak: document.getElementById('btnSpeak'),
    btnClear: document.getElementById('btnClear'),
    result: document.getElementById('ttsResult'),
    audio: document.getElementById('ttsAudio'),
    download: document.getElementById('btnDownload'),
    btnSrt: document.getElementById('btnSrt'),
    btnVideo: document.getElementById('btnVideo'),
    btnVideoDl: document.getElementById('btnVideoDl'),
    recStatus: document.getElementById('recStatus'),
    subtitles: document.getElementById('ttsSubtitles'),
    loading: document.getElementById('ttsLoading'),
    error: document.getElementById('ttsError'),
    notePicker: document.getElementById('notePicker'),
    toast: document.getElementById('toast')
  };

  var toastTimer = null;
  function showToast(msg, type) {
    els.toast.textContent = msg;
    els.toast.className = 'toast ' + (type || 'success');
    els.toast.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { els.toast.style.display = 'none'; }, 2500);
  }

  function saveBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  // ============== 字数统计 ==============
  function updateCount() {
    var len = els.text.value.length;
    els.charCount.textContent = len + ' / 5000';
    els.charCount.className = len > 4700 ? 'tts-charcount tts-charcount-warn' : 'tts-charcount';
  }
  els.text.addEventListener('input', updateCount);

  // ============== 语速滑杆 ==============
  var updSpeed = function () { els.speedVal.textContent = parseFloat(els.speed.value).toFixed(1) + 'x'; };
  els.speed.addEventListener('input', updSpeed);
  updSpeed();

  // ============== Markdown 清洗 ==============
  function mdStrip(md) {
    var t = String(md || '');
    t = t.replace(/```[\s\S]*?```/g, function (m) { return m.replace(/```\w*\n?|```/g, ''); });
    t = t.replace(/`([^`]*)`/g, '$1');
    t = t.replace(/!\[([^\]]*)\]\([^)]*\)/g, '');
    t = t.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1');
    t = t.replace(/^\s{0,3}#{1,6}\s+/gm, '');
    t = t.replace(/^\s*>\s?/gm, '');
    t = t.replace(/^\s*([-*+]|\d+[.)])\s+(\[[ xX]\]\s*)?/gm, '');
    t = t.replace(/(\*\*|__)(.*?)\1/g, '$2');
    t = t.replace(/(\*|_)(.*?)\1/g, '$2');
    t = t.replace(/~~(.*?)~~/g, '$1');
    t = t.replace(/^\s*\|.*\|\s*$/gm, function (r) {
      return r.trim().replace(/^\||\|$/g, '').replace(/\|/g, '，');
    });
    t = t.replace(/^\s*[-: |]+\s*$/gm, '');
    t = t.replace(/^\s*([-*_]\s*){3,}$/gm, '');
    t = t.replace(/\n{3,}/g, '\n\n');
    return t.trim();
  }

  // ============== 从便签导入 ==============
  var notesCache = null;
  fetch('api/notes.php').then(function (r) {
    if (r.status === 401) { window.location.href = 'login.php'; return null; }
    return r.json();
  }).then(function (data) {
    if (!data || !data.success || !data.notes || !data.notes.length) return;
    notesCache = data.notes;
    data.notes.forEach(function (n) {
      var opt = document.createElement('option');
      opt.value = n.id;
      opt.textContent = '📄 ' + (n.title || '无标题');
      els.notePicker.appendChild(opt);
    });
  }).catch(function () {});

  els.notePicker.addEventListener('change', function () {
    var id = parseInt(els.notePicker.value);
    if (!id || !notesCache) return;
    for (var i = 0; i < notesCache.length; i++) {
      if (parseInt(notesCache[i].id) === id) {
        els.text.value = mdStrip(notesCache[i].content).slice(0, 5000);
        updateCount();
        showToast('📄 已导入便签：' + (notesCache[i].title || '无标题'), 'success');
        break;
      }
    }
    els.notePicker.value = '';
  });

  els.btnClear.addEventListener('click', function () {
    els.text.value = '';
    updateCount();
    els.text.focus();
  });

  // ============================================================
  // 时间轴：分词 + 按权重估算（CJK单字/拉丁词/标点各权重不同）
  // ============================================================
  function tokenize(text) {
    var tokens = [];
    var re = /([\u4e00-\u9fff\u3400-\u4dbf\uf900-\ufaff\u3040-\u30ff\uac00-\ud7af])|([A-Za-z0-9]+)|(\s+)|(.)/g;
    var m;
    while ((m = re.exec(text)) !== null) {
      if (m[1]) {
        tokens.push({ text: m[1], weight: 2 });
      } else if (m[2]) {
        tokens.push({ text: m[2], weight: m[2].length + 1 });
      } else if (m[3]) {
        if (tokens.length) tokens[tokens.length - 1].spaceAfter = true;
        else tokens.push({ text: '', weight: 0.2, space: true });
      } else {
        tokens.push({ text: m[4], weight: 0.8 });
      }
    }
    return tokens;
  }

  function makeTokens(text, duration) {
    var tokens = tokenize(text);
    if (!tokens.length || !duration || !isFinite(duration)) return tokens;
    var totalW = 0;
    tokens.forEach(function (t) { totalW += t.weight; });
    var cursor = 0;
    tokens.forEach(function (t) {
      var d = duration * (t.weight / totalW);
      t.start = cursor; t.end = cursor + d;
      cursor = t.end;
    });
    return tokens;
  }

  function tokenDisp(t) { return t.text + (t.spaceAfter ? ' ' : ''); }

  // ---- 字幕分组（SRT 与视频共用）：标点断句或 ~12 字一切 ----
  var PUNCT_RE = /^[，。！？；：、,.!?;:…"'”）】]$/;
  function buildCues(tokens) {
    var cues = [], cur = [], len = 0;
    tokens.forEach(function (t) {
      if (t.space || !t.text) return;
      cur.push(t); len += t.text.length;
      if (PUNCT_RE.test(t.text) || len >= 12) { cues.push(cur); cur = []; len = 0; }
    });
    if (cur.length) cues.push(cur);
    return cues;
  }

  // ---- SRT 时间格式 00:00:01,234 ----
  function srtTime(sec) {
    if (!isFinite(sec)) sec = 0;
    var ms = Math.round(sec * 1000);
    var h = Math.floor(ms / 3600000); ms %= 3600000;
    var m = Math.floor(ms / 60000); ms %= 60000;
    var s = Math.floor(ms / 1000); ms %= 1000;
    var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
    var p3 = function (n) { return (n < 100 ? '0' : '') + (n < 10 ? '0' : '') + n; };
    return p2(h) + ':' + p2(m) + ':' + p2(s) + ',' + p3(ms);
  }

  function buildSRT(text, duration) {
    var tokens = makeTokens(text, duration);
    var cues = buildCues(tokens);
    var out = [];
    cues.forEach(function (cue, i) {
      var start = cue[0].start, end = cue[cue.length - 1].end;
      var line = cue.map(tokenDisp).join('');
      out.push((i + 1) + '\n' + srtTime(start) + ' --> ' + srtTime(end) + '\n' + line + '\n');
    });
    return out.join('\n');
  }

  // ============================================================
  // 页面逐字字幕（span 高亮）
  // ============================================================
  function buildSubtitles(text, duration) {
    els.subtitles.innerHTML = '';
    var tokens = makeTokens(text, duration);
    if (!tokens.length) return null;
    tokens.forEach(function (t, i) {
      if (t.space || !t.text) return;
      var sp = document.createElement('span');
      sp.className = 'tts-token';
      sp.textContent = t.text;
      sp.setAttribute('data-i', i);
      sp.addEventListener('click', function () {
        els.audio.currentTime = t.start + 0.01;
        els.audio.play();
      });
      els.subtitles.appendChild(sp);
    });
    return tokens;
  }

  var activeToken = -1;
  els.audio.addEventListener('timeupdate', function () {
    var tok = els.audio._tokens;
    if (!tok) return;
    var t = els.audio.currentTime;
    var idx = -1;
    for (var i = 0; i < tok.length; i++) {
      if (t >= tok[i].start && t < tok[i].end) { idx = i; break; }
    }
    if (idx === activeToken) return;
    activeToken = idx;
    var spans = els.subtitles.querySelectorAll('.tts-token');
    for (var j = 0; j < spans.length; j++) spans[j].classList.remove('tts-token-active');
    if (idx >= 0) {
      var sp = els.subtitles.querySelector('.tts-token[data-i="' + idx + '"]');
      if (sp) {
        sp.classList.add('tts-token-active');
        var box = els.subtitles;
        box.scrollTo({ top: Math.max(0, sp.offsetTop - box.clientHeight / 2), behavior: 'smooth' });
      }
    }
  });

  // ============================================================
  // 视频合成（Canvas 逐帧渲染卡拉OK字幕 + 音轨 → MediaRecorder）
  // ============================================================
  var VID_W = 1280, VID_H = 720;
  var COL = {
    bg: '#10101c', border: '#3c3c58', header: '#7a7a99',
    text: '#eceaf5', dim: '#6d6d8c', hi: '#ffcc00', bar: '#9d8cff', barBg: '#26263c'
  };

  function pickMime() {
    if (!window.MediaRecorder) return '';
    var list = [
      'video/mp4;codecs="avc1.42E01E,mp4a.40.2"',
      'video/mp4',
      'video/webm;codecs="vp9,opus"',
      'video/webm;codecs="vp8,opus"',
      'video/webm'
    ];
    for (var i = 0; i < list.length; i++) {
      try { if (MediaRecorder.isTypeSupported(list[i])) return list[i]; } catch (e) {}
    }
    return '';
  }

  // 展开成逐字符（含各自时间），供逐字高亮绘制
  function cueChars(cue) {
    var arr = [];
    cue.forEach(function (t) {
      var disp = tokenDisp(t);
      var n = t.text.length;
      if (!n) return;
      for (var k = 0; k < disp.length; k++) {
        var st = t.start + (t.end - t.start) * (k / n);
        var en = t.start + (t.end - t.start) * ((k + 1) / n);
        arr.push({ ch: disp[k], start: st, end: en });
      }
    });
    return arr;
  }

  function drawFrame(c2, cues, t, title) {
    c2.fillStyle = COL.bg;
    c2.fillRect(0, 0, VID_W, VID_H);
    // 像素边框
    c2.fillStyle = COL.border;
    c2.fillRect(24, 24, VID_W - 48, 8);
    c2.fillRect(24, VID_H - 32, VID_W - 48, 8);
    c2.fillRect(24, 24, 8, VID_H - 48);
    c2.fillRect(VID_W - 32, 24, 8, VID_H - 48);
    // 标题
    c2.font = '22px "Microsoft YaHei", "PingFang SC", sans-serif';
    c2.fillStyle = COL.header;
    c2.textAlign = 'left'; c2.textBaseline = 'alphabetic';
    c2.fillText('🔊 朗读工坊 · ' + (title || 'Pixel Notes'), 56, 68);
    // 进度像素块
    var blocks = 16, bx = 56, by = 96, bs = 20, gap = 8;
    var lit = Math.min(blocks, Math.ceil(t / (els.audio.duration || 1) * blocks));
    for (var b = 0; b < blocks; b++) {
      c2.fillStyle = b < lit ? COL.bar : COL.barBg;
      c2.fillRect(bx + b * (bs + gap), by, bs, bs);
    }

    // 当前句 + 高亮字
    var curIdx = -1;
    for (var i = 0; i < cues.length; i++) {
      if (t >= cues[i][0].start) curIdx = i; else break;
    }
    if (curIdx < 0 && cues.length) curIdx = 0;
    if (curIdx >= 0) {
      var chars = cueChars(cues[curIdx]);
      var size = chars.length > 40 ? 36 : 44;
      c2.font = size + 'px "Microsoft YaHei", "PingFang SC", sans-serif';
      var tracking = 2, lh = size + 22;
      var maxW = VID_W - 200;
      // 第一遍：折行
      var lines = [], line = [], w = 0, x = 0;
      chars.forEach(function (cc) {
        var cw = c2.measureText(cc.ch).width + tracking;
        if (x + cw > maxW && line.length) { lines.push(line); line = []; x = 0; }
        cc.x = x; cc.w = cw;
        line.push(cc); x += cw;
      });
      if (line.length) lines.push(line);
      // 第二遍：居中绘制
      var startY = VID_H * 0.42 - (lines.length - 1) * lh / 2;
      var li = 0;
      lines.forEach(function (ln) {
        var lw = ln.length ? ln[ln.length - 1].x + ln[ln.length - 1].w : 0;
        var sx = (VID_W - lw) / 2, y = startY + li * lh;
        ln.forEach(function (cc) {
          var active = t >= cc.start && t < cc.end;
          c2.textAlign = 'left';
          // 复古阴影
          c2.fillStyle = '#000';
          c2.fillText(cc.ch, sx + cc.x + 3, y + 3);
          c2.fillStyle = active ? COL.hi : COL.text;
          c2.fillText(cc.ch, sx + cc.x, y);
          if (active) { c2.fillStyle = COL.hi; c2.fillRect(sx + cc.x, y + 8, cc.w - tracking, 6); }
        });
        li++;
      });
      // 下一句预览
      var next = cues[curIdx + 1];
      if (next) {
        var nt = next.map(tokenDisp).join('');
        c2.font = '24px "Microsoft YaHei", "PingFang SC", sans-serif';
        c2.fillStyle = COL.dim;
        c2.textAlign = 'center';
        var nx = nt.length > 46 ? nt.slice(0, 44) + '…' : nt;
        c2.fillText(nx, VID_W / 2, startY + lines.length * lh + 46);
      }
    }
    // 底部进度条
    var pw = VID_W - 160, py = VID_H - 66;
    c2.fillStyle = COL.barBg; c2.fillRect(80, py, pw, 10);
    c2.fillStyle = COL.bar;
    c2.fillRect(80, py, Math.max(0, Math.min(1, t / (els.audio.duration || 1))) * pw, 10);
    c2.textAlign = 'right';
    c2.font = '18px "Microsoft YaHei", sans-serif';
    c2.fillStyle = COL.dim;
    c2.fillText(srtTime(t).slice(3) + ' / ' + srtTime(els.audio.duration || 0).slice(3), VID_W - 80, py - 10);
  }

  var rec = {
    active: false, ctx: null, srcNode: null, streamDest: null,
    url: null, mime: ''
  };

  function stopRecording(finished) {
    if (!rec.active) return;
    rec.active = false;
    try { els.audio.pause(); } catch (e) {}
    if (rec.recorder && rec.recorder.state !== 'inactive') {
      rec.finished = finished;
      try { rec.recorder.stop(); } catch (e) {}
    }
  }

  els.btnVideo.addEventListener('click', async function () {
    if (rec.active) { stopRecording(false); return; }   // 再点一次=取消
    if (!els.audio.src || !els.audio.duration) {
      showToast('⚠️ 请先生成语音', 'error');
      return;
    }
    var mime = pickMime();
    if (!mime) { showToast('⚠️ 当前浏览器不支持视频录制', 'error'); return; }

    // 音频路由（一次性建立）
    try {
      if (!rec.ctx) {
        var AC = window.AudioContext || window.webkitAudioContext;
        rec.ctx = new AC();
        rec.srcNode = rec.ctx.createMediaElementSource(els.audio);
        rec.streamDest = rec.ctx.createMediaStreamDestination();
        rec.srcNode.connect(rec.ctx.destination);
        rec.srcNode.connect(rec.streamDest);
      }
      await rec.ctx.resume();
    } catch (e) {
      showToast('⚠️ 音频引擎初始化失败', 'error');
      return;
    }

    var tokens = makeTokens(els.audio._lastText || els.text.value.trim(), els.audio.duration);
    var cues = buildCues(tokens);
    if (!cues.length) { showToast('⚠️ 无字幕内容', 'error'); return; }

    // Canvas + 混合流
    var canvas = document.createElement('canvas');
    canvas.width = VID_W; canvas.height = VID_H;
    var c2 = canvas.getContext('2d');
    var stream = canvas.captureStream(30);
    rec.streamDest.stream.getAudioTracks().forEach(function (tr) { stream.addTrack(tr); });

    var chunks = [];
    var mr = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 2500000 });
    rec.recorder = mr; rec.active = true; rec.finished = false;
    mr.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };

    var stopped = new Promise(function (res) { mr.onstop = res; });

    els.btnVideo.textContent = '⏹ 停止并保存';
    els.btnVideoDl.style.display = 'none';
    els.recStatus.style.display = 'block';
    els.audio.controls = false;

    mr.start(250);
    els.audio.currentTime = 0;
    try { await els.audio.play(); } catch (e) { stopRecording(false); }

    var onEnded = function () { setTimeout(function () { stopRecording(true); }, 200); };
    els.audio.addEventListener('ended', onEnded);

    var frameCount = 0;
    (function loop() {
      if (!rec.active) return;
      drawFrame(c2, cues, els.audio.currentTime, els.audio._lastTitle);
      if (++frameCount % 30 === 0) {
        var pct = Math.min(99, Math.round(els.audio.currentTime / els.audio.duration * 100));
        els.recStatus.textContent = '🎬 录制中 ' + pct + '% —— 请保持本页面可见（后台会掉帧）';
      }
      requestAnimationFrame(loop);
    })();

    await stopped;
    els.audio.removeEventListener('ended', onEnded);
    els.audio.controls = true;
    els.btnVideo.textContent = '🎬 生成视频';
    els.recStatus.style.display = 'none';

    if (rec.finished && chunks.length) {
      var ext = mime.indexOf('mp4') >= 0 ? 'mp4' : 'webm';
      if (rec.url) URL.revokeObjectURL(rec.url);
      var blob = new Blob(chunks, { type: mime });
      rec.url = URL.createObjectURL(blob);
      els.btnVideoDl.href = rec.url;
      els.btnVideoDl.setAttribute('download', 'pixel-tts.' + ext);
      els.btnVideoDl.textContent = '⬇ 下载视频 (' + ext.toUpperCase() + ')';
      els.btnVideoDl.style.display = '';
      showToast('🎬 视频已生成（' + ext.toUpperCase() + '）', 'success');
    } else {
      showToast('已取消录制', 'error');
    }
  });

  // ============== SRT 下载 ==============
  els.btnSrt.addEventListener('click', function () {
    if (!els.audio.src || !els.audio.duration) {
      showToast('⚠️ 请先生成语音', 'error');
      return;
    }
    var srt = buildSRT(els.audio._lastText || els.text.value.trim(), els.audio.duration);
    saveBlob(new Blob([srt], { type: 'text/plain;charset=utf-8' }), 'pixel-tts.srt');
    showToast('💬 SRT 字幕已下载', 'success');
  });

  // ============== 生成语音 ==============
  var busy = false;
  els.btnSpeak.addEventListener('click', async function () {
    if (busy) return;
    if (rec.active) { showToast('⚠️ 正在录制视频，请先停止', 'error'); return; }
    var text = els.text.value.trim();
    if (!text) { showToast('⚠️ 请先输入要朗读的文本', 'error'); els.text.focus(); return; }

    busy = true;
    els.btnSpeak.disabled = true;
    els.error.style.display = 'none';
    els.result.style.display = 'none';
    els.loading.style.display = 'block';

    try {
      var resp = await fetch('api/tts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          text: text,
          voice: els.voice.value,
          speed: parseFloat(els.speed.value)
        })
      });

      if (resp.status === 401) { window.location.href = 'login.php'; return; }

      var ctype = resp.headers.get('Content-Type') || '';
      if (ctype.indexOf('audio/') !== 0) {
        var j = {};
        try { j = await resp.json(); } catch (e) {}
        throw new Error(j.message || ('语音服务异常 (HTTP ' + resp.status + ')'));
      }

      var blob = await resp.blob();
      if (!blob.size) throw new Error('返回了空音频');

      var url = URL.createObjectURL(blob);
      els.audio.src = url;
      els.download.href = url;
      els.download.style.display = '';
      els.btnVideoDl.style.display = 'none';
      if (rec.url) { URL.revokeObjectURL(rec.url); rec.url = null; }
      els.result.style.display = 'block';
      els.loading.style.display = 'none';

      els.audio._lastText = text;
      els.audio._lastTitle = (els.notePicker.options[0] && '') || '';
      els.audio._tokens = null;
      els.audio.addEventListener('loadedmetadata', function build() {
        els.audio.removeEventListener('loadedmetadata', build);
        els.audio._tokens = buildSubtitles(text, els.audio.duration) || null;
      });

      showToast('✅ 语音已生成（可下载 MP3 / SRT / 视频）', 'success');
      els.result.scrollIntoView({ behavior: 'smooth' });

    } catch (e) {
      els.loading.style.display = 'none';
      els.error.textContent = '❌ ' + (e.message || '生成失败，请稍后再试');
      els.error.style.display = 'block';
    } finally {
      busy = false;
      els.btnSpeak.disabled = false;
    }
  });

  updateCount();
})();