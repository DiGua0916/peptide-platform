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
# Owner 2026-11-08: the third-party "cross-border-suite" plugin is never part of a copy;
# whatever the target has for it stays as it is.
IGNORED_PLUGIN_OPTIONS = ['cbs_%', 'cross_border%', 'cross-border%', 'payment_proxy%', 'cloaker%', 'advanced_cloaker%', 'woocommerce_payment_proxy%']

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
    # WordPress tables use zero dates as column defaults; strict sql_mode rejects CREATE TABLE ... LIKE.
    return run(args + ['-e', "SET SESSION sql_mode=''; " + query]).decode('utf-8', 'replace')


def store(slug):
    if slug in ('web01', 's0001'):  # the main store: legacy layout, registered as s0001
        return {'slug': 'web01', 'db': 'vialcove_web01', 'user': 'vialweb01', 'wp': '/srv/vialcove/current',
                'uploads': '/srv/vialcove-data/sites/web01/uploads', 'reports': '/srv/vialcove-data/sites/web01/private/reports'}
    if not re.fullmatch(r's[0-9]{4}', slug or ''):
        raise ValueError('store_slug')
    return {'slug': slug, 'db': 'vc_' + slug, 'user': 'vc' + slug, 'wp': '/srv/vialcove/tenants/' + slug + '/public',
            'uploads': '/srv/vialcove-data/tenants/' + slug + '/uploads', 'reports': '/srv/vialcove-data/tenants/' + slug + '/reports'}


def option(db, name):
    out = sql("SELECT option_value FROM wp_options WHERE option_name='" + name + "'", db).strip()
    return out


REVISION_PHP = (
    "use PeptideCore\\Templates; use PeptideCore\\Design_Sync; $made = 0;"
    "foreach ( get_posts( array( 'post_type' => 'pc_template', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {"
    "  $key = (string) get_post_meta( $id, Design_Sync::SYSTEM_META, true ); if ( '' === $key ) continue;"
    "  if ( get_posts( array( 'post_type' => 'pc_template', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => Design_Sync::REVISION_META, 'meta_value' => $key ) ) ) continue;"
    "  $rev = Templates::duplicate( (int) $id ); if ( ! $rev ) continue;"
    "  Templates::update_info( $rev, get_the_title( $id ) . ' · 修订版', '由系统模板复制，店铺可以随意修改；平台下发不会改动它。' );"
    "  update_post_meta( $rev, Design_Sync::REVISION_META, $key ); $made++; }"
    "if ( $made ) { $o = get_option( Design_Sync::OPTION, array() ); $o = is_array( $o ) ? $o : array(); $o['revision_created'] = true; update_option( Design_Sync::OPTION, $o, false ); }"
    "echo 'revisions_created=' . $made;"
)


def wp(s, *args):
    return run(['runuser', '-u', s['user'], '--', '/usr/local/bin/wp', '--path=' + s['wp'], '--skip-themes', *args],
               env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent'}).decode('utf-8', 'replace')


def host(url):
    m = re.match(r'https?://([^/]+)', url or '')
    return m.group(1) if m else ''


def clone(source, target, parts_arg='all', dry_run=False):
    if os.geteuid() != 0:
        raise ValueError('root_required')
    src, dst = store(source), store(target)
    if src['slug'] == dst['slug'] or dst['slug'] == 'web01':
        raise ValueError('target_invalid')
    if isinstance(parts_arg, (list, tuple, set)):
        parts = set(parts_arg)
    else:
        parts = set(PARTS) if parts_arg == 'all' else set(p for p in str(parts_arg).split(',') if p)
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
    if dry_run:
        return dict(plan, dry_run=True)

    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/var/backups/vialcove-clone') / (dst['slug'] + '-' + stamp)
    backup.mkdir(parents=True, mode=0o700)
    os.chmod(backup.parent, 0o700)
    with gzip.open(backup / (dst['db'] + '.sql.gz'), 'wb') as f:
        f.write(run(['mysqldump', '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces', dst['db']]))

    # 1) Remember the target's identity and (for unchosen setting groups) its own values.
    keep_where = ["option_name IN (" + ",".join("'" + k + "'" for k in sorted(KEEP_OPTIONS)) + ")",
                  "option_name REGEXP '" + KEEP_OPTION_PATTERN + "'"]
    keep_where += ["option_name LIKE '" + p + "'" for p in IGNORED_PLUGIN_OPTIONS]
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
    if 'design' in parts:
        # Every system template needs the editable "修订版" copy the normal HQ push creates on first receipt;
        # without it the store only sees locked templates (2026-11-08).
        wp(dst, 'eval', REVISION_PHP)
    admin = wp(dst, 'user', 'list', '--role=administrator', '--field=ID', '--number=1').strip()
    if admin:
        try:
            wp(dst, 'wc', 'tool', 'run', 'regenerate_product_lookup_tables', '--user=' + admin)
        except subprocess.CalledProcessError:
            pass
    counts = sql("SELECT post_type, COUNT(*) FROM wp_posts WHERE post_status<>'inherit' OR post_type='attachment' GROUP BY post_type", dd)
    return dict(plan, ok=True, backup=str(backup), files=copied,
                counts=dict(line.split('\t') for line in counts.strip().splitlines() if '\t' in line))


# ---------- HQ queue (admin host) and web entry point ----------
# Request (written by HQ into <state_root>/clone/requests/<operation_id>.json):
#   {version:1, operation_id, actor_id, source_site_id, source_uuid, target_site_id, target_uuid, parts:'all'|[...]}
# The admin worker resolves both stores to slugs, asks the web host over the existing forced-command
# SSH channel with phase 'clone', and writes a receipt into <state_root>/clone/results/.

UUID_RE = re.compile(r'[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}')
MESSAGES = {
    'target_invalid': '不能复制到主店或自己。', 'store_not_found': '没找到这家店铺，请刷新后重试。',
    'parts_invalid': '请至少选择一项要复制的内容。', 'database_missing': '目标店铺的数据库不存在。',
    'store_urls': '店铺地址不完整，不能复制。', 'remote_clone_failed': '店铺服务器没有完成复制，没有改动目标店铺。',
}


def helpers():
    path = Path(__file__).resolve().with_name('plugins.py')
    st = path.lstat()
    if not (st.st_uid == 0 and not st.st_mode & 0o022):
        raise ValueError('plugins_module_owner')
    import importlib.util
    spec = importlib.util.spec_from_file_location('vialcove_plugins', path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def validate_request(r):
    fields = {'version', 'operation_id', 'actor_id', 'source_site_id', 'source_uuid', 'source_slug', 'target_site_id', 'target_uuid', 'target_slug', 'parts'}
    if not (isinstance(r, dict) and set(r) == fields and r['version'] == 1):
        raise ValueError('clone_request_shape')
    for k in ('actor_id', 'source_site_id', 'target_site_id'):
        if type(r[k]) is not int or r[k] <= 0:
            raise ValueError(k)
    for k in ('operation_id', 'source_uuid', 'target_uuid'):
        if not (isinstance(r[k], str) and UUID_RE.fullmatch(r[k])):
            raise ValueError(k)
    for k in ('source_slug', 'target_slug'):
        if not (isinstance(r[k], str) and re.fullmatch(r's[0-9]{4}', r[k])):
            raise ValueError(k)
    if r['source_uuid'] == r['target_uuid'] or r['source_slug'] == r['target_slug']:
        raise ValueError('target_invalid')
    parts = r['parts']
    if parts != 'all' and not (isinstance(parts, list) and parts and all(p in PARTS for p in parts) and len(set(parts)) == len(parts)):
        raise ValueError('parts_invalid')
    return r


def web_main(c, payload):
    """Web host: run one copy. Called by worker.py when an envelope has phase 'clone'."""
    if c.get('role') != 'web' or not isinstance(payload, dict) or set(payload) != {'version', 'phase', 'source', 'target', 'parts'} \
            or payload['version'] != 1 or payload['phase'] != 'clone':
        raise ValueError('web_clone_shape')
    try:
        result = clone(str(payload['source']), str(payload['target']), payload['parts'])
        return {'ok': True, 'error_code': '', 'counts': result['counts'], 'files': result['files'], 'backup': result['backup']}
    except ValueError as e:
        return {'ok': False, 'error_code': str(e) if re.fullmatch(r'[a-z0-9_]{1,76}', str(e)) else 'clone_failed', 'counts': {}, 'files': {}, 'backup': ''}
    except subprocess.CalledProcessError:
        return {'ok': False, 'error_code': 'clone_command_failed', 'counts': {}, 'files': {}, 'backup': ''}


def remote(c, h, payload):
    args = ['/usr/bin/ssh', '-i', c['web_ssh_key'], '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + c['web_known_hosts'],
            '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=12', c['web_ssh_user'] + '@' + c['web_host'], 'lifecycle']
    p = subprocess.run(args, input=h.canonical(payload), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       timeout=1500, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent'})
    if p.returncode != 0:
        raise ValueError('remote_clone_failed')
    response = json.loads(p.stdout)
    if not (isinstance(response, dict) and set(response) == {'ok', 'error_code', 'counts', 'files', 'backup'}):
        raise ValueError('remote_clone_shape')
    if not response['ok']:
        code = response['error_code']
        raise ValueError(code if re.fullmatch(r'[a-z0-9_]{1,76}', str(code)) else 'remote_clone_failed')
    return response


def resolve_slug(c, h, store_uuid, claimed_slug):
    """Prefer the provisioning records; the main store (s0001) predates them and is accepted by name."""
    try:
        slug = h.resolve_admin(c, store_uuid)['slug']
    except ValueError as e:
        if str(e) != 'store_not_found' or claimed_slug != 's0001':
            raise
        return 's0001'
    if slug != claimed_slug:
        raise ValueError('store_identity_changed')
    return slug


def process_queue(c):
    """Admin host: handle at most one copy per worker run (a copy can take a minute)."""
    if c.get('role') != 'admin':
        raise ValueError('admin_role')
    h = helpers()
    base = Path(c['state_root']) / 'clone'
    if not base.exists():
        base.mkdir(mode=0o711)
        os.chmod(base, 0o711)
    if not (base.is_dir() and not base.is_symlink() and base.stat().st_uid == os.geteuid()):
        raise ValueError('clone_root')
    try:
        with h.lock(base / 'worker.lock', nonblocking=True):
            requests = h.queue_dir(base / 'requests', c['hq_user'], 0o730)
            results = h.queue_dir(base / 'results', c['hq_user'], 0o750)
            import grp as _grp, time as _time
            gid = _grp.getgrnam(c['hq_user']).gr_gid
            processed = 0
            for entry in sorted(requests.glob('*.json'))[:3]:  # batch copies: a few per run, one after another
                try:
                    request = validate_request(json.loads(h.read_checked(entry, owners=h.trusted_uids(c), limit=4096)))
                    if entry.name != request['operation_id'] + '.json':
                        raise ValueError('request_filename')
                except Exception:
                    rejected = h.private_dir(base / 'rejected')
                    if not entry.is_symlink():
                        os.replace(entry, rejected / (os.urandom(8).hex() + '.json'))
                    continue
                receipt = results / entry.name
                if receipt.exists():
                    entry.unlink()
                    continue
                value = {'operation_id': request['operation_id'], 'source_site_id': request['source_site_id'],
                         'target_site_id': request['target_site_id'], 'parts': request['parts'], 'state': 'running',
                         'error_code': '', 'message': '', 'counts': {}, 'files': {}, 'started_at': int(_time.time()), 'finished_at': 0}
                h.atomic(receipt, h.canonical(value), mode=0o640, gid=gid)
                try:
                    src = resolve_slug(c, h, request['source_uuid'], request['source_slug'])
                    dst = resolve_slug(c, h, request['target_uuid'], request['target_slug'])
                    response = remote(c, h, {'version': 1, 'phase': 'clone', 'source': src, 'target': dst, 'parts': request['parts']})
                    value.update(state='done', counts=response['counts'], files=response['files'])
                except Exception as exc:
                    code = h.safe_error(exc)
                    value.update(state='failed', error_code=code,
                                 message=MESSAGES.get(code.removeprefix('web_'), '这次复制没有完成，目标店铺没有改动。可以再试一次；仍失败请把括号里的错误代码发给技术人员。'))
                value['finished_at'] = int(_time.time())
                h.atomic(receipt, h.canonical(value), mode=0o640, gid=gid)
                entry.unlink()
                processed += 1
            return processed
    except BlockingIOError:
        return 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--source', required=True)
    ap.add_argument('--target', required=True)
    ap.add_argument('--parts', default='all')
    ap.add_argument('--dry-run', action='store_true')
    a = ap.parse_args()
    print(json.dumps(clone(a.source, a.target, a.parts, a.dry_run), ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as e:
        sys.stderr.write('clone failed: ' + ' '.join(map(str, e.cmd[:3])) + ' ' + e.stderr.decode('utf-8', 'replace')[-800:] + '\n')
        sys.exit(1)
    except ValueError as e:
        sys.stderr.write('clone refused: ' + str(e) + '\n')
        sys.exit(2)
