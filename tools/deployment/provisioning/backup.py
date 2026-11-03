#!/usr/bin/python3 -I
"""Encrypted backup of broker state and each newly provisioned store. Root only."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile


def main():
    assert os.geteuid() == 0
    profile = Path('/etc/vialcove-provision/config.json')
    assert profile.stat().st_uid == 0 and not profile.is_symlink()
    c = json.loads(profile.read_text())
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
        files = ['etc/vialcove-provision', 'usr/local/lib/vialcove-provision']
        if web:
            files += ['var/lib/vialcove-provision-web', 'etc/ssh/vialcove-bridge-keys', 'etc/ssh/sshd_config.d', 'etc/sudoers.d/vialcove-provision']
            for ledger in sorted(Path(c['web_ledger_root']).glob('s*.json')):
                slug = ledger.stem
                assert re.fullmatch(r's[0-9]{4}', slug) and int(slug[1:]) >= 2
                with (temp / (slug + '.sql')).open('wb') as output:
                    subprocess.run(['mysqldump', '--single-transaction', '--routines', '--triggers', '--events', 'vc_' + slug], stdout=output, stderr=subprocess.PIPE, check=True)
                files += [str(Path(c['web_data_root']) / slug).lstrip('/')]
        else:
            # Explicitly exclude ephemeral QA credentials and duplicate mounted media.
            files += ['var/lib/vialcove-provision/jobs', 'var/lib/vialcove-provision/results', 'var/lib/vialcove-provision/requests', 'var/lib/vialcove-provision/web-command-key', 'var/lib/vialcove-provision/web-known-hosts']
            for marker in sorted(Path(c['admin_store_root']).glob('s*/.provision-owner.json')):
                files += [str(marker).lstrip('/'), str(marker.parent / 'shared/wp-config.php').lstrip('/')]
        files += ['etc/systemd/system', 'etc/nginx/sites-available', 'etc/php/8.3/fpm/pool.d', 'etc/letsencrypt']
        out = base / (stamp + '.tar.age')
        archive = subprocess.Popen(['tar', '-C', '/', '-cf', '-', *files, '-C', str(temp), '.'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        encrypted = subprocess.run(['age', '-r', recipient, '-o', str(out)], stdin=archive.stdout, stderr=subprocess.PIPE)
        archive.stdout.close()
        error = archive.stderr.read()
        result = archive.wait()
        if result or encrypted.returncode:
            out.unlink(missing_ok=True)
            raise RuntimeError('backup_failed')
        digest = hashlib.file_digest(out.open('rb'), 'sha256').hexdigest()
        receipt = {'file': out.name, 'bytes': out.stat().st_size, 'sha256': digest, 'role': c['role'], 'encrypted': True, 'offsite_copy': False}
        (base / 'latest.json').write_text(json.dumps(receipt))
        for old in sorted(base.glob('????????T????????????Z.tar.age'), reverse=True)[7:]:
            old.unlink()
        print(json.dumps(receipt))


if __name__ == '__main__':
    main()
