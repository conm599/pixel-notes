/**
 * Pixel Notes - AI 透明反代 Workers 脚本
 *
 * 用途：部署到你自己的 Cloudflare Workers 后，浏览器即可直连任意
 *       OpenAI 兼容接口（绕过 CORS），完全不经过 Pixel Notes 平台。
 *
 * 部署方法：
 *   1. 打开 https://dash.cloudflare.com → Workers & Pages → Create Worker
 *   2. 把本文件全部内容粘贴进去，保存并部署（Deploy）
 *   3. 把你的 Worker 地址（形如 https://xxx.your-name.workers.dev）
 *      填到 Pixel Notes 的「AI 设置 → 透明代理（可选）」里
 *
 * 用法：把目标完整 URL 拼在代理地址后面即可，例如：
 *   https://你的worker.workers.dev/https://api.example.com/v1/chat/completions
 *
 * 说明：请求头（含你的 API Key 的 Authorization）原样转发到目标接口，
 *       响应原样返回并附加 CORS 头。脚本不记录、不存储任何内容。
 */

export default {
  async fetch(request) {
    const cors = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization',
      'Access-Control-Max-Age': '86400',
    };

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: cors });
    }

    const u = new URL(request.url);
    // 路径即目标地址：/https://api.example.com/v1/...
    let target = decodeURIComponent(u.pathname.slice(1)) + u.search;

    if (!/^https?:\/\//i.test(target)) {
      return new Response(
        'Pixel Notes 透明反代：把目标完整 URL 放在路径里，如 /https://api.example.com/v1/chat/completions',
        { status: 400, headers: { ...cors, 'Content-Type': 'text/plain; charset=utf-8' } }
      );
    }

    // 只放行 http/https，且目标必须是公网常见端口
    try {
      const t = new URL(target);
      if (t.protocol !== 'https:' && t.protocol !== 'http:') {
        return new Response('仅支持 http/https 目标', { status: 400, headers: cors });
      }
      if (t.port !== '' && t.port !== '80' && t.port !== '443') {
        return new Response('仅支持 80/443 端口', { status: 400, headers: cors });
      }
      target = t.toString();
    } catch (e) {
      return new Response('目标 URL 无效', { status: 400, headers: cors });
    }

    const fwdHeaders = new Headers();
    fwdHeaders.set('Content-Type', request.headers.get('Content-Type') || 'application/json');
    const auth = request.headers.get('Authorization');
    if (auth) fwdHeaders.set('Authorization', auth);

    try {
      const resp = await fetch(target, {
        method: request.method,
        headers: fwdHeaders,
        body: (request.method === 'GET' || request.method === 'HEAD') ? undefined : request.body,
      });
      const outHeaders = new Headers(resp.headers);
      for (const [k, v] of Object.entries(cors)) outHeaders.set(k, v);
      return new Response(resp.body, { status: resp.status, headers: outHeaders });
    } catch (e) {
      return new Response('转发失败：' + (e && e.message ? e.message : '网络错误'), {
        status: 502,
        headers: { ...cors, 'Content-Type': 'text/plain; charset=utf-8' },
      });
    }
  },
};
