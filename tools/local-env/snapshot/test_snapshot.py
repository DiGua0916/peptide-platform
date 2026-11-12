#!/usr/bin/env python3
"""snapshot.py 的离线自检（不连数据库）：python3 tools/local-env/snapshot/test_snapshot.py"""
import re
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import snapshot  # noqa: E402


def tables(kind):
    return (HERE / "fixtures" / f"tables-{kind}.txt").read_text().split()


class PlanTest(unittest.TestCase):
    def test_every_production_table_is_classified(self):
        # fixtures 是 2026-11-10 生产库的表名（只有表名，没有数据）。
        self.assertEqual(74, len(snapshot.classify("store", "wp_", tables("store"))))
        self.assertEqual(23, len(snapshot.classify("hq", "wp_", tables("hq"))))

    def test_unknown_table_refuses_export(self):
        with self.assertRaises(snapshot.Refused) as caught:
            snapshot.plan("store", "wp_", tables("store") + ["wp_pc_new_module_customers"], "salt")
        self.assertIn("wp_pc_new_module_customers", str(caught.exception))
        with self.assertRaises(snapshot.Refused):
            snapshot.plan("store", "wp_", ["other_users"], "salt")

    def test_statements_touch_only_existing_non_keep_tables(self):
        for kind in ("store", "hq"):
            rules = snapshot.classify(kind, "wp_", tables(kind))
            for statement in snapshot.plan(kind, "wp_", tables(kind), "salt"):
                for name in re.findall(r"`(wp_[a-z0-9_-]+)`", statement):
                    self.assertIn(name, rules, statement)
                    self.assertNotEqual(snapshot.KEEP, rules[name], statement)

    def test_subset_of_tables_generates_no_statement_for_missing_ones(self):
        sql = "\n".join(snapshot.plan("store", "wp_", ["wp_users", "wp_options"], "salt"))
        self.assertIn("`wp_users`", sql)
        self.assertNotIn("wp_wc_orders", sql)
        self.assertNotIn("wp_pc_recovery", sql)

    def test_pii_and_secret_columns_are_covered(self):
        sql = "\n".join(snapshot.plan("store", "wp_", tables("store"), "salt"))
        for needle in ("user_email", "user_pass = '!'", "billing_email", "`wp_wc_order_addresses` SET first_name", "phone", "comment_author_IP",
                       "TRUNCATE TABLE `wp_woocommerce_sessions`", "TRUNCATE TABLE `wp_pc_mail_outbox`", "TRUNCATE TABLE `wp_woocommerce_api_keys`",
                       "TRUNCATE TABLE `wp_pc_panapay_transactions`", "'peptide_core_hq'", "'peptide_core_pixels'", "fluentmail-settings%", "pc_sanitized_snapshot"):
            self.assertIn(needle, sql)
        hq = "\n".join(snapshot.plan("hq", "wp_", tables("hq"), "salt"))
        for needle in ("key_enc = ''", "'pchq_ai'", "'pchq_register_secret'", "'pchq_cdn'", "pc\\_panapay\\_hub\\_accounts%", "TRUNCATE TABLE `wp_pc_pay_hub_attempts`"):
            self.assertIn(needle, hq)

    def test_test_orders_keep_their_all_ones_address(self):
        sql = [s for s in snapshot.plan("store", "wp_", tables("store"), "salt") if "wp_wc_order_addresses" in s]
        self.assertTrue(any("WHERE NOT (first_name = '1'" in s for s in sql))

    def test_salt_changes_pseudonyms_and_is_quoted(self):
        a = "\n".join(snapshot.plan("store", "wp_", ["wp_users"], "salt-a"))
        b = "\n".join(snapshot.plan("store", "wp_", ["wp_users"], "salt-b"))
        self.assertNotEqual(a, b)
        self.assertIn("'it''s'", snapshot.q("it's"))


class NewModuleTablesTest(unittest.TestCase):
    """2026-11-12 登记的各模块新表和新列。"""

    def test_new_tables_are_classified(self):
        rules = snapshot.classify("store", "wp_", tables("store") + ["wp_pc_track_ads", "wp_pc_m4_shadow_stats", "wp_pc_m4_shadow_log"])
        self.assertEqual(snapshot.KEEP, rules["wp_pc_track_ads"])
        self.assertEqual(snapshot.TRUNCATE, rules["wp_pc_m4_shadow_log"])
        hq = snapshot.classify("hq", "wp_", tables("hq") + ["wp_pchq_series", "wp_pchq_series_products", "wp_pchq_series_campaigns", "wp_pchq_orders", "wp_pchq_refunds", "wp_pchq_ad_spend"])
        self.assertEqual(snapshot.SCRUB, hq["wp_pchq_orders"])

    def test_new_columns_only_scrubbed_when_present(self):
        names = tables("hq") + ["wp_pchq_orders", "wp_pchq_refunds"]
        old = "\n".join(snapshot.plan("hq", "wp_", tables("hq"), "salt", {"wp_pchq_sites": {"id", "key_enc", "key_pending"}}))
        self.assertNotIn("buyer_name", old)
        new = "\n".join(snapshot.plan("hq", "wp_", names, "salt", {"wp_pchq_sites": {"id", "key_enc", "key_pending", "buyer_name"}, "wp_pchq_orders": {"email_hash"}, "wp_pchq_refunds": {"note"}}))
        for needle in ("buyer_name = IF(", "email_hash = IF(", "`wp_pchq_refunds` SET note = ''"):
            self.assertIn(needle, new)
        latest = "\n".join(snapshot.plan("hq", "wp_", names, "salt"))
        self.assertIn("buyer_name", latest)

    def test_customer_ids_are_rehashed(self):
        hq = "\n".join(snapshot.plan("hq", "wp_", tables("hq") + ["wp_pchq_customer_links", "wp_pchq_abandoned", "wp_pchq_orders"], "salt"))
        self.assertIn("`wp_pchq_customer_links` SET customer_id = UPPER(", hq)
        self.assertIn("email_mask = ''", hq)
        self.assertIn("`wp_pchq_orders` SET customer_id", hq)
        self.assertNotIn("`wp_pchq_abandoned`", hq)
        store = "\n".join(snapshot.plan("store", "wp_", tables("store"), "salt"))
        self.assertEqual(2, store.count("meta_key = '_pc_customer_id'"))

    def test_module_switches_reset_to_off(self):
        sql = "\n".join(snapshot.plan("store", "wp_", ["wp_options"], "salt"))
        self.assertIn("'pc_pricing_engine_mode', 'peptide_core_m6_mode'", sql)


class LeakCheckTest(unittest.TestCase):
    def test_real_addresses_are_leaks(self):
        for text in ("john.doe@gmail.com", "a:1:{s:5:\"email\";s:16:\"Jane@Outlook.com\";}", "ok c1@example.test then x@y.co"):
            self.assertTrue(snapshot.leaks_in(text), text)

    def test_allowed_addresses_are_not_leaks(self):
        for text in ("c0123456789abcd@example.test", "admin@example.test", "img/logo@2x.png", "hero@1.5x.webp", "support@woocommerce.com", "no at sign", "", "user@sub.example.test"):
            self.assertFalse(snapshot.leaks_in(text), text)

    def test_store_domain_and_exact_address_allowlists(self):
        self.assertTrue(snapshot.leaks_in("orders@vialcoveplus.com"))
        self.assertFalse(snapshot.leaks_in("orders@vialcoveplus.com", allow_domains=["vialcoveplus.com"]))
        self.assertFalse(snapshot.leaks_in("Shop@Outlook.com", allow_emails=["shop@outlook.com"]))
        self.assertTrue(snapshot.leaks_in("customer@outlook.com", allow_emails=["shop@outlook.com"]))
        self.assertTrue(snapshot.leaks_in("x@notvialcoveplus.com", allow_domains=["vialcoveplus.com"]))

    def test_queries_only_for_text_columns(self):
        queries = snapshot.leak_queries([("wp_users", "user_email", "varchar"), ("wp_users", "ID", "bigint"), ("wp_options", "option_value", "longtext")])
        self.assertEqual(2, len(queries))
        self.assertIn("`wp_users`", queries[0])
        self.assertIn("\\\\.", queries[0])  # 正则里的反斜杠在 SQL 字面量里要成对


if __name__ == "__main__":
    unittest.main()
