#!/usr/bin/python3 -I
"""Poll only completed broker jobs with matching persisted store identities."""
import json
import os
from pathlib import Path
import worker


def main():
    worker.require(os.geteuid() == 0, 'root_required')
    c = json.loads(Path('/etc/vialcove-provision/config.json').read_text())
    worker.require(c['role'] == 'admin', 'role')
    count = 0
    for job in Path(c['state_root'], 'jobs').iterdir():
        statefile = job / 'state.json'
        if not statefile.exists():
            continue
        state = json.loads(statefile.read_text())
        if state.get('state') != 'complete':
            continue
        t = worker.tenant(state['slug'], c)
        expected, identity = json.dumps(t['management_url']), json.dumps(state['uuid'])
        code = "<?php $ok=false;foreach(PeptideHq\\Sites::all() as $s){if($s['uuid']===" + identity + " && rtrim(PeptideHq\\Site_Urls::management($s),'/')===" + expected + "){$ok=PeptideHq\\Monitor::poll((int)$s['id'],true);break;}}if(!$ok){throw new RuntimeException('monitor_failed');}"
        helper = job / 'monitor.php'
        worker.write(helper, code)
        try:
            worker.run(['php', '/usr/local/bin/wp', '--allow-root', '--path=' + c['hq_root'], 'eval-file', str(helper)])
            count += 1
        finally:
            helper.unlink(missing_ok=True)
    print(json.dumps({'stores_polled': count}))


if __name__ == '__main__':
    main()
