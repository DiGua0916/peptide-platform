# Cart confirmation and coupon presentation · 2026-11-04

Codex. User supplied the two outstanding frontend audit findings and asked this window to handle them. This supersedes the pending status of findings 2 and 3 in backend-frontend-integration-audit-2026-11-04.md for the local theme only. No backend, offer settings, real cart, customer, order or payment mutations.

## Applied theme changes

- live-cart-experience.js retains HTTP status inside a private transport envelope. The public client.request API still returns the original DTO for GETs and detached recommendation POST quotes.
- add-item only confirms on the same native HTTP201, a valid cart, and the precise requested SKU's aggregate quantity increase equal to the submitted quantity. Multiple same-SKU native lines are summed, unsafe/negative quantities refused. Purchase ID, quantity and ticket presence are captured before awaiting the request; stale generations and disposed clients cannot confirm.
- Ticketed recommendation adds also require same-response extensions.peptide_core.activity_recommendation.requested===true and added===true. No later GET, item count, existing line, HTTP200 or missing receipt can stand in for this confirmation. Native CartItemSchema.id is the actual product get_id, including a variation ID.
- Added:true with coupon failure remains one confirmed add, with its original partial receipt. live-cart-recommendation.js only claims an automatic coupon applied when couponRequested===true AND couponApplied===true; otherwise it reports the already-added item and unconfirmed offer, with an explicit review path. Never send a second add/coupon POST to repair an uncertain outcome.
- Existing failure handling reconciles once with a GET, retains the write error and throws to the feedback adapter, so no Added, checkmark, bag pulse or fireworks are announced for an unconfirmed response. Ordinary update/remove/apply/remove-coupon native HTTP200 semantics remain unchanged.

## Coupon identity and copy

The existing native coupon DTO has code, discount_type, totals and extensions; the backend supplies no stable origin/name mapping for already applied automatic coupons across GETs. The transient add receipt has no code/name and is deliberately cleared before later requests. Do not infer identity from code prefix, current activity, browser storage or the quote.

The agreed frontend solution uses neutral `Order discount` for ALL coupon chips, with the exact per-coupon native formatted saving where trustworthy, e.g. `Order discount · $5.00`. This avoids claiming that manual coupons are automatic or falsely assigning another promotion's name. Bad/missing/negative/unsafe money metadata falls back to the neutral label, never a guessed amount. Native currency precision controls formatting.

Raw native codes stay in memory and the original remove-coupon request only, not chip text, aria-label, title or data attributes. Refresh and fresh page objects use the same neutral presentation. Rendering signatures include label/amount so the same code's changed discount updates without rebuilding unchanged chips. Coupon entry/removal logic is preserved.

## Verification

- Combined six-file node --test run: 52 top-level tests passed. Includes 28 new full-client/actual-feedback tests, 7 actual coupon helper/renderer tests, strengthened strict recommendation receipt tests and existing recommendation lifecycle checks. The recent and drawer files additionally print 16 and 31 internal checks; do not add these again to the top-level count.
- New production-feedback VM cases verify no Added/Added aria/success firework for HTTP200 and missing ticket receipt, and preserve real201/partialcoupon success. Other cases cover wrong SKU/quantity, duplicate SKU rows, caller changes during await,409, timeout, stale/destroyed response, duplicate taps, one POST/at most one reconcile GET, nonadd200 and original public DTO API.
- Coupon tests run the actual renderer source block: internal/XSS code absent from visible and accessible text, original code used for removal, changed native savings re-render, unchanged chips stable, POST→GET/fresh-object presentation stable, native precision and invalid metadata fallbacks.
- Initial failures were an incomplete test DOM reset fixture (fixed to model the actual child text node), and an old recommendation fixture missing the real server requested field (updated). No production feedback script was relaxed to make them pass.
- JS syntax and git diff --check pass. Live browser/script validation is recorded below after root QA; no actual add, coupon application/removal, address, checkout or payment is performed.

Evidence: D:/Codex/Tasks/2026-11-04/cart-confirmation-coupon-ui/outputs. This is LocalWP source/runtime and normal Git integration. No public-server theme deployment; existing public receipts for other work do not publish these changes.
## Actual local runtime and browser limit

Both shipped asset URLs read directly from LocalWP with normal local networking and no proxy return HTTP200 with exact source SHA256 (see local-assets.json). The proxy path returns empty HTTP503; the mobile iframe also becomes blank. Local's panel reports the site running, and the direct homepage returns200. The issue is in the local proxy access path, not proof of a theme PHP or JS failure. No site stop/start or proxy, VPN, certificate, network settings or server changes were executed. The first native stop attempt failed before input because geometry was unavailable; fresh observation still showed the original running site, and direct200 established that a restart was unnecessary.

After the user changed their proxy mode, the normal local networking path and the direct path both return HTTP200 for mobile-preview.html (5226 bytes). Both modified assets now also return HTTP200 through the normal path with exact source SHA256; see normal-assets-restored.json. The earlier HTTP503 condition is resolved for these HTTP checks.

The existing in-app tab still has its generated network-error data URL. The browser tool rejects that document before a read or a fresh original-URL navigation can complete. No alternate browser-control path was used to evade that block, and no site/service/network setting was changed. Therefore source/assets are installed locally, but final live mobile add/coupon acceptance is not claimed. Full 201/coupon/failure behavior is proven by controlled real-source regression, not real customer operations. The earlier empty mobile-current-cart.json observation is a blank frame, not evidence of an actually empty cart. No real cart was changed to manufacture a coupon row.

An independent final source review found no blocking issue. It specifically reconfirmed exact-SKU/201 gating, strict ticket and coupon receipts, no retry POST, and UI-only coupon-code hiding (the native REST DTO still contains its original code as required for WooCommerce).
