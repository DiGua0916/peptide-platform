# Storefront UI refresh — 2026-11-04

Codex. User-authorized source: `D:/Codex/Tasks/2026-11-04/frontend-backend-ui-split/outputs/前端UI需求.md` (14 FE items). Mobile first; retain the approved storefront, actual Woo forms/cart/checkout, and editable layout documents. No public deployment was requested or performed.

## Implementation

| Items | Theme result |
| --- | --- |
| FE01 | Compact product cards, light full-card boundary, smaller internal gaps and mobile specification text; existing links, first configured SKU, prices, red ribbons and 44px actions remain. Focus/hover only, no scroll entrance. |
| FE02 | Editable `showContext` hides the standalone homepage reference-report notice. Report source, date, links, actual PDF and viewer context are retained. |
| FE03 | Editable per-counter `displayCap`: real product/specification counts remain unchanged; counters animate to 80+/180+ only when the actual count exceeds the cap. Exact-cap, manual and decimal behavior retained. This resolves the source's inconsistent 180/183+ wording as documented. |
| FE04 | Editable three-card reasons presentation: clear box pricing, dedicated account follow-up, shipping visibility. No new response SLA, lowest-price or delivery-time claim. Original step presentation remains available. |
| FE05 | Editable About Us rich text retains the full five approved paragraphs, adding three semantic subheadings and emphasis on original phrases. |
| FE06 | Cart line price and compact native quantity/remove controls on the right. One server-quoted recommendation only, exactly one box. Show current price, actual quoted incremental savings, optional configured coupon description. One native add-item POST with signed ticket; no second add/apply-coupon. |
| FE07 | Exact selected-SKU per-vial DTO shown beside specification; no frontend division, dose parsing or assumption of ten vials. Clear, loading, missing/ambiguous pack and wrong-SKU data hide it immediately. Actual purchase remains the box. |
| FE08–11 | Tighter native checkout; actual item-price savings larger/red, separate from native coupons/fees; compact rows and red sale badge. Native total occupies a circular cell at summary bottom-right. Both Woo responsive summaries share CSS, without copying amounts or moving React nodes. Native autocomplete/address/shipping updates and mandatory notices/errors stay. Checkout-only document diversion removed. |
| FE12 | Editorial header consumes the backend campaign helper. Local header686 now binds existing promotion10980, the same activity-listener promotion; homepage-only. English storefront localizes the existing title 黑色星期五 to Black Friday, leaving native tiers/status/dates unchanged. No invented dates, amounts or countdown. |
| FE13 | One optional bottom-right headset link reads enabled Support_Contacts WhatsApp URL; validates https wa.me identity. Hidden when missing/disabled, during dialogs/menus and on checkout, with safe-area/native mobile-nav clearance. No hardcoded phone or separate support storage. |
| FE14 | Local footer document copyright is ©2025 VialCove; redundant footer-only notice empty. Other product/checkout usage and terms remain. |

## Backend contract and race handling

The new BE source arrived during this task as `80b8894` (normal main integration `473dd8f`), after initial audit found old runtime. The LocalWP plugin is another role's dirty worktree; this task did not edit, reset, stage or advance that worktree. A later read confirmed the new helper/config protocol running locally, and the real same-origin mobile bag displayed its one-box recommendation. Earlier “not installed” notes in temporary audit files are historical snapshots, superseded by the final runtime check.

Theme declares `supportsAutomaticCoupon:true` only when the complete published `recommendationCoupon` config is present, avoiding old endpoint unknown-key errors. New quotes require a valid one-box ticket; add payload is native `{id,quantity:1,extensions:{peptide_core:{activityRecommendation:{offerToken}}}}`. The receipt is read from **that mutation's rawCart**, not a later GET. `added:true/couponApplied:false` preserves confirmed cart feedback, removes stale savings promises and offers a read-only review action; it never retries the add. Pending expiration/config changes retain response ownership; changes before the submit microtask block the stale POST. Current cart/snapshot/revision/currency/expiry and quoted savings consistency remain guarded. Backend remains the price/coupon authority.

Per-vial input is initial `peptide_core_get_unit_pricing($first)` or native `pc_unit_pricing` variation payload. Product/variation/purchasable ID, exact selected attributes, reliable count source, native formatted currency/tax context must match. Cached null replaces a previous value, pending/Clear cannot leak the preceding SKU's price.

## Local layout publication

Local theme junction serves this worktree. Guarded normal layout-controller save/publish updated home685/footer687, then header686. Original backups/candidates and receipts are under `D:/Codex/Tasks/2026-11-04/frontend-ui-refresh/outputs/`; no dumps or runtime data enter Git.

The first homepage save succeeded but its strict PHP comparison stopped before publication: exactly two counter `number` values round-tripped from `0.0` to `0`. A read-only audit found no content loss. A second script checked exact current draft/original published hashes and complete semantic equality (only numeric scalar representation/object key order tolerated), published the existing draft, then changed only the two footer fields. Other protected layout revisions remained unchanged. Header was subsequently changed intentionally only to the real campaign binding/homepage visibility through the same revision-guarded API; original manual text remains stored. No marketing activity, coupon, product, stock, support switch, customer/cart/address/order or payment setting was changed.

## Verification and limits

- 53 Node top-level tests passed in the final combined run: offer protocol/lifecycle, selected unit-price model/meta, dialog interaction, native purchase/dock/return, drawer/checkout bridge, and pending quick view. Internal assertions in legacy runners overlap with this count and are not added again.
- 15 PHP presentation guards, 9 actual WordPress homepage template render checks, PHP lint for the ten changed/new runtime PHP files, JS syntax and `git diff --check` passed.
- Actual IAB mobile homepage 375/390/430 and desktop1280: no page horizontal overflow, full-card borders, compact gaps, 44px card actions, three About headings, new reasons, updated footer. Real local mobile bag recommendation: current cart258.90, gap41.10 to300, exactly one Tirzepatide20mg box55, quoted additional saving25. No actual add button was clicked.
- Actual checkout375/390/430/1280: no horizontal overflow; both native summaries read the same total; 120×120 mobile /160×160 desktop total stamp; terms/privacy/no-payment warning retained. Native country/postcode autocomplete remains. No address was filled, shipping recalculation with an actual address or payment/order submission was tested.
- Current support is disabled with no channels, so absence of a floating link is correct. Automatic recommendation coupon is disabled/no selected coupon; actual coupon application was not exercised against a customer cart. Protocol and partial-success cases use the production JS with controlled responses, not real orders.
- **Data follow-up:** the selected Tirzepatide11306/TR20 and sampled other variation receive `unitPricing:null` from the newly running backend. Their native label uses `Specificatio`/`Size`, not the backend's recognized Pack field; no explicit `_pc_vials_per_sales_unit` was supplied. Frontend correctly hides the optional equivalent price. Backend/merchant must provide a reliable pack count (or explicitly support the verified legacy attribute) before it can display. No frontend guessing or silent product-meta migration.

Screenshots: `home-cards-390.png`, `cart-390-preview.png`, `home-desktop-top.jpg`, `checkout-390-full.jpg`, `checkout-desktop-full.jpg`. Evidence and migration scripts stay in the D-drive task, not the source repo. Browser simulations are not physical-device FPS, gesture or real payment acceptance tests.

## Next integration

Theme/contract changes and native tests are ready for normal Git integration. Public servers need a separate reviewed release of theme assets and their editable layout documents; Git push/local junction is not deployment. Coordinate pack-count data and a selected existing coupon with the backend window; do not manufacture customer data or coupon amounts to make those states appear.

Static design scan of the eight affected CSS assets reported six `overused-font` advisories for the incumbent Geist family; this refinement deliberately retains the user's approved storefront typography. No other findings were emitted. The repository's admin-only DESIGN.md was excluded from this public-store scan. This is static source review, not a performance measurement.
