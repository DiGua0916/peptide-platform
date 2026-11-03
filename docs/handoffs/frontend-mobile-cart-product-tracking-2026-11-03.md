# Mobile cart and current product-card status

2026-11-03 · Codex · MOBILE-CART-PRODUCT-TRACKING

The user's latest instruction replaces the older mobile full-page cart decision: the real shopping bag opens from the right on phones as well as desktop. Product details stay in the existing native product dialog. The user also requested the existing formal-site phone preview in the right panel.

## Implemented scope

- Reuse the current Woo Store API cart and published product/cart experience settings. No demo cart, price calculation, second business data source, option change, or backend plugin edit.
- The mobile bag is a right-side native dialog with a small inset, rounded left corners, safe-area padding, and reduced-motion support. The actual `/cart/` route remains the fallback and checkout stays native Woo Blocks.
- Keep the underlying product open when its Bag link opens the cart. History Back closes only the upper layer and preserves the product and host page. Reopening Bag from a product launched inside an existing cart reveals that cart instead of creating a circular stack.
- Load the bag on ordinary checkout too, excluding order-pay/order-received and explicit native fallback routes. Use Woo's public cart store `receiveCartContents` only for confirmed contents, explicitly excluding billing/shipping addresses. Native calculation, customer-data updates and payment processing guard writes. No submitted order, payment-session or merchant configuration changes.
- Fix the actual footer initialization race: drawer markup now renders at priority 10, before WordPress's priority-20 footer script printer. Previously the controller exited before the drawer existed without a console error, leaving the normal cart link navigation.
- Catch the original trusted lazy product opening at window capture, before the home controller's document capture stops propagation. A later native `vc:product-presented` must match the original opener, product ID, connected/open native DOM and visible content. Synthetic replay alone never counts; cancellation, Escape and bounded expiry retire pending eligibility.
- A passive surface observer supplies current product ID/name or cart state to existing presence/heartbeat envelopes. The physical document pathname remains unchanged. Product intent/view/impression sources remain physical, with the current-content heartbeat immediately following in the same normal batch. No synthetic page views or inflated PV counts. Closing a layer restores the real underlying surface.
- Existing enabled/staff/preview/GPC gates remain. No collection is turned on for verification. The current-content use of existing protocol fields was described in the project room; no shared schema or backend contract is changed.

## Verification actually completed

- JS combined run: 36 top-level test cases passed, including cart lifecycle/checkout bridge 20 checks, surfaces 17, native-intent 9 cases plus its existing 83 assertions, pair 7, transport 48 and product-actions 74. These are isolated regression tests, not real visitor/order activity.
- PHP configuration: cart entrypoints/footer ordering 14, visitor configuration 152, intents configuration 92 across 18 modes. Syntax and diff checks pass.
- IAB same-origin actual site at a 390 × 844 frame: checkout header Bag opens a 378px-wide native drawer at x=12, keeps `/checkout/`, and has no horizontal overflow. Escape closes it on the same checkout page.
- Actual homepage: opening Tirzepatide creates the native product dialog for product 11306 while retaining `/`; its Bag opens a second native layer, with the product still open beneath it. Browser Back closes the cart and retains Tirzepatide on `/`.
- No cart quantities, address values, tracking settings, orders or payment actions were changed in browser verification. This is responsive browser verification, not an assertion of physical iOS/Android hardware testing.
- The browser is logged in as staff, so the excluded admin session is not evidence of a real collector/server receipt. Product/current-context semantics are verified by production collector/adapter regression fixtures and the existing server receiver review; do not claim a live customer record was checked.

## Publication boundary

Local formal theme uses the frontend worktree junction. Public buyer and merchant-preview deployments are separate from Git integration. A scoped publication must copy each host's own active runtime, overlay only the reviewed ten cart/navigation/tracking files, guard config/current-pointer/code hashes, and verify HTTPS asset hashes and health.

`inc/live-commerce-settings.php` has a help-text update describing the current mobile behavior in the local source. Do **not** copy that entire file to the current public runtime: it also contains an earlier unrelated, unpublished FAQ change. Tests, docs and the complete theme are not the deployment payload. Public deployment receipts and the final immutable commit reference are recorded separately after execution.

Task artifacts: `D:/Codex/Tasks/2026-11-03/mobile-cart-product-tracking/`.
