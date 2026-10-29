"""Launch/stop only an operation-owned PHP local server, without a shell."""
from __future__ import annotations

import argparse
import ctypes
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import sys
import time


def norm(value: str | Path) -> str:
    return os.path.normcase(os.path.realpath(value))


def inside(path: Path, root: Path) -> bool:
    return path != root and root in path.parents


def save(path: Path, data: dict) -> None:
    temporary = path.with_suffix('.pending.json')
    temporary.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
    try:
        os.chmod(temporary, 0o600)
    except OSError:
        pass
    os.replace(temporary, path)


def load(path: Path) -> dict:
    data = json.loads(path.read_text(encoding='utf-8-sig'))
    if not isinstance(data, dict):
        raise ValueError('invalid_state')
    root = Path(data['root']).resolve(strict=True)
    public = Path(data['public']).resolve(strict=True)
    marker = json.loads((root / '.peptide-ops-owner.json').read_text(encoding='utf-8'))
    operation = str(data['operation_id'])
    if not re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', operation, re.I):
        raise ValueError('invalid_operation')
    if marker.get('operation_id') != operation or norm(marker.get('root', '')) != norm(root):
        raise ValueError('ownership_mismatch')
    if public != root / 'public' or not inside(public, root):
        raise ValueError('invalid_public_root')
    php = Path(data['php_binary']).resolve(strict=True)
    router = Path(data['router']).resolve(strict=True)
    if not php.is_file() or not router.is_file() or not inside(router, public):
        raise ValueError('invalid_executable_or_router')
    if data.get('php_ini') and not Path(data['php_ini']).is_file():
        raise ValueError('invalid_php_ini')
    log = Path(data['log_dir']).resolve(strict=True)
    if log != path.parent or inside(log, public) or log == public or not 1024 <= int(data['port']) <= 65535:
        raise ValueError('invalid_private_state')
    data.update(root=str(root), public=str(public), php_binary=str(php), router=str(router), log_dir=str(log))
    return data


def windows_args(command: str) -> list[str]:
    from ctypes import wintypes
    shell = ctypes.WinDLL('shell32', use_last_error=True)
    shell.CommandLineToArgvW.argtypes = [wintypes.LPCWSTR, ctypes.POINTER(ctypes.c_int)]
    shell.CommandLineToArgvW.restype = ctypes.POINTER(wintypes.LPWSTR)
    count = ctypes.c_int()
    pointer = shell.CommandLineToArgvW(command, ctypes.byref(count))
    if not pointer:
        raise OSError('process_arguments')
    try:
        return [pointer[i] for i in range(count.value)]
    finally:
        ctypes.windll.kernel32.LocalFree(pointer)


def windows_alive(pid: int) -> bool:
    from ctypes import wintypes
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
    kernel.OpenProcess.restype = wintypes.HANDLE
    kernel.GetExitCodeProcess.argtypes = [wintypes.HANDLE, ctypes.POINTER(wintypes.DWORD)]
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    handle = kernel.OpenProcess(0x1000, False, pid)
    if not handle:
        if ctypes.get_last_error() == 87:
            return False
        raise OSError('process_inspection')
    try:
        code = wintypes.DWORD()
        if not kernel.GetExitCodeProcess(handle, ctypes.byref(code)):
            raise OSError('process_inspection')
        return code.value == 259
    finally:
        kernel.CloseHandle(handle)


def process(pid: int) -> dict:
    if pid < 1:
        return {}
    if os.name == 'nt':
        if not windows_alive(pid):
            return {}
        system = Path(os.environ.get('SystemRoot', r'C:\Windows'))
        ps = system / 'System32/WindowsPowerShell/v1.0/powershell.exe'
        # The script is fixed; the sole value is a validated integer in a private environment.
        query = '$ErrorActionPreference="Stop";$item=Get-CimInstance Win32_Process -Filter ("ProcessId = " + [int]$env:PEPTIDE_OPS_QUERY_PID);if($null -eq $item){Write-Output "{}";exit 0};[ordered]@{pid=[int]$item.ProcessId;exe=[string]$item.ExecutablePath;command=[string]$item.CommandLine;created=[string]$item.CreationDate.ToUniversalTime().ToFileTimeUtc()}|ConvertTo-Json -Compress'
        environment = os.environ.copy()
        environment['PEPTIDE_OPS_QUERY_PID'] = str(pid)
        result = subprocess.run([str(ps), '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', query], env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=8, creationflags=subprocess.CREATE_NO_WINDOW)
        if not windows_alive(pid):
            return {}
        if result.returncode:
            raise OSError('process_inspection')
        data = json.loads(result.stdout.decode('utf-8-sig'))
        if data:
            data['args'] = windows_args(data.get('command', ''))
        return data
    directory = Path('/proc') / str(pid)
    if not directory.exists():
        return {}
    return {'pid': pid, 'exe': str((directory / 'exe').resolve(strict=True)), 'args': [part.decode() for part in (directory / 'cmdline').read_bytes().split(b'\0') if part], 'created': (directory / 'stat').read_text().split()[21]}


def matches(meta: dict, data: dict) -> bool:
    args = meta.get('args', [])
    try:
        document = args.index('-t')
        listen = args.index('-S')
        return norm(meta['exe']) == norm(data['php_binary']) and norm(args[0]) == norm(data['php_binary']) and norm(args[document + 1]) == norm(data['public']) and args[listen + 1] == f'127.0.0.1:{int(data["port"])}' and norm(args[-1]) == norm(data['router']) and (not data.get('created') or str(meta.get('created')) == str(data['created']))
    except (ValueError, IndexError, KeyError):
        return False


def running(data: dict) -> bool:
    meta = process(int(data.get('pid', 0)))
    if not meta:
        return False
    if not matches(meta, data):
        raise ValueError('pid_ownership_mismatch')
    return True


def ready(port: int) -> bool:
    try:
        with socket.create_connection(('127.0.0.1', port), timeout=0.3):
            return True
    except OSError:
        return False


def start(path: Path, data: dict) -> dict:
    if data.get('pid') and running(data):
        return {'pid': int(data['pid']), 'already_running': True}
    with socket.socket() as check:
        check.bind(('127.0.0.1', int(data['port'])))
    args = [data['php_binary']]
    if data.get('php_ini'):
        args += ['-c', data['php_ini']]
    args += ['-S', f'127.0.0.1:{int(data["port"])}', '-t', data['public'], data['router']]
    env = os.environ.copy()
    env['PATH'] = str(Path(data['php_binary']).parent) + os.pathsep + env.get('PATH', '')
    opts = {'cwd': data['public'], 'env': env, 'stdin': subprocess.DEVNULL}
    if os.name == 'nt':
        opts['creationflags'] = subprocess.CREATE_NO_WINDOW | subprocess.CREATE_NEW_PROCESS_GROUP | subprocess.DETACHED_PROCESS
    else:
        opts['start_new_session'] = True
    with open(Path(data['log_dir']) / 'server.stdout.log', 'ab') as output, open(Path(data['log_dir']) / 'server.stderr.log', 'ab') as error:
        child = subprocess.Popen(args, stdout=output, stderr=error, **opts)
    data['created'] = None
    data['stopped'] = None
    data['pid'] = child.pid
    data['started'] = int(time.time())
    save(path, data)
    meta = process(child.pid)
    if not matches(meta, data):
        raise ValueError('spawn_ownership')
    data['created'] = str(meta['created'])
    save(path, data)
    deadline = time.monotonic() + 6
    while time.monotonic() < deadline:
        if child.poll() is not None:
            raise OSError('server_exited')
        if ready(int(data['port'])):
            return {'pid': child.pid, 'already_running': False}
        time.sleep(0.15)
    raise OSError('server_start_timeout')


def stop(path: Path, data: dict) -> dict:
    pid = int(data.get('pid', 0))
    if not pid or not running(data):
        return {'stopped': True, 'already_stopped': True}
    if os.name == 'nt':
        from ctypes import wintypes
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        kernel.OpenProcess.restype = wintypes.HANDLE
        kernel.GetProcessTimes.argtypes = [wintypes.HANDLE] + [ctypes.POINTER(wintypes.FILETIME)] * 4
        kernel.TerminateProcess.argtypes = [wintypes.HANDLE, wintypes.UINT]
        kernel.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
        kernel.CloseHandle.argtypes = [wintypes.HANDLE]
        handle = kernel.OpenProcess(0x1000 | 0x0001 | 0x00100000, False, pid)
        if not handle:
            raise OSError('server_handle')
        try:
            values = [wintypes.FILETIME() for _ in range(4)]
            if not kernel.GetProcessTimes(handle, *[ctypes.byref(value) for value in values]):
                raise OSError('server_identity')
            created = str((values[0].dwHighDateTime << 32) | values[0].dwLowDateTime)
            # CIM CreationDate is microsecond precision; FILETIME retains 100 ns.
            if data.get('created') and int(created) // 10 != int(data['created']) // 10:
                raise ValueError('pid_reused')
            if not kernel.TerminateProcess(handle, 0):
                raise OSError('server_stop')
            if kernel.WaitForSingleObject(handle, 5000) != 0:
                raise OSError('server_stop_timeout')
        finally:
            kernel.CloseHandle(handle)
    else:
        os.kill(pid, signal.SIGTERM)
        deadline = time.monotonic() + 5
        while Path(f'/proc/{pid}').exists() and time.monotonic() < deadline:
            time.sleep(0.1)
        if Path(f'/proc/{pid}').exists():
            raise OSError('server_stop_timeout')
    data['stopped'] = int(time.time())
    save(path, data)
    return {'stopped': True, 'already_stopped': False}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['start', 'stop', 'status'])
    parser.add_argument('state')
    args = parser.parse_args()
    path = Path(args.state).resolve(strict=True)
    data = load(path)
    if args.action == 'start':
        result = start(path, data)
    elif args.action == 'stop':
        result = stop(path, data)
    else:
        result = {'running': running(data), 'pid': int(data.get('pid', 0))}
    print(json.dumps(result))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as exc:
        # No raw process output, private path or credentials cross this boundary.
        name = str(exc)
        print(json.dumps({'error': name if re.fullmatch(r'[a-z_]{2,60}', name) else 'site_server_failed'}))
        sys.exit(1)
