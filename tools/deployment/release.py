#!/usr/bin/env python3
"""M1-5 发布脚本：一条命令从打包到上线，每一步有自动检查，失败自动回滚。

    release.py plan     --inventory <文件> --env sandbox|production [--ref HEAD]
    release.py run      --inventory <文件> --env sandbox --label 2026.11.13-1 --modules M1,M4 [--ref HEAD]
    release.py run      --inventory <文件> --env production --label … --modules … --confirmed <同一个标签>
    release.py rollback --inventory <文件> --env … --label …

流水线（开发文档 11.2）：
  G0 打包     从已推送的提交打包；本机跑默认测试套件；发布包在服务器上解包并做 PHP 语法检查
  G1 沙盒     整包替换沙盒三个站 → 健康检查 → critical-path
  G2 试点店   只发试点店的前台、后台两份 → 生产验收脚本完整版（下一张测试单走到付款页）→ 观察若干分钟
  G3 全部店   其余店逐家替换，每家跑生产验收快速版；然后主店、两份开店模板、总后台
  G4 收尾     每份副本的清单哈希和发布包比对；写发布记录；清理

任何一步失败：当前这一组副本立刻换回旧目录，后面的不再动，已经成功的保持新版本（除非加 --rollback-all-on-failure）。
生产环境必须带 --confirmed <版本标签>：表示平台所有者已经确认这次发布。脚本自己不会去问。

inventory 文件不入库（主机、私钥路径），格式见 release-inventory.example.json。只用标准库。
"""

import argparse
import json
import os
import shlex
import subprocess
import sys
import time
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, HERE)
import manifest as manifest_lib  # noqa: E402

STORE_COMPONENTS = ["plugins/peptide-core", "plugins/peptide-panapay", "plugins/peptide-ops", "themes/peptide-site"]
HQ_COMPONENTS = ["plugins/peptide-hq", "plugins/peptide-panapay", "plugins/peptide-ops"]
REMOTE_DIR = "/var/tmp/vialcove-release-tools"


class Failed(Exception):
    pass


def say(text):
    print(time.strftime("[%H:%M:%S] ") + text, flush=True)


# ---------- 远端 ----------
class Remote:
    """一台服务器。所有远端操作都从这里走，离线测试时换成假的。"""

    def __init__(self, name, cfg):
        self.name, self.cfg = name, cfg

    def _ssh(self):
        return ["ssh", "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "ConnectTimeout=20", "-i", self.cfg["identity"], f"{self.cfg['user']}@{self.cfg['host']}"]

    def run(self, command, check=True, timeout=1800):
        done = subprocess.run(self._ssh() + [command], capture_output=True, text=True, timeout=timeout)
        if check and done.returncode != 0:
            raise Failed(f"[{self.name}] {command[:120]} → 退出码 {done.returncode}：{(done.stderr or done.stdout)[-500:]}")
        return done.returncode, done.stdout, done.stderr

    def put(self, local, remote):
        done = subprocess.run(["scp", "-q", "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-i", self.cfg["identity"], local, f"{self.cfg['user']}@{self.cfg['host']}:{remote}"], capture_output=True, text=True, timeout=1800)
        if done.returncode != 0:
            raise Failed(f"[{self.name}] 上传 {os.path.basename(local)} 失败：{done.stderr[-300:]}")

    def agent(self, *args, check=True):
        return self.run("sudo bash " + REMOTE_DIR + "/release_agent.sh " + " ".join(shlex.quote(str(a)) for a in args), check=check)


# ---------- 计划 ----------
def slug_of(url):
    host = urllib.parse.urlparse(url).netloc
    return host.split(".")[0] if host else ""


def production_units(registry, cfg, templates):
    """从总后台的店铺注册表生成发布顺序。纯函数。

    registry: wp pchq-release stores 的输出；cfg: inventory 里的 production；templates: {"web": 路径, "admin": 路径}
    返回 [unit]，unit = {name, stage, copies:[{key, host, root, kind, owner}], store_url, management_url, site_id, check}
    """
    stores = []
    for site in registry:
        slug = slug_of(site.get("management_url", ""))
        if not site.get("active", True) or not slug:
            continue
        stores.append({"slug": slug, "site": site})
    known = {s["slug"] for s in stores}
    pilot, main = cfg["pilot"], cfg["main_slug"]
    if pilot not in known:
        raise Failed(f"试点店 {pilot} 不在总后台的店铺注册表里（现有：{sorted(known)}）。")
    if main not in known:
        raise Failed(f"主店 {main} 不在总后台的店铺注册表里。")
    # 2026-11-14：平台所有者定试点店用主店 s0001（原来不允许）。试点就是主店时，主店第一个发、完整检查加观察，后面不再重复。

    def unit(entry, stage, check):
        slug, site = entry["slug"], entry["site"]
        copies = []
        for side in ("web", "admin"):
            rule = cfg[side]
            root = rule["main"] if slug == main else rule["tenant"].format(slug=slug)
            copies.append({"key": f"{slug}/{side}", "host": rule["host"], "root": root, "kind": "store", "owner": "keep"})
        return {"name": slug, "stage": stage, "copies": copies, "store_url": site.get("public_url", "").rstrip("/"), "management_url": site.get("management_url", "").rstrip("/"), "site_id": site.get("id"), "check": check}

    by_slug = {s["slug"]: s for s in stores}
    units = [unit(by_slug[pilot], "G2", "prod_full")]
    for slug in sorted(known - {pilot, main}):
        units.append(unit(by_slug[slug], "G3", "prod_quick"))
    if pilot != main:
        units.append(unit(by_slug[main], "G3", "prod_quick"))
    template_copies = [{"key": f"template/{side}", "host": cfg[side]["host"], "root": templates[side], "kind": "store", "owner": "keep"} for side in ("web", "admin") if templates.get(side)]
    if template_copies:
        units.append({"name": "templates", "stage": "G3", "copies": template_copies, "check": "none"})
    units.append({"name": "hq", "stage": "G3", "copies": [{"key": "hq", "host": cfg["hq"]["host"], "root": cfg["hq"]["root"], "kind": "hq", "owner": "keep"}], "management_url": cfg["hq"].get("url", "").rstrip("/"), "check": "hq"})
    return units


def components_for(copy):
    return HQ_COMPONENTS if copy["kind"] == "hq" else STORE_COMPONENTS


def expected_hashes(components):
    """发布包清单 → {plugins/peptide-core: hash}"""
    return {("themes/" if name == "peptide-site" else "plugins/") + name: info["hash"] for name, info in components.items()}


# ---------- 发布 ----------
class Release:
    def __init__(self, args, inventory, remote_factory=Remote):
        self.args = args
        self.env = inventory["environments"][args.env]
        self.remotes = {name: remote_factory(name, cfg) for name, cfg in self.env["hosts"].items()}
        self.label = args.label
        self.record_id = None
        self.results = {}
        self.applied = []       # 已经替换成功、还没回滚的 unit
        self.started = int(time.time())

    # --- 总后台记录 ---
    def hq_wp(self, command, check=True):
        hq = self.env["hq_wp"]
        return self.remotes[hq["host"]].run(f"cd /tmp && sudo -u {shlex.quote(hq['user'])} -H wp --path={shlex.quote(hq['path'])} {command}", check=check)

    def registry(self):
        code, out, err = self.hq_wp("pchq-release stores", check=False)
        if code != 0:
            raise Failed("总后台还没有 pchq-release 命令（peptide-hq 没带发布记录功能），读不到店铺注册表。第一次发布要先把总后台升级上去：" + (err or out)[-200:])
        return json.loads(out.strip().splitlines()[-1])

    def record_start(self, components_file):
        hq = self.env["hq_wp"]
        remote = self.remotes[hq["host"]]
        remote.put(components_file, REMOTE_DIR + "/components.json")
        remote.run(f"chmod 644 {REMOTE_DIR}/components.json")
        code, out, err = self.hq_wp("pchq-release start --label={} --commit={} --modules={} --operator={} --kind={} --manifest={}".format(
            shlex.quote(self.label), shlex.quote(self.commit[:12]), shlex.quote(self.args.modules), shlex.quote(self.args.operator), "emergency" if self.args.emergency else "normal", REMOTE_DIR + "/components.json"), check=False)
        if code != 0:
            message = (err or out).strip()[-300:]
            if "已有发布在进行" in message:
                raise Failed("发布锁被占用：" + message)
            say("提示：总后台没有记下这次发布（" + message + "）。继续，但没有发布锁和发布记录。")
            return
        self.record_id = json.loads(out.strip().splitlines()[-1])["id"]
        say(f"发布记录 #{self.record_id}，已加发布锁")

    def record_site(self, key, state, detail=""):
        self.results[key] = state
        if self.record_id:
            self.hq_wp(f"pchq-release site {self.record_id} {shlex.quote(key)} {state} --detail={shlex.quote(detail[:280])}", check=False)

    def record_finish(self, status, note=""):
        if self.record_id:
            self.hq_wp(f"pchq-release finish {self.record_id} {status} --note={shlex.quote(note[:500])}", check=False)

    # --- G0 ---
    def g0(self):
        say("G0 打包")
        git = lambda *a: subprocess.run(["git", "-C", REPO, *a], capture_output=True, text=True).stdout.strip()
        self.commit = git("rev-parse", self.args.ref + "^{commit}")
        if not self.commit:
            raise Failed(f"找不到提交 {self.args.ref}")
        if not self.args.allow_unpushed and not git("branch", "-r", "--contains", self.commit):
            raise Failed(f"提交 {self.commit[:7]} 还没有推送到远端。发布只从已推送的提交打包。")
        out_dir = os.path.join(self.args.workdir, self.label)
        os.makedirs(out_dir, exist_ok=True)
        done = subprocess.run([sys.executable, os.path.join(HERE, "prepare_release.py"), "--output", out_dir, "--ref", self.commit], capture_output=True, text=True)
        if done.returncode != 0:
            raise Failed("打包失败：" + done.stderr[-400:])
        info = json.loads(done.stdout)
        self.zip, self.manifest_path = info["archive"], info["manifest"]
        self.components = manifest_lib.from_release(self.manifest_path)["components"]
        self.components_file = os.path.join(out_dir, "components.json")
        json.dump({"git_commit": self.commit, "components": self.components}, open(self.components_file, "w"), indent=1)
        say(f"  提交 {self.commit[:7]}，{info['files']} 个文件：" + "，".join(f"{n} {c['version']}" for n, c in self.components.items()))
        if self.args.skip_local_tests:
            say("  本机测试：跳过（--skip-local-tests）")
        else:
            if not (self.args.php and self.args.node):
                raise Failed("要跑本机测试需要 --php 和 --node（或者明确加 --skip-local-tests）。")
            # 测的是被打包的那个提交，不是工作区：导出到临时目录再跑。
            tree = os.path.join(out_dir, "tree")
            subprocess.run(["rm", "-rf", tree]); os.makedirs(tree)
            archive = subprocess.run(["git", "-C", REPO, "archive", self.commit], capture_output=True)
            subprocess.run(["tar", "-x", "-C", tree], input=archive.stdout, check=True)
            test = subprocess.run([self.args.node, os.path.join(tree, "tests", "run-data-observations.cjs"), "--php", self.args.php], capture_output=True, text=True, cwd=tree)
            subprocess.run(["rm", "-rf", tree])
            tail = test.stdout.strip().splitlines()[-1] if test.stdout.strip() else test.stderr[-200:]
            if test.returncode != 0:
                failed = [json.loads(l)["suite"] for l in test.stdout.splitlines() if l.startswith("{") and '"exit_code"' in l and json.loads(l).get("exit_code")]
                raise Failed(f"本机测试没过：{tail}；失败的用例：{failed[:8]}")
            say("  本机测试：" + tail)

    def version_rule(self, latest):
        """被改动的组件必须抬过版本号（同版本号不同内容不允许）。latest：总后台里上一次成功发布的组件清单。"""
        if not latest:
            return []
        same_version_changed = [name for name, c in self.components.items() if name in latest and latest[name]["hash"] != c["hash"] and latest[name]["version"] == c["version"]]
        return same_version_changed

    # --- 上传、解包 ---
    def stage(self, hosts):
        for name in sorted(hosts):
            remote = self.remotes[name]
            remote.run(f"mkdir -p {REMOTE_DIR}/accept && chmod 755 {REMOTE_DIR} {REMOTE_DIR}/accept")
            for local, target in ((os.path.join(HERE, "release_agent.sh"), REMOTE_DIR + "/release_agent.sh"), (self.zip, REMOTE_DIR + "/" + os.path.basename(self.zip))):
                remote.put(local, target)
            code, out, err = remote.agent("stage", REMOTE_DIR + "/" + os.path.basename(self.zip), self.label)
            say(f"  [{name}] {out.strip().splitlines()[-1]}")

    # --- 一组副本 ---
    def reload(self, hosts):
        for name in sorted(hosts):
            self.remotes[name].run("sudo " + self.env["reload"][name])
        time.sleep(self.env.get("reload_wait", 2))

    def apply_unit(self, unit):
        say(f"{unit['stage']} {unit['name']}：替换 {len(unit['copies'])} 份副本")
        done = []
        try:
            for copy in unit["copies"]:
                self.remotes[copy["host"]].agent("apply", self.label, copy["root"], copy.get("owner", "keep"), *components_for(copy))
                done.append(copy)
        except Failed:
            self.rollback_copies(done + [copy])
            raise
        self.reload({c["host"] for c in unit["copies"]})
        self.applied.append(unit)

    def rollback_copies(self, copies):
        for copy in copies:
            self.remotes[copy["host"]].agent("rollback", self.label, copy["root"], *components_for(copy), check=False)
        self.reload({c["host"] for c in copies})

    def rollback_unit(self, unit, reason):
        begin = time.time()
        self.rollback_copies(unit["copies"])
        for copy in unit["copies"]:
            self.record_site(copy["key"], "rolled_back", reason)
        if unit in self.applied:
            self.applied.remove(unit)
        say(f"  已回滚 {unit['name']}（{time.time() - begin:.0f} 秒）")

    # --- 检查 ---
    def check_health(self, unit):
        for item in unit.get("health", []):
            code, out, _ = self.remotes[item["host"]].run("curl -s -o /dev/null -w '%{http_code}' --max-time 40 " + shlex.quote(item["url"]), check=False)
            if out.strip() not in [str(c) for c in item["expect"]]:
                return False, f"{item['url']} 返回 {out.strip() or code}（期望 {item['expect']}）"
        return True, ""

    def check_fatals(self, unit, since):
        for host, logs in unit.get("php_logs", self.env.get("php_logs", {})).items():
            if host not in {c["host"] for c in unit["copies"]}:
                continue
            _, out, _ = self.remotes[host].agent("fatals", since, *logs, check=False)
            count, _, sample = out.strip().partition("\t")
            if count.isdigit() and int(count) > 0:
                return False, f"[{host}] 发布后出现 {count} 条 PHP 致命错误：{sample[:200]}"
        return True, ""

    def check_prod(self, unit, quick):
        command = [sys.executable, "-B", os.path.join(HERE, "prod_check.py"), "--store", unit["store_url"], "--management", unit.get("management_url", "")] + (["--quick"] if quick else ["--gateway", self.env.get("gateway", "")])
        done = subprocess.run(command, capture_output=True, text=True, timeout=900)
        bad = [l for l in done.stdout.splitlines() if l.startswith("FAIL")]
        order = [l.strip() for l in done.stdout.splitlines() if "测试单订单号" in l]
        if order:
            say("  " + order[0])
        return done.returncode == 0, "；".join(bad[:3]) or done.stderr[-200:]

    def check_hq_poll(self, unit):
        if unit.get("site_id") is None:
            return True, ""
        code, out, _ = self.hq_wp("eval " + shlex.quote(f"echo PeptideHq\\Monitor::poll( {int(unit['site_id'])} ) ? 'POLL_OK' : 'POLL_FAIL';"), check=False)
        return "POLL_OK" in out, "总后台轮询这家店失败"

    def check_critical_path(self, host):
        remote = self.remotes[host]
        for local, target in ((os.path.join(REPO, "tools", "sandbox", "critical_path.py"), REMOTE_DIR + "/critical_path.py"), (os.path.join(REPO, "tools", "sandbox", "accept", "cookie.php"), REMOTE_DIR + "/accept/cookie.php")):
            remote.put(local, target)
        remote.run(f"chmod -R a+rX {REMOTE_DIR}")
        code, out, err = remote.run(f"cd {REMOTE_DIR} && python3 critical_path.py --out {REMOTE_DIR}/critical-path.json", check=False, timeout=1500)
        tail = out.strip().splitlines()[-1] if out.strip() else err[-200:]
        bad = [l for l in out.splitlines() if l.startswith("FAIL")]
        say("  critical-path：" + tail)
        return code == 0, "；".join(bad[:3]) or tail

    def check_unit(self, unit, since):
        kind = unit.get("check", "none")
        checks = [lambda: self.check_health(unit), lambda: self.check_fatals(unit, since)]
        if kind == "prod_full":
            checks += [lambda: self.check_prod(unit, quick=False), lambda: self.check_hq_poll(unit)]
        elif kind == "prod_quick":
            checks += [lambda: self.check_prod(unit, quick=True), lambda: self.check_hq_poll(unit)]
        elif kind == "critical_path":
            checks += [lambda: self.check_critical_path(unit["check_host"])]
        for run in checks:
            ok, detail = run()
            if not ok:
                return False, detail
        return True, ""

    def observe(self, unit, minutes, since):
        if minutes <= 0:
            return True, ""
        say(f"  观察 {minutes} 分钟（每分钟看一次致命错误和总后台轮询）")
        for _ in range(minutes):
            time.sleep(60)
            for run in (lambda: self.check_fatals(unit, since), lambda: self.check_hq_poll(unit), lambda: self.check_health(unit)):
                ok, detail = run()
                if not ok:
                    return False, detail
        return True, ""

    # --- G4 ---
    def g4_hashes(self, units):
        want = expected_hashes(self.components)
        bad = []
        for unit in units:
            for copy in unit["copies"]:
                _, out, _ = self.remotes[copy["host"]].agent("hash", copy["root"], *components_for(copy), check=False)
                for line in out.strip().splitlines():
                    comp, digest, _files = (line.split("\t") + ["", ""])[:3]
                    if comp in want and digest != want[comp]:
                        bad.append(f"{copy['key']} {comp}")
        return bad

    # --- 主流程 ---
    def units(self):
        if "units" in self.env:
            return self.env["units"]
        templates = {}
        for side in ("web", "admin"):
            host = self.env["production"][side]["host"]
            key = "source_web_root" if side == "web" else "source_admin_root"
            _, out, _ = self.remotes[host].run("sudo python3 -c " + shlex.quote(f"import json;print(json.load(open('/etc/vialcove-provision/config.json')).get('{key}') or '')"), check=False)
            templates[side] = out.strip()
        return production_units(self.registry(), self.env["production"], templates)

    def run(self):
        if self.env.get("requires_confirmation", self.args.env == "production") and self.args.confirmed != self.label:
            raise Failed("这是生产环境：必须带 --confirmed <版本标签>，表示平台所有者已经确认这次发布。")
        self.g0()
        units = self.units()
        hosts = {c["host"] for u in units for c in u["copies"]} | {self.env["hq_wp"]["host"]}
        self.stage(hosts)
        code, out, _ = self.hq_wp("pchq-release status", check=False)
        latest = (json.loads(out.strip().splitlines()[-1]).get("latest") or {}).get("components") if code == 0 and out.strip() else None
        unbumped = self.version_rule(latest)
        if unbumped:
            message = "内容变了但版本号没抬：" + "、".join(unbumped)
            if not self.args.allow_same_version:
                raise Failed(message + "。抬高版本号后重新打包（沙盒试装可以加 --allow-same-version）。")
            say("  提示：" + message)
        self.record_start(self.components_file)
        if self.env.get("db_snapshot"):
            for host, dbs in self.env["db_snapshot"].items():
                _, out, _ = self.remotes[host].agent("dbsnapshot", self.label, *dbs)
                say(f"  [{host}] 数据库快照：" + "；".join(out.strip().splitlines()))
        failure = None
        for unit in units:
            since = int(time.time())
            try:
                self.apply_unit(unit)
                ok, detail = self.check_unit(unit, since)
                if ok and unit["stage"] == "G2":
                    ok, detail = self.observe(unit, self.args.observe_minutes, since)
            except Failed as error:
                ok, detail = False, str(error)
            if not ok:
                say(f"  失败：{detail}")
                if unit in self.applied:
                    self.rollback_unit(unit, detail)
                else:
                    for copy in unit["copies"]:
                        self.record_site(copy["key"], "failed", detail)
                failure = (unit, detail)
                break
            for copy in unit["copies"]:
                self.record_site(copy["key"], "ok")
            say(f"  {unit['name']} 通过")
        if failure:
            rest = [u["name"] for u in units[units.index(failure[0]) + 1:]]
            if self.args.rollback_all_on_failure:
                for unit in list(reversed(self.applied)):
                    self.rollback_unit(unit, "连带回滚")
            status = "rolled_back" if not self.applied else "failed"
            self.record_finish(status, f"{failure[0]['name']} 失败：{failure[1]}")
            say(f"发布终止。失败的是 {failure[0]['name']}；没有动的：{rest or '无'}；保持新版本的：{[u['name'] for u in self.applied] or '无'}")
            return 1
        say("G4 收尾：比对每份副本的清单哈希")
        bad = self.g4_hashes(units)
        if bad:
            self.record_finish("failed", "哈希不一致：" + "，".join(bad[:10]))
            say("  不一致的副本：" + "，".join(bad))
            return 1
        self.record_finish("done")
        for unit in units:
            for copy in unit["copies"]:
                self.remotes[copy["host"]].agent("prune", copy["root"], self.env.get("keep_backups", 3), check=False)
        for name in sorted(hosts):
            self.remotes[name].agent("cleanup", self.label, check=False)
        copies = sum(len(u["copies"]) for u in units)
        say(f"发布完成：{self.label}，提交 {self.commit[:7]}，{copies} 份副本全部一致，用时 {int(time.time()) - self.started} 秒")
        return 0

    def rollback_all(self):
        units = self.units()
        hosts = {c["host"] for u in units for c in u["copies"]}
        for name in sorted(hosts):
            self.remotes[name].run(f"mkdir -p {REMOTE_DIR}")
            self.remotes[name].put(os.path.join(HERE, "release_agent.sh"), REMOTE_DIR + "/release_agent.sh")
        for unit in reversed(units):
            for copy in unit["copies"]:
                _, out, _ = self.remotes[copy["host"]].agent("rollback", self.label, copy["root"], *components_for(copy), check=False)
                say(f"{copy['key']}：" + "；".join(out.strip().splitlines()))
        self.reload(hosts)
        return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", choices=["plan", "run", "rollback"])
    parser.add_argument("--inventory", required=True)
    parser.add_argument("--env", required=True)
    parser.add_argument("--ref", default="HEAD")
    parser.add_argument("--label", default="")
    parser.add_argument("--modules", default="")
    parser.add_argument("--operator", default="F1")
    parser.add_argument("--confirmed", default="", help="生产环境必填：和 --label 相同，表示平台所有者已确认")
    parser.add_argument("--emergency", action="store_true", help="紧急修复：记录里标紧急（流程不变，沙盒由调用方决定是否先跑）")
    parser.add_argument("--observe-minutes", type=int, default=15)
    parser.add_argument("--php", default="")
    parser.add_argument("--node", default="")
    parser.add_argument("--skip-local-tests", action="store_true")
    parser.add_argument("--allow-unpushed", action="store_true")
    parser.add_argument("--allow-same-version", action="store_true")
    parser.add_argument("--rollback-all-on-failure", action="store_true")
    parser.add_argument("--workdir", default=os.path.join(os.environ.get("TMPDIR", "/tmp"), "vialcove-release"))
    args = parser.parse_args()
    inventory = json.load(open(args.inventory, encoding="utf-8"))
    if args.env not in inventory.get("environments", {}):
        sys.exit(f"inventory 里没有环境 {args.env}")
    if args.command != "plan" and not args.label:
        sys.exit("需要 --label")
    release = Release(args, inventory)
    try:
        if args.command == "plan":
            for unit in release.units():
                print(f"{unit['stage']:3} {unit['name']:10} 检查={unit.get('check', 'none'):13} " + "  ".join(f"{c['key']}@{c['host']}:{c['root']}" for c in unit["copies"]))
            return 0
        if args.command == "rollback":
            return release.rollback_all()
        return release.run()
    except Failed as error:
        say("失败：" + str(error))
        if release.record_id:
            release.record_finish("failed" if release.applied else "aborted", str(error))
        return 1


if __name__ == "__main__":
    sys.exit(main())
