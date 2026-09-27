#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Pixel Suite 生产部署脚本（paramiko SFTP 直传 HK VPS）· tools 收编版 2026-09-25

与旧 devpack 版差异：
  - 连接参数全部走环境变量（PSU_HOST/PSU_PORT/PSU_USER/PSU_PASS），仓库内不再出现任何凭据；
    PSU_PASS 缺省时交互式输入（不回显、不落盘）
  - 支持多文件批量上传，末尾汇总成败
  - --check：只做映射 + （PHP）远端 php -l，不落位
  - 落位后自动 chown www-data:www-data（README 部署约定）

用法：
  python tools/deploy-vps.py 本地文件.php ...                 # 按路径关键词自动落位
  python tools/deploy-vps.py 本地文件 tuchang/js/xx.js        # 显式给站点内相对路径
  python tools/deploy-vps.py --check 本地文件.php ...         # 只体检（php -l），不落位
  python tools/deploy-vps.py --list                          # 只看站点根目录，不传输
  python tools/deploy-vps.py --shell "命令"                  # 在生产 VPS 执行一条命令（如 crontab -l）

环境变量：
  PSU_HOST / PSU_PORT / PSU_USER / PSU_PASS   （必须；PSU_PASS 可改为交互输入）

规则（务必遵守）：
  - 便签站点根 = /var/www/hosting/，图床站点根 = /var/www/tuchang/，admini 面板 = /var/www/hosting/admini/
  - 改完 PHP 必须先传 /tmp 跑 php -l，通过后再落位（本地没有 php）
  - 改 JS/CSS 必须 bump 引用 ?v= 版本号（VPS HK 缓存 8H）
  - 图床单图分享机制（s.php/view.php/i.php/share_urls/PREFERRED_HOST）一行不动
  - 涉及数据库结构/新特性：先在测试机全流程验证，再上生产
"""
import os
import sys
import hashlib

try:
    import paramiko
except ImportError:
    print("缺依赖：先 pip install paramiko")
    sys.exit(1)

# 站点根映射：本地路径包含哪个关键词就传到哪个站点
SITES = {
    "bianqian": "/var/www/hosting",
    "tuchang": "/var/www/tuchang",
    "admini": "/var/www/hosting/admini",
}


def conn_params():
    host = os.environ.get("PSU_HOST", "")
    port = int(os.environ.get("PSU_PORT", "22"))
    user = os.environ.get("PSU_USER", "")
    pw = os.environ.get("PSU_PASS", "")
    missing = [k for k, v in (("PSU_HOST", host), ("PSU_USER", user)) if not v]
    if missing:
        print("缺少环境变量：%s（格式见文件头注释）" % ", ".join(missing))
        sys.exit(1)
    if not pw:
        import getpass
        pw = getpass.getpass("PSU_PASS（输入不回显）: ")
    return host, port, user, pw


def connect():
    host, port, user, pw = conn_params()
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(host, port=port, username=user, password=pw, timeout=25)
    return c


def run(c, cmd, timeout=60):
    _, out, err = c.exec_command(cmd, timeout=timeout)
    o = out.read().decode("utf-8", "replace")
    e = err.read().decode("utf-8", "replace")
    return o, e


def guess_remote_path(local):
    """按本地路径里的站点关键词猜远程落位（相对站点根）。"""
    norm = local.replace("\\", "/").lower()
    parts = norm.split("/")
    for key, root in SITES.items():
        if key in parts:
            idx = len(parts) - 1 - parts[::-1].index(key)
            rel = "/".join(parts[idx + 1:])
            return root + ("/" + rel if rel else "")
    print("无法判断站点（本地路径里没有 bianqian/tuchang/admini 关键词），请显式给远程路径：")
    print("  python tools/deploy-vps.py 本地文件 /var/www/tuchang/xxx.php")
    sys.exit(1)


def php_lint(c, local, remote_path):
    """先传 /tmp 跑 php -l，通过才返回 True（文件只到 /tmp/_lint_*，不动正式路径）。"""
    tmp_path = "/tmp/_lint_" + os.path.basename(remote_path)
    sftp = c.open_sftp()
    sftp.put(local, tmp_path)
    sftp.close()
    out, err = run(c, "php -l %s" % tmp_path)
    result = (out + err).strip()
    print(result[:500])
    return "No syntax errors" in result


def deploy_one(c, local, remote, check_only=False):
    if not os.path.isfile(local):
        print("❌ 本地文件不存在：%s" % local)
        return False
    print("→ %s -> %s" % (local, remote))
    if remote.endswith(".php"):
        if not php_lint(c, local, remote):
            print("❌ 语法检查未通过，已中止（文件只传到了 /tmp/_lint_*）")
            return False
        if check_only:
            print("✅ [check] 语法 OK（未落位）")
            return True

    sftp = c.open_sftp()
    sftp.put(local, remote)
    sftp.close()

    # 回读 sha256 校验闭环
    out, _ = run(c, "sha256sum %s" % remote)
    rhash = out.split()[0] if out.strip() else ""
    lhash = hashlib.sha256(open(local, "rb").read()).hexdigest()
    if rhash != lhash:
        print("❌ 校验不一致！远端=%s 本地=%s" % (rhash[:16], lhash[:16]))
        return False

    # README 约定：落位后归主 www-data（PHP-FPM 运行身份）
    run(c, "chown www-data:www-data %s" % remote)
    print("✅ 已落位 + chown www-data  sha256=%s..." % rhash[:16])
    return True


def main():
    args = sys.argv[1:]
    if not args:
        print(__doc__)
        sys.exit(0)

    check_only = False
    if args[0] == "--check":
        check_only = True
        args = args[1:]
    if not args:
        print(__doc__)
        sys.exit(0)

    c = connect()
    try:
        if args[0] == "--list":
            out, _ = run(c, "ls -la /var/www/hosting /var/www/tuchang /var/www/suite-config.php 2>&1")
            print(out)
            return
        if args[0] == "--shell":
            out, err = run(c, args[1], timeout=120)
            print(out)
            if err:
                print(err, file=sys.stderr)
            return

        ok = fail = 0
        i = 0
        while i < len(args):
            local = args[i]
            # 紧跟其后的若是以 /var/www 开头的参数，视为显式远程路径
            explicit = None
            if i + 1 < len(args) and args[i + 1].startswith("/var/www"):
                explicit = args[i + 1]
                i += 1
            remote = explicit or guess_remote_path(local)
            if deploy_one(c, local, remote, check_only=check_only):
                ok += 1
            else:
                fail += 1
            i += 1
        print("\n汇总：成功 %d / 失败 %d%s" % (ok, fail, ("（check 模式，未落位）" if check_only else "")))
        sys.exit(1 if fail else 0)
    finally:
        c.close()


if __name__ == "__main__":
    main()
