#!/usr/bin/python3 -I
"""Root-owned, bounded tenant deployment broker. No user-selected commands or paths."""
import base64
import fcntl
import grp
import hashlib
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import socket
import stat
import subprocess
import sys
import time
import uuid


def lifecycle_module():
    """Only load the root-owned broker shipped beside this exact worker."""
    path = Path(__file__).resolve().with_name('lifecycle.py')
    entry = path.lstat()
    require(stat.S_ISREG(entry.st_mode) and entry.st_uid == 0 and not entry.st_mode & 0o022
            and path.parent.stat().st_uid == 0 and not path.parent.stat().st_mode & 0o022,
            'lifecycle_module_owner')
    spec = importlib.util.spec_from_file_location('vialcove_tenant_lifecycle', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def plugins_module():
    """Third-party plugin broker (HQ 插件管理), root-owned and shipped beside this worker."""
    path = Path(__file__).resolve().with_name('plugins.py')
    entry = path.lstat()
    require(stat.S_ISREG(entry.st_mode) and entry.st_uid == 0 and not entry.st_mode & 0o022
            and path.parent.stat().st_uid == 0 and not path.parent.stat().st_mode & 0o022,
            'plugins_module_owner')
    spec = importlib.util.spec_from_file_location('vialcove_store_plugins', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def lifecycle_active(c, identity):
    """Missing history is active; malformed/retired root history is closed."""
    require(isinstance(identity, str) and str(uuid.UUID(identity)) == identity, 'lifecycle_identity')
    root = Path(c['state_root'] if c['role'] == 'admin' else c['web_ledger_root'])
    path = root / 'lifecycle/tenants' / identity / 'current.json'
    require(not path.is_symlink(), 'lifecycle_history')
    if not path.exists():
        return True
    engine = lifecycle_module()
    state = engine.load_json(path, private=True)
    require(state.get('uuid') == identity, 'lifecycle_identity')
    return state.get('state') == 'active'


def require(value, code):
    if not value:
        raise ValueError(code)


def run(args, *, data=None, user=None, timeout=180, extra_env=None):
    env = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent'}
    if user:
        cache = app_dir(Path('/var/cache/vialcove-cli') / user, user)
        env['WP_CLI_CACHE_DIR'] = str(cache)
        args = ['/usr/sbin/runuser', '-u', user, '--', *args]
    env.update(extra_env or {})
    p = subprocess.run(args, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       timeout=timeout, env=env)
    if p.returncode:
        debug = Path('/var/lib/vialcove-provision-debug')
        debug.mkdir(mode=0o700, exist_ok=True)
        write(debug / 'last-command-error.txt', p.stderr[-16000:].decode(errors='replace'))
    require(p.returncode == 0, 'command_failed_' + Path(args[0]).name)
    return p.stdout


def write(path, text, mode=0o600, group=None):
    path = Path(path)
    require(not path.is_symlink(), 'symlink_rejected')
    tmp = path.with_name(path.name + '.next-' + secrets.token_hex(4))
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    # The worker runs with UMask=0077, which would silently drop group/other bits
    # (HQ could not read 0640 job results). Apply the requested mode explicitly.
    os.fchmod(fd, mode)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(text)
        f.flush()
        os.fsync(f.fileno())
    if group:
        os.chown(tmp, 0, grp.getgrnam(group).gr_gid)
    os.replace(tmp, path)


def private_json(path, obj, group=None):
    write(path, json.dumps(obj, ensure_ascii=False), 0o640 if group else 0o600, group)


def customer_domain(value):
    """Canonical customer base; no URL, wildcard, Unicode or guessed public suffix."""
    require(isinstance(value, str), 'customer_domain')
    require(not re.search(r'[\x00-\x1f\x7f]', value), 'customer_domain')
    base = value.strip(' ').lower()
    if base.startswith('www.'):
        base = base[4:]
    require(not base.startswith('www.') and 0 < len(base) <= 249, 'customer_domain')
    labels = base.split('.')
    require(len(labels) >= 2 and all(re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', n) for n in labels)
            and re.fullmatch(r'(?:[a-z]{2,63}|xn--[a-z0-9](?:[a-z0-9-]{0,57}[a-z0-9])?)', labels[-1]), 'customer_domain')
    try:
        ipaddress.ip_address(base)
    except ValueError:
        return base
    raise ValueError('customer_domain')


def validate_request(r, *, allow_legacy=False):
    require(isinstance(r, dict), 'request_shape')
    version = r.get('version')
    require(type(version) is int and (version == 2 or allow_legacy and version == 1), 'request_version')
    fields = {'version', 'operation_id', 'actor_id', 'title', 'login', 'email', 'password'}
    require(set(r) == (fields | {'customer_domain'} if version == 2 else fields), 'request_shape')
    require(type(r['actor_id']) is int and r['actor_id'] > 0, 'actor')
    require(isinstance(r['operation_id'], str) and str(uuid.UUID(r['operation_id'], version=4)) == r['operation_id'] and uuid.UUID(r['operation_id']).version == 4, 'operation_id')
    require(isinstance(r['title'], str) and 0 < len(r['title']) <= 100 and not re.search(r'[\x00-\x1f\x7f<>]', r['title']), 'title')
    require(isinstance(r['login'], str) and re.fullmatch(r'[a-z][a-z0-9_]{2,31}', r['login']), 'login')
    require(isinstance(r['email'], str) and len(r['email']) <= 190 and re.fullmatch(r'[^\s<>"\x00-\x1f@]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,63}', r['email']), 'email')
    require(isinstance(r['password'], str) and 12 <= len(r['password'].encode()) <= 128 and not re.search(r'[\x00-\x1f\x7f]', r['password']), 'password')
    return dict(r, customer_domain=customer_domain(r['customer_domain'])) if version == 2 else dict(r)


def tenant(slug, c, domain=None):
    require(isinstance(slug, str) and re.fullmatch(r's[0-9]{4}', slug) and 2 <= int(slug[1:]) <= 9999, 'tenant_slug')
    result = {'slug': slug, 'user': 'vc' + slug, 'db': 'vc_' + slug,
              'management_url': 'https://' + slug + '.' + c['management_domain']}
    # No-domain lookup is retained only for immutable old job metadata/operations.
    if domain is None:
        result['public_url'] = 'https://' + slug + '.' + c['public_domain']
    else:
        base = customer_domain(domain)
        for reserved in [c['management_domain'], c['public_domain']]:
            reserved = reserved.lower().rstrip('.')
            require(base != reserved and not base.endswith('.' + reserved), 'platform_domain_reserved')
        result.update(customer_domain=base, public_url='https://www.' + base)
    return result


def dns_records(t, c):
    address = str(ipaddress.IPv4Address(c['public_ip']))
    return [{'type': 'A', 'name': name, 'value': address, 'ttl': 600} for name in ['@', 'www']]


def claim_domain(root, t, operation_id):
    """Root-only durable claim. Never transfer/release claims automatically on retry."""
    return persist_domain_claim(root, dict(version=1, kind='provisioned', customer_domain=t['customer_domain'],
                                          operation_id=operation_id, uuid=t['uuid'], slug=t['slug']))


def reserve_legacy_domain(root, request):
    """Explicit operator import; no job, account, SQL, HTTP or tenant installation."""
    require(isinstance(request, dict) and set(request) == {'customer_domain', 'uuid', 'slug'}, 'legacy_claim_shape')
    require(isinstance(request['uuid'], str) and str(uuid.UUID(request['uuid'])) == request['uuid'], 'legacy_claim_uuid')
    require(isinstance(request['slug'], str) and re.fullmatch(r's[0-9]{4}', request['slug']) and int(request['slug'][1:]) >= 1, 'legacy_claim_slug')
    return persist_domain_claim(root, dict(version=1, kind='legacy', customer_domain=customer_domain(request['customer_domain']),
                                          uuid=request['uuid'], slug=request['slug']))


def persist_domain_claim(root, claim):
    base = claim['customer_domain']
    directory = Path(root) / 'domain-claims'
    require(not directory.is_symlink(), 'domain_claim_directory')
    directory.mkdir(mode=0o700, exist_ok=True)
    st = directory.stat()
    require(st.st_uid == 0 and not st.st_mode & 0o077, 'domain_claim_directory')
    sync_directory(Path(root))
    path = directory / (hashlib.sha256(base.encode('ascii')).hexdigest() + '.json')
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    except FileExistsError:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as f:
            st = os.fstat(f.fileno())
            require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1
                    and not st.st_mode & 0o077 and st.st_size <= 1024, 'domain_claim_file')
            previous = json.loads(f.read(1025))
        require(previous == claim, 'domain_claim_conflict')
    else:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(claim, f, sort_keys=True)
            f.flush()
            os.fsync(f.fileno())
        sync_directory(directory)
    return claim


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


# Ask public resolvers directly (2026-11-08). The cloud VPC resolver caches "no such
# domain" for the zone's SOA minimum (30 minutes at Cloudflare) when a store is checked
# before its records exist, which stalled new stores; public resolvers see the records
# as soon as they are published. Every resolver must agree before a store continues.
PUBLIC_RESOLVERS = ('1.1.1.1', '8.8.8.8')


def public_answers(host, rtype):
    """Address answers from each public resolver, or None if a lookup could not run."""
    out = []
    for resolver in PUBLIC_RESOLVERS:
        try:
            p = subprocess.run(['/usr/bin/dig', '+short', '+time=3', '+tries=2', host, rtype, '@' + resolver],
                               stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=15,
                               env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin'})
        except (OSError, subprocess.TimeoutExpired):
            return None
        if p.returncode:
            return None
        family = socket.AF_INET if rtype == 'A' else socket.AF_INET6
        found = set()
        for line in p.stdout.decode(errors='replace').split():
            try:
                socket.inet_pton(family, line)
                found.add(line)
            except OSError:
                continue  # CNAME targets and other non-address lines
        out.append(found)
    return out


def dns_ready(host, expected):
    answers = public_answers(host, 'A')
    if answers is not None:
        return all(found == {expected} for found in answers)
    try:
        return {x[4][0] for x in socket.getaddrinfo(host, 443, socket.AF_INET)} == {expected}
    except socket.gaierror:
        return False


def customer_dns_ready(t, c):
    # The supplied instructions are A-only: an unexpected AAAA could send buyers elsewhere.
    for host in [t['customer_domain'], 'www.' + t['customer_domain']]:
        if not dns_ready(host, c['public_ip']):
            return False
        v6 = public_answers(host, 'AAAA')
        if v6 is not None:
            if any(v6):
                return False
            continue
        try:
            if socket.getaddrinfo(host, 443, socket.AF_INET6):
                return False
        except socket.gaierror:
            pass
    return True


def make_user(name):
    try:
        return pwd.getpwnam(name)
    except KeyError:
        run(['useradd', '--system', '--no-create-home', '--home-dir', '/nonexistent', '--shell', '/usr/sbin/nologin', name])
        return pwd.getpwnam(name)


def app_dir(p, user, mode=0o700):
    p = Path(p)
    require(not p.is_symlink(), 'directory_symlink')
    p.mkdir(parents=True, exist_ok=True, mode=mode)
    u = pwd.getpwnam(user)
    os.chown(p, u.pw_uid, u.pw_gid)
    p.chmod(mode)
    return p


def copy_runtime(source, target):
    source, target = Path(source).resolve(), Path(target)
    require(not target.exists(), 'runtime_exists')
    def skip(parent, names):
        return [n for n in names if n in {'wp-config.php', 'uploads', 'tests', '.git', 'node_modules', '__pycache__', 'debug.log'} or Path(parent, n).is_symlink()]
    staging = target.with_name('.public-next-' + secrets.token_hex(8))
    shutil.copytree(source, staging, ignore=skip, symlinks=False)
    os.replace(staging, target)
    # Only audited deployment guards/loaders are inherited; never copy a source tenant gate/config.
    mu = target / 'wp-content/mu-plugins'
    if mu.exists():
        for p in mu.iterdir():
            if p.name not in {'vialcove-operations.php', 'vialcove-managed-login.php'}:
                require(p.is_file() and not p.is_symlink(), 'unexpected_mu_directory')
                p.unlink()
    mu.mkdir(exist_ok=True)
    mu.chmod(0o755)
    # 2026-11-07: new stores send mail like the first store (FluentSMTP is activated in bootstrap);
    # the former vialcove-mail-gate.php is no longer written, and a copy in the source is removed above.


def configuration(t, c, dbuser, password, salts, registration_secret):
    values = {'DB_NAME': t['db'], 'DB_USER': dbuser, 'DB_PASSWORD': password,
              'DB_HOST': 'localhost', 'DB_CHARSET': 'utf8mb4', 'DB_COLLATE': '',
              'WP_SITEURL': t['management_url'], 'WP_DEBUG': False, 'WP_DEBUG_DISPLAY': False,
              'WP_ENVIRONMENT_TYPE': 'production', 'DISALLOW_FILE_EDIT': True, 'DISALLOW_FILE_MODS': True,
              'DISABLE_WP_CRON': True, 'AUTOMATIC_UPDATER_DISABLED': True, 'FORCE_SSL_ADMIN': True,
              'COOKIE_DOMAIN': False, 'COOKIEPATH': '/', 'SITECOOKIEPATH': '/', 'ADMIN_COOKIE_PATH': '/wp-admin',
              'PEPTIDE_CORE_MANAGEMENT_URL': t['management_url'], 'PEPTIDE_CORE_MANAGEMENT_UUID': t['uuid'],
              'PEPTIDE_HQ_URL': c['hq_url'], 'PEPTIDE_HQ_SECRET': registration_secret,
              'PEPTIDE_CORE_PRIVATE_DIR': str(t['data'] / 'reports'),
              'PEPTIDE_OPS_PRIVATE_DIR': str(t['data'] / 'ops')}
    values.update(salts)
    # JSON-to-PHP conversion only emits literals, never executable user text.
    def literal(v):
        if isinstance(v, bool):
            return 'true' if v else 'false'
        return "'" + str(v).replace('\\', '\\\\').replace("'", "\\'") + "'"
    return '<?php\n' + '\n'.join('define(' + literal(k) + ',' + literal(v) + ');' for k, v in values.items()) + "\n$table_prefix='wp_';\n"


def fpm(t):
    u, data = t['user'], t['data']
    write('/etc/php/8.3/fpm/pool.d/vialcove-' + t['slug'] + '.conf', f'''[{u}]
user = {u}
group = {u}
listen = /run/php/vialcove-{t['slug']}.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 15s
pm.max_requests = 300
clear_env = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[expose_php] = off
php_admin_value[error_log] = {data}/ops/php-error.log
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 68M
php_admin_value[upload_tmp_dir] = {data}/tmp
php_admin_value[session.save_path] = {data}/sessions
''', 0o644)
    run(['php-fpm8.3', '-t'])
    run(['systemctl', 'reload', 'php8.3-fpm'])


def vhost(t, host, *, tls=False, aliases=()):
    hosts = [host, *aliases]
    require(len(hosts) == len(set(hosts)) and all(customer_domain(h) in {h, h.removeprefix('www.')} for h in hosts), 'vhost_hosts')
    write('/etc/nginx/conf.d/vialcove-tenant-login-limit.conf',
          'limit_req_zone $binary_remote_addr zone=vialcove_tenant_login:10m rate=6r/m;\n', 0o644)
    path = Path('/etc/nginx/sites-available/vialcove-' + t['slug'])
    for other in Path('/etc/nginx/sites-enabled').iterdir():
        if other.resolve() == path.resolve() or not other.is_file():
            continue
        source = re.sub(r'(?m)#.*$', '', other.read_text(encoding='utf-8'))
        names = re.findall(r'\bserver_name\s+([^;{}]+);', source)
        existing = {n.strip('\"\'').lower() for group in names for n in group.split()}
        require(not set(hosts).intersection(existing), 'vhost_host_in_use')
    acme = '/var/www/vialcove-tenant-acme'
    Path(acme, '.well-known/acme-challenge').mkdir(parents=True, exist_ok=True)
    for d in (Path(acme), Path(acme, '.well-known'), Path(acme, '.well-known/acme-challenge')):
        d.chmod(0o755)  # nginx must read challenges despite UMask=0077
    text = f'''server {{
 listen 80; listen [::]:80; server_name {' '.join(hosts)};
 location ^~ /.well-known/acme-challenge/ {{ root {acme}; default_type text/plain; try_files $uri =404; }}
 location / {{ return {'308 https://$host$request_uri' if tls else '503'}; }}
}}
'''
    if tls:
        text += fr'''server {{
 listen 443 ssl; listen [::]:443 ssl; server_name {' '.join(hosts)};
 if ($host !~ ^(?:{'|'.join(re.escape(h) for h in hosts)})$) {{ return 444; }}
 if ($ssl_server_name !~ ^(?:{'|'.join(re.escape(h) for h in hosts)})$) {{ return 444; }}
 if ($host != $ssl_server_name) {{ return 444; }}
 ssl_certificate /etc/letsencrypt/live/{host}/fullchain.pem;
 ssl_certificate_key /etc/letsencrypt/live/{host}/privkey.pem;
 ssl_protocols TLSv1.2 TLSv1.3;
 root {t['public']}; index index.php; server_tokens off;
 client_max_body_size 68m; access_log off;
 add_header X-Content-Type-Options nosniff always;
 add_header Referrer-Policy strict-origin-when-cross-origin always;
 add_header Cache-Control "no-store" always;
 gzip on; gzip_vary on; gzip_min_length 1024;
 gzip_types text/css application/javascript application/json image/svg+xml;
 location = /wp-config.php {{ return 404; }}
 location = /wp-config-sample.php {{ return 404; }}
 location = /xmlrpc.php {{ return 404; }}
 location = /wp-cron.php {{ return 404; }}
 location = /readme.html {{ return 404; }}
 location ~ (^|/)\. {{ return 404; }}
 location ~* \.(?:sql|bak|log|ini|cnf|pem|key|sh|py)(?:$|/) {{ return 404; }}
 location ~* ^/wp-content/.*\.(?:php[0-9]*|phtml|phar)(?:$|/) {{ return 404; }}
 location / {{ try_files $uri $uri/ /index.php?$args; }}
 location = /wp-login.php {{
  limit_req zone=vialcove_tenant_login burst=10 nodelay;
  limit_req_status 429;
  include fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
  fastcgi_param HTTPS on; fastcgi_param HTTP_PROXY "";
  fastcgi_pass unix:/run/php/vialcove-{t['slug']}.sock;
 }}
 location ~ \.php$ {{
  try_files $uri =404;
  include fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
  fastcgi_param DOCUMENT_ROOT $realpath_root;
  fastcgi_param HTTPS on; fastcgi_param HTTP_PROXY "";
  fastcgi_pass unix:/run/php/vialcove-{t['slug']}.sock;
  fastcgi_read_timeout 120s;
 }}
}}
'''
    previous = path.read_text(encoding='utf-8') if path.exists() else None
    write(path, text, 0o644)
    enabled = Path('/etc/nginx/sites-enabled', path.name)
    if not enabled.exists():
        enabled.symlink_to(path)
    try:
        run(['nginx', '-t'])
        run(['systemctl', 'reload', 'nginx'])
    except Exception:
        if previous is not None:
            write(path, previous, 0o644)
        raise


def certificate(t, host, *, aliases=()):
    require(all(dns_ready(name, t['server_ip']) for name in [host, *aliases]), 'waiting_dns')
    vhost(t, host, aliases=aliases)
    if not Path('/etc/letsencrypt/live', host, 'fullchain.pem').exists():
        run(['certbot', 'certonly', '--webroot', '-w', '/var/www/vialcove-tenant-acme',
             *[arg for name in [host, *aliases] for arg in ['-d', name]],
             '--non-interactive', '--agree-tos', '--register-unsafely-without-email'], timeout=180)
    for name in [host, *aliases]:
        run(['openssl', 'x509', '-noout', '-checkhost', name, '-in', '/etc/letsencrypt/live/' + host + '/fullchain.pem'])


def wrapper(t, config, shared=False):
    guard = ''
    if shared:
        for name in ['uploads', 'reports']:
            guard += "if (!str_contains((string)file_get_contents('/proc/self/mountinfo'), '" + str(t['data'] / name) + " ')) {http_response_code(503);exit('Storage temporarily unavailable.');}\n"
    write(t['public'] / 'wp-config.php', '<?php\n' + guard + "require '" + str(config) + "';\nrequire_once ABSPATH . 'wp-settings.php';\n", 0o644)


def bootstrap(t, r):
    data = dict(r, public=str(t['public']), home=t['public_url'], siteurl=t['management_url'])
    payload = t['data'] / 'install.json'
    private_json(payload, data, t['user'])
    script = t['data'] / 'install.php'
    code = '''<?php
$x=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
define('WP_INSTALLING',true);define('ABSPATH',rtrim($x['public'],'/').'/');
require ABSPATH.'wp-load.php';require ABSPATH.'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail','__return_false',PHP_INT_MIN);
if(!is_blog_installed()){wp_install($x['title'],$x['login'],$x['email'],false,'',$x['password'],'en_US');}
update_option('home',$x['home']);update_option('siteurl',$x['siteurl']);update_option('blog_public','1');
$identity=get_option('peptide_core_hq',[]);
if(empty($identity['uuid'])){update_option('peptide_core_hq',['uuid'=>PEPTIDE_CORE_MANAGEMENT_UUID,'key'=>bin2hex(random_bytes(32)),'created'=>time()],false);}
elseif($identity['uuid']!==PEPTIDE_CORE_MANAGEMENT_UUID){throw new RuntimeException('store_identity_mismatch');}
'''
    write(script, code, 0o640, t['user'])
    try:
        run(['php', str(script), str(payload)], user=t['user'])
    finally:
        script.unlink(missing_ok=True)
        payload.unlink(missing_ok=True)
    wp = ['/usr/local/bin/wp', '--path=' + str(t['public']), '--url=' + t['management_url']]
    run([*wp, 'plugin', 'activate', 'woocommerce', 'peptide-core', 'peptide-panapay', 'fluent-smtp'], user=t['user'])
    run([*wp, 'theme', 'activate', 'peptide-site'], user=t['user'])
    setup = t['data'] / 'setup.php'
    # 2026-11-07: same starting settings as the first store (s0001): Chinese admin for the owner,
    # store time zone Asia/Shanghai, default country US:CA, visitor tracking on.
    write(setup, "<?php\nWC_Install::create_pages();\nupdate_option('woocommerce_allow_tracking','no');update_option('woocommerce_default_country','US:CA');\n"
          "update_option('timezone_string','Asia/Shanghai');update_option('gmt_offset','');\n"
          "foreach(get_users(['role'=>'administrator','fields'=>'ID'])as $id){update_user_meta($id,'locale','zh_CN');}\n"
          "if(class_exists('PeptideCore\\\\Tracking\\\\Tracker')){PeptideCore\\Tracking\\Tracker::set_enabled(true);}\n", 0o640, t['user'])
    try:
        run([*wp, 'eval-file', str(setup)], user=t['user'])
    finally:
        setup.unlink(missing_ok=True)


def export_media(t, media_key, admin_ip):
    user = 'vcm_' + t['slug']
    make_user(user)
    jail = Path('/srv/vialcove-media-export', t['slug'])
    jail.mkdir(parents=True, exist_ok=True, mode=0o755)
    jail.chmod(0o755)
    for name in ['uploads', 'reports']:
        src, dst = t['data'] / name, jail / name
        dst.mkdir(exist_ok=True, mode=0o755)
        dst.chmod(0o755)
        run(['setfacl', '-R', '-m', f'u:{user}:rwX,u:{t["user"]}:rwX', str(src)])
        for root, dirs, files in os.walk(src):
            run(['setfacl', '-m', f'd:u:{user}:rwx,d:u:{t["user"]}:rwx', root])
        if not dst.is_mount():
            run(['mount', '--bind', str(src), str(dst)])
    keys = Path('/etc/ssh/vialcove-bridge-keys')
    keys.mkdir(exist_ok=True, mode=0o755)
    keys.chmod(0o755)
    require(re.fullmatch(r'ssh-ed25519 [A-Za-z0-9+/=]+(?: [^\r\n]*)?', media_key), 'media_key')
    write(keys / user, 'from="' + admin_ip + '",restrict ' + media_key + '\n', 0o644)
    write('/etc/ssh/sshd_config.d/45-vialcove-' + t['slug'] + '.conf', f'''Match User {user}
 AuthorizedKeysFile /etc/ssh/vialcove-bridge-keys/%u
 PasswordAuthentication no
 AuthenticationMethods publickey
 ChrootDirectory {jail}
 ForceCommand internal-sftp
 AllowTcpForwarding no
 AllowAgentForwarding no
 PermitTTY no
Match all
''', 0o644)
    run(['sshd', '-t'])
    run(['systemctl', 'reload', 'ssh'])
    export = Path('/usr/local/libexec/vialcove-export-' + t['slug'])
    write(export, '#!/bin/sh\nset -eu\n/usr/local/libexec/vialcove-storage-guard --mysql\n' + ''.join('mountpoint -q ' + str(jail / n) + ' || mount --bind ' + str(t['data'] / n) + ' ' + str(jail / n) + '\n' for n in ['uploads', 'reports']), 0o700)
    write('/etc/systemd/system/vialcove-export-' + t['slug'] + '.service', '[Unit]\nRequiresMountsFor=/srv/vialcove-data\nAfter=local-fs.target\n[Service]\nType=oneshot\nRemainAfterExit=true\nExecStart=' + str(export) + '\n[Install]\nWantedBy=multi-user.target\n', 0o644)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'vialcove-export-' + t['slug'] + '.service'])
    return user


def store_purged(root, row):
    """True when the root lifecycle tombstone says this store was purged (it no longer uses capacity)."""
    ident = str(row.get('uuid', ''))
    if not re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', ident):
        return False
    path = Path(root) / 'lifecycle/tenants' / ident / 'current.json'
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_uid != os.geteuid():
            return False
        return json.loads(path.read_text(encoding='utf-8')).get('state') == 'purged'
    except (OSError, ValueError):
        return False


def seed_template(t, c):
    require(Path(c['source_web_root']).resolve() != t['public'].resolve(), 'master_is_target')
    if c.get('template_bundle'):
        # A root-owned published-layout snapshot keeps future creation independent of
        # the first live store. The bundle contains no users, products or order DB.
        bundle = Path(c['template_bundle'])
        fd = os.open(bundle, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as handle:
            entry = os.fstat(handle.fileno())
            require(stat.S_ISREG(entry.st_mode) and entry.st_uid == 0 and entry.st_nlink == 1
                    and not entry.st_mode & 0o022 and entry.st_size < 2 * 1024**2, 'template_bundle_owner')
            raw = handle.read(2 * 1024**2)
    else:
        raw = run(['/usr/local/bin/wp', '--path=' + c['source_web_root'], '--url=' + c['source_management_url'],
                   'eval-file', '/usr/local/lib/vialcove-provision/export-template.php'], user=c['source_web_user'])
    require(len(raw) < 2 * 1024**2, 'template_manifest_size')
    data = json.loads(raw)
    require(data.get('version') == 1 and set(data['documents']) == {'home', 'header', 'footer', 'global'} and len(data['images']) <= 64, 'template_manifest')
    source_uploads = Path(c['source_uploads']).resolve()
    dest = app_dir(t['data'] / 'uploads/master', t['user'], 0o755)
    total = 0
    for image in data['images']:
        p = Path(image['file'])
        require(p.is_file() and not p.is_symlink() and p.resolve().is_relative_to(source_uploads), 'template_source_path')
        video = p.suffix.lower() in {'.mp4', '.webm'}
        require((video or p.suffix.lower() in {'.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif'}) and isinstance(image['old_id'], int) and image['old_id'] > 0, 'template_image')
        total += p.stat().st_size
        require(p.stat().st_size <= (64 if video else 10) * 1024**2 and total <= 128 * 1024**2, 'template_media_size')
        target = dest / ('master-' + str(image['old_id']) + p.suffix.lower())
        require(not target.is_symlink(), 'template_target_symlink')
        shutil.copyfile(p, target)
        u = pwd.getpwnam(t['user'])
        os.chown(target, u.pw_uid, u.pw_gid)
        target.chmod(0o644)
        image.pop('file')
        image['target'] = target.name
    payload = t['data'] / 'template.json'
    private_json(payload, data, t['user'])
    try:
        run(['/usr/local/bin/wp', '--path=' + str(t['public']), '--url=' + t['management_url'],
             'eval-file', '/usr/local/lib/vialcove-provision/import-template.php'], user=t['user'],
            extra_env={'VIALCOVE_TEMPLATE_FILE': str(payload)})
    finally:
        payload.unlink(missing_ok=True)


def tenant_cron(t):
    # Only the storefront host owns shared-database scheduled jobs.
    name = 'vialcove-' + t['slug'] + '-cron'
    script = Path('/usr/local/sbin', name)
    write(script, '#!/bin/sh\nset -eu\n/usr/local/libexec/vialcove-storage-guard --mysql\nexec /usr/sbin/runuser -u ' + t['user'] + ' -- /usr/bin/flock -n ' + str(t['data'] / 'ops/cron.lock') + ' /usr/local/bin/wp --path=' + str(t['public']) + ' --url=' + t['public_url'] + ' --quiet cron event run --due-now\n', 0o700)
    write('/etc/systemd/system/' + name + '.service', '[Unit]\nAfter=mysql.service\n[Service]\nType=oneshot\nExecStart=' + str(script) + '\nTimeoutStartSec=90\nUMask=0077\n', 0o644)
    write('/etc/systemd/system/' + name + '.timer', '[Timer]\nOnBootSec=120\nOnUnitInactiveSec=60\n[Install]\nWantedBy=timers.target\n', 0o644)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', name + '.timer'])


def domain_setup_php(t):
    # Only validated DNS names are encoded; native APIs own options/connection checks.
    hosts = json.dumps(['www.' + t['customer_domain'], t['customer_domain']])
    return r"""<?php
$hosts=json_decode('HOSTS',true);$primary=$hosts[0];$apex=$hosts[1];
PeptideCore\Domains::ensure_primary();
$missing=array_values(array_diff($hosts,PeptideCore\Domains::hosts()));
if($missing){$r=PeptideCore\Domains::add($missing,'none');if(is_wp_error($r)){throw new RuntimeException('domain_binding');}}
foreach($hosts as $host){$r=PeptideCore\Domains::check($host);if(!is_array($r)||$r['conn']!=='connected'||$r['https']!=='active'){throw new RuntimeException('domain_verification');}}
$r=PeptideCore\Domains::set_primary($primary);if(is_wp_error($r)||PeptideCore\Domains::primary_host()!==$primary){throw new RuntimeException('public_primary');}
// Native redirect excludes POST/REST/AJAX and answers domain checks before redirects.
$r=PeptideCore\Domains::set_redirect($apex,$primary);if(is_wp_error($r)){throw new RuntimeException('apex_redirect');}
echo json_encode(['domains_ready'=>true]);
""".replace('HOSTS', hosts)


def bind_customer_domains(t):
    script = t['data'] / 'ops/bind-customer-domains.php'
    write(script, domain_setup_php(t), 0o640, t['user'])
    try:
        result = json.loads(run(['/usr/local/bin/wp', '--path=' + str(t['public']), '--url=' + t['management_url'],
                                 'eval-file', str(script)], user=t['user']))
        require(result.get('domains_ready') is True, 'domain_verification')
    finally:
        script.unlink(missing_ok=True)


def web_create(p, c):
    # Forced commands can arrive concurrently; serialize domain/tenant claims and creation.
    ledger = Path(c['web_ledger_root'])
    require(not ledger.is_symlink(), 'web_ledger')
    ledger.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd = os.open(ledger / 'create.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'a') as lock:
        st = os.fstat(lock.fileno())
        require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1 and not st.st_mode & 0o077, 'web_lock')
        fcntl.flock(lock, fcntl.LOCK_EX)
        return _web_create(p, c)


def _web_create(p, c):
    require(set(p) == {'request', 'slug', 'uuid', 'media_key', 'registration_secret'}, 'remote_shape')
    r = validate_request(p['request'])
    t = tenant(p['slug'], c, r['customer_domain'])
    require(str(uuid.UUID(p['uuid'])) == p['uuid'], 'tenant_uuid')
    require(lifecycle_active(c, p['uuid']), 'store_retired')
    require(re.fullmatch(r'[a-f0-9]{64}', p['registration_secret']), 'registration_secret')
    t.update(uuid=p['uuid'], server_ip=c['public_ip'], data=Path(c['web_data_root']) / t['slug'], public=Path(c['web_store_root']) / t['slug'] / 'public')
    ledger = Path(c['web_ledger_root'])
    ledger.mkdir(parents=True, exist_ok=True, mode=0o700)
    digest = hashlib.sha256(json.dumps(p, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
    statefile = ledger / (t['slug'] + '.json')
    state = json.loads(statefile.read_text(encoding='utf-8')) if statefile.exists() else {}
    require(not state or state.get('digest') == digest, 'tenant_ownership')
    claim_domain(ledger, t, r['operation_id'])
    require(customer_dns_ready(t, c), 'waiting_dns')
    if not state:
        require(len(list(ledger.glob('s*.json'))) < c['max_new_stores'], 'store_limit')
        require(not t['public'].parent.exists() and not t['data'].exists(), 'tenant_path_exists')
        require(shutil.disk_usage(c['web_data_root']).free > 5 * 1024**3, 'disk_capacity')
        make_user(t['user'])
        t['public'].parent.mkdir(parents=True, mode=0o755)
        t['public'].parent.chmod(0o755)  # UMask=0077 would leave 0700: the store account must traverse it.
        for n in ['', 'uploads', 'reports', 'ops', 'tmp', 'sessions']:
            app_dir(t['data'] / n, t['user'], 0o755 if n == 'uploads' else (0o711 if n == '' else 0o700))
        state = {'digest': digest, 'phase': 'reserved', 'customer_domain': t['customer_domain'], 'front_password': secrets.token_hex(32), 'admin_password': secrets.token_hex(32),
                 'salts': {n: secrets.token_hex(32) for n in ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT']}}
        private_json(statefile, state)
    def save(phase):
        state['phase'] = phase
        private_json(statefile, state)
    if state['phase'] == 'reserved':
        # Generated identifiers/passwords only. No web process receives a global SQL identity.
        q = f"CREATE DATABASE `{t['db']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        q += f"CREATE USER 'vcf_{t['slug']}'@'localhost' IDENTIFIED BY '{state['front_password']}';GRANT ALL ON `{t['db']}`.* TO 'vcf_{t['slug']}'@'localhost';"
        q += f"CREATE USER 'vca_{t['slug']}'@'127.0.0.1' IDENTIFIED BY '{state['admin_password']}';GRANT ALL ON `{t['db']}`.* TO 'vca_{t['slug']}'@'127.0.0.1';"
        run(['mysql', '--protocol=socket'], data=q.encode())
        save('database')
    if state['phase'] == 'database':
        copy_runtime(c['source_web_root'], t['public'])
        (t['public'] / 'wp-content/uploads').symlink_to(t['data'] / 'uploads')
        cfg = configuration(t, c, 'vcf_' + t['slug'], state['front_password'], state['salts'], p['registration_secret'])
        write(t['data'] / 'wp-config.php', cfg, 0o640, t['user'])
        wrapper(t, t['data'] / 'wp-config.php')
        save('files')
    if state['phase'] == 'files':
        bootstrap(t, r)
        save('installed')
    if state['phase'] == 'installed':
        seed_template(t, c)
        save('template')
    if state['phase'] == 'template':
        host = 'www.' + t['customer_domain']
        certificate(t, host, aliases=(t['customer_domain'],))
        fpm(t)
        vhost(t, host, aliases=(t['customer_domain'],), tls=True)
        save('served')
    if state['phase'] == 'served':
        bind_customer_domains(t)
        save('domains')
    require(state['phase'] == 'domains', 'tenant_phase')
    media_user = export_media(t, p['media_key'], c['admin_ip'])
    tenant_cron(t)
    cfg = configuration(t, c, 'vca_' + t['slug'], state['admin_password'], state['salts'], p['registration_secret'])
    return {'config': cfg, 'media_user': media_user, 'uuid': t['uuid'], 'ready': True}


def mount_media(t, c, key, remote_user):
    u = pwd.getpwnam(t['user'])
    for n in ['uploads', 'reports']:
        args = ['/usr/bin/sshfs', '-f', remote_user + '@' + c['web_host'] + ':/' + n, str(t['data'] / n), '-o',
                ','.join(['IdentityFile=' + str(key), 'IdentitiesOnly=yes', 'BatchMode=yes', 'StrictHostKeyChecking=yes',
                          'UserKnownHostsFile=' + c['web_known_hosts'], 'GlobalKnownHostsFile=/dev/null',
                          'reconnect', 'ServerAliveInterval=15', 'ServerAliveCountMax=3', 'allow_other', 'default_permissions', 'cache=no', 'sshfs_sync',
                          'uid=' + str(u.pw_uid), 'gid=' + str(u.pw_gid), 'umask=' + ('022' if n == 'uploads' else '077')])]
        # All argv tokens originate from root config/generated tenant identifiers; spaces are forbidden.
        require(all(not re.search(r'\s', a) for a in args), 'service_argument')
        name = 'vialcove-' + t['slug'] + '-' + n
        unit = '[Unit]\nAfter=network-online.target\nWants=network-online.target\n[Service]\nExecStart=' + ' '.join(args) + '\nExecStop=/usr/bin/fusermount3 -u ' + str(t['data'] / n) + '\nRestart=always\nRestartSec=3\n[Install]\nWantedBy=multi-user.target\n'
        write('/etc/systemd/system/' + name + '.service', unit, 0o644)
        run(['systemctl', 'daemon-reload'])
        run(['systemctl', 'enable', '--now', name + '.service'])
    for _ in range(30):
        if all((t['data'] / n).is_mount() for n in ['uploads', 'reports']):
            return
        time.sleep(.5)
    raise ValueError('media_mount_failed')


def admin_create(r, state, c, secret):
    t = tenant(state['slug'], c, r['customer_domain'])
    t.update(uuid=state['uuid'], server_ip=c['admin_ip'], data=Path(c['admin_store_root']) / t['slug'] / 'shared', public=Path(c['admin_store_root']) / t['slug'] / 'public')
    marker = t['public'].parent / '.provision-owner.json'
    if t['public'].parent.exists():
        require(marker.is_file() and not marker.is_symlink() and json.loads(marker.read_text(encoding='utf-8')) == {'operation_id': r['operation_id'], 'uuid': state['uuid']}, 'local_tenant_ownership')
    else:
        t['public'].parent.mkdir(parents=True, mode=0o755)
        t['public'].parent.chmod(0o755)  # UMask=0077 would leave 0700: the store account must traverse it.
        private_json(marker, {'operation_id': r['operation_id'], 'uuid': state['uuid']})
    make_user(t['user'])
    for n in ['', 'uploads', 'reports', 'ops', 'tmp', 'sessions']:
        app_dir(t['data'] / n, t['user'], 0o755 if n == 'uploads' else (0o711 if n == '' else 0o700))
    key = Path(c['state_root']) / 'jobs' / r['operation_id'] / 'media-key'
    if not key.exists():
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)])
    payload = {'request': r, 'slug': state['slug'], 'uuid': state['uuid'], 'media_key': key.with_suffix('.pub').read_text(encoding='utf-8').strip(), 'registration_secret': secret}
    args = ['ssh', '-i', c['web_ssh_key'], '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' + c['web_known_hosts'], '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=12',
            c['web_ssh_user'] + '@' + c['web_host'], 'create']
    response = json.loads(run(args, data=json.dumps(payload, ensure_ascii=False).encode(), timeout=420))
    require(response.get('ready') and response.get('uuid') == state['uuid'] and response.get('media_user') == 'vcm_' + state['slug'], 'remote_response')
    mount_media(t, c, key, response['media_user'])
    if not t['public'].exists():
        copy_runtime(c['source_admin_root'], t['public'])
        (t['public'] / 'wp-content/uploads').symlink_to(t['data'] / 'uploads')
    config = response['config']
    config = config.replace("'DB_HOST','localhost'", "'DB_HOST','127.0.0.1:13361'")
    webdata = str(Path(c['web_data_root']) / t['slug'])
    config = config.replace(webdata, str(t['data']))
    require("'DB_HOST','127.0.0.1:13361'" in config, 'database_channel_configuration')
    write(t['data'] / 'wp-config.php', config, 0o640, t['user'])
    wrapper(t, t['data'] / 'wp-config.php', shared=True)
    certificate(t, t['slug'] + '.' + c['management_domain'])
    fpm(t)
    vhost(t, t['slug'] + '.' + c['management_domain'], tls=True)
    hq_config = Path(c['hq_config'])
    previous = hq_config.read_text(encoding='utf-8')
    pattern = r"define\(\s*['\"]WP_ACCESSIBLE_HOSTS['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\);"
    match = re.search(pattern, previous)
    require(match is not None and hq_config.stat().st_uid == 0 and not hq_config.is_symlink(), 'hq_allowlist_config')
    hosts = [h.strip() for h in match.group(1).split(',') if h.strip()]
    hostname = t['slug'] + '.' + c['management_domain']
    if hostname not in hosts:
        hosts.append(hostname)
        updated = re.sub(pattern, "define('WP_ACCESSIBLE_HOSTS', '" + ','.join(hosts) + "');", previous)
        write(hq_config, updated, 0o640, c['hq_user'])
        run(['php', '-l', str(hq_config)])
        run(['systemctl', 'reload', 'php8.3-fpm'])
    # Native registration retains new per-store UUID/key. No merchant credentials are inherited.
    helper = t['data'] / 'register.php'
    write(helper, "<?php\nPeptideCore\\Domains::ensure_primary();foreach(PeptideCore\\Domains::items() as $host=>$unused){PeptideCore\\Domains::check($host);}$r=PeptideCore\\Hq_Connector::register();if(is_wp_error($r)){throw new RuntimeException('registration_failed');}echo json_encode(['registered'=>true]);", 0o640, t['user'])
    try:
        result = json.loads(run(['/usr/local/bin/wp', '--path=' + str(t['public']), '--url=' + t['management_url'], 'eval-file', str(helper)], user=t['user']))
        require(result.get('registered'), 'registration_failed')
    finally:
        helper.unlink(missing_ok=True)
    return t


def confirm_hq(t, c, job):
    helper = job / 'hq-confirm.php'
    expected = json.dumps(t['management_url'])
    identity = json.dumps(t['uuid'])
    code = "<?php\n$found=[];foreach(PeptideHq\\Sites::all() as $s){if(rtrim(PeptideHq\\Site_Urls::management($s),'/')===" + expected + " && rtrim(PeptideHq\\Site_Urls::public_url($s),'/')===" + json.dumps(t['public_url']) + " && $s['uuid']===" + identity + "){$found[]=$s;}}if(count($found)!==1){throw new RuntimeException('identity');}$id=(int)$found[0]['id'];$server=0;foreach(PeptideHq\\Servers::all() as $v){if($v['ip']===" + json.dumps(c['public_ip']) + "){$server=$v['id'];break;}}if(!$server){$server=PeptideHq\\Servers::save(0,'VialCove storefront 01'," + json.dumps(c['public_ip']) + ",'Provisioned storefronts');}if(is_wp_error($server)||!$server||is_wp_error(PeptideHq\\Servers::assign($id,(int)$server))){throw new RuntimeException('server_assignment');}if(!PeptideHq\\Monitor::poll($id,true)){throw new RuntimeException('monitor');}echo json_encode(['site_id'=>$id]);"
    write(helper, code)
    try:
        data = json.loads(run(['php', '/usr/local/bin/wp', '--allow-root', '--path=' + c['hq_root'], 'eval-file', str(helper)]))
        return int(data['site_id'])
    finally:
        helper.unlink(missing_ok=True)


def accept_request(root, r):
    """v1 may replay an already-complete job only; it never provisions or rewrites it."""
    r = validate_request(r, allow_legacy=True)
    job = Path(root) / 'jobs' / r['operation_id']
    require(not job.is_symlink(), 'job_symlink')
    statefile = job / 'state.json'
    finished = statefile.exists() and json.loads(statefile.read_text(encoding='utf-8')).get('state') == 'complete'
    if finished:
        original = json.loads((job / 'request.json').read_text(encoding='utf-8'))
        require({k: v for k, v in original.items() if k != 'password'} == {k: v for k, v in r.items() if k != 'password'}, 'request_changed')
        return job
    require(r['version'] == 2, 'legacy_request_requires_domain')
    if not job.exists():
        job.mkdir(mode=0o700)
        private_json(job / 'request.json', r)
    elif not (job / 'request.json').exists() and not statefile.exists():
        private_json(job / 'request.json', r)
    else:
        require(json.loads((job / 'request.json').read_text(encoding='utf-8')) == r, 'request_changed')
    return job


def process_queue(c):
    root = Path(c['state_root'])
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    for n in ['jobs', 'requests', 'results']:
        (root / n).mkdir(exist_ok=True, mode=0o700)
    fd = os.open(root / 'worker.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'a') as lock:
        st = os.fstat(lock.fileno())
        require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1 and not st.st_mode & 0o022, 'worker_lock')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        return _process_queue_locked(c, root)


def _process_queue_locked(c, root):
    lifecycle = lifecycle_module()
    with lifecycle.lock(Path('/var/lib/vialcove-deploy/nginx-update.lock'), nonblocking=True), \
            lifecycle.lock(Path('/var/lib/vialcove-deploy/store-admin-update.lock'), nonblocking=True):
        lifecycle_changed = lifecycle.process_queue(c, lock_held=True)
    if lifecycle_changed:
        # Apply trusted receipts even when no administrator is visiting HQ.
        run(['/usr/local/bin/wp', '--path=' + c['hq_root'], 'eval',
             'PeptideHq\\Site_Lifecycle::sync();'], user=c['hq_user'])
    def status(job, **fields):
        private_json(root / 'results' / (job + '.json'), fields, c['hq_user'])
    owner = pwd.getpwnam(c['hq_user']).pw_uid
    for entry in sorted((root / 'requests').glob('*.json')):
        if time.time() - entry.lstat().st_mtime < 3:
            continue
        try:
            fd = os.open(entry, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(fd, 'rb') as f:
                st = os.fstat(f.fileno())
                require(stat.S_ISREG(st.st_mode) and st.st_uid == owner and st.st_nlink == 1 and st.st_size <= 4096, 'queue_file')
                r = validate_request(json.loads(f.read(4097)), allow_legacy=True)
            require(entry.name == r['operation_id'] + '.json', 'queue_id')
            accept_request(root, r)
            entry.unlink()
        except Exception as e:
            if isinstance(e, ValueError) and str(e) == 'legacy_request_requires_domain':
                status(r['operation_id'], state='failed', message='旧版请求缺少顾客域名，请通过新开店表单提交新任务。原店铺不会修改。')
            # Never interpolate request content or an exception carrying passwords into logs.
            continue
    for job in sorted((root / 'jobs').iterdir()):
        if not job.is_dir() or job.is_symlink():
            continue
        statefile = job / 'state.json'
        state = json.loads(statefile.read_text(encoding='utf-8')) if statefile.exists() else {}
        if state.get('state') in {'complete', 'failed'}:
            continue
        try:
            r = validate_request(json.loads((job / 'request.json').read_text(encoding='utf-8')), allow_legacy=True)
            require(r['version'] == 2, 'legacy_request_requires_domain')
            if not state:
                records = [json.loads(p.read_text(encoding='utf-8')) for p in (root / 'jobs').glob('*/state.json')]
                allocated = [s for row in records if re.fullmatch(r's[0-9]{4}', s := str(row.get('slug', '')))]
                # Failed preflight identities are never reused, but own no VM/DB capacity.
                # Purged stores (lifecycle tombstone) also release their slot; their slug is still never reused.
                require(sum(row.get('state') != 'failed' and 'slug' in row and not store_purged(root, row) for row in records) < c['max_new_stores'], 'store_limit')
                number = max([1] + [int(s[1:]) for s in allocated]) + 1
                state = {'slug': 's' + str(number).zfill(4), 'uuid': str(uuid.uuid4()), 'state': 'pending', 'customer_domain': r['customer_domain']}
                private_json(statefile, state)
            t = tenant(state['slug'], c, r['customer_domain'])
            t['uuid'] = state['uuid']
            lifecycle_state = root / 'lifecycle/tenants' / t['uuid'] / 'current.json'
            if lifecycle_state.exists():
                identity = lifecycle.load_json(lifecycle_state, private=True)
                require(identity.get('uuid') == t['uuid'] and identity.get('state') == 'active', 'store_retired')
            claim_domain(root, t, r['operation_id'])
            if not dns_ready(state['slug'] + '.' + c['management_domain'], c['admin_ip']) or not customer_dns_ready(t, c):
                status(job.name, state='waiting_dns', message='请为顾客域名添加以下两条 A 记录。解析正确后自动继续；固定管理地址由平台维护。',
                       slug=state['slug'], customer_domain=t['customer_domain'], dns_records=dns_records(t, c))
                continue
            status(job.name, state='running', message='正在创建独立数据库、账号、媒体目录与 HTTPS。', slug=state['slug'], customer_domain=state.get('customer_domain', ''))
            # Registration secret is loaded on demand, never written to queue results or process argv.
            code = "echo PeptideHq\\Register::secret();"
            secret = run(['php', '/usr/local/bin/wp', '--allow-root', '--path=' + c['hq_root'], 'eval', code]).decode().strip()
            require(re.fullmatch(r'[a-f0-9]{64}', secret), 'hq_registration_secret')
            t = admin_create(r, state, c, secret)
            site_id = confirm_hq(t, c, job)
            lifecycle.register_provisioned(c, t, r, state, site_id)
            state['state'] = 'complete'
            private_json(statefile, state)
            # Retain no plaintext account password after native installation completes.
            r['password'] = ''
            private_json(job / 'request.json', r)
            status(job.name, state='complete', message='店铺已创建，可从店铺列表一键进入。', slug=t['slug'], management_url=t['management_url'] + '/wp-admin/',
                   public_url=t['public_url'] + '/', site_id=site_id, customer_domain=t['customer_domain'], dns_records=dns_records(t, c))
        except Exception as e:
            code = str(e) if isinstance(e, ValueError) and re.fullmatch(r'[a-z0-9_]+', str(e)) else 'deployment_step_failed'
            if code in {'domain_claim_conflict', 'platform_domain_reserved', 'legacy_request_requires_domain', 'store_retired'}:
                state['state'] = 'failed'
                private_json(statefile, state)
                status(job.name, state='failed', message='域名已被占用、属于平台保留地址，或请求缺少顾客域名。未变更已完成店铺，请核对后提交新任务。')
            elif code == 'store_limit':
                status(job.name, state='waiting_capacity', message='已达到当前服务器的开店容量上限，请管理员评估负载并调整容量后继续。', slug=state.get('slug', ''), customer_domain=state.get('customer_domain', ''))
            else:
                status(job.name, state='pending', message='这一步还没完成，开店服务会自动重试，不用重新提交。', slug=state.get('slug', ''), customer_domain=state.get('customer_domain', ''))
            write(job / 'error-code.txt', type(e).__name__ + ':' + code)


def main():
    require(os.geteuid() == 0, 'root_required')
    config_path = Path('/etc/vialcove-provision/config.json')
    require(config_path.stat().st_uid == 0 and not config_path.is_symlink() and config_path.stat().st_mode & 0o022 == 0, 'root_config')
    c = json.loads(config_path.read_text(encoding='utf-8'))
    if sys.argv[1:] == ['--reserve-legacy-domain']:
        raw = sys.stdin.buffer.read(2049)
        require(len(raw) <= 2048, 'legacy_claim_size')
        require(c['role'] in {'web', 'admin'}, 'role')
        root = c['web_ledger_root'] if c['role'] == 'web' else c['state_root']
        claim = reserve_legacy_domain(root, json.loads(raw))
        # UUID is private identity metadata, not a public result or process argument.
        print(json.dumps({'reserved': True, 'customer_domain': claim['customer_domain'], 'kind': 'legacy'}))
        return
    require(not sys.argv[1:] or c['role'] == 'web' and sys.argv[1:] in [['create'], ['lifecycle']], 'arguments')
    if c['role'] == 'web':
        # Plugin envelopes carry a base64 package (bounded separately); everything else stays <= 16 KiB.
        raw = sys.stdin.buffer.read(48 * 1024 * 1024 + 1)
        require(len(raw) <= 48 * 1024 * 1024, 'request_size')
        payload = json.loads(raw)
        require(len(raw) <= 16384 or isinstance(payload, dict) and payload.get('phase') == 'plugin', 'request_size')
        if isinstance(payload, dict) and payload.get('phase') == 'plugin':
            sys.stdout.write(json.dumps(plugins_module().web_main(c, payload)))
            return
        # The existing forced-command wrapper deliberately discards SSH command
        # text. Dispatch only a validated, bounded typed envelope, never that text.
        controlled_lifecycle = isinstance(payload, dict) and 'phase' in payload
        result = lifecycle_module().web_main(c, payload) if sys.argv[1:] == ['lifecycle'] or controlled_lifecycle else web_create(payload, c)
        sys.stdout.write(json.dumps(result))
    elif c['role'] == 'admin':
        process_queue(c)
        if Path(__file__).resolve().with_name('plugins.py').exists():
            plugins_module().process_queue(c)
    else:
        raise ValueError('role')


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        # Bounded step codes (e.g. waiting_dns) carry no request data; anything else stays generic.
        code = str(e) if isinstance(e, ValueError) and re.fullmatch(r'[a-z0-9_]{1,64}', str(e)) else ''
        sys.stderr.write('Provisioning did not complete: ' + type(e).__name__ + (':' + code if code else '') + '\n')
        sys.exit(1)
