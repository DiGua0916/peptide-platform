#!/usr/bin/env python3
"""把各店的系列差异报告（JSON）汇总成一份给平台所有者看的 Markdown 报告（大白话，不用懂代码）。

首次系列同步前，平台所有者要先看各店和主店（系列主商品库）差在哪里，确认后才下发（HQ-05）。
差异报告（JSON）有三个来源，格式相同：

1. 总后台“系列管理”里点“生成差异报告”（存在总后台，页面上直接看）；
2. 在店铺上运行：wp peptide series diff --file=<主店导出>.json --out=diff-s0006.json
3. 拿两份只读导出的商品文件离线比较（不碰任何店铺）：
     wp peptide series diff --file=s0001.json --store-file=s0006.json --out=diff-s0006.json

再用本脚本汇总：
     python3 tools/catalog/series_diff_report.py --master s0001 diff-*.json > 报告.md

店铺的标题取自文件名（diff-s0006.json → s0006），默认不写域名（仓库是公开的）；加 --hosts 才写。
第 3 种来源的报告带有更多细节（描述从哪里开始不同、图片文件名、运费方案），前两种没有，脚本都能处理。

只读脚本：只读取传入的 JSON 文件，不连接任何服务器，不改任何东西。只用 Python 标准库。
"""
import argparse
import json
import os
import re
import sys

FIELD_NAMES = {
    "name": "商品名称",
    "status": "上架状态",
    "type": "商品类型",
    "description": "详情描述",
    "short_description": "简短描述",
    "categories": "分类（专辑）",
    "menu_order": "排序位置",
    "attributes": "规格选项",
    "default_attributes": "默认选中的规格",
    "images": "商品图片",
    "meta": "附加信息",
    "price": "卖价",
    "compare_price": "划线原价",
    "stock": "有货 / 缺货状态",
    "enabled": "款式是否在售",
    "variation": "款式",
    "variation_attributes": "款式规格",
    "image": "款式图片",
}

META_NAMES = {
    "_pc_spu": "商品编码（SPU）",
    "_pc_product_subtitle": "副标题",
    "_pc_specs": "规格参数表",
    "_pc_vials_per_sales_unit": "每份包含的支数",
    "_pc_pricing": "价格设置",
    "_pc_is_demo": "演示商品标记",
}

VALUE_NAMES = {
    "status": {"publish": "上架", "draft": "未上架"},
    "type": {"simple": "单一规格", "variable": "多款式"},
    "stock": {"instock": "有货", "outofstock": "缺货", "onbackorder": "可预订"},
    "enabled": {"启用": "在售", "停用": "停售"},
}

PRICE_FIELDS = {"price", "compare_price"}
PRICE_META = {"_pc_pricing"}


def cell(value, limit=160):
    """表格单元格里的文字：去掉换行和竖线，太长的截断。"""
    text = str(value if value is not None else "").replace("\n", " ").replace("|", "／").strip()
    return text if len(text) <= limit else text[: limit - 1] + "…"


def load(path):
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict) or not isinstance(data.get("summary"), dict):
        raise ValueError("不是差异报告（缺少 summary）")
    return data


def store_title(path, diff, hosts=False):
    """店铺标题：文件名里的店铺编号 + 店名；--hosts 时再带域名。"""
    code = re.sub(r"^diff[-_]", "", os.path.splitext(os.path.basename(path))[0])
    store = diff.get("store") if isinstance(diff.get("store"), dict) else {}
    name = str(store.get("name") or "").strip()
    host = str(store.get("host") or "").strip()
    extra = [x for x in (name if name and name != code else "", host if hosts else "") if x]
    return f"{code}（{'，'.join(extra)}）" if extra else code


def kind(field):
    """一处差异属于哪一类：price 价格 / extra_variation 这家多出来的款式 / new_variation 主店新增的款式 / stock 库存状态 / content 其他内容。"""
    name = field.get("field")
    if name in PRICE_FIELDS or (name == "meta" and field.get("key") in PRICE_META):
        return "price"
    if name == "variation":
        return "new_variation" if str(field.get("store", "")).startswith("（没有") else "extra_variation"
    if name == "stock":
        return "stock"
    return "content"


def what(field):
    """差在哪（给人看的名字）。"""
    name = field.get("field", "")
    if name == "meta" and field.get("key"):
        return META_NAMES.get(field["key"], field["key"])
    return FIELD_NAMES.get(name, name)


def money(value):
    text = str(value)
    if re.fullmatch(r"\d+(\.\d+)?", text):
        return "没有" if float(text) == 0 else "$" + text
    return text


def sides(field):
    """这家店现在 / 主店（同步后），都写成给人看的文字。"""
    name = field.get("field", "")
    store, series = str(field.get("store", "")), str(field.get("series", ""))
    if name in PRICE_FIELDS:
        return money(store), money(series)
    if name in VALUE_NAMES:
        return VALUE_NAMES[name].get(store, store), VALUE_NAMES[name].get(series, series)
    if name in ("description", "short_description"):
        if field.get("note") == "markup":
            return f"{store}（文字和主店一样，只是排版代码不同）", series
        if "store_excerpt" in field:
            return f"{store}；从这里开始不同：“{field['store_excerpt']}”", f"{series}；“{field.get('series_excerpt', '')}”"
        return store, series
    if name == "images":
        if field.get("note") == "order":
            return f"{store}（图片和主店一样，只是顺序不同）", series
        only_store = "、".join(field.get("store_only") or [])
        only_series = "、".join(field.get("series_only") or [])
        return (
            store + (f"；这家独有：{only_store}" if only_store else ""),
            series + (f"；主店独有：{only_series}" if only_series else ""),
        )
    if name == "variation":
        if kind(field) == "new_variation":
            return "没有这个款式", "有（同步后新增）"
        return "有这个款式", "没有（同步后停售）"
    if name == "meta" and not field.get("key"):
        return f"不一样的项：{store}", "换成主店的"
    return store or "（空）", series or "（空）"


def product_label(product):
    name, sku = product.get("name", ""), product.get("sku", "")
    return f"{name}（{sku}）" if sku else str(name)


def fields_of(product):
    return [f for f in product.get("fields", []) if isinstance(f, dict)]


def norm_name(name):
    return re.sub(r"\s+", " ", str(name or "")).strip().lower()


def analyse(diff):
    """把一家店的差异分好类。"""
    changed = [c for c in diff.get("changed", []) if isinstance(c, dict)]
    create = [p for p in diff.get("create", []) if isinstance(p, dict)]
    extra = [p for p in diff.get("unpublish", []) if isinstance(p, dict)]
    rows = {"price": [], "extra_variation": [], "new_variation": [], "stock": [], "content": []}
    for product in changed:
        for field in fields_of(product):
            rows[kind(field)].append((product, field))
    extra_names = {norm_name(p.get("name")): p for p in extra}
    suspects = [(p, extra_names[norm_name(p.get("name"))]) for p in create if norm_name(p.get("name")) in extra_names]
    summary = diff["summary"]
    counts = {k: int(summary.get(k, 0)) for k in ("same", "changed", "create", "unpublish")}
    return {"changed": changed, "create": create, "extra": extra, "rows": rows, "suspects": suspects, "counts": counts}


def render(reports, master="主店", hosts=False, note=""):
    """reports: [(文件路径, 差异报告)]，返回 Markdown 文本。"""
    stores = [(store_title(path, diff, hosts), diff, analyse(diff)) for path, diff in reports]
    out = [f"# 各店与主店（{master}）的商品差异报告", ""]
    if note:
        out += [note, ""]
    out += [
        f"这份报告只是比较，**没有改任何一家店**。它回答一个问题：如果让各店的商品都跟主店（{master}）保持一致，每家店会变什么。",
        "",
        "“同步”之后会发生的事：",
        "",
        "- 和主店不一样的地方，改成主店的（价格、名称、描述、图片、分类、款式）；",
        "- 主店有、这家店没有的商品，在这家店新建；",
        "- 这家店有、主店没有的商品，在这家店下架（改成草稿，不删除）；",
        "- 同步前自动存一份快照，可以恢复到同步前的样子。",
        "",
        "## 一、结论",
        "",
        "| 店铺 | 和主店一样 | 有差异 | 主店有、这家没有 | 这家多出来的 |",
        "| --- | ---: | ---: | ---: | ---: |",
    ]
    for title, _, a in stores:
        c = a["counts"]
        out.append(f"| {cell(title)} | {c['same']} | {c['changed']} | {c['create']} | {c['unpublish']} |")
    out.append("")
    out.append("（单位：商品个数。“有差异”指两边都有这个商品，但价格或内容不一样。）")
    out.append("")
    for title, _, a in stores:
        c, r = a["counts"], a["rows"]
        if not (c["changed"] or c["create"] or c["unpublish"]):
            out.append(f"- **{title}**：和主店完全一样，同步不会改动这家店。")
            continue
        priced = len({id(p) for p, _ in r["price"]})
        parts = [f"{c['same']} 个商品和主店一样"]
        if c["changed"]:
            parts.append(f"{c['changed']} 个有差异" + (f"（其中 {priced} 个是价格不一样）" if priced else "（价格都一样，差在文字、图片等内容）"))
        if c["create"]:
            parts.append(f"主店有而这家没有的 {c['create']} 个" + (f"（其中 {len(a['suspects'])} 个可能只是编码对不上，见第二部分）" if a["suspects"] else ""))
        if c["unpublish"]:
            parts.append(f"这家多出来的 {c['unpublish']} 个")
        out.append(f"- **{title}**：" + "；".join(parts) + "。")

    # ---------- 二、需要确认 ----------
    out += ["", "## 二、需要您确认：同步后会被主店覆盖掉的本店改动", ""]
    out.append("下面这些是各店现在和主店不一样、同步后会**变成主店的样子**的地方。如果某一项是这家店特意改的、想保留，请告诉我们，那家店（或那个商品）就先不同步。")
    number = 0

    priced = [(t, p, f) for t, _, a in stores for p, f in a["rows"]["price"]]
    number += 1
    out += ["", f"### {number}. 价格会变（{len(priced)} 处）", ""]
    if priced:
        out += ["| 店铺 | 商品 | 款式 | 哪个价格 | 这家店现在 | 同步后（主店的） |", "| --- | --- | --- | --- | --- | --- |"]
        last = None
        for title, product, field in priced:
            store, series = sides(field)
            key = (title, id(product))
            out.append(
                f"| {cell(title) if last is None or last[0] != title else ''} | {cell(product_label(product)) if key != last else ''} | {cell(field.get('variation', ''))} | {cell(what(field))} | {cell(store)} | {cell(series)} |"
            )
            last = key
        out += ["", "说明：“卖价”是顾客实际付的价格；“划线原价”是促销时划掉的那个原价，写“没有”表示这个商品现在没有做促销。"]
    else:
        out.append("没有。各店的价格和主店一样。")

    extras = [(t, p) for t, _, a in stores for p in a["extra"]]
    number += 1
    out += ["", f"### {number}. 这家店多出来的商品，同步后会下架（{len(extras)} 个）", ""]
    if extras:
        out.append("主店没有这些商品。同步后它们在这家店改成草稿（顾客看不到），不删除，可以恢复。")
        out.append("")
        for title, _, a in stores:
            if a["extra"]:
                out.append(f"- **{title}**：" + "、".join(cell(product_label(p), 80) for p in a["extra"]))
    else:
        out.append("没有。")

    lost = [(t, p, f) for t, _, a in stores for p, f in a["rows"]["extra_variation"]]
    onoff = [(t, p, f) for t, _, a in stores for p, f in a["rows"]["content"] if f.get("field") == "enabled"]
    number += 1
    out += ["", f"### {number}. 款式会停售或改变在售状态（{len(lost) + len(onoff)} 处）", ""]
    if lost or onoff:
        out += ["| 店铺 | 商品 | 款式 | 这家店现在 | 同步后 |", "| --- | --- | --- | --- | --- |"]
        for title, product, field in lost:
            out.append(f"| {cell(title)} | {cell(product_label(product))} | {cell(field.get('variation', ''))} | 在售（主店没有这个款式） | 停售 |")
        for title, product, field in onoff:
            store, series = sides(field)
            out.append(f"| {cell(title)} | {cell(product_label(product))} | {cell(field.get('variation', ''))} | {cell(store)} | {cell(series)} |")
    else:
        out.append("没有。")

    number += 1
    content_total = sum(1 for _, _, a in stores for _, f in a["rows"]["content"] if f.get("field") != "enabled")
    out += ["", f"### {number}. 文字、图片、分类会换成主店的（{content_total} 处）", ""]
    if content_total:
        out += ["每个商品具体差在哪、两边各是什么，见第三部分的各店明细。", "", "| 店铺 | 商品 | 会被换成主店的内容 |", "| --- | --- | --- |"]
        for title, _, a in stores:
            shown = False
            for product in a["changed"]:
                names = []
                for field in fields_of(product):
                    if kind(field) == "content" and field.get("field") != "enabled":
                        label = what(field) + (f"（{field['variation']}）" if field.get("variation") else "")
                        if label not in names:
                            names.append(label)
                if names:
                    out.append(f"| {cell(title) if not shown else ''} | {cell(product_label(product))} | {cell('、'.join(names), 300)} |")
                    shown = True
    else:
        out.append("没有。")

    stock = [(t, p, f) for t, _, a in stores for p, f in a["rows"]["stock"]]
    if stock:
        number += 1
        out += ["", f"### {number}. 有货 / 缺货状态会跟主店走（{len(stock)} 处）", ""]
        out += ["只涉及没有开“库存数量”的商品。库存数量本身不比较，同步也不改。", "", "| 店铺 | 商品 | 款式 | 这家店现在 | 同步后（主店的） |", "| --- | --- | --- | --- | --- |"]
        for title, product, field in stock:
            store, series = sides(field)
            out.append(f"| {cell(title)} | {cell(product_label(product))} | {cell(field.get('variation', ''))} | {cell(store)} | {cell(series)} |")

    suspects = [(t, new, old) for t, _, a in stores for new, old in a["suspects"]]
    if suspects:
        number += 1
        out += ["", f"### {number}. 可能是同一个商品、但编码对不上（{len(suspects)} 个）", ""]
        out.append("这些商品在主店和这家店名字一样，但商品编码不同，系统认不出是同一个：同步会把这家店原来的那个下架、再新建一个。**同步前需要先把编码对上**（技术上的事，我们来处理），否则这家店的这个商品会换一个新链接。")
        out.append("")
        for title, new, old in suspects:
            out.append(f"- **{title}**：{cell(new.get('name'), 80)}（主店编码 {cell(new.get('sku'), 60)}，这家店编码 {cell(old.get('sku'), 60)}）")

    creates = sum(len(a["create"]) for _, _, a in stores)
    added = [(t, p, f) for t, _, a in stores for p, f in a["rows"]["new_variation"]]
    out += ["", "### 不算覆盖、只是新增的", ""]
    if creates or added:
        out.append("这些不会改掉各店现有的东西，只是把主店有而这家店没有的补上。")
        out.append("")
        for title, _, a in stores:
            if a["create"]:
                out.append(f"- **{title}** 会新建 {len(a['create'])} 个商品：" + "、".join(cell(product_label(p), 80) for p in a["create"]))
        for title, product, field in added:
            out.append(f"- **{title}** 的 {cell(product_label(product), 80)} 会新增款式：{cell(field.get('variation', ''), 80)}")
    else:
        out.append("没有。")

    # ---------- 三、各店明细 ----------
    out += ["", "## 三、各店明细", ""]
    for title, diff, a in stores:
        c = a["counts"]
        out += [f"### {title}", ""]
        if not (c["changed"] or c["create"] or c["unpublish"]):
            out += ["商品和主店完全一样。", ""]
        if a["changed"]:
            out += [f"**有差异的商品（{len(a['changed'])} 个）**", "", "| 商品 | 差在哪 | 这家店现在 | 主店（同步后） |", "| --- | --- | --- | --- |"]
            for product in a["changed"]:
                label = product_label(product)
                for field in fields_of(product):
                    name = what(field) + (f"（{field['variation']}）" if field.get("variation") and field.get("field") != "variation" else "")
                    if field.get("field") == "variation":
                        name = f"款式（{field.get('variation', '')}）"
                    store, series = sides(field)
                    out.append(f"| {cell(label)} | {cell(name)} | {cell(store, 260)} | {cell(series, 260)} |")
                    label = ""
            out.append("")
        if a["create"]:
            out += [f"**主店有、这家没有的商品（{len(a['create'])} 个，同步后新建）**", ""]
            out += [f"- {cell(product_label(p))}" for p in a["create"]]
            out.append("")
        if a["extra"]:
            out += [f"**这家多出来的商品（{len(a['extra'])} 个，同步后下架，不删除）**", ""]
            out += [f"- {cell(product_label(p))}" for p in a["extra"]]
            out.append("")
        shipping = diff.get("shipping") if isinstance(diff.get("shipping"), dict) else None
        if shipping is not None and "store" in shipping:
            out.append("**运费方案**")
            out.append("")
            if not shipping.get("changed"):
                out.append("和主店一样。")
            else:
                out.append("和主店不一样（这次同步商品**不会**改运费，列在这里供参考）：")
                out.append("")
                out.append("- 这家店现在：" + ("；".join(cell(x, 200) for x in shipping.get("store") or []) or "没有通用运费方案"))
                out.append("- 主店：" + ("；".join(cell(x, 200) for x in shipping.get("series") or []) or "没有通用运费方案"))
            out.append("")

    # ---------- 四、说明 ----------
    out += [
        "## 四、比了什么，没比什么",
        "",
        "- **比了**：商品名称、上架状态、详情和简短描述、分类、规格选项和款式、卖价和划线原价、商品图片和款式图片、副标题、规格参数表、每份支数、价格设置、排序位置；各店的通用运费方案（只列出，不随商品同步）。",
        "- **没比**：库存数量（各店每天自动变化，同步也不改它）；订单、顾客、优惠券和营销活动；页面装修和模板；各店自己的域名、像素、支付和邮件设置。这些都不受这次商品同步影响。",
        "- 商品是按“商品编码”配对的，不是按名字。名字一样但编码不同的，会在第二部分单独列出来。",
        "- 描述里指向主店域名的链接，比较时已经按“换成各店自己的域名”处理，不算差异。",
    ]
    return "\n".join(out).rstrip() + "\n"


def main(argv):
    parser = argparse.ArgumentParser(description="把各店的系列差异报告（JSON）汇总成给平台所有者看的 Markdown。只读。")
    parser.add_argument("files", nargs="+", help="差异报告文件，文件名里带店铺编号：diff-s0006.json")
    parser.add_argument("--master", default="主店", help="主店（母版店）的编号，写进标题，例如 s0001")
    parser.add_argument("--hosts", action="store_true", help="店铺标题里带上域名（默认不带：仓库是公开的）")
    parser.add_argument("--note", default="", help="放在报告开头的一句说明，例如数据的导出时间")
    args = parser.parse_args(argv[1:])
    reports = []
    for path in args.files:
        try:
            reports.append((path, load(path)))
        except (OSError, ValueError) as error:
            sys.stderr.write(f"读不了 {path}：{error}\n")
            return 1
    sys.stdout.write(render(reports, master=args.master, hosts=args.hosts, note=args.note))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
