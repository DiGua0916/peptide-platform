"""Backup-plan models only. No archive, SQL dump, server or customer read."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
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
def load(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(name + '.py'))
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module); return module
b, engine = load('backup'), load('lifecycle')
installer = load('install-operations')
TEST_ROOT = Path(os.environ.get('VIALCOVE_TEST_TMPDIR',
    'D:/Codex/Tasks/2026-11-03/store-lifecycle/work' if sys.platform == 'win32' else tempfile.gettempdir()))
TEST_ROOT.mkdir(parents=True, exist_ok=True)


class BackupModels(unittest.TestCase):
    def setUp(self):
        self.stack = __import__('contextlib').ExitStack(); self.addCleanup(self.stack.close)
        self.stack.enter_context(patch.object(b.subprocess, 'run', side_effect=AssertionError('external command forbidden')))
        self.stack.enter_context(patch.object(b.subprocess, 'Popen', side_effect=AssertionError('archive forbidden')))
        self.temp = self.stack.enter_context(tempfile.TemporaryDirectory(dir=TEST_ROOT))
        self.root = Path(self.temp)
        for name in ('ledger', 'admin', 'data', 'control'): (self.root / name).mkdir()
        self.config = dict(role='web', web_ledger_root=str(self.root/'ledger'), state_root=str(self.root/'control'),
                           web_data_root=str(self.root/'data'), admin_store_root=str(self.root/'admin'),
                           lifecycle_inventory=str(self.root/'inventory.json'))
        self.sites = []
        self.engine = types.SimpleNamespace(valid_uuid=engine.valid_uuid, require=engine.require,
            load_json=lambda p, **kw: json.loads(Path(p).read_text()), validate_inventory=lambda x: x,
            no_symlink_components=engine.no_symlink_components, INVENTORY_PATH=self.config['lifecycle_inventory'])
        # Synthetic Windows ACL/uid model; real enforcement is tested natively
        # by the deployment reviewer, never certified by this stub.
        real_stat = b.Path.stat
        def private_stat(path, *a, **kw):
            values = list(real_stat(path, *a, **kw)); values[4] = 0; values[0] &= ~0o022
            return os.stat_result(values)
        self.stack.enter_context(patch.object(b.Path, 'stat', private_stat))

    def save(self, path, value):
        path = Path(path); path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(value)); return path

    def site(self, slug, state='active', legacy=False):
        identity = str(uuid.uuid4()); retirement = str(uuid.uuid4())
        data = self.root/'data'/slug; data.mkdir()
        entry = {'slug': slug, 'uuid': identity,
                 'web': {'db': {'name': 'historical_first_db' if legacy else 'vc_'+slug},
                         'paths': [{'path': str(data), 'kind': 'directory'}]},
                 'admin': {'identity_files': []}}
        self.sites.append(entry)
        if slug != 's0001': self.save(self.root/'ledger'/(slug+'.json'), {'phase':'domains'})
        history = self.root/'ledger/lifecycle/tenants'/identity/'current.json'
        self.save(history, dict(uuid=identity, state=state, retirement_id=retirement))
        self.save(self.root/'inventory.json', {'version':1, 'sites':self.sites})
        return entry, history, retirement

    def test_active_and_retained_dump_but_purged_missing_db_is_not_scheduled(self):
        self.site('s0001', legacy=True); self.site('s0002', 'retained'); self.site('s0003','purged')
        plan = b.backup_plan(self.config, self.engine)
        self.assertEqual(plan['databases'], {'s0001':'historical_first_db','s0002':'vc_s0002'})
        self.assertEqual(plan['skipped_purging_or_purged'], ['s0003'])
        self.assertNotIn(str(self.root/'data/s0003').lstrip('/'), plan['files'])
        self.assertIn(str(self.root/'ledger/lifecycle').lstrip('/'), plan['files'])
        self.assertIn(str(self.root/'inventory.json').lstrip('/'), plan['files'])

    def test_failed_partial_purge_does_not_dump_deleted_database(self):
        entry, history, retirement = self.site('s0002', 'failed')
        self.save(history.parent/retirement/'purge-ownership.json', {'binding':'synthetic'})
        plan = b.backup_plan(self.config, self.engine)
        self.assertEqual(plan['databases'], {})
        self.assertEqual(plan['skipped_purging_or_purged'], ['s0002'])

    def test_orphan_ledgers_keep_original_backup_without_hq_registration(self):
        self.save(self.root/'ledger/s0002.json', {'phase':'domains'})
        (self.root/'data/s0002').mkdir()
        self.save(self.root/'inventory.json', {'version':1,'sites':[]})
        plan = b.backup_plan(self.config, self.engine)
        self.assertEqual(plan['databases'], {'s0002':'vc_s0002'})
        self.assertIn(str(self.root/'data/s0002').lstrip('/'), plan['files'])

    def test_bad_lifecycle_identity_cannot_report_successful_backup(self):
        entry, history, retirement = self.site('s0002')
        self.save(history, dict(uuid=str(uuid.uuid4()),state='purged',retirement_id=retirement))
        with self.assertRaisesRegex(ValueError, 'backup_lifecycle_state'): b.backup_plan(self.config,self.engine)

    def test_admin_checkpoints_and_tombstones_included_without_sql(self):
        config = dict(self.config, role='admin')
        (self.root/'control/lifecycle').mkdir()
        self.save(self.root/'inventory.json', {'version':1,'sites':[]})
        plan = b.backup_plan(config,self.engine)
        self.assertEqual(plan['databases'], {})
        self.assertIn(str(self.root/'control/lifecycle').lstrip('/'),plan['files'])
        self.assertIn(str(self.root/'control/jobs').lstrip('/'),plan['files'])

    def test_only_fixed_private_template_versions_deduplicated(self):
        base = self.root/'templates'; version = base/'reviewed-v1'
        source = version/'source'; source.mkdir(parents=True)
        bundle = self.save(version/'template.json', {'version':1})
        (source/'index.php').write_text('<?php // synthetic code only')
        (version/'images').mkdir()
        c = dict(source_web_root=str(source), template_bundle=str(bundle),
                 source_uploads=str(version/'images'), source_admin_root=str(self.root/'admin'))
        real_lstat = b.Path.lstat
        def owned(path, *a, **kw):
            values=list(real_lstat(path,*a,**kw)); values[4]=0; values[0] &= ~0o022
            return os.stat_result(values)
        with patch.object(b.Path,'lstat',owned):
            self.assertEqual(b.template_snapshots(c,self.engine,base=base),[version])
        self.assertEqual(b.template_snapshots({'source_web_root':str(self.root/'admin')},self.engine,base=base),[])
        def symlink(path,*a,**kw):
            values=list(owned(path,*a,**kw))
            if path == source/'index.php': values[0]=stat.S_IFLNK|0o777
            return os.stat_result(values)
        with patch.object(b.Path,'lstat',symlink), self.assertRaisesRegex(ValueError,'backup_template_ownership'):
            b.template_snapshots(c,self.engine,base=base)

    def test_installer_never_reactivates_orphan_when_inventory_present(self):
        worker = types.SimpleNamespace(lifecycle_active=lambda *a: (_ for _ in ()).throw(AssertionError('orphan state read')))
        self.assertFalse(installer.may_restore_cron(True, [], worker, self.config))
        self.assertTrue(installer.may_restore_cron(False, [], worker, self.config))

    def test_installer_only_restores_known_active_tenant_cron(self):
        identity = str(uuid.uuid4()); matches=[{'uuid':identity}]
        calls=[]
        worker=types.SimpleNamespace(lifecycle_active=lambda config,value: calls.append(value) or False)
        self.assertFalse(installer.may_restore_cron(True,matches,worker,self.config))
        self.assertEqual(calls,[identity])
        worker.lifecycle_active=lambda *a:True
        self.assertTrue(installer.may_restore_cron(True,matches,worker,self.config))


if __name__ == '__main__': unittest.main()
