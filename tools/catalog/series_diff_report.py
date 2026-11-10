#!/usr/bin/env python3
"""把各店的系列差异报告（JSON）汇总成一份给人看的 Markdown 报告。

首次系列同步前，平台所有者要先看各店和系列主商品库差在哪里，确认后才下发（HQ-05）。
差异报告有两个来源，格式相同：

1. 总后台“系列管理”里点“生成差异报告”（存在总后台，页面上直接看）；
2. 在店铺服务器上运行（不经过总后台，适合在脱敏快照上预演）：
     wp peptide series export --file=series.json            # 在母版店
     wp peptide series diff --file=series.json --out=diff-s0006.json   # 在每家店
   再用本脚本汇总：
     python3 tools/catalog/series_diff_report.py diff-*.json > series-diff-report.md

只读脚本：只读取传入的 JSON 文件，不连接任何服务器，不改任何东西。只用 Python 标准库。
"""
import json
import sys

FIELD_NAMES = {
    "name": "名称",
    "status": "上架状态",
    "type": "商品类型",
    "description": "描述",
    "short_description": "简短描述",
    "categories": "专辑",
    "attributes": "规格",
    "default_attributes": "默认规格",
    "images": "图片",
    "meta": "附加信息",
    "price": "成交价",
    "compare_price": "划线价",
    "stock": "库存状态",
    "enabled": "款式启用",
    "variation": "款式",
    "variation_attributes": "款式规格",
    "image": "款式图片",
}


def cell(value):
    """表格单元格里的文字：去掉换行和竖线，太长的截断。"""
    text = str(value if value is not None else "").replace("\n", " ").replace("|", "／").strip()
    return text if len(text) <= 80 else text[:77] + "…"


def load(path):
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict) or not isinstance(data.get("summary"), dict):
        raise ValueError("不是差异报告（缺少 summary）")
    return data


def store_title(path, diff):
    store = diff.get("store") if isinstance(diff.get("store"), dict) else {}
    name = store.get("name") or path
    host = store.get("host")
    return f"{name}（{host}）" if host else str(name)


def render(reports):
    """reports: [(文件路径, 差异报告)]，返回 Markdown 文本。"""
    out = ["# 系列主商品库差异报告", ""]
    out.append("同步后，每家店的商品和价格会变成和系列主商品库一致：有差异的改成系列的，本店没有的新建，系列里没有的下架（不删除）。同步前自动存快照，可以恢复。")
    out += ["", "## 汇总", "", "| 店铺 | 一样 | 有差异 | 同步后新建 | 同步后下架 |", "| --- | ---: | ---: | ---: | ---: |"]
    totals = [0, 0, 0, 0]
    for path, diff in reports:
        s = diff["summary"]
        row = [int(s.get(k, 0)) for k in ("same", "changed", "create", "unpublish")]
        totals = [a + b for a, b in zip(totals, row)]
        out.append(f"| {cell(store_title(path, diff))} | {row[0]} | {row[1]} | {row[2]} | {row[3]} |")
    out.append(f"| **合计** | {totals[0]} | {totals[1]} | {totals[2]} | {totals[3]} |")

    for path, diff in reports:
        out += ["", f"## {store_title(path, diff)}", ""]
        s = diff["summary"]
        if not any(int(s.get(k, 0)) for k in ("changed", "create", "unpublish")):
            out.append("和系列主商品库完全一样，同步不会改动这家店的商品。")
            continue
        changed = [c for c in diff.get("changed", []) if isinstance(c, dict)]
        if changed:
            out += [f"### 有差异的商品（{len(changed)} 个）", "", "| 商品 | 哪里不一样 | 本店现在 | 系列（同步后） |", "| --- | --- | --- | --- |"]
            for product in changed:
                label = f"{product.get('name', '')}（{product.get('sku', '')}）"
                for field in product.get("fields", []):
                    if not isinstance(field, dict):
                        continue
                    what = FIELD_NAMES.get(field.get("field"), field.get("field", ""))
                    if field.get("variation"):
                        what = f"{what}（{field['variation']}）"
                    out.append(f"| {cell(label)} | {cell(what)} | {cell(field.get('store'))} | {cell(field.get('series'))} |")
                    label = ""
            out.append("")
        for key, title in (("create", "本店没有、同步后会新建的商品"), ("unpublish", "系列里没有、同步后会下架的商品（不删除）")):
            items = [p for p in diff.get(key, []) if isinstance(p, dict)]
            if items:
                out += [f"### {title}（{len(items)} 个）", ""]
                out += [f"- {cell(p.get('name'))}（{cell(p.get('sku'))}）" for p in items]
                out.append("")
    return "\n".join(out).rstrip() + "\n"


def main(argv):
    if len(argv) < 2:
        sys.stderr.write("用法：python3 tools/catalog/series_diff_report.py diff-店铺1.json [diff-店铺2.json ...] > 报告.md\n")
        return 2
    reports = []
    for path in argv[1:]:
        try:
            reports.append((path, load(path)))
        except (OSError, ValueError) as error:
            sys.stderr.write(f"读不了 {path}：{error}\n")
            return 1
    sys.stdout.write(render(reports))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
