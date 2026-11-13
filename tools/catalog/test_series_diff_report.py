#!/usr/bin/env python3
"""series_diff_report.py 的自检：python3 tools/catalog/test_series_diff_report.py（只用标准库，不读写任何文件）。"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import series_diff_report as report  # noqa: E402


def diff(changed=(), create=(), unpublish=(), same=0, **extra):
    data = {
        "summary": {"same": same, "changed": len(changed), "create": len(create), "unpublish": len(unpublish)},
        "changed": list(changed),
        "create": list(create),
        "unpublish": list(unpublish),
        "store": {"name": "Shop", "host": "shop.example.test"},
    }
    data.update(extra)
    return data


def product(name, sku, *fields):
    return {"name": name, "sku": sku, "id": 1, "fields": list(fields)}


class ReportTest(unittest.TestCase):
    def test_store_title_hides_the_domain_by_default(self):
        d = diff()
        self.assertEqual("s0006（Shop）", report.store_title("/tmp/x/diff-s0006.json", d))
        self.assertEqual("s0006（Shop，shop.example.test）", report.store_title("diff-s0006.json", d, hosts=True))
        self.assertNotIn("example.test", report.render([("diff-s0006.json", d)], master="s0001"))

    def test_identical_store(self):
        text = report.render([("diff-s0008.json", diff(same=92))], master="s0001")
        self.assertIn("| s0008（Shop） | 92 | 0 | 0 | 0 |", text)
        self.assertIn("和主店完全一样，同步不会改动这家店", text)
        self.assertIn("### 1. 价格会变（0 处）", text)

    def test_prices_are_listed_for_confirmation(self):
        d = diff(
            same=1,
            changed=[
                product(
                    "BPC-157",
                    "VC-BPC",
                    {"field": "price", "store": "44.99", "series": "49.99", "variation": "5mg"},
                    {"field": "compare_price", "store": "0.00", "series": "59.99", "variation": "5mg"},
                    {"field": "meta", "key": "_pc_pricing", "store": '{"tier":2}', "series": "（没有）"},
                    {"field": "description", "store": "120 字", "series": "100 字", "store_excerpt": "…ice pack", "series_excerpt": "…"},
                )
            ],
        )
        text = report.render([("diff-s0006.json", d)], master="s0001")
        self.assertIn("### 1. 价格会变（3 处）", text)
        self.assertIn("| 5mg | 卖价 | $44.99 | $49.99 |", text)
        self.assertIn("| 5mg | 划线原价 | 没有 | $59.99 |", text)
        self.assertIn("价格设置", text)
        self.assertIn("其中 1 个是价格不一样", text)
        self.assertIn("从这里开始不同：“…ice pack”", text)
        self.assertIn("会换成主店的（1 处）", text)

    def test_extra_products_variations_and_new_ones(self):
        d = diff(
            changed=[
                product(
                    "TB-500",
                    "VC-TB",
                    {"field": "variation", "store": "50mg", "series": "系列里没有，同步后停用", "variation": "50mg"},
                    {"field": "variation", "store": "（没有）", "series": "新增", "variation": "2mg"},
                    {"field": "enabled", "store": "启用", "series": "停用", "variation": "5mg"},
                    {"field": "stock", "store": "outofstock", "series": "instock"},
                    {"field": "images", "store": "2 张", "series": "2 张", "note": "order"},
                )
            ],
            create=[{"sku": "VC-NEW", "name": "New one"}, {"sku": "VC-A", "name": "Same  Name"}],
            unpublish=[{"id": 9, "sku": "OLD-A", "name": "same name"}],
        )
        text = report.render([("diff-s0009.json", d)], master="s0001")
        self.assertIn("同步后会下架（1 个）", text)
        self.assertIn("| 50mg | 在售（主店没有这个款式） | 停售 |", text)
        self.assertIn("| 5mg | 在售 | 停售 |", text)
        self.assertIn("会新增款式：2mg", text)
        self.assertIn("会新建 2 个商品", text)
        self.assertIn("| 缺货 | 有货 |", text)
        self.assertIn("图片和主店一样，只是顺序不同", text)
        self.assertIn("编码对不上（1 个）", text)
        self.assertIn("主店编码 VC-A，这家店编码 OLD-A", text)

    def test_shipping_is_shown_but_marked_as_not_synced(self):
        same = diff(same=1, shipping={"changed": False, "store": ["a"], "series": ["a"]})
        other = diff(same=1, shipping={"changed": True, "store": ["General｜全部地区｜Flat：不限金额，运费 $22.50"], "series": []})
        text = report.render([("diff-a.json", same), ("diff-b.json", other)], master="s0001")
        self.assertIn("和主店一样。", text)
        self.assertIn("**不会**改运费", text)
        self.assertIn("运费 $22.50", text)
        self.assertIn("- 主店：没有通用运费方案", text)
        # 总后台生成的差异报告里 shipping 只有 changed，没有两边的内容：不出运费一节。
        self.assertNotIn("**运费方案**", report.render([("diff-c.json", diff(shipping={"changed": True}))]))

    def test_old_style_rows_without_details_still_render(self):
        d = diff(changed=[product("X", "X", {"field": "meta", "store": "_pc_specs", "series": "_pc_specs"}, {"field": "images", "store": "1 张", "series": "2 张"})])
        text = report.render([("diff-x.json", d)])
        self.assertIn("不一样的项：_pc_specs", text)
        self.assertIn("| 1 张 | 2 张 |", text)

    def test_table_cells_cannot_break_the_table(self):
        self.assertEqual("a／b c", report.cell("a|b\nc"))
        self.assertTrue(report.cell("x" * 500).endswith("…"))


if __name__ == "__main__":
    unittest.main()
