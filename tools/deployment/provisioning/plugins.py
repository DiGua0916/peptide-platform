#!/usr/bin/python3 -I
"""Root-only third-party plugin broker for HQ "插件管理" (2026-11-08).

HQ (the vchq user) may only deposit two kinds of bounded files:
  <state_root>/plugins/packages/<sha256>.zip  -- the uploaded plugin package
  <state_root>/plugins/requests/<operation_id>.json  -- {install|uninstall, store uuid, plugin slug, package sha}
It never names a path, user or command. This broker resolves the store from
root-owned evidence only (lifecycle inventory for the first store, completed
provisioning jobs / tenant wp-config for later stores), unpacks the zip with
strict checks into /srv/vialcove/store-plugins/<store>/<plugin>/ (root-owned,
read-only for PHP) and links it into every code root of that store:
  admin host: the store-admin release (or tenant-admin public dir)
  web host:   the storefront release (or tenant public dir), via the existing
              forced-command SSH channel ('plugin' phase envelope).
Plugins therefore live outside release directories: a new release made with
`cp -a current next` keeps the links, and core plugins can never be replaced.
Activation runs `wp plugin activate` as the store's PHP user (shared database,
so once on the admin host). A health check opens the storefront home and cart;
a failing store gets the plugin deactivated again and the job is reported failed.

Integration: worker.py loads this module beside itself. Admin role:
process_queue(c) after the provisioning queue. Web role: web_main(c, payload)
for envelopes with phase == 'plugin'.
"""
import base64
import contextlib
import fcntl
import grp
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import secrets
import shutil
import stat
import subprocess
import time
import urllib.error
import urllib.request
import uuid as uuidlib
import zipfile

MAX_PACKAGE = 32 * 1024 * 1024          # zip as uploaded
MAX_UNPACKED = 256 * 1024 * 1024        # total uncompressed size
MAX_FILES = 20000
MAX_REQUEST = 4096
MAX_ENVELOPE = 48 * 1024 * 1024         # base64 package + request
INVENTORY_PATH = '/etc/vialcove-provision/lifecycle-inventory.json'
STORE_PLUGINS = '/srv/vialcove/store-plugins'
SLUG_RE = re.compile(r'[a-z0-9][a-z0-9._-]{0,63}')
STORE_RE = re.compile(r's[0-9]{4}')
SHA_RE = re.compile(r'[0-9a-f]{64}')
# Platform plugins are shipped by releases; a third-party package may never shadow them.
RESERVED = {'peptide-core', 'peptide-panapay', 'peptide-ops', 'peptide-hq', 'woocommerce',
            'fluent-smtp', 'sqlite-database-integration', 'index.php', 'akismet', 'hello.php'}
REQUEST_FIELDS = {'version', 'operation_id', 'actor_id', 'site_id', 'uuid', 'action', 'plugin', 'package'}
RESULT_FIELDS = {'operation_id', 'site_id', 'uuid', 'action', 'plugin', 'state', 'version', 'name',
                 'error_code', 'message', 'finished_at'}


def require(value, code):
    if not value:
        raise ValueError(code)


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()


def safe_error(exc):
    code = str(exc) if isinstance(exc, ValueError) else type(exc).__name__.lower()
    return code if re.fullmatch(r'[a-z0-9_]{1,80}', code) else 'plugin_job_failed'


def valid_uuid(value, code, version=None):
    require(isinstance(value, str), code)
    try:
        parsed = uuidlib.UUID(value)
    except ValueError:
        raise ValueError(code)
    require(str(parsed) == value and (version is None or parsed.version == version), code)
    return value


def validate_request(request):
    require(isinstance(request, dict) and set(request) == REQUEST_FIELDS, 'plugin_request_shape')
    require(type(request['version']) is int and request['version'] == 1, 'plugin_request_version')
    for key in ('actor_id', 'site_id'):
        require(type(request[key]) is int and request[key] > 0, key)
    valid_uuid(request['operation_id'], 'operation_id', 4)
    valid_uuid(request['uuid'], 'uuid')
    require(request['action'] in {'install', 'uninstall'}, 'plugin_action')
    require(isinstance(request['plugin'], str) and SLUG_RE.fullmatch(request['plugin'])
            and request['plugin'] not in RESERVED, 'plugin_slug')
    if request['action'] == 'install':
        require(isinstance(request['package'], str) and SHA_RE.fullmatch(request['package']), 'plugin_package')
    else:
        require(request['package'] == '', 'plugin_package')
    return dict(request)


# ---------- filesystem helpers ----------

def trusted_uids(c):
    return (0, pwd.getpwnam(c['hq_user']).pw_uid) if c.get('hq_user') else (0,)


def no_symlink_components(path):
    path = Path(path)
    require(path.is_absolute() and '..' not in path.parts, 'absolute_path')
    current = Path(path.parts[0])
    for part in path.parts[1:]:
        current = current / part
        require(not current.is_symlink(), 'path_component_symlink')


def private_dir(path):
    path = Path(path)
    require(not path.is_symlink(), 'private_directory_symlink')
    path.mkdir(parents=True, exist_ok=True, mode=0o700)
    st = path.stat()
    require(stat.S_ISDIR(st.st_mode) and st.st_uid == os.geteuid() and not st.st_mode & 0o077,
            'private_directory_permissions')
    return path


def queue_dir(path, group, mode):
    path = Path(path)
    no_symlink_components(path)
    gid = grp.getgrnam(group).gr_gid
    if not path.exists():
        path.mkdir(mode=mode)
        os.chown(path, os.geteuid(), gid)
        os.chmod(path, mode)
    st = path.stat()
    require(stat.S_ISDIR(st.st_mode) and st.st_uid == os.geteuid() and st.st_gid == gid
            and stat.S_IMODE(st.st_mode) == mode, 'queue_directory_permissions')
    return path


def read_checked(path, *, owners, limit, private=False):
    path = Path(path)
    no_symlink_components(path.parent)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        st = os.fstat(stream.fileno())
        require(stat.S_ISREG(st.st_mode) and st.st_nlink == 1 and st.st_uid in owners
                and not st.st_mode & (0o077 if private else 0o022) and st.st_size <= limit,
                'protected_file_permissions')
        value = stream.read(limit + 1)
        require(len(value) <= limit, 'protected_file_size')
        return value


def atomic(path, value, *, mode=0o600, gid=None):
    path = Path(path)
    require(not path.is_symlink(), 'write_symlink')
    tmp = path.with_name(path.name + '.next-' + secrets.token_hex(8))
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(value)
            stream.flush()
            os.fchmod(stream.fileno(), mode)
            if gid is not None:
                os.fchown(stream.fileno(), os.geteuid(), gid)
            os.fsync(stream.fileno())
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            tmp.unlink()


@contextlib.contextmanager
def lock(path, *, nonblocking=False):
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'a') as stream:
        fcntl.flock(stream, fcntl.LOCK_EX | (fcntl.LOCK_NB if nonblocking else 0))
        yield


def command(args, *, user=None, timeout=180, allowed=(0,)):
    env = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin:/usr/local/bin', 'HOME': '/nonexistent'}
    if user:
        cache = Path('/var/cache/vialcove-cli') / user
        if not cache.exists():
            cache.mkdir(parents=True, mode=0o700)
            pw = pwd.getpwnam(user)
            os.chown(cache, pw.pw_uid, pw.pw_gid)
        env['WP_CLI_CACHE_DIR'] = str(cache)
        args = ['/usr/sbin/runuser', '-u', user, '--', *args]
    p = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, env=env)
    require(p.returncode in allowed, 'command_failed_' + Path(args[0]).name)
    return p.stdout.decode(errors='replace')


# ---------- package ----------

def inspect_package(raw, expected_sha, plugin):
    """Validate a plugin zip and return (members, name, version). Raises on anything unusual."""
    require(len(raw) <= MAX_PACKAGE, 'plugin_package_size')
    require(hashlib.sha256(raw).hexdigest() == expected_sha, 'plugin_package_checksum')
    try:
        archive = zipfile.ZipFile(io.BytesIO(raw))
    except zipfile.BadZipFile:
        raise ValueError('plugin_package_not_zip')
    members, total, tops = [], 0, set()
    for info in archive.infolist():
        name = info.filename
        if name.startswith('__MACOSX/') or PurePosixPath(name).name == '.DS_Store':
            continue
        require('\\' not in name and '\x00' not in name and not name.startswith('/'), 'plugin_package_path')
        parts = PurePosixPath(name).parts
        require(parts and '..' not in parts and '.' not in parts, 'plugin_package_path')
        mode = (info.external_attr >> 16) & 0o170000
        require(mode in (0, stat.S_IFREG, stat.S_IFDIR), 'plugin_package_special_file')
        require(not info.flag_bits & 0x1, 'plugin_package_encrypted')
        tops.add(parts[0])
        total += info.file_size
        members.append(info)
        require(len(members) <= MAX_FILES and total <= MAX_UNPACKED, 'plugin_package_too_large')
    require(tops == {plugin}, 'plugin_package_layout')
    name, version = '', ''
    for info in members:
        parts = PurePosixPath(info.filename).parts
        if len(parts) == 2 and parts[1].endswith('.php') and not info.is_dir():
            head = archive.read(info)[:8192].decode('utf-8', 'replace')
            found = re.search(r'^[ \t/*#@]*Plugin Name:(.*)$', head, re.M)
            if found:
                name = found.group(1).strip()[:120]
                v = re.search(r'^[ \t/*#@]*Version:(.*)$', head, re.M)
                version = v.group(1).strip()[:40] if v else ''
                break
    require(name, 'plugin_header_missing')
    return archive, members, name, version


def unpack(raw, sha, plugin, target):
    archive, members, name, version = inspect_package(raw, sha, plugin)
    target = Path(target)
    staging = target.with_name('.' + target.name + '.next-' + secrets.token_hex(6))
    staging.mkdir(mode=0o755)
    try:
        for info in members:
            rel = PurePosixPath(info.filename).relative_to(plugin)
            dest = staging.joinpath(*rel.parts) if rel.parts else staging
            if info.is_dir():
                dest.mkdir(parents=True, exist_ok=True, mode=0o755)
                continue
            dest.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            fd = os.open(dest, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
            with os.fdopen(fd, 'wb') as out, archive.open(info) as src:
                shutil.copyfileobj(src, out, 1024 * 1024)
        for d, dirs, files in os.walk(staging):
            os.chmod(d, 0o755)
            for f in files:
                os.chmod(os.path.join(d, f), 0o644)
        old = None
        if target.exists():
            require(target.is_dir() and not target.is_symlink(), 'plugin_target_unexpected')
            old = target.with_name('.' + target.name + '.old-' + secrets.token_hex(6))
            os.replace(target, old)
        os.replace(staging, target)
        if old:
            shutil.rmtree(old)
    finally:
        if staging.exists():
            shutil.rmtree(staging)
    return name, version


# ---------- store resolution (root-owned evidence only) ----------

def code_root(path):
    """A store's WordPress root: '<dir>/current' release link, '<dir>/public', or the dir itself."""
    path = Path(path)
    for candidate in (path / 'current', path / 'public', path):
        if (candidate / 'wp-content/plugins').is_dir() and (candidate / 'wp-load.php').exists():
            return candidate
    raise ValueError('store_code_root_missing')


def resolve_admin(c, store_uuid):
    inv_path = Path(c.get('lifecycle_inventory', INVENTORY_PATH))
    if inv_path.exists():
        data = json.loads(read_checked(inv_path, owners=(0, os.geteuid()), limit=1024 * 1024, private=True))
        for site in data.get('sites', []):
            if site.get('uuid') == store_uuid and 'admin' in site:
                require(STORE_RE.fullmatch(site['slug']), 'store_slug')
                roots = [p['path'] for p in site['admin']['paths'] if p.get('kind', 'directory') == 'directory']
                return {'slug': site['slug'], 'root': code_root(roots[0]), 'user': site['admin']['users'][0]}
    jobs = Path(c['state_root']) / 'jobs'
    for state_file in sorted(jobs.glob('*/state.json')) if jobs.is_dir() else []:
        try:
            state = json.loads(read_checked(state_file, owners=(0, os.geteuid()), limit=65536, private=True))
        except (ValueError, OSError):
            continue
        if state.get('uuid') == store_uuid and state.get('state') == 'complete' and STORE_RE.fullmatch(str(state.get('slug'))):
            slug = state['slug']
            return {'slug': slug, 'root': code_root(Path(c['admin_store_root']) / slug / 'public'), 'user': 'vc' + slug}
    raise ValueError('store_not_found')


def resolve_web(c, slug, store_uuid):
    require(STORE_RE.fullmatch(slug), 'store_slug')
    inv_path = Path(c.get('lifecycle_inventory', INVENTORY_PATH))
    if inv_path.exists():
        data = json.loads(read_checked(inv_path, owners=(0, os.geteuid()), limit=1024 * 1024, private=True))
        for site in data.get('sites', []):
            if site.get('uuid') == store_uuid:
                require(site.get('slug') == slug and 'web' in site, 'store_identity_changed')
                roots = [p['path'] for p in site['web']['paths'] if p.get('kind', 'directory') == 'directory']
                return {'slug': slug, 'root': code_root(roots[0]), 'user': site['web']['users'][0]}
    config = Path(c['web_data_root']) / slug / 'wp-config.php'
    text = read_checked(config, owners=(0, os.geteuid()) + tuple(
        [pwd.getpwnam('vc' + slug).pw_uid] if user_exists('vc' + slug) else []), limit=65536).decode('utf-8', 'replace')
    require(re.search(r"define\('PEPTIDE_CORE_MANAGEMENT_UUID','" + re.escape(store_uuid) + r"'\)", text), 'store_identity_changed')
    return {'slug': slug, 'root': code_root(Path(c['web_store_root']) / slug / 'public'), 'user': 'vc' + slug}


def user_exists(name):
    try:
        pwd.getpwnam(name)
        return True
    except KeyError:
        return False


# ---------- install / remove on one host ----------

def plugins_base(c):
    return Path(c.get('store_plugins_root', STORE_PLUGINS))


def link_into(root, plugin, target, base):
    plugins = Path(root).resolve() / 'wp-content/plugins'
    require(plugins.is_dir(), 'store_plugins_dir_missing')
    link = plugins / plugin
    if link.is_symlink():
        require(str(Path(os.readlink(link))).startswith(str(base) + '/'), 'plugin_name_conflict')
        if Path(os.readlink(link)) == Path(target):
            return
        link.unlink()
    require(not link.exists(), 'plugin_name_conflict')
    tmp = plugins / ('.' + plugin + '.link-' + secrets.token_hex(4))
    os.symlink(target, tmp)
    os.replace(tmp, link)


def unlink_from(root, plugin, base):
    link = Path(root).resolve() / 'wp-content/plugins' / plugin
    if link.is_symlink():
        require(str(Path(os.readlink(link))).startswith(str(base) + '/'), 'plugin_name_conflict')
        link.unlink()
    else:
        require(not link.exists(), 'plugin_name_conflict')


def install_local(c, store, plugin, sha, raw):
    base = plugins_base(c)
    # The admin worker runs with UMask=0077, so set modes explicitly: store PHP users must traverse these.
    base.mkdir(mode=0o755, exist_ok=True)
    require(base.is_dir() and not base.is_symlink(), 'plugins_base_unexpected')
    os.chmod(base, 0o755)
    store_dir = base / store['slug']
    store_dir.mkdir(mode=0o755, exist_ok=True)
    no_symlink_components(store_dir)
    os.chmod(store_dir, 0o755)
    target = store_dir / plugin
    name, version = unpack(raw, sha, plugin, target)
    link_into(store['root'], plugin, target, base)
    return name, version


def remove_local(c, store, plugin):
    base = plugins_base(c)
    unlink_from(store['root'], plugin, base)
    target = base / store['slug'] / plugin
    no_symlink_components(target.parent)
    if target.exists():
        require(target.is_dir() and not target.is_symlink(), 'plugin_target_unexpected')
        shutil.rmtree(target)


# ---------- web role (forced-command SSH) ----------

def envelope(request, slug, raw=b''):
    return {'version': 1, 'phase': 'plugin', 'request': request, 'slug': slug,
            'package_b64': base64.b64encode(raw).decode() if raw else ''}


def web_main(c, payload):
    require(c['role'] == 'web' and isinstance(payload, dict)
            and set(payload) == {'version', 'phase', 'request', 'slug', 'package_b64'}
            and payload['version'] == 1 and payload['phase'] == 'plugin', 'web_plugin_shape')
    request = validate_request(payload['request'])
    root = Path(c['web_ledger_root'])
    with lock(private_dir(root / 'plugins') / 'plugins.lock'):
        try:
            store = resolve_web(c, payload['slug'], request['uuid'])
            if request['action'] == 'install':
                raw = base64.b64decode(payload['package_b64'], validate=True)
                name, version = install_local(c, store, request['plugin'], request['package'], raw)
                return {'ok': True, 'name': name, 'version': version, 'error_code': ''}
            remove_local(c, store, request['plugin'])
            return {'ok': True, 'name': '', 'version': '', 'error_code': ''}
        except Exception as exc:
            return {'ok': False, 'name': '', 'version': '', 'error_code': safe_error(exc)}


def remote(c, payload):
    args = ['/usr/bin/ssh', '-i', c['web_ssh_key'], '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o',
            'UserKnownHostsFile=' + c['web_known_hosts'], '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=12', c['web_ssh_user'] + '@' + c['web_host'], 'lifecycle']
    p = subprocess.run(args, input=canonical(payload), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       timeout=420, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent'})
    require(p.returncode == 0, 'remote_plugin_failed')
    response = json.loads(p.stdout)
    require(isinstance(response, dict) and set(response) == {'ok', 'name', 'version', 'error_code'}, 'remote_plugin_shape')
    if not response['ok']:
        code = response['error_code']
        raise ValueError('web_' + code if re.fullmatch(r'[a-z0-9_]{1,76}', str(code)) else 'remote_plugin_failed')
    return response


# ---------- admin role ----------

def wp(store, *args, allowed=(0,)):
    return command(['/usr/local/bin/wp', '--path=' + str(store['root']), '--no-color', *args],
                   user=store['user'], timeout=180, allowed=allowed)


def healthy(store):
    home = wp(store, 'option', 'get', 'home').strip()
    require(re.fullmatch(r'https://[A-Za-z0-9.-]+/?', home), 'store_home_url')
    for path in ('', 'cart/'):
        url = home.rstrip('/') + '/' + path
        req = urllib.request.Request(url, headers={'User-Agent': 'VialCove-PluginCheck/1.0 (+health)'})
        try:
            with urllib.request.urlopen(req, timeout=25) as resp:
                body = resp.read(400000).decode('utf-8', 'replace')
                status = resp.status
        except urllib.error.HTTPError as err:
            status, body = err.code, ''
        if status >= 500 or 'There has been a critical error' in body:
            return False
    return True


def run_job(c, request, package_path=None):
    store = resolve_admin(c, request['uuid'])
    plugin = request['plugin']
    if request['action'] == 'install':
        raw = read_checked(package_path, owners=trusted_uids(c), limit=MAX_PACKAGE)
        inspect_package(raw, request['package'], plugin)  # fail fast before touching any host
        remote(c, envelope(request, store['slug'], raw))
        name, version = install_local(c, store, plugin, request['package'], raw)
        wp(store, 'plugin', 'activate', plugin)
        if not healthy(store):
            wp(store, 'plugin', 'deactivate', plugin, allowed=(0, 1))
            raise ValueError('health_check_failed')
        return name, version
    wp(store, 'plugin', 'deactivate', plugin, '--skip-plugins=' + plugin, allowed=(0, 1))
    remote(c, envelope(request, store['slug']))
    remove_local(c, store, plugin)
    return '', ''


MESSAGES = {
    'health_check_failed': '启用后店铺首页或购物车打不开，已自动停用这个插件。',
    'plugin_package_layout': '压缩包里应只有一个插件文件夹，且文件夹名要和插件标识一致。',
    'plugin_header_missing': '没找到插件主文件（缺少 Plugin Name 说明）。',
    'plugin_name_conflict': '店铺里已有同名插件，不能覆盖。',
    'store_not_found': '没找到这个店铺的服务器信息。',
}


def process_queue(c):
    require(c['role'] == 'admin', 'admin_role')
    base = Path(c['state_root']) / 'plugins'
    if not base.exists():
        base.mkdir(mode=0o711)
        os.chmod(base, 0o711)
    require(base.is_dir() and not base.is_symlink() and base.stat().st_uid == os.geteuid(), 'plugins_root')
    try:
        with lock(base / 'worker.lock', nonblocking=True):
            requests = queue_dir(base / 'requests', c['hq_user'], 0o730)
            packages = queue_dir(base / 'packages', c['hq_user'], 0o730)
            results = queue_dir(base / 'results', c['hq_user'], 0o750)
            gid = grp.getgrnam(c['hq_user']).gr_gid
            processed = 0
            for entry in sorted(requests.glob('*.json')):
                if processed >= 8:
                    break
                try:
                    request = validate_request(json.loads(read_checked(entry, owners=trusted_uids(c), limit=MAX_REQUEST)))
                    require(entry.name == request['operation_id'] + '.json', 'request_filename')
                except Exception:
                    rejected = private_dir(base / 'rejected')
                    if not entry.is_symlink():
                        os.replace(entry, rejected / (secrets.token_hex(8) + '.json'))
                    continue
                receipt = results / entry.name
                if receipt.exists():
                    entry.unlink()
                    continue
                value = {'operation_id': request['operation_id'], 'site_id': request['site_id'], 'uuid': request['uuid'],
                         'action': request['action'], 'plugin': request['plugin'], 'state': 'done', 'version': '',
                         'name': '', 'error_code': '', 'message': '', 'finished_at': 0}
                try:
                    pkg = packages / (request['package'] + '.zip') if request['action'] == 'install' else None
                    value['name'], value['version'] = run_job(c, request, pkg)
                except Exception as exc:
                    code = safe_error(exc)
                    value.update(state='failed', error_code=code,
                                 message=MESSAGES.get(code.removeprefix('web_'), '插件操作没有完成，请查看错误代码。'))
                value['finished_at'] = int(time.time())
                atomic(receipt, canonical(value), mode=0o640, gid=gid)
                entry.unlink()
                processed += 1
            return processed
    except BlockingIOError:
        return 0
