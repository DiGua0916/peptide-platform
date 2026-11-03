#!/usr/bin/python3 -I
"""Root-only tenant retirement broker, using the provisioning SSH channel.

No resource path, SQL identifier or command is accepted from an HQ request.
The protected inventory is deployment evidence, not a discovery heuristic.
See inventory_example() for the exact shape. It must enumerate legacy resources
explicitly. Retention is 72 hours in place: closed vhosts, absent PHP pools,
stopped tenant services, unmounted bridges, revoked bridge keys and locked DB
accounts. Shared platform backups are deliberately outside this manifest.

Integration: call process_queue(c, lock_held=True) while worker.worker.lock is
held; worker's forced-command web 'lifecycle' branch calls web_main(c, payload).
Do not dispatch this through an HTTP endpoint on the tenant being retired.
"""
import contextlib
import fcntl
import grp
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import secrets
import stat
import subprocess
import time
from urllib.parse import urlsplit
import uuid as uuidlib

RETENTION_SECONDS = 72 * 60 * 60
INVENTORY_PATH = '/etc/vialcove-provision/lifecycle-inventory.json'
MAX_JSON = 1024 * 1024
RESULT_FIELDS = {'state', 'site_id', 'uuid', 'retirement_id', 'retired_at',
                 'purge_at', 'error_code', 'message'}
REQUEST_FIELDS = {'version', 'operation_id', 'actor_id', 'site_id', 'uuid',
                  'management_url', 'public_url', 'action', 'retirement_id'}
ROOTS = ('/srv/vialcove/', '/srv/vialcove-data/', '/srv/vialcove-media-export/',
         '/var/lib/vialcove-provision/', '/var/lib/vialcove-provision-web/',
         '/etc/nginx/sites-available/', '/etc/nginx/sites-enabled/',
         '/etc/php/8.3/fpm/pool.d/', '/etc/systemd/system/',
         '/etc/ssh/vialcove-bridge-keys/', '/etc/ssh/sshd_config.d/',
         '/usr/local/libexec/', '/usr/local/sbin/vialcove-', '/usr/local/bin/vialcove-',
         '/var/cache/vialcove-cli/', '/var/lib/vialcove-store-admin/media-key')
PROTECTED = {'/srv/vialcove/current', '/srv/vialcove/store-admin/current',
             '/srv/vialcove-data/tenant-backups', '/srv/vialcove/hq',
             '/srv/vialcove/master', '/srv/vialcove/masters',
             '/srv/vialcove-media-export', '/var/lib/vialcove-provision',
             '/var/lib/vialcove-provision-web'}


def require(value, code):
    if not value:
        raise ValueError(code)


def digest(value):
    return hashlib.sha256(value).hexdigest()


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True,
                      separators=(',', ':')).encode('utf-8')


def valid_uuid(value, code='uuid', version=None):
    require(isinstance(value, str), code)
    try:
        parsed = uuidlib.UUID(value)
    except (ValueError, AttributeError):
        raise ValueError(code)
    require(str(parsed) == value and (version is None or parsed.version == version), code)
    return value


def valid_url(value):
    require(isinstance(value, str) and len(value) <= 253 and not re.search(r'[\s\x00-\x1f]', value), 'url')
    parsed = urlsplit(value)
    require(parsed.scheme == 'https' and parsed.netloc == parsed.hostname
            and re.fullmatch(r'[a-z0-9][a-z0-9.-]*\.[a-z]{2,63}', parsed.hostname or '')
            and parsed.path in {'', '/'} and not parsed.query and not parsed.fragment, 'url')
    return value.rstrip('/')


def validate_request(request):
    require(isinstance(request, dict) and set(request) == REQUEST_FIELDS, 'request_shape')
    require(type(request['version']) is int and request['version'] == 1, 'request_version')
    for key in ('actor_id', 'site_id'):
        require(type(request[key]) is int and request[key] > 0, key)
    valid_uuid(request['operation_id'], 'operation_id', 4)
    valid_uuid(request['uuid'])
    valid_uuid(request['retirement_id'], 'retirement_id', 4)
    require(request['action'] in {'retire', 'restore'}, 'action')
    clean = dict(request)
    for key in ('management_url', 'public_url'):
        clean[key] = valid_url(clean[key])
    return clean


def private_dir(path):
    path = Path(path)
    require(not path.is_symlink(), 'private_directory_symlink')
    path.mkdir(parents=True, exist_ok=True, mode=0o700)
    st = path.stat()
    require(stat.S_ISDIR(st.st_mode) and st.st_uid == 0 and not st.st_mode & 0o077,
            'private_directory_permissions')
    return path


def queue_dir(path, group, mode):
    path = Path(path)
    no_symlink_components(path)
    gid = grp.getgrnam(group).gr_gid
    if not path.exists():
        path.mkdir(mode=mode)
        os.chown(path, 0, gid)
        os.chmod(path, mode)
    st = path.stat()
    require(stat.S_ISDIR(st.st_mode) and st.st_uid == 0 and st.st_gid == gid
            and stat.S_IMODE(st.st_mode) == mode, 'queue_directory_permissions')
    return path


def checked_file(path, *, owners=(0,), limit=MAX_JSON, private=False):
    path = Path(path)
    no_symlink_components(path, allow_leaf=False)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        st = os.fstat(stream.fileno())
        require(stat.S_ISREG(st.st_mode) and st.st_nlink == 1 and st.st_uid in owners
                and not st.st_mode & (0o077 if private else 0o022) and st.st_size <= limit,
                'protected_file_permissions')
        value = stream.read(limit + 1)
        require(len(value) <= limit, 'protected_file_size')
        return value


def load_json(path, **kwargs):
    return json.loads(checked_file(path, **kwargs))


def atomic(path, value, *, mode=0o600, uid=0, gid=0):
    path = Path(path)
    require(not path.is_symlink(), 'write_symlink')
    no_symlink_components(path.parent)
    tmp = path.with_name(path.name + '.next-' + secrets.token_hex(8))
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(value)
            stream.flush()
            os.fchmod(stream.fileno(), mode)
            os.fchown(stream.fileno(), uid, gid)
            os.fsync(stream.fileno())
        os.replace(tmp, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if tmp.exists():
            tmp.unlink()


def save_json(path, value, *, group=None):
    atomic(path, canonical(value), mode=0o640 if group else 0o600,
           gid=grp.getgrnam(group).gr_gid if group else 0)


def no_symlink_components(path, allow_leaf=False):
    path = Path(path)
    require(path.is_absolute() and '..' not in path.parts, 'absolute_path')
    parts = path.parts[:-1] if allow_leaf else path.parts
    current = Path(parts[0])
    for part in parts[1:]:
        current = current / part
        require(not current.is_symlink(), 'path_component_symlink')


def owned_path(path, *, legacy=False):
    require(isinstance(path, str) and path.startswith('/') and not re.search(r'[\x00-\x20]', path)
            and str(PurePosixPath(path)) == path and '..' not in PurePosixPath(path).parts, 'resource_path')
    require(any(path.startswith(root) for root in ROOTS), 'resource_root')
    require(path not in PROTECTED or legacy and path in {'/srv/vialcove/current',
            '/srv/vialcove/store-admin/current'}, 'shared_resource')
    for forbidden in ('/srv/vialcove/hq/', '/srv/vialcove/master/', '/srv/vialcove/masters/',
                      '/srv/vialcove-data/tenant-backups/'):
        require(not path.startswith(forbidden), 'shared_resource')
    require(not path.endswith(('/config.json', '/worker.lock', '/create.lock',
            '/web-command-key', '/web-known-hosts', '/db-key', '/known_hosts')) and '/domain-claims/' not in path,
            'shared_resource')
    if path.startswith('/etc/systemd/system/'):
        require(tenant_unit(PurePosixPath(path).name), 'shared_unit')
    if path.startswith(('/etc/nginx/sites-available/', '/etc/nginx/sites-enabled/',
                        '/etc/php/8.3/fpm/pool.d/')):
        require('hq' not in PurePosixPath(path).name, 'shared_resource')
    require(not re.search(r'/vialcove-(?:provision|storage-guard|tenant-backup|tenant-monitor|hq)(?:[-.]|$)', path),
            'shared_resource')
    return Path(path)


def tenant_unit(unit):
    # s0001-db is a SHARED tunnel used by every store, despite its old name.
    return isinstance(unit, str) and bool(re.fullmatch(
        r'vialcove-(?:s[0-9]{4}-(?:uploads|reports|cron|monitor|export)|export-s[0-9]{4}|'
        r'web01-(?:cron|backup)|store-admin-backup)\.(?:service|timer)', unit))


def inventory_example():
    role = {'paths': [{'path': '/srv/vialcove/tenant-admin/s0002', 'kind': 'directory'}],
            'nginx': [{'available': '/etc/nginx/sites-available/vialcove-s0002',
                       'enabled': '/etc/nginx/sites-enabled/vialcove-s0002',
                       'sha256': '0' * 64, 'hosts': ['s0002.admin.example.net']}],
            'fpm': [{'path': '/etc/php/8.3/fpm/pool.d/vialcove-s0002.conf',
                     'sha256': '0' * 64, 'unit': 'php8.3-fpm'}],
            'units': ['vialcove-s0002-uploads.service', 'vialcove-s0002-reports.service'],
            'mounts': ['/srv/vialcove/tenant-admin/s0002/shared/uploads',
                       '/srv/vialcove/tenant-admin/s0002/shared/reports'],
            'revocable_keys': [],
            'identity_files': [{'path': '/srv/vialcove/tenant-admin/s0002/shared/wp-config.php'}],
            'users': ['vcs0002']}
    return {'version': 1, 'sites': [{'site_id': 3,
        'uuid': '00000000-0000-4000-8000-000000000002', 'slug': 's0002',
        'management_url': 'https://s0002.admin.example.net',
        'public_url': 'https://www.example.com', 'template_dependencies': [],
        'admin': role,
        'web': {**role, 'paths': [{'path': '/srv/vialcove/tenants/s0002', 'kind': 'directory'},
                                 {'path': '/srv/vialcove-data/tenants/s0002', 'kind': 'directory'}],
                'nginx': [{'available': '/etc/nginx/sites-available/vialcove-s0002',
                           'enabled': '/etc/nginx/sites-enabled/vialcove-s0002',
                           'sha256': '0' * 64, 'hosts': ['www.example.com', 'example.com']}],
                'units': ['vialcove-s0002-cron.timer', 'vialcove-s0002-cron.service',
                          'vialcove-export-s0002.service'],
                'mounts': ['/srv/vialcove-media-export/s0002/uploads',
                           '/srv/vialcove-media-export/s0002/reports'],
                'revocable_keys': [{'path': '/etc/ssh/vialcove-bridge-keys/vcm_s0002',
                                    'sha256': '0' * 64}],
                'identity_files': [{'path': '/srv/vialcove-data/tenants/s0002/wp-config.php'}],
                'users': ['vcs0002', 'vcm_s0002'],
                'db': {'name': 'vc_s0002', 'accounts': [
                    {'user': 'vcf_s0002', 'host': 'localhost'},
                    {'user': 'vca_s0002', 'host': '127.0.0.1'}]}}}]}


def validate_inventory(data):
    require(isinstance(data, dict) and set(data) == {'version', 'sites'} and data['version'] == 1
            and isinstance(data['sites'], list) and 0 < len(data['sites']) <= 10000, 'inventory_shape')
    seen = set()
    resources = {'admin': {}, 'web': {}}
    exclusive = {'admin': {}, 'web': {}}
    for site in data['sites']:
        site_fields = {'site_id', 'uuid', 'slug', 'management_url', 'public_url', 'template_dependencies'}
        require(isinstance(site, dict) and site_fields <= set(site)
                and set(site) <= site_fields | {'admin', 'web'}
                and bool(set(site) & {'admin', 'web'}), 'inventory_site')
        valid_uuid(site['uuid'])
        require(type(site['site_id']) is int and site['site_id'] > 0
                and re.fullmatch(r's[0-9]{4}', site['slug']) and 1 <= int(site['slug'][1:]) <= 9999,
                'inventory_identity')
        identity = (site['site_id'], site['uuid'], site['slug'])
        require(not any(any(a == b for a, b in zip(identity, other)) for other in seen), 'inventory_duplicate_identity')
        seen.add(identity)
        for key in ('management_url', 'public_url'):
            require(valid_url(site[key]) == site[key], 'inventory_url')
        require(isinstance(site['template_dependencies'], list)
                and all(isinstance(p, str) and p.startswith('/') for p in site['template_dependencies']),
                'inventory_master_dependencies')
        for role in ('admin', 'web'):
            if role not in site:
                continue
            part = site[role]
            expected = {'paths', 'nginx', 'fpm', 'units', 'mounts', 'revocable_keys', 'identity_files', 'users'}
            require(isinstance(part, dict) and set(part) == expected | ({'db'} if role == 'web' else set()),
                    'inventory_role')
            require(all(isinstance(part[k], list) for k in expected), 'inventory_lists')
            require(part['paths'] and part['nginx'] and part['fpm'] and part['identity_files']
                    and part['users'] and len(part['paths']) <= 128, 'inventory_incomplete')
            all_paths = []
            for item in part['paths']:
                require(set(item) == {'path', 'kind'} and item['kind'] in {'file', 'directory', 'symlink'}, 'inventory_path_kind')
                all_paths.append(item['path'])
            for item in part['nginx']:
                require(set(item) == {'available', 'enabled', 'sha256', 'hosts'}
                        and re.fullmatch(r'[a-f0-9]{64}', item['sha256']) and item['hosts']
                        and all(re.fullmatch(r'[a-z0-9][a-z0-9.-]*\.[a-z]{2,63}', h) for h in item['hosts']),
                        'inventory_nginx')
                require(item['available'].startswith('/etc/nginx/sites-available/')
                        and item['enabled'].startswith('/etc/nginx/sites-enabled/'), 'inventory_nginx_path')
                all_paths.extend([item['available'], item['enabled']])
            for item in part['fpm']:
                require(set(item) == {'path', 'sha256', 'unit'} and item['path'].startswith('/etc/php/8.3/fpm/pool.d/')
                        and re.fullmatch(r'[a-f0-9]{64}', item['sha256']) and item['unit'] == 'php8.3-fpm', 'inventory_fpm')
                all_paths.append(item['path'])
            for item in part['revocable_keys']:
                require(set(item) == {'path', 'sha256'} and item['path'].startswith('/etc/ssh/vialcove-bridge-keys/')
                        and re.fullmatch(r'[a-f0-9]{64}', item['sha256']), 'inventory_key')
                all_paths.append(item['path'])
            for item in part['identity_files']:
                require(set(item) == {'path'}, 'inventory_identity_file')
                owned_path(item['path'], legacy=site['slug'] == 's0001')
            for path in all_paths + part['mounts']:
                owned_path(path, legacy=site['slug'] == 's0001')
                prior = resources[role].get(path)
                require(prior is None or prior == site['uuid'], 'inventory_shared_path')
                resources[role][path] = site['uuid']
            for unit in part['units']:
                require(tenant_unit(unit), 'shared_unit')
                require(('unit', unit) not in exclusive[role], 'inventory_shared_unit')
                exclusive[role][('unit', unit)] = site['uuid']
            for user in part['users']:
                allowed_users = {'vc' + site['slug'], 'vcm_' + site['slug']}
                if site['slug'] == 's0001': allowed_users.add('vialweb01')
                require(user in allowed_users, 'inventory_user')
                require(('user', user) not in exclusive[role], 'inventory_shared_user')
                exclusive[role][('user', user)] = site['uuid']
            if role == 'web':
                database = part['db']
                require(set(database) == {'name', 'accounts'} and re.fullmatch(r'[a-z][a-z0-9_]{1,63}', database['name'])
                        and database['name'] not in {'mysql', 'sys', 'performance_schema', 'information_schema', 'vialcove_hq'}, 'inventory_database')
                require(isinstance(database['accounts'], list) and 1 <= len(database['accounts']) <= 4, 'inventory_accounts')
                require(('db', database['name']) not in exclusive[role], 'inventory_shared_database')
                exclusive[role][('db', database['name'])] = site['uuid']
                for account in database['accounts']:
                    require(set(account) == {'user', 'host'} and re.fullmatch(r'[a-z][a-z0-9_]{1,31}', account['user'])
                            and account['user'] not in {'root', 'mysql', 'vchq'}
                            and account['host'] in {'localhost', '127.0.0.1'}, 'inventory_account')
                    require(('account', account['user'], account['host']) not in exclusive[role], 'inventory_shared_account')
                    exclusive[role][('account', account['user'], account['host'])] = site['uuid']
    # Parent/child resources owned by different tenants are equally unsafe.
    for role in resources:
        values = sorted(resources[role].items())
        for index, (path, owner) in enumerate(values):
            require(not any(other.startswith(path.rstrip('/') + '/') and owner != other_owner
                            for other, other_owner in values[index + 1:]), 'inventory_nested_shared_path')
    return data


def find_site(c, request):
    data = validate_inventory(load_json(c.get('lifecycle_inventory', INVENTORY_PATH), private=True))
    matches = [site for site in data['sites'] if site['uuid'] == request['uuid']]
    require(len(matches) == 1, 'inventory_site_missing')
    site = matches[0]
    require(c['role'] in site, 'inventory_local_role_missing')
    require(all(site[key] == request[key] for key in ('site_id', 'uuid', 'management_url', 'public_url')),
            'inventory_identity_changed')
    return site


def command(args, *, data=None, timeout=90, allowed=(0,)):
    result = subprocess.run(args, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=timeout, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent'})
    require(result.returncode in allowed, 'command_failed_' + Path(args[0]).name)
    return result.stdout


@contextlib.contextmanager
def lock(path, *, nonblocking=False):
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'a') as stream:
        st = os.fstat(stream.fileno())
        require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1
                and not st.st_mode & 0o077, 'lifecycle_lock')
        fcntl.flock(stream, fcntl.LOCK_EX | (fcntl.LOCK_NB if nonblocking else 0))
        yield


@contextlib.contextmanager
def operation_locks(c):
    """Web lock order: create.lock (caller) -> nginx -> backup, never HQ.

    Admin deployment locks are held by worker's caller, outside this module.
    Nonblocking acquisition prevents a timed-out SSH request from subsequently
    making a delayed destructive mutation after its coordinator has given up.
    """
    require(c['role'] == 'web', 'web_lock_role')
    deployment = Path('/var/lib/vialcove-deploy')
    no_symlink_components(deployment)
    if not deployment.exists():
        deployment.mkdir(mode=0o700)
    require(deployment.stat().st_uid == 0 and not deployment.stat().st_mode & 0o022,
            'deployment_lock_directory')
    backup = Path('/srv/vialcove-data/tenant-backups')
    no_symlink_components(backup)
    require(backup.is_dir() and backup.stat().st_uid == 0 and not backup.stat().st_mode & 0o077,
            'backup_lock_directory')
    with contextlib.ExitStack() as stack:
        for path, code in ((deployment / 'nginx-update.lock', 'deployment_busy'),
                           (backup / 'backup.lock', 'backup_busy')):
            try:
                stack.enter_context(lock(path, nonblocking=True))
            except BlockingIOError:
                raise ValueError(code)
        yield


def state_root(c):
    root = Path(c['state_root'] if c['role'] == 'admin' else c['web_ledger_root'])
    if c['role'] == 'admin':
        return queue_dir(root / 'lifecycle', c['hq_user'], 0o710)
    return private_dir(root / 'lifecycle')


def tenant_state(c, request):
    root = private_dir(state_root(c) / 'tenants')
    return private_dir(root / request['uuid'])


def result(request, state, *, retired_at=0, purge_at=0, error_code='', message=''):
    return {'state': state, 'site_id': request['site_id'], 'uuid': request['uuid'],
            'retirement_id': request['retirement_id'], 'retired_at': retired_at,
            'purge_at': purge_at, 'error_code': error_code, 'message': message}


def safe_error(exc):
    code = str(exc) if isinstance(exc, ValueError) else type(exc).__name__
    code = code.lower()
    return code if re.fullmatch(r'[a-z0-9_]{1,80}', code) else 'lifecycle_failed'


def current(c, request):
    path = tenant_state(c, request) / 'current.json'
    if not path.exists():
        return None
    value = load_json(path, private=True)
    require(value['uuid'] == request['uuid'] and value['site_id'] == request['site_id'], 'state_identity')
    return value


def store_state(c, request, value):
    save_json(tenant_state(c, request) / 'current.json', value)


def mount_points():
    text = Path('/proc/self/mountinfo').read_text(encoding='utf-8')
    return {re.sub(r'\\([0-7]{3})', lambda m: chr(int(m[1], 8)), line.split()[4])
            for line in text.splitlines() if len(line.split()) > 4}


def no_mount_below(path, mounts=None):
    value = str(path).rstrip('/')
    mounts = mount_points() if mounts is None else mounts
    require(not any(m == value or m.startswith(value + '/') for m in mounts), 'resource_still_mounted')


def nginx_quarantine(raw, owned_hosts):
    """Insert 410 after existing server rewrite guards, retaining other bytes.

    A shared default TLS reject block may remain. Any other unowned server name
    makes the entire file ineligible. Tokenizer ignores braces inside quotes and
    comments; this is deliberately narrower than an arbitrary nginx AST editor.
    """
    text = raw.decode('utf-8')
    tokens = list(re.finditer(r'#[^\n]*|"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|[{};]|[^\s{};#]+', text))
    depth, start, blocks = 0, None, []
    previous = None
    for token in tokens:
        value = token[0]
        if value.startswith('#'):
            continue
        if value == '{':
            if previous and previous[0] == 'server' and depth == 0:
                start = token.end()
            depth += 1
        elif value == '}':
            depth -= 1
            require(depth >= 0, 'nginx_unbalanced')
            if depth == 0 and start is not None:
                blocks.append((start, token.start()))
                start = None
        previous = token
    require(depth == 0 and blocks, 'nginx_no_servers')
    seen, inserts = set(), []
    for begin, end in blocks:
        body = text[begin:end]
        clean = re.sub(r'#[^\n]*', '', body)
        names = re.findall(r'(?<![$\w])server_name\s+([^;]+);', clean)
        require(len(names) == 1, 'nginx_server_names')
        hosts = set(names[0].split())
        if hosts == {'_'} and (re.search(r'\bssl_reject_handshake\s+on\s*;', clean)
                or re.search(r'\breturn\s+444\s*;', clean) and not re.search(r'\b(?:root|alias|fastcgi_pass|proxy_pass|location)\b', clean)):
            continue
        require(hosts and hosts <= set(owned_hosts), 'nginx_shared_server')
        seen |= hosts
        require('vialcove-lifecycle-closed' not in body, 'nginx_already_closed')
        inserts.append(end)
    require(set(owned_hosts) <= seen, 'nginx_owned_hosts_missing')
    for index in reversed(inserts):
        text = text[:index] + '\n    # vialcove-lifecycle-closed\n    return 410;\n' + text[index:]
    return text.encode('utf-8')


def check_master(c, site, role):
    owned = [item['path'] for item in site[role]['paths']]
    source_keys = ('source_admin_root',) if role == 'admin' else ('source_web_root', 'source_uploads')
    sources = [c[key] for key in source_keys if c.get(key)] + site['template_dependencies']
    for source in sources:
        for path in owned:
            resolved_source, resolved_path = str(Path(source).resolve()), str(Path(path).resolve())
            require(not any(a == b or a.startswith(b.rstrip('/') + '/') or b.startswith(a.rstrip('/') + '/')
                            for a, b in ((source, path), (resolved_source, resolved_path))), 'master_in_use')


def snapshot_path(c, request):
    return private_dir(tenant_state(c, request) / request['retirement_id'])


def preflight(c, request, site, *, restoring=False):
    role = c['role']
    resources = site[role]
    check_master(c, site, role)
    for identity in resources['identity_files']:
        raw = checked_file(identity['path'], limit=1024 * 1024)
        require(request['uuid'].encode() in raw, 'identity_file_uuid_missing')
    for item in resources['paths']:
        path = owned_path(item['path'], legacy=site['slug'] == 's0001')
        no_symlink_components(path, allow_leaf=item['kind'] == 'symlink')
        st = path.lstat()
        require((item['kind'] == 'directory' and stat.S_ISDIR(st.st_mode))
                or (item['kind'] == 'file' and stat.S_ISREG(st.st_mode))
                or (item['kind'] == 'symlink' and stat.S_ISLNK(st.st_mode)), 'resource_kind_changed')
    if not restoring:
        for item in resources['nginx']:
            raw = checked_file(item['available'])
            require(digest(raw) == item['sha256'], 'nginx_baseline_changed')
            nginx_quarantine(raw, item['hosts'])
            link = Path(item['enabled'])
            require(link.is_symlink() and link.resolve() == Path(item['available']).resolve(), 'nginx_enabled_ownership')
        for item in resources['fpm'] + resources['revocable_keys']:
            require(digest(checked_file(item['path'])) == item['sha256'], 'resource_baseline_changed')
        for item in resources['fpm']:
            pool = checked_file(item['path']).decode('utf-8')
            names = re.findall(r'^\s*\[([^\]]+)\]', pool, re.M)
            users = re.findall(r'^\s*user\s*=\s*(\S+)', pool, re.M)
            require(names and set(names) <= set(resources['users']) and users
                    and set(users) <= set(resources['users']), 'fpm_pool_shared')
    for unit in resources['units']:
        # unit files must not be aliases to another tenant/shared service.
        path = Path('/etc/systemd/system') / unit
        require(path.is_file() and not path.is_symlink(), 'unit_ownership')
        checked_file(path)
    if role == 'web':
        database_check(resources['db'])
    return True


def mysql(sql):
    return command(['/usr/bin/mysql', '--batch', '--skip-column-names'], data=sql.encode('ascii'), timeout=90).decode('ascii').strip()


def account_sql(account):
    return "'" + account['user'] + "'@'" + account['host'] + "'"


def database_check(database):
    name = database['name']
    require(mysql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + name + "';") == '1', 'database_missing')
    expected = {(item['user'], item['host']) for item in database['accounts']}
    for account in database['accounts']:
        require(mysql("SELECT COUNT(*) FROM mysql.user WHERE User='" + account['user'] + "' AND Host='" + account['host'] + "';") == '1', 'database_account_missing')
        grants = mysql('SHOW GRANTS FOR ' + account_sql(account) + ';')
        for grant in grants.splitlines():
            # Never write grant text: older engines may include credential hashes.
            require(bool(re.match(r'GRANT USAGE ON \*\.\* TO ', grant))
                    or bool(re.match(r'GRANT [A-Z_, ()]+ ON `' + re.escape(name) + r'`\.\* TO ', grant)), 'database_account_shared')
    direct = mysql("SELECT User,Host FROM mysql.db WHERE Db='" + name + "';")
    observed = {tuple(line.split('\t')) for line in direct.splitlines() if line}
    require(observed <= expected, 'database_shared_grants')


def database_lock(database, locked):
    for account in database['accounts']:
        mysql('ALTER USER ' + account_sql(account) + (' ACCOUNT LOCK;' if locked else ' ACCOUNT UNLOCK;'))
    if locked:
        users = ','.join("'" + item['user'] + "'" for item in database['accounts'])
        rows = mysql('SELECT ID,USER FROM information_schema.PROCESSLIST WHERE USER IN (' + users + ');')
        for line in rows.splitlines():
            values = line.split('\t')
            require(len(values) == 2 and values[0].isdigit()
                    and values[1] in {item['user'] for item in database['accounts']}, 'database_session_identity')
            mysql('KILL CONNECTION ' + values[0] + ';')


def backup_file(snapshot, path, metadata):
    key = digest(str(path).encode())
    saved = snapshot / (key + '.bin')
    if key not in metadata:
        raw = checked_file(path)
        st = Path(path).stat()
        atomic(saved, raw)
        metadata[key] = {'path': str(path), 'sha256': digest(raw), 'uid': st.st_uid,
                         'gid': st.st_gid, 'mode': stat.S_IMODE(st.st_mode), 'backup': saved.name}
        save_json(snapshot / 'files.json', metadata)
    else:
        require(digest(checked_file(saved, private=True)) == metadata[key]['sha256'], 'checkpoint_changed')
    return metadata[key]


def saved_files(snapshot):
    path = snapshot / 'files.json'
    return load_json(path, private=True) if path.exists() else {}


def retire_local(c, request, site, retired_at, purge_at):
    role, resources = c['role'], site[c['role']]
    existing = current(c, request)
    if existing and existing['state'] == 'active' and existing['retirement_id'] != request['retirement_id']:
        # Both hosts, not only the admin coordinator, must allow a new cycle
        # after a fully restored previous retirement.
        existing = None
    if existing:
        require(existing['retirement_id'] == request['retirement_id'], 'different_retirement_active')
        require(existing['state'] != 'purged', 'already_purged')
        if existing['state'] == 'retained':
            return existing
    else:
        preflight(c, request, site)
    value = result(request, 'offlining', retired_at=retired_at, purge_at=purge_at)
    store_state(c, request, value)
    snapshot = snapshot_path(c, request)
    original = tenant_state(c, request) / (request['retirement_id'] + '.request.json')
    if not original.exists():
        save_json(original, request)
    else:
        before = validate_request(load_json(original, private=True))
        require(all(before[key] == request[key] for key in ('site_id', 'uuid', 'management_url',
                'public_url', 'retirement_id')), 'retirement_request_changed')
    metadata = saved_files(snapshot)
    for item in resources['nginx']:
        if digest(item['available'].encode()) not in metadata:
            require(digest(checked_file(item['available'])) == item['sha256'], 'nginx_baseline_changed')
        record = backup_file(snapshot, item['available'], metadata)
        raw = checked_file(snapshot / record['backup'], private=True)
        closed = nginx_quarantine(raw, item['hosts'])
        current_raw = checked_file(item['available'])
        require(digest(current_raw) in {record['sha256'], digest(closed)}, 'nginx_lifecycle_conflict')
        atomic(item['available'], closed, mode=record['mode'], uid=record['uid'], gid=record['gid'])
    command(['/usr/sbin/nginx', '-t'])
    command(['/bin/systemctl', 'reload', 'nginx'])
    # No rollback to live on a subsequent failure: HQ has already closed writes.
    enabled_path = snapshot / 'enabled-units.json'
    if not enabled_path.exists():
        enabled = {unit: command(['/bin/systemctl', 'is-enabled', unit], allowed=(0, 1, 3, 4)).decode().strip()
                   for unit in resources['units']}
        save_json(enabled_path, enabled)
    for unit in resources['units']:
        if unit.endswith('.timer'):
            command(['/bin/systemctl', 'disable', '--now', unit])
    for unit in resources['units']:
        if unit.endswith('-backup.service'):
            active = command(['/bin/systemctl', 'is-active', unit], allowed=(0, 3, 4)).decode().strip()
            require(active not in {'active', 'activating', 'deactivating', 'reloading'}, 'backup_busy')
    for unit in resources['units']:
        if unit.endswith('.timer'):
            continue
        command(['/bin/systemctl', 'disable', '--now', unit])
    for mount in sorted(resources['mounts'], key=len, reverse=True):
        no_symlink_components(mount)
        if mount in mount_points():
            command(['/bin/umount', mount])
        require(mount not in mount_points(), 'bridge_unmount_failed')
    for item in resources['fpm'] + resources['revocable_keys']:
        path = Path(item['path'])
        if path.exists():
            if digest(str(path).encode()) not in metadata:
                require(digest(checked_file(path)) == item['sha256'], 'resource_baseline_changed')
            record = backup_file(snapshot, path, metadata)
            require(digest(checked_file(path)) == record['sha256'], 'resource_lifecycle_conflict')
            path.unlink()
        else:
            require(digest(str(path).encode()) in metadata, 'disabled_resource_checkpoint_missing')
    command(['/usr/sbin/php-fpm8.3', '-t'])
    command(['/bin/systemctl', 'reload', 'php8.3-fpm'])
    if role == 'web':
        database_lock(resources['db'], True)
    # Every retained directory must be free of active or nested mounts.
    for item in resources['paths']:
        no_mount_below(item['path'])
    value['state'] = 'retained'
    store_state(c, request, value)
    return value


def restore_local(c, request, site, *, prepare_only=False):
    value = current(c, request)
    require(value and value['retirement_id'] == request['retirement_id'], 'retirement_not_found')
    require(value['state'] in {'retained', 'failed', 'restoring', 'active'}, 'restore_state')
    require(value['state'] == 'active' or time.time() < value['purge_at'], 'retention_expired')
    if value['state'] == 'active':
        return value
    preflight(c, request, site, restoring=True)
    value['state'] = 'restoring'
    store_state(c, request, value)
    snapshot = snapshot_path(c, request)
    files = saved_files(snapshot)
    resources = site[c['role']]
    # PHP/bridge resources first; public routing remains 410 until ready.
    for item in resources['fpm'] + resources['revocable_keys']:
        key = digest(item['path'].encode())
        require(key in files, 'restore_checkpoint_missing')
        record = files[key]
        raw = checked_file(snapshot / record['backup'], private=True)
        require(digest(raw) == record['sha256'], 'restore_checkpoint_changed')
        if Path(item['path']).exists():
            require(digest(checked_file(item['path'])) == record['sha256'], 'restore_resource_conflict')
        else:
            atomic(item['path'], raw, mode=record['mode'], uid=record['uid'], gid=record['gid'])
    command(['/usr/sbin/php-fpm8.3', '-t'])
    command(['/bin/systemctl', 'reload', 'php8.3-fpm'])
    if c['role'] == 'web':
        database_lock(resources['db'], False)
    enabled = load_json(snapshot / 'enabled-units.json', private=True)
    for unit in reversed(resources['units']):
        if enabled.get(unit) in {'enabled', 'enabled-runtime', 'linked', 'linked-runtime'}:
            command(['/bin/systemctl', 'enable', '--now', unit])
    # Parent coordinator calls admin restore only after web export and DB ready.
    for mount in resources['mounts']:
        require(mount in mount_points(), 'restore_bridge_not_ready')
    if prepare_only:
        return value
    for item in resources['nginx']:
        record = files.get(digest(item['available'].encode()))
        require(record is not None, 'restore_nginx_checkpoint_missing')
        raw = checked_file(snapshot / record['backup'], private=True)
        closed = nginx_quarantine(raw, item['hosts'])
        require(digest(checked_file(item['available'])) in {digest(closed), record['sha256']}, 'restore_nginx_conflict')
        atomic(item['available'], raw, mode=record['mode'], uid=record['uid'], gid=record['gid'])
    command(['/usr/sbin/nginx', '-t'])
    command(['/bin/systemctl', 'reload', 'nginx'])
    value['state'], value['error_code'], value['message'] = 'active', '', ''
    store_state(c, request, value)
    return value


def remove_directory(path):
    """Descriptor-relative unlink: never follow symlinks or cross a filesystem."""
    path = Path(path)
    no_symlink_components(path)
    no_mount_below(path)
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    root = os.open(path, flags)
    device = os.fstat(root).st_dev
    def walk(fd):
        for name in os.listdir(fd):
            st = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISDIR(st.st_mode):
                require(st.st_dev == device, 'purge_device_changed')
                child = os.open(name, flags, dir_fd=fd)
                try:
                    require(os.fstat(child).st_ino == st.st_ino, 'purge_directory_race')
                    walk(child)
                finally:
                    os.close(child)
                os.rmdir(name, dir_fd=fd)
            else:
                require(stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode)
                        or stat.S_ISFIFO(st.st_mode) or stat.S_ISSOCK(st.st_mode), 'purge_special_file')
                os.unlink(name, dir_fd=fd)
    try:
        walk(root)
    finally:
        os.close(root)
    path.rmdir()


def purge_ownership(snapshot, c, request, site):
    """Fresh identity proof before first unlink, then resumable inode proof.

    Deleted resources may be absent on retry; a resource replaced at the same
    string path must not inherit another tenant's deletion authorization.
    """
    resources = site[c['role']]
    journal = snapshot / 'purge-ownership.json'
    binding = digest(canonical({'uuid': request['uuid'], 'retirement_id': request['retirement_id'],
                                'resources': resources}))
    previous = load_json(journal, private=True) if journal.exists() else None
    if previous:
        require(previous['binding'] == binding, 'purge_inventory_changed')
    for identity in resources['identity_files']:
        path = Path(identity['path'])
        if path.exists():
            require(request['uuid'].encode() in checked_file(path), 'purge_identity_changed')
        else:
            require(previous is not None, 'purge_identity_missing')
    identities = {}
    for item in resources['paths']:
        path = Path(item['path'])
        no_symlink_components(path, allow_leaf=item['kind'] == 'symlink')
        no_mount_below(path)
        if not path.exists() and not path.is_symlink():
            require(previous is not None and item['path'] in previous['paths'], 'purge_resource_missing')
            continue
        node = path.lstat()
        kind = 'directory' if stat.S_ISDIR(node.st_mode) else 'symlink' if stat.S_ISLNK(node.st_mode) else 'file' if stat.S_ISREG(node.st_mode) else 'other'
        require(kind == item['kind'], 'purge_kind_changed')
        identity = {'device': node.st_dev, 'inode': node.st_ino, 'kind': kind}
        if previous:
            require(previous['paths'].get(item['path']) == identity, 'purge_resource_replaced')
        identities[item['path']] = identity
    if not previous:
        save_json(journal, {'binding': binding, 'paths': identities})


def purge_local(c, request, site, now):
    value = current(c, request)
    require(value and value['retirement_id'] == request['retirement_id'], 'retirement_not_found')
    if value['state'] == 'purged':
        # A crash after the tombstone commit may have left only private config
        # checkpoint files. This retry removes those without resurrecting state.
        remaining = tenant_state(c, request) / request['retirement_id']
        if remaining.exists():
            try:
                remove_directory(remaining)
            except Exception:
                value['message'] = '店铺运行数据已清理，私有配置检查点还需人工清理。'
                store_state(c, request, value)
        return value
    require(value['state'] in {'retained', 'purging'} and now >= value['purge_at']
            and value['purge_at'] == value['retired_at'] + RETENTION_SECONDS, 'purge_not_due')
    resources = site[c['role']]
    check_master(c, site, c['role'])
    # Ensure the closed config has not been replaced/reassigned since retirement.
    snapshot = snapshot_path(c, request)
    files = saved_files(snapshot)
    for item in resources['nginx']:
        record = files[digest(item['available'].encode())]
        original = checked_file(snapshot / record['backup'], private=True)
        require(digest(checked_file(item['available'])) == digest(nginx_quarantine(original, item['hosts'])),
                'purge_vhost_not_closed')
    for item in resources['paths']:
        path = owned_path(item['path'], legacy=site['slug'] == 's0001')
        no_symlink_components(path, allow_leaf=item['kind'] == 'symlink')
        no_mount_below(path)
    purge_ownership(snapshot, c, request, site)
    value['state'] = 'purging'
    store_state(c, request, value)
    if c['role'] == 'web':
        database = resources['db']
        # Idempotent SQL, identifiers originate only from the root manifest.
        mysql('DROP DATABASE IF EXISTS `' + database['name'] + '`;')
        for account in database['accounts']:
            mysql('DROP USER IF EXISTS ' + account_sql(account) + ';')
    # Longest first handles explicitly listed nested resources safely.
    for item in sorted(resources['paths'], key=lambda i: len(i['path']), reverse=True):
        path = Path(item['path'])
        if not path.exists() and not path.is_symlink():
            continue
        if item['kind'] == 'directory':
            require(path.is_dir() and not path.is_symlink(), 'purge_kind_changed')
            remove_directory(path)
        else:
            require(path.is_symlink() if item['kind'] == 'symlink' else path.is_file(), 'purge_kind_changed')
            path.unlink()
    # Keep 410 vhost and immutable tombstone: no domain can fall into another app.
    # FPM config / bridge key originals were removed during retirement. Service
    # files are exact tenant units and can now be removed; shared daemon stays.
    for unit in resources['units']:
        path = Path('/etc/systemd/system') / unit
        require(not path.is_symlink(), 'purge_unit_symlink')
        path.unlink(missing_ok=True)
    command(['/bin/systemctl', 'daemon-reload'])
    for username in resources['users']:
        # Never remove user homes, or files outside the explicit manifest.
        exists = command(['/usr/bin/getent', 'passwd', username], allowed=(0, 2))
        if exists:
            require(command(['/usr/bin/pgrep', '-u', username], allowed=(0, 1)).strip() == b'', 'purge_user_has_processes')
            command(['/usr/sbin/userdel', username])
    value['state'], value['error_code'], value['message'] = 'purged', '', ''
    store_state(c, request, value)
    # Commit tombstone BEFORE removing recovery metadata. A crash here is
    # retryable (above) and never needs files.json after it has been removed.
    try:
        remove_directory(snapshot)
    except Exception:
        value['message'] = '店铺运行数据已清理，私有配置检查点还需人工清理。'
        store_state(c, request, value)
    return value


def remote(c, payload):
    response = ssh_json(c, payload)
    require(set(response) == RESULT_FIELDS and response['site_id'] == payload['request']['site_id']
            and response['uuid'] == payload['request']['uuid']
            and response['retirement_id'] == payload['request']['retirement_id'], 'remote_lifecycle_identity')
    require(not response['error_code'] and response['state'] != 'failed',
            response['error_code'] if re.fullmatch(r'[a-z0-9_]{1,80}', response.get('error_code', '')) else 'remote_lifecycle_failed')
    return response


def ssh_json(c, payload):
    args = ['/usr/bin/ssh', '-i', c['web_ssh_key'], '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o',
            'UserKnownHostsFile=' + c['web_known_hosts'], '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=12', c['web_ssh_user'] + '@' + c['web_host'], 'lifecycle']
    return json.loads(command(args, data=canonical(payload), timeout=420))


def provisioned_entry(c, identity):
    """Build ONLY fixed new-tenant resources from root profile + proven identity.

    Legacy s0001 is intentionally unsupported by this builder; its root-reviewed
    deployment inventory is explicit. The current machine's resources are added,
    so automatic registration never fabricates another machine's file hashes.
    """
    require(set(identity) == {'site_id', 'uuid', 'slug', 'operation_id', 'customer_domain'}, 'register_shape')
    valid_uuid(identity['uuid'])
    valid_uuid(identity['operation_id'], 'operation_id', 4)
    slug = identity['slug']
    require(re.fullmatch(r's[0-9]{4}', slug) and 2 <= int(slug[1:]) <= 9999, 'register_legacy_denied')
    require(type(identity['site_id']) is int and identity['site_id'] > 0, 'register_site_id')
    domain = identity['customer_domain']
    require(isinstance(domain, str) and re.fullmatch(r'[a-z0-9][a-z0-9.-]*\.[a-z]{2,63}', domain)
            and not domain.startswith('www.') and '..' not in domain, 'register_customer_domain')
    role = c['role']
    require(role in {'web', 'admin'}, 'role')
    # The original root-owned domain claim binds this exact allocation.
    ledger = Path(c['state_root'] if role == 'admin' else c['web_ledger_root'])
    claim = load_json(ledger / 'domain-claims' / (digest(domain.encode()) + '.json'), private=True)
    require(all(claim.get(key) == identity[key] for key in ('uuid', 'slug', 'operation_id', 'customer_domain'))
            and claim.get('kind') == 'provisioned', 'register_domain_claim')
    if role == 'admin':
        base = Path(c['admin_store_root']) / slug
        data = base / 'shared'
        marker = load_json(base / '.provision-owner.json', private=True)
        require(marker == {'operation_id': identity['operation_id'], 'uuid': identity['uuid']}, 'register_owner_marker')
        job = ledger / 'jobs' / identity['operation_id']
        original = load_json(job / 'request.json', private=True)
        require(original.get('operation_id') == identity['operation_id']
                and original.get('customer_domain') == domain, 'register_original_job')
        paths = [{'path': str(base), 'kind': 'directory'}]
        for path in [job / 'media-key', job / 'media-key.pub', job / 'request.json',
                     ledger / 'requests' / (identity['operation_id'] + '.json')]:
            if path.exists():
                paths.append({'path': str(path), 'kind': 'file'})
        mounts = [str(data / name) for name in ('uploads', 'reports')]
        units = ['vialcove-' + slug + '-' + name + '.service' for name in ('uploads', 'reports')]
        users, keys = ['vc' + slug], []
        hosts = [slug + '.' + c['management_domain']]
    else:
        base = Path(c['web_store_root']) / slug
        data = Path(c['web_data_root']) / slug
        state = load_json(ledger / (slug + '.json'), private=True)
        require(state.get('phase') == 'domains' and state.get('customer_domain') == domain,
                'register_web_ledger_phase')
        paths = [{'path': str(base), 'kind': 'directory'}, {'path': str(data), 'kind': 'directory'},
                 {'path': str(ledger / (slug + '.json')), 'kind': 'file'},
                 {'path': '/srv/vialcove-media-export/' + slug, 'kind': 'directory'}]
        for path in ['/etc/ssh/sshd_config.d/45-vialcove-' + slug + '.conf',
                     '/usr/local/libexec/vialcove-export-' + slug,
                     '/usr/local/sbin/vialcove-' + slug + '-cron']:
            if Path(path).exists():
                paths.append({'path': path, 'kind': 'file'})
        mounts = ['/srv/vialcove-media-export/' + slug + '/' + name for name in ('uploads', 'reports')]
        units = ['vialcove-' + slug + '-cron.timer', 'vialcove-' + slug + '-cron.service',
                 'vialcove-export-' + slug + '.service']
        users = ['vc' + slug, 'vcm_' + slug]
        key = '/etc/ssh/vialcove-bridge-keys/vcm_' + slug
        keys = [{'path': key, 'sha256': digest(checked_file(key))}]
        hosts = ['www.' + domain, domain]
    config_file = data / 'wp-config.php'
    require(identity['uuid'].encode() in checked_file(config_file), 'register_config_uuid')
    nginx = '/etc/nginx/sites-available/vialcove-' + slug
    pool = '/etc/php/8.3/fpm/pool.d/vialcove-' + slug + '.conf'
    part = {'paths': paths,
            'nginx': [{'available': nginx, 'enabled': '/etc/nginx/sites-enabled/vialcove-' + slug,
                       'sha256': digest(checked_file(nginx)), 'hosts': hosts}],
            'fpm': [{'path': pool, 'sha256': digest(checked_file(pool)), 'unit': 'php8.3-fpm'}],
            'units': units, 'mounts': mounts, 'revocable_keys': keys,
            'identity_files': [{'path': str(config_file)}], 'users': users}
    if role == 'web':
        part['db'] = {'name': 'vc_' + slug, 'accounts': [
            {'user': 'vcf_' + slug, 'host': 'localhost'}, {'user': 'vca_' + slug, 'host': '127.0.0.1'}]}
    entry = {key: identity[key] for key in ('site_id', 'uuid', 'slug')}
    entry.update(management_url='https://' + slug + '.' + c['management_domain'],
                 public_url='https://www.' + domain, template_dependencies=[], **{role: part})
    validate_inventory({'version': 1, 'sites': [entry]})
    return entry


def merge_inventory(c, entry):
    path = Path(c.get('lifecycle_inventory', INVENTORY_PATH))
    data = validate_inventory(load_json(path, private=True)) if path.exists() else {'version': 1, 'sites': []}
    for index, existing in enumerate(data['sites']):
        if existing['uuid'] == entry['uuid']:
            require(all(existing[key] == entry[key] for key in ('site_id', 'uuid', 'slug', 'management_url', 'public_url')),
                    'register_inventory_identity_changed')
            request = {'uuid': entry['uuid'], 'site_id': entry['site_id']}
            value = current(c, request)
            require(not value or value['state'] == 'active', 'refresh_retired_inventory_denied')
            data['sites'][index] = {**existing, c['role']: entry[c['role']]}
            break
    else:
        data['sites'].append(entry)
    validate_inventory(data)
    save_json(path, data)


def register_provisioned(c, tenant, original, state, site_id):
    """Admin worker hook AFTER confirm_hq, before writing job complete.

    root worker owns its usual worker.lock; web register takes create.lock.
    On failure provisioning must remain incomplete/retryable, never omit the
    lifecycle manifest silently. Also refreshes safe file hashes after a known
    root deployment while the tenant is active (not retained/failed).
    """
    require(c['role'] == 'admin' and tenant['uuid'] == state['uuid']
            and tenant['slug'] == state['slug'], 'register_worker_identity')
    identity = {'site_id': site_id, 'uuid': state['uuid'], 'slug': state['slug'],
                'operation_id': original['operation_id'], 'customer_domain': original['customer_domain']}
    entry = provisioned_entry(c, identity)
    response = ssh_json(c, {'version': 1, 'phase': 'register', 'registration': identity})
    require(response == {'registered': True, 'site_id': site_id, 'uuid': state['uuid']}, 'register_remote_identity')
    merge_inventory(c, entry)
    return response


def envelope(request, phase, retired_at=0, purge_at=0):
    return {'version': 1, 'phase': phase, 'request': request,
            'retired_at': retired_at, 'purge_at': purge_at}


def web_main(c, payload):
    if isinstance(payload, dict) and payload.get('phase') == 'register':
        require(c['role'] == 'web' and set(payload) == {'version', 'phase', 'registration'}
                and payload['version'] == 1, 'web_register_shape')
        with lock(private_dir(Path(c['web_ledger_root'])) / 'create.lock'):
            entry = provisioned_entry(c, payload['registration'])
            merge_inventory(c, entry)
            return {'registered': True, 'site_id': entry['site_id'], 'uuid': entry['uuid']}
    require(c['role'] == 'web' and isinstance(payload, dict)
            and set(payload) == {'version', 'phase', 'request', 'retired_at', 'purge_at'}
            and payload['version'] == 1, 'web_lifecycle_shape')
    request = validate_request(payload['request'])
    require(payload['phase'] in {'preflight', 'retire', 'restore_prepare', 'restore_open',
                                'reclose', 'purge', 'inspect'}, 'web_lifecycle_phase')
    for key in ('retired_at', 'purge_at'):
        require(type(payload[key]) is int and payload[key] >= 0, 'web_lifecycle_time')
    root = private_dir(Path(c['web_ledger_root']))
    with lock(root / 'create.lock'), operation_locks(c):
        site = find_site(c, request)
        try:
            if payload['phase'] == 'preflight':
                preflight(c, request, site)
                return result(request, 'active')
            if payload['phase'] == 'inspect':
                return current(c, request) or result(request, 'active')
            if payload['phase'] == 'retire':
                require(request['action'] == 'retire' and payload['purge_at'] == payload['retired_at'] + RETENTION_SECONDS
                        and payload['retired_at'] <= int(time.time()) + 300, 'retirement_time')
                return retire_local(c, request, site, payload['retired_at'], payload['purge_at'])
            if payload['phase'] in {'restore_prepare', 'restore_open'}:
                require(request['action'] == 'restore', 'restore_action')
                return restore_local(c, request, site, prepare_only=payload['phase'] == 'restore_prepare')
            if payload['phase'] == 'reclose':
                old = current(c, request)
                require(old and old['retirement_id'] == request['retirement_id'], 'retirement_not_found')
                return retire_local(c, request, site, old['retired_at'], old['purge_at'])
            return purge_local(c, request, site, int(time.time()))
        except Exception as exc:
            previous = current(c, request)
            value = result(request, 'failed', retired_at=(previous or payload)['retired_at'],
                           purge_at=(previous or payload)['purge_at'], error_code=safe_error(exc),
                           message='操作未完成，请人工核对两台服务器；总后台保持停用。')
            # Pure preflight is read-only and cannot close an otherwise active store.
            if payload['phase'] not in {'preflight', 'inspect'}:
                store_state(c, request, value)
            return value


def dispatch(c, request, now=None):
    require(c['role'] == 'admin', 'admin_role')
    request = validate_request(request)
    site = find_site(c, request)
    now = int(time.time()) if now is None else now
    existing = current(c, request)
    if request['action'] == 'restore':
        require(existing and existing['retirement_id'] == request['retirement_id'], 'retirement_not_found')
        require(existing['state'] == 'active' or now < existing['purge_at'], 'retention_expired')
        try:
            remote(c, envelope(request, 'restore_prepare'))
            restore_local(c, request, site, prepare_only=True)
            local = restore_local(c, request, site)
            try:
                confirmed = remote(c, envelope(request, 'restore_open'))
            except Exception:
                # Public opening is LAST. A lost reply is never treated as
                # success without a second authenticated state readback.
                confirmed = remote(c, envelope(request, 'inspect'))
            require(confirmed['state'] == 'active'
                    and confirmed['retired_at'] == existing['retired_at']
                    and confirmed['purge_at'] == existing['purge_at'], 'remote_restore_unconfirmed')
            return local
        except Exception:
            # A failed second-host restore cannot leave the first host open.
            # Errors here remain visible as failed; nothing marks HQ active.
            for cleanup in (lambda: retire_local(c, request, site, existing['retired_at'], existing['purge_at']),
                            lambda: remote(c, envelope(request, 'reclose'))):
                try:
                    cleanup()
                except Exception:
                    pass
            raise
    if existing and existing['state'] == 'active':
        # A new retirement after successful restore gets its own immutable id.
        require(existing['retirement_id'] != request['retirement_id'], 'retirement_already_restored')
        preflight(c, request, site)
        store_state(c, request, result(request, 'offlining', retired_at=now,
                                      purge_at=now + RETENTION_SECONDS))
        existing = current(c, request)
    if existing:
        require(existing['retirement_id'] == request['retirement_id'], 'different_retirement_active')
        require(existing['state'] != 'purged', 'already_purged')
        retired_at, purge_at = existing['retired_at'], existing['purge_at']
        if existing['state'] == 'failed' and retired_at == 0 and purge_at == 0:
            preflight(c, request, site)
            remote(c, envelope(request, 'preflight'))
            retired_at, purge_at = now, now + RETENTION_SECONDS
    else:
        preflight(c, request, site)
        remote(c, envelope(request, 'preflight'))
        retired_at, purge_at = now, now + RETENTION_SECONDS
    # Admin PHP/SSHFS goes down first, then the public app/media/DB.
    local = retire_local(c, request, site, retired_at, purge_at)
    confirmed = remote(c, envelope(request, 'retire', retired_at, purge_at))
    require(confirmed['state'] == 'retained' and confirmed['retired_at'] == retired_at
            and confirmed['purge_at'] == purge_at, 'remote_retire_unconfirmed')
    return local


def process_queue(c, *, lock_held=False):
    require(c['role'] == 'admin', 'admin_role')
    root = Path(c['state_root'])
    no_symlink_components(root)
    require(root.is_dir() and root.stat().st_uid == 0 and not root.stat().st_mode & 0o022,
            'state_root_permissions')
    if not lock_held:
        try:
            with lock(root / 'worker.lock', nonblocking=True):
                return process_queue(c, lock_held=True)
        except BlockingIOError:
            return
    base = state_root(c)
    requests = queue_dir(base / 'requests', c['hq_user'], 0o730)
    results = queue_dir(base / 'results', c['hq_user'], 0o750)
    owner = pwd.getpwnam(c['hq_user']).pw_uid
    changed, processed = False, 0
    # HQ can deposit bounded request files; only this root worker writes states.
    for entry in sorted(requests.glob('*.json')):
        if processed >= 64:
            break
        request = None
        try:
            request = validate_request(load_json(entry, owners=(0, owner), limit=16384))
            require(entry.name == request['operation_id'] + '.json', 'request_filename')
            receipt = results / entry.name
            operation = private_dir(base / 'operations') / request['operation_id']
            private_dir(operation)
            fingerprint = digest(canonical(request))
            marker = operation / 'request.sha256'
            if marker.exists():
                require(checked_file(marker, private=True).decode() == fingerprint, 'request_replay_changed')
            else:
                atomic(marker, fingerprint.encode())
            journal = operation / 'request.json'
            if journal.exists():
                require(digest(canonical(load_json(journal, private=True))) == fingerprint, 'request_journal_changed')
            else:
                save_json(journal, request)
            if receipt.exists():
                before = load_json(receipt, private=False)
                require(set(before) == RESULT_FIELDS and before['site_id'] == request['site_id']
                        and before['uuid'] == request['uuid'] and before['retirement_id'] == request['retirement_id'],
                        'receipt_identity_changed')
                if before['state'] != 'failed':
                    entry.unlink()
                    continue
            try:
                value = dispatch(c, request)
            except Exception as exc:
                old = current(c, request)
                value = result(request, 'failed', retired_at=(old or {}).get('retired_at', 0),
                               purge_at=(old or {}).get('purge_at', 0), error_code=safe_error(exc),
                               message='操作未完成，请人工核对两台服务器；总后台保持停用。')
                store_state(c, request, value)
            save_json(receipt, value, group=c['hq_user'])
            entry.unlink()
            processed += 1
            changed = True
        except Exception:
            # Malformed/untrusted requests do not get a forged identity receipt.
            # Move only the inbox entry; do not let it starve later operations.
            rejected = private_dir(base / 'rejected')
            if not entry.is_symlink():
                os.replace(entry, rejected / (digest(entry.name.encode()) + '-' + secrets.token_hex(4) + '.json'))
            continue
    # Expiry is server-owned, not an HQ/browser request. Reuse original operation
    # and retirement id; a duplicate queue tick cannot extend the 72-hour clock.
    tenants = private_dir(base / 'tenants')
    for folder in sorted(tenants.iterdir())[:10000]:
        value, retired = None, None
        if not folder.is_dir() or folder.is_symlink():
            continue
        try:
            value = load_json(folder / 'current.json', private=True)
            cleanup_pending = value['state'] == 'purged' and (folder / value['retirement_id']).exists()
            if not cleanup_pending and (value['state'] not in {'retained', 'purging'}
                    or int(time.time()) < value['purge_at']):
                continue
            # The original request is bound to this tenant and retirement, not
            # searched by a caller-reusable retirement UUID across all tenants.
            valid_uuid(value['retirement_id'], 'retirement_id', 4)
            retired = validate_request(load_json(folder / (value['retirement_id'] + '.request.json'), private=True))
            require(retired['uuid'] == folder.name and retired['uuid'] == value['uuid']
                    and retired['site_id'] == value['site_id'] and retired['action'] == 'retire'
                    and retired['retirement_id'] == value['retirement_id'], 'expiry_original_request_identity')
            site = find_site(c, retired)
            peer = remote(c, envelope(retired, 'inspect'))
            require(peer['state'] in {'retained', 'purging', 'purged'}
                    and peer['retired_at'] == value['retired_at']
                    and peer['purge_at'] == value['purge_at'], 'purge_peer_not_retained')
            remote(c, envelope(retired, 'purge'))
            final = purge_local(c, retired, site, int(time.time()))
            save_json(results / (retired['operation_id'] + '.json'), final, group=c['hq_user'])
            changed = True
        except Exception as exc:
            if value and value.get('state') in {'retained', 'purging'}:
                code = safe_error(exc)
                transient = isinstance(exc, (TimeoutError, subprocess.TimeoutExpired, ConnectionError)) \
                    or code.startswith('command_failed_ssh') or code in {'remote_lifecycle_failed', 'backup_busy', 'deployment_busy'}
                value['state'], value['error_code'] = ('purging' if transient else 'failed'), code
                value['message'] = '自动清理已暂停，请人工核对；总后台保持停用。'
                save_json(folder / 'current.json', value)
                if 'retired' in locals() and retired and retired.get('uuid') == value['uuid']:
                    save_json(results / (retired['operation_id'] + '.json'), value, group=c['hq_user'])
                changed = True
    return changed
