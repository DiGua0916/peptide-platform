"""Phone preview for the local store (vialcovepeptidescom.local).

A phone cannot open the .local address, because only this PC knows it (hosts file).
This small proxy listens on this PC's network address, for example http://192.168.0.13:17850/,
forwards every request to the LocalWP router (127.0.0.1:80) with the site's own host name,
and rewrites the site's absolute links in the answers to the address the phone used.

- Only devices on the local network (private addresses) and this PC are served.
- WordPress itself is not changed: its address stays http://vialcovepeptidescom.local.
- Windows Firewall must allow the port for python.exe from the local subnet (see README.md).

Run:  python phone_preview.py [port]        (default port 17850)
"""

import http.client
import http.server
import ipaddress
import socket
import sys

SITE = 'vialcovepeptidescom.local'
UPSTREAM = ('127.0.0.1', 80)
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 17850

TEXT_TYPES = ('text/', 'application/json', 'application/javascript', 'application/x-javascript',
              'application/xml', 'application/rss+xml', 'application/ld+json', 'image/svg+xml')
HOP = {'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
       'transfer-encoding', 'upgrade', 'content-length', 'content-encoding'}


def lan_ip():
    """This PC's address on the local network (no packets are sent)."""
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(('192.168.0.1', 9))
        return s.getsockname()[0]
    finally:
        s.close()


PUBLIC_HOST = '%s:%d' % (lan_ip(), PORT)
PUBLIC = 'http://' + PUBLIC_HOST


def pairs():
    """(site form, phone form) for absolute links, JSON-escaped links and URL-encoded links."""
    out = []
    for host in (SITE, 'www.' + SITE):
        for scheme in ('https', 'http'):
            out.append((scheme + '://' + host, PUBLIC))
            out.append((scheme + ':\\/\\/' + host, 'http:\\/\\/' + PUBLIC_HOST))
            out.append((scheme + '%3A%2F%2F' + host, 'http%3A%2F%2F' + PUBLIC_HOST.replace(':', '%3A')))
        out.append(('//' + host, '//' + PUBLIC_HOST))
    return out


TO_PHONE = pairs()
TO_SITE = [(PUBLIC, 'http://' + SITE), ('http://' + PUBLIC_HOST, 'http://' + SITE)]


def rewrite(text, table):
    for a, b in table:
        text = text.replace(a, b)
    return text


class Handler(http.server.BaseHTTPRequestHandler):
    server_version = 'PhonePreview/1.0'

    def allowed(self):
        try:
            return ipaddress.ip_address(self.client_address[0]).is_private
        except ValueError:
            return False

    def forward(self):
        if not self.allowed():
            self.send_error(403, 'Only devices on the local network can use this preview.')
            return
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length) if length else None
        headers = {}
        for k, v in self.headers.items():
            lk = k.lower()
            if lk in HOP or lk in ('host', 'accept-encoding'):
                continue
            if lk in ('origin', 'referer'):
                v = rewrite(v, TO_SITE)
            headers[k] = v
        headers['Host'] = SITE
        headers['X-Forwarded-For'] = self.client_address[0]
        if body is not None:
            headers['Content-Length'] = str(len(body))
        conn = http.client.HTTPConnection(*UPSTREAM, timeout=120)
        try:
            conn.request(self.command, self.path, body=body, headers=headers)
            res = conn.getresponse()
            data = res.read()
        except OSError as err:
            self.send_error(502, 'The local site did not answer: %s' % err)
            return
        finally:
            conn.close()
        ctype = (res.getheader('Content-Type') or '').lower()
        if data and any(t in ctype for t in TEXT_TYPES):
            charset = 'utf-8'
            if 'charset=' in ctype:
                charset = ctype.split('charset=')[-1].split(';')[0].strip() or 'utf-8'
            try:
                data = rewrite(data.decode(charset), TO_PHONE).encode(charset)
            except (LookupError, UnicodeError):
                pass
        self.send_response(res.status, res.reason)
        for k, v in res.getheaders():
            lk = k.lower()
            if lk in HOP:
                continue
            if lk == 'location':
                v = rewrite(v, TO_PHONE)
            if lk == 'set-cookie':
                v = '; '.join(p for p in v.split(';') if not p.strip().lower().startswith('domain='))
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Connection', 'close')
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(data)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = do_OPTIONS = forward

    def log_message(self, fmt, *args):
        sys.stdout.write('%s %s\n' % (self.client_address[0], fmt % args))
        sys.stdout.flush()


if __name__ == '__main__':
    httpd = http.server.ThreadingHTTPServer(('0.0.0.0', PORT), Handler)
    print('Phone preview: open %s/ on a phone on the same Wi-Fi (admin: %s/wp-admin)' % (PUBLIC, PUBLIC), flush=True)
    httpd.serve_forever()
