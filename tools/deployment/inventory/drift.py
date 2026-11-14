#!/usr/bin/env python3
"""M1-1 版本盘点：比对生产上每份代码副本与仓库某个提交的文件哈希。

只读：远端只跑 collect.sh（ls / find / sha256sum），本地只读 git 对象。

用法：
  drift.py collect --hosts hosts.json --out <目录>      采集两台机器，写 raw-<role>.tsv
  drift.py report  --raw <目录> --ref HEAD --out <目录>   生成 drift.json 和 drift.md
  drift.py check   --raw <目录> --ref HEAD               有漂移时退出码 1（给 release.py 的 G4 用）

hosts.json 不入库（见 hosts.example.json），里面只有主机、用户和私钥路径。
"""

import argparse
import collections
import hashlib
import json
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[2]
COMPONENTS = {
    "peptide-core": "wp-content/plugins/peptide-core/",
    "peptide-hq": "wp-content/plugins/peptide-hq/",
    "peptide-panapay": "wp-content/plugins/peptide-panapay/",
    "peptide-ops": "wp-content/plugins/peptide-ops/",
    "peptide-site": "wp-content/themes/peptide-site/",
    "mu-plugins": "tools/deployment/mu-plugins/",
}
# 与 prepare_release.py 一致：这些目录不进发布包，线上没有它们不算缺失。
EXCLUDED_DIRS = {"tests", "node_modules", "vendor", "uploads", "cache", "backups", "private", "__pycache__"}
# 每类副本应该有哪些组件。
EXPECTED = {
    "store": ["peptide-core", "peptide-panapay", "peptide-ops", "peptide-site"],
    "hq": ["peptide-hq", "peptide-panapay", "peptide-ops"],
}
STATUS_TEXT = {
    "same": "一致",
    "eol": "仅换行符不同",
    "older": "落后（内容等于仓库的旧版本）",
    "other-branch": "内容在其他分支的提交里，不在比对的提交里",
    "unknown": "生产独有的改动（仓库任何提交里都没有）",
    "missing": "仓库有、线上缺",
    "extra-history": "线上多出：仓库曾有、现已删除",
    "extra-unknown": "线上多出：仓库从未有过",
}


def git(*args, data=None):
    return subprocess.run(["git", "-C", str(REPO), *args], check=True, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout


def sha(data):
    return hashlib.sha256(data).hexdigest()


def variants(data):
    """原样、全 LF、全 CRLF 三种哈希；仓库在 Windows 上开发，线上可能只差换行符。"""
    lf = data.replace(b"\r\n", b"\n")
    return sha(data), sha(lf), sha(lf.replace(b"\n", b"\r\n"))


class Blobs:
    def __init__(self):
        self.cache = {}

    def variants(self, blob):
        if blob not in self.cache:
            self.cache[blob] = variants(git("cat-file", "blob", blob))
        return self.cache[blob]


def reference(ref, blobs):
    """{component: {relpath: (raw, lf, crlf)}}，以及是否属于发布包。"""
    commit = git("rev-parse", "--verify", ref + "^{commit}").decode().strip()
    out = {name: {} for name in COMPONENTS}
    listing = git("ls-tree", "-r", "-z", commit, *[p.rstrip("/") for p in COMPONENTS.values()]).decode()
    for row in filter(None, listing.split("\0")):
        meta, path = row.split("\t", 1)
        blob = meta.split()[2]
        for name, prefix in COMPONENTS.items():
            if path.startswith(prefix):
                out[name][path[len(prefix):]] = blobs.variants(blob)
    return commit, out


def packaged(rel):
    return not any(part.lower() in EXCLUDED_DIRS for part in rel.split("/")[:-1])


def history(component, rel, ref_commit, blobs):
    """这个路径在所有分支历史里出现过的内容：{sha256: (commit, date, 是否为 ref 的祖先)}。"""
    path = COMPONENTS[component] + rel
    log = git("log", "--all", "--raw", "--no-abbrev", "--no-renames", "--format=@%H %cI", "--", path).decode()
    found, commit, date = {}, None, None
    for line in log.splitlines():
        if line.startswith("@"):
            commit, date = line[1:].split(" ", 1)
        elif line.startswith(":"):
            blob = line.split()[3]
            if set(blob) == {"0"}:
                continue
            ancestor = subprocess.run(["git", "-C", str(REPO), "merge-base", "--is-ancestor", commit, ref_commit]).returncode == 0
            for digest in blobs.variants(blob):
                # log 由新到旧；保留最新的那次提交，祖先优先。
                if digest not in found or (ancestor and not found[digest][2]):
                    found[digest] = (commit[:7], date[:10], ancestor)
    return found


def parse_raw(directory):
    copies = collections.OrderedDict()
    for file in sorted(Path(directory).glob("raw-*.tsv")):
        for line in file.read_text(encoding="utf-8", errors="replace").splitlines():
            parts = line.split("\t")
            if parts[0] == "R":
                copies[parts[1]] = {"root": parts[2], "resolved": parts[3], "components": {}, "ops": {}}
            elif parts[0] == "O":
                copies[parts[1]]["ops"] = {"private_dir": parts[2] == "yes", "server_key": parts[3] == "yes", "release_records": int(parts[4] or 0), "plugins_owner": parts[5] if len(parts) > 5 else "", "file_mods_disallowed": len(parts) > 6 and parts[6] == "yes"}
            elif parts[0] == "C":
                copies[parts[1]]["components"][parts[2]] = {} if parts[3] == "present" else None
            elif parts[0] == "F":
                copies[parts[1]]["components"][parts[2]][parts[4]] = parts[3]
    if not copies:
        raise SystemExit("没有找到采集数据（raw-*.tsv）。先运行 collect。")
    return copies


def compare(copies, ref):
    blobs = Blobs()
    commit, refs = reference(ref, blobs)
    hist_cache = {}
    result = {"ref": ref, "commit": commit, "copies": {}, "files": {}}
    for label, copy in copies.items():
        kind = "hq" if label.endswith(":hq") else "store"
        entry = {"root": copy["root"], "resolved": copy["resolved"], "ops": copy["ops"], "kind": kind, "components": {}}
        for component in COMPONENTS:
            remote = copy["components"].get(component)
            expected = component in EXPECTED[kind]
            if remote is None:
                entry["components"][component] = {"present": False, "expected": expected}
                continue
            counts = collections.Counter()
            local = refs[component]
            tree = sha("".join(f"{p}\0{h}\n" for p, h in sorted(remote.items())).encode())
            for rel in sorted(set(local) | set(remote)):
                if component == "mu-plugins" and rel not in local:
                    continue  # MU 目录里还有不属于本仓库的加载器，只比仓库管的那几个。
                have, want = remote.get(rel), local.get(rel)
                if have is None:
                    if not packaged(rel) or component == "mu-plugins":
                        continue
                    status, note = "missing", ""
                elif want and have == want[0]:
                    status, note = "same", ""
                elif want and have in want:
                    status, note = "eol", ""
                else:
                    key = (component, rel)
                    if key not in hist_cache:
                        hist_cache[key] = history(component, rel, commit, blobs)
                    match = hist_cache[key].get(have)
                    if want is None:
                        status = "extra-history" if hist_cache[key] else "extra-unknown"
                        note = f"{match[0]} {match[1]}" if match else ""
                    elif match:
                        status, note = ("older" if match[2] else "other-branch"), f"{match[0]} {match[1]}"
                    else:
                        status, note = "unknown", ""
                counts[status] += 1
                if status != "same":
                    result["files"].setdefault(f"{component}/{rel}", {}).setdefault(f"{status}|{note}|{(have or '')[:12]}", []).append(label)
            entry["components"][component] = {"present": True, "expected": expected, "files": len(remote), "tree": tree[:12], "counts": dict(counts)}
        result["copies"][label] = entry
    return result


def drifted(result):
    return bool(result["files"]) or any(c["expected"] and not c["present"] for copy in result["copies"].values() for c in copy["components"].values())


def markdown(result, generated):
    copies = result["copies"]
    lines = [
        f"# M1-1 版本盘点：漂移报告（{generated}）",
        "",
        f"比对基准：提交 `{result['commit'][:7]}`（`{result['ref']}`）。采集方式：只读（`tools/deployment/inventory/collect.sh`），没有改动服务器。",
        "",
        f"共 {len(copies)} 份副本：" + "、".join(f"`{k}`" for k in copies) + "。",
        "",
        "## 1. 每份副本的结果",
        "",
        "每格是“与仓库不一致的文件数 / 该组件文件总数”；`—` 表示这份副本没有这个组件。",
        "",
        "| 副本 | 路径 | " + " | ".join(c for c in COMPONENTS) + " |",
        "| --- | --- | " + " | ".join("---" for _ in COMPONENTS) + " |",
    ]
    for label, copy in copies.items():
        cells = []
        for name in COMPONENTS:
            comp = copy["components"][name]
            if not comp["present"]:
                cells.append("**缺**" if comp["expected"] else "—")
                continue
            bad = sum(v for k, v in comp["counts"].items() if k != "same")
            cells.append(f"{bad} / {comp['files']}" if bad else f"0 / {comp['files']}")
        lines.append(f"| `{label}` | `{copy['resolved']}` | " + " | ".join(cells) + " |")
    lines += ["", "## 2. 副本之间是否一致", "", "同一组件按整目录哈希分组；只有一组说明所有副本互相一致。", ""]
    for name in COMPONENTS:
        groups = collections.defaultdict(list)
        for label, copy in copies.items():
            comp = copy["components"][name]
            if comp["present"]:
                groups[comp["tree"]].append(label)
        if not groups:
            continue
        lines.append(f"- **{name}**：{len(groups)} 组")
        if len(groups) > 1:
            for tree, labels in sorted(groups.items(), key=lambda kv: -len(kv[1])):
                lines.append(f"  - `{tree}`：{'、'.join(labels)}")
    lines += ["", "## 3. 运维代理（peptide-ops）覆盖情况", "", "| 副本 | 装了代理 | 配了私有目录 | 配了独立密钥 | 已有发布记录数 | plugins 目录属主:权限 | 禁止文件修改 |", "| --- | --- | --- | --- | --- | --- | --- |"]
    for label, copy in copies.items():
        ops = copy["ops"]
        yes = lambda v: "是" if v else "否"
        lines.append(f"| `{label}` | {yes(copy['components']['peptide-ops']['present'])} | {yes(ops.get('private_dir'))} | {yes(ops.get('server_key'))} | {ops.get('release_records', 0)} | `{ops.get('plugins_owner', '')}` | {yes(ops.get('file_mods_disallowed'))} |")
    by_status = collections.defaultdict(list)
    for path, variants_ in sorted(result["files"].items()):
        for key, labels in variants_.items():
            status, note, digest = key.split("|")
            by_status[status].append((path, note, digest, labels))
    lines += ["", "## 4. 不一致的文件", ""]
    if not by_status:
        lines.append("没有：所有副本与基准提交逐文件一致。")
    for status in STATUS_TEXT:
        rows = by_status.get(status)
        if not rows or status == "same":
            continue
        lines += [f"### {STATUS_TEXT[status]}（{len(rows)} 项）", "", "| 文件 | 哪些副本 | 线上哈希 | 对应提交 |", "| --- | --- | --- | --- |"]
        for path, note, digest, labels in rows:
            where = "全部" if len(labels) == len(copies) else (f"{len(labels)} 份：" + "、".join(labels))
            lines.append(f"| `{path}` | {where} | `{digest}` | {note or '—'} |")
        lines.append("")
    return "\n".join(lines) + "\n"


def cmd_collect(args):
    hosts = json.loads(Path(args.hosts).read_text(encoding="utf-8"))["hosts"]
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    script = (HERE / "collect.sh").read_bytes()
    for host in hosts:
        command = ["ssh", "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "ConnectTimeout=15", "-i", host["identity"], f"{host['user']}@{host['host']}", "bash -s"]
        done = subprocess.run(command, input=script, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if done.returncode != 0:
            raise SystemExit(f"采集 {host['role']} 失败：{done.stderr.decode(errors='replace')[-400:]}")
        (out / f"raw-{host['role']}.tsv").write_bytes(done.stdout)
        count = sum(1 for line in done.stdout.splitlines() if line.startswith(b"F\t"))
        print(f"{host['role']}: {count} 个文件")


def cmd_report(args):
    result = compare(parse_raw(args.raw), args.ref)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "drift.json").write_text(json.dumps(result, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    (out / "drift.md").write_text(markdown(result, args.date), encoding="utf-8")
    print(f"副本 {len(result['copies'])} 份，不一致文件 {len(result['files'])} 个 → {out / 'drift.md'}")


def cmd_check(args):
    result = compare(parse_raw(args.raw), args.ref)
    if drifted(result):
        print(f"有漂移：{len(result['files'])} 个文件与 {result['commit'][:7]} 不一致。")
        return 1
    print("全部副本与基准提交一致。")
    return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="cmd", required=True)
    c = sub.add_parser("collect"); c.add_argument("--hosts", required=True); c.add_argument("--out", required=True); c.set_defaults(fn=cmd_collect)
    r = sub.add_parser("report"); r.add_argument("--raw", required=True); r.add_argument("--ref", default="HEAD"); r.add_argument("--out", required=True); r.add_argument("--date", default=""); r.set_defaults(fn=cmd_report)
    k = sub.add_parser("check"); k.add_argument("--raw", required=True); k.add_argument("--ref", default="HEAD"); k.set_defaults(fn=cmd_check)
    args = parser.parse_args()
    return args.fn(args) or 0


if __name__ == "__main__":
    sys.exit(main())
