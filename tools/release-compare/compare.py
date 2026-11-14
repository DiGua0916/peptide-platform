#!/usr/bin/env python3
"""发布前后“同一份数据逐页比对”的 44 个地址（F5，2026-11-14）。只在沙盒或本机副本上用。

  python3 compare.py fetch <配置文件> <输出目录> [--insecure]   取一遍（配置文件由 prepare.php 生成）
  python3 compare.py diff <目录A> <目录B> [--show N]             比两遍，逐个地址报一致 / 差异

做法：每个地址按对应身份取一次（不跟随跳转，记状态码和跳转地址），把每次都会变的东西（nonce、静态文件版本号、
时间、随机编号、来源地址、主题指纹、WooCommerce 数量框编号）统一去掉，再按行比（多重集差）。
访客请求带一个无关的 cookie，让首页缓存一律不命中，比的是代码当场画出来的页面。
只用 Python 标准库。
"""
import hashlib, json, os, re, ssl, sys, urllib.error, urllib.request
from collections import Counter

A = '/wp-admin/admin.php?page='


def addresses(cfg):
    o = cfg['order']
    simple = cfg['products']['simple']
    v1, v2 = cfg['products']['variable'][:2]
    cat = cfg['category']
    out = [('buyer', f'buyer:{p}', A + p) for p in ['pc-buyer-home', 'pc-recovery', 'pc-data', 'pc-buyer-ads', 'pc-creatives', 'pc-creatives-spend', 'pc-creatives-board', 'pc-visitors', 'pc-store-info', 'pc-onboarding']]
    out += [
        ('buyer', 'buyer:index.php', '/wp-admin/index.php'),
        ('buyer', 'buyer:profile.php', '/wp-admin/profile.php'),
        ('buyer', 'buyer:pc-overview', A + 'pc-overview'),
        ('buyer', 'buyer:pc-promotions', A + 'pc-promotions'),
        ('buyer', 'buyer:pc-apps', A + 'pc-apps'),
        ('buyer', 'buyer:orders-processing', A + 'wc-orders&status=wc-processing'),
        ('buyer', 'buyer:orders-all', A + 'wc-orders'),
        ('buyer', 'buyer:order-detail', A + f'pc-order&id={o}'),
        ('buyer', 'buyer:order-edit-redirect', A + f'wc-orders&action=edit&id={o}'),
        ('buyer', 'buyer:order-native', A + f'wc-orders&action=edit&id={o}&pc_native=1'),
    ]
    out += [('relnew', f'mustchange:{p}', A + p) for p in ['pc-onboarding', 'pc-buyer-home', 'wc-orders&status=wc-processing', 'pc-store-info']]
    out += [('localadmin', f'admin:{p}', A + p) for p in ['wc-orders&status=wc-processing', f'pc-order&id={o}', 'pc-staff', 'pc-overview', 'pc-promotions', 'pc-buyer-ads', 'pc-store-info']]
    out += [
        ('visitor', 'visitor:home', '/'),
        ('visitor', 'visitor:landing-simple', f"/?vc_product={simple['id']}"),
        ('visitor', 'visitor:landing-variable-1', f"/?vc_product={v1['id']}"),
        ('visitor', 'visitor:landing-variable-2', f"/?vc_product={v2['id']}"),
        ('visitor', 'visitor:product-link-simple', simple['path']),
        ('visitor', 'visitor:product-link-variable', v1['path']),
        ('visitor', 'visitor:collection-landing', f"/?vc_catalog_category={cat['id']}"),
        ('visitor', 'visitor:collection-link', cat['path']),
        ('visitor', 'visitor:track-order-page', '/track-order/'),
        ('visitor', 'visitor:dialog-api-simple', f"/wp-json/vialcove/v1/home-product/{simple['id']}"),
        ('visitor', 'visitor:dialog-api-variable', f"/wp-json/vialcove/v1/home-product/{v1['id']}"),
        ('visitor', 'visitor:buyer-status-api', '/wp-json/peptide/v1/buyer/status'),
        ('visitor', 'visitor:home-utm', '/?utm_source=fb&utm_campaign=c1&fbclid=abc123'),
    ]
    assert len(out) == 44, len(out)
    return out


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def fetch(cfg_path, out, insecure):
    cfg = json.load(open(cfg_path, encoding='utf-8'))
    ctx = ssl._create_unverified_context() if insecure else None
    opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPSHandler(context=ctx))
    os.makedirs(out, exist_ok=True)
    index = {}
    for role, key, path in addresses(cfg):
        if role != 'visitor' and role not in cfg['roles']:
            print(f'SKIP {key}（配置里没有 {role} 这个身份）')
            continue
        cookies = cfg['roles'][role]['cookies'] if role != 'visitor' else {'pc_release_compare': '1'}
        req = urllib.request.Request(cfg['base'] + path, headers={
            'Cookie': '; '.join(f'{k}={v}' for k, v in cookies.items()),
            'User-Agent': 'Mozilla/5.0 (release-compare)',
        })
        try:
            r = opener.open(req, timeout=120)
            status, loc, body = r.status, r.headers.get('Location', ''), r.read()
        except urllib.error.HTTPError as e:
            status, loc, body = e.code, e.headers.get('Location', ''), e.read()
        body = body.decode('utf-8', 'replace')
        name = hashlib.md5(key.encode()).hexdigest()[:10] + '.html'
        open(os.path.join(out, name), 'w', encoding='utf-8').write(body)
        index[key] = {'path': path, 'status': status, 'location': loc.replace(cfg['base'], ''), 'file': name}
        print(f'{status} {key} {loc[:80]}')
    json.dump(index, open(os.path.join(out, 'index.json'), 'w'), indent=1)


RULES = [
    (r'ver=[^"\'&\s<>)]*', 'ver=V'),
    (r'(?i)(nonce|_wpnonce|_ajax_nonce|nonce_value)(["\'=:\s%3A2C]+)[A-Za-z0-9]{10}', r'\1\2X'),
    (r'createNonceMiddleware\(\s*"[^"]*"\s*\)', 'createNonceMiddleware("X")'),
    (r'(?<=[_-])[0-9a-f]{12,}', 'U'),  # uniqid()，例如 quantity_6af768a265c31
    (r'\b[0-9a-f]{8,}\b', 'H'),
    (r'\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?([+-]\d{2}:?\d{2}|Z)?)?', 'D'),
    (r'\b\d{1,2}:\d{2}(:\d{2})?\b', 'T'),
    (r'\d{5,}', 'N'),
    (r'_dr=[^"\'&\s<>]*', '_dr=R'),
    (r'release["\']?\s*[:=]\s*["\'][^"\']*["\']', 'release:"R"'),
]


def lines(text):
    for pat, rep in RULES:
        text = re.sub(pat, rep, text)
    return [p.strip() for p in re.split(r'\n|(?<=>)(?=<)', text) if p.strip()]


def diff(da, db, show):
    ia = json.load(open(os.path.join(da, 'index.json')))
    ib = json.load(open(os.path.join(db, 'index.json')))
    keys = [k for k in ia if k in ib]
    same = 0
    for key in keys:
        a, b = ia[key], ib[key]
        ca = Counter(lines(open(os.path.join(da, a['file']), encoding='utf-8').read()))
        cb = Counter(lines(open(os.path.join(db, b['file']), encoding='utf-8').read()))
        only_a, only_b = list((ca - cb).elements()), list((cb - ca).elements())
        loc_a = re.sub(r'_wpnonce=[^&]*', '_wpnonce=X', a['location'])
        loc_b = re.sub(r'_wpnonce=[^&]*', '_wpnonce=X', b['location'])
        ok = a['status'] == b['status'] and loc_a == loc_b and not only_a and not only_b
        same += ok
        moved = f' 跳转 {loc_a[:60]} -> {loc_b[:60]}' if loc_a != loc_b else ''
        print(f"{'SAME' if ok else 'DIFF'} {key}: {a['status']}->{b['status']}{moved} 行 -{len(only_a)} +{len(only_b)}")
        if not ok and show:
            for l in only_a[:show]:
                print('    - ' + l[:220])
            for l in only_b[:show]:
                print('    + ' + l[:220])
    print(f'{same} / {len(keys)} 一致')


if __name__ == '__main__':
    if len(sys.argv) < 4 or sys.argv[1] not in ('fetch', 'diff'):
        sys.exit(__doc__)
    if sys.argv[1] == 'fetch':
        fetch(sys.argv[2], sys.argv[3], '--insecure' in sys.argv)
    else:
        diff(sys.argv[2], sys.argv[3], int(sys.argv[sys.argv.index('--show') + 1]) if '--show' in sys.argv else 0)
