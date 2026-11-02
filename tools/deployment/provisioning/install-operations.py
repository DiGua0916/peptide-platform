#!/usr/bin/python3 -I
"""Install durable operations after the private broker profiles have been reviewed."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess


def main():
    assert os.geteuid() == 0
    source = Path(__file__).resolve().parent
    assert source.stat().st_uid == 0 and source.stat().st_mode & 0o022 == 0
    spec = importlib.util.spec_from_file_location('worker', source / 'worker.py')
    w = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(w)
    profile = Path('/etc/vialcove-provision/config.json')
    assert profile.stat().st_uid == 0 and profile.stat().st_mode & 0o077 == 0
    c = json.loads(profile.read_text())
    assert c.get('backup_recipient', '').startswith('age1')
    services = Path('/etc/systemd/system')
    def install(name, command, timer):
        w.write(services / (name + '.service'), '[Unit]\nAfter=network-online.target\n[Service]\nType=oneshot\nExecStart=' + command + '\nUMask=0077\nTimeoutStartSec=1800\n', 0o644)
        w.write(services / (name + '.timer'), '[Timer]\n' + timer + '\n[Install]\nWantedBy=timers.target\n', 0o644)
        subprocess.run(['systemctl', 'daemon-reload'], check=True)
        subprocess.run(['systemctl', 'enable', '--now', name + '.timer'], check=True)
    install('vialcove-tenant-backup', '/usr/bin/python3 -I ' + str(source / 'backup.py'), 'OnCalendar=*-*-* 20:10:00 UTC\nRandomizedDelaySec=300\nPersistent=true')
    if c['role'] == 'admin':
        install('vialcove-provision-worker', '/usr/bin/python3 -I ' + str(source / 'worker.py'), 'OnBootSec=90\nOnUnitInactiveSec=20')
        install('vialcove-tenant-monitor', '/usr/bin/python3 -I -c "import sys;sys.path.insert(0,\'' + str(source) + '\');import monitor;monitor.main()"', 'OnBootSec=180\nOnUnitInactiveSec=300')
    elif c['role'] == 'web':
        for ledger in Path(c['web_ledger_root']).glob('s*.json'):
            t = w.tenant(ledger.stem, c)
            t.update(data=Path(c['web_data_root']) / t['slug'], public=Path(c['web_store_root']) / t['slug'] / 'public')
            w.tenant_cron(t)
    else:
        raise ValueError('role')
    print(json.dumps({'operations_installed': c['role']}))


if __name__ == '__main__':
    main()
