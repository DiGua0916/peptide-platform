# Storefront delivery, selected-SKU pricing and Core performance

2026-11-05 · Codex. Integration owner: the existing “独立站后端 站点” chat. This batch is a local commit for coordinated acceptance; it has not been pushed, installed into the shared runtime, or deployed independently.

## Problem and resulting behavior

The reported TR15 product view retained the initial TR20 discount. Public production reads prove TR15/11309 is $50/$50, zero savings and `on_sale=false`; TR20/11307 is $55/$78 and 29%. The existing Core returns the correct selected-SKU identity and explicit zero/null clearing values. A new regression test protects that contract; theme code must clear the old ribbon, comparison price and accessible text rather than reuse a parent's aggregate `on_sale` flag. No product price, pack count or stock was changed.

`Pricing::card_pair()` now batch primes the visible children’s native posts/meta before the unchanged Woo product, purchasability and pricing loop. This avoids per-child database reads without caching computed quotes, using another SKU's comparison price, or bypassing dynamic price filters. The existing raw object-cache backend remains authoritative.

The user chose to retain automatic country/language recognition and one readable DB-IP attribution. The duplicate attribution in the language switcher is removed; `Country_Provider::render_attribution()` remains the canonical renderer, invoked once by the existing theme footer. Country lookup, trusted IP boundaries, database paths and attribution configuration remain unchanged. The theme owner has been told to retain that last footer call. No geo source migration or loss of PR detection is intended.

## Changed scope

- `wp-content/plugins/peptide-core/includes/class-pricing.php`: native raw post/meta priming only.
- `wp-content/plugins/peptide-core/includes/class-storefront-translator.php`: remove switcher duplicate credit only.
- `wp-content/plugins/peptide-core/tests/variation-pricing-identity-pure.php`: real Pricing/Promotions contracts with in-memory Woo fixtures.
- `wp-content/plugins/peptide-core/tests/country-resource-attribution-pure.php`: canonical attribution/output regression.
- This handoff.

No theme, payment plugin, COA/file controller, product data, shipping rules, application configuration or production server configuration is changed by this commit. There is no schema or option migration. Deploy the two changed Core runtime files through the coordinating owner's normal reviewed release.

## Measured performance and limits

Real installed LocalWP, separate fresh PHP/WP processes, same 24 public products / 48 visible children: pricing queries **192 → 144**; full catalog-query-plus-pricing queries **226 → 178**. Baseline/candidate have identical included dependencies excluding the changed Pricing class; the full price-pair JSON SHA256 is identical. Timing varies substantially, so this is a reproducible query reduction, not a claimed production or whole-page speedup.

The real published local homepage performs 1,918 logged SQL queries, with most work during full-catalog rendering. Repeated native StoreAPI term lists, per-card layout settings and held-stock checks were identified and sent to the theme owner. Do not replace native held-stock checks with stale cached availability. A response-local reuse of identical layout settings is a theme task; Core template creation/invalidation was not changed.

The profiler uses a loopback-only PHP development server and actual LocalWP data, not production FPM or Safari. Its SQL guard starts at the first Core autoload; verified full query logs contain SELECT/SHOW only. A later applied-class check encountered three refused DELETE attempts and concurrent theme changes, so its elapsed time is excluded. No proof of pre-bootstrap outbound HTTP is claimed. The temporary server was stopped and no profiling endpoint was installed.

## Delivery audit

Fresh GitHub main during this audit: `b1c1717871997ee9073394f1e0d0b1958055a79f`. The prior accepted checkout/phone/payment batch has production release receipts. It must not be confused with this ongoing mobile repair batch or the front-end working tree's uncommitted changes.

Two bare asset URLs served old Cloudflare HIT bodies matching `3dcc478`. Actual HTML's `live-cart-experience.js?ver=1793879525` and fresh-query diagnostics match main. The site owner independently verified both changed source files and filemtime URLs. This does not establish stale CDN as the user's actual mobile-rendering cause; no broad purge or cache-rule change was made. Anonymous empty-cart checkout cannot prove nonempty checkout's rendered asset set.

Current public app safety fields were also rechecked: broadcast enabled with 998 items, four templates and three parameter columns on both instances; activity listener uses promotion10980 and gap ratio0.3; both countdown placements are active for the same promotion and end time. Earlier audit records of disabled production broadcast/countdown are outdated. No parameter values or customer data were saved in this batch.

## Verification and remaining acceptance

Root reran the selected-SKU contract (104/104), existing unit-pricing contract (109/109), attribution output regression (82/82), provider suite (139 pass, one existing real-MMDB-fixture skip), four changed PHP files' lint, and whitespace checks. The geo owner also ran HTML integration (85/85), resolver (146/146) and an old-source replay that correctly failed the duplicate-credit regression. Independent pricing candidate review exercised dynamic getters and purchasability with the priming branch. These fixtures are not browser tests; task receipts distinguish each layer.

The coordinating owner still needs integrated actual-mobile acceptance: discounted SKU → non-discounted SKU → discounted SKU, reopen/rapid selection/failure recovery, main price per box with quantity1 equal to one existing box, and one readable attribution on automatic and manually selected EN/ES pages. Preserve automatic recognition and historical tracking. Nothing in this report declares true iOS rendering or a real payment flow accepted.

Evidence root: `D:/Codex/Tasks/2026-11-05/core-storefront-pricing-performance/outputs/`:

- `GitHub与手机差异复核.md`, `github-delivery-recheck.json`, `github-delivery-versioned-followup.json`, `published-apps-recheck.json`.
- `variation.md`, `variation-public-readback.json`, `root-verification.json`.
- `performance.md`, `pricing-baseline-verified.json`, `pricing-candidate-verified.json`, `pricing-applied-verified.json`, `http-home-verified.json`.
- `geo.md` and its output/provider test receipts.

Do not deploy the task's local profiler, private resource files, customer parameters, or dirty runtime trees. This source handoff is the small reviewed Core change; the front-end and shop/COA owners deliver their separate commits to the same coordinator.
