"""Pure boundary checks. These tests do not execute provisioning commands."""
import copy
import contextlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch
import uuid

# Windows can run the pure policy/models without adding POSIX packages or a VM.
# OS enforcement itself is NOT certified by these fakes; live provisioning is never run.
if sys.platform == 'win32':
    sys.modules.setdefault('fcntl', types.SimpleNamespace(LOCK_EX=2, LOCK_NB=4, flock=lambda *a: None))
    sys.modules.setdefault('pwd', types.SimpleNamespace(getpwnam=lambda n: types.SimpleNamespace(pw_uid=0, pw_gid=0)))
    sys.modules.setdefault('grp', types.SimpleNamespace(getgrnam=lambda n: types.SimpleNamespace(gr_gid=0)))

spec = importlib.util.spec_from_file_location('worker', Path(__file__).with_name('worker.py'))
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)
REAL_LIFECYCLE_MODULE = w.lifecycle_module
TEST_ROOT = Path(os.environ.get('VIALCOVE_TEST_TMPDIR', Path(__file__).parent))
TEST_ROOT.mkdir(parents=True, exist_ok=True)


class Policy(unittest.TestCase):
    def setUp(self):
        self.stack = __import__('contextlib').ExitStack()
        self.addCleanup(self.stack.close)
        # A forgotten mock must never execute host commands or contact a server.
        self.stack.enter_context(patch.object(w, 'run', side_effect=AssertionError('unmocked command')))
        # The lifecycle broker is tested separately. Queue policy tests must not
        # import a live root broker or acquire host-wide deployment locks.
        self.lifecycle = types.SimpleNamespace(
            process_queue=Mock(return_value=False),
            register_provisioned=Mock(),
            load_json=Mock(side_effect=AssertionError('unexpected lifecycle state read')),
            lock=Mock(side_effect=lambda *a, **kw: contextlib.nullcontext()),
        )
        self.stack.enter_context(patch.object(w, 'lifecycle_module', return_value=self.lifecycle))
        if sys.platform == 'win32':
            self.stack.enter_context(patch.object(w.os, 'O_NOFOLLOW', 0, create=True))
            self.stack.enter_context(patch.object(w.os, 'chown', lambda *a: None, create=True))
            self.stack.enter_context(patch.object(w, 'sync_directory'))

    def request(self):
        return dict(version=2, operation_id=str(uuid.uuid4()), actor_id=1, title='测试店', login='shop_owner', email='owner@example.invalid', password='synthetic-password-123', customer_domain='example.com')

    def config(self, root=None):
        return dict(management_domain='admin.example.net', public_domain='platform.example.net', public_ip='192.0.2.1',
                    admin_ip='192.0.2.2', state_root=str(root or TEST_ROOT), hq_user='test', max_new_stores=4)

    def root_stat_model(self):
        """Model root/0700, never claim Windows ACL/flock/O_NOFOLLOW verification."""
        path_stat, fd_stat = Path.stat, os.fstat
        def private(s):
            values = list(s); values[0] &= ~0o077; values[4] = 0
            return os.stat_result(values)
        self.stack.enter_context(patch.object(w.Path, 'stat', lambda p, *a, **kw: private(path_stat(p, *a, **kw))))
        self.stack.enter_context(patch.object(w.os, 'fstat', lambda fd: private(fd_stat(fd))))

    def test_customer_domain_normalization_and_invalid_forms(self):
        for value in ['example.com', 'WWW.EXAMPLE.COM', ' Example.COM ', 'example.co.uk', 'xn--bcher-kva.example']:
            self.assertEqual(w.customer_domain(value), value.strip().lower().removeprefix('www.'))
        bad = ['', 'www.www.example.com', '*.example.com', 'https://example.com', 'example.com/', 'x@example.com',
               'example.com:443', 'example.com.', '127.0.0.1', '[::1]', 'bücher.example', 'a_b.example', '-a.example',
               'a-.example', 'a..example', 'example', 'a.' + 'b'*64, 'example.com\n', '\texample.com', '\u00a0example.com',
               'a'*64 + '.example.com', None, False, ['example.com']]
        for value in bad:
            with self.subTest(value=value), self.assertRaises(ValueError): w.customer_domain(value)
        with self.assertRaises(ValueError): w.customer_domain('.'.join(['a'*63]*4))

    def test_v2_requires_domain_and_fixed_schema(self):
        r = self.request(); r['customer_domain'] = 'WWW.EXAMPLE.COM'
        self.assertEqual(w.validate_request(r)['customer_domain'], 'example.com')
        for field in ['customer_domain', 'version']:
            r = self.request(); del r[field]
            with self.assertRaises(ValueError): w.validate_request(r)
        for version in [1, True, '2', 3]:
            r = self.request(); r['version'] = version
            with self.assertRaises(ValueError): w.validate_request(r)

    def test_public_names_do_not_rename_internal_identity(self):
        a, b = w.tenant('s0002', self.config(), 'example.com'), w.tenant('s0002', self.config(), 'www.other.org')
        for key in ['slug', 'user', 'db', 'management_url']: self.assertEqual(a[key], b[key])
        self.assertEqual(a['public_url'], 'https://www.example.com')
        self.assertEqual(b['public_url'], 'https://www.other.org')
        for base in ['platform.example.net', 'www.platform.example.net', 's0002.platform.example.net', 'admin.example.net']:
            with self.assertRaises(ValueError): w.tenant('s0002', self.config(), base)

    def test_two_dns_records_and_both_apex_www_required(self):
        t = w.tenant('s0002', self.config(), 'example.com')
        self.assertEqual(w.dns_records(t, self.config()), [dict(type='A',name='@',value='192.0.2.1',ttl=600),dict(type='A',name='www',value='192.0.2.1',ttl=600)])
        def resolver(host, port, family):
            if family == w.socket.AF_INET6: raise w.socket.gaierror()
            return [(None,None,None,None,('192.0.2.1' if host.startswith('www.') else '192.0.2.9',443))]
        with patch.object(w.socket, 'getaddrinfo', side_effect=resolver): self.assertFalse(w.customer_dns_ready(t, self.config()))
        def correct(host, port, family):
            if family == w.socket.AF_INET6: raise w.socket.gaierror()
            return [(None,None,None,None,('192.0.2.1',443))]
        with patch.object(w.socket, 'getaddrinfo', side_effect=correct): self.assertTrue(w.customer_dns_ready(t, self.config()))
        with patch.object(w.socket, 'getaddrinfo', return_value=[(None,None,None,None,('192.0.2.1',443))]): self.assertFalse(w.customer_dns_ready(t, self.config()))

    def test_claim_replay_cross_store_conflict_and_legacy_reservation(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            t = dict(w.tenant('s0002', self.config(), 'WWW.EXAMPLE.COM'), uuid=str(uuid.uuid4()))
            operation = str(uuid.uuid4())
            first = w.claim_domain(folder, t, operation)
            self.assertEqual(w.claim_domain(folder, t, operation), first)
            for changed in [dict(t, slug='s0003'), dict(t, uuid=str(uuid.uuid4()))]:
                with self.assertRaisesRegex(ValueError, 'domain_claim_conflict'): w.claim_domain(folder, changed, operation)
            with self.assertRaisesRegex(ValueError, 'domain_claim_conflict'): w.claim_domain(folder, t, str(uuid.uuid4()))
            legacy = dict(customer_domain='www.legacy.example.org', uuid=str(uuid.uuid4()), slug='s0001')
            self.assertEqual(w.reserve_legacy_domain(folder, legacy)['kind'], 'legacy')
            self.assertEqual(w.reserve_legacy_domain(folder, legacy)['slug'], 's0001')
            foreign = dict(w.tenant('s0004', self.config(), 'legacy.example.org'), uuid=legacy['uuid'])
            with self.assertRaisesRegex(ValueError, 'domain_claim_conflict'): w.claim_domain(folder, foreign, operation)
            self.assertEqual(len(list((Path(folder)/'domain-claims').glob('*.json'))), 2)
            self.assertFalse((Path(folder)/'jobs').exists())

    def test_claim_rejects_unsafe_directory_or_tampered_record(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            t = dict(w.tenant('s0002', self.config(), 'example.com'), uuid=str(uuid.uuid4()))
            with patch.object(w.Path, 'stat', return_value=types.SimpleNamespace(st_uid=1000, st_mode=stat.S_IFDIR|0o700)):
                with self.assertRaisesRegex(ValueError, 'domain_claim_directory'): w.claim_domain(folder,t,str(uuid.uuid4()))

    def test_completed_v1_replay_unchanged_and_new_v1_rejected(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); (root/'jobs').mkdir()
            legacy=self.request(); legacy['version']=1; del legacy['customer_domain']
            with self.assertRaisesRegex(ValueError, 'legacy_request_requires_domain'): w.accept_request(root,legacy)
            self.assertFalse((root/'jobs'/legacy['operation_id']).exists())
            job=root/'jobs'/legacy['operation_id']; job.mkdir()
            original=dict(legacy,password=''); (job/'request.json').write_text(json.dumps(original))
            (job/'state.json').write_text(json.dumps(dict(state='complete',slug='s0002',uuid=str(uuid.uuid4()))))
            before={p.name:p.read_bytes() for p in job.iterdir()}
            self.assertEqual(w.accept_request(root,legacy),job)
            self.assertEqual({p.name:p.read_bytes() for p in job.iterdir()},before)
            with self.assertRaisesRegex(ValueError,'request_changed'): w.accept_request(root,dict(legacy,title='changed'))

    def test_pending_v2_retry_binds_domain_and_original_request(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); (root/'jobs').mkdir(); r=self.request()
            job=w.accept_request(root,r)
            self.assertEqual(w.accept_request(root,dict(r,customer_domain='WWW.EXAMPLE.COM')),job)
            with self.assertRaisesRegex(ValueError,'request_changed'): w.accept_request(root,dict(r,customer_domain='other.org'))

    def test_native_domain_template_and_no_financial_code(self):
        t=w.tenant('s0002',self.config(),'example.com')
        php=w.domain_setup_php(t)
        for call in ['Domains::add(', 'Domains::check(', 'Domains::set_primary(', 'Domains::set_redirect(']: self.assertIn(call,php)
        self.assertIn('["www.example.com", "example.com"]',php)
        self.assertIn("$r['https']!=='active'",php)
        self.assertNotIn('update_option(',php)
        self.assertNotIn('payment',php)

    def test_waiting_dns_exposes_only_two_records_and_does_not_create_store(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); (root/'jobs').mkdir(); r=self.request(); job=w.accept_request(root,r)
            c=self.config(root)
            with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)), patch.object(w.grp,'getgrnam',return_value=types.SimpleNamespace(gr_gid=0)), patch.object(w.os,'chown',lambda *a:None,create=True), patch.object(w,'dns_ready',return_value=True), patch.object(w,'customer_dns_ready',return_value=False), patch.object(w,'admin_create') as create:
                w.process_queue(c)
                state=(job/'state.json').read_bytes()
                first=(root/'results'/ (r['operation_id']+'.json')).read_text(encoding='utf-8')
                w.process_queue(c)
                self.assertEqual((job/'state.json').read_bytes(),state)
                create.assert_not_called()
            result=json.loads(first)
            self.assertEqual(result['state'],'waiting_dns')
            self.assertEqual(result['customer_domain'],'example.com')
            self.assertEqual(result['dns_records'],w.dns_records({},c))
            self.assertNotIn('password',first)
            self.assertNotIn('registration_secret',first)
            self.assertNotIn('public_domain',first)
            self.assertNotIn('泛解析',result['message'])

    def test_completed_v1_queue_processing_does_not_touch_result_or_store(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); (root/'jobs').mkdir(); r=self.request(); r['version']=1; del r['customer_domain']
            job=root/'jobs'/r['operation_id']; job.mkdir()
            (job/'request.json').write_text(json.dumps(dict(r,password='')),encoding='utf-8')
            (job/'state.json').write_text(json.dumps(dict(state='complete',slug='s0002',uuid=str(uuid.uuid4()))))
            (root/'results').mkdir(); result=root/'results'/(r['operation_id']+'.json')
            result.write_text(json.dumps(dict(state='complete',public_url='https://s0002.platform.example.net/')))
            before=[(p,p.read_bytes()) for p in [job/'request.json',job/'state.json',result]]
            with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0)), patch.object(w,'admin_create') as create:
                w.process_queue(self.config(root)); create.assert_not_called()
            for p,value in before: self.assertEqual(p.read_bytes(),value)
            self.assertFalse((root/'domain-claims').exists())

    def test_vhost_model_exact_hosts_shared_code_no_apex_post_redirect(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder)
            for name in ['conf.d','sites-available','sites-enabled']: (root/'etc/nginx'/name).mkdir(parents=True,exist_ok=True)
            real_path=Path
            def mapped(*args):
                p=real_path(*args); name=str(p).replace('\\','/')
                return root/name.lstrip('/') if name.startswith(('/etc/nginx','/var/www/vialcove-tenant-acme')) else p
            t=dict(w.tenant('s0002',self.config(),'example.com'),public='/srv/tenants/s0002/public')
            with patch.object(w,'Path',side_effect=mapped), patch.object(Path,'symlink_to'), patch.object(w,'run',return_value=b'') as run:
                w.vhost(t,'www.example.com',aliases=('example.com',),tls=True)
            text=(root/'etc/nginx/sites-available/vialcove-s0002').read_text()
            self.assertEqual(text.count('server_name www.example.com example.com;'),2)
            self.assertIn('if ($host != $ssl_server_name)',text)
            self.assertIn('308 https://$host$request_uri',text)
            self.assertNotIn('308 https://www.example.com',text)
            self.assertIn('root /srv/tenants/s0002/public;',text)
            self.assertIn('ssl_certificate /etc/letsencrypt/live/www.example.com/fullchain.pem;',text)
            self.assertIn('fastcgi_pass unix:/run/php/vialcove-s0002.sock;',text)
            self.assertEqual(run.call_count,2)

    def test_vhost_rejects_upper_mixed_case_existing_hosts_in_ipv6_blocks(self):
        cases = [
            'server { listen [::]:443 ssl; server_name WWW.EXAMPLE.COM EXAMPLE.COM; }',
            '# Existing IPv6 tenant\nserver { listen [::]:80;\n server_name ExAmPlE.CoM # customer apex\n WwW.ExAmPlE.CoM; }',
            'server { listen [::]:443 ssl; server_name "WWW.EXAMPLE.COM"; # quoted DNS name\n}',
        ]
        for existing in cases:
            with self.subTest(existing=existing), tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
                root=Path(folder)
                for name in ['conf.d','sites-available','sites-enabled']: (root/'etc/nginx'/name).mkdir(parents=True,exist_ok=True)
                other=root/'etc/nginx/sites-enabled/another-tenant'
                other.write_text(existing,encoding='utf-8')
                real_path=Path
                def mapped(*args):
                    p=real_path(*args); name=str(p).replace('\\','/')
                    return root/name.lstrip('/') if name.startswith(('/etc/nginx','/var/www/vialcove-tenant-acme')) else p
                t=dict(w.tenant('s0002',self.config(),'example.com'),public='/srv/tenants/s0002/public')
                with patch.object(w,'Path',side_effect=mapped), patch.object(Path,'symlink_to'), patch.object(w,'run') as run:
                    with self.assertRaisesRegex(ValueError,'vhost_host_in_use'):
                        w.vhost(t,'www.example.com',aliases=('example.com',),tls=True)
                    run.assert_not_called()
                self.assertFalse((root/'etc/nginx/sites-available/vialcove-s0002').exists())
                self.assertEqual(other.read_text(encoding='utf-8'),existing)

    def test_vhost_ignores_commented_server_names(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder)
            for name in ['conf.d','sites-available','sites-enabled']: (root/'etc/nginx'/name).mkdir(parents=True,exist_ok=True)
            (root/'etc/nginx/sites-enabled/another-tenant').write_text('# server_name WWW.EXAMPLE.COM EXAMPLE.COM;\nserver { listen [::]:443 ssl; server_name OTHER.EXAMPLE.ORG; }')
            real_path=Path
            def mapped(*args):
                p=real_path(*args); name=str(p).replace('\\','/')
                return root/name.lstrip('/') if name.startswith(('/etc/nginx','/var/www/vialcove-tenant-acme')) else p
            t=dict(w.tenant('s0002',self.config(),'example.com'),public='/srv/tenants/s0002/public')
            with patch.object(w,'Path',side_effect=mapped), patch.object(Path,'symlink_to'), patch.object(w,'run',return_value=b''):
                w.vhost(t,'www.example.com',aliases=('example.com',),tls=True)
            self.assertTrue((root/'etc/nginx/sites-available/vialcove-s0002').exists())

    def test_certificate_requests_and_checks_two_names(self):
        t=dict(w.tenant('s0002',self.config(),'example.com'),server_ip='192.0.2.1')
        calls=[]
        with patch.object(w,'dns_ready',return_value=True), patch.object(w,'vhost') as vhost, patch.object(w.Path,'exists',return_value=False), patch.object(w,'run',side_effect=lambda args,**kw:calls.append(args) or b''):
            w.certificate(t,'www.example.com',aliases=('example.com',))
        vhost.assert_called_once_with(t,'www.example.com',aliases=('example.com',))
        cert=next(args for args in calls if args[0]=='certbot')
        self.assertEqual([cert[i+1] for i,s in enumerate(cert) if s=='-d'],['www.example.com','example.com'])
        self.assertEqual([args[4] for args in calls if args[0]=='openssl'],['www.example.com','example.com'])
        with patch.object(w,'dns_ready',return_value=False), patch.object(w,'vhost') as vhost:
            with self.assertRaisesRegex(ValueError,'waiting_dns'): w.certificate(t,'www.example.com',aliases=('example.com',))
            vhost.assert_not_called()

    def test_remote_dns_gate_before_users_sql_or_runtime_copy(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder)
            c=dict(self.config(root),role='web',web_ledger_root=str(root/'ledger'),web_data_root=str(root/'data'),web_store_root=str(root/'stores'))
            p=dict(request=self.request(),slug='s0002',uuid=str(uuid.uuid4()),media_key='synthetic-public-key',registration_secret='a'*64)
            with patch.object(w,'customer_dns_ready',return_value=False), patch.object(w,'make_user') as users, patch.object(w,'copy_runtime') as runtime, patch.object(w.fcntl,'flock') as lock:
                with self.assertRaisesRegex(ValueError,'waiting_dns'): w.web_create(p,c)
                users.assert_not_called(); runtime.assert_not_called(); lock.assert_called_once()
            self.assertFalse((root/'data').exists())
            self.assertFalse((root/'stores').exists())
            self.assertEqual(len(list((root/'ledger/domain-claims').glob('*.json'))),1)

    def test_valid(self):
        self.assertEqual(w.validate_request(self.request())['login'], 'shop_owner')

    def test_invalid_inputs(self):
        changes = [('operation_id', '../escape'), ('operation_id', str(uuid.uuid1())), ('actor_id', 0), ('actor_id', '1'),
                   ('title', 'x\ncommand'), ('title', '<script>'), ('title', 'x'*101), ('login', '../root'), ('login', 'a;id'),
                   ('email', 'a@example.invalid\nBcc:other@example.invalid'), ('email', ['a']), ('password', 'short'),
                   ('password', 'x'*129), ('password', 'x'*14+'\x00')]
        for field, value in changes:
            with self.subTest(field=field, value=repr(value)):
                r = self.request(); r[field] = value
                with self.assertRaises((ValueError, TypeError, AttributeError)):
                    w.validate_request(r)
        r = self.request(); r['command'] = 'id'
        with self.assertRaises(ValueError): w.validate_request(r)

    def test_slug_scope(self):
        c = dict(management_domain='admin.example.invalid', public_domain='shop.example.invalid')
        self.assertEqual(w.tenant('s0002', c)['db'], 'vc_s0002')
        for slug in ['s0001', 's0000', 's0002/../hq', 's2', 's0002;id', 's10000', 'S0002']:
            with self.subTest(slug=slug), self.assertRaises(ValueError): w.tenant(slug, c)

    def test_dns_requires_exact_target(self):
        with patch.object(w.socket, 'getaddrinfo', return_value=[(None,None,None,None,('192.0.2.1',443)),(None,None,None,None,('192.0.2.2',443))]):
            self.assertFalse(w.dns_ready('shop.example.invalid', '192.0.2.1'))
        with patch.object(w.socket, 'getaddrinfo', return_value=[(None,None,None,None,('192.0.2.1',443))]):
            self.assertTrue(w.dns_ready('shop.example.invalid', '192.0.2.1'))

    def test_runtime_never_copies_tenant_data_or_source_symlinks(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as root:
            root=Path(root); source=root/'source'; source.mkdir()
            (source/'wp-config.php').write_text('private config')
            (source/'wp-settings.php').write_text('code')
            (source/'wp-content/mu-plugins').mkdir(parents=True)
            (source/'wp-content/mu-plugins/vialcove-migration-guard.php').write_text('old tenant gate')
            (source/'wp-content/uploads').mkdir(); (source/'wp-content/uploads/private-image').write_text('tenant image')
            (source/'tests').mkdir(); (source/'tests/fixture').write_text('fixture')
            target=root/'target'; w.copy_runtime(source,target)
            self.assertFalse((target/'wp-config.php').exists())
            self.assertFalse((target/'wp-content/uploads').exists())
            self.assertFalse((target/'tests').exists())
            self.assertFalse((target/'wp-content/mu-plugins/vialcove-migration-guard.php').exists())
            self.assertEqual((target/'wp-settings.php').read_text(), 'code')
            self.assertTrue((target/'wp-content/mu-plugins/vialcove-mail-gate.php').exists())
            with self.assertRaises(ValueError): w.copy_runtime(source,target)

    def test_configuration_literal_and_cookie_isolation(self):
        t=dict(db='vc_s0002',uuid=str(uuid.uuid4()),management_url='https://s0002.admin.example.invalid',data=Path('/srv/tenant/s0002'))
        cfg=w.configuration(t,dict(hq_url='https://hq.example.invalid'), 'vcf_s0002', '0'*64, {}, '1'*64)
        self.assertIn("'COOKIE_DOMAIN',false",cfg)
        self.assertNotIn('WP_HOME',cfg)
        self.assertIn("'WP_SITEURL','https://s0002.admin.example.invalid'",cfg)
        self.assertNotIn('CREATE USER',cfg)

    def test_lifecycle_receipts_sync_before_new_provisioning(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            c=dict(self.config(folder),hq_root=str(Path(folder)/'hq'))
            order=[]
            self.lifecycle.process_queue.side_effect=lambda *a,**kw: order.append(('lifecycle',kw)) or True
            with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)), \
                    patch.object(w,'run',side_effect=lambda args,**kw: order.append(('sync',args,kw)) or b''):
                w.process_queue(c)
            self.assertEqual(order[0],('lifecycle',{'lock_held':True}))
            self.assertEqual(len(order),2)
            self.assertEqual(order[1][1],['/usr/local/bin/wp','--path='+c['hq_root'],'eval','PeptideHq\\Site_Lifecycle::sync();'])
            self.assertEqual(order[1][2],{'user':'test'})
            self.assertEqual(len(self.lifecycle.lock.call_args_list),2)
            self.assertTrue(all(call.kwargs=={'nonblocking':True} for call in self.lifecycle.lock.call_args_list))
            self.lifecycle.register_provisioned.assert_not_called()

    def test_lifecycle_dynamic_import_rejects_untrusted_file_or_parent(self):
        trusted_file=types.SimpleNamespace(st_uid=0,st_mode=stat.S_IFREG|0o644)
        trusted_parent=types.SimpleNamespace(st_uid=0,st_mode=stat.S_IFDIR|0o755)
        unsafe=[
            (types.SimpleNamespace(st_uid=1000,st_mode=stat.S_IFREG|0o644),trusted_parent),
            (types.SimpleNamespace(st_uid=0,st_mode=stat.S_IFREG|0o664),trusted_parent),
            (types.SimpleNamespace(st_uid=0,st_mode=stat.S_IFLNK|0o777),trusted_parent),
            (trusted_file,types.SimpleNamespace(st_uid=1000,st_mode=stat.S_IFDIR|0o755)),
            (trusted_file,types.SimpleNamespace(st_uid=0,st_mode=stat.S_IFDIR|0o775)),
        ]
        for entry,parent in unsafe:
            with self.subTest(file=entry,parent=parent),patch.object(w.Path,'lstat',return_value=entry), \
                    patch.object(w.Path,'stat',return_value=parent), \
                    patch.object(w.importlib.util,'spec_from_file_location') as loader:
                with self.assertRaisesRegex(ValueError,'lifecycle_module_owner'): REAL_LIFECYCLE_MODULE()
                loader.assert_not_called()

    def test_lifecycle_dynamic_import_only_executes_trusted_sibling(self):
        module=types.SimpleNamespace(marker='synthetic module; no code executed')
        loader=types.SimpleNamespace(exec_module=Mock())
        spec=types.SimpleNamespace(loader=loader)
        with patch.object(w.Path,'lstat',return_value=types.SimpleNamespace(st_uid=0,st_mode=stat.S_IFREG|0o644)), \
                patch.object(w.Path,'stat',return_value=types.SimpleNamespace(st_uid=0,st_mode=stat.S_IFDIR|0o755)), \
                patch.object(w.importlib.util,'spec_from_file_location',return_value=spec) as locate, \
                patch.object(w.importlib.util,'module_from_spec',return_value=module):
            self.assertIs(REAL_LIFECYCLE_MODULE(),module)
        self.assertEqual(locate.call_args.args[1],Path(w.__file__).resolve().with_name('lifecycle.py'))
        loader.exec_module.assert_called_once_with(module)

    def test_lifecycle_active_without_history_does_not_load_broker(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            identity=str(uuid.uuid4())
            for role in ['admin','web']:
                c=dict(self.config(folder),role=role,web_ledger_root=str(Path(folder)/'web-ledger'))
                with patch.object(w,'lifecycle_module') as load:
                    self.assertTrue(w.lifecycle_active(c,identity))
                    load.assert_not_called()

    def test_lifecycle_active_uses_selected_role_root_and_exact_identity(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); identity=str(uuid.uuid4()); admin=root/'admin';web=root/'web'
            for role,expected in [('admin',admin),('web',web)]:
                with self.subTest(role=role):
                    current=expected/'lifecycle/tenants'/identity/'current.json';current.parent.mkdir(parents=True);current.write_text('{}',encoding='utf-8')
                    c=dict(self.config(admin),role=role,web_ledger_root=str(web))
                    self.lifecycle.load_json.side_effect=None
                    for state in ['active','retained','offlining','restoring','purging','purged','failed','unexpected']:
                        self.lifecycle.load_json.return_value=dict(uuid=identity,state=state)
                        self.assertEqual(w.lifecycle_active(c,identity),state=='active')
                        self.lifecycle.load_json.assert_called_with(current,private=True)
                    self.lifecycle.load_json.return_value=dict(uuid=str(uuid.uuid4()),state='active')
                    with self.assertRaisesRegex(ValueError,'lifecycle_identity'):w.lifecycle_active(c,identity)

    def test_lifecycle_active_corrupt_or_unreadable_history_stays_closed(self):
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            identity=str(uuid.uuid4());c=dict(self.config(folder),role='admin')
            current=Path(folder)/'lifecycle/tenants'/identity/'current.json';current.parent.mkdir(parents=True);current.write_text('synthetic corrupt json',encoding='utf-8')
            for error in [ValueError('invalid_json'),PermissionError('synthetic denied'),OSError('synthetic truncated read')]:
                with self.subTest(error=type(error).__name__):
                    self.lifecycle.load_json.side_effect=error
                    with self.assertRaises(type(error)):w.lifecycle_active(c,identity)
            self.lifecycle.load_json.side_effect=None
            for state in [None,{},[],{'state':'active'},{'uuid':identity}]:
                with self.subTest(state=state):
                    self.lifecycle.load_json.return_value=state
                    try:active=w.lifecycle_active(c,identity)
                    except (ValueError,AttributeError):active=False
                    self.assertFalse(active)
            for invalid in ['../escape','AAAAAAAA-BBBB-4CCC-8DDD-111111111111',False,None]:
                with self.subTest(identity=invalid),self.assertRaises((ValueError,TypeError,AttributeError)):w.lifecycle_active(c,invalid)

    def test_web_retired_identity_blocks_all_replayed_creation_commands(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder);identity=str(uuid.uuid4())
            c=dict(self.config(root),role='web',web_ledger_root=str(root/'ledger'),web_data_root=str(root/'data'),web_store_root=str(root/'stores'))
            current=root/'ledger/lifecycle/tenants'/identity/'current.json';current.parent.mkdir(parents=True);current.write_text('{}',encoding='utf-8')
            self.lifecycle.load_json.side_effect=None;self.lifecycle.load_json.return_value=dict(uuid=identity,state='purged')
            payload=dict(request=self.request(),slug='s0002',uuid=identity,media_key='synthetic-public-key',registration_secret='a'*64)
            with patch.object(w,'make_user') as users,patch.object(w,'copy_runtime') as runtime,patch.object(w,'customer_dns_ready') as dns,patch.object(w,'run') as command:
                with self.assertRaisesRegex(ValueError,'store_retired'):w.web_create(payload,c)
                users.assert_not_called();runtime.assert_not_called();dns.assert_not_called();command.assert_not_called()
            self.assertFalse((root/'data').exists());self.assertFalse((root/'stores').exists());self.assertFalse((root/'ledger/domain-claims').exists())

    def test_unchanged_lifecycle_does_not_boot_wordpress(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder, \
                patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)), \
                patch.object(w,'run') as command:
            w.process_queue(self.config(folder))
            self.lifecycle.process_queue.assert_called_once_with(self.config(folder),lock_held=True)
            command.assert_not_called()

    def test_retired_pending_job_cannot_recreate_resources(self):
        self.root_stat_model()
        for lifecycle_state in ['retained','offlining','restoring','purged','failed']:
            with self.subTest(state=lifecycle_state), tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
                root=Path(folder); (root/'jobs').mkdir(); r=self.request(); job=w.accept_request(root,r)
                identity=str(uuid.uuid4()); state=dict(state='pending',slug='s0002',uuid=identity,customer_domain='example.com')
                (job/'state.json').write_text(json.dumps(state),encoding='utf-8')
                current=root/'lifecycle/tenants'/identity/'current.json'; current.parent.mkdir(parents=True)
                current.write_text('{}',encoding='utf-8')
                self.lifecycle.load_json.side_effect=None
                self.lifecycle.load_json.return_value=dict(uuid=identity,state=lifecycle_state)
                with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)), \
                        patch.object(w.grp,'getgrnam',return_value=types.SimpleNamespace(gr_gid=0)), \
                        patch.object(w,'admin_create') as create, patch.object(w,'dns_ready') as dns, patch.object(w,'run') as command:
                    w.process_queue(self.config(root))
                create.assert_not_called(); dns.assert_not_called(); command.assert_not_called()
                self.lifecycle.register_provisioned.assert_not_called()
                self.assertEqual(json.loads((job/'state.json').read_text(encoding='utf-8'))['state'],'failed')
                self.assertEqual(json.loads((root/'results'/(r['operation_id']+'.json')).read_text(encoding='utf-8'))['state'],'failed')
                self.assertFalse((root/'domain-claims').exists())

    def test_confirmed_new_store_registered_before_password_scrub_and_complete(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); (root/'jobs').mkdir(); r=self.request(); job=w.accept_request(root,r)
            c=dict(self.config(root),hq_root=str(root/'hq')); order=[]
            def create(request,state,config,secret):
                order.append('create')
                return dict(w.tenant(state['slug'],config,request['customer_domain']),uuid=state['uuid'])
            def confirm(t,config,jobpath): order.append('confirm'); return 12
            def register(config,t,request,state,site_id):
                order.append('register')
                self.assertEqual(site_id,12)
                self.assertEqual(json.loads((job/'state.json').read_text(encoding='utf-8'))['state'],'pending')
                self.assertEqual(json.loads((job/'request.json').read_text(encoding='utf-8'))['password'],r['password'])
                self.assertEqual(t['uuid'],state['uuid'])
            self.lifecycle.register_provisioned.side_effect=register
            with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)), \
                    patch.object(w.grp,'getgrnam',return_value=types.SimpleNamespace(gr_gid=0)), \
                    patch.object(w,'dns_ready',return_value=True),patch.object(w,'customer_dns_ready',return_value=True), \
                    patch.object(w,'admin_create',side_effect=create),patch.object(w,'confirm_hq',side_effect=confirm), \
                    patch.object(w,'run',return_value=b'a'*64):
                w.process_queue(c)
            self.assertEqual(order,['create','confirm','register'])
            self.assertEqual(json.loads((job/'state.json').read_text(encoding='utf-8'))['state'],'complete')
            self.assertEqual(json.loads((job/'request.json').read_text(encoding='utf-8'))['password'],'')
            self.assertEqual(json.loads((root/'results'/(r['operation_id']+'.json')).read_text(encoding='utf-8'))['site_id'],12)

    def template_fixture(self, root):
        source=root/'source'; source.mkdir(); uploads=source/'uploads'; uploads.mkdir()
        image=uploads/'synthetic.png'; image.write_bytes(b'synthetic-template-image-not-a-customer-upload')
        bundle=root/'template.json'; data=dict(version=1,documents={key:[] for key in ['home','header','footer','global']},images=[dict(old_id=7,file=str(image),title='Synthetic template image',alt='Synthetic')])
        bundle.write_text(json.dumps(data),encoding='utf-8'); bundle.chmod(0o600)
        t=dict(public=root/'target',data=root/'data',user='synthetic')
        c=dict(source_web_root=str(source),source_uploads=str(uploads),template_bundle=str(bundle))
        t['management_url']='https://s0002.admin.example.invalid'
        return t,c,data

    def test_template_bundle_never_exports_first_store_and_reuses_image_paths(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); t,c,data=self.template_fixture(root); payloads=[]; calls=[]
            def importer(args,**kw):
                calls.append(args)
                payloads.append(json.loads(Path(kw['extra_env']['VIALCOVE_TEMPLATE_FILE']).read_text(encoding='utf-8')))
                return b'Imported synthetic layout'
            with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)), \
                    patch.object(w.grp,'getgrnam',return_value=types.SimpleNamespace(gr_gid=0)),patch.object(w,'run',side_effect=importer):
                w.seed_template(t,c); first={p.name:p.read_bytes() for p in (t['data']/'uploads/master').iterdir()}
                w.seed_template(t,c)
            self.assertEqual({p.name:p.read_bytes() for p in (t['data']/'uploads/master').iterdir()},first)
            self.assertEqual(list(first),['master-7.png'])
            self.assertEqual(payloads[0],payloads[1])
            self.assertEqual(payloads[0]['images'][0]['target'],'master-7.png')
            self.assertNotIn('file',payloads[0]['images'][0])
            self.assertFalse((t['data']/'template.json').exists())
            self.assertEqual(len(calls),2)
            self.assertTrue(all(args[-1]=='/usr/local/lib/vialcove-provision/import-template.php' for args in calls))
            self.assertTrue(all('--path='+c['source_web_root'] not in args for args in calls))
            self.assertFalse(any(any(token in {'mysql','mariadb','db','export'} for token in args) for args in calls))

    def test_template_bundle_rejects_untrusted_owner_links_permissions_and_size(self):
        self.root_stat_model()
        for field,value in [('st_uid',1000),('st_nlink',2),('st_mode',stat.S_IFREG|0o622),('st_size',2*1024**2)]:
            with self.subTest(field=field),tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
                t,c,data=self.template_fixture(Path(folder))
                with Path(c['template_bundle']).open('rb') as handle: entry=os.fstat(handle.fileno())
                # Model precisely the trust predicate; Windows ACL/O_NOFOLLOW is not certified here.
                values=list(entry); values[dict(st_uid=4,st_nlink=3,st_mode=0,st_size=6)[field]]=value
                with patch.object(w.os,'fstat',return_value=os.stat_result(values)),patch.object(w,'run') as command:
                    with self.assertRaisesRegex(ValueError,'template_bundle_owner'): w.seed_template(t,c)
                    command.assert_not_called()
                self.assertFalse(t['data'].exists())

    def test_template_bundle_rejects_outside_media_and_master_as_destination(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            root=Path(folder); t,c,data=self.template_fixture(root); foreign=root/'foreign.png';foreign.write_bytes(b'synthetic foreign bytes')
            data['images'][0]['file']=str(foreign);Path(c['template_bundle']).write_text(json.dumps(data))
            with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)),patch.object(w,'run') as command:
                with self.assertRaisesRegex(ValueError,'template_source_path'): w.seed_template(t,c)
                command.assert_not_called()
                self.assertFalse(list((t['data']/'uploads/master').iterdir()))
                with self.assertRaisesRegex(ValueError,'master_is_target'): w.seed_template(dict(t,public=Path(c['source_web_root'])),c)

    def test_template_payload_removed_when_import_fails(self):
        self.root_stat_model()
        with tempfile.TemporaryDirectory(dir=TEST_ROOT) as folder:
            t,c,data=self.template_fixture(Path(folder))
            with patch.object(w.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0)), \
                    patch.object(w.grp,'getgrnam',return_value=types.SimpleNamespace(gr_gid=0)), \
                    patch.object(w,'run',side_effect=RuntimeError('synthetic import failure')):
                with self.assertRaisesRegex(RuntimeError,'synthetic import failure'): w.seed_template(t,c)
            self.assertFalse((t['data']/'template.json').exists())


if __name__ == '__main__': unittest.main()
