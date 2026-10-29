"""Private opt-in HQ schedule pump. Does not install a service or issue HTTP."""
from __future__ import annotations
import argparse
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import time

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('site_server_identity', Path(__file__).with_name('site-server.py'))
identity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(identity)


def read(path: Path) -> dict:
    data = json.loads(path.read_text(encoding='utf-8-sig'))
    private = Path(data['private_root']).resolve(strict=True)
    root = Path(data['wp_root']).resolve(strict=True)
    if not (root / 'wp-load.php').is_file() or private == root or root in private.parents:
        raise ValueError('scheduler_private_root')
    if path != private / 'scheduler/runtime.json' or Path(data['config']).resolve() != private / 'config.json':
        raise ValueError('scheduler_state_root')
    for name in ['python_binary', 'php_binary', 'wp_cli', 'runner', 'tick']:
        if not Path(data[name]).is_absolute() or not Path(data[name]).is_file():
            raise ValueError('scheduler_tools')
    if Path(data['runner']).resolve() != Path(__file__).resolve():
        raise ValueError('scheduler_runner')
    # Local plugin directories may be trusted junctions into the source worktree.
    if Path(data['tick']).resolve() != Path(__file__).with_name('tick-schedules.php').resolve():
        raise ValueError('scheduler_tick_root')
    if data.get('php_ini') and not Path(data['php_ini']).is_file():
        raise ValueError('scheduler_ini')
    data['interval_seconds'] = max(60, min(300, int(data.get('interval_seconds', 120))))
    return data


def enabled(data: dict) -> bool:
    cfg = json.loads(Path(data['config']).read_text(encoding='utf-8-sig'))
    return cfg.get('local_scheduler', {}).get('enabled') is True


def save_merge(path: Path, update: dict) -> dict:
    lock = acquire(path.parent / 'state.lock', blocking=True)
    if lock is None:
        raise ValueError('scheduler_state_busy')
    try:
        data = json.loads(path.read_text(encoding='utf-8-sig'))
        data.update(update)
        identity.save(path, data)
        return data
    finally:
        lock.close()


def matches(meta: dict, data: dict, path: Path) -> bool:
    args = meta.get('args', [])
    return len(args) == 4 and identity.norm(meta.get('exe', '')) == identity.norm(data['python_binary']) and identity.norm(args[0]) == identity.norm(data['python_binary']) and identity.norm(args[1]) == identity.norm(data['runner']) and args[2] == 'worker' and identity.norm(args[3]) == identity.norm(path) and (not data.get('created') or str(meta.get('created')) == str(data['created']))


def inspect(data: dict, path: Path) -> dict:
    meta = identity.process(int(data.get('pid', 0)))
    if meta and not matches(meta, data, path):
        raise ValueError('scheduler_pid_ownership')
    return meta


def acquire(path: Path, blocking: bool = False):
    handle = open(path, 'a+b')
    if handle.seek(0, 2) == 0:
        handle.write(b'0')
        handle.flush()
    handle.seek(0)
    try:
        if os.name == 'nt':
            import msvcrt
            msvcrt.locking(handle.fileno(), msvcrt.LK_LOCK if blocking else msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(handle, fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB))
        return handle
    except OSError:
        handle.close()
        return None


def ensure(path: Path, data: dict) -> dict:
    if not enabled(data):
        return {'enabled': False, 'running': False}
    lock = acquire(path.parent / 'launch.lock')
    if lock is None:
        return {'enabled': True, 'starting': True}
    try:
        data = read(path)
        if inspect(data, path):
            return {'enabled': True, 'running': True, 'pid': int(data['pid']), 'interval_seconds': data['interval_seconds']}
        args = [data['python_binary'], data['runner'], 'worker', str(path)]
        options = {'cwd': data['wp_root'], 'stdin': subprocess.DEVNULL}
        if os.name == 'nt':
            options['creationflags'] = subprocess.CREATE_NO_WINDOW | subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP
        else:
            options['start_new_session'] = True
        log = path.parent / 'worker.log'
        if log.exists() and log.stat().st_size > 5 * 1024 * 1024:
            log.replace(path.parent / 'worker.previous.log')
        with open(log, 'ab') as output:
            child = subprocess.Popen(args, stdout=output, stderr=output, **options)
        data = save_merge(path, {'pid': child.pid, 'created': None, 'started_at': int(time.time()), 'stop_requested': False, 'stopped_at': None, 'heartbeat': None, 'last_tick_at': None, 'last_tick_code': None})
        meta = identity.process(child.pid)
        if not matches(meta, data, path):
            raise ValueError('scheduler_spawn_identity')
        save_merge(path, {'created': str(meta['created'])})
        if child.poll() is not None:
            raise ValueError('scheduler_spawn_exit')
        return {'enabled': True, 'running': True, 'pid': child.pid, 'interval_seconds': data['interval_seconds']}
    finally:
        lock.close()


def worker(path: Path, data: dict) -> dict:
    lock = acquire(path.parent / 'worker.lock')
    if lock is None:
        return {'duplicate_worker': True}
    try:
        startup = time.monotonic() + 5
        while int(data.get('pid', 0)) != os.getpid() and time.monotonic() < startup:
            time.sleep(0.05)
            data = read(path)
        while True:
            data = read(path)
            if not enabled(data) or data.get('stop_requested') or int(data.get('pid', 0)) != os.getpid():
                break
            command = [data['php_binary']]
            if data.get('php_ini'):
                command += ['-c', data['php_ini']]
            command += [data['wp_cli'], '--path=' + data['wp_root'], '--no-color', 'eval-file', data['tick']]
            environment = os.environ.copy()
            environment['PATH'] = str(Path(data['php_binary']).parent) + os.pathsep + environment.get('PATH', '')
            environment['PEPTIDE_OPS_SCHEDULER'] = '1'
            opts = {'cwd': data['wp_root'], 'env': environment, 'stdin': subprocess.DEVNULL, 'stdout': subprocess.PIPE, 'stderr': subprocess.PIPE, 'timeout': 50}
            if os.name == 'nt':
                opts['creationflags'] = subprocess.CREATE_NO_WINDOW
            try:
                result = subprocess.run(command, **opts)
                code = result.returncode
                # Output is kept only in the private folder; the process metadata is redacted.
                (path.parent / 'tick.stdout.log').write_bytes(result.stdout[-1024 * 1024:])
                (path.parent / 'tick.stderr.log').write_bytes(result.stderr[-1024 * 1024:])
            except subprocess.TimeoutExpired:
                code = -2
            except OSError:
                code = -1
            save_merge(path, {'heartbeat': int(time.time()), 'last_tick_at': int(time.time()), 'last_tick_code': code})
            if code == 69:  # HQ was deactivated: wait for an explicit HQ heartbeat to start again.
                break
            deadline = time.monotonic() + int(data['interval_seconds'])
            while time.monotonic() < deadline:
                fresh = read(path)
                if not enabled(fresh) or fresh.get('stop_requested'):
                    return {'stopped': True}
                time.sleep(min(2, max(0, deadline - time.monotonic())))
        return {'stopped': True}
    finally:
        save_merge(path, {'stopped_at': int(time.time())})
        lock.close()


def stop(path: Path, data: dict) -> dict:
    meta = inspect(data, path)
    if not meta:
        return {'stopped': True, 'already_stopped': True}
    save_merge(path, {'stop_requested': True})
    deadline = time.monotonic() + 7
    while time.monotonic() < deadline:
        time.sleep(0.3)
        current = inspect(data, path)
        if not current:
            return {'stopped': True, 'already_stopped': False}
    # A tick can take up to 50 seconds; preserve it and report a pending graceful stop.
    return {'stopped': False, 'stopping': True, 'pid': int(data['pid'])}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['ensure', 'worker', 'stop', 'status'])
    parser.add_argument('state')
    args = parser.parse_args()
    path = Path(args.state).resolve(strict=True)
    data = read(path)
    if args.action == 'ensure':
        result = ensure(path, data)
    elif args.action == 'worker':
        result = worker(path, data)
    elif args.action == 'stop':
        result = stop(path, data)
    else:
        result = {'enabled': enabled(data), 'running': bool(inspect(data, path)), 'pid': int(data.get('pid', 0)), 'interval_seconds': data['interval_seconds'], 'heartbeat': data.get('heartbeat'), 'last_tick_at': data.get('last_tick_at'), 'last_tick_code': data.get('last_tick_code')}
    print(json.dumps(result))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        value = str(error)
        print(json.dumps({'error': value if value.startswith('scheduler_') and value.replace('_', '').isalnum() else 'scheduler_failed'}))
        sys.exit(1)
