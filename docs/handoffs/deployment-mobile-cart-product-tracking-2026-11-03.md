# Scoped cart/card UI publication

2026-11-03 · Codex · source `0635585d19fa2e04b88e98440907bc174eeacec4` (fix `9a75aba`).

## Delivered

Buyer `https://www.vialcovepeptides.com/` and merchant-preview runtime `https://s0001.vialcoveresearch.com/` now carry the ten reviewed cart/navigation/tracking files from this immutable source. This is an executed publication, separate from the earlier local-theme junction and Git push.

Each host cloned its own current release and overlaid only those ten files. No plugin, MU plugin, shared links, private configuration, database content, payment settings, order or customer address changes were made. The help-only `inc/live-commerce-settings.php` change was excluded because its complete local version also includes an unrelated unpublished FAQ change. Other undeployed FAQ files remain outside this payload.

The first web attempt stopped before candidate creation because another publisher had changed `current`. Fresh read-only preflight confirmed the new `pay-paired-capture-3fc6fcd9741c` runtime still had all expected old theme hashes. The same guards were retained and a new payload used those current pointer/inode/manifests; no expected hash was weakened. The new candidate therefore preserves the concurrent payment changes.

| Host | Previous release | New release | Completed UTC |
| --- | --- | --- | --- |
| web | `pay-paired-capture-3fc6fcd9741c` | `cart-cards-0635585d19fa-web01` | 2026-11-03 07:03:46 |
| admin | `admin-performance-d783d1ba4d81` | `cart-cards-0635585d19fa-admin01` | 2026-11-03 07:05:00 |

Both receipts confirm candidate PHP lint, atomic pointer switch, graceful FPM reload, complete runtime manifest boundary, private configuration inode/hash preservation, and exact HTTPS hashes/content types for all seven JS/CSS assets. Buyer homepage and merchant login health checks returned 200.

## Browser and test evidence

The actual anonymous buyer browser loaded all three new tracking assets at the new cache version. Header Bag opened an empty native drawer while URL stayed `https://www.vialcovepeptides.com/`; the temporary verification tab was closed. The responsive local formal site at 390 × 844 separately verified checkout Bag, product-to-Bag nesting and browser Back retaining the original product. No cart mutation, address entry, order or payment was performed.

The user's retained right-side preview is the existing same-origin tool at `/wp-content/themes/peptide-site/tools/mobile-preview.html`, displaying checkout at 390 × 844. It uses the real local formal site and shared cart, not the old standalone demo. Screenshot: `D:/Codex/Tasks/2026-11-03/mobile-cart-product-tracking/outputs/mobile-checkout-preview.png`.

Regression details and limits remain in [frontend-mobile-cart-product-tracking-2026-11-03.md](frontend-mobile-cart-product-tracking-2026-11-03.md). We did not inspect a real visitor record or claim a physical-device/real-payment test. The local staff session remains excluded from tracking; the public guest check proves resource loading and cart behavior, not a collector/server receipt.

## Operational artifacts

Outside Git: `D:/Codex/Tasks/2026-11-03/mobile-cart-product-tracking/{work,outputs}`. The outputs contain `cart-published-web.json`, `cart-published-admin.json`, per-host fresh baselines and payload verification. Each remote private checkpoint at `/var/lib/vialcove-ui-deploy/<new-release>/` contains the original manifest/state and receipt. Guarded manual rollback is provided by `work/run_cart_remote.py rollback web|admin --commit 0635585d19fa2e04b88e98440907bc174eeacec4`; it refuses after an unrelated pointer/config/code change and does not rewind a database. Rollback was not invoked.
