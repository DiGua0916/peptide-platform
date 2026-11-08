#!/usr/bin/env python3
"""Copy one store into another on the web host (2026-11-08).

Owner request: a new store should be a faithful copy of an existing store, either complete
("一键完整复制") or by chosen parts ("选择复制"). Orders, customers, visitor tracking and
payment records are never copied.

How it works (root, web host, all store databases on the local MySQL):
  1. Back up the target database and record its own identity settings.
  2. Replace the target's content tables with the source's (same IDs, so every link
     between products, images, pages and designs stays intact).
  3. Remove what must not travel: orders, order notes, coupon usage, revisions, trash.
  4. Remove the parts that were not chosen.
  5. Put back the target's identity settings (domain, store name, HQ connection key,
     payment link, emails, version markers, scheduled jobs).
  6. Rewrite the source's addresses to the target's, copy media / report files, flush caches.

Usage:
  clone.py --source web01|sNNNN --target sNNNN [--parts all|products,media,...] [--dry-run]
Prints one JSON line with the result. The target must not be the main store.
"""
import argparse
import datetime
import gzip
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

PARTS = {
    'products': '商品（分类、规格、价格、库存）',
    'media': '图片与媒体库',
    'pages': '页面与菜单',
    'design': '店铺装修（模板、首页、样式）',
    'reports': 'COA 报告',
    'marketing': '营销（优惠码、活动、应用设置）',
    'settings': '店铺设置（运费、结账、邮件模板等）',
    'tracking': '像素与追踪设置',
}

# Content tables replaced wholesale; anything else in the target is left untouched.
TABLES = ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
          'comments', 'commentmeta', 'options', 'links',
          'wc_product_meta_lookup', 'wc_product_attributes_lookup', 'wc_category_lookup',
          'woocommerce_attribute_taxonomies', 'wc_tax_rate_classes', 'woocommerce_tax_rates',
          'woocommerce_tax_rate_locations', 'woocommerce_shipping_zones',
          'woocommerce_shipping_zone_locations', 'woocommerce_shipping_zone_methods',
          'wc_product_download_directories']

# Target's own identity: always restored after the copy.
KEEP_OPTIONS = {'siteurl', 'home', 'blogname', 'admin_email', 'new_admin_email', 'admin_email_lifespan',
                'peptide_core_hq', 'pc_panapay_site_id', 'pc_panapay_central_managed', 'cron', 'rewrite_rules',
                'active_plugins', 'recently_activated', 'uninstall_plugins', 'fluentmail-settings',
                'woocommerce_store_id', 'woocommerce_admin_install_timestamp', 'initial_db_version',
                'woocommerce_initial_installed_version', 'woocommerce_email_from_address',
                'woocommerce_stock_email_recipient', 'woocommerce_pos_store_email',
                'peptide_core_recovery_optout', 'recovery_keys', 'recovery_mode_email_last_sent'}
KEEP_OPTION_PATTERN = r'(_db|_db_version|_schema|_schema_version|_version)$'

POST_TYPES = {
    'products': ['product', 'product_variation'],
    'media': ['attachment'],
    'pages': ['page', 'nav_menu_item', 'wp_navigation', 'post'],
    'design': ['pc_layout', 'pc_template', 'custom_css', 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_block'],
    'reports': ['pc_report'],
    'marketing': ['shop_coupon', 'pc_promotion'],
}
TAXONOMIES = {
    'products': ['product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class', 'pa_%'],
    'pages': ['nav_menu'],
}
# Option groups by part (LIKE patterns). An unchosen group keeps the target's own values.
OPTION_GROUPS = {
    'design': ['theme_mods_%', 'peptide_core_site_design%', 'peptide_core_store_design%', 'pc_design%', 'peptide_core_design%',
               'peptide_core_modules%', 'peptide_core_menus%', 'sidebars_widgets', 'widget_%'],
    'marketing': ['peptide_core_apps%', 'peptide_core_promotion%', 'peptide_core_coupon%', 'peptide_core_first_order%',
                  'peptide_core_exit_intent%', 'peptide_core_activity%', 'peptide_core_broadcast%', 'peptide_core_countdown%',
                  'peptide_core_recently_viewed%', 'peptide_core_cart_feedback%', 'peptide_core_postal%', 'peptide_core_loyalty%',
                  'peptide_core_marketing%', 'pc_app_%'],
    'tracking': ['peptide_core_pixels%', 'peptide_core_tracking%', 'peptide_core_attribution%'],
    'settings': ['woocommerce_%', 'peptide_core_shipping%', 'peptide_core_store%', 'peptide_core_terms%', 'peptide_core_checkout%',
                 'peptide_core_support%', 'peptide_core_basic%', 'peptide_core_emails%', 'peptide_core_email%', 'peptide_core_currency%',
                 'peptide_core_storefront%', 'peptide_core_language%'],
}


def run(args, **kw):
    return subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kw).stdout


def sql(query, db=None):
    args = ['mysql', '--batch', '--skip-column-names', '--default-character-set=utf8mb4']
    if db:
        args.append(db)
    return run(args + ['-e', query]).decode('utf-8', 'replace')


def store(slug):
    if slug == 'web01':
        return {'slug': 'web01', 'db': 'vialcove_web01', 'user': 'vialweb01', 'wp': '/srv/vialcove/current',
                'uploads': '/srv/vialcove-data/sites/web01/uploads', 'reports': '/srv/vialcove-data/sites/web01/private/reports'}
    if not re.fullmatch(r's[0-9]{4}', slug or '') or slug == 's0001':
        raise ValueError('store_slug')
    return {'slug': slug, 'db': 'vc_' + slug, 'user': 'vc' + slug, 'wp': '/srv/vialcove/tenants/' + slug + '/public',
            'uploads': '/srv/vialcove-data/tenants/' + slug + '/uploads', 'reports': '/srv/vialcove-data/tenants/' + slug + '/reports'}


def option(db, name):
    out = sql("SELECT option_value FROM wp_options WHERE option_name='" + name + "'", db).strip()
    return out


def wp(s, *args):
    return run(['runuser', '-u', s['user'], '--', '/usr/local/bin/wp', '--path=' + s['wp'], '--skip-themes', *args],
               env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent'}).decode('utf-8', 'replace')


def host(url):
    m = re.match(r'https?://([^/]+)', url or '')
    return m.group(1) if m else ''


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--source', required=True)
    ap.add_argument('--target', required=True)
    ap.add_argument('--parts', default='all')
    ap.add_argument('--dry-run', action='store_true')
    a = ap.parse_args()
    if os.geteuid() != 0:
        raise ValueError('root_required')
    src, dst = store(a.source), store(a.target)
    if src['slug'] == dst['slug'] or dst['slug'] == 'web01':
        raise ValueError('target_invalid')
    parts = set(PARTS) if a.parts == 'all' else set(p for p in a.parts.split(',') if p)
    if not parts or parts - set(PARTS):
        raise ValueError('parts_invalid')
    for s in (src, dst):
        if sql("SHOW DATABASES LIKE '" + s['db'] + "'").strip() != s['db']:
            raise ValueError('database_missing_' + s['slug'])
    src_home, src_admin = option(src['db'], 'home').rstrip('/'), option(src['db'], 'siteurl').rstrip('/')
    dst_home, dst_admin = option(dst['db'], 'home').rstrip('/'), option(dst['db'], 'siteurl').rstrip('/')
    if not (src_home and dst_home and src_admin and dst_admin):
        raise ValueError('store_urls')
    plan = {'source': src['slug'], 'target': dst['slug'], 'parts': sorted(parts),
            'from': [src_home, src_admin], 'to': [dst_home, dst_admin]}
    if a.dry_run:
        print(json.dumps(dict(plan, dry_run=True), ensure_ascii=False))
        return

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/var/backups/vialcove-clone') / (dst['slug'] + '-' + stamp)
    backup.mkdir(parents=True, mode=0o700)
    os.chmod(backup.parent, 0o700)
    with gzip.open(backup / (dst['db'] + '.sql.gz'), 'wb') as f:
        f.write(run(['mysqldump', '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces', dst['db']]))

    # 1) Remember the target's identity and (for unchosen setting groups) its own values.
    keep_where = ["option_name IN (" + ",".join("'" + k + "'" for k in sorted(KEEP_OPTIONS)) + ")",
                  "option_name REGEXP '" + KEEP_OPTION_PATTERN + "'"]
    for group, patterns in OPTION_GROUPS.items():
        if group not in parts:
            # Page-ID settings always follow the copied pages, even when settings are not chosen.
            keep_where += ["(option_name LIKE '" + p + "' AND option_name NOT LIKE '%\\_page\\_id')" for p in patterns]
    sd, dd = src['db'], dst['db']
    sql("DROP TABLE IF EXISTS pc_clone_keep; CREATE TABLE pc_clone_keep AS SELECT option_name, option_value, autoload FROM wp_options WHERE "
        + " OR ".join(keep_where), dd)

    # 2) Replace content tables with the source's.
    for t in TABLES:
        if sql("SHOW TABLES LIKE 'wp_" + t + "'", sd).strip() != 'wp_' + t:
            continue
        sql("DROP TABLE IF EXISTS `" + dd + "`.`wp_" + t + "`; CREATE TABLE `" + dd + "`.`wp_" + t + "` LIKE `" + sd + "`.`wp_" + t + "`; "
            "INSERT INTO `" + dd + "`.`wp_" + t + "` SELECT * FROM `" + sd + "`.`wp_" + t + "`;")

    # 3) Never copied: orders, order notes, coupon usage, revisions, trash, transients.
    cleanup = [
        "DELETE p, m FROM wp_posts p LEFT JOIN wp_postmeta m ON m.post_id=p.ID WHERE p.post_type IN ('shop_order','shop_order_placehold','shop_order_refund','shop_subscription','revision') OR p.post_status IN ('trash','auto-draft')",
        "DELETE c, cm FROM wp_comments c LEFT JOIN wp_commentmeta cm ON cm.comment_id=c.comment_ID WHERE c.comment_type IN ('order_note','webhook_delivery','action_log')",
        "DELETE m FROM wp_postmeta m JOIN wp_posts p ON p.ID=m.post_id WHERE p.post_type='shop_coupon' AND m.meta_key IN ('_used_by','_maybe_used_by')",
        "UPDATE wp_postmeta m JOIN wp_posts p ON p.ID=m.post_id SET m.meta_value='0' WHERE p.post_type='shop_coupon' AND m.meta_key='usage_count'",
        "UPDATE wp_postmeta m JOIN wp_posts p ON p.ID=m.post_id SET m.meta_value='0' WHERE p.post_type='product' AND m.meta_key='total_sales'",
        "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'",
        "DELETE m FROM wp_postmeta m LEFT JOIN wp_posts p ON p.ID=m.post_id WHERE p.ID IS NULL",
    ]
    # 4) Remove what was not chosen.
    core_pages = ("SELECT CAST(option_value AS UNSIGNED) FROM (SELECT option_value FROM wp_options WHERE option_name REGEXP "
                  "'^(woocommerce_.*_page_id|page_on_front|page_for_posts|wp_page_for_privacy_policy)$') AS core")
    for part, types in POST_TYPES.items():
        if part in parts:
            continue
        # WooCommerce's shop/cart/checkout/account pages and the front page always stay: the store needs them.
        cleanup.append("DELETE p, m FROM wp_posts p LEFT JOIN wp_postmeta m ON m.post_id=p.ID WHERE p.post_type IN ("
                       + ",".join("'" + t + "'" for t in types) + ") AND p.ID NOT IN (" + core_pages + ")")
    if 'design' in parts:
        # The source may be HQ's template studio: its "push to stores" templates arrive as this store's
        # system templates (key m<studio id>), so later HQ pushes update them instead of adding duplicates.
        cleanup += ["UPDATE wp_postmeta SET meta_key='_pc_system_template', meta_value=CONCAT('m', post_id) "
                    "WHERE meta_key='_pc_hq_system' AND meta_value NOT IN ('', '0')",
                    "DELETE FROM wp_postmeta WHERE meta_key='_pc_hq_system'",
                    "DELETE FROM wp_options WHERE option_name='peptide_core_template_domains'"]
    for part, taxes in TAXONOMIES.items():
        if part in parts:
            continue
        cond = " OR ".join("tt.taxonomy LIKE '" + x + "'" for x in taxes)
        cleanup.append("DELETE tr FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE " + cond)
        cleanup.append("DELETE t, tt FROM wp_term_taxonomy tt JOIN wp_terms t ON t.term_id=tt.term_id WHERE " + cond)
    if 'products' not in parts:
        cleanup += ["TRUNCATE wp_wc_product_meta_lookup", "TRUNCATE wp_wc_product_attributes_lookup", "TRUNCATE wp_wc_category_lookup"]
    # 5) Put the target's identity (and unchosen setting groups) back.
    cleanup += ["DELETE o FROM wp_options o JOIN pc_clone_keep k ON k.option_name=o.option_name",
                "INSERT INTO wp_options (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM pc_clone_keep",
                "DROP TABLE pc_clone_keep",
                "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('woocommerce_coming_soon','no','yes') "
                "ON DUPLICATE KEY UPDATE option_value='no'"]
    sql("; ".join(cleanup) + ";", dd)

    # 6) Addresses: only full URLs and the two hostnames, never bare domains (keeps email addresses intact).
    pairs = []
    for old, new in [(src_home, dst_home), (src_admin, dst_admin)]:
        for scheme in ('https://', 'http://', '//'):
            pairs.append((scheme + host(old), scheme + host(new)))
    pairs.append((src['uploads'], dst['uploads']))
    pairs.append((src['reports'], dst['reports']))
    seen = set()
    for old, new in pairs:
        if old == new or old in seen:
            continue
        seen.add(old)
        wp(dst, 'search-replace', old, new, *['wp_' + t for t in TABLES], '--skip-columns=guid', '--precise', '--quiet')

    # Files: media library and private report files.
    copied = {}
    for key, part in (('uploads', 'media'), ('reports', 'reports')):
        if part not in parts or not Path(src[key]).is_dir():
            continue
        Path(dst[key]).mkdir(parents=True, exist_ok=True)
        run(['rsync', '-a', '--delete', '--exclude=wc-logs/', '--exclude=cache/',
             '--chown=' + dst['user'] + ':' + dst['user'], src[key] + '/', dst[key] + '/'])
        media_user = 'vcm_' + dst['slug']
        if subprocess.run(['id', media_user], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            acl = 'u:' + media_user + ':rwX,u:' + dst['user'] + ':rwX'
            run(['setfacl', '-R', '-m', acl, dst[key]])
            run(['setfacl', '-R', '-d', '-m', acl, dst[key]])
        copied[key] = run(['du', '-sh', dst[key]]).decode().split()[0]

    wp(dst, 'cache', 'flush')
    wp(dst, 'rewrite', 'flush')
    admin = wp(dst, 'user', 'list', '--role=administrator', '--field=ID', '--number=1').strip()
    if admin:
        try:
            wp(dst, 'wc', 'tool', 'run', 'regenerate_product_lookup_tables', '--user=' + admin)
        except subprocess.CalledProcessError:
            pass
    counts = sql("SELECT post_type, COUNT(*) FROM wp_posts WHERE post_status<>'inherit' OR post_type='attachment' GROUP BY post_type", dd)
    print(json.dumps(dict(plan, ok=True, backup=str(backup), files=copied,
                          counts=dict(line.split('\t') for line in counts.strip().splitlines() if '\t' in line)),
                     ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as e:
        sys.stderr.write('clone failed: ' + ' '.join(map(str, e.cmd[:3])) + ' ' + e.stderr.decode('utf-8', 'replace')[-800:] + '\n')
        sys.exit(1)
    except ValueError as e:
        sys.stderr.write('clone refused: ' + str(e) + '\n')
        sys.exit(2)
