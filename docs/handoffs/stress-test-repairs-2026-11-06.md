# Stress-test repairs — 2026-11-06

Scope: branch `codex/checkout-acceptance-20261105` only, on top of `1a7bb95`. Not merged to main, not deployed, no sandbox or production server touched. Work was done by Claude on a fresh clone (macOS, no WordPress/MySQL runtime); the D-drive project chat room was not available, so this file is the progress record.

## Status by stress-test finding

| # | Finding | Status |
| --- | --- | --- |
| 1 | Concurrent add-to-cart loses quantity | Fixed in code; MySQL concurrency not yet verified |
| 2 | Homepage has no full-page cache (sandbox p50 ≈3.9 s at 10 concurrency; production ≈1.8–2.0 s) | Opt-in cache built after owner approval (off until enabled); not measured |
| 3 | Purchasable "测试" product id 11738 on production | No code change; commands below for the owner |
| 4 | Mobile checkout summary: no quantity, no "10 vials per box", label "Specificatio" | Fixed |
| 5 | HQ new payment account defaults: enabled, live, all stores | Fixed after owner decision "default off" (follow-up commit) |
| 6 | Payment on a cancelled order only adds a note | Fixed; real-DB contract test updated but not run |
| 7 | `tests/visitor-intents.cjs` and `tests/visitor-products-config.php` fail on 1a7bb95 | Fixed (test drift) |

## Changes

**7 — failing tests (theme tests only, no production code).** Both were stale harnesses, not product regressions.
- `visitor-products-config.php`: `9a75aba` intentionally added `vialcove-visitor-surfaces` as an observer dependency; the expectation now includes it. `vc_product_card` gained `vc_card_display_product()`, `vc_sale_percent()` and `$product->is_type()` calls; matching stubs added.
- `visitor-intents.cjs`: `dad2dac` added the `stagedRestore` closure variable to `home-quick-view.js`; the extracted-code `vm` context now provides it (as `home-quick-pending.cjs` already does).

**4 — mobile checkout summary.**
- `assets/checkout-polish.css`: the native quantity badge had `color:#fff` on WooCommerce's white badge background (invisible). It is now ink-on-white-ring. A text line style `.vc-summary-pack` was added.
- `assets/checkout-polish.js`: under each native order-summary item, adds "Qty: N box(es) · 10 vials per box" (Spanish: "Cant.: N caja(s) · 10 viales por caja"). Data comes from the Woo cart store (`wc/store/cart`): quantity plus `extensions.peptide_core.unit_pricing.vialsPerSalesUnit`. A row is labelled only when the row count equals the item count and the decoded item name equals the rendered name; otherwise no line (never guessed). No per-box claim without verified unit pricing, and none for single-vial units. `inc/cart-pricing.php` adds `wp-data` and `wc-blocks-data-store` as script dependencies.
- `inc/product-options.php`: `woocommerce_attribute_label` filter maps the exact imported label `Specificatio` to `Specification`. This is display only: attribute keys, variation matching, stored order item meta and unit pricing (which already accepts both spellings) are unchanged. It also fixes the cart, emails and order screens, which use the same label function.
- `peptide-core/includes/class-storefront-translator.php`: when a corrected `Specification: X` string has no Spanish key, it reuses the reviewed `Specificatio: X` translation exactly. 12 such strings would otherwise have fallen back to English. Existing translations are not regenerated, because the catalogue mixes "Especificación"/"Especificaciones".

**1 — concurrent add-to-cart.** Cause, from WooCommerce 11.2.1 source (release zip sha256 `3066f85e…aaf`, matching the local-setup README): Store API cart routes load the whole session lazily inside the route callback, and `WC_Session_Handler::save_data` writes it back on `shutdown` (priority 20) with `INSERT … ON DUPLICATE KEY UPDATE`. Two concurrent writes for one session both start from the same cart, and the later save wins.
- New `peptide-core/includes/class-cart-write-lock.php` (registered in `class-plugin.php`): on `rest_pre_dispatch` for non-GET `/wc/store[/vN]/(cart|batch|checkout)…`, take `GET_LOCK(name, 10)` before Woo reads the session; release on `shutdown` priority 1000, after the session save. Identity: Cart-Token header, then logged-in user, then the Woo guest session cookie; a brand-new session (nothing to overwrite) is not locked. The lock name is scoped by DB name and table prefix, because MySQL advisory locks are server-wide (≤64 chars). Nested batch sub-requests reuse the held lock. A timeout returns `pc_cart_busy` 503 with `retry_after`. A NULL/unsupported result (the SQLite dev site) keeps native behaviour.
- The live-cart drawer already refuses overlapping writes within a tab; this covers native forms, other tabs and scripted clients.
- No public field, route or response shape changed. The new error code only appears under lock contention beyond 10 s.

**6 — payment received for a cancelled order.** `peptide-panapay/includes/class-session-service.php`: for a verified success that is not eligible and not already persisted as paid, on the same frozen gateway, a cancelled order now moves to **on-hold** with an explicit note (refund, or confirm and fulfil). It is **not** marked paid, and completion and fulfilment are not triggered. Duplicate notifications stay idempotent (the claim is no longer fresh). WooCommerce side effects of `cancelled → on-hold`, verified in its source: stock is reduced again (`wc_maybe_reduce_stock_levels`), and the admin "New order" and customer "Order on-hold" emails fire. Refunded/processing/completed orders are unchanged (note only).
- This intentionally reverses the earlier contract assertion `late_success_does_not_reopen_cancelled_order` in `tests/native-contract.php`. It is now `late_success_on_cancelled_order_is_held_not_paid` plus a duplicate-notification check.

## Checks actually run (this machine)

PHP 8.3.32 CLI (static-php-cli build) and Node 26.13.0 (checksum-verified), downloaded to the session tools folder, not the repo.
- The two previously failing tests: `visitor-products-config.php` 50/50 (5 modes), `visitor-intents.cjs` 9/9. Both reproduced as failing on 1a7bb95 first.
- New: `themes/peptide-site/tests/checkout-summary-pack.cjs` 2/2; `themes/peptide-site/tests/attribute-display-label.php` 15/15; `plugins/peptide-core/tests/cart-write-lock-pure.php` 33/33 (route matching, identity order, scoping, interleaving model, batch re-entry, timeout 503, fail-open, idempotent release).
- Whole theme suite: `node --test tests/*.cjs` 386/386; every theme PHP test exits 0.
- Plugin/root suites run standalone on both a clean 1a7bb95 worktree and this tree: 176 vs 177 pass (the extra one is the new lock test), and the same 54 fail on both. The 54 need a WordPress/WP-CLI runtime or a disposable QA database, or were already failing; none changed status.
- `php -l` on every changed PHP file.

## Not verified

- No WordPress, WooCommerce or MySQL ran here. Still needed on the MySQL sandbox:
  - (a) parallel `add-item` for one session, e.g. 10× concurrent, to confirm the final quantity equals the sum;
  - (b) a phone-width checkout screenshot showing the badge, the line and "Specification";
  - (c) `wp eval-file …/peptide-panapay/tests/native-contract.php` in a disposable `panapay_qa_*` DB.
- The Spanish checkout line is generated client-side; Spanish checkout strings are otherwise untranslated, as before.

## For the owner — item 3 (production, not run by Claude)

```
wp post get 11738 --fields=ID,post_title,post_status,post_type
wp post update 11738 --post_status=draft
wp post list --post_type=product --post_status=publish --fields=ID,post_title --s=测试
wp post list --post_type=product --post_status=publish --fields=ID,post_title --s=test
```

Draft removes it from the catalogue and makes it unpurchasable; delete only after checking it has no orders.

## Follow-up — item 5 (owner decision 2026-11-06: "Default off")

Supersedes the 2026-11-03 default-on change (`ed7d260`, `backend-payment-default-2026-11-03.md`) and the CONTRACT PAY-HQ-03 "新32hex身份默认all_sites" default (CONTRACT annotated).
- `peptide-panapay/includes/class-hub-accounts.php`: `new_account_defaults()` is now enabled=no, test_mode=yes, all_sites=no, sites=[]. Stored/legacy fallback `defaults()` is unchanged, and existing accounts keep their saved values. No migration was run.
- `class-hub-page.php`: the add-form help text now says new methods start off, in test mode, with no stores, and that going live means checking parameters, selecting stores and unticking "支付方测试账号". The form posts its pre-filled values, so an untouched add form saves a closed method.
- Going live keeps the existing server checks: complete credentials, at least one store, no test mode in production, HTTPS.
- Test `peptide-panapay/tests/hub-new-account-defaults-pure.php` 11/11 runs the real `Hub_Accounts::save/policy` and `Configuration` with an in-memory options row and an offline host cache. It covers the defaults, omitted switches, the untouched form, store-less enable rejected, production test-mode rejected, and an explicit one-store live enable. It fails on the previous code ("new account default is off").
- Full plugin suite rerun: 178 pass; the same 54 environment-dependent failures as baseline.
- Not verified in a real HQ admin page.

## Follow-up — item 2 (owner approved "Build it")

`peptide-core/includes/class-page-cache.php` (`Page_Cache`, registered in `class-plugin.php`). It is **off** until `wp-config.php` defines:

```php
define( 'PEPTIDE_CORE_PAGE_CACHE', true );
define( 'PEPTIDE_CORE_PAGE_CACHE_TTL', 300 ); // optional, 30–3600 s
```

**What it caches.** Only an anonymous GET/HEAD of exactly the home path `/`, with **no query string**, from a browser that sent **no cookies** except `pc_storefront_language`. That means first-time visitors and load tests. Any other cookie bypasses the cache and the page renders exactly as today: Woo session or cart, login, `pc_vid` (returning visitor; customer-segment rules can match visitor IDs), recent products/history, pixel opt-out, click IDs, analytics. Any query string also bypasses, which covers utm/gclid/fbclid (`Pixels::remember_click_ids` must still run) and `pc_lang`.

**How it works.**
- It runs at `template_redirect` priority 100, after every existing hook (max 99). Bot blocking (`init`), redirects, language/GeoIP resolution, the visitor-ID cookie (priority 1) and the Spanish translation buffer (priority 90) all still run.
- A hit skips only theme rendering. It replays the stored headers (still `private, no-store`, so browsers and CDNs do not cache) plus `X-PC-Page-Cache: HIT` and `Age`.
- A miss sends `X-PC-Page-Cache: MISS` and captures the page. Front-page bypasses send `BYPASS`.

**Cache key.** An HMAC (keyed by `AUTH_SALT`) over:
- host and scheme;
- resolved storefront language, locale and GeoIP country;
- Woo's already-located guest billing/shipping country (no extra geolocation call);
- `wp_is_mobile()` device class (the theme renders per device);
- tracking declined (GPC);
- a content generation counter.

**Never stored when:**
- the status is not 200, or the response is not `text/html`, or the HTML is incomplete (< 512 B, > 4 MiB, or no `</html>`);
- `DONOTCACHEPAGE` is defined (already set by the personalised commerce/shipping blocks);
- any `Set-Cookie` other than `pc_vid` was sent.

**Invalidation.** Expiry after the TTL. A generation bump on post/product/variation/stock/term/menu/customizer/theme/plugin changes, and on writes to storefront options (`pc_*`, `peptide*`, `vc_*`, `theme_mods_*`, `woocommerce_*`, widgets, front-page settings). Transient, cron, lock, queue and session writes do not bump it.

**Storage.** `wp-content/cache/peptide-core-pages/<hmac>.php`, written atomically (temp file + rename), each file starting with a PHP `exit` guard so a direct web request cannot return it. Corrupt or expired files are ignored. Guest Store API nonces on a cookie-less page belong to user 0, so they are shared and stay valid well beyond the TTL.

**Important limits.**
- Ad pixels: superseded, see "Follow-up — pixels + cache" below. Pixel pages are now cached with per-view events.
- Returning visitors and ad landings with click IDs are not accelerated. A hit still loads WordPress and plugins, so expect roughly "TTFB minus template time", not static-file speed.

**Tests.** `peptide-core/tests/page-cache-pure.php` 46/46 with the real class. It covers:
- the eligibility and bypass matrix;
- the stored-header rules;
- key variance (device, language/country, GPC, host, generation);
- the option-flush filter and hook priorities;
- a real miss → capture → file → child-process hit;
- expired and corrupt copies are not served;
- a personalised Set-Cookie response is not stored;
- a returning visitor gets no capture.

Full plugin rerun: 179 pass, same 54 environment-dependent failures; theme 386/386.

**Not verified.** No real WordPress or Nginx ran here. Before enabling in production, on the sandbox:
- (a) enable the constant;
- (b) `curl -sI https://<sandbox>/` twice, expecting MISS then HIT, and with a `Cookie: pc_vid=…` header expecting BYPASS;
- (c) compare the HTML of HIT and a fresh render;
- (d) check Spanish (PR) and mobile variants;
- (e) repeat the 10-concurrency p50 measurement;
- (f) confirm add-to-cart from a cached page works (Store API nonce).

## Follow-up — pixels + cache, and local WordPress verification (2026-11-06)

The owner will run the Facebook pixel and the Conversions API together. The first cache version stored nothing when pixels were on, so it was reworked.

**Pixel-compatible cache.**
- `Pixels::enqueue()` is split into `page_config()` (permission check, page events with fresh IDs, the Conversions API/TikTok server send, browser config) and `localized_statement()`, which reproduces `WP_Scripts::localize()` exactly. A normal render behaves as before.
- `Page_Cache` stores a pixel page with its single `var pcPixels = …;` line (inside `#peptide-core-pixels-js-extra`) replaced by `/*pc-page-cache:pixels-config*/`. Each hit calls `Pixels::page_config()` again and fills the slot. So every cached view has its own event IDs and sends its own server page events, exactly once.
- The page is not stored when that block is missing, appears twice, or the placeholder already exists.
- `Pixels::allowed()` is part of the key. Entry format v2, so old copies are ignored.

**Checkout line timing bug, found only on the real checkout.**
- WooCommerce first renders summary rows with empty product names. The line was skipped then, and whether it appeared later depended on timing; it was missing on 2 of 3 early loads.
- `annotateItems()` now reports whether the rows have settled, and `reconcile()` retries every 250 ms, at most 40 times, until they have.

**Local site.** Built per `vialcove-local-setup/README.md`, outside the repo:
- WordPress 7.1.4 (GitHub tag), SQLite Database Integration 3.1.3, WooCommerce 11.2.1 (sha256 matches the README), WP-CLI 2.12.0 (sha512 verified), PHP 8.3 static CLI;
- the project plugins and theme symlinked from this checkout;
- `wp peptide demo commerce` / `demo seed`, `seed-local.php`, plus a synthetic product whose attribute is literally `Specificatio`;
- a must-use plugin blocks all non-loopback HTTP, so no request reached Meta;
- fake pixel ID and fake token only.

**Verified there:**
- `peptide-core/tests/smoke-pixels.php` via `wp eval-file`: 42/42 with the refactor, settings restored afterwards.
- `localized_statement()` is byte-identical to `WP_Scripts::localize()` for real configs, including quotes, `&`, `<>`, accents and slashes.
- HTTP with `PEPTIDE_CORE_PAGE_CACHE`:
  - MISS → HIT → HIT;
  - `pc_vid` cookie → BYPASS;
  - `?utm_source` → BYPASS;
  - iPhone UA → separate MISS → HIT.
- Every request, cached or not, queued exactly one Conversions API job.
- HIT HTML is byte-identical to the MISS render apart from page-event IDs and timestamps; each view has distinct IDs and no placeholder leaks.
- A product price change and a tagline change → MISS; transient/cron writes → still HIT.
- Real Store API: the `Specificatio` product reports "Specification" (raw key `attribute_specificatio` unchanged) and `vialsPerSalesUnit` 10.
- Real block checkout:
  - at 390 px: quantity badges visible, "Specification: 10mg*10vials" and "Qty: 2 boxes · 10 vials per box";
  - 6 of 6 fresh loads labelled (about 1.8 s after load);
  - no visible element wider than the viewport at 390 or 320 px.

  Screenshot in the session folder: `repro/real-checkout-390.jpg`.
- Tests: `page-cache-pure.php` 59/59 (adds placeholder/ambiguity rules and a two-visitor end-to-end with one send each); `checkout-summary-pack.cjs` 3/3; theme 387/387; plugin standalone 179 pass with the same 54 environment-dependent failures.

**Not verified here.**
- MySQL (`GET_LOCK` concurrency), Nginx, real GeoIP/Spanish, a real Meta Events Manager deduplication check, and any timing that represents the sandbox. Local SQLite times (MISS ≈0.30 s, HIT ≈0.25 s on a 5-product catalogue) are not a performance result.
- Background Conversions API jobs need a real per-minute system cron in production, as already required for payment write-backs.

## Sandbox deployment — 20261106T182942Z (owner approved "Deploy to sandbox")

**Scope.** Host 54.144.250.46. It also runs the production HQ `vialcoveresearch.com` and stores s0001–s0003, sharing Nginx and MySQL with the sandbox. Only `/srv/vialcove-sandbox/{shop,admin,hq}` and the `vialcove-sandbox-php` service were touched. Nginx, MySQL and production PHP/files were not touched. Main and production are unchanged.

**Pre-check (read-only).** All 23 target files on the three sandbox sites were byte-identical to `1a7bb95`, and the two new files were absent, so no unknown edits were overwritten.

**What ran.**
- Packaged the 12 runtime files from commit `083b962` with `git archive`, uploaded them, and verified sha256 12/12 and server `php -l`.
- Backed up every replaced file to `/home/ubuntu/vialcove-deploy-backups/20261106T182942Z/` (with `installed.txt` listing replace/create per site).
- Installed atomically as `vcsandbox`:
  - shop/admin: 10 replaced + 2 created each;
  - hq: 3 panapay files replaced.
- Verified the installed bytes, then sent a graceful `USR2` reload to the sandbox PHP-FPM master only. The unit has no ExecReload.

**Incident, fixed.** The macOS `tar` added AppleDouble `._*` companion entries. 36 such files (no PHP code; binary metadata) were briefly installed next to the real files. They were found from `installed.txt` within about a minute and only those created `._*` files were deleted; 0 `._*` files remain under sandbox plugins/themes. No bootstrap globs these folders (autoload by class name only), so they were never loaded.

Fix for next time: package with `COPYFILE_DISABLE=1` or `--no-mac-metadata`, and install from `manifest.sha256` only.

**Results on the sandbox.**
- Status matches the baseline: shop 200, admin 302 (login), HQ 200. The sandbox FPM log since deployment shows only reload notices, no PHP errors.
- **Cart lock on MySQL**: one guest cart, 1 box, then 10 simultaneous add-item calls gave 10×201 (0.24–0.93 s, serialized) and a **final quantity of 11 (expected 11)**. The test cart was emptied (0 items).
- **Specificatio**: the raw catalogue has 30 variable products named `Specificatio` (plus 31 `Specifications`, 20 `Specification`); displayed labels are now 50 "Specification". A real cart line carries `vialsPerSalesUnit` 10.
- **HQ defaults** (read-only WP-CLI as `vcsandbox`): new methods are `enabled=no, test_mode=yes, all_sites=no, sites=0`; the 1 existing account is unchanged.

**Not done or not verified yet.**
- Visual phone checkout on the sandbox: needs someone signed in to the `/_sandbox/login` gate.
- The page cache is **not enabled** on the sandbox (needs a `wp-config.php` constant; awaiting approval), so there are no cached timings yet.
- `native-contract.php` still needs a disposable `panapay_qa_*` instance.
- Real Meta deduplication check.

**Rollback.** Copy `/home/ubuntu/vialcove-deploy-backups/20261106T182942Z/<site>/...` back over the same paths (owner `vcsandbox`), delete the 2 created files on shop/admin (`class-cart-write-lock.php`, `class-page-cache.php`; also revert `class-plugin.php` from the backup), then `sudo systemctl kill --kill-whom=main -s USR2 vialcove-sandbox-php`.
