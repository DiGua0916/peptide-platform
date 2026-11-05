# Sandbox performance acceptance — 2026-11-06

Scope: current VialCove sandbox candidate only. No production rollout or redesign. Browser: authenticated desktop Chromium at 390×844 CSS-pixel viewport, no CPU/network throttling. These are lab observations, not physical-iPhone or field p75 results.

## Changes verified

- Existing theme/Core candidate reduces server rendering work and initial HTML; previous matching DTO/query checks remain recorded in the earlier handoffs.
- 95 non-font resource comparisons: 94 compressed, total bytes 1,514,895 → 386,387. This is resource transfer reduction, not a loading-speed percentage.
- PDF reader requests first-page geometry immediately, defers later geometry/raster work and bounds retained canvases. Actual 3-page PDF opens at fitted width, zooms to 150%, PageDown displays pages 2 and 3, and closes back to the correct product.
- Product 20mg → 15mg → 20mg restores exact price, discount and matching COA; pending updates cannot retain another SKU's discount or lose its report.
- Celebration artwork no longer downloads on initial storefront load: 736KB transfer removed until qualifying visible feedback needs it.
- Offline lossless WebP sidecars: 16 of 20 observed PNG uploads pass exact decoded-pixel comparison and shrink. Four transparent images fail that strict comparison and remain PNG. Combined bytes for these 20 images: 2,450,547 → 1,464,461 (about 40% lower). Originals untouched, verified by SHA256. No original COA/PDF changes.

## Runtime acceptance

40 server requests verify PNG/WebP negotiation, exact served bytes, correct MIME, Vary Accept and private/no-store. q=0 explicitly falls back to PNG. External unauthenticated request still redirects to the web login. Nginx syntax passes and sandbox PHP is active. Browser ResourceTiming confirms all 16 smaller product responses; no broken images or reopened dialog in the final homepage sample.

New server rollback point: /var/lib/vialcove-sandbox/backups/nginx-webp-before-1793905129.conf. Removing negotiation restores original PNG delivery; originals were never replaced. Shared sandbox uploads resolve to /srv/vialcove-sandbox/shared/uploads, not production. Generated sidecars and raw traces stay outside Git. Versioned tools/sandbox/lossless-webp.php provides the offline conversion procedure; lossless-webp-nginx.conf is a merge recipe, not a complete server configuration.

Fresh seven-suite regression run: 62 tests passed, 0 failed, including PDF geometry/cancellation, report pagination, pending selection ownership, device changes and cart celebration lifecycle. Browser viewport restored; tracing ended and stream handles released.

Independent review found a stale-sidecar case in the reusable converter after same-path source replacement. Fixed: rejected conversion removes the old sidecar; failed removal throws. Actual synthetic sandbox replacement test verifies original hash preserved and stale sidecar removed; fixture cleaned. PHP lint and product/transparent-fallback runtime checks pass. Reviewer confirmed no remaining important findings. This is an offline converter: run it again when replacing source images at the same path; it is not an automatic upload hook.

## Measurements and limits

Pure-homepage observations (ordinary reload, no dialog):

| Sample | Connection | TTFB after request | FCP | DOM ready | Load |
|---|---:|---:|---:|---:|---:|
| Before WebP, reused connection | not separately captured | 1.136s | 1.748s | 4.222s | 5.326s |
| Before WebP, new connection | 2.067s | 1.376s | 4.480s | 6.859s | 7.579s |
| With WebP, new connection | 1.253s | 1.244s | 3.428s | 5.306s | 6.169s |

Connection states differ, so these samples do not establish a causal timing percentage or a comparable median. Byte reductions are independently proven. Initial samples with restored product dialog are explicitly excluded from homepage-only LCP claims.

Actual navigation trace contains 8,751 events. Longest recorded FunctionCall 18.376ms; no FunctionCall/EvaluateScript/ParseHTML event over 50ms. Sum of layout-shift scores without recent input 0.00507 (not a field CLS claim). TLS alone 2.045s in that navigation; send-to-response-headers 1.376s. The first attempted trace contained no navigation and is excluded. Browser tooling latency is not page latency.

This closes the scoped sandbox optimization and functional regression work. It does not promise universally instant access, a real-device INP result, or a production improvement. Geographic/proxy/TLS latency remains measurable; CDN/edge deployment and production field monitoring would be separate rollout work. No real payments, external emails or production data writes were used for acceptance.

Evidence: D:/Codex/Tasks/2026-11-06/skills-performance-rollout/outputs/{webp-generated,webp-verification,webp-negotiation-deployment,browser-navigation-trace-summary,browser-webp-after}.json. Screenshot: D:/Codex/Tasks/2026-11-06/chrome-sandbox-acceptance/outputs/home-lossless-webp.png.
