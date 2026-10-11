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


class MultiRoomTests(unittest.TestCase):
    setUp = HttpTests.setUp
    tearDown = HttpTests.tearDown
    request = HttpTests.request

    def test_each_room_has_its_own_document_and_history(self):
        before = self.path.read_bytes()
        with self.request("/api/rooms", {"name": "隔离项目甲"}) as response:
            first = json.load(response)
        with self.request("/api/rooms", {"name": "隔离项目乙"}) as response:
            second = json.load(response)
        self.assertNotEqual(first["code"], second["code"])
        self.assertNotEqual(first["logPath"], second["logPath"])
        for entry, content in [(first, "只在甲群"), (second, "只在乙群")]:
            self.request("/api/messages?room="+entry["id"], {"body": content}).close()
            with self.request("/api/messages?room="+entry["code"]) as response:
                messages = json.load(response)["messages"]
            self.assertEqual([m["body"] for m in messages], [content])
            self.assertEqual(messages[0]["author"], "用户")
            self.assertEqual([p.name for p in Path(entry["logPath"]).parent.iterdir()], ["ROOM.md"])
        with self.request("/room.md?room="+first["id"]) as response:
            self.assertNotIn("只在乙群", response.read().decode())
        self.assertEqual(before, self.path.read_bytes())

    def test_code_resolves_after_registry_reopen_and_join_is_read_only(self):
        entry = self.server.registry.create("重载群聊")
        room.append_message("Claude", "后端进度", "这是隔离测试内容", path=Path(entry["logPath"]))
        before = Path(entry["logPath"]).read_bytes()
        registry = room.RoomRegistry(self.server.registry.root, self.path)
        resolved = room.join_context(registry, entry["code"].lower())
        self.assertEqual(resolved["room"], entry)
        self.assertEqual(resolved["messages"][0]["author"], "Claude")
        self.assertIn("--room "+entry["code"], resolved["instructions"])
        self.assertEqual(before, Path(entry["logPath"]).read_bytes())
        self.assertRegex(entry["code"], r"^CRK1-(?:[2-9A-HJ-NP-Z]{4}-){3}[2-9A-HJ-NP-Z]{4}$")

    def test_blank_project_creates_folder_without_reusing_existing_data(self):
        base = self.server.registry.projects_root
        base.mkdir(parents=True, exist_ok=True)
        existing = base / "自动目录"
        existing.mkdir()
        marker = existing / "原有文件.txt"
        marker.write_text("保留", encoding="utf-8")
        entry = self.server.registry.create("自动目录")
        project = Path(entry["projectRoot"])
        self.assertTrue(project.is_dir())
        self.assertEqual(project.parent, base)
        self.assertNotEqual(project, existing)
        self.assertEqual(marker.read_text(encoding="utf-8"), "保留")
        self.assertEqual(list(project.iterdir()), [])
        reserved = self.server.registry.create("CON")
        self.assertEqual(Path(reserved["projectRoot"]).name, "项目-CON")
        attached = self.server.registry.create("关联已有目录", str(existing))
        self.assertEqual(Path(attached["projectRoot"]), existing)

    def test_unknown_code_never_falls_back_to_default(self):
        original = self.path.read_bytes()
        for method in (None, {"body": "不能写到默认群"}):
            with self.assertRaises(HTTPError) as caught:
                self.request("/api/messages?room=unknown-code", method)
            self.assertEqual(caught.exception.code, 400)
        self.assertEqual(original, self.path.read_bytes())

    def test_room_creation_validates_names_paths_and_origin(self):
        for payload in [{"name": "../escape"}, {"name": ""}, {"name": "多肽项目群"},
                        {"name": "目录校验", "projectRoot": "C:/Windows"}]:
            with self.assertRaises(HTTPError) as caught:
                self.request("/api/rooms", payload)
            self.assertEqual(caught.exception.code, 400)
        with self.assertRaises(HTTPError) as caught:
            self.request("/api/rooms", {"name": "不允许外部创建"}, {"Origin": "https://example.com"})
        self.assertEqual(caught.exception.code, 403)
        self.assertEqual(len(self.server.registry.read()), 1)

    def test_concurrent_creation_preserves_all_rooms_and_unique_keys(self):
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            entries = list(pool.map(lambda i: self.server.registry.create(f"并发群 {i}"), range(12)))
        self.assertEqual(len(self.server.registry.read()), 13)
        self.assertEqual(len({r["code"] for r in entries}), 12)
        self.assertEqual(len({r["logPath"] for r in entries}), 12)

    def test_room_etags_are_distinct_and_records_cannot_impersonate(self):
        a = self.server.registry.create("缓存甲")
        b = self.server.registry.create("缓存乙")
        with self.request("/api/messages?room="+a["id"]) as response:
            etag = response.headers["ETag"]
        with self.request("/api/messages?room="+b["id"], extra={"If-None-Match": etag}) as response:
            self.assertEqual(response.status, 200)
        with self.request("/api/messages?room="+a["code"], {"body": "署名检查", "author": "Claude"}) as response:
            self.assertEqual(json.load(response)["author"], "用户")
        with self.request("/api/join?room="+a["code"]) as response:
            self.assertEqual(json.load(response)["room"]["id"], a["id"])

    def test_dissolution_preserves_history_and_projects_but_blocks_posts(self):
        registry = self.server.registry
        entry = registry.create("准备解散")
        registry.post(entry["code"], "Codex", "已完成", "保留这条记录")
        before = Path(entry["logPath"]).read_bytes()
        closed = registry.dissolve(entry["code"])
        self.assertEqual(closed["status"], "dissolved")
        self.assertEqual(closed, registry.dissolve(entry["code"]))
        with self.assertRaises(room.RoomClosedError):
            registry.post(entry["code"], "Claude", "不能追加", "停止汇报")
        with self.assertRaises(room.RoomClosedError):
            room.join_context(registry, entry["code"])
        self.assertEqual(before, Path(entry["logPath"]).read_bytes())
        self.assertTrue(Path(entry["projectRoot"]).is_dir())

    def test_http_dissolution_invalidates_cache_and_keeps_read_only_access(self):
        entry = self.server.registry.create("网页解散")
        with self.request("/api/messages?room="+entry["id"]) as response:
            etag = response.headers["ETag"]
        with self.assertRaises(HTTPError) as caught:
            self.request("/api/rooms/dissolve", {})
        self.assertEqual(caught.exception.code, 400)
        self.request("/api/rooms/dissolve", {"room":entry["id"]}).close()
        for path, payload in [("/api/join?room="+entry["code"],None),
                              ("/api/messages?room="+entry["code"],{"body":"拒绝新留言"})]:
            with self.assertRaises(HTTPError) as caught:
                self.request(path, payload)
            self.assertEqual(caught.exception.code, 410)
            self.assertEqual(json.load(caught.exception)["code"], "room_dissolved")
        with self.request("/api/messages?room="+entry["id"], extra={"If-None-Match":etag}) as response:
            self.assertEqual(response.status, 200)
            self.assertEqual(json.load(response)["room"]["status"], "dissolved")
        with self.request("/room.md?room="+entry["id"]) as response:
            self.assertEqual(response.status, 200)


if __name__ == "__main__":
    unittest.main(verbosity=2)
