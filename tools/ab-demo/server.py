"""Explicit, localhost-only traffic simulation. No visitor fingerprinting."""
import argparse
import json
import os
import re
import secrets
import sqlite3
import subprocess
import sys
import time
import webbrowser
from datetime import datetime
from contextlib import closing
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parent
DATA = ROOT / '.runtime' / 'visits.sqlite3'
PORT = 17836
IDENTITY = 'peptide-ab-local-demo-v1'
COOKIE = 'peptide_ab_demo_variant'
VERSION = 2


def device_route(headers):
    """Presentation routing only; browser hints are not authentication."""
    hint = headers.get('Sec-CH-UA-Mobile', '').strip()
    ua = headers.get('User-Agent', '').lower()
    if hint in ('?0', '?1'):
        device = 'mobile' if hint == '?1' else 'desktop'
        source = 'Sec-CH-UA-Mobile'
    elif 'ipad' in ua or 'tablet' in ua:
        device, source = 'desktop', 'User-Agent（平板按电脑规则）'
    elif re.search(r'iphone|ipod|windows phone|blackberry|bb10|opera mini|opera mobi|mobile', ua):
        device, source = 'mobile', 'User-Agent'
    elif ua:
        device, source = 'desktop', 'User-Agent'
    else:
        device, source = 'unknown', '缺少设备信息，默认电脑规则'
    variant = 'B' if device == 'mobile' else 'A'
    label = {'mobile': '手机', 'desktop': '电脑 / 非手机', 'unknown': '未知设备'}[device]
    return {'device': device, 'variant': variant, 'source': source,
            'reason': f'浏览器信息判断为{label}，进入 {variant} 页面；依据：{source}。'}


def choose(payload, previous=None, request_headers=None):
    if not isinstance(payload, dict):
        raise ValueError('请求应为对象。')
    mode = payload.get('mode')
    if mode == 'device':
        detected = device_route(request_headers or {})
        return detected['variant'], detected['reason'], False
    persona = payload.get('persona')
    weight = payload.get('weight', 50)
    fresh = payload.get('fresh', False)
    if mode not in ('role', 'random') or persona not in ('reviewer', 'customer'):
        raise ValueError('请选择有效的演示模式和身份。')
    if type(weight) is not int or not 0 <= weight <= 100 or type(fresh) is not bool:
        raise ValueError('A 版本比例须为 0–100 的整数。')
    if mode == 'role':
        variant = 'A' if persona == 'reviewer' else 'B'
        label = '模拟审核访客' if persona == 'reviewer' else '模拟普通访客'
        reason = f'你手动选择了{label}，演示规则将其分配到 {variant} 页面。'
        return variant, reason, False
    if previous in ('A', 'B') and not fresh:
        return previous, f'此浏览器已有 {previous} 分组，继续展示同一版本。', True
    variant = 'A' if secrets.randbelow(100) < weight else 'B'
    return variant, f'新访客随机分组：A {weight}% / B {100-weight}%，本次进入 {variant}。', False


def initialize(path):
    path.parent.mkdir(parents=True, exist_ok=True)
    with closing(sqlite3.connect(path, timeout=10)) as db, db:
        db.execute('CREATE TABLE IF NOT EXISTS visits (id INTEGER PRIMARY KEY, time TEXT, mode TEXT, persona TEXT, variant TEXT, reason TEXT, reused INTEGER)')


def record(path, payload, previous, request_headers=None):
    variant, reason, returning = choose(payload, previous, request_headers)
    persona = device_route(request_headers or {})['device'] if payload['mode'] == 'device' else payload['persona']
    stamp = datetime.now().astimezone().isoformat(timespec='seconds')
    with closing(sqlite3.connect(path, timeout=10)) as db, db:
        cursor = db.execute('INSERT INTO visits(time,mode,persona,variant,reason,reused) VALUES(?,?,?,?,?,?)',
                            (stamp, payload['mode'], persona, variant, reason, int(returning)))
        entry_id = cursor.lastrowid
    return {'id': entry_id, 'time': stamp, 'mode': payload['mode'], 'persona': persona,
            'variant': variant, 'reason': reason, 'returning': returning,
            'previewUrl': '/device-preview' if payload['mode'] == 'device' else f'/pages/{variant.lower()}.html'}


def history(path):
    with closing(sqlite3.connect(path, timeout=10)) as db, db:
        db.row_factory = sqlite3.Row
        entries = [dict(row) for row in db.execute('SELECT * FROM visits ORDER BY id DESC LIMIT 30')]
        counts = dict(db.execute('SELECT variant, COUNT(*) FROM visits GROUP BY variant'))
    for entry in entries:
        entry['returning'] = bool(entry.pop('reused'))
    return {'entries': entries, 'counts': {'A': counts.get('A', 0), 'B': counts.get('B', 0)}}


class Server(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address, database=DATA):
        self.database = database
        initialize(database)
        super().__init__(address, Handler)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def local(self):
        port = self.server.server_address[1]
        return self.headers.get('Host') in (f'127.0.0.1:{port}', f'localhost:{port}')

    def respond(self, code, data, mime='application/json; charset=utf-8', cookie=None, extra=None):
        if isinstance(data, dict):
            data = json.dumps(data, ensure_ascii=False).encode('utf-8')
        elif isinstance(data, str):
            data = data.encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', mime)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Vary', 'User-Agent, Sec-CH-UA-Mobile')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Referrer-Policy', 'no-referrer')
        self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'none'")
        if cookie:
            self.send_header('Set-Cookie', f'{COOKIE}={cookie}; Path=/; HttpOnly; SameSite=Strict; Max-Age=86400')
        for key, value in (extra or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if not self.local():
            return self.respond(403, {'error': '仅允许本机访问。'})
        route = urlparse(self.path).path
        if route == '/api/health':
            return self.respond(200, {'id': IDENTITY, 'version': VERSION})
        if route == '/api/device':
            return self.respond(200, device_route(self.headers))
        if route in ('/entry', '/device-preview'):
            detected = device_route(self.headers)
            if route == '/entry':
                try:
                    record(self.server.database, {'mode': 'device'}, None, self.headers)
                except sqlite3.Error:
                    return self.respond(503, {'error': '访问记录暂时无法保存，请稍后重试。'})
            page = ROOT / 'public' / (detected['variant'].lower()+'.html')
            return self.respond(200, page.read_bytes(), 'text/html; charset=utf-8',
                                extra={'X-AB-Variant': detected['variant'], 'X-AB-Device': detected['device']})
        if route == '/api/history':
            return self.respond(200, history(self.server.database))
        assets = {'/': ('index.html', 'text/html'), '/app.js': ('app.js', 'text/javascript'),
                  '/style.css': ('style.css', 'text/css'), '/pages/a.html': ('a.html', 'text/html'),
                  '/pages/b.html': ('b.html', 'text/html'), '/landing.css': ('landing.css', 'text/css')}
        if route not in assets:
            return self.respond(404, {'error': '页面不存在。'})
        filename, mime = assets[route]
        self.respond(200, (ROOT / 'public' / filename).read_bytes(), mime+'; charset=utf-8')

    def do_POST(self):
        port = self.server.server_address[1]
        if not self.local() or self.headers.get('Origin') not in (None, f'http://127.0.0.1:{port}', f'http://localhost:{port}') or self.headers.get('X-Local-Demo') != '1':
            return self.respond(403, {'error': '请从本地演示页面操作。'})
        if self.path != '/api/visit':
            return self.respond(404, {'error': '接口不存在。'})
        try:
            if self.headers.get('Content-Type', '').split(';')[0] != 'application/json':
                raise ValueError('请求需要 JSON 格式。')
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 < length < 4096:
                raise ValueError('请求为空或过大。')
            payload = json.loads(self.rfile.read(length).decode('utf-8'))
            jar = SimpleCookie()
            try:
                jar.load(self.headers.get('Cookie', ''))
            except Exception:
                pass
            previous = jar[COOKIE].value if COOKIE in jar else None
            entry = record(self.server.database, payload, previous, self.headers)
            cookie = entry['variant'] if payload['mode'] == 'random' else None
            return self.respond(201, entry, cookie=cookie)
        except (ValueError, UnicodeError) as error:
            return self.respond(400, {'error': str(error)})
        except sqlite3.Error:
            return self.respond(503, {'error': '记录未保存，请稍后重试。'})


def alive(port):
    try:
        with urlopen(f'http://127.0.0.1:{port}/api/health', timeout=1) as response:
            return json.load(response).get('id') == IDENTITY
    except Exception:
        return False


def main():
    parser = argparse.ArgumentParser(description='本地 A/B 分流演示')
    parser.add_argument('command', choices=('serve', 'start', 'open'), nargs='?', default='serve')
    parser.add_argument('--port', type=int, default=PORT)
    args = parser.parse_args()
    url = f'http://127.0.0.1:{args.port}/'
    if args.command == 'serve':
        server = Server(('127.0.0.1', args.port))
        if sys.stdout:
            print(url, flush=True)
        try:
            server.serve_forever()
        finally:
            server.server_close()
        return
    if not alive(args.port):
        DATA.parent.mkdir(parents=True, exist_ok=True)
        with (DATA.parent / 'server.log').open('ab') as log:
            process = subprocess.Popen([sys.executable, str(ROOT / 'server.py'), 'serve', '--port', str(args.port)],
                                       cwd=ROOT, stdin=subprocess.DEVNULL, stdout=log, stderr=log,
                                       creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
        for _ in range(40):
            if alive(args.port):
                break
            if process.poll() is not None:
                raise RuntimeError('演示服务启动失败，请查看 .runtime/server.log。')
            time.sleep(0.15)
        else:
            process.terminate()
            raise RuntimeError('演示服务启动超时。')
    if args.command == 'open':
        webbrowser.open(url)
    if sys.stdout:
        print(url)


if __name__ == '__main__':
    main()
