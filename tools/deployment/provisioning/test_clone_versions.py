"""M1：整店复制后不留下“版本号在、表不在”。不连服务器；SQL 的实际效果另在沙盒的 MySQL 上验过（见 docs/handoffs/m1-release.md）。"""
import importlib.util
import re
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('clone', Path(__file__).with_name('clone.py'))
clone = importlib.util.module_from_spec(spec)
spec.loader.exec_module(clone)


class OrphanVersionTest(unittest.TestCase):
    def test_pattern_matches_table_versions_only(self):
        hit = lambda name: bool(re.search(clone.TABLE_VERSION_PATTERN, name))
        for name in ('peptide_core_app_stats_db', 'peptide_core_recovery_db', 'pc_m4_db', 'woocommerce_db_version', 'pc_subscriptions_schema', 'wc_blocks_db_schema_version'):
            self.assertTrue(hit(name), name)
        for name in ('peptide_core_version', 'woocommerce_version', 'pc_mail_templates_version', 'db_upgraded', 'siteurl', 'pc_db_settings'):
            self.assertFalse(hit(name), name)

    def test_table_versions_are_a_subset_of_what_the_target_keeps(self):
        # 目标店自己原有的表结构版本号都在 pc_clone_keep 里（KEEP_OPTION_PATTERN 更宽），所以不会被误删。
        for name in ('peptide_core_app_stats_db', 'woocommerce_db_version', 'pc_subscriptions_schema'):
            self.assertTrue(re.search(clone.KEEP_OPTION_PATTERN, name), name)

    def test_statement_only_deletes_options_missing_from_keep(self):
        sql = clone.orphan_table_version_sql()
        self.assertIn('LEFT JOIN pc_clone_keep', sql)
        self.assertIn('k.option_name IS NULL', sql)
        self.assertIn(clone.TABLE_VERSION_PATTERN, sql)
        self.assertTrue(sql.startswith('DELETE o FROM wp_options o'))

    def test_runs_before_the_keep_table_is_restored_and_dropped(self):
        source = Path(clone.__file__).read_text(encoding='utf-8')
        body = source[source.index('# 5) Put the target'):source.index('# 6) Addresses')]
        self.assertLess(body.index('orphan_table_version_sql()'), body.index('INSERT INTO wp_options (option_name, option_value, autoload) SELECT'))
        self.assertLess(body.index('orphan_table_version_sql()'), body.index('DROP TABLE pc_clone_keep'))


if __name__ == '__main__':
    unittest.main()
