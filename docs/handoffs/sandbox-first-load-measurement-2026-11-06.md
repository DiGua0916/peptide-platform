# Sandbox first-load measurement and deferred cart artwork

2026-11-06, Codex. Only sandbox shop/admin updated; production unchanged.

Browser resource timing exposed a 736,357-byte transfer for live-cart-bean.png on initial navigation, before any qualifying cart celebration. createCartProgress assigned Image.src during initialization. The sprite is now requested once, only after a visible qualifying celebration requires it. Original artwork, prices, eligibility, reduced-motion handling and cancellation guards remain intact.

Added request-count regression checks. Old implementation: 9 passed, 2 failed. Fixed implementation: all 11 cart-progress-lifecycle tests passed. Live sandbox response bytes verified; backup /var/lib/vialcove-sandbox/backups/lazy-cart-art-1793903249. JS SHA256 eece255eb81622ce622092023d24246646f8b602798233b77a46e878e320c0b3.

## Browser evidence

Authenticated IAB desktop Chromium, viewport 390x844, unthrottled, ordinary reload (not a controlled cold cache), no physical-device field data. Browser command wall time is not website latency.

- Before artwork change navigation: TTFB 1141ms, FCP 1772ms. Resource timing includes restored product dialog: not a pure-homepage total.
- After change same restored-dialog flow: TTFB 1129ms, FCP 1740ms; live-cart-bean requests 0. Single-run FCP difference is too small to establish a speed improvement.
- Buffered LCP entries revealed homepage image at 1776ms and subsequently restored product dialog image at 4792ms. Do not report 4792ms as homepage-only LCP.
- After closing product dialog and reloading: pure homepage TTFB 1136ms, FCP 1748ms, DOMContentLoaded 4222ms, load 5326ms, 128 resources. LCP buffered callback timed out for this run; not reported as passing.

Artifacts are under D:/Codex/Tasks/2026-11-06/skills-performance-rollout/outputs/: browser-navigation-1.json, browser-navigation-after-1.json, browser-home-after-1.json, lazy-cart-art-deployment.json.

Remaining: equivalent repeated mobile lab runs, request-chain/main-thread attribution and first-load reduction beyond this unnecessary sprite. No median, real-device LCP/INP/CLS or overall-completion claim is supported yet. Existing compression and lazy PDF work remain deployed in sandbox.
