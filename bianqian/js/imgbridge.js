/**
 * 便签↔图床联动桥（唯一联动入口）
 * 流程：同意检查 → 上传到图床「便签」夹 → 永久分享 → 组 i.php 直链
 * 三入口汇入本模块：编辑器粘贴(PC) / 编辑器「🖼图片」按钮(三端) / AI 对话框粘贴+🖼按钮
 * 依赖：index.php 注入 JSON 数据块 #imgBridgeCfg = { tuchangBase, policyVer, policyHtml }（CSP 禁内联脚本）；app.js 解析后经 init 注入
 */
var ImgBridge = (function () {
    'use strict';

    var ctx = null;          // init 注入的运行上下文
    var csrf = null;         // 图床 CSRF token（会话级缓存）
    var busyCount = 0;       // 在途上传数（保存防竞态）

    function init(c) {
        ctx = c;
        // 文档级粘贴委托：覆盖编辑器 + 所有动态创建的 AI 对话框 textarea
        document.addEventListener('paste', onPaste);
        // AI 对话框动态注入「🖼」附件按钮（focus 时跟随出现）
        document.addEventListener('focusin', onFocusIn);
    }

    function isBusy() { return busyCount > 0; }

    // ---------- 基础工具 ----------
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function stamp() {
        var d = new Date();
        return '' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds());
    }
    function imgName() {
        return '便签-' + stamp();
    }
    function fetchTimeout(url, opts, ms) {
        opts = opts || {};
        if (typeof AbortController !== 'undefined') {
            var ac = new AbortController();
            opts.signal = ac.signal;
            var t = setTimeout(function () { ac.abort(); }, ms || 8000);
            return fetch(url, opts).then(function (r) { clearTimeout(t); return r; },
                function (e) { clearTimeout(t); throw e; });
        }
        return fetch(url, opts); // 老浏览器无超时，靠失败降级
    }
    function toast(msg, type) {
        if (ctx && typeof ctx.toast === 'function') ctx.toast(msg, type);
        else if (typeof showToast === 'function') showToast(msg, type);
    }
    function insertAtCursor(ta, text) {
        var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
        ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
        var p = s + text.length;
        ta.selectionStart = ta.selectionEnd = p;
        ta.focus();
        try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (err) { /* 老浏览器忽略 */ }
    }
    function isBridgeTarget(el) {
        if (!el || el.tagName !== 'TEXTAREA') return false;
        return el.id === 'newContent' || (el.classList && el.classList.contains('ai-instruction'));
    }

    // ---------- CSRF（图床会话同源播种） ----------
    function getCsrf() {
        if (csrf) return Promise.resolve(csrf);
        return fetchTimeout(ctx.tuchangBase + 'api.php?action=csrf', { credentials: 'include' }, 8000)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.ok && j.csrf) { csrf = j.csrf; return csrf; }
                throw new Error('无法取得图床会话');
            });
    }

    // ---------- 同意状态（0 未定 / 1 同意 / -1 拒绝；政策升级视为未定） ----------
    function consentState() {
        return fetch(ctx.notesApi, {
            method: 'PUT', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'img_consent_status' })
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.success) throw new Error('auth');
            if (j.consent === 1 && j.ver >= ctx.policyVer) return 1;
            if (j.consent === -1 && j.ver >= ctx.policyVer) return -1;
            return 0;
        });
    }
    function saveConsent(agree) {
        return fetch(ctx.notesApi, {
            method: 'PUT', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'img_consent_set', agree: agree })
        }).then(function (r) { return r.json(); });
    }

    // ---------- 上传 → 分享 → 直链 ----------
    function uploadImg(file) {
        return getCsrf().then(function (tok) {
            var fd = new FormData();
            fd.append('action', 'upload');
            fd.append('csrf_token', tok);
            fd.append('folder', 'notes');
            fd.append('name', imgName());
            fd.append('img', file, file.name || 'paste.png');
            return fetchTimeout(ctx.tuchangBase + 'api.php', { method: 'POST', credentials: 'include', body: fd }, 20000);
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) throw new Error(j.err || '上传失败');
            return j.id;
        });
    }
    function shareImg(id) {
        return getCsrf().then(function (tok) {
            var fd = new FormData();
            fd.append('action', 'share');
            fd.append('csrf_token', tok);
            fd.append('id', id);
            fd.append('hours', '0'); // 永久；删除引用后由保存 diff 转 30 天反悔期
            return fetchTimeout(ctx.tuchangBase + 'api.php', { method: 'POST', credentials: 'include', body: fd }, 10000);
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok || !j.token) throw new Error(j.err || '分享失败');
            return j.token;
        });
    }

    // 对外主入口：file → Promise<{url,name}|null>（null=取消/失败，已提示）
    function pick(file) {
        busyCount++;
        return consentState().then(function (s) {
            if (s === 1) return 1;
            if (s === -1) {
                toast('🖼 未开通图床图片（设置「🖼 图床图片」里可重新开启），图片未插入', 'error');
                return 0;
            }
            return askConsent();
        }).then(function (a) {
            if (a !== 1) return null;
            return uploadImg(file).then(function (id) {
                return shareImg(id).then(function (tok) {
                    return { url: ctx.tuchangBase + 'i.php?id=' + id + '&t=' + tok, name: imgName() };
                });
            });
        }).catch(function (e) {
            var m = (e && e.message === 'auth') ? '请先登录' : (e && e.message ? e.message : '网络异常');
            toast('🖼 图床上传失败：' + m, 'error');
            return null;
        }).then(function (res) {
            busyCount--;
            return res;
        });
    }

    // ---------- 首次同意弹窗（像素风；1 同意 / 0 暂不 / -1 不再询问） ----------
    function askConsent() {
        return new Promise(function (resolve) {
            var mask = document.createElement('div');
            mask.className = 'imgbridge-mask';
            var box = document.createElement('div');
            box.className = 'imgbridge-modal';
            var title = document.createElement('div');
            title.className = 'imgbridge-title';
            title.innerHTML = '<i class="ic ic-image"></i> 使用图床保存图片';
            var body = document.createElement('div');
            body.className = 'imgbridge-body';
            body.innerHTML = ctx.policyHtml; // index.php 服务端提供的规范文案（可信源）
            var btns = document.createElement('div');
            btns.className = 'imgbridge-btns';
            var ok = document.createElement('button');
            ok.type = 'button'; ok.className = 'ib-ok'; ok.innerHTML = '<i class="ic ic-checkall"></i> 同意并继续';
            var later = document.createElement('button');
            later.type = 'button'; later.textContent = '⏭ 暂不（本次跳过）';
            var never = document.createElement('button');
            never.type = 'button'; never.className = 'ib-never'; never.innerHTML = '<i class="ic ic-ban"></i> 不再询问';
            btns.appendChild(ok); btns.appendChild(later); btns.appendChild(never);
            box.appendChild(title); box.appendChild(body); box.appendChild(btns);
            mask.appendChild(box);
            document.body.appendChild(mask);
            btns.addEventListener('click', function (ev) {
                var b = ev.target.closest('button');
                if (!b) return;
                document.body.removeChild(mask);
                var a = b === ok ? 1 : (b === never ? -1 : 0);
                resolve(a);
            });
        }).then(function (a) {
            if (a === 1) {
                toast('✅ 已同意图床使用规范，图片将保存到图床「便签」文件夹', 'success');
                return saveConsent(1).catch(function () { }).then(function () { return 1; });
            }
            if (a === -1) {
                toast('🚫 已关闭图床图片，设置「🖼 图床图片」里可重新开启', 'error');
                return saveConsent(-1).catch(function () { }).then(function () { return -1; });
            }
            toast('⏭ 本次跳过，下次粘贴时再询问', 'info');
            return a; // 暂不：不记忆，下次再问
        });
    }

    // ---------- 设置入口：查看/修改同意状态 ----------
    function manage() {
        consentState().then(function (s) { showManage(s === 1 ? '已同意' : (s === -1 ? '已关闭' : '未设置')); })
            .catch(function () { showManage('未知（请先登录）'); });
    }
    function showManage(stateText) {
        var old = document.getElementById('imgbridge-manage');
        if (old) old.parentNode.removeChild(old);
        var mask = document.createElement('div');
        mask.className = 'imgbridge-mask';
        mask.id = 'imgbridge-manage';
        var box = document.createElement('div');
        box.className = 'imgbridge-modal';
        var title = document.createElement('div');
        title.className = 'imgbridge-title';
        title.innerHTML = '<i class="ic ic-image"></i> 图床图片设置（当前：' + stateText + '）';
        var body = document.createElement('div');
        body.className = 'imgbridge-body';
        body.innerHTML = ctx.policyHtml;
        var btns = document.createElement('div');
        btns.className = 'imgbridge-btns';
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'ib-ok'; ok.innerHTML = '<i class="ic ic-checkall"></i> 同意使用';
        var never = document.createElement('button');
        never.type = 'button'; never.className = 'ib-never'; never.innerHTML = '<i class="ic ic-ban"></i> 不再使用';
        var close = document.createElement('button');
        close.type = 'button'; close.textContent = '完成';
        btns.appendChild(ok); btns.appendChild(never); btns.appendChild(close);
        box.appendChild(title); box.appendChild(body); box.appendChild(btns);
        mask.appendChild(box);
        document.body.appendChild(mask);
        btns.addEventListener('click', function (ev) {
            var b = ev.target.closest('button');
            if (!b) return;
            if (b === ok) { saveConsent(1); toast('✅ 已开启图床图片', 'success'); }
            if (b === never) { saveConsent(-1); toast('🚫 已关闭图床图片', 'error'); }
            document.body.removeChild(mask);
        });
    }

    // ---------- 文档级粘贴委托（编辑器 + AI 对话框 textarea） ----------
    function onPaste(e) {
        var items = (e.clipboardData && e.clipboardData.items) || [];
        var file = null;
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
                var f = items[i].getAsFile();
                if (f) { file = f; break; }
            }
        }
        if (!file) return; // 文本粘贴走原生
        var ta = e.target;
        if (!isBridgeTarget(ta)) return;
        e.preventDefault();
        insertPicking(ta, file);
    }

    // 插入占位 → 上传 → 替换为 markdown 直链（失败清占位）；防重入由 pick 内部 busyCount 负责
    function insertPicking(ta, file) {
        if (busyCount > 0) { toast('⏳ 上一张还在上传，稍等一下喵', 'error'); return; }
        var ph = '⏳[上传中 ' + stamp() + ']';
        insertAtCursor(ta, ph);
        pick(file).then(function (res) {
            if (res) {
                ta.value = ta.value.replace(ph, '![' + res.name + '](' + res.url + ')');
            } else {
                ta.value = ta.value.replace(ph, '');
            }
            try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (err) { /* 忽略 */ }
        });
    }

    // ---------- AI 对话框动态「🖼」按钮 ----------
    function onFocusIn(e) {
        var ta = e.target;
        if (!ta || ta.tagName !== 'TEXTAREA' || !ta.classList || !ta.classList.contains('ai-instruction')) return;
        if (ta.nextSibling && ta.nextSibling.className === 'imgbridge-attach') return;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'imgbridge-attach';
        b.innerHTML = '<i class="ic ic-image"></i>';
        b.title = '插入图片（上传到图床，三端同链路）';
        b.addEventListener('click', function () {
            var inp = document.createElement('input');
            inp.type = 'file';
            inp.accept = 'image/*';
            // 手机 Chrome：未挂载到 DOM 的 <input type=file> 偶发 click 不振起原生选择器 → 挂上再点
            inp.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;pointer-events:none;';
            document.body.appendChild(inp);
            inp.addEventListener('change', function () {
                var f = inp.files && inp.files[0];
                inp.remove();
                if (f) insertPicking(ta, f);
            });
            inp.click();
        });
        if (ta.parentNode) ta.parentNode.insertBefore(b, ta.nextSibling);
    }

    // ---------- 对外 ----------
    return {
        init: init,
        pick: pick,
        insert: insertPicking,
        manage: manage,
        isBusy: isBusy
    };
})();
