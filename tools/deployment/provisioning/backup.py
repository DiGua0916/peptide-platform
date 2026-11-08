#!/usr/bin/python3 -I
"""Encrypted backup of broker state and each newly provisioned store. Root only."""
import datetime
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import stat


def lifecycle_module():
    path = Path(__file__).resolve().with_name('lifecycle.py')
    info = path.lstat()
    assert stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022
    assert path.parent.stat().st_uid == 0 and not path.parent.stat().st_mode & 0o022
    spec = importlib.util.spec_from_file_location('vialcove_backup_lifecycle', path)
    engine = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(engine)
    return engine


def retained_state(c, engine, identity):
    engine.valid_uuid(identity)
    root = Path(c['web_ledger_root'] if c['role'] == 'web' else c['state_root'])
    path = root / 'lifecycle/tenants' / identity / 'current.json'
    if not path.exists():
        return 'active'
    state = engine.load_json(path, private=True)
    engine.require(state.get('uuid') == identity and state.get('state') in
                   {'active', 'offlining', 'retained', 'restoring', 'purging', 'purged', 'failed'},
                   'backup_lifecycle_state')
    if state['state'] == 'failed':
        retirement = engine.valid_uuid(state['retirement_id'], 'retirement_id', 4)
        if (path.parent / retirement / 'purge-ownership.json').exists():
            return 'purging'  # A partial destructive phase is not a complete DB.
    return state['state']


def template_snapshots(c, engine, *, base=Path('/srv/vialcove-provision-template')):
    """Only root-owned, symlink-free immutable versions at the fixed base.

    Include the version directory once, not arbitrary profile-selected paths.
    These snapshots contain source/layout/images; no SQL dump is created here.
    """
    base = Path(base)  # The production caller never overrides the fixed base.
    selected = set()
    for key in ('source_admin_root', 'source_web_root', 'source_uploads', 'template_bundle'):
        value = c.get(key)
        if not value:
            continue
        path = Path(value)
        if not path.is_relative_to(base):
            continue  # Historical live-store sources are not copied as templates.
        relative = path.relative_to(base)
        engine.require(len(relative.parts) >= 2 and relative.parts[0] not in {'', '.', '..'},
                       'backup_template_version')
        version = base / relative.parts[0]
        engine.no_symlink_components(path)
        engine.require(path.exists(), 'backup_template_source_missing')
        selected.add(version)
    for version in selected:
        engine.no_symlink_components(version)
        for path in [base, version, *version.rglob('*')]:
            info = path.lstat()
            engine.require(not stat.S_ISLNK(info.st_mode) and (stat.S_ISREG(info.st_mode)
                           or stat.S_ISDIR(info.st_mode)) and info.st_uid == 0
                           and not info.st_mode & 0o022, 'backup_template_ownership')
    return sorted(selected)


def backup_plan(c, engine):
    """Metadata-only plan; no mysqldump, customer read, deletion or archive IO."""
    role, web = c['role'], c['role'] == 'web'
    engine.require(role in {'web', 'admin'}, 'backup_role')
    root = Path(c['web_ledger_root'] if web else c['state_root'])
    manifest = Path(c.get('lifecycle_inventory', engine.INVENTORY_PATH))
    inventory = engine.validate_inventory(engine.load_json(manifest, private=True)) \
        if manifest.exists() else {'sites': []}
    sites = {entry['slug']: entry for entry in inventory['sites'] if role in entry}
    states = {slug: retained_state(c, engine, entry['uuid']) for slug, entry in sites.items()}
    files = ['etc/vialcove-provision', 'usr/local/lib/vialcove-provision']
    lifecycle = root / 'lifecycle'
    if lifecycle.exists():
        engine.no_symlink_components(lifecycle)
        info = lifecycle.stat()
        engine.require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                       'backup_lifecycle_directory')
        files.append(str(lifecycle).lstrip('/'))
    if manifest.exists():
        files.append(str(manifest).lstrip('/'))
    files += [str(path).lstrip('/') for path in template_snapshots(c, engine)]
    databases, excluded = {}, []
    if web:
        # Broker metadata/claims/tombstones remain encrypted even after a purge.
        files += [str(root).lstrip('/'), 'etc/ssh/vialcove-bridge-keys',
                  'etc/ssh/sshd_config.d', 'etc/sudoers.d/vialcove-provision']
        for ledger in sorted(root.glob('s*.json')):
            slug = ledger.stem
            engine.require(bool(re.fullmatch(r's[0-9]{4}', slug)) and int(slug[1:]) >= 2,
                           'backup_ledger_slug')
            # Retained data is still backed up. A completed purge must not make
            # the next platform backup fail against a database already removed.
            if states.get(slug) in {'purged', 'purging'}:
                excluded.append(slug)
                continue
            databases[slug] = 'vc_' + slug
            data = Path(c['web_data_root']) / slug
            if data.exists():
                engine.no_symlink_components(data)
                files.append(str(data).lstrip('/'))
        for slug, entry in sites.items():
            state = states[slug]
            if state in {'purged', 'purging'}:
                excluded.append(slug)
                databases.pop(slug, None)
                continue
            # The explicit legacy manifest provides its database name; never
            # derive s0001's historical DB/user names from a new-store formula.
            database = entry['web']['db']['name']
            engine.require(bool(re.fullmatch(r'[a-z][a-z0-9_]{1,63}', database)), 'backup_database')
            databases[slug] = database
            for item in entry['web']['paths']:
                path = Path(item['path'])
                if item['kind'] == 'directory' and path.is_relative_to('/srv/vialcove-data') \
                        and not path.is_relative_to('/srv/vialcove-data/tenant-backups') and path.exists():
                    engine.no_symlink_components(path)
                    files.append(str(path).lstrip('/'))
    else:
        files += [str(root / child).lstrip('/') for child in
                  ('jobs', 'results', 'requests', 'web-command-key', 'web-known-hosts')]
        claims = root / 'domain-claims'
        if claims.exists():
            engine.no_symlink_components(claims)
            engine.require(claims.is_dir() and claims.stat().st_uid == 0 and not claims.stat().st_mode & 0o077,
                           'backup_domain_claims')
            files.append(str(claims).lstrip('/'))
        for marker in sorted(Path(c['admin_store_root']).glob('s*/.provision-owner.json')):
            identity = engine.load_json(marker, private=True)
            if retained_state(c, engine, identity['uuid']) in {'purged', 'purging'}:
                excluded.append(marker.parent.name)
                continue
            files += [str(marker).lstrip('/'), str(marker.parent / 'shared/wp-config.php').lstrip('/')]
        # Legacy control config is outside the new-tenant marker hierarchy.
        for slug, entry in sites.items():
            if states[slug] in {'purged', 'purging'}:
                excluded.append(slug)
                continue
            for identity in entry['admin']['identity_files']:
                if Path(identity['path']).exists():
                    files.append(identity['path'].lstrip('/'))
    files += ['etc/systemd/system', 'etc/nginx/sites-available', 'etc/php/8.3/fpm/pool.d', 'etc/letsencrypt']
    return {'files': list(dict.fromkeys(files)), 'databases': databases,
            'skipped_purging_or_purged': sorted(set(excluded))}


def main():
    assert os.geteuid() == 0
    profile = Path('/etc/vialcove-provision/config.json')
    assert profile.stat().st_uid == 0 and not profile.is_symlink()
    profile_before = profile.read_bytes()
    c = json.loads(profile_before)
    engine = lifecycle_module()
    recipient = c['backup_recipient']
    assert re.fullmatch(r'age1[0-9a-z]+', recipient)
    web = c['role'] == 'web'
    base = Path('/srv/vialcove-data/tenant-backups' if web else '/var/backups/vialcove-provision')
    base.mkdir(mode=0o700, exist_ok=True)
    base.chmod(0o700)
    lock = (base / 'backup.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    if web:
        subprocess.run(['/usr/local/libexec/vialcove-storage-guard', '--mysql'], check=True)
    assert shutil.disk_usage(base).free > 5 * 1024**3
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    with tempfile.TemporaryDirectory(prefix='.pending-', dir=base) as folder:
        temp = Path(folder)
        plan = backup_plan(c, engine)
        files = plan['files']
        for slug, database in sorted(plan['databases'].items()):
            with (temp / (slug + '.sql')).open('wb') as output:
                subprocess.run(['mysqldump', '--single-transaction', '--routines', '--triggers', '--events', database],
                               stdout=output, stderr=subprocess.PIPE, check=True)
        out = base / (stamp + '.tar.age')
        archive = subprocess.Popen(['tar', '-C', '/', '-cf', '-', *files, '-C', str(temp), '.'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        encrypted = subprocess.run(['age', '-r', recipient, '-o', str(out)], stdin=archive.stdout, stderr=subprocess.PIPE)
        archive.stdout.close()
        error = archive.stderr.read()
        result = archive.wait()
        if result or encrypted.returncode:
            out.unlink(missing_ok=True)
            raise RuntimeError('backup_failed')
        # A concurrent root template/profile switch must not produce an archive
        # whose config points to a different snapshot than the one selected.
        if profile.read_bytes() != profile_before:
            out.unlink(missing_ok=True)
            raise RuntimeError('backup_profile_changed')
        digest = hashlib.file_digest(out.open('rb'), 'sha256').hexdigest()
        receipt = {'file': out.name, 'bytes': out.stat().st_size, 'sha256': digest, 'role': c['role'],
                   'encrypted': True, 'offsite_copy': False,
                   'skipped_purging_or_purged': plan['skipped_purging_or_purged']}
        (base / 'latest.json').write_text(json.dumps(receipt))
        for old in sorted(base.glob('????????T????????????Z.tar.age'), reverse=True)[1:]:  # owner 2026-11-08: keep only the latest
            old.unlink()
        print(json.dumps(receipt))


if __name__ == '__main__':
    main()
