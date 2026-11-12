#!/usr/bin/env python3
"""M1-4 关键链路套件（critical-path）：发布流水线 G1 在沙盒上跑，全部通过才进下一步。

在后台机上以能 sudo 的账号运行（内部用 sudo 切到沙盒账号跑 WP-CLI）：
    python3 critical_path.py [--out result.json] [--only 前台,结账]

只对沙盒有效（wp-config.php 里没有 VIALCOVE_SANDBOX 就退出）。它会在沙盒里下几张货到付款的测试订单、
建一张临时优惠码，结束时全部删掉。沙盒不发邮件、不连外网，所以支付通道、真实发信、Cloudflare 不在这里验，
那些由 prod-check 在试点店用测试单验。

每项输出一行：PASS / FAIL / SKIP + 链路 + 检查点。有 FAIL 时退出码 1。SKIP 必须带原因。
"""

import argparse
import json
import os
import re
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
SB = "/srv/vialcove-sandbox"
SHOP = "https://sandbox-shop.vialcoveresearch.com"
ADMIN = "https://sandbox-admin.vialcoveresearch.com"
HQ = "https://sandbox.vialcoveresearch.com"
SITE = {"shop": SB + "/shop/public", "admin": SB + "/admin/public", "hq": SB + "/hq/public"}
RUN = str(int(time.time()))
PHP_ERROR = re.compile(r"<b>(Fatal error|Warning|Notice|Deprecated)</b>:|There has been a critical error|Uncaught (Error|Exception)|Parse error", re.I)
RESULTS = []


def record(status, area, name, detail=""):
    RESULTS.append({"status": status, "area": area, "check": name, "detail": detail})
    print(f"{status:4} {area}｜{name}" + (f"　{detail}" if detail else ""), flush=True)


def check(area, name, ok, detail=""):
    record("PASS" if ok else "FAIL", area, name, detail if not ok else "")
    return bool(ok)


def skip(area, name, reason):
    record("SKIP", area, name, reason)


# ---------- 沙盒里的 WordPress ----------
def wp(site, php, env=None):
    """在指定站点执行一段 PHP，取最后一行 JSON（PHP 里用 pc_out( … ) 输出）。"""
    code = "function pc_out( $v ) { echo \"\\nPCOUT:\", wp_json_encode( $v ), \"\\n\"; } " + php
    cmd = ["sudo", "-u", "vcsandbox", "-H"] + (["env"] + [f"{k}={v}" for k, v in env.items()] if env else []) + ["wp", "--path=" + SITE[site], "eval", code]
    done = subprocess.run(cmd, cwd=SB + "/tmp", capture_output=True, text=True, timeout=180)
    for line in reversed(done.stdout.splitlines()):
        if line.startswith("PCOUT:"):
            return json.loads(line[6:])
    raise RuntimeError("WP-CLI 没有返回结果：" + (done.stderr or done.stdout)[-400:])


def login_cookie(site, login):
    cmd = ["sudo", "-u", "vcsandbox", "-H", "env", "ACC_LOGIN=" + login, "ACC_ORDER=0", "wp", "--path=" + SITE[site], "eval-file", os.path.join(HERE, "accept", "cookie.php")]
    out = subprocess.run(cmd, cwd=SB + "/tmp", capture_output=True, text=True, timeout=120).stdout
    match = re.search(r"^COOKIE=(.+)$", out, re.M)
    if not match:
        raise RuntimeError("生成登录 cookie 失败：" + out[-300:])
    return match.group(1)


# ---------- HTTP ----------
CTX = ssl.create_default_context()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


OPENER = urllib.request.build_opener(NoRedirect, urllib.request.HTTPSHandler(context=CTX))


def http(url, method="GET", headers=None, body=None, timeout=90):
    data = None
    headers = dict(headers or {})
    headers.setdefault("User-Agent", "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) critical-path")
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    request = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with OPENER.open(request, timeout=timeout) as response:
            return response.status, response.read().decode("utf-8", "replace"), dict(response.headers)
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode("utf-8", "replace"), dict(error.headers)


class Cart:
    """WooCommerce Store API 的一个购物车（用 Cart-Token，不用 cookie）。"""

    def __init__(self):
        status, body, headers = http(SHOP + "/?rest_route=/wc/store/v1/cart")
        self.nonce = headers.get("Nonce") or headers.get("nonce") or ""
        self.token = headers.get("Cart-Token") or headers.get("cart-token") or ""
        self.cart = json.loads(body) if status == 200 else {}

    def call(self, method, route, body=None):
        status, text, headers = http(SHOP + "/?rest_route=/wc/store/v1" + route, method, {"Nonce": self.nonce, "Cart-Token": self.token}, body)
        self.nonce = headers.get("Nonce") or headers.get("nonce") or self.nonce
        self.token = headers.get("Cart-Token") or headers.get("cart-token") or self.token
        try:
            data = json.loads(text)
        except ValueError:
            data = {"raw": text[:300]}
        if status in (200, 201) and isinstance(data, dict) and "totals" in data:
            self.cart = data
        return status, data

    def totals(self):
        return {k: int(v) for k, v in self.cart.get("totals", {}).items() if isinstance(v, str) and v.lstrip("-").isdigit()}

    def consistent(self):
        t = self.totals()
        return t.get("total_price") == t.get("total_items", 0) - t.get("total_discount", 0) + t.get("total_shipping", 0) + t.get("total_fees", 0) + t.get("total_tax", 0)


def address(kind="US"):
    base = {"first_name": "Cp", "last_name": "Tester", "company": "", "address_1": "100 Test Ave", "address_2": "", "city": "Austin", "state": "TX", "postcode": "78701", "country": "US", "phone": "5125550100"}
    if kind == "PR":
        base.update({"city": "San Juan", "state": "PR", "postcode": "00901", "country": "PR", "phone": "7875550100"})
    if kind == "TEST":  # 测试单规则：姓、名、地址、城市全填“1”
        base.update({"first_name": "1", "last_name": "1", "address_1": "1", "city": "1", "postcode": "1", "phone": "1"})
    return base


def place_order(cart, addr, email):
    billing = dict(addr, email=email)
    cart.call("POST", "/cart/update-customer", {"billing_address": billing, "shipping_address": addr})
    rates = [r for package in cart.cart.get("shipping_rates", []) for r in package.get("shipping_rates", [])]
    if rates and not any(r.get("selected") for r in rates):
        cart.call("POST", "/cart/select-shipping-rate", {"package_id": cart.cart["shipping_rates"][0]["package_id"], "rate_id": rates[0]["rate_id"]})
    expected = cart.totals().get("total_price")
    status, data = cart.call("POST", "/checkout", {"billing_address": billing, "shipping_address": addr, "payment_method": "cod", "customer_note": "critical-path " + RUN})
    return status, data, expected


# ---------- 八条链路 ----------
def area_front(ctx):
    a = "前台打开"
    status, body, _ = http(SHOP + "/?cp=" + RUN)
    check(a, "首页 200", status == 200, f"HTTP {status}")
    check(a, "首页有“仅供研究”声明", bool(re.search(r"research (use|purposes)", body, re.I)))
    check(a, "首页没有 PHP 报错", not PHP_ERROR.search(body))
    status, body, _ = http(SHOP + f"/?vc_product={ctx['product']}&cp={RUN}")
    check(a, "商品链接 200 且有声明", status == 200 and bool(re.search(r"research (use|purposes)", body, re.I)) and not PHP_ERROR.search(body), f"HTTP {status}")
    if ctx.get("category_url"):
        status, body, _ = http(ctx["category_url"])
        check(a, "专辑页 200（或跳到店内地址）", status == 200 or (status in (301, 302)), f"HTTP {status}")
    for path in ("/cart/", "/my-account/"):
        status, body, _ = http(SHOP + path + "?cp=" + RUN)
        check(a, f"{path} 200 且无报错", status == 200 and not PHP_ERROR.search(body), f"HTTP {status}")


def area_cart(ctx):
    a = "加购与优惠"
    check(a, "加购推荐的钩子都认得（Quote_Cart::supported_hooks）", wp("shop", "pc_out( class_exists( '\\\\PeptideCore\\\\Activity\\\\Quote_Cart' ) ? \\PeptideCore\\Activity\\Quote_Cart::supported_hooks() : null );") is True)
    price = int(round(float(ctx["price"]) * 100))
    # 样例 1：一种商品 2 件
    cart = Cart()
    status, _ = cart.call("POST", "/cart/add-item", {"id": ctx["variation"], "quantity": 2})
    t = cart.totals()
    check(a, "样例 1：加购 2 件成功", status in (200, 201) and cart.cart.get("items_count") == 2, f"HTTP {status}")
    check(a, "样例 1：商品金额 = 单价 × 2", t.get("total_items") == price * 2, f"{t.get('total_items')} ≠ {price * 2}")
    check(a, "样例 1：总额 = 商品 − 优惠 + 运费 + 税", cart.consistent(), json.dumps(t))
    status, _ = cart.call("POST", "/cart/apply-coupon", {"code": "cp-not-a-coupon-" + RUN})
    check(a, "不存在的优惠码被拒绝", status == 400, f"HTTP {status}")
    # 样例 2：两种商品 + 临时九折码
    cart = Cart()
    cart.call("POST", "/cart/add-item", {"id": ctx["variation"], "quantity": 1})
    cart.call("POST", "/cart/add-item", {"id": ctx["variation2"], "quantity": 1})
    before = cart.totals()
    status, data = cart.call("POST", "/cart/apply-coupon", {"code": ctx["coupon"]})
    after = cart.totals()
    mine = [c for c in cart.cart.get("coupons", []) if c.get("code", "").lower() == ctx["coupon"].lower()]
    off = int(mine[0]["totals"]["total_discount"]) if mine else 0
    check(a, "样例 2：九折码生效", status == 200 and bool(mine) and off > 0, f"HTTP {status} {str(data)[:120]}")
    check(a, "样例 2：九折码减的钱不超过商品金额的 10%", 0 < off <= before.get("total_items", 0) * 0.10 + 1, f"减了 {off}，商品 {before.get('total_items')}")
    check(a, "样例 2：用码后总额变小且算得平", after.get("total_price", 0) < before.get("total_price", 0) and cart.consistent(), json.dumps(after))
    # 样例 3：波多黎各地址的运费
    cart = Cart()
    cart.call("POST", "/cart/add-item", {"id": ctx["variation"], "quantity": 1})
    cart.call("POST", "/cart/update-customer", {"billing_address": dict(address("PR"), email=f"cp.pr.{RUN}@example.test"), "shipping_address": address("PR")})
    rates = [r for package in cart.cart.get("shipping_rates", []) for r in package.get("shipping_rates", [])]
    check(a, "样例 3：波多黎各地址有运费方案可选", len(rates) > 0)
    chosen = [r for r in rates if r.get("selected")]
    check(a, "样例 3：选中的运费计入总额", bool(chosen) and int(chosen[0]["price"]) == cart.totals().get("total_shipping") and cart.consistent(), json.dumps(cart.totals()))


def area_checkout(ctx):
    a = "结账"
    email = f"cp.order.{RUN}@example.test"
    cart = Cart()
    cart.call("POST", "/cart/add-item", {"id": ctx["variation"], "quantity": 1})
    status, data, expected = place_order(cart, address("US"), email)
    order = int(data.get("order_id", 0)) if isinstance(data, dict) else 0
    check(a, "正常下单成功（货到付款）", status == 200 and order > 0, f"HTTP {status} {str(data)[:200]}")
    if order:
        ctx["orders"].append(order)
        info = wp("admin", f"$o = wc_get_order( {order} ); pc_out( array( 'status' => $o->get_status(), 'total' => (int) round( $o->get_total() * 100 ), 'test' => (string) $o->get_meta( '_pc_test_order' ), 'email' => $o->get_billing_email(), 'number' => $o->get_order_number(), 'user' => (int) $o->get_customer_id() ) );")
        check(a, "订单金额 = 结账页总额", info["total"] == expected, f"{info['total']} ≠ {expected}")
        check(a, "订单状态是待处理 / 处理中", info["status"] in ("processing", "on-hold", "pending"), info["status"])
        check(a, "正常订单没有被当成测试单", info["test"] in ("", "0"))
        ctx["normal_order"] = order
    # 测试单：收件信息全填 1
    cart = Cart()
    cart.call("POST", "/cart/add-item", {"id": ctx["variation"], "quantity": 1})
    status, data, _ = place_order(cart, address("TEST"), "1")
    order = int(data.get("order_id", 0)) if isinstance(data, dict) else 0
    check(a, "测试单（收件信息全填 1）能下单", status == 200 and order > 0, f"HTTP {status} {str(data)[:200]}")
    if order:
        ctx["orders"].append(order)
        info = wp("admin", f"global $wpdb; $o = wc_get_order( {order} ); $t = $wpdb->prefix . 'pc_mail_outbox'; pc_out( array( 'test' => (string) $o->get_meta( '_pc_test_order' ), 'mails' => (int) $wpdb->get_var( $wpdb->prepare( \"SELECT COUNT(*) FROM {{$t}} WHERE order_id = %d\", {order} ) ), 'email' => $o->get_billing_email() ) );".replace("{{$t}}", "{$t}"))
        check(a, "测试单被标记为测试单", info["test"] == "1", str(info))
        check(a, "测试单没有产生任何邮件记录", info["mails"] == 0, f"{info['mails']} 封")
        ctx["test_order"] = order


def area_recovery(ctx):
    a = "弃单"
    email = f"cp.abandon.{RUN}@example.test"
    made = wp("admin", f"""
        $ids = array();
        foreach ( array( 'failed', 'pending' ) as $st ) {{
            $o = wc_create_order(); $o->add_product( wc_get_product( {ctx['variation']} ), 1 );
            $o->set_address( array( 'first_name' => 'Cp', 'last_name' => 'Abandon', 'email' => '{email}', 'address_1' => '100 Test Ave', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US', 'phone' => '5125550100' ), 'billing' );
            // 弃单规则：顾客离开满 5 分钟才算。把下单时间拨到 15 分钟前。
            $o->set_payment_method( 'cod' ); $o->set_date_created( time() - 900 ); $o->calculate_totals(); $o->set_status( $st ); $o->save(); $ids[] = $o->get_id();
        }}
        $list = \\PeptideCore\\Recovery_Items::list( array(), 1, 100000 );
        $mine = \\PeptideCore\\Recovery_Items::list( array( 'q' => '{email}' ), 1, 100000 );
        $rows = (array) ( $mine['items'] ?? array() );
        $test = {int(ctx.get('test_order', 0))};
        $has_test = $test ? count( array_filter( (array) ( $list['items'] ?? array() ), static fn( $i ) => in_array( $test, array( (int) ( $i['order_id'] ?? 0 ), (int) ( $i['id'] ?? 0 ) ), true ) ) ) : 0;
        pc_out( array( 'ids' => $ids, 'rows' => count( $rows ), 'has_test' => $has_test, 'total' => count( (array) ( $list['items'] ?? array() ) ) ) );
    """)
    ctx["orders"] += made["ids"]
    check(a, "未付款订单进入弃单列表", made["rows"] >= 1, f"列表共 {made['total']} 条，这位顾客 {made['rows']} 条")
    check(a, "同一位顾客两张未付款订单只显示一条", made["rows"] == 1, f"{made['rows']} 条")
    check(a, "测试单不进弃单列表", made["has_test"] == 0)
    sent = wp("admin", f"""
        global $wpdb; $t = $wpdb->prefix . 'pc_mail_outbox';
        $o = wc_get_order( {made['ids'][1]} ); $o->update_status( 'cancelled', 'critical-path' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT kind, subject, status FROM {{$t}} WHERE recipient LIKE %s ORDER BY id", '%{email}%' ), ARRAY_A );
        pc_out( array( 'rows' => $rows ) );
    """)
    help_mails = [r for r in sent["rows"] if "payment" in (r.get("subject") or "").lower() or "failed" in (r.get("kind") or "")]
    check(a, "付款失败 / 未付款取消后，付款帮助信进了发件箱（沙盒不外发）", len(help_mails) >= 1, json.dumps(sent, ensure_ascii=False)[:300])
    check(a, "同一位顾客 24 小时内只发一封付款帮助信", len(help_mails) == 1, f"{len(help_mails)} 封")


def area_tracking(ctx):
    a = "追踪"
    status, body, _ = http(SHOP + "/?rest_route=/peptide/v1")
    routes = json.loads(body).get("routes", {}) if status == 200 else {}
    check(a, "追踪接收接口已注册", any("/track/events" in r for r in routes), f"HTTP {status}")
    status, body, _ = http(SHOP + "/?rest_route=/peptide/v1/track/events", "POST", body={"events": "not-a-list"})
    check(a, "格式不对的事件被拒绝而不是报错", status in (400, 403, 415, 422), f"HTTP {status} {body[:120]}")
    skip(a, "一次完整购买产生 8 步漏斗事件且顺序正确", "事件由浏览器里的追踪脚本上报，这个套件不开浏览器；等 F4 提供可回放的事件夹具后补上")


def area_pixels(ctx):
    a = "像素"
    configured = wp("admin", "$o = get_option( 'peptide_core_pixels' ); pc_out( ! empty( $o ) );")
    if not configured:
        skip(a, "浏览器与服务端事件 event_id 一致", "沙盒没有配置像素（脱敏快照不带像素 ID 和令牌）；在试点店按 F4 的像素验收清单验")
    else:
        skip(a, "浏览器与服务端事件 event_id 一致", "需要浏览器；F4 的 m7-pixel-acceptance-smoke 覆盖服务端一半")


def open_pages(area, base, cookie, pages, label):
    bad = []
    for page in pages:
        status, body, headers = http(base + "/wp-admin/" + page, headers={"Cookie": cookie})
        if status in (301, 302):
            status, body, headers = http(headers.get("Location", headers.get("location", "")), headers={"Cookie": cookie})
        if status != 200 or PHP_ERROR.search(body):
            found = PHP_ERROR.search(body)
            bad.append(f"{page} → {status}" + (f"（{found.group(0)}）" if found else ""))
    check(area, f"{label} {len(pages)} 个页面都能打开、没有 PHP 报错", not bad, "；".join(bad[:6]))


def menu_pages(base, cookie, start):
    status, body, _ = http(base + "/wp-admin/" + start, headers={"Cookie": cookie})
    i = body.find('id="adminmenu"')
    segment = body[i:body.find('id="wpcontent"', i)] if i > 0 else ""
    pages = []
    for href in re.findall(r'<a href=[\'"]([^\'"]+)[\'"][^>]*class=[\'"][^\'"]*menu-top', segment):
        href = href.replace("&#038;", "&").replace("&amp;", "&")
        if href.startswith("http"):
            href = href.split("/wp-admin/", 1)[-1]
        if href not in pages:
            pages.append(href)
    return pages


def area_admin(ctx):
    a = "后台"
    cookie = login_cookie("admin", ctx["admin_login"])
    pages = menu_pages(ADMIN, cookie, "admin.php?page=pc-overview")
    check(a, "店铺后台左侧菜单读得出来（至少 6 个）", len(pages) >= 6, f"{len(pages)} 个")
    open_pages(a, ADMIN, cookie, pages, "店铺后台主菜单")
    extra = ["admin.php?page=pc-manual-order", "admin.php?page=pc-accounts", "admin.php?page=pc-recovery", "admin.php?page=pc-mail", "admin.php?page=wc-orders"]
    if ctx.get("normal_order"):
        extra.append(f"admin.php?page=pc-order&id={ctx['normal_order']}")
    if ctx.get("test_order"):
        extra.append(f"admin.php?page=pc-order&id={ctx['test_order']}")
    open_pages(a, ADMIN, cookie, extra, "手动订单、顾客账户、弃单、邮件、订单列表和详情")
    hq_cookie = login_cookie("hq", ctx["hq_login"])
    # 总后台用自己的导航（不是 WordPress 的左侧菜单）：从首页里收集所有 page=pchq… 的链接。
    _, body, _ = http(HQ + "/wp-admin/admin.php?page=pchq", headers={"Cookie": hq_cookie})
    hq_pages = sorted(set("admin.php?page=" + p for p in re.findall(r"admin\.php\?page=(pchq[a-z0-9_-]*)", body)))
    check(a, "总后台导航读得出来（至少 5 个页面）", len(hq_pages) >= 5, f"{len(hq_pages)} 个")
    for slug, label in (("pchq-logistics", "物流填单"),):  # 主线新页面：导航里没有它说明没注册上
        check(a, f"总后台导航里有“{label}”", "admin.php?page=" + slug in hq_pages)
    open_pages(a, HQ, hq_cookie, hq_pages, "总后台主菜单")


def area_hq(ctx):
    a = "总后台连接"
    r = wp("hq", """
        $id = (int) array_key_first( \\PeptideHq\\Sites::all() );
        $ok = \\PeptideHq\\Monitor::poll( $id );
        $site = \\PeptideHq\\Sites::get( $id );
        $status = \\PeptideHq\\Client::status( $id );
        $summary = \\PeptideHq\\Client::summary( $id, 7 );
        $login = \\PeptideHq\\Client::login( $id );
        $tpl = \\PeptideHq\\Client::design_templates( $id );
        $bad = \\PeptideHq\\Client::request_raw( \\PeptideHq\\Site_Urls::management( $site ), str_repeat( '0', 64 ), 'GET', '/peptide/v1/hq/status' );
        pc_out( array(
            'id' => $id, 'poll' => $ok, 'state' => $site['status'], 'error' => $site['error'],
            'status_ok' => ! is_wp_error( $status ), 'caps' => is_array( $status ) ? ( $status['capabilities'] ?? null ) : null, 'modules' => is_array( $status ) && array_key_exists( 'modules', $status ) ? $status['modules'] : 'missing',
            'days' => is_array( $summary ) ? count( (array) ( $summary['days'] ?? array() ) ) : -1, 'day_keys' => is_array( $summary ) && ! empty( $summary['days'] ) ? array_keys( (array) $summary['days'][0] ) : array(),
            'login' => is_array( $login ) ? (string) ( $login['url'] ?? '' ) : '', 'tpl_ok' => ! is_wp_error( $tpl ),
            'wrong_key' => is_wp_error( $bad ) ? $bad->get_error_code() : 'accepted', 'alerts' => \\PeptideHq\\Alerts::open_count(),
        ) );
    """)
    check(a, "总后台轮询店铺成功", r["poll"] is True and r["state"] == "online", f"{r['state']} {r['error']}")
    check(a, "店铺状态里有功能声明", isinstance(r["caps"], dict) and r["caps"].get("hq.capabilities") == "1", str(r["caps"])[:120])
    check(a, "店铺状态里有模块简报（可以为空）", r["modules"] != "missing" and isinstance(r["modules"], (dict, list)), str(r["modules"])[:120])
    check(a, "每日汇总返回 7 天，带订单、销售额、访客", r["days"] == 7 and {"day", "orders", "revenue", "visitors"} <= set(r["day_keys"]), f"{r['days']} 天 {r['day_keys']}")
    check(a, "模板下发接口签名校验通过", r["tpl_ok"] is True)
    check(a, "错误的密钥被店铺拒绝", r["wrong_key"] == "pchq_denied", r["wrong_key"])
    status, _, _ = http(ADMIN + "/?rest_route=/peptide/v1/hq/status")
    check(a, "不带签名的请求被拒绝", status in (401, 403), f"HTTP {status}")
    if r["login"]:
        status, _, headers = http(r["login"])
        target = headers.get("Location", headers.get("location", ""))
        check(a, "一键登录链接能用（跳进店铺后台）", status in (301, 302) and "/wp-admin" in target, f"HTTP {status} → {target[:80]}")
        status, _, headers = http(r["login"])
        target = headers.get("Location", headers.get("location", ""))
        check(a, "一键登录链接只能用一次", not (status in (301, 302) and "/wp-admin/" in target and "wp-login" not in target), f"第二次 HTTP {status} → {target[:80]}")
    else:
        check(a, "一键登录链接能用（跳进店铺后台）", False, "没有拿到链接")


AREAS = [("前台", area_front), ("加购", area_cart), ("结账", area_checkout), ("弃单", area_recovery), ("追踪", area_tracking), ("像素", area_pixels), ("后台", area_admin), ("总后台", area_hq)]


def setup():
    if subprocess.run(["sudo", "grep", "-q", "VIALCOVE_SANDBOX", SITE["shop"] + "/wp-config.php"]).returncode != 0:
        sys.exit("这不是沙盒，拒绝运行。")
    ctx = wp("admin", f"""
        $found = array();
        foreach ( wc_get_products( array( 'limit' => 60, 'status' => 'publish', 'type' => 'variable' ) ) as $p ) {{
            foreach ( $p->get_children() as $v ) {{ $x = wc_get_product( $v ); if ( $x && $x->is_purchasable() && $x->is_in_stock() && (float) $x->get_price() > 0 ) {{ $found[] = array( $p->get_id(), $v, (float) $x->get_price() ); break; }} }}
            if ( count( $found ) >= 2 ) {{ break; }}
        }}
        $c = new WC_Coupon(); $c->set_code( 'cp10-{RUN}' ); $c->set_discount_type( 'percent' ); $c->set_amount( 10 ); $c->save();
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 1 ) );
        $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
        pc_out( array( 'found' => $found, 'coupon' => 'cp10-{RUN}', 'coupon_id' => $c->get_id(), 'category_url' => $terms && ! is_wp_error( $terms ) ? get_term_link( $terms[0] ) : '', 'admin_login' => $admins ? $admins[0]->user_login : '' ) );
    """)
    if len(ctx["found"]) < 2:
        sys.exit("沙盒里找不到两个能买的商品，没法跑。")
    hq = wp("hq", "$a = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) ); pc_out( $a ? $a[0]->user_login : '' );")
    return {"product": ctx["found"][0][0], "variation": ctx["found"][0][1], "price": ctx["found"][0][2], "variation2": ctx["found"][1][1], "coupon": ctx["coupon"], "coupon_id": ctx["coupon_id"],
            "category_url": ctx["category_url"] if isinstance(ctx["category_url"], str) else "", "admin_login": ctx["admin_login"], "hq_login": hq, "orders": []}


def cleanup(ctx):
    ids = ",".join(str(int(i)) for i in ctx.get("orders", []))
    left = wp("admin", f"""
        global $wpdb;
        foreach ( array_filter( array_map( 'intval', explode( ',', '{ids}' ) ) ) as $id ) {{ $o = wc_get_order( $id ); if ( $o ) {{ $o->delete( true ); }} }}
        wp_delete_post( {int(ctx.get('coupon_id', 0))}, true );
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ( get_users( array( 'search' => 'cp.*.{RUN}@example.test', 'search_columns' => array( 'user_email' ) ) ) as $u ) {{ wp_delete_user( $u->ID ); }}
        $wpdb->query( $wpdb->prepare( "DELETE FROM {{$wpdb->prefix}}pc_mail_outbox WHERE recipient LIKE %s", '%.{RUN}@example.test%' ) );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{{$wpdb->prefix}}pc_recovery'" ) ) {{ $wpdb->query( $wpdb->prepare( "DELETE FROM {{$wpdb->prefix}}pc_recovery WHERE email LIKE %s", '%.{RUN}@example.test' ) ); }}
        pc_out( array( 'orders_left' => count( array_filter( array_map( 'wc_get_order', array_filter( array_map( 'intval', explode( ',', '{ids}' ) ) ) ) ) ) ) );
    """.replace("{{$wpdb->prefix}}", "{$wpdb->prefix}"))
    print(f"清理：本次建的订单还剩 {left['orders_left']} 张；临时优惠码、测试账号、邮件记录已删。")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--out")
    parser.add_argument("--only", default="")
    args = parser.parse_args()
    only = [x for x in args.only.split(",") if x]
    ctx = setup()
    try:
        for name, fn in AREAS:
            if only and name not in only:
                continue
            try:
                fn(ctx)
            except Exception as error:  # 一条链路里出了没料到的错：记为失败，继续跑别的链路
                record("FAIL", name, "这条链路中途出错", f"{type(error).__name__}: {str(error)[:300]}")
    finally:
        try:
            cleanup(ctx)
        except Exception as error:
            record("FAIL", "清理", "删除本次测试数据", str(error)[:300])
    counts = {s: sum(1 for r in RESULTS if r["status"] == s) for s in ("PASS", "FAIL", "SKIP")}
    print(f"结果：通过 {counts['PASS']}，失败 {counts['FAIL']}，跳过 {counts['SKIP']}")
    if args.out:
        with open(args.out, "w", encoding="utf-8") as handle:
            json.dump({"run": RUN, "counts": counts, "results": RESULTS}, handle, ensure_ascii=False, indent=1)
    return 1 if counts["FAIL"] else 0


if __name__ == "__main__":
    sys.exit(main())
