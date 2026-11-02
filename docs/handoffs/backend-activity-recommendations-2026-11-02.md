# Activity listener recommendations — 2026-11-02 — Codex

Backend delivery for section C of `VialCove_插件对接最新总需求_20261102.md`. Reuses the existing activity application, Woo cart, promotion assignment/evaluation and native `WC_Cart_Totals`. No replacement discount engine, product price changes, recommendation order endpoint or automatic add-to-cart. Frontend layout, popup/player lifecycle and the approved Demo animation remain with the frontend window.

## Configuration and API

Existing application: `wp-admin/admin.php?page=pc-apps&app=activity-listener`. `GET /wp-json/peptide/v1/activity-listener` exposes the applied configuration. New `recommendation` settings are documented in [the settings handoff](backend-storefront-plugin-settings-2026-11-02.md); default disabled. No existing live app/promotion configuration was activated or rewritten by this delivery.

`GET /wp-json/wc/store/v1/cart` now includes opaque `extensions.peptide_core.activity_listener.cartSnapshotKey`, alongside the existing `stateKey`, `revision`, `promotionRevision` and `next`. The snapshot includes complete row identities/variations/quantities/current prices and line amounts, coupon codes, active rules, authorization/site context, currency/precision and stock/purchase constraints. It is an HMAC, not a customer identifier or stock/price lock. Same-price SKU replacement changes it even when the aggregate `stateKey` stays unchanged.

`POST /wp-json/peptide/v1/activity-listener/recommendations`, JSON <=16 KiB:

```json
{
  "cartStateKey": "<current activity_listener.stateKey:64 hex>",
  "cartSnapshotKey": "<current activity_listener.cartSnapshotKey:64 hex>",
  "promotionRevision": "<current activity_listener.promotionRevision:64 hex>",
  "configRevision": 1,
  "candidates": [
    {"productId":123,"variationId":456,"viewCount":4,"lastViewedAt":"2026-11-02T13:30:00Z"}
  ]
}
```

All five top-level fields are required. Up to twenty distinct parent products; bounded integer IDs/view counts (1–1,000,000), canonical UTC time or null. No prices, quantities, stock, thresholds, site/customer/cart/session identifiers or unknown fields accepted. Counts are ranking hints, never pricing/permission evidence. Invalid/hidden/unavailable candidates are skipped. Missing/incomplete variation selection is not silently replaced with the cheapest variation; this release hides that candidate until a specific complete variation is supplied.

Identity: existing native signed Woo session Cookie **or** valid native `Cart-Token`, with the header taking precedence. Native `CartTokenUtils` verifies signature/expiry/issuer; `SessionHandler::get_session` reads that exact cart. A bad/empty token never falls back to a cookie. Cookie HMAC, expiry and current-account rules are checked. No `wc_load_cart`, session initialization, session save or temporary `WC()->cart` replacement. Browser Origin/Referer must match the site, including scheme/port; logged-in requests require `X-WP-Nonce`. Force-login remains enforced. External Demo proxies must use their explicit same-site backend adapter; do not relax origin checks globally.

Responses, including REST validation/auth errors, use `private, no-store`, `Vary: Cookie, Origin, Cart-Token`, and nosniff. A native Woo security rate-limit key (opaque HMAC, two-second delay) is the only endpoint write; it is separate expiring abuse-control state, not cart/session/customer/visitor history. There are no quote/business/analytics writes.

## Result contract

The endpoint returns `{recommendation, recommendations}`. `recommendation` is the first result; `recommendations` contains at most configured `maxVisible` (default one). Hidden results have `visible:false`, a reason and an empty plural list. A visible DTO contains:

- `version:1`, `visible:true`, `reason:"most_viewed"`, all four freshness identifiers, `expiresAt` (UTC, 45 seconds), currency and precision.
- Real `productId`, `variationId`, `purchasableId`, parent name, exact specification, image `{url,alt}`, additional `quantity`, `unitLabel` box/boxes, and `{minor,text}` `unitPrice`/`linePrice` for that SKU.
- `reachesNextTier:true`, this request's `targetNodeKey`, `projectedDiscount` (total listened activity discount after addition), `additionalSavings` (its increase).
- `afterAdd.eligibleSubtotal`, target `remaining:"0"` / `remainingText`, `targetReached:true`, `promotionDiscount`, `allDiscountsBefore/After`, `incrementalSavings`, `cartTotalBefore/After`, `additionalPayable`, `totalsScope`, `calculationStatus:"quoted"`.

Amounts use current sale prices, not regular-price savings. Coupons and final negative fees are not double-counted. For physical goods shipping is deliberately not predicted: all three payable/total fields are **null**, `totalsScope.complete:false`, `shippingIncluded:false`, `taxesIncluded:false`. For virtual-only carts with tax disabled, the native total difference is available and complete for that context. This is a short-lived estimate; it never locks inventory or prices. Money differences can be signed decimal integer strings.

Native fixture example (synthetic, not business data): current $440, listened fee −$25, total $415; add one $100 box, eligible subtotal $540, listened fee −$55, total $485. `projectedDiscount.minor="5500"`, `additionalSavings.minor="3000"`, `incrementalSavings.minor="3000"`, `additionalPayable.minor="7000"`. The next tier's $55 is not advertised as $55 additional savings.

Rank only qualifying quotes by view count descending, last-view time descending, then lower additional purchase cost. Quantity is the smallest additional whole-box count satisfying native min/max/step/sold-individually and combined sibling/parent-managed inventory, including existing cart quantities and reserved stock. At most 64 native trials per request; expensive/unresolved cases hide instead of returning a guessed or partially ranked quote.

## Supported scope and failures

First release supports native CPT simple products and complete fixed variations, current **spend_amount** promotions (including repeat cycles), normal sale prices, native activity priority/assignment, multiple such activities and native negative-fee caps. All active relevant rule context must use unrestricted customer eligibility. Native Woo product hydration is reused; a scoped variation data-store subclass suppresses only read-time title repair. It installs no global data-store filters and creates no child-price/children transients.

Applied coupons, active gift/bundle offers, quantity/other activity types, customer-segment rules, taxes/tax-inclusive prices, custom product stores/classes, unknown pricing/purchase-limit/read callbacks and other ambiguous integrations fail closed. Coupon cases return `coupon_conflict`; unsupported cases return `unsupported_calculation`, not frontend estimates. Existing discount calculation/checkout still works normally. This conservative support boundary can be expanded with separate native verification later.

Normal hidden reasons: `disabled`, `far_from_tier`, `no_history`, `no_eligible_candidate`, `highest_tier`, `promotion_unavailable`, `coupon_conflict`, `unsupported_calculation`. The gap ratio and configured minor cap must both pass. Repeating activities retain a next cycle.

HTTP errors: 400 invalid/unknown/oversized input; 401 force-login/invalid token; 403 nonce/origin/no signed cart; 409 stale cart/config/rules or unsupported session data; 429 security rate limit (`data.retryAfter:3`); 503 unavailable cart/security limiter. A stale calculation response includes the latest safe activity DTO when available. Reader errors are prefixed `pc_activity_cart_`; calculation/input errors `pc_recommendation_`. Never expose a session key/token in logs or UI.

Frontend must discard late/expired/mismatched responses, including mismatched currency/precision, target, config, snapshot and activity. Display extra savings only for a fresh quoted result, limited by positive whole-cart `incrementalSavings`. Actual addition remains Woo `cart/add-item` with `purchasableId` and **additional** quantity. Wait for the real successful cart response to update amounts and celebrate; pending requests need a double-click lock. A failed/unsupported quote must not prevent shopping or checkout.

## Verification and files

Native detached cart: 41/41. Cold/stale-title native catalogue reading: 22/22. Full native recommendation engine: 56/56, including $25→$55 delta, activity priority, exact variation/stock/shared stock, minimum boxes, old state/config/rule/snapshot, same-price SKU replacement, unlimited/highest tiers, disabled/gap/no-history, coupons/offers and hostile input. Native token/cookie reader: 37/37 before the catalogue-helper integration; final transport checks cover that integration. These tests use connection-local TEMP fixtures; permanent catalog/customer/order/session/tracking/options fingerprints and QA guard remain unchanged. No global Woo object replacement or quote-phase SQL write attempts.

Dedicated loopback HTTP transport: 20/20. A private native Cart-Token/cart using the QA's existing synthetic product verified snapshot delivery, disabled 200, origin 403, invalid-input 400, invalid/empty-token 401, controlled-rate-window 429, private/no-store/all Vary headers, and unchanged native/raw-DB session across quote requests. Published recommendation configuration remained disabled throughout. This verifies transport and backend calculation separately; it is not a browser demonstration of a visible recommendation. HTTP 429 used a private ten-second native limiter test window because this single-process local server takes about three seconds per request; no claim of two-second concurrent load performance. Only the test's own cart session from native add-item and security limiter row were created; products/prices/config/orders/tracking and unrelated sessions/limiter rows were unchanged. The exact quote route was temporarily permitted by the dedicated QA guard; its original SHA was restored and actual HTTP was used to confirm the guard again rejects it. No production or visitor-only QA guard was weakened. No tokens/cookies are committed.

Files: `includes/activity/class-{cart-reader,cart-snapshot,catalog-read,quote-cart,recommendations,progress}.php`, `includes/api/class-activity-listener-controller.php`. Companion settings/history changes were committed separately as `728215c`. PHP lint/JS parse/diff checks and the existing activity client regression (52/52) pass. Task evidence is under `D:/Codex/Tasks/2026-11-02/storefront-apps-install/outputs/` (sanitized result/amount summaries; private fixtures/session tokens are not in Git).

This backend delivery does not claim the recommendation card, broadcast player, recently-viewed popup recording, add-feedback animation or current frontend Demo has completed real browser integration.
