#!/usr/bin/env python3
"""release.py 的离线测试（不连任何服务器）：python3 tools/deployment/test_release.py"""
import argparse
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import release  # noqa: E402

CFG = {"pilot": "s0006", "main_slug": "s0001",
       "web": {"host": "web", "main": "/srv/vialcove/current", "tenant": "/srv/vialcove/tenants/{slug}/public"},
       "admin": {"host": "admin", "main": "/srv/vialcove/store-admin/current", "tenant": "/srv/vialcove/tenant-admin/{slug}/public"},
       "hq": {"host": "admin", "root": "/srv/vialcove/hq/current/public", "url": "https://hq.example.test"}}


def site(i, slug, active=True):
    return {"id": i, "name": slug, "active": active, "management_url": f"https://{slug}.manager.example.test/", "public_url": f"https://www.{slug}.example.test/"}


REGISTRY = [site(2, "s0001"), site(12, "s0006"), site(13, "s0007"), site(16, "s0008"), site(10, "s0002", active=False)]


class PlanTest(unittest.TestCase):
    def test_order_pilot_then_others_then_main_templates_hq(self):
        units = release.production_units(REGISTRY, CFG, {"web": "/tpl/web", "admin": "/tpl/admin"})
        self.assertEqual(["s0006", "s0007", "s0008", "s0001", "templates", "hq"], [u["name"] for u in units])
        self.assertEqual(["G2", "G3", "G3", "G3", "G3", "G3"], [u["stage"] for u in units])
        self.assertEqual("prod_full", units[0]["check"])
        self.assertEqual(["prod_quick", "prod_quick", "prod_quick", "none", "hq"], [u["check"] for u in units[1:]])

    def test_each_store_has_front_and_admin_copy_with_right_paths(self):
        units = {u["name"]: u for u in release.production_units(REGISTRY, CFG, {})}
        self.assertEqual([("s0006/web", "web", "/srv/vialcove/tenants/s0006/public"), ("s0006/admin", "admin", "/srv/vialcove/tenant-admin/s0006/public")], [(c["key"], c["host"], c["root"]) for c in units["s0006"]["copies"]])
        self.assertEqual(["/srv/vialcove/current", "/srv/vialcove/store-admin/current"], [c["root"] for c in units["s0001"]["copies"]])
        self.assertNotIn("templates", units)
        self.assertEqual("https://www.s0006.example.test", units["s0006"]["store_url"])
        self.assertEqual(12, units["s0006"]["site_id"])

    def test_retired_store_is_left_out_and_new_store_is_picked_up(self):
        names = [u["name"] for u in release.production_units(REGISTRY + [site(20, "s0011")], CFG, {})]
        self.assertNotIn("s0002", names)
        self.assertIn("s0011", names)

    def test_pilot_must_exist_and_not_be_main(self):
        with self.assertRaises(release.Failed):
            release.production_units([site(2, "s0001")], CFG, {})
        with self.assertRaises(release.Failed):
            release.production_units(REGISTRY, dict(CFG, pilot="s0001"), {})

    def test_components_by_kind_and_expected_hashes(self):
        self.assertIn("plugins/peptide-hq", release.components_for({"kind": "hq"}))
        self.assertNotIn("plugins/peptide-hq", release.components_for({"kind": "store"}))
        self.assertEqual({"plugins/peptide-core": "a", "themes/peptide-site": "b"}, release.expected_hashes({"peptide-core": {"hash": "a"}, "peptide-site": {"hash": "b"}}))


class FakeRemote:
    """记录收到的命令；按配置让某一步失败。"""
    log = []
    fail_on = None       # (子串) 命中时返回失败
    hashes = {}

    def __init__(self, name, cfg):
        self.name = name

    def run(self, command, check=True, timeout=0):
        FakeRemote.log.append((self.name, command))
        if FakeRemote.fail_on and FakeRemote.fail_on in command:
            if check:
                raise release.Failed("boom")
            return 1, "000", "boom"
        if "pchq-release status" in command:
            return 0, '{"lock":null,"latest":null}\n', ""
        if "pchq-release start" in command:
            return 0, '{"id":7}\n', ""
        if " hash " in command:
            comps = [c for c in command.split() if c.startswith(("plugins/", "themes/"))]
            return 0, "".join(f"{c}\t{FakeRemote.hashes.get(c, 'x')}\t1\n" for c in comps), ""
        if " fatals " in command:
            return 0, "0\t\n", ""
        if "curl" in command:
            return 0, "200", ""
        return 0, "ok\n", ""

    def put(self, local, remote):
        FakeRemote.log.append((self.name, "PUT " + os.path.basename(local)))

    def agent(self, *args, check=True):
        return self.run("sudo bash agent " + " ".join(str(a) for a in args), check=check)


def make(env_units, **over):
    args = argparse.Namespace(env="sandbox", label="t1", modules="M1", operator="F1", emergency=False, confirmed="", observe_minutes=0, rollback_all_on_failure=False,
                              allow_same_version=True, skip_local_tests=True, ref="HEAD", php="", node="", allow_unpushed=True, workdir="/tmp")
    for k, v in over.items():
        setattr(args, k, v)
    inv = {"environments": {"sandbox": {"hosts": {"a": {}, "b": {}}, "hq_wp": {"host": "a", "path": "/hq", "user": "u"}, "reload": {"a": "reload-a", "b": "reload-b"}, "reload_wait": 0, "units": env_units, "requires_confirmation": False}}}
    r = release.Release(args, inv, FakeRemote)
    r.g0 = lambda: None
    r.commit, r.zip, r.components_file = "abc1234abc1234", "/tmp/pkg.zip", "/tmp/components.json"
    r.components = {"peptide-core": {"version": "1", "hash": "H1", "files": 1}, "peptide-hq": {"version": "1", "hash": "H2", "files": 1}}
    return r


def unit(name, stage="G1", host="a", kind="store"):
    return {"name": name, "stage": stage, "check": "none", "copies": [{"key": name + "/x", "host": host, "root": "/srv/" + name, "kind": kind, "owner": "keep"}], "health": [{"host": host, "url": "https://" + name + "/", "expect": [200]}]}


class FlowTest(unittest.TestCase):
    def setUp(self):
        FakeRemote.log, FakeRemote.fail_on = [], None
        FakeRemote.hashes = {"plugins/peptide-core": "H1", "plugins/peptide-hq": "H2"}
        release.say = lambda text: None

    def commands(self, needle):
        return [c for _, c in FakeRemote.log if needle in c]

    def test_success_applies_every_unit_checks_hashes_and_finishes_done(self):
        r = make([unit("one"), unit("two", host="b"), unit("hq", kind="hq")])
        self.assertEqual(0, r.run())
        self.assertEqual(3, len(self.commands(" apply t1 ")))
        self.assertEqual(0, len(self.commands(" rollback ")))
        self.assertTrue(self.commands("pchq-release finish 7 done"))
        self.assertEqual({"one/x": "ok", "two/x": "ok", "hq/x": "ok"}, r.results)
        self.assertTrue(self.commands(" prune "))

    def test_failed_health_check_rolls_back_that_unit_and_stops(self):
        FakeRemote.fail_on = "https://two/"
        r = make([unit("one"), unit("two", host="b"), unit("three")])
        self.assertEqual(1, r.run())
        self.assertEqual(["/srv/one", "/srv/two"], [c.split(" apply t1 ")[1].split()[0] for c in self.commands(" apply t1 ")])       # 第三组没有动
        self.assertEqual(1, len(self.commands(" rollback t1 /srv/two")))
        self.assertEqual(0, len(self.commands(" rollback t1 /srv/one")))                                       # 已成功的保持新版本
        self.assertEqual({"one/x": "ok", "two/x": "rolled_back"}, r.results)
        self.assertTrue(self.commands("pchq-release finish 7 failed"))
        self.assertTrue(self.commands("reload-b"))

    def test_rollback_all_on_failure_also_reverts_earlier_units(self):
        FakeRemote.fail_on = "https://two/"
        r = make([unit("one"), unit("two", host="b")], rollback_all_on_failure=True)
        self.assertEqual(1, r.run())
        self.assertEqual(1, len(self.commands(" rollback t1 /srv/one")))
        self.assertTrue(self.commands("pchq-release finish 7 rolled_back"))

    def test_hash_mismatch_at_the_end_fails_the_release(self):
        FakeRemote.hashes = {"plugins/peptide-core": "OTHER", "plugins/peptide-hq": "H2"}
        r = make([unit("one")])
        self.assertEqual(1, r.run())
        self.assertTrue(self.commands("pchq-release finish 7 failed"))

    def test_production_needs_explicit_confirmation(self):
        r = make([unit("one")])
        r.env["requires_confirmation"] = True
        with self.assertRaises(release.Failed):
            r.run()
        self.assertEqual([], self.commands(" apply "))
        r = make([unit("one")], confirmed="t1")
        r.env["requires_confirmation"] = True
        self.assertEqual(0, r.run())

    def test_same_version_with_changed_content_is_refused(self):
        r = make([unit("one")], allow_same_version=False)
        self.assertEqual(["peptide-core"], r.version_rule({"peptide-core": {"version": "1", "hash": "OLD"}, "peptide-hq": {"version": "1", "hash": "H2"}}))
        self.assertEqual([], r.version_rule({"peptide-core": {"version": "0.9", "hash": "OLD"}}))
        self.assertEqual([], r.version_rule(None))


if __name__ == "__main__":
    unittest.main()
