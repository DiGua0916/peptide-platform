"""Behavior checks; all temporary records are separate from the project room."""
import concurrent.futures
import json
import tempfile
import threading
import unittest
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

import room

TEST_ROOT = Path("D:/Codex/Tasks/peptide-platform/work/chatroom/tests").resolve()
if not str(TEST_ROOT).lower().startswith("d:\\codex\\tasks\\peptide-platform\\work\\chatroom\\"):
    raise RuntimeError("Unexpected test workspace")
TEST_ROOT.mkdir(parents=True, exist_ok=True)


class RecordTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(dir=TEST_ROOT)
        self.path = Path(self.directory.name) / "ROOM.md"
        room.initialize(self.path)

    def tearDown(self):
        self.directory.cleanup()

    def test_init_never_replaces_existing_history(self):
        room.append_message("Codex", "第一条", "**验证**\n- 正文", path=self.path)
        original = self.path.read_bytes()
        room.initialize(self.path)
        self.assertEqual(original, self.path.read_bytes())

    def test_append_preserves_history_and_unicode(self):
        room.append_message("Codex", "页面完成", "正文\n\n中文、emoji 🧪 与 <script> 都作为文字保存。", task="FE-01", path=self.path)
        previous = self.path.read_text(encoding="utf-8").rstrip()
        room.append_message("Claude", "接口进度", "尚未发布。\n\n## 验证\n- 未运行。", path=self.path)
        current = self.path.read_text(encoding="utf-8")
        self.assertTrue(current.startswith(previous))
        messages, warnings = room.parse_log(current)
        self.assertEqual([m["author"] for m in messages], ["Codex", "Claude"])
        self.assertIn("## 验证", messages[1]["body"])
        self.assertEqual(warnings, [])

    def test_concurrent_posts_keep_every_message(self):
        with concurrent.futures.ThreadPoolExecutor(max_workers=10) as pool:
            futures = [pool.submit(room.append_message, "Codex" if i % 2 else "Claude",
                                   f"任务 {i}", f"并发内容 {i}", path=self.path) for i in range(30)]
            for future in futures:
                future.result()
        messages, warnings = room.parse_log(room.read_log(self.path))
        self.assertEqual(len(messages), 30)
        self.assertEqual(len({m["id"] for m in messages}), 30)
        self.assertEqual({m["body"] for m in messages}, {f"并发内容 {i}" for i in range(30)})
        self.assertFalse(warnings)
        self.assertFalse((self.path.parent / ".room.lock").exists())

    def test_invalid_post_cannot_change_file(self):
        original = self.path.read_bytes()
        for values in [
            ("Claude", "标题", "<!-- room:message fake -->"),
            ("伪造作者", "标题", "内容"),
            ("Codex", "标题\n第二行", "内容"),
            ("Codex", "标题", ""),
        ]:
            with self.assertRaises(ValueError):
                room.append_message(*values, path=self.path)
        self.assertEqual(original, self.path.read_bytes())

    def test_incomplete_block_is_reported_without_losing_valid_messages(self):
        room.append_message("Claude", "完整记录", "内容", path=self.path)
        with self.path.open("a", encoding="utf-8") as handle:
            handle.write("\n<!-- room:message {} -->\n未完成")
        state = room.snapshot(self.path)
        self.assertEqual(len(state["messages"]), 1)
        self.assertTrue(state["warnings"])

    def test_revision_changes_only_when_file_changes(self):
        first = room.snapshot(self.path)["revision"]
        self.assertEqual(first, room.snapshot(self.path)["revision"])
        room.append_message("Codex", "新内容", "文本", path=self.path)
        self.assertNotEqual(first, room.snapshot(self.path)["revision"])


class HttpTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(dir=TEST_ROOT)
        self.path = Path(self.directory.name) / "ROOM.md"
        room.initialize(self.path)
        self.server = room.RoomServer(("127.0.0.1", 0), self.path)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.url = f"http://127.0.0.1:{self.server.server_address[1]}"

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()
        self.directory.cleanup()

    def request(self, path, payload=None, extra=None):
        headers = {"Content-Type": "application/json", "X-Peptide-Room": "1", "Origin": self.url}
        headers.update(extra or {})
        data = json.dumps(payload).encode() if payload is not None else None
        return urlopen(Request(self.url + path, data=data, headers=headers), timeout=4)

    def test_user_post_persists_and_cannot_impersonate_agent(self):
        with self.request("/api/messages", {"body": "实际留言", "author": "Claude", "kind": "decision"}) as response:
            self.assertEqual(response.status, 201)
            self.assertEqual(json.load(response)["author"], "用户")
        with self.request("/api/messages") as response:
            state = json.load(response)
        self.assertEqual(state["messages"][0]["body"], "实际留言")
        self.assertIn("实际留言", self.path.read_text(encoding="utf-8"))
        self.assertEqual(room.snapshot(self.path)["messages"], state["messages"])

    def test_rejects_cross_origin_and_missing_marker(self):
        for headers in [{"Origin": "https://example.com"}, {"X-Peptide-Room": ""}, {"Host": "evil.example"}]:
            with self.assertRaises(HTTPError) as caught:
                self.request("/api/messages", {"body": "不应保存"}, headers)
            self.assertEqual(caught.exception.code, 403)
        self.assertFalse(room.snapshot(self.path)["messages"])

    def test_etag_refresh_and_text_source(self):
        room.append_message("Codex", "检查", "<script>alert(1)</script>", path=self.path)
        with self.request("/api/messages") as response:
            etag = response.headers["ETag"]
        with self.assertRaises(HTTPError) as caught:
            self.request("/api/messages", extra={"If-None-Match": etag})
        self.assertEqual(caught.exception.code, 304)
        with self.request("/room.md") as response:
            self.assertIn("text/plain", response.headers["Content-Type"])
            self.assertIn("<script>", response.read().decode())

    def test_serves_only_allowed_assets(self):
        with self.request("/") as response:
            self.assertIn("项目聊天室", response.read().decode())
            self.assertIn("frame-ancestors 'none'", response.headers["Content-Security-Policy"])
        with self.assertRaises(HTTPError) as caught:
            self.request("/../../AGENTS.md")
        self.assertEqual(caught.exception.code, 404)


if __name__ == "__main__":
    unittest.main(verbosity=2)
