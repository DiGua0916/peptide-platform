"""Owned loopback PHP HTTP protocol checks using a synthetic non-certificate PDF.

Usage: python report-file-response-http.py PHP_EXE ABSOLUTE_D_TASK_DIRECTORY
No WordPress bootstrap, real reports, browser, database, provider or server deployment.
"""
from pathlib import Path
import hashlib, http.client, json, os, socket, subprocess, sys, time, uuid

php = Path(sys.argv[1]).resolve(strict=True)
work = Path(sys.argv[2]).resolve(strict=True)
helper = Path(__file__).resolve().parents[1] / "includes/class-report-file-response.php"
if os.name == "nt" and work.drive.lower() != "d:":
    raise RuntimeError("Task artifacts must be on D drive")
folder = work / ("http-representation-" + uuid.uuid4().hex[:12])
folder.mkdir()
fixture, router, log, memory = [folder / x for x in ("synthetic-http-fixture.pdf", "router.php", "php-server.log", "memory.jsonl")]

def php_string(value):
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"

# Valid blank PDF with a clear synthetic statement; its padding is PDF comments.
stream = b"BT /F1 12 Tf 40 720 Td (Synthetic HTTP fixture - NOT a certificate) Tj ET\n"
stream += b"% synthetic range-delivery padding\n" * 240000
objects = [
    b"<< /Type /Catalog /Pages 2 0 R >>",
    b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
    b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"endstream",
    b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
]
body = bytearray(b"%PDF-1.4\n% Synthetic non-certificate HTTP fixture\n")
offsets = []
for index, obj in enumerate(objects, 1):
    offsets.append(len(body)); body += str(index).encode() + b" 0 obj\n" + obj + b"\nendobj\n"
xref = len(body)
body += b"xref\n0 6\n0000000000 65535 f \n"
for offset in offsets: body += f"{offset:010d} 00000 n \n".encode()
body += b"trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" + str(xref).encode() + b"\n%%EOF\n"
fixture.write_bytes(body)
os.utime(fixture, (1700000000, 1700000000))
source_sha = hashlib.sha256(body).hexdigest()
router.write_text("""<?php
define('ABSPATH', __DIR__.'/');
require HELPER;
register_shutdown_function(static function() { file_put_contents(MEMORY, json_encode(['peak_bytes'=>memory_get_peak_usage(true)])."\\n",FILE_APPEND); });
$file=['path'=>FIXTURE,'mime'=>'application/pdf','ext'=>'pdf','size'=>filesize(FIXTURE),'original'=>'synthetic-http-fixture.pdf'];
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/denied.pdf'){ http_response_code(404);header('Cache-Control: no-store');exit; }
if($path==='/legacy.pdf'){
 header('Content-Type: application/pdf');header('Content-Length: '.$file['size']);header('Cache-Control: no-store, max-age=0');
 if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile($file['path']);exit;
}
if($path!=='/file.pdf'){http_response_code(404);exit;}
PeptideCore\\Report_File_Response::send($file,false,$_SERVER['REQUEST_METHOD']==='HEAD',42,getallheaders());
""".replace("HELPER", php_string(helper)).replace("MEMORY", php_string(memory)).replace("FIXTURE", php_string(fixture)), encoding="utf-8")

sock = socket.socket(); sock.bind(("127.0.0.1", 0)); port = sock.getsockname()[1]; sock.close()
checks, timings = 0, []
def check(condition, label):
    global checks
    checks += 1
    if not condition: raise AssertionError(label)

def request(path="/file.pdf", method="GET", headers=None, label=None):
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=20)
    started = time.perf_counter()
    connection.request(method, path, headers=headers or {})
    response = connection.getresponse(); ttfb = time.perf_counter() - started
    data = response.read(); elapsed = time.perf_counter() - started
    result = (response.status, {k.lower(): v for k, v in response.getheaders()}, data)
    connection.close()
    if label:
        timings.append(dict(label=label, status=result[0], bytes=len(data), ttfb_ms=round(ttfb*1000,3), elapsed_ms=round(elapsed*1000,3)))
    return result

server = None
with log.open("wb") as output:
    try:
        server = subprocess.Popen([str(php), "-n", "-d", "zlib.output_compression=1", "-S", f"127.0.0.1:{port}", str(router)], cwd=folder,
            stdout=output, stderr=output, creationflags=subprocess.CREATE_NO_WINDOW if os.name=="nt" else 0)
        deadline = time.monotonic()+8
        while True:
            try:
                with socket.create_connection(("127.0.0.1",port),timeout=.3): break
            except OSError:
                if server.poll() is not None or time.monotonic()>deadline: raise RuntimeError("Owned PHP server did not start")
                time.sleep(.05)
        legacy = request("/legacy.pdf", headers={"Range":"bytes=0-65535"}, label="legacy_first_range_ignored")
        check(legacy[0]==200 and legacy[2]==body,"Legacy streaming sends every original byte despite Range")
        legacy2 = request("/legacy.pdf", headers={"Range":"bytes=0-65535"}, label="legacy_repeat_range_ignored")
        check(legacy2[2]==body,"Legacy repeat byte identity")
        first = request(headers={"Range":"bytes=0-65535"},label="candidate_first_64KiB_range")
        repeat = request(headers={"Range":"bytes=0-65535"},label="candidate_repeat_64KiB_range")
        check(first[0]==206 and first[2]==body[:65536],"Actual GET returns the requested original prefix")
        check(repeat[2]==first[2] and repeat[1]["etag"]==first[1]["etag"],"Repeat prefix and content validator are identical")
        check(first[1]["content-length"]=="65536" and first[1]["content-range"]==f"bytes 0-65535/{len(body)}","Actual 206 length/range headers")
        check(first[1]["etag"]=='"sha256-'+source_sha+'"',"Actual ETag hashes original fixture bytes")
        check(first[1]["accept-ranges"]=="bytes" and "private, no-cache" in first[1]["cache-control"],"Actual private range/revalidation headers")
        tail = request(headers={"Range":"bytes=-37"})
        check(tail[0]==206 and tail[2]==body[-37:],"Actual suffix exact bytes")
        compressed = request(headers={"Range":"bytes=0-4","Accept-Encoding":"gzip"})
        check(compressed[0]==206 and compressed[2]==b"%PDF-" and "content-encoding" not in compressed[1],"Range bytes stay untransformed even with PHP compression configured and gzip accepted")
        one = request(headers={"Range":f"bytes={len(body)-1}-"})
        check(one[0]==206 and one[2]==body[-1:],"Actual final byte")
        bad = request(headers={"Range":f"bytes={len(body)}-"})
        check(bad[0]==416 and bad[2]==b"" and bad[1]["content-range"]==f"bytes */{len(body)}","Actual 416 has no body")
        head = request(method="HEAD",headers={"Range":"bytes=0-4"})
        check(head[0]==200 and head[2]==b"" and head[1]["content-length"]==str(len(body)),"Actual HEAD ignores Range and transfers no content")
        unchanged = request(headers={"If-None-Match":"W/"+first[1]["etag"],"Range":"bytes=0-4"},label="candidate_validated_repeat_304")
        check(unchanged[0]==304 and unchanged[2]==b"" and "content-range" not in unchanged[1],"Actual conditional GET precedes Range")
        if_range = request(headers={"If-Range":first[1]["etag"],"Range":"bytes=0-4"})
        check(if_range[0]==206 and if_range[2]==b"%PDF-","Actual matching If-Range")
        replaced = request(headers={"If-Range":'"not-current"',"Range":"bytes=0-4"})
        check(replaced[0]==200 and replaced[2]==body,"Actual stale If-Range returns full representation")
        multi = request(headers={"Range":"bytes=0-4,10-14"})
        check(multi[0]==200 and multi[2]==body,"Actual unsupported multipart request is ignored")
        denied = request("/denied.pdf",headers={"If-None-Match":"*","Range":"bytes=0-4"})
        check(denied[0]==404 and denied[2]==b"","Caller rejection precedes every response helper/validator")
        check(hashlib.sha256(fixture.read_bytes()).hexdigest()==source_sha,"HTTP delivery never modifies original fixture")
        peak = [json.loads(line)["peak_bytes"] for line in memory.read_text().splitlines()]
        check(max(peak)<len(body),"PHP delivery memory stays below the representation size")
        result=dict(passed=checks, fixture_bytes=len(body), fixture_sha256=source_sha, php_peak_bytes=max(peak),timings=timings,
            scope="Real owned loopback PHP HTTP requests to production helper with synthetic PDF; no WordPress/authentication/browser/real certificate/server deployment.",
            timing_limits="First and repeated TCP requests only. OS filesystem cache was not flushed; loopback timing is not WAN, real-site cold/warm or mobile acceptance.")
        destination=work/"report-file-response-http-result.json"; destination.write_text(json.dumps(result,indent=2),encoding="utf8")
        print(json.dumps(result))
    finally:
        if server is not None and server.poll() is None:
            server.terminate()
            try: server.wait(timeout=5)
            except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
        # Exact own files only; no recursive deletion or computed external target.
        fixture.unlink(missing_ok=True); router.unlink(missing_ok=True)
