#!/usr/bin/python3 -I
"""Root-owned, bounded tenant deployment broker. No user-selected commands or paths."""
import base64
import fcntl
import grp
import hashlib
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
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(text)
        f.flush()
        os.fsync(f.fileno())
    if group:
        os.chown(tmp, 0, grp.getgrnam(group).gr_gid)
    os.replace(tmp, path)


def private_json(path, obj, group=None):
    write(path, json.dumps(obj, ensure_ascii=False), 0o640 if group else 0o600, group)


def validate_request(r):
    require(isinstance(r, dict) and set(r) == {'version', 'operation_id', 'actor_id', 'title', 'login', 'email', 'password'}, 'request_shape')
    require(r['version'] == 1 and isinstance(r['actor_id'], int) and r['actor_id'] > 0, 'actor')
    require(isinstance(r['operation_id'], str) and str(uuid.UUID(r['operation_id'], version=4)) == r['operation_id'] and uuid.UUID(r['operation_id']).version == 4, 'operation_id')
    require(isinstance(r['title'], str) and 0 < len(r['title']) <= 100 and not re.search(r'[\x00-\x1f\x7f<>]', r['title']), 'title')
    require(isinstance(r['login'], str) and re.fullmatch(r'[a-z][a-z0-9_]{2,31}', r['login']), 'login')
    require(isinstance(r['email'], str) and len(r['email']) <= 190 and re.fullmatch(r'[^\s<>"\x00-\x1f@]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,63}', r['email']), 'email')
    require(isinstance(r['password'], str) and 12 <= len(r['password'].encode()) <= 128 and not re.search(r'[\x00-\x1f\x7f]', r['password']), 'password')
    return r


def tenant(slug, c):
    require(isinstance(slug, str) and re.fullmatch(r's[0-9]{4}', slug) and 2 <= int(slug[1:]) <= 9999, 'tenant_slug')
    return {'slug': slug, 'user': 'vc' + slug, 'db': 'vc_' + slug,
            'management_url': 'https://' + slug + '.' + c['management_domain'],
            'public_url': 'https://' + slug + '.' + c['public_domain']}


def dns_ready(host, expected):
    try:
        return {x[4][0] for x in socket.getaddrinfo(host, 443, socket.AF_INET)} == {expected}
    except socket.gaierror:
        return False


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
    write(mu / 'vialcove-mail-gate.php', "<?php\ndefined('ABSPATH')||exit;\nadd_filter('pre_wp_mail','__return_false',PHP_INT_MIN);\nadd_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);\n", 0o644)


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


def vhost(t, host, *, tls=False):
    write('/etc/nginx/conf.d/vialcove-tenant-login-limit.conf',
          'limit_req_zone $binary_remote_addr zone=vialcove_tenant_login:10m rate=6r/m;\n', 0o644)
    path = Path('/etc/nginx/sites-available/vialcove-' + t['slug'])
    acme = '/var/www/vialcove-tenant-acme'
    Path(acme, '.well-known/acme-challenge').mkdir(parents=True, exist_ok=True)
    text = f'''server {{
 listen 80; listen [::]:80; server_name {host};
 location ^~ /.well-known/acme-challenge/ {{ root {acme}; default_type text/plain; try_files $uri =404; }}
 location / {{ return {'308 https://' + host + '$request_uri' if tls else '503'}; }}
}}
'''
    if tls:
        text += fr'''server {{
 listen 443 ssl; listen [::]:443 ssl; server_name {host};
 if ($host != {host}) {{ return 444; }}
 if ($ssl_server_name != {host}) {{ return 444; }}
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
    previous = path.read_text() if path.exists() else None
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


def certificate(t, host):
    require(dns_ready(host, t['server_ip']), 'waiting_dns')
    vhost(t, host)
    if not Path('/etc/letsencrypt/live', host, 'fullchain.pem').exists():
        run(['certbot', 'certonly', '--webroot', '-w', '/var/www/vialcove-tenant-acme', '-d', host,
             '--non-interactive', '--agree-tos', '--register-unsafely-without-email'], timeout=180)


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
update_option('home',$x['home']);update_option('siteurl',$x['siteurl']);update_option('blog_public','0');
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
    run([*wp, 'plugin', 'activate', 'woocommerce', 'peptide-core', 'peptide-panapay'], user=t['user'])
    run([*wp, 'theme', 'activate', 'peptide-site'], user=t['user'])
    setup = t['data'] / 'setup.php'
    write(setup, "<?php\nWC_Install::create_pages();\nupdate_option('woocommerce_allow_tracking','no');update_option('woocommerce_default_country','US');\n", 0o640, t['user'])
    try:
        run([*wp, 'eval-file', str(setup)], user=t['user'])
    finally:
        setup.unlink(missing_ok=True)


def export_media(t, media_key, admin_ip):
    user = 'vcm_' + t['slug']
    make_user(user)
    jail = Path('/srv/vialcove-media-export', t['slug'])
    jail.mkdir(parents=True, exist_ok=True, mode=0o755)
    for name in ['uploads', 'reports']:
        src, dst = t['data'] / name, jail / name
        dst.mkdir(exist_ok=True, mode=0o755)
        run(['setfacl', '-R', '-m', f'u:{user}:rwX,u:{t["user"]}:rwX', str(src)])
        for root, dirs, files in os.walk(src):
            run(['setfacl', '-m', f'd:u:{user}:rwx,d:u:{t["user"]}:rwx', root])
        if not dst.is_mount():
            run(['mount', '--bind', str(src), str(dst)])
    keys = Path('/etc/ssh/vialcove-bridge-keys')
    keys.mkdir(exist_ok=True, mode=0o755)
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


def seed_template(t, c):
    require(Path(c['source_web_root']).resolve() != t['public'].resolve(), 'master_is_target')
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
        require(p.suffix.lower() in {'.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif'} and isinstance(image['old_id'], int) and image['old_id'] > 0, 'template_image')
        total += p.stat().st_size
        require(p.stat().st_size <= 10 * 1024**2 and total <= 128 * 1024**2, 'template_media_size')
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


def web_create(p, c):
    require(set(p) == {'request', 'slug', 'uuid', 'media_key', 'registration_secret'}, 'remote_shape')
    r = validate_request(p['request'])
    t = tenant(p['slug'], c)
    require(str(uuid.UUID(p['uuid'])) == p['uuid'], 'tenant_uuid')
    require(re.fullmatch(r'[a-f0-9]{64}', p['registration_secret']), 'registration_secret')
    t.update(uuid=p['uuid'], server_ip=c['public_ip'], data=Path(c['web_data_root']) / t['slug'], public=Path(c['web_store_root']) / t['slug'] / 'public')
    ledger = Path(c['web_ledger_root'])
    ledger.mkdir(parents=True, exist_ok=True, mode=0o700)
    digest = hashlib.sha256(json.dumps(p, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
    statefile = ledger / (t['slug'] + '.json')
    state = json.loads(statefile.read_text()) if statefile.exists() else {}
    require(not state or state.get('digest') == digest, 'tenant_ownership')
    require(dns_ready(t['slug'] + '.' + c['public_domain'], c['public_ip']), 'waiting_dns')
    if not state:
        require(len(list(ledger.glob('s*.json'))) < c['max_new_stores'], 'store_limit')
        require(not t['public'].parent.exists() and not t['data'].exists(), 'tenant_path_exists')
        require(shutil.disk_usage(c['web_data_root']).free > 5 * 1024**3, 'disk_capacity')
        make_user(t['user'])
        t['public'].parent.mkdir(parents=True, mode=0o755)
        for n in ['', 'uploads', 'reports', 'ops', 'tmp', 'sessions']:
            app_dir(t['data'] / n, t['user'], 0o755 if n == 'uploads' else (0o711 if n == '' else 0o700))
        state = {'digest': digest, 'phase': 'reserved', 'front_password': secrets.token_hex(32), 'admin_password': secrets.token_hex(32),
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
        certificate(t, t['slug'] + '.' + c['public_domain'])
        fpm(t)
        vhost(t, t['slug'] + '.' + c['public_domain'], tls=True)
        save('served')
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
    t = tenant(state['slug'], c)
    t.update(uuid=state['uuid'], server_ip=c['admin_ip'], data=Path(c['admin_store_root']) / t['slug'] / 'shared', public=Path(c['admin_store_root']) / t['slug'] / 'public')
    marker = t['public'].parent / '.provision-owner.json'
    if t['public'].parent.exists():
        require(marker.is_file() and not marker.is_symlink() and json.loads(marker.read_text()) == {'operation_id': r['operation_id'], 'uuid': state['uuid']}, 'local_tenant_ownership')
    else:
        t['public'].parent.mkdir(parents=True, mode=0o755)
        private_json(marker, {'operation_id': r['operation_id'], 'uuid': state['uuid']})
    make_user(t['user'])
    for n in ['', 'uploads', 'reports', 'ops', 'tmp', 'sessions']:
        app_dir(t['data'] / n, t['user'], 0o755 if n == 'uploads' else (0o711 if n == '' else 0o700))
    key = Path(c['state_root']) / 'jobs' / r['operation_id'] / 'media-key'
    if not key.exists():
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)])
    payload = {'request': r, 'slug': state['slug'], 'uuid': state['uuid'], 'media_key': key.with_suffix('.pub').read_text().strip(), 'registration_secret': secret}
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
    previous = hq_config.read_text()
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
    code = "<?php\n$found=[];foreach(PeptideHq\\Sites::all() as $s){if(rtrim(PeptideHq\\Site_Urls::management($s),'/')===" + expected + " && $s['uuid']===" + identity + "){$found[]=$s;}}if(count($found)!==1){throw new RuntimeException('identity');}$id=(int)$found[0]['id'];$server=0;foreach(PeptideHq\\Servers::all() as $v){if($v['ip']===" + json.dumps(c['public_ip']) + "){$server=$v['id'];break;}}if(!$server){$server=PeptideHq\\Servers::save(0,'VialCove storefront 01'," + json.dumps(c['public_ip']) + ",'Provisioned storefronts');}if(is_wp_error($server)||!$server||is_wp_error(PeptideHq\\Servers::assign($id,(int)$server))){throw new RuntimeException('server_assignment');}if(!PeptideHq\\Monitor::poll($id,true)){throw new RuntimeException('monitor');}echo json_encode(['site_id'=>$id]);"
    write(helper, code)
    try:
        data = json.loads(run(['php', '/usr/local/bin/wp', '--allow-root', '--path=' + c['hq_root'], 'eval-file', str(helper)]))
        return int(data['site_id'])
    finally:
        helper.unlink(missing_ok=True)


def process_queue(c):
    root = Path(c['state_root'])
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    for n in ['jobs', 'requests', 'results']:
        (root / n).mkdir(exist_ok=True, mode=0o700)
    lock = (root / 'worker.lock').open('a')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        return
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
                r = validate_request(json.loads(f.read(4097)))
            require(entry.name == r['operation_id'] + '.json', 'queue_id')
            job = root / 'jobs' / r['operation_id']
            if not job.exists():
                job.mkdir(mode=0o700)
                private_json(job / 'request.json', r)
            elif not (job / 'request.json').exists() and not (job / 'state.json').exists():
                private_json(job / 'request.json', r)
            else:
                original = json.loads((job / 'request.json').read_text())
                finished = (job / 'state.json').exists() and json.loads((job / 'state.json').read_text()).get('state') == 'complete'
                if finished:
                    # Completed operations are immutable; replay returns the same result,
                    # never recreates or changes an account whose password was redacted.
                    require({k: v for k, v in original.items() if k != 'password'} == {k: v for k, v in r.items() if k != 'password'}, 'request_changed')
                else:
                    require(original == r, 'request_changed')
            entry.unlink()
        except Exception:
            # Never interpolate request content or an exception carrying passwords into logs.
            continue
    for job in sorted((root / 'jobs').iterdir()):
        if not job.is_dir() or job.is_symlink():
            continue
        statefile = job / 'state.json'
        state = json.loads(statefile.read_text()) if statefile.exists() else {}
        if state.get('state') == 'complete':
            continue
        r = validate_request(json.loads((job / 'request.json').read_text()))
        try:
            if not state:
                allocated = [json.loads(p.read_text())['slug'] for p in (root / 'jobs').glob('*/state.json')]
                require(len(allocated) < c['max_new_stores'], 'store_limit')
                number = max([1] + [int(s[1:]) for s in allocated]) + 1
                state = {'slug': 's' + str(number).zfill(4), 'uuid': str(uuid.uuid4()), 'state': 'pending'}
                private_json(statefile, state)
            t = tenant(state['slug'], c)
            if not dns_ready(state['slug'] + '.' + c['management_domain'], c['admin_ip']) or not dns_ready(state['slug'] + '.' + c['public_domain'], c['public_ip']):
                status(job.name, state='waiting_dns', message='等待泛解析生效，将自动继续。', slug=state['slug'])
                continue
            status(job.name, state='running', message='正在创建独立数据库、账号、媒体目录与 HTTPS。', slug=state['slug'])
            # Registration secret is loaded on demand, never written to queue results or process argv.
            code = "echo PeptideHq\\Register::secret();"
            secret = run(['php', '/usr/local/bin/wp', '--allow-root', '--path=' + c['hq_root'], 'eval', code]).decode().strip()
            require(re.fullmatch(r'[a-f0-9]{64}', secret), 'hq_registration_secret')
            t = admin_create(r, state, c, secret)
            site_id = confirm_hq(t, c, job)
            state['state'] = 'complete'
            private_json(statefile, state)
            # Retain no plaintext account password after native installation completes.
            r['password'] = ''
            private_json(job / 'request.json', r)
            status(job.name, state='complete', message='店铺已创建，可从店铺列表一键进入。', slug=t['slug'], management_url=t['management_url'] + '/wp-admin/', public_url=t['public_url'] + '/', site_id=site_id)
        except Exception as e:
            if isinstance(e, ValueError) and str(e) == 'store_limit':
                status(job.name, state='waiting_capacity', message='已达到当前服务器的开店容量上限，请管理员评估负载并调整容量后继续。', slug=state.get('slug', ''))
            else:
                status(job.name, state='pending', message='部署尚未完成，服务会安全重试；管理员可查看私有部署记录。', slug=state.get('slug', ''))
            write(job / 'error-code.txt', type(e).__name__ + ':' + (str(e) if isinstance(e, ValueError) and re.fullmatch(r'[a-z0-9_]+', str(e)) else 'deployment_step_failed'))


def main():
    require(os.geteuid() == 0, 'root_required')
    config_path = Path('/etc/vialcove-provision/config.json')
    require(config_path.stat().st_uid == 0 and not config_path.is_symlink() and config_path.stat().st_mode & 0o022 == 0, 'root_config')
    c = json.loads(config_path.read_text())
    if c['role'] == 'web':
        raw = sys.stdin.buffer.read(16385)
        require(len(raw) <= 16384, 'request_size')
        result = web_create(json.loads(raw), c)
        sys.stdout.write(json.dumps(result))
    elif c['role'] == 'admin':
        process_queue(c)
    else:
        raise ValueError('role')


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        sys.stderr.write('Provisioning did not complete: ' + type(e).__name__ + '\n')
        sys.exit(1)
