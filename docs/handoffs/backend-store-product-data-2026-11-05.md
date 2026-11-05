# Lightweight Store API extension reader for server-rendered cards

2026-11-05 · Codex. This is a separate local Core candidate for the site coordinator. No theme/runtime installation, push or production deployment is performed here.

## Problem and interface

The theme's `vc_product_data()` dispatches a complete native Store API product response for each displayed SKU, JSON-serializes it, then keeps only `extensions.peptide_core`. That computes categories, tags, brands, images and quantity limits that this consumer does not use. An `_fields` parameter does not avoid ProductSchema calculation in the installed Woo version.

Core now provides the public PHP helper:

```php
peptide_core_get_store_product_data( $selectedSku ): ?array
```

- Input is an existing `WC_Product` object. Integers, strings, arrays, invalid objects and nonpositive IDs return `null`; the helper does not resolve arbitrary client input.
- A valid result is the **plain peptide_core extension array**, with the existing `is_demo`, `specs`, `batch_count`, `report_state`, `price_pair` and `unit_pricing` fields. It is not a new REST envelope, cart DTO, purchase quote or quantity authorization.
- All data comes from the same `Api\Store_Api::data($selectedSku)` callback. No private-meta implementation, comparison-price calculation, package-count inference, field mapping or second product system is copied into the theme/helper.
- Pass the exact displayed child object for selected-SKU data. A variable parent keeps the existing parent/aggregate semantics, whose price pair may refer to its cheapest eligible child. Never use that aggregate as the price of a different selected variation.

## Public eligibility and refusal

The helper checks current Woo identity/status and raw post identity/status/password. A variation must have its own published `product_variation` post, an active/visible native variation, matching raw/object parent IDs, and the same existing published, visible, password-free variable parent. The parent's raw status/password are also checked. Native variation reads do not hydrate their own `post_password`, so the raw-post check is necessary.

The existing `force_login` setting is respected for anonymous calls. Invalid, hidden, disabled, protected, missing or unavailable data returns `null`. Stock/purchasability is **not** promoted to a top-level display-authorization rule: a legitimate public item can be described without granting an add-to-cart attempt. The existing callback may still read native price/purchasability for its normal display fields.

The helper never initializes a cart/session, reserves stock, runs QuantityLimits, recalculates a cart, changes a product or writes an order. It adds no computed-price or cross-request/user cache. `report_state` remains current-user-dependent; price/currency/tax/locale are the current request's existing service context.

Existing Woo/WordPress reads can fill their own native caches; this is not a promise of zero cache maintenance under every plugin/environment. The helper introduces no explicit business writes or new cache layer. Do not use its user-dependent data to enable shared public HTML/DTO caching.

### Fallback correction

`null` can mean an explicit eligibility refusal. **Do not retry a broader REST lookup after the new helper returns null**, and do not replace it with guessed pricing or `canAddOne=true`. Preserve an appropriate public parent/selection link and withhold unavailable SKU extras/quick-add descriptors.

The old internal-REST path is a compatibility fallback only when `function_exists('peptide_core_get_store_product_data')` is false on an older Core installation. This corrects an earlier broad suggestion to fall back on every null result. An exception or malformed result must not bypass eligibility either.

## Front-end integration ownership

The front-end window owns theme changes. This commit does not change existing Store API routes or their semantics. The theme can use the helper for `vc_product_data()` after preserving its existing public-parent/selected-SKU handling, then retain normal escaping and response-local reuse. It must not cache a user's report state or computed price across requests.

The helper returns the owned extension callback's data, not unrelated third-party transformations added to complete native REST responses. Native same-SKU comparison establishes parity for the installed stack only; it is not a compatibility promise for arbitrary future response filters. There is no new administration setting or automated fallback switch.

For the separate stock optimization, SSR may describe a candidate shortcut without checking every card's held stock. The clicked card must still use the existing `home-product/{parent}?card=true&variation={exact}` quote and original Store API POST; selected identity, price/currency/precision, quantity1 eligibility, held stock, existing cart quantity and confirmed exact delta1 remain mandatory. Quantity1 is one current sales unit/box. Do not move 82 stock requests to page boot.

## Validation and deployment boundary

Root reran **181/181 pure checks**, both PHP lints and whitespace validation. The tests execute the actual helper and owned Store API/Pricing/Unit_Pricing/Catalog/Products services with finite fixtures. They include raw-post visibility/password/parent mismatch, null refusal, variable aggregate versus selected SKU, changing tax/currency/dynamic getters, display of legitimate nonpurchasable items, and no explicit cart/stock/write calls.

Native guest WordPress/Woo reads compare TR20/11307, TR15/11309, parent aggregate11306 and public parent11408 against the original same-SKU Store API extension: all fields match. The installed catalog has no eligible public simple-product sample; that branch is fixture-tested, not claimed as native-tested or created for testing.

Separate fresh-process measurements with identical frozen included dependencies show one SKU **12 → 4 queries**, and the same 24 displayed SKUs **263 → 96 queries**, with full extension-output hashes identical. Timing varies and the frozen helper run was slower (about199ms versus115ms for24); this supports fewer queries, **not a stable elapsed-time or whole-homepage speedup claim**.

The guard starts at the first Core autoload. The measured service phases have no rejected write attempts and full logged operations are SELECT/SHOW; bootstrap attempted three expired Panapay methods-policy transient DELETEs, all refused by the guard. No payment business operation was exercised, and pre-guard HTTP is not fully instrumented. These are guest CLI/internal-REST comparisons, not real iOS, authenticated-customer or arbitrary third-party REST-filter acceptance. Receipts and limitations are recorded under:

`D:/Codex/Tasks/2026-11-05/core-storefront-pricing-performance/outputs/`.

The relevant files are `store-product-data-pure.json`, `store-helper-root-verification.json`, `store-helper-native-evidence.md`, the five `store-helper-*-frozen.json` receipts, `storeapi-pagination-contract.md` and `card-stock-lazy-contract.md`. The frozen whole functions-file SHA256 is `d97101412631b4bdf3ea0e2da53b89556c731fef6172530f08bba6d585472446`; the new function fragment SHA256 is `530132a72b93952119af993c4205d16015d4f3f753025432a820b81a97fdd5ac`. The profiler is outside the website and must not be deployed.

Only `includes/functions.php` is a changed runtime file; the added test and this handoff are development material. There is no schema, option, payment, product or country-source migration. Existing local runtime may continue using legacy REST until the coordinator installs this exact reviewed Core candidate together with the front-end integration. Source availability is not a claim of installed runtime, full-homepage speedup, iOS rendering or completed release.
