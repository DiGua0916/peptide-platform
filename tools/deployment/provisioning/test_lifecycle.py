"""Policy / isolated filesystem models. No server, WP, SQL or SSH is contacted.

Windows stubs do not certify POSIX permissions, flock, mount or daemon behavior.
Every external command is denied unless the individual test explicitly mocks it.
"""
import copy
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import patch
import uuid

if sys.platform == 'win32':
    sys.modules.setdefault('fcntl', types.SimpleNamespace(LOCK_EX=2, LOCK_NB=4, flock=lambda *a: None))
    sys.modules.setdefault('pwd', types.SimpleNamespace(getpwnam=lambda n: types.SimpleNamespace(pw_uid=0, pw_gid=0)))
    sys.modules.setdefault('grp', types.SimpleNamespace(getgrnam=lambda n: types.SimpleNamespace(gr_gid=0)))
spec = importlib.util.spec_from_file_location('lifecycle', Path(__file__).with_name('lifecycle.py'))
lifecycle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lifecycle)
TEST_ROOT = Path(os.environ.get('VIALCOVE_TEST_TMPDIR',
    'D:/Codex/Tasks/2026-11-03/store-lifecycle/work' if sys.platform == 'win32'
    else tempfile.gettempdir()))
TEST_ROOT.mkdir(parents=True, exist_ok=True)


class Boundary(unittest.TestCase):
    def setUp(self):
        self.stack = __import__('contextlib').ExitStack()
        self.addCleanup(self.stack.close)
        self.stack.enter_context(patch.object(lifecycle, 'command', side_effect=AssertionError('external command forbidden')))
        # Synthetic queue owner, independent of host /etc/passwd. The suite
        # must never require or create a real operating-system account 'test'.
        self.stack.enter_context(patch.object(lifecycle.pwd, 'getpwnam',
            return_value=types.SimpleNamespace(pw_uid=0, pw_gid=0)))
        self.request = dict(version=1, operation_id=str(uuid.uuid4()), actor_id=1, site_id=3,
                            uuid='00000000-0000-4000-8000-000000000002',
                            management_url='https://s0002.admin.example.net', public_url='https://www.example.com',
                            action='retire', retirement_id=str(uuid.uuid4()))
        # Placeholder only: command() is denied and tests replace state access.
        self.config = dict(role='admin', state_root=str(TEST_ROOT / 'model'), hq_user='test')
        self.site = lifecycle.inventory_example()['sites'][0]

    def test_exact_request_schema_and_canonical_urls(self):
        clean = lifecycle.validate_request(self.request)
        self.assertEqual(clean, self.request)
        for key in self.request:
            bad = dict(self.request); del bad[key]
            with self.subTest(missing=key), self.assertRaises(ValueError):
                lifecycle.validate_request(bad)
        for key, value in [('paths', ['/etc']), ('command', 'rm'), ('retention_seconds', 1)]:
            with self.subTest(extra=key), self.assertRaises(ValueError):
                lifecycle.validate_request(dict(self.request, **{key: value}))
        for key in ('actor_id', 'site_id', 'version'):
            with self.assertRaises(ValueError): lifecycle.validate_request(dict(self.request, **{key: True}))
        for url in ['http://example.com', 'https://name@example.com', 'https://example.com:443',
                    'https://example.com/x', 'https://example.com/?a=b', 'https://example.com/#x',
                    'https://example.com\n', 'https://EXAMPLE.COM', 'https://127.0.0.1']:
            with self.subTest(url=url), self.assertRaises(ValueError): lifecycle.valid_url(url)

    def test_request_identifiers_and_actions(self):
        for key in ('uuid', 'retirement_id', 'operation_id'):
            for value in ('', '../../x', 'NOT-A-UUID', str(uuid.uuid4()).upper()):
                with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                    lifecycle.validate_request(dict(self.request, **{key: value}))
        for action in ('purge', 'delete', 'ssh', False):
            with self.assertRaises(ValueError): lifecycle.validate_request(dict(self.request, action=action))

    def test_inventory_example_validates(self):
        self.assertEqual(lifecycle.validate_inventory(lifecycle.inventory_example())['version'], 1)
        for role in ('admin', 'web'):
            data = lifecycle.inventory_example()
            del data['sites'][0]['web' if role == 'admin' else 'admin']
            lifecycle.validate_inventory(data)

    def test_inventory_shared_resources_rejected(self):
        for path in ('/', '/srv/vialcove/hq/shared', '/srv/vialcove-data/tenant-backups/old.age',
                     '/var/lib/vialcove-provision/web-command-key', '/etc/nginx/nginx.conf',
                     '/srv/vialcove/../hq', '/srv/vialcove/tenants/a b',
                     '/var/lib/vialcove-provision/domain-claims/hash.json',
                     '/etc/systemd/system/vialcove-s0001-db.service'):
            with self.subTest(path=path), self.assertRaises(ValueError): lifecycle.owned_path(path)
        data = lifecycle.inventory_example()
        data['sites'][0]['admin']['units'].append('vialcove-tenant-backup.timer')
        with self.assertRaisesRegex(ValueError, 'shared_unit'): lifecycle.validate_inventory(data)

    def second_site(self, data):
        second = copy.deepcopy(data['sites'][0])
        second.update(site_id=4, uuid=str(uuid.uuid4()), slug='s0003',
                      management_url='https://s0003.admin.example.net', public_url='https://www.other.example')
        for role in ('admin', 'web'):
            second[role] = json.loads(json.dumps(second[role]).replace('s0002', 's0003').replace('www.example.com', 'www.other.example').replace('example.com', 'other.example'))
        data['sites'].append(second)
        return second

    def test_cross_tenant_overlap_and_shared_users_db_units(self):
        for mutation in ('nested', 'user', 'database', 'account', 'unit'):
            data = lifecycle.inventory_example(); second = self.second_site(data)
            if mutation == 'nested': second['admin']['paths'][0]['path'] = data['sites'][0]['admin']['paths'][0]['path'] + '/child'
            if mutation == 'user': second['admin']['users'] = data['sites'][0]['admin']['users']
            if mutation == 'database': second['web']['db']['name'] = data['sites'][0]['web']['db']['name']
            if mutation == 'account': second['web']['db']['accounts'] = data['sites'][0]['web']['db']['accounts']
            if mutation == 'unit': second['admin']['units'] = data['sites'][0]['admin']['units']
            with self.subTest(mutation=mutation), self.assertRaises(ValueError): lifecycle.validate_inventory(data)

    def test_nginx_quarantine_preserves_security_and_other_bytes(self):
        raw = b'''# {comment}\nserver { listen 443 ssl default_server; server_name _; ssl_reject_handshake on; }\nserver {\n listen 443 ssl http2; server_name a.example.com b.example.com;\n if ($host != a.example.com) { return 444; }\n add_header X-Frame-Options SAMEORIGIN always;\n location / { try_files $uri $uri/ /index.php; }\n}\n'''
        changed = lifecycle.nginx_quarantine(raw, ['a.example.com', 'b.example.com'])
        self.assertEqual(changed.count(b'return 410;'), 1)
        self.assertEqual(changed.replace(b'\n    # vialcove-lifecycle-closed\n    return 410;\n', b''), raw)
        self.assertLess(changed.index(b'return 444'), changed.index(b'return 410'))
        with self.assertRaisesRegex(ValueError, 'nginx_shared_server'):
            lifecycle.nginx_quarantine(raw, ['a.example.com'])
        with self.assertRaisesRegex(ValueError, 'nginx_owned_hosts_missing'):
            lifecycle.nginx_quarantine(raw, ['a.example.com', 'b.example.com', 'c.example.com'])

    def test_nginx_refuses_default_nonreject_wildcards_and_malformed(self):
        for raw in (b'server { server_name _; return 200; }', b'server { server_name *.example.com; }',
                    b'server { server_name a.example.com;', b'location / { return 200; }'):
            with self.subTest(raw=raw), self.assertRaises(ValueError): lifecycle.nginx_quarantine(raw, ['a.example.com'])

    def test_legacy_http_default_and_server_name_variable_preserved(self):
        original = b'''server { listen 80 default_server; server_name _; return 444; }\nserver { listen 443 ssl; server_name a.example.com; if ($server_name != a.example.com) { return 444; } location / { return 200; } }'''
        changed = lifecycle.nginx_quarantine(original, ['a.example.com'])
        self.assertEqual(changed.count(b'vialcove-lifecycle-closed'), 1)
        self.assertEqual(changed.replace(b'\n    # vialcove-lifecycle-closed\n    return 410;\n', b''), original)

    def test_master_in_use_raw_and_resolved_source(self):
        c = dict(self.config, source_admin_root='/srv/vialcove/tenant-admin/s0002/public')
        with self.assertRaisesRegex(ValueError, 'master_in_use'): lifecycle.check_master(c, self.site, 'admin')
        lifecycle.check_master(dict(c, source_admin_root='/srv/vialcove/masters/code/public'), self.site, 'admin')

    def test_mount_descendants_rejected(self):
        for mount in ('/srv/vialcove/tenant-admin/s0002', '/srv/vialcove/tenant-admin/s0002/shared/uploads'):
            with self.assertRaisesRegex(ValueError, 'resource_still_mounted'):
                lifecycle.no_mount_below('/srv/vialcove/tenant-admin/s0002', {mount})
        lifecycle.no_mount_below('/srv/vialcove/tenant-admin/s0002', {'/srv/vialcove', '/srv/vialcove/tenant-admin/s0003'})

    def test_result_exact_safe_schema(self):
        value = lifecycle.result(self.request, 'retained', retired_at=100, purge_at=100 + lifecycle.RETENTION_SECONDS)
        self.assertEqual(set(value), lifecycle.RESULT_FIELDS)
        self.assertNotIn('paths', value)
        self.assertEqual(value['retirement_id'], self.request['retirement_id'])
        self.assertEqual(lifecycle.safe_error(RuntimeError('password secret')), 'runtimeerror')
        self.assertEqual(lifecycle.safe_error(ValueError('secret /etc/path')), 'lifecycle_failed')

    def test_error_codes_match_php_lowercase_eighty_character_contract(self):
        cases = [TimeoutError('private endpoint'), ConnectionError('private host'), RuntimeError('secret'),
                 ValueError('MIXED_Valid_Error'), ValueError('A' * 100), ValueError('x' * 1000),
                 ValueError('contains space'), ValueError('隐私资料')]
        for error in cases:
            with self.subTest(error=type(error).__name__):
                code = lifecycle.safe_error(error)
                self.assertRegex(code, r'^[a-z0-9_]{1,80}$')
        self.assertEqual(lifecycle.safe_error(TimeoutError()), 'timeouterror')
        self.assertEqual(lifecycle.safe_error(ConnectionError()), 'connectionerror')
        self.assertEqual(lifecycle.safe_error(ValueError('MIXED_Valid_Error')), 'mixed_valid_error')

    def dispatch_model(self, existing=None):
        states = []
        self.stack.enter_context(patch.object(lifecycle, 'find_site', return_value=self.site))
        state = [existing]
        self.stack.enter_context(patch.object(lifecycle, 'current', side_effect=lambda *a: state[0]))
        def save(c, request, value):
            states.append(dict(value)); state[0] = dict(value)
        self.stack.enter_context(patch.object(lifecycle, 'store_state', side_effect=save))
        self.stack.enter_context(patch.object(lifecycle, 'preflight', return_value=True))
        return states

    def test_retire_preflights_both_hosts_and_keeps_one_deadline(self):
        self.dispatch_model()
        remote = self.stack.enter_context(patch.object(lifecycle, 'remote', side_effect=lambda c, p:
            lifecycle.result(self.request, 'retained' if p['phase'] == 'retire' else 'active',
                             retired_at=p['retired_at'], purge_at=p['purge_at'])))
        local = self.stack.enter_context(patch.object(lifecycle, 'retire_local', return_value={'state': 'retained'}))
        lifecycle.dispatch(self.config, self.request, now=100)
        self.assertEqual([call.args[1]['phase'] for call in remote.call_args_list], ['preflight', 'retire'])
        self.assertEqual(local.call_args.args[-2:], (100, 259300))
        existing = lifecycle.result(self.request, 'offlining', retired_at=100, purge_at=259300)
        with patch.object(lifecycle, 'current', return_value=existing):
            lifecycle.dispatch(self.config, self.request, now=999)
        self.assertEqual(local.call_args.args[-2:], (100, 259300))

    def test_restored_store_can_start_new_retirement(self):
        old = lifecycle.result(self.request, 'active', retired_at=100, purge_at=259300)
        old['retirement_id'] = str(uuid.uuid4())
        states = self.dispatch_model(old)
        with patch.object(lifecycle, 'remote', side_effect=lambda c, p: lifecycle.result(self.request, 'retained',
                retired_at=p['retired_at'], purge_at=p['purge_at'])), patch.object(lifecycle, 'retire_local', return_value={}):
            lifecycle.dispatch(self.config, self.request, now=200)
        self.assertEqual(states[-1]['retirement_id'], self.request['retirement_id'])
        self.assertEqual(states[-1]['retired_at'], 200)

    def test_restore_two_phase_and_double_reclose_on_second_failure(self):
        self.request['action'] = 'restore'
        old = lifecycle.result(self.request, 'retained', retired_at=100, purge_at=259300)
        self.dispatch_model(old)
        events = []
        def remote(c, payload):
            events.append(payload['phase'])
            return dict(old, state='active' if payload['phase'] in {'restore_open', 'inspect'} else 'restoring')
        with patch.object(lifecycle, 'remote', side_effect=remote), \
             patch.object(lifecycle, 'restore_local', side_effect=lambda *a, **kw: events.append('admin_prepare' if kw.get('prepare_only') else 'admin_open') or {}):
            lifecycle.dispatch(self.config, self.request, now=200)
        self.assertEqual(events, ['restore_prepare', 'admin_prepare', 'admin_open', 'restore_open'])
        events.clear()
        def restore(*args, **kwargs):
            if not kwargs.get('prepare_only'): raise ValueError('second_host_failed')
            return {}
        with patch.object(lifecycle, 'remote', side_effect=lambda c, p: events.append(p['phase']) or {}), \
             patch.object(lifecycle, 'restore_local', side_effect=restore), \
             patch.object(lifecycle, 'retire_local', side_effect=lambda *a: events.append('admin_reclose') or {}):
            with self.assertRaisesRegex(ValueError, 'second_host_failed'): lifecycle.dispatch(self.config, self.request, now=200)
        self.assertIn('admin_reclose', events)
        self.assertEqual(events[-1], 'reclose')

    def test_restore_expired_and_wrong_retirement_denied_before_remote(self):
        self.request['action'] = 'restore'
        old = lifecycle.result(self.request, 'retained', retired_at=100, purge_at=259300)
        self.dispatch_model(old)
        with self.assertRaisesRegex(ValueError, 'retention_expired'): lifecycle.dispatch(self.config, self.request, now=259300)
        old['retirement_id'] = str(uuid.uuid4())
        with self.assertRaisesRegex(ValueError, 'retirement_not_found'): lifecycle.dispatch(self.config, self.request, now=200)

    def test_purge_refuses_active_failed_early_and_bad_clock(self):
        for state, retired_at, purge_at, now in [('active', 100, 259300, 999999),
                ('failed', 0, 0, 999999), ('retained', 100, 259300, 259299),
                ('retained', 100, 101, 999999)]:
            value = lifecycle.result(self.request, state, retired_at=retired_at, purge_at=purge_at)
            with self.subTest(state=state, deadline=purge_at), patch.object(lifecycle, 'current', return_value=value), \
                 self.assertRaisesRegex(ValueError, 'purge_not_due'):
                lifecycle.purge_local(self.config, self.request, self.site, now)

    def test_database_grants_reject_other_db(self):
        database = self.site['web']['db']
        outputs = iter(['1', '1', 'GRANT ALL PRIVILEGES ON `other_db`.* TO `vcf_s0002`@`localhost`'])
        with patch.object(lifecycle, 'mysql', side_effect=lambda *a: next(outputs)), \
             self.assertRaisesRegex(ValueError, 'database_account_shared'):
            lifecycle.database_check(database)

    def test_database_lock_uses_manifest_accounts_and_kills_only_matching_ids(self):
        commands = []
        def mysql(sql):
            commands.append(sql)
            return '41\tvcf_s0002' if sql.startswith('SELECT ID') else ''
        with patch.object(lifecycle, 'mysql', side_effect=mysql): lifecycle.database_lock(self.site['web']['db'], True)
        self.assertEqual(commands[-1], 'KILL CONNECTION 41;')
        self.assertEqual(len([s for s in commands if s.endswith('ACCOUNT LOCK;')]), 2)
        with patch.object(lifecycle, 'mysql', return_value='44\troot'), self.assertRaisesRegex(ValueError, 'database_session_identity'):
            lifecycle.database_lock(self.site['web']['db'], True)

    def test_web_previous_active_accepts_new_cycle_with_fresh_preflight(self):
        old = lifecycle.result(self.request, 'active', retired_at=100, purge_at=259300)
        old['retirement_id'] = str(uuid.uuid4())
        site = copy.deepcopy(self.site)
        site['web'] = {key: [] for key in ('paths', 'nginx', 'fpm', 'units', 'mounts', 'revocable_keys')}
        site['web']['db'] = self.site['web']['db']
        config = dict(self.config, role='web')
        saved = []
        with patch.object(lifecycle, 'current', return_value=old), \
             patch.object(lifecycle, 'preflight', return_value=True) as preflight, \
             patch.object(lifecycle, 'store_state', side_effect=lambda c, r, v: saved.append(dict(v))), \
             patch.object(lifecycle, 'snapshot_path', return_value=TEST_ROOT / 'mock-snapshot'), \
             patch.object(lifecycle, 'tenant_state', return_value=TEST_ROOT / 'mock-tenant'), \
             patch.object(lifecycle, 'saved_files', return_value={}), patch.object(lifecycle.Path, 'exists', return_value=False), \
             patch.object(lifecycle, 'save_json'), patch.object(lifecycle, 'command', return_value=b''), \
             patch.object(lifecycle, 'database_lock'):
            final = lifecycle.retire_local(config, self.request, site, 200, 259400)
        preflight.assert_called_once()
        self.assertEqual(final['state'], 'retained')
        self.assertEqual(final['retirement_id'], self.request['retirement_id'])
        self.assertEqual(saved[0]['retired_at'], 200)

    def test_purged_retry_needs_no_deleted_files_checkpoint(self):
        value = lifecycle.result(self.request, 'purged', retired_at=100, purge_at=259300)
        with patch.object(lifecycle, 'current', return_value=value), \
             patch.object(lifecycle, 'tenant_state', return_value=TEST_ROOT / 'mock-tenant'), \
             patch.object(lifecycle.Path, 'exists', return_value=True), \
             patch.object(lifecycle, 'remove_directory') as remove, \
             patch.object(lifecycle, 'saved_files', side_effect=AssertionError('deleted journal read')):
            final = lifecycle.purge_local(self.config, self.request, self.site, 999999)
        self.assertEqual(final['state'], 'purged')
        remove.assert_called_once()

    def test_restore_public_open_lost_reply_requires_inspect(self):
        self.request['action'] = 'restore'
        old = lifecycle.result(self.request, 'retained', retired_at=100, purge_at=259300)
        self.dispatch_model(old)
        events = []
        def remote(c, payload):
            events.append(payload['phase'])
            if payload['phase'] == 'restore_open': raise TimeoutError()
            return dict(old, state='active')
        with patch.object(lifecycle, 'remote', side_effect=remote), \
             patch.object(lifecycle, 'restore_local', return_value=dict(old, state='active')):
            final = lifecycle.dispatch(self.config, self.request, now=200)
        self.assertEqual(final['state'], 'active')
        self.assertEqual(events, ['restore_prepare', 'restore_open', 'inspect'])

    def queue_model(self, root):
        root = Path(root)
        base = root / 'lifecycle'
        def directory(path, *args, **kwargs):
            path = Path(path); path.mkdir(parents=True, exist_ok=True); return path
        def save(path, value, **kwargs): Path(path).write_text(json.dumps(value), encoding='utf-8')
        for name in ('requests', 'results', 'tenants', 'operations'): directory(base / name)
        self.stack.enter_context(patch.object(lifecycle, 'no_symlink_components'))
        self.stack.enter_context(patch.object(lifecycle, 'queue_dir', side_effect=directory))
        self.stack.enter_context(patch.object(lifecycle, 'private_dir', side_effect=directory))
        self.stack.enter_context(patch.object(lifecycle, 'state_root', return_value=base))
        self.stack.enter_context(patch.object(lifecycle, 'load_json', side_effect=lambda p, **kw: json.loads(Path(p).read_text(encoding='utf-8'))))
        self.stack.enter_context(patch.object(lifecycle, 'save_json', side_effect=save))
        self.stack.enter_context(patch.object(lifecycle, 'atomic', side_effect=lambda p, value, **kw: Path(p).write_bytes(value)))
        self.stack.enter_context(patch.object(lifecycle, 'checked_file', side_effect=lambda p, **kw: Path(p).read_bytes()))
        real_stat = lifecycle.Path.stat
        def private_stat(path, *args, **kwargs):
            original = list(real_stat(path, *args, **kwargs)); original[4] = 0; original[0] &= ~0o022
            return os.stat_result(original)
        self.stack.enter_context(patch.object(lifecycle.Path, 'stat', private_stat))
        return base, save

    def test_queue_completed_sixty_four_do_not_starve_next_and_failed_can_retry(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as temp:
            base, save = self.queue_model(temp)
            identifiers = sorted(str(uuid.uuid4()) for _ in range(66))
            for index, operation in enumerate(identifiers):
                request = dict(self.request, operation_id=operation)
                save(base / 'requests' / (operation + '.json'), request)
                if index < 65:
                    save(base / 'results' / (operation + '.json'), lifecycle.result(request,
                         'retained' if index < 64 else 'failed'))
            with patch.object(lifecycle, 'dispatch', side_effect=lambda c, r: lifecycle.result(r, 'retained')) as dispatch:
                changed = lifecycle.process_queue(dict(self.config, state_root=temp), lock_held=True)
            self.assertTrue(changed)
            self.assertEqual(dispatch.call_count, 2)
            self.assertEqual(list((base / 'requests').glob('*.json')), [])

    def test_purge_network_failure_retries_with_unchanged_retention(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as temp:
            base, save = self.queue_model(temp)
            folder = base / 'tenants' / self.request['uuid']; folder.mkdir()
            value = lifecycle.result(self.request, 'retained', retired_at=100, purge_at=259300)
            save(folder / 'current.json', value)
            save(folder / (self.request['retirement_id'] + '.request.json'), self.request)
            with patch.object(lifecycle, 'find_site', return_value=self.site), \
                 patch.object(lifecycle, 'remote', side_effect=TimeoutError()) as remote, \
                 patch.object(lifecycle.time, 'time', return_value=300000):
                for _ in range(2): lifecycle.process_queue(dict(self.config, state_root=temp), lock_held=True)
            after = json.loads((folder / 'current.json').read_text())
            self.assertEqual(after['state'], 'purging')
            self.assertEqual(after['purge_at'], 259300)
            self.assertEqual(after['retired_at'], 100)
            self.assertEqual(remote.call_count, 2)

    def test_future_registration_cannot_infer_legacy_resources(self):
        identity = {'site_id': 2, 'uuid': self.request['uuid'], 'slug': 's0001',
                    'operation_id': self.request['operation_id'], 'customer_domain': 'example.com'}
        with self.assertRaisesRegex(ValueError, 'register_legacy_denied'):
            lifecycle.provisioned_entry(self.config, identity)

    def test_web_operation_locks_are_nonblocking_and_ordered(self):
        events = []
        @__import__('contextlib').contextmanager
        def record(path, nonblocking=False):
            events.append((str(path).replace('\\', '/'), nonblocking)); yield
        with patch.object(lifecycle, 'no_symlink_components'), \
             patch.object(lifecycle.Path, 'exists', return_value=True), \
             patch.object(lifecycle.Path, 'is_dir', return_value=True), \
             patch.object(lifecycle.Path, 'stat', return_value=types.SimpleNamespace(st_uid=0, st_mode=0o40700)), \
             patch.object(lifecycle, 'lock', side_effect=record):
            with lifecycle.operation_locks({'role': 'web'}): pass
        self.assertEqual(events, [('/var/lib/vialcove-deploy/nginx-update.lock', True),
                                  ('/srv/vialcove-data/tenant-backups/backup.lock', True)])

    def test_purge_identity_rechecked_and_replaced_resource_not_deleted(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as temp:
            root = Path(temp); snapshot = root / 'snapshot'; snapshot.mkdir()
            tenant = root / 'tenant'; tenant.mkdir()
            marker = root / 'identity.json'; marker.write_text(self.request['uuid'])
            site = {'admin': {'paths': [{'path': str(tenant), 'kind': 'directory'}],
                              'identity_files': [{'path': str(marker)}]}}
            with patch.object(lifecycle, 'checked_file', side_effect=lambda p, **kw: Path(p).read_bytes()), \
                 patch.object(lifecycle, 'no_mount_below'), \
                 patch.object(lifecycle, 'load_json', side_effect=lambda p, **kw: json.loads(Path(p).read_text())), \
                 patch.object(lifecycle, 'save_json', side_effect=lambda p, v, **kw: Path(p).write_text(json.dumps(v))):
                lifecycle.purge_ownership(snapshot, self.config, self.request, site)
                marker.write_text(str(uuid.uuid4()))
                with self.assertRaisesRegex(ValueError, 'purge_identity_changed'):
                    lifecycle.purge_ownership(snapshot, self.config, self.request, site)
                marker.unlink()  # A partial prior purge may have removed marker.
                lifecycle.purge_ownership(snapshot, self.config, self.request, site)
                tenant.rename(root / 'previous-tenant'); tenant.mkdir()
                with self.assertRaisesRegex(ValueError, 'purge_resource_replaced'):
                    lifecycle.purge_ownership(snapshot, self.config, self.request, site)


if __name__ == '__main__':
    unittest.main()
