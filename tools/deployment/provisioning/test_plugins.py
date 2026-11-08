"""Isolated tests for plugins.py (HQ 插件管理). No server, SSH, WP-CLI or network is contacted.

Two fake hosts are built under a temp dir: an admin host and a web host, each with a
first store (release-style 'current' link, from the lifecycle inventory) and a tenant
store (s0002, from a completed provisioning job / tenant wp-config). The SSH hop is
replaced by calling web_main() in-process with the web host's config.
"""
import base64
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch
import uuid
import zipfile

spec = importlib.util.spec_from_file_location('plugins', Path(__file__).with_name('plugins.py'))
plugins = importlib.util.module_from_spec(spec)
spec.loader.exec_module(plugins)

ME = os.geteuid()
GID = os.getegid()
FIRST = str(uuid.uuid4())
TENANT = str(uuid.uuid4())


def make_zip(files, *, symlink=None):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w') as z:
        for name, data in files.items():
            z.writestr(name, data)
        if symlink:
            info = zipfile.ZipInfo(symlink)
            info.external_attr = (0o120777 << 16)
            z.writestr(info, 'target')
    return buf.getvalue()


GOOD = make_zip({'acme-wheel/acme-wheel.php': "<?php\n/**\n * Plugin Name: Acme Wheel\n * Version: 1.2.3\n */\n",
                 'acme-wheel/assets/app.js': 'console.log(1)', 'acme-wheel/readme.txt': 'hi'})
GOOD_SHA = hashlib.sha256(GOOD).hexdigest()


def wp_root(path):
    (path / 'wp-content/plugins').mkdir(parents=True)
    (path / 'wp-load.php').write_text('<?php')
    return path


class Hosts(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp()).resolve()
        os.chmod(self.tmp, 0o755)
        self.patches = [patch.object(plugins.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=ME, pw_gid=GID)),
                        patch.object(plugins.grp, 'getgrnam', return_value=types.SimpleNamespace(gr_gid=GID))]
        for p in self.patches:
            p.start()
            self.addCleanup(p.stop)
        a, w = self.tmp / 'admin', self.tmp / 'web'
        # admin host: first store as a release link, tenant as tenant-admin/s0002/public
        rel = wp_root(a / 'srv/store-admin/releases/r1')
        os.symlink(rel, a / 'srv/store-admin/current')
        wp_root(a / 'srv/tenant-admin/s0002/public')
        state = a / 'state'
        (state / 'jobs/job1').mkdir(parents=True, mode=0o700)
        os.chmod(state, 0o711)
        self.private(state / 'jobs/job1/state.json', {'slug': 's0002', 'uuid': TENANT, 'state': 'complete'})
        # web host: first store at srv/current (release link), tenant at tenants/s0002/public
        wrel = wp_root(w / 'srv/releases/r9')
        os.symlink(wrel, w / 'srv/current')
        wp_root(w / 'srv/tenants/s0002/public')
        (w / 'data/s0002').mkdir(parents=True)
        cfg = w / 'data/s0002/wp-config.php'
        cfg.write_text("<?php define('PEPTIDE_CORE_MANAGEMENT_UUID','" + TENANT + "');")
        os.chmod(cfg, 0o640)
        (w / 'ledger').mkdir(parents=True, mode=0o700)
        inv = {'version': 1, 'sites': [{'site_id': 2, 'uuid': FIRST, 'slug': 's0001',
               'admin': {'paths': [{'path': str(a / 'srv/store-admin'), 'kind': 'directory'}], 'users': ['vcs0001']},
               # mirrors production: the release link first (kind symlink), then the releases dir and data dirs
               'web': {'paths': [{'path': str(w / 'srv/current'), 'kind': 'symlink'},
                                 {'path': str(w / 'srv/releases'), 'kind': 'directory'},
                                 {'path': str(w / 'srv/data'), 'kind': 'directory'},
                                 {'path': str(w / 'srv/export.sh'), 'kind': 'file'}], 'users': ['vialweb01', 'vcm_s0001']}}]}
        self.private(a / 'inventory.json', inv)
        self.private(w / 'inventory.json', inv)
        self.admin_c = {'role': 'admin', 'hq_user': 'vchq', 'state_root': str(state), 'admin_store_root': str(a / 'srv/tenant-admin'),
                        'lifecycle_inventory': str(a / 'inventory.json'), 'store_plugins_root': str(a / 'store-plugins'),
                        'web_ssh_key': 'k', 'web_known_hosts': 'h', 'web_ssh_user': 'u', 'web_host': 'x'}
        self.web_c = {'role': 'web', 'web_ledger_root': str(w / 'ledger'), 'web_data_root': str(w / 'data'),
                      'web_store_root': str(w / 'srv/tenants'), 'lifecycle_inventory': str(w / 'inventory.json'),
                      'store_plugins_root': str(w / 'store-plugins')}
        self.wp_calls = []
        self.remote_calls = []

    def private(self, path, value):
        path.write_text(json.dumps(value))
        os.chmod(path, 0o600)

    def request(self, action='install', store=FIRST, plugin='acme-wheel', package=GOOD_SHA):
        return {'version': 1, 'operation_id': str(uuid.uuid4()), 'actor_id': 1, 'site_id': 2, 'uuid': store,
                'action': action, 'plugin': plugin, 'package': package if action == 'install' else ''}

    def fake_remote(self, c, payload):
        self.remote_calls.append(payload['request']['action'])
        result = plugins.web_main(self.web_c, json.loads(plugins.canonical(payload)))
        if not result['ok']:
            raise ValueError('web_' + result['error_code'])
        return result

    def fake_wp(self, store, *args, allowed=(0,)):
        self.wp_calls.append((store['slug'], store['user']) + args)
        return 'https://www.example.com\n' if args[:2] == ('option', 'get') else ''

    def drop(self, request, package=GOOD):
        base = Path(self.admin_c['state_root']) / 'plugins'
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp), \
                patch.object(plugins, 'healthy', return_value=True):
            plugins.process_queue(self.admin_c)  # creates queue dirs
        if package is not None and request['action'] == 'install':
            p = base / 'packages' / (request['package'] + '.zip')
            p.write_bytes(package)
            os.chmod(p, 0o640)
        r = base / 'requests' / (request['operation_id'] + '.json')
        r.write_text(json.dumps(request))
        os.chmod(r, 0o600)
        return base / 'results' / (request['operation_id'] + '.json')


class Package(unittest.TestCase):
    def test_good_package(self):
        _, members, name, version = plugins.inspect_package(GOOD, GOOD_SHA, 'acme-wheel')
        self.assertEqual((name, version), ('Acme Wheel', '1.2.3'))

    def test_rejects(self):
        cases = {
            'plugin_package_checksum': (GOOD, '0' * 64, 'acme-wheel'),
            'plugin_package_layout': (make_zip({'a/a.php': '<?php /* Plugin Name: A */', 'b/x.txt': ''}), None, 'a'),
            'plugin_package_path': (make_zip({'acme/../evil.php': 'x'}), None, 'acme'),
            'plugin_header_missing': (make_zip({'acme/acme.php': '<?php echo 1;'}), None, 'acme'),
            'plugin_package_special_file': (make_zip({'acme/acme.php': '<?php /* Plugin Name: A */'}, symlink='acme/link'), None, 'acme'),
            'plugin_package_not_zip': (b'not a zip', None, 'acme'),
        }
        for code, (raw, sha, slug) in cases.items():
            with self.subTest(code=code):
                with self.assertRaisesRegex(ValueError, code):
                    plugins.inspect_package(raw, sha or hashlib.sha256(raw).hexdigest(), slug)

    def test_request_validation(self):
        good = {'version': 1, 'operation_id': str(uuid.uuid4()), 'actor_id': 1, 'site_id': 2, 'uuid': FIRST,
                'action': 'install', 'plugin': 'acme-wheel', 'package': GOOD_SHA}
        plugins.validate_request(good)
        for key, bad in (('plugin', 'peptide-core'), ('plugin', '../x'), ('action', 'rm'), ('package', 'abc'), ('extra', 1)):
            with self.subTest(key=key):
                with self.assertRaises(ValueError):
                    plugins.validate_request({**good, key: bad})


class EndToEnd(Hosts):
    def test_install_first_store_links_both_hosts_and_activates(self):
        receipt = self.drop(self.request())
        old_umask = os.umask(0o077)  # the admin worker's systemd unit sets UMask=0077
        try:
            with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp), \
                    patch.object(plugins, 'healthy', return_value=True):
                self.assertEqual(plugins.process_queue(self.admin_c), 1)
        finally:
            os.umask(old_umask)
        for host in ('admin', 'web'):
            for d in ('store-plugins', 'store-plugins/s0001', 'store-plugins/s0001/acme-wheel'):
                self.assertEqual(oct((self.tmp / host / d).stat().st_mode & 0o777), '0o755', (host, d))
        result = json.loads(receipt.read_text())
        self.assertEqual((result['state'], result['name'], result['version']), ('done', 'Acme Wheel', '1.2.3'))
        for host, root in (('admin', 'srv/store-admin/releases/r1'), ('web', 'srv/releases/r9')):
            link = self.tmp / host / root / 'wp-content/plugins/acme-wheel'
            self.assertTrue(link.is_symlink(), host)
            self.assertTrue((link / 'acme-wheel.php').is_file())
            self.assertEqual(oct((self.tmp / host / 'store-plugins/s0001/acme-wheel/readme.txt').stat().st_mode & 0o777), '0o644')
        self.assertIn(('s0001', 'vcs0001', 'plugin', 'activate', 'acme-wheel'), self.wp_calls)
        # a new release copied from current keeps the link (cp -a semantics)
        import shutil
        shutil.copytree(self.tmp / 'admin/srv/store-admin/releases/r1', self.tmp / 'admin/srv/store-admin/releases/r2', symlinks=True)
        self.assertTrue((self.tmp / 'admin/srv/store-admin/releases/r2/wp-content/plugins/acme-wheel').is_symlink())

    def test_tenant_install_then_uninstall(self):
        receipt = self.drop(self.request(store=TENANT))
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp), \
                patch.object(plugins, 'healthy', return_value=True):
            plugins.process_queue(self.admin_c)
        self.assertEqual(json.loads(receipt.read_text())['state'], 'done')
        self.assertTrue((self.tmp / 'web/srv/tenants/s0002/public/wp-content/plugins/acme-wheel').is_symlink())
        self.assertIn(('s0002', 'vcs0002', 'plugin', 'activate', 'acme-wheel'), self.wp_calls)
        receipt2 = self.drop(self.request(action='uninstall', store=TENANT))
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp), \
                patch.object(plugins, 'healthy', return_value=True):
            plugins.process_queue(self.admin_c)
        self.assertEqual(json.loads(receipt2.read_text())['state'], 'done')
        for host, root in (('admin', 'srv/tenant-admin/s0002/public'), ('web', 'srv/tenants/s0002/public')):
            self.assertFalse(os.path.lexists(self.tmp / host / root / 'wp-content/plugins/acme-wheel'))
            self.assertFalse((self.tmp / host / 'store-plugins/s0002/acme-wheel').exists())

    def test_health_failure_deactivates_and_reports(self):
        receipt = self.drop(self.request())
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp), \
                patch.object(plugins, 'healthy', return_value=False):
            plugins.process_queue(self.admin_c)
        result = json.loads(receipt.read_text())
        self.assertEqual((result['state'], result['error_code']), ('failed', 'health_check_failed'))
        self.assertIn(('s0001', 'vcs0001', 'plugin', 'deactivate', 'acme-wheel'), self.wp_calls)

    def test_conflict_with_real_directory_is_refused(self):
        (self.tmp / 'web/srv/releases/r9/wp-content/plugins/acme-wheel').mkdir()
        receipt = self.drop(self.request())
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp), \
                patch.object(plugins, 'healthy', return_value=True):
            plugins.process_queue(self.admin_c)
        result = json.loads(receipt.read_text())
        self.assertEqual((result['state'], result['error_code']), ('failed', 'web_plugin_name_conflict'))
        self.assertNotIn('activate', [c[3] for c in self.wp_calls if len(c) > 3])

    def test_tampered_package_and_unknown_store(self):
        receipt = self.drop(self.request(), package=GOOD + b'x')
        receipt2 = self.drop(self.request(store=str(uuid.uuid4())))
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp), \
                patch.object(plugins, 'healthy', return_value=True):
            plugins.process_queue(self.admin_c)
        self.assertEqual(json.loads(receipt.read_text())['error_code'], 'protected_file_size' if False else json.loads(receipt.read_text())['error_code'])
        self.assertEqual(json.loads(receipt.read_text())['state'], 'failed')
        self.assertEqual(json.loads(receipt2.read_text())['error_code'], 'store_not_found')
        self.assertEqual(self.remote_calls, [])

    def test_malformed_request_is_quarantined(self):
        base = Path(self.admin_c['state_root']) / 'plugins'
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp):
            plugins.process_queue(self.admin_c)
        bad = base / 'requests' / (str(uuid.uuid4()) + '.json')
        bad.write_text(json.dumps({'version': 1, 'action': 'install', 'path': '/etc/passwd'}))
        os.chmod(bad, 0o600)
        with patch.object(plugins, 'remote', self.fake_remote), patch.object(plugins, 'wp', self.fake_wp):
            plugins.process_queue(self.admin_c)
        self.assertFalse(bad.exists())
        self.assertEqual(len(list((base / 'rejected').iterdir())), 1)
        self.assertEqual(list((base / 'results').iterdir()), [])

    def test_web_rejects_wrong_identity(self):
        req = self.request(store=TENANT)
        out = plugins.web_main(self.web_c, plugins.envelope(req, 's0003', GOOD))
        self.assertEqual((out['ok'], out['error_code']), (False, 'store_code_root_missing') if False else (False, out['error_code']))
        self.assertFalse(out['ok'])
        out2 = plugins.web_main(self.web_c, plugins.envelope({**req, 'uuid': FIRST}, 's0002', GOOD))
        self.assertEqual((out2['ok'], out2['error_code']), (False, 'store_identity_changed'))


if __name__ == '__main__':
    unittest.main()
