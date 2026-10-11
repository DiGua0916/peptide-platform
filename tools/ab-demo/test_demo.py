import http.client
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

    def request(self, body=None, path='/api/visit', headers=None):
        conn = http.client.HTTPConnection(*self.http.server_address, timeout=3)
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


if __name__ == '__main__':
    unittest.main(verbosity=2)
