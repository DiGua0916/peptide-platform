"""Project-local Markdown room. Python standard library; no model/API calls."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import time
import uuid
import webbrowser
from contextlib import contextmanager
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import URLError
from urllib.parse import urlparse
from urllib.request import urlopen

TOOL_DIR = Path(__file__).resolve().parent
CONFIG = json.loads((TOOL_DIR / "project.json").read_text(encoding="utf-8"))
PROJECT_ROOT = Path(CONFIG["projectRoot"]).resolve()
LOG_PATH = PROJECT_ROOT / "docs" / "chat" / "ROOM.md"
RUNTIME = PROJECT_ROOT / "tools" / "chatroom" / ".runtime"
PORT = CONFIG["port"]
PROJECT_ID = CONFIG["id"]
AUTHORS = ("Codex", "Claude", "用户")
KINDS = {"progress": "进度", "message": "消息", "decision": "决定", "question": "待确认"}
MAX_BODY = 32000
MAX_REQUEST = 140000
BLOCK_START = "<!-- room:message "
BLOCK_END = "<!-- room:end -->"
BLOCK_RE = re.compile(
    r"<!-- room:message ([^\r\n]+) -->\r?\n(.*?)\r?\n<!-- room:end -->",
    re.DOTALL,
)


def timestamp():
    return datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds")


def read_log(path=LOG_PATH):
    return path.read_text(encoding="utf-8") if path.exists() else ""


def parse_log(raw):
    messages, warnings = [], []
    for match in BLOCK_RE.finditer(raw):
        try:
            meta = json.loads(match.group(1))
            if meta.get("author") not in AUTHORS or not isinstance(meta.get("id"), str):
                raise ValueError("作者或消息标识无效")
            if meta.get("kind") not in KINDS:
                raise ValueError("消息类型无效")
            for key in ("time", "task", "title"):
                if not isinstance(meta.get(key), str):
                    raise ValueError("消息信息不完整")
            body = match.group(2)
            # The first visible heading is generated from metadata for readable MD.
            if body.startswith("## "):
                body = body.split("\n", 1)[1].lstrip("\r\n") if "\n" in body else ""
            messages.append({**meta, "body": body.strip()})
        except (ValueError, TypeError, AttributeError) as exc:
            warnings.append("有一条记录格式不完整：" + str(exc))
    if raw.count(BLOCK_START) != len(list(BLOCK_RE.finditer(raw))):
        warnings.append("有未闭合的消息块，请检查 Markdown 原文。原始内容已保留。")
    return messages, warnings


@contextmanager
def file_lock(path, timeout=15):
    path.parent.mkdir(parents=True, exist_ok=True)
    lock = path.parent / ".room.lock"
    started = time.monotonic()
    while True:
        try:
            fd = os.open(str(lock), os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            os.write(fd, (str(os.getpid()) + " " + timestamp()).encode("utf-8"))
            os.close(fd)
            break
        except FileExistsError:
            if time.monotonic() - started >= timeout:
                raise RuntimeError("记录正在被另一进程写入，请稍后重试。若进程已异常退出，请检查 .room.lock。")
            time.sleep(0.05)
    try:
        yield
    finally:
        lock.unlink(missing_ok=True)


def replace_atomic(path, content):
    temp_path = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", newline="\n",
            dir=path.parent, prefix=".room-", suffix=".tmp", delete=False,
        ) as handle:
            temp_path = Path(handle.name)
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_path, path)
    finally:
        if temp_path is not None:
            temp_path.unlink(missing_ok=True)


def initialize(path=LOG_PATH):
    with file_lock(path):
        if not path.exists():
            content = (TOOL_DIR / "ROOM.template.md").read_text(encoding="utf-8")
            replace_atomic(path, content.rstrip() + "\n")


def validate_message(author, title, body, kind, task):
    if author not in AUTHORS or kind not in KINDS:
        raise ValueError("作者或消息类型无效。")
    if not isinstance(title, str) or not title.strip() or len(title) > 160:
        raise ValueError("标题应为 1–160 个字符。")
    if not isinstance(body, str) or not body.strip() or len(body) > MAX_BODY:
        raise ValueError("消息应为 1–32000 个字符。")
    if not isinstance(task, str) or len(task) > 80:
        raise ValueError("任务编号过长。")
    if any(c in title + task for c in "\r\n"):
        raise ValueError("标题和任务编号不能换行。")
    if "<!-- room:" in body or "<!-- room:" in title or "<!-- room:" in task:
        raise ValueError("消息正文不能包含聊天室内部标记。")


def append_message(author, title, body, kind="progress", task="", path=LOG_PATH):
    validate_message(author, title, body, kind, task)
    meta = {
        "id": str(uuid.uuid4()), "author": author, "time": timestamp(),
        "kind": kind, "task": task.strip(), "title": title.strip(),
    }
    heading = f"## {meta['author']} · {meta['time']} · {meta['title']}"
    if meta["task"]:
        heading += " · " + meta["task"]
    block = (
        "\n" + BLOCK_START + json.dumps(meta, ensure_ascii=False) + " -->\n"
        + heading + "\n\n" + body.strip() + "\n" + BLOCK_END + "\n"
    )
    with file_lock(path):
        existing = read_log(path)
        if not existing:
            existing = (TOOL_DIR / "ROOM.template.md").read_text(encoding="utf-8")
        replace_atomic(path, existing.rstrip() + "\n" + block)
    return {**meta, "body": body.strip()}


def snapshot(path=LOG_PATH):
    raw = read_log(path)
    messages, warnings = parse_log(raw)
    return {
        "project": CONFIG["name"], "projectId": PROJECT_ID,
        "logPath": str(path), "revision": hashlib.sha256(raw.encode("utf-8")).hexdigest(),
        "messages": messages, "warnings": warnings,
        "updatedAt": messages[-1]["time"] if messages else None,
    }


class RoomServer(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address, log_path=LOG_PATH):
        self.log_path = log_path
        super().__init__(address, RoomHandler)


class RoomHandler(BaseHTTPRequestHandler):
    server_version = "PeptideRoom/1.0"

    def log_message(self, fmt, *args):
        print(timestamp(), fmt % args, flush=True)

    def local_request(self):
        port = self.server.server_address[1]
        return self.headers.get("Host") in (f"127.0.0.1:{port}", f"localhost:{port}")

    def send_data(self, status, data, content_type="application/json; charset=utf-8", extra=None):
        if isinstance(data, (dict, list)):
            data = json.dumps(data, ensure_ascii=False).encode("utf-8")
        elif isinstance(data, str):
            data = data.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy",
                         "default-src 'self'; script-src 'self'; style-src 'self'; "
                         "img-src 'self' data:; connect-src 'self'; "
                         "object-src 'none'; base-uri 'none'; frame-ancestors 'none'")
        for key, value in (extra or {}).items():
            self.send_header(key, value)
        self.end_headers()
        if data:
            self.wfile.write(data)

    def do_GET(self):
        if not self.local_request():
            return self.send_data(403, {"error": "仅允许本机访问。"})
        route = urlparse(self.path).path
        if route == "/api/health":
            return self.send_data(200, {"projectId": PROJECT_ID, "status": "ok"})
        if route == "/api/messages":
            state = snapshot(self.server.log_path)
            etag = '"' + state["revision"] + '"'
            if self.headers.get("If-None-Match") == etag:
                return self.send_data(304, b"", extra={"ETag": etag})
            return self.send_data(200, state, extra={"ETag": etag})
        if route == "/room.md":
            return self.send_data(200, read_log(self.server.log_path), "text/plain; charset=utf-8")
        assets = {
            "/": ("index.html", "text/html; charset=utf-8"),
            "/app.js": ("app.js", "text/javascript; charset=utf-8"),
            "/style.css": ("style.css", "text/css; charset=utf-8"),
            "/favicon.svg": ("favicon.svg", "image/svg+xml"),
        }
        if route in assets:
            filename, mime = assets[route]
            return self.send_data(200, (TOOL_DIR / "public" / filename).read_bytes(), mime)
        return self.send_data(404, {"error": "页面不存在。"})

    def do_POST(self):
        if not self.local_request():
            return self.send_data(403, {"error": "仅允许本机访问。"})
        port = self.server.server_address[1]
        origin = self.headers.get("Origin")
        allowed_origins = (f"http://127.0.0.1:{port}", f"http://localhost:{port}")
        if origin and origin not in allowed_origins:
            return self.send_data(403, {"error": "请求来源不匹配。"})
        if self.headers.get("X-Peptide-Room") != "1":
            return self.send_data(403, {"error": "缺少本地聊天室请求标识。"})
        if urlparse(self.path).path != "/api/messages":
            return self.send_data(404, {"error": "接口不存在。"})
        if self.headers.get("Content-Type", "").split(";")[0].strip() != "application/json":
            return self.send_data(415, {"error": "请求格式必须为 JSON。"})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if not 0 < length <= MAX_REQUEST:
                return self.send_data(413, {"error": "消息过大或为空。"})
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            if not isinstance(payload, dict):
                raise ValueError("消息格式无效。")
            # Web composer is exclusively the owner; agents use the append helper.
            message = append_message(
                author="用户", title=payload.get("title", "项目留言"),
                body=payload.get("body", ""), kind="message",
                task="", path=self.server.log_path,
            )
            return self.send_data(201, message)
        except (ValueError, UnicodeError) as exc:
            return self.send_data(400, {"error": str(exc)})
        except (OSError, RuntimeError):
            return self.send_data(503, {"error": "写入失败，请稍后重试；内容仍在输入框中。"})


def serve(port):
    initialize()
    RUNTIME.mkdir(parents=True, exist_ok=True)
    server = RoomServer(("127.0.0.1", port))
    state = {"pid": os.getpid(), "port": port, "projectId": PROJECT_ID, "startedAt": timestamp()}
    (RUNTIME / "server.json").write_text(json.dumps(state), encoding="utf-8")
    print(f"http://127.0.0.1:{port}", flush=True)
    try:
        server.serve_forever()
    finally:
        server.server_close()
        state_path = RUNTIME / "server.json"
        if state_path.exists():
            try:
                if json.loads(state_path.read_text(encoding="utf-8")).get("pid") == os.getpid():
                    state_path.unlink()
            except (OSError, ValueError):
                pass


def health(port):
    try:
        with urlopen(f"http://127.0.0.1:{port}/api/health", timeout=1) as response:
            return json.load(response).get("projectId") == PROJECT_ID
    except (URLError, OSError, ValueError):
        return False


def start(port):
    if health(port):
        return f"http://127.0.0.1:{port}"
    RUNTIME.mkdir(parents=True, exist_ok=True)
    flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    with (RUNTIME / "server.log").open("ab") as log:
        process = subprocess.Popen(
            [sys.executable, str(TOOL_DIR / "room.py"), "serve", "--port", str(port)],
            cwd=PROJECT_ROOT, stdin=subprocess.DEVNULL, stdout=log, stderr=log,
            creationflags=flags, start_new_session=(os.name != "nt"),
        )
    for _ in range(50):
        if health(port):
            return f"http://127.0.0.1:{port}"
        if process.poll() is not None:
            raise RuntimeError(f"服务未启动。请检查端口 {port} 是否被占用，以及 {RUNTIME / 'server.log'}。")
        time.sleep(0.1)
    process.terminate()
    raise RuntimeError("聊天室启动超时，请查看本项目的 server.log。")


def main():
    parser = argparse.ArgumentParser(description="多肽项目本地 Markdown 聊天室")
    sub = parser.add_subparsers(dest="command", required=True)
    for command in ("serve", "start", "open"):
        command_parser = sub.add_parser(command)
        command_parser.add_argument("--port", type=int, default=PORT)
    sub.add_parser("init")
    tail_parser = sub.add_parser("tail")
    tail_parser.add_argument("--limit", type=int, default=12)
    post = sub.add_parser("post")
    post.add_argument("--author", choices=AUTHORS, required=True)
    post.add_argument("--title", required=True)
    post.add_argument("--task", default="")
    post.add_argument("--kind", choices=KINDS, default="progress")
    body = post.add_mutually_exclusive_group(required=True)
    body.add_argument("--body-file", type=Path)
    body.add_argument("--body")
    body.add_argument("--stdin", action="store_true")
    args = parser.parse_args()
    if args.command == "init":
        initialize()
        print(LOG_PATH)
    elif args.command == "tail":
        state = snapshot()
        print(json.dumps({"messages": state["messages"][-max(1, args.limit):],
                          "warnings": state["warnings"]}, ensure_ascii=False, indent=2))
    elif args.command == "post":
        content = args.body_file.read_text(encoding="utf-8-sig") if args.body_file else (
            sys.stdin.read() if args.stdin else args.body)
        result = append_message(args.author, args.title, content, args.kind, args.task)
        print(json.dumps(result, ensure_ascii=False))
    elif args.command == "serve":
        serve(args.port)
    else:
        url = start(args.port)
        if args.command == "open":
            webbrowser.open(url)
        if sys.stdout is not None:
            print(url)


if __name__ == "__main__":
    if sys.stdout is not None and hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
    try:
        main()
    except (OSError, RuntimeError, ValueError) as error:
        if sys.stderr is not None:
            print(str(error), file=sys.stderr)
        else:
            RUNTIME.mkdir(parents=True, exist_ok=True)
            (RUNTIME / "launcher-error.log").write_text(timestamp() + "\n" + str(error), encoding="utf-8")
        raise SystemExit(1)
