# Storefront adapter check — 2026-11-02 — Codex backend

User asked to check backend adaptation while the frontend window synchronizes today's product dialog and cart UI to the live local theme. At the initial check the actual theme junction still targeted `frontend/wp-content/themes/peptide-site` at `668bcd9`; the new UI was in task `product-dialog-design-demo/outputs/demo`, and the frontend chat was actively implementing its live adapter. The initial Demo audit below is a checklist for that synchronization, not a claim about the final synced theme.

## Actual effective settings (read-only snapshot)

| Feature | Current state | Expected customer behavior |
| --- | --- | --- |
| Product broadcast | disabled, revision 0, zero applied texts | Hide; do not fall back to Demo/localStorage texts |
| Cart feedback | enabled, revision 0 defaults, burst true/850ms | Apply current settings to actual confirmed add-to-cart feedback |
| Recently viewed | enabled, revision 0, stored20/display2/30days, exclude cart in displayed list | Record true successful product views; selection is not another view |
| Activity listener | enabled, revision1, promotion10980 | Show native cart milestones |
| Activity recommendation | nested enabled=false | Hide recommendations until the applied config enables them; do not hide milestones |

Defaults with revision 0 are valid configurations, not a transport failure. This audit does not enable marketing, alter promotion tiers, or publish placeholder content. The site is USD/two decimals/tax disabled, with one all-customer `spend_amount` activity and no active gift/bundle offers.

## Adapter contract checklist

1. Use same-origin native REST URLs from PHP (`rest_url`), current native cart Cookie/Cart-Token and nonce. `/__demo/*`, the standalone Python proxy, snapshot catalogues and Demo token/storage keys are not the actual theme's data source. Product dialog, cart page, header counter and drawer must use the same native cart session.
2. Broadcast: `GET /peptide/v1/product-broadcast`; plugin block type `product-broadcast` already renders `[data-pc-product-broadcast]`, embedded `.pc-product-broadcast__config`, `data-config-url` and revision. Use the applied backend configuration without Demo storage listeners/overrides. Repeated same revisions should not restart playback. Update product/specification/current price/SKU from the actual selected native variation. Hide disabled/empty content.
3. Feedback: `GET /peptide/v1/cart-feedback`. Map `successDurationMs`, `successText`, `pendingText`, `bagPulseEnabled`, `burstEnabled`, `burstDurationMs`, and `enabled` to the presentation module. Existing native Showcase publishes `vc:cart-mutation-start` and confirmed `vc:cart-updated` (`detail.cart`, `mutation`, `stale`). A stale/uncertain response must not trigger success, animation, or another blind POST retry.
4. History: `GET /peptide/v1/recently-viewed/items`; use its browser-scoped `writeTokens` with `X-PC-Recent-Token`, and `X-WP-Nonce` when logged in. POST `/recently-viewed/record` only after successful visible product loading (`kind:"view"`); variation changes use `kind:"selection"`. The existing `items` is a recency-ordered display list limited to two and can exclude cart products. The new optional `candidates` contains up to twenty signed-history ranking hints, sorted by count then view time, including currently-in-cart products for top-ups. Both remain private/no-store. Use `candidates` for recommendation requests, not public visitor analytics or the display list.
5. Recommendation: read native cart `extensions.peptide_core.activity_listener`, then POST `/peptide/v1/activity-listener/recommendations` with all four freshness fields and bounded candidates. Merge the returned `recommendation` into the rendering input for that exact current snapshot. The backend does not automatically insert a dedicated POST quote into every native cart GET. See [the full quote contract](backend-activity-recommendations-2026-11-02.md).
6. Valid simple products can have an empty specification; products without media can have an empty image URL. These are not malformed quotes. Use a presentation fallback without changing the actual variation or inventing attributes. Backend `maxAddBoxes` allows 100. Money uses safe integer strings through 9007199254740991 and precision 0–8; current USD data is normally two decimals. Native rounding means rounded unit price times quantity need not equal rounded line price; the native quoted line amount is authoritative. Differences can be signed and physical-cart payable totals can be null with incomplete shipping scope.
7. On cart/config/rule/SKU changes, cancel stale fetches and discard late quotes. Display extra savings only from a current `quoted` result and bound it by whole-cart incremental savings. The actual add remains native Woo `cart/add-item`; no alternate price/order endpoint or automatic add is needed.

## Backend findings and scope

The actual site registers WooPayments multi-currency price callbacks even with only default USD enabled. The quote's previous unknown-callback guard correctly failed closed, but this also blocked the site's current no-conversion case. The integration fix accepts only the audited, already-initialized, exact native default-only callback path; nondefault/multiple currencies, uninitialized state and unknown overrides remain unsupported. No WooPayments setting, hook, session or product price is changed.

The history candidate extension keeps the two-item customer display and its ordering intact; it supplies the full browser's ranking hints separately and does not exclude existing cart products. Eligibility, actual variation, stock, promotion ownership and final savings still belong to the quote engine. No new customer/analytics tables or server-wide popularity claims.

Native variation formatting previously omitted `20mg` when it was already present in Woo's variation title. The public DTO uses the parent title, so the frontend then rejected that otherwise valid quote as missing a specification. Both recommendation `specification` and history `variationLabel` now request the full attribute text. The actual live Retatrutide variation quote is accepted by the new theme's validator after this fix.

The existing Showcase cart consumer accepts the new `live-cart` source in addition to `drawer`/`showcase`. Both start and confirmed response events invalidate pending generations; malformed arrays and stale responses cannot replace the confirmed cart. The current theme already emits the compatible `drawer` source; the alias also covers adapters using the new name.

## Validated backend stage

- Actual live catalogue/native WooPayments: 39/39 read-only checks. No database write attempts or changes to native cart/customer/session/options/business data. Anonymous and logged-in 37-check baseline receipts are retained separately. The final two additional assertions verify explicit quote/history specification labels.
- Quote trial and temporary compatibility probes preserve all original registered callbacks. A separate History DTO read can only add the exact native WordPress `WP_Metadata_Lazyloader::lazyload_meta_callback` on `get_term_metadata` at priority 10; it removes no original callbacks. The initial broader assertion that detected this lazy core registration is retained in the task evidence.
- Isolated native recommendation engine: 56/56; history ranking candidates: 29/29; original frequency/selection history: 43/43. No persistent fixture/business changes remain.
- Actual production Showcase JavaScript runtime: 14/14; source-handler event compatibility: 4/4. PHP syntax, JavaScript syntax and Git whitespace checks pass.
- Read-only current-theme contract probe accepts the real public variation quote. As of this stage, the frontend is still synchronizing. Its validator still rejects a valid simple product with an empty specification or a valid product with no image; its progress module still uses newly-crossed-tier-only celebration. These are frontend presentation/adapter follow-ups, not grounds to alter native product data or invent a specification/image.

### Follow-up after the frontend published its settings

The frontend window has now published the actual product/cart builder documents. Public HTTP verification of `/` and `/cart/` returns 200; all 13 new adapter JS/CSS resources return 200. Each page loads `live-cart-experience.js` exactly once, and neither loads the old `cart-drawer.js` or `activity-listener.js`. The original native Cart markup remains as a fallback until the new controller loads its real session.

The frontend's separate publication changed broadcast to enabled/revision1/four texts and activity to revision2 with recommendation enabled. Cart feedback and recently viewed remain enabled/revision0. All four public configuration endpoints return 200. This backend task did not publish these application settings or change products/orders. Evidence: `live-public-http-final.json`; the initial table is preserved as the earlier snapshot.

Current source-level adapter follow-ups (the frontend window is still working; per-file hashes and timestamps are in `frontend-adapter-final-audit.json`):

1. `product-dialog-experience.js` emits `vc:product-dialog-selection`, but no current theme consumer sends signed `view`/`selection` POSTs to the recent-history endpoint. Successful visible product loading must record a view; changing variation must update only its existing selection. Without this, new browsers do not build the history used by recently viewed/recommendation.
2. Recommendation buttons now receive feedback settings and zero-duration feedback works. Ordinary product-dialog add-to-cart still needs the same configured feedback lifecycle; `bagPulseEnabled` has no consumer in the current theme. Use actual confirmed native success, not button clicks or uncertain recovery.
3. `live-cart-experience.js` nulls the activity payload when the presentation progress toggle is off and thereby also hides recommendations. Keep the real activity payload for quote validation and render progress/recommendation independently.
4. `live-cart-progress.js` still celebrates newly crossed nodes only. The user's later instruction requires another celebration for a changed valid cart state that qualifies, while identical state, first load, recovery and changed rules remain static/deduplicated.
5. The recommendation presentation validator also needs the documented empty-specification/missing-image fallbacks and native money/quantity bounds described above. Do not solve presentation requirements by inventing native product attributes or prices.

Formal-site browser tab binding repeatedly timed out in this task, including after publication. HTTP/source/native checks are recorded separately and are not a claim that the actual visual/mobile interaction was accepted.

Full evidence and final validation counts are in the task outputs `D:/Codex/Tasks/2026-11-02/storefront-integration-check/outputs/`. Frontend/theme files are owned by the actively synchronizing frontend window and are not overwritten by this backend task. A completed backend audit does not by itself certify UI that has not yet landed or been exercised in the actual browser.
