# Signed recent-history recommendation candidates — 2026-11-02 — Codex

Integration audit found that `GET /peptide/v1/recently-viewed/items` exposed only the two display items and excluded products already in the cart. That list cannot provide the twenty signed browser-frequency hints needed for most-viewed recommendations or topping up an existing SKU.

## Additive response field

The existing personalized no-store response now includes `candidates`, independent of `items`:

```json
{
  "items": ["existing full display DTOs, maxVisible <= 2"],
  "candidates": [
    {"productId":123,"variationId":456,"viewCount":4,"lastViewedAt":"2026-11-02T13:30:00Z"}
  ]
}
```

- At most configured `maxStored` (maximum twenty) entries from the existing signed, unexpired browser cookie. No new browser identifier, localStorage, customer profile or analytics storage.
- Candidates sort by signed view count descending, real last-view time descending, then parent/variation ID for deterministic ties. Legacy v1 unknown per-product timestamps remain null and count one.
- Products already in the current cart are **included**, allowing the quote service to check an additional quantity of the exact recorded SKU. Ordinary display `items` retain their existing recent-first order, two-item limit and configured cart-parent exclusion.
- Current public/purchasable/in-stock parent and recorded complete variation are checked. Hidden/private/password-protected/unavailable products are omitted. Invalid or stale recorded variations are omitted; they are never silently replaced with another specification or a lowest-price variation. An unselected variable parent retains `variationId:0` as a choose-options hint.
- Candidate validation reuses the quote path's `Activity\Catalog_Read` to avoid Woo's read-time stale-title repair and parent child-price transient writes. Unknown read/pricing integrations conservatively produce no candidate hints; the ordinary recent display behavior remains independent.
- Each candidate has exactly the four input fields accepted by `POST /peptide/v1/activity-listener/recommendations`. It contains no product price, image/name, customer/session identity or eligibility promise. The quote endpoint must still validate current cart, activity ownership, stock, quantity, promotions and freshness.
- Disabled applications return empty `items` and `candidates`; force-login filtering and existing cookie/nonce/token permissions remain intact. Clear responses explicitly contain `candidates:[]`.

Frontend should pass `historyResponse.candidates` to the recommendation endpoint, together with the current cart snapshot/config/rule identifiers. Do not derive the recommendation candidates from the two displayed recent items, exclude existing cart products, or substitute a Demo catalogue/lowest variation.

## Verification

- New native WP/Woo regression: **29/29**, using signed synthetic history and connection-local TEMP product/options tables. Twenty valid hints vs two display items, already-in-cart top product, deterministic count/recency order, exact recorded variation, private/hidden/password/out-of-stock filters, expiration/maxStored, legacy unknown dates, disabled/force-login and clear all pass.
- Cold variation fixture kept an intentionally stale stored title; candidate processing did not repair it or create children/price transients. Read-phase SQL attempts were zero. Global Woo cart/customer/session pointers and data, permanent business/tracking/session/options fingerprint and dedicated QA guard remained unchanged.
- Previous native signed-frequency/cooldown/token regression: **43/43**, cookie budget 1,924 bytes; PHP lint and diff check pass.
- Initial test-only guard omitted TEMP `REPLACE INTO` from its fixture allowlist, and the first CLI fixture switched an already-authenticated process to guest before measuring session data. These harness issues were corrected; the final run uses the natural unauthenticated CLI context and performs no global object swapping. The initial failure receipt is retained separately.

Modified production source: `includes/recent/class-history.php` only. No app activation/configuration, frontend theme/Demo, products, prices, stock or orders changed. Native evidence: `D:/Codex/Tasks/2026-11-02/storefront-integration-check/outputs/recent-candidates-native.json`; script under the same task's `work`. This does not claim frontend/browser integration acceptance.
