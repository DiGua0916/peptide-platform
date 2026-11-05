# COA resource delivery and query performance — 2026-11-05

Author: Codex. Branch `codex/coa-delivery-20261105`, based on `b1c1717871997ee9073394f1e0d0b1958055a79f`. Local source candidate only; the site coordination chat owns integration, GitHub and deployment. No theme/Store API, original certificate, business data, active-plugin setting or server configuration was changed.

## Problem and resulting behavior

The deployed public archive ignored `Range: bytes=0-1023`: both measured PDFs returned HTTP 200 and the entire 2,157,035 / 2,416,573 bytes. PDFs/thumbnails were always no-store. Public list queries primed metadata but loaded report posts individually, checked every original and every cover, then sliced the page.

The candidate sends exact requested single ranges, supports HEAD/conditional revalidation, and streams binary data in bounded 64 KiB reads. Every request still passes the archive's current public opt-in, status/demo/password, local-path, MIME and content checks before either 206 or 304. This is only the explicitly public reference archive; the separate `/reports/{id}/file` stock-batch/staff access policy is unchanged.

The list query primes native report and related product/batch caches, including product-type terms. It still validates every original before calculating exact totals and filters, then validates covers only for the selected page. It does **not** introduce a persistent DTO/permission cache or claim O(page-size) original validation. DTO fields, current-origin URLs, ordering, filters, product summaries and pagination remain unchanged.

## Files and integration

- `includes/class-report-archive.php`: cache priming, selected-page covers, public route quota and response wiring.
- **New required runtime file** `includes/class-report-file-response.php`: autoloaded `PeptideCore\Report_File_Response`. Ship it together with the archive class.
- `tests/report-file-response.php`: standalone original-byte representation checks.
- `tests/report-file-response-http.py`: actual owned loopback PHP HTTP protocol checks using a clearly synthetic PDF.
- `tests/report-archive-delivery-routing.php`: actual WP request classes/controller/helper with isolated synthetic storage/publication/transients.

All paths above are under `wp-content/plugins/peptide-core/`. No installation, schema migration, report re-upload or PDF recompression is needed. The private file directory remains private. Existing thumbnail source-SHA binding and upload-replacement revocation are preserved. Existing derived covers are used; no Imagick/Ghostscript rendering is added to public GETs and no real certificate is rewritten. Missing covers retain the existing null/fallback contract.

## HTTP/asset contract for frontend and release coordinator

Existing endpoints and DTO URL fields are retained:

- `GET|HEAD /peptide/v1/report-library` (current JSON pagination/filter contract; still no-store).
- `GET|HEAD /peptide/v1/report-library/{id}/file?download=0|1`.
- `GET|HEAD /peptide/v1/report-library/{id}/thumbnail`.

Only file/thumbnail responses use `Cache-Control: private, no-cache, max-age=0, must-revalidate, no-transform`, actual-byte strong ETag and Last-Modified. They are **not public/immutable CDN objects**. Revalidation repeats the current permission checks; formerly downloaded public bytes cannot be recalled from a user's device. HEAD ignores Range, advertises full length and sends no body. A valid single range returns 206/Content-Range; malformed or unsatisfiable bytes return 416; unsupported units/multiple ranges deliberately use full 200. Conditional preconditions precede ranges, including 304/412. No wildcard CORS grant or cross-origin fetch proxy is added. The measured public DTO URLs were already same-origin.

The small-range quota is at most 960 total file attempts per 10 minutes; only GET, **absent** If-Range, and a valid effective single span <=1 MiB use that range allowance. Full/large/invalid/multipart/HEAD/If-Range requests additionally retain the old 120 full-file budget. This avoids blocking a 20 MiB PDF after 120 of its possible 320 default 64 KiB chunks while preventing a `Range` header from moving a full transfer into the small-range budget. Existing transient-based per-client rate limiter semantics remain unchanged; this is not a new atomic distributed limiter.

`WP_REST_Request::get_header()` returns null for absence. The route deliberately distinguishes absent If-Range from a present empty header: an empty validator can fall back to full 200 and must not bypass the full-file allowance. Missing Range is cast to the parser's string input.

### Precise server review allowlist (not applied here)

Actual public responses include a second server `Cache-Control: no-store`. That still constrains browser reuse after the PHP change. Review only GET/HEAD for canonical public paths matching `/wp-json/peptide/v1/report-library/[1-9][0-9]*/file` or `/thumbnail` (optional query `download=0|1`). If plain-permalink `rest_route` is supported, normalize and compare that exact route; never relax the entire `/`, `/wp-json`, or API location.

For eligible 200/206/304, preserve the upstream private revalidation/ETag/Last-Modified/Accept-Ranges/Content-Range/Content-Length headers, identity byte ranges, and 304/HEAD body suppression. Preserve no-store on 404/429/errors, the archive JSON query, private `/reports`, cart, checkout, account, payment and admin APIs. Do not alias the private report folder, cache everything, or give reports a public long TTL. If an Nginx/API rule appends no-store, the coordinator must inspect its exact matching rule; this document is a semantic allowlist, not a blind Nginx patch.

PDF.js module/worker are already same-origin legacy 6.5.438 and consistent. Browser gzip measured 291,603 + 651,709 bytes (943,312 total), not their 3.535 MB raw size. Viewer cancellation, fit-width, page rendering, list typography, routing/scroll lock and assets remain frontend-owned. Public archive requests should not attach an expired admin/REST nonce; keep private report authentication distinct. The current vendor lacks `Promise.withResolvers` shim in both main/worker paths: this is a compatibility risk, **not** proof of the user's iPhone error without its runtime log. The frontend should feature-test and verify both execution contexts.

## Actual performance and checks

Evidence root: `D:/Codex/Tasks/2026-11-05/coa-delivery-performance/`.

1. **Deployed HTTP baseline**, public resources only: two PDFs transferred whole files for a 1 KiB Range; proxy first GET 3.25 / 13.71 s, repeats 3.33 / 3.41 s; query per-page 24 about 2.30 s and per-page 2 about 2.08 s. MIME/HEAD/PDF signature/DTO sizes were correct. No HTTP loading failure or corrupted PDF was reproduced; do not label the screenshot cancellation as CORS/corruption. Direct/proxy conditions are separate and no CDN/OS cache was purged. See `outputs/http-baseline-analysis.md` and JSON.

2. **Real native WordPress service comparison**, existing 219 public reports, not fixture data: all five complete DTOs match baseline exactly. First SQL is **444 -> 62 (-86%)** for every case. Initial same-process repeat median **134.0 -> 109.2 ms**; product 138.9 -> 109.3, search 141.2 -> 118.3, page2 150.6 -> 109.7, date/oldest 203.6 -> 106.8 ms. First-call observations range 427.9–1132.6 ms before / 203.9–302.9 ms after, under concurrent desktop load; do not promise the same production percentage. Every original is still checked (219 -> 219); cover checks become 12/6/1/2/10 for those pages instead of 219. Query methods in the final source match the measured frozen candidate; its only later route change corrects null header handling. See `outputs/native-query-benchmark-analysis.md` and `native-query-benchmark-20261105T131955Z.json`.

   Both final CLI variants exclude only WooPayments for that process after a strict guard blocked its expired locale transient deletion during bootstrap. WooCommerce/Core and genuine records remain loaded; active_plugins/files/config are unchanged. Twenty final processes report zero SQL write attempts, external HTTP or mail. Earlier failure and sanitized trace remain. First service call is after WP bootstrap, not cold OS/DB/CDN. These numbers are not installed HTTP or phone timings. Two selected public originals were re-read with matching complete SHA; no claim of a fresh full-byte audit of all 219.

3. **Protocol tests**: 80 standalone assertions, 19 actual owned PHP HTTP checks, plus 56 isolated routing cases / 133 assertions. The routing checks use real installed WP request/error/post classes and actual controller/helper, with synthetic records/transients/files; they are not live authentication/DB/browser acceptance. Revoked/withdrawn/superseded/password/demo/no-opt-in cases cannot reach file opening/304/206; thumbnail source binding, null headers and both quotas are covered. Existing 35 public presentation and 6 client checks pass. PHP lint and diff checks pass.

4. **Strong ETag cost is explicit**: an 8,400,667-byte synthetic non-certificate prefix request drops from whole 8.4 MB to 65,536 bytes (-99.22% for that prefix); 304 has zero body and PHP peak is 2 MiB. Parent rerun TTFB was ~26.8/47.0 ms for new range versus ~1.45/1.36 ms for old readfile (new request hashes the original first). This does **not** prove faster full-viewer latency. Every range currently hashes all source bytes; a 20 MiB PDF requested in 320 default 64 KiB chunks would read about 6.25 GiB for hashes. No cross-request digest cache was silently introduced. Measure real first-page requests and choose bounded larger chunks/avoid needless full-document prefetch in the frontend. [PDF.js loading options](https://mozilla.github.io/pdf.js/api/draft/module-pdfjsLib.html) explain that disabling automatic prefetch also requires disabling streaming. HTTP conditional/range semantics follow [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html#section-14.2).

## Remaining acceptance

The coordinator must integrate the frontend candidate, review the exact extra server no-store rule, deploy both runtime PHP files, and test actual mobile first page/open/retry/back/cancel, header statuses, browser cache/revocation, range request count and total bytes. No original PDF, real order, payment, message, customer or production state was changed here. No Git push or server deployment was performed by this task. Source/fixtures/protocol/native CLI checks must not be described as real-phone acceptance.
