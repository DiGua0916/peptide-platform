# Native mobile presentation settings integration

2026-11-05 · Codex · local first, no GitHub push or public deployment in this phase.

## Reproduced problem and native fields

The backend window supplied a native read-only reproduction: mobile `broadcastEnabled=false` and `cart_progress_enabled=false` were saved and correctly resolved by Renderer, but the theme getters/controllers still consumed one desktop/global value. This phase is M10.08 plus product/cart presentation lifecycle fixes, not another page redesign or business system.

| Existing document/block | Desktop/mobile fields |
| --- | --- |
| Generic or product-specific product / product experience | `mobileDialog`, `reportGalleryEnabled`, `stickyPurchaseEnabled`, `broadcastEnabled`, `whatsappEnabled`, `whatsappNumber`, `whatsappLabel`, `whatsappMessage` |
| Cart / context content | `cart_experience_enabled`, `cart_progress_enabled`, `cart_recommendations_enabled`, `cart_recent_enabled`, `cart_title`, `cart_continue_label` |

Native row enabled, device visibility and sparse mobile overrides remain authoritative. Missing mobile values inherit; explicit false and empty text remain explicit. A disabled product-specific published row does not fall through to a generic row. Product/cart getters read published snapshots only. The separately authorized checkout-preview adapter and existing catalog quick-add/subtotal handling remain intact.

The theme-local DTO adds `settings.devices.desktop/mobile`, each with its full field set and effective enabled (native row enabled, device visible, device main switch). Contacts are independently sanitized/validated. Top-level values remain desktop-compatible except enabled/main switches, which aggregate whether either device needs assets. Controllers never use those aggregate gates to enable a disabled viewport. No new option, endpoint or localStorage is introduced; use the existing block's mobile settings and ordinary draft/publish controls.

## Actual behavior and files

- `inc/live-commerce-settings.php` resolves published device DTOs; `inc/storefront-entry.php` separates current-device redirect eligibility from any-device asset loading for later viewport changes.
- `assets/product-dialog-experience.js` retains its 820px breakpoint and one outer owner. Identical settings/variation updates retain the presentation. A changed device disposes the old inner reads, feedback, viewers, dock/meta and restores the original nodes before mounting the new view. Form/quantity/Add/Buy/image/title and focus are retained. Disabled devices remain on native presentation. Browser restore/focus rechecks a media event missed during suspension, without remounting identical settings.
- `assets/product-dialog-polish.js` and `product-dialog-offer-follow.js` release only the matching owner on `vc:product-experience-disposed`, before original nodes are restored. Other-product events cannot release the active owner. Approved removal of duplicate product promotions remains intact.
- `assets/live-cart-experience.js` retains its 768px breakpoint, single cart client/write owner, row map, checkout bridge and widget instances. Device switches retire old history/quote/decoration generations, then repaint features and copy. Disabled presentation restores native fallback/closes the existing drawer through its original owner. `View full bag` keeps its distinct cart-route meaning. Browser restore/focus also rechecks the media state.
- New tests: `live-commerce-devices.php`, `cart-device-settings.cjs`, `product-device-settings.cjs`; existing storefront-entry/polish/offer-follow tests gain lifecycle boundaries.

Backend application enablement, eligibility, same-SKU prices/stock, cart confirmation and payment state remain authoritative. Display settings cannot generate a recommendation or enable an unavailable application. A view switch cannot cancel/repeat a submitted native write or create another transaction queue. No backend source was changed; the owner's three installed shipping-trace files remain intact. The two existing component breakpoints are preserved rather than redefined by this fix.

## Verification and limits

50 isolated PHP device checks and 73 entry checks passed. Actual LocalWP/Woo verification passed 233 checks covering every field, inheritance, both override directions, text/contact values, disabled/hidden modules, product-specific precedence and published/draft isolation. Seven protected layout posts/metadata (draft/published/edit lock included) retained hashes. The guard recorded zero SQL write/lock attempts during the probe after native bootstrap; this does not claim arbitrary WordPress bootstrap/shutdown is maintenance-write-free.

The combined related Node run passed 178 top-level tests before the final small product BFCache addition. After that addition, 19 current device-controller tests (9 product, 10 cart) passed and an independent source review found no actionable P1/P2. Full production controllers run with isolated DOM/platform/HTTP peers, including real dock/meta helpers, stale response retirement, close/reopen, original node identity, checkout busy/processing and backend-disabled applications. Script-style internal assertions are not added again to top-level totals. Existing native purchase-flow tests retain their separate 12 assertions.

PHP/JS syntax and diff checks passed. Anonymous homepage/cart/COA/normal product redirect readbacks succeed; eleven runtime assets match disk (nine normal readbacks plus product experience/offer-follow). The final product restore-listener change is checked separately. No actual cart/coupon/address/stock/order/payment/WhatsApp action was used as UI verification.

Computer Use stopped earlier because it could not reliably identify the Windows browser URL. No alternate UI automation was used. New mobile visual/scroll acceptance remains outstanding; keep GitHub push held for the user's local-first condition. Source checkpoints are not public deployment, and native layout DB data does not travel with Git.

Private evidence: `D:/Codex/Tasks/2026-11-05/local-ui-integration-review/outputs/commerce-devices-native.json`, device implementation reports, `http-readback.json` and `device-assets-readback.json`. No preview nonce/token or customer record is persisted in this handoff.
