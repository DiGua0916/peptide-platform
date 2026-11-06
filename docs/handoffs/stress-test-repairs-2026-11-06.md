# Stress-test repairs — 2026-11-06

Scope: branch `codex/checkout-acceptance-20261105` only, on top of `1a7bb95`. Not merged to main, not deployed, no sandbox or production server touched. Work was done by Claude on a fresh clone (macOS, no WordPress/MySQL runtime); the D-drive project chat room was not available, so this file is the progress record.

## Status by stress-test finding

| # | Finding | Status |
| --- | --- | --- |
| 1 | Concurrent add-to-cart loses quantity | Fixed in code; MySQL concurrency not yet verified |
| 2 | Homepage has no full-page cache (sandbox p50 ≈3.9 s at 10 concurrency; production ≈1.8–2.0 s) | Not implemented; design below needs a decision |
| 3 | Purchasable "测试" product id 11738 on production | No code change; commands below for the owner |
| 4 | Mobile checkout summary: no quantity, no "10 vials per box", label "Specificatio" | Fixed |
| 5 | HQ new payment account defaults: enabled, live, all stores | Not changed: conflicts with the 2026-11-03 user decision (`ed7d260`); needs a decision |
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

## Decisions needed

- **5:** `ed7d260` / `backend-payment-default-2026-11-03.md` records that the user asked for new methods to default **on**. The stress test lists that as a risk. Not changed until the user chooses. Options:
  - keep on;
  - default off + test mode + no stores (fail-closed), matching PAY-HQ-01 "新注册默认不开放收款";
  - a middle option, such as on but no stores selected.
- **2:** All storefront documents are currently `private, no-store, Vary: Cookie` (`Storefront_Translation::private_header_values`). Language is per visitor (preference cookie, or local GeoIP on `REMOTE_ADDR`). A full-page cache must therefore:
  - only serve anonymous GET `/` without query string;
  - bypass `wordpress_logged_in_*`, `wp_woocommerce_session_*` and `woocommerce_items_in_cart` cookies;
  - key on the *resolved* language (cookie value, else GeoIP country → language), so Nginx alone cannot key it without the same GeoIP data;
  - keep TTL well under the 12–24 h nonce lifetime (guest Store API nonces without a session cookie are shared, so a short TTL is safe);
  - exclude cart, checkout, account, REST and report routes (CONTRACT "不得被公共整页缓存").

  The practical option is an opt-in application-level cache in peptide-core: resolve language early, then serve stored HTML for that key with `Cache-Control: private` to browsers. It skips the theme render (~0.6–0.8 s measured). It needs explicit approval, because it reverses the deliberate no-store policy, and it must be measured on the sandbox.

## For the owner — item 3 (production, not run by Claude)

```
wp post get 11738 --fields=ID,post_title,post_status,post_type
wp post update 11738 --post_status=draft
wp post list --post_type=product --post_status=publish --fields=ID,post_title --s=测试
wp post list --post_type=product --post_status=publish --fields=ID,post_title --s=test
```

Draft removes it from the catalogue and makes it unpurchasable; delete only after checking it has no orders.
