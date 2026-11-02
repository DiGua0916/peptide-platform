# Recently viewed: browser frequency contract

2026-11-02 · Codex · Backend handoff for the consolidated product dialog requirements.

This extends the existing Recently viewed application. It installs no frontend renderer and creates no analytics/customer table. The two provisional renderer assets were moved out of the plugin into `D:/Codex/Tasks/2026-11-02/storefront-apps-install/work/frontend-drafts/`; their move receipt is `outputs/frontend-draft-move.json` in that task. The older renderer tests do not prove this PHP frequency contract.

## Management and effective configuration

Existing application entry: `/wp-admin/admin.php?page=pc-apps&app=recently-viewed`.
Configuration remains unchanged: the existing draft/applied snapshots, revision CAS, permission and nonce boundaries still apply. `GET /wp-json/peptide/v1/recently-viewed` returns effective public configuration. The recent list remains in most recently viewed order, limited by `maxVisible` (at most two); it is not a most-viewed ranking endpoint. Up to twenty parent products are retained in the signed browser cookie.

## Routes and browser authority

- `GET /wp-json/peptide/v1/recently-viewed/items`: current browser only; no customer, site, cart or arbitrary product IDs accepted. Same-origin Fetch Metadata, Origin or Referer must pass the existing controller checks. `credentials: 'same-origin'` and `cache: 'no-store'` are required in the frontend.
- `POST /wp-json/peptide/v1/recently-viewed/record`: JSON at most 1 KiB. Supply `X-PC-Recent-Token` from the preceding items response's `writeTokens.record` and the same browser cookie.
- `POST /wp-json/peptide/v1/recently-viewed/clear`: JSON `{}`, the separate `writeTokens.clear` token and the same browser cookie. Clears this history and its frequency only. Remains available when the feature is disabled; unrelated legacy cookies are untouched.

History responses remain `private, no-store`, varying on Cookie and Origin. Tokens expire after thirty minutes and are scoped to action, POST, store and browser. They are not account credentials and must stay in memory; do not persist them in localStorage or expose them in logs. Empty enabled items reads seed the existing HttpOnly, SameSite=Lax cookie (Secure under HTTPS). A browser that blocks this cookie cannot authorize a record: hide this optional UI without obstructing purchasing. No second history/catalog fallback has been installed.

## Explicit view versus specification update

Record examples below are schema examples, not catalog records:

```json
{"productId":123,"variationId":456,"kind":"view"}
```

`kind` is optional and defaults to `view` for existing PDP callers. Only call it after the actual public product popup has successfully loaded and is shown, or from the existing native PDP hook. Preloads, hover, impressions, previews and repeated render calls must not call it. Do not automatically retry an uncertain POST outcome. A rejected expired token can be refreshed through items before retrying.

```json
{"productId":123,"variationId":789,"kind":"selection"}
```

Use `selection` only to update the saved specification of an already viewed parent. It changes no frequency, view timestamp, count cooldown or list position. Without a prior valid, unexpired view it returns `pc_recently_viewed_view_required` (409), rather than manufacturing a view. The native server validates public status, current visibility/purchasability/stock and exact parent/variation ownership for both kinds. One parent remains one history entry even when its variation changes.

Successful record adds these fields to the existing acknowledgement:

```json
{"recorded":true,"productId":123,"variationId":456,"kind":"view","viewCount":2,"lastViewedAt":"2026-11-02T14:00:00Z"}
```

Items retain their current native product DTO and add `viewCount` and `lastViewedAt` with the same meanings. Prices always come from the current eligible SKU and native Woo display context, never the cookie. The cart exclusion still excludes all variations of an in-cart parent. No private cooldown timestamp, name/email/IP/customer identity is added.

`viewCount` is a browser-local count, integer 1–1,000,000. First actual view counts one. A repeated actual view before ten seconds since the last **counted** view updates recency/specification but does not count again. Exactly ten seconds can increment. Intermediate repeated opens do not extend the cooldown. Frequency saturates at one million. This is a convenience ranking hint, not sales, popularity, number of people, a pricing entitlement or an anti-fraud signal. Parallel browser tabs can still race to replace the same cookie: the frontend must serialize its own writes; no cross-device or exact analytical counting is claimed.

## Storage migration and bounds

The cookie name remains `pc_recent_history_v1`, with a new signed payload version 3. Compact tuples store `[productId, variationId, seenAt, viewCount, lastCountedAt]`, while internal code retains named fields. The v3 signature includes the existing blog/store origin context. `lastCountedAt` is private and never an input field.

- Legacy v2 records conservatively start at one view and preserve their original real per-product `seenAt` as the ISO `lastViewedAt`.
- Legacy v1 contains only IDs and a global issue time. Each becomes a conservative one-view hint; the global issue time is used only for retention. Public `lastViewedAt` remains `null`, including after specification-only migration. A new actual view establishes a genuine timestamp.
- Reads never turn a legacy issue time into a new product view or renew the item's age. Real opens update `seenAt`; selection-only writes preserve it.
- At most twenty entries, at most twenty record/selection writes per browser-cookie minute, cookie at most 4096 bytes. Mutation is rejected rather than silently truncating or inventing data. Existing retention and clear behavior remain.

Additional rejected fields include client `viewCount`, `lastViewedAt`, prices, identities and arbitrary context. Invalid IDs, null IDs, unsupported/null `kind`, unknown fields or invalid JSON are 400; missing/wrong browser token or origin are 403; missing/hidden/stock-unavailable selection is 404; disabled is 409; required prior view is 409; rate limiting is 429; unavailable storage is 503. Existing force-login rules remain in effect.

## Actual verification and limits

`D:/Codex/Tasks/2026-11-02/storefront-apps-install/work/verify-recent-frequency-native.php` passed **43/43** in the dedicated CART QA native WordPress/Woo runtime. Only connection-local TEMP options/posts/meta changed; the history clock was controlled in that CLI process to prove the nine-/ten-second boundaries. Native Woo getters, current SKU prices, status/stock, controller validation, purpose tokens, signature migration/clear and cookie encoding ran from the actual plugin classes. Twenty maximal records plus twenty rate timestamps encoded to **1924 bytes**. Persistent product/customer/order/tracking/session counts and options hash were unchanged; the cart-only guard hash was unchanged. No persistent product, customer or order fixture was needed.

Receipt: `D:/Codex/Tasks/2026-11-02/storefront-apps-install/outputs/recent-frequency-native.json`. The initial 39/41 receipt is retained as `recent-frequency-native-initial.json`: two harness assumptions were corrected (empty `_price` alone left Woo's valid sale/regular price; a private-product check incorrectly expected the unrelated valid parent to disappear). No production workaround was introduced for these test assumptions.

This is native backend evidence, not browser popup/render acceptance, real HTTP cookie retention, cross-tab load testing or recommendation quote verification. No frontend renderer was installed, no analytics events were merged, and no recommendation savings were asserted. The frontend must still attach the explicit successful-view/selection callbacks, use the authoritative signed-cookie transport, and verify live lifecycle behavior. A recommendation implementation cannot infer all twenty frequency candidates from the two-item display listing; its own bounded hint/eligibility contract remains separate.

Changed plugin sources: `includes/recent/class-history.php` and `includes/api/class-recently-viewed-controller.php`. Config and Store were unchanged by this task. No Git commit was made by this subtask.
