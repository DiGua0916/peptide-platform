#!/usr/bin/env python3
"""Export committed, allowlisted runtime source. Never reads a WordPress instance."""

import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import subprocess
import sys
import tempfile
import zipfile

COMPONENTS = {
    "peptide-core": "wp-content/plugins/peptide-core/",
    "peptide-hq": "wp-content/plugins/peptide-hq/",
    "peptide-ops": "wp-content/plugins/peptide-ops/",
    "peptide-panapay": "wp-content/plugins/peptide-panapay/",
    "peptide-site": "wp-content/themes/peptide-site/",
}
EXCLUDED_DIRS = {"tests", "node_modules", "vendor", "uploads", "cache", "backups", "private", "__pycache__"}
FORBIDDEN_SUFFIXES = {".sql", ".sqlite", ".sqlite3", ".db", ".pem", ".key", ".p12", ".pfx", ".log", ".zip", ".7z", ".gz", ".tar", ".bak", ".bkp", ".dump", ".orig", ".ini", ".csv"}
FORBIDDEN_NAMES = {"wp-config.php", "credentials.json", "secrets.json", "auth.json", "config.json", "profiles.json", ".htpasswd"}
SAFE_DATA_TEMPLATES = {"wp-content/plugins/peptide-core/import-templates/batches.csv"}


def git(repo, *args):
    return subprocess.run(["git", "-C", str(repo), *args], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def verify_destination(destination, data):
    if destination.is_symlink():
        raise ValueError("Output symlinks are not allowed: " + str(destination))
    if destination.exists():
        if not destination.is_file() or destination.read_bytes() != data:
            raise ValueError("Existing output differs; choose a fresh directory: " + str(destination))
        return True
    return False


def prepare(repo, output, ref):
    repo = repo.resolve(strict=True)
    output = output.resolve()
    if output == repo or repo in output.parents:
        raise ValueError("Output must be outside the repository.")
    if os.name == "nt" and output.drive.upper() != "D:":
        raise ValueError("Project output on this workstation must use drive D.")
    # Pin immutable commit; archive does not read worktree edits, uploads or junctions.
    commit = git(repo, "rev-parse", "--verify", "--end-of-options", ref + "^{commit}").decode().strip()
    tracked_edits = git(repo, "status", "--porcelain", "--untracked-files=no").decode().strip()
    if tracked_edits:
        raise ValueError("Commit or preserve tracked edits before preparing a release.")
    raw = git(repo, "archive", "--format=zip", commit, *[p.rstrip("/") for p in COMPONENTS.values()])
    files = []
    skipped = []
    counts = {key: 0 for key in COMPONENTS}
    payload = io.BytesIO()
    with zipfile.ZipFile(io.BytesIO(raw)) as source, zipfile.ZipFile(payload, "w", zipfile.ZIP_DEFLATED) as target:
        for member in sorted(source.infolist(), key=lambda item: item.filename):
            if member.is_dir():
                continue
            path = PurePosixPath(member.filename)
            if path.is_absolute() or ".." in path.parts or "\\" in member.filename:
                raise ValueError("Unsafe archive path.")
            component = next((name for name, prefix in COMPONENTS.items() if member.filename.startswith(prefix)), None)
            if component is None:
                raise ValueError("Archive contains a non-allowlisted component.")
            if stat.S_ISLNK(member.external_attr >> 16):
                raise ValueError("Runtime archive contains a symlink: " + member.filename)
            if any(part.lower() in EXCLUDED_DIRS for part in path.parts):
                skipped.append(member.filename)
                continue
            if path.name.lower() in FORBIDDEN_NAMES or path.name.lower().startswith(".env") or (path.suffix.lower() in FORBIDDEN_SUFFIXES and member.filename not in SAFE_DATA_TEMPLATES):
                raise ValueError("Forbidden runtime/config/data file: " + member.filename)
            data = source.read(member)
            # Fixed timestamp and permissions make the source ZIP reproducible.
            info = zipfile.ZipInfo(member.filename, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            target.writestr(info, data)
            counts[component] += 1
            files.append({"path": member.filename, "bytes": len(data), "sha256": sha256(data)})
    if any(count == 0 for count in counts.values()):
        raise ValueError("Missing runtime component.")
    archive_name = "vialcove-source-" + commit[:12] + ".zip"
    manifest_name = "vialcove-source-" + commit[:12] + ".manifest.json"
    manifest = {
        "schema": 1, "git_commit": commit, "archive": archive_name,
        "sha256": sha256(payload.getvalue()), "file_count": len(files),
        "components": counts, "files": files, "excluded_development_paths": skipped,
        "scope": "Committed runtime source only; filename guards do not replace secret review. Not a full site backup or security certification.",
        "not_included": ["WordPress", "WooCommerce", "database", "uploads", "private reports", "wp-config.php", "salts/encryption keys", "Ops config/profiles/jobs", "DNS/TLS", "database-held layouts/custom CSS"],
    }
    output.mkdir(parents=True, exist_ok=True)
    targets = {archive_name: payload.getvalue(), manifest_name: (json.dumps(manifest, ensure_ascii=False, indent=2) + "\n").encode()}
    # Validate all existing destinations before writing either member of the pair.
    for name, data in targets.items():
        destination = output / name
        verify_destination(destination, data)
    for name, data in targets.items():
        destination = output / name
        if verify_destination(destination, data):
            continue
        with tempfile.NamedTemporaryFile(dir=output, delete=False, prefix=".release-", suffix=".tmp") as temp:
            temp.write(data)
            temp_path = Path(temp.name)
        try:
            # Hard-link publishes exclusively without replacing concurrent output.
            try:
                os.link(temp_path, destination)
            except FileExistsError:
                if not verify_destination(destination, data):
                    raise ValueError("Concurrent output disappeared: " + str(destination))
        finally:
            temp_path.unlink()
    for name, data in targets.items():
        if not verify_destination(output / name, data):
            raise ValueError("Output disappeared before final verification: " + name)
    return {"commit": commit, "archive": str(output / archive_name), "manifest": str(output / manifest_name), "sha256": manifest["sha256"], "files": len(files), "components": counts}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--ref", default="HEAD")
    args = parser.parse_args()
    try:
        print(json.dumps(prepare(args.repo, args.output, args.ref), ensure_ascii=False, indent=2))
    except (ValueError, OSError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        print("Release preparation failed: " + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
