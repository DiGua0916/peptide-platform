"""Local device-routing demo with an explicitly enabled LAN entry."""
import argparse
import html
import ipaddress
import json
import os
import re
import secrets
import sqlite3
import subprocess
import sys
import threading
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
PORT = 17837
IDENTITY = 'peptide-ab-local-demo-v1'
COOKIE = 'peptide_ab_demo_variant'
VERSION = 4


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
        db.execute('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY CHECK(id=1), a_url TEXT NOT NULL, b_url TEXT NOT NULL)')
        db.execute("INSERT OR IGNORE INTO settings VALUES(1,'','')")


def settings(path):
    with closing(sqlite3.connect(path, timeout=10)) as db:
        a_url, b_url = db.execute('SELECT a_url,b_url FROM settings WHERE id=1').fetchone()
    return {'aUrl': a_url, 'bUrl': b_url}


def validate_url(value, own_ports, own_hosts=()):
    if not isinstance(value, str):
        raise ValueError('网址须为文本。')
    value = value.strip()
    if not value:
        return ''
    if len(value) > 2048 or any(char.isspace() or ord(char) < 32 or ord(char) == 127 for char in value) or '\\' in value:
        raise ValueError('网址过长或含无效字符。')
    try:
        parsed = urlparse(value)
        if parsed.scheme.lower() not in ('http', 'https') or not parsed.hostname:
            raise ValueError()
        port = parsed.port or (443 if parsed.scheme.lower() == 'https' else 80)
        if parsed.username is not None or parsed.password is not None:
            raise ValueError()
    except ValueError:
        raise ValueError('请填写完整的 http:// 或 https:// 网址，不能含账号密码。') from None
    host = parsed.hostname.lower().rstrip('.')
    is_local = host == 'localhost' or host.endswith('.localhost')
    try:
        is_local = is_local or ipaddress.ip_address(host).is_loopback or host == '0.0.0.0'
    except ValueError:
        pass
    if (is_local or host in own_hosts) and port in own_ports:
        raise ValueError('不能把本工具的后台或入口作为目标，以免循环嵌套；内置页面请留空。')
    return value


def save_settings(path, payload, own_ports, own_hosts=()):
    if not isinstance(payload, dict) or set(payload) != {'aUrl', 'bUrl'}:
        raise ValueError('请同时提供 A 和 B 两个网址。')
    validated = {key: validate_url(value, own_ports, own_hosts) for key, value in payload.items()}
    with closing(sqlite3.connect(path, timeout=10)) as db, db:
        db.execute('UPDATE settings SET a_url=?,b_url=? WHERE id=1', (validated['aUrl'], validated['bUrl']))
    return validated


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

    def __init__(self, address, database=DATA, role='admin', admin_port=None, entry_port=None, lan_address=None, lan_prefix=24):
        self.database = database
        self.role = role
        self.lan_address = lan_address
        self.lan_network = ipaddress.ip_network(f'{lan_address}/{lan_prefix}', strict=False) if lan_address else None
        initialize(database)
        super().__init__(address, Handler)
        self.admin_port = self.server_address[1] if role == 'admin' else admin_port
        self.entry_port = self.server_address[1] if role == 'entry' else (entry_port or self.server_address[1])

    @property
    def entry_url(self):
        return f'http://127.0.0.1:{self.entry_port}'

    def configuration(self):
        return {**settings(self.database), 'entryUrl': self.entry_url+'/',
                'adminUrl': f'http://127.0.0.1:{self.admin_port}/', 'displayMode': 'embed',
                'lanEntryUrl': f'http://{self.lan_address}:{self.entry_port}/' if self.lan_address else None}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def local(self):
        port = self.server.server_address[1]
        allowed_hosts = {f'127.0.0.1:{port}', f'localhost:{port}'}
        remote = ipaddress.ip_address(self.client_address[0])
        allowed_client = remote.is_loopback
        if self.server.role == 'entry' and self.server.lan_network:
            allowed_hosts.add(f'{self.server.lan_address}:{port}')
            allowed_client = allowed_client or remote in self.server.lan_network
        return allowed_client and self.headers.get('Host') in allowed_hosts

    def respond(self, code, data, mime='application/json; charset=utf-8', cookie=None, extra=None, embed=False):
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
        frames = 'http: https:' if embed else f"'self' {self.server.entry_url}"
        ancestors = f"'self' http://127.0.0.1:{self.server.admin_port} http://localhost:{self.server.admin_port}"
        self.send_header('Content-Security-Policy', f"default-src 'self'; script-src 'self'; style-src 'self'; frame-src {frames}; frame-ancestors {ancestors}; object-src 'none'; base-uri 'none'")
        if cookie:
            self.send_header('Set-Cookie', f'{COOKIE}={cookie}; Path=/; HttpOnly; SameSite=Strict; Max-Age=86400')
        for key, value in (extra or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(data)

    def page(self, variant, device='preview'):
        target = settings(self.server.database)[variant.lower()+'Url']
        headers = {'X-AB-Variant': variant, 'X-AB-Device': device,
                   'X-AB-Display': 'embed' if target else 'local-html'}
        if not target:
            return self.respond(200, (ROOT / 'public' / (variant.lower()+'.html')).read_bytes(),
                                'text/html; charset=utf-8', extra=headers)
        # The browser loads the supplied URL. No server-side fetching or redirects.
        content = (ROOT / 'public' / 'embedded.html').read_text(encoding='utf-8')
        content = content.replace('{{VARIANT}}', variant).replace('{{TARGET}}', html.escape(target, quote=True))
        return self.respond(200, content, 'text/html; charset=utf-8', extra=headers, embed=True)

    def do_GET(self):
        if not self.local():
            return self.respond(403, {'error': '请求来源或访问地址不在允许范围内。'})
        route = urlparse(self.path).path
        if route == '/api/health':
            return self.respond(200, {'id': IDENTITY, 'version': VERSION, 'role': self.server.role,
                                      'entryPort': self.server.entry_port, 'adminPort': self.server.admin_port,
                                      'lanAddress': self.server.lan_address})
        if route == '/api/config' and self.server.role == 'admin':
            return self.respond(200, self.server.configuration())
        if route == '/api/device':
            return self.respond(200, device_route(self.headers))
        if route in ('/entry', '/device-preview') or (route == '/' and self.server.role == 'entry'):
            detected = device_route(self.headers)
            if route != '/device-preview':
                try:
                    record(self.server.database, {'mode': 'device'}, None, self.headers)
                except sqlite3.Error:
                    return self.respond(503, {'error': '访问记录暂时无法保存，请稍后重试。'})
            return self.page(detected['variant'], detected['device'])
        if route in ('/preview/a', '/preview/b'):
            return self.page(route[-1].upper())
        if route == '/api/history' and self.server.role == 'admin':
            return self.respond(200, history(self.server.database))
        assets = {'/': ('index.html', 'text/html'), '/app.js': ('app.js', 'text/javascript'),
                  '/style.css': ('style.css', 'text/css'), '/pages/a.html': ('a.html', 'text/html'),
                  '/pages/b.html': ('b.html', 'text/html'), '/landing.css': ('landing.css', 'text/css'),
                  '/embedded.css': ('embedded.css', 'text/css')}
        if self.server.role == 'entry':
            assets = {key: value for key, value in assets.items() if key in ('/landing.css', '/embedded.css', '/pages/a.html', '/pages/b.html')}
        if route not in assets:
            return self.respond(404, {'error': '页面不存在。'})
        filename, mime = assets[route]
        self.respond(200, (ROOT / 'public' / filename).read_bytes(), mime+'; charset=utf-8')

    def do_POST(self):
        if self.server.role != 'admin':
            return self.respond(404, {'error': '配置请使用后台端口。'})
        port = self.server.server_address[1]
        if not self.local() or self.headers.get('Origin') not in (None, f'http://127.0.0.1:{port}', f'http://localhost:{port}') or self.headers.get('X-Local-Demo') != '1':
            return self.respond(403, {'error': '请从本地演示页面操作。'})
        if self.path not in ('/api/visit', '/api/config'):
            return self.respond(404, {'error': '接口不存在。'})
        try:
            if self.headers.get('Content-Type', '').split(';')[0] != 'application/json':
                raise ValueError('请求需要 JSON 格式。')
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 < length < 16384:
                raise ValueError('请求为空或过大。')
            payload = json.loads(self.rfile.read(length).decode('utf-8'))
            if self.path == '/api/config':
                save_settings(self.server.database, payload, {self.server.admin_port, self.server.entry_port}, {self.server.lan_address})
                return self.respond(200, self.server.configuration())
            jar = SimpleCookie()
            try:
                jar.load(self.headers.get('Cookie', ''))
            except Exception:
                pass
            previous = jar[COOKIE].value if COOKIE in jar else None
            entry = record(self.server.database, payload, previous, self.headers)
            preview_path = '/device-preview' if payload['mode'] == 'device' else '/preview/'+entry['variant'].lower()
            entry['previewUrl'] = self.server.entry_url+preview_path
            entry['entryUrl'] = self.server.entry_url+'/'
            entry['targetUrl'] = settings(self.server.database)[entry['variant'].lower()+'Url']
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
    parser.add_argument('--entry-port', type=int, help='访问入口端口，默认后台端口 + 2')
    parser.add_argument('--lan-address', help='允许入口使用的本机局域网 IPv4 地址；后台仍只监听本机')
    parser.add_argument('--lan-prefix', type=int, default=24, help='允许的局域网子网前缀长度，默认 24')
    args = parser.parse_args()
    entry_port = args.entry_port if args.entry_port is not None else args.port+2
    if not 1 <= args.port <= 65535 or not 1 <= entry_port <= 65535 or args.port == entry_port:
        parser.error('后台和入口须使用两个不同的有效端口。')
    if args.lan_address:
        try:
            address = ipaddress.ip_address(args.lan_address)
            if address.version != 4 or address.is_loopback or address.is_unspecified or not address.is_private or not 8 <= args.lan_prefix <= 32:
                raise ValueError()
        except ValueError:
            parser.error('局域网地址须为本机私有 IPv4，前缀长度为 8–32。')
    url = f'http://127.0.0.1:{args.port}/'
    if args.command == 'serve':
        server = Server(('127.0.0.1', args.port), entry_port=entry_port, lan_address=args.lan_address, lan_prefix=args.lan_prefix)
        try:
            entry = Server(('0.0.0.0' if args.lan_address else '127.0.0.1', entry_port), role='entry', admin_port=args.port,
                           lan_address=args.lan_address, lan_prefix=args.lan_prefix)
        except OSError:
            server.server_close()
            raise
        worker = threading.Thread(target=entry.serve_forever, daemon=True)
        worker.start()
        if sys.stdout:
            print(f'Admin: {url}\nEntry: {server.entry_url}/', flush=True)
            if args.lan_address:
                print(f'LAN: http://{args.lan_address}:{entry_port}/ ({entry.lan_network})', flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass
        finally:
            entry.shutdown()
            entry.server_close()
            worker.join()
            server.server_close()
        return
    if not alive(args.port):
        DATA.parent.mkdir(parents=True, exist_ok=True)
        command = [sys.executable, str(ROOT / 'server.py'), 'serve', '--port', str(args.port), '--entry-port', str(entry_port)]
        if args.lan_address:
            command += ['--lan-address', args.lan_address, '--lan-prefix', str(args.lan_prefix)]
        with (DATA.parent / 'server.log').open('ab') as log:
            process = subprocess.Popen(command,
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
