# Storefront interaction motion: first pass

2026-11-03 · Codex · STOREFRONT-MOTION-POLISH

The user reports abrupt clicks and a lack of transitions, with mobile customers the priority. Preserve the current storefront design and native Woo business behavior. The subsequent request is to research high-star GitHub UI/mobile-interaction skills; research does not authorize installing any new skill.

## Changes

- The actual published header uses `demo-site-parts.js`, not the older `navigation.js` controller. Its menu/search native dialogs now enter over 350ms and leave over 200ms, with a bounded menu list sequence (at most six items and 300ms total). Interrupted/reversed animations continue from the current painted state. Real links navigate immediately; modal focus remains browser-owned until the exit finishes.
- Existing `close` events, Escape, backdrop, reduced-motion changes, hidden tabs, pagehide/BFCache, and builder detach/reinsert all settle correctly. No native method is monkeypatched. Cart/product history and backend plugins are unchanged.
- New `storefront-motion.css` supplies a 360ms product/child-dialog entrance and a restrained backdrop transition, button press feedback, and a short fade for opened FAQ answers. Initial product opacity is .2 so the original trusted-view visibility check is not cancelled by a completely invisible entrance. The animation ends at the base `transform:none` rather than retaining a transformed containing block. Reduced motion removes spatial animation. Disabled native, ARIA and Woo class states are excluded from press feedback.
- New `storefront-motion.js` listens only for actual loaded product images. A new source fades in; an identical source does not replay. Old animation is cancelled on a new source, close, hidden tab, reduced-motion change or pagehide. No image URL, selected specification, price, stock, request, form value or navigation is changed by the helper.
- `functions.php` enqueues these two small frontend assets at priority 120, using file versions. No library or new plugin is introduced.

## Verification and bounds

The menu controller's 11 lifecycle regression tests pass. JS syntax, PHP lint and diff checks pass. Independent review exercised image-load lifecycle branches and found no blocker; its two recommendations (backwards fill and class-disabled exclusion) were applied. The design detector reported inherited storefront Geist/color/type warnings against the explicitly backend-only DESIGN.md; existing public branding was retained and the backend design file was not rewritten.

Actual local formal-site 390 × 844 iframe: the new assets load; menu opens with aria-expanded=true, retains width=scrollWidth=390, and Escape closes it. The product dialog has the new 360ms entrance; its 380px content has no horizontal overflow. Native selection of 10mg updates the selected value and exact TR10 image. No cart write, customer address or payment action was performed. The browser check is not a physical-phone performance/FPS measurement.

Product **exit** still uses the existing backend-owned native close lifecycle, which immediately clears the body. This pass does not delay Back, copy product forms or promise a full product-body exit animation. A proper exit hook requires explicit frontend/backend coordination. The existing cart already has its own tested slide-out; this pass leaves it intact. FAQ content fades into the native immediately changed geometry; this is not animated height.

Publication is a separate stage. Only `functions.php`, `assets/demo-site-parts.js`, `assets/demo-site-parts.css`, and the two new motion assets belong in this runtime payload. Do not copy whole `home-quick-view.css` or `product-dialog-experience.js`: their local versions include earlier unrelated unpublished FAQ changes. Per-host current/manifest/config guards and exact HTTPS assets must be verified on any later publication.

Task artifacts: `D:/Codex/Tasks/2026-11-03/storefront-motion-polish/`.
