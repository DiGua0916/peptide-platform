"""Pure boundary checks. These tests do not execute provisioning commands."""
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import uuid

spec = importlib.util.spec_from_file_location('worker', Path(__file__).with_name('worker.py'))
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)


class Policy(unittest.TestCase):
    def request(self):
        return dict(version=1, operation_id=str(uuid.uuid4()), actor_id=1, title='测试店', login='shop_owner', email='owner@example.invalid', password='synthetic-password-123')

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
        with tempfile.TemporaryDirectory() as root:
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


if __name__ == '__main__': unittest.main()
