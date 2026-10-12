from http.client import HTTPConnection
import json
import tempfile
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import server

TEMP = Path(r'D:\Codex\Tasks\peptide-platform\work\ab-demo-tests')
TEMP.mkdir(parents=True, exist_ok=True)


def payload(mode='role', persona='reviewer', weight=50, fresh=False):
    return dict(mode=mode, persona=persona, weight=weight, fresh=fresh)


class DemoTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=TEMP)
        self.path = Path(self.temp.name) / 'visits.sqlite3'
        self.http = server.Server(('127.0.0.1', 0), self.path)
        self.thread = threading.Thread(target=self.http.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.http.shutdown()
        self.http.server_close()
        self.thread.join()
        self.temp.cleanup()

    def request(self, body=None, path='/api/visit', headers=None, http=None):
        conn = HTTPConnection(*(http or self.http).server_address, timeout=3)
        default = {'Content-Type':'application/json', 'X-Local-Demo':'1'}
        default.update(headers or {})
        conn.request('POST' if body is not None else 'GET', path,
                     json.dumps(body) if body is not None else None, default)
        response = conn.getresponse()
        result = response.status, dict(response.getheaders()), response.read().decode('utf-8')
        conn.close()
        return result

    def test_explicit_identity_routes(self):
        self.assertEqual(server.choose(payload())[0], 'A')
        self.assertEqual(server.choose(payload(persona='customer'))[0], 'B')

    def test_sticky_assignment_and_new_visitor(self):
        self.assertEqual(server.choose(payload('random', weight=100), 'B')[0], 'B')
        self.assertTrue(server.choose(payload('random'), 'A')[2])
        self.assertEqual(server.choose(payload('random', weight=100, fresh=True), 'B')[0], 'A')
        self.assertEqual(server.choose(payload('random', weight=0, fresh=True), 'A')[0], 'B')

    def test_invalid_request_creates_no_record(self):
        for data in [[], payload(mode='unknown'), payload(persona='bot'), payload(weight=101), payload(weight=True), payload(fresh='yes')]:
            self.assertEqual(self.request(data)[0], 400)
        self.assertEqual(server.history(self.path)['entries'], [])

    def test_http_cookie_and_page_result(self):
        status, headers, raw = self.request(payload('random', weight=100, fresh=True))
        self.assertEqual(status, 201)
        self.assertEqual(json.loads(raw)['variant'], 'A')
        cookie = headers['Set-Cookie'].split(';')[0]
        second = json.loads(self.request(payload('random', weight=0), headers={'Cookie':cookie})[2])
        self.assertEqual(second['variant'], 'A')
        self.assertTrue(second['returning'])
        third = json.loads(self.request(payload(persona='customer'), headers={'Cookie':cookie})[2])
        self.assertEqual(third['variant'], 'B')

    def test_history_survives_reinitialization(self):
        self.request(payload())
        self.request(payload(persona='customer'))
        server.initialize(self.path)
        data = server.history(self.path)
        self.assertEqual(data['counts'], {'A':1, 'B':1})
        self.assertEqual(data['entries'][0]['variant'], 'B')

    def test_concurrent_records_are_preserved(self):
        with ThreadPoolExecutor(max_workers=6) as pool:
            entries = list(pool.map(lambda _: server.record(self.path, payload(), None), range(20)))
        self.assertEqual(len({entry['id'] for entry in entries}), 20)
        self.assertEqual(server.history(self.path)['counts']['A'], 20)

    def test_local_request_and_static_scope(self):
        self.assertEqual(self.request(payload(), headers={'Origin':'https://example.com'})[0], 403)
        self.assertEqual(self.request(payload(), headers={'Host':'example.com'})[0], 403)
        self.assertEqual(self.request(payload(), headers={'X-Local-Demo':''})[0], 403)
        self.assertEqual(self.request(path='/server.py')[0], 404)
        self.assertEqual(self.request(path='/../server.py')[0], 404)

    def test_two_actual_pages(self):
        for version in ('a', 'b'):
            status, headers, body = self.request(path=f'/pages/{version}.html')
            self.assertEqual(status, 200)
            self.assertIn('VERSION '+version.upper(), body)
            self.assertIn('DEMO ONLY', body)

    def test_device_hints_and_user_agent_fallback(self):
        cases = [
            ({'Sec-CH-UA-Mobile':'?0', 'User-Agent':'Mozilla/5.0 (Windows NT 10.0)'}, 'A'),
            ({'Sec-CH-UA-Mobile':'?1'}, 'B'),
            ({'Sec-CH-UA-Mobile':'?0', 'User-Agent':'iPhone Mobile'}, 'A'),
            ({'User-Agent':'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Mobile Safari'}, 'B'),
            ({'User-Agent':'Mozilla/5.0 (Linux; Android 15) Chrome/130 Mobile Safari'}, 'B'),
            ({'User-Agent':'Mozilla/5.0 (Macintosh; Intel Mac OS X) Safari'}, 'A'),
            ({'User-Agent':'Mozilla/5.0 (iPad; CPU OS 18_0) Mobile Safari'}, 'A'),
            ({'User-Agent':'Mozilla/5.0 (Linux; Android 15) Chrome/130 Safari'}, 'A'),
            ({}, 'A'),
            ({'Sec-CH-UA-Mobile':'invalid', 'User-Agent':'iPhone Mobile'}, 'B'),
        ]
        for headers, expected in cases:
            with self.subTest(headers=headers):
                self.assertEqual(server.device_route(headers)['variant'], expected)

    def test_device_post_uses_headers_not_body_or_random_cookie(self):
        request = {'mode':'device', 'persona':'mobile', 'variant':'B'}
        status, headers, raw = self.request(request, headers={'Sec-CH-UA-Mobile':'?0', 'Cookie':server.COOKIE+'=B'})
        self.assertEqual(status, 201)
        self.assertEqual(json.loads(raw)['variant'], 'A')
        self.assertEqual(json.loads(raw)['persona'], 'desktop')
        self.assertNotIn('Set-Cookie', headers)
        self.assertEqual(server.history(self.path)['counts'], {'A':1, 'B':0})

    def test_same_entry_serves_real_html_by_device(self):
        for hint, expected in [('?0','A'), ('?1','B')]:
            status, headers, body = self.request(path='/entry', headers={'Sec-CH-UA-Mobile':hint})
            self.assertEqual(status, 200)
            self.assertEqual(headers['X-AB-Variant'], expected)
            self.assertIn('VERSION '+expected, body)
            self.assertIn('Sec-CH-UA-Mobile', headers['Vary'])
            self.assertEqual(headers['Cache-Control'], 'no-store')
        self.assertEqual(server.history(self.path)['counts'], {'A':1, 'B':1})

    def test_device_preview_and_detection_do_not_duplicate_visits(self):
        self.request(path='/api/device', headers={'Sec-CH-UA-Mobile':'?1'})
        status, headers, body = self.request(path='/device-preview', headers={'Sec-CH-UA-Mobile':'?1'})
        self.assertEqual(status, 200)
        self.assertIn('VERSION B', body)
        self.assertEqual(server.history(self.path)['entries'], [])


class ConfigurationTests(unittest.TestCase):
    request = DemoTests.request

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=TEMP)
        self.path = Path(self.temp.name) / 'visits.sqlite3'
        self.http = server.Server(('127.0.0.1', 0), self.path)
        self.entry = server.Server(('127.0.0.1', 0), self.path, role='entry', admin_port=self.http.server_port)
        self.http.entry_port = self.entry.server_port
        self.threads = [threading.Thread(target=http.serve_forever, daemon=True) for http in (self.http, self.entry)]
        for thread in self.threads:
            thread.start()

    def tearDown(self):
        for http in (self.http, self.entry):
            http.shutdown()
            http.server_close()
        for thread in self.threads:
            thread.join()
        self.temp.cleanup()

    def test_two_ports_separate_configuration_from_entry(self):
        config = json.loads(self.request(path='/api/config')[2])
        self.assertEqual(config['entryUrl'], f'http://127.0.0.1:{self.entry.server_port}/')
        for path in ('/api/config', '/api/history', '/app.js', '/server.py'):
            self.assertEqual(self.request(path=path, http=self.entry)[0], 404)
        self.assertEqual(self.request({'aUrl':'', 'bUrl':''}, '/api/config', http=self.entry)[0], 404)
        status, headers, body = self.request(path='/', http=self.entry, headers={'Sec-CH-UA-Mobile':'?1'})
        self.assertEqual(status, 200)
        self.assertIn('VERSION B', body)
        self.assertNotIn('Location', headers)

    def test_local_and_external_targets_persist_without_erasing_history(self):
        self.request(payload())
        targets = {'aUrl':'http://localhost:9000/path?q=one#section', 'bUrl':'https://example.com/catalog/'}
        self.assertEqual(self.request(targets, '/api/config')[0], 200)
        server.initialize(self.path)
        config = json.loads(self.request(path='/api/config')[2])
        self.assertEqual({key: config[key] for key in targets}, targets)
        self.assertEqual(server.history(self.path)['counts'], {'A':1, 'B':0})

    def test_entry_embeds_selected_url_with_no_redirect_or_server_fetch(self):
        targets = {'aUrl':'http://127.0.0.1:9/missing', 'bUrl':'https://example.com/path?q=1&label="two"'}
        self.request(targets, '/api/config')
        for hint, variant in [('?0','A'), ('?1','B')]:
            status, headers, body = self.request(path='/', headers={'Sec-CH-UA-Mobile':hint}, http=self.entry)
            self.assertEqual(status, 200)  # Port 9 need not exist: the server never fetches it.
            self.assertNotIn('Location', headers)
            self.assertEqual(headers['X-AB-Variant'], variant)
            self.assertEqual(headers['X-AB-Display'], 'embed')
            self.assertIn('<iframe src="'+server.html.escape(targets[variant.lower()+'Url'], quote=True)+'"', body)
            self.assertNotIn('window.location', body)
            self.assertNotIn('allow-top-navigation', body)
            self.assertIn('打开原网页', body)
        self.assertEqual(server.history(self.path)['counts'], {'A':1, 'B':1})

    def test_bad_url_or_loop_rejected_atomically(self):
        original = {'aUrl':'https://example.com/a', 'bUrl':'https://example.com/b'}
        self.request(original, '/api/config')
        bad_urls = ['javascript:alert(1)', 'file:///D:/index.html', 'https://user:secret@example.com/',
                    'https://example.com:bad/', 'https://example.com/\npath', 'https://example.com\\@localhost/',
                    f'http://localhost:{self.http.server_port}/', f'http://127.0.0.1:{self.entry.server_port}/',
                    f'http://[::1]:{self.entry.server_port}/', 123, 'https://'+'x'*2048]
        for value in bad_urls:
            with self.subTest(url=value):
                self.assertEqual(self.request({'aUrl':'', 'bUrl':value}, '/api/config')[0], 400)
                self.assertEqual(server.settings(self.path), original)
        for body in ([], {}, {'aUrl':''}):
            self.assertEqual(self.request(body, '/api/config')[0], 400)

    def test_configuration_write_requires_admin_origin_and_header(self):
        body = {'aUrl':'https://example.com', 'bUrl':''}
        self.assertEqual(self.request(body, '/api/config', {'X-Local-Demo':''})[0], 403)
        self.assertEqual(self.request(body, '/api/config', {'Origin':self.entry.entry_url})[0], 403)
        self.assertEqual(self.request(body, '/api/config', {'Origin':'https://example.com'})[0], 403)
        self.assertEqual(server.settings(self.path), {'aUrl':'', 'bUrl':''})

    def test_preview_uses_saved_urls_without_counting_and_empty_restores_builtin(self):
        self.request({'aUrl':'https://example.com', 'bUrl':'http://localhost:9000'}, '/api/config')
        for variant in ('a', 'b'):
            status, headers, body = self.request(path='/preview/'+variant, http=self.entry)
            self.assertEqual(status, 200)
            self.assertEqual(headers['X-AB-Display'], 'embed')
        self.assertEqual(server.history(self.path)['entries'], [])
        result = json.loads(self.request(payload(persona='customer'))[2])
        self.assertEqual(result['previewUrl'], self.entry.entry_url+'/preview/b')
        self.assertEqual(result['targetUrl'], 'http://localhost:9000')
        self.request({'aUrl':'', 'bUrl':''}, '/api/config')
        status, headers, body = self.request(path='/preview/b', http=self.entry)
        self.assertEqual(headers['X-AB-Display'], 'local-html')
        self.assertIn('VERSION B', body)

    def test_lan_entry_host_does_not_open_admin_or_configuration(self):
        for service in (self.http, self.entry):
            service.lan_address = '192.168.0.104'
            service.lan_network = server.ipaddress.ip_network('192.168.0.0/24')
        host = f'192.168.0.104:{self.entry.server_port}'
        status, headers, body = self.request(path='/', headers={'Host':host, 'User-Agent':'iPhone Mobile'}, http=self.entry)
        self.assertEqual(status, 200)
        self.assertEqual(headers['X-AB-Variant'], 'B')
        self.assertEqual(self.request(path='/api/config', headers={'Host':host}, http=self.entry)[0], 404)
        self.assertEqual(self.request(path='/', headers={'Host':f'192.168.0.104:{self.http.server_port}'})[0], 403)
        self.assertEqual(self.request(path='/', headers={'Host':f'example.com:{self.entry.server_port}'}, http=self.entry)[0], 403)
        self.assertEqual(self.request({'aUrl':f'http://{host}/','bUrl':''}, '/api/config')[0], 400)
        config = json.loads(self.request(path='/api/config')[2])
        self.assertEqual(config['lanEntryUrl'], f'http://{host}/')

    def test_lan_rejects_clients_outside_selected_subnet(self):
        self.entry.lan_address = '192.168.0.104'
        self.entry.lan_network = server.ipaddress.ip_network('192.168.0.0/24')
        handler = server.Handler.__new__(server.Handler)
        handler.server = self.entry
        handler.headers = {'Host':f'192.168.0.104:{self.entry.server_port}'}
        for address, expected in [('192.168.0.20',True), ('127.0.0.1',True), ('192.168.1.20',False), ('8.8.8.8',False)]:
            handler.client_address = (address, 50000)
            self.assertEqual(handler.local(), expected)


if __name__ == '__main__':
    unittest.main(verbosity=2)
