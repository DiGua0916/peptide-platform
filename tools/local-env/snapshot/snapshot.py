#!/usr/bin/env python3
"""脱敏数据快照：把一家店（或总后台）的数据库复制到临时库，脱敏、检查，再导出。

原则
  * 生产库只读：只对它做 mysqldump --single-transaction。所有改写都发生在临时库 vcsnap_*。
  * 原始数据不离开数据库服务器：只有脱敏并通过泄漏检查的导出文件才允许下载。
  * 不认识的表一律拒绝导出（fail closed）：新模块加了表，要先在本文件里登记怎么处理。
  * 标准库 only，单文件，可以 `ssh host 'sudo python3 - export …' < snapshot.py` 直接执行。

用法（在数据库所在的机器上，以 root 执行；拉生产快照前必须先得到平台所有者同意）
  snapshot.py export --db vc_s0010 --kind store --out /root/vialcove-snapshots --allow-domain vialcoveplus.com
  snapshot.py export --db vialcove_hq --kind hq --out /root/vialcove-snapshots

离线自检（不连数据库）
  snapshot.py plan --kind store --tables tables.txt        打印将要执行的 SQL
"""

import argparse
import datetime
import gzip
import os
import re
import secrets
import subprocess
import sys

KEEP, TRUNCATE, SCRUB = "keep", "truncate", "scrub"

# ---------------------------------------------------------------------------------------------
# 每张表怎么处理。键是去掉前缀后的表名。SCRUB 的具体语句在 scrub_statements() 里。
# ---------------------------------------------------------------------------------------------
STORE_TABLES = {
    # WordPress
    "commentmeta": KEEP, "comments": SCRUB, "links": KEEP, "options": SCRUB, "postmeta": SCRUB, "posts": KEEP,
    "term_relationships": KEEP, "term_taxonomy": KEEP, "termmeta": KEEP, "terms": KEEP, "usermeta": SCRUB, "users": SCRUB,
    # 任务队列和日志：参数里带邮箱，本地不需要历史
    "actionscheduler_actions": TRUNCATE, "actionscheduler_claims": TRUNCATE, "actionscheduler_groups": KEEP, "actionscheduler_logs": TRUNCATE,
    "fsmpt_email_logs": TRUNCATE, "woocommerce_log": TRUNCATE, "wc_download_log": TRUNCATE, "wc_rate_limits": TRUNCATE,
    # WooCommerce 订单与顾客
    "wc_orders": SCRUB, "wc_order_addresses": SCRUB, "wc_orders_meta": SCRUB, "wc_customer_lookup": SCRUB,
    "wc_order_operational_data": KEEP, "wc_order_stats": KEEP, "wc_order_product_lookup": KEEP, "wc_order_coupon_lookup": KEEP,
    "wc_order_tax_lookup": KEEP, "wc_order_fulfillments": KEEP, "wc_order_fulfillment_meta": KEEP,
    "woocommerce_order_items": KEEP, "woocommerce_order_itemmeta": KEEP,
    "wc_stock_notifications": SCRUB, "wc_stock_notificationmeta": KEEP, "woocommerce_downloadable_product_permissions": SCRUB,
    # 会话、密钥、令牌
    "woocommerce_sessions": TRUNCATE, "woocommerce_api_keys": TRUNCATE, "wc_webhooks": TRUNCATE,
    "woocommerce_payment_tokens": TRUNCATE, "woocommerce_payment_tokenmeta": TRUNCATE,
    # WooCommerce 商品、运费、税
    "wc_admin_notes": KEEP, "wc_admin_note_actions": KEEP, "wc_category_lookup": KEEP, "wc_product_attributes_lookup": KEEP,
    "wc_product_download_directories": KEEP, "wc_product_meta_lookup": KEEP, "wc_reserved_stock": TRUNCATE, "wc_tax_rate_classes": KEEP,
    "woocommerce_attribute_taxonomies": KEEP, "woocommerce_shipping_zone_locations": KEEP, "woocommerce_shipping_zone_methods": KEEP,
    "woocommerce_shipping_zones": KEEP, "woocommerce_tax_rate_locations": KEEP, "woocommerce_tax_rates": KEEP,
    # peptide-core
    "pc_activity": TRUNCATE, "pc_ai_results": TRUNCATE, "pc_app_stats": KEEP,
    "pc_email_campaign_deliveries": TRUNCATE, "pc_email_campaigns": KEEP, "pc_email_log": TRUNCATE, "pc_mail_outbox": TRUNCATE,
    "pc_panapay_creations": TRUNCATE, "pc_panapay_transactions": TRUNCATE,
    "pc_points_accounts": KEEP, "pc_points_ledger": SCRUB, "pc_px_log": KEEP, "pc_recovery": SCRUB, "pc_referral_touches": KEEP,
    "pc_subscriptions": SCRUB,
    # 追踪表按设计不存姓名、邮箱、电话、地址，只有匿名访客号
    "pc_track_events": KEEP, "pc_track_tabs": KEEP, "pc_track_visitors": KEEP, "pc_track_visits": KEEP,
}
HQ_TABLES = {
    "commentmeta": KEEP, "comments": SCRUB, "links": KEEP, "options": SCRUB, "postmeta": SCRUB, "posts": KEEP,
    "term_relationships": KEEP, "term_taxonomy": KEEP, "termmeta": KEEP, "terms": KEEP, "usermeta": SCRUB, "users": SCRUB,
    "fsmpt_email_logs": TRUNCATE,
    # 支付 Hub：密钥盒、收银台参数、交易回执
    "pc_pay_hub_attempts": TRUNCATE, "pc_pay_hub_details": TRUNCATE, "pc_pay_hub_events": TRUNCATE,
    "pchq_alerts": KEEP, "pchq_daily": KEEP, "pchq_mail_log": TRUNCATE, "pchq_plugins": KEEP, "pchq_servers": KEEP,
    "pchq_site_plugins": KEEP, "pchq_sites": SCRUB,
}

# 整条删除的选项：支付、邮件、AI、像素、第三方接口的密钥和连接信息；以及一次性数据。
OPTION_DELETE_EXACT = [
    "peptide_core_hq", "peptide_core_pixels", "peptide_core_content_tools", "peptide_core_youtube_metadata", "peptide_core_local_payments",
    "peptide_core_site_tools", "pc_ai_log", "jetpack_options", "jetpack_secrets", "recovery_keys", "woocommerce_share_key",
    "woocommerce_maxmind_geolocation_settings", "mailserver_url", "mailserver_login", "mailserver_pass", "auth_key", "auth_salt",
    "logged_in_key", "logged_in_salt", "nonce_key", "nonce_salt", "secure_auth_key", "secure_auth_salt", "secret_key",
    # 总后台
    "pchq_ai", "pchq_ai_providers", "pchq_ai_models", "pchq_register_secret", "pchq_cdn", "pchq_cdn_bot", "pchq_costs", "pchq_costs_snapshot",
    "pchq_mail", "pchq_ops_agents", "pchq_clone_jobs", "pc_panapay_hub_settings", "pc_panapay_hub_purged",
]
OPTION_DELETE_LIKE = [
    r"\_transient\_%", r"\_site\_transient\_%", r"\_pc\_pay\_nonce\_%", r"pc\_panapay\_handoff\_%", r"pc\_panapay\_hub\_accounts%",
    r"pc\_panapay\_provider\_site\_bindings%", r"fluentmail-settings%", r"\_fluentsmtp%", r"wcpay\_%", r"woopay\_%",
    r"woocommerce\_%payment%\_settings", r"woocommerce\_paypal\_settings", r"woocommerce\_pc\_panapay\_settings",
    r"woocommerce\_woocommerce\_payments%", r"pc\_mail\_templates\_backup\_%", r"pchq\_backup\_%",
]
# 名字里带这些词的选项也删（兜底；宁可本地少一个设置，也不带出一个密钥）。
OPTION_DELETE_REGEX = "secret|token|api_?key|passw|license|private_?key|credential|access_?key"
OPTION_SET_EMAIL = ["admin_email", "new_admin_email", "woocommerce_email_from_address", "woocommerce_stock_email_recipient",
                    "woocommerce_pos_store_email", "woocommerce_email_reply_to_address"]

# 泄漏检查时允许出现的邮箱域名（公开的示例、软件自带文案）。店铺自己的域名用 --allow-domain 加。
ALLOWED_EMAIL_DOMAINS = ["example.test", "example.com", "example.org", "woocommerce.com", "wordpress.org", "wordpress.com",
                         "automattic.com", "gravatar.com", "w3.org", "schema.org", "sentry.io", "paypal.com"]
EMAIL_RE = r"[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}"
TEXT_TYPES = {"char", "varchar", "tinytext", "text", "mediumtext", "longtext", "json"}


class Refused(Exception):
    pass


def classify(kind, prefix, tables):
    """返回 {完整表名: 处理方式}；有没登记的表就拒绝。"""
    rules = STORE_TABLES if kind == "store" else HQ_TABLES
    out, unknown = {}, []
    for table in tables:
        if not table.startswith(prefix):
            unknown.append(table)
            continue
        rule = rules.get(table[len(prefix):])
        if rule is None:
            unknown.append(table)
        else:
            out[table] = rule
    if unknown:
        raise Refused("有没登记的表，拒绝导出。先在 snapshot.py 的表清单里写明 keep / truncate / scrub：" + ", ".join(sorted(unknown)))
    return out


def q(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


def fake_email(column, salt):
    """同一个原邮箱永远得到同一个假邮箱（“同一顾客只算一条”这类逻辑还能测），但加盐后不可逆。"""
    return f"IF({column} IS NULL OR {column} = '', {column}, CONCAT('c', LEFT(SHA2(CONCAT({q(salt)}, LOWER(TRIM({column}))), 256), 14), '@example.test'))"


def fake_phone(column, salt):
    return f"IF({column} IS NULL OR {column} = '', {column}, CONCAT('+1555', LPAD(MOD(CONV(LEFT(SHA2(CONCAT({q(salt)}, {column}), 256), 8), 16, 10), 10000000), 7, '0')))"


def fake_postcode(country):
    return f"CASE {country} WHEN 'PR' THEN '00901' WHEN 'US' THEN '10001' WHEN 'CA' THEN 'K1A 0B1' WHEN 'GB' THEN 'SW1A 1AA' ELSE '00000' END"


def scrub_statements(kind, prefix, present, salt):
    """present：实际存在的完整表名集合。只为存在的表生成语句。"""
    t = lambda name: f"`{prefix}{name}`"
    has = lambda name: prefix + name in present
    sql = []
    if has("users"):
        sql.append(f"UPDATE {t('users')} SET user_email = {fake_email('user_email', salt)}, user_login = CONCAT('user', ID), user_nicename = CONCAT('user', ID), display_name = CONCAT('User ', ID), user_pass = '!', user_url = '', user_activation_key = ''")
    if has("usermeta"):
        m = t("usermeta")
        sql += [
            f"DELETE FROM {m} WHERE meta_key IN ('session_tokens', '_application_passwords', 'community-events-location', '_woocommerce_tracks_anon_id', 'jetpack_tracks_anon_id') OR meta_key LIKE '\\_woocommerce\\_persistent\\_cart%' OR (meta_key LIKE '%email%' AND meta_key <> 'billing_email')",
            f"UPDATE {m} SET meta_value = 'Test' WHERE meta_key IN ('first_name', 'billing_first_name', 'shipping_first_name')",
            f"UPDATE {m} SET meta_value = CONCAT('Customer', user_id) WHERE meta_key IN ('last_name', 'billing_last_name', 'shipping_last_name')",
            f"UPDATE {m} SET meta_value = CONCAT('user', user_id) WHERE meta_key = 'nickname'",
            f"UPDATE {m} SET meta_value = '' WHERE meta_key IN ('description', 'billing_company', 'shipping_company', 'billing_address_2', 'shipping_address_2')",
            f"UPDATE {m} SET meta_value = CONCAT(user_id, ' Test Street') WHERE meta_key IN ('billing_address_1', 'shipping_address_1')",
            f"UPDATE {m} SET meta_value = 'Testville' WHERE meta_key IN ('billing_city', 'shipping_city')",
            f"UPDATE {m} p JOIN {m} c ON c.user_id = p.user_id AND c.meta_key = REPLACE(p.meta_key, 'postcode', 'country') SET p.meta_value = {fake_postcode('c.meta_value')} WHERE p.meta_key IN ('billing_postcode', 'shipping_postcode')",
            f"UPDATE {m} SET meta_value = {fake_email('meta_value', salt)} WHERE meta_key = 'billing_email'",
            f"UPDATE {m} SET meta_value = {fake_phone('meta_value', salt)} WHERE meta_key IN ('billing_phone', 'shipping_phone')",
        ]
    if has("comments"):
        c = t("comments")
        sql += [
            f"UPDATE {c} SET comment_author = CONCAT('Customer ', comment_ID), comment_author_email = {fake_email('comment_author_email', salt)}, comment_author_url = '', comment_author_IP = '127.0.0.1', comment_agent = ''",
            # 订单备注里常有姓名、邮箱、物流号。
            f"UPDATE {c} SET comment_content = '[脱敏：订单备注]' WHERE comment_type = 'order_note'",
        ]
    if has("postmeta"):
        p = t("postmeta")
        sql += [
            f"UPDATE {p} SET meta_value = '' WHERE meta_key LIKE '\\_billing\\_%' OR meta_key LIKE '\\_shipping\\_%' OR meta_key IN ('_customer_ip_address', '_customer_user_agent', '_transaction_id')",
            # 优惠券的“限定邮箱”和“谁用过”。
            f"UPDATE {p} SET meta_value = '' WHERE meta_key IN ('customer_email', '_used_by')",
        ]
    if has("wc_orders"):
        sql.append(f"UPDATE {t('wc_orders')} SET billing_email = {fake_email('billing_email', salt)}, ip_address = '127.0.0.1', user_agent = '', customer_note = '', transaction_id = ''")
    if has("wc_order_addresses"):
        # 测试单规则：收件信息全填“1”。这些行原样保留，否则脱敏后测试单会变成普通单。
        not_test = "NOT (first_name = '1' AND last_name = '1' AND address_1 = '1' AND city = '1')"
        sql.append(
            f"UPDATE {t('wc_order_addresses')} SET first_name = 'Test', last_name = CONCAT('Customer', order_id), company = '', address_1 = CONCAT(order_id, ' Test Street'), address_2 = '', city = 'Testville', "
            f"postcode = {fake_postcode('country')}, email = {fake_email('email', salt)}, phone = {fake_phone('phone', salt)} WHERE {not_test}"
        )
        sql.append(f"UPDATE {t('wc_order_addresses')} SET email = {fake_email('email', salt)}, phone = {fake_phone('phone', salt)} WHERE NOT ({not_test})")
    if has("wc_orders_meta"):
        o = t("wc_orders_meta")
        sql += [
            f"UPDATE {o} SET meta_value = '' WHERE meta_key IN ('_billing_address_index', '_shipping_address_index', '_wc_order_attribution_user_agent', '_customer_ip_address', '_customer_user_agent')",
            f"DELETE FROM {o} WHERE meta_key IN ('_pc_panapay_sessions') OR meta_key LIKE '\\_pc\\_panapay\\_%' OR meta_key LIKE '%token%' OR meta_key LIKE '%secret%'",
        ]
    if has("wc_customer_lookup"):
        sql.append(
            f"UPDATE {t('wc_customer_lookup')} SET username = CONCAT('customer', customer_id), first_name = 'Test', last_name = CONCAT('Customer', customer_id), email = {fake_email('email', salt)}, "
            f"city = 'Testville', postcode = {fake_postcode('country')}, billing_phone = {fake_phone('billing_phone', salt)}, shipping_phone = {fake_phone('shipping_phone', salt)}"
        )
    if has("wc_stock_notifications"):
        sql.append(f"UPDATE {t('wc_stock_notifications')} SET user_email = {fake_email('user_email', salt)}")
    if has("woocommerce_downloadable_product_permissions"):
        sql.append(f"UPDATE {t('woocommerce_downloadable_product_permissions')} SET user_email = {fake_email('user_email', salt)}")
    if has("pc_recovery"):
        sql.append(
            f"UPDATE {t('pc_recovery')} SET email = {fake_email('email', salt)}, name = IF(name = '' OR name = '1 1', name, CONCAT('Test Customer', id)), note = '', "
            f"token = LEFT(SHA2(CONCAT({q(salt)}, 'recovery', id), 256), 32)"
        )
    if has("pc_subscriptions"):
        sql.append(
            f"UPDATE {t('pc_subscriptions')} SET email = {fake_email('email', salt)}, other_contact = '', token_hash = SHA2(CONCAT({q(salt)}, 'sub', id), 256), unsubscribe_hash = SHA2(CONCAT({q(salt)}, 'unsub', id), 256)"
        )
    if has("pc_points_ledger"):
        sql.append(f"UPDATE {t('pc_points_ledger')} SET note = ''")
    if has("pchq_sites"):
        # 店铺连接密钥：本地环境重新注册，不带生产密钥。
        sql.append(f"UPDATE {t('pchq_sites')} SET key_enc = '', key_pending = NULL")
    if has("options"):
        o = t("options")
        exact = ", ".join(q(name) for name in OPTION_DELETE_EXACT)
        like = " OR ".join(f"option_name LIKE '{pattern}'" for pattern in OPTION_DELETE_LIKE)  # 模式里的 \\_ 原样交给 MySQL
        sql += [
            f"DELETE FROM {o} WHERE option_name IN ({exact}) OR {like}",
            f"DELETE FROM {o} WHERE option_name REGEXP {q(OPTION_DELETE_REGEX)} AND option_name NOT LIKE '%\\_endpoint'",
            f"UPDATE {o} SET option_value = 'admin@example.test' WHERE option_name IN ({', '.join(q(n) for n in OPTION_SET_EMAIL)})",
            # 标记：本地环境据此确认导入的是脱敏快照。
            f"INSERT INTO {o} (option_name, option_value, autoload) VALUES ('pc_sanitized_snapshot', {q(datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'))}, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        ]
    return sql


def plan(kind, prefix, tables, salt):
    rules = classify(kind, prefix, tables)
    present = set(rules)
    sql = ["SET SESSION sql_safe_updates = 0", "SET FOREIGN_KEY_CHECKS = 0"]
    sql += [f"TRUNCATE TABLE `{table}`" for table, rule in sorted(rules.items()) if rule == TRUNCATE]
    sql += scrub_statements(kind, prefix, present, salt)
    return sql


def allowed_regex(allow_domains=(), allow_emails=()):
    """允许留下的“像邮箱的东西”：示例域名、店铺自己的域名、点名放行的地址、logo@2x.png 这类图片名。"""
    domains = "|".join(re.escape(d) for d in sorted(set(ALLOWED_EMAIL_DOMAINS) | {d.lower() for d in allow_domains}))
    exact = "".join("|" + re.escape(e) for e in sorted({e.lower() for e in allow_emails}))
    return rf"[A-Za-z0-9._%+-]+@(([A-Za-z0-9-]+\.)*({domains})|[0-9.]+x\.[A-Za-z]+){exact}"


def sql_regex(pattern):
    """正则放进 MySQL 字符串字面量：反斜杠和单引号要转义。"""
    return "'" + pattern.replace("\\", "\\\\").replace("'", "''") + "'"


def leak_queries(columns, allow_domains=(), allow_emails=()):
    """对每个文本列数“去掉允许的地址之后，还像邮箱的行”。columns: [(table, column, data_type)]"""
    allowed = sql_regex(allowed_regex(allow_domains, allow_emails))
    names = {}
    for table, column, _ in columns:
        names.setdefault(table, set()).add(column)
    out = []
    for table, column, data_type in columns:
        if data_type.lower() not in TEXT_TYPES:
            continue
        where = f"`{column}` LIKE '%@%' AND REGEXP_REPLACE(LOWER(`{column}`), {allowed}, '') REGEXP {sql_regex(EMAIL_RE)}"
        # meta / options 表按键名分组报告（只报键名，不报内容），不然不知道该补哪条规则。
        key = next((k for k in ("meta_key", "option_name") if k in names[table] and k != column), None)
        if key:
            out.append(f"SELECT CONCAT({q(table + '.' + column + ' [')}, `{key}`, ']'), COUNT(*) FROM `{table}` WHERE {where} GROUP BY `{key}`")
        else:
            out.append(f"SELECT {q(table + '.' + column)}, COUNT(*) FROM `{table}` WHERE {where} HAVING COUNT(*) > 0")
    return out


def leaks_in(text, allow_domains=(), allow_emails=()):
    """与 leak_queries 同一套规则的 Python 版，给自检用。"""
    return bool(re.search(EMAIL_RE, re.sub(allowed_regex(allow_domains, allow_emails), "", text.lower())))


# ---------------------------------------------------------------------------------------------
# 以下只在数据库服务器上执行。
# ---------------------------------------------------------------------------------------------
def mysql(sql, database=None, capture=True):
    command = ["mysql", "--batch", "--skip-column-names", "--default-character-set=utf8mb4"] + ([database] if database else [])
    done = subprocess.run(command, input=sql.encode(), stdout=subprocess.PIPE if capture else None, stderr=subprocess.PIPE)
    if done.returncode != 0:
        raise Refused("MySQL 报错：" + done.stderr.decode(errors="replace")[-600:])
    return done.stdout.decode(errors="replace") if capture else ""


def export(args):
    if not re.fullmatch(r"[A-Za-z0-9_]{1,48}", args.db):
        raise Refused("数据库名不合法。")
    if os.geteuid() != 0:
        raise Refused("需要 root（用 MySQL 的本机 socket 登录）。")
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    scratch = f"vcsnap_{args.db}_{stamp}"[:64]
    salt = secrets.token_hex(32)  # 只在内存里，用完即弃：假邮箱无法反推出真邮箱。
    os.makedirs(args.out, mode=0o700, exist_ok=True)
    target = os.path.join(args.out, f"{args.db}-sanitized-{stamp}.sql.gz")
    mysql(f"CREATE DATABASE `{scratch}` CHARACTER SET utf8mb4")
    try:
        # 1. 生产库 → 临时库（一致性快照，不锁表；生产库只被读）。
        dump = subprocess.Popen(["mysqldump", "--single-transaction", "--quick", "--no-tablespaces", "--skip-lock-tables", "--default-character-set=utf8mb4", args.db], stdout=subprocess.PIPE)
        load = subprocess.run(["mysql", "--default-character-set=utf8mb4", scratch], stdin=dump.stdout, stderr=subprocess.PIPE)
        if dump.wait() != 0 or load.returncode != 0:
            raise Refused("复制到临时库失败：" + load.stderr.decode(errors="replace")[-400:])
        # 2. 脱敏。
        tables = mysql(f"SELECT table_name FROM information_schema.tables WHERE table_schema = {q(scratch)}").split()
        prefix = args.prefix or next((name[: -len("options")] for name in tables if name.endswith("options")), "wp_")
        statements = plan(args.kind, prefix, tables, salt)
        mysql(";\n".join(statements) + ";\n", scratch, capture=False)
        # 3. 泄漏检查：任何文本列里还有非示例域名的邮箱，就不出文件。
        rows = [line.split("\t") for line in mysql(f"SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = {q(scratch)}").splitlines()]
        leaks = [line for query in leak_queries(rows, args.allow_domain, args.allow_email) for line in mysql(query, scratch).splitlines() if line.strip()]
        if leaks and not args.report_only:
            raise Refused("泄漏检查没过，未导出。以下列里还有真实邮箱（列名\t行数）：\n" + "\n".join(leaks))
        if args.report_only:
            print("（--report-only）泄漏检查结果：" + ("\n" + "\n".join(leaks) if leaks else "干净"))
            return 0
        # 4. 导出脱敏后的临时库。
        out = subprocess.Popen(["mysqldump", "--single-transaction", "--quick", "--no-tablespaces", "--default-character-set=utf8mb4", scratch], stdout=subprocess.PIPE)
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as raw, gzip.GzipFile(fileobj=raw, mode="wb", mtime=0) as packed:
            for chunk in iter(lambda: out.stdout.read(1 << 20), b""):
                packed.write(chunk)
        if out.wait() != 0:
            os.unlink(target)
            raise Refused("导出临时库失败。")
        print(f"完成：{target}（{os.path.getsize(target) // 1024} KB，表 {len(tables)} 张，脱敏语句 {len(statements)} 条，泄漏检查通过）")
        return 0
    finally:
        mysql(f"DROP DATABASE IF EXISTS `{scratch}`")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("plan")
    p.add_argument("--kind", choices=["store", "hq"], required=True); p.add_argument("--tables", required=True); p.add_argument("--prefix", default="wp_")
    e = sub.add_parser("export")
    e.add_argument("--db", required=True); e.add_argument("--kind", choices=["store", "hq"], required=True); e.add_argument("--out", required=True)
    e.add_argument("--prefix", default=""); e.add_argument("--allow-domain", action="append", default=[]); e.add_argument("--allow-email", action="append", default=[]); e.add_argument("--report-only", action="store_true")
    args = parser.parse_args()
    try:
        if args.cmd == "plan":
            tables = open(args.tables, encoding="utf-8").read().split()
            print(";\n".join(plan(args.kind, args.prefix, tables, "SALT")) + ";")
            return 0
        return export(args)
    except Refused as error:
        print("拒绝：" + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
