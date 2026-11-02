# Storefront plugin settings — 2026-11-02 — Codex

User supplied `VialCove_插件对接最新总需求_20261102.md` after asking us to wait for it. It supersedes the older two requirement files for this stage. This chat delivers backend/configuration; the other frontend window keeps the UI Demo, popup lifecycle, layout and animation integration. No theme or Demo files are included here.

## Delivered configuration and history extensions

- Activity listener existing application: complete nested `recommendation` object, default **disabled**, with `maxGapRatio:0.2`, `maxGapMinor:null`, `maxGapQuantity:3`, `maxAddBoxes:3`, `maxVisible:1`, `ranking:"most_viewed_then_recent"`. Publishing these settings does not edit or activate a promotion. Existing stored settings gain the disabled object only in memory; raw revision/CAS storage is preserved.
- Cart feedback existing application: `burstEnabled:true`, `burstDurationMs:850`, integer range 600–1000. Legacy complete six-field stored configurations get both defaults only in memory. New writes require all eight fields. Partially damaged/partially upgraded configurations are rejected, not silently repaired. Existing draft/applied revision handling remains unchanged.
- Recently viewed: signed browser-only v3 history now counts views per parent with a server ten-second cooldown; `viewCount` (max 1,000,000) and `lastViewedAt` are returned. `POST /peptide/v1/recently-viewed/record` accepts `kind:"view"` (default) or `kind:"selection"`. Selection updates an already-viewed parent's variation without incrementing count or changing its browser-recency position/time; it returns 409 `view_required` for an unseen parent. No IP/customer/order/visitor analytics table is used. Old signed formats migrate conservatively; v1 unknown view time is null.
- Product broadcast already has the backend application, import/export, effective configuration and block reference. Reuse `backend-product-broadcast-2026-11-02.md` and the current controller; a UI player is not installed by this change.

Admin entries remain `wp-admin/admin.php?page=pc-apps&app=activity-listener`, `...&app=cart-feedback`, and `...&app=recently-viewed`. Existing GET/PUT admin, POST publish, nonce/capability, draft/effective separation and site scoping are reused. Private history responses remain no-store. The currently installed activity celebration replay behavior follows the user's later explicit instruction: a changed, valid qualifying cart state may celebrate again; duplicate delivery of the same state does not. The older once-per-node paragraph is superseded by that direct instruction.

## Checks actually run

- Actual configuration pure tests: cart feedback 40/40; recommendation 95/95.
- Admin DOM harnesses: cart feedback 27/27; recommendation 35/35.
- Native WP/Woo using connection-local TEMP options: feedback migration/CAS 29/29; recommendation migration/CAS 27/27.
- Native WP/Woo signed history: 43/43; twenty maximum-sized entries plus write metadata used 1,924 cookie bytes. Persistent business counts/options hashes and QA protection remained unchanged.
- Activity client regression: 52/52. PHP lint, JS parse and diff checks passed for this stage.

These are backend/configuration/native-fixture checks, not claims that the frontend Demo, animation, popup view counting or real add-to-cart request lifecycle has been browser-accepted. No live application configuration, promotion, product, price, stock, customer or order was edited. New uninstalled frontend drafts were moved out of plugin source into task work files.

The recommendation quotation endpoint and full-cart fingerprint are delivered in the [separate quote handoff](backend-activity-recommendations-2026-11-02.md). Do not treat the configuration object alone as a working frontend recommendation card.
