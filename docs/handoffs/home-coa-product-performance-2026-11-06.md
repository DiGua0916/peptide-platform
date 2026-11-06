# Homepage COA and product opening follow-up — 2026-11-06

Scope: sandbox shop/admin only; production and main unchanged. Continues dad2dac fourteen-point work after user identified homepage COA and product artwork as remaining slow paths.

## Evidence and changes
- Homepage report links still used legacy iframe, bypassing the repaired archive reader. They now use the same public-resource reader and history; original/download links, provenance and safe native fallback remain.
- Nonlinearized report 11617 is 3,713,351 bytes. Offline actual PDF.js required five dependent ranges totaling the file before first-page operators. Injected 200 ms per range added ~1.01 s; this is protocol evidence, not browser paint timing.
- Actual on-disk public DTO file.size_bytes reaches product, homepage and archive reader. Only safe integer sizes 1..4 MiB on genuine public library URLs select continuous native PDF fetch. Large, unknown and protected documents retain range loading. Native credentials, guarded URLs, deadlines and cancellation unchanged; PDF originals untouched.
- Actual browser: homepage 11580, 2,367,556 bytes, one HTTP 200, no Range, Resource Timing duration 482.3 ms, reader ready with one page. Product 11617, 3,713,351 bytes, one HTTP 200, no Range, duration 472.5 ms, reader ready with four canvases/pages. These are download durations, not click-to-paint or field p75; physical iPhone and slower-network streaming still unmeasured.
- Product template payload: 117,215 bytes identity vs 8,831 gzip on sandbox Nginx, private/no-store preserved. Recipe tools/sandbox/json-compression-nginx.conf. Runtime backup json-compression-before-1793958002.conf.
- 18 observed 1024px PNG derivatives gained lossless negotiated WebP: 6,801,039→4,038,420 bytes; pixel identity verified, original files retained. These sandbox runtime media are not Git files.
- Native template prefetch: genuine hero plus debounced pointer/focus, max two IDs/page and one active shared read. Unused HTML expires after 30 seconds including synchronous click-time expiry. Only actual product-presented hands ownership permanently to native flow; superseded clicks cannot retain unused results forever. Hidden/exit retires unused work without aborting genuine shared clicks.
- SKU image preloading previously treated CPU idle as network idle: it could start two downloads while selected image was still loading. It now waits for selected image completion/error, retaining bounded low-priority two-wide loading, close/hidden cleanup and SaveData/2g skip. New regression failed before repair, passes after.
- Actual mobile-width browser: native 20→15 mg selection retained current page, selected image complete, correct 15 mg/10 vials per box, $50 per box, no prior sale badge. No payment/order generated.
- Isolated sandbox PHP quota 75→150% after measured two-request queue trial (wall .648→.379 s), persisted /etc/systemd/system/vialcove-sandbox-php.service.d/60-cpu-performance.conf. 1 GiB memory cap/security unchanged; host headroom measured. Runtime backup persistent-cpu-before-1793959288.

## Verification
Root: 61 Node test entries (navigation script additionally reports 11 internal checks), 4 actual PHP homepage-size checks + 5 prefetch enqueue + 8 viewer enqueue checks. PHP lint during sandbox deployment. New deadline/cancel/size provenance, TTL supersession and selected-image priority regressions covered. Latest candidate backup fourteen-candidate-1793962732, ten files per site, twenty SHA checks.

Receipts and screenshots: D:/Codex/Tasks/2026-11-06/fourteen-point-repair/outputs, especially pdf-single-stream-browser.json, home-coa-streaming-ready.png, product-four-page-streaming.png, candidate-deployment.json.

## Limits
No claim all stutter eliminated. Unseen products still need first native API response and image bytes; existing non-linearized PDF content still needs full bytes. SSH bandwidth samples (3.35–32.08 Mbps) describe current port-22 path only, not HTTPS capacity or provisioned bandwidth. Earlier 209 ms hero measurement preceded TTL fix and is not final performance proof. Do not publish it as current guaranteed opening speed.
