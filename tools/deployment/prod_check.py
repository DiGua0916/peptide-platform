#!/usr/bin/env python3
"""M1-4 生产验收脚本（prod-check）：发布流水线 G2（试点店）和 G3（其余店）用。

从外面像顾客一样访问一家店。两种跑法：

  快速版（G3，每家店替换后跑；只读，不下单）：
      prod_check.py --store https://www.example.com --management https://s0006.example.net --quick

  完整版（G2，试点店；会下一张测试单）：
      prod_check.py --store https://www.example.com --management https://s0006.example.net

完整版做什么：加一件商品 → 收件信息全填“1”（测试单规则：不发邮件、不进弃单、不计入报表）→ 下单 →
跟着支付通道给的地址走到付款页，确认刷卡框出来了。**不填卡、不付款。** 这张测试单会留在店里（待付款），
脚本最后打印订单号。沙盒验不了真实支付，这一步是唯一的真实支付通道检查。

完整版会在生产店里留下一张测试单，所以只在发布流程里、平台所有者确认过这次发布之后才跑。

输出：每项一行 PASS / FAIL / SKIP；有 FAIL 退出码 1（发布脚本据此回滚）。只用标准库。
"""

import argparse
from http.client import IncompleteRead
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

PHP_ERROR = re.compile(r"<b>(Fatal error|Warning|Notice|Deprecated)</b>:|There has been a critical error|Uncaught (Error|Exception)|Parse error", re.I)
STATEMENT = re.compile(r"research (use|purposes)", re.I)
CARD_FORM = re.compile(r"card[\s_-]?(number|no)|cardnumber|cc-number|卡号|name=[\"']?(pan|card)|<iframe", re.I)
UA = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 vialcove-prod-check"
RESULTS = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def http(url, method="GET", headers=None, body=None, timeout=60):
    headers = dict(headers or {})
    headers.setdefault("User-Agent", UA)
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    last = (0, "", {})
    # 只有读（GET）才重试：正文被中途掐断时（经过代理或 CDN 偶尔会这样）再取一次，最多 3 次。写操作绝不重发。
    for attempt in range(3 if method == "GET" else 1):
        try:
            with OPENER.open(urllib.request.Request(url, data=data, method=method, headers=headers), timeout=timeout) as response:
                try:
                    return response.status, response.read().decode("utf-8", "replace"), dict(response.headers)
                except IncompleteRead as cut:
                    last = (response.status, cut.partial.decode("utf-8", "replace"), dict(response.headers))
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8", "replace"), dict(error.headers)
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            last = (0, str(error), {})
        time.sleep(1 + attempt)
    return last


def follow(url, limit=5):
    """跟着跳转走，返回最后的 (状态码, 正文, 经过的地址)。"""
    chain = [url]
    for _ in range(limit):
        status, body, headers = http(chain[-1])
        target = headers.get("Location") or headers.get("location")
        if status in (301, 302, 303, 307, 308) and target:
            chain.append(urllib.parse.urljoin(chain[-1], target))
            continue
        return status, body, chain
    return status, body, chain


def record(status, name, detail=""):
    RESULTS.append({"status": status, "check": name, "detail": detail})
    print(f"{status:4} {name}" + (f"　{detail}" if detail else ""), flush=True)


def check(name, ok, detail=""):
    record("PASS" if ok else "FAIL", name, "" if ok else detail)
    return bool(ok)


def page(store, path, name, need_statement=False):
    status, body, chain = follow(store + path)
    same_site = urllib.parse.urlparse(chain[-1]).netloc == urllib.parse.urlparse(store).netloc
    ok = status == 200 and same_site and not PHP_ERROR.search(body) and (not need_statement or STATEMENT.search(body))
    found = PHP_ERROR.search(body)
    detail = f"HTTP {status}" + ("" if same_site else f"，跳到了站外 {chain[-1][:60]}") + (f"，{found.group(0)}" if found else "") + ("，没有“仅供研究”声明" if need_statement and status == 200 and not STATEMENT.search(body) else "")
    return check(name, ok, detail), body


class Cart:
    def __init__(self, store):
        self.api = store + "/?rest_route=/wc/store/v1"
        status, body, headers = http(self.api + "/cart")
        self.ok = status == 200
        self.nonce = headers.get("Nonce") or headers.get("nonce") or ""
        self.token = headers.get("Cart-Token") or headers.get("cart-token") or ""
        self.cart = json.loads(body) if self.ok else {}

    def call(self, method, route, body=None):
        status, text, headers = http(self.api + route, method, {"Nonce": self.nonce, "Cart-Token": self.token}, body)
        self.nonce = headers.get("Nonce") or headers.get("nonce") or self.nonce
        self.token = headers.get("Cart-Token") or headers.get("cart-token") or self.token
        try:
            data = json.loads(text)
        except ValueError:
            data = {"raw": text[:300]}
        if status in (200, 201) and isinstance(data, dict) and "totals" in data:
            self.cart = data
        return status, data


def pick_product(store):
    """公开的商品接口里找一个现在能买的（多规格商品取第一个款式）。"""
    status, body, _ = http(store + "/?rest_route=/wc/store/v1/products&per_page=10&stock_status=instock&_fields=id,variations,is_in_stock,is_purchasable")
    if status != 200:
        return None, None
    try:
        products = json.loads(body)
    except ValueError:
        return None, None
    for product in products:
        if not product.get("is_purchasable", True) and not product.get("variations"):
            continue
        if product.get("variations"):
            return product["id"], product["variations"][0]["id"]
        if product.get("is_in_stock"):
            return product["id"], product["id"]
    return None, None


def quick(store, management):
    page(store, "/", "首页 200、没有报错、有“仅供研究”声明", need_statement=True)
    product, _ = pick_product(store)
    check("商品接口能列出在售商品", product is not None)
    if product:
        page(store, f"/?vc_product={product}", "商品页 200、没有报错")
    page(store, "/cart/", "购物车页 200、没有报错")
    status, _, chain = follow(store + "/checkout/")
    check("结账页能到（空购物车时回购物车也算）", status == 200 and urllib.parse.urlparse(chain[-1]).netloc == urllib.parse.urlparse(store).netloc, f"HTTP {status} {chain[-1][:80]}")
    if management:
        status, body, _ = http(management + "/wp-login.php")
        check("店铺后台登录页 200、没有报错", status == 200 and not PHP_ERROR.search(body), f"HTTP {status}")
        status, body, _ = http(management + "/?rest_route=/peptide/v1/hq/status")
        check("总后台接口在线且拒绝未签名请求", status in (401, 403), f"HTTP {status}")
    return product


def full(store, management, gateway):
    product = quick(store, management)
    if not product:
        return
    _, variation = pick_product(store)
    cart = Cart(store)
    if not check("购物车接口可用", cart.ok and bool(cart.nonce)):
        return
    status, data = cart.call("POST", "/cart/add-item", {"id": variation, "quantity": 1})
    if not check("加购成功", status in (200, 201) and cart.cart.get("items_count") == 1, f"HTTP {status} {str(data)[:160]}"):
        return
    test = {"first_name": "1", "last_name": "1", "company": "", "address_1": "1", "address_2": "", "city": "1", "state": "TX", "postcode": "1", "country": "US", "phone": "1"}
    billing = dict(test, email="1")
    status, data = cart.call("POST", "/cart/update-customer", {"billing_address": billing, "shipping_address": test})
    check("测试单地址（全填 1）被接受", status == 200, f"HTTP {status} {str(data)[:200]}")
    rates = [r for package in cart.cart.get("shipping_rates", []) for r in package.get("shipping_rates", [])]
    check("有运费方案可选", len(rates) > 0)
    if rates and not any(r.get("selected") for r in rates):
        cart.call("POST", "/cart/select-shipping-rate", {"package_id": cart.cart["shipping_rates"][0]["package_id"], "rate_id": rates[0]["rate_id"]})
    methods = cart.cart.get("payment_methods", [])
    method = gateway if gateway in methods else (methods[0] if methods else "")
    check("有可用的付款方式" + (f"（期望 {gateway}）" if gateway else ""), bool(method) and (not gateway or method == gateway), f"可用：{methods}")
    if not method:
        return
    total = cart.cart.get("totals", {}).get("total_price")
    status, data = cart.call("POST", "/checkout", {"billing_address": billing, "shipping_address": test, "payment_method": method, "customer_note": "prod-check " + time.strftime("%Y-%m-%d %H:%M UTC", time.gmtime())})
    order = int(data.get("order_id", 0)) if isinstance(data, dict) else 0
    if not check("测试单下单成功", status == 200 and order > 0, f"HTTP {status} {str(data)[:240]}"):
        return
    print(f"     测试单订单号（店内编号）：{order}，金额 {total}（分），付款方式 {method}。它会留在店里，状态待付款。")
    result = data.get("payment_result", {}) if isinstance(data, dict) else {}
    pay_url = result.get("redirect_url", "")
    check("支付通道给出了付款地址", result.get("payment_status") in ("success", "pending") and pay_url.startswith("https://"), json.dumps(result)[:240])
    if pay_url:
        status, body, chain = follow(pay_url, limit=6)
        host = urllib.parse.urlparse(chain[-1]).netloc
        print(f"     付款页：{len(chain)} 步到 {host}，HTTP {status}，{len(body)} 字节")
        check("付款页能打开", status == 200 and not PHP_ERROR.search(body), f"HTTP {status} {chain[-1][:100]}")
        if method == "cod":
            check("货到付款：到了下单成功页", "order-received" in chain[-1] or "order-pay" in chain[-1], chain[-1][:100])
        else:
            check("付款页上有刷卡框", bool(CARD_FORM.search(body)), "页面里没找到卡号输入框或收银台 iframe")
    record("SKIP", "填卡付款", "按规定不填卡、不付款")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--store", required=True, help="顾客域名，例如 https://www.example.com")
    parser.add_argument("--management", default="", help="后台管理域名（可选）")
    parser.add_argument("--quick", action="store_true", help="只读的快速版，不下单")
    parser.add_argument("--gateway", default="", help="期望的付款方式（例如 pc_panapay）；不填就用结账页给的第一个")
    parser.add_argument("--out")
    args = parser.parse_args()
    store, management = args.store.rstrip("/"), args.management.rstrip("/")
    try:
        quick(store, management) if args.quick else full(store, management, args.gateway)
    except Exception as error:
        record("FAIL", "脚本中途出错", f"{type(error).__name__}: {str(error)[:300]}")
    counts = {s: sum(1 for r in RESULTS if r["status"] == s) for s in ("PASS", "FAIL", "SKIP")}
    print(f"结果：通过 {counts['PASS']}，失败 {counts['FAIL']}，跳过 {counts['SKIP']}")
    if args.out:
        with open(args.out, "w", encoding="utf-8") as handle:
            json.dump({"store": store, "mode": "quick" if args.quick else "full", "counts": counts, "results": RESULTS}, handle, ensure_ascii=False, indent=1)
    return 1 if counts["FAIL"] else 0


if __name__ == "__main__":
    sys.exit(main())
