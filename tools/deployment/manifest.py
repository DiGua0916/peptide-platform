#!/usr/bin/env python3
"""组件清单哈希：和店铺端 Hq_Connector::manifest_hash() 同一个算法（改了要两边一起改）。

算法：组件目录下除“不进发布包的目录”以外的全部文件，按相对路径（字节序）排序，
逐个拼 “相对路径 \\0 文件的 sha256 \\n”，再整体取 sha256。

  manifest.py from-release <prepare_release 生成的 manifest.json>   → {"components": {组件: {version, hash, files}}}
  manifest.py dir <目录>                                           → {"hash": …, "files": …}
"""

import hashlib
import json
import os
import re
import sys
import zipfile

COMPONENTS = {
    "peptide-core": "wp-content/plugins/peptide-core/",
    "peptide-hq": "wp-content/plugins/peptide-hq/",
    "peptide-ops": "wp-content/plugins/peptide-ops/",
    "peptide-panapay": "wp-content/plugins/peptide-panapay/",
    "peptide-site": "wp-content/themes/peptide-site/",
}
MAIN = {"peptide-core": "peptide-core.php", "peptide-hq": "peptide-hq.php", "peptide-ops": "peptide-ops.php", "peptide-panapay": "peptide-panapay.php", "peptide-site": "style.css"}
SKIP_DIRS = {"tests", "node_modules", "vendor", "uploads", "cache", "backups", "private", "__pycache__"}


def combine(pairs):
    """pairs: [(相对路径, 文件 sha256)]"""
    text = "".join(f"{rel}\0{digest}\n" for rel, digest in sorted(pairs, key=lambda p: p[0].encode()))
    return hashlib.sha256(text.encode()).hexdigest()


def hash_dir(root):
    pairs = []
    for base, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d.lower() not in SKIP_DIRS and not os.path.islink(os.path.join(base, d))]
        for name in files:
            full = os.path.join(base, name)
            with open(full, "rb") as handle:
                pairs.append((os.path.relpath(full, root).replace(os.sep, "/"), hashlib.sha256(handle.read()).hexdigest()))
    return {"files": len(pairs), "hash": combine(pairs)}


def version_of(text):
    match = re.search(r"^[ \t/*#@]*Version:\s*(\S+)", text, re.M)
    return match.group(1) if match else ""


def from_release(manifest_path):
    """prepare_release.py 的清单（每个文件的 sha256）→ 每个组件的哈希；版本号从同目录的发布包里读主文件。"""
    manifest = json.load(open(manifest_path, encoding="utf-8"))
    archive = os.path.join(os.path.dirname(manifest_path), manifest["archive"])
    out = {}
    with zipfile.ZipFile(archive) as bundle:
        for name, prefix in COMPONENTS.items():
            pairs = [(f["path"][len(prefix):], f["sha256"]) for f in manifest["files"] if f["path"].startswith(prefix)]
            if pairs:
                out[name] = {"version": version_of(bundle.read(prefix + MAIN[name]).decode("utf-8", "replace")), "hash": combine(pairs), "files": len(pairs)}
    return {"git_commit": manifest["git_commit"], "components": out}


if __name__ == "__main__":
    if len(sys.argv) == 3 and sys.argv[1] == "from-release":
        print(json.dumps(from_release(sys.argv[2]), ensure_ascii=False, indent=1))
    elif len(sys.argv) == 3 and sys.argv[1] == "dir":
        print(json.dumps(hash_dir(sys.argv[2])))
    else:
        sys.exit(__doc__)
